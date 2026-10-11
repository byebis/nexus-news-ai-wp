<?php
/**
 * Nexus Cron — schedulazione WP-Cron per la pipeline editoriale.
 *
 * Contratto pubblico:
 * Nexus_Cron::init()               — registra schedule 'nexus_interval', handler tick e sync su update settings
 * Nexus_Cron::sync_schedule()      — allinea l'evento 'nexus_cron_tick' a settings (auto_collect / collect_interval)
 * Nexus_Cron::handle_tick()        — handler dell'evento: lancia Nexus_Pipeline::cron_tick()
 * Nexus_Cron::on_settings_updated()— hook update_option_nexus_settings: reschedule se cambiano auto_collect/collect_interval
 * Nexus_Cron::deactivate()         — static, chiamato da register_deactivation_hook nel bootstrap
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
	 * @return void
	 */
	public static function handle_tick() {
		if ( ! class_exists( 'Nexus_Pipeline' ) ) {
			self::debug_log( 'Nexus_Pipeline non ancora caricata: tick ignorato.' );
			return;
		}

		self::debug_log( 'Cron tick: avvio pipeline editoriale.' );

		try {
			Nexus_Pipeline::cron_tick();
		} catch ( Exception $e ) {
			self::debug_log( 'Errore durante cron tick: ' . $e->getMessage() );
		} catch ( Throwable $e ) {
			self::debug_log( 'Errore fatale durante cron tick: ' . $e->getMessage() );
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
	}

	/* ============ HELPER PRIVATI ============ */

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
