<?php
/**
 * Nexus News Sitemap — GOOGLE NEWS SITEMAP v1.1.12 (pacchetto S5).
 *
 * SCOPO:
 * Espone la sitemap Google News all'indirizzo /nexus-news-sitemap.xml per i
 * post WordPress REALI pubblicati dal plugin (post nativo 'post' + CPT legacy
 * 'nexus_article', stessa copertura dual-mode di Nexus_Ping via
 * Nexus_Native::query_post_types()) e pinga Google dopo ogni pubblicazione.
 *
 * COMPORTAMENTO:
 * - Rewrite (hook 'init'): la rule nexus-news-sitemap\.xml$ →
 *   index.php?nexus_news_sitemap=1 viene aggiunta SOLO quando il setting
 *   news_sitemap_enabled = 1. Il flush di rewrite_rules e' controllato
 *   dall'option 'nexus_news_sitemap_flushed' che memorizza l'ultimo stato
 *   per cui le rules sono state rigenerate (1 = attiva, 0 = disattiva):
 *   flush UNA volta sola alla transizione enabled↔disabled, MAI ad ogni
 *   request. Quando il modulo e' disattivato la rule semplicemente non
 *   viene aggiunta e un flush unico la rimuove dalle rules persistite.
 * - Render (hook 'template_redirect'): Content-Type application/xml;
 *   charset=UTF-8 + nocache_headers(); se il modulo e' disabilitato → 404.
 *   XML urlset (namespace sitemap 0.9 + news 0.9) con SOLO gli articoli
 *   pubblicati nelle ultime 48 ORE (vincolo Google News), max 1000 URL,
 *   ordinati per data DESC: <loc> (esc_url), <news:name> (testata dal
 *   setting news_sitemap_name, fallback get_bloginfo('name')),
 *   <news:language> (prefisso di get_bloginfo('language'), es. it-IT → it),
 *   <news:publication_date> (ISO 8601 da post_date_gmt via gmdate('c')),
 *   <news:title> (esc_html). Tutti i valori escapati.
 * - Ping (hook 'nexus_pipeline_after_publish'): v1.1.13 (O1) DISATTIVATO di
 *   default — l'endpoint https://www.google.com/ping?sitemap={url} è stato
 *   deprecato da Google (rispondeva 4xx/5xx → riga "error" in activity_logs a
 *   ogni pubblicazione). La sitemap resta raggiungibile su
 *   /nexus-news-sitemap.xml e dichiarata in robots.txt: la scoperta avviene via
 *   crawl / Search Console. Riattivazione manuale (dev):
 *   add_filter( 'nexus_news_sitemap_ping', '__return_true' ) — il codice di ping
 *   originale resta sotto filtro, sempre try/catch, mai blocca la pubblicazione.
 *   NOTA: Nexus_Ping non espone metodi pubblici riutilizzabili per ping
 *   generici (ping_indexnow/ping_websub sono privati e specifici), quindi
 *   il ping Google (quando riattivato) è implementato qui.
 * - robots_txt: se abilitato aggiunge la riga "Sitemap: {url}".
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

final class Nexus_News_Sitemap {

        /**
         * Query var che attiva il rendering della sitemap su template_redirect.
         */
        const QUERY_VAR = 'nexus_news_sitemap';

        /**
         * Option di controllo del flush: memorizza l'ultimo stato (1|0) per cui
         * le rewrite rules sono state rigenerate. Cambio di stato → flush UNA volta.
         */
        const OPTION_FLUSHED = 'nexus_news_sitemap_flushed';

        /**
         * Massimo numero di URL nella sitemap (limite Google News: 1000).
         */
        const MAX_URLS = 1000;

        /**
         * Endpoint di ping sitemap di Google (formato "?sitemap={url}").
         *
         * v1.1.13 (O1): endpoint DEPRECATO da Google — il ping che lo usa è
         * disattivato di default (vedi maybe_ping_google e filtro
         * 'nexus_news_sitemap_ping'); la costante resta per il percorso dev.
         */
        const PING_ENDPOINT = 'https://www.google.com/ping?sitemap=';

        /**
         * Bootstrap modulo (self-registrato su nexus_modules_init a fondo file).
         *
         * @return void
         */
        public static function init() {
                add_filter( 'query_vars', array( __CLASS__, 'add_query_var' ) );
                add_action( 'init', array( __CLASS__, 'register_rewrite' ) );
                add_action( 'template_redirect', array( __CLASS__, 'render_sitemap' ) );
                add_action( 'nexus_pipeline_after_publish', array( __CLASS__, 'maybe_ping_google' ), 10, 3 );
                add_filter( 'robots_txt', array( __CLASS__, 'robots_txt' ), 10, 2 );
        }

        /**
         * Hook 'init': registra la rewrite rule della sitemap (solo se abilitata)
         * e gestisce il flush controllato via option nexus_news_sitemap_flushed.
         *
         * Perche' qui e non all'attivazione: il setting e' runtime (l'utente
         * abilita/disabilita dall'admin), quindi la rule segue lo stato del
         * setting; la option garantisce che il flush (costoso) avvenga UNA
         * sola volta per transizione, mai ad ogni request.
         *
         * @return void
         */
        public static function register_rewrite() {
                $enabled = self::is_enabled();

                // Rule presente SOLO quando il modulo e' abilitato: alla
                // disattivazione basta non aggiungerla.
                if ( $enabled ) {
                        add_rewrite_rule( 'nexus-news-sitemap\.xml$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
                }

                // Flush controllato: solo se lo stato attuale differisce da quello
                // per cui le rules sono state rigenerate l'ultima volta.
                $state   = $enabled ? 1 : 0;
                $flushed = (int) get_option( self::OPTION_FLUSHED, -1 );

                if ( $state !== $flushed ) {
                        flush_rewrite_rules();
                        update_option( self::OPTION_FLUSHED, $state );
                }
        }

        /**
         * Filtro query_vars: aggiunge 'nexus_news_sitemap' a quelle riconosciute.
         * Registrata sempre (anche da disabilitato): innocua e rende il 404
         * deterministico se la rule fosse ancora in cache.
         *
         * @param array $vars Query vars esistenti.
         * @return array
         */
        public static function add_query_var( $vars ) {
                $vars[] = self::QUERY_VAR;

                return $vars;
        }

        /**
         * Hook 'template_redirect': serving dell'XML della sitemap.
         *
         * - Richiesta diversa da nexus_news_sitemap=1 → esce subito (pagina normale).
         * - Modulo disabilitato → 404 e terminazione.
         * - Altrimenti: header XML + nocache_headers() + urlset con gli articoli
         *   pubblicati nelle ultime 48 ore (max 1000, orderby date DESC).
         *
         * @return void
         */
        public static function render_sitemap() {
                if ( 1 !== (int) get_query_var( self::QUERY_VAR ) ) {
                        return;
                }

                if ( ! self::is_enabled() ) {
                        status_header( 404 );
                        nocache_headers();
                        exit;
                }

                header( 'Content-Type: application/xml; charset=UTF-8' );
                nocache_headers();

                echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
                echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";

                foreach ( self::query_recent_posts() as $post ) {
                        self::render_url_entry( $post );
                }

                echo '</urlset>' . "\n";
                exit;
        }

        /**
         * Hook 'nexus_pipeline_after_publish': ping sitemap a Google dopo la
         * pubblicazione di un articolo.
         *
         * v1.1.13 (O1): DISATTIVATO di default. L'endpoint
         * https://www.google.com/ping?sitemap= è deprecato da Google: a ogni
         * pubblicazione produceva una riga "error" in activity_logs (risposta 4xx/5xx)
         * senza alcun valore reale (la scoperta avviene via robots.txt / Search
         * Console). Nessun log quando il ping è spento: nessun rumore, nessuna riga
         * d'errore. Riattivazione manuale (dev):
         * add_filter( 'nexus_news_sitemap_ping', '__return_true' ).
         *
         * Comportamento se riattivato: GET con timeout 3s, qualunque errore viene
         * solo loggato (mai eccezioni propagate, mai blocco della pipeline).
         *
         * @param int    $article_id ID dell'articolo nella tabella Nexus.
         * @param object $article    Riga articolo Nexus (non usata qui).
         * @param array  $logs       Log di pubblicazione (non usato qui).
         * @return void
         */
        public static function maybe_ping_google( $article_id, $article, $logs ) {
                // Contratto do_action: nessun ritorno atteso, le eccezioni non
                // devono mai raggiungere la pipeline editoriale.
                try {
                        if ( ! self::is_enabled() ) {
                                return;
                        }

                        // v1.1.13 (O1): filtro dev — default false (endpoint deprecato).
                        // Solo chi lo riattiva esplicitamente esegue la richiesta.
                        if ( ! apply_filters( 'nexus_news_sitemap_ping', false, $article_id ) ) {
                                return;
                        }

                        $response = wp_remote_get(
                                self::PING_ENDPOINT . urlencode( home_url( '/nexus-news-sitemap.xml' ) ),
                                array( 'timeout' => 3 )
                        );

                        if ( is_wp_error( $response ) ) {
                                self::log_activity( 'Ping sitemap Google errore: ' . substr( (string) $response->get_error_message(), 0, 120 ), 'error' );

                                return;
                        }

                        $code = (int) wp_remote_retrieve_response_code( $response );

                        if ( $code >= 200 && $code < 300 ) {
                                self::log_activity( 'Ping sitemap inviato a Google (HTTP ' . $code . ')', 'success' );
                        } else {
                                self::log_activity( 'Ping sitemap Google HTTP ' . $code, 'error' );
                        }
                } catch ( Exception $e ) {
                        // Mai bloccare la pubblicazione per un ping.
                } catch ( Throwable $e ) {
                        // Idem per errori PHP 7+.
                }
        }

        /**
         * Filtro robots_txt: se abilitato dichiara la sitemap ai crawler.
         *
         * @param string $output Output robots.txt generato da WordPress.
         * @param bool   $public True se il sito incoraggia l'indicizzazione.
         * @return string
         */
        public static function robots_txt( $output, $public ) {
                if ( ! self::is_enabled() ) {
                        return $output;
                }

                return $output . "\nSitemap: " . home_url( '/nexus-news-sitemap.xml' );
        }

        /**
         * Deattivazione plugin: nessuna azione necessaria.
         *
         * La rewrite rule non richiede pulizia qui: alla riattivazione il
         * flush controllato su register_rewrite() la rimuove (o la ripristina)
         * una volta sola in base allo stato del setting.
         *
         * @return void
         */
        public static function deactivate() {
                // Intenzionalmente vuota (contratto modulo, vedi docblock).
        }

        /* ============ HELPER PRIVATI ============ */

        /**
         * True se il setting news_sitemap_enabled e' attivo.
         *
         * @return bool
         */
        private static function is_enabled() {
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'get_settings' ) ) {
                        return false;
                }

                try {
                        $settings = Nexus_DB::get_settings();
                } catch ( Exception $e ) {
                        return false;
                } catch ( Throwable $e ) {
                        return false;
                }

                return ! empty( $settings['news_sitemap_enabled'] );
        }

        /**
         * Articoli candidati alla sitemap: post WP reali (dual-mode: post
         * nativo + CPT legacy, stessi tipi di Nexus_Ping), pubblicati, nelle
         * ultime 48 ORE (vincolo Google News), max 1000, data DESC.
         *
         * La finestra 48h confronta post_date_gmt (UTC) con un timestamp UTC:
         * nessun errore di fuso. Query snella (no found rows, no cache meta/term).
         *
         * @return WP_Post[]
         */
        private static function query_recent_posts() {
                $types = class_exists( 'Nexus_Native' ) && method_exists( 'Nexus_Native', 'query_post_types' )
                        ? Nexus_Native::query_post_types()
                        : array( 'post', 'nexus_article' );

                $query = new WP_Query(
                        array(
                                'post_type'              => $types,
                                'post_status'            => 'publish',
                                'posts_per_page'         => self::MAX_URLS,
                                'orderby'                => 'date',
                                'order'                  => 'DESC',
                                'ignore_sticky_posts'    => true,
                                'no_found_rows'          => true,
                                'update_post_meta_cache' => false,
                                'update_post_term_cache' => false,
                                'date_query'             => array(
                                        array(
                                                'column' => 'post_date_gmt',
                                                'after'  => gmdate( 'Y-m-d H:i:s', time() - 48 * HOUR_IN_SECONDS ),
                                        ),
                                ),
                        )
                );

                return $query->posts;
        }

        /**
         * Emette il blocco <url> di un singolo post. Tutti i valori escapati:
         * esc_url per <loc>, esc_html per name/language/date/title.
         *
         * @param WP_Post $post Post pubblicato.
         * @return void
         */
        private static function render_url_entry( $post ) {
                $permalink = get_permalink( $post );

                if ( ! is_string( $permalink ) || '' === $permalink ) {
                        return;
                }

                echo '<url>' . "\n";
                echo '<loc>' . esc_url( $permalink ) . '</loc>' . "\n";
                echo '<news:news>' . "\n";
                echo '<news:publication>' . "\n";
                echo '<news:name>' . esc_html( self::publication_name() ) . '</news:name>' . "\n";
                echo '<news:language>' . esc_html( self::publication_language() ) . '</news:language>' . "\n";
                echo '</news:publication>' . "\n";
                echo '<news:publication_date>' . esc_html( self::publication_date_iso( $post ) ) . '</news:publication_date>' . "\n";
                echo '<news:title>' . esc_html( $post->post_title ) . '</news:title>' . "\n";
                echo '</news:news>' . "\n";
                echo '</url>' . "\n";
        }

        /**
         * Testata della pubblicazione: setting news_sitemap_name se non vuoto,
         * altrimenti nome del sito.
         *
         * @return string
         */
        private static function publication_name() {
                $name = '';

                if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'get_settings' ) ) {
                        try {
                                $settings = Nexus_DB::get_settings();
                                $name     = isset( $settings['news_sitemap_name'] ) ? trim( (string) $settings['news_sitemap_name'] ) : '';
                        } catch ( Exception $e ) {
                                $name = '';
                        } catch ( Throwable $e ) {
                                $name = '';
                        }
                }

                if ( '' === $name ) {
                        $name = (string) get_bloginfo( 'name' );
                }

                return $name;
        }

        /**
         * Lingua della pubblicazione: parte prima di '-' di
         * get_bloginfo('language') (es. it-IT → it), fallback 'en'.
         *
         * @return string
         */
        private static function publication_language() {
                $lang = (string) get_bloginfo( 'language' );
                $pos  = strpos( $lang, '-' );

                if ( false !== $pos ) {
                        $lang = substr( $lang, 0, $pos );
                }

                $lang = strtolower( trim( $lang ) );

                return '' !== $lang ? $lang : 'en';
        }

        /**
         * Data di pubblicazione in ISO 8601 (gmdate('c')) da post_date_gmt.
         *
         * Fallback: post senza GMT valorizzato (import legacy '0000-00-00')
         * usano post_date; timestamp assente → ora corrente (mai XML invalido).
         *
         * @param WP_Post $post Post pubblicato.
         * @return string
         */
        private static function publication_date_iso( $post ) {
                $gmt = (string) $post->post_date_gmt;
                $ts  = false;

                if ( '' !== $gmt && '0000-00-00 00:00:00' !== $gmt ) {
                        $ts = strtotime( $gmt );
                }

                if ( false === $ts ) {
                        $ts = strtotime( (string) $post->post_date );
                }

                if ( false === $ts || $ts <= 0 ) {
                        $ts = time();
                }

                return gmdate( 'c', $ts );
        }

        /**
         * Log attività best-effort nella tabella activity_logs di Nexus.
         *
         * @param string $detail Descrizione breve dell'esito.
         * @param string $status 'success' oppure 'error'.
         * @return void
         */
        private static function log_activity( $detail, $status ) {
                try {
                        if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                                Nexus_DB::insert_activity( 0, 'news_sitemap', $detail, $status );
                        }
                } catch ( Exception $e ) {
                        // Log best-effort: mai interrompere il flusso per un errore di scrittura.
                } catch ( Throwable $e ) {
                        // Idem per errori PHP 7+.
                }
        }
}

add_action( 'nexus_modules_init', array( 'Nexus_News_Sitemap', 'init' ) );
