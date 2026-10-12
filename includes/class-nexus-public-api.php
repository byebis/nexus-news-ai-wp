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
 *             category (string, opzionale, filtro esatto),
 *             redazione (string, opzionale, v1.1.1: slug o nome; filtra il
 *             ramo COMPLETO della redazione — le sub-redazioni sono incluse,
 *             semantica nativa include_children delle tassonomie gerarchiche.
 *             Sanitizzato con sanitize_title),
 *             lang (string, opzionale, v1.1.2: codice lingua, sanitizzato con
 *             sanitize_key). Driver nexus: filtra gli articoli sulla lingua
 *             del post WordPress nativo collegato (meta _nexus_lang; i post
 *             SENZA meta sono della lingua predefinita del sito), stesso
 *             flusso del filtro redazione (post ID → righe tabella). Con
 *             Polylang o WPML il parametro e' accettato e ignorato: il
 *             contenuto e' gia' filtrato per lingua corrente dai plugin e la
 *             risposta porta il campo "lang_note" con la nota onesta. Lingua
 *             inesistente (o modulo multilingua assente): items vuoto e
 *             total 0, come per la redazione sconosciuta.
 *       Risposta: {"items": [articolo], "total": int, "page": int, "pages": int,
 *         "redazione": null oppure {"id": int, "name": string, "slug": string},
 *         "lang_note": string (solo quando il filtro lang e' delegato a
 *         Polylang/WPML o non applicabile; assente altrimenti)}.
 *       Redazione richiesta ma inesistente (o classe fondamenta assente):
 *       items vuoto, total 0 e "redazione" a null — nessun contenuto inventato.
 *       Articolo serializzato: id, title, subtitle, summary, content,
 *         category, agent_name, image_url, url, published_at, read_time,
 *         views, quality_score, factcheck_score, lang (v1.1.2: codice lingua
 *         del post WordPress nativo collegato; '' se l'articolo non ha post
 *         nativo o il modulo multilingua non e' disponibile).
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
 *   # Lista di una redazione con subtree (slug "sport", sub-redazioni incluse)
 *   curl "https://tuosito.it/wp-json/nexus-public/v1/articles?redazione=sport&api_key=LA_TUA_CHIAVE"
 *
 *   # Lista articoli di una lingua (v1.1.2; driver nexus: filtro meta sui post nativi,
 *   # driver Polylang/WPML: contenuto gia' filtrato dai plugin, risposta con lang_note)
 *   curl "https://tuosito.it/wp-json/nexus-public/v1/articles?lang=en&api_key=LA_TUA_CHIAVE"
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
                                                'redazione'         => array(
                                                        'type'              => 'string',
                                                        'required'          => false,
                                                        'default'           => '',
                                                        'sanitize_callback' => 'sanitize_title',
                                                ),
                                                'lang'              => array(
                                                        'type'              => 'string',
                                                        'required'          => false,
                                                        'default'           => '',
                                                        'sanitize_callback' => 'sanitize_key',
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
         * GET /articles — lista articoli pubblicati con paginazione e filtri
         * categoria, (v1.1.1) redazione a subtree e (v1.1.2) lingua.
         *
         * Con redazione valida il listing passa dai post WordPress del ramo
         * (WP_Query nativa con tax_query include_children) collegati alle righe
         * articoli tramite la colonna wp_post_id. Con redazione inesistente la
         * risposta e' una lista vuota con "redazione" a null: nessun contenuto
         * inventato.
         *
         * v1.1.2, parametro lang (sanitize_key):
         *   - driver nexus: gli articoli sono filtrati sulla lingua del post
         *     WordPress nativo collegato (meta _nexus_lang; i post SENZA meta
         *     sono della lingua predefinita del sito, clausola meta OR tra
         *     NOT EXISTS e valore = lang per la lingua predefinita);
         *   - driver polylang o wpml: il parametro e' accettato e ignorato, il
         *     contenuto e' gia' filtrato per lingua corrente dal plugin, la
         *     risposta porta il campo "lang_note" con la nota onesta;
         *   - lingua inesistente o modulo multilingua assente: lista vuota
         *     (con lang_note quando il modulo manca), come per la redazione
         *     sconosciuta.
         *
         * Combinabile con redazione: intersezione dei due insiemi di post ID.
         * Senza il parametro la query e' identica a prima (zero regressioni).
         *
         * @param WP_REST_Request $request Richiesta corrente.
         * @return WP_REST_Response
         */
        public static function rest_articles( $request ) {

                $page     = max( 1, absint( $request->get_param( 'page' ) ) );
                $per_page = min( 50, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
                $cat      = (string) $request->get_param( 'category' );

                /* v1.1.1: filtro redazione (sanitize_title), semantica subtree. */
                $red_param = sanitize_title( (string) $request->get_param( 'redazione' ) );
                $red_term  = ( '' !== $red_param ) ? self::redazione_term( $red_param ) : null;

                /* Redazione richiesta ma inesistente: lista vuota e metadato onesto. */
                if ( '' !== $red_param && ! $red_term instanceof WP_Term ) {
                        return rest_ensure_response(
                                array(
                                        'items'     => array(),
                                        'total'     => 0,
                                        'page'      => $page,
                                        'pages'     => 0,
                                        'redazione' => null,
                                )
                        );
                }

                /* v1.1.2: filtro lingua (parametro lang, sanitize_key). Stati:
                 *   off       — nessun filtro (parametro assente);
                 *   applied   — driver nexus: filtro meta sui post nativi;
                 *   delegated — Polylang/WPML: contenuto gia' filtrato per lingua
                 *               corrente dai plugin, risposta con lang_note;
                 *   unknown   — lingua inesistente o modulo assente: lista vuota onesta. */
                $lang       = sanitize_key( (string) $request->get_param( 'lang' ) );
                $lang_state = 'off';
                $lang_ids   = array();
                $lang_note  = '';

                if ( '' !== $lang ) {
                        if ( ! class_exists( 'Nexus_Multilang' ) ) {
                                $lang_state = 'unknown';
                                $lang_note  = 'Multilingua non disponibile: parametro lang non applicato.';
                        } else {
                                $driver = Nexus_Multilang::driver();

                                if ( 'nexus' !== $driver ) {
                                        /* Polylang/WPML: il contenuto e' gia' filtrato per lingua
                                         * corrente dai plugin, il parametro viene accettato e ignorato. */
                                        $lang_state = 'delegated';
                                        $lang_note  = ( 'wpml' === $driver )
                                                ? 'contenuto già filtrato da WPML.'
                                                : 'contenuto già filtrato da Polylang.';
                                } elseif ( ! in_array( $lang, Nexus_Multilang::site_langs(), true ) ) {
                                        $lang_state = 'unknown';
                                } else {
                                        $lang_state = 'applied';
                                        $lang_ids   = self::lang_post_ids( $lang );
                                }
                        }
                }

                /* Lingua richiesta ma non applicabile: lista vuota e nota onesta.
                 * Con redazione valida il metadato redazione resta in risposta. */
                if ( 'unknown' === $lang_state ) {
                        $response = array(
                                'items'     => array(),
                                'total'     => 0,
                                'page'      => $page,
                                'pages'     => 0,
                                'redazione' => ( $red_term instanceof WP_Term )
                                        ? array(
                                                'id'   => (int) $red_term->term_id,
                                                'name' => (string) $red_term->name,
                                                'slug' => (string) $red_term->slug,
                                        )
                                        : null,
                        );
                        if ( '' !== $lang_note ) {
                                $response['lang_note'] = $lang_note;
                        }

                        return rest_ensure_response( $response );
                }

                if ( $red_term instanceof WP_Term ) {
                        /* Ramo completo della redazione (sub-redazioni incluse). */
                        $post_ids = self::redazione_branch_post_ids( $red_term );
                        if ( 'applied' === $lang_state ) {
                                /* Intersezione ramo redazione e lingua richiesta (v1.1.2). */
                                $post_ids = array_values( array_intersect( $post_ids, $lang_ids ) );
                        }
                        $rows     = self::redazione_articles( $post_ids, $per_page, ( $page - 1 ) * $per_page );
                        $items    = array_map( array( __CLASS__, 'serialize_public_article' ), $rows );
                        $total    = self::redazione_count_articles( $post_ids );
                        $red_meta = array(
                                'id'   => (int) $red_term->term_id,
                                'name' => (string) $red_term->name,
                                'slug' => (string) $red_term->slug,
                        );
                } elseif ( 'applied' === $lang_state ) {
                        /* Filtro lingua senza redazione (v1.1.2): gli helper per post ID
                         * del filtro redazione sono generici e vengono riusati qui. */
                        $rows     = self::redazione_articles( $lang_ids, $per_page, ( $page - 1 ) * $per_page );
                        $items    = array_map( array( __CLASS__, 'serialize_public_article' ), $rows );
                        $total    = self::redazione_count_articles( $lang_ids );
                        $red_meta = null;
                } else {
                        // Gli articoli esposti sono SEMPRE e SOLO quelli pubblicati.
                        $items = array_map( array( __CLASS__, 'serialize_public_article' ), (array) Nexus_DB::articles(
                                array(
                                        'status'   => 'published',
                                        'category' => $cat,
                                        'page'     => $page,
                                        'per_page' => $per_page,
                                )
                        ) );

                        $total = (int) Nexus_DB::count_articles(
                                array(
                                        'status'   => 'published',
                                        'category' => $cat,
                                )
                        );
                        $red_meta = null;
                }

                $response = array(
                        'items'     => $items,
                        'total'     => $total,
                        'page'      => $page,
                        'pages'     => (int) ceil( $total / $per_page ),
                        'redazione' => $red_meta,
                );
                if ( '' !== $lang_note ) {
                        $response['lang_note'] = $lang_note;
                }

                return rest_ensure_response( $response );
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
         * FILTRO REDAZIONE (v1.1.1)
         * ======================================================================= */

        /**
         * Risolve il termine redazione da slug o nome (v1.1.1).
         *
         * Il parametro arriva gia' sanificato con sanitize_title (args della
         * rotta) e viene risanificato qui per difesa. Ordine di ricerca: slug,
         * poi nome (il collation del DB e' case-insensitive, quindi "sport"
         * matcha anche il nome "Sport"). Guard su classe fondamenta e
         * tassonomia: assenti, si restituisce null (degradazione senza fatal).
         *
         * @param string $value Slug o nome della redazione.
         * @return WP_Term|null
         */
        private static function redazione_term( $value ) {
                $value = sanitize_title( (string) $value );
                if ( '' === $value || ! class_exists( 'Nexus_Redazioni' ) || ! taxonomy_exists( Nexus_Redazioni::TAX ) ) {
                        return null;
                }

                $term = get_term_by( 'slug', $value, Nexus_Redazioni::TAX );
                if ( ! $term instanceof WP_Term ) {
                        $term = get_term_by( 'name', $value, Nexus_Redazioni::TAX );
                }

                return ( $term instanceof WP_Term ) ? $term : null;
        }

        /**
         * Clausola tax_query nativa del ramo redazione (v1.1.1).
         *
         * include_children => true: la redazione madre copre anche le
         * sub-redazioni, identico alla semantica delle categorie gerarchiche
         * di WordPress. Clausola singola, pronta per essere APPENDATA a un
         * eventuale tax_query preesistente con relation AND.
         *
         * @param int $term_id Term ID della redazione.
         * @return array Clausola pronta per WP_Query.
         */
        private static function redazione_tax_clause( $term_id ) {
                return array(
                        'taxonomy'         => Nexus_Redazioni::TAX,
                        'field'            => 'term_id',
                        'terms'            => absint( $term_id ),
                        'include_children' => true,
                );
        }

        /**
         * Post ID del ramo redazione: termine e tutti i discendenti (v1.1.1).
         *
         * WP_Query nativa con la clausola tax_query include_children sui tipi
         * di post dual-mode del plugin (nativo 'post' piu' CPT legacy
         * nexus_article, via Nexus_Native::query_post_types() con guard).
         * Speculare all'helper omonimo di Nexus_Shortcodes: i due file sono
         * scope separati del contratto, quindi la logica e' duplicata di proposito.
         *
         * @param WP_Term $term Termine redazione.
         * @return array Post ID (int).
         */
        private static function redazione_branch_post_ids( $term ) {
                $term_id = ( $term instanceof WP_Term ) ? absint( $term->term_id ) : 0;
                if ( $term_id < 1 || ! class_exists( 'Nexus_Redazioni' ) ) {
                        return array();
                }

                $post_types = array( 'post', 'nexus_article' );
                if ( class_exists( 'Nexus_Native' ) && method_exists( 'Nexus_Native', 'query_post_types' ) ) {
                        $post_types = Nexus_Native::query_post_types();
                }

                /*
                 * Merge attento: la clausola redazione si APPENDA a eventuali
                 * clausole gia' presenti (qui la query nasce da zero, quindi e'
                 * l'unica) e con piu' di una clausola l'operatore resta AND.
                 */
                $tax_query = array();
                $tax_query[] = self::redazione_tax_clause( $term_id );
                if ( count( $tax_query ) > 1 ) {
                        $tax_query['relation'] = 'AND';
                }

                $query = new WP_Query(
                        array(
                                'post_type'              => $post_types,
                                'post_status'            => 'publish',
                                'fields'                 => 'ids',
                                'posts_per_page'         => -1,
                                'no_found_rows'          => true,
                                'update_post_term_cache' => false,
                                'update_post_meta_cache' => false,
                                'tax_query'              => $tax_query,
                        )
                );

                return array_values( array_map( 'absint', (array) $query->posts ) );
        }

        /**
         * Righe articoli Nexus collegate a un insieme di post WordPress (v1.1.1;
         * da v1.1.2 helper generico riusato anche dal filtro lingua).
         *
         * Le righe della tabella articoli Nexus puntano al post WordPress
         * (nativo o CPT legacy) tramite la colonna wp_post_id: il filtro per
         * ramo e' quindi un IN sui Post ID del subtree. Query preparata con
         * wpdb prepare (LIMIT e OFFSET come placeholder), ID assolutizzati e
         * tabella letta da Nexus_DB::tables(): nessuna tabella nuova.
         *
         * @param array $post_ids Post ID del ramo o della lingua.
         * @param int   $limit    Numero massimo di righe (1-50, gia' clampato).
         * @param int   $offset   Offset di paginazione.
         * @return array Righe articolo (stesso shape di Nexus_DB::articles()).
         */
        private static function redazione_articles( $post_ids, $limit, $offset = 0 ) {
                global $wpdb;

                $post_ids = array_values( array_filter( array_map( 'absint', (array) $post_ids ) ) );
                $limit    = max( 1, absint( $limit ) );
                $offset   = max( 0, absint( $offset ) );
                if ( empty( $post_ids ) || ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'tables' ) ) {
                        return array();
                }

                $tables = Nexus_DB::tables();
                if ( ! is_array( $tables ) || empty( $tables['articles'] ) ) {
                        return array();
                }

                $table  = $tables['articles'];
                $ids_in = implode( ',', $post_ids );
                $sql    = "SELECT * FROM {$table}"
                        . " WHERE status = 'published' AND wp_post_id IN ( {$ids_in} )"
                        . " ORDER BY published_at DESC, id DESC"
                        . " LIMIT %d OFFSET %d";

                $rows = $wpdb->get_results( $wpdb->prepare( $sql, $limit, $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ID assolutizzati, LIMIT e OFFSET via prepare.
                return is_array( $rows ) ? $rows : array();
        }

        /**
         * Conta le righe articoli Nexus collegate a un insieme di post (v1.1.1;
         * da v1.1.2 helper generico riusato anche dal filtro lingua).
         *
         * @param array $post_ids Post ID del ramo o della lingua.
         * @return int
         */
        private static function redazione_count_articles( $post_ids ) {
                global $wpdb;

                $post_ids = array_values( array_filter( array_map( 'absint', (array) $post_ids ) ) );
                if ( empty( $post_ids ) || ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'tables' ) ) {
                        return 0;
                }

                $tables = Nexus_DB::tables();
                if ( ! is_array( $tables ) || empty( $tables['articles'] ) ) {
                        return 0;
                }

                $table  = $tables['articles'];
                $ids_in = implode( ',', $post_ids );
                $sql    = "SELECT COUNT(*) FROM {$table}"
                        . " WHERE status = 'published' AND wp_post_id IN ( {$ids_in} )";

                return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ID assolutizzati, nessun placeholder.
        }

        /* =========================================================================
         * FILTRO LINGUA (v1.1.2)
         * ======================================================================= */

        /**
         * Clausole meta_query del filtro lingua (driver nexus, v1.1.2).
         *
         * I post SENZA meta _nexus_lang sono nella lingua predefinita del sito:
         * per la lingua predefinita la clausola e' relation OR tra NOT EXISTS
         * (meta assente) e valore = lang (i post senza meta sono inclusi);
         * per le altre lingue basta la clausola "=" lang, altrimenti i post
         * della lingua predefinita finirebbero in ogni archivio. Ritorna
         * array() se il filtro non e' applicabile (classe assente o lingua non
         * tra le lingue del sito). Clausole pronte per essere APPENDATE a un
         * eventuale meta_query preesistente, senza sovrascriverne la relation.
         *
         * Speculare all'helper omonimo di Nexus_Shortcodes: i due file sono
         * scope separati del contratto, quindi la logica e' duplicata di proposito.
         *
         * @param string $lang Codice lingua.
         * @return array Clausole meta_query o array().
         */
        private static function lang_meta_clauses( $lang ) {
                $lang = sanitize_key( (string) $lang );
                if ( '' === $lang || ! class_exists( 'Nexus_Multilang' ) ) {
                        return array();
                }
                if ( ! in_array( $lang, Nexus_Multilang::site_langs(), true ) ) {
                        return array();
                }

                $equals = array(
                        'key'     => Nexus_Multilang::META_LANG,
                        'value'   => $lang,
                        'compare' => '=',
                );

                if ( $lang === Nexus_Multilang::default_lang() ) {
                        return array(
                                'relation' => 'OR',
                                array(
                                        'key'     => Nexus_Multilang::META_LANG,
                                        'compare' => 'NOT EXISTS',
                                ),
                                $equals,
                        );
                }

                return array( $equals );
        }

        /**
         * Post ID WordPress degli articoli pubblicati in una lingua (v1.1.2).
         *
         * WP_Query nativa con le clausole meta del filtro lingua sui tipi di
         * post dual-mode del plugin (nativo 'post' piu' il CPT legacy
         * nexus_article) via Nexus_Native::query_post_types() con guard
         * class_exists. Solo post pubblicati, cache di term e meta disattivate:
         * servono solo gli ID per filtrare le righe della tabella articoli
         * (colonna wp_post_id), identico al flusso del filtro redazione 1.1.1.
         *
         * @param string $lang Codice lingua.
         * @return array Post ID (int).
         */
        private static function lang_post_ids( $lang ) {
                $clauses = self::lang_meta_clauses( $lang );
                if ( empty( $clauses ) ) {
                        return array();
                }

                $post_types = array( 'post', 'nexus_article' );
                if ( class_exists( 'Nexus_Native' ) && method_exists( 'Nexus_Native', 'query_post_types' ) ) {
                        $post_types = Nexus_Native::query_post_types();
                }

                $query = new WP_Query(
                        array(
                                'post_type'              => $post_types,
                                'post_status'            => 'publish',
                                'fields'                 => 'ids',
                                'posts_per_page'         => -1,
                                'no_found_rows'          => true,
                                'update_post_term_cache' => false,
                                'update_post_meta_cache' => false,
                                'meta_query'             => $clauses,
                        )
                );

                return array_values( array_map( 'absint', (array) $query->posts ) );
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
         * Da v1.1.2 include il campo lang: codice lingua del post WordPress
         * nativo collegato (Nexus_Multilang::lang_of_post con guard class_exists);
         * stringa vuota se l'articolo non ha post nativo o il modulo multilingua
         * non e' disponibile (nessuna lingua inventata).
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

                /* v1.1.2: lingua del post WordPress nativo collegato ('' se l'articolo
                 * non ha post nativo o il modulo multilingua non e' disponibile). */
                $lang = '';
                if ( (int) $a->wp_post_id > 0 && class_exists( 'Nexus_Multilang' ) ) {
                        $lang = Nexus_Multilang::lang_of_post( (int) $a->wp_post_id );
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
                        'lang'            => sanitize_key( $lang ),
                );
        }
}
