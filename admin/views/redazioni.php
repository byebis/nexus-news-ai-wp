<?php
/**
 * Vista admin: "Redazioni gerarchiche" — data-page="redazioni" (v1.1.1).
 *
 * SKELETON STATICO, come le altre viste del plugin: NESSUN JavaScript
 * inline per il FORM e per l'ALBERO (tutta la logica è in
 * assets/js/admin.js, gestita da T-03) e NESSUN CSS inline (lo stile è
 * in admin/css/nexus-admin.css, sezione Redazioni, gestita da T-04).
 * Il contenuto dinamico arriva via REST (namespace nexus/v1, header
 * X-WP-Nonce con NexusAdminData.nonce).
 *
 * ECCEZIONE DOCUMENTATA (v1.1.10, WP8): l'EDITOR FONTI STRUTTURATO vive
 * in questo file con un suo <script> self-contained (in coda alla vista,
 * pattern già usato da jobs.php/tools.php con attesa di NexusAdminData
 * localizzata nei footer). Motivo: admin.js NON è toccato da WP8 e resta
 * agganciato al salvataggio legacy delle fonti (righe "type|url" dalla
 * textarea, che degrada query/full e i tipi non rss/site); per questo
 * l'editor intercetta il submit del form #nxa-red-form in CAPTURE su
 * document e gestisce il salvataggio COMPLETO (redazione + fonti in
 * shape array via wp/v2). Vedi sezione WP8 in fondo al docblock.
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
 *      - #nxa-red-sources → dal v1.1.10 (WP8) è una textarea NASCOSTA di
 *        compatibilità (hidden): resta nel DOM perché admin.js la
 *        referenzia in load/reset, ma NON è più modificata dall'utente;
 *        è tenuta sincronizzata dall'editor con le righe legacy "type|url"
 *        come fallback. L'editor strutturato è #nxa-red-sources-editor
 *        (punto 5) e il submit del form è intercettato in capture
 *        (punto 6: il flusso legacy di admin.js non viene eseguito).
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
 * 5) EDITOR FONTI STRUTTURATO (v1.1.10, WP8) — #nxa-red-sources-editor
 * =====================================================================
 *
 * Lista di righe fonte generate dallo script inline di questa vista
 * (niente innerHTML con dati utente: tutte le righe sono costruite con
 * createElement + textContent/value, quindi XSS-proof by construction;
 * le uniche stringhe interpolate in markup sono COSTANTI di questo file
 * già escapate lato PHP con esc_html/esc_attr). Ogni riga contiene:
 *      - select .nxa-src-type      → i 8 tipi Nexus_Redazioni::TYPES
 *        (rss|site|youtube|telegram|reddit|search|mcp|web);
 *      - input  .nxa-src-value     → url/valore, placeholder contestuale
 *        per tipo (rss "URL feed", site "URL sito", youtube "@canale o
 *        URL canale", telegram "@canale", reddit "r/subreddit", search
 *        "parola chiave", mcp "server|tool|{args}", web "URL pagina");
 *      - input  .nxa-src-query     → query (SOLO youtube/search);
 *      - label  .nxa-src-full-wrap → checkbox .nxa-src-full "Testo
 *        completo" (SOLO rss/site/web, tooltip: estrazione Readability);
 *      - button .nxa-src-test      → "Test": POST nexus/v1/sources/test
 *        {type, url, query, redazione_id} (rotta WP8 in class-nexus-rest),
 *        esito inline in .nxa-src-test-status (✓ N item + primo titolo /
 *        ✗ errore, aria-live polite);
 *      - button .nxa-src-del       → "✕" rimuove la riga.
 * Bottone #nxa-red-sources-add aggiunge una riga (default rss).
 *
 * SHAPE INVARIATA (term meta _nexus_sources): al salvataggio le righe
 * diventano [{type, url, query?, full?, profile?}] e vengono salvate con
 * lo STESSO meccanismo della pagina (v1.1.4): POST wp/v2/redazioni/{id}
 * body {meta:{_nexus_sources: [...]}} — sanitize_callback server-side
 * Nexus_Redazioni::sanitize_sources (Wave 0: accetta la shape completa).
 * profile NON ha input nella UI: viaggia come data-profile sulla riga e
 * viene riemesso intatto (round-trip senza perdite).
 *
 * 6) TAKEOVER DEL SUBMIT (v1.1.10, WP8) — perché e come:
 * admin.js onRedazioneFormSubmit salva le fonti leggendo la textarea con
 * parseRedSources (SOLO rss/site con URL http, query/full perse) e in
 * modifica scrive SEMPRE il meta: senza takeover ogni salvataggio
 * degraderebbe le fonti nuove. Lo script di questa vista registra su
 * document un listener 'submit' in CAPTURE (i listener document-capture
 * girano SEMPRE prima dei listener del form) che, per #nxa-red-form con
 * nome non vuoto: preventDefault + stopPropagation (il flusso legacy di
 * admin.js non parte) e replica fedelmente il flusso admin.js — POST
 * nexus/v1/redazioni (crea) o /redazioni/{id} (update) con {name, parent,
 * agent_ids, slug?}, poi salvataggio fonti full-shape via wp/v2 (in
 * modifica SEMPRE, array vuoto = pulisce; in creazione solo se ci sono
 * righe), quindi ricaricamento pagina (l'albero vive nell'IIFE di
 * admin.js e non è raggiungibile dall'esterno). Se il nome è vuoto NON
 * intercetta: admin.js gestisce la validazione/toast come prima.
 * Caricamento fonti in modifica: poll 400ms su #nxa-red-id (property
 * .value, non osservabile con MutationObserver): a cambio id → GET
 * wp/v2/redazioni/{id}?context=edit → meta._nexus_sources renderizzato
 * (accetta array shape nuova E righe testuali legacy "type|url").
 * Il click su #nxa-red-cancel (reset admin.js) riporta l'id a 0 e le
 * righe vengono svuotate dallo stesso poll.
 * Salvaguardia BC: la textarea nascosta è sincronizzata con righe
 * "type|url" a ogni modifica, così un eventuale flusso legacy che la
 * leggesse non salva MAI un array vuoto quando ci sono righe valide.
 * Autenticazione: stesso header X-WP-Nonce di admin.js (NexusAdminData).
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
                                        <span class="nxa-red-field-label"><?php esc_html_e( 'Fonti della redazione', 'nexus-news-ai' ); ?></span>
                                        <span class="description"><?php esc_html_e( 'Aggiungi le fonti della redazione: feed RSS, siti, canali YouTube o Telegram, subreddit, ricerche, server MCP o pagine web. Usa “Test” per provare una fonte prima di salvare. Le fonti sono opzionali: i giornalisti della redazione le useranno in raccolta e il capo redattore per verificare.', 'nexus-news-ai' ); ?></span>
                                        <!-- v1.1.10 (WP8): textarea nascosta di compatibilità per admin.js (load/reset la
                                             referenziano); NON è più modificata dall'utente. Lo script inline la tiene
                                             sincronizzata con le righe legacy "type|url" come fallback (contratto di testa §5/§6). -->
                                        <textarea id="nxa-red-sources" class="widefat" rows="3" hidden aria-hidden="true" tabindex="-1"></textarea>
                                </p>
                                <!-- Editor fonti strutturato (v1.1.10, WP8): righe fonte generate dallo script inline
                                     in coda alla vista (select tipo 8 valori, url/valore, query per youtube/search,
                                     full per rss/site/web, Test via POST nexus/v1/sources/test, rimuovi). -->
                                <div id="nxa-red-sources-editor" class="nxa-src-editor nxa-red-field" aria-live="polite"></div>
                                <p class="nxa-red-field">
                                        <button type="button" id="nxa-red-sources-add" class="button button-secondary">+ <?php esc_html_e( 'Aggiungi fonte', 'nexus-news-ai' ); ?></button>
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

        <?php /* v1.1.10 (WP8): script self-contained dell'editor fonti — contratto nel docblock di testa (§5/§6). Nessun dato utente in innerHTML: righe costruite con createElement/textContent. */ ?>
        <script>
        ( function () {
                'use strict';

                if ( window.__nxaSourcesEditor ) {
                        return;
                }
                window.__nxaSourcesEditor = true;

                /* Tipi = Nexus_Redazioni::TYPES (8). Placeholder contestuale e
                 * flag UI per query (youtube/search) e full (rss/site/web). */
                var TYPE_LABELS = {
                        rss: 'RSS (feed diretto)',
                        site: 'Sito (feed auto)',
                        youtube: 'YouTube',
                        telegram: 'Telegram',
                        reddit: 'Reddit',
                        search: 'Ricerca web',
                        mcp: 'MCP',
                        web: 'Pagina web'
                };
                var TYPE_PLACEHOLDERS = {
                        rss: 'URL del feed (https://…)',
                        site: 'URL del sito (https://…)',
                        youtube: '@canale o URL del canale',
                        telegram: '@canale',
                        reddit: 'r/subreddit',
                        search: 'Parola chiave da cercare',
                        mcp: 'server|tool|{args}',
                        web: 'URL della pagina (https://…)'
                };
                var QUERY_TYPES = [ 'youtube', 'search' ];
                var FULL_TYPES = [ 'rss', 'site', 'web' ];

                var D = null;
                var editor = null;
                var legacyField = null;
                var booted = false;
                var tries = 0;
                var lastId = null;
                var saving = false;

                function qs( selector, root ) {
                        return ( root || document ).querySelector( selector );
                }
                function qsa( selector, root ) {
                        return Array.prototype.slice.call( ( root || document ).querySelectorAll( selector ) );
                }
                function num( value ) {
                        var n = Number( value );
                        return isNaN( n ) ? 0 : n;
                }

                /* REST nexus/v1 (stessa auth di admin.js: X-WP-Nonce + cookie). */
                function apiNexus( path, options ) {
                        var opts = options || {};
                        var method = ( opts.method || 'GET' ).toUpperCase();
                        var init = {
                                method: method,
                                headers: {
                                        'X-WP-Nonce': D.nonce,
                                        'Content-Type': 'application/json'
                                },
                                credentials: 'same-origin'
                        };
                        if ( method !== 'GET' && opts.body !== undefined ) {
                                init.body = JSON.stringify( opts.body );
                        }
                        var base = String( D.restUrl || '' ).replace( /\/+$/, '' );
                        return fetch( base + '/' + String( path ).replace( /^\/+/, '' ), init ).then( handleJson );
                }

                /* REST nativa wp/v2 (root derivata da restUrl, come admin.js apiWp). */
                function apiWp( path, options ) {
                        var opts = options || {};
                        var method = ( opts.method || 'GET' ).toUpperCase();
                        var init = {
                                method: method,
                                headers: {
                                        'X-WP-Nonce': D.nonce,
                                        'Content-Type': 'application/json'
                                },
                                credentials: 'same-origin'
                        };
                        if ( method !== 'GET' && opts.body !== undefined ) {
                                init.body = JSON.stringify( opts.body );
                        }
                        var root = String( D.restUrl || '' ).replace( /\/nexus\/v1\/?$/, '' ).replace( /\/+$/, '' );
                        return fetch( root + '/' + String( path ).replace( /^\/+/, '' ), init ).then( handleJson );
                }

                function handleJson( res ) {
                        return res.text().then( function ( text ) {
                                var data = null;
                                if ( text ) {
                                        try { data = JSON.parse( text ); } catch ( e ) { data = null; }
                                }
                                if ( ! res.ok ) {
                                        var err = new Error( ( data && data.message ) ? data.message : 'Errore ' + res.status );
                                        err.status = res.status;
                                        throw err;
                                }
                                return data;
                        } );
                }

                function unwrap( payload ) {
                        if ( payload && typeof payload === 'object' && ! Array.isArray( payload ) &&
                                Object.prototype.hasOwnProperty.call( payload, 'data' ) &&
                                Object.prototype.hasOwnProperty.call( payload, 'success' ) ) {
                                return payload.data;
                        }
                        return payload;
                }

                /* Toast minimale: stessi markup/classi di admin.js (CSS già in
                 * nexus-admin.css). Testo passato SOLO via textContent. */
                function showToast( message, type, title ) {
                        var kind = ( type === 'error' || type === 'success' ) ? type : 'info';
                        var stack = qs( '.nxa-toast-stack' );
                        if ( ! stack ) {
                                stack = document.createElement( 'div' );
                                stack.className = 'nxa-toast-stack';
                                document.body.appendChild( stack );
                        }
                        var node = document.createElement( 'div' );
                        node.className = 'nxa-toast nxa-toast--' + kind;
                        node.setAttribute( 'role', 'status' );
                        var strong = document.createElement( 'strong' );
                        strong.setAttribute( 'aria-hidden', 'true' );
                        strong.textContent = kind === 'error' ? '✕' : ( kind === 'success' ? '✓' : 'ℹ' );
                        var box = document.createElement( 'div' );
                        if ( title ) {
                                var head = document.createElement( 'p' );
                                head.className = 'nxa-toast__title';
                                head.textContent = title;
                                box.appendChild( head );
                        }
                        var msg = document.createElement( 'p' );
                        msg.className = 'nxa-toast__msg';
                        msg.textContent = String( message || '' );
                        box.appendChild( msg );
                        node.appendChild( strong );
                        node.appendChild( box );
                        stack.appendChild( node );
                        window.setTimeout( function () { node.classList.add( 'nxa-toast--in' ); }, 20 );
                        window.setTimeout( function () {
                                if ( node.parentNode ) {
                                        node.parentNode.removeChild( node );
                                }
                        }, 6000 );
                }

                /* ---------- Righe fonti ---------- */

                /* Normalizza un item del meta (shape array nuova O riga testuale
                 * legacy "type|url") nella shape interna dell'editor. */
                function normalizeItem( raw ) {
                        if ( raw && typeof raw === 'object' ) {
                                return {
                                        type: String( raw.type || 'rss' ).toLowerCase(),
                                        url: String( raw.url || '' ),
                                        query: raw.query ? String( raw.query ) : '',
                                        full: raw.full ? 1 : 0,
                                        profile: raw.profile ? String( raw.profile ) : ''
                                };
                        }
                        var line = String( raw == null ? '' : raw ).trim();
                        if ( ! line ) {
                                return null;
                        }
                        var parts = line.split( '|' );
                        if ( parts.length >= 2 && TYPE_LABELS.hasOwnProperty( parts[ 0 ].trim().toLowerCase() ) ) {
                                return {
                                        type: parts[ 0 ].trim().toLowerCase(),
                                        url: parts.slice( 1 ).join( '|' ).trim(),
                                        query: '',
                                        full: 0,
                                        profile: ''
                                };
                        }
                        return { type: 'rss', url: line, query: '', full: 0, profile: '' };
                }

                function applyTypeUI( row ) {
                        if ( ! row ) {
                                return;
                        }
                        var select = qs( '.nxa-src-type', row );
                        var value = qs( '.nxa-src-value', row );
                        var query = qs( '.nxa-src-query', row );
                        var fullWrap = qs( '.nxa-src-full-wrap', row );
                        var type = select ? select.value : 'rss';
                        if ( value ) {
                                value.placeholder = TYPE_PLACEHOLDERS.hasOwnProperty( type ) ? TYPE_PLACEHOLDERS[ type ] : '';
                        }
                        if ( query ) {
                                query.placeholder = 'Query (ricerca ' + type + ')';
                                query.style.display = QUERY_TYPES.indexOf( type ) !== -1 ? '' : 'none';
                        }
                        if ( fullWrap ) {
                                fullWrap.style.display = FULL_TYPES.indexOf( type ) !== -1 ? '' : 'none';
                        }
                }

                /* Legge le righe dell'editor nella shape del term meta:
                 * [{type, url, query?, full?, profile?}]. */
                function readRows() {
                        var items = [];
                        if ( ! editor ) {
                                return items;
                        }
                        qsa( '.nxa-src-row', editor ).forEach( function ( row ) {
                                var type = qs( '.nxa-src-type', row );
                                var value = qs( '.nxa-src-value', row );
                                if ( ! type || ! value ) {
                                        return;
                                }
                                var url = String( value.value || '' ).replace( /[\x00-\x1F\x7F]/g, '' ).trim();
                                if ( ! url ) {
                                        return; // riga vuota: ignorata
                                }
                                var item = { type: type.value, url: url };
                                var query = qs( '.nxa-src-query', row );
                                var queryValue = query ? String( query.value || '' ).trim() : '';
                                if ( queryValue && QUERY_TYPES.indexOf( type.value ) !== -1 ) {
                                        item.query = queryValue;
                                }
                                var full = qs( '.nxa-src-full', row );
                                if ( full && full.checked && FULL_TYPES.indexOf( type.value ) !== -1 ) {
                                        item.full = 1;
                                }
                                var profile = row.getAttribute( 'data-profile' );
                                if ( profile ) {
                                        item.profile = profile; // round-trip senza input dedicato
                                }
                                items.push( item );
                        } );
                        return items;
                }

                /* Fallback BC: sincronizza la textarea nascosta con righe legacy
                 * "type|url" (admin.js le leggerebbe solo se il takeover non girasse). */
                function syncLegacy() {
                        if ( ! legacyField ) {
                                return;
                        }
                        var lines = readRows().map( function ( item ) {
                                return item.type + '|' + item.url;
                        } );
                        legacyField.value = lines.join( '\n' );
                }

                function clearRows() {
                        if ( ! editor ) {
                                return;
                        }
                        while ( editor.firstChild ) {
                                editor.removeChild( editor.firstChild );
                        }
                        syncLegacy();
                }

                /* Crea una riga via createElement + textContent: nessun dato
                 * utente passa da innerHTML (guardia XSS, contratto §5). */
                function addRow( raw ) {
                        if ( ! editor ) {
                                return;
                        }
                        var it = normalizeItem( raw );
                        if ( ! it || ! TYPE_LABELS.hasOwnProperty( it.type ) ) {
                                it = { type: 'rss', url: it ? it.url : '', query: '', full: 0, profile: '' };
                        }

                        var row = document.createElement( 'div' );
                        row.className = 'nxa-src-row';
                        if ( it.profile ) {
                                row.setAttribute( 'data-profile', it.profile );
                        }

                        var select = document.createElement( 'select' );
                        select.className = 'nxa-src-type';
                        select.setAttribute( 'aria-label', 'Tipo di fonte' );
                        Object.keys( TYPE_LABELS ).forEach( function ( key ) {
                                var option = document.createElement( 'option' );
                                option.value = key;
                                option.textContent = TYPE_LABELS[ key ];
                                if ( key === it.type ) {
                                        option.selected = true;
                                }
                                select.appendChild( option );
                        } );

                        var value = document.createElement( 'input' );
                        value.type = 'text';
                        value.className = 'nxa-src-value regular-text';
                        value.value = it.url; // assegnazione via property: mai innerHTML
                        value.setAttribute( 'aria-label', 'URL o valore della fonte' );
                        value.autocomplete = 'off';
                        value.spellcheck = false;

                        var query = document.createElement( 'input' );
                        query.type = 'text';
                        query.className = 'nxa-src-query regular-text';
                        query.value = it.query;
                        query.setAttribute( 'aria-label', 'Query della fonte' );
                        query.autocomplete = 'off';

                        var fullWrap = document.createElement( 'label' );
                        fullWrap.className = 'nxa-src-full-wrap';
                        fullWrap.title = 'Estrae l’articolo completo con Readability (solo rss, site e web).';
                        var full = document.createElement( 'input' );
                        full.type = 'checkbox';
                        full.className = 'nxa-src-full';
                        if ( it.full ) {
                                full.checked = true;
                        }
                        fullWrap.appendChild( full );
                        fullWrap.appendChild( document.createTextNode( ' Testo completo' ) );

                        var test = document.createElement( 'button' );
                        test.type = 'button';
                        test.className = 'button button-small nxa-src-test';
                        test.textContent = 'Test';

                        var status = document.createElement( 'span' );
                        status.className = 'nxa-src-test-status';
                        status.setAttribute( 'aria-live', 'polite' );

                        var del = document.createElement( 'button' );
                        del.type = 'button';
                        del.className = 'button button-small nxa-src-del';
                        del.textContent = '✕';
                        del.setAttribute( 'aria-label', 'Rimuovi fonte' );

                        row.appendChild( select );
                        row.appendChild( value );
                        row.appendChild( query );
                        row.appendChild( fullWrap );
                        row.appendChild( test );
                        row.appendChild( status );
                        row.appendChild( del );

                        editor.appendChild( row );
                        applyTypeUI( row );
                        syncLegacy();
                }

                /* ---------- Test di una riga (POST nexus/v1/sources/test) ---------- */

                function runTest( button ) {
                        var row = button.closest ? button.closest( '.nxa-src-row' ) : null;
                        if ( ! row ) {
                                return;
                        }
                        var type = qs( '.nxa-src-type', row );
                        var value = qs( '.nxa-src-value', row );
                        var query = qs( '.nxa-src-query', row );
                        var status = qs( '.nxa-src-test-status', row );
                        var url = value ? String( value.value || '' ).trim() : '';
                        if ( ! url ) {
                                if ( status ) {
                                        status.textContent = '✗ Inserisci prima il valore della fonte.';
                                }
                                return;
                        }
                        var body = {
                                type: type ? type.value : 'rss',
                                url: url,
                                redazione_id: num( ( qs( '#nxa-red-id' ) || {} ).value || 0 )
                        };
                        var queryValue = query ? String( query.value || '' ).trim() : '';
                        if ( queryValue && QUERY_TYPES.indexOf( body.type ) !== -1 ) {
                                body.query = queryValue;
                        }
                        button.disabled = true;
                        if ( status ) {
                                status.textContent = '… provo la fonte';
                        }
                        apiNexus( 'sources/test', { method: 'POST', body: body } ).then( function ( payload ) {
                                var data = unwrap( payload ) || {};
                                button.disabled = false;
                                if ( ! status ) {
                                        return;
                                }
                                if ( data.ok ) {
                                        var first = ( data.sample && data.sample[ 0 ] && data.sample[ 0 ].title ) ? ' — ' + data.sample[ 0 ].title : '';
                                        status.textContent = '✓ ' + num( data.items_found ) + ' item' + first;
                                } else {
                                        status.textContent = '✗ ' + ( data.error || 'test non riuscito' );
                                }
                        } ).catch( function ( err ) {
                                button.disabled = false;
                                if ( status ) {
                                        status.textContent = '✗ ' + ( err && err.message ? err.message : 'errore di rete' );
                                }
                        } );
                }

                /* ---------- Caricamento fonti in modalità edit ---------- */

                function loadSources( termId ) {
                        apiWp( 'wp/v2/redazioni/' + termId + '?context=edit' ).then( function ( term ) {
                                // se nel frattempo l'editore ha cambiato target, non toccare le righe
                                var idField = qs( '#nxa-red-id' );
                                if ( ! idField || num( idField.value ) !== termId ) {
                                        return;
                                }
                                var meta = term && term.meta ? term.meta : {};
                                var raw = meta._nexus_sources;
                                clearRows();
                                ( Array.isArray( raw ) ? raw : [] ).forEach( function ( item ) {
                                        addRow( item );
                                } );
                        } ).catch( function ( err ) {
                                window.console.warn( '[Nexus] fonti redazione:', err );
                        } );
                }

                /* Poll su #nxa-red-id: la property .value non è osservabile con
                 * MutationObserver (contratto §6). 400ms, solo a cambio id. */
                function startPoll() {
                        window.setInterval( function () {
                                var idField = qs( '#nxa-red-id' );
                                var id = idField ? num( idField.value ) : 0;
                                if ( id === lastId ) {
                                        return;
                                }
                                lastId = id;
                                if ( id > 0 ) {
                                        loadSources( id );
                                } else {
                                        clearRows();
                                }
                        }, 400 );
                }

                /* ---------- TAKEOVER del submit (contratto §6) ---------- */

                function saveAll( form, name ) {
                        if ( saving ) {
                                return;
                        }
                        var idField = qs( '#nxa-red-id', form );
                        var editId = num( idField ? idField.value : 0 );
                        var select = qs( '#nxa-red-parent', form );
                        var slugField = qs( '#nxa-red-slug', form );
                        var slug = slugField ? String( slugField.value || '' ).trim() : '';
                        var items = readRows();

                        var body = {
                                name: name,
                                parent: select ? num( select.value ) : 0,
                                agent_ids: qsa( 'input.nxa-red-agent:checked', form ).map( function ( box ) {
                                        return num( box.value );
                                } ).filter( function ( n ) { return n > 0; } )
                        };
                        if ( slug ) {
                                body.slug = slug; // inviato solo se compilato, come admin.js
                        }

                        var saveBtn = qs( '#nxa-red-save', form );
                        saving = true;
                        if ( saveBtn ) {
                                saveBtn.disabled = true;
                        }

                        apiNexus( editId > 0 ? 'redazioni/' + editId : 'redazioni', { method: 'POST', body: body } ).then( function ( payload ) {
                                var data = unwrap( payload ) || {};
                                var savedId = editId > 0 ? editId : num( data.term_id );
                                // stessa semantica admin.js: in modifica SEMPRE (array
                                // vuoto = pulisce), in creazione solo se ci sono righe
                                var needsSources = savedId > 0 && ( editId > 0 || items.length );
                                var after = needsSources
                                        ? apiWp( 'wp/v2/redazioni/' + savedId, { method: 'POST', body: { meta: { _nexus_sources: items } } } ).catch( function ( err ) {
                                                // redazione salvata ma fonti no: come admin.js si avvisa e si prosegue
                                                showToast( err && err.message ? err.message : 'Errore sconosciuto.', 'error', 'Redazione salvata ma fonti non salvate' );
                                        } )
                                        : Promise.resolve();
                                return after.then( function () {
                                        // l'albero vive nell'IIFE di admin.js (non raggiungibile):
                                        // ricaricamento pagina = stesso stato finale del flusso legacy
                                        window.location.reload();
                                } );
                        } ).catch( function ( err ) {
                                saving = false;
                                if ( saveBtn ) {
                                        saveBtn.disabled = false;
                                }
                                showToast( err && err.message ? err.message : 'Errore sconosciuto.', 'error', 'Salvataggio redazione non riuscito' );
                        } );
                }

                function bindTakeover() {
                        // document-capture: gira SEMPRE prima dei listener del form,
                        // qualunque sia l'ordine di caricamento degli script.
                        document.addEventListener( 'submit', function ( ev ) {
                                var form = ev.target;
                                if ( ! form || form.id !== 'nxa-red-form' ) {
                                        return;
                                }
                                var nameField = qs( '#nxa-red-name', form );
                                var name = nameField ? String( nameField.value || '' ).trim() : '';
                                if ( ! name ) {
                                        return; // senza nome: valida/toasta admin.js come prima
                                }
                                ev.preventDefault();
                                ev.stopPropagation(); // il flusso legacy di admin.js non parte
                                saveAll( form, name );
                        }, true );
                }

                /* ---------- Boot (attesa NexusAdminData nei footer, pattern jobs.php) ---------- */

                function boot() {
                        if ( booted ) {
                                return;
                        }
                        D = window.NexusAdminData;
                        editor = qs( '#nxa-red-sources-editor' );
                        legacyField = qs( '#nxa-red-sources' );
                        if ( ! editor || ! D || ! D.restUrl || ! D.nonce ) {
                                return;
                        }
                        booted = true;

                        bindTakeover();

                        editor.addEventListener( 'click', function ( ev ) {
                                var target = ev.target;
                                if ( ! target || ! target.closest ) {
                                        return;
                                }
                                if ( target.closest( '.nxa-src-del' ) ) {
                                        var row = target.closest( '.nxa-src-row' );
                                        if ( row && row.parentNode ) {
                                                row.parentNode.removeChild( row );
                                                syncLegacy();
                                        }
                                        return;
                                }
                                if ( target.closest( '.nxa-src-test' ) ) {
                                        runTest( target.closest( '.nxa-src-test' ) );
                                }
                        } );

                        editor.addEventListener( 'change', function ( ev ) {
                                if ( ev.target && ev.target.classList && ev.target.classList.contains( 'nxa-src-type' ) ) {
                                        applyTypeUI( ev.target.closest( '.nxa-src-row' ) );
                                }
                                syncLegacy();
                        } );

                        editor.addEventListener( 'input', function () {
                                syncLegacy();
                        } );

                        var addBtn = qs( '#nxa-red-sources-add' );
                        if ( addBtn ) {
                                addBtn.addEventListener( 'click', function () {
                                        addRow( null );
                                        var rows = qsa( '.nxa-src-row', editor );
                                        var last = rows.length ? qs( '.nxa-src-value', rows[ rows.length - 1 ] ) : null;
                                        if ( last ) {
                                                last.focus();
                                        }
                                } );
                        }

                        // "Annulla": admin.js azzera #nxa-red-id, il poll svuota le righe;
                        // qui si svuotano subito per feedback immediato.
                        var cancelBtn = qs( '#nxa-red-cancel' );
                        if ( cancelBtn ) {
                                cancelBtn.addEventListener( 'click', function () {
                                        clearRows();
                                } );
                        }

                        startPoll();
                }

                function waitData() {
                        if ( booted ) {
                                return;
                        }
                        if ( window.NexusAdminData && window.NexusAdminData.restUrl && window.NexusAdminData.nonce && qs( '#nxa-red-sources-editor' ) ) {
                                boot();
                                return;
                        }
                        tries++;
                        if ( tries > 100 ) {
                                // ~10s senza NexusAdminData: ripristina la textarea legacy
                                // così il flusso admin.js resta utilizzabile (BC, §6).
                                var field = qs( '#nxa-red-sources' );
                                if ( field ) {
                                        field.removeAttribute( 'hidden' );
                                }
                                return;
                        }
                        window.setTimeout( waitData, 100 );
                }

                if ( document.readyState === 'loading' ) {
                        document.addEventListener( 'DOMContentLoaded', waitData );
                } else {
                        waitData();
                }
        }() );
        </script>

</div>
