<?php
/**
 * Nexus Google Wizard — pagina admin "Google" guidata (v1.1.14, task P1).
 *
 * Nuova sottopagina "Google" sotto il menu top-level "Nexus News" (slug
 * nexus-google-wizard, registrata da QUESTO file su admin_menu priorità 20:
 * il file class-nexus-admin.php NON viene toccato).
 *
 * CONTENUTI PAGINA (vista admin/views/google-wizard.php):
 *  1. Stato connessione Google (READ-ONLY via Nexus_Google_Auth::is_connected()
 *     + esito onesto del token: presente/valido, scaduto con refresh fallito,
 *     assente) + link al flusso OAuth esistente (Impostazioni → tab sistema).
 *  2. VALIDAZIONE PROPERTY LIVE (il cuore): input URL property GSC → endpoint
 *     REST GET /nexus/v1/google/wizard/property → il server chiama la Search
 *     Console API con Nexus_Google_Auth::get_access_token() (READ-ONLY):
 *     GET https://searchconsole.googleapis.com/webmasters/v3/sites (lista) e,
 *     in caso di match mancante, GET site singola URL-encoded per distinguere
 *     403 (non verificata) da 404 (inesistente). La property viene salvata
 *     SOLO dopo verifica OK, con Nexus_DB::update_settings() sulla STESSA
 *     chiave 'gsc_site_url' letta da Nexus_Metrics (zero modifiche al modulo).
 *  3. Stato sync (server-side, onesto): cron schedulato o no (wp_next_scheduled
 *     dell'hook di Nexus_Metrics), property GSC/GA4 configurate, ultimo giro
 *     (MAX metric_date + COUNT righe del giorno per sorgente) e totali della
 *     tabella nexus_metrics. Stati vuoti onesti: MAI zeri finti.
 *  4. Bottone "Sincronizza ora": POST /nexus/v1/google/wizard/sync → chiama
 *     Nexus_Metrics::run_sync() (API PUBBLICA, usata READ-ONLY, file NON
 *     toccato) e riporta esito onesto: righe nuove, totali, ultime righe di
 *     log attività 'metrics_sync' prodotte dal giro.
 *
 * REST (permission_callback SU OGNI route: nonce X-WP-Nonce 'wp_rest' +
 * capability manage_options): risposte SEMPRE 200 con { ok, code, message }
 * distinguibile (stesso pattern Nexus_Rest::rest_sources_test) — i codici
 * 'no_token' | 'refresh_failed' | 'forbidden' | 'not_found' | 'http_error' |
 * 'network' coprono i casi d'errore richiesti dal mandato.
 *
 * SICUREZZA: token OAuth MAI loggato, mai restituito al client, mai in
 * errori/URL; wp_remote_get con timeout + is_wp_error; escape ovunque nella
 * vista; i valori numerici passano per absint.
 *
 * INTEGRAZIONE (contratto col glue): il file è SELF-REGISTRANTE via action
 * 'nexus_modules_init' (stesso pattern class-nexus-google-auth.php) ma va
 * aggiunto al require del bootstrap: 1 riga in nexus-news-ai.php
 * (require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-google-wizard.php';)
 * — il file bootstrap è fuori dal perimetro P1.
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

final class Nexus_Google_Wizard {

        /**
         * Slug della sottopagina admin (parent: 'nexus-news').
         */
        const MENU_SLUG = 'nexus-google-wizard';

        /**
         * Namespace REST condiviso col plugin.
         */
        const REST_NAMESPACE = 'nexus/v1';

        /**
         * Prefisso delle route REST del wizard.
         */
        const REST_BASE = '/google/wizard';

        /**
         * Endpoint lista property di Search Console (API webmasters v3).
         */
        const GSC_SITES_ENDPOINT = 'https://searchconsole.googleapis.com/webmasters/v3/sites';

        /**
         * Timeout delle richieste HTTP verso Google (secondi).
         */
        const HTTP_TIMEOUT = 20;

        /* ============ BOOTSTRAP ============ */

        /**
         * Hook del modulo: menu (priorità tarda 20), asset CSS e route REST.
         *
         * Chiamata via action 'nexus_modules_init' (self-registrazione in coda
         * al file): nessuna chiamata di rete, nessuna lettura di option qui.
         *
         * @return void
         */
        public static function init() {
                add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
                add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
                add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        }

        /**
         * Registra la sottopagina "Google" sotto il menu "Nexus News".
         *
         * Priorità 20 su admin_menu: arriva DOPO Nexus_Admin::register_menu()
         * (default 10) e NON tocca quel file. Capability identica alle altre
         * pagine Nexus ('manage_options', vedi class-nexus-caps.php).
         *
         * @return void
         */
        public static function register_menu() {
                add_submenu_page(
                        'nexus-news',
                        __( 'Google', 'nexus-news-ai' ), // <title> pagina.
                        __( 'Google', 'nexus-news-ai' ), // Voce di menu.
                        'manage_options',
                        self::MENU_SLUG,
                        array( __CLASS__, 'render_page' )
                );
        }

        /**
         * Rendering della pagina: include la vista con il gancio i18n dello
         * stesso pattern Nexus_Admin::render_view (buffer + translate_html).
         *
         * @return void
         */
        public static function render_page() {
                $file = NEXUS_PLUGIN_DIR . 'admin/views/google-wizard.php';

                if ( ! file_exists( $file ) ) {
                        echo '<div class="notice notice-warning"><p>' .
                                esc_html__( 'Vista "google-wizard" non ancora disponibile (admin/views/).', 'nexus-news-ai' ) .
                        '</p></div>';
                        return;
                }

                ob_start();
                include $file;
                $out = ob_get_clean();

                // Gancio i18n (v1.0.5): l'HTML della vista è bufferizzato per
                // poter applicare Nexus_I18n::translate_html quando attivo.
                if ( class_exists( 'Nexus_I18n' ) && method_exists( 'Nexus_I18n', 'translate_html' ) ) {
                        $out = Nexus_I18n::translate_html( $out );
                }

                echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        /**
         * Enqueue del CSS del wizard SOLO sulla pagina nexus-google-wizard.
         *
         * Il foglio dipende da nexus-admin-css (usa solo i suoi token
         * var(--nxa-*), stessa convenzione dei fogli jobs/analytics/tools);
         * admin.js e i18n.js sono già enqueuati da Nexus_Admin su ogni pagina
         * nexus (il hook della pagina contiene 'nexus-').
         *
         * @param string $hook Suffix della pagina admin corrente.
         * @return void
         */
        public static function enqueue_assets( $hook ) {
                if ( false === strpos( (string) $hook, self::MENU_SLUG ) ) {
                        return;
                }

                if ( ! file_exists( NEXUS_PLUGIN_DIR . 'admin/css/nexus-wizard.css' ) ) {
                        return;
                }

                wp_enqueue_style(
                        'nexus-wizard-css',
                        NEXUS_PLUGIN_URL . 'admin/css/nexus-wizard.css',
                        array( 'nexus-admin-css' ),
                        NEXUS_VERSION
                );
        }

        /* ============ REST ============ */

        /**
         * Registra le 3 route del wizard (namespace nexus/v1, base /google/wizard).
         *
         * Ogni route usa la permission dedicata perm_admin(): nonce X-WP-Nonce
         * ('wp_rest') + capability manage_options.
         *
         * @return void
         */
        public static function register_routes() {
                register_rest_route(
                        self::REST_NAMESPACE,
                        self::REST_BASE . '/status',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::READABLE,
                                        'callback'            => array( __CLASS__, 'rest_status' ),
                                        'permission_callback' => array( __CLASS__, 'perm_admin' ),
                                ),
                        )
                );

                register_rest_route(
                        self::REST_NAMESPACE,
                        self::REST_BASE . '/property',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::READABLE,
                                        'callback'            => array( __CLASS__, 'rest_property' ),
                                        'permission_callback' => array( __CLASS__, 'perm_admin' ),
                                        'args'                => array(
                                                'property' => array(
                                                        'type'              => 'string',
                                                        'required'          => false,
                                                        'default'           => '',
                                                        'sanitize_callback' => 'sanitize_text_field',
                                                ),
                                        ),
                                ),
                        )
                );

                register_rest_route(
                        self::REST_NAMESPACE,
                        self::REST_BASE . '/sync',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_sync' ),
                                        'permission_callback' => array( __CLASS__, 'perm_admin' ),
                                ),
                        )
                );
        }

        /**
         * Permesso delle route del wizard: nonce X-WP-Nonce ('wp_rest') valido
         * + capability manage_options (i due controlli del mandato, ESPLICITI).
         *
         * @param WP_REST_Request $request Request corrente.
         * @return true|WP_Error
         */
        public static function perm_admin( $request ) {
                $nonce = ( $request instanceof WP_REST_Request ) ? (string) $request->get_header( 'X-WP-Nonce' ) : '';

                if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
                        return new WP_Error(
                                'nexus_gw_forbidden_nonce',
                                __( 'Nonce REST mancante o non valido: ricarica la pagina e riprova.', 'nexus-news-ai' ),
                                array( 'status' => 403 )
                        );
                }

                if ( ! current_user_can( 'manage_options' ) ) {
                        return new WP_Error(
                                'nexus_gw_forbidden',
                                __( 'Non hai i permessi per accedere a questa risorsa.', 'nexus-news-ai' ),
                                array( 'status' => 403 )
                        );
                }

                return true;
        }

        /**
         * GET /google/wizard/status — stato completo della pagina (usato anche
         * dal client dopo le azioni per aggiornare i pannelli in pagina).
         *
         * @return WP_REST_Response
         */
        public static function rest_status() {
                return rest_ensure_response(
                        array(
                                'ok'    => true,
                                'code'  => 'ok',
                                'state' => self::page_state(),
                        )
                );
        }

        /**
         * GET /google/wizard/property?property=<url> — verifica LIVE della
         * property GSC contro l'account Google collegato.
         *
         * Con property vuota: SOLO lista property disponibili (nessun
         * salvataggio, nessuna richiesta di rete se il token manca). Con
         * property valorizzata: match nella lista (varianti URL normalizzate)
         * → salvataggio SOLO se verificata OK; in caso di match mancante una
         * GET site singola distingue 403 (non verificata) da 404 (inesistente).
         *
         * @param WP_REST_Request $request Request corrente.
         * @return WP_REST_Response
         */
        public static function rest_property( $request ) {
                $property = trim( (string) $request->get_param( 'property' ) );

                return rest_ensure_response( self::verify_property( $property ) );
        }

        /**
         * POST /google/wizard/sync — sincronizzazione manuale ONESTA.
         *
         * Pre-flight con messaggi distinti (modulo assente / Google non
         * connesso / nessuna property configurata), poi chiama la API PUBBLICA
         * Nexus_Metrics::run_sync() (READ-ONLY per questo modulo: nessuna
         * modifica a class-nexus-metrics.php) e riporta: righe prima/dopo,
         * delta, stato sync aggiornato e le ultime righe di log attività
         * 'metrics_sync' scritte dal giro (il log del plugin è la fonte
         * onesta del motivo quando nessuna riga viene scritta).
         *
         * @return WP_REST_Response
         */
        public static function rest_sync() {
                // Pre-flight 1: modulo metrics disponibile (run_sync è pubblico).
                if ( ! class_exists( 'Nexus_Metrics' ) || ! method_exists( 'Nexus_Metrics', 'run_sync' ) ) {
                        return rest_ensure_response(
                                array(
                                        'ok'      => false,
                                        'code'    => 'module_missing',
                                        'message' => __( 'Modulo metriche non disponibile: controlla che il plugin sia aggiornato.', 'nexus-news-ai' ),
                                )
                        );
                }

                $state_before = self::page_state();

                // Pre-flight 2: Google collegato (stessa guardia di do_sync:
                // senza token il giro finisce in un log "saltata" e basterebbe
                // — qui il messaggio è più chiaro per l'utente).
                if ( ! $state_before['google_connected'] ) {
                        return rest_ensure_response(
                                array(
                                        'ok'      => false,
                                        'code'    => 'not_connected',
                                        'message' => __( 'Google non è connesso: collega Search Console nelle Impostazioni (flusso OAuth) e riprova.', 'nexus-news-ai' ),
                                )
                        );
                }

                // Pre-flight 3: almeno una property configurata (GSC o GA4).
                if ( ! $state_before['gsc_configured'] && ! $state_before['ga4_configured'] ) {
                        return rest_ensure_response(
                                array(
                                        'ok'      => false,
                                        'code'    => 'no_targets',
                                        'message' => __( 'Nessuna property configurata: verifica e salva una property GSC in questa pagina, o inserisci un Property ID GA4.', 'nexus-news-ai' ),
                                )
                        );
                }

                $start_mysql = current_time( 'mysql' );

                try {
                        Nexus_Metrics::run_sync();
                } catch ( Exception $e ) {
                        return rest_ensure_response(
                                array(
                                        'ok'      => false,
                                        'code'    => 'sync_error',
                                        'message' => __( 'Sincronizzazione interrotta da un errore: guarda il log Attività per il dettaglio.', 'nexus-news-ai' ),
                                )
                        );
                } catch ( Throwable $e ) {
                        return rest_ensure_response(
                                array(
                                        'ok'      => false,
                                        'code'    => 'sync_error',
                                        'message' => __( 'Sincronizzazione interrotta da un errore fatale: guarda il log Attività per il dettaglio.', 'nexus-news-ai' ),
                                )
                        );
                }

                $state_after = self::page_state();

                $before_total = absint( $state_before['sync']['total_rows'] );
                $after_total  = absint( $state_after['sync']['total_rows'] );
                $delta        = $after_total - $before_total;

                if ( $delta > 0 ) {
                        $message = sprintf(
                                /* translators: %d: numero di righe nuove scritte. */
                                __( 'Sincronizzazione completata: %d righe nuove scritte.', 'nexus-news-ai' ),
                                $delta
                        );
                } elseif ( $after_total > 0 ) {
                        $message = __( 'Sincronizzazione completata: nessuna riga nuova (dati già sincronizzati o nessun articolo recente da attribuire).', 'nexus-news-ai' );
                } else {
                        $message = __( 'Sincronizzazione completata ma nessuna riga scritta: controlla le righe di log qui sotto (articoli recenti assenti o dati GSC/GA4 non ancora disponibili).', 'nexus-news-ai' );
                }

                return rest_ensure_response(
                        array(
                                'ok'         => true,
                                'code'       => 'ok',
                                'message'    => $message,
                                'before'     => $before_total,
                                'after'      => $after_total,
                                'new_rows'   => max( 0, $delta ),
                                'state'      => $state_after,
                                'activities' => self::recent_sync_activities( $start_mysql ),
                        )
                );
        }

        /* ============ STATO PAGINA (onesto, READ-ONLY) ============ */

        /**
         * Stato completo renderizzato in pagina (e riportato dalle REST).
         *
         * Onestà dei stati: valori vuoti reali ('' / false / has_data=false)
         * quando non ci sono dati — MAI zeri finti né placeholder.
         *
         * @return array
         */
        public static function page_state() {
                $state = array(
                        'google_connected'     => false,
                        'token_state'          => 'missing', // missing | ok | refresh_failed.
                        'gsc_site_url'         => '',
                        'ga4_property_id'      => '',
                        'gsc_configured'       => false,
                        'ga4_configured'       => false,
                        'metrics_sync_enabled' => false,
                        'cron_scheduled'       => false,
                        'cron_next_run'        => '',
                        'settings_url'         => admin_url( 'admin.php?page=nexus-settings-sistema' ),
                        'sync'                 => array(
                                'has_data'            => false,
                                'last_date'           => '',
                                'last_date_rows'      => 0,
                                'last_date_by_source' => array(),
                                'total_rows'          => 0,
                                'posts_total'         => 0,
                        ),
                );

                // Impostazioni plugin (chiave 'gsc_site_url' = STESSA chiave
                // letta da Nexus_Metrics::do_sync a ~riga 269).
                $settings = self::plugin_settings();

                $state['gsc_site_url'] = isset( $settings['gsc_site_url'] ) ? trim( (string) $settings['gsc_site_url'] ) : '';
                $ga4_prop              = isset( $settings['ga4_property_id'] ) ? trim( (string) $settings['ga4_property_id'] ) : '';

                $state['ga4_property_id']      = $ga4_prop;
                $state['gsc_configured']       = ( '' !== $state['gsc_site_url'] );
                $state['ga4_configured']       = ( '' !== $ga4_prop && (bool) preg_match( '/^\d+$/', $ga4_prop ) );
                $state['metrics_sync_enabled'] = isset( $settings['metrics_sync_enabled'] ) && 1 === absint( $settings['metrics_sync_enabled'] );

                // Connessione Google + stato token (mai il token stesso).
                if ( class_exists( 'Nexus_Google_Auth' ) && method_exists( 'Nexus_Google_Auth', 'is_connected' ) ) {
                        $connected = false;

                        try {
                                $connected = (bool) Nexus_Google_Auth::is_connected();
                        } catch ( Exception $e ) {
                                $connected = false;
                        } catch ( Throwable $e ) {
                                $connected = false;
                        }

                        if ( $connected ) {
                                $state['google_connected'] = true;

                                if ( method_exists( 'Nexus_Google_Auth', 'get_access_token' ) ) {
                                        $token = Nexus_Google_Auth::get_access_token();

                                        if ( is_wp_error( $token ) || ! is_string( $token ) || '' === $token ) {
                                                $state['token_state'] = 'refresh_failed';
                                        } else {
                                                $state['token_state'] = 'ok';
                                        }
                                } else {
                                        // Modulo presente ma helper assente: connesso
                                        // secondo is_connected() (stato onesto noto).
                                        $state['token_state'] = 'ok';
                                }
                        }
                }

                // Cron metriche: hook di Nexus_Metrics (const pubblica).
                $metrics_hook = 'nexus_metrics_sync';

                if ( defined( 'Nexus_Metrics::HOOK_SYNC' ) ) {
                        $metrics_hook = (string) constant( 'Nexus_Metrics::HOOK_SYNC' );
                }

                $next = wp_next_scheduled( $metrics_hook );

                if ( $next ) {
                        $state['cron_scheduled'] = true;
                        $state['cron_next_run']  = wp_date( 'Y-m-d H:i', $next );
                }

                // Righe metriche: MAX(metric_date), COUNT del giorno per
                // sorgente, totali. Tutte le letture con guardia: se la tabella
                // manca o la query fallisce lo stato resta "vuoto onesto".
                $state['sync'] = self::metrics_summary();

                return $state;
        }

        /**
         * Riepilogo righe della tabella nexus_metrics (READ-ONLY).
         *
         * @return array
         */
        private static function metrics_summary() {
                global $wpdb;

                $empty = array(
                        'has_data'            => false,
                        'last_date'           => '',
                        'last_date_rows'      => 0,
                        'last_date_by_source' => array(),
                        'total_rows'          => 0,
                        'posts_total'         => 0,
                );

                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'tables' ) ) {
                        return $empty;
                }

                try {
                        $tables = Nexus_DB::tables();
                } catch ( Throwable $e ) {
                        return $empty;
                }

                if ( empty( $tables['metrics'] ) ) {
                        return $empty;
                }

                $table = (string) $tables['metrics'];

                $totals = $wpdb->get_row( "SELECT MAX(metric_date) AS last_date, COUNT(*) AS total_rows, COUNT(DISTINCT post_id) AS posts_total FROM {$table}" ); // phpcs:ignore WordPress.DB

                if ( ! $totals || null === $totals->last_date ) {
                        return $empty;
                }

                $summary = array(
                        'has_data'            => true,
                        'last_date'           => (string) $totals->last_date,
                        'last_date_rows'      => 0,
                        'last_date_by_source' => array(),
                        'total_rows'          => absint( $totals->total_rows ),
                        'posts_total'         => absint( $totals->posts_total ),
                );

                $by_source = $wpdb->get_results( $wpdb->prepare( "SELECT source, COUNT(*) AS n FROM {$table} WHERE metric_date = %s GROUP BY source", $summary['last_date'] ) ); // phpcs:ignore WordPress.DB

                if ( is_array( $by_source ) ) {
                        foreach ( $by_source as $row ) {
                                $source = (string) $row->source;

                                if ( '' !== $source ) {
                                        $summary['last_date_by_source'][ $source ] = absint( $row->n );
                                }
                        }
                }

                // COUNT del giorno: la query dei totali conta TUTTE le righe
                // (non solo quelle del giorno) — il conteggio del giorno va
                // sommato dai gruppi per sorgente appena letti.
                $day_rows = 0;

                foreach ( $summary['last_date_by_source'] as $n ) {
                        $day_rows += absint( $n );
                }

                $summary['last_date_rows'] = $day_rows;

                return $summary;
        }

        /**
         * Ultime righe di log attività 'metrics_sync' scritte da un giro
         * (created_at >= $start_mysql, limite 5, più recenti prima).
         *
         * @param string $start_mysql Soglia temporale (current_time('mysql') del via).
         * @return array Array di array {created_at, status, detail}.
         */
        private static function recent_sync_activities( $start_mysql ) {
                global $wpdb;

                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'tables' ) ) {
                        return array();
                }

                try {
                        $tables = Nexus_DB::tables();
                } catch ( Throwable $e ) {
                        return array();
                }

                if ( empty( $tables['activity_logs'] ) ) {
                        return array();
                }

                $table = (string) $tables['activity_logs'];

                $rows = $wpdb->get_results( $wpdb->prepare( "SELECT created_at, status, detail FROM {$table} WHERE action = 'metrics_sync' AND created_at >= %s ORDER BY id DESC LIMIT 5", $start_mysql ) ); // phpcs:ignore WordPress.DB

                if ( ! is_array( $rows ) ) {
                        return array();
                }

                $out = array();

                foreach ( $rows as $row ) {
                        $out[] = array(
                                'created_at' => (string) $row->created_at,
                                'status'     => (string) $row->status,
                                'detail'     => (string) $row->detail,
                        );
                }

                return $out;
        }

        /* ============ VERIFICA PROPERTY GSC (il cuore) ============ */

        /**
         * Verifica live della property GSC e (solo su esito OK) salvataggio.
         *
         * @param string $property URL property richiesto ('' = solo lista).
         * @return array {ok, code, message?, found?, saved?, site_url?,
         *                permission?, properties[], state?}
         */
        private static function verify_property( $property ) {
                $out = array(
                        'ok'         => false,
                        'code'       => 'ok',
                        'found'      => false,
                        'saved'      => false,
                        'site_url'   => '',
                        'permission' => '',
                        'properties' => array(),
                );

                // Guard 1: modulo Google Auth (READ-ONLY).
                if ( ! class_exists( 'Nexus_Google_Auth' ) || ! method_exists( 'Nexus_Google_Auth', 'get_access_token' ) ) {
                        $out['code']    = 'no_token';
                        $out['message'] = __( 'Modulo Google Auth non disponibile: controlla che il plugin sia aggiornato.', 'nexus-news-ai' );

                        return $out;
                }

                // Guard 2: token (string|WP_Error). MAI loggato, mai restituito.
                $token = Nexus_Google_Auth::get_access_token();

                if ( is_wp_error( $token ) || ! is_string( $token ) || '' === $token ) {
                        $out['code']    = 'no_token';
                        $out['message'] = __( 'Token Google assente o scaduto: collega Google dalle Impostazioni (flusso OAuth) e riprova.', 'nexus-news-ai' );

                        return $out;
                }

                // Lista property dell'account (GET sites).
                $response = wp_remote_get(
                        self::GSC_SITES_ENDPOINT,
                        array(
                                'timeout' => self::HTTP_TIMEOUT,
                                'headers' => array(
                                        'Authorization' => 'Bearer ' . $token,
                                        'Accept'        => 'application/json',
                                ),
                        )
                );

                if ( is_wp_error( $response ) ) {
                        $out['code']    = 'network';
                        $out['message'] = __( 'Google non raggiungibile (errore di rete o timeout): riprova tra qualche istante.', 'nexus-news-ai' );

                        return $out;
                }

                $status = absint( wp_remote_retrieve_response_code( $response ) );

                if ( 401 === $status ) {
                        $out['code']    = 'refresh_failed';
                        $out['message'] = __( 'Token di accesso rifiutato da Google (401): riconnetti Google dalle Impostazioni.', 'nexus-news-ai' );

                        return $out;
                }

                if ( 403 === $status ) {
                        $out['code']    = 'forbidden';
                        $out['message'] = __( 'Accesso negato dall\'API Search Console (403): verifica che l\'API sia abilitata in Google Cloud Console e che l\'utente abbia accesso alla property.', 'nexus-news-ai' );

                        return $out;
                }

                if ( 200 !== $status ) {
                        $out['code']    = 'http_error';
                        $out['message'] = sprintf(
                                /* translators: %d: codice HTTP di risposta di Google. */
                                __( 'Google ha risposto con un errore HTTP %d: riprova più tardi.', 'nexus-news-ai' ),
                                $status
                        );

                        return $out;
                }

                $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

                if ( ! is_array( $body ) || ! isset( $body['sites'] ) || ! is_array( $body['sites'] ) ) {
                        $out['code']    = 'http_error';
                        $out['message'] = __( 'Risposta inattesa dall\'API Search Console: nessuna lista property leggibile.', 'nexus-news-ai' );

                        return $out;
                }

                // Lista property disponibili (siteUrl + permissionLevel), con
                // flag matched risolto dal confronto normalizzato.
                $sites       = $body['sites'];
                $properties  = array();
                $matched_key = -1;

                foreach ( $sites as $site ) {
                        if ( ! is_array( $site ) || empty( $site['siteUrl'] ) ) {
                                continue;
                        }

                        $site_url  = (string) $site['siteUrl'];
                        $is_match  = ( '' !== $property && self::site_matches( $property, $site_url ) );

                        if ( $is_match && -1 === $matched_key ) {
                                $matched_key = count( $properties );
                        }

                        $properties[] = array(
                                'site_url'        => $site_url,
                                'permission'      => isset( $site['permissionLevel'] ) ? (string) $site['permissionLevel'] : '',
                                'matched'         => $is_match,
                        );
                }

                $out['properties'] = $properties;

                // Solo lista richiesta (o input vuoto): esito ok senza match.
                if ( '' === $property ) {
                        $out['ok']    = true;
                        $out['state'] = self::page_state();

                        return $out;
                }

                if ( -1 === $matched_key ) {
                        // Nessun match nella lista: GET site singola per
                        // distinguere 403 (property non verificata per questo
                        // account) da 404 (property inesistente). Il token già
                        // validato viene riusato (nessun refresh doppio).
                        return self::verify_single_site( $property, $out, $token );
                }

                $match = $properties[ $matched_key ];

                // Property presente ma NON verificata per l'account: 403 onesto.
                if ( 'siteUnverifiedUser' === $match['permission'] ) {
                        $out['code']    = 'forbidden';
                        $out['message'] = __( 'La property compare nell\'account ma NON è verificata (permesso "siteUnverifiedUser"): completa la verifica della proprietà in Search Console e riprova.', 'nexus-news-ai' );
                        $out['found']   = true;
                        $out['site_url']   = $match['site_url'];
                        $out['permission'] = $match['permission'];

                        return $out;
                }

                // Verifica OK: salva il valore CANONICO restituito da Google
                // (siteUrl esatta) sulla chiave 'gsc_site_url' dell'array
                // settings — la stessa chiave che legge Nexus_Metrics.
                $saved = self::save_gsc_site_url( $match['site_url'] );

                $out['ok']         = true;
                $out['code']       = $saved ? 'ok' : 'save_failed';
                $out['found']      = true;
                $out['saved']      = $saved;
                $out['site_url']   = $match['site_url'];
                $out['permission'] = $match['permission'];
                $out['message']    = $saved
                        ? __( 'Property verificata e salvata nelle impostazioni.', 'nexus-news-ai' )
                        : __( 'Property verificata ma salvataggio fallito: salvala a mano nelle Impostazioni (tab Sistema).', 'nexus-news-ai' );
                $out['state']      = self::page_state();

                return $out;
        }

        /**
         * GET site singola (URL-encoded) per distinguere 403 da 404 quando la
         * property non compare nella lista dell'account.
         *
         * @param string $property URL property richiesto.
         * @param array  $out      Array di esito da arricchire.
         * @param string $token    Access token già validato (MAI loggato).
         * @return array
         */
        private static function verify_single_site( $property, $out, $token ) {
                // Il token arriva già validato da verify_property (stringa non
                // vuota): guardia difensiva senza ulteriori chiamate di rete.
                if ( ! is_string( $token ) || '' === $token ) {
                        $out['code']    = 'no_token';
                        $out['message'] = __( 'Token Google assente o scaduto: collega Google dalle Impostazioni (flusso OAuth) e riprova.', 'nexus-news-ai' );

                        return $out;
                }

                $url = self::GSC_SITES_ENDPOINT . '/' . rawurlencode( $property );

                $response = wp_remote_get(
                        $url,
                        array(
                                'timeout' => self::HTTP_TIMEOUT,
                                'headers' => array(
                                        'Authorization' => 'Bearer ' . $token,
                                        'Accept'        => 'application/json',
                                ),
                        )
                );

                if ( is_wp_error( $response ) ) {
                        $out['code']    = 'network';
                        $out['message'] = __( 'Google non raggiungibile (errore di rete o timeout): riprova tra qualche istante.', 'nexus-news-ai' );

                        return $out;
                }

                $status = absint( wp_remote_retrieve_response_code( $response ) );

                if ( 200 === $status ) {
                        // Edge: la lista non la mostrava ma la GET singola ok.
                        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

                        $site_url = ( is_array( $body ) && ! empty( $body['siteUrl'] ) ) ? (string) $body['siteUrl'] : $property;
                        $saved    = self::save_gsc_site_url( $site_url );

                        $out['ok']         = true;
                        $out['code']       = $saved ? 'ok' : 'save_failed';
                        $out['found']      = true;
                        $out['saved']      = $saved;
                        $out['site_url']   = $site_url;
                        $out['permission'] = ( is_array( $body ) && isset( $body['permissionLevel'] ) ) ? (string) $body['permissionLevel'] : '';
                        $out['message']    = $saved
                                ? __( 'Property verificata e salvata nelle impostazioni.', 'nexus-news-ai' )
                                : __( 'Property verificata ma salvataggio fallito: salvala a mano nelle Impostazioni (tab Sistema).', 'nexus-news-ai' );
                        $out['state']      = self::page_state();

                        return $out;
                }

                if ( 403 === $status ) {
                        $out['code']    = 'forbidden';
                        $out['message'] = __( 'La property esiste ma NON è verificata per questo account Google (403): completa la verifica della proprietà in Search Console e riprova.', 'nexus-news-ai' );

                        return $out;
                }

                if ( 404 === $status ) {
                        $out['code']    = 'not_found';
                        $out['message'] = __( 'Property non trovata nell\'account collegato (404): controlla l\'URL esatto registrato in Search Console (protocollo incluso, eventuale sottocartella, dominio sc-domain).', 'nexus-news-ai' );

                        return $out;
                }

                if ( 401 === $status ) {
                        $out['code']    = 'refresh_failed';
                        $out['message'] = __( 'Token di accesso rifiutato da Google (401): riconnetti Google dalle Impostazioni.', 'nexus-news-ai' );

                        return $out;
                }

                $out['code']    = 'http_error';
                $out['message'] = sprintf(
                        /* translators: %d: codice HTTP di risposta di Google. */
                        __( 'Google ha risposto con un errore HTTP %d: riprova più tardi.', 'nexus-news-ai' ),
                        $status
                );

                return $out;
        }

        /**
         * Salva la property GSC verificata sull'array settings esistente
         * (chiave 'gsc_site_url', la stessa letta da Nexus_Metrics), con la
         * funzione di update CANONICA del plugin Nexus_DB::update_settings().
         *
         * @param string $site_url Property esatta restituita da Google.
         * @return bool
         */
        private static function save_gsc_site_url( $site_url ) {
                if ( '' === trim( (string) $site_url ) ) {
                        return false;
                }

                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'update_settings' ) ) {
                        return false;
                }

                $updated = Nexus_DB::update_settings(
                        array(
                                'gsc_site_url' => sanitize_text_field( (string) $site_url ),
                        )
                );

                if ( ! is_array( $updated ) ) {
                        return false;
                }

                $saved = isset( $updated['gsc_site_url'] ) ? trim( (string) $updated['gsc_site_url'] ) : '';

                return ( '' !== $saved && self::site_matches( $saved, $site_url ) );
        }

        /* ============ HELPER URL PROPERTY ============ */

        /**
         * Confronto tollerante fra due URL property (input utente vs siteUrl
         * di Google): uguaglianza esatta, poi normalizzazione (scheme/host
         * minuscoli, slash finale rimosso, dominio sc-domain minuscolo).
         * MAI rimosso "www": https://www.example.com e https://example.com
         * sono property DIVERSE in Search Console.
         *
         * @param string $candidate URL richiesto (input utente).
         * @param string $site_url  Property esatta di Google.
         * @return bool
         */
        private static function site_matches( $candidate, $site_url ) {
                $candidate = trim( (string) $candidate );
                $site_url  = trim( (string) $site_url );

                if ( '' === $candidate || '' === $site_url ) {
                        return false;
                }

                if ( $candidate === $site_url ) {
                        return true;
                }

                $norm_candidate = self::normalize_site_url( $candidate );
                $norm_site_url  = self::normalize_site_url( $site_url );

                return ( '' !== $norm_candidate && $norm_candidate === $norm_site_url );
        }

        /**
         * Normalizzazione per confronto: scheme+host minuscoli, slash finale
         * rimosso, sc-domain minuscolo. Input senza scheme (es. esempio.com)
         * viene trattato come https://esempio.com (variante più probabile).
         *
         * @param string $url URL property.
         * @return string Forma normalizzata ('' se non interpretabile).
         */
        private static function normalize_site_url( $url ) {
                $url = trim( (string) $url );

                if ( '' === $url ) {
                        return '';
                }

                // Property di dominio: sc-domain:esempio.com (case-insensitive).
                if ( 0 === strpos( $url, 'sc-domain:' ) ) {
                        return 'sc-domain:' . strtolower( trim( substr( $url, 10 ) ) );
                }

                // Variante senza scheme: https:// è lo schema registrato più comune.
                if ( 0 !== strpos( $url, 'http://' ) && 0 !== strpos( $url, 'https://' ) ) {
                        $url = 'https://' . $url;
                }

                $parts = parse_url( $url );

                if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
                        return '';
                }

                $scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'https';
                $host   = strtolower( (string) $parts['host'] );
                $path   = isset( $parts['path'] ) ? (string) $parts['path'] : '';
                $port   = isset( $parts['port'] ) ? (int) $parts['port'] : 0;

                $out = $scheme . '://' . $host . ( $port > 0 ? ':' . $port : '' );

                if ( '' !== $path && '/' !== $path ) {
                        $out .= rtrim( $path, '/' );
                }

                return $out;
        }

        /**
         * Impostazioni plugin con guardia su Nexus_DB (mai fatal, sempre array).
         *
         * @return array
         */
        private static function plugin_settings() {
                if ( class_exists( 'Nexus_Dedup' ) && method_exists( 'Nexus_Dedup', 'settings_guarded' ) ) {
                        return Nexus_Dedup::settings_guarded();
                }

                // Fallback locale (caricamento senza Nexus_Dedup): comportamento
                // canonico Nexus_DB::get_settings().
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'get_settings' ) ) {
                        return array();
                }

                $settings = Nexus_DB::get_settings();

                return is_array( $settings ) ? $settings : array();
        }
}

/* v1.1.14 (P1) — init self-registrato: solo add_action, NESSUNA chiamata di rete.
 * Il require del file va aggiunto al bootstrap (1 riga, contratto col glue). */
add_action( 'nexus_modules_init', array( 'Nexus_Google_Wizard', 'init' ) );
