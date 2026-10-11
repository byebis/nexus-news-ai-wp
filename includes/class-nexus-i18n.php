<?php
/**
 * Nexus I18n — traduzione sperimentale dell'interfaccia admin in inglese.
 *
 * CONTRATTO PUBBLICO (v1.0.5):
 * Nexus_I18n::init()              — hook admin_enqueue_scripts → enqueue()
 * Nexus_I18n::current_lang()      — 'it' | 'en' (setting admin_language: auto/it/en)
 * Nexus_I18n::is_active()         — true se la lingua effettiva è 'en'
 * Nexus_I18n::dictionary()        — array IT=>EN (dict base + dict extra via filtro)
 * Nexus_I18n::translate_html( $html ) — strtr a match esatti (usato da Nexus_Admin::render_view)
 * Nexus_I18n::enqueue( $hook )    — carica assets/js/i18n.js + mappa solo sulle pagine nexus in EN
 *
 * Meccanismo:
 * 1. PHP: le viste admin passano per translate_html() (buffer in render_view).
 * 2. JS: i18n.js traduce i nodi testo renderizzati dinamicamente da admin.js
 *    via MutationObserver, usando la mappa ricevuta con wp_localize_script.
 *
 * Il dizionario extra (includes/class-nexus-i18n-dict-extra.php) viene caricato
 * automaticamente se presente e prevale sul dizionario base; altri plugin possono
 * aggiungere voci con il filtro 'nexus_i18n_dictionary'.
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

class Nexus_I18n {

        /**
         * Lingua forzata dall'impostazione ('auto' | 'it' | 'en').
         *
         * @var string|null
         */
        private static $forced = null;

        /**
         * Cache del dizionario.
         *
         * @var array|null
         */
        private static $dict = null;

        /**
         * Bootstrap modulo.
         *
         * @return void
         */
        public static function init() {
                add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        }

        /**
         * Lingua impostata dalla configurazione (con default 'auto').
         *
         * @return string
         */
        private static function forced_lang() {
                if ( null === self::$forced ) {
                        $forced      = 'auto';
                        if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'get_settings' ) ) {
                                $settings = Nexus_DB::get_settings();
                                $forced   = isset( $settings['admin_language'] ) ? (string) $settings['admin_language'] : 'auto';
                        }
                        self::$forced = in_array( $forced, array( 'auto', 'it', 'en' ), true ) ? $forced : 'auto';
                }
                return self::$forced;
        }

        /**
         * Lingua effettiva dell'interfaccia: 'it' | 'en'.
         *
         * @return string
         */
        public static function current_lang() {
                $forced = self::forced_lang();

                if ( 'en' === $forced ) {
                        return 'en';
                }
                if ( 'it' === $forced ) {
                        return 'it';
                }

                // auto: segue la lingua del sito (italiano → it, tutto il resto → en).
                $locale = (string) get_locale();
                return ( 0 === strpos( $locale, 'it' ) ) ? 'it' : 'en';
        }

        /**
         * Traduzione attiva? Attiva solo quando la lingua effettiva è inglese.
         *
         * @return bool
         */
        public static function is_active() {
                return 'en' === self::current_lang();
        }

        /**
         * Dizionario IT => EN: base + extra (file dedicato) + filtro.
         *
         * @return array
         */
        public static function dictionary() {
                if ( null !== self::$dict ) {
                        return self::$dict;
                }

                $dict = array();

                $base_file = NEXUS_PLUGIN_DIR . 'includes/class-nexus-i18n-dict.php';
                if ( file_exists( $base_file ) ) {
                        require_once $base_file;
                        if ( function_exists( 'nexus_i18n_default_dict' ) ) {
                                $dict = (array) nexus_i18n_default_dict();
                        }
                }

                // Dizionario esteso (creato dall'agente T-08): prevale sul base.
                $extra_file = NEXUS_PLUGIN_DIR . 'includes/class-nexus-i18n-dict-extra.php';
                if ( file_exists( $extra_file ) ) {
                        require_once $extra_file;
                        if ( function_exists( 'nexus_i18n_extra_dict' ) ) {
                                $dict = array_merge( $dict, (array) nexus_i18n_extra_dict() );
                        }
                }

                $dict = array_merge( $dict, (array) apply_filters( 'nexus_i18n_dictionary', array() ) );

                // Solo voci valide: chiave e valore stringhe non vuote e diverse.
                $clean = array();
                foreach ( $dict as $k => $v ) {
                        if ( is_string( $k ) && is_string( $v ) && '' !== $k && '' !== $v && $k !== $v ) {
                                $clean[ $k ] = $v;
                        }
                }

                self::$dict = $clean;
                return self::$dict;
        }

        /**
         * Traduce un blocco di HTML con sostituzioni a match esatti.
         * Se la traduzione non è attiva ritorna l'input invariato.
         *
         * @param string $html HTML renderizzato dalla vista.
         * @return string
         */
        public static function translate_html( $html ) {
                if ( ! self::is_active() || '' === (string) $html ) {
                        return $html;
                }
                $dict = self::dictionary();
                if ( empty( $dict ) ) {
                        return $html;
                }
                return strtr( (string) $html, $dict );
        }

        /**
         * Carica lo script di traduzione JS sulle pagine del plugin (solo in EN).
         *
         * @param string $hook Suffix della pagina admin corrente.
         * @return void
         */
        public static function enqueue( $hook ) {
                if ( false === strpos( (string) $hook, 'nexus-' ) ) {
                        return;
                }
                if ( ! self::is_active() ) {
                        return;
                }

                wp_enqueue_script(
                        'nexus-i18n',
                        NEXUS_PLUGIN_URL . 'assets/js/i18n.js',
                        array( 'nexus-admin' ),
                        NEXUS_VERSION,
                        true
                );

                wp_localize_script(
                        'nexus-i18n',
                        'NexusI18n',
                        array(
                                'enabled' => true,
                                'map'     => (object) self::dictionary(),
                        )
                );
        }
}
