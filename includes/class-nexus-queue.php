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
 *     array('queued'=>true,'job_id'=>int,'executed'=>false,'result'=>null,'error'=>'',
 *           'duplicate'=>false)
 *   v1.1.9: $opts['unique'] (bool) — se true il job NON viene inserito quando
 *     esiste già un job pending/running con lo stesso hook (dedup per hook);
 *     in quel caso ritorna queued=false + duplicate=true (nessun errore).
 *   enable_queue=0 → esegue SUBITO l'handler se registrato (try/catch Throwable):
 *     array('queued'=>false,'job_id'=>0,'executed'=>bool,'result'=>mixed,'error'=>string)
 *   handler sconosciuto e enable_queue=0 → error 'handler_non_registrato'
 *   (con enable_queue=1 il job resta in coda e fallirà con last_error chiaro).
 *
 * Nexus_Queue::run_batch( $limit = 0 )
 *   $limit<=0 → setting queue_batch_size (min 1). Nexus_DB::claim_jobs($limit),
 *   per ogni job risolve l'handler dal filtro 'nexus_job_handlers' ed esegue con
 *   call_user_func_array (try/catch Throwable). Successo → status 'complete';
 *   eccezione → bump_job_failure (attempts+1 ATOMICO, guardia status='running'):
 *   se attempts < max_attempts → status 'pending' con scheduled_at = now +
 *   backoff (60 * 2^attempts, cap 3600) e last_error; altrimenti status
 *   'failed'. Se il bump ritorna 0 il job è stato ripreso da un altro runner:
 *   nessuna scrittura (evita sovrascritture). Handler mancante → status
 *   'failed' con last_error 'handler_non_registrato'.
 *   v1.1.9 — budget tempo (setting 'queue_time_budget', default 25s, filtro
 *   'nexus_queue_time_budget') e guardia memoria (80% del memory_limit): le
 *   verifiche avvengono SOLO tra un job e l'altro (mai a metà esecuzione) e i
 *   job già claimati ma non avviati tornano 'pending' con claim_token azzerato
 *   (break graceful, nessun job orfano in 'running').
 *   Ritorna array('processed'=>int,'failed'=>int,'requeued'=>int).
 *   Manutenzione: ogni 20 batch (o 1 volta su 20) → cleanup_jobs(7) + requeue_stale(30).
 *
 * Nexus_Queue::handle_runner()
 *   Handler WP-Cron 'nexus_queue_runner': esegue run_batch(0) e, se
 *   processed+failed > 0, scrive 1 attività 'queue' (max 1 log per batch).
 *
 * Nexus_Queue::retry( $job_id )
 *   Lavoro con status failed|cancelled|complete (NON 'dead': quella è la via
 *   d'uscita dedicata reset_dead) → status 'pending', scheduled_at adesso,
 *   started_at/completed_at svuotati (stringa vuota: Nexus_DB::update_job non
 *   gestisce NULL), last_error '' e claim_token ''. v1.1.9: max_attempts viene
 *   RICALCOLATO come max_attempts += attempts (decisione documentata nel
 *   metodo: preserva lo storico dei fallimenti e ripristina un ciclo completo
 *   di tentativi con backoff pieno). Anti-TOCTOU: update + verifica post con
 *   get_job. Ritorna bool.
 *
 * Nexus_Queue::cancel( $job_id )
 *   Solo status 'pending' → status 'cancelled' + completed_at adesso +
 *   claim_token ''. Anti-TOCTOU: update + verifica post con get_job. Ritorna bool.
 *
 * Nexus_Queue::reset_dead( $job_id )  [v1.1.9]
 *   Unica via d'uscita dai 'dead': status 'pending', attempts azzerato,
 *   claim_token/started_at/completed_at/last_error svuotati, scheduled_at adesso.
 *   Solo per intervento manuale (wp nexus queue dead --reset=<id>). Ritorna bool.
 *
 * Nexus_Queue::requeue_stale( $minutes = 30 )
 *   v1.1.9 — DEAD LETTER: per ogni job 'running' bloccato da più di N minuti
 *   chiama Nexus_DB::bump_job_failure (attempts+1 atomico con guardia
 *   status='running'); se attempts >= max_attempts → status 'dead' +
 *   completed_at (terminale, NON riprocessabile dalla coda normale), altrimenti
 *   status 'pending' con scheduled_at = now e claim_token azzerato. Elabora
 *   al massimo 100 job per passata (i successivi al giro dopo). Log attività
 *   'error' quando ci sono dead letter. Ritorna int (job gestiti).
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
         * Lavori 'running' più vecchi di così (minuti) sono considerati bloccati.
         */
        const STALE_MINUTES = 30;

        /**
         * Budget tempo di DEFAULT per un batch, in secondi (usato se il setting
         * 'queue_time_budget' non è impostato). Protegge da batch che saturano
         * la finestra utile del runner WP-Cron o del worker CLI.
         */
        const DEFAULT_TIME_BUDGET = 25;

        /**
         * Soglia di memoria: oltre questa FRAZIONE del memory_limit il batch
         * si ferma in modo graceful tra un job e l'altro (0.8 = 80%).
         */
        const MEMORY_THRESHOLD = 0.8;

        /**
         * Massimo numero di job stale elaborati per singola passata di
         * requeue_stale (protezione da backlog enormi in una sola request).
         */
        const STALE_MAX_PER_PASS = 100;

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
         * @param array  $opts Opzioni coda: priority (int), delay (secondi),
         *                     max_attempts (int), unique (bool: dedup per hook).
         * @return array array('queued','job_id','executed','result','error','duplicate').
         */
        public static function dispatch( $hook, $args = array(), $opts = array() ) {
                $out = array(
                        'queued'    => false,
                        'job_id'    => 0,
                        'executed'  => false,
                        'result'    => null,
                        'error'     => '',
                        'duplicate' => false,
                );

                $settings = self::settings();

                if ( ! empty( $settings['enable_queue'] ) ) {
                        if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'insert_job' ) ) {
                                $out['error'] = 'nexus_db_non_disponibile';
                                return $out;
                        }

                        // v1.1.9 — job unici per hook: con unique=true non accodare
                        // un nuovo job se ne esiste già uno pending/running con lo
                        // stesso hook (es. ogni tick WP-Cron accoderebbe un nuovo
                        // 'pipeline_cron_tick' anche se quello precedente è ancora
                        // in attesa → run_full in parallelo e costi AI doppi).
                        // RAZZA RESIDUA DOCUMENTATA: due processi possono superare
                        // questo check nello stesso istante e inserire DUE job;
                        // chiuderebbe un INSERT ... SELECT condizionale o un UNIQUE
                        // su (hook, status) in db.php (vietato in questo task). La
                        // race richiede millisecondi esatti e l'effetto è solo un
                        // job ridondante (gestito dal claim atomico): accettabile.
                        if ( ! empty( $opts['unique'] ) && method_exists( 'Nexus_DB', 'count_jobs' ) ) {
                                $hook_key = sanitize_key( (string) $hook );

                                try {
                                        $same = (int) Nexus_DB::count_jobs( array( 'status' => 'pending', 'hook' => $hook_key ) )
                                                + (int) Nexus_DB::count_jobs( array( 'status' => 'running', 'hook' => $hook_key ) );
                                } catch ( Throwable $e ) {
                                        // In caso di errore DB conviene accodare comunque:
                                        // un job duplicato è meglio di un tick perso.
                                        $same = 0;
                                }

                                if ( $same > 0 ) {
                                        $out['duplicate'] = true;
                                        return $out;
                                }
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
         * v1.1.9 — runner resiliente:
         * - budget tempo per batch (setting 'queue_time_budget', filtro
         *   'nexus_queue_time_budget', default DEFAULT_TIME_BUDGET secondi);
         * - guardia memoria (memory_get_usage(true) > MEMORY_THRESHOLD * memory_limit);
         * - entrambe le verifiche avvengono SOLO TRA un job e l'altro: interrompere
         *   un job a metà lascerebbe 'running' un job orfano fino a requeue_stale.
         *   I job claimati ma non avviati tornano 'pending' con claim_token
         *   azzerato (chi rimanda in pending DEVE azzerare il claim_token, v.
         *   contratto claim_jobs): il prossimo runner li riprende subito.
         *
         * @param int $limit Numero massimo di lavori (0 = setting queue_batch_size).
         * @return array array('processed'=>int,'failed'=>int,'requeued'=>int).
         */
        public static function run_batch( $limit = 0 ) {
                $processed = 0;
                $failed    = 0;
                $requeued  = 0;
                $empty     = array( 'processed' => 0, 'failed' => 0, 'requeued' => 0 );

                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'claim_jobs' ) ) {
                        return $empty;
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
                        return $empty;
                }

                // Budget tempo e soglia memoria: risolti UNA volta per batch.
                $budget    = self::time_budget();
                $started   = microtime( true );
                $mem_limit = self::memory_limit_bytes();
                $leftover  = array();

                foreach ( (array) $jobs as $job ) {
                        $job_id = isset( $job->id ) ? absint( $job->id ) : 0;

                        if ( $job_id <= 0 ) {
                                continue;
                        }

                        // --- Guardie graceful (solo tra un job e l'altro) ---
                        if ( ( microtime( true ) - $started ) >= $budget || self::memory_exhausted( $mem_limit ) ) {
                                // Il job non è stato ancora avviato: torniamo 'pending'
                                // dopo il loop (claim_token azzerato = nessun orfano).
                                $leftover[] = $job_id;
                                continue;
                        }

                        $callable = self::resolve_handler( isset( $job->hook ) ? $job->hook : '' );

                        // Handler mancante: fallisce subito con last_error chiaro.
                        if ( null === $callable ) {
                                self::update_job_safe( $job_id, array(
                                        'status'       => 'failed',
                                        'last_error'   => 'handler_non_registrato',
                                        'completed_at' => current_time( 'mysql' ),
                                        'claim_token'  => '',
                                ) );
                                $failed++;
                                continue;
                        }

                        $args = array();
                        if ( isset( $job->args ) && is_string( $job->args ) && '' !== $job->args ) {
                                $decoded = json_decode( $job->args, true );
                                if ( is_array( $decoded ) ) {
                                        $args = $decoded;
                                }
                        }

                        try {
                                call_user_func_array( $callable, array_values( $args ) );

                                // La riga è stata claimata da QUESTO runner (claim_token
                                // dedicato): qui la scrittura diretta di attempts è sicura.
                                $attempts = isset( $job->attempts ) ? ( (int) $job->attempts + 1 ) : 1;

                                self::update_job_safe( $job_id, array(
                                        'status'       => 'complete',
                                        'attempts'     => $attempts,
                                        'last_error'   => '',
                                        'completed_at' => current_time( 'mysql' ),
                                        'claim_token'  => '',
                                ) );
                                $processed++;
                        } catch ( Throwable $e ) {
                                $outcome = self::fail_job( $job_id, $job, self::clip( $e->getMessage() ) );

                                if ( 'pending' === $outcome ) {
                                        // Ritentativo schedulato: non è (ancora) un fallimento.
                                } elseif ( 'failed' === $outcome ) {
                                        $failed++;
                                }
                                // 'stolen': un altro runner ha già ripreso il job
                                // (bump_job_failure = 0) → nessuna scrittura, il job
                                // non è né nostro né fallito: non lo contiamo.
                        }
                }

                // I job claimati ma non avviati (budget/memoria) tornano disponibili
                // SUBITO: senza questo resterebbero 'running' orfani per 30 minuti.
                $requeued = self::requeue_unclaimed( $leftover );

                self::maybe_maintenance();

                return array( 'processed' => $processed, 'failed' => $failed, 'requeued' => $requeued );
        }

        /**
         * Gestisce il fallimento di un job (v1.1.9): incremento ATOMICO dei
         * tentativi con Nexus_DB::bump_job_failure (guardia status='running'
         * inclusa, contratto db.php #2) e poi backoff o fallimento definitivo.
         *
         * @param int    $job_id  ID del job.
         * @param object $job     Riga job claimata (per max_attempts).
         * @param string $message Messaggio d'errore (già troncato).
         * @return string 'pending'|'failed'|'stolen'.
         */
        private static function fail_job( $job_id, $job, $message ) {
                $max = isset( $job->max_attempts ) ? max( 1, (int) $job->max_attempts ) : 3;

                // bump_job_failure ritorna il nuovo attempts, 0 se il job NON era
                // più 'running' sotto di noi (già ripreso da un altro runner): in
                // quel caso NON scriviamo nulla di stato (evitiamo sovrascritture).
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'bump_job_failure' ) ) {
                        return 'stolen';
                }

                try {
                        $attempts = (int) Nexus_DB::bump_job_failure( $job_id, $message );
                } catch ( Throwable $e ) {
                        self::debug_log( 'bump_job_failure fallito: ' . $e->getMessage() );
                        return 'stolen';
                }

                if ( $attempts <= 0 ) {
                        return 'stolen';
                }

                if ( $attempts < $max ) {
                        // Retry con backoff esponenziale: 60 * 2^attempts, cap 1 ora.
                        // NOTA: attempts NON si riscrive qui — l'incremento atomico
                        // è già avvenuto in bump_job_failure.
                        $backoff = (int) min( self::MAX_BACKOFF, 60 * pow( 2, $attempts ) );

                        self::update_job_safe( $job_id, array(
                                'status'       => 'pending',
                                'scheduled_at' => gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) + $backoff ),
                                'last_error'   => $message,
                                'claim_token'  => '',
                        ) );

                        return 'pending';
                }

                self::update_job_safe( $job_id, array(
                        'status'       => 'failed',
                        'last_error'   => $message,
                        'completed_at' => current_time( 'mysql' ),
                        'claim_token'  => '',
                ) );

                return 'failed';
        }

        /**
         * Rimette in 'pending' i job claimati ma NON avviati perché il budget
         * tempo/memoria è esaurito prima del loro turno (break graceful).
         * NON tocca attempts: il job non ha fallito, ha solo aspettato.
         *
         * @param array $job_ids ID dei job claimati non avviati.
         * @return int Numero di job effettivamente rimessi in coda.
         */
        private static function requeue_unclaimed( $job_ids ) {
                $count = 0;

                foreach ( (array) $job_ids as $job_id ) {
                        $job_id = absint( $job_id );

                        if ( $job_id <= 0 ) {
                                continue;
                        }

                        $ok = self::update_job_safe( $job_id, array(
                                'status'       => 'pending',
                                'scheduled_at' => current_time( 'mysql' ),
                                'started_at'   => '',
                                'claim_token'  => '',
                        ) );

                        if ( $ok ) {
                                $count++;
                        }
                }

                if ( $count > 0 ) {
                        self::debug_log( sprintf( 'Budget tempo/memoria esaurito: %d job claimati rimessi in coda.', $count ) );
                }

                return $count;
        }

        /**
         * Budget tempo (secondi) per un singolo batch: setting 'queue_time_budget'
         * se presente e positivo, altrimenti filtro 'nexus_queue_time_budget',
         * altrimenti il default di classe. Valori <= 0 del filtro → default
         * (mai 0: significherebbe batch infiniti).
         *
         * @return int
         */
        private static function time_budget() {
                $settings = self::settings();
                $budget   = isset( $settings['queue_time_budget'] ) ? absint( $settings['queue_time_budget'] ) : 0;

                if ( $budget <= 0 ) {
                        $budget = (int) apply_filters( 'nexus_queue_time_budget', self::DEFAULT_TIME_BUDGET );
                }

                if ( $budget <= 0 ) {
                        $budget = self::DEFAULT_TIME_BUDGET;
                }

                return $budget;
        }

        /**
         * Guardia memoria: true se memory_get_usage(true) supera la soglia
         * (MEMORY_THRESHOLD) del memory_limit configurato in php.ini.
         * Con memory_limit = -1 (illimitato) la guardia è disattivata: il solo
         * budget tempo resta la protezione.
         *
         * @param int $limit_bytes Limite in byte (0 = illimitato/sconosciuto).
         * @return bool
         */
        private static function memory_exhausted( $limit_bytes ) {
                if ( $limit_bytes <= 0 ) {
                        return false;
                }

                return memory_get_usage( true ) > ( $limit_bytes * self::MEMORY_THRESHOLD );
        }

        /**
         * Converte il memory_limit di php.ini (es. '256M', '1G', '512K', '-1')
         * in byte. Ritorna 0 per illimitato o valori non leggibili (guardia
         * disattivata: meglio un batch senza guardia che uno sempre interrotto).
         *
         * @return int
         */
        private static function memory_limit_bytes() {
                $raw = trim( (string) ini_get( 'memory_limit' ) );

                if ( '' === $raw || '-1' === $raw ) {
                        return 0;
                }

                $value = (int) $raw;
                $unit  = strtolower( substr( $raw, -1 ) );

                if ( 'g' === $unit ) {
                        $value *= 1024 * 1024 * 1024;
                } elseif ( 'm' === $unit ) {
                        $value *= 1024 * 1024;
                } elseif ( 'k' === $unit ) {
                        $value *= 1024;
                }

                return $value;
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
                        self::requeue_stale( self::STALE_MINUTES );
                } catch ( Throwable $e ) {
                        self::debug_log( 'Manutenzione coda fallita: ' . $e->getMessage() );
                }
        }

        /* ============ GESTIONE SINGOLI LAVORI ============ */

        /**
         * Rimette in coda un lavoro terminato (failed|cancelled|complete).
         *
         * v1.1.9 — decisione su attempts (punto 8 del piano):
         * max_attempts += attempts, attempts INVARIATO. PERCHÉ: un job fallito a
         * fine ciclo ha attempts == max_attempts; rimetterlo pending senza toccare
         * nulla lo farebbe rifallire AL PRIMO tentativo (niente backoff, niente
         * spazio retry). Azzerare attempts cancellerebbe lo storico dei fallimenti
         * (last_error resta ma i contatori della dashboard mentirebbero). Con
         * max_attempts += attempts il retry umano dà un CICLO COMPLETO di tentativi
         * con backoff pieno (60*2^attempts) e lo storico resta intatto.
         * 'dead' è volutamente ESCLUSO: i dead letter si ripristinano SOLO con
         * reset_dead (scelta esplicita e consapevole, non un retry qualunque).
         *
         * Anti-TOCTOU (update_job non supporta WHERE condizionale): lettura →
         * update → verifica post con get_job. Se lo status è cambiato nel frattempo
         * si logga onestamente e si ritorna false.
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

                $attempts = isset( $job->attempts ) ? (int) $job->attempts : 0;
                $max      = isset( $job->max_attempts ) ? (int) $job->max_attempts : 0;

                $ok = self::update_job_safe( $job_id, array(
                        'status'       => 'pending',
                        'scheduled_at' => current_time( 'mysql' ),
                        'started_at'   => '',
                        'completed_at' => '',
                        'last_error'   => '',
                        'claim_token'  => '',
                        'max_attempts' => max( 1, $max + $attempts ),
                ) );

                return self::verify_transition( $job_id, $ok, 'pending', sprintf( 'Retry job #%d: stato cambiato durante l\'operazione, retry annullato.', absint( $job_id ) ) );
        }

        /**
         * Annulla un lavoro: solo se è ancora in attesa ('pending').
         *
         * Anti-TOCTOU: il rischio reale è che un runner claimi il job (→'running')
         * fra la nostra lettura e la nostra scrittura, e che la nostra UPDATE
         * sovrascriva il claim. update_job non ammette WHERE condizionale (db.php
         * vietato in questo task): si minimizza la finestra (check immediatamente
         * prima della scrittura) e si verifica DOPO con get_job; se lo status non
         * è quello atteso si logga onestamente 'operazione annullata'. La chiusura
         * totale della race richiede un UPDATE condizionale in db.php (v1.2).
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
                        'claim_token'  => '',
                ) );

                return self::verify_transition( $job_id, $ok, 'cancelled', sprintf( 'Cancel job #%d: job già in esecuzione o stato cambiato, operazione annullata.', absint( $job_id ) ) );
        }

        /**
         * Ripristina un lavoro in DEAD LETTER ('dead', v1.1.9): unica via d'uscita
         * prevista per gli status 'dead', riservata a un intervento manuale
         * (wp nexus queue dead --reset=<id>). Azzera attempts (il conteggio vecchio
         * non ha più senso dopo l'analisi umana) e claim_token, ripulisce i
         * timestamp e riprogramma a adesso.
         *
         * @param int $job_id ID del lavoro.
         * @return bool
         */
        public static function reset_dead( $job_id ) {
                $job = self::get_job_safe( $job_id );

                if ( null === $job ) {
                        return false;
                }

                if ( ! isset( $job->status ) || 'dead' !== (string) $job->status ) {
                        return false;
                }

                $ok = self::update_job_safe( $job_id, array(
                        'status'       => 'pending',
                        'attempts'     => 0,
                        'scheduled_at' => current_time( 'mysql' ),
                        'started_at'   => '',
                        'completed_at' => '',
                        'last_error'   => '',
                        'claim_token'  => '',
                ) );

                return self::verify_transition( $job_id, $ok, 'pending', sprintf( 'Reset dead job #%d: stato cambiato durante l\'operazione, reset annullato.', absint( $job_id ) ) );
        }

        /**
         * Rimette in coda i lavori 'running' bloccati (started_at più vecchio di
         * N minuti, tipicamente per timeout o crash durante l'esecuzione).
         *
         * v1.1.9 — DEAD LETTER (fix del loop infinito): la vecchia UPDATE massiva
         * riportava i job in 'pending' SENZA incrementare attempts, così un job
         * morto per fatal/timeout tornava in coda all'infinito. Ora per ogni job:
         * 1. bump_job_failure (attempts+1 ATOMICO con guardia status='running') —
         *    se ritorna 0 il job è stato ripreso da un altro runner: skip;
         * 2. attempts >= max_attempts → status 'dead' + completed_at (terminale,
         *    NON riprocessabile dalla coda normale; via d'uscita solo reset_dead);
         * 3. altrimenti → status 'pending', scheduled_at = now, claim_token = ''.
         *
         * Per-job invece che UPDATE massiva PERCHÉ: il destino (dead vs pending)
         * dipende da attempts/max_attempts di ogni riga e il bump atomico è l'unica
         * scrittura attempts sicura in concorrenza. Un job il cui update successivo
         * al bump fallisse resterebbe 'running' con il nuovo attempts: verrebbe
         * ripreso dalla prossima passata (log onesto in debug_log).
         *
         * @param int $minutes Età massima in minuti dell'esecuzione (default 30).
         * @return int Numero di lavori gestiti (rimessi in coda + dead letter).
         */
        public static function requeue_stale( $minutes = 30 ) {
                global $wpdb;

                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'tables' ) || ! method_exists( 'Nexus_DB', 'bump_job_failure' ) ) {
                        return 0;
                }

                $minutes = max( 1, absint( $minutes ) );
                $table   = Nexus_DB::tables()['jobs'];
                $now     = current_time( 'mysql' );
                $cutoff  = gmdate( 'Y-m-d H:i:s', strtotime( $now ) - $minutes * 60 );

                // Solo gli ID: il destino di ogni riga si decide per-job (bump +
                // dead letter). Cap per passata per non impalare una request su
                // backlog enormi: la manutenzione gira spesso, il resto al giro dopo.
                $ids = $wpdb->get_col( $wpdb->prepare(
                        "SELECT id FROM {$table}
                                WHERE status = 'running' AND started_at IS NOT NULL AND started_at <> '' AND started_at < %s
                                ORDER BY id ASC LIMIT %d",
                        $cutoff,
                        self::STALE_MAX_PER_PASS
                ) );

                $requeued = 0;
                $dead     = 0;

                foreach ( (array) $ids as $job_id ) {
                        $job_id = absint( $job_id );

                        if ( $job_id <= 0 ) {
                                continue;
                        }

                        $job = self::get_job_safe( $job_id );

                        if ( null === $job ) {
                                continue;
                        }

                        $max = isset( $job->max_attempts ) ? max( 1, (int) $job->max_attempts ) : 3;

                        // bump atomico + guardia di stato: 0 = un altro runner è già
                        // intervenuto sul job → non lo tocchiamo.
                        try {
                                $attempts = (int) Nexus_DB::bump_job_failure( $job_id, sprintf( 'timeout: job bloccato >%dmin', $minutes ) );
                        } catch ( Throwable $e ) {
                                self::debug_log( 'requeue_stale: bump_job_failure fallito sul job #' . $job_id . ': ' . $e->getMessage() );
                                continue;
                        }

                        if ( $attempts <= 0 ) {
                                continue;
                        }

                        if ( $attempts >= $max ) {
                                // DEAD LETTER: status terminale,completed_at chiuso.
                                $ok = self::update_job_safe( $job_id, array(
                                        'status'       => 'dead',
                                        'completed_at' => $now,
                                        'started_at'   => '',
                                        'claim_token'  => '',
                                ) );

                                if ( $ok ) {
                                        $dead++;
                                } else {
                                        self::debug_log( 'requeue_stale: impossibile marcare dead il job #' . $job_id . ' (riprossima alla prossima passata).' );
                                }

                                continue;
                        }

                        $ok = self::update_job_safe( $job_id, array(
                                'status'       => 'pending',
                                'scheduled_at' => $now,
                                'started_at'   => '',
                                'claim_token'  => '',
                        ) );

                        if ( $ok ) {
                                $requeued++;
                        } else {
                                self::debug_log( 'requeue_stale: impossibile rimettere in coda il job #' . $job_id . ' (riprossima alla prossima passata).' );
                        }
                }

                if ( $dead > 0 ) {
                        // I dead letter sono eventi degni di attenzione umana: log attività
                        // in 'error' così compaiono nella dashboard, non solo nel debug.
                        self::log_activity( sprintf( 'Dead letter: %d lavori bloccati (> %d min) marcati come dead.', $dead, $minutes ), 'error' );
                }

                if ( $requeued + $dead > 0 ) {
                        self::debug_log( sprintf( 'Requeue stale: %d lavori rimessi in coda, %d in dead letter (> %d min).', $requeued, $dead, $minutes ) );
                }

                return $requeued + $dead;
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
                        self::requeue_stale( self::STALE_MINUTES );
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
         * Verifica post-update anti-TOCTOU per le transizioni di stato manuali
         * (retry/cancel/reset_dead): update_job non supporta WHERE condizionale,
         * quindi dopo la scrittura si rilegge il job con get_job. Se la scrittura
         * è fallita o lo status non è quello atteso (un concorrente è passato di
         * lì) si logga onestamente e l'operazione risulta non eseguita.
         *
         * @param int    $job_id       ID del lavoro.
         * @param bool   $ok           Esito grezzo di update_job_safe.
         * @param string $expected     Status atteso dopo la scrittura.
         * @param string $fail_message Messaggio onesto da registrare in debug_log.
         * @return bool
         */
        private static function verify_transition( $job_id, $ok, $expected, $fail_message ) {
                $job_id = absint( $job_id );

                if ( ! $ok ) {
                        return false;
                }

                $after = self::get_job_safe( $job_id );

                if ( null === $after || $expected !== (string) $after->status ) {
                        self::debug_log( $fail_message );

                        return false;
                }

                return true;
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
