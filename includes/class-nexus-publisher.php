<?php
/**
 * Nexus Publisher — pubblicazione multi-piattaforma degli articoli.
 *
 * Canale 'blog' (v1.0.1 — "Contenuti Veri"):
 *  - enable_wp_posts = 0 (legacy) → l'articolo vive solo nel database Nexus e viene
 *    servito tramite home_url( '/?nexus_article={id}' ). Nessun post WordPress reale.
 *  - enable_wp_posts = 1 (WordPress reale) → wp_insert_post / wp_update_post sul CPT
 *    'nexus_article': titolo, contenuto (wp_kses_post), estratto (summary) e slug
 *    derivati dall'articolo Nexus; post_status dalle impostazioni ('publish'|'draft').
 *    Categoria WP nativa riusata o creata dal nome articolo, meta di collegamento
 *    _nexus_article_id e sincronizzazione di wp_post_id su wp_nexus_articles.
 *    Subito dopo la pubblicazione vengono invocati — senza MAI bloccarla —
 *    Nexus_Image::ensure_featured() e Nexus_SEO::optimize() (classi v1.0.1, se
 *    presenti: guard con class_exists + try/catch).
 *    Se wp_insert_post/wp_update_post fallisce: log 'failed' + secondo log legacy
 *    di fallback con l'URL front-end Nexus.
 *
 * Canali social → SIMULAZIONE ONESTA (filosofia dell'originale): il plugin non ha
 * credenziali API social, quindi genera un post_id e un URL fittizi marcati come
 * simulati invece di fingere chiamate reali.
 *
 * CONTRATTO PUBBLICO (invariato):
 * Nexus_Publisher::publish( $article ) → array di log:
 *   array( array( 'platform', 'status', 'post_id', 'post_url', 'error', 'note' ), ... )
 * Nessuna scrittura su publish_logs qui: gli insert_publish_log sono responsabilità
 * della pipeline.
 *
 * @package Nexus_News_AI
 * @since   1.0.1 Canale 'blog' con post WordPress reali (CPT nexus_article).
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Publisher {

        /**
         * Pubblica un articolo su tutte le piattaforme configurate.
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

                        // Canali social: pubblicazione simulata, dichiarata come tale (nessuna API configurata).
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

                // Stato del post ammesso: 'publish' | 'draft'.
                $post_status = isset( $settings['post_status'] ) ? sanitize_key( (string) $settings['post_status'] ) : 'publish';
                if ( ! in_array( $post_status, array( 'publish', 'draft' ), true ) ) {
                        $post_status = 'publish';
                }

                // Se l'articolo è già collegato a un post WP esistente si aggiorna, senza duplicare.
                $wp_post_id = isset( $article->wp_post_id ) ? absint( $article->wp_post_id ) : 0;

                $post_args = array(
                        'post_title'   => $title,
                        'post_content' => $content,
                        'post_excerpt' => $excerpt,
                        'post_status'  => $post_status,
                        'post_name'    => sanitize_title( $title ),
                );

                if ( $wp_post_id > 0 && get_post( $wp_post_id ) ) {
                        // Aggiornamento del post esistente (title/content/excerpt aggiornati).
                        $post_args['ID'] = $wp_post_id;
                        $result          = wp_update_post( $post_args, true );
                        $note            = 'Aggiornato post WordPress esistente';
                } else {
                        // Inserimento di un nuovo post sul CPT dedicato.
                        $post_args['post_type'] = 'nexus_article';
                        $result                 = wp_insert_post( $post_args, true );
                        $note                   = 'Post WordPress reale (CPT nexus_article)';
                }

                if ( is_wp_error( $result ) || 0 === absint( $result ) ) {
                        $error = is_wp_error( $result ) ? $result->get_error_message() : 'wp_insert_post/wp_update_post ha restituito 0.';
                        return self::failed_with_legacy_fallback( $article_id, $error );
                }

                $post_id = absint( $result );

                // Categoria WP nativa: termine riusato o creato, poi assegnato al post.
                $category_id = self::ensure_category( $article );
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
