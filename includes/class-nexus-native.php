<?php
/**
 * Nexus Native — integrazione profonda in WordPress (v1.1.0 "Tutto nativo").
 *
 * PRINCIPIO ( decisione di prodotto, contratto CONTRATTO-v1.1.0.md ):
 * tutto cio' che WordPress offre gia' lo usiamo nativamente; le tabelle Nexus
 * restano SOLO per l'operativo che WP non ha (coda, costi, log, iscritti).
 *
 * Cosa fa questa classe:
 *  1. UTENTI BOT — ogni agente giornalista diventa un utente WP reale con ruolo
 *     "nexus_ai_journalist" (senza login: nessuna capability di wp-admin). Gli
 *     articoli pubblicati hanno l'agente come post_author vero, con byline e
 *     pagina autore del tema.
 *  2. CATEGORIE NATIVE — ogni agente e' agganciato a una categoria WP reale
 *     (colonna term_id): le sue pubblicazioni finiscono nella categoria della
 *     testata, non piu' in una stringa privata del plugin.
 *  3. MIGRAZIONE 1-CLICK — converte i vecchi post CPT "nexus_article" in post
 *     nativi (post_type 'post'): autore bot, categoria nativa, meta collegamento
 *     conservato, stato WP invariato. Batched e riassumibile via REST.
 *  4. HELPER DUAL-MODE — durante la transizione le query di lettura (blocchi,
 *     shortcode, API pubblica, SEO, ping) usano Nexus_Native::query_post_types()
 *     cosi' vecchi CPT e nuovi post nativi convivono senza buchi.
 *  5. TAG NATIVI — derivazione deterministica (nessuna chiamata AI) di 3-5 tag
 *     dal titolo/estratto, per popolare wp_tags come farebbe un redattore.
 *
 * CONVENZIONI:
 *  - nessun fatal: ogni helpers regge l'assenza delle classi/ funzioni WP;
 *  - nessuna scrittura fuori da: utenti bot (solo ruolo dedicato), agenti
 *    (colonne wp_user_id/term_id), post in migrazione, opzione di progresso;
 *  - errori ONESTI: il motore riporta cosa non ha potuto fare, mai simulati.
 *
 * @package Nexus_News_AI
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Native {

        const ROLE            = 'nexus_ai_journalist';
        const MIGRATION_STATE = 'nexus_native_migration';

        /**
         * Hook: ruolo dedicato (nessuna capability di amministrazione).
         *
         * @return void
         */
        public static function init() {
                add_action( 'init', array( __CLASS__, 'register_role' ) );
        }

        /**
         * Crea il ruolo "Giornalista AI" se non esiste: zero capability, l'utente
         * bot non puo' entrare in wp-admin ne' fare nulla di privilegiato.
         *
         * @return void
         */
        public static function register_role() {
                if ( ! function_exists( 'add_role' ) ) {
                        return;
                }
                if ( function_exists( 'get_role' ) && get_role( self::ROLE ) ) {
                        return;
                }
                add_role( self::ROLE, __( 'Giornalista AI', 'nexus-news-ai' ), array( 'read' => true ) );
        }

        /* ============ DUAL-MODE (lettura durante la transizione) ============ */

        /**
         * Tipi di post da leggere per il contenuto generato: il nativo 'post'
         * PIU' il CPT legacy finche' esistono articoli non migrati.
         *
         * Filtrabile con 'nexus_native_post_types'.
         *
         * @return array
         */
        public static function query_post_types() {
                $types = array( 'post', 'nexus_article' );
                if ( has_filter( 'nexus_native_post_types' ) ) {
                        $types = apply_filters( 'nexus_native_post_types', $types );
                }
                return array_values( array_unique( array_map( 'sanitize_key', $types ) ) );
        }

        /* ============ UTENTI BOT ============ */

        /**
         * Risolve l'agente (riga tabella) da id o oggetto.
         *
         * @param object|int $agent Oggetto agente o id.
         * @return object|null
         */
        private static function resolve_agent( $agent ) {
                if ( is_object( $agent ) && isset( $agent->id ) ) {
                        return $agent;
                }
                if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'get_agent' ) ) {
                        return Nexus_DB::get_agent( absint( $agent ) );
                }
                return null;
        }

        /**
         * Garantisce l'utente bot WP dell'agente e ritorna il suo user ID.
         *
         * Riutilizza l'utente esistente (per wp_user_id o per login canonico
         * "nexus_agent_{id}"), altrimenti lo crea. Non crea MAI privilegi.
         *
         * @param object|int $agent Agente (riga o id).
         * @return int User ID (0 se non determinabile — chiamante degradare senza autore).
         */
        public static function ensure_bot_user( $agent ) {
                $agent = self::resolve_agent( $agent );
                if ( ! $agent || ! function_exists( 'wp_insert_user' ) ) {
                        return 0;
                }

                $agent_id = absint( $agent->id );

                // Gia' mappato e valido: esci presto.
                $mapped = isset( $agent->wp_user_id ) ? absint( $agent->wp_user_id ) : 0;
                if ( $mapped > 0 && get_userdata( $mapped ) ) {
                        return $mapped;
                }

                $login = 'nexus_agent_' . $agent_id;

                // Utente creato in un run precedente ma non registrato su tabella: riallinea.
                $existing = get_user_by( 'login', $login );
                if ( $existing instanceof WP_User ) {
                        if ( $existing->ID !== $mapped && class_exists( 'Nexus_DB' ) ) {
                                Nexus_DB::update_agent( $agent_id, array( 'wp_user_id' => $existing->ID ) );
                        }
                        return (int) $existing->ID;
                }

                $name     = isset( $agent->name ) ? sanitize_text_field( (string) $agent->name ) : '';
                $display  = '' !== $name ? $name : sprintf( 'Agente AI #%d', $agent_id );
                $host     = wp_parse_url( home_url(), PHP_URL_HOST );
                $email    = $login . '@' . ( $host ? $host : 'localhost' );
                $about    = trim( ( isset( $agent->description ) ? (string) $agent->description : '' ) . ' ' . ( isset( $agent->personality ) ? (string) $agent->personality : '' ) );

                $user_id = wp_insert_user(
                        array(
                                'user_login'   => $login,
                                'user_pass'    => wp_generate_password( 32, true, true ),
                                'user_email'   => $email,
                                'user_url'     => home_url( '/' ),
                                'display_name' => $display,
                                'nickname'     => $display,
                                'description'  => sanitize_textarea_field( $about ),
                                'role'         => self::ROLE,
                        )
                );

                if ( is_wp_error( $user_id ) || ! absint( $user_id ) ) {
                        return 0; // Errore onesto: il chiamante pubblica senza autore bot.
                }

                if ( class_exists( 'Nexus_DB' ) ) {
                        Nexus_DB::update_agent( $agent_id, array( 'wp_user_id' => absint( $user_id ) ) );
                }

                return absint( $user_id );
        }

        /* ============ CATEGORIE NATIVE ============ */

        /**
         * Garantisce la categoria WP nativa dell'agente e ritorna il term ID.
         *
         * Usa il term_id gia' mappato se valido, altrimenti cerca la categoria
         * per nome/slug della categoria agente e la crea se manca.
         *
         * @param object|int $agent Agente (riga o id).
         * @return int Term ID (0 se non determinabile).
         */
        public static function sync_agent_term( $agent ) {
                $agent = self::resolve_agent( $agent );
                if ( ! $agent || ! function_exists( 'wp_insert_term' ) ) {
                        return 0;
                }

                $agent_id = absint( $agent->id );

                // Gia' mappato e valido: esci presto.
                $mapped = isset( $agent->term_id ) ? absint( $agent->term_id ) : 0;
                if ( $mapped > 0 && get_term( $mapped, 'category' ) instanceof WP_Term ) {
                        return $mapped;
                }

                $name = isset( $agent->category ) ? sanitize_text_field( (string) $agent->category ) : '';
                if ( '' === $name ) {
                        return 0;
                }

                $term = get_term_by( 'name', $name, 'category' );
                if ( ! $term instanceof WP_Term ) {
                        $term = get_term_by( 'slug', sanitize_title( $name ), 'category' );
                }
                if ( ! $term instanceof WP_Term ) {
                        $created = wp_insert_term( $name, 'category', array( 'slug' => sanitize_title( $name ) ) );
                        if ( is_wp_error( $created ) ) {
                                $race = get_term_by( 'slug', sanitize_title( $name ), 'category' );
                                $term = ( $race instanceof WP_Term ) ? $race : null;
                        } else {
                                $term = get_term( isset( $created['term_id'] ) ? absint( $created['term_id'] ) : 0, 'category' );
                        }
                }

                if ( ! $term instanceof WP_Term ) {
                        return 0;
                }

                if ( $term->term_id !== $mapped && class_exists( 'Nexus_DB' ) ) {
                        Nexus_DB::update_agent( $agent_id, array( 'term_id' => (int) $term->term_id ) );
                }

                return (int) $term->term_id;
        }

        /**
         * Sincronizza TUTTI gli agenti (utente bot + categoria nativa).
         *
         * @return array {synced:int, users:int, terms:int, errors:array}
         */
        public static function ensure_agent_mappings() {
                $out = array( 'synced' => 0, 'users' => 0, 'terms' => 0, 'errors' => array() );
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'agents' ) ) {
                        $out['errors'][] = 'Nexus_DB non disponibile.';
                        return $out;
                }
                foreach ( Nexus_DB::agents() as $agent ) {
                        $out['synced']++;
                        if ( self::ensure_bot_user( $agent ) > 0 ) {
                                $out['users']++;
                        }
                        if ( self::sync_agent_term( $agent ) > 0 ) {
                                $out['terms']++;
                        }
                }
                return $out;
        }

        /* ============ AUTORE E TAG PER GLI ARTICOLI ============ */

        /**
         * User ID dell'autore bot per un articolo Nexus (crea l'utente se manca).
         *
         * @param object|array $article Articolo Nexus (serve agent_id).
         * @return int User ID (0 se agente assente o creazione fallita).
         */
        public static function article_author_id( $article ) {
                $article = is_array( $article ) ? (object) $article : $article;
                $agent_id = isset( $article->agent_id ) ? absint( $article->agent_id ) : 0;
                if ( $agent_id < 1 || ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'get_agent' ) ) {
                        return 0;
                }
                $agent = Nexus_DB::get_agent( $agent_id );
                if ( ! $agent ) {
                        return 0;
                }
                return self::ensure_bot_user( $agent );
        }

        /**
         * Categoria nativa di un articolo Nexus: prima quella dell'agente
         * (term_id), altrimenti quella derivata dal nome categoria articolo.
         *
         * @param object|array $article Articolo Nexus.
         * @return int Term ID (0 se non determinabile).
         */
        public static function article_term_id( $article ) {
                $article  = is_array( $article ) ? (object) $article : $article;
                $agent_id = isset( $article->agent_id ) ? absint( $article->agent_id ) : 0;
                if ( $agent_id > 0 && class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'get_agent' ) ) {
                        $agent = Nexus_DB::get_agent( $agent_id );
                        if ( $agent ) {
                                $term_id = self::sync_agent_term( $agent );
                                if ( $term_id > 0 ) {
                                        return $term_id;
                                }
                        }
                }
                // Fallback: categoria dell'articolo (stessa semantica del publisher v1.0.1).
                $name = isset( $article->category ) ? sanitize_text_field( (string) $article->category ) : '';
                if ( '' === $name || ! function_exists( 'wp_insert_term' ) ) {
                        return 0;
                }
                $term = get_term_by( 'name', $name, 'category' );
                if ( ! $term instanceof WP_Term ) {
                        $term = get_term_by( 'slug', sanitize_title( $name ), 'category' );
                }
                if ( $term instanceof WP_Term ) {
                        return (int) $term->term_id;
                }
                $created = wp_insert_term( $name, 'category', array( 'slug' => sanitize_title( $name ) ) );
                if ( is_wp_error( $created ) ) {
                        return 0;
                }
                return isset( $created['term_id'] ) ? absint( $created['term_id'] ) : 0;
        }

        /**
         * Tag deterministici per un articolo (nessuna chiamata AI): token dal
         * titolo+estratto, stopwords IT/EN filtrate, frequenza, top 5.
         *
         * @param object|array $article Articolo Nexus.
         * @return array Array di stringhe (0-5 elementi).
         */
        public static function article_tags( $article ) {
                $article = is_array( $article ) ? (object) $article : $article;
                $text    = trim( ( isset( $article->title ) ? (string) $article->title : '' ) . ' ' . ( isset( $article->summary ) ? (string) $article->summary : '' ) );
                if ( '' === $text ) {
                        return array();
                }

                $stop = 'il lo la i gli le un una uno di a da in con su per tra fra del della dei delle al allo alla ai agli alle dal dalla dallo nel nella nei nelle sul sulla e o ma se come che chi non anche sono essere stato stata stati state ha hanno avere aveva ed quest questa questo quell quello della degli dell dal dallo nell sulla dello the and for with from this that these those are was were been being his her their your will just about into over under after before between during without within';
                $stop_set = array_flip( preg_split( '/\s+/', $stop, -1, PREG_SPLIT_NO_EMPTY ) );

                if ( ! preg_match_all( '/[\p{L}]{4,}/u', wp_strip_all_tags( strtolower( $text ) ), $m ) || empty( $m[0] ) ) {
                        return array();
                }

                $freq = array();
                foreach ( $m[0] as $word ) {
                        if ( isset( $stop_set[ $word ] ) ) {
                                continue;
                        }
                        $freq[ $word ] = isset( $freq[ $word ] ) ? $freq[ $word ] + 1 : 1;
                }
                if ( empty( $freq ) ) {
                        return array();
                }
                arsort( $freq );
                $tags = array_slice( array_keys( $freq ), 0, 5 );
                return array_map( 'sanitize_text_field', $tags );
        }

        /* ============ MIGRAZIONE 1-CLICK ============ */

        /**
         * Conteggio dei post CPT ancora da migrare (esclusi cestino e auto-draft).
         *
         * @return int
         */
        public static function cpt_remaining() {
                global $wpdb;
                return (int) $wpdb->get_var(
                        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'nexus_article' AND post_status NOT IN ( 'trash', 'auto-draft' )"
                );
        }

        /**
         * Stato attuale della migrazione (per UI e REST).
         *
         * @return array {remaining:int, done:int, total:int, agents:array, finished:bool, errors:array}
         */
        public static function migrate_status() {
                $state = get_option( self::MIGRATION_STATE, array() );
                $state = is_array( $state ) ? $state : array();

                $remaining = self::cpt_remaining();
                $done      = isset( $state['done'] ) ? absint( $state['done'] ) : 0;
                $total     = isset( $state['total'] ) ? absint( $state['total'] ) : 0;
                if ( 0 === $total ) {
                        $total = $done + $remaining;
                }

                $agents = self::agent_mapping_gaps();

                return array(
                        'remaining' => $remaining,
                        'done'      => $done,
                        'total'     => $total,
                        'agents'    => $agents,
                        'finished'  => $remaining < 1,
                        'errors'    => isset( $state['errors'] ) && is_array( $state['errors'] ) ? array_slice( $state['errors'], -50 ) : array(),
                );
        }

        /**
         * Agenti privi di mappatura nativa (utente bot o categoria).
         *
         * @return array {without_user:int, without_term:int, total:int}
         */
        public static function agent_mapping_gaps() {
                $total = $without_user = $without_term = 0;
                if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'agents' ) ) {
                        foreach ( Nexus_DB::agents() as $agent ) {
                                $total++;
                                if ( empty( $agent->wp_user_id ) || ! get_userdata( absint( $agent->wp_user_id ) ) ) {
                                        $without_user++;
                                }
                                if ( empty( $agent->term_id ) || ! ( get_term( absint( $agent->term_id ), 'category' ) instanceof WP_Term ) ) {
                                        $without_term++;
                                }
                        }
                }
                return array( 'total' => $total, 'without_user' => $without_user, 'without_term' => $without_term );
        }

        /**
         * Esegue un batch di migrazione CPT → post nativi.
         *
         * Prima sincronizza TUTTI gli agenti (utenti bot + categorie), poi
         * converte fino a $limit post per chiamata: cambia post_type in 'post',
         * assegna autore bot e categoria nativa, conserva meta e stato WP.
         *
         * @param int $limit Numero massimo di post per chiamata (1-100).
         * @return array Stato aggiornato (come migrate_status()).
         */
        public static function migrate_run( $limit = 20 ) {
                global $wpdb;

                $limit = max( 1, min( 100, absint( $limit ) ) );
                $state = get_option( self::MIGRATION_STATE, array() );
                $state = is_array( $state ) ? $state : array();
                if ( ! isset( $state['done'] ) ) {
                        $state['done'] = 0;
                }
                if ( ! isset( $state['errors'] ) || ! is_array( $state['errors'] ) ) {
                        $state['errors'] = array();
                }
                if ( ! isset( $state['total'] ) ) {
                        $state['total'] = self::cpt_remaining() + absint( $state['done'] );
                }

                // 1) Agenti: mapping completo una volta sola per sessione.
                if ( empty( $state['agents_synced'] ) ) {
                        $state['agents_report']     = self::ensure_agent_mappings();
                        $state['agents_synced']     = 1;
                        update_option( self::MIGRATION_STATE, $state, false );
                }

                // 2) Batch di post CPT da convertire.
                $ids = $wpdb->get_col(
                        $wpdb->prepare(
                                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'nexus_article' AND post_status NOT IN ( 'trash', 'auto-draft' ) ORDER BY ID ASC LIMIT %d",
                                $limit
                        )
                );
                $ids = array_map( 'absint', (array) $ids );

                foreach ( $ids as $post_id ) {
                        $post = get_post( $post_id );
                        if ( ! $post instanceof WP_Post ) {
                                $state['errors'][] = sprintf( 'Post #%d non trovato, saltato.', $post_id );
                                $state['done']++;
                                continue;
                        }

                        // Articolo/agente di origine per autore e categoria.
                        $article_id = absint( get_post_meta( $post_id, '_nexus_article_id', true ) );
                        $article    = ( $article_id > 0 && class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'get_article' ) ) ? Nexus_DB::get_article( $article_id ) : null;

                        $author = $article ? self::article_author_id( $article ) : 0;
                        $term   = $article ? self::article_term_id( $article ) : 0;

                        $args = array(
                                'ID'        => $post_id,
                                'post_type' => 'post',
                        );
                        if ( $author > 0 ) {
                                $args['post_author'] = $author;
                        }
                        if ( $term > 0 ) {
                                $args['post_category'] = array( $term );
                        }

                        $result = wp_update_post( $args, true );
                        if ( is_wp_error( $result ) ) {
                                $state['errors'][] = sprintf( 'Post #%d: %s', $post_id, sanitize_text_field( $result->get_error_message() ) );
                                continue; // Riprovera' nel prossimo batch.
                        }

                        $state['done']++;
                }

                update_option( self::MIGRATION_STATE, $state, false );
                return self::migrate_status();
        }

        /**
         * Ripristina lo stato di migrazione (per rieseguire da zero la scansione).
         *
         * @return void
         */
        public static function migrate_reset() {
                delete_option( self::MIGRATION_STATE );
        }
}
