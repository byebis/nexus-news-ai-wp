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
 * v1.1.3 — MENU AUTO-HIDE (contenuto legacy):
 * Dalla 1.1.0 i contenuti NUOVI vivono nei post nativi 'post' (Nexus_Publisher
 * + Nexus_Native) e il CPT ospita solo articoli LEGACY creati prima della
 * 1.1.0. Quando non restano articoli legacy (legacy_count() === 0, tipicamente
 * dopo la migrazione 1-click Strumenti → "Migrazione contenuti nativi") il CPT
 * viene registrato con 'show_in_menu' => false: la voce "Nexus News AI"
 * sparisce dal menu admin ma il post type RESTA REGISTRATO, quindi permalink
 * legacy /?nexus_article={id} e archivio pubblico /nexus/ continuano a
 * funzionare. Il conteggio e' cachato nel transient "nexus_cpt_legacy_count"
 * (TTL 300 secondi) e invalidato su hook core save_post /
 * transition_post_status / deleted_post: la migrazione cambia post_type via
 * wp_update_post e NON lancia do_action custom (vedi il docblock di
 * invalidate_legacy_count()). Filtro 'nexus_cpt_force_menu' per forzare il
 * menu sempre visibile o sempre nascosto (vedi register_cpt()).
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

                // v1.1.3: invalidazione della cache del conteggio legacy su
                // hook core (vedi invalidate_legacy_count() per il perche',
                // inclusa la copertura della migrazione senza do_action custom).
                add_action( 'save_post', array( __CLASS__, 'invalidate_legacy_count' ) );
                add_action( 'transition_post_status', array( __CLASS__, 'invalidate_legacy_count' ) );
                add_action( 'deleted_post', array( __CLASS__, 'invalidate_legacy_count' ) );
        }

        /**
         * Conteggio degli articoli LEGACY nel CPT "nexus_article".
         *
         * Stesso criterio di Nexus_Native::cpt_remaining() (esclusi cestino e
         * auto-draft), ma con query protetta da $wpdb->prepare e risultato
         * cachato nel transient "nexus_cpt_legacy_count" con TTL di 300
         * secondi: register_cpt() gira ad ogni richiesta e non deve
         * interrogare il DB a ogni load.
         *
         * @return int Numero di articoli legacy (0 = nessun legacy residuo).
         */
        public static function legacy_count() {
                $cached = get_transient( 'nexus_cpt_legacy_count' );
                if ( false !== $cached ) {
                        return (int) $cached;
                }

                global $wpdb;

                $count = (int) $wpdb->get_var(
                        $wpdb->prepare(
                                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ( %s, %s )",
                                'nexus_article',
                                'trash',
                                'auto-draft'
                        )
                );

                set_transient( 'nexus_cpt_legacy_count', $count, 300 );

                return $count;
        }

        /**
         * Callback degli hook core: invalida il transient del conteggio legacy.
         *
         * PERCHE' QUESTI HOOK COPRONO ANCHE LA MIGRAZIONE:
         * la migrazione 1-click (Strumenti → "Migrazione contenuti nativi",
         * Nexus_Native::migrate_run) cambia post_type da 'nexus_article' a
         * 'post' tramite wp_update_post() e al termine NON lancia alcun
         * do_action custom: l'unico segnale affidabile sono gli hook core.
         *  - 'save_post': scatta per OGNI post type dentro wp_insert_post(),
         *    quindi anche per il post riscritto dalla migrazione (che al
         *    salvataggio e' gia' di tipo 'post'): agganciare l'hook generico
         *    e' l'unico modo per intercettare la conversione CPT → 'post'
         *    senza modificare Nexus_Native.
         *  - 'transition_post_status': copre i cambi di stato (bozza,
         *    pubblicazione, cestino) che alterano il conteggio anche quando
         *    non passa da un nuovo salvataggio esplicito del CPT.
         *  - 'deleted_post': copre l'eliminazione definitiva, incluso lo
         *    svuotamento del cestino.
         *
         * @return void
         */
        public static function invalidate_legacy_count() {
                delete_transient( 'nexus_cpt_legacy_count' );
        }

        /**
         * Registra il post type "nexus_article".
         *
         * v1.1.3 MENU AUTO-HIDE: se non restano articoli legacy
         * (legacy_count() === 0) gli args ricevono 'show_in_menu' => false:
         * la voce "Nexus News AI" sparisce dal menu admin, ma il post type
         * RESTA REGISTRATO quindi permalink legacy /?nexus_article={id} e
         * archivio pubblico /nexus/ continuano a funzionare. Con almeno un
         * articolo legacy il menu resta visibile come nelle versioni
         * precedenti.
         *
         * Filtro 'nexus_cpt_force_menu' (applicato PRIMA del confronto, ha
         * priorita' sul conteggio automatico):
         *  - true  → menu sempre visibile;
         *  - false → menu sempre nascosto;
         *  - null (default) → comportamento automatico da legacy_count().
         *
         * Esempio d'uso (functions.php del tema o mu-plugin):
         *
         *     add_filter( 'nexus_cpt_force_menu', function () {
         *         return true; // Menu sempre visibile, anche senza legacy.
         *     } );
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

                // v1.1.3: menu auto-hide. Il filtro viene applicato PRIMA del
                // confronto: un valore non-null ha sempre la priorita'.
                $force_menu = apply_filters( 'nexus_cpt_force_menu', null );

                if ( null !== $force_menu ) {
                        // true = menu sempre visibile, false = sempre nascosto.
                        $args['show_in_menu'] = (bool) $force_menu;
                } elseif ( self::legacy_count() === 0 ) {
                        // Nessun articolo legacy: solo la voce di menu sparisce,
                        // permalink e archivio restano funzionanti.
                        $args['show_in_menu'] = false;
                }

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
