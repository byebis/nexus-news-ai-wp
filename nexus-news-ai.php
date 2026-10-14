<?php
/**
 * Plugin Name: Nexus News AI
 * Plugin URI:  https://github.com/byebis/nexus-news-ai
 * Description: Il giornale di nuova generazione, scritto da intelligenze artificiali. Crea la tua redazione con il wizard di onboarding: agenti giornalisti AI con preset one-click (zero agenti predefiniti) e pipeline editoriale completa: raccolta RSS, valutazione, riscrittura AI, approvazione e pubblicazione multi-piattaforma. Include admin panel, Nexus Wire (redazione collettiva), shortcode magazine e cron automatico.
 * Version:     1.1.17
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

define( 'NEXUS_VERSION', '1.1.17' );
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
// v1.1.15 (WB1): Nexus_Readability non era mai richiesto dal bootstrap:
// senza questa riga le fonti full=1 ("contenuto completo") restano dead code
// (guardie/estrazione usate dal dispatcher fonti in class-nexus-sources.php).
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-readability.php';
// v1.1.15 (contratto WB): Nexus_Sources non era mai richiesto dal bootstrap
// (nessun autoloader nel repo): class_exists('Nexus_Sources') era sempre
// false e il dispatcher multi-fonte degradava sempre su Nexus_Rss —
// fetch_web/maybe_full_content restavano dead code alla radice. Sicuro solo
// DOPO l'inversione della guardia binario/SVG di fetch_web (stessa release).
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-sources.php';
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
// v1.1.12 — pacchetti feedback/SEO (S1-S7): file nuovi, init self-registrato
// via action 'nexus_modules_init' (nessun'altra modifica al bootstrap richiesta).
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-google-auth.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-metrics.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-learn.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-dedup.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-interlink.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-news-sitemap.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-evergreen.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-notify.php';
// v1.1.14 — pacchetti redazione che impara/organizzazione (P1-P6): file nuovi,
// init self-registrato via action 'nexus_modules_init' (stesso pattern 1.1.12).
// NB: class-nexus-digest.php è richiesto da class-nexus-newsletter.php,
// class-nexus-video-sitemap.php da class-nexus-news-sitemap.php.
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-google-wizard.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-health.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-opportunities.php';
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-calendar.php';
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
        // v1.1.12: cron dei moduli "redazione che impara" (sync metriche GSC/GA4,
        // refresh briefing learning, refresh evergreen settimanale).
        if ( class_exists( 'Nexus_Metrics' ) && method_exists( 'Nexus_Metrics', 'deactivate' ) ) {
                Nexus_Metrics::deactivate();
        }
        if ( class_exists( 'Nexus_Learn' ) && method_exists( 'Nexus_Learn', 'deactivate' ) ) {
                Nexus_Learn::deactivate();
        }
        if ( class_exists( 'Nexus_Evergreen' ) && method_exists( 'Nexus_Evergreen', 'deactivate' ) ) {
                Nexus_Evergreen::deactivate();
        }
        // v1.1.13: cron retention (pulizia logs/metrics/transient) del modulo DB.
        if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'deactivate_retention' ) ) {
                Nexus_DB::deactivate_retention();
        }
        wp_clear_scheduled_hook( 'nexus_weekly_digest' );

        // v1.1.15 (WB4): flush canonico delle rewrite rules alla disattivazione
        // (pattern WP): senza flush le regole /nexus/… e le sitemap restano
        // nell'option rewrite_rules con il plugin inattivo (soft-404) fino al
        // prossimo flush naturale.
        flush_rewrite_rules();
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

        // v1.1.12: i pacchetti S1-S7 si auto-inizializzano qui (ogni classe
        // fa add_action('nexus_modules_init', ...) nel proprio file).
        do_action( 'nexus_modules_init' );

        if ( is_admin() ) {
                require_once NEXUS_PLUGIN_DIR . 'admin/class-nexus-admin.php';
                Nexus_Admin::init();
        }
}
add_action( 'plugins_loaded', 'nexus_bootstrap' );
