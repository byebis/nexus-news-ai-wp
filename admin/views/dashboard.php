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
 *   Placeholder iniziale di ogni card: "—" (em dash).
 * - Bottoni azione rapida (delegazione eventi sui seguenti selettori):
 *     .nxa-run-pipeline → POST {restUrl}/pipeline/run     body JSON {agent_id: 0}  (pipeline completa, tutti gli agenti)
 *     .nxa-collect-all  → POST {restUrl}/pipeline/collect body JSON {agent_id: 0}  (raccolta RSS immediata, tutti gli agenti)
 *   Autenticazione: header X-WP-Nonce con NexusAdminData.nonce.
 * - Feed attività: #nxa-activity-feed ← GET {restUrl}/activity (eventi recenti; admin.js sostituisce lo skeleton).
 * - Stato agenti:  #nxa-agents-mini    ← GET {restUrl}/agents   (griglia mini nome+stato; admin.js sostituisce lo skeleton).
 * - Link "Vedi magazine": apre home_url('/') in nuova scheda (NexusAdminData.siteUrl).
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

$nexus_settings = Nexus_DB::get_settings();
$nexus_tagline  = isset( $nexus_settings['site_tagline'] ) ? $nexus_settings['site_tagline'] : '';
?>
<div class="wrap nexus-admin" data-page="dashboard">

	<h1><?php echo esc_html__( 'Nexus News AI', 'nexus-news-ai' ); ?></h1>

	<?php if ( '' !== $nexus_tagline ) : ?>
		<p class="nxa-tagline"><?php echo esc_html( $nexus_tagline ); ?></p>
	<?php endif; ?>

	<div class="nxa-actions">
		<button type="button" class="button button-primary nxa-run-pipeline"><?php echo esc_html__( 'Esegui pipeline completa', 'nexus-news-ai' ); ?></button>
		<button type="button" class="button nxa-collect-all"><?php echo esc_html__( 'Raccogli notizie ora', 'nexus-news-ai' ); ?></button>
		<a class="button" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html__( 'Vedi magazine', 'nexus-news-ai' ); ?></a>
	</div>

	<div class="nxa-stats-grid">
		<div class="nxa-stat-card"><span class="nxa-stat-value" id="nxa-stat-published">—</span><span class="nxa-stat-label"><?php echo esc_html__( 'Pubblicati', 'nexus-news-ai' ); ?></span></div>
		<div class="nxa-stat-card"><span class="nxa-stat-value" id="nxa-stat-pending">—</span><span class="nxa-stat-label"><?php echo esc_html__( 'In attesa', 'nexus-news-ai' ); ?></span></div>
		<div class="nxa-stat-card"><span class="nxa-stat-value" id="nxa-stat-draft">—</span><span class="nxa-stat-label"><?php echo esc_html__( 'Bozze', 'nexus-news-ai' ); ?></span></div>
		<div class="nxa-stat-card"><span class="nxa-stat-value" id="nxa-stat-today">—</span><span class="nxa-stat-label"><?php echo esc_html__( 'Oggi', 'nexus-news-ai' ); ?></span></div>
		<div class="nxa-stat-card"><span class="nxa-stat-value" id="nxa-stat-agents">—</span><span class="nxa-stat-label"><?php echo esc_html__( 'Agenti attivi', 'nexus-news-ai' ); ?></span></div>
		<div class="nxa-stat-card"><span class="nxa-stat-value" id="nxa-stat-views">—</span><span class="nxa-stat-label"><?php echo esc_html__( 'Visualizzazioni', 'nexus-news-ai' ); ?></span></div>
	</div>

	<div class="nxa-section nxa-section-activity">
		<h2><?php echo esc_html__( 'Feed attività', 'nexus-news-ai' ); ?></h2>
		<div id="nxa-activity-feed" class="nxa-activity-feed">
			<p class="nxa-loading">
				<span class="spinner is-active"></span>
				<?php echo esc_html__( 'Caricamento attività…', 'nexus-news-ai' ); ?>
			</p>
		</div>
	</div>

	<div class="nxa-section nxa-section-agents">
		<h2><?php echo esc_html__( 'Stato agenti', 'nexus-news-ai' ); ?></h2>
		<div id="nxa-agents-mini" class="nxa-agents-grid">
			<p class="nxa-loading">
				<span class="spinner is-active"></span>
				<?php echo esc_html__( 'Caricamento agenti…', 'nexus-news-ai' ); ?>
			</p>
		</div>
	</div>

	<noscript>
		<div class="notice notice-error inline">
			<p><?php echo esc_html__( 'JavaScript è disattivato: la dashboard di Nexus News AI richiede JavaScript per caricare statistiche, attività e stato degli agenti.', 'nexus-news-ai' ); ?></p>
		</div>
	</noscript>

</div>
