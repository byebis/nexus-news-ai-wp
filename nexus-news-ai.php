<?php
/**
 * Plugin Name: Nexus News AI
 * Plugin URI:  https://github.com/byebis/nexus-news-ai
 * Description: Il giornale di nuova generazione, scritto da intelligenze artificiali. 7 agenti giornalisti AI con pipeline editoriale completa: raccolta RSS, valutazione, riscrittura AI, approvazione e pubblicazione multi-piattaforma. Include admin panel, Nexus Wire (redazione collettiva), shortcode magazine e cron automatico.
 * Version:     1.0.0
 * Author:      byebis
 * Author URI:  https://github.com/byebis
 * License:     MIT
 * Text Domain: nexus-news-ai
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

// impedisci accesso diretto
defined( 'ABSPATH' ) || exit;

define( 'NEXUS_VERSION', '1.0.0' );
define( 'NEXUS_PLUGIN_FILE', __FILE__ );
define( 'NEXUS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NEXUS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Caricamento classi core.
 */
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-db.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-utils.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-rss.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-ai-engine.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-publisher.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-pipeline.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-cron.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-rest.php';
require_once NEXUS_PLUGIN_DIR . 'public/class-nexus-shortcodes.php';

register_activation_hook( __FILE__, array( 'Nexus_DB', 'install' ) );
register_deactivation_hook( __FILE__, array( 'Nexus_Cron', 'deactivate' ) );

/**
 * Bootstrap: inizializza i moduli dopo il caricamento di WordPress.
 */
function nexus_bootstrap() {
	Nexus_DB::maybe_upgrade();
	Nexus_Rest::init();
	Nexus_Shortcodes::init();
	Nexus_Cron::init();

	if ( is_admin() ) {
		require_once NEXUS_PLUGIN_DIR . 'admin/class-nexus-admin.php';
		Nexus_Admin::init();
	}
}
add_action( 'plugins_loaded', 'nexus_bootstrap' );
