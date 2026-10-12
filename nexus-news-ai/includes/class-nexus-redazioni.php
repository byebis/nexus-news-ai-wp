<?php
/**
 * Nexus Redazioni — tassonomia WordPress gerarchica delle redazioni (v1.1.1).
 *
 * MODELLO ( richiesta di prodotto v1.1.1, contratto CONTRATTO-v1.1.1.md ):
 * ogni testata (sito WordPress) ha piu' redazioni contemporanee, e ogni
 * redazione puo' avere sub-redazioni annidate. Esempio di Anton:
 *
 *   Redazione Sport            (term madre)
 *     |- Redazione Calcio      (3 agenti assegnati)
 *     |- Redazione Basket      (2 agenti)
 *     |- Redazione F1          (1 agente)
 *
 * In WordPress questa struttura ESISTE GIA': e' una tassonomia gerarchica
 * (parent/child esattamente come le categorie), quindi non costruiamo
 * nulla di parallelo: usiamo wp_terms, wp_term_taxonomy, i meta dei
 * termini, gli archivi pubblici, la REST API nativa e la colonna
 * automatica nella lista Articoli di WordPress.
 *
 * Cosa fa questa classe (tutto nativo WP, ZERO tabelle nuove):
 *  1. TASSONOMIA "nexus_redazione" — gerarchica, pubblica, REST nativa
 *     (wp/v2/redazioni), colonna automatica nella lista articoli WP,
 *     archivi front-end /redazione/{slug}/ col tema attivo.
 *  2. TERM META "_nexus_agents" — array di agent ID assegnati al
 *     termine, a qualunque livello (redazione madre o sub-redazione).
 *  3. HELPERS — albero redazioni, agenti per ramo (subtree incluso),
 *     contatori per ramo, assegnazione articoli: quando l'agente X
 *     pubblica, il post viene tassonomizzato ai termini di X.
 *  4. FILTRO ARTICOLI — dropdown nativo "Tutte le redazioni" nella
 *     lista Articoli/CPT, con subtree incluso (come le categorie).
 *  5. FONTI DELLA REDAZIONE (v1.1.4) — term meta "_nexus_sources" con
 *     le fonti della redazione (feed RSS diretti o siti da cui scoprire
 *     i feed), opzionali: usate in raccolta (merge nei feed dell'agente
 *     tramite Nexus_Rss::fetch_for_agent) e dal capo redattore per
 *     verificare le notizie.
 *
 * EREDITARIETA' (regole chiave, tutta semantica WP):
 *  - ARCHIVI: l'archivio di una redazione madre mostra anche gli
 *    articoli delle sub-redazioni (include_children di WP per le
 *    tassonomie gerarchiche, identico alle categorie).
 *  - AGENTI: NON si ereditano in scrittura. Ogni assegnazione sta sul
 *    termine dove l'editore la mette; per il ramo completo si usa
 *    Nexus_Redazioni::agents_for_term() che somma termine + discendenti.
 *  - FONTI (v1.1.4): NON si ereditano (come gli agenti): valgono SOLO
 *    le fonti del termine assegnato all'agente
 *    (Nexus_Redazioni::sources_for_agent, senza discendenti).
 *  - MULTISITE: ogni sito del network ha le sue redazioni (le
 *    tassonomie sono per-sito): testata = sito, come da v1.0.5.
 *
 * @package Nexus_News_AI
 * @since   1.1.1
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Redazioni {

        const TAX          = 'nexus_redazione';
        const META_AGENTS  = '_nexus_agents';
        const META_SOURCES = '_nexus_sources';

        /**
         * Durata del transient di discovery feed per URL sito in secondi (1 ora).
         */
        const DISCOVER_TTL = 3600;

        /**
         * Bootstrapping: tassonomia + filtro articoli in lista admin.
         *
         * @return void
         */
        public static function init() {
                add_action( 'init', array( __CLASS__, 'register_taxonomy' ) );
                add_action( 'restrict_manage_posts', array( __CLASS__, 'posts_filter_dropdown' ), 10, 2 );
                add_filter( 'parse_query', array( __CLASS__, 'posts_filter_apply' ) );
        }

        /**
         * Registra la tassonomia gerarchica e i term meta degli agenti e
         * delle fonti (v1.1.4).
         *
         * Chiamata su init e all'attivazione (prima del flush delle rewrite).
         *
         * @return void
         */
        public static function register_taxonomy() {
                if ( ! function_exists( 'register_taxonomy' ) ) {
                        return;
                }

                if ( ! taxonomy_exists( self::TAX ) ) {
                        register_taxonomy(
                                self::TAX,
                                array( 'post', 'nexus_article' ),
                                array(
                                        'labels' => array(
                                                'name'              => __( 'Redazioni', 'nexus-news-ai' ),
                                                'singular_name'     => __( 'Redazione', 'nexus-news-ai' ),
                                                'search_items'      => __( 'Cerca redazioni', 'nexus-news-ai' ),
                                                'all_items'         => __( 'Tutte le redazioni', 'nexus-news-ai' ),
                                                'parent_item'       => __( 'Redazione madre', 'nexus-news-ai' ),
                                                'parent_item_colon' => __( 'Redazione madre:', 'nexus-news-ai' ),
                                                'edit_item'         => __( 'Modifica redazione', 'nexus-news-ai' ),
                                                'update_item'       => __( 'Aggiorna redazione', 'nexus-news-ai' ),
                                                'add_new_item'      => __( 'Aggiungi redazione', 'nexus-news-ai' ),
                                                'new_item_name'     => __( 'Nome nuova redazione', 'nexus-news-ai' ),
                                                'menu_name'         => __( 'Redazioni', 'nexus-news-ai' ),
                                                'not_found'         => __( 'Nessuna redazione trovata.', 'nexus-news-ai' ),
                                        ),
                                        /* Gerarchica: sub-redazioni native come le categorie. */
                                        'hierarchical'      => true,
                                        /* Pubblica: archivi /redazione/{slug}/ col tema attivo. */
                                        'public'            => true,
                                        'show_in_rest'      => true,
                                        'rest_base'         => 'redazioni',
                                        'rest_controller_class' => 'WP_REST_Terms_Controller',
                                        /* Colonna automatica nella lista Articoli di WordPress. */
                                        'show_admin_column' => true,
                                        'show_in_quick_edit' => true,
                                        'rewrite'           => array(
                                                'slug'         => 'redazione',
                                                'with_front'   => false,
                                                'hierarchical' => true,
                                        ),
                                )
                        );
                }

                if ( function_exists( 'register_term_meta' ) ) {
                        register_term_meta(
                                self::TAX,
                                self::META_AGENTS,
                                array(
                                        'type'              => 'array',
                                        'single'            => true,
                                        'show_in_rest'      => array(
                                                'schema' => array(
                                                        'type'  => 'array',
                                                        'items' => array( 'type' => 'integer' ),
                                                ),
                                        ),
                                        'sanitize_callback' => array( __CLASS__, 'sanitize_agent_ids' ),
                                        'description'       => __( 'Agenti giornalisti assegnati alla redazione.', 'nexus-news-ai' ),
                                )
                        );

                        // v1.1.4: fonti della redazione, array di oggetti {type, url}
                        // (type: 'rss' = feed diretto, 'site' = sito da cui scoprire il feed).
                        // show_in_rest: l'editor della pagina Redazioni legge e salva il meta
                        // con la REST nativa wp/v2/redazioni/{id}, senza endpoint nuovi.
                        register_term_meta(
                                self::TAX,
                                self::META_SOURCES,
                                array(
                                        'type'              => 'array',
                                        'single'            => true,
                                        'show_in_rest'      => array(
                                                'schema' => array(
                                                        'type'  => 'array',
                                                        'items' => array(
                                                                'type'       => 'object',
                                                                'properties' => array(
                                                                        'type' => array(
                                                                                'type' => 'string',
                                                                                'enum' => array( 'rss', 'site' ),
                                                                        ),
                                                                        'url'  => array(
                                                                                'type' => 'string',
                                                                        ),
                                                                ),
                                                        ),
                                                ),
                                        ),
                                        'sanitize_callback' => array( __CLASS__, 'sanitize_sources' ),
                                        'description'       => __( 'Fonti della redazione (feed RSS o siti da cui scoprire i feed).', 'nexus-news-ai' ),
                                )
                        );
                }
        }

        /**
         * Normalizza un array di agent ID (interi positivi unici).
         *
         * @param mixed $value Valore grezzo (array o scalare).
         * @return array
         */
        public static function sanitize_agent_ids( $value ) {
                $ids = is_array( $value ) ? $value : ( null === $value ? array() : array( $value ) );
                $ids = array_map( 'absint', $ids );
                $ids = array_filter( $ids, function ( $id ) {
                        return $id > 0;
                } );
                return array_values( array_unique( $ids ) );
        }

        /* ============ LETTURA: ALBERO, AGENTI, CONTATORI ============ */

        /**
         * Tutti i termini redazione (anche vuoti), ordinati per nome.
         *
         * @return array Array di WP_Term (vuoto se la tassonomia non esiste).
         */
        public static function all_terms() {
                if ( ! taxonomy_exists( self::TAX ) ) {
                        return array();
                }
                $terms = get_terms(
                        array(
                                'taxonomy'   => self::TAX,
                                'hide_empty' => false,
                                'orderby'    => 'name',
                                'order'      => 'ASC',
                        )
                );
                return ( is_wp_error( $terms ) || ! is_array( $terms ) ) ? array() : array_values( $terms );
        }

        /**
         * Agent ID assegnati direttamente a un termine (senza discendenti).
         *
         * @param int $term_id Term ID.
         * @return array
         */
        public static function agent_ids_for_term( $term_id ) {
                $term_id = absint( $term_id );
                if ( $term_id < 1 ) {
                        return array();
                }
                $value = get_term_meta( $term_id, self::META_AGENTS, true );
                return self::sanitize_agent_ids( $value );
        }

        /**
         * Tutti i termini a cui un agente e' assegnato (assegnazione diretta).
         *
         * @param int $agent_id Agent ID.
         * @return array Term ID (int).
         */
        public static function terms_for_agent( $agent_id ) {
                $agent_id = absint( $agent_id );
                if ( $agent_id < 1 ) {
                        return array();
                }
                $out = array();
                foreach ( self::all_terms() as $term ) {
                        if ( in_array( $agent_id, self::agent_ids_for_term( $term->term_id ), true ) ) {
                                $out[] = (int) $term->term_id;
                        }
                }
                return $out;
        }

        /**
         * Subtree completo di un termine: il termine stesso + tutti i
         * discendenti (a qualunque profondita').
         *
         * @param int $term_id Term ID radice.
         * @return array Term ID (int), radice inclusa.
         */
        public static function descendant_ids( $term_id ) {
                $term_id = absint( $term_id );
                if ( $term_id < 1 ) {
                        return array();
                }

                $children = array();
                foreach ( self::all_terms() as $term ) {
                        $parent = absint( $term->parent );
                        if ( ! isset( $children[ $parent ] ) ) {
                                $children[ $parent ] = array();
                        }
                        $children[ $parent ][] = (int) $term->term_id;
                }

                $stack = array( $term_id );
                $seen  = array();
                $out   = array();
                while ( ! empty( $stack ) ) {
                        $id = array_pop( $stack );
                        if ( isset( $seen[ $id ] ) ) {
                                continue;
                        }
                        $seen[ $id ] = true;
                        $out[]       = $id;
                        if ( isset( $children[ $id ] ) ) {
                                foreach ( $children[ $id ] as $child ) {
                                        $stack[] = $child;
                                }
                        }
                }
                return $out;
        }

        /**
         * Agenti che coprono un ramo: somma degli agenti assegnati al
         * termine e a tutti i suoi discendenti (Sport = Calcio + Basket + F1).
         *
         * @param int $term_id Term ID radice.
         * @return array Agent ID (int, unici).
         */
        public static function agents_for_term( $term_id ) {
                $ids = array();
                foreach ( self::descendant_ids( $term_id ) as $tid ) {
                        $ids = array_merge( $ids, self::agent_ids_for_term( $tid ) );
                }
                return array_values( array_unique( array_map( 'absint', $ids ) ) );
        }

        /**
         * Numero di articoli (post nativi + CPT legacy) nel subtree di un
         * termine, qualsiasi stato tranne cestino e auto-draft.
         *
         * @param int $term_id Term ID radice.
         * @return int
         */
        public static function count_subtree( $term_id ) {
                global $wpdb;

                $tt_ids = array();
                foreach ( self::descendant_ids( $term_id ) as $tid ) {
                        $term = get_term( $tid, self::TAX );
                        if ( $term instanceof WP_Term ) {
                                $tt_ids[] = (int) $term->term_taxonomy_id;
                        }
                }
                if ( empty( $tt_ids ) ) {
                        return 0;
                }

                $tt_in = implode( ',', array_map( 'absint', $tt_ids ) );
                $sql   = "SELECT COUNT( DISTINCT tr.object_id )"
                        . " FROM {$wpdb->term_relationships} AS tr"
                        . " INNER JOIN {$wpdb->posts} AS p ON p.ID = tr.object_id"
                        . " INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id"
                        . " WHERE tt.term_taxonomy_id IN ( {$tt_in} )"
                        . " AND p.post_type IN ( 'post', 'nexus_article' )"
                        . " AND p.post_status NOT IN ( 'trash', 'auto-draft' )";

                return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ID interi assolutizzati.
        }

        /* ============ FONTI DELLA REDAZIONE (v1.1.4) ============ */

        /**
         * Normalizza il term meta _nexus_sources in array di fonti valide.
         *
         * Accetta sia array di array/oggetti {type, url} sia righe di testo
         * "rss|https://..." oppure "site|https://..." (la forma usata dalla
         * textarea della pagina Redazioni). Tipo mancante o ignoto → 'rss'
         * (l'URL viene trattato come feed diretto). Item senza URL valido
         * vengono scartati; dedup per tipo e URL.
         *
         * @param mixed $value Valore grezzo (array, stringa o null).
         * @return array Array di array( 'type' => 'rss'|'site', 'url' => string ).
         */
        public static function sanitize_sources( $value ) {
                $rows = is_array( $value ) ? $value : ( null === $value ? array() : array( $value ) );
                $out  = array();
                $seen = array();

                foreach ( $rows as $row ) {
                        $type = 'rss';
                        $url  = '';

                        if ( is_array( $row ) ) {
                                $raw_type = isset( $row['type'] ) ? sanitize_key( (string) $row['type'] ) : '';
                                if ( 'site' === $raw_type ) {
                                        $type = 'site';
                                }
                                $url = isset( $row['url'] ) ? (string) $row['url'] : '';
                        } else {
                                // Riga testuale "type|url" (shape della textarea della UI).
                                $parts = explode( '|', (string) $row, 2 );
                                if ( 2 === count( $parts ) ) {
                                        $raw_type = sanitize_key( trim( $parts[0] ) );
                                        if ( 'site' === $raw_type ) {
                                                $type = 'site';
                                        }
                                        $url = trim( $parts[1] );
                                } else {
                                        $url = trim( (string) $row );
                                }
                        }

                        $url = esc_url_raw( trim( $url ) );
                        if ( '' === $url ) {
                                continue;
                        }

                        $key = $type . '|' . strtolower( $url );
                        if ( isset( $seen[ $key ] ) ) {
                                continue;
                        }
                        $seen[ $key ] = true;

                        $out[] = array(
                                'type' => $type,
                                'url'  => $url,
                        );
                }

                return $out;
        }

        /**
         * Fonti assegnate a un termine redazione (senza discendenti).
         *
         * @param int $term_id Term ID.
         * @return array Array di array( 'type' => 'rss'|'site', 'url' => string ).
         */
        public static function sources_for_term( $term_id ) {
                $term_id = absint( $term_id );
                if ( $term_id < 1 ) {
                        return array();
                }
                $value = get_term_meta( $term_id, self::META_SOURCES, true );
                return self::sanitize_sources( $value );
        }

        /**
         * Fonti della redazione assegnata a un agente (contratto pubblico
         * v1.1.4: usato da raccolta, capo redattore e UI).
         *
         * REGOLA DI EREDITARIETA' (deliberata, contratto v1.1.4): si leggono
         * SOLO le fonti del termine assegnato all'agente (colonna term_id di
         * nexus_agents, letta con Nexus_DB::get_agent in guard), NON quelle
         * dei termini discendenti: se un agente è su "Calcio", le fonti di
         * "Sport" (madre) NON vengono usate.
         *
         * @param int $agent_id Agent ID.
         * @return array Array di array( 'type' => 'rss'|'site', 'url' => string ).
         */
        public static function sources_for_agent( $agent_id ) {
                $agent_id = absint( $agent_id );
                if ( $agent_id < 1 || ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'get_agent' ) ) {
                        return array();
                }
                $agent = Nexus_DB::get_agent( $agent_id );
                if ( ! $agent || empty( $agent->term_id ) ) {
                        return array();
                }
                return self::sources_for_term( (int) $agent->term_id );
        }

        /**
         * Fonti di un agente convertite in ITEM FEED con lo STESSO shape di
         * Nexus_Rss::fetch_for_agent (title, description clip 400, link,
         * source): è l'array che collect e il capo redattore consumano.
         *
         * - type 'rss': l'URL della fonte è già un feed;
         * - type 'site': primo feed del sito scoperto con
         *   Nexus_Tools::discover_feeds (guard class_exists+method_exists),
         *   con transient di 1 ora per URL sito (DISCOVER_TTL).
         *
         * L'estrazione degli item è delegata a Nexus_Rss::fetch_feed_items
         * (guard class_exists+method_exists) così che lo shape resti identico
         * agli item dei feed di categoria anche in future modifiche.
         *
         * @param int $agent_id Agent ID.
         * @param int $max      Numero massimo di item restituiti.
         * @return array Array di item: {title, description, link, source}.
         */
        public static function sources_items_for_agent( $agent_id, $max = 5 ) {
                $max = (int) $max;
                if ( $max < 1 ) {
                        $max = 5;
                }

                $items = self::sources_for_agent( $agent_id );
                if ( empty( $items ) ) {
                        return array();
                }

                $out        = array();
                $seen_feeds = array();
                $seen_links = array();

                foreach ( $items as $item ) {
                        if ( count( $out ) >= $max ) {
                                break;
                        }
                        if ( ! is_array( $item ) || empty( $item['url'] ) ) {
                                continue;
                        }

                        $feed_urls = array();
                        if ( 'site' === $item['type'] ) {
                                $discovered = self::discover_feed_for_site( (string) $item['url'] );
                                if ( '' !== $discovered ) {
                                        $feed_urls[] = $discovered;
                                }
                        } else {
                                // 'rss': l'URL della fonte è un feed diretto.
                                $feed_urls[] = (string) $item['url'];
                        }

                        foreach ( $feed_urls as $feed_url ) {
                                if ( count( $out ) >= $max ) {
                                        break;
                                }
                                $feed_key = strtolower( $feed_url );
                                if ( '' === $feed_key || isset( $seen_feeds[ $feed_key ] ) ) {
                                        continue;
                                }
                                $seen_feeds[ $feed_key ] = true;

                                $fetched = array();
                                if ( class_exists( 'Nexus_Rss' ) && method_exists( 'Nexus_Rss', 'fetch_feed_items' ) ) {
                                        $fetched = Nexus_Rss::fetch_feed_items( $feed_url, $max - count( $out ) );
                                }
                                if ( ! is_array( $fetched ) ) {
                                        $fetched = array();
                                }

                                foreach ( $fetched as $feed_item ) {
                                        if ( count( $out ) >= $max ) {
                                                break;
                                        }
                                        if ( ! is_array( $feed_item ) || empty( $feed_item['link'] ) ) {
                                                continue;
                                        }
                                        $link_key = strtolower( (string) $feed_item['link'] );
                                        if ( isset( $seen_links[ $link_key ] ) ) {
                                                continue;
                                        }
                                        $seen_links[ $link_key ] = true;
                                        $out[]                   = $feed_item;
                                }
                        }
                }

                return $out;
        }

        /**
         * Primo feed trovato su un sito (discovery con cache 1 ora per URL).
         *
         * Il transient mantiene ANCHE il risultato vuoto: un sito senza feed
         * non viene risondato per un'ora (la discovery fa una GET completa
         * della pagina e non deve ripetersi ad ogni tick di raccolta).
         *
         * @param string $site_url URL del sito.
         * @return string URL del feed ('' se nessun feed o modulo strumenti assente).
         */
        private static function discover_feed_for_site( $site_url ) {
                $site_url = trim( (string) $site_url );
                if ( '' === $site_url ) {
                        return '';
                }

                $cache_key = 'nexus_disc_feed_' . md5( $site_url );
                $cached    = get_transient( $cache_key );
                if ( is_string( $cached ) ) {
                        return $cached;
                }

                $found = '';
                if ( class_exists( 'Nexus_Tools' ) && method_exists( 'Nexus_Tools', 'discover_feeds' ) ) {
                        $feeds = Nexus_Tools::discover_feeds( $site_url );
                        if ( is_array( $feeds ) ) {
                                foreach ( $feeds as $feed_url ) {
                                        if ( is_string( $feed_url ) && '' !== trim( $feed_url ) ) {
                                                // Primo feed trovato: basta, come da contratto v1.1.4.
                                                $found = esc_url_raw( trim( $feed_url ) );
                                                break;
                                        }
                                }
                        }
                }

                set_transient( $cache_key, $found, self::DISCOVER_TTL );

                return $found;
        }

        /* ============ SCRITTURA: ASSEGNAZIONI ============ */

        /**
         * Imposta gli agenti assegnati a un termine (sostituzione completa).
         *
         * @param int   $term_id   Term ID.
         * @param array $agent_ids Agent ID da assegnare (array vuoto = nessuno).
         * @return bool
         */
        public static function assign_agents( $term_id, $agent_ids ) {
                $term_id = absint( $term_id );
                if ( $term_id < 1 || ! taxonomy_exists( self::TAX ) ) {
                        return false;
                }
                if ( ! get_term( $term_id, self::TAX ) instanceof WP_Term ) {
                        return false;
                }
                return (bool) update_term_meta( $term_id, self::META_AGENTS, self::sanitize_agent_ids( $agent_ids ) );
        }

        /**
         * Assegna a un post tutti i termini redazione dell'agente.
         *
         * Chiamata dal publisher a pubblicazione avvenuta: se l'agente X e'
         * assegnato a Calcio (figlio di Sport), l'articolo finisce in Calcio
         * e per archivi/gerarchia e' coperto anche da Sport. L'assegnazione
         * e' nativa wp_set_object_terms, visibile in WP admin e REST.
         *
         * @param int $post_id  Post ID (nativo o CPT legacy).
         * @param int $agent_id Agent ID.
         * @return array Term ID assegnati (vuoto se nessuna redazione).
         */
        public static function assign_post_terms( $post_id, $agent_id ) {
                $post_id  = absint( $post_id );
                $agent_id = absint( $agent_id );
                if ( $post_id < 1 || $agent_id < 1 || ! taxonomy_exists( self::TAX ) ) {
                        return array();
                }
                $terms = self::terms_for_agent( $agent_id );
                if ( empty( $terms ) ) {
                        return array();
                }
                wp_set_object_terms( $post_id, array_map( 'intval', $terms ), self::TAX, false );
                return $terms;
        }

        /**
         * Crea una redazione (con eventuale madre e agenti).
         *
         * @param string $name      Nome.
         * @param int    $parent_id Term ID madre (0 = radice).
         * @param array  $agent_ids Agent ID assegnati.
         * @return array|WP_Error {term_id:int, agents:array} oppure errore.
         */
        public static function create( $name, $parent_id = 0, $agent_ids = array() ) {
                $name = sanitize_text_field( (string) $name );
                if ( '' === $name || ! taxonomy_exists( self::TAX ) || ! function_exists( 'wp_insert_term' ) ) {
                        return new WP_Error( 'nexus_redazioni', __( 'Nome redazione mancante o tassonomia non attiva.', 'nexus-news-ai' ) );
                }

                $parent_id = absint( $parent_id );
                $args      = array( 'slug' => sanitize_title( $name ) );
                if ( $parent_id > 0 ) {
                        $parent = get_term( $parent_id, self::TAX );
                        if ( ! $parent instanceof WP_Term ) {
                                return new WP_Error( 'nexus_redazioni', __( 'Redazione madre non trovata.', 'nexus-news-ai' ) );
                        }
                        $args['parent'] = $parent_id;
                }

                $created = wp_insert_term( $name, self::TAX, $args );
                if ( is_wp_error( $created ) ) {
                        // Riga duplicata: riprova con slug derivato (WP accetta il nome comunque).
                        if ( false !== strpos( (string) $created->get_error_code(), 'term_exists' ) ) {
                                $existing = get_term_by( 'name', $name, self::TAX );
                                if ( $existing instanceof WP_Term ) {
                                        self::assign_agents( (int) $existing->term_id, $agent_ids );
                                        return array( 'term_id' => (int) $existing->term_id, 'agents' => self::sanitize_agent_ids( $agent_ids ) );
                                }
                        }
                        return $created;
                }

                $term_id = isset( $created['term_id'] ) ? absint( $created['term_id'] ) : 0;
                if ( $term_id < 1 ) {
                        return new WP_Error( 'nexus_redazioni', __( 'Creazione redazione non riuscita.', 'nexus-news-ai' ) );
                }

                self::assign_agents( $term_id, $agent_ids );

                return array( 'term_id' => $term_id, 'agents' => self::sanitize_agent_ids( $agent_ids ) );
        }

        /* ============ FILTRO ARTICOLI IN LISTA ADMIN ============ */

        /**
         * Dropdown nativo "Tutte le redazioni" sopra la lista articoli.
         *
         * @param string $post_type Post type corrente.
         * @param string $which     Posizione (top|bottom).
         * @return void
         */
        public static function posts_filter_dropdown( $post_type, $which ) {
                if ( ! in_array( $post_type, array( 'post', 'nexus_article' ), true ) || 'top' !== $which ) {
                        return;
                }
                if ( ! taxonomy_exists( self::TAX ) || ! function_exists( 'wp_dropdown_categories' ) ) {
                        return;
                }

                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filtro di sola lettura, come i filtri WP core.
                $selected = isset( $_GET[ self::TAX ] ) ? sanitize_title( wp_unslash( $_GET[ self::TAX ] ) ) : '';

                wp_dropdown_categories(
                        array(
                                'show_option_all' => __( 'Tutte le redazioni', 'nexus-news-ai' ),
                                'taxonomy'        => self::TAX,
                                'name'            => self::TAX,
                                'orderby'         => 'name',
                                'selected'        => $selected,
                                'value_field'     => 'slug',
                                'hide_empty'      => false,
                                'hierarchical'    => true,
                                'show_count'      => false,
                                'walker'          => new Walker_Nexus_Redazioni_Dropdown(),
                        )
                );
        }

        /**
         * Applica il filtro redazione alla query della lista articoli,
         * includendo le sub-redazioni (identico al comportamento categorie).
         *
         * @param WP_Query $query Query admin corrente.
         * @return WP_Query
         */
        public static function posts_filter_apply( $query ) {
                if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
                        return $query;
                }

                $post_type = (array) $query->get( 'post_type' );
                if ( ! in_array( 'post', $post_type, true ) && ! in_array( 'nexus_article', $post_type, true ) ) {
                        return $query;
                }

                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filtro di sola lettura, come i filtri WP core.
                $slug = isset( $_GET[ self::TAX ] ) ? sanitize_title( wp_unslash( $_GET[ self::TAX ] ) ) : '';
                if ( '' === $slug ) {
                        return $query;
                }

                $term = get_term_by( 'slug', $slug, self::TAX );
                if ( ! $term instanceof WP_Term ) {
                        return $query;
                }

                $query->set(
                        'tax_query',
                        array(
                                array(
                                        'taxonomy'         => self::TAX,
                                        'field'            => 'term_id',
                                        'terms'            => $term->term_id,
                                        'include_children' => true,
                                ),
                        )
                );

                return $query;
        }
}

/**
 * Walker per il dropdown gerarchico: aggiunge trattini di profondita'
 * come fa il dropdown categorie di WP.
 *
 * @package Nexus_News_AI
 * @since   1.1.1
 */
class Walker_Nexus_Redazioni_Dropdown extends Walker_CategoryDropdown {

        /**
         * Avvio dell'elemento option con indentazione gerarchica.
         *
         * @param string   $output HTML accumulato.
         * @param WP_Term  $category Termine corrente.
         * @param int      $depth Profondita' (0 = radice).
         * @param array    $args Argomenti wp_dropdown_categories.
         * @param int      $id ID dell'elemento corrente.
         * @return string
         */
        public function start_el( &$output, $category, $depth = 0, $args = array(), $id = 0 ) {
                $pad     = str_repeat( '&nbsp;&nbsp;&nbsp;', max( 0, (int) $depth ) );
                $cat_name = apply_filters( 'list_cats', $category->name, $category );

                if ( isset( $args['value_field'] ) && 'slug' === $args['value_field'] ) {
                        $value = $category->slug;
                } else {
                        $value = $category->term_id;
                }

                $selected = ( isset( $args['selected'] ) && (string) $args['selected'] === (string) $value ) ? " selected='selected'" : '';

                $output .= "\t<option class=\"level-$depth\" value=\"" . esc_attr( $value ) . "\"{$selected}>";
                $output .= $pad . esc_html( $cat_name );
                $output .= "</option>\n";
        }
}
