<?php
/**
 * Nexus Nexus_Learn — modulo LEARNING (v1.1.12, pacchetto S2b).
 *
 * Chiude il loop di feedback della redazione: aggrega le metriche di
 * performance giornaliere (tabella nexus_metrics, popolata da Nexus_Metrics
 * dai dati GSC/GA4) e insegna alla redazione cosa funziona.
 *
 *  - build_briefing(): aggregazione SQL sulla finestra learn_window_days
 *    (default 30 giorni) con JOIN sulla tabella articoli Nexus:
 *      * top_sources      — fonti (articles.source_name) classificate per
 *                           clicks+views, top 5, con conteggio articoli;
 *      * top_categories   — categorie (articles.category) migliori, top 5;
 *      * worst_categories — categorie peggiori (solo con almeno 2 articoli
 *                           distinti, per escludere il rumore), worst 3;
 *      * advice           — massimo 3 consigli IT generati da REGOLE
 *                           deterministiche (percentuali e soglie), mai AI.
 *  - Il briefing è salvato nell'option 'nexus_learn_briefing' e iniettato
 *    nel prompt della pipeline (fasi 'collect' e 'evaluate') via filtro
 *    'nexus_pipeline_prompt': il filtro legge SOLO l'option (nessuna query
 *    DB), aggiunge al più ~600 caratteri e resta silenzioso quando i dati
 *    mancano. ZERO MOCK: tabella metriche vuota = briefing vuoto onesto.
 *  - Cron giornaliero 'nexus_learn_refresh' rigenera il briefing; il metodo
 *    public static deactivate() (contratto per il bootstrap, register_
 *    deactivation_hook) rimuove l'evento schedulato.
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

final class Nexus_Learn {

        const OPTION_BRIEFING = 'nexus_learn_briefing';
        const HOOK_REFRESH    = 'nexus_learn_refresh';

        // Rigenerazione lazy in get_briefing(): oltre 20 ore il briefing è stale.
        const BRIEFING_TTL = 72000; // 20 ore in secondi.

        const MIN_ARTICLES = 5;   // sotto questa soglia di articoli: consiglio "servono dati".
        const MAX_ADVICE   = 3;   // massimo consigli nel briefing.
        const PROMPT_LIMIT = 600; // massimi caratteri aggiunti al prompt della pipeline.

        /**
         * Memo di richiesta: quando una rigenerazione produce un briefing
         * vuoto evita di rifare le query a ogni chiamata successiva.
         *
         * @var bool
         */
        private static $empty_once = false;

        /**
         * Init self-registrato su 'nexus_modules_init' (hook a fondo file):
         * cron giornaliero + filtro prompt + schedulazione.
         *
         * @return void
         */
        public static function init() {
                add_action( self::HOOK_REFRESH, array( __CLASS__, 'refresh' ) );
                add_filter( 'nexus_pipeline_prompt', array( __CLASS__, 'filter_prompt' ), 10, 4 );
                self::sync_schedule();
        }

        /**
         * Handler del cron 'nexus_learn_refresh': rigenera il briefing.
         * build_briefing() gestisce da solo il caso learn disattivo.
         *
         * @return void
         */
        public static function refresh() {
                if ( ! self::enabled() ) {
                        return;
                }
                self::build_briefing();
        }

        /**
         * Deattivazione plugin: rimuove l'evento cron del briefing.
         * Chiamata static dal bootstrap (register_deactivation_hook, contratto
         * con guardia method_exists come per Nexus_Ping::deactivate).
         *
         * @return void
         */
        public static function deactivate() {
                wp_clear_scheduled_hook( self::HOOK_REFRESH );
        }

        /**
         * API pubblica: briefing corrente (sempre array). Legge l'option e
         * rigenera quando generated_at è assente o stale (> 20 ore).
         *
         * @return array
         */
        public static function get_briefing() {
                $b = get_option( self::OPTION_BRIEFING, array() );
                if ( ! is_array( $b ) ) {
                        $b = array();
                }

                $generated = isset( $b['generated_at'] ) ? strtotime( (string) $b['generated_at'] ) : 0;
                // generated_at è salvato in ORARIO SITO (current_time('mysql') qui
                // sotto): il TTL confronta lo stesso orologio — v1.1.16 WA1.
                $stale     = $generated <= 0 || ( current_time( 'timestamp' ) - $generated ) > self::BRIEFING_TTL;

                if ( $stale && ! self::$empty_once ) {
                        $b = self::build_briefing();
                        if ( empty( $b ) ) {
                                // Briefing vuoto (learn disattivo o metriche assenti):
                                // memo di richiesta per non ripetere le query a ogni lettura.
                                self::$empty_once = true;
                        }
                }

                return is_array( $b ) ? $b : array();
        }

        /**
         * Costruisce il briefing aggregando nexus_metrics sulla finestra
         * learn_window_days. Con learn_enabled != 1, o senza alcuna metrica
         * nella finestra, ritorna array vuoto: comportamento onesto, nessun
         * dato inventato.
         *
         * @return array
         */
        public static function build_briefing() {
                if ( ! self::enabled() ) {
                        return array();
                }

                $days  = self::window_days();
                // metric_date è un DATE sul "giorno locale" (sync GSC/GA4,
                // v1.1.16 WA1): la finestra usa l'orologio del sito, non UTC.
                $since = gmdate( 'Y-m-d', current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) );

                $totals  = self::fetch_totals( $since );
                $sources = self::fetch_sources( $since );
                $cats    = self::fetch_categories( $since, 5, false );
                $worst   = self::fetch_categories( $since, 3, true );

                // ZERO MOCK: nessun post con metriche nella finestra → briefing
                // vuoto. Se l'option conteneva un briefing superato lo si svuota:
                // il filtro prompt non deve iniettare dati vecchi come se fossero attuali.
                if ( $totals['articles'] <= 0 ) {
                        $had = get_option( self::OPTION_BRIEFING, array() );
                        if ( is_array( $had ) && ! empty( $had ) ) {
                                delete_option( self::OPTION_BRIEFING );
                                self::log_activity( sprintf( 'Briefing performance svuotato: nessuna metrica negli ultimi %d giorni.', $days ), 'info' );
                        }
                        return array();
                }

                $briefing = array(
                        'generated_at'     => current_time( 'mysql' ),
                        'window_days'      => $days,
                        'top_sources'      => $sources,
                        'top_categories'   => $cats,
                        'worst_categories' => $worst,
                        'advice'           => self::build_advice( $days, $totals, $sources, $cats, $worst ),
                );

                self::save_briefing( $briefing );

                $has_data = ! empty( $sources ) || ! empty( $cats );
                self::log_activity(
                        sprintf( 'Briefing performance rigenerato: %d fonti, %d categorie, %d consigli (finestra %d giorni).', count( $sources ), count( $cats ), count( $briefing['advice'] ), $days ),
                        $has_data ? 'success' : 'info'
                );

                return $briefing;
        }

        /* ============ INIEZIONE PROMPT ============ */

        /**
         * Filtro 'nexus_pipeline_prompt' (fasi 'collect' e 'evaluate'): appende
         * al prompt il briefing performance, per orientare la scelta degli
         * argomenti verso ciò che funziona sul sito.
         *
         * Contratto S2b: legge SOLO l'option nexus_learn_briefing (nessuna
         * query DB dentro il filtro), non aggiunge mai più di ~600 caratteri
         * e ritorna sempre una stringa.
         *
         * @param string      $prompt  Prompt corrente della pipeline.
         * @param string      $phase   Fase pipeline ('collect'|'evaluate'|'rewrite').
         * @param object|null $article Articolo Nexus (non usato, firma del filtro).
         * @param object|null $agent   Agente Nexus (non usato, firma del filtro).
         * @return string
         */
        public static function filter_prompt( $prompt, $phase = 'collect', $article = null, $agent = null ) {
                if ( ! is_string( $prompt ) ) {
                        return $prompt; // Contratto del chiamante violato: non si corrompe nulla.
                }
                if ( 'collect' !== $phase && 'evaluate' !== $phase ) {
                        return $prompt;
                }
                if ( ! self::enabled() ) {
                        return $prompt;
                }

                // SOLO option: nessuna query DB nel filtro (contratto S2b).
                $b = get_option( self::OPTION_BRIEFING, array() );
                if ( ! is_array( $b ) ) {
                        return $prompt;
                }

                $block = self::prompt_block( $b );
                if ( '' === $block ) {
                        return $prompt;
                }

                return $prompt . $block;
        }

        /**
         * Blocco testo del briefing da appendere al prompt (max ~600 caratteri).
         * Righe: fonti di successo, categorie consigliate, consigli del
         * direttore. Se il blocco supera il limite si scartano le righe in
         * coda, poi eventuale taglio d'emergenza.
         *
         * @param array $b Briefing (option nexus_learn_briefing).
         * @return string '' quando il briefing non ha nulla da dire.
         */
        private static function prompt_block( $b ) {
                $sources = isset( $b['top_sources'] ) && is_array( $b['top_sources'] ) ? $b['top_sources'] : array();
                $cats    = isset( $b['top_categories'] ) && is_array( $b['top_categories'] ) ? $b['top_categories'] : array();
                $advice  = isset( $b['advice'] ) && is_array( $b['advice'] ) ? $b['advice'] : array();

                if ( empty( $sources ) && empty( $cats ) && empty( $advice ) ) {
                        return '';
                }

                $days = isset( $b['window_days'] ) ? max( 1, absint( $b['window_days'] ) ) : 30;
                $head = sprintf( "\n\nCONTESTO PERFORMANCE DEL SITO (ultimi %d giorni, per orientare la scelta degli argomenti):", $days );

                $lines = array();

                $src = array();
                foreach ( array_slice( $sources, 0, 3 ) as $s ) {
                        if ( empty( $s['name'] ) ) {
                                continue;
                        }
                        $clicks = isset( $s['clicks'] ) ? (int) $s['clicks'] : 0;
                        $src[]  = sprintf( '%s (%d click)', self::short_label( (string) $s['name'] ), $clicks );
                }
                if ( ! empty( $src ) ) {
                        $lines[] = '- Fonti con più successo: ' . implode( ', ', $src );
                }

                $cat = array();
                foreach ( array_slice( $cats, 0, 3 ) as $c ) {
                        if ( empty( $c['name'] ) ) {
                                continue;
                        }
                        $score = isset( $c['score'] ) ? (int) $c['score'] : 0;
                        $cat[] = sprintf( '%s (%d punti)', self::short_label( (string) $c['name'] ), $score );
                }
                if ( ! empty( $cat ) ) {
                        $lines[] = '- Categorie consigliate: ' . implode( ', ', $cat );
                }

                if ( ! empty( $advice ) ) {
                        $lines[] = '- Consigli del direttore: ' . implode( ' ', array_slice( $advice, 0, self::MAX_ADVICE ) );
                }

                if ( empty( $lines ) ) {
                        return '';
                }

                $block = $head . "\n" . implode( "\n", $lines );

                // Cap progressivo: si scartano le righe in coda finché si rientra nel limite.
                while ( self::len( $block ) > self::PROMPT_LIMIT && count( $lines ) > 1 ) {
                        array_pop( $lines );
                        $block = $head . "\n" . implode( "\n", $lines );
                }
                if ( self::len( $block ) > self::PROMPT_LIMIT ) {
                        $block = self::cut( $block, self::PROMPT_LIMIT );
                }

                return $block;
        }

        /* ============ IMPOSTAZIONI ============ */

        /**
         * Pacchetto learning attivo (setting learn_enabled = 1).
         *
         * @return bool
         */
        private static function enabled() {
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'get_settings' ) ) {
                        return false;
                }
                $settings = Nexus_DB::get_settings();
                return isset( $settings['learn_enabled'] ) && 1 === (int) $settings['learn_enabled'];
        }

        /**
         * Finestra di aggregazione in giorni (setting learn_window_days,
         * default 30, limitata a 1..365).
         *
         * @return int
         */
        private static function window_days() {
                $days = 30;
                if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'get_settings' ) ) {
                        $settings = Nexus_DB::get_settings();
                        $days     = isset( $settings['learn_window_days'] ) ? (int) $settings['learn_window_days'] : 30;
                }
                return min( 365, max( 1, $days ) );
        }

        /* ============ SCHEDULAZIONE ============ */

        /**
         * Cron giornaliero: schedula 'nexus_learn_refresh' con il pattern del
         * plugin (prima esecuzione sfalsata time() + 300 + rand(0,900)); se il
         * pacchetto è disattivo rimuove l'evento (pattern Nexus_Newsletter).
         *
         * @return void
         */
        private static function sync_schedule() {
                if ( self::enabled() ) {
                        if ( ! wp_next_scheduled( self::HOOK_REFRESH ) ) {
                                wp_schedule_event( time() + 300 + wp_rand( 0, 900 ), 'daily', self::HOOK_REFRESH );
                        }
                        return;
                }

                if ( wp_next_scheduled( self::HOOK_REFRESH ) ) {
                        wp_clear_scheduled_hook( self::HOOK_REFRESH );
                }
        }

        /* ============ AGGREGAZIONI ============ */

        /**
         * Nome tabella metriche: Nexus_DB::tables()['metrics'] (schema v4)
         * con fallback onesto sul prefisso standard.
         *
         * @return string
         */
        private static function metrics_table() {
                global $wpdb;
                if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'tables' ) ) {
                        $tables = Nexus_DB::tables();
                        if ( isset( $tables['metrics'] ) && '' !== (string) $tables['metrics'] ) {
                                return $tables['metrics'];
                        }
                }
                return $wpdb->prefix . 'nexus_metrics';
        }

        /**
         * Nome tabella articoli Nexus: Nexus_DB::tables()['articles'] con
         * fallback onesto sul prefisso standard.
         *
         * @return string
         */
        private static function articles_table() {
                global $wpdb;
                if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'tables' ) ) {
                        $tables = Nexus_DB::tables();
                        if ( isset( $tables['articles'] ) && '' !== (string) $tables['articles'] ) {
                                return $tables['articles'];
                        }
                }
                return $wpdb->prefix . 'nexus_articles';
        }

        /**
         * Guardia: entrambe le tabelle devono esistere (es. installazione con
         * schema v4 non ancora allineato). Senza tabelle le aggregazioni sono
         * vuote e nessun errore DB riempie i log.
         *
         * @return bool
         */
        private static function tables_ready() {
                global $wpdb;
                foreach ( array( self::metrics_table(), self::articles_table() ) as $t ) {
                        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) );
                        if ( $found !== $t ) {
                                return false;
                        }
                }
                return true;
        }

        /**
         * Totali della finestra: articoli distinti con metriche, click,
         * visualizzazioni e punteggio complessivo (clicks+views).
         *
         * @param string $since Data inizio finestra (Y-m-d).
         * @return array
         */
        private static function fetch_totals( $since ) {
                global $wpdb;
                $out = array(
                        'articles' => 0,
                        'clicks'   => 0,
                        'views'    => 0,
                        'score'    => 0,
                );
                if ( ! self::tables_ready() ) {
                        return $out;
                }
                $m   = self::metrics_table();
                $row = $wpdb->get_row( $wpdb->prepare(
                        "SELECT COUNT( DISTINCT post_id ) AS articles,
                                COALESCE( SUM( clicks ), 0 ) AS clicks,
                                COALESCE( SUM( views ), 0 ) AS views,
                                COALESCE( SUM( clicks + views ), 0 ) AS score
                        FROM {$m} WHERE metric_date >= %s",
                        $since
                ) );
                if ( ! is_object( $row ) ) {
                        return $out;
                }
                $out['articles'] = (int) $row->articles;
                $out['clicks']   = (int) $row->clicks;
                $out['views']    = (int) $row->views;
                $out['score']    = (int) $row->score;
                return $out;
        }

        /**
         * Top 5 fonti per clicks+views (colonna articles.source_name della
         * tabella articoli Nexus, JOIN sui post WP con metriche).
         *
         * @param string $since Data inizio finestra (Y-m-d).
         * @return array
         */
        private static function fetch_sources( $since ) {
                global $wpdb;
                if ( ! self::tables_ready() ) {
                        return array();
                }
                $m = self::metrics_table();
                $a = self::articles_table();
                // v1.1.12: una riga metriche per post+giorno+provider ('gsc' porta
                // i click, 'ga4' le views): sommando colonne intere si totalizza
                // ciascuna metrica senza doppioni per post/giorno.
                $rows = $wpdb->get_results( $wpdb->prepare(
                        "SELECT a.source_name AS name,
                                COALESCE( SUM( m.clicks ), 0 ) AS clicks,
                                COALESCE( SUM( m.views ), 0 ) AS views,
                                COALESCE( SUM( m.clicks + m.views ), 0 ) AS score,
                                COUNT( DISTINCT m.post_id ) AS articles
                        FROM {$m} m
                        INNER JOIN {$a} a ON a.wp_post_id = m.post_id
                        WHERE m.metric_date >= %s AND a.source_name <> ''
                        GROUP BY a.source_name
                        ORDER BY score DESC, name ASC
                        LIMIT 5",
                        $since
                ) );
                return self::map_rows( $rows );
        }

        /**
         * Aggregazione per categoria (colonna articles.category). Ordinaria:
         * top N per clicks+views. Con $worst = true: solo categorie con almeno
         * 2 articoli distinti (per evitare il rumore dei casi isolati), le N
         * peggiori per punteggio crescente.
         *
         * @param string $since Data inizio finestra (Y-m-d).
         * @param int    $limit Numero massimo righe.
         * @param bool   $worst true = modalità worst (HAVING >= 2 articoli, ASC).
         * @return array
         */
        private static function fetch_categories( $since, $limit, $worst ) {
                global $wpdb;
                if ( ! self::tables_ready() ) {
                        return array();
                }
                $m = self::metrics_table();
                $a = self::articles_table();
                // $having e $order derivano da booleani interni: nessun input utente.
                $having = $worst ? ' HAVING COUNT( DISTINCT m.post_id ) >= 2' : '';
                $order  = $worst ? 'score ASC, name ASC' : 'score DESC, name ASC';
                $rows   = $wpdb->get_results( $wpdb->prepare(
                        "SELECT a.category AS name,
                                COALESCE( SUM( m.clicks ), 0 ) AS clicks,
                                COALESCE( SUM( m.views ), 0 ) AS views,
                                COALESCE( SUM( m.clicks + m.views ), 0 ) AS score,
                                COUNT( DISTINCT m.post_id ) AS articles
                        FROM {$m} m
                        INNER JOIN {$a} a ON a.wp_post_id = m.post_id
                        WHERE m.metric_date >= %s AND a.category <> ''
                        GROUP BY a.category{$having}
                        ORDER BY {$order}
                        LIMIT %d",
                        $since,
                        absint( $limit )
                ) );
                return self::map_rows( $rows );
        }

        /**
         * Normalizza le righe SQL in array tipizzati del briefing.
         *
         * @param array|null $rows Risultato $wpdb->get_results().
         * @return array
         */
        private static function map_rows( $rows ) {
                $out = array();
                if ( ! is_array( $rows ) ) {
                        return $out;
                }
                foreach ( $rows as $r ) {
                        $name = trim( (string) $r->name );
                        if ( '' === $name ) {
                                continue;
                        }
                        $out[] = array(
                                'name'     => $name,
                                'clicks'   => (int) $r->clicks,
                                'views'    => (int) $r->views,
                                'score'    => (int) $r->score,
                                'articles' => (int) $r->articles,
                        );
                }
                return $out;
        }

        /**
         * Consigli IT generati da REGOLE deterministiche (percentuali e
         * soglie), mai da AI. Priorità: dati insufficienti → fonte dominante
         * nei click → categoria sotto-performante. Massimo 3 consigli.
         *
         * @param int   $days    Finestra in giorni.
         * @param array $totals  Totali finestra (fetch_totals()).
         * @param array $sources Top fonti (fetch_sources()).
         * @param array $cats    Top categorie (fetch_categories()).
         * @param array $worst   Worst categorie (fetch_categories()).
         * @return array
         */
        private static function build_advice( $days, $totals, $sources, $cats, $worst ) {
                // Regola 1: pochi articoli con metriche → ogni percentuale è rumore.
                if ( $totals['articles'] < self::MIN_ARTICLES ) {
                        return array( 'Meno di 5 articoli con metriche: servono dati, riprova quando la redazione pubblica.' );
                }

                $advice = array();

                // Regola 2: una fonte genera da sola >= 30% dei click della finestra.
                if ( ! empty( $sources ) && isset( $sources[0]['clicks'] ) && $totals['clicks'] > 0 && $sources[0]['clicks'] > 0 ) {
                        $share = (int) round( ( $sources[0]['clicks'] / $totals['clicks'] ) * 100 );
                        if ( $share >= 30 ) {
                                $advice[] = sprintf( 'La fonte %s genera il %d%% dei click: dai priorità ai suoi argomenti.', $sources[0]['name'], $share );
                        }
                }

                // Regola 3: la categoria peggiore (min 2 articoli) rende meno di
                // un terzo della migliore.
                if ( ! empty( $worst ) && ! empty( $cats ) && isset( $cats[0]['score'], $worst[0]['score'] ) ) {
                        $best  = (int) $cats[0]['score'];
                        $score = (int) $worst[0]['score'];
                        if ( $best > 0 && $score < ( $best * 0.3 ) ) {
                                $advice[] = sprintf( 'La categoria %s sotto-performa (%d click in %d giorni): riduci la frequenza o cambia angolo.', $worst[0]['name'], isset( $worst[0]['clicks'] ) ? (int) $worst[0]['clicks'] : 0, $days );
                        }
                }

                return array_slice( $advice, 0, self::MAX_ADVICE );
        }

        /* ============ SALVATAGGIO E LOG ============ */

        /**
         * Persiste il briefing nell'option dedicata (no autoload: poche centinaia
         * di byte letti solo da filtro/API).
         *
         * @param array $briefing Briefing costruito.
         * @return void
         */
        private static function save_briefing( $briefing ) {
                update_option( self::OPTION_BRIEFING, $briefing, false );
        }

        /**
         * Log attività best-effort (action 'learn') nella tabella activity_logs
         * di Nexus: mai interrompere il flusso per un errore di scrittura.
         *
         * @param string $detail Riepilogo breve.
         * @param string $status 'success' | 'info' | 'error'.
         * @return void
         */
        private static function log_activity( $detail, $status ) {
                try {
                        if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                                Nexus_DB::insert_activity( 0, 'learn', $detail, $status );
                        }
                } catch ( Exception $e ) {
                        // Log best-effort: nessuna scrittura attività blocca il briefing.
                } catch ( Throwable $e ) {
                        // Idem per errori PHP 7+.
                }
        }

        /* ============ HELPER TESTO ============ */

        /**
         * Etichetta compatta per il prompt (max 40 caratteri + ellissi).
         *
         * @param string $label Etichetta fonte/categoria.
         * @return string
         */
        private static function short_label( $label ) {
                $label = trim( (string) $label );
                if ( self::len( $label ) > 40 ) {
                        $label = self::cut( $label, 40 ) . '…';
                }
                return $label;
        }

        /**
         * Lunghezza stringa con fallback quando mbstring non è disponibile
         * (stessa prudenza di Nexus_Pipeline).
         *
         * @param string $s Stringa.
         * @return int
         */
        private static function len( $s ) {
                return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
        }

        /**
         * Troncamento stringa con fallback quando mbstring non è disponibile.
         *
         * @param string $s    Stringa.
         * @param int    $max  Lunghezza massima.
         * @return string
         */
        private static function cut( $s, $max ) {
                return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max, 'UTF-8' ) : substr( $s, 0, $max );
        }
}

add_action( 'nexus_modules_init', array( 'Nexus_Learn', 'init' ) );
