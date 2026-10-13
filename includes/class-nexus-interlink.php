<?php
/**
 * Nexus Interlink — link interni automatici sui post pubblicati (v1.1.12, pacchetto S4;
 * v1.1.13-wip "Ottimizzazione" — limiti configurabili + helper condivisi col dedup).
 *
 * AGGANCIO:
 *   azione 'nexus_pipeline_after_publish' (article_id, article, logs) lanciata da
 *   Nexus_Pipeline::publish (v1.1.12/S0) DOPO una pubblicazione riuscita su almeno
 *   una piattaforma. $article è la riga della tabella wp_nexus_articles
 *   (Nexus_DB::get_article: title, content, category, agent_id, wp_post_id, ...).
 *
 * COSA FA:
 *   1. Se il setting interlink_enabled != 1 esce subito (default 1, vedi
 *      class-nexus-db.php).
 *   2. Risolve il post WP collegato all'articolo Nexus con l'helper condiviso
 *      Nexus_Dedup::resolve_article_post_id (UNICA versione da v1.1.13-wip:
 *      fast path article->wp_post_id + fallback meta _nexus_article_id su
 *      TUTTI i post type pipeline — post nativo + CPT legacy 'nexus_article',
 *      stesso criterio di Nexus_Public_API e Nexus_Native::query_post_types).
 *   3. Anti-reprocess: se il post porta già il meta _nexus_interlinked esce
 *      (nessun blocco duplicato, nessun loop).
 *   4. Estrae fino a Nexus_Interlink::KEYWORDS_MAX (6) keyword dal titolo del
 *      post corrente: parole di 5+ caratteri, senza le stopword italiane base
 *      (Nexus_Interlink::STOPWORDS). Un titolo corto degrada con grazia a
 *      meno di 4 keyword (target del contratto: 4-6).
 *   5. Candidati: WP_Query sullo STESSO post type, stato 'publish', escluso il
 *      post corrente, ultimi nexus_interlink_window_days giorni (option WP,
 *      default 180, filtro dev omonimo), max nexus_interlink_candidates
 *      (option WP, default 20, filtro dev omonimo) con fields 'ids' (titoli
 *      letti con get_the_title).
 *   6. Scoring: +Nexus_Interlink::KEYWORD_SCORE (2) per ogni keyword presente
 *      nel titolo del candidato (confronto lowercase via stripos/mb_stripos),
 *      +Nexus_Interlink::CATEGORY_SCORE (1) se condivide almeno un termine
 *      della tassonomia nativa 'category' (quella assegnata dal publisher con
 *      wp_set_post_categories; 'nexus_redazione' è proprietà editoriale
 *      dell'agente, NON argomento, e non entra nello scoring). Ordina per
 *      punteggio decrescente (tie-break: id crescente) e tiene i primi
 *      interlink_max (default 3) con punteggio > 0.
 *   7. Se c'è almeno un candidato APPENDE IN CODA al post_content (il corpo
 *      esistente non viene mai toccato; wp_update_post solo se il contenuto
 *      cambia):
 *        <div class="nexus-interlink"><h4>Leggi anche</h4>
 *          <ul><li><a href="{permalink}">{titolo}</a></li>...</ul></div>
 *      con URL protetti da esc_url e titoli da esc_html. wp_kses_post gira SOLO
 *      sul blocco generato, mai sul contenuto preesistente.
 *   8. Meta di tracciabilità/anti-loop: _nexus_interlinked = 1 e
 *      _nexus_interlink_ids = CSV degli ID linkati (scritti solo se l'update
 *      del contenuto è riuscito, così un fallimento resta riprocessabile).
 *
 * IMPOSTAZIONI DI SCALA (v1.1.13-wip — prima hardcoded, ora opzioni):
 *   - nexus_interlink_window_days (option WP, default 180): finestra
 *     temporale dei candidati in giorni; filtro dev omonimo.
 *   - nexus_interlink_candidates (option WP, default 20): cap candidati
 *     interrogati per pubblicazione; filtro dev omonimo.
 *   Lettura centralizzata nell'helper condiviso Nexus_Dedup::limit_option
 *   (get_option(default) → filtro dev → clamp [1, max]): stesso pattern del
 *   dedup (vedi class-nexus-dedup.php).
 *
 * HELPER CONDIVISI (v1.1.13-wip — v1.1.12 aveva copie private divergenti):
 *   - Nexus_Dedup::settings_guarded()        → impostazioni con guardie
 *   - Nexus_Dedup::limit_option()            → option + filtro dev + clamp
 *   - Nexus_Dedup::utf8_lower()              → lowercase robusto UTF-8
 *   - Nexus_Dedup::resolve_article_post_id() → risoluzione post ↔ articolo
 *   Entrambi i moduli sono sempre richiesti dal bootstrap (nessun ciclo di
 *   caricamento); la try/catch del callback copre comunque qualunque
 *   caricamento parziale (mai fatale).
 *
 * GUARDIA (v1.1.13-wip): il callback pipeline gira dentro try/catch
 * Throwable — la pubblicazione è già avvenuta, questo modulo non deve MAI
 * interrompere la pipeline.
 *
 * CONTRATTO PUBBLICO (tutti metodi statici, nessun output diretto):
 *   Nexus_Interlink::init()             → registra l'hook pipeline
 *   Nexus_Interlink::on_after_publish() → callback nexus_pipeline_after_publish
 *   Nexus_Interlink::deactivate()       → hook disattivazione (vuota, uniformità)
 *
 * Dipendenze guarded (mai fatali): Nexus_DB::get_settings() (via
 * Nexus_Dedup::settings_guarded), Nexus_Dedup (helper condivisi), funzioni WP
 * core. Il modulo si auto-inizializza su 'nexus_modules_init' (vedi fondo file
 * e bootstrap).
 *
 * @package Nexus_News_AI
 * @since   1.1.12
 */

defined( 'ABSPATH' ) || exit;

/**
 * Classe Nexus_Interlink — link interni automatici "Leggi anche".
 */
final class Nexus_Interlink {

        /**
         * Etichetta dell'intestazione del blocco link interni.
         *
         * NOTA i18n: stringa fissa IT nella v1.1.12 — da tradurre nello sweep
         * i18n futuro (il modulo non dipende dal dict Nexus_I18n).
         */
        const LABEL = 'Leggi anche';

        /**
         * Stopword italiane base escluse dall'estrazione delle keyword.
         * Le parole di lunghezza <= 4 caratteri sono comunque escluse dal
         * filtro di lunghezza: la lista serve per "della", "degli", "anche",
         * "delle" e per esplicitare il contratto S4.
         */
        const STOPWORDS = array(
                'il', 'di', 'che', 'della', 'degli', 'per', 'con', 'non', 'una',
                'come', 'sono', 'anche', 'più', 'tra', 'dal', 'nel', 'alla', 'delle',
        );

        /**
         * DEFAULT della finestra temporale dei candidati (giorni indietro
         * dalla pubblicazione).
         *
         * Il valore effettivo è l'option nexus_interlink_window_days (leggere
         * SEMPRE via self::window_days(): default → filtro dev → clamp).
         */
        const WINDOW_DAYS = 180;

        /**
         * DEFAULT del numero massimo di candidati interrogati (fields 'ids').
         *
         * Il valore effettivo è l'option nexus_interlink_candidates (leggere
         * SEMPRE via self::candidates_limit(): default → filtro dev → clamp).
         */
        const CANDIDATES_LIMIT = 20;

        /** Keyword massime estratte dal titolo (target contrattuale S4: 4-6). */
        const KEYWORDS_MAX = 6;

        /** Punti per ogni keyword presente nel titolo del candidato. */
        const KEYWORD_SCORE = 2;

        /** Punti se il candidato condivide almeno una categoria col post corrente. */
        const CATEGORY_SCORE = 1;

        /** Punteggio minimo per selezionare un candidato (contratto: > 0). */
        const MIN_SCORE = 1;

        /** Meta anti-reprocess: 1 = il post ha già ricevuto il blocco. */
        const META_FLAG = '_nexus_interlinked';

        /** Meta tracciabilità: CSV degli ID dei post linkati. */
        const META_IDS = '_nexus_interlink_ids';

        /** Marker CSS del blocco (usato anche come cintura-e-bretelle anti-duplicato). */
        const BLOCK_CLASS = 'nexus-interlink';

        /**
         * Bootstrap: aggancia il modulo alla pipeline di pubblicazione.
         *
         * Chiamato su 'nexus_modules_init' (self-registrazione, fondo file).
         *
         * @return void
         */
        public static function init() {
                add_action( 'nexus_pipeline_after_publish', array( __CLASS__, 'on_after_publish' ), 10, 3 );
        }

        /**
         * Hook di disattivazione (uniformità con gli altri moduli S1-S7).
         *
         * Vuota di proposito: non ci sono opzioni dedicate da rimuovere e i
         * meta anti-reprocess (_nexus_interlinked / _nexus_interlink_ids)
         * restano come storico dei post già processati.
         *
         * @return void
         */
        public static function deactivate() {
        }

        /**
         * Callback di 'nexus_pipeline_after_publish'.
         *
         * GUARDIA v1.1.13-wip: qualunque errore (anche un Error) viene
         * ingoiato — la pubblicazione è già avvenuta, questo modulo non deve
         * MAI interrompere la pipeline.
         *
         * @param int    $article_id ID articolo Nexus (riga wp_nexus_articles).
         * @param object $article    Riga articolo Nexus (Nexus_DB::get_article).
         * @param array  $logs       Log di pubblicazione per piattaforma (non
         *                           usato in v1, contratto della action).
         * @return void
         */
        public static function on_after_publish( $article_id, $article, $logs ) {
                try {
                        self::on_after_publish_core( $article_id, $article );
                } catch ( \Throwable $e ) {
                        // Guardia: nessuna propagazione verso la pipeline.
                }
        }

        /**
         * Corpo di on_after_publish (protetto dal try/catch del callback).
         *
         * Sequenza: helper condivisi disponibili? → interruttore setting →
         * risoluzione post → anti-reprocess → keyword + candidati + append
         * blocco + meta.
         *
         * @param int    $article_id ID articolo Nexus.
         * @param object $article    Riga articolo Nexus (o null).
         * @return void
         */
        private static function on_after_publish_core( $article_id, $article ) {
                // Helper condivisi vivono in Nexus_Dedup (stesso file-pair O6,
                // bootstrap richiede sempre entrambi i moduli): se per un
                // caricamento parziale manca, esce in silenzio.
                if ( ! class_exists( 'Nexus_Dedup' ) ) {
                        return;
                }

                // 1) Interruttore: setting interlink_enabled (default 1).
                $settings = Nexus_Dedup::settings_guarded();
                if ( 1 !== absint( isset( $settings['interlink_enabled'] ) ? $settings['interlink_enabled'] : 0 ) ) {
                        return;
                }

                // 2) Risoluzione post WP ↔ articolo Nexus (helper condiviso
                //    UNICO v1.1.13-wip: fast path wp_post_id + fallback meta
                //    _nexus_article_id su tutti i post type pipeline, CPT
                //    legacy 'nexus_article' incluso).
                $post_id = Nexus_Dedup::resolve_article_post_id( $article_id, $article );
                if ( $post_id <= 0 ) {
                        return;
                }

                // 3) Anti-reprocess: il post ha già ricevuto il blocco.
                if ( get_post_meta( $post_id, self::META_FLAG, true ) ) {
                        return;
                }

                // 4-8) Keyword, candidati, append, meta tracciabilità.
                self::run_interlink( $post_id, $settings );
        }

        /**
         * Corpo dell'elaborazione (keyword → candidati → blocco → meta).
         *
         * @param int   $post_id  Post WP appena pubblicato.
         * @param array $settings Impostazioni plugin (Nexus_DB::get_settings()).
         * @return void
         */
        private static function run_interlink( $post_id, $settings ) {
                // Ambiente WP incompleto (caso limite): esce in silenzio.
                if ( ! function_exists( 'wp_update_post' ) || ! function_exists( 'update_post_meta' ) ) {
                        return;
                }

                // Keyword dal titolo del post corrente (target 4-6, parole > 4 caratteri).
                $keywords = self::extract_keywords( get_the_title( absint( $post_id ) ) );
                if ( count( $keywords ) < 1 ) {
                        return; // senza keyword nessuno scoring è possibile.
                }

                // Candidati ordinati per punteggio (solo punteggio > 0).
                $candidates = self::find_candidates( $post_id, $keywords );
                if ( count( $candidates ) < 1 ) {
                        return;
                }

                // Massimo di link: setting interlink_max (default 3); 0 = modulo muto.
                $max = absint( isset( $settings['interlink_max'] ) ? $settings['interlink_max'] : 3 );
                if ( $max < 1 ) {
                        return;
                }

                $selected = array_slice( $candidates, 0, $max );

                // Append del blocco (solo se il contenuto cambia) + meta tracciabilità.
                if ( self::append_block( $post_id, $selected ) ) {
                        update_post_meta( $post_id, self::META_FLAG, 1 );
                        update_post_meta(
                                $post_id,
                                self::META_IDS,
                                implode( ',', array_map( 'absint', wp_list_pluck( $selected, 'id' ) ) )
                        );
                }
        }

        /**
         * Finestra temporale dei candidati (giorni): option
         * nexus_interlink_window_days (default 180) + filtro dev omonimo,
         * clamp [1, 3650] — via helper condiviso Nexus_Dedup::limit_option.
         *
         * @return int
         */
        private static function window_days() {
                return Nexus_Dedup::limit_option( 'nexus_interlink_window_days', 'nexus_interlink_window_days', self::WINDOW_DAYS, 1, 3650 );
        }

        /**
         * Cap candidati interrogati per pubblicazione: option
         * nexus_interlink_candidates (default 20) + filtro dev omonimo,
         * clamp [1, 500] — via helper condiviso Nexus_Dedup::limit_option.
         *
         * @return int
         */
        private static function candidates_limit() {
                return Nexus_Dedup::limit_option( 'nexus_interlink_candidates', 'nexus_interlink_candidates', self::CANDIDATES_LIMIT, 1, 500 );
        }

        /**
         * Estrae le keyword dal titolo (parole > 4 caratteri, no stopword).
         *
         * Tokenizzazione su tutto ciò che NON è lettera (accentate incluse) o
         * cifra, con lowercase robusto UTF-8 (helper condiviso
         * Nexus_Dedup::utf8_lower). Lunghezza in CARATTERI, non byte
         * ("più" vale 3). Massimo KEYWORDS_MAX parole, in ordine di comparsa,
         * senza duplicati.
         *
         * @param string $title Titolo del post corrente.
         * @return array Lista keyword (lowercase), eventualmente vuota.
         */
        private static function extract_keywords( $title ) {
                $lower = Nexus_Dedup::utf8_lower( $title );
                if ( '' === $lower ) {
                        return array();
                }

                $words = preg_split( '/[^a-zàáèéìíòóùú0-9]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY );
                if ( ! is_array( $words ) ) {
                        return array();
                }

                $keywords = array();
                foreach ( $words as $word ) {
                        $length = function_exists( 'mb_strlen' ) ? mb_strlen( $word, 'UTF-8' ) : strlen( $word );
                        if ( $length <= 4 ) {
                                continue; // solo parole di 5+ caratteri.
                        }
                        if ( in_array( $word, self::STOPWORDS, true ) ) {
                                continue; // stopword italiana base.
                        }
                        if ( in_array( $word, $keywords, true ) ) {
                                continue; // niente duplicati se la parola ricorre nel titolo.
                        }
                        $keywords[] = $word;
                        if ( count( $keywords ) >= self::KEYWORDS_MAX ) {
                                break;
                        }
                }

                return $keywords;
        }

        /**
         * Trova e scoreggia i candidati sullo stesso post type del post corrente.
         *
         * Query: stato 'publish', escluso il post corrente, ultimi
         * window_days() giorni (option nexus_interlink_window_days), max
         * candidates_limit() con fields 'ids' (option
         * nexus_interlink_candidates). Il cache dei termini viene tenuto
         * attivo: wp_get_post_terms() nello scoring non aggiunge query.
         * Ritorna SOLO candidati con punteggio > 0, ordinati per punteggio
         * decrescente.
         *
         * @param int   $post_id  Post corrente.
         * @param array $keywords Keyword estratte dal titolo del post corrente.
         * @return array Lista di array( id, title, score ).
         */
        private static function find_candidates( $post_id, $keywords ) {
                $post_id = absint( $post_id );
                $post    = get_post( $post_id );
                if ( ! $post instanceof WP_Post || ! class_exists( 'WP_Query' ) ) {
                        return array();
                }

                // Data limite: wall-clock locale meno la finestra (stesso idiom
                // di class-nexus-db.php: gmdate su current_time('timestamp')).
                $since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( self::window_days() * DAY_IN_SECONDS ) );

                $query = new WP_Query(
                        array(
                                'post_type'              => $post->post_type,
                                'post_status'            => 'publish',
                                'post__not_in'           => array( $post_id ),
                                'posts_per_page'         => self::candidates_limit(),
                                'fields'                 => 'ids',
                                'no_found_rows'          => true,
                                'update_post_meta_cache' => false,
                                'update_post_term_cache' => true,
                                'date_query'             => array(
                                        array(
                                                'after'     => $since,
                                                'inclusive' => true,
                                        ),
                                ),
                        )
                );

                // Scoring: id + titolo per ogni candidato (fields 'ids' + get_the_title).
                $candidates = array();
                foreach ( (array) $query->posts as $candidate_id ) {
                        $candidate_id = absint( $candidate_id );
                        if ( $candidate_id === $post_id ) {
                                continue; // doppia guardia: mai il post corrente.
                        }
                        $title = (string) get_the_title( $candidate_id );
                        if ( '' === $title ) {
                                continue; // senza titolo non ci sono né keyword né link utile.
                        }
                        $score = self::score_candidate( $title, $keywords, $post_id, $candidate_id );
                        if ( $score < self::MIN_SCORE ) {
                                continue; // contratto: solo punteggio > 0.
                        }
                        $candidates[] = array(
                                'id'    => $candidate_id,
                                'title' => $title,
                                'score' => $score,
                        );
                }

                usort( $candidates, array( __CLASS__, 'sort_candidates' ) );

                return $candidates;
        }

        /**
         * Punteggio di un candidato: +2 per keyword nel titolo, +1 stessa categoria.
         *
         * Il bonus categoria è indipendente dal match keyword (lettura
         * letterale del contratto S4): un candidato senza keyword ma della
         * stessa categoria ha punteggio 1 e può entrare quando i match
         * keyword sono meno di interlink_max — comportamento "post correlati"
         * voluto per un sito di news.
         *
         * @param string $candidate_title Titolo del candidato.
         * @param array  $keywords       Keyword (lowercase) del post corrente.
         * @param int    $current_id     Post corrente.
         * @param int    $candidate_id   Candidato.
         * @return int
         */
        private static function score_candidate( $candidate_title, $keywords, $current_id, $candidate_id ) {
                $score    = 0;
                $haystack = Nexus_Dedup::utf8_lower( $candidate_title );

                // +KEYWORD_SCORE per ogni keyword presente nel titolo (case-insensitive).
                foreach ( $keywords as $keyword ) {
                        if ( self::contains( $haystack, $keyword ) ) {
                                $score += self::KEYWORD_SCORE;
                        }
                }

                // +CATEGORY_SCORE se stesso argomento (tassonomia nativa 'category').
                if ( self::shares_category( $current_id, $candidate_id ) ) {
                        $score += self::CATEGORY_SCORE;
                }

                return $score;
        }

        /**
         * TRUE se post corrente e candidato condividono un termine di 'category'.
         *
         * @param int $current_id   Post corrente.
         * @param int $candidate_id Candidato.
         * @return bool
         */
        private static function shares_category( $current_id, $candidate_id ) {
                // Tassonomia nativa 'category': quella che il publisher assegna
                // ai post Nexus (wp_set_post_categories). 'nexus_redazione' è
                // proprietà editoriale dell'agente, non argomento: esclusa.
                if ( ! function_exists( 'taxonomy_exists' ) || ! taxonomy_exists( 'category' ) ) {
                        return false;
                }

                $current_terms = wp_get_post_terms( absint( $current_id ), 'category', array( 'fields' => 'ids' ) );
                if ( empty( $current_terms ) || is_wp_error( $current_terms ) ) {
                        return false;
                }

                $candidate_terms = wp_get_post_terms( absint( $candidate_id ), 'category', array( 'fields' => 'ids' ) );
                if ( empty( $candidate_terms ) || is_wp_error( $candidate_terms ) ) {
                        return false;
                }

                $shared = array_intersect( array_map( 'absint', $current_terms ), array_map( 'absint', $candidate_terms ) );

                return count( $shared ) > 0;
        }

        /**
         * usort callback — punteggio decrescente, tie-break id crescente.
         *
         * Tie-break esplicito (usort NON è stabile in PHP 7.x) per un output
         * deterministico a parità di punteggio.
         *
         * @param array $a Primo candidato (id, title, score).
         * @param array $b Secondo candidato (id, title, score).
         * @return int
         */
        private static function sort_candidates( $a, $b ) {
                if ( (int) $a['score'] === (int) $b['score'] ) {
                        return ( (int) $a['id'] < (int) $b['id'] ) ? -1 : 1;
                }

                return ( (int) $a['score'] > (int) $b['score'] ) ? -1 : 1;
        }

        /**
         * Appende il blocco "Leggi anche" in coda al post_content.
         *
         * Il corpo esistente non viene mai toccato: solo concatenazione in
         * coda. wp_update_post gira SOLO se il contenuto cambia e il marker
         * non è già presente (cintura-e-bretelles oltre al meta flag).
         *
         * @param int   $post_id  Post da aggiornare.
         * @param array $selected Candidati selezionati (id, title, score).
         * @return bool TRUE se il post è a posto (aggiornato o blocco già presente).
         */
        private static function append_block( $post_id, $selected ) {
                $post_id = absint( $post_id );
                if ( $post_id <= 0 || count( $selected ) < 1 ) {
                        return false;
                }

                // Costruzione degli item con escape completo di URL e titoli.
                $items = '';
                foreach ( $selected as $candidate ) {
                        $url = get_permalink( absint( $candidate['id'] ) );
                        if ( ! is_string( $url ) || '' === $url ) {
                                continue; // permalink non risolvibile: item scartato.
                        }
                        $items .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( (string) $candidate['title'] ) . '</a></li>';
                }
                if ( '' === $items ) {
                        return false;
                }

                $block = '<div class="' . esc_attr( self::BLOCK_CLASS ) . '">'
                        . '<h4>' . esc_html( self::LABEL ) . '</h4>'
                        . '<ul>' . $items . '</ul>'
                        . '</div>';

                // kses SOLO sul blocco generato (consente div/h4/ul/li/a): il
                // contenuto preesistente non passa da nessuna sanitizzazione.
                $block = wp_kses_post( $block );

                $current = (string) get_post_field( 'post_content', $post_id );

                if ( false !== strpos( $current, self::BLOCK_CLASS ) ) {
                        // Blocco già presente (es. inserito a mano): niente append,
                        // il post si considera comunque processato.
                        return true;
                }

                // APPEND in coda: niente rtrim/rewrite del corpo esistente.
                $new_content = $current . "\n" . $block;
                if ( $new_content === $current ) {
                        return false;
                }

                $updated = wp_update_post(
                        array(
                                'ID'           => $post_id,
                                'post_content' => $new_content,
                        ),
                        true
                );

                return ! is_wp_error( $updated ) && absint( $updated ) > 0;
        }

        /**
         * TRUE se $needle compare in $haystack (case-insensitive).
         *
         * Le keyword sono già lowercase (Nexus_Dedup::utf8_lower); mb_stripos
         * gestisce le accentate UTF-8, stripos fa da fallback.
         *
         * @param string $haystack Titolo del candidato (lowercase).
         * @param string $needle   Keyword (lowercase).
         * @return bool
         */
        private static function contains( $haystack, $needle ) {
                $found = function_exists( 'mb_stripos' ) ? mb_stripos( $haystack, $needle ) : stripos( $haystack, $needle );

                return false !== $found;
        }
}

// v1.1.12 (S4): self-registrazione del modulo sull'action del bootstrap
// (nessun'altra modifica al bootstrap richiesta, vedi nexus-news-ai.php).
add_action( 'nexus_modules_init', array( 'Nexus_Interlink', 'init' ) );
