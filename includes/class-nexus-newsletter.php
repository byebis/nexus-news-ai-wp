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
 *   articles, get_settings, insert_activity, usage_stats) e Nexus_AI_Engine::chat.
 *
 * v1.1.14 (P5) — digest settimanale potenziato:
 * - Nexus_Digest (richiesto qui, vedi file) aggiunge all'email le sezioni
 *   performance / uso AI / errori, SOLO se i dati esistono (mai blocchi finti);
 * - OPT-IN DIGEST su nexus_subscribers: migration idempotente di questa classe
 *   aggiunge la colonna digest_optin TINYINT(1) NOT NULL DEFAULT 1 (la conferma
 *   double opt-in promette esplicitamente il digest settimanale, quindi gli
 *   iscritti confermati sono già opt-in; il flag permette di rinunciare al SOLO
 *   digest via link footer ?nexus_unsub={token}&nexus_digest=0 senza
 *   disiscriversi). L'invio salta chi ha digest_optin = 0 (NIENTE invio a chi
 *   non ha optato); colonna assente (migrazione fallita) → comportamento legacy.
 *   NOTA coordinatore: la colonna NON è in Nexus_DB::schema() (file fuori
 *   perimetro P5): dbDelta PRESERVA le colonne extra; da aggiungere allo schema
 *   in una release futura per uniformità;
 * - destinatari fissi del digest = newsletter_recipients + copia garantita
 *   (preferenza admin Nexus_Digest, default email amministratore);
 * - anteprima: Nexus_Digest::handle_preview (admin_post, capability+nonce) usa
 *   Nexus_Newsletter::build_preview_email() e mail_headers() (ora pubbliche):
 *   l'UNICO invio fuori dal cron, sempre da azione admin esplicita.
 * - Il cron NON cambia orari né hook: resta 'nexus_weekly_digest' su 'weekly'.
 *
 * Sicurezza (v1.1.9, WP2):
 * - TOKEN HASHATI: in DB viaggia SOLO lo sha256 hex (64 char, colonna VARCHAR(64))
 *   del token casuale; il link email usa il token in CHIARO (mai persistito).
 *   get_subscriber_by_token è chiamata con l'hash (find_subscriber_by_token gestisce
 *   il fallback in chiaro per i token legacy, con ri-hash alla prima occasione).
 * - TTL conferma: i link ?nexus_confirm valgono SOLO per iscritti pending creati
 *   da ≤ 7 giorni (confronto su created_at); scaduto → errore onesto + invito a
 *   re-iscriversi. La rotazione del token avviene a ogni re-invio.
 * - ANTI-ENUMERATION: la risposta della rotta /subscribe è uniforme per iscritto
 *   nuovo / già iscritto (messaggio generico, niente indizi sull'esistenza dell'email).
 * - wp_mail fallita nell'invio conferma → log attività onesto (status 'error').
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

/* v1.1.14 (P5): builder dei contenuti extra del digest + handler admin
 * (preferenze + anteprima). Richiesto QUI perché il bootstrap principale
 * nexus-news-ai.php è fuori dal perimetro dell'agente digest. */
require_once __DIR__ . '/class-nexus-digest.php';

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
         * TTL del link di conferma double opt-in (7 giorni, v1.1.9): valgono SOLO
         * per iscritti 'pending' con created_at entro questa finestra.
         */
        const CONFIRM_TTL = 604800; // 7 * DAY_IN_SECONDS.

        /**
         * Flag opzione della migration della colonna digest_optin (v1.1.14, P5):
         * opzione WP 'nexus_digest_optin_v1' impostata dopo il primo check/ALTER
         * riuscito (zero costo a regime; fallimento = flag NON impostato → retry).
         */
        const OPTIN_MIGRATION_FLAG = 'nexus_digest_optin_v1';

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

                /* v1.1.14 (P5): handler admin del digest (preferenze + anteprima). */
                if ( class_exists( 'Nexus_Digest' ) && method_exists( 'Nexus_Digest', 'init' ) ) {
                        Nexus_Digest::init();
                }

                /* v1.1.14 (P5): migration idempotente colonna digest_optin (flag opzione: zero costo a regime). */
                self::ensure_digest_optin_column();

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
         * (transient 'nexus_sub_rate_' . md5(ip)) → insert_subscriber → ROTAZIONE
         * token (v1.1.9: nuovo token casuale, in DB solo lo sha256) → email di
         * conferma con link home_url('/?nexus_confirm={token in chiaro}').
         *
         * ANTI-ENUMERATION (v1.1.9): la risposta è UNIFORME per iscritto nuovo,
         * già iscritto confermato o pending: stesso messaggio generico, nessun
         * indizio sull'esistenza dell'email. Se l'email è già confermata NON
         * viene inviata alcuna email (nessun buzz ripetuto), il messaggio resta
         * comunque quello generico.
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
                $already  = ( $existing && 'confirmed' === (string) $existing->status );

                $id = Nexus_DB::insert_subscriber( $email );
                if ( $id <= 0 ) {
                        return new WP_Error(
                                'nexus_newsletter_failed',
                                __( 'Iscrizione non riuscita: riprova più tardi.', 'nexus-news-ai' ),
                                array( 'status' => 500 )
                        );
                }

                /* v1.1.9: token hashato nel DB. insert_subscriber genera ancora un token
                 * in chiaro (db.php non toccato): lo ruota SUBITO qui — nuovo token
                 * casuale, in DB solo lo sha256, il chiaro vive solo nel link email. */
                $mail_sent = true;
                if ( ! $already ) {
                        $clear_token = self::new_token();
                        $saved       = Nexus_DB::update_subscriber( (int) $id, array( 'token' => self::token_hash( $clear_token ) ) );
                        if ( $saved ) {
                                $mail_sent = self::send_confirmation_email( $email, $clear_token );
                        }
                }

                /* Invio fallito → log attività onesto (mai silenzio, mai PII nel log). */
                if ( ! $mail_sent ) {
                        self::log_newsletter_error( 'Invio dell\'email di conferma iscrizione fallito (verifica la configurazione mail del sito).' );
                }

                /* ANTI-ENUMERATION: risposta identica per nuovo / pending / già confermato. */
                return rest_ensure_response(
                        array(
                                'ok'      => true,
                                'message' => __( "Se l'email è valida riceverai a breve la conferma.", 'nexus-news-ai' ),
                        )
                );
        }

        /**
         * Handler POST /nexus/v1/unsubscribe.
         *
         * Parametro 'token' (il link con ?nexus_unsub={token} resta la via utente):
         * token valido → status 'unsubscribed'; token invalido → 404. La lookup usa
         * find_subscriber_by_token (v1.1.9: prima l'hash sha256, poi fallback in
         * chiaro per i token legacy con migrazione morbida). Nessun TTL: la
         * disiscrizione resta sempre possibile.
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
                $subscriber = ( '' !== $token ) ? self::find_subscriber_by_token( $token ) : null;

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
                        // v1.1.9: lookup con hash (fallback in chiaro per i token legacy).
                        $subscriber = self::find_subscriber_by_token( $confirm );

                        if ( ! $subscriber ) {
                                self::render_notice_page(
                                        __( 'Link non valido', 'nexus-news-ai' ),
                                        __( 'Il link di conferma non è valido o non esiste più.', 'nexus-news-ai' ),
                                        404
                                );
                        }

                        // TTL (v1.1.9): il link di conferma vale SOLO per iscritti 'pending'
                        // creati da ≤ 7 giorni. Scaduto → errore onesto + invito a re-iscriversi:
                        // il link scaduto NON conferma nulla, ripetendo l'iscrizione arriva
                        // un nuovo link con token ruotato.
                        if ( 'confirmed' !== (string) $subscriber->status && self::confirm_link_expired( $subscriber ) ) {
                                self::render_notice_page(
                                        __( 'Link scaduto', 'nexus-news-ai' ),
                                        __( 'Il link di conferma è scaduto (vale 7 giorni). Ripeti l\'iscrizione dalla pagina del sito per ricevere un nuovo link.', 'nexus-news-ai' ),
                                        410
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
                // v1.1.9: lookup con hash (fallback in chiaro per i token legacy). Nessun
                // TTL: la disiscrizione deve restare sempre possibile.
                $subscriber = self::find_subscriber_by_token( $unsub );

                if ( ! $subscriber ) {
                        self::render_notice_page(
                                __( 'Link non valido', 'nexus-news-ai' ),
                                __( 'Il link di disiscrizione non è valido o non esiste più.', 'nexus-news-ai' ),
                                404
                        );
                }

                $already = ( 'unsubscribed' === (string) $subscriber->status );

                /* ---------- v1.1.14 (P5): gestione SOLO-digest ----------
                 * Parametro AGGIUNTO al link di disiscrizione esistente
                 * (?nexus_unsub={token}&nexus_digest=0|1): NON disiscrive, aggiorna
                 * solo la preferenza digest (digest_optin) e mostra la pagina di
                 * esito. La disiscrizione completa resta il percorso predefinito
                 * (link senza parametro, rotta REST e flow storici invariati). */
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- il token È la credenziale (come nexus_unsub).
                $digest_pref = isset( $_GET['nexus_digest'] ) ? sanitize_text_field( wp_unslash( $_GET['nexus_digest'] ) ) : '';
                if ( '0' === $digest_pref || '1' === $digest_pref ) {
                        $updated = self::set_subscriber_digest_optin( (int) $subscriber->id, '1' === $digest_pref );

                        if ( ! $updated ) {
                                self::render_notice_page(
                                        __( 'Servizio non disponibile', 'nexus-news-ai' ),
                                        __( 'Impossibile aggiornare la preferenza digest: riprova più tardi.', 'nexus-news-ai' ),
                                        503
                                );
                        }

                        self::render_notice_page(
                                __( 'Preferenze aggiornate', 'nexus-news-ai' ),
                                '1' === $digest_pref
                                        ? __( 'Da ora riceverai di nuovo il digest settimanale.', 'nexus-news-ai' )
                                        : __( 'Da ora non riceverai più il digest settimanale: la tua iscrizione alla newsletter resta attiva.', 'nexus-news-ai' ),
                                200
                        );
                }

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
         * - articoli 'published' (ultimi 20) → filtro in PHP su published_at ultimi 7 giorni
         *   (recent_digest_articles(), estratto in v1.1.14 per il riuso nell'anteprima);
         * - 0 articoli → log attività 'Digest saltato: nessun articolo' (v1.1.15 WE5:
         *   niente più fallimenti silenziosi) + false;
         * - destinatari: newsletter_recipients (comma, sanitize_email, UNIQUE) + copia
         *   garantita (v1.1.14: preferenza admin Nexus_Digest, default admin_email) +
         *   subscriber 'confirmed' CON digest_optin = 1 (v1.1.14, P5: niente invio a
         *   chi ha rinunciato al solo digest) → nessuno → log 'Digest saltato:
         *   nessun destinatario' (v1.1.15 WE5) + false;
         * - enable_digest == 1 → UNA chiamata Nexus_AI_Engine::chat (solo testo IT:
         *   intro 2 frasi + un paragrafo per articolo), con fallback lista manuale
         *   titoli+link se la chat fallisce o non è disponibile;
         * - v1.1.14: sezioni extra (performance / uso AI / errori) via Nexus_Digest,
         *   SOLO se i dati esistono; il cron NON cambia orari né hook;
         * - wp_mail HTML (max-width 600px): footer con link disiscrizione per-subscriber
         *   (?nexus_unsub={token}) + link gestione solo-digest (?nexus_unsub={token}
         *   &nexus_digest=0), footer generico per i destinatari fissi;
         * - v1.1.15 (WE5): OGNI wp_mail false viene loggato con le email MASCHERATE
         *   (mask_email: 'm***@dominio.tld', niente PII completa) e il contatore
         *   $failed entra nel log finale (' — invii falliti: N' solo quando > 0:
         *   la riga storica 'Digest inviato a N destinatari' resta identica altrimenti);
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
                        $recent = self::recent_digest_articles();

                        if ( ! $recent ) {
                                // v1.1.15 (WE5): lo skip NON è più silenzioso — una riga onesta
                                // su activity log (canale 'newsletter' esistente, niente schema nuovo).
                                self::log_digest_notice( 'Digest saltato: nessun articolo' );
                                return false;
                        }

                        /* ---------- Destinatari: recipients fissi + copia admin + subscriber opt-in digest ---------- */
                        $recipients  = self::fixed_digest_recipients( $settings );
                        $subscribers = self::digest_subscribers();

                        if ( ! $recipients && ! $subscribers ) {
                                // v1.1.15 (WE5): anche lo skip 0 destinatari lascia traccia in log.
                                self::log_digest_notice( 'Digest saltato: nessun destinatario' );
                                return false;
                        }

                        /* ---------- Lista articoli (titolo + link) ---------- */
                        $items = self::digest_items( $recent );

                        /* ---------- Testo digest: AI (enable_digest=1) con fallback manuale ---------- */
                        $intro_html = self::digest_intro_html( $recent, $settings );

                        /* ---------- Sezioni extra (v1.1.14, P5): performance / uso AI / errori ----------
                         * Costruite UNA volta per tutti i destinatari; ogni sezione esiste
                         * solo se i dati esistono (onestà, vedi Nexus_Digest::sections_html). */
                        $sections_html = '';
                        if ( class_exists( 'Nexus_Digest' ) && method_exists( 'Nexus_Digest', 'sections_html' ) ) {
                                $sections_html = (string) Nexus_Digest::sections_html();
                        }

                        /* ---------- Invio ---------- */
                        $subject = sprintf( __( 'Digest Nexus — settimana del %s', 'nexus-news-ai' ), date_i18n( 'd/m/Y' ) );
                        $headers = self::mail_headers();
                        $sent    = 0;
                        $failed  = 0; // v1.1.15 (WE5): invii falliti, riportati nel log finale.

                        if ( $recipients ) {
                                $body = self::build_digest_html( $intro_html, $items, '', $sections_html );
                                if ( wp_mail( array_values( $recipients ), $subject, $body, $headers ) ) {
                                        $sent += count( $recipients );
                                } else {
                                        // v1.1.15 (WE5): fallimento wp_mail NON più silenzioso — le email
                                        // dei destinatari fissi vengono loggate SOLO mascherate (mask_email).
                                        $masked = array();
                                        foreach ( array_values( $recipients ) as $email ) {
                                                $masked[] = self::mask_email( $email );
                                        }
                                        self::log_digest_notice( 'Digest: invio fallito a ' . implode( ', ', $masked ) );
                                        $failed += count( $recipients );
                                }
                        }

                        foreach ( $subscribers as $subscriber ) {
                                $email = sanitize_email( (string) $subscriber->email );
                                if ( ! $email || ! is_email( $email ) ) {
                                        continue;
                                }
                                // v1.1.9: il token persistito è l'sha256 (i link di disiscrizione
                                // restano validi: find_subscriber_by_token matcha per uguaglianza
                                // diretta sull'hash senza riscriverlo).
                                $token   = (string) ( $subscriber->token ?? '' );
                                $unsub   = '' !== $token ? home_url( '/?nexus_unsub=' . rawurlencode( $token ) ) : '';
                                $body    = self::build_digest_html( $intro_html, $items, $token, $sections_html );
                                if ( wp_mail( $email, $subject, $body, self::mail_headers( $unsub ) ) ) {
                                        $sent++;
                                } else {
                                        // v1.1.15 (WE5): fallimento loggato con email MASCHERATA
                                        // (m***@dominio.tld): niente PII completa nei log.
                                        self::log_digest_notice( 'Digest: invio fallito a ' . self::mask_email( $email ) );
                                        $failed++;
                                }
                        }

                        /* ---------- Log attività best-effort ---------- */
                        try {
                                if ( method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                                        $detail = sprintf( 'Digest inviato a %d destinatari', $sent );

                                        // v1.1.15 (WE5): il contatore dei falliti entra nel log finale SOLO
                                        // quando serve (suffisso additivo: la riga storica resta identica
                                        // quando $failed = 0, il pattern i18n esistente non si rompe).
                                        if ( $failed > 0 ) {
                                                $detail .= sprintf( ' — invii falliti: %d', $failed );
                                        }

                                        Nexus_DB::insert_activity(
                                                0,
                                                'newsletter',
                                                $detail,
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

        /**
         * v1.1.15 (WE5) — log attività di un DIGEST SALTATO o di un invio fallito:
         * STESSA via del log esistente del modulo (Nexus_DB::insert_activity,
         * action 'newsletter' — nessuna modifica allo schema, nessun canale nuovo).
         * Messaggio ripulito e tagliato a 200 caratteri; le email nei messaggi
         * arrivano GIÀ mascherate (mask_email): nessuna PII completa nel log.
         * Status 'info' per gli skip (non sono errori), il chiamante decide.
         *
         * @param string $message Messaggio (email già mascherate).
         * @return void
         */
        private static function log_digest_notice( $message ) {
                try {
                        if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                                $message = sanitize_text_field( (string) $message );
                                $message = function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 200 ) : substr( $message, 0, 200 );

                                Nexus_DB::insert_activity( 0, 'newsletter', $message, 'info' );
                        }
                } catch ( Exception $e ) {
                        /* Silenzio: il log non deve mai bloccare il cron. */
                } catch ( Throwable $e ) {
                        /* Silenzio: il log non deve mai bloccare il cron. */
                }
        }

        /**
         * v1.1.15 (WE5) — maschera un indirizzo email per i log (es.
         * 'mario.rossi@esempio.it' → 'm***@esempio.it'): primo carattere della
         * parte locale + '***' + dominio. Formato non riconoscibile → segnaposto
         * senza PII ('[email]').
         *
         * @param string $email Indirizzo email.
         * @return string Email mascherata.
         */
        private static function mask_email( $email ) {
                $email = trim( (string) $email );
                $at    = strrpos( $email, '@' );

                if ( false === $at || 0 === $at || $at + 1 >= strlen( $email ) ) {
                        return '[email]';
                }

                return substr( $email, 0, 1 ) . '***@' . substr( $email, $at + 1 );
        }

        /* ============ BLOCCHI DEL DIGEST (v1.1.14, P5 — estratti per il riuso nell'anteprima) ============ */

        /**
         * Articoli 'published' degli ultimi 7 giorni (ultimi 20 per published_at,
         * filtro in PHP su published_at). Estratto da send_weekly_digest in
         * v1.1.14: comportamento IDENTICO al percorso cron storico, riusato da
         * Nexus_Newsletter::build_preview_email per l'anteprima admin.
         *
         * @return array
         */
        private static function recent_digest_articles() {
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'articles' ) ) {
                        return array();
                }

                $articles = Nexus_DB::articles(
                        array(
                                'status'   => 'published',
                                'per_page' => 20,
                                'orderby'  => 'published_at',
                                'order'    => 'desc',
                        )
                );

                $cutoff = time() - 7 * DAY_IN_SECONDS;
                $recent = array();
                foreach ( (array) $articles as $article ) {
                        $ts = ( ! empty( $article->published_at ) ) ? strtotime( (string) $article->published_at ) : 0;
                        if ( $ts && $ts >= $cutoff ) {
                                $recent[] = $article;
                        }
                }

                return $recent;
        }

        /**
         * Destinatari FISSI del digest (v1.1.14, P5): le email di
         * newsletter_recipients + la copia garantita al gestore del sito
         * (preferenza Nexus_Digest::admin_copy_recipient, default email
         * amministratore). Dedup per email lowercase.
         *
         * @param array $settings Impostazioni Nexus_DB::get_settings().
         * @return array Mappa lowercase => email.
         */
        private static function fixed_digest_recipients( $settings ) {
                $recipients = array();
                foreach ( explode( ',', (string) ( isset( $settings['newsletter_recipients'] ) ? $settings['newsletter_recipients'] : '' ) ) as $raw ) {
                        $email = sanitize_email( trim( (string) $raw ) );
                        if ( $email && is_email( $email ) ) {
                                $recipients[ strtolower( $email ) ] = $email;
                        }
                }

                /* Copia garantita (v1.1.14): l'admin vede sempre cosa ricevono gli iscritti. */
                if ( class_exists( 'Nexus_Digest' ) && method_exists( 'Nexus_Digest', 'admin_copy_recipient' ) ) {
                        $copy = Nexus_Digest::admin_copy_recipient();
                        if ( $copy && is_email( $copy ) ) {
                                $recipients[ strtolower( $copy ) ] = $copy;
                        }
                }

                return $recipients;
        }

        /**
         * Iscritti destinatari del DIGEST (v1.1.14, P5): status 'confirmed' CON
         * flag digest_optin attivo (colonna aggiunta da ensure_digest_optin_column).
         *
         * Semantica dell'opt-in: la conferma double opt-in promette ESPlicitamente
         * il digest settimanale ("Da ora riceverai il digest settimanale..."), quindi
         * l'opt-in per il digest ESISTE già per ogni iscritto confermato e la colonna
         * nasce a 1; il flag serve per RINUNCIARE al solo digest senza disiscriversi
         * (link "Non ricevere il digest" nel footer + ?nexus_unsub={token}&nexus_digest=0).
         * NIENTE invio a chi ha digest_optin = 0. Colonna assente (migrazione
         * fallita): comportamento legacy documentato (tutti i confermati).
         *
         * @return array
         */
        public static function digest_subscribers() {
                $all = self::confirmed_subscribers();
                if ( ! $all ) {
                        return array();
                }

                $out = array();
                foreach ( $all as $subscriber ) {
                        $optin = isset( $subscriber->digest_optin ) ? (int) $subscriber->digest_optin : 1;
                        if ( 1 === $optin ) {
                                $out[] = $subscriber;
                        }
                }

                return $out;
        }

        /**
         * Lista articoli per l'email digest: title, url, category.
         *
         * @param array $recent Righe nexus_articles 'published' della settimana.
         * @return array
         */
        private static function digest_items( $recent ) {
                $items = array();
                foreach ( (array) $recent as $article ) {
                        $items[] = array(
                                'title'    => wp_strip_all_tags( (string) $article->title ),
                                'url'      => self::article_link( $article ),
                                'category' => (string) $article->category,
                        );
                }

                return $items;
        }

        /**
         * Introduzione del digest: testo AI (enable_digest=1) con fallback
         * manuale. Estratta da send_weekly_digest in v1.1.14 (comportamento
         * identico; in più con 0 articoli l'AI NON viene chiamata e il fallback
         * è una nota onesta — raggiungibile solo dall'anteprima, perché il cron
         * esce prima con 0 articoli).
         *
         * @param array $recent   Righe articoli della settimana.
         * @param array $settings Impostazioni Nexus_DB::get_settings().
         * @return string HTML sicuro ('' mai: c'è sempre il fallback).
         */
        private static function digest_intro_html( $recent, $settings ) {
                $items = self::digest_items( $recent );

                $intro_html = '';
                if ( $recent && isset( $settings['enable_digest'] ) && (int) $settings['enable_digest'] === 1
                        && class_exists( 'Nexus_AI_Engine' ) && method_exists( 'Nexus_AI_Engine', 'chat' ) ) {

                        $titles = array();
                        foreach ( (array) $recent as $article ) {
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

                        if ( is_array( $result ) && ! empty( $result['ok'] ) && '' !== trim( (string) ( isset( $result['content'] ) ? $result['content'] : '' ) ) ) {
                                $intro_html = self::text_to_paragraphs( (string) $result['content'] );
                        }
                }

                /* Fallback: lista manuale titoli+link (o AI non disponibile/abilitata). */
                if ( '' === $intro_html ) {
                        if ( ! $items ) {
                                /* Solo anteprima (il cron esce prima con 0 articoli): nota onesta. */
                                $intro_html = '<p style="margin:0 0 14px;line-height:1.6;">'
                                        . esc_html__( 'Nessun articolo pubblicato negli ultimi 7 giorni: il digest reale non viene inviato.', 'nexus-news-ai' )
                                        . '</p>';
                        } else {
                                $intro_html = '<p style="margin:0 0 14px;line-height:1.6;">'
                                        . esc_html( sprintf( __( 'Ecco gli articoli pubblicati negli ultimi 7 giorni: %d in totale.', 'nexus-news-ai' ), count( $items ) ) )
                                        . '</p>';
                        }
                }

                return $intro_html;
        }

        /**
         * Oggetto + corpo dell'ANTEPRIMA digest (v1.1.14, P5) per
         * Nexus_Digest::handle_preview (admin_post, capability+nonce).
         * Stessi blocchi del digest reale (intro AI se abilitata, articoli,
         * sezioni extra) con banner ANTEPRIMA e footer generico (nessun link
         * per-subscriber). A differenza del cron NON esce con 0 articoli: mostra
         * la nota onesta, così l'admin vede come sarà l'email.
         *
         * @param array $settings Impostazioni Nexus_DB::get_settings().
         * @return array{subject:string, body:string}
         */
        public static function build_preview_email( $settings ) {
                $recent   = self::recent_digest_articles();
                $items    = self::digest_items( $recent );
                $intro    = self::digest_intro_html( $recent, $settings );
                $sections = ( class_exists( 'Nexus_Digest' ) && method_exists( 'Nexus_Digest', 'sections_html' ) ) ? (string) Nexus_Digest::sections_html() : '';
                $subject  = sprintf( __( 'Digest Nexus — settimana del %s', 'nexus-news-ai' ), date_i18n( 'd/m/Y' ) );

                return array(
                        'subject' => sprintf( __( 'Anteprima — %s', 'nexus-news-ai' ), $subject ),
                        'body'    => self::build_digest_html( $intro, $items, '', $sections, true ),
                );
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

        /* ============ TOKEN E LOOKUP (v1.1.9) ============ */

        /**
         * Genera un token casuale per i link double opt-in.
         *
         * 64 caratteri hex da 32 byte random (random_bytes); se la funzione non
         * è disponibile (PHP < 7.0 o esotiche build) fallback wp_generate_password
         * alfanumerico. Il token in CHIARO vive solo nel link email: nel DB va
         * SEMPRE salvato token_hash( $token ).
         *
         * @return string Token in chiaro (64 caratteri alfanumerici).
         */
        private static function new_token() {
                try {
                        return bin2hex( random_bytes( 32 ) );
                } catch ( Exception $e ) {
                        return wp_generate_password( 64, false, false );
                } catch ( Throwable $e ) {
                        return wp_generate_password( 64, false, false );
                }
        }

        /**
         * Hash del token per la persistenza: sha256 hex (64 char, sta in VARCHAR(64)).
         *
         * @param string $token Token in chiaro.
         * @return string
         */
        private static function token_hash( $token ) {
                return hash( 'sha256', (string) $token );
        }

        /**
         * Cerca un iscritto a partire dal token IN CHIARO di un link.
         *
         * Ordine (v1.1.9): 1) lookup per sha256 del token (token hashati); 2) fallback
         * per token in chiaro (righe legacy precedenti alla migrazione); in caso di
         * match legacy il token viene RI-HASHATO e ri-salvato (migrazione morbida,
         * il token in chiaro nella riga non resta esposto più a lungo del necessario).
         *
         * Nota: se il token fornito È già un hash valido (64 hex) e coincide con la
         * riga (caso dei link digest che portano l'hash), il match avviene per
         * uguaglianza diretta e la riga NON viene riscritta (nessun hash dell'hash).
         *
         * @param string $clear_token Token in chiaro dal link (o hash nei link digest).
         * @return object|null Riga iscritto oppure null.
         */
        private static function find_subscriber_by_token( $clear_token ) {
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'get_subscriber_by_token' ) ) {
                        return null;
                }

                $clear_token = trim( (string) $clear_token );
                if ( '' === $clear_token ) {
                        return null;
                }

                // 1) Percorso nuovo: lookup per hash del token in chiaro.
                $subscriber = Nexus_DB::get_subscriber_by_token( self::token_hash( $clear_token ) );
                if ( $subscriber ) {
                        return $subscriber;
                }

                // 2) Migrazione morbida: token legacy salvato in chiaro.
                $legacy = Nexus_DB::get_subscriber_by_token( $clear_token );
                if ( ! $legacy ) {
                        return null;
                }

                $stored = (string) ( $legacy->token ?? '' );
                $is_hash = ( 64 === strlen( $stored ) && ctype_xdigit( $stored ) );

                // Re-salva l'hash SOLO se il token fornito era il chiaro di una riga
                // legacy (32 char): se il match era per uguaglianza diretta con un hash
                // (link digest) riscrivere corromperebbe il token (hash dell'hash).
                if ( ! $is_hash && self::token_hash( $clear_token ) !== $stored ) {
                        Nexus_DB::update_subscriber( (int) $legacy->id, array( 'token' => self::token_hash( $clear_token ) ) );
                }

                return $legacy;
        }

        /**
         * Dice se il link di conferma è scaduto (v1.1.9): solo gli iscritti
         * 'pending' con created_at entro CONFIRM_TTL (7 giorni) possono confermare.
         *
         * @param object $subscriber Riga iscritto.
         * @return bool True se scaduto.
         */
        private static function confirm_link_expired( $subscriber ) {
                if ( ! $subscriber || 'pending' !== (string) $subscriber->status ) {
                        return false; // confermato/unsubscribed: il flusso gestisce lo stato, non il TTL.
                }

                $created = isset( $subscriber->created_at ) ? strtotime( (string) $subscriber->created_at ) : 0;
                if ( $created <= 0 ) {
                        // Data assente/malformata: per difetto il link è considerato scaduto
                        // (non c'è modo di valutare il TTL, meglio chiudere la porta).
                        return true;
                }

                return ( time() - $created ) > self::CONFIRM_TTL;
        }

        /**
         * Log attività best-effort per errori newsletter (onesto, senza PII).
         *
         * @param string $message Messaggio errore sintetico.
         * @return void
         */
        private static function log_newsletter_error( $message ) {
                try {
                        if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                                Nexus_DB::insert_activity( 0, 'newsletter', sanitize_text_field( (string) $message ), 'error' );
                        }
                } catch ( Exception $e ) {
                        /* Silenzio: il log non deve mai bloccare il flusso. */
                } catch ( Throwable $e ) {
                        /* Silenzio: il log non deve mai bloccare il flusso. */
                }
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
         * Il $token è il token IN CHIARO (generato al volo dal chiamante): non viene
         * mai persistito. Ritorna l'esito di wp_mail (v1.1.9) così il chiamante può
         * loggare onestamente un eventuale fallimento dell'invio.
         *
         * @param string $email Destinatario.
         * @param string $token Token dell'iscritto (in chiaro).
         * @return bool Esito di wp_mail.
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

                return (bool) wp_mail( $email, $subject, $body, self::mail_headers() );
        }

        /**
         * Headers email: HTML UTF-8 + From/Reply-To con nome sito e admin email,
         * X-Mailer del plugin e, quando esiste l'URL di disiscrizione, header
         * List-Unsubscribe (v1.1.9: richiesto dalle migliori pratiche antispam).
         *
         * v1.1.14 (P5): da private a PUBLIC — riusato da Nexus_Digest::handle_preview
         * per l'invio dell'anteprima (stessi header del digest reale).
         *
         * @param string $list_unsub_url URL di disiscrizione per il destinatario ('' = header omesso).
         * @return array
         */
        public static function mail_headers( $list_unsub_url = '' ) {
                $from_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
                $from_mail = get_bloginfo( 'admin_email' );
                $version   = defined( 'NEXUS_VERSION' ) ? NEXUS_VERSION : '1.1.9';

                $headers = array(
                        'Content-Type: text/html; charset=UTF-8',
                        'From: ' . $from_name . ' <' . $from_mail . '>',
                        'Reply-To: ' . $from_name . ' <' . $from_mail . '>',
                        'X-Mailer: Nexus News AI (' . $version . ')',
                );

                $list_unsub_url = trim( (string) $list_unsub_url );
                if ( '' !== $list_unsub_url ) {
                        // Formato RFC 2369: <url> (angolari richiesti; il GET one-click è
                        // il link ?nexus_unsub già offerto nel footer del digest).
                        $headers[] = 'List-Unsubscribe: <' . esc_url_raw( $list_unsub_url ) . '>';
                }

                return $headers;
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

        /* ============ MIGRATION E PREFERENZA DIGEST (v1.1.14, P5) ============ */

        /**
         * Migration SICURA e idempotente: aggiunge la colonna digest_optin
         * TINYINT(1) NOT NULL DEFAULT 1 a nexus_subscribers SE manca.
         *
         * Sicurezza:
         * - check con SHOW COLUMNS (prepato) + flag opzione OPTIN_MIGRATION_FLAG:
         *   a regime il costo è una get_option (autoload no), mai un ALTER;
         * - l'ALTER avviene SOLO se la colonna manca; table name dalla whitelist
         *   Nexus_DB::tables() (nessun input utente); avvolto in try/catch;
         * - fallimento → log attività onesto + flag NON impostato (retry alla
         *   prossima richiesta), il sito continua a funzionare;
         * - DEFAULT 1: la conferma double opt-in promette ESPlicitamente il
         *   digest settimanale, quindi gli iscritti confermati sono già opt-in
         *   per il digest (nessuna involontaria esclusione di iscritti storici);
         *   il flag serve a RINUNCIARE al solo digest (link nel footer email).
         *
         * NOTA coordinatore: la colonna NON è in Nexus_DB::schema() (file fuori
         * perimetro P5) — dbDelta PRESERVA le colonne extra già presenti, quindi
         * l'aggiornamento del plugin NON la rimuove; aggiungerla allo schema
         * canonico in una release futura per uniformità.
         *
         * @return bool True se la colonna è presente (o appena creata).
         */
        private static function ensure_digest_optin_column() {
                static $done = null;
                if ( null !== $done ) {
                        return $done;
                }

                if ( get_option( self::OPTIN_MIGRATION_FLAG ) ) {
                        $done = true;
                        return $done;
                }

                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'tables' ) ) {
                        return false;
                }

                global $wpdb;
                $tables = Nexus_DB::tables();
                $table  = ( isset( $tables['subscribers'] ) && is_string( $tables['subscribers'] ) ) ? $tables['subscribers'] : '';
                if ( '' === $table ) {
                        return false;
                }

                /* Colonna già presente (es. migration riuscita in un ambiente precedente
                 * con flag perso): registra il flag, zero ALTER. */
                $exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'digest_optin' ) );
                if ( $exists ) {
                        update_option( self::OPTIN_MIGRATION_FLAG, 1, false );
                        $done = true;
                        return $done;
                }

                $altered = false;
                try {
                        $altered = (bool) $wpdb->query( "ALTER TABLE {$table} ADD COLUMN digest_optin TINYINT(1) NOT NULL DEFAULT 1" );
                } catch ( Exception $e ) {
                        $altered = false;
                } catch ( Throwable $e ) {
                        $altered = false;
                }

                if ( $altered ) {
                        update_option( self::OPTIN_MIGRATION_FLAG, 1, false );
                        try {
                                if ( method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                                        Nexus_DB::insert_activity( 0, 'newsletter', 'Migrazione digest: colonna digest_optin aggiunta agli iscritti.', 'success' );
                                }
                        } catch ( Exception $e ) {
                                /* Best-effort. */
                        } catch ( Throwable $e ) {
                                /* Best-effort. */
                        }
                        $done = true;
                } else {
                        /* Fallimento onesto: log + nessun flag → retry alla prossima richiesta. */
                        self::log_digest_error( 'Migrazione digest fallita: impossibile aggiungere la colonna digest_optin (' . sanitize_text_field( (string) $wpdb->last_error ) . ').' );
                        $done = false;
                }

                return $done;
        }

        /**
         * Aggiorna SOLO la preferenza digest di un iscritto (colonna digest_optin).
         *
         * La scrittura avviene QUI (non via Nexus_DB::update_subscriber, la cui
         * whitelist colonne è fuori perimetro): la tabella viene dalla whitelist
         * Nexus_DB::tables() e il valore è un interno (id absint + int 0/1);
         * $wpdb->update prepara i valori. Usata dal link di gestione nel footer
         * email (?nexus_unsub={token}&nexus_digest=0|1).
         *
         * @param int  $subscriber_id ID iscritto.
         * @param bool $optin         True = riceve il digest, false = rinuncia al solo digest.
         * @return bool True se aggiornato (o nessun cambiamento), false su errore.
         */
        private static function set_subscriber_digest_optin( $subscriber_id, $optin ) {
                global $wpdb;

                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'tables' ) ) {
                        return false;
                }

                if ( ! self::ensure_digest_optin_column() ) {
                        return false;
                }

                $tables = Nexus_DB::tables();
                $table  = ( isset( $tables['subscribers'] ) && is_string( $tables['subscribers'] ) ) ? $tables['subscribers'] : '';
                if ( '' === $table ) {
                        return false;
                }

                $result = $wpdb->update(
                        $table,
                        array( 'digest_optin' => $optin ? 1 : 0 ),
                        array( 'id' => absint( $subscriber_id ) ),
                        array( '%d' ),
                        array( '%d' )
                );

                return false !== $result;
        }

        /**
         * Corpo HTML del digest (inline-style, max-width 600px).
         *
         * v1.1.14 (P5): nuovi parametri facoltativi APPEND-STYLE — $extra_sections
         * (HTML delle sezioni performance/uso AI/errori da Nexus_Digest, inserito
         * dopo la lista articoli, prima del footer) e $is_preview (banner di
         * anteprima per l'invio admin di prova). Il chiamante cron non li passa:
         * comportamento storico invariato.
         *
         * @param string $intro_html     Paragrafi di introduzione (già HTML sicuro).
         * @param array  $items          Lista articoli: title, url, category.
         * @param string $token          Token subscriber per il footer di disiscrizione ('' = footer generico).
         * @param string $extra_sections HTML extra (sezioni Nexus_Digest, già sicuro).
         * @param bool   $is_preview     True = banner ANTEPRIMA in testa (solo invii admin).
         * @return string
         */
        private static function build_digest_html( $intro_html, $items, $token, $extra_sections = '', $is_preview = false ) {
                $out  = '';
                $out .= '<div style="max-width:600px;margin:0 auto;padding:24px 16px;font-family:Arial,Helvetica,sans-serif;color:#111827;background:#ffffff;">';

                if ( $is_preview ) {
                        $out .= '<p style="margin:0 0 16px;padding:10px 12px;border:2px dashed #4f46e5;border-radius:8px;color:#4f46e5;font-weight:bold;font-size:13px;line-height:1.5;">'
                                . esc_html__( 'ANTEPRIMA — email di prova: non è stata inviata agli iscritti.', 'nexus-news-ai' )
                                . '</p>';
                }

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

                /* v1.1.14 (P5): sezioni extra (performance / uso AI / errori). Ogni
                 * sezione esiste SOLO se i dati esistono (onestà, Nexus_Digest). */
                if ( '' !== (string) $extra_sections ) {
                        $out .= (string) $extra_sections;
                }

                $out .= '<hr style="border:none;border-top:1px solid #e5e7eb;margin:20px 0;" />';

                if ( '' !== $token ) {
                        $unsub_url = home_url( '/?nexus_unsub=' . rawurlencode( $token ) );
                        // v1.1.14 (P5): link di gestione SOLO-digest (rinuncia al digest
                        // mantenendo l'iscrizione); la disiscrizione completa resta disponibile.
                        $digest_off_url = home_url( '/?nexus_unsub=' . rawurlencode( $token ) . '&nexus_digest=0' );
                        $out           .= '<p style="font-size:12px;color:#6b7280;line-height:1.6;margin:0;">'
                                . esc_html__( 'Ricevi questa email perché sei iscritto alla newsletter.', 'nexus-news-ai' ) . ' '
                                . '<a href="' . esc_url( $digest_off_url ) . '" style="color:#6b7280;">' . esc_html__( 'Non ricevere il digest', 'nexus-news-ai' ) . '</a>'
                                . ' · '
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
