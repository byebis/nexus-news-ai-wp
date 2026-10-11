<?php
/**
 * Nexus News AI — Disinstallazione.
 *
 * Eseguito SOLO quando il plugin viene cancellato dalla pagina Plugin di
 * WordPress (WP_UNINSTALL_PLUGIN definito). La semplice *disattivazione*
 * NON passa da qui: tabelle, articoli e impostazioni restano al loro posto.
 *
 * Attenzione: uninstall.php gira senza il bootstrap del plugin (nessun
 * plugins_loaded), quindi NEXUS_PLUGIN_DIR non esiste. Il path della classe
 * Nexus_DB viene calcolato dal basename del plugin (WP_UNINSTALL_PLUGIN)
 * con fallback robusto su __DIR__; se la classe non è caricabile si passa
 * alle query DROP TABLE dirette via $wpdb.
 *
 * @package Nexus_News_AI
 */

// Esci se non siamo in un contesto di disinstallazione valido
// (WP_UNINSTALL_PLUGIN definito e coincidente col basename di questo plugin).
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || 'nexus-news-ai/nexus-news-ai.php' !== WP_UNINSTALL_PLUGIN ) {
        exit;
}

/**
 * Elimina le opzioni del plugin.
 *
 * @return void
 */
function nexus_uninstall_delete_options() {
        delete_option( 'nexus_settings' );
        delete_option( 'nexus_db_version' );
        delete_option( 'nexus_wire_runs' );
}

/**
 * Elimina i transients di cache RSS (nexus_rss_*) e le voci di scadenza.
 *
 * @return void
 */
function nexus_uninstall_delete_transients() {
        global $wpdb;
        $like_transient = $wpdb->esc_like( '_transient_nexus_rss_' ) . '%';
        $like_timeout   = $wpdb->esc_like( '_transient_timeout_nexus_rss_' ) . '%';
        $wpdb->query(
                $wpdb->prepare(
                        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                        $like_transient,
                        $like_timeout
                )
        );
}

/**
 * Drop diretto delle 5 tabelle wp_nexus_* (fallback se Nexus_DB non caricabile).
 *
 * @return void
 */
function nexus_uninstall_drop_tables_direct() {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $tables = array(
                $prefix . 'nexus_agents',
                $prefix . 'nexus_articles',
                $prefix . 'nexus_publish_logs',
                $prefix . 'nexus_approval_logs',
                $prefix . 'nexus_activity_logs',
        );
        foreach ( $tables as $table ) {
                $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
}

/* ---------------- 1) Try via Nexus_DB ---------------- */

$candidates = array();
$loaded     = false;

if ( defined( 'WP_PLUGIN_DIR' ) ) {
        $candidates[] = WP_PLUGIN_DIR . '/' . dirname( WP_UNINSTALL_PLUGIN ) . '/includes/class-nexus-db.php';
}
// Il file uninstall.php vive nella root del plugin: __DIR__ è il path più affidabile.
$candidates[] = __DIR__ . '/includes/class-nexus-db.php';

foreach ( array_unique( $candidates ) as $db_class_file ) {
        if ( file_exists( $db_class_file ) ) {
                require_once $db_class_file;
                $loaded = true;
                break;
        }
}

if ( $loaded && class_exists( 'Nexus_DB' ) ) {
        // Nexus_DB::drop_tables() fa il drop delle 5 tabelle wp_nexus_* e cancella
        // le opzioni nexus_settings, nexus_db_version e nexus_wire_runs.
        Nexus_DB::drop_tables();

        // I transients non sono gestiti dalla classe: li rimuoviamo qui.
        nexus_uninstall_delete_transients();
} else {
        /* ---------------- 2) Fallback: query dirette ---------------- */

        nexus_uninstall_drop_tables_direct();
        nexus_uninstall_delete_options();
        nexus_uninstall_delete_transients();
}
