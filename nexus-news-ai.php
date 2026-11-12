<?php
/**
 * Plugin Name: Nexus News AI
 * Plugin URI:  https://github.com/byebis/nexus-news-ai
 * Description: Il giornale di nuova generazione, scritto da intelligenze artificiali. Crea la tua redazione con il wizard di onboarding: agenti giornalisti AI con preset one-click (zero agenti predefiniti) e pipeline editoriale completa: raccolta RSS, valutazione, riscrittura AI, approvazione e pubblicazione multi-piattaforma. Include admin panel, Nexus Wire (redazione collettiva), shortcode magazine e cron automatico.
 * Version:     1.1.31
 * Author:      ai4wp
 * Author URI:  https://github.com/byebis
 * License:     MIT
 * Text Domain: nexus-news-ai
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

// impedisci accesso diretto
defined( 'ABSPATH' ) || exit;

define( 'NEXUS_VERSION', '1.1.31' );
define( 'NEXUS_PLUGIN_FILE', __FILE__ );
define( 'NEXUS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NEXUS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoloader del plugin (v1.1.21, perf/nexus-autoload).
 *
 * Sostituisce la lista manuale di require_once con una mappa PSR-4-ish:
 * Nexus_* ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ includes/, public/, admin/, vendor-shot-ui/. La classe viene caricata
 * al primo utilizzo; il bootstrap (nexus_bootstrap) "riscalda" esplicitamente lo
 * stesso insieme di classi di prima per preservare gli effetti collaterali a
 * livello di file (es. l'auto-registrazione dei moduli su 'nexus_modules_init').
 *
 * @param string $class Nome classe completo.
 * @return void
 */
function nexus_autoload( $class ) {
        if ( 'Shot_UI' !== $class && 0 !== strpos( $class, 'Nexus_' ) ) {
                return;
        }

        if ( 'Shot_UI' === $class ) {
                $rel = 'class-1shot-ui.php';
        } else {
                // 'Nexus_' = 6 caratteri.
                $rel = 'class-nexus-' . str_replace( '_', '-', strtolower( substr( $class, 6 ) ) ) . '.php';
        }

        $dirs = array(
                NEXUS_PLUGIN_DIR . 'includes/',
                NEXUS_PLUGIN_DIR . 'public/',
                NEXUS_PLUGIN_DIR . 'admin/',
                NEXUS_PLUGIN_DIR . 'vendor-shot-ui/',
        );

        foreach ( $dirs as $dir ) {
                $file = $dir . $rel;
                if ( file_exists( $file ) ) {
                        require_once $file;
                        return;
                }
        }
}
spl_autoload_register( 'nexus_autoload' );

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
        // (pattern WP): senza flush le regole /nexus/ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¦ e le sitemap restano
        // nell'option rewrite_rules con il plugin inattivo (soft-404) fino al
        // prossimo flush naturale.
        flush_rewrite_rules();
}

/**
 * Attivazione v1.0.1: dopo l'installazione registra il CPT nexus_article
 * e ripulisce le rewrite rules, cosÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ i permalink /nexus/{slug}/ funzionano subito.
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
        // v1.1.21 (perf/nexus-autoload): riscalda lo stesso insieme di classi
        // dell'ex elenco require_once, cosÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ gli effetti collaterali a livello di
        // file (auto-registrazione dei moduli su 'nexus_modules_init') si
        // preservano identici. class_exists() attiva l'autoloader.
        foreach ( array(
                'Nexus_DB', 'Nexus_Utils', 'Nexus_Rss', 'Nexus_Providers', 'Nexus_AI_Engine',
                'Nexus_Publisher', 'Nexus_Pipeline', 'Nexus_Tools', 'Nexus_Chief', 'Nexus_Cron',
                'Nexus_Rest', 'Nexus_Cpt', 'Nexus_Native', 'Nexus_Redazioni', 'Nexus_Caps',
                'Nexus_Multilang', 'Nexus_Image', 'Nexus_Readability', 'Nexus_Sources', 'Nexus_SEO',
                'Nexus_Social_Telegram', 'Nexus_Social_Discord', 'Nexus_Social_X', 'Nexus_Social_Linkedin',
                'Nexus_Newsletter', 'Nexus_Ping', 'Nexus_Translate', 'Nexus_Queue', 'Nexus_Analytics',
                'Nexus_Blocks', 'Nexus_Cli', 'Nexus_I18n', 'Nexus_Public_API', 'Nexus_Multisite',
                'Nexus_Google_Auth', 'Nexus_Metrics', 'Nexus_Learn', 'Nexus_Dedup', 'Nexus_Interlink',
                'Nexus_News_Sitemap', 'Nexus_Evergreen', 'Nexus_Notify', 'Nexus_Google_Wizard',
                'Nexus_Health', 'Nexus_Opportunities', 'Nexus_Calendar', 'Nexus_Shortcodes',
                'Nexus_License',
                'Nexus_Ingest',
                'Nexus_MCP',
        ) as $nxa_class ) {
                class_exists( $nxa_class );
        }

        // v1.1.28 (Freemius): registra il gate licenza su ogni richiesta
        // (il filtro 'nexus_is_pro' deve essere attivo anche sulle REST).
        Nexus_License::init();

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
                require_once NEXUS_PLUGIN_DIR . 'vendor-shot-ui/class-1shot-ui.php';
                require_once NEXUS_PLUGIN_DIR . 'vendor-shot-ui/class-1shot-suite.php';
                \Shot_UI::init( array( 'plugin_slug' => 'nexus-news-ai' ) );

                require_once NEXUS_PLUGIN_DIR . 'admin/class-nexus-admin.php';
                Nexus_Admin::init();
        }
}
add_action( 'plugins_loaded', 'nexus_bootstrap' );

/**
 * v1.1.18 (FIX-8): carica il text domain dichiarato negli header del plugin
 * (Text Domain: nexus-news-ai, Domain Path: /languages ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â dove vive il .pot).
 * L'hook corretto ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¨ init: load_plugin_textdomain() non va chiamato prima.
 * La localizzazione REALE della UI resta il dict custom Nexus_I18n (IT
 * sorgente ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ EN), ma senza questa chiamata eventuali .mo (es. generati da
 * translate.wordpress.org o iniettati via filtro 'load_textdomain_mofile')
 * non venivano MAI caricati e le __()/esc_html__() delle viste restavano in
 * italiano. Innocuo oggi (nessun .mo distribuito): chiude l'incoerenza
 * header/meccanismo (audit A8, finding M2). La path usa la costante
 * NEXUS_PLUGIN_FILE (il file principale, non __FILE__ locale) come giÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â 
 * fatto per le altre costanti del bootstrap.
 */
function nexus_load_textdomain() {
        load_plugin_textdomain( 'nexus-news-ai', false, dirname( plugin_basename( NEXUS_PLUGIN_FILE ) ) . '/languages' );
}
add_action( 'init', 'nexus_load_textdomain' );
