<?php
/**
 * Vista Admin: "Strumenti" (pagina "tools") — v1.0.5.
 *
 * SELF-CONTAINED (contratto CONTRATTO-v1.0.5.md §2 T-02): markup + <style> inline
 * (SOLO classi prefisso .nxa-tools-, nessun tocco a admin/css/nexus-admin.css) e
 * <script> IIFE ES2019 inline (nessun tocco ad assets/js/admin.js). La sezione 1
 * (informazioni di sistema) è renderizzata in PHP puro con esc_html; le sezioni
 * 2-4 consumano le REST admin di nexus/v1.
 *
 * CONTRATTO REST (rotte definite nel §2 T-03, namespace nexus/v1, perm admin):
 * - GET  {restUrl}/export        → { version, exported_at, site_url, settings, agents }
 *                                  ATTENZIONE: il file esportato contiene segreti
 *                                  (chiavi API/token): conservalo in modo sicuro.
 * - POST {restUrl}/import        body: JSON prodotto da GET /export
 *                                  → { settings_updated, agents_updated,
 *                                      agents_inserted, errors: [] }
 * - POST {restUrl}/tools/api-key → { api_key } (la chiave precedente diventa invalida)
 * Autenticazione: header X-WP-Nonce = NexusAdminData.nonce (localizzato lato admin).
 *
 * CONTRATTO DOM (selettori fissi consumati dallo script inline in fondo al file):
 * - #nxa-tools-export         bottone "Scarica JSON" (sezione Esporta).
 * - #nxa-tools-import-file    input file accept=".json,application/json".
 * - #nxa-tools-import         bottone "Importa".
 * - #nxa-tools-import-result  box esiti dell'importazione.
 * - #nxa-tools-key            input readonly con la chiave API pubblica corrente.
 * - #nxa-tools-keygen         bottone "Genera nuova chiave" (con window.confirm).
 * - Toast: .nxa-tools-toast (--success / --error) su body, rimosso dopo 4 secondi.
 *
 * NOTE: la sezione 1 usa SOLO PHP (nessun fetch): versione plugin/WP/PHP, locale,
 * multisite, memory_limit, max_execution_time, lock WP-Cron, stato tabelle con
 * conteggio righe, eventi WP-Cron con prossima esecuzione, stat coda lavori.
 *
 * @package Nexus_News_AI
 * @since   1.0.5
 */

defined( 'ABSPATH' ) || exit;

$settings = class_exists( 'Nexus_DB' ) ? Nexus_DB::get_settings() : array();

/* Dati della sezione 1 con guardie: degradazione grazia, mai errori fatali. */
$nexus_tables = array();
$job_stats    = array();
if ( class_exists( 'Nexus_DB' ) ) {
        if ( method_exists( 'Nexus_DB', 'tables' ) ) {
                $nexus_tables = (array) Nexus_DB::tables();
        }
        if ( method_exists( 'Nexus_DB', 'job_stats' ) ) {
                $job_stats = (array) Nexus_DB::job_stats();
        }
}

/* API pubblica: stato e chiave corrente (sezione 4). */
$api_enabled = ! empty( $settings['enable_public_api'] );
$api_key     = isset( $settings['public_api_key'] ) ? (string) $settings['public_api_key'] : '';

/* Riga ambiente: ini_get può ritornare false o '' (mostro un trattino). */
$mem_raw   = ini_get( 'memory_limit' );
$exe_raw   = ini_get( 'max_execution_time' );
$mem_val   = ( is_string( $mem_raw ) && '' !== $mem_raw ) ? $mem_raw : '—';
$exe_val   = ( is_string( $exe_raw ) && '' !== $exe_raw ) ? $exe_raw : '—';
$cron_lock = defined( 'WP_CRON_LOCK_TIMEOUT' ) ? (string) WP_CRON_LOCK_TIMEOUT : '—';

/* Righe "Ambiente" (etichetta, valore). */
$sys_rows = array(
        array( 'Versione plugin', NEXUS_VERSION ),
        array( 'Versione WordPress', get_bloginfo( 'version' ) ),
        array( 'Versione PHP', PHP_VERSION ),
        array( 'Locale del sito', get_locale() ),
        array( 'Multisite', is_multisite() ? 'Sì' : 'No' ),
        array( 'Limite memoria (memory_limit)', $mem_val ),
        array( 'Tempo massimo di esecuzione (max_execution_time)', $exe_val ),
        array( 'Lock WP-Cron (WP_CRON_LOCK_TIMEOUT)', $cron_lock ),
);

/* Righe "Tabelle database": esistenza + conteggio righe. */
global $wpdb;

$table_rows = array();
foreach ( $nexus_tables as $tname ) {
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tname ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( $exists ) {
                $count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $tname ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
                $table_rows[] = array( $tname, 'on', 'Presente — ' . $count . ' righe' );
        } else {
                $table_rows[] = array( $tname, 'off', 'Assente' );
        }
}

/* Righe "Eventi WP-Cron": prossima esecuzione formattata o trattino. */
$cron_events = array( 'nexus_cron_tick', 'nexus_queue_runner', 'nexus_weekly_digest', 'nexus_ping_article' );
$cron_rows   = array();
foreach ( $cron_events as $evt ) {
        $next_ts = wp_next_scheduled( $evt );
        $cron_rows[] = array( $evt, $next_ts ? date_i18n( 'd/m/Y H:i', $next_ts ) : '—' );
}

/* Righe "Coda lavori" (Nexus_DB::job_stats()). */
$queue_rows = array(
        array( 'In attesa', isset( $job_stats['pending'] ) ? (int) $job_stats['pending'] : 0 ),
        array( 'In esecuzione', isset( $job_stats['running'] ) ? (int) $job_stats['running'] : 0 ),
        array( 'Completati', isset( $job_stats['complete'] ) ? (int) $job_stats['complete'] : 0 ),
        array( 'Falliti', isset( $job_stats['failed'] ) ? (int) $job_stats['failed'] : 0 ),
        array( 'Totale', isset( $job_stats['total'] ) ? (int) $job_stats['total'] : 0 ),
);
?>
<div class="wrap nexus-tools nxa-tools" data-page="tools">

        <h1><?php esc_html_e( 'Strumenti', 'nexus-news-ai' ); ?></h1>

        <p class="nxa-tools-sub"><?php esc_html_e( "Diagnostica del sistema, esportazione e importazione della configurazione, gestione dell'API pubblica.", 'nexus-news-ai' ); ?></p>

        <!-- SEZIONE 1: informazioni di sistema (PHP puro, esc_html su tutto). -->
        <div class="nxa-tools-section">
                <h2 class="nxa-tools-section-title"><?php esc_html_e( 'Informazioni di sistema', 'nexus-news-ai' ); ?></h2>

                <h3 class="nxa-tools-group-title"><?php esc_html_e( 'Ambiente', 'nexus-news-ai' ); ?></h3>
                <table class="widefat striped nxa-tools-table">
                        <tbody>
                        <?php foreach ( $sys_rows as $sys_row ) : ?>
                                <tr>
                                        <th scope="row"><?php echo esc_html( $sys_row[0] ); ?></th>
                                        <td><code><?php echo esc_html( $sys_row[1] ); ?></code></td>
                                </tr>
                        <?php endforeach; ?>
                        </tbody>
                </table>

                <h3 class="nxa-tools-group-title"><?php esc_html_e( 'Tabelle database', 'nexus-news-ai' ); ?></h3>
                <table class="widefat striped nxa-tools-table">
                        <thead>
                                <tr>
                                        <th><?php esc_html_e( 'Tabella', 'nexus-news-ai' ); ?></th>
                                        <th><?php esc_html_e( 'Stato', 'nexus-news-ai' ); ?></th>
                                </tr>
                        </thead>
                        <tbody>
                        <?php if ( $table_rows ) : ?>
                                <?php foreach ( $table_rows as $table_row ) : ?>
                                        <tr>
                                                <td><code><?php echo esc_html( $table_row[0] ); ?></code></td>
                                                <td><span class="nxa-tools-badge nxa-tools-badge--<?php echo esc_attr( $table_row[1] ); ?>"><?php echo esc_html( $table_row[2] ); ?></span></td>
                                        </tr>
                                <?php endforeach; ?>
                        <?php else : ?>
                                <tr>
                                        <td colspan="2"><?php esc_html_e( 'Nexus_DB non disponibile: stato tabelle non verificabile.', 'nexus-news-ai' ); ?></td>
                                </tr>
                        <?php endif; ?>
                        </tbody>
                </table>

                <h3 class="nxa-tools-group-title"><?php esc_html_e( 'Eventi WP-Cron', 'nexus-news-ai' ); ?></h3>
                <table class="widefat striped nxa-tools-table">
                        <thead>
                                <tr>
                                        <th><?php esc_html_e( 'Evento', 'nexus-news-ai' ); ?></th>
                                        <th><?php esc_html_e( 'Prossima esecuzione', 'nexus-news-ai' ); ?></th>
                                </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $cron_rows as $cron_row ) : ?>
                                <tr>
                                        <td><code><?php echo esc_html( $cron_row[0] ); ?></code></td>
                                        <td><?php echo esc_html( $cron_row[1] ); ?></td>
                                </tr>
                        <?php endforeach; ?>
                        </tbody>
                </table>

                <h3 class="nxa-tools-group-title"><?php esc_html_e( 'Coda lavori', 'nexus-news-ai' ); ?></h3>
                <table class="widefat striped nxa-tools-table">
                        <tbody>
                        <?php foreach ( $queue_rows as $queue_row ) : ?>
                                <tr>
                                        <th scope="row"><?php echo esc_html( $queue_row[0] ); ?></th>
                                        <td><?php echo esc_html( $queue_row[1] ); ?></td>
                                </tr>
                        <?php endforeach; ?>
                        </tbody>
                </table>
        </div>

        <!-- SEZIONE 2: esporta configurazione (GET /export → download JSON lato client). -->
        <div class="nxa-tools-section">
                <h2 class="nxa-tools-section-title"><?php esc_html_e( 'Esporta configurazione', 'nexus-news-ai' ); ?></h2>
                <p class="nxa-tools-desc"><?php esc_html_e( 'Esporta impostazioni e agenti in un file JSON. NON include articoli, iscritti o log.', 'nexus-news-ai' ); ?></p>
                <p class="nxa-tools-warn"><?php esc_html_e( 'ATTENZIONE: il file contiene chiavi API e token: conservalo in modo sicuro.', 'nexus-news-ai' ); ?></p>
                <p class="nxa-tools-actions">
                        <button type="button" id="nxa-tools-export" class="button button-primary"><?php esc_html_e( 'Scarica JSON', 'nexus-news-ai' ); ?></button>
                </p>
        </div>

        <!-- SEZIONE 3: importa configurazione (POST /import con JSON del file scelto). -->
        <div class="nxa-tools-section">
                <h2 class="nxa-tools-section-title"><?php esc_html_e( 'Importa configurazione', 'nexus-news-ai' ); ?></h2>
                <p class="nxa-tools-desc"><?php esc_html_e( 'Carica un file JSON esportato da Nexus News AI per ripristinare impostazioni e agenti.', 'nexus-news-ai' ); ?></p>
                <p class="nxa-tools-warn"><?php esc_html_e( "L'import sovrascrive impostazioni e agenti esistenti (match per categoria).", 'nexus-news-ai' ); ?></p>
                <p class="nxa-tools-actions">
                        <label class="screen-reader-text" for="nxa-tools-import-file"><?php esc_html_e( 'File di configurazione JSON', 'nexus-news-ai' ); ?></label>
                        <input type="file" id="nxa-tools-import-file" accept=".json,application/json">
                        <button type="button" id="nxa-tools-import" class="button button-primary"><?php esc_html_e( 'Importa', 'nexus-news-ai' ); ?></button>
                </p>
                <div id="nxa-tools-import-result" class="nxa-tools-result" role="status" aria-live="polite"><?php esc_html_e( 'Nessuna importazione eseguita in questa sessione.', 'nexus-news-ai' ); ?></div>
        </div>

        <!-- SEZIONE 4: API pubblica (stato, chiave, keygen, esempio curl). -->
        <div class="nxa-tools-section">
                <h2 class="nxa-tools-section-title"><?php esc_html_e( 'API pubblica', 'nexus-news-ai' ); ?></h2>
                <p class="nxa-tools-desc">
                        <span class="nxa-tools-badge nxa-tools-badge--<?php echo esc_attr( $api_enabled ? 'on' : 'off' ); ?>"><?php echo esc_html( $api_enabled ? 'Attiva' : 'Disattivata' ); ?></span>
                </p>
                <p class="nxa-tools-actions">
                        <label class="nxa-tools-key-label" for="nxa-tools-key"><?php esc_html_e( 'Chiave API corrente', 'nexus-news-ai' ); ?></label>
                        <input type="text" id="nxa-tools-key" class="nxa-tools-key" readonly value="<?php echo esc_attr( $api_key ); ?>" placeholder="<?php esc_attr_e( 'Nessuna chiave generata', 'nexus-news-ai' ); ?>">
                        <button type="button" id="nxa-tools-keygen" class="button"><?php esc_html_e( 'Genera nuova chiave', 'nexus-news-ai' ); ?></button>
                </p>
                <p class="nxa-tools-desc"><?php esc_html_e( "Attiva o disattiva l'API in Impostazioni → Lingua e API pubblica.", 'nexus-news-ai' ); ?></p>
                <h3 class="nxa-tools-group-title"><?php esc_html_e( 'Esempio di richiesta', 'nexus-news-ai' ); ?></h3>
                <p class="nxa-tools-desc"><?php esc_html_e( 'Sostituisci TUA_CHIAVE con la chiave generata qui.', 'nexus-news-ai' ); ?></p>
                <p><code class="nxa-tools-code">curl -H &quot;X-Api-Key: TUA_CHIAVE&quot; <?php echo esc_url( home_url( '/' ) ); ?>wp-json/nexus-public/v1/articles</code></p>
        </div>

        <noscript>
                <div class="notice notice-error inline">
                        <p><?php esc_html_e( 'JavaScript è disattivato: esportazione, importazione e generazione della chiave richiedono JavaScript.', 'nexus-news-ai' ); ?></p>
                </div>
        </noscript>

</div>

<style>
        /* Stile locale della vista Strumenti: SOLO classi prefisso .nxa-tools- (contratto §2 T-02). */
        .nxa-tools-sub {
                max-width: 780px;
                margin: 8px 0 16px;
                color: #50575e;
        }
        .nxa-tools-section {
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 8px;
                padding: 16px;
                margin: 0 0 16px;
        }
        .nxa-tools-section-title {
                margin: 0 0 12px;
                font-size: 16px;
        }
        .nxa-tools-group-title {
                margin: 16px 0 8px;
                font-size: 13px;
                text-transform: uppercase;
                letter-spacing: 0.03em;
                color: #646970;
        }
        .nxa-tools-table {
                margin: 0 0 4px;
        }
        .nxa-tools-table th {
                width: 340px;
        }
        .nxa-tools-desc {
                margin: 0 0 10px;
                color: #50575e;
        }
        .nxa-tools-warn {
                display: inline-block;
                margin: 0 0 10px;
                padding: 8px 12px;
                background: #fef3c7;
                border: 1px solid #f59e0b;
                border-radius: 4px;
                color: #92400e;
        }
        .nxa-tools-actions {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 10px;
                margin: 0 0 10px;
        }
        .nxa-tools-key {
                min-width: 320px;
                font-family: Consolas, Monaco, monospace;
        }
        .nxa-tools-key-label {
                font-weight: 600;
        }
        .nxa-tools-code {
                display: inline-block;
                max-width: 100%;
                padding: 8px 12px;
                background: #f0f0f1;
                border: 1px solid #dcdcde;
                border-radius: 4px;
                overflow-x: auto;
                white-space: pre-wrap;
                word-break: break-all;
        }
        .nxa-tools-badge {
                display: inline-block;
                padding: 2px 10px;
                border-radius: 999px;
                border: 1px solid transparent;
                font-size: 12px;
                font-weight: 600;
                line-height: 1.6;
                white-space: nowrap;
        }
        .nxa-tools-badge--on {
                color: #14532d;
                background: #dcfce7;
                border-color: #22c55e;
        }
        .nxa-tools-badge--off {
                color: #7f1d1d;
                background: #fee2e2;
                border-color: #ef4444;
        }
        .nxa-tools-result {
                margin: 4px 0 0;
                padding: 8px 12px;
                border: 1px solid #dcdcde;
                border-radius: 4px;
                background: #fff;
                color: #50575e;
        }
        .nxa-tools-result--ok {
                border-color: #22c55e;
                background: #dcfce7;
                color: #14532d;
        }
        .nxa-tools-result--warn {
                border-color: #ef4444;
                background: #fee2e2;
                color: #7f1d1d;
        }
        .nxa-tools-toast {
                position: fixed;
                right: 24px;
                bottom: 24px;
                z-index: 99999;
                max-width: 360px;
                padding: 10px 16px;
                border-radius: 4px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.25);
                color: #fff;
                font-size: 13px;
                line-height: 1.5;
        }
        .nxa-tools-toast--success {
                background: #22c55e;
        }
        .nxa-tools-toast--error {
                background: #ef4444;
        }
</style>

<script>
        (function () {
                'use strict';

                /* Guard: la vista dipende dai dati localizzati in pagina dal plugin. */
                if (!window.NexusAdminData || !window.NexusAdminData.restUrl || !window.NexusAdminData.nonce) {
                        if (window.console && window.console.warn) {
                                window.console.warn('[Nexus News AI] NexusAdminData non disponibile: vista Strumenti non inizializzata.');
                        }
                        return;
                }

                var base = String(window.NexusAdminData.restUrl).replace(/\/+$/, '');
                var nonce = window.NexusAdminData.nonce;

                /* Riferimenti DOM (selettori fissi del markup sopra). */
                var exportBtn = document.getElementById('nxa-tools-export');
                var importBtn = document.getElementById('nxa-tools-import');
                var importFile = document.getElementById('nxa-tools-import-file');
                var importBox = document.getElementById('nxa-tools-import-result');
                var keyInput = document.getElementById('nxa-tools-key');
                var keygenBtn = document.getElementById('nxa-tools-keygen');

                /* Stato pagina: true mentre gira una chiamata (blocca i doppi click). */
                var busy = false;
                var toastEl = null;
                var toastTimer = null;

                /* Escape di ogni valore dinamico prima di innerHTML (contratto §2 T-02). */
                function esc(value) {
                        return String(value === null || value === undefined ? '' : value)
                                .replace(/&/g, '&amp;')
                                .replace(/</g, '&lt;')
                                .replace(/>/g, '&gt;')
                                .replace(/\x22/g, '&quot;')
                                .replace(/\x27/g, '&#039;');
                }

                /* Toast flottante in basso a destra; si rimuove da solo dopo 4 secondi. */
                function toast(message, tipo) {
                        if (!toastEl) {
                                toastEl = document.createElement('div');
                                document.body.appendChild(toastEl);
                        }
                        toastEl.className = 'nxa-tools-toast ' + (tipo === 'error' ? 'nxa-tools-toast--error' : 'nxa-tools-toast--success');
                        toastEl.textContent = String(message);
                        if (toastTimer) {
                                window.clearTimeout(toastTimer);
                        }
                        toastTimer = window.setTimeout(function () {
                                if (toastEl && toastEl.parentNode) {
                                        toastEl.parentNode.removeChild(toastEl);
                                }
                                toastEl = null;
                                toastTimer = null;
                        }, 4000);
                }

                /* Wrapper fetch: nonce + JSON; su errore HTTP lancia Error col messaggio del body. */
                function api(path, opts) {
                        opts = opts || {};
                        var headers = { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' };
                        var init = { method: opts.method || 'GET', headers: headers, credentials: 'same-origin' };
                        var key;
                        if (opts.headers) {
                                for (key in opts.headers) {
                                        if (Object.prototype.hasOwnProperty.call(opts.headers, key)) {
                                                headers[key] = opts.headers[key];
                                        }
                                }
                        }
                        if (opts.body) {
                                init.body = opts.body;
                        }
                        return fetch(base + path, init).then(function (response) {
                                return response.text().then(function (text) {
                                        var data = null;
                                        try {
                                                data = text ? JSON.parse(text) : null;
                                        } catch (parseError) {
                                                data = null;
                                        }
                                        if (!response.ok) {
                                                var message = 'Errore HTTP ' + response.status;
                                                if (data && data.message) {
                                                        message = String(data.message);
                                                }
                                                throw new Error(message);
                                        }
                                        return data;
                                });
                        });
                }

                /* Data locale odierna "YYYY-MM-DD" per il nome file di export. */
                function localDate() {
                        var d = new Date();
                        var m = d.getMonth() + 1;
                        var day = d.getDate();
                        var mm = m < 10 ? '0' + m : String(m);
                        var dd = day < 10 ? '0' + day : String(day);
                        return String(d.getFullYear()) + '-' + mm + '-' + dd;
                }

                /* Intero difensivo dai payload REST. */
                function toInt(value) {
                        var n = parseInt(value, 10);
                        return isNaN(n) ? 0 : n;
                }

                /* SEZIONE 2: esportazione → GET /export → Blob JSON → download locale. */
                if (exportBtn) {
                        exportBtn.addEventListener('click', function () {
                                if (busy) {
                                        return;
                                }
                                busy = true;
                                exportBtn.disabled = true;
                                api('/export').then(function (json) {
                                        var payload = null;
                                        try {
                                                payload = JSON.stringify(json, null, 2);
                                        } catch (stringifyError) {
                                                payload = null;
                                        }
                                        if (!payload) {
                                                payload = '{}';
                                        }
                                        var blob = new Blob([payload], { type: 'application/json' });
                                        var urlObj = URL.createObjectURL(blob);
                                        var link = document.createElement('a');
                                        link.href = urlObj;
                                        link.download = 'nexus-config-' + localDate() + '.json';
                                        document.body.appendChild(link);
                                        link.click();
                                        document.body.removeChild(link);
                                        window.setTimeout(function () {
                                                URL.revokeObjectURL(urlObj);
                                        }, 1000);
                                        toast('Configurazione esportata in nexus-config-' + localDate() + '.json', 'success');
                                }).catch(function (error) {
                                        toast('Esportazione non riuscita: ' + (error && error.message ? String(error.message) : 'errore sconosciuto'), 'error');
                                }).then(function () {
                                        busy = false;
                                        exportBtn.disabled = false;
                                });
                        });
                }

                /* SEZIONE 3: importazione → FileReader → POST /import → box esiti. */
                if (importBtn && importFile) {
                        importBtn.addEventListener('click', function () {
                                if (busy) {
                                        return;
                                }
                                var file = importFile.files && importFile.files.length > 0 ? importFile.files[0] : null;
                                if (!file) {
                                        toast('Seleziona prima un file JSON da importare.', 'error');
                                        return;
                                }
                                busy = true;
                                importBtn.disabled = true;

                                /* Rilascio stato e bottone a fine operazione (async). */
                                function finish() {
                                        busy = false;
                                        importBtn.disabled = false;
                                }

                                /* Box esiti: contenuto dinamico sempre escapato via esc(). */
                                function renderResult(line, tipo) {
                                        if (!importBox) {
                                                return;
                                        }
                                        importBox.className = 'nxa-tools-result' + (tipo === 'ok' ? ' nxa-tools-result--ok' : (tipo === 'warn' ? ' nxa-tools-result--warn' : ''));
                                        importBox.innerHTML = esc(line);
                                }

                                var reader = new FileReader();
                                reader.onerror = function () {
                                        finish();
                                        toast('Lettura del file non riuscita.', 'error');
                                };
                                reader.onload = function (ev) {
                                        var parsed = null;
                                        try {
                                                parsed = JSON.parse(String(ev && ev.target ? ev.target.result : ''));
                                        } catch (parseError) {
                                                parsed = null;
                                        }
                                        if (!parsed || typeof parsed !== 'object' || (parsed instanceof Array)) {
                                                finish();
                                                renderResult('File JSON non valido: atteso un oggetto con le chiavi settings e agents.', 'warn');
                                                toast('File JSON non valido.', 'error');
                                                return;
                                        }
                                        api('/import', { method: 'POST', body: JSON.stringify(parsed) }).then(function (data) {
                                                var su = data ? toInt(data.settings_updated) : 0;
                                                var au = data ? toInt(data.agents_updated) : 0;
                                                var ai = data ? toInt(data.agents_inserted) : 0;
                                                var errs = data && data.errors && data.errors.length ? data.errors : [];
                                                var line = 'Impostazioni aggiornate: ' + su + ' · Agenti aggiornati: ' + au + ' · Agenti creati: ' + ai;
                                                var i;
                                                if (errs.length) {
                                                        line += ' · Errori: ';
                                                        for (i = 0; i < errs.length; i++) {
                                                                if (i > 0) {
                                                                        line += ' — ';
                                                                }
                                                                line += String(errs[i]);
                                                        }
                                                }
                                                renderResult(line, errs.length ? 'warn' : 'ok');
                                                finish();
                                                toast(errs.length ? 'Import completato con errori' : 'Import completato', errs.length ? 'error' : 'success');
                                        }).catch(function (error) {
                                                finish();
                                                renderResult('Import non riuscito: ' + (error && error.message ? String(error.message) : 'errore sconosciuto'), 'warn');
                                                toast('Import non riuscito: ' + (error && error.message ? String(error.message) : 'errore sconosciuto'), 'error');
                                        });
                                };
                                reader.readAsText(file);
                        });
                }

                /* SEZIONE 4: generazione chiave API pubblica → POST /tools/api-key. */
                if (keygenBtn && keyInput) {
                        keygenBtn.addEventListener('click', function () {
                                if (busy) {
                                        return;
                                }
                                if (!window.confirm('Generare una nuova chiave invalida la chiave attuale?')) {
                                        return;
                                }
                                busy = true;
                                keygenBtn.disabled = true;
                                api('/tools/api-key', { method: 'POST' }).then(function (data) {
                                        var key = data && data.api_key ? String(data.api_key) : '';
                                        if (key) {
                                                keyInput.value = key;
                                                toast('Nuova chiave API generata', 'success');
                                        } else {
                                                toast('Risposta senza chiave API', 'error');
                                        }
                                }).catch(function (error) {
                                        toast('Generazione chiave non riuscita: ' + (error && error.message ? String(error.message) : 'errore sconosciuto'), 'error');
                                }).then(function () {
                                        busy = false;
                                        keygenBtn.disabled = false;
                                });
                        });
                }
        })();
</script>
