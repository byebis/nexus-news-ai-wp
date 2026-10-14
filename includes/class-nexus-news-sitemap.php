<?php
/**
 * Nexus News Sitemap — GOOGLE NEWS SITEMAP v1.1.12 (pacchetto S5).
 *
 * v1.1.14 (P6): aggiunta la SITEMAP IMMAGINI su /nexus-images.xml (stesso
 * meccanismo della news sitemap: stessa query var pattern, stessa rewrite
 * controllata dal setting news_sitemap_enabled, stesso flush via option,
 * stessa robots_txt). Il modulo Video Sitemap (file Nuovo
 * class-nexus-video-sitemap.php, classe self-registrante) è caricato qui sotto:
 * è l'unico punto di caricamento autorizzato dal task P6, il bootstrap resta
 * intatto.
 *
 * SCOPO:
 * Espone la sitemap Google News all'indirizzo /nexus-news-sitemap.xml per i
 * post WordPress REALI pubblicati dal plugin (post nativo 'post' + CPT legacy
 * 'nexus_article', stessa copertura dual-mode di Nexus_Ping via
 * Nexus_Native::query_post_types()) e pinga Google dopo ogni pubblicazione.
 * Da v1.1.14 (P6) espone anche la sitemap immagini all'indirizzo
 * /nexus-images.xml: articoli pubblicati con le LORO immagini (featured image
 * gestita da Nexus_Image::ensure_featured() → attachment WP con metadati;
 * immagini nel contenuto SOLO quando sono attachment della libreria media del
 * sito, riconosciute dalla classe wp-image-{ID} nell'<img>).
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

// v1.1.14 (P6): carica il modulo Video Sitemap (classe self-registrante via
// hook 'nexus_modules_init'). Questo require è l'unico punto di caricamento
// autorizzato dal task P6: il bootstrap nexus-news-ai.php NON viene toccato.
if ( defined( 'NEXUS_PLUGIN_DIR' ) ) {
        require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-video-sitemap.php';
}

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
         * v1.1.14 (P6): query var che attiva il rendering della sitemap immagini
         * su template_redirect.
         */
        const QUERY_VAR_IMAGES = 'nexus_image_sitemap';

        /**
         * v1.1.14 (P6): massimo numero di PAGINE nella sitemap immagini (stessa
         * cap della news sitemap; query con LIMIT, orderby date DESC).
         */
        const MAX_POSTS_IMAGES = 1000;

        /**
         * v1.1.14 (P6): massimo numero di blocchi image:image per pagina
         * (cap difensivo; il limite Google è 1000 per URL).
         */
        const MAX_IMAGES_PER_POST = 10;

        /**
         * Bootstrap modulo (self-registrato su nexus_modules_init a fondo file).
         *
         * @return void
         */
        public static function init() {
                add_filter( 'query_vars', array( __CLASS__, 'add_query_var' ) );
                add_action( 'init', array( __CLASS__, 'register_rewrite' ) );
                add_action( 'template_redirect', array( __CLASS__, 'render_sitemap' ) );
                add_action( 'template_redirect', array( __CLASS__, 'render_image_sitemap' ) );
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

                        // v1.1.14 (P6): rule della sitemap immagini, stesso interruttore
                        // (nessun setting dedicato: lo schema DB è fuori perimetro).
                        add_rewrite_rule( 'nexus-images\.xml$', 'index.php?' . self::QUERY_VAR_IMAGES . '=1', 'top' );
                }

                // Flush controllato: solo se lo stato attuale differisce da quello
                // per cui le rules sono state rigenerate l'ultima volta. L'option
                // copre ENTRAMBE le rule (news + immagini): il gate è lo stesso
                // setting, quindi la transizione enabled↔disabled è simultanea.
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
                $vars[] = self::QUERY_VAR_IMAGES;

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
         * Hook 'template_redirect': serving dell'XML della SITEMAP IMMAGINI
         * (v1.1.14, P6).
         *
         * - Richiesta diversa da nexus_image_sitemap=1 → esce subito (pagina normale).
         * - Modulo disabilitato → 404 e terminazione.
         * - Altrimenti: header XML + nocache_headers() + urlset (namespace image
         *   1.1 di Google) con SOLO le pagine che hanno almeno un'immagine:
         *   featured image (gestita dal plugin) + immagini nel contenuto quando
         *   sono attachment del sito (classe wp-image-{ID} risolta).
         *
         * @return void
         */
        public static function render_image_sitemap() {
                if ( 1 !== (int) get_query_var( self::QUERY_VAR_IMAGES ) ) {
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
                echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

                foreach ( self::query_posts_for_images() as $post ) {
                        $images = self::collect_post_images( $post );

                        // Nessuna immagine raggiungibile → NESSUNA voce <url> per
                        // il post (una entry senza image:image sarebbe inutile).
                        if ( ! empty( $images ) ) {
                                self::render_image_url_entry( $post, $images );
                        }
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

                return $output
                        . "\nSitemap: " . home_url( '/nexus-news-sitemap.xml' )
                        . "\nSitemap: " . home_url( '/nexus-images.xml' );
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
         * v1.1.14 (P6): post candidati alla sitemap immagini: post WP reali
         * (dual-mode, stessi tipi della news sitemap), pubblicati, max 1000,
         * data DESC, NESSUN vincolo 48h (le immagini non hanno la finestra
         * temporale di Google News). Query snella (no found rows, no term
         * cache); la meta cache resta attiva perché serve _thumbnail_id per
         * OGNI post: viene primata in una sola query aggiuntiva.
         *
         * @return WP_Post[]
         */
        private static function query_posts_for_images() {
                $types = class_exists( 'Nexus_Native' ) && method_exists( 'Nexus_Native', 'query_post_types' )
                        ? Nexus_Native::query_post_types()
                        : array( 'post', 'nexus_article' );

                $query = new WP_Query(
                        array(
                                'post_type'              => $types,
                                'post_status'            => 'publish',
                                'posts_per_page'         => self::MAX_POSTS_IMAGES,
                                'orderby'                => 'date',
                                'order'                  => 'DESC',
                                'ignore_sticky_posts'    => true,
                                'no_found_rows'          => true,
                                'update_post_meta_cache' => true,
                                'update_post_term_cache' => false,
                        )
                );

                return $query->posts;
        }

        /**
         * v1.1.14 (P6): raccoglie le immagini di un post (max MAX_IMAGES_PER_POST,
         * dedup per URL):
         * 1. FEATURED IMAGE — gestita dal plugin (Nexus_Image::ensure_featured
         *    imposta _thumbnail_id su attachment WP): URL size 'full' + title
         *    (post_title attachment) + caption (post_excerpt attachment).
         * 2. IMMAGINI NEL CONTENUTO — SOLO quelle gestite dalla libreria media
         *    del sito: <img> con classe wp-image-{ID} risolta su un attachment
         *    reale con mime image/*. Le immagini remote hotlinkate nel contenuto
         *    AI NON sono gestite dal plugin (Nexus_Image gestisce solo la
         *    featured) e vengono ESCLUSE: nessuna finzione.
         *
         * @param WP_Post $post Post pubblicato.
         * @return array[] Lista array{loc: string, title: string, caption: string}.
         */
        private static function collect_post_images( $post ) {
                $images = array();
                $seen   = array();

                // 1) Featured image (attachment del sito, metadati reali).
                $thumb_id = (int) get_post_thumbnail_id( $post );
                if ( $thumb_id > 0 ) {
                        $entry = self::attachment_image_entry( $thumb_id );

                        if ( null !== $entry ) {
                                $images[]              = $entry;
                                $seen[ $entry['loc'] ] = true;
                        }
                }

                // 2) Immagini nel contenuto: solo attachment del sito (wp-image-{ID}).
                $content = (string) $post->post_content;

                if ( '' !== $content && preg_match_all( '#<img\b[^>]*>#i', $content, $tags ) ) {
                        foreach ( $tags[0] as $tag ) {
                                if ( ! preg_match( '#\bwp-image-(\d{1,10})\b#i', (string) $tag, $m ) ) {
                                        // Nessun id attachment: immagine non gestita → esclusa.
                                        continue;
                                }

                                $attach_id = absint( $m[1] );
                                if ( $attach_id < 1 ) {
                                        continue;
                                }

                                $entry = self::attachment_image_entry( $attach_id );

                                if ( null === $entry || isset( $seen[ $entry['loc'] ] ) ) {
                                        continue;
                                }

                                $images[]              = $entry;
                                $seen[ $entry['loc'] ] = true;

                                if ( count( $images ) >= self::MAX_IMAGES_PER_POST ) {
                                        break;
                                }
                        }
                }

                return $images;
        }

        /**
         * v1.1.14 (P6): dati di un'immagine-attachment (URL size full, title,
         * caption). Null se l'ID non è un attachment immagine reale del sito
         * o non ha un URL utilizzabile.
         *
         * @param int $attach_id ID attachment.
         * @return array|null array{loc: string, title: string, caption: string}|null
         */
        private static function attachment_image_entry( $attach_id ) {
                $attachment = get_post( $attach_id );

                if ( ! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type ) {
                        return null;
                }

                // Solo mime image/*: allegati non immagine (PDF, video) esclusi.
                if ( 0 !== strpos( (string) $attachment->post_mime_type, 'image/' ) ) {
                        return null;
                }

                $url = wp_get_attachment_image_url( $attach_id, 'full' );
                if ( ! is_string( $url ) || '' === $url ) {
                        return null;
                }

                return array(
                        'loc'     => $url,
                        'title'   => self::xml_text( (string) $attachment->post_title ),
                        'caption' => self::xml_text( (string) $attachment->post_excerpt ),
                );
        }

        /**
         * v1.1.14 (P6): emette il blocco <url> di un singolo post con le sue
         * immagini (image:loc sempre; image:title/image:caption se disponibili).
         * Tutti i valori escapati: URL con esc_url_raw + htmlspecialchars(ENT_XML1),
         * testi con lo stesso escaping XML.
         *
         * @param WP_Post  $post   Post pubblicato.
         * @param array[]  $images Lista array{loc,title,caption} (da collect_post_images).
         * @return void
         */
        private static function render_image_url_entry( $post, $images ) {
                $permalink = get_permalink( $post );

                if ( ! is_string( $permalink ) || '' === $permalink || empty( $images ) ) {
                        return;
                }

                echo '<url>' . "\n";
                echo '<loc>' . self::xml_escape( esc_url_raw( $permalink ) ) . '</loc>' . "\n";

                foreach ( $images as $image ) {
                        if ( ! is_array( $image ) || empty( $image['loc'] ) ) {
                                continue;
                        }

                        echo '<image:image>' . "\n";
                        echo '<image:loc>' . self::xml_escape( esc_url_raw( (string) $image['loc'] ) ) . '</image:loc>' . "\n";

                        if ( ! empty( $image['title'] ) ) {
                                echo '<image:title>' . self::xml_escape( (string) $image['title'] ) . '</image:title>' . "\n";
                        }
                        if ( ! empty( $image['caption'] ) ) {
                                echo '<image:caption>' . self::xml_escape( (string) $image['caption'] ) . '</image:caption>' . "\n";
                        }

                        echo '</image:image>' . "\n";
                }

                echo '</url>' . "\n";
        }

        /**
         * v1.1.14 (P6): normalizza un testo per i tag XML (strip tag + collasso
         * spazi). L'escaping XML vero avviene in xml_escape().
         *
         * @param string $text Testo di partenza.
         * @return string
         */
        private static function xml_text( $text ) {
                $text = trim( wp_strip_all_tags( (string) $text ) );

                return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
        }

        /**
         * v1.1.14 (P6): escaping XML per valori testuali e URL già normalizzati
         * con esc_url_raw: htmlspecialchars con ENT_XML1|ENT_COMPAT e UTF-8
         * (escapa & < > " — i requisiti di well-formedness XML).
         *
         * @param string $value Valore da escapare.
         * @return string
         */
        private static function xml_escape( $value ) {
                return htmlspecialchars( (string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8' );
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
