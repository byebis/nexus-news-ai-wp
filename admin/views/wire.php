<?php
/**
 * Vista Admin: Nexus Wire (pagina "wire" di Nexus News AI).
 *
 * SKELETON HTML: i dati vengono caricati lato client via REST (nexus/v1) da admin.js.
 *
 * CONTRATTO FISSO CON admin.js (non rinominare id/classi/data-attributes):
 * - Contenitore pagina: <div class="wrap nexus-admin" data-page="wire">.
 * - form#nxa-wire-form: submit intercettato; admin.js legge il valore di
 *   #nxa-wire-topic ed esegue POST /wire/run (nexus/v1) con body { topic },
 *   mostrando lo spinner "Redazione al lavoro… 3 agenti + direttore" fino
 *   alla risposta (durata: i 3 agenti propongono + il direttore AI fonde).
 * - #nxa-wire-runs.nxa-wire-runs: admin.js esegue GET /wire/runs (nexus/v1)
 *   e renderizza la lista dei run, ognuno con: topic, status (badge),
 *   agenti coinvolti, articolo collegato (link) e data (Nexus_Utils::time_ago_it).
 * - Le card .nxa-skeleton-* sono placeholder statici di caricamento,
 *   sostituite dal primo render di admin.js.
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap nexus-admin" data-page="wire">
	<h1><?php esc_html_e( 'Nexus Wire', 'nexus-news-ai' ); ?></h1>

	<p class="nxa-page-subtitle"><?php esc_html_e( 'La redazione collettiva multi-agente: tre agenti propongono i loro angoli editoriali su un tema e il direttore AI fonde tutto in un articolo.', 'nexus-news-ai' ); ?></p>

	<form id="nxa-wire-form" class="nxa-wire-form">
		<label class="screen-reader-text" for="nxa-wire-topic"><?php esc_html_e( 'Tema da sviluppare', 'nexus-news-ai' ); ?></label>
		<input type="text" id="nxa-wire-topic" name="topic" class="nxa-wire-topic regular-text" maxlength="200" autocomplete="off" placeholder="<?php esc_attr_e( "Tema da sviluppare (es. l'impatto dell'AI sulle notizie elettorali)", 'nexus-news-ai' ); ?>" />
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Avvia redazione collettiva', 'nexus-news-ai' ); ?></button>
	</form>

	<!-- SKELETON: card statiche di caricamento, rimpiazzate dal render di admin.js (GET /wire/runs). -->
	<div id="nxa-wire-runs" class="nxa-wire-runs" aria-live="polite" aria-busy="true">
		<div class="nxa-wire-run nxa-skeleton-card">
			<span class="nxa-skeleton-line nxa-skeleton-line--title"></span>
			<span class="nxa-skeleton-line nxa-skeleton-line--detail"></span>
		</div>
		<div class="nxa-wire-run nxa-skeleton-card">
			<span class="nxa-skeleton-line nxa-skeleton-line--title"></span>
			<span class="nxa-skeleton-line nxa-skeleton-line--detail"></span>
		</div>
		<div class="nxa-wire-run nxa-skeleton-card">
			<span class="nxa-skeleton-line nxa-skeleton-line--title"></span>
			<span class="nxa-skeleton-line nxa-skeleton-line--detail"></span>
		</div>
	</div>
</div>
