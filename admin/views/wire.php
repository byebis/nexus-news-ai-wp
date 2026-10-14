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
 *   admin.js inserisce dopo l'input i suggerimenti (#nxa-wire-ideas) e,
 *   durante l'esecuzione, il box .nxa-wire-status dentro il form.
 *   v1.1.16 (WE2): il box NON mostra più fasi pre-scritte animate su timer:
 *   polla GET /live ogni 5s e renderizza le FASI REALI per-agente (nome +
 *   step label corrente del runtime); runtime vuoto → riga onesta
 *   "Avvio della pipeline…" con spinner. Alla risposta del POST il box
 *   viene rimosso e resta il risultato vero (toast + storico ricaricato).
 * - #nxa-wire-runs.nxa-wire-runs: admin.js esegue GET /wire/runs (nexus/v1)
 *   e renderizza la lista dei run, ognuno con: topic, status (badge),
 *   agenti coinvolti, articolo collegato (link) e data (Nexus_Utils::time_ago_it).
 * - Le righe .nxa-skeleton sono placeholder statici di caricamento,
 *   sostituiti dal primo render di admin.js.
 *
 * v1.0.6 — markup allineato al design system a tema chiaro: bottone
 * .nxa-btn-primary, sezione storico con intestazione h2, skeleton
 * (.nxa-skeleton) ed empty state no-JS (.nxa-empty) con dashicon. Nessuna
 * modifica a id, data-* e selettori consumati da admin.js.
 *
 * v1.0.7 — fix overflow mobile view-level (CONTRATTO-v1.0.7.md §2 T-07): il
 * container #nxa-wire-runs porta anche la classe condivisa .nxa-table-wrap
 * (wrapper contrattuale definito dal task T-04 in admin/css/nexus-admin.css:
 * overflow-x auto, scroll touch, max-width 100%). La vista non contiene
 * tabelle dati: il wrapper agisce da contenimento scrollabile per la lista
 * dei run generata via REST (topic lunghi, errori, URL articolo), che scrolla
 * dentro il contenitore invece di spingere la pagina in orizzontale. L'input
 * #nxa-wire-topic (regular-text, 25em) è coperto su mobile dalla regola
 * condivisa max-width:100% di T-04. Nessun id, data-* e selettore consumato
 * da admin.js modificato; nessuno style inline nuovo.
 *
 * v1.1.5 — pass "respira" (CONTRATTO-v1.1.5.md §6 T-05): launcher e storico
 * sono separati da una hairline (hr.nxa-hairline, stile a cura di T-01) per
 * dare respiro tra le due sezioni. Nessun wrapper rimosso: la vista non
 * contiene box annidati; nessun id, data-* o selettore consumato da admin.js
 * modificato.
 *
 * v1.1.16 (WE2) — fasi REALI: nessuna modifica al markup (il rendering delle
 * fasi resta tutto in admin.js); aggiornato SOLO il contratto in testa che
 * descriveva il box .nxa-wire-status come "fasi animate" su timer. Anche lo
 * storico porta lo stato d'errore con bottone Riprova (delega .nxa-retry
 * di admin.js, WE5).
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
                <button type="submit" class="nxa-btn nxa-btn-primary"><?php esc_html_e( 'Avvia redazione collettiva', 'nexus-news-ai' ); ?></button>
        </form>

        <hr class="nxa-hairline" />

        <h2><?php esc_html_e( 'Storico delle redazioni collettive', 'nexus-news-ai' ); ?></h2>

        <!-- SKELETON: righe statiche di caricamento, rimpiazzate dal render di admin.js (GET /wire/runs). -->
        <div id="nxa-wire-runs" class="nxa-wire-runs nxa-table-wrap" aria-live="polite" aria-busy="true">
                <p class="screen-reader-text"><?php esc_html_e( 'Caricamento dello storico…', 'nexus-news-ai' ); ?></p>
                <div class="nxa-skeleton"></div>
                <div class="nxa-skeleton"></div>
                <div class="nxa-skeleton"></div>
        </div>

        <noscript>
                <div class="nxa-empty">
                        <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                        <p class="nxa-empty__title"><?php esc_html_e( 'JavaScript è disattivato', 'nexus-news-ai' ); ?></p>
                        <p><?php esc_html_e( 'Attiva JavaScript per avviare la redazione collettiva e visualizzare lo storico.', 'nexus-news-ai' ); ?></p>
                </div>
        </noscript>
</div>
