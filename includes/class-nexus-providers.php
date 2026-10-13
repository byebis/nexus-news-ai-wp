<?php
/**
 * Nexus Providers — registry dei provider AI del plugin (v1.1.4).
 *
 * MODELLO ( richiesta di prodotto v1.1.4, contratto CONTRATTO-v1.1.4.md §2-T-01 ):
 * ogni agente puo' usare un provider DIVERSO (e non solo un modello diverso).
 * La chiave API di ogni provider vive nei settings del plugin (chiavi
 * 'prov_key_{id}', gestite dalla pagina Impostazioni, vedi T-02), mentre
 * l'agente contiene solo l'ID del provider (campo ai_provider di
 * nexus_agents, creato da T-02). Le chiamate passano da
 * Nexus_AI_Engine::chat(), che consulta questo registry quando riceve
 * 'provider' (o un agente) tra le opzioni; senza provider esplicito la
 * chat continua a usare i globali ai_api_key/ai_base_url come prima.
 *
 * CONTRATTO PUBBLICO (usato dagli altri moduli):
 * Nexus_Providers::catalog()               — elenco provider, chiave = id:
 *                                            array( 'id', 'label', 'base_url',
 *                                            'auth' ) con auth = 'bearer' |
 *                                            'anthropic'. v1.1.10 (Wave 0/WP10):
 *                                            ogni voce aggiunge anche
 *                                            models_endpoint (URL lista modelli),
 *                                            models_auth ('none'|'bearer'|
 *                                            'anthropic'|'query_key'),
 *                                            key_page (deep link per creare la
 *                                            chiave), oauth (bool: provisioning
 *                                            chiavi via OAuth PKCE) e
 *                                            native_search (bool: ricerca web
 *                                            nativa del provider nei tool).
 * Nexus_Providers::key_option( $id )       — nome della chiave settings che
 *                                            contiene la chiave API del provider
 *                                            ('prov_key_' . sanitize_key( $id )).
 * Nexus_Providers::resolve( $id )          — risolve id in credenziali. Ritorna
 *                                            SEMPRE array( 'ok' => bool,
 *                                            'base_url' => string, 'api_key' => string,
 *                                            'auth' => string, 'label' => string,
 *                                            'error' => string ). Legge la chiave da
 *                                            Nexus_DB::get_settings()[ key_option() ];
 *                                            per 'custom' la base_url viene dal settings
 *                                            'prov_base_custom'; per 'ollama' la chiave
 *                                            vuota e' ammessa; per gli altri ok=false se
 *                                            la chiave e' vuota (errore in italiano).
 * Nexus_Providers::resolve_for_agent( $a ) — $a oggetto/array con eventuale campo
 *                                            'ai_provider'. ID vuoto o provider
 *                                            sconosciuto → array( 'ok' => true,
 *                                            'legacy' => true ): il chiamante usa i
 *                                            globali dei settings come prima della
 *                                            1.1.4. Altrimenti ritorna il risultato di
 *                                            resolve() con 'legacy' => false.
 *
 * v1.1.10 (WP10 — login web, piano Parte 2): client OAuth PKCE (RFC 7636) per il
 * provisioning di chiavi OpenRouter senza copia-incolla:
 * Nexus_Providers::oauth_start( $provider ) — genera state (one-shot, user-bound,
 *                                            transient 10 min) + code_verifier +
 *                                            code_challenge S256 e ritorna
 *                                            array( ok, auth_url, error ). L'URL
 *                                            di autorizzazione riporta l'utente su
 *                                            admin.php?page=nexus-settings-ai
 *                                            (slug della pagina Impostazioni AI,
 *                                            vedi admin/class-nexus-admin.php) con
 *                                            nexus_oauth=1&state=…&code=…;
 * Nexus_Providers::oauth_callback( $state, $code ) — consuma lo stato (ONE-SHOT:
 *                                            il transient viene eliminato subito
 *                                            dopo la lettura, qualunque sia
 *                                            l'esito), verifica l'utente, scambia
 *                                            code+verifier su
 *                                            https://openrouter.ai/api/v1/auth/keys
 *                                            e salva la chiave di provisioning in
 *                                            prov_key_openrouter. La chiave NON
 *                                            torna mai nel ritorno (array
 *                                            array( ok, error )) né nei log.
 * Nexus_Providers::url_guard_error( $url, $provider_id, $settings ) — guardia
 *                                            SSRF fail-closed (specchio della
 *                                            policy G4 di Nexus_AI_Engine, che è
 *                                            privata) usata dagli endpoint di
 *                                            test/wizard: http/https, host
 *                                            privati solo per 'ollama' o con
 *                                            allow_private_providers attivo.
 *                                            Ritorna '' = permesso, altrimenti il
 *                                            messaggio d'errore da mostrare.
 *
 * Rate limit OAuth: 1 avvio per utente ogni 30 secondi (transient per-user).
 *
 * Retrocompatibilita' 100%: nessun comportamento cambia per chi non usa i
 * provider per-agente (i globali restano il fallback, mai fatal, mai eccezioni).
 *
 * @package Nexus_News_AI
 * @since   1.1.4
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Providers {

        /**
         * Elenco dei provider supportati (chiave = id provider).
         *
         * Ogni voce: array( 'id', 'label', 'base_url', 'auth' ).
         * - 'base_url' vuota (solo 'custom') = usa il settings 'prov_base_custom'.
         * - 'auth' = 'bearer' (header Authorization Bearer) oppure 'anthropic'
         *   (endpoint OpenAI-compat di Anthropic: header x-api-key + Authorization
         *   Bearer + anthropic-version).
         * - 'ollama' ha chiave opzionale (server locale, la chiave vuota e' ammessa).
         *
         * @return array
         */
        public static function catalog() {
                // v1.1.10 (provider v2): ogni voce aggiunge i metadati per il login web,
                // il model picker live e la ricerca nativa del provider:
                //   models_endpoint = URL lista modelli (cache 12h nel picker)
                //   models_auth     = 'none' | 'bearer' | 'anthropic' | 'query_key'
                //   key_page        = deep link alla pagina dove creare la chiave
                //   oauth           = true se esiste provisioning chiavi via OAuth PKCE
                //   native_search   = true se il provider espone ricerca web nativa nei tool
                return array(
                        'openrouter' => array(
                                'id'              => 'openrouter',
                                'label'           => 'OpenRouter',
                                'base_url'        => 'https://openrouter.ai/api/v1',
                                'auth'            => 'bearer',
                                'models_endpoint' => 'https://openrouter.ai/api/v1/models',
                                'models_auth'     => 'none', // pubblica: include pricing e context_length
                                'key_page'        => 'https://openrouter.ai/settings/keys',
                                'oauth'           => true, // PKCE: openrouter.ai/auth → /api/v1/auth/keys
                                'native_search'   => false,
                        ),
                        'openai' => array(
                                'id'              => 'openai',
                                'label'           => 'OpenAI',
                                'base_url'        => 'https://api.openai.com/v1',
                                'auth'            => 'bearer',
                                'models_endpoint' => 'https://api.openai.com/v1/models',
                                'models_auth'     => 'bearer',
                                'key_page'        => 'https://platform.openai.com/api-keys',
                                'oauth'           => false,
                                'native_search'   => true, // web_search_preview su /responses
                        ),
                        'anthropic' => array(
                                'id'              => 'anthropic',
                                'label'           => 'Anthropic',
                                'base_url'        => 'https://api.anthropic.com/v1',
                                'auth'            => 'anthropic',
                                'models_endpoint' => 'https://api.anthropic.com/v1/models',
                                'models_auth'     => 'anthropic',
                                'key_page'        => 'https://console.anthropic.com/settings/keys',
                                'oauth'           => false, // OAuth MCP sperimentale: stretch WP10
                                'native_search'   => true, // web_search_20250305
                        ),
                        'google' => array(
                                'id'              => 'google',
                                'label'           => 'Google Gemini',
                                'base_url'        => 'https://generativelanguage.googleapis.com/v1beta/openai',
                                'auth'            => 'bearer',
                                'models_endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models',
                                'models_auth'     => 'query_key',
                                'key_page'        => 'https://aistudio.google.com/apikey',
                                'oauth'           => false,
                                'native_search'   => true, // google_search tool
                        ),
                        'groq' => array(
                                'id'              => 'groq',
                                'label'           => 'Groq',
                                'base_url'        => 'https://api.groq.com/openai/v1',
                                'auth'            => 'bearer',
                                'models_endpoint' => 'https://api.groq.com/openai/v1/models',
                                'models_auth'     => 'bearer',
                                'key_page'        => 'https://console.groq.com/keys',
                                'oauth'           => false,
                                'native_search'   => false,
                        ),
                        'deepseek' => array(
                                'id'              => 'deepseek',
                                'label'           => 'DeepSeek',
                                'base_url'        => 'https://api.deepseek.com/v1',
                                'auth'            => 'bearer',
                                'models_endpoint' => 'https://api.deepseek.com/models',
                                'models_auth'     => 'bearer',
                                'key_page'        => 'https://platform.deepseek.com/api_keys',
                                'oauth'           => false,
                                'native_search'   => false,
                        ),
                        'mistral' => array(
                                'id'              => 'mistral',
                                'label'           => 'Mistral',
                                'base_url'        => 'https://api.mistral.ai/v1',
                                'auth'            => 'bearer',
                                'models_endpoint' => 'https://api.mistral.ai/v1/models',
                                'models_auth'     => 'bearer',
                                'key_page'        => 'https://console.mistral.ai/api-keys/',
                                'oauth'           => false,
                                'native_search'   => false,
                        ),
                        'xai' => array(
                                'id'              => 'xai',
                                'label'           => 'xAI',
                                'base_url'        => 'https://api.x.ai/v1',
                                'auth'            => 'bearer',
                                'models_endpoint' => 'https://api.x.ai/v1/models',
                                'models_auth'     => 'bearer',
                                'key_page'        => 'https://console.x.ai',
                                'oauth'           => false,
                                'native_search'   => false,
                        ),
                        'custom' => array(
                                'id'              => 'custom',
                                'label'           => 'Personalizzato / compatibile OpenAI',
                                'base_url'        => '', // vuota: si usa il settings prov_base_custom.
                                'auth'            => 'bearer',
                                'models_endpoint' => '', // dinamica: {prov_base_custom}/models
                                'models_auth'     => 'bearer',
                                'key_page'        => '',
                                'oauth'           => false,
                                'native_search'   => false,
                        ),
                        'ollama' => array(
                                'id'              => 'ollama',
                                'label'           => 'Ollama locale',
                                'base_url'        => 'http://127.0.0.1:11434/v1',
                                'auth'            => 'bearer',
                                'models_endpoint' => 'http://127.0.0.1:11434/v1/models',
                                'models_auth'     => 'none',
                                'key_page'        => '',
                                'oauth'           => false,
                                'native_search'   => false,
                        ),
                );
        }

        /**
         * Nome della chiave settings che contiene la chiave API del provider.
         *
         * @param string $provider_id ID del provider (es. 'openrouter').
         * @return string Es. 'prov_key_openrouter'.
         */
        public static function key_option( $provider_id ) {
                return 'prov_key_' . sanitize_key( (string) $provider_id );
        }

        /**
         * Risolve un provider in base_url + chiave API + schema di autenticazione.
         *
         * La chiave viene letta dai settings del plugin con Nexus_DB::get_settings()
         * alla voce key_option( $provider_id ) (le chiavi prov_key_{id} arrivano con
         * T-02; se non esistono ancora la chiave risulta vuota e la validazione
         * fallisce in modo pulito, senza fatal).
         *
         * Regole:
         * - ID vuoto o assente nel catalogo → ok=false, errore in italiano.
         * - 'custom': base_url dal settings 'prov_base_custom' (errore se vuota).
         * - 'ollama': la chiave vuota e' ammessa (server locale).
         * - Tutti gli altri: ok=false se la chiave e' vuota.
         *
         * @param string $provider_id ID del provider (vedi catalog()).
         * @return array Array( ok, base_url, api_key, auth, label, error ).
         */
        public static function resolve( $provider_id ) {
                $provider_id = sanitize_key( (string) $provider_id );

                $out = array(
                        // v1.1.9: id risolto, usato da Nexus_AI_Engine per json_mode,
                        // circuit breaker e fallback chain.
                        'provider' => $provider_id,
                        'ok'       => false,
                        'base_url' => '',
                        'api_key'  => '',
                        'auth'     => 'bearer',
                        'label'    => '',
                        'error'    => '',
                );

                $catalog = self::catalog();
                if ( '' === $provider_id || ! isset( $catalog[ $provider_id ] ) || ! is_array( $catalog[ $provider_id ] ) ) {
                        $out['error'] = 'Provider AI sconosciuto: ' . $provider_id . '.';
                        return $out;
                }

                $entry = $catalog[ $provider_id ];
                $out['label'] = isset( $entry['label'] ) ? (string) $entry['label'] : $provider_id;
                $out['auth']  = ( isset( $entry['auth'] ) && 'anthropic' === $entry['auth'] ) ? 'anthropic' : 'bearer';

                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'get_settings' ) ) {
                        $out['error'] = 'Il layer dati Nexus_DB non è disponibile: il plugin Nexus News AI non è caricato correttamente.';
                        return $out;
                }

                $settings = Nexus_DB::get_settings();
                if ( ! is_array( $settings ) ) {
                        $settings = array();
                }

                $key_option = self::key_option( $provider_id );
                $api_key    = isset( $settings[ $key_option ] ) ? trim( (string) $settings[ $key_option ] ) : '';

                $base_url = isset( $entry['base_url'] ) ? trim( (string) $entry['base_url'] ) : '';
                if ( 'custom' === $provider_id ) {
                        $base_url = isset( $settings['prov_base_custom'] ) ? trim( (string) $settings['prov_base_custom'] ) : '';
                }

                $out['base_url'] = $base_url;
                $out['api_key']  = $api_key;

                if ( '' === $base_url ) {
                        $out['error'] = 'URL base mancante per il provider ' . $out['label'] . ': impostala in Nexus News AI → Impostazioni → Provider AI.';
                        return $out;
                }

                if ( '' === $api_key && 'ollama' !== $provider_id ) {
                        $out['error'] = 'Chiave API mancante per il provider ' . $out['label'] . ': impostala in Nexus News AI → Impostazioni → Provider AI.';
                        return $out;
                }

                $out['ok'] = true;
                return $out;
        }

        /**
         * Risolve il provider di un agente (campo ai_provider, v1.1.4).
         *
         * $agent puo' essere un oggetto (riga nexus_agents) o un array equivalente,
         * con l'eventuale campo 'ai_provider' (ID provider, stringa vuota = nessun
         * provider dedicato).
         *
         * Ritorna:
         * - array( 'ok' => true, 'legacy' => true ) se l'agente non ha un provider
         *   valido (assente, vuoto o sconosciuto): il chiamante usa i settings
         *   globali (ai_api_key/ai_base_url) come prima della 1.1.4;
         * - il risultato di resolve() (con 'legacy' => false) altrimenti: controlla
         *   'ok' prima di usare base_url/api_key/auth.
         *
         * @param object|array $agent Agente con eventuale campo 'ai_provider'.
         * @return array
         */
        public static function resolve_for_agent( $agent ) {
                $legacy = array(
                        'ok'     => true,
                        'legacy' => true,
                );

                if ( empty( $agent ) ) {
                        return $legacy;
                }

                if ( is_object( $agent ) ) {
                        $agent = (array) $agent;
                }
                if ( ! is_array( $agent ) || empty( $agent ) ) {
                        return $legacy;
                }

                $provider_id = isset( $agent['ai_provider'] ) ? sanitize_key( (string) $agent['ai_provider'] ) : '';
                if ( '' === $provider_id ) {
                        return $legacy;
                }

                $catalog = self::catalog();
                if ( ! isset( $catalog[ $provider_id ] ) ) {
                        // Provider non in catalogo (dati incompleti o vecchie installazioni):
                        // fallback legacy, mai bloccante.
                        return $legacy;
                }

                $resolved = self::resolve( $provider_id );
                $resolved['legacy'] = false;
                return $resolved;
        }

        /* =====================================================================
         * LOGIN WEB OAUTH PKCE (v1.1.10, WP10)
         *
         * Flusso (solo provider con 'oauth' => true nel catalog, oggi OpenRouter):
         *   1. oauth_start(): genera state (one-shot, user-bound) + code_verifier
         *      + code_challenge S256 e ritorna l'URL di autorizzazione;
         *   2. l'utente approva su openrouter.ai e viene rimandato al callback_url
         *      (pagina Impostazioni AI) con ?code=… (lo state viaggia dentro il
         *      callback stesso);
         *   3. l'admin JS posta state+code a /ai/oauth/callback → oauth_callback():
         *      scambio code+verifier → chiave 'sk-or-v1-…' salvata in
         *      prov_key_openrouter. La chiave non torna mai in risposta/log.
         * ===================================================================== */

        /**
         * Avvia il login web OAuth PKCE per un provider.
         *
         * Genera state (32 byte hex) e code_verifier (64 byte hex), calcola il
         * code_challenge S256 (base64url(sha256(verifier)) senza padding) e
         * salva lo stato in un transient one-shot di 10 minuti legato all'utente
         * corrente. Rate limit: 1 avvio per utente ogni 30 secondi.
         *
         * @param string $provider_id ID provider con 'oauth' => true (es. 'openrouter').
         * @return array Array( ok => bool, auth_url => string, error => string ).
         *               auth_url è valorizzata SOLO con ok=true.
         */
        public static function oauth_start( $provider_id ) {
                $provider_id = sanitize_key( (string) $provider_id );

                $out = array(
                        'ok'       => false,
                        'auth_url' => '',
                        'error'    => '',
                );

                $catalog = self::catalog();
                if ( ! isset( $catalog[ $provider_id ] ) || ! is_array( $catalog[ $provider_id ] ) || empty( $catalog[ $provider_id ]['oauth'] ) ) {
                        $out['error'] = 'Il login web OAuth non è disponibile per questo provider: inserisci la chiave API manualmente.';
                        return $out;
                }

                // Rate limit per utente: 1 avvio ogni 30 secondi (anti-abuso del
                // bottone e anti-enumerazione di state multipli).
                $rate_key = 'nexus_oauth_rl_' . (int) get_current_user_id();
                if ( get_transient( $rate_key ) ) {
                        $out['error'] = 'Attendere qualche secondo prima di riavviare il login web (limite di frequenza).';
                        return $out;
                }
                set_transient( $rate_key, 1, 30 );

                // PKCE RFC 7636: state e verifier casuali, challenge S256.
                $state          = bin2hex( random_bytes( 32 ) );   // 64 caratteri hex.
                $code_verifier  = bin2hex( random_bytes( 64 ) );   // 128 caratteri hex.
                $code_challenge = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );

                // Stato ONE-SHOT e USER-BOUND: 10 minuti per completare il login.
                // Il transient viene eliminato in oauth_callback() subito dopo la
                // lettura (ogni stato è utilizzabile una sola volta).
                set_transient(
                        'nexus_oauth_state_' . $state,
                        array(
                                'verifier' => $code_verifier,
                                'provider' => $provider_id,
                                'user'     => (int) get_current_user_id(),
                        ),
                        10 * MINUTE_IN_SECONDS
                );

                // Callback sulla pagina Impostazioni AI (slug registrato in
                // admin/class-nexus-admin.php): il codice OpenRouter arriva via
                // query (?code=…) e l'admin.js lo posta a /ai/oauth/callback.
                $callback = admin_url( 'admin.php?page=nexus-settings-ai&nexus_oauth=1&state=' . rawurlencode( $state ) );

                $out['ok']       = true;
                $out['auth_url'] = 'https://openrouter.ai/auth?callback_url=' . rawurlencode( $callback )
                        . '&code_challenge=' . rawurlencode( $code_challenge )
                        . '&code_challenge_method=S256';
                return $out;
        }

        /**
         * Completa il login web OAuth: scambia code+verifier con la chiave.
         *
         * Sicurezza:
         * - ONE-SHOT: il transient dello stato viene eliminato IMMEDIATAMENTE
         *   dopo la lettura (prima di qualunque verifica successiva): un secondo
         *   tentativo con lo stesso state fallisce sempre;
         * - USER-BOUND: lo stato è valido solo per l'utente che ha avviato il
         *   login (confronto con get_current_user_id());
         * - la chiave di provisioning NON è mai inclusa nel ritorno né nei log:
         *   viene salvata nelle settings (prov_key_{provider}) e basta.
         *
         * @param string $state Stato ricevuto dal callback (64 caratteri hex).
         * @param string $code  Codice di autorizzazione ricevuto da OpenRouter.
         * @return array Array( ok => bool, error => string ). Mai la chiave.
         */
        public static function oauth_callback( $state, $code ) {
                $out = array(
                        'ok'    => false,
                        'error' => '',
                );

                $state = (string) $state;
                if ( ! preg_match( '/^[0-9a-f]{64}$/', $state ) ) {
                        $out['error'] = 'Stato OAuth mancante o malformato: riavvia il login da Impostazioni AI.';
                        return $out;
                }

                $state_key  = 'nexus_oauth_state_' . $state;
                $state_data = get_transient( $state_key );
                // ONE-SHOT: consumo lo stato subito, qualunque sia l'esito.
                delete_transient( $state_key );

                if ( ! is_array( $state_data ) || empty( $state_data['verifier'] ) || empty( $state_data['provider'] ) ) {
                        $out['error'] = 'Sessione OAuth scaduta o già usata (validità 10 minuti): riavvia il login da Impostazioni AI.';
                        return $out;
                }

                // User-bound: il callback deve appartenere all'utente che ha avviato.
                if ( (int) ( isset( $state_data['user'] ) ? $state_data['user'] : 0 ) !== (int) get_current_user_id() ) {
                        $out['error'] = 'Sessione OAuth legata a un altro utente: riavvia il login dalla tua sessione.';
                        return $out;
                }

                $provider_id = sanitize_key( (string) $state_data['provider'] );
                $catalog     = self::catalog();
                if ( ! isset( $catalog[ $provider_id ] ) || ! is_array( $catalog[ $provider_id ] ) || empty( $catalog[ $provider_id ]['oauth'] ) ) {
                        $out['error'] = 'Provider OAuth non più valido: riavvia il login da Impostazioni AI.';
                        return $out;
                }

                $code = trim( sanitize_text_field( (string) $code ) );
                if ( '' === $code || strlen( $code ) > 512 ) {
                        $out['error'] = 'Codice OAuth mancante o non valido: riavvia il login da Impostazioni AI.';
                        return $out;
                }

                // Scambio code → chiave di provisioning (PKCE: serve il verifier
                // originale; il body è JSON senza autenticazione, il codice stesso
                // è la credenziale monouso).
                $response = wp_remote_post(
                        'https://openrouter.ai/api/v1/auth/keys',
                        array(
                                'timeout' => 20,
                                'headers' => array(
                                        'Content-Type' => 'application/json',
                                        'Accept'       => 'application/json',
                                ),
                                'body'    => wp_json_encode(
                                        array(
                                                'code'          => $code,
                                                'code_verifier' => (string) $state_data['verifier'],
                                        )
                                ),
                        )
                );

                if ( is_wp_error( $response ) ) {
                        $out['error'] = 'Errore di rete verso OpenRouter durante lo scambio del codice: ' . $response->get_error_message();
                        return $out;
                }

                $http     = (int) wp_remote_retrieve_response_code( $response );
                $body     = json_decode( (string) wp_remote_retrieve_body( $response ), true );
                $key      = '';
                $detail   = '';

                if ( is_array( $body ) ) {
                        if ( isset( $body['key'] ) && is_string( $body['key'] ) ) {
                                $key = trim( $body['key'] );
                        } elseif ( isset( $body['data']['key'] ) && is_string( $body['data']['key'] ) ) {
                                $key = trim( $body['data']['key'] );
                        }
                        if ( isset( $body['message'] ) && is_string( $body['message'] ) ) {
                                $detail = sanitize_text_field( $body['message'] );
                        }
                }

                if ( $http < 200 || $http >= 300 ) {
                        $out['error'] = 'OpenRouter ha rifiutato lo scambio del codice (HTTP ' . $http . ( '' !== $detail ? ': ' . $detail : '' ) . '). Riavvia il login da Impostazioni AI.';
                        return $out;
                }

                // Attesa: { key: 'sk-or-v1-…' }. Verifica difensiva del formato.
                if ( '' === $key || strlen( $key ) < 20 || strlen( $key ) > 512 || 0 !== strpos( $key, 'sk-or-' ) ) {
                        $out['error'] = 'Risposta inattesa da OpenRouter (chiave di provisioning mancante o dal formato inatteso). Riavvia il login da Impostazioni AI.';
                        return $out;
                }

                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'update_settings' ) ) {
                        $out['error'] = 'Il layer dati Nexus_DB non è disponibile: chiave non salvata.';
                        return $out;
                }

                // Salvataggio: stessa chiave settings delle chiavi manuali, stesso
                // sanitize (chiavi senza newline: sanitize_text_field è sufficiente
                // ed è coerente con il pool multi-chiave).
                Nexus_DB::update_settings( array( self::key_option( $provider_id ) => sanitize_text_field( $key ) ) );

                // Log attività ONESTO: nessuna chiave (nemmeno troncata) nel log.
                if ( method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                        Nexus_DB::insert_activity(
                                0,
                                'oauth_connected',
                                sprintf( 'Provider %s connesso via login web OAuth: chiave di provisioning salvata.', isset( $catalog[ $provider_id ]['label'] ) ? $catalog[ $provider_id ]['label'] : $provider_id ),
                                'success'
                        );
                }

                $out['ok'] = true;
                return $out;
        }

        /* =====================================================================
         * GUARDIA SSRF PER TEST/WIZARD (v1.1.10, WP10)
         * ===================================================================== */

        /**
         * Guardia SSRF fail-closed per gli URL chiamati dagli endpoint di test.
         *
         * Specchio della policy G4 di Nexus_AI_Engine (lì privata, non riutilizzabile
         * da altri moduli): solo http/https; host con IP privato/loopback ammessi
         * SOLO per il provider 'ollama' (locale by design) oppure quando il toggle
         * allow_private_providers è attivo (LM Studio/vLLM su LAN).
         *
         * Risiduo documentato (stessa nota della guardia G4): DNS rebinding TOCTOU
         * non coperto — servirebbe pinning IP + header Host.
         *
         * @param string       $url         URL dell'endpoint da chiamare.
         * @param string       $provider_id ID provider ('', 'ollama', 'custom', …).
         * @param array|null   $settings    Settings correnti (opzionale: lette da
         *                                  Nexus_DB se omesse).
         * @return string '' = permesso, altrimenti messaggio d'errore traducibile.
         */
        public static function url_guard_error( $url, $provider_id, $settings = null ) {
                $parsed = wp_parse_url( (string) $url );
                if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
                        return 'URL del provider AI non valida.';
                }

                $scheme = strtolower( isset( $parsed['scheme'] ) ? (string) $parsed['scheme'] : '' );
                if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
                        return 'URL del provider AI non valida: sono ammessi solo http e https.';
                }

                if ( 'ollama' === (string) $provider_id ) {
                        return ''; // Ollama locale ammesso di diritto (come G4).
                }

                if ( ! self::host_is_private( (string) $parsed['host'] ) ) {
                        return ''; // Host pubblico: nessun limite aggiuntivo.
                }

                if ( ! is_array( $settings ) ) {
                        $settings = class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'get_settings' ) ? Nexus_DB::get_settings() : array();
                        if ( ! is_array( $settings ) ) {
                                $settings = array();
                        }
                }
                if ( ! empty( $settings['allow_private_providers'] ) ) {
                        return '';
                }

                return 'Endpoint AI su IP privati/localhost non consentito: attiva l’opzione “Consenti endpoint AI su IP privati/localhost” nelle impostazioni se l’endpoint è il tuo server locale (LM Studio, vLLM, Ollama su LAN).';
        }

        /**
         * Verifica se un host (IP letterale o hostname da risolvere) punta a IP
         * privati/loopback/link-local. Fail-closed: DNS fallito o IP malformato
         * = privato. Stessa lista di reti della guardia G4 del motore AI e della
         * guardia D2 degli strumenti MCP (copia autonoma: le altre due sono
         * private e non riutilizzabili senza accoppiare i moduli).
         *
         * @param string $host Hostname o IP.
         * @return bool
         */
        private static function host_is_private( $host ) {
                $host = strtolower( trim( (string) $host ) );
                if ( '' === $host ) {
                        return true;
                }

                $ip = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );
                if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                        return true; // Risoluzione fallita: fail-closed.
                }

                if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
                        // IPv4-mapped (::ffff:a.b.c.d) → valuta la parte IPv4.
                        if ( preg_match( '/^::ffff:(\d+\.\d+\.\d+\.\d+)$/', $ip, $m ) ) {
                                $ip = $m[1];
                        } else {
                                $packed = @inet_pton( $ip );
                                if ( false === $packed ) {
                                        return true;
                                }
                                // ::1 (loopback) e :: (unspecified).
                                if ( "\x00\x01" === $packed || "\x00\x00" === $packed ) {
                                        return true;
                                }
                                $head = ord( $packed[0] );
                                // fc00::/7 (unique local), fe80::/10 (link-local), ff00::/8 (multicast).
                                if ( 0xfc === ( $head & 0xfe ) || 0xfe === ( $head & 0xff ) || 0xff === ( $head & 0xff ) ) {
                                        return true;
                                }
                                return false;
                        }
                }

                $long = ip2long( $ip );
                if ( false === $long ) {
                        return true;
                }
                $long = sprintf( '%u', $long );

                $cidr_match = function ( $net, $bits ) use ( $long ) {
                        $net_long = sprintf( '%u', ip2long( $net ) );
                        $mask     = $bits > 0 ? ( 0xffffffff << ( 32 - $bits ) ) & 0xffffffff : 0;
                        return ( (int) $net_long & $mask ) === ( (int) $long & $mask );
                };

                return $cidr_match( '0.0.0.0', 8 )
                        || $cidr_match( '10.0.0.0', 8 )
                        || $cidr_match( '127.0.0.0', 8 )
                        || $cidr_match( '169.254.0.0', 16 )
                        || $cidr_match( '172.16.0.0', 12 )
                        || $cidr_match( '192.0.0.0', 24 )
                        || $cidr_match( '192.168.0.0', 16 )
                        || $cidr_match( '100.64.0.0', 10 )
                        || $cidr_match( '198.18.0.0', 15 );
        }
}
