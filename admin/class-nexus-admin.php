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
 *     └── Impostazioni            slug nexus-settings              → admin/views/settings.php
 *
 * v1.1.11 (R2): le 4 voci Impostazioni (contenuti|ai|pubblicazione|sistema)
 * diventano UNA sola voce "Impostazioni" (slug nexus-settings): la vista
 * settings.php è una pagina a tab che INCLUDE i 4 partial storici
 * (admin/views/settings-*.php) come pannelli. I 4 slug storici restano
 * REGISTRATI (BC: URL e link esterni page=nexus-settings-* funzionanti) ma
 * rimossi dal menu con remove_submenu_page e mappano sulla stessa vista con
 * il rispettivo tab aperto.
 *
 * NOTA v1.1.7: la voce "Dashboard" NON è una sottopagina registrata: è la
 * voce auto-generata da add_menu_page per il top-level, rinominata in
 * register_menu(). Un add_submenu_page('nexus-news', ..., 'nexus-news', ...)
 * produrrebbe lo stesso hook WordPress del top-level
 * ('toplevel_page_nexus-news') e la dashboard verrebbe renderizzata 2 volte.
 *
 * BC v1.1.11 (R2): i 4 slug storici nexus-settings-contenuti|ai|pubblicazione|
 * sistema restano registrati e mappati sulla vista settings (tab preimpostato)
 * ma la voce di menu viene rimossa subito dopo la registrazione
 * (remove_submenu_page, stesso pattern v1.1.6): nel menu resta UNA voce
 * "Impostazioni" (slug nexus-settings). Le pagine condividono
 * data-page="settings" e la form #nxa-settings-form: PUT /settings è parziale
 * con whitelist, quindi ogni tab invia solo le proprie chiavi (la vista
 * settings.php ospita i campi del tab attivo nella form bindingata da
 * admin.js: vedi il contratto "form viaggiante" nel docblock della vista).
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
                        // v1.1.11 (R2): le Impostazioni tornano a essere UNA pagina
                        // (vista a tab che include i 4 partial storici).
                        'nexus-settings'               => 'settings',
                        // BC v1.1.11 (R2): i 4 slug storici mappano sulla stessa vista
                        // con il rispettivo tab preimpostato (URL e link esterni
                        // page=nexus-settings-* continuano a funzionare).
                        'nexus-settings-contenuti'     => 'settings',
                        'nexus-settings-ai'            => 'settings',
                        'nexus-settings-pubblicazione' => 'settings',
                        'nexus-settings-sistema'       => 'settings',
                );
        }

        /**
         * Registra la pagina di menu principale e le sottopagine.
         *
         * La Dashboard è SOLO la pagina top-level: non esiste alcun
         * add_submenu_page con slug figlio 'nexus-news' (slug figlio uguale
         * allo slug parent genera lo stesso hook 'toplevel_page_nexus-news'
         * già registrato da add_menu_page e il secondo callback finirebbe sulla
         * stessa azione → vista renderizzata 2 volte). La voce "Dashboard" del
         * sottomenu è la voce auto-generata da add_menu_page, rinominata in
         * coda al metodo.
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
                        'nexus-agents'    => __( 'Agenti', 'nexus-news-ai' ),
                        'nexus-redazioni' => __( 'Redazioni', 'nexus-news-ai' ),
                        'nexus-queue'     => __( 'Coda Approvazioni', 'nexus-news-ai' ),
                        'nexus-jobs'      => __( 'Coda Lavori', 'nexus-news-ai' ),
                        'nexus-activity'  => __( 'Attività', 'nexus-news-ai' ),
                        'nexus-wire'      => __( 'Nexus Wire', 'nexus-news-ai' ),
                        'nexus-analytics' => __( 'Analytics', 'nexus-news-ai' ),
                        'nexus-tools'     => __( 'Strumenti', 'nexus-news-ai' ),
                        // v1.1.11 (R2): le Impostazioni tornano a essere UNA voce di
                        // menu: la pagina nexus-settings (vista a tab settings.php)
                        // include i 4 partial storici come pannelli; PUT /settings è
                        // parziale con whitelist, il salvataggio resta per-tab.
                        'nexus-settings' => __( 'Impostazioni', 'nexus-news-ai' ),
                );

                // BC v1.1.11 (R2): i 4 slug storici restano REGISTRATI (URL vecchi
                // e link esterni page=nexus-settings-* funzionanti, ognuno mappa
                // sulla vista a tab col proprio tab aperto) ma la voce di menu è
                // rimossa subito dopo la registrazione (stesso pattern BC v1.1.6).
                $bc_submenus = array(
                        'nexus-settings-contenuti'     => __( 'Impostazioni contenuti', 'nexus-news-ai' ),
                        'nexus-settings-ai'            => __( 'Impostazioni AI', 'nexus-news-ai' ),
                        'nexus-settings-pubblicazione' => __( 'Impostazioni pubblicazione', 'nexus-news-ai' ),
                        'nexus-settings-sistema'       => __( 'Impostazioni sistema', 'nexus-news-ai' ),
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

                foreach ( $bc_submenus as $slug => $title ) {
                        add_submenu_page(
                                'nexus-news',
                                $title, // <title> pagina.
                                $title, // Titolo (la voce NON appare nel menu: remove sotto).
                                'manage_options',
                                $slug,
                                array( __CLASS__, 'render_current_page' )
                        );
                        remove_submenu_page( 'nexus-news', $slug );
                }

                // v1.1.7: la voce "Dashboard" è la voce auto-generata da
                // add_menu_page (prima posizione del sottomenu, slug
                // 'nexus-news'): qui viene solo rinominata. NON si registra
                // add_submenu_page('nexus-news', ..., 'nexus-news'): con slug
                // figlio == slug parent WordPress ritorna da
                // get_plugin_page_hookname() lo stesso hook già usato dal
                // top-level ('toplevel_page_nexus-news') e il secondo callback
                // (render_current_page) si aggiungerebbe alla stessa azione
                // della pagina, renderizzando la dashboard due volte.
                if ( isset( $GLOBALS['submenu']['nexus-news'][0] ) ) {
                        $GLOBALS['submenu']['nexus-news'][0][0] = __( 'Dashboard', 'nexus-news-ai' );
                }

                // Nessuna rimozione per nexus-settings: dalla v1.1.11 (R2) è la voce
                // "Impostazioni" VISIBILE del menu (logica BC v1.1.6 invertita).
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
         *   toplevel_page_nexus-news (unico hook della dashboard: il top-level
         *     non ha sottopagine con slug identico, vedi register_menu)
         *   nexus-news_page_nexus-agents|nexus-queue|nexus-activity|nexus-wire
         *   nexus-news_page_nexus-settings (v1.1.11 R2: pagina Impostazioni a tab)
         *   nexus-news_page_nexus-settings-* (BC v1.1.11: slug storici
         *     contenuti|ai|pubblicazione|sistema, registrati ma fuori menu)
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

                // v1.1.11 (R2): tab della pagina Impostazioni unica. Dipendente da
                // nexus-admin-css per garantire l'ordine (usa solo i suoi token).
                wp_enqueue_style(
                        'nexus-admin-tabs-css',
                        NEXUS_PLUGIN_URL . 'assets/css/admin-tabs.css',
                        array( 'nexus-admin-css' ),
                        NEXUS_VERSION
                );

                /*
                 * v1.1.13 (O4): fogli di stile PER VISTA, enqueuati solo sulla pagina
                 * corrispondente (check dello slug nell'hook, stesso stile della guard
                 * "nexus-" in testa) e SOLO se il file esiste (i fogli analytics/tools
                 * arrivano col merge di altri agenti della release: senza file nessun
                 * enqueue, zero 404). Ogni foglio dipende da nexus-admin-css (usa solo
                 * i suoi token var(--nxa-*)) e porta NEXUS_VERSION come versione.
                 */
                if ( false !== strpos( (string) $hook, 'nexus-jobs' ) && file_exists( NEXUS_PLUGIN_DIR . 'admin/css/nexus-jobs.css' ) ) {
                        wp_enqueue_style(
                                'nexus-jobs-css',
                                NEXUS_PLUGIN_URL . 'admin/css/nexus-jobs.css',
                                array( 'nexus-admin-css' ),
                                NEXUS_VERSION
                        );
                }
                if ( false !== strpos( (string) $hook, 'nexus-analytics' ) && file_exists( NEXUS_PLUGIN_DIR . 'admin/css/nexus-analytics.css' ) ) {
                        wp_enqueue_style(
                                'nexus-analytics-css',
                                NEXUS_PLUGIN_URL . 'admin/css/nexus-analytics.css',
                                array( 'nexus-admin-css' ),
                                NEXUS_VERSION
                        );
                }
                if ( false !== strpos( (string) $hook, 'nexus-tools' ) && file_exists( NEXUS_PLUGIN_DIR . 'admin/css/nexus-tools.css' ) ) {
                        wp_enqueue_style(
                                'nexus-tools-css',
                                NEXUS_PLUGIN_URL . 'admin/css/nexus-tools.css',
                                array( 'nexus-admin-css' ),
                                NEXUS_VERSION
                        );
                }

                /*
                 * v1.1.13 (glue): gli script estratti dalle viste (task O5) —
                 * analytics (grafico) e tools (export/import/keygen/test AI) —
                 * enqueuati SOLO sulla pagina corrispondente e SOLO se il file
                 * esiste, in footer, senza dipendenze (IIFE autonome che
                 * completano il DOM già presente nelle viste).
                 */
                if ( false !== strpos( (string) $hook, 'nexus-analytics' ) && file_exists( NEXUS_PLUGIN_DIR . 'assets/js/nexus-analytics.js' ) ) {
                        wp_enqueue_script(
                                'nexus-analytics-js',
                                NEXUS_PLUGIN_URL . 'assets/js/nexus-analytics.js',
                                array(),
                                NEXUS_VERSION,
                                true
                        );
                }
                if ( false !== strpos( (string) $hook, 'nexus-tools' ) && file_exists( NEXUS_PLUGIN_DIR . 'assets/js/nexus-tools.js' ) ) {
                        wp_enqueue_script(
                                'nexus-tools-js',
                                NEXUS_PLUGIN_URL . 'assets/js/nexus-tools.js',
                                array(),
                                NEXUS_VERSION,
                                true
                        );
                }

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
