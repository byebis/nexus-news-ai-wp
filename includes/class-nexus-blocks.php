<?php
/**
 * Nexus Blocks — blocchi Gutenberg server-side per il frontend Nexus News AI.
 *
 * Due blocchi dinamici (api_version 2, render_callback PHP, NESSUN build step):
 *
 *   1) nexus/latest-articles — griglia degli ultimi articoli pubblicati.
 *      Il render_callback mappa gli attributi del blocco su quelli REALMENTE
 *      accettati da Nexus_Shortcodes::render_articles() (shortcode [nexus_articles],
 *      vedi public/class-nexus-shortcodes.php):
 *
 *        count        → limit     (int, clampato 1..24 dentro lo shortcode)
 *        category     → category  (string, vuoto = tutte le categorie)
 *        columns      → NON passato (lo shortcode non supporta questo attributo:
 *                                    ignorato, degradazione con grazia)
 *        show_images  → NON passato (lo shortcode non supporta questo attributo:
 *                                    ignorato, le card gestiscono da sole i fallback)
 *
 *   2) nexus/subscribe-form — form di iscrizione alla newsletter.
 *      Delega a do_shortcode( '[nexus_subscribe]' ) (Nexus_Newsletter) e antepone
 *      un titolo <h3 class="nexus-block-subscribe-title"> quando l'attributo
 *      "title" non è vuoto. Nota: lo shortcode stampa già il proprio <h3> col
 *      titolo default; il wrapper del blocco aggiunge il titolo configurato.
 *
 * Contratto pubblico (CONTRATTO v1.0.4, §2 T-03):
 *   Nexus_Blocks::init()             → bootstrap: hook init (prio 20) + enqueue_block_editor_assets
 *   Nexus_Blocks::register_blocks()  → register_block_type() per i due blocchi
 *   Nexus_Blocks::render_latest()    → render_callback nexus/latest-articles (ritorna SEMPRE stringa)
 *   Nexus_Blocks::render_subscribe() → render_callback nexus/subscribe-form (ritorna SEMPRE stringa)
 *   Nexus_Blocks::enqueue_editor()   → wp_enqueue_script 'nexus-blocks' (solo editor a blocchi)
 *
 * Integrazione (per l'integratore, NON eseguita da questo agente):
 *   require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-blocks.php';
 *   Nexus_Blocks::init();
 *
 * Dipendenze guarded (mai errori fatali): Nexus_Shortcodes::render_articles(),
 * Nexus_Newsletter ([nexus_subscribe]). Nessun output diretto.
 *
 * @package Nexus_News_AI
 * @since   1.0.4
 */

defined( 'ABSPATH' ) || exit;

/**
 * Classe Nexus_Blocks — registrazione e render dei blocchi Gutenberg. Tutti metodi statici.
 */
class Nexus_Blocks {

        /**
         * Bootstrap: registra i blocchi su init (dopo la registrazione dei CPT)
         * e accoda lo script dell'editor solo negli editor a blocchi.
         *
         * @return void
         */
        public static function init() {
                add_action( 'init', array( __CLASS__, 'register_blocks' ), 20 );
                add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor' ) );
        }

        /**
         * Registra i due blocchi server-side. Se l'API blocchi non è disponibile
         * (es. REST senza Gutenberg attivo) esce silenziosamente senza errori.
         *
         * @return void
         */
        public static function register_blocks() {
                if ( ! function_exists( 'register_block_type' ) ) {
                        return;
                }

                // nexus/latest-articles — griglia ultimi articoli (render server-side).
                register_block_type(
                        'nexus/latest-articles',
                        array(
                                'api_version'     => 2,
                                'editor_script'   => 'nexus-blocks',
                                'render_callback' => array( __CLASS__, 'render_latest' ),
                                'category'        => 'widgets',
                                'icon'            => 'megaphone',
                                'keywords'        => array( 'nexus', 'articoli', 'news' ),
                                'attributes'      => array(
                                        'count'       => array(
                                                'type'    => 'integer',
                                                'default' => 6,
                                        ),
                                        'category'    => array(
                                                'type'    => 'string',
                                                'default' => '',
                                        ),
                                        'columns'     => array(
                                                'type'    => 'integer',
                                                'default' => 3,
                                        ),
                                        'show_images' => array(
                                                'type'    => 'boolean',
                                                'default' => true,
                                        ),
                                ),
                        )
                );

                // nexus/subscribe-form — form iscrizione newsletter (render server-side).
                register_block_type(
                        'nexus/subscribe-form',
                        array(
                                'api_version'     => 2,
                                'editor_script'   => 'nexus-blocks',
                                'render_callback' => array( __CLASS__, 'render_subscribe' ),
                                'category'        => 'widgets',
                                'icon'            => 'email',
                                'keywords'        => array( 'nexus', 'newsletter', 'iscrizione' ),
                                'attributes'      => array(
                                        'title' => array(
                                                'type'    => 'string',
                                                'default' => 'Iscriviti alla newsletter',
                                        ),
                                ),
                        )
                );
        }

        /**
         * Render del blocco nexus/latest-articles.
         *
         * Mappa gli attributi del blocco su quelli accettati da
         * Nexus_Shortcodes::render_articles() (SOLO: limit, category, layout).
         * Gli attributi del blocco senza corrispondenza nello shortcode
         * (columns, show_images) NON vengono passati: lo shortcode li ignorerebbe
         * comunque, ma la mappa esplicita evita ambiguità future.
         *
         * @param array $attributes Attributi del blocco (dal parser Gutenberg).
         * @return string Markup HTML (mai null).
         */
        public static function render_latest( $attributes ) {
                // Guard: lo shortcode magazine potrebbe non essere caricato.
                if ( ! class_exists( 'Nexus_Shortcodes' ) || ! method_exists( 'Nexus_Shortcodes', 'render_articles' ) ) {
                        return '';
                }

                $attributes = is_array( $attributes ) ? $attributes : array();

                // count → limit (lo shortcode clampa comunque 1..24; qui clamp difensivo min 1).
                $count = isset( $attributes['count'] ) ? absint( $attributes['count'] ) : 6;
                if ( $count < 1 ) {
                        $count = 1;
                }

                // category → category (vuoto = tutte).
                $category = isset( $attributes['category'] ) ? sanitize_text_field( (string) $attributes['category'] ) : '';

                $atts = array(
                        'limit'    => $count,
                        'category' => $category,
                );

                // columns / show_images: nessun attributo corrispondente in render_articles
                // → non passati (il layout resta 'grid' di default dello shortcode).
                return (string) Nexus_Shortcodes::render_articles( $atts );
        }

        /**
         * Render del blocco nexus/subscribe-form.
         *
         * Delega al modulo newsletter via do_shortcode('[nexus_subscribe]') e, se
         * l'attributo "title" non è vuoto, avvolge l'output in un wrapper con
         * titolo <h3 class="nexus-block-subscribe-title"> (escapato).
         * Se Nexus_Newsletter non è disponibile ritorna stringa vuota (mai fatale).
         *
         * @param array $attributes Attributi del blocco (dal parser Gutenberg).
         * @return string Markup HTML (anche vuoto se il modulo non è disponibile).
         */
        public static function render_subscribe( $attributes ) {
                $title = '';
                if ( is_array( $attributes ) && isset( $attributes['title'] ) ) {
                        $title = trim( sanitize_text_field( (string) $attributes['title'] ) );
                }

                $out = '';

                // Guard: il modulo newsletter potrebbe non essere caricato.
                if ( class_exists( 'Nexus_Newsletter' ) ) {
                        $out = do_shortcode( '[nexus_subscribe]' );
                }

                if ( '' !== $title ) {
                        $out = '<div class="nexus-block-subscribe"><h3 class="nexus-block-subscribe-title">' . esc_html( $title ) . '</h3>' . $out . '</div>';
                }

                return $out;
        }

        /**
         * Accoda lo script dell'editor a blocchi (assets/js/blocks.js) SOLO negli
         * editor: l'hook enqueue_block_editor_assets lo garantisce.
         * Dipendenze core WordPress (wp.*), nessun build step.
         *
         * @return void
         */
        public static function enqueue_editor() {
                wp_enqueue_script(
                        'nexus-blocks',
                        NEXUS_PLUGIN_URL . 'assets/js/blocks.js',
                        array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ),
                        NEXUS_VERSION,
                        true
                );
        }
}
