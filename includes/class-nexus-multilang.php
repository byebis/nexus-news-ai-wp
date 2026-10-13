<?php
/**
 * Nexus Multilang — adattatore multilingua WordPress (v1.1.2 "Multilingua nativo").
 *
 * PRINCIPIO ( decisione di prodotto, contratto CONTRATTO-v1.1.2.md ):
 * le traduzioni diventano POST WordPress veri e propri, collegati tra loro
 * ESATTAMENTE come fa il multilingua di WordPress. Se sul sito c'e' gia'
 * Polylang o WPML usiamo LE LORO API (zero conflitti, zero duplicazioni);
 * se non c'e' niente usiamo un collegamento interno del plugin basato su
 * post meta (zero tabelle nuove), pronto a convivere con Polylang/WPML
 * se l'utente li installera' in futuro.
 *
 * DRIVER ( selezionato automaticamente, filtrabile con 'nexus_multilang_driver' ):
 *  - 'polylang' — API pubblica pll_* : pll_get_post_language, pll_set_post_language,
 *    pll_get_post_translations, pll_save_post_translations, pll_languages_list,
 *    pll_current_language, pll_default_language, pll_count_posts.
 *  - 'wpml' — filtri WPML: wpml_element_language_details, wpml_set_element_language_details,
 *    wpml_get_element_translations, wpml_active_languages, wpml_default_language.
 *  - 'nexus' — fallback interno del plugin:
 *       meta _nexus_lang            = codice lingua del post
 *       meta _nexus_trid            = chiave del gruppo di traduzione (es. nexus-g42)
 *       meta _nexus_source_post_id  = ID del post sorgente della traduzione
 *
 * API (tutte statiche, mai fatale, sempre degradevoli):
 *  driver(), site_langs(), current_lang(), default_lang(), language_names(), lang_name()
 *  lang_of_post(), set_lang_of_post()
 *  group_of_post(), translations_of(), post_for_lang()
 *  register_translation( $post_id, $lang, $source_post_id )
 *  stats_counts()  — articoli pubblicati per lingua (dashboard)
 *  doctor( $fix = false ) — v1.1.9: diagnostica/riparazione collegamenti multilingua
 *    (ritorna array( 'report' => string[], 'fixed' => int ); ZERO distruttività senza $fix).
 *
 * CONVENZIONI: nessun fatal se i plugin di lingua mancano o cambiano API;
 * le chiamate degradano al driver 'nexus' o a valori vuoti onesti.
 *
 * @package Nexus_News_AI
 * @since   1.1.2
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Multilang {

        const META_LANG   = '_nexus_lang';
        const META_GROUP  = '_nexus_trid';
        const META_SOURCE = '_nexus_source_post_id';
        const FILTER      = 'nexus_multilang_driver';

        /* ========================= BOOTSTRAP ========================= */

        /**
         * Bootstrap: nessun hook obbligatorio, il driver si decide al volo.
         * Punto di aggancio futuro per query var e filtri di lingua.
         *
         * @return void
         */
        public static function init() {
        }

        /* ========================= DRIVER E LINGUE ========================= */

        /**
         * Driver attivo: 'polylang' se Polylang e' attivo, 'wpml' se WPML e'
         * attivo, altrimenti 'nexus' (collegamento interno del plugin).
         *
         * @return string
         */
        public static function driver() {
                $forced = apply_filters( self::FILTER, '' );
                if ( is_string( $forced ) && '' !== $forced ) {
                        return $forced;
                }
                if ( function_exists( 'pll_get_post_language' ) ) {
                        return 'polylang';
                }
                if ( class_exists( 'SitePress' ) ) {
                        return 'wpml';
                }
                return 'nexus';
        }

        /**
         * Codici lingua del sito (es. array( 'it', 'en', 'es' )).
         * Nexus driver: lingua del sito + lingue attive del setting translation_langs.
         *
         * @return array Lista di codici (default del sito al primo posto se noto).
         */
        public static function site_langs() {
                $d = self::driver();

                if ( 'polylang' === $d && function_exists( 'pll_languages_list' ) ) {
                        $langs = pll_languages_list( array( 'fields' => 'slug' ) );
                        if ( is_array( $langs ) && ! empty( $langs ) ) {
                                return array_values( array_unique( array_map( 'sanitize_key', $langs ) ) );
                        }
                }

                if ( 'wpml' === $d ) {
                        $active = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
                        if ( is_array( $active ) && ! empty( $active ) ) {
                                return array_values( array_unique( array_map( 'sanitize_key', array_keys( $active ) ) ) );
                        }
                }

                // Driver nexus: lingua del sito + lingue del setting traduzioni.
                $out = array( self::default_lang() );

                if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'get_settings' ) ) {
                        $settings = Nexus_DB::get_settings();
                        $raw      = isset( $settings['translation_langs'] ) ? (string) $settings['translation_langs'] : '';
                        foreach ( explode( ',', $raw ) as $part ) {
                                $code = sanitize_key( trim( $part ) );
                                if ( '' !== $code && ! in_array( $code, $out, true ) ) {
                                        $out[] = $code;
                                }
                        }
                }

                return array_values( array_unique( $out ) );
        }

        /**
         * Lingua corrente della richiesta.
         *
         * @return string Codice lingua (mai vuoto: fallback default_lang()).
         */
        public static function current_lang() {
                $d = self::driver();

                if ( 'polylang' === $d && function_exists( 'pll_current_language' ) ) {
                        $cur = pll_current_language( 'slug' );
                        if ( is_string( $cur ) && '' !== $cur ) {
                                return sanitize_key( $cur );
                        }
                }

                if ( 'wpml' === $d && defined( 'ICL_LANGUAGE_CODE' ) && '' !== (string) ICL_LANGUAGE_CODE ) {
                        return sanitize_key( (string) ICL_LANGUAGE_CODE );
                }

                return self::default_lang();
        }

        /**
         * Lingua predefinita del sito.
         *
         * @return string Codice lingua (fallback: primissimi caratteri del locale WP).
         */
        public static function default_lang() {
                $d = self::driver();

                if ( 'polylang' === $d && function_exists( 'pll_default_language' ) ) {
                        $def = pll_default_language( 'slug' );
                        if ( is_string( $def ) && '' !== $def ) {
                                return sanitize_key( $def );
                        }
                }

                if ( 'wpml' === $d ) {
                        $def = apply_filters( 'wpml_default_language', null );
                        if ( is_string( $def ) && '' !== $def ) {
                                return sanitize_key( $def );
                        }
                }

                $locale = function_exists( 'get_locale' ) ? (string) get_locale() : 'it';
                $def    = sanitize_key( substr( $locale, 0, 2 ) );

                return '' !== $def ? $def : 'it';
        }

        /**
         * Mappa codice lingua - nome italiano per la UI (etichette admin e prompt AI).
         *
         * @return array
         */
        public static function language_names() {
                return array(
                        'it' => 'Italiano',
                        'en' => 'Inglese',
                        'es' => 'Spagnolo',
                        'fr' => 'Francese',
                        'de' => 'Tedesco',
                        'pt' => 'Portoghese',
                        'ar' => 'Arabo',
                        'zh' => 'Cinese',
                        'ja' => 'Giapponese',
                );
        }

        /**
         * Nome italiano di una lingua; codici fuori mappa restano se stessi.
         *
         * @param string $code Codice lingua.
         * @return string
         */
        public static function lang_name( $code ) {
                $code = sanitize_key( (string) $code );
                $map  = self::language_names();
                return isset( $map[ $code ] ) ? $map[ $code ] : strtoupper( $code );
        }

        /* ========================= LINGUA DEI POST ========================= */

        /**
         * Lingua di un post.
         *
         * @param int $post_id Post ID.
         * @return string Codice lingua (fallback: default_lang()).
         */
        public static function lang_of_post( $post_id ) {
                $post_id = absint( $post_id );
                if ( $post_id < 1 ) {
                        return self::default_lang();
                }

                $d = self::driver();

                if ( 'polylang' === $d && function_exists( 'pll_get_post_language' ) ) {
                        $lang = pll_get_post_language( $post_id, 'slug' );
                        if ( is_string( $lang ) && '' !== $lang ) {
                                return sanitize_key( $lang );
                        }
                        return self::default_lang();
                }

                if ( 'wpml' === $d ) {
                        $details = apply_filters( 'wpml_element_language_details', null, array(
                                'element_id'   => $post_id,
                                'element_type' => 'post_post',
                        ) );
                        if ( $details && isset( $details->language_code ) && '' !== (string) $details->language_code ) {
                                return sanitize_key( (string) $details->language_code );
                        }
                        return self::default_lang();
                }

                // Driver nexus: meta, fallback lingua di default.
                $meta = get_post_meta( $post_id, self::META_LANG, true );
                $lang = is_string( $meta ) ? sanitize_key( trim( $meta ) ) : '';
                return '' !== $lang ? $lang : self::default_lang();
        }

        /**
         * Imposta la lingua di un post (solo driver nexus: con Polylang o WPML
         * la lingua si imposta tramite register_translation o le loro UI).
         *
         * @param int    $post_id Post ID.
         * @param string $lang    Codice lingua.
         * @return void
         */
        public static function set_lang_of_post( $post_id, $lang ) {
                $post_id = absint( $post_id );
                $lang    = sanitize_key( (string) $lang );
                if ( $post_id < 1 || '' === $lang ) {
                        return;
                }

                $d = self::driver();

                if ( 'polylang' === $d && function_exists( 'pll_set_post_language' ) ) {
                        pll_set_post_language( $post_id, $lang );
                        return;
                }

                if ( 'wpml' === $d ) {
                        do_action( 'wpml_set_element_language_details', array(
                                'element_id'   => $post_id,
                                'element_type' => 'post_post',
                                'language_code' => $lang,
                        ) );
                        return;
                }

                update_post_meta( $post_id, self::META_LANG, $lang );
        }

        /**
         * Lingua RAW di un post: il codice assegnato DAVVERO, senza fallback a
         * default_lang() come fa lang_of_post(). Serve al doctor per distinguere
         * "post senza lingua" (anomalia) da "lingua default del sito" (regolare).
         *
         * @param int $post_id Post ID.
         * @return string Codice lingua o '' se non assegnata.
         */
        private static function raw_lang_of_post( $post_id ) {
                $post_id = absint( $post_id );
                if ( $post_id < 1 ) {
                        return '';
                }

                $d = self::driver();

                if ( 'polylang' === $d && function_exists( 'pll_get_post_language' ) ) {
                        $lang = pll_get_post_language( $post_id, 'slug' );
                        return ( is_string( $lang ) && '' !== $lang ) ? sanitize_key( $lang ) : '';
                }

                if ( 'wpml' === $d ) {
                        $details = apply_filters( 'wpml_element_language_details', null, array(
                                'element_id'   => $post_id,
                                'element_type' => 'post_post',
                        ) );
                        if ( $details && ! empty( $details->language_code ) ) {
                                return sanitize_key( (string) $details->language_code );
                        }
                        return '';
                }

                $meta = get_post_meta( $post_id, self::META_LANG, true );
                return is_string( $meta ) ? sanitize_key( trim( $meta ) ) : '';
        }

        /* ========================= GRUPPI DI TRADUZIONE ========================= */

        /**
         * Chiave di gruppo per un nuovo gruppo di traduzione partito da un post.
         *
         * @param int $post_id Post sorgente.
         * @return string Es. 'nexus-g42'.
         */
        public static function new_group_key( $post_id ) {
                return 'nexus-g' . absint( $post_id );
        }

        /**
         * Chiave gruppo di traduzione di un post (driver nexus: meta; con
         * Polylang e WPML il concetto e' gestito dai plugin stessi).
         *
         * @param int $post_id Post ID.
         * @return string Chiave gruppo o '' se nessuna.
         */
        public static function group_of_post( $post_id ) {
                $post_id = absint( $post_id );
                if ( $post_id < 1 ) {
                        return '';
                }
                $group = get_post_meta( $post_id, self::META_GROUP, true );
                return is_string( $group ) ? sanitize_text_field( $group ) : '';
        }

        /**
         * Traduzioni di un post: mappa codice lingua - post_id (include il post stesso).
         * Mai fatale: con driver inaffidabile ritorna almeno il post stesso.
         *
         * @param int $post_id Post ID.
         * @return array
         */
        public static function translations_of( $post_id ) {
                $post_id = absint( $post_id );
                if ( $post_id < 1 ) {
                        return array();
                }

                $d = self::driver();

                if ( 'polylang' === $d && function_exists( 'pll_get_post_translations' ) ) {
                        $tr = pll_get_post_translations( $post_id );
                        if ( is_array( $tr ) && ! empty( $tr ) ) {
                                $out = array();
                                foreach ( $tr as $lang => $pid ) {
                                        $lang = sanitize_key( (string) $lang );
                                        $pid  = absint( $pid );
                                        if ( '' !== $lang && $pid > 0 ) {
                                                $out[ $lang ] = $pid;
                                        }
                                }
                                if ( ! empty( $out ) ) {
                                        return $out;
                                }
                        }
                }

                if ( 'wpml' === $d ) {
                        $details = apply_filters( 'wpml_element_language_details', null, array(
                                'element_id'   => $post_id,
                                'element_type' => 'post_post',
                        ) );
                        if ( $details && ! empty( $details->trid ) ) {
                                $translations = apply_filters( 'wpml_get_element_translations', null, $details->trid, 'post_post' );
                                if ( is_array( $translations ) && ! empty( $translations ) ) {
                                        $out = array();
                                        foreach ( $translations as $lang => $item ) {
                                                $lang = sanitize_key( (string) $lang );
                                                $pid  = ( is_object( $item ) && isset( $item->element_id ) ) ? absint( $item->element_id ) : 0;
                                                if ( '' !== $lang && $pid > 0 ) {
                                                        $out[ $lang ] = $pid;
                                                }
                                        }
                                        if ( ! empty( $out ) ) {
                                                return $out;
                                        }
                                }
                        }
                }

                // Driver nexus: cerca i post con lo stesso gruppo.
                $group = self::group_of_post( $post_id );
                if ( '' === $group ) {
                        return array( self::lang_of_post( $post_id ) => $post_id );
                }

                $ids = function_exists( 'get_posts' )
                        ? get_posts( array(
                                'post_type'        => 'post',
                                'post_status'      => 'any',
                                'meta_key'         => self::META_GROUP,
                                'meta_value'       => $group,
                                'numberposts'      => 100,
                                'orderby'          => 'ID',
                                'order'            => 'ASC',
                                'fields'           => 'ids',
                                'suppress_filters' => false,
                        ) )
                        : array();

                $out = array();
                foreach ( (array) $ids as $pid ) {
                        $pid  = absint( $pid );
                        $lang = self::lang_of_post( $pid );
                        if ( ! isset( $out[ $lang ] ) ) {
                                $out[ $lang ] = $pid;
                        }
                }
                if ( ! isset( $out[ self::lang_of_post( $post_id ) ] ) ) {
                        $out[ self::lang_of_post( $post_id ) ] = $post_id;
                }

                return $out;
        }

        /**
         * Post ID della traduzione in una lingua (0 se assente).
         *
         * @param int    $post_id Post di partenza.
         * @param string $lang    Codice lingua richiesto.
         * @return int
         */
        public static function post_for_lang( $post_id, $lang ) {
                $lang = sanitize_key( (string) $lang );
                $tr   = self::translations_of( $post_id );
                return ( isset( $tr[ $lang ] ) ) ? absint( $tr[ $lang ] ) : 0;
        }

        /**
         * Registra un post come traduzione di un post sorgente:
         * imposta la lingua e collega il gruppo di traduzione (con Polylang e
         * WPML usa le loro associazioni ufficiali, con il driver nexus i meta).
         *
         * @param int    $post_id        Post della traduzione (nuovo o aggiornato).
         * @param string $lang           Codice lingua della traduzione.
         * @param int    $source_post_id Post sorgente (lingua di partenza).
         * @return bool True se il collegamento e' riuscito (o gia' presente).
         */
        public static function register_translation( $post_id, $lang, $source_post_id ) {
                $post_id        = absint( $post_id );
                $lang           = sanitize_key( (string) $lang );
                $source_post_id = absint( $source_post_id );

                if ( $post_id < 1 || '' === $lang || $source_post_id < 1 ) {
                        return false;
                }

                $d = self::driver();

                if ( 'polylang' === $d && function_exists( 'pll_save_post_translations' ) && function_exists( 'pll_get_post_translations' ) ) {
                        // Il sorgente senza lingua Polylang (tipico dei post creati via wp_insert_post)
                        // produceva un gruppo SENZA sorgente: pll_get_post_translations() tornava vuoto,
                        // il run successivo non trovava la traduzione e creava un post duplicato.
                        // Fix v1.1.9: al sorgente viene assegnata la lingua default del sito PRIMA
                        // di costruire il gruppo, così compare sempre nella mappa delle traduzioni.
                        $source_lang = self::raw_lang_of_post( $source_post_id );
                        if ( '' === $source_lang ) {
                                $source_lang = self::default_lang();
                                pll_set_post_language( $source_post_id, $source_lang );
                        }

                        pll_set_post_language( $post_id, $lang );

                        $tr = pll_get_post_translations( $source_post_id );
                        $tr = is_array( $tr ) ? $tr : array();
                        // Il sorgente va SEMPRE nel gruppo, con la sua lingua (default se sconosciuta):
                        // pll_save_post_translations() sovrascrive il gruppo, non aggiungerlo qui
                        // riaprirebbe la stessa anomalia dei duplicati.
                        $tr[ $source_lang ] = $source_post_id;
                        $tr[ $lang ]        = $post_id;
                        pll_save_post_translations( $tr );

                        // Traccia il gruppo anche sui meta interni (query veloci, doctor, report):
                        // fino alla v1.1.9 questa sincronizzazione esisteva solo nel ramo WPML.
                        self::sync_internal_group( $post_id, $source_post_id );
                        return true;
                }

                if ( 'wpml' === $d ) {
                        $details = apply_filters( 'wpml_element_language_details', null, array(
                                'element_id'   => $source_post_id,
                                'element_type' => 'post_post',
                        ) );
                        if ( $details && ! empty( $details->trid ) ) {
                                do_action( 'wpml_set_element_language_details', array(
                                        'element_id'          => $post_id,
                                        'element_type'        => 'post_post',
                                        'trid'                => $details->trid,
                                        'language_code'       => $lang,
                                        'source_language_code' => isset( $details->language_code ) ? $details->language_code : null,
                                ) );
                                // Il gruppo interno resta sincronizzato come traccia di plugin.
                                self::sync_internal_group( $post_id, $source_post_id );
                                return true;
                        }
                        // WPML presente ma dettagli assenti: fallback interno.
                }

                // Driver nexus (o fallback): meta lingua + gruppo + sorgente.
                self::set_lang_of_post( $post_id, $lang );

                $group = self::group_of_post( $source_post_id );
                if ( '' === $group ) {
                        $group = self::new_group_key( $source_post_id );
                        update_post_meta( $source_post_id, self::META_GROUP, $group );
                }
                // Il sorgente senza lingua esplicita distorce stats_counts() e i gruppi:
                // gli assegniamo la lingua default del sito (idempotente, mai distruttivo).
                if ( '' === self::raw_lang_of_post( $source_post_id ) ) {
                        self::set_lang_of_post( $source_post_id, self::default_lang() );
                }
                update_post_meta( $post_id, self::META_GROUP, $group );
                update_post_meta( $post_id, self::META_SOURCE, $source_post_id );

                return true;
        }

        /**
         * Allinea il gruppo interno (meta _nexus_trid) anche quando l'associazione
         * ufficiale e' stata fatta da Polylang o WPML: serve a report e query
         * veloci del plugin, mai alla verita' (che sta nei driver).
         *
         * @param int $post_id        Post traduzione.
         * @param int $source_post_id Post sorgente.
         * @return void
         */
        private static function sync_internal_group( $post_id, $source_post_id ) {
                $group = self::group_of_post( $source_post_id );
                if ( '' === $group ) {
                        $group = self::new_group_key( $source_post_id );
                        update_post_meta( $source_post_id, self::META_GROUP, $group );
                }
                if ( '' === self::group_of_post( $post_id ) ) {
                        update_post_meta( $post_id, self::META_GROUP, $group );
                }
        }

        /* ========================= STATISTICHE ========================= */

        /**
         * Articoli pubblicati per lingua: mappa codice - numero (solo lingue con almeno 1).
         * Fonte per la card dashboard; con Polylang usa pll_count_posts, con WPML la
         * tabella icl_translations, con il driver nexus i meta del plugin.
         *
         * @return array
         */
        public static function stats_counts() {
                global $wpdb;
                $out = array();
                $d   = self::driver();

                if ( 'polylang' === $d && function_exists( 'pll_count_posts' ) ) {
                        foreach ( self::site_langs() as $lang ) {
                                $counts = pll_count_posts( $lang );
                                $n      = ( is_array( $counts ) && isset( $counts['publish'] ) ) ? absint( $counts['publish'] ) : 0;
                                if ( $n > 0 ) {
                                        $out[ $lang ] = $n;
                                }
                        }
                        return $out;
                }

                if ( 'wpml' === $d ) {
                        $table = $wpdb->prefix . 'icl_translations';
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $rows = $wpdb->get_results(
                                "SELECT t.language_code AS lang, COUNT(*) AS n
                                 FROM {$table} t
                                 INNER JOIN {$wpdb->posts} p ON p.ID = t.element_id
                                 WHERE t.element_type = 'post_post'
                                   AND p.post_status = 'publish'
                                 GROUP BY t.language_code"
                        );
                        foreach ( (array) $rows as $row ) {
                                $lang = sanitize_key( (string) $row->lang );
                                $n    = absint( $row->n );
                                if ( '' !== $lang && $n > 0 ) {
                                        $out[ $lang ] = $n;
                                }
                        }
                        return $out;
                }

                // Driver nexus: conteggio sui meta del plugin.
                $rows = $wpdb->get_results(
                        $wpdb->prepare(
                                "SELECT pm.meta_value AS lang, COUNT(*) AS n
                                 FROM {$wpdb->postmeta} pm
                                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                                 WHERE pm.meta_key = %s
                                   AND p.post_type = 'post'
                                   AND p.post_status = 'publish'
                                 GROUP BY pm.meta_value",
                                self::META_LANG
                        )
                );
                foreach ( (array) $rows as $row ) {
                        $lang = sanitize_key( (string) $row->lang );
                        $n    = absint( $row->n );
                        if ( '' !== $lang && $n > 0 ) {
                                $out[ $lang ] = $n;
                        }
                }

                return $out;
        }

        /* ========================= DIAGNOSTICA (v1.1.9) ========================= */

        /**
         * Doctor multilingua: diagnostica e (su richiesta) riparazione dei
         * collegamenti di traduzione. ZERO distruttività senza $fix esplicito.
         *
         * Controlli:
         *  (a) sorgenti Nexus senza lingua assegnata → report; con $fix la lingua
         *      default del sito viene assegnata (stessa anomalia che generava gruppi
         *      Polylang senza sorgente e post duplicati);
         *  (b) gruppi senza sorgente o incompleti (interni e, con Polylang/WPML,
         *      anche officiali) → report; con $fix il gruppo viene ricostruito col
         *      sorgente (meta interno + associazione ufficiale via register_translation);
         *  (c) traduzioni duplicate per stessa lingua e stesso articolo → SOLO
         *      rilevazione, anche con $fix (nessuna cancellazione automatica: quale
         *      copia tenere è una decisione editoriale, non meccanica);
         *  (d) collegamenti interni rotti (meta sorgente che punta a un post
         *      inesistente) → report; con $fix il meta rotto viene rimosso.
         *
         * Firma pensata per il comando WP-CLI `wp nexus multilang doctor [--fix]`
         * (aggiunto da D1 con guard method_exists): mai fatale, mai bloccante.
         *
         * @param bool $fix True per applicare le riparazioni sicure.
         * @return array{report:string[],fixed:int} Righe di report testuali + numero riparazioni applicate.
         */
        public static function doctor( $fix = false ) {
                $report = array();
                $fixed  = 0;
                $fix    = (bool) $fix;
                $driver = self::driver();

                $report[] = sprintf(
                        'Doctor multilingua Nexus — driver: %1$s — modalità: %2$s.',
                        $driver,
                        $fix ? 'riparazione' : 'sola lettura'
                );

                /* Tutti i post collegati come traduzioni Nexus (meta sorgente). */
                $linked_ids = function_exists( 'get_posts' )
                        ? get_posts( array(
                                'post_type'        => 'post',
                                'post_status'      => 'any',
                                'meta_key'         => self::META_SOURCE,
                                'numberposts'      => 1000,
                                'orderby'          => 'ID',
                                'order'            => 'ASC',
                                'fields'           => 'ids',
                                'suppress_filters' => false,
                        ) )
                        : array();
                $linked_ids = array_map( 'absint', (array) $linked_ids );

                /* (d) Collegamenti rotti + raccolta sorgenti distinti. */
                $sources = array();
                foreach ( $linked_ids as $pid ) {
                        $source_id = absint( get_post_meta( $pid, self::META_SOURCE, true ) );
                        if ( $source_id < 1 ) {
                                continue;
                        }
                        $sources[ $source_id ] = true;

                        if ( ! get_post( $source_id ) ) {
                                $report[] = sprintf( 'Rotto: la traduzione #%1$d punta al sorgente #%2$d che non esiste (meta %3$s).', $pid, $source_id, self::META_SOURCE );
                                if ( $fix ) {
                                        // Solo il meta rotto viene rimosso: il post traduzione resta intatto.
                                        delete_post_meta( $pid, self::META_SOURCE );
                                        $fixed++;
                                        $report[] = sprintf( 'Riparato: meta sorgente rotto rimosso dalla traduzione #%d.', $pid );
                                }
                        }
                }

                /* (a) Sorgenti senza lingua assegnata. */
                foreach ( array_keys( $sources ) as $source_id ) {
                        if ( ! get_post( $source_id ) ) {
                                // Sorgente inesistente: già segnalato in (d), qui si evita il doppio report.
                                continue;
                        }
                        if ( '' === self::raw_lang_of_post( $source_id ) ) {
                                $report[] = sprintf( 'Sorgente #%1$d senza lingua assegnata (tipico dei post creati via wp_insert_post).', $source_id );
                                if ( $fix ) {
                                        self::set_lang_of_post( $source_id, self::default_lang() );
                                        $fixed++;
                                        $report[] = sprintf( 'Riparato: lingua "%1$s" assegnata al sorgente #%2$d.', self::default_lang(), $source_id );
                                }
                        }
                }

                /* Traduzioni senza lingua: solo rilevazione, la lingua vera non è deducibile in sicurezza. */
                foreach ( $linked_ids as $pid ) {
                        if ( '' === self::raw_lang_of_post( $pid ) ) {
                                $report[] = sprintf( 'Attenzione: la traduzione #%1$d non ha lingua assegnata (intervento manuale richiesto).', $pid );
                        }
                }

                /* Gruppi interni incompleti: membri con sorgente dichiarato fuori dal gruppo. */
                $group_ids = function_exists( 'get_posts' )
                        ? get_posts( array(
                                'post_type'        => 'post',
                                'post_status'      => 'any',
                                'meta_key'         => self::META_GROUP,
                                'numberposts'      => 1000,
                                'orderby'          => 'ID',
                                'order'            => 'ASC',
                                'fields'           => 'ids',
                                'suppress_filters' => false,
                        ) )
                        : array();

                $groups = array();
                foreach ( (array) $group_ids as $pid ) {
                        $g = self::group_of_post( absint( $pid ) );
                        if ( '' !== $g ) {
                                $groups[ $g ][] = absint( $pid );
                        }
                }

                foreach ( $groups as $g => $members ) {
                        $members = array_values( array_unique( $members ) );

                        // Sorgente dichiarato: il primo membro che porta il meta sorgente.
                        $declared_source = 0;
                        foreach ( $members as $m ) {
                                $sid = absint( get_post_meta( $m, self::META_SOURCE, true ) );
                                if ( $sid > 0 ) {
                                        $declared_source = $sid;
                                        break;
                                }
                        }

                        if ( $declared_source > 0 && get_post( $declared_source ) && ! in_array( $declared_source, $members, true ) ) {
                                $report[] = sprintf( 'Gruppo incompleto "%1$s": il sorgente #%2$d non è tra i membri (%3$d membri).', $g, $declared_source, count( $members ) );
                                if ( $fix ) {
                                        // Ricostruzione col sorgente: meta gruppo sul sorgente + lingua se assente.
                                        update_post_meta( $declared_source, self::META_GROUP, $g );
                                        if ( '' === self::raw_lang_of_post( $declared_source ) ) {
                                                self::set_lang_of_post( $declared_source, self::default_lang() );
                                        }
                                        $fixed++;
                                        $report[] = sprintf( 'Riparato: sorgente #%1$d reinserito nel gruppo "%2$s".', $declared_source, $g );
                                }
                        }

                        /* (c) Duplicati per stessa lingua nello stesso gruppo: SOLO rilevazione. */
                        $seen_langs = array();
                        foreach ( $members as $m ) {
                                $l = self::raw_lang_of_post( $m );
                                if ( '' === $l ) {
                                        continue;
                                }
                                if ( isset( $seen_langs[ $l ] ) ) {
                                        $report[] = sprintf( 'Duplicato: nel gruppo "%1$s" due post (%2$d e %3$d) sono in lingua "%4$s" (nessuna cancellazione automatica: scelta editoriale).', $g, $seen_langs[ $l ], $m, $l );
                                } else {
                                        $seen_langs[ $l ] = $m;
                                }
                        }
                }

                /* (b) Con Polylang/WPML: il gruppo UFFICIALE deve contenere sorgente e traduzione. */
                if ( 'polylang' === $driver || 'wpml' === $driver ) {
                        foreach ( $linked_ids as $pid ) {
                                $source_id = absint( get_post_meta( $pid, self::META_SOURCE, true ) );
                                if ( $source_id < 1 || ! get_post( $source_id ) || ! get_post( $pid ) ) {
                                        continue;
                                }

                                $map       = self::translations_of( $source_id );
                                $map_ids   = array_map( 'absint', array_values( (array) $map ) );
                                $problems  = array();
                                if ( ! in_array( $source_id, $map_ids, true ) ) {
                                        $problems[] = sprintf( 'sorgente #%d assente dal gruppo', $source_id );
                                }
                                if ( ! in_array( $pid, $map_ids, true ) ) {
                                        $problems[] = sprintf( 'traduzione #%d assente dal gruppo', $pid );
                                }

                                if ( ! empty( $problems ) ) {
                                        $report[] = sprintf( 'Gruppo %1$s incompleto: %2$s.', ( 'polylang' === $driver ? 'Polylang' : 'WPML' ), implode( '; ', $problems ) );
                                        if ( $fix ) {
                                                // register_translation ricostruisce il gruppo: lingua del post,
                                                // sorgente SEMPRE incluso e meta interni sincronizzati.
                                                $lang_fix = self::raw_lang_of_post( $pid );
                                                if ( '' === $lang_fix ) {
                                                        $lang_fix = self::default_lang();
                                                }
                                                if ( self::register_translation( $pid, $lang_fix, $source_id ) ) {
                                                        $fixed++;
                                                        $report[] = sprintf( 'Riparato: gruppo ricostruito con sorgente #%1$d e traduzione #%2$d.', $source_id, $pid );
                                                } else {
                                                        $report[] = sprintf( 'Non riparato: ricostruzione del gruppo fallita per la traduzione #%d.', $pid );
                                                }
                                        }
                                }
                        }
                }

                /* (c) Duplicati per stessa lingua riferiti allo stesso sorgente (anche fuori gruppo). */
                foreach ( array_keys( $sources ) as $source_id ) {
                        if ( ! get_post( $source_id ) ) {
                                continue;
                        }
                        $seen = array();
                        foreach ( $linked_ids as $pid ) {
                                if ( absint( get_post_meta( $pid, self::META_SOURCE, true ) ) !== $source_id ) {
                                        continue;
                                }
                                $l = self::raw_lang_of_post( $pid );
                                if ( '' === $l ) {
                                        continue;
                                }
                                if ( isset( $seen[ $l ] ) ) {
                                        $report[] = sprintf( 'Duplicato: il sorgente #%1$d ha le traduzioni #%2$d e #%3$d entrambe in lingua "%4$s" (nessuna cancellazione automatica: scelta editoriale).', $source_id, $seen[ $l ], $pid, $l );
                                } else {
                                        $seen[ $l ] = $pid;
                                }
                        }
                }

                $report[] = sprintf( 'Fine controllo: %1$d riparazione/i applicate.', $fixed );

                return array(
                        'report' => $report,
                        'fixed'  => $fixed,
                );
        }
}
