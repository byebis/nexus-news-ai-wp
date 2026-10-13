<?php
/**
 * Nexus Analytics — statistiche reali della redazione AI (v1.0.4 "Scala & Pro").
 *
 * CONTRATTO PUBBLICO:
 * Nexus_Analytics::overview( $days = 30 ) — array aggregato in SOLA LETTURA
 * (nessuna scrittura su DB, nessuna dipendenza esterna):
 *
 *   array(
 *     'days'         => int,  // 7..90, valore effettivo dopo il clamp
 *     'generated_at' => string, // current_time('mysql') del momento della chiamata
 *
 *     // Serie giornaliera: UNA riga per giorno, ordine cronologico, giorni senza
 *     // dati riempiti a zero. Le viste non sono storicizzate per giorno → omissione.
 *     'series' => array(
 *         array( 'date' => 'Y-m-d', 'created' => int, 'published' => int,
 *                'ai_calls' => int, 'ai_cost' => float, 'jobs_done' => int,
 *                'jobs_failed' => int ), ...
 *     ),
 *
 *     // Totali del periodo, sommando la serie. ECCEZIONE: 'views' è la SOMMA
 *     // ATTUALE e GLOBALE di articles.views — è un contatore CUMULATIVO di sempre,
 *     // NON la somma del periodo (le viste giornaliere non sono disponibili nel DB).
 *     'totals' => array( 'created', 'published', 'views', 'ai_calls', 'ai_cost',
 *                        'jobs_complete', 'jobs_failed' ),
 *
 *     // Da publish_logs.created_at nel periodo, ordinato per published DESC.
 *     'per_platform' => array( array( 'platform' => string, 'published' => int,
 *                                     'failed' => int ), ... ),
 *
 *     // Da articles.created_at nel periodo, LEFT JOIN agents (copre anche
 *     // agent_id = 0), ordinato per created DESC; name fallback 'Agente #id'.
 *     'per_agent' => array( array( 'agent_id' => int, 'name' => string,
 *                                  'avatar' => string, 'category' => string,
 *                                  'created' => int, 'published' => int,
 *                                  'avg_quality' => float ), ... ),
 *
 *     // Top 10 articoli per views (globale, coerente col contatore cumulativo);
 *     // reactions_count = numero di chiavi del JSON reactions; title troncato a 80.
 *     'top_articles' => array( array( 'id' => int, 'title' => string,
 *                                     'views' => int, 'reactions_count' => int,
 *                                     'status' => string, 'agent_name' => string ), ... ),
 *
 *     // Contatori iscritti: stati attuali + nuovi iscritti nel periodo.
 *     'subscribers' => array( 'confirmed' => int, 'pending' => int,
 *                             'unsubscribed' => int, 'new_in_period' => int ),
 *   )
 *
 * CONSUMA: Nexus_DB::tables() (fondamenta, guard class_exists/method_exists con
 * degradazione grazia a struttura vuota se il layer dati non è ancora integrato).
 * USATA DA: rotta REST GET nexus/v1/analytics (class-nexus-rest.php) e vista
 * admin/views/analytics.php (self-contained, fetch client-side).
 *
 * Dalla 1.1.13.1 (task Q1) espone anche Nexus_Analytics::insight( $days ): dati
 * REALI dalla tabella nexus_metrics (schema v4: una riga per post+giorno+sorgente,
 * 'gsc' = clicks/impressions/position, 'ga4' = views) per la card "Insight
 * performance" della pagina Analytics, renderizzata SERVER-SIDE (nessuna rotta
 * REST nuova). Stati onesti: Google non connesso / sync non ancora arrivato /
 * dati reali. Zero numeri inventati.
 *
 * Regole: PHP 7.4, query di sola lettura con $wpdb->prepare dove ci sono
 * parametri (le costanti di tempo sono inline), tabelle via Nexus_DB::tables(),
 * current_time('mysql') per coerenza col resto del plugin, costi arrotondati a
 * 4 decimali, commenti e valori UI in italiano.
 *
 * @package Nexus_News_AI
 * @since   1.0.4
 * @since   1.1.13.1 Insight performance da nexus_metrics (task Q1).
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Analytics {

        /* Finestre selezionabili della card "Insight performance" (1.1.13.1 Q1):
         * whitelist server-side, default 30 giorni. */
        const INSIGHT_WINDOWS = array( 7, 30, 90 );

        /* Righe della classifica articoli. */
        const INSIGHT_TOP_LIMIT = 5;

        /**
         * Bootstrap riservato a hook futuri: attualmente nessuna operazione
         * è necessaria (la classe è chiamata on-demand da REST/vista).
         *
         * @return void
         */
        public static function init() {
                // no-op: riservato a hook futuri.
        }

        /**
         * Panoramica analytics degli ultimi N giorni (cap 90, min 7).
         *
         * @param int $days Numero di giorni della finestra (default 30).
         * @return array Struttura documentata nel docblock di testa.
         */
        public static function overview( $days = 30 ) {
                $days = min( 90, max( 7, absint( $days ) ) );
                $now  = current_time( 'mysql' );

                // Degradazione grazia: fondamenta DB non integrate → struttura vuota coerente.
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'tables' ) ) {
                        return self::empty_overview( $days, $now );
                }

                global $wpdb;
                $t      = Nexus_DB::tables();
                $now_ts = strtotime( $now );
                $start  = gmdate( 'Y-m-d 00:00:00', $now_ts - ( $days - 1 ) * DAY_IN_SECONDS );

                /* ============ SERIE GIORNALIERA (una query per tabella/colonna) ============ */

                // Articoli creati per giorno (articles.created_at).
                $map_created = array();
                $rows = (array) $wpdb->get_results( $wpdb->prepare(
                        "SELECT DATE(created_at) AS d, COUNT(*) AS c FROM {$t['articles']} WHERE created_at >= %s GROUP BY DATE(created_at)",
                        $start
                ) );
                foreach ( $rows as $r ) {
                        $map_created[ (string) $r->d ] = (int) $r->c;
                }

                // Articoli pubblicati per giorno (articles.published_at NOT NULL).
                $map_published = array();
                $rows = (array) $wpdb->get_results( $wpdb->prepare(
                        "SELECT DATE(published_at) AS d, COUNT(*) AS c FROM {$t['articles']} WHERE published_at IS NOT NULL AND published_at >= %s GROUP BY DATE(published_at)",
                        $start
                ) );
                foreach ( $rows as $r ) {
                        $map_published[ (string) $r->d ] = (int) $r->c;
                }

                // Chiamate AI e costo per giorno (usage_logs.created_at / cost).
                $map_calls = array();
                $map_cost  = array();
                $rows = (array) $wpdb->get_results( $wpdb->prepare(
                        "SELECT DATE(created_at) AS d, COUNT(*) AS c, COALESCE(SUM(cost), 0) AS cost FROM {$t['usage_logs']} WHERE created_at >= %s GROUP BY DATE(created_at)",
                        $start
                ) );
                foreach ( $rows as $r ) {
                        $map_calls[ (string) $r->d ] = (int) $r->c;
                        $map_cost[ (string) $r->d ]  = (float) $r->cost;
                }

                // Lavori coda completati/falliti per giorno (jobs.completed_at per status).
                $map_jobs_done   = array();
                $map_jobs_failed = array();
                $rows = (array) $wpdb->get_results( $wpdb->prepare(
                        "SELECT DATE(completed_at) AS d, status, COUNT(*) AS c FROM {$t['jobs']} WHERE completed_at IS NOT NULL AND completed_at >= %s AND status IN ( 'complete', 'failed' ) GROUP BY DATE(completed_at), status",
                        $start
                ) );
                foreach ( $rows as $r ) {
                        if ( 'complete' === (string) $r->status ) {
                                $map_jobs_done[ (string) $r->d ] = (int) $r->c;
                        } elseif ( 'failed' === (string) $r->status ) {
                                $map_jobs_failed[ (string) $r->d ] = (int) $r->c;
                        }
                }

                // Riempimento serie in PHP: una riga per giorno, ordine cronologico, zeri dove mancano dati.
                $series = array();
                for ( $i = $days - 1; $i >= 0; $i-- ) {
                        $key     = gmdate( 'Y-m-d', $now_ts - $i * DAY_IN_SECONDS );
                        $series[] = array(
                                'date'        => $key,
                                'created'     => isset( $map_created[ $key ] ) ? $map_created[ $key ] : 0,
                                'published'   => isset( $map_published[ $key ] ) ? $map_published[ $key ] : 0,
                                'ai_calls'    => isset( $map_calls[ $key ] ) ? $map_calls[ $key ] : 0,
                                'ai_cost'     => round( isset( $map_cost[ $key ] ) ? $map_cost[ $key ] : 0.0, 4 ),
                                'jobs_done'   => isset( $map_jobs_done[ $key ] ) ? $map_jobs_done[ $key ] : 0,
                                'jobs_failed' => isset( $map_jobs_failed[ $key ] ) ? $map_jobs_failed[ $key ] : 0,
                        );
                }

                /* ============ TOTALI (somma della serie; views a parte) ============ */

                $tot_created   = 0;
                $tot_published = 0;
                $tot_calls     = 0;
                $tot_cost      = 0.0;
                $tot_jobs_done = 0;
                $tot_jobs_fail = 0;
                foreach ( $series as $row ) {
                        $tot_created   += $row['created'];
                        $tot_published += $row['published'];
                        $tot_calls     += $row['ai_calls'];
                        $tot_cost      += $row['ai_cost'];
                        $tot_jobs_done += $row['jobs_done'];
                        $tot_jobs_fail += $row['jobs_failed'];
                }

                // NOTA: viste cumulativo ATTUALE (globale, non di periodo) — vedi docblock di testa.
                $total_views = (int) $wpdb->get_var( "SELECT COALESCE(SUM(views), 0) FROM {$t['articles']}" );

                /* ============ PER PIATTAFORMA (publish_logs nel periodo) ============ */

                $per_platform = array();
                $rows = (array) $wpdb->get_results( $wpdb->prepare(
                        "SELECT platform, SUM( CASE WHEN status = 'published' THEN 1 ELSE 0 END ) AS published, SUM( CASE WHEN status = 'failed' THEN 1 ELSE 0 END ) AS failed FROM {$t['publish_logs']} WHERE created_at >= %s GROUP BY platform ORDER BY published DESC",
                        $start
                ) );
                foreach ( $rows as $r ) {
                        $per_platform[] = array(
                                'platform'  => (string) $r->platform,
                                'published' => (int) $r->published,
                                'failed'    => (int) $r->failed,
                        );
                }

                /* ============ PER AGENTE (articles nel periodo, LEFT JOIN agents) ============ */

                $per_agent = array();
                $rows = (array) $wpdb->get_results( $wpdb->prepare(
                        "SELECT a.agent_id AS agent_id, COALESCE(g.name, '') AS name, COALESCE(g.avatar, '') AS avatar, COALESCE(g.category, '') AS category, COUNT(*) AS created, SUM( CASE WHEN a.published_at IS NOT NULL THEN 1 ELSE 0 END ) AS published, AVG(a.quality_score) AS avg_quality FROM {$t['articles']} AS a LEFT JOIN {$t['agents']} AS g ON g.id = a.agent_id WHERE a.created_at >= %s GROUP BY a.agent_id, g.name, g.avatar, g.category ORDER BY created DESC",
                        $start
                ) );
                foreach ( $rows as $r ) {
                        $name     = (string) $r->name;
                        $agent_id = (int) $r->agent_id;
                        $per_agent[] = array(
                                'agent_id'    => $agent_id,
                                'name'        => '' !== $name ? $name : 'Agente #' . $agent_id,
                                'avatar'      => (string) $r->avatar,
                                'category'    => (string) $r->category,
                                'created'     => (int) $r->created,
                                'published'   => (int) $r->published,
                                'avg_quality' => round( (float) $r->avg_quality, 1 ),
                        );
                }

                /* ============ TOP ARTICOLI (top 10 per views, globale cumulativo) ============ */

                $top_articles = array();
                $rows = (array) $wpdb->get_results( "SELECT a.id, a.title, a.views, a.reactions, a.status, COALESCE(g.name, '') AS agent_name FROM {$t['articles']} AS a LEFT JOIN {$t['agents']} AS g ON g.id = a.agent_id ORDER BY a.views DESC, a.id ASC LIMIT 10" );
                foreach ( $rows as $r ) {
                        $title     = trim( (string) $r->title );
                        $reactions = json_decode( (string) $r->reactions, true );
                        $top_articles[] = array(
                                'id'              => (int) $r->id,
                                'title'           => mb_strlen( $title ) > 80 ? mb_substr( $title, 0, 80 ) : $title,
                                'views'           => (int) $r->views,
                                'reactions_count' => is_array( $reactions ) ? count( $reactions ) : 0,
                                'status'          => (string) $r->status,
                                'agent_name'      => '' !== (string) $r->agent_name ? (string) $r->agent_name : '—',
                        );
                }

                /* ============ ISCRITTI NEWSLETTER ============ */

                $subscribers = array(
                        'confirmed'     => 0,
                        'pending'       => 0,
                        'unsubscribed'  => 0,
                        'new_in_period' => 0,
                );
                $rows = (array) $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$t['subscribers']} GROUP BY status" );
                foreach ( $rows as $r ) {
                        $status = (string) $r->status;
                        if ( isset( $subscribers[ $status ] ) ) {
                                $subscribers[ $status ] = (int) $r->c;
                        }
                }
                $subscribers['new_in_period'] = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$t['subscribers']} WHERE created_at >= %s",
                        $start
                ) );

                /* ============ RISULTATO ============ */

                return array(
                        'days'         => $days,
                        'generated_at' => $now,
                        'series'       => $series,
                        'totals'       => array(
                                'created'       => $tot_created,
                                'published'     => $tot_published,
                                'views'         => $total_views,
                                'ai_calls'      => $tot_calls,
                                'ai_cost'       => round( $tot_cost, 4 ),
                                'jobs_complete' => $tot_jobs_done,
                                'jobs_failed'   => $tot_jobs_fail,
                        ),
                        'per_platform' => $per_platform,
                        'per_agent'    => $per_agent,
                        'top_articles' => $top_articles,
                        'subscribers'  => $subscribers,
                );
        }

        /**
         * Struttura vuota coerente col contratto, usata quando il layer dati
         * (Nexus_DB) non è ancora disponibile. Nessun dato inventato: solo zeri.
         *
         * @param int    $days Giorni della finestra (già clampata).
         * @param string $now  Timestamp mysql corrente.
         * @return array
         */
        private static function empty_overview( $days, $now ) {
                $now_ts = strtotime( $now );
                $series = array();
                for ( $i = $days - 1; $i >= 0; $i-- ) {
                        $series[] = array(
                                'date'        => gmdate( 'Y-m-d', $now_ts - $i * DAY_IN_SECONDS ),
                                'created'     => 0,
                                'published'   => 0,
                                'ai_calls'    => 0,
                                'ai_cost'     => 0.0,
                                'jobs_done'   => 0,
                                'jobs_failed' => 0,
                        );
                }

                return array(
                        'days'         => $days,
                        'generated_at' => $now,
                        'series'       => $series,
                        'totals'       => array(
                                'created'       => 0,
                                'published'     => 0,
                                'views'         => 0,
                                'ai_calls'      => 0,
                                'ai_cost'       => 0.0,
                                'jobs_complete' => 0,
                                'jobs_failed'   => 0,
                        ),
                        'per_platform' => array(),
                        'per_agent'    => array(),
                        'top_articles' => array(),
                        'subscribers'  => array(
                                'confirmed'     => 0,
                                'pending'       => 0,
                                'unsubscribed'  => 0,
                                'new_in_period' => 0,
                        ),
                );
        }

        /* ==================================================================
         * INSIGHT PERFORMANCE (1.1.13.1, task Q1) — dati REALI nexus_metrics
         * ================================================================ */

        /**
         * Dati per la card "Insight performance" della pagina Analytics.
         *
         * CONSUMA la tabella nexus_metrics (schema v4: una riga per
         * post+giorno+sorgente — 'gsc' porta clicks/impressions/position,
         * 'ga4' porta views) con la STESSA disciplina delle query di
         * Nexus_Learn: range scan sull'indice metric_date (WHERE metric_date
         * >= start AND <= end), GROUP BY post_id, LIMIT, niente full-scan.
         * Tre query totali: classifica, totali finestra, totali periodo
         * precedente (per il trend semplice). Nessuna scrittura.
         *
         * CONTRATTO DI RITORNO (array in sola lettura):
         *   days      => int (uno di INSIGHT_WINDOWS, default 30)
         *   connected => bool (Nexus_Google_Auth::is_connected con guardie)
         *   has_data  => bool (true SOLO se esistono righe nel periodo)
         *   window    => array( start, end, prev_start, prev_end ) 'Y-m-d'
         *   totals    => array( clicks, impressions, views, avg_position )
         *   prev      => stessa struttura, per il periodo precedente uguale
         *   trend     => array( clicks, impressions, views, position ), ogni
         *                voce array( dir, delta, pct ): dir 'up'|'down'|
         *                'flat'|'new'|'none', delta assoluto, pct % vs periodo
         *                precedente (null se il precedente è 0). PER LA
         *                POSIZIONE la semantica è INVERTITA (più bassa =
         *                migliore): dir 'up' = posizione migliorata, delta e
         *                pct positivi = miglioramento.
         *   top       => array di massimo INSIGHT_TOP_LIMIT righe ordinate
         *                per click GSC, poi viste GA4: array( post_id, title,
         *                link, clicks, impressions, ctr, position, views ).
         *                ctr è null se impressions = 0 (niente 0% inventato);
         *                position è 0.0 quando non ci sono righe GSC.
         *
         * STATI ONESTI gestiti dalla vista: connected=false → CTA Impostazioni;
         * has_data=false → messaggio "sync ancora non arrivato"; zero righe
         * implica totals a zero SENZA percentuali finte.
         *
         * @param int $days Giorni della finestra (whitelist 7/30/90, default 30).
         * @return array Struttura documentata nel docblock.
         */
        public static function insight( $days = 30 ) {
                $days = self::normalize_insight_days( $days );

                $out = array(
                        'days'      => $days,
                        'connected' => self::google_connected(),
                        'has_data'  => false,
                        'window'    => array(
                                'start'      => '',
                                'end'        => '',
                                'prev_start' => '',
                                'prev_end'   => '',
                        ),
                        'totals'    => self::empty_metric_totals(),
                        'prev'      => self::empty_metric_totals(),
                        'trend'     => array(
                                'clicks'      => array( 'dir' => 'none', 'delta' => 0.0, 'pct' => null ),
                                'impressions' => array( 'dir' => 'none', 'delta' => 0.0, 'pct' => null ),
                                'views'       => array( 'dir' => 'none', 'delta' => 0.0, 'pct' => null ),
                                'position'    => array( 'dir' => 'none', 'delta' => 0.0, 'pct' => null ),
                        ),
                        'top'       => array(),
                );

                // Degradazione grazia: senza layer DB la card mostra lo stato
                // onesto (connesso sì/no + messaggio sync), zero numeri finti.
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'tables' ) ) {
                        return $out;
                }

                global $wpdb;
                $t = Nexus_DB::tables();
                if ( empty( $t['metrics'] ) || ! is_string( $t['metrics'] ) ) {
                        return $out;
                }
                $m = $t['metrics'];

                // metric_date è una DATE scritta dalla sync sul "giorno locale"
                // (stessa base current_time('mysql') di Nexus_Metrics): la
                // finestra segue la stessa convenzione.
                $now_ts   = strtotime( current_time( 'mysql' ) );
                $start_ts = $now_ts - ( $days - 1 ) * DAY_IN_SECONDS;
                $end      = gmdate( 'Y-m-d', $now_ts );
                $start    = gmdate( 'Y-m-d', $start_ts );
                $prev_end   = gmdate( 'Y-m-d', $start_ts - DAY_IN_SECONDS );
                $prev_start = gmdate( 'Y-m-d', $start_ts - $days * DAY_IN_SECONDS );

                $out['window'] = array(
                        'start'      => $start,
                        'end'        => $end,
                        'prev_start' => $prev_start,
                        'prev_end'   => $prev_end,
                );

                /* ============ TOP 5 ARTICOLI (indice metric_date + GROUP BY) ============ */

                // AVG ignora i NULL: la posizione media per articolo conta SOLO
                // le righe GSC con impressions > 0 (le righe 'ga4' hanno
                // position = 0 di default e inquinerebbero la media).
                $rows = (array) $wpdb->get_results( $wpdb->prepare(
                        "SELECT post_id,
                                COALESCE( SUM( clicks ), 0 ) AS clicks,
                                COALESCE( SUM( impressions ), 0 ) AS impressions,
                                COALESCE( SUM( views ), 0 ) AS views,
                                COALESCE( AVG( CASE WHEN source = 'gsc' AND impressions > 0 THEN position END ), 0 ) AS position
                        FROM {$m}
                        WHERE metric_date >= %s AND metric_date <= %s
                        GROUP BY post_id
                        ORDER BY clicks DESC, views DESC, post_id ASC
                        LIMIT %d",
                        $start,
                        $end,
                        self::INSIGHT_TOP_LIMIT
                ) );

                foreach ( $rows as $r ) {
                        $post_id     = (int) $r->post_id;
                        $clicks      = (int) $r->clicks;
                        $impressions = (int) $r->impressions;
                        $out['top'][] = array(
                                'post_id'     => $post_id,
                                'title'       => self::post_title( $post_id ),
                                'link'        => self::post_link( $post_id ),
                                'clicks'      => $clicks,
                                'impressions' => $impressions,
                                'ctr'         => $impressions > 0 ? round( ( $clicks / $impressions ) * 100, 1 ) : null,
                                'position'    => round( (float) $r->position, 1 ),
                                'views'       => (int) $r->views,
                        );
                }

                // Onestà: nessuna riga nel periodo → messaggio sync, i totali
                // restano a zero e la vista NON mostra percentuali inventate.
                if ( empty( $out['top'] ) ) {
                        return $out;
                }
                $out['has_data'] = true;

                /* ============ TOTALI FINESTRA + PERIODO PRECEDENTE ============ */

                $out['totals'] = self::metric_totals( $m, $start, $end );
                $out['prev']   = self::metric_totals( $m, $prev_start, $prev_end );
                $out['trend']  = self::metric_trend( $out['totals'], $out['prev'] );

                return $out;
        }

        /**
         * Finestra insight normalizzata: solo i valori della whitelist.
         *
         * @param int $days Valore richiesto.
         * @return int
         */
        private static function normalize_insight_days( $days ) {
                $days = absint( $days );
                if ( ! in_array( $days, self::INSIGHT_WINDOWS, true ) ) {
                        return 30;
                }
                return $days;
        }

        /**
         * Google connesso? Guardie class_exists/method_exists + try/catch:
         * la card non deve MAI rompere la pagina (convenzione Q1).
         *
         * @return bool
         */
        private static function google_connected() {
                if ( ! class_exists( 'Nexus_Google_Auth' ) || ! method_exists( 'Nexus_Google_Auth', 'is_connected' ) ) {
                        return false;
                }
                try {
                        return (bool) Nexus_Google_Auth::is_connected();
                } catch ( Throwable $e ) {
                        return false;
                }
        }

        /**
         * Struttura vuota coerente dei totali metriche (zero numeri finti).
         *
         * @return array
         */
        private static function empty_metric_totals() {
                return array(
                        'clicks'       => 0,
                        'impressions'  => 0,
                        'views'        => 0,
                        'avg_position' => 0.0,
                );
        }

        /**
         * Totali della finestra [start..end]: SOMMA delle colonne intere
         * (una riga per post+giorno+sorgente, quindi sommare senza GROUP BY
         * totalizza ogni metrica senza doppioni — stesso ragionamento di
         * Nexus_Learn::fetch_sources) + posizione media complessiva del sito
         * calcolata SOLO sulle righe GSC con impressions > 0.
         *
         * @param string $table Nome tabella metrics (Nexus_DB::tables()).
         * @param string $start Data inizio Y-m-d (inclusa).
         * @param string $end   Data fine Y-m-d (inclusa).
         * @return array
         */
        private static function metric_totals( $table, $start, $end ) {
                global $wpdb;
                $row = $wpdb->get_row( $wpdb->prepare(
                        "SELECT COALESCE( SUM( clicks ), 0 ) AS clicks,
                                COALESCE( SUM( impressions ), 0 ) AS impressions,
                                COALESCE( SUM( views ), 0 ) AS views,
                                COALESCE( AVG( CASE WHEN source = 'gsc' AND impressions > 0 THEN position END ), 0 ) AS avg_position
                        FROM {$table}
                        WHERE metric_date >= %s AND metric_date <= %s",
                        $start,
                        $end
                ) );
                $out = self::empty_metric_totals();
                if ( is_object( $row ) ) {
                        $out['clicks']       = (int) $row->clicks;
                        $out['impressions']  = (int) $row->impressions;
                        $out['views']        = (int) $row->views;
                        $out['avg_position'] = round( (float) $row->avg_position, 1 );
                }
                return $out;
        }

        /**
         * Trend semplice del periodo corrente vs precedente uguale.
         *
         * Per clicks/impressions/views la semantica è diretta ('up' = valore
         * salito). PER LA POSIZIONE è INVERTITA: 'up' = posizione migliorata
         * (valore sceso), delta/pct positivi = miglioramento — la vista
         * renderizza la freccia senza dover conoscere la metrica.
         *
         * @param array $cur  Totali finestra corrente (metric_totals()).
         * @param array $prev Totali periodo precedente (metric_totals()).
         * @return array
         */
        private static function metric_trend( $cur, $prev ) {
                $trend = array();
                foreach ( array( 'clicks', 'impressions', 'views' ) as $key ) {
                        $trend[ $key ] = self::trend_dir( isset( $cur[ $key ] ) ? $cur[ $key ] : 0, isset( $prev[ $key ] ) ? $prev[ $key ] : 0 );
                }

                // Posizione media del sito: semantica invertita (vedi docblock).
                $pos_prev = isset( $prev['avg_position'] ) ? (float) $prev['avg_position'] : 0.0;
                $pos_cur  = isset( $cur['avg_position'] ) ? (float) $cur['avg_position'] : 0.0;
                if ( $pos_prev <= 0.0 && $pos_cur > 0.0 ) {
                        // Nessuna posizione nel periodo precedente: comparsa "nuova".
                        $trend['position'] = array( 'dir' => 'new', 'delta' => round( $pos_cur, 1 ), 'pct' => null );
                } else {
                        // Miglioramento = valore sceso: delta/pct positivi = migliorato.
                        $pos_delta = round( $pos_prev - $pos_cur, 1 );
                        $pos_pct   = $pos_prev > 0.0 ? round( ( ( $pos_prev - $pos_cur ) / $pos_prev ) * 100, 1 ) : null;
                        if ( $pos_delta > 0.0 ) {
                                $trend['position'] = array( 'dir' => 'up', 'delta' => $pos_delta, 'pct' => $pos_pct );
                        } elseif ( $pos_delta < 0.0 ) {
                                $trend['position'] = array( 'dir' => 'down', 'delta' => $pos_delta, 'pct' => $pos_pct );
                        } else {
                                $trend['position'] = array( 'dir' => 'flat', 'delta' => 0.0, 'pct' => 0.0 );
                        }
                }

                return $trend;
        }

        /**
         * Direzione del confronto (cur vs prev) con delta assoluto e %.
         *
         * @param float|int $cur  Valore corrente.
         * @param float|int $prev Valore precedente.
         * @return array{dir:string,delta:float,pct:float|null} dir 'up'|'down'|'flat'|'new'.
         */
        private static function trend_dir( $cur, $prev ) {
                $cur  = (float) $cur;
                $prev = (float) $prev;
                if ( $prev <= 0.0 && $cur > 0.0 ) {
                        return array( 'dir' => 'new', 'delta' => round( $cur, 1 ), 'pct' => null );
                }
                $delta = round( $cur - $prev, 1 );
                $pct   = $prev > 0.0 ? round( ( ( $cur - $prev ) / $prev ) * 100, 1 ) : null;
                if ( $delta > 0.0 ) {
                        return array( 'dir' => 'up', 'delta' => $delta, 'pct' => $pct );
                }
                if ( $delta < 0.0 ) {
                        return array( 'dir' => 'down', 'delta' => $delta, 'pct' => $pct );
                }
                return array( 'dir' => 'flat', 'delta' => 0.0, 'pct' => 0.0 );
        }

        /**
         * Titolo reale del post WordPress con fallback onesto sull'ID
         * (post eliminato → 'Post #N', mai titolo inventato).
         *
         * @param int $post_id ID del post WP (colonna nexus_metrics.post_id).
         * @return string
         */
        private static function post_title( $post_id ) {
                $post_id = absint( $post_id );
                if ( $post_id <= 0 ) {
                        return '';
                }
                if ( ! function_exists( 'get_the_title' ) ) {
                        return 'Post #' . $post_id;
                }
                $title = trim( (string) get_the_title( $post_id ) );
                return '' !== $title ? $title : 'Post #' . $post_id;
        }

        /**
         * Permalink pubblico del post (stringa vuota se non risolvibile:
         * la vista mostra il titolo senza link).
         *
         * @param int $post_id ID del post WP.
         * @return string
         */
        private static function post_link( $post_id ) {
                if ( ! function_exists( 'get_permalink' ) ) {
                        return '';
                }
                $link = get_permalink( absint( $post_id ) );
                return is_string( $link ) && '' !== $link ? $link : '';
        }
}
