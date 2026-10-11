<?php
/**
 * Vista admin: "Coda di approvazione" — data-page="queue".
 *
 * SKELETON STATICO. La lista live NON viene renderizzata qui: la riempie
 * assets/js/admin.js (altro agente) via REST (namespace nexus/v1, header
 * X-WP-Nonce con NexusAdminData.nonce). Il <template> resta VOLUTAMENTE vuoto:
 * il contratto della riga è documentato nei commenti PHP di questo file
 * (nessun template tag JS complicato).
 *
 * =====================================================================
 * CONTRATTO JS (admin.js) — PAGINA CODA DI APPROVAZIONE
 * =====================================================================
 *
 * 1) LETTURA LISTA
 *    GET /articles?status={valore di #nxa-queue-filter}
 *    (altre args supportate dalla REST: category, agent_id, search, page, per_page — default 12)
 *    Trigger:
 *      - evento "change" su #nxa-queue-filter → ricarica la lista col nuovo status;
 *      - click su button.nxa-queue-refresh    → ricarica la lista col status corrente.
 *    Risposta attesa (serializer di class-nexus-rest.php): array di articoli:
 *      { id, title, subtitle, summary, category, agent_id, agent_name, agent_avatar,
 *        source_name, source_url, image_url, status, quality_score (float 0–100),
 *        read_time (minuti), views, reactions, excerpt (già calcolato: summary o
 *        content tagliato a 30 parole), published_at, created_at ('Y-m-d H:i:s') }
 *      v1.0.1 (opzionale, se il serializer la aggiungerà): wp_post_id (int) e
 *      post_permalink (string). admin.js legge i campi in modo difensivo e mostra
 *      il badge/link "Post WP" SOLO se wp_post_id > 0 (fallback href: /?p={id}).
 *
 * 2) RIGA CODA — per ogni articolo dentro #nxa-queue-list
 *    (clonare #nxa-queue-row-tpl o generare markup con gli STESSI selettori):
 *      article.nxa-queue-row[data-article-id="{id}"][data-status="{status}"]
 *        > .nxa-qa-score      → badge numerico quality_score arrotondato all'intero;
 *                               classe consigliata: nxa-score-high (>= 75),
 *                               nxa-score-mid (>= 50), nxa-score-low (< 50)
 *        > .nxa-qa-title      → title
 *        > .nxa-qa-subtitle   → subtitle (se presente)
 *        > .nxa-qa-category   → category
 *        > .nxa-qa-agent      → agent_name (già incluso nella risposta REST)
 *        > .nxa-qa-read-time  → "{read_time} min di lettura"
 *        > .nxa-qa-date       → equivalente JS di Nexus_Utils::time_ago_it(created_at)
 *        > .nxa-qa-source     → <a href="{source_url}" target="_blank" rel="noopener noreferrer">{source_name}</a>
 *        > .nxa-qa-wp-link    → (opzionale, v1.0.1) badge/link "Post WP" se wp_post_id > 0;
 *                               renderizzato da admin.js come .nxa-badge.nxa-badge--primary
 *                               con href = post_permalink (fallback: /?p={wp_post_id})
 *        > .nxa-qa-excerpt    → excerpt (fornito dalla REST); fallback summary
 *        > .nxa-qa-actions    → bottoni azione (punto 3)
 *
 * 3) AZIONI (selettori FISSI; l'id articolo sta su data-article-id della riga):
 *      button.nxa-qa-approve → POST /articles/{id}/approve  body JSON { note: '' }
 *                              (nota facoltativa: si può riusare il pattern inline del rifiuto)
 *      button.nxa-qa-reject  → NON chiama subito la REST: mostra dentro la riga il blocco
 *                              inline .nxa-qa-note-wrap (creato/nascosto da JS) contenente:
 *                                textarea.nxa-qa-note            (placeholder "Nota del revisore…")
 *                                button.nxa-qa-reject-confirm    → POST /articles/{id}/reject  body { note }
 *                                button.nxa-qa-reject-cancel     → nasconde .nxa-qa-note-wrap
 *      button.nxa-qa-publish → POST /pipeline/publish  body JSON { article_id: id }
 *                              NOTA (verificato su class-nexus-rest.php): la route publish
 *                              NON esiste ancora — le route pipeline sono /pipeline/collect
 *                              e /pipeline/run (body {agent_id}). Quindi: provare
 *                              POST /pipeline/publish {article_id}; su 404 usare come
 *                              fallback POST /pipeline/run (passando l'agent_id dell'articolo).
 *      button.nxa-qa-detail  → apre #nxa-queue-modal e riempie #nxa-queue-modal-body
 *                              con GET /articles/{id}: title, subtitle, category, agente,
 *                              quality_score, read_time, source, summary/excerpt, content
 *                              (già sanitizzato wp_kses_post lato server) e publish_logs
 *                              (eventuale); data creazione con equivalente JS di
 *                              Nexus_Utils::time_ago_it.
 *                              Titolo dialog: <h2 id="nxa-queue-modal-title">{title}</h2>.
 *                              Chiusura: click su [data-nxa-close-modal] (backdrop o X).
 *
 * 4) VISIBILITÀ AZIONI PER STATO (indicativa, a discrezione dell'integratore):
 *      pending_approval → Approva, Rifiuta, Dettaglio
 *      approved         → Pubblica, Rifiuta (opz.), Dettaglio
 *      published        → Dettaglio
 *      rejected / draft → Dettaglio (eventualmente Approva per le bozze)
 *
 * 5) DOPO UN'AZIONE
 *    Ricaricare la lista (GET /articles?status=…) e aggiornare #nxa-queue-count
 *    con il totale mostrato (es. "7 articoli") — elemento opzionale ma fisso.
 *
 * 6) STATI DELLA LISTA
 *    - Loading: #nxa-queue-list contiene <p class="nxa-loading"> (spinner WP + testo
 *      "Caricamento articoli…", stessa convenzione di dashboard.php) e ha la classe
 *      .is-loading; JS svuota il container e rimuove .is-loading.
 *    - Lista vuota: inserire .nxa-queue-empty con testo "Nessun articolo in questo stato."
 *    - Errore REST: inserire .nxa-queue-error (o .notice.notice-error) con
 *      testo "Impossibile caricare la coda."
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap nexus-admin" data-page="queue">

        <div class="nxa-page-header">
                <h1 class="nxa-page-title"><?php esc_html_e( 'Coda di approvazione', 'nexus-news-ai' ); ?></h1>

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

                <button type="button" class="button button-secondary nxa-queue-refresh">
                        <?php esc_html_e( 'Aggiorna', 'nexus-news-ai' ); ?>
                </button>

                <!-- Hook fisso opzionale per il conteggio ("7 articoli"), gestito da admin.js. -->
                <span id="nxa-queue-count" class="nxa-queue-count" aria-live="polite"></span>
        </div>

        <!-- LISTA CODA: riempita da admin.js (GET /articles?status=...). Contratto nel docblock di testa. -->
        <div id="nxa-queue-list" class="nxa-queue-list is-loading">
                <p class="nxa-loading">
                        <span class="spinner is-active"></span>
                        <?php esc_html_e( 'Caricamento articoli…', 'nexus-news-ai' ); ?>
                </p>
        </div>

        <?php /* Contratto riga coda: vedi sezione 2 del docblock in testa al file. Il template resta vuoto. */ ?>
        <template id="nxa-queue-row-tpl"></template>

        <!-- MODAL DETTAGLIO ARTICOLO (button.nxa-qa-detail → GET /articles/{id}). Contratto sezione 3. -->
        <div id="nxa-queue-modal" class="nxa-modal" hidden>
                <div class="nxa-modal__backdrop" data-nxa-close-modal></div>
                <div class="nxa-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="nxa-queue-modal-title">
                        <button type="button" class="nxa-modal__close" data-nxa-close-modal aria-label="<?php esc_attr_e( 'Chiudi', 'nexus-news-ai' ); ?>">&times;</button>
                        <div id="nxa-queue-modal-body" class="nxa-modal__body">
                                <p class="nxa-loading">
                                        <span class="spinner is-active"></span>
                                        <?php esc_html_e( 'Caricamento dettaglio…', 'nexus-news-ai' ); ?>
                                </p>
                        </div>
                </div>
        </div>

        <noscript>
                <div class="notice notice-warning">
                        <p><?php esc_html_e( 'JavaScript è disattivato: la coda di approvazione richiede JavaScript per caricare gli articoli.', 'nexus-news-ai' ); ?></p>
                </div>
        </noscript>

</div>
