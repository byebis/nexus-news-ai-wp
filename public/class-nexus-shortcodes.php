<?php
/**
 * Nexus Shortcodes — frontend pubblico in italiano: magazine, articoli e Nexus Wire.
 *
 * Shortcode disponibili:
 *   [nexus_magazine]                    — hero + barra categorie + griglia + modal root
 *   [nexus_magazine redazione="sport"]  — variante filtrata sul ramo COMPLETO della
 *                                         redazione (v1.1.1: sub-redazioni incluse,
 *                                         semantica nativa include_children)
 *   [nexus_magazine lang="en"]          — variante filtrata per lingua (v1.1.2,
 *                                         driver nexus; con Polylang/WPML delegata)
 *   [nexus_articles category="" limit="9" layout="grid|list" redazione="" lang=""]
 *   [nexus_wire limit="10"]             — Redazione Collettiva (ultime esecuzioni)
 *   [nexus_lang_switcher]               — selettore lingue del sito (v1.1.2)
 *
 * NOTA v1.1.1 — attributo redazione (slug o nome, anche parziale: basta lo slug
 * della redazione madre): risolve il termine con get_term_by slug e poi nome
 * sulla tassonomia Nexus_Redazioni::TAX e filtra il listing sul ramo completo
 * (redazione="sport" include anche Calcio, Basket e F1). Se la redazione non
 * esiste l'output e' un messaggio onesto nel markup, senza contenuto inventato.
 * Con il filtro attivo la barra categorie e il bottone "Carica altri" NON vengono
 * stampati: la paginazione e il filtro categoria client-side passano dalla REST
 * nexus-v1 public-articles che non accetta il parametro redazione, quindi
 * verrebbero mostrati articoli fuori dal ramo (niente percorsi disonesti).
 * Senza l'attributo il comportamento e' identico a prima (zero regressioni).
 *
 * NOTA v1.1.2 — multilingua (driver attivo da Nexus_Multilang: polylang, wpml o
 * collegamento interno nexus):
 *   - nuovo shortcode [nexus_lang_switcher]: elenco lingue del sito. Su un
 *     singolo articolo di tipo post i link puntano al permalink della
 *     traduzione PUBBLICATA (Nexus_Multilang::translations_of); una lingua
 *     senza traduzione pubblica diventa uno span non cliccabile con title
 *     onesto "Traduzione non disponibile". Fuori dal singular il link e' la
 *     home con query var ?lang={code}: per il driver nexus la variabile viene
 *     consumata dal filtro pre_get_posts di questa classe; con Polylang o
 *     WPML lo shortcode mostra comunque l'elenco lingue del driver, ma il
 *     comportamento dei link sugli archivi resta ai plugin (usare il loro
 *     switcher se preferiscono altri formati di URL). Senza la classe
 *     Nexus_Multilang l'output dello switcher e' vuoto: onesto e mai fatale.
 *   - nuovo attributo lang su [nexus_magazine] e [nexus_articles] (driver
 *     nexus): risolve i post WordPress della lingua (meta _nexus_lang; i post
 *     SENZA meta sono della lingua predefinita del sito) e filtra le righe
 *     articoli tramite la colonna wp_post_id, stesso pattern del filtro
 *     redazione v1.1.1. Combinabile con redazione (intersezione dei due
 *     insiemi di post ID). Lingua richiesta inesistente → messaggio onesto
 *     "Lingua non trovata"; con il filtro attivo barra categorie e bottone
 *     "Carica altri" NON vengono stampati (stesso motivo della redazione: il
 *     refetch client passa dalla REST nexus-v1 public-articles che non porta
 *     il parametro lang). Con Polylang/WPML l'attributo e' accettato e
 *     ignorato: i contenuti sono gia' filtrati per lingua corrente dai plugin.
 *   - query var 'lang' registrata su query_vars e filtro pre_get_posts della
 *     main query (solo driver nexus): solo front-end, mai admin, solo listing
 *     di post (home, archivi e ricerca con post_type nativo o non impostato);
 *     mai singular, pagine o archivi di altri tipi di post.
 *
 * NOTA v1.1.18 — SCALABILITÀ (cap post ID): le query che risolvono i post
 * ID dei filtri redazione/lingua NON usano più posts_per_page = -1 (costo
 * O(n) a ogni pageview, IN list enorme su siti con migliaia di articoli):
 * caricano al più branch_id_cap() ID — default 100, filtro dev
 * 'nexus_shortcode_branch_cap', tetto assoluto 500 (BRANCH_ID_CAP_MAX).
 * La lista alimenta solo la join con nexus_articles (che espone al più
 * per_page/limit ≤ 24 righe) e il contatore dell'hero: vedi
 * Nexus_Shortcodes::branch_id_cap() per i dettagli e i limiti onesti.
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
 *   9. Badge fact-check (v1.0.3) ... .nexus-factcheck.ok | .nexus-factcheck.low
 *                                    (renderizzato in render_card() e nella byline
 *                                     del template single quando factcheck_score > 0;
 *                                     classe "ok" se score >= 60 altrimenti "low",
 *                                     vedi Nexus_Shortcodes::factcheck_badge())
 *  10. Fonti verificate (v1.0.3) ... .nexus-citations (blocco h4 + lista
 *                                    claim → link fonte, max 8 voci; generato da
 *                                    Nexus_Shortcodes::citations_block() nella card
 *                                    e nel template single sotto il box fonti)
 *  11. Selettore lingua (v1.1.2) ... ul.nexus-lang-switcher con li.nexus-lang-item
 *                                    [nexus-lang-current] data-lang="{codice lingua}";
 *                                    lingua senza traduzione pubblica = span con
 *                                    classe nexus-lang-missing e title onesto.
 *                                    Solo CSS (sezione 16 di nexus-public.css):
 *                                    nessun comportamento JS previsto.
 *
 * NOTA v1.0.3: il modal articolo è costruito lato JS (nexus-public.js, fuori
 * scope del frontend PHP). Le card client-side (cardHTML) replicano render_card():
 * quando serialize_article esporrà factcheck_score/citations via REST, il JS può
 * riutilizzare le stesse classi .nexus-factcheck/.nexus-citations (CSS già pronto).
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
 *                     image_url, published_at, read_time, views, reactions,
 *                     factcheck_score, citations (JSON string), ... }
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
         * Soglia minima del punteggio fact-check per il badge verde
         * (.nexus-factcheck.ok): sotto soglia il badge è arancio (.low).
         */
        const FACTCHECK_MIN_OK = 60;

        /**
         * v1.1.18 (FIX-10): cap DEFAULT di post ID caricati dalle query dei
         * filtri redazione/lingua degli shortcode pubblici (prima:
         * posts_per_page = -1, costo O(n) per pageview). La lista ID alimenta
         * la join con nexus_articles (che espone al più per_page/limit ≤ 24
         * righe) e il conteggio dell'hero: 100 ID coprono ampiamente le righe
         * effettivamente mostrate.
         */
        const BRANCH_ID_CAP_DEFAULT = 100;

        /**
         * v1.1.18 (FIX-10): tetto MASSIMO del cap, rispettato anche se il
         * filtro 'nexus_shortcode_branch_cap' chiede di più: protegge il DB
         * da IN list enormi su qualunque configurazione (l'utente può
         * alzare il default con il filtro, mai oltre questo limite).
         */
        const BRANCH_ID_CAP_MAX = 500;

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
         * Bootstrap: registra shortcode + hook asset + hook multilingua (v1.1.2).
         *
         * @return void
         */
        public static function init() {
                add_shortcode( 'nexus_magazine', array( __CLASS__, 'render_magazine' ) );
                add_shortcode( 'nexus_articles', array( __CLASS__, 'render_articles' ) );
                add_shortcode( 'nexus_wire', array( __CLASS__, 'render_wire' ) );
                add_shortcode( 'nexus_lang_switcher', array( __CLASS__, 'render_lang_switcher' ) );

                add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_public' ) );

                /* v1.1.2 multilingua: query var lang e filtro lingua della main
                 * query, entrambi attivi SOLO con il driver nexus. Con Polylang
                 * o WPML i ganci restano inattivi: i plugin filtrano gia' da soli. */
                add_filter( 'query_vars', array( __CLASS__, 'register_lang_query_var' ) );
                add_action( 'pre_get_posts', array( __CLASS__, 'filter_main_query_by_lang' ) );
        }

        /* ========================= ASSET ========================= */

        /**
         * Registra (senza enqueue) CSS e JS pubblico + oggetto NexusPublicData.
         * L'enqueue reale avviene in enqueue_assets() durante il rendering shortcode.
         *
         * v1.1.7: la registrazione è estratta in register_public_assets() così
         * enqueue_assets() può richiamarla da sola: nei temi a blocchi lo shortcode
         * può essere processato dal blocco core/shortcode PRIMA di wp_enqueue_scripts
         * (il canvas dei template renderizza il contenuto prima di wp_head): al
         * momento del rendering l'handle non era ancora registrato e il vecchio
         * guard saltava l'enqueue — magazine senza CSS e senza JS. Ora lo shortcode
         * si registra gli asset da solo, in qualsiasi momento venga renderizzato
         * (stampa tardiva in footer: print_late_styles + print_footer_scripts di core).
         *
         * @return void
         */
        public static function enqueue_public() {
                self::register_public_assets();
        }

        /**
         * Registrazione vera e propria di CSS/JS pubblico + localizzazione.
         * Idempotente: chiamabile sia da wp_enqueue_scripts sia da enqueue_assets().
         *
         * @return void
         */
        private static function register_public_assets() {
                if ( wp_script_is( 'nexus-public', 'registered' ) ) {
                        return;
                }
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
         * Enqueue reale degli asset (chiamato durante il rendering shortcode).
         * v1.1.7: se l'handle non è ancora registrato (shortcode renderizzato
         * prima di wp_enqueue_scripts, es. blocco core/shortcode nei temi a
         * blocchi) registra al volo e poi enqueue — mai più asset mancanti.
         *
         * @return void
         */
        private static function enqueue_assets() {
                self::register_public_assets();
                wp_enqueue_style( 'nexus-public' );
                wp_enqueue_script( 'nexus-public' );
        }

        /* ========================= [nexus_magazine] ========================= */

        /**
         * Render del magazine completo: hero, barra categorie, griglia, load-more, modal root.
         *
         * Da v1.1.1 supporta l'attributo redazione (slug o nome): filtra la
         * griglia sul ramo completo della redazione (sub-redazioni incluse,
         * semantica nativa include_children della tassonomia gerarchica).
         * Esempio: [nexus_magazine redazione="sport"] mostra gli articoli di
         * Sport e di tutte le sue sub-redazioni. Se la redazione non esiste
         * l'output e' un messaggio onesto, senza contenuto inventato; con il
         * filtro attivo barra categorie e load-more restano fuori (vedi nota
         * v1.1.1 in testa al file).
         *
         * Da v1.1.2 supporta l'attributo lang (driver nexus): filtra la griglia
         * sugli articoli nella lingua richiesta (post WordPress con meta
         * _nexus_lang; i post SENZA meta sono della lingua predefinita del
         * sito), combinabile con redazione (intersezione dei due insiemi di
         * post ID). Lingua inesistente → messaggio onesto "Lingua non trovata";
         * con il filtro attivo barra categorie e load-more restano fuori,
         * stesso motivo della redazione. Con Polylang o WPML l'attributo e'
         * accettato e ignorato: i contenuti sono gia' filtrati per lingua
         * corrente dai plugin (nota v1.1.2 in testa al file).
         *
         * @param array|string $atts Attributi shortcode. Supportati: per_page (default 12), redazione (default ''), lang (default '').
         * @return string
         */
        public static function render_magazine( $atts = array() ) {
                $atts = shortcode_atts(
                        array(
                                'per_page'  => 12,
                                'redazione' => '',
                                'lang'      => '',
                        ),
                        (array) $atts,
                        'nexus_magazine'
                );
                $per_page = min( 24, max( 1, absint( $atts['per_page'] ) ) );

                /* v1.1.1: filtro redazione (slug o nome), semantica subtree. */
                $redazione      = sanitize_text_field( (string) $atts['redazione'] );
                $redazione_term = ( '' !== $redazione ) ? self::redazione_term( $redazione ) : null;

                /* Redazione richiesta ma inesistente: output onesto, nessun contenuto inventato. */
                if ( '' !== $redazione && ! $redazione_term instanceof WP_Term ) {
                        return '<div class="nxa-magazine" data-nxa="magazine"><p class="nxa-empty">'
                                . esc_html( sprintf(
                                        /* translators: %s: redazione richiesta. */
                                        __( 'Redazione non trovata: %s', 'nexus-news-ai' ),
                                        $redazione
                                ) )
                                . '</p></div>';
                }

                /* v1.1.2: filtro lingua (driver nexus). Con Polylang/WPML i contenuti
                 * sono gia' filtrati per lingua corrente dai plugin: attributo
                 * accettato e senza effetto qui (nota v1.1.2 in testa al file). */
                $lang_state = self::lang_filter_state( $atts['lang'] );

                /* Lingua richiesta inesistente: output onesto, nessun contenuto inventato. */
                if ( $lang_state['unknown'] ) {
                        return '<div class="nxa-magazine" data-nxa="magazine"><p class="nxa-empty">'
                                . esc_html( sprintf(
                                        /* translators: %s: codice lingua richiesto. */
                                        __( 'Lingua non trovata: %s', 'nexus-news-ai' ),
                                        $lang_state['lang']
                                ) )
                                . '</p></div>';
                }

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

                if ( $redazione_term instanceof WP_Term ) {
                        /* Ramo completo della redazione (sub-redazioni incluse). */
                        $branch_post_ids = self::redazione_branch_post_ids( $redazione_term );
                        if ( $lang_state['active'] ) {
                                /* Intersezione ramo redazione e lingua richiesta (v1.1.2). */
                                $branch_post_ids = array_values( array_intersect( $branch_post_ids, $lang_state['post_ids'] ) );
                        }
                        $published_count = self::redazione_count_articles( $branch_post_ids );
                        $articles        = self::redazione_articles( $branch_post_ids, $per_page );
                } elseif ( $lang_state['active'] ) {
                        /* Filtro lingua senza redazione (v1.1.2): gli helper per post ID
                         * del filtro redazione sono generici e vengono riusati qui. */
                        $published_count = self::redazione_count_articles( $lang_state['post_ids'] );
                        $articles        = self::redazione_articles( $lang_state['post_ids'], $per_page );
                } else {
                        $published_count = Nexus_DB::count_articles( array( 'status' => 'published' ) );
                        $articles        = Nexus_DB::articles(
                                array(
                                        'status'   => 'published',
                                        'per_page' => $per_page,
                                        'orderby'  => 'published_at',
                                        'order'    => 'desc',
                                )
                        );
                }

                $out  = '';
                $out .= '<div class="nxa-magazine" data-nxa="magazine">';

                /* ---------- Hero ---------- */
                /* v1.1.5: nome e tagline dal WP nativo (Impostazioni > Generali,
                 * opzioni blogname e blogdescription). Fallback nell'ordine:
                 * le vecchie chiavi settings (retrocompatibilita'), poi i
                 * valori storici di default. */
                $hero_site_name = trim( (string) get_bloginfo( 'name' ) );
                if ( '' === $hero_site_name ) {
                        $hero_site_name = trim( (string) ( $settings['site_name'] ?? '' ) );
                }
                if ( '' === $hero_site_name ) {
                        $hero_site_name = 'Nexus News AI';
                }
                $hero_site_tagline = trim( (string) get_bloginfo( 'description' ) );
                if ( '' === $hero_site_tagline ) {
                        $hero_site_tagline = trim( (string) ( $settings['site_tagline'] ?? '' ) );
                }
                if ( '' === $hero_site_tagline ) {
                        $hero_site_tagline = 'Il giornale di nuova generazione';
                }
                $out .= '<section class="nxa-hero" aria-labelledby="nxa-hero-title">';
                $out .= '<div class="nxa-hero-inner">';
                $out .= '<span class="nxa-hero-badge">🤖 ' . sprintf(
                        /* translators: %s: numero di agenti attivi. */
                        esc_html( _n( '%s agente giornalista AI', '%s agenti giornalisti AI', $n_agents, 'nexus-news-ai' ) ),
                        esc_html( number_format_i18n( $n_agents ) )
                ) . '</span>';
                $out .= '<h1 class="nxa-hero-title" id="nxa-hero-title">' . esc_html( wp_specialchars_decode( $hero_site_name, ENT_QUOTES ) ) . '</h1>';
                $out .= '<p class="nxa-hero-tagline">' . esc_html( wp_specialchars_decode( $hero_site_tagline, ENT_QUOTES ) ) . '</p>';
                $out .= '<div class="nxa-hero-stats">';
                $out .= '<span class="nxa-hero-stat"><strong>' . esc_html( number_format_i18n( $published_count ) ) . '</strong> ' . esc_html__( 'articoli pubblicati', 'nexus-news-ai' ) . '</span>';
                $out .= '</div>';
                $out .= '</div>';
                $out .= '</section>';

                /* ---------- Barra categorie ---------- */
                /* Con filtro redazione o lingua la barra resta fuori: il refetch
                 * client-side per categoria passa dalla REST pubblica senza i
                 * parametri redazione/lang e sostituirebbe le card filtrate con
                 * articoli fuori dal filtro (niente percorsi disonesti). */
                if ( ! $redazione_term instanceof WP_Term && ! $lang_state['active'] ) {
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
                }

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
                /* Con filtro redazione o lingua il bottone resta fuori: la
                 * paginazione JS chiama la REST pubblica che non accetta i
                 * parametri redazione/lang, quindi caricherebbe articoli
                 * fuori dal filtro attivo. */
                if ( ! $redazione_term instanceof WP_Term && ! $lang_state['active'] && $published_count > $per_page ) {
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
         * Da v1.0.3 include, se presenti nella riga articolo:
         *   - badge fact-check .nexus-factcheck (nel meta del footer);
         *   - blocco fonti verificate .nexus-citations (dopo l'excerpt,
         *     variante compatta via CSS). Le card JS (cardHTML) replicano
         *     questo markup: allineare lì i nuovi elementi quando i campi
         *     factcheck_score/citations saranno esposti via REST.
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

                /*
                 * v1.1.15 (WD8): la colonna read_time (popolata alla pubblicazione)
                 * fa da primaria — prima ogni card del magazine strip-pava e
                 * ricontava il contenuto completo a ogni render. Il ricalcolo
                 * strip+conteggio resta SOLO come fallback onesto per righe con
                 * colonna assente o 0 (articoli storici).
                 */
                if ( (int) ( $article->read_time ?? 0 ) > 0 ) {
                        $read_time = sprintf( /* translators: %d: minuti di lettura. */ esc_html__( '%d min', 'nexus-news-ai' ), (int) $article->read_time );
                } else {
                        $content_words = trim( wp_strip_all_tags( (string) ( $article->content ?? '' ) ) );
                        $read_time     = ( '' !== $content_words )
                                ? Nexus_Utils::reading_time_it( (string) $article->content )
                                : sprintf( /* translators: %d: minuti di lettura. */ esc_html__( '%d min', 'nexus-news-ai' ), 3 );
                }

                $cat_color = self::color_attr( Nexus_Utils::category_color( $cat ) );
                $avatar    = ( $agent && ! empty( $agent->avatar ) ) ? (string) $agent->avatar : '🤖';
                $agent_nm  = ( $agent && ! empty( $agent->name ) ) ? (string) $agent->name : __( 'Agente AI', 'nexus-news-ai' );

                /* Fact-check + fonti verificate (v1.0.3): helper condivisi col template single. */
                $factcheck_badge = self::factcheck_badge( $article );
                $citations_html  = self::citations_block( $article );

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

                /* Fonti verificate (max 8) se il fact-check ha prodotto citazioni. */
                if ( $citations_html ) {
                        $out .= $citations_html; // phpcs:ignore WordPress.Security.EscapeOutput -- HTML già escaped nell'helper.
                }

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
                if ( $factcheck_badge ) {
                        if ( $time_ago || $read_time ) {
                                $out .= '<span class="nxa-sep" aria-hidden="true">·</span>';
                        }
                        $out .= $factcheck_badge; // phpcs:ignore WordPress.Security.EscapeOutput -- HTML già escaped nell'helper.
                }
                $out .= '</span>';
                $out .= '</footer>';

                $out .= '</div>';
                $out .= '</article>';

                return $out;
        }

        /* ========================= Fact-check & citazioni (v1.0.3) ========================= */

        /**
         * Badge "Verificato AI {score}/100" per un articolo fact-checkato.
         *
         * Restituisce stringa vuota se factcheck_score manca o è <= 0.
         * Classe CSS: "ok" se score >= FACTCHECK_MIN_OK (60), altrimenti "low".
         * Punteggio formattato come intero (82) o con decimale italiano (82,5).
         *
         * @param object $article Riga tabella nexus_articles.
         * @return string HTML del badge o ''.
         */
        public static function factcheck_badge( $article ) {
                if ( ! is_object( $article ) || ! isset( $article->factcheck_score ) ) {
                        return '';
                }

                $score = (float) $article->factcheck_score;
                if ( $score <= 0 ) {
                        return '';
                }

                $class     = ( $score >= self::FACTCHECK_MIN_OK ) ? 'ok' : 'low';
                $score_txt = ( floor( $score ) === $score )
                        ? (string) (int) $score
                        : number_format( $score, 1, ',', '.' );

                return '<span class="nexus-factcheck ' . esc_attr( $class ) . '">'
                        . esc_html( sprintf(
                                /* translators: %s: punteggio fact-check su 100. */
                                __( 'Verificato AI %s/100', 'nexus-news-ai' ),
                                $score_txt
                        ) )
                        . '</span>';
        }

        /**
         * Blocco "Fonti verificate" (.nexus-citations) dalle citazioni del fact-check.
         *
         * Decodifica difensiva del campo citations (stringa JSON, può essere
         * null/vuota/malformata): accetta array di {claim, source} (o oggetti /
         * stringhe pure) e restituisce al massimo $max voci valide. Tutto il
         * contenuto è escaped (esc_html/esc_url); il link apre in nuova scheda.
         *
         * @param object $article Riga tabella nexus_articles.
         * @param int    $max     Numero massimo di citazioni (default 8, min 1).
         * @return string HTML del blocco o ''.
         */
        public static function citations_block( $article, $max = 8 ) {
                if ( ! is_object( $article ) || empty( $article->citations ) ) {
                        return '';
                }

                $max     = min( 8, max( 1, absint( $max ) ) );
                $decoded = json_decode( (string) $article->citations, true );
                if ( ! is_array( $decoded ) ) {
                        return '';
                }

                /* Normalizzazione difensiva: {claim, source} validi e non vuoti. */
                $items = array();
                foreach ( $decoded as $citation ) {
                        if ( count( $items ) >= $max ) {
                                break;
                        }
                        $claim  = '';
                        $source = '';
                        if ( is_array( $citation ) ) {
                                $claim  = trim( (string) ( $citation['claim'] ?? '' ) );
                                $source = trim( (string) ( $citation['source'] ?? '' ) );
                        } elseif ( is_object( $citation ) ) {
                                $claim  = trim( (string) ( $citation->claim ?? '' ) );
                                $source = trim( (string) ( $citation->source ?? '' ) );
                        } elseif ( is_string( $citation ) ) {
                                $claim = trim( $citation );
                        }
                        if ( '' === $claim && '' === $source ) {
                                continue;
                        }
                        $items[] = array(
                                'claim'  => $claim,
                                'source' => $source,
                        );
                }

                if ( ! $items ) {
                        return '';
                }

                $out  = '<div class="nexus-citations">';
                $out .= '<h4>' . esc_html__( 'Fonti verificate', 'nexus-news-ai' ) . '</h4>';
                $out .= '<ul>';
                foreach ( $items as $item ) {
                        $out .= '<li>';
                        if ( '' !== $item['claim'] ) {
                                $out .= esc_html( $item['claim'] );
                        }
                        if ( '' !== $item['source'] ) {
                                if ( '' !== $item['claim'] ) {
                                        $out .= ' — ';
                                }
                                $out .= '<a href="' . esc_url( $item['source'] ) . '" rel="noopener nofollow" target="_blank">'
                                        . esc_html__( 'fonte', 'nexus-news-ai' )
                                        . '</a>';
                        }
                        $out .= '</li>';
                }
                $out .= '</ul>';
                $out .= '</div>';

                return $out;
        }

        /* ========================= [nexus_articles] ========================= */

        /**
         * Griglia o lista semplice di card su articoli pubblicati.
         *
         * Attributi: category (default ''), limit (default 9, max 24),
         * layout (grid|list), redazione (default '', slug o nome, v1.1.1) e
         * lang (default '', v1.1.2, driver nexus): filtra sul ramo completo
         * della redazione (semantica nativa include_children) e/o sulla lingua
         * richiesta (meta _nexus_lang sui post WordPress; i post SENZA meta
         * sono della lingua predefinita). Esempi:
         * [nexus_articles redazione="sport" limit="6"] mostra gli articoli di
         * Sport e di Calcio, Basket, F1; [nexus_articles lang="en"] mostra gli
         * articoli in inglese. Redazione o lingua inesistenti → messaggio
         * onesto, senza contenuto inventato. Con Polylang o WPML l'attributo
         * lang e' accettato e ignorato: i contenuti sono gia' filtrati per
         * lingua corrente dai plugin (nota v1.1.2 in testa al file).
         *
         * @param array|string $atts Attributi.
         * @return string
         */
        public static function render_articles( $atts = array() ) {
                $atts = shortcode_atts(
                        array(
                                'category'  => '',
                                'limit'     => 9,
                                'layout'    => 'grid',
                                'redazione' => '',
                                'lang'      => '',
                        ),
                        (array) $atts,
                        'nexus_articles'
                );

                $limit    = min( 24, max( 1, absint( $atts['limit'] ) ) );
                $category = sanitize_text_field( (string) $atts['category'] );
                $layout   = ( 'list' === strtolower( (string) $atts['layout'] ) ) ? 'list' : 'grid';

                /* v1.1.1: filtro redazione (slug o nome), semantica subtree. */
                $redazione      = sanitize_text_field( (string) $atts['redazione'] );
                $redazione_term = ( '' !== $redazione ) ? self::redazione_term( $redazione ) : null;

                /* Redazione richiesta ma inesistente: output onesto, nessun contenuto inventato. */
                if ( '' !== $redazione && ! $redazione_term instanceof WP_Term ) {
                        return '<div class="nxa-articles" data-nxa="articles"><p class="nxa-empty">'
                                . esc_html( sprintf(
                                        /* translators: %s: redazione richiesta. */
                                        __( 'Redazione non trovata: %s', 'nexus-news-ai' ),
                                        $redazione
                                ) )
                                . '</p></div>';
                }

                /* v1.1.2: filtro lingua (driver nexus), stesso stato del magazine. */
                $lang_state = self::lang_filter_state( $atts['lang'] );

                /* Lingua richiesta inesistente: output onesto, nessun contenuto inventato. */
                if ( $lang_state['unknown'] ) {
                        return '<div class="nxa-articles" data-nxa="articles"><p class="nxa-empty">'
                                . esc_html( sprintf(
                                        /* translators: %s: codice lingua richiesto. */
                                        __( 'Lingua non trovata: %s', 'nexus-news-ai' ),
                                        $lang_state['lang']
                                ) )
                                . '</p></div>';
                }

                self::enqueue_assets();

                if ( $redazione_term instanceof WP_Term ) {
                        /* Ramo completo della redazione (sub-redazioni incluse). */
                        $branch_post_ids = self::redazione_branch_post_ids( $redazione_term );
                        if ( $lang_state['active'] ) {
                                /* Intersezione ramo redazione e lingua richiesta (v1.1.2). */
                                $branch_post_ids = array_values( array_intersect( $branch_post_ids, $lang_state['post_ids'] ) );
                        }
                        $articles = self::redazione_articles( $branch_post_ids, $limit );
                } elseif ( $lang_state['active'] ) {
                        /* Filtro lingua senza redazione (v1.1.2): gli helper per post ID
                         * del filtro redazione sono generici e vengono riusati qui. */
                        $articles = self::redazione_articles( $lang_state['post_ids'], $limit );
                } else {
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
                }

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

        /* ========================= [nexus_lang_switcher] (v1.1.2) ========================= */

        /**
         * Selettore lingue del sito (v1.1.2): elenco lingue del driver attivo
         * di Nexus_Multilang (Polylang, WPML o collegamento interno nexus).
         *
         * Markup: ul.nexus-lang-switcher con li.nexus-lang-item (data-lang =
         * codice lingua); la lingua corrente porta la classe nexus-lang-current
         * e aria-current sul link. Su un singolo articolo di tipo post i link
         * puntano al permalink della traduzione PUBBLICATA (Nexus_Multilang::
         * translations_of): una lingua senza traduzione pubblica diventa uno
         * span non cliccabile con title onesto "Traduzione non disponibile"
         * (niente percorsi rotti verso bozze). Fuori dal singular il link e'
         * la home con query var ?lang={code}: per il driver nexus la variabile
         * e' consumata da filter_main_query_by_lang(); con Polylang o WPML lo
         * shortcode mostra comunque l'elenco lingue del driver, ma il
         * comportamento dei link sugli archivi resta ai plugin (usare il loro
         * switcher se preferiscono altri formati di URL, nota in testa al file).
         * Senza la classe Nexus_Multilang l'output e' vuoto: onesto, mai fatale.
         *
         * @param array|string $atts Attributi shortcode (nessuno supportato).
         * @return string
         */
        public static function render_lang_switcher( $atts = array() ) {
                /* Guard: senza il modulo multilingua output vuoto onesto. */
                if ( ! class_exists( 'Nexus_Multilang' ) ) {
                        return '';
                }

                /* Punto di estensione: nessun attributo supportato al momento. */
                shortcode_atts( array(), (array) $atts, 'nexus_lang_switcher' );

                $langs = Nexus_Multilang::site_langs();
                $langs = is_array( $langs ) ? $langs : array();
                if ( empty( $langs ) ) {
                        return '';
                }

                self::enqueue_assets();

                /* Su un singolo articolo la lingua corrente e' quella del post stesso. */
                $post_id = ( is_singular( 'post' ) ) ? absint( get_queried_object_id() ) : 0;
                $current = ( $post_id > 0 ) ? Nexus_Multilang::lang_of_post( $post_id ) : Nexus_Multilang::current_lang();

                /* Traduzioni del post corrente (mappa codice lingua - post ID, includo il post stesso). */
                $translations = ( $post_id > 0 ) ? Nexus_Multilang::translations_of( $post_id ) : array();
                $translations = is_array( $translations ) ? $translations : array();

                $out  = '';
                $out .= '<ul class="nexus-lang-switcher">';

                foreach ( $langs as $lang ) {
                        $lang = sanitize_key( (string) $lang );
                        if ( '' === $lang ) {
                                continue;
                        }

                        $is_current = ( $lang === $current );
                        $classes    = 'nexus-lang-item' . ( $is_current ? ' nexus-lang-current' : '' );

                        $out .= '<li class="' . esc_attr( $classes ) . '" data-lang="' . esc_attr( $lang ) . '">';

                        /* URL della lingua: permalink della traduzione pubblicata
                         * (singular) oppure home con query var lang (listing e archivi). */
                        $url = '';
                        if ( $post_id > 0 ) {
                                $pid = isset( $translations[ $lang ] ) ? absint( $translations[ $lang ] ) : 0;
                                if ( $pid > 0 && get_post( $pid ) instanceof WP_Post && 'publish' === get_post_status( $pid ) ) {
                                        $permalink = get_permalink( $pid );
                                        if ( is_string( $permalink ) && '' !== $permalink ) {
                                                $url = $permalink;
                                        }
                                }
                        } else {
                                /* Driver nexus: la query var lang e' consumata dal filtro
                                 * pre_get_posts di questa classe. Polylang e WPML gestiscono
                                 * gli URL degli archivi a modo loro: qui il link resta la
                                 * home con la query var, il loro switcher ufficiale puo'
                                 * essere usato in alternativa (nota in testa al file). */
                                $url = add_query_arg( 'lang', $lang, home_url( '/' ) );
                        }

                        if ( '' !== $url ) {
                                $out .= '<a href="' . esc_url( $url ) . '"' . ( $is_current ? ' aria-current="true"' : '' ) . '>' . esc_html( Nexus_Multilang::lang_name( $lang ) ) . '</a>';
                        } else {
                                $out .= '<span class="nexus-lang-missing" title="' . esc_attr__( 'Traduzione non disponibile', 'nexus-news-ai' ) . '">' . esc_html( Nexus_Multilang::lang_name( $lang ) ) . '</span>';
                        }

                        $out .= '</li>';
                }

                $out .= '</ul>';

                return $out;
        }

        /* ========================= Filtro lingua main query (v1.1.2) ========================= */

        /**
         * Registra la query var pubblica 'lang' (v1.1.2, solo driver nexus).
         *
         * Senza la registrazione ?lang=en non arriverebbe alla main query di
         * WordPress. Con Polylang o WPML la variabile la gestiscono i plugin:
         * nessuna registrazione (se fosse gia' presente resta intatta).
         *
         * @param array $vars Query var pubbliche.
         * @return array
         */
        public static function register_lang_query_var( $vars ) {
                if ( class_exists( 'Nexus_Multilang' ) && 'nexus' === Nexus_Multilang::driver() && ! in_array( 'lang', (array) $vars, true ) ) {
                        $vars[] = 'lang';
                }

                return $vars;
        }

        /**
         * Filtro lingua della main query (v1.1.2, solo driver nexus).
         *
         * pre_get_posts: solo front-end, solo main query, MAI admin. La query
         * var lang viene sanificata con sanitize_key e accettata solo se
         * presente tra le lingue del sito (Nexus_Multilang::site_langs()).
         * Il filtro si applica ai listing di post (home, archivi e ricerca con
         * post_type nativo o non impostato): mai a singular, pagine o archivi
         * di altri tipi di post. Con Polylang o WPML nessun intervento: i
         * plugin filtrano gia' da soli.
         *
         * Clausola meta (v. lang_meta_clauses): i post SENZA meta _nexus_lang
         * sono nella lingua predefinita del sito, quindi per la lingua
         * predefinita la meta_query e' relation OR tra NOT EXISTS e valore
         * = lang; per le altre lingue basta la clausola "=" lang (altrimenti
         * i post della lingua predefinita finirebbero in ogni archivio). La
         * clausola si APPENDA a un eventuale meta_query preesistente senza
         * sovrascriverne la relation (stesso pattern del filtro redazione 1.1.1).
         *
         * @param WP_Query $query Query corrente.
         * @return void
         */
        public static function filter_main_query_by_lang( $query ) {
                if ( is_admin() || ! $query->is_main_query() ) {
                        return;
                }

                if ( ! class_exists( 'Nexus_Multilang' ) || 'nexus' !== Nexus_Multilang::driver() ) {
                        return;
                }

                $lang = sanitize_key( (string) $query->get( 'lang' ) );
                if ( '' === $lang || ! in_array( $lang, Nexus_Multilang::site_langs(), true ) ) {
                        return;
                }

                /* Solo listing di post: singular, pagine e altri post type restano fuori. */
                $post_type = $query->get( 'post_type' );
                if ( '' !== $post_type && 'post' !== $post_type ) {
                        return;
                }
                if ( ! ( $query->is_home() || $query->is_archive() || $query->is_search() ) ) {
                        return;
                }

                $clauses = self::lang_meta_clauses( $lang );
                if ( empty( $clauses ) ) {
                        return;
                }

                /* Merge attento: la clausola lingua si APPENDA all'eventuale
                 * meta_query esistente, senza sovrascriverne la relation. */
                $meta_query = $query->get( 'meta_query' );
                $meta_query = is_array( $meta_query ) ? $meta_query : array();

                if ( empty( $meta_query ) ) {
                        $meta_query = $clauses;
                } elseif ( isset( $meta_query['key'] ) || isset( $meta_query['value'] ) ) {
                        /* Clausola singola piatta: incapsulata prima dell'append. */
                        $flat                   = $meta_query;
                        $meta_query             = array( $flat, $clauses );
                        $meta_query['relation'] = 'AND';
                } else {
                        $meta_query[] = $clauses;
                        if ( count( $meta_query ) > 1 && empty( $meta_query['relation'] ) ) {
                                $meta_query['relation'] = 'AND';
                        }
                }

                $query->set( 'meta_query', $meta_query );
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

        /* ========================= Redazioni (v1.1.1) ========================= */

        /**
         * Risolve il termine redazione da slug o nome (v1.1.1).
         *
         * Ordine di ricerca: slug (sanitize_title del valore) e poi nome esatto
         * cosi' come digitato (il collation del DB e' case-insensitive, quindi
         * "Sport" matcha anche il nome "Redazione Sport" solo se identico).
         * Guard: la classe fondamenta e la tassonomia devono esistere,
         * altrimenti null (degradazione senza fatal, contratto §0.7).
         *
         * @param string $value Slug o nome della redazione.
         * @return WP_Term|null
         */
        private static function redazione_term( $value ) {
                $value = trim( sanitize_text_field( (string) $value ) );
                if ( '' === $value || ! class_exists( 'Nexus_Redazioni' ) || ! taxonomy_exists( Nexus_Redazioni::TAX ) ) {
                        return null;
                }

                $term = get_term_by( 'slug', sanitize_title( $value ), Nexus_Redazioni::TAX );
                if ( ! $term instanceof WP_Term ) {
                        $term = get_term_by( 'name', $value, Nexus_Redazioni::TAX );
                }

                return ( $term instanceof WP_Term ) ? $term : null;
        }

        /**
         * Clausola tax_query nativa del ramo redazione (v1.1.1).
         *
         * include_children => true: la redazione madre copre anche le
         * sub-redazioni (Sport include Calcio, Basket e F1), identico alla
         * semantica delle categorie gerarchiche di WordPress. La clausola e'
         * restituita singola cosi' puo' essere APPENDATA a un eventuale
         * tax_query preesistente con relation AND, senza sovrascriverlo.
         *
         * @param int $term_id Term ID della redazione.
         * @return array Clausola pronta per WP_Query.
         */
        private static function redazione_tax_clause( $term_id ) {
                return array(
                        'taxonomy'         => Nexus_Redazioni::TAX,
                        'field'            => 'term_id',
                        'terms'            => absint( $term_id ),
                        'include_children' => true,
                );
        }

        /**
         * v1.1.18 (FIX-10) — cap di post ID per le query dei filtri
         * redazione/lingua. Default 100 (BRANCH_ID_CAP_DEFAULT), filtrabile
         * con 'nexus_shortcode_branch_cap', SEMPRE clampato a [1,
         * BRANCH_ID_CAP_MAX=500] per proteggere il DB.
         *
         * Perché il cap basta (nota onesta): la lista alimenta
         * redazione_articles() — che espone al più per_page/limit ≤ 24 righe,
         * ordinate per published_at DESC: le righe più recenti stanno tra i
         * post ID più recenti della query (default orderby date DESC) — e
         * redazione_count_articles() per il contatore dell'hero. Con più di
         * `cap` post pubblicati nel ramo/lingua il contatore mostra il totale
         * del sottoinsieme (gli ID oltre il cap non entrano nella lista): i
         * siti con rami molto più grandi del cap possono alzare il filtro
         * fino al tetto di 500.
         *
         * @return int
         */
        private static function branch_id_cap() {
                $cap = (int) apply_filters( 'nexus_shortcode_branch_cap', self::BRANCH_ID_CAP_DEFAULT );
                return max( 1, min( self::BRANCH_ID_CAP_MAX, $cap ) );
        }

        /**
         * Post ID del ramo redazione: termine e tutti i discendenti (v1.1.1).
         *
         * WP_Query nativa con la clausola tax_query include_children; i tipi di
         * post sono quelli dual-mode del plugin (nativo 'post' piu' il CPT
         * legacy nexus_article) via Nexus_Native::query_post_types() con guard
         * class_exists. Solo post pubblicati, cache di term e meta disattivate:
         * servono solo gli ID per collegare le righe articoli della tabella
         * Nexus (colonna wp_post_id) ai post WordPress del ramo.
         *
         * v1.1.18 (FIX-10): posts_per_page = -1 (lista illimitata, O(n) per
         * pageview) → cap branch_id_cap() (default 100 ID più recenti, filtro
         * 'nexus_shortcode_branch_cap', tetto 500): la lista alimenta solo la
         * join/contatore nexus_articles, che espone al più per_page ≤ 24 righe.
         * ignore_sticky_posts: gli ID servono in ordine di data (gli sticky
         * non devono sottrarre posto ai più recenti entro il cap).
         *
         * @param WP_Term $term Termine redazione.
         * @return array Post ID (int).
         */
        private static function redazione_branch_post_ids( $term ) {
                $term_id = ( $term instanceof WP_Term ) ? absint( $term->term_id ) : 0;
                if ( $term_id < 1 || ! class_exists( 'Nexus_Redazioni' ) ) {
                        return array();
                }

                $post_types = array( 'post', 'nexus_article' );
                if ( class_exists( 'Nexus_Native' ) && method_exists( 'Nexus_Native', 'query_post_types' ) ) {
                        $post_types = Nexus_Native::query_post_types();
                }

                /*
                 * Merge attento: la clausola redazione si APPENDA a eventuali
                 * clausole gia' presenti (qui la query nasce da zero, quindi e'
                 * l'unica) e con piu' di una clausola l'operatore resta AND:
                 * mai una sovrascrittura dell'operatore esistente.
                 */
                $tax_query = array();
                $tax_query[] = self::redazione_tax_clause( $term_id );
                if ( count( $tax_query ) > 1 ) {
                        $tax_query['relation'] = 'AND';
                }

                $query = new WP_Query(
                        array(
                                'post_type'              => $post_types,
                                'post_status'            => 'publish',
                                'fields'                 => 'ids',
                                // v1.1.18 (FIX-10): cap invece di -1 (default 100,
                                // filtro 'nexus_shortcode_branch_cap', tetto 500):
                                // vedi branch_id_cap().
                                'posts_per_page'         => self::branch_id_cap(),
                                'ignore_sticky_posts'    => true,
                                'no_found_rows'          => true,
                                'update_post_term_cache' => false,
                                'update_post_meta_cache' => false,
                                'tax_query'              => $tax_query,
                        )
                );

                return array_values( array_map( 'absint', (array) $query->posts ) );
        }

        /**
         * Righe articoli Nexus collegate a un insieme di post WordPress (v1.1.1;
         * da v1.1.2 helper generico riusato anche dal filtro lingua).
         *
         * Le righe della tabella articoli Nexus puntano al post WordPress
         * (nativo o CPT legacy) tramite la colonna wp_post_id: il filtro per
         * ramo e' quindi un IN sui Post ID del subtree. Query preparata con
         * wpdb prepare (LIMIT e OFFSET come placeholder), ID assolutizzati e
         * tabella letta da Nexus_DB::tables(): nessuna tabella nuova.
         *
         * @param array $post_ids Post ID del ramo.
         * @param int   $limit    Numero massimo di righe.
         * @param int   $offset   Offset di paginazione.
         * @return array Righe articolo (stesso shape di Nexus_DB::articles()).
         */
        private static function redazione_articles( $post_ids, $limit, $offset = 0 ) {
                global $wpdb;

                $post_ids = array_values( array_filter( array_map( 'absint', (array) $post_ids ) ) );
                $limit    = max( 1, absint( $limit ) );
                $offset   = max( 0, absint( $offset ) );
                if ( empty( $post_ids ) || ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'tables' ) ) {
                        return array();
                }

                $tables = Nexus_DB::tables();
                if ( ! is_array( $tables ) || empty( $tables['articles'] ) ) {
                        return array();
                }

                $table  = $tables['articles'];
                $ids_in = implode( ',', $post_ids );
                $sql    = "SELECT * FROM {$table}"
                        . " WHERE status = 'published' AND wp_post_id IN ( {$ids_in} )"
                        . " ORDER BY published_at DESC, id DESC"
                        . " LIMIT %d OFFSET %d";

                $rows = $wpdb->get_results( $wpdb->prepare( $sql, $limit, $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ID assolutizzati, LIMIT e OFFSET via prepare.
                return is_array( $rows ) ? $rows : array();
        }

        /**
         * Conta le righe articoli Nexus collegate a un insieme di post (v1.1.1;
         * da v1.1.2 helper generico riusato anche dal filtro lingua).
         *
         * @param array $post_ids Post ID del ramo o della lingua.
         * @return int
         */
        private static function redazione_count_articles( $post_ids ) {
                global $wpdb;

                $post_ids = array_values( array_filter( array_map( 'absint', (array) $post_ids ) ) );
                if ( empty( $post_ids ) || ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'tables' ) ) {
                        return 0;
                }

                $tables = Nexus_DB::tables();
                if ( ! is_array( $tables ) || empty( $tables['articles'] ) ) {
                        return 0;
                }

                $table  = $tables['articles'];
                $ids_in = implode( ',', $post_ids );
                $sql    = "SELECT COUNT(*) FROM {$table}"
                        . " WHERE status = 'published' AND wp_post_id IN ( {$ids_in} )";

                return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ID assolutizzati, nessun placeholder.
        }

        /* ========================= Multilingua: helper filtro lang (v1.1.2) ========================= */

        /**
         * Stato del filtro lingua richiesto da shortcode (v1.1.2).
         *
         * Ritorna un array associativo con:
         *   active   — true se il filtro si applica (driver nexus e lingua valida);
         *   lang     — codice lingua richiesto, sanificato con sanitize_key ('' se assente);
         *   post_ids — post ID WordPress degli articoli pubblicati in quella lingua;
         *   unknown  — true se la lingua richiesta NON esiste tra le lingue del
         *              sito (output onesto, nessun contenuto inventato).
         *
         * Senza la classe Nexus_Multilang l'attributo resta senza effetto
         * (nessun filtro, listing standard: zero regressioni). Con Polylang o
         * WPML il filtro non si applica: i contenuti sono gia' filtrati per
         * lingua corrente dai plugin (nota v1.1.2 in testa al file).
         *
         * @param string $raw_lang Valore grezzo dell'attributo lang.
         * @return array
         */
        private static function lang_filter_state( $raw_lang ) {
                $state = array(
                        'active'   => false,
                        'lang'     => '',
                        'post_ids' => array(),
                        'unknown'  => false,
                );

                $lang = sanitize_key( (string) $raw_lang );
                if ( '' === $lang ) {
                        return $state;
                }
                $state['lang'] = $lang;

                if ( ! class_exists( 'Nexus_Multilang' ) || 'nexus' !== Nexus_Multilang::driver() ) {
                        return $state;
                }

                if ( ! in_array( $lang, Nexus_Multilang::site_langs(), true ) ) {
                        $state['unknown'] = true;
                        return $state;
                }

                $state['active']   = true;
                $state['post_ids'] = self::lang_post_ids( $lang );

                return $state;
        }

        /**
         * Clausole meta_query del filtro lingua (driver nexus, v1.1.2).
         *
         * I post SENZA meta _nexus_lang sono nella lingua predefinita del sito:
         * per la lingua predefinita la clausola e' relation OR tra NOT EXISTS
         * (meta assente) e valore = lang (i post senza meta sono inclusi);
         * per le altre lingue basta la clausola "=" lang, altrimenti i post
         * della lingua predefinita finirebbero in ogni archivio. Ritorna
         * array() se il filtro non e' applicabile (classe assente o lingua non
         * tra le lingue del sito). Clausole pronte per essere APPENDATE a un
         * eventuale meta_query preesistente, senza sovrascriverne la relation.
         *
         * Speculare all'helper omonimo di Nexus_Public_API: i due file sono
         * scope separati del contratto, quindi la logica e' duplicata di proposito.
         *
         * @param string $lang Codice lingua.
         * @return array Clausole meta_query o array().
         */
        private static function lang_meta_clauses( $lang ) {
                $lang = sanitize_key( (string) $lang );
                if ( '' === $lang || ! class_exists( 'Nexus_Multilang' ) ) {
                        return array();
                }
                if ( ! in_array( $lang, Nexus_Multilang::site_langs(), true ) ) {
                        return array();
                }

                $equals = array(
                        'key'     => Nexus_Multilang::META_LANG,
                        'value'   => $lang,
                        'compare' => '=',
                );

                if ( $lang === Nexus_Multilang::default_lang() ) {
                        return array(
                                'relation' => 'OR',
                                array(
                                        'key'     => Nexus_Multilang::META_LANG,
                                        'compare' => 'NOT EXISTS',
                                ),
                                $equals,
                        );
                }

                return array( $equals );
        }

        /**
         * Post ID WordPress degli articoli pubblicati in una lingua (v1.1.2).
         *
         * WP_Query nativa con le clausole meta del filtro lingua sui tipi di
         * post dual-mode del plugin (nativo 'post' piu' il CPT legacy
         * nexus_article) via Nexus_Native::query_post_types() con guard
         * class_exists. Solo post pubblicati, cache di term e meta disattivate:
         * servono solo gli ID per filtrare le righe della tabella articoli
         * (colonna wp_post_id), identico al flusso del filtro redazione 1.1.1.
         *
         * v1.1.18 (FIX-10): posts_per_page = -1 (lista illimitata, O(n) per
         * pageview) → cap branch_id_cap() (default 100 ID più recenti, filtro
         * 'nexus_shortcode_branch_cap', tetto 500), stessa motivazione e
         * stessa nota onesta del filtro redazione. ignore_sticky_posts: gli
         * ID servono in ordine di data (gli sticky non devono sottrarre
         * posto ai più recenti entro il cap).
         *
         * @param string $lang Codice lingua.
         * @return array Post ID (int).
         */
        private static function lang_post_ids( $lang ) {
                $clauses = self::lang_meta_clauses( $lang );
                if ( empty( $clauses ) ) {
                        return array();
                }

                $post_types = array( 'post', 'nexus_article' );
                if ( class_exists( 'Nexus_Native' ) && method_exists( 'Nexus_Native', 'query_post_types' ) ) {
                        $post_types = Nexus_Native::query_post_types();
                }

                $query = new WP_Query(
                        array(
                                'post_type'              => $post_types,
                                'post_status'            => 'publish',
                                'fields'                 => 'ids',
                                // v1.1.18 (FIX-10): cap invece di -1 (default 100,
                                // filtro 'nexus_shortcode_branch_cap', tetto 500):
                                // vedi branch_id_cap().
                                'posts_per_page'         => self::branch_id_cap(),
                                'ignore_sticky_posts'    => true,
                                'no_found_rows'          => true,
                                'update_post_term_cache' => false,
                                'update_post_meta_cache' => false,
                                'meta_query'             => $clauses,
                        )
                );

                return array_values( array_map( 'absint', (array) $query->posts ) );
        }
}
