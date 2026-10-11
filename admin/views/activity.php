<?php
/**
 * Vista Admin: Feed attività (pagina "activity" di Nexus News AI).
 *
 * SKELETON HTML: i dati vengono caricati lato client via REST (nexus/v1) da admin.js.
 * Questo file fornisce solo la struttura statica con selettori fissi.
 *
 * CONTRATTO FISSO CON admin.js (non rinominare id/classi/data-attributes):
 * - Contenitore pagina: <div class="wrap nexus-admin" data-page="activity">.
 * - #nxa-activity-list.nxa-activity-feed: admin.js esegue GET /activity?limit=50
 *   (nexus/v1) e renderizza la timeline dentro questo container, sostituendo
 *   le righe skeleton (.nxa-skeleton-* → placeholder statici di caricamento).
 * - Ogni evento della timeline deve mostrare:
 *   - icona azione:  collecting 📡 | evaluating ⚖️ | rewriting ✍️ | publishing 🚀 | wire 🕸️
 *   - nome agente (o "Sistema" se agent_id = 0)
 *   - detail (testo descrittivo dell'evento)
 *   - badge status → etichetta italiana: info → "Info", success → "Completato",
 *     warning → "Attenzione", error → "Errore"
 *   - data relativa calcolata con Nexus_Utils::time_ago_it().
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap nexus-admin" data-page="activity">
	<h1><?php esc_html_e( 'Feed attività', 'nexus-news-ai' ); ?></h1>

	<!-- SKELETON: righe statiche di caricamento, rimpiazzate dal primo render di admin.js (GET /activity?limit=50). -->
	<div id="nxa-activity-list" class="nxa-activity-feed" aria-live="polite" aria-busy="true">
		<div class="nxa-activity-item nxa-skeleton-item">
			<span class="nxa-activity-icon nxa-skeleton-icon" aria-hidden="true"></span>
			<div class="nxa-activity-body">
				<span class="nxa-skeleton-line nxa-skeleton-line--title"></span>
				<span class="nxa-skeleton-line nxa-skeleton-line--detail"></span>
			</div>
		</div>
		<div class="nxa-activity-item nxa-skeleton-item">
			<span class="nxa-activity-icon nxa-skeleton-icon" aria-hidden="true"></span>
			<div class="nxa-activity-body">
				<span class="nxa-skeleton-line nxa-skeleton-line--title"></span>
				<span class="nxa-skeleton-line nxa-skeleton-line--detail"></span>
			</div>
		</div>
		<div class="nxa-activity-item nxa-skeleton-item">
			<span class="nxa-activity-icon nxa-skeleton-icon" aria-hidden="true"></span>
			<div class="nxa-activity-body">
				<span class="nxa-skeleton-line nxa-skeleton-line--title"></span>
				<span class="nxa-skeleton-line nxa-skeleton-line--detail"></span>
			</div>
		</div>
	</div>
</div>
