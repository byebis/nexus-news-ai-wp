<?php
/**
 * Nexus CLI — comandi WP-CLI per la redazione AI (v1.0.4 "Scala & Pro"; v1.1.9: coda resiliente).
 *
 * Contratto pubblico (CONTRATTO v1.0.4, sezione §2 T-02):
 *
 * Comando radice: wp nexus
 *   run       [--agent=<id>] [--all]                  — pipeline completa per un agente o per tutti gli attivi
 *   collect   <agent_id>                              — FASE 1: raccolta notizie per un agente
 *   evaluate  <article_id>                            — FASE 2: valutazione editoriale AI
 *   rewrite   <article_id>                            — FASE 3: riscrittura AI (+ fact-check)
 *   publish   <article_id>                            — FASE 5: pubblicazione multi-piattaforma
 *   approve   <article_id> [--note=...]               — approva un articolo
 *   reject    <article_id> [--note=...]               — rifiuta un articolo
 *   translate <article_id> --lang=<codice>            — traduzione AI multi-lingua
 *   agents                                            — tabella agenti (con conteggio articoli)
 *   articles  [--status=...] [--agent=<id>] [--limit=20] — tabella articoli
 *   stats                                             — statistiche chiave → valore
 *   queue     stats|process|work|retry <id>|cancel <id>|cleanup [--days=7]|dead [--reset=<id>] — coda lavori
 *             (v1.1.9: work = worker per supervisord/systemd, dead = lista dead letter con --reset)
 *   cron      status                                  — eventi WP-Cron di Nexus schedulati (v1.1.9)
 *   multilang doctor [--fix]                          — diagnostica multilingua (v1.1.9, richiede Nexus_Multilang::doctor)
 *   settings  get [chiave] | set <chiave> <valore>    — impostazioni del plugin
 *   sources   list [--redazione=<term_id|slug>]       — tabella fonti della redazione (8 tipi, v1.1.10 WP8)
 *             test <tipo> <url|valore> [--query=...]  — testa UNA fonte via adapter Nexus_Sources::fetch_{tipo}
 *                                                       (ReflectionMethod setAccessible, decisione WP8 documentata:
 *                                                       gli adapter sono private) e stampa i primi 5 item (v1.1.10)
 *             cooldown [--clear]                      — transient cooldown fonti attivi (nexus_src_cool_*, chiavi
 *                                                       md5(type|url) NON decodificabili: count + chiavi + scadenza)
 *                                                       oppure azzeramento con --clear → Nexus_Sources::clear_cooldown (v1.1.10)
 *
 * Note:
 * - Il file si auto-disabilita fuori da WP-CLI (guard class_exists('WP_CLI') con return).
 * - L'integratore deve aggiungere nel bootstrap il require_once del file: il guard
 *   interno rende l'inclusione innocua in contesto web.
 * - Dipendenze usate con guard class_exists/method_exists (mai fatale):
 *   Nexus_DB, Nexus_Pipeline, Nexus_Translate, Nexus_Queue (modulo coda v1.0.4 T-01:
 *   finché non è integrato i sottocomandi queue/process|retry|cancel rispondono
 *   con un errore chiaro invece di fallire fatalmente).
 * - Tutte le chiamate pipeline/coda sono in try/catch Throwable con WP_CLI::error
 *   (exit 1) sul fallimento; ogni ID posizionale è validato con absint + verifica
 *   di esistenza; output in italiano; nessuna dipendenza esterna.
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_CLI' ) ) {
        return;
}

class Nexus_CLI {

        /* ========================= PIPELINE ========================= */

        /**
         * Avvia la pipeline editoriale completa (raccolta → valutazione → riscrittura
         * → approvazione → pubblicazione) per un agente o per tutti gli agenti attivi.
         *
         * Senza flag il comando si interrompe con un errore d'uso.
         *
         * @subcommand run
         * @synopsis [--agent=<id>] [--all]
         *
         * @param array $args       Argomenti posizionali.
         * @param array $assoc_args Argomenti associativi (--chiave=valore).
         */
        public static function run( $args, $assoc_args ) {
                self::check_core();
                self::maybe_queue_notice();

                $agent_id = isset( $assoc_args['agent'] ) ? absint( $assoc_args['agent'] ) : 0;
                $all      = ! empty( $assoc_args['all'] );

                if ( ! $all && $agent_id < 1 ) {
                        WP_CLI::error( 'Uso: wp nexus run --all oppure wp nexus run --agent=<id>.' );
                }

                /* ---- Modalità singolo agente ---- */
                if ( $agent_id > 0 ) {
                        $agent = self::require_agent( $agent_id );

                        self::log_progress( sprintf( 'Pipeline avviata per l\'agente «%s» (#%d)...', $agent->name, $agent_id ) );

                        try {
                                $summary = Nexus_Pipeline::run_full( $agent_id );
                        } catch ( Throwable $e ) {
                                WP_CLI::error( sprintf( 'Pipeline agente #%d fallita: %s', $agent_id, $e->getMessage() ) );
                        }

                        self::log_summary_messages( isset( $summary['messages'] ) ? $summary['messages'] : array() );

                        $collected = isset( $summary['collected'] ) ? (int) $summary['collected'] : 0;
                        $published = isset( $summary['published'] ) ? (int) $summary['published'] : 0;

                        if ( empty( $summary['ok'] ) ) {
                                WP_CLI::error( sprintf( 'Pipeline terminata con errori per l\'agente «%s» (#%d): articoli creati %d, pubblicati %d.', $agent->name, $agent_id, $collected, $published ) );
                        }

                        WP_CLI::success( sprintf( 'Pipeline completata per l\'agente «%s» (#%d): %d articoli creati, %d pubblicati.', $agent->name, $agent_id, $collected, $published ) );
                        return;
                }

                /* ---- Modalità --all: loop sugli agenti attivi ---- */
                $active = array();
                foreach ( (array) Nexus_DB::agents() as $agent ) {
                        if ( isset( $agent->status ) && 'active' === $agent->status ) {
                                $active[] = $agent;
                        }
                }

                if ( empty( $active ) ) {
                        WP_CLI::error( 'Nessun agente attivo trovato: attivare almeno un agente prima di lanciare la pipeline.' );
                }

                $total     = count( $active );
                $done      = 0;
                $created   = 0;
                $published = 0;
                $position  = 0;

                foreach ( $active as $agent ) {
                        $position++;
                        $current_id = (int) $agent->id;

                        self::log_progress( sprintf( '[%d/%d] Agente «%s» (#%d): avvio pipeline...', $position, $total, $agent->name, $current_id ) );

                        try {
                                $summary = Nexus_Pipeline::run_full( $current_id );
                        } catch ( Throwable $e ) {
                                WP_CLI::log( sprintf( '[%d/%d] Agente «%s» (#%d): errore %s', $position, $total, $agent->name, $current_id, $e->getMessage() ) );
                                continue;
                        }

                        $done++;
                        $created   += isset( $summary['collected'] ) ? (int) $summary['collected'] : 0;
                        $published += isset( $summary['published'] ) ? (int) $summary['published'] : 0;

                        self::log_summary_messages( isset( $summary['messages'] ) ? $summary['messages'] : array() );
                        WP_CLI::log( sprintf( '[%d/%d] Agente «%s» (#%d): pipeline terminata.', $position, $total, $agent->name, $current_id ) );
                }

                if ( $done < 1 ) {
                        WP_CLI::error( 'Pipeline fallita su tutti gli agenti: controllare i messaggi sopra e le impostazioni AI.' );
                }

                WP_CLI::success( sprintf( 'Pipeline completata: %d agenti elaborati su %d, %d articoli creati, %d pubblicati.', $done, $total, $created, $published ) );
        }

        /**
         * FASE 1 — raccolta notizie per un agente (RSS reali o analisi evergreen).
         *
         * @subcommand collect
         * @synopsis <agent_id>
         *
         * @param array $args       Argomenti posizionali.
         * @param array $assoc_args Argomenti associativi (--chiave=valore).
         */
        public static function collect( $args, $assoc_args ) {
                self::check_core();
                self::maybe_queue_notice();

                $agent = self::require_agent_from_args( $args, 'Uso: wp nexus collect <agent_id>.' );

                try {
                        $result = Nexus_Pipeline::collect( (int) $agent->id );
                } catch ( Throwable $e ) {
                        WP_CLI::error( sprintf( 'Raccolta agente #%d fallita: %s', (int) $agent->id, $e->getMessage() ) );
                }

                if ( ! empty( $result['skipped'] ) ) {
                        WP_CLI::log( sprintf( 'Agente «%s» non attivo: raccolta saltata.', $agent->name ) );
                        return;
                }

                foreach ( (array) ( isset( $result['errors'] ) ? $result['errors'] : array() ) as $error ) {
                        WP_CLI::log( 'Errore: ' . (string) $error );
                }

                foreach ( (array) ( isset( $result['article_ids'] ) ? $result['article_ids'] : array() ) as $article_id ) {
                        WP_CLI::log( sprintf( 'Creato articolo #%d.', (int) $article_id ) );
                }

                $collected = isset( $result['collected'] ) ? (int) $result['collected'] : 0;

                if ( empty( $result['ok'] ) ) {
                        WP_CLI::error( sprintf( 'Raccolta fallita per l\'agente «%s» (articoli creati: %d).', $agent->name, $collected ) );
                }

                WP_CLI::success( sprintf( 'Raccolta completata per l\'agente «%s» (#%d): %d articoli creati.', $agent->name, (int) $agent->id, $collected ) );
        }

        /**
         * FASE 2 — valutazione editoriale AI di un articolo (punteggio 0-100).
         *
         * @subcommand evaluate
         * @synopsis <article_id>
         *
         * @param array $args       Argomenti posizionali.
         * @param array $assoc_args Argomenti associativi (--chiave=valore).
         */
        public static function evaluate( $args, $assoc_args ) {
                self::check_core();
                self::maybe_queue_notice();

                $article = self::require_article_from_args( $args, 'Uso: wp nexus evaluate <article_id>.' );

                try {
                        $result = Nexus_Pipeline::evaluate( (int) $article->id );
                } catch ( Throwable $e ) {
                        WP_CLI::error( sprintf( 'Valutazione articolo #%d fallita: %s', (int) $article->id, $e->getMessage() ) );
                }

                if ( empty( $result['ok'] ) ) {
                        WP_CLI::error( sprintf( 'Valutazione articolo #%d fallita: %s', (int) $article->id, self::result_error( $result ) ) );
                }

                WP_CLI::success( sprintf( 'Articolo #%d valutato: punteggio %d/100.', (int) $article->id, isset( $result['score'] ) ? (int) $result['score'] : 0 ) );
        }

        /**
         * FASE 3 — riscrittura AI dell'articolo in versione editoriale completa
         * (con fact-check automatico se attivo nelle impostazioni).
         *
         * @subcommand rewrite
         * @synopsis <article_id>
         *
         * @param array $args       Argomenti posizionali.
         * @param array $assoc_args Argomenti associativi (--chiave=valore).
         */
        public static function rewrite( $args, $assoc_args ) {
                self::check_core();
                self::maybe_queue_notice();

                $article = self::require_article_from_args( $args, 'Uso: wp nexus rewrite <article_id>.' );

                try {
                        $result = Nexus_Pipeline::rewrite( (int) $article->id );
                } catch ( Throwable $e ) {
                        WP_CLI::error( sprintf( 'Riscrittura articolo #%d fallita: %s', (int) $article->id, $e->getMessage() ) );
                }

                if ( empty( $result['ok'] ) ) {
                        WP_CLI::error( sprintf( 'Riscrittura articolo #%d fallita: %s', (int) $article->id, self::result_error( $result ) ) );
                }

                WP_CLI::success( sprintf( 'Articolo #%d riscritto e messo in coda di approvazione.', (int) $article->id ) );
        }

        /**
         * FASE 5 — pubblicazione multi-piattaforma di un articolo approvato.
         * Stampa una riga di log per piattaforma, incluso l'URL del post quando presente.
         *
         * @subcommand publish
         * @synopsis <article_id>
         *
         * @param array $args       Argomenti posizionali.
         * @param array $assoc_args Argomenti associativi (--chiave=valore).
         */
        public static function publish( $args, $assoc_args ) {
                self::check_core();
                self::maybe_queue_notice();

                $article = self::require_article_from_args( $args, 'Uso: wp nexus publish <article_id>.' );

                try {
                        $result = Nexus_Pipeline::publish( (int) $article->id );
                } catch ( Throwable $e ) {
                        WP_CLI::error( sprintf( 'Pubblicazione articolo #%d fallita: %s', (int) $article->id, $e->getMessage() ) );
                }

                /* Log per piattaforma (URL del post quando presente nel ritorno). */
                foreach ( (array) ( isset( $result['logs'] ) ? $result['logs'] : array() ) as $log ) {
                        $line = sprintf(
                                '[%s] %s',
                                isset( $log['platform'] ) ? (string) $log['platform'] : '',
                                isset( $log['status'] ) && '' !== (string) $log['status'] ? (string) $log['status'] : 'sconosciuto'
                        );
                        if ( ! empty( $log['post_url'] ) ) {
                                $line .= ' - ' . (string) $log['post_url'];
                        }
                        if ( ! empty( $log['error'] ) ) {
                                $line .= ' - ' . (string) $log['error'];
                        }
                        WP_CLI::log( $line );
                }

                if ( empty( $result['ok'] ) ) {
                        WP_CLI::error( sprintf( 'Pubblicazione articolo #%d fallita: %s', (int) $article->id, self::result_error( $result ) ) );
                }

                $message = isset( $result['message'] ) ? (string) $result['message'] : '';

                if ( ! empty( $result['already'] ) ) {
                        WP_CLI::success( sprintf( 'Articolo #%d già pubblicato in precedenza. %s', (int) $article->id, $message ) );
                        return;
                }

                WP_CLI::success( sprintf( 'Articolo #%d pubblicato. %s', (int) $article->id, $message ) );
        }

        /**
         * Approva un articolo (FASE 4: coda di approvazione umana).
         *
         * @subcommand approve
         * @synopsis <article_id> [--note=<nota>]
         *
         * @param array $args       Argomenti posizionali.
         * @param array $assoc_args Argomenti associativi (--chiave=valore).
         */
        public static function approve( $args, $assoc_args ) {
                self::check_core();

                $article = self::require_article_from_args( $args, 'Uso: wp nexus approve <article_id> [--note=<nota>].' );
                $note    = isset( $assoc_args['note'] ) ? sanitize_textarea_field( (string) $assoc_args['note'] ) : '';

                try {
                        $result = Nexus_Pipeline::approve( (int) $article->id, $note );
                } catch ( Throwable $e ) {
                        WP_CLI::error( sprintf( 'Approvazione articolo #%d fallita: %s', (int) $article->id, $e->getMessage() ) );
                }

                if ( empty( $result['ok'] ) ) {
                        WP_CLI::error( sprintf( 'Approvazione articolo #%d fallita: %s', (int) $article->id, self::result_error( $result ) ) );
                }

                WP_CLI::success( sprintf( 'Articolo #%d approvato.', (int) $article->id ) );
        }

        /**
         * Rifiuta un articolo (FASE 4: coda di approvazione umana).
         *
         * @subcommand reject
         * @synopsis <article_id> [--note=<nota>]
         *
         * @param array $args       Argomenti posizionali.
         * @param array $assoc_args Argomenti associativi (--chiave=valore).
         */
        public static function reject( $args, $assoc_args ) {
                self::check_core();

                $article = self::require_article_from_args( $args, 'Uso: wp nexus reject <article_id> [--note=<nota>].' );
                $note    = isset( $assoc_args['note'] ) ? sanitize_textarea_field( (string) $assoc_args['note'] ) : '';

                try {
                        $result = Nexus_Pipeline::reject( (int) $article->id, $note );
                } catch ( Throwable $e ) {
                        WP_CLI::error( sprintf( 'Rifiuto articolo #%d fallito: %s', (int) $article->id, $e->getMessage() ) );
                }

                if ( empty( $result['ok'] ) ) {
                        WP_CLI::error( sprintf( 'Rifiuto articolo #%d fallito: %s', (int) $article->id, self::result_error( $result ) ) );
                }

                WP_CLI::success( sprintf( 'Articolo #%d rifiutato.', (int) $article->id ) );
        }

        /**
         * Traduzione AI multi-lingua di un articolo (modulo Nexus_Translate).
         *
         * @subcommand translate
         * @synopsis <article_id> --lang=<codice>
         *
         * @param array $args       Argomenti posizionali.
         * @param array $assoc_args Argomenti associativi (--chiave=valore).
         */
        public static function translate( $args, $assoc_args ) {
                self::check_core();
                self::maybe_queue_notice();

                if ( ! isset( $assoc_args['lang'] ) || '' === trim( (string) $assoc_args['lang'] ) ) {
                        WP_CLI::error( 'Uso: wp nexus translate <article_id> --lang=<codice> (es. --lang=en).' );
                }

                $lang = sanitize_key( (string) $assoc_args['lang'] );
                if ( '' === $lang ) {
                        WP_CLI::error( 'Codice lingua non valido: usare un codice breve (es. en, es, fr).' );
                }

                if ( ! class_exists( 'Nexus_Translate' ) || ! method_exists( 'Nexus_Translate', 'translate_article' ) ) {
                        WP_CLI::error( 'Modulo traduzioni non disponibile: la classe Nexus_Translate non è caricata.' );
                }

                $article = self::require_article_from_args( $args, 'Uso: wp nexus translate <article_id> --lang=<codice>.' );

                try {
                        $result = Nexus_Translate::translate_article( (int) $article->id, $lang );
                } catch ( Throwable $e ) {
                        WP_CLI::error( sprintf( 'Traduzione articolo #%d fallita: %s', (int) $article->id, $e->getMessage() ) );
                }

                if ( empty( $result['ok'] ) ) {
                        WP_CLI::error( sprintf( 'Traduzione articolo #%d in «%s» fallita: %s', (int) $article->id, $lang, self::result_error( $result ) ) );
                }

                if ( ! empty( $result['already'] ) ) {
                        WP_CLI::success( sprintf( 'Articolo #%d già tradotto in «%s»: traduzione esistente confermata.', (int) $article->id, $lang ) );
                        return;
                }

                WP_CLI::success( sprintf( 'Articolo #%d tradotto in «%s».', (int) $article->id, $lang ) );
        }

        /* ========================= ELENCHI E STATISTICHE ========================= */

        /**
         * Tabella degli agenti con conteggio articoli per ciascuno.
         *
         * @subcommand agents
         * @synopsis
         *
         * @param array $args       Argomenti posizionali.
         * @param array $assoc_args Argomenti associativi (--chiave=valore).
         */
        public static function agents( $args, $assoc_args ) {
                self::check_core();

                $agents = Nexus_DB::agents();
                if ( empty( $agents ) ) {
                        WP_CLI::log( 'Nessun agente configurato.' );
                        return;
                }

                $rows = array();
                foreach ( (array) $agents as $agent ) {
                        $rows[] = array(
                                'ID'        => (int) $agent->id,
                                'Nome'      => (string) $agent->name,
                                'Categoria' => (string) $agent->category,
                                'Stato'     => (string) $agent->status,
                                'Modello'   => '' !== (string) $agent->ai_model ? (string) $agent->ai_model : '(globale)',
                                'Articoli'  => (int) Nexus_DB::count_articles( array( 'agent_id' => (int) $agent->id ) ),
                        );
                }

                \WP_CLI\Utils\format_items( 'table', $rows, array( 'ID', 'Nome', 'Categoria', 'Stato', 'Modello', 'Articoli' ) );
        }

        /**
         * Tabella degli articoli con filtri opzionali (stato, agente, limite).
         *
         * @subcommand articles
         * @synopsis [--status=<stato>] [--agent=<id>] [--limit=<n>]
         *
         * @param array $args       Argomenti posizionali.
         * @param array $assoc_args Argomenti associativi (--chiave=valore).
         */
        public static function articles( $args, $assoc_args ) {
                self::check_core();

                $limit  = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 20;
                $limit  = max( 1, min( 100, $limit ) );
                $status = isset( $assoc_args['status'] ) ? sanitize_key( (string) $assoc_args['status'] ) : '';

                $query_args = array( 'per_page' => $limit );

                if ( '' !== $status ) {
                        $query_args['status'] = $status;
                }

                if ( isset( $assoc_args['agent'] ) ) {
                        $agent_id = absint( $assoc_args['agent'] );
                        if ( $agent_id < 1 ) {
                                WP_CLI::error( 'Valore --agent non valido: specificare un ID numerico.' );
                        }
                        self::require_agent( $agent_id );
                        $query_args['agent_id'] = $agent_id;
                }

                $items = Nexus_DB::articles( $query_args );
                if ( empty( $items ) ) {
                        WP_CLI::log( 'Nessun articolo trovato con i filtri specificati.' );
                        return;
                }

                $rows = array();
                foreach ( (array) $items as $article ) {
                        $agent_name = '-';
                        $agent      = ! empty( $article->agent_id ) ? Nexus_DB::get_agent( (int) $article->agent_id ) : null;
                        if ( $agent ) {
                                $agent_name = (string) $agent->name;
                        }

                        $rows[] = array(
                                'ID'      => (int) $article->id,
                                'Titolo'  => self::clip( (string) $article->title, 40 ),
                                'Stato'   => (string) $article->status,
                                'Agente'  => $agent_name,
                                'Qualità' => (string) round( (float) $article->quality_score, 1 ),
                                'Viste'   => (int) $article->views,
                        );
                }

                \WP_CLI\Utils\format_items( 'table', $rows, array( 'ID', 'Titolo', 'Stato', 'Agente', 'Qualità', 'Viste' ) );
        }

        /**
         * Statistiche generali del plugin (tabella chiave → valore).
         *
         * @subcommand stats
         * @synopsis
         *
         * @param array $args       Argomenti posizionali.
         * @param array $assoc_args Argomenti associativi (--chiave=valore).
         */
        public static function stats( $args, $assoc_args ) {
                self::check_core();

                $labels = array(
                        'total'         => 'Articoli totali',
                        'published'     => 'Articoli pubblicati',
                        'pending'       => 'In attesa di approvazione',
                        'draft'         => 'Bozze',
                        'rejected'      => 'Rifiutati',
                        'approved'      => 'Approvati',
                        'today'         => 'Creati oggi',
                        'views'         => 'Viste totali',
                        'agents_active' => 'Agenti attivi',
                        'activity_24h'  => 'Eventi attività (ultime 24h)',
                );

                $rows = array();
                foreach ( (array) Nexus_DB::stats() as $key => $value ) {
                        $rows[] = array(
                                'Chiave' => isset( $labels[ $key ] ) ? $labels[ $key ] : (string) $key,
                                'Valore' => (int) $value,
                        );
                }

                \WP_CLI\Utils\format_items( 'table', $rows, array( 'Chiave', 'Valore' ) );
        }

        /* ========================= CODA LAVORI ========================= */

        /**
         * Gestione della coda lavori (modulo Nexus_Queue, v1.0.4; v1.1.9 dead letter + worker).
         *
         * Sottocomandi:
         *   stats               — tabella lavori per stato (include 'dead').
         *   process [--limit=10] — esegue un batch di lavori in coda.
         *   work [--iterations=N] [--sleep=S] — worker continuo per supervisord/systemd
         *                          (iterations 0 = infinito, sleep default 5s).
         *   retry <id>          — rimette in coda un lavoro fallito/annullato/completato.
         *   cancel <id>         — annulla un lavoro in attesa.
         *   cleanup [--days=7]  — elimina i lavori chiusi più vecchi di N giorni.
         *   dead [--reset=<id>] [--limit=50] — lista dead letter (o ripristina UN job).
         *
         * @subcommand queue
         * @synopsis <azione> [<id>] [--days=<n>] [--limit=<n>] [--iterations=<n>] [--sleep=<s>] [--reset=<id>]
         *
         * @param array $args       Argomenti posizionali.
         * @param array $assoc_args Argomenti associativi (--chiave=valore).
         */
        public static function queue( $args, $assoc_args ) {
                self::check_core();

                $action = isset( $args[0] ) ? sanitize_key( (string) $args[0] ) : '';

                if ( '' === $action ) {
                        WP_CLI::error( 'Uso: wp nexus queue stats|process|work|retry <id>|cancel <id>|cleanup [--days=7]|dead [--reset=<id>].' );
                }

                switch ( $action ) {
                        case 'stats':
                                $labels = array(
                                        'pending'   => 'In attesa',
                                        'running'   => 'In esecuzione',
                                        'complete'  => 'Completati',
                                        'failed'    => 'Falliti',
                                        'cancelled' => 'Annullati',
                                        'dead'      => 'Dead letter',
                                        'total'     => 'Totale',
                                );

                                $stats = (array) Nexus_DB::job_stats();

                                // v1.1.9 — dead letter: Nexus_DB::job_stats() non conosce
                                // (ancora) lo status 'dead': lo conteggiamo qui con
                                // count_jobs così la CLI resta onesta senza toccare db.php.
                                if ( ! isset( $stats['dead'] ) && method_exists( 'Nexus_DB', 'count_jobs' ) ) {
                                        try {
                                                $stats['dead'] = (int) Nexus_DB::count_jobs( array( 'status' => 'dead' ) );
                                        } catch ( Throwable $e ) {
                                                $stats['dead'] = 0;
                                        }
                                }

                                $rows = array();
                                foreach ( $stats as $key => $value ) {
                                        $rows[] = array(
                                                'Stato'  => isset( $labels[ $key ] ) ? $labels[ $key ] : (string) $key,
                                                'Lavori' => (int) $value,
                                        );
                                }

                                \WP_CLI\Utils\format_items( 'table', $rows, array( 'Stato', 'Lavori' ) );
                                break;

                        case 'process':
                                if ( ! self::queue_ready( 'run_batch' ) ) {
                                        WP_CLI::error( 'Coda non disponibile: il modulo Nexus_Queue non è caricato.' );
                                }

                                // v1.1.9 — i job di pipeline sono lunghi (chiamate AI):
                                // alza il limite di memoria come fa WP admin e togli il
                                // time-limit PHP (il runner ha comunque un budget interno
                                // 'queue_time_budget' che spezza il lavoro tra i job).
                                if ( function_exists( 'wp_raise_memory_limit' ) ) {
                                        wp_raise_memory_limit( 'admin' );
                                }

                                if ( function_exists( 'set_time_limit' ) ) {
                                        @set_time_limit( 0 );
                                }

                                $limit = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 10;
                                $limit = max( 1, $limit );

                                try {
                                        $batch = Nexus_Queue::run_batch( $limit );
                                } catch ( Throwable $e ) {
                                        WP_CLI::error( 'Elaborazione coda fallita: ' . $e->getMessage() );
                                }

                                $processed = isset( $batch['processed'] ) ? (int) $batch['processed'] : 0;
                                $failed    = isset( $batch['failed'] ) ? (int) $batch['failed'] : 0;
                                $requeued  = isset( $batch['requeued'] ) ? (int) $batch['requeued'] : 0;

                                $message = sprintf( 'Batch elaborato: %d lavori processati, %d falliti.', $processed, $failed );

                                if ( $requeued > 0 ) {
                                        // Onestà: job claimati ma non avviati per budget
                                        // tempo/memoria — tornano disponibili subito.
                                        $message .= sprintf( ' %d lavori rimessi in coda (budget tempo/memoria esaurito).', $requeued );
                                }

                                WP_CLI::success( $message );
                                break;

                        case 'work':
                                if ( ! self::queue_ready( 'run_batch' ) ) {
                                        WP_CLI::error( 'Coda non disponibile: il modulo Nexus_Queue non è caricato.' );
                                }

                                $iterations = isset( $assoc_args['iterations'] ) ? absint( $assoc_args['iterations'] ) : 0;
                                $sleep      = isset( $assoc_args['sleep'] ) ? absint( $assoc_args['sleep'] ) : 5;

                                if ( function_exists( 'wp_raise_memory_limit' ) ) {
                                        wp_raise_memory_limit( 'admin' );
                                }

                                if ( function_exists( 'set_time_limit' ) ) {
                                        @set_time_limit( 0 );
                                }

                                // Ctrl-C (SIGINT) e SIGTERM → uscita pulita con 0, così
                                // supervisord/systemd vedono uno stop volontario e non
                                // un crash. Senza estensione pcntl il segnale termina il
                                // processo con il codice di default del sistema: nessun
                                // danno comunque, il runner riprende i job claimati.
                                if ( function_exists( 'pcntl_async_signals' ) && function_exists( 'pcntl_signal' ) && defined( 'SIGINT' ) && defined( 'SIGTERM' ) ) {
                                        pcntl_async_signals( true );
                                        pcntl_signal( SIGINT, array( __CLASS__, 'handle_work_signal' ) );
                                        pcntl_signal( SIGTERM, array( __CLASS__, 'handle_work_signal' ) );
                                }

                                WP_CLI::log( sprintf( 'Worker coda avviato (iterazioni: %s, pausa: %d secondi). Ctrl-C per uscire.', $iterations > 0 ? (string) $iterations : 'infinito', $sleep ) );

                                $done = 0;

                                while ( 0 === $iterations || $done < $iterations ) {
                                        $done++;

                                        try {
                                                $batch = Nexus_Queue::run_batch( 0 );
                                        } catch ( Throwable $e ) {
                                                // Un batch fallito NON deve uccidere il worker:
                                                // loghiamo e ripartiamo alla prossima iterazione.
                                                WP_CLI::log( '[worker] Batch fallito: ' . $e->getMessage() );
                                                $batch = array();
                                        }

                                        $processed = isset( $batch['processed'] ) ? (int) $batch['processed'] : 0;
                                        $failed    = isset( $batch['failed'] ) ? (int) $batch['failed'] : 0;
                                        $requeued  = isset( $batch['requeued'] ) ? (int) $batch['requeued'] : 0;

                                        WP_CLI::log( sprintf( '[worker] iterazione %d: %d eseguiti, %d falliti, %d rimessi in coda.', $done, $processed, $failed, $requeued ) );

                                        // Pausa SOLO quando la coda è vuota (drain rapido se
                                        // c'è lavoro). Minimo 1 secondo anche con --sleep=0:
                                        // evita l'hot-loop sul DB quando non c'è nulla da fare.
                                        if ( 0 === $processed + $failed ) {
                                                sleep( max( 1, $sleep ) );
                                        }
                                }

                                WP_CLI::success( sprintf( 'Worker terminato dopo %d iterazioni.', $done ) );
                                return;

                        case 'retry':
                                $job_id = self::require_job_from_args( $args, 'Uso: wp nexus queue retry <id>.' );

                                if ( ! self::queue_ready( 'retry' ) ) {
                                        WP_CLI::error( 'Coda non disponibile: il modulo Nexus_Queue non è caricato.' );
                                }

                                try {
                                        $retried = Nexus_Queue::retry( $job_id );
                                } catch ( Throwable $e ) {
                                        WP_CLI::error( sprintf( 'Retry lavoro #%d fallito: %s', $job_id, $e->getMessage() ) );
                                }

                                if ( $retried ) {
                                        // v1.1.9 — return OBBLIGATORIO dopo il success: senza,
                                        // l'esecuzione cade nel WP_CLI::error successivo e il
                                        // comando esce 1 anche in caso di successo.
                                        WP_CLI::success( sprintf( 'Lavoro #%d rimesso in coda (stato: pending, ciclo di tentativi rinnovato).', $job_id ) );
                                        return;
                                }

                                WP_CLI::error( sprintf( 'Impossibile rimettere in coda il lavoro #%d.', $job_id ) );
                                break;

                        case 'cancel':
                                $job_id = self::require_job_from_args( $args, 'Uso: wp nexus queue cancel <id>.' );

                                if ( ! self::queue_ready( 'cancel' ) ) {
                                        WP_CLI::error( 'Coda non disponibile: il modulo Nexus_Queue non è caricato.' );
                                }

                                try {
                                        $cancelled = Nexus_Queue::cancel( $job_id );
                                } catch ( Throwable $e ) {
                                        WP_CLI::error( sprintf( 'Annullamento lavoro #%d fallito: %s', $job_id, $e->getMessage() ) );
                                }

                                if ( $cancelled ) {
                                        // v1.1.9 — return dopo il success (exit code corretti).
                                        WP_CLI::success( sprintf( 'Lavoro #%d annullato (stato: cancelled).', $job_id ) );
                                        return;
                                }

                                WP_CLI::error( sprintf( 'Impossibile annullare il lavoro #%d (solo i lavori in attesa possono essere annullati).', $job_id ) );
                                break;

                        case 'cleanup':
                                $days = isset( $assoc_args['days'] ) ? absint( $assoc_args['days'] ) : 7;
                                $days = max( 1, $days );

                                $deleted = (int) Nexus_DB::cleanup_jobs( $days );

                                WP_CLI::success( sprintf( 'Eliminati %d lavori chiusi più vecchi di %d giorni.', $deleted, $days ) );
                                break;

                        case 'dead':
                                // v1.1.9 — dead letter: i job 'dead' sono terminali (fatal/
                                // timeout ripetuti). Lista per analisi; --reset è l'unica
                                // via di ripristino, riservata a un intervento manuale.
                                if ( isset( $assoc_args['reset'] ) ) {
                                        $job_id = absint( $assoc_args['reset'] );

                                        if ( $job_id < 1 ) {
                                                WP_CLI::error( 'Valore --reset non valido: specificare l\'ID numerico del lavoro in dead letter.' );
                                        }

                                        if ( ! self::queue_ready( 'reset_dead' ) ) {
                                                WP_CLI::error( 'Coda non disponibile: il modulo Nexus_Queue non è caricato.' );
                                        }

                                        try {
                                                $reset = Nexus_Queue::reset_dead( $job_id );
                                        } catch ( Throwable $e ) {
                                                WP_CLI::error( sprintf( 'Reset lavoro #%d fallito: %s', $job_id, $e->getMessage() ) );
                                        }

                                        if ( $reset ) {
                                                WP_CLI::success( sprintf( 'Lavoro #%d rimesso in coda (stato: pending, attempts azzerato).', $job_id ) );
                                                return;
                                        }

                                        WP_CLI::error( sprintf( 'Impossibile ripristinare il lavoro #%d: non è in stato «dead».', $job_id ) );
                                        return;
                                }

                                if ( ! method_exists( 'Nexus_DB', 'jobs' ) ) {
                                        WP_CLI::error( 'Coda non disponibile: il modulo Nexus_DB non è caricato.' );
                                }

                                $dead_limit = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 50;
                                $dead_limit = max( 1, min( 500, $dead_limit ) );

                                try {
                                        $dead_items = Nexus_DB::jobs( array( 'status' => 'dead', 'limit' => $dead_limit ) );
                                } catch ( Throwable $e ) {
                                        WP_CLI::error( 'Lettura dead letter fallita: ' . $e->getMessage() );
                                }

                                if ( empty( $dead_items ) ) {
                                        WP_CLI::success( 'Nessun lavoro in dead letter: coda pulita.' );
                                        return;
                                }

                                $dead_rows = array();
                                foreach ( (array) $dead_items as $dead_job ) {
                                        $dead_rows[] = array(
                                                'ID'         => (int) $dead_job->id,
                                                'Hook'       => (string) $dead_job->hook,
                                                'Tentativi'  => (int) $dead_job->attempts,
                                                'Ultimo errore' => self::clip( (string) $dead_job->last_error, 60 ),
                                                'Creato'     => (string) $dead_job->created_at,
                                        );
                                }

                                WP_CLI::log( sprintf( 'Dead letter (%d mostrati): ripristino con «wp nexus queue dead --reset=<id>».', count( $dead_rows ) ) );
                                \WP_CLI\Utils\format_items( 'table', $dead_rows, array( 'ID', 'Hook', 'Tentativi', 'Ultimo errore', 'Creato' ) );
                                break;

                        default:
                                WP_CLI::error( sprintf( 'Azione coda non riconosciuta: «%s». Azioni ammesse: stats, process, work, retry, cancel, cleanup, dead.', $action ) );
                }
        }

        /* ========================= WP-CRON ========================= */

        /**
         * Stato degli eventi WP-Cron schedulati da Nexus (v1.1.9).
         *
         * Sottocomandi:
         *   status — tabella degli eventi 'nexus_*' (hook, prossima esecuzione,
         *            ricorrenza) letti dall'array cron del sito corrente.
         *
         * Usa _get_cron_array() invece di wp_get_scheduled_event() PERCHÉ: mostra
         * TUTTI gli hook nexus_* in una passata (tick, runner, autotranslate per
         * articolo e futuri) senza conoscere a priori gli argomenti di ogni evento.
         *
         * @subcommand cron
         * @synopsis <azione>
         *
         * @param array $args       Argomenti posizionali.
         * @param array $assoc_args Argomenti associativi (--chiave=valore).
         */
        public static function cron( $args, $assoc_args ) {
                $action = isset( $args[0] ) ? sanitize_key( (string) $args[0] ) : 'status';

                if ( '' === $action ) {
                        $action = 'status';
                }

                if ( 'status' !== $action ) {
                        WP_CLI::error( sprintf( 'Azione cron non riconosciuta: «%s». Azioni ammesse: status.', $action ) );
                }

                $cron = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
                $now  = time();

                // hook => array( 'next' => timestamp, 'recurrence' => testo ).
                $found = array();

                foreach ( (array) $cron as $timestamp => $events ) {
                        if ( ! is_array( $events ) ) {
                                continue;
                        }

                        foreach ( $events as $hook => $hook_events ) {
                                $hook = (string) $hook;

                                // Solo eventi del plugin: prefisso condiviso da tick,
                                // runner coda e autotranslate (nexus_*).
                                if ( 0 !== strpos( $hook, 'nexus_' ) ) {
                                        continue;
                                }

                                foreach ( (array) $hook_events as $data ) {
                                        $schedule   = is_array( $data ) && isset( $data['schedule'] ) ? (string) $data['schedule'] : '';
                                        $interval   = is_array( $data ) && isset( $data['interval'] ) ? (int) $data['interval'] : 0;
                                        $ts         = (int) $timestamp;
                                        $recurrence = '' !== $schedule
                                                ? sprintf( '%s (ogni %d s)', $schedule, $interval )
                                                : 'una tantum';

                                        // Stesso hook con più istanze (autotranslate è per
                                        // articolo): mostra la prossima esecuzione più vicina.
                                        if ( ! isset( $found[ $hook ] ) || $ts < $found[ $hook ]['next'] ) {
                                                $found[ $hook ] = array( 'next' => $ts, 'recurrence' => $recurrence );
                                        }
                                }
                        }
                }

                if ( empty( $found ) ) {
                        WP_CLI::log( 'Nessun evento WP-Cron di Nexus schedulato su questo sito.' );
                        return;
                }

                uasort( $found, array( __CLASS__, 'sort_cron_events' ) );

                $rows = array();
                foreach ( $found as $hook => $info ) {
                        $ts = (int) $info['next'];

                        $next = function_exists( 'wp_date' )
                                ? wp_date( 'Y-m-d H:i:s', $ts )
                                : date( 'Y-m-d H:i:s', $ts );

                        if ( $ts < $now ) {
                                // Evento in ritardo: WP-Cron lo esegue alla prossima
                                // visita/request (o al prossimo wp cron event run).
                                $next .= sprintf( ' (in ritardo di %s)', human_time_diff( $ts, $now ) );
                        } else {
                                $next .= sprintf( ' (tra %s)', human_time_diff( $now, $ts ) );
                        }

                        $rows[] = array(
                                'Hook'               => (string) $hook,
                                'Prossima esecuzione' => $next,
                                'Ricorrenza'         => (string) $info['recurrence'],
                        );
                }

                \WP_CLI\Utils\format_items( 'table', $rows, array( 'Hook', 'Prossima esecuzione', 'Ricorrenza' ) );

                // Coerenza con le impostazioni: avvisa se un evento atteso manca
                // (es. enable_queue=1 ma runner non schedulato → la coda non gira).
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'get_settings' ) ) {
                        return;
                }

                try {
                        $settings = Nexus_DB::get_settings();
                } catch ( Throwable $e ) {
                        return;
                }

                if ( ! is_array( $settings ) ) {
                        return;
                }

                if ( ! empty( $settings['enable_queue'] ) && ! isset( $found['nexus_queue_runner'] ) ) {
                        WP_CLI::log( 'Attenzione: la coda è attiva ma «nexus_queue_runner» NON è schedulato — verificare le impostazioni della coda.' );
                }

                if ( ! empty( $settings['auto_collect'] ) && ! isset( $found['nexus_cron_tick'] ) ) {
                        WP_CLI::log( 'Attenzione: la raccolta automatica è attiva ma «nexus_cron_tick» NON è schedulato — verificare le impostazioni della raccolta.' );
                }
        }

        /**
         * Callback di ordinamento per gli eventi cron (prossima esecuzione prima).
         *
         * @param array $a Evento (chiave 'next').
         * @param array $b Evento (chiave 'next').
         * @return int
         */
        private static function sort_cron_events( $a, $b ) {
                $a_next = isset( $a['next'] ) ? (int) $a['next'] : 0;
                $b_next = isset( $b['next'] ) ? (int) $b['next'] : 0;

                if ( $a_next === $b_next ) {
                        return 0;
                }

                return ( $a_next < $b_next ) ? -1 : 1;
        }

        /* ========================= IMPOSTAZIONI ========================= */

        /**
         * Lettura o scrittura delle impostazioni del plugin.
         *
         * Sottocomandi:
         *   get [chiave]                — tutte le impostazioni o una singola.
         *   set <chiave> <valore>       — aggiorna una chiave (solo chiavi di default).
         *
         * @subcommand settings
         * @synopsis <azione> [<chiave>] [<valore>]
         *
         * @param array $args       Argomenti posizionali.
         * @param array $assoc_args Argomenti associativi (--chiave=valore).
         */
        public static function settings( $args, $assoc_args ) {
                self::check_core();

                $action = isset( $args[0] ) ? sanitize_key( (string) $args[0] ) : '';

                if ( 'get' === $action ) {
                        self::settings_get( $args );
                        return;
                }

                if ( 'set' === $action ) {
                        self::settings_set( $args );
                        return;
                }

                WP_CLI::error( 'Uso: wp nexus settings get [chiave] oppure wp nexus settings set <chiave> <valore>.' );
        }

        /* ========================= MULTISITE ========================= */

        /**
         * Gestione multisite: stato o installazione tabelle sui siti della rete.
         *
         * Sottocomandi:
         *   status               — tabella siti della rete (tabelle "n/9", agenti, impostazioni).
         *   install [--sites=..] — installa tabelle+seed sui siti indicati o su tutti.
         *
         * @subcommand multisite
         * @synopsis <action> [--sites=<ids>]   action: status | install
         *
         * @param array $args       Argomenti posizionali.
         * @param array $assoc_args Argomenti associativi (--chiave=valore).
         */
        public static function multisite( $args, $assoc_args ) {
                if ( ! is_multisite() ) {
                        WP_CLI::error( 'Questo comando è disponibile solo su installazioni multisite.' );
                }

                $action = isset( $args[0] ) ? sanitize_key( (string) $args[0] ) : '';

                if ( '' === $action ) {
                        WP_CLI::error( 'Uso: wp nexus multisite status|install [--sites=<ids>].' );
                }

                if ( 'status' === $action ) {
                        if ( ! class_exists( 'Nexus_Multisite' ) || ! method_exists( 'Nexus_Multisite', 'status' ) ) {
                                WP_CLI::error( 'Modulo multisite non disponibile: la classe Nexus_Multisite non è caricata.' );
                        }

                        $rows = array();
                        foreach ( (array) Nexus_Multisite::status() as $row ) {
                                $rows[] = array(
                                        'Blog ID'      => (int) ( isset( $row['blog_id'] ) ? $row['blog_id'] : 0 ),
                                        'URL'          => isset( $row['url'] ) ? (string) $row['url'] : '',
                                        'Tabelle'      => isset( $row['tables'] ) ? (string) $row['tables'] : '0/9',
                                        'Agenti'       => (int) ( isset( $row['agents'] ) ? $row['agents'] : 0 ),
                                        'Impostazioni' => ! empty( $row['settings'] ) ? 'Sì' : 'No',
                                );
                        }

                        \WP_CLI\Utils\format_items( 'table', $rows, array( 'Blog ID', 'URL', 'Tabelle', 'Agenti', 'Impostazioni' ) );
                        return;
                }

                if ( 'install' === $action ) {
                        if ( ! class_exists( 'Nexus_Multisite' ) || ! method_exists( 'Nexus_Multisite', 'install_all' ) ) {
                                WP_CLI::error( 'Modulo multisite non disponibile: la classe Nexus_Multisite non è caricata.' );
                        }

                        $ids = array();
                        if ( isset( $assoc_args['sites'] ) && '' !== trim( (string) $assoc_args['sites'] ) ) {
                                foreach ( explode( ',', (string) $assoc_args['sites'] ) as $piece ) {
                                        $id = absint( trim( $piece ) );

                                        if ( $id > 0 ) {
                                                $ids[ $id ] = $id;
                                        }
                                }

                                $ids = array_values( $ids );

                                if ( empty( $ids ) ) {
                                        WP_CLI::error( 'Valore --sites non valido: specificare ID di sito separati da virgola (es. --sites=1,2).' );
                                }
                        }

                        try {
                                $result = Nexus_Multisite::install_all( $ids );
                        } catch ( Throwable $e ) {
                                WP_CLI::error( 'Installazione multisite fallita: ' . $e->getMessage() );
                        }

                        foreach ( (array) ( isset( $result['details'] ) ? $result['details'] : array() ) as $detail ) {
                                $url = isset( $detail['url'] ) && '' !== (string) $detail['url'] ? (string) $detail['url'] : sprintf( 'sito #%d', isset( $detail['blog_id'] ) ? (int) $detail['blog_id'] : 0 );

                                WP_CLI::log( sprintf( 'Sito %s: %s', $url, ! empty( $detail['ok'] ) ? 'OK' : 'errore' ) );
                        }

                        $installed = isset( $result['installed'] ) ? (int) $result['installed'] : 0;
                        $failed    = isset( $result['failed'] ) ? (int) $result['failed'] : 0;

                        if ( $failed > 0 ) {
                                WP_CLI::error( sprintf( 'Installazione completata con errori: %d siti OK, %d falliti.', $installed, $failed ) );
                        }

                        WP_CLI::success( sprintf( 'Installazione completata su %d siti.', $installed ) );
                        return;
                }

                WP_CLI::error( sprintf( 'Azione multisite non riconosciuta: «%s». Azioni ammesse: status, install.', $action ) );
        }

        /* ========================= MULTILINGUA ========================= */

        /**
         * Diagnostica/riparazione multilingua (v1.1.9).
         *
         * Sottocomandi:
         *   doctor [--fix] — report sorgenti orfani/gruppi rotti/duplicati;
         *                    con --fix applica le riparazioni.
         *
         * Dipende da Nexus_Multilang::doctor() implementata in parallelo da un
         * altro agente (WP5): con method_exists diamo un errore ONESTO se la
         * build non la contiene ancora, invece di un fatale.
         *
         * @subcommand multilang
         * @synopsis <azione> [--fix]
         *
         * @param array $args       Argomenti posizionali.
         * @param array $assoc_args Argomenti associativi (--chiave=valore).
         */
        public static function multilang( $args, $assoc_args ) {
                $action = isset( $args[0] ) ? sanitize_key( (string) $args[0] ) : '';

                if ( 'doctor' !== $action ) {
                        WP_CLI::error( 'Uso: wp nexus multilang doctor [--fix].' );
                }

                if ( ! class_exists( 'Nexus_Multilang' ) || ! method_exists( 'Nexus_Multilang', 'doctor' ) ) {
                        // Onestà: la classe c'è ma il metodo doctor arriva con la
                        // 1.1.9 completa — non è un fatale né un problema locale.
                        WP_CLI::error( 'Comando non disponibile in questa build: «multilang doctor» arriva con la 1.1.9 completa (metodo Nexus_Multilang::doctor non ancora presente).' );
                }

                $fix = ! empty( $assoc_args['fix'] );

                try {
                        $result = Nexus_Multilang::doctor( $fix );
                } catch ( Throwable $e ) {
                        WP_CLI::error( 'Diagnostica multilingua fallita: ' . $e->getMessage() );
                }

                $report = is_array( $result ) && isset( $result['report'] ) ? (array) $result['report'] : array();
                $fixed  = is_array( $result ) && isset( $result['fixed'] ) ? (int) $result['fixed'] : 0;

                if ( empty( $report ) ) {
                        WP_CLI::log( 'Nessuna segnalazione dalla diagnostica multilingua.' );
                }

                foreach ( $report as $line ) {
                        WP_CLI::log( ' - ' . (string) $line );
                }

                if ( $fix ) {
                        WP_CLI::success( sprintf( 'Diagnostica multilingua completata con --fix: %d interventi eseguiti.', $fixed ) );
                        return;
                }

                WP_CLI::success( sprintf( 'Diagnostica multilingua completata (%d interventi possibili; rieseguire con --fix per applicarli).', $fixed ) );
        }

        /* ========================= FONTI (v1.1.10, WP8) ========================= */

        /**
         * Comando «wp nexus sources»: ispezione e test delle fonti della
         * redazione (term meta _nexus_sources, shape [{type,url,query?,full?,profile?}],
         * tipi Nexus_Redazioni::TYPES: rss|site|youtube|telegram|reddit|search|mcp|web).
         *
         * Sottocomandi:
         *   list [--redazione=<term_id|slug>]
         *       Tabella fonti: redazione, tipo, url/valore, query, full e stato
         *       (cooldown/fallimenti). NOTA ONESTÀ (regola ZERO MOCK): Nexus_Sources
         *       NON memorizza un timestamp di "ultima esecuzione" (solo hash
         *       early-exit, contatore fallimenti e cooldown), quindi la colonna
         *       Stato mostra SOLO ciò che è realmente recoverible dai transient.
         *   test <tipo> <url|valore> [--query=<q>]
         *       Testa UNA fonte invocando l'adapter Nexus_Sources::fetch_{tipo}
         *       (private → ReflectionMethod::setAccessible, decisione WP8
         *       documentata: alternativa alla modifica di class-nexus-sources.php,
         *       fuori perimetro). Prima del test pulisce cooldown/fallimenti della
         *       fonte e ripristina (best-effort) il budget full-content, così il
         *       test prova davvero la fonte; un test fallito NON lascia la fonte
         *       in cooldown per il collect reale. Stampa i primi 5 item.
         *   cooldown [--clear]
         *       Elenco dei transient cooldown attivi (nexus_src_cool_*): le chiavi
         *       sono md5(type|url) NON decodificabili → mostra count, chiavi e
         *       scadenza (secondi residui, se l'opzione timeout esiste: con object
         *       cache esterno potrebbe non esserci). --clear → Nexus_Sources::clear_cooldown().
         *
         * @subcommand sources
         * @synopsis <azione> [<tipo>] [<url>] [--redazione=<term_id|slug>] [--query=<q>] [--clear]
         *
         * @param array $args       Argomenti posizionali.
         * @param array $assoc_args Argomenti associativi (--chiave=valore).
         */
        public static function sources( $args, $assoc_args ) {
                if ( ! class_exists( 'Nexus_Redazioni' ) || ! class_exists( 'Nexus_Sources' ) ) {
                        WP_CLI::error( 'I moduli fonti (Nexus_Redazioni/Nexus_Sources) non sono caricati: attivare il plugin e riprovare.' );
                }

                $action = isset( $args[0] ) ? sanitize_key( (string) $args[0] ) : '';

                if ( '' === $action ) {
                        WP_CLI::error( 'Uso: wp nexus sources list [--redazione=<term_id|slug>] | test <tipo> <url|valore> [--query=...] | cooldown [--clear].' );
                }

                switch ( $action ) {
                        case 'list':
                                self::sources_list( $assoc_args );
                                break;
                        case 'test':
                                self::sources_test( $args, $assoc_args );
                                break;
                        case 'cooldown':
                                self::sources_cooldown( $assoc_args );
                                break;
                        default:
                                WP_CLI::error( sprintf( 'Azione «%s» non riconosciuta: usare list, test oppure cooldown.', $action ) );
                }
        }

        /**
         * Sottocomando interno: sources list [--redazione=<term_id|slug>].
         *
         * @param array $assoc_args Argomenti associativi.
         * @return void
         */
        private static function sources_list( $assoc_args ) {
                if ( ! method_exists( 'Nexus_Redazioni', 'sources_for_term' ) ) {
                        WP_CLI::error( 'Nexus_Redazioni::sources_for_term non è disponibile: plugin da aggiornare.' );
                }

                $tax = Nexus_Redazioni::TAX;
                if ( ! function_exists( 'taxonomy_exists' ) || ! taxonomy_exists( $tax ) ) {
                        WP_CLI::error( 'Tassonomia «' . $tax . '» non registrata: aprire la pagina Redazioni in admin per inizializzarla (init) e riprovare.' );
                }

                $filter = isset( $assoc_args['redazione'] ) ? trim( (string) $assoc_args['redazione'] ) : '';
                $terms  = array();

                if ( '' !== $filter ) {
                        if ( ctype_digit( $filter ) ) {
                                $term = function_exists( 'get_term' ) ? get_term( absint( $filter ), $tax ) : null;
                        } else {
                                $term = function_exists( 'get_term_by' ) ? get_term_by( 'slug', sanitize_title( $filter ), $tax ) : null;
                        }
                        if ( ! $term || is_wp_error( $term ) ) {
                                WP_CLI::error( sprintf( 'Redazione «%s» non trovata (term_id o slug).', $filter ) );
                        }
                        $terms = array( $term );
                } else {
                        if ( method_exists( 'Nexus_Redazioni', 'all_terms' ) ) {
                                $terms = (array) Nexus_Redazioni::all_terms();
                        } elseif ( function_exists( 'get_terms' ) ) {
                                $terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );
                                $terms = is_wp_error( $terms ) ? array() : (array) $terms;
                        }
                }

                if ( empty( $terms ) ) {
                        WP_CLI::log( 'Nessuna redazione trovata: crearne una dalla pagina Redazioni in admin.' );
                        return;
                }

                $rows  = array();
                $total = 0;
                $red   = 0;

                foreach ( $terms as $term ) {
                        $term_id = isset( $term->term_id ) ? absint( $term->term_id ) : 0;
                        if ( $term_id < 1 ) {
                                continue;
                        }
                        $red++;
                        $sources = Nexus_Redazioni::sources_for_term( $term_id );
                        $label   = ( isset( $term->name ) ? $term->name : 'Redazione #' . $term_id ) . ' (#' . $term_id . ')';

                        if ( empty( $sources ) ) {
                                $rows[] = array(
                                        'Redazione'    => $label,
                                        'Tipo'         => '—',
                                        'URL / Valore' => '— (nessuna fonte)',
                                        'Query'        => '',
                                        'Full'         => '',
                                        'Stato'        => '—',
                                );
                                continue;
                        }

                        foreach ( $sources as $source ) {
                                if ( ! is_array( $source ) ) {
                                        continue;
                                }
                                $total++;
                                $rows[] = array(
                                        'Redazione'    => $label,
                                        'Tipo'         => (string) ( isset( $source['type'] ) ? $source['type'] : '' ),
                                        'URL / Valore' => self::clip( (string) ( isset( $source['url'] ) ? $source['url'] : '' ), 70 ),
                                        'Query'        => (string) ( isset( $source['query'] ) ? $source['query'] : '' ),
                                        'Full'         => ! empty( $source['full'] ) ? 'sì' : 'no',
                                        'Stato'        => self::sources_state_label( $source ),
                                );
                        }
                }

                if ( empty( $rows ) ) {
                        WP_CLI::log( 'Nessuna fonte configurata.' );
                        return;
                }

                \WP_CLI\Utils\format_items( 'table', $rows, array( 'Redazione', 'Tipo', 'URL / Valore', 'Query', 'Full', 'Stato' ) );
                WP_CLI::log( sprintf( 'Redazioni: %d · fonti totali: %d.', $red, $total ) );
        }

        /**
         * Stato recoverible di una fonte dai transient Nexus_Sources
         * (cooldown 1h dopo 3 fallimenti consecutivi, contatore fallimenti 1h).
         * Nessun timestamp di esecuzione esiste: qui NON se ne inventa uno.
         *
         * @param array $source Fonte {type,url}.
         * @return string Etichetta per la tabella.
         */
        private static function sources_state_label( $source ) {
                $type = isset( $source['type'] ) ? (string) $source['type'] : '';
                $url  = isset( $source['url'] ) ? (string) $source['url'] : '';
                if ( '' === $type || '' === $url || ! function_exists( 'get_transient' ) ) {
                        return '—';
                }

                $key = md5( $type . '|' . trim( $url ) );

                if ( get_transient( 'nexus_src_cool_' . $key ) ) {
                        // Scadenza leggibile solo se il transient vive nella tabella
                        // options (object cache esterno → solo "attivo").
                        $timeout = function_exists( 'get_option' ) ? get_option( '_transient_timeout_nexus_src_cool_' . $key ) : 0;
                        if ( $timeout ) {
                                $left = max( 0, (int) $timeout - time() );
                                return sprintf( 'Cooldown (ancora %ds)', $left );
                        }
                        return 'Cooldown attivo';
                }

                $fails = get_transient( 'nexus_src_fail_' . $key );
                if ( $fails ) {
                        return sprintf( 'Fallimenti recenti: %d', (int) $fails );
                }

                return '—';
        }

        /**
         * Sottocomando interno: sources test <tipo> <url|valore> [--query=...].
         *
         * @param array $args       Argomenti posizionali (args[1] = tipo, args[2] = url/valore).
         * @param array $assoc_args Argomenti associativi.
         * @return void
         */
        private static function sources_test( $args, $assoc_args ) {
                $types = array( 'rss', 'site', 'youtube', 'telegram', 'reddit', 'search', 'mcp', 'web' );
                if ( ! empty( Nexus_Redazioni::TYPES ) && is_array( Nexus_Redazioni::TYPES ) ) {
                        $types = array_map( 'strval', (array) Nexus_Redazioni::TYPES );
                }

                $type  = isset( $args[1] ) ? sanitize_key( (string) $args[1] ) : '';
                $value = isset( $args[2] ) ? trim( (string) $args[2] ) : '';

                if ( '' === $type || '' === $value ) {
                        WP_CLI::error( 'Uso: wp nexus sources test <tipo> <url|valore> [--query=...] — tipi: ' . implode( ', ', $types ) . '.' );
                }
                if ( ! in_array( $type, $types, true ) ) {
                        WP_CLI::error( sprintf( 'Tipo «%s» non valido. Tipi ammessi: %s.', $type, implode( ', ', $types ) ) );
                }

                // Normalizzazione identica a Nexus_Redazioni::sanitize_sources:
                // URL-based (rss/site/web) → esc_url_raw; handle non-URL → solo
                // pulizia dei caratteri di controllo.
                $value = trim( preg_replace( '/[\x00-\x1F\x7F]/', '', $value ) );
                if ( in_array( $type, array( 'rss', 'site', 'web' ), true ) ) {
                        $value = esc_url_raw( $value );
                }
                if ( '' === $value ) {
                        WP_CLI::error( 'Valore della fonte mancante o non valido.' );
                }

                $source = array(
                        'type' => $type,
                        'url'  => $value,
                );
                $query = isset( $assoc_args['query'] ) ? trim( (string) $assoc_args['query'] ) : '';
                if ( '' !== $query ) {
                        $source['query'] = sanitize_text_field( $query );
                }

                $adapter = 'fetch_' . $type;
                if ( ! method_exists( 'Nexus_Sources', $adapter ) ) {
                        WP_CLI::error( sprintf( 'Nessun adapter disponibile per il tipo «%s» (metodo Nexus_Sources::%s assente).', $type, $adapter ) );
                }

                // Chiave stato (formula Nexus_Sources::state_key): un test deve
                // sempre provare davvero la fonte, mai restituire il vuoto del cooldown.
                $key = md5( $type . '|' . trim( $value ) );
                delete_transient( 'nexus_src_cool_' . $key );
                delete_transient( 'nexus_src_fail_' . $key );

                // Budget full-content del run di test (best-effort, vedi docblock comando).
                try {
                        $budget_value = (int) constant( 'Nexus_Sources::FULL_CONTENT_BUDGET' );
                        $budget       = new ReflectionProperty( 'Nexus_Sources', 'full_budget_left' );
                        $budget->setAccessible( true );
                        $budget->setValue( null, $budget_value );
                } catch ( Throwable $e ) {
                        // Budget non impostabile: il test prosegue senza full-content.
                }

                WP_CLI::log( sprintf( 'Test fonte %s «%s»%s…', $type, self::clip( $value, 80 ), '' !== $query ? ' (query: ' . self::clip( $query, 40 ) . ')' : '' ) );

                try {
                        $method = new ReflectionMethod( 'Nexus_Sources', $adapter );
                        $method->setAccessible( true ); // PHP 7.4: richiesto per i metodi private.
                        $items = $method->invoke( null, $source );
                } catch ( Throwable $e ) {
                        // Un test fallito NON deve lasciare la fonte in cooldown.
                        delete_transient( 'nexus_src_cool_' . $key );
                        delete_transient( 'nexus_src_fail_' . $key );
                        WP_CLI::error( 'Test fallito: ' . $e->getMessage() );
                }

                if ( ! is_array( $items ) ) {
                        $items = array();
                }

                if ( empty( $items ) ) {
                        // Nessun item: ripulisci anche qui (l'adapter può aver marcato
                        // un fallimento) e dai un messaggio onesto con le cause tipiche.
                        delete_transient( 'nexus_src_cool_' . $key );
                        delete_transient( 'nexus_src_fail_' . $key );
                        WP_CLI::warning( 'Nessun item ricevuto (0): verifica tipo/valore, chiavi API necessarie (youtube_api_key, tools_search_key, reddit, MCP) e la raggiungibilità della fonte. Vedi log attività.' );
                        return;
                }

                WP_CLI::log( sprintf( 'Item trovati: %d. Primi %d:', count( $items ), min( 5, count( $items ) ) ) );

                $rows = array();
                foreach ( array_slice( $items, 0, 5 ) as $item ) {
                        if ( ! is_array( $item ) ) {
                                continue;
                        }
                        $rows[] = array(
                                'Titolo' => self::clip( (string) ( isset( $item['title'] ) ? $item['title'] : '' ), 80 ),
                                'Link'   => (string) ( isset( $item['link'] ) ? $item['link'] : '' ),
                        );
                }

                if ( ! empty( $rows ) ) {
                        \WP_CLI\Utils\format_items( 'table', $rows, array( 'Titolo', 'Link' ) );
                }

                WP_CLI::success( sprintf( 'Test completato: fonte %s «%s» ha restituito %d item.', $type, self::clip( $value, 60 ), count( $items ) ) );
        }

        /**
         * Sottocomando interno: sources cooldown [--clear].
         *
         * @param array $assoc_args Argomenti associativi.
         * @return void
         */
        private static function sources_cooldown( $assoc_args ) {
                global $wpdb;

                if ( ! empty( $assoc_args['clear'] ) ) {
                        if ( ! method_exists( 'Nexus_Sources', 'clear_cooldown' ) ) {
                                WP_CLI::error( 'Nexus_Sources::clear_cooldown non è disponibile: plugin da aggiornare.' );
                        }
                        try {
                                $removed = (int) Nexus_Sources::clear_cooldown();
                        } catch ( Throwable $e ) {
                                WP_CLI::error( 'Azzeramento cooldown fallito: ' . $e->getMessage() );
                        }
                        WP_CLI::success( sprintf( 'Cooldown fonti azzerato: %d righe transient rimosse.', $removed ) );
                        return;
                }

                if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'esc_like' ) || ! function_exists( 'get_option' ) ) {
                        WP_CLI::error( 'Database non disponibile: impossibile elencare i cooldown.' );
                }

                $like  = $wpdb->esc_like( '_transient_nexus_src_cool_' ) . '%';
                $names = $wpdb->get_col(
                        $wpdb->prepare(
                                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC",
                                $like
                        )
                );

                if ( empty( $names ) || ! is_array( $names ) ) {
                        WP_CLI::success( 'Nessuna fonte in cooldown (transient nexus_src_cool_* assenti).' );
                        return;
                }

                WP_CLI::log( sprintf( 'Cooldown attivi: %d. Le chiavi sono md5(type|url): non decodificabili.', count( $names ) ) );

                $rows = array();
                foreach ( $names as $name ) {
                        $plain   = preg_replace( '/^_transient_/', '', (string) $name );
                        $timeout = (int) get_option( '_transient_timeout_' . $plain );
                        $rows[]  = array(
                                'Chiave'   => $plain,
                                'Scadenza' => $timeout > 0 ? sprintf( 'tra %ds', max( 0, $timeout - time() ) ) : 'sconosciuta (object cache?)',
                        );
                }

                \WP_CLI\Utils\format_items( 'table', $rows, array( 'Chiave', 'Scadenza' ) );
                WP_CLI::log( 'Suggerimento: azzerare con «wp nexus sources cooldown --clear».' );
        }

        /* ========================= HELPER PRIVATI ========================= */

        /**
         * Handler dei segnali per «queue work» (public: usato come callback
         * pcntl_signal). Uscita pulita con 0: supervisord/systemd non registrano
         * crash e riavviano il worker come da policy.
         *
         * @return void
         */
        public static function handle_work_signal() {
                WP_CLI::log( 'Segnale di interruzione ricevuto: arresto del worker della coda.' );
                exit( 0 );
        }

        /**
         * Sottocomando interno: wp nexus settings get [chiave].
         *
         * @param array $args Argomenti posizionali.
         * @return void
         */
        private static function settings_get( $args ) {
                $key = isset( $args[1] ) ? sanitize_key( (string) $args[1] ) : '';

                if ( '' === $key ) {
                        $rows = array();
                        foreach ( (array) Nexus_DB::get_settings() as $name => $value ) {
                                $rows[] = array(
                                        'Chiave' => (string) $name,
                                        'Valore' => is_array( $value ) ? (string) wp_json_encode( $value ) : (string) $value,
                                );
                        }

                        \WP_CLI\Utils\format_items( 'table', $rows, array( 'Chiave', 'Valore' ) );
                        return;
                }

                $settings = Nexus_DB::get_settings();

                if ( ! isset( $settings[ $key ] ) ) {
                        WP_CLI::error( sprintf( 'Impostazione «%s» non trovata: usare «wp nexus settings get» per l\'elenco completo.', $key ) );
                }

                $value = $settings[ $key ];
                WP_CLI::log( is_array( $value ) ? (string) wp_json_encode( $value ) : (string) $value );
        }

        /**
         * Sottocomando interno: wp nexus settings set <chiave> <valore>.
         * Ammette SOLE le chiavi presenti in Nexus_DB::default_settings().
         *
         * @param array $args Argomenti posizionali.
         * @return void
         */
        private static function settings_set( $args ) {
                if ( ! isset( $args[1] ) || ! isset( $args[2] ) ) {
                        WP_CLI::error( 'Uso: wp nexus settings set <chiave> <valore>.' );
                }

                $key = sanitize_key( (string) $args[1] );
                if ( '' === $key ) {
                        WP_CLI::error( 'Chiave impostazione non valida.' );
                }

                $defaults = Nexus_DB::default_settings();
                if ( ! array_key_exists( $key, $defaults ) ) {
                        WP_CLI::error( sprintf( 'Impostazione «%s» non riconosciuta: consultare «wp nexus settings get» per le chiavi disponibili.', $key ) );
                }

                $value = self::normalize_setting_value( $args[2] );

                try {
                        $updated = Nexus_DB::update_settings( array( $key => $value ) );
                } catch ( Throwable $e ) {
                        WP_CLI::error( sprintf( 'Salvataggio impostazione «%s» fallito: %s', $key, $e->getMessage() ) );
                }

                $new_value = is_array( $updated ) && isset( $updated[ $key ] ) ? $updated[ $key ] : $value;

                WP_CLI::success( sprintf( 'Impostazione «%s» aggiornata a «%s».', $key, is_array( $new_value ) ? (string) wp_json_encode( $new_value ) : (string) $new_value ) );
        }

        /**
         * Normalizza un valore di impostazione: true/false → 1/0, numerico → int/float,
         * altrimenti stringa pulita.
         *
         * @param mixed $raw Valore grezzo da CLI.
         * @return mixed
         */
        private static function normalize_setting_value( $raw ) {
                $value = trim( (string) $raw );
                $lower = strtolower( $value );

                if ( 'true' === $lower ) {
                        return 1;
                }

                if ( 'false' === $lower ) {
                        return 0;
                }

                if ( is_numeric( $value ) ) {
                        return preg_match( '/^-?\d+$/', $value ) ? (int) $value : (float) $value;
                }

                return sanitize_text_field( $value );
        }

        /**
         * Verifica che il core del plugin (Nexus_DB + Nexus_Pipeline) sia caricato.
         *
         * @return void
         */
        private static function check_core() {
                if ( ! class_exists( 'Nexus_DB' ) || ! class_exists( 'Nexus_Pipeline' ) ) {
                        WP_CLI::error( 'Il plugin Nexus News AI non è caricato completamente: attivarlo e riprovare.' );
                }
        }

        /**
         * Nota informativa quando la coda lavori è attiva: i comandi pipeline procedono
         * comunque in sincrono, ma si suggerisce il consumo esplicito della coda.
         *
         * @return void
         */
        private static function maybe_queue_notice() {
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'get_settings' ) ) {
                        return;
                }

                $settings = Nexus_DB::get_settings();

                if ( ! empty( $settings['enable_queue'] ) ) {
                        WP_CLI::log( 'Nota: la coda lavori è attiva. Il comando procede in sincrono; per consumare la coda usare «wp nexus queue process» o attendere il runner WP-Cron.' );
                }
        }

        /**
         * Verifica la disponibilità del modulo coda (Nexus_Queue) per un metodo.
         *
         * @param string $method Nome del metodo da usare (run_batch, retry, cancel).
         * @return bool
         */
        private static function queue_ready( $method ) {
                return class_exists( 'Nexus_Queue' ) && method_exists( 'Nexus_Queue', $method );
        }

        /**
         * Valida un ID agente numerico e ne verifica l'esistenza.
         *
         * @param int $id ID agente già passata ad absint.
         * @return object Riga agente.
         */
        private static function require_agent( $id ) {
                $agent = Nexus_DB::get_agent( absint( $id ) );

                if ( ! $agent ) {
                        WP_CLI::error( sprintf( 'Agente #%d non trovato.', absint( $id ) ) );
                }

                return $agent;
        }

        /**
         * Legge il PRIMO argomento posizionale come ID agente, lo valida e verifica l'esistenza.
         *
         * @param array  $args  Argomenti posizionali.
         * @param string $usage Messaggio d'uso da mostrare se l'ID manca o non è valido.
         * @return object Riga agente.
         */
        private static function require_agent_from_args( $args, $usage ) {
                $id = absint( isset( $args[0] ) ? $args[0] : 0 );

                if ( $id < 1 ) {
                        WP_CLI::error( $usage );
                }

                return self::require_agent( $id );
        }

        /**
         * Legge il PRIMO argomento posizionale come ID articolo, lo valida e verifica l'esistenza.
         *
         * @param array  $args  Argomenti posizionali.
         * @param string $usage Messaggio d'uso da mostrare se l'ID manca o non è valido.
         * @return object Riga articolo.
         */
        private static function require_article_from_args( $args, $usage ) {
                $id = absint( isset( $args[0] ) ? $args[0] : 0 );

                if ( $id < 1 ) {
                        WP_CLI::error( $usage );
                }

                $article = Nexus_DB::get_article( $id );

                if ( ! $article ) {
                        WP_CLI::error( sprintf( 'Articolo #%d non trovato.', $id ) );
                }

                return $article;
        }

        /**
         * Legge il SECONDO argomento posizionale come ID lavoro (dopo l'azione coda),
         * lo valida e verifica l'esistenza.
         *
         * @param array  $args  Argomenti posizionali (args[0] = azione, args[1] = id).
         * @param string $usage Messaggio d'uso da mostrare se l'ID manca o non è valido.
         * @return int ID lavoro valido.
         */
        private static function require_job_from_args( $args, $usage ) {
                $id = absint( isset( $args[1] ) ? $args[1] : 0 );

                if ( $id < 1 ) {
                        WP_CLI::error( $usage );
                }

                $job = method_exists( 'Nexus_DB', 'get_job' ) ? Nexus_DB::get_job( $id ) : null;

                if ( ! $job ) {
                        WP_CLI::error( sprintf( 'Lavoro #%d non trovato.', $id ) );
                }

                return $id;
        }

        /**
         * Estrae il messaggio d'errore da un ritorno pipeline (chiave 'error'),
         * con fallback generico.
         *
         * @param mixed $result Ritorno di Nexus_Pipeline/Nexus_Translate.
         * @return string
         */
        private static function result_error( $result ) {
                $error = is_array( $result ) && isset( $result['error'] ) ? trim( (string) $result['error'] ) : '';

                if ( '' === $error ) {
                        return 'errore sconosciuto.';
                }

                return $error;
        }

        /**
         * Tronca un testo alla lunghezza indicata aggiungendo i puntini di sospensione.
         *
         * @param string $text   Testo di partenza.
         * @param int    $length Lunghezza massima in caratteri.
         * @return string
         */
        private static function clip( $text, $length ) {
                $text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );
                $clip = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length ) : substr( $text, 0, $length );

                if ( $clip !== $text ) {
                        $clip .= '...';
                }

                return $clip;
        }

        /**
         * Stampa le righe di messaggio restituite da Nexus_Pipeline::run_full().
         *
         * @param mixed $messages Elenco messaggi.
         * @return void
         */
        private static function log_summary_messages( $messages ) {
                foreach ( (array) $messages as $message ) {
                        WP_CLI::log( ' - ' . (string) $message );
                }
        }

        /**
         * Prefisso uniforme per le righe di progresso dei comandi lunghi.
         *
         * @param string $message Messaggio da stampare.
         * @return void
         */
        private static function log_progress( $message ) {
                WP_CLI::log( (string) $message );
        }
}

WP_CLI::add_command( 'nexus', 'Nexus_CLI' );
