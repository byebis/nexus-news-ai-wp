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
 *                                            array( 'id', 'label', 'base_url', 'auth' )
 *                                            con auth = 'bearer' | 'anthropic'.
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
}
