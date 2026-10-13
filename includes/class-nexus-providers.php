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
 * v1.1.10 (F2b/WP11 — provider personalizzati ILLIMITATI): qualsiasi servizio
 * compatibile OpenAI (Together, Fireworks, Perplexity, Cerebras, LM Studio,
 * vLLM, gateway interni…) può essere aggiunto dalla pagina Impostazioni AI
 * come PROVIDER CUSTOM con id 'cust_' + 8 caratteri esadecimali generati.
 * Storage DEDICATO, toccato SOLO da questa classe:
 * Nexus_Providers::custom_providers()          — legge l'option
 *                                            'nexus_custom_providers' e la
 *                                            restituisce SEMPRE come mappa
 *                                            normalizzata id => entry
 *                                            { id, label, base_url, api_key,
 *                                            auth:'bearer' } (solo id canonici
 *                                            cust_*, entry corrotte scartate).
 * Nexus_Providers::save_custom_providers( $list ) — sanifica e riscrive l'intera
 *                                            option (update_option con
 *                                            autoload NO: contiene chiavi API).
 * Nexus_Providers::upsert_custom_provider( $data ) — crea (id assente → nuovo
 *                                            'cust_'+8hex) o aggiorna l'entry
 *                                            { id?, label, base_url, api_key? }
 *                                            con sanitize e guardia SSRF
 *                                            url_guard_error (G4-style: host
 *                                            privato → rifiuto se
 *                                            allow_private_providers è off).
 *                                            Ritorna array( ok, id, error ):
 *                                            la chiave VUOTA su update NON
 *                                            cancella quella salvata (pattern
 *                                            segreti, come PUT /settings).
 * Nexus_Providers::delete_custom_provider( $id ) — rimozione dell'entry. Gli
 *                                            agenti che lo referenziavano
 *                                            (ai_provider = cust_*) NON cadono
 *                                            sul fallback legacy: resolve_for_agent()
 *                                            ritorna errore onesto (ok=false).
 * Nel catalog() i custom compaiono DOPO i 10 built-in con base_url,
 * models_endpoint = {base_url}/models, models_auth 'bearer', key_page '',
 * oauth=false, native_search=false, custom=true (mai sovrascrivere un
 * built-in a parità di id). La CHIAVE API NON arriva mai dal catalog: vive
 * SOLO dentro l'entry e viene risolta da resolve()/resolve_for_agent(),
 * che per gli id cust_* leggono l'entry invece di prov_key_{id}.
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
         * v1.1.10 (F2b/WP11): il risultato MERGIA anche i provider personalizzati
         * (custom_providers(), id 'cust_' + 8 hex) DOPO i built-in: le voci custom
         * portano custom=true, models_endpoint = {base_url}/models e models_auth
         * 'bearer'; la chiave API resta NELL'ENTRY (option nexus_custom_providers)
         * e NON passa dal catalog — la risolve resolve()/resolve_for_agent().
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
                $builtin = array(
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

                // v1.1.10 (F2b/WP11 — provider personalizzati ILLIMITATI): dopo i
                // 10 built-in compaiono i CUSTOM dell'option 'nexus_custom_providers'
                // (id 'cust_' + 8 hex). Contratto voce custom: bearer su
                // {base_url}/models, niente OAuth, niente ricerca nativa. A parità
                // di id vince SEMPRE il built-in (mai sovrascritto, difesa da
                // option corrotte o manomesse).
                foreach ( self::custom_providers() as $custom ) {
                        $custom_id = isset( $custom['id'] ) ? (string) $custom['id'] : '';
                        if ( '' === $custom_id || isset( $builtin[ $custom_id ] ) ) {
                                continue;
                        }
                        $custom_base = isset( $custom['base_url'] ) ? trim( (string) $custom['base_url'] ) : '';
                        $builtin[ $custom_id ] = array(
                                'id'              => $custom_id,
                                'label'           => isset( $custom['label'] ) && '' !== trim( (string) $custom['label'] ) ? (string) $custom['label'] : $custom_id,
                                'base_url'        => $custom_base,
                                'auth'            => 'bearer',
                                'models_endpoint' => '' !== $custom_base ? untrailingslashit( $custom_base ) . '/models' : '',
                                'models_auth'     => 'bearer',
                                'key_page'        => '',
                                'oauth'           => false,
                                'native_search'   => false,
                                'custom'          => true,
                        );
                }

                return $builtin;
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
         * - v1.1.10 (F2b/WP11), id 'cust_*' (provider personalizzati): la chiave
         *   e la base_url arrivano DALL'ENTRY (option nexus_custom_providers),
         *   non da prov_key_{id}; id inesistente (es. provider eliminato dopo
         *   l'assegnazione all'agente) → ok=false con errore onesto.
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

                // v1.1.10 (F2b/WP11): provider personalizzati (id 'cust_' + 8 hex).
                // La chiave NON vive in prov_key_{id} ma DENTRO l'entry (option
                // 'nexus_custom_providers'): risoluzione dedicata con errori onesti
                // per id inesistente, URL base vuoto o chiave mancante.
                if ( 0 === strpos( $provider_id, 'cust_' ) ) {
                        $customs = self::custom_providers();
                        if ( ! isset( $customs[ $provider_id ] ) || ! is_array( $customs[ $provider_id ] ) ) {
                                $out['error'] = 'Provider personalizzato sconosciuto o eliminato (' . $provider_id . '): scegli un altro provider per gli agenti che lo usavano.';
                                return $out;
                        }

                        $custom_entry = $customs[ $provider_id ];
                        $out['label']    = isset( $custom_entry['label'] ) && '' !== trim( (string) $custom_entry['label'] ) ? (string) $custom_entry['label'] : $provider_id;
                        $out['auth']     = 'bearer';
                        $out['base_url'] = isset( $custom_entry['base_url'] ) ? trim( (string) $custom_entry['base_url'] ) : '';
                        $out['api_key']  = isset( $custom_entry['api_key'] ) ? trim( (string) $custom_entry['api_key'] ) : '';

                        if ( '' === $out['base_url'] ) {
                                $out['error'] = 'URL base mancante per il provider personalizzato ' . $out['label'] . ': correggilo in Nexus News AI → Impostazioni → Provider AI.';
                                return $out;
                        }
                        if ( '' === $out['api_key'] ) {
                                $out['error'] = 'Chiave API mancante per il provider personalizzato ' . $out['label'] . ': inseriscila in Nexus News AI → Impostazioni → Provider AI.';
                                return $out;
                        }

                        $out['ok'] = true;
                        return $out;
                }

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
         * v1.1.10 (F2b/WP11): un provider PERSONALIZZATO (ai_provider = 'cust_*')
         * risolve SEMPRE da custom_providers() e non conosce il fallback legacy:
         * se l'entry non esiste più (provider eliminato) il risultato è ok=false
         * con errore onesto — usare i globali scruberebbe un provider DIVERSO da
         * quello scelto per l'agente.
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

                // v1.1.10 (F2b/WP11): i provider personalizzati NON hanno fallback
                // legacy — risolvono SEMPRE da custom_providers(): entry
                // mancante/eliminata → errore onesto (resolve() con ok=false),
                // così nessuna generazione parte con le credenziali sbagliate.
                // Il branch sta PRIMA del check catalog: un cust_* eliminato non è
                // più nel catalog merged e senza questo check cadrebbe sul legacy.
                if ( 0 === strpos( $provider_id, 'cust_' ) ) {
                        $resolved = self::resolve( $provider_id );
                        $resolved['legacy'] = false;
                        return $resolved;
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
         * PROVIDER PERSONALIZZATI ILLIMITATI (v1.1.10, F2b/WP11)
         *
         * Storage: option 'nexus_custom_providers' — mappa id => entry
         * { id, label, base_url, api_key, auth:'bearer' }. Letta e scritta SOLO
         * da questi metodi statici (custom_providers / save_custom_providers /
         * upsert_custom_provider / delete_custom_provider): nessun altro modulo
         * tocca l'option. Le chiavi API non transitano mai nei settings globali
         * né nelle risposte REST (la UI riceve solo has_key).
         * ===================================================================== */

        /**
         * Nome dell'option dei provider personalizzati (v1.1.10, F2b/WP11).
         * Autoload NO (contiene chiavi API, non serve su ogni pagina).
         *
         * @var string
         */
        const CUSTOM_PROVIDERS_OPTION = 'nexus_custom_providers';

        /**
         * Legge l'option dei provider personalizzati (read-only, normalizzante).
         *
         * Ritorna SEMPRE una mappa id => entry { id, label, base_url, api_key,
         * auth:'bearer' }: le entry corrotte o con id non canonico ('cust_' +
         * 8 esadecimali) vengono scartate in lettura, così i consumatori
         * (catalog, resolve, REST) non vedono mai dati malformati.
         *
         * @return array
         */
        public static function custom_providers() {
                $raw = get_option( self::CUSTOM_PROVIDERS_OPTION, array() );
                if ( ! is_array( $raw ) ) {
                        return array();
                }

                $out = array();
                foreach ( $raw as $raw_key => $entry ) {
                        if ( ! is_array( $entry ) ) {
                                continue;
                        }
                        $id = isset( $entry['id'] ) ? sanitize_key( (string) $entry['id'] ) : sanitize_key( (string) $raw_key );
                        if ( '' === $id || ! preg_match( '/^cust_[0-9a-f]{8}$/', $id ) ) {
                                continue; // Solo id canonici: entry corrotte fuori.
                        }
                        $out[ $id ] = array(
                                'id'       => $id,
                                'label'    => isset( $entry['label'] ) ? sanitize_text_field( (string) $entry['label'] ) : $id,
                                'base_url' => isset( $entry['base_url'] ) ? esc_url_raw( (string) $entry['base_url'] ) : '',
                                'api_key'  => isset( $entry['api_key'] ) ? str_replace( array( "\r", "\n" ), '', trim( (string) $entry['api_key'] ) ) : '',
                                'auth'     => 'bearer',
                        );
                }

                return $out;
        }

        /**
         * Sanifica e scrive l'INTERA option dei provider personalizzati.
         *
         * Unico punto di scrittura dell'option (autoload NO): qualunque entry
         * non canonica (id non 'cust_'+8hex, tipi sbagliati) viene scartata;
         * i campi sono risanati con gli stessi sanitize della lettura, quindi
         * l'operazione è idempotente e sicura anche su dati già puliti.
         *
         * @param array $list Mappa id => entry (o lista di entry).
         * @return bool Esito di update_option.
         */
        public static function save_custom_providers( $list ) {
                if ( ! is_array( $list ) ) {
                        return false;
                }

                $clean = array();
                foreach ( $list as $list_key => $entry ) {
                        if ( ! is_array( $entry ) ) {
                                continue;
                        }
                        $id = isset( $entry['id'] ) ? sanitize_key( (string) $entry['id'] ) : sanitize_key( (string) $list_key );
                        if ( '' === $id || ! preg_match( '/^cust_[0-9a-f]{8}$/', $id ) ) {
                                continue;
                        }
                        $clean[ $id ] = array(
                                'id'       => $id,
                                'label'    => isset( $entry['label'] ) ? sanitize_text_field( (string) $entry['label'] ) : '',
                                'base_url' => isset( $entry['base_url'] ) ? esc_url_raw( (string) $entry['base_url'] ) : '',
                                'api_key'  => isset( $entry['api_key'] ) ? str_replace( array( "\r", "\n" ), '', trim( (string) sanitize_textarea_field( (string) $entry['api_key'] ) ) ) : '',
                                'auth'     => 'bearer',
                        );
                }

                // Autoload NO: la option contiene chiavi API, non serve mai su
                // ogni pagina (stessa filosofia dei segreti nei settings).
                return update_option( self::CUSTOM_PROVIDERS_OPTION, $clean, false );
        }

        /**
         * Crea o aggiorna un provider personalizzato.
         *
         * Sanitize:
         * - label: sanitize_text_field, obbligatoria, max 100 caratteri;
         * - base_url: trim + esc_url_raw, obbligatoria http/https, poi GUARDIA
         *   SSRF url_guard_error (G4-style): host privato/localhost rifiutato a
         *   meno che allow_private_providers sia attivo (presidi LM Studio/vLLM
         *   in LAN; il messaggio della guardia spiega come abilitarla);
         * - api_key: sanitize_textarea_field + trim (i newline vengono rimossi:
         *   la chiave viaggia in UN header Authorization, il pool multi-riga dei
         *   prov_key_* non esiste per i custom).
         *
         * SEMANTICA SEGRETI: su UPDATE una api_key vuota NON cancella la chiave
         * salvata (come PUT /settings): la cancellazione esiste solo rimuovendo
         * il provider. Su CREATE la chiave può essere vuota (il test e le chat
         * falliranno con errore onesto finché non viene inserita).
         *
         * @param array $data Array( id? => 'cust_xxxxxxxx' (esistente),
         *                       label, base_url, api_key? ).
         * @return array Array( ok => bool, id => string, error => string ).
         */
        public static function upsert_custom_provider( $data ) {
                $out = array(
                        'ok'    => false,
                        'id'    => '',
                        'error' => '',
                );

                if ( ! is_array( $data ) ) {
                        $out['error'] = 'Dati del provider personalizzato mancanti o non validi.';
                        return $out;
                }

                $list = self::custom_providers();

                // --- ID: assente = nuovo ('cust_' + 8 hex generato); presente =
                // aggiornamento di un entry che DEVE esistere (niente creazione
                // accidentale con id sbagliato).
                $id = isset( $data['id'] ) ? sanitize_key( (string) $data['id'] ) : '';
                if ( '' !== $id ) {
                        if ( ! preg_match( '/^cust_[0-9a-f]{8}$/', $id ) ) {
                                $out['error'] = 'Identificativo del provider personalizzato non valido: deve avere la forma “cust_” + 8 caratteri esadecimali.';
                                return $out;
                        }
                        if ( ! isset( $list[ $id ] ) ) {
                                $out['error'] = 'Provider personalizzato non trovato: forse è stato eliminato. Ricarica la pagina e riprova.';
                                return $out;
                        }
                } else {
                        for ( $i = 0; $i < 5; $i++ ) {
                                $candidate = 'cust_' . bin2hex( random_bytes( 4 ) ); // 8 caratteri hex.
                                if ( ! isset( $list[ $candidate ] ) ) {
                                        $id = $candidate;
                                        break;
                                }
                        }
                        if ( '' === $id ) {
                                $out['error'] = 'Generazione dell’identificativo non riuscita: riprova.';
                                return $out;
                        }
                }
                $out['id'] = $id;

                // --- Etichetta: obbligatoria, testo semplice, max 100.
                $label = isset( $data['label'] ) ? trim( sanitize_text_field( (string) $data['label'] ) ) : '';
                if ( '' === $label ) {
                        $out['error'] = 'Etichetta del provider mancante: dai un nome riconoscibile (es. “Together”).';
                        return $out;
                }
                if ( mb_strlen( $label ) > 100 ) {
                        $label = mb_substr( $label, 0, 100 );
                }

                // --- URL base: obbligatorio, http/https + guardia SSRF G4-style
                // (host privato ammesso solo con allow_private_providers attivo).
                $base_url = isset( $data['base_url'] ) ? esc_url_raw( trim( (string) $data['base_url'] ) ) : '';
                if ( '' === $base_url ) {
                        $out['error'] = 'URL base mancante o non valido: inserisci l’endpoint compatibile OpenAI (es. https://api.together.xyz/v1).';
                        return $out;
                }

                $settings = class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'get_settings' ) ? Nexus_DB::get_settings() : array();
                if ( ! is_array( $settings ) ) {
                        $settings = array();
                }
                $guard = self::url_guard_error( $base_url, $id, $settings );
                if ( '' !== $guard ) {
                        $out['error'] = $guard;
                        return $out;
                }

                // --- Chiave API: sanitize_textarea_field + trim; i newline vengono
                // rimossi (UN header Authorization, niente pool multi-riga). Vuota
                // su update = NESSUN cambio (pattern segreti).
                $api_key = isset( $data['api_key'] ) ? str_replace( array( "\r", "\n" ), '', trim( (string) sanitize_textarea_field( (string) $data['api_key'] ) ) ) : '';
                if ( '' === $api_key && isset( $list[ $id ] ) && '' !== trim( (string) $list[ $id ]['api_key'] ) ) {
                        $api_key = (string) $list[ $id ]['api_key'];
                }

                $list[ $id ] = array(
                        'id'       => $id,
                        'label'    => $label,
                        'base_url' => $base_url,
                        'api_key'  => $api_key,
                        'auth'     => 'bearer',
                );

                if ( ! self::save_custom_providers( $list ) ) {
                        $out['error'] = 'Salvataggio del provider personalizzato non riuscito: verifica i permessi del database.';
                        return $out;
                }

                $out['ok'] = true;
                return $out;
        }

        /**
         * Rimuove un provider personalizzato (id 'cust_' + 8 hex).
         *
         * I riferimenti negli agenti (ai_provider = cust_*) NON vengono toccati:
         * resolve_for_agent() restituirà errore onesto (ok=false) finché l'admin
         * non riassegna un provider, mai il fallback legacy globale.
         *
         * @param string $id ID del provider ('cust_xxxxxxxx').
         * @return bool true = rimosso, false = id non valido o entry assente.
         */
        public static function delete_custom_provider( $id ) {
                $id = sanitize_key( (string) $id );
                if ( '' === $id || ! preg_match( '/^cust_[0-9a-f]{8}$/', $id ) ) {
                        return false;
                }

                $list = self::custom_providers();
                if ( ! isset( $list[ $id ] ) ) {
                        return false;
                }

                unset( $list[ $id ] );
                return (bool) self::save_custom_providers( $list );
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
