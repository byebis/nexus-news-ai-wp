<?php
/**
 * Nexus Newsletter — iscrizioni double opt-in, digest settimanale e shortcode pubblico.
 *
 * Contratto pubblico (CONTRATTO v1.0.2, sezione §Newsletter):
 * Nexus_Newsletter::init()                   — bootstrap: rotte REST, cron digest, shortcode, query var
 * Nexus_Newsletter::register_routes()        — POST /nexus/v1/subscribe e POST /nexus/v1/unsubscribe
 * Nexus_Newsletter::rest_subscribe($request) — handler iscrizione (nonce, rate limit, email conferma)
 * Nexus_Newsletter::rest_unsubscribe($r)     — handler disiscrizione via token
 * Nexus_Newsletter::handle_query_vars()      — template_redirect: ?nexus_confirm / ?nexus_unsub
 * Nexus_Newsletter::render_subscribe_form()  — shortcode [nexus_subscribe] (form + fetch + nonce)
 * Nexus_Newsletter::send_weekly_digest()     — azione cron 'nexus_weekly_digest'
 * Nexus_Newsletter::deactivate()             — static, opzionale per register_deactivation_hook
 *
 * Note di integrazione:
 * - Le rotte REST sono gestite QUI, non in class-nexus-rest.php (nessuna modifica lì).
 * - Lo schedule 'weekly' è builtin di WordPress (5.4+); l'evento 'nexus_weekly_digest'
 *   viene allineato automaticamente a enable_newsletter a ogni init() (autosync).
 * - Dipendenze usate SOLO con guard class_exists/method_exists (mai fatale):
 *   Nexus_DB (subscribers, get_subscriber*, insert_subscriber, update_subscriber,
 *   articles, get_settings, insert_activity) e Nexus_AI_Engine::chat.
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Newsletter {

        /**
         * Hook WP-Cron del digest settimanale.
         */
        const HOOK_DIGEST = 'nexus_weekly_digest';

        /**
         * Azione nonce condivisa da form shortcode e rotta REST /subscribe.
         */
        const NONCE_ACTION = 'nexus_subscribe';

        /**
         * Ritardo (secondi) prima della prima esecuzione del digest dopo la schedulazione.
         */
        const FIRST_RUN_DELAY = 3600;

        /**
         * Contatore form renderizzati nella pagina (per id univoci dell'input email).
         *
         * @var int
         */
        private static $form_count = 0;

        /**
         * Flag: script inline dello shortcode stampato una sola volta per pagina.
         *
         * @var bool
         */
        private static $script_printed = false;

        /* ========================= BOOTSTRAP ========================= */

        /**
         * Bootstrap modulo: rotte REST, azione cron, gestione query var, shortcode
         * e allineamento automatico dello schedule a enable_newsletter.
         *
         * @return void
         */
        public static function init() {
                add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
                add_action( self::HOOK_DIGEST, array( __CLASS__, 'send_weekly_digest' ) );
                add_action( 'template_redirect', array( __CLASS__, 'handle_query_vars' ) );
                add_shortcode( 'nexus_subscribe', array( __CLASS__, 'render_subscribe_form' ) );

                self::sync_schedule();
        }

        /**
         * Registra le due rotte REST del namespace nexus/v1 (nuove, non in class-nexus-rest.php):
         *
         * - POST /nexus/v1/subscribe   → Nexus_Newsletter::rest_subscribe()
         * - POST /nexus/v1/unsubscribe → Nexus_Newsletter::rest_unsubscribe()
         *
         * Entrambe pubbliche (permission __return_true): la /subscribe è protetta da
         * nonce 'nexus_subscribe' + rate limit per IP, la /unsubscribe usa il token
         * univoco dell'iscritto (link presente nelle email).
         *
         * @return void
         */
        public static function register_routes() {
                register_rest_route(
                        'nexus/v1',
                        '/subscribe',
                        array(
                                'methods'             => WP_REST_Server::CREATABLE,
                                'callback'            => array( __CLASS__, 'rest_subscribe' ),
                                'permission_callback' => '__return_true',
                        )
                );

                register_rest_route(
                        'nexus/v1',
                        '/unsubscribe',
                        array(
                                'methods'             => WP_REST_Server::CREATABLE,
                                'callback'            => array( __CLASS__, 'rest_unsubscribe' ),
                                'permission_callback' => '__return_true',
                        )
                );
        }

        /* ========================= ROTTE REST ========================= */

        /**
         * Handler POST /nexus/v1/subscribe.
         *
         * Pipeline: nonce → email valida → rate limit 1 richiesta/60s per IP
         * (transient 'nexus_sub_rate_' . md5(ip)) → insert_subscriber → email di
         * conferma con link home_url('/?nexus_confirm={token}').
         *
         * Se l'email è già registrata e confermata risponde con messaggio onesto
         * 'Sei già iscritto' (nessuna nuova email). Se è registrata ma pending o
         * unsubscribed, reinvia il link di conferma con il token esistente.
         *
         * @param WP_REST_Request $request Richiesta REST.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_subscribe( $request ) {
                if ( ! class_exists( 'Nexus_DB' ) ) {
                        return new WP_Error(
                                'nexus_newsletter_unavailable',
                                __( 'Servizio newsletter non disponibile al momento.', 'nexus-news-ai' ),
                                array( 'status' => 500 )
                        );
                }

                $nonce = sanitize_text_field( (string) $request->get_param( 'nonce' ) );
                if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
                        return new WP_Error(
                                'nexus_newsletter_bad_nonce',
                                __( 'Sessione scaduta: ricarica la pagina e riprova.', 'nexus-news-ai' ),
                                array( 'status' => 403 )
                        );
                }

                $email = sanitize_email( (string) $request->get_param( 'email' ) );
                if ( ! is_email( $email ) ) {
                        return new WP_Error(
                                'nexus_newsletter_bad_email',
                                __( 'Inserisci un indirizzo email valido.', 'nexus-news-ai' ),
                                array( 'status' => 400 )
                        );
                }

                /* Rate limit semplice: 1 richiesta di iscrizione ogni 60 secondi per IP. */
                $ip        = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
                $rate_key  = 'nexus_sub_rate_' . md5( $ip );
                $rate_hit  = get_transient( $rate_key );
                if ( $rate_hit ) {
                        return new WP_Error(
                                'nexus_newsletter_rate_limited',
                                __( 'Troppi tentativi: riprova tra un minuto.', 'nexus-news-ai' ),
                                array( 'status' => 429 )
                        );
                }
                set_transient( $rate_key, 1, 60 );

                $existing = Nexus_DB::get_subscriber_by_email( $email );

                if ( $existing && 'confirmed' === (string) $existing->status ) {
                        return rest_ensure_response(
                                array(
                                        'ok'      => true,
                                        'message' => __( 'Sei già iscritto alla nostra newsletter: nessuna azione necessaria.', 'nexus-news-ai' ),
                                )
                        );
                }

                $id = Nexus_DB::insert_subscriber( $email );
                if ( $id <= 0 ) {
                        return new WP_Error(
                                'nexus_newsletter_failed',
                                __( 'Iscrizione non riuscita: riprova più tardi.', 'nexus-news-ai' ),
                                array( 'status' => 500 )
                        );
                }

                /* Token: preferisci quello della riga appena letta, altrimenti quello pre-esistente. */
                $token = '';
                $row   = Nexus_DB::get_subscriber( $id );
                if ( $row && ! empty( $row->token ) ) {
                        $token = (string) $row->token;
                } elseif ( $existing && ! empty( $existing->token ) ) {
                        $token = (string) $existing->token;
                }

                if ( '' !== $token ) {
                        self::send_confirmation_email( $email, $token );
                }

                $message = $existing
                        ? __( 'Email già registrata: ti abbiamo appena inviato un nuovo link di conferma.', 'nexus-news-ai' )
                        : __( 'Iscrizione completata: apri la tua casella email e clicca il link di conferma.', 'nexus-news-ai' );

                return rest_ensure_response(
                        array(
                                'ok'      => true,
                                'message' => $message,
                        )
                );
        }

        /**
         * Handler POST /nexus/v1/unsubscribe.
         *
         * Parametro 'token' (il link con ?nexus_unsub={token} resta la via utente):
         * token valido → status 'unsubscribed'; token invalido → 404.
         *
         * @param WP_REST_Request $request Richiesta REST.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_unsubscribe( $request ) {
                if ( ! class_exists( 'Nexus_DB' ) ) {
                        return new WP_Error(
                                'nexus_newsletter_unavailable',
                                __( 'Servizio newsletter non disponibile al momento.', 'nexus-news-ai' ),
                                array( 'status' => 500 )
                        );
                }

                $token      = sanitize_text_field( (string) $request->get_param( 'token' ) );
                $subscriber = ( '' !== $token ) ? Nexus_DB::get_subscriber_by_token( $token ) : null;

                if ( ! $subscriber ) {
                        return new WP_Error(
                                'nexus_newsletter_bad_token',
                                __( 'Token di disiscrizione non valido.', 'nexus-news-ai' ),
                                array( 'status' => 404 )
                        );
                }

                Nexus_DB::update_subscriber( (int) $subscriber->id, array( 'status' => 'unsubscribed' ) );

                return rest_ensure_response(
                        array(
                                'ok'      => true,
                                'message' => __( 'Disiscrizione completata: non riceverai più la nostra newsletter.', 'nexus-news-ai' ),
                        )
                );
        }

        /* ========================= QUERY VAR (CONFERMA / DISISCRIZIONE) ========================= */

        /**
         * Gestione link dalle email, su template_redirect:
         *
         * - ?nexus_confirm={token} → status 'confirmed' + confirmed_at adesso;
         * - ?nexus_unsub={token}   → status 'unsubscribed'.
         *
         * In entrambi i casi stampa una pagina minimale in italiano (doctype + html
         * con wp_head/wp_footer, messaggio centrale, link al sito) e termina con exit.
         * Token non trovato → pagina 404 con messaggio. Nessun die() nudo.
         *
         * @return void
         */
        public static function handle_query_vars() {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- link one-time firmato dal token dell'iscritto.
                $confirm = isset( $_GET['nexus_confirm'] ) ? sanitize_text_field( wp_unslash( $_GET['nexus_confirm'] ) ) : '';
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- idem: il token È la credenziale.
                $unsub   = isset( $_GET['nexus_unsub'] ) ? sanitize_text_field( wp_unslash( $_GET['nexus_unsub'] ) ) : '';

                if ( '' === $confirm && '' === $unsub ) {
                        return;
                }

                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'get_subscriber_by_token' ) ) {
                        self::render_notice_page(
                                __( 'Servizio non disponibile', 'nexus-news-ai' ),
                                __( 'Il servizio newsletter non è attivo al momento: riprova più tardi.', 'nexus-news-ai' ),
                                503
                        );
                }

                /* ---------- Conferma iscrizione ---------- */
                if ( '' !== $confirm ) {
                        $subscriber = Nexus_DB::get_subscriber_by_token( $confirm );

                        if ( ! $subscriber ) {
                                self::render_notice_page(
                                        __( 'Link non valido', 'nexus-news-ai' ),
                                        __( 'Il link di conferma non è valido o non esiste più.', 'nexus-news-ai' ),
                                        404
                                );
                        }

                        $already = ( 'confirmed' === (string) $subscriber->status );

                        if ( ! $already ) {
                                Nexus_DB::update_subscriber(
                                        (int) $subscriber->id,
                                        array(
                                                'status'       => 'confirmed',
                                                'confirmed_at' => current_time( 'mysql' ),
                                        )
                                );
                        }

                        self::render_notice_page(
                                $already ? __( 'Iscrizione già confermata', 'nexus-news-ai' ) : __( 'Iscrizione confermata!', 'nexus-news-ai' ),
                                $already
                                        ? __( 'Sei già iscritto alla newsletter: nessuna azione necessaria.', 'nexus-news-ai' )
                                        : __( 'Grazie! Da ora riceverai il digest settimanale con i nostri migliori articoli.', 'nexus-news-ai' ),
                                200
                        );
                }

                /* ---------- Disiscrizione ---------- */
                $subscriber = Nexus_DB::get_subscriber_by_token( $unsub );

                if ( ! $subscriber ) {
                        self::render_notice_page(
                                __( 'Link non valido', 'nexus-news-ai' ),
                                __( 'Il link di disiscrizione non è valido o non esiste più.', 'nexus-news-ai' ),
                                404
                        );
                }

                $already = ( 'unsubscribed' === (string) $subscriber->status );

                if ( ! $already ) {
                        Nexus_DB::update_subscriber( (int) $subscriber->id, array( 'status' => 'unsubscribed' ) );
                }

                self::render_notice_page(
                        __( 'Disiscrizione completata', 'nexus-news-ai' ),
                        $already
                                ? __( 'Risulti già disiscritto dalla newsletter.', 'nexus-news-ai' )
                                : __( 'Non riceverai più la nostra newsletter. Ci dispiace di averti perso!', 'nexus-news-ai' ),
                        200
                );
        }

        /* ========================= SHORTCODE [nexus_subscribe] ========================= */

        /**
         * Shortcode [nexus_subscribe]: form email + bottone con invio via fetch
         * alla rotta REST /nexus/v1/subscribe.
         *
         * Markup: classi prefisso 'nexus-news-', nonce nascosto via wp_nonce_field
         * ('nexus_subscribe'), REST root nell'attributo data-nexus-news-rest.
         * Messaggi successo/errore in italiano mostrati dallo script inline.
         *
         * @param array|string $atts Attributi. Supportati: title (titolo del box).
         * @return string
         */
        public static function render_subscribe_form( $atts = array() ) {
                // v1.0.6: il form renderizzato via shortcode bare non eredita
                // l'enqueue dello stile pubblico (lo fa Nexus_Shortcodes solo per
                // i propri shortcode e il blocco subscribe): qui lo mettiamo in
                // coda se registrato, così il form esce formattato anche qui.
                if ( wp_style_is( 'nexus-public', 'registered' ) ) {
                        wp_enqueue_style( 'nexus-public' );
                }

                $atts = shortcode_atts(
                        array(
                                'title'       => __( 'Iscriviti alla newsletter', 'nexus-news-ai' ),
                                'description' => __( 'Un digest settimanale con i migliori articoli della redazione AI, direttamente nella tua email.', 'nexus-news-ai' ),
                                'button'      => __( 'Iscrivimi', 'nexus-news-ai' ),
                        ),
                        (array) $atts,
                        'nexus_subscribe'
                );

                self::$form_count++;
                $uid        = 'nexus-news-email-' . self::$form_count;
                $rest_url   = esc_url( rest_url( 'nexus/v1/subscribe' ) );
                $nonce_html = wp_nonce_field( self::NONCE_ACTION, 'nexus_news_nonce', false, false );

                $out  = '';
                $out .= '<div class="nexus-news-subscribe" data-nexus-news-rest="' . $rest_url . '">';
                $out .= '<h3 class="nexus-news-title">' . esc_html( (string) $atts['title'] ) . '</h3>';
                if ( '' !== trim( (string) $atts['description'] ) ) {
                        $out .= '<p class="nexus-news-description">' . esc_html( (string) $atts['description'] ) . '</p>';
                }
                $out .= '<form class="nexus-news-form" method="post" action="#">';
                $out .= $nonce_html;
                $out .= '<label class="nexus-news-label" for="' . esc_attr( $uid ) . '">' . esc_html__( 'Indirizzo email', 'nexus-news-ai' ) . '</label>';
                $out .= '<div class="nexus-news-row">';
                $out .= '<input type="email" id="' . esc_attr( $uid ) . '" class="nexus-news-input" name="nexus_news_email" required="required" autocomplete="email" placeholder="' . esc_attr__( 'tua@email.it', 'nexus-news-ai' ) . '" />';
                $out .= '<button type="submit" class="nexus-news-button">' . esc_html( (string) $atts['button'] ) . '</button>';
                $out .= '</div>';
                $out .= '</form>';
                $out .= '<p class="nexus-news-status" role="status" aria-live="polite" hidden></p>';
                $out .= '</div>';

                $out .= self::inline_script();

                return $out;
        }

        /**
         * Script inline dello shortcode (stampato una sola volta per pagina).
         * Fetch POST JSON {email, nonce} verso l'URL nell'attributo data-nexus-news-rest;
         * messaggi IT in .nexus-news-status. Contenuto statico: nessun dato dinamico
         * iniettato nel JS, il blocco è protetto con nowdoc.
         *
         * @return string
         */
        private static function inline_script() {
                if ( self::$script_printed ) {
                        return '';
                }
                self::$script_printed = true;

                $js = <<<'JS'
(function () {
        'use strict';

        function bind(root) {
                if (root.getAttribute('data-nexus-news-bound') === '1') { return; }
                root.setAttribute('data-nexus-news-bound', '1');

                var form = root.querySelector('.nexus-news-form');
                var input = root.querySelector('.nexus-news-input');
                var status = root.querySelector('.nexus-news-status');
                var button = root.querySelector('.nexus-news-button');
                var url = root.getAttribute('data-nexus-news-rest');
                if (!form || !input || !status || !url) { return; }

                function show(message, isError) {
                        status.textContent = message;
                        status.className = 'nexus-news-status ' + (isError ? 'nexus-news-status--error' : 'nexus-news-status--success');
                        status.hidden = false;
                }

                form.addEventListener('submit', function (event) {
                        event.preventDefault();
                        var email = (input.value || '').trim();
                        var nonceField = form.querySelector('[name="nexus_news_nonce"]');

                        if (!email || email.indexOf('@') === -1) {
                                show('Inserisci un indirizzo email valido.', true);
                                return;
                        }
                        if (!nonceField || !nonceField.value) {
                                show('Sessione scaduta: ricarica la pagina e riprova.', true);
                                return;
                        }

                        if (button) { button.disabled = true; }
                        show('Invio in corso\u2026', false);

                        fetch(url, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: { 'Content-Type': 'application/json; charset=UTF-8' },
                                body: JSON.stringify({ email: email, nonce: nonceField.value })
                        }).then(function (response) {
                                return response.json().then(function (data) {
                                        return { ok: response.ok && data && data.ok, message: data && data.message ? data.message : '' };
                                }).catch(function () {
                                        return { ok: false, message: '' };
                                });
                        }).then(function (result) {
                                if (result.ok) {
                                        show(result.message || 'Iscrizione completata: controlla la tua email.', false);
                                        form.reset();
                                } else {
                                        show(result.message || 'Si \u00e8 verificato un errore: riprova.', true);
                                }
                        }).catch(function () {
                                show('Errore di rete: riprova tra qualche istante.', true);
                        }).then(function () {
                                if (button) { button.disabled = false; }
                        });
                });
        }

        function boot() {
                var roots = document.querySelectorAll('.nexus-news-subscribe');
                for (var i = 0; i < roots.length; i++) { bind(roots[i]); }
        }

        if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', boot);
        } else {
                boot();
        }
})();
JS;

                return '<script>' . $js . '</script>';
        }

        /* ========================= DIGEST SETTIMANALE (CRON) ========================= */

        /**
         * Azione cron 'nexus_weekly_digest': invia il digest settimanale.
         *
         * Pipeline:
         * - guard enable_newsletter != 1 → false (l'evento è comunque deschedulato dall'autosync);
         * - articoli 'published' (ultimi 20) → filtro in PHP su published_at ultimi 7 giorni;
         * - 0 articoli → false;
         * - destinatari: newsletter_recipients (comma, sanitize_email, UNIQUE) + subscriber
         *   'confirmed' → nessuno → false;
         * - enable_digest == 1 → UNA chiamata Nexus_AI_Engine::chat (solo testo IT:
         *   intro 2 frasi + un paragrafo per articolo), con fallback lista manuale
         *   titoli+link se la chat fallisce o non è disponibile;
         * - wp_mail HTML (max-width 600px): footer con link disiscrizione per-subscriber
         *   (?nexus_unsub={token}), footer generico per i destinatari fissi;
         * - log attività best-effort ('Digest inviato a N destinatari').
         *
         * @return bool True se almeno una email è stata inviata.
         */
        public static function send_weekly_digest() {
                try {
                        if ( ! class_exists( 'Nexus_DB' ) ) {
                                return false;
                        }

                        $settings = Nexus_DB::get_settings();
                        if ( ! isset( $settings['enable_newsletter'] ) || (int) $settings['enable_newsletter'] !== 1 ) {
                                return false;
                        }

                        /* ---------- Articoli degli ultimi 7 giorni (filtro in PHP) ---------- */
                        $recent = array();
                        if ( method_exists( 'Nexus_DB', 'articles' ) ) {
                                $articles = Nexus_DB::articles(
                                        array(
                                                'status'   => 'published',
                                                'per_page' => 20,
                                                'orderby'  => 'published_at',
                                                'order'    => 'desc',
                                        )
                                );

                                $cutoff = time() - 7 * DAY_IN_SECONDS;
                                foreach ( (array) $articles as $article ) {
                                        $ts = ( ! empty( $article->published_at ) ) ? strtotime( (string) $article->published_at ) : 0;
                                        if ( $ts && $ts >= $cutoff ) {
                                                $recent[] = $article;
                                        }
                                }
                        }

                        if ( ! $recent ) {
                                return false;
                        }

                        /* ---------- Destinatari: recipients fissi + subscriber confermati ---------- */
                        $recipients = array();
                        foreach ( explode( ',', (string) ( $settings['newsletter_recipients'] ?? '' ) ) as $raw ) {
                                $email = sanitize_email( trim( (string) $raw ) );
                                if ( $email && is_email( $email ) ) {
                                        $recipients[ strtolower( $email ) ] = $email;
                                }
                        }

                        $subscribers = self::confirmed_subscribers();

                        if ( ! $recipients && ! $subscribers ) {
                                return false;
                        }

                        /* ---------- Lista articoli (titolo + link) ---------- */
                        $items = array();
                        foreach ( $recent as $article ) {
                                $items[] = array(
                                        'title'    => wp_strip_all_tags( (string) $article->title ),
                                        'url'      => self::article_link( $article ),
                                        'category' => (string) $article->category,
                                );
                        }

                        /* ---------- Testo digest: AI (enable_digest=1) con fallback manuale ---------- */
                        $intro_html = '';
                        if ( isset( $settings['enable_digest'] ) && (int) $settings['enable_digest'] === 1
                                && class_exists( 'Nexus_AI_Engine' ) && method_exists( 'Nexus_AI_Engine', 'chat' ) ) {

                                $titles = array();
                                foreach ( $recent as $article ) {
                                        $cat = ( '' !== trim( (string) $article->category ) ) ? ' [' . (string) $article->category . ']' : '';
                                        $titles[] = '- ' . wp_strip_all_tags( (string) $article->title ) . $cat;
                                }

                                /* UNA SOLA chiamata AI: solo testo, niente JSON/markdown/link. */
                                $result = Nexus_AI_Engine::chat(
                                        array(
                                                array(
                                                        'role'    => 'system',
                                                        'content' => "Sei il caporedattore di una newsletter italiana di un giornale online. Rispondi SOLO con testo italiano semplice: niente JSON, niente markdown, niente elenchi puntati, niente link.",
                                                ),
                                                array(
                                                        'role'    => 'user',
                                                        'content' => "Scrivi il testo del digest settimanale della newsletter.\n"
                                                                . "Struttura obbligatoria: due frasi di introduzione, poi un breve paragrafo per ciascun articolo (titolo dell'articolo seguito da una frase di commento).\n"
                                                                . "Non inventare articoli, non aggiungere link, non usare markdown.\n\n"
                                                                . "Articoli pubblicati negli ultimi 7 giorni:\n" . implode( "\n", $titles ),
                                                ),
                                        ),
                                        array(
                                                'temperature' => 0.6,
                                                'max_tokens'  => 1200,
                                        )
                                );

                                if ( is_array( $result ) && ! empty( $result['ok'] ) && '' !== trim( (string) ( $result['content'] ?? '' ) ) ) {
                                        $intro_html = self::text_to_paragraphs( (string) $result['content'] );
                                }
                        }

                        /* Fallback: lista manuale titoli+link (o AI non disponibile/abilitata). */
                        if ( '' === $intro_html ) {
                                $intro_html = '<p style="margin:0 0 14px;line-height:1.6;">'
                                        . esc_html( sprintf( __( 'Ecco gli articoli pubblicati negli ultimi 7 giorni: %d in totale.', 'nexus-news-ai' ), count( $items ) ) )
                                        . '</p>';
                        }

                        /* ---------- Invio ---------- */
                        $subject = sprintf( __( 'Digest Nexus — settimana del %s', 'nexus-news-ai' ), date_i18n( 'd/m/Y' ) );
                        $headers = self::mail_headers();
                        $sent    = 0;

                        if ( $recipients ) {
                                $body = self::build_digest_html( $intro_html, $items, '' );
                                if ( wp_mail( array_values( $recipients ), $subject, $body, $headers ) ) {
                                        $sent += count( $recipients );
                                }
                        }

                        foreach ( $subscribers as $subscriber ) {
                                $email = sanitize_email( (string) $subscriber->email );
                                if ( ! $email || ! is_email( $email ) ) {
                                        continue;
                                }
                                $token = (string) ( $subscriber->token ?? '' );
                                $body  = self::build_digest_html( $intro_html, $items, $token );
                                if ( wp_mail( $email, $subject, $body, $headers ) ) {
                                        $sent++;
                                }
                        }

                        /* ---------- Log attività best-effort ---------- */
                        try {
                                if ( method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                                        Nexus_DB::insert_activity(
                                                0,
                                                'newsletter',
                                                sprintf( 'Digest inviato a %d destinatari', $sent ),
                                                $sent > 0 ? 'success' : 'error'
                                        );
                                }
                        } catch ( Exception $e ) {
                                /* Best-effort: mai bloccare il cron. */
                        } catch ( Throwable $e ) {
                                /* Best-effort: mai bloccare il cron. */
                        }

                        return $sent > 0;
                } catch ( Exception $e ) {
                        self::log_digest_error( $e->getMessage() );
                        return false;
                } catch ( Throwable $e ) {
                        self::log_digest_error( $e->getMessage() );
                        return false;
                }
        }

        /**
         * Log best-effort di un errore del digest (mai segreti, solo messaggio sintetico).
         *
         * @param string $message Messaggio errore.
         * @return void
         */
        private static function log_digest_error( $message ) {
                try {
                        if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                                Nexus_DB::insert_activity( 0, 'newsletter', 'Invio digest fallito: ' . sanitize_text_field( (string) $message ), 'error' );
                        }
                } catch ( Exception $e ) {
                        /* Silenzio. */
                } catch ( Throwable $e ) {
                        /* Silenzio. */
                }
        }

        /* ========================= DEATTIVAZIONE ========================= */

        /**
         * Deattivazione plugin: rimuove l'evento digest schedulato.
         * Disponibile per register_deactivation_hook nel bootstrap (opzionale).
         *
         * @return void
         */
        public static function deactivate() {
                wp_clear_scheduled_hook( self::HOOK_DIGEST );
        }

        /* ============ HELPER PRIVATI ============ */

        /**
         * Autosync schedule: enable_newsletter == 1 → schedula 'nexus_weekly_digest'
         * su 'weekly' se assente; enable_newsletter != 1 → rimuove l'evento se presente.
         * Chiamato a ogni init() (quindi a ogni richiesta): l'allineamento è immediato.
         *
         * @return void
         */
        private static function sync_schedule() {
                $enabled = false;

                if ( class_exists( 'Nexus_DB' ) ) {
                        $settings = Nexus_DB::get_settings();
                        $enabled  = isset( $settings['enable_newsletter'] ) && (int) $settings['enable_newsletter'] === 1;
                }

                if ( $enabled && ! wp_next_scheduled( self::HOOK_DIGEST ) ) {
                        wp_schedule_event( time() + self::FIRST_RUN_DELAY, 'weekly', self::HOOK_DIGEST );
                        return;
                }

                if ( ! $enabled && wp_next_scheduled( self::HOOK_DIGEST ) ) {
                        wp_clear_scheduled_hook( self::HOOK_DIGEST );
                }
        }

        /**
         * Email di conferma double opt-in con link ?nexus_confirm={token}.
         *
         * @param string $email Destinatario.
         * @param string $token Token dell'iscritto.
         * @return void
         */
        private static function send_confirmation_email( $email, $token ) {
                $confirm_url = home_url( '/?nexus_confirm=' . rawurlencode( $token ) );
                $site_name   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

                $subject = sprintf( __( 'Conferma la tua iscrizione a %s', 'nexus-news-ai' ), $site_name );

                $body  = '<div style="max-width:600px;margin:0 auto;padding:24px 16px;font-family:Arial,Helvetica,sans-serif;color:#111827;background:#ffffff;">';
                $body .= '<h2 style="font-size:18px;margin:0 0 12px;">' . esc_html( sprintf( __( "Un ultimo passo per completare l'iscrizione a %s", 'nexus-news-ai' ), get_bloginfo( 'name' ) ) ) . '</h2>';
                $body .= '<p style="line-height:1.6;">' . esc_html__( 'Ciao! Abbiamo ricevuto una richiesta di iscrizione alla newsletter da questo indirizzo email. Per confermarla clicca il pulsante qui sotto.', 'nexus-news-ai' ) . '</p>';
                $body .= '<p style="text-align:center;margin:24px 0;"><a href="' . esc_url( $confirm_url ) . '" style="display:inline-block;background:#4f46e5;color:#ffffff;padding:12px 24px;border-radius:8px;font-weight:bold;text-decoration:none;">' . esc_html__( 'Conferma iscrizione', 'nexus-news-ai' ) . '</a></p>';
                $body .= '<p style="font-size:13px;color:#4b5563;line-height:1.6;">' . esc_html__( 'Se il pulsante non funziona, copia e incolla questo link nel browser:', 'nexus-news-ai' ) . '<br />' . esc_html( $confirm_url ) . '</p>';
                $body .= '<p style="font-size:12px;color:#6b7280;line-height:1.6;">' . esc_html__( 'Se non hai richiesto questa iscrizione ignora questa email: nessun account verrà creato.', 'nexus-news-ai' ) . '</p>';
                $body .= '</div>';

                wp_mail( $email, $subject, $body, self::mail_headers() );
        }

        /**
         * Headers email: HTML UTF-8 + From con nome sito e admin email.
         *
         * @return array
         */
        private static function mail_headers() {
                $from_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
                $from_mail = get_bloginfo( 'admin_email' );

                return array(
                        'Content-Type: text/html; charset=UTF-8',
                        'From: ' . $from_name . ' <' . $from_mail . '>',
                );
        }

        /**
         * Link articolo: post WordPress reale se wp_post_id > 0, altrimenti
         * fallback legacy /?nexus_article={id}.
         *
         * @param object $article Riga tabella nexus_articles.
         * @return string
         */
        private static function article_link( $article ) {
                $wp_post_id = isset( $article->wp_post_id ) ? absint( $article->wp_post_id ) : 0;

                if ( $wp_post_id > 0 ) {
                        return home_url( '/?p=' . $wp_post_id );
                }

                return home_url( '/?nexus_article=' . absint( $article->id ) );
        }

        /**
         * Tutti gli iscritti status 'confirmed' (paginazione a blocchi di 500,
         * limite difensivo 20 pagine ≈ 10.000 iscritti).
         *
         * @return array
         */
        private static function confirmed_subscribers() {
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'subscribers' ) ) {
                        return array();
                }

                $all   = array();
                $limit = 500;

                for ( $page = 1; $page <= 20; $page++ ) {
                        $rows = Nexus_DB::subscribers(
                                array(
                                        'status' => 'confirmed',
                                        'limit'  => $limit,
                                        'offset' => ( $page - 1 ) * $limit,
                                )
                        );

                        if ( ! $rows ) {
                                break;
                        }

                        $all = array_merge( $all, $rows );

                        if ( count( $rows ) < $limit ) {
                                break;
                        }
                }

                return $all;
        }

        /**
         * Corpo HTML del digest (inline-style, max-width 600px).
         *
         * @param string $intro_html Paragrafi di introduzione (già HTML sicuro).
         * @param array  $items      Lista articoli: title, url, category.
         * @param string $token      Token subscriber per il footer di disiscrizione ('' = footer generico).
         * @return string
         */
        private static function build_digest_html( $intro_html, $items, $token ) {
                $out  = '';
                $out .= '<div style="max-width:600px;margin:0 auto;padding:24px 16px;font-family:Arial,Helvetica,sans-serif;color:#111827;background:#ffffff;">';
                $out .= '<h1 style="font-size:20px;line-height:1.3;margin:0 0 16px;">📰 ' . esc_html__( 'Digest settimanale', 'nexus-news-ai' ) . ' — ' . esc_html( get_bloginfo( 'name' ) ) . '</h1>';

                $out .= $intro_html;

                if ( $items ) {
                        $out .= '<ul style="list-style:none;margin:16px 0;padding:0;">';
                        foreach ( $items as $item ) {
                                $out .= '<li style="margin:0 0 12px;padding:0 0 12px;border-bottom:1px solid #e5e7eb;">';
                                $out .= '<a href="' . esc_url( $item['url'] ) . '" style="color:#4f46e5;font-weight:bold;font-size:15px;text-decoration:none;">' . esc_html( $item['title'] ) . '</a>';
                                if ( '' !== trim( (string) $item['category'] ) ) {
                                        $out .= '<span style="display:block;color:#6b7280;font-size:12px;margin-top:2px;">' . esc_html( $item['category'] ) . '</span>';
                                }
                                $out .= '</li>';
                        }
                        $out .= '</ul>';
                }

                $out .= '<hr style="border:none;border-top:1px solid #e5e7eb;margin:20px 0;" />';

                if ( '' !== $token ) {
                        $unsub_url = home_url( '/?nexus_unsub=' . rawurlencode( $token ) );
                        $out      .= '<p style="font-size:12px;color:#6b7280;line-height:1.6;margin:0;">'
                                . esc_html__( 'Ricevi questa email perché sei iscritto alla newsletter.', 'nexus-news-ai' ) . ' '
                                . '<a href="' . esc_url( $unsub_url ) . '" style="color:#6b7280;">' . esc_html__( 'Disiscriviti', 'nexus-news-ai' ) . '</a>.'
                                . '</p>';
                } else {
                        $out .= '<p style="font-size:12px;color:#6b7280;line-height:1.6;margin:0;">'
                                . esc_html( sprintf( __( 'Ricevi questa email come destinatario fisso della newsletter di %s.', 'nexus-news-ai' ), get_bloginfo( 'name' ) ) )
                                . '</p>';
                }

                $out .= '</div>';

                return $out;
        }

        /**
         * Converte testo AI (solo testo) in paragrafi HTML sicuri:
         * rimuove i tag, divide su righe vuote, escape + <br> per gli a-capo interni.
         *
         * @param string $text Testo AI.
         * @return string HTML (vuoto se testo vuoto).
         */
        private static function text_to_paragraphs( $text ) {
                $text = trim( wp_strip_all_tags( (string) $text ) );

                if ( '' === $text ) {
                        return '';
                }

                $blocks = preg_split( '/\n{2,}/', $text );
                $out    = '';

                foreach ( (array) $blocks as $block ) {
                        $block = trim( (string) $block );
                        if ( '' === $block ) {
                                continue;
                        }
                        $out .= '<p style="margin:0 0 14px;line-height:1.6;">' . nl2br( esc_html( $block ) ) . '</p>';
                }

                return $out;
        }

        /**
         * Pagina minimale di conferma/disiscrizione: doctype + html con wp_head e
         * wp_footer, messaggio centrale, link al sito. Termina con exit.
         *
         * @param string $title  Titolo pagina (H1).
         * @param string $message Messaggio centrale.
         * @param int    $status  Codice HTTP (200/404/503).
         * @return void
         */
        private static function render_notice_page( $title, $message, $status = 200 ) {
                nocache_headers();
                status_header( (int) $status );

                ob_start();
                wp_head();
                $head_html = ob_get_clean();

                ob_start();
                wp_footer();
                $footer_html = ob_get_clean();

                $lang        = get_language_attributes();
                $charset     = get_bloginfo( 'charset' );
                $site_name   = get_bloginfo( 'name' );
                $page_title  = esc_html( (string) $title );
                $page_msg    = esc_html( (string) $message );
                $site_url    = esc_url( home_url( '/' ) );
                $back_label  = esc_html__( 'Torna al sito', 'nexus-news-ai' );

                echo <<<HTML
<!DOCTYPE html>
<html {$lang}>
<head>
<meta charset="{$charset}" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>{$page_title} — {$site_name}</title>
{$head_html}
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f4f5fb;color:#1f2937;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;}
.nexus-news-notice{max-width:440px;margin:24px;padding:40px 28px;text-align:center;background:#ffffff;border-radius:14px;box-shadow:0 12px 32px rgba(17,24,39,0.10);}
.nexus-news-notice h1{margin:0 0 12px;font-size:22px;line-height:1.3;}
.nexus-news-notice p{margin:0 0 24px;font-size:15px;line-height:1.6;color:#4b5563;}
.nexus-news-notice a{color:#4f46e5;font-weight:700;text-decoration:none;}
</style>
</head>
<body class="nexus-news-notice-page">
<div class="nexus-news-notice">
<h1>{$page_title}</h1>
<p>{$page_msg}</p>
<p><a href="{$site_url}">{$back_label}</a></p>
</div>
{$footer_html}
</body>
</html>
HTML;
                exit;
        }
}
