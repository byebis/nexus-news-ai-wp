<?php
/**
 * Nexus SEO — ottimizzazione SEO degli articoli pubblicati come post
 * WordPress reali (CPT nexus_article): meta description, Open Graph base,
 * JSON-LD NewsArticle e title tag personalizzati.
 *
 * Implementa la sezione SEO del contratto v1.0.1 (§4 "Contenuti Veri") e, da
 * v1.1.2 (CONTRATTO-v1.1.2.md "Multilingua nativo"), i meta hreflang alternati
 * e og:locale per le single 'post' con più di una lingua pubblicata: le
 * traduzioni sono post WordPress collegati, mappati via Nexus_Multilang (§1).
 *
 * CONTRATTO PUBBLICO (usato dagli altri moduli):
 * Nexus_SEO::init()                          — registra gli hook front-end:
 *                                              wp_head (priorità 1) → print_head_meta,
 *                                              pre_get_document_title (priorità 20) → filter_title.
 *                                              Da chiamare UNA sola volta al bootstrap del plugin.
 * Nexus_SEO::optimize( $article, $post_id )  — genera title + description SEO per un articolo Nexus
 *                                              e li salva come post meta. Ritorna SEMPRE un array:
 *                                              array( 'title' => string, 'description' => string, 'source' => 'ai'|'fallback' )
 *                                              oppure array() se la SEO è disabilitata (enable_seo != 1).
 *                                              Mai fatale, mai eccezioni: in caso di problemi usa il fallback locale.
 * Nexus_SEO::print_head_meta()               — hook wp_head: meta description + Open Graph base + blocco
 *                                              JSON-LD @type NewsArticle su single nexus_article e post
 *                                              nativi (dual-mode v1.1.0); da v1.1.2 anche hreflang
 *                                              alternati e og:locale se 'post' ha più lingue pubblicate.
 *                                              v1.1.14 (P6): JSON-LD NewsArticle COMPLETO (dateModified,
 *                                              publisher Organization con logo = site icon, articleSection
 *                                              dalla categoria, author Person/Organization secondo l'entità
 *                                              risolta) + Twitter Card (twitter:card/title/description/
 *                                              image) affiancate agli OG esistenti.
 * Nexus_SEO::filter_title( $title )          — hook pre_get_document_title: su single nexus_article usa
 *                                              _nexus_seo_title se presente, altrimenti "Titolo | Nome sito"
 *                                              (separatore ' | ').
 *
 * WIRING (nota per l'integratore, in nexus-news-ai.php):
 *   require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-seo.php';
 *   // e, dopo i require, una sola volta:
 *   Nexus_SEO::init();
 *   // L'integratore (publisher) chiama invece, dentro un guard class_exists,
 *   // Nexus_SEO::optimize( $article, $post_id ) subito dopo il wp_insert_post riuscito.
 *
 * Settings usati (Nexus_DB::get_settings()): enable_seo, seo_generate, ai_api_key.
 * Meta scritti sul post: _nexus_seo_title, _nexus_seo_description
 *                        (+ _yoast_wpseo_title/_yoast_wpseo_metadesc se Yoast è attivo,
 *                         + rank_math_title/rank_math_description se RankMath è attivo).
 * Meta letti dal post:   _nexus_article_id (per risalire ad articolo/agente Nexus).
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

class Nexus_SEO {

        /**
         * Registra gli hook front-end della SEO.
         *
         * - wp_head con priorità 1 (bassa): i meta devono uscire prima di quelli
         *   del tema/plugin per evitare duplicati di description/OG.
         * - pre_get_document_title con priorità 20: gira DOPO i filtri dei plugin
         *   SEO (es. Yoast a 15); il titolo generato da Nexus è comunque quello
         *   ufficiale perché viene replicato anche nei meta di Yoast/RankMath.
         *
         * @return void
         */
        public static function init() {
                add_action( 'wp_head', array( __CLASS__, 'print_head_meta' ), 1 );
                add_filter( 'pre_get_document_title', array( __CLASS__, 'filter_title' ), 20 );
        }

        /**
         * Genera (via AI o fallback locale) e salva title + description SEO
         * per un articolo Nexus pubblicato come post WordPress.
         *
         * Contratto v1.0.1 §4: al massimo UNA chiamata a Nexus_AI_Engine::chat(),
         * con risposta JSON {'title': ≤60 char, 'description': ≤155 char} in italiano.
         * Se l'AI non è disponibile/disabilitata o il JSON non è recuperabile, si
         * usa il fallback locale (titolo articolo + summary troncata a 155).
         *
         * @param array|object $article Articolo Nexus (row di wp_nexus_articles o array equivalente).
         * @param int          $post_id  ID del post WordPress (CPT nexus_article).
         * @return array array( 'title' => string, 'description' => string, 'source' => 'ai'|'fallback' )
         *               oppure array() se la SEO è disabilitata.
         */
        public static function optimize( $article, $post_id ) {
                if ( ! class_exists( 'Nexus_DB' ) ) {
                        return array();
                }

                $post_id = (int) $post_id;

                // Guard: SEO disabilitata → nessun lavoro, nessun salvataggio.
                $settings = Nexus_DB::get_settings();
                if ( ! is_array( $settings ) ) {
                        return array();
                }
                $enable_seo = isset( $settings['enable_seo'] ) ? (int) $settings['enable_seo'] : 0;
                if ( 1 !== $enable_seo ) {
                        return array();
                }

                // Fallback locale: titolo articolo + summary troncata a 155 caratteri.
                $fallback_title = self::clean_text( self::field( $article, 'title' ), 0 );
                $fallback_description = self::clean_text( self::field( $article, 'summary' ), 155 );

                if ( '' === $fallback_title && $post_id > 0 ) {
                        $fallback_title = self::clean_text( get_the_title( $post_id ), 0 );
                }
                if ( '' === $fallback_description && $post_id > 0 ) {
                        $fallback_description = self::clean_text( get_post_field( 'post_excerpt', $post_id ), 155 );
                }

                $title       = $fallback_title;
                $description = $fallback_description;
                $source      = 'fallback';

                // Generazione AI: solo se seo_generate=1, chiave API presente ed engine caricato.
                $seo_generate = isset( $settings['seo_generate'] ) ? (int) $settings['seo_generate'] : 0;
                $api_key      = isset( $settings['ai_api_key'] ) ? trim( (string) $settings['ai_api_key'] ) : '';

                if ( 1 === $seo_generate && '' !== $api_key && class_exists( 'Nexus_AI_Engine' ) ) {
                        $category = self::clean_text( self::field( $article, 'category' ), 0 );

                        // Il sistema chiede ESCLUSIVAMENTE JSON, niente testo attorno.
                        $system_message = 'Sei un esperto di SEO editoriale per un magazine di notizie in italiano. Riceverai i dati di un articolo. Rispondi ESCLUSIVAMENTE con un oggetto JSON valido, senza testo prima o dopo, nel formato esatto: {"title": "...", "description": "..."}. Il campo "title" è il titolo SEO dell\'articolo: massimo 60 caratteri, in italiano, accattivante ma fedele al contenuto, senza clickbait ingannevoli. Il campo "description" è la meta description: massimo 155 caratteri, in italiano, riassume il contenuto con le parole chiave più pertinenti. Nessun altro campo, nessun commento, nessun markdown.';

                        $user_message = 'Titolo articolo: ' . $fallback_title . "\n"
                                . 'Sommario: ' . $fallback_description . "\n"
                                . 'Categoria: ' . $category;

                        // UNA sola chiamata AI, parametri sobri (risposta breve).
                        $response = Nexus_AI_Engine::chat(
                                array(
                                        array(
                                                'role'    => 'system',
                                                'content' => $system_message,
                                        ),
                                        array(
                                                'role'    => 'user',
                                                'content' => $user_message,
                                        ),
                                ),
                                array(
                                        'temperature' => 0.4,
                                        'max_tokens'  => 300,
                                )
                        );

                        if ( is_array( $response ) && ! empty( $response['ok'] ) && isset( $response['content'] ) ) {
                                $data = Nexus_AI_Engine::extract_json( $response['content'] );

                                if ( is_array( $data ) ) {
                                        $ai_title = isset( $data['title'] ) ? self::clean_text( $data['title'], 60 ) : '';
                                        $ai_desc  = isset( $data['description'] ) ? self::clean_text( $data['description'], 155 ) : '';

                                        // Almeno un campo utile: usa l'AI riempiendo i buchi col fallback.
                                        if ( '' !== $ai_title || '' !== $ai_desc ) {
                                                if ( '' !== $ai_title ) {
                                                        $title = $ai_title;
                                                }
                                                if ( '' !== $ai_desc ) {
                                                        $description = $ai_desc;
                                                }
                                                $source = 'ai';
                                        }
                                }
                        }
                }

                // Se manca ancora qualcosa, rientra il fallback (mai campi vuoti se l'articolo ha dati).
                if ( '' === $title ) {
                        $title = $fallback_title;
                }
                if ( '' === $description ) {
                        $description = $fallback_description;
                }

                // Salvataggio sui meta del post (solo con post_id valido).
                if ( $post_id > 0 && ( '' !== $title || '' !== $description ) ) {
                        update_post_meta( $post_id, '_nexus_seo_title', $title );
                        update_post_meta( $post_id, '_nexus_seo_description', $description );

                        // Compatibilità Yoast SEO: replica i valori nei suoi meta.
                        if ( class_exists( 'WPSEO_Options' ) ) {
                                update_post_meta( $post_id, '_yoast_wpseo_title', $title );
                                update_post_meta( $post_id, '_yoast_wpseo_metadesc', $description );
                        }

                        // Compatibilità RankMath: replica i valori nei suoi meta.
                        if ( class_exists( 'RankMath' ) ) {
                                update_post_meta( $post_id, 'rank_math_title', $title );
                                update_post_meta( $post_id, 'rank_math_description', $description );
                        }
                }

                return array(
                        'title'       => $title,
                        'description' => $description,
                        'source'      => $source,
                );
        }

        /**
         * Hook wp_head (priorità 1): meta description, Open Graph base e JSON-LD
         * NewsArticle sulle single nexus_article e post nativi (dual-mode v1.1.0).
         *
         * Da v1.1.2 ("Multilingua nativo"): sulle single di tipo 'post' con più di
         * una lingua pubblicata emette anche i link rel alternate hreflang (x-default
         * sulla lingua predefinita) e og:locale + og:locale:alternate, tramite le API
         * Nexus_Multilang. Guardie: classe assente, non-singular o meno di due lingue
         * pubblicate: nessun output aggiuntivo, mai fatale.
         *
         * v1.1.14 (P6) — JSON-LD NewsArticle completo (UN SOLO blocco, esteso in
         * loco, mai duplicato): headline, description, image (featured image,
         * corretta dalla v1.1.0: get_the_post_thumbnail_url con fallback
         * article->image_url — verificata, nessuna modifica necessaria),
         * datePublished, dateModified (post modified, ISO 8601), author come
         * Person quando l'entità risolta è un agente/utente reale, Organization
         * quando l'unico autore disponibile è il nome del sito, publisher
         * Organization con logo dal site icon (assente il tag), articleSection
         * dalla categoria WP nativa (fallback categoria Nexus), mainEntityOfPage.
         * v1.1.14 (P6) — Twitter Card: twitter:card (summary_large_image con
         * immagine, summary senza), twitter:title, twitter:description e
         * twitter:image STESSA immagine in evidenza di og:image (nessun tag
         * twitter esisteva prima: nessun duplicato introdotto).
         *
         * @return void
         */
        public static function print_head_meta() {
                // v1.1.0 dual-mode: meta SEO anche sui post nativi, non solo sul CPT legacy.
                $types = class_exists( 'Nexus_Native' ) && method_exists( 'Nexus_Native', 'query_post_types' ) ? Nexus_Native::query_post_types() : array( 'post', 'nexus_article' );

                if ( ! is_singular( $types ) ) {
                        return;
                }

                $post_id = get_queried_object_id();
                if ( $post_id <= 0 ) {
                        return;
                }

                $post = get_post( $post_id );
                if ( ! $post instanceof WP_Post ) {
                        return;
                }

                // Articolo Nexus di origine (se collegato via meta _nexus_article_id).
                $article = null;
                if ( class_exists( 'Nexus_DB' ) ) {
                        $article_id = (int) get_post_meta( $post_id, '_nexus_article_id', true );
                        if ( $article_id > 0 ) {
                                $article = Nexus_DB::get_article( $article_id );
                        }
                }

                // Title/description: SEO meta se presenti, altrimenti titolo/riassunto del post.
                $seo_title = get_post_meta( $post_id, '_nexus_seo_title', true );
                if ( ! is_string( $seo_title ) || '' === trim( $seo_title ) ) {
                        $seo_title = get_the_title( $post_id );
                }
                $seo_description = get_post_meta( $post_id, '_nexus_seo_description', true );
                if ( ! is_string( $seo_description ) || '' === trim( $seo_description ) ) {
                        // Il publisher salva il summary nel post_excerpt.
                        $seo_description = get_post_field( 'post_excerpt', $post_id );
                }
                $seo_title       = self::clean_text( $seo_title, 0 );
                $seo_description = self::clean_text( $seo_description, 155 );

                // URL canonico del post.
                $permalink = get_permalink( $post_id );
                if ( ! is_string( $permalink ) || '' === $permalink ) {
                        $permalink = '';
                }

                // Immagine: featured image del post, altrimenti image_url dell'articolo Nexus.
                $image_url = get_the_post_thumbnail_url( $post_id, 'large' );
                if ( ! is_string( $image_url ) || '' === $image_url ) {
                        $image_url = self::clean_text( self::field( $article, 'image_url' ), 0 );
                }

                // Data di pubblicazione in formato ISO 8601.
                $published = get_the_date( 'c', $post );

                // v1.1.14 (P6): data ultima modifica (ISO 8601) per dateModified.
                $modified = get_the_modified_date( 'c', $post );

                // Autore: nome dell'agente Nexus che ha generato l'articolo, con fallback.
                // v1.1.14 (P6): $author_is_site true quando l'unica entità disponibile
                // è il nome del sito → JSON-LD author come Organization (non Person).
                $author_is_site = false;
                $author_name = '';
                if ( $article && ! empty( $article->agent_id ) && class_exists( 'Nexus_DB' ) ) {
                        $agent = Nexus_DB::get_agent( (int) $article->agent_id );
                        if ( $agent && ! empty( $agent->name ) ) {
                                $author_name = (string) $agent->name;
                        }
                }
                if ( '' === $author_name ) {
                        $author_id = (int) get_post_field( 'post_author', $post_id );
                        if ( $author_id > 0 ) {
                                $author_name = get_the_author_meta( 'display_name', $author_id );
                        }
                }
                if ( '' === $author_name ) {
                        $author_name    = get_bloginfo( 'name' );
                        $author_is_site = true;
                }
                $author_name = self::clean_text( $author_name, 0 );

                // v1.1.14 (P6): articleSection dalla categoria WP nativa del post
                // (impostata dal publisher), fallback categoria dell'articolo Nexus.
                $ld_section = '';
                $wp_cats    = get_the_category( $post_id );
                if ( is_array( $wp_cats ) && ! empty( $wp_cats ) && isset( $wp_cats[0]->name ) ) {
                        $ld_section = self::clean_text( $wp_cats[0]->name, 0 );
                }
                if ( '' === $ld_section && $article && ! empty( $article->category ) ) {
                        $ld_section = self::clean_text( self::field( $article, 'category' ), 0 );
                }

                // Uscita anticipata se non c'è proprio nulla di utile da stampare.
                if ( '' === $seo_title && '' === $seo_description && '' === $permalink ) {
                        return;
                }

                echo "\n" . '<!-- SEO Nexus News AI -->' . "\n";

                // Meta description.
                if ( '' !== $seo_description ) {
                        echo '<meta name="description" content="' . esc_attr( $seo_description ) . '">' . "\n";
                }

                // Open Graph base.
                if ( '' !== $seo_title ) {
                        echo '<meta property="og:title" content="' . esc_attr( $seo_title ) . '">' . "\n";
                }
                if ( '' !== $seo_description ) {
                        echo '<meta property="og:description" content="' . esc_attr( $seo_description ) . '">' . "\n";
                }
                echo '<meta property="og:type" content="article">' . "\n";
                if ( '' !== $permalink ) {
                        echo '<meta property="og:url" content="' . esc_url( $permalink ) . '">' . "\n";
                }
                if ( '' !== $image_url ) {
                        echo '<meta property="og:image" content="' . esc_url( $image_url ) . '">' . "\n";
                }
                if ( '' !== $published ) {
                        echo '<meta property="article:published_time" content="' . esc_attr( $published ) . '">' . "\n";
                }
                if ( '' !== $modified ) {
                        echo '<meta property="article:modified_time" content="' . esc_attr( $modified ) . '">' . "\n";
                }

                // v1.1.14 (P6): Twitter Card. twitter:image usa la STESSA immagine
                // in evidenza di og:image (featured image, fallback image_url
                // dell'articolo Nexus): nessun tag twitter esisteva prima.
                if ( '' !== $image_url ) {
                        echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
                } else {
                        echo '<meta name="twitter:card" content="summary">' . "\n";
                }
                if ( '' !== $seo_title ) {
                        echo '<meta name="twitter:title" content="' . esc_attr( $seo_title ) . '">' . "\n";
                }
                if ( '' !== $seo_description ) {
                        echo '<meta name="twitter:description" content="' . esc_attr( $seo_description ) . '">' . "\n";
                }
                if ( '' !== $image_url ) {
                        echo '<meta name="twitter:image" content="' . esc_url( $image_url ) . '">' . "\n";
                }

                // v1.1.2 Multilingua nativo (CONTRATTO-v1.1.2.md): sulle single di tipo
                // 'post' con più di una lingua pubblicata emette hreflang alternati e
                // og:locale + og:locale:alternate. Guard: Nexus_Multilang presente;
                // classe assente, non-singular o poche lingue: output invariato, mai fatale.
                if ( class_exists( 'Nexus_Multilang' ) && 'post' === $post->post_type ) {
                        $translations = Nexus_Multilang::translations_of( $post_id );

                        // Solo traduzioni davvero pubblicate, con permalink utilizzabile.
                        $published_translations = array();
                        if ( is_array( $translations ) && ! empty( $translations ) ) {
                                foreach ( $translations as $lang => $translation_id ) {
                                        $lang           = sanitize_key( (string) $lang );
                                        $translation_id = absint( $translation_id );

                                        if ( '' === $lang || $translation_id < 1 || 'publish' !== get_post_status( $translation_id ) ) {
                                                continue;
                                        }

                                        $translation_url = get_permalink( $translation_id );
                                        if ( ! is_string( $translation_url ) || '' === $translation_url ) {
                                                continue;
                                        }

                                        $published_translations[ $lang ] = $translation_url;
                                }
                        }

                        // Almeno due lingue pubblicate: emette hreflang e og:locale.
                        if ( count( $published_translations ) > 1 ) {
                                // Un link hreflang per ciascuna lingua pubblicata.
                                foreach ( $published_translations as $lang => $translation_url ) {
                                        echo '<link rel="alternate" hreflang="' . esc_attr( $lang ) . '" href="' . esc_url( $translation_url ) . '" />' . "\n";
                                }

                                // x-default: permalink della lingua predefinita, se tra le pubblicate.
                                $default_lang = sanitize_key( (string) Nexus_Multilang::default_lang() );
                                if ( '' !== $default_lang && isset( $published_translations[ $default_lang ] ) ) {
                                        echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $published_translations[ $default_lang ] ) . '" />' . "\n";
                                }

                                // Mappa codice lingua: locale Open Graph (fuori mappa: codice così com'è).
                                $locale_map = array(
                                        'it' => 'it_IT',
                                        'en' => 'en_US',
                                        'es' => 'es_ES',
                                        'fr' => 'fr_FR',
                                        'de' => 'de_DE',
                                        'pt' => 'pt_PT',
                                        'ar' => 'ar_AR',
                                        'zh' => 'zh_CN',
                                        'ja' => 'ja_JP',
                                );

                                // og:locale del post corrente.
                                $current_lang   = sanitize_key( (string) Nexus_Multilang::lang_of_post( $post_id ) );
                                $current_locale = isset( $locale_map[ $current_lang ] ) ? $locale_map[ $current_lang ] : $current_lang;
                                if ( '' !== $current_locale ) {
                                        echo '<meta property="og:locale" content="' . esc_attr( $current_locale ) . '">' . "\n";
                                }

                                // og:locale:alternate per le altre lingue pubblicate.
                                foreach ( array_keys( $published_translations ) as $lang ) {
                                        if ( $lang === $current_lang ) {
                                                continue;
                                        }
                                        $alternate_locale = isset( $locale_map[ $lang ] ) ? $locale_map[ $lang ] : $lang;
                                        echo '<meta property="og:locale:alternate" content="' . esc_attr( $alternate_locale ) . '">' . "\n";
                                }
                        }
                }

                // JSON-LD NewsArticle (dati strutturati per i motori di ricerca).
                // v1.1.14 (P6): UN SOLO blocco (esteso, mai duplicato) con
                // dateModified, publisher+logo, articleSection e author tipizzato.
                $ld_headline       = '' !== $seo_title ? $seo_title : self::clean_text( get_the_title( $post_id ), 0 );
                $ld_description    = $seo_description;
                $ld_published      = self::clean_text( $published, 0 );
                $ld_modified       = self::clean_text( $modified, 0 );

                $schema = array(
                        '@context'         => 'https://schema.org',
                        '@type'            => 'NewsArticle',
                        'headline'         => $ld_headline,
                        'description'      => $ld_description,
                        'mainEntityOfPage' => array(
                                '@type' => 'WebPage',
                                '@id'   => $permalink,
                        ),
                );

                // Author: Person per agente/utente reale, Organization quando
                // l'unico autore disponibile è il nome del sito (v1.1.14 P6).
                if ( $author_is_site ) {
                        $schema['author'] = array(
                                '@type' => 'Organization',
                                'name'  => $author_name,
                        );
                } else {
                        $schema['author'] = array(
                                '@type' => 'Person',
                                'name'  => $author_name,
                        );
                }

                // Publisher: Organization col nome del sito; logo dal site icon
                // (campo non configurabile nel plugin: niente opzioni inventate).
                $publisher = array(
                        '@type' => 'Organization',
                        'name'  => self::clean_text( get_bloginfo( 'name' ), 0 ),
                );
                $logo_url = get_site_icon_url( 512 );
                if ( is_string( $logo_url ) && '' !== $logo_url ) {
                        $publisher['logo'] = array(
                                '@type' => 'ImageObject',
                                'url'   => $logo_url,
                        );
                }
                $schema['publisher'] = $publisher;

                if ( '' !== $ld_published ) {
                        $schema['datePublished'] = $ld_published;
                }
                if ( '' !== $ld_modified ) {
                        $schema['dateModified'] = $ld_modified;
                }
                if ( '' !== $ld_section ) {
                        $schema['articleSection'] = $ld_section;
                }
                // "image" viene incluso solo se disponibile (valori vuoti non ammessi da schema.org).
                if ( '' !== $image_url ) {
                        $schema['image'] = $image_url;
                }

                $json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
                if ( is_string( $json ) && '' !== $json ) {
                        // Previene l'interruzione del tag <script> se il testo contiene '</'.
                        $json = str_replace( '</', '<\\/', $json );
                        echo '<script type="application/ld+json">' . $json . '</script>' . "\n";
                }

                echo '<!-- /SEO Nexus News AI -->' . "\n";
        }

        /**
         * Hook pre_get_document_title: title tag per le single nexus_article.
         *
         * Usa _nexus_seo_title se presente; altrimenti "Titolo post | Nome sito"
         * con separatore ' | '. Su ogni altra pagina restituisce $title intatto.
         *
         * @param string $title Title corrente passato da WordPress.
         * @return string
         */
        public static function filter_title( $title ) {
                // v1.1.0 dual-mode: title tag anche sui post nativi, non solo sul CPT legacy.
                $types = class_exists( 'Nexus_Native' ) && method_exists( 'Nexus_Native', 'query_post_types' ) ? Nexus_Native::query_post_types() : array( 'post', 'nexus_article' );

                if ( ! is_singular( $types ) ) {
                        return $title;
                }

                $post_id = get_queried_object_id();
                if ( $post_id <= 0 ) {
                        return $title;
                }

                // Priorità al titolo SEO generato (meta _nexus_seo_title).
                $seo_title = get_post_meta( $post_id, '_nexus_seo_title', true );
                if ( is_string( $seo_title ) && '' !== trim( $seo_title ) ) {
                        return self::clean_text( $seo_title, 0 );
                }

                // Fallback: titolo del post + separatore ' | ' + nome del sito.
                $post_title = get_the_title( $post_id );
                if ( '' === trim( (string) $post_title ) ) {
                        return $title;
                }

                return self::clean_text( $post_title, 0 ) . ' | ' . get_bloginfo( 'name' );
        }

        /**
         * Legge un campo da un articolo in formato oggetto (row DB) o array.
         *
         * @param array|object|null $article Articolo Nexus.
         * @param string            $key     Nome del campo.
         * @return string Valore come stringa ('' se assente).
         */
        private static function field( $article, $key ) {
                if ( is_object( $article ) && isset( $article->{$key} ) ) {
                        return (string) $article->{$key};
                }
                if ( is_array( $article ) && isset( $article[ $key ] ) ) {
                        return (string) $article[ $key ];
                }
                return '';
        }

        /**
         * Ripulisce un testo per l'uso in meta tag e JSON-LD: rimuove i tag HTML,
         * collassa spazi/newline in spazi singoli e tronca alla lunghezza massima
         * (multibyte quando disponibile, come in Nexus_AI_Engine::clip()).
         *
         * @param string $text Testo di partenza.
         * @param int    $max  Lunghezza massima in caratteri (0 = nessun troncamento).
         * @return string
         */
        private static function clean_text( $text, $max = 0 ) {
                $text = trim( wp_strip_all_tags( (string) $text ) );
                // I meta tag non devono contenere newline: collassa gli spazi bianchi.
                $text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

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
}

