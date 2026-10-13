<?php
/**
 * Nexus Notify — notifiche eventi pipeline (v1.1.12, pacchetto notify).
 *
 * Invia notifiche esterne sugli eventi della pipeline senza MAI toccarla:
 * nessuna eccezione propagata (try/guard ovunque), nessun segreto nei log,
 * nessun URL completo con query string nei messaggi d'errore.
 *
 * CANALI (entrambi se configurati):
 *  - Webhook Slack-compatibile: wp_remote_post() su notify_webhook_url con body
 *    JSON {"text": "..."} (formato Slack), Content-Type application/json, timeout 3s;
 *  - Telegram: riuso del canale esistente Nexus_Social_Telegram::publish() tramite
 *    class_exists + method_exists (le guardie di configurazione — enable_telegram,
 *    telegram_bot_token, telegram_chat_id — restano dentro la classe social; canale
 *    non configurato → 'skipped' e qui ignorato in silenzio).
 *
 * EVENTI (mappa dai VALORI REALI emessi da Nexus_Pipeline::log_phase() v1.1.11):
 *  - azioni esistenti: duplicate|collecting|evaluating|rewriting|publishing|
 *    budget|factcheck|ai_output_quality;
 *  - status esistenti: info|success|error|warning (lo status 'pending' NON esiste
 *    ancora: la mappa lo prevede per compatibilità futura);
 *  - status 'error'                                  → evento 'error';
 *  - status 'pending'                                → evento 'pending';
 *  - azioni di coda/approvazione (i due log reali
 *    "Articolo #N in attesa del capo redattore: auto-approvazione saltata…"
 *    su 'evaluating'/'info')                          → evento 'pending';
 *  - tutto il resto viene ignorato;
 *  - hook 'nexus_pipeline_after_publish' ( $article_id, $article, $logs ) → evento
 *    'published' con titolo dell'articolo (campo ->title della riga nexus_articles,
 *    presente in $article) tagliato a ~70 caratteri.
 *
 * IMPOSTAZIONI (Nexus_DB::get_settings()):
 *  - notify_webhook_url: URL del webhook (segreto-like, mai loggato);
 *  - notify_events: CSV degli eventi da instradare, default 'error,pending'
 *    (valori ammessi: error,pending,published). L'evento non presente in lista
 *    fa uscire PRIMA di qualunque lavoro pesante (nessun messaggio, nessun
 *    rate limit consumato, nessuna lettura ulteriore).
 *
 * RATE LIMIT: transient 'nexus_notify_rl_' . gmdate( 'YmdH' ), contatore max 10
 * invii/ora (auto-expiry sull'ora UTC); oltre soglia l'invio viene scartato in
 * silenzio — protegge da flood di errori a cascata verso i canali.
 *
 * MESSAGGI: "📰 Nexus: {evento} — {dettaglio}" in italiano plain (sweep i18n
 * futuro); mai dati sensibili (token, chiavi, URL con query string).
 *
 * @package Nexus_News_AI
 * @since   1.1.12
 */

defined( 'ABSPATH' ) || exit;

final class Nexus_Notify {

        /**
         * Massimo numero di notifiche inviate per ora (rate limit anti-flood).
         */
        const MAX_PER_HOUR = 10;

        /**
         * Registra i listener sugli eventi pipeline (self-init su nexus_modules_init).
         *
         * @return void
         */
        public static function init() {
                add_action( 'nexus_pipeline_phase', array( 'Nexus_Notify', 'on_pipeline_phase' ), 10, 4 );
                add_action( 'nexus_pipeline_after_publish', array( 'Nexus_Notify', 'on_after_publish' ), 10, 3 );
        }

        /**
         * Hook di disattivazione del modulo (nessuna risorsa persistente da pulire:
         * i transient del rate limit scadono da soli entro un'ora).
         *
         * @return void
         */
        public static function deactivate() {
        }

        /**
         * Listener di 'nexus_pipeline_phase' ( $agent_id, $action, $detail, $status ).
         *
         * Mappa evento → filtro lista → messaggio → invio. Mai eccezioni: qualunque
         * errore qui dentro NON deve rompere la pipeline.
         *
         * @param int    $agent_id ID agente (0 = sistema).
         * @param string $action   Azione pipeline (duplicate|collecting|evaluating|rewriting|publishing|budget|factcheck|ai_output_quality).
         * @param string $detail   Dettaglio testuale del log.
         * @param string $status   Stato (info|success|error|warning; 'pending' previsto per il futuro).
         * @return void
         */
        public static function on_pipeline_phase( $agent_id, $action, $detail, $status ) {
                try {
                        // 1) Mappa evento ('' = ignorato): primo filtro, costo quasi zero.
                        $event = self::map_phase_event( $action, $detail, $status );
                        if ( '' === $event ) {
                                return;
                        }

                        // 2) Filtro lista eventi: fuori lista si esce PRIMA di qualunque lavoro pesante.
                        $settings = self::settings();
                        if ( ! self::event_enabled( $event, $settings ) ) {
                                return;
                        }

                        // 3) Messaggio (dettaglio già ripulito e tagliato).
                        $detail = self::clip( (string) $detail, 200 );
                        if ( '' === $detail ) {
                                return;
                        }

                        // 4) Invio (rate limit dentro dispatch).
                        self::dispatch( self::build_message( $event, $detail ), $settings );
                } catch ( \Throwable $e ) {
                        // Le notifiche non devono MAI rompere la pipeline: silenzio totale.
                        return;
                }
        }

        /**
         * Listener di 'nexus_pipeline_after_publish' ( $article_id, $article, $logs ).
         *
         * $article è la riga nexus_articles (object con ->title, ->id, ...): ne viene
         * usato solo il titolo, tagliato a ~70 caratteri.
         *
         * @param int         $article_id ID articolo pubblicato.
         * @param object|null $article    Riga dell'articolo (o null se non recuperata).
         * @param array       $logs       Log di pubblicazione per piattaforma.
         * @return void
         */
        public static function on_after_publish( $article_id, $article, $logs ) {
                try {
                        $article_id = (int) $article_id;

                        // Filtro lista eventi prima di qualunque lavoro pesante.
                        $settings = self::settings();
                        if ( ! self::event_enabled( 'published', $settings ) ) {
                                return;
                        }

                        // Titolo breve (clip ~70 char): campo reale ->title della riga articolo.
                        $title = self::article_field( $article, 'title' );
                        $title = self::clip( $title, 70 );
                        if ( '' === $title ) {
                                $title = sprintf( 'articolo #%d', $article_id );
                        }

                        $detail = ( $article_id > 0 )
                                ? sprintf( '«%s» (articolo #%d).', $title, $article_id )
                                : sprintf( '«%s».', $title );

                        self::dispatch( self::build_message( 'published', $detail ), $settings );
                } catch ( \Throwable $e ) {
                        // Le notifiche non devono MAI rompere la pipeline: silenzio totale.
                        return;
                }
        }

        /* ============ MAPPA E FILTRO EVENTI ============ */

        /**
         * Mappa un log fase pipeline sull'evento di notifica ('' = ignorato).
         *
         * Valori reali pipeline v1.1.11: status 'error' → 'error'; status 'pending'
         * (non ancora emesso, previsto per compatibilità futura) → 'pending'; le uniche
         * azioni di coda/approvazione esistenti sono i due log "in attesa del capo
         * redattore" ('evaluating'/'info') → 'pending'; tutto il resto ignorato.
         *
         * @param string $action Azione pipeline.
         * @param string $detail Dettaglio del log.
         * @param string $status Stato del log.
         * @return string Evento ('error'|'pending') o '' se non instradabile.
         */
        private static function map_phase_event( $action, $detail, $status ) {
                $status = strtolower( trim( (string) $status ) );

                if ( 'error' === $status ) {
                        return 'error';
                }
                if ( 'pending' === $status ) {
                        return 'pending';
                }

                // Azioni di coda/approvazione (needle specifico dei messaggi reali
                // "Articolo #N in attesa del capo redattore: auto-approvazione saltata...").
                $detail = (string) $detail;
                if ( '' !== $detail && false !== stripos( $detail, 'in attesa del capo redattore' ) ) {
                        return 'pending';
                }

                return '';
        }

        /**
         * Lista eventi abilitati dal setting notify_events (CSV).
         *
         * @param array $settings Impostazioni plugin.
         * @return array Eventi normalizzati (trim + lowercase, vuoti rimossi).
         */
        private static function enabled_events( $settings ) {
                $csv = ( is_array( $settings ) && isset( $settings['notify_events'] ) )
                        ? (string) $settings['notify_events']
                        : 'error,pending';

                $events = array_map( 'trim', explode( ',', $csv ) );
                $events = array_map( 'strtolower', $events );
                $events = array_values( array_filter( $events, 'strlen' ) );

                return $events;
        }

        /**
         * L'evento è nella lista notify_events?
         *
         * @param string $event    Evento richiesto.
         * @param array  $settings Impostazioni plugin.
         * @return bool
         */
        private static function event_enabled( $event, $settings ) {
                return in_array( strtolower( trim( (string) $event ) ), self::enabled_events( $settings ), true );
        }

        /* ============ MESSAGGI ============ */

        /**
         * Messaggio di notifica: "📰 Nexus: {evento} — {dettaglio}" (italiano plain,
         * sweep i18n futuro; mai dati sensibili).
         *
         * @param string $event  Evento (error|pending|published).
         * @param string $detail Dettaglio già ripulito.
         * @return string
         */
        private static function build_message( $event, $detail ) {
                $labels = array(
                        'error'     => 'Errore',
                        'pending'   => 'In attesa',
                        'published' => 'Articolo pubblicato',
                );

                $event  = (string) $event;
                $label  = isset( $labels[ $event ] ) ? $labels[ $event ] : $event;
                $detail = trim( (string) $detail );

                if ( '' === $detail ) {
                        return sprintf( '📰 Nexus: %s.', $label );
                }

                return sprintf( '📰 Nexus: %s — %s', $label, $detail );
        }

        /* ============ INVIO ============ */

        /**
         * Dispaccia il messaggio su tutti i canali configurati.
         *
         * Rate limit PRIMA dei canali: oltre 10/ora l'invio viene scartato in
         * silenzio (protegge da flood di errori a cascata).
         *
         * @param string $message  Messaggio già costruito.
         * @param array  $settings Impostazioni plugin.
         * @return void
         */
        private static function dispatch( $message, $settings ) {
                $message = trim( (string) $message );
                if ( '' === $message ) {
                        return;
                }

                // Nessun canale configurato: nessun invio e nessun consumo di rate limit.
                if ( ! self::has_channel( $settings ) ) {
                        return;
                }

                if ( ! self::rate_limit_allow() ) {
                        return; // scarto silenzioso oltre il tetto orario.
                }

                self::send_webhook( $message, $settings );
                self::send_telegram( $message, $settings );
        }

        /**
         * C'è almeno un canale di notifica configurato?
         *
         * Guardia economica PRIMA del rate limit: il contatore conta gli invii,
         * non le notifiche scartate per mancanza di canali.
         *
         * @param array $settings Impostazioni plugin.
         * @return bool
         */
        private static function has_channel( $settings ) {
                if ( is_array( $settings ) && isset( $settings['notify_webhook_url'] )
                        && '' !== trim( (string) $settings['notify_webhook_url'] ) ) {
                        return true;
                }

                // Telegram: guardia solo sull'interruttore generale; le credenziali
                // restano responsabilità delle guardie interne della classe social.
                if ( ! class_exists( 'Nexus_Social_Telegram' ) || ! method_exists( 'Nexus_Social_Telegram', 'publish' ) ) {
                        return false;
                }
                $tg_enabled = ( is_array( $settings ) && isset( $settings['enable_telegram'] ) )
                        ? absint( $settings['enable_telegram'] )
                        : 0;

                return 1 === $tg_enabled;
        }

        /**
         * Rate limit: contatore orario su transient 'nexus_notify_rl_YYYYMMDDHH'.
         *
         * Conteggio best-effort (nessun lock: race acceptable per un tetto anti-flood).
         *
         * @return bool True se l'invio è sotto soglia (contatore già incrementato).
         */
        private static function rate_limit_allow() {
                $key   = 'nexus_notify_rl_' . gmdate( 'YmdH' );
                $count = get_transient( $key );
                $count = ( false === $count ) ? 0 : (int) $count;

                if ( $count >= self::MAX_PER_HOUR ) {
                        return false;
                }

                set_transient( $key, $count + 1, HOUR_IN_SECONDS );
                return true;
        }

        /**
         * Canale webhook Slack-compatibile: POST JSON {"text": ...}, timeout 3.
         *
         * Fallimento → riga attività senza MAI l'URL (al più l'host, se pulito).
         *
         * @param string $message  Messaggio già costruito.
         * @param array  $settings Impostazioni plugin.
         * @return void
         */
        private static function send_webhook( $message, $settings ) {
                $url = ( is_array( $settings ) && isset( $settings['notify_webhook_url'] ) )
                        ? trim( (string) $settings['notify_webhook_url'] )
                        : '';

                if ( '' === $url ) {
                        return; // canale non configurato: silenzioso.
                }

                if ( ! preg_match( '#^https?://#i', $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
                        self::log_failure( 'Webhook notifica fallito (URL non valido).' ); // mai l'URL nel log.
                        return;
                }

                $payload = wp_json_encode( array( 'text' => (string) $message ), JSON_UNESCAPED_UNICODE );
                if ( ! is_string( $payload ) || '' === $payload ) {
                        return;
                }

                $response = wp_remote_post(
                        $url,
                        array(
                                'timeout' => 3,
                                'headers' => array(
                                        'Content-Type' => 'application/json',
                                ),
                                'body'    => $payload,
                        )
                );

                if ( is_wp_error( $response ) ) {
                        self::log_failure( 'Webhook notifica fallito (errore di connessione).' ); // niente dettagli WP_Error (potrebbero contenere l'URL).
                        return;
                }

                $code = (string) wp_remote_retrieve_response_code( $response );
                if ( (int) $code < 200 || (int) $code >= 300 ) {
                        $host = self::safe_host( $url );
                        if ( '' !== $host ) {
                                self::log_failure( sprintf( 'Webhook notifica fallito (HTTP %s; host: %s).', $code, $host ) );
                        } else {
                                self::log_failure( sprintf( 'Webhook notifica fallito (HTTP %s).', $code ) );
                        }
                }
        }

        /**
         * Canale Telegram: riuso del metodo pubblico di invio del pacchetto social
         * (Nexus_Social_Telegram::publish) con guardie class_exists + method_exists.
         *
         * Il contratto del canale social prevede guardie interne di configurazione
         * (enable_telegram + credenziali → 'skipped') e mascheratura del token negli
         * errori: qui un stub articolo con solo il messaggio come titolo è sufficiente
         * a percorrere l'intera catena di invio reale (sendMessage/sendPhoto).
         *
         * @param string $message  Messaggio già costruito.
         * @param array  $settings Impostazioni plugin.
         * @return void
         */
        private static function send_telegram( $message, $settings ) {
                if ( ! class_exists( 'Nexus_Social_Telegram' ) || ! method_exists( 'Nexus_Social_Telegram', 'publish' ) ) {
                        return;
                }

                // Stub articolo minimo: il messaggio di notifica viaggia come titolo
                // (summary/link vuoti → sendMessage di solo testo, nessuna inventa).
                $stub             = new stdClass();
                $stub->title      = (string) $message;
                $stub->summary    = '';
                $stub->source_url = '';
                $stub->image_url  = '';

                $res = Nexus_Social_Telegram::publish( $stub, is_array( $settings ) ? $settings : array() );
                if ( ! is_array( $res ) || ! isset( $res['status'] ) ) {
                        return;
                }

                // 'skipped' = canale non configurato (silenzioso); 'published' = inviato.
                if ( 'failed' !== $res['status'] ) {
                        return;
                }

                $error = isset( $res['error'] ) ? trim( (string) $res['error'] ) : '';
                if ( '' === $error ) {
                        $error = 'errore non specificato.';
                }

                // L'errore arriva già senza token (mascheratura nel pacchetto social).
                self::log_failure( sprintf( 'Notifica Telegram fallita: %s', self::clip( $error, 200 ) ) );
        }

        /* ============ HELPER ============ */

        /**
         * Legge le impostazioni plugin (default merged) con guardie anti-errore.
         *
         * @return array
         */
        private static function settings() {
                if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'get_settings' ) ) {
                        $settings = Nexus_DB::get_settings();
                        if ( is_array( $settings ) ) {
                                return $settings;
                        }
                }
                return array();
        }

        /**
         * Legge un campo dalla riga articolo (object|array) come stringa.
         *
         * @param object|array|null $article Riga nexus_articles.
         * @param string            $key     Nome del campo.
         * @return string
         */
        private static function article_field( $article, $key ) {
                if ( is_object( $article ) && isset( $article->{$key} ) ) {
                        return (string) $article->{$key};
                }
                if ( is_array( $article ) && isset( $article[ $key ] ) ) {
                        return (string) $article[ $key ];
                }
                return '';
        }

        /**
         * Estrae l'host da un URL SOLO se è pulito (nessun path/query nel log).
         *
         * @param string $url URL del webhook (mai loggato intero).
         * @return string Host sicuro o '' se non affidabile.
         */
        private static function safe_host( $url ) {
                $host = parse_url( (string) $url, PHP_URL_HOST );
                if ( ! is_string( $host ) || '' === $host || strlen( $host ) > 253 ) {
                        return '';
                }
                if ( 1 !== preg_match( '/^[a-z0-9.\-]+$/i', $host ) ) {
                        return '';
                }
                return strtolower( $host );
        }

        /**
         * Tronca e ripulisce un testo per i messaggi (multibyte quando disponibile).
         *
         * @param string $text Testo di partenza.
         * @param int    $max  Lunghezza massima in caratteri.
         * @return string
         */
        private static function clip( $text, $max ) {
                $text = trim( wp_strip_all_tags( (string) $text ) );
                $text = (string) preg_replace( '/\s+/u', ' ', $text );
                $max  = (int) $max;

                if ( '' === $text || $max < 1 ) {
                        return $text;
                }

                $len = function_exists( 'mb_strlen' )
                        ? (int) mb_strlen( $text, 'UTF-8' )
                        : (int) strlen( $text );

                if ( $len <= $max ) {
                        return $text;
                }

                $cut = function_exists( 'mb_substr' )
                        ? mb_substr( $text, 0, $max, 'UTF-8' )
                        : substr( $text, 0, $max );

                return rtrim( (string) $cut ) . '…';
        }

        /**
         * Logga un fallimento consegna su activity log (agente 0, action 'notify').
         *
         * @param string $detail Dettaglio breve SENZA segreti/URL completi.
         * @return void
         */
        private static function log_failure( $detail ) {
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                        return;
                }
                Nexus_DB::insert_activity( 0, 'notify', self::clip( (string) $detail, 200 ), 'error' );
        }
}

// v1.1.12 (S7): self-init del modulo notifiche (bootstrap non toccato).
add_action( 'nexus_modules_init', array( 'Nexus_Notify', 'init' ) );
