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

        /** Colore per categoria — identico alla mappa in Nexus_Utils (PHP). */
        var CATEGORY_COLORS = {
                tecnologia: '#38bdf8',
                politica:   '#f59e0b',
                economia:   '#34d399',
                scienza:    '#a78bfa',
                sport:      '#f87171',
                cultura:    '#f472b6',
                salute:     '#4ade80'
        };
        var CATEGORY_COLOR_DEFAULT = '#94a3b8';

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

        function categoryColor( category ) {
                var key = String( category || '' ).toLowerCase().trim();
                return CATEGORY_COLORS[ key ] || CATEGORY_COLOR_DEFAULT;
        }

        function hexToRgba( hex, alpha ) {
                var clean = String( hex || '' ).replace( '#', '' );
                if ( clean.length !== 6 ) {
                        return 'rgba(148,163,184,' + alpha + ')';
                }
                var r = parseInt( clean.slice( 0, 2 ), 16 );
                var g = parseInt( clean.slice( 2, 4 ), 16 );
                var b = parseInt( clean.slice( 4, 6 ), 16 );
                return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
        }

        /** Chip categoria colorata (colore = mappa Nexus_Utils). */
        function categoryChip( category ) {
                var color = categoryColor( category );
                var label = category ? String( category ) : 'Generale';
                return '<span class="nxa-chip" style="background:' + hexToRgba( color, 0.14 ) +
                        ';color:' + color + ';border:1px solid ' + hexToRgba( color, 0.35 ) + '">' + esc( label ) + '</span>';
        }

        /** Badge di stato con classe colore. */
        function badge( status ) {
                var meta = STATUS_META[ String( status || '' ).toLowerCase() ] || [ String( status || '—' ), 'neutral' ];
                return '<span class="nxa-badge nxa-badge--' + meta[ 1 ] + '">' + esc( meta[ 0 ] ) + '</span>';
        }

        /** Badge punteggio qualità: ≥75 verde, ≥50 giallo, altrimenti rosso. */
        function scoreBadge( score ) {
                var value = num( score );
                var color = value >= 75 ? '#22c55e' : ( value >= 50 ? '#f59e0b' : '#ef4444' );
                return '<span class="nxa-badge" style="background:' + color + ';color:#fff" title="Punteggio qualità">' +
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
         *    reale del plugin, caricato dopo, ha sempre la precedenza)
         * ================================================================== */

        var FALLBACK_CSS = [
                '#nxa-toast-stack{position:fixed;right:16px;bottom:16px;z-index:99999;display:flex;flex-direction:column;gap:8px;max-width:340px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}',
                '.nxa-toast{display:flex;gap:10px;align-items:flex-start;background:#1e293b;color:#f8fafc;border-radius:10px;padding:12px 14px;box-shadow:0 10px 30px rgba(0,0,0,.25);font-size:13px;line-height:1.45;opacity:0;transform:translateY(8px);transition:opacity .25s,transform .25s}',
                '.nxa-toast.nxa-toast--in{opacity:1;transform:none}',
                '.nxa-toast.nxa-toast--out{opacity:0;transform:translateY(6px)}',
                '.nxa-toast--success{border-left:4px solid #22c55e}',
                '.nxa-toast--error{border-left:4px solid #ef4444}',
                '.nxa-toast--info{border-left:4px solid #38bdf8}',
                '.nxa-toast__title{font-weight:600;margin:0 0 2px}',
                '.nxa-toast__msg{margin:0;color:#e2e8f0;word-break:break-word}',
                '.nxa-toast__close{background:none;border:0;color:inherit;cursor:pointer;font-size:16px;line-height:1;margin-left:auto;opacity:.7;padding:0 2px}',
                '.nxa-spinner{display:inline-block;width:14px;height:14px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:nxa-spin .7s linear infinite;vertical-align:-2px}',
                '@keyframes nxa-spin{to{transform:rotate(360deg)}}',
                '.nxa-skeleton{background:linear-gradient(90deg,#e2e8f0 25%,#f1f5f9 37%,#e2e8f0 63%);background-size:400% 100%;animation:nxa-shimmer 1.2s ease infinite;border-radius:6px;height:14px;margin:10px 0}',
                '@keyframes nxa-shimmer{0%{background-position:100% 50%}100%{background-position:0 50%}}',
                '.nxa-badge{display:inline-flex;align-items:center;gap:4px;border-radius:999px;padding:2px 10px;font-size:11px;font-weight:600;line-height:1.6;white-space:nowrap}',
                '.nxa-badge--success{background:#dcfce7;color:#166534}',
                '.nxa-badge--warning{background:#fef3c7;color:#92400e}',
                '.nxa-badge--error{background:#fee2e2;color:#991b1b}',
                '.nxa-badge--info{background:#e0f2fe;color:#075985}',
                '.nxa-badge--neutral{background:#f1f5f9;color:#475569}',
                '.nxa-badge--primary{background:#ede9fe;color:#5b21b6}',
                '.nxa-chip{display:inline-flex;align-items:center;border-radius:999px;padding:2px 10px;font-size:11px;font-weight:600;white-space:nowrap}',
                '.nxa-dot{width:7px;height:7px;border-radius:50%;display:inline-block}',
                '.nxa-empty{text-align:center;padding:44px 16px;color:#64748b;font-size:14px}',
                '.nxa-empty__icon{font-size:34px;margin-bottom:8px}',
                '.nxa-empty__title{font-weight:600;font-size:16px;color:#334155;margin:0 0 4px}',
                '.nxa-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px}',
                '.nxa-queue-item{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px 16px;margin-bottom:12px}',
                '.nxa-act-item{display:flex;gap:12px;padding:12px 4px;border-bottom:1px solid #eef2f7}',
                '.nxa-act-item:last-child{border-bottom:0}',
                '.nxa-act-icon{flex:0 0 auto;width:36px;height:36px;border-radius:50%;background:#f1f5f9;display:flex;align-items:center;justify-content:center;font-size:17px}',
                '.nxa-act-agent{font-weight:600;font-size:13px;color:#0f172a}',
                '.nxa-act-detail{font-size:12.5px;color:#64748b;margin:3px 0 0;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}',
                '.nxa-act-time{font-size:11px;color:#94a3b8;white-space:nowrap;margin-left:auto}',
                '.nxa-modal{position:fixed;left:0;top:0;right:0;bottom:0;z-index:100000;display:flex;align-items:center;justify-content:center;background:rgba(15,23,42,.55);padding:24px}',
                '.nxa-modal[hidden]{display:none}',
                '.nxa-modal__box{background:#fff;color:#0f172a;max-width:760px;width:100%;max-height:85vh;overflow:auto;border-radius:14px;box-shadow:0 24px 60px rgba(0,0,0,.3);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}',
                '.nxa-modal__head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 18px;border-bottom:1px solid #e2e8f0;position:sticky;top:0;background:#fff;z-index:1}',
                '.nxa-modal__head h2{font-size:17px;margin:0}',
                '.nxa-modal__body{padding:18px}',
                '.nxa-modal__close{background:none;border:0;font-size:22px;line-height:1;cursor:pointer;color:#475569}',
                'body.nxa-modal-open{overflow:hidden}',
                '.nxa-btn-row{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}',
                '.nxa-note-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:10px}',
                '.nxa-note-input{flex:1 1 220px;border:1px solid #cbd5e1;border-radius:8px;padding:6px 10px;font-size:13px}',
                '.nxa-mini-agents{display:flex;flex-wrap:wrap;gap:10px}',
                '.nxa-mini-agent{display:flex;align-items:center;gap:8px;background:#fff;border:1px solid #e2e8f0;border-radius:999px;padding:6px 12px 6px 6px;font-size:13px}',
                '.nxa-mini-agent__avatar{width:30px;height:30px;border-radius:50%;background:#f1f5f9;display:flex;align-items:center;justify-content:center;font-size:16px}',
                '.nxa-wire-run{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:12px 16px;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}',
                '.nxa-wire-status{margin-top:12px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;padding:12px 14px;font-size:13px;color:#475569}',
                '.nxa-idea{border:1px solid #cbd5e1;background:#fff;border-radius:999px;padding:3px 10px;font-size:12px;color:#475569;cursor:pointer;margin:0 6px 6px 0}',
                '.nxa-idea:hover{background:#f1f5f9}'
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
                                '<div style="margin-bottom:8px">' + categoryChip( pick( a, 'category' ) ) + ' ' + badge( pick( a, 'status' ) ) + ' ' + wpPostBadge( a ) + '</div>' +
                                '<h3 style="margin:0 0 4px;font-size:20px;line-height:1.3">' + esc( pick( a, 'title' ) ) + '</h3>' +
                                ( pick( a, 'subtitle', 'subTitle' ) ? '<p style="margin:0 0 10px;color:#475569;font-size:14px">' + esc( pick( a, 'subtitle', 'subTitle' ) ) + '</p>' : '' ) +
                                '<p style="font-size:12px;color:#64748b;margin:0 0 14px">' +
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

                        var logs = pick( a, 'publish_logs', 'publishLogs' );
                        if ( Array.isArray( logs ) && logs.length ) {
                                html += '<h4 style="margin:18px 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:.04em;color:#64748b">Log di pubblicazione</h4>';
                                logs.forEach( function ( log ) {
                                        html += '<p style="font-size:13px;margin:4px 0">' +
                                                '<strong>' + esc( pick( log, 'platform' ) || 'blog' ) + '</strong> ' + badge( pick( log, 'status' ) ) +
                                                ( pick( log, 'post_url' ) ? ' <a href="' + esc( pick( log, 'post_url' ) ) + '" target="_blank" rel="noopener noreferrer">vedi post ↗</a>' : '' ) +
                                                ( pick( log, 'error' ) ? '<br><span style="color:#b91c1c">' + esc( pick( log, 'error' ) ) + '</span>' : '' ) +
                                                '</p>';
                                } );
                        }
                        openModalBody( html );
                } ).catch( function ( err ) {
                        openModalBody( emptyState( '⚠️', 'Impossibile caricare l’articolo', err.message || '' ) );
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

        function refreshDashboardData() {
                return Promise.all( [
                        loadStats().catch( function ( e ) { window.console.warn( '[Nexus] stats:', e ); } ),
                        loadDashboardFeed().catch( function ( e ) { window.console.warn( '[Nexus] feed:', e ); } ),
                        loadAgentsMini().catch( function ( e ) { window.console.warn( '[Nexus] mini agenti:', e ); } )
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
                var dotColor = isActive ? '#22c55e' : ( status === 'error' ? '#ef4444' : '#f59e0b' );
                return '<div class="nxa-card nxa-agent-card" data-agent-id="' + esc( pick( agent, 'id' ) ) + '">' +
                        '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px">' +
                                '<div style="display:flex;align-items:center;gap:10px;min-width:0">' +
                                        '<span style="width:44px;height:44px;border-radius:12px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;font-size:22px" aria-hidden="true">' + esc( pick( agent, 'avatar' ) || '🤖' ) + '</span>' +
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
                        '<p style="font-size:12.5px;color:#64748b;margin:10px 0 6px;line-height:1.55">' + esc( pick( agent, 'description' ) || '' ) + '</p>' +
                        '<p style="font-size:12px;color:#475569;margin:0 0 10px"><em>“' + esc( pick( agent, 'personality' ) || '' ) + '”</em></p>' +
                        '<p style="font-size:11.5px;color:#94a3b8;margin:0 0 12px">Ultimo run: ' + esc( timeAgoIt( pick( agent, 'last_run', 'lastRun' ) ) ) + '</p>' +
                        '<button type="button" class="button nxa-agent-toggle" data-agent-id="' + esc( pick( agent, 'id' ) ) + '" data-status="' + esc( status ) + '">' +
                                ( isActive ? 'Metti in pausa' : 'Riattiva' ) +
                        '</button>' +
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
                }

                qsa( '.nxa-agents-refresh' ).forEach( function ( btn ) {
                        btn.addEventListener( 'click', function () {
                                setBtnLoading( btn, true, 'Aggiorno…' );
                                loadAgents( true ).then( function () {
                                        setBtnLoading( btn, false );
                                } );
                        } );
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
                                '<span style="font-size:12px;color:#64748b">✍️ ' + esc( articleAgentName( a ) ) + '</span>' +
                                '<span style="font-size:11px;color:#94a3b8;margin-left:auto">' + esc( timeAgoIt( pick( a, 'created_at', 'createdAt' ) ) ) + '</span>' +
                        '</div>' +
                        '<h3 style="margin:0 0 4px;font-size:15px;line-height:1.35">' +
                                '<a href="#" class="nxa-detail" data-id="' + id + '" style="text-decoration:none;color:inherit">' + esc( pick( a, 'title' ) || '(senza titolo)' ) + '</a>' +
                        '</h3>' +
                        ( pick( a, 'subtitle', 'subTitle' ) ? '<p style="margin:0 0 6px;font-size:13px;color:#475569">' + esc( pick( a, 'subtitle', 'subTitle' ) ) + '</p>' : '' ) +
                        ( summary ? '<p style="margin:0 0 8px;font-size:12.5px;color:#64748b;line-height:1.55">' + excerpt( summary, 220 ) + '</p>' : '' ) +
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
                var simple = [ 'mode', 'site_name', 'site_tagline', 'ai_base_url', 'ai_model', 'post_status', 'image_model', 'image_size' ];
                for ( i = 0; i < simple.length; i++ ) {
                        var field = form.elements[ simple[ i ] ];
                        if ( field && field.value !== undefined ) {
                                payload[ simple[ i ] ] = String( field.value || '' ).trim();
                        }
                }

                var numbers = [ 'collect_interval', 'max_articles_per_day', 'auto_approve_score' ];
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
                        meta.push( '<span style="color:#b91c1c">' + esc( run.error ) + '</span>' );
                }
                return '<div class="nxa-wire-run">' +
                        '<div style="min-width:0;flex:1">' +
                                '<strong style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc( run.topic ) + '</strong>' +
                                '<span style="font-size:12px;color:#64748b">' + meta.join( ' · ' ) + '</span>' +
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
         * 14. ROUTER + BOOT
         * ================================================================== */

        var LOADERS = {
                dashboard: loadDashboard,
                agents: function () {
                        bindAgents();
                        return loadAgents( false );
                },
                queue: function () {
                        bindQueue();
                        return loadQueueList();
                },
                activity: loadActivity,
                settings: bindSettings,
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
