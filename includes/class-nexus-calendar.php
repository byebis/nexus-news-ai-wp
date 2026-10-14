<?php
/**
 * Nexus Calendar — calendario editoriale con slot orari di pubblicazione.
 *
 * SCOPO (v1.1.14, task P4):
 * Pagina admin "Calendario" (sottomenu di Nexus News) con:
 *  - vista mensile degli articoli PROGRAMMATI (post_status 'future', range
 *    di date su post_date sfruttando l'indice core type_status_date);
 *  - pannello onesto "in attesa" con gli articoli della pipeline NON ancora
 *    programmati (stati reali di wp_nexus_articles: 'draft' = raccolta in
 *    corso, 'pending_approval' = attesa revisione umana, 'approved' =
 *    approvato in attesa di pubblicazione) + contatore coda lavori;
 *  - SLOT ORARI: finestre orarie di pubblicazione globali o per agente
 *    (option 'nexus_calendar_slots'), lista aggiungibile/rimovibile con
 *    salvataggio nonce+capability via admin-post;
 *  - CRON 'nexus_calendar_tick' (ogni 30 min): se gli slot sono attivi,
 *    riprogramma SOLO i post con post_status 'future' che cadono FUORI
 *    dagli slot, spostandoli allo slot successivo (wp_update_post su
 *    post_date + post_date_gmt). NON tocca pipeline né coda: gli articoli
 *    nascono programmati dal flusso esistente, il calendario li
 *    riallinea agli slot entro l'intervallo del cron (30 minuti);
 *  - log onesto degli ultimi spostamenti (option 'nexus_calendar_moves');
 *  - suggerimento orari ONESTO: istogramma HOUR(post_date) dei post
 *    pubblicati (ultimi 90 giorni) + join con nexus_metrics SOLO a
 *    granularità GIORNALIERA (metric_date = giorno di pubblicazione):
 *    nessuna metrica oraria inventata.
 *
 * STATI REALI (verificati sul codice alla 1.1.13.1):
 *  - i contenuti nuovi sono POST NATIVI di tipo 'post' (filtro
 *    'nexus_native_post_type', Nexus_Publisher) collegati all'articolo
 *    Nexus via meta '_nexus_article_id'; lo stato 'future' compare quando
 *    il post è programmato nel futuro (core WordPress);
 *  - il CPT 'nexus_article' ospita solo articoli LEGACY (v1.1.3): è
 *    incluso nel calendario per completezza (filtro
 *    'nexus_calendar_post_types' per ampliare/restringere);
 *  - gli articoli in pipeline vivono nella tabella custom
 *    wp_nexus_articles (stati 'draft'|'pending_approval'|'approved'|
 *    'rejected'|'published') e NON hanno una data di pubblicazione
 *    programmata: finché non diventano post non compaiono in un giorno
 *    del calendario (panel "in attesa", mai pallini su giorni inventati).
 *
 * WIRING (nota per l'integratore — NON implementare in questo file):
 *  - in nexus-news-ai.php aggiungere (dopo class-nexus-queue.php):
 *      require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-calendar.php';
 *  - l'init è self-registrato: Nexus_Calendar::init() aggancia SOLO hook
 *    (admin_menu, admin_enqueue_scripts, cron_schedules, 'init' per la
 *    schedulazione, handler admin-post). Nessuna chiamata esplicita
 *    richiesta nel bootstrap oltre al require.
 *  - per la disattivazione (opzionale ma consigliato) in nexus_deactivate():
 *      if ( class_exists( 'Nexus_Calendar' ) && method_exists( 'Nexus_Calendar', 'deactivate' ) ) {
 *          Nexus_Calendar::deactivate();
 *      }
 *  - nessun altra modifica: menu, CSS, cron e option sono autocoordinati
 *    qui. Per il corretto funzionamento serve anche la vista
 *    admin/views/calendar.php e il foglio admin/css/nexus-calendar.css
 *    (stessi file di questo task, vanno distribuiti insieme).
 *
 * SICUREZZA:
 *  - capability 'manage_options' (come le altre pagine nexus) su menu,
 *    pagina e handler admin-post;
 *  - nonce dedicati per ogni azione POST ('nexus_calendar_slots' e
 *    'nexus_calendar_run') verificati con check_admin_referer;
 *  - tutte le query usano $wpdb->prepare; nessuna scrittura su tabelle
 *    pipeline/coda (sole LETTURE su wp_nexus_articles/wp_nexus_jobs);
 *  - output della vista esc_* ovunque (vedi admin/views/calendar.php).
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Calendar {

        /**
         * Slug della pagina admin (sottomenu di nexus-news).
         */
        const MENU_SLUG = 'nexus-calendar';

        /**
         * Priorità dell'hook admin_menu (dopo la registrazione del menu nexus).
         */
        const MENU_PRIORITY = 20;

        /**
         * Capability richiesta (come le altre pagine del plugin).
         */
        const CAPABILITY = 'manage_options';

        /**
         * Hook WP-Cron del riallineamento slot.
         */
        const HOOK_TICK = 'nexus_calendar_tick';

        /**
         * Slug dello schedule custom (30 minuti).
         */
        const SCHEDULE_SLUG = 'nexus_calendar_30m';

        /**
         * Intervallo del cron in secondi (1800 = 30 min): entro questo tempo
         * gli articoli fuori slot vengono riallineati (nota onesta in vista).
         */
        const INTERVAL = 1800;

        /**
         * Ritardo iniziale del primo giro dopo la schedulazione (secondi).
         */
        const FIRST_RUN_DELAY = 300;

        /**
         * Option con la configurazione slot (globali + per agente).
         */
        const OPT_SLOTS = 'nexus_calendar_slots';

        /**
         * Option con il log degli ultimi spostamenti.
         */
        const OPT_MOVES = 'nexus_calendar_moves';

        /**
         * Numero massimo di voci nel log spostamenti.
         */
        const MOVES_LIMIT = 50;

        /**
         * Orizzonte massimo del cron: i post programmati oltre questo limite
         * NON vengono toccati (scelta conservativa, documentata in vista).
         */
        const HORIZON_DAYS = 30;

        /**
         * Massimo post processati per giro del cron (rete di sicurezza).
         */
        const MAX_POSTS_PER_RUN = 200;

        /**
         * Giorni di osservazione dell'istogramma ore di pubblicazione.
         */
        const HIST_DAYS = 90;

        /**
         * Massimo slot per set (globale o per agente).
         */
        const MAX_SLOTS = 12;

        /**
         * Azioni admin-post e nonce associati.
         */
        const ACTION_SLOTS = 'nexus_calendar_save_slots';
        const ACTION_RUN   = 'nexus_calendar_run';
        const NONCE_SLOTS  = 'nexus_calendar_slots';
        const NONCE_RUN    = 'nexus_calendar_run';

        /**
         * Bootstrap modulo: registra SOLO hook, nessun side-effect diretto.
         *
         * @return void
         */
        public static function init() {
                add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), self::MENU_PRIORITY );
                add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
                add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
                add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
                add_action( self::HOOK_TICK, array( __CLASS__, 'handle_tick' ) );
                add_action( 'admin_post_' . self::ACTION_SLOTS, array( __CLASS__, 'handle_save_slots' ) );
                add_action( 'admin_post_' . self::ACTION_RUN, array( __CLASS__, 'handle_run_now' ) );
        }

        /**
         * Disattivazione: rimuove l'evento cron del calendario.
         *
         * v1.1.15 (WF3): il metodo è ora registrato automaticamente via
         * register_deactivation_hook a fondo file (stesso pattern di
         * class-nexus-health.php) — prima nessuno lo registrava e il cron
         * nexus_calendar_tick sopravviveva alla disattivazione del plugin.
         * Le option slot/spostamenti NON vengono cancellate: la
         * configurazione sopravvive a riattivazioni.
         *
         * @return void
         */
        public static function deactivate() {
                wp_clear_scheduled_hook( self::HOOK_TICK );
        }

        /* ==================================================================
         * MENU + ASSET
         * ================================================================ */

        /**
         * Sottomenu "Calendario" sotto Nexus News (priorità 20: arriva dopo
         * le voci registrate da Nexus_Admin a priorità default).
         *
         * @return void
         */
        public static function register_menu() {
                add_submenu_page(
                        'nexus-news',
                        __( 'Calendario editoriale', 'nexus-news-ai' ), // <title> pagina.
                        __( 'Calendario', 'nexus-news-ai' ),            // Voce di menu.
                        self::CAPABILITY,
                        self::MENU_SLUG,
                        array( __CLASS__, 'render_page' )
                );
        }

        /**
         * Enqueue del foglio di stile SOLO sulla pagina calendario.
         *
         * Dipendenza nexus-admin-css quando disponibile (i token var(--nxa-*)
         * vivono lì); il foglio è senza hex/rgba: solo token del design system.
         *
         * @param string $hook Suffix della pagina admin corrente.
         * @return void
         */
        public static function enqueue_assets( $hook ) {
                if ( false === strpos( (string) $hook, self::MENU_SLUG ) ) {
                        return;
                }

                if ( ! file_exists( NEXUS_PLUGIN_DIR . 'admin/css/nexus-calendar.css' ) ) {
                        return;
                }

                $deps = wp_style_is( 'nexus-admin-css', 'registered' ) ? array( 'nexus-admin-css' ) : array();

                wp_enqueue_style(
                        'nexus-calendar-css',
                        NEXUS_PLUGIN_URL . 'admin/css/nexus-calendar.css',
                        $deps,
                        NEXUS_VERSION
                );
        }

        /* ==================================================================
         * CRON
         * ================================================================ */

        /**
         * Schedule custom 'nexus_calendar_30m' (30 minuti).
         *
         * @param array $schedules Schedules WP-Cron esistenti.
         * @return array
         */
        public static function add_schedule( $schedules ) {
                $schedules[ self::SCHEDULE_SLUG ] = array(
                        'interval' => self::INTERVAL,
                        'display'  => 'Nexus: riallineamento slot calendario (30 min)',
                );

                return $schedules;
        }

        /**
         * Schedula l'evento WP-Cron se assente (chiamato su 'init').
         *
         * @return void
         */
        public static function ensure_schedule() {
                if ( ! wp_next_scheduled( self::HOOK_TICK ) ) {
                        wp_schedule_event( time() + self::FIRST_RUN_DELAY, self::SCHEDULE_SLUG, self::HOOK_TICK );
                }
        }

        /**
         * Handler WP-Cron: riallinea gli articoli fuori slot.
         *
         * Gira in try/catch \Throwable: un errore non deve lasciare l'evento
         * cron in stato inconsistente; l'esito (riepilogo onesto) finisce nel
         * log spostamenti leggibile dalla pagina calendario.
         *
         * @return void
         */
        public static function handle_tick() {
                // Rete di sicurezza: se l'evento è stato perso, rischedula.
                if ( ! wp_next_scheduled( self::HOOK_TICK ) ) {
                        wp_schedule_event( time() + self::INTERVAL, self::SCHEDULE_SLUG, self::HOOK_TICK );
                }

                try {
                        self::realign_now();
                } catch ( \Throwable $e ) {
                        // Log di fallback: il dettaglio resta nel log spostamenti
                        // quando l'errore avviene durante il riallineamento.
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                                error_log( '[Nexus Calendar] tick fallito: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                        }
                }
        }

        /* ==================================================================
         * PAGINA
         * ================================================================ */

        /**
         * Render della pagina calendario: raccoglie i dati (solo letture) e
         * include la vista server-side admin/views/calendar.php.
         *
         * @return void
         */
        public static function render_page() {
                if ( ! current_user_can( self::CAPABILITY ) ) {
                        wp_die( 'Permesso negato: serve la capability manage_options.' );
                }

                $tz      = wp_timezone();
                $window  = self::month_window();
                $types   = self::post_types();
                $agents  = self::agents_list();
                $moves   = self::moves_log();

                // Articoli programmati del mese (raggruppati per giorno).
                $month_posts = self::query_month_posts( $window['start'], $window['end'] );
                $agent_map   = self::agent_map_for_posts( $month_posts );
                $items       = array();
                $truncated   = false;

                foreach ( $month_posts as $row ) {
                        $dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $row->post_date, $tz );
                        if ( false === $dt ) {
                                // Data corrotta: non si inventano giorni, la riga è saltata.
                                continue;
                        }
                        $day = $dt->format( 'Y-m-d' );
                        if ( ! isset( $items[ $day ] ) ) {
                                $items[ $day ] = array();
                        }

                        $agent_id = isset( $agent_map[ (int) $row->ID ] ) ? (int) $agent_map[ (int) $row->ID ] : 0;
                        $agent    = ( $agent_id > 0 && isset( $agents[ $agent_id ] ) ) ? $agents[ $agent_id ] : null;

                        if ( count( $items[ $day ] ) < 30 ) {
                                $items[ $day ][] = array(
                                        'id'        => (int) $row->ID,
                                        'title'     => (string) $row->post_title,
                                        'time'      => $dt->format( 'H:i' ),
                                        'agent_id'  => $agent_id,
                                        'agent'     => $agent ? (string) $agent->name : '',
                                        'category'  => $agent ? (string) $agent->category : '',
                                        'is_legacy' => ( 'nexus_article' === (string) $row->post_type ),
                                );
                        } else {
                                // Troncamento onesto per giorno: la griglia resta leggibile.
                                $truncated = true;
                        }
                }

                // Pannello "in attesa": articoli pipeline non ancora post WP.
                $pending = self::pending_summary();

                // Suggerimento orari (istogramma onesto su post pubblicati).
                $hist = self::hour_histogram();

                $next_run = wp_next_scheduled( self::HOOK_TICK );

                // Notifiche post-redirect (whitelist chiavi, numeri assinti).
                $notice_key  = isset( $_GET['nxa_cal_notice'] ) ? sanitize_key( wp_unslash( $_GET['nxa_cal_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sola lettura esito redirect, nessuna azione.
                $notice_key  = in_array( $notice_key, array( 'saved', 'run', 'err' ), true ) ? $notice_key : '';
                $notice_moved   = isset( $_GET['moved'] ) ? absint( $_GET['moved'] ) : 0;     // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $notice_checked = isset( $_GET['checked'] ) ? absint( $_GET['checked'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $notice_bad     = isset( $_GET['bad'] ) ? absint( $_GET['bad'] ) : 0;         // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $notice_dis     = isset( $_GET['dis'] ) ? absint( $_GET['dis'] ) : 0;         // phpcs:ignore WordPress.Security.NonceVerification.Recommended

                include NEXUS_PLUGIN_DIR . 'admin/views/calendar.php';
        }

        /**
         * Finestra del mese visualizzato (da GET cal_month = 'Y-m', default
         * mese corrente nel timezone del sito).
         *
         * @return array{start:DateTimeImmutable,end:DateTimeImmutable,prev:string,next:string,label:string}
         */
        private static function month_window() {
                $tz   = wp_timezone();
                $dt   = null;
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigazione GET read-only.
                $raw  = isset( $_GET['cal_month'] ) ? (string) $_GET['cal_month'] : '';

                if ( preg_match( '/^(\d{4})-(\d{2})$/', $raw, $m ) ) {
                        $y = (int) $m[1];
                        $mo = (int) $m[2];
                        if ( $mo >= 1 && $mo <= 12 ) {
                                $dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', sprintf( '%04d-%02d-01 00:00:00', $y, $mo ), $tz );
                        }
                }

                if ( false === $dt || null === $dt ) {
                        $dt = new DateTimeImmutable( 'now', $tz );
                        $dt = $dt->setDate( (int) $dt->format( 'Y' ), (int) $dt->format( 'n' ), 1 )->setTime( 0, 0, 0 );
                }

                $start = $dt->setTime( 0, 0, 0 );
                $end   = $start->modify( 'first day of next month' )->setTime( 0, 0, 0 )->modify( '-1 second' );

                return array(
                        'start' => $start,
                        'end'   => $end,
                        'prev'  => $start->modify( '-1 month' )->format( 'Y-m' ),
                        'next'  => $start->modify( '+1 month' )->format( 'Y-m' ),
                        // wp_date applica già il timezone del sito al timestamp Unix.
                        'label' => wp_date( 'F Y', $start->getTimestamp() ),
                );
        }

        /**
         * Tipi di post osservati dal calendario: post nativo del publisher +
         * CPT legacy nexus_article. Estendibile con il filtro
         * 'nexus_calendar_post_types' (array di stringhe non vuote).
         *
         * @return array
         */
        public static function post_types() {
                $native = 'post';
                if ( has_filter( 'nexus_native_post_type' ) ) {
                        $candidate = apply_filters( 'nexus_native_post_type', 'post' );
                        if ( is_string( $candidate ) && '' !== $candidate ) {
                                $native = $candidate;
                        }
                }

                $types = array_unique( array_filter( array_map( 'sanitize_key', apply_filters( 'nexus_calendar_post_types', array( $native, 'nexus_article' ) ) ) ) );
                if ( empty( $types ) ) {
                        $types = array( 'post' );
                }

                return array_values( $types );
        }

        /**
         * Post programmati (post_status 'future') nella finestra mese.
         *
         * Query con range su post_date: usa l'indice core type_status_date.
         *
         * @param DateTimeImmutable $start Inizio mese (incluso).
         * @param DateTimeImmutable $end   Fine mese (incluso).
         * @return array
         */
        private static function query_month_posts( $start, $end ) {
                global $wpdb;

                $types = self::post_types();
                $ph    = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

                $sql  = "SELECT ID, post_title, post_date, post_type FROM {$wpdb->posts}"
                        . " WHERE post_status = %s AND post_type IN ( {$ph} )"
                        . ' AND post_date >= %s AND post_date <= %s'
                        . ' ORDER BY post_date ASC LIMIT 400';

                $params   = array_merge( array( 'future' ), $types, array( $start->format( 'Y-m-d 00:00:00' ), $end->format( 'Y-m-d 23:59:59' ) ) );
                $results  = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql e' costruito solo con placeholder.

                return is_array( $results ) ? $results : array();
        }

        /**
         * Mappa post_id → agent_id (via meta _nexus_article_id → riga
         * wp_nexus_articles). SOLE LETTURE, due query con IN.
         *
         * @param array $rows Righe post (oggetti con ->ID).
         * @return array
         */
        private static function agent_map_for_posts( $rows ) {
                global $wpdb;

                $map = array();
                if ( empty( $rows ) || ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'tables' ) ) {
                        return $map;
                }

                $ids = array();
                foreach ( $rows as $row ) {
                        $ids[] = (int) $row->ID;
                }
                $ph = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

                // 1) meta di collegamento post WP → articolo Nexus.
                $meta = $wpdb->get_results(
                        $wpdb->prepare(
                                "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ( {$ph} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder IN costruiti da interi.
                                array_merge( array( '_nexus_article_id' ), $ids )
                        )
                );
                if ( ! is_array( $meta ) || empty( $meta ) ) {
                        return $map;
                }

                $article_ids      = array();
                $article_ids_value = array();
                foreach ( $meta as $m ) {
                        $aid = absint( $m->meta_value );
                        if ( $aid > 0 ) {
                                $article_ids[ (int) $m->post_id ] = $aid;
                                $article_ids_value[] = $aid;
                        }
                }
                $unique_articles = array_values( array_unique( $article_ids_value ) );
                if ( empty( $unique_articles ) ) {
                        return $map;
                }

                // 2) agente proprietario dell'articolo Nexus.
                $tables = Nexus_DB::tables();
                if ( empty( $tables['articles'] ) ) {
                        return $map;
                }
                $ph2 = implode( ', ', array_fill( 0, count( $unique_articles ), '%d' ) );
                $arts = $wpdb->get_results(
                        $wpdb->prepare(
                                "SELECT id, agent_id FROM {$tables['articles']} WHERE id IN ( {$ph2} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome tabella + placeholder interi.
                                $unique_articles
                        )
                );
                if ( ! is_array( $arts ) ) {
                        return $map;
                }
                $agent_of_article = array();
                foreach ( $arts as $a ) {
                        $agent_of_article[ (int) $a->id ] = (int) $a->agent_id;
                }

                foreach ( $article_ids as $post_id => $article_id ) {
                        if ( isset( $agent_of_article[ $article_id ] ) && $agent_of_article[ $article_id ] > 0 ) {
                                $map[ $post_id ] = $agent_of_article[ $article_id ];
                        }
                }

                return $map;
        }

        /**
         * Elenco agenti (id → riga) per pallini colore, legenda e form slot.
         *
         * @return array
         */
        private static function agents_list() {
                $out = array();
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'agents' ) ) {
                        return $out;
                }
                $all = Nexus_DB::agents();
                if ( is_array( $all ) ) {
                        foreach ( $all as $agent ) {
                                $out[ (int) $agent->id ] = $agent;
                        }
                }

                return $out;
        }

        /**
         * Riepilogo onesto degli articoli pipeline NON ancora programmati.
         *
         * Stati reali letti da wp_nexus_articles (sola LETTURA):
         * 'draft' = raccolta/riscrittura in corso, 'pending_approval' =
         * attesa revisione umana, 'approved' = approvato in attesa di
         * pubblicazione. Più il numero di lavori 'pending' in coda
         * (wp_nexus_jobs, sola lettura).
         *
         * @return array
         */
        private static function pending_summary() {
                global $wpdb;

                $out = array(
                        'counts' => array( 'draft' => 0, 'pending_approval' => 0, 'approved' => 0 ),
                        'list'   => array(),
                        'jobs'   => 0,
                        'ok'     => false,
                );

                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'tables' ) ) {
                        return $out;
                }
                $tables = Nexus_DB::tables();
                if ( empty( $tables['articles'] ) ) {
                        return $out;
                }

                $rows = $wpdb->get_results(
                        $wpdb->prepare(
                                "SELECT status, COUNT(*) AS c FROM {$tables['articles']} WHERE status IN ( %s, %s, %s ) GROUP BY status", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome tabella plugin.
                                'draft',
                                'pending_approval',
                                'approved'
                        )
                );
                if ( is_array( $rows ) ) {
                        foreach ( $rows as $r ) {
                                $key = (string) $r->status;
                                if ( isset( $out['counts'][ $key ] ) ) {
                                        $out['counts'][ $key ] = (int) $r->c;
                                }
                        }
                        $out['ok'] = true;
                }

                $list = $wpdb->get_results(
                        $wpdb->prepare(
                                "SELECT id, title, agent_id, created_at FROM {$tables['articles']} WHERE status = %s ORDER BY created_at DESC LIMIT 8", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome tabella plugin.
                                'pending_approval'
                        )
                );
                if ( is_array( $list ) ) {
                        $out['list'] = $list;
                }

                if ( ! empty( $tables['jobs'] ) ) {
                        $jobs = (int) $wpdb->get_var(
                                $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['jobs']} WHERE status = %s", 'pending' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome tabella plugin.
                        );
                        $out['jobs'] = $jobs;
                }

                return $out;
        }

        /**
         * Istogramma ONESTO delle ore di pubblicazione.
         *
         * Conta i post PUBBLICATI (post_status 'publish') degli ultimi
         * HIST_DAYS giorni per HOUR(post_date) — ora LOCALE del sito, è
         * l'ora scritta in post_date. Il join con nexus_metrics è SOLO a
         * granularità giornaliera (metric_date = giorno di pubblicazione):
         * somma di clicks/views del "giorno 0". Nessuna granularità oraria
         * inventata: le metriche non ce l'hanno.
         *
         * @return array
         */
        private static function hour_histogram() {
                global $wpdb;

                $tz  = wp_timezone();
                $now = new DateTimeImmutable( 'now', $tz );

                $out = array(
                        'rows'        => array(),
                        'max'         => 0,
                        'total'       => 0,
                        'top'         => array(),
                        'has_metrics' => false,
                        'clicks'      => 0,
                        'views'       => 0,
                        'ok'          => false,
                );

                $types = self::post_types();
                $ph    = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
                $since = $now->modify( '-' . self::HIST_DAYS . ' days' )->format( 'Y-m-d 00:00:00' );

                $metrics_table = '';
                if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'tables' ) ) {
                        $tables        = Nexus_DB::tables();
                        $metrics_table = empty( $tables['metrics'] ) ? '' : $tables['metrics'];
                }

                if ( '' !== $metrics_table ) {
                        $sql = "SELECT HOUR(p.post_date) AS h, COUNT(DISTINCT p.ID) AS n,"
                                . " COALESCE(SUM(m.clicks), 0) AS clicks, COALESCE(SUM(m.views), 0) AS views"
                                . " FROM {$wpdb->posts} p"
                                . " LEFT JOIN {$metrics_table} m ON m.post_id = p.ID AND m.metric_date = DATE(p.post_date)"
                                . " WHERE p.post_status = %s AND p.post_type IN ( {$ph} ) AND p.post_date >= %s"
                                . ' GROUP BY HOUR(p.post_date) ORDER BY h ASC';
                } else {
                        $sql = "SELECT HOUR(p.post_date) AS h, COUNT(DISTINCT p.ID) AS n, 0 AS clicks, 0 AS views"
                                . " FROM {$wpdb->posts} p"
                                . " WHERE p.post_status = %s AND p.post_type IN ( {$ph} ) AND p.post_date >= %s"
                                . ' GROUP BY HOUR(p.post_date) ORDER BY h ASC';
                }

                $rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( 'publish' ), $types, array( $since ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql costruito solo con placeholder.
                if ( ! is_array( $rows ) ) {
                        return $out;
                }

                $by_hour = array();
                foreach ( $rows as $r ) {
                        $h                  = (int) $r->h;
                        $by_hour[ $h ]      = array(
                                'n'      => (int) $r->n,
                                'clicks' => (int) $r->clicks,
                                'views'  => (int) $r->views,
                        );
                        $out['total']      += (int) $r->n;
                        $out['clicks']     += (int) $r->clicks;
                        $out['views']      += (int) $r->views;
                        if ( (int) $r->clicks > 0 || (int) $r->views > 0 ) {
                                $out['has_metrics'] = true;
                        }
                        if ( (int) $r->n > $out['max'] ) {
                                $out['max'] = (int) $r->n;
                        }
                }

                // 24 righe fisse (0..23): il grafico non inventa ore con zero articoli.
                for ( $h = 0; $h < 24; $h++ ) {
                        $out['rows'][ $h ] = isset( $by_hour[ $h ] )
                                ? $by_hour[ $h ]
                                : array( 'n' => 0, 'clicks' => 0, 'views' => 0 );
                }

                // Fasce top per numero di pubblicazioni (max 3, n > 0).
                $ranked = array();
                foreach ( $out['rows'] as $h => $data ) {
                        if ( $data['n'] > 0 ) {
                                $ranked[ $h ] = $data['n'];
                        }
                }
                arsort( $ranked );
                $out['top'] = array_slice( array_keys( $ranked ), 0, 3, true );

                $out['ok'] = true;

                return $out;
        }

        /* ==================================================================
         * SLOT ORARI
         * ================================================================ */

        /**
         * Configurazione slot normalizzata (option 'nexus_calendar_slots').
         *
         * Shape: array(
         *   'enabled' => 0|1,                       // slot globali attivi
         *   'global'  => array( array('from'=>'09:00','to'=>'11:00'), ... ),
         *   'agents'  => array( agent_id => array( 'enabled' => 0|1, 'slots' => array(...) ) ),
         * )
         *
         * @return array
         */
        public static function slots_config() {
                $raw = get_option( self::OPT_SLOTS, array() );
                $cfg = array(
                        'enabled' => 0,
                        'global'  => array(),
                        'agents'  => array(),
                );

                if ( is_array( $raw ) ) {
                        $cfg['enabled'] = empty( $raw['enabled'] ) ? 0 : 1;

                        if ( isset( $raw['global'] ) && is_array( $raw['global'] ) ) {
                                $cfg['global'] = self::valid_slots( $raw['global'] );
                        }

                        if ( isset( $raw['agents'] ) && is_array( $raw['agents'] ) ) {
                                foreach ( $raw['agents'] as $agent_id => $data ) {
                                        $aid = absint( $agent_id );
                                        if ( $aid <= 0 || ! is_array( $data ) ) {
                                                continue;
                                        }
                                        $cfg['agents'][ $aid ] = array(
                                                'enabled' => empty( $data['enabled'] ) ? 0 : 1,
                                                'slots'   => isset( $data['slots'] ) && is_array( $data['slots'] ) ? self::valid_slots( $data['slots'] ) : array(),
                                        );
                                }
                        }
                }

                return $cfg;
        }

        /**
         * Filtra/valida un set di slot: da 'HH:MM' a 'HH:MM' con from < to
         * (nessuna finestra a cavallo di mezzanotte in v1: validatione
         * esplicita, niente inferenze). Max MAX_SLOTS slot.
         *
         * @param array $rows Set di slot grezzi.
         * @return array
         */
        private static function valid_slots( $rows ) {
                $out = array();
                if ( ! is_array( $rows ) ) {
                        return $out;
                }

                foreach ( $rows as $row ) {
                        if ( ! is_array( $row ) || ! isset( $row['from'], $row['to'] ) ) {
                                continue;
                        }
                        $from = self::normalize_hhmm( $row['from'] );
                        $to   = self::normalize_hhmm( $row['to'] );
                        if ( null === $from || null === $to || strcmp( $from, $to ) >= 0 ) {
                                continue;
                        }
                        $out[] = array(
                                'from' => $from,
                                'to'   => $to,
                        );
                        if ( count( $out ) >= self::MAX_SLOTS ) {
                                break;
                        }
                }

                return $out;
        }

        /**
         * Normalizza una stringa oraria in 'HH:MM' (null se non valida).
         *
         * @param mixed $raw Valore grezzo ('9:05', '09:05', '09.05').
         * @return string|null
         */
        private static function normalize_hhmm( $raw ) {
                $raw = trim( (string) $raw );
                if ( '' === $raw ) {
                        return '';
                }

                if ( ! preg_match( '/^(\d{1,2})[:.](\d{1,2})$/', $raw, $m ) ) {
                        return null;
                }

                $h = (int) $m[1];
                $i = (int) $m[2];
                if ( $h > 23 || $i > 59 ) {
                        return null;
                }

                return sprintf( '%02d:%02d', $h, $i );
        }

        /**
         * Set di slot applicabile a un post: slot PROPRI dell'agente se
         * l'override è attivo, altrimenti slot globali se attivi, altrimenti
         * niente (il post non viene toccato).
         *
         * @param int   $agent_id Agente proprietario (0 = sconosciuto).
         * @param array $cfg      Configurazione slot normalizzata.
         * @return array
         */
        public static function slots_for( $agent_id, $cfg ) {
                $agent_id = absint( $agent_id );

                if ( $agent_id > 0 && isset( $cfg['agents'][ $agent_id ] ) ) {
                        $per_agent = $cfg['agents'][ $agent_id ];
                        if ( ! empty( $per_agent['enabled'] ) ) {
                                return is_array( $per_agent['slots'] ) ? $per_agent['slots'] : array();
                        }
                }

                if ( ! empty( $cfg['enabled'] ) ) {
                        return is_array( $cfg['global'] ) ? $cfg['global'] : array();
                }

                return array();
        }

        /**
         * True se l'ora 'H:i' cade DENTRO una finestra (from <= t < to).
         *
         * @param array  $slots Set di slot validati.
         * @param string $hi    Ora 'H:i'.
         * @return bool
         */
        public static function slot_contains( $slots, $hi ) {
                if ( empty( $slots ) ) {
                        return false;
                }
                foreach ( $slots as $slot ) {
                        if ( strcmp( $slot['from'], $hi ) <= 0 && strcmp( $hi, $slot['to'] ) < 0 ) {
                                return true;
                        }
                }

                return false;
        }

        /**
         * Prossimo inizio slot (datetime) strettamente successivo a $after
         * e comunque non nel passato. Scansiona al massimo 8 giorni: oltre,
         * salta (restituisce null) — caso limite, mai dati inventati.
         *
         * @param array             $slots Set di slot validati.
         * @param DateTimeImmutable $after Datetime di partenza.
         * @param DateTimeImmutable $now   Adesso (per non tornare nel passato).
         * @return DateTimeImmutable|null
         */
        public static function next_slot_start( $slots, DateTimeImmutable $after, DateTimeImmutable $now ) {
                if ( empty( $slots ) ) {
                        return null;
                }

                $ordered = $slots;
                usort( $ordered, array( __CLASS__, 'sort_slots' ) );

                $floor = ( $after < $now ) ? $now : $after;
                $day   = $floor->setTime( 0, 0, 0 );

                for ( $i = 0; $i < 8; $i++ ) {
                        $day_candidate = $day->modify( '+' . $i . ' days' );
                        foreach ( $ordered as $slot ) {
                                $parts = explode( ':', $slot['from'] );
                                $cand  = $day_candidate->setTime( (int) $parts[0], (int) $parts[1], 0 );
                                if ( $cand >= $floor ) {
                                        return $cand;
                                }
                        }
                }

                return null;
        }

        /**
         * Callback usort per slot (per 'from' crescente).
         *
         * @param array $a Slot A.
         * @param array $b Slot B.
         * @return int
         */
        private static function sort_slots( $a, $b ) {
                return strcmp( (string) $a['from'], (string) $b['from'] );
        }

        /* ==================================================================
         * APPLICAZIONE SLOT (cron + azione manuale)
         * ================================================================ */

        /**
         * Riallinea gli articoli programmati fuori slot allo slot successivo.
         *
         * Regole v1 (oneste e conservative):
         *  - SOLO post con post_status 'future' (mai pubblicati, mai bozze);
         *  - solo post con post_date nel futuro e entro HORIZON_DAYS giorni;
         *  - l'agente del post decide il set di slot (override per agente
         *    attivo → slot propri; altrimenti slot globali se attivi);
         *  - se il post è già dentro uno slot NON viene toccato;
         *  - se fuori slot viene spostato ALL'INIZIO dello slot successivo;
         *  - nessuno slot risolvibile → il post NON viene toccato (nessun
         *    spostamento a caso);
         *  - la pipeline e la coda NON vengono toccate (sole letture).
         *
         * @return array{checked:int,moved:int,errors:array,disabled:bool}
         */
        public static function realign_now() {
                global $wpdb;

                $out = array(
                        'checked'  => 0,
                        'moved'    => 0,
                        'errors'   => array(),
                        'disabled' => false,
                );

                $cfg = self::slots_config();

                // Qualche slot attivo (globale o per agente)? Altrimenti no-op onesto.
                $any_enabled = ! empty( $cfg['enabled'] );
                if ( ! $any_enabled ) {
                        foreach ( $cfg['agents'] as $data ) {
                                if ( ! empty( $data['enabled'] ) ) {
                                        $any_enabled = true;
                                        break;
                                }
                        }
                }
                if ( ! $any_enabled ) {
                        $out['disabled'] = true;
                        self::log_moves( array(), 'Slot disattivati: nessun riallineamento.', 0, 0 );
                        return $out;
                }

                $tz  = wp_timezone();
                $now = new DateTimeImmutable( 'now', $tz );
                $max = $now->modify( '+' . self::HORIZON_DAYS . ' days' );

                $types = self::post_types();
                $ph    = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

                $sql  = "SELECT ID, post_title, post_date FROM {$wpdb->posts}"
                        . " WHERE post_status = %s AND post_type IN ( {$ph} )"
                        . ' AND post_date >= %s AND post_date < %s'
                        . ' ORDER BY post_date ASC LIMIT ' . absint( self::MAX_POSTS_PER_RUN );

                $params = array_merge(
                        array( 'future' ),
                        $types,
                        array( $now->format( 'Y-m-d H:i:s' ), $max->format( 'Y-m-d H:i:s' ) )
                );
                $rows   = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql costruito solo con placeholder.
                if ( ! is_array( $rows ) || empty( $rows ) ) {
                        self::log_moves( array(), 'Nessun articolo programmato nell\'orizzonte di ' . self::HORIZON_DAYS . ' giorni.', 0, 0 );
                        return $out;
                }

                $agent_map = self::agent_map_for_posts( $rows );
                $entries   = array();

                foreach ( $rows as $row ) {
                        $out['checked']++;

                        $dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $row->post_date, $tz );
                        if ( false === $dt ) {
                            continue;
                        }

                        $agent_id = isset( $agent_map[ (int) $row->ID ] ) ? (int) $agent_map[ (int) $row->ID ] : 0;
                        $slots    = self::slots_for( $agent_id, $cfg );
                        if ( empty( $slots ) ) {
                                continue;
                        }

                        $hi = $dt->format( 'H:i' );
                        if ( self::slot_contains( $slots, $hi ) ) {
                                continue;
                        }

                        $target = self::next_slot_start( $slots, $dt, $now );
                        if ( null === $target ) {
                                // Nessuno slot trovato entro 8 giorni: non si inventano date.
                                continue;
                        }

                        $local = $target->format( 'Y-m-d H:i:s' );
                        $gmt   = get_gmt_from_date( $local );

                        $result = wp_update_post(
                                array(
                                        'ID'            => (int) $row->ID,
                                        'post_date'     => $local,
                                        'post_date_gmt' => $gmt,
                                ),
                                true
                        );

                        if ( is_wp_error( $result ) ) {
                                $out['errors'][] = sprintf( 'Post #%1$d: %2$s', (int) $row->ID, $result->get_error_message() );
                                continue;
                        }

                        $out['moved']++;
                        $entries[] = array(
                                'time'    => current_time( 'mysql' ),
                                'post_id' => (int) $row->ID,
                                'title'   => (string) $row->post_title,
                                'from'    => $dt->format( 'd/m H:i' ),
                                'to'      => $target->format( 'd/m H:i' ),
                        );
                }

                $summary = sprintf(
                        'Riallineamento slot: %1$d articoli controllati, %2$d spostati%3$s',
                        $out['checked'],
                        $out['moved'],
                        empty( $out['errors'] ) ? '.' : ', ' . count( $out['errors'] ) . ' errori.'
                );

                self::log_moves( $entries, $summary, $out['checked'], $out['moved'] );

                return $out;
        }

        /**
         * Log onesto degli spostamenti (option 'nexus_calendar_moves').
         *
         * Shape: array(
         *   'entries'    => array( array('time','post_id','title','from','to'), ... ), // più recenti in testa
         *   'summary'    => string, // riepilogo ultimo giro
         *   'last_run'   => string, // current_time('mysql') dell'ultimo giro
         *   'last_check' => int,
         *   'last_moved' => int,
         * )
         *
         * @param array  $entries Voci di spostamento di questo giro.
         * @param string $summary Riepilogo testuale del giro.
         * @param int    $checked Quanti post controllati.
         * @param int    $moved   Quanti post spostati.
         * @return void
         */
        private static function log_moves( $entries, $summary, $checked, $moved ) {
                $raw = get_option( self::OPT_MOVES, array() );
                $log = is_array( $raw ) ? $raw : array();

                $old = isset( $log['entries'] ) && is_array( $log['entries'] ) ? $log['entries'] : array();

                $log['entries']    = array_merge( $entries, $old );
                $log['entries']    = array_slice( $log['entries'], 0, self::MOVES_LIMIT );
                $log['summary']    = (string) $summary;
                $log['last_run']   = current_time( 'mysql' );
                $log['last_check'] = absint( $checked );
                $log['last_moved'] = absint( $moved );

                update_option( self::OPT_MOVES, $log, false );
        }

        /**
         * Log spostamenti normalizzato per la vista.
         *
         * @return array
         */
        public static function moves_log() {
                $raw = get_option( self::OPT_MOVES, array() );
                $log = array(
                        'entries'    => array(),
                        'summary'    => '',
                        'last_run'   => '',
                        'last_check' => 0,
                        'last_moved' => 0,
                );

                if ( is_array( $raw ) ) {
                        if ( isset( $raw['entries'] ) && is_array( $raw['entries'] ) ) {
                                foreach ( $raw['entries'] as $entry ) {
                                        if ( ! is_array( $entry ) ) {
                                                continue;
                                        }
                                        $log['entries'][] = array(
                                                'time'    => isset( $entry['time'] ) ? (string) $entry['time'] : '',
                                                'post_id' => isset( $entry['post_id'] ) ? absint( $entry['post_id'] ) : 0,
                                                'title'   => isset( $entry['title'] ) ? (string) $entry['title'] : '',
                                                'from'    => isset( $entry['from'] ) ? (string) $entry['from'] : '',
                                                'to'      => isset( $entry['to'] ) ? (string) $entry['to'] : '',
                                        );
                                }
                        }
                        $log['summary']    = isset( $raw['summary'] ) ? (string) $raw['summary'] : '';
                        $log['last_run']   = isset( $raw['last_run'] ) ? (string) $raw['last_run'] : '';
                        $log['last_check'] = isset( $raw['last_check'] ) ? absint( $raw['last_check'] ) : 0;
                        $log['last_moved'] = isset( $raw['last_moved'] ) ? absint( $raw['last_moved'] ) : 0;
                }

                return $log;
        }

        /* ==================================================================
         * AZIONI ADMIN-POST (slot + riallineamento manuale)
         * ================================================================ */

        /**
         * Handler admin-post: salvataggio slot (globali o di un agente).
         *
         * Guard: capability + nonce 'nexus_calendar_slots'. Il form porta le
         * righe slot correnti (modificabili), eventuali rimozioni (bottone
         * nxa_cal_*_remove con indice) e un'eventuale riga nuova
         * (nxa_cal_*_new_from / _new_to): si persiste lo stato finale.
         *
         * @return void
         */
        public static function handle_save_slots() {
                self::guard_admin( self::NONCE_SLOTS );

                $cfg = self::slots_config();

                // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verificato sopra con check_admin_referer.
                $scope = isset( $_POST['nxa_cal_scope'] ) ? sanitize_key( wp_unslash( $_POST['nxa_cal_scope'] ) ) : 'global';
                $bad   = 0;

                if ( 'agent' === $scope ) {
                        $agent_id = isset( $_POST['nxa_cal_agent_id'] ) ? absint( $_POST['nxa_cal_agent_id'] ) : 0;
                        $agent_ok = ( $agent_id > 0 && null !== self::agent_row( $agent_id ) );
                        if ( ! $agent_ok ) {
                                self::redirect_self( array( 'nxa_cal_notice' => 'err' ) );
                        }

                        $parsed = self::parse_slot_rows( 'a', 'a' );
                        $bad    = $parsed['bad'];

                        $enabled = isset( $_POST['nxa_cal_a_enabled'] ) ? 1 : 0;

                        if ( ! $enabled || empty( $parsed['rows'] ) ) {
                                // Override spento o senza slot: rimuovi la voce (option pulita).
                                unset( $cfg['agents'][ $agent_id ] );
                        } else {
                                $cfg['agents'][ $agent_id ] = array(
                                        'enabled' => 1,
                                        'slots'   => $parsed['rows'],
                                );
                        }
                } else {
                        $parsed  = self::parse_slot_rows( 'g', 'g' );
                        $bad     = $parsed['bad'];
                        $enabled = isset( $_POST['nxa_cal_g_enabled'] ) ? 1 : 0;

                        $cfg['enabled'] = $enabled;
                        $cfg['global']  = $parsed['rows'];
                }
                // phpcs:enable

                update_option( self::OPT_SLOTS, $cfg, false );

                $args = array( 'nxa_cal_notice' => 'saved' );
                if ( $bad > 0 ) {
                        $args['bad'] = $bad;
                }

                self::redirect_self( $args );
        }

        /**
         * Handler admin-post: riallineamento manuale (stessa logica del cron).
         *
         * Guard: capability + nonce 'nexus_calendar_run'.
         *
         * @return void
         */
        public static function handle_run_now() {
                self::guard_admin( self::NONCE_RUN );

                $res = self::realign_now();

                $args = array(
                        'nxa_cal_notice' => 'run',
                        'moved'          => $res['moved'],
                        'checked'        => $res['checked'],
                );
                if ( ! empty( $res['disabled'] ) ) {
                        // Esito onesto: slot spenti, il riallineamento non fa nulla.
                        $args['dis'] = 1;
                }

                self::redirect_self( $args );
        }

        /**
         * Guard comune handler admin-post: capability + nonce, altrimenti wp_die.
         *
         * @param string $nonce_action Azione del nonce.
         * @return void
         */
        private static function guard_admin( $nonce_action ) {
                if ( ! current_user_can( self::CAPABILITY ) ) {
                        wp_die( 'Permesso negato: serve la capability manage_options.' );
                }

                check_admin_referer( $nonce_action, 'nxa_cal_nonce' );
        }

        /**
         * Parsing delle righe slot da $_POST per un form (scope global/agent).
         *
         * $prefix_from/$prefix_to identificano i nomi campo:
         *  - righe esistenti: nxa_cal_{p}_from[] / nxa_cal_{p}_to[];
         *  - rimozione: bottone nxa_cal_{p}_remove con value = indice riga;
         *  - riga nuova: nxa_cal_{p}_new_from / nxa_cal_{p}_new_to.
         *
         * Le righe non valide NON bloccano il salvataggio: vengono scartate e
         * contate (esito onesto via parametro 'bad' nel redirect).
         *
         * @param string $prefix_from Prefisso campi 'from' (g = globali, a = agente).
         * @param string $prefix_to   Prefisso campi 'to'.
         * @return array{rows:array,bad:int}
         */
        private static function parse_slot_rows( $prefix_from, $prefix_to ) {
                $rows = array();
                $bad  = 0;

                $from_key = 'nxa_cal_' . $prefix_from . '_from';
                $to_key   = 'nxa_cal_' . $prefix_to . '_to';
                $rem_key  = 'nxa_cal_' . $prefix_from . '_remove';
                $new_from = isset( $_POST[ 'nxa_cal_' . $prefix_from . '_new_from' ] ) ? wp_unslash( $_POST[ 'nxa_cal_' . $prefix_from . '_new_from' ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verificato dal chiamante.
                $new_to   = isset( $_POST[ 'nxa_cal_' . $prefix_to . '_new_to' ] ) ? wp_unslash( $_POST[ 'nxa_cal_' . $prefix_to . '_new_to' ] ) : '';       // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verificato dal chiamante.

                $froms = isset( $_POST[ $from_key ] ) && is_array( $_POST[ $from_key ] ) ? array_map( 'wp_unslash', $_POST[ $from_key ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- ogni valore normalizzato/validato sotto.
                $tos   = isset( $_POST[ $to_key ] ) && is_array( $_POST[ $to_key ] ) ? array_map( 'wp_unslash', $_POST[ $to_key ] ) : array();       // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- ogni valore normalizzato/validato sotto.

                // Le righe mantengono l'INDICE del form (chiave $i): la rimozione
                // via bottone usa proprio quell'indice, anche se qualche riga
                // non valida viene scartata (i buchi restano tali).
                $count = max( count( $froms ), count( $tos ) );
                for ( $i = 0; $i < $count; $i++ ) {
                        $from = isset( $froms[ $i ] ) ? self::normalize_hhmm( $froms[ $i ] ) : '';
                        $to   = isset( $tos[ $i ] ) ? self::normalize_hhmm( $tos[ $i ] ) : '';

                        if ( '' === $from && '' === $to ) {
                                // Riga vuota: ignorata (non è un errore).
                                continue;
                        }
                        if ( null === $from || null === $to || strcmp( $from, $to ) >= 0 ) {
                                // Da > a o orario illeggibile: scartata e contata.
                                $bad++;
                                continue;
                        }
                        $rows[ $i ] = array(
                                'from' => $from,
                                'to'   => $to,
                        );
                }

                // Rimozione di una riga (bottone submit con indice del form).
                $remove = isset( $_POST[ $rem_key ] ) ? absint( $_POST[ $rem_key ] ) : -1; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verificato dal chiamante.
                if ( $remove >= 0 && isset( $rows[ $remove ] ) ) {
                        unset( $rows[ $remove ] );
                }
                $rows = array_values( $rows );

                // Riga nuova (dal mini-form "aggiungi slot").
                $nf = self::normalize_hhmm( $new_from );
                $nt = self::normalize_hhmm( $new_to );
                if ( '' !== $new_from || '' !== $new_to ) {
                        if ( null === $nf || null === $nt || strcmp( $nf, $nt ) >= 0 ) {
                                $bad++;
                        } elseif ( count( $rows ) < self::MAX_SLOTS ) {
                                $rows[] = array(
                                        'from' => $nf,
                                        'to'   => $nt,
                                );
                        }
                }

                $rows = array_slice( $rows, 0, self::MAX_SLOTS );

                return array(
                        'rows' => $rows,
                        'bad'  => $bad,
                );
        }

        /**
         * Riga agente (per whitelist degli id nel salvataggio per-agente).
         *
         * @param int $agent_id Id agente.
         * @return object|null
         */
        private static function agent_row( $agent_id ) {
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'get_agent' ) ) {
                        return null;
                }

                return Nexus_DB::get_agent( absint( $agent_id ) );
        }

        /**
         * Redirect di ritorno alla pagina calendario con parametri extra.
         *
         * @param array $args Parametri query aggiuntivi (già chiavi whitelistate dal chiamante).
         * @return void
         */
        private static function redirect_self( $args ) {
                $url = admin_url( 'admin.php?page=' . self::MENU_SLUG );
                if ( ! empty( $args ) && is_array( $args ) ) {
                        $url = add_query_arg( $args, $url );
                }

                wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- redirect interno alla stessa admin.
                exit;
        }
}

// Boot self-registrante (pattern class-nexus-health.php / class-nexus-google-wizard.php):
// il bootstrap richiede il file PRIMA di do_action('nexus_modules_init') (v1.1.14, glue).
add_action( 'nexus_modules_init', array( 'Nexus_Calendar', 'init' ) );

// v1.1.15 (WF3): alla disattivazione del plugin il cron del calendario
// (nexus_calendar_tick) viene rimosso. Il metodo Nexus_Calendar::deactivate()
// esisteva dal 1.1.14 ma NESSUNO lo registrava (dead code): il cron sopravviveva
// alla disattivazione. Stesso pattern di class-nexus-health.php:1345 — il
// modulo è richiesto dal bootstrap (require a nexus-news-ai.php:76) PRIMA di
// do_action('nexus_modules_init'), quindi NEXUS_PLUGIN_FILE è già definito
// a questo punto.
register_deactivation_hook( NEXUS_PLUGIN_FILE, array( 'Nexus_Calendar', 'deactivate' ) );
