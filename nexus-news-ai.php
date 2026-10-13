<?php
/**
 * Plugin Name: Nexus News AI
 * Plugin URI:  https://github.com/byebis/nexus-news-ai
 * Description: Il giornale di nuova generazione, scritto da intelligenze artificiali. Crea la tua redazione con il wizard di onboarding: agenti giornalisti AI con preset one-click (zero agenti predefiniti) e pipeline editoriale completa: raccolta RSS, valutazione, riscrittura AI, approvazione e pubblicazione multi-piattaforma. Include admin panel, Nexus Wire (redazione collettiva), shortcode magazine e cron automatico.
 * Version:     1.1.10.1
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

define( 'NEXUS_VERSION', '1.1.10.1' );
define( 'NEXUS_PLUGIN_FILE', __FILE__ );
define( 'NEXUS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NEXUS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Caricamento classi core.
 */
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-db.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-utils.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-rss.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-providers.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-ai-engine.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-publisher.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-pipeline.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-tools.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-chief.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-cron.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-rest.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-cpt.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-native.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-redazioni.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-caps.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-multilang.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-image.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-seo.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-social-telegram.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-social-discord.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-social-x.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-social-linkedin.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-newsletter.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-ping.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-translate.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-queue.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-analytics.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-blocks.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-cli.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-i18n.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-public-api.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-multisite.php';
require_once NEXUS_PLUGIN_DIR . 'public/class-nexus-shortcodes.php';

register_activation_hook( __FILE__, array( 'Nexus_DB', 'install' ) );
register_deactivation_hook( __FILE__, 'nexus_deactivate' );

/**
 * Disattivazione: pulizia eventi cron del plugin (pipeline, digest, ping).
 */
function nexus_deactivate() {
        Nexus_Cron::deactivate();
        if ( class_exists( 'Nexus_Ping' ) && method_exists( 'Nexus_Ping', 'deactivate' ) ) {
                Nexus_Ping::deactivate();
        }
        wp_clear_scheduled_hook( 'nexus_weekly_digest' );
}

/**
 * Attivazione v1.0.1: dopo l'installazione registra il CPT nexus_article
 * e ripulisce le rewrite rules, così i permalink /nexus/{slug}/ funzionano subito.
 * v1.1.0: registra anche il ruolo "Giornalista AI" per gli utenti bot degli agenti.
 * v1.1.1: registra la tassonomia gerarchica delle redazioni prima del flush.
 */
function nexus_activate() {
        Nexus_Cpt::register_cpt();
        Nexus_Cpt::register_taxonomies();
        Nexus_Cpt::register_meta();
        Nexus_Native::register_role();
        if ( class_exists( 'Nexus_Redazioni' ) ) {
                Nexus_Redazioni::register_taxonomy();
        }
        flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'nexus_activate' );

/**
 * Bootstrap: inizializza i moduli dopo il caricamento di WordPress.
 */
function nexus_bootstrap() {
        Nexus_DB::maybe_upgrade();
        Nexus_Rest::init();
        Nexus_Shortcodes::init();
        Nexus_Cron::init();
        Nexus_Cpt::init();
        Nexus_Native::init();
        Nexus_Redazioni::init();
        Nexus_Caps::init();
        Nexus_SEO::init();
        Nexus_Newsletter::init();
        Nexus_Ping::init();
        Nexus_Translate::init();
        Nexus_Multilang::init();
        Nexus_Publisher::init();
        Nexus_Queue::init();

        // v1.1.4: Capo Redattore AI (registra l'handler coda nexus_chief_review).
        if ( class_exists( 'Nexus_Chief' ) && method_exists( 'Nexus_Chief', 'init' ) ) {
                Nexus_Chief::init();
        }

        Nexus_Blocks::init();
        Nexus_I18n::init();
        Nexus_Public_API::init();
        Nexus_Multisite::init();

        if ( is_admin() ) {
                require_once NEXUS_PLUGIN_DIR . 'admin/class-nexus-admin.php';
                Nexus_Admin::init();
        }
}
add_action( 'plugins_loaded', 'nexus_bootstrap' );
