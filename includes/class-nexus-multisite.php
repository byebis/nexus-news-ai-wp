<?php
/**
 * Nexus Multisite — gestione multisite del plugin (v1.0.5).
 *
 * Contratto pubblico (CONTRATTO v1.0.5, sezione §2 T-05):
 *
 * - init(): su installazioni multisite aggancia l'hook wp_initialize_site
 *   (priorità 20, WP 5.1+; WordPress 6.0 è il minimo del plugin) per
 *   installare automaticamente le tabelle Nexus sui NUOVI siti della rete.
 * - on_new_site( $site ): estrae il blog_id dall'oggetto sito e lancia
 *   install_on_site() in try/catch Throwable; log su error_log SOLO se WP_DEBUG.
 * - install_on_site( $blog_id ): switch_to_blog → Nexus_DB::install()
 *   (tabelle + seed agenti + impostazioni di default) → restore_current_blog;
 *   ritorna true in caso di successo, false altrimenti.
 * - status(): una riga per sito della rete (max 500): blog_id, URL, tabelle
 *   presenti "n/9" (SHOW TABLES LIKE per ognuna di Nexus_DB::tables()), numero
 *   agenti e presenza impostazioni (get_option('nexus_settings')).
 * - install_all( $blog_ids ): installa tabelle+seed su una lista di siti
 *   (lista vuota = TUTTI i siti della rete); ritorna array con 'installed',
 *   'failed' e 'details' (una voce per sito).
 *
 * Note:
 * - Ogni sito della rete ha tabelle e impostazioni PROPRIE: i nomi tabella
 *   derivano da $wpdb->prefix del sito corrente (grazie a switch_to_blog),
 *   quindi Nexus_DB::install() opera sempre sul sito selezionato.
 * - Dipendenze usate con guard class_exists/method_exists (mai fatale):
 *   Nexus_DB.
 * - Solo API core WordPress: get_sites, switch_to_blog, restore_current_blog,
 *   get_home_url, get_option. Nessuna dipendenza esterna.
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Multisite {

        /**
         * Registra l'hook di inizializzazione dei nuovi siti (solo multisite).
         *
         * @return void
         */
        public static function init() {
                if ( ! is_multisite() ) {
                        return;
                }

                add_action( 'wp_initialize_site', array( __CLASS__, 'on_new_site' ), 20 );
        }

        /**
         * Hook wp_initialize_site: installa le tabelle Nexus su un sito appena creato.
         *
         * @param mixed $site Oggetto sito (WP_Site) passato dall'hook.
         * @return void
         */
        public static function on_new_site( $site ) {
                $blog_id = is_object( $site ) && property_exists( $site, 'blog_id' ) ? (int) $site->blog_id : 0;

                if ( $blog_id < 1 ) {
                        return;
                }

                try {
                        self::install_on_site( $blog_id );
                } catch ( Throwable $e ) {
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                                error_log( sprintf( 'Nexus Multisite: installazione automatica fallita sul nuovo sito #%d: %s', $blog_id, $e->getMessage() ) );
                        }
                }
        }

        /**
         * Installa tabelle + seed del plugin su UN sito della rete.
         *
         * @param int $blog_id ID del sito su cui installare.
         * @return bool True se l'installazione è riuscita, false altrimenti.
         */
        public static function install_on_site( $blog_id ) {
                $blog_id = absint( $blog_id );

                if ( $blog_id < 1 ) {
                        return false;
                }

                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'install' ) ) {
                        return false;
                }

                $switched = false;

                try {
                        $switched = switch_to_blog( $blog_id );

                        if ( ! $switched ) {
                                return false;
                        }

                        Nexus_DB::install();

                        restore_current_blog();
                        $switched = false;

                        return true;
                } catch ( Throwable $e ) {
                        if ( $switched ) {
                                restore_current_blog();
                        }

                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                                error_log( sprintf( 'Nexus Multisite: installazione tabelle fallita sul sito #%d: %s', $blog_id, $e->getMessage() ) );
                        }

                        return false;
                }
        }

        /**
         * Stato del plugin sito per sito (max 500 siti della rete).
         *
         * Per ogni sito riporta: blog_id, URL (letto PRIMA dello switch), tabelle
         * presenti "n/9" (SHOW TABLES LIKE per ognuna di Nexus_DB::tables()),
         * numero agenti e presenza impostazioni (get_option('nexus_settings')).
         *
         * @return array Elenco di righe; array() vuoto fuori dal multisite.
         */
        public static function status() {
                if ( ! is_multisite() ) {
                        return array();
                }

                $rows = array();

                foreach ( (array) get_sites( array( 'number' => 500 ) ) as $site ) {
                        $blog_id = is_object( $site ) && property_exists( $site, 'blog_id' ) ? (int) $site->blog_id : 0;

                        if ( $blog_id < 1 ) {
                                continue;
                        }

                        /* URL letto PRIMA dello switch al sito. */
                        $url = get_home_url( $blog_id );

                        $tables_present = 0;
                        $tables_total   = 0;
                        $agents_count   = 0;
                        $settings_ok    = false;

                        $switched = switch_to_blog( $blog_id );

                        if ( $switched ) {
                                if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'tables' ) ) {
                                        global $wpdb;

                                        foreach ( (array) Nexus_DB::tables() as $table ) {
                                                $tables_total++;
                                                $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

                                                if ( $found === $table ) {
                                                        $tables_present++;
                                                }
                                        }

                                        if ( method_exists( 'Nexus_DB', 'agents' ) ) {
                                                $agents_count = count( (array) Nexus_DB::agents() );
                                        }
                                }

                                $settings_ok = ( false !== get_option( 'nexus_settings' ) );

                                restore_current_blog();
                        }

                        $rows[] = array(
                                'blog_id'  => $blog_id,
                                'url'      => $url,
                                'tables'   => $tables_present . '/' . $tables_total,
                                'agents'   => $agents_count,
                                'settings' => $settings_ok,
                        );
                }

                return $rows;
        }

        /**
         * Installa tabelle + seed su più siti della rete.
         *
         * @param array $blog_ids ID dei siti; lista vuota = TUTTI i siti della rete.
         * @return array array('installed' => n, 'failed' => n, 'details' => righe per sito).
         */
        public static function install_all( $blog_ids = array() ) {
                $ids = array();

                foreach ( (array) $blog_ids as $blog_id ) {
                        $id = absint( $blog_id );

                        if ( $id > 0 ) {
                                $ids[ $id ] = $id;
                        }
                }

                if ( empty( $ids ) ) {
                        foreach ( (array) get_sites( array( 'number' => 500 ) ) as $site ) {
                                $id = is_object( $site ) && property_exists( $site, 'blog_id' ) ? (int) $site->blog_id : 0;

                                if ( $id > 0 ) {
                                        $ids[ $id ] = $id;
                                }
                        }
                }

                $installed = 0;
                $failed    = 0;
                $details   = array();

                foreach ( $ids as $id ) {
                        $url = get_home_url( $id );
                        $ok  = self::install_on_site( $id );

                        if ( $ok ) {
                                $installed++;
                        } else {
                                $failed++;
                        }

                        $details[] = array(
                                'blog_id' => $id,
                                'url'     => $url,
                                'ok'      => $ok,
                        );
                }

                return array(
                        'installed' => $installed,
                        'failed'    => $failed,
                        'details'   => $details,
                );
        }
}
