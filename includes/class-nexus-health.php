<?php
/**
 * Nexus Health — health check redazione (v1.1.14, pacchetto P3).
 *
 * Controlli periodici (cron WP-Cron ORARIO proprio, hook 'nexus_health_tick')
 * sullo stato di salute della redazione, con risultati salvati in UNA opzione
 * ('nexus_health_state', autoload no): la card in Dashboard legge SOLO
 * l'opzione — zero query pesanti a view-time.
 *
 * CHECKS:
 *  1. feeds  — Feed RSS morti: fonti RSS attive (term meta redazioni + fonti
 *     RSS globali) senza NESSUN articolo registrato sugli ultimi N giorni
 *     (default 7, costante DEAD_FEED_DAYS / filtro 'nexus_health_dead_feed_days')
 *     E senza raccolta riuscita recente (transient 'nexus_src_{key}' del
 *     dispatcher fonti assente → nessun fetch OK nelle ultime ~6h). Il segnale
 *     "articoli per fonte" usa l'host di source_url degli articoli (gli item
 *     RSS vivono sull'host del feed); il transient di early-exit copre i feed
 *     che rispondono ma non generano candidati (es. redirect Google News),
 *     evitando falsi positivi. Fonti mai raccolte con errori recenti
 *     (transient nexus_src_fail_/cool_) sono segnalate; con NESSUN agente mai
 *     eseguito il check resta neutro (niente allarmi su installazione vergine).
 *  2. jobs   — Agenti bloccati: lavori in stato 'running' nella tabella
 *     nexus_jobs con started_at più vecchio di X ore (default 3, costante
 *     STUCK_JOB_HOURS / filtro 'nexus_health_stuck_job_hours'). La coda già
 *     ri-schedula i running > 30min (Nexus_Queue::requeue_stale): un running
 *     a 3+ ore significa runner WP-Cron fermo o job davvero irrecuperabile.
 *  3. budget — Budget AI vicino al limite: costo del mese corrente
 *     (Nexus_DB::usage_stats('month'), query su indice created_at) vs setting
 *     'monthly_budget_usd' (0 = nessun limite → check neutro). warn >= 80%
 *     (filtro 'nexus_health_budget_warn_pct'), err >= 100%.
 *  4. cron   — Cron ferma: gli hook WP-Cron principali del plugin
 *     (nexus_cron_tick, nexus_queue_runner, nexus_metrics_sync,
 *     nexus_weekly_digest, nexus_evergreen_refresh) verificati SOLO se
 *     abilitati dalle impostazioni: mai schedulati o con next run nel passato
 *     da più di 1 ora (Cron const CRON_LAG) = WP-Cron fermo o evento perso.
 *  5. token  — Token Google scaduto: Nexus_Google_Auth::is_connected() true ma
 *     get_access_token() in errore (WP_Error). Il risultato (anche l'eventuale
 *     refresh di rete) resta in cache nell'opzione stato: la chiamata avviene
 *     SOLO dentro il giro orario del cron, MAI a view-time.
 *
 * NOTIFICHE: quando UN check NUOVO entra in warn/err (confronto con lo stato
 * precedente; re-notifica dello stesso stato solo dopo 24h — NOTIFY_REPEAT)
 * invia UN messaggio unico via Nexus_Notify::send_generic('health', ...) con
 * la stessa disciplina del pacchetto notify (canali + rate limit, mai segreti,
 * mai eccezioni). Il messaggio è in italiano plain come gli altri messaggi del
 * canale notifiche. Nessun canale configurato → nessun invio, nessun consumo.
 *
 * CARD DASHBOARD: Nexus_Health::render_card() (append in admin/views/dashboard.php)
 * renderizza server-side pallino stato per check (ok/warn/err con token CSS),
 * dettaglio breve per problemi attivi, timestamp ultimo giro e link alle pagine
 * di risoluzione. Mai girato → messaggio onesto "primo controllo entro ~5 minuti".
 *
 * AZIONE MANUALE: "Ricontrolla ora" → admin-post.php?action=nexus_health_run
 * (capability manage_options + nonce, redirect alla dashboard): esegue lo
 * stesso giro dei checks subito, senza aspettare il cron.
 *
 * PERIMETRO: nessuna modifica a queue/cron/sources/google-auth/pipeline/admin:
 * tutte le letture usano API pubbliche esistenti con guard class_exists +
 * method_exists e try/catch per check (un check in errore non blocca gli altri).
 *
 * @package Nexus_News_AI
 * @since   1.1.14
 */

defined( 'ABSPATH' ) || exit;

final class Nexus_Health {

        /**
         * Hook WP-Cron orario di questo modulo.
         */
        const CRON_HOOK = 'nexus_health_tick';

        /**
         * Schedule WP-Cron usato (core 'hourly').
         */
        const SCHEDULE = 'hourly';

        /**
         * Ritardo del primo giro dopo la schedulazione (300s = ~5 minuti,
         * coerente col messaggio onesto della card).
         */
        const FIRST_RUN_DELAY = 300;

        /**
         * Opzione stato (checks + ultimo giro + memoria anti-spam notifiche).
         */
        const OPTION_STATE = 'nexus_health_state';

        /**
         * Default giorni senza nuovi articoli per dichiarare morta una fonte RSS
         * (filtrabile con 'nexus_health_dead_feed_days').
         */
        const DEAD_FEED_DAYS = 7;

        /**
         * Default ore di stato 'running' oltre le quali un lavoro è "bloccato"
         * (filtrabile con 'nexus_health_stuck_job_hours').
         */
        const STUCK_JOB_HOURS = 3;

        /**
         * Ritardo massimo tollerato sull'evento WP-Cron: next run nel passato da
         * più di 1 ora = cron ferma (l'evento è dovuto ma non parte).
         */
        const CRON_LAG = HOUR_IN_SECONDS;

        /**
         * Re-notifica dello STESSO problema non prima di 24h (anti-spam).
         */
        const NOTIFY_REPEAT = DAY_IN_SECONDS;

        /**
         * TTL del mutex non bloccante che evita doppi giri (cron + azione manuale).
         */
        const RUN_LOCK_TTL = 120;

        /**
         * Cap di righe articolo esaminate per il check fonti (finestra N giorni):
         * il cap limita memoria e result set anche su configurazioni extreme.
         */
        const FEED_ROWS_CAP = 5000;

        /**
         * Massimo numero di fonti segnalate per check (dettaglio in card/notifica).
         */
        const MAX_REPORTED = 8;

        /**
         * Bootstrap modulo (self-init su nexus_modules_init).
         *
         * @return void
         */
        public static function init() {
                add_action( self::CRON_HOOK, array( 'Nexus_Health', 'handle_tick' ) );
                add_action( 'admin_enqueue_scripts', array( 'Nexus_Health', 'enqueue_assets' ) );
                add_action( 'admin_post_nexus_health_run', array( 'Nexus_Health', 'handle_manual_run' ) );

                self::sync_schedule();
        }

        /**
         * Hook di disattivazione plugin: rimuove l'evento schedulato e il mutex.
         * Registrato con register_deactivation_hook nello stesso file (il
         * bootstrap non è nel perimetro P3).
         *
         * @return void
         */
        public static function deactivate() {
                wp_clear_scheduled_hook( self::CRON_HOOK );
                delete_transient( 'nexus_health_run_lock' );
        }

        /**
         * Allinea l'evento WP-Cron orario: schedula il primo giro tra ~5 minuti
         * se assente. Idempotente, chiamato a ogni init (come Nexus_Metrics).
         *
         * @return void
         */
        public static function sync_schedule() {
                if ( wp_next_scheduled( self::CRON_HOOK ) ) {
                        return;
                }

                wp_schedule_event( time() + self::FIRST_RUN_DELAY, self::SCHEDULE, self::CRON_HOOK );
        }

        /**
         * Handler dell'evento 'nexus_health_tick': gira i checks con mutex
         * non bloccante (un giro manuale contemporaneo non duplica lavoro) e
         * salva lo stato. Mai fatale: qualunque errore qui dentro non deve
         * rompere il cron.
         *
         * @return void
         */
        public static function handle_tick() {
                try {
                        self::run_all();
                } catch ( \Throwable $e ) {
                        // Il cron orario non deve mai fallire: silenzio totale.
                        return;
                }
        }

        /**
         * Azione manuale "Ricontrolla ora" (admin-post.php?action=nexus_health_run):
         * capability manage_options + nonce, poi stesso giro dei checks e
         * redirect alla dashboard con flag di conferma.
         *
         * @return void
         */
        public static function handle_manual_run() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_die( esc_html__( 'Permessi insufficienti per eseguire i controlli.', 'nexus-news-ai' ) );
                }

                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- il nonce è verificato nella riga sotto.
                $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
                if ( ! wp_verify_nonce( $nonce, 'nexus_health_run' ) ) {
                        wp_die( esc_html__( 'Nonce non valido: ricarica la dashboard e riprova.', 'nexus-news-ai' ) );
                }

                self::run_all();

                $back = add_query_arg(
                        array(
                                'page'           => 'nexus-news',
                                'nxa_health_run' => '1',
                        ),
                        admin_url( 'admin.php' )
                );

                wp_safe_redirect( $back );
                exit;
        }

        /**
         * Enqueue del foglio stile SOLO sulla dashboard del plugin
         * (hook 'toplevel_page_nexus-news', stesso pattern per-vista di
         * class-nexus-admin.php che qui NON è toccato).
         *
         * @param string $hook Suffix della pagina admin corrente.
         * @return void
         */
        public static function enqueue_assets( $hook ) {
                if ( 'toplevel_page_nexus-news' !== (string) $hook ) {
                        return;
                }

                if ( ! file_exists( NEXUS_PLUGIN_DIR . 'admin/css/nexus-health.css' ) ) {
                        return;
                }

                wp_enqueue_style(
                        'nexus-health-css',
                        NEXUS_PLUGIN_URL . 'admin/css/nexus-health.css',
                        array( 'nexus-admin-css' ),
                        NEXUS_VERSION
                );
        }

        /* ============ ESECUZIONE CHECKS ============ */

        /**
         * Gira tutti i checks, salva lo stato nell'opzione e notifica i check
         * che ENTRANO (novità) in warn/err. Restituisce lo stato salvato.
         *
         * @return array Stato completo (struttura nel docblock di classe).
         */
        public static function run_all() {
                // Mutex non bloccante: cron e azione manuale non girano in parallelo.
                if ( get_transient( 'nexus_health_run_lock' ) ) {
                        return self::state();
                }
                set_transient( 'nexus_health_run_lock', time(), self::RUN_LOCK_TTL );

                try {
                        $state           = self::state();
                        $prev_checks     = isset( $state['checks'] ) && is_array( $state['checks'] ) ? $state['checks'] : array();
                        $prev_notify     = isset( $state['notify'] ) && is_array( $state['notify'] ) ? $state['notify'] : array();

                        $checks = array(
                                'feeds'  => self::run_check( 'feeds' ),
                                'jobs'   => self::run_check( 'jobs' ),
                                'budget' => self::run_check( 'budget' ),
                                'cron'   => self::run_check( 'cron' ),
                                'token'  => self::run_check( 'token' ),
                        );

                        $state['checks']   = $checks;
                        $state['last_run'] = time();

                        // Anti-spam notifiche: confronto con lo stato precedente.
                        $state['notify']    = self::merge_notify( $prev_notify, $prev_checks, $checks );
                        $notify_new_status  = array();

                        foreach ( $state['notify'] as $key => $entry ) {
                                if ( ! empty( $entry['pending'] ) ) {
                                        $notify_new_status[ $key ] = $entry;
                                }
                        }

                        update_option( self::OPTION_STATE, $state, false );

                        if ( ! empty( $notify_new_status ) ) {
                                self::notify_new( $notify_new_status, $checks );
                        }

                        return $state;
                } catch ( \Throwable $e ) {
                        return self::state();
                } finally {
                        delete_transient( 'nexus_health_run_lock' );
                }
        }

        /**
         * Esegue UN check con isolamento totale: un'eccezione diventa uno stato
         * 'err' onesto ("controllo non riuscito") senza bloccare gli altri.
         *
         * @param string $key Chiave del check (feeds|jobs|budget|cron|token).
         * @return array Risultato del check.
         */
        private static function run_check( $key ) {
                try {
                        if ( 'feeds' === $key ) {
                                return self::check_feeds();
                        }
                        if ( 'jobs' === $key ) {
                                return self::check_jobs();
                        }
                        if ( 'budget' === $key ) {
                                return self::check_budget();
                        }
                        if ( 'cron' === $key ) {
                                return self::check_cron();
                        }
                        if ( 'token' === $key ) {
                                return self::check_token();
                        }
                } catch ( \Throwable $e ) {
                        return array(
                                'status' => 'err',
                                'broken' => true,
                        );
                }

                return array(
                        'status' => 'err',
                        'broken' => true,
                );
        }

        /**
         * Stato corrente letto dall'opzione (view-time: ZERO query pesanti).
         *
         * @return array
         */
        public static function state() {
                $state = get_option( self::OPTION_STATE, array() );

                if ( ! is_array( $state ) ) {
                        return array();
                }

                return $state;
        }

        /* ============ CHECK 1 — FEED RSS MORTI ============ */

        /**
         * Fonti RSS attive senza nuovi articoli da N giorni.
         *
         * Segnale articoli: host di source_url degli articoli creati negli ultimi
         * N giorni (una sola query con finestra, risultato piccolo). Segnale
         * raccolta OK: transient 'nexus_src_{md5(rss|url)}' del dispatcher fonti
         * (6h, scritto a ogni fetch riuscito). Segnale errori recenti: transient
         * 'nexus_src_fail_{key}' / 'nexus_src_cool_{key}'.
         *
         * Stati: 'ok' (tutte le fonti vive / nessuna fonte configurata / redazione
         * mai eseguita), 'warn' (almeno una fonte sospetta).
         *
         * @return array
         */
        private static function check_feeds() {
                $days    = self::dead_feed_days();
                $sources = self::rss_sources();

                $result = array(
                        'status'  => 'ok',
                        'sources' => count( $sources ),
                        'days'    => $days,
                        'dead'    => array(),
                        'errors'  => array(),
                        'virgin'  => false,
                );

                if ( empty( $sources ) ) {
                        return $result;
                }

                // Redazione mai eseguita: nessun allarme su installazione vergine
                // (una fonte appena configurata non può essere "morta").
                if ( ! self::pipeline_ever_ran() ) {
                        $result['virgin'] = true;
                        return $result;
                }

                // Host → ultimo articolo registrato nella finestra (UNA query).
                $host_last = self::article_hosts_in_window( $days );

                $now_local = current_time( 'timestamp' );
                $cutoff    = $now_local - $days * DAY_IN_SECONDS;

                foreach ( $sources as $source ) {
                        $host = self::url_host( $source['url'] );
                        if ( '' === $host ) {
                                continue; // URL non interpretabile: nessun match possibile, skip onesto.
                        }

                        $key = md5( 'rss|' . trim( (string) $source['url'] ) );

                        if ( isset( $host_last[ $host ] ) && (int) $host_last[ $host ] >= $cutoff ) {
                                continue; // Articoli recenti sull'host della fonte: viva.
                        }

                        // Raccolta riuscita di recente (feed risponde, AI non ha
                        // prodotto candidati pubblicabili): viva ai fini del check.
                        if ( get_transient( 'nexus_src_' . $key ) ) {
                                continue;
                        }

                        if ( get_transient( 'nexus_src_cool_' . $key ) || get_transient( 'nexus_src_fail_' . $key ) ) {
                                // Mai un item E errori di raccolta recenti: fonte ferma e in errore.
                                $result['errors'][] = $host;
                                continue;
                        }

                        // Nessun item nella finestra, nessun segnale recente: morta o ferma.
                        $result['dead'][] = $host;
                }

                $result['dead']   = array_values( array_unique( $result['dead'] ) );
                $result['errors'] = array_values( array_unique( $result['errors'] ) );
                $result['dead']   = array_slice( $result['dead'], 0, self::MAX_REPORTED );
                $result['errors'] = array_slice( $result['errors'], 0, self::MAX_REPORTED );

                if ( ! empty( $result['dead'] ) || ! empty( $result['errors'] ) ) {
                        $result['status'] = 'warn';
                }

                return $result;
        }

        /**
         * Elenco delle fonti RSS attive: term meta delle redazioni (tipo 'rss')
         * + fonti RSS globali (setting 'global_rss_sources' via Nexus_Rss).
         * Dedup per URL (il match articoli avviene poi per host: due feed dello
         * stesso host condividono lo stesso segnale).
         *
         * @return array Array di array( 'url' => string ).
         */
        private static function rss_sources() {
                $out   = array();
                $seen  = array();

                // 1) Fonti delle redazioni (term meta _nexus_sources).
                if ( class_exists( 'Nexus_Redazioni' ) && method_exists( 'Nexus_Redazioni', 'all_terms' )
                        && method_exists( 'Nexus_Redazioni', 'sources_for_term' ) ) {
                        foreach ( (array) Nexus_Redazioni::all_terms() as $term ) {
                                $sources = Nexus_Redazioni::sources_for_term( (int) $term->term_id );
                                foreach ( (array) $sources as $source ) {
                                        if ( ! is_array( $source ) || empty( $source['url'] ) ) {
                                                continue;
                                        }
                                        if ( 'rss' !== sanitize_key( (string) ( isset( $source['type'] ) ? $source['type'] : '' ) ) ) {
                                                continue; // Il check feed guarda SOLO le fonti RSS (contratto).
                                        }
                                        $key = strtolower( (string) $source['url'] );
                                        if ( isset( $seen[ $key ] ) ) {
                                                continue;
                                        }
                                        $seen[ $key ] = true;
                                        $out[]        = array( 'url' => (string) $source['url'] );
                                }
                        }
                }

                // 2) Fonti RSS globali (v1.1.11 R1).
                if ( class_exists( 'Nexus_Rss' ) && method_exists( 'Nexus_Rss', 'global_sources' ) ) {
                        foreach ( (array) Nexus_Rss::global_sources() as $url ) {
                                $key = strtolower( (string) $url );
                                if ( '' === $key || isset( $seen[ $key ] ) ) {
                                        continue;
                                }
                                $seen[ $key ] = true;
                                $out[]        = array( 'url' => (string) $url );
                        }
                }

                return $out;
        }

        /**
         * Host degli articoli creati negli ultimi N giorni: UNA query con
         * finestra su created_at, risultato limitato (FEED_ROWS_CAP), host
         * calcolato in PHP da source_url (l'item RSS vive sull'host del feed).
         *
         * @param int $days Finestra in giorni.
         * @return array Mappa host => timestamp locale dell'ultimo articolo.
         */
        private static function article_hosts_in_window( $days ) {
                global $wpdb;

                $map = array();

                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'tables' ) ) {
                        return $map;
                }

                $tables = Nexus_DB::tables();
                if ( ! is_array( $tables ) || empty( $tables['articles'] ) ) {
                        return $map;
                }

                $window = current_time( 'timestamp' ) - max( 1, (int) $days ) * DAY_IN_SECONDS;
                $since  = gmdate( 'Y-m-d H:i:s', $window );

                // phpcs:ignore WordPress.DB -- query mirata con finestra; result set limitato dal cap.
                $rows = $wpdb->get_results(
                        $wpdb->prepare(
                                "SELECT source_url, created_at FROM {$tables['articles']}
                                 WHERE created_at >= %s AND source_url <> ''
                                 ORDER BY id DESC LIMIT %d",
                                $since,
                                self::FEED_ROWS_CAP
                        )
                );

                foreach ( (array) $rows as $row ) {
                        $host = self::url_host( isset( $row->source_url ) ? (string) $row->source_url : '' );
                        if ( '' === $host ) {
                                continue;
                        }
                        $ts = isset( $row->created_at ) ? strtotime( (string) $row->created_at ) : 0;
                        if ( $ts <= 0 ) {
                                continue;
                        }
                        if ( ! isset( $map[ $host ] ) || $ts > $map[ $host ] ) {
                                $map[ $host ] = $ts;
                        }
                }

                return $map;
        }

        /**
         * La pipeline ha MAI eseguito un agente? (MAX(last_run) su nexus_agents:
         * tabella piccola, nessun indice necessario).
         *
         * @return bool True se almeno un agente ha un last_run registrato.
         */
        private static function pipeline_ever_ran() {
                global $wpdb;

                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'tables' ) ) {
                        return true; // Senza DB non si può sapere: NON bloccare il check in modalità "vergine".
                }

                $tables = Nexus_DB::tables();
                if ( ! is_array( $tables ) || empty( $tables['agents'] ) ) {
                        return true;
                }

                // phpcs:ignore WordPress.DB -- punto su tabella piccola e limitata al plugin.
                $last = $wpdb->get_var( "SELECT MAX(last_run) FROM {$tables['agents']} WHERE last_run IS NOT NULL AND last_run <> ''" );

                return ! empty( $last );
        }

        /* ============ CHECK 2 — AGENTI BLOCCATI ============ */

        /**
         * Lavori 'running' bloccati da più di X ore nella tabella nexus_jobs.
         * Stati: 'ok' (nessun blocco), 'err' (almeno un lavoro bloccato).
         *
         * @return array
         */
        private static function check_jobs() {
                global $wpdb;

                $hours = self::stuck_job_hours();

                $result = array(
                        'status'         => 'ok',
                        'hours'          => $hours,
                        'count'          => 0,
                        'oldest_minutes' => 0,
                        'hooks'          => array(),
                );

                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'tables' ) ) {
                        return $result;
                }

                $tables = Nexus_DB::tables();
                if ( ! is_array( $tables ) || empty( $tables['jobs'] ) ) {
                        return $result;
                }

                // Stesso schema temporale di Nexus_Queue::requeue_stale: now locale
                // e cutoff per differenza, così i confronti DATETIME restano coerenti.
                $now    = current_time( 'mysql' );
                $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( $now ) - $hours * HOUR_IN_SECONDS );

                // phpcs:ignore WordPress.DB -- COUNT + MIN sul sottoinsieme 'running' (indice status_sched).
                $row = $wpdb->get_row(
                        $wpdb->prepare(
                                "SELECT COUNT(*) AS n, MIN(started_at) AS oldest FROM {$tables['jobs']}
                                 WHERE status = 'running' AND started_at IS NOT NULL AND started_at <> '' AND started_at < %s",
                                $cutoff
                        )
                );

                $count = ( $row && isset( $row->n ) ) ? (int) $row->n : 0;
                if ( $count <= 0 ) {
                        return $result;
                }

                $result['count'] = $count;

                $oldest = ( $row && isset( $row->oldest ) ) ? strtotime( (string) $row->oldest ) : 0;
                if ( $oldest > 0 ) {
                        $result['oldest_minutes'] = max( 0, (int) round( ( strtotime( $now ) - $oldest ) / 60 ) );
                }

                // phpcs:ignore WordPress.DB -- campione dei hook bloccati per il dettaglio (max 5).
                $hooks = $wpdb->get_col(
                        $wpdb->prepare(
                                "SELECT DISTINCT hook FROM {$tables['jobs']}
                                 WHERE status = 'running' AND started_at IS NOT NULL AND started_at <> '' AND started_at < %s
                                 ORDER BY hook ASC LIMIT 5",
                                $cutoff
                        )
                );

                foreach ( (array) $hooks as $hook ) {
                        $hook = sanitize_key( (string) $hook );
                        if ( '' !== $hook ) {
                                $result['hooks'][] = $hook;
                        }
                }

                $result['status'] = 'err';

                return $result;
        }

        /* ============ CHECK 3 — BUDGET AI ============ */

        /**
         * Uso AI del mese corrente vs budget mensile configurato.
         * Stati: 'ok' (< 80% o nessun limite), 'warn' (>= 80%), 'err' (>= 100%).
         * Riusa Nexus_DB::usage_stats('month') (query su indice created_at).
         *
         * @return array
         */
        private static function check_budget() {
                $result = array(
                        'status' => 'ok',
                        'pct'    => null,
                        'cost'   => 0.0,
                        'budget' => 0.0,
                );

                $settings = array();
                if ( class_exists( 'Nexus_Dedup' ) && method_exists( 'Nexus_Dedup', 'settings_guarded' ) ) {
                        $settings = Nexus_Dedup::settings_guarded();
                }

                $budget = ( is_array( $settings ) && isset( $settings['monthly_budget_usd'] ) )
                        ? (float) $settings['monthly_budget_usd']
                        : 0.0;

                if ( $budget <= 0 ) {
                        $result['no_limit'] = true; // Nessun limite configurato: check neutro, onesto.
                        return $result;
                }

                $result['budget'] = $budget;

                if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'usage_stats' ) ) {
                        $stats = Nexus_DB::usage_stats( 'month' );
                        if ( is_array( $stats ) && isset( $stats['totals']['cost'] ) ) {
                                $result['cost'] = (float) $stats['totals']['cost'];
                        }
                }

                $pct             = ( $budget > 0 ) ? ( $result['cost'] / $budget ) * 100 : 0.0;
                $result['pct']   = (float) round( $pct, 1 );

                $warn_pct = (float) apply_filters( 'nexus_health_budget_warn_pct', 80 );

                if ( $result['pct'] >= 100 ) {
                        $result['status'] = 'err';
                } elseif ( $result['pct'] >= max( 1.0, min( 99.0, $warn_pct ) ) ) {
                        $result['status'] = 'warn';
                }

                return $result;
        }

        /* ============ CHECK 4 — CRON FERMA ============ */

        /**
         * Hook WP-Cron principali del plugin, verificati SOLO se abilitati dalle
         * impostazioni (hook volutamente spento = NON è un problema). Stati:
         * 'ok' (tutti gli hook abilitati sono schedulati e in orario), 'err'
         * (almeno un hook mai schedulato o in ritardo > 1 ora).
         *
         * @return array
         */
        private static function check_cron() {
                $result = array(
                        'status'   => 'ok',
                        'problems' => array(),
                        'enabled'  => 0,
                );

                foreach ( self::cron_watchlist() as $entry ) {
                        if ( ! $entry['enabled'] ) {
                                continue; // Funzionalità spenta dall'utente: nessun allarme.
                        }

                        $result['enabled']++;

                        $next = wp_next_scheduled( $entry['hook'] );

                        if ( false === $next ) {
                                $result['problems'][] = array(
                                        'hook'     => $entry['hook'],
                                        'missing'  => true,
                                        'lag_min'  => 0,
                                );
                                continue;
                        }

                        $next = (int) $next;
                        if ( $next < time() - self::CRON_LAG ) {
                                $result['problems'][] = array(
                                        'hook'     => $entry['hook'],
                                        'missing'  => false,
                                        'lag_min'  => max( 1, (int) round( ( time() - $next ) / 60 ) ),
                                );
                        }
                }

                if ( ! empty( $result['problems'] ) ) {
                        $result['status'] = 'err';
                }

                return $result;
        }

        /**
         * Watchlist degli hook WP-Cron principali con la condizione di
         * abilitazione letta dalle impostazioni (replica fedele delle guardie
         * delle rispettive classi, che restano intoccate).
         *
         * @return array Array di array( 'hook' => string, 'enabled' => bool ).
         */
        private static function cron_watchlist() {
                $settings = array();
                if ( class_exists( 'Nexus_Dedup' ) && method_exists( 'Nexus_Dedup', 'settings_guarded' ) ) {
                        $settings = Nexus_Dedup::settings_guarded();
                }

                $auto_collect = false;
                if ( isset( $settings['auto_collect'] ) ) {
                        $auto_collect = self::normalize_bool( $settings['auto_collect'] );
                }

                return array(
                        array(
                                'hook'    => 'nexus_cron_tick',
                                'enabled' => $auto_collect,
                        ),
                        array(
                                'hook'    => 'nexus_queue_runner',
                                'enabled' => ! empty( $settings['enable_queue'] ),
                        ),
                        array(
                                'hook'    => 'nexus_metrics_sync',
                                'enabled' => isset( $settings['metrics_sync_enabled'] ) && 1 === absint( $settings['metrics_sync_enabled'] ),
                        ),
                        array(
                                'hook'    => 'nexus_weekly_digest',
                                'enabled' => isset( $settings['enable_newsletter'] ) && 1 === absint( $settings['enable_newsletter'] ),
                        ),
                        array(
                                'hook'    => 'nexus_evergreen_refresh',
                                'enabled' => isset( $settings['evergreen_enabled'] ) && 1 === absint( $settings['evergreen_enabled'] ),
                        ),
                );
        }

        /* ============ CHECK 5 — TOKEN GOOGLE ============ */

        /**
         * Token Google: is_connected() true ma get_access_token() fallisce =
         * collegamento rotto (refresh non riuscito). Il risultato è in cache
         * nell'opzione stato: l'eventuale chiamata di rete di refresh avviene
         * SOLO in questo giro orario, mai a view-time.
         *
         * Stati: 'ok' con flag 'not_connected' (nessun collegamento: niente da
         * verificare), 'ok' con flag 'valid', 'err' con flag 'refresh_failed'.
         *
         * @return array
         */
        private static function check_token() {
                $result = array(
                        'status' => 'ok',
                        'flag'   => 'not_connected',
                );

                if ( ! class_exists( 'Nexus_Google_Auth' )
                        || ! method_exists( 'Nexus_Google_Auth', 'is_connected' )
                        || ! method_exists( 'Nexus_Google_Auth', 'get_access_token' ) ) {
                        return $result;
                }

                if ( ! Nexus_Google_Auth::is_connected() ) {
                        return $result; // Non collegato: stato neutro, nessun allarme.
                }

                $result['flag'] = 'valid';

                $token = Nexus_Google_Auth::get_access_token();

                if ( is_wp_error( $token ) ) {
                        $result['status'] = 'err';
                        $result['flag']   = 'refresh_failed';
                }

                return $result;
        }

        /* ============ NOTIFICHE (ANTI-SPAM) ============ */

        /**
         * Confronta i nuovi check con lo stato precedente e decide quali check
         * vanno notificati: SOLO i check che entrano in warn/err (transizione o
         * prima osservazione) oppure che persistono da >= 24h (re-notifica).
         *
         * La struttura ritornata contiene per ogni check {status, at, pending}:
         * 'pending' = da notificare in questo giro (consumato dal chiamante).
         *
         * @param array $prev_notify Memoria notifiche precedente.
         * @param array $prev_checks Checks del giro precedente.
         * @param array $checks     Checks appena eseguiti.
         * @return array
         */
        private static function merge_notify( $prev_notify, $prev_checks, $checks ) {
                $notify = array();

                foreach ( $checks as $key => $check ) {
                        $status = isset( $check['status'] ) ? (string) $check['status'] : 'ok';

                        $prev_status = '';
                        if ( isset( $prev_notify[ $key ]['status'] ) ) {
                                $prev_status = (string) $prev_notify[ $key ]['status'];
                        } elseif ( isset( $prev_checks[ $key ]['status'] ) ) {
                                // Prima memoria assente ma c'è un giro precedente: parte da 'ok'.
                                $prev_status = (string) $prev_checks[ $key ]['status'];
                        }

                        $prev_at = isset( $prev_notify[ $key ]['at'] ) ? (int) $prev_notify[ $key ]['at'] : 0;

                        $pending = false;

                        if ( 'warn' === $status || 'err' === $status ) {
                                $changed = ( $status !== $prev_status );
                                $repeat  = ( $prev_at > 0 && ( time() - $prev_at ) >= self::NOTIFY_REPEAT );

                                if ( $changed || $repeat ) {
                                        $pending = true;
                                }
                        }

                        $notify[ $key ] = array(
                                'status'  => $status,
                                'at'      => ( 'warn' === $status || 'err' === $status ) ? ( $prev_at > 0 && ! $pending ? $prev_at : time() ) : 0,
                                'pending' => $pending,
                        );
                }

                return $notify;
        }

        /**
         * Costruisce e invia UN messaggio unico (italiano plain, stessa disciplina
         * del canale notify) per i check appena entrati in warn/err.
         *
         * @param array $pending_map Mappa key => entry con 'pending' = true.
         * @param array $checks      Checks appena eseguiti.
         * @return void
         */
        private static function notify_new( $pending_map, $checks ) {
                $parts = array();

                foreach ( array( 'feeds', 'jobs', 'budget', 'cron', 'token' ) as $key ) {
                        if ( empty( $pending_map[ $key ] ) ) {
                                continue;
                        }
                        $part = self::notify_part( $key, isset( $checks[ $key ] ) ? $checks[ $key ] : array() );
                        if ( '' !== $part ) {
                                $parts[] = $part;
                        }
                }

                if ( empty( $parts ) ) {
                        return;
                }

                if ( ! class_exists( 'Nexus_Notify' ) || ! method_exists( 'Nexus_Notify', 'send_generic' ) ) {
                        return;
                }

                try {
                        Nexus_Notify::send_generic( 'health', implode( '; ', $parts ) );
                } catch ( \Throwable $e ) {
                        // Le notifiche non devono MAI rompere il giro dei checks.
                        return;
                }
        }

        /**
         * Frase (italiano plain, per il canale notifiche) di un singolo check in
         * warn/err. Dati SOLO strutturati: mai URL completi, mai segreti.
         *
         * @param string $key   Chiave del check.
         * @param array  $check Risultato del check.
         * @return string '' se niente da raccontare.
         */
        private static function notify_part( $key, $check ) {
                if ( ! is_array( $check ) ) {
                        return '';
                }

                if ( 'feeds' === $key ) {
                        $dead   = isset( $check['dead'] ) && is_array( $check['dead'] ) ? $check['dead'] : array();
                        $errors = isset( $check['errors'] ) && is_array( $check['errors'] ) ? $check['errors'] : array();
                        $all    = array_merge( $dead, $errors );
                        if ( empty( $all ) ) {
                                return '';
                        }
                        $days = isset( $check['days'] ) ? (int) $check['days'] : self::DEAD_FEED_DAYS;

                        return sprintf( 'Feed RSS senza nuovi articoli da %d+ giorni: %s', $days, implode( ', ', array_slice( array_map( 'strval', $all ), 0, 5 ) ) );
                }

                if ( 'jobs' === $key ) {
                        $count = isset( $check['count'] ) ? (int) $check['count'] : 0;
                        if ( $count <= 0 ) {
                                return '';
                        }
                        $hours = isset( $check['hours'] ) ? (int) $check['hours'] : self::STUCK_JOB_HOURS;
                        $hooks = isset( $check['hooks'] ) && is_array( $check['hooks'] ) ? implode( ', ', array_slice( $check['hooks'], 0, 3 ) ) : '';

                        return sprintf( 'Lavori running bloccati da oltre %1$d ore: %2$d%3$s', $hours, $count, '' !== $hooks ? ' (' . $hooks . ')' : '' );
                }

                if ( 'budget' === $key ) {
                        $pct = isset( $check['pct'] ) && null !== $check['pct'] ? (int) round( (float) $check['pct'] ) : 0;
                        if ( $pct < 80 ) {
                                return '';
                        }
                        if ( $pct >= 100 ) {
                                return sprintf( 'Budget AI mensile esaurito (%d%%)', $pct );
                        }

                        return sprintf( 'Uso AI al %d%% del budget mensile', $pct );
                }

                if ( 'cron' === $key ) {
                        $problems = isset( $check['problems'] ) && is_array( $check['problems'] ) ? $check['problems'] : array();
                        if ( empty( $problems ) ) {
                                return '';
                        }
                        $bits = array();
                        foreach ( array_slice( $problems, 0, 3 ) as $problem ) {
                                $hook = isset( $problem['hook'] ) ? (string) $problem['hook'] : '';
                                if ( '' === $hook ) {
                                        continue;
                                }
                                if ( ! empty( $problem['missing'] ) ) {
                                        $bits[] = sprintf( '%s mai schedulato', $hook );
                                        continue;
                                }
                                $lag = isset( $problem['lag_min'] ) ? (int) $problem['lag_min'] : 0;
                                $bits[] = sprintf( '%s in ritardo di %d min', $hook, $lag );
                        }

                        return empty( $bits ) ? '' : 'WP-Cron ferma: ' . implode( ', ', $bits );
                }

                if ( 'token' === $key ) {
                        if ( isset( $check['flag'] ) && 'refresh_failed' === $check['flag'] ) {
                                return 'Token Google non rinnovabile: riavvia il collegamento dalle Impostazioni.';
                        }
                        return '';
                }

                return '';
        }

        /* ============ RENDER CARD DASHBOARD ============ */

        /**
         * Card "Health check redazione" per la dashboard (server-side, zero
         * query pesanti: legge SOLO l'opzione stato). Tutto escapato, stringhe
         * via __()/esc_html__. Nessun id consumato da admin.js.
         *
         * @return void
         */
        public static function render_card() {
                $state = self::state();

                $never_ran = empty( $state['last_run'] );
                $checks    = ( ! $never_ran && isset( $state['checks'] ) && is_array( $state['checks'] ) ) ? $state['checks'] : array();

                $overall = self::overall_status( $checks, $never_ran );
                ?>
                <div class="nxa-section nxa-section-health">
                        <h2><?php echo esc_html__( 'Health check redazione', 'nexus-news-ai' ); ?></h2>
                        <div class="nxa-health-card" data-nxa-health="1">
                                <div class="nxa-health-head">
                                        <p class="nxa-health-updated">
                                                <span class="nxa-health-dot nxa-health-dot--<?php echo esc_attr( $overall ); ?>" aria-hidden="true"></span>
                                                <?php
                                                if ( $never_ran ) {
                                                        echo esc_html__( 'Primo controllo entro circa 5 minuti dall\'attivazione.', 'nexus-news-ai' );
                                                } else {
                                                        // last_run è salvato con time() (epoch UTC): il confronto
                                                        // resta nello stesso dominio, nessuno skew da fuso.
                                                        $ago = human_time_diff( (int) $state['last_run'], time() );
                                                        echo esc_html( sprintf(
                                                                /* translators: %s: tempo trascorso dall'ultimo giro di controlli. */
                                                                __( 'Ultimo controllo: %s fa.', 'nexus-news-ai' ),
                                                                $ago
                                                        ) );
                                                }
                                                ?>
                                        </p>
                                        <?php if ( ! $never_ran && isset( $_GET['nxa_health_run'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo conferma visiva di un'azione già verificata via nonce. ?>
                                                <span class="nxa-health-justnow"><?php echo esc_html__( 'Controlli eseguiti adesso.', 'nexus-news-ai' ); ?></span>
                                        <?php endif; ?>
                                        <a class="nxa-btn is-sm nxa-health-rerun" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=nexus_health_run' ), 'nexus_health_run' ) ); ?>"><?php echo esc_html__( 'Ricontrolla ora', 'nexus-news-ai' ); ?></a>
                                </div>

                                <ul class="nxa-health-list">
                                        <?php
                                        self::render_row( 'feeds', __( 'Feed RSS', 'nexus-news-ai' ), isset( $checks['feeds'] ) ? $checks['feeds'] : array(), $never_ran, __( 'Fonti', 'nexus-news-ai' ), admin_url( 'admin.php?page=nexus-redazioni' ) );
                                        self::render_row( 'jobs', __( 'Agenti', 'nexus-news-ai' ), isset( $checks['jobs'] ) ? $checks['jobs'] : array(), $never_ran, __( 'Coda lavori', 'nexus-news-ai' ), admin_url( 'admin.php?page=nexus-jobs' ) );
                                        self::render_row( 'budget', __( 'Budget AI', 'nexus-news-ai' ), isset( $checks['budget'] ) ? $checks['budget'] : array(), $never_ran, __( 'Impostazioni', 'nexus-news-ai' ), admin_url( 'admin.php?page=nexus-settings' ) );
                                        self::render_row( 'cron', __( 'Cron', 'nexus-news-ai' ), isset( $checks['cron'] ) ? $checks['cron'] : array(), $never_ran, __( 'Impostazioni', 'nexus-news-ai' ), admin_url( 'admin.php?page=nexus-settings' ) );
                                        self::render_row( 'token', __( 'Token Google', 'nexus-news-ai' ), isset( $checks['token'] ) ? $checks['token'] : array(), $never_ran, __( 'Sistema Google', 'nexus-news-ai' ), admin_url( 'admin.php?page=nexus-settings&tab=sistema' ) );
                                        ?>
                                </ul>

                                <?php if ( $never_ran ) : ?>
                                        <p class="nxa-health-empty"><?php echo esc_html__( 'I risultati compaiono dopo il primo giro del cron orario: nel frattempo puoi avviare i controlli subito col bottone qui sopra.', 'nexus-news-ai' ); ?></p>
                                <?php endif; ?>
                        </div>
                </div>
                <?php
        }

        /**
         * Riga singola della card: pallino stato + nome check + dettaglio breve
         * + link alla pagina di risoluzione.
         *
         * @param string $key       Chiave check.
         * @param string $name      Nome mostrato (già localizzato).
         * @param array  $check     Risultato del check (o array vuoto se mai girato).
         * @param bool   $never_ran True se il cron non ha mai girato.
         * @param string $link_text Testo del link di risoluzione.
         * @param string $link_url  URL della pagina di risoluzione.
         * @return void
         */
        private static function render_row( $key, $name, $check, $never_ran, $link_text, $link_url ) {
                $status = ( ! $never_ran && is_array( $check ) && isset( $check['status'] ) )
                        ? (string) $check['status']
                        : 'idle';

                $detail = $never_ran
                        ? __( 'In attesa del primo controllo.', 'nexus-news-ai' )
                        : self::row_detail( $key, is_array( $check ) ? $check : array() );
                ?>
                <li class="nxa-health-item nxa-health-item--<?php echo esc_attr( $status ); ?>">
                        <span class="nxa-health-dot nxa-health-dot--<?php echo esc_attr( $status ); ?>" aria-hidden="true"></span>
                        <span class="nxa-health-name"><?php echo esc_html( $name ); ?></span>
                        <span class="nxa-health-detail"><?php echo esc_html( $detail ); ?></span>
                        <a class="nxa-health-link" href="<?php echo esc_url( $link_url ); ?>"><?php echo esc_html( $link_text ); ?></a>
                </li>
                <?php
        }

        /**
         * Dettaglio breve e ONESTO per un check (testo localizzato a view-time
         * dai dati strutturati dell'opzione: il testo segue la lingua corrente).
         *
         * @param string $key   Chiave del check.
         * @param array  $check Risultato del check.
         * @return string
         */
        private static function row_detail( $key, $check ) {
                if ( isset( $check['broken'] ) && $check['broken'] ) {
                        return __( 'Controllo non riuscito: riprova con "Ricontrolla ora".', 'nexus-news-ai' );
                }

                if ( 'feeds' === $key ) {
                        $sources = isset( $check['sources'] ) ? (int) $check['sources'] : 0;
                        if ( isset( $check['virgin'] ) && $check['virgin'] ) {
                                return __( 'Nessuna raccolta ancora eseguita: i controlli fonti partono dopo il primo giro della pipeline.', 'nexus-news-ai' );
                        }
                        if ( 0 === $sources ) {
                                return __( 'Nessuna fonte RSS configurata.', 'nexus-news-ai' );
                        }
                        $dead   = isset( $check['dead'] ) && is_array( $check['dead'] ) ? $check['dead'] : array();
                        $errors = isset( $check['errors'] ) && is_array( $check['errors'] ) ? $check['errors'] : array();
                        $all    = array_merge( $dead, $errors );
                        if ( empty( $all ) ) {
                                return sprintf(
                                        /* translators: 1: numero fonti monitorate, 2: finestra in giorni. */
                                        __( '%1$d fonti RSS monitorate: nessuna ferma da oltre %2$d giorni.', 'nexus-news-ai' ),
                                        $sources,
                                        isset( $check['days'] ) ? (int) $check['days'] : self::DEAD_FEED_DAYS
                                );
                        }
                        return self::problem_list(
                                $all,
                                sprintf(
                                        /* translators: 1: numero fonti sospette, 2: finestra in giorni. */
                                        __( '%1$d fonti RSS senza nuovi articoli da %2$d+ giorni:', 'nexus-news-ai' ),
                                        count( $all ),
                                        isset( $check['days'] ) ? (int) $check['days'] : self::DEAD_FEED_DAYS
                                )
                        );
                }

                if ( 'jobs' === $key ) {
                        $count = isset( $check['count'] ) ? (int) $check['count'] : 0;
                        if ( 0 === $count ) {
                                return __( 'Nessun lavoro bloccato in coda.', 'nexus-news-ai' );
                        }
                        $hours = isset( $check['hours'] ) ? (int) $check['hours'] : self::STUCK_JOB_HOURS;

                        return sprintf(
                                /* translators: 1: numero lavori, 2: soglia in ore. */
                                _n( '%1$d lavoro "running" da oltre %2$d ore.', '%1$d lavori "running" da oltre %2$d ore.', $count, 'nexus-news-ai' ),
                                $count,
                                $hours
                        );
                }

                if ( 'budget' === $key ) {
                        if ( isset( $check['no_limit'] ) && $check['no_limit'] ) {
                                return __( 'Nessun budget mensile configurato: controllo non applicabile.', 'nexus-news-ai' );
                        }
                        $pct = isset( $check['pct'] ) && null !== $check['pct'] ? (float) $check['pct'] : 0.0;
                        if ( $pct >= 100 ) {
                                return sprintf(
                                        /* translators: %s: percentuale di budget consumata. */
                                        __( 'Budget mensile esaurito (%s%%).', 'nexus-news-ai' ),
                                        number_format_i18n( round( $pct ) )
                                );
                        }

                        return sprintf(
                                /* translators: %s: percentuale di budget consumata. */
                                __( 'Uso AI del mese: %s%% del budget.', 'nexus-news-ai' ),
                                number_format_i18n( round( $pct ) )
                        );
                }

                if ( 'cron' === $key ) {
                        $problems = isset( $check['problems'] ) && is_array( $check['problems'] ) ? $check['problems'] : array();
                        $enabled  = isset( $check['enabled'] ) ? (int) $check['enabled'] : 0;
                        if ( 0 === $enabled && empty( $problems ) ) {
                                return __( 'Nessun evento WP-Cron attivo nelle impostazioni: controllo non applicabile.', 'nexus-news-ai' );
                        }
                        if ( empty( $problems ) ) {
                                return sprintf(
                                        /* translators: %s: numero di eventi WP-Cron verificati. */
                                        _n( '%s evento WP-Cron verificato: tutto in orario.', '%s eventi WP-Cron verificati: tutto in orario.', $enabled, 'nexus-news-ai' ),
                                        number_format_i18n( $enabled )
                                );
                        }
                        $labels = array();
                        foreach ( $problems as $problem ) {
                                if ( ! is_array( $problem ) || empty( $problem['hook'] ) ) {
                                        continue;
                                }
                                $hook = (string) $problem['hook'];
                                if ( ! empty( $problem['missing'] ) ) {
                                        $labels[] = $hook . ' ' . __( 'mai schedulato', 'nexus-news-ai' );
                                        continue;
                                }
                                $lag      = isset( $problem['lag_min'] ) ? (int) $problem['lag_min'] : 0;
                                $labels[] = $hook . ' ' . sprintf(
                                        /* translators: %s: minuti di ritardo dell'evento. */
                                        __( 'in ritardo di %s', 'nexus-news-ai' ),
                                        number_format_i18n( $lag )
                                );
                        }

                        return self::problem_list( $labels, __( 'WP-Cron ferma:', 'nexus-news-ai' ) );
                }

                if ( 'token' === $key ) {
                        $flag = isset( $check['flag'] ) ? (string) $check['flag'] : '';
                        if ( 'not_connected' === $flag ) {
                                return __( 'Google non collegato: controllo non applicabile.', 'nexus-news-ai' );
                        }
                        if ( 'refresh_failed' === $flag ) {
                                return __( 'Token non rinnovabile: riavvia il collegamento Google.', 'nexus-news-ai' );
                        }

                        return __( 'Token Google valido.', 'nexus-news-ai' );
                }

                return __( 'Stato non disponibile.', 'nexus-news-ai' );
        }

        /**
         * Lista problemi compatta per la card: primi 3 + conteggio residuo.
         *
         * @param array  $items  Label dei problemi.
         * @param string $prefix Prefisso della frase.
         * @return string
         */
        private static function problem_list( $items, $prefix ) {
                $items = array_values( array_filter( array_map( 'strval', (array) $items ), 'strlen' ) );

                if ( empty( $items ) ) {
                        return $prefix;
                }

                $shown = array_slice( $items, 0, 3 );
                $rest  = count( $items ) - count( $shown );

                $text = $prefix . ' ' . implode( ', ', $shown );

                if ( $rest > 0 ) {
                        /* translators: %s: numero di ulteriori problemi non elencati. */
                        $text .= ' ' . sprintf( __( 'e altre %s.', 'nexus-news-ai' ), number_format_i18n( $rest ) );
                }

                return $text;
        }

        /**
         * Stato peggiore tra i checks (per il pallino di testa della card).
         *
         * @param array $checks    Checks correnti.
         * @param bool  $never_ran True se mai girato.
         * @return string ok|warn|err|idle
         */
        private static function overall_status( $checks, $never_ran ) {
                if ( $never_ran ) {
                        return 'idle';
                }

                $rank = array( 'ok' => 0, 'warn' => 1, 'err' => 2 );
                $worst = 'ok';

                foreach ( (array) $checks as $check ) {
                        $status = is_array( $check ) && isset( $check['status'] ) ? (string) $check['status'] : 'ok';
                        if ( isset( $rank[ $status ] ) && $rank[ $status ] > $rank[ $worst ] ) {
                                $worst = $status;
                        }
                }

                return $worst;
        }

        /* ============ HELPER ============ */

        /**
         * Soglia giorni per il check fonti: costante + filtro dev, clamp 1-365.
         *
         * @return int
         */
        private static function dead_feed_days() {
                $days = (int) apply_filters( 'nexus_health_dead_feed_days', self::DEAD_FEED_DAYS );

                return max( 1, min( 365, $days ) );
        }

        /**
         * Soglia ore per il check lavori bloccati: costante + filtro dev, clamp 1-72.
         *
         * @return int
         */
        private static function stuck_job_hours() {
                $hours = (int) apply_filters( 'nexus_health_stuck_job_hours', self::STUCK_JOB_HOURS );

                return max( 1, min( 72, $hours ) );
        }

        /**
         * Host pulito da un URL (lowercase, senza "www."): chiave di match tra
         * fonti RSS e source_url degli articoli.
         *
         * @param string $url URL.
         * @return string Host ('' se non interpretabile).
         */
        private static function url_host( $url ) {
                $host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );

                if ( '' === $host || strlen( $host ) > 253 ) {
                        return '';
                }

                if ( 1 !== preg_match( '/^[a-z0-9.\-]+$/', $host ) ) {
                        return '';
                }

                return preg_replace( '/^www\./', '', $host );
        }

        /**
         * Normalizza un valore settings a boolean (stessa semantica di
         * Nexus_Cron::normalize_bool, replicata perché la classe è fuori perimetro).
         *
         * @param mixed $value Valore grezzo.
         * @return bool
         */
        private static function normalize_bool( $value ) {
                if ( is_bool( $value ) ) {
                        return $value;
                }

                if ( is_numeric( $value ) ) {
                        return (int) $value > 0;
                }

                if ( is_string( $value ) ) {
                        $value = strtolower( trim( $value ) );

                        return ! in_array( $value, array( '', '0', 'false', 'no', 'off' ), true );
                }

                return (bool) $value;
        }
}

// v1.1.14 (P3): self-init del modulo health check (bootstrap non toccato: il
// caricamento del file avviene via require append-only in class-nexus-notify.php).
add_action( 'nexus_modules_init', array( 'Nexus_Health', 'init' ) );

// v1.1.14 (P3): alla disattivazione del plugin il cron orario e il mutex
// vengono rimossi (callback aggiuntiva, il bootstrap resta intatto).
register_deactivation_hook( NEXUS_PLUGIN_FILE, array( 'Nexus_Health', 'deactivate' ) );
