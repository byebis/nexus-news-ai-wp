<?php
/**
 * Nexus I18n — traduzione sperimentale dell'interfaccia admin in inglese.
 *
 * CONTRATTO PUBBLICO (v1.0.5):
 * Nexus_I18n::init()              — hook admin_enqueue_scripts → enqueue()
 * Nexus_I18n::current_lang()      — 'it' | 'en' (setting admin_language: auto/it/en)
 * Nexus_I18n::is_active()         — true se la lingua effettiva è 'en'
 * Nexus_I18n::dictionary()        — array IT=>EN (dict base + dict extra via filtro)
 * Nexus_I18n::patterns()          — array di pattern {re, tpl} IT=>EN (dict extra + filtro)
 * Nexus_I18n::translate_html( $html ) — strtr a match esatti (usato da Nexus_Admin::render_view)
 * Nexus_I18n::enqueue( $hook )    — carica assets/js/i18n.js + mappa e pattern solo sulle pagine nexus in EN
 *
 * Meccanismo:
 * 1. PHP: le viste admin passano per translate_html() (buffer in render_view).
 * 2. JS: i18n.js traduce i nodi testo renderizzati dinamicamente da admin.js
 *    via MutationObserver, usando la mappa ricevuta con wp_localize_script.
 *
 * Il dizionario extra (includes/class-nexus-i18n-dict-extra.php) viene caricato
 * automaticamente se presente e prevale sul dizionario base; altri plugin possono
 * aggiungere voci con il filtro 'nexus_i18n_dictionary' e pattern con il filtro
 * 'nexus_i18n_patterns' (stesso formato della funzione nexus_i18n_extra_patterns()).
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
         * Cache dei pattern (messaggi con parti variabili).
         *
         * @var array|null
         */
        private static $patterns = null;

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
         * Pattern IT => EN per i messaggi con parti variabili (activity feed,
         * toast e frasi composite di admin.js): lista di coppie
         * array( 're' => regex senza delimitatori, 'tpl' => template EN con $1/$2 ).
         *
         * Stesso schema di dictionary(): i pattern EXTRA (terza mappa di
         * includes/class-nexus-i18n-dict-extra.php, nexus_i18n_extra_patterns())
         * vengono caricati se il file esiste e merge con il filtro
         * 'nexus_i18n_patterns'; vengono mantenuti solo i pattern validi
         * (chiavi 're' e 'tpl' stringhe non vuote).
         *
         * @return array Lista indicizzata di array( 're' => string, 'tpl' => string ).
         */
        public static function patterns() {
                if ( null !== self::$patterns ) {
                        return self::$patterns;
                }

                $patterns = array();

                $extra_file = NEXUS_PLUGIN_DIR . 'includes/class-nexus-i18n-dict-extra.php';
                if ( file_exists( $extra_file ) ) {
                        require_once $extra_file;
                        if ( function_exists( 'nexus_i18n_extra_patterns' ) ) {
                                $patterns = array_values( (array) nexus_i18n_extra_patterns() );
                        }
                }

                $patterns = array_merge( $patterns, (array) apply_filters( 'nexus_i18n_patterns', array() ) );

                // Solo pattern validi: 're' e 'tpl' stringhe non vuote.
                $clean = array();
                foreach ( $patterns as $pattern ) {
                        if (
                                is_array( $pattern )
                                && isset( $pattern['re'], $pattern['tpl'] )
                                && is_string( $pattern['re'] ) && is_string( $pattern['tpl'] )
                                && '' !== $pattern['re'] && '' !== $pattern['tpl']
                        ) {
                                $clean[] = array(
                                        're'  => $pattern['re'],
                                        'tpl' => $pattern['tpl'],
                                );
                        }
                }

                self::$patterns = $clean;
                return self::$patterns;
        }

        /**
         * Traduce un blocco di HTML con sostituzioni a match esatti.
         * Se la traduzione non è attiva ritorna l'input invariato.
         *
         * v1.1.7 (T-C): i nodi di CONTENUTO UTENTE (es. tagline del sito in
         * dashboard) vengono estratti PRIMA dello strtr e reinseriti identici
         * dopo: la tagline è un setting del sito (get_bloginfo('description')
         * / site_tagline), non UI del plugin, e non deve mai essere tradotta
         * (in e2e una chiave corta del dizionario produceva il testo
         * Frankenstein "Il giornale of nuova generazione").
         *
         * In più le chiavi "parola semplice" del dizionario (solo lettere
         * ASCII, es. 'Mai', 'Vedi', 'Errore') NON passano da strtr (che fa
         * match di sottostringa e corrompe ogni occorrenza dentro altre
         * parole o identificatori: caso reale 'Data' => 'Date' trasformava il
         * global JS NexusAdminData in NexusAdminDate) ma da una sostituzione
         * con confini di parola (\b): stesso risultato sulle etichette UI,
         * zero collisioni dentro identificatori, URL e contenuto utente.
         *
         * v1.1.7 (T-C, secondo caso Frankenstein): le viste renderizzano le
         * stringhe con esc_html__()/esc_attr__(), che trasformano apostrofi e
         * virgolette in entita' HTML DENTRO il buffer (&#039; &quot;): una
         * chiave del dizionario con l'apostrofo grezzo (es. il sottotitolo
         * dashboard "…colpo d'occhio: articoli pubblicati…") non matcha mai
         * la frase intera e strtr la frammenta sulle sole chiavi corte senza
         * apostrofi ("… articles published, agents at work e consumo AI del
         * mese."). Per ogni chiave con caratteri escapabili viene generata a
         * runtime la variante escapata equivalente (stessa codifica di WP:
         * ENT_QUOTES, double_encode=false) mappata sulla traduzione escapata:
         * la frase intera torna a vincere sul frammento (strtr preferisce
         * sempre la chiave piu' lunga per posizione).
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
                $html = (string) $html;

                $held = array();
                $html = self::hold_user_content( $html, $dict, $held );

                /*
                 * v1.1.7: gli script inline non passano MAI dalla traduzione.
                 * Il codice JS delle viste contiene identificatori in italiano
                 * (caso reale: tooltipGiorno( creati, pubblicati ) in analytics.php):
                 * le chiavi del dizionario a confini di parola li rinominano in
                 * inglese e in lingua EN il JS muore con ReferenceError
                 * ("creati is not defined"). I testi generati dal JS sono
                 * tradotti lato client da i18n.js (MutationObserver sui nodi di
                 * testo): qui il JS resta intatto byte per byte.
                 */
                $script_nodes = array();
                if ( preg_match_all( '~<script\b[^>]*>.*?</script>~is', $html, $script_nodes ) && ! empty( $script_nodes[0] ) ) {
                        foreach ( $script_nodes[0] as $node ) {
                                if ( '' === $node || isset( $held[ $node ] ) ) {
                                        continue;
                                }
                                $token   = self::hold_token( $html, $dict, count( $held ) );
                                $held[ $token ] = $node;
                                $html    = str_replace( $node, $token, $html );
                        }
                }

                /*
                 * v1.1.7 (T-C): il dizionario viene diviso in due parti prima
                 * della sostituzione (vedi docblock sopra per il perche').
                 */
                $word_dict = array();
                $rest_dict = array();
                foreach ( $dict as $key => $value ) {
                        if ( preg_match( '/^[A-Za-z]{2,}$/', $key ) ) {
                                $word_dict[ $key ] = $value;
                        } else {
                                $rest_dict[ $key ] = $value;
                        }
                }

                /*
                 * v1.1.7 (T-C): varianti HTML-escaped delle chiavi (secondo caso
                 * Frankenstein, vedi docblock sopra). Le viste escapano le
                 * stringhe (esc_html__/esc_attr__), quindi la forma presente nel
                 * buffer non e' quella grezza: senza la variante la chiave intera
                 * non matcha e strtr frammenta la frase sulle chiavi corte.
                 */
                $esc_variants = self::escaped_variants( $rest_dict );
                if ( ! empty( $esc_variants ) ) {
                        $rest_dict = array_merge( $rest_dict, $esc_variants );
                }

                if ( ! empty( $rest_dict ) ) {
                        $html = strtr( $html, $rest_dict );
                }
                $html = self::translate_whole_words( $html, $word_dict );

                if ( ! empty( $held ) ) {
                        $html = str_replace( array_keys( $held ), array_values( $held ), $html );
                }
                return $html;
        }

        /**
         * Genera le varianti HTML-escaped delle chiavi del dizionario (v1.1.7 T-C).
         *
         * esc_html()/esc_attr() di WP (_wp_specialchars con ENT_QUOTES e
         * double_encode=false) convertono & < > ' " nelle rispettive entita'
         * senza ricodificare le entita' gia' presenti: qui si applica la stessa
         * trasformazione (htmlspecialchars con double_encode=false) alla chiave
         * e alla traduzione. Solo le chiavi che contengono almeno un carattere
         * escapabile producono una variante (le altre sono identiche al buffer);
         * una variante che collida con una chiave esistente viene scartata:
         * vince la voce già presente nel dizionario.
         *
         * @param array $dict Dizionario (chiavi grezze) della passata strtr.
         * @return array Varianti chiave-escapata => traduzione-escapata.
         */
        private static function escaped_variants( $dict ) {
                $variants = array();
                foreach ( $dict as $key => $value ) {
                        if ( ! preg_match( '/[&<>\'"]/', (string) $key ) ) {
                                continue;
                        }
                        $escaped_key = htmlspecialchars( (string) $key, ENT_QUOTES, 'UTF-8', false );
                        if ( '' === $escaped_key || $escaped_key === (string) $key ) {
                                continue;
                        }
                        if ( isset( $dict[ $escaped_key ] ) || isset( $variants[ $escaped_key ] ) ) {
                                continue;
                        }
                        $variants[ $escaped_key ] = htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8', false );
                }
                return $variants;
        }

        /**
         * Traduce le chiavi "parola semplice" con confini di parola (v1.1.7 T-C).
         *
         * strtr con una chiave come 'Data' matcha anche la coda di
         * "NexusAdminData"; con \b la sostituzione avviene solo quando la
         * parola e' intera: stesso effetto sulle etichette UI renderizzate
         * dalle viste, zero danni dentro identificatori, URL e contenuto
         * utente. Le chiavi sono ordinate per lunghezza decrescente cosi' una
         * chiave piu' lunga ha la precedenza su un suo prefisso, come farebbe
         * strtr. Il nodo tagline e gli altri nodi utente sono gia' trattenuti
         * (hold_user_content) quindi non raggiungono mai questa funzione.
         *
         * @param string $html      HTML con i nodi utente gia' trattenuti.
         * @param array  $word_dict Mappa chiave => traduzione (solo [A-Za-z]{2,}).
         * @return string
         */
        private static function translate_whole_words( $html, $word_dict ) {
                if ( '' === $html || empty( $word_dict ) ) {
                        return $html;
                }

                uksort( $word_dict, array( __CLASS__, 'key_length_desc' ) );

                $parts = array();
                foreach ( array_keys( $word_dict ) as $key ) {
                        $parts[] = preg_quote( $key, '/' );
                }
                $regex = '/\b(?:' . implode( '|', $parts ) . ')\b/';

                return preg_replace_callback(
                        $regex,
                        function ( $matches ) use ( $word_dict ) {
                                $key = $matches[0];
                                return isset( $word_dict[ $key ] ) ? $word_dict[ $key ] : $key;
                        },
                        $html
                );
        }

        /**
         * Callback di ordinamento per uksort: chiavi piu' lunghe prima
         * (a parita' di lunghezza, ordine alfabetico per determinismo).
         *
         * @param string $a Prima chiave.
         * @param string $b Seconda chiave.
         * @return int
         */
        private static function key_length_desc( $a, $b ) {
                $diff = strlen( $b ) - strlen( $a );
                if ( 0 !== $diff ) {
                        return $diff;
                }
                return strcmp( $a, $b );
        }

        /**
         * Regex dei nodi di contenuto utente da NON tradurre (v1.1.7 T-C).
         *
         * Ogni pattern cattura il nodo HTML INTERO (<p ...>...</p>): viene
         * sostituito da un placeholder prima dello strtr e ripristinato dopo.
         * Il testo dentro questi nodi e' contenuto scritto dall'utente
         * (setting del sito), non stringhe UI del plugin.
         *
         * @return array Lista di pattern regex PCRE (delimitatori inclusi).
         */
        private static function user_content_patterns() {
                return array(
                        // Tagline WP nativa renderizzata in dashboard
                        // (admin/views/dashboard.php: <p class="nxa-tagline">).
                        '#<p\b[^>]*nxa-tagline[^>]*>.*?</p>#s',
                        // v1.1.7 (T-C): nome della redazione nella card "Redazioni"
                        // della dashboard (nxa-red-top-name = nome del termine
                        // scelto dall'utente in Nexus_Redazioni): stesso principio
                        // della tagline, il contenuto utente non si traduce mai.
                        '#<span\b[^>]*nxa-red-top-name[^>]*>.*?</span>#s',
                );
        }

        /**
         * Sostituisce i nodi di contenuto utente con placeholder sicuri.
         *
         * Il placeholder e' un token che: (a) non compare gia' nell'HTML,
         * (b) non contiene nessuna chiave del dizionario come sottostringa
         * (cosi' lo strtr non puo' toccarlo).
         *
         * @param string $html  HTML della vista (modificato per riferimento).
         * @param array  $dict  Dizionario attivo.
         * @param array  $held  Mappa token => nodo originale (output).
         * @return string HTML con i nodi utente sostituiti da token.
         */
        private static function hold_user_content( $html, $dict, &$held ) {
                foreach ( self::user_content_patterns() as $pattern ) {
                        $matches = array();
                        if ( ! preg_match_all( $pattern, $html, $matches ) || empty( $matches[0] ) ) {
                                continue;
                        }
                        foreach ( $matches[0] as $node ) {
                                if ( '' === $node || isset( $held[ $node ] ) ) {
                                        continue;
                                }
                                $token   = self::hold_token( $html, $dict, count( $held ) );
                                $held[ $token ] = $node;
                                $html    = str_replace( $node, $token, $html );
                        }
                }
                return $html;
        }

        /**
         * Token placeholder unico e "strtr-proof".
         *
         * @param string $html  HTML corrente.
         * @param array  $dict  Dizionario attivo.
         * @param int    $index Indice progressivo del nodo trattenuto.
         * @return string
         */
        private static function hold_token( $html, $dict, $index ) {
                $index = (int) $index;
                do {
                        $token = '@@NEXUS_I18N_HOLD_' . $index . '_@@';
                        $index++;
                } while ( false !== strpos( $html, $token ) || self::dict_key_in( $token, $dict ) );
                return $token;
        }

        /**
         * True se una chiave del dizionario e' sottostringa del token.
         *
         * @param string $token Token candidato.
         * @param array  $dict  Dizionario attivo.
         * @return bool
         */
        private static function dict_key_in( $token, $dict ) {
                foreach ( array_keys( $dict ) as $key ) {
                        if ( '' !== $key && false !== strpos( $token, $key ) ) {
                                return true;
                        }
                }
                return false;
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
                                'enabled'  => true,
                                'map'      => (object) self::dictionary(),
                                'patterns' => self::patterns(),
                        )
                );
        }
}
