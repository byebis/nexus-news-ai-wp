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
 * Regole: PHP 7.4, query di sola lettura con $wpdb->prepare dove ci sono
 * parametri (le costanti di tempo sono inline), tabelle via Nexus_DB::tables(),
 * current_time('mysql') per coerenza col resto del plugin, costi arrotondati a
 * 4 decimali, commenti e valori UI in italiano.
 *
 * @package Nexus_News_AI
 * @since   1.0.4
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Analytics {

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
}
