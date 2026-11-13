<?php
/**
 * Nexus Automation — cruscotto cron, retry con backoff, alert redazione ferma
 * e digest giornaliero di attività (v1.1.34, B4/B5).
 *
 * - Registra metadati del tick (ultimo/prossimo/ritardo) per la UI.
 * - Fornisce retry con backoff per i job falliti (coda).
 * - Rileva la redazione ferma (nessun articolo prodotto in X ore) → log + notifica.
 * - Invia un digest giornaliero di attività (via Nexus_Notify).
 *
 * @package Nexus_News_AI
 * @since   1.1.34
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Nexus_Automation {

	const OPT_TICK_STATUS = 'nexus_automation_tick_status';
	const OPT_ALERT_TS   = 'nexus_automation_stall_alert_ts';

	/**
	 * Self-init (su nexus_modules_init).
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::HOOK_DAILY, array( __CLASS__, 'run_daily' ) );
		add_action( self::HOOK_STALL_CHECK, array( __CLASS__, 'check_stall' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		self::ensure_schedules();
	}

	const HOOK_DAILY = 'nexus_automation_daily';
	const HOOK_STALL_CHECK = 'nexus_automation_stall_check';

	/**
	 * Registra i cron (se non già presenti).
	 *
	 * @return void
	 */
	public static function ensure_schedules() {
		if ( ! wp_next_scheduled( self::HOOK_DAILY ) ) {
			wp_schedule_event( strtotime( 'tomorrow 08:00' ), 'daily', self::HOOK_DAILY );
		}
		if ( ! wp_next_scheduled( self::HOOK_STALL_CHECK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK_STALL_CHECK );
		}
	}

	/**
	 * Intervallo di rilevamento redazione ferma (ore, default 12).
	 *
	 * @return int
	 */
	public static function stall_hours() {
		$settings = ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'get_settings' ) ) ? Nexus_DB::get_settings() : array();
		return max( 2, (int) ( $settings['automation_stall_hours'] ?? 12 ) );
	}

	/**
	 * Registra lo stato dell'ultimo tick.
	 *
	 * @return void
	 */
	public static function record_tick() {
		$data = array(
			'last_tick'  => current_time( 'mysql' ),
			'last_ts'    => (int) current_time( 'timestamp' ),
			'next_tick'  => wp_next_scheduled( 'nexus_cron_tick' ),
			'count'      => ( (int) get_option( self::OPT_TICK_STATUS . '_count', 0 ) ) + 1,
		);
		update_option( self::OPT_TICK_STATUS, $data, false );
		update_option( self::OPT_TICK_STATUS . '_count', $data['count'], false );
	}

	/**
	 * Stato tick per la UI.
	 *
	 * @return array
	 */
	public static function tick_status() {
		$data = get_option( self::OPT_TICK_STATUS, array() );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		$next = isset( $data['next_tick'] ) ? (int) $data['next_tick'] : wp_next_scheduled( 'nexus_cron_tick' );
		$last = isset( $data['last_ts'] ) ? (int) $data['last_ts'] : 0;
		$now  = (int) current_time( 'timestamp' );

		$delay = 0;
		if ( $next > 0 ) {
			$delay = max( 0, $now - $next );
		}

		return array(
			'last_tick'  => isset( $data['last_tick'] ) ? (string) $data['last_tick'] : '',
			'next_tick'  => $next ? gmdate( 'Y-m-d H:i:s', $next + ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) : '',
			'delay_sec'  => $delay,
			'delay_label' => self::human_diff( $delay ),
			'count'      => (int) ( $data['count'] ?? 0 ),
		);
	}

	/**
	 * Retry dei job falliti con backoff esponenziale.
	 *
	 * @param int $max_attempts Tentativi massimi.
	 * @return array{retried:int,only:bool}
	 */
	public static function retry_failed_jobs( $max_attempts = 3 ) {
		if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'jobs' ) ) {
			return array( 'retried' => 0, 'ok' => false );
		}

		$jobs = Nexus_DB::jobs( array( 'status' => 'failed', 'limit' => 50 ) );
		$retried = 0;
		$now = (int) current_time( 'timestamp' );

		foreach ( (array) $jobs as $job ) {
			$id = isset( $job->id ) ? (int) $job->id : 0;
			if ( ! $id ) {
				continue;
			}
			$attempts = (int) ( $job->attempts ?? 0 );
			if ( $attempts >= $max_attempts ) {
				continue;
			}
			// Backoff: 2^attempts * 10 min.
			$backoff = (int) pow( 2, $attempts ) * 600;
			$last_fail = (int) ( $job->completed_at ?? 0 );
			if ( $last_fail > 0 && ( $now - $last_fail ) < $backoff ) {
				continue;
			}
			if ( class_exists( 'Nexus_Queue' ) && method_exists( 'Nexus_Queue', 'retry' ) ) {
				if ( Nexus_Queue::retry( $id ) ) {
					$retried++;
				}
			}
		}

		return array( 'retried' => $retried, 'ok' => true );
	}

	/**
	 * Rileva la redazione ferma (nessun articolo prodotto in X ore) e invia
	 * log + notifica (una volta per periodo).
	 *
	 * @return array{stalled:bool}
	 */
	public static function check_stall() {
		$hours = self::stall_hours();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS );

		// Conta articoli creati dopo cutoff (query diretta: count_articles non
		// supporta 'after').
		$produced = 0;
		if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'tables' ) ) {
			global $wpdb;
			$table = Nexus_DB::tables()['articles'];
			$produced = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $cutoff ) );
		}

		if ( $produced > 0 ) {
			delete_option( self::OPT_ALERT_TS );
			return array( 'stalled' => false );
		}

		$last_alert = (int) get_option( self::OPT_ALERT_TS, 0 );
		if ( $last_alert > 0 && ( time() - $last_alert ) < $hours * HOUR_IN_SECONDS ) {
			return array( 'stalled' => true, 'already_alerted' => true );
		}

		update_option( self::OPT_ALERT_TS, (int) current_time( 'timestamp' ), false );

		if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'insert_activity' ) ) {
			Nexus_DB::insert_activity( 0, 'automation', sprintf( 'Redazione ferma: nessun articolo prodotto nelle ultime %d ore.', $hours ), 'warning' );
		}

		if ( class_exists( 'Nexus_Notify' ) && method_exists( 'Nexus_Notify', 'send_generic' ) ) {
			Nexus_Notify::send_generic( 'error', sprintf( 'Redazione ferma: nessun articolo prodotto nelle ultime %d ore.', $hours ) );
		}

		return array( 'stalled' => true );
	}

	/**
	 * Digest giornaliero di attività (via Nexus_Notify::send_generic).
	 *
	 * @return bool
	 */
	public static function run_daily() {
		$activities = 0;
		if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'count_articles' ) ) {
			$today = gmdate( 'Y-m-d 00:00:00' );
			$activities = (int) Nexus_DB::count_articles( array( 'after' => $today ) );
		}

		$msg = sprintf( 'Riepilogo giornaliero: %d articoli prodotti oggi.', $activities );

		if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'insert_activity' ) ) {
			Nexus_DB::insert_activity( 0, 'automation', $msg, 'info' );
		}

		if ( class_exists( 'Nexus_Notify' ) && method_exists( 'Nexus_Notify', 'send_generic' ) ) {
			Nexus_Notify::send_generic( 'pending', $msg );
		}

		return true;
	}

	/**
	 * Differenza temporale leggibile (secondi → etichette).
	 *
	 * @param int $sec Secondi.
	 * @return string
	 */
	private static function human_diff( $sec ) {
		$sec = max( 0, (int) $sec );
		if ( $sec < 60 ) {
			return $sec . 's';
		}
		if ( $sec < 3600 ) {
			return floor( $sec / 60 ) . 'm';
		}
		return floor( $sec / 3600 ) . 'h';
	}

	/**
	 * Pulizia cron alla disattivazione.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::HOOK_DAILY );
		wp_clear_scheduled_hook( self::HOOK_STALL_CHECK );
	}

	/* ==================================================================
	 * REST (admin-only) — cruscotto automazione (B4)
	 * ================================================================ */

	/**
	 * Registra le route admin GET /automation/status e POST /automation/retry.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'nexus/v1',
			'/automation/status',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'rest_status' ),
					'permission_callback' => array( __CLASS__, 'perm_admin' ),
				),
			)
		);

		register_rest_route(
			'nexus/v1',
			'/automation/retry',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'rest_retry' ),
					'permission_callback' => array( __CLASS__, 'perm_admin' ),
					'args'                => array(
						'max_attempts' => array(
							'type'              => 'integer',
							'required'          => false,
							'default'           => 3,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Permesso admin: nonce REST + manage_options.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return true|WP_Error
	 */
	public static function perm_admin( $request ) {
		$nonce = ( $request instanceof \WP_REST_Request ) ? (string) $request->get_header( 'X-WP-Nonce' ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'nexus_automation_bad_nonce', 'Nonce REST mancante o non valido.', array( 'status' => 403 ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'nexus_automation_forbidden', 'Non hai i permessi per questa risorsa.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * GET /automation/status.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_status() {
		return rest_ensure_response( self::tick_status() );
	}

	/**
	 * POST /automation/retry — lancia il retry dei job falliti.
	 *
	 * @param WP_REST_Request $request Richiesta.
	 * @return WP_REST_Response
	 */
	public static function rest_retry( $request ) {
		$max = min( 10, max( 1, absint( $request->get_param( 'max_attempts' ) ) ) );
		$res = self::retry_failed_jobs( $max );
		return rest_ensure_response( array_merge( array( 'ok' => true ), $res ) );
	}
}

// Self-init (bootstrap non toccato).
add_action( 'nexus_modules_init', array( 'Nexus_Automation', 'init' ) );
