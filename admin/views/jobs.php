<?php
/**
 * Vista Admin: "Coda Lavori" (pagina "jobs") — v1.0.4 "Scala & Pro".
 *
 * SELF-CONTAINED (contratto CONTRATTO-v1.0.4.md §2 T-05): markup + <style> inline
 * (SOLO classi prefisso .nxa-jb-, nessun tocco a admin/css/nexus-admin.css) e
 * <script> IIFE ES2019 inline (nessun tocco ad assets/js/admin.js). Tutta la logica
 * vive qui: fetch REST, rendering, azioni di riga, toast, auto-refresh.
 *
 * CONTRATTO REST (rotte definite nel §2 T-06, namespace nexus/v1, perm admin):
 * - GET  {restUrl}/jobs?per_page=50[&status=pending|running|complete|failed|cancelled]
 *        → { items: [ { id, hook, args, status, priority, attempts, max_attempts,
 *                       scheduled_at, started_at, completed_at, last_error } ],
 *            total, pages, stats: { pending, running, complete, failed, cancelled, total } }
 * - POST {restUrl}/jobs/process      → { processed, failed }       (un batch subito)
 * - POST {restUrl}/jobs/cleanup      body {"days":7} → { deleted }
 * - POST {restUrl}/jobs/{id}/retry   → lavoro complete|failed|cancelled rimesso in pending
 * - POST {restUrl}/jobs/{id}/cancel  → lavoro pending annullato
 * Autenticazione: header X-WP-Nonce = NexusAdminData.nonce (localizzato lato admin).
 *
 * CONTRATTO DOM (selettori fissi consumati dallo script inline in fondo al file):
 * - Contenitore pagina: .wrap.nxa-jb[data-page="jobs"].
 * - Stat-card numeriche: #nxa-jb-stat-pending / #nxa-jb-stat-running /
 *   #nxa-jb-stat-complete / #nxa-jb-stat-failed / #nxa-jb-stat-total.
 * - Toolbar: #nxa-jb-status (filtro stato, valore vuoto = tutti), #nxa-jb-process,
 *   #nxa-jb-cleanup (con window.confirm), #nxa-jb-auto (auto-refresh 15s).
 * - Tabella: tbody#nxa-jb-rows riempito da JS; azioni per riga tramite
 *   [data-nxa-jb-action="retry|cancel"][data-nxa-jb-id] con listener delegato.
 * - Toast: .nxa-jb-toast (--success / --error) su body, rimosso dopo 4 secondi.
 *
 * NOTE: date formattate IT (gg/mm/aa HH:MM) da 'Y-m-d H:i:s'; errore di riga
 * troncato a 60 caratteri con testo completo nell'attributo title; empty state
 * italiano; errori di fetch con toast rosso. L'auto-refresh silenzioso non genera
 * toast ripetuti ogni 15 secondi.
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap nexus-jobs nxa-jb" data-page="jobs">

        <h1><?php esc_html_e( 'Coda Lavori', 'nexus-news-ai' ); ?></h1>

        <p class="nxa-jb-note">
                <?php esc_html_e( "La coda esegue i lavori pesanti in batch ogni 60 secondi tramite WP-Cron quando l'opzione 'Usa la coda' è attiva in Impostazioni → Scala & Pro. Il pulsante 'Processa ora' esegue subito un batch.", 'nexus-news-ai' ); ?>
        </p>

        <!-- STAT-CARD: numeri riempiti dallo script inline via GET /jobs (stats). -->
        <div class="nxa-jb-cards">
                <div class="nxa-jb-card nxa-jb-card--pending">
                        <span class="nxa-jb-card-value" id="nxa-jb-stat-pending">&mdash;</span>
                        <span class="nxa-jb-card-label"><?php esc_html_e( 'In attesa', 'nexus-news-ai' ); ?></span>
                </div>
                <div class="nxa-jb-card nxa-jb-card--running">
                        <span class="nxa-jb-card-value" id="nxa-jb-stat-running">&mdash;</span>
                        <span class="nxa-jb-card-label"><?php esc_html_e( 'In esecuzione', 'nexus-news-ai' ); ?></span>
                </div>
                <div class="nxa-jb-card nxa-jb-card--complete">
                        <span class="nxa-jb-card-value" id="nxa-jb-stat-complete">&mdash;</span>
                        <span class="nxa-jb-card-label"><?php esc_html_e( 'Completati', 'nexus-news-ai' ); ?></span>
                </div>
                <div class="nxa-jb-card nxa-jb-card--failed">
                        <span class="nxa-jb-card-value" id="nxa-jb-stat-failed">&mdash;</span>
                        <span class="nxa-jb-card-label"><?php esc_html_e( 'Falliti', 'nexus-news-ai' ); ?></span>
                </div>
                <div class="nxa-jb-card nxa-jb-card--total">
                        <span class="nxa-jb-card-value" id="nxa-jb-stat-total">&mdash;</span>
                        <span class="nxa-jb-card-label"><?php esc_html_e( 'Totale', 'nexus-news-ai' ); ?></span>
                </div>
        </div>

        <!-- TOOLBAR: filtro stato, process batch, pulizia, auto-refresh. -->
        <div class="nxa-jb-toolbar">
                <label class="screen-reader-text" for="nxa-jb-status">
                        <?php esc_html_e( 'Filtra lavori per stato', 'nexus-news-ai' ); ?>
                </label>
                <select id="nxa-jb-status">
                        <option value="" selected><?php esc_html_e( 'Tutti', 'nexus-news-ai' ); ?></option>
                        <option value="pending"><?php esc_html_e( 'In attesa', 'nexus-news-ai' ); ?></option>
                        <option value="running"><?php esc_html_e( 'In esecuzione', 'nexus-news-ai' ); ?></option>
                        <option value="complete"><?php esc_html_e( 'Completati', 'nexus-news-ai' ); ?></option>
                        <option value="failed"><?php esc_html_e( 'Falliti', 'nexus-news-ai' ); ?></option>
                        <option value="cancelled"><?php esc_html_e( 'Annullati', 'nexus-news-ai' ); ?></option>
                </select>

                <button type="button" id="nxa-jb-process" class="button button-primary">
                        <?php esc_html_e( 'Processa ora', 'nexus-news-ai' ); ?>
                </button>

                <button type="button" id="nxa-jb-cleanup" class="button">
                        <?php esc_html_e( 'Pulizia (>7 giorni)', 'nexus-news-ai' ); ?>
                </button>

                <label class="nxa-jb-auto-label">
                        <input type="checkbox" id="nxa-jb-auto" checked>
                        <?php esc_html_e( 'Auto-aggiornamento (15s)', 'nexus-news-ai' ); ?>
                </label>
        </div>

        <!-- TABELLA CODA: tbody riempito dallo script inline (GET /jobs?per_page=50). -->
        <div class="nxa-jb-table-wrap">
                <table class="widefat striped nxa-jb-table">
                        <thead>
                                <tr>
                                        <th><?php esc_html_e( 'ID', 'nexus-news-ai' ); ?></th>
                                        <th><?php esc_html_e( 'Lavoro', 'nexus-news-ai' ); ?></th>
                                        <th><?php esc_html_e( 'Stato', 'nexus-news-ai' ); ?></th>
                                        <th><?php esc_html_e( 'Priorità', 'nexus-news-ai' ); ?></th>
                                        <th><?php esc_html_e( 'Tentativi', 'nexus-news-ai' ); ?></th>
                                        <th><?php esc_html_e( 'Programmatore', 'nexus-news-ai' ); ?></th>
                                        <th><?php esc_html_e( 'Iniziato', 'nexus-news-ai' ); ?></th>
                                        <th><?php esc_html_e( 'Completato', 'nexus-news-ai' ); ?></th>
                                        <th><?php esc_html_e( 'Errore', 'nexus-news-ai' ); ?></th>
                                        <th><?php esc_html_e( 'Azioni', 'nexus-news-ai' ); ?></th>
                                </tr>
                        </thead>
                        <tbody id="nxa-jb-rows">
                                <tr class="nxa-jb-message">
                                        <td colspan="10">
                                                <span class="spinner is-active" style="float: none;"></span>
                                                <?php esc_html_e( 'Caricamento lavori…', 'nexus-news-ai' ); ?>
                                        </td>
                                </tr>
                        </tbody>
                </table>
        </div>

        <noscript>
                <div class="notice notice-error inline">
                        <p><?php esc_html_e( 'JavaScript è disattivato: la pagina Coda Lavori richiede JavaScript per caricare lo stato della coda.', 'nexus-news-ai' ); ?></p>
                </div>
        </noscript>

</div>

<style>
        /* Stile locale della vista Coda Lavori: SOLO classi prefisso .nxa-jb- (contratto §2 T-05). */
        .nxa-jb-note {
                max-width: 780px;
                margin: 8px 0 16px;
                color: #50575e;
        }
        .nxa-jb-cards {
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
                margin: 0 0 16px;
        }
        .nxa-jb-card {
                flex: 1 1 170px;
                min-width: 160px;
                padding: 12px 14px;
                background: #fff;
                border: 1px solid #dcdcde;
                border-left: 4px solid #646970;
                border-radius: 4px;
                box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
        }
        .nxa-jb-card-value {
                display: block;
                font-size: 26px;
                font-weight: 600;
                line-height: 1.2;
                color: #1d2327;
        }
        .nxa-jb-card-label {
                display: block;
                margin-top: 2px;
                font-size: 12px;
                letter-spacing: 0.03em;
                text-transform: uppercase;
                color: #646970;
        }
        .nxa-jb-card--pending {
                border-left-color: #f59e0b;
        }
        .nxa-jb-card--pending .nxa-jb-card-value {
                color: #f59e0b;
        }
        .nxa-jb-card--running {
                border-left-color: #2271b1;
        }
        .nxa-jb-card--running .nxa-jb-card-value {
                color: #2271b1;
        }
        .nxa-jb-card--complete {
                border-left-color: #22c55e;
        }
        .nxa-jb-card--complete .nxa-jb-card-value {
                color: #22c55e;
        }
        .nxa-jb-card--failed {
                border-left-color: #ef4444;
        }
        .nxa-jb-card--failed .nxa-jb-card-value {
                color: #ef4444;
        }
        .nxa-jb-card--total {
                border-left-color: #646970;
        }
        .nxa-jb-card--total .nxa-jb-card-value {
                color: #646970;
        }

        .nxa-jb-toolbar {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 10px;
                margin: 0 0 12px;
        }
        .nxa-jb-auto-label {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                white-space: nowrap;
        }

        .nxa-jb-table-wrap {
                overflow-x: auto;
                margin: 0 0 16px;
        }
        .nxa-jb-badge {
                display: inline-block;
                padding: 2px 10px;
                border-radius: 999px;
                border: 1px solid transparent;
                font-size: 12px;
                font-weight: 600;
                line-height: 1.6;
                white-space: nowrap;
        }
        .nxa-jb-badge--pending {
                color: #92400e;
                background: #fef3c7;
                border-color: #f59e0b;
        }
        .nxa-jb-badge--running {
                color: #0a4b78;
                background: #e5f0f8;
                border-color: #2271b1;
        }
        .nxa-jb-badge--complete {
                color: #14532d;
                background: #dcfce7;
                border-color: #22c55e;
        }
        .nxa-jb-badge--failed {
                color: #7f1d1d;
                background: #fee2e2;
                border-color: #ef4444;
        }
        .nxa-jb-badge--cancelled {
                color: #3c434a;
                background: #f0f0f1;
                border-color: #949494;
        }
        .nxa-jb-err {
                display: inline-block;
                max-width: 260px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                vertical-align: middle;
                color: #b32d2e;
        }
        .nxa-jb-empty td,
        .nxa-jb-message td {
                padding: 18px 10px;
                text-align: center;
                color: #646970;
        }
        .nxa-jb-empty td {
                font-style: italic;
        }
        .nxa-jb-act {
                margin-right: 4px;
        }
        .nxa-jb-act--cancel {
                color: #b32d2e;
                border-color: #b32d2e;
        }

        .nxa-jb-toast {
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
        .nxa-jb-toast--success {
                background: #22c55e;
        }
        .nxa-jb-toast--error {
                background: #ef4444;
        }
</style>

<script>
        (function () {
                'use strict';

                /* Guard: la vista dipende dai dati localizzati in pagina dal plugin. */
                if (!window.NexusAdminData || !window.NexusAdminData.restUrl || !window.NexusAdminData.nonce) {
                        if (window.console && window.console.warn) {
                                window.console.warn('[Nexus News AI] NexusAdminData non disponibile: vista Coda Lavori non inizializzata.');
                        }
                        return;
                }

                var base = String(window.NexusAdminData.restUrl).replace(/\/+$/, '');
                var nonce = window.NexusAdminData.nonce;

                /* Riferimenti DOM (selettori fissi del markup sopra). */
                var statusSelect = document.getElementById('nxa-jb-status');
                var processBtn = document.getElementById('nxa-jb-process');
                var cleanupBtn = document.getElementById('nxa-jb-cleanup');
                var autoCheckbox = document.getElementById('nxa-jb-auto');
                var rowsBody = document.getElementById('nxa-jb-rows');
                var statNodes = {
                        pending: document.getElementById('nxa-jb-stat-pending'),
                        running: document.getElementById('nxa-jb-stat-running'),
                        complete: document.getElementById('nxa-jb-stat-complete'),
                        failed: document.getElementById('nxa-jb-stat-failed'),
                        total: document.getElementById('nxa-jb-stat-total')
                };

                /* Stato pagina: true mentre gira una chiamata d'azione (blocca l'auto-refresh). */
                var busy = false;
                var toastEl = null;
                var toastTimer = null;

                /* Etichette italiane degli stati (badge in tabella). */
                var STATUS_LABELS = {
                        pending: 'In attesa',
                        running: 'In esecuzione',
                        complete: 'Completato',
                        failed: 'Fallito',
                        cancelled: 'Annullato'
                };
                var STATUS_KEYS = ['pending', 'running', 'complete', 'failed', 'cancelled'];

                /* Escape di ogni valore dinamico prima di innerHTML (contratto §2 T-05). */
                function esc(value) {
                        return String(value === null || value === undefined ? '' : value)
                                .replace(/&/g, '&amp;')
                                .replace(/</g, '&lt;')
                                .replace(/>/g, '&gt;')
                                .replace(/\x22/g, '&quot;')
                                .replace(/\x27/g, '&#039;');
                }

                /* Troncamento difensivo: errore in tabella a 60 caratteri, completo nel title. */
                function clip(value, max) {
                        var text = String(value === null || value === undefined ? '' : value);
                        if (text.length <= max) {
                                return text;
                        }
                        return text.slice(0, max - 1) + '…';
                }

                /* Data IT "gg/mm/aa HH:MM" da 'Y-m-d H:i:s' (o ISO); trattino se vuota o non valida. */
                function fmtDate(value) {
                        if (value === null || value === undefined || value === '') {
                                return '—';
                        }
                        var parts = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/.exec(String(value));
                        if (!parts) {
                                return '—';
                        }
                        return parts[3] + '/' + parts[2] + '/' + parts[1].slice(2) + ' ' + parts[4] + ':' + parts[5];
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

                /* Toast flottante in basso a destra; si rimuove da solo dopo 4 secondi. */
                function toast(message, tipo) {
                        if (!toastEl) {
                                toastEl = document.createElement('div');
                                document.body.appendChild(toastEl);
                        }
                        toastEl.className = 'nxa-jb-toast ' + (tipo === 'error' ? 'nxa-jb-toast--error' : 'nxa-jb-toast--success');
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

                /* Riga di messaggio dentro tbody (caricamento o errore). */
                function setTableMessage(text, isLoading) {
                        if (!rowsBody) {
                                return;
                        }
                        rowsBody.innerHTML = '<tr class="nxa-jb-message"><td colspan="10">' +
                                (isLoading ? '<span class="spinner is-active" style="float: none;"></span> ' : '') +
                                esc(text) + '</td></tr>';
                }

                /* Riempie le 5 stat-card: usa stats dalla REST; fallback difensivo sul conteggio righe. */
                function renderStats(payload) {
                        var stats = payload && payload.stats ? payload.stats : null;
                        var items = payload && payload.items ? payload.items : [];
                        var fallbackTotal = payload && payload.total !== undefined ? parseInt(payload.total, 10) || 0 : 0;
                        var counts = { pending: 0, running: 0, complete: 0, failed: 0, cancelled: 0 };
                        var i;
                        var key;
                        if (stats) {
                                for (i = 0; i < STATUS_KEYS.length; i++) {
                                        key = STATUS_KEYS[i];
                                        counts[key] = parseInt(stats[key], 10) || 0;
                                }
                        } else {
                                /* La REST contrattuale fornisce sempre stats; se manca si contano
                                   le righe ricevute (può risentire del filtro attivo). */
                                for (i = 0; i < items.length; i++) {
                                        if (items[i] && counts[items[i].status] !== undefined) {
                                                counts[items[i].status] = counts[items[i].status] + 1;
                                        }
                                }
                        }
                        if (statNodes.pending) {
                                statNodes.pending.textContent = String(counts.pending);
                        }
                        if (statNodes.running) {
                                statNodes.running.textContent = String(counts.running);
                        }
                        if (statNodes.complete) {
                                statNodes.complete.textContent = String(counts.complete);
                        }
                        if (statNodes.failed) {
                                statNodes.failed.textContent = String(counts.failed);
                        }
                        if (statNodes.total) {
                                statNodes.total.textContent = String(stats ? parseInt(stats.total, 10) || 0 : fallbackTotal);
                        }
                }

                /* Riga tabella: badge con classe presa da lista chiusa (nessuna classe dinamica libera). */
                function rowHtml(job) {
                        var status = job && job.status ? String(job.status) : '';
                        var known = STATUS_LABELS.hasOwnProperty(status);
                        var label = known ? STATUS_LABELS[status] : status;
                        var badgeClass = known ? 'nxa-jb-badge nxa-jb-badge--' + status : 'nxa-jb-badge nxa-jb-badge--cancelled';
                        var errorText = job && job.last_error ? String(job.last_error) : '';
                        var actions = '';

                        if (status === 'complete' || status === 'failed' || status === 'cancelled') {
                                actions = '<button type="button" class="button button-small nxa-jb-act" data-nxa-jb-action="retry" data-nxa-jb-id="' + esc(job.id) + '">Riprova</button>';
                        } else if (status === 'pending') {
                                actions = '<button type="button" class="button button-small nxa-jb-act nxa-jb-act--cancel" data-nxa-jb-action="cancel" data-nxa-jb-id="' + esc(job.id) + '">Annulla</button>';
                        }

                        return '<tr>' +
                                '<td>' + esc(job.id) + '</td>' +
                                '<td><code>' + esc(job.hook) + '</code></td>' +
                                '<td><span class="' + badgeClass + '">' + esc(label) + '</span></td>' +
                                '<td>' + esc(job.priority) + '</td>' +
                                '<td>' + esc(job.attempts) + ' / ' + esc(job.max_attempts) + '</td>' +
                                '<td>' + esc(fmtDate(job.scheduled_at)) + '</td>' +
                                '<td>' + esc(fmtDate(job.started_at)) + '</td>' +
                                '<td>' + esc(fmtDate(job.completed_at)) + '</td>' +
                                '<td>' + (errorText ? '<span class="nxa-jb-err" title="' + esc(errorText) + '">' + esc(clip(errorText, 60)) + '</span>' : '—') + '</td>' +
                                '<td>' + actions + '</td>' +
                                '</tr>';
                }

                /* Rendering righe: lista vuota con messaggio italiano. */
                function renderTable(payload) {
                        if (!rowsBody) {
                                return;
                        }
                        var items = payload && payload.items ? payload.items : [];
                        if (!items.length) {
                                rowsBody.innerHTML = '<tr class="nxa-jb-empty"><td colspan="10">Nessun lavoro in coda</td></tr>';
                                return;
                        }
                        var html = '';
                        var i;
                        for (i = 0; i < items.length; i++) {
                                html += rowHtml(items[i]);
                        }
                        rowsBody.innerHTML = html;
                }

                /* Caricamento coda: GET /jobs?per_page=50 (più "status=" se filtrato). */
                function load(opts) {
                        opts = opts || {};
                        var status = statusSelect ? String(statusSelect.value) : '';
                        var path = '/jobs?per_page=50';
                        if (status !== '') {
                                path += '&status=' + encodeURIComponent(status);
                        }
                        if (!opts.silent) {
                                setTableMessage('Caricamento lavori…', true);
                        }
                        return api(path).then(function (payload) {
                                renderStats(payload);
                                renderTable(payload);
                        }).catch(function (error) {
                                var message = error && error.message ? String(error.message) : 'errore sconosciuto';
                                if (opts.silent) {
                                        /* Auto-refresh: nessun toast ripetuto ogni 15 secondi. */
                                        return;
                                }
                                setTableMessage('Impossibile caricare la coda: ' + message, false);
                                toast('Impossibile caricare la coda: ' + message, 'error');
                        });
                }

                /* Azione POST con bottone disabilitato e refresh finale della lista. */
                function runAction(btn, makeCall, onDone) {
                        if (busy) {
                                return;
                        }
                        busy = true;
                        if (btn) {
                                btn.disabled = true;
                        }
                        makeCall().then(function (data) {
                                if (onDone) {
                                        onDone(data);
                                }
                                return load();
                        }).catch(function (error) {
                                toast(error && error.message ? String(error.message) : 'Errore sconosciuto', 'error');
                        }).then(function () {
                                busy = false;
                                if (btn) {
                                        btn.disabled = false;
                                }
                        });
                }

                /* Click delegato sulle azioni di riga (Riprova / Annulla). */
                function onRowsClick(event) {
                        var target = event.target;
                        if (!target || !target.closest) {
                                return;
                        }
                        var btn = target.closest('[data-nxa-jb-action]');
                        if (!btn || !rowsBody.contains(btn) || btn.disabled) {
                                return;
                        }
                        var id = parseInt(btn.getAttribute('data-nxa-jb-id'), 10);
                        var action = btn.getAttribute('data-nxa-jb-action');
                        if (!id) {
                                return;
                        }
                        if (action === 'retry') {
                                runAction(btn, function () {
                                        return api('/jobs/' + id + '/retry', { method: 'POST' });
                                }, function () {
                                        toast('Lavoro #' + id + ' rimesso in coda', 'success');
                                });
                        } else if (action === 'cancel') {
                                if (!window.confirm('Annullare il lavoro #' + id + '?')) {
                                        return;
                                }
                                runAction(btn, function () {
                                        return api('/jobs/' + id + '/cancel', { method: 'POST' });
                                }, function () {
                                        toast('Lavoro #' + id + ' annullato', 'success');
                                });
                        }
                }

                /* Processa ora: un batch immediato via POST /jobs/process. */
                if (processBtn) {
                        processBtn.addEventListener('click', function () {
                                runAction(processBtn, function () {
                                        return api('/jobs/process', { method: 'POST' });
                                }, function (data) {
                                        var processed = data && data.processed !== undefined ? parseInt(data.processed, 10) || 0 : 0;
                                        var failed = data && data.failed !== undefined ? parseInt(data.failed, 10) || 0 : 0;
                                        toast('Batch eseguito: ' + processed + ' processati, ' + failed + ' falliti', 'success');
                                });
                        });
                }

                /* Pulizia: elimina complete/failed/cancelled più vecchi di 7 giorni. */
                if (cleanupBtn) {
                        cleanupBtn.addEventListener('click', function () {
                                if (!window.confirm('Eliminare i lavori completati, falliti o annullati più vecchi di 7 giorni?')) {
                                        return;
                                }
                                runAction(cleanupBtn, function () {
                                        return api('/jobs/cleanup', { method: 'POST', body: JSON.stringify({ days: 7 }) });
                                }, function (data) {
                                        var deleted = data && data.deleted !== undefined ? parseInt(data.deleted, 10) || 0 : 0;
                                        toast('Eliminati ' + deleted + ' lavori', 'success');
                                });
                        });
                }

                /* Filtro stato: ricarica la lista col nuovo valore. */
                if (statusSelect) {
                        statusSelect.addEventListener('change', function () {
                                if (!busy) {
                                        load();
                                }
                        });
                }

                /* Toggle auto-refresh: se riattivato aggiorna subito la vista. */
                if (autoCheckbox) {
                        autoCheckbox.addEventListener('change', function () {
                                if (autoCheckbox.checked && !busy) {
                                        load({ silent: true });
                                }
                        });
                }

                if (rowsBody) {
                        rowsBody.addEventListener('click', onRowsClick);
                        load();
                }

                /* Auto-refresh ogni 15 secondi: solo con flag attivo e senza azioni in corso. */
                window.setInterval(function () {
                        if (autoCheckbox && autoCheckbox.checked && !busy) {
                                load({ silent: true });
                        }
                }, 15000);
        })();
</script>
