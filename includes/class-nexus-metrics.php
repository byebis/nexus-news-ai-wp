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
 * LIMITI PER RUN:
 *  - max 50 post (posts_per_page hard cap, articoli pubblicati negli ultimi 7 giorni);
 *  - budget di tempo 60 secondi (check nel loop con microtime: il cron finisce SEMPRE
 *    pulito, anche a metà lavoro — il completamento avviene al giro successivo);
 *  - timeout wp_remote 15 secondi;
 *  - 1 richiesta GSC a run + max 1 richiesta GA4 per post (matching esatto permalink;
 *    se il permalink non è risolvibile il post viene saltato con contatore skip).
 *
 * SEMANTICA GSC:
 * L'API Search Analytics viene interrogata UNA volta a run con dimensione 'date'
 * (aggregati sito-wide per giorno, finestra oggi-4 → oggi-2: GSC ha lag sui dati).
 * Ogni riga giornaliera viene attribuita a ciascun post recente sincronizzato
 * (una riga per post+giorno, UNIQUE post_metric idempotente all'upsert).
 *
 * NESSUN DATO SENSIBILE NEI LOG: il token OAuth non viene MAI scritto in activity
 * log o error_log; i messaggi d'errore sono brevi e senza URL completi (brief_error()).
 * Nessuna eccezione non catturata: run_sync() incassa Exception/Throwable e logga.
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
         * Finestra dati GA4: start = oggi - 3 giorni (ultimi 3 giorni completi,
         * lag GA4 inferiore a quello di GSC).
         */
        const GA4_START_OFFSET = 3;

        /**
         * Finestra dati GA4: end = oggi - 1 giorno.
         */
        const GA4_END_OFFSET = 1;

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
         * 1 richiesta GSC → per post: attribuzione righe GSC + richiesta GA4 →
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
                        self::log_activity( 'Metriche sincronizzate: 0 righe (gsc 0, ga4 0) — nessun articolo recente.', 'info' );
                        return;
                }

                $start_ts   = microtime( true );
                $rows_gsc   = 0;
                $rows_ga4   = 0;
                $errors     = 0;
                $last_error = '';
                $skipped    = 0;
                $budget_hit = false;

                // GSC: UNA sola richiesta a run, righe giornaliere sito-wide.
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
                                        $gsc_rows = $gsc_res['rows'];
                                }
                        }
                }

                // Per post: attribuzione righe GSC + richiesta GA4 (matching permalink).
                foreach ( $post_ids as $post_id ) {
                        if ( self::budget_exceeded( $start_ts ) ) {
                                $budget_hit = true;
                                break;
                        }

                        $permalink = get_permalink( $post_id );

                        if ( ! is_string( $permalink ) || '' === $permalink ) {
                                // Permalink non risolvibile: matching GA4 impossibile → salta il post.
                                $skipped++;
                                continue;
                        }

                        // GSC: una riga per post+giorno (UNIQUE post_metric, idempotente).
                        foreach ( $gsc_rows as $row ) {
                                $written = self::upsert_metric_row(
                                        $post_id,
                                        $row['date'],
                                        'gsc',
                                        $row['clicks'],
                                        $row['impressions'],
                                        $row['position'],
                                        0
                                );

                                if ( $written ) {
                                        $rows_gsc++;
                                } else {
                                        $errors++;
                                        $last_error = 'Scrittura metrica GSC fallita (DB).';
                                }
                        }

                        if ( ! $has_ga4 ) {
                                continue;
                        }

                        if ( self::budget_exceeded( $start_ts ) ) {
                                $budget_hit = true;
                                break;
                        }

                        $ga4_res = self::fetch_ga4_rows( $token, $ga4_prop, $permalink );

                        if ( '' !== $ga4_res['error'] ) {
                                $errors++;
                                $last_error = $ga4_res['error'];
                                continue;
                        }

                        foreach ( $ga4_res['rows'] as $row ) {
                                $written = self::upsert_metric_row( $post_id, $row['date'], 'ga4', 0, 0, 0.0, $row['views'] );

                                if ( $written ) {
                                        $rows_ga4++;
                                } else {
                                        $errors++;
                                        $last_error = 'Scrittura metrica GA4 fallita (DB).';
                                }
                        }
                }

                // Log finale: template richiesto, con aggiunte brevi solo se servono.
                $total  = $rows_gsc + $rows_ga4;
                $detail = 'Metriche sincronizzate: ' . $total . ' righe (gsc ' . $rows_gsc . ', ga4 ' . $rows_ga4 . ')';

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

                self::log_activity( $detail, $status );
        }

        /* ============ API REMOTE ============ */

        /**
         * Richiesta GSC Search Analytics (dimensione 'date', ultimi 3 giorni
         * completi con lag GSC: oggi-4 → oggi-2).
         *
         * @param string $token    Access token OAuth (mai loggato).
         * @param string $site_url Property Search Console (URL esatto).
         * @return array { rows: array, error: string } — error '' = OK.
         */
        private static function fetch_gsc_rows( $token, $site_url ) {
                $now_local = strtotime( current_time( 'mysql' ) );

                $body = wp_json_encode(
                        array(
                                'startDate'  => gmdate( 'Y-m-d', $now_local - self::GSC_START_OFFSET * DAY_IN_SECONDS ),
                                'endDate'    => gmdate( 'Y-m-d', $now_local - self::GSC_END_OFFSET * DAY_IN_SECONDS ),
                                'dimensions' => array( 'date' ),
                        )
                );

                $response = wp_remote_post(
                        self::GSC_ENDPOINT . rawurlencode( $site_url ) . '/searchAnalytics/query',
                        array(
                                'timeout' => self::HTTP_TIMEOUT,
                                'headers' => array(
                                        'Authorization' => 'Bearer ' . $token,
                                        'Content-Type'  => 'application/json; charset=utf-8',
                                ),
                                'body'    => $body,
                        )
                );

                if ( is_wp_error( $response ) ) {
                        return array(
                                'rows'  => array(),
                                'error' => 'Search Console: ' . self::brief_error( $response->get_error_message() ),
                        );
                }

                $code = (int) wp_remote_retrieve_response_code( $response );

                if ( $code < 200 || $code >= 300 ) {
                        return array(
                                'rows'  => array(),
                                'error' => 'Search Console: risposta HTTP ' . $code,
                        );
                }

                $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

                if ( ! is_array( $data ) ) {
                        return array(
                                'rows'  => array(),
                                'error' => 'Search Console: risposta non valida.',
                        );
                }

                $rows = array();

                if ( isset( $data['rows'] ) && is_array( $data['rows'] ) ) {
                        foreach ( $data['rows'] as $row ) {
                                if ( ! is_array( $row ) || ! isset( $row['keys'][0] ) ) {
                                        continue;
                                }

                                $date = (string) $row['keys'][0];

                                if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
                                        continue;
                                }

                                $rows[] = array(
                                        'date'        => $date,
                                        'clicks'      => isset( $row['clicks'] ) ? absint( $row['clicks'] ) : 0,
                                        'impressions' => isset( $row['impressions'] ) ? absint( $row['impressions'] ) : 0,
                                        'position'    => isset( $row['position'] ) ? round( (float) $row['position'], 2 ) : 0.0,
                                );
                        }
                }

                return array(
                        'rows'  => $rows,
                        'error' => '',
                );
        }

        /**
         * Richiesta GA4 runReport per UN post (matching esatto sul permalink,
         * dimensione 'date', metrica screenPageViews; ultimi 3 giorni completi).
         *
         * @param string $token     Access token OAuth (mai loggato).
         * @param string $property  Property ID GA4 (solo cifre).
         * @param string $permalink Permalink del post (valore EXACT su pagePath).
         * @return array { rows: array, error: string } — error '' = OK.
         */
        private static function fetch_ga4_rows( $token, $property, $permalink ) {
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
                                ),
                                'metrics'    => array(
                                        array( 'name' => 'screenPageViews' ),
                                ),
                                'dimensionFilter' => array(
                                        'filter' => array(
                                                'fieldName'    => 'pagePath',
                                                'stringFilter' => array(
                                                        'matchType' => 'EXACT',
                                                        'value'     => $permalink,
                                                ),
                                        ),
                                ),
                        )
                );

                $response = wp_remote_post(
                        self::GA4_ENDPOINT . rawurlencode( $property ) . ':runReport',
                        array(
                                'timeout' => self::HTTP_TIMEOUT,
                                'headers' => array(
                                        'Authorization' => 'Bearer ' . $token,
                                        'Content-Type'  => 'application/json; charset=utf-8',
                                ),
                                'body'    => $body,
                        )
                );

                if ( is_wp_error( $response ) ) {
                        return array(
                                'rows'  => array(),
                                'error' => 'GA4: ' . self::brief_error( $response->get_error_message() ),
                        );
                }

                $code = (int) wp_remote_retrieve_response_code( $response );

                if ( $code < 200 || $code >= 300 ) {
                        return array(
                                'rows'  => array(),
                                'error' => 'GA4: risposta HTTP ' . $code,
                        );
                }

                $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

                if ( ! is_array( $data ) ) {
                        return array(
                                'rows'  => array(),
                                'error' => 'GA4: risposta non valida.',
                        );
                }

                $rows = array();

                if ( isset( $data['rows'] ) && is_array( $data['rows'] ) ) {
                        foreach ( $data['rows'] as $row ) {
                                if ( ! is_array( $row ) ) {
                                        continue;
                                }

                                // GA4 restituisce la data come YYYYMMDD: normalizza a YYYY-MM-DD.
                                $raw_date = isset( $row['dimensionValues'][0]['value'] ) ? (string) $row['dimensionValues'][0]['value'] : '';
                                $views    = isset( $row['metricValues'][0]['value'] ) ? absint( $row['metricValues'][0]['value'] ) : 0;
                                $date     = '';

                                if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $raw_date, $m ) ) {
                                        $date = $m[1] . '-' . $m[2] . '-' . $m[3];
                                } elseif ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw_date ) ) {
                                        $date = $raw_date;
                                }

                                if ( '' === $date ) {
                                        continue;
                                }

                                $rows[] = array(
                                        'date'  => $date,
                                        'views' => $views,
                                );
                        }
                }

                return array(
                        'rows'  => $rows,
                        'error' => '',
                );
        }

        /* ============ PERSISTENZA ============ */

        /**
         * Upsert di una riga metrica su wp_nexus_metrics.
         *
         * INSERT ... ON DUPLICATE KEY UPDATE sulla UNIQUE post_metric
         * (post_id, metric_date, source): re-run della sync sovrascrive i
         * valori del giorno senza duplicare righe. synced_at = now (locale).
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
                global $wpdb;

                $table = self::metrics_table();

                $sql = "INSERT INTO {$table} ( post_id, metric_date, source, clicks, impressions, position, views, synced_at ) "
                        . 'VALUES ( %d, %s, %s, %d, %d, %f, %d, %s ) '
                        . 'ON DUPLICATE KEY UPDATE clicks = VALUES( clicks ), impressions = VALUES( impressions ), '
                        . 'position = VALUES( position ), views = VALUES( views ), synced_at = VALUES( synced_at )';

                $result = $wpdb->query(
                        $wpdb->prepare(
                                $sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholder completi, tabella da allowlist interna.
                                absint( $post_id ),
                                $metric_date,
                                sanitize_key( $source ),
                                absint( $clicks ),
                                absint( $impressions ),
                                round( (float) $position, 2 ),
                                absint( $views ),
                                current_time( 'mysql' )
                        )
                );

                return false !== $result;
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
         * @return array
         */
        private static function get_settings() {
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
         * @param mixed $message Messaggio grezzo (string|WP_Error message).
         * @return string
         */
        private static function brief_error( $message ) {
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
}

// v1.1.12: init self-registrato sull'action del bootstrap (nessuna modifica al file principale).
add_action( 'nexus_modules_init', array( 'Nexus_Metrics', 'init' ) );
