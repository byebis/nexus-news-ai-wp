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
 *   le righe skeleton (.nxa-skeleton → placeholder statici di caricamento).
 * - Ogni evento della timeline deve mostrare:
 *   - icona azione:  collecting | evaluating | rewriting | publishing | wire
 *   - nome agente (o "Sistema" se agent_id = 0)
 *   - detail (testo descrittivo dell'evento)
 *   - badge status → etichetta italiana: info → "Info", success → "Completato",
 *     warning → "Attenzione", error → "Errore"
 *   - data relativa calcolata con Nexus_Utils::time_ago_it().
 *
 * v1.0.6 — markup allineato al design system a tema chiaro: skeleton
 * (.nxa-skeleton) al posto delle righe decorative, sottotitolo di pagina e
 * empty state no-JS (.nxa-empty) con dashicon. Nessuna modifica a id,
 * data-* e selettori consumati da admin.js.
 *
 * v1.0.7 — fix overflow mobile view-level (CONTRATTO-v1.0.7.md §2 T-07): il
 * container #nxa-activity-list porta anche la classe condivisa
 * .nxa-table-wrap (wrapper contrattuale definito dal task T-04 in
 * admin/css/nexus-admin.css: overflow-x auto, scroll touch, max-width 100%).
 * La vista non contiene tabelle dati: il wrapper agisce da contenimento
 * scrollabile per la timeline generata via REST (dettagli evento con testi
 * lunghi), che scrolla dentro il contenitore invece di spingere la pagina in
 * orizzontale. Nessun id, data-* e selettore consumato da admin.js
 * modificato; nessuno style inline nuovo.
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap nexus-admin" data-page="activity">
	<h1><?php esc_html_e( 'Feed attività', 'nexus-news-ai' ); ?></h1>

	<p class="nxa-page-subtitle"><?php esc_html_e( 'Cronologia del lavoro degli agenti: raccolte, valutazioni, riscritture e pubblicazioni.', 'nexus-news-ai' ); ?></p>

	<!-- SKELETON: righe statiche di caricamento, rimpiazzate dal primo render di admin.js (GET /activity?limit=50). -->
	<div id="nxa-activity-list" class="nxa-activity-feed nxa-table-wrap" aria-live="polite" aria-busy="true">
		<p class="screen-reader-text"><?php esc_html_e( 'Caricamento attività…', 'nexus-news-ai' ); ?></p>
		<div class="nxa-skeleton"></div>
		<div class="nxa-skeleton"></div>
		<div class="nxa-skeleton"></div>
		<div class="nxa-skeleton"></div>
		<div class="nxa-skeleton"></div>
	</div>

	<noscript>
		<div class="nxa-empty">
			<span class="dashicons dashicons-warning" aria-hidden="true"></span>
			<p class="nxa-empty__title"><?php esc_html_e( 'JavaScript è disattivato', 'nexus-news-ai' ); ?></p>
			<p><?php esc_html_e( 'Attiva JavaScript per visualizzare lo storico delle attività.', 'nexus-news-ai' ); ?></p>
		</div>
	</noscript>
</div>
