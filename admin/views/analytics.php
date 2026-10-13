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
 * - Stili con SOLO classi prefisso .nxa-an- (più selettori scoped dentro
 *   .nxa-an / .nxa-an-panel; divieto di toccare il CSS condiviso): consumano i
 *   token var(--nxa-*) del tema da admin/css/nexus-admin.css (enqueued su
 *   ogni pagina admin del plugin). Dalla 1.1.13 vivono in
 *   admin/css/nexus-analytics.css (token puri, fallback rgba rimossi).
 * - Tooltip del grafico: solo CSS via content: attr(title) su .nxa-an-day (il
 *   title è già impostato dal JS): fondello scuro adattivo — background
 *   var(--nxa-text), testo var(--nxa-surface) — che si inverte da solo nel tema
 *   dark; il tooltip nativo del title resta come fallback (touch, hover prolungato).
 * - Script IIFE ES2019 (dalla 1.1.13 in assets/js/nexus-analytics.js, statico:
 *   zero PHP interpolato): rendering completo client-side con esc() su ogni
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
 * @since   1.1.13 Estrazione stile/script inline in admin/css/nexus-analytics.css
 *          e assets/js/nexus-analytics.js (task O5): markup, selettori e logica
 *          invariati, palette unificata sui token (fallback rgba rimossi).
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

        <?php
        /*
         * Stili della vista estratti in admin/css/nexus-analytics.css
         * (1.1.13, task O5): stessi selettori, palette unificata sui token
         * var(--nxa-*) del foglio condiviso (fallback rgba/px rimossi).
         * Enqueue in class-nexus-admin.php, pagina analytics.
         */
        ?>

        <?php
        /*
         * Script della vista estratto in assets/js/nexus-analytics.js
         * (1.1.13, task O5): IIFE statica, zero PHP interpolato, byte-intatta.
         * Enqueue in class-nexus-admin.php (in_footer: usa window.NexusAdminData).
         */
        ?>

</div>
