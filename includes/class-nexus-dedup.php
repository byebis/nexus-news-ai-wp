<?php
/**
 * Nexus Dedup — dedup semantico simhash 64-bit (v1.1.12, pacchetto S3;
 * v1.1.13-wip "Ottimizzazione" — limiti configurabili + segnalazione onesta).
 *
 * COME FUNZIONA (algoritmo):
 *  - Normalizzazione: strip tag, decode entità, lowercase (mb_strtolower se
 *    disponibile, altrimenti strtolower), si tengono SOLO lettere e numeri
 *    ([\p{L}\p{N}] via PCRE-Unicode quando supportato, fallback ASCII [a-z0-9]).
 *  - Shingle: 4-grammi di parole consecutive (testo < 4 parole = un solo
 *    shingle con tutte le parole, così anche i testi minimi hanno impronta).
 *  - Feature hash 64-bit SENZA overflow: per ogni shingle h = md5(shingle),
 *    primi 16 caratteri hex → due interi 32-bit h1 = hexdec(8 char),
 *    h2 = hexdec(8 char). Bit b (0..63): b < 32 → (h1 >> b) & 1,
 *    b >= 32 → (h2 >> (b - 32)) & 1 (schema lineare, documentato e coerente
 *    tra fingerprint() e hamming()).
 *  - Simhash: 64 contatori (+1 se bit = 1, -1 se bit = 0); bit i del
 *    fingerprint = 1 se contatore[i] > 0. Rappresentazione finale: STRINGA
 *    hex di 16 caratteri = due int 32-bit con sprintf('%08x') (evita i
 *    problemi di int firmato a 32 bit e rende il meta leggibile/stabile).
 *  - hamming(): i due fingerprint hex vengono ri-convertiti in 2 x int32
 *    ciascuno, XOR + popcount (gmp_popcount se GMP esiste, altrimenti
 *    decbin + substr_count su binario a 32 bit str_pad-ato a sinistra).
 *    Distanza <= dedup_similar_threshold (default 6) = contenuto simile.
 *
 * IMPOSTAZIONI DI SCALA (v1.1.13-wip — prima hardcoded, ora opzioni):
 *  - nexus_dedup_window_days (option WP, default 90): finestra temporale
 *    della lookup in giorni; filtro dev omonimo 'nexus_dedup_window_days'.
 *  - nexus_dedup_lookup_max (option WP, default 400): cap di post id per
 *    run (UNA sola query); filtro dev omonimo 'nexus_dedup_lookup_max'.
 *  - Lettura centralizzata in limit_option(): get_option(default) → filtro
 *    dev → clamp [1, max]. Sono opzioni DEDICATE (non nell'array
 *    nexus_settings): il default è la costante di versione.
 *
 * SEGNALAZIONE ONESTA (v1.1.13-wip): quando la lookup TOCCA IL CAP (N post
 * con fingerprint >= lookup_max) il confronto è per costruzione troncato —
 * gli articoli della finestra oltre il cap NON vengono confrontati e i
 * duplicati possono passare. In quel caso UNA riga in activity log
 * (Nexus_DB::insert_activity, action 'dedup', status 'warning') THROTTLED a
 * 1/giorno via transient 'nexus_dedup_window_note'. MAI spam: il turno
 * giornaliero si consuma al primo trigger anche in ambiente degradato, e
 * deactivate() azzera il transient. Rilevazione SENZA query extra: la
 * heuristic count($ids) >= cap (no_found_rows resta true, niente
 * SQL_CALC_FOUND_ROWS; al limite esatto di cap post il messaggio resta
 * veritiero: il confronto È limitato a N).
 *
 * METODI CONDIVISI (v1.1.13-wip — usati anche da Nexus_Interlink; entrambi
 * i moduli sono sempre richiesti dal bootstrap, nessun ciclo di caricamento):
 *  - Nexus_Dedup::settings_guarded()        → get_settings() con guardie
 *  - Nexus_Dedup::limit_option()            → option + filtro dev + clamp
 *  - Nexus_Dedup::utf8_lower()              → lowercase robusto UTF-8
 *  - Nexus_Dedup::pipeline_post_types()     → post type pubblicati dalla pipeline
 *  - Nexus_Dedup::resolve_article_post_id() → risoluzione post WP ↔ articolo
 *
 * INTEGRAZIONE PIPELINE:
 *  - filtro 'nexus_pipeline_skip_item' (S0, pipeline riga ~322): un candidato
 *    il cui fingerprint è a distanza Hamming <= soglia da un post pubblicato
 *    negli ultimi nexus_dedup_window_days giorni viene scartato PRIMA
 *    dell'INSERT (il pipeline logga già lo scarto come 'duplicate').
 *  - azione 'nexus_pipeline_after_publish' (S0, pipeline riga ~755): calcola
 *    il fingerprint dal contenuto FINALE del post WordPress pubblicato
 *    (post_id risolto con l'helper condiviso resolve_article_post_id) e lo
 *    salva in _nexus_simhash. INDIPENDENTE DAL POST TYPE: la risoluzione
 *    copre il post type del publisher (filtro 'nexus_native_post_type') E i
 *    post type pipeline di Nexus_Native::query_post_types() (nativo +
 *    CPT legacy 'nexus_article'), così il fingerprint arriva anche sui post
 *    pubblicati fuori dal default.
 *
 * POST VECCHI: gli articoli pubblicati PRIMA di questa versione non hanno il
 * meta _nexus_simhash: vengono semplicemente SALTATI dalla lookup (meta_query
 * EXISTS) e il sistema si popola da solo man mano che i nuovi articoli
 * vengono pubblicati. Nessuna migrazione retroattiva richiesta.
 *
 * PERFORMANCE: la lookup del filtro fa UNA sola WP_Query (fields ids, cap
 * nexus_dedup_lookup_max, no_found_rows, post_type IN pipeline_post_types)
 * + update_meta_cache() per precaricare TUTTI i meta _nexus_simhash in un
 * colpo solo; i confronti Hamming sono in memoria e get_post_meta è servito
 * dalla cache (zero query extra nel loop). Le query restano indicizzate
 * (post_type + post_date_gmt, meta_key EXISTS): niente LIKE su contenuti.
 *
 * GUARDIA (v1.1.13-wip): i callback pipeline girano dentro try/catch
 * Throwable — il dedup NON deve MAI rompere raccolta/pubblicazione.
 *
 * CONTRATTO PUBBLICO:
 *  - Nexus_Dedup::fingerprint( $text )  → string hex 16 char (o '' se testo vuoto)
 *  - Nexus_Dedup::hamming( $a, $b )     → int 0..64
 *  - Nexus_Dedup::maybe_skip_similar()  → callback filtro skip_item
 *  - Nexus_Dedup::store_fingerprint()   → callback azione after_publish
 *  - Nexus_Dedup::deactivate()          → azzera il throttle della segnalazione
 *  - helper condivisi (vedi sezione METODI CONDIVISI)
 *
 * CONSUMA: Nexus_DB::get_settings() (setting dedup_similar_enabled default 1,
 * dedup_similar_threshold default 6); opzioni nexus_dedup_window_days e
 * nexus_dedup_lookup_max con filtri dev omonimi.
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

final class Nexus_Dedup {

        /**
         * Larghezza del fingerprint in bit (simhash 64-bit).
         *
         * @var int
         */
        const BITS = 64;

        /**
         * Lunghezza degli shingle in parole (4-grammi).
         *
         * @var int
         */
        const SHINGLE_WORDS = 4;

        /**
         * DEFAULT della finestra temporale della lookup in giorni (90).
         *
         * Il valore effettivo è l'option nexus_dedup_window_days (leggere
         * SEMPRE via self::window_days(): default → filtro dev → clamp).
         *
         * @var int
         */
        const WINDOW_DAYS = 90;

        /**
         * DEFAULT del cap della lookup: post id per run (UNA sola query).
         *
         * Il valore effettivo è l'option nexus_dedup_lookup_max (leggere
         * SEMPRE via self::lookup_max(): default → filtro dev → clamp).
         *
         * @var int
         */
        const LOOKUP_MAX = 400;

        /**
         * Chiave meta del fingerprint sul post WordPress.
         *
         * @var string
         */
        const META_KEY = '_nexus_simhash';

        /**
         * Transient di throttle della segnalazione "confronto troncato"
         * (1 segnalazione al giorno: 24h di vita, scadenza autonoma).
         *
         * @var string
         */
        const THROTTLE_TRANSIENT = 'nexus_dedup_window_note';

        /**
         * Bootstrap del modulo: registra filtro e azione sulla pipeline S0.
         *
         * @return void
         */
        public static function init() {
                add_filter( 'nexus_pipeline_skip_item', array( 'Nexus_Dedup', 'maybe_skip_similar' ), 10, 4 );
                add_action( 'nexus_pipeline_after_publish', array( 'Nexus_Dedup', 'store_fingerprint' ), 10, 3 );
        }

        /**
         * Disattivazione: azzera il transient di throttle della segnalazione
         * "confronto troncato", così alla riattivazione la prima esecuzione
         * troncata può loggare subito (i meta _nexus_simhash restano inutilizzati
         * ma innocui; il sistema si ripopola da solo).
         *
         * @return void
         */
        public static function deactivate() {
                if ( function_exists( 'delete_transient' ) ) {
                        delete_transient( self::THROTTLE_TRANSIENT );
                }
        }

        /* ============ HELPER CONDIVISI (anche per Nexus_Interlink) ============ */

        /**
         * Impostazioni plugin con guardie (mai fatali se Nexus_DB manca).
         *
         * Versione UNICA del pattern (prima: copie private in dedup e
         * interlink): restituisce sempre un array.
         *
         * @return array Impostazioni plugin (array vuoto se non disponibili).
         */
        public static function settings_guarded() {
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'get_settings' ) ) {
                        return array();
                }

                $settings = Nexus_DB::get_settings();

                return is_array( $settings ) ? $settings : array();
        }

        /**
         * Legge un limite di scala da option WP + filtro dev, con clamp.
         *
         * Pattern condiviso dedup/interlink (v1.1.13-wip): i limiti prima
         * hardcoded nelle costanti sono ora opzioni dedicate:
         *  1) get_option( $option_name, $default ) — default = costante di
         *     versione; option assente/vuota/0 → default (config rotta);
         *  2) filtro dev $filter_tag (il risultato del filtro è responsabilità
         *     del dev, ma viene comunque clampato per determinismo);
         *  3) clamp [$min, $max] — il tetto protegge memoria/DB (es. il
         *     preload della meta cache scala col cap).
         *
         * @param string $option_name Nome option WP (es. 'nexus_dedup_lookup_max').
         * @param string $filter_tag  Tag filtro dev (es. 'nexus_dedup_lookup_max').
         * @param int    $default     Default di versione (costante della classe).
         * @param int    $min         Minimo ammesso (>= 1).
         * @param int    $max         Massimo ammesso (tetto di protezione).
         * @return int Valore clampato tra $min e $max.
         */
        public static function limit_option( $option_name, $filter_tag, $default, $min, $max ) {
                $default = (int) $default;
                $min     = (int) $min;
                $max     = (int) $max;

                $value = function_exists( 'get_option' ) ? (int) get_option( $option_name, $default ) : $default;
                if ( $value < 1 ) {
                        $value = $default; // option assente/vuota/0: torna al default di versione.
                }

                $value = (int) apply_filters( $filter_tag, $value );

                if ( $value < $min ) {
                        $value = $min;
                }
                if ( $value > $max ) {
                        $value = $max;
                }

                return $value;
        }

        /**
         * Lowercase robusto UTF-8 (mbstring con fallback strtolower).
         *
         * Versione UNICA del pattern (prima: inline in normalize() e copia
         * privata lower() in interlink).
         *
         * @param string $text Testo in ingresso.
         * @return string Testo lowercase.
         */
        public static function utf8_lower( $text ) {
                $text = (string) $text;

                return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
        }

        /**
         * Post type pubblicati dalla pipeline (nativo + legacy + dev filter).
         *
         * UNIONE di:
         *  - il post type target del publisher (filtro 'nexus_native_post_type',
         *     default 'post' — quello che Nexus_Publisher usa in wp_insert_post);
         *  - i post type query di Nexus_Native::query_post_types() (nativo +
         *     CPT legacy 'nexus_article', filtro 'nexus_native_post_types').
         *
         * Serve per lookup e risoluzioni indipendenti dal post type: il
         * fingerprint/_nexus_article_id può stare su qualunque post pubblicato
         * dalla pipeline (il default è 'post', ma un dev filter può ridirigere
         * su un CPT; il CPT legacy 'nexus_article' esiste negli storici).
         * Zero impatto sulle performance: meta come _nexus_simhash esistono
         * solo sui post pipeline, quindi i tipi extra non aggiungono righe.
         *
         * @return array Lista post type (sanitizzati, unici, mai vuota).
         */
        public static function pipeline_post_types() {
                $types = array( (string) apply_filters( 'nexus_native_post_type', 'post' ) );

                if ( class_exists( 'Nexus_Native' ) && method_exists( 'Nexus_Native', 'query_post_types' ) ) {
                        $types = array_merge( $types, (array) Nexus_Native::query_post_types() );
                } else {
                        $types[] = 'nexus_article'; // stesso default di Nexus_Native.
                }

                $clean = array();
                foreach ( $types as $type ) {
                        $type = sanitize_key( (string) $type );
                        if ( '' !== $type ) {
                                $clean[] = $type;
                        }
                }

                if ( empty( $clean ) ) {
                        $clean = array( 'post' );
                }

                return array_values( array_unique( $clean ) );
        }

        /**
         * Risolve il post_id WordPress di un articolo Nexus (UNICA versione,
         * riusata da Nexus_Interlink::on_after_publish; prima c'erano DUE
         * versioni divergenti: dedup solo-'post' con fast path, interlink
         * multi-post-type senza fast path).
         *
         * Ordine:
         *  1) fast path $article->wp_post_id (il publisher lo aggiorna PRIMA
         *     dell'hook after_publish) + check esistenza post;
         *  2) fallback: lookup inversa sul meta _nexus_article_id scritto dal
         *     publisher, su pipeline_post_types() (nativo + CPT legacy) e
         *     post_status 'any' (include publish/future/draft/pending/private,
         *     esclude trash/auto-draft per exclude_from_search).
         *
         * UNA sola query nel fallback (fields ids, no_found_rows, cache meta
         * e termini disattivate).
         *
         * @param int    $article_id ID articolo Nexus.
         * @param object $article    Riga articolo Nexus (o null): se presente
         *                            wp_post_id abilita il fast path.
         * @return int Post WordPress o 0 se non trovato.
         */
        public static function resolve_article_post_id( $article_id, $article = null ) {
                $article_id = absint( $article_id );
                if ( $article_id <= 0 || ! class_exists( 'WP_Query' ) ) {
                        return 0;
                }

                // 1) Percorso veloce, stesso check del publisher: wp_post_id + esistenza.
                if ( is_object( $article ) && isset( $article->wp_post_id ) ) {
                        $wp_post_id = absint( $article->wp_post_id );
                        if ( $wp_post_id > 0 && get_post( $wp_post_id ) ) {
                                return $wp_post_id;
                        }
                }

                // 2) Fallback: meta inverso scritto dal publisher
                //    (update_post_meta '_nexus_article_id'), tutti i post type pipeline.
                $query = new WP_Query(
                        array(
                                'post_type'              => self::pipeline_post_types(),
                                'post_status'            => 'any',
                                'fields'                 => 'ids',
                                'posts_per_page'         => 1,
                                'no_found_rows'          => true,
                                'ignore_sticky_posts'    => true,
                                'update_post_term_cache' => false,
                                'update_post_meta_cache' => false,
                                'meta_query'             => array(
                                        array(
                                                'key'   => '_nexus_article_id',
                                                'value' => $article_id,
                                        ),
                                ),
                        )
                );

                if ( ! empty( $query->posts ) ) {
                        return absint( $query->posts[0] );
                }

                return 0;
        }

        /* ============ ALGORITMO: NORMALIZZAZIONE + SIMHASH ============ */

        /**
         * Normalizza il testo per il fingerprinting: strip tag, decode entità,
         * lowercase, solo lettere/numeri separati da spazi singoli.
         *
         * Unicode: se PCRE supporta le classi \p{L}/\p{N} (verificato con un
         * preg_match di prova, esito memoizzato) usa i range Unicode; il fallback
         * ASCII [a-z0-9] perde le lettere accentate non ASCII (limitazione
         * documentata, accettabile: è il percorso degradato, non il default).
         *
         * @param string $text Testo grezzo (può contenere HTML).
         * @return string Testo normalizzato.
         */
        private static function normalize( $text ) {
                $text = (string) $text;

                // Rimuovi markup HTML (il contenuto arriva da wp_kses_post/RSS).
                if ( function_exists( 'wp_strip_all_tags' ) ) {
                        $text = wp_strip_all_tags( $text );
                } else {
                        $text = strip_tags( $text );
                }

                // Decode entità: &amp; e soci diventano i caratteri reali prima
                // del filtro lettere/numeri.
                if ( function_exists( 'html_entity_decode' ) ) {
                        $text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
                }

                // Lowercase robusto (helper condiviso con interlink).
                $text = self::utf8_lower( $text );

                // Solo lettere/numeri (Unicode se PCRE lo supporta, altrimenti ASCII).
                if ( self::unicode_regex_ok() ) {
                        $text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );
                } else {
                        $text = preg_replace( '/[^a-z0-9]+/', ' ', $text );
                }

                return trim( (string) $text );
        }

        /**
         * Verifica (memoizzata, una sola volta per run) che PCRE supporti le
         * classi Unicode \p{L}/\p{N} col modificatore /u.
         *
         * @return bool True se il pattern Unicode è utilizzabile.
         */
        private static function unicode_regex_ok() {
                static $ok = null;
                if ( null === $ok ) {
                        $ok = ( 1 === @preg_match( '/\p{L}/u', 'a' ) );
                }
                return $ok;
        }

        /**
         * Estrae i 4-grammi di parole consecutive dal testo normalizzato.
         *
         * Con meno di SHINGLE_WORDS parole ritorna un unico shingle con tutte
         * le parole (testi minimi: fingerprint stabile comunque).
         *
         * @param string $text Testo normalizzato.
         * @return array Lista di shingle (stringhe).
         */
        private static function shingles( $text ) {
                $words = preg_split( '/\s+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
                if ( ! is_array( $words ) ) {
                        return array();
                }

                $count = count( $words );
                if ( 0 === $count ) {
                        return array();
                }

                if ( $count < self::SHINGLE_WORDS ) {
                        return array( implode( ' ', $words ) );
                }

                $out  = array();
                $last = $count - self::SHINGLE_WORDS;
                for ( $i = 0; $i <= $last; $i++ ) {
                        $out[] = implode( ' ', array_slice( $words, $i, self::SHINGLE_WORDS ) );
                }
                return $out;
        }

        /**
         * Calcola il fingerprint simhash 64-bit del testo.
         *
         * Schema bit (coerente con hamming()):
         *  - per ogni shingle: h = md5(shingle), primi 16 char hex →
         *    h1 = hexdec(char 0..7), h2 = hexdec(char 8..15) (entrambi < 2^32,
         *    nessun overflow su int PHP a 64 bit);
         *  - bit b del feature hash: b < 32 → (h1 >> b) & 1,
         *    b >= 32 → (h2 >> (b - 32)) & 1;
         *  - contatori[b] += (+1 se bit 1, -1 se bit 0) su tutti gli shingle;
         *  - bit i del fingerprint = 1 se contatori[i] > 0;
         *  - rappresentazione hex: primi 8 char = bit 0..31, ultimi 8 = bit 32..63.
         *
         * @param string $text Testo grezzo (titolo, sommario, contenuto...).
         * @return string Fingerprint hex a 16 caratteri, o '' se nessuno shingle.
         */
        public static function fingerprint( $text ) {
                $shingles = self::shingles( self::normalize( $text ) );
                if ( empty( $shingles ) ) {
                        return '';
                }

                // 64 contatori: +1 per bit 1, -1 per bit 0, somma su tutti gli shingle.
                $counters = array_fill( 0, self::BITS, 0 );

                foreach ( $shingles as $shingle ) {
                        $hex = md5( $shingle );
                        $h1  = hexdec( substr( $hex, 0, 8 ) ); // bit 0..31 (< 2^32, ok su int 64).
                        $h2  = hexdec( substr( $hex, 8, 8 ) ); // bit 32..63 (< 2^32, ok su int 64).

                        for ( $b = 0; $b < self::BITS; $b++ ) {
                                if ( $b < 32 ) {
                                        $bit = ( $h1 >> $b ) & 1;
                                } else {
                                        $bit = ( $h2 >> ( $b - 32 ) ) & 1;
                                }
                                $counters[ $b ] += ( 1 === $bit ) ? 1 : -1;
                        }
                }

                // Pacchettizza i 64 bit in due int 32-bit (hex 8+8 char):
                // niente int firmato, niente perdita di precisione.
                $hi = 0;
                $lo = 0;
                for ( $b = 0; $b < 32; $b++ ) {
                        if ( $counters[ $b ] > 0 ) {
                                $hi |= ( 1 << $b );
                        }
                        if ( $counters[ $b + 32 ] > 0 ) {
                                $lo |= ( 1 << $b );
                        }
                }

                return sprintf( '%08x', $hi ) . sprintf( '%08x', $lo );
        }

        /**
         * Distanza Hamming tra due fingerprint hex (0..64).
         *
         * Conversione: ogni fingerprint hex a 16 char → 2 x int32 (hexdec su
         * 8+8 char) → XOR + popcount. Fingerprint malformati/assenti valgono
         * la massima distanza (64): mai considerati simili, i post vecchi
         * senza meta vengono così saltati per costruzione.
         *
         * @param string $hex_a Primo fingerprint (16 char hex).
         * @param string $hex_b Secondo fingerprint (16 char hex).
         * @return int Distanza Hamming 0..64.
         */
        public static function hamming( $hex_a, $hex_b ) {
                if ( ! self::is_fingerprint( $hex_a ) || ! self::is_fingerprint( $hex_b ) ) {
                        return self::BITS;
                }

                if ( $hex_a === $hex_b ) {
                        return 0;
                }

                $a1 = hexdec( substr( $hex_a, 0, 8 ) );
                $a2 = hexdec( substr( $hex_a, 8, 8 ) );
                $b1 = hexdec( substr( $hex_b, 0, 8 ) );
                $b2 = hexdec( substr( $hex_b, 8, 8 ) );

                return self::popcount32( $a1 ^ $b1 ) + self::popcount32( $a2 ^ $b2 );
        }

        /**
         * Popcount di un intero < 2^32 (conta i bit a 1 dell'XOR).
         *
         * Con GMP usa gmp_popcount; altrimenti decbin + substr_count sul
         * binario a 32 bit (decbin omette gli zero iniziali: str_pad a sinistra).
         *
         * @param int $x Valore 0..4294967295.
         * @return int Numero di bit a 1.
         */
        private static function popcount32( $x ) {
                $x = (int) $x;

                if ( function_exists( 'gmp_popcount' ) && function_exists( 'gmp_init' ) ) {
                        return (int) gmp_popcount( gmp_init( $x, 10 ) );
                }

                $bin = str_pad( decbin( $x ), 32, '0', STR_PAD_LEFT );
                return (int) substr_count( $bin, '1' );
        }

        /**
         * Riconosce un fingerprint valido: stringa di esattamente 16 char hex.
         *
         * @param mixed $value Valore da validare (tipicamente get_post_meta).
         * @return bool True se fingerprint ben formato.
         */
        private static function is_fingerprint( $value ) {
                return is_string( $value )
                        && 16 === strlen( $value )
                        && (bool) preg_match( '/^[0-9a-f]{16}$/', $value );
        }

        /* ============ HOOK PIPELINE S0 ============ */

        /**
         * Filtro 'nexus_pipeline_skip_item': scarta i candidati simili a post
         * già pubblicati (dedup semantico simhash).
         *
         * GUARDIA v1.1.13-wip: qualunque errore (anche un Error) degrada al
         * ritorno $skip INTATTO — il dedup non deve MAI rompere la raccolta.
         *
         * @param bool  $skip      Esito dei filtri precedenti (true = già scartato).
         * @param array $candidate Candidato pipeline (title/summary/content/sourceName/sourceUrl/score).
         * @param mixed $agent     Riga agente (non usato, parte del contratto hook).
         * @param bool  $evergreen True se pipeline evergreen (non usato, contratto hook).
         * @return bool True per scartare il candidato, altrimenti $skip invariato.
         */
        public static function maybe_skip_similar( $skip, $candidate, $agent, $evergreen ) {
                try {
                        return self::skip_similar_core( $skip, $candidate );
                } catch ( \Throwable $e ) {
                        return $skip;
                }
        }

        /**
         * Corpo di maybe_skip_similar (protetto dal try/catch del callback).
         *
         * @param bool  $skip      Esito dei filtri precedenti.
         * @param array $candidate Candidato pipeline.
         * @return bool Esito del filtro.
         */
        private static function skip_similar_core( $skip, $candidate ) {
                // Già scartato da un filtro precedente: nessun lavoro extra.
                if ( $skip ) {
                        return $skip;
                }

                // Modulo disattivato dalle impostazioni: esito invariato.
                $settings = self::settings_guarded();
                if ( 1 !== (int) ( isset( $settings['dedup_similar_enabled'] ) ? $settings['dedup_similar_enabled'] : 1 ) ) {
                        return $skip;
                }

                // Candidato malformato o privo di testo: niente da confrontare.
                if ( ! is_array( $candidate ) ) {
                        return $skip;
                }
                $text = ( isset( $candidate['title'] ) ? (string) $candidate['title'] : '' )
                        . ' '
                        . ( isset( $candidate['summary'] ) ? (string) $candidate['summary'] : '' )
                        . ' '
                        . ( isset( $candidate['content'] ) ? (string) $candidate['content'] : '' );

                $fingerprint = self::fingerprint( $text );
                if ( '' === $fingerprint ) {
                        return $skip;
                }

                // Soglia: default 6, clampata 0..64 (64 = distanza massima possibile).
                $threshold = (int) ( isset( $settings['dedup_similar_threshold'] ) ? $settings['dedup_similar_threshold'] : 6 );
                if ( $threshold < 0 ) {
                        $threshold = 0;
                }
                if ( $threshold > self::BITS ) {
                        $threshold = self::BITS;
                }

                // Limiti di scala (option + filtro dev + clamp, v1.1.13-wip).
                $window_days = self::window_days();
                $lookup_max  = self::lookup_max();

                // UNA sola query: id dei post pipeline con fingerprint, ultimi N giorni.
                // Tutti i post type pipeline (nativo + CPT legacy 'nexus_article'):
                // il meta esiste solo dove la pipeline ha pubblicato, quindi i tipi
                // extra non aggiungono righe alla scan (query indicizzata).
                $query = new WP_Query(
                        array(
                                'post_type'              => self::pipeline_post_types(),
                                'post_status'            => 'publish',
                                'fields'                 => 'ids',
                                'posts_per_page'         => $lookup_max,
                                'no_found_rows'          => true,
                                'ignore_sticky_posts'    => true,
                                'update_post_term_cache' => false,
                                'update_post_meta_cache' => false, // La facciamo a mano qui sotto.
                                'meta_query'             => array(
                                        array(
                                                'key'     => self::META_KEY,
                                                'compare' => 'EXISTS',
                                        ),
                                ),
                                'date_query'             => array(
                                        array(
                                                'column' => 'post_date_gmt',
                                                'after'  => gmdate( 'Y-m-d H:i:s', time() - ( $window_days * DAY_IN_SECONDS ) ),
                                        ),
                                ),
                        )
                );

                $ids = $query->posts;
                if ( empty( $ids ) ) {
                        return $skip;
                }

                // SEGNALAZIONE ONESTA (v1.1.13-wip): cap raggiunto = confronto
                // troncato per costruzione (gli articoli della finestra oltre il
                // cap non vengono confrontati). Heuristica a COSTO ZERO (nessun
                // SQL_CALC_FOUND_ROWS): N trovati >= cap. Throttle 1/giorno.
                if ( count( $ids ) >= $lookup_max ) {
                        self::log_window_notice( count( $ids ), $window_days );
                }

                // Precarica TUTTI i meta dei post trovati con UNA query SQL;
                // i get_post_meta nel loop vengono serviti dalla cache.
                update_meta_cache( 'post', $ids );

                foreach ( $ids as $post_id ) {
                        $other = get_post_meta( (int) $post_id, self::META_KEY, true );

                        // Post vecchi senza fingerprint (pre-S3): saltati, il
                        // sistema si popola col tempo (vedi docblock di classe).
                        if ( ! self::is_fingerprint( $other ) ) {
                                continue;
                        }

                        if ( self::hamming( $fingerprint, $other ) <= $threshold ) {
                                return true; // Contenuto simile: scarta (pipeline logga 'duplicate').
                        }
                }

                return $skip;
        }

        /**
         * Azione 'nexus_pipeline_after_publish': salva il fingerprint simhash
         * calcolato dal contenuto FINALE del post WordPress appena pubblicato
         * (title + excerpt + post_content, incluse eventuali trasformazioni del
         * publisher: kses, blocco citazioni, estratto da summary).
         *
         * GUARDIA v1.1.13-wip: qualunque errore (anche un Error) viene
         * ingoiato — la pubblicazione non deve MAI fallire per il dedup.
         *
         * INDIPENDENTE DAL POST TYPE: la risoluzione del post passa
         * dall'helper condiviso resolve_article_post_id (fast path wp_post_id
         * + fallback meta _nexus_article_id su TUTTI i post type pipeline).
         *
         * @param int    $article_id ID articolo Nexus.
         * @param object $article    Riga articolo Nexus (stdClass da Nexus_DB::get_article).
         * @param array  $logs       Log di pubblicazione per piattaforma (non usato, contratto hook).
         * @return void
         */
        public static function store_fingerprint( $article_id, $article, $logs ) {
                try {
                        self::store_fingerprint_core( $article_id, $article );
                } catch ( \Throwable $e ) {
                        // Guardia: niente propagazione, la pubblicazione è già avvenuta.
                }
        }

        /**
         * Corpo di store_fingerprint (protetto dal try/catch del callback).
         *
         * @param int    $article_id ID articolo Nexus.
         * @param object $article    Riga articolo Nexus (o null).
         * @return void
         */
        private static function store_fingerprint_core( $article_id, $article ) {
                $article_id = absint( $article_id );
                if ( $article_id <= 0 ) {
                        return;
                }

                $post_id = self::resolve_article_post_id( $article_id, $article );
                if ( $post_id <= 0 ) {
                        return; // Post non trovato: silenzioso (guard, vedi docblock).
                }

                $post = get_post( $post_id );
                if ( ! $post instanceof WP_Post ) {
                        return;
                }

                // Stessa base del candidato (skip_item): title + summary/excerpt + content.
                $fingerprint = self::fingerprint( $post->post_title . ' ' . $post->post_excerpt . ' ' . $post->post_content );
                if ( '' === $fingerprint ) {
                        // Contenuto svuotato: rimuovi eventuale fingerprint stantio.
                        delete_post_meta( $post_id, self::META_KEY );
                        return;
                }

                update_post_meta( $post_id, self::META_KEY, $fingerprint );
        }

        /* ============ LIMITI DI SCALA + SEGNALAZIONE ONESTA ============ */

        /**
         * Finestra temporale della lookup (giorni): option
         * nexus_dedup_window_days (default 90) + filtro dev omonimo, clamp [1, 3650].
         *
         * @return int
         */
        private static function window_days() {
                return self::limit_option( 'nexus_dedup_window_days', 'nexus_dedup_window_days', self::WINDOW_DAYS, 1, 3650 );
        }

        /**
         * Cap della lookup (post id per run): option
         * nexus_dedup_lookup_max (default 400) + filtro dev omonimo, clamp
         * [1, 10000] (il tetto protegge il preload della meta cache).
         *
         * @return int
         */
        private static function lookup_max() {
                return self::limit_option( 'nexus_dedup_lookup_max', 'nexus_dedup_lookup_max', self::LOOKUP_MAX, 1, 10000 );
        }

        /**
         * Segnalazione onesta "confronto troncato": UNA riga in activity log
         * (meccanismo esistente Nexus_DB::insert_activity, action 'dedup',
         * status 'warning') THROTTLED a 1/giorno via transient
         * self::THROTTLE_TRANSIENT. MAI spam: il turno giornaliero si consuma
         * al primo trigger, anche se l'insert fallisce (ambiente degradato:
         * niente tentativi a raffica per ogni candidato); deactivate() lo azzera.
         *
         * @param int $compared   Post effettivamente confrontati (= cap).
         * @param int $window_days Finestra in giorni applicata alla lookup.
         * @return void
         */
        private static function log_window_notice( $compared, $window_days ) {
                if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
                        return; // Ambiente WP incompleto: silenzioso.
                }

                if ( get_transient( self::THROTTLE_TRANSIENT ) ) {
                        return; // Già segnalato nelle ultime 24h.
                }

                $message = sprintf(
                        /* translators: 1: numero articoli confrontati (cap), 2: finestra in giorni. */
                        __( 'Dedup: confronto limitato a %1$d articoli (finestra %2$d gg) — duplicati recenti oltre il limite non verificati. Alzalo in Impostazioni → Sistema → Limiti e scala.', 'nexus-news-ai' ),
                        (int) $compared,
                        (int) $window_days
                );

                set_transient( self::THROTTLE_TRANSIENT, 1, DAY_IN_SECONDS );

                self::log_activity( $message, 'warning' );
        }

        /**
         * Log attività best-effort (action 'dedup') nella tabella
         * activity_logs di Nexus — stesso pattern di Learn e Google_Auth
         * (guard class_exists/method_exists, mai fatale).
         *
         * @param string $detail Testo del log (già composto e i18n-ready).
         * @param string $status 'info' | 'warning' | 'error' | 'success'.
         * @return bool True se la riga è stata scritta.
         */
        private static function log_activity( $detail, $status ) {
                if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                        return (bool) Nexus_DB::insert_activity( 0, 'dedup', $detail, $status );
                }
                return false;
        }
}

add_action( 'nexus_modules_init', array( 'Nexus_Dedup', 'init' ) );
