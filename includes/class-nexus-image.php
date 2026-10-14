<?php
/**
 * Nexus Image — featured image per gli articoli pubblicati come post WordPress.
 *
 * Catena con stop al primo successo (mai bloccante, mai eccezioni propagate):
 * ① GENERAZIONE AI — se settings `enable_ai_images` = 1: POST {ai_base_url}/images/generations
 *    con Authorization Bearer {ai_api_key}; accetta sia data[0].b64_json (decodifica)
 *    sia data[0].url (download con wp_remote_get). Timeout 30s per richiesta.
 * ② FALLBACK image_url — se l'articolo ha image_url non vuota, download (timeout 20s).
 * ③ FALLBACK Pixabay (v1.1.10) — SOLO se AI disabilitata/fallita E nessun image_url
 *    utilizzabile: ricerca foto (GET https://pixabay.com/api/, image_type=photo,
 *    per_page=10, safesearch=true) con query dalle KEYWORD dell'articolo (titolo:
 *    prime 3-4 parole significative con stopwords IT/EN rimosse; categoria come
 *    fallback). Dedup: transient 'nexus_pixabay_seen' (id già usati, cap 500,
 *    scadenza 30 giorni) — si sceglie il primo risultato NON già usato e lo si
 *    marca usato. Cache risposta per-query 24h ('nexus_pixabay_{md5(query)}').
 *    Gate qualità: scartati gli hit con imageWidth < 640px, preferita
 *    largeImageURL (~960px) a previewURL (mai usata); il download passa SEMPRE
 *    da download_image (stesse guardie SSRF/SVG/MIME degli altri download remoti).
 *    Chiave mancante (settings `pixabay_key`) → log 'pixabay_skip' UNA volta
 *    (non spam) e ritorno alla catena. Risposta API non valida/zero risultati →
 *    log onesto e ritorno alla catena senza rompere il flusso.
 * ④ FALLBACK placeholder locale — con GD: PNG 1200x675 con gradiente derivato dall'hash
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
 * Sicurezza (v1.1.9, WP2):
 * - SSRF guard sul download remoto (guard_url): http/https + wp_http_validate_url +
 *   risoluzione DNS + blocco IP privati/riservati (logica duplicata da Nexus_Tools:
 *   db.php non deve essere toccato e la classe immagine resta autonoma).
 * - 'redirection' => 0 (v1.1.15 WC2: NESSUN redirect — il target di un
 *   rimbalzo bypasserebbe la guard SSRF; degrado onesto su 3xx),
 *   'limit_response_size' => 5MB e timeout espliciti sul download.
 * - SVG REMOTI RIFIUTATI (content-type o sniffing): vettore di stored XSS.
 * - MIME reale del corpo scaricato validato (getimagesizefromstring) prima del salvataggio.
 * - FALLBACK file_put_contents RIMOSSO (v1.1.9): scrivere in uploads bypassando
 *   la whitelist mime di wp_upload_bits poteva depositare XML/SVG eseguibile;
 *   se wp_upload_bits rifiuta, la featured image non viene impostata (log onesto).
 * - v1.1.10 (WP6): il download dell'immagine Pixabay PASSA dallo stesso
 *   download_image (SSRF guard + SVG ban + MIME reale + limit_response_size):
 *   nessuna via parallela. Attribuzione: Pixabay non la richiede e NON viene
 *   inventata — alt text = titolo dell'articolo (comportamento esistente di
 *   save_and_attach). Gate qualità: min-width 640px su TUTTE le immagini
 *   candidate remote (image_url e Pixabay; generazione AI esente); il nome
 *   file è sanificato da sanitize_file_name + wp_upload_bits (verificato).
 *
 * wiring: l'integratore farà require in nexus-news-ai.php
 * (require_once NEXUS_PLUGIN_DIR . 'includes/class-nexus-image.php';).
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Image {

        /**
         * Dimensione massima del download di un'immagine remota (5 MB).
         */
        const MAX_DOWNLOAD_BYTES = 5242880; // 5 MB (5 * 1024 * 1024).

        /** CIDR IPv4 bloccati dall'SSRF guard (stessa lista di Nexus_Tools). */
        const BANNED_IPV4_CIDR = '0.0.0.0/8 10.0.0.0/8 127.0.0.0/8 169.254.0.0/16 172.16.0.0/12 192.0.0.0/24 192.168.0.0/16 100.64.0.0/10 198.18.0.0/15 224.0.0.0/4 240.0.0.0/4';

        /** Gate qualità (v1.1.10): larghezza minima (px) per le immagini candidate remote. */
        const MIN_IMAGE_WIDTH = 640;

        /** Dedup Pixabay: cap di ID ricordati (transient 'nexus_pixabay_seen'). */
        const PIXABAY_SEEN_CAP = 500;

        /** Dedup Pixabay: scadenza del transient (30 giorni), rinnovata a ogni marcatura. */
        const PIXABAY_SEEN_TTL = 2592000; // 30 * 24 * 3600.

        /** Cache della risposta API Pixabay per singola query (24 ore). */
        const PIXABAY_QUERY_TTL = 86400; // 24 h.

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

                        $binary          = '';
                        $ext             = '';
                        $source_desc     = '';
                        $notes           = array();
                        // featured_source (v1.1.10): anello che ha fornito l'immagine —
                        // 'ai' | 'source' | 'pixabay' | 'none' (placeholder o fallimento).
                        $featured_source = 'none';

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
                                                $binary          = $generated['binary'];
                                                $ext             = $generated['ext'];
                                                $source_desc     = 'generazione AI (modello ' . $generated['model'] . ')';
                                                $featured_source = 'ai';
                                        } else {
                                                $notes[] = 'generazione AI fallita (' . (string) $generated . ')';
                                        }
                                }
                        }

                        // ② FALLBACK image_url dell'articolo (gate qualità: larghezza minima v1.1.10).
                        if ( '' === $binary && '' !== $image_url ) {
                                $downloaded = self::download_image( $image_url, 20 );
                                if ( is_array( $downloaded ) ) {
                                        // Gate qualità v1.1.10 (AI esente): larghezza REALE del file scaricato.
                                        // Se la larghezza non è determinabile (funzione assente) il gate non blocca
                                        // (fail-open dichiarato): il MIME resta comunque validato da download_image.
                                        $width = self::binary_image_width( $downloaded['binary'] );
                                        if ( $width > 0 && $width < self::MIN_IMAGE_WIDTH ) {
                                                $notes[] = 'image_url scartata dal gate qualità: larghezza ' . $width . 'px sotto il minimo di ' . self::MIN_IMAGE_WIDTH . 'px';
                                        } else {
                                                $binary          = $downloaded['binary'];
                                                $ext             = $downloaded['ext'];
                                                $source_desc     = 'immagine dall\'articolo (image_url)';
                                                $featured_source = 'source';
                                        }
                                } else {
                                        $notes[] = 'image_url non utilizzabile (' . (string) $downloaded . ')';
                                }
                        }

                        // ③ FALLBACK Pixabay (v1.1.10) — scatta SOLO se AI è disabilitata/fallita
                        // E nessuna image_url utilizzabile (a questo punto '' === $binary).
                        if ( '' === $binary ) {
                                $pixabay = self::fetch_pixabay_image( $title, $category, $settings );
                                if ( is_array( $pixabay ) ) {
                                        // Download SEMPRE via download_image: SSRF guard + SVG ban + MIME
                                        // reale + limit_response_size (le guardie v1.1.9 non vengono aggirate).
                                        $downloaded = self::download_image( $pixabay['url'], 20 );
                                        if ( is_array( $downloaded ) ) {
                                                // Secondo gate sulla larghezza REALE del file scaricato.
                                                $width = self::binary_image_width( $downloaded['binary'] );
                                                if ( $width > 0 && $width < self::MIN_IMAGE_WIDTH ) {
                                                        $notes[] = 'pixabay scartata dal gate qualità: larghezza reale ' . $width . 'px sotto il minimo di ' . self::MIN_IMAGE_WIDTH . 'px';
                                                } else {
                                                        $binary          = $downloaded['binary'];
                                                        $ext             = $downloaded['ext'];
                                                        $source_desc     = 'immagine Pixabay (query "' . $pixabay['query'] . '")';
                                                        $featured_source = 'pixabay';
                                                }
                                        } else {
                                                $notes[] = 'pixabay non utilizzabile (' . (string) $downloaded . ')';
                                        }
                                } elseif ( '' !== (string) $pixabay ) {
                                        // Errore onesto della ricerca (chiave mancante → '' già loggato una volta sola).
                                        $notes[] = (string) $pixabay;
                                }
                        }

                        // ④ FALLBACK placeholder locale (PNG con GD, altrimenti SVG).
                        if ( '' === $binary ) {
                                $placeholder = self::build_placeholder( $title, $category );
                                $binary      = $placeholder['binary'];
                                $ext         = $placeholder['ext'];
                                $source_desc = $placeholder['desc'];
                        }

                        // SALVATAGGIO: upload + attachment + metadata.
                        $attach_id = self::save_and_attach( $binary, $ext, $article_id, $title, $post_id );
                        if ( $attach_id <= 0 ) {
                                self::log_activity( 'Impossibile salvare la featured image per il post #' . $post_id . ' (upload o attachment falliti; featured_source: ' . $featured_source . ').', 'error' );
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
                        self::log_activity( 'Featured image impostata sul post #' . $post_id . ' (origine: ' . $source_desc . '; featured_source: ' . $featured_source . ').' . $suffix, 'success' );

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
         * Guardie (v1.1.9 + v1.1.15 WC2): SSRF (guard_url: schema+host validati,
         * DNS risolto fuori dagli IP privati/riservati), 'redirection' => 0
         * (NESSUN redirect: il target di un rimbalzo non passerebbe dalla SSRF
         * guard — vettore blind SSRF verso IP interni; un host che risponde
         * 3xx degrada in modo onesto), timeout esplicito, 'limit_response_size'
         * 5MB, SVG remoti rifiutati (content-type o sniffing, vettore di stored
         * XSS) e MIME reale del corpo validato con getimagesizefromstring PRIMA
         * di consegnare il binario al chiamante.
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

                // SSRF guard (v1.1.9): l'URL (dall'articolo o dal provider AI) non deve
                // puntare a servizi interni dell'host.
                $guard = self::guard_url( $url );
                if ( '' !== $guard ) {
                        return $guard;
                }

                $response = wp_remote_get(
                        $url,
                        array(
                                'timeout'             => max( 1, (int) $timeout ),
                                // v1.1.15 (WC2): NESSUN redirect. Con 'redirection' => 1 il
                                // target del rimbalzo NON passava da guard_url: un 302 verso
                                // 169.254.169.254 o un IP interno era un SSRF "blind" raggiungibile
                                // da qualsiasi fonte che controlli image_url. L'immagine di
                                // destinazione deve rispondere direttamente (comportamento di
                                // Nexus_Tools::http_get, che usa 'redirection' => 0); un host che
                                // redirige degrada in modo onesto: errore HTTP 3xx → nota nella
                                // catena → placeholder. La SSRF guard valida SOLO l'URL richiesto.
                                'redirection'         => 0,
                                'limit_response_size' => self::MAX_DOWNLOAD_BYTES,
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

                // SVG BAN (v1.1.9): content-type dichiarato o sniffing del contenuto
                // (<?xml / <svg): un SVG in uploads è un vettore di stored XSS.
                $content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
                if ( false !== strpos( $content_type, 'image/svg' ) ) {
                        return 'il file remoto è un SVG: formato non ammesso per le immagini degli articoli';
                }

                $ext = self::detect_image_ext( $body );
                if ( 'svg' === $ext ) {
                        return 'il file remoto è un SVG: formato non ammesso per le immagini degli articoli';
                }
                if ( '' === $ext ) {
                        return 'il file scaricato non è un\'immagine riconosciuta';
                }

                // Validazione del MIME REALE del corpo scaricato (firma strutturale,
                // non il content-type dichiarato): blocca file camaleonte che si
                // presentano come PNG ma non sono immagini valide.
                $mime = self::real_image_mime( $body );
                if ( '' === $mime ) {
                        return 'il file scaricato non è un\'immagine valida';
                }
                if ( false !== strpos( $mime, 'svg' ) ) {
                        return 'il file remoto è un SVG: formato non ammesso per le immagini degli articoli';
                }

                return array(
                        'binary' => $body,
                        'ext'    => $ext,
                );
        }

        /* ============ SSRF GUARD E MIME (v1.1.9) ============ */

        /**
         * Guardia SSRF per il download remoto: ritorna '' se l'URL è sicuro,
         * altrimenti un messaggio di errore italiano ('URL non consentito…').
         *
         * Logica duplicata da Nexus_Tools::assert_safe_url (la classe immagine resta
         * autonoma e db.php non viene toccato): http/https + wp_http_validate_url +
         * risoluzione DNS + blocco IP privati/riservati.
         *
         * @param string $url URL da validare.
         * @return string '' se sicuro, messaggio di errore altrimenti.
         */
        private static function guard_url( $url ) {
                $url = trim( (string) $url );
                if ( '' === $url || ! function_exists( 'wp_http_validate_url' ) ) {
                        return 'URL non consentito: sono ammessi solo URL http/https con host valido.';
                }

                $validated = wp_http_validate_url( $url );
                if ( ! $validated ) {
                        return 'URL non consentito: sono ammessi solo URL http/https con host valido.';
                }

                $host = (string) ( function_exists( 'wp_parse_url' ) ? wp_parse_url( $validated, PHP_URL_HOST ) : parse_url( $validated, PHP_URL_HOST ) );
                if ( '' === $host ) {
                        return 'URL non consentito: host mancante.';
                }

                $ips = self::resolve_host_ips( $host );
                if ( empty( $ips ) ) {
                        return 'URL non consentito: impossibile risolvere il nome host.';
                }

                foreach ( $ips as $ip ) {
                        if ( self::is_banned_ip( $ip ) ) {
                                // Messaggio generico di proposito: non rivelare la topologia interna.
                                return 'URL non consentito: l\'host risolve a un indirizzo privato o riservato.';
                        }
                }

                return '';
        }

        /**
         * Risolve un host in una lista di IP (A e AAAA; fallback gethostbyname).
         *
         * @param string $host Hostname o IP.
         * @return array Lista di IP (vuota se non risolvibile).
         */
        private static function resolve_host_ips( $host ) {
                $host = trim( (string) $host );
                if ( '' === $host ) {
                        return array();
                }

                $bare = trim( $host, '[]' );
                if ( filter_var( $bare, FILTER_VALIDATE_IP ) ) {
                        return array( $bare );
                }

                $ips = array();

                if ( function_exists( 'dns_get_record' ) ) {
                        // @: il DNS failure genera warning, qui è un percorso di errore atteso.
                        $a = @dns_get_record( $host, DNS_A );
                        if ( is_array( $a ) ) {
                                foreach ( $a as $row ) {
                                        if ( ! empty( $row['ip'] ) && filter_var( $row['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
                                                $ips[] = (string) $row['ip'];
                                        }
                                }
                        }
                        $aaaa = @dns_get_record( $host, DNS_AAAA );
                        if ( is_array( $aaaa ) ) {
                                foreach ( $aaaa as $row ) {
                                        if ( ! empty( $row['ipv6'] ) && filter_var( $row['ipv6'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
                                                $ips[] = (string) $row['ipv6'];
                                        }
                                }
                        }
                }

                if ( empty( $ips ) ) {
                        $resolved = @gethostbyname( $host );
                        if ( is_string( $resolved ) && '' !== $resolved && $resolved !== $host && filter_var( $resolved, FILTER_VALIDATE_IP ) ) {
                                $ips[] = $resolved;
                        }
                }

                return array_values( array_unique( $ips ) );
        }

        /**
         * True se l'IP rientra negli intervalli privati/riservati bloccati.
         * IPv4 sui CIDR in BANNED_IPV4_CIDR; IPv6: ::, ::1, fc00::/7, fe80::/10,
         * ff00::/8 e IPv4-mapped ricontrollati come IPv4. Fail-closed su IP malformati.
         *
         * @param string $ip Indirizzo IP.
         * @return bool
         */
        private static function is_banned_ip( $ip ) {
                $ip = trim( (string) $ip );
                if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                        return true;
                }

                $is_v6 = ( false !== strpos( $ip, ':' ) );

                if ( $is_v6 && preg_match( '/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m ) ) {
                        return self::is_banned_ipv4( $m[1] );
                }

                $packed = (string) @inet_pton( $ip );
                if ( '' === $packed || false === $packed ) {
                        return true; // fail-closed.
                }

                if ( ! $is_v6 ) {
                        return self::is_banned_ipv4( $ip );
                }

                // IPv6: unspecified (::) e loopback (::1) — confronto sui 16 byte binari.
                if ( $packed === str_repeat( "\x00", 16 ) || $packed === str_repeat( "\x00", 15 ) . "\x01" ) {
                        return true;
                }

                foreach ( array( 'fc00::/7', 'fe80::/10', 'ff00::/8' ) as $cidr ) {
                        if ( self::cidr_match( $packed, $cidr ) ) {
                                return true;
                        }
                }

                return false;
        }

        /**
         * Blocco IPv4 sui CIDR dichiarati in BANNED_IPV4_CIDR.
         *
         * @param string $ip Indirizzo IPv4.
         * @return bool
         */
        private static function is_banned_ipv4( $ip ) {
                $packed = (string) @inet_pton( $ip );
                if ( '' === $packed || false === $packed ) {
                        return true;
                }
                foreach ( preg_split( '/\s+/', self::BANNED_IPV4_CIDR, -1, PREG_SPLIT_NO_EMPTY ) as $cidr ) {
                        if ( self::cidr_match( $packed, $cidr ) ) {
                                return true;
                        }
                }
                return false;
        }

        /**
         * Confronto binario IP (inet_pton) contro una notazione CIDR.
         *
         * @param string $packed IP in forma binaria.
         * @param string $cidr   Rete CIDR (es. 10.0.0.0/8).
         * @return bool
         */
        private static function cidr_match( $packed, $cidr ) {
                $parts = explode( '/', $cidr );
                if ( count( $parts ) !== 2 ) {
                        return false;
                }
                $net_packed = @inet_pton( $parts[0] );
                if ( false === $net_packed || '' === $net_packed || strlen( $net_packed ) !== strlen( $packed ) ) {
                        return false;
                }

                $bits       = (int) $parts[1];
                $total      = strlen( $packed ) * 8;
                $full_bytes = (int) floor( $bits / 8 );
                $rest_bits  = $bits % 8;

                if ( $full_bytes > 0 && substr( $packed, 0, $full_bytes ) !== substr( $net_packed, 0, $full_bytes ) ) {
                        return false;
                }
                if ( $rest_bits > 0 ) {
                        $mask = chr( ( 0xFF << ( 8 - $rest_bits ) ) & 0xFF );
                        if ( ( ord( $packed[ $full_bytes ] ) & ord( $mask ) ) !== ( ord( $net_packed[ $full_bytes ] ) & ord( $mask ) ) ) {
                                return false;
                        }
                }
                return true;
        }

        /**
         * MIME reale del corpo immagine: getimagesizefromstring (fallo su funzione
         * GD interna, valida la STRUTTURA del file) con fallback su finfo.
         *
         * @param string $binary Contenuto del file.
         * @return string MIME rilevato ('' se non riconosciuto come immagine).
         */
        private static function real_image_mime( $binary ) {
                $binary = (string) $binary;
                if ( '' === $binary ) {
                        return '';
                }

                // Preferenza: getimagesizefromstring valida la struttura dell'immagine.
                if ( function_exists( 'getimagesizefromstring' ) ) {
                        $info = @getimagesizefromstring( $binary );
                        if ( is_array( $info ) && ! empty( $info['mime'] ) ) {
                                return (string) $info['mime'];
                        }
                }

                // Fallback: finfo (libmagic) sul buffer.
                if ( function_exists( 'finfo_buffer' ) ) {
                        $finfo = @finfo_open( FILEINFO_MIME_TYPE );
                        if ( $finfo ) {
                                $mime = @finfo_buffer( $finfo, $binary );
                                @finfo_close( $finfo );
                                if ( is_string( $mime ) && '' !== $mime ) {
                                        return $mime;
                                }
                        }
                }

                return '';
        }

        /* ============ ③ FALLBACK PIXABAY E GATE QUALITÀ (v1.1.10) ============ */

        /**
         * Larghezza in pixel di un binario immagine (getimagesizefromstring).
         *
         * Gate qualità v1.1.10: verifica la larghezza REALE delle immagini candidate
         * scaricate (image_url e Pixabay; la generazione AI è esente). Ritorna 0 se
         * la larghezza non è determinabile (funzione assente o binario atipico): in
         * quel caso il gate NON blocca (fail-open dichiarato nel chiamante) — il
         * MIME resta comunque validato da download_image.
         *
         * @param string $binary Contenuto del file immagine.
         * @return int Larghezza in pixel, 0 se non determinabile.
         */
        private static function binary_image_width( $binary ) {
                $binary = (string) $binary;
                if ( '' === $binary || ! function_exists( 'getimagesizefromstring' ) ) {
                        return 0;
                }

                $info = @getimagesizefromstring( $binary );
                if ( is_array( $info ) && ! empty( $info[0] ) ) {
                        return (int) $info[0];
                }

                return 0;
        }

        /**
         * Anello ③: cerca una foto Pixabay idonea per la featured image.
         *
         * Scatta SOLO se AI è disabilitata/fallita e non c'è image_url utilizzabile
         * (il chiamante invoca questo metodo solo con '' === $binary).
         *
         * - Settings `pixabay_key` mancante → log 'pixabay_skip' UNA volta sola
         *   (opzione nexus_pixabay_key_missing, ri-armata al primo uso di una chiave
         *   valida) e ritorno '' (skip silenzioso nelle note per-articolo: niente spam).
         * - Query: prime 3-4 parole significative del titolo (stopwords IT/EN
         *   rimosse), fallback sulla categoria; URL-encoding via http_build_query.
         * - Cache risposta per-query 24h (transient 'nexus_pixabay_{md5(query)}'):
         *   viene cachata la lista di hit idonei, ANCHE se vuota (evita di martellare
         *   l'API); gli errori HTTP/connessione NON vengono cachati.
         * - Dedup: transient 'nexus_pixabay_seen' (map id => timestamp, cap 500,
         *   scadenza 30 giorni rinnovata a ogni uso): si sceglie il PRIMO risultato
         *   NON già usato e lo si marca usato SUBITO (anche se il download dovesse
         *   poi fallire: evita retry-loop sullo stesso ID rotto).
         * - Gate qualità: scartati gli hit con imageWidth < MIN_IMAGE_WIDTH (640px);
         *   URL preferito largeImageURL (~960px), fallback webformatURL solo se
         *   ≥ 640px dichiarati, MAI previewURL (troppo piccola).
         *
         * @param string $title    Titolo dell'articolo (testo pulito).
         * @param string $category Categoria dell'articolo (testo pulito).
         * @param array  $settings Impostazioni del plugin.
         * @return array Array( 'url' => string, 'query' => string ) in successo;
         *               stringa di errore onesta altrimenti ('' = skip già gestito).
         */
        private static function fetch_pixabay_image( $title, $category, $settings ) {
                $key = isset( $settings['pixabay_key'] ) ? trim( (string) $settings['pixabay_key'] ) : '';
                if ( '' === $key ) {
                        self::pixabay_skip_once( 'chiave Pixabay (setting pixabay_key) non configurata: anello ③ disattivato' );
                        return '';
                }

                // La chiave c'è: ri-armo l'eventuale log "una volta sola" dello skip.
                self::pixabay_rearm_skip();

                $query = self::pixabay_query( $title, $category );
                if ( '' === $query ) {
                        return 'pixabay: nessuna keyword utilizzabile (titolo e categoria vuoti o composti solo da stopwords)';
                }

                $hits = self::pixabay_search( $query, $key );
                if ( ! is_array( $hits ) ) {
                        // Errore API (connessione/HTTP/JSON): messaggio onesto, la catena prosegue.
                        return (string) $hits;
                }
                if ( empty( $hits ) ) {
                        return 'pixabay: zero risultati utilizzabili per la query "' . $query . '" (nessun esito o tutti sotto il gate di ' . self::MIN_IMAGE_WIDTH . 'px)';
                }

                // Dedup: primo hit idoneo NON ancora usato.
                $seen = self::pixabay_seen_ids();
                foreach ( $hits as $hit ) {
                        $id = isset( $hit['id'] ) ? (int) $hit['id'] : 0;
                        if ( $id <= 0 || empty( $hit['url'] ) ) {
                                continue;
                        }
                        if ( isset( $seen[ $id ] ) ) {
                                continue;
                        }
                        self::pixabay_mark_seen( $id );
                        return array(
                                'url'   => (string) $hit['url'],
                                'query' => $query,
                        );
                }

                return 'pixabay: tutti i risultati per la query "' . $query . '" sono già stati usati';
        }

        /**
         * Chiama l'API Pixabay e ritorna gli hit idonei (già filtrati dal gate).
         *
         * Endpoint: GET https://pixabay.com/api/?key={key}&q={query}&image_type=photo
         * &per_page=10&safesearch=true (URL-encoding completo via http_build_query).
         * L'endpoint passa per guard_url (stessa SSRF guard degli altri fetch remoti:
         * l'host è fisso ma il controllo resta identico per coerenza). La chiave API
         * non finisce mai nei messaggi di errore (generici by design).
         *
         * @param string $query Query di ricerca (parole separate da spazio).
         * @param string $key   Chiave API Pixabay.
         * @return array Lista di hit idonei (map 'id'/'url', anche vuota) oppure stringa di errore.
         */
        private static function pixabay_search( $query, $key ) {
                $cache_key = 'nexus_pixabay_' . md5( (string) $query );

                $cached = function_exists( 'get_transient' ) ? get_transient( $cache_key ) : false;
                if ( is_array( $cached ) ) {
                        return $cached;
                }

                $endpoint = 'https://pixabay.com/api/?' . http_build_query( array(
                        'key'        => $key,
                        'q'          => (string) $query,
                        'image_type' => 'photo',
                        'per_page'   => 10,
                        'safesearch' => 'true',
                ) );

                $guard = self::guard_url( $endpoint );
                if ( '' !== $guard ) {
                        return 'pixabay: ' . $guard;
                }

                $response = wp_remote_get( $endpoint, array(
                        'timeout'             => 15,
                        'redirection'         => 1,
                        'limit_response_size' => self::MAX_DOWNLOAD_BYTES,
                ) );

                if ( is_wp_error( $response ) ) {
                        return 'pixabay: errore di connessione all\'API (' . $response->get_error_message() . ')';
                }

                $code = (int) wp_remote_retrieve_response_code( $response );
                $raw  = (string) wp_remote_retrieve_body( $response );

                if ( 200 !== $code ) {
                        // 401 chiave invalida, 429 rate limit, ecc.: messaggio onesto, senza corpo.
                        return 'pixabay: API ha risposto con errore HTTP ' . $code;
                }

                $data = json_decode( $raw, true );
                if ( ! is_array( $data ) || ! isset( $data['hits'] ) || ! is_array( $data['hits'] ) ) {
                        return 'pixabay: risposta API non valida';
                }

                $hits = array();
                foreach ( $data['hits'] as $hit ) {
                        if ( ! is_array( $hit ) ) {
                                continue;
                        }

                        // Gate qualità: larghezza dell'originale dichiarata dall'API.
                        $width = isset( $hit['imageWidth'] ) ? (int) $hit['imageWidth'] : 0;
                        if ( $width < self::MIN_IMAGE_WIDTH ) {
                                continue;
                        }

                        // largeImageURL (~960px) preferita; fallback webformatURL solo se
                        // ≥ 640px dichiarati; previewURL MAI (troppo piccola).
                        $url = '';
                        if ( ! empty( $hit['largeImageURL'] ) ) {
                                $url = (string) $hit['largeImageURL'];
                        } elseif ( ! empty( $hit['webformatURL'] ) && ( isset( $hit['webformatWidth'] ) ? (int) $hit['webformatWidth'] : 0 ) >= self::MIN_IMAGE_WIDTH ) {
                                $url = (string) $hit['webformatURL'];
                        }
                        if ( '' === $url ) {
                                continue;
                        }

                        $hits[] = array(
                                'id'  => isset( $hit['id'] ) ? (int) $hit['id'] : 0,
                                'url' => $url,
                        );
                }

                // Cache per-query 24h ANCHE dei risultati vuoti (zero risultati = esito valido).
                if ( function_exists( 'set_transient' ) ) {
                        set_transient( $cache_key, $hits, self::PIXABAY_QUERY_TTL );
                }

                return $hits;
        }

        /**
         * Costruisce la query Pixabay dalle keyword dell'articolo.
         *
         * Prima scelta: prime 3-4 parole significative del titolo (stopwords IT/EN
         * rimosse). Se il titolo non produce nulla di utile, fallback sulla categoria.
         *
         * @param string $title    Titolo dell'articolo.
         * @param string $category Categoria dell'articolo.
         * @return string Query (parole separate da spazio), '' se nulla di utilizzabile.
         */
        private static function pixabay_query( $title, $category ) {
                $terms = self::pixabay_terms( $title );
                if ( empty( $terms ) ) {
                        $terms = self::pixabay_terms( $category );
                }

                return implode( ' ', array_slice( $terms, 0, 4 ) );
        }

        /**
         * Estrae le parole significative (max 4) da un testo: tokenizzazione
         * unicode, rimozione stopwords IT/EN, token di almeno 2 caratteri.
         *
         * Gli accenti vengono rimossi (remove_accents) per uniformare i token
         * (È → E). La lista stopwords copre i casi comuni ma non è esaustiva:
         * falsi positivi possibili su nomi propri (es. "La" in "La Liga"),
         * accettati: la ricerca Pixabay è fuzzy e la query resta sensata.
         *
         * @param string $text Testo sorgente (titolo o categoria).
         * @return array Lista di termini (max 4).
         */
        private static function pixabay_terms( $text ) {
                $text = function_exists( 'remove_accents' ) ? remove_accents( (string) $text ) : (string) $text;
                $text = trim( strtolower( $text ) );
                if ( '' === $text ) {
                        return array();
                }

                // Tokenizzazione unicode; fallback ASCII se il pattern /u fallisce su input degradato.
                $matches = array();
                if ( ! preg_match_all( '/[\\p{L}\\p{N}]{2,}/u', $text, $matches ) || empty( $matches[0] ) ) {
                        preg_match_all( '/[a-z0-9]{2,}/', $text, $matches );
                }
                if ( empty( $matches[0] ) || ! is_array( $matches[0] ) ) {
                        return array();
                }

                $stopwords = self::pixabay_stopwords();

                $terms = array();
                foreach ( $matches[0] as $word ) {
                        if ( isset( $stopwords[ $word ] ) ) {
                                continue;
                        }
                        $terms[] = $word;
                        if ( count( $terms ) >= 4 ) {
                                break;
                        }
                }

                return $terms;
        }

        /**
         * Stopwords IT/EN per la costruzione della query (lookup per chiave, O(1)).
         *
         * @return array Map parola => true.
         */
        private static function pixabay_stopwords() {
                static $map = null;
                if ( null !== $map ) {
                        return $map;
                }

                $words = array(
                        // Italiano.
                        'il', 'lo', 'la', 'i', 'gli', 'le', 'un', 'uno', 'una',
                        'di', 'del', 'della', 'dei', 'dello', 'delle', 'degli', 'dell',
                        'a', 'al', 'allo', 'alla', 'ai', 'agli', 'alle', 'all',
                        'da', 'dal', 'dallo', 'dalla', 'dai', 'dagli', 'dalle', 'dall',
                        'in', 'nel', 'nello', 'nella', 'nei', 'negli', 'nelle', 'nell',
                        'su', 'sul', 'sullo', 'sulla', 'sui', 'sugli', 'sulle', 'sull',
                        'con', 'per', 'tra', 'fra', 'e', 'ed', 'o', 'od', 'ma', 'se',
                        'che', 'chi', 'cui', 'non', 'come', 'dove', 'quando', 'perche',
                        'cosa', 'quale', 'quali', 'sono', 'stato', 'stata', 'stati', 'state',
                        'ha', 'hanno', 'ho', 'hai', 'avere', 'aveva', 'era', 'erano',
                        'essere', 'sia', 'anche', 'pero', 'quindi', 'gia', 'ancora',
                        'dopo', 'prima', 'contro', 'senza', 'fino', 'verso', 'secondo',
                        'piu', 'meno', 'molto', 'poco', 'tutto', 'tutti', 'tutta', 'tutte',
                        'questo', 'questa', 'questi', 'queste', 'quello', 'quella',
                        'quelli', 'quelle', 'suo', 'sua', 'suoi', 'sue', 'loro',
                        'nostro', 'vostro', 'oggi', 'ieri', 'giorno', 'fare', 'detto',
                        // Inglese.
                        'the', 'an', 'and', 'or', 'but', 'if', 'of', 'to', 'at', 'for',
                        'with', 'from', 'by', 'as', 'is', 'are', 'was', 'were', 'be',
                        'been', 'being', 'am', 'it', 'its', 'this', 'that', 'these',
                        'those', 'he', 'she', 'they', 'them', 'his', 'her', 'their',
                        'we', 'our', 'us', 'you', 'your', 'me', 'my', 'not', 'no',
                        'do', 'does', 'did', 'done', 'has', 'have', 'had', 'will',
                        'would', 'can', 'could', 'should', 'shall', 'may', 'might',
                        'must', 'about', 'into', 'over', 'under', 'than', 'then', 'so',
                        'who', 'whom', 'whose', 'what', 'when', 'where', 'why', 'how',
                        'all', 'any', 'both', 'each', 'few', 'more', 'most', 'other',
                        'some', 'such', 'only', 'own', 'same', 'too', 'very', 'just',
                        'also', 'after', 'before', 'between', 'during', 'through',
                        'against', 'out', 'up', 'down', 'off', 'again', 'once', 'here',
                        'there', 'on', 'said', 'says', 'new',
                );

                $map = array();
                foreach ( $words as $w ) {
                        $map[ $w ] = true;
                }

                return $map;
        }

        /**
         * ID Pixabay già usati per la dedup (transient 'nexus_pixabay_seen').
         *
         * Struttura: map id => timestamp dell'uso. Scadenza 30 giorni, rinnovata a
         * ogni nuova marcatura (sliding); cap 500 ID, i più vecchi vengono scartati.
         *
         * @return array Map id => timestamp.
         */
        private static function pixabay_seen_ids() {
                if ( ! function_exists( 'get_transient' ) ) {
                        return array();
                }

                $seen = get_transient( 'nexus_pixabay_seen' );
                return is_array( $seen ) ? $seen : array();
        }

        /**
         * Marca un ID Pixabay come usato (cap 500, scadenza 30 giorni slittante).
         *
         * @param int $id ID della foto Pixabay.
         * @return void
         */
        private static function pixabay_mark_seen( $id ) {
                $id = (int) $id;
                if ( $id <= 0 || ! function_exists( 'set_transient' ) ) {
                        return;
                }

                $seen        = self::pixabay_seen_ids();
                $seen[ $id ] = time();

                if ( count( $seen ) > self::PIXABAY_SEEN_CAP ) {
                        // Scarta i più vecchi (timestamp minore = usato prima);
                        // preserve_keys = true: le chiavi ID restano intatte.
                        asort( $seen );
                        $seen = array_slice( $seen, -self::PIXABAY_SEEN_CAP, self::PIXABAY_SEEN_CAP, true );
                }

                set_transient( 'nexus_pixabay_seen', $seen, self::PIXABAY_SEEN_TTL );
        }

        /**
         * Log 'pixabay_skip' UNA volta sola: la chiave API Pixabay è mancante.
         *
         * L'opzione nexus_pixabay_key_missing evita di scrivere un log a ogni
         * articolo (non spam): viene scritta al primo skip e RIMOSSA appena una
         * chiave viene trovata (pixabay_rearm_skip), così un cambio di configurazione
         * viene comunque riportato nel log una volta.
         *
         * @param string $detail Dettaglio onesto dello skip.
         * @return void
         */
        private static function pixabay_skip_once( $detail ) {
                if ( function_exists( 'get_option' ) && get_option( 'nexus_pixabay_key_missing' ) ) {
                        return;
                }

                if ( function_exists( 'update_option' ) ) {
                        update_option( 'nexus_pixabay_key_missing', 1, false );
                }

                self::log_activity( 'pixabay_skip: ' . (string) $detail, 'warning' );
        }

        /**
         * Ri-arma il log 'pixabay_skip' (rimuove l'opzione una-tantum).
         * Chiamato ogni volta che una chiave Pixabay risulta configurata.
         *
         * @return void
         */
        private static function pixabay_rearm_skip() {
                if ( function_exists( 'get_option' ) && function_exists( 'delete_option' ) && get_option( 'nexus_pixabay_key_missing' ) ) {
                        delete_option( 'nexus_pixabay_key_missing' );
                }
        }

        /* ============ ④ PLACEHOLDER LOCALE ============ */

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
         * Usa wp_upload_bits('nexus-<article_id>-<timestamp>.<ext>'). Se la scrittura
         * viene rifiutata (es. estensione non in whitelist mime) NON c'è ripiego
         * manuale (v1.1.9): il vecchio fallback file_put_contents depositava file in
         * uploads bypassando la whitelist mime (vettore stored XSS per XML/SVG).
         * Ritorna 0 e il chiamante logga l'errore onesto.
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
                if ( ! is_array( $upload ) || ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
                        // v1.1.9: NESSUN ripiego manuale su filesystem (ex file_put_contents):
                        // se la whitelist mime di WP rifiuta il file (es. SVG), la featured
                        // image semplicemente non viene impostata (errore onesto a monte).
                        return 0;
                }

                $file_path = (string) $upload['file'];
                $file_url  = isset( $upload['url'] ) ? (string) $upload['url'] : '';

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
