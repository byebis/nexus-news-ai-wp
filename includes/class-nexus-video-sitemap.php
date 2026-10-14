<?php
/**
 * Nexus Video Sitemap — VIDEO SITEMAP v1.1.14 (pacchetto P6).
 *
 * SCOPO:
 * Espone la video sitemap all'indirizzo /nexus-videos.xml per gli articoli
 * pubblicati che CONTENGONO VIDEOS REALLY INCORPORATI nel contenuto (embed
 * YouTube: URL nudi auto-embed di WordPress, [embed], o iframe con src
 * youtube.com/embed — gli URL auto-embed e gli iframe producono il player
 * sulla pagina, quindi l'articolo è una pagina video legittima).
 *
 * CARICAMENTO (nota P6):
 * Il file è caricato da includes/class-nexus-news-sitemap.php (unico punto
 * autorizzato dal task P6): la classe NON richiede modifiche al bootstrap
 * (nexus-news-ai.php resta intatto) e si registra da sola tramite l'hook
 * 'nexus_modules_init', esattamente come i moduli del pacchetto S1-S7.
 *
 * ONESTÀ / LIMITI (contratto P6: niente finzione):
 * - Il plugin NON ha tracciamento dedicato dei video: non esistono meta
 *   video sul CPT né campi dedicati su wp_nexus_articles. L'unico tracciato
 *   raggiungibile è l'embed REALE dentro post_content (URL YouTube nudi che
 *   WordPress trasforma in player, shortcode [embed], iframe).
 * - Gli articoli generati da fonti YouTube (articles.source_type = 'youtube')
 *   NON vengono inseriti se il video non è incorporato nel contenuto: il
 *   video riproduce su YouTube, non sulla pagina articolo, e una voce video
 *   sarebbe fuorviante per Google. Resta tracciato nel docblock, non nei dati.
 * - video:title/video:description usano titolo/estratto del POST (il titolo
 *   del video YouTube non è tracciato dal plugin): descrivono la pagina.
 * - video:thumbnail_loc usa l'URL deterministico e pubblico di YouTube
 *   (https://i.ytimg.com/vi/{id}/hqdefault.jpg), l'unico thumbnail raggiungibile
 *   senza tracciamento dedicato.
 * - video:family_friendly = yes: il plugin non traccia rating per età; il
 *   valore esplicito coincide con il default Google (assenza del tag = yes).
 * - Nessun video:duration/video:uploader/video:live: dati non tracciati,
 *   non vengono inventati.
 * - Sitemap vuota (urlset senza voci) è uno stato lecito e documentato.
 *
 * COMPORTAMENTO:
 * - Rewrite (hook 'init'): nexus-videos\.xml$ → index.php?nexus_video_sitemap=1
 *   aggiunta SOLO quando il setting news_sitemap_enabled = 1 (NON esiste un
 *   setting dedicato: lo schema DB è fuori perimetro P6; la sitemap video
 *   segue lo stesso interruttore della news sitemap, stesso meccanismo).
 *   Flush controllato dall'option 'nexus_video_sitemap_flushed' (1|0):
 *   flush UNA sola volta per transizione enabled↔disabled, mai ad ogni request.
 * - Render (hook 'template_redirect'): Content-Type application/xml;
 *   charset=UTF-8 + nocache_headers() (come la news sitemap); modulo
 *   disabilitato → 404. Namespace video 0.9 di Google.
 * - Query: post pubblicati (dual-mode Nexus_Native::query_post_types()),
 *   max 1000, orderby date DESC, no found rows: stessa disciplina della
 *   news sitemap. Nessun ping aggiuntivo a Google (come da v1.1.13 O1).
 * - robots_txt: se abilitato aggiunge la riga "Sitemap: {url}".
 * - CACHE dell'output XML (v1.1.15, WF6): l'OUTPUT finale della video sitemap
 *   è tenuto nel transient 'nexus_video_sitemap_cache' (TTL 2 ore, filtro
 *   nexus_sitemap_cache_ttl — lo STESSO filtro della sitemap immagini/news —
 *   0 = cache spenta, comportamento v1.1.14): il costo della query su 1000
 *   post completi (contenuti interi in memoria) scade a UNA volta per TTL.
 *   Invalidazione su nexus_pipeline_after_publish e su save_post dei tipi
 *   pubblicati (dual-mode, edit umano compreso). nocache_headers() resta
 *   (intestazioni verso il client): il Googlebot riceve sempre XML coerente,
 *   rigenerato al prossimo hit dopo l'invalidazione.
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

final class Nexus_Video_Sitemap {

        /**
         * Query var che attiva il rendering della video sitemap.
         */
        const QUERY_VAR = 'nexus_video_sitemap';

        /**
         * Option di controllo del flush: memorizza l'ultimo stato (1|0) per cui
         * le rewrite rules sono state rigenerate. Cambio di stato → flush UNA volta.
         */
        const OPTION_FLUSHED = 'nexus_video_sitemap_flushed';

        /**
         * Massimo numero di URL (pagine) nella sitemap: stessa cap della news
         * sitemap, query con LIMIT e orderby date DESC.
         */
        const MAX_POSTS = 1000;

        /**
         * Massimo numero di blocchi video:video per pagina (cap difensivo,
         * evita entry enormi su articoli con decine di embed).
         */
        const MAX_VIDEOS_PER_POST = 10;

        /**
         * v1.1.15 (WF6b): chiave del transient con l'OUTPUT XML della video
         * sitemap (TTL 2h, filtro nexus_sitemap_cache_ttl). Invalidato su
         * nexus_pipeline_after_publish e su save_post dei tipi pubblicati.
         */
        const CACHE_KEY_VIDEOS = 'nexus_video_sitemap_cache';

        /**
         * Pattern degli embed YouTube riconosciuti nel contenuto. Cattura l'ID
         * video dalla FORMA completa dell'URL (youtu.be/ID anche con prefisso
         * shorts/ o live/, youtube.com/embed/ID, /shorts/ID, /live/ID, /v/ID,
         * watch?v=ID anche con parametri prima/dopo, inclusa la variante
         * -nocookie). Charset ID: [A-Za-z0-9_-]. Il matching avviene su una
         * copia html_entity_decode del contenuto (vedi extract_youtube_ids).
         */
        const YT_PATTERN = '#(?:youtu\.be/(?:shorts/|live/)?|youtube(?:-nocookie)?\.com/(?:embed/|shorts/|live/|v/|watch\?(?:[^>"\'\s]*&)?v=))([A-Za-z0-9_-]{6,20})#i';

        /**
         * Segmenti di path YouTube riservati che NON sono ID video: la cattura
         * {6,20} potrebbe toccarli su link incompleti (es. youtu.be/shorts):
         * la guardia in extract_youtube_ids li scarta.
         */
        const YT_RESERVED = array( 'shorts', 'live', 'embed', 'watch', 'v', 'channel', 'user', 'c', 'playlist', 'results' );

        /**
         * Bootstrap modulo (self-registrato su nexus_modules_init a fondo file).
         *
         * @return void
         */
        public static function init() {
                // v1.1.15 (glue, replica WF6d della news sitemap): seeding dello
                // stato di flush — un'installazione fresca faceva leggere
                // register_rewrite() il default -1 e causava un
                // flush_rewrite_rules fantasma al primo request anche a modulo
                // SPENTO. Seeminando 0 il flush avviene solo alla vera
                // transizione enabled↔disabled: nessun flush al seeding.
                if ( false === get_option( self::OPTION_FLUSHED ) ) {
                        update_option( self::OPTION_FLUSHED, 0 );
                }

                add_filter( 'query_vars', array( __CLASS__, 'add_query_var' ) );
                add_action( 'init', array( __CLASS__, 'register_rewrite' ) );
                add_action( 'template_redirect', array( __CLASS__, 'render_sitemap' ) );
                add_filter( 'robots_txt', array( __CLASS__, 'robots_txt' ), 10, 2 );

                // v1.1.15 (WF6b): invalidazione della cache XML della video
                // sitemap a ogni pubblicazione Nexus e a ogni salvataggio dei
                // tipi pubblicati (dual-mode: edit umano compreso — correttezza
                // prima dell'efficienza: la cache si rigenera al prossimo hit).
                add_action( 'nexus_pipeline_after_publish', array( __CLASS__, 'flush_xml_cache' ), 5 );
                add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 10, 2 );
        }

        /**
         * Hook 'init': registra la rewrite rule della video sitemap (solo se
         * abilitata) e gestisce il flush controllato via option
         * nexus_video_sitemap_flushed (stesso meccanismo della news sitemap).
         *
         * @return void
         */
        public static function register_rewrite() {
                $enabled = self::is_enabled();

                // Rule presente SOLO quando il modulo e' abilitato: alla
                // disattivazione basta non aggiungerla.
                if ( $enabled ) {
                        add_rewrite_rule( 'nexus-videos\.xml$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
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
         * Filtro query_vars: aggiunge 'nexus_video_sitemap' a quelle riconosciute.
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
         * Hook 'template_redirect': serving dell'XML della video sitemap.
         *
         * - Richiesta diversa da nexus_video_sitemap=1 → esce subito (pagina normale).
         * - Modulo disabilitato → 404 e terminazione.
         * - Altrimenti: header XML + nocache_headers() + urlset (namespace video 0.9)
         *   con le pagine che incorporano video, una voce video:video per embed.
         *   Nessuna voce → urlset vuoto (stato lecito, documentato nel docblock).
         *   v1.1.15 (WF6b): l'OUTPUT è cachato nel transient
         *   nexus_video_sitemap_cache (TTL 2h, filtro nexus_sitemap_cache_ttl):
         *   hit → echo + exit; miss → rigenerazione + set_transient.
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

                // v1.1.15 (WF6b): cache dell'OUTPUT XML in un transient (TTL 2h,
                // filtro nexus_sitemap_cache_ttl, 0 = cache spenta): il costo della
                // query su 1000 post completi scade a una volta per TTL.
                // nocache_headers resta (intestazioni verso il client): il Googlebot
                // riceve sempre XML coerente e il contenuto è sempre fresco dopo
                // l'invalidazione.
                $ttl = self::cache_ttl();

                if ( $ttl > 0 ) {
                        $cached = get_transient( self::CACHE_KEY_VIDEOS );

                        if ( is_string( $cached ) && '' !== $cached ) {
                                echo $cached;
                                exit;
                        }
                }

                ob_start();

                echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
                echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">' . "\n";

                foreach ( self::query_published_posts() as $post ) {
                        self::render_url_entry( $post );
                }

                echo '</urlset>' . "\n";

                $xml = (string) ob_get_clean();

                if ( $ttl > 0 ) {
                        set_transient( self::CACHE_KEY_VIDEOS, $xml, $ttl );
                }

                echo $xml;
                exit;
        }

        /**
         * v1.1.15 (WF6b): invalidazione della cache XML della video sitemap.
         *
         * Registrata su nexus_pipeline_after_publish (priorità 5) e chiamata
         * da on_save_post() su save_post dei tipi pubblicati. delete_transient
         * è idempotente: sicuro anche a cache mai generata.
         *
         * @return void
         */
        public static function flush_xml_cache() {
                delete_transient( self::CACHE_KEY_VIDEOS );
        }

        /**
         * v1.1.15 (WF6b): listener di save_post per l'invalidazione cache.
         *
         * Gated sui tipi pubblicati dual-mode (Nexus_Native::query_post_types(),
         * fallback post + nexus_article): un edit manuale a un post nativo
         * cambia gli embed nel contenuto tanto quanto una pubblicazione Nexus,
         * quindi busta la cache (correttezza prima dell'efficienza: cache
         * rigenerata al prossimo hit). save_post gira anche per revisioni/
         * autosave: i tipi estranei escono subito senza toccare nulla.
         *
         * @param int     $post_id ID del post salvato.
         * @param WP_Post $post    Oggetto post salvato.
         * @return void
         */
        public static function on_save_post( $post_id, $post ) {
                if ( ! $post instanceof WP_Post ) {
                        return;
                }

                $types = class_exists( 'Nexus_Native' ) && method_exists( 'Nexus_Native', 'query_post_types' )
                        ? Nexus_Native::query_post_types()
                        : array( 'post', 'nexus_article' );

                if ( in_array( $post->post_type, (array) $types, true ) ) {
                        self::flush_xml_cache();
                }
        }

        /**
         * v1.1.15 (WF6b): TTL della cache XML (filtro nexus_sitemap_cache_ttl,
         * lo STESSO filtro della sitemap immagini/news: un dev che lo regola
         * copre tutte le sitemap Nexus). Default 2 ore; 0 o negativo = cache
         * DISATTIVATA (rigenerazione a ogni hit, comportamento v1.1.14).
         *
         * @return int TTL in secondi (0 = cache spenta).
         */
        private static function cache_ttl() {
                $ttl = (int) apply_filters( 'nexus_sitemap_cache_ttl', 2 * HOUR_IN_SECONDS );

                return $ttl > 0 ? $ttl : 0;
        }

        /**
         * Filtro robots_txt: se abilitato dichiara la video sitemap ai crawler.
         *
         * @param string $output Output robots.txt generato da WordPress.
         * @param bool   $public True se il sito incoraggia l'indicizzazione.
         * @return string
         */
        public static function robots_txt( $output, $public ) {
                if ( ! self::is_enabled() ) {
                        return $output;
                }

                return $output . "\nSitemap: " . home_url( '/nexus-videos.xml' );
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
         * True se il setting news_sitemap_enabled e' attivo (interruttore
         * condiviso con la news sitemap: nessun setting dedicato esiste, lo
         * schema DB e' fuori perimetro P6 — vedi docblock del modulo).
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
         * Pagine candidate: post WP reali (dual-mode: post nativo + CPT legacy,
         * stessi tipi di Nexus_Ping/news sitemap), pubblicati, max 1000, data
         * DESC. Query snella (no found rows, nessuna cache meta/term: il
         * contenuto è già sugli oggetti post restituiti).
         *
         * @return WP_Post[]
         */
        private static function query_published_posts() {
                $types = class_exists( 'Nexus_Native' ) && method_exists( 'Nexus_Native', 'query_post_types' )
                        ? Nexus_Native::query_post_types()
                        : array( 'post', 'nexus_article' );

                $query = new WP_Query(
                        array(
                                'post_type'              => $types,
                                'post_status'            => 'publish',
                                'posts_per_page'         => self::MAX_POSTS,
                                'orderby'                => 'date',
                                'order'                  => 'DESC',
                                'ignore_sticky_posts'    => true,
                                'no_found_rows'          => true,
                                'update_post_meta_cache' => false,
                                'update_post_term_cache' => false,
                        )
                );

                return $query->posts;
        }

        /**
         * Emette il blocco <url> di un singolo post SE il contenuto incorpora
         * almeno un video YouTube. Tutti i valori escapati: URL con esc_url_raw
         * + htmlspecialchars(ENT_XML1), testi con lo stesso escaping.
         *
         * @param WP_Post $post Post pubblicato.
         * @return void
         */
        private static function render_url_entry( $post ) {
                $permalink = get_permalink( $post );

                if ( ! is_string( $permalink ) || '' === $permalink ) {
                        return;
                }

                $video_ids = self::extract_youtube_ids( (string) $post->post_content );

                if ( empty( $video_ids ) ) {
                        // Nessun embed realmente incorporato: NESSUNA voce (onestà,
                        // vedi docblock modulo).
                        return;
                }

                $video_ids = array_slice( $video_ids, 0, self::MAX_VIDEOS_PER_POST );

                echo '<url>' . "\n";
                echo '<loc>' . self::xml_escape( esc_url_raw( $permalink ) ) . '</loc>' . "\n";

                foreach ( $video_ids as $video_id ) {
                        self::render_video_entry( $post, $video_id );
                }

                echo '</url>' . "\n";
        }

        /**
         * Emette un blocco <video:video> per un ID YouTube incorporato nel post.
         *
         * Ordine figli secondo lo standard Google: thumbnail_loc, title,
         * description, player_loc, publication_date, family_friendly.
         * player_loc (embed) e NON content_loc: per YouTube non esiste un file
         * video grezzo raggiungibile, il player è la forma corretta.
         *
         * @param WP_Post $post     Post pubblicato.
         * @param string  $video_id ID video YouTube (charset validato).
         * @return void
         */
        private static function render_video_entry( $post, $video_id ) {
                $thumbnail = 'https://i.ytimg.com/vi/' . $video_id . '/hqdefault.jpg';
                $player    = 'https://www.youtube.com/embed/' . $video_id;
                $title     = self::xml_text( (string) $post->post_title );
                $desc      = self::video_description( $post );

                if ( '' === $title ) {
                        $title = $video_id; // Mai titolo vuoto: ultima spiaggia l'ID stesso.
                }

                echo '<video:video>' . "\n";
                echo '<video:thumbnail_loc>' . self::xml_escape( esc_url_raw( $thumbnail ) ) . '</video:thumbnail_loc>' . "\n";
                echo '<video:title>' . self::xml_escape( $title ) . '</video:title>' . "\n";
                echo '<video:description>' . self::xml_escape( $desc ) . '</video:description>' . "\n";
                echo '<video:player_loc>' . self::xml_escape( esc_url_raw( $player ) ) . '</video:player_loc>' . "\n";
                echo '<video:publication_date>' . self::xml_escape( self::publication_date_iso( $post ) ) . '</video:publication_date>' . "\n";
                // Il plugin non traccia rating per età: 'yes' è il default Google
                // (assenza del tag = yes), reso esplicito per completezza.
                echo '<video:family_friendly>yes</video:family_friendly>' . "\n";
                echo '</video:video>' . "\n";
        }

        /**
         * Estrae gli ID YouTube dagli embed nel contenuto (dedup, ordine di
         * comparsa). Il matching gira su una copia html_entity_decode del
         * contenuto (vedi commento nel corpo). Solo ID con charset
         * [A-Za-z0-9_-] da FORME embed esplicite: youtu.be/ID (anche /shorts/,
         * /live/), youtube(-nocookie).com/embed/ID, /shorts/ID, /live/ID,
         * /v/ID, watch?v=ID (con o senza parametri aggiuntivi). URL YouTube
         * citati come testo non in forma embed (es. in un link «Fonte»)
         * producono comunque un auto-embed di WordPress: sono ammessi.
         *
         * @param string $content Contenuto del post.
         * @return string[] ID video unici.
         */
        private static function extract_youtube_ids( $content ) {
                $ids = array();

                if ( '' === $content ) {
                        return $ids;
                }

                // Copia decodificata SOLO per il matching (mai restituita in output):
                // nel post_content gli URL dentro attributi href/src possono avere
                // & codificato (&amp; o &#038;) e watch?v=ID&list=... verrebbe perso
                // senza decodifica.
                $haystack = html_entity_decode( (string) $content, ENT_QUOTES | ENT_HTML401, 'UTF-8' );

                if ( ! preg_match_all( self::YT_PATTERN, $haystack, $matches ) ) {
                        return $ids;
                }

                foreach ( $matches[1] as $id ) {
                        $id = trim( (string) $id );

                        // Guard charset + guard parole riservate (es. youtu.be/shorts
                        // senza ID: la cattura toccherebbe 'shorts').
                        if ( '' === $id
                                || ! preg_match( '#^[A-Za-z0-9_-]+$#', $id )
                                || in_array( strtolower( $id ), self::YT_RESERVED, true ) ) {
                                continue;
                        }

                        if ( ! in_array( $id, $ids, true ) ) {
                                $ids[] = $id;
                        }

                        if ( count( $ids ) >= self::MAX_VIDEOS_PER_POST ) {
                                break;
                        }
                }

                return $ids;
        }

        /**
         * Descrizione video: estratto del post (il publisher salva il summary
         * in post_excerpt); fallback: primo frammento pulito del contenuto
         * (max 300 caratteri); ultimo fallback: titolo del post. Mai vuota.
         *
         * @param WP_Post $post Post pubblicato.
         * @return string
         */
        private static function video_description( $post ) {
                $desc = self::xml_text( (string) get_post_field( 'post_excerpt', $post->ID ) );

                if ( '' === $desc ) {
                        $desc = self::clip( self::xml_text( (string) $post->post_content ), 300 );
                }

                if ( '' === $desc ) {
                        $desc = self::xml_text( (string) $post->post_title );
                }

                return $desc;
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
         * Testo per XML: rimuove i tag HTML e collassa gli spazi bianchi.
         * L'escaping XML vero avviene in xml_escape() (qui solo normalizzazione).
         *
         * @param string $text Testo di partenza.
         * @return string
         */
        private static function xml_text( $text ) {
                $text = trim( wp_strip_all_tags( (string) $text ) );

                return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
        }

        /**
         * Tronca un testo già normalizzato alla lunghezza massima
         * (multibyte quando disponibile).
         *
         * @param string $text Testo di partenza.
         * @param int    $max  Lunghezza massima in caratteri.
         * @return string
         */
        private static function clip( $text, $max ) {
                $text = trim( (string) $text );

                if ( $max > 0 && '' !== $text ) {
                        if ( function_exists( 'mb_substr' ) ) {
                                $text = mb_substr( $text, 0, (int) $max );
                        } else {
                                $text = substr( $text, 0, (int) $max );
                        }
                        $text = trim( $text );
                }

                return $text;
        }

        /**
         * Escaping XML per valori testuali e URL già normalizzati con
         * esc_url_raw: htmlspecialchars con ENT_XML1|ENT_COMPAT e UTF-8
         * (escapa & < > " — i requisiti di well-formedness XML).
         *
         * @param string $value Valore da escapare.
         * @return string
         */
        private static function xml_escape( $value ) {
                return htmlspecialchars( (string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8' );
        }
}

add_action( 'nexus_modules_init', array( 'Nexus_Video_Sitemap', 'init' ) );
