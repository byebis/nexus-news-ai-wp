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
 *   POST   /nexus/v1/pipeline/run                  body {agent_id, redazione_id, agent_ids[]} (v1.1.5: avvii mirati, scope agent_ids > redazione_id > agent_id, risposta {ok, scope, results[], summary} + chiavi BC al top-level per run di un agente singolo)
 *   POST   /nexus/v1/pipeline/publish              body {article_id}
 *   GET    /nexus/v1/settings
 *   PUT    /nexus/v1/settings                      body JSON parziale
 *   GET    /nexus/v1/activity                      args: limit, agent_id
 *   GET    /nexus/v1/stats
 *   GET    /nexus/v1/live                          stato live della redazione {updated_at, agents[], redazioni[], testata} per il pannello "In redazione ora" (v1.1.5, pensato per polling ~5s)
 *   POST   /nexus/v1/wire/run                      body {topic} (obbligatorio, max 200 char)
 *   GET    /nexus/v1/wire/runs                     args: limit
 *   GET    /nexus/v1/jobs, /nexus/v1/jobs/stats; POST /nexus/v1/jobs/process, /nexus/v1/jobs/cleanup, /nexus/v1/jobs/{id}/retry, /nexus/v1/jobs/{id}/cancel (v1.0.4)
 *   GET    /nexus/v1/analytics                     args: days (7..90) (v1.0.4)
 *   GET    /nexus/v1/export, POST /nexus/v1/import (config: impostazioni + agenti) (v1.0.5)
 *   POST   /nexus/v1/tools/api-key (v1.0.5; v1.1.9: salva l'hash SHA-256 della chiave,
 *          la risposta porta il valore in chiaro UNA SOLA VOLTA in 'api_key_plain').
 *          NOTA v1.1.9: /export NON include più segreti (mascherati da D2/WP2).
 *   GET    /nexus/v1/wp/categories                 categorie WP native {categories: [{id, name, slug, count}]} per nome (v1.1.0)
 *   POST   /nexus/v1/native/migrate                body {limit} opzionale, default 20, clamp 1-100, risposta shape migrate_status (v1.1.0)
 *   GET    /nexus/v1/native/migrate/status         stato migrazione (shape migrate_status) (v1.1.0)
 *   POST   /nexus/v1/native/migrate/reset          uso amministrativo: reset stato e ritorna status (v1.1.0)
 *   GET    /nexus/v1/redazioni                     albero redazioni {redazioni: [{id, name, slug, parent, depth, agents, count, count_subtree}]} (v1.1.1)
 *   POST   /nexus/v1/redazioni                     body {name, parent, agent_ids[]} crea, risposta {term_id, agents} (v1.1.1)
 *   POST   /nexus/v1/redazioni/{id}                body {name, slug, parent, agent_ids} parziali, risposta {ok, term} (v1.1.1)
 *   DELETE /nexus/v1/redazioni/{id}                risposta {ok, message}: WP riassegna le sub-redazioni alla madre (v1.1.1)
 *   GET    /nexus/v1/multilang/info                 driver attivo e lingue del sito {driver, default, current, langs} (v1.1.2)
 *   POST   /nexus/v1/multilang/translate-post       body {article_id, lang}: pubblica la traduzione come post WP {ok, already, lang, post_id, edit_url, permalink} (v1.1.2)
 *   GET    /nexus/v1/multilang/translations-posts   args: article_id. Traduzioni native {article_id, source_post_id, source_lang, note, translations} (v1.1.2)
 *   GET    /nexus/v1/tools/catalog                  catalogo strumenti di ricerca e provider AI per agente (v1.1.4)
 *   POST   /nexus/v1/tools/test                     body {tool: search|read|feeds|mcp, query|url|server|tool_name|args}: test strumento dalla UI impostazioni (v1.1.4)
 *   POST   /nexus/v1/sources/test                   body {type, url, query?, redazione_id?}: test UNA fonte della redazione (8 tipi) invocando l'adapter Nexus_Sources::fetch_{type} (via ReflectionMethod, v1.1.10 WP8); risposta SEMPRE 200 {ok, items_found, sample[], source_type, redazione_id?, error?} con ok:false sull'errore (stesso pattern /tools/test e /ai/test)
 *   POST   /nexus/v1/ai/oauth/start                 body {provider}: avvia il login web OAuth PKCE (solo provider con oauth=true nel catalog), risposta {ok, auth_url} (v1.1.10)
 *   POST   /nexus/v1/ai/oauth/callback              body {state, code}: scambia il codice con la chiave di provisioning SALVATA server-side, risposta {ok} — MAI la chiave (v1.1.10)
 *   POST   /nexus/v1/ai/disconnect/{provider}       svuota prov_key_{provider} (cancellazione VOLUTA, log 'oauth_disconnected'), risposta {ok, provider} (v1.1.10)
 *   POST   /nexus/v1/ai/test/{provider}             body {base_url?} (solo custom): test connessione vivo (GET /models con models_auth del catalog + guardia SSRF G4 + account OpenRouter), risposta 200 {ok, models_count, sample, latency_ms, account?} | {ok:false, error} (v1.1.10) — dalla v1.1.10 (F2b) funziona anche per i provider personalizzati cust_* (endpoint e chiave DALL'ENTRY, body base_url ignorato)
 *   GET    /nexus/v1/ai/custom-providers           elenco provider personalizzati {id, label, base_url, auth, has_key}: api_key MAI in chiaro (v1.1.10, F2b)
 *   POST   /nexus/v1/ai/custom-providers           body {id?, label, base_url, api_key?}: upsert (id assente = nuovo 'cust_'+8hex tornato nella risposta; api_key vuota su update NON cancella — pattern segreti), risposta {ok, id, provider} (v1.1.10, F2b)
 *   DELETE /nexus/v1/ai/custom-providers/{id}      rimozione definitiva (gli agenti che lo usavano ricevono errore onesto, MAI fallback legacy), risposta {ok, id} (v1.1.10, F2b)
 *   POST   /nexus/v1/chief/run/{id}                 forza la revisione del capo redattore su un articolo, risposta {ok, chief_status, note} (v1.1.4)
 *
 * ROUTE PUBBLICHE (permission_callback: __return_true, dati SOLO pubblicabili):
 *   GET    /nexus/v1/public/articles               args: category, search, page, per_page (max 24)
 *   GET    /nexus/v1/public/articles/{id}          solo status published
 *   POST   /nexus/v1/public/articles/{id}/reaction body {emoji}
 *   POST   /nexus/v1/public/articles/{id}/view
 *   GET    /nexus/v1/public/wire/runs              solo run completati, senza dati interni
 *   GET    /nexus/v1/health
 *
 * SHAPE ARTICOLI (serialize_article): ogni endpoint che restituisce articoli
 * (admin e public) usa la stessa shape: oltre a wp_post_id e post_permalink
 * (v1.0.1) dalla v1.1.3 include sempre anche wp_post_status, wp_edit_url e
 * wp_preview_url (stringa vuota quando il dato non è disponibile); dalla
 * v1.1.4 include sempre anche i campi capo redattore chief_status,
 * chief_note, chief_reviewed_at, chief_agent, chief_agent_name e
 * chief_rounds (stringa vuota o 0 quando il capo redattore non ha ancora
 * revisionato l'articolo).
 *
 * SHAPE AGENTI (serialize_agent): dalla v1.1.4 ogni endpoint che restituisce
 * agenti (lista, status, model, create, update, clone) include sempre anche
 * ai_provider ('' = provider globale), role ('giornalista' default),
 * chief_scope ('' | global | category | term) e chief_term_id (int).
 *
 * SHAPE DATE (v1.1.6, regola ZERO MOCK): nessun serializzatore emette la data
 * zero MySQL ('0000-00-00 00:00:00') o stringhe vuote come date: date_or_empty()
 * le converte in '' (la UI mostrerà vuoto/—, mai una data finta). Le date valide
 * passano intatte: nessun cambio di shape per i consumatori.
 *
 * HARDENING v1.1.9 (WP2):
 * - Le rotte PUBBLICHE degli articoli usano serialize_public_article(): mai il
 *   contenuto interno del capo redattore (chief_status/note/agent_name/rounds);
 *   citations resta (fonti verificate, contenuto pubblico).
 * - view/reaction: permission callback che verifica il nonce wp_rest QUANDO
 *   l'header X-WP-Nonce è presente, rate limit transient per IP+articolo
 *   (1 vista/60s, max 10 reazioni/min) e whitelist emoji (allineata a
 *   Nexus_Utils::reaction_emojis).
 * - GET /settings, PUT /settings (risposta), GET /export: SEGRETI mascherati
 *   ('' + 'x_<chiave>_set'); PUT e import salvano i segreti SOLO se valorizzati.
 * - API Pubblica (D6): public_api_key NON è più salvata in chiaro — se il valore
 *   fornito (PUT /settings o keygen) è non vuoto e non è già hex-64 viene salvato
 *   hash('sha256', valore) (MIGRAZIONE SOFT: le chiavi legacy in chiaro restano
 *   valide finché non vengono risalvate; l'upgrade-on-use è in Nexus_Public_API).
 *   Il keygen restituisce il valore in chiaro UNA SOLA VOLTA ('api_key_plain').
 * - Mutazioni pubbliche view/reaction: le risposte 403/429 portano l'header CORS
 *   già usato dall'API pubblica (Access-Control-Allow-Origin: *, stesso ACAO delle
 *   risposte 200 di nexus-public/v1) e il 429 porta Retry-After con i secondi
 *   REALI rimasti della finestra del rate limiter.
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Rest {

        const NAMESPACE_URI = 'nexus/v1';

        /**
         * Età massima (secondi) di uno stato runtime considerato valido
         * dall'endpoint /live (v1.1.5): oltre questa soglia lo stato è un
         * residuo (crash, job di coda finiti senza clear) e l'agente viene
         * mostrato come non in esecuzione. 30 minuti: un run reale non ci
         * mette così tanto e le fasi aggiornano lo stato a ogni passo.
         */
        const LIVE_RUNTIME_TTL = 1800;

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

                // v1.1.7: eliminazione agente (contenuto preservato, riferimenti puliti).
                register_rest_route(
                        self::NAMESPACE_URI,
                        '/agents/(?P<id>[0-9]+)',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::DELETABLE,
                                        'callback'            => array( __CLASS__, 'rest_agent_delete' ),
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

                // v1.1.10 (WP10): login web OAuth PKCE per i provider con
                // 'oauth' => true nel catalog (oggi OpenRouter). start ritorna
                // l'URL di autorizzazione; callback scambia state+code con la
                // chiave di provisioning SALVATA server-side (mai in risposta).
                register_rest_route(
                        self::NAMESPACE_URI,
                        '/ai/oauth/start',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_ai_oauth_start' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => array(
                                                'provider'          => array(
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
                        '/ai/oauth/callback',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_ai_oauth_callback' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => array(
                                                'state'             => array(
                                                        'type'              => 'string',
                                                        'required'          => true,
                                                        'sanitize_callback' => 'sanitize_text_field',
                                                ),
                                                'code'              => array(
                                                        'type'              => 'string',
                                                        'required'          => true,
                                                        'sanitize_callback' => 'sanitize_text_field',
                                                ),
                                        ),
                                ),
                        )
                );

                // v1.1.10 (WP10): scollega un provider (svuota prov_key_{provider}).
                register_rest_route(
                        self::NAMESPACE_URI,
                        '/ai/disconnect/(?P<provider>[a-z0-9_-]+)',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_ai_disconnect' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => array(
                                                'provider'          => array(
                                                        'type'              => 'string',
                                                        'required'          => true,
                                                        'sanitize_callback' => 'sanitize_key',
                                                ),
                                        ),
                                ),
                        )
                );

                // v1.1.10 (WP10): test connessione VIVO del provider (GET /models con
                // l'auth giusta + info account per OpenRouter). Risposta SEMPRE 200
                // con {ok, ...}: l'esito del test è un dato, non un errore HTTP.
                register_rest_route(
                        self::NAMESPACE_URI,
                        '/ai/test/(?P<provider>[a-z0-9_-]+)',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_ai_test' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => array(
                                                'provider'          => array(
                                                        'type'              => 'string',
                                                        'required'          => true,
                                                        'sanitize_callback' => 'sanitize_key',
                                                ),
                                                'base_url'          => array(
                                                        'type'              => 'string',
                                                        'required'          => false,
                                                        'default'           => '',
                                                        'sanitize_callback' => 'esc_url_raw',
                                                ),
                                        ),
                                ),
                        )
                );

                /* ---------------- ADMIN: PROVIDER PERSONALIZZATI (F2b/WP11) ---------------- */

                // v1.1.10 (F2b/WP11): provider personalizzati ILLIMITATI (option
                // 'nexus_custom_providers', id 'cust_' + 8 hex, qualsiasi servizio
                // compatibile OpenAI). CRUD admin-only; la chiave API NON è MAI
                // restituita (api_key sempre '' + has_key, pattern segreti D2/WP2).
                register_rest_route(
                        self::NAMESPACE_URI,
                        '/ai/custom-providers',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::READABLE,
                                        'callback'            => array( __CLASS__, 'rest_custom_providers_list' ),
                                        'permission_callback' => $admin_permission,
                                ),
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_custom_providers_upsert' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => array(
                                                'id'                => array(
                                                        'type'              => 'string',
                                                        'required'          => false,
                                                        'default'           => '',
                                                        'sanitize_callback' => 'sanitize_key',
                                                ),
                                                'label'             => array(
                                                        'type'              => 'string',
                                                        'required'          => false,
                                                        'default'           => '',
                                                        'sanitize_callback' => 'sanitize_text_field',
                                                ),
                                                'base_url'          => array(
                                                        'type'              => 'string',
                                                        'required'          => false,
                                                        'default'           => '',
                                                        'sanitize_callback' => 'esc_url_raw',
                                                ),
                                                'api_key'           => array(
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
                        '/ai/custom-providers/(?P<id>cust_[a-z0-9]+)',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::DELETABLE,
                                        'callback'            => array( __CLASS__, 'rest_custom_providers_delete' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => array(
                                                'id'                => array(
                                                        'type'              => 'string',
                                                        'required'          => true,
                                                        'sanitize_callback' => 'sanitize_key',
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
                                                // v1.1.5: avvio mirato per redazione (subtree incluso).
                                                'redazione_id'      => array(
                                                        'type'              => 'integer',
                                                        'required'          => false,
                                                        'default'           => 0,
                                                        'sanitize_callback' => 'absint',
                                                ),
                                                // v1.1.5: avvio mirato su una lista esplicita di agenti.
                                                'agent_ids'         => array(
                                                        'type'              => 'array',
                                                        'required'          => false,
                                                        'default'           => array(),
                                                        'sanitize_callback' => array( __CLASS__, 'sanitize_redazione_agent_ids' ),
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

                // v1.1.5: stato live della redazione (pannello "In redazione ora",
                // polling ~5s dalla dashboard: solo query raggruppate, niente N+1).
                register_rest_route(
                        self::NAMESPACE_URI,
                        '/live',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::READABLE,
                                        'callback'            => array( __CLASS__, 'rest_live' ),
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
                                        // v1.1.9: permission callback che verifica il nonce wp_rest
                                        // QUANDO l'header X-WP-Nonce è presente + rate limit nel callback.
                                        'permission_callback' => array( __CLASS__, 'perm_public_mutation' ),
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
                                        // v1.1.9: idem reaction (nonce se presente + rate limit nel callback).
                                        'permission_callback' => array( __CLASS__, 'perm_public_mutation' ),
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

                /* ---------------- ADMIN: REDAZIONI (v1.1.1) ---------------- */

                // v1.1.1: albero redazioni per la UI admin (lettura) + creazione redazione.
                register_rest_route(
                        self::NAMESPACE_URI,
                        '/redazioni',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::READABLE,
                                        'callback'            => array( __CLASS__, 'rest_redazioni_list' ),
                                        'permission_callback' => $admin_permission,
                                ),
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_redazioni_create' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => array(
                                                'name'              => array(
                                                        'type'              => 'string',
                                                        'required'          => true,
                                                        'sanitize_callback' => 'sanitize_text_field',
                                                ),
                                                'parent'            => array(
                                                        'type'              => 'integer',
                                                        'required'          => false,
                                                        'default'           => 0,
                                                        'sanitize_callback' => 'absint',
                                                ),
                                                'agent_ids'         => array(
                                                        'type'              => 'array',
                                                        'required'          => false,
                                                        'default'           => array(),
                                                        'sanitize_callback' => array( __CLASS__, 'sanitize_redazione_agent_ids' ),
                                                ),
                                        ),
                                ),
                        )
                );

                // v1.1.1: aggiornamento redazione (nome, slug, madre, agenti) ed eliminazione.
                register_rest_route(
                        self::NAMESPACE_URI,
                        '/redazioni/(?P<id>[0-9]+)',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_redazioni_update' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => array(
                                                'id'                => array(
                                                        'type'              => 'integer',
                                                        'required'          => true,
                                                        'sanitize_callback' => 'absint',
                                                ),
                                                'name'              => array(
                                                        'type'              => 'string',
                                                        'required'          => false,
                                                        'sanitize_callback' => 'sanitize_text_field',
                                                ),
                                                'slug'              => array(
                                                        'type'              => 'string',
                                                        'required'          => false,
                                                        'sanitize_callback' => array( __CLASS__, 'sanitize_slug_param' ),
                                                ),
                                                'parent'            => array(
                                                        'type'              => 'integer',
                                                        'required'          => false,
                                                        'sanitize_callback' => 'absint',
                                                ),
                                                'agent_ids'         => array(
                                                        'type'              => 'array',
                                                        'required'          => false,
                                                        'sanitize_callback' => array( __CLASS__, 'sanitize_redazione_agent_ids' ),
                                                ),
                                        ),
                                ),
                                array(
                                        'methods'             => WP_REST_Server::DELETABLE,
                                        'callback'            => array( __CLASS__, 'rest_redazioni_delete' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => $id_arg,
                                ),
                        )
                );

                /* ---------------- ADMIN: CAPO REDATTORE + STRUMENTI DI RICERCA (v1.1.4) ---------------- */

                // v1.1.4: catalogo strumenti di ricerca e provider AI (editor agenti + impostazioni).
                register_rest_route(
                        self::NAMESPACE_URI,
                        '/tools/catalog',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::READABLE,
                                        'callback'            => array( __CLASS__, 'rest_tools_catalog' ),
                                        'permission_callback' => $admin_permission,
                                ),
                        )
                );

                // v1.1.4: test di uno strumento di ricerca (solo dalla UI impostazioni, nessuna scrittura).
                register_rest_route(
                        self::NAMESPACE_URI,
                        '/tools/test',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_tools_test' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => array(
                                                'tool'      => array(
                                                        'type'              => 'string',
                                                        'required'          => true,
                                                        'sanitize_callback' => 'sanitize_key',
                                                ),
                                                'query'     => array(
                                                        'type'              => 'string',
                                                        'required'          => false,
                                                        'sanitize_callback' => 'sanitize_text_field',
                                                ),
                                                'url'       => array(
                                                        'type'              => 'string',
                                                        'required'          => false,
                                                        'sanitize_callback' => 'esc_url_raw',
                                                ),
                                                'max'       => array(
                                                        'type'              => 'integer',
                                                        'required'          => false,
                                                        'default'           => 0,
                                                        'sanitize_callback' => 'absint',
                                                ),
                                                'server'    => array(
                                                        'type'              => 'string',
                                                        'required'          => false,
                                                        'sanitize_callback' => 'sanitize_key',
                                                ),
                                                'tool_name' => array(
                                                        'type'              => 'string',
                                                        'required'          => false,
                                                        'sanitize_callback' => 'sanitize_text_field',
                                                ),
                                                'mcp_tool'  => array(
                                                        'type'              => 'string',
                                                        'required'          => false,
                                                        'sanitize_callback' => 'sanitize_text_field',
                                                ),
                                                'args'      => array(
                                                        'type'              => 'array',
                                                        'required'          => false,
                                                        'default'           => array(),
                                                ),
                                        ),
                                ),
                        )
                );

                // v1.1.10 (WP8): test di UNA fonte della redazione (8 tipi) —
                // usato dall'editor fonti della pagina Redazioni e utile per il
                // debug. Risposta SEMPRE 200 con {ok, ...}: l'esito del test è
                // un dato, non un errore HTTP (stesso pattern di /tools/test).
                register_rest_route(
                        self::NAMESPACE_URI,
                        '/sources/test',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_sources_test' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => array(
                                                'type'              => array(
                                                        'type'              => 'string',
                                                        'required'          => true,
                                                        'sanitize_callback' => 'sanitize_key',
                                                ),
                                                // NOTA: NON esc_url_raw — per i tipi non
                                                // URL-based (youtube @canale, telegram,
                                                // reddit, search, mcp server|tool|args)
                                                // il valore NON è un URL; la normalizzazione
                                                // per tipo avviene nell'handler.
                                                'url'               => array(
                                                        'type'              => 'string',
                                                        'required'          => true,
                                                        'sanitize_callback' => 'sanitize_text_field',
                                                ),
                                                'query'             => array(
                                                        'type'              => 'string',
                                                        'required'          => false,
                                                        'default'           => '',
                                                        'sanitize_callback' => 'sanitize_text_field',
                                                ),
                                                'redazione_id'      => array(
                                                        'type'              => 'integer',
                                                        'required'          => false,
                                                        'default'           => 0,
                                                        'sanitize_callback' => 'absint',
                                                ),
                                        ),
                                ),
                        )
                );

                // v1.1.4: revisione forzata del capo redattore su un articolo.
                register_rest_route(
                        self::NAMESPACE_URI,
                        '/chief/run/(?P<id>[0-9]+)',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_chief_run' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => $id_arg,
                                ),
                        )
                );

                // v1.1.2: multilingua — info driver e lingue, traduzione come post nativo, stato traduzioni.
                register_rest_route(
                        self::NAMESPACE_URI,
                        '/multilang/info',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::READABLE,
                                        'callback'            => array( __CLASS__, 'rest_multilang_info' ),
                                        'permission_callback' => $admin_permission,
                                ),
                        )
                );

                register_rest_route(
                        self::NAMESPACE_URI,
                        '/multilang/translate-post',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_multilang_translate_post' ),
                                        'permission_callback' => $admin_permission,
                                        'args'                => array(
                                                'article_id'        => array(
                                                        'type'              => 'integer',
                                                        'required'          => true,
                                                        'sanitize_callback' => 'absint',
                                                ),
                                                'lang'              => array(
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
                        '/multilang/translations-posts',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::READABLE,
                                        'callback'            => array( __CLASS__, 'rest_multilang_translations_posts' ),
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

        /**
         * Permesso per le mutazioni pubbliche (view/reaction, v1.1.9).
         *
         * Le rotte restano utilizzabili dai visitatori anonimi del magazine, ma se la
         * richiesta porta l'header X-WP-Nonce quest'ultimo DEVE essere un nonce
         * 'wp_rest' valido (403 altrimenti): un client che si dichiara REST-aware
         * non può aggirare la protezione CSRF/riuso inviando nonce fasulli.
         *
         * v1.1.9 (D6): il 403 porta l'header CORS già usato dall'API pubblica
         * (Access-Control-Allow-Origin: *, stesso ACAO delle risposte 200 di
         * nexus-public/v1) così un client JS cross-origin legge il messaggio
         * d'errore invece di un fallimento CORS opaco.
         *
         * @param WP_REST_Request $request Request.
         * @return true|WP_Error
         */
        public static function perm_public_mutation( $request ) {
                $nonce = ( $request instanceof WP_REST_Request ) ? (string) $request->get_header( 'X-WP-Nonce' ) : '';
                if ( '' !== $nonce && ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
                        // v1.1.9 (D6): CORS anche sull'errore, vedi docblock.
                        self::public_cors_headers();
                        return new WP_Error(
                                'nexus_rest_bad_nonce',
                                __( 'Nonce non valido: ricarica la pagina e riprova.', 'nexus-news-ai' ),
                                array( 'status' => 403 )
                        );
                }
                return true;
        }

        /* =========================================================================
         * HARDENING PUBBLICO (v1.1.9): rate limit + whitelist emoji
         * ======================================================================= */

        /**
         * Rate limit a finestra fissa per chiave (IP + contesto), su transient:
         * il numero della finestra è incluso nella chiave, quindi il limite si
         * resetta automaticamente a ogni finestra.
         *
         * v1.1.9 (D6): quando il limite è raggiunto invia (effetto collaterale
         * documentato) gli header della risposta d'errore: CORS pubblico (come
         * perm_public_mutation) e Retry-After con i secondi REALI rimasti alla
         * fine della finestra corrente (calcolati dal window_id, non un fisso 60).
         * Il 429 vero e proprio è costruito dal chiamante (rest_public_reaction);
         * sulla rotta view l'esito resta 200 con 'rate_limit' => true (decisione
         * D2): l'header Retry-After è comunque onesto e indicativo per il client.
         *
         * @param string $bucket Contesto (es. 'view', 'reaction').
         * @param int    $max    Massimo numero di eventi nella finestra.
         * @param int    $window Secondi della finestra.
         * @return bool True se l'evento è ammesso.
         */
        private static function rate_limit_hit( $bucket, $max, $window ) {
                $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

                $window_id = (int) floor( time() / max( 1, (int) $window ) );
                $key       = 'nexus_rl_' . md5( $bucket . '|' . $ip . '|' . $window_id );
                $count     = (int) get_transient( $key );

                if ( $count >= max( 1, (int) $max ) ) {
                        // v1.1.9 (D6): secondi REALI rimasti della finestra corrente.
                        $window    = max( 1, (int) $window );
                        $remaining = ( ( $window_id + 1 ) * $window ) - time();
                        @header( 'Retry-After: ' . max( 1, min( $window, (int) $remaining ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                        self::public_cors_headers();
                        return false;
                }

                // TTL finestra + 60s di grazia: il contatore sopravvive anche a fine finestra.
                set_transient( $key, $count + 1, max( 1, (int) $window ) + 60 );
                return true;
        }

        /**
         * Header CORS delle risposte D'ERRORE delle rotte pubbliche (v1.1.9, D6).
         *
         * Stesso ACAO già usato dall'API pubblica (Nexus_Public_API::auth):
         * 'Access-Control-Allow-Origin: *'. Queste rotte sono mutazioni senza
         * credenziali (nessun cookie letto dalla risposta, nonce opzionale), quindi
         * il wildcard non allarga la superficie d'attacco; serve perché i client JS
         * cross-origin possano LEGGERE 403/429 invece di fermarsi al fallimento CORS.
         *
         * @return void
         */
        private static function public_cors_headers() {
                @header( 'Access-Control-Allow-Origin: *' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        /**
         * Whitelist canonica delle emoji di reazione (v1.1.9).
         *
         * ALLINEATA al codice esistente (Nexus_Utils::reaction_emojis() e il config
         * iniettato in class-nexus-shortcodes.php): ❤️ 👏 🔥 😮 😂 🤔. Fallback
         * hardcoded identico se la classe non è caricata. NIENTE emoji nuove.
         *
         * @return array
         */
        private static function reaction_whitelist() {
                $fallback = array( '❤️', '👏', '🔥', '😮', '😂', '🤔' );

                if ( class_exists( 'Nexus_Utils' ) && method_exists( 'Nexus_Utils', 'reaction_emojis' ) ) {
                        $list = Nexus_Utils::reaction_emojis();
                        if ( is_array( $list ) && ! empty( $list ) ) {
                                return $list;
                        }
                }

                return $fallback;
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
         * v1.1.4: ai_provider ('' = provider globale, altrimenti id presente
         * nel catalogo Nexus_Providers — dalla v1.1.10 (F2b/WP11) anche i
         * provider personalizzati 'cust_*', validati contro custom_providers() —
         * altrimenti ''), role in
         * giornalista|capo_redattore (valore non riconosciuto → giornalista),
         * chief_scope in ''|global|category|term (valore non riconosciuto → ''),
         * chief_term_id sempre intero positivo.
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
                if ( null !== $request->get_param( 'ai_provider' ) ) {
                        // v1.1.4: provider dedicato dell'agente; '' = provider globale (fallback impostazioni).
                        $provider = sanitize_key( (string) $request->get_param( 'ai_provider' ) );
                        if ( '' !== $provider ) {
                                $catalog  = class_exists( 'Nexus_Providers' ) && method_exists( 'Nexus_Providers', 'catalog' ) ? Nexus_Providers::catalog() : null;
                                $valid    = is_array( $catalog ) && array_key_exists( $provider, $catalog );
                                // v1.1.10 (F2b/WP11): i provider PERSONALIZZATI ('cust_*')
                                // sono validi se esistono in custom_providers(); il catalog
                                // li mergia già, la doppia verifica è difensiva (catalog
                                // obsoleto o classe parzialmente caricata).
                                if ( ! $valid && 0 === strpos( $provider, 'cust_' ) && class_exists( 'Nexus_Providers' ) && method_exists( 'Nexus_Providers', 'custom_providers' ) ) {
                                        $valid = array_key_exists( $provider, Nexus_Providers::custom_providers() );
                                }
                                $provider = $valid ? $provider : '';
                        }
                        $fields['ai_provider'] = $provider;
                }
                if ( null !== $request->get_param( 'role' ) ) {
                        // v1.1.4: ruolo editoriale dell'agente.
                        $role = sanitize_key( (string) $request->get_param( 'role' ) );
                        $fields['role'] = in_array( $role, array( 'giornalista', 'capo_redattore' ), true ) ? $role : 'giornalista';
                }
                if ( null !== $request->get_param( 'chief_scope' ) ) {
                        // v1.1.4: ambito di competenza del capo redattore ('' = nessuno).
                        $scope = sanitize_key( (string) $request->get_param( 'chief_scope' ) );
                        $fields['chief_scope'] = in_array( $scope, array( 'global', 'category', 'term' ), true ) ? $scope : '';
                }
                if ( null !== $request->get_param( 'chief_term_id' ) ) {
                        // v1.1.4: redazione di competenza quando chief_scope è 'term'.
                        $fields['chief_term_id'] = absint( $request->get_param( 'chief_term_id' ) );
                }

                // v1.1.10 (WP4, tools per-agente): provider di ricerca personale
                // ('' = usa il provider globale) e server MCP personali (stesso
                // formato righe 'nome|url|tokenOpzionale' del setting globale).
                if ( null !== $request->get_param( 'search_provider' ) ) {
                        $sp = sanitize_key( (string) $request->get_param( 'search_provider' ) );
                        $fields['search_provider'] = in_array( $sp, array( 'tavily', 'serper', 'brave' ), true ) ? $sp : '';
                }
                if ( null !== $request->get_param( 'mcp_servers_text' ) ) {
                        $fields['mcp_servers_text'] = sanitize_textarea_field( (string) $request->get_param( 'mcp_servers_text' ) );
                }
                if ( null !== $request->get_param( 'search_key' ) ) {
                        // v1.1.10: chiave di ricerca PERSONALE dell'agente. Viene
                        // memorizzata IN CHIARO nella colonna search_key_hash (nome
                        // colonnale storico: le chiavi search servono in chiaro a
                        // runtime per chiamare le API — stesse ragioni delle prov_key
                        // in nexus_settings; un hash sarebbe inutilizzabile). Max 64
                        // caratteri (VARCHAR/CHAR(64)), charset prudente.
                        $sk = preg_replace( '/[^A-Za-z0-9_\-]/', '', trim( (string) $request->get_param( 'search_key' ) ) );
                        if ( '' !== $sk ) {
                                $fields['search_key_hash'] = mb_substr( $sk, 0, 64 );
                        }
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
         * Il clone nasce in pausa per non partire a sorpresa con la pipeline e
         * mantiene provider, ruolo e ambito capo redattore dell'originale (v1.1.4).
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
                                'ai_provider' => (string) ( $agent->ai_provider ?? '' ),
                                'role'        => (string) ( $agent->role ?? 'giornalista' ),
                                'chief_scope' => (string) ( $agent->chief_scope ?? '' ),
                                'chief_term_id' => absint( $agent->chief_term_id ?? 0 ),
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
         * DELETE /agents/{id} — elimina l'agente (v1.1.7).
         * La storia editoriale resta: gli articoli vengono scollegati (agent_id = 0),
         * job pending/running rimossi, assegnazioni redazioni pulite, chief_id
         * azzerato se puntava a questo agente. Il dettaglio è in Nexus_DB::delete_agent().
         */
        public static function rest_agent_delete( $request ) {
                $id    = absint( $request->get_param( 'id' ) );
                $agent = Nexus_DB::get_agent( $id );
                if ( ! $agent ) {
                        return self::err( 'nexus_rest_agent_not_found', __( 'Agente non trovato.', 'nexus-news-ai' ), 404 );
                }
                $deleted = Nexus_DB::delete_agent( $id );
                if ( ! $deleted ) {
                        return self::err( 'nexus_rest_agent_delete_failed', __( 'Impossibile eliminare l\'agente.', 'nexus-news-ai' ), 500 );
                }
                return rest_ensure_response(
                        array(
                                'deleted' => true,
                                'id'      => $id,
                                'name'    => (string) $agent->name,
                        )
                );
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
         * CALLBACK — ADMIN: PROVIDER AI v2 (v1.1.10, WP10)
         * OAuth PKCE OpenRouter + wizard chiavi + test connessione.
         * ======================================================================= */

        /**
         * POST /ai/oauth/start — avvia il login web OAuth PKCE (v1.1.10).
         *
         * Body {provider}. Solo provider con 'oauth' => true nel catalog
         * (oggi OpenRouter): Nexus_Providers::oauth_start genera state one-shot
         * + PKCE e ritorna l'URL di autorizzazione. Risposta {ok, auth_url}:
         * il browser redirige l'admin su openrouter.ai; il ritorno arriva sulla
         * pagina Impostazioni AI con ?nexus_oauth=1&state=…&code=….
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_ai_oauth_start( $request ) {
                $provider = sanitize_key( (string) $request->get_param( 'provider' ) );

                $catalog = class_exists( 'Nexus_Providers' ) ? Nexus_Providers::catalog() : array();
                if ( ! isset( $catalog[ $provider ] ) || empty( $catalog[ $provider ]['oauth'] ) ) {
                        return self::err(
                                'nexus_rest_oauth_provider_unsupported',
                                __( 'Login web OAuth non disponibile per questo provider: usa la chiave API manuale.', 'nexus-news-ai' ),
                                400
                        );
                }

                $start = Nexus_Providers::oauth_start( $provider );
                if ( empty( $start['ok'] ) || empty( $start['auth_url'] ) ) {
                        return self::err(
                                'nexus_rest_oauth_start_failed',
                                // Il messaggio arriva da Nexus_Providers (runtime, già in italiano).
                                isset( $start['error'] ) && '' !== trim( (string) $start['error'] ) ? (string) $start['error'] : __( 'Avvio del login web non riuscito: riprova.', 'nexus-news-ai' ),
                                429
                        );
                }

                return rest_ensure_response(
                        array(
                                'ok'       => true,
                                'provider' => $provider,
                                'auth_url' => (string) $start['auth_url'],
                        )
                );
        }

        /**
         * POST /ai/oauth/callback — completa il login web OAuth PKCE (v1.1.10).
         *
         * Body {state, code}: Nexus_Providers::oauth_callback consuma lo stato
         * (one-shot, user-bound), scambia il codice con la chiave di provisioning
         * e la salva in prov_key_openrouter. La risposta è SOLO {ok: true}:
         * la chiave NON torna mai in risposta REST (coerente con la mascheratura
         * dei segreti). L'attività 'oauth_connected' è loggata senza la chiave.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_ai_oauth_callback( $request ) {
                if ( ! class_exists( 'Nexus_Providers' ) || ! method_exists( 'Nexus_Providers', 'oauth_callback' ) ) {
                        return self::err(
                                'nexus_rest_providers_missing',
                                __( 'Il registry dei provider AI non è disponibile: verifica che il plugin sia aggiornato.', 'nexus-news-ai' ),
                                502
                        );
                }

                $state = (string) $request->get_param( 'state' );
                $code  = (string) $request->get_param( 'code' );

                if ( '' === trim( $state ) || '' === trim( $code ) ) {
                        return self::err(
                                'nexus_rest_oauth_missing_params',
                                __( 'Parametri OAuth mancanti (state o code): riavvia il login da Impostazioni AI.', 'nexus-news-ai' ),
                                400
                        );
                }

                $result = Nexus_Providers::oauth_callback( $state, $code );
                if ( empty( $result['ok'] ) ) {
                        return self::err(
                                'nexus_rest_oauth_failed',
                                // Il messaggio arriva da Nexus_Providers (runtime, già in italiano).
                                isset( $result['error'] ) && '' !== trim( (string) $result['error'] ) ? (string) $result['error'] : __( 'Connessione OAuth non riuscita: riavvia il login.', 'nexus-news-ai' ),
                                400
                        );
                }

                // Mai la chiave: solo l'esito.
                return rest_ensure_response( array( 'ok' => true ) );
        }

        /**
         * POST /ai/disconnect/{provider} — scollega un provider (v1.1.10).
         *
         * Svuota prov_key_{provider} tramite Nexus_DB::update_settings (chiave
         * '' esplicita: a differenza del PUT /settings qui la cancellazione è
         * VOLUTA, è il flusso "Scollega" della UI). Log attività onesto
         * 'oauth_disconnected' senza alcun valore sensibile.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_ai_disconnect( $request ) {
                $provider = sanitize_key( (string) $request->get_param( 'provider' ) );

                $catalog = class_exists( 'Nexus_Providers' ) ? Nexus_Providers::catalog() : array();
                if ( ! isset( $catalog[ $provider ] ) || ! is_array( $catalog[ $provider ] ) ) {
                        return self::err(
                                'nexus_rest_provider_unknown',
                                __( 'Provider AI sconosciuto.', 'nexus-news-ai' ),
                                404
                        );
                }

                $key_option = Nexus_Providers::key_option( $provider );
                $defaults   = method_exists( 'Nexus_DB', 'default_settings' ) ? Nexus_DB::default_settings() : array();
                if ( ! is_array( $defaults ) || ! array_key_exists( $key_option, $defaults ) ) {
                        return self::err(
                                'nexus_rest_provider_key_unknown',
                                __( 'Questo provider non ha una chiave configurabile nelle impostazioni.', 'nexus-news-ai' ),
                                400
                        );
                }

                Nexus_DB::update_settings( array( $key_option => '' ) );

                $label = isset( $catalog[ $provider ]['label'] ) ? (string) $catalog[ $provider ]['label'] : $provider;
                Nexus_DB::insert_activity(
                        0,
                        'oauth_disconnected',
                        sprintf( 'Provider %s scollegato: chiave API rimossa dalle impostazioni.', $label ),
                        'info'
                );

                return rest_ensure_response(
                        array(
                                'ok'       => true,
                                'provider' => $provider,
                        )
                );
        }

        /* =========================================================================
         * CALLBACK — ADMIN: PROVIDER PERSONALIZZATI (v1.1.10, F2b/WP11)
         *
         * Storage: option 'nexus_custom_providers' letta/scritta SOLO da
         * Nexus_Providers (custom_providers / save_custom_providers /
         * upsert_custom_provider / delete_custom_provider). La chiave API NON è
         * MAI inclusa nelle risposte REST: ogni voce è mascherata con
         * api_key '' + has_key (pattern segreti D2/WP2).
         * ======================================================================= */

        /**
         * Voce provider custom mascherata per le risposte REST: mai la chiave.
         *
         * @param array $entry Entry da Nexus_Providers::custom_providers().
         * @return array Array( id, label, base_url, auth, has_key ).
         */
        private static function serialize_custom_provider( $entry ) {
                return array(
                        'id'       => isset( $entry['id'] ) ? (string) $entry['id'] : '',
                        'label'    => isset( $entry['label'] ) ? (string) $entry['label'] : '',
                        'base_url' => isset( $entry['base_url'] ) ? (string) $entry['base_url'] : '',
                        'auth'     => isset( $entry['auth'] ) ? (string) $entry['auth'] : 'bearer',
                        'has_key'  => isset( $entry['api_key'] ) && '' !== trim( (string) $entry['api_key'] ),
                );
        }

        /**
         * GET /ai/custom-providers — elenco provider personalizzati (F2b/WP11).
         *
         * Risposta { providers: [ {id, label, base_url, auth, has_key} ] }:
         * api_key SEMPRE vuota (has_key porta la sola presenza). base_url NON è
         * un segreto (endpoint dichiarato dall'admin) e torna integro per
         * precompilare il form di modifica nella UI.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_custom_providers_list( $request ) {
                unset( $request );
                if ( ! class_exists( 'Nexus_Providers' ) || ! method_exists( 'Nexus_Providers', 'custom_providers' ) ) {
                        return self::err(
                                'nexus_rest_providers_missing',
                                __( 'Il registry dei provider AI non è disponibile: verifica che il plugin sia aggiornato.', 'nexus-news-ai' ),
                                502
                        );
                }

                $providers = array();
                foreach ( Nexus_Providers::custom_providers() as $entry ) {
                        $providers[] = self::serialize_custom_provider( $entry );
                }

                return rest_ensure_response( array( 'providers' => $providers ) );
        }

        /**
         * POST /ai/custom-providers — crea o aggiorna un provider personalizzato.
         *
         * Body {id?, label, base_url, api_key?}: id assente → CREAZIONE (id
         * generato 'cust_' + 8 hex, tornato nella risposta); id presente →
         * UPDATE dell'entry esistente (id inesistente → 400, niente creazione
         * accidentale). SEMANTICA SEGRETI: api_key vuota su update NON cancella
         * la chiave salvata (come PUT /settings). La guardia SSRF G4-style è
         * applicata dal upsert (host privato rifiutato se allow_private_providers
         * è off, messaggio con l'istruzione per abilitarlo).
         *
         * Risposta { ok: true, id: 'cust_xxxxxxxx', provider: {...mascherata} }.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_custom_providers_upsert( $request ) {
                if ( ! class_exists( 'Nexus_Providers' ) || ! method_exists( 'Nexus_Providers', 'upsert_custom_provider' ) ) {
                        return self::err(
                                'nexus_rest_providers_missing',
                                __( 'Il registry dei provider AI non è disponibile: verifica che il plugin sia aggiornato.', 'nexus-news-ai' ),
                                502
                        );
                }

                $result = Nexus_Providers::upsert_custom_provider(
                        array(
                                'id'       => (string) $request->get_param( 'id' ),
                                'label'    => (string) $request->get_param( 'label' ),
                                'base_url' => (string) $request->get_param( 'base_url' ),
                                'api_key'  => (string) $request->get_param( 'api_key' ),
                        )
                );

                if ( empty( $result['ok'] ) ) {
                        return self::err(
                                'nexus_rest_custom_provider_invalid',
                                // Messaggio da Nexus_Providers (runtime, già in italiano).
                                isset( $result['error'] ) && '' !== trim( (string) $result['error'] ) ? (string) $result['error'] : __( 'Salvataggio del provider personalizzato non riuscito.', 'nexus-news-ai' ),
                                400
                        );
                }

                $new_id = isset( $result['id'] ) ? (string) $result['id'] : '';
                $entry  = array();
                foreach ( Nexus_Providers::custom_providers() as $custom_id => $custom_entry ) {
                        if ( $custom_id === $new_id ) {
                                $entry = $custom_entry;
                                break;
                        }
                }

                // Log attività onesto: solo esito e nome, MAI la chiave.
                if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                        Nexus_DB::insert_activity(
                                0,
                                'custom_provider_saved',
                                sprintf( 'Provider personalizzato “%1$s” salvato (%2$s).', isset( $entry['label'] ) ? $entry['label'] : $new_id, $new_id ),
                                'success'
                        );
                }

                return rest_ensure_response(
                        array(
                                'ok'       => true,
                                'id'       => $new_id,
                                'provider' => self::serialize_custom_provider( $entry ),
                        )
                );
        }

        /**
         * DELETE /ai/custom-providers/{id} — rimozione definitiva (F2b/WP11).
         *
         * I riferimenti negli agenti (ai_provider = cust_*) NON vengono toccati:
         * le loro generazioni falliranno con errore onesto finché l'admin non
         * riassegna un provider (mai fallback silenzioso sul provider globale).
         *
         * Risposta { ok: true, id }.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_custom_providers_delete( $request ) {
                if ( ! class_exists( 'Nexus_Providers' ) || ! method_exists( 'Nexus_Providers', 'delete_custom_provider' ) ) {
                        return self::err(
                                'nexus_rest_providers_missing',
                                __( 'Il registry dei provider AI non è disponibile: verifica che il plugin sia aggiornato.', 'nexus-news-ai' ),
                                502
                        );
                }

                $id = sanitize_key( (string) $request->get_param( 'id' ) );
                if ( '' === $id || ! preg_match( '/^cust_[0-9a-f]{8}$/', $id ) ) {
                        return self::err(
                                'nexus_rest_custom_provider_invalid_id',
                                __( 'Identificativo del provider personalizzato non valido: deve avere la forma “cust_” + 8 caratteri esadecimali.', 'nexus-news-ai' ),
                                400
                        );
                }

                if ( ! Nexus_Providers::delete_custom_provider( $id ) ) {
                        return self::err(
                                'nexus_rest_custom_provider_not_found',
                                __( 'Provider personalizzato non trovato: forse è già stato eliminato.', 'nexus-news-ai' ),
                                404
                        );
                }

                // Log attività onesto: nessun dato sensibile (solo l'id).
                if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                        Nexus_DB::insert_activity(
                                0,
                                'custom_provider_deleted',
                                sprintf( 'Provider personalizzato rimosso (%s).', $id ),
                                'info'
                        );
                }

                return rest_ensure_response(
                        array(
                                'ok' => true,
                                'id' => $id,
                        )
                );
        }

        /**
         * POST /ai/test/{provider} — test connessione VIVO del provider (v1.1.10).
         *
         * Body opzionale {base_url} (solo per 'custom': permette di testare l'URL
         * del campo prima del salvataggio). La chiave prov_key_{provider} è
         * obbligatoria per TUTTI tranne Ollama (endpoint locale senza auth): chiave
         * vuota → errore chiaro (per OpenRouter invita a connettersi via login web).
         * v1.1.10 (F2b/WP11): funziona anche per i provider PERSONALIZZATI cust_* —
         * endpoint {base_url}/models e chiave DALL'ENTRY (option
         * 'nexus_custom_providers'); per i cust_* il body base_url è ignorato
         * (l'entry è l'unica fonte di verità: si modifica e si salvano, poi si
         * riprova il test). Il test chiama l'endpoint /models del provider con
         * l'autenticazione giusta (models_endpoint/models_auth del catalog;
         * custom: {base_url}/models), timeout 15s, guardia SSRF fail-closed
         * (specchio G4: host privati solo per ollama o con allow_private_providers
         * attivo — vale anche per i cust_*).
         *
         * OpenRouter: la lista /models è PUBBLICA, quindi la validazione della
         * chiave passa per GET https://openrouter.ai/api/v1/key con la stessa
         * chiave (401/403 → chiave non valida, 429 → rate limit); la risposta di
         * quell'endpoint alimenta anche account (usage/limit/is_free_tier).
         *
         * Risposta (SEMPRE 200, l'esito del test è un dato):
         *   {ok: true, provider, models_count, sample: [primi 5 id], latency_ms,
         *    account?: {usage, limit, is_free_tier} per OpenRouter}
         *   {ok: false, error: messaggio leggibile e tradotto} — chiave non valida
         *   (401/403), rate limit (429), endpoint non raggiungibile (rete).
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_ai_test( $request ) {
                if ( ! class_exists( 'Nexus_Providers' ) ) {
                        return self::err(
                                'nexus_rest_providers_missing',
                                __( 'Il registry dei provider AI non è disponibile: verifica che il plugin sia aggiornato.', 'nexus-news-ai' ),
                                502
                        );
                }

                $provider = sanitize_key( (string) $request->get_param( 'provider' ) );
                $catalog  = Nexus_Providers::catalog();
                if ( ! isset( $catalog[ $provider ] ) || ! is_array( $catalog[ $provider ] ) ) {
                        return self::err(
                                'nexus_rest_provider_unknown',
                                __( 'Provider AI sconosciuto.', 'nexus-news-ai' ),
                                404
                        );
                }
                $entry = $catalog[ $provider ];

                $settings = Nexus_DB::get_settings();
                if ( ! is_array( $settings ) ) {
                        $settings = array();
                }

                // Chiave del provider (guard vuota con errore chiaro). SOLO Ollama è
                // ammessa senza chiave (endpoint locale senza auth): per gli altri la
                // chiave è obbligatoria — per OpenRouter anche se /models è pubblico,
                // perché il test serve a validare la chiave salvata (vedi /key sotto).
                // v1.1.10 (F2b/WP11): i provider CUSTOM (cust_*) tengono la chiave
                // DENTRO l'entry (option 'nexus_custom_providers'), NON in
                // prov_key_{id}: risoluzione dedicata prima del lookup standard.
                $is_custom = 0 === strpos( $provider, 'cust_' );
                if ( $is_custom ) {
                        $customs = method_exists( 'Nexus_Providers', 'custom_providers' ) ? Nexus_Providers::custom_providers() : array();
                        if ( ! isset( $customs[ $provider ] ) ) {
                                return self::err(
                                        'nexus_rest_provider_unknown',
                                        __( 'Provider AI sconosciuto.', 'nexus-news-ai' ),
                                        404
                                );
                        }
                        $api_key = trim( (string) $customs[ $provider ]['api_key'] );
                } else {
                        $api_key = isset( $settings[ 'prov_key_' . $provider ] ) ? trim( (string) $settings[ 'prov_key_' . $provider ] ) : '';
                }
                if ( '' === $api_key && 'ollama' !== $provider ) {
                        $is_oauth = ! empty( $entry['oauth'] );
                        $label    = isset( $entry['label'] ) ? (string) $entry['label'] : $provider;
                        return rest_ensure_response(
                                array(
                                        'ok'       => false,
                                        'provider' => $provider,
                                        'error'    => $is_oauth
                                                ? sprintf( __( 'Chiave API mancante per il provider %1$s: connettiti con il login web o salva una chiave e riprova.', 'nexus-news-ai' ), $label )
                                                : sprintf( __( 'Chiave API mancante per il provider %1$s: salvala in Impostazioni AI e riprova.', 'nexus-news-ai' ), $label ),
                                )
                        );
                }

                // Endpoint modelli: catalogo, oppure {base_url}/models per 'custom'
                // (il body permette di testare l'URL del campo PRIMA del salvataggio).
                if ( 'custom' === $provider ) {
                        $base_url = trim( (string) $request->get_param( 'base_url' ) );
                        if ( '' === $base_url ) {
                                $base_url = isset( $settings['prov_base_custom'] ) ? trim( (string) $settings['prov_base_custom'] ) : '';
                        }
                        if ( '' === $base_url ) {
                                return rest_ensure_response(
                                        array(
                                                'ok'       => false,
                                                'provider' => $provider,
                                                'error'    => __( 'URL base mancante per il provider Personalizzato: inseriscila nel campo “URL base” e riprova.', 'nexus-news-ai' ),
                                        )
                                );
                        }
                        $endpoint = untrailingslashit( $base_url ) . '/models';
                } else {
                        $endpoint = isset( $entry['models_endpoint'] ) ? trim( (string) $entry['models_endpoint'] ) : '';
                        if ( '' === $endpoint ) {
                                return rest_ensure_response(
                                        array(
                                                'ok'       => false,
                                                'provider' => $provider,
                                                'error'    => __( 'Endpoint modelli non configurato per questo provider: il test live non è disponibile.', 'nexus-news-ai' ),
                                        )
                                );
                        }
                }

                // Guardia SSRF fail-closed (specchio G4, Nexus_Providers::url_guard_error).
                $guard = Nexus_Providers::url_guard_error( $endpoint, $provider, $settings );
                if ( '' !== $guard ) {
                        return rest_ensure_response(
                                array(
                                        'ok'       => false,
                                        'provider' => $provider,
                                        'error'    => $guard,
                                )
                        );
                }

                // OpenRouter: validazione chiave + info account con GET /api/v1/key
                // (lista modelli è pubblica: senza questo passo un "test riuscito"
                // non proverebbe affatto la chiave salvata). Messaggi onesti:
                // 401/403 = chiave non valida, 429 = rate limit.
                $account = array();
                if ( 'openrouter' === $provider ) {
                        $acc = wp_remote_get(
                                'https://openrouter.ai/api/v1/key',
                                array(
                                        'timeout' => 15,
                                        'headers' => array(
                                                'Authorization' => 'Bearer ' . $api_key,
                                                'Accept'        => 'application/json',
                                        ),
                                )
                        );
                        if ( is_wp_error( $acc ) ) {
                                return rest_ensure_response(
                                        array(
                                                'ok'       => false,
                                                'provider' => $provider,
                                                'error'    => __( 'Provider non raggiungibile: ', 'nexus-news-ai' ) . $acc->get_error_message(),
                                        )
                                );
                        }
                        $acc_http = (int) wp_remote_retrieve_response_code( $acc );
                        if ( 200 !== $acc_http ) {
                                $acc_error = __( 'Il provider ha risposto con errore HTTP ' . $acc_http . ' sul controllo della chiave.', 'nexus-news-ai' );
                                if ( 401 === $acc_http || 403 === $acc_http ) {
                                        $acc_error = __( 'Chiave API non valida o non autorizzata (HTTP ' . $acc_http . '): verifica la chiave nelle impostazioni o riconnettiti.', 'nexus-news-ai' );
                                } elseif ( 429 === $acc_http ) {
                                        $acc_error = __( 'Rate limit del provider raggiunto (HTTP 429): riprova tra qualche minuto.', 'nexus-news-ai' );
                                }
                                return rest_ensure_response(
                                        array(
                                                'ok'        => false,
                                                'provider'  => $provider,
                                                'http_code' => $acc_http,
                                                'error'     => $acc_error,
                                        )
                                );
                        }
                        $acc_body = json_decode( (string) wp_remote_retrieve_body( $acc ), true );
                        if ( is_array( $acc_body ) && isset( $acc_body['data'] ) && is_array( $acc_body['data'] ) ) {
                                $acc_data = $acc_body['data'];
                                // usage/limit in USD; limit null = credito illimitato (omesso).
                                if ( isset( $acc_data['usage'] ) && is_numeric( $acc_data['usage'] ) ) {
                                        $account['usage'] = (float) $acc_data['usage'];
                                }
                                if ( isset( $acc_data['limit'] ) && null !== $acc_data['limit'] && is_numeric( $acc_data['limit'] ) ) {
                                        $account['limit'] = (float) $acc_data['limit'];
                                }
                                if ( isset( $acc_data['is_free_tier'] ) ) {
                                        $account['is_free_tier'] = (bool) $acc_data['is_free_tier'];
                                }
                        }
                }

                // Autenticazione secondo models_auth del catalog:
                // bearer (Authorization) | anthropic (x-api-key + anthropic-version) |
                // query_key (?key=) | none.
                $models_auth = isset( $entry['models_auth'] ) ? (string) $entry['models_auth'] : 'bearer';
                $args        = array(
                        'timeout' => 15,
                        'headers' => array( 'Accept' => 'application/json' ),
                );
                if ( 'bearer' === $models_auth ) {
                        $args['headers']['Authorization'] = 'Bearer ' . $api_key;
                        $args['headers']['HTTP-Referer']  = home_url( '/' );
                        $args['headers']['X-Title']       = 'Nexus News AI';
                } elseif ( 'anthropic' === $models_auth ) {
                        $args['headers']['x-api-key']         = $api_key;
                        $args['headers']['anthropic-version'] = '2023-06-01';
                } elseif ( 'query_key' === $models_auth ) {
                        $endpoint = add_query_arg( 'key', $api_key, $endpoint );
                }

                $started    = microtime( true );
                $response   = wp_remote_get( $endpoint, $args );
                $latency_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

                if ( is_wp_error( $response ) ) {
                        return rest_ensure_response(
                                array(
                                        'ok'         => false,
                                        'provider'   => $provider,
                                        'latency_ms' => $latency_ms,
                                        'error'      => __( 'Provider non raggiungibile: ', 'nexus-news-ai' ) . $response->get_error_message() . __( ' Se l’endpoint è in rete locale o sull’host di WordPress verifica che sia attivo e raggiungibile.', 'nexus-news-ai' ),
                                )
                        );
                }

                $http = (int) wp_remote_retrieve_response_code( $response );
                $raw  = (string) wp_remote_retrieve_body( $response );

                if ( 200 !== $http ) {
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
                        if ( 401 === $http || 403 === $http ) {
                                $error = __( 'Chiave API non valida o non autorizzata (HTTP ' . $http . '): verifica la chiave nelle impostazioni o riconnettiti.', 'nexus-news-ai' );
                        } elseif ( 429 === $http ) {
                                $error = __( 'Rate limit del provider raggiunto (HTTP 429): riprova tra qualche minuto.', 'nexus-news-ai' );
                        } else {
                                $error = __( 'Il provider ha risposto con errore HTTP ' . $http . ( '' !== $detail ? ': ' . $detail : '.' ), 'nexus-news-ai' );
                        }
                        return rest_ensure_response(
                                array(
                                        'ok'         => false,
                                        'provider'   => $provider,
                                        'http_code'  => $http,
                                        'latency_ms' => $latency_ms,
                                        'error'      => $error,
                                )
                        );
                }

                $models = self::normalize_ai_models_body( $raw );
                if ( ! $models ) {
                        return rest_ensure_response(
                                array(
                                        'ok'         => false,
                                        'provider'   => $provider,
                                        'latency_ms' => $latency_ms,
                                        'error'      => __( 'Il provider non ha restituito un elenco di modelli riconoscibile.', 'nexus-news-ai' ),
                                )
                        );
                }

                $out = array(
                        'ok'           => true,
                        'provider'     => $provider,
                        'models_count' => count( $models ),
                        'sample'       => array_slice( $models, 0, 5 ),
                        'latency_ms'   => $latency_ms,
                );

                // OpenRouter: info account già raccolte dalla validazione /key qui sopra.
                if ( ! empty( $account ) ) {
                        $out['account'] = $account;
                }

                // Log attività onesto: mai la chiave, solo esito e numeri.
                Nexus_DB::insert_activity(
                        0,
                        'provider_test',
                        sprintf( 'Test connessione provider %1$s riuscito: %2$d modelli in %3$d ms.', isset( $entry['label'] ) ? $entry['label'] : $provider, count( $models ), $latency_ms ),
                        'success'
                );

                return rest_ensure_response( $out );
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
         * POST /pipeline/run — pipeline completa (v1.1.5: avvii mirati).
         *
         * Scope accettati (tutti opzionali, risolti in ordine): agent_ids
         * (lista esplicita di agent ID) > redazione_id (subtree completo via
         * API reali di Nexus_Redazioni) > agent_id (0 = intera testata, solo
         * agenti attivi, come da comportamento storico). Esecuzione per agente
         * con try/catch: un agente in errore NON blocca gli altri.
         *
         * Risposta (shape contrattuale §4.1): {ok, scope: testata|redazione|
         * agent|agents, results[], summary} con results[] = {agent_id, name,
         * ok, collected, evaluated, rewritten, published, messages, error} e
         * summary = {agents, collected, published, errors}. BC: come v1.1.4
         * la chiave success vale true quando la richiesta è stata eseguita
         * (i vecchi risultati non restituivano mai WP_Error: l'api() di
         * admin.js la usa per decidere se lanciare l'errore; gli errori
         * per-agente viaggiano in ok/results/summary/errors + message e il
         * consumer v1.1.5 li rende come riepilogo "info"); quando il run ha
         * UN solo agente il suo risultato viene promosso al top-level con le
         * chiavi che run_full restituisce oggi (agent_id, agent, collected,
         * evaluated, rewritten, published, messages) per i consumer
         * esistenti. In caso di redazione senza agenti (o testata senza
         * agenti attivi) la risposta è ok con results vuoti e "message"
         * esplicativo in italiano; stesso campo su errori parziali.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_pipeline_run( $request ) {
                if ( ! is_callable( array( 'Nexus_Pipeline', 'run_full' ) ) ) {
                        return self::err( 'nexus_rest_pipeline_missing', __( 'Modulo pipeline non disponibile.', 'nexus-news-ai' ), 500 );
                }

                $agent_ids    = array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'agent_ids' ) ) ) );
                $redazione_id = absint( $request->get_param( 'redazione_id' ) );
                $agent_id     = absint( $request->get_param( 'agent_id' ) );

                /* ---- Risoluzione scope: agent_ids > redazione_id > agent_id ---- */
                $scope   = '';
                $targets = array();
                $message = '';

                if ( ! empty( $agent_ids ) ) {
                        $scope   = 'agents';
                        $targets = self::pipeline_run_agents_existing( $agent_ids );
                        if ( empty( $targets ) ) {
                                return self::err( 'nexus_rest_agent_not_found', __( 'Nessun agente valido tra quelli indicati.', 'nexus-news-ai' ), 404 );
                        }
                } elseif ( $redazione_id > 0 ) {
                        $scope = 'redazione';
                        if ( ! class_exists( 'Nexus_Redazioni' ) || ! method_exists( 'Nexus_Redazioni', 'agents_for_term' ) ) {
                                return self::err(
                                        'nexus_rest_redazioni_missing',
                                        __( 'Il modulo redazioni non è disponibile: verifica che il plugin sia aggiornato.', 'nexus-news-ai' ),
                                        502
                                );
                        }
                        $term = get_term( $redazione_id, Nexus_Redazioni::TAX );
                        if ( ! $term instanceof WP_Term ) {
                                return self::err( 'nexus_rest_redazione_not_found', __( 'Redazione non trovata.', 'nexus-news-ai' ), 404 );
                        }
                        $ids     = self::pipeline_run_redazione_agent_ids( $redazione_id );
                        $targets = self::pipeline_run_agents_existing( $ids );
                        if ( empty( $targets ) ) {
                                $name    = (string) $term->name;
                                $message = '' !== $name
                                        ? sprintf( __( 'La redazione "%s" non ha agenti assegnati: nessun avvio.', 'nexus-news-ai' ), $name )
                                        : __( 'La redazione selezionata non ha agenti assegnati: nessun avvio.', 'nexus-news-ai' );
                        }
                } elseif ( $agent_id > 0 ) {
                        $scope = 'agent';
                        $agent = Nexus_DB::get_agent( $agent_id );
                        if ( ! $agent ) {
                                return self::err( 'nexus_rest_agent_not_found', __( 'Agente non trovato.', 'nexus-news-ai' ), 404 );
                        }
                        $targets = array( $agent );
                } else {
                        $scope = 'testata';
                        foreach ( Nexus_DB::agents() as $agent ) {
                                if ( 'active' === $agent->status ) {
                                        $targets[] = $agent;
                                }
                        }
                        if ( empty( $targets ) ) {
                                $message = __( 'Nessun agente attivo in redazione: nessun avvio.', 'nexus-news-ai' );
                        }
                }

                /* ---- Esecuzione per agente: un errore non blocca gli altri ---- */
                $results = array();
                foreach ( $targets as $agent ) {
                        $entry = array(
                                'agent_id'  => (int) $agent->id,
                                'name'      => (string) $agent->name,
                                'ok'        => false,
                                'collected' => 0,
                                'evaluated' => 0,
                                'rewritten' => 0,
                                'published' => 0,
                                'messages'  => array(),
                                'error'     => null,
                        );

                        try {
                                $res = Nexus_Pipeline::run_full( (int) $agent->id );
                                if ( is_wp_error( $res ) ) {
                                        $entry['error'] = $res->get_error_message();
                                } elseif ( is_array( $res ) ) {
                                        $entry['ok']        = ! empty( $res['ok'] );
                                        $entry['collected'] = isset( $res['collected'] ) ? (int) $res['collected'] : 0;
                                        $entry['evaluated'] = isset( $res['evaluated'] ) ? (int) $res['evaluated'] : 0;
                                        $entry['rewritten'] = isset( $res['rewritten'] ) ? (int) $res['rewritten'] : 0;
                                        $entry['published'] = isset( $res['published'] ) ? (int) $res['published'] : 0;
                                        $entry['messages']  = array_values( array_map( 'strval', (array) ( isset( $res['messages'] ) ? $res['messages'] : array() ) ) );
                                } else {
                                        $entry['error'] = __( 'La pipeline non ha restituito un risultato valido.', 'nexus-news-ai' );
                                }
                        } catch ( Throwable $e ) {
                                $msg = trim( (string) $e->getMessage() );
                                if ( function_exists( 'mb_substr' ) ) {
                                        $msg = mb_substr( $msg, 0, 300 );
                                } else {
                                        $msg = substr( $msg, 0, 300 );
                                }
                                $entry['error'] = '' !== $msg ? $msg : __( 'Errore sconosciuto durante la pipeline.', 'nexus-news-ai' );
                        }

                        $results[] = $entry;
                }

                /* ---- Riepilogo (shape contrattuale) ---- */
                $sum_collected = 0;
                $sum_published = 0;
                $errors        = 0;
                foreach ( $results as $r ) {
                        $sum_collected += $r['collected'];
                        $sum_published += $r['published'];
                        if ( null !== $r['error'] || ! $r['ok'] ) {
                                $errors++;
                        }
                }
                $all_ok = 0 === $errors;

                $response = array(
                        'ok'      => $all_ok,
                        'scope'   => $scope,
                        'results' => $results,
                        'summary' => array(
                                'agents'    => count( $results ),
                                'collected' => $sum_collected,
                                'published' => $sum_published,
                                'errors'    => $errors,
                        ),
                        // BC v1.1.4: true = richiesta eseguita (come la vecchia shape,
                        // dove i risultati per-agente non restituivano mai WP_Error).
                        // L'api() di admin.js la usa come guardia: con false lancerebbe
                        // l'errore e il consumer v1.1.5 perderebbe il riepilogo results/.
                        // Gli errori per-agente restano in ok/results/summary/errors.
                        'success' => true,
                );

                if ( '' !== $message ) {
                        $response['message'] = $message;
                } elseif ( ! $all_ok ) {
                        $response['message'] = sprintf(
                                __( 'Avvio completato con errori: %1$d di %2$d agenti hanno riportato problemi.', 'nexus-news-ai' ),
                                $errors,
                                count( $results )
                        );
                }

                /* ---- BC: run con un solo agente → chiavi di run_full al top-level ---- */
                if ( 1 === count( $results ) ) {
                        $only                  = $results[0];
                        $response['agent_id']  = $only['agent_id'];
                        $response['agent']     = $only['name'];
                        $response['collected'] = $only['collected'];
                        $response['evaluated'] = $only['evaluated'];
                        $response['rewritten'] = $only['rewritten'];
                        $response['published'] = $only['published'];
                        $response['messages']  = $only['messages'];
                }

                return rest_ensure_response( $response );
        }

        /**
         * Agenti realmente esistenti tra gli ID richiesti (dedup incluso:
         * un solo passaggio su Nexus_DB::agents()).
         *
         * @param array $agent_ids Agent ID richiesti.
         * @return array Righe agente (stesso ordine di Nexus_DB::agents()).
         */
        private static function pipeline_run_agents_existing( $agent_ids ) {
                $want = array_fill_keys( array_map( 'absint', (array) $agent_ids ), true );
                $out  = array();
                foreach ( Nexus_DB::agents() as $agent ) {
                        if ( isset( $want[ (int) $agent->id ] ) ) {
                                $out[] = $agent;
                        }
                }
                return $out;
        }

        /**
         * Agent ID del subtree di una redazione (dedup) tramite le API reali
         * di Nexus_Redazioni: agents_for_term (unione termine + discendenti),
         * con fallback esplicito su descendant_ids + agent_ids_for_term per
         * versioni precedenti del modulo.
         *
         * @param int $term_id Term ID radice.
         * @return array Agent ID (int, unici, senza lo 0).
         */
        private static function pipeline_run_redazione_agent_ids( $term_id ) {
                if ( method_exists( 'Nexus_Redazioni', 'agents_for_term' ) ) {
                        return array_values( array_filter( array_map( 'absint', (array) Nexus_Redazioni::agents_for_term( $term_id ) ) ) );
                }

                $ids = array();
                if ( method_exists( 'Nexus_Redazioni', 'descendant_ids' ) && method_exists( 'Nexus_Redazioni', 'agent_ids_for_term' ) ) {
                        foreach ( (array) Nexus_Redazioni::descendant_ids( $term_id ) as $tid ) {
                                foreach ( (array) Nexus_Redazioni::agent_ids_for_term( $tid ) as $aid ) {
                                        $aid = absint( $aid );
                                        if ( $aid > 0 ) {
                                                $ids[] = $aid;
                                        }
                                }
                        }
                }
                return array_values( array_unique( $ids ) );
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

                // v1.1.5: pubblicazione standalone → nessun run che pulisce lo
                // stato runtime della fase "Pubblica": rimuovilo qui.
                if ( is_callable( array( 'Nexus_Pipeline', 'clear_runtime' ) ) && ! empty( $article->agent_id ) ) {
                        Nexus_Pipeline::clear_runtime( (int) $article->agent_id );
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
         * v1.1.9: i SEGRETI (ai_api_key, prov_key_*, tools_search_key, chiavi social,
         * public_api_key, mcp_servers_text) NON vengono più restituiti: il valore è
         * '' e viene aggiunta per ciascuno la chiave informativa 'x_<chiave>_set'
         * (true/false) così la UI sa se il segreto è configurato senza leggerlo.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response
         */
        public static function rest_settings_get( $request ) {
                unset( $request );
                return rest_ensure_response( self::mask_secret_settings( Nexus_DB::get_settings() ) );
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

                // v1.1.9: i segreti sono salvati SOLO se valorizzati — una stringa vuota
                // non può cancellare un segreto esistente (mitigazione per client che
                // reinviano i campi vuoti; per revocare un segreto si rigenera/rimpiazza).
                foreach ( self::secret_settings_keys() as $secret_key ) {
                        if ( isset( $payload[ $secret_key ] ) && '' === trim( (string) $payload[ $secret_key ] ) ) {
                                unset( $payload[ $secret_key ] );
                        }
                }

                $updated = Nexus_DB::update_settings( $payload );
                Nexus_DB::insert_activity( 0, 'settings_update', __( 'Impostazioni aggiornate via API.', 'nexus-news-ai' ), 'info' );

                // v1.1.9: la risposta del PUT ripete le settings complete (update_settings
                // le ritorna intere): mascherate anche qui, il segreto non deve comparire
                // in NESSUNA risposta REST.
                return rest_ensure_response( self::mask_secret_settings( is_array( $updated ) ? $updated : array() ) );
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

        /**
         * GET /live — stato live della redazione (v1.1.5, pannello "In redazione ora").
         *
         * Shape contrattuale (§4.3): {updated_at: ISO8601, agents: [{id, name,
         * avatar, status: active|paused|error, running, current: {step, label,
         * article_id, article_title, since}|null, last_action: {action, detail,
         * status, created_at}|null, today: {articles, runs}}], redazioni:
         * [{id, name, agents, running}], testata: {running, total}}.
         *
         * Fonti: agenti da Nexus_DB::agents(), runtime da
         * Nexus_Pipeline::get_runtime() (option nexus_agent_runtime; stati più
         * vecchi di LIVE_RUNTIME_TTL trattati come residui e ignorati), ultima
         * azione per agente da nexus_activity_logs con UNA query raggruppata,
         * articoli e run di oggi con DUE query raggruppate (GROUP BY agent_id).
         * Niente N+1: endpoint leggero pensato per polling ~5s. today.runs =
         * righe di attività 'collecting' di oggi per agente (un run della
         * pipeline parte sempre dalla raccolta).
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_live( $request ) {
                unset( $request );

                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'agents' ) ) {
                        return self::err(
                                'nexus_rest_db_missing',
                                __( 'Il modulo database non è disponibile: verifica che il plugin sia aggiornato.', 'nexus-news-ai' ),
                                502
                        );
                }

                $agent_rows = Nexus_DB::agents();
                $runtime    = self::live_runtime_states();

                $agent_ids = array();
                foreach ( $agent_rows as $row ) {
                        $agent_ids[] = (int) $row->id;
                }

                $last_actions = self::live_last_actions( $agent_ids );
                $today_counts = self::live_today_counts( $agent_ids );

                /* ---- Agenti ---- */
                $agents_out = array();
                $running    = 0;
                foreach ( $agent_rows as $row ) {
                        $aid = (int) $row->id;

                        $status = (string) ( isset( $row->status ) ? $row->status : 'paused' );
                        if ( ! in_array( $status, array( 'active', 'paused', 'error' ), true ) ) {
                                $status = 'paused';
                        }

                        $current = isset( $runtime[ $aid ] ) ? $runtime[ $aid ] : null;
                        if ( is_array( $current ) ) {
                                $running++;
                        }

                        $counts = isset( $today_counts[ $aid ] ) ? $today_counts[ $aid ] : array();
                        $avatar = (string) ( isset( $row->avatar ) ? $row->avatar : '' );

                        $agents_out[] = array(
                                'id'          => $aid,
                                'name'        => (string) $row->name,
                                'avatar'      => '' !== $avatar ? $avatar : '🤖',
                                'status'      => $status,
                                'running'     => is_array( $current ),
                                'current'     => $current,
                                'last_action' => isset( $last_actions[ $aid ] ) ? $last_actions[ $aid ] : null,
                                'today'       => array(
                                        'articles' => isset( $counts['articles'] ) ? (int) $counts['articles'] : 0,
                                        'runs'     => isset( $counts['runs'] ) ? (int) $counts['runs'] : 0,
                                ),
                        );
                }

                /* ---- Redazioni: agenti del ramo + quanti sono in esecuzione ---- */
                $redazioni_out = array();
                if ( class_exists( 'Nexus_Redazioni' )
                        && method_exists( 'Nexus_Redazioni', 'all_terms' )
                        && method_exists( 'Nexus_Redazioni', 'agents_for_term' ) ) {
                        $existing = array_fill_keys( $agent_ids, true );
                        foreach ( Nexus_Redazioni::all_terms() as $term ) {
                                $ramo_ids = array_values( array_filter( array_map( 'absint', (array) Nexus_Redazioni::agents_for_term( (int) $term->term_id ) ) ) );
                                $count_agents  = 0;
                                $count_running = 0;
                                foreach ( $ramo_ids as $ramo_agent_id ) {
                                        if ( isset( $existing[ $ramo_agent_id ] ) ) {
                                                $count_agents++;
                                                if ( isset( $runtime[ $ramo_agent_id ] ) ) {
                                                        $count_running++;
                                                }
                                        }
                                }
                                $redazioni_out[] = array(
                                        'id'      => (int) $term->term_id,
                                        'name'    => (string) $term->name,
                                        'agents'  => $count_agents,
                                        'running' => $count_running,
                                );
                        }
                }

                return rest_ensure_response(
                        array(
                                'updated_at' => gmdate( 'c' ),
                                'agents'     => $agents_out,
                                'redazioni'  => $redazioni_out,
                                'testata'    => array(
                                        'running' => $running,
                                        'total'   => count( $agent_rows ),
                                ),
                        )
                );
        }

        /**
         * Stati runtime validi (non più vecchi di LIVE_RUNTIME_TTL) normalizzati
         * per il pannello live: array agent_id => {step, label, article_id,
         * article_title, since}. Gli stati residui (crash, job di coda conclusi
         * senza clear) oltre soglia vengono ignorati: l'agente appare fermo.
         *
         * @return array
         */
        private static function live_runtime_states() {
                $runtime = is_callable( array( 'Nexus_Pipeline', 'get_runtime' ) ) ? Nexus_Pipeline::get_runtime() : array();
                if ( ! is_array( $runtime ) ) {
                        return array();
                }

                $out = array();
                foreach ( $runtime as $key => $state ) {
                        $agent_id = absint( $key );
                        if ( $agent_id < 1 || ! is_array( $state ) ) {
                                continue;
                        }

                        $since = isset( $state['since'] ) ? strtotime( (string) $state['since'] ) : false;
                        if ( false === $since || ( time() - $since ) > self::LIVE_RUNTIME_TTL ) {
                                continue; // Stato residuo: trattato come non in esecuzione.
                        }

                        $out[ $agent_id ] = array(
                                'step'          => isset( $state['step'] ) ? (string) $state['step'] : '',
                                'label'         => isset( $state['label'] ) ? (string) $state['label'] : '',
                                'article_id'    => isset( $state['article_id'] ) ? absint( $state['article_id'] ) : 0,
                                'article_title' => isset( $state['article_title'] ) ? (string) $state['article_title'] : '',
                                'since'         => isset( $state['since'] ) ? (string) $state['since'] : '',
                        );
                }
                return $out;
        }

        /**
         * Ultima azione per agente da nexus_activity_logs con UNA sola query
         * raggruppata (MAX(id) per agent_id): array agent_id => {action,
         * detail, status, created_at}. created_at convertito in RFC3339
         * (ISO8601, fuso del sito) per il diff lato client; le date zero
         * (v1.1.6) diventano stringa vuota, mai RFC3339 di una data finta.
         *
         * @param array $agent_ids Agent ID da considerare.
         * @return array
         */
        private static function live_last_actions( $agent_ids ) {
                global $wpdb;

                $out = array();
                $ids = array_values( array_filter( array_map( 'absint', (array) $agent_ids ) ) );
                if ( empty( $ids ) || ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'tables' ) ) {
                        return $out;
                }

                $tables = Nexus_DB::tables();
                if ( empty( $tables['activity_logs'] ) ) {
                        return $out;
                }
                $table = $tables['activity_logs'];

                $placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
                $sql          = "SELECT t.agent_id, t.action, t.detail, t.status, t.created_at"
                        . " FROM {$table} AS t"
                        . " INNER JOIN ("
                        . " SELECT agent_id, MAX( id ) AS last_id FROM {$table}"
                        . " WHERE agent_id IN ( {$placeholders} ) GROUP BY agent_id"
                        . " ) AS m ON m.last_id = t.id";
                $rows = $wpdb->get_results( $wpdb->prepare( $sql, $ids ) );

                if ( ! is_array( $rows ) ) {
                        return $out;
                }

                foreach ( $rows as $row ) {
                        $detail = trim( (string) ( isset( $row->detail ) ? $row->detail : '' ) );
                        if ( function_exists( 'mb_substr' ) ) {
                                $detail = mb_substr( $detail, 0, 200 );
                        } else {
                                $detail = substr( $detail, 0, 200 );
                        }

                        $created = self::date_or_empty( isset( $row->created_at ) ? $row->created_at : '' );
                        if ( '' !== $created && function_exists( 'mysql_to_rfc3339' ) ) {
                                $created = mysql_to_rfc3339( $created );
                        }

                        $out[ (int) $row->agent_id ] = array(
                                'action'     => (string) $row->action,
                                'detail'     => $detail,
                                'status'     => (string) $row->status,
                                'created_at' => $created,
                        );
                }
                return $out;
        }

        /**
         * Articoli creati oggi e run di oggi per agente con DUE query
         * raggruppate (GROUP BY agent_id): array agent_id => {articles, runs}.
         * Un run della pipeline parte sempre dalla raccolta: runs = righe di
         * attività 'collecting' di oggi per agente (colonne reali:
         * articles.agent_id + articles.created_at, activity_logs.action +
         * activity_logs.created_at).
         *
         * @param array $agent_ids Agent ID da considerare.
         * @return array
         */
        private static function live_today_counts( $agent_ids ) {
                global $wpdb;

                $out = array();
                $ids = array_values( array_filter( array_map( 'absint', (array) $agent_ids ) ) );
                if ( empty( $ids ) || ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'tables' ) ) {
                        return $out;
                }

                $tables = Nexus_DB::tables();
                if ( empty( $tables['articles'] ) || empty( $tables['activity_logs'] ) ) {
                        return $out;
                }

                $today_start  = substr( current_time( 'mysql' ), 0, 10 ) . ' 00:00:00';
                $placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
                $args         = array_merge( $ids, array( $today_start ) );

                $sql_articles = "SELECT agent_id, COUNT( * ) AS n FROM {$tables['articles']}"
                        . " WHERE agent_id IN ( {$placeholders} ) AND created_at >= %s GROUP BY agent_id";
                $rows = $wpdb->get_results( $wpdb->prepare( $sql_articles, $args ) );
                if ( is_array( $rows ) ) {
                        foreach ( $rows as $row ) {
                                $out[ (int) $row->agent_id ] = array(
                                        'articles' => (int) $row->n,
                                        'runs'     => 0,
                                );
                        }
                }

                $sql_runs = "SELECT agent_id, COUNT( * ) AS n FROM {$tables['activity_logs']}"
                        . " WHERE agent_id IN ( {$placeholders} ) AND action = 'collecting' AND created_at >= %s GROUP BY agent_id";
                $rows = $wpdb->get_results( $wpdb->prepare( $sql_runs, $args ) );
                if ( is_array( $rows ) ) {
                        foreach ( $rows as $row ) {
                                $aid = (int) $row->agent_id;
                                if ( ! isset( $out[ $aid ] ) ) {
                                        $out[ $aid ] = array(
                                                'articles' => 0,
                                                'runs'     => 0,
                                        );
                                }
                                $out[ $aid ]['runs'] = (int) $row->n;
                        }
                }

                return $out;
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

                // v1.1.9: anche il singolo articolo pubblico usa il serializzatore
                // pubblico (senza campi interni del capo redattore).
                return rest_ensure_response( self::serialize_public_article( $article, true ) );
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

                // v1.1.9: whitelist emoji (allineata a Nexus_Utils::reaction_emojis):
                // niente reazioni arbitrarie che sporcano il JSON delle reactions.
                if ( ! in_array( $emoji, self::reaction_whitelist(), true ) ) {
                        return self::err( 'nexus_rest_invalid_emoji', __( 'Emoji non ammessa: scegli una delle reazioni disponibili.', 'nexus-news-ai' ), 400 );
                }

                // v1.1.9: rate limit 10 reazioni/min per IP+articolo (anti flood).
                if ( ! self::rate_limit_hit( 'reaction|' . $id, 10, 60 ) ) {
                        return self::err( 'nexus_rest_rate_limited', __( 'Troppe reazioni: riprova tra poco.', 'nexus-news-ai' ), 429 );
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

                // v1.1.9: rate limit 1 vista/60s per IP+articolo (anti-gonfiaggio contatore).
                if ( ! self::rate_limit_hit( 'view|' . $id, 1, 60 ) ) {
                        $fresh = Nexus_DB::get_article( $id );

                        return rest_ensure_response(
                                array(
                                        'views'      => $fresh ? (int) $fresh->views : (int) $article->views,
                                        'rate_limit' => true,
                                )
                        );
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
         * Dalla v1.1.5 usato solo da /pipeline/collect (l'handler di
         * /pipeline/run gestisce in proprio gli avvii mirati §4.1). Al termine
         * di una raccolta standalone rimuove lo stato runtime 'collect'
         * impostato da Nexus_Pipeline::collect() (non essendo un run completo
         * non c'è un finally che lo pulisca).
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

                // v1.1.5: raccolta standalone → nessun run completo che pulisce
                // lo stato runtime: rimuovilo per ogni agente toccato.
                if ( 'collect' === $method && is_callable( array( 'Nexus_Pipeline', 'clear_runtime' ) ) ) {
                        foreach ( $targets as $agent ) {
                                Nexus_Pipeline::clear_runtime( (int) $agent->id );
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
         * v1.1.4: ammesse anche le chiavi provider AI per agente (prov_key_*),
         * capo redattore (chief_*) e strumenti di ricerca (tools_*,
         * mcp_servers_text) con tipi, range ed enum dedicati.
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
                        // Provider AI per agente (v1.1.4): chiavi per provider e base URL personalizzato.
                        // v1.1.10 (WP10, contratto D5): il sanitize delle prov_key_* passa
                        // a sanitize_secret_multiline() (vedi docblock del metodo) invece
                        // di sanitize_text_field, che avrebbe PERSO i newline del pool
                        // multi-chiave (una chiave per riga, rotazione round-robin).
                        'prov_key_openrouter'  => array( __CLASS__, 'sanitize_secret_multiline' ),
                        'prov_key_openai'      => array( __CLASS__, 'sanitize_secret_multiline' ),
                        'prov_key_anthropic'   => array( __CLASS__, 'sanitize_secret_multiline' ),
                        'prov_key_google'      => array( __CLASS__, 'sanitize_secret_multiline' ),
                        'prov_key_groq'        => array( __CLASS__, 'sanitize_secret_multiline' ),
                        'prov_key_deepseek'    => array( __CLASS__, 'sanitize_secret_multiline' ),
                        'prov_key_mistral'     => array( __CLASS__, 'sanitize_secret_multiline' ),
                        'prov_key_xai'         => array( __CLASS__, 'sanitize_secret_multiline' ),
                        'prov_key_custom'      => array( __CLASS__, 'sanitize_secret_multiline' ),
                        'prov_key_ollama'      => array( __CLASS__, 'sanitize_secret_multiline' ), // v1.1.10 (G2): ora in whitelist anche per Ollama
                        'prov_base_custom'     => 'esc_url_raw',
                        // v1.1.10 — motore fonti multi-tipo + provider v2.
                        'youtube_api_key'      => 'sanitize_text_field',
                        'pixabay_key'          => 'sanitize_text_field',
                        'reddit_client_id'     => 'sanitize_text_field',
                        'reddit_client_secret' => 'sanitize_text_field',
                        'allow_private_providers' => 'absint',
                        // Capo redattore AI (v1.1.4).
                        'chief_enabled'        => 'absint',
                        'chief_auto_publish'   => 'absint',
                        'chief_max_rounds'     => 'absint',
                        'chief_id'             => 'absint',
                        // Strumenti di ricerca (v1.1.4).
                        'tools_enabled'        => 'absint',
                        'tools_search_provider' => 'sanitize_key',
                        'tools_search_key'     => 'sanitize_text_field',
                        'tools_max_results'    => 'absint',
                        'tools_read_page'      => 'absint',
                        'tools_read_max_chars' => 'absint',
                        'tools_max_rounds'     => 'absint',
                        'tools_timeout'        => 'absint',
                        // Sistema (v1.1.6): coda, API pubblica, lingua admin, autotraduzione (v1.1.7: chiavi prima scartate dalla whitelist).
                        'admin_language'       => 'sanitize_key',
                        'enable_queue'         => 'absint',
                        'queue_batch_size'     => 'absint',
                        'queue_max_attempts'   => 'absint',
                        'enable_public_api'    => 'absint',
                        'enable_autotranslate_posts' => 'absint',
                        'mcp_servers_text'     => 'sanitize_textarea_field',
                        // Chiave API pubblica (v1.1.9, D6): scrivibile anche via PUT come gli
                        // altri segreti; il valore viene normalizzato DOPO la mappa (vedi
                        // normalize_public_api_key): non vuoto e non hex-64 → hash SHA-256.
                        'public_api_key'       => 'sanitize_text_field',
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
                        // Capo redattore + strumenti di ricerca (v1.1.4).
                        'chief_enabled',
                        'chief_auto_publish',
                        'tools_enabled',
                        'tools_read_page',
                        // Sistema (v1.1.7): coerenza con le chiavi aggiunte in mappa.
                        'enable_queue',
                        'enable_public_api',
                        'enable_autotranslate_posts',
                ) as $flag ) {
                        if ( isset( $out[ $flag ] ) ) {
                                $out[ $flag ] = $out[ $flag ] ? 1 : 0;
                        }
                }

                // Tema admin: ammessi solo light | dark (v1.0.6), fallback chiaro.
                if ( isset( $out['admin_theme'] ) && ! in_array( $out['admin_theme'], array( 'light', 'dark' ), true ) ) {
                        $out['admin_theme'] = 'light';
                }

                // Lingua admin: ammesse solo auto | it | en (v1.0.5), fallback auto (V117-T-B2).
                if ( isset( $out['admin_language'] ) && ! in_array( $out['admin_language'], array( 'auto', 'it', 'en' ), true ) ) {
                        $out['admin_language'] = 'auto';
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

                // Valida lo stato dei post WordPress creati dal publisher (v1.1.7: 'pending' ammesso, la UI lo offre dalla v1.1.5).
                if ( isset( $out['post_status'] ) && ! in_array( $out['post_status'], array( 'publish', 'draft', 'pending' ), true ) ) {
                        return self::err(
                                'nexus_rest_invalid_post_status',
                                __( 'Stato post non valido. Valori ammessi: publish, draft, pending.', 'nexus-news-ai' ),
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

                // Capo redattore: giri di revisione 1-5 (v1.1.4).
                if ( isset( $out['chief_max_rounds'] ) ) {
                        $out['chief_max_rounds'] = min( 5, max( 1, (int) $out['chief_max_rounds'] ) );
                }

                // Provider di ricerca: tavily | serper | brave | none (v1.1.4).
                if ( isset( $out['tools_search_provider'] ) && ! in_array( $out['tools_search_provider'], array( 'tavily', 'serper', 'brave', 'none' ), true ) ) {
                        return self::err(
                                'nexus_rest_invalid_search_provider',
                                __( 'Provider di ricerca non valido. Valori ammessi: tavily, serper, brave, none.', 'nexus-news-ai' ),
                                400
                        );
                }

                // Risultati di ricerca per query: 1-10 (v1.1.4).
                if ( isset( $out['tools_max_results'] ) ) {
                        $out['tools_max_results'] = min( 10, max( 1, (int) $out['tools_max_results'] ) );
                }

                // Lettura pagine: 500-20000 caratteri (v1.1.4).
                if ( isset( $out['tools_read_max_chars'] ) ) {
                        $out['tools_read_max_chars'] = min( 20000, max( 500, (int) $out['tools_read_max_chars'] ) );
                }

                // Giri del tool-use loop: 0-6 (0 = loop disattivato) (v1.1.4).
                if ( isset( $out['tools_max_rounds'] ) ) {
                        $out['tools_max_rounds'] = min( 6, max( 0, (int) $out['tools_max_rounds'] ) );
                }

                // Timeout richieste strumenti: 5-30 secondi (v1.1.4).
                if ( isset( $out['tools_timeout'] ) ) {
                        $out['tools_timeout'] = min( 30, max( 5, (int) $out['tools_timeout'] ) );
                }

                // Coda lavori: batch 1-50, tentativi 1-10 (v1.0.4; limiti del markup, V117-T-B2).
                if ( isset( $out['queue_batch_size'] ) ) {
                        $out['queue_batch_size'] = min( 50, max( 1, absint( $out['queue_batch_size'] ) ) );
                }
                if ( isset( $out['queue_max_attempts'] ) ) {
                        $out['queue_max_attempts'] = min( 10, max( 1, absint( $out['queue_max_attempts'] ) ) );
                }

                // Chiave API pubblica (v1.1.9, D6): se fornita non vuota e NON già hex-64
                // viene salvata come hash('sha256', valore) (esadecimale minuscolo). Un valore
                // hex-64 fornito dall'admin è considerato GIÀ un hash (chiave passthrough).
                // Nota: qui nel payload non si può distinguere il caso "vuoto" gestito a
                // monte da rest_settings_update (i segreti vuoti non cancellano).
                if ( isset( $out['public_api_key'] ) ) {
                        $out['public_api_key'] = self::normalize_public_api_key( $out['public_api_key'] );
                }

                return $out;
        }

        /* =========================================================================
         * SEGRETI: MASCHERATURA (v1.1.9)
         * ======================================================================= */

        /**
         * Sanitize dei SEGRETI multi-riga (prov_key_*, v1.1.10 WP10).
         *
         * Contratto D5 (key pool multi-chiave): in prov_key_{provider} le chiavi
         * multiple vanno salvate UNA PER RIGA (il consumer ruota round-robin),
         * quindi il sanitize deve PRESERVARE i newline: sanitize_text_field li
         * avrebbe persi (li sostituisce con spazi) e avrebbe fuso il pool in una
         * riga sola illeggibile. sanitize_textarea_field + trim mantiene le righe
         * e ripulisce i bordi; niente strip_tags perché una chiave API non
         * contiene markup (e gli eventuali '<'/'>' veri andrebbero preservati).
         *
         * Usato dalla whitelist di sanitize_settings_payload tramite callback
         * callable (array( __CLASS__, … )): call_user_func accetta array-callable
         * come le stringhe di funzione già presenti nella mappa.
         *
         * @param string $value Valore grezzo del campo.
         * @return string Valore sanificato, newline preservati.
         */
        private static function sanitize_secret_multiline( $value ) {
                return trim( (string) sanitize_textarea_field( (string) $value ) );
        }

        /**
         * Chiavi settings considerate SEGRETI (mai in risposta REST, mai nell'export).
         *
         * Comprende: chiave AI globale, chiavi per-provider (prov_key_*), chiave di
         * ricerca, le 7 credenziali social, la chiave dell'API pubblica e il testo
         * di configurazione dei server MCP (contiene URL e token opzionali).
         *
         * @return array
         */
        private static function secret_settings_keys() {
                return array(
                        'ai_api_key',
                        'prov_key_openrouter',
                        'prov_key_openai',
                        'prov_key_anthropic',
                        'prov_key_google',
                        'prov_key_groq',
                        'prov_key_deepseek',
                        'prov_key_mistral',
                        'prov_key_xai',
                        'prov_key_custom',
                        'prov_key_ollama', // v1.1.10 (G2): omissa prima, incoerenza con la whitelist PUT
                        'youtube_api_key',
                        'pixabay_key',
                        'reddit_client_secret',
                        'tools_search_key',
                        'telegram_bot_token',
                        'discord_webhook_url',
                        'x_api_key',
                        'x_api_secret',
                        'x_access_token',
                        'x_access_secret',
                        'linkedin_access_token',
                        'public_api_key',
                        'mcp_servers_text',
                );
        }

        /**
         * Maschera i segreti di un array settings per le risposte REST.
         *
         * Per ciascuna chiave segreta: valore '' + chiave informativa
         * 'x_<chiave>_set' => true/false (presenza, non valore). Non modifica
         * l'array ricevuto (restituisce una copia).
         *
         * v1.1.9 (D6): public_api_key è memorizzata come hash SHA-256 (hex-64)
         * oppure, finché non migrata, come chiave legacy in chiaro. In ENTRAMBI
         * i casi il valore è non vuoto, quindi 'x_public_api_key_set' resta true:
         * la mascheratura NON va duplicata né adattata (coerenza già garantita).
         *
         * @param array $settings Settings (es. Nexus_DB::get_settings()).
         * @return array
         */
        private static function mask_secret_settings( $settings ) {
                $out = is_array( $settings ) ? $settings : array();

                foreach ( self::secret_settings_keys() as $secret_key ) {
                        $is_set = isset( $out[ $secret_key ] ) && '' !== trim( (string) $out[ $secret_key ] );

                        $out[ $secret_key ] = '';

                        // Chiave informativa: la UI sa che il segreto esiste senza leggerlo.
                        $out[ 'x_' . $secret_key . '_set' ] = $is_set;
                }

                return $out;
        }

        /**
         * Normalizza il valore della chiave API pubblica al formato v1.1.9 (D6).
         *
         * Contratto di memorizzazione:
         *  - stringa vuota            → '' (nessuna chiave; nei PUT i segreti vuoti
         *                              vengono comunque scartati a monte);
         *  - già hex-64 (qualsiasi caso, normalizzato minuscolo) → passthrough:
         *                              l'admin ha fornito direttamente un hash SHA-256;
         *  - qualunque altro valore NON vuoto → hash('sha256', valore), esadecimale
         *                              minuscolo (64 caratteri).
         *
         * MIGRAZIONE SOFT: le chiavi legacy salvate in chiaro dalle versioni
         * precedenti restano valide (Nexus_Public_API::auth() le confronta in
         * chiaro e le ri-salva hashate alla prima occasione, upgrade-on-use);
         * una chiave legacy cessa di esistere in chiaro solo quando viene
         * risalvata (qui, dal keygen o dall'upgrade-on-use).
         *
         * @param string $value Valore fornito (già sanificato sanitize_text_field).
         * @return string Valore da memorizzare nelle settings.
         */
        private static function normalize_public_api_key( $value ) {
                $value = trim( (string) $value );

                if ( '' === $value ) {
                        return '';
                }

                // Già un hash SHA-256 (hex-64): canonicalizzato minuscolo e salvato così.
                if ( preg_match( '/^[0-9a-f]{64}$/i', $value ) ) {
                        return strtolower( $value );
                }

                // Chiave in chiaro: si salva SOLO l'hash, mai il valore.
                return hash( 'sha256', $value );
        }

        /**
         * Serializza un agente per la risposta JSON.
         *
         * Dalla v1.1.4 include sempre anche ai_provider ('' = provider globale),
         * role (default 'giornalista'), chief_scope ('' | global | category | term)
         * e chief_term_id: letture difensive con ?? per righe precedenti alla
         * migrazione colonne.
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
                        'ai_provider' => (string) ( $agent->ai_provider ?? '' ),
                        // v1.1.10 (WP4): strumenti personali dell'agente. La chiave
                        // search NON viene mai restituita: solo il flag _set.
                        'search_provider' => (string) ( $agent->search_provider ?? '' ),
                        'search_key_set'  => ( 0 < strlen( (string) ( $agent->search_key_hash ?? '' ) ) ) ? 1 : 0,
                        'mcp_servers_text' => (string) ( $agent->mcp_servers_text ?? '' ),
                        'role'        => (string) ( $agent->role ?? 'giornalista' ),
                        'chief_scope' => (string) ( $agent->chief_scope ?? '' ),
                        'chief_term_id' => absint( $agent->chief_term_id ?? 0 ),
                        'last_run'    => self::date_or_empty( $agent->last_run ?? '' ),
                        'created_at'  => self::date_or_empty( $agent->created_at ?? '' ),
                        'updated_at'  => self::date_or_empty( $agent->updated_at ?? '' ),
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
         * Dalla v1.1.3 include anche wp_post_status, wp_edit_url e
         * wp_preview_url: campi SEMPRE presenti, stringa vuota quando il post
         * WP manca, l'utente non può modificarlo o il permalink pubblico è
         * già sufficiente.
         *
         * Dalla v1.1.4 include anche i campi del capo redattore chief_status,
         * chief_note, chief_reviewed_at, chief_agent, chief_agent_name e
         * chief_rounds: SEMPRE presenti, stringa vuota o 0 quando il capo
         * redattore non ha ancora revisionato l'articolo (o la colonna non
         * esiste ancora: letture difensive con ?? come stile del file).
         *
         * Dalla v1.1.6 le date (created_at, updated_at, published_at,
         * chief_reviewed_at) vuote o zero MySQL sono emesse come stringa
         * vuota (date_or_empty): mai una data finta nelle risposte.
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

                // v1.1.3: dati del post WP collegato (status, link modifica, link anteprima bozza).
                $wp_post_id = (int) ( $a->wp_post_id ?? 0 );
                $wp_post    = ( $wp_post_id > 0 && function_exists( 'get_post' ) ) ? get_post( $wp_post_id ) : null;
                $wp_status  = ( $wp_post instanceof WP_Post && function_exists( 'get_post_status' ) ) ? (string) get_post_status( $wp_post ) : '';

                // Link modifica: valorizzato solo se l'utente corrente può modificare il post.
                $wp_edit_url = '';
                if ( $wp_post instanceof WP_Post && function_exists( 'admin_url' ) && current_user_can( 'edit_post', $wp_post_id ) ) {
                        $wp_edit_url = esc_url_raw( admin_url( 'post.php?post=' . $wp_post_id . '&action=edit' ) );
                }

                // Link anteprima: solo per post non pubblicati (per i pubblicati c'è già post_permalink).
                $wp_preview_url = '';
                if ( $wp_post instanceof WP_Post && 'publish' !== $wp_status ) {
                        if ( function_exists( 'get_preview_post_link' ) ) {
                                $preview = get_preview_post_link( $wp_post );
                                if ( is_string( $preview ) && '' !== $preview ) {
                                        $wp_preview_url = esc_url_raw( $preview );
                                }
                        }
                        if ( '' === $wp_preview_url ) {
                                $wp_preview_url = esc_url_raw( home_url( '?p=' . $wp_post_id . '&preview=true' ) );
                        }
                }

                // v1.1.4: dati capo redattore (verdetto, nota, agente revisore, giri di revisione).
                $chief_agent_id = (int) ( $a->chief_agent_id ?? 0 );
                $chief_agent    = $chief_agent_id > 0 ? self::get_agent_cached( $chief_agent_id ) : null;

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
                        'wp_post_status' => $wp_status,
                        'wp_edit_url'   => $wp_edit_url,
                        'wp_preview_url' => $wp_preview_url,
                        'chief_status' => (string) ( $a->chief_status ?? '' ),
                        'chief_note' => (string) ( $a->chief_note ?? '' ),
                        'chief_reviewed_at' => self::date_or_empty( $a->chief_reviewed_at ?? '' ),
                        'chief_agent' => $chief_agent_id,
                        'chief_agent_name' => $chief_agent ? (string) $chief_agent->name : '',
                        'chief_rounds' => (int) ( $a->chief_rounds ?? 0 ),
                        'factcheck_score' => (float) ( $a->factcheck_score ?? 0 ),
                        'citations'     => (string) ( $a->citations ?? '' ),
                        'status'        => (string) $a->status,
                        'quality_score' => (float) $a->quality_score,
                        'read_time'     => (int) $a->read_time,
                        'views'         => (int) $a->views,
                        'reactions'     => is_array( $reactions ) ? $reactions : array(),
                        'excerpt'       => $excerpt,
                        'published_at'  => self::date_or_empty( $a->published_at ?? '' ),
                        'created_at'    => self::date_or_empty( $a->created_at ?? '' ),
                        'updated_at'    => self::date_or_empty( $a->updated_at ?? '' ),
                );

                if ( $with_content ) {
                        $out['content'] = (string) ( $a->content ?? '' );
                }

                return $out;
        }

        /**
         * Serializza un articolo per le rotte PUBBLICHE (v1.1.9): mai il contenuto
         * completo e MAI i campi interni del capo redattore (chief_status,
         * chief_note, chief_agent_name, chief_rounds: note e verdetto di revisione
         * sono materiale editoriale interno). citations RESTA: è contenuto pubblico
         * (fonti verificate dell'articolo).
         *
         * @param object $a            Riga articolo.
         * @param bool   $with_content Includere il contenuto completo (default false, lista).
         * @return array
         */
        private static function serialize_public_article( $a, $with_content = false ) {
                $out = self::serialize_article( $a, $with_content );

                // Campi interni del capo redattore: rimossi dalla superficie pubblica.
                unset(
                        $out['chief_status'],
                        $out['chief_note'],
                        $out['chief_agent_name'],
                        $out['chief_rounds']
                );

                return $out;
        }

        /**
         * Serializza un publish log per la risposta JSON.
         *
         * v1.1.6: published_at/created_at vuote o zero MySQL → stringa vuota
         * (date_or_empty), mai una data finta.
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
                        'published_at' => self::date_or_empty( $log->published_at ?? '' ),
                        'created_at'   => self::date_or_empty( $log->created_at ?? '' ),
                );
        }

        /**
         * Serializza un log attività per la risposta JSON.
         *
         * v1.1.6: created_at vuota o zero MySQL → stringa vuota (date_or_empty):
         * il feed attività non mostra mai '0000-00-00 00:00:00'.
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
                        'created_at' => self::date_or_empty( $row->created_at ?? '' ),
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
         * Data onesta per le risposte JSON (v1.1.6, regola ZERO MOCK).
         *
         * Le date zero MySQL ('0000-00-00 00:00:00' e ogni variante che inizia
         * con '0000-00-00') e le stringhe vuote diventano '': la UI mostrerà
         * vuoto/—, mai una data finta. Le date valide passano intatte (nessuna
         * riformattazione: lo shape storico delle risposte resta invariato).
         *
         * @param string|null $value Data letta dal DB (o assente).
         * @return string
         */
        private static function date_or_empty( $value ) {
                $value = trim( (string) $value );
                if ( '' === $value || 0 === strpos( $value, '0000-00-00' ) ) {
                        return '';
                }
                return $value;
        }

        /**
         * Serializza un lavoro della coda per la risposta JSON (v1.0.4).
         *
         * args è decodificato dal JSON (array, fallback array vuoto);
         * date e last_error null diventano stringa vuota. v1.1.6: anche le
         * date zero MySQL diventano stringa vuota (date_or_empty).
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
                        'scheduled_at' => self::date_or_empty( $job->scheduled_at ?? '' ),
                        'started_at'   => self::date_or_empty( $job->started_at ?? '' ),
                        'completed_at' => self::date_or_empty( $job->completed_at ?? '' ),
                        'last_error'   => (string) ( $job->last_error ?? '' ),
                        'created_at'   => self::date_or_empty( $job->created_at ?? '' ),
                        'updated_at'   => self::date_or_empty( $job->updated_at ?? '' ),
                );
        }

        /* =========================================================================
         * CALLBACK — ADMIN: STRUMENTI (v1.0.5)
         * ======================================================================= */

        /**
         * GET /export — esporta impostazioni e agenti come JSON (v1.0.5).
         *
         * ATTENZIONE (v1.1.9): i SEGRETI non sono più inclusi nell'export (valore ''
         * + chiave 'x_*_set' con la sola presenza): il file esportato può essere
         * archiviato senza esporre chiavi API e token. Al re-import i segreti vuoti
         * NON sovrascrivono quelli esistenti (guardia in rest_import).
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
                                // v1.1.10 (G6): il provider per-agente non deve più perdersi
                                // nell'export/import (prima era solo ai_model).
                                'ai_provider' => (string) ( $agent->ai_provider ?? '' ),
                        );
                }

                return rest_ensure_response(
                        array(
                                'version'     => defined( 'NEXUS_VERSION' ) ? NEXUS_VERSION : '',
                                'exported_at' => current_time( 'mysql' ),
                                'site_url'    => home_url( '/' ),
                                'settings'    => self::mask_secret_settings( Nexus_DB::get_settings() ),
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

                        // v1.1.9: 1) scarta le chiavi informative 'x_*_set' (solo GET/export);
                        // 2) i segreti VUOTI non sovrascrivono quelli esistenti: un export
                        // mascherato re-importato non può azzerare le chiavi del sito.
                        foreach ( array_keys( $filtered ) as $key ) {
                                if ( is_string( $key ) && 0 === strpos( $key, 'x_' ) && '_set' === substr( $key, -4 ) ) {
                                        unset( $filtered[ $key ] );
                                }
                        }
                        foreach ( self::secret_settings_keys() as $secret_key ) {
                                if ( isset( $filtered[ $secret_key ] ) && '' === trim( (string) $filtered[ $secret_key ] ) ) {
                                        unset( $filtered[ $secret_key ] );
                                }
                        }

                        // v1.1.9 (D6): la chiave API pubblica importata NON resta mai in
                        // chiaro — stesso contratto di PUT/keygen. Le chiavi legacy in
                        // chiaro importate da export vecchi vengono subito hashate; un
                        // valore hex-64 è considerato già un hash (passthrough).
                        if ( isset( $filtered['public_api_key'] ) ) {
                                $filtered['public_api_key'] = self::normalize_public_api_key( $filtered['public_api_key'] );
                        }

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
         * v1.1.9 (D6): in settings viene salvato SOLO l'hash SHA-256 della chiave
         * (hex-64, via normalize_public_api_key). Il valore IN CHIARO è restituito
         * UNA SOLA VOLTA in questa risposta JSON:
         *   - 'api_key_plain' — nome canonico (v1.1.9);
         *   - 'api_key'       — alias storico, stessa stringa, per compatibilità
         *                       con i client già esistenti (es. JS di tools.php);
         *   - 'message'       — invito a copiarla subito: non sarà più mostrata
         *                       né leggibile da alcun endpoint (il GET /settings
         *                       è mascherato da D2 e il DB contiene solo l'hash).
         * Se il client non salva la chiave mostrata, l'unica via è rigenerarla.
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

                // v1.1.9 (D6): in settings va solo l'hash — la chiave in chiaro vive
                // esclusivamente in questa risposta una-tantum.
                Nexus_DB::update_settings( array( 'public_api_key' => self::normalize_public_api_key( $key ) ) );

                return rest_ensure_response(
                        array(
                                'api_key'       => $key,
                                'api_key_plain' => $key,
                                'message'       => __( 'Chiave generata: copiala subito, non verrà più mostrata (nel database è salvato solo il suo hash SHA-256).', 'nexus-news-ai' ),
                        )
                );
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

        /* =========================================================================
         * CALLBACK — ADMIN: REDAZIONI (v1.1.1)
         * ======================================================================= */

        /**
         * Sanitizer per i parametri slug (v1.1.8).
         *
         * PERCHÉ ESISTE: WP invoca i sanitize_callback come ( $value, $request, $param )
         * e sanitize_title usa il 2° argomento come titolo di fallback quando il valore
         * è vuoto — con uno slug vuoto esplicito (es. slug="" in una PUT redazioni)
         * il parametro finiva per contenere l'oggetto WP_REST_Request e il handler
         * falliva con un fatal. Il wrapper sanifica SEMPRE e SOLO il valore.
         *
         * @param mixed $value Valore del parametro.
         * @return string
         */
        public static function sanitize_slug_param( $value ) {
                return sanitize_title( (string) $value );
        }

        /**
         * Sanitize callback per gli agent ID delle redazioni: delega a
         * Nexus_Redazioni::sanitize_agent_ids (helper di contratto); se il
         * modulo non fosse disponibile normalizza in interi positivi senza fatal.
         *
         * @param mixed $value Valore grezzo (array o scalare).
         * @return array
         */
        public static function sanitize_redazione_agent_ids( $value ) {
                if ( class_exists( 'Nexus_Redazioni' ) && method_exists( 'Nexus_Redazioni', 'sanitize_agent_ids' ) ) {
                        return Nexus_Redazioni::sanitize_agent_ids( $value );
                }
                $ids = is_array( $value ) ? $value : ( null === $value ? array() : array( $value ) );
                $ids = array_map( 'absint', $ids );
                $ids = array_filter( $ids, function ( $id ) {
                        return $id > 0;
                } );
                return array_values( array_unique( $ids ) );
        }

        /**
         * GET /redazioni — albero delle redazioni per la UI admin (v1.1.1).
         *
         * Lista piatta ordinata ad albero: ogni madre è subito seguita dal
         * suo sottoalbero, tra sorelle vale l'ordine per nome di all_terms().
         * Ogni riga porta depth per l'indentazione, gli agenti assegnati
         * risolti {id, name, avatar} via Nexus_DB::agents(), il count nativo
         * del termine e il count_subtree del ramo completo.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_redazioni_list( $request ) {
                unset( $request );

                if ( ! class_exists( 'Nexus_Redazioni' ) || ! method_exists( 'Nexus_Redazioni', 'all_terms' ) ) {
                        return self::err(
                                'nexus_rest_redazioni_missing',
                                __( 'Il modulo redazioni non è disponibile: verifica che il plugin sia aggiornato.', 'nexus-news-ai' ),
                                502
                        );
                }

                $terms      = Nexus_Redazioni::all_terms();
                $agents_map = self::redazioni_agents_map();
                $out        = array();

                foreach ( self::redazioni_tree_order( $terms ) as $row ) {
                        $term = $row['term'];
                        $tid  = (int) $term->term_id;
                        $out[] = array(
                                'id'            => $tid,
                                'name'          => (string) $term->name,
                                'slug'          => (string) $term->slug,
                                'parent'        => absint( $term->parent ),
                                'depth'         => (int) $row['depth'],
                                'agents'        => self::redazioni_agents_details( Nexus_Redazioni::agent_ids_for_term( $tid ), $agents_map ),
                                'count'         => (int) $term->count,
                                'count_subtree' => (int) Nexus_Redazioni::count_subtree( $tid ),
                        );
                }

                return rest_ensure_response( array( 'redazioni' => $out ) );
        }

        /**
         * POST /redazioni — crea una redazione (v1.1.1).
         *
         * Body: {name, parent, agent_ids[]}. Delega a Nexus_Redazioni::create
         * (wp_insert_term + assegnazione agenti) e propaga l'errore onesto di
         * WordPress (termine duplicato, madre inesistente, tassonomia assente).
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_redazioni_create( $request ) {
                if ( ! class_exists( 'Nexus_Redazioni' ) || ! method_exists( 'Nexus_Redazioni', 'create' ) ) {
                        return self::err(
                                'nexus_rest_redazioni_missing',
                                __( 'Il modulo redazioni non è disponibile: verifica che il plugin sia aggiornato.', 'nexus-news-ai' ),
                                502
                        );
                }

                $name = sanitize_text_field( (string) $request->get_param( 'name' ) );
                if ( '' === $name ) {
                        return self::err( 'nexus_rest_redazione_name_required', __( 'Il nome della redazione è obbligatorio.', 'nexus-news-ai' ), 400 );
                }

                $parent    = absint( $request->get_param( 'parent' ) );
                $agent_ids = Nexus_Redazioni::sanitize_agent_ids( $request->get_param( 'agent_ids' ) );

                $created = Nexus_Redazioni::create( $name, $parent, $agent_ids );
                if ( is_wp_error( $created ) ) {
                        return new WP_Error(
                                $created->get_error_code(),
                                $created->get_error_message(),
                                array( 'status' => 400 )
                        );
                }

                $response = rest_ensure_response(
                        array(
                                'term_id' => (int) ( $created['term_id'] ?? 0 ),
                                'agents'  => array_values( array_map( 'intval', (array) ( $created['agents'] ?? array() ) ) ),
                        )
                );
                $response->set_status( 201 );
                return $response;
        }

        /**
         * POST /redazioni/{id} — aggiorna una redazione esistente (v1.1.1).
         *
         * Body parziale: {name?, slug?, parent?, agent_ids?}. Nome con
         * sanitize_text_field, slug con sanitize_title solo se fornito,
         * madre con absint e validazione anti-ciclo: non può essere se stessa
         * né una propria sub-redazione (via descendant_ids). Se agent_ids è
         * presente (anche vuoto) sostituisce l'assegnazione via assign_agents.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_redazioni_update( $request ) {
                if ( ! class_exists( 'Nexus_Redazioni' ) || ! method_exists( 'Nexus_Redazioni', 'descendant_ids' ) ) {
                        return self::err(
                                'nexus_rest_redazioni_missing',
                                __( 'Il modulo redazioni non è disponibile: verifica che il plugin sia aggiornato.', 'nexus-news-ai' ),
                                502
                        );
                }

                $id   = absint( $request->get_param( 'id' ) );
                $term = get_term( $id, Nexus_Redazioni::TAX );
                if ( is_wp_error( $term ) || ! $term instanceof WP_Term ) {
                        return self::err( 'nexus_rest_redazione_not_found', __( 'Redazione non trovata.', 'nexus-news-ai' ), 404 );
                }

                $args = array();

                if ( null !== $request->get_param( 'name' ) ) {
                        $name = sanitize_text_field( (string) $request->get_param( 'name' ) );
                        if ( '' === $name ) {
                                return self::err( 'nexus_rest_redazione_name_required', __( 'Il nome della redazione non può essere vuoto.', 'nexus-news-ai' ), 400 );
                        }
                        $args['name'] = $name;
                }

                if ( null !== $request->get_param( 'slug' ) ) {
                        $slug = sanitize_title( (string) $request->get_param( 'slug' ) );
                        if ( '' !== $slug ) {
                                $args['slug'] = $slug;
                        }
                }

                if ( null !== $request->get_param( 'parent' ) ) {
                        $parent = absint( $request->get_param( 'parent' ) );
                        if ( $parent > 0 ) {
                                if ( $parent === $id ) {
                                        return self::err( 'nexus_rest_redazione_parent_self', __( 'Una redazione non può essere madre di se stessa.', 'nexus-news-ai' ), 400 );
                                }
                                $parent_term = get_term( $parent, Nexus_Redazioni::TAX );
                                if ( is_wp_error( $parent_term ) || ! $parent_term instanceof WP_Term ) {
                                        return self::err( 'nexus_rest_redazione_parent_not_found', __( 'Redazione madre non trovata.', 'nexus-news-ai' ), 404 );
                                }
                                if ( in_array( $parent, Nexus_Redazioni::descendant_ids( $id ), true ) ) {
                                        return self::err( 'nexus_rest_redazione_parent_cycle', __( 'La redazione madre scelta è una sub-redazione di questa: lo spostamento creerebbe un ciclo.', 'nexus-news-ai' ), 400 );
                                }
                                $args['parent'] = $parent;
                        } else {
                                $args['parent'] = 0;
                        }
                }

                $agent_ids_param = $request->get_param( 'agent_ids' );
                $has_agent_ids   = null !== $agent_ids_param;

                if ( empty( $args ) && ! $has_agent_ids ) {
                        return self::err( 'nexus_rest_redazione_no_fields', __( 'Nessun campo da aggiornare.', 'nexus-news-ai' ), 400 );
                }

                if ( ! empty( $args ) ) {
                        $updated = wp_update_term( $id, Nexus_Redazioni::TAX, $args );
                        if ( is_wp_error( $updated ) ) {
                                return new WP_Error(
                                        $updated->get_error_code(),
                                        $updated->get_error_message(),
                                        array( 'status' => 400 )
                                );
                        }
                }

                if ( $has_agent_ids ) {
                        Nexus_Redazioni::assign_agents( $id, Nexus_Redazioni::sanitize_agent_ids( $agent_ids_param ) );
                }

                $fresh = get_term( $id, Nexus_Redazioni::TAX );
                $view  = ( $fresh instanceof WP_Term ) ? $fresh : $term;

                return rest_ensure_response(
                        array(
                                'ok'   => true,
                                'term' => array(
                                        'id'     => (int) $view->term_id,
                                        'name'   => (string) $view->name,
                                        'slug'   => (string) $view->slug,
                                        'parent' => absint( $view->parent ),
                                        'agents' => self::redazioni_agents_details( Nexus_Redazioni::agent_ids_for_term( $id ), self::redazioni_agents_map() ),
                                ),
                        )
                );
        }

        /**
         * DELETE /redazioni/{id} — elimina una redazione (v1.1.1).
         *
         * wp_delete_term nativo: WordPress riassegna da solo le sub-redazioni
         * alla redazione madre precedente e gli articoli perdono SOLO questo
         * termine (le altre assegnazioni restano). Messaggio onesto su cosa
         * è successo davvero.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_redazioni_delete( $request ) {
                if ( ! class_exists( 'Nexus_Redazioni' ) ) {
                        return self::err(
                                'nexus_rest_redazioni_missing',
                                __( 'Il modulo redazioni non è disponibile: verifica che il plugin sia aggiornato.', 'nexus-news-ai' ),
                                502
                        );
                }

                $id   = absint( $request->get_param( 'id' ) );
                $term = get_term( $id, Nexus_Redazioni::TAX );
                if ( is_wp_error( $term ) || ! $term instanceof WP_Term ) {
                        return self::err( 'nexus_rest_redazione_not_found', __( 'Redazione non trovata.', 'nexus-news-ai' ), 404 );
                }

                // Sub-redazioni dirette prima dell'eliminazione, per il messaggio onesto.
                $children = get_terms(
                        array(
                                'taxonomy'   => Nexus_Redazioni::TAX,
                                'parent'     => $id,
                                'hide_empty' => false,
                                'fields'     => 'ids',
                        )
                );
                $children_count = ( is_wp_error( $children ) || ! is_array( $children ) ) ? 0 : count( $children );

                $deleted = wp_delete_term( $id, Nexus_Redazioni::TAX );
                if ( is_wp_error( $deleted ) ) {
                        return new WP_Error(
                                $deleted->get_error_code(),
                                $deleted->get_error_message(),
                                array( 'status' => 500 )
                        );
                }
                if ( true !== $deleted ) {
                        return self::err( 'nexus_rest_redazione_delete_failed', __( 'Eliminazione della redazione non riuscita.', 'nexus-news-ai' ), 500 );
                }

                if ( $children_count > 0 ) {
                        $message = sprintf(
                                /* translators: 1: nome redazione, 2: numero sub-redazioni. */
                                __( 'Redazione "%1$s" eliminata. Le %2$d sub-redazioni sono state riassegnate da WordPress alla redazione madre precedente (o al primo livello se non esisteva); gli articoli hanno perso solo questo termine e mantengono le altre redazioni assegnate.', 'nexus-news-ai' ),
                                $term->name,
                                $children_count
                        );
                } else {
                        $message = sprintf(
                                /* translators: %s: nome redazione. */
                                __( 'Redazione "%s" eliminata. Gli articoli hanno perso solo questo termine e mantengono le altre redazioni assegnate.', 'nexus-news-ai' ),
                                $term->name
                        );
                }

                return rest_ensure_response( array( 'ok' => true, 'message' => $message ) );
        }

        /**
         * Mappa agent ID → dettagli minimi (id, nome, avatar) con una sola
         * query via Nexus_DB::agents(). Se il DB non fosse risolvibile la
         * mappa resta vuota e gli agenti vengono omessi: la lista resta valida.
         *
         * @return array
         */
        private static function redazioni_agents_map() {
                $map = array();
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'agents' ) ) {
                        return $map;
                }
                foreach ( Nexus_DB::agents() as $row ) {
                        $aid         = (int) $row->id;
                        $map[ $aid ] = array(
                                'id'     => $aid,
                                'name'   => (string) $row->name,
                                'avatar' => (string) $row->avatar,
                        );
                }
                return $map;
        }

        /**
         * Dettagli degli agenti assegnati (solo ID realmente esistenti).
         *
         * @param array $agent_ids  Agent ID assegnati al termine.
         * @param array $agents_map Mappa di redazioni_agents_map().
         * @return array Array {id, name, avatar}.
         */
        private static function redazioni_agents_details( $agent_ids, $agents_map ) {
                $out = array();
                foreach ( (array) $agent_ids as $agent_id ) {
                        $agent_id = (int) $agent_id;
                        if ( isset( $agents_map[ $agent_id ] ) ) {
                                $out[] = $agents_map[ $agent_id ];
                        }
                }
                return $out;
        }

        /**
         * Ordina i termini come albero per la risposta della lista.
         *
         * Ordine DFS dalla radice: ogni madre è subito seguita dal suo
         * sottoalbero, tra sorelle vale l'ordine per nome di all_terms().
         * La profondità è calcolata risalendo la catena delle madri, con
         * guardia anti-ciclo su dati pregressi anomali; i termini orfani
         * (madre inesistente) vanno in coda nell'ordine naturale.
         *
         * @param array $terms WP_Term[] da Nexus_Redazioni::all_terms().
         * @return array Array di coppie {term: WP_Term, depth: int}.
         */
        private static function redazioni_tree_order( $terms ) {
                $by_id = array();
                foreach ( $terms as $term ) {
                        $by_id[ (int) $term->term_id ] = $term;
                }

                $depth_of = array();
                foreach ( $by_id as $tid => $term ) {
                        $depth  = 0;
                        $cursor = absint( $term->parent );
                        $guard  = array();
                        while ( $cursor > 0 && isset( $by_id[ $cursor ] ) && ! isset( $guard[ $cursor ] ) ) {
                                $guard[ $cursor ] = true;
                                $depth++;
                                $cursor = absint( $by_id[ $cursor ]->parent );
                        }
                        $depth_of[ $tid ] = $depth;
                }

                $children = array();
                foreach ( $terms as $term ) {
                        $parent = absint( $term->parent );
                        if ( ! isset( $children[ $parent ] ) ) {
                                $children[ $parent ] = array();
                        }
                        $children[ $parent ][] = (int) $term->term_id;
                }

                $ordered = array();
                $stack   = array( 0 );
                $seen    = array();
                while ( ! empty( $stack ) ) {
                        $current = array_pop( $stack );
                        if ( $current > 0 && isset( $by_id[ $current ] ) && ! isset( $seen[ $current ] ) ) {
                                $seen[ $current ] = true;
                                $ordered[]        = array(
                                        'term'  => $by_id[ $current ],
                                        'depth' => isset( $depth_of[ $current ] ) ? (int) $depth_of[ $current ] : 0,
                                );
                        }
                        if ( empty( $children[ $current ] ) ) {
                                continue;
                        }
                        // Pop consuma dall'ultimo: push inverso per mantenere l'ordine per nome.
                        foreach ( array_reverse( $children[ $current ] ) as $kid ) {
                                if ( ! isset( $seen[ $kid ] ) ) {
                                        $stack[] = $kid;
                                }
                        }
                }

                foreach ( $terms as $term ) {
                        $tid = (int) $term->term_id;
                        if ( ! isset( $seen[ $tid ] ) ) {
                                $ordered[] = array(
                                        'term'  => $term,
                                        'depth' => isset( $depth_of[ $tid ] ) ? (int) $depth_of[ $tid ] : 0,
                                );
                        }
                }

                return $ordered;
        }

        /* =========================================================================
         * CALLBACK — ADMIN: MULTILINGUA (v1.1.2)
         * ======================================================================= */

        /**
         * GET /multilang/info — driver attivo e lingue del sito (v1.1.2).
         *
         * Risposta: {driver, default, current, langs: [{code, name, default}]}.
         * Fonte unica Nexus_Multilang: con Polylang o WPML attivi i dati vengono
         * dalle loro API ufficiali, altrimenti dal collegamento interno del plugin.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_multilang_info( $request ) {
                unset( $request );

                if ( ! class_exists( 'Nexus_Multilang' ) ) {
                        return self::err(
                                'nexus_rest_multilang_missing',
                                __( 'Il modulo multilingua non è disponibile: verifica che il plugin sia aggiornato.', 'nexus-news-ai' ),
                                500
                        );
                }

                $default = sanitize_key( (string) Nexus_Multilang::default_lang() );
                $current = sanitize_key( (string) Nexus_Multilang::current_lang() );
                $langs   = array();

                foreach ( (array) Nexus_Multilang::site_langs() as $code ) {
                        $code = sanitize_key( (string) $code );
                        if ( '' === $code ) {
                                continue;
                        }
                        $langs[] = array(
                                'code'    => $code,
                                'name'    => (string) Nexus_Multilang::lang_name( $code ),
                                'default' => ( $code === $default ),
                        );
                }

                return rest_ensure_response(
                        array(
                                'driver'  => sanitize_key( (string) Nexus_Multilang::driver() ),
                                'default' => $default,
                                'current' => $current,
                                'langs'   => $langs,
                        )
                );
        }

        /**
         * POST /multilang/translate-post — pubblica la traduzione come post WP (v1.1.2).
         *
         * Body: {article_id, lang}. Delega tutto a Nexus_Translate::translate_to_post
         * (traduzione AI, upsert del post nativo e collegamento al gruppo lingua).
         * Errori onesti: 400 per le guardie su articolo e lingua, 500 se la
         * traduzione AI fallisce (messaggio del modulo traduzioni riportato intero).
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_multilang_translate_post( $request ) {
                $article_id = absint( $request->get_param( 'article_id' ) );
                $lang       = sanitize_key( (string) $request->get_param( 'lang' ) );

                if ( $article_id <= 0 ) {
                        return self::err( 'nexus_rest_article_id_missing', __( 'Parametro article_id mancante.', 'nexus-news-ai' ), 400 );
                }

                if ( '' === $lang ) {
                        return self::err( 'nexus_rest_lang_missing', __( 'Parametro lang mancante o non valido.', 'nexus-news-ai' ), 400 );
                }

                $article = Nexus_DB::get_article( $article_id );
                if ( ! $article ) {
                        return self::err( 'nexus_rest_article_not_found', __( 'Articolo non trovato.', 'nexus-news-ai' ), 404 );
                }

                if ( ! class_exists( 'Nexus_Translate' ) || ! method_exists( 'Nexus_Translate', 'translate_to_post' ) ) {
                        return self::err( 'nexus_rest_translate_missing', __( 'Il modulo traduzioni non è disponibile: verifica che il plugin sia aggiornato.', 'nexus-news-ai' ), 500 );
                }

                $result = Nexus_Translate::translate_to_post( $article_id, $lang );

                if ( ! is_array( $result ) ) {
                        return self::err( 'nexus_rest_translate_failed', __( 'Il modulo traduzioni non ha restituito un risultato valido.', 'nexus-news-ai' ), 500 );
                }

                if ( empty( $result['ok'] ) ) {
                        $error = isset( $result['error'] ) ? trim( (string) $result['error'] ) : '';
                        return self::err(
                                'nexus_rest_translate_failed',
                                '' !== $error ? $error : __( 'Traduzione non riuscita per un motivo non specificato dal modulo traduzioni.', 'nexus-news-ai' ),
                                self::multilang_translate_error_status( $error )
                        );
                }

                $post_id  = isset( $result['post_id'] ) ? absint( $result['post_id'] ) : 0;
                $out_lang = isset( $result['lang'] ) ? sanitize_key( (string) $result['lang'] ) : '';
                if ( '' === $out_lang ) {
                        $out_lang = $lang;
                }

                $links = self::multilang_post_links( $post_id );

                return rest_ensure_response(
                        array(
                                'ok'        => true,
                                'already'   => ! empty( $result['already'] ),
                                'lang'      => $out_lang,
                                'post_id'   => $post_id,
                                'edit_url'  => $links['edit_url'],
                                'permalink' => $links['permalink'],
                        )
                );
        }

        /**
         * GET /multilang/translations-posts — traduzioni native di un articolo (v1.1.2).
         *
         * Args: article_id. Risposta: {article_id, source_post_id, source_lang,
         * note, translations: [{lang, post_id, edit_url, permalink, status,
         * modified, is_source}]}. Articolo senza post WP nativo: source_post_id 0
         * con nota onesta (risposta 200, non è un errore). Il permalink è
         * valorizzato solo per i post pubblicati.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_multilang_translations_posts( $request ) {
                $article_id = absint( $request->get_param( 'article_id' ) );

                if ( $article_id <= 0 ) {
                        return self::err( 'nexus_rest_article_id_missing', __( 'Parametro article_id mancante.', 'nexus-news-ai' ), 400 );
                }

                if ( ! Nexus_DB::get_article( $article_id ) ) {
                        return self::err( 'nexus_rest_article_not_found', __( 'Articolo non trovato.', 'nexus-news-ai' ), 404 );
                }

                if ( ! class_exists( 'Nexus_Translate' ) || ! method_exists( 'Nexus_Translate', 'native_post_for_article' ) ) {
                        return self::err( 'nexus_rest_translate_missing', __( 'Il modulo traduzioni non è disponibile: verifica che il plugin sia aggiornato.', 'nexus-news-ai' ), 500 );
                }

                $source_post_id = absint( Nexus_Translate::native_post_for_article( $article_id ) );

                $note         = '';
                $source_lang  = '';
                $translations = array();

                if ( $source_post_id <= 0 ) {
                        $note = __( 'L\'articolo non ha ancora un post WordPress nativo: pubblicalo per attivare le traduzioni come post.', 'nexus-news-ai' );
                } elseif ( class_exists( 'Nexus_Multilang' ) && method_exists( 'Nexus_Multilang', 'translations_of' ) && method_exists( 'Nexus_Multilang', 'lang_of_post' ) ) {
                        $source_lang = sanitize_key( (string) Nexus_Multilang::lang_of_post( $source_post_id ) );

                        $map = (array) Nexus_Multilang::translations_of( $source_post_id );
                        ksort( $map );

                        // Prima la riga sorgente, poi le traduzioni in ordine di codice lingua.
                        foreach ( array( true, false ) as $want_source ) {
                                foreach ( $map as $code => $pid ) {
                                        $is_source = ( absint( $pid ) === $source_post_id );
                                        if ( $is_source !== $want_source ) {
                                                continue;
                                        }
                                        $translations[] = self::multilang_translation_row( $code, absint( $pid ), $is_source );
                                }
                        }
                } else {
                        $note = __( 'Il modulo multilingua non è disponibile: impossibile elencare le traduzioni collegate.', 'nexus-news-ai' );
                }

                return rest_ensure_response(
                        array(
                                'article_id'     => $article_id,
                                'source_post_id' => $source_post_id,
                                'source_lang'    => $source_lang,
                                'note'           => $note,
                                'translations'   => $translations,
                        )
                );
        }

        /**
         * Link di modifica e permalink pubblico di un post WP (v1.1.2).
         *
         * Il permalink è valorizzato SOLO se il post è pubblicato: bozze e
         * stati intermedi non hanno un URL pubblico stabile.
         *
         * @param int $post_id Post WP.
         * @return array {edit_url: string, permalink: string}
         */
        private static function multilang_post_links( $post_id ) {
                $post_id = absint( $post_id );

                $edit_url = '';
                if ( $post_id > 0 && function_exists( 'admin_url' ) ) {
                        $edit_url = esc_url_raw( admin_url( 'post.php?post=' . $post_id . '&action=edit' ) );
                }

                $permalink = '';
                if ( $post_id > 0 && function_exists( 'get_permalink' ) && function_exists( 'get_post_status' ) && 'publish' === get_post_status( $post_id ) ) {
                        $link = get_permalink( $post_id );
                        if ( is_string( $link ) && '' !== $link ) {
                                $permalink = esc_url_raw( $link );
                        }
                }

                return array(
                        'edit_url'  => $edit_url,
                        'permalink' => $permalink,
                );
        }

        /**
         * Riga di stato per un post della catena di traduzione (v1.1.2).
         *
         * @param string $lang      Codice lingua.
         * @param int    $post_id   Post WP.
         * @param bool   $is_source True se il post è la sorgente del gruppo.
         * @return array
         */
        private static function multilang_translation_row( $lang, $post_id, $is_source ) {
                $post   = ( $post_id > 0 && function_exists( 'get_post' ) ) ? get_post( $post_id ) : null;
                $status = ( $post instanceof WP_Post && function_exists( 'get_post_status' ) ) ? get_post_status( $post ) : '';

                $modified = '';
                if ( $post instanceof WP_Post && function_exists( 'get_the_modified_date' ) ) {
                        $date     = get_the_modified_date( 'c', $post );
                        $modified = is_string( $date ) ? $date : '';
                }

                $links = self::multilang_post_links( $post_id );

                return array(
                        'lang'      => sanitize_key( (string) $lang ),
                        'post_id'   => $post_id,
                        'edit_url'  => $links['edit_url'],
                        'permalink' => $links['permalink'],
                        'status'    => sanitize_key( (string) $status ),
                        'modified'  => $modified,
                        'is_source' => (bool) $is_source,
                );
        }

        /**
         * Status HTTP onesto per un errore restituito da translate_to_post.
         *
         * Le guardie su articolo e lingua (nessun post WP nativo, lingua uguale
         * alla sorgente, traduzioni disattivate) sono condizioni richiedibili
         * dal client: 400. Gli altri casi (AI fallita, database) sono interni: 500.
         * La classificazione legge il messaggio del modulo, mai il suo funzionamento.
         *
         * @param string $error Messaggio di errore del modulo traduzioni.
         * @return int
         */
        private static function multilang_translate_error_status( $error ) {
                $error  = (string) $error;
                $guards = array( 'WordPress nativo', 'in questa lingua', 'disattivate', 'non attive' );

                foreach ( $guards as $needle ) {
                        if ( '' !== $needle && false !== stripos( $error, $needle ) ) {
                                return 400;
                        }
                }

                return 500;
        }

        /* =========================================================================
         * CALLBACK — ADMIN: CAPO REDATTORE E STRUMENTI DI RICERCA (v1.1.4)
         * ======================================================================= */

        /**
         * GET /tools/catalog — catalogo strumenti di ricerca e provider AI (v1.1.4).
         *
         * Delega a Nexus_Tools::catalog(): shape {enabled, search_providers,
         * search_provider, has_search_key, mcp, providers} (providers è il
         * catalogo Nexus_Providers, serve alla select provider dell'editor
         * agenti). Non richiede strumenti attivi: è lettura di configurazione.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_tools_catalog( $request ) {
                unset( $request );

                if ( ! class_exists( 'Nexus_Tools' ) || ! method_exists( 'Nexus_Tools', 'catalog' ) ) {
                        return self::err(
                                'nexus_rest_tools_missing',
                                __( 'Gli strumenti di ricerca non sono disponibili: verifica che il plugin sia aggiornato.', 'nexus-news-ai' ),
                                502
                        );
                }

                $catalog = Nexus_Tools::catalog();

                return rest_ensure_response( is_array( $catalog ) ? $catalog : array() );
        }

        /**
         * POST /tools/test — test onesto di uno strumento di ricerca (v1.1.4).
         *
         * Body JSON: {tool: 'search'|'read'|'feeds'|'mcp', ...args}:
         *   search {query, max?}          → Nexus_Tools::search_web
         *   read   {url}                  → Nexus_Tools::read_page
         *   feeds  {url}                  → Nexus_Tools::discover_feeds
         *   mcp    {server, tool_name, args?} → Nexus_Tools::mcp_call
         * Il nome dello strumento MCP viaggia su tool_name (fallback mcp_tool)
         * per non collidere con il selettore 'tool' del tipo di test.
         * Risposta: {ok, tool, result, error} con result = JSON serializzato e
         * clipped a 4000 caratteri (anteprima, mai un dump completo). Non
         * richiede tools_enabled: serve proprio per provare la configurazione
         * prima di attivare. Solo per test dalla UI impostazioni: nessuna
         * scrittura su DB.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_tools_test( $request ) {
                if ( ! class_exists( 'Nexus_Tools' ) ) {
                        return self::err(
                                'nexus_rest_tools_missing',
                                __( 'Gli strumenti di ricerca non sono disponibili: verifica che il plugin sia aggiornato.', 'nexus-news-ai' ),
                                502
                        );
                }

                $tool = sanitize_key( (string) $request->get_param( 'tool' ) );
                if ( ! in_array( $tool, array( 'search', 'read', 'feeds', 'mcp' ), true ) ) {
                        return self::err(
                                'nexus_rest_invalid_tool',
                                __( 'Strumento non valido. Valori ammessi: search, read, feeds, mcp.', 'nexus-news-ai' ),
                                400
                        );
                }

                $tool_methods = array(
                        'search' => 'search_web',
                        'read'   => 'read_page',
                        'feeds'  => 'discover_feeds',
                        'mcp'    => 'mcp_call',
                );
                if ( ! method_exists( 'Nexus_Tools', $tool_methods[ $tool ] ) ) {
                        return self::err(
                                'nexus_rest_tools_missing',
                                __( 'Gli strumenti di ricerca non sono disponibili: verifica che il plugin sia aggiornato.', 'nexus-news-ai' ),
                                502
                        );
                }

                $result = array();

                try {
                        if ( 'search' === $tool ) {
                                $query = trim( (string) $request->get_param( 'query' ) );
                                if ( '' === $query ) {
                                        return self::err( 'nexus_rest_tool_query_missing', __( 'Parametro query mancante per il test di ricerca.', 'nexus-news-ai' ), 400 );
                                }
                                $result = Nexus_Tools::search_web( $query, absint( $request->get_param( 'max' ) ) );
                        } elseif ( 'read' === $tool ) {
                                $url = esc_url_raw( (string) $request->get_param( 'url' ) );
                                if ( '' === $url ) {
                                        return self::err( 'nexus_rest_tool_url_missing', __( 'Parametro url mancante per il test di lettura pagina.', 'nexus-news-ai' ), 400 );
                                }
                                $result = Nexus_Tools::read_page( $url );
                        } elseif ( 'feeds' === $tool ) {
                                $url = esc_url_raw( (string) $request->get_param( 'url' ) );
                                if ( '' === $url ) {
                                        return self::err( 'nexus_rest_tool_url_missing', __( 'Parametro url mancante per il test di RSS discovery.', 'nexus-news-ai' ), 400 );
                                }
                                $result = Nexus_Tools::discover_feeds( $url );
                        } else {
                                $server = sanitize_key( (string) $request->get_param( 'server' ) );
                                $mcp_tool = sanitize_text_field( (string) $request->get_param( 'tool_name' ) );
                                if ( '' === $mcp_tool ) {
                                        $mcp_tool = sanitize_text_field( (string) $request->get_param( 'mcp_tool' ) );
                                }
                                if ( '' === $server ) {
                                        return self::err( 'nexus_rest_tool_server_missing', __( 'Parametro server mancante per il test MCP.', 'nexus-news-ai' ), 400 );
                                }
                                if ( '' === $mcp_tool ) {
                                        return self::err( 'nexus_rest_tool_mcp_tool_missing', __( 'Parametro tool_name mancante per il test MCP.', 'nexus-news-ai' ), 400 );
                                }
                                $mcp_args = $request->get_param( 'args' );
                                $result   = Nexus_Tools::mcp_call( $server, $mcp_tool, is_array( $mcp_args ) ? $mcp_args : array() );
                        }
                } catch ( Throwable $e ) {
                        // Mai fatal: l'errore del modulo strumenti diventa esito del test.
                        $result = array( 'ok' => false, 'error' => sanitize_text_field( $e->getMessage() ) );
                }

                if ( ! is_array( $result ) ) {
                        $result = array( 'data' => $result );
                }

                $error = isset( $result['error'] ) ? trim( (string) $result['error'] ) : '';
                $ok    = array_key_exists( 'ok', $result ) ? (bool) $result['ok'] : true;
                if ( '' !== $error ) {
                        $ok = false;
                }

                // Clip 4000: la risposta resta un'anteprima compatta per la UI.
                $json = wp_json_encode( $result );
                if ( ! is_string( $json ) ) {
                        $json = '';
                }
                if ( mb_strlen( $json ) > 4000 ) {
                        $json = mb_substr( $json, 0, 4000 );
                }

                return rest_ensure_response(
                        array(
                                'ok'     => $ok,
                                'tool'   => $tool,
                                'result' => $json,
                                'error'  => $error,
                        )
                );
        }

        /**
         * POST /sources/test — test di UNA fonte della redazione (v1.1.10, WP8).
         *
         * Body JSON: {type, url, query?, redazione_id?} con type in
         * Nexus_Redazioni::TYPES (rss|site|youtube|telegram|reddit|search|mcp|web)
         * e url = URL/valore della fonte (per i tipi non URL-based è l'handle:
         * @canale, r/subreddit, parola chiave, "server|tool|{args}").
         *
         * MECCANICA (decisione WP8 documentata): gli adapter per tipo di
         * Nexus_Sources (fetch_rss, fetch_youtube, ...) sono tutti private,
         * quindi il test li invoca via ReflectionMethod::setAccessible() —
         * accettato e documentato nel piano WP8 in alternativa a una modifica
         * di class-nexus-sources.php (fuori perimetro). Costruito un array-fonte
         * fittizio {type, url, query?} identico alla shape del term meta.
         *
         * Effetti collaterali controllati:
         * - cooldown/fallimenti della fonte (transient nexus_src_cool_/fail_)
         *   vengono PULITI prima del test (un test deve sempre provare davvero)
         * e, se il test fallisce, il cooldown viene ripulito anche dopo (un
         * test manuale non deve mandare in cooldown la fonte nel collect reale);
         * - il budget full-content del run (statico privato) viene portato
         *   temporaneamente al valore FULL_CONTENT_BUDGET (best-effort, reflection)
         * così una fonte full=1 viene provata con l'estrazione Readability.
         *
         * Risposta: SEMPRE 200 con
         *   {ok: bool, items_found: int, sample: [{title, link}] (max 3),
         *    source_type: string, redazione_id?: int, error?: string}
         * — ok:false sull'errore (stesso pattern di /tools/test e /ai/test:
         * l'esito del test è un dato, non un errore HTTP).
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response
         */
        public static function rest_sources_test( $request ) {
                if ( ! class_exists( 'Nexus_Sources' ) ) {
                        return rest_ensure_response(
                                array(
                                        'ok'          => false,
                                        'source_type' => '',
                                        'items_found' => 0,
                                        'sample'      => array(),
                                        'error'       => __( 'Il modulo fonti non è disponibile: verifica che il plugin sia aggiornato.', 'nexus-news-ai' ),
                                )
                        );
                }

                // PHP 7.4: la costante di classe si legge direttamente con la guardia
                // class_exists (defined('Class::CONST') esiste solo da PHP 8.0).
                $types = array( 'rss', 'site', 'youtube', 'telegram', 'reddit', 'search', 'mcp', 'web' );
                if ( class_exists( 'Nexus_Redazioni' ) && ! empty( Nexus_Redazioni::TYPES ) && is_array( Nexus_Redazioni::TYPES ) ) {
                        $types = array_map( 'strval', (array) Nexus_Redazioni::TYPES );
                }

                $type = sanitize_key( (string) $request->get_param( 'type' ) );
                if ( ! in_array( $type, $types, true ) ) {
                        return rest_ensure_response(
                                array(
                                        'ok'          => false,
                                        'source_type' => $type,
                                        'items_found' => 0,
                                        'sample'      => array(),
                                        'error'       => __( 'Tipo di fonte non valido. Valori ammessi: rss, site, youtube, telegram, reddit, search, mcp, web.', 'nexus-news-ai' ),
                                )
                        );
                }

                // Valore fonte: per i tipi URL-based (rss/site/web) normalizza con
                // esc_url_raw (come sanitize_sources), per gli altri toglie solo i
                // caratteri di controllo (l'handle può contenere '@', '|', ecc.).
                $value = trim( (string) $request->get_param( 'url' ) );
                $value = trim( preg_replace( '/[\x00-\x1F\x7F]/', '', $value ) );
                if ( in_array( $type, array( 'rss', 'site', 'web' ), true ) ) {
                        $value = esc_url_raw( $value );
                }
                if ( '' === $value ) {
                        return rest_ensure_response(
                                array(
                                        'ok'          => false,
                                        'source_type' => $type,
                                        'items_found' => 0,
                                        'sample'      => array(),
                                        'error'       => __( 'Valore della fonte mancante.', 'nexus-news-ai' ),
                                )
                        );
                }

                // Array-fonte fittizio, stessa shape del term meta _nexus_sources.
                $source = array(
                        'type' => $type,
                        'url'  => $value,
                );
                $query  = trim( (string) $request->get_param( 'query' ) );
                if ( '' !== $query ) {
                        $source['query'] = sanitize_text_field( $query );
                }

                $redazione_id = absint( $request->get_param( 'redazione_id' ) );

                // Chiave stato della fonte (stessa formula di Nexus_Sources::state_key):
                // serve per pulire cooldown/fallimenti PRIMA del test.
                $key = md5( $type . '|' . trim( $value ) );
                delete_transient( 'nexus_src_cool_' . $key );
                delete_transient( 'nexus_src_fail_' . $key );

                // Budget full-content del "run" di test: i fetch_* non lo resettano
                // (lo fa solo fetch_for_agent), quindi da 0 non proverebbe mai il
                // Readability. Best-effort via reflection: se qualcosa cambia nella
                // classe il test continua comunque (solo senza full-content).
                try {
                        // constant() con sintassi Class::CONST è disponibile da PHP 5.5
                        // (a differenza di defined(): vedi nota sopra); se la costante
                        // non esisse, l'Error viene intercettato qui sotto.
                        $budget_value = (int) constant( 'Nexus_Sources::FULL_CONTENT_BUDGET' );
                        $budget = new ReflectionProperty( 'Nexus_Sources', 'full_budget_left' );
                        $budget->setAccessible( true );
                        $budget->setValue( null, $budget_value );
                } catch ( Throwable $e ) {
                        // Budget non impostabile: il test prosegue senza full-content.
                }

                $adapter = 'fetch_' . $type;
                $items   = array();
                $error   = '';

                if ( ! method_exists( 'Nexus_Sources', $adapter ) ) {
                        $error = __( 'Nessun adapter disponibile per questo tipo di fonte.', 'nexus-news-ai' );
                } else {
                        try {
                                $method = new ReflectionMethod( 'Nexus_Sources', $adapter );
                                $method->setAccessible( true ); // PHP 7.4: richiesto per i metodi private.
                                $invoked = $method->invoke( null, $source );
                                if ( is_array( $invoked ) ) {
                                        $items = $invoked;
                                }
                        } catch ( Throwable $e ) {
                                // Mai fatal: l'errore dell'adapter diventa esito del test.
                                $error = self::clip_sources_test( $e->getMessage(), 200 );
                        }
                }

                // Un test fallito NON deve lasciare la fonte in cooldown per il
                // collect reale: ripulisce cooldown e contatore fallimenti.
                if ( '' !== $error || empty( $items ) ) {
                        delete_transient( 'nexus_src_cool_' . $key );
                        delete_transient( 'nexus_src_fail_' . $key );
                }

                // Sample: primi 3 item {title, link} (sanificati per il transito JSON).
                $sample = array();
                foreach ( array_slice( $items, 0, 3 ) as $item ) {
                        if ( ! is_array( $item ) ) {
                                continue;
                        }
                        $sample[] = array(
                                'title' => sanitize_text_field( (string) ( isset( $item['title'] ) ? $item['title'] : '' ) ),
                                'link'  => esc_url_raw( (string) ( isset( $item['link'] ) ? $item['link'] : '' ) ),
                        );
                }

                $response = array(
                        'ok'           => '' === $error,
                        'items_found'  => count( $items ),
                        'sample'       => $sample,
                        'source_type'  => $type,
                );
                if ( $redazione_id > 0 ) {
                        $response['redazione_id'] = $redazione_id;
                }
                if ( '' !== $error ) {
                        $response['error'] = $error;
                }

                return rest_ensure_response( $response );
        }

        /**
         * Clip locale per il messaggio d'errore del test fonti (multibyte-safe
         * con fallback substr): non riutilizza Nexus_Sources::clip perché private.
         *
         * @param string $text   Testo di partenza.
         * @param int    $length Lunghezza massima.
         * @return string
         */
        private static function clip_sources_test( $text, $length ) {
                $text = trim( (string) $text );
                if ( function_exists( 'mb_substr' ) ) {
                        return mb_substr( $text, 0, (int) $length );
                }
                return substr( $text, 0, (int) $length );
        }

        /**
         * POST /chief/run/{id} — forza la revisione del capo redattore (v1.1.4).
         *
         * Delega a Nexus_Chief::review_article: il capo legge l'articolo (con
         * strumenti attivi verifica anche le fonti) e applica il verdetto
         * secondo le impostazioni (auto publish o badge in bozza). Risposta:
         * {ok, chief_status, note} con lo stato capo redattore e la nota
         * presenti sull'articolo dopo la revisione. Se il capo redattore non
         * è attivo la risposta è 400 con messaggio operativo.
         *
         * @param WP_REST_Request $request Request.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_chief_run( $request ) {
                if ( ! class_exists( 'Nexus_Chief' ) || ! method_exists( 'Nexus_Chief', 'review_article' ) ) {
                        return self::err(
                                'nexus_rest_chief_missing',
                                __( 'Il modulo capo redattore non è disponibile: verifica che il plugin sia aggiornato.', 'nexus-news-ai' ),
                                502
                        );
                }

                if ( method_exists( 'Nexus_Chief', 'enabled' ) && ! Nexus_Chief::enabled() ) {
                        return self::err(
                                'nexus_rest_chief_disabled',
                                __( 'Capo redattore non disponibile: attivalo nelle impostazioni.', 'nexus-news-ai' ),
                                400
                        );
                }

                $id      = absint( $request->get_param( 'id' ) );
                $article = self::get_article_or_404( $id );
                if ( is_wp_error( $article ) ) {
                        return $article;
                }

                $res = Nexus_Chief::review_article( $id );
                if ( is_wp_error( $res ) ) {
                        return $res;
                }

                $ok = true;
                if ( is_array( $res ) && array_key_exists( 'ok', $res ) ) {
                        $ok = (bool) $res['ok'];
                }

                $fresh = Nexus_DB::get_article( $id );

                return rest_ensure_response(
                        array(
                                'ok'           => $ok,
                                'chief_status' => $fresh ? (string) ( $fresh->chief_status ?? '' ) : '',
                                'note'         => $fresh ? (string) ( $fresh->chief_note ?? '' ) : '',
                        )
                );
        }
}
