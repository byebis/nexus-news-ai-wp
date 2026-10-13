/**
 * Nexus News AI — Interfaccia admin (JavaScript vanilla, ES2019).
 *
 * File unico, senza jQuery né build step. Viene caricato SOLO nelle pagine
 * admin del plugin e comunica con il backend tramite la REST route del
 * plugin, autenticandosi con l'header X-WP-Nonce.
 *
 * Dipendenze (wp_localize_script):
 *   window.NexusAdminData = {
 *     restUrl: 'https://sito.it/wp-json/nexus/v1',
 *     nonce:   '…',       // header X-WP-Nonce
 *     version: '1.0.0',
 *     siteUrl: 'https://sito.it'
 *   };
 *
 * Convenzioni:
 *  - IIFE + 'use strict', nessuna variabile globale (a parte il fallback CSS).
 *  - Ogni pannello è isolato in try/catch: un errore non blocca gli altri.
 *  - Tutti gli errori utente finiscono in toast (mai alert()).
 *  - Le skeleton/classi (.nxa-skeleton, .nxa-badge, .nxa-toast, …) sono
 *    definite dal foglio di stile del plugin; qui iniettiamo solo un CSS
 *    "di soccorso" minimale, inserito come PRIMO nodo del <head> così che
 *    lo stylesheet reale prevalga sempre a parità di specificità.
 *
 * v1.1.3: modal dettaglio mobile-safe (flex-start, dvh, overflow-wrap) e link "Vedi bozza WordPress" (wpViewLink).
 * v1.1.4: barra "Creazione rapida" con preset agenti (prefill + modal), editor con ruolo/provider AI/ambito capo redattore, badge e "Nota del capo redattore" in coda e dettaglio, bottone "Revisione capo redattore" (POST /chief/run/{id}) e flag settings capo/strumenti.
 * v1.1.5: bottoni "Avvia" (agente/redazione/testata) sul POST pipeline/run esteso con toast riepilogo e blocco .nxa-run-summary, e pannello live "In redazione ora" in dashboard (#nxa-live-strip: GET /live con polling 5s solo a pagina visibile, 404 → strip nascosta in silenzio).
 * v1.1.10 (F2b/WP11): sub-sezione "Provider personalizzati" in Impostazioni AI — CRUD REST /ai/custom-providers (chiave MAI in chiaro: solo has_key), preset 1-click Together/Fireworks/Perplexity/Cerebras/LM Studio/vLLM, delete con conferma INLINE in riga; i bottoni Test delle righe riusano il wizard WP10 (.nxa-prov-test → POST /ai/test/cust_{id}).
 * v1.1.10 (WP4/C1): modal agente — sezione "Strumenti personali (opzionali)": provider ricerca per-agente (#nxa-agent-search-provider, '' = globale, senza "none"), chiave search_key (password: inviata ma non persistente in questa wave, la scarta la whitelist REST — patch wave 4) e server MCP personali (#nxa-agent-mcp-servers, cascata con i globali: vince l'agente). Prefill al load con fallback vuoto client-side (serialize_agent non espone ancora i campi: wave 4); serializzazione search_provider/search_key/mcp_servers_text nei POST/PUT /agents.
 * v1.1.11 (R5): guardia fetch catalogo modelli — toggle models_fetch_enabled serializzato 0/1 (blocco providerFlags), flag onesti del server (fetch_disabled/stale) propagati dal resolve di fetchProviderModels, wrapper in coda al file che trasforma "fetch disattivato + nessuna cache" in un errore onesto (messaggio rosso nel picker e toast sul "Carica modelli") e status con suffissi "(fetch disattivato)" / "(cache scaduta…)".
 * v1.1.11 (R3): wizard di onboarding "Crea la tua redazione" (modal #nxa-onboarding, partial admin/views/onboarding-wizard.php incluso da agents.php): con 0 agenti la pagina Agenti apre il wizard al primo render (una volta per vista) e l'empty state porta il bottone "Apri il wizard"; pacchetti preset one-click (1/3/5 agenti o 7+capo) eseguiti come sequenza di POST /agents con progresso e riepilogo onesto, opzione capo redattore e PUT /settings {global_rss_sources} (stringa una URL per riga — contratto branch R1); la dashboard porta la card CTA con hash #nxa-onboarding.
 * v1.1.13 (O4, feedback/audit UI): wizard NON chiudibile a run in corso (X/Annulla/ESC → toast informativo, chiusura solo a run concluso o fallito); conferme distruttive INLINE uniformi (pattern custom provider: pipeline, eliminazione agente, eliminazione redazione — zero window.confirm); focus trap + ripristino focus per #nxa-modal e #nxa-onboarding (Tab confinato, ESC coerente, opener ricordato); stringhe wizard/confirm passate da nxaT() (dizionario esatto + pattern).
 *
 * @package Nexus_News_AI
 */

( function () {
        'use strict';

        /* ==================================================================
         * 0. GUARDIA D'INGRESSO
         * ================================================================== */

        var D = window.NexusAdminData;

        if ( ! D || ! D.restUrl || ! D.nonce ) {
                if ( window.console && window.console.warn ) {
                        window.console.warn( '[Nexus News AI] NexusAdminData non disponibile: script admin non inizializzato.' );
                }
                return;
        }

        var REST = String( D.restUrl ).replace( /\/+$/, '' );

        /* ==================================================================
         * 1. COSTANTI DI PRESENTAZIONE
         * ================================================================== */

        /** Colore per categoria — dalla 1.0.6 delegato ai token CSS:
         *  il chip porta l'attributo data-category e il foglio Nexus mappa
         *  --nxa-cat/-soft/-line (palette chiara di default, override scuro
         *  su body.nexus-theme-dark). Nessuna palette duplicata qui. */

        /** Stato → [ etichetta IT, variante badge ]. */
        var STATUS_META = {
                published:        [ 'Pubblicato', 'success' ],
                approved:         [ 'Approvato', 'info' ],
                pending_approval: [ 'In attesa', 'warning' ],
                pending:          [ 'In attesa', 'warning' ],
                draft:            [ 'Bozza', 'neutral' ],
                rejected:         [ 'Rifiutato', 'error' ],
                active:           [ 'Attivo', 'success' ],
                paused:           [ 'In pausa', 'warning' ],
                error:            [ 'Errore', 'error' ],
                success:          [ 'Successo', 'success' ],
                warning:          [ 'Attenzione', 'warning' ],
                info:             [ 'Info', 'neutral' ],
                completed:        [ 'Completata', 'success' ],
                failed:           [ 'Fallita', 'error' ],
                running:          [ 'In corso', 'warning' ]
        };

        /** Azione attività → icona + badge. */
        var ACTION_META = {
                collecting: { icon: '📡', label: 'Raccolta',      variant: 'info' },
                evaluating: { icon: '⚖️', label: 'Valutazione',   variant: 'warning' },
                rewriting:  { icon: '✍️', label: 'Riscrittura',   variant: 'primary' },
                publishing: { icon: '🚀', label: 'Pubblicazione', variant: 'success' },
                wire:       { icon: '🕸️', label: 'Wire',          variant: 'primary' }
        };
        var ACTION_DEFAULT = { icon: '📰', label: 'Evento', variant: 'neutral' };

        /** Fasi della redazione collettiva (Nexus Wire). */
        var WIRE_PHASES = [
                'Lettura delle fonti in arrivo…',
                'Gli agenti stanno scegliendo le fonti migliori…',
                'Ricercatore al lavoro sul dossier…',
                'Redattore capo sta scrivendo la bozza…',
                'Due revisori stanno valutando la bozza…',
                'Editor responsabile sta fondendo le revisioni…',
                'Invio in coda di approvazione…'
        ];

        /** Suggerimenti argomenti (fedeli al pannello React). */
        var WIRE_IDEAS = [
                'Intelligenza artificiale e lavoro',
                'Transizione energetica in Europa',
                'Elezioni e politica digitale',
                'Missione lunare Artemis',
                'Economia: tassi e inflazione',
                'Nuove terapie nel settore salute'
        ];

        /* ==================================================================
         * 2. HELPER GENERICI
         * ================================================================== */

        function qs( selector, root ) {
                return ( root || document ).querySelector( selector );
        }

        function qsa( selector, root ) {
                return Array.prototype.slice.call( ( root || document ).querySelectorAll( selector ) );
        }

        /** Escape HTML per qualsiasi valore dinamico. */
        function esc( str ) {
                return String( str === null || str === undefined ? '' : str )
                        .replace( /&/g, '&amp;' )
                        .replace( /</g, '&lt;' )
                        .replace( />/g, '&gt;' )
                        .replace( /"/g, '&quot;' )
                        .replace( /'/g, '&#39;' );
        }

        /** Crea un elemento da una stringa HTML. */
        function el( html ) {
                var tpl = document.createElement( 'template' );
                tpl.innerHTML = String( html ).trim();
                return tpl.content.firstElementChild;
        }

        /**
         * Legge il primo campo esistente tra più nomi (snake_case WP o camelCase).
         * Evita null/undefined/'' ma accetta 0 e false.
         */
        function pick( obj ) {
                for ( var i = 1; i < arguments.length; i++ ) {
                        var key = arguments[ i ];
                        if ( obj && Object.prototype.hasOwnProperty.call( obj, key ) ) {
                                var val = obj[ key ];
                                if ( val !== null && val !== undefined && val !== '' ) {
                                        return val;
                                }
                        }
                }
                return undefined;
        }

        function num( value ) {
                var n = Number( value );
                return isNaN( n ) ? 0 : n;
        }

        function fmt( value ) {
                return num( value ).toLocaleString( 'it-IT' );
        }

        function debounce( fn, wait ) {
                var timer = null;
                return function () {
                        var args = arguments;
                        var self = this;
                        if ( timer ) {
                                window.clearTimeout( timer );
                        }
                        timer = window.setTimeout( function () {
                                timer = null;
                                fn.apply( self, args );
                        }, wait || 250 );
                };
        }

        /**
         * Tempo relativo in italiano: Adesso / X min fa / X ore fa / X giorni fa.
         * Accetta ISO o 'YYYY-MM-DD HH:MM:SS' (tempo locale del sito WordPress).
         */
        function timeAgoIt( iso ) {
                if ( ! iso ) {
                        return 'Mai';
                }
                var raw = String( iso ).replace( ' ', 'T' );
                var date = new Date( raw );
                if ( isNaN( date.getTime() ) ) {
                        return esc( iso );
                }
                var diff = Date.now() - date.getTime();
                if ( diff < 0 ) {
                        diff = 0;
                }
                var mins = Math.floor( diff / 60000 );
                if ( mins < 1 ) {
                        return 'Adesso';
                }
                if ( mins < 60 ) {
                        return mins + ' min fa';
                }
                var hours = Math.floor( mins / 60 );
                if ( hours < 24 ) {
                        return hours === 1 ? '1 ora fa' : hours + ' ore fa';
                }
                var days = Math.floor( hours / 24 );
                if ( days < 7 ) {
                        return days === 1 ? '1 giorno fa' : days + ' giorni fa';
                }
                try {
                        return date.toLocaleDateString( 'it-IT', { day: 'numeric', month: 'short' } );
                } catch ( e ) {
                        return date.toISOString().slice( 0, 10 );
                }
        }

        function dateIt( iso ) {
                var date = new Date( String( iso || '' ).replace( ' ', 'T' ) );
                if ( isNaN( date.getTime() ) ) {
                        return esc( iso || '—' );
                }
                try {
                        return date.toLocaleString( 'it-IT', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' } );
                } catch ( e ) {
                        return esc( iso );
                }
        }

        /** Chip categoria colorata: i colori vivono nel CSS (token --nxa-cat
         *  mappati da [data-category]); qui solo attributo + struttura. */
        function categoryChip( category ) {
                var label = category ? String( category ) : 'Generale';
                var attr  = category ? ' data-category="' + esc( String( category ) ) + '"' : '';
                return '<span class="nxa-chip"' + attr +
                        ' style="background:var(--nxa-cat-soft,rgba(100,116,139,.12));color:var(--nxa-cat,#64748b);border:1px solid var(--nxa-cat-line,rgba(100,116,139,.35))">' +
                        esc( label ) + '</span>';
        }

        /** Badge di stato con classe colore. */
        function badge( status ) {
                var meta = STATUS_META[ String( status || '' ).toLowerCase() ] || [ String( status || '—' ), 'neutral' ];
                return '<span class="nxa-badge nxa-badge--' + meta[ 1 ] + '">' + esc( meta[ 0 ] ) + '</span>';
        }

        /** Badge punteggio qualità: ≥75 verde, ≥50 giallo, altrimenti rosso
         *  (varianti token .nxa-score is-high/is-mid/is-low del design system). */
        function scoreBadge( score ) {
                var value = num( score );
                var cls   = value >= 75 ? 'is-high' : ( value >= 50 ? 'is-mid' : 'is-low' );
                return '<span class="nxa-score ' + cls + '" title="Punteggio qualità">' +
                        Math.round( value ) + '%</span>';
        }

        /**
         * Badge "Post WP" (v1.0.1): link al post WordPress quando l'articolo è
         * stato pubblicato come post reale (wp_post_id > 0). La REST potrebbe
         * non restituire ancora i campi: lettura difensiva, mai crash.
         */
        function wpPostBadge( a ) {
                var row = a || {};
                var wpId = num( row.wp_post_id || row.wpPostId || 0 );
                if ( wpId <= 0 ) {
                        return '';
                }
                var href = row.post_permalink || row.permalink || ( '/?p=' + wpId );
                return '<a class="nxa-badge nxa-badge--primary" href="' + esc( href ) + '" target="_blank" rel="noopener noreferrer" title="Post WordPress #' + wpId + '">Post WP ↗</a>';
        }

        /**
         * Link "Vedi bozza WordPress" (v1.1.3): accanto al badge Post WP
         * apre il link più utile in base allo stato del post collegato.
         * Lettura difensiva come wpPostBadge: i campi wp_post_status,
         * wp_preview_url e wp_edit_url arrivano dalla REST con T-03, gli
         * articoli legacy senza post WP (wp_post_id assente o 0) non
         * mostrano nessun link, campi mancanti non crashano mai.
         */
        function wpViewLink( a ) {
                var row = a || {};
                var wpId = num( row.wp_post_id || row.wpPostId || 0 );
                if ( wpId <= 0 ) {
                        return '';
                }
                var status = String( row.wp_post_status || row.wpPostStatus || '' ).toLowerCase();
                var permalink = String( row.post_permalink || row.permalink || '' );
                var previewUrl = String( row.wp_preview_url || row.wpPreviewUrl || '' );
                var editUrl = String( row.wp_edit_url || row.wpEditUrl || '' );
                var href = '';
                var label = '';
                if ( status === 'publish' ) {
                        label = 'Vedi in WordPress';
                        href = permalink;
                } else {
                        label = 'Vedi bozza WordPress';
                        href = previewUrl || editUrl;
                        if ( ! href ) {
                                href = permalink || editUrl;
                        }
                }
                if ( ! href ) {
                        return '';
                }
                return '<a class="nxa-wp-view" href="' + esc( href ) + '" target="_blank" rel="noopener noreferrer" title="Apri il post WordPress nativo">' + esc( label ) + '</a>';
        }

        /* ------------------- CAPO REDATTORE (v1.1.4) ------------------- */

        /**
         * v1.1.4: chief_status dell'articolo (serialize_article T-05). Lettura
         * difensiva come wpPostBadge/wpViewLink: risposta vecchia senza i campi
         * → stringa vuota = nessun badge, mai crash.
         */
        function chiefStatusOf( a ) {
                return String( pick( a || {}, 'chief_status', 'chiefStatus' ) || '' ).toLowerCase();
        }

        /** v1.1.4: verdetto capo redattore → testo IT del contratto ('' = nessuno). */
        function chiefStatusLabel( status ) {
                var map = {
                        visto:      '🎖 Capo redattore: visto',
                        modificato: '✏ Capo redattore: modificato',
                        rifiutato:  '⛔ Capo redattore: rifiutato',
                        revisione:  '🔄 Revisione capo redattore'
                };
                return map[ String( status || '' ).toLowerCase() ] || '';
        }

        /** v1.1.4: badge capo redattore accanto allo stato articolo (coda + dettaglio). */
        function chiefBadge( a ) {
                var status = chiefStatusOf( a );
                var label = chiefStatusLabel( status );
                if ( ! label ) {
                        return '';
                }
                return '<span class="nxa-badge nxa-badge-chief nxa-badge-chief--' + esc( status ) + '">' + esc( label ) + '</span>';
        }

        /** v1.1.4: bottone forzatura revisione capo (usato in coda e dettaglio). */
        function chiefRunButtonHtml( chiefStatus, attrs ) {
                var extra = attrs || '';
                return '<button type="button" class="button nxa-chief-run" data-chief-status="' + esc( String( chiefStatus || '' ) ) + '"' + ( extra ? ' ' + extra : '' ) + '>🎖 Revisione capo redattore</button>';
        }

        /**
         * v1.1.4: blocco "Nota del capo redattore" del modal dettaglio, come
         * la box fact-check: classi + stili inline, chief_note/chief_reviewed_at
         * da serialize_article T-05; nota assente → blocco omesso.
         */
        function chiefNoteBlockHtml( a ) {
                var row = a || {};
                var note = String( pick( row, 'chief_note', 'chiefNote' ) || '' ).trim();
                if ( ! note ) {
                        return '';
                }
                var reviewed = String( pick( row, 'chief_reviewed_at', 'chiefReviewedAt' ) || '' );
                return '<div class="nxa-chief-note" style="margin:16px 0 4px;padding:12px 14px;border:1px solid var(--nxa-border);border-radius:10px;background:var(--nxa-surface-2)">' +
                                '<h4 style="margin:0 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--nxa-muted)">Nota del capo redattore</h4>' +
                                '<p style="margin:0;font-size:13px;line-height:1.6;white-space:pre-line">' + esc( note ) + '</p>' +
                                ( reviewed ? '<p style="margin:8px 0 0;font-size:11.5px;color:var(--nxa-muted)">' + esc( dateIt( reviewed ) ) + '</p>' : '' ) +
                        '</div>';
        }

        function actionMeta( action ) {
                return ACTION_META[ String( action || '' ).toLowerCase() ] || ACTION_DEFAULT;
        }

        function stripTags( text ) {
                return String( text || '' ).replace( /<[^>]*>/g, ' ' ).replace( /\s+/g, ' ' ).trim();
        }

        function excerpt( text, max ) {
                var clean = stripTags( text );
                if ( clean.length <= max ) {
                        return esc( clean );
                }
                return esc( clean.slice( 0, max ).replace( /\s+\S*$/, '' ) ) + '…';
        }

        /* ==================================================================
         * 3. API REST (fetch + nonce + gestione errori {code,message})
         * ================================================================== */

        function joinUrl( path ) {
                return REST + '/' + String( path ).replace( /^\/+/, '' );
        }

        /**
         * Chiamata REST. Ritorna il payload JSON; in caso di errore lancia
         * un Error con .status e .code (formato WP {code, message}).
         */
        function api( path, options ) {
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

                return fetch( joinUrl( path ), init ).then( function ( res ) {
                        return res.text().then( function ( text ) {
                                var data = null;
                                if ( text ) {
                                        try {
                                                data = JSON.parse( text );
                                        } catch ( e ) {
                                                data = null;
                                        }
                                }
                                if ( ! res.ok ) {
                                        var err = new Error( ( data && data.message ) ? data.message : 'Errore ' + res.status );
                                        err.status = res.status;
                                        err.code = data ? data.code : '';
                                        throw err;
                                }
                                if ( data && data.success === false ) {
                                        var fail = new Error( data.message || 'Operazione non riuscita' );
                                        fail.status = 200;
                                        fail.code = data.code || 'nexus_error';
                                        throw fail;
                                }
                                return data;
                        } );
                } );
        }

        /** Alcuni controller incapsulano in {success,data}: normalizza. */
        function unwrap( payload ) {
                if ( payload && typeof payload === 'object' && ! Array.isArray( payload ) &&
                        Object.prototype.hasOwnProperty.call( payload, 'data' ) &&
                        Object.prototype.hasOwnProperty.call( payload, 'success' ) ) {
                        return payload.data;
                }
                return payload;
        }

        /**
         * v1.1.4: chiamata REST nativa wp/v2 (stessa autenticazione nonce di api(),
         * root derivata da NexusAdminData.restUrl) usata per il meta _nexus_sources
         * delle redazioni (contratto nel docblock della vista redazioni.php).
         */
        function apiWp( path, options ) {
                var opts = options || {};
                var wpRoot = REST.replace( /\/nexus\/v1\/?$/, '' );
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
                return fetch( wpRoot.replace( /\/+$/, '' ) + '/' + String( path ).replace( /^\/+/, '' ), init ).then( function ( res ) {
                        return res.text().then( function ( text ) {
                                var data = null;
                                if ( text ) {
                                        try {
                                                data = JSON.parse( text );
                                        } catch ( e ) {
                                                data = null;
                                        }
                                }
                                if ( ! res.ok ) {
                                        var err = new Error( ( data && data.message ) ? data.message : 'Errore ' + res.status );
                                        err.status = res.status;
                                        err.code = data ? data.code : '';
                                        throw err;
                                }
                                return data || {};
                        } );
                } );
        }

        /**
         * Estrae una lista da forme diverse: array puro, {articles:[]},
         * {items:[]}, {runs:[]}, {logs:[]}, {activity:[]}, {data:[]}.
         */
        function getList( payload, keys ) {
                var data = unwrap( payload );
                if ( Array.isArray( data ) ) {
                        return data;
                }
                if ( data && typeof data === 'object' ) {
                        var candidates = ( keys || [ 'articles', 'items', 'runs', 'logs', 'activity', 'list' ] );
                        for ( var i = 0; i < candidates.length; i++ ) {
                                var val = data[ candidates[ i ] ];
                                if ( Array.isArray( val ) ) {
                                        return val;
                                }
                        }
                        if ( Array.isArray( data.data ) ) {
                                return data.data;
                        }
                }
                return [];
        }

        /* ==================================================================
         * 4. FALLBACK CSS MINIMALE (primo nodo del <head>: lo stylesheet
         *    reale del plugin, caricato dopo, ha sempre la precedenza).
         *    v1.1.3: modal dettaglio mobile-safe (align-items flex-start,
         *    scroll sull'overlay, max-height dvh con fallback vh,
         *    overflow-wrap nel body, media query 600px) e link .nxa-wp-view.
         * ================================================================== */

        var FALLBACK_CSS = [
                '#nxa-toast-stack{position:fixed;right:16px;bottom:16px;z-index:99999;display:flex;flex-direction:column;gap:8px;max-width:340px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}',
                '.nxa-toast{display:flex;gap:10px;align-items:flex-start;background:var(--nxa-surface);color:var(--nxa-text-body);border-radius:10px;padding:12px 14px;box-shadow:var(--nxa-shadow-lg);font-size:13px;line-height:1.45;opacity:0;transform:translateY(8px);transition:opacity .25s,transform .25s}',
                '.nxa-toast.nxa-toast--in{opacity:1;transform:none}',
                '.nxa-toast.nxa-toast--out{opacity:0;transform:translateY(6px)}',
                '.nxa-toast--success{border-left:4px solid var(--nxa-ok-text)}',
                '.nxa-toast--error{border-left:4px solid var(--nxa-err-text)}',
                '.nxa-toast--info{border-left:4px solid var(--nxa-info-text)}',
                '.nxa-toast__title{font-weight:600;margin:0 0 2px}',
                '.nxa-toast__msg{margin:0;color:var(--nxa-muted);word-break:break-word}',
                '.nxa-toast__close{background:none;border:0;color:inherit;cursor:pointer;font-size:16px;line-height:1;margin-left:auto;opacity:.7;padding:0 2px}',
                '.nxa-spinner{display:inline-block;width:14px;height:14px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:nxa-spin .7s linear infinite;vertical-align:-2px}',
                '@keyframes nxa-spin{to{transform:rotate(360deg)}}',
                '.nxa-skeleton{background:linear-gradient(90deg,var(--nxa-surface-3) 25%,var(--nxa-skeleton-hi) 37%,var(--nxa-surface-3) 63%);background-size:400% 100%;animation:nxa-shimmer 1.2s ease infinite;border-radius:6px;height:14px;margin:10px 0}',
                '@keyframes nxa-shimmer{0%{background-position:100% 50%}100%{background-position:0 50%}}',
                '.nxa-badge{display:inline-flex;align-items:center;gap:4px;border-radius:999px;padding:2px 10px;font-size:11px;font-weight:600;line-height:1.6;white-space:nowrap}',
                '.nxa-badge--success{background:var(--nxa-ok-bg);color:var(--nxa-ok-text)}',
                '.nxa-badge--warning{background:var(--nxa-warn-bg);color:var(--nxa-warn-text)}',
                '.nxa-badge--error{background:var(--nxa-err-bg);color:var(--nxa-err-text)}',
                '.nxa-badge--info{background:var(--nxa-info-bg);color:var(--nxa-info-text)}',
                '.nxa-badge--neutral{background:var(--nxa-surface-3);color:var(--nxa-text-body)}',
                '.nxa-badge--primary{background:var(--nxa-accent-1-soft);color:var(--nxa-accent-1)}',
                '.nxa-chip{display:inline-flex;align-items:center;border-radius:999px;padding:2px 10px;font-size:11px;font-weight:600;white-space:nowrap}',
                '.nxa-dot{width:7px;height:7px;border-radius:50%;display:inline-block}',
                '.nxa-empty{text-align:center;padding:44px 16px;color:var(--nxa-muted);font-size:14px}',
                '.nxa-empty__icon{font-size:34px;margin-bottom:8px}',
                '.nxa-empty__title{font-weight:600;font-size:16px;color:var(--nxa-text-body);margin:0 0 4px}',
                '.nxa-card{background:var(--nxa-surface);border:1px solid var(--nxa-border);border-radius:12px;padding:16px}',
                '.nxa-queue-item{background:var(--nxa-surface);border:1px solid var(--nxa-border);border-radius:12px;padding:14px 16px;margin-bottom:12px}',
                '.nxa-act-item{display:flex;gap:12px;padding:12px 4px;border-bottom:1px solid var(--nxa-border-soft)}',
                '.nxa-act-item:last-child{border-bottom:0}',
                '.nxa-act-icon{flex:0 0 auto;width:36px;height:36px;border-radius:50%;background:var(--nxa-surface-3);display:flex;align-items:center;justify-content:center;font-size:17px}',
                '.nxa-act-agent{font-weight:600;font-size:13px;color:var(--nxa-text)}',
                '.nxa-act-detail{font-size:12.5px;color:var(--nxa-muted);margin:3px 0 0;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}',
                '.nxa-act-time{font-size:11px;color:var(--nxa-muted);white-space:nowrap;margin-left:auto}',
                '.nxa-modal{position:fixed;left:0;top:0;right:0;bottom:0;z-index:100000;display:flex;align-items:flex-start;justify-content:center;overflow-y:auto;-webkit-overflow-scrolling:touch;background:rgba(15,23,42,var(--nxa-overlay-alpha,.55));padding:16px 16px 32px}',
                '.nxa-modal[hidden]{display:none}',
                '.nxa-modal__box{margin:auto;background:var(--nxa-surface);color:var(--nxa-text);max-width:760px;width:100%;max-height:calc(100vh - 32px);max-height:calc(100dvh - 32px);overflow:auto;border-radius:14px;box-shadow:var(--nxa-shadow-lg);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}',
                '.nxa-modal__head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 18px;border-bottom:1px solid var(--nxa-border);position:sticky;top:0;background:var(--nxa-surface);z-index:1}',
                '.nxa-modal__head h2{font-size:17px;margin:0}',
                '.nxa-modal__body{padding:18px;overflow-wrap:anywhere;word-break:break-word}',
                '.nxa-modal__close{background:none;border:0;font-size:22px;line-height:1;cursor:pointer;color:var(--nxa-muted)}',
                'body.nxa-modal-open{overflow:hidden}',
                '@media (max-width:600px){.nxa-modal{padding:8px;padding-bottom:20px}.nxa-modal__box{max-height:calc(100vh - 16px);max-height:calc(100dvh - 16px);border-radius:10px}.nxa-modal__body{padding:14px}.nxa-modal__head{padding:12px 14px}.nxa-modal__head h2{font-size:15px}}',
                '.nxa-wp-view{margin-left:8px;font-size:12px;text-decoration:none;color:inherit}',
                '.nxa-btn-row{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}',
                '.nxa-note-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:10px}',
                '.nxa-note-input{flex:1 1 220px;border:1px solid var(--nxa-input-line);border-radius:8px;padding:6px 10px;font-size:13px}',
                '.nxa-mini-agents{display:flex;flex-wrap:wrap;gap:10px}',
                '.nxa-mini-agent{display:flex;align-items:center;gap:8px;background:var(--nxa-surface);border:1px solid var(--nxa-border);border-radius:999px;padding:6px 12px 6px 6px;font-size:13px}',
                '.nxa-mini-agent__avatar{width:30px;height:30px;border-radius:50%;background:var(--nxa-surface-3);display:flex;align-items:center;justify-content:center;font-size:16px}',
                '.nxa-wire-run{background:var(--nxa-surface);border:1px solid var(--nxa-border);border-radius:12px;padding:12px 16px;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}',
                '.nxa-wire-status{margin-top:12px;border:1px solid var(--nxa-border);border-radius:10px;background:var(--nxa-surface-2);padding:12px 14px;font-size:13px;color:var(--nxa-muted)}',
                '.nxa-idea{border:1px solid var(--nxa-input-line);background:var(--nxa-surface);border-radius:999px;padding:3px 10px;font-size:12px;color:var(--nxa-muted);cursor:pointer;margin:0 6px 6px 0}',
                '.nxa-idea:hover{background:var(--nxa-surface-2)}'
        ].join( '\n' );

        function injectFallbackCss() {
                if ( qs( '#nxa-fallback-css' ) ) {
                        return;
                }
                var style = document.createElement( 'style' );
                style.id = 'nxa-fallback-css';
                style.textContent = FALLBACK_CSS;
                var head = document.head || qs( 'head' );
                if ( head ) {
                        head.insertBefore( style, head.firstChild );
                }
        }

        /* ==================================================================
         * 5. TOAST SYSTEM (success / error / info, auto-dismiss 4s)
         * ================================================================== */

        function ensureToastStack() {
                if ( ! qs( '#nxa-toast-stack' ) ) {
                        document.body.appendChild( el( '<div id="nxa-toast-stack" aria-live="polite"></div>' ) );
                }
                return qs( '#nxa-toast-stack' );
        }

        var TOAST_ICONS = { success: '✓', error: '✕', info: 'ℹ' };

        /** toast(messaggio, tipo, titolo) — tipo: success|error|info. */
        function toast( message, type, title ) {
                var stack = ensureToastStack();
                var kind = TOAST_ICONS[ type ] ? type : 'info';
                var defaultTitle = { success: 'Fatto', error: 'Errore', info: 'Info' }[ kind ];
                var node = el(
                        '<div class="nxa-toast nxa-toast--' + kind + '" role="status">' +
                                '<strong aria-hidden="true">' + ( TOAST_ICONS[ kind ] ) + '</strong>' +
                                '<div>' +
                                        ( title ? '<p class="nxa-toast__title">' + esc( title ) + '</p>' : '' ) +
                                        '<p class="nxa-toast__msg">' + esc( message ) + '</p>' +
                                '</div>' +
                                '<button type="button" class="nxa-toast__close" aria-label="Chiudi notifica">×</button>' +
                        '</div>'
                );
                node.setAttribute( 'data-default-title', defaultTitle );
                stack.appendChild( node );

                // massimo 4 toast a schermo: rimuovi i più vecchi
                while ( stack.children.length > 4 ) {
                        stack.removeChild( stack.firstChild );
                }

                window.setTimeout( function () {
                        node.classList.add( 'nxa-toast--in' );
                }, 20 );

                var dismiss = function () {
                        node.classList.remove( 'nxa-toast--in' );
                        node.classList.add( 'nxa-toast--out' );
                        window.setTimeout( function () {
                                if ( node.parentNode ) {
                                        node.parentNode.removeChild( node );
                                }
                        }, 300 );
                };

                var timer = window.setTimeout( dismiss, 4000 );
                node.addEventListener( 'click', function ( ev ) {
                        if ( ev.target && ev.target.classList && ev.target.classList.contains( 'nxa-toast__close' ) ) {
                                window.clearTimeout( timer );
                                dismiss();
                        }
                } );
        }

        /** Toast per errori REST (messa a fuoco su 403 = nonce scaduto). */
        function apiErrorToast( err, title ) {
                var message = ( err && err.message ) ? err.message : 'Si è verificato un errore inatteso.';
                if ( err && ( err.status === 403 || err.code === 'rest_cookie_invalid_nonce' ) ) {
                        message = 'Sessione scaduta o permessi insufficienti: ricarica la pagina e riprova.';
                }
                toast( message, 'error', title || 'Operazione non riuscita' );
        }

        /* ==================================================================
         * 6. UI COMUNE: spinner su bottoni, skeleton, empty state, modal
         * ================================================================== */

        function setBtnLoading( btn, loading, label ) {
                // v1.1.6: accetta anche una LISTA di bottoni (le pagine impostazioni
                // hanno la barra Salva in cima E in fondo): applica lo stato a tutti.
                if ( btn && typeof btn.length === 'number' && ! btn.nodeType ) {
                        for ( var i = 0; i < btn.length; i++ ) {
                                setBtnLoading( btn[ i ], loading, label );
                        }
                        return;
                }
                if ( ! btn ) {
                        return;
                }
                if ( loading ) {
                        if ( ! btn.getAttribute( 'data-nxa-label' ) ) {
                                btn.setAttribute( 'data-nxa-label', btn.innerHTML );
                        }
                        btn.disabled = true;
                        btn.classList.add( 'nxa-is-loading' );
                        btn.innerHTML = '<span class="nxa-spinner" aria-hidden="true"></span> ' + esc( label || 'Attendi…' );
                } else {
                        var original = btn.getAttribute( 'data-nxa-label' );
                        btn.disabled = false;
                        btn.classList.remove( 'nxa-is-loading' );
                        if ( original !== null ) {
                                btn.innerHTML = original;
                                btn.removeAttribute( 'data-nxa-label' );
                        }
                }
        }

        function showSkeleton( container, rows ) {
                var n = rows || 3;
                var html = '';
                for ( var i = 0; i < n; i++ ) {
                        html += '<div class="nxa-skeleton" style="width:' + ( 92 - i * 17 ) + '%"></div>';
                }
                container.innerHTML = html;
        }

        function emptyState( icon, title, hint ) {
                return '<div class="nxa-empty"><div class="nxa-empty__icon" aria-hidden="true">' + icon + '</div>' +
                        '<p class="nxa-empty__title">' + esc( title ) + '</p>' +
                        ( hint ? '<p>' + esc( hint ) + '</p>' : '' ) +
                        '</div>';
        }

        /* ------------------------- CONFERME INLINE ------------------------- */

        /**
         * v1.1.13 (O4): conferma distruttiva "cancellabile in riga" — stessa UX
         * del pattern custom provider (renderCustomProviders: niente
         * window.confirm nativo). Il bottone scatenante viene nascosto e al suo
         * posto compare un gruppo .nxa-inline-confirm con messaggio + Sì + No;
         * alla chiusura il bottone torna al suo posto. Una sola conferma aperta
         * per pagina: aprirne un'altra chiude la precedente. Il messaggio è
         * inserito come nodo TESTO (textContent): sicuro con nomi utente.
         */
        var inlineConfirmState = { anchor: null, box: null };

        /** Chiude la conferma inline aperta e ripristina il bottone nascosto. */
        function nxaInlineConfirmClose() {
                var box = inlineConfirmState.box;
                var anchor = inlineConfirmState.anchor;
                inlineConfirmState.box = null;
                inlineConfirmState.anchor = null;
                if ( box && box.parentNode ) {
                        box.parentNode.removeChild( box );
                }
                if ( anchor && anchor.parentNode ) {
                        anchor.style.display = '';
                }
        }

        /**
         * Apre la conferma inline accanto ad `anchor` (che viene nascosto e
         * ripristinato alla chiusura). `message` è testo puro (textContent),
         * `yesLabel` l'etichetta del bottone di conferma, `onConfirm` la
         * callback eseguita SOLO dopo "Sì" (la conferma è già chiusa).
         */
        function nxaInlineConfirmOpen( anchor, message, yesLabel, onConfirm ) {
                if ( ! anchor || ! anchor.parentNode ) {
                        return;
                }
                nxaInlineConfirmClose();

                var box = document.createElement( 'span' );
                box.className = 'nxa-inline-confirm';
                // Stessa pelle del pattern custom provider (.nxa-custprov-confirm,
                // definito nello style locale di settings-ai.php): qui inline perché
                // il gruppo compare su dashboard/agenti/redazioni che non caricano
                // quel foglio. Colori/spacing via token: dark theme intatto.
                box.style.display = 'inline-flex';
                box.style.flexWrap = 'wrap';
                box.style.gap = '6px';
                box.style.alignItems = 'center';
                box.style.fontWeight = '600';
                box.style.color = 'var(--nxa-err-text)';

                var text = document.createElement( 'span' );
                text.className = 'nxa-inline-confirm-msg';
                text.textContent = String( message === null || message === undefined ? '' : message );

                var yes = document.createElement( 'button' );
                yes.type = 'button';
                yes.className = 'button nxa-inline-confirm-yes';
                yes.textContent = String( yesLabel || nxaT( 'Sì' ) );

                var no = document.createElement( 'button' );
                no.type = 'button';
                no.className = 'button nxa-inline-confirm-no';
                no.textContent = nxaT( 'No' );

                var refocus = function () {
                        nxaInlineConfirmClose();
                        if ( anchor && typeof anchor.focus === 'function' ) {
                                anchor.focus();
                        }
                };

                yes.addEventListener( 'click', function () {
                        nxaInlineConfirmClose();
                        if ( typeof onConfirm === 'function' ) {
                                onConfirm();
                        }
                } );
                no.addEventListener( 'click', refocus );
                box.addEventListener( 'keydown', function ( ev ) {
                        if ( ev.key === 'Escape' ) {
                                // ESC chiude solo la conferma, non altri handler (modali).
                                ev.stopPropagation();
                                refocus();
                        }
                } );

                box.appendChild( text );
                box.appendChild( yes );
                box.appendChild( no );
                anchor.parentNode.insertBefore( box, anchor.nextSibling );
                anchor.style.display = 'none';
                inlineConfirmState.anchor = anchor;
                inlineConfirmState.box = box;
                // Il focus entra nel gruppo: ESC/Tab restano usabili da tastiera.
                yes.focus();
        }

        /* ------------------------- MODAL ------------------------- */

        /* v1.1.13 (O4): focus trap per le modali, senza dipendenze. All'apertura
         * si salva document.activeElement, il Tab (e Shift+Tab) cicla SOLO tra i
         * focusabili visibili del dialog e alla chiusura il focus torna
         * all'elemento che l'aveva aperta. Usata da #nxa-modal (ensureModal) e
         * dal wizard #nxa-onboarding. offsetParent === null filtra i nodi non
         * renderizzati (passi [hidden] del wizard, display:none). */
        var nxaModalFocusOpener = null;

        /** Salva chi ha aperto la modale (chiamato SOLO mentre è nascosta). */
        function nxaModalRememberFocus() {
                nxaModalFocusOpener = ( document.activeElement && document.activeElement !== document.body ) ? document.activeElement : null;
        }

        /** Ripristina il focus all'elemento che aveva aperto la modale. */
        function nxaModalRestoreFocus() {
                var opener = nxaModalFocusOpener;
                nxaModalFocusOpener = null;
                if ( opener && typeof opener.focus === 'function' && ( opener.isConnected === undefined || opener.isConnected ) ) {
                        opener.focus();
                }
        }

        /** Confinare il Tab dentro `modal` (listener keydown condiviso). */
        function nxaModalTrapTab( ev, modal ) {
                if ( ev.key !== 'Tab' || ! modal ) {
                        return;
                }
                var focusables = qsa(
                        'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
                        modal
                ).filter( function ( node ) {
                        return node.offsetParent !== null;
                } );
                if ( ! focusables.length ) {
                        ev.preventDefault();
                        return;
                }
                var first = focusables[ 0 ];
                var last = focusables[ focusables.length - 1 ];
                var active = document.activeElement;
                if ( ! modal.contains( active ) ) {
                        // Focus finito fuori (es. click sul backdrop): rientra nel dialog.
                        ev.preventDefault();
                        ( ev.shiftKey ? last : first ).focus();
                        return;
                }
                if ( ev.shiftKey && active === first ) {
                        ev.preventDefault();
                        last.focus();
                } else if ( ! ev.shiftKey && active === last ) {
                        ev.preventDefault();
                        first.focus();
                }
        }

        function ensureModal() {
                if ( qs( '#nxa-modal' ) ) {
                        return qs( '#nxa-modal' );
                }
                var modal = el(
                        '<div id="nxa-modal" class="nxa-modal" hidden role="dialog" aria-modal="true">' +
                                '<div class="nxa-modal__box">' +
                                        '<div class="nxa-modal__head">' +
                                                '<h2 id="nxa-modal-title">Dettaglio articolo</h2>' +
                                                '<button type="button" class="nxa-modal__close" aria-label="Chiudi finestra">×</button>' +
                                        '</div>' +
                                        '<div class="nxa-modal__body" id="nxa-modal-body"></div>' +
                                '</div>' +
                        '</div>'
                );
                document.body.appendChild( modal );

                modal.addEventListener( 'click', function ( ev ) {
                        if ( ev.target === modal || ( ev.target.classList && ev.target.classList.contains( 'nxa-modal__close' ) ) ) {
                                closeModal();
                        }
                } );
                document.addEventListener( 'keydown', function ( ev ) {
                        // v1.1.13 (O4): Tab confinato nel dialog, ESC chiude.
                        nxaModalTrapTab( ev, modal );
                        if ( ev.key === 'Escape' && ! modal.hidden ) {
                                closeModal();
                        }
                } );
                return modal;
        }

        function openModalBody( html ) {
                var modal = ensureModal();
                qs( '#nxa-modal-body', modal ).innerHTML = html;
                // v1.1.13 (O4): chi ha aperto la modale va salvato SOLO alla prima
                // apertura (i re-render del corpo non devono sovrascrivere l'opener).
                if ( modal.hidden ) {
                        nxaModalRememberFocus();
                }
                modal.hidden = false;
                document.body.classList.add( 'nxa-modal-open' );
        }

        function closeModal() {
                var modal = qs( '#nxa-modal' );
                if ( modal ) {
                        modal.hidden = true;
                }
                document.body.classList.remove( 'nxa-modal-open' );
                // v1.1.13 (O4): il focus torna a chi aveva aperto la modale.
                nxaModalRestoreFocus();
        }

        /* v1.1.7: una sola delega sul modal per aprire il dettaglio dalle liste
         * in modal (es. articoli dell'agente .nxa-agent-article-open[data-id]). */
        var agentArticleModalBound = false;

        function bindAgentArticleListModal() {
                if ( agentArticleModalBound ) {
                        return;
                }
                var modal = ensureModal();
                agentArticleModalBound = true;
                modal.addEventListener( 'click', function ( ev ) {
                        var open = ( ev.target && ev.target.closest ) ? ev.target.closest( '.nxa-agent-article-open' ) : null;
                        if ( open ) {
                                openArticleModal( num( open.getAttribute( 'data-id' ) ) );
                        }
                } );
        }

        /* v1.1.7: chiusura delle modali statiche delle viste ([data-nxa-close-modal],
         * hook riutilizzabile per qualunque .nxa-modal di vista; il blocco riserva
         * #nxa-queue-modal di queue.php è stato rimosso in 1.1.13 — admin.js usa
         * la SUA modale #nxa-modal): il bottone .nxa-modal-close delle viste non
         * aveva nessun handler in admin.js (wiring morto). Delega globale,
         * registrata una volta sola. */
        var staticModalClosersBound = false;

        function bindStaticModalClosers() {
                if ( staticModalClosersBound ) {
                        return;
                }
                staticModalClosersBound = true;
                document.addEventListener( 'click', function ( ev ) {
                        var btn = ( ev.target && ev.target.closest ) ? ev.target.closest( '[data-nxa-close-modal]' ) : null;
                        if ( ! btn ) {
                                return;
                        }
                        var modal = btn.closest ? btn.closest( '.nxa-modal' ) : null;
                        if ( modal ) {
                                modal.hidden = true;
                        }
                        document.body.classList.remove( 'nxa-modal-open' );
                } );
        }

        /** Modal di dettaglio: GET /articles/{id} → titolo, meta, contenuto, log. */
        function openArticleModal( id ) {
                openModalBody( '<div class="nxa-skeleton" style="width:70%"></div><div class="nxa-skeleton"></div><div class="nxa-skeleton" style="width:40%"></div>' );
                api( 'articles/' + num( id ) ).then( function ( payload ) {
                        var data = unwrap( payload ) || {};
                        var a = ( data.article && typeof data.article === 'object' ) ? data.article : data;
                        if ( ! a || ! pick( a, 'title' ) ) {
                                openModalBody( emptyState( '📄', 'Articolo non trovato', 'Potrebbe essere stato eliminato.' ) );
                                return;
                        }
                        var agentName = pick( a, 'agent_name', 'agentName' ) || ( a.agent && a.agent.name ) || 'AI Agent';
                        var sourceName = pick( a, 'source_name', 'sourceName' ) || '';
                        var sourceUrl = pick( a, 'source_url', 'sourceUrl' ) || '';
                        var statusLine = String( pick( a, 'status' ) || '' ).toLowerCase();
                        var html = '' +
                                '<div style="margin-bottom:8px">' + categoryChip( pick( a, 'category' ) ) + ' ' + badge( pick( a, 'status' ) ) + ' ' + chiefBadge( a ) + ' ' + wpPostBadge( a ) + ' ' + wpViewLink( a ) + '</div>' +
                                // v1.1.4: forzatura revisione capo redattore nel dettaglio
                                // (solo pending_approval; al successo il dettaglio si riapre).
                                ( statusLine === 'pending_approval' ? '<div style="margin:0 0 12px">' + chiefRunButtonHtml( chiefStatusOf( a ), 'id="nxa-chief-run-btn"' ) + '</div>' : '' ) +
                                '<h3 style="margin:0 0 4px;font-size:20px;line-height:1.3">' + esc( pick( a, 'title' ) ) + '</h3>' +
                                ( pick( a, 'subtitle', 'subTitle' ) ? '<p style="margin:0 0 10px;color:var(--nxa-muted);font-size:14px">' + esc( pick( a, 'subtitle', 'subTitle' ) ) + '</p>' : '' ) +
                                '<p style="font-size:12px;color:var(--nxa-muted);margin:0 0 14px">' +
                                        esc( agentName ) + ' · ' + dateIt( pick( a, 'created_at', 'createdAt' ) ) +
                                        ' · ' + scoreBadge( pick( a, 'quality_score', 'qualityScore' ) ) +
                                        ( sourceUrl ? ' · <a href="' + esc( sourceUrl ) + '" target="_blank" rel="noopener noreferrer">' + esc( sourceName || sourceUrl ) + ' ↗</a>' : '' ) +
                                '</p>';

                        var content = String( pick( a, 'content' ) || '' );
                        if ( content ) {
                                if ( /<p[\s>]|<br/i.test( content ) ) {
                                        // contenuto già sanitizzato lato server con wp_kses_post
                                        html += '<div class="nxa-article-content">' + content + '</div>';
                                } else {
                                        html += content.split( /\n{2,}/ ).map( function ( par ) {
                                                return '<p style="line-height:1.7">' + esc( par ) + '</p>';
                                        } ).join( '' );
                                }
                        } else if ( pick( a, 'summary' ) ) {
                                html += '<p style="line-height:1.7">' + esc( pick( a, 'summary' ) ) + '</p>';
                        }

                        // Fact-check + citazioni (v1.0.3): campi difensivi, il valore
                        // arriverà con l'integrazione REST di serialize_article.
                        var factBox = factCheckBoxHtml( a );
                        if ( factBox ) {
                                html += factBox;
                        }

                        // v1.1.4: blocco "Nota del capo redattore" (chief_note +
                        // chief_reviewed_at da serialize_article T-05; assenti → omesso).
                        var chiefNoteBox = chiefNoteBlockHtml( a );
                        if ( chiefNoteBox ) {
                                html += chiefNoteBox;
                        }

                        var logs = pick( a, 'publish_logs', 'publishLogs' );
                        if ( Array.isArray( logs ) && logs.length ) {
                                html += '<h4 style="margin:18px 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:.04em;color:var(--nxa-muted)">Log di pubblicazione</h4>';
                                logs.forEach( function ( log ) {
                                        html += '<p style="font-size:13px;margin:4px 0">' +
                                                '<strong>' + esc( pick( log, 'platform' ) || 'blog' ) + '</strong> ' + badge( pick( log, 'status' ) ) +
                                                ( pick( log, 'post_url' ) ? ' <a href="' + esc( pick( log, 'post_url' ) ) + '" target="_blank" rel="noopener noreferrer">vedi post ↗</a>' : '' ) +
                                                ( pick( log, 'error' ) ? '<br><span style="color:var(--nxa-err-text)">' + esc( pick( log, 'error' ) ) + '</span>' : '' ) +
                                                '</p>';
                                } );
                        }
                        // Sezione "Traduci" (v1.0.3): lingue caricate in modo
                        // asincrono da GET /settings, fallback contrattuale en/es/fr.
                        // v1.1.2: sotto la riga traduzioni la riga "Pubblica come
                        // post" — bottoni lingua che creano il post WP tradotto
                        // collegato e lista di stato delle traduzioni come post
                        // (riempita da GET multilang/translations-posts con la
                        // nota discreta del driver da multilang/info, cache pagina).
                        html += '<h4 style="margin:18px 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:.04em;color:var(--nxa-muted)">Traduci</h4>' +
                                '<div class="nxa-translate-row" id="nxa-translate-row">' +
                                        '<p style="font-size:12px;color:var(--nxa-muted);margin:0">Caricamento lingue…</p>' +
                                '</div>' +
                                '<div class="nxa-translate-post-row" id="nxa-translate-post-row">' +
                                        '<p class="nxa-translate-post-label" style="font-size:12px;font-weight:600;margin:10px 0 4px;color:var(--nxa-text-body)">Pubblica come post</p>' +
                                        '<div class="nxa-translate-post-btns">' +
                                                '<p style="font-size:12px;color:var(--nxa-muted);margin:0">Caricamento lingue…</p>' +
                                        '</div>' +
                                        '<div id="nxa-translations-posts-list"></div>' +
                                '</div>';

                        openModalBody( html );
                        bindTranslateButtons( qs( '#nxa-translate-row' ), num( pick( a, 'id' ) ) || num( id ) );
                        // v1.1.2: bottoni "Pubblica come post" + lista di stato
                        // delle traduzioni come post nativi (una fetch per apertura).
                        bindTranslatePostButtons( qs( '#nxa-translate-post-row' ), num( pick( a, 'id' ) ) || num( id ) );
                        loadTranslationsPosts( num( pick( a, 'id' ) ) || num( id ) );
                        // v1.1.4: bottone "Revisione capo redattore" nel dettaglio —
                        // al successo il dettaglio si riapre con badge/note aggiornati.
                        var chiefBtn = qs( '#nxa-chief-run-btn' );
                        if ( chiefBtn ) {
                                chiefBtn.addEventListener( 'click', function () {
                                        runChiefReview( num( pick( a, 'id' ) ) || num( id ), chiefStatusOf( a ), chiefBtn, function () {
                                                openArticleModal( num( pick( a, 'id' ) ) || num( id ) );
                                        } );
                                } );
                        }
                } ).catch( function ( err ) {
                        openModalBody( emptyState( '⚠️', 'Impossibile caricare l’articolo', err.message || '' ) );
                } );
        }

        /* ==================================================================
         * 6-bis. FACT-CHECK + TRADUZIONI (v1.0.3)
         * ================================================================== */

        /**
         * Blocco "Fact-check" del modal articolo (v1.0.3).
         * - badge punteggio: row.factcheck_score || 0 → nascosto se 0,
         *   classe ok (verde) se >= 60, ko (rosso) altrimenti;
         * - citazioni: row.citations è una STRINGA JSON [{claim, source}],
         *   decodificata in modo difensivo (mai crash); source è un URL → <a>.
         * Ritorna '' quando non c'è nulla da mostrare (box nascosto).
         */
        function factCheckBoxHtml( row ) {
                var a = row || {};
                var score = num( a.factcheck_score );
                var badgeHtml = '';

                if ( score > 0 ) {
                        var ok = score >= 60;
                        badgeHtml = '<span class="nxa-badge ' + ( ok ? 'nxa-fc-ok nxa-badge--success' : 'nxa-fc-ko nxa-badge--error' ) + '" title="Punteggio fact-check AI">Fact-check: ' + Math.round( score ) + '/100</span>';
                }

                var citations = [];
                if ( a.citations ) {
                        try {
                                var parsed = ( typeof a.citations === 'string' ) ? JSON.parse( a.citations ) : a.citations;
                                if ( Array.isArray( parsed ) ) {
                                        citations = parsed;
                                }
                        } catch ( e ) {
                                citations = []; // JSON corrotto: ignora senza bloccare il modal
                        }
                }
                citations = citations.filter( function ( c ) {
                        return c && ( c.claim || c.source );
                } );

                if ( ! badgeHtml && ! citations.length ) {
                        return '';
                }

                var items = citations.map( function ( c ) {
                        var source = String( c.source || '' );
                        return '<li>' + esc( c.claim || '' ) +
                                ( source ? ' — <a href="' + esc( source ) + '" target="_blank" rel="noopener noreferrer">' + esc( source ) + '</a>' : '' ) +
                                '</li>';
                } ).join( '' );

                return '<div class="nxa-factcheck-box" style="margin:16px 0 4px;padding:12px 14px;border:1px solid var(--nxa-border);border-radius:10px;background:var(--nxa-surface-2)">' +
                                ( badgeHtml ? '<div style="margin-bottom:' + ( citations.length ? '8px' : '0' ) + '">' + badgeHtml + '</div>' : '' ) +
                                ( citations.length
                                        ? '<h4 style="margin:0 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--nxa-muted)">Citazioni verificate</h4>' +
                                          '<ul style="margin:0;padding-left:18px;font-size:13px;line-height:1.6">' + items + '</ul>'
                                        : '' ) +
                        '</div>';
        }

        /** Etichetta bottone lingua → emoji bandiera + codice (il file usa già emoji). */
        var TRANSLATE_META = {
                en: '🇬🇧 EN', es: '🇪🇸 ES', fr: '🇫🇷 FR', de: '🇩🇪 DE',
                pt: '🇵🇹 PT', ar: '🇸🇦 AR', zh: '🇨🇳 ZH', ja: '🇯🇵 JA'
        };

        /**
         * Cache impostazioni per i bottoni "Traduci" (v1.0.3): GET /settings
         * viene chiamata UNA sola volta per pagina; errore → null (fallback).
         */
        var settingsCache = null;
        var settingsFetched = false;

        function getSettingsOnce() {
                if ( settingsFetched ) {
                        return Promise.resolve( settingsCache );
                }
                settingsFetched = true;
                return api( 'settings' ).then( function ( payload ) {
                        settingsCache = unwrap( payload );
                        return settingsCache;
                } ).catch( function ( err ) {
                        window.console.warn( '[Nexus] settings (per traduzioni):', err );
                        return null;
                } );
        }

        /**
         * Lingue dei bottoni "Traduci": settings.translation_langs se disponibile
         * nello state (cache sopra), altrimenti fallback contrattuale ['en','es','fr'].
         */
        function translateLangs( settings ) {
                var raw = settings ? String( pick( settings, 'translation_langs' ) || '' ) : '';
                var seen = {};
                var langs = raw.split( ',' ).map( function ( l ) {
                        return String( l || '' ).trim().toLowerCase();
                } ).filter( function ( l ) {
                        if ( ! /^[a-z]{2}$/.test( l ) || seen[ l ] ) {
                                return false;
                        }
                        seen[ l ] = true;
                        return true;
                } );
                return langs.length ? langs : [ 'en', 'es', 'fr' ]; // fallback (contratto v1.0.3)
        }

        /**
         * Riga bottoni "Traduci" nel modal articolo (v1.0.3).
         * Click → POST /articles/{id}/translate {lang} con gli stessi header/nonce
         * di api(); spinner/disabled durante la chiamata, esito in toast IT.
         */
        function bindTranslateButtons( container, articleId ) {
                if ( ! container ) {
                        return;
                }
                getSettingsOnce().then( function ( settings ) {
                        if ( ! container.isConnected ) {
                                return; // il modal è stato chiuso nel frattempo
                        }
                        container.innerHTML = translateLangs( settings ).map( function ( lang ) {
                                var label = TRANSLATE_META[ lang ] || ( '🌐 ' + lang.toUpperCase() );
                                return '<button type="button" class="button nxa-translate" data-lang="' + esc( lang ) + '">' + label + '</button>';
                        } ).join( '' );
                } );
                container.addEventListener( 'click', function ( ev ) {
                        var btn = ev.target.closest ? ev.target.closest( '.nxa-translate' ) : null;
                        if ( ! btn || btn.disabled ) {
                                return;
                        }
                        var lang = btn.getAttribute( 'data-lang' ) || '';
                        setBtnLoading( btn, true, 'Traduco…' );
                        api( 'articles/' + num( articleId ) + '/translate', { method: 'POST', body: { lang: lang } } ).then( function () {
                                toast( 'Traduzione completata (' + ( TRANSLATE_META[ lang ] || lang.toUpperCase() ) + ').', 'success', 'Traduzioni' );
                        } ).catch( function ( err ) {
                                apiErrorToast( err, 'Traduzione non riuscita' );
                        } ).then( function () {
                                setBtnLoading( btn, false );
                        } );
                } );
        }

        /* ==================================================================
         * 6-ter. TRADUZIONI COME POST NATIVI (v1.1.2)
         * ==================================================================
         *
         * Le traduzioni diventano post WordPress veri, collegati tra loro
         * (Polylang, WPML o meta interni Nexus secondo il driver attivo).
         * Endpoint admin usati tramite helper api() (namespace nexus / v1):
         *  - GET  multilang/info               → driver attivo, cache di pagina;
         *  - POST multilang/translate-post     → corpo { article_id, lang },
         *    risposta con lang, post_id, edit_url, permalink e already;
         *  - GET  multilang/translations-posts → { article_id, source_post_id,
         *    source_lang, translations: [ { lang, post_id, edit_url,
         *    permalink, status, modified, is_source } ] }.
         *
         * Lettura difensiva come nel resto del file: campi mancanti non
         * crashano, errori REST sempre in toast italiano, bottoni riabilitati
         * nel ramo finale after-catch (chiusura modal e ESC safe).
         */

        /**
         * v1.1.2: info driver multilingua, UNA fetch per pagina (cache in
         * variabile, stesso pattern di getSettingsOnce). Errore → null: la
         * nota driver sparisce e il resto della UI funziona comunque.
         */
        var multilangInfoCache = null;
        var multilangInfoFetched = false;

        function getMultilangInfo() {
                if ( multilangInfoFetched ) {
                        return Promise.resolve( multilangInfoCache );
                }
                multilangInfoFetched = true;
                return api( 'multilang/info' ).then( function ( payload ) {
                        multilangInfoCache = unwrap( payload ) || {};
                        return multilangInfoCache;
                } ).catch( function ( err ) {
                        window.console.warn( '[Nexus] multilang info:', err );
                        return null;
                } );
        }

        /**
         * v1.1.2: nota discreta sul driver attivo. Con Polylang o WPML le
         * associazioni tra lingue sono gestite dai plugin: lo dichiariamo
         * apertamente invece di far credere che sia Nexus a collegarle.
         */
        function multilangDriverNoteHtml() {
                var info = multilangInfoCache;
                if ( ! info ) {
                        return '';
                }
                var driver = String( pick( info, 'driver' ) || '' ).toLowerCase();
                if ( driver === 'polylang' ) {
                        return '<p class="nxa-multilang-note" style="font-size:12px;color:var(--nxa-muted);margin:6px 0 0">Associazioni lingua gestite da Polylang.</p>';
                }
                if ( driver === 'wpml' ) {
                        return '<p class="nxa-multilang-note" style="font-size:12px;color:var(--nxa-muted);margin:6px 0 0">Associazioni lingua gestite da WPML.</p>';
                }
                return '';
        }

        /**
         * v1.1.2: chip di una traduzione pubblicata come post. Bandiera e
         * codice lingua da TRANSLATE_META, badge di stato (STATUS_META:
         * 'publish' mostrato come Pubblicato, 'future' come In attesa),
         * marcatura "sorgente" per il post nativo dell'articolo e link
         * Modifica (editor admin) e Vedi (permalink pubblico).
         */
        function translationPostChip( t ) {
                var lang = String( pick( t, 'lang' ) || '' );
                var label = TRANSLATE_META[ lang ] || ( '🌐 ' + lang.toUpperCase() );
                var status = String( pick( t, 'status' ) || '' ).toLowerCase();
                if ( status === 'publish' ) {
                        status = 'published';
                }
                if ( status === 'future' ) {
                        status = 'pending';
                }
                var editUrl = String( pick( t, 'edit_url' ) || '' );
                var permalink = String( pick( t, 'permalink' ) || '' );
                var isSource = !! t.is_source;
                return '<span class="nxa-trans-post-chip" data-lang="' + esc( lang ) + '"' + ( isSource ? ' data-source="1"' : '' ) +
                        ' style="display:inline-flex;align-items:center;gap:6px;border:1px solid var(--nxa-border);border-radius:999px;padding:3px 10px;font-size:12px;background:var(--nxa-surface-2)">' +
                        '<strong>' + esc( label ) + '</strong>' +
                        badge( status ) +
                        ( isSource ? '<span class="nxa-trans-post-source" style="font-size:11px;color:var(--nxa-muted)">sorgente</span>' : '' ) +
                        ( editUrl ? '<a class="nxa-trans-post-edit" href="' + esc( editUrl ) + '">Modifica</a>' : '' ) +
                        ( permalink ? '<a class="nxa-trans-post-view" href="' + esc( permalink ) + '" target="_blank" rel="noopener noreferrer">Vedi</a>' : '' ) +
                '</span>';
        }

        /**
         * v1.1.2: render della lista #nxa-translations-posts-list. Senza
         * post nativo (source_post_id 0) nota onesta: le traduzioni come
         * post si attivano pubblicando l'articolo. Post nativo presente ma
         * nessuna traduzione: stato vuoto onesto, mai contenuti simulati.
         */
        function renderTranslationsPosts( data ) {
                var box = qs( '#nxa-translations-posts-list' );
                if ( ! box ) {
                        return;
                }
                var d = data || {};
                var sourcePostId = num( pick( d, 'source_post_id' ) );
                var translations = Array.isArray( d.translations ) ? d.translations : [];
                var html = multilangDriverNoteHtml();
                if ( sourcePostId <= 0 ) {
                        html += '<p class="nxa-trans-posts-note" style="font-size:12px;color:var(--nxa-muted);margin:6px 0 0">Pubblica l’articolo per attivare le traduzioni come post.</p>';
                        box.innerHTML = html;
                        return;
                }
                if ( ! translations.length ) {
                        html += '<p class="nxa-trans-posts-note" style="font-size:12px;color:var(--nxa-muted);margin:6px 0 0">Nessuna traduzione pubblicata come post.</p>';
                        box.innerHTML = html;
                        return;
                }
                html += '<div class="nxa-trans-posts-chips" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px">' +
                        translations.map( translationPostChip ).join( '' ) +
                '</div>';
                box.innerHTML = html;
        }

        /**
         * v1.1.2: GET multilang/translations-posts per l'articolo e render
         * della lista. Chiamata all'apertura del dettaglio e dopo ogni
         * pubblicazione riuscita; driver e stato arrivano in parallelo e
         * un modal chiuso nel frattempo (box fuori dal documento) interrompe
         * il render senza errori.
         */
        function loadTranslationsPosts( articleId ) {
                var box = qs( '#nxa-translations-posts-list' );
                var id = num( articleId );
                if ( ! box || id <= 0 ) {
                        return Promise.resolve();
                }
                showSkeleton( box, 2 );
                return Promise.all( [
                        getMultilangInfo(),
                        api( 'multilang/translations-posts?article_id=' + id )
                ] ).then( function ( results ) {
                        if ( ! box.isConnected ) {
                                return; // modal chiuso nel frattempo
                        }
                        renderTranslationsPosts( unwrap( results[ 1 ] ) || {} );
                } ).catch( function ( err ) {
                        if ( ! box.isConnected ) {
                                return;
                        }
                        box.innerHTML = '<p class="nxa-trans-posts-note" style="font-size:12px;color:var(--nxa-muted);margin:6px 0 0">' +
                                esc( ( err && err.message ) ? err.message : 'Stato delle traduzioni non disponibile.' ) + '</p>';
                        window.console.warn( '[Nexus] traduzioni come post:', err );
                } );
        }

        /**
         * v1.1.2: riga "Pubblica come post" del modal articolo. Stesse lingue
         * dei bottoni "Traduci" (translateLangs su GET /settings, cache di
         * pagina). Click → POST multilang/translate-post { article_id, lang }:
         * tutti i bottoni lingua della riga restano disabilitati durante la
         * chiamata; successo → toast + refresh della lista di stato (chips
         * con link Modifica e Vedi); errore → apiErrorToast.
         */
        function bindTranslatePostButtons( container, articleId ) {
                if ( ! container ) {
                        return;
                }
                getSettingsOnce().then( function ( settings ) {
                        if ( ! container.isConnected ) {
                                return; // il modal è stato chiuso nel frattempo
                        }
                        var btns = container.querySelector( '.nxa-translate-post-btns' );
                        if ( ! btns ) {
                                return;
                        }
                        btns.innerHTML = translateLangs( settings ).map( function ( lang ) {
                                var label = TRANSLATE_META[ lang ] || ( '🌐 ' + lang.toUpperCase() );
                                return '<button type="button" class="button nxa-translate-post" data-lang="' + esc( lang ) + '">' + label + '</button>';
                        } ).join( '' );
                } );
                container.addEventListener( 'click', function ( ev ) {
                        var btn = ev.target.closest ? ev.target.closest( '.nxa-translate-post' ) : null;
                        if ( ! btn || btn.disabled ) {
                                return;
                        }
                        var id = num( articleId );
                        if ( id <= 0 ) {
                                return;
                        }
                        var lang = btn.getAttribute( 'data-lang' ) || '';
                        qsa( '.nxa-translate-post', container ).forEach( function ( b ) {
                                b.disabled = true;
                        } );
                        setBtnLoading( btn, true, 'Pubblico…' );
                        api( 'multilang/translate-post', { method: 'POST', body: { article_id: id, lang: lang } } ).then( function ( payload ) {
                                var d = unwrap( payload ) || {};
                                var code = String( pick( d, 'lang' ) || lang ).toUpperCase();
                                toast( d.already
                                        ? 'Traduzione già pubblicata come post (' + code + ').'
                                        : 'Traduzione pubblicata come post (' + code + ').',
                                        'success', 'Traduzioni come post' );
                                loadTranslationsPosts( id );
                        } ).catch( function ( err ) {
                                apiErrorToast( err, 'Pubblicazione traduzione non riuscita' );
                        } ).then( function () {
                                setBtnLoading( btn, false );
                                qsa( '.nxa-translate-post', container ).forEach( function ( b ) {
                                        b.disabled = false;
                                } );
                        } );
                } );
        }

        /* ==================================================================
         * 7. CACHE AGENTI (nomi per la coda + mini griglia dashboard)
         * ================================================================== */

        var agentsCache = { list: null, at: 0 };
        var AGENTS_TTL = 30000;

        function getAgents( force ) {
                var fresh = agentsCache.list && ( Date.now() - agentsCache.at ) < AGENTS_TTL;
                if ( fresh && ! force ) {
                        return Promise.resolve( agentsCache.list );
                }
                return api( 'agents' ).then( function ( payload ) {
                        agentsCache.list = getList( payload, [ 'agents', 'items', 'list' ] );
                        agentsCache.at = Date.now();
                        return agentsCache.list;
                } );
        }

        function agentNameById( agentId ) {
                var list = agentsCache.list || [];
                for ( var i = 0; i < list.length; i++ ) {
                        if ( num( list[ i ].id ) === num( agentId ) ) {
                                return String( list[ i ].name || '' );
                        }
                }
                return '';
        }

        /** Riga agente completa per id (v1.0.3: serve a tabella uso AI + modello). */
        function agentById( agentId ) {
                var list = agentsCache.list || [];
                for ( var i = 0; i < list.length; i++ ) {
                        if ( num( list[ i ].id ) === num( agentId ) ) {
                                return list[ i ];
                        }
                }
                return null;
        }

        function articleAgentName( a ) {
                return String( pick( a, 'agent_name', 'agentName' ) ||
                        ( a.agent && a.agent.name ) ||
                        agentNameById( pick( a, 'agent_id', 'agentId' ) ) ||
                        'AI Agent' );
        }

        /* ==================================================================
         * 8. DASHBOARD
         * ================================================================== */

        function setStat( selector, value ) {
                var node = qs( selector );
                if ( node ) {
                        node.textContent = fmt( value );
                }
        }

        function loadStats() {
                return api( 'stats' ).then( function ( payload ) {
                        var s = unwrap( payload ) || {};
                        setStat( '#nxa-stat-published', pick( s, 'published' ) );
                        setStat( '#nxa-stat-pending', pick( s, 'pending', 'pending_approval' ) );
                        setStat( '#nxa-stat-draft', pick( s, 'draft' ) );
                        setStat( '#nxa-stat-today', pick( s, 'today' ) );
                        setStat( '#nxa-stat-agents', pick( s, 'agents_active', 'agents' ) );
                        setStat( '#nxa-stat-views', pick( s, 'views' ) );
                } );
        }

        function activityItemHtml( item ) {
                var meta = actionMeta( pick( item, 'action' ) );
                var agent = ( item.agent && item.agent.name ) ? item.agent :
                        ( num( pick( item, 'agent_id', 'agentId' ) ) > 0 ? { name: agentNameById( pick( item, 'agent_id', 'agentId' ) ) || 'Agente #' + pick( item, 'agent_id', 'agentId' ), avatar: '🤖' } : { name: 'Sistema', avatar: '⚙️' } );
                return '<div class="nxa-act-item">' +
                        '<div class="nxa-act-icon" aria-hidden="true">' + ( ( item.agent && item.agent.avatar ) || agent.avatar || meta.icon ) + '</div>' +
                        '<div style="flex:1;min-width:0">' +
                                '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">' +
                                        '<span class="nxa-act-agent">' + esc( agent.name ) + '</span>' +
                                        '<span class="nxa-badge nxa-badge--' + meta.variant + '">' + esc( meta.label ) + '</span>' +
                                        '<span class="nxa-act-time">' + esc( timeAgoIt( pick( item, 'created_at', 'createdAt' ) ) ) + '</span>' +
                                '</div>' +
                                '<p class="nxa-act-detail">' + esc( pick( item, 'detail' ) || '' ) + '</p>' +
                        '</div>' +
                '</div>';
        }

        function loadDashboardFeed() {
                var container = qs( '#nxa-activity-feed' );
                if ( ! container ) {
                        return Promise.resolve();
                }
                return api( 'activity?limit=8' ).then( function ( payload ) {
                        var items = getList( payload, [ 'activity', 'logs', 'items' ] );
                        container.innerHTML = items.length
                                ? items.map( activityItemHtml ).join( '' )
                                : emptyState( '📡', 'Nessuna attività registrata', 'Il lavoro degli agenti apparirà qui.' );
                } );
        }

        function loadAgentsMini() {
                var container = qs( '#nxa-agents-mini' );
                if ( ! container ) {
                        return Promise.resolve();
                }
                return getAgents().then( function ( agents ) {
                        container.innerHTML = agents.length
                                ? '<div class="nxa-mini-agents">' + agents.map( function ( agent ) {
                                        return '<span class="nxa-mini-agent">' +
                                                '<span class="nxa-mini-agent__avatar" aria-hidden="true">' + esc( pick( agent, 'avatar' ) || '🤖' ) + '</span>' +
                                                '<span>' + esc( pick( agent, 'name' ) || 'Agente' ) + '</span>' +
                                                badge( pick( agent, 'status' ) ) +
                                        '</span>';
                                } ).join( '' ) + '</div>'
                                : emptyState( '🤖', 'Nessun agente configurato' );
                } );
        }

        /** Costo USD con 4 decimali: "$12.3456" (contratto v1.0.3). */
        function fmtUsd( value ) {
                return '$' + num( value ).toFixed( 4 );
        }

        /**
         * Pannello "Uso AI" (v1.0.3): legge il JSON stampato dalla view
         * dashboard in #nexus-usage-panel[data-usage] (Nexus_DB::usage_stats('month'))
         * e il budget mensile da [data-budget]. Renderizza stat-card + tabella
         * per-agente + barra budget. ZERO rotte REST nuove: solo i nomi degli
         * agenti dalla cache (se disponibili), altrimenti "Agente #id".
         */
        function renderUsagePanel() {
                var panel = qs( '#nexus-usage-panel' );
                if ( ! panel ) {
                        return Promise.resolve();
                }

                var stats = null;
                try {
                        stats = JSON.parse( panel.getAttribute( 'data-usage' ) || 'null' );
                } catch ( e ) {
                        stats = null; // JSON corrotto: mostriamo comunque gli zeri
                }
                var totals = ( stats && stats.totals ) ? stats.totals : {};
                var byAgent = ( stats && Array.isArray( stats.by_agent ) ) ? stats.by_agent : [];
                var budget = num( panel.getAttribute( 'data-budget' ) );
                var cost = num( totals.cost );

                var html = '<div class="nxa-stat-grid" style="margin-bottom:14px">' +
                                '<div class="nxa-stat-card"><span class="nxa-stat-value">' + fmt( totals.calls ) + '</span><span class="nxa-stat-label">' + esc( 'Chiamate AI' ) + '</span></div>' +
                                '<div class="nxa-stat-card"><span class="nxa-stat-value">' + fmt( totals.total_tokens ) + '</span><span class="nxa-stat-label">' + esc( 'Token totali' ) + '</span></div>' +
                                '<div class="nxa-stat-card"><span class="nxa-stat-value">' + esc( fmtUsd( cost ) ) + '</span><span class="nxa-stat-label">' + esc( 'Costo stimato (mese)' ) + '</span></div>' +
                        '</div>';

                // Budget mensile (se > 0): {cost} / {budget} USD, colore in base alla %.
                if ( budget > 0 ) {
                        var pct = Math.min( 100, ( cost / budget ) * 100 );
                        var barColor = pct < 60 ? 'var(--nxa-ok-text)' : ( pct < 90 ? 'var(--nxa-warn-text)' : 'var(--nxa-err-text)' );
                        html += '<div class="nxa-usage-budget" style="margin:0 0 14px">' +
                                        '<p style="margin:0 0 6px;font-size:12.5px;color:var(--nxa-muted)">' +
                                                'Budget mensile: <strong style="color:' + barColor + '">' + esc( fmtUsd( cost ) ) + '</strong> / ' + esc( budget.toFixed( 2 ) ) + ' USD' +
                                                ( pct >= 90 ? ' ⚠️' : '' ) +
                                        '</p>' +
                                        '<div style="height:10px;background:var(--nxa-border);border-radius:999px;overflow:hidden" role="progressbar" aria-valuenow="' + Math.round( pct ) + '" aria-valuemin="0" aria-valuemax="100">' +
                                                '<div style="height:100%;width:' + pct.toFixed( 1 ) + '%;background:' + barColor + '"></div>' +
                                        '</div>' +
                                '</div>';
                }

                if ( byAgent.length ) {
                        var rows = byAgent.map( function ( r ) {
                                var agent = agentById( pick( r, 'agent_id' ) );
                                var label = agent
                                        ? '<span aria-hidden="true">' + esc( pick( agent, 'avatar' ) || '🤖' ) + '</span> ' + esc( pick( agent, 'name' ) || 'Agente' )
                                        : 'Agente #' + esc( pick( r, 'agent_id' ) );
                                return '<tr>' +
                                        '<td style="padding:8px 10px;border-bottom:1px solid var(--nxa-border-soft)">' + label + '</td>' +
                                        '<td style="padding:8px 10px;border-bottom:1px solid var(--nxa-border-soft)">' + fmt( pick( r, 'calls' ) ) + '</td>' +
                                        '<td style="padding:8px 10px;border-bottom:1px solid var(--nxa-border-soft)">' + fmt( pick( r, 'total_tokens' ) ) + '</td>' +
                                        '<td style="padding:8px 10px;border-bottom:1px solid var(--nxa-border-soft)">' + esc( fmtUsd( pick( r, 'cost' ) ) ) + '</td>' +
                                '</tr>';
                        } ).join( '' );
                        html += '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:13px">' +
                                        '<thead><tr>' +
                                                '<th align="left" style="padding:8px 10px;border-bottom:1px solid var(--nxa-border);color:var(--nxa-muted);font-size:11px;text-transform:uppercase;letter-spacing:.04em">Agente</th>' +
                                                '<th align="left" style="padding:8px 10px;border-bottom:1px solid var(--nxa-border);color:var(--nxa-muted);font-size:11px;text-transform:uppercase;letter-spacing:.04em">Chiamate</th>' +
                                                '<th align="left" style="padding:8px 10px;border-bottom:1px solid var(--nxa-border);color:var(--nxa-muted);font-size:11px;text-transform:uppercase;letter-spacing:.04em">Token</th>' +
                                                '<th align="left" style="padding:8px 10px;border-bottom:1px solid var(--nxa-border);color:var(--nxa-muted);font-size:11px;text-transform:uppercase;letter-spacing:.04em">Costo</th>' +
                                        '</tr></thead>' +
                                        '<tbody>' + rows + '</tbody>' +
                                '</table></div>';
                } else {
                        html += emptyState( '📊', 'Nessun consumo AI questo mese', 'Le chiamate AI degli agenti appariranno qui con il tracking v1.0.3.' );
                }

                panel.innerHTML = html;
                return Promise.resolve();
        }

        function refreshDashboardData() {
                return Promise.all( [
                        loadStats().catch( function ( e ) { window.console.warn( '[Nexus] stats:', e ); } ),
                        loadDashboardFeed().catch( function ( e ) { window.console.warn( '[Nexus] feed:', e ); } ),
                        loadAgentsMini().catch( function ( e ) { window.console.warn( '[Nexus] mini agenti:', e ); } ),
                        renderUsagePanel().catch( function ( e ) { window.console.warn( '[Nexus] uso AI:', e ); } )
                ] );
        }

        function loadDashboard() {
                refreshDashboardData();

                // --- Bottone pipeline completa ---
                // v1.1.13 (O4): conferma distruttiva INLINE uniforme (stessa UX del
                // pattern custom provider, niente window.confirm nativo). NB: dalla
                // v1.1.6 il bottone .nxa-run-pipeline non è più nel markup della
                // dashboard; l'handler resta per BC con eventuali viste custom.
                qsa( '.nxa-run-pipeline' ).forEach( function ( btn ) {
                        btn.addEventListener( 'click', function () {
                                nxaInlineConfirmOpen(
                                        btn,
                                        nxaT( 'Avviare la pipeline editoriale completa?\n\nGli agenti raccoglieranno le notizie, le valuteranno, le riscriveranno e pubblicheranno gli articoli secondo le impostazioni correnti.' ),
                                        nxaT( 'Sì, avvia' ),
                                        function () {
                                                setBtnLoading( btn, true, nxaT( 'Pipeline in corso…' ) );
                                                api( 'pipeline/run', { method: 'POST', body: { agent_id: 0 } } ).then( function ( payload ) {
                                                        var d = unwrap( payload ) || {};
                                                        var collected = num( pick( d, 'collected', 'collected_count' ) );
                                                        var evaluated = num( pick( d, 'evaluated', 'evaluated_count' ) );
                                                        var rewritten = num( pick( d, 'rewritten', 'rewritten_count' ) );
                                                        var published = num( pick( d, 'published', 'published_count' ) );
                                                        toast( 'Raccolte: ' + collected + ' · Valutate: ' + evaluated + ' · Riscritte: ' + rewritten + ' · Pubblicate: ' + published,
                                                                'success', 'Pipeline completata' );
                                                        return refreshDashboardData();
                                                } ).catch( function ( err ) {
                                                        apiErrorToast( err, 'Pipeline non completata' );
                                                } ).then( function () {
                                                        setBtnLoading( btn, false );
                                                } );
                                        }
                                );
                        } );
                } );

                // --- Bottone raccolta per tutti gli agenti ---
                qsa( '.nxa-collect-all' ).forEach( function ( btn ) {
                        btn.addEventListener( 'click', function () {
                                setBtnLoading( btn, true, 'Raccolta in corso…' );
                                api( 'pipeline/collect', { method: 'POST', body: { agent_id: 0 } } ).then( function ( payload ) {
                                        var d = unwrap( payload ) || {};
                                        var created = num( pick( d, 'created', 'created_count', 'inserted', 'new_articles' ) );
                                        var ran = num( pick( d, 'ran', 'agents_run', 'processed' ) );
                                        var msg = created > 0
                                                ? 'Raccolta completata: ' + created + ' nuovi articoli' + ( ran ? ' da ' + ran + ' agenti' : '' ) + '.'
                                                : 'Raccolta completata: nessuna notizia abbastanza rilevante in questo momento.';
                                        toast( msg, 'success', 'Redazione al lavoro' );
                                        return refreshDashboardData();
                                } ).catch( function ( err ) {
                                        apiErrorToast( err, 'Raccolta non completata' );
                                } ).then( function () {
                                        setBtnLoading( btn, false );
                                } );
                        } );
                } );
        }

        /* ==================================================================
         * 9. AGENTI
         * ================================================================== */

        function agentCardHtml( agent ) {
                var status = String( pick( agent, 'status' ) || 'paused' );
                var isActive = status === 'active';
                var dotColor = isActive ? 'var(--nxa-ok-text)' : ( status === 'error' ? 'var(--nxa-err-text)' : 'var(--nxa-warn-text)' );
                return '<div class="nxa-card nxa-agent-card" data-agent-id="' + esc( pick( agent, 'id' ) ) + '">' +
                        '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px">' +
                                '<div style="display:flex;align-items:center;gap:10px;min-width:0">' +
                                        '<span style="width:44px;height:44px;border-radius:12px;background:var(--nxa-surface-3);display:flex;align-items:center;justify-content:center;font-size:22px" aria-hidden="true">' + esc( pick( agent, 'avatar' ) || '🤖' ) + '</span>' +
                                        '<div style="min-width:0">' +
                                                '<strong style="display:block;font-size:14px">' + esc( pick( agent, 'name' ) || 'Agente' ) + '</strong>' +
                                                '<span style="margin-top:2px;display:inline-block">' + categoryChip( pick( agent, 'category' ) ) + '</span>' +
                                        '</div>' +
                                '</div>' +
                                '<span class="nxa-badge nxa-badge--' + ( STATUS_META[ status ] ? STATUS_META[ status ][ 1 ] : 'neutral' ) + '">' +
                                        '<span class="nxa-dot" style="background:' + dotColor + ( isActive ? ';animation:nxa-pulse 1.6s ease infinite' : '' ) + '"></span>' +
                                        esc( STATUS_META[ status ] ? STATUS_META[ status ][ 0 ] : status ) +
                                '</span>' +
                        '</div>' +
                        '<p style="font-size:12.5px;color:var(--nxa-muted);margin:10px 0 6px;line-height:1.55">' + esc( pick( agent, 'description' ) || '' ) + '</p>' +
                        '<p style="font-size:12px;color:var(--nxa-muted);margin:0 0 10px"><em>“' + esc( pick( agent, 'personality' ) || '' ) + '”</em></p>' +
                        '<p style="font-size:11.5px;color:var(--nxa-muted);margin:0 0 12px">Ultimo run: ' + esc( timeAgoIt( pick( agent, 'last_run', 'lastRun' ) ) ) + '</p>' +
                        // v1.1.0: badge autore bot nativo — la riga compare solo se
                        // l'agente ha un utente WP collegato (wp_user_id arriva con le
                        // route v1.1.0; response vecchie non lo hanno: riga omessa).
                        ( num( pick( agent, 'wp_user_id' ) ) > 0 ? '<p class="nxa-agent-wp-author">Autore WP attivo</p>' : '' ) +
                        // v1.1.1: segnaposto "Redazioni" (colonna T-01 in agents.php):
                        // refreshAgentRedazioniCells riempie la cella con i chip
                        // delle redazioni in cui l'agente compare, "—" se nessuna.
                        '<div class="nxa-agent-redazioni" data-agent-id="' + esc( pick( agent, 'id' ) ) + '"></div>' +
                        // Modello AI dedicato (v1.0.3): vuoto = modello globale da settings.
                        // WP12: l'input è avvolto in span.nxa-mp (combobox cercabile con
                        // inizializzazione delegata al focus — vedi mpBindDocument) e
                        // porta data-provider (ai_provider dell'agente): la lista modelli
                        // del picker segue il provider DEDICATO dell'agente.
                        '<div style="margin:0 0 12px;display:flex;gap:6px;flex-wrap:wrap;align-items:center">' +
                                '<span class="nxa-mp" style="flex:1 1 170px;min-width:0">' +
                                        '<input type="text" class="regular-text nxa-agent-model" data-agent-id="' + esc( pick( agent, 'id' ) ) + '" data-provider="' + esc( pick( agent, 'ai_provider', 'aiProvider' ) || '' ) + '" value="' + esc( pick( agent, 'ai_model' ) || '' ) + '" placeholder="vuoto = modello globale" aria-label="Modello AI per ' + esc( pick( agent, 'name' ) || 'agente' ) + '" />' +
                                '</span>' +
                                '<button type="button" class="button nxa-agent-model-save" data-agent-id="' + esc( pick( agent, 'id' ) ) + '">Salva modello</button>' +
                        '</div>' +
                        // v1.0.7: riga azioni in contenitore flex wrap: i nuovi
                        // "Modifica"/"Clona" + il toggle di stato preesistente.
                        // v1.1.5: "Avvia" (nxa-btn-run, data-run-agent) è il primo
                        // bottone: azione primaria della card (POST pipeline/run
                        // agent_id, handler delegato nella sezione AVVII MIRATI).
                        // v1.1.7 (T-B2): "Vedi articoli dell'agente" ORA è renderizzato
                        // anche nelle card JS (prima esisteva solo nel <noscript> di
                        // agents.php, lasciando l'handler delegato
                        // .nxa-agent-articles-link senza markup: wiring morto).
                        // Stessa classe/data-* del noscript: nxa-btn is-sm +
                        // nxa-agent-articles-link + data-agent-id + href="#".
                        '<div style="display:flex;gap:6px;flex-wrap:wrap">' +
                                '<button type="button" class="button nxa-btn-run" data-run-agent="' + esc( pick( agent, 'id' ) ) + '">▶ Avvia</button>' +
                                '<button type="button" class="button nxa-agent-edit" data-agent-id="' + esc( pick( agent, 'id' ) ) + '">Modifica</button>' +
                                '<button type="button" class="button nxa-agent-clone" data-agent-id="' + esc( pick( agent, 'id' ) ) + '">Clona</button>' +
                                '<button type="button" class="button nxa-agent-toggle" data-agent-id="' + esc( pick( agent, 'id' ) ) + '" data-status="' + esc( status ) + '">' +
                                        ( isActive ? 'Metti in pausa' : 'Riattiva' ) +
                                '</button>' +
                                '<a class="nxa-btn is-sm nxa-agent-articles-link" data-agent-id="' + esc( pick( agent, 'id' ) ) + '" href="#">Vedi articoli dell’agente</a>' +
                                '<button type="button" class="button nxa-agent-delete" data-agent-id="' + esc( pick( agent, 'id' ) ) + '" data-name="' + esc( pick( agent, 'name' ) ) + '">Elimina</button>' +
                        '</div>' +
                '</div>';
        }

        function loadAgents( force ) {
                var grid = qs( '#nxa-agents-grid' );
                if ( ! grid ) {
                        return Promise.resolve();
                }
                showSkeleton( grid, 4 );
                return getAgents( force ).then( function ( agents ) {
                        grid.innerHTML = agents.length
                                ? '<div class="nxa-agents-grid">' + agents.map( agentCardHtml ).join( '' ) + '</div>'
                                // v1.1.11 (R3): con 0 agenti niente più "7 agenti predefiniti":
                                // empty state nuovo + bottone che apre il wizard onboarding.
                                : nxaOnboardingEmptyHtml();
                        // v1.1.1: il render ricrea le card, quindi ripristina la
                        // colonna "Redazioni" dalla cache pagina (nessuna fetch).
                        refreshAgentRedazioniCells();
                        // v1.1.11 (R3): al primo render con la redazione vuota il wizard
                        // si apre da solo (una sola volta per vista — vedi nxaOnboardingAutoOpen).
                        nxaOnboardingAutoOpen( agents.length );
                } ).catch( function ( err ) {
                        grid.innerHTML = emptyState( '⚠️', 'Impossibile caricare gli agenti', err.message || '' );
                        window.console.warn( '[Nexus] agenti:', err );
                } );
        }

        function bindAgents() {
                var grid = qs( '#nxa-agents-grid' );
                if ( grid ) {
                        grid.addEventListener( 'click', function ( ev ) {
                                var btn = ev.target.closest ? ev.target.closest( '.nxa-agent-toggle' ) : null;
                                if ( ! btn || btn.disabled ) {
                                        return;
                                }
                                var id = num( btn.getAttribute( 'data-agent-id' ) );
                                var current = btn.getAttribute( 'data-status' );
                                var next = current === 'active' ? 'paused' : 'active';
                                setBtnLoading( btn, true, 'Aggiorno…' );
                                api( 'agents/' + id + '/status', { method: 'POST', body: { status: next } } ).then( function () {
                                        toast( next === 'active' ? 'Agente riattivato.' : 'Agente messo in pausa.', 'success', 'Stato aggiornato' );
                                        return loadAgents( true );
                                } ).catch( function ( err ) {
                                        apiErrorToast( err, 'Impossibile aggiornare lo stato' );
                                        setBtnLoading( btn, false );
                                } );
                        } );

                        // v1.1.7: eliminazione agente (DELETE /agents/{id}). Il contenuto
                        // resta: gli articoli vengono solo scollegati dall'autore.
                        // v1.1.13 (O4): conferma distruttiva INLINE uniforme (stessa UX
                        // del pattern custom provider, niente window.confirm nativo).
                        grid.addEventListener( 'click', function ( ev ) {
                                var del = ev.target.closest ? ev.target.closest( '.nxa-agent-delete' ) : null;
                                if ( ! del || del.disabled ) {
                                        return;
                                }
                                var id = num( del.getAttribute( 'data-agent-id' ) );
                                var name = del.getAttribute( 'data-name' ) || '';
                                nxaInlineConfirmOpen(
                                        del,
                                        nxaT( 'Eliminare l’agente "' ) + name + nxaT( '"? Gli articoli restano pubblicati ma senza autore assegnato.' ),
                                        nxaT( 'Sì, elimina' ),
                                        function () {
                                                setBtnLoading( del, true, nxaT( 'Elimino…' ) );
                                                api( 'agents/' + id, { method: 'DELETE' } ).then( function () {
                                                        toast( nxaT( 'Agente eliminato.' ), 'success', nxaT( 'Fatto' ) );
                                                        return loadAgents( true );
                                                } ).catch( function ( err ) {
                                                        apiErrorToast( err, nxaT( 'Impossibile eliminare l’agente' ) );
                                                        setBtnLoading( del, false );
                                                } );
                                        }
                                );
                        } );

                        // Salva modello AI dedicato (v1.0.3): POST /agents/{id}/model {model}
                        // → risposta serialize_agent: aggiorna la riga locale (niente reload).
                        grid.addEventListener( 'click', function ( ev ) {
                                var saveBtn = ev.target.closest ? ev.target.closest( '.nxa-agent-model-save' ) : null;
                                if ( ! saveBtn || saveBtn.disabled ) {
                                        return;
                                }
                                var id = num( saveBtn.getAttribute( 'data-agent-id' ) );
                                var card = saveBtn.closest ? saveBtn.closest( '.nxa-agent-card' ) : null;
                                var input = card ? qs( '.nxa-agent-model[data-agent-id="' + id + '"]', card ) : null;
                                var model = input ? String( input.value || '' ).trim() : '';
                                setBtnLoading( saveBtn, true, 'Salvo…' );
                                api( 'agents/' + id + '/model', { method: 'POST', body: { model: model } } ).then( function ( payload ) {
                                        var updated = unwrap( payload ) || {};
                                        var newModel = pick( updated, 'ai_model' );
                                        var value = ( newModel !== undefined ) ? newModel : model;
                                        if ( agentsCache.list ) {
                                                for ( var i = 0; i < agentsCache.list.length; i++ ) {
                                                        if ( num( agentsCache.list[ i ].id ) === id ) {
                                                                agentsCache.list[ i ].ai_model = value;
                                                                break;
                                                        }
                                                }
                                        }
                                        if ( input ) {
                                                input.value = value;
                                        }
                                        toast( model ? 'Modello "' + model + '" assegnato all\'agente.' : 'Modello dedicato rimosso: verrà usato il modello globale.', 'success', 'Modello aggiornato' );
                                } ).catch( function ( err ) {
                                        apiErrorToast( err, 'Salvataggio modello non riuscito' );
                                        setBtnLoading( saveBtn, false );
                                } );
                        } );

                        // v1.1.7: "Vedi articoli dell'agente" (.nxa-agent-articles-link,
                        // markup PHP statico agents.php con data-agent-id) — link senza
                        // handler (wiring rotto: click = href="#" inerte). Ora apre la
                        // modal con la lista articoli dell'agente (GET /articles
                        // ?agent_id=&per_page=20) e il dettaglio al click sulla riga.
                        grid.addEventListener( 'click', function ( ev ) {
                                var link = ev.target.closest ? ev.target.closest( '.nxa-agent-articles-link' ) : null;
                                if ( ! link ) {
                                        return;
                                }
                                ev.preventDefault();
                                var agentId = num( link.getAttribute( 'data-agent-id' ) );
                                if ( agentId <= 0 ) {
                                        return;
                                }
                                openModalBody( '<div class="nxa-skeleton"></div><div class="nxa-skeleton"></div><div class="nxa-skeleton" style="width:60%"></div>' );
                                bindAgentArticleListModal();
                                api( 'articles?agent_id=' + agentId + '&per_page=20' ).then( function ( payload ) {
                                        var d = unwrap( payload ) || {};
                                        var list = getList( d, [ 'articles', 'items', 'list' ] );
                                        if ( ! list.length ) {
                                                openModalBody( emptyState( '📄', 'Nessun articolo', 'Questo agente non ha ancora prodotto articoli.' ) );
                                                return;
                                        }
                                        var rows = '';
                                        for ( var i = 0; i < list.length; i++ ) {
                                                rows += '<div style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid var(--nxa-border)">' +
                                                        badge( pick( list[ i ], 'status' ) ) +
                                                        '<button type="button" class="button-link nxa-agent-article-open" data-id="' + esc( num( pick( list[ i ], 'id' ) ) ) + '" style="flex:1;min-width:0;text-align:left;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' +
                                                                esc( pick( list[ i ], 'title' ) || '(senza titolo)' ) +
                                                        '</button>' +
                                                '</div>';
                                        }
                                        openModalBody( rows );
                                } ).catch( function ( err ) {
                                        openModalBody( emptyState( '⚠️', 'Impossibile caricare gli articoli', err.message || '' ) );
                                } );
                        } );
                }

                qsa( '.nxa-agents-refresh' ).forEach( function ( btn ) {
                        btn.addEventListener( 'click', function () {
                                setBtnLoading( btn, true, 'Aggiorno…' );
                                loadAgents( true ).then( function () {
                                        setBtnLoading( btn, false );
                                } );
                        } );
                } );

                // v1.0.7: binding dell'editor agenti (modal + nuovo/modifica/clona).
                bindAgentEditor();
        }

        /* ------------------- EDITOR AGENTE (v1.0.7) ------------------- */

        /**
         * v1.0.7: apre l'editor agenti #nxa-agent-modal (markup statico
         * servito da agents.php). agent = riga di agentsCache oppure null
         * per "nuovo agente". Markup/campo mancante → warn silenzioso ed exit.
         */
        function openAgentEditor( agent ) {
                var modal = qs( '#nxa-agent-modal' );
                if ( ! modal ) {
                        window.console.warn( '[Nexus News AI] Editor agenti: #nxa-agent-modal non trovato (markup agents.php assente).' );
                        return;
                }
                var title = qs( '#nxa-agent-modal-title', modal );
                var idField = qs( '#nxa-agent-id', modal );
                var nameField = qs( '#nxa-agent-name', modal );
                var avatarField = qs( '#nxa-agent-avatar', modal );
                var catField = qs( '#nxa-agent-category', modal );
                var statusField = qs( '#nxa-agent-status', modal );
                var modelField = qs( '#nxa-agent-model', modal );
                var descField = qs( '#nxa-agent-description', modal );
                var persField = qs( '#nxa-agent-personality', modal );
                if ( ! title || ! idField || ! nameField || ! avatarField || ! catField || ! statusField || ! modelField || ! descField || ! persField ) {
                        window.console.warn( '[Nexus News AI] Editor agenti: markup del form incompleto, apertura annullata.' );
                        return;
                }
                var a = agent || {};
                var currentStatus = String( pick( a, 'status' ) || 'active' );
                idField.value = String( num( pick( a, 'id' ) ) || 0 );
                nameField.value = String( pick( a, 'name' ) || '' );
                avatarField.value = String( pick( a, 'avatar' ) || '' );
                catField.value = String( pick( a, 'category' ) || '' );
                statusField.value = currentStatus === 'paused' ? 'paused' : 'active';
                modelField.value = String( pick( a, 'ai_model' ) || '' );
                descField.value = String( pick( a, 'description' ) || '' );
                persField.value = String( pick( a, 'personality' ) || '' );
                // v1.1.4: ruolo, provider AI e ambito capo redattore — campi nuovi
                // T-06; markup legacy senza i selettori → precompilazioni saltate
                // (guard null-safe: i campi originali restano obbligatori come prima).
                var roleField = qs( '#nxa-agent-role', modal );
                var providerField = qs( '#nxa-agent-provider', modal );
                var chiefField = qs( '#nxa-agent-chief-field', modal );
                var scopeField = qs( '#nxa-agent-chief-scope', modal );
                var termField = qs( '#nxa-agent-chief-term', modal );
                if ( roleField ) {
                        roleField.value = String( pick( a, 'role' ) ) === 'capo_redattore' ? 'capo_redattore' : 'giornalista';
                }
                if ( providerField ) {
                        loadAgentProviderOptions( providerField, String( pick( a, 'ai_provider', 'aiProvider' ) || '' ) );
                }
                if ( chiefField && scopeField ) {
                        var scopeRaw = String( pick( a, 'chief_scope', 'chiefScope' ) || '' ).toLowerCase();
                        scopeField.value = [ 'global', 'category', 'term' ].indexOf( scopeRaw ) >= 0 ? scopeRaw : 'global';
                }
                if ( termField ) {
                        termField.value = String( num( pick( a, 'chief_term_id', 'chiefTermId' ) ) );
                }
                // v1.1.10 WP4 (Task C1): strumenti personali per-agente. serialize_agent
                // (rest.php) NON espone ancora search_provider/mcp_servers_text →
                // fallback vuoto client-side (la wave 4 aggiungerà i campi al
                // serializzatore per il prefill reale). La chiave NON viene MAI
                // precompilata (segreto: non è leggibile dal server).
                var searchProviderField = qs( '#nxa-agent-search-provider', modal );
                if ( searchProviderField ) {
                        var spSaved = String( pick( a, 'search_provider', 'searchProvider' ) || '' ).toLowerCase();
                        searchProviderField.value = [ 'tavily', 'serper', 'brave' ].indexOf( spSaved ) >= 0 ? spSaved : '';
                }
                var searchKeyField = qs( '#nxa-agent-search-key', modal );
                if ( searchKeyField ) {
                        searchKeyField.value = ''; // mai in chiaro nel DOM al load.
                }
                var mcpField = qs( '#nxa-agent-mcp-servers', modal );
                if ( mcpField ) {
                        mcpField.value = String( pick( a, 'mcp_servers_text', 'mcpServersText' ) || '' );
                }
                updateAgentChiefVisibility( modal );
                if ( termField ) {
                        loadAgentChiefTerms( termField, num( pick( a, 'chief_term_id', 'chiefTermId' ) ) );
                }
                title.textContent = num( pick( a, 'id' ) ) > 0
                        ? 'Modifica agente: ' + String( pick( a, 'name' ) || '' )
                        : 'Nuovo agente';
                var statusBox = qs( '#nxa-agent-form-status', modal );
                if ( statusBox ) {
                        statusBox.textContent = '';
                }
                modal.hidden = false;
                document.body.style.overflow = 'hidden';
                // WP12: niente più datalist dei modelli da riempire qui — il campo
                // #nxa-agent-model è un combobox cercabile (nexusModelPicker) che
                // carica da solo la lista del provider selezionato (cache client 60s,
                // fix G5: reload al cambio di #nxa-agent-provider).
                loadWpCategories(); // v1.1.0: datalist categorie native (cache 60s, fetch solo in questo contesto)
                nameField.focus();
        }

        /** v1.0.7: chiude l'editor agenti, ripristina lo scroll e pulisce lo stato. */
        function closeAgentEditor() {
                var modal = qs( '#nxa-agent-modal' );
                if ( modal ) {
                        modal.hidden = true;
                }
                document.body.style.overflow = '';
                var statusBox = qs( '#nxa-agent-form-status' );
                if ( statusBox ) {
                        statusBox.textContent = '';
                }
        }

        /**
         * v1.0.7: binding dell'editor — chiamata da bindAgents() (nessun
         * tocco a LOADERS/boot). Copre: nuovo, modifica (cache o reload),
         * clona, submit del form, annulla, × [data-nxa-agent-close] e tasto
         * ESC quando la modal è aperta.
         */
        function bindAgentEditor() {
                var grid = qs( '#nxa-agents-grid' );
                if ( grid ) {
                        // v1.0.7: "Modifica" — l'agente arriva da agentsCache.list;
                        // cache vuota/scaduta → getAgents( true ) e riprova.
                        grid.addEventListener( 'click', function ( ev ) {
                                var editBtn = ev.target.closest ? ev.target.closest( '.nxa-agent-edit' ) : null;
                                if ( ! editBtn || editBtn.disabled ) {
                                        return;
                                }
                                var editId = num( editBtn.getAttribute( 'data-agent-id' ) );
                                var agent = agentById( editId );
                                if ( agent ) {
                                        openAgentEditor( agent );
                                        return;
                                }
                                getAgents( true ).then( function ( agents ) {
                                        var found = null;
                                        for ( var i = 0; i < agents.length; i++ ) {
                                                if ( num( agents[ i ].id ) === editId ) {
                                                        found = agents[ i ];
                                                        break;
                                                }
                                        }
                                        if ( found ) {
                                                openAgentEditor( found );
                                        } else {
                                                toast( 'Agente non trovato: ricarica la pagina.', 'error', 'Editor agente' );
                                        }
                                } ).catch( function ( err ) {
                                        apiErrorToast( err, 'Impossibile caricare gli agenti' );
                                } );
                        } );

                        // v1.0.7: "Clona" → POST /agents/{id}/clone; il clone nasce
                        // sempre in pausa lato server, la griglia si ricarica.
                        grid.addEventListener( 'click', function ( ev ) {
                                var cloneBtn = ev.target.closest ? ev.target.closest( '.nxa-agent-clone' ) : null;
                                if ( ! cloneBtn || cloneBtn.disabled ) {
                                        return;
                                }
                                var cloneId = num( cloneBtn.getAttribute( 'data-agent-id' ) );
                                setBtnLoading( cloneBtn, true, 'Clono…' );
                                api( 'agents/' + cloneId + '/clone', { method: 'POST' } ).then( function () {
                                        toast( 'Agente clonato: nasce in pausa.', 'success' );
                                        return loadAgents( true );
                                } ).catch( function ( err ) {
                                        apiErrorToast( err, 'Clonazione non riuscita' );
                                } ).then( function () {
                                        setBtnLoading( cloneBtn, false );
                                } );
                        } );
                }

                // v1.0.7: bottone toolbar "Nuovo agente".
                var newBtn = qs( '#nxa-agent-new' );
                if ( newBtn ) {
                        newBtn.addEventListener( 'click', function () {
                                openAgentEditor( null );
                        } );
                }

                // v1.1.4: barra "Creazione rapida" (agents.php T-06) — il preset
                // prefilla il form e apre il modal nuovo agente; l'invio resta il
                // normale POST /agents del submit qui sotto.
                qsa( '.nxa-preset-btn' ).forEach( function ( btn ) {
                        btn.addEventListener( 'click', function () {
                                var preset = AGENT_PRESETS[ String( btn.getAttribute( 'data-preset' ) || '' ).toLowerCase() ];
                                if ( ! preset ) {
                                        return;
                                }
                                var row = {};
                                for ( var key in preset ) {
                                        if ( Object.prototype.hasOwnProperty.call( preset, key ) ) {
                                                row[ key ] = preset[ key ];
                                        }
                                }
                                openAgentEditor( row );
                        } );
                } );

                // v1.1.4: cambio ruolo → mostra/nasconde l'ambito capo redattore;
                // cambio ambito → mostra/nasconde la scelta della redazione.
                var roleToggle = qs( '#nxa-agent-role' );
                if ( roleToggle ) {
                        roleToggle.addEventListener( 'change', function () {
                                updateAgentChiefVisibility( qs( '#nxa-agent-modal' ) );
                        } );
                }
                var scopeToggle = qs( '#nxa-agent-chief-scope' );
                if ( scopeToggle ) {
                        scopeToggle.addEventListener( 'change', function () {
                                updateAgentChiefVisibility( qs( '#nxa-agent-modal' ) );
                        } );
                }

                // WP12 (fix G5): il combobox del modello nel modal segue il provider
                // selezionato — al cambio si ricarica la lista (GET /ai/models
                // ?provider={slug}, cache client 60s per provider). Il picker valuta
                // #nxa-agent-provider a ogni apertura, il reload aggiorna lo stato
                // se il pannello è già aperto.
                var modalModelInput = qs( '#nxa-agent-model' );
                if ( modalModelInput ) {
                        var modalPicker = nexusModelPicker( modalModelInput, function () {
                                var sel = qs( '#nxa-agent-provider' );
                                return sel ? String( sel.value || '' ) : '';
                        } );
                        var providerSel = qs( '#nxa-agent-provider' );
                        if ( providerSel && modalPicker ) {
                                providerSel.addEventListener( 'change', function () {
                                        modalPicker.reload();
                                } );
                        }
                }
                // v1.0.7: chiusure — "Annulla", × [data-nxa-agent-close], tasto ESC.
                var cancelBtn = qs( '#nxa-agent-cancel' );
                if ( cancelBtn ) {
                        cancelBtn.addEventListener( 'click', closeAgentEditor );
                }
                var closeBtn = qs( '[data-nxa-agent-close]' );
                if ( closeBtn ) {
                        closeBtn.addEventListener( 'click', closeAgentEditor );
                }
                document.addEventListener( 'keydown', function ( ev ) {
                        var modal = qs( '#nxa-agent-modal' );
                        if ( modal && ! modal.hidden && ev.key === 'Escape' ) {
                                closeAgentEditor();
                        }
                } );

                // v1.0.7: submit → id > 0: PUT /agents/{id}/update; id = 0: POST /agents.
                var form = qs( '#nxa-agent-form' );
                if ( ! form ) {
                        return;
                }
                form.addEventListener( 'submit', function ( ev ) {
                        ev.preventDefault();
                        var idField = qs( '#nxa-agent-id', form );
                        var agentId = num( idField ? idField.value : 0 );
                        var readField = function ( selector ) {
                                var field = qs( selector, form );
                                return field ? String( field.value || '' ).trim() : '';
                        };
                        var fields = {
                                name: readField( '#nxa-agent-name' ),
                                avatar: readField( '#nxa-agent-avatar' ),
                                category: readField( '#nxa-agent-category' ),
                                status: readField( '#nxa-agent-status' ),
                                ai_model: readField( '#nxa-agent-model' ),
                                description: readField( '#nxa-agent-description' ),
                                personality: readField( '#nxa-agent-personality' )
                        };
                        if ( ! fields.status ) {
                                fields.status = 'active';
                        }
                        // v1.1.4: ruolo, provider e ambito capo redattore — serializzati
                        // nei POST/PUT agenti esistenti; markup legacy senza i selettori
                        // (agents.php vecchio) → campi omessi, payload invariato.
                        var roleSel = qs( '#nxa-agent-role', form );
                        if ( roleSel ) {
                                fields.role = readField( '#nxa-agent-role' ) === 'capo_redattore' ? 'capo_redattore' : 'giornalista';
                                if ( fields.role === 'capo_redattore' ) {
                                        var scopeVal = readField( '#nxa-agent-chief-scope' );
                                        fields.chief_scope = [ 'global', 'category', 'term' ].indexOf( scopeVal ) >= 0 ? scopeVal : 'global';
                                        fields.chief_term_id = fields.chief_scope === 'term' ? num( readField( '#nxa-agent-chief-term' ) ) : 0;
                                } else {
                                        fields.chief_scope = '';
                                        fields.chief_term_id = 0;
                                }
                        }
                        var providerSel = qs( '#nxa-agent-provider', form );
                        if ( providerSel ) {
                                fields.ai_provider = readField( '#nxa-agent-provider' );
                        }
                        // v1.1.10 WP4 (Task C1): strumenti personali per-agente.
                        // search_provider e mcp_servers_text sono nella whitelist di
                        // update_agent (db.php v1.1.10); search_key viene inviata IN CHIARO
                        // ma l'attuale agent_fields_from_request (rest.php) la scarta senza
                        // errori: la persistenza della chiave richiede il patch REST wave 4
                        // (decisione hashing/chiaro documentata nel worklog C1).
                        var searchProvSel = qs( '#nxa-agent-search-provider', form );
                        if ( searchProvSel ) {
                                var spVal = readField( '#nxa-agent-search-provider' ).toLowerCase();
                                fields.search_provider = [ 'tavily', 'serper', 'brave' ].indexOf( spVal ) >= 0 ? spVal : '';
                        }
                        var searchKeyField = qs( '#nxa-agent-search-key', form );
                        if ( searchKeyField ) {
                                var skVal = readField( '#nxa-agent-search-key' );
                                if ( skVal ) {
                                        fields.search_key = skVal; // solo se compilata: mai svuotamenti accidentali.
                                }
                        }
                        var mcpField = qs( '#nxa-agent-mcp-servers', form );
                        if ( mcpField ) {
                                fields.mcp_servers_text = readField( '#nxa-agent-mcp-servers' );
                        }
                        var statusBox = qs( '#nxa-agent-form-status', form );
                        if ( ! fields.name || ! fields.category ) {
                                if ( statusBox ) {
                                        statusBox.textContent = 'Nome e categoria sono obbligatori.';
                                }
                                return;
                        }
                        if ( statusBox ) {
                                statusBox.textContent = '';
                        }
                        var saveBtn = qs( '#nxa-agent-save', form );
                        setBtnLoading( saveBtn, true, 'Salvo…' );
                        var request = agentId > 0
                                ? api( 'agents/' + agentId + '/update', { method: 'PUT', body: fields } )
                                : api( 'agents', { method: 'POST', body: fields } );
                        request.then( function () {
                                toast( 'Agente salvato.', 'success', 'Fatto' );
                                closeAgentEditor();
                                return loadAgents( true );
                        } ).catch( function ( err ) {
                                apiErrorToast( err, 'Salvataggio agente fallito' );
                                var box = qs( '#nxa-agent-form-status', form );
                                if ( box ) {
                                        box.textContent = ( err && err.message ) ? err.message : 'Salvataggio non riuscito';
                                }
                        } ).then( function () {
                                setBtnLoading( saveBtn, false );
                        } );
                } );
        }

        /* ---------------- CREAZIONE RAPIDA + RUOLO/PROVIDER/CAPO (v1.1.4) ---------------- */

        /**
         * v1.1.4: preset della barra "Creazione rapida" in agents.php (T-06):
         * data-preset → riga di prefill per openAgentEditor (nessun id: resta
         * "Nuovo agente" e l'invio è il normale POST /agents). Categorie,
         * descrizioni e personalità sono le stesse un tempo usate dal seed
         * automatico, RIMOSSO in v1.1.11 (R3: zero agenti default) e da allora
         * uniche sorgenti di questi testi insieme al wizard onboarding; solo il
         * capo porta role/chief_scope. Emoji solo da quelle già presenti nel
         * progetto.
         */
        var AGENT_PRESETS = {
                tech: {
                        name: 'Giornalista tech',
                        avatar: '🤖',
                        category: 'Tecnologia',
                        description: 'Agente AI specializzato in tecnologia, AI, gadget e innovazione.',
                        personality: 'Analitico e orientato all’impatto pratico'
                },
                sport: {
                        name: 'Giornalista sport',
                        avatar: '⚽',
                        category: 'Sport',
                        description: 'Agente AI su calcio, sport nazionali e internazionali.',
                        personality: 'Emozionante e narrativo, con statistiche e analisi tattiche'
                },
                economia: {
                        name: 'Giornalista economia',
                        avatar: '📈',
                        category: 'Economia',
                        description: 'Agente AI su mercati, imprese e finanza personale.',
                        personality: 'Rigoroso ma accessibile, con dati concreti'
                },
                politica: {
                        name: 'Giornalista politica',
                        avatar: '🏛️',
                        category: 'Politica',
                        description: 'Agente AI che copre politica italiana ed estera con contesto storico.',
                        personality: 'Equilibrato e oggettivo, con contesto geopolitico'
                },
                scienza: {
                        name: 'Giornalista scienza',
                        avatar: '🔬',
                        category: 'Scienza',
                        description: 'Agente AI su ricerca, spazio, ambiente e scoperte scientifiche.',
                        personality: 'Divulgativo ma preciso, con spiegazioni chiare'
                },
                cultura: {
                        name: 'Giornalista cultura',
                        avatar: '🎭',
                        category: 'Cultura',
                        description: 'Agente AI su cinema, libri, musica e eventi culturali.',
                        personality: 'Elegante e riflessivo, con riferimenti storici e artistici'
                },
                salute: {
                        name: 'Giornalista salute',
                        avatar: '🩺',
                        category: 'Salute',
                        description: 'Agente AI su salute, benessere e medicina basata su evidenze.',
                        personality: 'Rassicurante e basato su evidenze scientifiche'
                },
                capo: {
                        name: 'Capo redattore',
                        avatar: '📰',
                        category: 'Direzione',
                        role: 'capo_redattore',
                        chief_scope: 'global',
                        description: 'Legge gli articoli dei giornalisti e li approva, modifica, manda in revisione o rifiuta.',
                        personality: 'Rigoroso, sintetico e difensore della qualità editoriale'
                }
        };

        /**
         * v1.1.4: visibilità dell'ambito capo redattore nel modal editor — il
         * blocco #nxa-agent-chief-field compare solo con ruolo capo_redattore,
         * la scelta della redazione solo con ambito "term". Markup assente →
         * exit silenzioso (compatibile con agents.php vecchio).
         */
        function updateAgentChiefVisibility( modal ) {
                var root = modal || qs( '#nxa-agent-modal' );
                if ( ! root ) {
                        return;
                }
                var roleSel = qs( '#nxa-agent-role', root );
                var chiefBox = qs( '#nxa-agent-chief-field', root );
                var scopeSel = qs( '#nxa-agent-chief-scope', root );
                var termSel = qs( '#nxa-agent-chief-term', root );
                if ( ! roleSel || ! chiefBox ) {
                        return;
                }
                var isCapo = String( roleSel.value || '' ) === 'capo_redattore';
                chiefBox.hidden = ! isCapo;
                var scope = scopeSel ? String( scopeSel.value || '' ) : '';
                if ( termSel ) {
                        termSel.hidden = ! isCapo || scope !== 'term';
                }
        }

        /** v1.1.4: option di riserva per un provider corrente fuori catalogo. */
        function ensureProviderOption( select, providerId, label ) {
                var value = String( providerId || '' ).trim();
                if ( ! select || ! value ) {
                        return;
                }
                for ( var i = 0; i < select.options.length; i++ ) {
                        if ( String( select.options[ i ].value || '' ) === value ) {
                                return;
                        }
                }
                var opt = document.createElement( 'option' );
                opt.setAttribute( 'value', value );
                opt.textContent = String( label || value );
                select.appendChild( opt );
        }

        /**
         * v1.1.4: riempie #nxa-agent-provider con i provider del catalogo
         * (GET /tools/catalog T-05 → {providers} come array o mappa id→voce).
         * La prima option resta "" = "Provider globale (impostazioni)". La
         * risposta vecchia senza providers (route T-05 non ancora integrata)
         * o la fetch fallita lasciano il solo provider globale + eventuale
         * provider corrente dell'agente: mai crash, mai perdita silenziosa.
         */
        function fillAgentProviderOptions( select, catalog, currentProvider ) {
                if ( ! select ) {
                        return;
                }
                var current = String( currentProvider || '' );
                var providers = ( catalog && typeof catalog === 'object' ) ? catalog.providers : null;
                var entries = [];
                if ( Array.isArray( providers ) ) {
                        for ( var i = 0; i < providers.length; i++ ) {
                                var item = providers[ i ] || {};
                                var itemId = String( item.id || '' ).trim();
                                if ( itemId ) {
                                        entries.push( { id: itemId, label: String( item.label || itemId ) } );
                                }
                        }
                } else if ( providers && typeof providers === 'object' ) {
                        for ( var key in providers ) {
                                if ( Object.prototype.hasOwnProperty.call( providers, key ) ) {
                                        var entry = providers[ key ] || {};
                                        entries.push( { id: String( key ), label: String( entry.label || key ) } );
                                }
                        }
                }
                if ( ! entries.length ) {
                        ensureProviderOption( select, current, current );
                        select.value = current;
                        return;
                }
                select.innerHTML = '';
                var globalOpt = document.createElement( 'option' );
                globalOpt.setAttribute( 'value', '' );
                globalOpt.textContent = 'Provider globale (impostazioni)';
                select.appendChild( globalOpt );
                for ( var j = 0; j < entries.length; j++ ) {
                        var opt = document.createElement( 'option' );
                        opt.setAttribute( 'value', entries[ j ].id );
                        opt.textContent = entries[ j ].label;
                        select.appendChild( opt );
                }
                ensureProviderOption( select, current, current );
                select.value = current;
        }

        /** v1.1.4: cache pagina del catalogo provider (GET /tools/catalog, 60s). */
        var toolsCatalogCache = { data: null, at: 0, ttl: 60000 };

        /**
         * v1.1.4: provider corrente impostato SUBITO (anche a catalogo non
         * ancora caricato), poi la fetch (o la cache) completa la lista.
         */
        function loadAgentProviderOptions( providerSelect, currentProvider ) {
                var current = String( currentProvider || '' );
                if ( providerSelect && current ) {
                        ensureProviderOption( providerSelect, current, current );
                        providerSelect.value = current;
                }
                if ( ! providerSelect ) {
                        return Promise.resolve();
                }
                var fresh = toolsCatalogCache.data && ( Date.now() - toolsCatalogCache.at ) < toolsCatalogCache.ttl;
                if ( fresh ) {
                        fillAgentProviderOptions( providerSelect, toolsCatalogCache.data, current );
                        return Promise.resolve();
                }
                return api( 'tools/catalog' ).then( function ( payload ) {
                        var data = unwrap( payload ) || {};
                        toolsCatalogCache.data = ( data.catalog && typeof data.catalog === 'object' ) ? data.catalog : data;
                        toolsCatalogCache.at = Date.now();
                        fillAgentProviderOptions( providerSelect, toolsCatalogCache.data, current );
                } ).catch( function ( err ) {
                        window.console.warn( '[Nexus] catalogo strumenti/provider:', err );
                } );
        }

        /** v1.1.4: option di riserva per un termine salvato non più in elenco. */
        function ensureTermOption( select, termId ) {
                var value = String( num( termId ) );
                if ( ! select || num( value ) <= 0 ) {
                        return;
                }
                for ( var i = 0; i < select.options.length; i++ ) {
                        if ( String( select.options[ i ].value || '' ) === value ) {
                                return;
                        }
                }
                var opt = document.createElement( 'option' );
                opt.setAttribute( 'value', value );
                opt.textContent = 'Redazione #' + value;
                select.appendChild( opt );
        }

        /**
         * v1.1.4: riempie #nxa-agent-chief-term con i termini redazioni. Contratto
         * T-06: il file ha già fetch verso nexus/v1 (getRedazioni, cache pagina
         * della colonna "Redazioni") quindi si riusa QUELLA route — nessun fetch
         * nuovo verso wp/v2. Fetch fallita → warn + select col solo placeholder.
         */
        function loadAgentChiefTerms( termSelect, savedTermId ) {
                if ( ! termSelect ) {
                        return Promise.resolve();
                }
                return getRedazioni().then( function ( terms ) {
                        if ( ! termSelect.parentNode ) {
                                return null; // modal chiuso nel frattempo: nessun tocco al DOM
                        }
                        termSelect.innerHTML = '';
                        var placeholder = document.createElement( 'option' );
                        placeholder.setAttribute( 'value', '0' );
                        placeholder.textContent = 'Seleziona redazione…';
                        termSelect.appendChild( placeholder );
                        ( Array.isArray( terms ) ? terms : [] ).forEach( function ( term ) {
                                var tid = num( pick( term, 'id' ) );
                                if ( tid <= 0 ) {
                                        return;
                                }
                                var opt = document.createElement( 'option' );
                                opt.setAttribute( 'value', String( tid ) );
                                opt.textContent = String( pick( term, 'name' ) || ( 'Redazione #' + tid ) );
                                termSelect.appendChild( opt );
                        } );
                        var saved = num( savedTermId );
                        if ( saved > 0 ) {
                                ensureTermOption( termSelect, saved );
                                termSelect.value = String( saved );
                        }
                        return null;
                } ).catch( function ( err ) {
                        window.console.warn( '[Nexus] redazioni (ambito capo redattore):', err );
                } );
        }

        /* ==================================================================
         * 9-bis. MODELLI AI PROVIDER + MODEL PICKER (v1.0.7; v2 WP12 v1.1.10)
         * ================================================================== */

        /**
         * WP12: cache client GET /ai/models PER PROVIDER (Map slug→entry).
         * '__global__' = ramo legacy (chiave+base URL globali). TTL 60s: il
         * server ha la sua cache 12h per-provider, qui si limitano i rifetch.
         */
        var mpCache = {};
        var MP_TTL = 60000;
        var MP_MAX_RESULTS = 60;

        /**
         * WP12: registry dei picker apribili + bind document UNICO (fuori-click,
         * focusin delegato per le card agente). Evita che ogni picker aggiunga il
         * proprio listener document (le card si rirenderizzano: leak evitato,
         * i wrap staccati dal DOM vengono potati).
         */
        var mpRegistry = [];
        var mpDocBound = false;

        /** WP12: normalizza una voce modello (stringa legacy v1 o oggetto v2). */
        function mpNormModel( m ) {
                if ( typeof m === 'string' ) {
                        var sid = m.trim();
                        return sid ? { id: sid } : null;
                }
                if ( m && typeof m === 'object' && m.id ) {
                        var out = { id: String( m.id ).trim() };
                        if ( m.name ) {
                                out.name = String( m.name );
                        }
                        var cl = num( m.context_length );
                        if ( cl > 0 ) {
                                out.context_length = cl;
                        }
                        if ( m.prompt_price !== undefined && m.prompt_price !== null && isFinite( parseFloat( m.prompt_price ) ) ) {
                                out.prompt_price = parseFloat( m.prompt_price );
                        }
                        if ( m.completion_price !== undefined && m.completion_price !== null && isFinite( parseFloat( m.completion_price ) ) ) {
                                out.completion_price = parseFloat( m.completion_price );
                        }
                        if ( m.free ) {
                                out.free = true;
                        }
                        return out.id ? out : null;
                }
                return null;
        }

        /** WP12: cache client fresca per provider ('' = globale)? */
        function mpCacheFresh( provider ) {
                var c = mpCache[ provider || '__global__' ];
                return !!( c && c.list && ( Date.now() - c.at ) < MP_TTL );
        }

        /**
         * WP12: GET /ai/models[?provider={slug}&refresh=1] → Promise che risolve
         * {list:[{id,…}], cached, count}. Cache client per-provider 60s e dedupe
         * delle chiamate concorrenti; force=true bypassa la cache client E
         * invalida quella server (refresh=1). Nessuna cache negativa: un errore
         * lascia ripartire il prossimo open pulito.
         */
        function fetchProviderModels( provider, force ) {
                var key = provider || '__global__';
                var now = Date.now();
                var cached = mpCache[ key ];
                if ( ! force && cached && cached.list && ( now - cached.at ) < MP_TTL ) {
                        return Promise.resolve( { list: cached.list, cached: true, count: cached.list.length } );
                }
                if ( ! force && cached && cached.pending ) {
                        return cached.pending;
                }
                var query = 'ai/models';
                if ( provider ) {
                        query += '?provider=' + encodeURIComponent( provider );
                        if ( force ) {
                                query += '&refresh=1';
                        }
                } else if ( force ) {
                        query += '?refresh=1';
                }
                var req = api( query ).then( function ( payload ) {
                        var data = unwrap( payload ) || {};
                        var raw = Array.isArray( data.models ) ? data.models : getList( payload, [ 'models', 'items', 'list' ] );
                        var list = [];
                        for ( var i = 0; i < raw.length; i++ ) {
                                var m = mpNormModel( raw[ i ] );
                                if ( m && m.id ) {
                                        list.push( m );
                                }
                        }
                        mpCache[ key ] = { list: list, at: Date.now(), ttl: MP_TTL, pending: null };
                        // v1.1.11 (R5): flag onesti del server propagati al caller
                        // (fetch_disabled / stale / missing_key) — il wrapper in coda
                        // al file li usa per gli avvisi, i picker mostrano la lista.
                        return { list: list, cached: !!data.cached, count: num( data.count ) || list.length,
                                fetch_disabled: !!data.fetch_disabled, stale: !!data.stale, missing_key: !!data.missing_key };
                } ).catch( function ( err ) {
                        if ( mpCache[ key ] ) {
                                mpCache[ key ].pending = null;
                        }
                        throw err;
                } );
                if ( ! mpCache[ key ] ) {
                        mpCache[ key ] = { list: null, at: 0, ttl: MP_TTL, pending: null };
                }
                mpCache[ key ].pending = req;
                return req;
        }

        /** WP12: CSS del picker (.nxa-mp-*), iniettato UNA volta per pagina. */
        var MP_CSS = [
                '.nxa-mp{position:relative;display:inline-block;max-width:100%}',
                '.nxa-mp>.nxa-agent-model,.nxa-agent-field .nxa-mp>input{width:100%;max-width:none;box-sizing:border-box}',
                '.nxa-mp .nxa-mp-panel{position:absolute;top:100%;left:0;right:0;z-index:100500;margin-top:4px;display:block;padding:8px;background:var(--nxa-surface,#fff);border:1px solid var(--nxa-border,#e2e8f2);border-radius:10px;box-shadow:var(--nxa-shadow,0 10px 28px rgba(15,23,42,.12));text-align:left}',
                '.nxa-mp .nxa-mp-search{display:block;width:100%;box-sizing:border-box;margin:0 0 8px;padding:6px 10px;border:1px solid var(--nxa-border,#e2e8f2);border-radius:8px;background:var(--nxa-surface-2,#f2f5fa);color:var(--nxa-text-body,#1d2327);font-size:13px;line-height:1.5}',
                '.nxa-mp .nxa-mp-search:focus{border-color:var(--nxa-focus,#0891b2);outline:none;box-shadow:0 0 0 2px var(--nxa-info-bg,rgba(14,165,233,.12))}',
                '.nxa-mp .nxa-mp-list{display:block;max-height:320px;overflow-y:auto;overscroll-behavior:contain}',
                '.nxa-mp .nxa-mp-item{display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:8px;cursor:pointer;line-height:1.45}',
                '.nxa-mp .nxa-mp-item:hover,.nxa-mp .nxa-mp-item.is-active{background:var(--nxa-surface-2,#f2f5fa)}',
                '.nxa-mp .nxa-mp-id{font-family:Consolas,Monaco,monospace;font-size:12px;color:var(--nxa-text-body,#1d2327);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
                '.nxa-mp .nxa-mp-name{color:var(--nxa-muted,#5c6b84);font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0;flex:0 1 auto}',
                '.nxa-mp .nxa-mp-badges{margin-left:auto;display:inline-flex;gap:4px;flex-shrink:0}',
                '.nxa-mp .nxa-mp-badge{display:inline-block;padding:1px 7px;border-radius:999px;border:1px solid var(--nxa-border,#e2e8f2);background:var(--nxa-surface-2,#f2f5fa);color:var(--nxa-muted,#5c6b84);font-size:10px;font-weight:600;line-height:1.6;white-space:nowrap}',
                '.nxa-mp .nxa-mp-badge--free{color:var(--nxa-ok-text,#15803d);background:var(--nxa-ok-bg,rgba(34,197,94,.12));border-color:transparent}',
                '.nxa-mp .nxa-mp-badge--ctx{font-family:Consolas,Monaco,monospace}',
                '.nxa-mp .nxa-mp-empty{display:block;padding:10px 8px;color:var(--nxa-muted,#5c6b84);font-size:12px}',
                '.nxa-mp .nxa-mp-empty--err{color:var(--nxa-err-text,#b91c1c);font-weight:600}',
                '.nxa-mp .nxa-mp-more{display:block;padding:8px 8px 2px;color:var(--nxa-muted,#5c6b84);font-size:11px}'
        ].join( '\n' );

        function mpInjectStyles() {
                if ( qs( '#nxa-mp-css' ) ) {
                        return;
                }
                var style = document.createElement( 'style' );
                style.id = 'nxa-mp-css';
                style.textContent = MP_CSS;
                var head = document.head || qs( 'head' );
                if ( head ) {
                        head.appendChild( style );
                }
        }

        /** WP12: bind document UNICO — fuori-click chiude i picker, focusin inizializza le card agente. */
        function mpBindDocument() {
                if ( mpDocBound ) {
                        return;
                }
                mpDocBound = true;
                document.addEventListener( 'mousedown', function ( ev ) {
                        for ( var i = 0; i < mpRegistry.length; i++ ) {
                                var p = mpRegistry[ i ];
                                if ( p.wrap && ! p.wrap.contains( ev.target ) ) {
                                        p.close();
                                }
                        }
                } );
                // Card agente (.nxa-agent-model): il combobox WP12 si attacca al
                // focus con inizializzazione delegata (le card si rirenderizzano).
                document.addEventListener( 'focusin', function ( ev ) {
                        var input = ev.target && ev.target.classList && ev.target.classList.contains( 'nxa-agent-model' ) ? ev.target : null;
                        if ( ! input || input.id === 'nxa-agent-model' ) {
                                return; // il modal è agganciato a parte in bindAgents()
                        }
                        var picker = nexusModelPicker( input, function () {
                                return String( input.getAttribute( 'data-provider' ) || '' );
                        } );
                        if ( picker && document.activeElement === input ) {
                                picker.open();
                        }
                } );
        }

        /** WP12: pota dal registry i picker il cui input è stato rimosso dal DOM. */
        function mpRegister( wrap, closeFn ) {
                mpRegistry.push( { wrap: wrap, close: closeFn } );
                var alive = [];
                for ( var i = 0; i < mpRegistry.length; i++ ) {
                        var w = mpRegistry[ i ].wrap;
                        if ( w && document.contains( w ) ) {
                                alive.push( mpRegistry[ i ] );
                        }
                }
                mpRegistry = alive;
        }

        /**
         * WP12: factory del COMBOBOX cercabile riusabile (Impostazioni → modello
         * globale, modal agenti, card agente). nexusModelPicker( inputEl, getProviderFn ):
         * - inputEl       : input testo (value = model id);
         * - getProviderFn : () → provider slug ('' = globale), rivalutato a ogni
         *                   apertura (il cambio provider reload la lista, fix G5).
         * Dropdown assoluto con box di ricerca interna (match su id/name,
         * case-insensitive), badge GRATIS/contesto/prezzo, max 60 risultati con
         * scroll, frecce + Invio, Esc/blur chiude. Nessuna virtualizzazione: il
         * filtering client-side sul cap server 800 basta.
         * Doppie inizializzazioni bloccate da data-nxa-mp="1". Ritorna {reload, open, close}.
         */
        function nexusModelPicker( inputEl, getProviderFn ) {
                if ( ! inputEl || ! inputEl.parentNode || inputEl.getAttribute( 'data-nxa-mp' ) ) {
                        return null;
                }
                inputEl.setAttribute( 'data-nxa-mp', '1' );
                inputEl.setAttribute( 'autocomplete', 'off' );
                inputEl.setAttribute( 'role', 'combobox' );
                inputEl.setAttribute( 'aria-autocomplete', 'list' );
                inputEl.setAttribute( 'aria-expanded', 'false' );
                mpInjectStyles();
                mpBindDocument();

                // La card agente arriva GIÀ avvolta in span.nxa-mp (template
                // agentCardHtml, col flex inline): riusala invece di annidarne
                // una seconda; per gli input nudi (Impostazioni/modal) creala qui.
                var wrap = inputEl.parentNode;
                if ( ! ( wrap && wrap.classList && wrap.classList.contains( 'nxa-mp' ) ) ) {
                        wrap = document.createElement( 'span' );
                        wrap.className = 'nxa-mp';
                        inputEl.parentNode.insertBefore( wrap, inputEl );
                        wrap.appendChild( inputEl );
                }

                // Dentro una modal (.nxa-modal-panel ha overflow-y:auto) l'absolute
                // verrebbe clippato: positioning FIXED ancorato al rettangolo input.
                var fixedMode = !!( inputEl.closest && inputEl.closest( '.nxa-modal' ) );

                var panel = document.createElement( 'span' );
                panel.className = 'nxa-mp-panel';
                panel.setAttribute( 'role', 'listbox' );
                panel.hidden = true;

                var search = document.createElement( 'input' );
                search.type = 'text';
                search.className = 'nxa-mp-search';
                search.placeholder = 'Cerca modello per id o nome…';
                search.setAttribute( 'aria-label', 'Cerca modello' );

                var listBox = document.createElement( 'span' );
                listBox.className = 'nxa-mp-list';

                panel.appendChild( search );
                panel.appendChild( listBox );
                wrap.appendChild( panel );

                var state = { open: false, filter: '', all: [], matches: [], active: -1, loading: false, err: '' };

                function providerSlug() {
                        try {
                                return String( ( typeof getProviderFn === 'function' ? getProviderFn() : '' ) || '' ).trim();
                        } catch ( e ) {
                                return '';
                        }
                }

                function mpPrice( v ) {
                        var n = parseFloat( v );
                        if ( ! isFinite( n ) ) {
                                return '?';
                        }
                        var s = n >= 1 ? n.toFixed( 2 ) : n.toFixed( 3 );
                        s = s.replace( /0+$/, '' ).replace( /\.$/, '' );
                        return s === '' || s === '-' ? '0' : s;
                }

                function mpCtx( n ) {
                        n = num( n );
                        if ( n >= 1000000 ) {
                                return ( Math.round( ( n / 1000000 ) * 10 ) / 10 ) + 'M';
                        }
                        if ( n >= 1000 ) {
                                return Math.round( n / 1000 ) + 'k';
                        }
                        return n > 0 ? String( n ) : '';
                }

                function badgeHtml( m ) {
                        var out = '';
                        if ( m.free ) {
                                out += '<span class="nxa-mp-badge nxa-mp-badge--free">GRATIS</span>';
                        } else if ( typeof m.prompt_price === 'number' && typeof m.completion_price === 'number' ) {
                                out += '<span class="nxa-mp-badge">$' + mpPrice( m.prompt_price ) + '/' + mpPrice( m.completion_price ) + '</span>';
                        } else if ( typeof m.prompt_price === 'number' ) {
                                out += '<span class="nxa-mp-badge">~$' + mpPrice( m.prompt_price ) + '</span>';
                        }
                        var ctx = mpCtx( m.context_length );
                        if ( ctx ) {
                                out += '<span class="nxa-mp-badge nxa-mp-badge--ctx">' + esc( ctx ) + '</span>';
                        }
                        return out;
                }

                function itemHtml( m ) {
                        var name = ( m.name && String( m.name ) !== String( m.id ) )
                                ? '<span class="nxa-mp-name">' + esc( m.name ) + '</span>'
                                : '';
                        return '<span class="nxa-mp-item" role="option" data-mp-id="' + esc( m.id ) + '">' +
                                '<span class="nxa-mp-id">' + esc( m.id ) + '</span>' + name +
                                '<span class="nxa-mp-badges">' + badgeHtml( m ) + '</span></span>';
                }

                /** WP12: filtro su id/name case-insensitive; prefisso id prima; cap 60. */
                function computeMatches() {
                        var q = state.filter.trim().toLowerCase();
                        var all = state.all;
                        if ( ! q ) {
                                return all.slice( 0, MP_MAX_RESULTS );
                        }
                        var scored = [];
                        for ( var i = 0; i < all.length; i++ ) {
                                var m = all[ i ];
                                var id = String( m.id || '' ).toLowerCase();
                                var nm = m.name ? String( m.name ).toLowerCase() : '';
                                if ( id.indexOf( q ) === -1 && nm.indexOf( q ) === -1 ) {
                                        continue;
                                }
                                scored.push( { m: m, rank: id.indexOf( q ) === 0 ? 0 : 1 } );
                        }
                        scored.sort( function ( a, b ) {
                                if ( a.rank !== b.rank ) {
                                        return a.rank - b.rank;
                                }
                                var ai = String( a.m.id ).toLowerCase();
                                var bi = String( b.m.id ).toLowerCase();
                                return ai < bi ? -1 : ( ai > bi ? 1 : 0 );
                        } );
                        return scored.slice( 0, MP_MAX_RESULTS ).map( function ( s ) {
                                return s.m;
                        } );
                }

                function syncActive() {
                        var items = listBox.querySelectorAll( '.nxa-mp-item' );
                        for ( var i = 0; i < items.length; i++ ) {
                                if ( i === state.active ) {
                                        items[ i ].classList.add( 'is-active' );
                                        items[ i ].setAttribute( 'aria-selected', 'true' );
                                } else {
                                        items[ i ].classList.remove( 'is-active' );
                                        items[ i ].removeAttribute( 'aria-selected' );
                                }
                        }
                }

                function render() {
                        var html = '';
                        if ( state.err ) {
                                html = '<span class="nxa-mp-empty nxa-mp-empty--err">' + esc( state.err ) + '</span>';
                        } else if ( state.loading && ! state.all.length ) {
                                html = '<span class="nxa-mp-empty">Caricamento modelli…</span>';
                        } else {
                                var matches = computeMatches();
                                state.matches = matches;
                                if ( ! matches.length ) {
                                        html = '<span class="nxa-mp-empty">Nessun modello trovato' + ( state.filter ? ' per “' + esc( state.filter ) + '”' : '' ) + '.</span>';
                                } else {
                                        var parts = [];
                                        for ( var i = 0; i < matches.length; i++ ) {
                                                parts.push( itemHtml( matches[ i ] ) );
                                        }
                                        if ( state.all.length > matches.length ) {
                                                parts.push( '<span class="nxa-mp-more">Mostrati ' + matches.length + ' di ' + state.all.length + ' modelli: affina la ricerca.</span>' );
                                        }
                                        html = parts.join( '' );
                                }
                        }
                        listBox.innerHTML = html;
                        state.active = state.matches.length ? 0 : -1;
                        syncActive();
                        if ( search.value !== state.filter ) {
                                search.value = state.filter;
                        }
                }

                function moveActive( delta ) {
                        if ( ! state.matches.length ) {
                                return;
                        }
                        state.active = ( state.active + delta + state.matches.length ) % state.matches.length;
                        syncActive();
                        var items = listBox.querySelectorAll( '.nxa-mp-item' );
                        if ( items[ state.active ] && items[ state.active ].scrollIntoView ) {
                                items[ state.active ].scrollIntoView( { block: 'nearest' } );
                        }
                }

                function choose( m ) {
                        if ( ! m ) {
                                return;
                        }
                        inputEl.value = String( m.id || '' );
                        close();
                        try {
                                inputEl.dispatchEvent( new Event( 'change', { bubbles: true } ) );
                        } catch ( e ) {
                                // browser legacy: il value è già impostato, nessun danno
                        }
                }

                /** WP12: fetch per il provider CORRENTE (cache client 60s). */
                function load( force ) {
                        var prov = providerSlug();
                        state.err = '';
                        state.loading = true;
                        render();
                        fetchProviderModels( prov, !!force ).then( function ( res ) {
                                if ( prov !== providerSlug() || ! state.open ) {
                                        return; // il provider è cambiato nel frattempo
                                }
                                state.loading = false;
                                state.all = res.list || [];
                                render();
                        } ).catch( function ( err ) {
                                if ( prov !== providerSlug() || ! state.open ) {
                                        return;
                                }
                                state.loading = false;
                                state.err = ( err && err.message ) ? err.message : 'Caricamento modelli fallito';
                                render();
                        } );
                }

                function positionFixed() {
                        var rect = inputEl.getBoundingClientRect();
                        panel.style.position = 'fixed';
                        panel.style.left = rect.left + 'px';
                        panel.style.top = ( rect.bottom + 4 ) + 'px';
                        panel.style.width = Math.max( rect.width, 280 ) + 'px';
                }

                function open() {
                        if ( state.open ) {
                                return;
                        }
                        state.open = true;
                        state.filter = '';
                        state.err = '';
                        search.value = '';
                        if ( fixedMode ) {
                                positionFixed();
                        }
                        panel.hidden = false;
                        inputEl.setAttribute( 'aria-expanded', 'true' );
                        render();
                        load( false );
                }

                function close() {
                        if ( ! state.open ) {
                                return;
                        }
                        state.open = false;
                        panel.hidden = true;
                        inputEl.setAttribute( 'aria-expanded', 'false' );
                }

                /** WP12: chiudi dopo un blur, SALVO il focus spostato nel pannello. */
                function closeSoon() {
                        window.setTimeout( function () {
                                if ( ! state.open ) {
                                        return;
                                }
                                var active = document.activeElement;
                                if ( active && wrap.contains( active ) ) {
                                        return;
                                }
                                close();
                        }, 140 );
                }

                function onKeyDown( ev, fromSearch ) {
                        var key = ev.key || '';
                        if ( key === 'ArrowDown' || key === 'ArrowUp' ) {
                                ev.preventDefault();
                                if ( ! state.open ) {
                                        open();
                                        return;
                                }
                                moveActive( key === 'ArrowDown' ? 1 : -1 );
                        } else if ( key === 'Enter' ) {
                                if ( state.open ) {
                                        ev.preventDefault(); // pannello aperto: mai submit accidentale
                                        if ( state.matches.length && state.active >= 0 ) {
                                                ev.stopPropagation();
                                                choose( state.matches[ state.active ] );
                                        }
                                }
                        } else if ( key === 'Escape' || key === 'Esc' ) {
                                if ( state.open ) {
                                        ev.preventDefault();
                                        ev.stopPropagation(); // Esc chiude il picker, NON il modal/pagina
                                        close();
                                        if ( fromSearch ) {
                                                inputEl.focus();
                                        }
                                }
                        } else if ( key === 'Tab' ) {
                                close();
                        }
                }

                if ( fixedMode ) {
                        // La modal scorre: allineare continuamente è rumore → chiudi.
                        document.addEventListener( 'scroll', function ( ev ) {
                                if ( state.open && wrap.contains( ev.target ) === false ) {
                                        close();
                                }
                        }, true );
                        window.addEventListener( 'resize', close );
                }

                inputEl.addEventListener( 'focus', open );
                inputEl.addEventListener( 'input', function () {
                        if ( ! state.open ) {
                                open();
                        }
                        state.filter = inputEl.value;
                        render();
                } );
                inputEl.addEventListener( 'keydown', function ( ev ) {
                        onKeyDown( ev, false );
                } );
                inputEl.addEventListener( 'blur', closeSoon );

                search.addEventListener( 'input', function () {
                        if ( ! state.open ) {
                                open();
                        }
                        state.filter = search.value;
                        render();
                } );
                search.addEventListener( 'keydown', function ( ev ) {
                        onKeyDown( ev, true );
                } );
                search.addEventListener( 'blur', closeSoon );

                // Click su un risultato: preventDefault al mousedown per non perdere
                // il focus dall'input (il blur-chiusura non scatta).
                listBox.addEventListener( 'mousedown', function ( ev ) {
                        var item = ev.target && ev.target.closest ? ev.target.closest( '.nxa-mp-item' ) : null;
                        if ( item ) {
                                ev.preventDefault();
                        }
                } );
                listBox.addEventListener( 'click', function ( ev ) {
                        var item = ev.target && ev.target.closest ? ev.target.closest( '.nxa-mp-item' ) : null;
                        if ( ! item ) {
                                return;
                        }
                        var id = item.getAttribute( 'data-mp-id' );
                        for ( var i = 0; i < state.matches.length; i++ ) {
                                if ( String( state.matches[ i ].id ) === id ) {
                                        choose( state.matches[ i ] );
                                        return;
                                }
                        }
                } );

                var picker = {
                        /**
                         * WP12 (fix G5): lista aggiornata al cambio provider.
                         * force=true scarta anche la cache client 60s.
                         */
                        reload: function ( force ) {
                                var key = providerSlug() || '__global__';
                                if ( force ) {
                                        delete mpCache[ key ];
                                }
                                state.all = [];
                                state.err = '';
                                if ( state.open ) {
                                        load( false );
                                }
                        },
                        open: open,
                        close: close
                };
                mpRegister( wrap, close );
                return picker;
        }

        /** v1.0.7: stato leggibile accanto al bottone "Carica modelli". */
        function setModelsStatus( text ) {
                var node = qs( '#nxa-ai-models-status' );
                if ( node ) {
                        node.textContent = text;
                }
        }

        /**
         * v1.0.7 (v2 WP12): forza il caricamento dei modelli del provider GLOBALE
         * (force → refresh=1 lato server). Aggiorna lo stato leggibile; i picker
         * aperti si riallineano da soli al prossimo render (cache client
         * invalidata da fetchProviderModels(force)). Errori onesti: toast +
         * messaggio nello stato (chiave assente, HTTP errato, …).
         */
        function loadAiModels( force ) {
                var loadBtn = qs( '#nxa-ai-models-load' );
                setBtnLoading( loadBtn, true, 'Carico…' );
                return fetchProviderModels( '', !!force ).then( function ( res ) {
                        var list = res.list || [];
                        // v1.1.11 (R5): suffisso onesto quando la lista NON viene dal
                        // provider (fetch disattivato / cache scaduta dopo fail-open).
                        var suffix = res.stale ? ' (cache scaduta: il provider non ha risposto)'
                                : ( res.fetch_disabled ? ' (fetch disattivato)' : ( res.cached ? ' (cache)' : '' ) );
                        setModelsStatus( ( num( res.count ) || list.length ) + ' modelli disponibili dal provider' + suffix );
                } ).catch( function ( err ) {
                        apiErrorToast( err, 'Caricamento modelli fallito' );
                        var message = ( err && err.message ) ? err.message : 'Operazione non riuscita';
                        if ( message.indexOf( 'salva prima la chiave' ) !== -1 ) {
                                message += ' Salva le impostazioni e riprova.';
                        }
                        setModelsStatus( message );
                } ).then( function () {
                        setBtnLoading( loadBtn, false );
                } );
        }

        /* ==================================================================
         * 9-bis/1. PROVIDER AI v2 — wizard chiavi, OAuth PKCE e test
         * connessione (v1.1.10, WP10 — pagina Impostazioni AI, sezione
         * "Provider AI per agente"; contratto classi/data-attribute nel
         * docblock di settings-ai.php)
         * ================================================================== */

        /** Nodo dell'esito inline del test per un provider (aria-live). */
        function providerStatusNode( provider ) {
                return qs( '.nxa-prov-status[data-nxa-status="' + provider + '"]' );
        }

        /** Esito inline verde/rosso: kind 'ok' | 'err' | '' (neutro/in corso). */
        function setProviderStatus( provider, message, kind ) {
                var node = providerStatusNode( provider );
                if ( ! node ) {
                        return;
                }
                node.textContent = message || '';
                node.classList.remove( 'nxa-prov-status--ok', 'nxa-prov-status--err' );
                if ( kind === 'ok' ) {
                        node.classList.add( 'nxa-prov-status--ok' );
                } else if ( kind === 'err' ) {
                        node.classList.add( 'nxa-prov-status--err' );
                }
        }

        /** Badge stato provider: verde = chiave salvata, grigio = vuota. */
        function setProviderDot( provider, on ) {
                var dot = qs( '.nxa-prov-dot[data-nxa-dot="' + provider + '"]' );
                if ( ! dot ) {
                        return;
                }
                dot.classList.remove( 'nxa-prov-dot--on', 'nxa-prov-dot--off' );
                dot.classList.add( on ? 'nxa-prov-dot--on' : 'nxa-prov-dot--off' );
        }

        /**
         * Bottoni wizard provider (delega click sul form: i bottoni sono statici,
         * renderizzati server-side, la pagina non li ricrea mai):
         *   .nxa-prov-oauth[data-provider]      → POST /ai/oauth/start → redirect
         *                                         sull'auth_url di OpenRouter;
         *   .nxa-prov-test[data-provider]       → POST /ai/test/{provider} (esito
         *                                         inline verde/rosso; {base_url}
         *                                         dal campo #nxa-prov-base-custom
         *                                         per il provider custom);
         *   .nxa-prov-disconnect[data-provider] → POST /ai/disconnect/{provider}.
         * Mai alert(): toast + esito inline. La chiave non è mai letta/loggata.
         */
        function bindProviderActions( form ) {
                if ( ! form ) {
                        return;
                }

                form.addEventListener( 'click', function ( ev ) {
                        var target = ev.target;
                        if ( ! target || ! target.closest ) {
                                return;
                        }

                        // --- Connetti con OpenRouter (login web OAuth PKCE) -------
                        var oauthBtn = target.closest( '.nxa-prov-oauth' );
                        if ( oauthBtn ) {
                                var oauthProvider = String( oauthBtn.getAttribute( 'data-provider' ) || '' ).trim();
                                if ( ! oauthProvider ) {
                                        return;
                                }
                                setProviderStatus( oauthProvider, 'Avvio del login web in corso…', '' );
                                setBtnLoading( oauthBtn, true, 'Connessione…' );
                                api( 'ai/oauth/start', { method: 'POST', body: { provider: oauthProvider } } ).then( function ( payload ) {
                                        var data = unwrap( payload ) || {};
                                        var authUrl = String( pick( data, 'auth_url' ) || '' );
                                        if ( ! authUrl ) {
                                                throw new Error( 'URL di autorizzazione mancante nella risposta del server.' );
                                        }
                                        window.location.href = authUrl;
                                } ).catch( function ( err ) {
                                        apiErrorToast( err, 'Connessione non riuscita' );
                                        setProviderStatus( oauthProvider, ( err && err.message ) ? err.message : 'Avvio del login non riuscito', 'err' );
                                        setBtnLoading( oauthBtn, false );
                                } );
                                return;
                        }

                        // --- Test connessione vivo (per Ollama è "Rileva Ollama") -
                        var testBtn = target.closest( '.nxa-prov-test' );
                        if ( testBtn ) {
                                var testProvider = String( testBtn.getAttribute( 'data-provider' ) || '' ).trim();
                                if ( ! testProvider ) {
                                        return;
                                }
                                var testBody = {};
                                if ( testProvider === 'custom' ) {
                                        // Test dell'URL base PRIMA del salvataggio: se il campo è
                                        // compilato ha la precedenza sul setting salvato.
                                        var baseField = qs( '#nxa-prov-base-custom', form );
                                        if ( baseField && String( baseField.value || '' ).trim() !== '' ) {
                                                testBody.base_url = String( baseField.value ).trim();
                                        }
                                }
                                setProviderStatus( testProvider, 'Test connessione in corso…', '' );
                                setBtnLoading( testBtn, true, 'Test…' );
                                api( 'ai/test/' + encodeURIComponent( testProvider ), { method: 'POST', body: testBody } ).then( function ( payload ) {
                                        var data = unwrap( payload ) || {};
                                        if ( data.ok === false ) {
                                                // Esito del test = dato (200 con ok:false): messaggio
                                                // già leggibile e tradotto dal server.
                                                setProviderStatus( testProvider, String( data.error || 'Test non riuscito' ), 'err' );
                                                return;
                                        }
                                        var count = num( data.models_count );
                                        var latency = num( data.latency_ms );
                                        var message = 'Connesso: ' + count + ' modelli, ' + latency + ' ms';
                                        var sample = Array.isArray( data.sample ) ? data.sample.filter( Boolean ).slice( 0, 3 ) : [];
                                        if ( sample.length ) {
                                                message += ' · es. ' + sample.join( ', ' );
                                        }
                                        if ( data.account && typeof data.account === 'object' ) {
                                                // OpenRouter: usage/limit dell'account (USD).
                                                message += ' · utilizzo account $' + num( data.account.usage ).toFixed( 2 );
                                                if ( data.account.limit !== undefined && data.account.limit !== null ) {
                                                        message += ' su $' + num( data.account.limit ).toFixed( 2 );
                                                }
                                        }
                                        setProviderStatus( testProvider, message, 'ok' );
                                } ).catch( function ( err ) {
                                        setProviderStatus( testProvider, ( err && err.message ) ? err.message : 'Test non riuscito', 'err' );
                                } ).then( function () {
                                        setBtnLoading( testBtn, false );
                                } );
                                return;
                        }

                        // --- Scollega provider (rimuove la chiave salvata) --------
                        var discBtn = target.closest( '.nxa-prov-disconnect' );
                        if ( discBtn ) {
                                var discProvider = String( discBtn.getAttribute( 'data-provider' ) || '' ).trim();
                                if ( ! discProvider ) {
                                        return;
                                }
                                if ( ! window.confirm( nxaT( 'Rimuovere la chiave salvata per questo provider? Gli agenti non potranno più usarlo finché non la riconfiguri o ti riconnetti.' ) ) ) {
                                        return;
                                }
                                setBtnLoading( discBtn, true, 'Scollego…' );
                                api( 'ai/disconnect/' + encodeURIComponent( discProvider ), { method: 'POST' } ).then( function ( payload ) {
                                        var data = unwrap( payload ) || {};
                                        if ( data.ok === false ) {
                                                throw new Error( String( data.error || 'Scollegamento non riuscito' ) );
                                        }
                                        var field = qs( '#nxa-prov-key-' + discProvider, form );
                                        if ( field ) {
                                                field.value = '';
                                        }
                                        setProviderDot( discProvider, false );
                                        setProviderStatus( discProvider, '', '' );
                                        discBtn.setAttribute( 'hidden', 'hidden' );
                                        toast( 'Chiave rimossa: il provider è scollegato.', 'success', 'Scollegamento' );
                                } ).catch( function ( err ) {
                                        apiErrorToast( err, 'Scollegamento non riuscito' );
                                } ).then( function () {
                                        setBtnLoading( discBtn, false );
                                } );
                        }
                } );
        }

        /**
         * Completamento del login web OAuth (callback OpenRouter, v1.1.10).
         *
         * OpenRouter rimanda sulla pagina Impostazioni AI con
         * ?nexus_oauth=1&state=…&code=… (il CODE viaggia in query): al load la
         * pagina POSTa {state, code} a /ai/oauth/callback, mostra il toast
         * dell'esito e pulisce la querystring con history.replaceState — il
         * codice monouso non resta in cronologia né viene reinviato a un refresh
         * (lo stato one-shot lato server lo rifiuterebbe comunque).
         */
        function maybeCompleteOauthCallback() {
                var search = String( window.location.search || '' );
                if ( search.indexOf( 'nexus_oauth=1' ) === -1 ) {
                        return;
                }

                var params;
                try {
                        params = new URLSearchParams( search );
                } catch ( err ) {
                        return;
                }

                var state = String( params.get( 'state' ) || '' ).trim();
                var code = String( params.get( 'code' ) || '' ).trim();
                var oauthError = String( params.get( 'error' ) || '' ).trim();
                cleanOauthQuery();

                if ( oauthError ) {
                        toast( 'OpenRouter non ha completato il login: ' + oauthError, 'error', 'Connessione OpenRouter' );
                        return;
                }
                if ( ! state || ! code ) {
                        toast( 'Ritorno dal login incompleto (state o code mancanti): riavvia la connessione da Impostazioni AI.', 'error', 'Connessione OpenRouter' );
                        return;
                }

                toast( 'Completamento della connessione in corso…', 'info', 'Connessione OpenRouter' );
                api( 'ai/oauth/callback', { method: 'POST', body: { state: state, code: code } } ).then( function () {
                        // La chiave è già salvata server-side: il campo password resta
                        // vuoto (password vuota = nessun cambio, contratto settings) e
                        // il badge passa a verde.
                        var field = qs( '#nxa-prov-key-openrouter' );
                        if ( field ) {
                                field.value = '';
                        }
                        setProviderDot( 'openrouter', true );
                        var discBtn = qs( '.nxa-prov-disconnect[data-provider="openrouter"]' );
                        if ( discBtn ) {
                                discBtn.removeAttribute( 'hidden' );
                        }
                        setProviderStatus( 'openrouter', 'Connesso via login web: la chiave è stata salvata.', 'ok' );
                        toast( 'OpenRouter connesso: la chiave è stata salvata.', 'success', 'Connessione OpenRouter' );
                } ).catch( function ( err ) {
                        apiErrorToast( err, 'Connessione OpenRouter non riuscita' );
                } );
        }

        /**
         * Rimuove i parametri OAuth dalla querystring (nexus_oauth/state/code/
         * error*) preservando gli altri (page=…). best-effort: senza
         * history.replaceState la pagina funziona comunque (lo stato è one-shot
         * lato server).
         */
        function cleanOauthQuery() {
                try {
                        var params = new URLSearchParams( window.location.search );
                        var drop = [ 'nexus_oauth', 'state', 'code', 'error', 'error_description', 'error_uri' ];
                        for ( var i = 0; i < drop.length; i++ ) {
                                params.delete( drop[ i ] );
                        }
                        var rest = params.toString();
                        window.history.replaceState( {}, document.title, window.location.pathname + ( rest ? '?' + rest : '' ) );
                } catch ( err ) {
                        // no-op: senza replaceState il flusso resta corretto.
                }
        }

        /* ==================================================================
         * 9-bis/2. PROVIDER PERSONALIZZATI ILLIMITATI (v1.1.10, F2b/WP11 —
         * pagina Impostazioni AI, sub-sezione del pannello "Provider AI per
         * agente"; contratto id/classi nel docblock di settings-ai.php).
         * QUALSIASI servizio compatibile OpenAI: storage lato server (option
         * 'nexus_custom_providers', via REST /ai/custom-providers), chiave MAI
         * in chiaro in pagina (la lista porta solo has_key). Il bottone Test di
         * ogni riga riusa il wizard WP10 (.nxa-prov-test → POST
         * /ai/test/cust_{id} con esito inline .nxa-prov-status).
         * ================================================================== */

        /** Preset 1-click: precompilano etichetta + URL base del form. */
        var CUSTOM_PROVIDER_PRESETS = {
                together:   { label: 'Together',   baseUrl: 'https://api.together.xyz/v1' },
                fireworks:  { label: 'Fireworks',  baseUrl: 'https://api.fireworks.ai/inference/v1' },
                perplexity: { label: 'Perplexity', baseUrl: 'https://api.perplexity.ai' },
                cerebras:   { label: 'Cerebras',   baseUrl: 'https://api.cerebras.ai/v1' },
                lmstudio:   { label: 'LM Studio',  baseUrl: 'http://localhost:1234/v1' },
                vllm:       { label: 'vLLM',       baseUrl: 'http://localhost:8000/v1' }
        };

        /** Ultima lista nota (GET /ai/custom-providers) per Modifica/Elimina. */
        var customProvidersCache = [];

        /** Riga con conferma di eliminazione inline attiva (id), oppure ''. */
        var customProvidersConfirmId = '';

        /** Trova una voce della cache per id (o null). */
        function findCustomProvider( id ) {
                for ( var i = 0; i < customProvidersCache.length; i++ ) {
                        if ( String( customProvidersCache[ i ].id || '' ) === String( id ) ) {
                                return customProvidersCache[ i ];
                        }
                }
                return null;
        }

        /**
         * Render della lista: una riga .nxa-custprov-row per provider con dot
         * stato chiave (riuso .nxa-prov-dot--on/--off), etichetta, URL base
         * (troncato via CSS, integro nel title), badge has_key e azioni. Con
         * customProvidersConfirmId la riga mostra la conferma inline al posto
         * delle azioni (niente window.confirm: conferma cancellabile in riga).
         */
        function renderCustomProviders() {
                var box = qs( '#nxa-custprov-list' );
                if ( ! box ) {
                        return;
                }
                if ( ! customProvidersCache.length ) {
                        box.innerHTML = '';
                        return;
                }
                box.innerHTML = customProvidersCache.map( function ( p ) {
                        var id = String( p.id || '' );
                        var hasKey = !! p.has_key;
                        var actions;
                        if ( customProvidersConfirmId === id ) {
                                actions = '<span class="nxa-custprov-confirm">' +
                                        esc( 'Eliminare definitivamente? Gli agenti che lo usavano mostreranno un errore finché non riassegni un provider.' ) +
                                        '<button type="button" class="button nxa-custprov-del-yes" data-id="' + esc( id ) + '">' + esc( 'Sì, elimina' ) + '</button>' +
                                        '<button type="button" class="button nxa-custprov-del-no" data-id="' + esc( id ) + '">' + esc( 'No' ) + '</button>' +
                                        '</span>';
                        } else {
                                actions = '<button type="button" class="button nxa-prov-test" data-provider="' + esc( id ) + '">' + esc( 'Test' ) + '</button>' +
                                        '<button type="button" class="button nxa-custprov-edit" data-id="' + esc( id ) + '">' + esc( 'Modifica' ) + '</button>' +
                                        '<button type="button" class="button-link nxa-custprov-del" data-id="' + esc( id ) + '">' + esc( 'Elimina' ) + '</button>';
                        }
                        return '<div class="nxa-custprov-row" data-id="' + esc( id ) + '">' +
                                '<span class="nxa-prov-dot ' + ( hasKey ? 'nxa-prov-dot--on' : 'nxa-prov-dot--off' ) + '" title="' + esc( hasKey ? 'Chiave salvata' : 'Nessuna chiave' ) + '" aria-hidden="true"></span>' +
                                '<span class="nxa-custprov-label">' + esc( String( p.label || id ) ) + '</span>' +
                                '<span class="nxa-custprov-url" title="' + esc( String( p.base_url || '' ) ) + '">' + esc( String( p.base_url || '' ) ) + '</span>' +
                                '<span class="nxa-custprov-badge">' + esc( hasKey ? 'Chiave salvata' : 'Nessuna chiave' ) + '</span>' +
                                '<span class="nxa-custprov-actions">' + actions + '</span>' +
                                '<p class="description nxa-prov-status" data-nxa-status="' + esc( id ) + '" aria-live="polite"></p>' +
                                '</div>';
                } ).join( '' );
        }

        /** Esito operazioni del form (verde/rosso, aria-live). */
        function setCustomFormStatus( message, kind ) {
                var node = qs( '#nxa-custprov-status' );
                if ( ! node ) {
                        return;
                }
                node.textContent = message || '';
                node.classList.remove( 'nxa-prov-status--ok', 'nxa-prov-status--err' );
                if ( kind === 'ok' ) {
                        node.classList.add( 'nxa-prov-status--ok' );
                } else if ( kind === 'err' ) {
                        node.classList.add( 'nxa-prov-status--err' );
                }
        }

        /** Apre il form per CREARE (entry null) o MODIFICARE una voce esistente. */
        function openCustomProviderForm( entry ) {
                var form = qs( '#nxa-custprov-form' );
                if ( ! form ) {
                        return;
                }
                var editing = !!( entry && entry.id );
                var idField = qs( '#nxa-custprov-id' );
                var labelField = qs( '#nxa-custprov-label' );
                var urlField = qs( '#nxa-custprov-base-url' );
                var keyField = qs( '#nxa-custprov-api-key' );
                if ( idField ) {
                        idField.value = editing ? String( entry.id ) : '';
                }
                if ( labelField ) {
                        labelField.value = editing ? String( entry.label || '' ) : '';
                }
                if ( urlField ) {
                        urlField.value = editing ? String( entry.base_url || '' ) : '';
                }
                if ( keyField ) {
                        keyField.value = '';
                        keyField.placeholder = editing ? 'invariata se lasciata vuota' : '';
                }
                var preset = qs( '#nxa-custprov-preset' );
                if ( preset ) {
                        preset.value = '';
                }
                var saveBtn = qs( '#nxa-custprov-save' );
                if ( saveBtn ) {
                        saveBtn.removeAttribute( 'data-nxa-label' );
                        saveBtn.innerHTML = editing ? 'Aggiorna provider' : 'Salva provider';
                }
                var cancelBtn = qs( '#nxa-custprov-cancel' );
                if ( cancelBtn ) {
                        cancelBtn.hidden = ! editing;
                }
                form.removeAttribute( 'hidden' );
                setCustomFormStatus( editing ? 'Modifica in corso: la chiave salvata resta invariata se lasci vuota.' : '', '' );
                if ( labelField ) {
                        labelField.focus();
                }
        }

        /** Chiude il form e azzera i campi (niente attributi name: solo reset JS). */
        function closeCustomProviderForm() {
                var form = qs( '#nxa-custprov-form' );
                if ( form ) {
                        form.setAttribute( 'hidden', 'hidden' );
                }
                var fields = [ 'nxa-custprov-id', 'nxa-custprov-label', 'nxa-custprov-base-url', 'nxa-custprov-api-key' ];
                for ( var i = 0; i < fields.length; i++ ) {
                        var field = qs( '#' + fields[ i ] );
                        if ( field ) {
                                field.value = '';
                        }
                }
                var preset = qs( '#nxa-custprov-preset' );
                if ( preset ) {
                        preset.value = '';
                }
                setCustomFormStatus( '', '' );
        }

        /** Carica la lista dal server e la renderizza (al bind e dopo le CRUD). */
        function loadCustomProviders() {
                var box = qs( '#nxa-custprov-list' );
                if ( ! box ) {
                        return Promise.resolve();
                }
                return api( 'ai/custom-providers' ).then( function ( payload ) {
                        var data = unwrap( payload ) || {};
                        var list = Array.isArray( data.providers ) ? data.providers : getList( payload, [ 'providers' ] );
                        customProvidersCache = Array.isArray( list ) ? list : [];
                        customProvidersConfirmId = '';
                        renderCustomProviders();
                } ).catch( function ( err ) {
                        window.console.warn( '[Nexus] custom providers:', err );
                        toast( ( err && err.message ) ? err.message : 'Caricamento dei provider personalizzati non riuscito.', 'error', 'Provider personalizzati' );
                } );
        }

        /**
         * Salvataggio (create se id nascosto vuoto, update altrimenti). La
         * chiave è inviata SOLO se compilata: vuota = nessun cambio (pattern
         * segreti, rispettato anche dal server).
         */
        function saveCustomProvider( saveBtn ) {
                var idField = qs( '#nxa-custprov-id' );
                var labelField = qs( '#nxa-custprov-label' );
                var urlField = qs( '#nxa-custprov-base-url' );
                var keyField = qs( '#nxa-custprov-api-key' );
                var idValue = idField ? String( idField.value || '' ).trim() : '';
                var label = labelField ? String( labelField.value || '' ).trim() : '';
                var baseUrl = urlField ? String( urlField.value || '' ).trim() : '';
                var apiKey = keyField ? String( keyField.value || '' ) : '';

                if ( ! label || ! baseUrl ) {
                        setCustomFormStatus( 'Etichetta e URL base sono obbligatori.', 'err' );
                        toast( 'Etichetta e URL base sono obbligatori per il provider personalizzato.', 'error', 'Provider personalizzati' );
                        return;
                }

                var body = { label: label, base_url: baseUrl };
                if ( idValue ) {
                        body.id = idValue;
                }
                if ( String( apiKey ).trim() !== '' ) {
                        body.api_key = apiKey;
                }

                setBtnLoading( saveBtn, true, 'Salvataggio…' );
                api( 'ai/custom-providers', { method: 'POST', body: body } ).then( function ( payload ) {
                        var data = unwrap( payload ) || {};
                        if ( data.ok === false ) {
                                throw new Error( String( data.error || 'Salvataggio non riuscito' ) );
                        }
                        closeCustomProviderForm();
                        toast( 'Provider personalizzato salvato: ora è selezionabile dagli agenti.', 'success', 'Provider personalizzati' );
                        return loadCustomProviders();
                } ).catch( function ( err ) {
                        apiErrorToast( err, 'Provider personalizzati' );
                        setCustomFormStatus( ( err && err.message ) ? err.message : 'Salvataggio non riuscito', 'err' );
                } ).then( function () {
                        setBtnLoading( saveBtn, false );
                } );
        }

        /** Eliminazione (conferma già data INLINE nella riga). */
        function deleteCustomProvider( id, deleteBtn ) {
                setBtnLoading( deleteBtn, true, 'Elimino…' );
                api( 'ai/custom-providers/' + encodeURIComponent( id ), { method: 'DELETE' } ).then( function () {
                        var idField = qs( '#nxa-custprov-id' );
                        if ( idField && String( idField.value || '' ) === String( id ) ) {
                                closeCustomProviderForm();
                        }
                        toast( 'Provider personalizzato eliminato.', 'success', 'Provider personalizzati' );
                        return loadCustomProviders();
                } ).catch( function ( err ) {
                        apiErrorToast( err, 'Provider personalizzati' );
                        return loadCustomProviders();
                } ).then( function () {
                        setBtnLoading( deleteBtn, false );
                } );
        }

        /**
         * Bind della sub-sezione (chiamato da bindSettings SOLO se la pagina ha
         * #nxa-custprov-list): delega click per add/edit/delete + conferma
         * inline + Salva/Annulla, change sul preset 1-click, caricamento lista.
         * I bottoni Test NON sono gestiti qui: portano .nxa-prov-test e il
         * wizard WP10 (bindProviderActions) li gestisce con la stessa POST
         * /ai/test/cust_{id} scrivendo l'esito inline nella riga.
         */
        function bindCustomProviders( form ) {
                var box = qs( '#nxa-custprov-list' );
                if ( ! box || ! form ) {
                        return;
                }

                form.addEventListener( 'click', function ( ev ) {
                        var target = ev.target;
                        if ( ! target || ! target.closest ) {
                                return;
                        }

                        var addBtn = target.closest( '#nxa-custprov-add' );
                        if ( addBtn ) {
                                customProvidersConfirmId = '';
                                renderCustomProviders();
                                openCustomProviderForm( null );
                                return;
                        }

                        var editBtn = target.closest( '.nxa-custprov-edit' );
                        if ( editBtn ) {
                                var editId = String( editBtn.getAttribute( 'data-id' ) || '' );
                                customProvidersConfirmId = '';
                                renderCustomProviders();
                                openCustomProviderForm( findCustomProvider( editId ) );
                                return;
                        }

                        var delBtn = target.closest( '.nxa-custprov-del' );
                        if ( delBtn ) {
                                // Conferma INLINE: la riga mostra "Sì, elimina / No".
                                customProvidersConfirmId = String( delBtn.getAttribute( 'data-id' ) || '' );
                                renderCustomProviders();
                                return;
                        }

                        var delYes = target.closest( '.nxa-custprov-del-yes' );
                        if ( delYes ) {
                                deleteCustomProvider( String( delYes.getAttribute( 'data-id' ) || '' ), delYes );
                                return;
                        }

                        var delNo = target.closest( '.nxa-custprov-del-no' );
                        if ( delNo ) {
                                customProvidersConfirmId = '';
                                renderCustomProviders();
                                return;
                        }

                        if ( target.closest( '#nxa-custprov-save' ) ) {
                                saveCustomProvider( qs( '#nxa-custprov-save' ) );
                                return;
                        }

                        if ( target.closest( '#nxa-custprov-cancel' ) ) {
                                closeCustomProviderForm();
                        }
                } );

                var presetSelect = qs( '#nxa-custprov-preset' );
                if ( presetSelect ) {
                        presetSelect.addEventListener( 'change', function () {
                                var preset = CUSTOM_PROVIDER_PRESETS[ String( presetSelect.value || '' ) ];
                                if ( ! preset ) {
                                        return;
                                }
                                var labelField = qs( '#nxa-custprov-label' );
                                var urlField = qs( '#nxa-custprov-base-url' );
                                if ( labelField ) {
                                        labelField.value = preset.label;
                                }
                                if ( urlField ) {
                                        urlField.value = preset.baseUrl;
                                }
                        } );
                }

                loadCustomProviders();
        }

        /* ==================================================================
         * 9-ter. CATEGORIE NATIVE (v1.1.0)
         * ================================================================== */

        /**
         * v1.1.0: cache client delle categorie WP native (GET /wp/categories,
         * risposta { categories: [ { id, name, slug, count } ] }). La fetch
         * parte SOLO quando si apre l'editor agenti: nessun fetch automatico
         * fuori dal contesto agenti. Cache valida 60 secondi; errore →
         * datalist vuota e la modal funziona comunque (input libero).
         */
        var wpCategoriesCache = { list: null, at: 0, ttl: 60000 };

        /** v1.1.0: svuota e riempie #nxa-agent-category-list con opzioni value="{name}". */
        function fillCategoryDatalist() {
                var list = qs( '#nxa-agent-category-list' );
                if ( ! list ) {
                        return;
                }
                list.innerHTML = '';
                var cats = wpCategoriesCache.list || [];
                for ( var i = 0; i < cats.length; i++ ) {
                        var name = String( pick( cats[ i ], 'name' ) || '' ).trim();
                        if ( ! name ) {
                                continue;
                        }
                        var option = document.createElement( 'option' );
                        option.setAttribute( 'value', name );
                        list.appendChild( option );
                }
        }

        /**
         * v1.1.0: GET /wp/categories con cache 60s, chiamata da openAgentEditor.
         * Datalist assente (markup T-02 non ancora atterrato o pagina senza
         * modal agenti) → nessun fetch, exit silenzioso. Fetch fallita →
         * warn in console e datalist resta vuota: l'editor resta pienamente
         * usabile (l'utente digita la categoria a mano).
         */
        function loadWpCategories() {
                var list = qs( '#nxa-agent-category-list' );
                if ( ! list ) {
                        return Promise.resolve();
                }
                var fresh = wpCategoriesCache.list && ( Date.now() - wpCategoriesCache.at ) < wpCategoriesCache.ttl;
                if ( fresh ) {
                        fillCategoryDatalist();
                        return Promise.resolve();
                }
                return api( 'wp/categories' ).then( function ( payload ) {
                        wpCategoriesCache.list = getList( payload, [ 'categories', 'items', 'list' ] );
                        wpCategoriesCache.at = Date.now();
                        fillCategoryDatalist();
                } ).catch( function ( err ) {
                        window.console.warn( '[Nexus] categorie native:', err );
                } );
        }

        /* ==================================================================
         * 10. CODA DI APPROVAZIONE
         * ================================================================== */

        /**
         * v1.1.4: forza la revisione capo redattore su un articolo
         * (POST /chief/run/{id}, route T-05). chiefStatus è il valore
         * chief_status al momento del render: se non vuoto (articolo già
         * verificato) chiede conferma. onDone richiamata a OK (reload coda
         * o riapertura dettaglio); errori → toast, mai crash.
         */
        function runChiefReview( id, chiefStatus, btn, onDone ) {
                if ( num( id ) <= 0 ) {
                        return;
                }
                if ( String( chiefStatus || '' ) !== '' && ! window.confirm( nxaT( 'Questo articolo è già passato dal capo redattore. Eseguire una nuova verifica?' ) ) ) {
                        return;
                }
                setBtnLoading( btn, true, 'Verifico…' );
                api( 'chief/run/' + num( id ), { method: 'POST' } ).then( function ( payload ) {
                        var data = unwrap( payload ) || {};
                        var outcome = chiefStatusLabel( pick( data, 'chief_status', 'chiefStatus' ) );
                        toast( outcome ? outcome : 'Revisione capo redattore completata.', 'success', 'Capo redattore' );
                        return typeof onDone === 'function' ? onDone() : null;
                } ).catch( function ( err ) {
                        apiErrorToast( err, 'Revisione capo redattore non riuscita' );
                        setBtnLoading( btn, false );
                } ).then( function () {
                        setBtnLoading( btn, false );
                } );
        }

        function currentQueueFilter() {
                var select = qs( '#nxa-queue-filter' );
                if ( ! select ) {
                        return '';
                }
                var value = String( select.value || '' ).trim();
                return ( value && value !== 'all' && value !== 'any' ) ? value : '';
        }

        function queueItemHtml( a ) {
                var id = num( pick( a, 'id' ) );
                var status = String( pick( a, 'status' ) || 'draft' );
                var sourceUrl = pick( a, 'source_url', 'sourceUrl' ) || '';
                var sourceName = pick( a, 'source_name', 'sourceName' ) || '';
                var summary = pick( a, 'summary' ) || stripTags( pick( a, 'content' ) ).slice( 0, 200 );

                var actions = '<div class="nxa-btn-row" data-actions-for="' + id + '">' +
                        '<button type="button" class="button button-primary nxa-approve" data-id="' + id + '">Approva</button>' +
                        '<button type="button" class="button nxa-reject" data-id="' + id + '">Rifiuta</button>' +
                        // La pubblicazione di un singolo articolo passerà dalla pipeline editoriale:
                        // inviamo article_id al run della pipeline; se il backend non supporta
                        // ancora la pubblicazione puntuale risponderà 400 e mostriamo un toast chiaro.
                        ( status === 'approved' ? '<button type="button" class="button nxa-publish-one" data-id="' + id + '">Pubblica</button>' : '' ) +
                        // v1.1.4: revisione capo redattore forzata (solo pending_approval);
                        // data-chief-status alimenta la conferma extra se già verificato.
                        ( status === 'pending_approval' ? chiefRunButtonHtml( chiefStatusOf( a ), 'data-id="' + id + '"' ) : '' ) +
                        '<button type="button" class="button-link nxa-detail" data-id="' + id + '">Dettagli</button>' +
                '</div>';

                return '<article class="nxa-queue-item" data-article-id="' + id + '">' +
                        '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px">' +
                                categoryChip( pick( a, 'category' ) ) +
                                badge( status ) +
                                chiefBadge( a ) +
                                scoreBadge( pick( a, 'quality_score', 'qualityScore' ) ) +
                                wpPostBadge( a ) +
                                wpViewLink( a ) +
                                '<span style="font-size:12px;color:var(--nxa-muted)">✍️ ' + esc( articleAgentName( a ) ) + '</span>' +
                                '<span style="font-size:11px;color:var(--nxa-muted);margin-left:auto">' + esc( timeAgoIt( pick( a, 'created_at', 'createdAt' ) ) ) + '</span>' +
                        '</div>' +
                        '<h3 style="margin:0 0 4px;font-size:15px;line-height:1.35">' +
                                '<a href="#" class="nxa-detail" data-id="' + id + '" style="text-decoration:none;color:inherit">' + esc( pick( a, 'title' ) || '(senza titolo)' ) + '</a>' +
                        '</h3>' +
                        ( pick( a, 'subtitle', 'subTitle' ) ? '<p style="margin:0 0 6px;font-size:13px;color:var(--nxa-muted)">' + esc( pick( a, 'subtitle', 'subTitle' ) ) + '</p>' : '' ) +
                        ( summary ? '<p style="margin:0 0 8px;font-size:12.5px;color:var(--nxa-muted);line-height:1.55">' + excerpt( summary, 220 ) + '</p>' : '' ) +
                        ( sourceUrl ? '<p style="margin:0 0 4px;font-size:12px">🔗 <a href="' + esc( sourceUrl ) + '" target="_blank" rel="noopener noreferrer">' + esc( sourceName || sourceUrl ) + ' ↗</a></p>' : '' ) +
                        actions +
                '</article>';
        }

        function loadQueueList() {
                var list = qs( '#nxa-queue-list' );
                if ( ! list ) {
                        return Promise.resolve();
                }
                showSkeleton( list, 4 );
                list.setAttribute( 'aria-busy', 'true' );
                var status = currentQueueFilter();
                var query = '?per_page=20&page=1' + ( status ? '&status=' + encodeURIComponent( status ) : '' );
                return api( 'articles' + query ).then( function ( payload ) {
                        var items = getList( payload, [ 'articles', 'items' ] );
                        list.innerHTML = items.length
                                ? items.map( queueItemHtml ).join( '' )
                                : emptyState( '🗂️', 'Nessun articolo in coda',
                                        status ? 'Nessun articolo con lo stato selezionato.' : 'Esegui la raccolta per generare nuovi articoli.' );
                        // v1.1.7: aria-busy non deve restare "true" per sempre (rimasto dal markup
                        // server-side) e il contatore #nxa-queue-count non veniva mai popolato.
                        list.removeAttribute( 'aria-busy' );
                        var count = qs( '#nxa-queue-count' );
                        if ( count ) {
                                var unwrapped = unwrap( payload );
                                var total = ( unwrapped && typeof unwrapped.total === 'number' ) ? unwrapped.total : items.length;
                                count.textContent = ( 1 === total ) ? '1 articolo in coda' : total + ' articoli in coda';
                        }
                } ).catch( function ( err ) {
                        list.removeAttribute( 'aria-busy' );
                        list.innerHTML = emptyState( '⚠️', 'Impossibile caricare la coda', err.message || '' );
                        window.console.warn( '[Nexus] coda:', err );
                } );
        }

        function inlineRejectForm( id ) {
                return '<div class="nxa-note-row" data-reject-for="' + id + '">' +
                        '<input type="text" class="nxa-note-input" placeholder="Nota di rifiuto (opzionale)" maxlength="300" />' +
                        '<button type="button" class="button button-primary nxa-reject-confirm" data-id="' + id + '">Conferma rifiuto</button>' +
                        '<button type="button" class="button-link nxa-reject-cancel">Annulla</button>' +
                '</div>';
        }

        function bindQueue() {
                var list = qs( '#nxa-queue-list' );

                if ( list ) {
                        list.addEventListener( 'click', function ( ev ) {
                                var target = ev.target;

                                // dettaglio (titolo o bottone)
                                var detail = target.closest ? target.closest( '.nxa-detail' ) : null;
                                if ( detail ) {
                                        ev.preventDefault();
                                        openArticleModal( detail.getAttribute( 'data-id' ) );
                                        return;
                                }

                                // approva
                                var approve = target.closest ? target.closest( '.nxa-approve' ) : null;
                                if ( approve ) {
                                        var aId = num( approve.getAttribute( 'data-id' ) );
                                        setBtnLoading( approve, true, 'Approvo…' );
                                        api( 'articles/' + aId + '/approve', { method: 'POST', body: { note: '' } } ).then( function () {
                                                toast( 'Articolo approvato.', 'success', 'Coda aggiornata' );
                                                return loadQueueList();
                                        } ).catch( function ( err ) {
                                                apiErrorToast( err, 'Approvazione non riuscita' );
                                                setBtnLoading( approve, false );
                                        } );
                                        return;
                                }

                                // rifiuta → form nota inline
                                var reject = target.closest ? target.closest( '.nxa-reject' ) : null;
                                if ( reject ) {
                                        var rId = num( reject.getAttribute( 'data-id' ) );
                                        var actionsRow = qs( '[data-actions-for="' + rId + '"]', list );
                                        if ( actionsRow ) {
                                                actionsRow.insertAdjacentHTML( 'afterend', inlineRejectForm( rId ) );
                                                actionsRow.hidden = true;
                                                var input = qs( '[data-reject-for="' + rId + '"] .nxa-note-input', list );
                                                if ( input ) {
                                                        input.focus();
                                                }
                                        }
                                        return;
                                }

                                // conferma rifiuto con nota
                                var confirmBtn = target.closest ? target.closest( '.nxa-reject-confirm' ) : null;
                                if ( confirmBtn ) {
                                        var cId = num( confirmBtn.getAttribute( 'data-id' ) );
                                        var noteInput = qs( '[data-reject-for="' + cId + '"] .nxa-note-input', list );
                                        var note = noteInput ? String( noteInput.value || '' ).trim() : '';
                                        setBtnLoading( confirmBtn, true, 'Rifiuto…' );
                                        api( 'articles/' + cId + '/reject', { method: 'POST', body: { note: note } } ).then( function () {
                                                toast( 'Articolo rifiutato.', 'info', 'Coda aggiornata' );
                                                return loadQueueList();
                                        } ).catch( function ( err ) {
                                                apiErrorToast( err, 'Rifiuto non riuscito' );
                                                setBtnLoading( confirmBtn, false );
                                        } );
                                        return;
                                }

                                // annulla rifiuto
                                if ( target.classList && target.classList.contains( 'nxa-reject-cancel' ) ) {
                                        var row = target.closest( '[data-reject-for]' );
                                        if ( row && row.previousElementSibling ) {
                                                row.previousElementSibling.hidden = false;
                                        }
                                        if ( row && row.parentNode ) {
                                                row.parentNode.removeChild( row );
                                        }
                                        return;
                                }

                                // pubblica singolo (solo articoli approved)
                                var publish = target.closest ? target.closest( '.nxa-publish-one' ) : null;
                                if ( publish ) {
                                        var pId = num( publish.getAttribute( 'data-id' ) );
                                        setBtnLoading( publish, true, 'Pubblico…' );
                                        api( 'pipeline/publish', { method: 'POST', body: { article_id: pId } } ).then( function ( payload ) {
                                                var d = unwrap( payload ) || {};
                                                toast( d.already
                                                        ? 'Articolo già pubblicato in precedenza.'
                                                        : 'Articolo pubblicato: log multi-piattaforma generati.',
                                                        'success', 'Pubblicazione' );
                                                return loadQueueList();
                                        } ).catch( function ( err ) {
                                                apiErrorToast( err, 'Pubblicazione non riuscita' );
                                                setBtnLoading( publish, false );
                                        } );
                                        return;
                                }

                                // v1.1.4: revisione capo redattore (POST /chief/run/{id});
                                // conferma extra se l'articolo è già stato verificato.
                                var chiefRun = target.closest ? target.closest( '.nxa-chief-run' ) : null;
                                if ( chiefRun ) {
                                        runChiefReview(
                                                num( chiefRun.getAttribute( 'data-id' ) ),
                                                chiefRun.getAttribute( 'data-chief-status' ) || '',
                                                chiefRun,
                                                loadQueueList
                                        );
                                        return;
                                }
                        } );
                }

                var filter = qs( '#nxa-queue-filter' );
                if ( filter ) {
                        filter.addEventListener( 'change', loadQueueList );
                }

                qsa( '.nxa-queue-refresh' ).forEach( function ( btn ) {
                        btn.addEventListener( 'click', function () {
                                setBtnLoading( btn, true, 'Aggiorno…' );
                                loadQueueList().then( function () {
                                        setBtnLoading( btn, false );
                                } );
                        } );
                } );
        }

        /* ==================================================================
         * 11. ATTIVITÀ
         * ================================================================== */

        function loadActivity() {
                var container = qs( '#nxa-activity-list' );
                if ( ! container ) {
                        return Promise.resolve();
                }
                showSkeleton( container, 5 );
                return api( 'activity?limit=50' ).then( function ( payload ) {
                        var items = getList( payload, [ 'activity', 'logs', 'items' ] );
                        container.innerHTML = items.length
                                ? items.map( activityItemHtml ).join( '' )
                                : emptyState( '📰', 'Nessuna attività registrata', 'L’attività degli agenti apparirà qui in tempo reale.' );
                } ).catch( function ( err ) {
                        container.innerHTML = emptyState( '⚠️', 'Impossibile caricare l’attività', err.message || '' );
                        window.console.warn( '[Nexus] attività:', err );
                } );
        }

        /* ==================================================================
         * 12. IMPOSTAZIONI
         * ================================================================== */

        function collectSettings( form ) {
                var payload = {};
                var i;

                // campi testuali / select per nome (v1.0.1: + post_status, image_model, image_size)
                // (v1.0.2: + credenziali social, newsletter_recipients, indexnow_key)
                // (v1.0.3: + redazione intelligente: ai_model_cheap, budget_action,
                //  translation_langs; factcheck_min_score e monthly_budget_usd sono
                //  anche nel blocco numerico sotto per essere inviati come numeri)
                // (v1.0.5: + admin_language, sezione "Lingua e API pubblica")
                // (v1.0.6: + admin_theme, sezione "Interfaccia" — select #nxa-admin-theme)
                // (v1.1.4: + tools_search_provider, mcp_servers_text, prov_base_custom
                //  — sezione "Provider AI per agente" e "Strumenti di ricerca"; le chiavi
                //  prov_key_* e tools_search_key seguono il pattern solo-se-compilata
                //  del blocco ai_api_key in fondo a questa funzione)
                // (v1.1.5: - site_name, site_tagline — la sezione "Identità del sito" è
                //  stata rimossa: nome e tagline sono nativi WP, il magazine li legge da
                //  bloginfo; la whitelist REST li conserva per retrocompatibilità)
                var simple = [ 'mode', 'ai_base_url', 'ai_model', 'post_status', 'image_model', 'image_size', 'telegram_bot_token', 'telegram_chat_id', 'discord_webhook_url', 'x_api_key', 'x_api_secret', 'x_access_token', 'x_access_secret', 'linkedin_access_token', 'linkedin_author_urn', 'newsletter_recipients', 'indexnow_key', 'ai_model_cheap', 'factcheck_min_score', 'monthly_budget_usd', 'budget_action', 'translation_langs', 'admin_language', 'admin_theme', 'tools_search_provider', 'mcp_servers_text', 'prov_base_custom', 'global_rss_sources', 'gsc_oauth_client_id', 'gsc_site_url', 'ga4_property_id', 'news_sitemap_name', 'notify_events' ]; // v1.1.11 (R1): + global_rss_sources (fonti RSS globali, una URL per riga) — v1.1.12 (S8): + 5 chiavi moduli feedback (GSC/GA4, news sitemap, eventi notifiche)
                for ( i = 0; i < simple.length; i++ ) {
                        var field = form.elements[ simple[ i ] ];
                        if ( field && field.value !== undefined ) {
                                payload[ simple[ i ] ] = String( field.value || '' ).trim();
                        }
                }

                // numerici (v1.0.4: + scala & pro: queue_batch_size, queue_max_attempts)
                // (v1.1.4: + chief_max_rounds, chief_id, tools_max_results,
                //  tools_read_max_chars, tools_max_rounds, tools_timeout)
                var numbers = [ 'collect_interval', 'max_articles_per_day', 'auto_approve_score', 'factcheck_min_score', 'monthly_budget_usd', 'queue_batch_size', 'queue_max_attempts', 'chief_max_rounds', 'chief_id', 'tools_max_results', 'tools_read_max_chars', 'tools_max_rounds', 'tools_timeout', 'dedup_similar_threshold', 'interlink_max', 'learn_window_days', 'evergreen_refresh_days', 'evergreen_max_posts' ]; // v1.1.12 (S8): + 5 numerici moduli feedback (dedup, interlink, learning, evergreen)
                for ( i = 0; i < numbers.length; i++ ) {
                        var numField = form.elements[ numbers[ i ] ];
                        if ( numField && numField.value !== undefined && String( numField.value ).trim() !== '' ) {
                                payload[ numbers[ i ] ] = num( numField.value );
                        }
                }

                // checkbox automazioni: presenti → 1, assenti → 0
                var autos = [ 'auto_collect', 'auto_evaluate', 'auto_rewrite', 'auto_publish' ];
                for ( i = 0; i < autos.length; i++ ) {
                        var boxes = qsa( 'input[name="' + autos[ i ] + '"]', form );
                        if ( boxes.length ) {
                                payload[ autos[ i ] ] = boxes.some( function ( b ) { return b.checked; } ) ? 1 : 0;
                        }
                }

                // checkbox pubblicazione WordPress & SEO (v1.0.1): presenti → 1, assenti → 0
                // (se i campi non sono ancora in settings.php, qsa torna [] e il payload li omette)
                var wpFlags = [ 'enable_wp_posts', 'enable_ai_images', 'enable_seo', 'seo_generate' ];
                for ( i = 0; i < wpFlags.length; i++ ) {
                        var flagBoxes = qsa( 'input[name="' + wpFlags[ i ] + '"]', form );
                        if ( flagBoxes.length ) {
                                payload[ wpFlags[ i ] ] = flagBoxes.some( function ( b ) { return b.checked; } ) ? 1 : 0;
                        }
                }

                // checkbox distribuzione sociale / newsletter / indicizzazione (v1.0.2): presenti → 1, assenti → 0
                // (se i campi non sono ancora in settings.php, qsa torna [] e il payload li omette)
                var distribFlags = [ 'enable_telegram', 'enable_discord', 'enable_x', 'enable_linkedin', 'enable_newsletter', 'enable_digest', 'enable_indexnow', 'enable_websub' ];
                for ( i = 0; i < distribFlags.length; i++ ) {
                        var distribBoxes = qsa( 'input[name="' + distribFlags[ i ] + '"]', form );
                        if ( distribBoxes.length ) {
                                payload[ distribFlags[ i ] ] = distribBoxes.some( function ( b ) { return b.checked; } ) ? 1 : 0;
                        }
                }

                // checkbox redazione intelligente (v1.0.3): presenti → 1, assenti → 0
                // (v1.1.2: + enable_autotranslate_posts, post tradotti nativi;
                // stesso pattern dei blocchi wpFlags/distribFlags sopra)
                var intelFlags = [ 'enable_factcheck', 'enable_translations', 'enable_autotranslate_posts' ];
                for ( i = 0; i < intelFlags.length; i++ ) {
                        var intelBoxes = qsa( 'input[name="' + intelFlags[ i ] + '"]', form );
                        if ( intelBoxes.length ) {
                                payload[ intelFlags[ i ] ] = intelBoxes.some( function ( b ) { return b.checked; } ) ? 1 : 0;
                        }
                }

                // checkbox scala & pro (v1.0.4): presenti → 1, assenti → 0
                // (v1.0.5: + enable_public_api, sezione "Lingua e API pubblica")
                // (stesso pattern dei blocchi wpFlags/distribFlags/intelFlags sopra)
                var scalaFlags = [ 'enable_queue', 'enable_public_api' ];
                for ( i = 0; i < scalaFlags.length; i++ ) {
                        var scalaBoxes = qsa( 'input[name="' + scalaFlags[ i ] + '"]', form );
                        if ( scalaBoxes.length ) {
                                payload[ scalaFlags[ i ] ] = scalaBoxes.some( function ( b ) { return b.checked; } ) ? 1 : 0;
                        }
                }

                // checkbox capo redattore + strumenti di ricerca (v1.1.4): presenti → 1,
                // assenti → 0 (i campi name arrivano con settings.php T-07; se non sono
                // ancora nel markup qsa torna [] e il payload li omette — stesso pattern
                // dei blocchi wpFlags/distribFlags/intelFlags/scalaFlags sopra)
                var chiefFlags = [ 'chief_enabled', 'chief_auto_publish', 'tools_enabled', 'tools_read_page' ];
                for ( i = 0; i < chiefFlags.length; i++ ) {
                        var chiefBoxes = qsa( 'input[name="' + chiefFlags[ i ] + '"]', form );
                        if ( chiefBoxes.length ) {
                                payload[ chiefFlags[ i ] ] = chiefBoxes.some( function ( b ) { return b.checked; } ) ? 1 : 0;
                        }
                }

                // piattaforme social: checkbox multiple name="social_platforms[]" → CSV
                var platformInputs = qsa( 'input[name="social_platforms[]"]', form );
                var platforms = platformInputs
                        .filter( function ( b ) { return b.checked; } )
                        .map( function ( b ) { return String( b.value || '' ).trim(); } )
                        .filter( Boolean );
                if ( platformInputs.length ) {
                        payload.social_platforms = platforms.join( ',' );
                }

                // API key: inviata solo se compilata (evita di cancellarla per sbaglio)
                var key = form.elements.ai_api_key;
                if ( key && String( key.value || '' ).trim() !== '' ) {
                        payload.ai_api_key = String( key.value ).trim();
                }

                // (v1.1.4) chiavi per-provider + tools_search_key: stesso pattern
                // solo-se-compilata di ai_api_key (password vuota = nessun cambio).
                // v1.1.10 (WP10/G2): aggiunta prov_key_ollama (riga presente nella
                // pagina Impostazioni AI dal wizard provider).
                var secretNames = [ 'prov_key_openrouter', 'prov_key_openai', 'prov_key_anthropic', 'prov_key_google', 'prov_key_groq', 'prov_key_deepseek', 'prov_key_mistral', 'prov_key_xai', 'prov_key_custom', 'prov_key_ollama', 'tools_search_key', 'gsc_oauth_client_secret', 'notify_webhook_url' ]; // v1.1.12 (S8): + segreto OAuth Google + webhook notifiche (stesso pattern solo-se-compilata)
                for ( i = 0; i < secretNames.length; i++ ) {
                        var secretField = form.elements[ secretNames[ i ] ];
                        if ( secretField && String( secretField.value || '' ).trim() !== '' ) {
                                payload[ secretNames[ i ] ] = String( secretField.value ).trim();
                        }
                }

                // v1.1.10 (WP10): toggle endpoint AI su IP privati/localhost (page
                // Impostazioni AI, sezione provider): presente → 1, assente → 0.
                // v1.1.11 (R5): anche models_fetch_enabled (guardia fetch catalogo
                // modelli, sezione "Configurazione AI"): deselezionato → 0.
                var providerFlags = [ 'allow_private_providers', 'models_fetch_enabled' ];
                for ( i = 0; i < providerFlags.length; i++ ) {
                        var provFlagBoxes = qsa( 'input[name="' + providerFlags[ i ] + '"]', form );
                        if ( provFlagBoxes.length ) {
                                payload[ providerFlags[ i ] ] = provFlagBoxes.some( function ( b ) { return b.checked; } ) ? 1 : 0;
                        }
                }

                // checkbox moduli feedback v1.1.12 (S8): presenti → 1, assenti → 0
                // (sync metriche GSC/GA4, news sitemap, dedup semantico, link interni,
                // learning, evergreen — stesso pattern dei blocchi flags sopra; se i
                // campi non sono nel markup qsa torna [] e il payload li omette)
                var feedbackFlags = [ 'metrics_sync_enabled', 'news_sitemap_enabled', 'dedup_similar_enabled', 'interlink_enabled', 'learn_enabled', 'evergreen_enabled' ];
                for ( i = 0; i < feedbackFlags.length; i++ ) {
                        var fbBoxes = qsa( 'input[name="' + feedbackFlags[ i ] + '"]', form );
                        if ( fbBoxes.length ) {
                                payload[ feedbackFlags[ i ] ] = fbBoxes.some( function ( b ) { return b.checked; } ) ? 1 : 0;
                        }
                }

                return payload;
        }

        function bindSettings() {
                var form = qs( '#nxa-settings-form' );
                if ( ! form ) {
                        return;
                }
                form.addEventListener( 'submit', function ( ev ) {
                        ev.preventDefault();
                        // v1.1.6: le pagine impostazioni hanno il bottone SALVA in cima
                        // OLTRE quello in fondo → stato di caricamento su TUTTI i submit
                        // della form (qualunque sia quello cliccato).
                        var submits = qsa( 'button[type="submit"], input[type="submit"]', form );
                        if ( ! submits.length ) {
                                submits = qsa( 'button', form );
                        }
                        setBtnLoading( submits, true, 'Salvataggio…' );
                        api( 'settings', { method: 'PUT', body: collectSettings( form ) } ).then( function () {
                                toast( 'Impostazioni salvate.', 'success', 'Impostazioni' );
                        } ).catch( function ( err ) {
                                apiErrorToast( err, 'Salvataggio non riuscito' );
                        } ).then( function () {
                                setBtnLoading( submits, false );
                        } );
                } );

                // v1.0.7 (WP12): modelli AI provider — il combobox cercabile del
                // modello globale (provider legacy '' = chiave+base URL globali) e
                // il bottone "Carica modelli" (refresh=1, invalida la cache server).
                // Precarico al blur della chiave (solo se compilata e cache client
                // scaduta; il server legge la chiave SALVATA).
                var globalModelInput = qs( '#nxa-ai-model' );
                if ( globalModelInput ) {
                        nexusModelPicker( globalModelInput, function () {
                                return '';
                        } );
                }
                var modelsBtn = qs( '#nxa-ai-models-load' );
                if ( modelsBtn ) {
                        modelsBtn.addEventListener( 'click', function () {
                                loadAiModels( true );
                        } );
                }
                var apiKeyField = qs( '#nxa-ai-api-key' );
                if ( apiKeyField ) {
                        apiKeyField.addEventListener( 'blur', function () {
                                if ( String( apiKeyField.value || '' ).trim() !== '' && ! mpCacheFresh( '' ) ) {
                                        loadAiModels( false );
                                }
                        } );
                }

                // v1.1.10 (WP10): wizard provider — bottoni OAuth/test/scollega
                // (sezione "Provider AI per agente") + completamento del ritorno
                // OAuth di OpenRouter (?nexus_oauth=1&state&code nella querystring).
                bindProviderActions( form );
                maybeCompleteOauthCallback();

                // v1.1.10 (F2b/WP11): sub-sezione "Provider personalizzati" — CRUD
                // REST, preset 1-click, conferma delete inline (no-op fuori da
                // Impostazioni AI: il bind richiede #nxa-custprov-list).
                bindCustomProviders( form );
        }

        /* ==================================================================
         * 13. NEXUS WIRE (redazione collettiva)
         * ================================================================== */

        function normalizeRun( run ) {
                var d = ( run && typeof run.data === 'object' && run.data ) ? run.data : {};
                var agentsRaw = pick( run, 'agents' ) || pick( d, 'agents' ) || [];
                var names = ( Array.isArray( agentsRaw ) ? agentsRaw : [] ).map( function ( a ) {
                        if ( typeof a === 'string' ) {
                                return a;
                        }
                        if ( a && a.name ) {
                                return a.role ? a.name + ' (' + a.role + ')' : a.name;
                        }
                        return '';
                } ).filter( Boolean );

                return {
                        id: pick( run, 'id', 'run_id' ) || '',
                        topic: pick( run, 'topic' ) || pick( d, 'topic' ) || '(senza titolo)',
                        status: String( pick( run, 'status' ) || pick( d, 'status' ) || 'info' ).toLowerCase(),
                        articleId: num( pick( run, 'article_id', 'articleId' ) || pick( d, 'article_id' ) || 0 ),
                        articleTitle: pick( run, 'article_title' ) || pick( d, 'title' ) || '',
                        score: num( pick( run, 'review_score', 'reviewScore' ) || pick( d, 'review_score', 'reviewScore' ) || 0 ),
                        sourcesCount: Array.isArray( pick( run, 'sources' ) || pick( d, 'sources' ) ) ? ( pick( run, 'sources' ) || pick( d, 'sources' ) ).length : 0,
                        error: pick( run, 'error' ) || pick( d, 'error' ) || '',
                        agents: names,
                        createdAt: pick( run, 'created_at', 'createdAt' ) || ''
                };
        }

        function wireRunHtml( run ) {
                var variant = 'neutral';
                var label = run.status;
                if ( run.status === 'completed' ) {
                        variant = 'success'; label = 'Completata';
                } else if ( run.status === 'failed' || run.status === 'error' ) {
                        variant = 'error'; label = 'Fallita';
                } else if ( run.status === 'running' || run.status === 'pending' ) {
                        variant = 'warning'; label = 'In corso';
                }
                var meta = [];
                meta.push( dateIt( run.createdAt ) );
                if ( run.sourcesCount ) {
                        meta.push( run.sourcesCount + ' fonti' );
                }
                if ( run.score ) {
                        meta.push( 'qualità ' + run.score + '%' );
                }
                if ( run.agents.length ) {
                        meta.push( 'agenti: ' + esc( run.agents.join( ', ' ) ) );
                }
                if ( run.error ) {
                        meta.push( '<span style="color:var(--nxa-err-text)">' + esc( run.error ) + '</span>' );
                }
                return '<div class="nxa-wire-run">' +
                        '<div style="min-width:0;flex:1">' +
                                '<strong style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc( run.topic ) + '</strong>' +
                                '<span style="font-size:12px;color:var(--nxa-muted)">' + meta.join( ' · ' ) + '</span>' +
                        '</div>' +
                        '<div style="display:flex;align-items:center;gap:8px">' +
                                ( run.articleId
                                        ? '<button type="button" class="button-link nxa-wire-article" data-id="' + run.articleId + '">Vedi articolo</button>'
                                        : '' ) +
                                '<span class="nxa-badge nxa-badge--' + variant + '">' + esc( label ) + '</span>' +
                        '</div>' +
                '</div>';
        }

        function loadWireRuns() {
                var container = qs( '#nxa-wire-runs' );
                if ( ! container ) {
                        return Promise.resolve();
                }
                showSkeleton( container, 3 );
                return api( 'wire/runs' ).then( function ( payload ) {
                        var runs = getList( payload, [ 'runs', 'items', 'list' ] ).map( normalizeRun );
                        container.innerHTML = runs.length
                                ? runs.map( wireRunHtml ).join( '' )
                                : emptyState( '🕸️', 'Nessuna esecuzione del Wire', 'Avvia la prima redazione collettiva qui sopra.' );
                } ).catch( function ( err ) {
                        container.innerHTML = emptyState( '⚠️', 'Impossibile caricare lo storico', err.message || '' );
                        window.console.warn( '[Nexus] wire runs:', err );
                } );
        }

        function bindWire() {
                var form = qs( '#nxa-wire-form' );
                if ( form ) {
                        var input = qs( '#nxa-wire-topic', form ) || qs( '#nxa-wire-topic' );
                        var submit = qs( 'button[type="submit"]', form ) || qs( 'button', form );
                        var phasesBox = null;
                        var phaseTimer = null;
                        var phaseIdx = 0;

                        // suggerimenti argomenti (fedeli al pannello React)
                        if ( input && ! qs( '#nxa-wire-ideas' ) ) {
                                var ideas = el( '<div id="nxa-wire-ideas" style="margin-top:8px"></div>' );
                                ideas.innerHTML = WIRE_IDEAS.map( function ( idea ) {
                                        return '<button type="button" class="nxa-idea">' + esc( idea ) + '</button>';
                                } ).join( '' );
                                ideas.addEventListener( 'click', function ( ev ) {
                                        if ( ev.target.classList && ev.target.classList.contains( 'nxa-idea' ) && input ) {
                                                input.value = ev.target.textContent;
                                                input.dispatchEvent( new Event( 'input' ) );
                                        }
                                } );
                                ( input.parentNode || form ).insertBefore( ideas, input.nextSibling );
                        }

                        // abilita il bottone solo con un argomento valido (debounced)
                        var syncState = debounce( function () {
                                if ( ! submit || form.getAttribute( 'data-running' ) ) {
                                        return; // non toccare il bottone durante una redazione in corso
                                }
                                submit.disabled = ! input || String( input.value || '' ).trim().length < 3;
                        }, 200 );
                        if ( input ) {
                                input.addEventListener( 'input', syncState );
                                syncState(); // stato iniziale: disabilitato se l'argomento manca
                        }

                        var clearPhases = function () {
                                if ( phaseTimer ) {
                                        window.clearInterval( phaseTimer );
                                        phaseTimer = null;
                                }
                                if ( phasesBox && phasesBox.parentNode ) {
                                        phasesBox.parentNode.removeChild( phasesBox );
                                        phasesBox = null;
                                }
                        };

                        form.addEventListener( 'submit', function ( ev ) {
                                ev.preventDefault();
                                var topic = input ? String( input.value || '' ).trim() : '';
                                if ( topic.length < 3 ) {
                                        toast( 'Inserisci un argomento di almeno 3 caratteri.', 'error', 'Nexus Wire' );
                                        return;
                                }
                                form.setAttribute( 'data-running', '1' );
                                setBtnLoading( submit, true, 'Redazione al lavoro… (3 agenti + direttore)' );
                                if ( input ) {
                                        input.disabled = true;
                                }

                                // fasi animate: la redazione impiega 1-3 minuti
                                phasesBox = el( '<div class="nxa-wire-status" role="status"></div>' );
                                phaseIdx = 0;
                                phasesBox.innerHTML = '<span class="nxa-spinner" aria-hidden="true"></span> ' + esc( WIRE_PHASES[ 0 ] ) +
                                        '<br><small>La redazione collettiva impiega 1-3 minuti: non chiudere la pagina.</small>';
                                form.appendChild( phasesBox );
                                phaseTimer = window.setInterval( function () {
                                        phaseIdx = Math.min( phaseIdx + 1, WIRE_PHASES.length - 1 );
                                        if ( phasesBox ) {
                                                phasesBox.innerHTML = '<span class="nxa-spinner" aria-hidden="true"></span> ' + esc( WIRE_PHASES[ phaseIdx ] ) +
                                                        '<br><small>La redazione collettiva impiega 1-3 minuti: non chiudere la pagina.</small>';
                                        }
                                }, 15000 );

                                api( 'wire/run', { method: 'POST', body: { topic: topic } } ).then( function ( payload ) {
                                        var d = unwrap( payload ) || {};
                                        var title = pick( d, 'title', 'article_title' ) || topic;
                                        var score = num( pick( d, 'review_score', 'reviewScore', 'quality_score' ) );
                                        toast( '“' + title + '” è in coda di approvazione' + ( score ? ' (qualità ' + score + '%)' : '' ) + '.',
                                                'success', 'Edizione completata' );
                                        if ( input ) {
                                                input.value = '';
                                        }
                                        return loadWireRuns();
                                } ).catch( function ( err ) {
                                        apiErrorToast( err, 'Redazione interrotta' );
                                } ).then( function () {
                                        clearPhases();
                                        form.removeAttribute( 'data-running' );
                                        setBtnLoading( submit, false );
                                        if ( input ) {
                                                input.disabled = false;
                                                submit.disabled = String( input.value || '' ).trim().length < 3;
                                        }
                                } );
                        } );
                }

                var runsBox = qs( '#nxa-wire-runs' );
                if ( runsBox ) {
                        runsBox.addEventListener( 'click', function ( ev ) {
                                var link = ev.target.closest ? ev.target.closest( '.nxa-wire-article' ) : null;
                                if ( link ) {
                                        openArticleModal( link.getAttribute( 'data-id' ) );
                                }
                        } );
                }
        }

        /* ==================================================================
         * 13-bis. MIGRAZIONE CONTENUTI NATIVI (v1.1.0)
         * ================================================================== */

        /**
         * v1.1.0: card "Migrazione contenuti nativi" (Strumenti). Loop di
         * batch POST /native/migrate {limit:20} finché finished === true o
         * errore HTTP; per ogni batch aggiorna barra e label con done/total.
         * Guardia anti-stallo: se un batch non muove né done né remaining si
         * interrompe con errore onesto (mai loop infinito, mai successo
         * simulato). Markup T-01: #nxa-native-run (bottone),
         * #nxa-native-progress con barra .nxa-native-progress-fill e
         * #nxa-native-progress-label, #nxa-native-report (errori, max 10) e
         * #nxa-native-status (esito onesto, aria-live).
         */
        var nativeMigrateRunning = false;

        /** v1.1.0: voce errori del batch → testo leggibile (stringa o oggetto). */
        function nativeErrorText( item ) {
                if ( ! item ) {
                        return '';
                }
                if ( typeof item === 'string' ) {
                        return item;
                }
                if ( item.message ) {
                        return String( item.message );
                }
                if ( item.error ) {
                        return String( item.error );
                }
                try {
                        return JSON.stringify( item );
                } catch ( e ) {
                        return String( item );
                }
        }

        /** v1.1.0: barra (width %, guard total 0) + label "X di Y". */
        function setNativeProgress( done, total ) {
                var fill = qs( '.nxa-native-progress-fill' );
                var label = qs( '#nxa-native-progress-label' );
                var safeTotal = num( total );
                var pct = safeTotal > 0 ? ( num( done ) / safeTotal ) * 100 : 0;
                if ( pct < 0 ) {
                        pct = 0;
                }
                if ( pct > 100 ) {
                        pct = 100;
                }
                if ( fill ) {
                        fill.style.width = pct.toFixed( 1 ) + '%';
                }
                if ( label ) {
                        label.textContent = fmt( done ) + ' di ' + fmt( total );
                }
        }

        /** v1.1.0: report errori (max 10); nessun errore → box nascosto e pulito. */
        function renderNativeReport( errors ) {
                var box = qs( '#nxa-native-report' );
                if ( ! box ) {
                        return;
                }
                var list = Array.isArray( errors ) ? errors : [];
                if ( ! list.length ) {
                        box.hidden = true;
                        box.innerHTML = '';
                        return;
                }
                var max = Math.min( list.length, 10 );
                var html = '';
                for ( var i = 0; i < max; i++ ) {
                        html += '<li>' + esc( nativeErrorText( list[ i ] ) ) + '</li>';
                }
                box.innerHTML = html;
                box.hidden = false;
        }

        /**
         * v1.1.0: batch successivi finché finished === true. Stato in state
         * (errors accumulati, done, ultimo done/remaining per la guardia
         * anti-stallo). Errore HTTP → l'Error di api() propaga al chiamante.
         */
        function runNativeMigrationBatches( state ) {
                return api( 'native/migrate', { method: 'POST', body: { limit: 20 } } ).then( function ( payload ) {
                        var d = unwrap( payload ) || {};
                        var done = num( pick( d, 'done' ) );
                        var total = num( pick( d, 'total' ) );
                        var remaining = num( pick( d, 'remaining' ) );
                        setNativeProgress( done, total );
                        var batchErrors = Array.isArray( d.errors ) ? d.errors : [];
                        for ( var i = 0; i < batchErrors.length; i++ ) {
                                state.errors.push( nativeErrorText( batchErrors[ i ] ) );
                        }
                        state.done = done;
                        if ( d.finished === true ) {
                                return null;
                        }
                        if ( done === state.lastDone && remaining === state.lastRemaining ) {
                                throw new Error( 'La migrazione non sta progredendo: interrotta dopo un batch senza movimenti.' );
                        }
                        state.lastDone = done;
                        state.lastRemaining = remaining;
                        return runNativeMigrationBatches( state );
                } );
        }

        /**
         * v1.1.0: binding del bottone #nxa-native-run — chiamata dal loader
         * "tools" di LOADERS. Bottone assente (altri contesti) → exit
         * silenzioso. Durante il loop il bottone resta disabilitato e viene
         * SEMPRE riabilitato alla fine (ramo finale after-catch = finally:
         * ESC/refresh safe).
         */
        function bindNativeMigration() {
                var btn = qs( '#nxa-native-run' );
                if ( ! btn ) {
                        return;
                }
                btn.addEventListener( 'click', function () {
                        if ( nativeMigrateRunning ) {
                                return;
                        }
                        var progress = qs( '#nxa-native-progress' );
                        var status = qs( '#nxa-native-status' );
                        var state = { errors: [], done: 0, lastDone: -1, lastRemaining: -1 };
                        nativeMigrateRunning = true;
                        setBtnLoading( btn, true, 'Migrazione in corso…' );
                        if ( progress ) {
                                progress.hidden = false;
                        }
                        setNativeProgress( 0, 0 );
                        renderNativeReport( [] );
                        if ( status ) {
                                status.textContent = '';
                        }
                        runNativeMigrationBatches( state ).then( function () {
                                renderNativeReport( state.errors );
                                if ( status ) {
                                        status.textContent = 'Migrazione completata: ' + fmt( state.done ) + ' articoli convertiti';
                                }
                        } ).catch( function ( err ) {
                                renderNativeReport( state.errors );
                                if ( status ) {
                                        status.textContent = 'Migrazione interrotta: ' + ( ( err && err.message ) ? err.message : 'errore sconosciuto' );
                                }
                        } ).then( function () {
                                nativeMigrateRunning = false;
                                setBtnLoading( btn, false );
                        } );
                } );
        }

        /* ==================================================================
         * 13-ter. TEST DEGLI STRUMENTI (v1.1.7) — POST /tools/test
         * ==================================================================
         *
         * Card "Test degli strumenti" della pagina Strumenti (markup T-B2 in
         * tools.php): select #nxa-tools-test-tool (search|read|feeds), input
         * #nxa-tools-test-input (placeholder dinamico per strumento), bottone
         * #nxa-tools-test-run e box esito #nxa-tools-test-result. La rotta
         * POST /tools/test (callback rest_tools_test, già funzionante) vuole
         * query per tool=search e url per tool=read|feeds: il body viene
         * costruito di conseguenza. Errori HTTP 400 onesti (tool/query/url
         * mancanti) → messaggio del body WP nel box con modificatore --warn,
         * mai toast. Markup assente (altri contesti) → exit silenzioso.
         */

        var toolsTestBusy = false;

        /** v1.1.7: placeholder dell'input per lo strumento selezionato. */
        function toolsTestPlaceholder( tool ) {
                if ( tool === 'read' ) {
                        return 'https://esempio.it/pagina';
                }
                if ( tool === 'feeds' ) {
                        return 'https://esempio.it';
                }
                return 'es. intelligenza artificiale';
        }

        /**
         * v1.1.7: render dell'esito nel box #nxa-tools-test-result. ok →
         * modificatore --ok con il JSON dello strumento in <code>; esito
         * negativo (ok=false o campo error) → --warn con l'errore e l'eventuale
         * JSON; il JSON è CLIPPATO lato server a 4000 caratteri e inserito via
         * esc() (mai HTML dello strumento nel DOM).
         */
        function renderToolsTestResult( box, tool, data ) {
                if ( ! box ) {
                        return;
                }
                var d = data || {};
                var error = String( pick( d, 'error' ) || '' ).trim();
                var json = String( pick( d, 'result' ) || '' );
                var ok = d.ok === true && '' === error;
                if ( ok ) {
                        box.className = 'nxa-tools-result nxa-tools-result--ok';
                        box.innerHTML = 'Test riuscito (' + esc( tool ) + '). Esito dello strumento:' +
                                '<code class="nxa-tools-test-json">' + esc( json || '(nessun output)' ) + '</code>';
                        return;
                }
                box.className = 'nxa-tools-result nxa-tools-result--warn';
                box.innerHTML = 'Test non riuscito (' + esc( tool ) + '): ' +
                        esc( error || 'lo strumento ha restituito un esito negativo.' ) +
                        ( json ? '<code class="nxa-tools-test-json">' + esc( json ) + '</code>' : '' );
        }

        /**
         * v1.1.7: binding della card "Test degli strumenti" — chiamata dal
         * loader "tools" di LOADERS (accanto a bindNativeMigration). Bottone
         * assente → exit silenzioso. Durante la chiamata il bottone resta
         * disabilitato (setBtnLoading) e viene SEMPRE riabilitato alla fine.
         */
        function bindToolsTest() {
                var btn = qs( '#nxa-tools-test-run' );
                if ( ! btn ) {
                        return;
                }
                var toolSel = qs( '#nxa-tools-test-tool' );
                var input = qs( '#nxa-tools-test-input' );
                var box = qs( '#nxa-tools-test-result' );

                function syncInput() {
                        var tool = toolSel ? String( toolSel.value || 'search' ) : 'search';
                        if ( input ) {
                                input.placeholder = toolsTestPlaceholder( tool );
                        }
                }
                if ( toolSel ) {
                        toolSel.addEventListener( 'change', syncInput );
                        syncInput();
                }

                btn.addEventListener( 'click', function () {
                        if ( toolsTestBusy ) {
                                return;
                        }
                        var tool = toolSel ? String( toolSel.value || 'search' ) : 'search';
                        var value = input ? String( input.value || '' ).trim() : '';
                        if ( ! value ) {
                                if ( box ) {
                                        box.className = 'nxa-tools-result nxa-tools-result--warn';
                                        box.textContent = 'Inserisci una query o un URL da testare.';
                                }
                                return;
                        }
                        var body = { tool: tool };
                        if ( tool === 'search' ) {
                                body.query = value;
                        } else {
                                body.url = value;
                        }
                        toolsTestBusy = true;
                        setBtnLoading( btn, true, 'Test in corso…' );
                        if ( box ) {
                                box.className = 'nxa-tools-result';
                                box.textContent = 'Test in corso…';
                        }
                        api( 'tools/test', { method: 'POST', body: body } ).then( function ( payload ) {
                                renderToolsTestResult( box, tool, unwrap( payload ) || {} );
                        } ).catch( function ( err ) {
                                // Errori 400 onesti della rotta (tool/query/url mancanti):
                                // messaggio WP nel box, senza toast.
                                if ( box ) {
                                        box.className = 'nxa-tools-result nxa-tools-result--warn';
                                        box.textContent = 'Test non riuscito: ' + ( ( err && err.message ) ? err.message : 'errore sconosciuto' );
                                }
                        } ).then( function () {
                                toolsTestBusy = false;
                                setBtnLoading( btn, false );
                        } );
                } );
        }

        /* ==================================================================
         * 13-quater. REDAZIONI GERARCHICHE (v1.1.1)
         * ================================================================== */

        /**
         * v1.1.1: pagina "Redazioni" (markup T-01, REST T-02). Albero nativo
         * della tassonomia nexus_redazione: righe .nxa-red-row con classe di
         * profondità .nxa-red-depth-0 fino a -3, toggle espandi e compridi,
         * chip agenti, contatore articoli del ramo e azioni modifica,
         * elimina, archivio pubblico. La stessa risposta GET /redazioni
         * alimenta la colonna "Redazioni" della pagina Agenti (una sola
         * fetch per pagina, cache in redazioniCache come pattern getAgents).
         *
         * Forma attesa del payload (T-02): { redazioni: [ { id, name, slug,
         * parent, agents: [ { id, name, avatar } ], count, count_subtree } ] }.
         * Lettura difensiva con pick e getList: campi mancanti non crashano.
         */
        var redazioniCache = { list: null };
        var redCollapsed = {};
        var redazioniBound = false;

        /** v1.1.1: GET /redazioni con cache pagina (force = ricarica dalla REST). */
        function getRedazioni( force ) {
                if ( redazioniCache.list && ! force ) {
                        return Promise.resolve( redazioniCache.list );
                }
                return api( 'redazioni' ).then( function ( payload ) {
                        redazioniCache.list = getList( payload, [ 'redazioni', 'items', 'list' ] );
                        return redazioniCache.list;
                } );
        }

        /** v1.1.1: confronto per nome, usato per ordinare le fratellanze. */
        function redazioniNameCompare( a, b ) {
                return String( pick( a, 'name' ) || '' ).localeCompare( String( pick( b, 'name' ) || '' ) );
        }

        /**
         * v1.1.1: indice dell'albero redazioni. Ritorna byId, children (mappa
         * da parent a id dei figli), order (lista piatta in ordine albero:
         * madri prima, nome dentro ogni livello), depths (0 fino a 3, clamp
         * per il CSS che copre 4 livelli) e roots (radici reali: parent 0 o
         * padre assente — servono alle statistiche "articoli coperti").
         * Protezione anti-ciclo: un termine è visitato una sola volta.
         */
        function redazioniIndex( terms ) {
                var byId = {};
                var children = {};
                var i;
                for ( i = 0; i < terms.length; i++ ) {
                        var tId = num( pick( terms[ i ], 'id' ) );
                        byId[ tId ] = terms[ i ];
                        var tParent = num( pick( terms[ i ], 'parent' ) );
                        if ( ! children[ tParent ] ) {
                                children[ tParent ] = [];
                        }
                        children[ tParent ].push( tId );
                }
                Object.keys( children ).forEach( function ( key ) {
                        children[ key ].sort( function ( a, b ) {
                                return redazioniNameCompare( byId[ a ], byId[ b ] );
                        } );
                } );

                var roots = ( children[ '0' ] || [] ).slice();
                for ( i = 0; i < terms.length; i++ ) {
                        var oId = num( pick( terms[ i ], 'id' ) );
                        var oParent = num( pick( terms[ i ], 'parent' ) );
                        if ( oParent > 0 && ! byId[ oParent ] && roots.indexOf( oId ) === -1 ) {
                                roots.push( oId ); // orfano: padre cancellato, esposto come radice
                        }
                }
                roots.sort( function ( a, b ) {
                        return redazioniNameCompare( byId[ a ], byId[ b ] );
                } );

                var order = [];
                var depths = {};
                var visited = {};
                var walk = function ( id, depth ) {
                        if ( visited[ id ] ) {
                                return;
                        }
                        visited[ id ] = true;
                        order.push( id );
                        depths[ id ] = Math.min( depth, 3 );
                        ( children[ id ] || [] ).forEach( function ( childId ) {
                                walk( childId, depth + 1 );
                        } );
                };
                roots.forEach( function ( id ) {
                        walk( id, 0 );
                } );
                // Cicli patologici (parent tra termini esistenti ma non raggiunti
                // dalla discesa): esposti come radici invece che persi.
                for ( i = 0; i < terms.length; i++ ) {
                        var rId = num( pick( terms[ i ], 'id' ) );
                        if ( ! visited[ rId ] ) {
                                walk( rId, 0 );
                        }
                }
                return { byId: byId, children: children, order: order, depths: depths, roots: roots };
        }

        /** v1.1.1: termine redazione per id, dalla cache pagina. */
        function redazioniTermById( termId ) {
                var list = redazioniCache.list || [];
                for ( var i = 0; i < list.length; i++ ) {
                        if ( num( pick( list[ i ], 'id' ) ) === num( termId ) ) {
                                return list[ i ];
                        }
                }
                return null;
        }

        /** v1.1.1: chip agente per l'albero (avatar emoji + nome). */
        function redAgentChip( agent ) {
                return '<span class="nxa-red-chip">' +
                        '<span aria-hidden="true">' + esc( pick( agent, 'avatar' ) || '🤖' ) + '</span> ' +
                        esc( pick( agent, 'name' ) || 'Agente' ) +
                        '</span>';
        }

        /** v1.1.1: chip redazione per la colonna nella pagina Agenti (solo nome). */
        function redTermChip( term ) {
                return '<span class="nxa-red-chip">' + esc( pick( term, 'name' ) || 'Redazione' ) + '</span>';
        }

        /** v1.1.1: riga dell'albero .nxa-red-row (data-id, data-depth, data-slug). */
        function redazioneRowHtml( term, index ) {
                var id = num( pick( term, 'id' ) );
                var depth = index.depths[ id ] || 0;
                var slug = String( pick( term, 'slug' ) || '' );
                var hasChildren = ( index.children[ id ] || [] ).length > 0;
                var agents = Array.isArray( term.agents ) ? term.agents : [];
                var chips = agents.length
                        ? '<span class="nxa-red-agents-chips">' + agents.map( redAgentChip ).join( '' ) + '</span>'
                        : '';
                var count = num( pick( term, 'count_subtree', 'count' ) );
                var countLabel = fmt( count ) + ( count === 1 ? ' articolo' : ' articoli' );
                var toggle = hasChildren
                        ? '<button type="button" class="nxa-red-toggle" data-id="' + id + '" aria-expanded="true" aria-label="Mostra o nascondi le sub-redazioni">▾</button>'
                        : '';
                // v1.1.5: "Avvia redazione" (nxa-btn-run, data-run-redazione) è
                // il primo bottone della riga: lancia la pipeline per TUTTI gli
                // agenti della redazione (POST pipeline/run redazione_id,
                // handler delegato nella sezione AVVII MIRATI di admin.js).
                var actions =
                        '<button type="button" class="button-link nxa-btn-run" data-run-redazione="' + id + '">▶ Avvia redazione</button>' +
                        '<button type="button" class="button-link nxa-red-edit" data-id="' + id + '">Modifica</button>' +
                        '<button type="button" class="button-link nxa-red-del" data-id="' + id + '">Elimina</button>' +
                        ( slug ? '<button type="button" class="button-link nxa-red-view" data-slug="' + esc( slug ) + '">Archivio ↗</button>' : '' );
                return '<div class="nxa-red-row nxa-red-depth-' + depth + '" data-id="' + id + '" data-depth="' + depth + '" data-slug="' + esc( slug ) + '">' +
                        toggle +
                        '<span class="nxa-red-name">' + esc( pick( term, 'name' ) || '(senza nome)' ) + '</span>' +
                        chips +
                        '<span class="nxa-red-count">' + esc( countLabel ) + '</span>' +
                        '<span class="nxa-red-actions">' + actions + '</span>' +
                '</div>';
        }

        /**
         * v1.1.1: visibilità delle righe dopo un toggle. Una riga è nascosta
         * se un antenato è collassato (flag in redCollapsed, id e boolean).
         * Display inline: nessuna regola CSS può sovrascriverlo.
         */
        function applyRedazioniVisibility() {
                var tree = qs( '#nxa-red-tree' );
                if ( ! tree ) {
                        return;
                }
                var chain = []; // chain[d] = un antenato a profondità d o inferiore è collassato
                qsa( '.nxa-red-row', tree ).forEach( function ( row ) {
                        var depth = num( row.getAttribute( 'data-depth' ) );
                        var hidden = depth > 0 && chain[ depth - 1 ] === true;
                        row.style.display = hidden ? 'none' : '';
                        var id = num( row.getAttribute( 'data-id' ) );
                        chain.length = depth;
                        chain[ depth ] = hidden || !! redCollapsed[ id ];
                } );
        }

        /** v1.1.1: render dell'albero in #nxa-red-tree (reset dello stato espanso). */
        function renderRedazioniTree( terms ) {
                var tree = qs( '#nxa-red-tree' );
                if ( ! tree ) {
                        return;
                }
                redCollapsed = {};
                if ( ! terms.length ) {
                        tree.innerHTML = '';
                        return;
                }
                var index = redazioniIndex( terms );
                tree.innerHTML = index.order.map( function ( id ) {
                        return redazioneRowHtml( index.byId[ id ], index );
                } ).join( '' );
                applyRedazioniVisibility();
        }

        /**
         * v1.1.1: statistiche in #nxa-red-stats — "N redazioni · N agenti
         * assegnati · N articoli coperti". Agenti assegnati = agenti distinti
         * presenti nei termini; articoli coperti = somma di count_subtree
         * delle sole radici (le redazioni madri).
         */
        function renderRedazioniStats( terms ) {
                var box = qs( '#nxa-red-stats' );
                if ( ! box ) {
                        return;
                }
                var seenAgents = {};
                var totalAgents = 0;
                terms.forEach( function ( term ) {
                        var agents = Array.isArray( term.agents ) ? term.agents : [];
                        agents.forEach( function ( agent ) {
                                var id = num( pick( agent, 'id' ) );
                                if ( id > 0 && ! seenAgents[ id ] ) {
                                        seenAgents[ id ] = true;
                                        totalAgents++;
                                }
                        } );
                } );
                var index = redazioniIndex( terms );
                var covered = 0;
                index.roots.forEach( function ( id ) {
                        covered += num( pick( index.byId[ id ], 'count_subtree' ) );
                } );
                box.textContent = fmt( terms.length ) + ' ' + ( terms.length === 1 ? 'redazione' : 'redazioni' ) + ' · ' +
                        fmt( totalAgents ) + ' ' + ( totalAgents === 1 ? 'agente assegnato' : 'agenti assegnati' ) + ' · ' +
                        fmt( covered ) + ' ' + ( covered === 1 ? 'articolo coperto' : 'articoli coperti' );
        }

        /** v1.1.1: stato vuoto #nxa-red-empty (classe hidden) quando 0 termini. */
        function toggleRedazioniEmpty( terms ) {
                var empty = qs( '#nxa-red-empty' );
                if ( ! empty ) {
                        return;
                }
                if ( terms.length > 0 ) {
                        empty.classList.add( 'hidden' );
                } else {
                        empty.classList.remove( 'hidden' );
                }
        }

        /**
         * v1.1.1: riempie #nxa-red-parent (voce "(nessuna — redazione madre)"
         * con value 0, poi opzioni indentate con trattini per profondità) e
         * #nxa-red-agents (checkbox .nxa-red-agent con value pari all'id
         * agente, label avatar e nome, checked se assegnato al termine in
         * edit). In edit esclude dal select madre il termine stesso e tutti
         * i suoi discendenti (un ramo non può diventare figlio di se stesso).
         */
        function fillRedazioneFormOptions( terms, agents, editTerm ) {
                var form = qs( '#nxa-red-form' );
                if ( ! form ) {
                        return;
                }
                var index = redazioniIndex( terms );
                var editId = editTerm ? num( pick( editTerm, 'id' ) ) : 0;
                var excluded = {};
                if ( editId > 0 ) {
                        var stack = [ editId ];
                        while ( stack.length ) {
                                var cur = stack.pop();
                                if ( excluded[ cur ] ) {
                                        continue;
                                }
                                excluded[ cur ] = true;
                                ( index.children[ cur ] || [] ).forEach( function ( childId ) {
                                        stack.push( childId );
                                } );
                        }
                }
                var select = qs( '#nxa-red-parent', form );
                if ( select ) {
                        var html = '<option value="0">' + esc( '(nessuna — redazione madre)' ) + '</option>';
                        index.order.forEach( function ( id ) {
                                if ( excluded[ id ] ) {
                                        return;
                                }
                                var depth = index.depths[ id ] || 0;
                                var pad = depth > 0 ? new Array( depth + 1 ).join( '— ' ) : '';
                                html += '<option value="' + id + '">' + esc( pad + String( pick( index.byId[ id ], 'name' ) || '' ) ) + '</option>';
                        } );
                        select.innerHTML = html;
                        var wantParent = editTerm ? num( pick( editTerm, 'parent' ) ) : 0;
                        select.value = String( wantParent );
                        if ( select.value !== String( wantParent ) ) {
                                select.value = '0'; // opzione assente o esclusa: torna la radice
                        }
                }
                var box = qs( '#nxa-red-agents', form );
                if ( box ) {
                        var assigned = {};
                        if ( editTerm && Array.isArray( editTerm.agents ) ) {
                                editTerm.agents.forEach( function ( agent ) {
                                        var aid = num( pick( agent, 'id' ) );
                                        if ( aid > 0 ) {
                                                assigned[ aid ] = true;
                                        }
                                } );
                        }
                        box.innerHTML = agents.length
                                ? agents.map( function ( agent ) {
                                        var aid = num( pick( agent, 'id' ) );
                                        return '<label class="nxa-red-agent-item">' +
                                                '<input type="checkbox" class="nxa-red-agent" value="' + aid + '"' + ( assigned[ aid ] ? ' checked="checked"' : '' ) + ' /> ' +
                                                '<span aria-hidden="true">' + esc( pick( agent, 'avatar' ) || '🤖' ) + '</span> ' +
                                                esc( pick( agent, 'name' ) || 'Agente' ) +
                                        '</label>';
                                } ).join( '' )
                                : '<p class="nxa-red-agents-empty">Nessun agente configurato.</p>';
                }
        }

        /* v1.1.4: fonti della redazione (meta _nexus_sources) — conversioni tra le
         * righe della textarea "rss|URL" / "site|URL" e l'array {type, url} che la
         * REST nativa wp/v2 si aspetta (sanitize_callback server-side: Nexus_Redazioni). */
        function redSourcesField() {
                return qs( '#nxa-red-sources' );
        }

        function parseRedSources( text ) {
                var out = [];
                var seen = {};
                String( text || '' ).split( /\r?\n/ ).forEach( function ( raw ) {
                        var line = String( raw || '' ).trim();
                        if ( ! line ) {
                                return;
                        }
                        var parts = line.split( '|' );
                        var type = ( parts[ 0 ] || '' ).trim().toLowerCase() === 'site' ? 'site' : 'rss';
                        var url = ( parts.length > 1 ? parts.slice( 1 ).join( '|' ) : line ).trim();
                        if ( ! url || ! /^https?:\/\//i.test( url ) ) {
                                return; // riga vuota o URL non valido: ignorata
                        }
                        var key = type + ' ' + url.toLowerCase();
                        if ( seen[ key ] ) {
                                return;
                        }
                        seen[ key ] = 1;
                        out.push( { type: type, url: url } );
                } );
                return out;
        }

        function redSourcesToText( items ) {
                return ( Array.isArray( items ) ? items : [] ).map( function ( item ) {
                        var type = String( pick( item, 'type' ) || 'rss' ).toLowerCase() === 'site' ? 'site' : 'rss';
                        return type + '|' + String( pick( item, 'url' ) || '' );
                } ).filter( function ( row ) {
                        return row.length > 4;
                } ).join( '\n' );
        }

        /** Salvataggio fonti via REST nativa wp/v2: array vuoto = cancella tutte. */
        function saveRedSources( termId, items ) {
                return apiWp( 'wp/v2/redazioni/' + num( termId ), {
                        method: 'POST',
                        body: { meta: { _nexus_sources: items } }
                } );
        }

        /** Caricamento fonti in modalità edit (GET wp/v2 context=edit, meta opzionale). */
        function loadRedSources( termId ) {
                var field = redSourcesField();
                if ( ! field ) {
                        return;
                }
                apiWp( 'wp/v2/redazioni/' + num( termId ) + '?context=edit' ).then( function ( term ) {
                        // se nel frattempo l'editore ha cambiato target, non toccare la textarea
                        var idField = qs( '#nxa-red-id' );
                        if ( ! idField || num( idField.value ) !== num( termId ) ) {
                                return;
                        }
                        var meta = term && term.meta ? term.meta : {};
                        field.value = redSourcesToText( meta._nexus_sources );
                } ).catch( function ( err ) {
                        window.console.warn( '[Nexus] fonti redazione:', err );
                } );
        }

        /** v1.1.1: reset del form alla modalità crea (id 0, campi vuoti, cancel nascosto). */
        function resetRedazioneForm() {
                var form = qs( '#nxa-red-form' );
                if ( ! form ) {
                        return;
                }
                var idField = qs( '#nxa-red-id', form );
                if ( idField ) {
                        idField.value = '0';
                }
                var nameField = qs( '#nxa-red-name', form );
                if ( nameField ) {
                        nameField.value = '';
                }
                var slugField = qs( '#nxa-red-slug', form );
                if ( slugField ) {
                        slugField.value = '';
                }
                var sourcesField = redSourcesField();
                if ( sourcesField ) {
                        sourcesField.value = '';
                }
                var select = qs( '#nxa-red-parent', form );
                if ( select ) {
                        select.value = '0';
                }
                qsa( 'input.nxa-red-agent', form ).forEach( function ( box ) {
                        box.checked = false;
                } );
                var cancel = qs( '#nxa-red-cancel' );
                if ( cancel ) {
                        cancel.classList.add( 'hidden' );
                }
        }

        /**
         * v1.1.1: modalità edit — precompila il form dal termine (id, nome,
         * slug, madre, agenti assegnati), mostra #nxa-red-cancel che in
         * modalità crea resta nascosto e riporta la vista in cima.
         */
        function openRedazioneEditor( term ) {
                var form = qs( '#nxa-red-form' );
                if ( ! form ) {
                        return;
                }
                var idField = qs( '#nxa-red-id', form );
                var nameField = qs( '#nxa-red-name', form );
                var slugField = qs( '#nxa-red-slug', form );
                if ( ! idField || ! nameField || ! slugField ) {
                        return;
                }
                fillRedazioneFormOptions( redazioniCache.list || [], agentsCache.list || [], term );
                idField.value = String( num( pick( term, 'id' ) ) || 0 );
                nameField.value = String( pick( term, 'name' ) || '' );
                slugField.value = String( pick( term, 'slug' ) || '' );
                loadRedSources( num( pick( term, 'id' ) ) ); // v1.1.4: fonti da meta wp/v2
                var cancel = qs( '#nxa-red-cancel' );
                if ( cancel ) {
                        cancel.classList.remove( 'hidden' );
                }
                window.scrollTo( 0, 0 );
                nameField.focus();
        }

        /** v1.1.1: archivio pubblico della redazione in una nuova scheda. */
        function openRedazioneArchive( slug ) {
                var clean = String( slug || '' ).trim();
                var base = String( D.siteUrl || '' ).replace( /\/+$/, '' );
                if ( ! clean || ! base ) {
                        return;
                }
                window.open( base + '/redazione/' + encodeURIComponent( clean ) + '/', '_blank' );
        }

        /** v1.1.1: conferma INLINE in riga (v1.1.13 O4, niente window.confirm),
         *  poi DELETE /redazioni/{id}. */
        function confirmDeleteRedazione( btn ) {
                var id = num( btn.getAttribute( 'data-id' ) );
                var term = redazioniTermById( id );
                var label = term ? String( pick( term, 'name' ) || '' ) : 'ID ' + id;
                nxaInlineConfirmOpen(
                        btn,
                        nxaT( 'Eliminare la redazione “' ) + label + nxaT( '”?\n\nLe sub-redazioni figlie verranno riassegnate alla redazione madre. Gli articoli non vengono cancellati.' ),
                        nxaT( 'Sì, elimina' ),
                        function () {
                                setBtnLoading( btn, true, nxaT( 'Elimino…' ) );
                                api( 'redazioni/' + id, { method: 'DELETE' } ).then( function ( payload ) {
                                        var data = unwrap( payload ) || {};
                                        toast( String( pick( data, 'message' ) || 'Redazione eliminata.' ), 'success', 'Redazioni' );
                                        var idField = qs( '#nxa-red-id' );
                                        if ( idField && num( idField.value ) === id ) {
                                                resetRedazioneForm(); // era in edit il termine eliminato
                                        }
                                        return loadRedazioniPage();
                                } ).catch( function ( err ) {
                                        apiErrorToast( err, 'Eliminazione non riuscita' );
                                        setBtnLoading( btn, false );
                                } );
                        }
                );
        }

        /** v1.1.1: submit del form — id 0 crea, id maggiore aggiorna (POST). */
        function onRedazioneFormSubmit( ev ) {
                ev.preventDefault();
                var form = ev.target;
                var idField = qs( '#nxa-red-id', form );
                var editId = num( idField ? idField.value : 0 );
                var readField = function ( selector ) {
                        var field = qs( selector, form );
                        return field ? String( field.value || '' ).trim() : '';
                };
                var name = readField( '#nxa-red-name' );
                if ( ! name ) {
                        toast( 'Inserisci il nome della redazione.', 'error', 'Redazioni' );
                        return;
                }
                var select = qs( '#nxa-red-parent', form );
                var body = {
                        name: name,
                        parent: select ? num( select.value ) : 0,
                        agent_ids: qsa( 'input.nxa-red-agent:checked', form ).map( function ( box ) {
                                return num( box.value );
                        } ).filter( Boolean )
                };
                var slug = readField( '#nxa-red-slug' );
                if ( slug ) {
                        body.slug = slug; // inviato solo se compilato
                }
                // v1.1.4: fonti della redazione — in modifica sempre (array vuoto = pulisce),
                // in creazione solo se compilate (dopo che il POST restituisce term_id)
                var sourcesItems = parseRedSources( readField( '#nxa-red-sources' ) );
                var saveBtn = qs( '#nxa-red-save', form );
                setBtnLoading( saveBtn, true, 'Salvo…' );
                var request = editId > 0
                        ? api( 'redazioni/' + editId, { method: 'POST', body: body } )
                        : api( 'redazioni', { method: 'POST', body: body } );
                request.then( function ( payload ) {
                        var savedId = editId > 0 ? editId : num( pick( unwrap( payload ) || {}, 'term_id' ) );
                        var afterSources = ( savedId > 0 && ( editId > 0 || sourcesItems.length ) )
                                ? saveRedSources( savedId, sourcesItems ).catch( function ( err ) {
                                        window.console.warn( '[Nexus] fonti redazione (salvataggio):', err );
                                        toast( 'Redazione salvata ma fonti non salvate.', 'error', 'Redazioni' );
                                } )
                                : Promise.resolve();
                        return afterSources.then( function () {
                                toast( editId > 0 ? 'Redazione aggiornata.' : 'Redazione creata.', 'success', 'Redazioni' );
                                resetRedazioneForm();
                                return loadRedazioniPage();
                        } );
                } ).catch( function ( err ) {
                        apiErrorToast( err, 'Salvataggio redazione non riuscito' );
                } ).then( function () {
                        setBtnLoading( saveBtn, false );
                } );
        }

        /**
         * v1.1.1: binding della pagina Redazioni, eseguito UNA sola volta
         * (guardia redazioniBound): il loader viene richiamato dopo ogni
         * operazione riuscita e i listener delegati su nodi statici (albero,
         * form) sopravvivono al re-render dell'innerHTML.
         */
        function bindRedazioniPage() {
                var tree = qs( '#nxa-red-tree' );
                if ( tree ) {
                        tree.addEventListener( 'click', function ( ev ) {
                                var target = ev.target;
                                var toggleBtn = target.closest ? target.closest( '.nxa-red-toggle' ) : null;
                                if ( toggleBtn && toggleBtn.tagName === 'BUTTON' ) {
                                        var toggleId = num( toggleBtn.getAttribute( 'data-id' ) );
                                        var collapsed = ! redCollapsed[ toggleId ];
                                        redCollapsed[ toggleId ] = collapsed;
                                        toggleBtn.setAttribute( 'aria-expanded', collapsed ? 'false' : 'true' );
                                        toggleBtn.textContent = collapsed ? '▸' : '▾';
                                        applyRedazioniVisibility();
                                        return;
                                }
                                var editBtn = target.closest ? target.closest( '.nxa-red-edit' ) : null;
                                if ( editBtn ) {
                                        var editId = num( editBtn.getAttribute( 'data-id' ) );
                                        var term = redazioniTermById( editId );
                                        if ( term ) {
                                                openRedazioneEditor( term );
                                        } else {
                                                toast( 'Redazione non trovata: ricarica la pagina.', 'error', 'Redazioni' );
                                        }
                                        return;
                                }
                                var delBtn = target.closest ? target.closest( '.nxa-red-del' ) : null;
                                if ( delBtn ) {
                                        confirmDeleteRedazione( delBtn );
                                        return;
                                }
                                var viewBtn = target.closest ? target.closest( '.nxa-red-view' ) : null;
                                if ( viewBtn ) {
                                        openRedazioneArchive( viewBtn.getAttribute( 'data-slug' ) );
                                }
                        } );
                }
                var form = qs( '#nxa-red-form' );
                if ( form ) {
                        form.addEventListener( 'submit', onRedazioneFormSubmit );
                        var cancelBtn = qs( '#nxa-red-cancel' );
                        if ( cancelBtn ) {
                                cancelBtn.addEventListener( 'click', function () {
                                        resetRedazioneForm();
                                        fillRedazioneFormOptions( redazioniCache.list || [], agentsCache.list || [], null );
                                } );
                        }
                }
        }

        /**
         * v1.1.1: loader della pagina Redazioni (voce "redazioni" in LOADERS).
         * Fetch in parallelo di GET /redazioni e GET /agents, poi statistiche,
         * albero, stato vuoto e opzioni del form. Markup T-01 non presente
         * (altre pagine admin) → exit silenzioso come da pattern del file.
         */
        function loadRedazioniPage() {
                var tree = qs( '#nxa-red-tree' );
                var form = qs( '#nxa-red-form' );
                if ( ! tree && ! form ) {
                        return Promise.resolve();
                }
                if ( ! redazioniBound ) {
                        redazioniBound = true;
                        bindRedazioniPage();
                }
                if ( tree ) {
                        showSkeleton( tree, 3 );
                }
                return Promise.all( [ getRedazioni( true ), getAgents( true ) ] ).then( function ( results ) {
                        var terms = Array.isArray( results[ 0 ] ) ? results[ 0 ] : [];
                        var agents = Array.isArray( results[ 1 ] ) ? results[ 1 ] : [];
                        renderRedazioniStats( terms );
                        renderRedazioniTree( terms );
                        toggleRedazioniEmpty( terms );
                        var editTerm = null;
                        var idField = qs( '#nxa-red-id' );
                        var editId = idField ? num( idField.value ) : 0;
                        if ( editId > 0 ) {
                                editTerm = redazioniTermById( editId );
                                if ( ! editTerm ) {
                                        resetRedazioneForm(); // il termine è stato eliminato altrove
                                }
                        }
                        fillRedazioneFormOptions( terms, agents, editTerm );
                } ).catch( function ( err ) {
                        apiErrorToast( err, 'Impossibile caricare le redazioni' );
                        if ( tree ) {
                                tree.innerHTML = emptyState( '⚠️', 'Impossibile caricare le redazioni', err.message || '' );
                        }
                } );
        }

        /**
         * v1.1.1: chip delle redazioni di un agente per la colonna "Redazioni"
         * in agents.php (markup T-01). Cerca i termini il cui array agents
         * contiene l'agente; se non compare in nessuno resta il testo "—".
         */
        function agentRedazioniChipsHtml( terms, agentId ) {
                var chips = [];
                terms.forEach( function ( term ) {
                        var agents = Array.isArray( term.agents ) ? term.agents : [];
                        for ( var i = 0; i < agents.length; i++ ) {
                                if ( num( pick( agents[ i ], 'id' ) ) === agentId ) {
                                        chips.push( redTermChip( term ) );
                                        break;
                                }
                        }
                } );
                if ( ! chips.length ) {
                        return '—';
                }
                return '<span class="nxa-red-agents-chips">' + chips.join( '' ) + '</span>';
        }

        /**
         * v1.1.1: riempie tutte le celle .nxa-agent-redazioni con data-agent-id
         * presenti nel documento (marcatura PHP T-01 e card generate da
         * agentCardHtml). Usa SOLO la cache pagina: la fetch parte una volta
         * sola nel loader "agents". Cache mai popolata (fetch non riuscita o
         * non ancora partita) → exit senza toccare il markup, mai "—" di
         * comodo che fingerebbe dati.
         */
        function refreshAgentRedazioniCells() {
                if ( redazioniCache.list === null ) {
                        return;
                }
                var terms = redazioniCache.list;
                qsa( '.nxa-agent-redazioni[data-agent-id]' ).forEach( function ( cell ) {
                        var agentId = num( cell.getAttribute( 'data-agent-id' ) );
                        cell.innerHTML = agentRedazioniChipsHtml( terms, agentId );
                } );
        }

        /* ==================================================================
         * 13-quater. AVVII MIRATI (v1.1.5)
         * ==================================================================
         *
         * Bottoni "Avvia" (contratto v1.1.5 §5.1, endpoint esteso T-03 §4.1):
         *  - [data-run-agent]     → POST pipeline/run { agent_id: id }
         *    (card agente: agentCardHtml qui sopra + fallback noscript in
         *    agents.php, dove il bottone resta disabilitato in sola lettura);
         *  - [data-run-redazione] → POST pipeline/run { redazione_id: id }
         *    (riga della pagina Redazioni: template redazioneRowHtml);
         *  - [data-run-testata]   → POST pipeline/run { agent_id: 0 }
         *    (dashboard "Avvia testata", window.confirm con N agenti).
         *
         * UN SOLO listener delegato su document con guardia di binding
         * (stesso approccio della guardia redazioniBound): i bottoni vengono
         * rigenerati dai re-render (loadAgents, loadRedazioniPage) senza mai
         * duplicare i listener. Il bottone storico .nxa-run-pipeline resta
         * intatto con il suo handler in loadDashboard.
         *
         * Risposta: shape §4.1 (results[] + summary) → toast "Avvio
         * completato: N agenti · X raccolti · Y pubblicati · Z errori" e
         * blocco .nxa-run-summary nella dashboard; results[] presente ma nel
         * formato v1.1.4 (righe success/agent_name, summary assente) →
         * riepilogo degradato onesto con riusciti e falliti; results[]
         * assente → chiavi BC top-level, identiche a quelle del vecchio
         * handler. Errori sempre in apiErrorToast; a fine run si ricaricano
         * SOLO i dati della pagina corrente (RUN_REFRESH: funzioni dati dei
         * LOADERS senza i bind, che duplicherebbero listener su nodi statici).
         */

        var runButtonsBound = false;

        /** Mappa scope risposta §4.1 → etichetta IT del blocco riepilogo. */
        function runScopeLabel( scope ) {
                var map = {
                        testata:   'Intera testata',
                        redazione: 'Redazione',
                        agent:     'Singolo agente',
                        agents:    'Più agenti'
                };
                return map[ String( scope || '' ).toLowerCase() ] || 'Avvio';
        }

        /** Esito booleano di UNA riga results[]: accetta ok (§4.1) o success (v1.1.4). */
        function runResultOk( r ) {
                if ( ! r || typeof r !== 'object' ) {
                        return false;
                }
                if ( r.ok === true ) {
                        return true;
                }
                if ( r.ok === false ) {
                        return false;
                }
                if ( r.success === true ) {
                        return true;
                }
                if ( r.success === false ) {
                        return false;
                }
                return true;
        }

        /** Testo errore di UNA riga: error (§4.1) o result stringato (v1.1.4). */
        function runResultErrorText( r ) {
                if ( ! r || typeof r !== 'object' ) {
                        return '';
                }
                var e = String( pick( r, 'error' ) || '' );
                if ( e ) {
                        return e;
                }
                if ( r.success === false && r.result && typeof r.result === 'string' ) {
                        return r.result;
                }
                return '';
        }

        /** Riga del blocco .nxa-run-summary: esito e contatori di UN agente. */
        function runSummaryRowHtml( row ) {
                var r = row || {};
                var id = num( pick( r, 'agent_id', 'agentId' ) );
                var name = String( pick( r, 'name' ) || pick( r, 'agent_name' ) || agentNameById( id ) || ( 'Agente #' + id ) );
                var errorText = runResultErrorText( r );
                var ok = runResultOk( r );
                var hasCounts = pick( r, 'collected' ) !== undefined || pick( r, 'evaluated' ) !== undefined ||
                        pick( r, 'rewritten' ) !== undefined || pick( r, 'published' ) !== undefined;
                var stats = hasCounts
                        ? ' <span class="nxa-run-summary-stats">Raccolti ' + fmt( pick( r, 'collected' ) ) +
                                ' · Valutati ' + fmt( pick( r, 'evaluated' ) ) +
                                ' · Riscritti ' + fmt( pick( r, 'rewritten' ) ) +
                                ' · Pubblicati ' + fmt( pick( r, 'published' ) ) + '</span>'
                        : '';
                return '<div class="nxa-run-summary-row">' +
                                '<strong>' + esc( name ) + '</strong> ' +
                                ( ok
                                        ? '<span class="nxa-badge nxa-badge--success">OK</span>'
                                        : '<span class="nxa-badge nxa-badge--error">Errore</span>' ) +
                                stats +
                                ( errorText ? '<span class="nxa-run-summary-error">' + esc( errorText ) + '</span>' : '' ) +
                        '</div>';
        }

        /**
         * Blocco .nxa-run-summary con dettaglio per agente: dove ha senso,
         * cioè nella dashboard (sezione attività). Le altre pagine si
         * affidano al toast riepilogo; markup assente → exit silenzioso.
         */
        function renderRunSummaryBlock( data ) {
                var section = qs( '.nxa-section-activity' );
                if ( ! section || ! data || ! Array.isArray( data.results ) || ! data.results.length ) {
                        return;
                }
                qsa( '.nxa-run-summary', section ).forEach( function ( old ) {
                        if ( old.parentNode ) {
                                old.parentNode.removeChild( old );
                        }
                } );
                var html = '<div class="nxa-run-summary" role="status">' +
                                '<p class="nxa-run-summary-title">Esito avvio — ' + esc( runScopeLabel( pick( data, 'scope' ) ) ) + '</p>' +
                                data.results.map( runSummaryRowHtml ).join( '' ) +
                        '</div>';
                var heading = qs( 'h2', section );
                if ( heading && heading.insertAdjacentHTML ) {
                        heading.insertAdjacentHTML( 'afterend', html );
                } else {
                        section.insertAdjacentHTML( 'afterbegin', html );
                }
        }

        /**
         * Toast di esito. Tree di shape in ordine di preferenza, tutto
         * difensivo: (1) §4.1 con summary o righe name/ok → riepilogo
         * contrattuale (summary quando c'è, altrimenti ricalcolato dalle
         * righe); (2) results[] formato v1.1.4 → riusciti e falliti; (3)
         * results[] assente → chiavi BC del vecchio handler. results[]
         * vuoto → messaggio onesto della risposta senza contatori a zero.
         */
        function announceRunOutcome( data ) {
                var d = data || {};
                var results = Array.isArray( d.results ) ? d.results : null;

                if ( ! results ) {
                        /* BC v1.1.4: nessuna results[], stesse chiavi del vecchio handler. */
                        toast(
                                'Raccolte: ' + num( pick( d, 'collected', 'collected_count' ) ) +
                                ' · Valutate: ' + num( pick( d, 'evaluated', 'evaluated_count' ) ) +
                                ' · Riscritte: ' + num( pick( d, 'rewritten', 'rewritten_count' ) ) +
                                ' · Pubblicate: ' + num( pick( d, 'published', 'published_count' ) ),
                                'success', 'Pipeline completata'
                        );
                        return;
                }

                if ( ! results.length ) {
                        /* Redazione senza agenti (T-03: ok + messaggio chiaro). */
                        toast( String( pick( d, 'message' ) || 'Nessun agente da avviare in questo ambito.' ), 'info', 'Avvio' );
                        return;
                }

                var isNewShape = !!( d.summary && typeof d.summary === 'object' );
                if ( ! isNewShape ) {
                        for ( var i = 0; i < results.length; i++ ) {
                                if ( results[ i ] && ( results[ i ].name !== undefined || results[ i ].ok !== undefined ) ) {
                                        isNewShape = true;
                                        break;
                                }
                        }
                }

                if ( isNewShape ) {
                        var summary = ( d.summary && typeof d.summary === 'object' ) ? d.summary : null;
                        var agents = summary ? num( pick( summary, 'agents' ) ) : results.length;
                        var collected = 0;
                        var published = 0;
                        var errors = 0;
                        if ( summary ) {
                                collected = num( pick( summary, 'collected' ) );
                                published = num( pick( summary, 'published' ) );
                                errors = num( pick( summary, 'errors' ) );
                        } else {
                                results.forEach( function ( r ) {
                                        collected += num( pick( r, 'collected' ) );
                                        published += num( pick( r, 'published' ) );
                                        if ( ! runResultOk( r ) ) {
                                                errors++;
                                        }
                                } );
                        }
                        toast(
                                'Avvio completato: ' + fmt( agents ) + ( agents === 1 ? ' agente' : ' agenti' ) +
                                ' · ' + fmt( collected ) + ( collected === 1 ? ' raccolto' : ' raccolti' ) +
                                ' · ' + fmt( published ) + ( published === 1 ? ' pubblicato' : ' pubblicati' ) +
                                ' · ' + fmt( errors ) + ( errors === 1 ? ' errore' : ' errori' ),
                                errors > 0 ? 'info' : 'success', 'Redazione al lavoro'
                        );
                        renderRunSummaryBlock( d );
                        return;
                }

                /* results[] formato v1.1.4 (success/agent_name, senza contatori):
                 * riepilogo degradato onesto con riusciti e falliti. */
                var okCount = 0;
                results.forEach( function ( r ) {
                        if ( runResultOk( r ) ) {
                                okCount++;
                        }
                } );
                var errCount = results.length - okCount;
                toast(
                        'Avvio completato: ' + fmt( results.length ) + ( results.length === 1 ? ' agente' : ' agenti' ) +
                        ' · ' + fmt( okCount ) + ( okCount === 1 ? ' riuscito' : ' riusciti' ) +
                        ( errCount ? ' · ' + fmt( errCount ) + ( errCount === 1 ? ' fallito' : ' falliti' ) : '' ),
                        errCount > 0 ? 'info' : 'success', 'Redazione al lavoro'
                );
                renderRunSummaryBlock( d );
        }

        /**
         * Refresh dei SOLO dati della pagina corrente a fine run. Usa le
         * funzioni dati invocate dai LOADERS SENZA rieseguire i bind:
         * bindAgents/bindQueue/loadDashboard aggiungono listener su nodi
         * statici (griglia, toolbar, form) e rieseguirli duplicherebbe i
         * click handler. Pagine senza loader dati → nessun refresh.
         */
        var RUN_REFRESH = {
                dashboard: function () { return refreshDashboardData(); },
                agents: function () { return loadAgents( true ); },
                queue: loadQueueList,
                activity: loadActivity,
                redazioni: loadRedazioniPage,
                wire: loadWireRuns
        };

        function refreshCurrentPageData() {
                var pageEl = qs( '.wrap.nexus-admin[data-page]' ) || qs( '[data-page]' );
                var page = pageEl ? String( pageEl.getAttribute( 'data-page' ) || '' ).trim() : '';
                var refresh = RUN_REFRESH[ page ];
                if ( ! refresh ) {
                        return Promise.resolve();
                }
                return Promise.resolve().then( refresh ).catch( function ( err ) {
                        window.console.warn( '[Nexus] refresh post-run "' + page + '":', err );
                } );
        }

        /**
         * POST pipeline/run condiviso dai tre bottoni: bottone .is-busy +
         * spinner (setBtnLoading) durante l'attesa, esito in announceRunOutcome,
         * errori in apiErrorToast, poi refresh dei dati della pagina corrente
         * e hint immediato alla strip live. Mai eccezioni verso l'utente.
         */
        function runPipeline( body, btn, loadingLabel ) {
                setBtnLoading( btn, true, loadingLabel || 'Avvio…' );
                if ( btn.classList ) {
                        btn.classList.add( 'is-busy' );
                }
                api( 'pipeline/run', { method: 'POST', body: body } ).then( function ( payload ) {
                        announceRunOutcome( unwrap( payload ) || {} );
                        refreshCurrentPageData();
                        liveTickSoon();
                } ).catch( function ( err ) {
                        apiErrorToast( err, 'Avvio non riuscito' );
                } ).then( function () {
                        setBtnLoading( btn, false );
                        if ( btn.classList ) {
                                btn.classList.remove( 'is-busy' );
                        }
                } );
        }

        /**
         * N agenti per il confirm "Avvia testata": dal conteggio già caricato
         * (cache pagina getAgents); cache vuota → una fetch; errore → 0 e la
         * conferma usa il testo generico (l'azione non viene mai bloccata).
         */
        function testataAgentCount() {
                if ( agentsCache.list && agentsCache.list.length ) {
                        return Promise.resolve( agentsCache.list.length );
                }
                return getAgents( true ).then( function ( agents ) {
                        return Array.isArray( agents ) ? agents.length : 0;
                } ).catch( function () {
                        return 0;
                } );
        }

        /**
         * Binding delegato singolo dei bottoni Avvia (chiamato a fine sezione:
         * listener su document, non dipende dal DOM già pronto). Il ramo
         * testata chiede conferma PRIMA di qualsiasi richiesta; N arriva
         * dalla cache agenti se già caricata, altrimenti da una fetch.
         */
        function bindRunButtons() {
                if ( runButtonsBound ) {
                        return;
                }
                runButtonsBound = true;
                document.addEventListener( 'click', function ( ev ) {
                        var btn = ( ev.target && ev.target.closest )
                                ? ev.target.closest( '[data-run-agent],[data-run-redazione],[data-run-testata]' )
                                : null;
                        if ( ! btn || btn.disabled ) {
                                return;
                        }
                        var agentId = num( btn.getAttribute( 'data-run-agent' ) );
                        if ( agentId > 0 ) {
                                runPipeline( { agent_id: agentId }, btn, 'Avvio…' );
                                return;
                        }
                        var redazioneId = num( btn.getAttribute( 'data-run-redazione' ) );
                        if ( redazioneId > 0 ) {
                                runPipeline( { redazione_id: redazioneId }, btn, 'Avvio redazione…' );
                                return;
                        }
                        if ( btn.getAttribute( 'data-run-testata' ) !== null ) {
                                testataAgentCount().then( function ( count ) {
                                        var message = count > 0
                                                ? nxaT( 'Avviare l’intera testata? Verranno avviati ' ) + fmt( count ) + nxaT( ' agenti.' )
                                                : nxaT( 'Avviare l’intera testata? Verranno avviati tutti gli agenti.' );
                                        if ( ! window.confirm( message ) ) {
                                                return;
                                        }
                                        runPipeline( { agent_id: 0 }, btn, 'Testata in corso…' );
                                } );
                        }
                } );
        }

        bindRunButtons();

        // v1.1.7: chiusura modali statiche delle viste ([data-nxa-close-modal]).
        bindStaticModalClosers();

        /* ==================================================================
         * 13-quinquies. LIVE REDAZIONALE (v1.1.5)
         * ==================================================================
         *
         * Pannello "In redazione ora" (#nxa-live-strip, markup T-04 nella
         * dashboard): GET /live (contratto T-03 §4.3, shape FROZEN) al
         * caricamento e polling ogni 5 secondi SOLO con la dashboard visibile.
         *  - pausa su visibilitychange hidden, ripresa su visible;
         *  - MAI polling da altre pagine: l'init esce subito se la strip non
         *    esiste (la dashboard è l'unica pagina che la contiene);
         *  - rendering difensivo: ogni campo mancante degrada a testo onesto
         *    ("In attesa di avvio", "Ultima azione: …"), mai eccezioni;
         *  - /live 404 (plugin v1.1.4) → strip nascosta in silenzio e polling
         *    fermato: la route non apparirà a caldo, inutile insistere;
         *  - altri errori → riga discreta nella strip, MAI toast (il polling
         *    ogni 5 secondi non deve fare spam di notifiche).
         *
         * Classi consumate dal CSS (T-01): .nxa-live-strip, .nxa-live-agent,
         * .nxa-live-dot (+ --running|--paused|--error|--idle), .nxa-live-step,
         * .nxa-live-last, .nxa-live-updated.
         */

        var LIVE_POLL_MS = 5000;
        var liveTimer = null;
        var liveInFlight = false;
        var liveStopped = false;
        var liveBound = false;

        function liveStripEl() {
                return qs( '#nxa-live-strip' );
        }

        /** Variante del pallino da running/status (running prima di tutto). */
        function liveDotVariant( agent ) {
                var a = agent || {};
                if ( a.running ) {
                        return 'nxa-live-dot--running';
                }
                var status = String( pick( a, 'status' ) || '' ).toLowerCase();
                if ( status === 'paused' ) {
                        return 'nxa-live-dot--paused';
                }
                if ( status === 'error' ) {
                        return 'nxa-live-dot--error';
                }
                return 'nxa-live-dot--idle';
        }

        /** Fase corrente: current.label + titolo articolo, altrimenti attesa. */
        function liveStepText( agent ) {
                var current = ( agent && agent.current && typeof agent.current === 'object' ) ? agent.current : null;
                if ( ! current ) {
                        return 'In attesa di avvio';
                }
                var label = String( pick( current, 'label' ) || '' );
                var title = String( pick( current, 'article_title', 'articleTitle' ) || '' );
                if ( label && title ) {
                        return label + ' — ' + title;
                }
                return label || title || 'In attesa di avvio';
        }

        /** Ultima azione con tempo umano it-IT (riusa timeAgoIt del file). */
        function liveLastText( agent ) {
                var last = ( agent && agent.last_action && typeof agent.last_action === 'object' ) ? agent.last_action : null;
                if ( ! last ) {
                        return '';
                }
                var detail = String( pick( last, 'detail' ) || '' ).trim();
                var when = String( timeAgoIt( pick( last, 'created_at', 'createdAt' ) ) );
                var hasWhen = when && when !== 'Mai';
                if ( detail && hasWhen ) {
                        return detail + ' · ' + when;
                }
                if ( detail ) {
                        return detail;
                }
                return hasWhen ? 'Ultima azione: ' + when : '';
        }

        /** "HH:MM" dall'ISO della risposta (fallback: adesso), it-IT. */
        function liveClock( iso ) {
                var date = iso ? new Date( String( iso ).replace( ' ', 'T' ) ) : new Date();
                if ( isNaN( date.getTime() ) ) {
                        date = new Date();
                }
                try {
                        return date.toLocaleTimeString( 'it-IT', { hour: '2-digit', minute: '2-digit' } );
                } catch ( e ) {
                        var hh = date.getHours();
                        var mm = date.getMinutes();
                        return ( hh < 10 ? '0' : '' ) + hh + ':' + ( mm < 10 ? '0' : '' ) + mm;
                }
        }

        /** Riga agente della strip (difensiva: campi mancanti → default). */
        function liveAgentRowHtml( agent ) {
                var a = agent || {};
                var avatar = String( pick( a, 'avatar' ) || '🤖' );
                var name = String( pick( a, 'name' ) || ( 'Agente #' + num( pick( a, 'id' ) ) ) );
                var last = liveLastText( a );
                return '<div class="nxa-live-agent">' +
                                '<span class="nxa-live-dot ' + liveDotVariant( a ) + '" aria-hidden="true"></span>' +
                                '<span class="nxa-live-avatar" aria-hidden="true">' + esc( avatar ) + '</span>' +
                                '<strong class="nxa-live-name">' + esc( name ) + '</strong>' +
                                '<span class="nxa-live-step">' + esc( liveStepText( a ) ) + '</span>' +
                                ( last ? '<span class="nxa-live-last">' + esc( last ) + '</span>' : '' ) +
                        '</div>';
        }

        /**
         * Render della strip: intestazione .nxa-live-updated ("In redazione
         * ora: N agenti al lavoro · aggiornato alle HH:MM", N calcolato dai
         * dati renderizzati, fallback testata.running) + riga per agente,
         * oppure stato vuoto onesto a zero agenti.
         */
        function renderLiveStrip( data ) {
                var strip = liveStripEl();
                if ( ! strip ) {
                        return;
                }
                var d = unwrap( data ) || {};
                var agents = Array.isArray( d.agents ) ? d.agents : [];
                var testata = ( d.testata && typeof d.testata === 'object' ) ? d.testata : null;
                var running = [];
                var idleCount = 0;
                var pausedCount = 0;
                var errorCount = 0;
                agents.forEach( function ( a ) {
                        if ( a && a.running ) {
                                running.push( a );
                                return;
                        }
                        var status = String( pick( a, 'status' ) || '' );
                        if ( status === 'error' ) {
                                errorCount++;
                        } else if ( status === 'paused' ) {
                                pausedCount++;
                        } else {
                                idleCount++;
                        }
                } );
                var runningCount = running.length;
                if ( ! agents.length && testata && pick( testata, 'running' ) !== undefined ) {
                        runningCount = num( pick( testata, 'running' ) );
                }
                var html = '<p class="nxa-live-updated">In redazione ora: ' + fmt( runningCount ) +
                        ( runningCount === 1 ? ' agente al lavoro' : ' agenti al lavoro' ) +
                        ' · aggiornato alle ' + esc( liveClock( pick( d, 'updated_at', 'updatedAt' ) ) ) + '</p>';
                // v1.1.6 ("dashboard doppia"): in dettaglio SOLO gli agenti al
                // lavoro; gli altri compattati in una riga di conteggio, così la
                // strip non replica la lista completa già presente in "Stato agenti".
                if ( running.length ) {
                        html += running.map( liveAgentRowHtml ).join( '' );
                }
                var parts = [];
                if ( idleCount ) {
                        parts.push( fmt( idleCount ) + ' in attesa' );
                }
                if ( pausedCount ) {
                        parts.push( fmt( pausedCount ) + ' in pausa' );
                }
                if ( errorCount ) {
                        parts.push( fmt( errorCount ) + ' in errore' );
                }
                if ( parts.length ) {
                        html += '<p class="nxa-live-last">Altri agenti: ' + esc( parts.join( ' · ' ) ) + '.</p>';
                }
                if ( ! agents.length ) {
                        html += emptyState( '🤖', 'Nessun agente configurato', 'Crea gli agenti dalla pagina Agenti per vedere la redazione al lavoro.' );
                } else if ( ! running.length && ! parts.length ) {
                        html += '<p class="nxa-live-last">Nessun agente al lavoro.</p>';
                }
                strip.hidden = false;
                strip.innerHTML = html;
        }

        /** Nasconde la strip (endpoint assente): nessun titolo orfano resta. */
        function hideLiveStrip() {
                var strip = liveStripEl();
                if ( strip ) {
                        strip.hidden = true;
                        strip.innerHTML = '';
                }
        }

        function stopLivePolling() {
                if ( liveTimer ) {
                        window.clearInterval( liveTimer );
                        liveTimer = null;
                }
        }

        /**
         * Una lettura /live: guardie anti-sovrapposizione (liveInFlight),
         * anti-polling a tab nascosta (document.hidden) e anti-polling dopo
         * lo stop (liveStopped). Errori gestiti in handleLiveError.
         */
        function liveTick() {
                if ( liveInFlight || liveStopped || document.hidden ) {
                        return;
                }
                var strip = liveStripEl();
                if ( ! strip ) {
                        liveStopped = true;
                        stopLivePolling();
                        return;
                }
                liveInFlight = true;
                api( 'live' ).then( function ( payload ) {
                        liveInFlight = false;
                        renderLiveStrip( payload );
                } ).catch( function ( err ) {
                        liveInFlight = false;
                        handleLiveError( err );
                } );
        }

        /** Hint post-run: una lettura immediata della strip, se è attiva. */
        function liveTickSoon() {
                if ( ! liveStopped && liveStripEl() ) {
                        liveTick();
                }
        }

        function handleLiveError( err ) {
                if ( err && err.status === 404 ) {
                        liveStopped = true;
                        stopLivePolling();
                        hideLiveStrip();
                        window.console.warn( '[Nexus] live: endpoint /live non disponibile, pannello nascosto.' );
                        return;
                }
                var strip = liveStripEl();
                if ( ! strip ) {
                        return;
                }
                strip.innerHTML = '<p class="nxa-live-updated">In redazione ora: stato non disponibile (' +
                        esc( ( err && err.message ) ? err.message : 'errore di rete' ) + ')</p>';
        }

        function startLivePolling() {
                if ( liveTimer || liveStopped ) {
                        return;
                }
                liveTick(); // prima lettura immediata
                liveTimer = window.setInterval( liveTick, LIVE_POLL_MS );
        }

        function onLiveVisibility() {
                if ( document.hidden ) {
                        stopLivePolling(); // pausa: zero richieste a tab nascosta
                } else if ( ! liveStopped && liveStripEl() ) {
                        startLivePolling(); // ripresa immediata al ritorno
                }
        }

        /**
         * Init del pannello live: SOLO se #nxa-live-strip esiste (dashboard).
         * Guardia doppia (liveBound + attributo data-nxa-live) contro doppie
         * init; visibilitychange è registrato SOLO qui, quindi le altre
         * pagine non hanno nessun listener e nessun timer.
         */
        function initLiveRedazionale() {
                var strip = liveStripEl();
                if ( ! strip || liveBound ) {
                        return;
                }
                liveBound = true;
                strip.setAttribute( 'data-nxa-live', 'on' );
                showSkeleton( strip, 2 ); // copre la prima fetch
                document.addEventListener( 'visibilitychange', onLiveVisibility );
                startLivePolling();
        }

        /* Boot locale del pannello live: stesso dual-path del boot principale
         * (il listener DOMContentLoaded è registrato dopo quello del boot e
         * parte quindi dopo; script già oltre "loading" → init immediato). */
        if ( document.readyState === 'loading' ) {
                document.addEventListener( 'DOMContentLoaded', initLiveRedazionale );
        } else {
                initLiveRedazionale();
        }

        /* ==================================================================
         * 14. ROUTER + BOOT
         * ================================================================== */

        var LOADERS = {
                dashboard: loadDashboard,
                agents: function () {
                        bindAgents();
                        // v1.1.1: una sola fetch GET /redazioni per pagina; dopo il
                        // render agenti riempie le celle .nxa-agent-redazioni (T-01)
                        // con i chip delle redazioni di ciascun agente.
                        return loadAgents( false ).then( function () {
                                return getRedazioni().then( function () {
                                        refreshAgentRedazioniCells();
                                } ).catch( function ( err ) {
                                        window.console.warn( '[Nexus] redazioni (colonna agenti):', err );
                                } );
                        } );
                },
                queue: function () {
                        bindQueue();
                        return loadQueueList();
                },
                activity: loadActivity,
                settings: bindSettings,
                tools: function () {
                        // v1.1.0: migrazione contenuti nativi (card Strumenti, T-01);
                        // bottone #nxa-native-run assente → exit silenzioso.
                        bindNativeMigration();
                        // v1.1.7 (T-B2): card "Test degli strumenti" (POST /tools/test,
                        // promessa v1.1.4); markup assente → exit silenzioso.
                        bindToolsTest();
                },
                // v1.1.7 (T-B2): viste self-contained con script inline proprio
                // (jobs.php, analytics.php): il boot non deve stampare il warn
                // "pagina sconosciuta" — loader no-op, nessun binding condiviso.
                jobs: function () {
                        return null;
                },
                analytics: function () {
                        return null;
                },
                redazioni: function () {
                        // v1.1.1: pagina "Redazioni" (markup T-01, REST T-02) —
                        // albero gerarchico + form; markup assente → exit silenzioso.
                        return loadRedazioniPage();
                },
                wire: function () {
                        bindWire();
                        return loadWireRuns();
                }
        };

        var booted = false;

        /**
         * Traduzione difensiva per stringhe fuori dal DOM (window.confirm,
         * window.alert): usa window.NexusI18nTranslate quando i18n.js è attivo
         * (v1.1.6), altrimenti ritorna il testo originale. I frammenti tradotti
         * devono esistere come chiavi ESATTE nel dizionario.
         */
        function nxaT( text ) {
                if ( typeof window.NexusI18nTranslate === 'function' ) {
                        var translated = window.NexusI18nTranslate( text );
                        if ( translated ) {
                                return translated;
                        }
                }
                return text;
        }

        function boot() {
                // v1.1.6 ("dashboard doppia"): il boot può girare UNA sola volta
                // per pagina (dominio di esecuzione singolo, nessun doppio loader).
                if ( booted ) {
                        return;
                }
                booted = true;
                injectFallbackCss();
                ensureToastStack();

                var pageEl = qs( '.wrap.nexus-admin[data-page]' ) || qs( '[data-page]' );
                var page = pageEl ? String( pageEl.getAttribute( 'data-page' ) || '' ).trim() : '';

                if ( ! page ) {
                        window.console.warn( '[Nexus News AI] Nessun data-page trovato nel DOM: nessun pannello da inizializzare.' );
                        return;
                }
                var loader = LOADERS[ page ];
                if ( ! loader ) {
                        window.console.warn( '[Nexus News AI] Pagina admin sconosciuta: "' + page + '"' );
                        return;
                }

                Promise.resolve()
                        .then( loader )
                        .catch( function ( err ) {
                                window.console.warn( '[Nexus News AI] Errore di inizializzazione "' + page + '":', err );
                                toast( 'Errore durante il caricamento del pannello: ' + ( err && err.message ? err.message : 'riprova ricaricando la pagina.' ),
                                        'error', 'Nexus News AI' );
                        } );
        }

        /* ==================================================================
         * v1.1.11 (R5) — Model fetch guard
         *
         * Il server GET /ai/models ora porta flag onesti nel payload
         * (fetch_disabled / stale / missing_key, guardia models_fetch_enabled):
         * qui li rendiamo visibili SENZA toccare il rendering del picker WP12.
         * Si estende SOLO fetchProviderModels (riassegnata in coda: i closure
         * che la richiamano — load() dei picker e loadAiModels — risolvono il
         * binding a call time, quindi vedono il wrapper; la logica di rendering
         * esistente state.err / apiErrorToast / setModelsStatus fa il resto).
         *
         * - fetch_disabled + lista vuota → l'open del picker e il bottone
         *   "Carica modelli" mostrano il messaggio onesto qui sotto (rosso
         *   nello stato vuoto del picker, toast sul bottone) invece del
         *   fuorviante "Nessun modello trovato".
         * - fetch_disabled con cache → la lista cache passa intatta: lo status
         *   accanto al bottone aggiunge "(fetch disattivato)" (loadAiModels).
         * - stale → la lista scaduta (fail-open server) passa intatta: lo
         *   status aggiunge "(cache scaduta: il provider non ha risposto)".
         * - missing_key → errore 400 del server: i messaggi onesti esistenti
         *   bastano, il flag resta disponibile per usi futuri.
         * ================================================================== */
        var r5FetchProviderModels = fetchProviderModels;
        fetchProviderModels = function ( provider, force ) {
                return r5FetchProviderModels( provider, force ).then( function ( res ) {
                        if ( res && res.fetch_disabled && ( ! res.list || ! res.list.length ) ) {
                                var err = new Error( 'Caricamento modelli disattivato dalle impostazioni: nessun catalogo in cache.' );
                                err.nexus_r5_fetch_disabled = true;
                                throw err;
                        }
                        return res;
                } );
        };

        if ( document.readyState === 'loading' ) {
                document.addEventListener( 'DOMContentLoaded', boot );
        } else {
                boot();
        }

        /* ==================================================================
         * 15. WIZARD DI ONBOARDING — v1.1.11 (R3) — Onboarding wizard
         * ==================================================================
         *
         * Dalla v1.1.11 il plugin si attiva con ZERO agenti (Nexus_DB::seed_agents()
         * è un no-op BC): questo wizard accoglie la redazione vuota con pacchetti
         * preset one-click, riprendendo ESATTAMENTE le stesse righe di AGENT_PRESETS
         * della barra "Creazione rapida" (v1.1.4). Tutto personalizzabile dopo:
         * ogni agente creato qui resta modificabile dal modal editor.
         *
         * Markup: admin/views/onboarding-wizard.php (partial incluso da agents.php,
         * contratto nel suo docblock). Dashboard: SOLO card CTA con link
         * admin.php?page=nexus-agents#nxa-onboarding (nessun wizard lì).
         *
         * Trigger di apertura:
         *  - primo render della pagina Agenti con 0 agenti (nxaOnboardingAutoOpen,
         *    chiamato da loadAgents: UNA sola volta per vista, così la chiusura
         *    manuale o l'eliminazione dell'ultimo agente non riaprono il modal);
         *  - hash #nxa-onboarding (card CTA della dashboard / link diretti);
         *  - click su .nxa-onboarding-open (empty state della griglia, delega
         *    document UNICA registrata qui sotto).
         *
         * Esecuzione (step 2 → 3 → 4): sequenza di POST /agents con i campi del
         * preset (il REST non espone un campo "preset": qui si replica il contratto
         * della barra creazione rapida — copia della riga AGENT_PRESETS + status
         * "active", il server sanifica con la sua whitelist). Progresso onesto
         * ("Creo agente X di N…"), una riga di log per agente, riepilogo finale
         * con i messaggi d'errore REALI (es. categoria già in uso). In coda:
         * opzionale PUT /settings con la chiave `global_rss_sources` (STRINGA,
         * una URL per riga — contratto con la branch fonti R1; se il campo non è
         * ancora in whitelist lato REST l'errore viene mostrato nel riepilogo
         * senza bloccare il resto). Poi loadAgents(true) per ricaricare la griglia.
         *
         * i18n: le stringhe fisse hanno le chiavi ESATTE nei dizionari; le frasi
         * con numeri passano dai pattern (class-nexus-i18n-dict-extra.php).
         * ================================================================== */

        var onboardingAutoShown = false;
        var onboardingRunning   = false;

        /**
         * v1.1.11: empty state del grid agenti con 0 agenti — testo aggiornato
         * (niente "7 agenti predefiniti") + bottone che apre il wizard.
         */
        function nxaOnboardingEmptyHtml() {
                return '<div class="nxa-empty nxa-onboarding-empty">' +
                        '<div class="nxa-empty__icon" aria-hidden="true">🤖</div>' +
                        '<p class="nxa-empty__title">' + esc( nxaT( 'Nessun agente: crea il tuo primo giornalista con un preset o apri il wizard.' ) ) + '</p>' +
                        '<p><button type="button" class="nxa-btn nxa-btn-primary nxa-onboarding-open">' + esc( nxaT( 'Apri il wizard' ) ) + '</button></p>' +
                        '</div>';
        }

        function nxaOnboardingModal() {
                return qs( '#nxa-onboarding' );
        }

        /** Apre il wizard (reset al passo 1; durante un run mostra il progresso live). */
        function nxaOnboardingOpen() {
                var modal = nxaOnboardingModal();
                if ( ! modal ) {
                        return;
                }
                if ( onboardingRunning ) {
                        nxaOnboardingShowStep( 3 );
                } else {
                        nxaOnboardingReset();
                }
                // v1.1.13 (O4): opener salvato SOLO alla prima apertura (auto-open e
                // riaperture da hash non devono sovrascriverlo mentre è visibile).
                if ( modal.hidden ) {
                        nxaModalRememberFocus();
                }
                modal.hidden = false;
                document.body.style.overflow = 'hidden';
        }

        /**
         * v1.1.13 (O4): il wizard NON si chiude più a run in corso — X, Annulla,
         * "Vedi la redazione" e ESC passano tutti da qui, e con onboardingRunning
         * i POST /agents proseguirebbero dietro un modal chiuso (riepilogo perso).
         * Chiusura consentita solo a run concluso o fallito. Al run in corso:
         * toast informativo, il modal resta aperto sul passo 3.
         */
        function nxaOnboardingClose() {
                if ( onboardingRunning ) {
                        toast( nxaT( 'Creazione in corso…' ), 'info', nxaT( 'Crea la tua redazione' ) );
                        return;
                }
                var modal = nxaOnboardingModal();
                if ( modal ) {
                        modal.hidden = true;
                }
                document.body.style.overflow = '';
                // v1.1.13 (O4): il focus torna a chi aveva aperto il wizard.
                nxaModalRestoreFocus();
        }

        /** Ripristina il wizard ai valori iniziali del partial PHP e torna al passo 1. */
        function nxaOnboardingReset() {
                var modal = nxaOnboardingModal();
                if ( ! modal ) {
                        return;
                }
                var first = qs( 'input[name="nxa-obs-preset"]', modal );
                if ( first ) {
                        first.checked = true;
                }
                nxaOnboardingSyncCards();
                var chief = qs( '#nxa-obs-chief', modal );
                if ( chief ) {
                        chief.checked = false;
                }
                var rss = qs( '#nxa-obs-rss', modal );
                if ( rss ) {
                        rss.checked = true;
                }
                var fill = qs( '#nxa-obs-progressbar-fill', modal );
                if ( fill ) {
                        fill.style.width = '0%';
                }
                var log = qs( '#nxa-obs-log', modal );
                if ( log ) {
                        log.innerHTML = '';
                }
                var summary = qs( '#nxa-obs-summary', modal );
                if ( summary ) {
                        summary.innerHTML = '';
                }
                var progress = qs( '#nxa-obs-progress', modal );
                if ( progress ) {
                        progress.textContent = nxaT( 'Creo gli agenti…' );
                }
                nxaOnboardingShowStep( 1 );
        }

        /** Visibilità passi e bottoni per lo step richiesto (1..4). */
        function nxaOnboardingShowStep( step ) {
                var modal = nxaOnboardingModal();
                if ( ! modal ) {
                        return;
                }
                var steps = [ 1, 2, 3, 4 ];
                for ( var i = 0; i < steps.length; i++ ) {
                        var pane = qs( '[data-nxa-obs-step="' + steps[ i ] + '"]', modal );
                        if ( pane ) {
                                pane.hidden = ( steps[ i ] !== step );
                        }
                }
                var isChoice = step === 1 || step === 2;
                var btnContinue = qs( '#nxa-obs-continue', modal );
                var btnBack     = qs( '#nxa-obs-back', modal );
                var btnRun      = qs( '#nxa-obs-run', modal );
                var btnDone     = qs( '#nxa-obs-done', modal );
                var btnCancel   = qs( '#nxa-obs-cancel', modal );
                if ( btnContinue ) {
                        btnContinue.hidden = ! isChoice;
                }
                if ( btnBack ) {
                        btnBack.hidden = step !== 2;
                }
                if ( btnRun ) {
                        btnRun.hidden = step !== 2;
                }
                if ( btnDone ) {
                        btnDone.hidden = step !== 4;
                }
                if ( btnCancel ) {
                        btnCancel.hidden = ! isChoice;
                }
        }

        /** Evidenzia la radio card scelta (classe is-selected, gestita dal JS). */
        function nxaOnboardingSyncCards() {
                var modal = nxaOnboardingModal();
                if ( ! modal ) {
                        return;
                }
                var cards = qsa( '.nxa-obs-card', modal );
                for ( var i = 0; i < cards.length; i++ ) {
                        var input = qs( 'input[name="nxa-obs-preset"]', cards[ i ] );
                        if ( input ) {
                                if ( input.checked ) {
                                        cards[ i ].classList.add( 'is-selected' );
                                } else {
                                        cards[ i ].classList.remove( 'is-selected' );
                                }
                        }
                }
        }

        /**
         * Scelta del passo 1: legge la radio attiva e i suoi data-attribute
         * (data-presets = chiavi preset separate da virgola, data-include-chief).
         * Le chiavi sono quelle di AGENT_PRESETS (stessa grammatica della barra
         * creazione rapida); chiavi sconosciute scartate in silenzio.
         */
        function nxaOnboardingChoice() {
                var modal  = nxaOnboardingModal();
                var checked = modal ? qs( 'input[name="nxa-obs-preset"]:checked', modal ) : null;
                if ( ! checked ) {
                        return null;
                }
                var keys    = String( checked.getAttribute( 'data-presets' ) || '' ).split( ',' );
                var presets = [];
                for ( var i = 0; i < keys.length; i++ ) {
                        var key = keys[ i ].replace( /^\s+|\s+$/g, '' );
                        if ( key && AGENT_PRESETS[ key ] ) {
                                presets.push( key );
                        }
                }
                return {
                        manual:        checked.value === 'manual',
                        presets:       presets,
                        chiefIncluded: checked.getAttribute( 'data-include-chief' ) === '1'
                };
        }

        /**
         * Auto-open dal primo render del grid agenti (loadAgents): apre il wizard
         * SOLO se il primo render ha 0 agenti; in ogni caso la decisione vale per
         * tutta la vista (nessuna riapertura su ricariche successive — es. dopo
         * l'eliminazione di un agente o al termine del wizard stesso).
         */
        function nxaOnboardingAutoOpen( agentsCount ) {
                var shouldOpen = num( agentsCount ) === 0 && ! onboardingAutoShown && ! onboardingRunning;
                onboardingAutoShown = true;
                if ( ! shouldOpen ) {
                        return;
                }
                var modal = nxaOnboardingModal();
                if ( ! modal ) {
                        return;
                }
                nxaOnboardingOpen();
        }

        /**
         * Esecuzione del wizard: sequenza di POST /agents (una promessa per
         * agente, errori NON bloccanti raccolti per il riepilogo) + eventuale
         * PUT /settings { global_rss_sources } (contratto branch R1: stringa
         * una URL per riga). Al termine: riepilogo onesto, loadAgents(true),
         * passo 4.
         */
        function nxaOnboardingRun() {
                var modal  = nxaOnboardingModal();
                var choice = nxaOnboardingChoice();
                if ( ! modal || ! choice || onboardingRunning ) {
                        return;
                }
                if ( choice.manual ) {
                        // "Preferisco crearli a mano": chiude il wizard e basta.
                        nxaOnboardingClose();
                        return;
                }
                var keys = choice.presets.slice();
                var chiefBox = qs( '#nxa-obs-chief', modal );
                if ( ! choice.chiefIncluded && chiefBox && chiefBox.checked ) {
                        keys.push( 'capo' );
                }
                if ( ! keys.length ) {
                        nxaOnboardingClose();
                        return;
                }

                var rssBox  = qs( '#nxa-obs-rss', modal );
                var rssText = qs( '#nxa-obs-rss-textarea', modal );
                var rssValue = rssText ? String( rssText.value || '' ).replace( /^\s+|\s+$/g, '' ) : '';
                var wantRss = !!( rssBox && rssBox.checked && rssValue );

                onboardingRunning = true;
                nxaOnboardingShowStep( 3 );

                var progressEl = qs( '#nxa-obs-progress', modal );
                var fillEl     = qs( '#nxa-obs-progressbar-fill', modal );
                var logEl      = qs( '#nxa-obs-log', modal );
                var total      = keys.length;
                var created    = 0;
                var errors     = [];

                var runOne = function ( i ) {
                        if ( i >= total ) {
                                return Promise.resolve();
                        }
                        var key    = keys[ i ];
                        var preset = AGENT_PRESETS[ key ] || {};
                        var label  = preset.name ? String( preset.name ) : key;
                        if ( progressEl ) {
                                // i18n: pattern dizionario 'Creo agente (\d+) di (\d+)…'.
                                progressEl.textContent = nxaT( 'Creo agente ' + ( i + 1 ) + ' di ' + total + '…' );
                        }
                        if ( fillEl ) {
                                fillEl.style.width = Math.round( ( i / total ) * 100 ) + '%';
                        }
                        var fields = {};
                        for ( var prop in preset ) {
                                if ( Object.prototype.hasOwnProperty.call( preset, prop ) ) {
                                        fields[ prop ] = preset[ prop ];
                                }
                        }
                        fields.status = 'active';
                        return api( 'agents', { method: 'POST', body: fields } ).then( function () {
                                created++;
                                if ( logEl ) {
                                        logEl.innerHTML += '<li class="is-ok">✓ ' + esc( label ) + '</li>';
                                }
                        } ).catch( function ( err ) {
                                var message = ( err && err.message ) ? err.message : nxaT( 'Errore sconosciuto' );
                                errors.push( { name: label, message: message } );
                                if ( logEl ) {
                                        logEl.innerHTML += '<li class="is-err">✕ ' + esc( label ) + ' — ' + esc( message ) + '</li>';
                                }
                        } ).then( function () {
                                return runOne( i + 1 );
                        } );
                };

                runOne( 0 ).then( function () {
                        if ( fillEl ) {
                                fillEl.style.width = '100%';
                        }
                        if ( ! wantRss ) {
                                return 'skip';
                        }
                        if ( progressEl ) {
                                progressEl.textContent = nxaT( 'Salvo le fonti RSS…' );
                        }
                        // Contratto branch R1: global_rss_sources è una STRINGA,
                        // una URL per riga. Se la chiave non è (ancora) in whitelist
                        // lato REST l'errore reale finisce nel riepilogo: mai in silenzio.
                        return api( 'settings', { method: 'PUT', body: { global_rss_sources: rssValue } } )
                                .then( function () { return 'ok'; } )
                                .catch( function ( err ) {
                                        return 'err:' + ( ( err && err.message ) ? err.message : '' );
                                } );
                } ).then( function ( rssResult ) {
                        onboardingRunning = false;
                        if ( progressEl ) {
                                progressEl.textContent = nxaT( 'Creazione completata.' );
                        }
                        var summary = qs( '#nxa-obs-summary', modal );
                        if ( summary ) {
                                var html = '<p class="nxa-obs-summary-head">' + esc( errors.length ? nxaT( 'Creazione completata con errori.' ) : nxaT( 'Creazione completata.' ) ) + '</p>';
                                // i18n: pattern 'Agenti creati: (\d+)' + 'Errori: (\d+)' a catena.
                                html += '<p class="nxa-obs-summary-row">' + esc( nxaT( 'Agenti creati: ' + created + ' · Errori: ' + errors.length ) ) + '</p>';
                                if ( errors.length ) {
                                        html += '<ul class="nxa-obs-summary-errors">';
                                        for ( var i = 0; i < errors.length; i++ ) {
                                                html += '<li>✕ ' + esc( errors[ i ].name ) + ' — ' + esc( errors[ i ].message ) + '</li>';
                                        }
                                        html += '</ul>';
                                }
                                if ( wantRss && rssResult === 'ok' ) {
                                        html += '<p class="nxa-obs-summary-row is-ok">✓ ' + esc( nxaT( 'Fonti RSS salvate nelle impostazioni.' ) ) + '</p>';
                                } else if ( wantRss ) {
                                        html += '<p class="nxa-obs-summary-row is-err">✕ ' + esc( nxaT( 'Fonti RSS non salvate: ' ) + String( rssResult || '' ).replace( /^err:/, '' ) ) + '</p>';
                                }
                                summary.innerHTML = html;
                        }
                        // Griglia dati pagina ricaricata dietro il wizard (stesso
                        // pattern delle altre creazioni: loadAgents(true)).
                        loadAgents( true );
                        nxaOnboardingShowStep( 4 );
                        // i18n: pattern 'Wizard completato: 1 agente creato.' / '(\d+) agenti creati.'.
                        var toastMsg = created > 0
                                ? nxaT( 'Wizard completato: ' + created + ( created === 1 ? ' agente creato.' : ' agenti creati.' ) )
                                : nxaT( 'Nessun agente creato: controlla gli errori nel riepilogo.' );
                        toast( toastMsg, created > 0 ? 'success' : 'error', nxaT( 'Crea la tua redazione' ) );
                } );
        }

        /** Binding del wizard: una sola volta per pagina (guard sul markup). */
        function nxaOnboardingBind() {
                var modal = nxaOnboardingModal();
                if ( ! modal || modal.getAttribute( 'data-nxa-obs-bound' ) === '1' ) {
                        return;
                }
                modal.setAttribute( 'data-nxa-obs-bound', '1' );

                // Selezione radio card → evidenzia la card scelta.
                modal.addEventListener( 'change', function ( ev ) {
                        if ( ev.target && ev.target.name === 'nxa-obs-preset' ) {
                                nxaOnboardingSyncCards();
                        }
                } );

                // Chiusure: X, Annulla (solo nei passi 1-2), "Vedi la redazione", ESC.
                var closeBtn = qs( '[data-nxa-onboarding-close]', modal );
                if ( closeBtn ) {
                        closeBtn.addEventListener( 'click', nxaOnboardingClose );
                }
                var cancelBtn = qs( '#nxa-obs-cancel', modal );
                if ( cancelBtn ) {
                        cancelBtn.addEventListener( 'click', nxaOnboardingClose );
                }
                var doneBtn = qs( '#nxa-obs-done', modal );
                if ( doneBtn ) {
                        doneBtn.addEventListener( 'click', nxaOnboardingClose );
                }
                document.addEventListener( 'keydown', function ( ev ) {
                        // v1.1.13 (O4): Tab confinato nel dialog; ESC passa da
                        // nxaOnboardingClose, che a run in corso non chiude (toast).
                        nxaModalTrapTab( ev, modal );
                        if ( ev.key === 'Escape' && ! modal.hidden ) {
                                nxaOnboardingClose();
                        }
                } );

                // Passo 1 → 2: "a mano" chiude e basta; il capo redattore è
                // proponibile SOLO se non è già incluso nel pacchetto scelto.
                var continueBtn = qs( '#nxa-obs-continue', modal );
                if ( continueBtn ) {
                        continueBtn.addEventListener( 'click', function () {
                                var choice = nxaOnboardingChoice();
                                if ( ! choice ) {
                                        return;
                                }
                                if ( choice.manual ) {
                                        nxaOnboardingClose();
                                        return;
                                }
                                var chiefRow = qs( '#nxa-obs-chief-row', modal );
                                if ( chiefRow ) {
                                        chiefRow.hidden = choice.chiefIncluded;
                                }
                                var chiefBox = qs( '#nxa-obs-chief', modal );
                                if ( chiefBox && choice.chiefIncluded ) {
                                        chiefBox.checked = false;
                                }
                                nxaOnboardingShowStep( 2 );
                        } );
                }
                var backBtn = qs( '#nxa-obs-back', modal );
                if ( backBtn ) {
                        backBtn.addEventListener( 'click', function () {
                                nxaOnboardingShowStep( 1 );
                        } );
                }
                var runBtn = qs( '#nxa-obs-run', modal );
                if ( runBtn ) {
                        runBtn.addEventListener( 'click', nxaOnboardingRun );
                }

                // Bottone "Apri il wizard" (empty state + altri eventuali): delega
                // document UNICA, funziona anche con il grid renderizzato di nuovo.
                document.addEventListener( 'click', function ( ev ) {
                        var opener = ( ev.target && ev.target.closest ) ? ev.target.closest( '.nxa-onboarding-open' ) : null;
                        if ( opener ) {
                                ev.preventDefault();
                                nxaOnboardingOpen();
                        }
                } );
        }

        /**
         * Boot locale del wizard (stesso dual-path del pannello live): markup
         * assente → exit silenzioso; hash #nxa-onboarding → apertura immediata
         * (il pannello è markup statico, non serve attendere il primo GET /agents).
         */
        function nxaOnboardingInit() {
                var modal = nxaOnboardingModal();
                if ( ! modal ) {
                        return;
                }
                nxaOnboardingBind();
                var isAgentsPage = qs( '.wrap.nexus-admin[data-page="agents"]' );
                if ( isAgentsPage && window.location && window.location.hash === '#nxa-onboarding' ) {
                        nxaOnboardingOpen();
                }
        }

        if ( document.readyState === 'loading' ) {
                document.addEventListener( 'DOMContentLoaded', nxaOnboardingInit );
        } else {
                nxaOnboardingInit();
        }
}() );
