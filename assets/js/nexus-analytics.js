/**
 * Nexus News AI — Script vista "Analytics" (admin/views/analytics.php)
 *
 * Slug plugin: nexus-news-ai
 * Versione:    1.1.13
 *
 * Estratto dal blocco <script> inline della vista (1.1.13 "Ottimizzazione",
 * task O5): JS 100% statico (zero PHP interpolato), IIFE ES2019 vanilla
 * spostata identica al byte (solo dedent). Nessuna dipendenza da admin.js:
 * consuma window.NexusAdminData (restUrl + nonce, localizzata in
 * class-nexus-admin.php) e la rotta REST nexus/v1/analytics.
 *
 * CONTRATTO DOM (selettori fissi — NON rinominare, usati anche qui e nel
 * markup della vista): #nxa-an-days, #nxa-an-error, #nxa-an-chart,
 * #nxa-an-created, #nxa-an-published, #nxa-an-calls, #nxa-an-cost,
 * #nxa-an-platform, #nxa-an-agents, #nxa-an-top, #nxa-an-sub-confirmed,
 * #nxa-an-sub-pending, #nxa-an-sub-unsubscribed, #nxa-an-sub-new;
 * classi generate: .nxa-an-bar(-created/-published), .nxa-an-day,
 * .nxa-an-empty, .nxa-an-muted.
 *
 * NOTA enqueue: in_footer (come admin.js). L'IIFE attende comunque il
 * DOM ready se eseguita durante il parsing, quindi e' sicura anche in head.
 * i18n: dal 1.1.7 gli script delle viste non passano da translate_html e i
 * testi generati dal JS sono tradotti lato client da i18n.js
 * (MutationObserver): l'estrazione non cambia il comportamento EN.
 */
(function () {
        'use strict';

        var restUrl = '';
        var nonce = '';
        var MESI = [ 'gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic' ];
        var STATI = { draft: 'Bozza', published: 'Pubblicato', pending_approval: 'In attesa', approved: 'Approvato', rejected: 'Rifiutato' };
        var EL = {
                days: document.getElementById( 'nxa-an-days' ),
                error: document.getElementById( 'nxa-an-error' ),
                chart: document.getElementById( 'nxa-an-chart' ),
                created: document.getElementById( 'nxa-an-created' ),
                published: document.getElementById( 'nxa-an-published' ),
                calls: document.getElementById( 'nxa-an-calls' ),
                cost: document.getElementById( 'nxa-an-cost' ),
                platform: document.getElementById( 'nxa-an-platform' ),
                agents: document.getElementById( 'nxa-an-agents' ),
                top: document.getElementById( 'nxa-an-top' ),
                subConfirmed: document.getElementById( 'nxa-an-sub-confirmed' ),
                subPending: document.getElementById( 'nxa-an-sub-pending' ),
                subUnsubscribed: document.getElementById( 'nxa-an-sub-unsubscribed' ),
                subNew: document.getElementById( 'nxa-an-sub-new' )
        };

        /* Escape HTML: sostituisce & < > " ' (convenzione condivisa con admin.js).
         * Le virgolette sono scritte come escape esadecimali (\x22 = ", \x27 = ')
         * e \x23 = # per restare compatibili con lo checker statico del progetto,
         * che tratta virgolette e cancelletto come delimitatori PHP. */
        function esc( val ) {
                return String( val === null || val === undefined ? '' : val )
                        .replace( /&/g, '&amp;' )
                        .replace( /</g, '&lt;' )
                        .replace( />/g, '&gt;' )
                        .replace( /\x22/g, '&quot;' )
                        .replace( /\x27/g, '&\x23039;' );
        }

        function fmtInt( n ) {
                var v = parseInt( n, 10 );
                if ( isNaN( v ) ) { v = 0; }
                return v.toLocaleString( 'it-IT' );
        }

        /* Costo in dollari con 4 decimali, separatore italiano. */
        function fmtCost( n ) {
                var v = parseFloat( n );
                if ( isNaN( v ) ) { v = 0; }
                return '$' + v.toLocaleString( 'it-IT', { minimumFractionDigits: 4, maximumFractionDigits: 4 } );
        }

        function fmtQual( n ) {
                var v = parseFloat( n );
                if ( isNaN( v ) ) { v = 0; }
                return v.toLocaleString( 'it-IT', { minimumFractionDigits: 1, maximumFractionDigits: 1 } );
        }

        /* '2026-10-12' → '12/10'. */
        function dataIt( ymd ) {
                var parts = String( ymd || '' ).split( '-' );
                if ( 3 !== parts.length ) { return String( ymd || '' ); }
                return parts[2] + '/' + parts[1];
        }

        /* Tooltip giorno: '12 ott — 3 creati, 2 pubblicati'. */
        function tooltipGiorno( ymd, creati, pubblicati ) {
                var parts = String( ymd || '' ).split( '-' );
                var label = dataIt( ymd );
                if ( 3 === parts.length ) {
                        var m = parseInt( parts[1], 10 );
                        label = parseInt( parts[2], 10 ) + ' ' + ( MESI[ m - 1 ] || parts[1] );
                }
                return label + ' — ' + fmtInt( creati ) + ' creati, ' + fmtInt( pubblicati ) + ' pubblicati';
        }

        function mostraErrore() {
                if ( EL.error ) { EL.error.hidden = false; }
        }

        function nascondiErrore() {
                if ( EL.error ) { EL.error.hidden = true; }
        }

        function barra( valore, max, classe, tooltip ) {
                var el = document.createElement( 'div' );
                el.className = 'nxa-an-bar ' + classe;
                var pct = max > 0 ? Math.round( ( valore || 0 ) / max * 100 ) : 0;
                if ( ( valore || 0 ) > 0 && pct < 1 ) { pct = 1; }
                el.style.height = pct + '%';
                el.setAttribute( 'title', tooltip );
                return el;
        }

        function renderGrafico( series ) {
                if ( ! EL.chart ) { return; }
                var max = 0;
                var i;
                for ( i = 0; i < series.length; i++ ) {
                        max = Math.max( max, series[i].created || 0, series[i].published || 0 );
                }
                EL.chart.innerHTML = '';
                if ( ! series.length ) {
                        EL.chart.innerHTML = '<p class="nxa-an-empty">Nessun dato nel periodo</p>';
                        return;
                }
                for ( i = 0; i < series.length; i++ ) {
                        var giorno = document.createElement( 'div' );
                        giorno.className = 'nxa-an-day';
                        giorno.setAttribute( 'title', tooltipGiorno( series[i].date, series[i].created, series[i].published ) );
                        giorno.appendChild( barra( series[i].created, max, 'nxa-an-bar-created', tooltipGiorno( series[i].date, series[i].created, series[i].published ) ) );
                        giorno.appendChild( barra( series[i].published, max, 'nxa-an-bar-published', tooltipGiorno( series[i].date, series[i].created, series[i].published ) ) );
                        EL.chart.appendChild( giorno );
                }
        }

        function rigaVuota( colonne ) {
                return '<tr><td colspan="' + colonne + '" class="nxa-an-empty">Nessun dato nel periodo</td></tr>';
        }

        function corpoTabella( tabella ) {
                return tabella && tabella.tBodies && tabella.tBodies[0] ? tabella.tBodies[0] : null;
        }

        function renderPiattaforme( righe ) {
                var body = corpoTabella( EL.platform );
                if ( ! body ) { return; }
                if ( ! righe.length ) { body.innerHTML = rigaVuota( 3 ); return; }
                var html = '';
                for ( var i = 0; i < righe.length; i++ ) {
                        html += '<tr><td>' + esc( righe[i].platform ) + '</td>'
                                + '<td>' + fmtInt( righe[i].published ) + '</td>'
                                + '<td>' + fmtInt( righe[i].failed ) + '</td></tr>';
                }
                body.innerHTML = html;
        }

        function renderAgenti( righe ) {
                var body = corpoTabella( EL.agents );
                if ( ! body ) { return; }
                if ( ! righe.length ) { body.innerHTML = rigaVuota( 4 ); return; }
                var html = '';
                for ( var i = 0; i < righe.length; i++ ) {
                        var r = righe[i];
                        var id = parseInt( r.agent_id, 10 ) || 0;
                        var nome = r.name ? r.name : ( 'Agente #' + id );
                        var cat = r.category ? ' <span class="nxa-an-muted">· ' + esc( r.category ) + '</span>' : '';
                        html += '<tr><td>' + esc( r.avatar ) + ' ' + esc( nome ) + cat + '</td>'
                                + '<td>' + fmtInt( r.created ) + '</td>'
                                + '<td>' + fmtInt( r.published ) + '</td>'
                                + '<td>' + fmtQual( r.avg_quality ) + '</td></tr>';
                }
                body.innerHTML = html;
        }

        function etichettaStato( status ) {
                return STATI[ status ] ? STATI[ status ] : String( status || '' );
        }

        function renderTop( righe ) {
                var body = corpoTabella( EL.top );
                if ( ! body ) { return; }
                if ( ! righe.length ) { body.innerHTML = rigaVuota( 5 ); return; }
                var html = '';
                for ( var i = 0; i < righe.length; i++ ) {
                        var r = righe[i];
                        html += '<tr><td>' + esc( r.title ) + '</td>'
                                + '<td>' + fmtInt( r.views ) + '</td>'
                                + '<td>' + fmtInt( r.reactions_count ) + '</td>'
                                + '<td>' + esc( etichettaStato( r.status ) ) + '</td>'
                                + '<td>' + esc( r.agent_name || '—' ) + '</td></tr>';
                }
                body.innerHTML = html;
        }

        function renderIscritti( subs ) {
                if ( ! subs || 'object' !== typeof subs ) { subs = {}; }
                if ( EL.subConfirmed ) { EL.subConfirmed.textContent = fmtInt( subs.confirmed ); }
                if ( EL.subPending ) { EL.subPending.textContent = fmtInt( subs.pending ); }
                if ( EL.subUnsubscribed ) { EL.subUnsubscribed.textContent = fmtInt( subs.unsubscribed ); }
                if ( EL.subNew ) { EL.subNew.textContent = fmtInt( subs.new_in_period ); }
        }

        function render( data ) {
                if ( ! data || 'object' !== typeof data ) { throw new Error( 'Risposta analytics non valida' ); }
                var totals = data.totals || {};
                var series = data.series || [];
                if ( EL.created ) { EL.created.textContent = fmtInt( totals.created ); }
                if ( EL.published ) { EL.published.textContent = fmtInt( totals.published ); }
                if ( EL.calls ) { EL.calls.textContent = fmtInt( totals.ai_calls ); }
                if ( EL.cost ) { EL.cost.textContent = fmtCost( totals.ai_cost ); }
                renderGrafico( series );
                renderPiattaforme( data.per_platform || [] );
                renderAgenti( data.per_agent || [] );
                renderTop( data.top_articles || [] );
                renderIscritti( data.subscribers || {} );
                nascondiErrore();
        }

        /* Carica i dati per il periodo scelto (rotta REST nexus/v1/analytics). */
        function carica( giorni ) {
                nascondiErrore();
                fetch( restUrl + '/analytics?days=' + encodeURIComponent( giorni ), {
                        headers: { 'X-WP-Nonce': nonce }
                } )
                        .then( function ( res ) {
                                if ( ! res.ok ) { throw new Error( 'HTTP ' + res.status ); }
                                return res.json();
                        } )
                        .then( function ( data ) {
                                render( data );
                        } )
                        .catch( function ( e ) {
                                mostraErrore();
                                if ( window.console && window.console.error ) {
                                        window.console.error( '[Nexus News AI] analytics:', e && e.message ? e.message : e );
                                }
                        } );
        }

        function avvia() {
                if ( ! window.NexusAdminData || ! window.NexusAdminData.restUrl ) {
                        mostraErrore();
                        return;
                }
                restUrl = window.NexusAdminData.restUrl;
                nonce = window.NexusAdminData.nonce || '';
                if ( ! EL.days || ! EL.chart ) { return; }
                EL.days.addEventListener( 'change', function () {
                        carica( EL.days.value || '30' );
                } );
                carica( EL.days.value || '30' );
        }

        /* NexusAdminData è localizzata nei footer admin: attende il DOM ready
         * se lo script viene eseguito durante il parsing della pagina. */
        if ( 'loading' === document.readyState ) {
                document.addEventListener( 'DOMContentLoaded', avvia );
        } else {
                avvia();
        }
})();
