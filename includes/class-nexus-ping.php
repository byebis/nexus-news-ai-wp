<?php
/**
 * Nexus Ping — indicizzazione attiva (IndexNow + WebSub) per i post WP reali.
 *
 * Contratto pubblico (CONTRATTO v1.0.2, sezione "Ping indicizzazione"):
 * Nexus_Ping::init()         — registra query var 'nexus_indexnow', il serving
 *                              della chiave su template_redirect e l'hook
 *                              transition_post_status che schedula il ping.
 * Nexus_Ping::ping_article() — listener dell'evento WP-Cron 'nexus_ping_article':
 *                              invia i ping IndexNow e/o WebSub (mai fatale, mai blocco).
 * Nexus_Ping::deactivate()   — static, rimuove eventuali ping schedulati
 *                              (cablabile con register_deactivation_hook nel bootstrap).
 *
 * Comportamento:
 * - Al publish di un post_type 'nexus_article' viene schedulato un evento
 *   singolo 'nexus_ping_article' a time()+30 (NON blocca la pubblicazione).
 * - IndexNow (enable_indexnow=1): se indexnow_key è vuota viene generata con
 *   wp_generate_password(32,false) e salvata via Nexus_DB::update_settings();
 *   POST JSON a https://api.indexnow.org/indexnow con {host, key, keyLocation, urlList}.
 * - keyLocation = home_url('/?nexus_indexnow=' . key): la chiave viene servita
 *   in chiaro text/plain quando il parametro GET coincide con la chiave salvata
 *   (altrimenti 404), così i motori possono verificarne la proprietà del sito.
 * - WebSub (enable_websub=1): POST form-encoded a https://pubsubhubbub.appspot.com/
 *   con hub.mode=publish e hub.url ripetuto per il feed principale e /feed/.
 * - Tutti i ping: timeout 15s, log attività Nexus_DB::insert_activity(0,'ping',...)
 *   best-effort, nessuna eccezione propagata, mai blocco del flusso editoriale.
 *
 * Wiring integratore (in nexus-news-ai.php):
 *   require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-ping.php';
 *   Nexus_Ping::init();
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Ping {

        /**
         * Evento WP-Cron singolo che esegue i ping dopo il publish.
         */
        const HOOK_PING = 'nexus_ping_article';

        /**
         * Ritardo (secondi) tra il publish e l'esecuzione dei ping.
         */
        const PING_DELAY = 30;

        /**
         * Timeout HTTP (secondi) per le richieste IndexNow / WebSub.
         */
        const HTTP_TIMEOUT = 15;

        /**
         * Bootstrap modulo.
         *
         * @return void
         */
        public static function init() {
                add_filter( 'query_vars', array( __CLASS__, 'add_query_var' ) );
                add_action( 'template_redirect', array( __CLASS__, 'serve_indexnow_key' ) );
                add_action( 'transition_post_status', array( __CLASS__, 'on_transition_post_status' ), 10, 3 );
                add_action( self::HOOK_PING, array( __CLASS__, 'ping_article' ) );
        }

        /**
         * Aggiunge la query var 'nexus_indexnow' a quelle riconosciute da WP.
         *
         * @param array $vars Query vars esistenti.
         * @return array
         */
        public static function add_query_var( $vars ) {
                $vars[] = 'nexus_indexnow';

                return $vars;
        }

        /**
         * Serving della chiave IndexNow su template_redirect.
         *
         * Se il GET contiene 'nexus_indexnow' e il valore coincide con la chiave
         * salvata nelle impostazioni (chiave non vuota) → risposta text/plain con
         * la chiave (formato richiesto dai motori per verificare la proprietà).
         * In ogni altro caso → 404 e terminazione.
         *
         * @return void
         */
        public static function serve_indexnow_key() {
                if ( ! isset( $_GET['nexus_indexnow'] ) ) {
                        return;
                }

                $requested = sanitize_text_field( wp_unslash( (string) $_GET['nexus_indexnow'] ) );

                $key = self::indexnow_key();

                if ( '' === $key || '' === $requested || ! hash_equals( $key, $requested ) ) {
                        status_header( 404 );
                        exit;
                }

                header( 'Content-Type: text/plain; charset=utf-8' );

                // La chiave è alfanumerica ([a-zA-Z0-9]): output byte-esatto in text/plain.
                echo $key;
                exit;
        }

        /**
         * Hook transition_post_status: al publish di un nexus_article schedula
         * l'evento singolo dei ping a time()+30, senza bloccare la pubblicazione.
         *
         * @param string  $new_status Nuovo stato del post.
         * @param string  $old_status Stato precedente del post.
         * @param WP_Post $post       Oggetto post.
         * @return void
         */
        public static function on_transition_post_status( $new_status, $old_status, $post ) {
                if ( 'publish' !== $new_status || ! $post instanceof WP_Post ) {
                        return;
                }

                if ( 'nexus_article' !== $post->post_type ) {
                        return;
                }

                $post_id = (int) $post->ID;

                if ( $post_id <= 0 ) {
                        return;
                }

                // Anti-duplicato: se per questo post esiste già un ping in attesa, non rischedulare.
                if ( wp_next_scheduled( self::HOOK_PING, array( $post_id ) ) ) {
                        return;
                }

                wp_schedule_single_event( time() + self::PING_DELAY, self::HOOK_PING, array( $post_id ) );
        }

        /**
         * Listener dell'evento 'nexus_ping_article': esegue i ping di indicizzazione.
         *
         * - Guard: post esistente, post_type 'nexus_article' e stato 'publish'.
         * - IndexNow (enable_indexnow=1): POST JSON (timeout 15s), chiave
         *   auto-generata e salvata se vuota.
         * - WebSub (enable_websub=1): POST form-encoded (timeout 15s) ai feed.
         * - Log attività best-effort per ogni esito; nessuna eccezione propagata.
         *
         * @param int $post_id ID del post WordPress pubblicato.
         * @return void
         */
        public static function ping_article( $post_id ) {
                $post_id = absint( $post_id );

                if ( $post_id <= 0 || ! class_exists( 'Nexus_DB' ) ) {
                        return;
                }

                $post = get_post( $post_id );

                // Guard: post esistente, tipo corretto e realmente pubblicato.
                if ( ! $post instanceof WP_Post || 'nexus_article' !== $post->post_type || 'publish' !== $post->post_status ) {
                        return;
                }

                $settings = Nexus_DB::get_settings();

                $enable_indexnow = ! empty( $settings['enable_indexnow'] );
                $enable_websub   = ! empty( $settings['enable_websub'] );

                if ( ! $enable_indexnow && ! $enable_websub ) {
                        return;
                }

                $permalink = get_permalink( $post );

                if ( ! is_string( $permalink ) || '' === $permalink ) {
                        return;
                }

                $permalink = esc_url_raw( $permalink );

                if ( $enable_indexnow ) {
                        try {
                                self::ping_indexnow( $settings, $permalink );
                        } catch ( Exception $e ) {
                                self::log_activity( 'IndexNow errore: ' . $e->getMessage(), 'error' );
                        } catch ( Throwable $e ) {
                                self::log_activity( 'IndexNow errore fatale: ' . $e->getMessage(), 'error' );
                        }
                }

                if ( $enable_websub ) {
                        try {
                                self::ping_websub();
                        } catch ( Exception $e ) {
                                self::log_activity( 'WebSub errore: ' . $e->getMessage(), 'error' );
                        } catch ( Throwable $e ) {
                                self::log_activity( 'WebSub errore fatale: ' . $e->getMessage(), 'error' );
                        }
                }
        }

        /**
         * Deattivazione plugin: rimuove eventuali ping singoli schedulati.
         * Chiamata static via register_deactivation_hook nel bootstrap (facoltativa).
         *
         * @return void
         */
        public static function deactivate() {
                wp_clear_scheduled_hook( self::HOOK_PING );
        }

        /* ============ HELPER PRIVATI ============ */

        /**
         * Ping IndexNow per un permalink pubblicato.
         *
         * @param array  $settings  Impostazioni Nexus (get_settings()).
         * @param string $permalink Permalink del post pubblicato.
         * @return void
         */
        private static function ping_indexnow( $settings, $permalink ) {
                $key = isset( $settings['indexnow_key'] ) ? trim( (string) $settings['indexnow_key'] ) : '';

                // Chiave assente: generazione automatica e persistenza (best-effort).
                if ( '' === $key && class_exists( 'Nexus_DB' ) ) {
                        $key = wp_generate_password( 32, false );

                        try {
                                Nexus_DB::update_settings( array( 'indexnow_key' => $key ) );
                        } catch ( Exception $e ) {
                                self::log_activity( 'IndexNow salvataggio chiave fallito', 'error' );

                                return;
                        } catch ( Throwable $e ) {
                                self::log_activity( 'IndexNow salvataggio chiave fallito', 'error' );

                                return;
                        }
                }

                if ( '' === $key ) {
                        return;
                }

                $host = (string) parse_url( home_url(), PHP_URL_HOST );

                if ( '' === $host ) {
                        self::log_activity( 'IndexNow host del sito non disponibile', 'error' );

                        return;
                }

                $payload = array(
                        'host'        => $host,
                        'key'         => $key,
                        'keyLocation' => home_url( '/?nexus_indexnow=' . $key ),
                        'urlList'     => array( $permalink ),
                );

                $response = wp_remote_post(
                        'https://api.indexnow.org/indexnow',
                        array(
                                'timeout' => self::HTTP_TIMEOUT,
                                'headers' => array( 'Content-Type' => 'application/json' ),
                                'body'    => (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ),
                        )
                );

                if ( is_wp_error( $response ) ) {
                        self::log_activity( 'IndexNow errore: ' . $response->get_error_message(), 'error' );

                        return;
                }

                $code = (int) wp_remote_retrieve_response_code( $response );

                if ( $code >= 200 && $code < 300 ) {
                        self::log_activity( 'IndexNow HTTP ' . $code, 'success' );
                } else {
                        self::log_activity( 'IndexNow HTTP ' . $code, 'error' );
                }
        }

        /**
         * Ping WebSub (PubSubHubbub) per i feed RSS del sito.
         *
         * @return void
         */
        private static function ping_websub() {
                $feed_link = get_feed_link();

                if ( ! is_string( $feed_link ) || '' === $feed_link ) {
                        $feed_link = home_url( '/feed/' );
                }

                $feed_link = esc_url_raw( $feed_link );

                // hub.url ripetuto: feed principale del sito e feed standard /feed/.
                $body = 'hub.mode=publish'
                        . '&hub.url=' . rawurlencode( $feed_link )
                        . '&hub.url=' . rawurlencode( home_url( '/feed/' ) );

                $response = wp_remote_post(
                        'https://pubsubhubbub.appspot.com/',
                        array(
                                'timeout' => self::HTTP_TIMEOUT,
                                'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
                                'body'    => $body,
                        )
                );

                if ( is_wp_error( $response ) ) {
                        self::log_activity( 'WebSub errore: ' . $response->get_error_message(), 'error' );

                        return;
                }

                $code = (int) wp_remote_retrieve_response_code( $response );

                if ( $code >= 200 && $code < 300 ) {
                        self::log_activity( 'WebSub HTTP ' . $code, 'success' );
                } else {
                        self::log_activity( 'WebSub HTTP ' . $code, 'error' );
                }
        }

        /**
         * Legge la chiave IndexNow salvata nelle impostazioni ('' se non disponibile).
         *
         * @return string
         */
        private static function indexnow_key() {
                if ( ! class_exists( 'Nexus_DB' ) ) {
                        return '';
                }

                try {
                        $settings = Nexus_DB::get_settings();

                        return isset( $settings['indexnow_key'] ) ? trim( (string) $settings['indexnow_key'] ) : '';
                } catch ( Exception $e ) {
                        return '';
                } catch ( Throwable $e ) {
                        return '';
                }
        }

        /**
         * Log attività best-effort nella tabella activity_logs di Nexus.
         *
         * @param string $detail Descrizione dell'esito (es. 'IndexNow HTTP 200').
         * @param string $status 'success' oppure 'error'.
         * @return void
         */
        private static function log_activity( $detail, $status ) {
                try {
                        if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                                Nexus_DB::insert_activity( 0, 'ping', $detail, $status );
                        }
                } catch ( Exception $e ) {
                        // Log best-effort: mai interrompere il flusso per un errore di scrittura.
                } catch ( Throwable $e ) {
                        // Idem per errori PHP 7+.
                }
        }
}
