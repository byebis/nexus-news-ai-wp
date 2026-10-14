<?php
/**
 * Nexus Cron — schedulazione WP-Cron per la pipeline editoriale.
 *
 * Contratto pubblico:
 * Nexus_Cron::init()               — registra schedule 'nexus_interval', handler tick e sync su update settings
 * Nexus_Cron::sync_schedule()      — allinea l'evento 'nexus_cron_tick' a settings (auto_collect / collect_interval)
 * Nexus_Cron::handle_tick()        — handler dell'evento: con la coda attiva (v1.0.4) accoda
 *                                    'pipeline_cron_tick' con unique=true (v1.1.9: job unici per
 *                                    hook, zero accumulo se il tick precedente è ancora in coda),
 *                                    altrimenti lancia Nexus_Pipeline::cron_tick() con mutex
 *                                    transient non bloccante (v1.1.13 O1: due tick WP-Cron
 *                                    sovrapposti non girano mai in parallelo)
 * Nexus_Cron::on_settings_updated()— hook update_option_nexus_settings: reschedule se cambiano auto_collect/collect_interval
 * Nexus_Cron::deactivate()         — static, chiamato da register_deactivation_hook nel bootstrap
 *                                    (v1.0.4: deattiva anche la coda Nexus_Queue; v1.1.13 O1:
 *                                    rimuove il mutex del tick)
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Cron {

        /**
         * Hook WP-Cron gestito da questa classe.
         */
        const HOOK_TICK = 'nexus_cron_tick';

        /**
         * Slug dello schedule custom registrato via filtro cron_schedules.
         */
        const SCHEDULE_SLUG = 'nexus_interval';

        /**
         * Intervallo minimo ammesso tra due raccolte (300 secondi = 5 minuti).
         */
        const MIN_INTERVAL = 300;

        /**
         * Ritardo iniziale prima del primo tick dopo la schedulazione (300 secondi).
         */
        const FIRST_RUN_DELAY = 300;

        /**
         * Chiave del transient-mutex del tick (v1.1.13 O1), percorso SENZA coda:
         * presente = un tick è già in esecuzione → il tick sovrapposto salta
         * (log debug, non bloccante). Con la coda attiva non serve: il claim del
         * job è già atomico.
         * v1.1.15 (WA11): il lock vive come opzione _transient_<key> con valore =
         * timestamp di acquisizione (add_option atomico, TTL manuale — v.
         * acquire_lock): niente più finestra di corsa nel check-then-set.
         */
        const TICK_LOCK = 'nexus_tick_lock';

        /**
         * TTL del mutex tick in secondi (900 = 15 min): rete di sicurezza se il
         * processo muore prima del finally; il rilascio NORMALE avviene a fine run
         * (try/finally), anche su eccezione.
         */
        const TICK_LOCK_TTL = 900;

        /**
         * Bootstrap modulo.
         *
         * @return void
         */
        public static function init() {
                add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
                add_action( self::HOOK_TICK, array( __CLASS__, 'handle_tick' ) );
                add_action( 'update_option_nexus_settings', array( __CLASS__, 'on_settings_updated' ), 10, 2 );

                self::sync_schedule();
        }

        /**
         * Registra lo schedule custom 'nexus_interval' con l'intervallo corrente
         * dalle impostazioni (collect_interval, minimo 300 secondi).
         *
         * @param array $schedules Schedules WP-Cron esistenti.
         * @return array
         */
        public static function add_schedule( $schedules ) {
                $schedules[ self::SCHEDULE_SLUG ] = array(
                        'interval' => self::get_interval(),
                        'display'  => 'Nexus: intervallo raccolta',
                );

                return $schedules;
        }

        /**
         * Allinea l'evento WP-Cron allo stato attuale delle impostazioni:
         *
         * - auto_collect falsy  → nessun evento: rimuove l'hook se presente.
         * - auto_collect truthy → schedula l'evento se assente; se presente ma
         *   l'intervallo registrato differisce da collect_interval, cancella
         *   e rischedula con il nuovo intervallo.
         *
         * @return void
         */
        public static function sync_schedule() {
                $auto_collect = self::auto_collect_enabled();

                if ( ! $auto_collect ) {
                        if ( wp_next_scheduled( self::HOOK_TICK ) ) {
                                wp_clear_scheduled_hook( self::HOOK_TICK );
                        }
                        return;
                }

                $interval = self::get_interval();

                if ( ! wp_next_scheduled( self::HOOK_TICK ) ) {
                        wp_schedule_event( time() + self::FIRST_RUN_DELAY, self::SCHEDULE_SLUG, self::HOOK_TICK );
                        self::debug_log( 'Raccolta automatica schedulata (intervallo ' . $interval . 's).' );
                        return;
                }

                // L'evento esiste: verifica che l'intervallo registrato coincida con le impostazioni.
                $event = wp_get_scheduled_event( self::HOOK_TICK );

                if ( $event && (int) $event->interval !== $interval ) {
                        wp_clear_scheduled_hook( self::HOOK_TICK );
                        wp_schedule_event( time() + self::FIRST_RUN_DELAY, self::SCHEDULE_SLUG, self::HOOK_TICK );
                        self::debug_log( 'Raccolta automatica rischedulata con nuovo intervallo ' . $interval . 's.' );
                }
        }

        /**
         * Handler dell'evento 'nexus_cron_tick': lancia un tick della pipeline.
         *
         * Il bootstrap carica tutte le classi prima di plugins_loaded, quindi
         * Nexus_Pipeline è disponibile; per sicurezza si protegge comunque con
         * class_exists nel caso l'evento scatti fuori dal normale bootstrap.
         *
         * v1.1.13 (O1): sul percorso senza coda il tick è protetto da un mutex
         * transient (TICK_LOCK, non bloccante): un tick sovrapposto salta invece
         * di duplicare il lavoro AI. Rilascio nel finally anche su eccezione.
         * v1.1.15 (WA11): l'acquisizione del mutex è ATOMICA (add_option
         * check-and-set con TTL manuale, v. acquire_lock).
         *
         * @return void
         */
        public static function handle_tick() {
                if ( ! class_exists( 'Nexus_Pipeline' ) ) {
                        self::debug_log( 'Nexus_Pipeline non ancora caricata: tick ignorato.' );
                        return;
                }

                self::debug_log( 'Cron tick: avvio pipeline editoriale.' );

                // v1.0.4 — coda lavori attiva: il tick diventa un lavoro accodato
                // (il runner WP-Cron 'nexus_queue_runner' lo esegue in batch).
                if ( class_exists( 'Nexus_Queue' ) && method_exists( 'Nexus_Queue', 'dispatch' )
                        && class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'get_settings' ) ) {
                        $nexus_settings = Nexus_DB::get_settings();
                        if ( ! empty( $nexus_settings['enable_queue'] ) ) {
                                // v1.1.9 — unique=true (job unici per hook): senza dedup,
                                // ogni tick accodava un nuovo 'pipeline_cron_tick' anche
                                // se il precedente era ancora pending/running → più
                                // run_full in parallelo e costi AI duplicati. Se il job
                                // esiste già il dispatch ritorna duplicate=true e qui
                                // non si inserisce nulla (zero accumulo in coda).
                                $out = Nexus_Queue::dispatch( 'pipeline_cron_tick', array(), array( 'unique' => true ) );

                                if ( is_array( $out ) && ! empty( $out['duplicate'] ) ) {
                                        self::debug_log( 'Tick già presente in coda: dispatch saltato (unique).' );
                                } else {
                                        self::debug_log( 'Tick accodato alla coda lavori.' );
                                }

                                return;
                        }
                }

                // v1.1.15 (WA11) — guardia di ownership per il rilascio: il finally
                // scatta anche sul percorso 'skip' (return dentro il try) e un tick
                // saltato NON deve cancellare il lock del tick ancora in esecuzione
                // (riaprirebbe la finestra di concorrenza che il mutex deve chiudere).
                $lock_acquired = false;

                try {
                        // v1.1.13 (O1) — mutex tick non bloccante: con enable_queue=0 due
                        // tick WP-Cron sovrapposti giravano cron_tick in parallelo
                        // (doppio costo AI, doppie pubblicazioni). Lock presente → skip
                        // silenzioso con log debug; il rilascio è garantito nel finally a
                        // fine run anche su eccezione. Il percorso CON coda non è toccato
                        // (ha già il claim atomico del job unique).
                        // v1.1.15 (WA11) — acquisizione ATOMICA (add_option check-and-set,
                        // v. acquire_lock): il vecchio get_transient+set_transient era
                        // check-then-set con una finestra di corsa tra i due tick.
                        if ( ! self::acquire_lock( self::TICK_LOCK, self::TICK_LOCK_TTL ) ) {
                                self::debug_log( 'Tick già in esecuzione (mutex attivo): tick saltato.' );
                                return;
                        }
                        $lock_acquired = true;

                        Nexus_Pipeline::cron_tick();
                } catch ( Exception $e ) {
                        self::debug_log( 'Errore durante cron tick: ' . $e->getMessage() );
                } catch ( Throwable $e ) {
                        self::debug_log( 'Errore fatale durante cron tick: ' . $e->getMessage() );
                } finally {
                        // Rilascio garantito del mutex (anche su eccezione): il TTL è la
                        // rete di sicurezza solo per crash duri del processo. Solo se il
                        // lock è nostro ($lock_acquired): il tick saltato esce dal try
                        // senza possedere il mutex.
                        if ( $lock_acquired ) {
                                self::release_lock( self::TICK_LOCK );
                        }
                }
        }

        /**
         * Hook su update_option_nexus_settings: rischedula automaticamente
         * quando cambiano auto_collect o collect_interval.
         *
         * @param mixed $old_value Valore precedente dell'opzione.
         * @param mixed $new_value Nuovo valore dell'opzione.
         * @return void
         */
        public static function on_settings_updated( $old_value, $new_value ) {
                $old = is_array( $old_value ) ? $old_value : array();
                $new = is_array( $new_value ) ? $new_value : array();

                $defaults = class_exists( 'Nexus_DB' ) ? Nexus_DB::default_settings() : array();
                $old      = wp_parse_args( $old, $defaults );
                $new      = wp_parse_args( $new, $defaults );

                $old_auto     = self::normalize_bool( $old['auto_collect'] );
                $new_auto     = self::normalize_bool( $new['auto_collect'] );
                $old_interval = self::normalize_interval( $old['collect_interval'] );
                $new_interval = self::normalize_interval( $new['collect_interval'] );

                if ( $old_auto !== $new_auto || $old_interval !== $new_interval ) {
                        self::debug_log( 'Impostazioni raccolta modificate: sincronizzo la schedulazione.' );
                        self::sync_schedule();
                }
        }

        /**
         * Deattivazione plugin: rimuove l'evento schedulato.
         * Chiamata static via register_deactivation_hook nel bootstrap.
         *
         * @return void
         */
        public static function deactivate() {
                wp_clear_scheduled_hook( self::HOOK_TICK );

                // v1.1.13 (O1): nessun tick può più girare dopo la disattivazione:
                // il mutex residuo non deve sopravvivere al plugin. v1.1.15 (WA11):
                // rilascio via helper (opzione _transient_ + eventuale timeout legacy).
                self::release_lock( self::TICK_LOCK );

                // v1.0.4 — deattiva anche la coda lavori (rimuove il runner e
                // rimette in coda i lavori 'running' bloccati).
                if ( class_exists( 'Nexus_Queue' ) && method_exists( 'Nexus_Queue', 'deactivate' ) ) {
                        Nexus_Queue::deactivate();
                }
        }

        /* ============ HELPER PRIVATI ============ */

        /**
         * v1.1.15 (WA11) — acquisizione ATOMICA del mutex: add_option fallisce se
         * l'opzione esiste già (INSERT su chiave duplicata), quindi il
         * check-and-set è un solo passo (il vecchio get_transient+set_transient
         * era una finestra di corsa tra i due tick WP-Cron sovrapposti).
         * Valore = timestamp di acquisizione: un lock più vecchio del TTL è orfano
         * di un crash duro (il release in finally non è potuto girare) e viene
         * RUBATO (delete + re-add). I lock legacy creati con set_transient (con
         * opzione _transient_timeout_*) sono letti con la stessa regola.
         *
         * @param string $key Chiave del lock (senza prefisso _transient_).
         * @param int    $ttl TTL in secondi oltre il quale il lock è rubabile.
         * @return bool True se il lock è stato acquisito.
         */
        private static function acquire_lock( $key, $ttl ) {
                $option = '_transient_' . $key;
                $now    = time();
                $held   = get_option( $option, false );

                if ( false !== $held ) {
                        if ( is_numeric( $held ) && ( $now - (int) $held ) > (int) $ttl ) {
                                self::release_lock( $key );
                        } else {
                                return false;
                        }
                }

                return (bool) add_option( $option, $now, '', 'no' );
        }

        /**
         * v1.1.15 (WA11) — rilascio del mutex (idempotente; pulisce anche
         * l'eventuale _transient_timeout_* lasciato dai lock legacy set_transient).
         *
         * @param string $key Chiave del lock (senza prefisso _transient_).
         * @return void
         */
        private static function release_lock( $key ) {
                delete_option( '_transient_' . $key );
                delete_option( '_transient_timeout_' . $key );
        }

        /**
         * Intervallo effettivo in secondi: collect_interval dalle impostazioni,
         * con minimo 300 secondi.
         *
         * @return int
         */
        private static function get_interval() {
                $interval = 3600;

                if ( class_exists( 'Nexus_DB' ) ) {
                        $settings = Nexus_DB::get_settings();
                        if ( isset( $settings['collect_interval'] ) ) {
                                $interval = (int) $settings['collect_interval'];
                        }
                }

                return self::normalize_interval( $interval );
        }

        /**
         * Normalizza un intervallo: intero positivo, minimo 300 secondi.
         *
         * @param mixed $value Valore grezzo dell'impostazione.
         * @return int
         */
        private static function normalize_interval( $value ) {
                $interval = absint( $value );

                if ( $interval < self::MIN_INTERVAL ) {
                        $interval = self::MIN_INTERVAL;
                }

                return $interval;
        }

        /**
         * Legge auto_collect dalle impostazioni e lo normalizza a boolean.
         *
         * @return bool
         */
        private static function auto_collect_enabled() {
                if ( ! class_exists( 'Nexus_DB' ) ) {
                        return false;
                }

                $settings = Nexus_DB::get_settings();

                if ( ! isset( $settings['auto_collect'] ) ) {
                        return false;
                }

                return self::normalize_bool( $settings['auto_collect'] );
        }

        /**
         * Normalizza un valore di impostazione a boolean (1/'1'/true/'yes' → true).
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

        /**
         * Log su error_log solo quando WP_DEBUG è attivo.
         *
         * @param string $message Messaggio da registrare.
         * @return void
         */
        private static function debug_log( $message ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[Nexus News AI][Cron] ' . $message );
                }
        }
}
