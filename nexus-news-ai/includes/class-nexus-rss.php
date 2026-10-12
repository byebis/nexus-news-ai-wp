<?php
/**
 * Nexus RSS — raccolta notizie REALI da feed RSS italiani per gli agenti AI.
 *
 * Port PHP di nexus-src/src/lib/rss.ts (adattato a WordPress: SimplePie via fetch_feed).
 *
 * CONTRATTO PUBBLICO (usato dagli altri moduli):
 * Nexus_Rss::sources_for_category( $category ) — feed RSS italiani reali per categoria
 *                                                (default generico: ANSA). Ritorna array di URL.
 * Nexus_Rss::fetch_for_agent( $agent )         — $agent è un oggetto (o array) con ->category
 *                                                e ->id (agente). Ritorna fino a 10 item come array
 *                                                di array: array( 'title', 'description' (max 400 char,
 *                                                senza tag), 'link', 'source' (nome host pulito) ).
 *                                                v1.1.4: gli item delle FONTI della redazione assegnata
 *                                                all'agente (term meta _nexus_sources, via
 *                                                Nexus_Redazioni::sources_items_for_agent) precedono i
 *                                                feed globali di categoria (merge deterministico,
 *                                                dedup per link, cap MAX_ITEMS invariato). La cache
 *                                                transient 'nexus_rss_' . md5( $category ) resta SOLO
 *                                                per gli item di categoria (30 minuti: il merge con le
 *                                                fonti redazione dipende dall'agente, non dalla categoria).
 *                                                I feed in errore vengono ignorati; se nessun item → array() vuoto.
 * Nexus_Rss::fetch_feed_items( $feed_url, $max_items = 5 ) — v1.1.4: item di UN solo feed,
 *                                                nello STESSO shape di fetch_for_agent. Usato da
 *                                                Nexus_Redazioni::sources_items_for_agent per garantire
 *                                                shape identico tra fonti redazione e feed di categoria.
 *                                                Feed in errore o vuoto → array() vuoto.
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Rss {

        /**
         * Durata della cache transient in secondi (30 minuti).
         */
        const CACHE_TTL = 1800;

        /**
         * Numero massimo di item restituiti per agente.
         */
        const MAX_ITEMS = 10;

        /**
         * Nomi sorgente leggibili per dominio (nome host pulito).
         *
         * @return array
         */
        private static function source_names() {
                return array(
                        'ansa.it'             => 'ANSA',
                        'ilpost.it'           => 'Il Post',
                        'wired.it'            => 'Wired',
                        'hdblog.it'           => 'HDblog',
                        'ilsole24ore.com'     => 'Il Sole 24 Ore',
                        'media.inaf.it'       => 'MEDIA INAF',
                        'lescienze.it'        => 'Le Scienze',
                        'galileonet.it'       => 'Galileo',
                        'sport.sky.it'        => 'Sky Sport',
                        'formiche.net'        => 'Formiche',
                        'artribune.com'       => 'Artribune',
                        'quotidianosanita.it' => 'Quotidiano Sanità',
                );
        }

        /**
         * Feed RSS italiani reali e attivi per categoria (3-4 per categoria).
         * Per categorie non mappate viene usato il feed generico ANSA.
         *
         * @param string $category Nome della categoria (es. "Tecnologia").
         * @return array Array di URL dei feed.
         */
        public static function sources_for_category( $category ) {
                $map = array(
                        'Tecnologia' => array(
                                'https://www.wired.it/feed/rss',
                                'https://www.hdblog.it/feed/',
                                'https://www.ansa.it/sito/notizie/tecnologia/tecnologia_rss.xml',
                                'https://www.ilpost.it/tecnologia/feed/',
                        ),
                        'Politica'   => array(
                                'https://www.ansa.it/sito/notizie/politica/politica_rss.xml',
                                'https://www.ilpost.it/politica/feed/',
                                'https://formiche.net/feed/',
                                'https://www.ilsole24ore.com/rss/italia.xml',
                        ),
                        'Economia'   => array(
                                'https://www.ansa.it/sito/notizie/economia/economia_rss.xml',
                                'https://www.ilsole24ore.com/rss/italia.xml',
                                'https://www.ilsole24ore.com/rss/economia.xml',
                                'https://www.ilpost.it/economia/feed/',
                        ),
                        'Scienza'    => array(
                                'https://www.ansa.it/canale_scienza_tecnica/notizie/scienza_tecnica_rss.xml',
                                'https://www.media.inaf.it/feed/',
                                'https://www.lescienze.it/rss/tutti/rss2.0.xml',
                                'https://www.galileonet.it/feed/',
                        ),
                        'Sport'      => array(
                                'https://www.ansa.it/sito/notizie/sport/sport_rss.xml',
                                'https://sport.sky.it/rss',
                                'https://www.ilpost.it/sport/feed/',
                        ),
                        'Cultura'    => array(
                                'https://www.ansa.it/sito/notizie/cultura/cultura_rss.xml',
                                'https://www.ilpost.it/cultura/feed/',
                                'https://www.artribune.com/feed/',
                        ),
                        'Salute'     => array(
                                'https://www.ansa.it/canale_salute/notizie/salute_rss.xml',
                                'https://www.ilsole24ore.com/rss/salute.xml',
                                'https://www.quotidianosanita.it/rss/home.xml',
                        ),
                );

                $key = trim( (string) $category );
                if ( isset( $map[ $key ] ) ) {
                        return $map[ $key ];
                }

                // Default generico.
                return array( 'https://www.ansa.it/sito/ansait_rss.xml' );
        }

        /**
         * v1.1.4 (T-08): item di UN solo feed RSS/Atom, nello STESSO shape di
         * fetch_for_agent (title, description clip 400, link, source) così che
         * Nexus_Redazioni::sources_items_for_agent restituisca item identici a
         * quelli dei feed di categoria e collect li consumi allo stesso modo.
         * Difensivo: feed in errore, vuoto o fetch_feed assente → array().
         *
         * @param string $feed_url  URL del feed.
         * @param int    $max_items Numero massimo di item da estrarre dal feed.
         * @return array Array di item: {title, description, link, source}.
         */
        public static function fetch_feed_items( $feed_url, $max_items = 5 ) {
                $feed_url  = trim( (string) $feed_url );
                $max_items = max( 1, (int) $max_items );
                if ( '' === $feed_url || ! function_exists( 'fetch_feed' ) ) {
                        return array();
                }

                $feed = fetch_feed( $feed_url );
                if ( is_wp_error( $feed ) ) {
                        return array();
                }

                $rss_items = $feed->get_items( 0, $max_items );
                if ( empty( $rss_items ) || ! is_array( $rss_items ) ) {
                        return array();
                }

                $out = array();
                foreach ( $rss_items as $rss_item ) {
                        if ( count( $out ) >= $max_items ) {
                                break;
                        }
                        if ( ! is_object( $rss_item ) ) {
                                continue;
                        }

                        $title = trim( wp_strip_all_tags( (string) $rss_item->get_title() ) );
                        $link  = trim( (string) $rss_item->get_permalink() );
                        if ( '' === $title || '' === $link ) {
                                continue;
                        }

                        $description = trim( wp_strip_all_tags( (string) $rss_item->get_description() ) );
                        $description = self::clip( preg_replace( '/\s+/', ' ', $description ), 400 );

                        $out[] = array(
                                'title'       => $title,
                                'description' => $description,
                                'link'        => esc_url_raw( $link ),
                                'source'      => self::source_from_url( $feed_url ),
                        );
                }

                return $out;
        }

        /**
         * Raccoglie fino a 10 notizie reali per un agente: PRIMA le fonti della
         * redazione assegnata (v1.1.4, term meta _nexus_sources via
         * Nexus_Redazioni::sources_items_for_agent) e POI i feed globali della
         * categoria, con merge deterministico (ordine stabile, deduplica per
         * link, cap MAX_ITEMS totale invariato).
         *
         * - I feed in errore vengono ignorati e si continua con i successivi;
         * - Cache transient 'nexus_rss_' . md5( $category ) per 30 minuti SOLO
         *   per gli item di categoria (i risultati vuoti NON vengono cachati,
         *   così il prossimo ciclo può ritentare): il merge con le fonti della
         *   redazione avviene SEMPRE dopo la cache, perché dipende dall'agente
         *   e non solo dalla categoria;
         * - Se nessun item è disponibile ritorna array() vuoto.
         *
         * @param object|array $agent Oggetto (o array) con ->category e ->id
         *                            (id agente per le fonti della redazione).
         * @return array Array di item: {title, description, link, source}.
         */
        public static function fetch_for_agent( $agent ) {
                $category = '';
                if ( is_object( $agent ) && isset( $agent->category ) ) {
                        $category = (string) $agent->category;
                } elseif ( is_array( $agent ) && isset( $agent['category'] ) ) {
                        $category = (string) $agent['category'];
                }
                $category = trim( $category );

                // v1.1.4: id agente per il merge con le fonti della redazione (difensivo).
                $agent_id = 0;
                if ( is_object( $agent ) && isset( $agent->id ) ) {
                        $agent_id = absint( $agent->id );
                } elseif ( is_array( $agent ) && isset( $agent['id'] ) ) {
                        $agent_id = absint( $agent['id'] );
                }

                // v1.1.4 (T-08): fonti della redazione PRIMA dei feed globali.
                // Guard classi/metodi: mai fatal se il modulo redazioni manca.
                $red_items = array();
                if ( $agent_id > 0 && class_exists( 'Nexus_Redazioni' ) && method_exists( 'Nexus_Redazioni', 'sources_items_for_agent' ) ) {
                        $fetched_red = Nexus_Redazioni::sources_items_for_agent( $agent_id );
                        if ( is_array( $fetched_red ) ) {
                                $red_items = $fetched_red;
                        }
                }

                // I feed globali di categoria si richiamano solo se le fonti della
                // redazione non riempiono già il limite totale (niente richieste inutili).
                $global_items = array();
                if ( count( $red_items ) < self::MAX_ITEMS ) {
                        $global_items = self::fetch_category_items( $category );
                }

                // Merge deterministico: prima le fonti della redazione, poi la
                // categoria; dedup per link (primo che arriva vince: le fonti
                // della redazione hanno precedenza sui globali).
                $items   = array();
                $seen    = array();
                $batches = array( $red_items, $global_items );
                foreach ( $batches as $batch ) {
                        if ( ! is_array( $batch ) ) {
                                continue;
                        }
                        foreach ( $batch as $item ) {
                                if ( count( $items ) >= self::MAX_ITEMS ) {
                                        break 2;
                                }
                                if ( ! is_array( $item ) || empty( $item['link'] ) ) {
                                        continue;
                                }
                                $item_key = strtolower( (string) $item['link'] );
                                if ( '' === $item_key || isset( $seen[ $item_key ] ) ) {
                                        continue;
                                }
                                $seen[ $item_key ] = true;
                                $items[]           = $item;
                        }
                }

                return array_slice( $items, 0, self::MAX_ITEMS );
        }

        /**
         * Item dei feed globali di categoria (comportamento storico v1.0.x,
         * estratto da fetch_for_agent in v1.1.4 per il merge con le fonti della
         * redazione). La cache transient resta SOLO qui: gli item di categoria
         * dipendono dalla categoria e non dall'agente.
         *
         * - Loop sui feed della categoria con fetch_feed() (SimplePie di WordPress);
         * - I feed in errore vengono ignorati e si continua con i successivi;
         * - Cache transient 'nexus_rss_' . md5( $category ) per 30 minuti
         *   (i risultati vuoti NON vengono cachati, così il prossimo ciclo può ritentare).
         *
         * @param string $category Nome della categoria (es. "Tecnologia").
         * @return array Array di item: {title, description, link, source}.
         */
        private static function fetch_category_items( $category ) {
                // Cache per categoria (30 minuti).
                $cache_key = 'nexus_rss_' . md5( $category );
                $cached    = get_transient( $cache_key );
                if ( is_array( $cached ) ) {
                        return $cached;
                }

                $feeds = self::sources_for_category( $category );
                $items = array();
                $seen  = array();

                foreach ( $feeds as $feed_url ) {
                        if ( count( $items ) >= self::MAX_ITEMS ) {
                                break;
                        }
                        if ( ! is_string( $feed_url ) || '' === $feed_url ) {
                                continue;
                        }

                        $rss_items = self::fetch_feed_items( $feed_url, self::MAX_ITEMS - count( $items ) );
                        if ( empty( $rss_items ) ) {
                                continue; // Feed irraggiungibile o senza item: ignora e prosegui con il successivo.
                        }

                        foreach ( $rss_items as $item ) {
                                if ( count( $items ) >= self::MAX_ITEMS ) {
                                        break;
                                }

                                // Deduplica per link (i feed della stessa categoria possono sovrapporsi).
                                $item_key = strtolower( (string) $item['link'] );
                                if ( '' === $item_key || isset( $seen[ $item_key ] ) ) {
                                        continue;
                                }
                                $seen[ $item_key ] = true;
                                $items[]           = $item;
                        }
                }

                $items = array_slice( $items, 0, self::MAX_ITEMS );

                if ( ! empty( $items ) ) {
                        set_transient( $cache_key, $items, self::CACHE_TTL );
                }

                return $items;
        }

        /**
         * Deriva un nome sorgente pulito dall'host del feed (es. "www.ansa.it" → "ANSA").
         *
         * @param string $url URL del feed o dell'articolo.
         * @return string
         */
        private static function source_from_url( $url ) {
                $host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
                $host = preg_replace( '/^www\./', '', $host );

                if ( '' === $host ) {
                        return '';
                }

                $names = self::source_names();
                if ( isset( $names[ $host ] ) ) {
                        return $names[ $host ];
                }

                return ucfirst( $host );
        }

        /**
         * Tronca una stringa alla lunghezza indicata (multibyte quando disponibile).
         *
         * @param string $text   Testo.
         * @param int    $length Lunghezza massima in caratteri.
         * @return string
         */
        private static function clip( $text, $length ) {
                $text = trim( (string) $text );
                if ( function_exists( 'mb_substr' ) ) {
                        return mb_substr( $text, 0, (int) $length );
                }
                return substr( $text, 0, (int) $length );
        }
}
