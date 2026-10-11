<?php
/**
 * Nexus Translate — traduzioni AI multi-lingua degli articoli (v1.0.3 "Redazione intelligente").
 *
 * Contratto pubblico (CONTRATTO v1.0.3, sezione §3):
 *
 * Nexus_Translate::init()                      — bootstrap: registra le rotte REST su rest_api_init
 * Nexus_Translate::register_routes()           — POST /nexus/v1/articles/{id}/translate (admin)
 *                                                GET  /nexus/v1/articles/{id}/translations (pubblico)
 * Nexus_Translate::rest_translate($request)    — handler traduzione: guard settings, 1 chiamata AI,
 *                                                upsert su wp_nexus_translations
 * Nexus_Translate::rest_translations($request) — handler lista traduzioni di un articolo (senza content)
 * Nexus_Translate::translate_article($article_id, $lang)
 *                                              — array('ok'=>bool,'already'=>bool,'error'=>string,'translation'=>row|null)
 *                                                logica riutilizzabile (cron, editor, future integrazioni)
 *
 * Note di integrazione:
 * - Le rotte REST sono gestite QUI (pattern Nexus_Newsletter), NON in class-nexus-rest.php.
 * - L'integratore deve aggiungere nel bootstrap il require del file + Nexus_Translate::init().
 * - Risposte d'errore REST: new WP_Error( 'nexus_translate_*', messaggio IT, array( 'status' => N ) )
 *   (stessa struttura di class-nexus-rest.php e class-nexus-newsletter.php).
 * - Dipendenze usate SOLO con guard class_exists/method_exists (mai fatale):
 *   Nexus_DB (get_article, get_translation, get_translations, insert_translation,
 *   get_settings, insert_activity) e Nexus_AI_Engine (chat, extract_json).
 * - Le rotte /articles/{id}/translate e /articles/{id}/translations NON collidono con
 *   le rotte esistenti /articles di class-nexus-rest.php (sotto-percorsi dedicati).
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Translate {

        /**
         * Namespace REST condiviso del plugin.
         */
        const ROUTE_NAMESPACE = 'nexus/v1';

        /**
         * Lunghezza massima (caratteri) del content originale inviato al prompt AI.
         */
        const MAX_CONTENT_CHARS = 8000;

        /**
         * Mappa codice lingua → nome italiano (usato nei prompt AI).
         * Codici fuori mappa: si usa il codice stesso come nome.
         */
        const LANGUAGES = array(
                'en' => 'Inglese',
                'es' => 'Spagnolo',
                'fr' => 'Francese',
                'de' => 'Tedesco',
                'pt' => 'Portoghese',
                'ar' => 'Arabo',
                'zh' => 'Cinese',
                'ja' => 'Giapponese',
        );

        /* ========================= BOOTSTRAP ========================= */

        /**
         * Bootstrap traduzioni: aggancia la registrazione delle rotte REST.
         *
         * @return void
         */
        public static function init() {
                add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        }

        /* ========================= ROTTE REST ========================= */

        /**
         * Registra le due rotte REST del namespace nexus/v1 (nuove, non in class-nexus-rest.php):
         *
         * - POST /nexus/v1/articles/{id}/translate {lang} → Nexus_Translate::rest_translate()
         *   permission admin (manage_options), pattern class-nexus-rest.php.
         * - GET /nexus/v1/articles/{id}/translations      → Nexus_Translate::rest_translations()
         *   permission pubblica (__return_true), elenco SENZA content.
         *
         * @return void
         */
        public static function register_routes() {
                register_rest_route(
                        self::ROUTE_NAMESPACE,
                        '/articles/(?P<id>[0-9]+)/translate',
                        array(
                                'methods'             => WP_REST_Server::CREATABLE,
                                'callback'            => array( __CLASS__, 'rest_translate' ),
                                'permission_callback' => array( __CLASS__, 'perm_admin' ),
                                'args'                => array(
                                        'lang' => array(
                                                'type'              => 'string',
                                                'sanitize_callback' => 'sanitize_key',
                                        ),
                                ),
                        )
                );

                register_rest_route(
                        self::ROUTE_NAMESPACE,
                        '/articles/(?P<id>[0-9]+)/translations',
                        array(
                                'methods'             => WP_REST_Server::READABLE,
                                'callback'            => array( __CLASS__, 'rest_translations' ),
                                'permission_callback' => '__return_true',
                        )
                );
        }

        /**
         * Permesso admin: capability manage_options, altrimenti 403.
         *
         * @return true|WP_Error
         */
        public static function perm_admin() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        return new WP_Error(
                                'nexus_translate_forbidden',
                                __( 'Non hai i permessi per accedere a questa risorsa.', 'nexus-news-ai' ),
                                array( 'status' => 403 )
                        );
                }
                return true;
        }

        /* ========================= HANDLER REST ========================= */

        /**
         * Handler POST /nexus/v1/articles/{id}/translate.
         *
         * Pipeline: articolo esiste (404) → guard enable_translations==1 (400) →
         * lang ∈ lista settings translation_langs (400) → translate_article() →
         * 200 {ok, lang, title, summary} (+ already=true se traduzione già esistente)
         * oppure WP_Error con il messaggio d'errore della logica.
         *
         * @param WP_REST_Request $request Richiesta REST.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_translate( $request ) {
                if ( ! class_exists( 'Nexus_DB' ) ) {
                        return new WP_Error(
                                'nexus_translate_unavailable',
                                __( 'Servizio traduzioni non disponibile al momento.', 'nexus-news-ai' ),
                                array( 'status' => 500 )
                        );
                }

                $id = absint( $request->get_param( 'id' ) );
                if ( $id <= 0 ) {
                        return new WP_Error(
                                'nexus_translate_bad_id',
                                __( 'ID articolo non valido.', 'nexus-news-ai' ),
                                array( 'status' => 400 )
                        );
                }

                $article = method_exists( 'Nexus_DB', 'get_article' ) ? Nexus_DB::get_article( $id ) : null;
                if ( ! $article ) {
                        return new WP_Error(
                                'nexus_translate_article_missing',
                                sprintf( __( 'Articolo #%d non trovato.', 'nexus-news-ai' ), $id ),
                                array( 'status' => 404 )
                        );
                }

                $settings = method_exists( 'Nexus_DB', 'get_settings' ) ? Nexus_DB::get_settings() : array();
                if ( ! isset( $settings['enable_translations'] ) || 1 !== (int) $settings['enable_translations'] ) {
                        return new WP_Error(
                                'nexus_translate_disabled',
                                __( 'Traduzioni disattivate.', 'nexus-news-ai' ),
                                array( 'status' => 400 )
                        );
                }

                $raw_lang = $request->get_param( 'lang' );
                $lang     = is_scalar( $raw_lang ) ? sanitize_key( (string) $raw_lang ) : '';
                if ( '' === $lang ) {
                        return new WP_Error(
                                'nexus_translate_bad_lang',
                                __( 'Lingua richiesta mancante o non valida.', 'nexus-news-ai' ),
                                array( 'status' => 400 )
                        );
                }

                $allowed = self::allowed_langs( $settings );
                if ( ! in_array( $lang, $allowed, true ) ) {
                        return new WP_Error(
                                'nexus_translate_lang_not_allowed',
                                sprintf(
                                        /* translators: 1: codice lingua, 2: lingue attive. */
                                        __( 'Lingua "%1$s" non consentita: lingue attive %2$s.', 'nexus-news-ai' ),
                                        $lang,
                                        implode( ', ', $allowed )
                                ),
                                array( 'status' => 400 )
                        );
                }

                $result = self::translate_article( $id, $lang );

                if ( empty( $result['ok'] ) ) {
                        $message = '' !== (string) $result['error'] ? (string) $result['error'] : __( 'Traduzione non riuscita.', 'nexus-news-ai' );
                        return new WP_Error(
                                'nexus_translate_failed',
                                $message,
                                array( 'status' => 500 )
                        );
                }

                $translation = is_object( $result['translation'] ) ? $result['translation'] : null;

                $payload = array(
                        'ok'      => true,
                        'lang'    => $lang,
                        'title'   => $translation ? (string) $translation->title : '',
                        'summary' => $translation ? (string) $translation->summary : '',
                );

                if ( ! empty( $result['already'] ) ) {
                        $payload['already'] = true;
                }

                return rest_ensure_response( $payload );
        }

        /**
         * Handler GET /nexus/v1/articles/{id}/translations (pubblico).
         *
         * Ritorna {translations: [...]} — l'elenco delle traduzioni dell'articolo
         * (title/summary/lang/created_at, SENZA content, come da Nexus_DB::get_translations).
         *
         * @param WP_REST_Request $request Richiesta REST.
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_translations( $request ) {
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'get_translations' ) ) {
                        return new WP_Error(
                                'nexus_translate_unavailable',
                                __( 'Servizio traduzioni non disponibile al momento.', 'nexus-news-ai' ),
                                array( 'status' => 500 )
                        );
                }

                $id = absint( $request->get_param( 'id' ) );
                if ( $id <= 0 ) {
                        return new WP_Error(
                                'nexus_translate_bad_id',
                                __( 'ID articolo non valido.', 'nexus-news-ai' ),
                                array( 'status' => 400 )
                        );
                }

                $article = method_exists( 'Nexus_DB', 'get_article' ) ? Nexus_DB::get_article( $id ) : null;
                if ( ! $article ) {
                        return new WP_Error(
                                'nexus_translate_article_missing',
                                sprintf( __( 'Articolo #%d non trovato.', 'nexus-news-ai' ), $id ),
                                array( 'status' => 404 )
                        );
                }

                $rows = Nexus_DB::get_translations( $id );

                return rest_ensure_response(
                        array(
                                'translations' => is_array( $rows ) ? $rows : array(),
                        )
                );
        }

        /* ========================= LOGICA DI TRADUZIONE ========================= */

        /**
         * Traduce un articolo nella lingua richiesta (riutilizzabile, mai fatale).
         *
         * Flusso: guard settings/dipendenze → lingua valida → se la traduzione esiste
         * già con contenuto ritorna already=true → UNA chiamata Nexus_AI_Engine::chat
         * (action='translate', agent_id=0) con prompt IT che chiede ESCLUSIVAMENTE
         * JSON {"title","summary","content"} → extract_json → validazione title →
         * Nexus_DB::insert_translation (upsert) → ritorna la riga fresca.
         *
         * Codici lingua fuori dalla mappa LANGUAGES sono ammessi: viene usato il
         * codice stesso come nome lingua nel prompt.
         *
         * @param int    $article_id ID articolo.
         * @param string $lang       Codice lingua (es. 'en', 'es', 'fr', 'de', 'pt', 'ar', 'zh', 'ja').
         * @return array{ok:bool,already:bool,error:string,translation:object|array|null}
         */
        public static function translate_article( $article_id, $lang ) {
                $out = array(
                        'ok'         => false,
                        'already'    => false,
                        'error'      => '',
                        'translation' => null,
                );

                $article_id = absint( $article_id );
                $lang       = sanitize_key( (string) $lang );

                if ( $article_id <= 0 || '' === $lang ) {
                        $out['error'] = 'Articolo o lingua non validi.';
                        return $out;
                }

                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'get_article' ) || ! method_exists( 'Nexus_DB', 'insert_translation' ) ) {
                        $out['error'] = 'Layer dati Nexus_DB non disponibile: traduzione impossibile.';
                        return $out;
                }

                $settings = method_exists( 'Nexus_DB', 'get_settings' ) ? Nexus_DB::get_settings() : array();
                if ( ! isset( $settings['enable_translations'] ) || 1 !== (int) $settings['enable_translations'] ) {
                        $out['error'] = 'Traduzioni disattivate.';
                        return $out;
                }

                /* Traduzione già presente con contenuto: nessuna chiamata AI. */
                if ( method_exists( 'Nexus_DB', 'get_translation' ) ) {
                        $existing = Nexus_DB::get_translation( $article_id, $lang );
                        if ( $existing && '' !== trim( (string) $existing->content ) ) {
                                $out['ok']         = true;
                                $out['already']    = true;
                                $out['translation'] = $existing;
                                return $out;
                        }
                }

                $article = Nexus_DB::get_article( $article_id );
                if ( ! $article ) {
                        $out['error'] = sprintf( 'Articolo #%d non trovato.', $article_id );
                        self::log_activity( $article_id, $lang, false );
                        return $out;
                }

                $title   = (string) $article->title;
                $summary = (string) $article->summary;
                $content = self::truncate( (string) $article->content, self::MAX_CONTENT_CHARS );

                if ( '' === trim( $title ) && '' === trim( $content ) ) {
                        $out['error'] = sprintf( 'L\'articolo #%d non ha titolo né contenuto da tradurre.', $article_id );
                        self::log_activity( $article_id, $lang, false );
                        return $out;
                }

                if ( ! class_exists( 'Nexus_AI_Engine' ) || ! method_exists( 'Nexus_AI_Engine', 'chat' ) ) {
                        $out['error'] = 'AI engine non disponibile: traduzione impossibile.';
                        self::log_activity( $article_id, $lang, false );
                        return $out;
                }

                $lang_name = self::lang_name( $lang );

                $messages = array(
                        array(
                                'role'    => 'system',
                                'content' => 'Sei un traduttore editoriale professionale di Nexus News AI. Rispondi SOLO con JSON valido {"title": "...", "summary": "...", "content": "..."}, senza testo aggiuntivo né blocco markdown.',
                        ),
                        array(
                                'role'    => 'user',
                                'content' => sprintf(
                                        'Traduci in %1$s il seguente articolo. Ritorna ESCLUSIVAMENTE JSON {"title": "...", "summary": "...", "content": "..."} — mantieni struttura HTML del content se presente, registri giornalistici naturali, NON tradurre nomi propri/marchi.' . "\n\n" .
                                        'Titolo originale: %2$s' . "\n\n" .
                                        'Summary originale: %3$s' . "\n\n" .
                                        'Content originale:' . "\n%4\$s",
                                        $lang_name,
                                        $title,
                                        $summary,
                                        $content
                                ),
                        ),
                );

                try {
                        $res = Nexus_AI_Engine::chat(
                                $messages,
                                array(
                                        'action'   => 'translate',
                                        'agent_id' => 0,
                                )
                        );
                } catch ( \Throwable $e ) {
                        $res = null;
                }

                if ( ! is_array( $res ) || empty( $res['ok'] ) ) {
                        $ai_error     = ( is_array( $res ) && isset( $res['error'] ) ) ? trim( (string) $res['error'] ) : '';
                        $out['error'] = '' !== $ai_error ? 'AI: ' . $ai_error : 'AI: chiamata di traduzione non riuscita.';
                        self::log_activity( $article_id, $lang, false );
                        return $out;
                }

                $data = null;
                if ( method_exists( 'Nexus_AI_Engine', 'extract_json' ) ) {
                        $data = Nexus_AI_Engine::extract_json( (string) ( $res['content'] ?? '' ) );
                }

                if ( ! is_array( $data ) ) {
                        $out['error'] = 'AI: risposta non valida, JSON atteso non ricevuto.';
                        self::log_activity( $article_id, $lang, false );
                        return $out;
                }

                $t_title = trim( (string) ( $data['title'] ?? '' ) );
                if ( '' === $t_title ) {
                        $out['error'] = 'AI: traduzione incompleta, titolo mancante.';
                        self::log_activity( $article_id, $lang, false );
                        return $out;
                }

                $new_id = Nexus_DB::insert_translation(
                        array(
                                'article_id' => $article_id,
                                'lang'       => $lang,
                                'title'      => $t_title,
                                'summary'    => (string) ( $data['summary'] ?? '' ),
                                'content'    => (string) ( $data['content'] ?? '' ),
                        )
                );

                if ( $new_id <= 0 ) {
                        $out['error'] = 'Salvataggio della traduzione non riuscito.';
                        self::log_activity( $article_id, $lang, false );
                        return $out;
                }

                $row = method_exists( 'Nexus_DB', 'get_translation' ) ? Nexus_DB::get_translation( $article_id, $lang ) : null;

                self::log_activity( $article_id, $lang, true );

                $out['ok']         = true;
                $out['already']    = false;
                $out['translation'] = $row ? $row : null;

                return $out;
        }

        /* ========================= HELPER PRIVATI ========================= */

        /**
         * Lingue attive dal setting translation_langs (lista comma-separated).
         * Fallback difensivo ai default del plugin ('en,es,fr') se il setting è vuoto.
         *
         * @param array $settings Settings correnti (Nexus_DB::get_settings()).
         * @return array Lista di codici lingua sanitizzati.
         */
        private static function allowed_langs( $settings ) {
                $raw   = isset( $settings['translation_langs'] ) ? (string) $settings['translation_langs'] : '';
                $parts = explode( ',', $raw );
                $out   = array();

                foreach ( $parts as $part ) {
                        $code = sanitize_key( trim( $part ) );
                        if ( '' !== $code && ! in_array( $code, $out, true ) ) {
                                $out[] = $code;
                        }
                }

                if ( empty( $out ) ) {
                        return array( 'en', 'es', 'fr' );
                }

                return $out;
        }

        /**
         * Nome italiano della lingua per i prompt; codici fuori mappa restano sé stessi.
         *
         * @param string $lang Codice lingua.
         * @return string
         */
        private static function lang_name( $lang ) {
                $lang = sanitize_key( (string) $lang );
                $map  = self::LANGUAGES;
                return isset( $map[ $lang ] ) ? $map[ $lang ] : $lang;
        }

        /**
         * Tronca una stringa a N caratteri senza dipendere da mbstring.
         *
         * @param string $text      Testo di partenza.
         * @param int    $max_chars Numero massimo di caratteri.
         * @return string
         */
        private static function truncate( $text, $max_chars ) {
                $text = (string) $text;
                if ( function_exists( 'mb_substr' ) ) {
                        return (string) mb_substr( $text, 0, (int) $max_chars );
                }
                return (string) substr( $text, 0, (int) $max_chars );
        }

        /**
         * Log attività best-effort su Nexus_DB::insert_activity: mai fatale, mai bloccante.
         *
         * @param int    $article_id ID articolo.
         * @param string $lang       Codice lingua.
         * @param bool   $success    Esito della traduzione.
         * @return void
         */
        private static function log_activity( $article_id, $lang, $success ) {
                try {
                        if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                                Nexus_DB::insert_activity(
                                        0,
                                        'translate',
                                        sprintf( 'Traduzione %1$s articolo #%2$d', sanitize_key( (string) $lang ), absint( $article_id ) ),
                                        $success ? 'success' : 'error'
                                );
                        }
                } catch ( \Throwable $e ) {
                        // Best-effort: un errore di logging non deve bloccare la traduzione.
                }
        }
}
