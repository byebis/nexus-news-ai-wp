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

        /* ------------------------- MODAL ------------------------- */

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
                        if ( ev.key === 'Escape' && ! modal.hidden ) {
                                closeModal();
                        }
                } );
                return modal;
        }

        function openModalBody( html ) {
                var modal = ensureModal();
                qs( '#nxa-modal-body', modal ).innerHTML = html;
                modal.hidden = false;
                document.body.classList.add( 'nxa-modal-open' );
        }

        function closeModal() {
                var modal = qs( '#nxa-modal' );
                if ( modal ) {
                        modal.hidden = true;
                }
                document.body.classList.remove( 'nxa-modal-open' );
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
                        var html = '' +
                                '<div style="margin-bottom:8px">' + categoryChip( pick( a, 'category' ) ) + ' ' + badge( pick( a, 'status' ) ) + ' ' + wpPostBadge( a ) + ' ' + wpViewLink( a ) + '</div>' +
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
                qsa( '.nxa-run-pipeline' ).forEach( function ( btn ) {
                        btn.addEventListener( 'click', function () {
                                var ok = window.confirm( 'Avviare la pipeline editoriale completa?\n\nGli agenti raccoglieranno le notizie, le valuteranno, le riscriveranno e pubblicheranno gli articoli secondo le impostazioni correnti.' );
                                if ( ! ok ) {
                                        return;
                                }
                                setBtnLoading( btn, true, 'Pipeline in corso…' );
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
                        '<div style="margin:0 0 12px;display:flex;gap:6px;flex-wrap:wrap;align-items:center">' +
                                '<input type="text" class="regular-text nxa-agent-model" data-agent-id="' + esc( pick( agent, 'id' ) ) + '" value="' + esc( pick( agent, 'ai_model' ) || '' ) + '" placeholder="vuoto = modello globale" style="flex:1 1 170px;min-width:0" aria-label="Modello AI per ' + esc( pick( agent, 'name' ) || 'agente' ) + '" />' +
                                '<button type="button" class="button nxa-agent-model-save" data-agent-id="' + esc( pick( agent, 'id' ) ) + '">Salva modello</button>' +
                        '</div>' +
                        // v1.0.7: riga azioni in contenitore flex wrap: i nuovi
                        // "Modifica"/"Clona" + il toggle di stato preesistente.
                        '<div style="display:flex;gap:6px;flex-wrap:wrap">' +
                                '<button type="button" class="button nxa-agent-edit" data-agent-id="' + esc( pick( agent, 'id' ) ) + '">Modifica</button>' +
                                '<button type="button" class="button nxa-agent-clone" data-agent-id="' + esc( pick( agent, 'id' ) ) + '">Clona</button>' +
                                '<button type="button" class="button nxa-agent-toggle" data-agent-id="' + esc( pick( agent, 'id' ) ) + '" data-status="' + esc( status ) + '">' +
                                        ( isActive ? 'Metti in pausa' : 'Riattiva' ) +
                                '</button>' +
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
                                : emptyState( '🤖', 'Nessun agente configurato', 'Attiva il plugin per creare i 7 agenti predefiniti.' );
                        // v1.1.1: il render ricrea le card, quindi ripristina la
                        // colonna "Redazioni" dalla cache pagina (nessuna fetch).
                        refreshAgentRedazioniCells();
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
                title.textContent = num( pick( a, 'id' ) ) > 0
                        ? 'Modifica agente: ' + String( pick( a, 'name' ) || '' )
                        : 'Nuovo agente';
                var statusBox = qs( '#nxa-agent-form-status', modal );
                if ( statusBox ) {
                        statusBox.textContent = '';
                }
                modal.hidden = false;
                document.body.style.overflow = 'hidden';
                fillModelDatalists(); // v1.0.7: solo cache, nessun fetch dal modal
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

        /* ==================================================================
         * 9-bis. MODELLI AI PROVIDER (v1.0.7)
         * ================================================================== */

        /**
         * v1.0.7: cache client dei modelli esposti dal provider (GET /ai/models).
         * Il bottone in Impostazioni forza la chiamata; il modal agenti legge
         * SOLO questa cache (fillModelDatalists, nessun fetch automatico).
         */
        var aiModelsCache = { list: null, at: 0, ttl: 60000 };

        /** v1.0.7: svuota e riempie un <datalist> con <option value="id">. */
        function fillDatalist( node, models ) {
                if ( ! node ) {
                        return;
                }
                node.innerHTML = '';
                for ( var i = 0; i < models.length; i++ ) {
                        var modelId = String( models[ i ] || '' ).trim();
                        if ( ! modelId ) {
                                continue;
                        }
                        var option = document.createElement( 'option' );
                        option.setAttribute( 'value', modelId );
                        node.appendChild( option );
                }
        }

        /** v1.0.7: riempie i datalist (Impostazioni + editor agente) dalla cache. */
        function fillModelDatalists() {
                if ( ! aiModelsCache.list ) {
                        return;
                }
                fillDatalist( qs( '#nxa-ai-models-datalist' ), aiModelsCache.list );
                fillDatalist( qs( '#nxa-agent-model-datalist' ), aiModelsCache.list );
        }

        /** v1.0.7: stato leggibile accanto al bottone "Carica modelli". */
        function setModelsStatus( text ) {
                var node = qs( '#nxa-ai-models-status' );
                if ( node ) {
                        node.textContent = text;
                }
        }

        /**
         * v1.0.7: GET /ai/models → datalist + stato "{count} modelli
         * disponibili dal provider" (+ " (cache)" se il provider serve la sua).
         * Errori onesti: toast + err.message nello stato (chiave assente, HTTP
         * errato, …); chiave non ancora salvata → invito esplicito a salvare.
         */
        function loadAiModels( force ) {
                var fresh = aiModelsCache.list && ( Date.now() - aiModelsCache.at ) < aiModelsCache.ttl;
                if ( fresh && ! force ) {
                        fillModelDatalists();
                        return Promise.resolve();
                }
                var loadBtn = qs( '#nxa-ai-models-load' );
                setBtnLoading( loadBtn, true, 'Carico…' );
                return api( 'ai/models' ).then( function ( payload ) {
                        var data = unwrap( payload ) || {};
                        var models = Array.isArray( data.models ) ? data.models : getList( payload, [ 'models', 'items', 'list' ] );
                        aiModelsCache.list = models;
                        aiModelsCache.at = Date.now();
                        fillModelDatalists();
                        var count = num( data.count ) || models.length;
                        setModelsStatus( count + ' modelli disponibili dal provider' + ( data.cached ? ' (cache)' : '' ) );
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
                        '<button type="button" class="button-link nxa-detail" data-id="' + id + '">Dettagli</button>' +
                '</div>';

                return '<article class="nxa-queue-item" data-article-id="' + id + '">' +
                        '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px">' +
                                categoryChip( pick( a, 'category' ) ) +
                                badge( status ) +
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
                var status = currentQueueFilter();
                var query = '?per_page=20&page=1' + ( status ? '&status=' + encodeURIComponent( status ) : '' );
                return api( 'articles' + query ).then( function ( payload ) {
                        var items = getList( payload, [ 'articles', 'items' ] );
                        list.innerHTML = items.length
                                ? items.map( queueItemHtml ).join( '' )
                                : emptyState( '🗂️', 'Nessun articolo in coda',
                                        status ? 'Nessun articolo con lo stato selezionato.' : 'Esegui la raccolta per generare nuovi articoli.' );
                } ).catch( function ( err ) {
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
                var simple = [ 'mode', 'site_name', 'site_tagline', 'ai_base_url', 'ai_model', 'post_status', 'image_model', 'image_size', 'telegram_bot_token', 'telegram_chat_id', 'discord_webhook_url', 'x_api_key', 'x_api_secret', 'x_access_token', 'x_access_secret', 'linkedin_access_token', 'linkedin_author_urn', 'newsletter_recipients', 'indexnow_key', 'ai_model_cheap', 'factcheck_min_score', 'monthly_budget_usd', 'budget_action', 'translation_langs', 'admin_language', 'admin_theme' ];
                for ( i = 0; i < simple.length; i++ ) {
                        var field = form.elements[ simple[ i ] ];
                        if ( field && field.value !== undefined ) {
                                payload[ simple[ i ] ] = String( field.value || '' ).trim();
                        }
                }

                // numerici (v1.0.4: + scala & pro: queue_batch_size, queue_max_attempts)
                var numbers = [ 'collect_interval', 'max_articles_per_day', 'auto_approve_score', 'factcheck_min_score', 'monthly_budget_usd', 'queue_batch_size', 'queue_max_attempts' ];
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

                return payload;
        }

        function bindSettings() {
                var form = qs( '#nxa-settings-form' );
                if ( ! form ) {
                        return;
                }
                form.addEventListener( 'submit', function ( ev ) {
                        ev.preventDefault();
                        var submit = qs( 'button[type="submit"]', form ) || qs( 'input[type="submit"]', form ) || qs( 'button', form );
                        setBtnLoading( submit, true, 'Salvataggio…' );
                        api( 'settings', { method: 'PUT', body: collectSettings( form ) } ).then( function () {
                                toast( 'Impostazioni salvate.', 'success', 'Impostazioni' );
                        } ).catch( function ( err ) {
                                apiErrorToast( err, 'Salvataggio non riuscito' );
                        } ).then( function () {
                                setBtnLoading( submit, false );
                        } );
                } );

                // v1.0.7: modelli AI provider — click sul bottone "Carica modelli"
                // e precarico al blur della chiave (solo se compilata e i datalist
                // sono ancora vuoti; il server legge la chiave SALVATA).
                var modelsBtn = qs( '#nxa-ai-models-load' );
                if ( modelsBtn ) {
                        modelsBtn.addEventListener( 'click', function () {
                                loadAiModels( true );
                        } );
                }
                var apiKeyField = qs( '#nxa-ai-api-key' );
                if ( apiKeyField ) {
                        apiKeyField.addEventListener( 'blur', function () {
                                var modelsList = qs( '#nxa-ai-models-datalist' );
                                if ( String( apiKeyField.value || '' ).trim() !== '' && ( ! modelsList || ! modelsList.firstChild ) ) {
                                        loadAiModels( true );
                                }
                        } );
                }
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
         * 13-ter. REDAZIONI GERARCHICHE (v1.1.1)
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
                var actions =
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

        /** v1.1.1: conferma nativa in italiano, poi DELETE /redazioni/{id}. */
        function confirmDeleteRedazione( btn ) {
                var id = num( btn.getAttribute( 'data-id' ) );
                var term = redazioniTermById( id );
                var label = term ? String( pick( term, 'name' ) || '' ) : 'ID ' + id;
                var ok = window.confirm( 'Eliminare la redazione “' + label + '”?\n\nLe sub-redazioni figlie verranno riassegnate alla redazione madre. Gli articoli non vengono cancellati.' );
                if ( ! ok ) {
                        return;
                }
                setBtnLoading( btn, true, 'Elimino…' );
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
                var saveBtn = qs( '#nxa-red-save', form );
                setBtnLoading( saveBtn, true, 'Salvo…' );
                var request = editId > 0
                        ? api( 'redazioni/' + editId, { method: 'POST', body: body } )
                        : api( 'redazioni', { method: 'POST', body: body } );
                request.then( function () {
                        toast( editId > 0 ? 'Redazione aggiornata.' : 'Redazione creata.', 'success', 'Redazioni' );
                        resetRedazioneForm();
                        return loadRedazioniPage();
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

        function boot() {
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

        if ( document.readyState === 'loading' ) {
                document.addEventListener( 'DOMContentLoaded', boot );
        } else {
                boot();
        }
}() );
