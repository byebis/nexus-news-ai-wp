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
 * v1.1.13 — Ottimizzazione (audit, pacchetto O1 sicurezza/pipeline):
 *   - SCORE AI: parse_score() NON inventa più un default 70 (né collect
 *     evergreen né evaluate): risposta AI illeggibile = punteggio 0 +
 *     'score_parsed' false → l'articolo NON si auto-approva MAI (qualunque
 *     soglia): va in attesa di revisione umana con log onesto del motivo.
 *   - RESUME STALLED: le bozze non riprendibili (senza fonte, factcheck_score
 *     > 0) sono escluse IN SQL (niente più starvation dei draft recenti oltre
 *     la finestra dei 100 più vecchi) e ogni articolo ripreso è protetto da
 *     un lock transient per-articolo (nexus_resume_lock_{id}, rilascio in
 *     finally, errore confinato al singolo articolo).
 *   - EVENTI STRUTTURATI: log_phase() accetta una chiave macchina evento
 *     (5° arg) che viaggia nel 5° parametro di 'nexus_pipeline_phase' come
 *     array {action, status, event}: notify instrada sulla chiave (fallback
 *     testuale mantenuto per BC).
 *
 * v1.1.16 — Agenti Viventi (telemetria run, WD1):
 *   - LIFECYCLE AGENT_RUNS: ogni run effettivo (dopo i gate che lo bloccano)
 *     apre una riga su wp_nexus_agent_runs (Nexus_DB::insert_agent_run) e la
 *     chiude in OGNI caso di uscita (try/catch/finally →
 *     Nexus_DB::finish_agent_run) con status 'ok'|'partial'|'failed',
 *     contatori (articles_created, candidates, sources_ok, sources_err),
 *     ultimo errore e steps {step, ok, detail, ms} per
 *     collect/evaluate/rewrite/publish. I gate che bloccano il run PRIMA
 *     che parta (agente assente/non attivo, auto_collect spento, cap
 *     giornaliero) NON lasciano nessuna run row.
 *   - TRIGGERED_BY: run_full_agent( $agent_id, $triggered_by = 'cron' ) —
 *     run_full() (REST/CLI/coda) apre run 'manual'; cron_tick() passa 'cron'.
 *     Parametro additivo in coda: i chiamanti esistenti restano validi.
 *   - STATO ERRORE VIVO: run 'failed' → agents.status='error' (escluso il
 *     caso stop per budget mensile: condizione economica già loggata come
 *     attività 'budget', non un guasto dell'agente — vedi run_full_phases);
 *     run 'ok'/'partial' riporta 'error' → 'active' SOLO se lo status
 *     corrente è già 'error' (mai toccati 'paused' e altri stati scelti
 *     dall'utente). Telemetria fonti (WD1b): collect() espone le chiavi
 *     additive 'candidates', 'sources_ok' e 'sources_err'.
 *
 * v1.1.18 — Recupero e Segreti (FIX-1: CR-1, AL-6, AL-7, AL-8, AL-11):
 *   - REAPER RUN FANTASMA (CR-1): a inizio cron_tick le righe agent_runs
 *     rimaste 'running' da oltre 30 min (crash/timeout/OOM: il finally non è
 *     potuto girare) vengono CHIUSE con status 'failed' +
 *     error='reaper: run fantasma (timeout)'. La busy map del resume
 *     (resume_stalled) ha un TTL sul campo 'since' (RUNTIME_STALE_SECONDS):
 *     le voci runtime scadute sono ignorate e rimosse dalla option —
 *     l'articolo in lavorazione al momento del crash torna riprendibile
 *     (prima: escluso PER SEMPRE).
 *   - AUTO-RETRY AGENTI 'ERROR' (AL-6): nel tick un agente con
 *     status='error' la cui registrazione errore (agents.updated_at, scritta
 *     da update_agent nella transizione di finish_agent_run_row) è più
 *     vecchia del backoff (30 min) viene riattivato automaticamente
 *     ('error' → 'active') con activity log onesto: un outage AI transiente
 *     non blocca più la redazione fino a intervento umano.
 *   - WIRE CON LOCK + RUN ROW + HEARTBEAT (AL-7): wire_run acquisisce un
 *     mutex per-tema (pattern acquire_lock WA11), apre una run row
 *     agent_runs con trigger 'wire' (valore del contratto finora mai usato),
 *     la chiude nel finally e chiama heartbeat()/touch_agent_run() a ogni
 *     confine di fase: il reaper della coda (requeue_stale) e quello delle
 *     run (CR-1) non riaccodano/chiudono mai un Wire vivo → niente doppio
 *     costo AI per esecuzioni parallele.
 *   - RESUME LOCK ATOMICO (AL-8): il lock per-articolo del resume usa il
 *     pattern acquire_lock/release_lock (add_option check-and-set, valore =
 *     timestamp) al posto del set_transient check-then-set: due tick
 *     sovrapposti non processano più lo stesso articolo.
 *   - TRUNCATED ONESTO (AL-11): se il risultato della fase rewrite porta il
 *     marker 'truncated' del motore (contratto chat v1.1.9/v1.1.17: ultima
 *     risposta troncata per max_tokens) lo step fallisce con errore chiaro
 *     e l'articolo NON viene aggiornato né marcato completo (stessa guardia
 *     sulla chiamata direttore del Wire).
 *
 * v1.1.15 — Affidabilità (Perfezione Maniacale, WA2/WA5/WA6/WA7/WA10):
 *   - HEARTBEAT JOB: ogni fase (collect/evaluate/rewrite/publish) e il giro
 *     per-agente di cron_tick toccano Nexus_Queue::heartbeat() (started_at del
 *     job corrente): il reaper requeue_stale non riaccoda più i job vivi.
 *   - CRON TICK: budget di tempo globale (filtro nexus_pipeline_tick_budget,
 *     default 240s) verificato a inizio iterazione agente + cap agenti per tick
 *     (filtro nexus_pipeline_tick_agent_cap, default 5) con rotazione last_run;
 *     contratto di ritorno di cron_tick INVARIATO (results = agenti avviati).
 *   - RUNTIME ONESTO: collect/evaluate/rewrite (e publish) puliscono lo stato
 *     runtime in try/finally anche su uscita anticipata/eccezione (corpi spostati
 *     in collect_run/evaluate_run/rewrite_run/publish_locked).
 *   - MUTEX PUBLISH per-articolo (nexus_publish_lock_<id>, acquisizione atomica
 *     via add_option, TTL 120s, ri-check "published" dentro il lock, release in
 *     finally): chiude la doppia pubblicazione social dai percorsi concorrenti.
 *   - Limite giornaliero: 1 query diretta su created_at (indice schema v7)
 *     invece dell'intera stats() a ogni tick per ogni agente.
 *
 * CONTRATTO PUBBLICO:
 * Nexus_Pipeline::collect( $agent_id )                       → array{ok, collected, errors, article_ids, skipped?}
 * Nexus_Pipeline::evaluate( $article_id )                    → array{ok, score, score_parsed, error?}
 * Nexus_Pipeline::rewrite( $article_id )                     → array{ok, error?}
 * Nexus_Pipeline::approve( $article_id, $note = '' )         → array{ok, error?}
 * Nexus_Pipeline::reject( $article_id, $note = '' )          → array{ok, error?}
 * Nexus_Pipeline::publish( $article_id )                     → array{ok, logs, error?, already?, message?}
 * Nexus_Pipeline::run_full( $agent_id, $triggered_by = 'manual' ) → array{ok, collected, evaluated, rewritten, published, messages} (v1.1.16: $triggered_by additivo in coda, 'manual'|'cron')
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
         * Prefisso del lock per-articolo del resume (v1.1.13 O1): durante il resume
         * di una bozza il lock impedisce a un tick sovrapposto (o a un resume
         * concorrente) di lavorare lo stesso articolo.
         * v1.1.18 (AL-8): acquisizione ATOMICA via acquire_lock/release_lock
         * (add_option check-and-set, valore = timestamp) al posto del
         * set_transient check-then-set.
         */
        const RESUME_LOCK_PREFIX = 'nexus_resume_lock_';

        /**
         * Durata del lock per-articolo del resume in secondi (900 = 15 min):
         * copre evaluate + rewrite + fact-check (budget per fase 90s default);
         * il rilascio avviene comunque nel finally a fine lavorazione.
         */
        const RESUME_LOCK_TTL = 900;

        /**
         * v1.1.15 (WA5): budget di tempo di DEFAULT dell'intero tick (secondi),
         * regolabile con il filtro 'nexus_pipeline_tick_budget' (0 = senza limite).
         * Prima N agenti giravano in serie senza tetto: tick da 10-40+ min uccisi
         * da max_execution_time → lock residuo → 15 min di giri saltati.
         */
        const TICK_BUDGET_SECONDS = 240;

        /**
         * v1.1.15 (WA5): cap di agenti per singolo tick, regolabile con il filtro
         * 'nexus_pipeline_tick_agent_cap'. Gli agenti oltre il cap partono al giro
         * successivo (rotazione last_run, vedi sort_agents_by_last_run): zero
         * starvation anche con redazioni grandi.
         */
        const TICK_AGENT_CAP = 5;

        /**
         * v1.1.15 (WA7): prefisso del mutex di pubblicazione per-articolo.
         * publish() è raggiunto da 4 percorsi concorrenti (REST, CLI, capo
         * redattore, coda) e requeue_stale può creare runner paralleli: senza
         * lock una doppia pubblicazione social era possibile.
         */
        const PUBLISH_LOCK_PREFIX = 'nexus_publish_lock_';

        /**
         * v1.1.15 (WA7): TTL del mutex publish in secondi (120): copre il giro
         * completo del Publisher (immagini incluse); il rilascio NORMALE avviene
         * nel finally, il TTL rende il lock auto-guarigente dopo un crash duro.
         */
        const PUBLISH_LOCK_TTL = 120;

        /**
         * v1.1.18 (CR-1): età massima (minuti) di una run agent_runs in stato
         * 'running' prima che il reaper di cron_tick la chiuda come fantasma
         * (crash/timeout/OOM: il finally di run_full_agent non è potuto girare).
         * Generosa rispetto alla durata reale dei run (fasi con budget 90s e
         * timeout AI 60s); il Wire vivo rinfresca started_at a ogni fase e non
         * viene mai toccato. Regolabile con il filtro
         * 'nexus_pipeline_run_reaper_minutes' (0 = reaper disattivato).
         */
        const RUN_STALE_MINUTES = 30;

        /**
         * v1.1.18 (CR-1): TTL in secondi delle voci della option runtime
         * (nexus_agent_runtime, campo 'since' ISO8601 UTC). Le voci più vecchie
         * sono vittime di un crash (nessun clear_runtime): la busy map di
         * resume_stalled le IGNORA (l'articolo torna riprendibile) e il reaper
         * del tick le rimuove. Regolabile con il filtro
         * 'nexus_pipeline_runtime_ttl' (0 = TTL disattivato: comportamento v1.1.17).
         */
        const RUNTIME_STALE_SECONDS = 1800;

        /**
         * v1.1.18 (AL-6): backoff in secondi prima di riattivare automaticamente
         * un agente in status 'error' (v1.1.16: il cron non ripassava MAI un
         * agente in errore — un outage AI di 5 minuti fermava la redazione fino
         * a intervento umano). Il timestamp dell'errore è agents.updated_at,
         * scritto da update_agent nella transizione di finish_agent_run_row.
         * Regolabile con il filtro 'nexus_pipeline_agent_error_backoff'
         * (0 = auto-retry disattivato: comportamento v1.1.17).
         */
        const AGENT_ERROR_BACKOFF_SECONDS = 1800;

        /**
         * v1.1.18 (AL-7): prefisso del mutex per-tema del Nexus Wire. Un secondo
         * wire_run sullo stesso tema (doppio click su "assegna", richiesta REST
         * concorrente, requeue del reaper coda) non gira in parallelo: niente
         * doppio costo AI né articolo duplicato.
         */
        const WIRE_LOCK_PREFIX = 'nexus_wire_lock_';

        /**
         * v1.1.18 (AL-7): TTL del mutex Wire in secondi (900 = 15 min, 4+ chiamate
         * AI in serie con retry lato motore). Il rilascio NORMALE avviene nel
         * finally; il TTL rende il lock auto-guarigente dopo un crash duro. Il
         * requeue_stale della coda (30 min) arriva sempre DOPO la scadenza del
         * lock: un Wire morto per crash non fa collisioni al re-dispatch.
         */
        const WIRE_LOCK_TTL = 900;

        /**
         * FASE 1 — Raccolta notizie per un agente.
         *
         * v1.1.16 (WD1b): chiavi additive nel ritorno per la telemetria della
         * run (Nexus_DB::finish_agent_run): 'candidates' (proposte AI del
         * collect), 'sources_ok' (fonti che hanno contribuito almeno un item)
         * e 'sources_err' (0: il contratto attuale di fetch_for_agent non
         * espone le fonti fallite — l'attribuzione degli errori per-fonte vive
         * nel log attività 'source_error', v1.1.16 WD2).
         *
         * @param int $agent_id ID agente.
         * @return array{ok:bool, collected:int, errors:array, article_ids:array, skipped?:bool, candidates:int, sources_ok:int, sources_err:int}
         */
        public static function collect( $agent_id ) {
                $out = array(
                        'ok'          => false,
                        'collected'   => 0,
                        'errors'      => array(),
                        'article_ids' => array(),
                        'candidates'  => 0,
                        'sources_ok'  => 0,
                        'sources_err' => 0,
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
                // v1.1.15 (WA6): try/finally con clear_runtime — le uscite anticipate
                // (budget esaurito, motore AI non disponibile, eccezione) non lasciano
                // più l'agente "In redazione ora" fintamente quando collect gira come
                // job di coda standalone (il runtime vive SOLO per la durata della fase).
                self::set_runtime( (int) $agent->id, 'collect', 'Raccoglie le fonti' );

                try {
                        return self::collect_run( $out, $agent );
                } finally {
                        self::clear_runtime( (int) $agent->id );
                }
        }

        /**
         * Corpo della FASE 1 (raccolta), eseguito dentro il try/finally del runtime
         * agente (WA6). Riceve l'esito parziale e l'agente già verificato attivo;
         * ritorna l'esito completo.
         *
         * @param array  $out   Esito parziale da riempire.
         * @param object $agent Agente attivo.
         * @return array{ok:bool, collected:int, errors:array, article_ids:array, skipped?:bool}
         */
        private static function collect_run( $out, $agent ) {
                self::heartbeat(); // v1.1.15 (WA2): job di coda vivo, started_at fresco.

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

                // v1.1.16 (WD1b): telemetria per-fonte del collect, contata SOLO
                // dai dati che il dispatcher produce GIÀ (gli item ritornati): una
                // fonte è "ok" se ha contribuito almeno un item sopravvissuto a
                // merge/dedup/cap. Il numero di fonti in errore NON è deducibile
                // da questo contratto (fetch_for_agent ritorna solo gli item
                // vivi): resta 0 e gli errori per-fonte restano attribuiti nel
                // log attività 'source_error' dall'agente in esecuzione (WD2).
                $sources_ok_map = array();
                foreach ( $items as $item ) {
                        $src = self::item_field( $item, 'source' );
                        if ( '' === $src ) {
                                $src = 'RSS';
                        }
                        $sources_ok_map[ $src ] = true;
                }
                $out['sources_ok'] = count( $sources_ok_map );

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
                        $out['candidates'] = count( $candidates ); // v1.1.16 (WD1b): telemetria run.
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
                        $out['candidates'] = count( $candidates ); // v1.1.16 (WD1b): telemetria run.
                }

                /* ---- FASE 1b: filtra i candidati (max 2, score >= 50) ---- */
                $kept = array();
                foreach ( $candidates as $candidate ) {
                        // v1.1.13 (O1): default 0 (era 70) — un punteggio non fornito dalla
                        // risposta AI non deve poter auto-approvare l'articolo. L'evaluate
                        // reale (FASE 2) sovrascrive quality_score; finché non c'è una
                        // valutazione parsata l'auto-approvazione resta bloccata.
                        $score = 0;
                        if ( isset( $candidate['score'] ) && is_numeric( $candidate['score'] ) ) {
                                $score = max( 0, min( 100, (int) round( (float) $candidate['score'] ) ) );
                        }
                        // Evergreen non ha punteggio dalla selezione: nasce a 0, lo score
                        // reale arriva dalla valutazione. I candidati RSS senza punteggio
                        // (o con score < 50) vengono scartati qui.
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
         * v1.1.13 (O1): 'score_parsed' = false quando la risposta AI non contiene
         * un punteggio interpretabile (score salvato 0): in quel caso l'articolo
         * NON si auto-approva mai (serve revisione umana, log onesto del motivo).
         *
         * @param int $article_id ID articolo.
         * @return array{ok:bool, score:int, score_parsed:bool, error:string}
         */
        public static function evaluate( $article_id ) {
                $out = array(
                        'ok'           => false,
                        'score'        => 0,
                        'score_parsed' => false,
                        'error'        => '',
                );

                $article = Nexus_DB::get_article( $article_id );
                if ( ! $article ) {
                        $out['error'] = 'Articolo non trovato.';
                        return $out;
                }

                // v1.1.5: stato runtime per il pannello live (fase di valutazione).
                // v1.1.15 (WA6): try/finally con clear_runtime — vedi collect(): anche
                // le uscite anticipate di evaluate (budget, AI fallita, eccezione) non
                // lasciano più lo stato runtime appeso per un job di coda standalone.
                self::set_runtime( (int) $article->agent_id, 'evaluate', 'Valuta i materiali', (int) $article_id, (string) $article->title );

                try {
                        return self::evaluate_run( $out, $article, $article_id );
                } finally {
                        self::clear_runtime( (int) $article->agent_id );
                }
        }

        /**
         * Corpo della FASE 2 (valutazione), eseguito dentro il try/finally del
         * runtime agente (WA6).
         *
         * @param array  $out        Esito parziale da riempire.
         * @param object $article    Articolo esistente.
         * @param int    $article_id ID articolo.
         * @return array{ok:bool, score:int, score_parsed:bool, error:string}
         */
        private static function evaluate_run( $out, $article, $article_id ) {
                $category = '' !== (string) $article->category ? (string) $article->category : self::agent_category( $article->agent_id );

                self::heartbeat(); // v1.1.15 (WA2): job di coda vivo, started_at fresco.

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

                $parsed_score = self::parse_score( (string) $res['content'] );
                $score        = (int) $parsed_score['score'];
                Nexus_DB::update_article( $article_id, array( 'quality_score' => $score ) );
                self::touch_agent( (int) $article->agent_id );

                // v1.1.13 (O1): log onesto — risposta AI illeggibile = NESSUNA valutazione
                // reale: punteggio 0 e auto-approvazione bloccata (revisione umana).
                if ( empty( $parsed_score['parsed'] ) ) {
                        self::log_phase(
                                (int) $article->agent_id,
                                'evaluating',
                                sprintf( 'Valutazione articolo #%d: risposta AI non interpretabile, punteggio non ricavabile (0/100). Auto-approvazione bloccata: serve revisione umana.', $article_id ),
                                'warning',
                                'pending'
                        );
                } else {
                        self::log_phase(
                                (int) $article->agent_id,
                                'evaluating',
                                sprintf( 'Articolo #%d "%s" valutato: punteggio %d/100.', $article_id, self::clip( $article->title, 60 ), $score ),
                                'success'
                        );
                }

                $out['ok']           = true;
                $out['score']        = $score;
                $out['score_parsed'] = ! empty( $parsed_score['parsed'] );
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

                // v1.1.5: stato runtime per il pannello live (fase di scrittura).
                // v1.1.15 (WA6): try/finally con clear_runtime — vedi collect(): anche
                // le uscite anticipate di rewrite (budget, AI fallita, eccezione) non
                // lasciano più lo stato runtime appeso per un job di coda standalone.
                self::set_runtime( (int) $article->agent_id, 'rewrite', 'Scrive l\'articolo', (int) $article_id, (string) $article->title );

                try {
                        return self::rewrite_run( $out, $article, $article_id );
                } finally {
                        self::clear_runtime( (int) $article->agent_id );
                }
        }

        /**
         * Corpo della FASE 3 (riscrittura), eseguito dentro il try/finally del
         * runtime agente (WA6).
         *
         * @param array  $out        Esito parziale da riempire.
         * @param object $article    Articolo esistente.
         * @param int    $article_id ID articolo.
         * @return array{ok:bool, error:string}
         */
        private static function rewrite_run( $out, $article, $article_id ) {
                $agent    = $article->agent_id ? Nexus_DB::get_agent( $article->agent_id ) : null;
                $category = '' !== (string) $article->category ? (string) $article->category : self::agent_category( $article->agent_id );

                self::heartbeat(); // v1.1.15 (WA2): job di coda vivo, started_at fresco.

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

                // v1.1.18 (AL-11): marker 'truncated' del motore (contratto chat
                // v1.1.9/v1.1.17: chiave additiva quando l'ULTIMA risposta resta
                // troncata per max_tokens, finish_reason=length). Risposta troncata =
                // contenuto INCOMPLETO: fallimento ONESTO — l'articolo NON viene
                // aggiornato né marcato completo (prima il testo mozzato veniva
                // salvato in silenzio e messo in coda di approvazione: audit A2-A3).
                // Resta nel suo stato: il resume/tick successivo ritenta con il
                // budget max_tokens pieno.
                if ( ! empty( $res['truncated'] ) ) {
                        $out['error'] = 'Riscrittura troncata: la risposta AI è stata interrotta per max_tokens esauriti e il contenuto incompleto NON è stato salvato. Aumenta max_tokens o scegli un modello con più contesto.';
                        self::log_phase( (int) $article->agent_id, 'rewriting', sprintf( 'Riscrittura articolo #%d interrotta: risposta AI troncata per max_tokens (contenuto incompleto non salvato, articolo non marcato completo).', $article_id ), 'error' );
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
         * v1.1.15 (WA7) — MUTEX PUBLISH PER-ARTICOLO: publish() è raggiunto da 4
         * percorsi concorrenti (REST, CLI, capo redattore, coda) e requeue_stale
         * può creare runner paralleli: senza lock una doppia pubblicazione social
         * era possibile. Acquisizione ATOMICA del lock (add_option fallisce se la
         * chiave esiste già, v. acquire_lock), TTL PUBLISH_LOCK_TTL con auto-guarigione
         * sui lock scaduti (ruba-lock), ri-check di OGNI condizione di stato DENTRO
         * il lock (publish_locked) e rilascio in finally. Lock occupato → esito
         * onesto 'già in corso' (nessuna attesa, nessuna doppia spesa).
         * Il rilascio del lock è in finally insieme a clear_runtime (v1.1.15 WA6
         * per estensione: anche publish poteva lasciare lo stato runtime "finto"
         * quando eseguito come job di coda standalone).
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

                $lock_key = self::PUBLISH_LOCK_PREFIX . absint( $article_id );

                if ( ! self::acquire_lock( $lock_key, self::PUBLISH_LOCK_TTL ) ) {
                        $out['error']   = 'Pubblicazione già in corso per questo articolo: riprova più tardi.';
                        $out['message'] = 'Pubblicazione già in corso.';
                        return $out;
                }

                try {
                        return self::publish_locked( $out, (int) $article_id, $mode );
                } finally {
                        self::release_lock( $lock_key );
                        self::clear_runtime( (int) $article->agent_id );
                }
        }

        /**
         * Corpo della FASE 5, eseguito DENTRO il mutex per-articolo (WA7).
         *
         * TUTTI i controlli di stato sono ridettetti qui sulla riga fresca: tra il
         * rilascio del lock precedente e la nostra acquisizione un altro percorso
         * (REST/CLI/capo/coda) può aver già completato la pubblicazione. Il
         * ri-check "published → already" dentro il lock è la vera chiusura della
         * doppia pubblicazione.
         *
         * @param array  $out        Esito parziale da riempire.
         * @param int    $article_id ID articolo.
         * @param string $mode       Modalità corrente ('semi_autonomous'|'fully_autonomous').
         * @return array{ok:bool, logs:array, error:string, already:bool, message:string}
         */
        private static function publish_locked( $out, $article_id, $mode ) {
                $article = Nexus_DB::get_article( $article_id );
                if ( ! $article ) {
                        $out['error'] = 'Articolo non trovato.';
                        return $out;
                }

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
                                self::log_phase( (int) $article->agent_id, 'evaluating', sprintf( 'Articolo #%d in attesa del capo redattore: auto-approvazione saltata, resta in coda di revisione.', $article_id ), 'info', 'pending' );
                                return $out;
                        }

                        Nexus_DB::set_approval( $article_id, 'approved', 'Approvazione automatica prima della pubblicazione (modalità fully autonomous).' );
                } else {
                        $out['error'] = sprintf( 'L\'articolo #%d non è pubblicabile nello stato attuale (%s): serve lo stato "approved".', $article_id, $article->status );
                        return $out;
                }

                // v1.1.5: stato runtime per il pannello live (fase di pubblicazione).
                self::set_runtime( (int) $article->agent_id, 'publish', 'Pubblica', (int) $article_id, (string) $article->title );
                self::heartbeat(); // v1.1.15 (WA2): job di coda vivo, started_at fresco.

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
         * v1.1.15 (WA7/WA11) — acquisizione ATOMICA di un lock a transient:
         * add_option('_transient_<key>', timestamp) è un check-and-set atomico
         * (fallisce se l'opzione esiste già: la INSERT su chiave duplicata di
         * wpdb ritorna false). Il valore è il timestamp di acquisizione: un lock
         * più vecchio del TTL viene considerato orfano di un crash duro (il
         * finally non è potuto girare) e viene RUBATO (delete + re-add); i lock
         * legacy di set_transient (con opzione _transient_timeout_*) scaduti
         * vengono gestiti dalla stessa regola. Il timeout non usa
         * _transient_timeout_: la scadenza è gestita qui, così add_option resta
         * l'unico primitivo di acquisizione.
         *
         * NOTA onesta: su installazioni con object cache esterna il lock resta
         * best-effort (come ogni mutex transient di WordPress); la ri-verifica
         * di stato DENTRO il lock (publish_locked) è la protezione vera.
         *
         * @param string $key Chiave del lock (senza prefisso _transient_).
         * @param int    $ttl TTL in secondi oltre il quale il lock è rubabile.
         * @return bool True se il lock è stato acquisito.
         */
        private static function acquire_lock( $key, $ttl ) {
                $option = '_transient_' . $key;
                $now    = time();
                $held   = get_option( $option, false );

                if ( false !== $held ) {
                        // Lock presente: è orfano se il suo timestamp è più vecchio del TTL.
                        if ( is_numeric( $held ) && ( $now - (int) $held ) > (int) $ttl ) {
                                self::release_lock( $key );
                        } else {
                                return false;
                        }
                }

                // Atomico: ritorna false se l'opzione esiste già (persa la corsa).
                return (bool) add_option( $option, $now, '', 'no' );
        }

        /**
         * v1.1.15 (WA7/WA11) — rilascio del lock (idempotente, pulisce anche l'
         * eventuale opzione _transient_timeout_* lasciata da lock legacy).
         *
         * @param string $key Chiave del lock (senza prefisso _transient_).
         * @return void
         */
        private static function release_lock( $key ) {
                delete_option( '_transient_' . $key );
                delete_option( '_transient_timeout_' . $key );
        }

        /**
         * Pipeline completa per un agente (facciata v1.1.5).
         *
         * Delega il corpo a run_full_agent e garantisce la pulizia dello stato
         * runtime con clear_runtime() in un blocco finally: a fine run (successo,
         * ritorno anticipato o eccezione propagata al chiamante) l'agente non
         * resta "in redazione" sul pannello live.
         *
         * v1.1.16 (WD1): parametro additivo in coda $triggered_by per etichettare
         * la run row (wp_nexus_agent_runs.trigger): default 'manual' — REST
         * POST /pipeline/run, CLI e job di coda pipeline_run_full continuano a
         * chiamare senza parametro; cron_tick() passa 'cron'. La struttura di
         * ritorno resta INVARIATA.
         *
         * @param int    $agent_id     ID agente.
         * @param string $triggered_by 'manual'|'cron' (v1.1.16, additivo).
         * @return array{ok:bool, collected:int, evaluated:int, rewritten:int, published:int, messages:array}
         */
        public static function run_full( $agent_id, $triggered_by = 'manual' ) {
                try {
                        return self::run_full_agent( $agent_id, $triggered_by );
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
         * v1.1.16 (WD1) — LIFECYCLE RUN: i gate che bloccano il run PRIMA che
         * parta (agente assente/non attivo, auto_collect spento, cap giornaliero
         * raggiunto) non lasciano NESSUNA run row; dal primo giro effettivo una
         * riga su wp_nexus_agent_runs viene aperta (Nexus_DB::insert_agent_run)
         * e chiusa in OGNI caso di uscita (try/catch/finally →
         * finish_agent_run_row) con status 'ok' (flusso completo senza errori),
         * 'partial' (completo ma con errori in qualche fase) o 'failed'
         * (eccezione o raccolta fallita). L'eccezione viene RILANCIATA: il
         * contratto verso i chiamanti (cron_tick logga per agente, run_full
         * propaga al REST/CLI) resta invariato.
         *
         * @param int    $agent_id     ID agente.
         * @param string $triggered_by 'cron'|'manual'|'wire' (default 'cron' per BC, v1.1.16).
         * @return array{ok:bool, collected:int, evaluated:int, rewritten:int, published:int, messages:array}
         */
        private static function run_full_agent( $agent_id, $triggered_by = 'cron' ) {
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

                // v1.1.15 (WA10): qui serve SOLO il conteggio 'today' — niente
                // Nexus_DB::stats() (10 query, 5 full-scan su LONGTEXT per OGNI agente
                // a OGNI tick): 1 query diretta sulla colonna created_at (indice della
                // migration schema v7), STESSA semantica di stats() per 'today'
                // (inizio del giorno locale da current_time, v. Nexus_DB::stats()).
                global $wpdb;
                $today_start = substr( current_time( 'mysql' ), 0, 10 ) . ' 00:00:00';
                $today_table = Nexus_DB::tables()['articles'];
                $today       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$today_table} WHERE created_at >= %s", $today_start ) );
                if ( $today >= $max_per_day ) {
                        $summary['ok']         = true;
                        $summary['messages'][] = sprintf( 'Limite giornaliero raggiunto (%d/%d articoli oggi): pipeline non avviata.', $today, $max_per_day );
                        return $summary;
                }

                /* ---- v1.1.16 (WD1): il run PARTE qui — apertura della run row ----
                 * Guard method_exists difensiva (WB-DB lavora in parallelo alla
                 * pipeline): se i metodi agent_runs non esistono ancora il run
                 * prosegue identico ma senza traccia (nessun fatal). $run raccoglie
                 * la telemetria che le fasi producono; finish_agent_run_row() chiude
                 * la riga nel finally, in OGNI caso di uscita. */
                $run_id = 0;
                if ( method_exists( 'Nexus_DB', 'insert_agent_run' ) ) {
                        $run_id = (int) Nexus_DB::insert_agent_run( (int) $agent->id, self::run_trigger( $triggered_by ) );
                }

                $run = array(
                        // Default più onesto: solo le uscite grazie lo sovrascrivono
                        // ('ok'/'partial'); un'uscita imprevista resta 'failed'.
                        'status'      => 'failed',
                        'error'       => '',
                        'steps'       => array(),
                        'phase_error' => false,
                        'budget_stop' => false,
                        'articles'    => 0,
                        'candidates'  => 0,
                        'sources_ok'  => 0,
                        'sources_err' => 0,
                );

                try {
                        $summary = self::run_full_phases( $summary, $agent, $settings, $mode, $run );
                } catch ( Throwable $e ) {
                        $run['status'] = 'failed';
                        $run['error']  = self::clip( $e->getMessage(), 300 );
                        throw $e; // Contratto esistente: l'eccezione resta al chiamante (cron_tick la logga per agente).
                } finally {
                        self::finish_agent_run_row( $run_id, (int) $agent->id, $summary, $run );
                }

                return $summary;
        }

        /**
         * Corpo delle FASI 1-5 del run completo (estratto da run_full_agent, stesso
         * pattern WA6 di collect_run/evaluate_run/rewrite_run/publish_locked).
         *
         * v1.1.16 (WD1): riceve $run PER RIFERIMENTO e lo alimenta con la
         * telemetria che le fasi GIÀ producono (steps {step, ok, detail, ms} per
         * collect/evaluate/rewrite/publish, contatori della run, ultimo errore):
         * anche su eccezione la telemetria parziale resta visibile al finally del
         * chiamante. Messaggi e struttura della pipeline INVARIATI.
         *
         * @param array  $summary  Esito parziale da riempire (ritornato).
         * @param object $agent    Agente attivo.
         * @param array  $settings Settings del plugin.
         * @param string $mode     Modalità redazionale.
         * @param array  $run      Telemetria della run (per riferimento).
         * @return array{ok:bool, collected:int, evaluated:int, rewritten:int, published:int, messages:array}
         */
        private static function run_full_phases( $summary, $agent, $settings, $mode, &$run ) {
                /* ---- FASE 1: raccolta ---- */
                $step_t  = microtime( true );
                $collect = self::collect( (int) $agent->id );
                $step_ms = (int) round( ( microtime( true ) - $step_t ) * 1000 );

                // v1.1.16 (WD1): contatori della run letti dal ritorno di collect()
                // (chiavi additive WD1b) — impostati SUBITO per sopravvivere a un'
                // eccezione nelle fasi successive (finish_agent_run_row li legge).
                $run['articles']    = (int) ( isset( $collect['collected'] ) ? $collect['collected'] : 0 );
                $run['candidates']  = (int) ( isset( $collect['candidates'] ) ? $collect['candidates'] : 0 );
                $run['sources_ok']  = (int) ( isset( $collect['sources_ok'] ) ? $collect['sources_ok'] : 0 );
                $run['sources_err'] = (int) ( isset( $collect['sources_err'] ) ? $collect['sources_err'] : 0 );

                $collect_errors = (array) ( isset( $collect['errors'] ) ? $collect['errors'] : array() );
                foreach ( $collect_errors as $collect_error ) {
                        $summary['messages'][] = 'Raccolta: ' . $collect_error;
                }

                if ( ! empty( $collect['skipped'] ) ) {
                        // v1.1.16 (WD1): uscita graziosa (agente disattivato a metà run): nessun errore.
                        $run['status']         = 'ok';
                        $summary['ok']         = true;
                        $summary['messages'][] = 'Agente non attivo: pipeline saltata.';
                        return $summary;
                }

                // v1.1.16 (WD1): step collect (dettaglio: ultimo errore o conteggi).
                $run['steps'][] = array(
                        'step'   => 'collect',
                        'ok'     => ! empty( $collect['ok'] ),
                        'detail' => self::clip( ! empty( $collect_errors ) ? (string) end( $collect_errors ) : sprintf( '%d candidati, %d articoli creati', $run['candidates'], $run['articles'] ), 200 ),
                        'ms'     => $step_ms,
                );

                if ( empty( $collect['ok'] ) ) {
                        // v1.1.16 (WD1): run 'failed'. Se gli UNICI errori sono di budget
                        // mensile (budget_action 'stop' in budget_allows) la condizione è
                        // economica, non un guasto dell'agente: budget_stop=true esclude la
                        // transizione a agents.status='error' (che disattiverebbe l'agente
                        // nel cron fino al cambio mese, vedi finish_agent_run_row).
                        $budget_stop = ! empty( $collect_errors );
                        foreach ( $collect_errors as $collect_error ) {
                                if ( false === strpos( (string) $collect_error, 'budget mensile esaurito' ) ) {
                                        $budget_stop = false;
                                        break;
                                }
                        }
                        $run['budget_stop']    = $budget_stop;
                        $run['status']         = 'failed';
                        $run['error']          = self::clip( ! empty( $collect_errors ) ? (string) end( $collect_errors ) : 'Raccolta fallita.', 300 );
                        $summary['messages'][] = 'Raccolta fallita: pipeline interrotta.';
                        return $summary;
                }

                $summary['collected'] = (int) ( isset( $collect['collected'] ) ? $collect['collected'] : 0 );
                if ( $summary['collected'] < 1 ) {
                        // v1.1.16 (WD1): run 'ok' — uscita normale senza candidati (non è un errore).
                        $run['status']         = 'ok';
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

                        // v1.1.13 (O1): true SOLO se la valutazione ha prodotto un punteggio
                        // parsato dalla risposta AI. Valutazione assente (toggle off o budget
                        // esaurito) o risposta illeggibile → auto-approvazione bloccata sotto.
                        $score_parsed = false;

                        // FASE 2: valutazione.
                        if ( ! empty( $settings['auto_evaluate'] )
                                && self::article_time_available( $article_id, (int) $article->agent_id, $article_started_at, $phase_budget, 'valutazione' ) ) {
                                $step_t     = microtime( true );
                                $evaluation = self::evaluate( $article_id );
                                $step_ms    = (int) round( ( microtime( true ) - $step_t ) * 1000 );
                                if ( ! empty( $evaluation['ok'] ) ) {
                                        $summary['evaluated']++;
                                        $score_parsed = ! empty( $evaluation['score_parsed'] );
                                        if ( $score_parsed ) {
                                                $summary['messages'][] = sprintf( 'Articolo #%d valutato: punteggio %d/100.', $article_id, (int) $evaluation['score'] );
                                        } else {
                                                $summary['messages'][] = sprintf( 'Articolo #%d: punteggio AI non interpretabile (0/100): auto-approvazione bloccata, serve revisione umana.', $article_id );
                                        }
                                        // v1.1.16 (WD1): step evaluate (dettaglio = messaggio di fase appena emesso).
                                        $run['steps'][] = array(
                                                'step'   => 'evaluate',
                                                'ok'     => true,
                                                'detail' => self::clip( (string) end( $summary['messages'] ), 200 ),
                                                'ms'     => $step_ms,
                                        );
                                        $article = Nexus_DB::get_article( $article_id );
                                } else {
                                        $summary['messages'][] = sprintf( 'Valutazione articolo #%d fallita: %s', $article_id, $evaluation['error'] );
                                        // v1.1.16 (WD1): fase fallita → run 'partial' + ultimo errore.
                                        $run['phase_error'] = true;
                                        $run['error']       = self::clip( (string) ( isset( $evaluation['error'] ) ? $evaluation['error'] : '' ), 300 );
                                        $run['steps'][]     = array(
                                                'step'   => 'evaluate',
                                                'ok'     => false,
                                                'detail' => self::clip( (string) end( $summary['messages'] ), 200 ),
                                                'ms'     => $step_ms,
                                        );
                                }
                        }

                        // FASE 3: riscrittura.
                        $rewritten_now = false;
                        if ( ! empty( $settings['auto_rewrite'] )
                                && self::article_time_available( $article_id, (int) $article->agent_id, $article_started_at, $phase_budget, 'riscrittura' ) ) {
                                $step_t    = microtime( true );
                                $rewriting = self::rewrite( $article_id );
                                $step_ms   = (int) round( ( microtime( true ) - $step_t ) * 1000 );
                                if ( ! empty( $rewriting['ok'] ) ) {
                                        $summary['rewritten']++;
                                        $rewritten_now = true;
                                        $summary['messages'][] = sprintf( 'Articolo #%d riscritto e messo in coda di approvazione.', $article_id );
                                        // v1.1.16 (WD1): step rewrite (la riscrittura è riuscita; la
                                        // eventuale rimessa in bozza del fact-check resta nei messaggi).
                                        $run['steps'][] = array(
                                                'step'   => 'rewrite',
                                                'ok'     => true,
                                                'detail' => self::clip( (string) end( $summary['messages'] ), 200 ),
                                                'ms'     => $step_ms,
                                        );
                                        $article = Nexus_DB::get_article( $article_id );

                                        // Fact-check sotto soglia (v1.0.3): l'articolo è tornato in bozza,
                                        // nessuna approvazione/pubblicazione automatica in questo tick.
                                        if ( $article && 'draft' === $article->status ) {
                                                $summary['messages'][] = sprintf( 'Articolo #%d rimesso in bozza dal fact-check: serve revisione umana.', $article_id );
                                                continue;
                                        }
                                } else {
                                        $summary['messages'][] = sprintf( 'Riscrittura articolo #%d fallita: %s', $article_id, $rewriting['error'] );
                                        // v1.1.16 (WD1): fase fallita → run 'partial' + ultimo errore.
                                        $run['phase_error'] = true;
                                        $run['error']       = self::clip( (string) ( isset( $rewriting['error'] ) ? $rewriting['error'] : '' ), 300 );
                                        $run['steps'][]     = array(
                                                'step'   => 'rewrite',
                                                'ok'     => false,
                                                'detail' => self::clip( (string) end( $summary['messages'] ), 200 ),
                                                'ms'     => $step_ms,
                                        );
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
                        //
                        // v1.1.13 (O1): senza punteggio PARSTATO ($score_parsed false: valutazione
                        // mai eseguita o risposta AI illeggibile) l'articolo NON si auto-approva
                        // MAI, qualunque sia la soglia: va in attesa di revisione umana.
                        $chief_gate = $is_full_auto && self::awaiting_chief_review( $article );

                        if ( $rewritten_now && $is_full_auto && $score_parsed && $score >= $threshold && ! $chief_gate ) {
                                $approval = self::approve(
                                        $article_id,
                                        sprintf( 'Approvazione automatica: punteggio %.0f >= %d (modalità fully autonomous).', $score, $threshold )
                                );
                                if ( ! empty( $approval['ok'] ) ) {
                                        $summary['messages'][] = sprintf( 'Articolo #%d approvato automaticamente (punteggio %.0f >= %d).', $article_id, $score, $threshold );

                                        $step_t      = microtime( true );
                                        $publication = self::publish( $article_id );
                                        $step_ms     = (int) round( ( microtime( true ) - $step_t ) * 1000 );
                                        if ( ! empty( $publication['ok'] ) && empty( $publication['already'] ) ) {
                                                $summary['published']++;
                                                $summary['messages'][] = sprintf( 'Articolo #%d pubblicato automaticamente.', $article_id );
                                        } elseif ( ! empty( $publication['error'] ) ) {
                                                $summary['messages'][] = sprintf( 'Pubblicazione automatica articolo #%d fallita: %s', $article_id, $publication['error'] );
                                        }

                                        // v1.1.16 (WD1): step publish (contratto publish(): 'message'
                                        // a successo, 'error' a fallimento — anche con already=true).
                                        $run['steps'][] = array(
                                                'step'   => 'publish',
                                                'ok'     => ! empty( $publication['ok'] ) && empty( $publication['error'] ),
                                                'detail' => self::clip( (string) ( ! empty( $publication['error'] ) ? $publication['error'] : ( isset( $publication['message'] ) ? $publication['message'] : '' ) ), 200 ),
                                                'ms'     => $step_ms,
                                        );
                                        if ( ! empty( $publication['error'] ) ) {
                                                $run['phase_error'] = true;
                                                $run['error']       = self::clip( (string) $publication['error'], 300 );
                                        }
                                } else {
                                        $summary['messages'][] = sprintf( 'Approvazione automatica articolo #%d fallita: %s', $article_id, $approval['error'] );
                                        // v1.1.16 (WD1): la fase di pubblicazione non è mai arrivata
                                        // (approvazione fallita) → run 'partial' + ultimo errore.
                                        $run['phase_error'] = true;
                                        $run['error']       = self::clip( (string) ( isset( $approval['error'] ) ? $approval['error'] : '' ), 300 );
                                }
                        } elseif ( $rewritten_now && $chief_gate ) {
                                $summary['messages'][] = sprintf( 'Articolo #%d in attesa del capo redattore: auto-approvazione e pubblicazione automatiche saltate.', $article_id );
                                self::log_phase( (int) $article->agent_id, 'evaluating', sprintf( 'Articolo #%d in attesa del capo redattore: auto-approvazione saltata (modalità fully autonomous).', $article_id ), 'info', 'pending' );
                        } elseif ( $rewritten_now && $is_full_auto && ! $score_parsed ) {
                                // v1.1.13 (O1): log onesto del motivo — nessuna valutazione reale
                                // disponibile, l'articolo resta in coda per un revisore umano.
                                $summary['messages'][] = sprintf( 'Articolo #%d in coda di revisione umana: punteggio AI assente o non interpretabile, auto-approvazione bloccata.', $article_id );
                                self::log_phase( (int) $article->agent_id, 'evaluating', sprintf( 'Articolo #%d: punteggio AI assente o non interpretabile: auto-approvazione bloccata, richiesta revisione umana.', $article_id ), 'info', 'pending' );
                        } elseif ( $rewritten_now && $is_full_auto ) {
                                $summary['messages'][] = sprintf( 'Articolo #%d in coda di revisione: punteggio %.0f sotto la soglia di auto-approvazione (%d).', $article_id, $score, $threshold );
                        } elseif ( $rewritten_now && ! empty( $settings['auto_publish'] ) && 'approved' === $article->status ) {
                                // semi_autonomous con auto_publish attivo: pubblica solo ciò che risulta già approvato.
                                $step_t      = microtime( true );
                                $publication = self::publish( $article_id );
                                $step_ms     = (int) round( ( microtime( true ) - $step_t ) * 1000 );
                                if ( ! empty( $publication['ok'] ) && empty( $publication['already'] ) ) {
                                        $summary['published']++;
                                        $summary['messages'][] = sprintf( 'Articolo #%d pubblicato (auto_publish attivo).', $article_id );
                                }

                                // v1.1.16 (WD1): step publish anche nel percorso semi_autonomous
                                // (l'errore qui prima era silenzioso: ora entra nella run row).
                                $run['steps'][] = array(
                                        'step'   => 'publish',
                                        'ok'     => ! empty( $publication['ok'] ) && empty( $publication['error'] ),
                                        'detail' => self::clip( (string) ( ! empty( $publication['error'] ) ? $publication['error'] : ( isset( $publication['message'] ) ? $publication['message'] : '' ) ), 200 ),
                                        'ms'     => $step_ms,
                                );
                                if ( ! empty( $publication['error'] ) ) {
                                        $run['phase_error'] = true;
                                        $run['error']       = self::clip( (string) $publication['error'], 300 );
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

                // v1.1.16 (WD1): esito della run — 'partial' se qualche fase è
                // fallita (o la raccolta è uscita con errori), 'ok' altrimenti.
                $run['status'] = ( ! empty( $run['phase_error'] ) || ! empty( $collect_errors ) ) ? 'partial' : 'ok';

                $summary['ok'] = true;
                return $summary;
        }

        /**
         * Etichetta della run normalizzata sui valori del contratto DB (WB-DB):
         * 'cron' | 'manual' | 'wire' (qualunque altro valore → 'cron').
         * v1.1.18 (AL-7): 'wire' non è più un valore morto — wire_run apre la sua
         * run row con trigger 'wire' (audit A4 🟢14 chiuso).
         *
         * @param string $triggered_by Valore passato dal chiamante.
         * @return string
         */
        private static function run_trigger( $triggered_by ) {
                $triggered_by = strtolower( trim( (string) $triggered_by ) );

                return in_array( $triggered_by, array( 'cron', 'manual', 'wire' ), true ) ? $triggered_by : 'cron';
        }

        /**
         * Chiusura della run row (v1.1.16 WD1): chiamata nel finally di
         * run_full_agent, in OGNI caso di uscita del run.
         *
         * - finish_agent_run(): status + steps + contatori + ultimo errore.
         * - Stato errore vivo: run 'failed' → agents.status='error' (escluso lo
         *   stop per budget mensile: condizione economica già loggata come
         *   attività 'budget', non un guasto — vedi run_full_phases); run
         *   'ok'/'partial' riporta 'error' → 'active' SOLO se lo status corrente
         *   è già 'error' (mai toccati 'paused' e altri stati scelti dall'utente:
         *   cron_tick esegue solo gli 'active', pipeline.php:224-228/1088).
         *   v1.1.18 (AL-6): il TIMESTAMP della registrazione errore è la colonna
         *   agents.updated_at (update_agent la scrive a OGNI transizione, quindi
         *   anche qui quando status passa a 'error'): è la base del backoff di
         *   auto-riattivazione di reactivate_error_agents() nel tick.
         *
         * Guard method_exists difensive (WB-DB lavora in parallelo) e try/catch
         * totali: la telemetria non deve MAI mascherare l'esito del run.
         *
         * @param int    $run_id  ID della run row (0 = non registrata).
         * @param int    $agent_id ID agente.
         * @param array  $summary Esito della pipeline.
         * @param array  $run     Telemetria della run (steps, contatori, errori).
         * @return void
         */
        private static function finish_agent_run_row( $run_id, $agent_id, $summary, $run ) {
                $status = (string) ( isset( $run['status'] ) ? $run['status'] : 'failed' );
                if ( ! in_array( $status, array( 'ok', 'partial', 'failed' ), true ) ) {
                        $status = 'failed';
                }

                try {
                        if ( $run_id > 0 && method_exists( 'Nexus_DB', 'finish_agent_run' ) ) {
                                Nexus_DB::finish_agent_run(
                                        (int) $run_id,
                                        $status,
                                        array(
                                                'steps'            => isset( $run['steps'] ) && is_array( $run['steps'] ) ? $run['steps'] : array(),
                                                'articles_created' => isset( $run['articles'] ) ? (int) $run['articles'] : (int) ( isset( $summary['collected'] ) ? $summary['collected'] : 0 ),
                                                'candidates'       => (int) ( isset( $run['candidates'] ) ? $run['candidates'] : 0 ),
                                                'sources_ok'       => (int) ( isset( $run['sources_ok'] ) ? $run['sources_ok'] : 0 ),
                                                'sources_err'      => (int) ( isset( $run['sources_err'] ) ? $run['sources_err'] : 0 ),
                                                'error'            => (string) ( isset( $run['error'] ) ? $run['error'] : '' ),
                                        )
                                );
                        }
                } catch ( Throwable $e ) {
                        // Silenzio onesto: la scrittura della run row non rompe il run.
                }

                try {
                        if ( 'failed' === $status && empty( $run['budget_stop'] ) ) {
                                Nexus_DB::update_agent( $agent_id, array( 'status' => 'error' ) );
                        } elseif ( 'failed' !== $status ) {
                                $fresh = Nexus_DB::get_agent( $agent_id );
                                if ( $fresh && 'error' === (string) $fresh->status ) {
                                        Nexus_DB::update_agent( $agent_id, array( 'status' => 'active' ) );
                                }
                        }
                } catch ( Throwable $e ) {
                        // Silenzio onesto: la transizione di stato non maschera l'esito.
                }
        }

        /**
         * Tick del cron: esegue run_full per ogni agente attivo, senza fatal error
         * (ogni agente è protetto da try/catch, come runAllAgents dell'originale).
         *
         * v1.1.16 (WD1): le run degli agenti partono con triggered_by 'cron' —
         * le run rows di wp_nexus_agent_runs distinguono i giri automatici da
         * quelli manuali (run_full() → 'manual'). Contratto di ritorno INVARIATO.
         *
         * @return array{ok:bool, ran:int, results:array, timestamp:string}
         */
        public static function cron_tick() {
                $results = array();
                $ran     = 0;

                // v1.1.9 — Resume articoli bloccati ALL'INIZIO del tick (WP4): UN solo
                // batch per chiamata (limit interno, default 10) per non sovrapporsi al
                // lavoro degli agenti né mandare in loop il tick. Mai fatale: il giro
                // regolare degli agenti deve proseguire comunque. L'orologio del budget
                // tick (WA5) parte PRIMA del resume: è tempo del tick a tutti gli effetti.
                $tick_started = microtime( true );

                // v1.1.18 (CR-1) — REAPER RUN FANTASMA: le run agent_runs rimaste
                // 'running' da oltre RUN_STALE_MINUTES sono vittime di un crash/timeout
                // del processo (il finally di run_full_agent non è potuto girare):
                // senza chiusura restavano fantasmi eterni in telemetria (drawer,
                // diagnostica) e l'articolo in lavorazione restava escluso dal resume
                // PER SEMPRE via busy map. Chiusura PRIMA del resume, così le voci
                // runtime scadute che il reaper rimuove non bloccano più il recupero.
                try {
                        self::reap_ghost_runs();
                } catch ( Throwable $e ) {
                        self::log_phase( 0, 'rewriting', sprintf( 'Reaper run fantasma fallito: %s', self::clip( $e->getMessage(), 300 ) ), 'error' );
                }

                // v1.1.18 (CR-1) — runtime stuck resettato: le voci della option
                // nexus_agent_runtime più vecchie del TTL (campo 'since') sono
                // rimosse. Mai fatale: è pulizia del pannello live, il tick prosegue.
                try {
                        self::prune_runtime_stale();
                } catch ( Throwable $e ) {
                        // Silenzio onesto: la pulizia del runtime non deve rompere il tick.
                }

                try {
                        self::resume_stalled();
                } catch ( Throwable $e ) {
                        self::log_phase( 0, 'rewriting', sprintf( 'Resume articoli bloccati fallito: %s', self::clip( $e->getMessage(), 300 ) ), 'error' );
                }

                // v1.1.18 (AL-6) — auto-retry agenti 'error': riattivazione con backoff
                // PRIMA della costruzione della lista $active, così gli agenti
                // riattivati possono essere ripassati GIÀ in questo tick (probe onesta:
                // se l'AI è ancora giù la run fallisce e il backoff riparte da qui).
                try {
                        self::reactivate_error_agents();
                } catch ( Throwable $e ) {
                        // Silenzio onesto: l'auto-retry non deve mai rompere il tick.
                }

                // v1.1.15 (WA5) — budget di tempo GLOBALE per tick + cap agenti per
                // tick: prima gli agenti attivi giravano tutti IN SERIE senza tetto.
                // Il budget è verificato a inizio di OGNI iterazione agente; gli agenti
                // oltre il cap (o con budget esaurito) partono al prossimo giro —
                // rotazione last_run (sort_agents_by_last_run), zero starvation.
                // Il contratto di ritorno è INVARIATO: $results contiene solo gli
                // agenti effettivamente avviati (ok o in errore).
                $tick_budget = (float) apply_filters( 'nexus_pipeline_tick_budget', self::TICK_BUDGET_SECONDS );
                $agent_cap   = max( 1, (int) apply_filters( 'nexus_pipeline_tick_agent_cap', self::TICK_AGENT_CAP ) );

                $active = array();
                foreach ( (array) Nexus_DB::agents() as $agent ) {
                        if ( 'active' !== $agent->status ) {
                                continue;
                        }
                        $active[] = $agent;
                }

                // Rotazione onesta (solo se serve davvero): chi ha girato più di
                // recente lascia il posto a chi attende da più tempo.
                if ( count( $active ) > $agent_cap ) {
                        usort( $active, array( __CLASS__, 'sort_agents_by_last_run' ) );
                }

                $skipped_by_cap = 0;
                $skipped_budget = 0;

                foreach ( $active as $index => $agent ) {
                        if ( $index >= $agent_cap ) {
                                $skipped_by_cap++;
                                continue;
                        }

                        if ( $tick_budget > 0 && ( microtime( true ) - $tick_started ) >= $tick_budget ) {
                                $skipped_budget++;
                                continue;
                        }

                        try {
                                self::heartbeat(); // v1.1.15 (WA2): tick job vivo tra un agente e l'altro.
                                // v1.1.16 (WD1): run row con trigger 'cron' (run_full default 'manual').
                                $result         = self::run_full( (int) $agent->id, 'cron' );
                                $result['agent'] = (string) $agent->name;
                                $results[]      = $result;
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

                // v1.1.15 (WA5): nota ONESTA (una per tick) degli agenti rinviati al
                // prossimo giro — fuori da $results, che resta "agenti avviati".
                if ( $skipped_by_cap + $skipped_budget > 0 ) {
                        self::log_phase( 0, 'budget', sprintf( 'Tick: %d agenti attivi rinviati al prossimo giro (%d oltre il cap di %d agenti per tick, %d per budget di tempo %.0fs).', $skipped_by_cap + $skipped_budget, $skipped_by_cap, $agent_cap, $skipped_budget, $tick_budget ), 'info' );
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
         * v1.1.15 (WA5) — comparatore di rotazione per il cap agenti per tick:
         * prima gli agenti con last_run più vecchio (0 = mai girato o last_run
         * illeggibile), tie-break per id ASC (ordine stabile).
         *
         * @param object $a Primo agente.
         * @param object $b Secondo agente.
         * @return int
         */
        private static function sort_agents_by_last_run( $a, $b ) {
                $a_time = isset( $a->last_run ) && '' !== (string) $a->last_run ? strtotime( (string) $a->last_run ) : 0;
                $b_time = isset( $b->last_run ) && '' !== (string) $b->last_run ? strtotime( (string) $b->last_run ) : 0;

                if ( $a_time === $b_time ) {
                        return ( (int) $a->id < (int) $b->id ) ? -1 : 1;
                }

                return ( $a_time < $b_time ) ? -1 : 1;
        }

        /**
         * v1.1.18 (CR-1) — REAPER delle run fantasma: chiude le righe agent_runs
         * rimaste 'running' da oltre RUN_STALE_MINUTES (default 30).
         *
         * PERCHÉ ESISTE: il finally di run_full_agent copre solo le eccezioni PHP;
         * su fatal/max_execution_time/OOM/kill (classe di crash vissuta dal
         * plugin, changelog v1.1.15 WA5) la run row resta 'running' per sempre:
         * telemetria bugiarna (drawer/diagnostica mostrano run "in corso"
         * fantasma) e — combinato con la busy map senza TTL — articolo mai più
         * ripreso. Il reaper gira a inizio cron_tick (stessa cadenza del tick,
         * percorso con coda e inline).
         *
         * Chiusura ONESTA: status 'failed' (contratto tabella/PC1:
         * ok|failed|partial|running — il messaggio d'errore distingue la causa),
         * finished_at = adesso, duration_ms calcolata da started_at,
         * error='reaper: run fantasma (timeout)'. UPDATE condizionale su
         * (id, status='running'): se nel frattempo finish_agent_run ha chiuso la
         * riga con l'esito vero, il reaper non tocca nulla (0 righe = non vinto).
         * Il Wire vivo (trigger 'wire') rinfresca started_at a ogni fase con
         * touch_agent_run() e non viene mai chiuso.
         *
         * @return int Numero di run fantasma effettivamente chiuse.
         */
        private static function reap_ghost_runs() {
                global $wpdb;

                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'tables' ) ) {
                        return 0;
                }

                $minutes = (int) apply_filters( 'nexus_pipeline_run_reaper_minutes', self::RUN_STALE_MINUTES );
                if ( $minutes <= 0 ) {
                        return 0; // Filtro 0 = reaper disattivato (comportamento v1.1.17).
                }

                $tables = Nexus_DB::tables();
                if ( ! is_array( $tables ) || empty( $tables['agent_runs'] ) ) {
                        return 0; // Tabella non ancora migrata (schema < v8): niente da fare.
                }

                $table  = $tables['agent_runs'];
                $now    = current_time( 'mysql' );
                $now_ts = strtotime( $now );
                $cutoff = gmdate( 'Y-m-d H:i:s', $now_ts - $minutes * 60 );

                // phpcs:ignore WordPress.DB -- query mirata (stesso schema di requeue_stale):
                // solo id/started_at, cap 50 per passata, il resto al prossimo tick.
                $rows = $wpdb->get_results(
                        $wpdb->prepare(
                                "SELECT id, started_at FROM {$table}
                                        WHERE status = 'running' AND started_at IS NOT NULL AND started_at <> '' AND started_at < %s
                                        ORDER BY started_at ASC LIMIT 50",
                                $cutoff
                        ),
                        ARRAY_A
                );

                if ( ! is_array( $rows ) || empty( $rows ) ) {
                        return 0;
                }

                $closed = 0;

                foreach ( $rows as $row ) {
                        $run_id = absint( isset( $row['id'] ) ? $row['id'] : 0 );
                        if ( $run_id < 1 ) {
                                continue;
                        }

                        $started  = isset( $row['started_at'] ) ? strtotime( (string) $row['started_at'] ) : 0;
                        $duration = ( $started && $now_ts ) ? max( 0, (int) ( ( $now_ts - $started ) * 1000 ) ) : 0;

                        // UPDATE condizionale: vince solo chi chiude una riga ancora 'running'.
                        $done = $wpdb->update(
                                $table,
                                array(
                                        'status'      => 'failed',
                                        'finished_at' => $now,
                                        'duration_ms' => $duration,
                                        'error'       => 'reaper: run fantasma (timeout)',
                                ),
                                array(
                                        'id'     => $run_id,
                                        'status' => 'running',
                                ),
                                array( '%s', '%s', '%d', '%s' ),
                                array( '%d', '%s' )
                        );

                        if ( $done ) {
                                $closed++;
                        }
                }

                if ( $closed > 0 ) {
                        self::log_phase( 0, 'rewriting', sprintf( 'Reaper: chiuse %d run fantasma (stato "running" da oltre %d min: crash/timeout del processo, telemetria ripristinata).', $closed, $minutes ), 'warning' );
                }

                return $closed;
        }

        /**
         * v1.1.18 (CR-1) — TTL delle voci runtime: secondi oltre i quali una voce
         * della option nexus_agent_runtime è considerata morta (filtro
         * 'nexus_pipeline_runtime_ttl', 0 = disattivato).
         *
         * @return int
         */
        private static function runtime_stale_seconds() {
                $ttl = (int) apply_filters( 'nexus_pipeline_runtime_ttl', self::RUNTIME_STALE_SECONDS );
                return $ttl > 0 ? $ttl : 0;
        }

        /**
         * v1.1.18 (CR-1) — rimuove dalla option runtime le voci scadute ('since'
         * più vecchio del TTL, o corrotte/assenti: set_runtime scrive SEMPRE
         * 'since' ISO8601 UTC, quindi l'assenza è stato corrotto/legacy).
         *
         * Chiamata a inizio cron_tick: un crash a metà run non lascia più voci
         * fantasma "In redazione ora" nel pannello live (il filtro anti-stale del
         * /live le nascondeva già, ma la option cresceva senza fine e la busy map
         * del resume le considerava ancora attive — esclusione perpetua).
         *
         * @return int Numero di voci rimosse.
         */
        private static function prune_runtime_stale() {
                $ttl = self::runtime_stale_seconds();
                if ( $ttl <= 0 ) {
                        return 0; // TTL disattivato via filtro: nessuna pulizia.
                }

                $runtime = self::get_runtime();
                if ( empty( $runtime ) ) {
                        return 0;
                }

                $now     = time();
                $removed = 0;

                foreach ( $runtime as $agent_id => $state ) {
                        $since = is_array( $state ) && isset( $state['since'] ) ? strtotime( (string) $state['since'] ) : 0;

                        if ( ! $since || ( $now - $since ) > $ttl ) {
                                unset( $runtime[ $agent_id ] );
                                $removed++;
                        }
                }

                if ( $removed > 0 ) {
                        update_option( self::RUNTIME_OPTION, $runtime, false );
                }

                return $removed;
        }

        /**
         * v1.1.18 (CR-1) — articoli "veramente" in lavorazione per la busy map di
         * resume_stalled: considera SOLO le voci runtime con 'since' fresco
         * (entro il TTL). Le voci scadute (crash senza clear_runtime) sono
         * ignorate: l'articolo torna riprendibile — niente più esclusione
         * perpetua.
         *
         * @return array article_id => true per gli articoli in lavorazione VIVI.
         */
        private static function fresh_runtime_articles() {
                $ttl  = self::runtime_stale_seconds();
                $now  = time();
                $busy = array();

                foreach ( self::get_runtime() as $state ) {
                        if ( ! is_array( $state ) || empty( $state['article_id'] ) ) {
                                continue;
                        }

                        if ( $ttl <= 0 ) {
                                // TTL disattivato via filtro: comportamento v1.1.17 (tutte le voci contano).
                                $busy[ absint( $state['article_id'] ) ] = true;
                                continue;
                        }

                        $since = isset( $state['since'] ) ? strtotime( (string) $state['since'] ) : 0;

                        if ( $since && ( $now - $since ) <= $ttl ) {
                                $busy[ absint( $state['article_id'] ) ] = true;
                        }
                }

                return $busy;
        }

        /**
         * v1.1.18 (AL-6) — auto-retry degli agenti in status 'error': un agente
         * la cui registrazione errore è più vecchia del backoff
         * (AGENT_ERROR_BACKOFF_SECONDS, default 30 min) viene riattivato
         * automaticamente ('error' → 'active') e ripassato dal tick.
         *
         * PERCHÉ: finish_agent_run_row mette status='error' su OGNI run 'failed'
         * e cron_tick esegue solo gli 'active' (run_full_agent skippa i
         * non-attivi, anche via REST): un outage AI di 5 minuti disattivava TUTTI
         * gli agenti in un tick e il recovery richiedeva un click manuale per
         * agente. Ora l'errore transiente si "guarisce" da solo: se l'AI è ancora
         * giù la run riparte, fallisce e il backoff riparte dal nuovo errore —
         * costo massimo 1 collect ogni 30 min per agente.
         *
         * TIMESTAMP DELL'ERRORE: agents.updated_at — update_agent() la scrive a
         * OGNI transizione, inclusa la messa in 'error' di finish_agent_run_row
         * (nessuna colonna nuova, nessun cambio schema). Un'eventuale modifica
         * manuale all'agente (REST/UI) rinfresca updated_at e posticipa la
         * riattivazione di una finestra: effetto accettato e innocuo.
         *
         * SOLO 'error' è toccato: 'paused' e ogni altro stato scelto dall'utente
         * restano intoccabili (stessa regola di finish_agent_run_row).
         *
         * @return int Numero di agenti riattivati.
         */
        private static function reactivate_error_agents() {
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'agents' ) || ! method_exists( 'Nexus_DB', 'update_agent' ) ) {
                        return 0;
                }

                $backoff = (int) apply_filters( 'nexus_pipeline_agent_error_backoff', self::AGENT_ERROR_BACKOFF_SECONDS );
                if ( $backoff <= 0 ) {
                        return 0; // Filtro 0 = auto-retry disattivato (comportamento v1.1.17).
                }

                // agents.updated_at è scritto con current_time('mysql') (orologio SITO):
                // il "now" del confronto usa current_time('timestamp') (stesso orologio,
                // stessa convenzione del resume_stalled v1.1.13) — con time() UTC il
                // backoff sarebbe falsato dell'offset del fuso.
                $now   = current_time( 'timestamp' );
                $count = 0;

                foreach ( (array) Nexus_DB::agents() as $agent ) {
                        if ( 'error' !== (string) $agent->status ) {
                                continue;
                        }

                        $errored_at = isset( $agent->updated_at ) ? strtotime( (string) $agent->updated_at ) : 0;

                        if ( ! $errored_at || ( $now - $errored_at ) < $backoff ) {
                                continue; // Errore recente o timestamp illeggibile: backoff non ancora scaduto.
                        }

                        Nexus_DB::update_agent( (int) $agent->id, array( 'status' => 'active' ) );
                        $count++;

                        // Activity log ONESTO: la riattivazione è automatica e visibile,
                        // con il momento dell'errore originale e la durata del backoff.
                        self::log_phase(
                                (int) $agent->id,
                                'collecting',
                                sprintf( 'Agente "%s" riattivato automaticamente: l\'errore era stato registrato alle %s (oltre %d min fa, backoff scaduto). Nuovo tentativo del giro di redazione: se l\'AI è ancora irraggiungibile l\'agente tornerà in errore e il backoff ripartirà.', (string) $agent->name, (string) $agent->updated_at, (int) round( $backoff / 60 ) ),
                                'info'
                        );
                }

                return $count;
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
         * v1.1.13 (O1) — anti-starvation: le esclusioni "non riprendibile" (senza
         * fonte, factcheck_score > 0) stanno NELLA WHERE SQL e non più in PHP dopo
         * la query: prima i 100 draft più vecchi, se tutti non-riprendibili,
         * nascondevano per sempre i draft più recenti. La query diretta (già
         * prepare'd, colonna della tabella nexus_articles via Nexus_DB::tables())
         * sostituisce Nexus_DB::articles() che non espone questi filtri.
         *
         * v1.1.13 (O1) — lock per-articolo: 'nexus_resume_lock_{id}' con TTL
         * RESUME_LOCK_TTL mentre l'articolo è in lavorazione; due tick
         * sovrapposti non processano mai lo stesso articolo (lock presente → skip,
         * si riprende al tick successivo). Rilascio garantito nel finally anche su
         * eccezione (che ora resta confinata al singolo articolo).
         * v1.1.18 (AL-8): acquisizione ATOMICA via acquire_lock (add_option
         * check-and-set, valore = timestamp, ruba-lock su orfani oltre TTL) al
         * posto del set_transient check-then-set (finestra di corsa tra check e
         * set: due tick sovrapposti lavoravano lo stesso articolo).
         *
         * v1.1.18 (CR-1) — niente più esclusione perpetua: la busy map del
         * runtime (fresh_runtime_articles) ha un TTL sul campo 'since': le voci
         * lasciate da un crash sono ignorate dopo RUNTIME_STALE_SECONDS e
         * rimosse dalla option dal reaper di cron_tick (prune_runtime_stale).
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

                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'tables' ) ) {
                        return $out;
                }

                $limit         = min( 20, max( 1, absint( $limit ) ) );
                $max_age_hours = max( 1, absint( $max_age_hours ) );
                $threshold     = current_time( 'timestamp' ) - $max_age_hours * HOUR_IN_SECONDS;

                /* ---- v1.1.13 (O1): solo draft RIPRENDIBILI, esclusi in SQL ----
                 * - source_name vuoto = bozza non prodotta dalla pipeline;
                 * - factcheck_score > 0 = tornata in bozza dal fact-check (revisione
                 *   umana richiesta). La colonna è DECIMAL NOT NULL DEFAULT 0: con lo
                 *   schema attuale una fact-check 0.00 è indistinguibile dal "mai
                 *   eseguito" (edge accettato, come da audit).
                 * Finestra di scansione invariata (100 draft più vecchi), ma ora la
                 * finestra contiene SOLO candidati riprendibili: nessuna starvation.
                 */
                global $wpdb;
                $table = Nexus_DB::tables()['articles'];
                $rows  = $wpdb->get_results(
                        $wpdb->prepare(
                                "SELECT id, agent_id, title, source_name, factcheck_score, created_at, updated_at
                                        FROM {$table}
                                        WHERE status = 'draft'
                                          AND source_name <> ''
                                          AND (factcheck_score IS NULL OR factcheck_score <= 0)
                                        ORDER BY created_at ASC
                                        LIMIT %d",
                                100
                        )
                );
                if ( ! is_array( $rows ) ) {
                        return $out;
                }

                // Runtime corrente: un articolo già "in redazione" per un agente non si
                // tocca. v1.1.18 (CR-1): la busy map ha un TTL sul campo 'since'
                // (ISO8601 UTC) — le voci più vecchie di RUNTIME_STALE_SECONDS (crash
                // del processo, nessun clear_runtime) sono IGNORATE: prima l'articolo
                // in lavorazione al momento del crash restava escluso dal resume PER
                // SEMPRE (l'agente era 'error' → nessun nuovo giro → nessun clear).
                $busy = self::fresh_runtime_articles();

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

                        // v1.1.13 (O1): lock per-articolo non bloccante — due tick sovrapposti
                        // (o resume concorrenti via CLI/REST) non devono mai lavorare lo
                        // stesso articolo: lock presente → skip, ripresa al prossimo tick.
                        // v1.1.18 (AL-8): acquisizione ATOMICA col pattern acquire_lock
                        // (add_option check-and-set, valore = timestamp, TTL RESUME_LOCK_TTL
                        // con ruba-lock per orfani di crash): il vecchio
                        // get_transient+set_transient era check-then-set — due tick che
                        // leggevano assenza nello stesso istante procedevano ENTRAMBI
                        // (doppia evaluate/rewrite = doppio costo AI; la doppia
                        // pubblicazione restava chiusa solo dal mutex publish).
                        $resume_lock = self::RESUME_LOCK_PREFIX . $article_id;
                        if ( ! self::acquire_lock( $resume_lock, self::RESUME_LOCK_TTL ) ) {
                                $out['skipped']++;
                                continue;
                        }

                        // Età dello stallo: updated_at (fallback created_at) più vecchio della soglia.
                        $stamp_raw = isset( $row->updated_at ) && '' !== (string) $row->updated_at ? (string) $row->updated_at : (string) $row->created_at;
                        $stamp     = strtotime( $stamp_raw );
                        if ( ! $stamp || $stamp > $threshold ) {
                                self::release_lock( $resume_lock ); // v1.1.18 (AL-8): pattern acquire/release_lock.
                                continue; // Ancora fresco: non è uno stallo.
                        }

                        // Difensive in PHP (le stesse regole della WHERE: mai fidarsi ciecamente).
                        if ( '' === trim( (string) ( isset( $row->source_name ) ? $row->source_name : '' ) ) ) {
                                self::release_lock( $resume_lock ); // v1.1.18 (AL-8).
                                continue; // Senza fonte non è una bozza prodotta dalla pipeline.
                        }

                        if ( isset( $row->factcheck_score ) && (float) $row->factcheck_score > 0 ) {
                                self::release_lock( $resume_lock ); // v1.1.18 (AL-8).
                                $out['skipped']++;
                                continue; // Tornato in bozza dal fact-check: la revisione è richiesta all'umano.
                        }

                        if ( ! $auto_evaluate && ! $auto_rewrite ) {
                                self::release_lock( $resume_lock ); // v1.1.18 (AL-8).
                                $out['skipped']++;
                                continue; // Fasi successive disattivate: bozza parcheggiata di proposito.
                        }

                        try {
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
                        } catch ( Exception $e ) {
                                // v1.1.13 (O1): l'errore resta confinato al singolo articolo
                                // (prima abortiva l'intero batch) e il lock viene sempre rilasciato.
                                $out['messages'][] = sprintf( 'Articolo #%d: errore nel resume: %s', $article_id, self::clip( $e->getMessage(), 200 ) );
                        } catch ( Throwable $e ) {
                                $out['messages'][] = sprintf( 'Articolo #%d: errore fatale nel resume: %s', $article_id, self::clip( $e->getMessage(), 200 ) );
                        } finally {
                                // Rilascio garantito del lock per-articolo (anche su eccezione):
                                // il TTL RESUME_LOCK_TTL è la rete di sicurezza per crash duri.
                                // v1.1.18 (AL-8): release_lock pulisce anche l'eventuale
                                // _transient_timeout_* residuo dei lock legacy.
                                self::release_lock( $resume_lock );
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
         * v1.1.18 (AL-7) — LOCK + RUN ROW + HEARTBEAT:
         *  - MUTEX PER-TEMA (pattern acquire_lock WA11: add_option atomico, valore =
         *    timestamp, TTL WIRE_LOCK_TTL con ruba-lock per orfani): un secondo Wire
         *    sullo stesso tema (doppio click su "assegna", richiesta REST concorrente,
         *    requeue del reaper coda) NON gira più in parallelo → niente doppio costo
         *    AI né articolo duplicato. Il lock è rilasciato nel finally; dopo un crash
         *    duro si auto-guarigione alla scadenza del TTL (15 min < soglia requeue
         *    coda 30 min: nessuna collisione al re-dispatch).
         *  - RUN ROW agent_runs con trigger 'wire' (contratto insert_agent_run /
         *    run_trigger: valore finora mai usato — audit 🟢14): aperta DOPO i gate
         *    (team/budget/lock: le uscite precedenti non lasciano riga, stessa regola
         *    di run_full_agent) e chiusa nel finally con finish_agent_run (esito +
         *    steps + errore). Il Wire non è più l'unico flusso AI multiplo invisibile
         *    alla telemetria v1.1.16. La run row NON tocca agents.status: un Wire
         *    fallito è un esito collettivo, non il guasto di un giornalista.
         *  - HEARTBEAT DOPPIO a ogni confine di fase: Nexus_Queue::heartbeat()
         *    rinfresca started_at del job di coda (requeue_stale non riaccoda un Wire
         *    vivo) e touch_agent_run() rinfresca started_at della run row (il reaper
         *    CR-1 non la chiude mai come fantasma). Lock + heartbeat + run row
         *    chiudono la doppia esecuzione parallela su OGNI via.
         *
         * NOTA onesta: la durata (duration_ms) della run row Wire misura l'ULTIMA
         * fase (started_at è rinfrescato a ogni fase): il tempo totale resta
         * ricostruibile da created_at (colonna fissa dell'INSERT) a finished_at.
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

                // v1.1.18 (AL-7): mutex per-tema. Chiave normalizzata (md5 del tema in
                // minuscolo): i sinonimi di casing non aggirano il lock. Rifiuto ONESTO
                // (nessuna attesa): il chiamante (REST/coda) vede l'esito e riprova dopo.
                $wire_lock = self::WIRE_LOCK_PREFIX . md5( strtolower( $topic ) );
                if ( ! self::acquire_lock( $wire_lock, self::WIRE_LOCK_TTL ) ) {
                        $run['error'] = 'Nexus Wire già in corso su questo tema: attendere il completamento del giro attuale.';
                        Nexus_DB::insert_activity( (int) $first->id, 'wire', sprintf( 'Nexus Wire "%s" non avviato: un altro Wire è già in corso sullo stesso tema (lock attivo).', $topic ), 'warning' );
                        return array(
                                'ok'  => false,
                                'run' => $run,
                        );
                }

                // v1.1.18 (AL-7): run row telemetria con trigger 'wire' (guard
                // method_exists difensiva, come in run_full_agent: senza i metodi
                // agent_runs il Wire prosegue identico ma senza traccia).
                $run_id = 0;
                if ( method_exists( 'Nexus_DB', 'insert_agent_run' ) ) {
                        $run_id = (int) Nexus_DB::insert_agent_run( (int) $first->id, 'wire' );
                }

                $telemetry = array(
                        'status' => 'failed',
                        'error'  => '',
                        'steps'  => array(),
                );

                try {
                        Nexus_DB::insert_activity( (int) $first->id, 'wire', sprintf( 'Nexus Wire avviato sul tema "%s" con %d agenti in redazione.', $topic, count( $team ) ), 'info' );

                        return self::wire_run_locked( $run, $topic, $team, $first, $run_id, $telemetry );
                } catch ( Throwable $e ) {
                        $telemetry['status'] = 'failed';
                        $telemetry['error']  = self::clip( $e->getMessage(), 300 );
                        throw $e; // Contratto invariato: l'eccezione resta al chiamante (coda: retry/backoff; REST: 500).
                } finally {
                        // Rilascio garantito del mutex e chiusura della run row in OGNI
                        // caso di uscita (stessa disciplina try/finally di run_full_agent).
                        self::release_lock( $wire_lock );
                        self::finish_wire_run_row( $run_id, $telemetry );
                }
        }

        /**
         * Corpo del NEXUS WIRE, eseguito DENTRO il mutex per-tema e con la run row
         * aperta (v1.1.18 AL-7, stesso pattern WA6 dei corpi *_run). Riceve la
         * run del registro wire_runs, la squadra, il referente, l'ID run row e la
         * telemetria PER RIFERIMENTO; alimenta steps/errore/stato e ritorna
         * l'esito {ok, run} al chiamante.
         *
         * @param array  $run       Registro wire_runs (nexus_wire_runs).
         * @param string $topic     Tema già sanificato.
         * @param array  $team      Agenti attivi (max 3).
         * @param object $first     Agente referente (autore dell'articolo).
         * @param int    $run_id    ID run row (0 = telemetria non registrata).
         * @param array  $telemetry Telemetria della run row (per riferimento).
         * @return array{ok:bool, run:array}
         */
        private static function wire_run_locked( $run, $topic, $team, $first, $run_id, &$telemetry ) {

                /* ---- 1. Angoli editoriali dei colleghi ---- */
                $angles = array();
                foreach ( $team as $agent ) {
                        // v1.1.18 (AL-7): heartbeat doppio a ogni confine di fase — il job
                        // di coda resta "fresco" (requeue_stale non riaccoda) e la run row
                        // resta viva (reaper CR-1 non la chiude come fantasma).
                        self::heartbeat();
                        self::touch_agent_run( $run_id );

                        $step_t = microtime( true );

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
                                $telemetry['error'] = (string) $run['error'];
                                $telemetry['steps'][] = array(
                                        'step'   => 'angles',
                                        'ok'     => false,
                                        'detail' => self::clip( sprintf( '%s: %s', $agent->name, $run['error'] ), 200 ),
                                        'ms'     => (int) round( ( microtime( true ) - $step_t ) * 1000 ),
                                );
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

                        // v1.1.18 (AL-7): step telemetria per angolo (contratto steps {step, ok, detail, ms}).
                        $telemetry['steps'][] = array(
                                'step'   => 'angles',
                                'ok'     => true,
                                'detail' => self::clip( sprintf( '%s: angolo proposto (%d caratteri).', $agent->name, function_exists( 'mb_strlen' ) ? mb_strlen( $angle ) : strlen( $angle ) ), 200 ),
                                'ms'     => (int) round( ( microtime( true ) - $step_t ) * 1000 ),
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
                // v1.1.18 (AL-7): heartbeat doppio prima della chiamata più lunga del Wire.
                self::heartbeat();
                self::touch_agent_run( $run_id );

                $step_t = microtime( true );

                $res = self::chat( $director_prompt, 'wire', array( 'agent_id' => (int) $first->id, 'json_mode' => true ) );

                if ( empty( $res['ok'] ) ) {
                        $run['error'] = sprintf( 'La direzione non ha completato la fusione: %s', self::chat_error( $res, 'motore AI non disponibile' ) );
                        Nexus_DB::insert_activity( (int) $first->id, 'wire', sprintf( 'Nexus Wire "%s" fallito: %s', $topic, self::clip( $run['error'], 300 ) ), 'error' );
                        $telemetry['error'] = (string) $run['error'];
                        $telemetry['steps'][] = array(
                                'step'   => 'director',
                                'ok'     => false,
                                'detail' => self::clip( $run['error'], 200 ),
                                'ms'     => (int) round( ( microtime( true ) - $step_t ) * 1000 ),
                        );
                        self::save_wire_run( $run );
                        return array(
                                'ok'  => false,
                                'run' => $run,
                        );
                }

                // v1.1.18 (AL-11): stessa guardia onesta della fase rewrite — una
                // risposta del direttore troncata per max_tokens produce un articolo
                // INCOMPLETO: lo step fallisce e l'articolo NON viene salvato come
                // completo (nessun pending_approval con testo mozzato).
                if ( ! empty( $res['truncated'] ) ) {
                        $run['error'] = 'La direzione ha prodotto una risposta troncata per max_tokens esauriti: articolo incompleto NON salvato. Aumenta max_tokens o scegli un modello con più contesto.';
                        Nexus_DB::insert_activity( (int) $first->id, 'wire', sprintf( 'Nexus Wire "%s" fallito: risposta del direttore troncata per max_tokens (articolo incompleto non salvato).', $topic ), 'error' );
                        $telemetry['error'] = (string) $run['error'];
                        $telemetry['steps'][] = array(
                                'step'   => 'director',
                                'ok'     => false,
                                'detail' => self::clip( 'Risposta troncata per max_tokens (articolo incompleto non salvato).', 200 ),
                                'ms'     => (int) round( ( microtime( true ) - $step_t ) * 1000 ),
                        );
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
                        $telemetry['error'] = (string) $run['error'];
                        $telemetry['steps'][] = array(
                                'step'   => 'director',
                                'ok'     => false,
                                'detail' => self::clip( $run['error'], 200 ),
                                'ms'     => (int) round( ( microtime( true ) - $step_t ) * 1000 ),
                        );
                        self::save_wire_run( $run );
                        return array(
                                'ok'  => false,
                                'run' => $run,
                        );
                }

                $telemetry['steps'][] = array(
                        'step'   => 'director',
                        'ok'     => true,
                        'detail' => self::clip( sprintf( 'Articolo fuso da %d angoli (%d parole).', count( $angles ), self::word_count( (string) $data['content'] ) ), 200 ),
                        'ms'     => (int) round( ( microtime( true ) - $step_t ) * 1000 ),
                );

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
                        $telemetry['error'] = (string) $run['error'];
                        $telemetry['steps'][] = array(
                                'step'   => 'save',
                                'ok'     => false,
                                'detail' => self::clip( $run['error'], 200 ),
                                'ms'     => 0,
                        );
                        self::save_wire_run( $run );
                        return array(
                                'ok'  => false,
                                'run' => $run,
                        );
                }

                $run['status']     = 'completed';
                $run['article_id'] = $article_id;

                // v1.1.18 (AL-7): esito run row 'ok' (unico percorso di successo).
                $telemetry['status'] = 'ok';
                $telemetry['steps'][] = array(
                        'step'   => 'save',
                        'ok'     => true,
                        'detail' => self::clip( sprintf( 'Articolo #%d salvato in coda di approvazione.', $article_id ), 200 ),
                        'ms'     => 0,
                );

                Nexus_DB::insert_activity( (int) $first->id, 'wire', sprintf( 'Nexus Wire completato sul tema "%s": articolo #%d in coda di approvazione (redazione: %s).', $topic, $article_id, implode( ', ', $run['agents'] ) ), 'success' );
                self::save_wire_run( $run );

                return array(
                        'ok'  => true,
                        'run' => $run,
                );
        }

        /**
         * v1.1.18 (AL-7) — chiusura della run row del Wire (finally di wire_run):
         * finish_agent_run con esito ('ok'|'failed') + steps + ultimo errore.
         * NON tocca agents.status (a differenza di finish_agent_run_row): un Wire
         * fallito è un esito collettivo (tema/lock/budget/AI), non il guasto di un
         * giornalista — lo status degli agenti resta appannaggio dei loro run.
         *
         * Guard method_exists difensive e try/catch totale: la telemetria non deve
         * MAI mascherare l'esito del Wire.
         *
         * @param int   $run_id    ID run row (0 = non registrata).
         * @param array $telemetry Telemetria (status, error, steps).
         * @return void
         */
        private static function finish_wire_run_row( $run_id, $telemetry ) {
                if ( $run_id <= 0 || ! method_exists( 'Nexus_DB', 'finish_agent_run' ) ) {
                        return;
                }

                $status = ( isset( $telemetry['status'] ) && 'ok' === (string) $telemetry['status'] ) ? 'ok' : 'failed';

                try {
                        Nexus_DB::finish_agent_run(
                                (int) $run_id,
                                $status,
                                array(
                                        'steps' => isset( $telemetry['steps'] ) && is_array( $telemetry['steps'] ) ? $telemetry['steps'] : array(),
                                        'error' => (string) ( isset( $telemetry['error'] ) ? $telemetry['error'] : '' ),
                                )
                        );
                } catch ( Throwable $e ) {
                        // Silenzio onesto: la scrittura della run row non rompe il Wire.
                }
        }

        /**
         * v1.1.18 (AL-7) — heartbeat della run row del Wire: rinfresca started_at
         * (solo se la riga è ancora 'running': UPDATE condizionale) così il reaper
         * CR-1 (soglia RUN_STALE_MINUTES su started_at) non chiude mai come
         * fantasma un Wire vivo. Guardie totali, mai fatale.
         *
         * @param int $run_id ID run row (0 = nessuna azione).
         * @return void
         */
        private static function touch_agent_run( $run_id ) {
                if ( $run_id <= 0 || ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'tables' ) ) {
                        return;
                }

                $tables = Nexus_DB::tables();
                if ( ! is_array( $tables ) || empty( $tables['agent_runs'] ) ) {
                        return;
                }

                global $wpdb;

                $wpdb->update(
                        $tables['agent_runs'],
                        array( 'started_at' => current_time( 'mysql' ) ),
                        array(
                                'id'     => (int) $run_id,
                                'status' => 'running',
                        ),
                        array( '%s' ),
                        array( '%d', '%s' )
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

        /**
         * v1.1.15 (WA2) — Heartbeat del job di coda in esecuzione (se c'è):
         * rinfresca started_at del job corrente del runner Nexus_Queue, così il
         * reaper requeue_stale non riaccoda i job vivi (doppia esecuzione = doppio
         * costo AI). Fuori dalla coda (REST/CLI dirette) è un no-op guarded:
         * nessuna dipendenza obbligatoria da Nexus_Queue, mai fatale.
         *
         * @return void
         */
        private static function heartbeat() {
                if ( ! class_exists( 'Nexus_Queue' ) || ! method_exists( 'Nexus_Queue', 'heartbeat' ) ) {
                        return;
                }

                try {
                        Nexus_Queue::heartbeat();
                } catch ( Throwable $e ) {
                        // Mai fatale: un heartbeat perso non interrompe la fase corrente.
                }
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
         * v1.1.13.1 (Q4): delega all'helper condiviso Nexus_Dedup::settings_guarded()
         * (semantica identica: guardie class_exists/method_exists su Nexus_DB,
         * ritorna sempre un array). Il modulo è richiesto dal bootstrap PRIMA di
         * class-nexus-dedup.php: il corpo storico resta SOLO come fallback per un
         * eventuale caricamento senza Nexus_Dedup.
         *
         * @return array
         */
        private static function settings() {
                if ( class_exists( 'Nexus_Dedup' ) && method_exists( 'Nexus_Dedup', 'settings_guarded' ) ) {
                        return Nexus_Dedup::settings_guarded();
                }

                // Fallback locale (caricamento senza Nexus_Dedup): comportamento storico.
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
         * v1.1.13 (O1): NESSUN default 70 (era il default dell'originale). Se la
         * risposta AI è garbage/illeggibile il punteggio vale 0 e 'parsed' vale
         * false: con un punteggio non parsato l'articolo NON si auto-approva MAI
         * (v. run_full_agent e resume_stalled) — serve revisione umana.
         *
         * @param string $text Risposta del modello.
         * @return array{score:int, parsed:bool} Punteggio normalizzato 0-100 (0 se non ricavabile) + flag di parsing riuscito.
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
                        // v1.1.13 (O1): risposta illeggibile → 0 + parsed=false.
                        // Mai un default "ottimista": un punteggio inventato auto-approva
                        // articoli mai valutati davvero (audit v1.1.13, parse_score).
                        return array(
                                'score'  => 0,
                                'parsed' => false,
                        );
                }

                return array(
                        'score'  => max( 0, min( 100, $score ) ),
                        'parsed' => true,
                );
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
         * v1.1.13 (O1): quinto argomento opzionale $event_key — chiave macchina
         * dell'evento (es. 'pending') passata ai listener di 'nexus_pipeline_phase'
         * come array strutturato {action, status, event}: Nexus_Notify instrada sulla
         * chiave strutturata e non più sul matching testuale del messaggio (immune a
         * cambi di testo/i18n). BC: i listener registrati con 4 argomenti continuano
         * a funzionare; chi non passa l'evento fa uscire notify sul fallback testuale.
         *
         * @param int    $agent_id   ID agente (0 = sistema).
         * @param string $action     Azione (collecting|evaluating|rewriting|publishing|wire).
         * @param string $detail     Dettaglio.
         * @param string $status     Stato.
         * @param string $event_key  Evento strutturato per notify (''|error|pending|published).
         * @return void
         */
        private static function log_phase( $agent_id, $action, $detail, $status, $event_key = '' ) {
                // v1.1.13 (O1): evento pipeline per pacchetti esterni — le notifiche
                // (S7/O1) instradano sull'evento strutturato con fallback sul testuale.
                $meta = array(
                        'action' => (string) $action,
                        'status' => (string) $status,
                        'event'  => (string) $event_key,
                );
                do_action( 'nexus_pipeline_phase', $agent_id, $action, $detail, $status, $meta );
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
