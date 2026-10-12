<?php
/**
 * Nexus Publisher — pubblicazione multi-piattaforma degli articoli.
 *
 * Canale 'blog' (v1.0.1 — "Contenuti Veri"):
 *  - enable_wp_posts = 0 (legacy) → l'articolo vive solo nel database Nexus e viene
 *    servito tramite home_url( '/?nexus_article={id}' ). Nessun post WordPress reale.
 *  - enable_wp_posts = 1 (WordPress reale) → wp_insert_post / wp_update_post.
 *    v1.1.0 "Tutto nativo" — i post sono sul post type NATIVO 'post' (filtrabile
 *    con 'nexus_native_post_type'): post_author = utente bot dell'agente
 *    (Nexus_Native::ensure_bot_user), categoria nativa della testata
 *    (Nexus_Native::article_term_id, prima il term dell'agente), tag nativi
 *    deterministici (Nexus_Native::article_tags), titolo, contenuto (wp_kses_post),
 *    estratto (summary) e slug derivati dall'articolo Nexus; post_status dalle
 *    impostazioni ('publish'|'draft'). Meta di collegamento _nexus_article_id e
 *    sincronizzazione di wp_post_id su wp_nexus_articles.
 *    Subito dopo la pubblicazione vengono invocati — senza MAI bloccarla —
 *    Nexus_Image::ensure_featured() e Nexus_SEO::optimize() (classi v1.0.1, se
 *    presenti: guard con class_exists + try/catch).
 *    Se wp_insert_post/wp_update_post fallisce: log 'failed' + secondo log legacy
 *    di fallback con l'URL front-end Nexus.
 *    v1.0.3 — se l'articolo ha citazioni dal fact-check (colonna citations, JSON),
 *    un blocco HTML "Fonti verificate" (max 8 voci, escape completo) viene incluso
 *    nel contenuto iniziale del post, PRIMA di wp_insert_post/wp_update_post.
 *
 * Canali social REALI (v1.0.2 — "Distribuzione Reale"): telegram, discord, x
 * (con alias twitter) e linkedin vengono delegati, quando le relative classi
 * sono presenti, a Nexus_Social_Telegram, Nexus_Social_Discord, Nexus_Social_X
 * e Nexus_Social_LinkedIn secondo il contratto condiviso (CONTRATTO-v1.0.2.md):
 * ogni classe social dedicata espone publish( $article, $settings ) che ritorna
 * array( 'status' ('published'|'failed'|'skipped'), 'post_id', 'post_url',
 * 'error', 'note' ). Ogni esito reale riuscito/fallito registra un'attività
 * Nexus (best-effort).
 *
 * Canali social → SIMULAZIONE ONESTA (fallback, filosofia dell'originale):
 * facebook, instagram, ogni platform non mappato e i 4 canali reali quando la
 * relativa classe NON esiste (installazione parziale/vecchia) generano un
 * post_id e un URL fittizi marcati come simulati invece di fingere chiamate reali.
 *
 * CONTRATTO PUBBLICO (invariato):
 * Nexus_Publisher::publish( $article ) → array di log:
 *   array( array( 'platform', 'status', 'post_id', 'post_url', 'error', 'note' ), ... )
 * Nessuna scrittura su publish_logs qui: gli insert_publish_log sono responsabilità
 * della pipeline.
 *
 * @package Nexus_News_AI
 * @since   1.0.1 Canale 'blog' con post WordPress reali (CPT nexus_article).
 * @since   1.0.2 Canali social reali (Telegram, Discord, X, LinkedIn) con fallback simulato.
 * @since   1.0.3 Blocco "Fonti verificate" (nexus-citations) dal fact-check nel post WP reale.
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Publisher {

        /**
         * Pubblica un articolo su tutte le piattaforme configurate.
         *
         * Canali social reali (v1.0.2): telegram, discord, x (alias twitter) e
         * linkedin vengono delegati alle classi social dedicate se presenti;
         * altrimenti — come facebook, instagram e ogni platform non mappato —
         * resta la simulazione onesta. Un log per platform in entrambi i casi.
         *
         * @param object|array $article Articolo Nexus (riga di nexus_articles).
         * @return array Log di pubblicazione (uno o più per piattaforma).
         */
        public static function publish( $article ) {
                if ( is_array( $article ) ) {
                        $article = (object) $article;
                }
                if ( ! is_object( $article ) || empty( $article->id ) ) {
                        return array();
                }

                $article_id = absint( $article->id );

                $settings  = Nexus_DB::get_settings();
                $raw       = isset( $settings['social_platforms'] ) ? (string) $settings['social_platforms'] : 'blog';
                $platforms = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
                if ( empty( $platforms ) ) {
                        $platforms = array( 'blog' );
                }

                // 1 = pubblica post WordPress reali (CPT nexus_article), 0 = comportamento legacy.
                $enable_wp_posts = ! empty( $settings['enable_wp_posts'] ) ? absint( $settings['enable_wp_posts'] ) : 0;

                // Mappa platform → classe social reale (v1.0.2, CONTRATTO-v1.0.2.md).
                // 'twitter' è alias storico di 'x'; facebook/instagram restano simulati.
                $social_map = array(
                        'telegram' => 'Nexus_Social_Telegram',
                        'discord'  => 'Nexus_Social_Discord',
                        'x'        => 'Nexus_Social_X',
                        'twitter'  => 'Nexus_Social_X',
                        'linkedin' => 'Nexus_Social_LinkedIn',
                );

                $logs = array();
                foreach ( $platforms as $platform ) {
                        $platform = sanitize_key( $platform );
                        if ( '' === $platform ) {
                                continue;
                        }

                        if ( 'blog' === $platform ) {
                                if ( $enable_wp_posts ) {
                                        // Canale reale v1.0.1: post WordPress sul CPT nexus_article.
                                        foreach ( self::publish_wp_post( $article, $article_id, $settings ) as $log ) {
                                                $logs[] = $log;
                                        }
                                } else {
                                        // Modalità legacy: l'articolo è già nel database Nexus, esposto via front-end.
                                        $logs[] = self::legacy_blog_log( $article_id );
                                }
                                continue;
                        }

                        // Canale social reale (v1.0.2): delega alla classe dedicata se presente.
                        if ( isset( $social_map[ $platform ] )
                                && class_exists( $social_map[ $platform ] )
                                && method_exists( $social_map[ $platform ], 'publish' ) ) {
                                $logs[] = self::publish_social_real( $platform, $social_map[ $platform ], $article, $settings );
                                continue;
                        }

                        // Fallback simulazione: platform non mappato (facebook, instagram, ...)
                        // oppure classe social assente (installazione parziale). Pubblicazione
                        // simulata, dichiarata come tale (nessuna API configurata).
                        $sim_id = 'sim_' . wp_generate_password( 8, false );
                        $logs[] = array(
                                'platform' => $platform,
                                'status'   => 'published',
                                'post_id'  => $sim_id,
                                'post_url' => 'https://social.example/' . $platform . '/' . $sim_id,
                                'error'    => '',
                                'note'     => 'Pubblicazione simulata (demo): nessuna API social configurata.',
                        );
                }

                return $logs;
        }

        /**
         * Pubblica su un canale social REALE tramite la classe dedicata (v1.0.2).
         *
         * Contratto classe social (CONTRATTO-v1.0.2.md): ogni classe espone
         * publish( $article, $settings ) → array( 'status' ('published'|'failed'|'skipped'),
         * 'post_id', 'post_url', 'error', 'note' ). Il risultato viene tradotto in UN
         * log per platform; qualunque eccezione diventa log 'failed' (mai fatale).
         *
         * @param string $platform Platform corrente (telegram|discord|x|twitter|linkedin).
         * @param string $cls      Nome della classe social reale.
         * @param object $article  Articolo Nexus.
         * @param array  $settings Impostazioni plugin (Nexus_DB::get_settings()).
         * @return array Log di pubblicazione (uno per platform).
         */
        private static function publish_social_real( $platform, $cls, $article, $settings ) {
                try {
                        $res = call_user_func( array( $cls, 'publish' ), $article, $settings );
                } catch ( \Throwable $e ) { // Nessuna eccezione propagate: log 'failed'.
                        $res = array(
                                'status'   => 'failed',
                                'post_id'  => '',
                                'post_url' => '',
                                'error'    => sanitize_text_field( $cls . ': ' . $e->getMessage() ),
                                'note'     => '',
                        );
                }

                $res = is_array( $res ) ? $res : array();

                // Normalizzazione difensiva secondo il contratto delle classi social.
                $status = isset( $res['status'] ) ? sanitize_key( (string) $res['status'] ) : 'failed';
                if ( ! in_array( $status, array( 'published', 'failed', 'skipped' ), true ) ) {
                        $status = 'failed';
                }
                $post_id  = isset( $res['post_id'] ) ? sanitize_text_field( (string) $res['post_id'] ) : '';
                $post_url = isset( $res['post_url'] ) ? esc_url_raw( (string) $res['post_url'] ) : '';
                $error    = isset( $res['error'] ) ? sanitize_text_field( (string) $res['error'] ) : '';
                $note     = isset( $res['note'] ) ? sanitize_text_field( (string) $res['note'] ) : '';

                // Attività Nexus (best-effort): solo per esiti reali riusciti/falliti.
                if ( 'skipped' !== $status ) {
                        self::log_social_activity( $platform, 'published' === $status ? 'success' : 'error', 'published' === $status ? $note : $error );
                }

                return array(
                        'platform' => $platform,
                        'status'   => $status,
                        'post_id'  => $post_id,
                        'post_url' => $post_url,
                        'error'    => $error,
                        'note'     => $note,
                );
        }

        /**
         * Registra l'attività del canale social (best-effort: mai bloccante).
         *
         * @param string $platform Platform corrente.
         * @param string $state    Stato attività ('success'|'error').
         * @param string $detail   Nota (successo) o errore (fallimento) da registrare.
         * @return void
         */
        private static function log_social_activity( $platform, $state, $detail ) {
                if ( ! method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                        return;
                }

                $detail = trim( (string) $detail );
                if ( '' === $detail ) {
                        $detail = 'success' === $state ? 'Pubblicazione completata.' : 'Pubblicazione fallita senza dettagli.';
                }

                try {
                        Nexus_DB::insert_activity( 0, 'social', sprintf( 'Canale %s: %s', $platform, $detail ), $state );
                } catch ( \Throwable $e ) { // Skip silenzioso: errore non bloccante.
                }
        }

        /**
         * Log legacy del canale blog: l'articolo resta servito dal front-end Nexus.
         *
         * @param int $article_id ID dell'articolo Nexus.
         * @return array Log di pubblicazione legacy.
         */
        private static function legacy_blog_log( $article_id ) {
                return array(
                        'platform' => 'blog',
                        'status'   => 'published',
                        'post_id'  => 'nexus-' . $article_id,
                        'post_url' => home_url( '/?nexus_article=' . $article_id ),
                        'error'    => '',
                );
        }

        /**
         * Pubblica (o aggiorna) l'articolo come post WordPress reale sul CPT nexus_article.
         *
         * Sequenza (contratto v1.0.1 §2/§5): insert/update → categoria → meta di
         * collegamento → update_article( wp_post_id ) → Nexus_Image::ensure_featured()
         * → Nexus_SEO::optimize() → log. Le chiamate a image/SEO non bloccano mai.
         * v1.0.3: il contenuto iniziale include il blocco citazioni del fact-check
         * ("Fonti verificate", max 8) quando l'articolo ha citations non vuote.
         *
         * @param object $article    Articolo Nexus.
         * @param int    $article_id ID dell'articolo Nexus.
         * @param array  $settings   Impostazioni plugin (Nexus_DB::get_settings()).
         * @return array Un log di successo, oppure due log ('failed' + fallback legacy).
         */
        private static function publish_wp_post( $article, $article_id, $settings ) {
                // Ambiente WordPress non completo (caso limite): resta il comportamento legacy.
                if ( ! function_exists( 'wp_insert_post' ) ) {
                        return array( self::legacy_blog_log( $article_id ) );
                }

                $title = isset( $article->title ) ? sanitize_text_field( (string) $article->title ) : '';
                if ( '' === $title ) {
                        $title = sprintf( 'Articolo Nexus #%d', $article_id );
                }
                $content = isset( $article->content ) ? wp_kses_post( (string) $article->content ) : '';
                $excerpt = isset( $article->summary ) ? wp_strip_all_tags( (string) $article->summary ) : '';

                // v1.0.3 — Citazioni del fact-check: blocco "Fonti verificate" (max 8, escape
                // completo) incluso nel contenuto iniziale, PRIMA di wp_insert_post/
                // wp_update_post. Se il contenuto contiene già il blocco (aggiornamento del
                // post) non viene duplicato. Nessun effetto sul resto del publisher v1.0.2.
                $citations = self::citations_block( $article );
                if ( '' !== $citations && false === strpos( $content, 'nexus-citations' ) ) {
                        $content .= $citations;
                }

                // Stato del post ammesso: 'publish' | 'draft' | 'pending' (v1.1.0: revisione WP nativa).
                $post_status = isset( $settings['post_status'] ) ? sanitize_key( (string) $settings['post_status'] ) : 'publish';
                if ( ! in_array( $post_status, array( 'publish', 'draft', 'pending' ), true ) ) {
                        $post_status = 'publish';
                }

                // Se l'articolo è già collegato a un post WP esistente si aggiorna, senza duplicare.
                $wp_post_id = isset( $article->wp_post_id ) ? absint( $article->wp_post_id ) : 0;

                // v1.1.0 "Tutto nativo": autore bot dell'agente, categoria nativa e tag.
                $post_args = array(
                        'post_title'   => $title,
                        'post_content' => $content,
                        'post_excerpt' => $excerpt,
                        'post_status'  => $post_status,
                        'post_name'    => sanitize_title( $title ),
                );
                if ( class_exists( 'Nexus_Native' ) && method_exists( 'Nexus_Native', 'article_author_id' ) ) {
                        $nxa_author = absint( Nexus_Native::article_author_id( $article ) );
                        if ( $nxa_author > 0 ) {
                                $post_args['post_author'] = $nxa_author;
                        }
                }
                if ( class_exists( 'Nexus_Native' ) && method_exists( 'Nexus_Native', 'article_tags' ) ) {
                        $nxa_tags = Nexus_Native::article_tags( $article );
                        if ( ! empty( $nxa_tags ) ) {
                                $post_args['tags_input'] = $nxa_tags;
                        }
                }

                if ( $wp_post_id > 0 && get_post( $wp_post_id ) ) {
                        // Aggiornamento del post esistente (title/content/excerpt aggiornati).
                        $post_args['ID'] = $wp_post_id;
                        $result          = wp_update_post( $post_args, true );
                        $note            = 'Aggiornato post WordPress esistente';
                } else {
                        // v1.1.0: inserimento come POST NATIVO (filtrabile con 'nexus_native_post_type').
                        $post_args['post_type'] = apply_filters( 'nexus_native_post_type', 'post' );
                        $result                 = wp_insert_post( $post_args, true );
                        $note                   = 'Post WordPress nativo';
                }

                if ( is_wp_error( $result ) || 0 === absint( $result ) ) {
                        $error = is_wp_error( $result ) ? $result->get_error_message() : 'wp_insert_post/wp_update_post ha restituito 0.';
                        return self::failed_with_legacy_fallback( $article_id, $error );
                }

                $post_id = absint( $result );

                // Categoria WP nativa (v1.1.0): prima il term dell'agente, fallback dal nome articolo.
                if ( class_exists( 'Nexus_Native' ) && method_exists( 'Nexus_Native', 'article_term_id' ) ) {
                        $category_id = absint( Nexus_Native::article_term_id( $article ) );
                } else {
                        $category_id = self::ensure_category( $article );
                }
                if ( $category_id > 0 ) {
                        wp_set_post_categories( $post_id, array( $category_id ) );
                }

                // Meta di collegamento post WP ↔ articolo Nexus.
                update_post_meta( $post_id, '_nexus_article_id', $article_id );

                // Persisti il riferimento nel database Nexus SUBITO dopo insert/update riuscito.
                Nexus_DB::update_article( $article_id, array( 'wp_post_id' => $post_id ) );

                // Immagine in evidenza (v1.0.1): la classe può non esistere ancora; mai bloccare.
                if ( class_exists( 'Nexus_Image' ) && method_exists( 'Nexus_Image', 'ensure_featured' ) ) {
                        try {
                                Nexus_Image::ensure_featured( $article, $post_id );
                        } catch ( \Throwable $e ) { // Ignora: l'immagine non deve mai interrompere la pubblicazione.
                        }
                }

                // Ottimizzazione SEO (v1.0.1): stessa disciplina, nessun blocco in caso di errore.
                if ( class_exists( 'Nexus_SEO' ) && method_exists( 'Nexus_SEO', 'optimize' ) ) {
                        try {
                                Nexus_SEO::optimize( $article, $post_id );
                        } catch ( \Throwable $e ) { // Ignora: la SEO non deve mai interrompere la pubblicazione.
                        }
                }

                // Attività (best-effort, contratto §5): insert_activity() se esiste, altrimenti skip.
                if ( method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                        try {
                                Nexus_DB::insert_activity( 0, 'publish_wp', sprintf( 'Post WP #%d per articolo Nexus #%d', $post_id, $article_id ), 'success' );
                        } catch ( \Throwable $e ) { // Skip silenzioso: errore non bloccante.
                        }
                }

                $permalink = get_permalink( $post_id );

                return array(
                        array(
                                'platform' => 'blog',
                                'status'   => 'published',
                                'post_id'  => (string) $post_id,
                                'post_url' => $permalink ? esc_url_raw( $permalink ) : '',
                                'error'    => '',
                                'note'     => $note,
                        ),
                );
        }

        /**
         * Costruisce il blocco HTML "Fonti verificate" dalle citazioni del fact-check (v1.0.3).
         *
         * La colonna citations dell'articolo è una stringa JSON (generata dalla pipeline:
         * array di oggetti {claim, source}). Decode difensivo: se il JSON è vuoto, non
         * valido o l'array risultante è vuoto si ritorna una stringa vuota (nessun blocco).
         * Massimo 8 citazioni; claim in esc_html, source in esc_url con rel noopener
         * nofollow e target _blank.
         *
         * @param object $article Articolo Nexus (eventuale colonna citations).
         * @return string Markup HTML del blocco (stringa vuota se nessuna citazione valida).
         */
        private static function citations_block( $article ) {
                $raw = isset( $article->citations ) ? trim( (string) $article->citations ) : '';
                if ( '' === $raw ) {
                        return '';
                }

                $citations = json_decode( $raw, true );
                if ( ! is_array( $citations ) || empty( $citations ) ) {
                        return '';
                }

                $items = '';
                $count = 0;
                foreach ( $citations as $citation ) {
                        if ( $count >= 8 ) {
                                break; // Massimo 8 citazioni nel post.
                        }

                        $c      = is_array( $citation ) ? $citation : (array) $citation;
                        $claim  = isset( $c['claim'] ) ? trim( wp_strip_all_tags( (string) $c['claim'] ) ) : '';
                        $source = isset( $c['source'] ) ? trim( (string) $c['source'] ) : '';
                        if ( '' === $claim || '' === $source ) {
                                continue; // Citazione incompleta: scartata.
                        }

                        $url = esc_url_raw( $source );
                        if ( '' === $url ) {
                                continue; // URL non valido: scartata.
                        }

                        $items .= '<li>' . esc_html( $claim )
                                . ' — <a href="' . esc_url( $url ) . '" rel="noopener nofollow" target="_blank">fonte</a></li>';
                        $count++;
                }

                if ( '' === $items ) {
                        return '';
                }

                return '<div class="nexus-citations"><h4>Fonti verificate</h4><ul>' . $items . '</ul></div>';
        }

        /**
         * Trova o crea la categoria WP nativa con il nome dell'articolo Nexus.
         *
         * @param object $article Articolo Nexus.
         * @return int Term ID della categoria, 0 se non determinabile.
         */
        private static function ensure_category( $article ) {
                $name = isset( $article->category ) ? sanitize_text_field( (string) $article->category ) : '';
                if ( '' === $name ) {
                        return 0;
                }

                $slug = sanitize_title( $name );

                // Cerca per nome, poi per slug (nome con maiuscole/accenti differenti).
                $term = get_term_by( 'name', $name, 'category' );
                if ( ! $term instanceof WP_Term ) {
                        $term = get_term_by( 'slug', $slug, 'category' );
                }
                if ( $term instanceof WP_Term ) {
                        return (int) $term->term_id;
                }

                // Non esiste: creala.
                $created = wp_insert_term( $name, 'category', array( 'slug' => $slug ) );
                if ( is_wp_error( $created ) ) {
                        // Possibile race condition: riprova con il solo slug.
                        $existing = get_term_by( 'slug', $slug, 'category' );
                        return ( $existing instanceof WP_Term ) ? (int) $existing->term_id : 0;
                }

                return isset( $created['term_id'] ) ? (int) $created['term_id'] : 0;
        }

        /**
         * Log di fallimento dell'insert/update WP + fallback legacy sul front-end Nexus.
         *
         * @param int    $article_id ID dell'articolo Nexus.
         * @param string $error      Messaggio d'errore.
         * @return array Due log: 'failed' (WordPress) + 'published' (fallback legacy).
         */
        private static function failed_with_legacy_fallback( $article_id, $error ) {
                return array(
                        array(
                                'platform' => 'blog',
                                'status'   => 'failed',
                                'post_id'  => '',
                                'post_url' => '',
                                'error'    => sanitize_text_field( (string) $error ),
                        ),
                        array(
                                'platform' => 'blog',
                                'status'   => 'published',
                                'post_id'  => 'nexus-' . $article_id,
                                'post_url' => home_url( '/?nexus_article=' . $article_id ),
                                'error'    => '',
                                'note'     => 'Fallback front-end Nexus: inserimento post WP fallito.',
                        ),
                );
        }
}
