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
 * Dalla v1.0.1 rimuove anche i post WordPress reali del CPT nexus_article
 * (wp_delete_post con force_delete, che elimina post e meta associati) e i
 * meta Nexus residui sui post (_nexus_article_id, _nexus_seo_title,
 * _nexus_seo_description) via delete_post_meta_by_key.
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
 * Elimina tutti i post WordPress reali del CPT nexus_article (v1.0.1)
 * e i meta Nexus correlati.
 *
 * wp_delete_post() con force_delete=true bypassa il cestino e rimuove
 * il post insieme ai suoi meta. delete_post_meta_by_key() viene poi
 * eseguita comunque: è sicura anche a post già eliminati (meta orfani).
 *
 * @return void
 */
function nexus_uninstall_delete_wp_posts() {
        global $wpdb;

        // Tutti i post del CPT nexus_article: cancellazione definitiva.
        $post_ids = $wpdb->get_col(
                $wpdb->prepare(
                        "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
                        'nexus_article'
                )
        );

        if ( ! empty( $post_ids ) ) {
                foreach ( $post_ids as $post_id ) {
                        wp_delete_post( (int) $post_id, true ); // force_delete: bypassa il cestino.
                }
        }

        // Meta Nexus sui post (residui o orfani): pulizia per chiave.
        delete_post_meta_by_key( '_nexus_article_id' );
        delete_post_meta_by_key( '_nexus_seo_title' );
        delete_post_meta_by_key( '_nexus_seo_description' );
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

/* ---------------- 0) Post WordPress reali (CPT nexus_article, v1.0.1) ---------------- */

nexus_uninstall_delete_wp_posts();

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
