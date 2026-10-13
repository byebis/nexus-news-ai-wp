<?php
/**
 * Vista admin: "Redazioni gerarchiche" — data-page="redazioni" (v1.1.1).
 *
 * SKELETON STATICO, come le altre viste del plugin: NESSUN JavaScript
 * inline (tutta la logica è in assets/js/admin.js, gestita da T-03) e
 * NESSUN CSS inline (lo stile è in admin/css/nexus-admin.css, sezione
 * Redazioni, gestita da T-04). Il contenuto dinamico arriva via REST
 * (namespace nexus/v1, header X-WP-Nonce con NexusAdminData.nonce).
 *
 * MODELLO (contratto CONTRATTO-v1.1.1.md): ogni testata può avere più
 * redazioni contemporanee e ogni redazione può avere sub-redazioni
 * annidate, esattamente come i termini padre-figlio di una tassonomia
 * gerarchica nativa di WordPress (nexus_redazione). Esempio: Redazione
 * Sport madre di Calcio, Basket e F1, ciascuna con i suoi agenti.
 *
 * =====================================================================
 * CONTRATTO JS (v1.1.1, T-01 e T-03) — PAGINA REDAZIONI
 * =====================================================================
 *
 * 1) GANCIO PAGINA: .wrap.nexus-page[data-page="redazioni"] → boot del
 *    loader "redazioni" in admin.js (GET nexus/v1/redazioni e GET
 *    nexus/v1/agents in parallelo).
 *
 * 2) ID ESATTI consumati da admin.js (NON cambiarli: T-03 si aggancia a
 *    questi selettori):
 *      - #nxa-red-stats  → riepilogo "N redazioni · N agenti assegnati ·
 *        N articoli coperti" (div.nxa-red-stats, vuoto all'origine);
 *      - #nxa-red-form   → submit: creazione se #nxa-red-id vale 0,
 *        aggiornamento se contiene un term id (POST nexus/v1/redazioni
 *        oppure POST nexus/v1/redazioni/{id});
 *      - #nxa-red-id     → input hidden, term id in modifica (0 = crea);
 *      - #nxa-red-name   → nome della redazione (obbligatorio);
 *      - #nxa-red-slug   → slug opzionale (vuoto = generato dal nome);
 *      - #nxa-red-parent → select VUOTA all'origine: admin.js la riempie
 *        con la prima voce "(nessuna — redazione madre)" value 0 seguita
 *        dai termini esistenti indentati per profondità (in modifica il
 *        termine stesso è escluso dalle scelte);
 *      - #nxa-red-agents → div VUOTO all'origine: admin.js lo riempie
 *        con una checkbox per agente (class .nxa-red-agent, value =
 *        agent id, spuntata se l'agente è assegnato alla redazione);
 *      - #nxa-red-save   → submit "Crea redazione" (in modalità modifica
 *        admin.js ne aggiorna l'etichetta);
 *      - #nxa-red-cancel → bottone "Annulla", nascosto con la class
 *        hidden finché non si entra in modifica; click = reset form;
 *      - #nxa-red-empty  → stato vuoto (class hidden all'origine: resta
 *        nascosto finché esistono termini, admin.js lo mostra a zero);
 *      - #nxa-red-tree   → albero: righe generate da admin.js come
 *        .nxa-red-row con data-id, data-depth e data-slug, dentro cui
 *        compaiono .nxa-red-toggle (espandi e collassa, solo con figli),
 *        .nxa-red-name, chips .nxa-red-agents-chips, contatore
 *        .nxa-red-count e azioni .nxa-red-edit, .nxa-red-del e
 *        .nxa-red-view (apre l'archivio pubblico in una nuova scheda);
 *      - #nxa-red-sources → textarea fonti della redazione (v1.1.4):
 *        in modifica riempita dal meta _nexus_sources del termine come
 *        righe "rss|https://..." / "site|https://...", al submit le
 *        righe diventano array di oggetti {type, url} (plumbing nel
 *        punto 4: NON passa dalla route nexus/v1/redazioni).
 *
 * 3) Le barre .nxa-skeleton dentro #nxa-red-tree coprono il tempo prima
 *    del primo render REST (admin.js le sostituisce, stesso pattern delle
 *    viste Agenti e Coda). NIENTE fallback noscript di contenuto: senza
 *    JavaScript la pagina resta uno skeleton e mostra solo l'avviso
 *    noscript; le stringhe visibili usano il text domain nexus-news-ai.
 *
 * 4) FONTI DELLA REDAZIONE (v1.1.4, T-08) — plumbing del meta
 *    "_nexus_sources" (array di oggetti {type, url}, type 'rss'|'site',
 *    registrato show_in_rest in Nexus_Redazioni::register_taxonomy).
 *    Percorso scelto: REST NATIVA dei termini, ZERO endpoint nuovi
 *    (le route nexus/v1/redazioni non gestiscono le fonti):
 *      - CARICAMENTO (modalità modifica, click .nxa-red-edit): GET
 *        wp/v2/redazioni/{id}?context=edit (header X-WP-Nonce con
 *        NexusAdminData.nonce, lo stesso di api()) → meta._nexus_sources
 *        → righe "type|url" in #nxa-red-sources (meta assente o vuoto →
 *        textarea vuota; modalità crea → textarea vuota).
 *      - SALVATAGGIO: POST wp/v2/redazioni/{id} con body
 *        {meta: {_nexus_sources: [ {type, url}, ... ]}}: in modifica dopo
 *        il salvataggio della redazione (sempre, array vuoto = cancella
 *        tutte le fonti); in creazione solo se la textarea non è vuota,
 *        dopo la risposta di POST nexus/v1/redazioni che porta term_id.
 *        La normalizzazione server-side è garantita dal sanitize_callback
 *        Nexus_Redazioni::sanitize_sources (accetta anche le righe "type|url").
 *    Nota UI: le fonti sono opzionali — i giornalisti della redazione le
 *    usano in raccolta e il capo redattore per verificare.
 *
 * =====================================================================
 * v1.1.5 — BOTTONE AVVIA REDAZIONE (CONTRATTO-v1.1.5.md §5 T-04)
 * =====================================================================
 *
 * Ogni riga dell'albero (#nxa-red-tree) espone il bottone "Avvia redazione"
 * (classe nxa-btn-run, data-run-redazione="{id}", primo bottone della riga):
 * avvia gli agenti della redazione con POST nexus/v1/pipeline/run
 * {redazione_id: id} (shape §4.1 con results[] e toast riepilogo). Le righe
 * sono generate da admin.js (template redazioneRowHtml, sezione "AVVII
 * MIRATI (v1.1.5)"): questa vista non contiene markup di riga (come per
 * .nxa-red-edit e soci), quindi il bottone atterra SOLO nel template JS.
 * Handler delegato unico su document con guardia di binding: nessuna
 * doppia registrazione.
 *
 * @package Nexus_News_AI
 * @since   1.1.1
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap nexus-page" data-page="redazioni">

        <h1><?php esc_html_e( 'Redazioni', 'nexus-news-ai' ); ?></h1>

        <p class="nxa-page-subtitle"><?php esc_html_e( 'Organizza la testata in redazioni gerarchiche: una redazione madre può contenere sub-redazioni (es. Sport → Calcio, Basket, F1) e ogni redazione ha i suoi agenti assegnati. Ogni redazione ha anche un archivio pubblico con il tema attivo.', 'nexus-news-ai' ); ?></p>

        <noscript>
                <div class="notice notice-warning"><p><?php esc_html_e( 'JavaScript è disattivato: la gestione delle redazioni richiede JavaScript (form e albero sono riempiti via REST).', 'nexus-news-ai' ); ?></p></div>
        </noscript>

        <!-- Riepilogo pagina: "N redazioni · N agenti assegnati · N articoli coperti", riempito da admin.js. -->
        <div id="nxa-red-stats" class="nxa-red-stats" aria-live="polite"></div>

        <div class="nxa-red-grid">

                <div class="nxa-red-formcol">
                        <form id="nxa-red-form">
                                <input type="hidden" id="nxa-red-id" value="0" />
                                <p class="nxa-red-field">
                                        <label for="nxa-red-name"><?php esc_html_e( 'Nome redazione', 'nexus-news-ai' ); ?></label>
                                        <input type="text" id="nxa-red-name" class="widefat" maxlength="200" required />
                                        <span class="description"><?php esc_html_e( 'Es. Redazione Sport, Redazione Calcio.', 'nexus-news-ai' ); ?></span>
                                </p>
                                <p class="nxa-red-field">
                                        <label for="nxa-red-slug"><?php esc_html_e( 'Slug (opzionale)', 'nexus-news-ai' ); ?></label>
                                        <input type="text" id="nxa-red-slug" class="widefat" maxlength="200" />
                                        <span class="description"><?php esc_html_e( 'Vuoto = slug generato dal nome.', 'nexus-news-ai' ); ?></span>
                                </p>
                                <p class="nxa-red-field">
                                        <label for="nxa-red-parent"><?php esc_html_e( 'Redazione madre', 'nexus-news-ai' ); ?></label>
                                        <!-- Select riempita da admin.js: prima voce value 0 "(nessuna — redazione madre)", poi i termini indentati per profondità. -->
                                        <select id="nxa-red-parent" class="widefat"></select>
                                        <span class="description"><?php esc_html_e( 'Lascia la prima voce per una redazione di primo livello; scegli una redazione madre per annidare una sub-redazione (es. Calcio dentro Sport).', 'nexus-news-ai' ); ?></span>
                                </p>
                                <p class="nxa-red-field">
                                        <span class="nxa-red-field-label"><?php esc_html_e( 'Agenti assegnati', 'nexus-news-ai' ); ?></span>
                                        <span class="description"><?php esc_html_e( 'Segna gli agenti che lavorano in questa redazione: la lista delle caselle è riempita da admin.js.', 'nexus-news-ai' ); ?></span>
                                </p>
                                <!-- Container checkbox agenti: admin.js genera input.nxa-red-agent (value = agent id) con avatar e nome. -->
                                <div id="nxa-red-agents"></div>
                                <p class="nxa-red-field">
                                        <label for="nxa-red-sources"><?php esc_html_e( 'Fonti della redazione', 'nexus-news-ai' ); ?></label>
                                        <!-- Textarea fonti (v1.1.4): righe "rss|https://..." (feed diretto) o "site|https://..." (sito: feed scoperto automaticamente). admin.js la riempie dal meta _nexus_sources e salva via REST nativa wp/v2 (contratto nel docblock di testa). -->
                                        <textarea id="nxa-red-sources" class="widefat" rows="4" placeholder="rss|https://www.ansa.it/sito/ansait_rss.xml&#10;site|https://www.ilpost.it"></textarea>
                                        <span class="description"><?php esc_html_e( 'Una riga per fonte: rss|URL per un feed RSS diretto, site|URL per un sito di cui scoprire il feed automaticamente. Le fonti sono opzionali: i giornalisti della redazione le useranno in raccolta e il capo redattore per verificare.', 'nexus-news-ai' ); ?></span>
                                </p>
                                <p class="nxa-red-form-actions">
                                        <button type="submit" id="nxa-red-save" class="button button-primary"><?php esc_html_e( 'Crea redazione', 'nexus-news-ai' ); ?></button>
                                        <button type="button" id="nxa-red-cancel" class="button hidden"><?php esc_html_e( 'Annulla', 'nexus-news-ai' ); ?></button>
                                </p>
                        </form>
                </div>

                <div class="nxa-red-treecol">
                        <!-- Stato vuoto: admin.js toglie la class hidden quando non esistono redazioni. -->
                        <div id="nxa-red-empty" class="hidden nxa-empty">
                                <span class="dashicons dashicons-sitemap" aria-hidden="true"></span>
                                <p class="nxa-empty__title"><?php esc_html_e( 'Nessuna redazione', 'nexus-news-ai' ); ?></p>
                                <p><?php esc_html_e( 'Crea la prima redazione con il form accanto: potrai annidarvi sub-redazioni e assegnare gli agenti (es. Sport con Calcio, Basket e F1).', 'nexus-news-ai' ); ?></p>
                        </div>
                        <!-- Albero gerarchico: righe generate da admin.js (contratto nel docblock di testa).
                             Le barre .nxa-skeleton coprono il tempo prima del primo render REST. -->
                        <div id="nxa-red-tree" aria-live="polite" aria-busy="true">
                                <p class="screen-reader-text"><?php esc_html_e( 'Caricamento redazioni…', 'nexus-news-ai' ); ?></p>
                                <div class="nxa-skeleton"></div>
                                <div class="nxa-skeleton"></div>
                                <div class="nxa-skeleton"></div>
                        </div>
                </div>

        </div>

</div>
