<?php
/**
 * Nexus Chief — Capo Redattore AI (v1.1.4).
 *
 * L'agente "capo redattore" (nexus_agents.role = 'capo_redattore') legge gli
 * articoli portati in coda dai giornalisti (stato 'pending_approval') e decide
 * il verdetto editoriale con una chiamata AI (attraverso il tool-use loop di
 * Nexus_Tools quando gli strumenti di ricerca sono attivi: il capo puo' CERCARE
 * e VERIFICARE le notizie prima di decidere).
 *
 * MODELLO EDITORIALE (contratto v1.1.4):
 *   verdetto "approva"  + chief_auto_publish=1 → status 'approved' (stesse
 *                         scritture interne di Nexus_Pipeline::review():
 *                         update_article + set_approval) + pubblicazione
 *                         immediata (Nexus_Pipeline::publish, log "Pubblicato dal
 *                         capo redattore AI");
 *   verdetto "approva"  + chief_auto_publish=0 → resta in bozza (pending_approval)
 *                         con badge chief_status 'visto' (o 'modificato' se il capo
 *                         ha ritoccato il testo); l'umano approva come oggi;
 *   verdetto "revisiona" → nota al giornalista (chief_note), chief_status
 *                         'revisione', nuovo giro di riscrittura (Nexus_Pipeline::rewrite,
 *                         richiamabile standalone: legge l'articolo e lo rimette in
 *                         pending_approval) e ri-dispatch della revisione, fino a
 *                         chief_max_rounds; giri esauriti → chief_status 'revisione'
 *                         con nota "revisioni esaurite" e NESSUN ulteriore dispatch
 *                         (cap rigido anti-loop su chief_rounds);
 *   verdetto "rifiuta"  + chief_auto_publish=1 → status 'rejected' via
 *                         Nexus_Pipeline::reject() (fallback: stesse scritture
 *                         dirette); chief_auto_publish=0 → resta in bozza con
 *                         badge 'rifiutato' (l'umano decide).
 *
 * L'umano resta SEMPRE sovrano: puo' approvare/rifiutare dalla coda come oggi,
 * i badge coesistono e la revisione puo' essere forzata via REST
 * (POST /nexus/v1/chief/run/{id} → review_article con $force=true).
 *
 * CONTRATTO PUBBLICO (tutti metodi statici, mai fatale):
 *
 * Nexus_Chief::init()
 *   Registra l'handler coda 'nexus_chief_review' → queue_review()
 *   (guard class_exists Nexus_Queue / method_exists; nessun ritorno).
 *
 * Nexus_Chief::enabled()
 *   True se settings chief_enabled=1 ED esiste almeno un agente attivo con
 *   role 'capo_redattore' (SELECT su nexus_agents, cache statica per-request).
 *
 * Nexus_Chief::find_for( $category, $term_id )
 *   Sceglie il capo per un articolo. Priorita': (a) settings chief_id > 0 e
 *   valido; (b) primo attivo chief_scope='global'; (c) primo attivo
 *   chief_scope='category' con category = $category; (d) primo attivo
 *   chief_scope='term' con chief_term_id = (int) $term_id. Ritorna la riga
 *   agente (object) o null.
 *
 * Nexus_Chief::review_prompt( $title, $summary, $content, $category, $note_precedenti )
 *   Prompt IT da capo redattore di vera testata (titolo, piombo, struttura,
 *   tono, errori, coerenza col tema; con note_precedenti non vuote e' un giro
 *   di revisione successivo). Ritorna un array di messaggi (system + user) nel
 *   formato chat di Nexus_AI_Engine::chat() / Nexus_Tools::wrap_chat() e chiede
 *   ESCLUSIVAMENTE il JSON:
 *   {"verdict":"approva|revisiona|rifiuta","note":"...","edited_title":"","edited_content":"","edited_summary":""}
 *   (i campi edited_* sono opzionali: non vuoti = il capo ha ritoccato il testo).
 *
 * Nexus_Chief::review_article( $article_id, $force = true )
 *   Revisione completa di un articolo. $force=true (default, usato dalla route
 *   REST POST /chief/run/{id} che richiama con il solo ID) forza la revisione
 *   anche di un articolo gia' rivisto; $force=false (flussi automatici: coda e
 *   run_for_pending) salta gli articoli con chief_status gia' valorizzato per
 *   evitare doppioni AI. Ritorna SEMPRE array:
 *   array( 'ok', 'article_id', 'skipped', 'verdict', 'chief_status', 'note', 'error', 'message' ).
 *   Le colonne chief_status/chief_note/chief_agent_id/chief_reviewed_at/
 *   chief_rounds (+updated_at) sono scritte via $wpdb->update su nexus_articles
 *   (schema colonne: T-02, v1.1.4); le sostituzioni title/content/summary del
 *   capo passano da Nexus_DB::update_article (sanitizzazione esistente).
 *
 * Nexus_Chief::queue_review( $args )
 *   Handler della coda: accetta l'ID articolo come int (Nexus_Queue passa gli
 *   args POSIZIONALI con array_values) oppure array( 'article_id' => int ).
 *   try/catch totale, log errori, mai fatale.
 *
 * Nexus_Chief::run_for_pending( $limit = 5 )
 *   Revisiona fino a $limit articoli in pending_approval con chief_status ''
 *   oppure 'revisione' (con chief_rounds sotto il cap), chiamando review_article
 *   con $force=false. Usata da Nexus_Pipeline::cron_tick() (limit 3).
 *
 * Integrazioni (tutte guarded, mai fatale):
 *   - Nexus_Tools::wrap_chat (T-04) se la classe esiste, altrimenti chat
 *     diretta su Nexus_AI_Engine (motore multi-provider v1.1.4);
 *   - Nexus_Pipeline::rewrite (giro di revisione), Nexus_Pipeline::publish
 *     (auto publish) e Nexus_Pipeline::reject (rifiuto automatico, con
 *     fallback alle stesse scritture dirette) con guard method_exists;
 *   - Nexus_Queue::dispatch per i re-dispatch, con fallback alla chiamata
 *     diretta se la coda non e' disponibile.
 *
 * v1.1.9 (WP4 — snapshot + budget per-articolo):
 *   - Snapshot revisioni: PRIMA di ogni sovrascrittura del testo (edit del capo
 *     sull'approvazione e riscrittura del giro di revisione) viene salvata una
 *     revisione (Nexus_DB::insert_article_revision, schema v2) con etichetta
 *     "capo redattore: Nome": l'originale non resta solo nel log attività.
 *   - Budget AI per-articolo: transient 'nexus_chief_budget_{id}' (24h) conta gli
 *     avvii di revisione; oltre il tetto (default 30, filtro
 *     'nexus_chief_budget_limit') hard stop con chief_status 'revisione' e nota
 *     onesta. Il budget mensile AI esistente NON e' toccato.
 *   - json_mode sul verdetto (risposta attesa: oggetto JSON, contratto Task 4.8).
 *
 * @package Nexus_News_AI
 * @since   1.1.4
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Chief {

        /**
         * Hook logico della coda lavori per la revisione capo redattore.
         */
        const QUEUE_HOOK = 'nexus_chief_review';

        /**
         * Prefisso del transient di lock anti-concorrenza per articolo.
         */
        const LOCK_PREFIX = 'nexus_chief_lock_';

        /**
         * Durata del lock in secondi: copre la chiamata AI del capo e impedisce
         * che coda e cron revisionino lo stesso articolo contemporaneamente.
         */
        const LOCK_TTL = 180;

        /**
         * Profondita' massima di revisioni concatenate nella stessa request
         * (insurance anti-loop oltre al cap sui giri in database).
         */
        const MAX_DEPTH = 8;

        /**
         * Prefisso del transient del budget AI per-articolo del capo (v1.1.9):
         * conta gli avvii di revisione per un singolo articolo.
         */
        const BUDGET_PREFIX = 'nexus_chief_budget_';

        /**
         * Finestra del budget AI per-articolo: 24 ore (sliding: ogni revisione
         * rinnova la finestra). A finestra scaduta il conteggio riparte da zero.
         */
        const BUDGET_TTL = DAY_IN_SECONDS;

        /**
         * Cache per-request dei capi redattori attivi (null = non ancora caricati).
         *
         * @var array|null
         */
        private static $chiefs = null;

        /**
         * Cache per-request del risultato di enabled().
         *
         * @var bool|null
         */
        private static $enabled_cache = null;

        /**
         * Profondita' corrente di review_article concatenate (anti-loop).
         *
         * @var int
         */
        private static $depth = 0;

        /* ============ BOOTSTRAP ============ */

        /**
         * Registra l'handler della coda per la revisione capo redattore.
         *
         * Chiamata dal bootstrap del plugin (guard sul chiamante): la guardia
         * interna rende l'operazione sicura anche se Nexus_Queue non fosse
         * ancora disponibile (versioni precedenti).
         *
         * @return void
         */
        public static function init() {
                if ( class_exists( 'Nexus_Queue' ) && method_exists( 'Nexus_Queue', 'register_handler' ) ) {
                        Nexus_Queue::register_handler( self::QUEUE_HOOK, array( __CLASS__, 'queue_review' ) );
                }
        }

        /* ============ STATO E SELEZIONE CAPO ============ */

        /**
         * Capo redattore attivo se la funzionalita' e' configurata.
         *
         * @return bool
         */
        public static function enabled() {
                if ( null !== self::$enabled_cache ) {
                        return self::$enabled_cache;
                }

                $enabled = false;
                $settings = self::settings();

                if ( ! empty( $settings['chief_enabled'] ) && ! empty( self::chiefs() ) ) {
                        $enabled = true;
                }

                self::$enabled_cache = $enabled;
                return $enabled;
        }

        /**
         * Agenti attivi con ruolo 'capo_redattore' (cache statica per-request).
         *
         * Lettura difensiva: se la colonna role (v1.1.4) non esiste ancora
         * l'elenco resta vuoto e il flusso torna quello precedente.
         *
         * @return array
         */
        private static function chiefs() {
                if ( null !== self::$chiefs ) {
                        return self::$chiefs;
                }

                self::$chiefs = array();

                if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'tables' ) ) {
                        global $wpdb;
                        $table = Nexus_DB::tables()['agents'];
                        $rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'active' ORDER BY id ASC" );

                        if ( is_array( $rows ) ) {
                                foreach ( $rows as $row ) {
                                        if ( isset( $row->role ) && 'capo_redattore' === (string) $row->role ) {
                                                self::$chiefs[] = $row;
                                        }
                                }
                        }
                }

                return self::$chiefs;
        }

        /**
         * Capo redattore per un articolo: priorita' chief_id, global, category, term.
         *
         * @param string $category Categoria dell'articolo.
         * @param int    $term_id  Term nexus_redazione dell'articolo (0 se nessuno).
         * @return object|null
         */
        public static function find_for( $category, $term_id = 0 ) {
                $chiefs = self::chiefs();
                if ( empty( $chiefs ) ) {
                        return null;
                }

                $settings = self::settings();
                $fixed_id = absint( isset( $settings['chief_id'] ) ? $settings['chief_id'] : 0 );
                $category = (string) $category;
                $term_id  = absint( $term_id );

                // (a) Capo designato esplicitamente nelle impostazioni.
                if ( $fixed_id > 0 ) {
                        foreach ( $chiefs as $chief ) {
                                if ( (int) $chief->id === $fixed_id ) {
                                        return $chief;
                                }
                        }
                }

                // (b) Primo capo attivo con ambito "intero giornale".
                foreach ( $chiefs as $chief ) {
                        if ( isset( $chief->chief_scope ) && 'global' === (string) $chief->chief_scope ) {
                                return $chief;
                        }
                }

                // (c) Primo capo attivo assegnato alla categoria dell'articolo.
                foreach ( $chiefs as $chief ) {
                        if ( isset( $chief->chief_scope ) && 'category' === (string) $chief->chief_scope
                                && self::same_text( isset( $chief->category ) ? (string) $chief->category : '', $category ) ) {
                                return $chief;
                        }
                }

                // (d) Primo capo attivo assegnato alla redazione dell'articolo.
                if ( $term_id > 0 ) {
                        foreach ( $chiefs as $chief ) {
                                if ( isset( $chief->chief_scope ) && 'term' === (string) $chief->chief_scope
                                        && absint( isset( $chief->chief_term_id ) ? $chief->chief_term_id : 0 ) === $term_id ) {
                                        return $chief;
                                }
                        }
                }

                return null;
        }

        /**
         * Term nexus_redazione dell'articolo.
         *
         * Percorso principale (contratto): wp_get_object_terms sul post WP
         * collegato (wp_post_id, valorizzato alla pubblicazione). Nei flussi
         * in coda il post WP non esiste ancora: fallback sul term_id della
         * redazione dell'agente autore (colonna term_id di nexus_agents).
         *
         * @param object $article Articolo (riga nexus_articles).
         * @return int Term ID (0 se nessuno).
         */
        private static function article_term_id( $article ) {
                $post_id = isset( $article->wp_post_id ) ? absint( $article->wp_post_id ) : 0;

                if ( $post_id > 0 && function_exists( 'wp_get_object_terms' ) ) {
                        $tax   = class_exists( 'Nexus_Redazioni' ) ? Nexus_Redazioni::TAX : 'nexus_redazione';
                        $terms = wp_get_object_terms( $post_id, $tax, array( 'fields' => 'ids' ) );
                        if ( ! is_wp_error( $terms ) && is_array( $terms ) && ! empty( $terms ) ) {
                                return absint( $terms[0] );
                        }
                }

                // Fallback (articoli in attesa, senza post WP): redazione dell'autore.
                $agent_id = isset( $article->agent_id ) ? absint( $article->agent_id ) : 0;
                if ( $agent_id > 0 && class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'get_agent' ) ) {
                        $agent = Nexus_DB::get_agent( $agent_id );
                        if ( $agent && isset( $agent->term_id ) ) {
                                return absint( $agent->term_id );
                        }
                }

                return 0;
        }

        /* ============ PROMPT ============ */

        /**
         * Prompt del capo redattore per la revisione di un articolo.
         *
         * Ritorna un array di messaggi (system + user) pronto per
         * Nexus_Tools::wrap_chat() o Nexus_AI_Engine::chat(). Il modello deve
         * rispondere SOLO con il JSON del verdetto (vedi docblock di testa).
         *
         * @param string $title            Titolo dell'articolo.
         * @param string $summary          Riassunto (piombo).
         * @param string $content          Contenuto completo.
         * @param string $category        Categoria/sezione.
         * @param string $note_precedenti Note del giro di revisione precedente ('' = primo giro).
         * @return array
         */
        public static function review_prompt( $title, $summary, $content, $category, $note_precedenti = '' ) {
                $title   = trim( wp_strip_all_tags( (string) $title ) );
                $summary = trim( wp_strip_all_tags( (string) $summary ) );
                $content = trim( wp_strip_all_tags( (string) $content ) );
                $note    = trim( wp_strip_all_tags( (string) $note_precedenti ) );

                $system = "Sei il CAPO REDATTORE AI di una vera testata giornalistica italiana. "
                        . "Prima della pubblicazione leggi gli articoli dei tuoi giornalisti e decidi se sono pronti: "
                        . "valuti il titolo (chiaro, d'impatto, senza clickbait), il piombo, la struttura, il tono "
                        . "giornalistico, gli errori di lingua e la coerenza con la sezione e con i fatti. "
                        . "Se hai a disposizione strumenti di ricerca, verifica i fatti principali prima di decidere. "
                        . "Sei rigoroso ma corretto: quando chiedi una revisione suggerisci sempre come migliorare. "
                        . "Rispondi SEMPRE e SOLO con un oggetto JSON valido, senza testo fuori dal JSON.";

                $user = sprintf(
                        "Sezione: %s\n\nTitolo: %s\n\nPiombo (riassunto): %s\n\nContenuto dell'articolo:\n%s\n\n",
                        trim( (string) $category ),
                        $title,
                        $summary,
                        $content
                );

                if ( '' !== $note ) {
                        $user .= "QUESTO ARTICOLO E' AL GIRO DI REVISIONE: il giornalista ha gia' riscritto il pezzo. "
                                . "Le note del giro precedente del capo redattore (da tenere presenti nel giudizio):\n"
                                . $note . "\n\n";
                }

                $user .= "COMPITO: esamini l'articolo come in redazione e decidi il verdetto:\n"
                        . "- \"approva\": l'articolo e' pronto per la pubblicazione;\n"
                        . "- \"revisiona\": serve un altro giro, scrivi in \"note\" le correzioni precise per il giornalista;\n"
                        . "- \"rifiuta\": l'articolo non e' pubblicabile, spiega in \"note\" il motivo.\n"
                        . "Se ritocchi direttamente il testo, compila i campi edited_title/edited_content/edited_summary "
                        . "con la TUA versione corretta; se non ritocchi nulla lasciali come stringa vuota. "
                        . "Il verdetto va motivato sempre in \"note\".\n\n"
                        . "Rispondi SOLO con un oggetto JSON valido, nient'altro:\n"
                        . '{"verdict":"approva|revisiona|rifiuta","note":"nota per il giornalista","edited_title":"","edited_content":"","edited_summary":""}';

                return array(
                        array(
                                'role'    => 'system',
                                'content' => $system,
                        ),
                        array(
                                'role'    => 'user',
                                'content' => $user,
                        ),
                );
        }

        /* ============ REVISIONE ============ */

        /**
         * Revisione capo redattore di un articolo (verdetto + applicazione).
         *
         * @param int  $article_id ID articolo.
         * @param bool $force      True = forza anche se gia' rivisto (route REST);
         *                         False = flussi automatici (coda/cron, anti-doppioni).
         * @return array array( ok, article_id, skipped, verdict, chief_status, note, error, message )
         */
        public static function review_article( $article_id, $force = true ) {
                $out = array(
                        'ok'           => false,
                        'article_id'   => absint( $article_id ),
                        'skipped'      => false,
                        'verdict'      => '',
                        'chief_status' => '',
                        'note'         => '',
                        'error'        => '',
                        'message'      => '',
                );

                // Insurance anti-loop: profondita' massima di revisioni concatenate.
                if ( self::$depth >= self::MAX_DEPTH ) {
                        $out['error'] = 'Troppe revisioni concatenate in questa richiesta: interrotto per prevenire loop.';
                        self::log( 0, 'Capo redattore: ' . $out['error'], 'error' );
                        return $out;
                }

                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'get_article' ) ) {
                        $out['error'] = 'Layer dati Nexus_DB non disponibile.';
                        return $out;
                }

                $article = Nexus_DB::get_article( $article_id );
                if ( ! $article ) {
                        $out['error'] = 'Articolo non trovato.';
                        return $out;
                }

                $status = (string) $article->status;

                // Stati terminali o non in coda: nessuna revisione (come da contratto).
                if ( in_array( $status, array( 'approved', 'published', 'rejected' ), true ) ) {
                        $out['ok']      = true;
                        $out['skipped'] = true;
                        $out['message'] = sprintf( 'Articolo in stato "%s": revisione capo redattore non necessaria.', $status );
                        return $out;
                }
                if ( 'pending_approval' !== $status ) {
                        $out['ok']      = true;
                        $out['skipped'] = true;
                        $out['message'] = sprintf( "Articolo in stato \"%s\": non e' in coda di revisione.", $status );
                        return $out;
                }

                $chief_status = isset( $article->chief_status ) ? (string) $article->chief_status : '';
                $rounds       = isset( $article->chief_rounds ) ? (int) $article->chief_rounds : 0;

                // Anti-doppioni dei SOLI flussi automatici: un articolo gia' visto,
                // modificato o rifiutato non viene riletto (la route REST forza con $force=true).
                if ( ! $force && '' !== $chief_status && 'revisione' !== $chief_status ) {
                        $out['ok']      = true;
                        $out['skipped'] = true;
                        $out['message'] = sprintf( "Articolo gia' rivisto dal capo redattore (stato: %s).", $chief_status );
                        return $out;
                }

                if ( ! self::enabled() ) {
                        $out['ok']      = true;
                        $out['skipped'] = true;
                        $out['message'] = 'Capo redattore non attivo nelle impostazioni.';
                        return $out;
                }

                // Budget mensile AI (coerenza con la pipeline v1.0.3): la revisione
                // e' una chiamata AI, con budget esaurito viene rimandata (mai fatale).
                if ( ! self::budget_allows() ) {
                        $out['ok']      = true;
                        $out['skipped'] = true;
                        $out['message'] = 'Budget mensile AI esaurito: revisione rimandata.';
                        self::log( isset( $article->agent_id ) ? (int) $article->agent_id : 0, 'Capo redattore: revisione articolo #' . absint( $article_id ) . ' rimandata: budget mensile esaurito.', 'warning' );
                        return $out;
                }

                $category = '' !== (string) $article->category ? (string) $article->category : self::agent_category( isset( $article->agent_id ) ? (int) $article->agent_id : 0 );
                $term_id  = self::article_term_id( $article );
                $chief    = self::find_for( $category, $term_id );

                if ( ! $chief ) {
                        $out['ok']      = true;
                        $out['skipped'] = true;
                        $out['message'] = 'Nessun capo redattore disponibile per questa categoria/redazione.';
                        self::log( isset( $article->agent_id ) ? (int) $article->agent_id : 0, sprintf( "Capo redattore: nessun capo disponibile per l'articolo #%d (sezione %s).", absint( $article_id ), self::clip( $category, 60 ) ), 'info' );
                        return $out;
                }

                // Lock anti-concorrenza: coda e cron possono sovrapporsi sullo stesso articolo.
                $lock_key = self::LOCK_PREFIX . absint( $article_id );
                if ( get_transient( $lock_key ) ) {
                        $out['ok']      = true;
                        $out['skipped'] = true;
                        $out['message'] = "Revisione capo redattore gia' in corso per questo articolo.";
                        return $out;
                }
                set_transient( $lock_key, 1, self::LOCK_TTL );

                self::$depth++;

                try {
                        $out = self::run_review( $out, $article, $chief, $force );
                } catch ( Throwable $e ) {
                        $out['error'] = self::clip( 'Errore imprevisto: ' . $e->getMessage(), 300 );
                        self::log( isset( $article->agent_id ) ? (int) $article->agent_id : 0, sprintf( 'Capo redattore: revisione articolo #%d fallita: %s', absint( $article_id ), self::clip( $e->getMessage(), 300 ) ), 'error' );
                } finally {
                        self::$depth--;
                        delete_transient( $lock_key );
                }

                return $out;
        }

        /**
         * Corpo della revisione (chiamata AI + applicazione del verdetto).
         *
         * Separato da review_article per la gestione try/catch/finally del lock
         * e della profondita'. Ritorna l'array di esito aggiornato.
         *
         * @param array  $out     Esito parziale da aggiornare.
         * @param object $article Articolo corrente.
         * @param object $chief   Capo redattore scelto.
         * @param bool   $force   Forza revisione anche se gia' rivisto.
         * @return array
         */
        private static function run_review( $out, $article, $chief, $force ) {
                $article_id   = absint( $out['article_id'] );
                $agent_id     = isset( $article->agent_id ) ? (int) $article->agent_id : 0;
                $chief_status = isset( $article->chief_status ) ? (string) $article->chief_status : '';

                // Giro di revisione: le note precedenti del capo guidano il secondo giudizio.
                $note_precedenti = '';
                if ( 'revisione' === $chief_status && isset( $article->chief_note ) && '' !== trim( (string) $article->chief_note ) ) {
                        $note_precedenti = (string) $article->chief_note;
                }

                $messages = self::review_prompt(
                        (string) $article->title,
                        (string) $article->summary,
                        (string) $article->content,
                        isset( $article->category ) ? (string) $article->category : '',
                        $note_precedenti
                );

                // v1.1.9 — Budget AI per-articolo del capo (transient 24h): la revisione
                // con strumenti puo' moltiplicare le chiamate; senza tetto un articolo
                // problematico brucia il budget mensile su un solo pezzo. Si conta ogni
                // avvio di revisione (una sola wrap_chat puo' includere piu' call AI:
                // contare qui e' il tetto piu' onesto senza toccare il motore). Il budget
                // mensile esistente NON e' toccato.
                $budget_key   = self::BUDGET_PREFIX . $article_id;
                $budget_used  = (int) get_transient( $budget_key );
                $budget_max   = self::chief_budget_limit( $article_id );

                if ( $budget_used >= $budget_max ) {
                        // Hard stop: chief_status 'revisione' + nota onesta. Idempotente: se la
                        // nota e' gia' quella non si riscrive e non si rigonfia il log a ogni tick
                        // (run_for_pending ripescarebbe l'articolo: qui costa zero, nessuna AI).
                        $budget_note   = 'budget AI di revisione esaurito per questo articolo';
                        $already_noted = isset( $article->chief_note ) && trim( (string) $article->chief_note ) === $budget_note;

                        $out['ok']           = true;
                        $out['skipped']      = true;
                        $out['chief_status'] = 'revisione';
                        $out['note']         = $budget_note;
                        $out['message']      = 'Budget AI di revisione esaurito per questo articolo: revisione sospesa.';

                        if ( ! $already_noted ) {
                                $saved = self::save_chief_columns(
                                        $article_id,
                                        array(
                                                'chief_status'      => 'revisione',
                                                'chief_note'        => $budget_note,
                                                'chief_agent_id'    => (int) $chief->id,
                                                'chief_reviewed_at' => current_time( 'mysql' ),
                                        )
                                );
                                if ( $saved['ok'] ) {
                                        self::log( $agent_id, sprintf( "Capo redattore: budget AI di revisione esaurito per l'articolo #%d (%d revisioni in 24h): sospeso, serve intervento umano.", $article_id, $budget_max ), 'warning' );
                                }
                        }

                        return $out;
                }

                // Incremento PRIMA della chiamata: contano anche i tentativi falliti/timeout
                // (il costo API e' gia' stato pagato dal tentativo).
                set_transient( $budget_key, $budget_used + 1, self::BUDGET_TTL );

                // Contratto T-03: il capo passa da Nexus_Tools::wrap_chat (T-04) se
                // disponibile (tool-use loop con ricerca web), altrimenti chat diretta.
                $opts = array(
                        'action'      => 'chief_review',
                        'agent_id'    => (int) $chief->id,
                        'temperature' => 0.3,
                        'max_tokens'  => 3000,
                        'model'       => isset( $chief->ai_model ) ? (string) $chief->ai_model : '',
                        'provider'    => isset( $chief->ai_provider ) ? (string) $chief->ai_provider : '',
                        // v1.1.9 (contratto Task 4.8): il verdetto e' un oggetto JSON. Opzione
                        // opzionale: motori piu' vecchi o provider che non la supportano la ignorano.
                        'json_mode'   => true,
                );

                if ( class_exists( 'Nexus_Tools' ) && method_exists( 'Nexus_Tools', 'wrap_chat' ) ) {
                        $res = Nexus_Tools::wrap_chat( $messages, $opts );
                } elseif ( class_exists( 'Nexus_AI_Engine' ) && method_exists( 'Nexus_AI_Engine', 'chat' ) ) {
                        $res = Nexus_AI_Engine::chat( $messages, $opts );
                } else {
                        $res = array( 'ok' => false, 'content' => '', 'error' => 'Motore AI non disponibile.', 'simulated' => false );
                }

                if ( ! is_array( $res ) || empty( $res['ok'] ) ) {
                        $out['error'] = self::chat_error( $res, 'motore AI non disponibile' );
                        self::log( $agent_id, sprintf( 'Capo redattore: revisione articolo #%d fallita: %s', $article_id, $out['error'] ), 'error' );
                        return $out;
                }

                $data = null;
                if ( class_exists( 'Nexus_AI_Engine' ) && method_exists( 'Nexus_AI_Engine', 'extract_json' ) ) {
                        $data = Nexus_AI_Engine::extract_json( (string) $res['content'] );
                }

                if ( ! is_array( $data ) ) {
                        $out['error'] = 'Risposta del capo redattore non interpretabile (JSON mancante o non valido).';
                        self::log( $agent_id, sprintf( 'Capo redattore: revisione articolo #%d con risposta non interpretabile.', $article_id ), 'error' );
                        return $out;
                }

                $verdict = strtolower( trim( (string) ( isset( $data['verdict'] ) ? $data['verdict'] : '' ) ) );
                if ( ! in_array( $verdict, array( 'approva', 'revisiona', 'rifiuta' ), true ) ) {
                        // Verdetto fuori elenco: revisione conservativa (mai pubblicare con dati ambigui).
                        self::log( $agent_id, sprintf( "Capo redattore: verdetto non riconosciuto (\"%s\") sull'articolo #%d: trattato come richiesta di revisione.", self::clip( $verdict, 40 ), $article_id ), 'warning' );
                        $verdict = 'revisiona';
                }

                $note = self::clip( wp_strip_all_tags( (string) ( isset( $data['note'] ) ? $data['note'] : '' ) ), 2000 );

                $edited = array(
                        'title'   => trim( wp_strip_all_tags( (string) ( isset( $data['edited_title'] ) ? $data['edited_title'] : '' ) ) ),
                        'content' => trim( wp_strip_all_tags( (string) ( isset( $data['edited_content'] ) ? $data['edited_content'] : '' ) ) ),
                        'summary' => trim( wp_strip_all_tags( (string) ( isset( $data['edited_summary'] ) ? $data['edited_summary'] : '' ) ) ),
                );

                $out['verdict'] = $verdict;
                $out['note']    = $note;

                $applied = self::apply_verdict( $out, $article, $chief, $verdict, $note, $edited );

                return $applied;
        }

        /**
         * Applica il verdetto del capo redattore all'articolo.
         *
         * Colonne chief_ scritte via update diretto su nexus_articles (schema
         * T-02, v1.1.4); sostituzioni di testo via Nexus_DB::update_article.
         *
         * @param array  $out     Esito parziale da aggiornare.
         * @param object $article Articolo corrente.
         * @param object $chief   Capo redattore.
         * @param string $verdict 'approva' | 'revisiona' | 'rifiuta'.
         * @param string $note    Nota del capo.
         * @param array  $edited  array( title, content, summary ) con gli eventuali ritocchi.
         * @return array Esito aggiornato.
         */
        private static function apply_verdict( $out, $article, $chief, $verdict, $note, $edited ) {
                $article_id   = absint( $out['article_id'] );
                $agent_id     = isset( $article->agent_id ) ? (int) $article->agent_id : 0;
                $settings     = self::settings();
                $auto_publish = ! empty( $settings['chief_auto_publish'] );
                $now          = current_time( 'mysql' );

                $edited_title   = isset( $edited['title'] ) ? (string) $edited['title'] : '';
                $edited_content = isset( $edited['content'] ) ? trim( (string) $edited['content'] ) : '';
                $edited_summary = isset( $edited['summary'] ) ? (string) $edited['summary'] : '';
                $has_edits      = '' !== $edited_content;

                /* ---- APPROVA ---- */
                if ( 'approva' === $verdict ) {
                        $chief_status = $has_edits ? 'modificato' : 'visto';

                        if ( $has_edits ) {
                                // v1.1.9: snapshot PRIMA dell'edit (schema v2): l'originale non resta
                                // solo nel log attività ma è ripristinabile dall'admin (revisioni).
                                self::snapshot_revision( $article_id, $chief );

                                // Il capo ha ritoccato il testo: l'originale resta SOLO nel log attivita'.
                                $fields = array();
                                if ( '' !== $edited_title ) {
                                        $fields['title'] = $edited_title;
                                }
                                $fields['content'] = $edited_content;
                                if ( '' !== $edited_summary ) {
                                        $fields['summary'] = $edited_summary;
                                }
                                if ( method_exists( 'Nexus_DB', 'update_article' ) ) {
                                        Nexus_DB::update_article( $article_id, $fields );
                                }
                                self::log( $agent_id, sprintf( "Capo redattore ha modificato l'articolo #%d (versione originale nel log). Titolo precedente: \"%s\". Nota: %s", $article_id, self::clip( (string) $article->title, 100 ), self::clip( '' !== $note ? $note : 'nessuna nota', 200 ) ), 'warning' );
                        }

                        $saved = self::save_chief_columns(
                                $article_id,
                                array(
                                        'chief_status'      => $chief_status,
                                        'chief_note'        => $note,
                                        'chief_agent_id'    => (int) $chief->id,
                                        'chief_reviewed_at' => $now,
                                )
                        );
                        if ( ! $saved['ok'] ) {
                                $out['error'] = $saved['error'];
                                self::log( $agent_id, sprintf( 'Capo redattore: salvataggio esito su articolo #%d fallito: %s', $article_id, $saved['error'] ), 'error' );
                                return $out;
                        }
                        $out['chief_status'] = $chief_status;

                        if ( $auto_publish ) {
                                // Pubblicazione automatica: approva + pubblica (stesso percorso umano).
                                if ( method_exists( 'Nexus_DB', 'update_article' ) ) {
                                        Nexus_DB::update_article( $article_id, array( 'status' => 'approved' ) );
                                }
                                if ( method_exists( 'Nexus_DB', 'set_approval' ) ) {
                                        Nexus_DB::set_approval( $article_id, 'approved', '' !== $note ? 'Capo redattore AI: ' . $note : 'Approvato dal capo redattore AI.' );
                                }
                                self::log( $agent_id, sprintf( 'Capo redattore: articolo #%d approvato%s.', $article_id, $has_edits ? ' nella versione modificata' : '' ), 'success' );

                                if ( class_exists( 'Nexus_Pipeline' ) && method_exists( 'Nexus_Pipeline', 'publish' ) ) {
                                        $publication = Nexus_Pipeline::publish( $article_id );
                                        if ( is_array( $publication ) && ! empty( $publication['ok'] ) && empty( $publication['already'] ) ) {
                                                self::log( $agent_id, sprintf( 'Pubblicato dal capo redattore AI: articolo #%d.', $article_id ), 'success' );
                                        } elseif ( is_array( $publication ) && ! empty( $publication['error'] ) ) {
                                                self::log( $agent_id, sprintf( "Pubblicazione automatica dell'articolo #%d fallita dopo approvazione del capo: %s", $article_id, self::clip( (string) $publication['error'], 300 ) ), 'error' );
                                        } elseif ( is_array( $publication ) && ! empty( $publication['already'] ) ) {
                                                self::log( $agent_id, sprintf( "Pubblicato dal capo redattore AI: articolo #%d (gia' pubblicato in precedenza).", $article_id ), 'info' );
                                        }
                                } else {
                                        self::log( $agent_id, sprintf( 'Capo redattore: articolo #%d in stato approved (modulo di pubblicazione non disponibile).', $article_id ), 'info' );
                                }
                        } else {
                                self::log( $agent_id, sprintf( 'Capo redattore: articolo #%d visto%s. In attesa di approvazione umana.', $article_id, $has_edits ? ' e modificato' : '' ), 'success' );
                        }

                        return $out;
                }

                /* ---- REVISIONA (giro di revisione con cap rigido) ---- */
                if ( 'revisiona' === $verdict ) {
                        $max_rounds = self::max_rounds( $settings );
                        $rounds     = isset( $article->chief_rounds ) ? (int) $article->chief_rounds : 0;

                        if ( $rounds < $max_rounds ) {
                                $new_rounds = $rounds + 1;

                                $saved = self::save_chief_columns(
                                        $article_id,
                                        array(
                                                'chief_status'      => 'revisione',
                                                'chief_note'        => $note,
                                                'chief_agent_id'    => (int) $chief->id,
                                                'chief_reviewed_at' => $now,
                                                'chief_rounds'      => $new_rounds,
                                        )
                                );
                                if ( ! $saved['ok'] ) {
                                        // Aggiornamento fallito (colonne mancanti/db): NESSUN dispatch,
                                        // altrimenti il contatore non cresce e il giro non termina mai.
                                        $out['error'] = $saved['error'];
                                        self::log( $agent_id, sprintf( "Capo redattore: impossibile salvare il giro di revisione sull'articolo #%d: %s", $article_id, $saved['error'] ), 'error' );
                                        return $out;
                                }

                                // Cap rigido anti-loop: il contatore DEVE essere persistito.
                                $fresh = Nexus_DB::get_article( $article_id );
                                if ( ! $fresh || ! isset( $fresh->chief_rounds ) || (int) $fresh->chief_rounds !== $new_rounds ) {
                                        $out['error'] = 'Contatore giri di revisione non persistito: revisione interrotta per prevenire loop.';
                                        self::log( $agent_id, sprintf( 'Capo redattore: %s (articolo #%d)', $out['error'], $article_id ), 'error' );
                                        return $out;
                                }

                                $out['chief_status'] = 'revisione';
                                self::log( $agent_id, sprintf( 'Capo redattore: articolo #%d in revisione (giro %d/%d). Nota al giornalista: %s', $article_id, $new_rounds, $max_rounds, self::clip( '' !== $note ? $note : 'nessuna nota', 200 ) ), 'warning' );

                                // v1.1.9: snapshot PRIMA della riscrittura del giro di revisione
                                // (la rewrite del giornalista sovrascrive title/content/summary).
                                self::snapshot_revision( $article_id, $chief );

                                // Il giornalista rigenera l'articolo: la rewrite legge gia'
                                // l'articolo e, se chief_status e' 'revisione', allega la nota del capo.
                                $rewritten = false;
                                if ( class_exists( 'Nexus_Pipeline' ) && method_exists( 'Nexus_Pipeline', 'rewrite' ) ) {
                                        try {
                                                $rw        = Nexus_Pipeline::rewrite( $article_id );
                                                $rewritten = is_array( $rw ) && ! empty( $rw['ok'] );
                                                if ( ! $rewritten && is_array( $rw ) && ! empty( $rw['error'] ) ) {
                                                        self::log( $agent_id, sprintf( 'Riscrittura articolo #%d fallita nel giro di revisione: %s', $article_id, self::clip( (string) $rw['error'], 300 ) ), 'error' );
                                                }
                                        } catch ( Throwable $e ) {
                                                self::log( $agent_id, sprintf( 'Riscrittura articolo #%d fallita nel giro di revisione: %s', $article_id, self::clip( $e->getMessage(), 300 ) ), 'error' );
                                        }
                                } else {
                                        self::log( $agent_id, sprintf( 'Capo redattore: modulo riscrittura non disponibile, articolo #%d resta in revisione con la nota del capo.', $article_id ), 'warning' );
                                }

                                // Nuovo giro di revisione SOLO se il testo e' stato rigenerato
                                // (con la riscrittura fallita rilasciare la revisione non serve:
                                // il cron rileggera' l'articolo con rounds sotto cap).
                                if ( $rewritten ) {
                                        self::redispatch( $article_id );
                                }

                                return $out;
                        }

                        // Giri esauriti: badge 'revisione' + nota, NESSUN ulteriore dispatch.
                        $exhausted  = sprintf( 'Revisioni esaurite (%d giri): richiesta intervento umano.', $max_rounds );
                        $final_note = self::cut( '' !== $note ? $exhausted . ' Nota del capo redattore: ' . $note : $exhausted, 2000 );

                        $saved = self::save_chief_columns(
                                $article_id,
                                array(
                                        'chief_status'      => 'revisione',
                                        'chief_note'        => $final_note,
                                        'chief_agent_id'    => (int) $chief->id,
                                        'chief_reviewed_at' => $now,
                                )
                        );
                        if ( ! $saved['ok'] ) {
                                $out['error'] = $saved['error'];
                                self::log( $agent_id, sprintf( 'Capo redattore: salvataggio esito su articolo #%d fallito: %s', $article_id, $saved['error'] ), 'error' );
                                return $out;
                        }

                        $out['chief_status'] = 'revisione';
                        self::log( $agent_id, sprintf( "Capo redattore: giri di revisione esauriti sull'articolo #%d: richiesta intervento umano.", $article_id ), 'warning' );
                        return $out;
                }

                /* ---- RIFIUTA ---- */
                $saved = self::save_chief_columns(
                        $article_id,
                        array(
                                'chief_status'      => 'rifiutato',
                                'chief_note'        => $note,
                                'chief_agent_id'    => (int) $chief->id,
                                'chief_reviewed_at' => $now,
                        )
                );
                if ( ! $saved['ok'] ) {
                        $out['error'] = $saved['error'];
                        self::log( $agent_id, sprintf( 'Capo redattore: salvataggio esito su articolo #%d fallito: %s', $article_id, $saved['error'] ), 'error' );
                        return $out;
                }

                $out['chief_status'] = 'rifiutato';

                if ( $auto_publish ) {
                        $reject_note = '' !== $note ? 'Capo redattore AI: ' . $note : 'Rifiutato dal capo redattore AI.';

                        // Percorso preferito: Nexus_Pipeline::reject() (stesse scritture di
                        // review(): update_article + set_approval, con log 'evaluating').
                        $rejected = false;
                        if ( class_exists( 'Nexus_Pipeline' ) && method_exists( 'Nexus_Pipeline', 'reject' ) ) {
                                try {
                                        $rejected_res = Nexus_Pipeline::reject( $article_id, $reject_note );
                                        $rejected     = is_array( $rejected_res ) && ! empty( $rejected_res['ok'] );
                                        if ( ! $rejected && is_array( $rejected_res ) && ! empty( $rejected_res['error'] ) ) {
                                                self::log( $agent_id, sprintf( 'Rifiuto automatico articolo #%d fallito: %s', $article_id, self::clip( (string) $rejected_res['error'], 300 ) ), 'error' );
                                        }
                                } catch ( Throwable $e ) {
                                        self::log( $agent_id, sprintf( 'Rifiuto automatico articolo #%d fallito: %s', $article_id, self::clip( $e->getMessage(), 300 ) ), 'error' );
                                }
                        }

                        // Fallback / route diretta: stesse scritture interne di review()
                        // (stato 'rejected' + log approvazione), come il percorso "approva".
                        if ( ! $rejected ) {
                                if ( method_exists( 'Nexus_DB', 'update_article' ) ) {
                                        Nexus_DB::update_article( $article_id, array( 'status' => 'rejected' ) );
                                }
                                if ( method_exists( 'Nexus_DB', 'set_approval' ) ) {
                                        Nexus_DB::set_approval( $article_id, 'rejected', $reject_note );
                                }
                        }

                        self::log( $agent_id, sprintf( 'Capo redattore: articolo #%d rifiutato.', $article_id ), 'warning' );
                } else {
                        self::log( $agent_id, sprintf( 'Capo redattore: articolo #%d rifiutato: resta in coda per la decisione umana.', $article_id ), 'warning' );
                }

                return $out;
        }

        /* ============ CODA E CRON ============ */

        /**
         * Handler coda 'nexus_chief_review'.
         *
         * Nexus_Queue invoca gli handler con gli args POSIZIONALI (array_values):
         * l'ID articolo arriva come int; la chiamata diretta con array
         * ( 'article_id' => int ) resta supportata. try/catch totale, mai fatale.
         *
         * @param int|array $args ID articolo o array( 'article_id' => int ).
         * @return array
         */
        public static function queue_review( $args = array() ) {
                $article_id = 0;

                if ( is_array( $args ) ) {
                        if ( isset( $args['article_id'] ) ) {
                                $article_id = absint( $args['article_id'] );
                        } elseif ( isset( $args[0] ) ) {
                                $article_id = absint( $args[0] );
                        }
                } else {
                        $article_id = absint( $args );
                }

                if ( $article_id < 1 ) {
                        self::log( 0, 'Capo redattore: lavoro di revisione senza article_id valido.', 'error' );
                        return array(
                                'ok'      => false,
                                'skipped' => false,
                                'error'   => 'article_id mancante o non valido.',
                        );
                }

                try {
                        return self::review_article( $article_id, false );
                } catch ( Throwable $e ) {
                        self::log( 0, sprintf( 'Capo redattore: revisione articolo #%d fallita: %s', $article_id, self::clip( $e->getMessage(), 300 ) ), 'error' );
                        return array(
                                'ok'      => false,
                                'skipped' => false,
                                'error'   => self::clip( $e->getMessage(), 300 ),
                        );
                }
        }

        /**
         * Revisione degli articoli in attesa (usata da Nexus_Pipeline::cron_tick).
         *
         * Seleziona gli articoli in pending_approval con chief_status '' oppure
         * 'revisione' (con chief_rounds sotto il cap) e chiama review_article con
         * $force=false. Mai fatale: query protetta e per-article try/catch.
         *
         * @param int $limit Numero massimo di articoli da revisionare.
         * @return array array( ok, processed, skipped, errors )
         */
        public static function run_for_pending( $limit = 5 ) {
                $out = array(
                        'ok'        => true,
                        'processed' => 0,
                        'skipped'   => 0,
                        'errors'    => array(),
                );

                if ( ! self::enabled() ) {
                        return $out;
                }

                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'tables' ) || ! method_exists( 'Nexus_DB', 'get_article' ) ) {
                        return $out;
                }

                global $wpdb;
                $table      = Nexus_DB::tables()['articles'];
                $max_rounds = self::max_rounds( self::settings() );
                $limit      = min( 20, max( 1, absint( $limit ) ) );

                $sql = $wpdb->prepare(
                        "SELECT id FROM {$table} WHERE status = %s AND ( chief_status = %s OR ( chief_status = %s AND chief_rounds < %d ) ) ORDER BY id ASC LIMIT %d",
                        'pending_approval',
                        '',
                        'revisione',
                        $max_rounds,
                        $limit
                );

                $rows = $wpdb->get_results( $sql );

                if ( ! is_array( $rows ) ) {
                        // Schema non aggiornato o tabella non disponibile: mai fatale.
                        $out['errors'][] = 'Query articoli in attesa non riuscita (schema database non aggiornato?).';
                        self::log( 0, "Capo redattore: query articoli in attesa fallita (verifica l'aggiornamento dello schema).", 'error' );
                        return $out;
                }

                foreach ( $rows as $row ) {
                        $id = isset( $row->id ) ? absint( $row->id ) : 0;
                        if ( $id < 1 ) {
                                continue;
                        }

                        try {
                                $res = self::review_article( $id, false );
                                if ( is_array( $res ) && ! empty( $res['ok'] ) && empty( $res['skipped'] ) ) {
                                        $out['processed']++;
                                } else {
                                        $out['skipped']++;
                                }
                        } catch ( Throwable $e ) {
                                $out['errors'][] = sprintf( 'Articolo #%d: %s', $id, self::clip( $e->getMessage(), 200 ) );
                        }
                }

                return $out;
        }

        /* ============ HELPER PRIVATI ============ */

        /**
         * Re-dispatch della revisione dopo la riscrittura: coda se disponibile,
         * altrimenti chiamata diretta (profondita' gia' limitata da MAX_DEPTH
         * e dal cap sui giri in database).
         *
         * @param int $article_id ID articolo.
         * @return void
         */
        private static function redispatch( $article_id ) {
                if ( class_exists( 'Nexus_Queue' ) && method_exists( 'Nexus_Queue', 'dispatch' ) ) {
                        try {
                                Nexus_Queue::dispatch( self::QUEUE_HOOK, array( 'article_id' => (int) $article_id ) );
                                return;
                        } catch ( Throwable $e ) {
                                // Coda non disponibile: fallback alla revisione diretta.
                        }
                }

                self::review_article( (int) $article_id, false );
        }

        /**
         * Scrittura delle colonne chief_* dell'articolo via $wpdb->update.
         *
         * updated_at viene sempre aggiornato. Ritorna array( ok, error ):
         * false da $wpdb->update (errore DB, es. colonne v1.1.4 mancanti)
         * produce ok=false con il messaggio dell'ultimo errore.
         *
         * @param int   $article_id ID articolo.
         * @param array $fields     Campi chief_* da scrivere.
         * @return array
         */
        private static function save_chief_columns( $article_id, $fields ) {
                global $wpdb;

                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'tables' ) ) {
                        return array( 'ok' => false, 'error' => 'Layer dati Nexus_DB non disponibile.' );
                }

                $formats_map = array(
                        'chief_status'      => '%s',
                        'chief_note'        => '%s',
                        'chief_agent_id'    => '%d',
                        'chief_reviewed_at' => '%s',
                        'chief_rounds'      => '%d',
                );

                $fields  = is_array( $fields ) ? $fields : array();
                $allowed = array();
                $formats = array();

                foreach ( $formats_map as $key => $format ) {
                        if ( array_key_exists( $key, $fields ) ) {
                                $allowed[ $key ] = $fields[ $key ];
                                $formats[]       = $format;
                        }
                }

                if ( empty( $allowed ) ) {
                        return array( 'ok' => true, 'error' => '' );
                }

                $allowed['updated_at'] = current_time( 'mysql' );
                $formats[]             = '%s';

                $result = $wpdb->update(
                        Nexus_DB::tables()['articles'],
                        $allowed,
                        array( 'id' => absint( $article_id ) ),
                        $formats,
                        array( '%d' )
                );

                if ( false === $result ) {
                        $db_error = '' !== (string) $wpdb->last_error ? self::clip( (string) $wpdb->last_error, 200 ) : 'aggiornamento database fallito (colonne chief_ della v1.1.4 mancanti?)';
                        return array( 'ok' => false, 'error' => $db_error );
                }

                return array( 'ok' => true, 'error' => '' );
        }

        /**
         * Snapshot dell'articolo PRIMA di una sovrascrittura del testo da parte
         * del capo (edit sull'approvazione o riscrittura del giro di revisione)
         * (v1.1.9, schema v2). Best effort: un fallimento NON blocca la revisione
         * (l'originale resta quantomeno nel log attività, come prima della v1.1.9).
         *
         * @param int    $article_id ID articolo.
         * @param object $chief      Capo redattore (per l'etichetta editor).
         * @return void
         */
        private static function snapshot_revision( $article_id, $chief ) {
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'insert_article_revision' ) ) {
                        return; // Schema v2 non ancora applicato: snapshot non disponibile.
                }

                try {
                        Nexus_DB::insert_article_revision(
                                absint( $article_id ),
                                'capo redattore: ' . self::clip( isset( $chief->name ) ? (string) $chief->name : 'AI', 100 )
                        );
                } catch ( Throwable $e ) {
                        return; // Mai bloccare la revisione per lo snapshot.
                }
        }

        /**
         * Tetto di revisioni AI del capo per un singolo articolo in 24h (v1.1.9).
         * Default 30, filtrabile con 'nexus_chief_budget_limit'.
         *
         * @param int $article_id ID articolo (passato al filtro per tetto per-articolo).
         * @return int
         */
        private static function chief_budget_limit( $article_id ) {
                $limit = (int) apply_filters( 'nexus_chief_budget_limit', 30, absint( $article_id ) );
                return max( 1, $limit );
        }

        /**
         * Massimo di giri di revisione (settings chief_max_rounds, clamp 1-5).
         *
         * @param array $settings Settings del plugin.
         * @return int
         */
        private static function max_rounds( $settings ) {
                $max = absint( isset( $settings['chief_max_rounds'] ) ? $settings['chief_max_rounds'] : 2 );
                $max = min( 5, max( 1, $max ) );
                return $max;
        }

        /**
         * Guardia budget mensile AI (coerenza con la pipeline v1.0.3).
         *
         * @return bool True se la revisione puo' procedere.
         */
        private static function budget_allows() {
                if ( ! class_exists( 'Nexus_AI_Engine' ) || ! method_exists( 'Nexus_AI_Engine', 'budget_exceeded' ) ) {
                        return true;
                }

                try {
                        return ! Nexus_AI_Engine::budget_exceeded();
                } catch ( Throwable $e ) {
                        return true; // Mai bloccare la revisione per un errore del controllo budget.
                }
        }

        /**
         * Messaggio d'errore leggibile da una risposta chat AI.
         *
         * @param mixed  $res      Risposta del motore AI.
         * @param string $fallback Default se non ci sono errori espliciti.
         * @return string
         */
        private static function chat_error( $res, $fallback ) {
                $error = ( is_array( $res ) && ! empty( $res['error'] ) ) ? (string) $res['error'] : $fallback;
                return self::clip( $error, 300 );
        }

        /**
         * Impostazioni del plugin in forma difensiva.
         *
         * @return array
         */
        private static function settings() {
                if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'get_settings' ) ) {
                        $settings = Nexus_DB::get_settings();
                        return is_array( $settings ) ? $settings : array();
                }
                return array();
        }

        /**
         * Confronto testi case-insensitive (UTF-8 quando disponibile).
         *
         * @param string $a Primo testo.
         * @param string $b Secondo testo.
         * @return bool
         */
        private static function same_text( $a, $b ) {
                $a = trim( (string) $a );
                $b = trim( (string) $b );

                if ( function_exists( 'mb_strtolower' ) ) {
                        return mb_strtolower( $a, 'UTF-8' ) === mb_strtolower( $b, 'UTF-8' );
                }
                return strtolower( $a ) === strtolower( $b );
        }

        /**
         * Categoria dell'agente con fallback.
         *
         * @param int $agent_id ID agente.
         * @return string
         */
        private static function agent_category( $agent_id ) {
                if ( $agent_id && class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'get_agent' ) ) {
                        $agent = Nexus_DB::get_agent( $agent_id );
                        if ( $agent && ! empty( $agent->category ) ) {
                                return (string) $agent->category;
                        }
                }
                return 'news';
        }

        /**
         * Log attivita' della redazione (azione 'evaluating': giudizio editoriale,
         * come le approvazioni/rifiuti della pipeline; NON tocca last_run).
         *
         * @param int    $agent_id ID agente (0 = sistema).
         * @param string $detail   Dettaglio.
         * @param string $status   Stato (success|error|warning|info).
         * @return void
         */
        private static function log( $agent_id, $detail, $status ) {
                if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                        Nexus_DB::insert_activity( absint( $agent_id ), 'evaluating', self::clip( $detail, 500 ), $status );
                }
        }

        /**
         * Normalizza spazi e tronca una stringa (per log e note).
         *
         * @param string $text Testo.
         * @param int    $max  Lunghezza massima.
         * @return string
         */
        private static function clip( $text, $max ) {
                $text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
                if ( function_exists( 'mb_substr' ) ) {
                        return mb_substr( $text, 0, max( 1, (int) $max ) );
                }
                return substr( $text, 0, max( 1, (int) $max ) );
        }

        /**
         * Tronca una stringa SENZA collassare gli a-capo (per i testi
         * dell'articolo, dove i paragrafi sono separati da doppio a-capo).
         *
         * @param string $text Testo.
         * @param int    $max  Lunghezza massima.
         * @return string
         */
        private static function cut( $text, $max ) {
                $text = trim( (string) $text );
                if ( function_exists( 'mb_substr' ) ) {
                        return mb_substr( $text, 0, max( 1, (int) $max ) );
                }
                return substr( $text, 0, max( 1, (int) $max ) );
        }
}
