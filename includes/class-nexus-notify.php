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
 *  - Telegram: v1.1.13 (O1) con l'opzione nexus_notify_telegram_chat impostata il
 *    messaggio va con sendMessage DIRETTO alla chat OPS dedicata (credenziali
 *    esistenti telegram_bot_token; MAI nel canale pubblico dei lettori; chat id
 *    mai loggato). Con l'opzione vuota fallback al comportamento v1.1.12:
 *    riuso del canale esistente Nexus_Social_Telegram::publish() tramite
 *    class_exists + method_exists (le guardie di configurazione — enable_telegram,
 *    telegram_bot_token, telegram_chat_id — restano dentro la classe social; canale
 *    non configurato → 'skipped' e qui ignorato in silenzio).
 *
 * EVENTI (routing v1.1.13 O1):
 *  - PRIMARIO: chiave strutturata. Nexus_Pipeline::log_phase() emette nel 5°
 *    parametro di 'nexus_pipeline_phase' un array {action, status, event}: l'evento
 *    ('error'|'pending') è già risolto a sorgente, immune a cambi di testo/i18n;
 *  - FALLBACK BC: quando la chiave strutturata non arriva (log_phase senza
 *    evento, listener v1.1.12) si torna al matching sui VALORI REALI emessi da
 *    Nexus_Pipeline::log_phase() v1.1.11:
 *    - azioni esistenti: duplicate|collecting|evaluating|rewriting|publishing|
 *      budget|factcheck|ai_output_quality;
 *    - status esistenti: info|success|error|warning (lo status 'pending' NON esiste
 *      ancora: la mappa lo prevede per compatibilità futura);
 *    - status 'error'                                  → evento 'error';
 *    - status 'pending'                                → evento 'pending';
 *    - azioni di coda/approvazione (i due log reali
 *      "Articolo #N in attesa del capo redattore: auto-approvazione saltata…"
 *      su 'evaluating'/'info')                          → evento 'pending';
 *    - tutto il resto viene ignorato;
 *  - hook 'nexus_pipeline_after_publish' ( $article_id, $article, $logs ) → evento
 *    'published' con titolo dell'articolo (campo ->title della riga nexus_articles,
 *    presente in $article) tagliato a ~70 caratteri.
 *
 * IMPOSTAZIONI (Nexus_DB::get_settings(); chiave assente dai default → fallback
 * get_option, così le due nuove opzioni O1 sono impostabili subito via WP-CLI o
 * codice anche prima del glue impostazioni):
 *  - notify_webhook_url: URL del webhook (segreto-like, mai loggato);
 *  - notify_events: CSV degli eventi da instradare, default 'error,pending'
 *    (valori ammessi: error,pending,published). L'evento non presente in lista
 *    fa uscire PRIMA di qualunque lavoro pesante (nessun messaggio, nessun
 *    rate limit consumato, nessuna lettura ulteriore);
 *  - nexus_notify_telegram_chat (v1.1.13 O1): chat/canale Telegram OPS per le
 *    notifiche pipeline (testo, default '' = fallback canale pubblico v1.1.12).
 *    Chat id MAI nei log né nelle risposte REST;
 *  - nexus_notify_max_per_hour (v1.1.13 O1): tetto invii/ora (int, default 10;
 *    0 = tutte scartate), filtrabile in dev con 'nexus_notify_max_per_hour'.
 *
 * RATE LIMIT: transient 'nexus_notify_rl_' . gmdate( 'YmdH' ), contatore max
 * nexus_notify_max_per_hour invii/ora (auto-expiry sull'ora UTC; default 10);
 * oltre soglia l'invio viene scartato in silenzio — protegge da flood di errori
 * a cascata verso i canali.
 *
 * HELPER CONDIVISI (v1.1.15, WE1/WE7) — casa unica per i moduli di integrazione:
 *  - Nexus_Notify::http_post_with_retry( $url, $args ): wp_remote_post con UN
 *    retry automatico (max 2 tentativi) SOLO su errori transitori (rete/429/5xx),
 *    attesa da Retry-After (secondi o data HTTP) o parameters.retry_after
 *    (Telegram Bot API), SEMPRE cap 60s. Usato dai 4 canali social (X, Telegram,
 *    LinkedIn, Discord) e dalla sync GSC/GA4 di Nexus_Metrics: UNA sola
 *    implementazione del retry nel plugin.
 *  - Nexus_Notify::brief_error( $wp_error_or_string, $cap ): messaggio d'errore
 *    breve e sicuro per i log (URL redatti '[url]', pattern token mascherati);
 *    usato qui e da Nexus_Metrics (cap 120, brief_error storico).
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
                add_action( 'nexus_pipeline_phase', array( 'Nexus_Notify', 'on_pipeline_phase' ), 10, 5 );
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
         * Listener di 'nexus_pipeline_phase' ( $agent_id, $action, $detail, $status, $meta ).
         *
         * Mappa evento → filtro lista → messaggio → invio. Mai eccezioni: qualunque
         * errore qui dentro NON deve rompere la pipeline.
         *
         * @param int    $agent_id ID agente (0 = sistema).
         * @param string $action   Azione pipeline (duplicate|collecting|evaluating|rewriting|publishing|budget|factcheck|ai_output_quality).
         * @param string $detail   Dettaglio testuale del log.
         * @param string $status   Stato (info|success|error|warning; 'pending' previsto per il futuro).
         * @param array  $meta     v1.1.13 (O1): metadati strutturati {action, status, event}
         *                         emessi da Nexus_Pipeline::log_phase() (chiave macchina evento).
         * @return void
         */
        public static function on_pipeline_phase( $agent_id, $action, $detail, $status, $meta = array() ) {
                try {
                        // 1) Mappa evento ('' = ignorato): primo filtro, costo quasi zero.
                        //    v1.1.13 (O1): PRIMA la chiave strutturata {action,status,event},
                        //    poi il fallback testuale BC.
                        $event = self::map_phase_event( $action, $detail, $status, $meta );
                        if ( '' === $event ) {
                                return;
                        }

                        // 2) Filtro lista eventi: fuori lista si esce PRIMA di qualunque lavoro pesante.
                        //    v1.1.13.1 (Q4): lettura via helper condiviso Nexus_Dedup::settings_guarded().
                        $settings = Nexus_Dedup::settings_guarded();
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
                        // v1.1.13.1 (Q4): lettura via helper condiviso Nexus_Dedup::settings_guarded().
                        $settings = Nexus_Dedup::settings_guarded();
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
         * v1.1.13 (O1): routing PRIMARIO sulla chiave strutturata — il 5° argomento
         * dell'azione (array {action, status, event}) porta l'evento già risolto a
         * sorgente da Nexus_Pipeline::log_phase(): il routing non si rompe più a ogni
         * cambio di testo/i18n. Solo eventi noti sono instradati.
         *
         * FALLBACK BC (comportamento v1.1.12, per log_phase senza evento strutturato):
         * status 'error' → 'error'; status 'pending' (non ancora emesso, previsto per
         * compatibilità futura) → 'pending'; le uniche azioni di coda/approvazione
         * esistenti sono i due log "in attesa del capo redattore" ('evaluating'/'info')
         * → 'pending'; tutto il resto ignorato.
         *
         * @param string $action Azione pipeline.
         * @param string $detail Dettaglio del log.
         * @param string $status Stato del log.
         * @param array  $meta   Metadati strutturati {action, status, event} (v1.1.13 O1).
         * @return string Evento ('error'|'pending') o '' se non instradabile.
         */
        private static function map_phase_event( $action, $detail, $status, $meta = array() ) {
                // 1) v1.1.13 (O1): chiave strutturata (machine-key) — priorità assoluta.
                if ( is_array( $meta ) && isset( $meta['event'] ) ) {
                        $event = strtolower( trim( (string) $meta['event'] ) );
                        if ( in_array( $event, array( 'error', 'pending', 'published' ), true ) ) {
                                return $event;
                        }
                }

                // 2) Fallback BC: matching su status + testo dei messaggi reali v1.1.11.
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

                if ( ! self::rate_limit_allow( $settings ) ) {
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
                $settings = is_array( $settings ) ? $settings : array();

                if ( isset( $settings['notify_webhook_url'] )
                        && '' !== trim( (string) $settings['notify_webhook_url'] ) ) {
                        return true;
                }

                // v1.1.13 (O1): chat OPS Telegram + bot token = canale a sé (indipendente
                // dall'interruttore del canale pubblico: chi configura la chat ops si
                // aspetta le notifiche anche con enable_telegram spento).
                if ( '' !== self::telegram_ops_chat( $settings )
                        && isset( $settings['telegram_bot_token'] )
                        && '' !== trim( (string) $settings['telegram_bot_token'] ) ) {
                        return true;
                }

                // Telegram pubblico: guardia solo sull'interruttore generale; le credenziali
                // restano responsabilità delle guardie interne della classe social.
                if ( ! class_exists( 'Nexus_Social_Telegram' ) || ! method_exists( 'Nexus_Social_Telegram', 'publish' ) ) {
                        return false;
                }
                $tg_enabled = isset( $settings['enable_telegram'] )
                        ? absint( $settings['enable_telegram'] )
                        : 0;

                return 1 === $tg_enabled;
        }

        /**
         * Rate limit: contatore orario su transient 'nexus_notify_rl_YYYYMMDDHH'.
         *
         * Conteggio best-effort (nessun lock: race acceptable per un tetto anti-flood).
         *
         * @param array $settings Impostazioni plugin.
         * @return bool True se l'invio è sotto soglia (contatore già incrementato).
         */
        private static function rate_limit_allow( $settings ) {
                $key   = 'nexus_notify_rl_' . gmdate( 'YmdH' );
                $count = get_transient( $key );
                $count = ( false === $count ) ? 0 : (int) $count;

                if ( $count >= self::max_per_hour( $settings ) ) {
                        return false;
                }

                set_transient( $key, $count + 1, HOUR_IN_SECONDS );
                return true;
        }

        /**
         * Tetto orario di notifiche (v1.1.13 O1): opzione nexus_notify_max_per_hour
         * (int, default 10 — era la costante fissa MAX_PER_HOUR; 0 = tutte scartate),
         * filtro dev 'nexus_notify_max_per_hour' per override runtime.
         *
         * Lettura: chiave dell'array settings se presente (dopo il glue impostazioni),
         * altrimenti opzione diretta (impostabile subito via WP-CLI/codice).
         *
         * @param array $settings Impostazioni plugin.
         * @return int Numero massimo di invii per ora (clampato 0-999).
         */
        private static function max_per_hour( $settings ) {
                $max = self::MAX_PER_HOUR;

                $raw = null;
                if ( is_array( $settings ) && array_key_exists( 'nexus_notify_max_per_hour', $settings ) ) {
                        $raw = $settings['nexus_notify_max_per_hour'];
                } else {
                        $raw = get_option( 'nexus_notify_max_per_hour', null );
                }

                if ( null !== $raw && is_numeric( $raw ) ) {
                        $max = (int) $raw;
                }

                $max = (int) apply_filters( 'nexus_notify_max_per_hour', $max );

                return max( 0, min( 999, $max ) );
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
                        // v1.1.15 (WE7): dettaglio WP_Error ora incluso passando dal redact
                        // condiviso (URL con eventuali token rimossi): l'indizio utile (es.
                        // timeout cURL) arriva in log senza esporre l'endpoint.
                        self::log_failure( 'Webhook notifica fallito (errore di connessione): ' . self::brief_error( $response, 120 ) );
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
         * Canale Telegram.
         *
         * v1.1.13 (O1): con l'opzione nexus_notify_telegram_chat impostata il messaggio
         * va con sendMessage DIRETTO alla chat OPS dedicata (metodo privato
         * send_telegram_ops, credenziali esistenti telegram_bot_token): gli errori
         * pipeline NON finiscono più nel canale PUBBLICO dei lettori. Chat id MAI nei
         * log né nei messaggi d'errore.
         *
         * Con l'opzione vuota fallback BC (v1.1.12): riuso del metodo pubblico di invio
         * del pacchetto social (Nexus_Social_Telegram::publish) con guardie
         * class_exists + method_exists. Il contratto del canale social prevede guardie
         * interne di configurazione (enable_telegram + credenziali → 'skipped') e
         * mascheratura del token negli errori: qui un stub articolo con solo il
         * messaggio come titolo è sufficiente a percorrere l'intera catena di invio
         * reale (sendMessage/sendPhoto).
         *
         * @param string $message  Messaggio già costruito.
         * @param array  $settings Impostazioni plugin.
         * @return void
         */
        private static function send_telegram( $message, $settings ) {
                $settings = is_array( $settings ) ? $settings : array();

                // v1.1.13 (O1): chat ops configurata → invio DIRETTO, mai canale pubblico.
                $ops_chat = self::telegram_ops_chat( $settings );
                if ( '' !== $ops_chat ) {
                        self::send_telegram_ops( $message, $settings, $ops_chat );
                        return;
                }

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

                $res = Nexus_Social_Telegram::publish( $stub, $settings );
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

        /**
         * Chat Telegram OPS per le notifiche pipeline (v1.1.13 O1).
         *
         * Lettura: chiave nexus_notify_telegram_chat dell'array settings se presente
         * (dopo il glue impostazioni), altrimenti opzione diretta (impostabile subito
         * via WP-CLI/codice). Default '' = fallback comportamento v1.1.12 (canale
         * pubblico). Il valore NON viene mai loggato né restituito in risposte REST.
         *
         * @param array $settings Impostazioni plugin.
         * @return string Chat id/canale ops ('' = non configurata).
         */
        private static function telegram_ops_chat( $settings ) {
                if ( is_array( $settings ) && array_key_exists( 'nexus_notify_telegram_chat', $settings ) ) {
                        return trim( (string) $settings['nexus_notify_telegram_chat'] );
                }

                return trim( (string) get_option( 'nexus_notify_telegram_chat', '' ) );
        }

        /**
         * Invio DIRETTO via Bot API alla chat OPS (v1.1.13 O1): sendMessage di solo
         * testo, nessun riuso del canale social (nessun rischio di pubblicare nel
         * canale dei lettori). Usa le credenziali esistenti (telegram_bot_token).
         *
         * SICUREZZA: il token viaggia SOLO nell'endpoint — mai in log, messaggi o
         * errori (nessun dettaglio WP_Error, può contenere l'URL col token); il chat
         * id non compare mai nei log. Fallimenti → activity log generico (HTTP code).
         *
         * @param string $message  Messaggio già costruito.
         * @param array  $settings Impostazioni plugin.
         * @param string $chat     Chat id/canale ops (mai loggato).
         * @return void
         */
        private static function send_telegram_ops( $message, $settings, $chat ) {
                $token = ( is_array( $settings ) && isset( $settings['telegram_bot_token'] ) )
                        ? trim( (string) $settings['telegram_bot_token'] )
                        : '';

                // Serve il bot token esistente del canale social: nessuna credenziale nuova.
                if ( '' === $token ) {
                        self::log_failure( 'Notifica Telegram ops non inviata: bot token non configurato.' );
                        return;
                }

                $body = wp_json_encode(
                        array(
                                'chat_id'                  => (string) $chat,
                                'text'                     => (string) $message,
                                'disable_web_page_preview' => true,
                        ),
                        JSON_UNESCAPED_UNICODE
                );
                if ( ! is_string( $body ) || '' === $body ) {
                        return;
                }

                $endpoint = 'https://api.telegram.org/bot' . $token . '/sendMessage';

                $response = wp_remote_post(
                        $endpoint,
                        array(
                                'timeout' => 5,
                                'headers' => array(
                                        'Content-Type' => 'application/json',
                                ),
                                'body'    => $body,
                        )
                );

                if ( is_wp_error( $response ) ) {
                        // v1.1.15 (WE7): dettaglio WP_Error tramite brief_error — l'endpoint
                        // col token viene redatto come '[url]', l'indizio (es. timeout)
                        // arriva in log senza segreti.
                        self::log_failure( 'Notifica Telegram ops fallita (errore di connessione): ' . self::brief_error( $response, 120 ) );
                        return;
                }

                $code = (int) wp_remote_retrieve_response_code( $response );
                $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

                // Esito onesto ma sobrio: HTTP code + ok flag, MAI description (potrebbe
                // riportare il chat id) né token.
                if ( $code < 200 || $code >= 300 || ! is_array( $data ) || empty( $data['ok'] ) ) {
                        self::log_failure( sprintf( 'Notifica Telegram ops fallita (HTTP %d).', $code ) );
                }
        }

        /* ============ HELPER HTTP CONDIVISO (v1.1.15, WE1) ============ */

        /**
         * v1.1.15 (WE1) — POST HTTP con UN retry automatico (max 2 tentativi
         * totali) SOLO su errori transitori: errore di rete (WP_Error), HTTP 429
         * o HTTP >= 500. Casa unica del retry nel plugin: i 4 canali social
         * (X, Telegram, LinkedIn, Discord) e la sync GSC/GA4 di Nexus_Metrics
         * chiamano questo metodo invece di wp_remote_post.
         *
         * Attesa prima del retry (SEMPRE cap 60s):
         *  - header 'Retry-After' della risposta (secondi oppure data HTTP);
         *  - body JSON con parameters.retry_after (formato 429 della Bot API Telegram);
         *  - default esponenziale breve: 5 secondi.
         *
         * SICUREZZA DOPPIO-POST: il retry avviene SOLO quando NON è arrivata una
         * risposta 2xx (cioè PRIMA di ottenere qualunque id remoto): una 201/204/200
         * torna subito al chiamante senza mai essere ripetuta. Gli altri 4xx (400,
         * 401, 403, 404, 422 ...) NON sono transitori: stessi input darebbero lo
         * stesso errore, nessun retry.
         *
         * @param string $url  Endpoint della richiesta.
         * @param array  $args Argomenti di wp_remote_post (timeout, headers, body, ...).
         * @return array|WP_Error Risposta dell'ULTIMO tentativo (array o WP_Error).
         */
        public static function http_post_with_retry( $url, array $args ) {
                $response = wp_remote_post( $url, $args );

                if ( ! self::http_transient_failure( $response ) ) {
                        return $response; // successo, 2xx qualsiasi, o 4xx permanente.
                }

                // Attesa dal contratto del provider (cap 60s), poi UN secondo invio.
                $wait = self::http_retry_wait_seconds( $response );
                if ( $wait > 0 ) {
                        sleep( $wait );
                }

                return wp_remote_post( $url, $args );
        }

        /**
         * v1.1.15 (WE1) — la risposta è un fallimento transitorio che ammette retry?
         *
         * @param array|WP_Error $response Risposta di wp_remote_post.
         * @return bool True su WP_Error (rete/timeout) o HTTP 429 / >= 500.
         */
        private static function http_transient_failure( $response ) {
                if ( is_wp_error( $response ) ) {
                        return true;
                }

                $code = (int) wp_remote_retrieve_response_code( $response );

                return ( 429 === $code || $code >= 500 );
        }

        /**
         * v1.1.15 (WE1) — secondi di attesa prima del retry (SEMPRE cap 60s).
         *
         * Ordine: 1) header 'Retry-After' (secondi o data HTTP); 2) body JSON
         * parameters.retry_after (Telegram Bot API); 3) default 5s.
         *
         * @param array|WP_Error $response Risposta dell'ultimo tentativo.
         * @return int Secondi di attesa (>= 1).
         */
        private static function http_retry_wait_seconds( $response ) {
                $wait = 0.0;

                if ( ! is_wp_error( $response ) ) {
                        // 1) Header Retry-After: numerico ("30") o data HTTP
                        //    ("Wed, 21 Oct 2015 07:28:00 GMT").
                        $ra = wp_remote_retrieve_header( $response, 'retry-after' );
                        if ( is_array( $ra ) ) {
                                $ra = isset( $ra[0] ) ? $ra[0] : '';
                        }
                        $ra = trim( (string) $ra );

                        if ( '' !== $ra ) {
                                if ( is_numeric( $ra ) && (float) $ra > 0 ) {
                                        $wait = (float) $ra;
                                } else {
                                        $ts = strtotime( $ra );
                                        if ( false !== $ts && $ts > time() ) {
                                                $wait = (float) ( $ts - time() );
                                        }
                                }
                        }

                        // 2) Solo se l'header non ha fornito un valore: il 429 della
                        //    Bot API Telegram porta {"parameters":{"retry_after":N}}.
                        if ( $wait <= 0 ) {
                                $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
                                if ( is_array( $body ) && isset( $body['parameters']['retry_after'] )
                                        && is_numeric( (string) $body['parameters']['retry_after'] )
                                        && (float) $body['parameters']['retry_after'] > 0 ) {
                                        $wait = (float) $body['parameters']['retry_after'];
                                }
                        }
                }

                if ( $wait <= 0 ) {
                        $wait = 5.0; // default esponenziale breve (contratto WE1).
                }

                return (int) min( 60, (int) ceil( $wait ) );
        }

        /**
         * v1.1.15 (WE7) — helper PUBBLICO condiviso per i messaggi d'errore dei
         * moduli di integrazione: rende un WP_Error (o una stringa) breve e sicuro
         * per i log. Redact (in ordine):
         *  - qualunque URL sostituito con '[url]' (gli endpoint con token — es.
         *    Bot API Telegram, webhook Discord — non devono MAI comparire interi);
         *  - pattern di token/chiavi noti mascherati: bot token Telegram
         *    (forma '123456:ABC...') e header 'Bearer ...';
         *  - tag HTML e whitespace ripuliti (sanitize_text_field).
         * Cap caratteri (default 200, suffisso '...' come il brief_error storico
         * di Nexus_Metrics, che da oggi delega a questo metodo con cap 120).
         *
         * @param mixed $wp_error_or_string WP_Error oppure messaggio grezzo.
         * @param int   $cap                Lunghezza massima (default 200, minimo 20).
         * @return string Messaggio breve senza segreti/URL ('errore sconosciuto' se vuoto).
         */
        public static function brief_error( $wp_error_or_string, $cap = 200 ) {
                if ( is_wp_error( $wp_error_or_string ) ) {
                        $message = $wp_error_or_string->get_error_message();
                } else {
                        $message = is_string( $wp_error_or_string ) ? $wp_error_or_string : '';
                }

                $message = wp_strip_all_tags( (string) $message );

                // Redact 1: qualunque URL con schema (token nell'endpoint o nella query).
                $clean = preg_replace( '#[a-z][a-z0-9+.\-]*://[^\s]+#i', '[url]', $message );
                if ( is_string( $clean ) ) {
                        $message = $clean;
                }

                // Redact 2: bot token Telegram (formato <bot_id>:<chiave alfanumerica>).
                $clean = preg_replace( '/\b\d{6,}:[A-Za-z0-9_\-]{20,}\b/', '[token]', $message );
                if ( is_string( $clean ) ) {
                        $message = $clean;
                }

                // Redact 3: token Bearer (Authorization o body di risposta).
                $clean = preg_replace( '/(Bearer\s+)[A-Za-z0-9._\-]{8,}/i', '$1[token]', $message );
                if ( is_string( $clean ) ) {
                        $message = $clean;
                }

                $message = trim( (string) sanitize_text_field( $message ) );

                if ( '' === $message ) {
                        return 'errore sconosciuto';
                }

                $cap = max( 20, (int) $cap );
                if ( strlen( $message ) > $cap ) {
                        $message = substr( $message, 0, max( 1, $cap - 3 ) ) . '...';
                }

                return $message;
        }

        /* ============ HELPER ============ */

        // v1.1.13.1 (Q4): la copia privata settings() è stata eliminata; i
        // call-site (on_pipeline_phase / on_after_publish) leggono ora da
        // Nexus_Dedup::settings_guarded() — semantica identica (guardie
        // class_exists/method_exists su Nexus_DB, ritorna sempre un array).
        // Il modulo è richiesto dal bootstrap DOPO class-nexus-dedup.php e i
        // due listener girano in try/catch \Throwable: nessun rischio di
        // caricamento senza Nexus_Dedup.

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

        /**
         * v1.1.14 (P3): invio generico per messaggi di sistema fuori dalla
         * pipeline (unico consumatore attuale: Nexus_Health per i check che
         * entrano in warn/err). STESSA disciplina d'invio esistente: try/catch
         * Throwable, clip del dettaglio, canali + rate limit dentro dispatch(),
         * nessun segreto nei messaggi, nessuna eccezione propagata.
         *
         * Eventi ammessi: 'health' (nuovo) + i tre eventi storici. Per gli
         * eventi storici vale il filtro notify_events (BC); per 'health' il
         * messaggio parte sempre — notify_events governa gli eventi pipeline e
         * l'anti-spam del modulo health vive a monte (confronto con lo stato
         * precedente + re-notifica solo dopo 24h), mentre il tetto orario del
         * rate limit resta attivo anche qui.
         *
         * @param string $event  Evento (health|error|pending|published).
         * @param string $detail Dettaglio libero (clip a 300, niente segreti).
         * @return void
         */
        public static function send_generic( $event, $detail ) {
                try {
                        $event = strtolower( trim( (string) $event ) );
                        if ( ! in_array( $event, array( 'health', 'error', 'pending', 'published' ), true ) ) {
                                return;
                        }

                        // v1.1.13.1 (Q4): lettura settings via helper condiviso guardato.
                        $settings = ( class_exists( 'Nexus_Dedup' ) && method_exists( 'Nexus_Dedup', 'settings_guarded' ) )
                                ? Nexus_Dedup::settings_guarded()
                                : array();

                        // Eventi storici: invariato il filtro lista; 'health' è un
                        // evento di sistema fuori lista (vedi docblock).
                        if ( 'health' !== $event && ! self::event_enabled( $event, $settings ) ) {
                                return;
                        }

                        $detail = self::clip( (string) $detail, 300 );
                        if ( '' === $detail ) {
                                return;
                        }

                        if ( 'health' === $event ) {
                                // build_message non ha label per i nuovi eventi: messaggio dedicato,
                                // stesso formato "📰 Nexus: {evento} — {dettaglio}".
                                $message = sprintf( '📰 Nexus: Health check — %s', $detail );
                        } else {
                                $message = self::build_message( $event, $detail );
                        }

                        self::dispatch( $message, $settings );
                } catch ( \Throwable $e ) {
                        // Le notifiche non devono MAI rompere il chiamante: silenzio totale.
                        return;
                }
        }
}

// v1.1.12 (S7): self-init del modulo notifiche (bootstrap non toccato).
add_action( 'nexus_modules_init', array( 'Nexus_Notify', 'init' ) );

// v1.1.14 (P3): caricamento del modulo health check redazione. Il bootstrap
// (nexus-news-ai.php) è FUORI dal perimetro P3, quindi il file nuovo si carica
// qui in coda (append-only, nessuna riga esistente modificata): il require
// avviene durante il caricamento del plugin, PRIMA di nexus_modules_init, così
// Nexus_Health::init() si auto-registra in tempo (cron orario + admin + card).
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-health.php';
