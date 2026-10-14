<?php
/**
 * Nexus RSS — raccolta notizie REALI da feed RSS per gli agenti AI.
 *
 * Port PHP di nexus-src/src/lib/rss.ts (adattato a WordPress: SimplePie via fetch_feed).
 *
 * v1.1.11 (R1) — ZERO FONTI HARDCODE: nel codice non resta NESSUNA URL di feed
 * letterale (la vecchia mappa categoria→feed ANSA/Wired/Il Post/… e il fallback
 * generico ANSA sono stati rimossi, così come la mappa dominio→etichetta di
 * source_names()). Le fonti RSS globali arrivano SOLO dalla configurazione
 * utente: setting 'global_rss_sources' (una URL http(s) per riga, vedi
 * Nexus_Rss::global_sources()), estendibile col filtro
 * 'nexus_rss_global_sources'. Nessun fallback nascosto: senza fonti
 * configurate la raccolta RSS globale non produce item.
 *
 * CONTRATTO PUBBLICO (usato dagli altri moduli):
 * Nexus_Rss::sources_for_category( $category ) — feed RSS GLOBALI configurati
 *                                                dall'utente (setting
 *                                                'global_rss_sources'). $category resta nella
 *                                                firma per retrocompatibilità e come contesto del
 *                                                filtro 'nexus_rss_global_sources', ma NON
 *                                                seleziona più nessuna fonte hardcoded.
 *                                                Ritorna array di URL (vuoto senza configurazione).
 * Nexus_Rss::global_sources()                  — v1.1.11 (R1): parse della setting
 *                                                'global_rss_sources' riga per riga (una URL
 *                                                http(s) per riga, righe vuote e che iniziano
 *                                                con '#' ignorate, esc_url_raw, dedup).
 * Nexus_Rss::fetch_for_agent( $agent )         — $agent è un oggetto (o array) con ->category
 *                                                e ->id (agente). Ritorna fino a 10 item come array
 *                                                di array: array( 'title', 'description' (max 400 char,
 *                                                senza tag), 'link', 'source' (nome host pulito) ).
 *                                                v1.1.4: gli item delle FONTI della redazione assegnata
 *                                                all'agente (term meta _nexus_sources, via
 *                                                Nexus_Redazioni::sources_items_for_agent) precedono le
 *                                                fonti RSS globali (merge deterministico,
 *                                                dedup per link, cap MAX_ITEMS invariato). La cache
 *                                                transient 'nexus_rss_' . md5( $category ) resta SOLO
 *                                                per gli item globali (30 minuti: il merge con le
 *                                                fonti redazione dipende dall'agente, non dalla categoria).
 *                                                I feed in errore vengono ignorati; senza fonti redazione
 *                                                e senza fonti globali → array() vuoto (comportamento
 *                                                onesto: la pipeline devia sul percorso evergreen).
 * Nexus_Rss::fetch_feed_items( $feed_url, $max_items = 5 ) — v1.1.4: item di UN solo feed,
 *                                                nello STESSO shape di fetch_for_agent. Usato da
 *                                                Nexus_Redazioni::sources_items_for_agent per garantire
 *                                                shape identico tra fonti redazione e fonti globali.
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
         * v1.1.15 (WB2): circuit breaker per-feed (stesso pattern di
         * Nexus_Sources): contatore fallimenti sliding 1h e cooldown 1h dopo
         * soglia (3 esiti vuoti consecutivi). TTL espliciti: i transient
         * espirano da soli, nessuna retention dedicata.
         */
        const FAIL_TTL       = 3600;
        const COOLDOWN_TTL   = 3600;
        const FAIL_THRESHOLD = 3;

        /**
         * v1.1.15 (WB2): cache negativa breve (10 minuti) sui risultati vuoti:
         * il vuoto non viene più rifetchato a ogni collect_interval.
         */
        const EMPTY_TTL = 600;

        /**
         * v1.1.11 (R1): feed RSS GLOBALI configurati dall'utente nella setting
         * 'global_rss_sources' (una URL http(s) per riga). ZERO URL hardcoded
         * nel codice: senza configurazione utente ritorna array() e la raccolta
         * RSS globale non produce item (comportamento onesto, nessun fallback
         * predefinito). $category resta nella firma per retrocompatibilità e
         * come contesto per il filtro 'nexus_rss_global_sources', ma NON
         * seleziona più nessuna fonte (non esiste più una mappa categoria→feed).
         *
         * @param string $category Nome della categoria dell'agente (contesto
         *                         per il filtro, non per la selezione fonti).
         * @return array Array di URL dei feed http(s).
         */
        public static function sources_for_category( $category ) {
                $sources = self::global_sources();

                /**
                 * Filtra i feed RSS GLOBALI (configurati dall'utente in
                 * Impostazioni → contenuti; dalla v1.1.11 (R1) non esiste più
                 * alcuna fonte hardcoded). Usati quando l'agente non ha fonti
                 * redazione, o per completare il paniere in
                 * Nexus_Rss::fetch_for_agent. Ritorna un array di URL feed http(s).
                 *
                 * @param array  $sources  URL dei feed globali configurati dall'utente.
                 * @param string $category Nome della categoria dell'agente.
                 */
                return apply_filters( 'nexus_rss_global_sources', $sources, $category );
        }

        /**
         * v1.1.11 (R1): parse della setting 'global_rss_sources' (STRINGA,
         * stesso pattern di 'mcp_servers_text'): una URL http(s) per riga,
         * righe vuote e che iniziano con '#' ignorate (stessa convenzione dei
         * server MCP), ogni URL normalizzata con esc_url_raw, SOLO schemi
         * http/https (niente file://, ftp://, data:), deduplica per URL.
         * Difensivo: Nexus_DB assente o setting mancante → array().
         *
         * @return array Array di URL http(s) uniche, in ordine di inserimento.
         */
        public static function global_sources() {
                $raw = '';
                if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'get_settings' ) ) {
                        $settings = Nexus_DB::get_settings();
                        if ( is_array( $settings ) && isset( $settings['global_rss_sources'] ) ) {
                                $raw = (string) $settings['global_rss_sources'];
                        }
                }

                if ( '' === trim( $raw ) ) {
                        return array();
                }

                $lines   = preg_split( '/\r\n|\r|\n/', $raw );
                $sources = array();
                $seen    = array();

                foreach ( (array) $lines as $line ) {
                        $url = trim( (string) $line );
                        if ( '' === $url || 0 === strpos( $url, '#' ) ) {
                                continue;
                        }

                        // Solo http/https: il valore arriva da una textarea libera.
                        $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
                        if ( 'http' !== $scheme && 'https' !== $scheme ) {
                                continue;
                        }

                        $url = esc_url_raw( $url );
                        if ( '' === $url ) {
                                continue;
                        }

                        $key = strtolower( $url );
                        if ( isset( $seen[ $key ] ) ) {
                                continue;
                        }
                        $seen[ $key ] = true;
                        $sources[]    = $url;
                }

                return $sources;
        }

        /**
         * v1.1.4 (T-08): item di UN solo feed RSS/Atom, nello STESSO shape di
         * fetch_for_agent (title, description clip 400, link, source) così che
         * Nexus_Redazioni::sources_items_for_agent restituisca item identici a
         * quelli dei feed di categoria e collect li consumi allo stesso modo.
         * Difensivo: URL non consentita dalla SSRF guard (v1.1.15 WB3,
         * Nexus_Tools::assert_safe_url: DNS + IP privati, fail-closed), feed
         * in errore, vuoto o fetch_feed assente → array().
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

                // v1.1.15 (WB3): SSRF guard prima del fetch (stesso pattern di
                // Nexus_Tools::read_feed e discover_feeds): SimplePie non ha
                // guardie SSRF proprie e le URL dei feed arrivano da configurazione
                // utente/redazioni. assert_safe_url normalizza e blocca schemi non
                // http(s), host invalidi e IP privati/riservati (DNS incluso).
                // Guardie class_exists/method_exists come nell'adapter web di
                // Nexus_Sources (mai fatal); fetch_feed_items non ha fallback
                // alternativo, quindi senza guardia disponibile si rinuncia al
                // fetch (fail-closed: array() vuoto onesto).
                if ( ! class_exists( 'Nexus_Tools' ) || ! method_exists( 'Nexus_Tools', 'assert_safe_url' )
                        || is_wp_error( Nexus_Tools::assert_safe_url( $feed_url ) ) ) {
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
         * Nexus_Redazioni::sources_items_for_agent) e POI le fonti RSS globali
         * configurate dall'utente (v1.1.11 (R1), setting 'global_rss_sources'),
         * con merge deterministico (ordine stabile, deduplica per
         * link, cap MAX_ITEMS totale invariato).
         *
         * - I feed in errore vengono ignorati e si continua con i successivi;
         * - Cache transient 'nexus_rss_' . md5( $category ) per 30 minuti per
         *   gli item globali (v1.1.15 (WB2): anche i risultati vuoti vengono
         *   cachati brevemente, 10 minuti, con circuit breaker per-feed a
         *   monte): il merge con le fonti della redazione avviene SEMPRE dopo
         *   la cache, perché dipende dall'agente e non solo dalla categoria;
         * - Se nessun item è disponibile (nessuna fonte redazione E nessuna
         *   fonte globale configurata) ritorna array() vuoto.
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

                // v1.1.4 (T-08): fonti della redazione PRIMA delle fonti globali.
                // Guard classi/metodi: mai fatal se il modulo redazioni manca.
                $red_items = array();
                if ( $agent_id > 0 && class_exists( 'Nexus_Redazioni' ) && method_exists( 'Nexus_Redazioni', 'sources_items_for_agent' ) ) {
                        $fetched_red = Nexus_Redazioni::sources_items_for_agent( $agent_id );
                        if ( is_array( $fetched_red ) ) {
                                $red_items = $fetched_red;
                        }
                }

                // Le fonti RSS globali si richiamano solo se le fonti della
                // redazione non riempiono già il limite totale (niente richieste inutili).
                $global_items = array();
                if ( count( $red_items ) < self::MAX_ITEMS ) {
                        $global_items = self::fetch_category_items( $category );
                }

                // Merge deterministico: prima le fonti della redazione, poi le
                // globali; dedup per link (primo che arriva vince: le fonti
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
         * Item delle fonti RSS globali configurate (comportamento storico
         * v1.0.x, estratto da fetch_for_agent in v1.1.4 per il merge con le
         * fonti della redazione; dalla v1.1.11 (R1) le fonti arrivano dalla
         * setting 'global_rss_sources', senza più mappa per categoria). La
         * cache transient resta SOLO qui: la chiave include la categoria
         * (contesto dell'agente) e il contenuto non dipende dall'agente.
         *
         * - Loop sui feed globali con fetch_feed() (SimplePie di WordPress);
         * - v1.1.15 (WB2) circuit breaker per-feed (stile Nexus_Sources):
         *   ogni esito vuoto incrementa un contatore sliding 1h
         *   ('nexus_rss_fail_<hash>', hash = md5 dell'URL normalizzato);
         *   al 3° esito consecutivo il feed entra in cooldown 1h
         *   ('nexus_rss_cool_<hash>') e viene saltato senza fetch;
         * - I feed in errore vengono ignorati e si continua con i successivi;
         * - Cache transient 'nexus_rss_' . md5( $category ) per 30 minuti con
         *   i risultati; i vuoti sono cachati brevemente (10 minuti, EMPTY_TTL)
         *   così un giro senza item non rifetcha i feed a ogni collect_interval.
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

                        // v1.1.15 (WB2): feed in cooldown (3 esiti vuoti consecutivi
                        // → 1h): niente fetch sul feed morto, si prosegue col successivo.
                        $feed_key = self::feed_state_key( $feed_url );
                        if ( get_transient( 'nexus_rss_cool_' . $feed_key ) ) {
                                continue;
                        }

                        $rss_items = self::fetch_feed_items( $feed_url, self::MAX_ITEMS - count( $items ) );
                        if ( empty( $rss_items ) ) {
                                // v1.1.15 (WB2): esito vuoto = fallimento per il circuit
                                // breaker (fail-counter + cooldown dopo soglia).
                                self::mark_feed_failure( $feed_key );
                                continue; // Feed irraggiungibile o senza item: ignora e prosegui con il successivo.
                        }

                        // v1.1.15 (WB2): esito positivo → azzera il contatore fallimenti.
                        self::mark_feed_success( $feed_key );

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
                } else {
                        // v1.1.15 (WB2): cache negativa breve sul vuoto: prima il
                        // vuoto non veniva cachato e i feed venivano rifetchati a
                        // ogni collect_interval (N feed × M agenti, per sempre).
                        // Cache TTL 10 minuti: il ritento resta frequente ma non
                        // martellante, e il circuit breaker per-feed taglia i morti.
                        set_transient( $cache_key, array(), self::EMPTY_TTL );
                }

                return $items;
        }

        /**
         * v1.1.15 (WB2): chiave stato stabile di un feed per i transient del
         * circuit breaker: md5 dell'URL normalizzato (trim + lower). Stessa
         * idea di Nexus_Sources::state_key, senza il tipo (qui esiste solo
         * il feed RSS/Atom): la chiave non cambia tra giri e tra agenti.
         *
         * @param string $feed_url URL del feed.
         * @return string Hash stabile.
         */
        private static function feed_state_key( $feed_url ) {
                return md5( strtolower( trim( (string) $feed_url ) ) );
        }

        /**
         * v1.1.15 (WB2): registra un esito vuoto del feed: contatore sliding 1h
         * ('nexus_rss_fail_<hash>'); al 3° esito consecutivo → cooldown 1h
         * ('nexus_rss_cool_<hash>'). Stesso pattern di
         * Nexus_Sources::mark_failure (fail-counter + cooldown, mai fatale).
         *
         * @param string $feed_key Chiave stato del feed (feed_state_key).
         * @return void
         */
        private static function mark_feed_failure( $feed_key ) {
                $transient = 'nexus_rss_fail_' . $feed_key;
                $failures  = (int) get_transient( $transient ) + 1;
                set_transient( $transient, $failures, self::FAIL_TTL );

                if ( $failures >= self::FAIL_THRESHOLD && ! get_transient( 'nexus_rss_cool_' . $feed_key ) ) {
                        set_transient( 'nexus_rss_cool_' . $feed_key, 1, self::COOLDOWN_TTL );
                }
        }

        /**
         * v1.1.15 (WB2): registra un esito positivo del feed: azzera il
         * contatore fallimenti (transient 'nexus_rss_fail_<hash>').
         *
         * @param string $feed_key Chiave stato del feed (feed_state_key).
         * @return void
         */
        private static function mark_feed_success( $feed_key ) {
                delete_transient( 'nexus_rss_fail_' . $feed_key );
        }

        /**
         * Deriva un nome sorgente pulito dall'host del feed, best effort:
         * niente "www.", niente TLD (ultimo segmento), eventuali segmenti
         * residui come parole separate con iniziale maiuscola.
         *
         * v1.1.11 (R1): la vecchia mappa dominio→etichetta (source_names(),
         * "ansa.it" => "ANSA"…) era a sua volta una fonte hardcoded ed è stata
         * rimossa: l'etichetta deriva SOLO dall'host. Esempi: "www.ansa.it" →
         * "Ansa", "sport.sky.it" → "Sport Sky", "www.ilpost.it" → "Ilpost"
         * (una parola unica resta una parola: nessun dizionario nel codice).
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

                // TLD via (solo l'ultimo segmento, es. ".it", ".com"): best effort,
                // senza lista di suffissi pubblici (sarebbe altra hardcode).
                $host = preg_replace( '/\.[a-z0-9]{2,}$/', '', $host );

                // Sottodominii residui (es. "sport.sky") come parole separate.
                $label = str_replace( '.', ' ', $host );

                return trim( ucwords( $label ) );
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
