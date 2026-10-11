<?php
/**
 * Nexus CPT — registro il Custom Post Type "nexus_article" e il collegamento WP ↔ Nexus.
 *
 * SCOPO:
 * Espone gli articoli generati dal motore Nexus come post WordPress reali, con
 * permalink puliti, archivio pubblico, tassonomie native (categorie e tag) e
 * esposizione REST. Il collegamento con la riga di origine nella tabella custom
 * wp_nexus_articles è garantito dal meta `_nexus_article_id`.
 *
 * PERMALINK GENERATI:
 *  - singolo articolo:  /nexus/{slug}/          (rewrite slug 'nexus')
 *  - archivio pubblico: /nexus/                 (has_archive 'nexus')
 *
 * WIRING (nota per l'integratore — NON implementare in questo file):
 *  - in nexus-news-ai.php aggiungere:
 *      require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-cpt.php';
 *      Nexus_Cpt::init();
 *  - flush_rewrite_rules va fatto SOLO all'attivazione, MAI ad ogni load.
 *    In nexus-news-ai.php (non qui) aggiungere qualcosa come:
 *      register_activation_hook( __FILE__, function () {
 *          Nexus_Cpt::init();   // registra CPT/tassonomie/meta
 *          flush_rewrite_rules();
 *      } );
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Cpt {

        /**
         * Registra tutti gli hook del CPT.
         *
         * NOTA: qui NON si fa flush_rewrite_rules (vedi docblock header per il
         * wiring dell'attivazione affidato all'integratore).
         *
         * @return void
         */
        public static function init() {
                add_action( 'init', array( __CLASS__, 'register_cpt' ) );
                add_action( 'init', array( __CLASS__, 'register_taxonomies' ) );
                add_action( 'init', array( __CLASS__, 'register_meta' ) );
                add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
                add_filter( 'template_include', array( __CLASS__, 'maybe_use_single_template' ), 99 );
        }

        /**
         * Registra il post type "nexus_article".
         *
         * @return void
         */
        public static function register_cpt() {
                $labels = array(
                        'name'                     => __( 'Articoli Nexus', 'nexus-news-ai' ),
                        'singular_name'            => __( 'Articolo Nexus', 'nexus-news-ai' ),
                        'add_new'                  => __( 'Aggiungi nuovo', 'nexus-news-ai' ),
                        'add_new_item'             => __( 'Aggiungi nuovo articolo Nexus', 'nexus-news-ai' ),
                        'edit_item'                => __( 'Modifica articolo Nexus', 'nexus-news-ai' ),
                        'new_item'                 => __( 'Nuovo articolo Nexus', 'nexus-news-ai' ),
                        'view_item'                => __( 'Visualizza articolo Nexus', 'nexus-news-ai' ),
                        'view_items'               => __( 'Visualizza articoli', 'nexus-news-ai' ),
                        'search_items'             => __( 'Cerca articoli Nexus', 'nexus-news-ai' ),
                        'not_found'                => __( 'Nessun articolo Nexus trovato.', 'nexus-news-ai' ),
                        'not_found_in_trash'       => __( 'Nessun articolo Nexus nel cestino.', 'nexus-news-ai' ),
                        'all_items'                => __( 'Tutti gli articoli Nexus', 'nexus-news-ai' ),
                        'archives'                 => __( 'Archivio articoli Nexus', 'nexus-news-ai' ),
                        'attributes'               => __( 'Attributi dell’articolo', 'nexus-news-ai' ),
                        'insert_into_item'         => __( 'Inserisci nell’articolo', 'nexus-news-ai' ),
                        'uploaded_to_this_item'    => __( 'Caricato in questo articolo', 'nexus-news-ai' ),
                        'featured_image'           => __( 'Immagine in evidenza', 'nexus-news-ai' ),
                        'set_featured_image'       => __( 'Imposta immagine in evidenza', 'nexus-news-ai' ),
                        'remove_featured_image'    => __( 'Rimuovi immagine in evidenza', 'nexus-news-ai' ),
                        'use_featured_image'       => __( 'Usa come immagine in evidenza', 'nexus-news-ai' ),
                        'filter_items_list'        => __( 'Filtra la lista articoli', 'nexus-news-ai' ),
                        'items_list_navigation'    => __( 'Navigazione lista articoli', 'nexus-news-ai' ),
                        'items_list'               => __( 'Lista articoli', 'nexus-news-ai' ),
                        'item_published'           => __( 'Articolo pubblicato.', 'nexus-news-ai' ),
                        'item_published_privately' => __( 'Articolo pubblicato in privato.', 'nexus-news-ai' ),
                        'item_reverted_to_draft'   => __( 'Articolo riportato a bozza.', 'nexus-news-ai' ),
                        'item_scheduled'           => __( 'Articolo programmato.', 'nexus-news-ai' ),
                        'item_updated'             => __( 'Articolo aggiornato.', 'nexus-news-ai' ),
                        'menu_name'                => __( 'Nexus News AI', 'nexus-news-ai' ),
                );

                $args = array(
                        'labels'              => $labels,
                        'public'              => true,
                        'exclude_from_search' => false,
                        'has_archive'         => 'nexus',
                        'rewrite'             => array( 'slug' => 'nexus' ),
                        'supports'            => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author' ),
                        'show_in_rest'        => true,
                        'menu_icon'           => 'dashicons-megaphone',
                );

                register_post_type( 'nexus_article', $args );
        }

        /**
         * Associa al CPT le tassonomie NATIVE di WordPress: niente tassonomie
         * custom da mantenere, si riusano categorie e tag esistenti.
         *
         * @return void
         */
        public static function register_taxonomies() {
                register_taxonomy_for_object_type( 'category', 'nexus_article' );
                register_taxonomy_for_object_type( 'post_tag', 'nexus_article' );
        }

        /**
         * Registra il meta di collegamento all'articolo Nexus di origine
         * (riga in wp_nexus_articles). Gestito solo dal plugin, mai in REST.
         *
         * Nota: '__return_true' è il callable nativo di WordPress per auth_callback.
         *
         * @return void
         */
        public static function register_meta() {
                register_post_meta(
                        'nexus_article',
                        '_nexus_article_id',
                        array(
                                'type'          => 'integer',
                                'single'        => true,
                                'show_in_rest'  => false,
                                'auth_callback' => '__return_true',
                        )
                );
        }

        /**
         * Meta box informativa (read-only) sull'editor del post type.
         *
         * @return void
         */
        public static function add_meta_box() {
                add_meta_box(
                        'nexus-article-info',
                        __( 'Nexus News AI', 'nexus-news-ai' ),
                        array( __CLASS__, 'render_meta_box' ),
                        'nexus_article',
                        'side',
                        'high'
                );
        }

        /**
         * Render della meta box: mostra l'articolo Nexus di origine, il suo
         * permalink legacy e l'agente autore. Nessun campo salvato, quindi
         * nessun nonce necessario (box puramente informativo).
         *
         * @param WP_Post|int $post Post corrente (o ID).
         * @return void
         */
        public static function render_meta_box( $post ) {
                $post_id    = is_object( $post ) ? absint( $post->ID ) : absint( $post );
                $article_id = absint( get_post_meta( $post_id, '_nexus_article_id', true ) );

                echo '<div class="nexus-metabox">';

                // Articolo Nexus di origine (se il post è stato generato dal plugin).
                echo '<p><strong>' . esc_html__( 'Articolo Nexus di origine:', 'nexus-news-ai' ) . '</strong><br />';
                if ( $article_id > 0 ) {
                        echo esc_html( '#' . $article_id );
                } else {
                        echo esc_html__( 'Nessun collegamento (post non generato da Nexus).', 'nexus-news-ai' );
                }
                echo '</p>';

                if ( $article_id > 0 ) {
                        // Permalink legacy dell'articolo nella tabella custom wp_nexus_articles.
                        $nexus_url = home_url( '/?nexus_article=' . $article_id );
                        echo '<p><strong>' . esc_html__( 'Permalink Nexus:', 'nexus-news-ai' ) . '</strong><br />';
                        echo '<a href="' . esc_url( $nexus_url ) . '" target="_blank" rel="noopener">' . esc_html( $nexus_url ) . '</a></p>';

                        // Agente autore: dipendenza difensiva da Nexus_DB (mai fatal se assente).
                        if ( class_exists( 'Nexus_DB' ) ) {
                                $article = Nexus_DB::get_article( $article_id );
                                $agent   = ( $article && ! empty( $article->agent_id ) ) ? Nexus_DB::get_agent( $article->agent_id ) : null;

                                echo '<p><strong>' . esc_html__( 'Agente autore:', 'nexus-news-ai' ) . '</strong><br />';
                                if ( $agent && ! empty( $agent->name ) ) {
                                        echo esc_html( $agent->name );
                                        if ( ! empty( $agent->category ) ) {
                                                echo ' — ' . esc_html( $agent->category );
                                        }
                                } else {
                                        echo esc_html__( 'Agente non disponibile.', 'nexus-news-ai' );
                                }
                                echo '</p>';
                        }
                }

                echo '</div>';
        }

        /**
         * Filter template_include: su single nexus_article usa il template del
         * plugin SOLO se il file esiste, altrimenti lascia il default del tema.
         *
         * @param string $template Percorso del template risolto da WordPress.
         * @return string Percorso del template da caricare.
         */
        public static function maybe_use_single_template( $template ) {
                if ( is_singular( 'nexus_article' ) && defined( 'NEXUS_PLUGIN_DIR' ) ) {
                        $custom = NEXUS_PLUGIN_DIR . 'templates/single-nexus_article.php';
                        if ( file_exists( $custom ) ) {
                                return $custom;
                        }
                }

                return $template;
        }
}
