<?php
/**
 * Nexus Pipeline — orchestrazione della pipeline editoriale AI.
 *
 * Fasi (fedeli all'app originale Next.js, vedi nexus-src/src/lib/ai-engine.ts e api.ts):
 *   1. COLLECT   → RSS reali (o percorso evergreen se nessun feed) + 1 chiamata chat
 *                  con collect_prompt / evergreen_prompt → candidati (max 2, score >= 50)
 *                  salvati come articoli in stato 'draft'.
 *   2. EVALUATE  → 1 chiamata chat evaluate_prompt → punteggio intero 0-100 salvato.
 *   3. REWRITE   → 1 chiamata chat rewrite_prompt → titolo/sottotitolo/contenuto/riassunto
 *                  definitivi, stato 'pending_approval', read_time calcolato.
 *   4. APPROVE / REJECT → revisione editoriale (umana o automatica in fully_autonomous).
 *   5. PUBLISH   → Nexus_Publisher::publish() → log per piattaforma + stato 'published'.
 *   + NEXUS WIRE → redazione collettiva multi-agente su un tema (wire_run).
 *
 * Ogni fase logga su nexus_activity_logs (azioni: collecting|evaluating|rewriting|publishing|wire)
 * e aggiorna last_run dell'agente. Le azioni di approvazione/rifiuto loggano come
 * 'evaluating' (giudizio editoriale) e NON toccano last_run (non sono run automatici).
 *
 * Stati articolo: draft|pending_approval|approved|published|rejected.
 *
 * CONTRATTO PUBBLICO:
 * Nexus_Pipeline::collect( $agent_id )                       → array{ok, collected, errors, article_ids, skipped?}
 * Nexus_Pipeline::evaluate( $article_id )                    → array{ok, score, error?}
 * Nexus_Pipeline::rewrite( $article_id )                     → array{ok, error?}
 * Nexus_Pipeline::approve( $article_id, $note = '' )         → array{ok, error?}
 * Nexus_Pipeline::reject( $article_id, $note = '' )          → array{ok, error?}
 * Nexus_Pipeline::publish( $article_id )                     → array{ok, logs, error?, already?, message?}
 * Nexus_Pipeline::run_full( $agent_id )                      → array{ok, collected, evaluated, rewritten, published, messages}
 * Nexus_Pipeline::cron_tick()                                → array{ok, ran, results, timestamp}
 * Nexus_Pipeline::wire_run( $topic )                         → array{ok, run}
 * Nexus_Pipeline::wire_runs( $limit = 20 )                   → array
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Pipeline {

        /**
         * FASE 1 — Raccolta notizie per un agente.
         *
         * @param int $agent_id ID agente.
         * @return array{ok:bool, collected:int, errors:array, article_ids:array, skipped?:bool}
         */
        public static function collect( $agent_id ) {
                $out = array(
                        'ok'          => false,
                        'collected'   => 0,
                        'errors'      => array(),
                        'article_ids' => array(),
                );

                $agent = Nexus_DB::get_agent( $agent_id );
                if ( ! $agent ) {
                        $out['errors'][] = 'Agente non trovato.';
                        return $out;
                }

                // Agente non attivo → skip (non è un errore).
                if ( 'active' !== $agent->status ) {
                        $out['ok']      = true;
                        $out['skipped'] = true;
                        return $out;
                }

                $category    = (string) $agent->category;
                $personality = self::agent_personality( $agent );

                /* ---- FASE 1a: fonti RSS reali (o percorso evergreen) ---- */
                $items = array();
                if ( class_exists( 'Nexus_Rss' ) && method_exists( 'Nexus_Rss', 'fetch_for_agent' ) ) {
                        $fetched = Nexus_Rss::fetch_for_agent( $agent );
                        if ( is_array( $fetched ) ) {
                                $items = $fetched;
                        }
                }
                $evergreen = empty( $items );

                $candidates  = array();
                $source_name = '';

                if ( $evergreen ) {
                        // Nessun feed disponibile → 1 analisi editoriale evergreen (nessuna pretesa di notizia in tempo reale).
                        $prompt = Nexus_AI_Engine::evergreen_prompt( $category, $personality );
                        $res    = self::chat( $prompt, 'collect' );

                        if ( empty( $res['ok'] ) ) {
                                $error = self::chat_error( $res, 'motore AI non disponibile' );
                                self::log_collect( $agent, sprintf( 'Raccolta evergreen fallita: %s', $error ), 'error' );
                                $out['errors'][] = $error;
                                return $out;
                        }

                        $candidates  = self::normalize_candidates( Nexus_AI_Engine::extract_json( (string) $res['content'] ) );
                        $source_name = 'Nexus News AI - Analisi editoriale';
                } else {
                        // Raggruppa gli item per fonte e usa il PRIMO feed/source disponibile.
                        $groups = array();
                        foreach ( $items as $item ) {
                                $src = self::item_field( $item, 'source' );
                                if ( '' === $src ) {
                                        $src = 'RSS';
                                }
                                if ( ! isset( $groups[ $src ] ) ) {
                                        $groups[ $src ] = array();
                                }
                                $groups[ $src ][] = $item;
                        }
                        $source_name = (string) array_key_first( $groups );
                        $items_text  = self::build_items_text( $groups[ $source_name ] );

                        $prompt = Nexus_AI_Engine::collect_prompt( $category, $personality, $items_text, $source_name );
                        $res    = self::chat( $prompt, 'collect' );

                        if ( empty( $res['ok'] ) ) {
                                $error = self::chat_error( $res, 'motore AI non disponibile' );
                                self::log_collect( $agent, sprintf( 'Raccolta fallita da %s: %s', $source_name, $error ), 'error' );
                                $out['errors'][] = sprintf( 'Raccolta fallita da %s: %s', $source_name, $error );
                                return $out;
                        }

                        $candidates = self::normalize_candidates( Nexus_AI_Engine::extract_json( (string) $res['content'] ) );
                }

                /* ---- FASE 1b: filtra i candidati (max 2, score >= 50) ---- */
                $kept = array();
                foreach ( $candidates as $candidate ) {
                        $score = 70;
                        if ( isset( $candidate['score'] ) && is_numeric( $candidate['score'] ) ) {
                                $score = max( 0, min( 100, (int) round( (float) $candidate['score'] ) ) );
                        }
                        // Evergreen non ha punteggio dalla selezione: si tiene il default 70.
                        if ( ! $evergreen && $score < 50 ) {
                                continue;
                        }

                        $title   = trim( (string) ( isset( $candidate['title'] ) ? $candidate['title'] : '' ) );
                        $summary = trim( (string) ( isset( $candidate['summary'] ) ? $candidate['summary'] : '' ) );
                        if ( '' === $title ) {
                                continue;
                        }

                        $content    = (string) ( isset( $candidate['content'] ) ? $candidate['content'] : '' );
                        $source_url = (string) ( isset( $candidate['sourceUrl'] ) ? $candidate['sourceUrl'] : '' );

                        // Arricchisci con i fatti REALI dell'RSS (match per titolo o URL, come l'originale).
                        if ( '' === $content && ! $evergreen ) {
                                foreach ( $items as $item ) {
                                        if ( self::item_field( $item, 'title' ) === $title || self::item_field( $item, 'link' ) === $source_url ) {
                                                $content = self::item_field( $item, 'description' );
                                                break;
                                        }
                                }
                        }

                        $kept[] = array(
                                'title'      => $title,
                                'summary'    => $summary,
                                'content'    => $content,
                                'sourceName' => trim( (string) ( isset( $candidate['sourceName'] ) ? $candidate['sourceName'] : '' ) ),
                                'sourceUrl'  => $source_url,
                                'score'      => $score,
                        );

                        if ( count( $kept ) >= ( $evergreen ? 1 : 2 ) ) {
                                break;
                        }
                }

                /* ---- FASE 1c: inserimento articoli in stato 'draft' ---- */
                foreach ( $kept as $candidate ) {
                        $article_id = Nexus_DB::insert_article(
                                array(
                                        'title'         => $candidate['title'],
                                        'summary'       => $candidate['summary'],
                                        'content'       => $candidate['content'],
                                        'category'      => $category,
                                        'agent_id'      => (int) $agent->id,
                                        'source_name'   => '' !== $candidate['sourceName'] ? $candidate['sourceName'] : $source_name,
                                        'source_url'    => $candidate['sourceUrl'],
                                        'status'        => 'draft',
                                        'quality_score' => $candidate['score'],
                                )
                        );
                        if ( $article_id ) {
                                $out['article_ids'][] = $article_id;
                                $out['collected']++;
                        }
                }

                // last_run + attività di raccolta.
                Nexus_DB::update_agent( (int) $agent->id, array( 'last_run' => current_time( 'mysql' ) ) );
                if ( $out['collected'] > 0 ) {
                        $detail = $evergreen
                                ? sprintf( 'Raccolto %d candidato (analisi evergreen: RSS non disponibile).', $out['collected'] )
                                : sprintf( 'Raccolti %d nuovi candidati da %s.', $out['collected'], $source_name );
                        self::log_collect( $agent, $detail, 'success' );
                } else {
                        $detail = $evergreen
                                ? 'Nessun candidato valido prodotto dall\'analisi evergreen.'
                                : sprintf( 'Nessun candidato con punteggio sufficiente (>= 50) da %s.', $source_name );
                        self::log_collect( $agent, $detail, 'warning' );
                }

                $out['ok'] = true;
                return $out;
        }

        /**
         * FASE 2 — Valutazione editoriale AI di un articolo (punteggio 0-100).
         *
         * @param int $article_id ID articolo.
         * @return array{ok:bool, score:int, error:string}
         */
        public static function evaluate( $article_id ) {
                $out = array(
                        'ok'    => false,
                        'score' => 0,
                        'error' => '',
                );

                $article = Nexus_DB::get_article( $article_id );
                if ( ! $article ) {
                        $out['error'] = 'Articolo non trovato.';
                        return $out;
                }

                $category = '' !== (string) $article->category ? (string) $article->category : self::agent_category( $article->agent_id );

                $prompt = Nexus_AI_Engine::evaluate_prompt(
                        (string) $article->title,
                        (string) $article->summary,
                        (string) $article->content,
                        $category
                );
                $res = self::chat( $prompt, 'evaluate' );

                if ( empty( $res['ok'] ) ) {
                        $error        = self::chat_error( $res, 'motore AI non disponibile' );
                        $out['error'] = $error;
                        self::log_phase( (int) $article->agent_id, 'evaluating', sprintf( 'Valutazione articolo #%d fallita: %s', $article_id, $error ), 'error' );
                        return $out;
                }

                $score = self::parse_score( (string) $res['content'] );
                Nexus_DB::update_article( $article_id, array( 'quality_score' => $score ) );
                self::touch_agent( (int) $article->agent_id );
                self::log_phase(
                        (int) $article->agent_id,
                        'evaluating',
                        sprintf( 'Articolo #%d "%s" valutato: punteggio %d/100.', $article_id, self::clip( $article->title, 60 ), $score ),
                        'success'
                );

                $out['ok']    = true;
                $out['score'] = $score;
                return $out;
        }

        /**
         * FASE 3 — Riscrittura AI dell'articolo in versione editoriale completa.
         *
         * @param int $article_id ID articolo.
         * @return array{ok:bool, error:string}
         */
        public static function rewrite( $article_id ) {
                $out = array(
                        'ok'    => false,
                        'error' => '',
                );

                $article = Nexus_DB::get_article( $article_id );
                if ( ! $article ) {
                        $out['error'] = 'Articolo non trovato.';
                        return $out;
                }

                $agent = $article->agent_id ? Nexus_DB::get_agent( $article->agent_id ) : null;
                $category = '' !== (string) $article->category ? (string) $article->category : self::agent_category( $article->agent_id );

                $prompt = Nexus_AI_Engine::rewrite_prompt(
                        (string) $article->title,
                        (string) $article->summary,
                        (string) $article->content,
                        $category,
                        $agent ? (string) $agent->name : 'Nexus AI',
                        $agent ? self::agent_personality( $agent ) : 'professionale e oggettivo'
                );
                $res = self::chat( $prompt, 'rewrite' );

                if ( empty( $res['ok'] ) ) {
                        $error        = self::chat_error( $res, 'motore AI non disponibile' );
                        $out['error'] = $error;
                        self::log_phase( (int) $article->agent_id, 'rewriting', sprintf( 'Riscrittura articolo #%d fallita: %s', $article_id, $error ), 'error' );
                        return $out;
                }

                $data = Nexus_AI_Engine::extract_json( (string) $res['content'] );
                if ( ! is_array( $data ) || empty( $data['content'] ) ) {
                        $out['error'] = 'La risposta AI non contiene un JSON valido con il contenuto dell\'articolo.';
                        self::log_phase( (int) $article->agent_id, 'rewriting', sprintf( 'Riscrittura articolo #%d fallita: risposta AI non interpretabile.', $article_id ), 'error' );
                        return $out;
                }

                $content   = (string) $data['content'];
                $read_time = max( 1, (int) ceil( self::word_count( $content ) / 200 ) );

                Nexus_DB::update_article(
                        $article_id,
                        array(
                                'title'     => ! empty( $data['title'] ) ? (string) $data['title'] : (string) $article->title,
                                'subtitle'  => isset( $data['subtitle'] ) ? (string) $data['subtitle'] : '',
                                'content'   => $content,
                                'summary'   => ! empty( $data['summary'] ) ? (string) $data['summary'] : (string) $article->summary,
                                'status'    => 'pending_approval',
                                'read_time' => $read_time,
                        )
                );
                self::touch_agent( (int) $article->agent_id );
                self::log_phase(
                        (int) $article->agent_id,
                        'rewriting',
                        sprintf( 'Articolo #%d riscritto (%d parole, %d min di lettura) e messo in coda di approvazione.', $article_id, self::word_count( $content ), $read_time ),
                        'success'
                );

                $out['ok'] = true;
                return $out;
        }

        /**
         * Approva un articolo in coda di revisione.
         *
         * @param int    $article_id ID articolo.
         * @param string $note       Nota facoltativa del revisore.
         * @return array{ok:bool, error:string}
         */
        public static function approve( $article_id, $note = '' ) {
                return self::review( $article_id, 'approved', $note );
        }

        /**
         * Rifiuta un articolo in coda di revisione.
         *
         * @param int    $article_id ID articolo.
         * @param string $note       Nota facoltativa del revisore.
         * @return array{ok:bool, error:string}
         */
        public static function reject( $article_id, $note = '' ) {
                return self::review( $article_id, 'rejected', $note );
        }

        /**
         * FASE 5 — Pubblicazione multi-piattaforma di un articolo approvato.
         *
         * Consentito solo se lo stato è 'approved' (oppure 'pending_approval' in
         * modalità fully_autonomous, dove l'approvazione automatica avviene al volo).
         *
         * @param int $article_id ID articolo.
         * @return array{ok:bool, logs:array, error:string, already:bool, message:string}
         */
        public static function publish( $article_id ) {
                $out = array(
                        'ok'      => false,
                        'logs'    => array(),
                        'error'   => '',
                        'already' => false,
                        'message' => '',
                );

                $article = Nexus_DB::get_article( $article_id );
                if ( ! $article ) {
                        $out['error'] = 'Articolo non trovato.';
                        return $out;
                }

                $settings = Nexus_DB::get_settings();
                $mode     = isset( $settings['mode'] ) ? (string) $settings['mode'] : 'semi_autonomous';

                if ( 'published' === $article->status ) {
                        // Idempotenza: già pubblicato → restituisci i log esistenti senza duplicare.
                        foreach ( Nexus_DB::publish_logs( $article_id ) as $row ) {
                                $out['logs'][] = array(
                                        'platform' => (string) $row->platform,
                                        'status'   => (string) $row->status,
                                        'post_id'  => (string) $row->post_id,
                                        'post_url' => (string) $row->post_url,
                                        'error'    => (string) $row->error,
                                );
                        }
                        $out['ok']      = true;
                        $out['already'] = true;
                        $out['message'] = 'Articolo già pubblicato in precedenza: nessuna nuova pubblicazione.';
                        return $out;
                }

                if ( 'approved' === $article->status ) {
                        // Percorso normale: nulla da fare qui.
                } elseif ( 'pending_approval' === $article->status && 'fully_autonomous' === $mode ) {
                        Nexus_DB::set_approval( $article_id, 'approved', 'Approvazione automatica prima della pubblicazione (modalità fully autonomous).' );
                } else {
                        $out['error'] = sprintf( 'L\'articolo #%d non è pubblicabile nello stato attuale (%s): serve lo stato "approved".', $article_id, $article->status );
                        return $out;
                }

                $logs = Nexus_Publisher::publish( $article );
                if ( empty( $logs ) ) {
                        $out['error'] = 'Nessuna piattaforma di pubblicazione configurata o articolo non valido.';
                        self::log_phase( (int) $article->agent_id, 'publishing', sprintf( 'Pubblicazione articolo #%d fallita: nessuna piattaforma configurata.', $article_id ), 'error' );
                        return $out;
                }

                $published_platforms = array();
                foreach ( $logs as $log ) {
                        Nexus_DB::insert_publish_log(
                                $article_id,
                                isset( $log['platform'] ) ? $log['platform'] : '',
                                isset( $log['status'] ) ? $log['status'] : 'failed',
                                isset( $log['post_id'] ) ? $log['post_id'] : '',
                                isset( $log['post_url'] ) ? $log['post_url'] : '',
                                isset( $log['error'] ) ? $log['error'] : ''
                        );
                        if ( isset( $log['status'] ) && 'published' === $log['status'] ) {
                                $published_platforms[] = (string) $log['platform'];
                        }
                }
                $out['logs'] = $logs;

                if ( ! empty( $published_platforms ) ) {
                        Nexus_DB::update_article(
                                $article_id,
                                array(
                                        'status'       => 'published',
                                        'published_at' => current_time( 'mysql' ),
                                )
                        );
                        self::touch_agent( (int) $article->agent_id );
                        self::log_phase(
                                (int) $article->agent_id,
                                'publishing',
                                sprintf( 'Articolo #%d "%s" pubblicato su: %s.', $article_id, self::clip( $article->title, 60 ), implode( ', ', $published_platforms ) ),
                                'success'
                        );
                        $out['ok']      = true;
                        $out['message'] = sprintf( 'Pubblicato su: %s.', implode( ', ', $published_platforms ) );
                } else {
                        $out['error'] = 'Pubblicazione fallita su tutte le piattaforme.';
                        self::log_phase( (int) $article->agent_id, 'publishing', sprintf( 'Pubblicazione articolo #%d fallita su tutte le piattaforme.', $article_id ), 'error' );
                }

                return $out;
        }

        /**
         * Pipeline completa per un agente: rispetta i toggle delle impostazioni
         * (auto_collect / auto_evaluate / auto_rewrite / auto_publish) e la modalità.
         *
         * - fully_autonomous + punteggio >= auto_approve_score dopo la riscrittura
         *   → approvazione + pubblicazione automatiche.
         * - semi_autonomous → ci si ferma a 'pending_approval' (coda umana).
         * - max_articles_per_day → limite di articoli creati oggi (guardia pre-raccolta).
         *
         * @param int $agent_id ID agente.
         * @return array{ok:bool, collected:int, evaluated:int, rewritten:int, published:int, messages:array}
         */
        public static function run_full( $agent_id ) {
                $summary = array(
                        'ok'        => false,
                        'agent_id'  => absint( $agent_id ),
                        'collected' => 0,
                        'evaluated' => 0,
                        'rewritten' => 0,
                        'published' => 0,
                        'messages'  => array(),
                );

                $agent = Nexus_DB::get_agent( $agent_id );
                if ( ! $agent ) {
                        $summary['messages'][] = 'Agente non trovato.';
                        return $summary;
                }
                $summary['agent'] = (string) $agent->name;

                if ( 'active' !== $agent->status ) {
                        $summary['ok']         = true;
                        $summary['messages'][] = sprintf( 'Agente %s non attivo: pipeline saltata.', $agent->name );
                        return $summary;
                }

                $settings = Nexus_DB::get_settings();
                $mode     = isset( $settings['mode'] ) ? (string) $settings['mode'] : 'semi_autonomous';

                // Toggle fase raccolta.
                if ( empty( $settings['auto_collect'] ) ) {
                        $summary['ok']         = true;
                        $summary['messages'][] = 'Raccolta automatica disabilitata nelle impostazioni: pipeline non avviata.';
                        return $summary;
                }

                // Limite giornaliero di articoli creati.
                $max_per_day = max( 1, absint( isset( $settings['max_articles_per_day'] ) ? $settings['max_articles_per_day'] : 10 ) );
                $stats       = Nexus_DB::stats();
                $today       = isset( $stats['today'] ) ? (int) $stats['today'] : 0;
                if ( $today >= $max_per_day ) {
                        $summary['ok']         = true;
                        $summary['messages'][] = sprintf( 'Limite giornaliero raggiunto (%d/%d articoli oggi): pipeline non avviata.', $today, $max_per_day );
                        return $summary;
                }

                /* ---- FASE 1: raccolta ---- */
                $collect = self::collect( $agent_id );
                foreach ( (array) ( isset( $collect['errors'] ) ? $collect['errors'] : array() ) as $collect_error ) {
                        $summary['messages'][] = 'Raccolta: ' . $collect_error;
                }

                if ( ! empty( $collect['skipped'] ) ) {
                        $summary['ok']         = true;
                        $summary['messages'][] = 'Agente non attivo: pipeline saltata.';
                        return $summary;
                }

                if ( empty( $collect['ok'] ) ) {
                        $summary['messages'][] = 'Raccolta fallita: pipeline interrotta.';
                        return $summary;
                }

                $summary['collected'] = (int) ( isset( $collect['collected'] ) ? $collect['collected'] : 0 );
                if ( $summary['collected'] < 1 ) {
                        $summary['ok']         = true;
                        $summary['messages'][] = 'Nessun nuovo candidato raccolto: pipeline terminata.';
                        return $summary;
                }
                $summary['messages'][] = sprintf( 'Raccolti %d nuovi candidati.', $summary['collected'] );

                /* ---- FASI 2-5 per ogni bozza appena creata ---- */
                $article_ids = (array) ( isset( $collect['article_ids'] ) ? $collect['article_ids'] : array() );
                foreach ( $article_ids as $article_id ) {
                        $article = Nexus_DB::get_article( $article_id );
                        if ( ! $article ) {
                                continue;
                        }

                        // FASE 2: valutazione.
                        if ( ! empty( $settings['auto_evaluate'] ) ) {
                                $evaluation = self::evaluate( $article_id );
                                if ( ! empty( $evaluation['ok'] ) ) {
                                        $summary['evaluated']++;
                                        $summary['messages'][] = sprintf( 'Articolo #%d valutato: punteggio %d/100.', $article_id, (int) $evaluation['score'] );
                                        $article = Nexus_DB::get_article( $article_id );
                                } else {
                                        $summary['messages'][] = sprintf( 'Valutazione articolo #%d fallita: %s', $article_id, $evaluation['error'] );
                                }
                        }

                        // FASE 3: riscrittura.
                        $rewritten_now = false;
                        if ( ! empty( $settings['auto_rewrite'] ) ) {
                                $rewriting = self::rewrite( $article_id );
                                if ( ! empty( $rewriting['ok'] ) ) {
                                        $summary['rewritten']++;
                                        $rewritten_now = true;
                                        $summary['messages'][] = sprintf( 'Articolo #%d riscritto e messo in coda di approvazione.', $article_id );
                                        $article = Nexus_DB::get_article( $article_id );
                                } else {
                                        $summary['messages'][] = sprintf( 'Riscrittura articolo #%d fallita: %s', $article_id, $rewriting['error'] );
                                }
                        }

                        if ( ! $article ) {
                                continue;
                        }

                        // FASI 4-5: approvazione/pubblicazione automatiche secondo modalità e soglia.
                        $threshold    = absint( isset( $settings['auto_approve_score'] ) ? $settings['auto_approve_score'] : 75 );
                        $score        = (float) $article->quality_score;
                        $is_full_auto = ( 'fully_autonomous' === $mode );

                        if ( $rewritten_now && $is_full_auto && $score >= $threshold ) {
                                $approval = self::approve(
                                        $article_id,
                                        sprintf( 'Approvazione automatica: punteggio %.0f >= %d (modalità fully autonomous).', $score, $threshold )
                                );
                                if ( ! empty( $approval['ok'] ) ) {
                                        $summary['messages'][] = sprintf( 'Articolo #%d approvato automaticamente (punteggio %.0f >= %d).', $article_id, $score, $threshold );

                                        $publication = self::publish( $article_id );
                                        if ( ! empty( $publication['ok'] ) && empty( $publication['already'] ) ) {
                                                $summary['published']++;
                                                $summary['messages'][] = sprintf( 'Articolo #%d pubblicato automaticamente.', $article_id );
                                        } elseif ( ! empty( $publication['error'] ) ) {
                                                $summary['messages'][] = sprintf( 'Pubblicazione automatica articolo #%d fallita: %s', $article_id, $publication['error'] );
                                        }
                                } else {
                                        $summary['messages'][] = sprintf( 'Approvazione automatica articolo #%d fallita: %s', $article_id, $approval['error'] );
                                }
                        } elseif ( $rewritten_now && $is_full_auto ) {
                                $summary['messages'][] = sprintf( 'Articolo #%d in coda di revisione: punteggio %.0f sotto la soglia di auto-approvazione (%d).', $article_id, $score, $threshold );
                        } elseif ( $rewritten_now && ! empty( $settings['auto_publish'] ) && 'approved' === $article->status ) {
                                // semi_autonomous con auto_publish attivo: pubblica solo ciò che risulta già approvato.
                                $publication = self::publish( $article_id );
                                if ( ! empty( $publication['ok'] ) && empty( $publication['already'] ) ) {
                                        $summary['published']++;
                                        $summary['messages'][] = sprintf( 'Articolo #%d pubblicato (auto_publish attivo).', $article_id );
                                }
                        } elseif ( $rewritten_now ) {
                                $summary['messages'][] = sprintf( 'Articolo #%d in coda di approvazione (modalità semi_autonomous).', $article_id );
                        } elseif ( empty( $settings['auto_rewrite'] ) ) {
                                $summary['messages'][] = sprintf( 'Articolo #%d lasciato in stato bozza (auto_rewrite disabilitato).', $article_id );
                        }
                }

                $summary['ok'] = true;
                return $summary;
        }

        /**
         * Tick del cron: esegue run_full per ogni agente attivo, senza fatal error
         * (ogni agente è protetto da try/catch, come runAllAgents dell'originale).
         *
         * @return array{ok:bool, ran:int, results:array, timestamp:string}
         */
        public static function cron_tick() {
                $results = array();
                $ran     = 0;

                foreach ( (array) Nexus_DB::agents() as $agent ) {
                        if ( 'active' !== $agent->status ) {
                                continue;
                        }

                        try {
                                $result        = self::run_full( (int) $agent->id );
                                $result['agent'] = (string) $agent->name;
                                $results[]     = $result;
                                $ran++;
                        } catch ( Throwable $e ) {
                                Nexus_DB::insert_activity(
                                        (int) $agent->id,
                                        'collecting',
                                        sprintf( 'Errore pipeline automatica per %s: %s', $agent->name, self::clip( $e->getMessage(), 300 ) ),
                                        'error'
                                );
                                $results[] = array(
                                        'agent'     => (string) $agent->name,
                                        'agent_id'  => (int) $agent->id,
                                        'ok'        => false,
                                        'collected' => 0,
                                        'evaluated' => 0,
                                        'rewritten' => 0,
                                        'published' => 0,
                                        'messages'  => array( 'Errore pipeline: ' . self::clip( $e->getMessage(), 300 ) ),
                                );
                        }
                }

                return array(
                        'ok'        => true,
                        'ran'       => $ran,
                        'results'   => $results,
                        'timestamp' => current_time( 'mysql' ),
                );
        }

        /**
         * NEXUS WIRE — redazione collettiva multi-agente su un tema.
         *
         * 1. I primi 3 agenti attivi propongono ciascuno un angolo editoriale (~100 parole).
         * 2. Una chiamata finale "direttore" fonde i 3 angoli in un articolo completo.
         * 3. L'articolo viene salvato in stato 'pending_approval' (fonte: Nexus Wire).
         *
         * @param string $topic Tema della redazione collettiva.
         * @return array{ok:bool, run:array}
         */
        public static function wire_run( $topic ) {
                $topic = self::clip( sanitize_text_field( (string) $topic ), 120 );

                $run = array(
                        'id'         => uniqid( 'wire_' ),
                        'topic'      => $topic,
                        'status'     => 'failed',
                        'article_id' => 0,
                        'agents'     => array(),
                        'error'      => '',
                        'created_at' => current_time( 'mysql' ),
                );

                $team = self::active_agents( 3 );
                if ( '' === $topic || empty( $team ) ) {
                        $run['error'] = '' === $topic ? 'Tema mancante: specifica un argomento per il Nexus Wire.' : 'Nessun agente attivo in redazione.';
                        self::save_wire_run( $run );
                        return array(
                                'ok'  => false,
                                'run' => $run,
                        );
                }

                $first = $team[0];
                $run['agents'] = array_map(
                        static function ( $agent ) {
                                return (string) $agent->name;
                        },
                        $team
                );

                Nexus_DB::insert_activity( (int) $first->id, 'wire', sprintf( 'Nexus Wire avviato sul tema "%s" con %d agenti in redazione.', $topic, count( $team ) ), 'info' );

                /* ---- 1. Angoli editoriali dei colleghi ---- */
                $angles = array();
                foreach ( $team as $agent ) {
                        $prompt = sprintf(
                                "Sei %s, giornalista AI di Nexus News AI, curatore della sezione \"%s\".\nPersonalità editoriale: %s\n\nLa redazione ha attivato il NEXUS WIRE (redazione collettiva) sul tema: \"%s\"\n\nCOMPITO: proponi il TUO angolo editoriale sul tema, in circa 100 parole, coerente con la tua personalità e la tua sezione. Porta un punto di vista originale e complementare rispetto agli altri colleghi.\n\nRispondi SOLO con un JSON valido, nient'altro:\n{\"title\": \"titolo provvisorio del tuo angolo\", \"angle\": \"il tuo angolo editoriale in circa 100 parole\"}",
                                $agent->name,
                                $agent->category,
                                self::agent_personality( $agent ),
                                $topic
                        );
                        $res = self::chat( $prompt, 'wire' );

                        if ( empty( $res['ok'] ) ) {
                                $run['error'] = sprintf( 'Chat fallita per l\'agente %s: %s', $agent->name, self::chat_error( $res, 'motore AI non disponibile' ) );
                                Nexus_DB::insert_activity( (int) $first->id, 'wire', sprintf( 'Nexus Wire "%s" fallito: %s', $topic, self::clip( $run['error'], 300 ) ), 'error' );
                                self::save_wire_run( $run );
                                return array(
                                        'ok'  => false,
                                        'run' => $run,
                                );
                        }

                        $data  = Nexus_AI_Engine::extract_json( (string) $res['content'] );
                        $angle = is_array( $data ) && ! empty( $data['angle'] ) ? (string) $data['angle'] : '';
                        if ( '' === trim( $angle ) ) {
                                // Fallback: usa il testo grezzo della risposta.
                                $angle = wp_strip_all_tags( (string) $res['content'] );
                        }
                        $angles[] = array(
                                'name'     => (string) $agent->name,
                                'category' => (string) $agent->category,
                                'angle'    => self::clip( $angle, 1200 ),
                        );
                }

                /* ---- 2. Direttore: fonde gli angoli in un articolo unico ---- */
                $angles_text = '';
                foreach ( $angles as $i => $angle ) {
                        $angles_text .= sprintf( "ANGOLO %d — %s (sezione %s):\n%s\n\n", $i + 1, $angle['name'], $angle['category'], $angle['angle'] );
                }

                $director_prompt = sprintf(
                        "Sei il DIRETTORE RESPONSABILE della redazione di Nexus News AI.\nTema della redazione collettiva (Nexus Wire): \"%s\"\n\nI tuoi colleghi hanno proposto questi angoli editoriali:\n\n%s\nCOMPITO: fondi i 3 angoli in UN SOLO articolo completo, in ITALIANO, stile giornalistico professionale:\n- titolo d'impatto (max 90 caratteri)\n- sottotitolo di 1 frase\n- contenuto di ALMENO 500 parole, con paragrafi separati da doppio a capo\n- riassunto di 2-3 frasi\n- usa i punti di tutti gli angoli senza inventare fatti non presenti\n\nRispondi SOLO con un JSON valido, senza markdown né testo fuori dal JSON:\n{\"title\": \"...\", \"subtitle\": \"...\", \"content\": \"...\", \"summary\": \"...\"}",
                        $topic,
                        $angles_text
                );
                $res = self::chat( $director_prompt, 'wire' );

                if ( empty( $res['ok'] ) ) {
                        $run['error'] = sprintf( 'La direzione non ha completato la fusione: %s', self::chat_error( $res, 'motore AI non disponibile' ) );
                        Nexus_DB::insert_activity( (int) $first->id, 'wire', sprintf( 'Nexus Wire "%s" fallito: %s', $topic, self::clip( $run['error'], 300 ) ), 'error' );
                        self::save_wire_run( $run );
                        return array(
                                'ok'  => false,
                                'run' => $run,
                        );
                }

                $data = Nexus_AI_Engine::extract_json( (string) $res['content'] );
                if ( ! is_array( $data ) || empty( $data['title'] ) || empty( $data['content'] ) ) {
                        $run['error'] = 'La direzione non ha prodotto un articolo valido (JSON mancante o incompleto).';
                        Nexus_DB::insert_activity( (int) $first->id, 'wire', sprintf( 'Nexus Wire "%s" fallito: risposta non interpretabile.', $topic ), 'error' );
                        self::save_wire_run( $run );
                        return array(
                                'ok'  => false,
                                'run' => $run,
                        );
                }

                /* ---- 3. Salvataggio articolo in coda di approvazione ---- */
                $content   = (string) $data['content'];
                $read_time = max( 1, (int) ceil( self::word_count( $content ) / 200 ) );

                $article_id = Nexus_DB::insert_article(
                        array(
                                'title'       => (string) $data['title'],
                                'subtitle'    => isset( $data['subtitle'] ) ? (string) $data['subtitle'] : '',
                                'content'     => $content,
                                'summary'     => isset( $data['summary'] ) ? (string) $data['summary'] : '',
                                'category'    => (string) $first->category,
                                'agent_id'    => (int) $first->id,
                                'source_name' => 'Nexus Wire',
                                'status'      => 'pending_approval',
                                'read_time'   => $read_time,
                        )
                );

                if ( ! $article_id ) {
                        $run['error'] = 'Salvataggio dell\'articolo Nexus Wire fallito.';
                        Nexus_DB::insert_activity( (int) $first->id, 'wire', sprintf( 'Nexus Wire "%s" fallito: salvataggio articolo non riuscito.', $topic ), 'error' );
                        self::save_wire_run( $run );
                        return array(
                                'ok'  => false,
                                'run' => $run,
                        );
                }

                $run['status']     = 'completed';
                $run['article_id'] = $article_id;

                Nexus_DB::insert_activity( (int) $first->id, 'wire', sprintf( 'Nexus Wire completato sul tema "%s": articolo #%d in coda di approvazione (redazione: %s).', $topic, $article_id, implode( ', ', $run['agents'] ) ), 'success' );
                self::save_wire_run( $run );

                return array(
                        'ok'  => true,
                        'run' => $run,
                );
        }

        /**
         * Ultime run del Nexus Wire (option nexus_wire_runs, max 20 conservate).
         *
         * @param int $limit Numero massimo di run da restituire.
         * @return array
         */
        public static function wire_runs( $limit = 20 ) {
                $runs = get_option( 'nexus_wire_runs', array() );
                if ( ! is_array( $runs ) ) {
                        return array();
                }
                return array_values( array_slice( $runs, 0, max( 1, absint( $limit ) ) ) );
        }

        /* ================= HELPER PRIVATI ================= */

        /**
         * Percorso comune di approvazione/rifiuto.
         *
         * @param int    $article_id ID articolo.
         * @param string $action     'approved' | 'rejected'.
         * @param string $note       Nota del revisore.
         * @return array{ok:bool, error:string}
         */
        private static function review( $article_id, $action, $note ) {
                $out = array(
                        'ok'    => false,
                        'error' => '',
                );

                $article = Nexus_DB::get_article( $article_id );
                if ( ! $article ) {
                        $out['error'] = 'Articolo non trovato.';
                        return $out;
                }

                if ( 'approved' === $action ) {
                        if ( ! in_array( $article->status, array( 'pending_approval', 'approved' ), true ) ) {
                                $out['error'] = sprintf( 'Stato non valido per l\'approvazione: "%s" (richiesti "pending_approval" o "approved").', $article->status );
                                return $out;
                        }
                } else {
                        if ( 'published' === $article->status ) {
                                $out['error'] = 'Non è possibile rifiutare un articolo già pubblicato.';
                                return $out;
                        }
                }

                Nexus_DB::update_article( $article_id, array( 'status' => $action ) );
                Nexus_DB::set_approval( $article_id, $action, (string) $note );

                $verb     = ( 'approved' === $action ) ? 'approvato' : 'rifiutato';
                $detail   = sprintf( 'Articolo #%d "%s" %s dal revisore.', $article_id, self::clip( $article->title, 60 ), $verb );
                if ( '' !== trim( (string) $note ) ) {
                        $detail .= sprintf( ' Nota: %s', self::clip( (string) $note, 200 ) );
                }
                self::log_phase( (int) $article->agent_id, 'evaluating', $detail, ( 'approved' === $action ) ? 'success' : 'warning' );

                $out['ok'] = true;
                return $out;
        }

        /**
         * Chiamata standard al motore AI con un prompt utente singolo.
         *
         * @param string $prompt Prompt completo.
         * @param string $phase  Fase logica (collect|evaluate|rewrite|wire).
         * @return array{ok:bool, content:string, error:string, simulated:bool}
         */
        private static function chat( $prompt, $phase ) {
                $res = Nexus_AI_Engine::chat(
                        array(
                                array(
                                        'role'    => 'user',
                                        'content' => (string) $prompt,
                                ),
                        ),
                        array( 'phase' => (string) $phase )
                );
                return is_array( $res ) ? $res : array();
        }

        /**
         * Messaggio d'errore leggibile da una risposta chat.
         *
         * @param array  $res      Risposta del motore AI.
         * @param string $fallback Default se la risposta non contiene errori espliciti.
         * @return string
         */
        private static function chat_error( $res, $fallback ) {
                $error = ( is_array( $res ) && ! empty( $res['error'] ) ) ? (string) $res['error'] : $fallback;
                return self::clip( $error, 300 );
        }

        /**
         * Estrae il punteggio 0-100 dalla risposta: extract_json + fallback regex /\d+/.
         *
         * @param string $text Risposta del modello.
         * @return int Punteggio normalizzato 0-100 (default 70 se non ricavabile).
         */
        private static function parse_score( $text ) {
                $text  = (string) $text;
                $score = null;

                $data = Nexus_AI_Engine::extract_json( $text );
                if ( is_array( $data ) ) {
                        foreach ( array( 'score', 'punteggio', 'voto', 'value' ) as $key ) {
                                if ( isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) ) {
                                        $score = (int) round( (float) $data[ $key ] );
                                        break;
                                }
                        }
                        if ( null === $score ) {
                                // JSON a lista: cerca il primo numero o il primo oggetto con "score".
                                foreach ( $data as $value ) {
                                        if ( is_numeric( $value ) ) {
                                                $score = (int) round( (float) $value );
                                                break;
                                        }
                                        if ( is_array( $value ) && isset( $value['score'] ) && is_numeric( $value['score'] ) ) {
                                                $score = (int) round( (float) $value['score'] );
                                                break;
                                        }
                                }
                        }
                }

                if ( null === $score && preg_match( '/\d+/', $text, $matches ) ) {
                        $score = (int) $matches[0];
                }

                if ( null === $score ) {
                        $score = 70; // default dell'originale quando il modello non fornisce un punteggio.
                }

                return max( 0, min( 100, $score ) );
        }

        /**
         * Normalizza la risposta collect/evergreen in una lista di candidati.
         *
         * @param array|null $data Dati estratti dal JSON.
         * @return array Lista di array candidato.
         */
        private static function normalize_candidates( $data ) {
                if ( ! is_array( $data ) ) {
                        return array();
                }

                if ( isset( $data['title'] ) ) {
                        return array( $data );
                }

                $candidates = array();
                foreach ( $data as $row ) {
                        if ( is_array( $row ) && ! empty( $row['title'] ) ) {
                                $candidates[] = $row;
                        }
                        if ( count( $candidates ) >= 5 ) {
                                break;
                        }
                }
                return $candidates;
        }

        /**
         * Costruisce l'elenco numerato Titolo/Estratto/Link (formato del collect_prompt).
         *
         * @param array $items Item RSS.
         * @param int   $max   Numero massimo di item nell'elenco.
         * @return string
         */
        private static function build_items_text( $items, $max = 10 ) {
                $lines = array();
                $i     = 0;

                foreach ( (array) $items as $item ) {
                        $title = self::clip( self::item_field( $item, 'title' ), 160 );
                        if ( '' === $title ) {
                                continue;
                        }
                        $i++;
                        $lines[] = sprintf(
                                "%d. Titolo: %s\n   Estratto: %s\n   Link: %s",
                                $i,
                                $title,
                                self::clip( self::item_field( $item, 'description' ), 220 ),
                                self::item_field( $item, 'link' )
                        );
                        if ( $i >= $max ) {
                                break;
                        }
                }

                return implode( "\n", $lines );
        }

        /**
         * Legge un campo da un item RSS (array o oggetto).
         *
         * @param mixed  $item Item RSS.
         * @param string $key  Chiave.
         * @return string
         */
        private static function item_field( $item, $key ) {
                if ( is_array( $item ) ) {
                        return isset( $item[ $key ] ) ? (string) $item[ $key ] : '';
                }
                if ( is_object( $item ) ) {
                        return isset( $item->$key ) ? (string) $item->$key : '';
                }
                return '';
        }

        /**
         * Conteggio parole robusto UTF-8 (accenti italiani inclusi).
         *
         * @param string $text Testo.
         * @return int
         */
        private static function word_count( $text ) {
                $text = trim( wp_strip_all_tags( (string) $text ) );
                if ( '' === $text ) {
                        return 0;
                }
                $words = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
                return is_array( $words ) ? count( $words ) : str_word_count( $text );
        }

        /**
         * Normalizza spazi e tronca una stringa.
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
         * Personalità dell'agente con fallback.
         *
         * @param object $agent Agente.
         * @return string
         */
        private static function agent_personality( $agent ) {
                $personality = isset( $agent->personality ) ? trim( (string) $agent->personality ) : '';
                return '' !== $personality ? $personality : 'professionale e oggettivo';
        }

        /**
         * Categoria dell'agente con fallback.
         *
         * @param int $agent_id ID agente.
         * @return string
         */
        private static function agent_category( $agent_id ) {
                $agent = $agent_id ? Nexus_DB::get_agent( $agent_id ) : null;
                return $agent && ! empty( $agent->category ) ? (string) $agent->category : 'news';
        }

        /**
         * Aggiorna last_run dell'agente.
         *
         * @param int $agent_id ID agente.
         * @return void
         */
        private static function touch_agent( $agent_id ) {
                if ( $agent_id ) {
                        Nexus_DB::update_agent( $agent_id, array( 'last_run' => current_time( 'mysql' ) ) );
                }
        }

        /**
         * Log attività di raccolta.
         *
         * @param object $agent  Agente.
         * @param string $detail Dettaglio.
         * @param string $status Stato (success|error|warning|info).
         * @return void
         */
        private static function log_collect( $agent, $detail, $status ) {
                self::log_phase( (int) $agent->id, 'collecting', $detail, $status );
        }

        /**
         * Log attività generico di fase.
         *
         * @param int    $agent_id ID agente (0 = sistema).
         * @param string $action   Azione (collecting|evaluating|rewriting|publishing|wire).
         * @param string $detail   Dettaglio.
         * @param string $status   Stato.
         * @return void
         */
        private static function log_phase( $agent_id, $action, $detail, $status ) {
                Nexus_DB::insert_activity( $agent_id, $action, self::clip( $detail, 500 ), $status );
        }

        /**
         * Primi N agenti attivi.
         *
         * @param int $count Numero massimo di agenti.
         * @return array
         */
        private static function active_agents( $count ) {
                $active = array();
                foreach ( (array) Nexus_DB::agents() as $agent ) {
                        if ( 'active' === $agent->status ) {
                                $active[] = $agent;
                        }
                        if ( count( $active ) >= max( 1, absint( $count ) ) ) {
                                break;
                        }
                }
                return $active;
        }

        /**
         * Salva una run del Nexus Wire nell'option nexus_wire_runs (ultime 20).
         *
         * @param array $run Run da salvare.
         * @return void
         */
        private static function save_wire_run( $run ) {
                $runs = get_option( 'nexus_wire_runs', array() );
                if ( ! is_array( $runs ) ) {
                        $runs = array();
                }
                array_unshift( $runs, $run );
                update_option( 'nexus_wire_runs', array_slice( $runs, 0, 20 ), false );
        }
}
