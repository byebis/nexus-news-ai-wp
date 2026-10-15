<?php
/**
 * Nexus Metrics — sincronizzazione giornaliera metriche GSC/GA4 (feedback loop, v1.1.12).
 *
 * Sostituisce lo stub S0: modulo che popola la tabella wp_nexus_metrics
 * (schema v4, UNIQUE post_metric su post_id + metric_date + source) con i
 * dati di performance giornalieri degli articoli Nexus pubblicati:
 *  - 'gsc' = Google Search Console (clicks, impressions, position);
 *  - 'ga4' = Google Analytics 4 (views / screenPageViews).
 *
 * CONTRATTO PUBBLICO:
 * Nexus_Metrics::init()        — registra l'handler cron + allinea la schedulazione
 *                                giornaliera alle impostazioni (metrics_sync_enabled).
 * Nexus_Metrics::run_sync()    — handler dell'evento 'nexus_metrics_sync': esegue la sync.
 * Nexus_Metrics::deactivate()  — static: rimuove l'evento schedulato (chiamata dalla
 *                                deattivazione del plugin, stessa firma di Nexus_Cron).
 *
 * SCHEDULAZIONE:
 * Evento WP-Cron 'nexus_metrics_sync' con ricorrenza 'daily', primo run sfalsato di
 * 300 secondi + jitter casuale 0..600 (evita i picchi :00 su hosting condivisi).
 * In init() si schedula SOLO se metrics_sync_enabled == 1 (Nexus_DB::get_settings(),
 * con guardia), altrimenti l'evento viene rimosso (wp_clear_scheduled_hook).
 * init() NON fa richieste di rete: solo hook + scheduling (lettura opzioni locale).
 *
 * LIMITI PER RUN (v1.1.13, O2 — GA4 batched):
 *  - max 50 post (posts_per_page hard cap, articoli pubblicati negli ultimi 7 giorni);
 *  - budget di tempo 60 secondi (check nel loop con microtime: il cron finisce SEMPRE
 *    pulito, anche a metà lavoro — il completamento avviene al giro successivo);
 *  - timeout wp_remote 15 secondi;
 *  - 1 richiesta GSC a run (rowLimit 1000, nessuna paginazione oltre la prima pagina);
 *  - GA4 BATCHED: 1 sola richiesta runReport (max 3 se le varianti di path
 *    superano il cap per richiesta) per TUTTE le pagine dei post del giro,
 *    dimensioni 'date' + 'pagePath', filtro inListFilter sui path dei permalink
 *    MAI una richiesta per post (il vecchio N+1 con budget 60s tagliava
 *    sistematicamente: le metriche arrivavano incomplete).
 *
 * SEMANTICA GSC:
 * L'API Search Analytics viene interrogata UNA volta a run con dimensioni
 * 'date' + 'page': righe per-pagina REALI (finestra oggi-4 → oggi-2: GSC ha
 * lag sui dati; rowLimit 1000, nessuna paginazione oltre la prima pagina).
 * Ogni riga (date, page, clicks, impressions, position) viene attribuita a UN
 * post recente tramite matching del path del permalink: chiave = parse_url
 * del permalink (fallback: prefisso home_url rimosso), confronto sul path
 * della riga GSC (spesso un URL assoluto: path estratto con parse_url) con
 * trailing slash normalizzata (rtrim '/') e fallback case-insensitive.
 * Le righe che non matchano nessun post recente vengono SCARTATE: nessuna
 * replica sito-wide e nessuna riga fittizia (il dato mancante è onesto).
 *
 * SEMANTICA GA4 (v1.1.13, O2):
 * runReport UNICO (o max 3 chunk) con dimensioni 'date' + 'pagePath' e
 * dimensionFilter inListFilter su 'pagePath': i valori del filtro sono le
 * varianti di path dei permalink del giro (parse_url del permalink + variante
 * rawurldecoded, per coprire sia GA4 che restituisce path encodati sia
 * decodati; array_unique elimina i doppioni dei slug ASCII). Ogni riga
 * (date, pagePath, views) viene attribuita a UN post con la STESSA mappa
 * path→post_id di GSC (permalink_path_map: match esatto + fallback
 * case-insensitive, encoding decodificato su entrambi i lati, trailing slash
 * normalizzata); le righe senza post corrispondente vengono SCARTATE con
 * contatore (nessuna replica). L'upsert sulla UNIQUE post_metric
 * (post_id, metric_date, source) rende impossibile duplicare righe: un
 * re-run sovrascrive i valori del giorno. Limiti GA4 rispettati: 2
 * dimensioni (max 9), 1 metrica (max 20), inListFilter con cap
 * GA4_FILTER_VALUES per richiesta (le pagine oltre il cap NON vengono
 * richieste: nota onesta a log, nessun dato fittizio).
 *
 * NESSUN DATO SENSIBILE NEI LOG: il token OAuth non viene MAI scritto in activity
 * log o error_log; i messaggi d'errore sono brevi e senza URL completi (brief_error()).
 * Nessuna eccezione non catturata: run_sync() incassa Exception/Throwable e logga.
 *
 * v1.1.15 (WE4) — AFFIDABILITÀ E PERFORMANCE HTTP/DB:
 *  - RETRY: le richieste GSC/GA4 (per-pagina, GA4 batched e search queries) girano
 *    via google_post_with_recovery(): UN retry automatico su errori transitori
 *    (rete/429/5xx) con attesa da Retry-After, cap 60s — UNA sola implementazione
 *    nel plugin, l'helper condiviso Nexus_Notify::http_post_with_retry di WE1;
 *  - 401: su risposta 401 viene tentato UN re-accesso al token
 *    (Nexus_Google_Auth::get_access_token(), usa il refresh_token se necessario):
 *    se il token cambia, la richiesta parte UNA seconda volta col token rinnovato;
 *  - UPSERT BATCHED: upsert_metric_rows() scrive le righe metriche in INSERT
 *    multi-row (chunk di 100) ... ON DUPLICATE KEY UPDATE: niente più migliaia di
 *    round-trip DB riga-per-riga; upsert_metric_row() resta come delega a 1 riga.
 *
 * v1.1.14 (P2) — SEARCH QUERIES GSC (opportunità di ricerca):
 * Stessa chiamata searchAnalytics (endpoint GSC_ENDPOINT), dimension 'query':
 * UNA richiesta per GIORNO della finestra dati (oggi-2 → oggi-4: dal più
 * recente al più vecchio, rowLimit GSC_ROW_LIMIT per giorno) → righe
 * sito-wide indipendenti dai post, upsert idempotente sulla nuova tabella
 * wp_nexus_search_queries (schema v6, UNIQUE query_day su query + query_date).
 * La colonna top_url resta NULL: la risposta query-dimension non porta
 * pagine (colonna riservata a un futuro sync con dimension 'page').
 * ISOLAMENTO (contratto del task): il ciclo query gira DOPO il sync
 * per-pagina/GA4 (che così non può essere interrotto da un errore query) e
 * il suo esito viaggia in un suffisso additivo del log finale; nel ramo
 * "nessun articolo recente" le query vengono comunque raccolte (sito-wide,
 * non dipendono dai post). Errori dei due percorsi restano SEPARATI dai
 * contatori per-pagina (onestà: nessun doppio conteggio).
 * GLUE (debito documentato): il file include/class-nexus-opportunities.php
 * (endpoint REST "assegna a un agente" + dati della sezione opportunità)
 * viene richiesto QUI in fondo, perché il bootstrap non è nel perimetro del
 * task: al prossimo integratore basta spostare il require_once in
 * nexus-news-ai.php ed eliminare la riga in fondo a questo file.
 *
 * v1.1.18 (FIX-10) — SCALABILITÀ:
 *  - SEARCH QUERIES: upsert BATCHED per giorno (upsert_search_query_rows,
 *    chunk QUERY_UPSERT_CHUNK = 500, stesso pattern di upsert_metric_rows):
 *    al più 6 round-trip DB a run invece di ~3000 upsert riga-per-riga
 *    (3 giorni × rowLimit GSC_ROW_LIMIT = 1000); il check budget resta tra
 *    le richieste HTTP giornaliere, la scrittura batched è O(6) query.
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

final class Nexus_Metrics {

        /**
         * Hook WP-Cron gestito da questa classe.
         */
        const HOOK_SYNC = 'nexus_metrics_sync';

        /**
         * Cap massimo di post processati per run.
         */
        const MAX_POSTS = 50;

        /**
         * Budget di tempo per run in secondi (check nel loop).
         */
        const TIME_BUDGET = 60;

        /**
         * Timeout delle richieste HTTP (wp_remote_post) in secondi.
         */
        const HTTP_TIMEOUT = 15;

        /**
         * Ritardo iniziale prima del primo run dopo la schedulazione (300 secondi).
         */
        const FIRST_RUN_DELAY = 300;

        /**
         * Jitter massimo (secondi) aggiunto al ritardo iniziale.
         */
        const JITTER_MAX = 600;

        /**
         * Finestra di ricerca articoli pubblicati (giorni).
         */
        const LOOKBACK_DAYS = 7;

        /**
         * Finestra dati GSC: start = oggi - 4 giorni (GSC ha lag sui dati).
         */
        const GSC_START_OFFSET = 4;

        /**
         * Finestra dati GSC: end = oggi - 2 giorni (ultimi 3 giorni completi).
         */
        const GSC_END_OFFSET = 2;

        /**
         * rowLimit della query GSC: prima pagina di risultati, nessuna
         * paginazione oltre (resta 1 sola richiesta HTTP a run).
         */
        const GSC_ROW_LIMIT = 1000;

        /**
         * v1.1.18 (FIX-10): righe per chunk dell'upsert batched delle search
         * query (INSERT multi-row ... ON DUPLICATE KEY UPDATE, pattern
         * upsert_metric_rows). 500 righe × 6 colonne ≈ 3000 placeholder per
         * query: pacchetto ben sotto max_allowed_packet e 3 giorni ×
         * rowLimit GSC_ROW_LIMIT (1000) → al più 6 round-trip DB a run
         * invece di ~3000 upsert riga-per-riga.
         */
        const QUERY_UPSERT_CHUNK = 500;

        /**
         * Finestra dati GA4: start = oggi - 3 giorni (ultimi 3 giorni completi,
         * lag GA4 inferiore a quello di GSC).
         */
        const GA4_START_OFFSET = 3;

        /**
         * Finestra dati GA4: end = oggi - 1 giorno.
         */
        const GA4_END_OFFSET = 1;

        /**
         * rowLimit della richiesta GA4 batched: con 2 dimensioni (date +
         * pagePath) e cap 50 post le righe attese sono ≤ 150 (50 pagine × 3
         * giorni); 1000 è il tetto prudente della prima pagina (se la risposta
         * lo riempie il flag 'truncated' va a log, nessuna paginazione).
         */
        const GA4_ROW_LIMIT = 1000;

        /**
         * Numero massimo di VALORI per il filtro inListFilter di GA4 in una
         * singola richiesta: il valore filtrato è 'pagePath'; ogni post può
         * contribuire fino a 2 varianti (path grezzo + decodificato), quindi
         * 50 valori coprono ≥ 25 post per richiesta. Prudente rispetto ai
         * limiti per-request dell'API (dimensioni max 9, metriche max 20).
         */
        const GA4_FILTER_VALUES = 50;

        /**
         * Cap di richieste GA4 per run: 3 × GA4_FILTER_VALUES valori coprono
         * sempre i 50 post del giro (anche nel caso peggiore 2 varianti a
         * post). Oltre il cap le pagine residue NON vengono richieste (nota a
         * log): MAI una richiesta per post.
         */
        const GA4_MAX_REQUESTS = 3;

        /**
         * Endpoint Search Analytics di Search Console (la property segue nel path).
         */
        const GSC_ENDPOINT = 'https://searchconsole.googleapis.com/webmasters/v3/sites/';

        /**
         * Endpoint Data API di GA4 (la property segue nel path, prima di :runReport).
         */
        const GA4_ENDPOINT = 'https://analyticsdata.googleapis.com/v1beta/properties/';

        /**
         * Bootstrap modulo: hook cron + allineamento schedulazione.
         *
         * Nessuna richiesta di rete: la schedulazione legge solo le opzioni locali
         * (Nexus_DB::get_settings()) e registra/rimuove l'evento WP-Cron.
         *
         * @return void
         */
        public static function init() {
                add_action( self::HOOK_SYNC, array( __CLASS__, 'run_sync' ) );

                self::sync_schedule();
        }

        /**
         * Allinea l'evento WP-Cron allo stato delle impostazioni:
         *  - metrics_sync_enabled == 1 → schedula l'evento giornaliero se assente;
         *  - altrimenti → rimuove l'evento se presente.
         *
         * @return void
         */
        public static function sync_schedule() {
                if ( ! self::is_sync_enabled() ) {
                        if ( wp_next_scheduled( self::HOOK_SYNC ) ) {
                                wp_clear_scheduled_hook( self::HOOK_SYNC );
                        }
                        return;
                }

                if ( ! wp_next_scheduled( self::HOOK_SYNC ) ) {
                        // Primo run sfalsato (300s) + jitter casuale (0..600s).
                        $first_run = time() + self::FIRST_RUN_DELAY + wp_rand( 0, self::JITTER_MAX );

                        if ( ! wp_schedule_event( $first_run, 'daily', self::HOOK_SYNC ) ) {
                                self::debug_log( 'Schedulazione evento ' . self::HOOK_SYNC . ' fallita.' );
                        }
                }
        }

        /**
         * Deattivazione plugin: rimuove l'evento schedulato.
         * Chiamata static (stesso pattern di Nexus_Cron::deactivate).
         *
         * @return void
         */
        public static function deactivate() {
                wp_clear_scheduled_hook( self::HOOK_SYNC );
        }

        /**
         * Handler dell'evento 'nexus_metrics_sync'.
         *
         * Il cron DEVE finire sempre pulito: nessuna eccezione può propagare
         * (incassata e loggata come attività 'error' con messaggio breve).
         *
         * @return void
         */
        public static function run_sync() {
                try {
                        self::do_sync();
                } catch ( Exception $e ) {
                        self::log_activity( 'Sync metriche interrotta: ' . self::brief_error( $e->getMessage() ), 'error' );
                } catch ( Throwable $e ) {
                        self::log_activity( 'Sync metriche interrotta (errore fatale): ' . self::brief_error( $e->getMessage() ), 'error' );
                }
        }

        /* ============ CORE ============ */

        /**
         * Corpo della sincronizzazione (chiamato da run_sync dentro try/catch).
         *
         * Flusso: guardie (Google Auth, targets) → token → post recenti →
         * 1 richiesta GSC per-pagina → attribuzione righe via path permalink
         * (righe senza post corrispondente scartate) → per post: richiesta GA4 →
         * upsert su nexus_metrics → log attività finale.
         *
         * @return void
         */
        private static function do_sync() {
                // Guardia 1: modulo Google Auth (pacchetto S1). Se assente o non
                // connesso NON è un errore: log informativo ed uscita pulita.
                if ( ! class_exists( 'Nexus_Google_Auth' ) || ! method_exists( 'Nexus_Google_Auth', 'is_connected' ) ) {
                        self::log_activity( 'Metriche: modulo Google Auth non disponibile, sincronizzazione saltata.', 'info' );
                        return;
                }

                if ( ! Nexus_Google_Auth::is_connected() ) {
                        self::log_activity( 'Metriche: Google non connesso (OAuth), sincronizzazione saltata.', 'info' );
                        return;
                }

                // Guardia 2: targets configurati (property GSC e/o property GA4).
                $settings = self::get_settings();

                $gsc_site = isset( $settings['gsc_site_url'] ) ? trim( (string) $settings['gsc_site_url'] ) : '';
                $ga4_prop = isset( $settings['ga4_property_id'] ) ? trim( (string) $settings['ga4_property_id'] ) : '';

                $has_gsc = ( '' !== $gsc_site );
                $has_ga4 = ( '' !== $ga4_prop && (bool) preg_match( '/^\d+$/', $ga4_prop ) );

                if ( ! $has_gsc && ! $has_ga4 ) {
                        self::log_activity( 'Metriche: property GSC/GA4 non configurate, sincronizzazione saltata.', 'info' );
                        return;
                }

                // Token OAuth (string|WP_Error): mai loggato, mai in errori.
                $token = '';
                if ( method_exists( 'Nexus_Google_Auth', 'get_access_token' ) ) {
                        $token = Nexus_Google_Auth::get_access_token();
                }

                if ( is_wp_error( $token ) || ! is_string( $token ) || '' === $token ) {
                        self::log_activity( 'Metriche: token di accesso Google non disponibile, sincronizzazione saltata.', 'error' );
                        return;
                }

                // Post recenti pubblicati da Nexus (meta _nexus_article_id, cap 50).
                $post_ids = self::recent_post_ids();

                if ( empty( $post_ids ) ) {
                        // v1.1.14 (P2): anche senza articoli recenti le search
                        // queries sito-wide vengono raccolte (non dipendono dai
                        // post); un errore qui è confinato nel suffisso di log.
                        $q_res = self::maybe_sync_gsc_queries( $token, $gsc_site, microtime( true ) );

                        $detail = 'Metriche sincronizzate: 0 righe (gsc 0, ga4 0, gsc non attribuite 0, ga4 non attribuite 0) — nessun articolo recente.';
                        $status = 'info';

                        if ( $q_res['ran'] ) {
                                $detail .= self::queries_log_suffix( $q_res );

                                if ( $q_res['errors'] > 0 ) {
                                        $status = 'error';
                                }
                        }

                        self::log_activity( $detail, $status );
                        return;
                }

                $start_ts      = microtime( true );
                $rows_gsc      = 0;
                $rows_ga4      = 0;
                $gsc_unmapped  = 0;
                $ga4_unmapped  = 0;
                $errors        = 0;
                $last_error    = '';
                $skipped       = 0;
                $budget_hit    = false;
                $gsc_truncated = false;
                $ga4_truncated = false;
                $ga4_capped    = 0;

                // GSC: UNA sola richiesta a run, righe per-pagina (date + page).
                $gsc_rows = array();
                if ( $has_gsc ) {
                        if ( self::budget_exceeded( $start_ts ) ) {
                                $budget_hit = true;
                        } else {
                                $gsc_res = self::fetch_gsc_rows( $token, $gsc_site );

                                if ( '' !== $gsc_res['error'] ) {
                                        $errors++;
                                        $last_error = $gsc_res['error'];
                                } else {
                                        $gsc_rows      = $gsc_res['rows'];
                                        $gsc_truncated = ! empty( $gsc_res['truncated'] );
                                }
                        }
                }

                // Mappa path permalink → post_id dei SOLO post recenti già caricati
                // (nessuna query aggiuntiva): attribuzione GSC e GA4 per-pagina
                // (v1.1.13 O2: UNICA mappa riusata da entrambe le sorgenti).
                $gsc_map = self::permalink_path_map( $post_ids );

                // Post non attribuibili a nessun path (permalink non risolvibile):
                // la richiesta GA4 non può coprirli → contatore "post saltati".
                $mapped_ids = array();
                foreach ( $gsc_map['exact'] as $mapped_post_id ) {
                        $mapped_ids[ absint( $mapped_post_id ) ] = true;
                }
                foreach ( $post_ids as $post_id ) {
                        if ( ! isset( $mapped_ids[ absint( $post_id ) ] ) ) {
                                $skipped++;
                        }
                }

                // Attribuzione righe GSC: una riga per post+giorno (UNIQUE
                // post_metric, idempotente all'upsert). Le righe che non
                // matchano nessun post recente vengono SCARTATE: nessuna
                // replica sito-wide, il dato mancante è onesto.
                // v1.1.15 (WE4): le righe valide vengono raccolte e scritte con
                // l'upsert BATCHED upsert_metric_rows() (chunk di 100, INSERT
                // multi-row): niente più un round-trip DB per riga.
                $gsc_upsert = array();
                foreach ( $gsc_rows as $row ) {
                        $row_post_id = self::resolve_post_by_page( $row['page'], $gsc_map['exact'], $gsc_map['ci'] );

                        if ( 0 === $row_post_id ) {
                                $gsc_unmapped++;
                                continue;
                        }

                        if ( self::budget_exceeded( $start_ts ) ) {
                                $budget_hit = true;
                                break;
                        }

                        $gsc_upsert[] = array(
                                'post_id'     => $row_post_id,
                                'date'        => $row['date'],
                                'source'      => 'gsc',
                                'clicks'      => $row['clicks'],
                                'impressions' => $row['impressions'],
                                'position'    => $row['position'],
                                'views'       => 0,
                        );
                }

                if ( ! empty( $gsc_upsert ) ) {
                        $written = self::upsert_metric_rows( $gsc_upsert );

                        if ( $written > 0 ) {
                                $rows_gsc += $written;
                        }

                        if ( $written < count( $gsc_upsert ) ) {
                                $errors++;
                                $last_error = 'Scrittura metrica GSC fallita (DB).';
                        }
                }

                // GA4 BATCHED (v1.1.13 O2): UNA sola richiesta runReport (max
                // 3 chunk) con dimensioni date + pagePath per TUTTE le pagine
                // dei post del giro — MAI una richiesta per post (il vecchio
                // N+1 con budget 60s tagliava sistematicamente la coda). Le
                // righe di risposta sono attribuite con la STESSA mappa di GSC;
                // le righe senza post corrispondente vengono scartate (nessun
                // doppio conteggio: UNIQUE post_metric all'upsert).
                if ( $has_ga4 ) {
                        $variants = self::ga4_path_variants( $post_ids );
                        $chunks   = array_chunk( $variants, self::GA4_FILTER_VALUES );

                        // Pagine oltre il cap (GA4_FILTER_VALUES × GA4_MAX_REQUESTS):
                        // non richieste, nota onesta a log (nessun dato fittizio).
                        $ga4_capped = max( 0, count( $variants ) - self::GA4_MAX_REQUESTS * self::GA4_FILTER_VALUES );
                        $chunks     = array_slice( $chunks, 0, self::GA4_MAX_REQUESTS );

                        // v1.1.15 (WE4): righe valide raccolte e scritte in batch a fine ciclo.
                        $ga4_upsert = array();

                        foreach ( $chunks as $chunk ) {
                                if ( self::budget_exceeded( $start_ts ) ) {
                                        $budget_hit = true;
                                        break;
                                }

                                $ga4_res = self::fetch_ga4_rows( $token, $ga4_prop, $chunk );

                                if ( '' !== $ga4_res['error'] ) {
                                        $errors++;
                                        $last_error = $ga4_res['error'];
                                        continue;
                                }

                                if ( ! empty( $ga4_res['truncated'] ) ) {
                                        $ga4_truncated = true;
                                }

                                foreach ( $ga4_res['rows'] as $row ) {
                                        $row_post_id = self::resolve_post_by_page( $row['page'], $gsc_map['exact'], $gsc_map['ci'] );

                                        if ( 0 === $row_post_id ) {
                                                $ga4_unmapped++;
                                                continue;
                                        }

                                        $ga4_upsert[] = array(
                                                'post_id'     => $row_post_id,
                                                'date'        => $row['date'],
                                                'source'      => 'ga4',
                                                'clicks'      => 0,
                                                'impressions' => 0,
                                                'position'    => 0.0,
                                                'views'       => $row['views'],
                                        );
                                }
                        }

                        if ( ! empty( $ga4_upsert ) ) {
                                $written = self::upsert_metric_rows( $ga4_upsert );

                                if ( $written > 0 ) {
                                        $rows_ga4 += $written;
                                }

                                if ( $written < count( $ga4_upsert ) ) {
                                        $errors++;
                                        $last_error = 'Scrittura metrica GA4 fallita (DB).';
                                }
                        }
                }

                // Log finale: template richiesto, con aggiunte brevi solo se servono.
                $total  = $rows_gsc + $rows_ga4;
                $detail = 'Metriche sincronizzate: ' . $total . ' righe (gsc ' . $rows_gsc . ', ga4 ' . $rows_ga4
                        . ', gsc non attribuite ' . $gsc_unmapped . ', ga4 non attribuite ' . $ga4_unmapped . ')';

                if ( $gsc_truncated ) {
                        $detail .= ' — GSC troncata: raggiunto rowLimit ' . self::GSC_ROW_LIMIT;
                }

                if ( $ga4_truncated ) {
                        $detail .= ' — GA4 troncata: raggiunto rowLimit ' . self::GA4_ROW_LIMIT;
                }

                if ( $ga4_capped > 0 ) {
                        $detail .= ' — pagine non richieste a GA4 (superato cap ' . self::GA4_MAX_REQUESTS . ' richieste): ' . $ga4_capped;
                }

                if ( $skipped > 0 ) {
                        $detail .= ' — post saltati: ' . $skipped;
                }

                if ( $budget_hit ) {
                        $detail .= ' — budget tempo raggiunto, si completa al prossimo giro';
                }

                $status = 'info';

                if ( $errors > 0 ) {
                        $status  = 'error';
                        $detail .= ' — errori: ' . $errors . ' (ultimo: ' . $last_error . ')';
                }

                // v1.1.14 (P2) — SEARCH QUERIES GSC: DOPO per-pagina/GA4 (un
                // fallimento qui non tocca le righe già scritte); il budget di
                // tempo è interno al metodo (se esaurito, esce senza tentare e
                // senza suffisso). Gli errori query NON entrano nel contatore
                // $errors delle per-pagina: viaggiano nel suffisso dedicato.
                $q_res = self::maybe_sync_gsc_queries( $token, $gsc_site, $start_ts );

                if ( $q_res['ran'] ) {
                        $detail .= self::queries_log_suffix( $q_res );

                        if ( $q_res['errors'] > 0 ) {
                                $status = 'error';
                        }
                }

                self::log_activity( $detail, $status );
        }

        /* ============ API REMOTE ============ */

        /**
         * v1.1.15 (WE4) — POST verso le API Google con recovery a due livelli:
         *  1) RETRY TRANSITORIO: UN retry automatico (max 2 tentativi) su errore di
         *     rete (WP_Error), HTTP 429 o HTTP >= 500, con attesa da Retry-After
         *     (secondi o data HTTP), cap 60s — tramite l'helper condiviso
         *     Nexus_Notify::http_post_with_retry() di WE1 (UNA sola implementazione
         *     nel plugin); fallback wp_remote_post diretto senza retry se il modulo
         *     notify non è caricato (caricamenti parziali);
         *  2) 401: UN tentativo di re-accesso al token (refresh_access_token():
         *     Nexus_Google_Auth::get_access_token(), che usa il refresh_token se
         *     necessario); SOLO se il token cambia la richiesta parte UNA seconda
         *     volta con l'Authorization rinnovato (stesso token = stesso esito, nessun
         *     secondo round-trip inutile).
         *
         * Le chiamate GSC/GA4 sono LETTURE idempotenti: nessun rischio di duplicati.
         * Il token NON viene mai loggato né inserito nei messaggi d'errore.
         *
         * @param string $url   Endpoint Google (property inclusa).
         * @param array  $args  Args wp_remote_post (headers con Authorization Bearer, body, ...).
         * @param string $token Token usato nella richiesta (per valutare il re-refresh).
         * @return array|WP_Error Risposta finale.
         */
        private static function google_post_with_recovery( $url, array $args, $token ) {
                // 1) Retry transitorio (rete/429/5xx), cap attesa 60s.
                if ( class_exists( 'Nexus_Notify' ) && method_exists( 'Nexus_Notify', 'http_post_with_retry' ) ) {
                        $response = Nexus_Notify::http_post_with_retry( $url, $args );
                } else {
                        $response = wp_remote_post( $url, $args );
                }

                if ( is_wp_error( $response ) || 401 !== (int) wp_remote_retrieve_response_code( $response ) ) {
                        return $response;
                }

                // 2) 401: UN re-accesso al token e, solo se rinnovato davvero, un secondo invio.
                $fresh = self::refresh_access_token();

                if ( '' === $fresh || $fresh === (string) $token ) {
                        return $response;
                }

                if ( isset( $args['headers']['Authorization'] ) ) {
                        $args['headers']['Authorization'] = 'Bearer ' . $fresh;
                }

                if ( class_exists( 'Nexus_Notify' ) && method_exists( 'Nexus_Notify', 'http_post_with_retry' ) ) {
                        return Nexus_Notify::http_post_with_retry( $url, $args );
                }

                return wp_remote_post( $url, $args );
        }

        /**
         * v1.1.15 (WE4) — UN tentativo di re-accesso al token Google (guardie
         * class_exists/method_exists come nel resto del modulo; get_access_token()
         * usa il refresh_token quando quello in cache è scaduto). Il token NON
         * viene mai loggato né restituito fuori da qui.
         *
         * @return string Token rinnovato ('' se non disponibile).
         */
        private static function refresh_access_token() {
                if ( ! class_exists( 'Nexus_Google_Auth' ) || ! method_exists( 'Nexus_Google_Auth', 'get_access_token' ) ) {
                        return '';
                }

                $fresh = Nexus_Google_Auth::get_access_token();

                if ( is_wp_error( $fresh ) || ! is_string( $fresh ) || '' === $fresh ) {
                        return '';
                }

                return $fresh;
        }

        /**
         * Richiesta GSC Search Analytics (dimensioni 'date' + 'page': righe
         * per-pagina REALI, ultimi 3 giorni completi con lag GSC: oggi-4 →
         * oggi-2).
         *
         * rowLimit = GSC_ROW_LIMIT (prima pagina di risultati): NESSUNA
         * paginazione oltre, resta 1 sola richiesta HTTP a run; se la risposta
         * riempie il rowLimit il flag 'truncated' viene usato per la nota a log.
         *
         * @param string $token    Access token OAuth (mai loggato).
         * @param string $site_url Property Search Console (URL esatto).
         * @return array { rows: array, truncated: bool, error: string } — error '' = OK.
         */
        private static function fetch_gsc_rows( $token, $site_url ) {
                $now_local = strtotime( current_time( 'mysql' ) );

                $body = wp_json_encode(
                        array(
                                'startDate'  => gmdate( 'Y-m-d', $now_local - self::GSC_START_OFFSET * DAY_IN_SECONDS ),
                                'endDate'    => gmdate( 'Y-m-d', $now_local - self::GSC_END_OFFSET * DAY_IN_SECONDS ),
                                'dimensions' => array( 'date', 'page' ),
                                'rowLimit'   => self::GSC_ROW_LIMIT,
                        )
                );

                $args = array(
                        'timeout' => self::HTTP_TIMEOUT,
                        'headers' => array(
                                'Authorization' => 'Bearer ' . $token,
                                'Content-Type'  => 'application/json; charset=utf-8',
                        ),
                        'body'    => $body,
                );

                // v1.1.15 (WE4): retry transitorio (rete/429/5xx) + re-accesso token su 401.
                $response = self::google_post_with_recovery(
                        self::GSC_ENDPOINT . rawurlencode( $site_url ) . '/searchAnalytics/query',
                        $args,
                        $token
                );

                if ( is_wp_error( $response ) ) {
                        return array(
                                'rows'      => array(),
                                'truncated' => false,
                                'error'     => 'Search Console: ' . self::brief_error( $response->get_error_message() ),
                        );
                }

                $code = (int) wp_remote_retrieve_response_code( $response );

                if ( $code < 200 || $code >= 300 ) {
                        return array(
                                'rows'      => array(),
                                'truncated' => false,
                                'error'     => 'Search Console: risposta HTTP ' . $code,
                        );
                }

                $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

                if ( ! is_array( $data ) ) {
                        return array(
                                'rows'      => array(),
                                'truncated' => false,
                                'error'     => 'Search Console: risposta non valida.',
                        );
                }

                $rows = array();

                if ( isset( $data['rows'] ) && is_array( $data['rows'] ) ) {
                        foreach ( $data['rows'] as $row ) {
                                // Con dimensions date+page: keys[0] = data, keys[1] = pagina.
                                if ( ! is_array( $row ) || ! isset( $row['keys'][0], $row['keys'][1] ) ) {
                                        continue;
                                }

                                $date = (string) $row['keys'][0];
                                $page = trim( (string) $row['keys'][1] );

                                if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || '' === $page ) {
                                        continue;
                                }

                                $rows[] = array(
                                        'date'        => $date,
                                        'page'        => $page,
                                        'clicks'      => isset( $row['clicks'] ) ? absint( $row['clicks'] ) : 0,
                                        'impressions' => isset( $row['impressions'] ) ? absint( $row['impressions'] ) : 0,
                                        'position'    => isset( $row['position'] ) ? round( (float) $row['position'], 2 ) : 0.0,
                                );
                        }
                }

                // rowLimit raggiunto: probabilmente ci sono più righe oltre la
                // prima pagina (nota a log, nessuna richiesta aggiuntiva).
                $truncated = ( count( $rows ) >= self::GSC_ROW_LIMIT );

                return array(
                        'rows'      => $rows,
                        'truncated' => $truncated,
                        'error'     => '',
                );
        }

        /**
         * Richiesta GA4 runReport BATCHED (v1.1.13, O2): una sola chiamata
         * per UN gruppo di pagine (dimensioni 'date' + 'pagePath', metrica
         * screenPageViews, dimensionFilter inListFilter sui path; ultimi 3
         * giorni completi). Sostituisce la richiesta per post: max 3
         * richieste a run indipendentemente dal numero di post (MAI una per
         * post).
         *
         * @param string $token    Access token OAuth (mai loggato).
         * @param string $property Property ID GA4 (solo cifre).
         * @param array  $paths    Valori pagePath per inListFilter (varianti di
         *                         path dei permalink del giro, ≤ GA4_FILTER_VALUES).
         * @return array { rows: array, truncated: bool, error: string } — error '' = OK;
         *               rows: lista { date: 'Y-m-d', page: pagePath, views: int }.
         */
        private static function fetch_ga4_rows( $token, $property, $paths ) {
                // Difesa: valori vuoti/doppioni fuori (array_unique preserva le chiavi).
                $paths = array_values( array_unique( array_filter( array_map( 'trim', (array) $paths ) ) ) );

                if ( empty( $paths ) ) {
                        return array(
                                'rows'      => array(),
                                'truncated' => false,
                                'error'     => '',
                        );
                }

                $now_local = strtotime( current_time( 'mysql' ) );

                $body = wp_json_encode(
                        array(
                                'dateRanges' => array(
                                        array(
                                                'startDate' => gmdate( 'Y-m-d', $now_local - self::GA4_START_OFFSET * DAY_IN_SECONDS ),
                                                'endDate'   => gmdate( 'Y-m-d', $now_local - self::GA4_END_OFFSET * DAY_IN_SECONDS ),
                                        ),
                                ),
                                'dimensions' => array(
                                        array( 'name' => 'date' ),
                                        array( 'name' => 'pagePath' ),
                                ),
                                'metrics'    => array(
                                        array( 'name' => 'screenPageViews' ),
                                ),
                                'dimensionFilter' => array(
                                        'filter' => array(
                                                'fieldName'    => 'pagePath',
                                                'inListFilter' => array(
                                                        'values' => array_slice( $paths, 0, self::GA4_FILTER_VALUES ),
                                                ),
                                        ),
                                ),
                                'limit'      => self::GA4_ROW_LIMIT,
                        )
                );

                $args = array(
                        'timeout' => self::HTTP_TIMEOUT,
                        'headers' => array(
                                'Authorization' => 'Bearer ' . $token,
                                'Content-Type'  => 'application/json; charset=utf-8',
                        ),
                        'body'    => $body,
                );

                // v1.1.15 (WE4): retry transitorio (rete/429/5xx) + re-accesso token su 401.
                $response = self::google_post_with_recovery(
                        self::GA4_ENDPOINT . rawurlencode( $property ) . ':runReport',
                        $args,
                        $token
                );

                if ( is_wp_error( $response ) ) {
                        return array(
                                'rows'      => array(),
                                'truncated' => false,
                                'error'     => 'GA4: ' . self::brief_error( $response->get_error_message() ),
                        );
                }

                $code = (int) wp_remote_retrieve_response_code( $response );

                if ( $code < 200 || $code >= 300 ) {
                        return array(
                                'rows'      => array(),
                                'truncated' => false,
                                'error'     => 'GA4: risposta HTTP ' . $code,
                        );
                }

                $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

                if ( ! is_array( $data ) ) {
                        return array(
                                'rows'      => array(),
                                'truncated' => false,
                                'error'     => 'GA4: risposta non valida.',
                        );
                }

                $rows = array();

                if ( isset( $data['rows'] ) && is_array( $data['rows'] ) ) {
                        foreach ( $data['rows'] as $row ) {
                                if ( ! is_array( $row ) ) {
                                        continue;
                                }

                                // Con dimensions date+pagePath: dimensionValues[0] = data
                                // (GA4: YYYYMMDD), dimensionValues[1] = pagePath.
                                $raw_date = isset( $row['dimensionValues'][0]['value'] ) ? (string) $row['dimensionValues'][0]['value'] : '';
                                $raw_page = isset( $row['dimensionValues'][1]['value'] ) ? trim( (string) $row['dimensionValues'][1]['value'] ) : '';
                                $views    = isset( $row['metricValues'][0]['value'] ) ? absint( $row['metricValues'][0]['value'] ) : 0;
                                $date     = '';

                                if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $raw_date, $m ) ) {
                                        $date = $m[1] . '-' . $m[2] . '-' . $m[3];
                                } elseif ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw_date ) ) {
                                        $date = $raw_date;
                                }

                                if ( '' === $date || '' === $raw_page ) {
                                        continue;
                                }

                                $rows[] = array(
                                        'date'  => $date,
                                        'page'  => $raw_page,
                                        'views' => $views,
                                );
                        }
                }

                // rowLimit raggiunto: probabilmente ci sono più righe oltre la
                // prima pagina (nota a log, nessuna richiesta aggiuntiva).
                $truncated = ( count( $rows ) >= self::GA4_ROW_LIMIT );

                return array(
                        'rows'      => $rows,
                        'truncated' => $truncated,
                        'error'     => '',
                );
        }

        /* ============ ATTRIBUZIONE GSC/GA4 ============ */

        /**
         * Varianti di pagePath da chiedere a GA4 per i post del giro (v1.1.13,
         * O2): per ogni permalink si estrae il path con parse_url (fallback:
         * prefisso home_url rimosso) e si aggiunge la variante rawurldecoded —
         * GA4 può restituire/matchare path encodati o decodati a seconda della
         * property; con slug ASCII le due varianti coincidono e array_unique
         * le riduce a una. NON vengono normalizzate (trailing slash e encoding
         * restano come nel permalink reale: è ciò che il browser ha richiesto).
         * L'attribuzione delle righe di risposta resta comunque tollerante:
         * passa dalla mappa normalizzata (resolve_post_by_page).
         *
         * @param array $post_ids ID dei post recenti (int).
         * @return array Lista di stringhe pagePath (unica, valori non vuoti).
         */
        private static function ga4_path_variants( $post_ids ) {
                $variants = array();

                foreach ( $post_ids as $post_id ) {
                        $permalink = get_permalink( $post_id );

                        if ( ! is_string( $permalink ) || '' === $permalink ) {
                                continue;
                        }

                        $raw = '';
                        $parsed = parse_url( $permalink, PHP_URL_PATH );

                        if ( is_string( $parsed ) && '' !== $parsed ) {
                                $raw = $parsed;
                        } else {
                                // Fallback: rimuovi il prefisso home_url() dal permalink.
                                $home = home_url();

                                if ( is_string( $home ) && '' !== $home && 0 === strpos( $permalink, $home ) ) {
                                        $raw = substr( $permalink, strlen( $home ) );
                                }
                        }

                        $raw = trim( (string) $raw );

                        if ( '' === $raw ) {
                                continue;
                        }

                        $variants[] = $raw;
                        $decoded    = rawurldecode( $raw );

                        if ( '' !== $decoded && $decoded !== $raw ) {
                                $variants[] = $decoded;
                        }
                }

                return array_values( array_unique( $variants ) );
        }

        /**
         * Mappa path permalink → post_id per i SOLO post recenti già caricati
         * (lista di ID da recent_post_ids(), nessuna query aggiuntiva).
         *
         * Chiave: path del permalink — parse_url(get_permalink(post)) con
         * fallback sulla rimozione del prefisso home_url(), trailing slash
         * rimossa, encoding percentuale decodificato su entrambi i lati.
         * Vengono restituite due mappe:
         *  - 'exact': case-sensitive (match preferito);
         *  - 'ci'   : path lowercase, fallback case-insensitive.
         *
         * @param array $post_ids ID dei post recenti (int).
         * @return array { exact: array, ci: array }
         */
        private static function permalink_path_map( $post_ids ) {
                $map_exact = array();
                $map_ci    = array();

                foreach ( $post_ids as $post_id ) {
                        $permalink = get_permalink( $post_id );

                        if ( ! is_string( $permalink ) || '' === $permalink ) {
                                continue;
                        }

                        $path = self::permalink_path( $permalink );

                        if ( '' === $path ) {
                                continue;
                        }

                        if ( ! isset( $map_exact[ $path ] ) ) {
                                $map_exact[ $path ] = absint( $post_id );
                        }

                        $key = strtolower( $path );

                        if ( ! isset( $map_ci[ $key ] ) ) {
                                $map_ci[ $key ] = absint( $post_id );
                        }
                }

                return array(
                        'exact' => $map_exact,
                        'ci'    => $map_ci,
                );
        }

        /**
         * Risolve il post_id di una riga GSC dal valore 'page' (spesso un URL
         * assoluto): 1) match esatto case-sensitive, 2) fallback
         * case-insensitive. 0 = nessun post recente corrisponde → riga scartata.
         *
         * @param string $page      Valore 'page' della riga GSC (URL o path).
         * @param array  $map_exact Mappa path → post_id (case-sensitive).
         * @param array  $map_ci    Mappa path lowercase → post_id.
         * @return int
         */
        private static function resolve_post_by_page( $page, $map_exact, $map_ci ) {
                $path = self::gsc_page_path( $page );

                if ( '' === $path ) {
                        return 0;
                }

                if ( isset( $map_exact[ $path ] ) ) {
                        return absint( $map_exact[ $path ] );
                }

                $key = strtolower( $path );

                if ( isset( $map_ci[ $key ] ) ) {
                        return absint( $map_ci[ $key ] );
                }

                return 0;
        }

        /**
         * Path normalizzato di un permalink: parse_url con fallback sulla
         * rimozione del prefisso home_url() se parse_url non restituisce un path.
         *
         * @param string $permalink Permalink del post.
         * @return string Path normalizzato ('' se non ricavabile).
         */
        private static function permalink_path( $permalink ) {
                $permalink = trim( (string) $permalink );
                $path      = '';

                if ( '' !== $permalink ) {
                        $parsed = parse_url( $permalink, PHP_URL_PATH );

                        if ( is_string( $parsed ) && '' !== $parsed ) {
                                $path = $parsed;
                        } else {
                                // Fallback: rimuovi il prefisso home_url() dal permalink.
                                $home = home_url();

                                if ( is_string( $home ) && '' !== $home && 0 === strpos( $permalink, $home ) ) {
                                        $path = substr( $permalink, strlen( $home ) );
                                }
                        }
                }

                return self::normalize_path( $path );
        }

        /**
         * Path normalizzato del valore 'page' di una riga GSC: di solito è un
         * URL assoluto (parse_url estrae il path), ma può arrivare anche un
         * path già puro.
         *
         * @param string $page Valore 'page' della riga GSC.
         * @return string Path normalizzato ('' se non ricavabile).
         */
        private static function gsc_page_path( $page ) {
                $page = trim( (string) $page );
                $path = '';

                if ( '' !== $page ) {
                        $parsed = parse_url( $page, PHP_URL_PATH );

                        if ( is_string( $parsed ) && '' !== $parsed ) {
                                $path = $parsed;
                        } elseif ( 0 === strpos( $page, '/' ) ) {
                                // Già un path puro che parse_url non ha riconosciuto.
                                $path = $page;
                        }
                }

                return self::normalize_path( $path );
        }

        /**
         * Normalizzazione condivisa dei path per il confronto: encoding
         * percentuale decodificato (GSC e permalink possono differire), query
         * e fragment già esclusi da parse_url, trailing slash rimossa (rtrim
         * '/'), leading slash garantita. La homepage ('/') restituisce ''.
         *
         * @param string $path Path grezzo.
         * @return string
         */
        private static function normalize_path( $path ) {
                if ( ! is_string( $path ) || '' === $path ) {
                        return '';
                }

                $path = rawurldecode( $path );
                $path = rtrim( $path, '/' );

                if ( '' === $path ) {
                        return '';
                }

                return ( '/' === $path[0] ) ? $path : '/' . $path;
        }

        /* ============ PERSISTENZA ============ */

        /**
         * Upsert di UNA riga metrica su wp_nexus_metrics (retrocompatibilità:
         * v1.1.15 WE4 delega al batched upsert_metric_rows() con 1 riga, che fa
         * la stessa INSERT ... ON DUPLICATE KEY UPDATE sulla UNIQUE post_metric
         * (post_id, metric_date, source) del metodo storico).
         *
         * @param int    $post_id      ID del post WordPress.
         * @param string $metric_date  Data metrica (YYYY-MM-DD, già validata).
         * @param string $source       'gsc' | 'ga4'.
         * @param int    $clicks       Click Search Console.
         * @param int    $impressions  Impression Search Console.
         * @param float  $position     Posizione media Search Console.
         * @param int    $views        Visualizzazioni GA4.
         * @return bool True se la scrittura è andata a buon fine.
         */
        private static function upsert_metric_row( $post_id, $metric_date, $source, $clicks, $impressions, $position, $views ) {
                $rows = array(
                        array(
                                'post_id'     => $post_id,
                                'date'        => $metric_date,
                                'source'      => $source,
                                'clicks'      => $clicks,
                                'impressions' => $impressions,
                                'position'    => $position,
                                'views'       => $views,
                        ),
                );

                return self::upsert_metric_rows( $rows ) > 0;
        }

        /**
         * v1.1.15 (WE4) — upsert BATCHED di righe metriche su wp_nexus_metrics:
         * INSERT multi-row "VALUES (...),(...)" ... ON DUPLICATE KEY UPDATE sulla
         * UNIQUE post_metric (post_id, metric_date, source), a CHUNK di 100 righe
         * (1 round-trip DB per chunk invece che per riga: la sync con ~150 righe
         * GSC+GA4 passa da centinaia di query a 2). Re-run della sync sovrascrive
         * i valori del giorno senza duplicare righe; synced_at = now (locale).
         *
         * SICUREZZA: placeholder completi per OGNI riga (%d/%s/%f) costruiti
         * dinamicamente e passati a $wpdb->prepare con l'array dei valori; i valori
         * passano per le stesse normalizzazioni del metodo singolo (absint /
         * sanitize_key / round). La tabella arriva da metrics_table() (allowlist
         * interna Nexus_DB, mai input utente).
         *
         * Nota onesta: $wpdb->query su ON DUPLICATE KEY UPDATE ritorna 1 per INSERT
         * e 2 per UPDATE — per questo le righe scritte vengono contate con
         * count($chunk) e NON con il valore di ritorno di query(); un chunk fallito
         * (false) conta come 0 e i suoi errori emergono dal confronto col totale.
         *
         * @param array $rows Righe da scrivere: ognuna array con chiavi
         *                    post_id (int), date (Y-m-d), source ('gsc'|'ga4'),
         *                    clicks (int), impressions (int), position (float), views (int).
         * @return int Numero di righe incluse in chunk scritti con successo.
         */
        private static function upsert_metric_rows( array $rows ) {
                global $wpdb;

                if ( empty( $rows ) ) {
                        return 0;
                }

                $table   = self::metrics_table();
                $written = 0;

                foreach ( array_chunk( $rows, 100 ) as $chunk ) {
                        $placeholders = array();
                        $params       = array();

                        foreach ( $chunk as $row ) {
                                if ( ! is_array( $row ) ) {
                                        continue;
                                }

                                $placeholders[] = '( %d, %s, %s, %d, %d, %f, %d, %s )';

                                $params[] = absint( isset( $row['post_id'] ) ? $row['post_id'] : 0 );
                                $params[] = (string) ( isset( $row['date'] ) ? $row['date'] : '' );
                                $params[] = sanitize_key( isset( $row['source'] ) ? $row['source'] : '' );
                                $params[] = absint( isset( $row['clicks'] ) ? $row['clicks'] : 0 );
                                $params[] = absint( isset( $row['impressions'] ) ? $row['impressions'] : 0 );
                                $params[] = round( (float) ( isset( $row['position'] ) ? $row['position'] : 0 ), 2 );
                                $params[] = absint( isset( $row['views'] ) ? $row['views'] : 0 );
                                $params[] = current_time( 'mysql' );
                        }

                        if ( empty( $placeholders ) ) {
                                continue;
                        }

                        $sql = "INSERT INTO {$table} ( post_id, metric_date, source, clicks, impressions, position, views, synced_at ) "
                                . 'VALUES ' . implode( ', ', $placeholders ) . ' '
                                . 'ON DUPLICATE KEY UPDATE clicks = VALUES( clicks ), impressions = VALUES( impressions ), '
                                . 'position = VALUES( position ), views = VALUES( views ), synced_at = VALUES( synced_at )';

                        $result = $wpdb->query(
                                $wpdb->prepare(
                                        $sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholder completi per ogni riga, tabella da allowlist interna.
                                        $params
                                )
                        );

                        if ( false !== $result ) {
                                $written += count( $placeholders );
                        }
                }

                return $written;
        }

        /**
         * Nome tabella metriche, con guardie sulle API di Nexus_DB:
         *  - Nexus_DB::table( 'metrics' ) (contratto del pacchetto, se esiste);
         *  - Nexus_DB::tables()['metrics'] (implementazione attuale del repo);
         *  - fallback: $wpdb->prefix . 'nexus_metrics'.
         *
         * @return string
         */
        private static function metrics_table() {
                global $wpdb;

                if ( class_exists( 'Nexus_DB' ) ) {
                        if ( method_exists( 'Nexus_DB', 'table' ) ) {
                                $table = Nexus_DB::table( 'metrics' );
                                if ( is_string( $table ) && '' !== $table ) {
                                        return $table;
                                }
                        }

                        if ( method_exists( 'Nexus_DB', 'tables' ) ) {
                                $tables = Nexus_DB::tables();
                                if ( isset( $tables['metrics'] ) && is_string( $tables['metrics'] ) && '' !== $tables['metrics'] ) {
                                        return $tables['metrics'];
                                }
                        }
                }

                return $wpdb->prefix . 'nexus_metrics';
        }

        /**
         * ID dei post Nexus pubblicati da sincronizzare: post type nativo
         * reale del repo (filtrabile con 'nexus_native_post_type'), stato
         * 'publish', meta _nexus_article_id presente, ultimi 7 giorni,
         * hard cap 50 (const MAX_POSTS).
         *
         * @return array Lista di ID (int).
         */
        private static function recent_post_ids() {
                $now_local = strtotime( current_time( 'mysql' ) );

                $args = array(
                        'post_type'           => apply_filters( 'nexus_native_post_type', 'post' ),
                        'post_status'         => array( 'publish' ),
                        'posts_per_page'      => self::MAX_POSTS,
                        'fields'              => 'ids',
                        'no_found_rows'       => true,
                        'ignore_sticky_posts' => true,
                        'date_query'          => array(
                                array(
                                        'column'    => 'post_date',
                                        'after'     => gmdate( 'Y-m-d H:i:s', $now_local - self::LOOKBACK_DAYS * DAY_IN_SECONDS ),
                                        'inclusive' => true,
                                ),
                        ),
                        'meta_query'          => array(
                                array(
                                        'key'     => '_nexus_article_id',
                                        'compare' => 'EXISTS',
                                ),
                        ),
                );

                $query = new WP_Query( $args );
                $ids   = ( isset( $query->posts ) && is_array( $query->posts ) ) ? array_map( 'absint', $query->posts ) : array();

                return $ids;
        }

        /* ============ HELPER ============ */

        /**
         * Impostazioni plugin con guardia su Nexus_DB (mai fatal se assente).
         *
         * v1.1.13.1 (Q4): delega all'helper condiviso Nexus_Dedup::settings_guarded()
         * (semantica identica: guardie class_exists/method_exists su Nexus_DB,
         * ritorna sempre un array). Il modulo è richiesto dal bootstrap PRIMA di
         * class-nexus-dedup.php: il corpo storico resta SOLO come fallback per un
         * eventuale caricamento senza Nexus_Dedup.
         *
         * @return array
         */
        private static function get_settings() {
                if ( class_exists( 'Nexus_Dedup' ) && method_exists( 'Nexus_Dedup', 'settings_guarded' ) ) {
                        return Nexus_Dedup::settings_guarded();
                }

                // Fallback locale (caricamento senza Nexus_Dedup): comportamento storico.
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'get_settings' ) ) {
                        return array();
                }

                $settings = Nexus_DB::get_settings();

                return is_array( $settings ) ? $settings : array();
        }

        /**
         * metrics_sync_enabled == 1 (con guardie su Nexus_DB).
         *
         * @return bool
         */
        private static function is_sync_enabled() {
                $settings = self::get_settings();

                if ( ! isset( $settings['metrics_sync_enabled'] ) ) {
                        return false;
                }

                return 1 === absint( $settings['metrics_sync_enabled'] );
        }

        /**
         * Budget di tempo esaurito (const TIME_BUDGET dal via di run_sync).
         *
         * @param float $start_ts microtime(true) d'inizio run.
         * @return bool
         */
        private static function budget_exceeded( $start_ts ) {
                return ( microtime( true ) - $start_ts ) >= self::TIME_BUDGET;
        }

        /**
         * Log attività con guardia su Nexus_DB (fallback su error_log di debug).
         *
         * @param string $detail Messaggio breve (senza token/URL completi).
         * @param string $status 'info' | 'error'.
         * @return void
         */
        private static function log_activity( $detail, $status = 'info' ) {
                if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                        Nexus_DB::insert_activity( 0, 'metrics_sync', $detail, $status );
                        return;
                }

                self::debug_log( $detail );
        }

        /**
         * Rende un messaggio d'errore breve e sicuro per i log: niente URL
         * completi (sostituiti con '[url]'), niente token, max 120 caratteri.
         *
         * v1.1.15 (WE7): il formato è PROMOSO a helper condiviso pubblico
         * Nexus_Notify::brief_error() (stesso redact: URL '[url]', pattern di
         * token noti mascherati, sanitize, cap con suffisso '...'); qui la
         * delega mantiene il cap storico 120. Fallback locale identico al
         * comportamento storico se il modulo notify non è caricato (ordine di
         * bootstrap: metrics carica PRIMA di notify).
         *
         * @param mixed $message Messaggio grezzo (string|WP_Error).
         * @return string
         */
        private static function brief_error( $message ) {
                if ( class_exists( 'Nexus_Notify' ) && method_exists( 'Nexus_Notify', 'brief_error' ) ) {
                        return Nexus_Notify::brief_error( $message, 120 );
                }

                $message = is_string( $message ) ? $message : '';
                $message = preg_replace( '#https?://[^\s]+#i', '[url]', $message );
                $message = trim( (string) sanitize_text_field( $message ) );

                if ( '' === $message ) {
                        return 'errore sconosciuto';
                }

                if ( strlen( $message ) > 120 ) {
                        $message = substr( $message, 0, 117 ) . '...';
                }

                return $message;
        }

        /**
         * Log su error_log solo quando WP_DEBUG è attivo (stesso pattern Nexus_Cron).
         *
         * @param string $message Messaggio.
         * @return void
         */
        private static function debug_log( $message ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[Nexus News AI][Metrics] ' . $message );
                }
        }

        /* ============ SEARCH QUERIES GSC (v1.1.14, P2) ============ */

        /**
         * Wrapper del ciclo di sync delle search queries GSC (chiamato da
         * do_sync in ENTRAMBI i rami: con e senza articoli recenti).
         *
         * NON lancia mai eccezioni (incassa Exception/Throwable nei contatori,
         * stesso contratto di run_sync): un fallimento del ciclo query non può
         * interrompere il resto della sincronizzazione né il cron. Se il
         * budget di tempo è già esaurito esce SENZA tentare (ran = false).
         *
         * @param string $token    Access token OAuth (mai loggato).
         * @param string $site_url Property Search Console (URL esatto).
         * @param float  $start_ts microtime(true) d'inizio run (budget condiviso).
         * @return array{ran:bool,rows:int,days:int,errors:int,last_error:string,truncated:int}
         */
        private static function maybe_sync_gsc_queries( $token, $site_url, $start_ts ) {
                $out = array(
                        'ran'        => false,
                        'rows'       => 0,
                        'days'       => 0,
                        'errors'     => 0,
                        'last_error' => '',
                        'truncated'  => 0,
                );

                if ( ! is_string( $token ) || '' === $token || ! is_string( $site_url ) || '' === $site_url ) {
                        return $out;
                }

                if ( self::budget_exceeded( $start_ts ) ) {
                        return $out;
                }

                try {
                        $out['ran'] = true;

                        // Un giorno per richiesta (dimension 'query', rowLimit
                        // dedicato per giorno): dal più recente al più vecchio,
                        // così il giorno più utile atterra per primo anche se il
                        // budget si esaurisce a metà ciclo.
                        for ( $offset = self::GSC_END_OFFSET; $offset <= self::GSC_START_OFFSET; $offset++ ) {
                                if ( self::budget_exceeded( $start_ts ) ) {
                                        break;
                                }

                                $date = gmdate( 'Y-m-d', strtotime( current_time( 'mysql' ) ) - $offset * DAY_IN_SECONDS );
                                $res  = self::fetch_gsc_query_rows( $token, $site_url, $date );

                                $out['days']++;

                                if ( '' !== $res['error'] ) {
                                        $out['errors']++;
                                        $out['last_error'] = $res['error'];
                                        continue;
                                }

                                if ( ! empty( $res['truncated'] ) ) {
                                        $out['truncated'] = 1;
                                }

                                // v1.1.18 (FIX-10): upsert BATCHED per giorno
                                // (INSERT multi-row a chunk QUERY_UPSERT_CHUNK =
                                // 500, pattern upsert_metric_rows) al posto
                                // dell'upsert riga-per-riga (~3000 round-trip
                                // DB a run sui siti con traffico): stesso
                                // risultato, al più 6 query. Le righe arrivano
                                // già normalizzate da fetch_gsc_query_rows (trim
                                // + clip 191 + absint/round); il batch le
                                // ritratta per difesa (stesse normalizzazioni).
                                $rows_day  = is_array( $res['rows'] ) ? $res['rows'] : array();
                                $res_write = self::upsert_search_query_rows( $rows_day, $date );

                                $out['rows'] += $res_write['written'];

                                if ( $res_write['failed'] > 0 ) {
                                        $out['errors']++;
                                        $out['last_error'] = 'Scrittura query GSC fallita (DB).';
                                }
                        }
                } catch ( Exception $e ) {
                        $out['errors']++;
                        $out['last_error'] = self::brief_error( $e->getMessage() );
                } catch ( Throwable $e ) {
                        $out['errors']++;
                        $out['last_error'] = self::brief_error( $e->getMessage() );
                }

                return $out;
        }

        /**
         * Suffisso additivo del log finale per il ciclo query (chiamato SOLO
         * quando ran = true): ' — query GSC: N righe (giorni D[, troncate a
         * rowLimit X])' + eventuale nota errori (ultimo messaggio breve).
         *
         * @param array $q Contatori ritornati da maybe_sync_gsc_queries().
         * @return string
         */
        private static function queries_log_suffix( $q ) {
                $suffix = ' — query GSC: ' . (int) ( isset( $q['rows'] ) ? $q['rows'] : 0 )
                        . ' righe (giorni ' . (int) ( isset( $q['days'] ) ? $q['days'] : 0 );

                if ( ! empty( $q['truncated'] ) ) {
                        $suffix .= ', troncate a rowLimit ' . self::GSC_ROW_LIMIT;
                }

                $suffix .= ')';

                $errors = isset( $q['errors'] ) ? (int) $q['errors'] : 0;

                if ( $errors > 0 ) {
                        $suffix .= ' — errori query: ' . $errors . ' (ultimo: ' . (string) ( isset( $q['last_error'] ) ? $q['last_error'] : '' ) . ')';
                }

                return $suffix;
        }

        /**
         * Richiesta GSC Search Analytics per UN singolo giorno con dimension
         * 'query' (stesso endpoint di fetch_gsc_rows): righe sito-wide
         * (query, clicks, impressions, position) senza info di pagina.
         *
         * rowLimit = GSC_ROW_LIMIT per richiesta: avendo UN giorno per
         * richiesta il cap copre fino a 1000 query/giorno (la richiesta unica
         * con dimensioni date+query condividerebbe lo stesso cap su 3 giorni).
         *
         * @param string $token    Access token OAuth (mai loggato).
         * @param string $site_url Property Search Console (URL esatto).
         * @param string $date     Giorno richiesto (Y-m-d, già validato).
         * @return array { rows: array, truncated: bool, error: string } — error '' = OK;
         *               rows: lista { query: string, clicks: int, impressions: int, position: float }.
         */
        private static function fetch_gsc_query_rows( $token, $site_url, $date ) {
                $body = wp_json_encode(
                        array(
                                'startDate'  => $date,
                                'endDate'    => $date,
                                'dimensions' => array( 'query' ),
                                'rowLimit'   => self::GSC_ROW_LIMIT,
                        )
                );

                $args = array(
                        'timeout' => self::HTTP_TIMEOUT,
                        'headers' => array(
                                'Authorization' => 'Bearer ' . $token,
                                'Content-Type'  => 'application/json; charset=utf-8',
                        ),
                        'body'    => $body,
                );

                // v1.1.15 (WE4): stesso trattamento di fetch_gsc_rows/fetch_ga4_rows
                // (retry transitorio + re-accesso token su 401) per coerenza: il ciclo
                // query è 3 richieste a run, la stessa fragilità va coperta uguale.
                $response = self::google_post_with_recovery(
                        self::GSC_ENDPOINT . rawurlencode( $site_url ) . '/searchAnalytics/query',
                        $args,
                        $token
                );

                if ( is_wp_error( $response ) ) {
                        return array(
                                'rows'      => array(),
                                'truncated' => false,
                                'error'     => 'Search Console query: ' . self::brief_error( $response->get_error_message() ),
                        );
                }

                $code = (int) wp_remote_retrieve_response_code( $response );

                if ( $code < 200 || $code >= 300 ) {
                        return array(
                                'rows'      => array(),
                                'truncated' => false,
                                'error'     => 'Search Console query: risposta HTTP ' . $code,
                        );
                }

                $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

                if ( ! is_array( $data ) ) {
                        return array(
                                'rows'      => array(),
                                'truncated' => false,
                                'error'     => 'Search Console query: risposta non valida.',
                        );
                }

                $rows = array();

                if ( isset( $data['rows'] ) && is_array( $data['rows'] ) ) {
                        foreach ( $data['rows'] as $row ) {
                                // Con dimension 'query': keys[0] = stringa di ricerca.
                                if ( ! is_array( $row ) || ! isset( $row['keys'][0] ) ) {
                                        continue;
                                }

                                // Trim + clip a 191 caratteri: la colonna query è
                                // VARCHAR(191) (entra nell'indice UNIQUE su utf8mb4).
                                $query = trim( (string) $row['keys'][0] );

                                if ( '' === $query ) {
                                        continue;
                                }

                                if ( function_exists( 'mb_substr' ) ) {
                                        $query = mb_substr( $query, 0, 191 );
                                } else {
                                        $query = substr( $query, 0, 191 );
                                }

                                $rows[] = array(
                                        'query'       => $query,
                                        'clicks'      => isset( $row['clicks'] ) ? absint( $row['clicks'] ) : 0,
                                        'impressions' => isset( $row['impressions'] ) ? absint( $row['impressions'] ) : 0,
                                        'position'    => isset( $row['position'] ) ? round( (float) $row['position'], 2 ) : 0.0,
                                );
                        }
                }

                // rowLimit raggiunto: probabilmente ci sono più query oltre la
                // prima pagina (nota a log, nessuna richiesta aggiuntiva).
                $truncated = ( count( $rows ) >= self::GSC_ROW_LIMIT );

                return array(
                        'rows'      => $rows,
                        'truncated' => $truncated,
                        'error'     => '',
                );
        }

        /**
         * v1.1.18 (FIX-10) — upsert BATCHED di righe search query su
         * wp_nexus_search_queries (sostituisce upsert_search_query_row,
         * rimossa: era usata solo dal ciclo riga-per-riga della sync).
         *
         * INSERT multi-row "VALUES (...),(...)" ... ON DUPLICATE KEY UPDATE
         * sulla UNIQUE query_day (query, query_date), a chunk di
         * QUERY_UPSERT_CHUNK (500) righe: 3 giorni × rowLimit GSC_ROW_LIMIT
         * (1000) → al più 6 round-trip DB a run (prima: UNA query per riga).
         * Re-run della sync sovrascrive i valori del giorno senza duplicare
         * righe; synced_at = now (locale). La colonna top_url NON è scritta:
         * la risposta query-dimension non include pagine → resta NULL
         * (onestà del dato; nessun URL inventato).
         *
         * SICUREZZA (stesso pattern di upsert_metric_rows): placeholder
         * completi per OGNI riga (%s/%d/%f) costruiti dinamicamente e passati
         * a $wpdb->prepare con l'array dei valori; la query viene ritrimmata
         * e riclippata a 191 caratteri (difesa: la colonna query è
         * VARCHAR(191) per entrare nell'indice UNIQUE su utf8mb4); le righe
         * malformate (non array o query vuota) vengono scartate. La tabella
         * arriva da search_queries_table() (allowlist interna Nexus_DB, mai
         * input utente).
         *
         * Nota budget: il check budget_exceeded resta tra le RICHIESTE HTTP
         * giornaliere (nel chiamante): la scrittura batched è O(6) query per
         * run e non consuma budget misurabile.
         *
         * Nota onesta: $wpdb->query su ON DUPLICATE KEY UPDATE ritorna 1 per
         * INSERT e 2 per UPDATE — le righe scritte sono contate con
         * count($placeholders) e NON col valore di ritorno di query(); un
         * chunk fallito (false) conta come 'failed' e i suoi errori emergono
         * dal log del chiamante.
         *
         * @param array  $rows       Righe normalizzate da fetch_gsc_query_rows:
         *                           ognuna { query: string, clicks: int, impressions: int, position: float }.
         * @param string $query_date Giorno locale (Y-m-d, già validato).
         * @return array{written:int, failed:int} written = righe incluse in chunk
         *                                      eseguiti con successo; failed = righe
         *                                      incluse in chunk falliti (le malformate
         *                                      escluse da entrambi i contatori).
         */
        private static function upsert_search_query_rows( array $rows, $query_date ) {
                global $wpdb;

                $result = array(
                        'written' => 0,
                        'failed'  => 0,
                );

                if ( empty( $rows ) ) {
                        return $result;
                }

                $table = self::search_queries_table();
                $now   = current_time( 'mysql' );

                foreach ( array_chunk( $rows, self::QUERY_UPSERT_CHUNK ) as $chunk ) {
                        $placeholders = array();
                        $params       = array();

                        foreach ( $chunk as $row ) {
                                if ( ! is_array( $row ) || ! isset( $row['query'] ) ) {
                                        continue;
                                }

                                // Trim + clip a 191: colonna query VARCHAR(191)
                                // (entra nell'indice UNIQUE su utf8mb4) — stessa
                                // normalizzazione di fetch_gsc_query_rows,
                                // ripetuta qui per difesa.
                                $query = trim( (string) $row['query'] );

                                if ( '' === $query ) {
                                        continue;
                                }

                                if ( function_exists( 'mb_substr' ) ) {
                                        $query = mb_substr( $query, 0, 191 );
                                } else {
                                        $query = substr( $query, 0, 191 );
                                }

                                $placeholders[] = '( %s, %s, %d, %d, %f, %s )';

                                $params[] = $query;
                                $params[] = (string) $query_date;
                                $params[] = absint( isset( $row['clicks'] ) ? $row['clicks'] : 0 );
                                $params[] = absint( isset( $row['impressions'] ) ? $row['impressions'] : 0 );
                                $params[] = round( (float) ( isset( $row['position'] ) ? $row['position'] : 0 ), 2 );
                                $params[] = $now;
                        }

                        if ( empty( $placeholders ) ) {
                                continue;
                        }

                        $sql = "INSERT INTO {$table} ( query, query_date, clicks, impressions, position, synced_at ) "
                                . 'VALUES ' . implode( ', ', $placeholders ) . ' '
                                . 'ON DUPLICATE KEY UPDATE clicks = VALUES( clicks ), impressions = VALUES( impressions ), '
                                . 'position = VALUES( position ), synced_at = VALUES( synced_at )';

                        $ok = $wpdb->query(
                                $wpdb->prepare(
                                        $sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholder completi per ogni riga, tabella da allowlist interna.
                                        $params
                                )
                        );

                        if ( false !== $ok ) {
                                $result['written'] += count( $placeholders );
                        } else {
                                $result['failed'] += count( $placeholders );
                        }
                }

                return $result;
        }

        /**
         * Nome tabella search queries, con guardie sulle API di Nexus_DB
         * (stesso pattern di metrics_table()): Nexus_DB::tables()['search_queries']
         * con fallback su $wpdb->prefix . 'nexus_search_queries'.
         *
         * @return string
         */
        private static function search_queries_table() {
                global $wpdb;

                if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'tables' ) ) {
                        $tables = Nexus_DB::tables();
                        if ( isset( $tables['search_queries'] ) && is_string( $tables['search_queries'] ) && '' !== $tables['search_queries'] ) {
                                return $tables['search_queries'];
                        }
                }

                return $wpdb->prefix . 'nexus_search_queries';
        }
}

// v1.1.12: init self-registrato sull'action del bootstrap (nessuna modifica al file principale).
add_action( 'nexus_modules_init', array( 'Nexus_Metrics', 'init' ) );

// v1.1.14 (P2) — GLUE (debito documentato in testa al file): il modulo
// opportunità di ricerca (endpoint REST "assegna a un agente" + dati della
// sezione Analytics) non può toccare il bootstrap (perimetro del task): viene
// richiesto qui, PRIMA di plugins_loaded, così la classe è definita sia per
// le rotte REST sia per il render della vista. Al prossimo integratore:
// spostare QUESTA riga in nexus-news-ai.php (dopo class-nexus-metrics.php).
require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-opportunities.php';
