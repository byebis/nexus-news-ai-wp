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
 * @since   1.1.13.1 Card "Insight performance" (task Q1): sezione server-side
 *          dopo le stat-card, dati REALI da Nexus_Analytics::insight() sulla
 *          tabella nexus_metrics (GSC/GA4) con stati onesti (Google non
 *          connesso / sync non ancora arrivato / errore interno confinato in
 *          try/catch Throwable: la card non può rompere la pagina). Selettore
 *          periodo DEDICATO alla card (form GET server-side, id
 *          nxa-an-insight-days): il selettore #nxa-an-days esistente e la sua
 *          logica JS restano intoccati.
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

        <?php
        /*
         * 1.1.13.1 (Q1) — Card "Insight performance": dati REALI dalla tabella
         * nexus_metrics (GSC/GA4) via Nexus_Analytics::insight(), renderizzati
         * SERVER-SIDE (nessuna rotta REST nuova). La card è difensiva: qualunque
         * problema (classe mancante, DB irraggiungibile, dato anomalo) produce
         * SOLO un messaggio onesto dentro il pannello — il resto della pagina
         * Analytics resta intatto (try/catch Throwable, convenzione del task).
         */
        $nxa_insight     = array();
        $nxa_insight_err = '';
        try {
                if ( class_exists( 'Nexus_Analytics' ) && method_exists( 'Nexus_Analytics', 'insight' ) ) {
                        // GET di sola lettura (nessuna scrittura): nonce non richiesto.
                        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                        $nxa_insight = Nexus_Analytics::insight( isset( $_GET['nxa_insight_days'] ) ? $_GET['nxa_insight_days'] : 30 );
                } else {
                        $nxa_insight_err = __( 'Modulo analytics non disponibile: impossibile leggere le metriche di performance.', 'nexus-news-ai' );
                }
        } catch ( Throwable $e ) {
                $nxa_insight_err = __( 'Dati performance temporaneamente non disponibili (errore interno). Il resto della pagina resta funzionante.', 'nexus-news-ai' );
        }
        ?>

        <h2><?php echo esc_html__( 'Insight performance', 'nexus-news-ai' ); ?></h2>
        <div class="nxa-an-panel nxa-an-ins">

                <?php if ( '' !== $nxa_insight_err ) : ?>

                        <p class="nxa-an-ins-note"><?php echo esc_html( $nxa_insight_err ); ?></p>

                <?php elseif ( empty( $nxa_insight['connected'] ) ) : ?>

                        <p class="nxa-an-ins-note"><?php echo esc_html__( 'Google non è ancora connesso: collega Search Console o Analytics 4 nelle Impostazioni per vedere l\'insight performance.', 'nexus-news-ai' ); ?></p>
                        <p class="nxa-an-ins-cta">
                                <a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=nexus-settings&tab=sistema' ) ); ?>"><?php echo esc_html__( 'Connetti Google', 'nexus-news-ai' ); ?></a>
                        </p>

                <?php elseif ( empty( $nxa_insight['has_data'] ) ) : ?>

                        <p class="nxa-an-ins-note"><?php echo esc_html__( 'Sync ancora non arrivato: i dati compaiono dopo il primo sync giornaliero.', 'nexus-news-ai' ); ?></p>

                <?php else : ?>

                        <?php
                        $nxa_ins_days = isset( $nxa_insight['days'] ) ? absint( $nxa_insight['days'] ) : 30;
                        $nxa_ins_win  = isset( $nxa_insight['window'] ) && is_array( $nxa_insight['window'] ) ? $nxa_insight['window'] : array();
                        $nxa_ins_tot  = isset( $nxa_insight['totals'] ) && is_array( $nxa_insight['totals'] ) ? $nxa_insight['totals'] : array();
                        $nxa_ins_trd  = isset( $nxa_insight['trend'] ) && is_array( $nxa_insight['trend'] ) ? $nxa_insight['trend'] : array();
                        ?>

                        <div class="nxa-an-ins-head">
                                <p class="nxa-an-ins-meta">
                                        <?php
                                        printf(
                                                /* translators: 1: giorni, 2: data inizio, 3: data fine. */
                                                esc_html__( 'Ultimi %1$d giorni (%2$s → %3$s) vs periodo precedente uguale.', 'nexus-news-ai' ),
                                                $nxa_ins_days,
                                                esc_html( isset( $nxa_ins_win['start'] ) ? (string) $nxa_ins_win['start'] : '' ),
                                                esc_html( isset( $nxa_ins_win['end'] ) ? (string) $nxa_ins_win['end'] : '' )
                                        );
                                        ?>
                                </p>
                                <form class="nxa-an-period nxa-an-ins-period" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
                                        <input type="hidden" name="page" value="nexus-analytics" />
                                        <label for="nxa-an-insight-days"><?php echo esc_html__( 'Periodo', 'nexus-news-ai' ); ?></label>
                                        <select id="nxa-an-insight-days" name="nxa_insight_days">
                                                <?php
                                                // Etichette IDENTICHE alle opzioni del selettore esistente
                                                // (stesse stringhe i18n già in dizionario).
                                                $nxa_ins_labels = array(
                                                        7  => __( 'Ultimi 7 giorni', 'nexus-news-ai' ),
                                                        30 => __( 'Ultimi 30 giorni', 'nexus-news-ai' ),
                                                        90 => __( 'Ultimi 90 giorni', 'nexus-news-ai' ),
                                                );
                                                foreach ( $nxa_ins_labels as $nxa_ins_d => $nxa_ins_label ) :
                                                        ?>
                                                        <option value="<?php echo absint( $nxa_ins_d ); ?>" <?php selected( $nxa_ins_days, $nxa_ins_d ); ?>><?php echo esc_html( $nxa_ins_label ); ?></option>
                                                <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="button button-secondary"><?php echo esc_html__( 'Applica', 'nexus-news-ai' ); ?></button>
                                </form>
                        </div>

                        <?php
                        /* Trend semplice vs periodo precedente uguale. La classe ha
                         * già invertito la semantica della POSIZIONE ('up' =
                         * migliorata): qui si renderizza solo il dato. */
                        $nxa_ins_trend_html = array();
                        foreach ( array( 'clicks', 'impressions', 'views', 'position' ) as $nxa_ins_k ) {
                                $nxa_t   = isset( $nxa_ins_trd[ $nxa_ins_k ] ) && is_array( $nxa_ins_trd[ $nxa_ins_k ] ) ? $nxa_ins_trd[ $nxa_ins_k ] : array();
                                $nxa_dir = isset( $nxa_t['dir'] ) ? (string) $nxa_t['dir'] : 'none';
                                $nxa_pct = isset( $nxa_t['pct'] ) && null !== $nxa_t['pct'] ? (float) $nxa_t['pct'] : null;
                                $nxa_txt = '';
                                if ( 'new' === $nxa_dir ) {
                                        $nxa_txt = '↑ ' . __( 'nuovo', 'nexus-news-ai' );
                                } elseif ( 'up' === $nxa_dir && null !== $nxa_pct ) {
                                        $nxa_txt = '↑ +' . number_format_i18n( $nxa_pct, 1 ) . '%';
                                } elseif ( 'down' === $nxa_dir && null !== $nxa_pct ) {
                                        $nxa_txt = '↓ −' . number_format_i18n( abs( $nxa_pct ), 1 ) . '%';
                                } elseif ( 'flat' === $nxa_dir ) {
                                        $nxa_txt = '→ 0%';
                                }
                                $nxa_ins_trend_html[ $nxa_ins_k ] = '' !== $nxa_txt
                                        ? '<span class="nxa-an-delta nxa-an-delta-' . esc_attr( $nxa_dir ) . '">' . esc_html( $nxa_txt ) . '</span>'
                                        : '';
                        }
                        ?>

                        <div class="nxa-an-cards nxa-an-ins-totals">
                                <div class="nxa-an-card">
                                        <span class="nxa-an-value"><?php echo esc_html( number_format_i18n( isset( $nxa_ins_tot['clicks'] ) ? (int) $nxa_ins_tot['clicks'] : 0 ) ); ?></span>
                                        <span class="nxa-an-label"><?php echo esc_html__( 'Click', 'nexus-news-ai' ); ?></span>
                                        <?php echo $nxa_ins_trend_html['clicks']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML costruito sopra con esc_html/esc_attr. ?>
                                </div>
                                <div class="nxa-an-card">
                                        <span class="nxa-an-value"><?php echo esc_html( number_format_i18n( isset( $nxa_ins_tot['impressions'] ) ? (int) $nxa_ins_tot['impressions'] : 0 ) ); ?></span>
                                        <span class="nxa-an-label"><?php echo esc_html__( 'Impressioni', 'nexus-news-ai' ); ?></span>
                                        <?php echo $nxa_ins_trend_html['impressions']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML costruito sopra con esc_html/esc_attr. ?>
                                </div>
                                <div class="nxa-an-card">
                                        <span class="nxa-an-value"><?php echo esc_html( number_format_i18n( isset( $nxa_ins_tot['views'] ) ? (int) $nxa_ins_tot['views'] : 0 ) ); ?></span>
                                        <span class="nxa-an-label"><?php echo esc_html__( 'Viste', 'nexus-news-ai' ); ?></span>
                                        <?php echo $nxa_ins_trend_html['views']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML costruito sopra con esc_html/esc_attr. ?>
                                </div>
                                <div class="nxa-an-card">
                                        <?php $nxa_ins_pos = isset( $nxa_ins_tot['avg_position'] ) ? (float) $nxa_ins_tot['avg_position'] : 0.0; ?>
                                        <span class="nxa-an-value"><?php echo esc_html( $nxa_ins_pos > 0 ? number_format_i18n( $nxa_ins_pos, 1 ) : '—' ); ?></span>
                                        <span class="nxa-an-label"><?php echo esc_html__( 'Posizione media', 'nexus-news-ai' ); ?></span>
                                        <?php echo $nxa_ins_trend_html['position']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML costruito sopra con esc_html/esc_attr. ?>
                                </div>
                        </div>

                        <div class="nxa-table-wrap">
                                <table class="widefat striped nxa-an-table" id="nxa-an-insight-top">
                                        <thead>
                                                <tr>
                                                        <th><?php echo esc_html__( 'Articolo', 'nexus-news-ai' ); ?></th>
                                                        <th><?php echo esc_html__( 'Click', 'nexus-news-ai' ); ?></th>
                                                        <th><?php echo esc_html__( 'Impressioni', 'nexus-news-ai' ); ?></th>
                                                        <th><?php echo esc_html__( 'CTR', 'nexus-news-ai' ); ?></th>
                                                        <th><?php echo esc_html__( 'Posizione media', 'nexus-news-ai' ); ?></th>
                                                        <th><?php echo esc_html__( 'Viste', 'nexus-news-ai' ); ?></th>
                                                </tr>
                                        </thead>
                                        <tbody>
                                                <?php foreach ( (array) $nxa_insight['top'] as $nxa_ins_row ) : ?>
                                                        <?php
                                                        $nxa_ins_title = isset( $nxa_ins_row['title'] ) ? (string) $nxa_ins_row['title'] : '';
                                                        $nxa_ins_link  = isset( $nxa_ins_row['link'] ) ? (string) $nxa_ins_row['link'] : '';
                                                        $nxa_ins_ctr   = isset( $nxa_ins_row['ctr'] ) && null !== $nxa_ins_row['ctr'] ? (float) $nxa_ins_row['ctr'] : null;
                                                        $nxa_ins_pmed  = isset( $nxa_ins_row['position'] ) ? (float) $nxa_ins_row['position'] : 0.0;
                                                        ?>
                                                        <tr>
                                                                <td>
                                                                        <?php if ( '' !== $nxa_ins_link ) : ?>
                                                                                <a href="<?php echo esc_url( $nxa_ins_link ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $nxa_ins_title ); ?></a>
                                                                        <?php else : ?>
                                                                                <?php echo esc_html( $nxa_ins_title ); ?>
                                                                        <?php endif; ?>
                                                                </td>
                                                                <td><?php echo esc_html( number_format_i18n( isset( $nxa_ins_row['clicks'] ) ? (int) $nxa_ins_row['clicks'] : 0 ) ); ?></td>
                                                                <td><?php echo esc_html( number_format_i18n( isset( $nxa_ins_row['impressions'] ) ? (int) $nxa_ins_row['impressions'] : 0 ) ); ?></td>
                                                                <td><?php echo esc_html( null === $nxa_ins_ctr ? '—' : number_format_i18n( $nxa_ins_ctr, 1 ) . '%' ); ?></td>
                                                                <td><?php echo esc_html( $nxa_ins_pmed > 0 ? number_format_i18n( $nxa_ins_pmed, 1 ) : '—' ); ?></td>
                                                                <td><?php echo esc_html( number_format_i18n( isset( $nxa_ins_row['views'] ) ? (int) $nxa_ins_row['views'] : 0 ) ); ?></td>
                                                        </tr>
                                                <?php endforeach; ?>
                                        </tbody>
                                </table>
                        </div>

                        <p class="nxa-an-ins-meta nxa-an-ins-order"><?php echo esc_html__( 'Ordinati per click Search Console, poi per viste Analytics 4.', 'nexus-news-ai' ); ?></p>

                <?php endif; ?>
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
