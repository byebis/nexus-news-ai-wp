<?php
/**
 * Nexus Image — featured image per gli articoli pubblicati come post WordPress.
 *
 * Catena con stop al primo successo (mai bloccante, mai eccezioni propagate):
 * ① GENERAZIONE AI — se settings `enable_ai_images` = 1: POST {ai_base_url}/images/generations
 *    con Authorization Bearer {ai_api_key}; accetta sia data[0].b64_json (decodifica)
 *    sia data[0].url (download con wp_remote_get). Timeout 30s per richiesta.
 * ② FALLBACK image_url — se l'articolo ha image_url non vuota, download (timeout 20s).
 * ③ FALLBACK placeholder locale — con GD: PNG 1200x675 con gradiente derivato dall'hash
 *    della categoria e iniziali del titolo (font builtin, nessun file esterno);
 *    senza GD: SVG minimale (gradiente + testo) come ultima spiaggia.
 *
 * CONTRATTO PUBBLICO (usato dagli altri moduli):
 * Nexus_Image::ensure_featured( $article, $post_id ) — accetta l'articolo come
 *   object|array e l'ID del post WP; ritorna bool: true SOLO se la thumbnail è
 *   stata impostata, false in caso contrario. Una sola immagine per articolo
 *   (n:1, nessun batch; se la thumbnail esiste già non viene rigenerata).
 *   Qualsiasi errore → log attività (Nexus_DB::insert_activity, con guard) + false,
 *   mai fatal e mai eccezioni non catturate.
 *
 * wiring: l'integratore farà require in nexus-news-ai.php
 * (require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-image.php';).
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Image {

        /**
         * Punto d'ingresso: garantisce che il post WP abbia una featured image.
         *
         * @param object|array $article Articolo (riga wp_nexus_articles o array associativo).
         * @param int          $post_id ID del post WordPress (post_type nexus_article).
         * @return bool True solo se la thumbnail è stata impostata (o già presente).
         */
        public static function ensure_featured( $article, $post_id ) {
                $post_id = absint( $post_id );

                try {
                        // Normalizza l'articolo ad array associativo.
                        if ( is_object( $article ) ) {
                                $article = (array) $article;
                        }
                        if ( ! is_array( $article ) || empty( $article ) ) {
                                self::log_activity( 'Articolo non valido: featured image non elaborabile.', 'error' );
                                return false;
                        }

                        $title = self::clean_text( self::pick( $article, 'title' ) );
                        if ( '' === $title ) {
                                self::log_activity( 'Titolo dell\'articolo assente: featured image non generabile.', 'error' );
                                return false;
                        }

                        // Una sola immagine per articolo: se la thumbnail esiste già, non toccare nulla.
                        if ( function_exists( 'has_post_thumbnail' ) && $post_id > 0 && has_post_thumbnail( $post_id ) ) {
                                return true;
                        }

                        if ( $post_id <= 0 ) {
                                self::log_activity( 'ID post WordPress mancante: featured image non impostabile.', 'error' );
                                return false;
                        }

                        $category   = self::clean_text( self::pick( $article, 'category' ) );
                        $article_id = absint( self::pick( $article, 'id' ) );
                        $image_url  = esc_url_raw( trim( (string) self::pick( $article, 'image_url' ) ) );

                        $binary       = '';
                        $ext          = '';
                        $source_desc  = '';
                        $notes        = array();

                        // Impostazioni plugin (guard: il layer dati deve esistere).
                        $settings = array();
                        if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'get_settings' ) ) {
                                $settings = (array) Nexus_DB::get_settings();
                        }

                        // ① GENERAZIONE AI — solo se abilitata nelle impostazioni.
                        $enable_ai = isset( $settings['enable_ai_images'] ) && (int) $settings['enable_ai_images'] === 1;
                        if ( $enable_ai ) {
                                $api_key  = isset( $settings['ai_api_key'] ) ? trim( (string) $settings['ai_api_key'] ) : '';
                                $base_url = isset( $settings['ai_base_url'] ) ? trim( (string) $settings['ai_base_url'] ) : '';
                                if ( '' === $base_url ) {
                                        // Default coerente con Nexus_AI_Engine.
                                        $base_url = 'https://openrouter.ai/api/v1';
                                }

                                if ( '' === $api_key ) {
                                        $notes[] = 'generazione AI saltata (chiave API non configurata)';
                                } else {
                                        $generated = self::generate_ai_image( $title, $category, $settings, $api_key, $base_url );
                                        if ( is_array( $generated ) ) {
                                                $binary      = $generated['binary'];
                                                $ext         = $generated['ext'];
                                                $source_desc = 'generazione AI (modello ' . $generated['model'] . ')';
                                        } else {
                                                $notes[] = 'generazione AI fallita (' . (string) $generated . ')';
                                        }
                                }
                        }

                        // ② FALLBACK image_url dell'articolo.
                        if ( '' === $binary && '' !== $image_url ) {
                                $downloaded = self::download_image( $image_url, 20 );
                                if ( is_array( $downloaded ) ) {
                                        $binary      = $downloaded['binary'];
                                        $ext         = $downloaded['ext'];
                                        $source_desc = 'immagine dall\'articolo (image_url)';
                                } else {
                                        $notes[] = 'image_url non utilizzabile (' . (string) $downloaded . ')';
                                }
                        }

                        // ③ FALLBACK placeholder locale (PNG con GD, altrimenti SVG).
                        if ( '' === $binary ) {
                                $placeholder = self::build_placeholder( $title, $category );
                                $binary      = $placeholder['binary'];
                                $ext         = $placeholder['ext'];
                                $source_desc = $placeholder['desc'];
                        }

                        // SALVATAGGIO: upload + attachment + metadata.
                        $attach_id = self::save_and_attach( $binary, $ext, $article_id, $title, $post_id );
                        if ( $attach_id <= 0 ) {
                                self::log_activity( 'Impossibile salvare la featured image per il post #' . $post_id . ' (upload o attachment falliti).', 'error' );
                                return false;
                        }

                        // Imposta la thumbnail (verifica anche il meta per robustezza).
                        $ok = false;
                        if ( function_exists( 'set_post_thumbnail' ) ) {
                                $ok = (bool) set_post_thumbnail( $post_id, $attach_id );
                        }
                        if ( ! $ok && function_exists( 'get_post_meta' ) ) {
                                $ok = (int) get_post_meta( $post_id, '_thumbnail_id', true ) === $attach_id;
                        }

                        if ( ! $ok ) {
                                self::log_activity( 'set_post_thumbnail fallito per il post #' . $post_id . '.', 'error' );
                                return false;
                        }

                        $suffix = empty( $notes ) ? '' : ' Note: ' . implode( '; ', $notes ) . '.';
                        self::log_activity( 'Featured image impostata sul post #' . $post_id . ' (origine: ' . $source_desc . ').' . $suffix, 'success' );

                        return true;
                } catch ( Throwable $e ) {
                        // Nessuna eccezione deve propagare: log descrittivo e false.
                        self::log_activity( 'Errore imprevisto nella gestione della featured image: ' . $e->getMessage(), 'error' );
                        return false;
                }
        }

        /* ============ ① GENERAZIONE AI ============ */

        /**
         * Chiama POST {ai_base_url}/images/generations e ritorna il binario dell'immagine.
         *
         * @param string $title    Titolo dell'articolo (testo pulito).
         * @param string $category Categoria dell'articolo.
         * @param array  $settings Impostazioni del plugin.
         * @param string $api_key  Chiave API (ai_api_key).
         * @param string $base_url Base URL del provider (ai_base_url).
         * @return array|string Array( 'binary', 'ext', 'model' ) in successo, stringa di errore italiano altrimenti.
         */
        private static function generate_ai_image( $title, $category, $settings, $api_key, $base_url ) {
                $model = isset( $settings['image_model'] ) ? trim( (string) $settings['image_model'] ) : '';
                if ( '' === $model ) {
                        $model = 'gpt-image-1';
                }

                $size = isset( $settings['image_size'] ) ? trim( (string) $settings['image_size'] ) : '';
                if ( '' === $size ) {
                        $size = '1024x1024';
                }

                $endpoint = untrailingslashit( $base_url ) . '/images/generations';

                $encoded_body = wp_json_encode( array(
                        'model'  => $model,
                        'prompt' => self::build_prompt( $title, $category ),
                        'size'   => $size,
                        'n'      => 1,
                ) );
                if ( false === $encoded_body || '' === $encoded_body ) {
                        return 'encoding JSON della richiesta fallito';
                }

                $response = wp_remote_post(
                        $endpoint,
                        array(
                                'timeout' => 30,
                                'headers' => array(
                                        'Authorization' => 'Bearer ' . $api_key,
                                        'Content-Type'  => 'application/json',
                                        'Accept'        => 'application/json',
                                        // Intestazioni di attribuzione coerenti con Nexus_AI_Engine.
                                        'HTTP-Referer'  => home_url( '/' ),
                                        'X-Title'       => 'Nexus News AI',
                                ),
                                'body'    => $encoded_body,
                        )
                );

                if ( is_wp_error( $response ) ) {
                        return 'errore di connessione al servizio immagini: ' . $response->get_error_message();
                }

                $code = (int) wp_remote_retrieve_response_code( $response );
                $raw  = (string) wp_remote_retrieve_body( $response );

                if ( 200 !== $code ) {
                        return 'il servizio immagini ha risposto con errore HTTP ' . $code;
                }

                $data = json_decode( $raw, true );
                if ( ! is_array( $data ) || empty( $data['data'][0] ) || ! is_array( $data['data'][0] ) ) {
                        return 'risposta del servizio immagini non valida';
                }

                $item   = $data['data'][0];
                $binary = '';

                // Caso 1: immagine inline in base64 (es. gpt-image-1).
                if ( ! empty( $item['b64_json'] ) ) {
                        $binary = base64_decode( (string) $item['b64_json'] );
                        if ( false === $binary || '' === $binary ) {
                                return 'decodifica base64 dell\'immagine generata fallita';
                        }
                } elseif ( ! empty( $item['url'] ) ) {
                        // Caso 2: URL temporaneo da scaricare (es. DALL·E).
                        $downloaded = self::download_image( (string) $item['url'], 30 );
                        if ( ! is_array( $downloaded ) ) {
                                return 'download immagine generata fallito: ' . (string) $downloaded;
                        }
                        $binary = $downloaded['binary'];
                } else {
                        return 'il servizio immagini non ha restituito né b64_json né url';
                }

                $ext = self::detect_image_ext( $binary );
                if ( '' === $ext ) {
                        return 'l\'immagine generata non è in un formato riconosciuto';
                }

                return array(
                        'binary' => $binary,
                        'ext'    => $ext,
                        'model'  => $model,
                );
        }

        /**
         * Prompt inglese breve per la generazione, basato su titolo e categoria.
         *
         * @param string $title    Titolo pulito.
         * @param string $category Categoria pulita.
         * @return string
         */
        private static function build_prompt( $title, $category ) {
                $prompt = 'Editorial header illustration about ' . self::clip( $title, 120 ) . '.';
                if ( '' !== $category ) {
                        $prompt .= ' Topic: ' . self::clip( $category, 40 ) . '.';
                }
                $prompt .= ' Style: clean, modern news illustration, no text.';
                return $prompt;
        }

        /* ============ ② DOWNLOAD DA URL ============ */

        /**
         * Scarica un'immagine da URL e ritorna il binario con l'estensione rilevata.
         *
         * @param string $url     URL dell'immagine (viene sanificato con esc_url_raw).
         * @param int    $timeout Timeout in secondi.
         * @return array|string Array( 'binary', 'ext' ) in successo, stringa di errore italiano altrimenti.
         */
        private static function download_image( $url, $timeout = 20 ) {
                $url = esc_url_raw( trim( (string) $url ) );
                if ( '' === $url ) {
                        return 'URL immagine vuoto o non valido';
                }

                $response = wp_remote_get(
                        $url,
                        array(
                                'timeout' => max( 1, (int) $timeout ),
                        )
                );

                if ( is_wp_error( $response ) ) {
                        return 'errore di download: ' . $response->get_error_message();
                }

                $code = (int) wp_remote_retrieve_response_code( $response );
                $body = (string) wp_remote_retrieve_body( $response );

                if ( 200 !== $code ) {
                        return 'download con errore HTTP ' . $code;
                }
                if ( '' === $body ) {
                        return 'download: contenuto vuoto';
                }

                $ext = self::detect_image_ext( $body );
                if ( '' === $ext ) {
                        return 'il file scaricato non è un\'immagine riconosciuta';
                }

                return array(
                        'binary' => $body,
                        'ext'    => $ext,
                );
        }

        /* ============ ③ PLACEHOLDER LOCALE ============ */

        /**
         * Genera il placeholder locale: PNG con GD se disponibile, altrimenti SVG minimale.
         *
         * @param string $title    Titolo pulito (per le iniziali).
         * @param string $category Categoria (per i colori del gradiente).
         * @return array Array( 'binary' => string, 'ext' => 'png'|'svg', 'desc' => string ).
         */
        private static function build_placeholder( $title, $category ) {
                $initials = self::title_initials( $title );
                $colors   = self::category_colors( $category );

                $rgb1 = $colors[0];
                $rgb2 = $colors[1];
                $hex1 = sprintf( '%02x%02x%02x', $rgb1[0], $rgb1[1], $rgb1[2] );
                $hex2 = sprintf( '%02x%02x%02x', $rgb2[0], $rgb2[1], $rgb2[2] );

                // Placeholder PNG via GD (preferito).
                if ( function_exists( 'imagecreatetruecolor' ) ) {
                        $png = self::build_png_placeholder( $initials, $rgb1, $rgb2 );
                        if ( is_array( $png ) && '' !== $png['binary'] ) {
                                return array(
                                        'binary' => $png['binary'],
                                        'ext'    => 'png',
                                        'desc'   => 'placeholder locale GD',
                                );
                        }
                }

                // Ultima spiaggia: SVG minimale (gradiente + testo iniziali).
                return array(
                        'binary' => self::build_svg_placeholder( $initials, $hex1, $hex2 ),
                        'ext'    => 'svg',
                        'desc'   => 'placeholder SVG locale',
                );
        }

        /**
         * PNG 1200x675 con gradiente orizzontale e iniziali del titolo (font builtin GD).
         *
         * @param string $initials Iniziali (massimo 2 caratteri, già sanificate).
         * @param array  $rgb1     Colore iniziale (r,g,b).
         * @param array  $rgb2     Colore finale (r,g,b).
         * @return array Array( 'binary' => string ) oppure array( 'binary' => '' ) su errore.
         */
        private static function build_png_placeholder( $initials, $rgb1, $rgb2 ) {
                $width  = 1200;
                $height = 675;

                $img = @imagecreatetruecolor( $width, $height );
                if ( ! $img ) {
                        return array( 'binary' => '' );
                }

                // Gradiente orizzontale: una riga verticale per colonna con colore interpolato.
                $ok = true;
                for ( $x = 0; $x < $width; $x++ ) {
                        $t   = $width > 1 ? ( $x / ( $width - 1 ) ) : 0;
                        $col = @imagecolorallocate(
                                $img,
                                (int) round( $rgb1[0] + ( $rgb2[0] - $rgb1[0] ) * $t ),
                                (int) round( $rgb1[1] + ( $rgb2[1] - $rgb1[1] ) * $t ),
                                (int) round( $rgb1[2] + ( $rgb2[2] - $rgb1[2] ) * $t )
                        );
                        if ( false === $col || false === @imageline( $img, $x, 0, $x, $height - 1, $col ) ) {
                                $ok = false;
                                break;
                        }
                }

                if ( $ok ) {
                        // Iniziali centrate con leggera ombra, font builtin (nessun file esterno).
                        $font    = 5;
                        $char_w  = (int) imagefontwidth( $font );
                        $char_h  = (int) imagefontheight( $font );
                        $text_w  = strlen( $initials ) * $char_w;
                        $text_x  = (int) ( ( $width - $text_w ) / 2 );
                        $text_y  = (int) ( ( $height - $char_h ) / 2 );

                        $shadow = @imagecolorallocate( $img, 0, 0, 0 );
                        $white  = @imagecolorallocate( $img, 255, 255, 255 );

                        if ( false !== $shadow && false !== $white ) {
                                @imagestring( $img, $font, $text_x + 2, $text_y + 2, $initials, $shadow );
                                @imagestring( $img, $font, $text_x, $text_y, $initials, $white );
                        }
                }

                // Esporta il PNG in un buffer di output.
                $binary = '';
                if ( $ok && ob_start() ) {
                        $exported = @imagepng( $img, null, 6 );
                        $buffer   = ob_get_clean();
                        if ( $exported && is_string( $buffer ) && '' !== $buffer ) {
                                $binary = $buffer;
                        }
                }

                @imagedestroy( $img );

                return array( 'binary' => $binary );
        }

        /**
         * SVG minimale 1200x675 (gradiente + iniziali), usato solo senza GD.
         *
         * @param string $initials Iniziali sanificate (solo A-Z 0-9).
         * @param string $hex1     Colore iniziale (hex senza #).
         * @param string $hex2     Colore finale (hex senza #).
         * @return string
         */
        private static function build_svg_placeholder( $initials, $hex1, $hex2 ) {
                // Solo caratteri sicuri per XML/testo.
                $safe = preg_replace( '/[^A-Za-z0-9]/', '', (string) $initials );
                if ( '' === $safe || ! is_string( $safe ) ) {
                        $safe = 'NX';
                }

                $svg = '<?xml version="1.0" encoding="UTF-8"?>'
                        . '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="675" viewBox="0 0 1200 675">'
                        . '<defs>'
                        . '<linearGradient id="nexusGrad" x1="0" y1="0" x2="1" y2="0">'
                        . '<stop offset="0%" stop-color="#' . $hex1 . '"/>'
                        . '<stop offset="100%" stop-color="#' . $hex2 . '"/>'
                        . '</linearGradient>'
                        . '</defs>'
                        . '<rect width="1200" height="675" fill="url(#nexusGrad)"/>'
                        . '<text x="600" y="338" fill="#ffffff" font-family="Helvetica, Arial, sans-serif"'
                        . ' font-size="180" font-weight="700" text-anchor="middle" dominant-baseline="central">'
                        . $safe . '</text>'
                        . '</svg>';

                return (string) $svg;
        }

        /**
         * Due colori (gradiente) derivati dall'hash della categoria.
         *
         * @param string $category Categoria dell'articolo.
         * @return array Array di due triple RGB: array( array(r,g,b), array(r,g,b) ).
         */
        private static function category_colors( $category ) {
                $seed_str = '' !== (string) $category ? strtolower( (string) $category ) : 'nexus';
                $seed     = abs( (int) crc32( $seed_str ) );

                $h1 = $seed % 360;
                $h2 = ( $h1 + 45 ) % 360;

                return array(
                        self::hsl_to_rgb( $h1, 0.55, 0.38 ),
                        self::hsl_to_rgb( $h2, 0.50, 0.24 ),
                );
        }

        /**
         * Conversione HSL → RGB (h in gradi 0-359, s e l in 0-1).
         *
         * @param int   $h Grado hue.
         * @param float $s Saturation.
         * @param float $l Lightness.
         * @return array Array( r, g, b ) con valori 0-255.
         */
        private static function hsl_to_rgb( $h, $s, $l ) {
                $h = (int) $h;
                $c = ( 1 - abs( 2 * (float) $l - 1 ) ) * (float) $s;
                $x = $c * ( 1 - abs( fmod( $h / 60, 2 ) - 1 ) );
                $m = (float) $l - $c / 2;

                if ( $h < 60 ) {
                        $rgb = array( $c, $x, 0.0 );
                } elseif ( $h < 120 ) {
                        $rgb = array( $x, $c, 0.0 );
                } elseif ( $h < 180 ) {
                        $rgb = array( 0.0, $c, $x );
                } elseif ( $h < 240 ) {
                        $rgb = array( 0.0, $x, $c );
                } elseif ( $h < 300 ) {
                        $rgb = array( $x, 0.0, $c );
                } else {
                        $rgb = array( $c, 0.0, $x );
                }

                return array(
                        (int) round( ( $rgb[0] + $m ) * 255 ),
                        (int) round( ( $rgb[1] + $m ) * 255 ),
                        (int) round( ( $rgb[2] + $m ) * 255 ),
                );
        }

        /**
         * Iniziali del titolo (massimo 2): prima lettera delle prime due parole utili.
         *
         * @param string $title Titolo pulito.
         * @return string Due caratteri (o 'NX' se non derivabili).
         */
        private static function title_initials( $title ) {
                // remove_accents (core WP) rende usabili le lettere accentate (È → E).
                $title = function_exists( 'remove_accents' ) ? remove_accents( (string) $title ) : (string) $title;
                $title = strtoupper( $title );

                $words    = preg_split( '/\s+/', $title, -1, PREG_SPLIT_NO_EMPTY );
                $initials = '';

                if ( is_array( $words ) ) {
                        foreach ( $words as $word ) {
                                $first = substr( $word, 0, 1 );
                                if ( preg_match( '/[A-Z0-9]/', $first ) ) {
                                        $initials .= $first;
                                        if ( strlen( $initials ) >= 2 ) {
                                                break;
                                        }
                                }
                        }
                }

                return '' !== $initials ? $initials : 'NX';
        }

        /* ============ SALVATAGGIO E ATTACHMENT ============ */

        /**
         * Salva il binario negli upload e crea l'attachment WP (senza thumbnail set).
         *
         * Usa wp_upload_bits('nexus-<article_id>-<timestamp>.<ext>'); se la scrittura
         * viene rifiutata (es. estensione non in whitelist mime come SVG), ripiega su
         * una scrittura manuale nella cartella uploads. Metadata generati se disponibili.
         *
         * @param string $binary     Contenuto binario dell'immagine.
         * @param string $ext        Estensione rilevata (png|jpg|gif|webp|svg).
         * @param int    $article_id ID articolo Nexus (per il nome file).
         * @param string $title      Titolo (usato per titolo attachment e alt text).
         * @param int    $post_id    ID post WP a cui collegare l'attachment.
         * @return int ID attachment, 0 su errore.
         */
        private static function save_and_attach( $binary, $ext, $article_id, $title, $post_id ) {
                if ( '' === (string) $binary || '' === (string) $ext ) {
                        return 0;
                }

                // wp_upload_bits vive in wp-admin/includes/file.php: includi dove serve.
                if ( ! function_exists( 'wp_upload_bits' ) ) {
                        require_once ABSPATH . 'wp-admin/includes/file.php';
                }

                $filename = sanitize_file_name( 'nexus-' . max( 0, (int) $article_id ) . '-' . time() . '.' . $ext );
                $ext      = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $ext ) );

                $mime = self::mime_for_ext( $ext );
                if ( '' === $mime ) {
                        return 0;
                }

                $file_path = '';
                $file_url  = '';

                $upload = wp_upload_bits( $filename, null, (string) $binary );
                if ( is_array( $upload ) && empty( $upload['error'] ) && ! empty( $upload['file'] ) ) {
                        $file_path = (string) $upload['file'];
                        $file_url  = isset( $upload['url'] ) ? (string) $upload['url'] : '';
                } else {
                        // Ripiego manuale (es. SVG rifiutato dalla whitelist mime): scrittura diretta in uploads.
                        $uploads = wp_upload_dir();
                        if ( ! is_array( $uploads ) || ! empty( $uploads['error'] ) || empty( $uploads['path'] ) ) {
                                return 0;
                        }
                        $unique    = wp_unique_filename( $uploads['path'], $filename );
                        $file_path = trailingslashit( $uploads['path'] ) . $unique;
                        $written   = @file_put_contents( $file_path, (string) $binary );
                        if ( false === $written || ! file_exists( $file_path ) ) {
                                return 0;
                        }
                        $file_url = trailingslashit( $uploads['url'] ) . $unique;
                }

                $attachment = array(
                        'post_mime_type' => $mime,
                        'post_title'     => self::clean_text( $title ),
                        'post_content'   => '',
                        'post_status'    => 'inherit',
                );

                $attach_id = wp_insert_attachment( $attachment, $file_path, $post_id );
                if ( ! $attach_id || is_wp_error( $attach_id ) ) {
                        @unlink( $file_path );
                        return 0;
                }
                $attach_id = (int) $attach_id;

                // Metadati immagine (richiede image.php): senza GD genera meta parziali, non bloccante.
                if ( function_exists( 'wp_generate_attachment_metadata' ) ) {
                        require_once ABSPATH . 'wp-admin/includes/image.php';
                        $meta = wp_generate_attachment_metadata( $attach_id, $file_path );
                        if ( $meta ) {
                                wp_update_attachment_metadata( $attach_id, $meta );
                        }
                }

                // Testo alternativo per accessibilità.
                $alt = self::clean_text( $title );
                if ( '' !== $alt ) {
                        update_post_meta( $attach_id, '_wp_attachment_image_alt', $alt );
                }

                return $attach_id;
        }

        /* ============ HELPER ============ */

        /**
         * Legge un campo dall'articolo normalizzato ad array.
         *
         * @param array  $article Articolo come array.
         * @param string $key     Chiave richiesta.
         * @return mixed
         */
        private static function pick( $article, $key ) {
                if ( is_array( $article ) && array_key_exists( $key, $article ) ) {
                        return $article[ $key ];
                }
                return '';
        }

        /**
         * Pulizia testo: rimozione tag e spazi compatti.
         *
         * @param mixed $value Valore grezzo.
         * @return string
         */
        private static function clean_text( $value ) {
                $value = wp_strip_all_tags( (string) $value );
                $value = preg_replace( '/\s+/u', ' ', $value );
                return trim( (string) $value );
        }

        /**
         * Troncamento sicuro (multibyte se disponibile) con ellissi.
         *
         * @param string $text Testo.
         * @param int    $max Lunghezza massima.
         * @return string
         */
        private static function clip( $text, $max ) {
                $text = (string) $text;
                $max  = max( 1, (int) $max );

                if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
                        if ( mb_strlen( $text ) > $max ) {
                                return mb_substr( $text, 0, $max - 1 ) . '…';
                        }
                        return $text;
                }

                if ( strlen( $text ) > $max ) {
                        return substr( $text, 0, $max - 1 ) . '…';
                }
                return $text;
        }

        /**
         * Rileva l'estensione dell'immagine dalla firma binaria (o XML per SVG).
         *
         * @param string $binary Contenuto del file.
         * @return string Estensione ('png'|'jpg'|'gif'|'webp'|'svg') o '' se non riconosciuta.
         */
        private static function detect_image_ext( $binary ) {
                $binary = (string) $binary;
                if ( '' === $binary ) {
                        return '';
                }

                if ( 0 === strpos( $binary, "\x89PNG\x0D\x0A\x1A\x0A" ) ) {
                        return 'png';
                }
                if ( 0 === strpos( $binary, "\xFF\xD8\xFF" ) ) {
                        return 'jpg';
                }
                if ( 0 === strpos( $binary, 'GIF87a' ) || 0 === strpos( $binary, 'GIF89a' ) ) {
                        return 'gif';
                }
                if ( 0 === strpos( $binary, 'RIFF' ) && substr( $binary, 8, 4 ) === 'WEBP' ) {
                        return 'webp';
                }

                $trimmed = ltrim( $binary, " \t\r\n\0\x0B" );
                if ( 0 === strpos( $trimmed, '<?xml' ) || 0 === stripos( $trimmed, '<svg' ) ) {
                        return 'svg';
                }

                return '';
        }

        /**
         * Mime type per estensione (solo estensioni gestite dalla classe).
         *
         * @param string $ext Estensione.
         * @return string Mime type o '' se non gestita.
         */
        private static function mime_for_ext( $ext ) {
                $map = array(
                        'png'  => 'image/png',
                        'jpg'  => 'image/jpeg',
                        'jpeg' => 'image/jpeg',
                        'gif'  => 'image/gif',
                        'webp' => 'image/webp',
                        'svg'  => 'image/svg+xml',
                );

                return isset( $map[ $ext ] ) ? $map[ $ext ] : '';
        }

        /**
         * Scrive un'attività nel log plugin (guard completa: classe e metodo).
         *
         * @param string $detail Descrizione risultato in italiano.
         * @param string $status 'success' oppure 'error'.
         * @return void
         */
        private static function log_activity( $detail, $status ) {
                if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                        Nexus_DB::insert_activity(
                                0,
                                'image',
                                (string) $detail,
                                'success' === $status ? 'success' : 'error'
                        );
                }
        }
}
