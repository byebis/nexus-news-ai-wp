<?php
/**
 * Nexus Opportunities — opportunità di ricerca (v1.1.14, task P2).
 *
 * Chiude il cerchio learning → redazione: legge le SEARCH QUERIES GSC
 * sito-wide archiviate in wp_nexus_search_queries (schema v6, popolate dal
 * sync di Nexus_Metrics con dimension 'query') e trasforma i dati in DUE
 * liste di opportunità editoriali per la pagina Analytics:
 *
 *  - TITOLI DA MIGLIORARE: query con impressions >= soglia, ALMENO un click
 *    e CTR sotto soglia (il sito appare per quella ricerca ma il titolo/
 *    snippet non converte) → azione: migliorare titolo/eccità dell'articolo
 *    che copre la query.
 *  - ARGOMENTI MANCANTI: query con impressions >= soglia ma ZERO click e
 *    nessuna pagina collegata → azione: nuovo articolo sul tema.
 *
 * CONTRATTO PUBBLICO (tutti metodi statici, mai fatale):
 * Nexus_Opportunities::data( $days )   — dati in SOLA LETTURA per la vista
 *   Analytics (nessuna scrittura). Struttura ritornata:
 *   array(
 *     'days'          => int (whitelist OPP_DAYS, default 30),
 *     'connected'     => bool (Nexus_Google_Auth::is_connected con guardie),
 *     'db_ok'         => bool (false = layer DB assente: stato modulo),
 *     'has_rows_ever' => bool (tabella query TOTALMENTE vuota → sync query
 *                        non ancora arrivato; DISTINTA da "nessuna query
 *                        nel periodo" per stati onesti),
 *     'has_queries'   => bool (righe presenti nella finestra),
 *     'window'        => array( 'start' => 'Y-m-d', 'end' => 'Y-m-d' ),
 *     'thresholds'    => array( 'min_impressions' => int, 'max_ctr' => float,
 *                               'topic_min_impressions' => int ),
 *     'titles'        => array di righe opportunità "titoli da migliorare",
 *     'topics'        => array di righe opportunità "argomenti mancanti",
 *     'agents'        => array( array( 'id' => int, 'name' => string,
 *                                      'active' => bool ), ordinate per nome ),
 *   )
 *   Ogni riga opportunità: array( 'query' => string, 'clicks' => int,
 *   'impressions' => int, 'ctr' => float|null (null se impressions = 0),
 *   'position' => float (0.0 se non disponibile) ).
 *
 * Nexus_Opportunities::rest_init()     — registra POST /nexus/v1/opportunities/
 *   assign (self-registrato su rest_api_init a fondo file, stesso pattern dei
 *   moduli v1.1.12: NESSUNA modifica al bootstrap né a class-nexus-rest.php).
 * Nexus_Opportunities::rest_permission() — capability manage_options + nonce
 *   REST esplicito (header X-WP-Nonce, come inviato da tutta l'admin).
 * Nexus_Opportunities::rest_assign( $request ) — crea il job di raccolta/
 *   redazione usando SOLO API PUBBLICHE esistenti:
 *   Nexus_Queue::dispatch( 'nexus_opportunity_wire', array( $topic, $agent_id ) )
 *   — il meccanismo coda del plugin (quando enable_queue=1 accoda un lavoro
 *   reale visibile nella pagina Coda Lavori, con retry e backoff; quando la
 *   coda è spenta esegue l'handler SUBITO in sincrono). L'handler
 *   Nexus_Opportunities::run_assignment() è registrato tramite il filtro
 *   pubblico 'nexus_job_handlers' di Nexus_Queue (nessun file toccato) e
 *   lancia il Nexus Wire sul tema (Nexus_Pipeline::wire_run, la STESSA API
 *   usata da POST /wire/run): l'articolo prodotto finisce in coda di
 *   approvazione. L'agente scelto nella UI è validato (esiste + attivo) e
 *   resta il REFERENTE dell'assegnazione nei log attività (contratto onesto:
 *   il Wire è una redazione collettiva; un binding per-agente del job sul
 *   tema richiederebbe una modifica a Nexus_Pipeline, fuori perimetro P2 —
 *   segnalato al coordinatore).
 *
 * LIMITI ONESTI (documentati anche nella vista):
 *  - la risposta GSC con dimension 'query' NON include le pagine: la colonna
 *    top_url resta NULL e "nessuna pagina corrispondente" è dedotta dai
 *    click (zero click = nessuna pagina nostra che converte per la query);
 *  - le soglie sono costanti di classe filtrabili (NESSUNA UI): filtrare con
 *    apply_filters('nexus_opportunities_min_impressions', ...), idem max_ctr
 *    e topic_min_impressions;
 *  - MAI zeri finti: ctr = null se impressions = 0, position = 0.0 se non
 *    ci sono righe con impressions > 0, e gli stati vuoti hanno messaggi
 *    distinti (modulo assente / Google non connesso / sync query non
 *    arrivato / nessuna query nel periodo / nessuna opportunità).
 *
 * QUERY DISCIPLINA (stessa di Nexus_Learn / Nexus_Analytics): SOLO range
 * scan sull'indice KEY query_date (WHERE query_date >= start AND <= end),
 * GROUP BY query, LIMIT; nessun full-scan.
 *
 * @package Nexus_News_AI
 * @since   1.1.14
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Opportunities {

        /**
         * Namespace REST del plugin (Nexus_Rest::NAMESPACE_URI = 'nexus/v1';
         * il valore è replicato qui per non creare una dipendenza dal modulo
         * REST in questo file auto-registrato).
         */
        const REST_NAMESPACE = 'nexus/v1';

        /**
         * Hook logico del job coda registrato via filtro 'nexus_job_handlers'.
         * Args: array( $topic, $agent_id ).
         */
        const QUEUE_HOOK = 'nexus_opportunity_wire';

        /**
         * Lunghezza massima del tema inviato al Wire (stesso cap di POST
         * /nexus/v1/wire/run: 200 caratteri).
         */
        const TOPIC_MAX = 200;

        /**
         * Whitelist giorni della finestra (allineata a
         * Nexus_Analytics::INSIGHT_WINDOWS).
         */
        const OPP_DAYS = array( 7, 30, 90 );

        /**
         * Cap di sicurezza per lista (le liste reali sono limitate dal filtro
         * dev nexus_opportunities_limit, default OPP_LIST_DEFAULT).
         */
        const OPP_LIST_CAP = 50;
        const OPP_LIST_DEFAULT = 10;

        /* ==================================================================
         * DATI PER LA VISTA (sola lettura)
         * ================================================================ */

        /**
         * Dati per la sezione "Opportunità di ricerca" di Analytics.
         *
         * @param int $days Giorni della finestra (whitelist OPP_DAYS).
         * @return array Contratto completo nel docblock della classe.
         */
        public static function data( $days = 30 ) {
                $days = absint( $days );
                if ( ! in_array( $days, self::OPP_DAYS, true ) ) {
                        $days = 30;
                }

                $out = array(
                        'days'          => $days,
                        'connected'     => self::google_connected(),
                        'db_ok'         => false,
                        'has_rows_ever' => false,
                        'has_queries'   => false,
                        'window'        => array(
                                'start' => '',
                                'end'   => '',
                        ),
                        'thresholds'    => array(
                                'min_impressions'       => self::min_impressions(),
                                'max_ctr'               => self::max_ctr(),
                                'topic_min_impressions' => self::topic_min_impressions(),
                        ),
                        'titles'        => array(),
                        'topics'        => array(),
                        'agents'        => self::agents_list(),
                );

                // Finestra: stessa convenzione "giorno locale" della sync e di
                // Nexus_Analytics (metric_date/query_date sono DATE locali).
                $now_ts      = strtotime( current_time( 'mysql' ) );
                $out['window']['start'] = gmdate( 'Y-m-d', $now_ts - ( $days - 1 ) * DAY_IN_SECONDS );
                $out['window']['end']   = gmdate( 'Y-m-d', $now_ts );

                // Degradazione grazia: senza layer DB la vista mostra lo stato
                // onesto (modulo non disponibile), zero numeri finti.
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'tables' ) ) {
                        return $out;
                }

                $table = self::queries_table();
                if ( '' === $table ) {
                        return $out;
                }

                $out['db_ok'] = true;

                global $wpdb;

                // Stato onesto: la tabella è vuota in assoluto (sync query
                // ancora non arrivato) o solo nel periodo scelto?
                $ever = $wpdb->get_var( "SELECT 1 FROM {$table} LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabella da allowlist interna, nessun input utente.
                $out['has_rows_ever'] = ! empty( $ever );

                if ( ! $out['has_rows_ever'] ) {
                        return $out;
                }

                $start = $out['window']['start'];
                $end   = $out['window']['end'];

                $in_window = $wpdb->get_var( $wpdb->prepare(
                        "SELECT 1 FROM {$table} WHERE query_date >= %s AND query_date <= %s LIMIT 1",
                        $start,
                        $end
                ) );
                $out['has_queries'] = ! empty( $in_window );

                if ( ! $out['has_queries'] ) {
                        return $out;
                }

                $out['titles'] = self::opportunity_rows( $table, $start, $end, 'title' );
                $out['topics'] = self::opportunity_rows( $table, $start, $end, 'topic' );

                return $out;
        }

        /**
         * Righe opportunità per la finestra [start..end] con GROUP BY query.
         *
         * kind 'title': impressions >= min_impressions AND clicks > 0 AND
         *               CTR% < max_ctr (il sito appare ma il titolo non
         *               converte).
         * kind 'topic': impressions >= topic_min_impressions AND clicks = 0
         *               (nessuna pagina nostra che converte: probabile
         *               argomento non coperto → nuovo articolo).
         *
         * CTR calcolato in PHP da SUM/SUM (ctr = null se impressions = 0,
         * mai 0% inventato); posizione media pesata per impressions dove
         * disponibile (righe con impressions > 0).
         *
         * @param string $table Nome tabella queries (allowlist interna).
         * @param string $start Data inizio Y-m-d (inclusa).
         * @param string $end   Data fine Y-m-d (inclusa).
         * @param string $kind  'title' | 'topic'.
         * @return array Lista di righe (contratto nel docblock della classe).
         */
        private static function opportunity_rows( $table, $start, $end, $kind ) {
                global $wpdb;

                $limit = self::list_limit();

                if ( 'title' === $kind ) {
                        $having = 'HAVING impressions >= %d AND clicks > 0 AND ( 100.0 * clicks / impressions ) < %f';
                        $having_args = array( self::min_impressions(), self::max_ctr() );
                } else {
                        $having = 'HAVING impressions >= %d AND clicks = 0';
                        $having_args = array( self::topic_min_impressions() );
                }

                $sql = "SELECT query,
                                COALESCE( SUM( clicks ), 0 ) AS clicks,
                                COALESCE( SUM( impressions ), 0 ) AS impressions,
                                COALESCE( SUM( CASE WHEN impressions > 0 THEN position * impressions END ) / NULLIF( SUM( CASE WHEN impressions > 0 THEN impressions END ), 0 ), 0 ) AS position
                        FROM {$table}
                        WHERE query_date >= %s AND query_date <= %s
                        GROUP BY query
                        {$having}
                        ORDER BY impressions DESC, query ASC
                        LIMIT %d";

                // prepare variadico: il placeholder COUNT deve combaciare con
                // gli argomenti della HAVING + LIMIT (start/end in testa).
                $prepared = call_user_func_array(
                        array( $wpdb, 'prepare' ),
                        array_merge( array( $sql, $start, $end ), $having_args, array( $limit ) )
                );

                $rows = (array) $wpdb->get_results( $prepared ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- query preparata sopra (placeholder completi, tabella da allowlist interna).

                $out = array();

                foreach ( $rows as $r ) {
                        $impressions = isset( $r->impressions ) ? (int) $r->impressions : 0;
                        $clicks      = isset( $r->clicks ) ? (int) $r->clicks : 0;

                        $out[] = array(
                                'query'       => (string) $r->query,
                                'clicks'      => $clicks,
                                'impressions' => $impressions,
                                'ctr'         => $impressions > 0 ? round( ( $clicks / $impressions ) * 100, 1 ) : null,
                                'position'    => round( (float) $r->position, 1 ),
                        );
                }

                return $out;
        }

        /* ==================================================================
         * SOGLIE (costanti di classe filtrabili — NESSUNA UI)
         * ================================================================ */

        /**
         * "Titoli da migliorare": impressions minime per query nella finestra.
         * Default 100: sotto questa soglia il CTR è statistica rumorosa.
         *
         * @return int
         */
        private static function min_impressions() {
                return max( 1, (int) apply_filters( 'nexus_opportunities_min_impressions', 100 ) );
        }

        /**
         * "Titoli da migliorare": CTR massimo (%) per considerare il titolo
         * debole. Default 2.0%: la pagina appare ma quasi nessuno clicca.
         *
         * @return float
         */
        private static function max_ctr() {
                $ctr = (float) apply_filters( 'nexus_opportunities_max_ctr', 2.0 );

                return $ctr > 0.0 ? $ctr : 2.0;
        }

        /**
         * "Argomenti mancanti": impressions minime per query con ZERO click.
         * Default 10: più basso della soglia "titoli" (un tema cercato e mai
         * cliccato è un segnale anche con poche impressioni), abbastanza alto
         * da escludere il rumore delle query isolate.
         *
         * @return int
         */
        private static function topic_min_impressions() {
                return max( 1, (int) apply_filters( 'nexus_opportunities_topic_min_impressions', 10 ) );
        }

        /**
         * Numero massimo di righe per lista.
         *
         * @return int
         */
        private static function list_limit() {
                $limit = (int) apply_filters( 'nexus_opportunities_limit', self::OPP_LIST_DEFAULT );

                return max( 1, min( self::OPP_LIST_CAP, $limit ) );
        }

        /* ==================================================================
         * AGENTI (lista per il select "assegna")
         * ================================================================ */

        /**
         * Lista agenti esistenti per il select della vista (stessa fonte di
         * redazioni/agenti: Nexus_DB::agents(), con guardie). Ordinate per
         * nome (case-insensitive) per un select leggibile.
         *
         * @return array array( array( 'id' => int, 'name' => string, 'active' => bool ) )
         */
        private static function agents_list() {
                $out = array();

                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'agents' ) ) {
                        return $out;
                }

                foreach ( (array) Nexus_DB::agents() as $agent ) {
                        $id = isset( $agent->id ) ? absint( $agent->id ) : 0;
                        if ( $id <= 0 ) {
                                continue;
                        }

                        $out[] = array(
                                'id'     => $id,
                                'name'   => isset( $agent->name ) ? (string) $agent->name : 'Agente #' . $id,
                                'active' => isset( $agent->status ) && 'active' === $agent->status,
                        );
                }

                usort( $out, array( __CLASS__, 'agents_sort' ) );

                return $out;
        }

        /**
         * Callback usort per agents_list(): nome case-insensitive, tie-break
         * su id crescente (deterministico).
         *
         * @param array $a Agente A.
         * @param array $b Agente B.
         * @return int
         */
        private static function agents_sort( $a, $b ) {
                $cmp = strcasecmp( (string) $a['name'], (string) $b['name'] );

                if ( 0 !== $cmp ) {
                        return $cmp;
                }

                return (int) $a['id'] <=> (int) $b['id'];
        }

        /* ==================================================================
         * REST — POST /nexus/v1/opportunities/assign
         * ================================================================ */

        /**
         * Registra la rotta (hook rest_api_init, self-registrato a fondo file).
         *
         * @return void
         */
        public static function rest_init() {
                register_rest_route(
                        self::REST_NAMESPACE,
                        '/opportunities/assign',
                        array(
                                array(
                                        'methods'             => WP_REST_Server::CREATABLE,
                                        'callback'            => array( __CLASS__, 'rest_assign' ),
                                        'permission_callback' => array( __CLASS__, 'rest_permission' ),
                                        'args'                => array(
                                                'query'    => array(
                                                        'type'              => 'string',
                                                        'required'          => true,
                                                        'sanitize_callback' => 'sanitize_text_field',
                                                ),
                                                'agent_id' => array(
                                                        'type'              => 'integer',
                                                        'required'          => true,
                                                        'sanitize_callback' => 'absint',
                                                ),
                                                'kind'     => array(
                                                        'type'              => 'string',
                                                        'required'          => false,
                                                        'default'           => 'topic',
                                                        'sanitize_callback' => array( __CLASS__, 'sanitize_kind' ),
                                                ),
                                        ),
                                ),
                        )
                );
        }

        /**
         * Sanitize whitelist del campo 'kind' (informativo: 'title' = riga
         * proveniente dai "titoli da migliorare", 'topic' = argomenti).
         *
         * @param mixed $value Valore grezzo.
         * @return string
         */
        public static function sanitize_kind( $value ) {
                $value = sanitize_key( (string) $value );

                return in_array( $value, array( 'title', 'topic' ), true ) ? $value : 'topic';
        }

        /**
         * Permission della rotta: capability manage_options + nonce REST
         * esplicito (contratto del task: capability + nonce — l'header
         * X-WP-Nonce è inviato da tutta l'admin del plugin via
         * NexusAdminData.nonce).
         *
         * @param WP_REST_Request $request Richiesta corrente.
         * @return true|WP_Error
         */
        public static function rest_permission( $request ) {
                $nonce = $request->get_header( 'X-WP-Nonce' );

                if ( ! is_string( $nonce ) || '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
                        return new WP_Error(
                                'nexus_opportunities_bad_nonce',
                                __( 'Nonce di sicurezza non valido: ricarica la pagina e riprova.', 'nexus-news-ai' ),
                                array( 'status' => 403 )
                        );
                }

                if ( ! current_user_can( 'manage_options' ) ) {
                        return new WP_Error(
                                'nexus_opportunities_forbidden',
                                __( 'Non hai i permessi per accedere a questa risorsa.', 'nexus-news-ai' ),
                                array( 'status' => 403 )
                        );
                }

                return true;
        }

        /**
         * POST /opportunities/assign — valida query e agente, crea il job di
         * raccolta/redazione con Nexus_Queue::dispatch (hook proprio
         * 'nexus_opportunity_wire' registrato su 'nexus_job_handlers') e
         * risponde con l'esito onesto (accodato / eseguito / duplicato /
         * errore). NESSUNA modifica a pipeline/queue/db.
         *
         * @param WP_REST_Request $request Richiesta (query, agent_id, kind).
         * @return WP_REST_Response|WP_Error
         */
        public static function rest_assign( $request ) {
                $query = trim( (string) $request->get_param( 'query' ) );

                if ( '' === $query ) {
                        return new WP_Error(
                                'nexus_opportunities_missing_query',
                                __( 'Il campo "query" è obbligatorio.', 'nexus-news-ai' ),
                                array( 'status' => 400 )
                        );
                }

                // Stesso cap del topic di POST /wire/run (TOPIC_MAX).
                $query = self::clip( $query, self::TOPIC_MAX );

                $agent_id = absint( $request->get_param( 'agent_id' ) );

                if ( $agent_id <= 0 ) {
                        return new WP_Error(
                                'nexus_opportunities_missing_agent',
                                __( 'Seleziona un agente a cui assegnare l\'opportunità.', 'nexus-news-ai' ),
                                array( 'status' => 400 )
                        );
                }

                $kind = self::sanitize_kind( $request->get_param( 'kind' ) );

                $agent = self::get_agent( $agent_id );

                if ( ! $agent ) {
                        return new WP_Error(
                                'nexus_opportunities_agent_missing',
                                __( 'Agente non trovato: aggiorna la pagina e riprova.', 'nexus-news-ai' ),
                                array( 'status' => 404 )
                        );
                }

                if ( empty( $agent['active'] ) ) {
                        return new WP_Error(
                                'nexus_opportunities_agent_inactive',
                                __( 'L\'agente scelto non è attivo: attivalo nella pagina Agenti e riprova.', 'nexus-news-ai' ),
                                array( 'status' => 409 )
                        );
                }

                if ( ! class_exists( 'Nexus_Queue' ) || ! method_exists( 'Nexus_Queue', 'dispatch' ) ) {
                        return new WP_Error(
                                'nexus_opportunities_queue_missing',
                                __( 'Coda lavori non disponibile: impossibile creare il lavoro.', 'nexus-news-ai' ),
                                array( 'status' => 500 )
                        );
                }

                $dispatch = Nexus_Queue::dispatch( self::QUEUE_HOOK, array( $query, $agent_id ) );

                if ( ! is_array( $dispatch ) ) {
                        return new WP_Error(
                                'nexus_opportunities_queue_failed',
                                __( 'Creazione del lavoro non riuscita: riprova.', 'nexus-news-ai' ),
                                array( 'status' => 500 )
                        );
                }

                $queued    = ! empty( $dispatch['queued'] );
                $executed  = ! empty( $dispatch['executed'] );
                $duplicate = ! empty( $dispatch['duplicate'] );
                $job_id    = isset( $dispatch['job_id'] ) ? absint( $dispatch['job_id'] ) : 0;
                $error     = isset( $dispatch['error'] ) ? (string) $dispatch['error'] : '';

                // Lavoro accodato: log dell'assegnazione con l'agente referente
                // (l'esito del Wire, in coda, viene loggato dall'handler).
                if ( $queued && $job_id > 0 ) {
                        self::log_assignment_queued( $agent, $query, $job_id );
                }

                // Esecuzione sincrona (coda spenta): l'handler ha già loggato
                // l'esito; qui si risponde onestamente se è fallito.
                if ( $executed && '' !== $error ) {
                        return new WP_Error(
                                'nexus_opportunities_wire_failed',
                                self::clip( $error, 200 ),
                                array( 'status' => 500 )
                        );
                }

                if ( ! $queued && ! $executed && ! $duplicate ) {
                        return new WP_Error(
                                'nexus_opportunities_not_dispatched',
                                '' !== $error ? self::clip( $error, 200 ) : __( 'Creazione del lavoro non riuscita: riprova.', 'nexus-news-ai' ),
                                array( 'status' => 500 )
                        );
                }

                $article_id = 0;

                if ( $executed && isset( $dispatch['result'] ) && is_array( $dispatch['result'] ) ) {
                        $article_id = isset( $dispatch['result']['article_id'] ) ? absint( $dispatch['result']['article_id'] ) : 0;
                }

                return rest_ensure_response(
                        array(
                                'success'    => true,
                                'queued'     => $queued,
                                'executed'   => $executed,
                                'duplicate'  => $duplicate,
                                'job_id'     => $job_id,
                                'article_id' => $article_id,
                                'topic'      => $query,
                                'kind'       => $kind,
                                'agent'      => array(
                                        'id'   => $agent_id,
                                        'name' => (string) $agent['name'],
                                ),
                        )
                );
        }

        /* ==================================================================
         * HANDLER CODA ('nexus_opportunity_wire')
         * ================================================================ */

        /**
         * Registra l'handler del job sul filtro pubblico 'nexus_job_handlers'
         * di Nexus_Queue (nessun file del plugin toccato). Chiamato da
         * Nexus_Queue::resolve_handler al dispatch/run_batch.
         *
         * @param array $handlers Mappa hook logico => callable.
         * @return array
         */
        public static function job_handlers( $handlers ) {
                if ( ! is_array( $handlers ) ) {
                        $handlers = array();
                }

                if ( ! isset( $handlers[ self::QUEUE_HOOK ] ) ) {
                        $handlers[ self::QUEUE_HOOK ] = array( __CLASS__, 'run_assignment' );
                }

                return $handlers;
        }

        /**
         * Handler del job 'nexus_opportunity_wire': avvia il Nexus Wire sul
         * tema (Nexus_Pipeline::wire_run — la STESSA API pubblica usata da
         * POST /nexus/v1/wire/run) e logga l'assegnazione con l'agente
         * referente.
         *
         * CONTRATTO CODA: solleva Exception su qualunque fallimento → la
         * coda registra last_error e applica retry/backoff (comportamento
         * standard del runner); con la coda spenta l'eccezione viene incassata
         * da Nexus_Queue::dispatch (executed + error) e restituita alla REST.
         * L'agente viene RIVALIDATO qui (il job può girare minuti dopo la
         * richiesta: lo stato dell'agente può essere cambiato).
         *
         * @param string $topic    Tema (la query di ricerca).
         * @param int    $agent_id ID dell'agente referente.
         * @return array{ok:bool, article_id:int}
         * @throws Exception Su tema vuoto, agente non valido o Wire fallito.
         */
        public static function run_assignment( $topic, $agent_id = 0 ) {
                $topic = trim( sanitize_text_field( (string) $topic ) );

                if ( '' === $topic ) {
                        throw new Exception( 'Tema mancante: la query è vuota.' );
                }

                $topic = self::clip( $topic, self::TOPIC_MAX );

                $agent_id = absint( $agent_id );
                $agent    = self::get_agent( $agent_id );

                if ( ! $agent ) {
                        throw new Exception( 'Agente assegnatario non trovato.' );
                }

                if ( empty( $agent['active'] ) ) {
                        throw new Exception( 'Agente assegnatario non attivo.' );
                }

                if ( ! class_exists( 'Nexus_Pipeline' ) || ! method_exists( 'Nexus_Pipeline', 'wire_run' ) ) {
                        throw new Exception( 'Modulo pipeline non disponibile.' );
                }

                $result     = Nexus_Pipeline::wire_run( $topic );
                $ok         = is_array( $result ) && ! empty( $result['ok'] ) && isset( $result['run'] ) && is_array( $result['run'] );
                $article_id = 0;
                $error      = '';

                if ( $ok ) {
                        $article_id = isset( $result['run']['article_id'] ) ? absint( $result['run']['article_id'] ) : 0;
                } else {
                        $error = isset( $result['run']['error'] ) && '' !== (string) $result['run']['error']
                                ? (string) $result['run']['error']
                                : 'Nexus Wire fallito senza dettagli.';
                }

                self::log_assignment_outcome( $agent, $topic, $ok, $article_id, $error );

                if ( ! $ok ) {
                        throw new Exception( self::clip( $error, 200 ) );
                }

                return array(
                        'ok'         => true,
                        'article_id' => $article_id,
                );
        }

        /* ==================================================================
         * HELPER
         * ================================================================ */

        /**
         * Agente per ID, con guardie su Nexus_DB e forma normalizzata
         * array( 'id' => int, 'name' => string, 'active' => bool ).
         *
         * @param int $agent_id ID agente.
         * @return array|false False se il modulo/agente manca.
         */
        private static function get_agent( $agent_id ) {
                $agent_id = absint( $agent_id );

                if ( $agent_id <= 0 || ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'get_agent' ) ) {
                        return false;
                }

                $agent = Nexus_DB::get_agent( $agent_id );

                if ( ! $agent || ! is_object( $agent ) ) {
                        return false;
                }

                return array(
                        'id'     => $agent_id,
                        'name'   => isset( $agent->name ) ? (string) $agent->name : 'Agente #' . $agent_id,
                        'active' => isset( $agent->status ) && 'active' === $agent->status,
                );
        }

        /**
         * Log attività dell'assegnazione ACCODATA (agente referente; l'esito
         * del Wire arriverà con il giro del runner e verrà loggato da
         * log_assignment_outcome).
         *
         * @param array  $agent  Agente normalizzato (get_agent).
         * @param string $query  Tema assegnato.
         * @param int    $job_id ID del lavoro in coda.
         * @return void
         */
        private static function log_assignment_queued( $agent, $query, $job_id ) {
                self::log_activity(
                        sprintf(
                                'Opportunità di ricerca «%1$s» assegnata a %2$s (referente): lavoro %3$s accodato (job #%4$d), il Nexus Wire partirà al prossimo giro del runner.',
                                (string) $query,
                                (string) $agent['name'],
                                self::QUEUE_HOOK,
                                (int) $job_id
                        ),
                        'info'
                );
        }

        /**
         * Log attività dell'ESITO dell'assegnazione (chiamato dall'handler).
         *
         * @param array  $agent      Agente normalizzato (get_agent).
         * @param string $query      Tema assegnato.
         * @param bool   $ok         Esito del Nexus Wire.
         * @param int    $article_id Articolo prodotto (se ok).
         * @param string $error      Errore (se !ok).
         * @return void
         */
        private static function log_assignment_outcome( $agent, $query, $ok, $article_id, $error ) {
                if ( $ok ) {
                        self::log_activity(
                                sprintf(
                                        'Opportunità di ricerca «%1$s» assegnata a %2$s (referente): Nexus Wire completato, articolo #%3$d in coda di approvazione.',
                                        (string) $query,
                                        (string) $agent['name'],
                                        (int) $article_id
                                ),
                                'success'
                        );

                        return;
                }

                self::log_activity(
                        sprintf(
                                'Opportunità di ricerca «%1$s» assegnata a %2$s (referente): Nexus Wire fallito: %3$s',
                                (string) $query,
                                (string) $agent['name'],
                                self::clip( (string) $error, 300 )
                        ),
                        'error'
                );
        }

        /**
         * Nome tabella search queries con guardie (Nexus_DB::tables()).
         * '' = layer DB non disponibile (la vista mostra lo stato onesto).
         *
         * @return string
         */
        private static function queries_table() {
                global $wpdb;

                if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'tables' ) ) {
                        $tables = Nexus_DB::tables();
                        if ( isset( $tables['search_queries'] ) && is_string( $tables['search_queries'] ) && '' !== $tables['search_queries'] ) {
                                return $tables['search_queries'];
                        }
                }

                return $wpdb->prefix . 'nexus_search_queries';
        }

        /**
         * Google connesso? Guardie class_exists/method_exists + try/catch:
         * la sezione non deve MAI rompere la pagina Analytics (convenzione Q1).
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
         * Clip multibyte (mb_substr con fallback substr).
         *
         * @param string $text Testo.
         * @param int    $max Lunghezza massima.
         * @return string
         */
        private static function clip( $text, $max ) {
                $text = (string) $text;
                $max  = max( 1, (int) $max );

                if ( function_exists( 'mb_substr' ) ) {
                        return mb_substr( $text, 0, $max );
                }

                return substr( $text, 0, $max );
        }

        /**
         * Log attività con guardia su Nexus_DB (fallback su error_log di
         * debug, stesso pattern di Nexus_Metrics::log_activity). Messaggi
         * in italiano, senza dati sensibili (la query è contenuto editoriale,
         * non un segreto).
         *
         * @param string $detail Messaggio.
         * @param string $status 'info' | 'error' | 'success'.
         * @return void
         */
        private static function log_activity( $detail, $status = 'info' ) {
                if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                        Nexus_DB::insert_activity( 0, 'opportunities', (string) $detail, (string) $status );
                        return;
                }

                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[Nexus News AI][Opportunities] ' . (string) $detail );
                }
        }
}

// v1.1.14 (P2): rotta REST self-registrata su rest_api_init (stesso pattern
// dei moduli v1.1.12: NESSUNA modifica al bootstrap né a class-nexus-rest.php).
// NB: il file viene richiesto da class-nexus-metrics.php (debito glue
// documentato lì): la classe è quindi definita PRIMA di plugins_loaded.
add_action( 'rest_api_init', array( 'Nexus_Opportunities', 'rest_init' ) );

// v1.1.14 (P2): handler del job coda 'nexus_opportunity_wire' registrato sul
// filtro PUBBLICO 'nexus_job_handlers' di Nexus_Queue (read-only reuse: la
// coda risolve gli handler al dispatch, quindi il filtro è applicato a ogni
// chiamata — nessun problema di ordine di caricamento).
add_filter( 'nexus_job_handlers', array( 'Nexus_Opportunities', 'job_handlers' ) );
