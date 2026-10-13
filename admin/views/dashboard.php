<?php
/**
 * Vista: Dashboard (skeleton HTML) — riempita da assets/js/admin.js.
 *
 * CONTRATTO CON admin.js (altro agente):
 * - Container root: <div class="wrap nexus-admin" data-page="dashboard">.
 *   admin.js rileva la pagina dall'attributo data-page e inizializza il binding.
 * - Statistiche (GET {restUrl}/stats → chiavi: published, pending, draft, today,
 *   agents_active, views). admin.js scrive il valore nel testo di ciascun nodo:
 *     #nxa-stat-published ← stats.published    (articoli pubblicati)
 *     #nxa-stat-pending   ← stats.pending      (in coda di approvazione)
 *     #nxa-stat-draft     ← stats.draft        (bozze)
 *     #nxa-stat-today     ← stats.today        (articoli creati oggi)
 *     #nxa-stat-agents    ← stats.agents_active (agenti attivi)
 *     #nxa-stat-views     ← stats.views        (visualizzazioni totali)
 *   Placeholder iniziale di ogni card: "—" (em dash), dentro .nxa-stat-value.
 * - Bottoni azione rapida nella .nxa-toolbar (delegazione eventi sui seguenti
 *   selettori):
 *     .nxa-run-pipeline → POST {restUrl}/pipeline/run     body JSON {agent_id: 0}  (pipeline completa, tutti gli agenti)
 *     .nxa-collect-all  → POST {restUrl}/pipeline/collect body JSON {agent_id: 0}  (raccolta RSS immediata, tutti gli agenti)
 *   Autenticazione: header X-WP-Nonce con NexusAdminData.nonce.
 * - Feed attività: #nxa-activity-feed ← GET {restUrl}/activity (eventi recenti;
 *   admin.js sostituisce lo skeleton iniziale).
 * - Stato agenti:  #nxa-agents-mini    ← GET {restUrl}/agents   (griglia mini
 *   nome+stato; admin.js sostituisce lo skeleton iniziale).
 * - Uso AI (v1.0.3): #nexus-usage-panel ← dati INLINE (zero rotte REST nuove):
 *     data-usage = wp_json_encode( Nexus_DB::usage_stats('month') )  → {totals:{calls,prompt_tokens,completion_tokens,total_tokens,cost}, by_agent:[{agent_id,calls,total_tokens,cost}]}
 *     data-budget = budget mensile corrente (settings monthly_budget_usd, 0 = nessun limite).
 *   admin.js renderizza dentro il contenitore: stat-card (chiamate/token/costo
 *   $X.XXXX), tabella per-agente (nomi/avatar dalla cache agenti, fallback
 *   "Agente #id") e barra budget con soglie colore 60%/90%.
 * - Link "Vedi magazine": apre home_url('/') in nuova scheda (NexusAdminData.siteUrl).
 *
 * v1.0.6 — markup allineato al design system a tema chiaro: toolbar azioni
 * (.nxa-toolbar + .nxa-btn), griglia statistiche (.nxa-stat-grid), placeholder
 * di caricamento (.nxa-skeleton) e stati vuoti (.nxa-empty) per il fallback
 * no-JS. Nessuna modifica a id, data-* e selettori consumati da admin.js.
 *
 * v1.0.7 — Audit overflow mobile (contratto §2 T-06): la vista NON contiene
 * tabelle statiche, quindi nessun wrapper .nxa-table-wrap è necessario nel
 * markup PHP: l'unica tabella dati (uso AI per agente) è generata da admin.js
 * dentro #nexus-usage-panel ed è già avvolta lato JS in un contenitore
 * overflow-x:auto. Feed attività e mini agenti sono markup generato da
 * admin.js: il word-break/overflow è gestito dal CSS condiviso
 * (.nxa-activity-item p ha overflow-wrap:anywhere; la griglia agenti è fluida).
 * Nessun id, data-* o selettore consumato da admin.js modificato.
 *
 * v1.1.0 — Contratto "Tutto nativo" (T-06): nella griglia statistiche è
 * presente una stat-card server-side aggiuntiva "Contenuti nativi". Nessun
 * id nuovo consumato da admin.js: il JS scrive solo sui nodi #nxa-stat-*
 * elencati qui sotto, quindi i valori di questa card non vengono sovrascritti.
 * Primo numero: articoli Nexus collegati a un post WordPress nativo (COUNT
 * sulla tabella articoli con wp_post_id maggiore di 0, query difensiva via
 * $wpdb e Nexus_DB::tables(), guard su classe e metodo). Secondo numero
 * (mostrato solo se la classe esiste): articoli del vecchio CPT ancora da
 * migrare, via Nexus_Native::cpt_remaining() con guard class_exists e
 * method_exists. Nessun JavaScript nuovo.
 *
 * v1.1.1 — Contratto "Redazioni gerarchiche" (T-07): nella griglia statistiche
 * e' presente una stat-card server-side aggiuntiva "Redazioni". Nessun id nuovo
 * consumato da admin.js: il JS scrive solo sui nodi con id nxa-stat-* elencati
 * qui sopra, quindi i valori di questa card non vengono sovrascritti. Tutti i
 * numeri arrivano da chiamate PHP dirette agli helper di Nexus_Redazioni
 * (nessuna rotta REST nuova): N redazioni totali, N agenti assegnati distinti
 * su tutti i termini, top 3 redazioni per articoli nel subtree (i termini con
 * zero articoli sono esclusi) con chip degli agenti del ramo (max 4, poi il
 * segno piu' e il numero residuo). Con zero redazioni la card mostra un
 * invito con link alla pagina Redazioni. Guard class_exists: senza la classe
 * fondamenta la card non viene stampata (degradazione senza fatal).
 *
 * v1.1.2 — Contratto "Multilingua nativo" (T-07): nella griglia statistiche e'
 * presente una stat-card server-side aggiuntiva "Multilingua" (stesso pattern
 * visuale della card Redazioni). Nessun id nuovo consumato da admin.js: il JS
 * scrive solo sui nodi con id nxa-stat-*, quindi i valori di questa card non
 * vengono sovrascritti. Tutti i dati arrivano da chiamate PHP dirette a
 * Nexus_Multilang (nessuna rotta REST nuova): badge del driver attivo
 * (Polylang, WPML o collegamento interno Nexus) con testo breve, e per ogni
 * voce di Nexus_Multilang::stats_counts() una chip "Lingua — N articoli"
 * (etichetta da lang_name, plurale con _n). Con zero traduzioni la card
 * mostra uno stato vuoto onesto ("Nessun post tradotto ancora.") con link
 * alla lista Articoli. Guard class_exists: senza la classe fondamenta la
 * card non viene stampata (degradazione senza fatal).
 *
 * v1.1.5 — Contratto "Respira" (T-04): nella toolbar arriva il bottone
 * "Avvia testata" (classe nxa-btn-run, data-run-testata="1") che avvia
 * l'intera testata con POST pipeline/run {agent_id:0} dopo conferma inline in
 * admin.js (v1.1.13.1 Q2, niente window.confirm nativo: "Avviare l'intera
 * testata? Verranno avviati N agenti."); dentro
 * la sezione nxa-section-activity il contenitore vuoto #nxa-live-strip ospita
 * il pannello live "In redazione ora" renderizzato da admin.js (GET /live +
 * polling 5 secondi solo con la pagina visibile; endpoint assente → strip
 * nascosta in silenzio, nessun titolo orfano). Il bottone storico
 * .nxa-run-pipeline e il feed #nxa-activity-feed restano intatti.
 *
 * v1.1.11 — ZERO AGENTI DEFAULT + WIZARD (Task R3): con la redazione VUOTA
 * (Nexus_DB::agents() senza righe, guard class_exists/method_exists) sopra
 * la griglia statistiche compare la card CTA "Crea la tua redazione": porta
 * alla pagina Agenti con hash #nxa-onboarding, che admin.js (sezione
 * v1.1.11) usa per aprire in automatico il wizard di onboarding. Il link è
 * un semplice anchor: nessun JS nuovo in questa vista.
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

$nexus_settings = Nexus_DB::get_settings();
// v1.1.5: tagline WP nativa (blogdescription) — la sezione "Identità del sito" è
// stata rimossa dalle impostazioni; fallback BC al vecchio setting se WP è vuoto.
$nexus_tagline  = trim( (string) get_bloginfo( 'description' ) );
if ( '' === $nexus_tagline ) {
        $nexus_tagline = isset( $nexus_settings['site_tagline'] ) ? (string) $nexus_settings['site_tagline'] : '';
}

/*
 * v1.1.11 (R3) — Card CTA "Crea la tua redazione": visibile SOLO con la
 * redazione vuota (0 agenti). Il wizard vive nella pagina Agenti
 * (modal #nxa-onboarding): qui c'è solo l'invito con l'hash che admin.js
 * usa per aprirlo in automatico. Guard complete: senza le classi fondamenta
 * la card non viene stampata (degradazione senza fatal).
 */
$nexus_agents_count = 0;
if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'agents' ) ) {
        $nexus_agents_count = count( (array) Nexus_DB::agents() );
}

/*
 * v1.1.0 (T-06) — Contenuti nativi: primo numero = articoli Nexus collegati
 * a un post WordPress nativo (wp_post_id maggiore di 0, COUNT difensivo via
 * $wpdb e Nexus_DB::tables()); secondo numero = articoli del vecchio CPT
 * ancora da migrare (Nexus_Native::cpt_remaining()). Guard difensive: se le
 * classi/metodi non esistono la card mostra solo il primo numero (o zero).
 */
$nexus_native_linked    = 0;
$nexus_native_remaining = null;
if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'tables' ) ) {
        $nexus_tables = Nexus_DB::tables();
        if ( is_array( $nexus_tables ) && ! empty( $nexus_tables['articles'] ) ) {
                global $wpdb;
                $nexus_native_linked = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$nexus_tables['articles']} WHERE wp_post_id > 0" );
        }
}
if ( class_exists( 'Nexus_Native' ) && method_exists( 'Nexus_Native', 'cpt_remaining' ) ) {
        $nexus_native_remaining = (int) Nexus_Native::cpt_remaining();
}

/*
 * v1.1.1 (T-07) — Card "Redazioni": numeri e top 3 letti in PHP diretto dagli
 * helper di Nexus_Redazioni (contratto §1: nessuna rotta REST nuova). La mappa
 * agenti serve per nomi ed emoji delle chip (stesso fallback della card uso AI:
 * senza riga l'agente viene indicato con il suo ID).
 */
$nexus_red_terms     = array();
$nexus_red_agents    = 0;
$nexus_red_top       = array();
$nexus_red_agent_map = array();
if ( class_exists( 'Nexus_Redazioni' ) ) {
        $nexus_red_terms = Nexus_Redazioni::all_terms();

        /* Agenti assegnati distinti su tutti i termini (assegnazione diretta). */
        $nexus_red_all_ids = array();
        foreach ( $nexus_red_terms as $nexus_red_term ) {
                $nexus_red_all_ids = array_merge( $nexus_red_all_ids, Nexus_Redazioni::agent_ids_for_term( (int) $nexus_red_term->term_id ) );
        }
        $nexus_red_agents = count( array_unique( array_map( 'absint', $nexus_red_all_ids ) ) );

        /* Top 3 per articoli nel subtree: i termini senza articoli sono esclusi. */
        $nexus_red_ranked = array();
        foreach ( $nexus_red_terms as $nexus_red_term ) {
                $nexus_red_count = (int) Nexus_Redazioni::count_subtree( (int) $nexus_red_term->term_id );
                if ( $nexus_red_count > 0 ) {
                        $nexus_red_ranked[] = array(
                                'term'  => $nexus_red_term,
                                'count' => $nexus_red_count,
                        );
                }
        }
        usort( $nexus_red_ranked, static function ( $a, $b ) {
                if ( $a['count'] === $b['count'] ) {
                        return strcmp( (string) $a['term']->name, (string) $b['term']->name );
                }
                return ( $b['count'] <=> $a['count'] );
        } );
        $nexus_red_top = array_slice( $nexus_red_ranked, 0, 3 );

        /* Mappa agenti per nomi ed emoji delle chip. */
        if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'agents' ) ) {
                foreach ( (array) Nexus_DB::agents() as $nexus_red_agent_row ) {
                        $nexus_red_agent_map[ (int) $nexus_red_agent_row->id ] = $nexus_red_agent_row;
                }
        }
}

/*
 * v1.1.2 (T-07) — Card "Multilingua": driver attivo e conteggio dei post
 * pubblicati per lingua, letti in PHP diretto da Nexus_Multilang (contratto
 * §1: nessuna rotta REST nuova). Con zero traduzioni la card mostra lo stato
 * vuoto con link alla lista Articoli; senza la classe fondamenta la card non
 * viene stampata.
 */
$nexus_ml_driver     = '';
$nexus_ml_counts     = array();
$nexus_ml_badge      = 'Nexus';
$nexus_ml_badge_text = __( 'Collegamento interno Nexus.', 'nexus-news-ai' );

if ( class_exists( 'Nexus_Multilang' ) ) {
        $nexus_ml_driver = Nexus_Multilang::driver();
        $nexus_ml_counts = Nexus_Multilang::stats_counts();
        $nexus_ml_counts = is_array( $nexus_ml_counts ) ? $nexus_ml_counts : array();

        if ( 'polylang' === $nexus_ml_driver ) {
                $nexus_ml_badge      = 'Polylang';
                $nexus_ml_badge_text = __( 'Associazioni gestite da Polylang.', 'nexus-news-ai' );
        } elseif ( 'wpml' === $nexus_ml_driver ) {
                $nexus_ml_badge      = 'WPML';
                $nexus_ml_badge_text = __( 'Associazioni gestite da WPML.', 'nexus-news-ai' );
        }
}
?>
<div class="wrap nexus-admin" data-page="dashboard">

        <h1><?php echo esc_html__( 'Nexus News AI', 'nexus-news-ai' ); ?></h1>

        <?php if ( '' !== $nexus_tagline ) : ?>
                <p class="nxa-tagline"><?php echo esc_html( $nexus_tagline ); ?></p>
        <?php endif; ?>

        <p class="nxa-page-subtitle"><?php echo esc_html__( 'La redazione AI in un colpo d\'occhio: articoli pubblicati, agenti al lavoro e consumo AI del mese.', 'nexus-news-ai' ); ?></p>

        <div class="nxa-toolbar">
                <!-- v1.1.5 (T-04): avvio dell'intera testata. Handler delegato in admin.js
                     (sezione AVVII MIRATI): conferma inline (v1.1.13.1 Q2) con il numero
                     di agenti già caricato, poi POST pipeline/run {agent_id:0} (contratto §4.1).
                     v1.1.6: il vecchio bottone "Esegui pipeline completa" è stato RIMOSSO
                     (duplicava la stessa azione su due bottoni distinti). -->
                <button type="button" class="nxa-btn nxa-btn-run" data-run-testata="1">▶ <?php echo esc_html__( 'Avvia testata', 'nexus-news-ai' ); ?></button>
                <button type="button" class="nxa-btn nxa-collect-all"><?php echo esc_html__( 'Raccogli notizie ora', 'nexus-news-ai' ); ?></button>
                <a class="nxa-btn" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html__( 'Vedi magazine', 'nexus-news-ai' ); ?></a>
                <a class="nxa-btn is-sm" href="<?php echo esc_url( admin_url( 'admin.php?page=nexus-agents' ) ); ?>"><?php echo esc_html__( 'Gestisci agenti', 'nexus-news-ai' ); ?></a>
        </div>

        <?php if ( 0 === $nexus_agents_count ) : ?>
                <!-- v1.1.11 (R3): card CTA wizard — SOLO con zero agenti (guard nel
                     docblock di testa). L'hash #nxa-onboarding apre il wizard in
                     automatico nella pagina Agenti (sezione v1.1.11 di admin.js). -->
                <div class="nxa-onboarding-cta">
                        <div class="nxa-onboarding-cta__text">
                                <strong><?php esc_html_e( 'Crea la tua redazione', 'nexus-news-ai' ); ?></strong>
                                <span><?php esc_html_e( 'Nessun agente ancora: apri il wizard e parti da un pacchetto pronto (1, 3 o più giornalisti), tutto personalizzabile.', 'nexus-news-ai' ); ?></span>
                        </div>
                        <a class="nxa-btn nxa-btn-primary nxa-onboarding-cta__btn" href="<?php echo esc_url( admin_url( 'admin.php?page=nexus-agents#nxa-onboarding' ) ); ?>"><?php esc_html_e( 'Apri il wizard', 'nexus-news-ai' ); ?></a>
                </div>
        <?php endif; ?>

        <div class="nxa-stat-grid">
                <div class="nxa-stat-card">
                        <span class="nxa-stat-value" id="nxa-stat-published">—</span>
                        <span class="nxa-stat-label"><?php echo esc_html__( 'Pubblicati', 'nexus-news-ai' ); ?></span>
                </div>
                <div class="nxa-stat-card">
                        <span class="nxa-stat-value" id="nxa-stat-pending">—</span>
                        <span class="nxa-stat-label"><?php echo esc_html__( 'In attesa', 'nexus-news-ai' ); ?></span>
                </div>
                <div class="nxa-stat-card">
                        <span class="nxa-stat-value" id="nxa-stat-draft">—</span>
                        <span class="nxa-stat-label"><?php echo esc_html__( 'Bozze', 'nexus-news-ai' ); ?></span>
                </div>
                <div class="nxa-stat-card">
                        <span class="nxa-stat-value" id="nxa-stat-today">—</span>
                        <span class="nxa-stat-label"><?php echo esc_html__( 'Oggi', 'nexus-news-ai' ); ?></span>
                </div>
                <div class="nxa-stat-card">
                        <span class="nxa-stat-value" id="nxa-stat-agents">—</span>
                        <span class="nxa-stat-label"><?php echo esc_html__( 'Agenti attivi', 'nexus-news-ai' ); ?></span>
                </div>
                <div class="nxa-stat-card">
                        <span class="nxa-stat-value" id="nxa-stat-views">—</span>
                        <span class="nxa-stat-label"><?php echo esc_html__( 'Visualizzazioni', 'nexus-news-ai' ); ?></span>
                </div>
                <div class="nxa-stat-card">
                        <span class="nxa-stat-value"><?php echo esc_html( $nexus_native_linked ); ?></span>
                        <span class="nxa-stat-label"><?php echo esc_html__( 'Contenuti nativi', 'nexus-news-ai' ); ?></span>
                        <?php if ( null !== $nexus_native_remaining ) : ?>
                                <span class="nxa-stat-label"><?php echo esc_html( $nexus_native_remaining ); ?> <?php echo esc_html__( 'da migrare', 'nexus-news-ai' ); ?></span>
                        <?php endif; ?>
                </div>
                <?php if ( class_exists( 'Nexus_Redazioni' ) ) : ?>
                <div class="nxa-stat-card">
                        <span class="nxa-stat-value"><?php echo esc_html( number_format_i18n( count( $nexus_red_terms ) ) ); ?></span>
                        <span class="nxa-stat-label"><?php echo esc_html__( 'Redazioni', 'nexus-news-ai' ); ?></span>
                        <span class="nxa-stat-label"><?php echo esc_html( sprintf(
                                /* translators: %s: numero di agenti assegnati alle redazioni. */
                                __( 'Agenti assegnati: %s', 'nexus-news-ai' ),
                                number_format_i18n( $nexus_red_agents )
                        ) ); ?></span>
                        <?php if ( 0 === count( $nexus_red_terms ) ) : ?>
                                <span class="nxa-stat-label"><a href="<?php echo esc_url( admin_url( 'admin.php?page=nexus-redazioni' ) ); ?>"><?php echo esc_html__( 'Crea la prima redazione dalla pagina Redazioni.', 'nexus-news-ai' ); ?></a></span>
                        <?php endif; ?>
                        <?php foreach ( $nexus_red_top as $nexus_red_item ) : ?>
                                <?php
                                $nexus_red_t      = $nexus_red_item['term'];
                                $nexus_red_ids    = Nexus_Redazioni::agents_for_term( (int) $nexus_red_t->term_id );
                                $nexus_red_extra  = max( 0, count( $nexus_red_ids ) - 4 );
                                ?>
                                <div class="nxa-red-top-item">
                                        <span class="nxa-red-top-name"><?php echo esc_html( (string) $nexus_red_t->name ); ?></span>
                                        <span class="nxa-red-top-count"><?php echo esc_html( sprintf(
                                                /* translators: %s: numero di articoli nel ramo della redazione. */
                                                _n( '%s articolo', '%s articoli', $nexus_red_item['count'], 'nexus-news-ai' ),
                                                number_format_i18n( $nexus_red_item['count'] )
                                        ) ); ?></span>
                                        <span class="nxa-red-agents-chips">
                                                <?php foreach ( array_slice( $nexus_red_ids, 0, 4 ) as $nexus_red_agent_id ) : ?>
                                                        <?php
                                                        $nexus_red_agent  = isset( $nexus_red_agent_map[ $nexus_red_agent_id ] ) ? $nexus_red_agent_map[ $nexus_red_agent_id ] : null;
                                                        $nexus_red_avatar = ( $nexus_red_agent && ! empty( $nexus_red_agent->avatar ) ) ? (string) $nexus_red_agent->avatar : '🤖';
                                                        $nexus_red_aname  = ( $nexus_red_agent && ! empty( $nexus_red_agent->name ) ) ? (string) $nexus_red_agent->name : sprintf( __( 'Agente #%d', 'nexus-news-ai' ), $nexus_red_agent_id );
                                                        ?>
                                                        <span class="nxa-red-chip"><?php echo esc_html( $nexus_red_avatar . ' ' . $nexus_red_aname ); ?></span>
                                                <?php endforeach; ?>
                                                <?php if ( $nexus_red_extra > 0 ) : ?>
                                                        <span class="nxa-red-chip">+<?php echo esc_html( number_format_i18n( $nexus_red_extra ) ); ?></span>
                                                <?php endif; ?>
                                        </span>
                                </div>
                        <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if ( class_exists( 'Nexus_Multilang' ) ) : ?>
                <div class="nxa-stat-card">
                        <span class="nxa-stat-value"><?php echo esc_html( $nexus_ml_badge ); ?></span>
                        <span class="nxa-stat-label"><?php echo esc_html__( 'Multilingua', 'nexus-news-ai' ); ?></span>
                        <span class="nxa-stat-label"><?php echo esc_html( $nexus_ml_badge_text ); ?></span>
                        <?php if ( empty( $nexus_ml_counts ) ) : ?>
                                <span class="nxa-stat-label"><?php echo esc_html__( 'Nessun post tradotto ancora.', 'nexus-news-ai' ); ?></span>
                                <span class="nxa-stat-label"><a href="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>"><?php echo esc_html__( 'Apri la lista Articoli.', 'nexus-news-ai' ); ?></a></span>
                        <?php else : ?>
                                <span class="nxa-red-agents-chips">
                                        <?php foreach ( $nexus_ml_counts as $nexus_ml_lang => $nexus_ml_count ) : ?>
                                                <?php
                                                $nexus_ml_count = (int) $nexus_ml_count;
                                                $nexus_ml_name  = Nexus_Multilang::lang_name( (string) $nexus_ml_lang );
                                                ?>
                                                <span class="nxa-red-chip"><?php echo esc_html( sprintf(
                                                        /* translators: 1: nome della lingua, 2: numero di post pubblicati in quella lingua. */
                                                        _n( '%1$s — %2$s articolo', '%1$s — %2$s articoli', $nexus_ml_count, 'nexus-news-ai' ),
                                                        $nexus_ml_name,
                                                        number_format_i18n( $nexus_ml_count )
                                                ) ); ?></span>
                                        <?php endforeach; ?>
                                </span>
                        <?php endif; ?>
                </div>
                <?php endif; ?>
        </div>

        <div class="nxa-section nxa-section-usage">
                <h2><?php echo esc_html__( 'Uso AI (mese corrente)', 'nexus-news-ai' ); ?></h2>
                <?php
                /*
                 * v1.0.3 — Redazione intelligente: statistiche consumo AI stampate
                 * inline (contratto §4: nessuna rotta REST nuova). admin.js legge i
                 * data-attribute del contenitore qui sotto e renderizza stat-card,
                 * tabella per-agente e barra budget. Guard PHP 7.4-safe: se la
                 * classe/metodo non esiste ancora si passa un array vuoto.
                 */
                $nexus_usage = ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'usage_stats' ) )
                                ? Nexus_DB::usage_stats( 'month' )
                                : array( 'totals' => array(), 'by_agent' => array() );
                /* Il budget arriva da get_settings(), GIÀ chiamata in testa alla view ($nexus_settings). */
                $nexus_budget = ( is_array( $nexus_settings ) && isset( $nexus_settings['monthly_budget_usd'] ) )
                                ? (float) $nexus_settings['monthly_budget_usd']
                                : 0.0;
                ?>
                <div id="nexus-usage-panel"
                        data-usage="<?php echo esc_attr( wp_json_encode( $nexus_usage ) ); ?>"
                        data-budget="<?php echo esc_attr( (string) $nexus_budget ); ?>">
                        <p class="screen-reader-text"><?php echo esc_html__( 'Caricamento uso AI…', 'nexus-news-ai' ); ?></p>
                        <div class="nxa-skeleton"></div>
                        <div class="nxa-skeleton"></div>
                        <div class="nxa-skeleton"></div>
                </div>
        </div>

        <div class="nxa-section nxa-section-activity">
                <h2><?php echo esc_html__( 'Feed attività', 'nexus-news-ai' ); ?></h2>
                <!-- v1.1.5 (T-04): pannello live "In redazione ora" (contratto §5.2).
                     Contenitore vuoto: intestazione .nxa-live-updated e righe
                     .nxa-live-agent sono renderizzate da admin.js (GET /live + polling
                     5s solo con la dashboard visibile; endpoint assente → la strip
                     viene nascosta in silenzio, senza lasciare titoli orfani). -->
                <div id="nxa-live-strip" class="nxa-live-strip"></div>
                <div id="nxa-activity-feed" class="nxa-activity-feed">
                        <p class="screen-reader-text"><?php echo esc_html__( 'Caricamento attività…', 'nexus-news-ai' ); ?></p>
                        <div class="nxa-skeleton"></div>
                        <div class="nxa-skeleton"></div>
                        <div class="nxa-skeleton"></div>
                </div>
        </div>

        <div class="nxa-section nxa-section-agents">
                <h2><?php echo esc_html__( 'Stato agenti', 'nexus-news-ai' ); ?></h2>
                <div id="nxa-agents-mini" class="nxa-agents-grid">
                        <p class="screen-reader-text"><?php echo esc_html__( 'Caricamento agenti…', 'nexus-news-ai' ); ?></p>
                        <div class="nxa-skeleton"></div>
                        <div class="nxa-skeleton"></div>
                        <div class="nxa-skeleton"></div>
                </div>
        </div>

        <noscript>
                <div class="nxa-empty">
                        <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                        <p class="nxa-empty__title"><?php echo esc_html__( 'JavaScript è disattivato', 'nexus-news-ai' ); ?></p>
                        <p><?php echo esc_html__( 'La dashboard di Nexus News AI richiede JavaScript per caricare statistiche, attività e stato degli agenti.', 'nexus-news-ai' ); ?></p>
                </div>
        </noscript>

</div>
