<?php
/**
 * Nexus Public API — REST pubblica in sola lettura (namespace nexus-public/v1).
 *
 * Espone verso l'esterno (app, front-end di terzi, integrazioni) gli articoli
 * PUBBLICATI, gli agenti ATTIVI e le statistiche pubbliche. Autenticazione
 * tramite API key:
 *   - header HTTP:  X-Api-Key: <chiave>
 *   - in alternativa query/body: ?api_key=<chiave>
 * La chiave si genera dalla pagina admin "Strumenti" → "API pubblica"
 * (rotta admin POST /tools/api-key, v1.0.5) e l'interfaccia si attiva da
 * Impostazioni → "Lingua e API pubblica" (setting enable_public_api = 1).
 * Tutte le rotte usano come permission_callback Nexus_Public_API::auth(),
 * che valida la chiave, impone il rate limit e imposta l'header CORS.
 *
 * ENDPOINT (tutti GET, tutti dietro auth() con API key):
 *
 *   GET /wp-json/nexus-public/v1/articles
 *       Lista articoli con status SEMPRE 'published' (mai bozze/rifiutati).
 *       Args: page (int, default 1), per_page (int, default 12, max 50),
 *             category (string, opzionale, filtro esatto).
 *       Risposta: {"items": [articolo], "total": int, "page": int, "pages": int}
 *       Articolo serializzato: id, title, subtitle, summary, content,
 *         category, agent_name, image_url, url, published_at, read_time,
 *         views, quality_score, factcheck_score.
 *
 *   GET /wp-json/nexus-public/v1/articles/{id}
 *       Singolo articolo, ritornato SOLO se status = 'published',
 *       altrimenti 404 "Articolo non trovato.".
 *
 *   GET /wp-json/nexus-public/v1/agents
 *       Agenti con status 'active': id, name, avatar, category, description,
 *       articles_count (totale articoli dell'agente, senza filtro stato).
 *
 *   GET /wp-json/nexus-public/v1/stats
 *       Contatori pubblici da Nexus_DB::stats(): total_articles, published,
 *       agents_active, views.
 *
 * ERRORI (WP_Error con array('status' => codice HTTP)):
 *   501 nexus_not_available         "Nexus non disponibile." (classe DB assente)
 *   503 nexus_api_disabled          "API pubblica disattivata." (enable_public_api = 0)
 *   401 nexus_api_key_missing       "Chiave API mancante."
 *   503 nexus_api_key_not_configured "Chiave API non configurata: generala dalla pagina Strumenti di Nexus."
 *   401 nexus_api_key_invalid       "Chiave API non valida."
 *   404 nexus_public_article_not_found "Articolo non trovato."
 *   429 nexus_rate_limited          "Troppe richieste: limite 120 al minuto."
 *
 * CORS: a auth superata viene inviato "Access-Control-Allow-Origin: *" (dati
 * pubblici in sola lettura, nessuna credenziale o cookie coinvolta). NOTA per
 * i client browser: i preflight OPTIONS di WordPress non annunciano l'header
 * custom X-Api-Key, quindi da JavaScript cross-origin conviene passare la
 * chiave con la query string (?api_key=...) invece dell'header.
 *
 * RATE LIMIT: 120 richieste al minuto per chiave. Finestra fissa di 60
 * secondi allineata al minuto (floor(time()/60)); il contatore è salvato in
 * un transient con TTL 120 secondi. Al superamento si risponde 429 con
 * header "Retry-After: 60". Il contatore non è atomico (get + set_transient):
 * sotto burst altamente concorrenti può sottostimare di poche unità —
 * tollerabile per un limite di cortesia, non è una misura di sicurezza.
 *
 * ESEMPI CURL:
 *   # Lista articoli (prima pagina, default 12 per pagina)
 *   curl -H "X-Api-Key: LA_TUA_CHIAVE" https://tuosito.it/wp-json/nexus-public/v1/articles
 *
 *   # Lista con filtri (pagina 2, 24 per pagina, categoria Economia)
 *   curl "https://tuosito.it/wp-json/nexus-public/v1/articles?page=2&per_page=24&category=Economia&api_key=LA_TUA_CHIAVE"
 *
 *   # Singolo articolo
 *   curl -H "X-Api-Key: LA_TUA_CHIAVE" https://tuosito.it/wp-json/nexus-public/v1/articles/42
 *
 *   # Agenti attivi
 *   curl -H "X-Api-Key: LA_TUA_CHIAVE" https://tuosito.it/wp-json/nexus-public/v1/agents
 *
 *   # Statistiche pubbliche
 *   curl -H "X-Api-Key: LA_TUA_CHIAVE" https://tuosito.it/wp-json/nexus-public/v1/stats
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Public_API {

        /**
         * Namespace REST pubblico del plugin.
         */
        const NAMESPACE_URI = 'nexus-public/v1';

        /**
         * Limite richieste per finestra di 60 secondi (per chiave API).
         */
        const RATE_LIMIT = 120;

        /**
         * Bootstrap: registra le rotte su rest_api_init (priorità 20, dopo il
         * REST admin che usa il default 10).
         */
        public static function init() {
                add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ), 20 );
        }

        /* =========================================================================
         * REGISTRAZIONE ROTTE
         * ======================================================================= */

        /**
         * Registra le 4 rotte pubbliche. Tutte con permission_callback auth().
         */
        public static function register_routes() {

                /* ---------------- PUBBLICO: ARTICOLI ---------------- */

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/articles',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::READABLE,
                                        'callback'            => array( __CLASS__, 'rest_articles' ),
                                        'permission_callback' => array( __CLASS__, 'auth' ),
                                        'args'                => array(
                                                'page'              => array(
                                                        'type'              => 'integer',
                                                        'required'          => false,
                                                        'default'           => 1,
                                                        'sanitize_callback' => 'absint',
                                                ),
                                                'per_page'          => array(
                                                        'type'              => 'integer',
                                                        'required'          => false,
                                                        'default'           => 12,
                                                        'sanitize_callback' => 'absint',
                                                ),
                                                'category'          => array(
                                                        'type'              => 'string',
                                                        'required'          => false,
                                                        'default'           => '',
                                                        'sanitize_callback' => 'sanitize_text_field',
                                                ),
                                        ),
                                ),
                        )
                );

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/articles/(?P<id>[0-9]+)',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::READABLE,
                                        'callback'            => array( __CLASS__, 'rest_article' ),
                                        'permission_callback' => array( __CLASS__, 'auth' ),
                                        'args'                => array(
                                                'id'                => array(
                                                        'type'              => 'integer',
                                                        'required'          => true,
                                                        'sanitize_callback' => 'absint',
                                                ),
                                        ),
                                ),
                        )
                );

                /* ---------------- PUBBLICO: AGENTI ---------------- */

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/agents',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::READABLE,
                                        'callback'            => array( __CLASS__, 'rest_agents' ),
                                        'permission_callback' => array( __CLASS__, 'auth' ),
                                ),
                        )
                );

                /* ---------------- PUBBLICO: STATISTICHE ---------------- */

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/stats',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::READABLE,
                                        'callback'            => array( __CLASS__, 'rest_stats' ),
                                        'permission_callback' => array( __CLASS__, 'auth' ),
                                ),
                        )
                );
        }

        /* =========================================================================
         * AUTENTICAZIONE E RATE LIMIT
         * ======================================================================= */

        /**
         * Permission callback per tutte le rotte pubbliche.
         *
         * Ordine dei controlli:
         *  1. guard Nexus_DB (altrimenti 501) e check setting enable_public_api (503).
         *  2. chiave da header X-Api-Key, fallback query/body api_key (401 se vuota).
         *  3. chiave configurata nel setting public_api_key (503 se vuota).
         *  4. confronto hash_equals (401 se diversa).
         *  5. header CORS + rate limit.
         *
         * @param WP_REST_Request $request Richiesta corrente.
         * @return true|WP_Error
         */
        public static function auth( $request ) {

                // 1. Nexus_DB è il modulo base: senza di esso l'API non può rispondere.
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'get_settings' ) ) {
                        return new WP_Error( 'nexus_not_available', 'Nexus non disponibile.', array( 'status' => 501 ) );
                }

                $s = Nexus_DB::get_settings();

                if ( empty( $s['enable_public_api'] ) ) {
                        return new WP_Error( 'nexus_api_disabled', 'API pubblica disattivata.', array( 'status' => 503 ) );
                }

                // 2. Chiave dall'header X-Api-Key, con fallback sul parametro api_key.
                $key = $request->get_header( 'X-Api-Key' );
                if ( ! $key ) {
                        $key = $request->get_param( 'api_key' );
                }
                $key = trim( (string) $key );

                if ( '' === $key ) {
                        return new WP_Error( 'nexus_api_key_missing', 'Chiave API mancante.', array( 'status' => 401 ) );
                }

                // 3. Nessuna chiave configurata: l'admin deve generarla in Strumenti.
                if ( empty( $s['public_api_key'] ) ) {
                        return new WP_Error( 'nexus_api_key_not_configured', 'Chiave API non configurata: generala dalla pagina Strumenti di Nexus.', array( 'status' => 503 ) );
                }

                // 4. Confronto a tempo costante tra chiave salvata e chiave ricevuta.
                if ( ! hash_equals( (string) $s['public_api_key'], (string) $key ) ) {
                        return new WP_Error( 'nexus_api_key_invalid', 'Chiave API non valida.', array( 'status' => 401 ) );
                }

                // 5. Dati pubblici in sola lettura: CORS aperto e rate limit.
                @header( 'Access-Control-Allow-Origin: *' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

                return self::rate_limit( $key );
        }

        /**
         * Rate limit: 120 richieste al minuto per chiave (finestra fissa 60s).
         *
         * Implementazione a transient NON atomica: get + setTransient non sono
         * un'operazione unica, quindi sotto forte concorrenza due richieste
         * possono leggere lo stesso contatore e incrementarlo una volta sola.
         * Race-condition TOLLERATA di proposito: il limite serve a scoraggiare
         * abusi grossolani, non è un meccanismo di sicurezza; evitare lock/DB
         * extra mantiene il costo della verifica praticamente nullo.
         *
         * @param string $key Chiave API già validata.
         * @return true|WP_Error true se dentro il limite, 429 al superamento.
         */
        public static function rate_limit( $key ) {

                // Finestra fissa allineata al minuto: cambia chiave transient ogni 60s.
                $window = floor( time() / 60 );
                $tid    = 'nexus_pubapi_rl_' . md5( (string) $key . wp_salt() ) . '_' . $window;

                $count = (int) get_transient( $tid ) + 1;
                set_transient( $tid, $count, 120 );

                if ( $count > self::RATE_LIMIT ) {
                        @header( 'Retry-After: 60' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                        return new WP_Error( 'nexus_rate_limited', 'Troppe richieste: limite 120 al minuto.', array( 'status' => 429 ) );
                }

                return true;
        }

        /* =========================================================================
         * CALLBACK DELLE ROTTE
         * ======================================================================= */

        /**
         * GET /articles — lista articoli pubblicati con paginazione e filtro categoria.
         *
         * @param WP_REST_Request $request Richiesta corrente.
         * @return WP_REST_Response
         */
        public static function rest_articles( $request ) {

                $page     = max( 1, absint( $request->get_param( 'page' ) ) );
                $per_page = min( 50, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
                $cat      = (string) $request->get_param( 'category' );

                // Gli articoli esposti sono SEMPRE e SOLO quelli pubblicati.
                $items = Nexus_DB::articles(
                        array(
                                'status'   => 'published',
                                'category' => $cat,
                                'page'     => $page,
                                'per_page' => $per_page,
                        )
                );

                $total = (int) Nexus_DB::count_articles(
                        array(
                                'status'   => 'published',
                                'category' => $cat,
                        )
                );

                return rest_ensure_response(
                        array(
                                'items' => array_map( array( __CLASS__, 'serialize_public_article' ), (array) $items ),
                                'total' => $total,
                                'page'  => $page,
                                'pages' => (int) ceil( $total / $per_page ),
                        )
                );
        }

        /**
         * GET /articles/{id} — singolo articolo, SOLO se status 'published'.
         *
         * @param WP_REST_Request $request Richiesta corrente.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_article( $request ) {

                $id = absint( $request->get_param( 'id' ) );
                $a  = Nexus_DB::get_article( $id );

                // Bozze, rifiutati, in attesa e id inesistenti sono lo stesso 404.
                if ( ! $a || 'published' !== (string) $a->status ) {
                        return new WP_Error( 'nexus_public_article_not_found', 'Articolo non trovato.', array( 'status' => 404 ) );
                }

                return rest_ensure_response( self::serialize_public_article( $a ) );
        }

        /**
         * GET /agents — agenti con status 'active' e numero articoli scritti.
         *
         * @param WP_REST_Request $request Richiesta corrente.
         * @return WP_REST_Response
         */
        public static function rest_agents( $request ) {

                $items = array();

                foreach ( (array) Nexus_DB::agents() as $a ) {
                        if ( 'active' !== (string) $a->status ) {
                                continue;
                        }
                        $items[] = array(
                                'id'             => (int) $a->id,
                                'name'           => (string) $a->name,
                                'avatar'         => (string) $a->avatar,
                                'category'       => (string) $a->category,
                                'description'    => (string) $a->description,
                                'articles_count' => (int) Nexus_DB::count_articles( array( 'agent_id' => (int) $a->id ) ),
                        );
                }

                return rest_ensure_response( array( 'items' => $items ) );
        }

        /**
         * GET /stats — solo i contatori pubblici da Nexus_DB::stats().
         *
         * @param WP_REST_Request $request Richiesta corrente.
         * @return WP_REST_Response
         */
        public static function rest_stats( $request ) {

                $stats = Nexus_DB::stats();

                return rest_ensure_response(
                        array(
                                'total_articles' => (int) ( $stats['total'] ?? 0 ),
                                'published'      => (int) ( $stats['published'] ?? 0 ),
                                'agents_active'  => (int) ( $stats['agents_active'] ?? 0 ),
                                'views'          => (int) ( $stats['views'] ?? 0 ),
                        )
                );
        }

        /* =========================================================================
         * SERIALIZZAZIONE
         * ======================================================================= */

        /**
         * Serializza un articolo per l'API pubblica (solo campi destinati al pubblico).
         *
         * Il content viene ritornato così com'è in DB (come scritto dalla pipeline).
         * L'URL punta al permalink WordPress se l'articolo è pubblicato come post
         * (wp_post_id > 0), altrimenti al front-end con query string dedicata.
         *
         * @param object|null $a Riga articolo da Nexus_DB::articles()/get_article().
         * @return array
         */
        public static function serialize_public_article( $a ) {

                if ( ! $a ) {
                        return array();
                }

                $agent = ! empty( $a->agent_id ) ? Nexus_DB::get_agent( $a->agent_id ) : null;
                $name  = ( $agent && ! empty( $agent->name ) ) ? (string) $agent->name : '—';

                $url = home_url( '/?nexus_article=' . (int) $a->id );
                if ( (int) $a->wp_post_id > 0 && function_exists( 'get_permalink' ) ) {
                        $permalink = get_permalink( (int) $a->wp_post_id );
                        if ( $permalink ) {
                                $url = $permalink;
                        }
                }

                return array(
                        'id'              => (int) $a->id,
                        'title'           => (string) $a->title,
                        'subtitle'        => (string) $a->subtitle,
                        'summary'         => (string) $a->summary,
                        'content'         => (string) $a->content,
                        'category'        => (string) $a->category,
                        'agent_name'      => $name,
                        'image_url'       => (string) $a->image_url,
                        'url'             => $url,
                        'published_at'    => (string) $a->published_at,
                        'read_time'       => (int) $a->read_time,
                        'views'           => (int) $a->views,
                        'quality_score'   => (float) $a->quality_score,
                        'factcheck_score' => (float) $a->factcheck_score,
                );
        }
}
