<?php
/**
 * Nexus Shortcodes — frontend pubblico in italiano: magazine, articoli e Nexus Wire.
 *
 * Shortcode disponibili:
 *   [nexus_magazine]                    — hero + barra categorie + griglia + modal root
 *   [nexus_articles category="" limit="9" layout="grid|list"]
 *   [nexus_wire limit="10"]             — Redazione Collettiva (ultime esecuzioni)
 *
 * =====================================================================================
 * NOTA-INTEGRAZIONE (per public/js/nexus-public.js e public/css/nexus-public.css)
 * =====================================================================================
 * Selettori ESATTI esposti dal markup di questa classe:
 *
 *   1. Contenitore magazine ........ [data-nxa="magazine"]
 *   2. Griglia articoli ............ #nxa-magazine-grid (contiene i .nxa-card)
 *   3. Card articolo ............... .nxa-card[data-article-id="{id}"]
 *                                    (click o Invio/Spazio sulla card → apre il modal;
 *                                     la card è role="button" tabindex="0")
 *   4. Bottoni filtro categoria .... .nxa-cat-btn[data-category="{category}"]
 *                                    (la voce "Tutte" ha data-category="";
 *                                     il bottone attivo porta la classe "is-active")
 *   5. Bottone "Carica altri" ...... [data-nxa="load-more"][data-page="{pagina successiva}"]
 *                                    (opzionale: data-per-page="{articoli per pagina}")
 *   6. Root del modal .............. #nxa-modal-root (vuoto: il JS inietta il modal)
 *   7. Link wire → articolo ........ a.nxa-wire-link[data-nxa="wire-read"][data-article-id="{id}"]
 *                                    con href="#nxa-article-{id}" (il JS intercetta e
 *                                    apre l'articolo nel modal invece di navigare)
 *   8. Layout lista (shortcode) .... .nxa-grid--list (variante di .nxa-grid)
 *
 * Oggetto localizzato per il JS (handle "nexus-public"):
 *   window.NexusPublicData = {
 *     restUrl: rest_url('nexus/v1'),   // es. https://sito.it/wp-json/nexus/v1
 *     nonce:   wp_create_nonce('wp_rest'),
 *     emojis:  ['❤️','👏','🔥','😮','😂','🤔'] // ordine stabile, usato per le reazioni
 *   }
 *
 * Contratto dati per le card caricate via REST (load-more / filtri):
 *   riga articolo = { id, title, subtitle, summary, content, category, agent_id,
 *                     image_url, published_at, read_time, views, reactions, ... }
 *   riga agente   = { id, name, avatar (emoji), category, status, ... }
 *   La card client-side DEVE replicare il markup di Nexus_Shortcodes::render_card()
 *   (stesse classi nxa-*) per restare coerente con nexus-public.css.
 *
 * Gli asset sono REGISTRATI su 'wp_enqueue_scripts' (enqueue_public) e viene fatto
 * l'ENQUEUE REALE solo dentro il rendering degli shortcode (WordPress stampa le
 * script in footer e gli stili in ritardo via wp_footer: è un comportamento core).
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Classe frontend: shortcode magazine, articoli e wire.
 */
final class Nexus_Shortcodes {

        /**
         * Colore di fallback per badge/dot categoria.
         */
        const FALLBACK_COLOR = '#6366f1';

        /**
         * Cache locale mappa agenti {id => oggetto}.
         *
         * @var array|null
         */
        private static $agent_map = null;

        /**
         * Costruttore privato: solo metodi statici.
         */
        private function __construct() {}

        /**
         * Bootstrap: registra shortcode + hook asset.
         *
         * @return void
         */
        public static function init() {
                add_shortcode( 'nexus_magazine', array( __CLASS__, 'render_magazine' ) );
                add_shortcode( 'nexus_articles', array( __CLASS__, 'render_articles' ) );
                add_shortcode( 'nexus_wire', array( __CLASS__, 'render_wire' ) );

                add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_public' ) );
        }

        /* ========================= ASSET ========================= */

        /**
         * Registra (senza enqueue) CSS e JS pubblico + oggetto NexusPublicData.
         * L'enqueue reale avviene in enqueue_assets() durante il rendering shortcode.
         *
         * @return void
         */
        public static function enqueue_public() {
                wp_register_style(
                        'nexus-public',
                        NEXUS_PLUGIN_URL . 'public/css/nexus-public.css',
                        array(),
                        NEXUS_VERSION
                );

                wp_register_script(
                        'nexus-public',
                        NEXUS_PLUGIN_URL . 'public/js/nexus-public.js',
                        array(),
                        NEXUS_VERSION,
                        true
                );

                wp_localize_script(
                        'nexus-public',
                        'NexusPublicData',
                        array(
                                'restUrl' => esc_url_raw( rest_url( 'nexus/v1' ) ),
                                'nonce'   => wp_create_nonce( 'wp_rest' ),
                                'emojis'  => array( '❤️', '👏', '🔥', '😮', '😂', '🤔' ),
                        )
                );
        }

        /**
         * Enqueue reale degli asset (chiamato solo durante il rendering shortcode).
         *
         * @return void
         */
        private static function enqueue_assets() {
                if ( wp_script_is( 'nexus-public', 'registered' ) ) {
                        wp_enqueue_style( 'nexus-public' );
                        wp_enqueue_script( 'nexus-public' );
                }
        }

        /* ========================= [nexus_magazine] ========================= */

        /**
         * Render del magazine completo: hero, barra categorie, griglia, load-more, modal root.
         *
         * @param array|string $atts Attributi shortcode. Supportati: per_page (default 12).
         * @return string
         */
        public static function render_magazine( $atts = array() ) {
                $atts = shortcode_atts(
                        array( 'per_page' => 12 ),
                        (array) $atts,
                        'nexus_magazine'
                );
                $per_page = min( 24, max( 1, absint( $atts['per_page'] ) ) );

                self::enqueue_assets();

                $settings = Nexus_DB::get_settings();
                $agents   = Nexus_DB::agents();

                $active_agents = array();
                foreach ( (array) $agents as $agent ) {
                        if ( isset( $agent->status ) && 'active' === $agent->status ) {
                                $active_agents[] = $agent;
                        }
                }
                $n_agents = count( $active_agents );

                $published_count = Nexus_DB::count_articles( array( 'status' => 'published' ) );
                $articles        = Nexus_DB::articles(
                        array(
                                'status'   => 'published',
                                'per_page' => $per_page,
                                'orderby'  => 'published_at',
                                'order'    => 'desc',
                        )
                );

                $out  = '';
                $out .= '<div class="nxa-magazine" data-nxa="magazine">';

                /* ---------- Hero ---------- */
                $out .= '<section class="nxa-hero" aria-labelledby="nxa-hero-title">';
                $out .= '<div class="nxa-hero-inner">';
                $out .= '<span class="nxa-hero-badge">🤖 ' . sprintf(
                        /* translators: %s: numero di agenti attivi. */
                        esc_html( _n( '%s agente giornalista AI', '%s agenti giornalisti AI', $n_agents, 'nexus-news-ai' ) ),
                        esc_html( number_format_i18n( $n_agents ) )
                ) . '</span>';
                $out .= '<h1 class="nxa-hero-title" id="nxa-hero-title">' . esc_html( (string) ( $settings['site_name'] ?? 'Nexus News AI' ) ) . '</h1>';
                $out .= '<p class="nxa-hero-tagline">' . esc_html( (string) ( $settings['site_tagline'] ?? 'Il giornale di nuova generazione' ) ) . '</p>';
                $out .= '<div class="nxa-hero-stats">';
                $out .= '<span class="nxa-hero-stat"><strong>' . esc_html( number_format_i18n( $published_count ) ) . '</strong> ' . esc_html__( 'articoli pubblicati', 'nexus-news-ai' ) . '</span>';
                $out .= '</div>';
                $out .= '</div>';
                $out .= '</section>';

                /* ---------- Barra categorie ---------- */
                $out .= '<nav class="nxa-category-bar" aria-label="' . esc_attr__( 'Filtra articoli per categoria', 'nexus-news-ai' ) . '">';
                $out .= '<button type="button" class="nxa-cat-btn is-active" data-category="">' . esc_html__( 'Tutte', 'nexus-news-ai' ) . '</button>';
                foreach ( $active_agents as $agent ) {
                        $cat = (string) $agent->category;
                        if ( '' === $cat ) {
                                continue;
                        }
                        $out .= '<button type="button" class="nxa-cat-btn" data-category="' . esc_attr( $cat ) . '">';
                        $out .= '<span class="nxa-cat-dot" style="background-color:' . self::color_attr( Nexus_Utils::category_color( $cat ) ) . '"></span>';
                        $out .= esc_html( $cat );
                        $out .= '</button>';
                }
                $out .= '</nav>';

                /* ---------- Griglia articoli ---------- */
                $out .= '<div class="nxa-grid" id="nxa-magazine-grid">';
                if ( $articles ) {
                        foreach ( $articles as $article ) {
                                $out .= self::render_card( $article );
                        }
                } else {
                        $out .= '<p class="nxa-empty">' . esc_html__( 'Nessun articolo pubblicato per il momento. Torna presto!', 'nexus-news-ai' ) . '</p>';
                }
                $out .= '</div>';

                /* ---------- Carica altri ---------- */
                if ( $published_count > $per_page ) {
                        $out .= '<div class="nxa-load-more-wrap">';
                        $out .= '<button type="button" class="nxa-btn-load-more" data-nxa="load-more" data-page="2" data-per-page="' . esc_attr( $per_page ) . '">' . esc_html__( 'Carica altri articoli', 'nexus-news-ai' ) . '</button>';
                        $out .= '</div>';
                }

                /* ---------- Root modal ---------- */
                $out .= '<div id="nxa-modal-root"></div>';

                $out .= '</div>';

                return $out;
        }

        /* ========================= Card articolo ========================= */

        /**
         * Render di una singola card articolo (server-side e riusabile).
         *
         * @param object $article Riga tabella nexus_articles.
         * @return string
         */
        public static function render_card( $article ) {
                if ( ! is_object( $article ) || empty( $article->id ) ) {
                        return '';
                }

                $agent     = self::agent( (int) $article->agent_id );
                $cat       = (string) $article->category;
                $title     = (string) $article->title;
                $subtitle  = (string) ( $article->subtitle ?? '' );
                $image_url = (string) ( $article->image_url ?? '' );

                $excerpt_text = ( '' !== trim( (string) ( $article->summary ?? '' ) ) )
                        ? (string) $article->summary
                        : wp_strip_all_tags( (string) ( $article->content ?? '' ) );

                $when     = ( ! empty( $article->published_at ) ) ? (string) $article->published_at : (string) ( $article->created_at ?? '' );
                $time_ago = $when ? Nexus_Utils::time_ago_it( $when ) : '';

                $content_words = trim( wp_strip_all_tags( (string) ( $article->content ?? '' ) ) );
                $read_time     = ( '' !== $content_words )
                        ? Nexus_Utils::reading_time_it( (string) $article->content )
                        : sprintf( /* translators: %d: minuti di lettura. */ esc_html__( '%d min', 'nexus-news-ai' ), (int) ( $article->read_time ?? 3 ) );

                $cat_color = self::color_attr( Nexus_Utils::category_color( $cat ) );
                $avatar    = ( $agent && ! empty( $agent->avatar ) ) ? (string) $agent->avatar : '🤖';
                $agent_nm  = ( $agent && ! empty( $agent->name ) ) ? (string) $agent->name : __( 'Agente AI', 'nexus-news-ai' );

                $out  = '';
                $out .= '<article class="nxa-card" data-article-id="' . esc_attr( absint( $article->id ) ) . '" data-category="' . esc_attr( $cat ) . '" role="button" tabindex="0" aria-label="' . esc_attr( sprintf( /* translators: %s: titolo articolo. */ __( 'Leggi: %s', 'nexus-news-ai' ), $title ) ) . '">';

                /* Copertina */
                $out .= '<div class="nxa-card-media" data-category="' . esc_attr( $cat ) . '">';
                if ( $image_url ) {
                        $out .= '<img class="nxa-card-img" src="' . esc_url( $image_url ) . '" alt="' . esc_attr( $title ) . '" loading="lazy" />'; 
                } else {
                        $out .= '<div class="nxa-card-cover" style="background-image:linear-gradient(135deg,' . esc_attr( $cat_color ) . '33 0%,' . esc_attr( $cat_color ) . '66 100%)">';
                        $out .= '<span class="nxa-card-avatar" aria-hidden="true">' . esc_html( $avatar ) . '</span>';
                        $out .= '</div>';
                }
                $out .= '<span class="nxa-card-cat">' . esc_html( $cat ) . '</span>';
                $out .= '<span class="nxa-card-ai">AI</span>';
                $out .= '</div>';

                /* Corpo */
                $out .= '<div class="nxa-card-body">';
                $out .= '<h3 class="nxa-card-title">' . esc_html( $title ) . '</h3>';
                if ( '' !== $subtitle ) {
                        $out .= '<p class="nxa-card-subtitle">' . esc_html( $subtitle ) . '</p>';
                }
                $out .= '<p class="nxa-card-excerpt">' . esc_html( Nexus_Utils::excerpt_it( $excerpt_text, 20 ) ) . '</p>';

                $out .= '<footer class="nxa-card-footer">';
                $out .= '<span class="nxa-card-agent"><span class="nxa-avatar" aria-hidden="true">' . esc_html( $avatar ) . '</span><span class="nxa-card-agent-name">' . esc_html( $agent_nm ) . '</span></span>';
                $out .= '<span class="nxa-card-meta">';
                if ( $time_ago ) {
                        $out .= '<span class="nxa-card-when">' . esc_html( $time_ago ) . '</span>';
                }
                if ( $time_ago && $read_time ) {
                        $out .= '<span class="nxa-sep" aria-hidden="true">·</span>';
                }
                if ( $read_time ) {
                        $out .= '<span class="nxa-card-read">' . esc_html( $read_time ) . '</span>';
                }
                $out .= '</span>';
                $out .= '</footer>';

                $out .= '</div>';
                $out .= '</article>';

                return $out;
        }

        /* ========================= [nexus_articles] ========================= */

        /**
         * Griglia o lista semplice di card su articoli pubblicati (filtro categoria).
         *
         * @param array|string $atts Attributi: category (default ''), limit (default 9, max 24), layout (grid|list).
         * @return string
         */
        public static function render_articles( $atts = array() ) {
                $atts = shortcode_atts(
                        array(
                                'category' => '',
                                'limit'    => 9,
                                'layout'   => 'grid',
                        ),
                        (array) $atts,
                        'nexus_articles'
                );

                $limit    = min( 24, max( 1, absint( $atts['limit'] ) ) );
                $category = sanitize_text_field( (string) $atts['category'] );
                $layout   = ( 'list' === strtolower( (string) $atts['layout'] ) ) ? 'list' : 'grid';

                self::enqueue_assets();

                $args = array(
                        'status'   => 'published',
                        'per_page' => $limit,
                        'orderby'  => 'published_at',
                        'order'    => 'desc',
                );
                if ( '' !== $category ) {
                        $args['category'] = $category;
                }
                $articles = Nexus_DB::articles( $args );

                $wrapper_class = ( 'list' === $layout ) ? 'nxa-grid nxa-grid--list' : 'nxa-grid';

                $out  = '';
                $out .= '<div class="nxa-articles" data-nxa="articles">';
                $out .= '<div class="' . esc_attr( $wrapper_class ) . '">';
                if ( $articles ) {
                        foreach ( $articles as $article ) {
                                $out .= self::render_card( $article );
                        }
                } else {
                        $out .= '<p class="nxa-empty">' . esc_html__( 'Nessun articolo pubblicato per il momento. Torna presto!', 'nexus-news-ai' ) . '</p>';
                }
                $out .= '</div>';
                $out .= '</div>';

                return $out;
        }

        /* ========================= [nexus_wire] ========================= */

        /**
         * Nexus Wire — La redazione collettiva: ultime esecuzioni della pipeline Wire.
         *
         * @param array|string $atts Attributi: limit (default 10, max 20).
         * @return string
         */
        public static function render_wire( $atts = array() ) {
                $atts = shortcode_atts(
                        array( 'limit' => 10 ),
                        (array) $atts,
                        'nexus_wire'
                );
                $limit = min( 20, max( 1, absint( $atts['limit'] ) ) );

                self::enqueue_assets();

                $runs = get_option( 'nexus_wire_runs', array() );
                if ( ! is_array( $runs ) ) {
                        $runs = array();
                }

                /* Più recenti prima (created_at 'Y-m-d H:i:s' ordina bene come stringa). */
                usort(
                        $runs,
                        static function ( $a, $b ) {
                                $ca = isset( $a['created_at'] ) ? (string) $a['created_at'] : '';
                                $cb = isset( $b['created_at'] ) ? (string) $b['created_at'] : '';
                                if ( $cb === $ca ) {
                                        $ia = isset( $a['id'] ) ? (int) $a['id'] : 0;
                                        $ib = isset( $b['id'] ) ? (int) $b['id'] : 0;
                                        return $ib <=> $ia;
                                }
                                return $cb <=> $ca;
                        }
                );
                $runs = array_slice( $runs, 0, $limit );

                $out  = '';
                $out .= '<section class="nxa-wire" data-nxa="wire" aria-labelledby="nxa-wire-title">';
                $out .= '<header class="nxa-wire-head">';
                $out .= '<h2 class="nxa-wire-title" id="nxa-wire-title">' . esc_html__( 'Nexus Wire — La redazione collettiva', 'nexus-news-ai' ) . '</h2>';
                $out .= '<p class="nxa-wire-sub">' . esc_html__( 'Gli agenti AI leggono le fonti, scrivono e si revisionano a vicenda: qui trovi le ultime edizioni collettive.', 'nexus-news-ai' ) . '</p>';
                $out .= '</header>';

                if ( ! $runs ) {
                        $out .= '<p class="nxa-empty">' . esc_html__( 'La redazione collettiva non ha ancora prodotto articoli', 'nexus-news-ai' ) . '</p>';
                        $out .= '</section>';
                        return $out;
                }

                $out .= '<ul class="nxa-wire-list">';
                foreach ( $runs as $run ) {
                        $run        = (array) $run;
                        $status     = sanitize_key( (string) ( $run['status'] ?? '' ) );
                        $topic      = (string) ( $run['topic'] ?? '' );
                        $article_id = absint( $run['article_id'] ?? 0 );
                        $created    = (string) ( $run['created_at'] ?? '' );

                        $out .= '<li class="nxa-wire-item nxa-wire-' . esc_attr( '' !== $status ? $status : 'unknown' ) . '">';
                        $out .= '<div class="nxa-wire-item-main">';
                        $out .= '<h3 class="nxa-wire-topic">' . esc_html( '' !== $topic ? $topic : __( 'Edizione senza titolo', 'nexus-news-ai' ) ) . '</h3>';

                        $agents_badges = self::wire_agents( $run['agents'] ?? array() );
                        if ( $agents_badges ) {
                                $out .= '<div class="nxa-wire-agents">';
                                foreach ( $agents_badges as $agent_name ) {
                                        $out .= '<span class="nxa-wire-agent">' . esc_html( $agent_name ) . '</span>';
                                }
                                $out .= '</div>';
                        }

                        if ( $created ) {
                                $out .= '<div class="nxa-wire-meta"><span class="nxa-wire-when">' . esc_html( Nexus_Utils::time_ago_it( $created ) ) . '</span></div>';
                        }
                        $out .= '</div>';

                        $out .= '<div class="nxa-wire-item-side">';
                        if ( 'completed' === $status ) {
                                if ( $article_id > 0 ) {
                                        $out .= '<a class="nxa-wire-link" data-nxa="wire-read" data-article-id="' . esc_attr( $article_id ) . '" href="#nxa-article-' . esc_attr( $article_id ) . '">' . esc_html__( 'Leggi l’articolo →', 'nexus-news-ai' ) . '</a>';
                                } else {
                                        $out .= '<span class="nxa-badge nxa-badge-wire">' . esc_html__( 'Completata', 'nexus-news-ai' ) . '</span>';
                                }
                        } elseif ( 'failed' === $status ) {
                                $out .= '<span class="nxa-badge nxa-badge-failed">' . esc_html__( 'Non completata', 'nexus-news-ai' ) . '</span>';
                        } else {
                                $out .= '<span class="nxa-badge nxa-badge-running">' . esc_html__( 'In redazione', 'nexus-news-ai' ) . '</span>';
                        }
                        $out .= '</div>';
                        $out .= '</li>';
                }
                $out .= '</ul>';
                $out .= '</section>';

                return $out;
        }

        /* ========================= Helpers privati ========================= */

        /**
         * Agente per id (con cache statica su Nexus_DB::agents()).
         *
         * @param int $id ID agente.
         * @return object|null
         */
        private static function agent( $id ) {
                if ( null === self::$agent_map ) {
                        self::$agent_map = array();
                        foreach ( (array) Nexus_DB::agents() as $agent ) {
                                self::$agent_map[ (int) $agent->id ] = $agent;
                        }
                }
                return isset( self::$agent_map[ absint( $id ) ] ) ? self::$agent_map[ absint( $id ) ] : null;
        }

        /**
         * Normalizza i nomi degli agenti di un run Wire per i badge.
         * Accetta: array di stringhe, array di array/oggetti con chiave "name", stringa separata da virgole.
         *
         * @param mixed $agents Valore campo "agents" del run.
         * @return array lista nomi (stringhe non vuote).
         */
        private static function wire_agents( $agents ) {
                $names = array();
                if ( is_string( $agents ) ) {
                        $agents = explode( ',', $agents );
                }
                foreach ( (array) $agents as $a ) {
                        if ( is_string( $a ) ) {
                                $names[] = trim( $a );
                        } elseif ( is_array( $a ) && isset( $a['name'] ) ) {
                                $names[] = trim( (string) $a['name'] );
                        } elseif ( is_object( $a ) && isset( $a->name ) ) {
                                $names[] = trim( (string) $a->name );
                        }
                }
                return array_values( array_unique( array_filter( $names ) ) );
        }

        /**
         * Sanifica/normalizza un colore per l'uso in attributi inline.
         * Accetta solo #rgb / #rrggbb (espande #rgb in #rrggbb), altrimenti fallback.
         *
         * @param string $color Colore in ingresso (es. output di Nexus_Utils::category_color()).
         * @return string Colore sicuro tipo "#6366f1".
         */
        private static function color_attr( $color ) {
                $color = trim( (string) $color );
                if ( preg_match( '/^#([0-9a-fA-F]{6})$/', $color, $m ) ) {
                        return '#' . strtolower( $m[1] );
                }
                if ( preg_match( '/^#([0-9a-fA-F]{3})$/', $color, $m ) ) {
                        $hex = $m[1];
                        return '#' . strtolower( $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2] );
                }
                return self::FALLBACK_COLOR;
        }
}
