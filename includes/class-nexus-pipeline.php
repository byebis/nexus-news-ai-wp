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
 * Ogni fase logga su nexus_activity_logs (azioni: collecting|evaluating|rewriting|publishing|wire
 * + v1.0.3: budget|duplicate|factcheck) e aggiorna last_run dell'agente. Le azioni di
 * approvazione/rifiuto loggano come 'evaluating' (giudizio editoriale) e NON toccano
 * last_run (non sono run automatici).
 *
 * Stati articolo: draft|pending_approval|approved|published|rejected.
 *
 * v1.1.4 — "Redazione completa" (capo redattore AI + fonti redazione, guarded):
 *   - COLLECT: NESSUN tocco per le fonti (v1.1.4 integratore): i feed delle fonti
 *     della redazione dell'agente sono gia' uniti DENTRO Nexus_Rss::fetch_for_agent()
 *     (T-08: fonti redazione prima dei globali, dedup per link, cap 10); collect()
 *     consuma gli item finali senza alcun merge aggiuntivo.
 *   - REWRITE: se l'articolo e' in giro di revisione (chief_status 'revisione'),
 *     la nota del capo redattore (chief_note) viene allegata al prompt del giornalista.
 *   - DOPO la rewrite: se l'articolo resta in pending_approval e il capo redattore
 *     e' attivo (Nexus_Chief::enabled), la revisione gli viene affidata via coda
 *     'nexus_chief_review' (o chiamata diretta se la coda non e' disponibile).
 *     Nessun doppio recapito: lo stato viene riletto prima dell'invio e il
 *     percorso fully_autonomous sopra soglia NON cambia (auto-approvazione esistente).
 *   - CRON TICK: dopo il giro degli agenti, il capo redattore rilegge fino a 3
 *     articoli in attesa (Nexus_Chief::run_for_pending( 3 )), giri di revisione inclusi.
 *
 * v1.1.5 — "Avvii mirati + live redazionale" (T-03):
 *   - STATO RUNTIME AGENTE: option 'nexus_agent_runtime' (array
 *     agent_id => {step, label, article_id, article_title, since}) alimentata
 *     nei punti chiave della pipeline con set_runtime(): collect
 *     ("Raccoglie le fonti"), evaluate ("Valuta i materiali"), rewrite
 *     ("Scrive l'articolo"), publish ("Pubblica"), chief ("Revisione del
 *     capo redattore", impostata sul giornalista proprietario dell'articolo
 *     in chief_dispatch). clear_runtime() a fine run SEMPRE (try/finally in
 *     run_full, anche su eccezione); l'option viene scritta con
 *     update_option solo quando lo stato cambia realmente (no write storm).
 *     La coda non ha bisogno di handler dedicati: i suoi core handler
 *     (pipeline_run_full/collect/evaluate/rewrite/publish) delegano a QUESTI
 *     metodi, quindi lo stato runtime si aggiorna da solo anche via coda.
 *     Le chiamate standalone (REST pipeline/collect e pipeline/publish)
 *     puliscono lo stato nell'handler REST; i job di coda standalone sono
 *     coperti dal filtro anti-stato-vecchio sull'endpoint /live (30 min).
 *
 * v1.0.3 — "Redazione Intelligente" (difensivo: ogni integrazione è guarded e mai fatale):
 *   - Modello AI per-agente in fase rewrite (agents.ai_model, fallback ai_model globale);
 *     modello economico ai_model_cheap (fallback ai_model) per collect, evaluate e fact-check.
 *   - Ogni chiamata passa a Nexus_AI_Engine::chat() gli opt 'model', 'agent_id' e 'action'
 *     per il tracking dell'uso AI su wp_nexus_usage_logs (ignorati dal motore v1.0.2).
 *   - Budget mensile AI: Nexus_AI_Engine::budget_exceeded() prima di ogni fase AI; con
 *     budget_action 'stop' la fase viene saltata (attività 'budget') e il tick prosegue;
 *     con 'warn' viene registrato un avviso e la fase prosegue.
 *   - Anti-duplicato: hash md5 del titolo normalizzato (articles.content_hash); gli item
 *     già presenti vengono scartati con attività 'duplicate'.
 *   - Fact-check post-rewrite (se enable_factcheck e fonte disponibile): punteggio e
 *     citazioni salvati sull'articolo; sotto factcheck_min_score l'articolo torna in bozza.
 *
 * v1.1.9 — Pipeline resilience (WP4):
 *   - Dedup ATOMICO: content_hash scritto da insert_article (schema v2, UNIQUE);
 *     rimosso l'update post-insert: insert==0 con hash impostato = duplicato scartato.
 *   - Resume articoli bloccati: resume_stalled() riprende le bozze ferme da oltre 6h
 *     (UN solo batch all'inizio di cron_tick) rilanciando evaluate/rewrite.
 *   - Guard capo redattore: in fully_autonomous non si auto-approva un articolo
 *     che aspetta (o sta rifacendo) la revisione del capo (publish() e run_full).
 *   - Fact-check su fonte reale: scarica source_url (cache 6h, clip 20k) invece di
 *     verificare contro il solo riassunto collect-stage; fallback onesto sul riassunto.
 *   - json_mode sulle fasi che leggono oggetti JSON + time budget per articolo
 *     (filtro nexus_pipeline_phase_budget, default 90s, interruzione con grazia).
 *
 * v1.1.10 — Full-content + output AI pulito (WP3+WP7):
 *   - COLLECT (FASE 1b): se l'item reale matchato porta il full-content
 *     estratto dalla pagina (campo additivo 'full_content' = 1 di
 *     Nexus_Sources, fonti redazione full=1), il candidato nasce col TESTO
 *     COMPLETO della fonte (clip 8000) invece del solo estratto RSS.
 *   - EVALUATE: con contenuto full (>1500 caratteri) attività 'full_content'
 *     (info) una sola volta per articolo (marcatore transient 24h).
 *   - REWRITE (WP7): prima del salvataggio l'output AI passa da
 *     Nexus_Utils::clean_ai_content — pulizia ESTETICA/STRUTTURALE (fence
 *     markdown, tag escapati, markdown→HTML minimo): NON è un sanitizer di
 *     sicurezza, la sanitizzazione resta a valle dove il contenuto viene
 *     salvato/pubblicato (wp_kses_post in insert/update/publisher). I flag
 *     Nexus_Utils::ai_content_quality_flags (calcolati sull'output RAW: per
 *     contratto riportano ciò che è stato OSSERVATO, anche se già riparato)
 *     diversi da ['ok'] loggano 'ai_output_quality' (info, una volta per
 *     articolo).
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
 * Nexus_Pipeline::resume_stalled( $max_age_hours = 6, $limit = 10 ) → array{ok, resumed, skipped, messages} (v1.1.9)
 * Nexus_Pipeline::wire_run( $topic )                         → array{ok, run}
 * Nexus_Pipeline::wire_runs( $limit = 20 )                   → array
 * Nexus_Pipeline::set_runtime( $agent_id, $step, $label, $article_id = 0, $article_title = '' ) → void (v1.1.5)
 * Nexus_Pipeline::clear_runtime( $agent_id )                 → void (v1.1.5)
 * Nexus_Pipeline::get_runtime()                              → array (v1.1.5)
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Pipeline {

        /**
         * Option dello stato runtime per agente (v1.1.5, pannello live).
         * Shape: array agent_id => {step, label, article_id, article_title, since}.
         */
        const RUNTIME_OPTION = 'nexus_agent_runtime';

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

                // v1.1.5: stato runtime per il pannello live "In redazione ora".
                self::set_runtime( (int) $agent->id, 'collect', 'Raccoglie le fonti' );

                $category    = (string) $agent->category;
                $personality = self::agent_personality( $agent );

                // Budget mensile (v1.0.3): con budget_action 'stop' la fase di raccolta viene
                // saltata (attività 'budget' già registrata) e il tick prosegue regolarmente.
                if ( ! self::budget_allows( 'raccolta', (int) $agent->id ) ) {
                        $out['errors'][] = 'Fase di raccolta saltata: budget mensile esaurito.';
                        return $out;
                }

                /* ---- FASE 1a: fonti multi-tipo (o percorso evergreen) ----
                 * v1.1.10 (B1): il dispatcher fonti e' Nexus_Sources::fetch_for_agent
                 * (fonti redazione di OGNI tipo: rss/site/youtube/telegram/reddit/
                 * search/mcp/web, fair-share, merge + dedup per link + cap 10,
                 * campo additivo source_type per item). Fallback storico
                 * Nexus_Rss::fetch_for_agent (solo RSS) se il modulo manca.
                 */
                $items = array();
                if ( class_exists( 'Nexus_Sources' ) && method_exists( 'Nexus_Sources', 'fetch_for_agent' ) ) {
                        $fetched = Nexus_Sources::fetch_for_agent( $agent );
                        if ( is_array( $fetched ) ) {
                                $items = $fetched;
                        }
                } elseif ( class_exists( 'Nexus_Rss' ) && method_exists( 'Nexus_Rss', 'fetch_for_agent' ) ) {
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
                        // v1.1.12 (S0): hook prompt generico (S2/S6).
                        $prompt = apply_filters( 'nexus_pipeline_prompt', $prompt, 'collect', null, $agent );
                        // v1.0.3: modello economico per lo screening + tracking usage (action/agent_id).
                        // v1.1.10 (G1): l'agente passa al motore (provider per-agente reale).
                        $res    = self::chat( $prompt, 'collect', array( 'model' => self::cheap_model(), 'agent_id' => (int) $agent->id, 'agent' => $agent ) );

                        if ( empty( $res['ok'] ) ) {
                                $error = self::chat_error( $res, 'motore AI non disponibile' );
                                self::log_collect( $agent, sprintf( 'Raccolta evergreen fallita: %s', $error ), 'error' );
                                $out['errors'][] = $error;
                                return $out;
                        }

                        $candidates  = self::normalize_candidates( Nexus_AI_Engine::extract_json( (string) $res['content'] ) );
                        $source_name = 'Nexus News AI - Analisi editoriale';
                } else {
                        // v1.1.10 (B1): TUTTI gli item del dispatcher fonti (merge,
                        // dedup e cap gia' fatti in Nexus_Sources), non piu' solo il
                        // primo gruppo di fonte: il prompt vede l'intero paniere.
                        $distinct = array();
                        foreach ( $items as $item ) {
                                $src = self::item_field( $item, 'source' );
                                if ( '' === $src ) {
                                        $src = 'RSS';
                                }
                                $distinct[ $src ] = true;
                        }
                        $total_sources = count( $distinct );
                        $source_name   = $total_sources > 1
                                ? sprintf( '%d fonti redazione', $total_sources )
                                : (string) array_key_first( $distinct );
                        $items_text = self::build_items_text( $items );

                        $prompt = Nexus_AI_Engine::collect_prompt( $category, $personality, $items_text, $source_name );
                        // v1.1.12 (S0): hook prompt generico (S2/S6).
                        $prompt = apply_filters( 'nexus_pipeline_prompt', $prompt, 'collect', null, $agent );
                        // v1.0.3: modello economico per lo screening + tracking usage (action/agent_id).
                        // v1.1.10 (G1): l'agente passa al motore (provider per-agente reale).
                        $res    = self::chat( $prompt, 'collect', array( 'model' => self::cheap_model(), 'agent_id' => (int) $agent->id, 'agent' => $agent ) );

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
                        // v1.1.10 (WP3): se l'item matchato ha il full-content estratto dalla
                        // pagina (Nexus_Sources, fonte full=1), il candidato usa il testo
                        // completo (clip 8000) al posto del solo estratto RSS.
                        if ( '' === $content && ! $evergreen ) {
                                foreach ( $items as $item ) {
                                        if ( self::item_field( $item, 'title' ) === $title || self::item_field( $item, 'link' ) === $source_url ) {
                                                $content = self::item_field( $item, 'description' );
                                                if ( self::item_has_full_content( $item ) ) {
                                                        $content = self::clip( $content, 8000 );
                                                }
                                                break;
                                        }
                                }
                        }

                        // v1.1.10 (B1): propaga il tipo fonte dell'item reale (rss/
                        // youtube/telegram/...) nel candidato, per insert_article.
                        $source_type = '';
                        if ( ! $evergreen ) {
                                foreach ( $items as $item ) {
                                        if ( self::item_field( $item, 'title' ) === $title || ( '' !== $source_url && self::item_field( $item, 'link' ) === $source_url ) ) {
                                                $source_type = self::item_field( $item, 'source_type' );
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
                                'sourceType' => $source_type,
                                'score'      => $score,
                        );

                        if ( count( $kept ) >= ( $evergreen ? 1 : 2 ) ) {
                                break;
                        }
                }

                /* ---- FASE 1c: inserimento articoli in stato 'draft' (anti-duplicato ATOMICO v1.1.9) ---- */
                foreach ( $kept as $candidate ) {
                        // Fast-path anti-duplicato (v1.0.3): hash del titolo normalizzato; se esiste
                        // già un articolo con lo stesso hash l'item viene scartato senza tentare
                        // l'insert (risparmia anche l'INSERT che fallirebbe sull'UNIQUE).
                        $hash = self::title_hash( $candidate['title'] );
                        if ( '' !== $hash && method_exists( 'Nexus_DB', 'article_hash_exists' ) && Nexus_DB::article_hash_exists( $hash ) ) {
                                self::log_phase( 0, 'duplicate', sprintf( 'Duplicato scartato: %s', self::clip( $candidate['title'], 60 ) ), 'info' );
                                continue;
                        }

                        // v1.1.12 (S0): gate pre-inserimento per pacchetti esterni — es.
                        // dedup semantico (S3): un filtro che ritorna true scarta il
                        // candidato PRIMA dell'INSERT (log attività 'duplicate').
                        if ( apply_filters( 'nexus_pipeline_skip_item', false, $candidate, $agent, $evergreen ) ) {
                                self::log_phase( (int) $agent->id, 'duplicate', sprintf( 'Candidato scartato da un filtro pipeline (contenuto simile): %s', self::clip( $candidate['title'], 60 ) ), 'info' );
                                continue;
                        }

                        // v1.1.9 (schema v2, contratto Task 4.3): l'hash viaggia DENTRO l'insert
                        // (content_hash con UNIQUE): il vecchio check → insert → update era una
                        // sequenza non atomica che poteva lasciare articoli mai deduplicati.
                        // Niente più update dopo l'insert; senza hash la chiave NON viene passata.
                        $data = array(
                                'title'         => $candidate['title'],
                                'summary'       => $candidate['summary'],
                                'content'       => $candidate['content'],
                                'category'      => $category,
                                'agent_id'      => (int) $agent->id,
                                'source_name'   => '' !== $candidate['sourceName'] ? $candidate['sourceName'] : $source_name,
                                'source_url'    => $candidate['sourceUrl'],
                                'status'        => 'draft',
                                'quality_score' => $candidate['score'],
                        );
                        // v1.1.10 (B1): tipo fonte reale dell'item, se noto.
                        if ( ! empty( $candidate['sourceType'] ) ) {
                                $data['source_type'] = (string) $candidate['sourceType'];
                        }
                        if ( '' !== $hash ) {
                                $data['content_hash'] = $hash;
                        }

                        $article_id = Nexus_DB::insert_article( $data );
                        if ( $article_id ) {
                                $out['article_ids'][] = $article_id;
                                $out['collected']++;
                        } elseif ( '' !== $hash ) {
                                // Insert fallito con hash impostato: con UNIQUE content_hash è un
                                // duplicato (es. inserito da un tick concorrente tra il fast-path e
                                // l'insert). Log onesto + skip: nessun dato residuo da ripulire.
                                self::log_phase( 0, 'duplicate', sprintf( 'Duplicato scartato (hash): %s', self::clip( $candidate['title'], 60 ) ), 'info' );
                        } else {
                                self::log_phase( 0, 'collecting', sprintf( 'Inserimento articolo fallito: %s', self::clip( $candidate['title'], 60 ) ), 'error' );
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

                // v1.1.5: stato runtime per il pannello live (fase di valutazione).
                self::set_runtime( (int) $article->agent_id, 'evaluate', 'Valuta i materiali', (int) $article_id, (string) $article->title );

                // Budget mensile (v1.0.3): con budget_action 'stop' la fase di valutazione viene saltata.
                if ( ! self::budget_allows( 'valutazione', (int) $article->agent_id ) ) {
                        $out['error'] = 'Fase di valutazione saltata: budget mensile esaurito.';
                        return $out;
                }

                $prompt = Nexus_AI_Engine::evaluate_prompt(
                        (string) $article->title,
                        (string) $article->summary,
                        (string) $article->content,
                        $category
                );

                // v1.1.12 (S0): hook prompt generico — il learning loop (S2) inietta qui
                // la guida performance; l'agente è caricato prima per i filtri.
                $eval_agent = Nexus_DB::get_agent( (int) $article->agent_id );
                $prompt     = apply_filters( 'nexus_pipeline_prompt', $prompt, 'evaluate', $article, $eval_agent );

                // v1.1.10 (WP3): il prompt di valutazione riceve già il content — nessun
                // cambio di firma. Se il content è full (>1500 caratteri: estrazione
                // della pagina riuscita, un estratto RSS resta tipicamente ~400) log
                // info una sola volta per articolo (marcatore transient 24h).
                $eval_content = (string) $article->content;
                $content_len  = function_exists( 'mb_strlen' ) ? mb_strlen( $eval_content ) : strlen( $eval_content );
                if ( $content_len > 1500 ) {
                        self::log_once_article(
                                'full_content',
                                (int) $article_id,
                                (int) $article->agent_id,
                                'full_content',
                                sprintf( 'Articolo #%d valutato col full-content della fonte (%d caratteri di testo nel prompt).', $article_id, $content_len ),
                                'info'
                        );
                }
                // v1.0.3: modello economico per lo screening (ai_model_cheap, fallback ai_model).
                // v1.1.9: json_mode (contratto Task 4.8): parse_score legge il punteggio JSON,
                // il fallback regex resta per le risposte numeriche.
                // v1.1.10 (G1): l'agente dell'articolo passa al motore (provider per-agente reale;
                // se il cheap model non esiste su quel provider, il fallback chain del motore
                // riprova coi globali — stessa semantica v1.1.9, mai su 401/403).
                $res = self::chat( $prompt, 'evaluate', array( 'model' => self::cheap_model(), 'agent_id' => (int) $article->agent_id, 'agent' => $eval_agent, 'json_mode' => true ) );

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
         * Richiamabile in standalone (legge l'articolo, lo rimette in
         * pending_approval): e' il giro di revisione riusato dal capo redattore
         * (v1.1.4, Nexus_Chief) quando il verdetto e' "revisiona". In quel caso,
         * se l'articolo e' in revisione (chief_status 'revisione') la nota del
         * capo (chief_note) viene allegata al prompt del giornalista.
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

                // v1.1.5: stato runtime per il pannello live (fase di scrittura).
                self::set_runtime( (int) $article->agent_id, 'rewrite', 'Scrive l\'articolo', (int) $article_id, (string) $article->title );

                // Budget mensile (v1.0.3): con budget_action 'stop' la fase di riscrittura viene saltata.
                if ( ! self::budget_allows( 'riscrittura', (int) $article->agent_id ) ) {
                        $out['error'] = 'Fase di riscrittura saltata: budget mensile esaurito.';
                        return $out;
                }

                // v1.1.4 — Giro di revisione del capo redattore: se l'articolo e' in
                // revisione (chief_status 'revisione'), la nota del capo guida la nuova
                // stesura del giornalista. Guard isset: le colonne chief_ della v1.1.4
                // possono non esistere su installazioni non ancora aggiornate.
                $chief_note = '';
                if ( isset( $article->chief_status ) && 'revisione' === (string) $article->chief_status
                        && isset( $article->chief_note ) && '' !== trim( (string) $article->chief_note ) ) {
                        $chief_note = self::clip( (string) $article->chief_note, 600 );
                }

                $prompt = Nexus_AI_Engine::rewrite_prompt(
                        (string) $article->title,
                        (string) $article->summary,
                        (string) $article->content,
                        $category,
                        $agent ? (string) $agent->name : 'Nexus AI',
                        $agent ? self::agent_personality( $agent ) : 'professionale e oggettivo'
                );
                if ( '' !== $chief_note ) {
                        $prompt .= sprintf(
                                "\n\nNOTA DEL CAPO REDATTORE (articolo mandato in revisione: tienila presente riscrivendo):\n%s",
                                $chief_note
                        );
                }
                // v1.1.12 (S0): hook prompt generico — evergreen/pillar (S6) e learning
                // loop (S2) arricchiscono qui il prompt di riscrittura.
                $prompt = apply_filters( 'nexus_pipeline_prompt', $prompt, 'rewrite', $article, $agent );
                // v1.0.3: modello per-agente (agents.ai_model se impostato, fallback ai_model globale).
                // v1.1.9: json_mode (contratto Task 4.8): il prompt chiede già un oggetto JSON.
                // v1.1.10 (G1): anche il provider per-agente viene usato davvero (prima
                // passava solo il modello: il giornalista con provider dedicato scriveva
                // col provider globale — gap A1-G1 del piano v1.1.10).
                $res = self::chat( $prompt, 'rewrite', array( 'model' => self::agent_model( $agent ), 'agent_id' => (int) $article->agent_id, 'agent' => $agent, 'json_mode' => true ) );

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

                $content = (string) $data['content'];

                /* ---- v1.1.10 (WP7): pulizia dell'output AI prima del salvataggio ----
                 * Nexus_Utils::clean_ai_content è pulizia ESTETICA/STRUTTURALE (fence
                 * markdown, tag escapati in entità, markdown→HTML minimo): NON è un
                 * sanitizer di sicurezza. La sanitizzazione resta a VALLE, dove il
                 * contenuto viene salvato/pubblicato (wp_kses_post in insert/update
                 * del DB e nel publisher). Mai fatale: su input non vuoto ritorna
                 * sempre una stringa; fallback difensivo sull'output raw.
                 * I flag di qualità si calcolano sull'output RAW del modello (prima
                 * della pulizia): riportano ciò che è stato OSSERVATO anche se la
                 * pulizia lo ha già riparato (contratto Nexus_Utils v1.1.10).
                 */
                if ( class_exists( 'Nexus_Utils' ) && method_exists( 'Nexus_Utils', 'ai_content_quality_flags' ) ) {
                        $raw_flags = Nexus_Utils::ai_content_quality_flags( $content );
                        if ( ! is_array( $raw_flags ) ) {
                                $raw_flags = array();
                        }
                        if ( array( 'ok' ) !== $raw_flags ) {
                                self::log_once_article(
                                        'ai_output_quality',
                                        (int) $article_id,
                                        (int) $article->agent_id,
                                        'ai_output_quality',
                                        sprintf(
                                                'Output AI articolo #%d: flag %s (contenuto ripulito con Nexus_Utils::clean_ai_content prima del salvataggio).',
                                                $article_id,
                                                implode( ', ', $raw_flags )
                                        ),
                                        'info'
                                );
                        }
                }
                if ( class_exists( 'Nexus_Utils' ) && method_exists( 'Nexus_Utils', 'clean_ai_content' ) ) {
                        $cleaned = Nexus_Utils::clean_ai_content( $content );
                        if ( is_string( $cleaned ) && '' !== trim( $cleaned ) ) {
                                $content = $cleaned;
                        }
                }

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

                /* ---- Fact-check (v1.0.3): verifica del draft contro la fonte originale ----
                 * Se abilitato e la fonte è disponibile aggiorna factcheck_score/citations e,
                 * sotto la soglia minima, riporta l'articolo in bozza. Mai bloccante.
                 */
                self::factcheck_article( $article_id, $article, (int) $article->agent_id );

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
                        // v1.1.9 — Guard capo redattore: in autonomia totale NON si auto-approva
                        // un articolo che aspetta la revisione del capo (chief_status vuoto = mai
                        // visto; 'revisione' = giro di revisione in corso): l'auto-approvazione al
                        // volo bypassava il controllo editoriale del capo. Con capo disattivo o
                        // esito già presente ('visto'/'modificato') comportamento invariato.
                        if ( self::awaiting_chief_review( $article ) ) {
                                $out['error']   = sprintf( 'Articolo #%d in attesa del capo redattore: auto-approvazione non eseguita, resta in coda di revisione.', $article_id );
                                $out['message'] = 'In attesa del capo redattore.';
                                self::log_phase( (int) $article->agent_id, 'evaluating', sprintf( 'Articolo #%d in attesa del capo redattore: auto-approvazione saltata, resta in coda di revisione.', $article_id ), 'info' );
                                return $out;
                        }

                        Nexus_DB::set_approval( $article_id, 'approved', 'Approvazione automatica prima della pubblicazione (modalità fully autonomous).' );
                } else {
                        $out['error'] = sprintf( 'L\'articolo #%d non è pubblicabile nello stato attuale (%s): serve lo stato "approved".', $article_id, $article->status );
                        return $out;
                }

                // v1.1.5: stato runtime per il pannello live (fase di pubblicazione).
                self::set_runtime( (int) $article->agent_id, 'publish', 'Pubblica', (int) $article_id, (string) $article->title );

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
                        // v1.1.12 (S0): post-publish hook — internal linking (S4),
                        // fingerprint dedup (S3), notifiche (S7) si agganciano qui.
                        do_action( 'nexus_pipeline_after_publish', $article_id, $article, $logs );
                        $out['ok']      = true;
                        $out['message'] = sprintf( 'Pubblicato su: %s.', implode( ', ', $published_platforms ) );
                } else {
                        $out['error'] = 'Pubblicazione fallita su tutte le piattaforme.';
                        self::log_phase( (int) $article->agent_id, 'publishing', sprintf( 'Pubblicazione articolo #%d fallita su tutte le piattaforme.', $article_id ), 'error' );
                }

                return $out;
        }

        /**
         * Pipeline completa per un agente (facciata v1.1.5).
         *
         * Delega il corpo a run_full_agent e garantisce la pulizia dello stato
         * runtime con clear_runtime() in un blocco finally: a fine run (successo,
         * ritorno anticipato o eccezione propagata al chiamante) l'agente non
         * resta "in redazione" sul pannello live.
         *
         * @param int $agent_id ID agente.
         * @return array{ok:bool, collected:int, evaluated:int, rewritten:int, published:int, messages:array}
         */
        public static function run_full( $agent_id ) {
                try {
                        return self::run_full_agent( $agent_id );
                } finally {
                        // v1.1.5: lo stato runtime va sempre rimosso a fine run,
                        // successo o errore (il finally gira anche su eccezione).
                        self::clear_runtime( absint( $agent_id ) );
                }
        }

        /**
         * Corpo della pipeline completa per un agente: rispetta i toggle delle
         * impostazioni (auto_collect / auto_evaluate / auto_rewrite /
         * auto_publish) e la modalità.
         *
         * - fully_autonomous + punteggio >= auto_approve_score dopo la riscrittura
         *   → approvazione + pubblicazione automatiche.
         * - semi_autonomous → ci si ferma a 'pending_approval' (coda umana).
         * - max_articles_per_day → limite di articoli creati oggi (guardia pre-raccolta).
         *
         * @param int $agent_id ID agente.
         * @return array{ok:bool, collected:int, evaluated:int, rewritten:int, published:int, messages:array}
         */
        private static function run_full_agent( $agent_id ) {
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

                        // v1.1.9 — Time budget per articolo: le fasi AI di UN solo articolo non
                        // devono poter occupare il tick all'infinito (un modello lento non deve
                        // bloccare il lavoro degli altri). Superato il budget le fasi successive
                        // si interrompono con grazia: l'articolo resta nel suo stato corrente e
                        // i cicli successivi (resume_stalled / coda del capo) lo recuperano.
                        $article_started_at = microtime( true );
                        $phase_budget       = self::phase_budget( $article_id );

                        // FASE 2: valutazione.
                        if ( ! empty( $settings['auto_evaluate'] )
                                && self::article_time_available( $article_id, (int) $article->agent_id, $article_started_at, $phase_budget, 'valutazione' ) ) {
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
                        if ( ! empty( $settings['auto_rewrite'] )
                                && self::article_time_available( $article_id, (int) $article->agent_id, $article_started_at, $phase_budget, 'riscrittura' ) ) {
                                $rewriting = self::rewrite( $article_id );
                                if ( ! empty( $rewriting['ok'] ) ) {
                                        $summary['rewritten']++;
                                        $rewritten_now = true;
                                        $summary['messages'][] = sprintf( 'Articolo #%d riscritto e messo in coda di approvazione.', $article_id );
                                        $article = Nexus_DB::get_article( $article_id );

                                        // Fact-check sotto soglia (v1.0.3): l'articolo è tornato in bozza,
                                        // nessuna approvazione/pubblicazione automatica in questo tick.
                                        if ( $article && 'draft' === $article->status ) {
                                                $summary['messages'][] = sprintf( 'Articolo #%d rimesso in bozza dal fact-check: serve revisione umana.', $article_id );
                                                continue;
                                        }
                                } else {
                                        $summary['messages'][] = sprintf( 'Riscrittura articolo #%d fallita: %s', $article_id, $rewriting['error'] );
                                }
                        }

                        if ( ! $article ) {
                                continue;
                        }

                        // FASI 4-5: approvazione/pubblicazione automatiche secondo modalità e soglia.
                        if ( ! self::article_time_available( $article_id, (int) $article->agent_id, $article_started_at, $phase_budget, 'approvazione/pubblicazione' ) ) {
                                continue;
                        }

                        $threshold    = absint( isset( $settings['auto_approve_score'] ) ? $settings['auto_approve_score'] : 75 );
                        $score        = (float) $article->quality_score;
                        $is_full_auto = ( 'fully_autonomous' === $mode );

                        // v1.1.9 — Guard capo redattore anche sull'auto-approvazione del run: la
                        // guardia in publish() da sola non basterebbe (qui l'articolo viene PRIMA
                        // approvato con approve() e POI pubblicato). Con il capo attivo e revisione
                        // ancora da fare l'auto-approvazione salta: l'articolo resta in coda e lo
                        // prende il blocco chief_dispatch in fondo al ciclo.
                        $chief_gate = $is_full_auto && self::awaiting_chief_review( $article );

                        if ( $rewritten_now && $is_full_auto && $score >= $threshold && ! $chief_gate ) {
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
                        } elseif ( $rewritten_now && $chief_gate ) {
                                $summary['messages'][] = sprintf( 'Articolo #%d in attesa del capo redattore: auto-approvazione e pubblicazione automatiche saltate.', $article_id );
                                self::log_phase( (int) $article->agent_id, 'evaluating', sprintf( 'Articolo #%d in attesa del capo redattore: auto-approvazione saltata (modalità fully autonomous).', $article_id ), 'info' );
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

                        // v1.1.4 — CAPO REDATTORE AI: se dopo la riscrittura l'articolo e'
                        // rimasto in coda (pending_approval: nessuna auto-approvazione) e il
                        // capo redattore e' attivo, la revisione passa a lui. Nessun doppio
                        // recapito: lo stato viene riletto e stato diverso da pending_approval
                        // non viene inviato (l'auto-approvazione fully_autonomous NON cambia).
                        if ( $rewritten_now ) {
                                $chief_article = Nexus_DB::get_article( $article_id );
                                if ( $chief_article && 'pending_approval' === $chief_article->status ) {
                                        self::chief_dispatch( $article_id, (int) $chief_article->agent_id, (string) $chief_article->title );
                                }
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

                // v1.1.9 — Resume articoli bloccati ALL'INIZIO del tick (WP4): UN solo
                // batch per chiamata (limit interno, default 10) per non sovrapporsi al
                // lavoro degli agenti né mandare in loop il tick. Mai fatale: il giro
                // regolare degli agenti deve proseguire comunque.
                try {
                        self::resume_stalled();
                } catch ( Throwable $e ) {
                        self::log_phase( 0, 'rewriting', sprintf( 'Resume articoli bloccati fallito: %s', self::clip( $e->getMessage(), 300 ) ), 'error' );
                }

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

                // v1.1.4 — Capo redattore AI: dopo il giro degli agenti il capo rilegge
                // fino a 3 articoli in attesa di revisione (giri di revisione inclusi),
                // se la funzionalita' e' attiva. Guard totali, mai fatale.
                if ( class_exists( 'Nexus_Chief' ) && method_exists( 'Nexus_Chief', 'enabled' ) && method_exists( 'Nexus_Chief', 'run_for_pending' ) ) {
                        try {
                                if ( Nexus_Chief::enabled() ) {
                                        Nexus_Chief::run_for_pending( 3 );
                                }
                        } catch ( Throwable $e ) {
                                Nexus_DB::insert_activity( 0, 'evaluating', sprintf( 'Errore capo redattore nel tick del cron: %s', self::clip( $e->getMessage(), 300 ) ), 'error' );
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
         * v1.1.9 — Resume degli articoli bloccati (WP4 "Pipeline resume").
         *
         * Un crash a metà tick lasciava bozze 'draft' orfane per sempre: il collect
         * le crea, ma le fasi successive (evaluate → rewrite → fact-check) girano
         * solo nel medesimo tick. Qui si ripescano le bozze ferme da oltre
         * $max_age_hours e si rilanciano le fasi successive riusando evaluate() e
         * rewrite() (il fact-check è già dentro rewrite).
         *
         * Selettivo per costruzione:
         * - solo stato 'draft' (la pipeline NON usa stati intermedi tipo
         *   'pending_evaluation'/'in_rewrite': lo stallo visibile è il draft);
         * - solo con fonte impostata (le bozze della pipeline nascono da collect);
         * - solo se auto_evaluate/auto_rewrite lo consentono: una bozza parcheggiata
         *   dai toggle NON è uno stallo, non si aggira la configurazione;
         * - escluse le bozze rimandate in draft dal fact-check (factcheck_score
         *   valorizzato = richiesta di revisione umana, non un orfano: riprenderle
         *   creerebbe un loop AI di riscritture ogni 6h);
         * - esclusi gli articoli già in lavorazione (stato runtime degli agenti
         *   e lock del capo redattore).
         *
         * Chiamata UNA volta per tick (da cron_tick) e al massimo $limit articoli:
         * il resto si riprende ai tick successivi.
         *
         * @param int $max_age_hours Età minima dello stallo in ore (default 6).
         * @param int $limit         Numero massimo di articoli ripresi per chiamata (default 10).
         * @return array{ok:bool, resumed:int, skipped:int, messages:array}
         */
        public static function resume_stalled( $max_age_hours = 6, $limit = 10 ) {
                $out = array(
                        'ok'       => true,
                        'resumed'  => 0,
                        'skipped'  => 0,
                        'messages' => array(),
                );

                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'articles' ) ) {
                        return $out;
                }

                $limit         = min( 20, max( 1, absint( $limit ) ) );
                $max_age_hours = max( 1, absint( $max_age_hours ) );
                $threshold     = current_time( 'timestamp' ) - $max_age_hours * HOUR_IN_SECONDS;

                // Solo API db esistenti (NESSUNA query diretta): i draft più vecchi per
                // primi, i filtri di stallo/lavorazione applicati qui in PHP.
                $rows = Nexus_DB::articles( array(
                        'status'   => 'draft',
                        'orderby'  => 'created_at',
                        'order'    => 'asc',
                        'per_page' => 100,
                        'page'     => 1,
                ) );
                if ( ! is_array( $rows ) ) {
                        return $out;
                }

                // Runtime corrente: un articolo già "in redazione" per un agente non si tocca.
                $busy = array();
                foreach ( self::get_runtime() as $state ) {
                        if ( is_array( $state ) && ! empty( $state['article_id'] ) ) {
                                $busy[ absint( $state['article_id'] ) ] = true;
                        }
                }

                $settings      = self::settings();
                $auto_evaluate = ! empty( $settings['auto_evaluate'] );
                $auto_rewrite  = ! empty( $settings['auto_rewrite'] );

                foreach ( $rows as $row ) {
                        if ( $out['resumed'] >= $limit ) {
                                break;
                        }

                        $article_id = absint( isset( $row->id ) ? $row->id : 0 );
                        if ( $article_id < 1 || isset( $busy[ $article_id ] ) ) {
                                continue;
                        }

                        // Lock del capo redattore (difensivo: le bozze non sono in revisione,
                        // ma il lock esclude ogni sovrapposizione con la coda).
                        if ( class_exists( 'Nexus_Chief' ) && get_transient( Nexus_Chief::LOCK_PREFIX . $article_id ) ) {
                                $out['skipped']++;
                                continue;
                        }

                        // Età dello stallo: updated_at (fallback created_at) più vecchio della soglia.
                        $stamp_raw = isset( $row->updated_at ) && '' !== (string) $row->updated_at ? (string) $row->updated_at : (string) $row->created_at;
                        $stamp     = strtotime( $stamp_raw );
                        if ( ! $stamp || $stamp > $threshold ) {
                                continue; // Ancora fresco: non è uno stallo.
                        }

                        if ( '' === trim( (string) ( isset( $row->source_name ) ? $row->source_name : '' ) ) ) {
                                continue; // Senza fonte non è una bozza prodotta dalla pipeline.
                        }

                        if ( isset( $row->factcheck_score ) && null !== $row->factcheck_score ) {
                                $out['skipped']++;
                                continue; // Tornato in bozza dal fact-check: la revisione è richiesta all'umano.
                        }

                        if ( ! $auto_evaluate && ! $auto_rewrite ) {
                                $out['skipped']++;
                                continue; // Fasi successive disattivate: bozza parcheggiata di proposito.
                        }

                        // Log onesto del resume, PRIMA di rilanciare le fasi.
                        self::log_phase( (int) ( isset( $row->agent_id ) ? $row->agent_id : 0 ), 'rewriting', sprintf( 'Resume dopo stallo: articolo #%d in bozza da oltre %dh: rilancio valutazione/riscrittura.', $article_id, $max_age_hours ), 'info' );

                        if ( $auto_evaluate ) {
                                $evaluation = self::evaluate( $article_id );
                                if ( empty( $evaluation['ok'] ) ) {
                                        $out['messages'][] = sprintf( 'Articolo #%d: valutazione fallita nel resume: %s', $article_id, (string) $evaluation['error'] );
                                }
                        }

                        if ( $auto_rewrite ) {
                                $rewriting = self::rewrite( $article_id );
                                if ( ! empty( $rewriting['ok'] ) ) {
                                        $out['resumed']++;
                                        $out['messages'][] = sprintf( 'Articolo #%d ripreso dopo stallo: riscritto e messo in coda di approvazione.', $article_id );
                                } else {
                                        // Resta in bozza: il prossimo tick tenterà di nuovo il resume.
                                        $out['messages'][] = sprintf( 'Articolo #%d: riscrittura fallita nel resume: %s', $article_id, (string) $rewriting['error'] );
                                }
                        } else {
                                // Solo valutazione attiva: la bozza è stata comunque fatta ripartire.
                                $out['resumed']++;
                                $out['messages'][] = sprintf( 'Articolo #%d ripreso dopo stallo: rivalutato.', $article_id );
                        }
                }

                if ( $out['resumed'] > 0 ) {
                        self::log_phase( 0, 'rewriting', sprintf( 'Resume articoli bloccati: %d bozze riprese (ferme da oltre %dh).', $out['resumed'], $max_age_hours ), 'info' );
                }

                return $out;
        }

        /**
         * Affida un articolo alla revisione del capo redattore AI (v1.1.4).
         *
         * Preferisce la coda lavori ('nexus_chief_review': asincrona con
         * enable_queue=1, sincrona con enable_queue=0); se la coda non e'
         * disponibile chiama Nexus_Chief::review_article direttamente. Guardie
         * difensive: senza Nexus_Chief (versioni precedenti) o con capo
         * disattivato il flusso resta identico alla versione precedente.
         *
         * v1.1.5: prima dell'invio imposta lo stato runtime 'chief' sul
         * giornalista proprietario dell'articolo (etichetta "Revisione del capo
         * redattore"): e' l'agente su cui il pannello live mostra il lavoro in
         * corso. Lo stato viene rimosso dal finally di run_full.
         *
         * @param int    $article_id    ID articolo in pending_approval.
         * @param int    $agent_id      ID giornalista proprietario (0 = nessuno).
         * @param string $article_title Titolo dell'articolo (per il pannello live).
         * @return void
         */
        private static function chief_dispatch( $article_id, $agent_id = 0, $article_title = '' ) {
                if ( ! class_exists( 'Nexus_Chief' ) || ! method_exists( 'Nexus_Chief', 'enabled' ) ) {
                        return; // Capo redattore non disponibile: flusso invariato.
                }

                try {
                        if ( ! Nexus_Chief::enabled() ) {
                                return;
                        }
                } catch ( Throwable $e ) {
                        return; // Mai bloccare la pipeline per un errore del capo redattore.
                }

                // v1.1.5: stato runtime per il live sulla fase di revisione del
                // capo redattore (mostrata sul giornalista proprietario).
                self::set_runtime( absint( $agent_id ), 'chief', 'Revisione del capo redattore', absint( $article_id ), (string) $article_title );

                if ( class_exists( 'Nexus_Queue' ) && method_exists( 'Nexus_Queue', 'dispatch' ) ) {
                        try {
                                Nexus_Queue::dispatch( 'nexus_chief_review', array( 'article_id' => (int) $article_id ) );
                                return;
                        } catch ( Throwable $e ) {
                                // Coda non disponibile: fallback alla revisione diretta.
                        }
                }

                if ( method_exists( 'Nexus_Chief', 'review_article' ) ) {
                        try {
                                Nexus_Chief::review_article( (int) $article_id, false );
                        } catch ( Throwable $e ) {
                                self::log_phase( 0, 'evaluating', sprintf( 'Capo redattore: revisione articolo #%d fallita: %s', (int) $article_id, self::clip( $e->getMessage(), 300 ) ), 'error' );
                        }
                }
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

                // Budget mensile (v1.0.3): il wire effettua più chiamate AI (3 angoli + direttore).
                if ( ! self::budget_allows( 'nexus wire', (int) $first->id ) ) {
                        $run['error'] = 'Fase nexus wire saltata: budget mensile esaurito.';
                        self::save_wire_run( $run );
                        return array(
                                'ok'  => false,
                                'run' => $run,
                        );
                }

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
                        // v1.0.3: tracking usage del wire con l'agente proponente.
                        // v1.1.9: json_mode (contratto Task 4.8): il prompt chiede un oggetto JSON.
                        $res = self::chat( $prompt, 'wire', array( 'agent_id' => (int) $agent->id, 'json_mode' => true ) );

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
                // v1.0.3: tracking usage della chiamata direttore (agente responsabile).
                // v1.1.9: json_mode (contratto Task 4.8): il prompt chiede un oggetto JSON.
                $res = self::chat( $director_prompt, 'wire', array( 'agent_id' => (int) $first->id, 'json_mode' => true ) );

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

        /* ================= RUNTIME AGENTE PER IL LIVE (v1.1.5, T-03) =================
         *
         * Stato "cosa sta facendo l'agente adesso" salvato nella option
         * nexus_agent_runtime (array agent_id => {step, label, article_id,
         * article_title, since}). Alimenta GET /nexus/v1/live per il pannello
         * "In redazione ora" della dashboard. update_option SOLO quando lo
         * stato cambia realmente (polling e fasi successive non riscrivono
         * sempre). since e' ISO8601 UTC (gmdate('c')): il consumer puo'
         * calcolare da quanto gira senza ambiguita' di fuso.
         */

        /**
         * Imposta (o aggiorna) lo stato runtime di un agente.
         *
         * La option viene scritta solo se lo stato cambia realmente (stesso
         * step/label/articolo = nessuna scrittura, since resta invariato):
         * evita write storm con polling e fasi ravvicinate.
         *
         * @param int    $agent_id      ID agente (minimo 1, altri valori ignorati).
         * @param string $step          Fase logica (collect|evaluate|rewrite|publish|chief).
         * @param string $label         Etichetta italiana per il pannello live.
         * @param int    $article_id    ID articolo in lavorazione (0 = nessuno).
         * @param string $article_title Titolo articolo in lavorazione.
         * @return void
         */
        public static function set_runtime( $agent_id, $step, $label, $article_id = 0, $article_title = '' ) {
                $agent_id = absint( $agent_id );
                if ( $agent_id < 1 ) {
                        return;
                }

                $state = array(
                        'step'          => sanitize_key( (string) $step ),
                        'label'         => self::clip( (string) $label, 120 ),
                        'article_id'    => absint( $article_id ),
                        'article_title' => self::clip( (string) $article_title, 200 ),
                        'since'         => gmdate( 'c' ),
                );

                $runtime = get_option( self::RUNTIME_OPTION, array() );
                if ( ! is_array( $runtime ) ) {
                        $runtime = array();
                }

                // Nessuna scrittura se lo stato non cambia (no write storm).
                $current = isset( $runtime[ $agent_id ] ) ? $runtime[ $agent_id ] : null;
                if ( is_array( $current )
                        && isset( $current['step'], $current['label'], $current['article_id'], $current['article_title'] )
                        && (string) $current['step'] === $state['step']
                        && (string) $current['label'] === $state['label']
                        && (int) $current['article_id'] === $state['article_id']
                        && (string) $current['article_title'] === $state['article_title'] ) {
                        return;
                }

                $runtime[ $agent_id ] = $state;
                update_option( self::RUNTIME_OPTION, $runtime, false );
        }

        /**
         * Rimuove lo stato runtime di un agente (fine run, successo o errore).
         *
         * Nessuna scrittura se l'agente non ha uno stato da rimuovere.
         *
         * @param int $agent_id ID agente (minimo 1, altri valori ignorati).
         * @return void
         */
        public static function clear_runtime( $agent_id ) {
                $agent_id = absint( $agent_id );
                if ( $agent_id < 1 ) {
                        return;
                }

                $runtime = get_option( self::RUNTIME_OPTION, array() );
                if ( ! is_array( $runtime ) || ! array_key_exists( $agent_id, $runtime ) ) {
                        return;
                }

                unset( $runtime[ $agent_id ] );
                update_option( self::RUNTIME_OPTION, $runtime, false );
        }

        /**
         * Legge l'intera option dello stato runtime (array agent_id => stato).
         *
         * @return array Sempre un array (option corrotta o assente = array vuoto).
         */
        public static function get_runtime() {
                $runtime = get_option( self::RUNTIME_OPTION, array() );
                return is_array( $runtime ) ? $runtime : array();
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
         * v1.0.3: oltre alla fase, passa al motore gli opt 'model' (modello risolto
         * per la fase corrente), 'agent_id' e 'action' per il tracking dell'uso AI
         * su wp_nexus_usage_logs. Un motore più vecchio (v1.0.2) ignora questi opt:
         * passarli è sempre innocuo.
         *
         * @param string $prompt Prompt completo.
         * @param string $phase  Fase logica (collect|evaluate|rewrite|wire) — usata anche come 'action'.
         * @param array  $opts   Opzioni extra: 'model' (string), 'agent_id' (int), 'json_mode' (bool, v1.1.9).
         * @return array{ok:bool, content:string, error:string, simulated:bool}
         */
        private static function chat( $prompt, $phase, $opts = array() ) {
                $opts = is_array( $opts ) ? $opts : array();

                $send = array(
                        'phase'    => (string) $phase, // Compatibilità v1.0.x.
                        'action'   => (string) $phase, // v1.0.3: azione per il tracking usage.
                        'agent_id' => isset( $opts['agent_id'] ) ? absint( $opts['agent_id'] ) : 0,
                );
                if ( isset( $opts['model'] ) && '' !== trim( (string) $opts['model'] ) ) {
                        $send['model'] = trim( (string) $opts['model'] );
                }

                // v1.1.10 (G1, piano Parte 2): l'oggetto agente viene inoltrato al motore
                // così resolve_for_agent() usa il PROVIDER per-agente (agents.ai_provider)
                // invece di scrivere sempre col provider globale. Solo se l'agente ha
                // davvero un provider dedicato (altrimenti percorso globale intatto).
                if ( isset( $opts['agent'] ) && is_object( $opts['agent'] ) && ! empty( $opts['agent']->ai_provider ) ) {
                        $send['agent'] = $opts['agent'];
                }

                // v1.1.9 (contratto Task 4.8): 'json_mode' chiede al motore una risposta in
                // JSON dove il flusso se la aspetta. Opzionale e retrocompatibile: un motore
                // più vecchio o un provider che non la supporta la ignora.
                if ( ! empty( $opts['json_mode'] ) ) {
                        $send['json_mode'] = true;
                }

                $res = Nexus_AI_Engine::chat(
                        array(
                                array(
                                        'role'    => 'user',
                                        'content' => (string) $prompt,
                                ),
                        ),
                        $send
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
         * Impostazioni del plugin in forma difensiva (v1.0.3).
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
         * Guardia del budget mensile AI (v1.0.3), da chiamare PRIMA di ogni fase che
         * effettua chiamate AI.
         *
         * - budget_exceeded() = false (o motore v1.0.2 senza il metodo): la fase prosegue.
         * - budget_action 'warn': registra un avviso e la fase prosegue.
         * - budget_action 'stop' (default): registra l'attività 'budget' e la fase viene
         *   saltata; il chiamante prosegue con le fasi successive (il tick non si blocca).
         *
         * @param string $phase    Nome fase leggibile (raccolta|valutazione|riscrittura|fact-check|nexus wire).
         * @param int    $agent_id ID agente per l'attività (0 = sistema).
         * @return bool True se la fase può procedere, false se va saltata.
         */
        private static function budget_allows( $phase, $agent_id = 0 ) {
                if ( ! class_exists( 'Nexus_AI_Engine' ) || ! method_exists( 'Nexus_AI_Engine', 'budget_exceeded' ) ) {
                        return true; // Motore v1.0.2: nessun controllo di budget disponibile.
                }

                try {
                        $exceeded = Nexus_AI_Engine::budget_exceeded();
                } catch ( \Throwable $e ) {
                        return true; // Mai bloccare la pipeline per un errore del controllo budget.
                }

                if ( ! $exceeded ) {
                        return true;
                }

                $settings    = self::settings();
                $bud_action  = isset( $settings['budget_action'] ) ? (string) $settings['budget_action'] : 'stop';

                if ( 'warn' === $bud_action ) {
                        // Solo avviso: la fase prosegue comunque.
                        self::log_phase( (int) $agent_id, 'budget', sprintf( 'Budget mensile esaurito: la fase %s prosegue comunque (budget_action=warn).', $phase ), 'warning' );
                        return true;
                }

                // 'stop' (default): fase saltata, il tick continua con le fasi successive.
                self::log_phase( (int) $agent_id, 'budget', sprintf( 'Fase %s saltata: budget mensile esaurito.', $phase ), 'error' );
                return false;
        }

        /**
         * Modello economico per screening/valutazione/fact-check (v1.0.3):
         * settings ai_model_cheap se impostato, altrimenti ai_model.
         *
         * @return string
         */
        private static function cheap_model() {
                $settings = self::settings();
                $cheap    = isset( $settings['ai_model_cheap'] ) ? trim( (string) $settings['ai_model_cheap'] ) : '';
                if ( '' !== $cheap ) {
                        return $cheap;
                }
                return isset( $settings['ai_model'] ) ? trim( (string) $settings['ai_model'] ) : '';
        }

        /**
         * Modello per-agente per la fase rewrite (v1.0.3): agents.ai_model se non vuoto,
         * altrimenti il modello globale settings ai_model.
         *
         * @param object|null $agent Agente (o null se non trovato).
         * @return string
         */
        private static function agent_model( $agent ) {
                if ( $agent && ! empty( $agent->ai_model ) ) {
                        return trim( (string) $agent->ai_model );
                }
                $settings = self::settings();
                return isset( $settings['ai_model'] ) ? trim( (string) $settings['ai_model'] ) : '';
        }

        /**
         * Hash anti-duplicato del titolo (v1.0.3): md5 del titolo senza tag, spazi
         * normalizzati e minuscole (mb_strtolower con fallback, mai fatale).
         *
         * @param string $title Titolo candidato.
         * @return string Hash md5 (32 caratteri).
         */
        private static function title_hash( $title ) {
                $norm = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $title ) ) );
                if ( function_exists( 'mb_strtolower' ) ) {
                        $norm = mb_strtolower( $norm, 'UTF-8' );
                } else {
                        $norm = strtolower( $norm );
                }
                return md5( $norm );
        }

        /**
         * Scarica il testo della fonte originale per il fact-check (v1.1.9).
         *
         * Fetch SOLO del source_url dell'articolo. Dalla v1.1.10 il source_url può
         * arrivare da adapter multi-tipo (web, search, MCP) e da contenuti non
         * fidati: la guardia SSRF centralizzata Nexus_Tools::assert_safe_url()
         * (DNS + IP privati/riservati) viene applicata SEMPRE, fail-closed
         * (v1.1.10.1 — chiude il gap audit 1.1.9/1.1.10). Cache
         * transient 'nexus_src_{md5url}' 6h: il fact-check gira a ogni riscrittura ma
         * la fonte non cambia. Ritorna '' a qualunque fallimento (chiamante fa il
         * fallback sul riassunto con log onesto).
         *
         * @param string $url URL della fonte.
         * @return string Testo pulito (max 20k caratteri) o '' se non raggiungibile.
         */
        private static function fetch_source_text( $url ) {
                $url = trim( (string) $url );
                if ( '' === $url || ! function_exists( 'wp_remote_get' ) ) {
                        return '';
                }

                // v1.1.10.1 — Guardia SSRF esplicita: anche il fetch del fact-check
                // passa dalla guardia centralizzata (DNS + IP privati/riservati).
                // Fail-closed: URL non consentito → nessun fetch, testo vuoto.
                if ( true !== Nexus_Tools::assert_safe_url( $url ) ) {
                        return '';
                }

                // Cache 6h per URL (chiave md5 come da contratto WP4).
                $cache_key = 'nexus_src_' . md5( $url );
                $cached    = get_transient( $cache_key );
                if ( is_string( $cached ) && '' !== $cached ) {
                        return $cached;
                }

                $response = wp_remote_get(
                        $url,
                        array(
                                'timeout'             => 15,
                                'redirection'         => 2, // Evita redirect infiniti.
                                'limit_response_size' => 512000, // 500 KB: più che sufficiente per un articolo.
                                'user-agent'          => 'Nexus News AI/' . ( defined( 'NEXUS_VERSION' ) ? NEXUS_VERSION : '1.1.9' ) . ' (WordPress; ' . home_url() . ')',
                        )
                );

                if ( is_wp_error( $response ) ) {
                        return '';
                }

                $code = (int) wp_remote_retrieve_response_code( $response );
                if ( $code < 200 || $code >= 300 ) {
                        return '';
                }

                $body = wp_remote_retrieve_body( $response );
                if ( ! is_string( $body ) || '' === trim( $body ) ) {
                        return '';
                }

                // Il modello verifica testo: via tag, normalizzato e clip a 20k caratteri
                // (budget prompt: 20k coprono ampiamente il piombo della fonte).
                $text = self::clip( wp_strip_all_tags( $body ), 20000 );
                if ( '' === $text ) {
                        return '';
                }

                // Solo i successi finiscono in cache: i fallimenti riprovano al giro successivo.
                set_transient( $cache_key, $text, 6 * HOUR_IN_SECONDS );

                return $text;
        }

        /**
         * Budget di tempo per le fasi AI di un singolo articolo nel tick (v1.1.9).
         * Default 90 secondi, filtrabile con 'nexus_pipeline_phase_budget'; valori
         * <= 0 disattivano il limite (comportamento precedente).
         *
         * @param int $article_id ID articolo (passato al filtro per tetto per-articolo).
         * @return float Secondi (0 = nessun limite).
         */
        private static function phase_budget( $article_id = 0 ) {
                $budget = (float) apply_filters( 'nexus_pipeline_phase_budget', 90, absint( $article_id ) );
                return $budget > 0 ? $budget : 0.0;
        }

        /**
         * Guardia del time budget per articolo (v1.1.9): true se l'articolo può
         * ancora avviare una fase AI nel tick corrente. Al superamento logga un'
         * attività onesta e il chiamante salta le fasi rimanenti con grazia.
         *
         * @param int    $article_id  ID articolo.
         * @param int    $agent_id    ID agente proprietario.
         * @param float  $started_at  microtime(true) all'inizio delle fasi di questo articolo.
         * @param float  $budget      Budget in secondi (0 = nessun limite).
         * @param string $phase_label Nome della fase che si vorrebbe avviare (per il log).
         * @return bool
         */
        private static function article_time_available( $article_id, $agent_id, $started_at, $budget, $phase_label ) {
                if ( $budget <= 0 ) {
                        return true; // Limite disattivato via filtro.
                }
                if ( ( microtime( true ) - (float) $started_at ) < $budget ) {
                        return true;
                }
                self::log_phase( (int) $agent_id, 'budget', sprintf( 'Articolo #%d: time budget per articolo esaurito (%.0fs) prima della fase "%s": fasi successive interrotte con grazia, i cicli successivi (resume/coda) recuperano.', $article_id, $budget, $phase_label ), 'warning' );
                return false;
        }

        /**
         * v1.1.9 — L'articolo è in attesa della revisione del capo redattore?
         *
         * Vero se il capo è attivo (setting chief_enabled + almeno un capo
         * configurato) e il chief_status dell'articolo è vuoto (mai visto) oppure
         * 'revisione' (giro di revisione in corso). Guardie difensive: colonne
         * chief_ assenti (schema vecchio) o errore del capo = false, cioè il
         * comportamento precedente (nessun blocco della pubblicazione).
         *
         * @param object $article Articolo (riga nexus_articles).
         * @return bool
         */
        private static function awaiting_chief_review( $article ) {
                $chief_status = isset( $article->chief_status ) ? trim( (string) $article->chief_status ) : '';
                if ( '' !== $chief_status && 'revisione' !== $chief_status ) {
                        return false; // Esito del capo già presente ('visto'/'modificato'/'rifiutato') o colonna assente.
                }

                if ( ! class_exists( 'Nexus_Chief' ) || ! method_exists( 'Nexus_Chief', 'enabled' ) ) {
                        return false;
                }

                try {
                        return (bool) Nexus_Chief::enabled();
                } catch ( Throwable $e ) {
                        return false; // Mai bloccare la pipeline per un errore del capo.
                }
        }

        /**
         * Fact-check del draft appena riscritto (v1.0.3), mai bloccante.
         *
         * Attivo solo se enable_factcheck == 1 e c'è almeno un riferimento: da
         * v1.1.9 il riferimento preferito è il TESTO della source_url (riscaricato
         * con cache 6h, vedi fetch_source_text); se la fonte non è raggiungibile si
         * ripiega sul riassunto del collect-stage con log onesto.
         * Flusso: factcheck_prompt → chat (modello economico, action 'factcheck') →
         * extract_json → update_article(factcheck_score, citations). Sotto
         * factcheck_min_score l'articolo torna in bozza con attività 'error';
         * sopra soglia un'attività 'success'; se extract fallisce un'attività
         * 'warning' senza interrompere il flusso.
         *
         * @param int    $article_id ID articolo.
         * @param object $article    Articolo PRIMA dell'aggiornamento (contiene il riassunto fonte originale).
         * @param int    $agent_id   ID agente proprietario (per tracking e attività).
         * @return void
         */
        private static function factcheck_article( $article_id, $article, $agent_id ) {
                $settings = self::settings();
                if ( empty( $settings['enable_factcheck'] ) ) {
                        return; // Fact-check disattivato: flusso identico a v1.0.2.
                }

                // Fonte originale necessaria: serve almeno un riferimento (URL da scaricare
                // oppure il riassunto del collect-stage).
                $source_summary = isset( $article->summary ) ? trim( (string) $article->summary ) : '';
                $source_url     = isset( $article->source_url ) ? trim( (string) $article->source_url ) : '';
                if ( '' === $source_summary && '' === $source_url ) {
                        return;
                }

                // v1.1.9 — Fact-check su fonte reale: il riferimento è il TESTO della
                // source_url riscaricato, non il riassunto del collect-stage (prodotto
                // dalla stessa AI che ha generato il draft: verifica debole). Se la fonte
                // non è raggiungibile si usa il riassunto con log onesto.
                $source_reference = '';
                if ( '' !== $source_url ) {
                        $source_reference = self::fetch_source_text( $source_url );
                }
                if ( '' === $source_reference ) {
                        if ( '' === $source_summary ) {
                                return; // Nessun riferimento utilizzabile (né fonte né riassunto).
                        }
                        if ( '' !== $source_url ) {
                                self::log_phase( (int) $agent_id, 'factcheck', sprintf( 'Fact-check articolo #%d: fonte non raggiungibile, fact-check sul riassunto.', $article_id ), 'warning' );
                        }
                        $source_reference = $source_summary;
                }

                // Motore senza factcheck_prompt (v1.0.2): skip silenzioso.
                if ( ! class_exists( 'Nexus_AI_Engine' )
                        || ! method_exists( 'Nexus_AI_Engine', 'factcheck_prompt' )
                        || ! method_exists( 'Nexus_AI_Engine', 'extract_json' )
                        || ! method_exists( 'Nexus_AI_Engine', 'chat' ) ) {
                        return;
                }

                // Il fact-check è una chiamata AI: soggetta al budget mensile.
                if ( ! self::budget_allows( 'fact-check', (int) $agent_id ) ) {
                        return;
                }

                // Rileggi l'articolo AGGIORNATO: il fact-check valuta il testo riscritto.
                $current = Nexus_DB::get_article( $article_id );
                if ( ! $current ) {
                        return;
                }

                $messages = Nexus_AI_Engine::factcheck_prompt(
                        (string) $current->title,
                        (string) $current->content,
                        $source_reference,
                        $source_url
                );
                if ( ! is_array( $messages ) || empty( $messages ) ) {
                        return;
                }

                $res = Nexus_AI_Engine::chat(
                        $messages,
                        array(
                                'model'     => self::cheap_model(),
                                'agent_id'  => (int) $agent_id,
                                'action'    => 'factcheck',
                                'json_mode' => true, // v1.1.9 (contratto Task 4.8): la risposta attesa è un oggetto JSON.
                        )
                );

                if ( empty( $res['ok'] ) ) {
                        // Chiamata fallita: solo un avviso, non bloccare la riscrittura riuscita.
                        self::log_phase( (int) $agent_id, 'factcheck', sprintf( 'Fact-check articolo #%d non eseguito: %s', $article_id, self::chat_error( $res, 'motore AI non disponibile' ) ), 'warning' );
                        return;
                }

                $data = Nexus_AI_Engine::extract_json( (string) $res['content'] );
                if ( ! is_array( $data ) ) {
                        self::log_phase( (int) $agent_id, 'factcheck', sprintf( 'Fact-check articolo #%d: risposta non interpretabile, verifica saltata.', $article_id ), 'warning' );
                        return;
                }

                // Punteggio clampato 0-100.
                $score = isset( $data['score'] ) && is_numeric( $data['score'] ) ? (float) $data['score'] : 0.0;
                $score = max( 0.0, min( 100.0, $score ) );

                $update = array( 'factcheck_score' => $score );

                // Citazioni: salvate come JSON leggibile (array non vuoto).
                if ( isset( $data['citations'] ) && is_array( $data['citations'] ) && ! empty( $data['citations'] ) ) {
                        $encoded = wp_json_encode( array_values( $data['citations'] ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
                        if ( false !== $encoded && null !== $encoded ) {
                                $update['citations'] = $encoded;
                        }
                }
                Nexus_DB::update_article( $article_id, $update );

                $min_score = isset( $settings['factcheck_min_score'] ) ? (float) $settings['factcheck_min_score'] : 60.0;

                if ( $score < $min_score ) {
                        // Sotto soglia: l'articolo torna in bozza per revisione umana.
                        Nexus_DB::update_article( $article_id, array( 'status' => 'draft' ) );

                        $issues      = array();
                        $issues_text = '';
                        if ( isset( $data['issues'] ) && is_array( $data['issues'] ) ) {
                                $issues = $data['issues'];
                        }
                        foreach ( array_slice( $issues, 0, 2 ) as $issue ) {
                                $single       = self::clip( wp_strip_all_tags( (string) $issue ), 120 );
                                $issues_text .= ( '' === $issues_text ? '' : ' | ' ) . $single;
                        }
                        if ( '' === $issues_text ) {
                                $issues_text = 'nessun dettaglio fornito dal modello';
                        }

                        self::log_phase( 0, 'factcheck', sprintf( 'Fact-check %.0f/100 sotto soglia %.0f: articolo #%d in bozza. Problemi: %s', $score, $min_score, $article_id, $issues_text ), 'error' );
                        return;
                }

                self::log_phase( 0, 'factcheck', sprintf( 'Fact-check articolo #%d superato: %.0f/100 (soglia %.0f).', $article_id, $score, $min_score ), 'success' );
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
         * L'item porta il full-content estratto dalla pagina? (campo additivo
         * 'full_content' = 1 scritto da Nexus_Sources per le fonti full=1,
         * v1.1.10 WP3: description = testo plain della pagina, clip 6000).
         *
         * @param mixed $item Item fonte.
         * @return bool
         */
        private static function item_has_full_content( $item ) {
                return 1 === (int) self::item_field( $item, 'full_content' );
        }

        /**
         * Log di attività una sola volta per articolo (marcatore transient 24h,
         * auto-scadente): usato dai diagnostici v1.1.10 (full_content,
         * ai_output_quality) per non inquinare il log ad ogni re-run della fase.
         *
         * @param string $key        Chiave log (marker diversi sullo stesso articolo).
         * @param int    $article_id ID articolo.
         * @param int    $agent_id   ID agente (0 = sistema).
         * @param string $action     Azione.
         * @param string $detail     Dettaglio.
         * @param string $status     Stato (success|error|warning|info).
         * @return void
         */
        private static function log_once_article( $key, $article_id, $agent_id, $action, $detail, $status ) {
                $marker = 'nexus_log1_' . md5( (string) $key . '|' . (int) $article_id );
                if ( get_transient( $marker ) ) {
                        return;
                }
                set_transient( $marker, 1, DAY_IN_SECONDS );
                self::log_phase( (int) $agent_id, $action, $detail, $status );
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
                // v1.1.12 (S0): evento pipeline per pacchetti esterni — le notifiche
                // (S7) ascoltano status 'error'/'info' e instradano su webhook/Telegram.
                do_action( 'nexus_pipeline_phase', $agent_id, $action, $detail, $status );
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
