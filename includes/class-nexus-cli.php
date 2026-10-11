<?php
/**
 * Nexus CLI — comandi WP-CLI per la redazione AI (v1.0.4 "Scala & Pro").
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
 *   queue     stats|process|retry <id>|cancel <id>|cleanup [--days=7] — coda lavori
 *   settings  get [chiave] | set <chiave> <valore>    — impostazioni del plugin
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
         * Gestione della coda lavori (modulo Nexus_Queue, v1.0.4).
         *
         * Sottocomandi:
         *   stats              — tabella lavori per stato.
         *   process            — esegue un batch di lavori in coda.
         *   retry <id>         — rimette in coda un lavoro fallito/annullato/completato.
         *   cancel <id>        — annulla un lavoro in attesa.
         *   cleanup [--days=7] — elimina i lavori chiusi più vecchi di N giorni.
         *
         * @subcommand queue
         * @synopsis <azione> [<id>] [--days=<n>]
         *
         * @param array $args       Argomenti posizionali.
         * @param array $assoc_args Argomenti associativi (--chiave=valore).
         */
        public static function queue( $args, $assoc_args ) {
                self::check_core();

                $action = isset( $args[0] ) ? sanitize_key( (string) $args[0] ) : '';

                if ( '' === $action ) {
                        WP_CLI::error( 'Uso: wp nexus queue stats|process|retry <id>|cancel <id>|cleanup [--days=7].' );
                }

                switch ( $action ) {
                        case 'stats':
                                $labels = array(
                                        'pending'   => 'In attesa',
                                        'running'   => 'In esecuzione',
                                        'complete'  => 'Completati',
                                        'failed'    => 'Falliti',
                                        'cancelled' => 'Annullati',
                                        'total'     => 'Totale',
                                );

                                $rows = array();
                                foreach ( (array) Nexus_DB::job_stats() as $key => $value ) {
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

                                try {
                                        $batch = Nexus_Queue::run_batch();
                                } catch ( Throwable $e ) {
                                        WP_CLI::error( 'Elaborazione coda fallita: ' . $e->getMessage() );
                                }

                                $processed = isset( $batch['processed'] ) ? (int) $batch['processed'] : 0;
                                $failed    = isset( $batch['failed'] ) ? (int) $batch['failed'] : 0;

                                WP_CLI::success( sprintf( 'Batch elaborato: %d lavori processati, %d falliti.', $processed, $failed ) );
                                break;

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
                                        WP_CLI::success( sprintf( 'Lavoro #%d rimesso in coda (stato: pending).', $job_id ) );
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
                                        WP_CLI::success( sprintf( 'Lavoro #%d annullato (stato: cancelled).', $job_id ) );
                                }

                                WP_CLI::error( sprintf( 'Impossibile annullare il lavoro #%d (solo i lavori in attesa possono essere annullati).', $job_id ) );
                                break;

                        case 'cleanup':
                                $days = isset( $assoc_args['days'] ) ? absint( $assoc_args['days'] ) : 7;
                                $days = max( 1, $days );

                                $deleted = (int) Nexus_DB::cleanup_jobs( $days );

                                WP_CLI::success( sprintf( 'Eliminati %d lavori chiusi più vecchi di %d giorni.', $deleted, $days ) );
                                break;

                        default:
                                WP_CLI::error( sprintf( 'Azione coda non riconosciuta: «%s». Azioni ammesse: stats, process, retry, cancel, cleanup.', $action ) );
                }
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

        /* ========================= HELPER PRIVATI ========================= */

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
