<?php
/**
 * Vista: Analytics (self-contained, v1.0.6 "UI chiara & curata").
 *
 * CONTRATTO DELLA VISTA (nessuna dipendenza da admin.js):
 * - Container root: <div class="wrap nxa-an" data-page="analytics">.
 * - Dati: GET {NexusAdminData.restUrl}/analytics?days=N con header X-WP-Nonce
 *   → payload Nexus_Analytics::overview() (rotta definita in class-nexus-rest.php):
 *   { days, generated_at, series[], totals, per_platform[], per_agent[],
 *     top_articles[], subscribers }.
 * - <style> inline con SOLO classi prefisso .nxa-an- (più selettori scoped dentro
 *   .nxa-an / .nxa-an-panel; divieto di toccare il CSS condiviso): consuma i token
 *   var(--nxa-*) del tema chiaro 1.0.6 da admin/css/nexus-admin.css (enqueued su
 *   ogni pagina admin del plugin); i fallback rgba() replicano i valori light del
 *   contratto e tengono la vista leggibile anche senza il foglio condiviso.
 * - Tooltip del grafico: solo CSS via content: attr(title) su .nxa-an-day (il
 *   title è già impostato dal JS): fondello scuro adattivo — background
 *   var(--nxa-text), testo var(--nxa-surface) — che si inverte da solo nel tema
 *   dark; il tooltip nativo del title resta come fallback (touch, hover prolungato).
 * - <script> inline IIFE ES2019: rendering completo client-side con esc() su ogni
 *   valore dinamico; cambio periodo (7/14/30/90) → ricarica; errore fetch →
 *   div inline rosso "Errore nel caricamento analytics" (mai alert()).
 *   Logica JS invariata alla 1.0.6: è cambiato solo lo stile.
 * - Sezioni: 4 stat-card, grafico a barre CSS puro (created/published per giorno,
 *   altezza %, legenda), tabelle per piattaforma / per agente / top articoli in
 *   pannelli curati, contatori iscritti. Empty state IT: "Nessun dato nel periodo".
 *
 * v1.0.7 — Fix overflow mobile (contratto §2 T-06): le 3 tabelle dati sono
 * avvolte in <div class="nxa-table-wrap"> (wrapper overflow-x:auto definito da
 * T-04 nel CSS condiviso, con fallback locale identico per restare
 * autosufficiente), le celle hanno word-break (overflow-wrap:anywhere) per
 * titoli/nomi/URL lunghi e il grafico a barre su mobile stretto scorre in
 * orizzontale invece di schiacciare le colonne. Id e data-* invariati.
 *
 * @package Nexus_News_AI
 * @since   1.0.4
 * @since   1.0.6 Tema chiaro: stile convertito ai token §1 del contratto v1.0.6.
 * @since   1.0.7 Overflow mobile: wrapper .nxa-table-wrap sulle tabelle dati,
 *          word-break sulle celle e grafico a barre scrollabile su <=600px.
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
        <div class="nxa-an-panel nxa-an-chart-panel">
                <ul class="nxa-an-legend">
                        <li><span class="nxa-an-dot nxa-an-dot-created"></span><?php echo esc_html__( 'Creati', 'nexus-news-ai' ); ?></li>
                        <li><span class="nxa-an-dot nxa-an-dot-published"></span><?php echo esc_html__( 'Pubblicati', 'nexus-news-ai' ); ?></li>
                </ul>
                <div id="nxa-an-chart" class="nxa-an-chart"></div>
        </div>

        <h2><?php echo esc_html__( 'Pubblicazioni per piattaforma', 'nexus-news-ai' ); ?></h2>
        <div class="nxa-an-panel">
                <div class="nxa-table-wrap">
                        <table class="widefat striped nxa-an-table" id="nxa-an-platform">
                                <thead>
                                        <tr>
                                                <th><?php echo esc_html__( 'Piattaforma', 'nexus-news-ai' ); ?></th>
                                                <th><?php echo esc_html__( 'Pubblicati', 'nexus-news-ai' ); ?></th>
                                                <th><?php echo esc_html__( 'Falliti', 'nexus-news-ai' ); ?></th>
                                        </tr>
                                </thead>
                                <tbody></tbody>
                        </table>
                </div>
        </div>

        <h2><?php echo esc_html__( 'Attività per agente', 'nexus-news-ai' ); ?></h2>
        <div class="nxa-an-panel">
                <div class="nxa-table-wrap">
                        <table class="widefat striped nxa-an-table" id="nxa-an-agents">
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
                </div>
        </div>

        <h2><?php echo esc_html__( 'Articoli più visti', 'nexus-news-ai' ); ?></h2>
        <div class="nxa-an-panel">
                <div class="nxa-table-wrap">
                        <table class="widefat striped nxa-an-table" id="nxa-an-top">
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
                </div>
        </div>

        <h2><?php echo esc_html__( 'Iscritti newsletter', 'nexus-news-ai' ); ?></h2>
        <div class="nxa-an-cards nxa-an-subs">
                <div class="nxa-an-card"><span class="nxa-an-value" id="nxa-an-sub-confirmed">—</span><span class="nxa-an-label"><?php echo esc_html__( 'Confermati', 'nexus-news-ai' ); ?></span></div>
                <div class="nxa-an-card"><span class="nxa-an-value" id="nxa-an-sub-pending">—</span><span class="nxa-an-label"><?php echo esc_html__( 'In attesa', 'nexus-news-ai' ); ?></span></div>
                <div class="nxa-an-card"><span class="nxa-an-value" id="nxa-an-sub-unsubscribed">—</span><span class="nxa-an-label"><?php echo esc_html__( 'Disiscritti', 'nexus-news-ai' ); ?></span></div>
                <div class="nxa-an-card"><span class="nxa-an-value" id="nxa-an-sub-new">—</span><span class="nxa-an-label"><?php echo esc_html__( 'Nuovi nel periodo', 'nexus-news-ai' ); ?></span></div>
        </div>

        <style>
  /* Stile locale alla vista (1.0.6 "UI chiara & curata"): SOLO classi prefisso
   * .nxa-an- più selettori scoped dentro .nxa-an / .nxa-an-panel; divieto di
   * toccare il CSS condiviso. Consuma i token var(--nxa-*) di
   * admin/css/nexus-admin.css (:root = light, body.nexus-theme-dark = dark);
   * i fallback rgba() sono i valori light del contratto v1.0.6 e mantengono la
   * vista leggibile anche senza il foglio condiviso. Regole multilinea e zero
   * colori esadecimali: il checker statico tratta # come commento PHP. */
  .nxa-an h1 {
    margin: 0 0 6px;
    font-size: 1.55rem;
    font-weight: 800;
    line-height: 1.2;
    letter-spacing: -0.02em;
    color: var(--nxa-text, rgba(15, 26, 51, 1));
  }
  .nxa-an-sub {
    max-width: 820px;
    margin: 0 0 8px;
    color: var(--nxa-muted, rgba(92, 107, 132, 1));
  }
  .nxa-an-period {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    margin: 16px 0;
  }
  .nxa-an-period label {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--nxa-muted, rgba(92, 107, 132, 1));
  }
  .nxa-an-period select {
    min-width: 190px;
    min-height: 32px;
    border-color: var(--nxa-border, rgba(226, 232, 242, 1));
    border-radius: var(--nxa-radius-sm, 10px);
    background-color: var(--nxa-surface, rgba(255, 255, 255, 1));
    color: var(--nxa-text, rgba(15, 26, 51, 1));
  }
  .nxa-an-period select:focus-visible {
    outline: 2px solid var(--nxa-focus, rgba(8, 145, 178, 1));
    outline-offset: 2px;
  }
  .nxa-an h2 {
    margin: 28px 0 12px;
    font-size: 1.05rem;
    font-weight: 700;
    line-height: 1.3;
    color: var(--nxa-text, rgba(15, 26, 51, 1));
  }
  .nxa-an h2::after {
    content: "";
    display: block;
    width: 42px;
    height: 3px;
    margin-top: 8px;
    border-radius: 999px;
    background: var(--nxa-accent-grad, linear-gradient(135deg, rgba(124, 58, 237, 1) 0%, rgba(6, 182, 212, 1) 100%));
  }
  .nxa-an-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
    margin: 16px 0;
  }
  .nxa-an-card {
    position: relative;
    overflow: hidden;
    min-width: 0;
    padding: 16px;
    background: var(--nxa-surface, rgba(255, 255, 255, 1));
    border: 1px solid var(--nxa-border, rgba(226, 232, 242, 1));
    border-radius: var(--nxa-radius, 14px);
    box-shadow: var(--nxa-shadow, 0 1px 2px rgba(15, 23, 42, 0.05), 0 10px 28px rgba(15, 23, 42, 0.07));
    box-sizing: border-box;
  }
  .nxa-an-card::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: var(--nxa-accent-grad, linear-gradient(135deg, rgba(124, 58, 237, 1) 0%, rgba(6, 182, 212, 1) 100%));
  }
  .nxa-an-value {
    display: block;
    font-size: 28px;
    line-height: 1.2;
    font-weight: 700;
    letter-spacing: -0.01em;
    font-variant-numeric: tabular-nums;
    color: var(--nxa-text, rgba(15, 26, 51, 1));
  }
  .nxa-an-label {
    display: block;
    margin-top: 6px;
    color: var(--nxa-muted, rgba(92, 107, 132, 1));
  }
  .nxa-an-legend {
    list-style: none;
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    margin: 0 0 12px;
    padding: 0;
    font-size: 13px;
    color: var(--nxa-text-body, rgba(51, 65, 92, 1));
  }
  .nxa-an-legend li {
    display: flex;
    align-items: center;
  }
  .nxa-an-dot {
    display: inline-block;
    width: 10px;
    height: 10px;
    margin-right: 6px;
    border-radius: 3px;
  }
  .nxa-an-dot-created {
    background: var(--nxa-accent-1, rgba(124, 58, 237, 1));
  }
  .nxa-an-dot-published {
    background: var(--nxa-accent-2, rgba(6, 182, 212, 1));
  }
  .nxa-an-panel {
    margin: 0 0 16px;
    overflow: hidden;
    background: var(--nxa-surface, rgba(255, 255, 255, 1));
    border: 1px solid var(--nxa-border, rgba(226, 232, 242, 1));
    border-radius: var(--nxa-radius, 14px);
    box-shadow: var(--nxa-shadow, 0 1px 2px rgba(15, 23, 42, 0.05), 0 10px 28px rgba(15, 23, 42, 0.07));
    box-sizing: border-box;
  }
  .nxa-an-chart-panel {
    padding: 16px 16px 0;
    overflow: visible;
  }
  .nxa-an-panel .widefat {
    border: 0;
    background: transparent;
    box-shadow: none;
  }
  .nxa-an-panel .widefat th,
  .nxa-an-panel .widefat td {
    padding: 10px 12px;
    font-variant-numeric: tabular-nums;
  }
  .nxa-an-panel .widefat th {
    color: var(--nxa-text-body, rgba(51, 65, 92, 1));
    border-color: var(--nxa-border, rgba(226, 232, 242, 1));
  }
  .nxa-an-panel .widefat td {
    border-color: var(--nxa-border-soft, rgba(15, 23, 42, 0.08));
  }
  .nxa-an-panel .striped tbody tr:nth-child(odd) {
    background-color: var(--nxa-surface-2, rgba(242, 245, 250, 1));
  }
  .nxa-an-panel td.nxa-an-empty {
    padding: 20px 12px;
    text-align: center;
  }
  .nxa-an-chart {
    display: flex;
    align-items: flex-end;
    justify-content: space-around;
    height: 240px;
    gap: 3px;
    background-image: linear-gradient(var(--nxa-border-soft, rgba(15, 23, 42, 0.08)) 1px, transparent 1px);
    background-size: 100% 25%;
    box-sizing: border-box;
  }
  .nxa-an-day {
    position: relative;
    display: flex;
    align-items: flex-end;
    flex: 1 1 0;
    height: 100%;
    max-width: 72px;
    min-width: 0;
    gap: 2px;
    border-radius: 4px;
    transition: background-color 0.15s ease;
  }
  .nxa-an-day:hover {
    background: var(--nxa-surface-2, rgba(242, 245, 250, 1));
  }
  .nxa-an-bar {
    flex: 1 1 0;
    min-height: 2px;
    border-radius: 2px 2px 0 0;
    pointer-events: none;
    transition: transform 0.18s ease, filter 0.18s ease;
  }
  .nxa-an-bar-created {
    background: var(--nxa-accent-1, rgba(124, 58, 237, 1));
  }
  .nxa-an-bar-published {
    background: var(--nxa-accent-2, rgba(6, 182, 212, 1));
  }
  .nxa-an-day:hover .nxa-an-bar {
    transform: translateY(-2px);
    filter: brightness(1.06);
  }
  .nxa-an-day::after {
    content: attr(title);
    position: absolute;
    bottom: calc(100% + 6px);
    left: 50%;
    z-index: 5;
    padding: 6px 10px;
    border-radius: var(--nxa-radius-sm, 10px);
    background: var(--nxa-text, rgba(15, 26, 51, 1));
    color: var(--nxa-surface, rgba(255, 255, 255, 1));
    font-size: 12px;
    font-weight: 500;
    line-height: 1.4;
    white-space: nowrap;
    box-shadow: var(--nxa-shadow, 0 1px 2px rgba(15, 23, 42, 0.05), 0 10px 28px rgba(15, 23, 42, 0.07));
    opacity: 0;
    transform: translateX(-50%) translateY(2px);
    transition: opacity 0.15s ease, transform 0.15s ease;
    pointer-events: none;
  }
  .nxa-an-day:hover::after {
    opacity: 1;
    transform: translateX(-50%) translateY(0);
  }
  .nxa-an-error {
    margin: 12px 0;
    padding: 12px 16px;
    border: 1px solid var(--nxa-err-line, rgba(220, 38, 38, 0.35));
    border-radius: var(--nxa-radius-sm, 10px);
    background: var(--nxa-err-bg, rgba(239, 68, 68, 0.10));
    color: var(--nxa-err-text, rgba(185, 28, 28, 1));
    font-weight: 500;
  }
  .nxa-an-error[hidden] {
    display: none;
  }
  .nxa-an-empty {
    color: var(--nxa-muted, rgba(92, 107, 132, 1));
  }
  .nxa-an-chart .nxa-an-empty {
    margin: auto;
    padding: 24px;
    text-align: center;
  }
  .nxa-an-muted {
    color: var(--nxa-muted, rgba(92, 107, 132, 1));
  }
  /* v1.0.7 — overflow mobile: le tabelle dati scorrono in orizzontale dentro
   * il wrapper .nxa-table-wrap (contratto CSS condiviso di T-04; il blocco qui
   * sotto è il fallback locale identico che tiene la vista autosufficiente
   * anche senza il foglio condiviso) e le celle vanno a capo su titoli, nomi
   * e URL lunghi senza forzare la larghezza delle colonne. */
  .nxa-an-panel .nxa-table-wrap {
    max-width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }
  .nxa-an-panel .nxa-an-table th,
  .nxa-an-panel .nxa-an-table td {
    overflow-wrap: anywhere;
  }
  /* Mobile stretto: il grafico a barre scorre in orizzontale invece di
   * schiacciare le colonne (larghezza minima per giornata; con serie corte le
   * colonne si distendono comunque grazie a flex-grow). justify-content passa
   * a flex-start perché con contenuto in overflow space-around lo farebbe
   * uscire da entrambi i lati, rendendo il primo giorno irraggiungibile. Il
   * tooltip CSS è disattivo qui per non essere troncato dallo scroll: resta
   * il tooltip nativo del title, come già avviene sui touch. */
  @media (max-width: 600px) {
    .nxa-an-chart {
      justify-content: flex-start;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }
    .nxa-an-day {
      min-width: 14px;
    }
    .nxa-an-day::after {
      display: none;
    }
  }
  @media (max-width: 782px) {
    .nxa-an-chart {
      height: 200px;
      gap: 2px;
    }
    .nxa-an-day {
      gap: 1px;
    }
  }
  @media (hover: none) {
    .nxa-an-day::after {
      display: none;
    }
  }
  @media (prefers-reduced-motion: reduce) {
    .nxa-an-day,
    .nxa-an-bar,
    .nxa-an-day::after {
      transition: none;
    }
    .nxa-an-day:hover .nxa-an-bar {
      transform: none;
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
