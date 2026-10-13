<?php
/**
 * Nexus Readability — estrazione full-content "pulita" da HTML grezzo (v1.1.10, WP3 Parte 1).
 *
 * Clean-room implementation dell'algoritmo Arc90 Readability (algoritmo di dominio
 * pubblico): niente librerie copiate, niente composer, niente dipendenze esterne.
 * Solo ext/dom (DOMDocument + DOMXPath-free, API DOM classica PHP 7.4) e PCRE.
 *
 * FILOSOFIA (stile del progetto, v. class-nexus-tools.php):
 *  - Tutto statico, errori in italiano descrittivi, MAI fatale: ogni percorso di
 *    errore ritorna l'array atteso dal contratto; ogni blocco critico è dentro
 *    try/catch(\Throwable).
 *  - ZERO MOCK: la classe non inventa mai contenuti. Se non c'è contenuto leggibile
 *    ritorna ok=false con un errore onesto. L'HTML in uscita contiene SOLO nodi
 *    realmente presenti nella pagina sorgente (testi e immagini).
 *  - NESSUNA RICHIESTA HTTP: la classe RICEVE $html già scaricato (chi chiama
 *    fetcha, es. il dispatcher fonti o Nexus_Tools::http_get). Nessun fetch qui.
 *
 * ALGORITMO extract() (Arc90 adattato, pesi documentati):
 *  1. Guard: input clip a 500.000 caratteri, scarto binario/SVG, parse DOMDocument
 *     con il prefix trick '<?xml encoding="utf-8">' (input atteso UTF-8; best
 *     effort iconv per le fonti legacy iso-8859-1/windows-1252).
 *  2. Titolo (best effort): og:title → <title> → primo <h1>. Si legge PRIMA delle
 *     rimozioni (l'<h1> sta spesso dentro <header> che viene rimosso).
 *  3. <base href> rispettato come base per la risoluzione dei link relativi.
 *  4. Lazy-load fix PRIMA della rimozione di <noscript>: data-src / data-original /
 *     data-lazy-src / data-srcset / srcset → src sulle <img> con src vuota o
 *     placeholder "data:"; "rescue" del vero <img> dentro <noscript> sul <img>
 *     sibling pigro precedente.
 *  5. Relative → absolute su a[href] e img[src] (protocol-relative //, root /,
 *     ../ e path relativi; fragment e javascript/mailto/tel scartati).
 *  6. Pre-pulizia: rimozione (con discendenti) di script, style, noscript, nav,
 *     header, footer, aside, form, iframe e di altri elementi non editoriali
 *     (template, button, select, textarea, svg, canvas, video, audio, object,
 *     embed, dialog, map, meta, link, base, source, track, input); unwrap di
 *     <picture> (l'<img> interna deve sopravvivere); rimozione di commenti HTML
 *     e processing instruction.
 *  7. Scoring: candidati = <p> (testo ≥ 25 caratteri), <pre> e <blockquote>
 *     (testo non vuoto), <div> con figli <p> diretti (peso 5) o con testo diretto
 *     ≥ 25 caratteri (peso 1). Pesi base: p=1, pre=3, blockquote=2, div=5 (con <p>
 *     diretti) o div=1 (solo testo diretto). Bonus classe/id: +25 per token
 *     positivo, −25 per token negativo (word-boundary sul value di class+id).
 *     Moltiplicatore (1 − link-density) sulla contribuzione. Arc90: contribuzione
 *     intera al candidato e al padre, metà al nonno.
 *  8. Top ancestor = elemento con score massimo (primo in ordine documento in caso
 *     di pari merito; se tutti ≤ 0 → <body>); se è un candidato foglia (p/pre/
 *     blockquote/heading/a/span/img) si sale al padre.
 *  9. Pulizia del sottoalbero scelto: rimozione dei nodi con score accumulato
 *     ≤ −20 (comment/sidebar/sponsor forti), <a> senza href http(s) → unwrap,
 *     tag fuori whitelist → unwrap (o rimozione se senza figli), <br> consecutivi
 *     collassati a uno, attributi ridotti alla whitelist (a: href; img: src+alt;
 *     td/th: colspan+rowspan), div con figli block → unwrap, div solo inline →
 *     rinominato <p> (gli span restano span: convertirli in p romperebbe i
 *     paragrafi — decisione documentata), immagini: solo src http(s) assoluta,
 *     massimo 6 in ordine documento, alt preservato, nodi vuoti rimossi.
 * 10. Serializzazione per nodi top-level (ogni nodo è un sottoalbero completo →
 *     HTML sempre bilanciato) con clip a 50.000 caratteri.
 *
 * CONTRATTO PUBBLICO (usato da dispatcher fonti / adapter / pipeline):
 * Nexus_Readability::extract( $html, $url = '' )
 *     → array( 'ok'      => bool,   // true = contenuto estratto
 *              'title'   => string, // titolo best effort ('' se introvabile)
 *              'content' => string, // HTML pulito e clipato (50.000 caratteri)
 *              'text'    => string, // plain text (strip_tags + decode)
 *              'error'   => string  // '' se ok; messaggio IT onesto altrimenti )
 *     La classe NON fa richieste HTTP: $html è la risposta già scaricata dal
 *     chiamante; $url serve SOLO per risolvere i link relativi ('' ammesso: i
 *     relativi restano tali e le immagini relative vengono scartate).
 * Nexus_Readability::validate( $title, $content_html, $min_chars = 300 )
 *     → array( 'ok' => bool, 'reason' => string IT )
 *     Pattern WP Automatic (P2): almeno UNA parola significativa del titolo
 *     (> 3 caratteri, non stopword IT/EN) deve comparire nel contenuto (word
 *     boundary, case-insensitive) E il testo plain deve raggiungere $min_chars.
 *     Da chiamare DOPO extract(): se fallisce → fallback alla description/summary.
 * Nexus_Readability::is_svg_or_binary_guard( $html )
 *     → bool: TRUE = plausibilmente HTML testuale (si può parsare); FALSE =
 *     binario/SVG/vuoto (NON parsare). Guardia economica sui primi 512 byte.
 * Nexus_Readability::cleanup_lazy_and_relative( $html, $base_url )
 *     → string: SOLO lazy-load fix (+ rescue noscript) e relative→absolute su
 *     a/img, senza scoring né altre pulizie. Riutilizzabile dal dispatcher fonti
 *     e dagli adapter social per le description. In caso di fallimento ritorna
 *     l'input invariato (best effort, mai eccezioni). Rimuove comunque
 *     script/style/noscript: contenuto di script in una description finirebbe nei
 *     prompt AI e nei messaggi social (superficie d'iniezione inutile).
 *
 * LIMITI NOTI (onestà tecnica):
 *  - Input atteso UTF-8; charset dichiarati latin1/windows-1252 vengono convertiti
 *    best effort con iconv (se disponibile), altri charset possono dare mojibake.
 *  - Niente analisi "sibling" di Arc90 né punteggio per-paragrafo sommato alla
 *    maniera classica sui DIV senza <p>: le pagine con moltissimi paragrafi
 *    cortissimi (< 25 caratteri) possono sotto-scorare (fallback: <body>).
 *  - La guard binaria non è un magic-byte detector completo: PDF/garbage passano
 *    la guard ma cadono alla validazione titolo→contenuto (seconda rete).
 *  - Il clipping a 50.000 caratteri avviene per nodi top-level (HTML bilanciato);
 *    l'unico caso limite (UN solo nodo > 50.000 caratteri senza testo) può
 *    troncare su un confine di tag: documentato e raro.
 *
 * SICUREZZA: nessun echo/print_r/log; nessuna fuga di dati; mai eccezioni
 * propagate; nessun requisito PHP oltre 7.4 (niente arrow fn, match, ?->, enum,
 * niente API DOM di PHP 8: previousElementSibling, remove(), ecc. NON usate).
 *
 * @package Nexus_News_AI
 * @since   1.1.10 Full-content extraction (WP3 Parte 1, Task B2).
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Readability {

        /* ============ LIMITI E PESI (tutti documentati nel docblock di classe) ============ */

        const MAX_INPUT_CHARS     = 500000; // clip dell'input PRIMA del parse (protezione DoS)
        const MAX_CONTENT_CHARS   = 50000;  // clip del content HTML in uscita
        const MAX_IMAGES          = 6;      // immagini mantenute nel contenuto (prime in ordine documento)
        const TITLE_CLIP          = 300;    // lunghezza massima del titolo estratto
        const MIN_CANDIDATE_CHARS = 25;     // testo minimo per un candidato p/div (stile Arc90)
        const GRANDPARENT_FACTOR  = 0.5;    // quota di contribuzione arrivata al nonno (Arc90)
        const CLASS_BONUS         = 25;     // bonus/malus per token di classe/id (Arc90)
        const STRONG_NEGATIVE     = -20;    // soglia di rimozione per score fortemente negativo
        const MAX_VALIDATE_WORDS  = 30;     // cap parole controllate in validate() (anti-pathologie)
        const MAX_VALIDATE_CHARS  = 200000; // cap del testo plain sottoposto ai regex in validate()

        /** Token positivi per class/id (Arc90), separati da spazio. */
        const POSITIVE_CLASSES = 'article body content entry main post text blog story';

        /** Token negativi per class/id (Arc90); "com-" è un prefisso voluto. */
        const NEGATIVE_CLASSES = 'comment com- contact footer link meta promo related scroll shoutbox sidebar sponsor shopping tags widget';

        /** Stopword IT/EN brevi per validate() (parole ≤ 3 caratteri sono già escluse). */
        const STOPWORDS = 'sono stato stati essere esse questo questa questi queste quello quella dello degli dalla dagli dall sulla nella sulle come quando dove pero anche oppure inoltre infatti dunque quindi tutto tutta molto moltissima tanto tanta poco poca dopo prima durante sempre mai ancora senza sotto sopra tra fra verso mentre invece circa meno piu della and the for with that this these those from will would could should have has had been being their they them than then there here what when where why how into over under about after before more most other some such only own same very can now new also just like made many much both each which while whose';

        /** Tag ammessi nel contenuto finale (whitelist del task WP3). */
        private static $allowed_tags = array(
                'p', 'br', 'strong', 'em', 'b', 'i', 'u', 'a', 'ul', 'ol', 'li',
                'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'code',
                'img', 'figure', 'figcaption', 'table', 'thead', 'tbody', 'tr',
                'th', 'td', 'span', 'div',
        );

        /** Attributi ammessi per tag (tutto il resto viene rimosso, eventi inclusi). */
        private static $allowed_attrs = array(
                'a'   => array( 'href' ),
                'img' => array( 'src', 'alt' ),
                'td'  => array( 'colspan', 'rowspan' ),
                'th'  => array( 'colspan', 'rowspan' ),
        );

        /** Tag rimossi CON i discendenti nella pre-pulizia. */
        private static $kill_tags = array(
                'script', 'style', 'noscript', 'nav', 'header', 'footer', 'aside',
                'form', 'iframe', 'template', 'button', 'select', 'textarea',
                'svg', 'canvas', 'video', 'audio', 'object', 'embed', 'dialog',
                'map', 'meta', 'link', 'base', 'source', 'track', 'input',
        );

        /** Tag "block" usati per decidere se un div va sciolto o convertito in <p>. */
        private static $block_tags = array(
                'p', 'div', 'ul', 'ol', 'li', 'table', 'figure', 'blockquote',
                'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'figcaption',
                'thead', 'tbody', 'tr', 'td', 'th',
        );

        /* ============ CONTRATTO PUBBLICO ============ */

        /**
         * Estrae titolo + contenuto leggibile da un HTML già scaricato.
         *
         * Pipeline completa: guard → DOM → titolo → base href → lazy fix →
         * relative→absolute → pre-pulizia → scoring Arc90 → top ancestor →
         * pulizia → serializzazione con clip 50.000 → plain text.
         *
         * MAI fatale: qualunque problema ritorna l'array con ok=false e un
         * errore italiano onesto (HTML vuoto, binario, indigeribile, senza
         * contenuto leggibile...).
         *
         * @param string $html HTML della pagina (atteso UTF-8), già scaricato dal chiamante.
         * @param string $url  URL sorgente per la risoluzione dei relativi ('' ammesso).
         * @return array array( 'ok', 'title', 'content', 'text', 'error' ).
         */
        public static function extract( $html, $url = '' ) {
                $fail = array( 'ok' => false, 'title' => '', 'content' => '', 'text' => '', 'error' => '' );

                // Clip dell'input PRIMA del parse: protezione DoS (contratto: 500k).
                $html = self::clip_chars( (string) $html, self::MAX_INPUT_CHARS );
                $url  = trim( (string) $url );

                if ( '' === trim( $html ) ) {
                        $fail['error'] = 'Readability: HTML in ingresso vuoto.';
                        return $fail;
                }

                if ( ! self::is_svg_or_binary_guard( $html ) ) {
                        $fail['error'] = 'Readability: la risposta non è HTML testuale (binario o SVG): parse bloccato dalla guard.';
                        return $fail;
                }

                try {
                        $doc = self::load_dom( $html );
                        if ( null === $doc ) {
                                $fail['error'] = 'Readability: il parser DOM non è riuscito a leggere l\'HTML (documento indigeribile).';
                                return $fail;
                        }

                        // Il titolo si legge PRIMA delle rimozioni (l'<h1> spesso sta in <header>).
                        $title = self::extract_title( $doc );

                        // Base per i relativi: $url, poi <base href> se presente e risolvibile.
                        $parts = self::base_parts( $url );
                        $btag  = self::base_tag_parts( $doc, $parts );
                        if ( null !== $btag ) {
                                $parts = $btag;
                        }

                        self::fix_lazy_images( $doc );
                        self::absolutize( $doc, $parts );
                        self::pre_clean( $doc );

                        $scores = new SplObjectStorage();
                        self::score_candidates( $doc, $scores );

                        $top = self::pick_top( $doc, $scores );
                        $top = self::clean_top( $top, $scores );

                        $content = self::serialize_top( $top );
                        $text    = self::plain_text( $content );

                        $has_image = (bool) preg_match( '/<img\s/i', $content );
                        if ( '' === trim( $text ) && ! $has_image ) {
                                $fail['title'] = $title;
                                $fail['error'] = 'Readability: nessun contenuto leggibile estratto dalla pagina.';
                                return $fail;
                        }

                        return array(
                                'ok'      => true,
                                'title'   => $title,
                                'content' => $content,
                                'text'    => $text,
                                'error'   => '',
                        );
                } catch ( \Throwable $e ) {
                        $fail['error'] = 'Readability: errore inatteso durante l\'estrazione (' . self::clip( $e->getMessage(), 200 ) . ').';
                        return $fail;
                }
        }

        /**
         * Valida la coerenza titolo→contenuto e la lunghezza minima (pattern WP Automatic P2).
         *
         * Regola 1: almeno UNA parola del titolo con più di 3 caratteri e non
         * stopword (lista IT/EN della classe) deve comparire nel contenuto,
         * con word boundary e confronto case-insensitive (mb_strtolower).
         * Regola 2: il testo plain del contenuto deve raggiungere $min_chars
         * (0/negativo = controllo disattivato).
         *
         * ReDoS: i pattern sono costruiti con preg_quote su parole corte
         * (max 30, cap della classe) senza quantificatori annidati → matching
         * lineare; nessun regex fornito dall'utente viene mai compilato.
         *
         * @param string $title        Titolo atteso (dal feed/dal risultato di extract).
         * @param string $content_html HTML del contenuto (output di extract o summary).
         * @param int    $min_chars    Minimo di caratteri di testo plain (default 300).
         * @return array array( 'ok' => bool, 'reason' => string IT ).
         */
        public static function validate( $title, $content_html, $min_chars = 300 ) {
                $title = trim( (string) $title );
                $plain = self::plain_text( (string) $content_html );
                $min   = max( 0, (int) $min_chars );

                $plain_len = self::text_len( trim( $plain ) );

                if ( '' === $title ) {
                        return array( 'ok' => false, 'reason' => 'Titolo vuoto: impossibile validare la corrispondenza titolo→contenuto.' );
                }

                if ( 0 === $plain_len ) {
                        return array( 'ok' => false, 'reason' => 'Contenuto vuoto: niente da validare.' );
                }

                $title_low = self::lower( self::clip_chars( $title, 2000 ) );
                $plain_low = self::lower( self::clip_chars( $plain, self::MAX_VALIDATE_CHARS ) );

                // Parole candidata: > 3 caratteri, non stopword, cap 30 (anti-pathologie).
                $candidates = array();
                $words      = preg_split( '/[^\p{L}\p{N}]+/u', $title_low, -1, PREG_SPLIT_NO_EMPTY );
                if ( ! is_array( $words ) ) {
                        // /u fallito (title non UTF-8 valido): fallback ASCII whitespace.
                        $words = preg_split( '/[^a-z0-9]+/', $title_low, -1, PREG_SPLIT_NO_EMPTY );
                        if ( ! is_array( $words ) ) {
                                $words = array();
                        }
                }
                foreach ( $words as $word ) {
                        if ( self::text_len( $word ) <= 3 ) {
                                continue;
                        }
                        if ( in_array( $word, self::stopwords(), true ) ) {
                                continue;
                        }
                        $candidates[] = $word;
                        if ( count( $candidates ) >= self::MAX_VALIDATE_WORDS ) {
                                break;
                        }
                }

                if ( empty( $candidates ) ) {
                        return array( 'ok' => false, 'reason' => 'Il titolo non contiene parole significative (solo stopwords o parole di ≤ 3 caratteri): validazione titolo→contenuto impossibile.' );
                }

                $found = false;
                foreach ( $candidates as $word ) {
                        if ( preg_match( '/\b' . preg_quote( $word, '/' ) . '\b/u', $plain_low ) ) {
                                $found = true;
                                break;
                        }
                }
                if ( ! $found ) {
                        return array( 'ok' => false, 'reason' => 'Nessuna parola significativa del titolo compare nel contenuto: probabile paywall, pagina vuota o contenuto non pertinente.' );
                }

                if ( $min > 0 && $plain_len < $min ) {
                        return array( 'ok' => false, 'reason' => 'Contenuto troppo corto: ' . $plain_len . ' caratteri di testo contro il minimo di ' . $min . '.' );
                }

                return array( 'ok' => true, 'reason' => 'ok: titolo coerente col contenuto e ' . $plain_len . ' caratteri di testo (minimo richiesto: ' . $min . ').' );
        }

        /**
         * Guardia rapida anti-binario/anti-SVG sui primi 512 byte.
         *
         * RITORNA TRUE se il contenuto è plausibilmente HTML testuale e si può
         * parsare; FALSE se è binario, SVG come elemento radice, vuoto o plain
         * text senza markup (le fonti web rompono spesso il parse).
         *
         * Criteri (documentati): byte NUL nei primi 512; BOM UTF-16; nessun
         * "<" nei primi 512 byte (non è HTML); <svg> come radice (prima di esso
         * solo whitespace/doctype/commenti/dichiarazione XML); più del 12,5% di
         * byte di controllo (euristica extra per garbage/PDF/zip).
         *
         * NON è un magic-byte detector completo (PDF testuali possono passare):
         * la seconda rete è validate() sul titolo→contenuto.
         *
         * @param string $html Contenuto da verificare.
         * @return bool TRUE = proseguibili col parse; FALSE = bloccare.
         */
        public static function is_svg_or_binary_guard( $html ) {
                $head = self::clip_chars( (string) $html, 512 );

                if ( '' === $head ) {
                        return false; // vuoto: niente da parsare (contratto conservativo)
                }

                if ( strpos( $head, "\x00" ) !== false ) {
                        return false; // NUL = binario (png/zip/gzip/eseguibili/pagine binarie)
                }

                // BOM UTF-16: per un parser UTF-8 è garantito il mojibake.
                if ( 0 === substr_compare( $head, "\xFF\xFE", 0, 2 ) || 0 === substr_compare( $head, "\xFE\xFF", 0, 2 ) ) {
                        return false;
                }

                if ( strpos( $head, '<' ) === false ) {
                        return false; // nessun markup nei primi 512 byte: plain text
                }

                // <svg> come RADICE: prima ammessi solo whitespace/doctype/commenti/dich. XML.
                $probe = preg_replace( '/^(?:\s+|<!--[\s\S]*?-->|<!DOCTYPE[^>]*>|<\?xml[^>]*\?>)+/i', '', $head );
                if ( is_string( $probe ) && 0 === stripos( $probe, '<svg' ) ) {
                        return false;
                }

                // Euristica extra: troppi byte di controllo = quasi certamente binario.
                $control = preg_match_all( '/[\x01-\x08\x0B-\x1F]/', $head );
                if ( $control > 64 ) {
                        return false;
                }

                return true;
        }

        /**
         * Helper riusabile: SOLO lazy-load fix (+ rescue noscript) e relative→absolute.
         *
         * Pensato per il dispatcher fonti e per gli adapter social che devono
         * correggere le description dei feed senza passare dallo scoring.
         * Rimuove anche script/style/noscript/template (superficie d'iniezione:
         * il codice script finirebbe nei prompt AI e nei messaggi social).
         *
         * Best effort MAI fatale: qualunque problema → ritorna l'input invariato.
         *
         * @param string $html     HTML (o description HTML) da correggere.
         * @param string $base_url URL base per i relativi ('' = solo lazy fix).
         * @return string HTML corretto, oppure l'input invariato in caso di fallimento.
         */
        public static function cleanup_lazy_and_relative( $html, $base_url ) {
                $html     = self::clip_chars( (string) $html, self::MAX_INPUT_CHARS );
                $base_url = trim( (string) $base_url );

                if ( '' === trim( $html ) ) {
                        return '';
                }

                try {
                        $doc = self::load_dom( $html );
                        if ( null === $doc ) {
                                return $html; // onesto: parse impossibile, input invariato
                        }

                        self::fix_lazy_images( $doc );

                        $parts = ( '' !== $base_url ) ? self::base_parts( $base_url ) : null;
                        self::absolutize( $doc, $parts );

                        // Rimozione minima anti-iniezione (documentata nel docblock di classe).
                        self::remove_tags( $doc, array( 'script', 'style', 'noscript', 'template' ) );

                        $body = $doc->getElementsByTagName( 'body' )->item( 0 );
                        if ( $body instanceof DOMElement ) {
                                return self::serialize_children( $body, self::MAX_INPUT_CHARS );
                        }

                        // Documenti senza body (rari): fallback su tutto l'elemento radice.
                        if ( $doc->documentElement instanceof DOMElement ) {
                                return self::serialize_children( $doc->documentElement, self::MAX_INPUT_CHARS );
                        }

                        return $html;
                } catch ( \Throwable $e ) {
                        return $html; // mai eccezioni propagate: input invariato
                }
        }

        /* ============ DOM / PARSE ============ */

        /**
         * Carica l'HTML in un DOMDocument UTF-8 senza mai emettere warning.
         *
         * - charset dichiarati iso-8859-1/windows-1252/latin1 → conversione
         *   best effort con iconv (//IGNORE) se disponibile;
         * - BOM e dichiarazioni XML pre-esistenti rimossi;
         * - prefix PI '<?xml encoding="utf-8">' per forzare libxml a leggere
         *   l'input come UTF-8 (trick noto di DOMDocument, ripristinato rimuovendo
         *   la PI dopo il parse);
         * - libxml_use_internal_errors(true) con ripristino SEMPRE del valore
         *   precedente (il progetto non ha ancora altri usi di libxml: qui si
         *   stabilisce il pattern, v. worklog Task B2);
         * - output encoding forzato a UTF-8 (senza, saveHTML emette entità
         *   numeriche per ogni carattere non-ASCII).
         *
         * @param string $html HTML già clipato e guardato dal chiamante.
         * @return DOMDocument|null null se il parse non è riuscito o ext/dom manca.
         */
        private static function load_dom( $html ) {
                if ( ! class_exists( 'DOMDocument' ) || ! function_exists( 'libxml_use_internal_errors' ) ) {
                        return null; // ambiente senza ext/dom: ritorno onesto, mai fatale
                }

                // Best effort charset legacy: le fonti che dichiarano latin1 sono
                // ancora comuni; //IGNORE scarta i byte illeggibili invece di troncare.
                $head = substr( $html, 0, 2048 );
                if ( is_string( $head ) && preg_match( '/charset\s*=\s*["\']?\s*(iso-8859-1|windows-1252|latin1)\b/i', $head ) && function_exists( 'iconv' ) ) {
                        $converted = @iconv( 'Windows-1252', 'UTF-8//IGNORE', $html );
                        if ( is_string( $converted ) && '' !== $converted ) {
                                $html = $converted;
                        }
                }

                // BOM UTF-8 e dichiarazione XML pre-esistente: fuori, prima del prefix.
                $html = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $html );
                $html = (string) preg_replace( '/^\s*<\?xml[^>]*\?>/i', '', $html );

                // Prefix PI: forza l'encoding d'ingresso a UTF-8 (il parser HTML di
                // libxml presumerebbe us-ascii). La PI viene rimossa dopo il parse.
                $html = '<?xml encoding="utf-8">' . $html;

                $prev = libxml_use_internal_errors( true );
                $doc  = null;
                $ok   = false;
                try {
                        $doc = new DOMDocument();
                        $ok  = $doc->loadHTML( $html, LIBXML_NOWARNING | LIBXML_NOERROR );
                } catch ( \Throwable $e ) {
                        $ok  = false;
                        $doc = null;
                }
                libxml_clear_errors();
                libxml_use_internal_errors( $prev ); // ripristino SEMPRE

                if ( ! $ok || ! $doc instanceof DOMDocument ) {
                        return null;
                }

                // Output UTF-8 reale (senza: saveHTML emette entità numeriche).
                $doc->encoding = 'UTF-8';

                // Rimozione della PI iniettata (primo figlio del documento).
                $first = $doc->firstChild;
                if ( $first instanceof DOMProcessingInstruction && 'xml' === $first->target ) {
                        $doc->removeChild( $first );
                }

                return $doc;
        }

        /* ============ TITOLO ============ */

        /**
         * Titolo best effort: og:title (property o name) → <title> → primo <h1>.
         *
         * og:title per primo perché di solito è il più pulito (senza suffisso
         * del sito tipo " - NomeSito.it" che invece spesso c'è in <title>).
         * textContent/getAttribute di DOM ritornano già il testo decodificato:
         * serve solo whitespace e clip.
         *
         * @param DOMDocument $doc Documento caricato.
         * @return string Titolo normalizzato ('' se introvabile).
         */
        private static function extract_title( $doc ) {
                $candidates = array();

                foreach ( $doc->getElementsByTagName( 'meta' ) as $meta ) {
                        if ( ! $meta instanceof DOMElement ) {
                                continue;
                        }
                        $prop = self::lower( $meta->getAttribute( 'property' ) );
                        $name = self::lower( $meta->getAttribute( 'name' ) );
                        if ( 'og:title' === $prop || 'og:title' === $name ) {
                                $candidates[] = trim( $meta->getAttribute( 'content' ) );
                        }
                }

                $title_el = $doc->getElementsByTagName( 'title' )->item( 0 );
                if ( $title_el instanceof DOMElement ) {
                        $candidates[] = trim( $title_el->textContent );
                }

                $h1 = $doc->getElementsByTagName( 'h1' )->item( 0 );
                if ( $h1 instanceof DOMElement ) {
                        $candidates[] = trim( $h1->textContent );
                }

                foreach ( $candidates as $candidate ) {
                        $candidate = self::norm( $candidate );
                        if ( '' !== $candidate ) {
                                return self::clip( $candidate, self::TITLE_CLIP );
                        }
                }

                return '';
        }

        /* ============ URL: BASE, RISOLUZIONE, ASSOLUTIZZAZIONE ============ */

        /**
         * Scompone un URL base in parti utili alla risoluzione dei relativi.
         *
         * @param string $url URL base.
         * @return array|null array( 'scheme', 'host', 'origin', 'path' ) o null se non http(s).
         */
        private static function base_parts( $url ) {
                $p = parse_url( trim( (string) $url ) );
                if ( ! is_array( $p ) || empty( $p['scheme'] ) || empty( $p['host'] ) ) {
                        return null;
                }
                $scheme = self::lower( (string) $p['scheme'] );
                if ( 'http' !== $scheme && 'https' !== $scheme ) {
                        return null;
                }
                return array(
                        'scheme' => $scheme,
                        'host'   => (string) $p['host'],
                        'origin' => $scheme . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ),
                        'path'   => isset( $p['path'] ) && '' !== (string) $p['path'] ? (string) $p['path'] : '/',
                );
        }

        /**
         * Legge <base href> e lo risolve contro l'URL della pagina.
         *
         * @param DOMDocument $doc       Documento caricato.
         * @param array|null  $url_parts Parti dell'URL della pagina (base_parts).
         * @return array|null Parti della base, o null se assente/non risolvibile.
         */
        private static function base_tag_parts( $doc, $url_parts ) {
                $base = $doc->getElementsByTagName( 'base' )->item( 0 );
                if ( ! $base instanceof DOMElement ) {
                        return null;
                }
                $href = trim( $base->getAttribute( 'href' ) );
                if ( '' === $href ) {
                        return null;
                }
                $resolved = self::resolve_url( $href, $url_parts );
                if ( '' === $resolved ) {
                        return null;
                }
                return self::base_parts( $resolved );
        }

        /**
         * Risolve un URL relativo contro le parti della base (risoluzione RFC-approx).
         *
         * Gestisce: URL assoluti http(s) (pass-through), protocol-relative "//",
         * root-relative "/", query-only "?", path relativi con "./" e "../",
         * frammenti (scartati: href="#..." → ''), schemi non http (javascript,
         * mailto, tel, data, ftp...) → '' (il chiamante li scarta).
         * Senza base ($parts null) passano solo gli assoluti.
         *
         * @param string     $rel   URL (assoluto o relativo) da risolvere.
         * @param array|null $parts Parti base (base_parts) o null.
         * @return string URL risolto, o '' se non risolvibile/da scartare.
         */
        private static function resolve_url( $rel, $parts ) {
                $rel = trim( (string) $rel );
                if ( '' === $rel ) {
                        return '';
                }

                // Assoluti http(s): nessuna trasformazione.
                if ( preg_match( '#^https?://#i', $rel ) ) {
                        return $rel;
                }

                // Schemi non attinenti al contenuto editoriale: scartati.
                if ( preg_match( '/^(?:javascript|mailto|tel|sms|data|about|ftp|file|blob):/i', $rel ) ) {
                        return '';
                }

                if ( ! is_array( $parts ) ) {
                        return ''; // senza base i relativi restano irrisolvibili
                }

                // Protocol-relative (//host/path): serve solo lo schema della base.
                if ( 0 === strpos( $rel, '//' ) ) {
                        return $parts['scheme'] . ':' . $rel;
                }

                // Frammento puro o frammento in coda: l'ancora interna non serve al contenuto.
                $rel = (string) preg_replace( '/#.*$/s', '', $rel );
                if ( '' === $rel ) {
                        return '';
                }

                // Root-relative e query-only.
                if ( '/' === $rel[0] ) {
                        return $parts['origin'] . $rel;
                }
                if ( '?' === $rel[0] ) {
                        return $parts['origin'] . (string) preg_replace( '/[^\/]*$/', '', $parts['path'] ) . $rel;
                }

                // Path relativo: merge col percorso della base (senza nome file),
                // poi risoluzione dei segmenti "." e "..".
                $dir  = (string) preg_replace( '/[^\/]*$/', '', $parts['path'] );
                $path = $dir . $rel;
                $out  = array();
                foreach ( explode( '/', $path ) as $segment ) {
                        if ( '' === $segment || '.' === $segment ) {
                                continue;
                        }
                        if ( '..' === $segment ) {
                                if ( count( $out ) > 0 ) {
                                        array_pop( $out );
                                }
                                continue;
                        }
                        $out[] = $segment;
                }
                return $parts['origin'] . '/' . implode( '/', $out );
        }

        /**
         * Riscrive a[href] e img[src] in assoluto (le <img> pigre sono già state fixate).
         *
         * href/src non risolvibili vengono rimossi (il cleanup li scarterà).
         * Con $parts null (nessun URL base) non si tocca nulla: i relativi
         * restano relativi (decisione documentata).
         *
         * @param DOMDocument $doc   Documento.
         * @param array|null  $parts Parti base o null.
         * @return void
         */
        private static function absolutize( $doc, $parts ) {
                if ( ! is_array( $parts ) ) {
                        return;
                }

                foreach ( $doc->getElementsByTagName( 'a' ) as $el ) {
                        if ( ! $el instanceof DOMElement ) {
                                continue;
                        }
                        $href = trim( $el->getAttribute( 'href' ) );
                        if ( '' === $href ) {
                                continue;
                        }
                        $resolved = self::resolve_url( $href, $parts );
                        if ( '' === $resolved ) {
                                $el->removeAttribute( 'href' );
                        } elseif ( $resolved !== $href ) {
                                $el->setAttribute( 'href', $resolved );
                        }
                }

                foreach ( $doc->getElementsByTagName( 'img' ) as $el ) {
                        if ( ! $el instanceof DOMElement ) {
                                continue;
                        }
                        $src = trim( $el->getAttribute( 'src' ) );
                        if ( '' === $src ) {
                                continue;
                        }
                        $resolved = self::resolve_url( $src, $parts );
                        if ( '' === $resolved ) {
                                $el->removeAttribute( 'src' );
                        } elseif ( $resolved !== $src ) {
                                $el->setAttribute( 'src', $resolved );
                        }
                }
        }

        /* ============ LAZY-LOAD FIX ============ */

        /**
         * Fix delle immagini pigre PRIMA della rimozione di <noscript>.
         *
         * 1. Su ogni <img> con src vuota o placeholder "data:": data-src →
         *    data-original → data-lazy-src → data-srcset → srcset (primo
         *    candidato del srcset, i candidati "data:" vengono saltati perché
         *    la virgola dentro il base64 romperebbe lo split).
         * 2. Rescue noscript: se un <noscript> contiene un <img> vero e il
         *    sibling precedente è un <img> pigro, src/alt del vero img vengono
         *    copiati sul pigro (pattern WordPress/Rocket comune).
         *
         * I data-* residui vengono tolti in extract() dalla whitelist attributi;
         * in cleanup_lazy_and_relative restano (innocui per le description).
         *
         * @param DOMDocument $doc Documento.
         * @return void
         */
        private static function fix_lazy_images( $doc ) {
                $imgs = array();
                foreach ( $doc->getElementsByTagName( 'img' ) as $el ) {
                        if ( $el instanceof DOMElement ) {
                                $imgs[] = $el;
                        }
                }

                foreach ( $imgs as $el ) {
                        $src = trim( $el->getAttribute( 'src' ) );
                        $bad = ( '' === $src ) || ( 0 === strpos( self::lower( $src ), 'data:' ) );
                        if ( ! $bad ) {
                                continue;
                        }

                        // Candidati "semplici": un valore pulito basta.
                        foreach ( array( 'data-src', 'data-original', 'data-lazy-src' ) as $attr ) {
                                $value = trim( $el->getAttribute( $attr ) );
                                if ( '' !== $value && 0 !== strpos( self::lower( $value ), 'data:' ) ) {
                                        $el->setAttribute( 'src', $value );
                                        $bad = false;
                                        break;
                                }
                        }

                        if ( ! $bad ) {
                                continue;
                        }

                        // Srcset: primo candidato utilizzabile (decisione: il primo,
                        // deterministico; di solito è anche il più piccolo ma sicuro).
                        // Guardie: candidati "data:" scartati (il base64 contiene
                        // virgole che rompono lo split) e candidati senza "/" né "."
                        // scartati (frammenti base64 residui dello stesso split; un
                        // falso positivo residuo viene comunque buttato dopo dal
                        // controllo "src deve essere http(s) assoluto").
                        foreach ( array( 'data-srcset', 'srcset' ) as $attr ) {
                                $value = trim( $el->getAttribute( $attr ) );
                                if ( '' === $value ) {
                                        continue;
                                }
                                foreach ( preg_split( '/\s*,\s*/', $value ) as $candidate ) {
                                        $candidate = trim( (string) $candidate );
                                        if ( '' === $candidate || 0 === strpos( self::lower( $candidate ), 'data:' ) ) {
                                                continue;
                                        }
                                        if ( false === strpos( $candidate, '/' ) && false === strpos( $candidate, '.' ) ) {
                                                continue; // frammento base64, non è un URL
                                        }
                                        $pieces = preg_split( '/\s+/', $candidate );
                                        $url    = is_array( $pieces ) && isset( $pieces[0] ) ? trim( $pieces[0] ) : '';
                                        if ( '' !== $url ) {
                                                $el->setAttribute( 'src', $url );
                                                $bad = false;
                                                break 2;
                                        }
                                }
                        }
                }

                self::rescue_noscript_images( $doc );
        }

        /**
         * Rescue del vero <img> dentro <noscript> sul <img> pigro precedente.
         *
         * Implementato per regex sull'HTML del <noscript> (self-contained,
         * senza seconda DOMDocument né fragment XML che esigerebbero markup
         * ben formato): il contenuto di <noscript> è HTML scritto a mano dai
         * siti, spesso non ben formato.
         *
         * @param DOMDocument $doc Documento.
         * @return void
         */
        private static function rescue_noscript_images( $doc ) {
                $nodes = array();
                foreach ( $doc->getElementsByTagName( 'noscript' ) as $el ) {
                        if ( $el instanceof DOMElement ) {
                                $nodes[] = $el;
                        }
                }

                foreach ( $nodes as $el ) {
                        $prev = $el->previousSibling;
                        while ( null !== $prev && ! ( $prev instanceof DOMElement ) ) {
                                $prev = $prev->previousSibling;
                        }
                        if ( ! $prev instanceof DOMElement || 'img' !== self::lower( $prev->tagName ) ) {
                                continue;
                        }

                        $src = trim( $prev->getAttribute( 'src' ) );
                        $bad = ( '' === $src ) || ( 0 === strpos( self::lower( $src ), 'data:' ) );
                        if ( ! $bad ) {
                                continue;
                        }

                        // Estrai il primo <img> dal markup del <noscript>.
                        $inner = '';
                        try {
                                $inner = (string) $doc->saveHTML( $el );
                        } catch ( \Throwable $e ) {
                                $inner = '';
                        }
                        $inner = (string) preg_replace( '/<\/?noscript[^>]*>/i', '', $inner );
                        if ( ! preg_match( '/<img\b[^>]*>/i', $inner, $m ) ) {
                                continue;
                        }
                        $tag = (string) $m[0];

                        $real_src = '';
                        if ( preg_match( '/\ssrc\s*=\s*(["\'])(.*?)\1/i', $tag, $m2 ) ) {
                                $real_src = trim( (string) $m2[2] );
                        }
                        if ( '' === $real_src || 0 === strpos( self::lower( $real_src ), 'data:' ) ) {
                                continue;
                        }

                        $prev->setAttribute( 'src', $real_src );
                        if ( '' === trim( $prev->getAttribute( 'alt' ) ) && preg_match( '/\salt\s*=\s*(["\'])(.*?)\1/i', $tag, $m3 ) ) {
                                $prev->setAttribute( 'alt', (string) $m3[2] );
                        }
                }
        }

        /* ============ PRE-PULIZIA ============ */

        /**
         * Pre-pulizia del documento prima dello scoring.
         *
         * Rimuove (con discendenti) i tag della lista $kill_tags, le <picture>
         * vengono SCIOLTE (l'<img> interna deve sopravvivere), i commenti HTML
         * e le processing instruction vengono rimossi.
         *
         * @param DOMDocument $doc Documento.
         * @return void
         */
        private static function pre_clean( $doc ) {
                self::remove_tags( $doc, self::$kill_tags );
                self::unwrap_tags( $doc, array( 'picture' ) );

                $junk = array();
                foreach ( $doc->getElementsByTagName( '*' ) as $node ) {
                        if ( $node instanceof DOMComment || $node instanceof DOMProcessingInstruction ) {
                                $junk[] = $node;
                        }
                }
                foreach ( $junk as $node ) {
                        if ( $node->parentNode ) {
                                $node->parentNode->removeChild( $node );
                        }
                }
        }

        /**
         * Rimuove tutte le occorrenze dei tag dati (con tutto il loro sottoalbero).
         *
         * @param DOMDocument $doc  Documento.
         * @param array       $tags Nomi tag (lowercase).
         * @return void
         */
        private static function remove_tags( $doc, $tags ) {
                foreach ( $tags as $tag ) {
                        $nodes = array();
                        foreach ( $doc->getElementsByTagName( $tag ) as $el ) {
                                if ( $el instanceof DOMElement ) {
                                        $nodes[] = $el;
                                }
                        }
                        foreach ( $nodes as $el ) {
                                if ( $el->parentNode ) {
                                        $el->parentNode->removeChild( $el );
                                }
                        }
                }
        }

        /**
         * Scioglie i tag dati mantenendo i figli al loro posto.
         *
         * @param DOMDocument $doc  Documento.
         * @param array       $tags Nomi tag (lowercase).
         * @return void
         */
        private static function unwrap_tags( $doc, $tags ) {
                foreach ( $tags as $tag ) {
                        $nodes = array();
                        foreach ( $doc->getElementsByTagName( $tag ) as $el ) {
                                if ( $el instanceof DOMElement ) {
                                        $nodes[] = $el;
                                }
                        }
                        foreach ( $nodes as $el ) {
                                self::unwrap( $el );
                        }
                }
        }

        /* ============ SCORING (ARC90) ============ */

        /**
         * Scoring Arc90: candidati, bonus classe/id, link-density, ancestor sum.
         *
         * Candidati (documentati nel docblock di classe): p (testo ≥ 25), pre e
         * blockquote (testo non vuoto), div con figli <p> diretti (peso 5) o con
         * testo diretto ≥ 25 (peso 1). Contribuzione = (peso + bonus classe) ×
         * (1 − link-density), aggiunta intera al candidato e al padre, per metà
         * al nonno (Arc90).
         *
         * @param DOMDocument      $doc    Documento pre-pulito.
         * @param SplObjectStorage $scores Mappa nodo → score (popolata qui).
         * @return void
         */
        private static function score_candidates( $doc, $scores ) {
                foreach ( $doc->getElementsByTagName( '*' ) as $el ) {
                        if ( ! $el instanceof DOMElement ) {
                                continue;
                        }
                        $tag = self::lower( $el->tagName );

                        $weight = 0.0;
                        if ( 'p' === $tag ) {
                                if ( self::text_len( self::norm( $el->textContent ) ) >= self::MIN_CANDIDATE_CHARS ) {
                                        $weight = 1.0;
                                }
                        } elseif ( 'pre' === $tag ) {
                                if ( '' !== trim( (string) $el->textContent ) ) {
                                        $weight = 3.0;
                                }
                        } elseif ( 'blockquote' === $tag ) {
                                if ( '' !== trim( (string) $el->textContent ) ) {
                                        $weight = 2.0;
                                }
                        } elseif ( 'div' === $tag ) {
                                $has_p_child = false;
                                foreach ( $el->childNodes as $child ) {
                                        if ( $child instanceof DOMElement && 'p' === self::lower( $child->tagName ) ) {
                                                $has_p_child = true;
                                                break;
                                        }
                                }
                                if ( $has_p_child ) {
                                        $weight = 5.0;
                                } else {
                                        // Div con testo DIRETTO (nodi testo propri, non dei discendenti).
                                        $direct = '';
                                        foreach ( $el->childNodes as $child ) {
                                                if ( $child instanceof DOMText ) {
                                                        $direct .= (string) $child->nodeValue;
                                                }
                                        }
                                        if ( self::text_len( self::norm( $direct ) ) >= self::MIN_CANDIDATE_CHARS ) {
                                                $weight = 1.0;
                                        }
                                }
                        }

                        if ( $weight <= 0.0 ) {
                                continue;
                        }

                        $contribution = ( $weight + self::class_bonus( $el ) ) * ( 1.0 - self::link_density( $el ) );
                        if ( $contribution <= 0.0 ) {
                                continue; // solo link o solo malus: non alimenta nessuno
                        }

                        self::add_score( $scores, $el, $contribution );

                        $parent = $el->parentNode;
                        if ( $parent instanceof DOMElement ) {
                                self::add_score( $scores, $parent, $contribution );
                                $grand = $parent->parentNode;
                                if ( $grand instanceof DOMElement ) {
                                        self::add_score( $scores, $grand, $contribution * self::GRANDPARENT_FACTOR );
                                }
                        }
                }
        }

        /**
         * Somma $delta allo score del nodo (0 se assente).
         *
         * @param SplObjectStorage $scores Mappa score.
         * @param DOMElement       $el     Nodo.
         * @param float            $delta  Contribuzione (può essere negativa via class_bonus).
         * @return void
         */
        private static function add_score( $scores, $el, $delta ) {
                $current = $scores->contains( $el ) ? (float) $scores[ $el ] : 0.0;
                $scores[ $el ] = $current + (float) $delta;
        }

        /**
         * Bonus/malus classe+id: +25 per token positivo, −25 per token negativo.
         *
         * Match con word boundary sul value di class+id (concatenati, lowercase).
         * I pattern vengono compilati una sola volta (static cache del metodo).
         * Token "com-" voluto: \bcom\-\b matcha "com-box" ma non "comment".
         *
         * @param DOMElement $el Elemento.
         * @return float Bonus totale (può essere negativo).
         */
        private static function class_bonus( $el ) {
                static $positive_patterns = null;
                static $negative_patterns = null;

                if ( null === $positive_patterns ) {
                        $positive_patterns = array();
                        foreach ( preg_split( '/\s+/', self::POSITIVE_CLASSES ) as $token ) {
                                if ( '' !== $token ) {
                                        $positive_patterns[] = '/\b' . preg_quote( $token, '/' ) . '\b/';
                                }
                        }
                        $negative_patterns = array();
                        foreach ( preg_split( '/\s+/', self::NEGATIVE_CLASSES ) as $token ) {
                                if ( '' !== $token ) {
                                        $negative_patterns[] = '/\b' . preg_quote( $token, '/' ) . '\b/';
                                }
                        }
                }

                $hay = self::lower( $el->getAttribute( 'class' ) . ' ' . $el->getAttribute( 'id' ) );
                if ( '' === trim( $hay ) ) {
                        return 0.0;
                }

                $bonus = 0.0;
                foreach ( $positive_patterns as $pattern ) {
                        if ( preg_match( $pattern, $hay ) ) {
                                $bonus += self::CLASS_BONUS;
                        }
                }
                foreach ( $negative_patterns as $pattern ) {
                        if ( preg_match( $pattern, $hay ) ) {
                                $bonus -= self::CLASS_BONUS;
                        }
                }
                return $bonus;
        }

        /**
         * Link-density: quota di caratteri di testo dentro link <a> discendenti.
         *
         * @param DOMElement $el Elemento (candidato).
         * @return float 0.0 .. 1.0
         */
        private static function link_density( $el ) {
                $total = self::text_len( self::norm( $el->textContent ) );
                if ( $total <= 0 ) {
                        return 0.0;
                }
                $linked = 0;
                foreach ( $el->getElementsByTagName( 'a' ) as $a ) {
                        $linked += self::text_len( self::norm( $a->textContent ) );
                }
                return max( 0.0, min( 1.0, $linked / $total ) );
        }

        /**
         * Scelta del top ancestor: score massimo (primo documento in caso di pari merito).
         *
         * Regole:
         *  - nessuno score > 0 → <body> (o documentElement) come fallback onesto;
         *  - se il migliore è un candidato "foglia" (p/pre/blockquote/span/a/
         *    heading/img/code/em/strong/b/i/u/li) si sale al padre (Arc90 sceglie
         *    sempre un contenitore).
         *
         * @param DOMDocument      $doc    Documento.
         * @param SplObjectStorage $scores Mappa score.
         * @return DOMElement Top ancestor.
         */
        private static function pick_top( $doc, $scores ) {
                $best       = null;
                $best_score = 0.0;
                foreach ( $scores as $el ) {
                        $score = (float) $scores[ $el ];
                        if ( $score > $best_score ) {
                                $best       = $el;
                                $best_score = $score;
                        }
                }

                if ( null === $best || ! $best instanceof DOMElement ) {
                        $body = $doc->getElementsByTagName( 'body' )->item( 0 );
                        if ( $body instanceof DOMElement ) {
                                return $body;
                        }
                        return $doc->documentElement instanceof DOMElement ? $doc->documentElement : $doc->createElement( 'div' );
                }

                $leaf_tags = array(
                        'p', 'pre', 'blockquote', 'span', 'a', 'img', 'code', 'em',
                        'strong', 'b', 'i', 'u', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                );
                if ( in_array( self::lower( $best->tagName ), $leaf_tags, true ) ) {
                        $parent = $best->parentNode;
                        if ( $parent instanceof DOMElement ) {
                                return $parent;
                        }
                }

                return $best;
        }

        /* ============ PULIZIA DEL TOP ============ */

        /**
         * Pulizia del sottoalbero scelto (in ordine: negativi, <a>, whitelist,
         * <br>, attributi, div→p, immagini, vuoti). Tutte le regole sono
         * documentate nel docblock di classe.
         *
         * @param DOMElement       $top    Top ancestor.
         * @param SplObjectStorage $scores Mappa score (per la rimozione negativi forti).
         * @return DOMElement Stesso nodo $top (pulito).
         */
        private static function clean_top( $top, $scores ) {
                // 1. Nodi con score accumulato fortemente negativo (comment/sidebar/sponsor).
                $to_remove = array();
                foreach ( $top->getElementsByTagName( '*' ) as $el ) {
                        if ( $el instanceof DOMElement && $scores->contains( $el ) && (float) $scores[ $el ] <= self::STRONG_NEGATIVE ) {
                                $to_remove[] = $el;
                        }
                }
                if ( ! empty( $to_remove ) ) {
                        $removals = new SplObjectStorage();
                        foreach ( $to_remove as $el ) {
                                $removals[ $el ] = true;
                        }
                        foreach ( $to_remove as $el ) {
                                // Salta se un antenato è già in rimozione (sottobraccio già staccato).
                                $ancestor = $el->parentNode;
                                $skip     = false;
                                while ( null !== $ancestor ) {
                                        if ( $removals->contains( $ancestor ) ) {
                                                $skip = true;
                                                break;
                                        }
                                        $ancestor = $ancestor->parentNode;
                                }
                                if ( ! $skip && $el->parentNode ) {
                                        $el->parentNode->removeChild( $el );
                                }
                        }
                }

                // 2. <a> senza href http(s) assoluto → sciolti (resta il testo).
                $elements = array();
                foreach ( $top->getElementsByTagName( '*' ) as $el ) {
                        if ( $el instanceof DOMElement ) {
                                $elements[] = $el;
                        }
                }
                foreach ( array_reverse( $elements ) as $el ) {
                        if ( null === $el->parentNode ) {
                                continue; // già staccato con un antenato rimosso
                        }
                        if ( 'a' === self::lower( $el->tagName ) ) {
                                $href = trim( $el->getAttribute( 'href' ) );
                                if ( '' === $href || ! preg_match( '#^https?://#i', $href ) ) {
                                        self::unwrap( $el );
                                }
                        }
                }

                // 3. Whitelist tag: non ammessi → unwrap (o remove se senza figli).
                //    Ordine bottom-up (reverse document order) per processare i
                //    figli prima dei padri.
                $elements = array();
                foreach ( $top->getElementsByTagName( '*' ) as $el ) {
                        if ( $el instanceof DOMElement ) {
                                $elements[] = $el;
                        }
                }
                foreach ( array_reverse( $elements ) as $el ) {
                        if ( null === $el->parentNode ) {
                                continue;
                        }
                        if ( ! in_array( self::lower( $el->tagName ), self::$allowed_tags, true ) ) {
                                if ( 0 === $el->childNodes->length ) {
                                        $el->parentNode->removeChild( $el );
                                } else {
                                        self::unwrap( $el );
                                }
                        }
                }

                // 4. <br> consecutivi: ogni br che segue un br viene rimosso
                //    (bottom-up: le sequenze collassano a un solo <br>).
                $brs = array();
                foreach ( $top->getElementsByTagName( 'br' ) as $el ) {
                        if ( $el instanceof DOMElement ) {
                                $brs[] = $el;
                        }
                }
                foreach ( array_reverse( $brs ) as $el ) {
                        if ( null === $el->parentNode ) {
                                continue;
                        }
                        $prev = $el->previousSibling;
                        while ( null !== $prev && ! ( $prev instanceof DOMElement ) ) {
                                $prev = $prev->previousSibling;
                        }
                        if ( $prev instanceof DOMElement && 'br' === self::lower( $prev->tagName ) ) {
                                $el->parentNode->removeChild( $el );
                        }
                }

                // 5. Attributi fuori whitelist rimossi (eventi/style/class/id inclusi).
                foreach ( $top->getElementsByTagName( '*' ) as $el ) {
                        if ( ! $el instanceof DOMElement ) {
                                continue;
                        }
                        $tag   = self::lower( $el->tagName );
                        $keep  = isset( self::$allowed_attrs[ $tag ] ) ? self::$allowed_attrs[ $tag ] : array();
                        $names = array();
                        foreach ( $el->attributes as $attr ) {
                                $names[] = $attr->nodeName;
                        }
                        foreach ( $names as $name ) {
                                if ( ! in_array( $name, $keep, true ) ) {
                                        $el->removeAttribute( $name );
                                }
                        }
                }

                // 6. div residui: con figli block → sciolti; solo inline/testo → <p>.
                //    Gli span restano span (inline dentro un p: convertirli romperebbe
                //    i paragrafi — decisione documentata).
                $elements = array();
                foreach ( $top->getElementsByTagName( 'div' ) as $el ) {
                        if ( $el instanceof DOMElement ) {
                                $elements[] = $el;
                        }
                }
                foreach ( array_reverse( $elements ) as $el ) {
                        if ( null === $el->parentNode ) {
                                continue;
                        }
                        $has_block = false;
                        foreach ( $el->childNodes as $child ) {
                                if ( $child instanceof DOMElement && in_array( self::lower( $child->tagName ), self::$block_tags, true ) ) {
                                        $has_block = true;
                                        break;
                                }
                        }
                        if ( $has_block ) {
                                self::unwrap( $el );
                        } else {
                                $doc  = $top->ownerDocument;
                                $new  = $doc->createElement( 'p' );
                                while ( $el->firstChild ) {
                                        $new->appendChild( $el->firstChild );
                                }
                                $el->parentNode->replaceChild( $new, $el );
                        }
                }

                // 7. Immagini: solo src http(s) assoluta, max 6 in ordine documento.
                $kept = 0;
                $imgs = array();
                foreach ( $top->getElementsByTagName( 'img' ) as $el ) {
                        if ( $el instanceof DOMElement ) {
                                $imgs[] = $el;
                        }
                }
                foreach ( $imgs as $el ) {
                        $src = trim( $el->getAttribute( 'src' ) );
                        if ( ! preg_match( '#^https?://#i', $src ) || $kept >= self::MAX_IMAGES ) {
                                if ( $el->parentNode ) {
                                        $el->parentNode->removeChild( $el );
                                }
                                continue;
                        }
                        $kept++;
                }

                // 8. Nodi vuoti (p/span/figure/figcaption/li senza testo e senza img,
                //    p composti da soli <br>).
                $elements = array();
                foreach ( $top->getElementsByTagName( '*' ) as $el ) {
                        if ( $el instanceof DOMElement ) {
                                $elements[] = $el;
                        }
                }
                foreach ( array_reverse( $elements ) as $el ) {
                        if ( null === $el->parentNode ) {
                                continue;
                        }
                        $tag = self::lower( $el->tagName );
                        if ( ! in_array( $tag, array( 'p', 'span', 'figure', 'figcaption', 'li' ), true ) ) {
                                continue;
                        }
                        if ( self::node_has_image( $el ) ) {
                                continue;
                        }
                        if ( '' !== trim( (string) $el->textContent ) ) {
                                continue;
                        }
                        $only_brs = true;
                        foreach ( $el->childNodes as $child ) {
                                if ( $child instanceof DOMElement && 'br' !== self::lower( $child->tagName ) ) {
                                        $only_brs = false;
                                        break;
                                }
                        }
                        if ( $only_brs && $el->parentNode ) {
                                $el->parentNode->removeChild( $el );
                        }
                }

                return $top;
        }

        /**
         * Verifica se un nodo contiene almeno un'immagine <img> nei discendenti.
         *
         * @param DOMElement $el Elemento.
         * @return bool
         */
        private static function node_has_image( $el ) {
                foreach ( $el->getElementsByTagName( 'img' ) as $img ) {
                        return true; // basta il primo
                }
                return false;
        }

        /* ============ SERIALIZZAZIONE E TESTO ============ */

        /**
         * Serializza i figli top-level di $top con clip per NODI.
         *
         * Ogni nodo top-level serializzato è un sottoalbero completo → l'HTML
         * in uscita è sempre bilanciato. Il primo nodo che sfora il limite
         * scarta il resto (i nodi già accettati restano interi).
         *
         * Caso limite (UN solo nodo > limite): se il nodo ha testo → clip del
         * testo in un <p>; se è senza testo (tabella gigante...) → taglio onesto
         * all'ultimo ">" prima del limite (può lasciare un tag aperto: caso
         * documentato e raro).
         *
         * @param DOMElement $top    Contenitore.
         * @param int        $limit  Limite caratteri (MAX_CONTENT_CHARS o MAX_INPUT_CHARS).
         * @return string HTML.
         */
        private static function serialize_top( $top ) {
                return self::serialize_children( $top, self::MAX_CONTENT_CHARS );
        }

        /**
         * Implementazione comune della serializzazione per figli con clip.
         *
         * @param DOMElement $container Contenitore (top ancestor o body).
         * @param int        $limit     Limite caratteri.
         * @return string HTML.
         */
        private static function serialize_children( $container, $limit ) {
                $doc  = $container->ownerDocument;
                $out  = '';

                $nodes = array();
                foreach ( $container->childNodes as $node ) {
                        $nodes[] = $node;
                }

                foreach ( $nodes as $node ) {
                        // Whitespace tra i blocchi e commenti: fuori.
                        if ( $node instanceof DOMText && '' === trim( (string) $node->nodeValue ) ) {
                                continue;
                        }
                        if ( $node instanceof DOMComment || $node instanceof DOMProcessingInstruction ) {
                                continue;
                        }

                        $chunk = '';
                        try {
                                $chunk = (string) $doc->saveHTML( $node );
                        } catch ( \Throwable $e ) {
                                $chunk = '';
                        }
                        if ( '' === trim( $chunk ) ) {
                                continue;
                        }

                        if ( self::text_len( $out ) + self::text_len( $chunk ) > $limit ) {
                                break;
                        }
                        $out .= $chunk;
                }

                if ( '' === $out ) {
                        // Nessun nodo entra nel limite: gestione del nodo gigante.
                        $first = null;
                        foreach ( $container->childNodes as $node ) {
                                if ( $node instanceof DOMElement ) {
                                        $first = $node;
                                        break;
                                }
                        }
                        if ( $first instanceof DOMElement ) {
                                $text = self::clip( self::norm( $first->textContent ), $limit );
                                if ( '' !== trim( $text ) ) {
                                        return '<p>' . self::escape_html( $text ) . '</p>';
                                }
                                try {
                                        $chunk = (string) $doc->saveHTML( $first );
                                        $cut   = self::clip_chars( $chunk, $limit );
                                        $pos   = strrpos( $cut, '>' );
                                        if ( false !== $pos && $pos > 0 ) {
                                                return substr( $cut, 0, $pos + 1 );
                                        }
                                        return $cut;
                                } catch ( \Throwable $e ) {
                                        return '';
                                }
                        }
                }

                return $out;
        }

        /**
         * Plain text dall'HTML: i blocchi diventano newline, tag via, entità
         * decodificate, whitespace normalizzato (le sequenze di spazio restano
         * leggibili per validate() e per l'uso nei prompt).
         *
         * @param string $html HTML.
         * @return string Testo plain.
         */
        private static function plain_text( $html ) {
                $html = (string) $html;
                // I confini dei blocchi diventano newline: strip_tags da solo
                // incollerebbe le parole tra paragrafi ("fine.Inizio").
                $html = (string) preg_replace( '/<\/(?:p|div|li|ul|ol|h[1-6]|blockquote|pre|tr|td|th|figcaption|table|section|article)>/i', "\n", $html );
                $html = (string) preg_replace( '/<br\s*\/?>/i', "\n", $html );
                $text = strip_tags( $html );
                $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML401, 'UTF-8' );
                $text = (string) preg_replace( '/[ \t]+/', ' ', $text );
                $text = (string) preg_replace( '/\n{3,}/', "\n\n", $text );
                $text = (string) preg_replace( '/ ?\n ?/', "\n", $text );
                return trim( $text );
        }

        /* ============ HELPER TESTO / ARRAY ============ */

        /**
         * Scioglie un elemento mantenendo i figli al suo posto.
         *
         * @param DOMElement $el Elemento da sciogliere.
         * @return void
         */
        private static function unwrap( $el ) {
                $parent = $el->parentNode;
                if ( null === $parent ) {
                        return;
                }
                while ( $el->firstChild ) {
                        $parent->insertBefore( $el->firstChild, $el );
                }
                $parent->removeChild( $el );
        }

        /**
         * Normalizza whitespace (collapsing) senza flag /u: byte-safe anche su
         * input con UTF-8 sporco (preg con /u ritornerebbe null e perderemmo
         * tutto il testo — bug evitato per costruzione).
         *
         * @param string $text Testo.
         * @return string Testo con whitespace collassato.
         */
        private static function norm( $text ) {
                return (string) preg_replace( '/\s+/', ' ', trim( (string) $text ) );
        }

        /**
         * Clip con trim (stile Nexus_Tools::clip, self-contained).
         *
         * @param string $text   Testo.
         * @param int    $length Limite caratteri.
         * @return string
         */
        private static function clip( $text, $length ) {
                $text = trim( (string) $text );
                if ( function_exists( 'mb_substr' ) ) {
                        return mb_substr( $text, 0, (int) $length );
                }
                return substr( $text, 0, (int) $length );
        }

        /**
         * Clip SENZA trim (serve per HTML/binario dove il trim altererebbe i byte).
         *
         * @param string $text   Testo.
         * @param int    $length Limite caratteri.
         * @return string
         */
        private static function clip_chars( $text, $length ) {
                $text = (string) $text;
                if ( function_exists( 'mb_substr' ) ) {
                        return (string) mb_substr( $text, 0, (int) $length );
                }
                return (string) substr( $text, 0, (int) $length );
        }

        /**
         * Lunghezza in caratteri (mb_strlen con fallback strlen: se mbstring
         * manca il conteggio è in byte, sovra-stima conservativa documentata).
         *
         * @param string $text Testo.
         * @return int
         */
        private static function text_len( $text ) {
                $text = (string) $text;
                if ( function_exists( 'mb_strlen' ) ) {
                        return (int) mb_strlen( $text );
                }
                return (int) strlen( $text );
        }

        /**
         * Lowercase UTF-8 con fallback ASCII.
         *
         * v1.1.13.1 (Q4): delega all'helper condiviso Nexus_Dedup::utf8_lower()
         * (semantica identica: mb_strtolower UTF-8 con fallback strtolower).
         * Il file non è richiesto dal bootstrap principale: in caso di
         * caricamento senza Nexus_Dedup resta il corpo locale come fallback.
         *
         * @param string $text Testo.
         * @return string
         */
        private static function lower( $text ) {
                if ( class_exists( 'Nexus_Dedup' ) && method_exists( 'Nexus_Dedup', 'utf8_lower' ) ) {
                        return Nexus_Dedup::utf8_lower( $text );
                }

                // Fallback locale (caricamento senza Nexus_Dedup): comportamento storico.
                $text = (string) $text;
                if ( function_exists( 'mb_strtolower' ) ) {
                        return (string) mb_strtolower( $text, 'UTF-8' );
                }
                return (string) strtolower( $text );
        }

        /**
         * Escape HTML per il fallback del nodo gigante (testo → <p>).
         *
         * @param string $text Testo raw.
         * @return string Testo escaped.
         */
        private static function escape_html( $text ) {
                return (string) htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        }

        /**
         * Lista stopword IT/EN (const STOPWORDS → array, cache statica).
         *
         * @return array
         */
        private static function stopwords() {
                static $list = null;
                if ( null === $list ) {
                        $list = preg_split( '/\s+/', self::STOPWORDS );
                        if ( ! is_array( $list ) ) {
                                $list = array();
                        }
                }
                return $list;
        }
}
