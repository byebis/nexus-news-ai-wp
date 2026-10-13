<?php
/**
 * Nexus Dedup — dedup semantico simhash 64-bit (v1.1.12, pacchetto S3).
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
 * INTEGRAZIONE PIPELINE:
 *  - filtro 'nexus_pipeline_skip_item' (S0, pipeline riga ~322): un candidato
 *    il cui fingerprint è a distanza Hamming <= soglia da un post pubblicato
 *    negli ultimi 90 giorni viene scartato PRIMA dell'INSERT (il pipeline
 *    logga già lo scarto come 'duplicate').
 *  - azione 'nexus_pipeline_after_publish' (S0, pipeline riga ~755): calcola
 *    il fingerprint dal contenuto FINALE del post WordPress pubblicato
 *    (post_id risolto come fa il publisher: prima article->wp_post_id, poi
 *    meta inverso _nexus_article_id) e lo salva in _nexus_simhash.
 *
 * POST VECCHI: gli articoli pubblicati PRIMA di questa versione non hanno il
 * meta _nexus_simhash: vengono semplicemente SALTATI dalla lookup (meta_query
 * EXISTS) e il sistema si popola da solo man mano che i nuovi articoli
 * vengono pubblicati. Nessuna migrazione retroattiva richiesta.
 *
 * PERFORMANCE: la lookup del filtro fa UNA sola WP_Query (fields ids, cap
 * 400, no_found_rows) + update_meta_cache() per precaricare TUTTI i meta
 * _nexus_simhash in un colpo solo; i confronti Hamming sono in memoria e
 * get_post_meta è servito dalla cache (zero query extra nel loop).
 *
 * CONTRATTO PUBBLICO:
 *  - Nexus_Dedup::fingerprint( $text )  → string hex 16 char (o '' se testo vuoto)
 *  - Nexus_Dedup::hamming( $a, $b )     → int 0..64
 *  - Nexus_Dedup::maybe_skip_similar()  → callback filtro skip_item
 *  - Nexus_Dedup::store_fingerprint()   → callback azione after_publish
 *  - Nexus_Dedup::deactivate()          → no-op (uniformità con gli altri moduli)
 *
 * CONSUMA: Nexus_DB::get_settings() (setting dedup_similar_enabled default 1,
 * dedup_similar_threshold default 6); post type reale del publisher, filtrabile
 * con 'nexus_native_post_type' (default 'post').
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
         * Finestra temporale della lookup in giorni (ultimi 90 giorni).
         *
         * @var int
         */
        const WINDOW_DAYS = 90;

        /**
         * Cap della lookup: massimo 400 post id per run (UNA sola query).
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
         * Bootstrap del modulo: registra filtro e azione sulla pipeline S0.
         *
         * @return void
         */
        public static function init() {
                add_filter( 'nexus_pipeline_skip_item', array( 'Nexus_Dedup', 'maybe_skip_similar' ), 10, 4 );
                add_action( 'nexus_pipeline_after_publish', array( 'Nexus_Dedup', 'store_fingerprint' ), 10, 3 );
        }

        /**
         * Disattivazione: nessuna pulizia necessaria (i meta _nexus_simhash
         * restano inutilizzati ma innocui; il sistema si ripopula da solo).
         *
         * @return void
         */
        public static function deactivate() {
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

                // Lowercase con mbstring se disponibile, altrimenti strtolower.
                if ( function_exists( 'mb_strtolower' ) ) {
                        $text = mb_strtolower( $text, 'UTF-8' );
                } else {
                        $text = strtolower( $text );
                }

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

                $out = array();
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
         * Ritorna $skip INTATTO quando: un filtro a priorità più alta ha già
         * scartato, il setting dedup_similar_enabled non è 1, il candidato è
         * privo di testo utile, la lookup non trova simili. Ritorna true SOLO
         * quando la distanza Hamming <= dedup_similar_threshold (il pipeline
         * logga già lo scarto come 'duplicate').
         *
         * PERFORMANCE: UNA sola WP_Query (fields ids, cap 400, ultimi 90 giorni,
         * meta _nexus_simhash EXISTS) + update_meta_cache() per precaricare
         * tutti i fingerprint in un colpo; nessuna altra query nel filtro.
         *
         * @param bool  $skip      Esito dei filtri precedenti (true = già scartato).
         * @param array $candidate Candidato pipeline (title/summary/content/sourceName/sourceUrl/score).
         * @param mixed $agent     Riga agente (non usato, parte del contratto hook).
         * @param bool  $evergreen True se pipeline evergreen (non usato, contratto hook).
         * @return bool True per scartare il candidato, altrimenti $skip invariato.
         */
        public static function maybe_skip_similar( $skip, $candidate, $agent, $evergreen ) {
                // Già scartato da un filtro precedente: nessun lavoro extra.
                if ( $skip ) {
                        return $skip;
                }

                // Modulo disattivato dalle impostazioni: esito invariato.
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'get_settings' ) ) {
                        return $skip;
                }
                $settings = Nexus_DB::get_settings();
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

                // UNA sola query: id dei post pubblicati con fingerprint, ultimi 90 giorni.
                $query = new WP_Query(
                        array(
                                'post_type'              => apply_filters( 'nexus_native_post_type', 'post' ),
                                'post_status'            => 'publish',
                                'fields'                 => 'ids',
                                'posts_per_page'         => self::LOOKUP_MAX,
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
                                                'after'  => gmdate( 'Y-m-d H:i:s', time() - ( self::WINDOW_DAYS * 86400 ) ),
                                        ),
                                ),
                        )
                );

                $ids = $query->posts;
                if ( empty( $ids ) ) {
                        return $skip;
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
         * Guardie: senza post risolvibile o post senza testo utile esce
         * silenzioso — la pubblicazione non deve MAI fallire per il dedup.
         *
         * @param int    $article_id ID articolo Nexus.
         * @param object $article    Riga articolo Nexus (stdClass da Nexus_DB::get_article).
         * @param array  $logs       Log di pubblicazione per piattaforma (non usato, contratto hook).
         * @return void
         */
        public static function store_fingerprint( $article_id, $article, $logs ) {
                $article_id = absint( $article_id );
                if ( $article_id <= 0 ) {
                        return;
                }

                $post_id = self::resolve_post_id( $article_id, $article );
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

        /**
         * Risolve il post_id WordPress di un articolo Nexus, come fa il
         * publisher (v1.1.0 "Tutto nativo", meta _nexus_article_id):
         *  1) article->wp_post_id (il publisher lo aggiorna PRIMA dell'hook);
         *  2) fallback: lookup inversa sul meta _nexus_article_id (UNA query,
         *     fields ids, post_status 'any' = include publish/draft/pending).
         *
         * @param int    $article_id ID articolo Nexus.
         * @param object $article    Riga articolo Nexus (o null).
         * @return int Post WordPress o 0 se non trovato.
         */
        private static function resolve_post_id( $article_id, $article ) {
                // Percorso veloce, stesso check del publisher: wp_post_id + esistenza post.
                if ( is_object( $article ) && isset( $article->wp_post_id ) ) {
                        $wp_post_id = absint( $article->wp_post_id );
                        if ( $wp_post_id > 0 && get_post( $wp_post_id ) ) {
                                return $wp_post_id;
                        }
                }

                // Fallback: meta inverso scritto dal publisher (update_post_meta
                // '_nexus_article_id'). 'any' esclude trash/auto-draft.
                $query = new WP_Query(
                        array(
                                'post_type'              => apply_filters( 'nexus_native_post_type', 'post' ),
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
}

add_action( 'nexus_modules_init', array( 'Nexus_Dedup', 'init' ) );
