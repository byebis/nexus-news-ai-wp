<?php
/**
 * Nexus Publisher — pubblicazione multi-piattaforma degli articoli.
 *
 * Canale 'blog' (v1.0.1 — "Contenuti Veri"):
 *  - enable_wp_posts = 0 (legacy) → l'articolo vive solo nel database Nexus e viene
 *    servito tramite home_url( '/?nexus_article={id}' ). Nessun post WordPress reale.
 *  - enable_wp_posts = 1 (WordPress reale) → wp_insert_post / wp_update_post.
 *    v1.1.0 "Tutto nativo" — i post sono sul post type NATIVO 'post' (filtrabile
 *    con 'nexus_native_post_type'): post_author = utente bot dell'agente
 *    (Nexus_Native::ensure_bot_user), categoria nativa della testata
 *    (Nexus_Native::article_term_id, prima il term dell'agente), tag nativi
 *    deterministici (Nexus_Native::article_tags), titolo, contenuto (wp_kses_post),
 *    estratto (summary) e slug derivati dall'articolo Nexus; post_status dalle
 *    impostazioni ('publish'|'draft'). Meta di collegamento _nexus_article_id e
 *    sincronizzazione di wp_post_id su wp_nexus_articles.
 *    Subito dopo la pubblicazione vengono invocati — senza MAI bloccarla —
 *    Nexus_Image::ensure_featured() e Nexus_SEO::optimize() (classi v1.0.1, se
 *    presenti: guard con class_exists + try/catch).
 *    Se wp_insert_post/wp_update_post fallisce: log 'failed' + secondo log legacy
 *    di fallback con l'URL front-end Nexus.
 *    v1.0.3 — se l'articolo ha citazioni dal fact-check (colonna citations, JSON),
 *    un blocco HTML "Fonti verificate" (max 8 voci, escape completo) viene incluso
 *    nel contenuto iniziale del post, PRIMA di wp_insert_post/wp_update_post.
 *    v1.1.2 "Multilingua nativo" — subito dopo il successo della pubblicazione WP
 *    (meta di collegamento _nexus_article_id già scritto), se il setting
 *    enable_autotranslate_posts è attivo e Nexus_Translate è disponibile, per ogni
 *    lingua attiva (setting translation_langs) diversa dalla lingua del post
 *    sorgente (Nexus_Multilang::lang_of_post, guard class_exists) viene schedulato
 *    un evento WP nativo 'nexus_ml_autotranslate' sfalsato di un minuto per lingua
 *    (wp_schedule_single_event): le traduzioni diventano post nativi collegati
 *    senza saturare l'AI. L'handler Nexus_Publisher::run_autotranslate(),
 *    agganciato in Nexus_Publisher::init() (da chiamare nel bootstrap, stesso
 *    schema di Nexus_Multilang::init()), delega a Nexus_Translate::translate_to_post()
 *    e registra l'attività con esito onesto. Best-effort totale: la schedulazione
 *    non fa MAI fallire la pubblicazione (try/catch + guard).
 *
 * Canali social REALI (v1.0.2 — "Distribuzione Reale"): telegram, discord, x
 * (con alias twitter) e linkedin vengono delegati, quando le relative classi
 * sono presenti, a Nexus_Social_Telegram, Nexus_Social_Discord, Nexus_Social_X
 * e Nexus_Social_LinkedIn secondo il contratto condiviso (CONTRATTO-v1.0.2.md):
 * ogni classe social dedicata espone publish( $article, $settings ) che ritorna
 * array( 'status' ('published'|'failed'|'skipped'), 'post_id', 'post_url',
 * 'error', 'note' ). Ogni esito reale riuscito/fallito registra un'attività
 * Nexus (best-effort).
 *
 * Canali senza integrazione → ERRORE ONESTO (v1.1.6, regola ZERO MOCK):
 * facebook, instagram, ogni platform non mappato e i 4 canali reali quando la
 * relativa classe NON esiste (installazione parziale/vecchia) producono un log
 * con status 'error', post_id e post_url VUOTI e il messaggio esplicativo
 * "Canale social non configurato: nessuna integrazione disponibile per
 * {platform}.". Nessun id/URL generato, nessuna pubblicazione simulata:
 * ciò che non è stato pubblicato non può mai comparire come pubblicato.
 *
 * CONTRATTO PUBBLICO (invariato):
 * Nexus_Publisher::publish( $article ) → array di log:
 *   array( array( 'platform', 'status', 'post_id', 'post_url', 'error', 'note' ), ... )
 * Nessuna scrittura su publish_logs qui: gli insert_publish_log sono responsabilità
 * della pipeline.
 *
 * @package Nexus_News_AI
 * @since   1.0.1 Canale 'blog' con post WordPress reali (CPT nexus_article).
 * @since   1.0.2 Canali social reali (Telegram, Discord, X, LinkedIn).
 * @since   1.0.3 Blocco "Fonti verificate" (nexus-citations) dal fact-check nel post WP reale.
 * @since   1.1.2 Auto-traduzione post-publish come post nativi: eventi WP 'nexus_ml_autotranslate'
 *               sfalsati di un minuto per lingua, handler run_autotranslate() e init().
 * @since   1.1.6 Regola ZERO MOCK: il canale senza integrazione non è più una
 *                pubblicazione simulata ma un log di errore onesto (status
 *                'error', post_id/post_url vuoti, nessun id/URL generato).
 * @since   1.1.18 Gate social su post_status WP (social SOLO su 'publish') e
 *                wp_slash() su title/content/excerpt per wp_insert_post/
 *                wp_update_post (i backslash del contenuto AI non vengono più
 *                mangiati all'insert/update).
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Publisher {

        /**
         * Bootstrap del publisher (v1.1.2): registra l'handler degli eventi WP
         * 'nexus_ml_autotranslate' (uno per lingua, sfalsati di un minuto)
         * schedulati da publish_wp_post dopo la pubblicazione riuscita.
         *
         * Da chiamare UNA volta nel bootstrap del plugin (stesso schema di
         * Nexus_Multilang::init()): senza questa chiamata l'evento cron resta
         * senza handler e le traduzioni automatiche non partono.
         *
         * @return void
         */
        public static function init() {
                add_action( 'nexus_ml_autotranslate', array( __CLASS__, 'run_autotranslate' ), 10, 2 );
        }

        /**
         * Pubblica un articolo su tutte le piattaforme configurate.
         *
         * Canali social reali (v1.0.2): telegram, discord, x (alias twitter) e
         * linkedin vengono delegati alle classi social dedicate se presenti.
         * v1.1.6 (ZERO MOCK): facebook, instagram, ogni platform non mappato e
         * i canali reali senza classe disponibile producono un log di ERRORE
         * onesto (status 'error', post_id/post_url vuoti, messaggio esplicativo
         * nel campo error): nessuna simulazione, nessun successo finto.
         * Un log per platform in tutti i casi.
         *
         * v1.1.18 (AL-12) — GATE SOCIAL SU PUBLISH (decisione di prodotto): il
         * canale 'blog' viene processato SEMPRE per primo (anche se elencato dopo
         * i social in social_platforms) e i canali social partono SOLO se il post
         * WordPress collegato all'articolo è in stato 'publish', MAI su bozze:
         * draft/pending (o post assente/cestinato) → log status 'skipped' con
         * motivo onesto, NESSUNA chiamata al canale, l'articolo WP resta comunque
         * pubblicato (in bozza) dal canale blog.
         *
         * @param object|array $article Articolo Nexus (riga di nexus_articles).
         * @return array Log di pubblicazione (uno o più per piattaforma).
         */
        public static function publish( $article ) {
                if ( is_array( $article ) ) {
                        $article = (object) $article;
                }
                if ( ! is_object( $article ) || empty( $article->id ) ) {
                        return array();
                }

                $article_id = absint( $article->id );

                $settings  = Nexus_DB::get_settings();
                $raw       = isset( $settings['social_platforms'] ) ? (string) $settings['social_platforms'] : 'blog';
                $platforms = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
                if ( empty( $platforms ) ) {
                        $platforms = array( 'blog' );
                }

                // 1 = pubblica post WordPress reali (CPT nexus_article), 0 = comportamento legacy.
                $enable_wp_posts = ! empty( $settings['enable_wp_posts'] ) ? absint( $settings['enable_wp_posts'] ) : 0;

                // Mappa platform → classe social reale (v1.0.2, CONTRATTO-v1.0.2.md).
                // 'twitter' è alias storico di 'x'; i canali senza classe (o non
                // mappati) finiscono nel ramo di errore onesto più sotto.
                $social_map = array(
                        'telegram' => 'Nexus_Social_Telegram',
                        'discord'  => 'Nexus_Social_Discord',
                        'x'        => 'Nexus_Social_X',
                        'twitter'  => 'Nexus_Social_X',
                        'linkedin' => 'Nexus_Social_LinkedIn',
                );

                $logs = array();

                // v1.1.18 (AL-12) — GATE SOCIAL SU PUBLISH: il canale 'blog' viene
                // processato SEMPRE per primo (anche se elencato dopo i social nella
                // lista social_platforms): è l'unico modo per conoscere il post_status
                // effettivo del post WP di QUESTO run (insert nuovo o update) prima di
                // decidere sui canali social. In modalità draft esistente l'articolo
                // WordPress continua a essere pubblicato (in bozza) ma senza social.
                $social_requested = array();

                foreach ( $platforms as $platform ) {
                        $platform = sanitize_key( $platform );
                        if ( '' === $platform ) {
                                continue;
                        }

                        if ( 'blog' === $platform ) {
                                if ( $enable_wp_posts ) {
                                        // Canale reale v1.0.1: post WordPress sul CPT nexus_article.
                                        foreach ( self::publish_wp_post( $article, $article_id, $settings ) as $log ) {
                                                $logs[] = $log;
                                        }
                                } else {
                                        // Modalità legacy: l'articolo è già nel database Nexus, esposto via front-end.
                                        $logs[] = self::legacy_blog_log( $article_id );
                                }
                                continue;
                        }

                        // Canale social: la decisione (invio o skip) avviene dopo il gate.
                        $social_requested[] = $platform;
                }

                // v1.1.18 (AL-12) — gate: i social procedono SOLO se NON esiste un post
                // WP collegato in stato diverso da 'publish'. Con enable_wp_posts=0
                // (legacy) o senza alcun post WP collegato il gate non scatta: non c'è
                // nessuna bozza WP da proteggere. wp_post_id viene rilegto dal database
                // perché il canale 'blog' sopra può averlo appena scritto su questo run
                // (update_article a fine publish_wp_post).
                $skip_reason = '';
                if ( ! empty( $social_requested ) && $enable_wp_posts && function_exists( 'get_post_status' ) && method_exists( 'Nexus_DB', 'get_article' ) ) {
                        $nxa_fresh = Nexus_DB::get_article( $article_id );
                        $nxa_wp_id = ( $nxa_fresh && isset( $nxa_fresh->wp_post_id ) ) ? absint( $nxa_fresh->wp_post_id ) : 0;
                        if ( $nxa_wp_id > 0 ) {
                                $nxa_status = sanitize_key( (string) get_post_status( $nxa_wp_id ) );
                                if ( 'publish' !== $nxa_status ) {
                                        $skip_reason = in_array( $nxa_status, array( 'draft', 'pending' ), true )
                                                ? 'Social saltati: articolo in bozza WP.'
                                                : sprintf( 'Social saltati: post WordPress non pubblicato (stato: %s).', '' !== $nxa_status ? $nxa_status : 'sconosciuto' );
                                }
                        }
                }

                if ( '' !== $skip_reason ) {
                        // Contratto (v1.0.2): UN log per platform in tutti i casi. Status
                        // 'skipped' (già previsto dal contratto delle classi social),
                        // post_id/post_url vuoti, motivo onesto in error/note: nessun
                        // contenuto non revisionato esce sui canali pubblici.
                        foreach ( $social_requested as $platform ) {
                                $logs[] = array(
                                        'platform' => $platform,
                                        'status'   => 'skipped',
                                        'post_id'  => '',
                                        'post_url' => '',
                                        'error'    => $skip_reason,
                                        'note'     => $skip_reason,
                                );
                        }

                        // Activity onesta aggregata (best-effort, mai bloccante): uno skip
                        // non è un fallimento → status 'info'.
                        if ( method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                                try {
                                        Nexus_DB::insert_activity( 0, 'social', $skip_reason, 'info' );
                                } catch ( \Throwable $e ) { // Skip silenzioso: errore non bloccante.
                                }
                        }

                        return $logs;
                }

                foreach ( $social_requested as $platform ) {
                        // Canale social reale (v1.0.2): delega alla classe dedicata se presente.
                        if ( isset( $social_map[ $platform ] )
                                && class_exists( $social_map[ $platform ] )
                                && method_exists( $social_map[ $platform ], 'publish' ) ) {
                                $logs[] = self::publish_social_real( $platform, $social_map[ $platform ], $article, $settings );
                                continue;
                        }

                        // Nessuna integrazione disponibile (v1.1.6, regola ZERO MOCK):
                        // platform non mappato (facebook, instagram, ...) oppure classe
                        // social assente (installazione parziale). Log di ERRORE onesto:
                        // mai un successo finto, NESSUN id/URL generato. Lo status 'error'
                        // è quello renderizzato onestamente dai consumatori attuali
                        // (badge "Errore" rosso in admin.js, STATUS_META['error']; il
                        // messaggio compare nel campo error del log di pubblicazione).
                        $nxa_error = sprintf( 'Canale social non configurato: nessuna integrazione disponibile per %s.', $platform );
                        $logs[]    = array(
                                'platform' => $platform,
                                'status'   => 'error',
                                'post_id'  => '',
                                'post_url' => '',
                                'error'    => $nxa_error,
                                'note'     => $nxa_error,
                        );

                        // Attività onesta nel feed (best-effort, stessa disciplina di
                        // publish_social_real per gli esiti falliti: mai bloccante).
                        if ( method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                                try {
                                        Nexus_DB::insert_activity( 0, 'social', $nxa_error, 'error' );
                                } catch ( \Throwable $e ) { // Skip silenzioso: errore non bloccante.
                                }
                        }
                }

                return $logs;
        }

        /**
         * Pubblica su un canale social REALE tramite la classe dedicata (v1.0.2).
         *
         * Contratto classe social (CONTRATTO-v1.0.2.md): ogni classe espone
         * publish( $article, $settings ) → array( 'status' ('published'|'failed'|'skipped'),
         * 'post_id', 'post_url', 'error', 'note' ). Il risultato viene tradotto in UN
         * log per platform; qualunque eccezione diventa log 'failed' (mai fatale).
         *
         * @param string $platform Platform corrente (telegram|discord|x|twitter|linkedin).
         * @param string $cls      Nome della classe social reale.
         * @param object $article  Articolo Nexus.
         * @param array  $settings Impostazioni plugin (Nexus_DB::get_settings()).
         * @return array Log di pubblicazione (uno per platform).
         */
        private static function publish_social_real( $platform, $cls, $article, $settings ) {
                try {
                        $res = call_user_func( array( $cls, 'publish' ), $article, $settings );
                } catch ( \Throwable $e ) { // Nessuna eccezione propagate: log 'failed'.
                        $res = array(
                                'status'   => 'failed',
                                'post_id'  => '',
                                'post_url' => '',
                                'error'    => sanitize_text_field( $cls . ': ' . $e->getMessage() ),
                                'note'     => '',
                        );
                }

                $res = is_array( $res ) ? $res : array();

                // Normalizzazione difensiva secondo il contratto delle classi social.
                $status = isset( $res['status'] ) ? sanitize_key( (string) $res['status'] ) : 'failed';
                if ( ! in_array( $status, array( 'published', 'failed', 'skipped' ), true ) ) {
                        $status = 'failed';
                }
                $post_id  = isset( $res['post_id'] ) ? sanitize_text_field( (string) $res['post_id'] ) : '';
                $post_url = isset( $res['post_url'] ) ? esc_url_raw( (string) $res['post_url'] ) : '';
                $error    = isset( $res['error'] ) ? sanitize_text_field( (string) $res['error'] ) : '';
                $note     = isset( $res['note'] ) ? sanitize_text_field( (string) $res['note'] ) : '';

                // Attività Nexus (best-effort): solo per esiti reali riusciti/falliti.
                if ( 'skipped' !== $status ) {
                        self::log_social_activity( $platform, 'published' === $status ? 'success' : 'error', 'published' === $status ? $note : $error );
                }

                return array(
                        'platform' => $platform,
                        'status'   => $status,
                        'post_id'  => $post_id,
                        'post_url' => $post_url,
                        'error'    => $error,
                        'note'     => $note,
                );
        }

        /**
         * Registra l'attività del canale social (best-effort: mai bloccante).
         *
         * @param string $platform Platform corrente.
         * @param string $state    Stato attività ('success'|'error').
         * @param string $detail   Nota (successo) o errore (fallimento) da registrare.
         * @return void
         */
        private static function log_social_activity( $platform, $state, $detail ) {
                if ( ! method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                        return;
                }

                $detail = trim( (string) $detail );
                if ( '' === $detail ) {
                        $detail = 'success' === $state ? 'Pubblicazione completata.' : 'Pubblicazione fallita senza dettagli.';
                }

                try {
                        Nexus_DB::insert_activity( 0, 'social', sprintf( 'Canale %s: %s', $platform, $detail ), $state );
                } catch ( \Throwable $e ) { // Skip silenzioso: errore non bloccante.
                }
        }

        /**
         * Log legacy del canale blog: l'articolo resta servito dal front-end Nexus.
         *
         * @param int $article_id ID dell'articolo Nexus.
         * @return array Log di pubblicazione legacy.
         */
        private static function legacy_blog_log( $article_id ) {
                return array(
                        'platform' => 'blog',
                        'status'   => 'published',
                        'post_id'  => 'nexus-' . $article_id,
                        'post_url' => home_url( '/?nexus_article=' . $article_id ),
                        'error'    => '',
                );
        }

        /**
         * Pubblica (o aggiorna) l'articolo come post WordPress reale sul CPT nexus_article.
         *
         * Sequenza (contratto v1.0.1 §2/§5): insert/update → categoria → meta di
         * collegamento → update_article( wp_post_id ) → Nexus_Image::ensure_featured()
         * → Nexus_SEO::optimize() → log. Le chiamate a image/SEO non bloccano mai.
         * v1.0.3: il contenuto iniziale include il blocco citazioni del fact-check
         * ("Fonti verificate", max 8) quando l'articolo ha citations non vuote.
         *
         * @param object $article    Articolo Nexus.
         * @param int    $article_id ID dell'articolo Nexus.
         * @param array  $settings   Impostazioni plugin (Nexus_DB::get_settings()).
         * @return array Un log di successo, oppure due log ('failed' + fallback legacy).
         */
        private static function publish_wp_post( $article, $article_id, $settings ) {
                // Ambiente WordPress non completo (caso limite): resta il comportamento legacy.
                if ( ! function_exists( 'wp_insert_post' ) ) {
                        return array( self::legacy_blog_log( $article_id ) );
                }

                $title = isset( $article->title ) ? sanitize_text_field( (string) $article->title ) : '';
                if ( '' === $title ) {
                        $title = sprintf( 'Articolo Nexus #%d', $article_id );
                }
                $content = isset( $article->content ) ? wp_kses_post( (string) $article->content ) : '';
                $excerpt = isset( $article->summary ) ? wp_strip_all_tags( (string) $article->summary ) : '';

                // v1.0.3 — Citazioni del fact-check: blocco "Fonti verificate" (max 8, escape
                // completo) incluso nel contenuto iniziale, PRIMA di wp_insert_post/
                // wp_update_post. Se il contenuto contiene già il blocco (aggiornamento del
                // post) non viene duplicato. Nessun effetto sul resto del publisher v1.0.2.
                $citations = self::citations_block( $article );
                if ( '' !== $citations && false === strpos( $content, 'nexus-citations' ) ) {
                        $content .= $citations;
                }

                // Stato del post ammesso: 'publish' | 'draft' | 'pending' (v1.1.0: revisione WP nativa).
                $post_status = isset( $settings['post_status'] ) ? sanitize_key( (string) $settings['post_status'] ) : 'publish';
                if ( ! in_array( $post_status, array( 'publish', 'draft', 'pending' ), true ) ) {
                        $post_status = 'publish';
                }

                // Se l'articolo è già collegato a un post WP esistente si aggiorna, senza duplicare.
                $wp_post_id = isset( $article->wp_post_id ) ? absint( $article->wp_post_id ) : 0;

                // v1.1.0 "Tutto nativo": autore bot dell'agente, categoria nativa e tag.
                // v1.1.18 (audit A6 M1) — wp_insert_post()/wp_update_post() si aspettano
                // dati SLASHED (fanno wp_unslash prima del write): senza wp_slash ogni
                // backslash nel contenuto AI (blocchi codice, JSON, regex) viene mangiato
                // all'insert/update. Applicato a title/content/excerpt DOPO la
                // sanitizzazione (kses/strip); il ramo update sotto usa lo stesso
                // $post_args, quindi l'aggiornamento è coperto. Lo slug non serve
                // (sanitize_title già rimuove i backslash).
                $post_args = array(
                        'post_title'   => wp_slash( $title ),
                        'post_content' => wp_slash( $content ),
                        'post_excerpt' => wp_slash( $excerpt ),
                        'post_status'  => $post_status,
                        'post_name'    => sanitize_title( $title ),
                );
                if ( class_exists( 'Nexus_Native' ) && method_exists( 'Nexus_Native', 'article_author_id' ) ) {
                        $nxa_author = absint( Nexus_Native::article_author_id( $article ) );
                        if ( $nxa_author > 0 ) {
                                $post_args['post_author'] = $nxa_author;
                        }
                }
                if ( class_exists( 'Nexus_Native' ) && method_exists( 'Nexus_Native', 'article_tags' ) ) {
                        $nxa_tags = Nexus_Native::article_tags( $article );
                        if ( ! empty( $nxa_tags ) ) {
                                $post_args['tags_input'] = $nxa_tags;
                        }
                }

                if ( $wp_post_id > 0 && get_post( $wp_post_id ) ) {
                        // Aggiornamento del post esistente (title/content/excerpt aggiornati).
                        $post_args['ID'] = $wp_post_id;
                        $result          = wp_update_post( $post_args, true );
                        $note            = 'Aggiornato post WordPress esistente';
                } else {
                        // v1.1.0: inserimento come POST NATIVO (filtrabile con 'nexus_native_post_type').
                        $post_args['post_type'] = apply_filters( 'nexus_native_post_type', 'post' );
                        $result                 = wp_insert_post( $post_args, true );
                        $note                   = 'Post WordPress nativo';
                }

                if ( is_wp_error( $result ) || 0 === absint( $result ) ) {
                        $error = is_wp_error( $result ) ? $result->get_error_message() : 'wp_insert_post/wp_update_post ha restituito 0.';
                        return self::failed_with_legacy_fallback( $article_id, $error );
                }

                $post_id = absint( $result );

                // Redazioni (v1.1.1): assegna al post i termini redazione dell'agente
                // (tassonomia nativa nexus_redazione), best-effort silenzioso — la
                // pubblicazione non deve mai fallire per la tassonomizzazione.
                if ( class_exists( 'Nexus_Redazioni' ) && method_exists( 'Nexus_Redazioni', 'assign_post_terms' ) ) {
                        $nxa_agent_id = isset( $article->agent_id ) ? absint( $article->agent_id ) : 0;
                        try {
                                Nexus_Redazioni::assign_post_terms( (int) $post_id, $nxa_agent_id );
                        } catch ( \Throwable $e ) { // Ignora: errore non bloccante, come per immagine e SEO.
                        }
                }

                // Categoria WP nativa (v1.1.0): prima il term dell'agente, fallback dal nome articolo.
                if ( class_exists( 'Nexus_Native' ) && method_exists( 'Nexus_Native', 'article_term_id' ) ) {
                        $category_id = absint( Nexus_Native::article_term_id( $article ) );
                } else {
                        $category_id = self::ensure_category( $article );
                }
                if ( $category_id > 0 ) {
                        wp_set_post_categories( $post_id, array( $category_id ) );
                }

                // Meta di collegamento post WP ↔ articolo Nexus.
                update_post_meta( $post_id, '_nexus_article_id', $article_id );

                // Persisti il riferimento nel database Nexus SUBITO dopo insert/update riuscito.
                Nexus_DB::update_article( $article_id, array( 'wp_post_id' => $post_id ) );

                // Auto-traduzione post-publish (v1.1.2 "Multilingua nativo"): qui il post
                // esiste e il meta _nexus_article_id è già scritto. Se il setting
                // enable_autotranslate_posts è attivo e Nexus_Translate è disponibile,
                // per ogni lingua attiva (setting translation_langs, stesso split e
                // sanitize_key di Nexus_Translate) diversa dalla lingua del post sorgente
                // si schedula un evento WP nativo sfalsato di un minuto per lingua
                // (i parte da 0). Best-effort totale: nessun errore qui deve mai
                // interrompere la pubblicazione.
                $nxa_autotranslate = ! empty( $settings['enable_autotranslate_posts'] ) ? absint( $settings['enable_autotranslate_posts'] ) : 0;
                if ( 1 === $nxa_autotranslate
                        && class_exists( 'Nexus_Translate' )
                        && method_exists( 'Nexus_Translate', 'translate_to_post' )
                        && function_exists( 'wp_schedule_single_event' ) ) {
                        try {
                                $nxa_langs = array();
                                $nxa_raw   = isset( $settings['translation_langs'] ) ? (string) $settings['translation_langs'] : '';
                                foreach ( explode( ',', $nxa_raw ) as $nxa_part ) {
                                        $nxa_code = sanitize_key( trim( $nxa_part ) );
                                        if ( '' !== $nxa_code && ! in_array( $nxa_code, $nxa_langs, true ) ) {
                                                $nxa_langs[] = $nxa_code;
                                        }
                                }

                                // Lingua del post sorgente: senza Nexus_Multilang il confronto
                                // salta e si usano tutte le lingue attive (comportamento difensivo).
                                $nxa_source_lang = class_exists( 'Nexus_Multilang' ) ? Nexus_Multilang::lang_of_post( $post_id ) : '';

                                $nxa_i = 0;
                                foreach ( $nxa_langs as $nxa_lang ) {
                                        if ( '' !== $nxa_source_lang && $nxa_lang === $nxa_source_lang ) {
                                                continue;
                                        }
                                        wp_schedule_single_event( time() + ( 60 * $nxa_i ), 'nexus_ml_autotranslate', array( (int) $article_id, $nxa_lang ) );
                                        $nxa_i++;
                                }

                                // Log attività best-effort: solo se almeno un evento è stato schedulato.
                                if ( $nxa_i > 0 && method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                                        Nexus_DB::insert_activity( 0, 'autotranslate', sprintf( 'Programmate %d traduzioni automatiche per articolo #%d (post WP #%d).', $nxa_i, $article_id, $post_id ), 'success' );
                                }
                        } catch ( \Throwable $e ) { // Ignora: la schedulazione non deve mai interrompere la pubblicazione.
                        }
                }

                // Immagine in evidenza (v1.0.1): la classe può non esistere ancora; mai bloccare.
                if ( class_exists( 'Nexus_Image' ) && method_exists( 'Nexus_Image', 'ensure_featured' ) ) {
                        try {
                                Nexus_Image::ensure_featured( $article, $post_id );
                        } catch ( \Throwable $e ) { // Ignora: l'immagine non deve mai interrompere la pubblicazione.
                        }
                }

                // Ottimizzazione SEO (v1.0.1): stessa disciplina, nessun blocco in caso di errore.
                if ( class_exists( 'Nexus_SEO' ) && method_exists( 'Nexus_SEO', 'optimize' ) ) {
                        try {
                                Nexus_SEO::optimize( $article, $post_id );
                        } catch ( \Throwable $e ) { // Ignora: la SEO non deve mai interrompere la pubblicazione.
                        }
                }

                // Attività (best-effort, contratto §5): insert_activity() se esiste, altrimenti skip.
                if ( method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                        try {
                                Nexus_DB::insert_activity( 0, 'publish_wp', sprintf( 'Post WP #%d per articolo Nexus #%d', $post_id, $article_id ), 'success' );
                        } catch ( \Throwable $e ) { // Skip silenzioso: errore non bloccante.
                        }
                }

                $permalink = get_permalink( $post_id );

                return array(
                        array(
                                'platform' => 'blog',
                                'status'   => 'published',
                                'post_id'  => (string) $post_id,
                                'post_url' => $permalink ? esc_url_raw( $permalink ) : '',
                                'error'    => '',
                                'note'     => $note,
                        ),
                );
        }

        /**
         * Handler cron dell'auto-traduzione (v1.1.2 "Multilingua nativo").
         *
         * Eseguito dagli eventi WP nativi 'nexus_ml_autotranslate' schedulati in
         * publish_wp_post (uno per lingua, sfalsati di un minuto). Delega a
         * Nexus_Translate::translate_to_post(), che crea o aggiorna il post
         * tradotto collegato: se la lingua esiste già risponde already=true e
         * nessun costo AI viene duplicato. Il log attività registra l'esito
         * onesto (success/error). Mai fatale: qualunque problema diventa una
         * riga di log 'error', mai un crash del cron.
         *
         * @param int    $article_id ID dell'articolo Nexus.
         * @param string $lang       Codice lingua della traduzione (es. 'en').
         * @return void
         */
        public static function run_autotranslate( $article_id, $lang ) {
                $article_id = absint( $article_id );
                $lang       = sanitize_key( (string) $lang );

                if ( $article_id < 1 || '' === $lang ) {
                        return;
                }

                if ( ! class_exists( 'Nexus_Translate' ) || ! method_exists( 'Nexus_Translate', 'translate_to_post' ) ) {
                        self::log_autotranslate_activity( $article_id, $lang, false, 'Nexus_Translate::translate_to_post non disponibile: traduzione automatica saltata.' );
                        return;
                }

                try {
                        $res = Nexus_Translate::translate_to_post( $article_id, $lang );
                } catch ( \Throwable $e ) { // Il cron non deve mai morire per un'eccezione dell'AI.
                        self::log_autotranslate_activity( $article_id, $lang, false, sprintf( 'Eccezione: %s', sanitize_text_field( $e->getMessage() ) ) );
                        return;
                }

                $res = is_array( $res ) ? $res : array();

                if ( empty( $res['ok'] ) ) {
                        $error = isset( $res['error'] ) ? trim( (string) $res['error'] ) : '';
                        self::log_autotranslate_activity( $article_id, $lang, false, '' !== $error ? sanitize_text_field( $error ) : 'Traduzione automatica fallita senza dettagli.' );
                        return;
                }

                $already = ! empty( $res['already'] );
                $post_id = isset( $res['post_id'] ) ? absint( $res['post_id'] ) : 0;
                $message = $already
                        ? sprintf( 'Post #%1$d aggiornato in lingua %2$s per articolo #%3$d (già tradotto).', $post_id, $lang, $article_id )
                        : sprintf( 'Post #%1$d pubblicato in lingua %2$s per articolo #%3$d.', $post_id, $lang, $article_id );

                self::log_autotranslate_activity( $article_id, $lang, true, $message );
        }

        /**
         * Log attività dell'auto-traduzione (best-effort, stesso pattern del
         * publisher: insert_activity se esiste, altrimenti skip silenzioso).
         *
         * @param int    $article_id ID dell'articolo Nexus.
         * @param string $lang       Codice lingua della traduzione.
         * @param bool   $success    True se la traduzione è riuscita.
         * @param string $detail     Messaggio onesto sull'esito.
         * @return void
         */
        private static function log_autotranslate_activity( $article_id, $lang, $success, $detail ) {
                if ( ! method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                        return;
                }

                try {
                        Nexus_DB::insert_activity( 0, 'autotranslate', sprintf( 'Traduzione %1$s articolo #%2$d: %3$s', $lang, $article_id, $detail ), $success ? 'success' : 'error' );
                } catch ( \Throwable $e ) { // Skip silenzioso: errore non bloccante.
                }
        }

        /**
         * Costruisce il blocco HTML "Fonti verificate" dalle citazioni del fact-check (v1.0.3).
         *
         * La colonna citations dell'articolo è una stringa JSON (generata dalla pipeline:
         * array di oggetti {claim, source}). Decode difensivo: se il JSON è vuoto, non
         * valido o l'array risultante è vuoto si ritorna una stringa vuota (nessun blocco).
         * Massimo 8 citazioni; claim in esc_html, source in esc_url con rel noopener
         * nofollow e target _blank.
         *
         * @param object $article Articolo Nexus (eventuale colonna citations).
         * @return string Markup HTML del blocco (stringa vuota se nessuna citazione valida).
         */
        private static function citations_block( $article ) {
                $raw = isset( $article->citations ) ? trim( (string) $article->citations ) : '';
                if ( '' === $raw ) {
                        return '';
                }

                $citations = json_decode( $raw, true );
                if ( ! is_array( $citations ) || empty( $citations ) ) {
                        return '';
                }

                $items = '';
                $count = 0;
                foreach ( $citations as $citation ) {
                        if ( $count >= 8 ) {
                                break; // Massimo 8 citazioni nel post.
                        }

                        $c      = is_array( $citation ) ? $citation : (array) $citation;
                        $claim  = isset( $c['claim'] ) ? trim( wp_strip_all_tags( (string) $c['claim'] ) ) : '';
                        $source = isset( $c['source'] ) ? trim( (string) $c['source'] ) : '';
                        if ( '' === $claim || '' === $source ) {
                                continue; // Citazione incompleta: scartata.
                        }

                        $url = esc_url_raw( $source );
                        if ( '' === $url ) {
                                continue; // URL non valido: scartata.
                        }

                        $items .= '<li>' . esc_html( $claim )
                                . ' — <a href="' . esc_url( $url ) . '" rel="noopener nofollow" target="_blank">fonte</a></li>';
                        $count++;
                }

                if ( '' === $items ) {
                        return '';
                }

                return '<div class="nexus-citations"><h4>Fonti verificate</h4><ul>' . $items . '</ul></div>';
        }

        /**
         * Trova o crea la categoria WP nativa con il nome dell'articolo Nexus.
         *
         * @param object $article Articolo Nexus.
         * @return int Term ID della categoria, 0 se non determinabile.
         */
        private static function ensure_category( $article ) {
                $name = isset( $article->category ) ? sanitize_text_field( (string) $article->category ) : '';
                if ( '' === $name ) {
                        return 0;
                }

                $slug = sanitize_title( $name );

                // Cerca per nome, poi per slug (nome con maiuscole/accenti differenti).
                $term = get_term_by( 'name', $name, 'category' );
                if ( ! $term instanceof WP_Term ) {
                        $term = get_term_by( 'slug', $slug, 'category' );
                }
                if ( $term instanceof WP_Term ) {
                        return (int) $term->term_id;
                }

                // Non esiste: creala.
                $created = wp_insert_term( $name, 'category', array( 'slug' => $slug ) );
                if ( is_wp_error( $created ) ) {
                        // Possibile race condition: riprova con il solo slug.
                        $existing = get_term_by( 'slug', $slug, 'category' );
                        return ( $existing instanceof WP_Term ) ? (int) $existing->term_id : 0;
                }

                return isset( $created['term_id'] ) ? (int) $created['term_id'] : 0;
        }

        /**
         * Log di fallimento dell'insert/update WP + fallback legacy sul front-end Nexus.
         *
         * @param int    $article_id ID dell'articolo Nexus.
         * @param string $error      Messaggio d'errore.
         * @return array Due log: 'failed' (WordPress) + 'published' (fallback legacy).
         */
        private static function failed_with_legacy_fallback( $article_id, $error ) {
                return array(
                        array(
                                'platform' => 'blog',
                                'status'   => 'failed',
                                'post_id'  => '',
                                'post_url' => '',
                                'error'    => sanitize_text_field( (string) $error ),
                        ),
                        array(
                                'platform' => 'blog',
                                'status'   => 'published',
                                'post_id'  => 'nexus-' . $article_id,
                                'post_url' => home_url( '/?nexus_article=' . $article_id ),
                                'error'    => '',
                                'note'     => 'Fallback front-end Nexus: inserimento post WP fallito.',
                        ),
                );
        }
}
