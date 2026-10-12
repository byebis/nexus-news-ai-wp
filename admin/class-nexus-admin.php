<?php
/**
 * Nexus Admin — menu di amministrazione, asset backend e rendering delle viste.
 *
 * CONTRATTO CON GLI ALTRI MODULI:
 * - assets/js/admin.js (gestito da altro agente) consuma le REST route nexus/v1
 *   e si aggancia ai container con attributo data-page presenti nelle viste
 *   admin/views/*.php. Le viste sono skeleton HTML riempiti via REST.
 * - Ogni pagina admin usa una vista in admin/views/{nome}.php (vedi mappa sotto).
 * - render_view() include la vista solo se il file esiste: le viste degli altri
 *   agenti possono essere aggiunte successivamente senza errori fatali.
 *
 * MAPPA MENU → VISTA:
 *   Nexus News (top-level, slug nexus-news)
 *     ├── Dashboard               slug nexus-news                  → admin/views/dashboard.php
 *     ├── Agenti                  slug nexus-agents                → admin/views/agents.php
 *     ├── Redazioni               slug nexus-redazioni             → admin/views/redazioni.php
 *     ├── Coda Approvazioni       slug nexus-queue                 → admin/views/queue.php
 *     ├── Coda Lavori             slug nexus-jobs                  → admin/views/jobs.php
 *     ├── Attività                slug nexus-activity              → admin/views/activity.php
 *     ├── Nexus Wire              slug nexus-wire                  → admin/views/wire.php
 *     ├── Analytics               slug nexus-analytics             → admin/views/analytics.php
 *     ├── Strumenti               slug nexus-tools                 → admin/views/tools.php
 *     ├── Impostazioni contenuti  slug nexus-settings-contenuti    → admin/views/settings-contenuti.php
 *     ├── Impostazioni AI         slug nexus-settings-ai           → admin/views/settings-ai.php
 *     ├── Impostazioni pubblicazione  slug nexus-settings-pubblicazione → admin/views/settings-pubblicazione.php
 *     └── Impostazioni sistema    slug nexus-settings-sistema      → admin/views/settings-sistema.php
 *
 * BC v1.1.6: lo slug storico nexus-settings resta registrato e mappato sulla
 * vista settings-contenuti (URL e link esterni con page=nexus-settings
 * continuano a funzionare) ma la voce di menu viene rimossa subito dopo la
 * registrazione (remove_submenu_page): al suo posto le 4 voci Impostazioni.
 * Le 4 pagine condividono data-page="settings" e la form
 * #nxa-settings-form: PUT /settings è parziale con whitelist, quindi ogni
 * pagina invia solo le proprie chiavi.
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Admin {

        /**
         * Bootstrapping modulo: registra menu e asset.
         *
         * @return void
         */
        public static function init() {
                add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
                add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
                add_filter( 'admin_body_class', array( __CLASS__, 'admin_body_class' ) );
        }

        /**
         * Tema corrente del pannello admin (v1.0.6).
         *
         * Legge il setting admin_theme ('light' | 'dark'); light è il default
         * dalla 1.0.6, dark mantiene l'aspetto notturno delle versioni precedenti.
         *
         * @return string 'light' oppure 'dark'.
         */
        public static function current_theme() {
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'get_settings' ) ) {
                        return 'light';
                }

                $settings = Nexus_DB::get_settings();
                $theme    = isset( $settings['admin_theme'] ) ? (string) $settings['admin_theme'] : 'light';

                return ( 'dark' === $theme ) ? 'dark' : 'light';
        }

        /**
         * Applica la classe tema al body dell'admin (v1.0.6).
         *
         * Il CSS (admin/css/nexus-admin.css) usa i token su :root per il tema
         * chiaro e l'override su body.nexus-theme-dark per quello scuro; gli
         * elementi flottanti (modale, toast) ereditano il tema da body.
         *
         * @param string $classes Classi CSS già presenti sul body admin.
         * @return string
         */
        public static function admin_body_class( $classes ) {
                if ( 'dark' === self::current_theme() ) {
                        $classes .= ' nexus-theme-dark';
                }

                return $classes;
        }

        /**
         * Mappa slug pagina admin → nome vista (senza estensione .php).
         *
         * @return array
         */
        private static function page_views() {
                return array(
                        'nexus-news'      => 'dashboard',
                        'nexus-agents'    => 'agents',
                        'nexus-redazioni' => 'redazioni',
                        'nexus-queue'     => 'queue',
                        'nexus-jobs'      => 'jobs',
                        'nexus-activity'  => 'activity',
                        'nexus-wire'      => 'wire',
                        'nexus-analytics' => 'analytics',
                        'nexus-tools'     => 'tools',
                        // v1.1.6: le Impostazioni diventano 4 pagine (una per gruppo).
                        'nexus-settings-contenuti'     => 'settings-contenuti',
                        'nexus-settings-ai'            => 'settings-ai',
                        'nexus-settings-pubblicazione' => 'settings-pubblicazione',
                        'nexus-settings-sistema'       => 'settings-sistema',
                        // BC v1.1.6: lo slug storico mappa sulla prima pagina
                        // Impostazioni (URL e link esterni page=nexus-settings).
                        'nexus-settings'               => 'settings-contenuti',
                );
        }

        /**
         * Registra la pagina di menu principale e le sottopagine.
         *
         * @return void
         */
        public static function register_menu() {
                add_menu_page(
                        __( 'Nexus News AI', 'nexus-news-ai' ),
                        __( 'Nexus News', 'nexus-news-ai' ),
                        'manage_options',
                        'nexus-news',
                        array( __CLASS__, 'render_dashboard_page' ),
                        'dashicons-megaphone',
                        26
                );

                $submenus = array(
                        'nexus-news'      => __( 'Dashboard', 'nexus-news-ai' ),
                        'nexus-agents'    => __( 'Agenti', 'nexus-news-ai' ),
                        'nexus-redazioni' => __( 'Redazioni', 'nexus-news-ai' ),
                        'nexus-queue'     => __( 'Coda Approvazioni', 'nexus-news-ai' ),
                        'nexus-jobs'      => __( 'Coda Lavori', 'nexus-news-ai' ),
                        'nexus-activity'  => __( 'Attività', 'nexus-news-ai' ),
                        'nexus-wire'      => __( 'Nexus Wire', 'nexus-news-ai' ),
                        'nexus-analytics' => __( 'Analytics', 'nexus-news-ai' ),
                        'nexus-tools'     => __( 'Strumenti', 'nexus-news-ai' ),
                        // v1.1.6: le Impostazioni diventano 4 sottomenu (una pagina per
                        // gruppo, così il salvataggio non richiede di scorrere un'unica
                        // pagina lunga; PUT /settings è parziale con whitelist).
                        'nexus-settings-contenuti'     => __( 'Impostazioni contenuti', 'nexus-news-ai' ),
                        'nexus-settings-ai'            => __( 'Impostazioni AI', 'nexus-news-ai' ),
                        'nexus-settings-pubblicazione' => __( 'Impostazioni pubblicazione', 'nexus-news-ai' ),
                        'nexus-settings-sistema'       => __( 'Impostazioni sistema', 'nexus-news-ai' ),
                        // BC v1.1.6: lo slug storico resta registrato (URL vecchi
                        // funzionanti) ma la voce è rimossa subito sotto.
                        'nexus-settings'               => __( 'Impostazioni', 'nexus-news-ai' ),
                );

                foreach ( $submenus as $slug => $title ) {
                        add_submenu_page(
                                'nexus-news',
                                $title, // <title> pagina.
                                $title, // Voce di menu.
                                'manage_options',
                                $slug,
                                array( __CLASS__, 'render_current_page' )
                        );
                }

                // BC v1.1.6: la voce "Impostazioni" non appare più nel menu (al suo
                // posto le 4 voci Impostazioni); lo slug resta raggiungibile via
                // URL page=nexus-settings e mappa sulla pagina contenuti.
                remove_submenu_page( 'nexus-news', 'nexus-settings' );
        }

        /**
         * Callback pagina Dashboard (voci di menu "Nexus News" e "Dashboard").
         *
         * @return void
         */
        public static function render_dashboard_page() {
                self::render_view( 'dashboard' );
        }

        /**
         * Callback generica sottopagine: risolve la vista dallo slug corrente.
         *
         * @return void
         */
        public static function render_current_page() {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sola lettura dello slug menu, poi whitelistata.
                $page  = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'nexus-news';
                $views = self::page_views();
                $view  = isset( $views[ $page ] ) ? $views[ $page ] : 'dashboard';

                self::render_view( $view );
        }

        /**
         * Carica CSS/JS solo sulle pagine del plugin (hook contenente "nexus-").
         *
         * Hook attesi:
         *   toplevel_page_nexus-news
         *   nexus-news_page_nexus-agents|nexus-queue|nexus-activity|nexus-wire
         *   nexus-news_page_nexus-settings-* (contenuti|ai|pubblicazione|sistema)
         *   nexus-news_page_nexus-settings (BC v1.1.6, slug storico)
         *
         * @param string $hook Suffix della pagina admin corrente.
         * @return void
         */
        public static function enqueue_assets( $hook ) {
                if ( false === strpos( (string) $hook, 'nexus-' ) ) {
                        return;
                }

                wp_enqueue_style(
                        'nexus-admin-css',
                        NEXUS_PLUGIN_URL . 'admin/css/nexus-admin.css',
                        array(),
                        NEXUS_VERSION
                );

                wp_enqueue_script(
                        'nexus-admin',
                        NEXUS_PLUGIN_URL . 'assets/js/admin.js',
                        array(),
                        NEXUS_VERSION,
                        true
                );

                wp_localize_script(
                        'nexus-admin',
                        'NexusAdminData',
                        array(
                                'restUrl' => esc_url_raw( rest_url( 'nexus/v1' ) ),
                                'nonce'   => wp_create_nonce( 'wp_rest' ),
                                'version' => NEXUS_VERSION,
                                'siteUrl' => home_url( '/' ),
                                'theme'   => self::current_theme(),
                        )
                );
        }

        /**
         * Include una vista da admin/views/{view}.php in modo sicuro.
         *
         * Il nome vista viene ripulito (solo [a-z0-9_-]) per prevenire path traversal;
         * se il file non esiste (es. vista di altro agente non ancora presente)
         * mostra un avviso admin invece di generare un errore fatale.
         *
         * @param string $view Nome della vista, senza estensione (es. "dashboard").
         * @return void
         */
        public static function render_view( $view ) {
                $view = preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $view ) );

                if ( '' === $view ) {
                        return;
                }

                $file = NEXUS_PLUGIN_DIR . 'admin/views/' . $view . '.php';

                if ( ! file_exists( $file ) ) {
                        printf(
                                '<div class="notice notice-warning"><p>%s</p></div>',
                                sprintf(
                                        /* translators: %s: nome della vista mancante. */
                                        esc_html__( 'Vista "%s" non ancora disponibile (admin/views/).', 'nexus-news-ai' ),
                                        esc_html( $view )
                                )
                        );
                        return;
                }

                ob_start();
                include $file;
                $out = ob_get_clean();

                /* Gancio i18n (v1.0.5): l'HTML della vista è bufferizzato per poter
                 * applicare Nexus_I18n::translate_html quando il modulo è attivo. */
                if ( class_exists( 'Nexus_I18n' ) && method_exists( 'Nexus_I18n', 'translate_html' ) ) {
                        $out = Nexus_I18n::translate_html( $out );
                }

                echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
}
