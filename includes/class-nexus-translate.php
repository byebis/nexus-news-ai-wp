<?php
/**
 * Nexus Translate — traduzioni AI multi-lingua degli articoli (v1.1.2 "Multilingua nativo").
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
 * API v1.1.2 "Multilingua nativo" (CONTRATTO-v1.1.2.md, sezione T-01):
 *
 * Nexus_Translate::native_post_for_article($article_id)
 *                                              — ID del post WordPress nativo collegato all'articolo
 *                                                Nexus via meta _nexus_article_id (0 se assente)
 * Nexus_Translate::translate_to_post($article_id, $lang)
 *                                              — array('ok','already','post_id','lang','error'):
 *                                                traduce l'articolo riusando translate_article
 *                                                (upsert su wp_nexus_translations: storico e costi
 *                                                restano tracciati) e pubblica o aggiorna il POST
 *                                                WordPress nativo della traduzione (titolo, slug,
 *                                                contenuto, estratto, autore bot, categoria, tag,
 *                                                thumbnail), poi collega sorgente e traduzione con
 *                                                Nexus_Multilang::register_translation, assegna
 *                                                le redazioni e lancia l'hook
 *                                                nexus_translate_post_created (SOLO
 *                                                alla prima creazione; su update i
 *                                                contenuti vengono aggiornati senza
 *                                                ri-emitere l'hook).
 *
 * v1.1.9 "Affidabilità traduzioni":
 *  - Lock anti-race 'nexus_tr_lock_{article_id}_{lang}' (transient, TTL 5 min):
 *    REST manuale e cron 'nexus_ml_autotranslate' sovrapposti NON fanno più DUE
 *    chiamate AI. Se il lock non è acquisito l'esito è onesto ('traduzione già
 *    in corso'); il flusso completo gira dentro il lock (con re-check della
 *    traduzione esistente DOPO l'acquisizione) e il release avviene su OGNI
 *    percorso d'uscita tramite try/finally in run_locked().
 *  - Budget guard: PRIMA di ogni chiamata al modello viene verificato
 *    Nexus_AI_Engine::budget_exceeded(); budget esaurito → traduzione saltata
 *    con log attività onesto ed esito onesto al chiamante (tipo di ritorno
 *    invariato: array{ok,already,error,translation} + flag additivo 'partial').
 *  - Chunking: il content oltre MAX_CONTENT_CHARS NON è più troncato in
 *    silenzio: viene spezzato in segmenti ≤ MAX_CHUNK_CHARS su confini di
 *    paragrafo, tradotti in sequenza con continuità ('continua la traduzione
 *    dal segmento precedente') e ricongiunti; cap MAX_CHUNKS segmenti (oltre:
 *    log onesto 'contenuto parzialmente tradotto' + 'partial' => true).
 *
 * Note di integrazione:
 * - Le rotte REST sono gestite QUI (pattern Nexus_Newsletter), NON in class-nexus-rest.php.
 * - v1.1.2: native_post_for_article e translate_to_post NON registrano rotte:
 *   le rotte REST multilingua (T-02 in class-nexus-rest.php) e il cron
 *   auto-translate post-publish (T-07 in class-nexus-publisher.php) consumano
 *   questi metodi.
 * - L'integratore deve aggiungere nel bootstrap il require del file + Nexus_Translate::init().
 * - Risposte d'errore REST: new WP_Error( 'nexus_translate_*', messaggio IT, array( 'status' => N ) )
 *   (stessa struttura di class-nexus-rest.php e class-nexus-newsletter.php).
 * - Dipendenze usate SOLO con guard class_exists/method_exists (mai fatale):
 *   Nexus_DB (get_article, get_translation, get_translations, insert_translation,
 *   get_settings, insert_activity), Nexus_AI_Engine (chat, extract_json),
 *   Nexus_Multilang (lang_of_post, post_for_lang, register_translation),
 *   Nexus_Native (article_author_id, article_term_id, article_tags),
 *   Nexus_Redazioni (assign_post_terms).
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
         * Soglia (caratteri) oltre la quale il content viene spezzato in chunk:
         * fino a questa dimensione va al modello intero, in una sola chiamata.
         */
        const MAX_CONTENT_CHARS = 8000;

        /**
         * Lunghezza massima (caratteri) di ogni chunk quando il content viene
         * spezzato (margine sotto MAX_CONTENT_CHARS per prompt sicuri).
         */
        const MAX_CHUNK_CHARS = 7500;

        /**
         * Numero massimo di chunk tradotti per articolo: oltre, la traduzione
         * resta parziale con log onesto 'contenuto parzialmente tradotto'
         * (nessun taglio silenzioso: il limite è sempre dichiarato nell'esito).
         */
        const MAX_CHUNKS = 10;

        /**
         * Durata (secondi) del lock anti-race traduzioni: 5 minuti coprono la
         * chiamata AI e il TTL rende il lock auto-guarigente anche dopo un fatal.
         */
        const LOCK_TTL = 300;

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

                // v1.1.9: onestà sul contenuto — true se il content supera il cap di
                // segmenti ed è stato tradotto solo in parte (mai troncato in silenzio).
                if ( ! empty( $result['partial'] ) ) {
                        $payload['partial'] = true;
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
         * Flusso: guard settings/dipendenze → LOCK anti-race per articolo+lingua
         * (lock non acquisito → esito onesto 'traduzione già in corso', ZERO
         * chiamate AI) → re-check della traduzione esistente DENTRO il lock →
         * budget guard PRIMA di ogni chiamata Nexus_AI_Engine::chat
         * (action='translate', agent_id=0) con prompt IT che chiede
         * ESCLUSIVAMENTE JSON {"title","summary","content"} → extract_json →
         * validazione title → Nexus_DB::insert_translation (upsert) → riga fresca.
         *
         * Content lungo: oltre MAX_CONTENT_CHARS NON viene troncato in silenzio
         * ma spezzato in segmenti ≤ MAX_CHUNK_CHARS su confini di paragrafo e
         * tradotto in sequenza con continuità; oltre MAX_CHUNKS segmenti la
         * traduzione è parziale (flag 'partial' a true + log onesto).
         *
         * Codici lingua fuori dalla mappa LANGUAGES sono ammessi: viene usato il
         * codice stesso come nome lingua nel prompt.
         *
         * @param int    $article_id ID articolo.
         * @param string $lang       Codice lingua (es. 'en', 'es', 'fr', 'de', 'pt', 'ar', 'zh', 'ja').
         * @return array{ok:bool,already:bool,error:string,translation:object|array|null,partial:bool}
         */
        public static function translate_article( $article_id, $lang ) {
                $out = array(
                        'ok'          => false,
                        'already'     => false,
                        'error'       => '',
                        'translation' => null,
                        'partial'     => false,
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

                // Lock anti-race (v1.1.9): REST manuale e cron 'nexus_ml_autotranslate'
                // possono sovrapporsi sullo stesso articolo+lingua e fare DUE chiamate AI.
                // Tutto il flusso d'effetto gira dentro il lock e il release avviene su
                // OGNI percorso d'uscita (try/finally in run_locked()).
                return self::run_locked( $article_id, $lang );
        }

        /**
         * Esegue translate_article_locked() tenendo il lock anti-race e garantendo
         * il release su OGNI percorso d'uscita (successo, errore, eccezione): è il
         * "try/finally" manuale del flusso traduzioni.
         *
         * @param int    $article_id ID articolo.
         * @param string $lang       Codice lingua.
         * @return array{ok:bool,already:bool,error:string,translation:object|array|null,partial:bool}
         */
        private static function run_locked( $article_id, $lang ) {
                $lock_key = self::lock_key( $article_id, $lang );

                if ( ! self::acquire_lock( $lock_key ) ) {
                        // Esito onesto: un altro flusso (REST o cron) sta già traducendo
                        // lo stesso articolo nella stessa lingua. Nessuna seconda chiamata AI.
                        return array(
                                'ok'          => false,
                                'already'     => false,
                                'error'       => 'Traduzione già in corso per questo articolo e questa lingua: riprova più tardi.',
                                'translation' => null,
                                'partial'     => false,
                        );
                }

                try {
                        return self::translate_article_locked( $article_id, $lang );
                } catch ( \Throwable $e ) {
                        // Mai fatale: l'eccezione diventa esito onesto (e log) per il chiamante.
                        $message = 'Eccezione durante la traduzione: ' . sanitize_text_field( $e->getMessage() );
                        self::log_activity( $article_id, $lang, false, $message );
                        return array(
                                'ok'          => false,
                                'already'     => false,
                                'error'       => $message,
                                'translation' => null,
                                'partial'     => false,
                        );
                } finally {
                        // Release garantito: anche sui return dentro try/catch e su eccezione.
                        self::release_lock( $lock_key );
                }
        }

        /**
         * Chiave transient del lock anti-race per articolo+lingua.
         *
         * @param int    $article_id ID articolo.
         * @param string $lang       Codice lingua.
         * @return string
         */
        private static function lock_key( $article_id, $lang ) {
                return 'nexus_tr_lock_' . absint( $article_id ) . '_' . sanitize_key( (string) $lang );
        }

        /**
         * Acquisisce il lock: transient con TTL 5 minuti e valore timestamp.
         * Il TTL rende il lock auto-guarigente: un fatal del flusso precedente
         * non lascia l'articolo bloccato per sempre.
         *
         * @param string $lock_key Chiave del lock.
         * @return bool True se il lock è stato acquisito.
         */
        private static function acquire_lock( $lock_key ) {
                if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
                        return true; // Ambiente senza transients: comportamento pre-1.1.9, mai bloccante.
                }
                if ( false !== get_transient( $lock_key ) ) {
                        return false; // Lock attivo: un altro flusso sta già traducendo.
                }
                set_transient( $lock_key, time(), self::LOCK_TTL );
                return true;
        }

        /**
         * Rilascia il lock (idempotente: sicuro anche se il TTL è già scaduto).
         *
         * @param string $lock_key Chiave del lock.
         * @return void
         */
        private static function release_lock( $lock_key ) {
                if ( function_exists( 'delete_transient' ) ) {
                        delete_transient( $lock_key );
                }
        }

        /**
         * Corpo della traduzione, eseguito DENTRO il lock (mai chiamato direttamente).
         *
         * Il check-then-act della traduzione esistente è ripetuto QUI: tra il
         * rilascio del lock precedente e la nostra acquisizione un altro flusso
         * può aver già completato la traduzione (prima il controllo era fuori da
         * ogni protezione e la doppia chiamata AI restava possibile).
         *
         * @param int    $article_id ID articolo.
         * @param string $lang       Codice lingua.
         * @return array{ok:bool,already:bool,error:string,translation:object|array|null,partial:bool}
         */
        private static function translate_article_locked( $article_id, $lang ) {
                $out = array(
                        'ok'          => false,
                        'already'     => false,
                        'error'       => '',
                        'translation' => null,
                        'partial'     => false,
                );

                /* Re-check DENTRO il lock: il run che teneva il lock può aver già finito. */
                if ( method_exists( 'Nexus_DB', 'get_translation' ) ) {
                        $existing = Nexus_DB::get_translation( $article_id, $lang );
                        if ( $existing && '' !== trim( (string) $existing->content ) ) {
                                $out['ok']          = true;
                                $out['already']     = true;
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
                $content = (string) $article->content;

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

                /* Chunking (v1.1.9): nessun taglio silenzioso del content lungo. */
                $chunks  = self::split_content_chunks( $content );
                $total   = count( $chunks );
                $partial = false;
                if ( $total > self::MAX_CHUNKS ) {
                        // Cap di segmenti: ciò che non si traduce viene DICHIARATO
                        // ('partial' => true + log onesto), mai perso in silenzio.
                        $chunks  = array_slice( $chunks, 0, self::MAX_CHUNKS );
                        $partial = true;
                }

                $t_title       = '';
                $t_summary     = '';
                $content_parts = array();

                foreach ( array_values( $chunks ) as $i => $chunk ) {
                        /* Budget guard PRIMA di OGNI chiamata al modello (v1.1.9). */
                        if ( self::budget_exceeded() ) {
                                $out['error'] = ( ! empty( $content_parts ) )
                                        ? sprintf( 'Traduzione interrotta: budget AI esaurito dopo %1$d di %2$d segmenti.', count( $content_parts ), $total )
                                        : 'Traduzione saltata: budget AI esaurito.';
                                // Nessun salvataggio parziale: la traduzione ripartirà
                                // integralmente quando il budget si libera.
                                self::log_activity(
                                        $article_id,
                                        $lang,
                                        false,
                                        'Traduzione saltata: budget AI esaurito (articolo #' . absint( $article_id ) . ', ' . sanitize_key( $lang ) . ')'
                                );
                                return $out;
                        }

                        $messages = ( 0 === $i )
                                ? self::chunk_prompt_first( $lang_name, $title, $summary, $chunk )
                                : self::chunk_prompt_next( $lang_name, $chunk );

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

                        $parsed = self::parse_ai_json( $res );
                        if ( empty( $parsed['ok'] ) ) {
                                $out['error'] = (string) $parsed['error'];
                                self::log_activity( $article_id, $lang, false );
                                return $out;
                        }

                        $data = $parsed['data'];

                        if ( 0 === $i ) {
                                // Titolo e summary arrivano dal primo segmento (il prompt li richiede esplicitamente):
                                // validarlo qui evita di pagare i segmenti successivi per una traduzione inutilizzabile.
                                $t_title   = trim( (string) ( $data['title'] ?? '' ) );
                                $t_summary = (string) ( $data['summary'] ?? '' );
                                if ( '' === $t_title ) {
                                        $out['error'] = 'AI: traduzione incompleta, titolo mancante.';
                                        self::log_activity( $article_id, $lang, false );
                                        return $out;
                                }
                        }

                        $part = (string) ( $data['content'] ?? '' );
                        if ( '' === trim( $part ) ) {
                                $out['error'] = 'AI: traduzione incompleta, segmento ' . ( $i + 1 ) . ' vuoto.';
                                self::log_activity( $article_id, $lang, false );
                                return $out;
                        }

                        $content_parts[] = $part;
                }

                // Ricongiunzione: i segmenti portano con sé i loro separatori di paragrafo,
                // quindi la giustapposta riproduce il testo originale senza perdite.
                $t_content = implode( '', $content_parts );

                $new_id = Nexus_DB::insert_translation(
                        array(
                                'article_id' => $article_id,
                                'lang'       => $lang,
                                'title'      => $t_title,
                                'summary'    => $t_summary,
                                'content'    => $t_content,
                        )
                );

                if ( $new_id <= 0 ) {
                        $out['error'] = 'Salvataggio della traduzione non riuscito.';
                        self::log_activity( $article_id, $lang, false );
                        return $out;
                }

                $row = method_exists( 'Nexus_DB', 'get_translation' ) ? Nexus_DB::get_translation( $article_id, $lang ) : null;

                /* Log onesto: se ci si è fermati al cap di segmenti lo si dichiara. */
                if ( $partial ) {
                        self::log_activity(
                                $article_id,
                                $lang,
                                true,
                                sprintf(
                                        'Traduzione %1$s articolo #%2$d completata ma contenuto parzialmente tradotto (limite di %3$d segmenti).',
                                        sanitize_key( $lang ),
                                        absint( $article_id ),
                                        self::MAX_CHUNKS
                                )
                        );
                } else {
                        self::log_activity( $article_id, $lang, true );
                }

                $out['ok']          = true;
                $out['already']     = false;
                $out['partial']     = $partial;
                $out['translation'] = $row ? $row : null;

                return $out;
        }

        /**
         * Prompt del primo segmento (o dell'unico): chiede titolo, summary e content.
         *
         * @param string $lang_name Nome lingua per il prompt.
         * @param string $title     Titolo originale.
         * @param string $summary   Summary originale.
         * @param string $chunk     Segmento di content da tradurre.
         * @return array Messaggi per Nexus_AI_Engine::chat.
         */
        private static function chunk_prompt_first( $lang_name, $title, $summary, $chunk ) {
                return array(
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
                                        $chunk
                                ),
                        ),
                );
        }

        /**
         * Prompt dei segmenti successivi: continua la traduzione dal segmento
         * precedente e ritorna SOLO il content del segmento (titolo e summary sono
         * già quelli del primo: meno token, zero ripetizioni).
         *
         * @param string $lang_name Nome lingua per il prompt.
         * @param string $chunk     Segmento successivo da tradurre.
         * @return array Messaggi per Nexus_AI_Engine::chat.
         */
        private static function chunk_prompt_next( $lang_name, $chunk ) {
                return array(
                        array(
                                'role'    => 'system',
                                'content' => 'Sei un traduttore editoriale professionale di Nexus News AI. Rispondi SOLO con JSON valido {"content": "..."}, senza testo aggiuntivo né blocco markdown.',
                        ),
                        array(
                                'role'    => 'user',
                                'content' => sprintf(
                                        'Continua la traduzione in %1$s del medesimo articolo dal segmento precedente: il testo qui sotto è il segmento successivo. Prosegui con continuità (tono e terminologia del segmento precedente), mantieni la struttura HTML se presente, NON tradurre nomi propri/marchi e NON ripetere la traduzione già fatta. Ritorna ESCLUSIVAMENTE JSON {"content": "..."} con SOLO la traduzione di QUESTO segmento.' . "\n\n" .
                                        'Segmento successivo:' . "\n%2\$s",
                                        $lang_name,
                                        $chunk
                                ),
                        ),
                );
        }

        /**
         * Normalizza la risposta AI: ok=false con errore onesto se la chiamata
         * fallisce o il JSON non è riparabile (pattern condiviso da tutti i chunk).
         *
         * @param mixed $res Risposta Nexus_AI_Engine::chat.
         * @return array{ok:bool,error:string,data:array}
         */
        private static function parse_ai_json( $res ) {
                if ( ! is_array( $res ) || empty( $res['ok'] ) ) {
                        $ai_error = ( is_array( $res ) && isset( $res['error'] ) ) ? trim( (string) $res['error'] ) : '';
                        return array(
                                'ok'    => false,
                                'error' => '' !== $ai_error ? 'AI: ' . $ai_error : 'AI: chiamata di traduzione non riuscita.',
                                'data'  => array(),
                        );
                }

                $data = null;
                if ( method_exists( 'Nexus_AI_Engine', 'extract_json' ) ) {
                        $data = Nexus_AI_Engine::extract_json( (string) ( $res['content'] ?? '' ) );
                }

                if ( ! is_array( $data ) ) {
                        return array(
                                'ok'    => false,
                                'error' => 'AI: risposta non valida, JSON atteso non ricevuto.',
                                'data'  => array(),
                        );
                }

                return array( 'ok' => true, 'error' => '', 'data' => $data );
        }

        /**
         * Budget guard del flusso traduzioni (v1.1.9): delega a
         * Nexus_AI_Engine::budget_exceeded() senza mai fatale; un errore del
         * controllo NON blocca la traduzione (stessa scelta della pipeline).
         *
         * @return bool True se il budget mensile AI è esaurito.
         */
        private static function budget_exceeded() {
                if ( ! class_exists( 'Nexus_AI_Engine' ) || ! method_exists( 'Nexus_AI_Engine', 'budget_exceeded' ) ) {
                        return false;
                }
                try {
                        return (bool) Nexus_AI_Engine::budget_exceeded();
                } catch ( \Throwable $e ) {
                        return false;
                }
        }

        /**
         * Spezza il content in segmenti per la traduzione: nessun taglio silenzioso.
         *
         * Sotto MAX_CONTENT_CHARS il content va al modello intero (comportamento
         * precedente). Oltre, si spezza in segmenti ≤ MAX_CHUNK_CHARS sui confini
         * di paragrafo (</p> o doppio newline), mantenendo i separatori così i
         * segmenti ricongiunti riproducono il testo originale senza perdite.
         *
         * @param string $content Content originale.
         * @return string[] Segmenti nell'ordine originale.
         */
        private static function split_content_chunks( $content ) {
                $content = (string) $content;
                if ( self::str_len( $content ) <= self::MAX_CONTENT_CHARS ) {
                        return array( $content );
                }

                // Paragrafi con i loro separatori: con PREG_SPLIT_DELIM_CAPTURE il risultato
                // alterna [testo, separatore, testo, separatore, ...].
                $pieces     = preg_split( '/(<\/p>|\r\n\r\n|\n\n)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
                $paragraphs = array();
                $buf        = '';
                foreach ( (array) $pieces as $idx => $piece ) {
                        $buf .= (string) $piece;
                        if ( 1 === ( $idx % 2 ) ) {
                                $paragraphs[] = $buf;
                                $buf          = '';
                        }
                }
                if ( '' !== $buf ) {
                        $paragraphs[] = $buf;
                }

                $chunks = array();
                $cur    = '';
                foreach ( $paragraphs as $p ) {
                        if ( self::str_len( $p ) > self::MAX_CHUNK_CHARS ) {
                                // Paragrafo più grande di un chunk: flush e spezzatura dura
                                // (preferendo confini di parola, mai a metà tag aperto).
                                if ( '' !== trim( $cur ) ) {
                                        $chunks[] = $cur;
                                        $cur      = '';
                                }
                                foreach ( self::hard_split( $p, self::MAX_CHUNK_CHARS ) as $piece ) {
                                        $chunks[] = $piece;
                                }
                                continue;
                        }
                        if ( '' !== $cur && self::str_len( $cur . $p ) > self::MAX_CHUNK_CHARS ) {
                                $chunks[] = $cur;
                                $cur      = '';
                        }
                        $cur .= $p;
                }
                if ( '' !== trim( $cur ) ) {
                        $chunks[] = $cur;
                }

                // Rete di sicurezza: se la spezzatura non produce nulla, l'intero content
                // va al modello come segmento unico (MAI troncato in silenzio).
                if ( empty( $chunks ) ) {
                        return array( $content );
                }

                return $chunks;
        }

        /**
         * Spezzatura dura di un testo oltre il limite (paragrafo gigantesco):
         * taglia preferendo confini di parola e mai a metà tag HTML aperto.
         *
         * @param string $text Testo da spezzare.
         * @param int    $max  Lunghezza massima di ogni pezzo.
         * @return string[]
         */
        private static function hard_split( $text, $max ) {
                $out = array();
                while ( self::str_len( $text ) > $max ) {
                        $piece = self::str_substr( $text, 0, $max );

                        // Preferisci tagliare all'ultimo spazio: confini di parola, non a metà.
                        $sp = function_exists( 'mb_strrpos' ) ? mb_strrpos( $piece, ' ' ) : strrpos( $piece, ' ' );
                        if ( is_int( $sp ) && $sp > (int) ( $max * 0.5 ) ) {
                                $piece = self::str_substr( $piece, 0, $sp + 1 );
                        }

                        // Non lasciare un tag aperto a cavallo dei segmenti: se l'ultimo '<'
                        // non è stato chiuso, arretra il taglio prima del tag.
                        $lt = function_exists( 'mb_strrpos' ) ? mb_strrpos( $piece, '<' ) : strrpos( $piece, '<' );
                        $gt = function_exists( 'mb_strrpos' ) ? mb_strrpos( $piece, '>' ) : strrpos( $piece, '>' );
                        if ( is_int( $lt ) && ( ! is_int( $gt ) || $gt < $lt ) ) {
                                $piece = self::str_substr( $piece, 0, $lt );
                        }

                        if ( '' === trim( $piece ) ) {
                                $piece = self::str_substr( $text, 0, $max );
                        }

                        $out[] = $piece;
                        $text  = self::str_substr( $text, self::str_len( $piece ) );
                }
                if ( '' !== trim( $text ) ) {
                        $out[] = $text;
                }
                return $out;
        }

        /* ========================= TRADUZIONE COME POST NATIVO (v1.1.2) ========================= */

        /**
         * Post WordPress nativo collegato a un articolo Nexus (v1.1.2).
         *
         * Il publisher (v1.1.0 "Tutto nativo") scrive il meta _nexus_article_id sul
         * post pubblicato: qui lo ritroviamo con una query per meta (get_posts,
         * qualsiasi stato, primo post per ID crescente). I filtri delle query
         * restano disattivati: il sorgente deve emergere anche se un plugin di
         * lingua filtra le query per la lingua corrente.
         *
         * @param int $article_id ID articolo Nexus.
         * @return int Post ID (0 se nessun post nativo collegato).
         */
        public static function native_post_for_article( $article_id ) {
                $article_id = absint( $article_id );
                if ( $article_id < 1 || ! function_exists( 'get_posts' ) ) {
                        return 0;
                }

                $ids = get_posts( array(
                        'post_type'        => 'post',
                        'post_status'      => 'any',
                        'meta_key'         => '_nexus_article_id',
                        'meta_value'       => (string) $article_id,
                        'numberposts'      => 1,
                        'orderby'          => 'ID',
                        'order'            => 'ASC',
                        'fields'           => 'ids',
                        'suppress_filters' => true,
                ) );

                if ( empty( $ids ) || ! is_array( $ids ) ) {
                        return 0;
                }

                return absint( $ids[0] );
        }

        /**
         * Traduce un articolo e lo pubblica come POST WordPress nativo (v1.1.2).
         *
         * Il contenuto passa SEMPRE da translate_article (che dalla v1.1.9 applica
         * lock anti-race, budget guard e chunking; upsert su wp_nexus_translations:
         * storico e costi restano tracciati); il risultato diventa un post nativo
         * (post type 'post') con titolo, slug, contenuto, estratto, autore bot,
         * categoria e tag dell'articolo, collegato al sorgente con
         * Nexus_Multilang::register_translation. Se il post della lingua richiesta
         * esiste gia' viene aggiornato (already=true, stato invariato) senza
         * duplicare nulla. Con Polylang o WPML attivi l'associazione usa le loro
         * API ufficiali: zero lavoro extra.
         *
         * v1.1.9: l'hook nexus_translate_post_created scatta SOLO quando il post
         * tradotto e' NUOVAMENTE creato; su update i contenuti vengono aggiornati
         * senza ri-emitere l'hook (evita ping/notifiche duplicate a ogni run cron).
         *
         * @param int    $article_id ID articolo Nexus.
         * @param string $lang       Codice lingua della traduzione (es. 'en').
         * @return array{ok:bool,already:bool,post_id:int,lang:string,error:string}
         */
        public static function translate_to_post( $article_id, $lang ) {
                $out = array(
                        'ok'      => false,
                        'already' => false,
                        'post_id' => 0,
                        'lang'    => '',
                        'error'   => '',
                );

                $article_id  = absint( $article_id );
                $lang        = sanitize_key( (string) $lang );
                $out['lang'] = $lang;

                // Passo 1: guard dipendenze, argomenti e settings.
                if ( $article_id < 1 || '' === $lang ) {
                        $out['error'] = __( 'Articolo o lingua non validi.', 'nexus-news-ai' );
                        return $out;
                }

                if ( ! class_exists( 'Nexus_DB' ) || ! class_exists( 'Nexus_Multilang' ) || ! method_exists( 'Nexus_DB', 'get_settings' ) ) {
                        $out['error'] = __( 'Servizio multilingua non disponibile al momento.', 'nexus-news-ai' );
                        return $out;
                }

                $settings = Nexus_DB::get_settings();
                if ( ! isset( $settings['enable_translations'] ) || 1 !== (int) $settings['enable_translations'] ) {
                        $out['error'] = __( 'Traduzioni disattivate.', 'nexus-news-ai' );
                        return $out;
                }

                // Passo 2: post WordPress nativo dell'articolo (pubblicato o migrato).
                $source_post_id = self::native_post_for_article( $article_id );
                if ( $source_post_id < 1 ) {
                        $out['error'] = __( 'L\'articolo non ha un post WordPress nativo: pubblica o migra l\'articolo prima.', 'nexus-news-ai' );
                        return $out;
                }

                // Passo 3: lingua del sorgente; tradurre nella stessa lingua non ha senso.
                if ( method_exists( 'Nexus_Multilang', 'lang_of_post' ) ) {
                        $source_lang = Nexus_Multilang::lang_of_post( $source_post_id );
                        if ( '' !== $source_lang && $source_lang === $lang ) {
                                $out['error'] = __( 'L\'articolo è già in questa lingua.', 'nexus-news-ai' );
                                return $out;
                        }
                }

                // Passo 4: contenuto tradotto (storico e costi restano su wp_nexus_translations).
                $tr = self::translate_article( $article_id, $lang );
                if ( empty( $tr['ok'] ) ) {
                        $out['error'] = ( isset( $tr['error'] ) && '' !== (string) $tr['error'] )
                                ? (string) $tr['error']
                                : __( 'Traduzione non riuscita.', 'nexus-news-ai' );
                        return $out;
                }

                $translation = isset( $tr['translation'] ) ? $tr['translation'] : null;
                if ( is_array( $translation ) ) {
                        $translation = (object) $translation;
                }

                $t_title   = ( $translation && isset( $translation->title ) ) ? sanitize_text_field( (string) $translation->title ) : '';
                $t_summary = ( $translation && isset( $translation->summary ) ) ? wp_strip_all_tags( (string) $translation->summary ) : '';
                $t_content = ( $translation && isset( $translation->content ) ) ? wp_kses_post( (string) $translation->content ) : '';

                if ( '' === $t_title ) {
                        $out['error'] = __( 'Traduzione non riuscita: titolo tradotto mancante.', 'nexus-news-ai' );
                        return $out;
                }

                // Passo 5: upsert del post tradotto (aggiornamento se esiste, inserimento altrimenti).
                $existing_id = method_exists( 'Nexus_Multilang', 'post_for_lang' ) ? Nexus_Multilang::post_for_lang( $source_post_id, $lang ) : 0;
                $already     = $existing_id > 0;

                $source_post = function_exists( 'get_post' ) ? get_post( $source_post_id ) : null;
                $post_status = ( $source_post instanceof WP_Post && '' !== (string) $source_post->post_status ) ? (string) $source_post->post_status : 'publish';

                $article = method_exists( 'Nexus_DB', 'get_article' ) ? Nexus_DB::get_article( $article_id ) : null;

                if ( $already ) {
                        // Il post della lingua esiste gia': aggiorniamo titolo, contenuto ed estratto.
                        if ( ! function_exists( 'wp_update_post' ) ) {
                                $out['error'] = __( 'Aggiornamento del post tradotto non riuscito: ambiente WordPress incompleto.', 'nexus-news-ai' );
                                self::log_activity( $article_id, $lang, false );
                                return $out;
                        }

                        $result = wp_update_post( array(
                                'ID'           => $existing_id,
                                'post_title'   => $t_title,
                                'post_content' => $t_content,
                                'post_excerpt' => $t_summary,
                        ), true );

                        if ( is_wp_error( $result ) || 0 === absint( $result ) ) {
                                $detail  = is_wp_error( $result ) ? sanitize_text_field( $result->get_error_message() ) : '';
                                $message = '' !== $detail
                                        ? sprintf(
                                                /* translators: %s: dettaglio errore. */
                                                __( 'Aggiornamento del post tradotto non riuscito: %s', 'nexus-news-ai' ),
                                                $detail
                                        )
                                        : __( 'Aggiornamento del post tradotto non riuscito.', 'nexus-news-ai' );
                                $out['error'] = $message;
                                self::log_activity( $article_id, $lang, false );
                                return $out;
                        }

                        $post_id = absint( $result );
                } else {
                        $post_args = array(
                                'post_title'   => $t_title,
                                'post_name'    => sanitize_title( $t_title ),
                                'post_content' => $t_content,
                                'post_excerpt' => $t_summary,
                                'post_status'  => $post_status,
                                'post_type'    => 'post',
                        );

                        // Autore bot, categoria e tag nativi dell'articolo (best-effort, mai fatale).
                        if ( $article && class_exists( 'Nexus_Native' ) ) {
                                if ( method_exists( 'Nexus_Native', 'article_author_id' ) ) {
                                        $author_id = absint( Nexus_Native::article_author_id( $article ) );
                                        if ( $author_id > 0 ) {
                                                $post_args['post_author'] = $author_id;
                                        }
                                }
                                if ( method_exists( 'Nexus_Native', 'article_term_id' ) ) {
                                        $term_id = absint( Nexus_Native::article_term_id( $article ) );
                                        if ( $term_id > 0 ) {
                                                $post_args['post_category'] = array( $term_id );
                                        }
                                }
                                if ( method_exists( 'Nexus_Native', 'article_tags' ) ) {
                                        $tags = Nexus_Native::article_tags( $article );
                                        if ( is_array( $tags ) && ! empty( $tags ) ) {
                                                $post_args['tags_input'] = array_map( 'sanitize_text_field', $tags );
                                        }
                                }
                        }

                        if ( ! function_exists( 'wp_insert_post' ) ) {
                                $out['error'] = __( 'Pubblicazione del post tradotto non riuscita: ambiente WordPress incompleto.', 'nexus-news-ai' );
                                self::log_activity( $article_id, $lang, false );
                                return $out;
                        }

                        $result = wp_insert_post( $post_args, true );

                        if ( is_wp_error( $result ) || 0 === absint( $result ) ) {
                                $detail  = is_wp_error( $result ) ? sanitize_text_field( $result->get_error_message() ) : '';
                                $message = '' !== $detail
                                        ? sprintf(
                                                /* translators: %s: dettaglio errore. */
                                                __( 'Pubblicazione del post tradotto non riuscita: %s', 'nexus-news-ai' ),
                                                $detail
                                        )
                                        : __( 'Pubblicazione del post tradotto non riuscita.', 'nexus-news-ai' );
                                $out['error'] = $message;
                                self::log_activity( $article_id, $lang, false );
                                return $out;
                        }

                        $post_id = absint( $result );
                }

                // Passo 6: meta di collegamento e thumbnail del sorgente (best-effort).
                update_post_meta( $post_id, '_nexus_article_id', $article_id );

                if ( $source_post instanceof WP_Post && function_exists( 'set_post_thumbnail' ) ) {
                        $thumb_id = absint( get_post_meta( $source_post->ID, '_thumbnail_id', true ) );
                        if ( $thumb_id > 0 ) {
                                set_post_thumbnail( $post_id, $thumb_id );
                        }
                }

                // Passo 7: registrazione della traduzione (Polylang, WPML o collegamento interno nexus).
                if ( method_exists( 'Nexus_Multilang', 'register_translation' ) ) {
                        Nexus_Multilang::register_translation( $post_id, $lang, $source_post_id );
                }

                // Passo 8: redazioni del post tradotto (best-effort silenzioso, come nel publisher).
                $agent_id = ( $article && isset( $article->agent_id ) ) ? absint( $article->agent_id ) : 0;
                if ( $agent_id > 0 && class_exists( 'Nexus_Redazioni' ) && method_exists( 'Nexus_Redazioni', 'assign_post_terms' ) ) {
                        try {
                                Nexus_Redazioni::assign_post_terms( $post_id, $agent_id );
                        } catch ( \Throwable $e ) {
                                // Un errore di tassonomia non deve bloccare la traduzione.
                        }
                }

                // Passo 9: hook per integrazioni (SEO, ping, cache, notifiche) SOLO su
                // creazione reale (v1.1.9): su update il post esiste già e i consumatori
                // non devono ri-scattare (prima l'hook arrivava anche a traduzione
                // aggiornata, con ping/notifiche duplicate a ogni run cron).
                if ( ! $already ) {
                        do_action( 'nexus_translate_post_created', $post_id, $source_post_id, $lang );
                }

                // Passo 10: log attivita' con esito.
                self::log_activity( $article_id, $lang, true );

                $out['ok']      = true;
                $out['already'] = $already;
                $out['post_id'] = $post_id;

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
         * Lunghezza stringa senza dipendere da mbstring.
         *
         * @param string $text Testo.
         * @return int
         */
        private static function str_len( $text ) {
                return function_exists( 'mb_strlen' ) ? (int) mb_strlen( (string) $text ) : strlen( (string) $text );
        }

        /**
         * Substring senza dipendere da mbstring (l $length è opzionale).
         *
         * @param string   $text   Testo.
         * @param int      $start  Offset di partenza.
         * @param int|null $length Lunghezza (null = fino alla fine).
         * @return string
         */
        private static function str_substr( $text, $start, $length = null ) {
                if ( function_exists( 'mb_substr' ) ) {
                        return null === $length
                                ? (string) mb_substr( (string) $text, (int) $start )
                                : (string) mb_substr( (string) $text, (int) $start, (int) $length );
                }
                return null === $length
                        ? (string) substr( (string) $text, (int) $start )
                        : (string) substr( (string) $text, (int) $start, (int) $length );
        }

        /**
         * Log attività best-effort su Nexus_DB::insert_activity: mai fatale, mai bloccante.
         *
         * @param int         $article_id ID articolo.
         * @param string      $lang       Codice lingua.
         * @param bool        $success    Esito della traduzione.
         * @param string|null $message    Messaggio personalizzato (budget esaurito, contenuto
         *                                parziale, eccezione...): null = messaggio standard.
         * @return void
         */
        private static function log_activity( $article_id, $lang, $success, $message = null ) {
                try {
                        if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                                $default = sprintf( 'Traduzione %1$s articolo #%2$d', sanitize_key( (string) $lang ), absint( $article_id ) );
                                Nexus_DB::insert_activity(
                                        0,
                                        'translate',
                                        ( null !== $message && '' !== (string) $message ) ? (string) $message : $default,
                                        $success ? 'success' : 'error'
                                );
                        }
                } catch ( \Throwable $e ) {
                        // Best-effort: un errore di logging non deve bloccare la traduzione.
                }
        }
}
