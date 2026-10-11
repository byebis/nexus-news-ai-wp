<?php
/**
 * Nexus REST — API REST complete del plugin (namespace nexus/v1).
 *
 * NOTA-INTEGRAZIONE: le route public/* sono consumate dal front-end magazine
 * public/js/nexus-public.js tramite nonce WordPress 'wp_rest' (esposto con
 * wp_create_nonce( 'wp_rest' ) / wp_localize_script). Le route admin usano
 * l'autenticazione cookie standard della REST API (nonce wp_rest + cookie
 * di login) e richiedono la capability 'manage_options'.
 *
 * ROUTE ADMIN (permission_callback: current_user_can('manage_options')):
 *   GET    /nexus/v1/agents
 *   POST   /nexus/v1/agents/{id}/status            body {status: active|paused|error}
 *   GET    /nexus/v1/articles                      args: status, category, agent_id, search, page, per_page
 *   GET    /nexus/v1/articles/{id}
 *   DELETE /nexus/v1/articles/{id}
 *   POST   /nexus/v1/articles/{id}/approve         body {note}
 *   POST   /nexus/v1/articles/{id}/reject          body {note}
 *   POST   /nexus/v1/pipeline/collect              body {agent_id: 0=tutti}
 *   POST   /nexus/v1/pipeline/run                  body {agent_id: 0=tutti}
 *   POST   /nexus/v1/pipeline/publish              body {article_id}
 *   GET    /nexus/v1/settings
 *   PUT    /nexus/v1/settings                      body JSON parziale
 *   GET    /nexus/v1/activity                      args: limit, agent_id
 *   GET    /nexus/v1/stats
 *   POST   /nexus/v1/wire/run                      body {topic} (obbligatorio, max 200 char)
 *   GET    /nexus/v1/wire/runs                     args: limit
 *   GET    /nexus/v1/jobs, /nexus/v1/jobs/stats; POST /nexus/v1/jobs/process, /nexus/v1/jobs/cleanup, /nexus/v1/jobs/{id}/retry, /nexus/v1/jobs/{id}/cancel (v1.0.4)
 *   GET    /nexus/v1/analytics                     args: days (7..90) (v1.0.4)
 *
 * ROUTE PUBBLICHE (permission_callback: __return_true, dati SOLO pubblicabili):
 *   GET    /nexus/v1/public/articles               args: category, search, page, per_page (max 24)
 *   GET    /nexus/v1/public/articles/{id}          solo status published
 *   POST   /nexus/v1/public/articles/{id}/reaction body {emoji}
 *   POST   /nexus/v1/public/articles/{id}/view
 *   GET    /nexus/v1/public/wire/runs              solo run completati, senza dati interni
 *   GET    /nexus/v1/health
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Rest {

        const NAMESPACE_URI = 'nexus/v1';

        /**
         * Cache agenti per la serializzazione degli articoli (1 query per agente per request).
         *
         * @var array
         */
        private static $agent_cache = array();

        /**
         * Bootstrap: registra le route su rest_api_init.
         */
        public static function init() {
                add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        }

        /* =========================================================================
         * REGISTRAZIONE ROUTE
         * ======================================================================= */

        /**
         * Registra tutte le route del plugin.
         */
        public static function register_routes() {

                // Argomento ID condiviso da tutte le route con placeholder {id}.
                $id_arg = array(
                        'id'                => array(
                                'type'              => 'integer',
                                'required'          => true,
                                'sanitize_callback' => 'absint',
                        ),
                );

                $admin_permission = array( __CLASS__, 'perm_admin' );

                /* ---------------- ADMIN: AGENTI ---------------- */

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/agents',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::READABLE,
                                        'callback'            => array( __CLASS__, 'rest_agents' ),
                                        'permission_callback' => $admin_permission,
                                ),
                        )
                );

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/agents/(?P<id>[0-9]+)/status',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_agent_status' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => $id_arg + array(
                                                'status'            => array(
                                                        'type'              => 'string',
                                                        'required'          => true,
                                                        'sanitize_callback' => 'sanitize_key',
                                                ),
                                        ),
                                ),
                        )
                );

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/agents/(?P<id>[0-9]+)/model',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_agent_model' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => $id_arg + array(
                                                'model'             => array(
                                                        'type'              => 'string',
                                                        'required'          => false,
                                                        'default'           => '',
                                                        'sanitize_callback' => 'sanitize_text_field',
                                                ),
                                        ),
                                ),
                        )
                );

                /* ---------------- ADMIN: ARTICOLI ---------------- */

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/articles',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::READABLE,
                                        'callback'            => array( __CLASS__, 'rest_articles' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => array(
                                                'status'            => array(
                                                        'type'              => 'string',
                                                        'required'          => false,
                                                        'sanitize_callback' => 'sanitize_key',
                                                ),
                                                'category'          => array(
                                                        'type'              => 'string',
                                                        'required'          => false,
                                                        'sanitize_callback' => 'sanitize_text_field',
                                                ),
                                                'agent_id'          => array(
                                                        'type'              => 'integer',
                                                        'required'          => false,
                                                        'default'           => 0,
                                                        'sanitize_callback' => 'absint',
                                                ),
                                                'search'            => array(
                                                        'type'              => 'string',
                                                        'required'          => false,
                                                        'sanitize_callback' => 'sanitize_text_field',
                                                ),
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
                                        'callback'            => array( __CLASS__, 'rest_article_get' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => $id_arg,
                                ),
                                array(
                                        'methods'             => WP_REST_Server::DELETABLE,
                                        'callback'            => array( __CLASS__, 'rest_article_delete' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => $id_arg,
                                ),
                        )
                );

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/articles/(?P<id>[0-9]+)/approve',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_article_approve' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => $id_arg + array(
                                                'note'              => array(
                                                        'type'              => 'string',
                                                        'required'          => false,
                                                        'default'           => '',
                                                        'sanitize_callback' => 'sanitize_textarea_field',
                                                ),
                                        ),
                                ),
                        )
                );

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/articles/(?P<id>[0-9]+)/reject',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_article_reject' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => $id_arg + array(
                                                'note'              => array(
                                                        'type'              => 'string',
                                                        'required'          => false,
                                                        'default'           => '',
                                                        'sanitize_callback' => 'sanitize_textarea_field',
                                                ),
                                        ),
                                ),
                        )
                );

                /* ---------------- ADMIN: PIPELINE ---------------- */

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/pipeline/collect',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_pipeline_collect' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => array(
                                                'agent_id'          => array(
                                                        'type'              => 'integer',
                                                        'required'          => false,
                                                        'default'           => 0,
                                                        'sanitize_callback' => 'absint',
                                                ),
                                        ),
                                ),
                        )
                );

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/pipeline/run',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_pipeline_run' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => array(
                                                'agent_id'          => array(
                                                        'type'              => 'integer',
                                                        'required'          => false,
                                                        'default'           => 0,
                                                        'sanitize_callback' => 'absint',
                                                ),
                                        ),
                                ),
                        )
                );

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/pipeline/publish',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_pipeline_publish' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => array(
                                                'article_id'        => array(
                                                        'type'              => 'integer',
                                                        'required'          => true,
                                                        'sanitize_callback' => 'absint',
                                                ),
                                        ),
                                ),
                        )
                );

                /* ---------------- ADMIN: SETTINGS ---------------- */

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/settings',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::READABLE,
                                        'callback'            => array( __CLASS__, 'rest_settings_get' ),
                                        'permission_callback' => $admin_permission,
                                ),
                                array(
                                        'methods'             => WP_REST_Server::EDITABLE,
                                        'callback'            => array( __CLASS__, 'rest_settings_update' ),
                                        'permission_callback' => $admin_permission,
                                ),
                        )
                );

                /* ---------------- ADMIN: ACTIVITY / STATS ---------------- */

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/activity',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::READABLE,
                                        'callback'            => array( __CLASS__, 'rest_activity' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => array(
                                                'limit'             => array(
                                                        'type'              => 'integer',
                                                        'required'          => false,
                                                        'default'           => 20,
                                                        'sanitize_callback' => 'absint',
                                                ),
                                                'agent_id'          => array(
                                                        'type'              => 'integer',
                                                        'required'          => false,
                                                        'default'           => 0,
                                                        'sanitize_callback' => 'absint',
                                                ),
                                        ),
                                ),
                        )
                );

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/stats',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::READABLE,
                                        'callback'            => array( __CLASS__, 'rest_stats' ),
                                        'permission_callback' => $admin_permission,
                                ),
                        )
                );

                /* ---------------- ADMIN: NEXUS WIRE ---------------- */

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/wire/run',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_wire_run' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => array(
                                                'topic'             => array(
                                                        'type'              => 'string',
                                                        'required'          => true,
                                                        'sanitize_callback' => 'sanitize_text_field',
                                                ),
                                        ),
                                ),
                        )
                );

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/wire/runs',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::READABLE,
                                        'callback'            => array( __CLASS__, 'rest_wire_runs' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => array(
                                                'limit'             => array(
                                                        'type'              => 'integer',
                                                        'required'          => false,
                                                        'default'           => 10,
                                                        'sanitize_callback' => 'absint',
                                                ),
                                        ),
                                ),
                        )
                );

                /* ---------------- PUBBLICO: ARTICOLI ---------------- */

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/public/articles',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::READABLE,
                                        'callback'            => array( __CLASS__, 'rest_public_articles' ),
                                        'permission_callback' => '__return_true',
                                        'args'                => array(
                                                'category'          => array(
                                                        'type'              => 'string',
                                                        'required'          => false,
                                                        'sanitize_callback' => 'sanitize_text_field',
                                                ),
                                                'search'            => array(
                                                        'type'              => 'string',
                                                        'required'          => false,
                                                        'sanitize_callback' => 'sanitize_text_field',
                                                ),
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
                                        ),
                                ),
                        )
                );

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/public/articles/(?P<id>[0-9]+)',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::READABLE,
                                        'callback'            => array( __CLASS__, 'rest_public_article_get' ),
                                        'permission_callback' => '__return_true',
                                        'args'                => $id_arg,
                                ),
                        )
                );

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/public/articles/(?P<id>[0-9]+)/reaction',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_public_reaction' ),
                                        'permission_callback' => '__return_true',
                                        'args'                => $id_arg + array(
                                                'emoji'             => array(
                                                        'type'              => 'string',
                                                        'required'          => true,
                                                        'sanitize_callback' => 'sanitize_text_field',
                                                ),
                                        ),
                                ),
                        )
                );

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/public/articles/(?P<id>[0-9]+)/view',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_public_view' ),
                                        'permission_callback' => '__return_true',
                                        'args'                => $id_arg,
                                ),
                        )
                );

                /* ---------------- PUBBLICO: WIRE ---------------- */

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/public/wire/runs',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::READABLE,
                                        'callback'            => array( __CLASS__, 'rest_public_wire_runs' ),
                                        'permission_callback' => '__return_true',
                                        'args'                => array(
                                                'limit'             => array(
                                                        'type'              => 'integer',
                                                        'required'          => false,
                                                        'default'           => 10,
                                                        'sanitize_callback' => 'absint',
                                                ),
                                        ),
                                ),
                        )
                );

                /* ---------------- HEALTH ---------------- */

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/health',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::READABLE,
                                        'callback'            => array( __CLASS__, 'rest_health' ),
                                        'permission_callback' => '__return_true',
                                ),
                        )
                );

                /* ---------------- ADMIN: CODA LAVORI (v1.0.4) ---------------- */

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/jobs',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::READABLE,
                                        'callback'            => array( __CLASS__, 'rest_jobs' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => array(
                                                'status'            => array(
                                                        'type'              => 'string',
                                                        'required'          => false,
                                                        'default'           => '',
                                                        'sanitize_callback' => 'sanitize_key',
                                                ),
                                                'hook'              => array(
                                                        'type'              => 'string',
                                                        'required'          => false,
                                                        'default'           => '',
                                                        'sanitize_callback' => 'sanitize_key',
                                                ),
                                                'page'              => array(
                                                        'type'              => 'integer',
                                                        'required'          => false,
                                                        'default'           => 1,
                                                        'sanitize_callback' => 'absint',
                                                ),
                                                'per_page'          => array(
                                                        'type'              => 'integer',
                                                        'required'          => false,
                                                        'default'           => 50,
                                                        'sanitize_callback' => 'absint',
                                                ),
                                        ),
                                ),
                        )
                );

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/jobs/stats',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::READABLE,
                                        'callback'            => array( __CLASS__, 'rest_jobs_stats' ),
                                        'permission_callback' => $admin_permission,
                                ),
                        )
                );

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/jobs/process',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_jobs_process' ),
                                        'permission_callback' => $admin_permission,
                                ),
                        )
                );

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/jobs/cleanup',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_jobs_cleanup' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => array(
                                                'days'              => array(
                                                        'type'              => 'integer',
                                                        'required'          => false,
                                                        'default'           => 7,
                                                        'sanitize_callback' => 'absint',
                                                ),
                                        ),
                                ),
                        )
                );

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/jobs/(?P<id>[0-9]+)/retry',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_job_retry' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => $id_arg,
                                ),
                        )
                );

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/jobs/(?P<id>[0-9]+)/cancel',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_job_cancel' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => $id_arg,
                                ),
                        )
                );

                /* ---------------- ADMIN: ANALYTICS (v1.0.4) ---------------- */

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/analytics',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::READABLE,
                                        'callback'            => array( __CLASS__, 'rest_analytics' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => array(
                                                'days'              => array(
                                                        'type'              => 'integer',
                                                        'required'          => false,
                                                        'default'           => 30,
                                                        'sanitize_callback' => 'absint',
                                                ),
                                        ),
                                ),
                        )
                );
        }

        /* =========================================================================
         * PERMISSION CALLBACKS
         * ======================================================================= */

        /**
         * Permesso admin: capability manage_options, altrimenti 403.
         *
         * @return true|WP_Error
         */
        public static function perm_admin() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        return new WP_Error(
                                'nexus_rest_forbidden',
                                __( 'Non hai i permessi per accedere a questa risorsa.', 'nexus-news-ai' ),
                                array( 'status' => 403 )
                        );
                }
                return true;
        }

        /* =========================================================================
         * CALLBACK — ADMIN: AGENTI
         * ======================================================================= */

        /**
         * GET /agents — elenco agenti.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response
         */
        public static function rest_agents( $request ) {
                unset( $request );
                $agents = array_map( array( __CLASS__, 'serialize_agent' ), Nexus_DB::agents() );
                return rest_ensure_response( $agents );
        }

        /**
         * POST /agents/{id}/status — cambia stato agente (active|paused|error).
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_agent_status( $request ) {
                $id     = absint( $request->get_param( 'id' ) );
                $status = (string) $request->get_param( 'status' );

                $allowed = array( 'active', 'paused', 'error' );
                if ( ! in_array( $status, $allowed, true ) ) {
                        return self::err(
                                'nexus_rest_invalid_status',
                                /* translators: %s: elenco stati ammessi. */
                                sprintf( __( 'Stato non valido. Valori ammessi: %s.', 'nexus-news-ai' ), implode( ', ', $allowed ) ),
                                400
                        );
                }

                $agent = Nexus_DB::get_agent( $id );
                if ( ! $agent ) {
                        return self::err( 'nexus_rest_agent_not_found', __( 'Agente non trovato.', 'nexus-news-ai' ), 404 );
                }

                Nexus_DB::update_agent( $id, array( 'status' => $status ) );
                Nexus_DB::insert_activity( $id, 'agent_status', sprintf( 'Stato agente aggiornato a "%s" via API.', $status ), 'info' );

                $updated = Nexus_DB::get_agent( $id );
                return rest_ensure_response( self::serialize_agent( $updated ) );
        }

        /**
         * PUT/POST /agents/{id}/model — imposta il modello AI dedicato di un agente.
         * Stringa vuota = usa il modello globale (v1.0.3).
         */
        public static function rest_agent_model( $request ) {
                $id    = absint( $request->get_param( 'id' ) );
                $model = trim( (string) $request->get_param( 'model' ) );
                if ( $id <= 0 ) {
                        return self::err( 'nexus_rest_agent_not_found', __( 'Agente non trovato.', 'nexus-news-ai' ), 404 );
                }
                if ( mb_strlen( $model ) > 100 ) {
                        return self::err( 'nexus_rest_invalid_model', __( 'Nome modello troppo lungo (max 100 caratteri).', 'nexus-news-ai' ), 400 );
                }
                $agent = Nexus_DB::get_agent( $id );
                if ( ! $agent ) {
                        return self::err( 'nexus_rest_agent_not_found', __( 'Agente non trovato.', 'nexus-news-ai' ), 404 );
                }
                Nexus_DB::update_agent( $id, array( 'ai_model' => $model ) );
                Nexus_DB::insert_activity( $id, 'agent_model', sprintf( 'Modello AI aggiornato: %s', '' !== $model ? $model : '(globale)' ), 'info' );
                $updated = Nexus_DB::get_agent( $id );
                return rest_ensure_response( self::serialize_agent( $updated ) );
        }

        /* =========================================================================
         * CALLBACK — ADMIN: ARTICOLI
         * ======================================================================= */

        /**
         * GET /articles — elenco articoli con filtri e paginazione.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response
         */
        public static function rest_articles( $request ) {
                $args = array(
                        'status'   => (string) $request->get_param( 'status' ),
                        'category' => (string) $request->get_param( 'category' ),
                        'agent_id' => absint( $request->get_param( 'agent_id' ) ),
                        'search'   => (string) $request->get_param( 'search' ),
                        'page'     => max( 1, absint( $request->get_param( 'page' ) ) ),
                        'per_page' => min( 100, max( 1, absint( $request->get_param( 'per_page' ) ) ) ),
                );

                $rows  = Nexus_DB::articles( $args );
                $total = Nexus_DB::count_articles( $args );

                return rest_ensure_response(
                        array(
                                'items'        => array_map( array( __CLASS__, 'serialize_article' ), $rows ),
                                'total'        => (int) $total,
                                'page'         => (int) $args['page'],
                                'per_page'     => (int) $args['per_page'],
                                'total_pages'  => (int) ceil( $total / $args['per_page'] ),
                        )
                );
        }

        /**
         * GET /articles/{id} — singolo articolo con publish_logs.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_article_get( $request ) {
                $id      = absint( $request->get_param( 'id' ) );
                $article = self::get_article_or_404( $id );
                if ( is_wp_error( $article ) ) {
                        return $article;
                }

                $out              = self::serialize_article( $article, true );
                $out['publish_logs'] = array_map( array( __CLASS__, 'serialize_publish_log' ), Nexus_DB::publish_logs( $id ) );

                return rest_ensure_response( $out );
        }

        /**
         * DELETE /articles/{id} — elimina articolo e log correlati.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_article_delete( $request ) {
                $id      = absint( $request->get_param( 'id' ) );
                $article = self::get_article_or_404( $id );
                if ( is_wp_error( $article ) ) {
                        return $article;
                }

                $deleted = Nexus_DB::delete_article( $id );
                if ( ! $deleted ) {
                        return self::err( 'nexus_rest_delete_failed', __( 'Impossibile eliminare l\'articolo.', 'nexus-news-ai' ), 500 );
                }

                return rest_ensure_response(
                        array(
                                'deleted' => true,
                                'id'      => $id,
                        )
                );
        }

        /**
         * POST /articles/{id}/approve — approvazione via pipeline.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_article_approve( $request ) {
                return self::handle_review( $request, 'approve' );
        }

        /**
         * POST /articles/{id}/reject — rifiuto via pipeline.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_article_reject( $request ) {
                return self::handle_review( $request, 'reject' );
        }

        /* =========================================================================
         * CALLBACK — ADMIN: PIPELINE
         * ======================================================================= */

        /**
         * POST /pipeline/collect — raccolta per un agente o per tutti gli agenti attivi.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_pipeline_collect( $request ) {
                return self::run_pipeline_for_agents( absint( $request->get_param( 'agent_id' ) ), 'collect' );
        }

        /**
         * POST /pipeline/run — pipeline completa per un agente o per tutti gli agenti attivi.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_pipeline_run( $request ) {
                return self::run_pipeline_for_agents( absint( $request->get_param( 'agent_id' ) ), 'run_full' );
        }

        /**
         * POST /pipeline/publish — pubblica un singolo articolo approvato.
         *
         * @param WP_REST_Request $request Richiesta REST.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_pipeline_publish( $request ) {
                $article_id = absint( $request->get_param( 'article_id' ) );

                if ( $article_id <= 0 ) {
                        return self::err( 'nexus_rest_article_id_missing', __( 'Parametro article_id mancante.', 'nexus-news-ai' ), 400 );
                }

                $article = Nexus_DB::get_article( $article_id );
                if ( ! $article ) {
                        return self::err( 'nexus_rest_article_not_found', __( 'Articolo non trovato.', 'nexus-news-ai' ), 404 );
                }

                if ( ! is_callable( array( 'Nexus_Pipeline', 'publish' ) ) ) {
                        return self::err( 'nexus_rest_pipeline_missing', __( 'Modulo pipeline non disponibile.', 'nexus-news-ai' ), 500 );
                }

                $res = Nexus_Pipeline::publish( $article_id );

                if ( is_wp_error( $res ) ) {
                        return self::err( 'nexus_rest_publish_failed', $res->get_error_message(), 500 );
                }

                $fresh = Nexus_DB::get_article( $article_id );

                return rest_ensure_response(
                        array(
                                'published' => ! empty( $res['ok'] ),
                                'already'   => ! empty( $res['already'] ),
                                'logs'      => isset( $res['logs'] ) ? $res['logs'] : array(),
                                'article'   => $fresh ? self::serialize_article( $fresh, true ) : null,
                        )
                );
        }

        /* =========================================================================
         * CALLBACK — ADMIN: SETTINGS
         * ======================================================================= */

        /**
         * GET /settings — impostazioni complete.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response
         */
        public static function rest_settings_get( $request ) {
                unset( $request );
                return rest_ensure_response( Nexus_DB::get_settings() );
        }

        /**
         * PUT /settings — aggiornamento parziale con whitelist chiavi.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_settings_update( $request ) {
                $body = $request->get_json_params();
                if ( ! is_array( $body ) || empty( $body ) ) {
                        $body = (array) $request->get_body_params();
                }
                if ( empty( $body ) ) {
                        return self::err( 'nexus_rest_empty_body', __( 'Nessun campo da aggiornare nel corpo della richiesta.', 'nexus-news-ai' ), 400 );
                }

                $payload = self::sanitize_settings_payload( $body );
                if ( is_wp_error( $payload ) ) {
                        return $payload;
                }
                if ( empty( $payload ) ) {
                        return self::err( 'nexus_rest_no_valid_fields', __( 'Nessuna chiave riconosciuta tra i campi inviati.', 'nexus-news-ai' ), 400 );
                }

                $updated = Nexus_DB::update_settings( $payload );
                Nexus_DB::insert_activity( 0, 'settings_update', __( 'Impostazioni aggiornate via API.', 'nexus-news-ai' ), 'info' );

                return rest_ensure_response( $updated );
        }

        /* =========================================================================
         * CALLBACK — ADMIN: ACTIVITY / STATS
         * ======================================================================= */

        /**
         * GET /activity — log attività recenti.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response
         */
        public static function rest_activity( $request ) {
                $rows = Nexus_DB::activity(
                        array(
                                'limit'    => min( 200, max( 1, absint( $request->get_param( 'limit' ) ) ) ),
                                'agent_id' => absint( $request->get_param( 'agent_id' ) ),
                        )
                );

                return rest_ensure_response(
                        array(
                                'items' => array_map( array( __CLASS__, 'serialize_activity' ), $rows ),
                                'total' => count( $rows ),
                        )
                );
        }

        /**
         * GET /stats — conteggi dashboard.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response
         */
        public static function rest_stats( $request ) {
                unset( $request );
                return rest_ensure_response( Nexus_DB::stats() );
        }

        /* =========================================================================
         * CALLBACK — ADMIN: NEXUS WIRE
         * ======================================================================= */

        /**
         * POST /wire/run — lancia una redazione collettiva su un tema.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_wire_run( $request ) {
                $topic = trim( (string) $request->get_param( 'topic' ) );
                if ( '' === $topic ) {
                        return self::err( 'nexus_rest_missing_topic', __( 'Il campo "topic" è obbligatorio.', 'nexus-news-ai' ), 400 );
                }
                $topic = mb_substr( $topic, 0, 200 );

                if ( ! is_callable( array( 'Nexus_Pipeline', 'wire_run' ) ) ) {
                        return self::err( 'nexus_rest_pipeline_missing', __( 'Modulo pipeline non disponibile.', 'nexus-news-ai' ), 500 );
                }

                $result = Nexus_Pipeline::wire_run( $topic );
                if ( is_wp_error( $result ) ) {
                        return $result;
                }

                return rest_ensure_response(
                        array(
                                'success' => true,
                                'topic'   => $topic,
                                'run'     => $result,
                        )
                );
        }

        /**
         * GET /wire/runs — storico run Nexus Wire (admin: dati completi).
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response
         */
        public static function rest_wire_runs( $request ) {
                $limit = min( 50, max( 1, absint( $request->get_param( 'limit' ) ) ) );

                if ( ! is_callable( array( 'Nexus_Pipeline', 'wire_runs' ) ) ) {
                        return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
                }

                $runs = Nexus_Pipeline::wire_runs( $limit );
                if ( is_wp_error( $runs ) ) {
                        return $runs;
                }
                $runs = is_array( $runs ) ? array_values( $runs ) : array();

                return rest_ensure_response(
                        array(
                                'items' => $runs,
                                'total' => count( $runs ),
                        )
                );
        }

        /* =========================================================================
         * CALLBACK — PUBBLICO
         * ======================================================================= */

        /**
         * GET /public/articles — solo articoli pubblicati, ordinati per published_at DESC.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response
         */
        public static function rest_public_articles( $request ) {
                $args = array(
                        'status'   => 'published',
                        'category' => (string) $request->get_param( 'category' ),
                        'search'   => (string) $request->get_param( 'search' ),
                        'page'     => max( 1, absint( $request->get_param( 'page' ) ) ),
                        'per_page' => min( 24, max( 1, absint( $request->get_param( 'per_page' ) ) ) ),
                        'orderby'  => 'published_at',
                        'order'    => 'desc',
                );

                $rows  = Nexus_DB::articles( $args );
                $total = Nexus_DB::count_articles( $args );

                return rest_ensure_response(
                        array(
                                'items'       => array_map( array( __CLASS__, 'serialize_public_article' ), $rows ),
                                'total'       => (int) $total,
                                'page'        => (int) $args['page'],
                                'per_page'    => (int) $args['per_page'],
                                'total_pages' => (int) ceil( $total / $args['per_page'] ),
                        )
                );
        }

        /**
         * GET /public/articles/{id} — articolo completo, solo se pubblicato.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_public_article_get( $request ) {
                $id      = absint( $request->get_param( 'id' ) );
                $article = self::get_published_article_or_404( $id );
                if ( is_wp_error( $article ) ) {
                        return $article;
                }

                return rest_ensure_response( self::serialize_article( $article, true ) );
        }

        /**
         * POST /public/articles/{id}/reaction — registra una reazione emoji.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_public_reaction( $request ) {
                $id      = absint( $request->get_param( 'id' ) );
                $article = self::get_published_article_or_404( $id );
                if ( is_wp_error( $article ) ) {
                        return $article;
                }

                $emoji = trim( (string) $request->get_param( 'emoji' ) );
                if ( '' === $emoji ) {
                        return self::err( 'nexus_rest_missing_emoji', __( 'Il campo "emoji" è obbligatorio.', 'nexus-news-ai' ), 400 );
                }

                $counts = Nexus_DB::add_reaction( $id, $emoji );
                if ( false === $counts ) {
                        return self::err( 'nexus_rest_reaction_failed', __( 'Impossibile registrare la reazione.', 'nexus-news-ai' ), 500 );
                }

                return rest_ensure_response(
                        array(
                                'reactions' => is_array( $counts ) ? $counts : array(),
                        )
                );
        }

        /**
         * POST /public/articles/{id}/view — incrementa il contatore visualizzazioni.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_public_view( $request ) {
                $id      = absint( $request->get_param( 'id' ) );
                $article = self::get_published_article_or_404( $id );
                if ( is_wp_error( $article ) ) {
                        return $article;
                }

                Nexus_DB::bump_view( $id );
                $fresh = Nexus_DB::get_article( $id );

                return rest_ensure_response(
                        array(
                                'views' => $fresh ? (int) $fresh->views : (int) $article->views + 1,
                        )
                );
        }

        /**
         * GET /public/wire/runs — run Wire completati, senza dati interni.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response
         */
        public static function rest_public_wire_runs( $request ) {
                $limit = min( 50, max( 1, absint( $request->get_param( 'limit' ) ) ) );

                if ( ! is_callable( array( 'Nexus_Pipeline', 'wire_runs' ) ) ) {
                        return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
                }

                $runs = Nexus_Pipeline::wire_runs( $limit );
                if ( is_wp_error( $runs ) || ! is_array( $runs ) ) {
                        $runs = array();
                }

                $allowed_keys = array( 'id', 'topic', 'status', 'created_at', 'updated_at' );
                $items        = array();
                foreach ( $runs as $run ) {
                        $arr = is_array( $run ) ? $run : (array) $run;
                        if ( ! isset( $arr['status'] ) || 'completed' !== $arr['status'] ) {
                                continue;
                        }
                        $items[] = array_intersect_key( $arr, array_flip( $allowed_keys ) );
                }

                return rest_ensure_response(
                        array(
                                'items' => $items,
                                'total' => count( $items ),
                        )
                );
        }

        /**
         * GET /health — stato del plugin.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response
         */
        public static function rest_health( $request ) {
                unset( $request );

                $settings  = Nexus_DB::get_settings();
                $cron_next = wp_next_scheduled( 'nexus_cron_tick' );

                return rest_ensure_response(
                        array(
                                'ok'        => true,
                                'version'   => defined( 'NEXUS_VERSION' ) ? NEXUS_VERSION : '',
                                'mode'      => isset( $settings['mode'] ) ? (string) $settings['mode'] : '',
                                'cron_next' => $cron_next ? (int) $cron_next : null,
                        )
                );
        }

        /* =========================================================================
         * CALLBACK — ADMIN: CODA LAVORI (v1.0.4)
         * ======================================================================= */

        /**
         * GET /jobs — elenco lavori della coda con filtri e paginazione (v1.0.4).
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response
         */
        public static function rest_jobs( $request ) {
                $page     = max( 1, absint( $request->get_param( 'page' ) ) );
                $per_page = min( 200, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
                $args     = array(
                        'status' => (string) $request->get_param( 'status' ),
                        'hook'   => (string) $request->get_param( 'hook' ),
                        'limit'  => $per_page,
                        'offset' => ( $page - 1 ) * $per_page,
                );

                $rows  = Nexus_DB::jobs( $args );
                $total = (int) Nexus_DB::count_jobs( array( 'status' => $args['status'], 'hook' => $args['hook'] ) );

                return rest_ensure_response(
                        array(
                                'items' => array_map( array( __CLASS__, 'serialize_job' ), $rows ),
                                'total' => $total,
                                'pages' => (int) ceil( $total / $per_page ),
                                'stats' => Nexus_DB::job_stats(),
                        )
                );
        }

        /**
         * GET /jobs/stats — statistiche aggregate della coda (v1.0.4).
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response
         */
        public static function rest_jobs_stats( $request ) {
                unset( $request );
                return rest_ensure_response( Nexus_DB::job_stats() );
        }

        /**
         * POST /jobs/process — esegue subito un batch della coda (v1.0.4).
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_jobs_process( $request ) {
                unset( $request );
                if ( ! class_exists( 'Nexus_Queue' ) || ! method_exists( 'Nexus_Queue', 'run_batch' ) ) {
                        return self::err( 'nexus_not_available', __( 'Coda lavori non disponibile.', 'nexus-news-ai' ), 501 );
                }

                $res = Nexus_Queue::run_batch();
                $res = is_array( $res ) ? $res : array();

                return rest_ensure_response(
                        array(
                                'processed' => (int) ( $res['processed'] ?? 0 ),
                                'failed'    => (int) ( $res['failed'] ?? 0 ),
                        )
                );
        }

        /**
         * POST /jobs/cleanup — elimina i lavori conclusi più vecchi di N giorni (v1.0.4).
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response
         */
        public static function rest_jobs_cleanup( $request ) {
                $days    = min( 90, max( 1, absint( $request->get_param( 'days' ) ) ) );
                $deleted = (int) Nexus_DB::cleanup_jobs( $days );

                return rest_ensure_response( array( 'deleted' => $deleted ) );
        }

        /**
         * POST /jobs/{id}/retry — rimette in coda un lavoro concluso o fallito (v1.0.4).
         *
         * Stati ammessi per il retry: complete, failed, cancelled → pending.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_job_retry( $request ) {
                if ( ! class_exists( 'Nexus_Queue' ) || ! method_exists( 'Nexus_Queue', 'retry' ) ) {
                        return self::err( 'nexus_not_available', __( 'Coda lavori non disponibile.', 'nexus-news-ai' ), 501 );
                }

                $id  = absint( $request->get_param( 'id' ) );
                $job = Nexus_DB::get_job( $id );
                if ( ! $job ) {
                        return self::err( 'nexus_rest_job_not_found', __( 'Lavoro non trovato.', 'nexus-news-ai' ), 404 );
                }

                $allowed = array( 'complete', 'failed', 'cancelled' );
                if ( ! in_array( (string) $job->status, $allowed, true ) ) {
                        return self::err( 'nexus_rest_invalid_job_status', __( 'Lo stato attuale non consente il retry.', 'nexus-news-ai' ), 400 );
                }

                $res = Nexus_Queue::retry( $id );

                return rest_ensure_response(
                        array(
                                'success' => ! empty( $res ),
                                'status'  => 'pending',
                        )
                );
        }

        /**
         * POST /jobs/{id}/cancel — annulla un lavoro in attesa (v1.0.4).
         *
         * Solo i lavori pending possono essere annullati → cancelled.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_job_cancel( $request ) {
                if ( ! class_exists( 'Nexus_Queue' ) || ! method_exists( 'Nexus_Queue', 'cancel' ) ) {
                        return self::err( 'nexus_not_available', __( 'Coda lavori non disponibile.', 'nexus-news-ai' ), 501 );
                }

                $id  = absint( $request->get_param( 'id' ) );
                $job = Nexus_DB::get_job( $id );
                if ( ! $job ) {
                        return self::err( 'nexus_rest_job_not_found', __( 'Lavoro non trovato.', 'nexus-news-ai' ), 404 );
                }

                if ( 'pending' !== (string) $job->status ) {
                        return self::err( 'nexus_rest_invalid_job_status', __( 'Solo i lavori in attesa possono essere annullati.', 'nexus-news-ai' ), 400 );
                }

                $res = Nexus_Queue::cancel( $id );

                return rest_ensure_response(
                        array(
                                'success' => ! empty( $res ),
                                'status'  => 'cancelled',
                        )
                );
        }

        /* =========================================================================
         * CALLBACK — ADMIN: ANALYTICS (v1.0.4)
         * ======================================================================= */

        /**
         * GET /analytics — overview analytics per periodo (v1.0.4).
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_analytics( $request ) {
                if ( ! class_exists( 'Nexus_Analytics' ) || ! method_exists( 'Nexus_Analytics', 'overview' ) ) {
                        return self::err( 'nexus_not_available', __( 'Analytics non disponibile.', 'nexus-news-ai' ), 501 );
                }

                $days = min( 90, max( 7, absint( $request->get_param( 'days' ) ) ) );

                return rest_ensure_response( Nexus_Analytics::overview( $days ) );
        }

        /* =========================================================================
         * HELPER PRIVATI
         * ======================================================================= */

        /**
         * WP_Error pronto con status HTTP.
         *
         * @param string $code    Codice errore.
         * @param string $message Messaggio.
         * @param int    $status  Status HTTP.
         * @return WP_Error
         */
        private static function err( $code, $message, $status ) {
                return new WP_Error( $code, $message, array( 'status' => (int) $status ) );
        }

        /**
         * Articolo o 404 (uso admin).
         *
         * @param int $id ID articolo.
         * @return object|WP_Error
         */
        private static function get_article_or_404( $id ) {
                $article = Nexus_DB::get_article( $id );
                if ( ! $article ) {
                        return self::err( 'nexus_rest_article_not_found', __( 'Articolo non trovato.', 'nexus-news-ai' ), 404 );
                }
                return $article;
        }

        /**
         * Articolo pubblicato o 404 (uso pubblico: non svela l'esistenza di bozze).
         *
         * @param int $id ID articolo.
         * @return object|WP_Error
         */
        private static function get_published_article_or_404( $id ) {
                $article = Nexus_DB::get_article( $id );
                if ( ! $article || 'published' !== $article->status ) {
                        return self::err( 'nexus_rest_article_not_found', __( 'Articolo non trovato.', 'nexus-news-ai' ), 404 );
                }
                return $article;
        }

        /**
         * Approvazione/rifiuto articolo via Nexus_Pipeline.
         *
         * @param WP_REST_Request $request Request.
         * @param string          $action  'approve'|'reject'.
         * @return WP_REST_Response|WP_Error
         */
        private static function handle_review( $request, $action ) {
                $id      = absint( $request->get_param( 'id' ) );
                $article = self::get_article_or_404( $id );
                if ( is_wp_error( $article ) ) {
                        return $article;
                }

                $note = (string) $request->get_param( 'note' );

                if ( ! is_callable( array( 'Nexus_Pipeline', $action ) ) ) {
                        return self::err( 'nexus_rest_pipeline_missing', __( 'Modulo pipeline non disponibile.', 'nexus-news-ai' ), 500 );
                }

                $result = 'approve' === $action
                        ? Nexus_Pipeline::approve( $id, $note )
                        : Nexus_Pipeline::reject( $id, $note );

                if ( is_wp_error( $result ) ) {
                        return $result;
                }

                $fresh = Nexus_DB::get_article( $id );

                return rest_ensure_response(
                        array(
                                'success' => true,
                                'action'  => $action,
                                'article' => $fresh ? self::serialize_article( $fresh, true ) : null,
                        )
                );
        }

        /**
         * Esegue collect() o run_full() su un agente singolo o su tutti gli agenti attivi.
         *
         * @param int    $agent_id ID agente, 0 = tutti gli agenti attivi.
         * @param string $method   'collect'|'run_full'.
         * @return WP_REST_Response|WP_Error
         */
        private static function run_pipeline_for_agents( $agent_id, $method ) {
                if ( ! is_callable( array( 'Nexus_Pipeline', $method ) ) ) {
                        return self::err( 'nexus_rest_pipeline_missing', __( 'Modulo pipeline non disponibile.', 'nexus-news-ai' ), 500 );
                }

                if ( $agent_id > 0 ) {
                        $agent = Nexus_DB::get_agent( $agent_id );
                        if ( ! $agent ) {
                                return self::err( 'nexus_rest_agent_not_found', __( 'Agente non trovato.', 'nexus-news-ai' ), 404 );
                        }
                        $targets = array( $agent );
                } else {
                        $targets = array();
                        foreach ( Nexus_DB::agents() as $agent ) {
                                if ( 'active' === $agent->status ) {
                                        $targets[] = $agent;
                                }
                        }
                }

                $results = array();
                foreach ( $targets as $agent ) {
                        $res       = 'collect' === $method
                                ? Nexus_Pipeline::collect( (int) $agent->id )
                                : Nexus_Pipeline::run_full( (int) $agent->id );
                        $results[] = array(
                                'agent_id'   => (int) $agent->id,
                                'agent_name' => (string) $agent->name,
                                'success'    => ! is_wp_error( $res ),
                                'result'     => is_wp_error( $res ) ? $res->get_error_message() : $res,
                        );
                }

                $ok = 0;
                foreach ( $results as $r ) {
                        if ( ! empty( $r['success'] ) ) {
                                $ok++;
                        }
                }

                return rest_ensure_response(
                        array(
                                'success'      => count( $results ) === $ok,
                                'method'       => $method,
                                'agents'       => count( $results ),
                                'successful'   => $ok,
                                'results'      => $results,
                        )
                );
        }

        /**
         * Sanitizza e filtra il body PUT /settings con whitelist chiavi.
         *
         * @param array $body Body JSON decodificato.
         * @return array|WP_Error Payload pulito (solo chiavi ammesse presenti).
         */
        private static function sanitize_settings_payload( $body ) {
                $map = array(
                        'mode'                 => 'sanitize_key',
                        'auto_collect'         => 'absint',
                        'auto_evaluate'        => 'absint',
                        'auto_rewrite'         => 'absint',
                        'auto_publish'         => 'absint',
                        'collect_interval'     => 'absint',
                        'max_articles_per_day' => 'absint',
                        'social_platforms'     => 'sanitize_text_field',
                        'site_name'            => 'sanitize_text_field',
                        'site_tagline'         => 'sanitize_text_field',
                        'ai_api_key'           => 'sanitize_text_field',
                        'ai_base_url'          => 'esc_url_raw',
                        'ai_model'             => 'sanitize_text_field',
                        'auto_approve_score'   => 'absint',
                        'enable_wp_posts'      => 'absint',
                        'post_status'          => 'sanitize_key',
                        'enable_ai_images'     => 'absint',
                        'image_model'          => 'sanitize_text_field',
                        'image_size'           => 'sanitize_text_field',
                        'enable_seo'           => 'absint',
                        'seo_generate'         => 'absint',
                        // Distribuzione sociale (v1.0.2).
                        'enable_telegram'      => 'absint',
                        'telegram_bot_token'   => 'sanitize_text_field',
                        'telegram_chat_id'     => 'sanitize_text_field',
                        'enable_discord'       => 'absint',
                        'discord_webhook_url'  => 'sanitize_text_field',
                        'enable_x'             => 'absint',
                        'x_api_key'            => 'sanitize_text_field',
                        'x_api_secret'         => 'sanitize_text_field',
                        'x_access_token'       => 'sanitize_text_field',
                        'x_access_secret'      => 'sanitize_text_field',
                        'enable_linkedin'      => 'absint',
                        'linkedin_access_token' => 'sanitize_text_field',
                        'linkedin_author_urn'  => 'sanitize_text_field',
                        // Newsletter (v1.0.2).
                        'enable_newsletter'    => 'absint',
                        'newsletter_recipients' => 'sanitize_text_field',
                        'enable_digest'        => 'absint',
                        // Indicizzazione (v1.0.2).
                        'enable_indexnow'      => 'absint',
                        'indexnow_key'         => 'sanitize_text_field',
                        'enable_websub'        => 'absint',
                        // Redazione intelligente (v1.0.3).
                        'ai_model_cheap'       => 'sanitize_text_field',
                        'enable_factcheck'     => 'absint',
                        'factcheck_min_score'  => 'absint',
                        'monthly_budget_usd'   => 'sanitize_text_field',
                        'budget_action'        => 'sanitize_key',
                        'enable_translations'  => 'absint',
                        'translation_langs'    => 'sanitize_text_field',
                );

                $out = array();
                foreach ( $map as $key => $callback ) {
                        if ( ! array_key_exists( $key, $body ) || is_array( $body[ $key ] ) || is_object( $body[ $key ] ) ) {
                                continue;
                        }
                        $out[ $key ] = call_user_func( $callback, (string) $body[ $key ] );
                }

                // Normalizza i flag booleani a 0/1.
                foreach ( array(
                        'auto_collect',
                        'auto_evaluate',
                        'auto_rewrite',
                        'auto_publish',
                        'enable_wp_posts',
                        'enable_ai_images',
                        'enable_seo',
                        'seo_generate',
                        'enable_telegram',
                        'enable_discord',
                        'enable_x',
                        'enable_linkedin',
                        'enable_newsletter',
                        'enable_digest',
                        'enable_indexnow',
                        'enable_websub',
                        'enable_factcheck',
                        'enable_translations',
                ) as $flag ) {
                        if ( isset( $out[ $flag ] ) ) {
                                $out[ $flag ] = $out[ $flag ] ? 1 : 0;
                        }
                }

                // Chiave IndexNow: solo caratteri alfanumerici (formato richiesto dal protocollo).
                if ( isset( $out['indexnow_key'] ) ) {
                        $out['indexnow_key'] = (string) preg_replace( '/[^a-zA-Z0-9]/', '', $out['indexnow_key'] );
                }

                // Budget mensile: float >= 0 (v1.0.3).
                if ( isset( $out['monthly_budget_usd'] ) ) {
                        $out['monthly_budget_usd'] = max( 0, (float) $out['monthly_budget_usd'] );
                }

                // Azione budget: stop | warn (v1.0.3).
                if ( isset( $out['budget_action'] ) && ! in_array( $out['budget_action'], array( 'stop', 'warn' ), true ) ) {
                        return self::err(
                                'nexus_rest_invalid_budget_action',
                                __( 'Azione budget non valida. Valori ammessi: stop, warn.', 'nexus-news-ai' ),
                                400
                        );
                }

                // Soglia fact-check: 0-100 (v1.0.3).
                if ( isset( $out['factcheck_min_score'] ) ) {
                        $out['factcheck_min_score'] = min( 100, absint( $out['factcheck_min_score'] ) );
                }

                // Valida la modalità di autonomia.
                if ( isset( $out['mode'] ) && ! in_array( $out['mode'], array( 'semi_autonomous', 'fully_autonomous' ), true ) ) {
                        return self::err(
                                'nexus_rest_invalid_mode',
                                __( 'Modalità non valida. Valori ammessi: semi_autonomous, fully_autonomous.', 'nexus-news-ai' ),
                                400
                        );
                }

                // Valida lo stato dei post WordPress creati dal publisher.
                if ( isset( $out['post_status'] ) && ! in_array( $out['post_status'], array( 'publish', 'draft' ), true ) ) {
                        return self::err(
                                'nexus_rest_invalid_post_status',
                                __( 'Stato post non valido. Valori ammessi: publish, draft.', 'nexus-news-ai' ),
                                400
                        );
                }

                // Valida la dimensione richiesta per le immagini AI.
                if ( isset( $out['image_size'] ) && ! in_array( $out['image_size'], array( '1024x1024', '1792x1024', '1024x1792' ), true ) ) {
                        return self::err(
                                'nexus_rest_invalid_image_size',
                                __( 'Dimensione immagini non valida. Valori ammessi: 1024x1024, 1792x1024, 1024x1792.', 'nexus-news-ai' ),
                                400
                        );
                }

                // Punteggio auto-approvazione nell'intervallo 0-100.
                if ( isset( $out['auto_approve_score'] ) ) {
                        $out['auto_approve_score'] = min( 100, max( 0, (int) $out['auto_approve_score'] ) );
                }

                return $out;
        }

        /**
         * Serializza un agente per la risposta JSON.
         *
         * @param object $agent Riga agente.
         * @return array
         */
        private static function serialize_agent( $agent ) {
                if ( ! $agent ) {
                        return array();
                }
                return array(
                        'id'          => (int) $agent->id,
                        'name'        => (string) $agent->name,
                        'avatar'      => (string) $agent->avatar,
                        'category'    => (string) $agent->category,
                        'description' => (string) $agent->description,
                        'status'      => (string) $agent->status,
                        'personality' => (string) $agent->personality,
                        'ai_model'    => (string) ( $agent->ai_model ?? '' ),
                        'last_run'    => (string) ( $agent->last_run ?? '' ),
                        'created_at'  => (string) $agent->created_at,
                        'updated_at'  => (string) $agent->updated_at,
                );
        }

        /**
         * Permalink del post WordPress collegato a un articolo Nexus (se esiste).
         *
         * @param int $wp_post_id ID del post WP.
         * @return string Permalink sicuro o stringa vuota.
         */
        private static function wp_post_permalink( $wp_post_id ) {
                if ( $wp_post_id <= 0 || ! function_exists( 'get_permalink' ) ) {
                        return '';
                }
                $post = get_post( $wp_post_id );
                if ( ! $post instanceof WP_Post ) {
                        return '';
                }
                $link = get_permalink( $post );
                return is_string( $link ) && '' !== $link ? esc_url_raw( $link ) : '';
        }

        /**
         * Serializza un articolo per la risposta JSON.
         *
         * Aggiunge agent_name, agent_avatar, reactions (JSON decodificato),
         * source_url, excerpt (wp_trim_words 30 parole su summary o content),
         * wp_post_id e post_permalink (v1.0.1, post WordPress reale).
         *
         * @param object $a             Riga articolo.
         * @param bool   $with_content  Includere il contenuto completo (default true).
         * @return array
         */
        private static function serialize_article( $a, $with_content = true ) {
                $agent = self::get_agent_cached( (int) $a->agent_id );

                $summary = trim( (string) ( $a->summary ?? '' ) );
                $base    = '' !== $summary ? $summary : (string) ( $a->content ?? '' );
                $excerpt = wp_trim_words( wp_strip_all_tags( $base ), 30, '…' );

                $reactions = json_decode( (string) ( $a->reactions ?? '' ), true );

                $out = array(
                        'id'            => (int) $a->id,
                        'title'         => (string) $a->title,
                        'subtitle'      => (string) ( $a->subtitle ?? '' ),
                        'summary'       => (string) ( $a->summary ?? '' ),
                        'category'      => (string) $a->category,
                        'agent_id'      => (int) $a->agent_id,
                        'agent_name'    => $agent ? (string) $agent->name : '',
                        'agent_avatar'  => $agent ? (string) $agent->avatar : '',
                        'source_name'   => (string) ( $a->source_name ?? '' ),
                        'source_url'    => esc_url_raw( (string) ( $a->source_url ?? '' ) ),
                        'image_url'     => esc_url_raw( (string) ( $a->image_url ?? '' ) ),
                        'wp_post_id'    => (int) ( $a->wp_post_id ?? 0 ),
                        'post_permalink' => self::wp_post_permalink( (int) ( $a->wp_post_id ?? 0 ) ),
                        'factcheck_score' => (float) ( $a->factcheck_score ?? 0 ),
                        'citations'     => (string) ( $a->citations ?? '' ),
                        'status'        => (string) $a->status,
                        'quality_score' => (float) $a->quality_score,
                        'read_time'     => (int) $a->read_time,
                        'views'         => (int) $a->views,
                        'reactions'     => is_array( $reactions ) ? $reactions : array(),
                        'excerpt'       => $excerpt,
                        'published_at'  => (string) ( $a->published_at ?? '' ),
                        'created_at'    => (string) ( $a->created_at ?? '' ),
                        'updated_at'    => (string) ( $a->updated_at ?? '' ),
                );

                if ( $with_content ) {
                        $out['content'] = (string) ( $a->content ?? '' );
                }

                return $out;
        }

        /**
         * Serializza un articolo per la lista pubblica: mai il contenuto completo.
         *
         * @param object $a Riga articolo.
         * @return array
         */
        private static function serialize_public_article( $a ) {
                return self::serialize_article( $a, false );
        }

        /**
         * Serializza un publish log per la risposta JSON.
         *
         * @param object $log Riga publish log.
         * @return array
         */
        private static function serialize_publish_log( $log ) {
                return array(
                        'id'           => (int) $log->id,
                        'article_id'   => (int) $log->article_id,
                        'platform'     => (string) $log->platform,
                        'status'       => (string) $log->status,
                        'post_id'      => (string) ( $log->post_id ?? '' ),
                        'post_url'     => esc_url_raw( (string) ( $log->post_url ?? '' ) ),
                        'error'        => (string) ( $log->error ?? '' ),
                        'published_at' => (string) ( $log->published_at ?? '' ),
                        'created_at'   => (string) ( $log->created_at ?? '' ),
                );
        }

        /**
         * Serializza un log attività per la risposta JSON.
         *
         * @param object $row Riga activity log.
         * @return array
         */
        private static function serialize_activity( $row ) {
                $agent = self::get_agent_cached( (int) $row->agent_id );
                return array(
                        'id'         => (int) $row->id,
                        'agent_id'   => (int) $row->agent_id,
                        'agent_name' => $agent ? (string) $agent->name : '',
                        'action'     => (string) $row->action,
                        'detail'     => (string) ( $row->detail ?? '' ),
                        'status'     => (string) $row->status,
                        'created_at' => (string) ( $row->created_at ?? '' ),
                );
        }

        /**
         * get_agent() con cache per request (evita query ripetute in una lista).
         *
         * @param int $id ID agente.
         * @return object|null
         */
        private static function get_agent_cached( $id ) {
                if ( $id <= 0 ) {
                        return null;
                }
                if ( ! array_key_exists( $id, self::$agent_cache ) ) {
                        self::$agent_cache[ $id ] = Nexus_DB::get_agent( $id );
                }
                return self::$agent_cache[ $id ];
        }

        /**
         * Serializza un lavoro della coda per la risposta JSON (v1.0.4).
         *
         * args è decodificato dal JSON (array, fallback array vuoto);
         * date e last_error null diventano stringa vuota.
         *
         * @param object $job Riga job.
         * @return array
         */
        private static function serialize_job( $job ) {
                if ( ! $job ) {
                        return array();
                }

                $args = json_decode( (string) ( $job->args ?? '' ), true );

                return array(
                        'id'           => (int) $job->id,
                        'hook'         => (string) $job->hook,
                        'args'         => is_array( $args ) ? $args : array(),
                        'status'       => (string) $job->status,
                        'priority'     => (int) $job->priority,
                        'attempts'     => (int) $job->attempts,
                        'max_attempts' => (int) $job->max_attempts,
                        'scheduled_at' => (string) ( $job->scheduled_at ?? '' ),
                        'started_at'   => (string) ( $job->started_at ?? '' ),
                        'completed_at' => (string) ( $job->completed_at ?? '' ),
                        'last_error'   => (string) ( $job->last_error ?? '' ),
                        'created_at'   => (string) ( $job->created_at ?? '' ),
                        'updated_at'   => (string) ( $job->updated_at ?? '' ),
                );
        }
}
