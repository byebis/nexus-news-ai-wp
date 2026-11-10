<?php
/**
 * Gestione licenza Freemium (Freemius) — v1.1.28.
 *
 * Bridge canonico verso l'SDK Freemius bundled in freemius/start.php.
 * Contratto di design (pattern keeper-ai/Visibility-AI):
 *   - Il plugin DEVE funzionare sempre senza credenziali Freemius: fs()
 *     ritorna null, ogni metodo degrada silenziosamente, nessun fatal.
 *   - Le credenziali del prodotto (id + chiave pubblica) sono dati di
 *     distribuzione; la chiave segreta non entra mai nel plugin. Si
 *     configurano via costanti (wp-config.php) o filtri:
 *       NEXUS_FS_ID / NEXUS_FS_PUBLIC_KEY (costanti, precedenza massima)
 *       nexus_fs_id / nexus_fs_public_key              (filtri)
 *   - Stato non configurato (fs() null) = tutto Free, fail-closed.
 *   - Il gate unico è Nexus_License::is_pro(); il filtro 'nexus_is_pro'
 *     è il punto di contatto per la UI (window.nexusLicense).
 *
 * @package Nexus_News_AI
 * @since   1.1.28
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gate unico licenza (Freemius).
 */
final class Nexus_License {

	/**
	 * Id prodotto Freemius (default 0 = non configurato → Free).
	 * Sovrascrivibile con NEXUS_FS_ID o filtro nexus_fs_id.
	 *
	 * @var int
	 */
	const DEFAULT_ID = 0;

	/**
	 * Chiave pubblica prodotto Freemius (default '' = non configurato).
	 * Sovrascrivibile con NEXUS_FS_PUBLIC_KEY o filtro nexus_fs_public_key.
	 *
	 * @var string
	 */
	const DEFAULT_PUBLIC_KEY = '';

	/** @var \Freemius|null Istanza SDK in cache (null = non inizializzata/non disponibile). */
	private static $fs = null;

	/** @var bool True dopo il primo tentativo di init (successo o fallimento). */
	private static $tried = false;

	/** @var bool|null Risultato in cache di sdk_available(). */
	private static $sdk_available = null;

	/** @var bool|null Test seam: forza il verdetto Pro (null = risoluzione normale). */
	public static $pro_override = null;

	/**
	 * URL presente dell'SDK bundled.
	 *
	 * @return bool
	 */
	public static function sdk_available() {
		if ( null === self::$sdk_available ) {
			self::$sdk_available = file_exists( NEXUS_PLUGIN_DIR . 'freemius/start.php' );
		}
		return self::$sdk_available;
	}

	/**
	 * Configurazione prodotto Freemius. Risolve in ordine di priorità:
	 * $pro_override (test) → costanti → filtri → default (non configurato).
	 *
	 * @return array {id:int, public_key:string, slug:string, type:string}
	 */
	public static function config() {
		if ( defined( 'NEXUS_FS_ID' ) ) {
			$id = (int) constant( 'NEXUS_FS_ID' );
		} else {
			$id = (int) apply_filters( 'nexus_fs_id', self::DEFAULT_ID );
		}

		if ( defined( 'NEXUS_FS_PUBLIC_KEY' ) ) {
			$public_key = (string) constant( 'NEXUS_FS_PUBLIC_KEY' );
		} else {
			$public_key = (string) apply_filters( 'nexus_fs_public_key', self::DEFAULT_PUBLIC_KEY );
		}

		return array(
			'id'         => $id,
			'public_key' => $public_key,
			'slug'       => 'nexus-news-ai',
			'type'       => 'plugin',
		);
	}

	/**
	 * Accessor lazy dell'SDK. Ritorna l'istanza Freemius o null quando
	 * l'SDK è assente, non configurato o non inizializzato. Mai throw.
	 *
	 * @return \Freemius|null
	 */
	public static function fs() {
		if ( self::$tried ) {
			return self::$fs;
		}
		self::$tried = true;

		if ( ! self::sdk_available() ) {
			return self::$fs = null;
		}

		$config = self::config();
		if ( empty( $config['id'] ) || '' === (string) $config['public_key'] ) {
			// Configurazione incompleta: resta Free, non caricare l'SDK.
			return self::$fs = null;
		}

		try {
			if ( ! function_exists( 'fs_dynamic_init' ) ) {
				require_once NEXUS_PLUGIN_DIR . 'freemius/start.php';
			}
		} catch ( \Throwable $e ) {
			return self::$fs = null;
		}

		if ( ! function_exists( 'fs_dynamic_init' ) || ! class_exists( 'Freemius', false ) ) {
			return self::$fs = null;
		}

		try {
			$instance = fs_dynamic_init(
				array(
					'id'                  => (int) $config['id'],
					'slug'                => 'nexus-news-ai',
					'type'                => 'plugin',
					'public_key'          => (string) $config['public_key'],
					'is_premium'          => true,
					'premium_suffix'      => ' (Pro)',
					'has_premium_version' => true,
					'has_addons'          => false,
					'has_paid_plans'      => true,
					'is_live'             => true,
					'is_org_compliant'    => true,
					// Il plugin gestisce il proprio menu; Freemius aggiunge
					// le pagine account/checkout ma non hijacka la top-level.
					'menu'                => array(
						'slug'           => 'nexus-news',
						'override_exact' => true,
						'account'        => true,
						'contact'        => false,
						'support'        => false,
					),
				)
			);
			self::$fs = ( is_object( $instance ) && $instance instanceof \Freemius ) ? $instance : null;
		} catch ( \Throwable $e ) {
			self::$fs = null;
		}

		return self::$fs;
	}

	/**
	 * Verdetto Pro (gate unico). Supporta:
	 *   - NEXUS_DEV_PRO = true (solo ambiente local/development) per dev/test;
	 *   - filtro 'nexus_license_is_pro' (bridge SDK, allarga la decisione);
	 *   - altrimenti false.
	 *
	 * @return bool
	 */
	public static function is_pro() {
		if ( null !== self::$pro_override ) {
			return (bool) self::$pro_override;
		}

		// Dev backdoor legata all'ambiente: solo local/development (o
		// contesto non-WP, che è sviluppo).
		if ( defined( 'NEXUS_DEV_PRO' ) && NEXUS_DEV_PRO ) {
			$env = function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : 'development';
			if ( 'local' === $env || 'development' === $env ) {
				return true;
			}
			return false;
		}

		return (bool) apply_filters( 'nexus_license_is_pro', false );
	}

	/**
	 * Bridge del filtro 'nexus_is_pro' (consumato da Nexus_Admin::license_state()).
	 *
	 * @return bool
	 */
	public static function filter_is_pro() {
		return self::is_pro();
	}

	/**
	 * Nome piano dall'SDK ('' quando sconosciuto).
	 *
	 * @return string
	 */
	public static function plan_name() {
		$fs = self::fs();
		if ( $fs && method_exists( $fs, 'get_plan_name' ) ) {
			try {
				return (string) $fs->get_plan_name();
			} catch ( \Throwable $e ) {
				return '';
			}
		}
		return '';
	}

	/**
	 * URL upgrade/checkout dall'SDK ('' quando non disponibile).
	 *
	 * @return string
	 */
	public static function upgrade_url() {
		$fs = self::fs();
		if ( $fs && method_exists( $fs, 'get_upgrade_url' ) ) {
			try {
				return (string) $fs->get_upgrade_url();
			} catch ( \Throwable $e ) {
				return '';
			}
		}
		return '';
	}

	/**
	 * Punto di ingresso del coordinatore (nexus_bootstrap). Registra il
	 * filtro 'nexus_is_pro' su ogni richiesta (incluse REST). Completamente
	 * guarded: sicuro in ogni contesto.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'nexus_is_pro', array( __CLASS__, 'filter_is_pro' ) );
		add_filter( 'nexus_upgrade_url', array( __CLASS__, 'upgrade_url' ) );
	}
}
