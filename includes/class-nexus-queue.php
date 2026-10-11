<?php
/**
 * Nexus Queue — coda lavori "stile Action Scheduler" basata su WP-Cron (v1.0.4).
 *
 * La coda usa WP-Cron come trigger (evento 'nexus_queue_runner' ogni 60 secondi,
 * schedule custom 'nexus_queue_60s') e NON sostituisce 'nexus_cron_tick', che
 * resta la sorgente: con enable_queue=1 il tick viene accodato come lavoro
 * 'pipeline_cron_tick' invece di girare inline.
 *
 * Contratto pubblico (tutti metodi statici):
 *
 * Nexus_Queue::init()
 *   Bootstrap del modulo: registra il filtro 'cron_schedules' (schedule
 *   'nexus_queue_60s' = 60 secondi), l'azione WP-Cron 'nexus_queue_runner' →
 *   self::handle_runner() e gli handler core (guard class_exists/method_exists)
 *   nell'array statico, risolto poi con il filtro 'nexus_job_handlers'.
 *   Poi chiama sync_schedule(): schedula l'evento SE enable_queue=1, lo rimuove
 *   se enable_queue=0 e l'evento esiste (pattern identico a Nexus_Cron).
 *
 * Nexus_Queue::add_schedule( $schedules )
 *   Callback filtro 'cron_schedules': aggiunge 'nexus_queue_60s' (60 secondi).
 *
 * Nexus_Queue::sync_schedule()
 *   Allinea l'evento WP-Cron 'nexus_queue_runner' al setting enable_queue.
 *
 * Nexus_Queue::register_handler( $hook, $callable )
 *   Registra un handler in array statico (merge col filtro 'nexus_job_handlers'
 *   alla risoluzione). Nessun ritorno.
 *
 * Nexus_Queue::dispatch( $hook, $args = array(), $opts = array() )
 *   enable_queue=1 → Nexus_DB::insert_job($hook,$args,$opts) e ritorna
 *     array('queued'=>true,'job_id'=>int,'executed'=>false,'result'=>null,'error'=>'')
 *   enable_queue=0 → esegue SUBITO l'handler se registrato (try/catch Throwable):
 *     array('queued'=>false,'job_id'=>0,'executed'=>bool,'result'=>mixed,'error'=>string)
 *   handler sconosciuto e enable_queue=0 → error 'handler_non_registrato'
 *   (con enable_queue=1 il job resta in coda e fallirà con last_error chiaro).
 *
 * Nexus_Queue::run_batch( $limit = 0 )
 *   $limit<=0 → setting queue_batch_size (min 1). Nexus_DB::claim_jobs($limit),
 *   per ogni job risolve l'handler dal filtro 'nexus_job_handlers' ed esegue con
 *   call_user_func_array (try/catch Throwable). Successo → status 'complete';
 *   eccezione → attempts+1: se attempts < max_attempts → status 'pending' con
 *   scheduled_at = now + backoff (60 * 2^attempts, cap 3600) e last_error;
 *   altrimenti status 'failed'. Handler mancante → status 'failed' con
 *   last_error 'handler_non_registrato'. Ritorna array('processed'=>int,'failed'=>int).
 *   Manutenzione: ogni 20 batch (o 1 volta su 20) → cleanup_jobs(7) + requeue_stale(30).
 *
 * Nexus_Queue::handle_runner()
 *   Handler WP-Cron 'nexus_queue_runner': esegue run_batch(0) e, se
 *   processed+failed > 0, scrive 1 attività 'queue' (max 1 log per batch).
 *
 * Nexus_Queue::retry( $job_id )
 *   Lavoro con status failed|cancelled|complete → status 'pending', scheduled_at
 *   adesso, started_at/completed_at svuotati (stringa vuota: Nexus_DB::update_job
 *   non gestisce NULL), last_error ''. Attempts INVARIATI. Ritorna bool.
 *
 * Nexus_Queue::cancel( $job_id )
 *   Solo status 'pending' → status 'cancelled' + completed_at adesso. Ritorna bool.
 *
 * Nexus_Queue::requeue_stale( $minutes = 30 )
 *   Lavori 'running' con started_at più vecchio di N minuti → status 'pending',
 *   last_error 'timeout: esecuzione interrotta'. Query diretta preparata su
 *   Nexus_DB::tables()['jobs']. Ritorna int (righe).
 *
 * Nexus_Queue::deactivate()
 *   Deattivazione: wp_clear_scheduled_hook('nexus_queue_runner') + requeue_stale(30)
 *   (try/catch, mai fatale). Chiamata con guard da Nexus_Cron::deactivate().
 *
 * Handler core registrati in init() (hook logico → callable):
 *   pipeline_run_full    → Nexus_Pipeline::run_full( $agent_id )
 *   pipeline_collect     → Nexus_Pipeline::collect( $agent_id )
 *   pipeline_evaluate    → Nexus_Pipeline::evaluate( $article_id )
 *   pipeline_rewrite     → Nexus_Pipeline::rewrite( $article_id )
 *   pipeline_publish     → Nexus_Pipeline::publish( $article_id )
 *   pipeline_wire_run    → Nexus_Pipeline::wire_run( $topic )
 *   article_translate    → Nexus_Translate::translate_article( $article_id, $lang )
 *   pipeline_cron_tick   → Nexus_Pipeline::cron_tick()
 *
 * Wiring per l'integratore: require del file nel bootstrap + Nexus_Queue::init();
 * Nexus_Cron::handle_tick() con enable_queue=1 accoda 'pipeline_cron_tick'.
 * Nessun output diretto, nessuna dipendenza esterna, PHP 7.4 compatibile.
 *
 * @package Nexus_News_AI
 * @since   1.0.4
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Queue {

        /**
         * Hook WP-Cron del runner della coda.
         */
        const HOOK_RUNNER = 'nexus_queue_runner';

        /**
         * Slug dello schedule custom (60 secondi) registrato via filtro cron_schedules.
         */
        const SCHEDULE_SLUG = 'nexus_queue_60s';

        /**
         * Intervallo del runner in secondi.
         */
        const INTERVAL = 60;

        /**
         * Cap del backoff esponenziale in secondi (1 ora).
         */
        const MAX_BACKOFF = 3600;

        /**
         * Età massima (giorni) dei lavori terminati prima della pulizia automatica.
         */
        const CLEANUP_DAYS = 7;

        /**
         * Ogni quanti batch eseguire la manutenzione (pulizia + requeue stale).
         */
        const MAINTENANCE_EVERY = 20;

        /**
         * Handler registrati (hook logico => callable), incluso i core di init().
         *
         * @var array
         */
        private static $handlers = array();

        /**
         * Contatore batch eseguiti in questa request (per la manutenzione periodica).
         *
         * @var int
         */
        private static $batch_count = 0;

        /* ============ BOOTSTRAP ============ */

        /**
         * Bootstrap modulo: filtri, azione WP-Cron, handler core e sync dello schedule.
         *
         * @return void
         */
        public static function init() {
                add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
                add_action( self::HOOK_RUNNER, array( __CLASS__, 'handle_runner' ) );

                // Handler core: solo se le classi/metodi esistono (mai fatale).
                foreach ( self::core_handlers() as $hook => $callable ) {
                        self::$handlers[ $hook ] = $callable;
                }

                self::sync_schedule();
        }

        /**
         * Costruisce la mappa degli handler core con guard class_exists/method_exists.
         *
         * @return array hook logico => callable.
         */
        private static function core_handlers() {
                $handlers = array();

                if ( class_exists( 'Nexus_Pipeline' ) ) {
                        $pipeline_methods = array(
                                'pipeline_run_full'  => 'run_full',
                                'pipeline_collect'   => 'collect',
                                'pipeline_evaluate'  => 'evaluate',
                                'pipeline_rewrite'   => 'rewrite',
                                'pipeline_publish'   => 'publish',
                                'pipeline_wire_run'  => 'wire_run',
                                'pipeline_cron_tick' => 'cron_tick',
                        );
                        foreach ( $pipeline_methods as $hook => $method ) {
                                if ( method_exists( 'Nexus_Pipeline', $method ) ) {
                                        $handlers[ $hook ] = array( 'Nexus_Pipeline', $method );
                                }
                        }
                }

                if ( class_exists( 'Nexus_Translate' ) && method_exists( 'Nexus_Translate', 'translate_article' ) ) {
                        $handlers['article_translate'] = array( 'Nexus_Translate', 'translate_article' );
                }

                return $handlers;
        }

        /**
         * Callback filtro 'cron_schedules': registra lo schedule custom del runner.
         *
         * @param array $schedules Schedules WP-Cron esistenti.
         * @return array
         */
        public static function add_schedule( $schedules ) {
                if ( ! isset( $schedules[ self::SCHEDULE_SLUG ] ) ) {
                        $schedules[ self::SCHEDULE_SLUG ] = array(
                                'interval' => self::INTERVAL,
                                'display'  => 'Nexus: runner coda lavori (ogni 60 secondi)',
                        );
                }

                return $schedules;
        }

        /**
         * Allinea l'evento WP-Cron 'nexus_queue_runner' al setting enable_queue:
         *
         * - enable_queue attivo  → schedula l'evento se assente (primo run tra 60s).
         * - enable_queue disattivo → rimuove l'evento se presente.
         *
         * @return void
         */
        public static function sync_schedule() {
                $settings = self::settings();

                if ( ! empty( $settings['enable_queue'] ) ) {
                        if ( ! wp_next_scheduled( self::HOOK_RUNNER ) ) {
                                wp_schedule_event( time() + self::INTERVAL, self::SCHEDULE_SLUG, self::HOOK_RUNNER );
                                self::debug_log( 'Runner coda lavori schedulato (ogni 60 secondi).' );
                        }
                        return;
                }

                if ( wp_next_scheduled( self::HOOK_RUNNER ) ) {
                        wp_clear_scheduled_hook( self::HOOK_RUNNER );
                        self::debug_log( 'Runner coda lavori rimosso (coda disattivata).' );
                }
        }

        /* ============ HANDLER E DISPATCH ============ */

        /**
         * Registra un handler per un hook logico (merge col filtro alla risoluzione).
         *
         * @param string   $hook     Hook logico (es. 'pipeline_run_full').
         * @param callable $callable Handler da eseguire.
         * @return void
         */
        public static function register_handler( $hook, $callable ) {
                $hook = sanitize_key( (string) $hook );

                if ( '' === $hook || ! is_callable( $callable ) ) {
                        return;
                }

                self::$handlers[ $hook ] = $callable;
        }

        /**
         * Risolve l'handler di un hook logico: array statico (core + register_handler)
         * unito al filtro 'nexus_job_handlers' (array hook => callable).
         *
         * @param string $hook Hook logico.
         * @return callable|null Handler risolto o null se assente/non callable.
         */
        private static function resolve_handler( $hook ) {
                $hook = sanitize_key( (string) $hook );

                if ( '' === $hook ) {
                        return null;
                }

                $map = self::$handlers;
                $map = apply_filters( 'nexus_job_handlers', $map );

                if ( isset( $map[ $hook ] ) && is_callable( $map[ $hook ] ) ) {
                        return $map[ $hook ];
                }

                return null;
        }

        /**
         * Dispatch di un lavoro: lo accoda se la coda è attiva, altrimenti
         * esegue l'handler SUBITO in sincrono.
         *
         * @param string $hook Hook logico (es. 'pipeline_cron_tick').
         * @param array  $args Argomenti passati all'handler (serializzati in coda).
         * @param array  $opts Opzioni coda: priority (int), delay (secondi), max_attempts (int).
         * @return array array('queued','job_id','executed','result','error').
         */
        public static function dispatch( $hook, $args = array(), $opts = array() ) {
                $out = array(
                        'queued'   => false,
                        'job_id'   => 0,
                        'executed' => false,
                        'result'   => null,
                        'error'    => '',
                );

                $settings = self::settings();

                if ( ! empty( $settings['enable_queue'] ) ) {
                        if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'insert_job' ) ) {
                                $out['error'] = 'nexus_db_non_disponibile';
                                return $out;
                        }

                        try {
                                $out['job_id'] = (int) Nexus_DB::insert_job( $hook, $args, $opts );
                                $out['queued'] = true;
                        } catch ( Throwable $e ) {
                                $out['error'] = $e->getMessage();
                        }

                        return $out;
                }

                // Coda disattivata: esecuzione sincrona se l'handler esiste.
                $callable = self::resolve_handler( $hook );

                if ( null === $callable ) {
                        $out['error'] = 'handler_non_registrato';
                        return $out;
                }

                try {
                        $out['result']   = call_user_func_array( $callable, array_values( (array) $args ) );
                        $out['executed'] = true;
                } catch ( Throwable $e ) {
                        $out['executed'] = true;
                        $out['error']    = $e->getMessage();
                }

                return $out;
        }

        /* ============ RUNNER ============ */

        /**
         * Esegue un batch di lavori in coda: claim atomico, esecuzione handler,
         * retry con backoff esponenziale e manutenzione periodica.
         *
         * @param int $limit Numero massimo di lavori (0 = setting queue_batch_size).
         * @return array array('processed'=>int,'failed'=>int).
         */
        public static function run_batch( $limit = 0 ) {
                $processed = 0;
                $failed    = 0;

                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'claim_jobs' ) ) {
                        return array( 'processed' => $processed, 'failed' => $failed );
                }

                $limit = absint( $limit );

                if ( $limit <= 0 ) {
                        $settings = self::settings();
                        $limit    = max( 1, absint( isset( $settings['queue_batch_size'] ) ? $settings['queue_batch_size'] : 5 ) );
                }

                try {
                        $jobs = Nexus_DB::claim_jobs( $limit );
                } catch ( Throwable $e ) {
                        self::debug_log( 'claim_jobs fallito: ' . $e->getMessage() );
                        return array( 'processed' => $processed, 'failed' => $failed );
                }

                foreach ( (array) $jobs as $job ) {
                        $job_id = isset( $job->id ) ? absint( $job->id ) : 0;

                        if ( $job_id <= 0 ) {
                                continue;
                        }

                        $callable = self::resolve_handler( isset( $job->hook ) ? $job->hook : '' );

                        // Handler mancante: fallisce subito con last_error chiaro.
                        if ( null === $callable ) {
                                self::update_job_safe( $job_id, array(
                                        'status'       => 'failed',
                                        'last_error'   => 'handler_non_registrato',
                                        'completed_at' => current_time( 'mysql' ),
                                ) );
                                $failed++;
                                continue;
                        }

                        $attempts = isset( $job->attempts ) ? ( (int) $job->attempts + 1 ) : 1;
                        $max      = isset( $job->max_attempts ) ? max( 1, (int) $job->max_attempts ) : 3;
                        $args     = array();
                        if ( isset( $job->args ) && is_string( $job->args ) && '' !== $job->args ) {
                                $decoded = json_decode( $job->args, true );
                                if ( is_array( $decoded ) ) {
                                        $args = $decoded;
                                }
                        }

                        try {
                                call_user_func_array( $callable, array_values( $args ) );

                                self::update_job_safe( $job_id, array(
                                        'status'       => 'complete',
                                        'attempts'     => $attempts,
                                        'last_error'   => '',
                                        'completed_at' => current_time( 'mysql' ),
                                ) );
                                $processed++;
                        } catch ( Throwable $e ) {
                                $message = self::clip( $e->getMessage() );

                                if ( $attempts < $max ) {
                                        // Retry con backoff esponenziale: 60 * 2^attempts, cap 1 ora.
                                        $backoff = (int) min( self::MAX_BACKOFF, 60 * pow( 2, $attempts ) );

                                        self::update_job_safe( $job_id, array(
                                                'status'       => 'pending',
                                                'attempts'     => $attempts,
                                                'scheduled_at' => gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) + $backoff ),
                                                'last_error'   => $message,
                                        ) );
                                } else {
                                        self::update_job_safe( $job_id, array(
                                                'status'       => 'failed',
                                                'attempts'     => $attempts,
                                                'last_error'   => $message,
                                                'completed_at' => current_time( 'mysql' ),
                                        ) );
                                        $failed++;
                                }
                        }
                }

                self::maybe_maintenance();

                return array( 'processed' => $processed, 'failed' => $failed );
        }

        /**
         * Handler dell'evento WP-Cron 'nexus_queue_runner': esegue un batch e
         * scrive (max) 1 attività di log se qualcosa è stato processato.
         *
         * @return void
         */
        public static function handle_runner() {
                try {
                        $result = self::run_batch( 0 );
                } catch ( Throwable $e ) {
                        self::debug_log( 'Runner coda fallito: ' . $e->getMessage() );
                        return;
                }

                $processed = isset( $result['processed'] ) ? (int) $result['processed'] : 0;
                $failed    = isset( $result['failed'] ) ? (int) $result['failed'] : 0;

                if ( $processed + $failed > 0 ) {
                        $detail = sprintf( 'Coda: %d eseguiti, %d falliti nel batch', $processed, $failed );
                        self::log_activity( $detail, $failed > 0 ? 'error' : 'info' );
                }
        }

        /**
         * Manutenzione periodica: ogni 20 batch (o con probabilità 1 su 20)
         * elimina i lavori terminati più vecchi di 7 giorni e rimette in coda
         * i 'running' bloccati da più di 30 minuti. Mai fatale.
         *
         * @return void
         */
        private static function maybe_maintenance() {
                self::$batch_count++;

                if ( 0 !== self::$batch_count % self::MAINTENANCE_EVERY && 1 !== mt_rand( 1, self::MAINTENANCE_EVERY ) ) {
                        return;
                }

                try {
                        if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'cleanup_jobs' ) ) {
                                Nexus_DB::cleanup_jobs( self::CLEANUP_DAYS );
                        }
                        self::requeue_stale( 30 );
                } catch ( Throwable $e ) {
                        self::debug_log( 'Manutenzione coda fallita: ' . $e->getMessage() );
                }
        }

        /* ============ GESTIONE SINGOLI LAVORI ============ */

        /**
         * Rimette in coda un lavoro terminato (failed|cancelled|complete).
         * Attempts invariati; started_at/completed_at/last_error vengono svuotati
         * con stringa vuota (Nexus_DB::update_job non gestisce NULL).
         *
         * @param int $job_id ID del lavoro.
         * @return bool
         */
        public static function retry( $job_id ) {
                $job = self::get_job_safe( $job_id );

                if ( null === $job ) {
                        return false;
                }

                $status = isset( $job->status ) ? (string) $job->status : '';

                if ( ! in_array( $status, array( 'failed', 'cancelled', 'complete' ), true ) ) {
                        return false;
                }

                $ok = self::update_job_safe( $job_id, array(
                        'status'       => 'pending',
                        'scheduled_at' => current_time( 'mysql' ),
                        'started_at'   => '',
                        'completed_at' => '',
                        'last_error'   => '',
                ) );

                return (bool) $ok;
        }

        /**
         * Annulla un lavoro: solo se è ancora in attesa ('pending').
         *
         * @param int $job_id ID del lavoro.
         * @return bool
         */
        public static function cancel( $job_id ) {
                $job = self::get_job_safe( $job_id );

                if ( null === $job ) {
                        return false;
                }

                if ( ! isset( $job->status ) || 'pending' !== (string) $job->status ) {
                        return false;
                }

                $ok = self::update_job_safe( $job_id, array(
                        'status'       => 'cancelled',
                        'completed_at' => current_time( 'mysql' ),
                ) );

                return (bool) $ok;
        }

        /**
         * Rimette in coda i lavori 'running' bloccati (started_at più vecchio di
         * N minuti, tipicamente per timeout o crash durante l'esecuzione).
         *
         * @param int $minutes Età massima in minuti dell'esecuzione (default 30).
         * @return int Numero di lavori rimessi in coda.
         */
        public static function requeue_stale( $minutes = 30 ) {
                global $wpdb;

                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'tables' ) ) {
                        return 0;
                }

                $minutes = max( 1, absint( $minutes ) );
                $table   = Nexus_DB::tables()['jobs'];
                $now     = current_time( 'mysql' );
                $cutoff  = gmdate( 'Y-m-d H:i:s', strtotime( $now ) - $minutes * 60 );

                $updated = $wpdb->query( $wpdb->prepare(
                        "UPDATE {$table} SET status = 'pending', last_error = %s, scheduled_at = %s, updated_at = %s
                                WHERE status = 'running' AND started_at IS NOT NULL AND started_at <> '' AND started_at < %s",
                        'timeout: esecuzione interrotta',
                        $now,
                        $now,
                        $cutoff
                ) );

                if ( $updated ) {
                        self::debug_log( sprintf( 'Requeue stale: %d lavori rimessi in coda (> %d min).', $updated, $minutes ) );
                }

                return (int) $updated;
        }

        /**
         * Deattivazione plugin: rimuove l'evento WP-Cron del runner e rimette
         * in coda i lavori 'running' bloccati. Mai fatale.
         *
         * @return void
         */
        public static function deactivate() {
                wp_clear_scheduled_hook( self::HOOK_RUNNER );

                try {
                        self::requeue_stale( 30 );
                } catch ( Throwable $e ) {
                        self::debug_log( 'Deattivazione coda: requeue_stale fallito: ' . $e->getMessage() );
                }
        }

        /* ============ HELPER PRIVATI ============ */

        /**
         * Legge le impostazioni plugin con guard su Nexus_DB (mai fatale).
         *
         * @return array
         */
        private static function settings() {
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'get_settings' ) ) {
                        return array();
                }

                try {
                        $settings = Nexus_DB::get_settings();
                } catch ( Throwable $e ) {
                        self::debug_log( 'get_settings fallito: ' . $e->getMessage() );
                        return array();
                }

                return is_array( $settings ) ? $settings : array();
        }

        /**
         * Recupera una riga lavoro con guard (ritorna null su qualsiasi problema).
         *
         * @param int $job_id ID del lavoro.
         * @return object|null
         */
        private static function get_job_safe( $job_id ) {
                $job_id = absint( $job_id );

                if ( $job_id <= 0 || ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'get_job' ) ) {
                        return null;
                }

                try {
                        $job = Nexus_DB::get_job( $job_id );
                } catch ( Throwable $e ) {
                        self::debug_log( 'get_job fallito: ' . $e->getMessage() );
                        return null;
                }

                return is_object( $job ) ? $job : null;
        }

        /**
         * Aggiorna un lavoro ignorando eventuali errori DB (mai fatale).
         *
         * @param int   $job_id ID del lavoro.
         * @param array $fields Colonne da aggiornare (whitelist Nexus_DB::update_job).
         * @return bool
         */
        private static function update_job_safe( $job_id, $fields ) {
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'update_job' ) ) {
                        return false;
                }

                try {
                        $ok = Nexus_DB::update_job( $job_id, $fields );
                } catch ( Throwable $e ) {
                        self::debug_log( 'update_job fallito: ' . $e->getMessage() );
                        return false;
                }

                return (bool) $ok;
        }

        /**
         * Scrive un'attività di log della coda con guard (mai fatale).
         *
         * @param string $detail Dettaglio attività.
         * @param string $status Stato ('info'|'error'|...).
         * @return void
         */
        private static function log_activity( $detail, $status = 'info' ) {
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                        return;
                }

                try {
                        Nexus_DB::insert_activity( 0, 'queue', $detail, $status );
                } catch ( Throwable $e ) {
                        self::debug_log( 'insert_activity fallito: ' . $e->getMessage() );
                }
        }

        /**
         * Tronca un messaggio di errore a una lunghezza massima (per last_error).
         *
         * @param string $message Messaggio originale.
         * @param int    $max     Lunghezza massima.
         * @return string
         */
        private static function clip( $message, $max = 500 ) {
                $message = trim( (string) $message );

                if ( '' === $message ) {
                        return 'errore sconosciuto';
                }

                if ( function_exists( 'mb_substr' ) ) {
                        return mb_substr( $message, 0, $max );
                }

                return substr( $message, 0, $max );
        }

        /**
         * Log su error_log solo quando WP_DEBUG è attivo.
         *
         * @param string $message Messaggio da registrare.
         * @return void
         */
        private static function debug_log( $message ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[Nexus News AI][Queue] ' . $message );
                }
        }
}
