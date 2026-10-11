<?php
/**
 * Vista: Analytics (self-contained, v1.0.4 "Scala & Pro").
 *
 * CONTRATTO DELLA VISTA (nessuna dipendenza da admin.js né da nexus-admin.css):
 * - Container root: <div class="wrap nxa-an" data-page="analytics">.
 * - Dati: GET {NexusAdminData.restUrl}/analytics?days=N con header X-WP-Nonce
 *   → payload Nexus_Analytics::overview() (rotta definita in class-nexus-rest.php):
 *   { days, generated_at, series[], totals, per_platform[], per_agent[],
 *     top_articles[], subscribers }.
 * - <style> inline con SOLO classi prefisso .nxa-an- (divieto di toccare il CSS condiviso).
 * - <script> inline IIFE ES2019: rendering completo client-side con esc() su ogni
 *   valore dinamico; cambio periodo (7/14/30/90) → ricarica; errore fetch →
 *   div inline rosso "Errore nel caricamento analytics" (mai alert()).
 * - Sezioni: 4 stat-card, grafico a barre CSS puro (created/published per giorno,
 *   altezza %, tooltip title, legenda), tabelle per piattaforma / per agente /
 *   top articoli, contatori iscritti. Empty state IT: "Nessun dato nel periodo".
 *
 * @package Nexus_News_AI
 * @since   1.0.4
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap nxa-an" data-page="analytics">

        <h1><?php echo esc_html__( 'Analytics', 'nexus-news-ai' ); ?></h1>

        <p class="nxa-an-sub"><?php echo esc_html__( 'Statistiche reali della redazione AI: articoli creati e pubblicati, uso e costi AI, piattaforme, agenti e iscritti newsletter.', 'nexus-news-ai' ); ?></p>

        <p class="nxa-an-period">
                <label for="nxa-an-days"><?php echo esc_html__( 'Periodo', 'nexus-news-ai' ); ?></label>
                <select id="nxa-an-days">
                        <option value="7"><?php echo esc_html__( 'Ultimi 7 giorni', 'nexus-news-ai' ); ?></option>
                        <option value="14"><?php echo esc_html__( 'Ultimi 14 giorni', 'nexus-news-ai' ); ?></option>
                        <option value="30" selected="selected"><?php echo esc_html__( 'Ultimi 30 giorni', 'nexus-news-ai' ); ?></option>
                        <option value="90"><?php echo esc_html__( 'Ultimi 90 giorni', 'nexus-news-ai' ); ?></option>
                </select>
        </p>

        <div id="nxa-an-error" class="nxa-an-error" hidden><?php echo esc_html__( 'Errore nel caricamento analytics', 'nexus-news-ai' ); ?></div>

        <div class="nxa-an-cards">
                <div class="nxa-an-card"><span class="nxa-an-value" id="nxa-an-created">—</span><span class="nxa-an-label"><?php echo esc_html__( 'Articoli creati', 'nexus-news-ai' ); ?></span></div>
                <div class="nxa-an-card"><span class="nxa-an-value" id="nxa-an-published">—</span><span class="nxa-an-label"><?php echo esc_html__( 'Pubblicati', 'nexus-news-ai' ); ?></span></div>
                <div class="nxa-an-card"><span class="nxa-an-value" id="nxa-an-calls">—</span><span class="nxa-an-label"><?php echo esc_html__( 'Chiamate AI', 'nexus-news-ai' ); ?></span></div>
                <div class="nxa-an-card"><span class="nxa-an-value" id="nxa-an-cost">—</span><span class="nxa-an-label"><?php echo esc_html__( 'Costo AI', 'nexus-news-ai' ); ?></span></div>
        </div>

        <h2><?php echo esc_html__( 'Serie giornaliera', 'nexus-news-ai' ); ?></h2>
        <ul class="nxa-an-legend">
                <li><span class="nxa-an-dot nxa-an-dot-created"></span><?php echo esc_html__( 'Creati', 'nexus-news-ai' ); ?></li>
                <li><span class="nxa-an-dot nxa-an-dot-published"></span><?php echo esc_html__( 'Pubblicati', 'nexus-news-ai' ); ?></li>
        </ul>
        <div id="nxa-an-chart" class="nxa-an-chart"></div>

        <h2><?php echo esc_html__( 'Pubblicazioni per piattaforma', 'nexus-news-ai' ); ?></h2>
        <table class="widefat striped" id="nxa-an-platform">
                <thead>
                        <tr>
                                <th><?php echo esc_html__( 'Piattaforma', 'nexus-news-ai' ); ?></th>
                                <th><?php echo esc_html__( 'Pubblicati', 'nexus-news-ai' ); ?></th>
                                <th><?php echo esc_html__( 'Falliti', 'nexus-news-ai' ); ?></th>
                        </tr>
                </thead>
                <tbody></tbody>
        </table>

        <h2><?php echo esc_html__( 'Attività per agente', 'nexus-news-ai' ); ?></h2>
        <table class="widefat striped" id="nxa-an-agents">
                <thead>
                        <tr>
                                <th><?php echo esc_html__( 'Agente', 'nexus-news-ai' ); ?></th>
                                <th><?php echo esc_html__( 'Creati', 'nexus-news-ai' ); ?></th>
                                <th><?php echo esc_html__( 'Pubblicati', 'nexus-news-ai' ); ?></th>
                                <th><?php echo esc_html__( 'Qualità media', 'nexus-news-ai' ); ?></th>
                        </tr>
                </thead>
                <tbody></tbody>
        </table>

        <h2><?php echo esc_html__( 'Articoli più visti', 'nexus-news-ai' ); ?></h2>
        <table class="widefat striped" id="nxa-an-top">
                <thead>
                        <tr>
                                <th><?php echo esc_html__( 'Titolo', 'nexus-news-ai' ); ?></th>
                                <th><?php echo esc_html__( 'Viste', 'nexus-news-ai' ); ?></th>
                                <th><?php echo esc_html__( 'Reazioni', 'nexus-news-ai' ); ?></th>
                                <th><?php echo esc_html__( 'Stato', 'nexus-news-ai' ); ?></th>
                                <th><?php echo esc_html__( 'Agente', 'nexus-news-ai' ); ?></th>
                        </tr>
                </thead>
                <tbody></tbody>
        </table>

        <h2><?php echo esc_html__( 'Iscritti newsletter', 'nexus-news-ai' ); ?></h2>
        <div class="nxa-an-cards nxa-an-subs">
                <div class="nxa-an-card"><span class="nxa-an-value" id="nxa-an-sub-confirmed">—</span><span class="nxa-an-label"><?php echo esc_html__( 'Confermati', 'nexus-news-ai' ); ?></span></div>
                <div class="nxa-an-card"><span class="nxa-an-value" id="nxa-an-sub-pending">—</span><span class="nxa-an-label"><?php echo esc_html__( 'In attesa', 'nexus-news-ai' ); ?></span></div>
                <div class="nxa-an-card"><span class="nxa-an-value" id="nxa-an-sub-unsubscribed">—</span><span class="nxa-an-label"><?php echo esc_html__( 'Disiscritti', 'nexus-news-ai' ); ?></span></div>
                <div class="nxa-an-card"><span class="nxa-an-value" id="nxa-an-sub-new">—</span><span class="nxa-an-label"><?php echo esc_html__( 'Nuovi nel periodo', 'nexus-news-ai' ); ?></span></div>
        </div>

        <style>
                /* Stile locale alla vista: SOLO classi prefisso .nxa-an-.
                 * Nota di stile: regole multilinea così i selettori restano leggibili. */
                .nxa-an-sub {
                        margin-top: -4px;
                        color: #50575e;
                }
                .nxa-an-period {
                        margin: 16px 0;
                }
                .nxa-an-cards {
                        display: flex;
                        flex-wrap: wrap;
                        gap: 12px;
                        margin: 16px 0;
                }
                .nxa-an-card {
                        background: #fff;
                        border: 1px solid #dcdcde;
                        border-radius: 8px;
                        padding: 16px;
                        min-width: 200px;
                        flex: 1 1 200px;
                        box-sizing: border-box;
                }
                .nxa-an-value {
                        display: block;
                        font-size: 26px;
                        line-height: 1.2;
                        font-weight: 600;
                }
                .nxa-an-label {
                        display: block;
                        margin-top: 6px;
                        color: #50575e;
                }
                .nxa-an-legend {
                        list-style: none;
                        display: flex;
                        flex-wrap: wrap;
                        gap: 16px;
                        margin: 12px 0 0;
                        padding: 0;
                }
                .nxa-an-dot {
                        display: inline-block;
                        width: 10px;
                        height: 10px;
                        margin-right: 6px;
                        border-radius: 2px;
                        vertical-align: middle;
                }
                .nxa-an-dot-created {
                        background: #2271b1;
                }
                .nxa-an-dot-published {
                        background: #22c55e;
                }
                .nxa-an-chart {
                        display: flex;
                        align-items: flex-end;
                        height: 220px;
                        gap: 3px;
                        margin: 12px 0 16px;
                        padding: 8px;
                        background: #fff;
                        border: 1px solid #dcdcde;
                        border-radius: 8px;
                        box-sizing: border-box;
                }
                .nxa-an-day {
                        display: flex;
                        align-items: flex-end;
                        flex: 1 1 0;
                        height: 100%;
                        min-width: 0;
                        gap: 2px;
                }
                .nxa-an-bar {
                        flex: 1 1 0;
                        min-height: 2px;
                        border-radius: 1px 1px 0 0;
                }
                .nxa-an-bar-created {
                        background: #2271b1;
                }
                .nxa-an-bar-published {
                        background: #22c55e;
                }
                .nxa-an-error {
                        margin: 12px 0;
                        padding: 12px;
                        border: 1px solid #d63638;
                        border-radius: 4px;
                        background: #fcf0f1;
                        color: #8a2424;
                }
                .nxa-an-error[hidden] {
                        display: none;
                }
                .nxa-an-empty {
                        color: #50575e;
                        font-style: italic;
                }
                .nxa-an-muted {
                        color: #50575e;
                }
                @media (max-width: 782px) {
                        .nxa-an-card {
                                min-width: 100%;
                                flex-basis: 100%;
                        }
                        .nxa-an-chart {
                                gap: 2px;
                                padding: 6px;
                        }
                }
        </style>

        <script>
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
                                        .catch( function () {
                                                mostraErrore();
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
        </script>

</div>
