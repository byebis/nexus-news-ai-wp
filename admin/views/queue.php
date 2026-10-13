<?php
/**
 * Vista admin: "Coda di approvazione" — data-page="queue".
 *
 * SKELETON STATICO. La lista live NON viene renderizzata qui: la riempie
 * assets/js/admin.js via REST (namespace nexus/v1, header X-WP-Nonce con
 * NexusAdminData.nonce). Questo file fornisce solo:
 *  - il guscio pagina con ID/classi/data-attributes FISSI (contratto sotto);
 *  - la toolbar filtri/azioni (.nxa-toolbar);
 *  - il placeholder .nxa-skeleton dentro la lista (rimpiazzato dal primo
 *    render di admin.js).
 *
 * =====================================================================
 * CONTRATTO JS (admin.js 1.0.5, verificato sul sorgente) — PAGINA CODA
 * =====================================================================
 *
 * 1) HOOK CONSUMATI dal markup statico di questa vista:
 *      - .wrap.nexus-admin[data-page="queue"] → boot del pannello;
 *      - #nxa-queue-filter (select) → evento "change": ricarica la lista con
 *        GET /articles?per_page=20&page=1&status={value}; valore vuoto, "all"
 *        o "any" = nessun filtro di stato;
 *      - .nxa-queue-refresh (qsa: anche più bottoni) → ricarica la lista
 *        con lo stato corrente del filtro;
 *      - #nxa-queue-list → container lista: admin.js lo svuota, vi inietta
 *        4 barre .nxa-skeleton (showSkeleton) e poi le righe; lista vuota o
 *        errore REST → admin.js inserisce una .nxa-empty.
 *
 * 2) RIGA CODA (generata da admin.js dentro #nxa-queue-list):
 *      article.nxa-queue-item[data-article-id="{id}"]
 *        chip categoria (.nxa-chip, colore inline), badge stato
 *        (.nxa-badge.nxa-badge--{variant}), punteggio qualità (badge inline
 *        con percentuale; l'equivalente design system è .nxa-score.is-high /
 *        .is-mid / .is-low), eventuale badge "Post WP" (.nxa-badge--primary),
 *        nome agente, titolo come link .nxa-detail[data-id], sottotitolo,
 *        estratto, fonte (link esterno) e azioni in .nxa-btn-row:
 *          .nxa-approve[data-id]     → POST /articles/{id}/approve {note: ''}
 *          .nxa-reject[data-id]      → apre il form nota inline .nxa-note-row:
 *                                       input .nxa-note-input +
 *                                       .nxa-reject-confirm[data-id] (POST
 *                                       /articles/{id}/reject {note}) +
 *                                       .nxa-reject-cancel (ripristina le azioni)
 *          .nxa-publish-one[data-id] → (solo stato approved) POST
 *                                       /pipeline/publish {article_id}
 *          .nxa-detail[data-id]      → apre la modale di dettaglio (punto 3)
 *
 * 3) MODALE DETTAGLIO: admin.js usa la SUA modale #nxa-modal (ensureModal,
 *    appesa a body; openArticleModal → GET /articles/{id} e riempie il
 *    corpo con chip/badge categoria e stato, titolo, meta, contenuto e
 *    log di pubblicazione). v1.1.13 (O4): il blocco di riserva
 *    #nxa-queue-modal è stato RIMOSSO (markup morto: nessun selettore
 *    nxa-queue-modal* consumato da admin.js — verificato con grep prima
 *    dell’eliminazione). L’hook [data-nxa-close-modal] delegato in
 *    admin.js (bindStaticModalClosers) resta disponibile per le modali
 *    statiche di altre viste.
 *
 * 4) HOOK CONSUMATI / PULITI (v1.1.13, O4): #nxa-queue-count (contatore
 *    "N articoli", aria-live) È consumato: admin.js lo popola ad ogni
 *    caricamento lista (loadQueueList, da v1.1.7 T-C2: "1 articolo in
 *    coda" / "N articoli in coda" — il vecchio docblock che lo dichiara
 *    "non consumato" era obsoleto). Il template vuoto
 *    #nxa-queue-row-tpl è stato RIMOSSO: la riga è documentata al punto 2
 *    e viene generata da admin.js, mai dal markup statico.
 *
 * v1.0.6 — markup allineato al design system a tema chiaro: sottotitolo di
 * pagina, toolbar filtri (.nxa-toolbar), bottone .nxa-btn, placeholder
 * .nxa-skeleton, empty state no-JS .nxa-empty con dashicon, modale
 * riallineata alla famiglia .nxa-modal / .nxa-modal-close / .nxa-modal-body
 * del foglio di stile. Nessuna modifica a id, data-* e selettori consumati
 * da admin.js.
 *
 * v1.0.7 — fix overflow mobile view-level (CONTRATTO-v1.0.7.md §2 T-07): il
 * container #nxa-queue-list porta anche la classe condivisa .nxa-table-wrap
 * (wrapper contrattuale definito dal task T-04 in admin/css/nexus-admin.css:
 * overflow-x auto, scroll touch, max-width 100%). Se una riga generata da
 * admin.js (titolo, URL fonte, chip) supera la larghezza disponibile, scrolla
 * dentro il contenitore invece di spingere la pagina in orizzontale. Il
 * markup delle righe resta generato da admin.js (fuori scope): il word-break
 * dei titoli/fonti è demandato al CSS condiviso di T-04. Nessun id, data-* e
 * selettore consumato da admin.js modificato; nessuno style inline nuovo.
 *
 * v1.1.13 (O4) — sweep markup morto: rimossi <template id="nxa-queue-row-tpl">
 * (vuoto per contratto, la riga la genera admin.js) e la modale di riserva
 * #nxa-queue-modal (nessun riferimento in admin.js: il dettaglio apre la
 * modale condivisa #nxa-modal). Docblock punti 3/4 riscritti: il contatore
 * #nxa-queue-count è consumato da admin.js da v1.1.7. Zero modifiche a id,
 * data-* e selettori consumati dal JS vivo.
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap nexus-admin" data-page="queue">

        <h1><?php esc_html_e( 'Coda di approvazione', 'nexus-news-ai' ); ?></h1>

        <p class="nxa-page-subtitle"><?php esc_html_e( 'Articoli proposti dagli agenti: valuta qualità e fonte, poi approva, rifiuta o pubblica.', 'nexus-news-ai' ); ?></p>

        <div class="nxa-toolbar">
                <label class="screen-reader-text" for="nxa-queue-filter">
                        <?php esc_html_e( 'Filtra articoli per stato', 'nexus-news-ai' ); ?>
                </label>
                <select id="nxa-queue-filter" class="nxa-queue-filter">
                        <option value="pending_approval" selected><?php esc_html_e( 'In attesa', 'nexus-news-ai' ); ?></option>
                        <option value="approved"><?php esc_html_e( 'Approvati', 'nexus-news-ai' ); ?></option>
                        <option value="rejected"><?php esc_html_e( 'Rifiutati', 'nexus-news-ai' ); ?></option>
                        <option value="draft"><?php esc_html_e( 'Bozze', 'nexus-news-ai' ); ?></option>
                        <option value="published"><?php esc_html_e( 'Pubblicati', 'nexus-news-ai' ); ?></option>
                </select>

                <button type="button" class="nxa-btn nxa-queue-refresh">
                        <?php esc_html_e( 'Aggiorna', 'nexus-news-ai' ); ?>
                </button>

                <!-- Hook fisso per il conteggio ("N articoli"), aria-live: è CONSUMATO
                     da admin.js (loadQueueList, v1.1.7 T-C2 — vedi punto 4 del docblock). -->
                <span id="nxa-queue-count" class="nxa-queue-count" aria-live="polite"></span>
        </div>

        <!-- LISTA CODA: riempita da admin.js (GET /articles?status=...). Contratto nel docblock di testa.
             Le barre .nxa-skeleton coprono il tempo prima del primo render REST. -->
        <div id="nxa-queue-list" class="nxa-queue-list nxa-table-wrap" aria-live="polite" aria-busy="true">
                <p class="screen-reader-text"><?php esc_html_e( 'Caricamento articoli…', 'nexus-news-ai' ); ?></p>
                <div class="nxa-skeleton"></div>
                <div class="nxa-skeleton"></div>
                <div class="nxa-skeleton"></div>
                <div class="nxa-skeleton"></div>
        </div>

        <!-- v1.1.13 (O4): rimossi il template nxa-queue-row-tpl (vuoto per
             contratto: la riga la genera admin.js) e la modale di riserva
             nxa-queue-modal (nessun riferimento in admin.js: il dettaglio
             apre la modale condivisa nxa-modal — vedi punti 3 e 4 del
             docblock di testa). -->

        <noscript>
                <div class="nxa-empty">
                        <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                        <p class="nxa-empty__title"><?php esc_html_e( 'JavaScript è disattivato', 'nexus-news-ai' ); ?></p>
                        <p><?php esc_html_e( 'La coda di approvazione richiede JavaScript per caricare gli articoli.', 'nexus-news-ai' ); ?></p>
                </div>
        </noscript>

</div>
