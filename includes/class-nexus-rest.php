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
 *   GET    /nexus/v1/export, POST /nexus/v1/import (config: impostazioni + agenti) (v1.0.5)
 *   POST   /nexus/v1/tools/api-key (v1.0.5) — ATTENZIONE: /export include segreti (chiavi API/token)
 *   GET    /nexus/v1/wp/categories                 categorie WP native {categories: [{id, name, slug, count}]} per nome (v1.1.0)
 *   POST   /nexus/v1/native/migrate                body {limit} opzionale, default 20, clamp 1-100, risposta shape migrate_status (v1.1.0)
 *   GET    /nexus/v1/native/migrate/status         stato migrazione (shape migrate_status) (v1.1.0)
 *   POST   /nexus/v1/native/migrate/reset          uso amministrativo: reset stato e ritorna status (v1.1.0)
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

                // v1.0.7: creazione agente (editor completo).
                register_rest_route(
                        self::NAMESPACE_URI,
                        '/agents',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_agent_create' ),
                                        'permission_callback' => $admin_permission,
                                ),
                        )
                );

                // v1.0.7: aggiornamento completo agente (tutti i campi dell'editor).
                register_rest_route(
                        self::NAMESPACE_URI,
                        '/agents/(?P<id>[0-9]+)/update',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::EDITABLE,
                                        'callback'            => array( __CLASS__, 'rest_agent_update' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => $id_arg,
                                ),
                        )
                );

                // v1.0.7: clona un agente esistente (nome e categoria resi univoci).
                register_rest_route(
                        self::NAMESPACE_URI,
                        '/agents/(?P<id>[0-9]+)/clone',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_agent_clone' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => $id_arg,
                                ),
                        )
                );

                // v1.0.7: modelli disponibili dal provider AI (auto-load da chiave API).
                register_rest_route(
                        self::NAMESPACE_URI,
                        '/ai/models',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::READABLE,
                                        'callback'            => array( __CLASS__, 'rest_ai_models' ),
                                        'permission_callback' => $admin_permission,
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

                /* ---------------- ADMIN: STRUMENTI (v1.0.5) ---------------- */

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/export',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::READABLE,
                                        'callback'            => array( __CLASS__, 'rest_export' ),
                                        'permission_callback' => $admin_permission,
                                ),
                        )
                );

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/import',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_import' ),
                                        'permission_callback' => $admin_permission,
                                ),
                        )
                );

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/tools/api-key',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_tools_api_key' ),
                                        'permission_callback' => $admin_permission,
                                ),
                        )
                );

                /* ---------------- ADMIN: NATIVO WP (v1.1.0) ---------------- */

                // v1.1.0: categorie WP native (datalist categoria editor agenti + UI admin).
                register_rest_route(
                        self::NAMESPACE_URI,
                        '/wp/categories',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::READABLE,
                                        'callback'            => array( __CLASS__, 'rest_wp_categories' ),
                                        'permission_callback' => $admin_permission,
                                ),
                        )
                );

                // v1.1.0: migrazione contenuti nativi (batch CPT → post nativi).
                register_rest_route(
                        self::NAMESPACE_URI,
                        '/native/migrate',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_native_migrate_run' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => array(
                                                'limit'             => array(
                                                        'type'              => 'integer',
                                                        'required'          => false,
                                                        'default'           => 20,
                                                        'sanitize_callback' => 'absint',
                                                ),
                                        ),
                                ),
                        )
                );

                // v1.1.0: stato attuale della migrazione (shape migrate_status).
                register_rest_route(
                        self::NAMESPACE_URI,
                        '/native/migrate/status',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::READABLE,
                                        'callback'            => array( __CLASS__, 'rest_native_migrate_status' ),
                                        'permission_callback' => $admin_permission,
                                ),
                        )
                );

                // v1.1.0: reset dello stato di migrazione (uso amministrativo).
                register_rest_route(
                        self::NAMESPACE_URI,
                        '/native/migrate/reset',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_native_migrate_reset' ),
                                        'permission_callback' => $admin_permission,
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
         * CALLBACK — ADMIN: AGENTI v1.0.7 (editor completo, clone, modelli AI)
         * ======================================================================= */

        /**
         * Normalizza i campi dell'editor agente in un array sicuro per il DB.
         * Regole: name max 100, category max 100, ai_model max 100, status in
         * active|paused (error non è impostabile a mano), testi sanitizzati.
         *
         * @param WP_REST_Request $request Request.
         * @return array Campi pronti per insert/update.
         */
        private static function agent_fields_from_request( $request ) {
                $fields = array();

                if ( null !== $request->get_param( 'name' ) ) {
                        $fields['name'] = sanitize_text_field( (string) $request->get_param( 'name' ) );
                }
                if ( null !== $request->get_param( 'avatar' ) ) {
                        $fields['avatar'] = sanitize_text_field( (string) $request->get_param( 'avatar' ) );
                }
                if ( null !== $request->get_param( 'category' ) ) {
                        $fields['category'] = sanitize_text_field( (string) $request->get_param( 'category' ) );
                }
                if ( null !== $request->get_param( 'description' ) ) {
                        $fields['description'] = sanitize_textarea_field( (string) $request->get_param( 'description' ) );
                }
                if ( null !== $request->get_param( 'personality' ) ) {
                        $fields['personality'] = sanitize_textarea_field( (string) $request->get_param( 'personality' ) );
                }
                if ( null !== $request->get_param( 'ai_model' ) ) {
                        $fields['ai_model'] = sanitize_text_field( trim( (string) $request->get_param( 'ai_model' ) ) );
                }
                if ( null !== $request->get_param( 'status' ) ) {
                        $status = sanitize_key( (string) $request->get_param( 'status' ) );
                        $fields['status'] = in_array( $status, array( 'active', 'paused' ), true ) ? $status : 'paused';
                }

                // Limiti di lunghezza coerenti con lo schema (VARCHAR 100).
                foreach ( array( 'name', 'category', 'ai_model' ) as $short ) {
                        if ( isset( $fields[ $short ] ) && mb_strlen( $fields[ $short ] ) > 100 ) {
                                $fields[ $short ] = mb_substr( $fields[ $short ], 0, 100 );
                        }
                }

                return $fields;
        }

        /**
         * POST /agents — crea un nuovo agente (v1.0.7).
         * Richiede name e category; la categoria deve essere unica.
         */
        public static function rest_agent_create( $request ) {
                $fields = self::agent_fields_from_request( $request );

                if ( '' === ( $fields['name'] ?? '' ) ) {
                        return self::err( 'nexus_rest_agent_name_required', __( 'Il nome dell\'agente è obbligatorio.', 'nexus-news-ai' ), 400 );
                }
                if ( '' === ( $fields['category'] ?? '' ) ) {
                        return self::err( 'nexus_rest_agent_category_required', __( 'La categoria dell\'agente è obbligatoria.', 'nexus-news-ai' ), 400 );
                }
                $existing = Nexus_DB::get_agent_by_category( $fields['category'] );
                if ( $existing ) {
                        return self::err(
                                'nexus_rest_agent_category_taken',
                                sprintf( __( 'Categoria già in uso dall\'agente "%s". Scegiline un\'altra.', 'nexus-news-ai' ), $existing->name ),
                                400
                        );
                }

                $fields['status'] = $fields['status'] ?? 'active';
                $new_id           = Nexus_DB::insert_agent( $fields );
                if ( ! $new_id ) {
                        return self::err( 'nexus_rest_agent_create_failed', __( 'Impossibile creare l\'agente (verifica nome e categoria).', 'nexus-news-ai' ), 500 );
                }

                Nexus_DB::insert_activity( $new_id, 'agent_create', sprintf( 'Agente "%s" creato via editor.', $fields['name'] ), 'info' );

                // v1.1.0: sync nativo best-effort (utente bot + categoria WP) subito dopo la
                // scrittura riuscita su tabella, così l'agente nuovo è subito nativo.
                self::native_sync_agent_best_effort( Nexus_DB::get_agent( $new_id ) );

                return rest_ensure_response( self::serialize_agent( Nexus_DB::get_agent( $new_id ) ) );
        }

        /**
         * PUT/POST /agents/{id}/update — aggiornamento completo di un agente (v1.0.7).
         * Tutti i campi sono opzionali: vengono aggiornati solo quelli presenti nel body.
         */
        public static function rest_agent_update( $request ) {
                $id    = absint( $request->get_param( 'id' ) );
                $agent = Nexus_DB::get_agent( $id );
                if ( ! $agent ) {
                        return self::err( 'nexus_rest_agent_not_found', __( 'Agente non trovato.', 'nexus-news-ai' ), 404 );
                }

                $fields = self::agent_fields_from_request( $request );
                if ( ! $fields ) {
                        return self::err( 'nexus_rest_agent_no_fields', __( 'Nessun campo da aggiornare.', 'nexus-news-ai' ), 400 );
                }

                if ( isset( $fields['name'] ) && '' === $fields['name'] ) {
                        return self::err( 'nexus_rest_agent_name_required', __( 'Il nome dell\'agente non può essere vuoto.', 'nexus-news-ai' ), 400 );
                }
                if ( isset( $fields['category'] ) && '' === $fields['category'] ) {
                        return self::err( 'nexus_rest_agent_category_required', __( 'La categoria dell\'agente non può essere vuota.', 'nexus-news-ai' ), 400 );
                }
                if ( isset( $fields['category'] ) ) {
                        $clash = Nexus_DB::get_agent_by_category( $fields['category'] );
                        if ( $clash && (int) $clash->id !== $id ) {
                                return self::err(
                                        'nexus_rest_agent_category_taken',
                                        sprintf( __( 'Categoria già in uso dall\'agente "%s". Scegiline un\'altra.', 'nexus-news-ai' ), $clash->name ),
                                        400
                                );
                        }
                }

                Nexus_DB::update_agent( $id, $fields );
                Nexus_DB::insert_activity( $id, 'agent_update', sprintf( 'Agente "%s" aggiornato via editor (%s).', $fields['name'] ?? $agent->name, implode( ', ', array_keys( $fields ) ) ), 'info' );

                // v1.1.0: sync nativo best-effort dopo la scrittura riuscita su tabella
                // (l'utente bot e la categoria nativa restano allineati all'agente).
                self::native_sync_agent_best_effort( Nexus_DB::get_agent( $id ) );

                return rest_ensure_response( self::serialize_agent( Nexus_DB::get_agent( $id ) ) );
        }

        /**
         * POST /agents/{id}/clone — duplica un agente esistente (v1.0.7).
         * Nome: "{name} (copia)"; categoria: "{category}-copia", poi -copia-2, -3…
         * Il clone nasce in pausa per non partire a sorpresa con la pipeline.
         */
        public static function rest_agent_clone( $request ) {
                $id    = absint( $request->get_param( 'id' ) );
                $agent = Nexus_DB::get_agent( $id );
                if ( ! $agent ) {
                        return self::err( 'nexus_rest_agent_not_found', __( 'Agente non trovato.', 'nexus-news-ai' ), 404 );
                }

                $new_name = mb_substr( trim( (string) $agent->name ) . ' (copia)', 0, 100 );

                $base_cat = trim( (string) $agent->category );
                $suffix_n = 0;
                do {
                        $candidate = 0 === $suffix_n ? $base_cat . '-copia' : $base_cat . '-copia-' . ( $suffix_n + 1 );
                        $candidate = mb_substr( $candidate, 0, 100 );
                        ++$suffix_n;
                } while ( Nexus_DB::get_agent_by_category( $candidate ) && $suffix_n < 50 );

                $new_id = Nexus_DB::insert_agent(
                        array(
                                'name'        => $new_name,
                                'avatar'      => (string) $agent->avatar,
                                'category'    => $candidate,
                                'description' => (string) $agent->description,
                                'status'      => 'paused',
                                'personality' => (string) $agent->personality,
                                'ai_model'    => (string) $agent->ai_model,
                        )
                );
                if ( ! $new_id ) {
                        return self::err( 'nexus_rest_agent_clone_failed', __( 'Impossibile clonare l\'agente.', 'nexus-news-ai' ), 500 );
                }

                Nexus_DB::insert_activity( $new_id, 'agent_clone', sprintf( 'Agente clonato da "%s" (id %d) come "%s".', $agent->name, (int) $agent->id, $new_name ), 'info' );

                // v1.1.0: sync nativo best-effort anche per il clone, subito dopo la
                // scrittura riuscita su tabella (utente bot + categoria WP dedicati).
                self::native_sync_agent_best_effort( Nexus_DB::get_agent( $new_id ) );

                return rest_ensure_response( self::serialize_agent( Nexus_DB::get_agent( $new_id ) ) );
        }

        /**
         * GET /ai/models — elenco modelli disponibili dal provider AI (v1.0.7).
         * Auto-load: chiamata a {ai_base_url}/models con la chiave configurata,
         * risposta normalizzata e cachata in transient per 12 ore (chiave per base URL).
         * Errori onesti: mai modelli inventati.
         */
        public static function rest_ai_models( $request ) {
                unset( $request );

                $settings = Nexus_DB::get_settings();
                $api_key  = isset( $settings['ai_api_key'] ) ? trim( (string) $settings['ai_api_key'] ) : '';
                $base_url = isset( $settings['ai_base_url'] ) ? trim( (string) $settings['ai_base_url'] ) : '';

                if ( '' === $api_key ) {
                        return self::err(
                                'nexus_rest_ai_key_missing',
                                __( 'Chiave API AI non configurata: salva prima la chiave nelle Impostazioni.', 'nexus-news-ai' ),
                                400
                        );
                }
                if ( '' === $base_url ) {
                        $base_url = 'https://openrouter.ai/api/v1';
                }

                $transient = 'nexus_ai_models_' . md5( $base_url );
                $cached    = get_transient( $transient );
                if ( is_array( $cached ) && isset( $cached['models'] ) ) {
                        $cached['cached'] = true;
                        return rest_ensure_response( $cached );
                }

                $endpoint = untrailingslashit( $base_url ) . '/models';
                $response = wp_remote_get(
                        $endpoint,
                        array(
                                'timeout' => 30,
                                'headers' => array(
                                        'Authorization' => 'Bearer ' . $api_key,
                                        'Accept'        => 'application/json',
                                        'HTTP-Referer'  => home_url( '/' ),
                                        'X-Title'       => 'Nexus News AI',
                                ),
                        )
                );

                if ( is_wp_error( $response ) ) {
                        return self::err(
                                'nexus_rest_ai_models_unreachable',
                                __( 'Errore di connessione al provider AI: ', 'nexus-news-ai' ) . $response->get_error_message(),
                                502
                        );
                }

                $code = (int) wp_remote_retrieve_response_code( $response );
                $raw  = (string) wp_remote_retrieve_body( $response );

                if ( 200 !== $code ) {
                        $detail = '';
                        $data   = json_decode( $raw, true );
                        if ( is_array( $data ) ) {
                                foreach ( array( 'message', 'error' ) as $k ) {
                                        if ( ! empty( $data[ $k ] ) && is_string( $data[ $k ] ) ) {
                                                $detail = $data[ $k ];
                                                break;
                                        }
                                }
                        }
                        if ( 401 === $code || 403 === $code ) {
                                return self::err( 'nexus_rest_ai_models_auth', __( 'Chiave API non valida o non autorizzata (HTTP ' . $code . '). Verifica la chiave nelle Impostazioni.', 'nexus-news-ai' ), 502 );
                        }
                        return self::err(
                                'nexus_rest_ai_models_http',
                                __( 'Il provider AI ha risposto con errore HTTP ' . $code . ( '' !== $detail ? ': ' . $detail : '.' ), 'nexus-news-ai' ),
                                502
                        );
                }

                $models = self::normalize_ai_models_body( $raw );
                if ( ! $models ) {
                        return self::err(
                                'nexus_rest_ai_models_unparsable',
                                __( 'Il provider AI non ha restituito un elenco di modelli riconoscibile.', 'nexus-news-ai' ),
                                502
                        );
                }

                $payload = array(
                        'models'    => $models,
                        'base_url'  => $base_url,
                        'cached'    => false,
                        'count'     => count( $models ),
                        'fetched_at'=> current_time( 'mysql' ),
                );
                set_transient( $transient, $payload, 12 * HOUR_IN_SECONDS );

                return rest_ensure_response( $payload );
        }

        /**
         * Estrae e normalizza gli id dei modelli da un body /models.
         * Formati supportati: {data:[{id}]} (OpenAI/OpenRouter), {data:["id"]},
         * {models:[{id|name}]} (alcuni gateway), ["id"].
         *
         * @param string $raw Body JSON.
         * @return array Lista di id (stringhe), ordinata, senza duplicati.
         */
        private static function normalize_ai_models_body( $raw ) {
                $data = json_decode( (string) $raw, true );
                if ( ! is_array( $data ) ) {
                        return array();
                }

                $list = array();
                if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
                        $list = $data['data'];
                } elseif ( isset( $data['models'] ) && is_array( $data['models'] ) ) {
                        $list = $data['models'];
                } else {
                        $list = $data;
                }

                $ids = array();
                foreach ( $list as $item ) {
                        if ( is_string( $item ) ) {
                                $ids[] = trim( $item );
                        } elseif ( is_array( $item ) ) {
                                foreach ( array( 'id', 'name', 'model' ) as $k ) {
                                        if ( ! empty( $item[ $k ] ) && is_string( $item[ $k ] ) ) {
                                                $ids[] = trim( $item[ $k ] );
                                                break;
                                        }
                                }
                        }
                }

                $ids = array_values( array_unique( array_filter( $ids ) ) );
                sort( $ids, SORT_STRING );
                return array_slice( $ids, 0, 500 );
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
                        // Tema UI admin (v1.0.6).
                        'admin_theme'          => 'sanitize_key',
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

                // Tema admin: ammessi solo light | dark (v1.0.6), fallback chiaro.
                if ( isset( $out['admin_theme'] ) && ! in_array( $out['admin_theme'], array( 'light', 'dark' ), true ) ) {
                        $out['admin_theme'] = 'light';
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
                        'wp_user_id'  => absint( $agent->wp_user_id ?? 0 ),
                        'term_id'     => absint( $agent->term_id ?? 0 ),
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

        /* =========================================================================
         * CALLBACK — ADMIN: STRUMENTI (v1.0.5)
         * ======================================================================= */

        /**
         * GET /export — esporta impostazioni e agenti come JSON (v1.0.5).
         *
         * ATTENZIONE: il file esportato contiene segreti (chiavi API e token):
         * conservalo in modo sicuro. Non include articoli, iscritti o log.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_export( $request ) {
                unset( $request );
                if ( ! class_exists( 'Nexus_DB' ) ) {
                        return self::err( 'nexus_not_available', __( 'DB non disponibile.', 'nexus-news-ai' ), 501 );
                }

                $agents = array();
                foreach ( Nexus_DB::agents() as $agent ) {
                        $agents[] = array(
                                'name'        => (string) $agent->name,
                                'avatar'      => (string) $agent->avatar,
                                'category'    => (string) $agent->category,
                                'description' => (string) $agent->description,
                                'status'      => (string) $agent->status,
                                'personality' => (string) $agent->personality,
                                'ai_model'    => (string) ( $agent->ai_model ?? '' ),
                        );
                }

                return rest_ensure_response(
                        array(
                                'version'     => defined( 'NEXUS_VERSION' ) ? NEXUS_VERSION : '',
                                'exported_at' => current_time( 'mysql' ),
                                'site_url'    => home_url( '/' ),
                                'settings'    => Nexus_DB::get_settings(),
                                'agents'      => $agents,
                        )
                );
        }

        /**
         * POST /import — importa impostazioni e agenti da un export JSON (v1.0.5).
         *
         * Body: JSON prodotto da GET /export; "settings" e "agents" sono entrambi
         * opzionali. settings: applicate solo le chiavi ammesse da default_settings();
         * agents: match per categoria ESATTA → update, altrimenti insert.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_import( $request ) {
                if ( ! class_exists( 'Nexus_DB' ) ) {
                        return self::err( 'nexus_not_available', __( 'DB non disponibile.', 'nexus-news-ai' ), 501 );
                }

                $body = $request->get_json_params();
                if ( ! is_array( $body ) ) {
                        return self::err( 'nexus_import_invalid', __( 'Corpo JSON non valido.', 'nexus-news-ai' ), 400 );
                }

                // Validazione: "settings" (se presente) deve essere un array.
                if ( isset( $body['settings'] ) && ! is_array( $body['settings'] ) ) {
                        return self::err( 'nexus_import_invalid_settings', __( 'Il campo "settings" deve essere un array.', 'nexus-news-ai' ), 400 );
                }

                // Validazione: "agents" (se presente) deve essere una lista di array.
                if ( isset( $body['agents'] ) && ! is_array( $body['agents'] ) ) {
                        return self::err( 'nexus_import_invalid_agents', __( 'Il campo "agents" deve essere una lista di array.', 'nexus-news-ai' ), 400 );
                }

                $settings_updated = 0;
                $agents_updated   = 0;
                $agents_inserted  = 0;
                $errors           = array();

                // Impostazioni: whitelist sulle chiavi dei default, poi update.
                if ( ! empty( $body['settings'] ) ) {
                        $keys     = array_keys( Nexus_DB::default_settings() );
                        $filtered = array_intersect_key( $body['settings'], array_flip( $keys ) );

                        if ( $filtered ) {
                                Nexus_DB::update_settings( $filtered );
                        }
                        $settings_updated = count( $filtered );
                }

                // Agenti: match per categoria esatta → update, altrimenti insert.
                $allowed = array( 'name', 'avatar', 'category', 'description', 'status', 'personality', 'ai_model' );
                if ( ! empty( $body['agents'] ) ) {
                        foreach ( array_values( $body['agents'] ) as $agent ) {
                                if ( ! is_array( $agent ) ) {
                                        continue;
                                }

                                $fields = array_intersect_key( $agent, array_flip( $allowed ) );
                                $name   = isset( $agent['name'] ) ? (string) $agent['name'] : '';
                                $cat    = isset( $agent['category'] ) ? (string) $agent['category'] : '';

                                // Cerca un agente esistente con la stessa categoria ESATTA.
                                $existing_id = 0;
                                if ( '' !== $cat ) {
                                        foreach ( Nexus_DB::agents() as $existing ) {
                                                if ( (string) $existing->category === $cat ) {
                                                        $existing_id = (int) $existing->id;
                                                        break;
                                                }
                                        }
                                }

                                if ( $existing_id > 0 ) {
                                        Nexus_DB::update_agent( $existing_id, $fields );
                                        $agents_updated++;
                                        continue;
                                }

                                $inserted = (int) Nexus_DB::insert_agent( $fields );
                                if ( $inserted > 0 ) {
                                        $agents_inserted++;
                                } else {
                                        $errors[] = 'Agente non valido: ' . sanitize_text_field( $name );
                                }
                        }
                }

                return rest_ensure_response(
                        array(
                                'settings_updated' => $settings_updated,
                                'agents_updated'   => $agents_updated,
                                'agents_inserted'  => $agents_inserted,
                                'errors'           => $errors,
                        )
                );
        }

        /**
         * POST /tools/api-key — genera una nuova chiave per l'API pubblica (v1.0.5).
         *
         * La nuova chiave sostituisce la precedente, che diventa invalida.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_tools_api_key( $request ) {
                unset( $request );
                if ( ! class_exists( 'Nexus_DB' ) ) {
                        return self::err( 'nexus_not_available', __( 'DB non disponibile.', 'nexus-news-ai' ), 501 );
                }

                $key = bin2hex( random_bytes( 24 ) );
                Nexus_DB::update_settings( array( 'public_api_key' => $key ) );

                return rest_ensure_response( array( 'api_key' => $key ) );
        }

        /* =========================================================================
         * CALLBACK — ADMIN: NATIVO WP (v1.1.0)
         * ======================================================================= */

        /**
         * Sync nativo best-effort di un agente appena scritto su tabella (v1.1.0).
         *
         * Crea/riallinea utente bot e categoria WP nativa via Nexus_Native, così
         * l'agente nuovo o modificato è subito nativo. Mai fatale: guard
         * class_exists/method_exists, try/catch, log onesto in activity se fallisce.
         *
         * @param object|null $agent_row Riga agente appena scritta su tabella.
         * @return void
         */
        private static function native_sync_agent_best_effort( $agent_row ) {
                if ( ! $agent_row || ! class_exists( 'Nexus_Native' ) ) {
                        return;
                }

                try {
                        if ( method_exists( 'Nexus_Native', 'ensure_bot_user' ) ) {
                                Nexus_Native::ensure_bot_user( $agent_row );
                        }
                        if ( method_exists( 'Nexus_Native', 'sync_agent_term' ) ) {
                                Nexus_Native::sync_agent_term( $agent_row );
                        }
                } catch ( Throwable $e ) {
                        if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'insert_activity' ) && ! empty( $agent_row->id ) ) {
                                Nexus_DB::insert_activity(
                                        (int) $agent_row->id,
                                        'agent_native_sync',
                                        sprintf( 'Sync nativo agente fallito: %s', sanitize_text_field( $e->getMessage() ) ),
                                        'error'
                                );
                        }
                }
        }

        /**
         * GET /wp/categories — categorie WP native (v1.1.0).
         *
         * Lista completa (hide_empty false), ordinata per nome, shape compatta
         * {categories: [{id, name, slug, count}, ...]}: serve al datalist della
         * categoria nell'editor agenti e alla UI admin.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_wp_categories( $request ) {
                unset( $request );

                if ( ! function_exists( 'get_categories' ) ) {
                        return self::err(
                                'nexus_rest_categories_unavailable',
                                __( 'Le categorie WordPress non sono disponibili in questo contesto.', 'nexus-news-ai' ),
                                502
                        );
                }

                $terms = get_categories( array( 'hide_empty' => false ) );
                if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
                        return self::err(
                                'nexus_rest_categories_failed',
                                __( 'Impossibile leggere le categorie WordPress.', 'nexus-news-ai' ),
                                502
                        );
                }

                $categories = array();
                foreach ( $terms as $term ) {
                        if ( ! $term instanceof WP_Term ) {
                                continue;
                        }
                        $categories[] = array(
                                'id'    => (int) $term->term_id,
                                'name'  => (string) $term->name,
                                'slug'  => (string) $term->slug,
                                'count' => (int) $term->count,
                        );
                }

                usort( $categories, array( __CLASS__, 'compare_categories_by_name' ) );

                return rest_ensure_response( array( 'categories' => $categories ) );
        }

        /**
         * Comparatore usort: categorie ordinate per nome (case-insensitive, multibyte).
         *
         * @param array $a Categoria.
         * @param array $b Categoria.
         * @return int
         */
        private static function compare_categories_by_name( $a, $b ) {
                return strcmp( mb_strtolower( (string) $a['name'] ), mb_strtolower( (string) $b['name'] ) );
        }

        /**
         * POST /native/migrate — esegue un batch di migrazione CPT → post nativi (v1.1.0).
         *
         * Body: {limit: int opzionale} — default 20, clamp 1-100 (difensivo: il clamp
         * è ripetuto in Nexus_Native::migrate_run). Risposta: ritorno diretto di
         * migrate_run, shape migrate_status {remaining, done, total, agents, finished, errors}
         * con errori onesti già inclusi dal modulo.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_native_migrate_run( $request ) {
                if ( ! class_exists( 'Nexus_Native' ) || ! method_exists( 'Nexus_Native', 'migrate_run' ) ) {
                        return self::err(
                                'nexus_rest_native_missing',
                                __( 'Il modulo di migrazione nativa non è disponibile: verifica che il plugin sia aggiornato.', 'nexus-news-ai' ),
                                502
                        );
                }

                $limit = absint( $request->get_param( 'limit' ) );
                if ( $limit < 1 ) {
                        $limit = 20; // Default contrattuale (param assente o non valido).
                }
                $limit = max( 1, min( 100, $limit ) );

                $status = Nexus_Native::migrate_run( $limit );
                if ( ! is_array( $status ) ) {
                        return self::err(
                                'nexus_rest_native_migrate_failed',
                                __( 'La migrazione non ha restituito uno stato valido.', 'nexus-news-ai' ),
                                502
                        );
                }

                return rest_ensure_response( $status );
        }

        /**
         * GET /native/migrate/status — stato attuale della migrazione (v1.1.0).
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_native_migrate_status( $request ) {
                unset( $request );

                if ( ! class_exists( 'Nexus_Native' ) || ! method_exists( 'Nexus_Native', 'migrate_status' ) ) {
                        return self::err(
                                'nexus_rest_native_missing',
                                __( 'Il modulo di migrazione nativa non è disponibile: verifica che il plugin sia aggiornato.', 'nexus-news-ai' ),
                                502
                        );
                }

                $status = Nexus_Native::migrate_status();
                return rest_ensure_response( is_array( $status ) ? $status : array() );
        }

        /**
         * POST /native/migrate/reset — reset dello stato di migrazione (v1.1.0).
         *
         * Uso amministrativo: azzera done/total/errors e la sincronizzazione agenti
         * per rieseguire la scansione da zero. Ritorna lo status aggiornato.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_native_migrate_reset( $request ) {
                unset( $request );

                if ( ! class_exists( 'Nexus_Native' ) || ! method_exists( 'Nexus_Native', 'migrate_reset' ) || ! method_exists( 'Nexus_Native', 'migrate_status' ) ) {
                        return self::err(
                                'nexus_rest_native_missing',
                                __( 'Il modulo di migrazione nativa non è disponibile: verifica che il plugin sia aggiornato.', 'nexus-news-ai' ),
                                502
                        );
                }

                Nexus_Native::migrate_reset();
                $status = Nexus_Native::migrate_status();
                return rest_ensure_response( is_array( $status ) ? $status : array() );
        }
}
