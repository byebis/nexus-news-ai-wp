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
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

$nexus_settings = Nexus_DB::get_settings();
$nexus_tagline  = isset( $nexus_settings['site_tagline'] ) ? $nexus_settings['site_tagline'] : '';

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
?>
<div class="wrap nexus-admin" data-page="dashboard">

        <h1><?php echo esc_html__( 'Nexus News AI', 'nexus-news-ai' ); ?></h1>

        <?php if ( '' !== $nexus_tagline ) : ?>
                <p class="nxa-tagline"><?php echo esc_html( $nexus_tagline ); ?></p>
        <?php endif; ?>

        <p class="nxa-page-subtitle"><?php echo esc_html__( 'La redazione AI in un colpo d\'occhio: articoli pubblicati, agenti al lavoro e consumo AI del mese.', 'nexus-news-ai' ); ?></p>

        <div class="nxa-toolbar">
                <button type="button" class="nxa-btn nxa-btn-primary nxa-run-pipeline"><?php echo esc_html__( 'Esegui pipeline completa', 'nexus-news-ai' ); ?></button>
                <button type="button" class="nxa-btn nxa-collect-all"><?php echo esc_html__( 'Raccogli notizie ora', 'nexus-news-ai' ); ?></button>
                <a class="nxa-btn" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html__( 'Vedi magazine', 'nexus-news-ai' ); ?></a>
                <a class="nxa-btn is-sm" href="<?php echo esc_url( admin_url( 'admin.php?page=nexus-agents' ) ); ?>"><?php echo esc_html__( 'Gestisci agenti', 'nexus-news-ai' ); ?></a>
        </div>

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
