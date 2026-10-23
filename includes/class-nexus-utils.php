<?php
/**
 * Nexus Utils — helper statici di formattazione per l'interfaccia italiana.
 *
 * Contratto pubblico:
 * Nexus_Utils::time_ago_it( $mysql_datetime )          — tempo trascorso umano in italiano ('—' se null)
 * Nexus_Utils::excerpt_it( $text, $words )             — estratto pulito con ellissi (default 30 parole)
 * Nexus_Utils::reading_time_it( $text )                — "3 min di lettura" (200 parole/minuto)
 * Nexus_Utils::word_count_it( $text )                  — conteggio parole robusto (supporto UTF-8)
 * Nexus_Utils::reaction_emojis()                       — emoji ammesse per le reazioni
 * Nexus_Utils::category_color( $category )             — colore hex per categoria editoriale
 * Nexus_Utils::status_label_it( $status )              — etichetta italiana dello stato articolo
 * Nexus_Utils::platform_label_it( $platform )          — etichetta piattaforma di pubblicazione
 * Nexus_Utils::format_settings_for_form( $settings )   — settings normalizzate per il form admin
 * Nexus_Utils::clean_ai_content( $html )               — pulizia deterministica dell’output AI (v1.1.10, WP7)
 * Nexus_Utils::ai_content_quality_flags( $html )       — flag qualità output AI per il log attività (v1.1.10, WP7)
 * Nexus_Utils::to_rfc3339_local( $mysql_datetime )     — datetime RFC3339 CON l’offset del fuso del sito (v1.1.16, WC1)
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Utils {

        /**
         * Parole al minuto usate per il calcolo del tempo di lettura.
         */
        const WORDS_PER_MINUTE = 200;

        /**
         * Differenza umana in italiano tra una data MySQL e adesso.
         *
         * Esempi: 'adesso', '5 minuti fa', '3 ore fa', '2 giorni fa',
         * '1 settimana fa', '2 mesi fa', '1 anno fa'.
         *
         * @param string|null $mysql_datetime Data MySQL (Y-m-d H:i:s, ora locale del sito).
         * @return string
         */
        public static function time_ago_it( $mysql_datetime ) {
                if ( empty( $mysql_datetime ) ) {
                        return '—';
                }

                $timestamp = strtotime( (string) $mysql_datetime );

                if ( false === $timestamp ) {
                        return '—';
                }

                $diff = current_time( 'timestamp' ) - $timestamp;

                // Data nel futuro (differenze di fuso o tick in arrivo): considerala "adesso".
                if ( $diff < 0 ) {
                        return 'adesso';
                }

                if ( $diff < MINUTE_IN_SECONDS ) {
                        return 'adesso';
                }

                if ( $diff < HOUR_IN_SECONDS ) {
                        $minutes = (int) floor( $diff / MINUTE_IN_SECONDS );
                        return self::plural_it( $minutes, 'minuto', 'minuti' ) . ' fa';
                }

                if ( $diff < DAY_IN_SECONDS ) {
                        $hours = (int) floor( $diff / HOUR_IN_SECONDS );
                        return self::plural_it( $hours, 'ora', 'ore' ) . ' fa';
                }

                if ( $diff < WEEK_IN_SECONDS ) {
                        $days = (int) floor( $diff / DAY_IN_SECONDS );
                        return self::plural_it( $days, 'giorno', 'giorni' ) . ' fa';
                }

                if ( $diff < MONTH_IN_SECONDS ) {
                        $weeks = (int) floor( $diff / WEEK_IN_SECONDS );
                        return self::plural_it( $weeks, 'settimana', 'settimane' ) . ' fa';
                }

                if ( $diff < YEAR_IN_SECONDS ) {
                        $months = (int) floor( $diff / MONTH_IN_SECONDS );
                        return self::plural_it( $months, 'mese', 'mesi' ) . ' fa';
                }

                $years = (int) floor( $diff / YEAR_IN_SECONDS );
                return self::plural_it( $years, 'anno', 'anni' ) . ' fa';
        }

        /* ============ OROLOGI ONESTI (v1.1.16, WC1) ============ */

        /**
         * Converte un datetime MySQL (Y-m-d H:i:s, orologio LOCALE del sito —
         * quello scritto ovunque da current_time('mysql')) in RFC3339 CON
         * l'offset del fuso orario configurato in WordPress (wp_timezone()).
         *
         * Perché esiste: i payload REST uscivano SENZA fuso ('2025-01-01 12:00:00')
         * e il browser li interpretava come ora locale del visitatore: con
         * WordPress in UTC e il browser a Roma (CEST) un evento "pochi minuti
         * fa" appariva "2 ore fa". Con l'offset esplicito ('…T12:00:00+00:00')
         * new Date() mostra l'ora di WordPress su qualunque browser: il plugin
         * prende l'orario già impostato in WordPress, zero altre meccaniche.
         *
         * Contratto: input vuoto → ''; stringa non interpretabile (o
         * DateTimeImmutable/wp_timezone non disponibile) → l'INPUT torna
         * invariato. Mai fatal, mai null, mai una data inventata.
         *
         * @param string|null $mysql_datetime Datetime MySQL (ora locale del sito).
         * @return string RFC3339 con offset ('' o input invariato nei casi limite).
         */
        public static function to_rfc3339_local( $mysql_datetime ) {
                if ( empty( $mysql_datetime ) ) {
                        return '';
                }

                if ( ! function_exists( 'wp_timezone' ) || ! class_exists( 'DateTimeImmutable' ) ) {
                        return (string) $mysql_datetime;
                }

                try {
                        $date = new DateTimeImmutable( (string) $mysql_datetime, wp_timezone() );
                } catch ( Throwable $e ) {
                        // Stringa non interpretabile: mai una data ricostruita a caso,
                        // si restituisce l'input com'è (contratto onesto, v. docblock).
                        return (string) $mysql_datetime;
                }

                return $date->format( DATE_RFC3339 );
        }

        /**
         * Estratto in italiano: strip_tags + wp_trim_words con ellissi '…'.
         *
         * @param string|null $text Testo completo.
         * @param int         $words Numero massimo di parole (default 30).
         * @return string
         */
        public static function excerpt_it( $text, $words = 30 ) {
                $text = ( null === $text ) ? '' : (string) $text;
                $text = trim( wp_strip_all_tags( $text ) );

                if ( '' === $text ) {
                        return '';
                }

                $words = max( 1, absint( $words ) );

                return wp_trim_words( $text, $words, '…' );
        }

        /**
         * Tempo di lettura stimato: "3 min di lettura" (minimo 1).
         *
         * @param string|null $text Testo dell'articolo.
         * @return string
         */
        public static function reading_time_it( $text ) {
                $count   = self::word_count_it( $text );
                $minutes = max( 1, (int) ceil( $count / self::WORDS_PER_MINUTE ) );

                return $minutes . ' min di lettura';
        }

        /**
         * Conteggio parole robusto per testo italiano (split su spazi Unicode).
         *
         * @param string|null $text Testo (HTML ammesso, i tag vengono rimossi).
         * @return int
         */
        public static function word_count_it( $text ) {
                $text = ( null === $text ) ? '' : (string) $text;
                $text = trim( wp_strip_all_tags( $text ) );

                if ( '' === $text ) {
                        return 0;
                }

                $chunks = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

                if ( ! is_array( $chunks ) ) {
                        // Fallback estremo in caso di errore PCRE.
                        return count( explode( ' ', preg_replace( '/\s+/', ' ', $text ) ) );
                }

                return count( $chunks );
        }

        /**
         * Emoji ammesse per le reazioni agli articoli.
         *
         * @return array
         */
        public static function reaction_emojis() {
                return array( '❤️', '👏', '🔥', '😮', '😂', '🤔' );
        }

        /**
         * Mappa categoria editoriale → colore hex (usata per badge e accenti UI).
         *
         * @param string $category Nome categoria.
         * @return string Colore hex.
         */
        public static function category_color( $category ) {
                $map = array(
                        'Tecnologia' => '#38bdf8',
                        'Politica'   => '#f59e0b',
                        'Economia'   => '#34d399',
                        'Scienza'    => '#22d3ee',
                        'Sport'      => '#f87171',
                        'Cultura'    => '#f472b6',
                        'Salute'     => '#4ade80',
                );

                $key = mb_strtolower( trim( (string) $category ), 'UTF-8' );

                foreach ( $map as $name => $color ) {
                        if ( mb_strtolower( $name, 'UTF-8' ) === $key ) {
                                return $color;
                        }
                }

                return '#94a3b8';
        }

        /**
         * Etichetta italiana dello stato di un articolo.
         * Stati sconosciuti: restituisce il valore grezzo.
         *
         * @param string $status Stato articolo.
         * @return string
         */
        public static function status_label_it( $status ) {
                $map = array(
                        'draft'            => 'Bozza',
                        'pending_approval' => 'In attesa',
                        'approved'         => 'Approvato',
                        'published'        => 'Pubblicato',
                        'rejected'         => 'Rifiutato',
                );

                $key = strtolower( trim( (string) $status ) );

                return isset( $map[ $key ] ) ? $map[ $key ] : (string) $status;
        }

        /**
         * Etichetta italiana della piattaforma di pubblicazione.
         * Piattaforme sconosciute: restituisce il valore grezzo.
         *
         * @param string $platform Slug piattaforma.
         * @return string
         */
        public static function platform_label_it( $platform ) {
                $map = array(
                        'blog'      => 'Blog',
                        'twitter'   => 'Twitter/X',
                        'facebook'  => 'Facebook',
                        'linkedin'  => 'LinkedIn',
                        'instagram' => 'Instagram',
                );

                $key = strtolower( trim( (string) $platform ) );

                return isset( $map[ $key ] ) ? $map[ $key ] : (string) $platform;
        }

        /**
         * Normalizza le impostazioni per il form admin: ogni valore diventa
         * una stringa o un numero sicuro per esc_attr (niente bool/null/array).
         *
         * - bool  → '1' / '0'
         * - null  → ''
         * - array → lista join con virgola (es. social_platforms)
         * - altro → ''
         *
         * @param array $settings Impostazioni grezze (es. Nexus_DB::get_settings()).
         * @return array
         */
        public static function format_settings_for_form( $settings ) {
                $settings = is_array( $settings ) ? $settings : array();

                if ( class_exists( 'Nexus_DB' ) ) {
                        $settings = wp_parse_args( $settings, Nexus_DB::default_settings() );
                }

                $form = array();

                foreach ( $settings as $key => $value ) {
                        $key = (string) $key;

                        if ( is_bool( $value ) ) {
                                $form[ $key ] = $value ? '1' : '0';
                                continue;
                        }

                        if ( null === $value ) {
                                $form[ $key ] = '';
                                continue;
                        }

                        if ( is_scalar( $value ) ) {
                                $form[ $key ] = is_int( $value ) || is_float( $value ) ? $value : (string) $value;
                                continue;
                        }

                        if ( is_array( $value ) ) {
                                $form[ $key ] = implode( ',', array_map( 'strval', $value ) );
                                continue;
                        }

                        $form[ $key ] = '';
                }

                return $form;
        }

        /* ============ AI POLISH OUTPUT (v1.1.10, WP7) ============ */

        /**
         * Pulizia deterministica dell'output generato da un LLM (WP7 "AI polish",
         * ispirata al post-processing di WP Automatic P4 ma in versione conservativa:
         * nessuna trasformazione rischiosa, nessuna interpretazione semantica —
         * se un caso è ambiguo il contenuto viene lasciato com'è).
         *
         * Pipeline (nell'ordine):
         *  1. normalizza CRLF/CR → LF e clip difensiva dell'input a 200.000 caratteri;
         *  2. rimuove i fence markdown residui: il fence ```html … ``` / ``` … ```
         *     che avvolge TUTTO il contenuto e le righe fence orfane;
         *  3. un-escape dell'HTML codificato in entità (&lt;p&gt; → <p>) SOLO se il
         *     contenuto NON contiene già tag reali (guard documentata: se ci sono
         *     tag veri il decode corromperebbe esempi di codice legittimi) e gestisce
         *     il wrapper <pre><code>…</code></pre> con l'inner codificato;
         *  4. estrae il contenuto di <body> se il modello ha prodotto un documento
         *     HTML completo (DOMDocument + libxml_use_internal_errors, fallback regex);
         *  5. rimuove SOLO un eventuale wrapper <div> unico esterno senza attributi
         *     (unwrap con guard di bilanciamento). Nessun <h1> viene toccato: il
         *     titolo non è noto in questo contesto;
         *  6. se la PRIMA riga è un titolo racchiuso tra virgolette dritte "…" o
         *     caporali «…» (riga singola, max 300 caratteri) le rimuove;
         *  7. rimuove i placeholder di template non risolti ({title}, [matched_content],
         *     ecc. — whitelist di token noti nelle due forme {x} e [x]; la forma [x]
         *     non è toccata se seguita da "(" perché sarebbe un link markdown);
         *  8. conversione markdown→HTML MINIMA solo se il contenuto è markdown puro
         *     (guard: nessun tag di blocco HTML presente E almeno una riga inizia con
         *     #, -, * o "1."): heading → h1…h6, **bold** → <strong>, *italic* → <em>,
         *     [testo](url http/https) → <a>, righe di lista consecutive → <ul>/<ol>
         *     con <li>. I paragrafi restano testo semplice (nessun <p> inventato,
         *     nessun nl2br): il rendering WordPress li gestisce via wpautop;
         *  9. normalizza 3+ newline consecutivi a 2 e trima.
         *
         * NON è un sanitizer di sicurezza: è pulizia di formato. La sanitizzazione
         * di sicurezza resta a valle, dove il contenuto viene salvato/pubblicato.
         *
         * Mai fatale: tutto in try/catch Throwable; su errore interno ritorna
         * l'input (già clip) senza trasformazioni. Contratto per l'integrazione
         * nella rewrite della wave successiva: string (o null) in → string out.
         *
         * @param string|null $html Output AI (HTML atteso, markdown tollerato).
         * @return string Contenuto ripulito ('' se input vuoto).
         */
        public static function clean_ai_content( $html ) {
                $html = ( null === $html ) ? '' : (string) $html;
                if ( '' === trim( $html ) ) {
                        return '';
                }

                // Clip difensiva: output LLM anomali (loop, risposte enormi) non
                // devono mettere in ginocchio la pipeline di pulizia.
                if ( function_exists( 'mb_substr' ) ) {
                        $html = mb_substr( $html, 0, 200000 );
                } else {
                        $html = substr( $html, 0, 200000 );
                }

                $original = $html;

                try {
                        $html = self::ai_normalize_eol( $html );
                        $html = self::ai_strip_code_fences( $html );
                        $html = self::ai_unescape_html( $html );
                        $html = self::ai_extract_body( $html );
                        $html = self::ai_unwrap_single_div( $html );
                        $html = self::ai_strip_quoted_title( $html );
                        $html = self::ai_strip_template_placeholders( $html );
                        $html = self::ai_markdown_to_html( $html );
                        $html = self::ai_collapse_newlines( $html );
                } catch ( Throwable $e ) {
                        // Mai fatale: restituisce l'input normale, senza trasformazioni.
                        return trim( $original );
                }

                return trim( $html );
        }

        /**
         * Flag di qualità dell'output AI (WP7): lista onesta di etichette per il
         * log attività. NON blocca nulla e non modifica il contenuto: serve solo a
         * rendere visibili i casi limite dell'output del modello.
         *
         * Flag possibili:
         *  - 'empty'             → input vuoto (o svuotato dalla pulizia);
         *  - 'escaped_html'      → l'input conteneva tag codificati in entità
         *                          (&lt;p&gt;…): il modello ha fatto l'escape;
         *  - 'markdown_residual' → l'input conteneva tracce markdown (fence,
         *                          heading #, liste, **bold**), o ne restano dopo
         *                          la pulizia (contenuto misto/ambiguo): il
         *                          modello ha risposto in markdown;
         *  - 'ok'                → nessun problema rilevato.
         *
         * I flag riportano ciò che è stato OSSERVATO (un 'escaped_html' già
         * riparato da clean_ai_content viene comunque segnalato): il log resta
         * onesto anche quando la pulizia ha corretto il problema.
         *
         * @param string|null $html Output AI.
         * @return array Lista di flag (stringhe).
         */
        public static function ai_content_quality_flags( $html ) {
                $raw = ( null === $html ) ? '' : (string) $html;
                if ( '' === trim( $raw ) ) {
                        return array( 'empty' );
                }

                $flags = array();

                if ( self::ai_looks_escaped( $raw ) ) {
                        $flags[] = 'escaped_html';
                }

                // Markdown OSSERVATO nell'input (anche se la pulizia lo converte con
                // successo): il modello doveva rispondere in HTML/JSON, il segnale
                // resta utile al log. Il controllo sull'output pulito copre il caso
                // misto/ambiguo (conversione non avvenuta, tracce residue).
                if ( self::ai_has_markdown_residual( $raw ) ) {
                        $flags[] = 'markdown_residual';
                }

                $clean = self::clean_ai_content( $raw );

                if ( '' === trim( $clean ) ) {
                        $flags[] = 'empty';
                        return $flags;
                }

                if ( ! in_array( 'markdown_residual', $flags, true ) && self::ai_has_markdown_residual( $clean ) ) {
                        $flags[] = 'markdown_residual';
                }

                if ( empty( $flags ) ) {
                        $flags[] = 'ok';
                }

                return $flags;
        }

        /* ============ HELPER PRIVATI ============ */

        /**
         * Normalizza i fine riga a LF (l'output dei modelli spesso mescola CRLF/CR).
         *
         * @param string $text Testo.
         * @return string
         */
        private static function ai_normalize_eol( $text ) {
                return str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
        }

        /**
         * True se il testo contiene entità HTML che codificano tag (segno che il
         * modello ha fatto l'escape dell'output): &lt;p&gt;, &lt;div …, ecc.
         *
         * @param string $text Testo.
         * @return bool
         */
        private static function ai_looks_escaped( $text ) {
                return (bool) preg_match( '/&lt;\/?(?:p|div|h[1-6]|ul|ol|li|br|blockquote|table|thead|tbody|tr|td|th|strong|em|b|i|span|a|img|figure|figcaption|pre|code)\b/i', (string) $text );
        }

        /**
         * True se il testo contiene già tag HTML reali (guard dell'un-escape).
         * Volutamente severa: falso positivo = nessuna trasformazione (sicuro).
         *
         * @param string $text Testo.
         * @return bool
         */
        private static function ai_has_real_tags( $text ) {
                return (bool) preg_match( '/<(?:p|div|h[1-6]|ul|ol|li|br|blockquote|table|strong|em|b|i|span|a|img|figure|pre|code)\b/i', (string) $text );
        }

        /**
         * Rimuove i fence markdown residui:
         *  - il fence che avvolge TUTTO il contenuto (```html\n…\n``` / ```\n…\n```):
         *    il fence di chiusura considerato è l'ULTIMO (l'ancora \s*$ lo impone);
         *  - le righe fence orfane rimaste (righe composte solo da ``` opzionalmente
         *    seguite da un linguaggio), tipiche di risposte troncate o innestate.
         *
         * @param string $text Testo.
         * @return string
         */
        private static function ai_strip_code_fences( $text ) {
                $text = (string) $text;

                // 1. Wrapper completo: prima riga ```[lang], ultima riga ```.
                if ( preg_match( '/^\s*```[a-zA-Z0-9_-]*\s*\n([\s\S]*?)\n?\s*```\s*$/', $text, $m ) && isset( $m[1] ) ) {
                        $text = (string) $m[1];
                }

                // 2. Fence orfani: righe che contengono solo il marker.
                $stripped = preg_replace( '/^\s*```[a-zA-Z0-9_-]*\s*$/m', '', $text );

                return ( null === $stripped ) ? $text : (string) $stripped;
        }

        /**
         * Un-escape dell'HTML codificato in entità: il modello a volte risponde con
         * &lt;p&gt;…&lt;/p&gt; (tutto l'output codificato) o con un wrapper
         * <pre><code>&lt;p&gt;…&lt;/p&gt;</code></pre>. Trasformazione applicata
         * SOLO se il contenuto NON contiene già tag reali (guard sensata e
         * documentata): in quel caso ogni entità è HTML inteso e il decode è
         * sicuro; se ci sono tag veri mescolati alle entità il decode potrebbe
         * corrompere esempi di codice legittimi e il testo resta com'è.
         *
         * @param string $text Testo.
         * @return string
         */
        private static function ai_unescape_html( $text ) {
                $text = (string) $text;

                // Wrapper <pre><code>…</code></pre>: si valuta l'INNER, il wrapper
                // viene rimosso solo se l'inner è codificato (altrimenti potrebbe
                // essere un blocco di codice legittimo e resta intatto).
                if ( preg_match( '/^\s*<pre[^>]*>\s*<code[^>]*>([\s\S]*?)<\/code>\s*<\/pre>\s*$/i', $text, $m ) && isset( $m[1] ) ) {
                        $inner = (string) $m[1];
                        if ( self::ai_looks_escaped( $inner ) && ! self::ai_has_real_tags( $inner ) ) {
                                return html_entity_decode( $inner, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                        }
                        return $text;
                }

                if ( ! self::ai_looks_escaped( $text ) || self::ai_has_real_tags( $text ) ) {
                        return $text;
                }

                // Un solo passaggio di decode: nessun loop su doppio-escaping.
                return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        }

        /**
         * Estrae il contenuto di <body> quando il modello ha prodotto un documento
         * HTML completo (DOCTYPE/<html>/<body>). DOMDocument con errori libxml
         * silenziati (libxml_use_internal_errors) e trick encoding UTF-8; fallback
         * regex se DOMDocument non è disponibile o fallisce. Senza <body> il testo
         * torna invariato.
         *
         * @param string $text Testo.
         * @return string
         */
        private static function ai_extract_body( $text ) {
                $text = (string) $text;
                if ( false === stripos( $text, '<body' ) ) {
                        return $text;
                }

                if ( class_exists( 'DOMDocument' ) ) {
                        $prev  = libxml_use_internal_errors( true );
                        $inner = '';
                        try {
                                $dom = new DOMDocument();
                                // Trick encoding: senza dichiarazione loadHTML assume
                                // ISO-8859-1 e l'UTF-8 verrebbe serializzato a entità.
                                if ( $dom->loadHTML( '<?xml encoding="utf-8"?>' . $text ) ) {
                                        $body = $dom->getElementsByTagName( 'body' )->item( 0 );
                                        if ( null !== $body ) {
                                                foreach ( $body->childNodes as $node ) {
                                                        $inner .= $dom->saveHTML( $node );
                                                }
                                        }
                                }
                        } catch ( Throwable $e ) {
                                $inner = '';
                        }
                        libxml_clear_errors();
                        libxml_use_internal_errors( $prev );

                        if ( '' !== trim( $inner ) ) {
                                return $inner;
                        }
                }

                // Fallback regex: tutto ciò che sta tra <body…> e l'ultimo </body>.
                if ( preg_match( '/<body[^>]*>([\s\S]*)<\/body>/i', $text, $m ) && isset( $m[1] ) ) {
                        return (string) $m[1];
                }
                return $text;
        }

        /**
         * Rimuove SOLO un eventuale wrapper <div> unico esterno SENZA attributi
         * (i modelli spesso avvolgono tutto in <div>…</div>). Un solo livello,
         * niente loop; guard di bilanciamento: se l'inner contiene <div>
         * sbilanciati il primo </div> non chiuderebbe il nostro wrapper e non
         * si unwrappa. Nessun <h1> toccato: il titolo non è noto in questo contesto.
         *
         * @param string $text Testo.
         * @return string
         */
        private static function ai_unwrap_single_div( $text ) {
                $text = (string) $text;
                if ( ! preg_match( '/^\s*<div>\s*([\s\S]*?)\s*<\/div>\s*$/i', $text, $m ) || ! isset( $m[1] ) ) {
                        return $text;
                }

                $inner = (string) $m[1];

                if ( preg_match_all( '/<\/?div\b/i', $inner, $tags ) ) {
                        $depth = 0;
                        foreach ( $tags[0] as $tag ) {
                                $depth += ( 0 === strpos( (string) $tag, '</' ) ) ? -1 : 1;
                                if ( $depth < 0 ) {
                                        return $text; // </div> orfano prima dell'apertura: non è un wrapper pulito.
                                }
                        }
                        if ( 0 !== $depth ) {
                                return $text; // <div> aperti non chiusi nell'inner: non si unwrappa.
                        }
                }

                return $inner;
        }

        /**
         * Titolo tra virgolette: se la PRIMA riga è interamente racchiusa tra
         * virgolette dritte ("…") o caporali («…») le rimuove lasciando il testo.
         * Solo riga singola e ragionevolmente corta (max 300 caratteri): una
         * citazione lunga legittima non viene toccata.
         *
         * @param string $text Testo.
         * @return string
         */
        private static function ai_strip_quoted_title( $text ) {
                $text   = (string) $text;
                $pieces = explode( "\n", $text );
                $first  = trim( (string) $pieces[0] );

                if ( '' === $first || ( function_exists( 'mb_strlen' ) ? mb_strlen( $first ) : strlen( $first ) ) > 300 ) {
                        return $text;
                }

                $inner = null;
                if ( preg_match( '/^"(.+)"$/u', $first, $m ) || preg_match( '/^«(.+)»$/u', $first, $m ) ) {
                        $inner = trim( (string) $m[1] );
                }

                if ( null === $inner || '' === $inner ) {
                        return $text;
                }

                $pieces[0] = $inner;
                return implode( "\n", $pieces );
        }

        /**
         * Placeholder di template non risolti ({title}, [matched_content], …):
         * sono errori di pipeline (il template non è stato valorizzato) e non
         * devono finire pubblicati. Whitelist conservativa dei token noti, nelle
         * due forme {token} e [token]; la forma [token] NON viene toccata se
         * seguita da "(" (sarebbe il testo di un link markdown [testo](url)).
         *
         * @param string $text Testo.
         * @return string
         */
        private static function ai_strip_template_placeholders( $text ) {
                $tokens = 'title|post_title|page_title|subtitle|content|post_content|original_content|excerpt|summary'
                        . '|matched_content|keyword|keywords|category|categories|tag|tags'
                        . '|source|source_name|source_title|source_url|source_link|feed_title'
                        . '|item_title|item_content|item_url|item_description|date|time|author'
                        . '|image|featured_image|thumbnail|image_url|prompt|gpt';

                $clean = preg_replace( '/\{\s*(?:' . $tokens . ')\s*\}/i', '', (string) $text );
                $clean = preg_replace( '/\[\s*(?:' . $tokens . ')\s*\](?!\s*\()/i', '', ( null === $clean ) ? '' : (string) $clean );

                return ( null === $clean ) ? (string) $text : (string) $clean;
        }

        /**
         * Conversione markdown→HTML MINIMA, SOLO se il contenuto sembra markdown
         * puro. Guard (documentata nel metodo pubblico): nessun tag di blocco HTML
         * già presente (p/div/h1-6/ul/ol/li/blockquote/table/pre/figure) E almeno
         * una riga inizia con un marcatore markdown (#, -, * o "1."). Se la guard
         * fallisce: ZERO trasformazioni (contenuto ambiguo lasciato com'è).
         *
         * Supporta: heading #…###### → h1…h6, **bold** → <strong>, *italic* →
         * <em>, [testo](url http/https) → <a>, righe "- "/"* " consecutive →
         * <ul><li>, righe "N. " consecutive → <ol><li>. I paragrafi restano testo
         * semplice (nessun <p> inventato, nessun nl2br): WordPress li gestisce.
         *
         * @param string $text Testo.
         * @return string
         */
        private static function ai_markdown_to_html( $text ) {
                $text = (string) $text;

                if ( preg_match( '/<(?:p|div|h[1-6]|ul|ol|li|blockquote|table|pre|figure)\b/i', $text ) ) {
                        return $text; // c'è già HTML strutturato: non è markdown puro.
                }
                if ( ! preg_match( '/^\s*(?:#{1,6}\s|- |\* |\d+\.\s)/m', $text ) ) {
                        return $text; // nessun marcatore markdown a inizio riga.
                }

                $lines = explode( "\n", $text );
                $out   = array();
                $list  = null; // 'ul' | 'ol' | null

                $flush_list = static function () use ( &$out, &$list ) {
                        if ( null !== $list ) {
                                $out[] = '</' . $list . '>';
                                $list  = null;
                        }
                };

                foreach ( $lines as $line ) {
                        $trimmed = trim( $line );

                        // Liste: apri/continua/chiudi <ul>/<ol>.
                        if ( preg_match( '/^[-*]\s+(.*)$/', $trimmed, $m ) ) {
                                if ( 'ul' !== $list ) {
                                        $flush_list();
                                        $list  = 'ul';
                                        $out[] = '<ul>';
                                }
                                $out[] = '<li>' . self::ai_md_inline( (string) $m[1] ) . '</li>';
                                continue;
                        }
                        if ( preg_match( '/^\d+\.\s+(.*)$/', $trimmed, $m ) ) {
                                if ( 'ol' !== $list ) {
                                        $flush_list();
                                        $list  = 'ol';
                                        $out[] = '<ol>';
                                }
                                $out[] = '<li>' . self::ai_md_inline( (string) $m[1] ) . '</li>';
                                continue;
                        }

                        $flush_list();

                        if ( '' === $trimmed ) {
                                $out[] = '';
                                continue;
                        }

                        // Heading: da ###### (h6) a # (h1).
                        if ( preg_match( '/^(#{1,6})\s+(.*)$/', $trimmed, $m ) ) {
                                $level = strlen( (string) $m[1] );
                                $out[] = '<h' . $level . '>' . self::ai_md_inline( trim( (string) $m[2] ) ) . '</h' . $level . '>';
                                continue;
                        }

                        $out[] = self::ai_md_inline( $trimmed );
                }
                $flush_list();

                return implode( "\n", $out );
        }

        /**
         * Formattazione inline markdown MINIMA su una riga: **bold**, *italic* e
         * link [testo](url). Solo URL assoluti http/https (niente relativi o schemi
         * insoliti, URL passato a esc_url). Nessun escaping del testo: il contenuto
         * resta quello del modello (clean_ai_content non è un sanitizer di sicurezza).
         *
         * @param string $line Riga singola.
         * @return string
         */
        private static function ai_md_inline( $line ) {
                $line = (string) $line;

                // Link: [testo](https://…) — prima di bold/italic (il testo può
                // contenere asterischi).
                $linked = preg_replace_callback(
                        '/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/i',
                        static function ( $matches ) {
                                return '<a href="' . esc_url( (string) $matches[2] ) . '">' . (string) $matches[1] . '</a>';
                        },
                        $line
                );
                if ( null !== $linked ) {
                        $line = $linked;
                }

                // Bold: **testo** (prima dell'italic, che altrimenti mangerebbe **).
                $bold = preg_replace( '/\*\*([^*\n]+)\*\*/', '<strong>$1</strong>', $line );
                if ( null !== $bold ) {
                        $line = $bold;
                }

                // Italic: *testo* (niente spazi o asterischi al bordo interno: evita
                // i falsi positivi tipo "3 * 4").
                $italic = preg_replace( '/(^|[\s(])\*([^\s*][^*\n]*?)\*(?=[\s.,;:!?)]|$)/', '$1<em>$2</em>', $line );

                return ( null === $italic ) ? $line : (string) $italic;
        }

        /**
         * Normalizza le righe vuote: righe di soli spazi/tab → vuote, poi 3+ newline
         * consecutivi → 2. I paragrafi esistenti NON vengono toccati (no nl2br).
         *
         * @param string $text Testo.
         * @return string
         */
        private static function ai_collapse_newlines( $text ) {
                $clean = preg_replace( '/^[ \t]+$/m', '', (string) $text );
                $clean = preg_replace( '/\n{3,}/', "\n\n", ( null === $clean ) ? '' : (string) $clean );

                return ( null === $clean ) ? (string) $text : (string) $clean;
        }

        /**
         * True se il testo ripulito mostra ancora tracce markdown (fence, heading,
         * liste a inizio riga, **bold** residuo). Euristica per i flag di qualità:
         * non blocca e non trasforma nulla.
         *
         * @param string $text Testo ripulito.
         * @return bool
         */
        private static function ai_has_markdown_residual( $text ) {
                $text = (string) $text;
                if ( '' === trim( $text ) ) {
                        return false;
                }
                if ( preg_match( '/^\s*```/m', $text ) ) {
                        return true;
                }
                if ( preg_match( '/^\s*#{1,6}\s+\S/m', $text ) ) {
                        return true;
                }
                if ( preg_match( '/^\s*[-*]\s+\S/m', $text ) ) {
                        return true;
                }
                if ( preg_match( '/\*\*[^*\n]+\*\*/', $text ) ) {
                        return true;
                }
                return false;
        }

        /**
         * Plurale italiano: "1 minuto" / "5 minuti".
         *
         * @param int    $count       Numero.
         * @param string $singular    Forma singolare.
         * @param string $plural      Forma plurale.
         * @return string
         */
        private static function plural_it( $count, $singular, $plural ) {
                return 1 === (int) $count ? '1 ' . $singular : (int) $count . ' ' . $plural;
        }
}
