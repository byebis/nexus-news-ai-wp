<?php
/**
 * Vista Admin: "Coda Lavori" (pagina "jobs") — v1.0.6 "UI chiara & curata".
 *
 * Markup + <script> IIFE ES2019 inline (contratto CONTRATTO-v1.0.4.md §2 T-05;
 * restyle token-driven CONTRATTO-v1.0.6.md §2 T-04). v1.1.13 (O4): il blocco
 * <style> inline è stato ESTRATTO in admin/css/nexus-jobs.css e viene
 * enqueuato SOLO su questa pagina da admin/class-nexus-admin.php
 * (enqueue_assets → hook "nexus-jobs", dipendenza nexus-admin-css, ver
 * NEXUS_VERSION; SOLO classi prefisso .nxa-jb-, colori/raggi/ombre al 100%
 * via token del design system §1: tema chiaro default, scuro opzionale su
 * body.nexus-theme-dark). Il <script> resta inline (ha interleaving con il
 * PHP e nessun tocco ad assets/js/admin.js). Tutta la logica vive qui:
 * fetch REST, rendering, azioni di riga, toast, auto-refresh.
 *
 * CONTRATTO REST (rotte definite nel §2 T-06, namespace nexus/v1, perm admin):
 * - GET  {restUrl}/jobs?per_page=50[&status=pending|running|complete|failed|cancelled|dead]
 *        → { items: [ { id, hook, args, status, priority, attempts, max_attempts,
 *                       scheduled_at, started_at, completed_at, last_error } ],
 *            total, pages, stats: { pending, running, complete, failed, cancelled, total } }
 *        v1.1.16 (WE3): jobs.args (già nel payload REST) viene renderizzato in
 *        forma leggibile nella colonna "Lavoro" ("agente #N" / "articolo #N" /
 *        topic), troncato con tooltip title= del JSON completo (stesso pattern
 *        di last_error); lo stato 'dead' (dead letter, Nexus_Queue::monitor)
 *        ha filtro, card statistica e badge dedicati — la card usa stats.dead
 *        SE presente (WB aggiunge la chiave a job_stats), altrimenti riconta
 *        le righe visibili come nel fallback difensivo pre-esistente.
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
 *   #nxa-jb-cleanup (con conferma inline, v1.1.13.1 Q2), #nxa-jb-auto (auto-refresh 15s).
 * - Tabella: tbody#nxa-jb-rows riempito da JS; azioni per riga tramite
 *   [data-nxa-jb-action="retry|cancel"][data-nxa-jb-id] con listener delegato.
 * - Toast: .nxa-jb-toast (--success / --error) su body, rimosso dopo 4 secondi;
 *   colori via token del tema (leggibile su chiaro e su scuro: i token valgono
 *   su body e la variante body.nexus-theme-dark è inclusa in
 *   admin/css/nexus-jobs.css).
 *
 * NOTE: date formattate IT (gg/mm/aa HH:MM) da 'Y-m-d H:i:s'; errore di riga
 * troncato a 60 caratteri con testo completo nell'attributo title; empty state
 * italiano; errori di fetch con toast rosso. L'auto-refresh silenzioso non genera
 * toast ripetuti ogni 15 secondi.
 *
 * v1.1.13 (O4) — polling pausato a tab nascosta: l'auto-refresh 15s gira SOLO
 * con la pagina visibile (document.hidden guard nel tick + visibilitychange
 * che ferma/riprende l'interval, stesso pattern della live-strip dashboard in
 * assets/js/admin.js). Al ritorno sulla pagina: ripresa immediata con una
 * lettura silenziosa. Zero richieste in background quando l'admin è in altro
 * tab; CSS estratto in admin/css/nexus-jobs.css (nessun selettore cambiato).
 *
 * v1.0.7 — fix overflow mobile view-level (CONTRATTO-v1.0.7.md §2 T-07): il
 * wrapper della tabella porta anche la classe condivisa .nxa-table-wrap
 * (definita dal task T-04 in admin/css/nexus-admin.css: overflow-x auto,
 * scroll touch, max-width 100%) accanto alla .nxa-jb-table-wrap locale, che
 * resta il target degli stili di questa vista. Le parole lunghe (hook del
 * lavoro in <code>, testo errore) vanno a capo via overflow-wrap invece di
 * spingere le colonne; su schermi <=600px l'errore troncato passa a wrapping
 * completo. Nessuna width/min-width fissa eccessiva presente nel markup o
 * nello stile locale (verificato). Nessun id, data-attributo o selettore
 * consumato dallo script inline modificato; nessuno style inline nuovo.
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
                <!-- v1.1.16 (WE3): dead letter (status 'dead'). Riusa il modificatore
                     --failed (colore coerente: esauriti i tentativi) perché nexus-jobs.css
                     non porta variante --dead. -->
                <div class="nxa-jb-card nxa-jb-card--failed">
                        <span class="nxa-jb-card-value" id="nxa-jb-stat-dead">&mdash;</span>
                        <span class="nxa-jb-card-label"><?php esc_html_e( 'Dead letter', 'nexus-news-ai' ); ?></span>
                </div>
                <div class="nxa-jb-card nxa-jb-card--total">
                        <span class="nxa-jb-card-value" id="nxa-jb-stat-total">&mdash;</span>
                        <span class="nxa-jb-card-label"><?php esc_html_e( 'Totale', 'nexus-news-ai' ); ?></span>
                </div>
        </div>

        <!-- TOOLBAR: filtro stato, process batch, pulizia, auto-refresh. -->
        <div class="nxa-jb-toolbar nxa-toolbar">
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
                        <!-- v1.1.16 (WE3): dead letter — i job 'running' bloccati oltre i
                             tentativi massimi (Nexus_Queue::monitor); si riaprono SOLO via
                             CLI (wp nexus queue dead --reset=<id>), nessun bottone UI. -->
                        <option value="dead"><?php esc_html_e( 'Dead letter', 'nexus-news-ai' ); ?></option>
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
        <div class="nxa-jb-table-wrap nxa-table-wrap">
                <table class="widefat striped nxa-jb-table">
                        <thead>
                                <tr>
                                        <th scope="col"><?php esc_html_e( 'ID', 'nexus-news-ai' ); ?></th>
                                        <th scope="col"><?php esc_html_e( 'Lavoro', 'nexus-news-ai' ); ?></th>
                                        <th scope="col"><?php esc_html_e( 'Stato', 'nexus-news-ai' ); ?></th>
                                        <th scope="col"><?php esc_html_e( 'Priorità', 'nexus-news-ai' ); ?></th>
                                        <th scope="col"><?php esc_html_e( 'Tentativi', 'nexus-news-ai' ); ?></th>
                                        <th scope="col"><?php esc_html_e( 'Programmatore', 'nexus-news-ai' ); ?></th>
                                        <th scope="col"><?php esc_html_e( 'Iniziato', 'nexus-news-ai' ); ?></th>
                                        <th scope="col"><?php esc_html_e( 'Completato', 'nexus-news-ai' ); ?></th>
                                        <th scope="col"><?php esc_html_e( 'Errore', 'nexus-news-ai' ); ?></th>
                                        <th scope="col"><?php esc_html_e( 'Azioni', 'nexus-news-ai' ); ?></th>
                                </tr>
                        </thead>
                        <tbody id="nxa-jb-rows">
                                <tr class="nxa-jb-message">
                                        <td colspan="10">
                                                <span class="spinner is-active nxa-jb-spinner"></span>
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

<!-- v1.1.13 (O4): il CSS di vista è stato estratto in admin/css/nexus-jobs.css
     e viene enqueuato SOLO su questa pagina da admin/class-nexus-admin.php
     (enqueue_assets, hook "nexus-jobs", dipendenza nexus-admin-css, ver
     NEXUS_VERSION). Il <script> resta inline: contiene interleaving PHP. -->
<script>
        (function () {
                'use strict';

                /* v1.1.7: la guardia NON esce più per sempre. NexusAdminData è
                 * localizzata nei FOOTER admin (wp_enqueue_script in_footer): quando
                 * questo script inline gira durante il parsing la variabile non esiste
                 * ancora e la vecchia guardia chiudeva l'IIFE — pagina Coda Lavori
                 * morta in ogni lingua. Tutto il corpo ora vive in avvia(), invocata
                 * subito o al DOM ready (stesso pattern di tools.php). */
                function avvia() {
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
                        dead: document.getElementById('nxa-jb-stat-dead'),
                        total: document.getElementById('nxa-jb-stat-total')
                };

                /* Stato pagina: true mentre gira una chiamata d'azione (blocca l'auto-refresh). */
                var busy = false;
                var toastEl = null;
                var toastTimer = null;

                /* Etichette italiane degli stati (badge in tabella).
                 * v1.1.16 (WE3): + 'dead' (dead letter — Nexus_Queue::monitor marca
                 * i job 'running' bloccati oltre i tentativi massimi; nessun retry UI,
                 * si riaprono solo con wp nexus queue dead --reset=<id>). */
                var STATUS_LABELS = {
                        pending: 'In attesa',
                        running: 'In esecuzione',
                        complete: 'Completato',
                        failed: 'Fallito',
                        cancelled: 'Annullato',
                        dead: 'Dead letter'
                };
                var STATUS_KEYS = ['pending', 'running', 'complete', 'failed', 'cancelled', 'dead'];

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
                                /* a11y: annuncio live, stesso pattern di redazioni.php e dello stack admin.js. */
                                toastEl.setAttribute('role', 'status');
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

                /* Riga di messaggio dentro tbody (caricamento o errore).
                 * v1.1.16 (WE5): con `retry` a true aggiunge il bottone "Riprova"
                 * (handler delegato .nxa-jb-retry su rowsBody → load()). */
                function setTableMessage(text, isLoading, retry) {
                        if (!rowsBody) {
                                return;
                        }
                        rowsBody.innerHTML = '<tr class="nxa-jb-message"><td colspan="10">' +
                                (isLoading ? '<span class="spinner is-active nxa-jb-spinner"></span> ' : '') +
                                esc(text) +
                                (retry ? ' <button type="button" class="button button-small nxa-jb-retry">Riprova</button>' : '') +
                                '</td></tr>';
                }

                /* Riempie le stat-card: usa stats dalla REST; fallback difensivo sul conteggio righe. */
                function renderStats(payload) {
                        var stats = payload && payload.stats ? payload.stats : null;
                        var items = payload && payload.items ? payload.items : [];
                        var fallbackTotal = payload && payload.total !== undefined ? parseInt(payload.total, 10) || 0 : 0;
                        var counts = { pending: 0, running: 0, complete: 0, failed: 0, cancelled: 0, dead: 0 };
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
                        /* v1.1.16 (WE3): stats.dead arriva solo con la WB (chiave 'dead' in
                         * job_stats). Se manca ma ci sono righe 'dead' visibili, mostro il
                         * conteggio client-side (stesso fallback difensivo qui sopra):
                         * mai la card a zero mentendo sullo stato reale. */
                        if (stats && stats.dead === undefined) {
                                counts.dead = 0;
                                for (i = 0; i < items.length; i++) {
                                        if (items[i] && items[i].status === 'dead') {
                                                counts.dead = counts.dead + 1;
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
                        if (statNodes.dead) {
                                statNodes.dead.textContent = String(counts.dead);
                        }
                        if (statNodes.total) {
                                statNodes.total.textContent = String(stats ? parseInt(stats.total, 10) || 0 : fallbackTotal);
                        }
                }

                /* v1.1.16 (WE3): args leggibili — "agente #N" / "articolo #N" / topic
                 * e le altre chiavi scalari in forma key: value; troncato a 60 caratteri
                 * con il JSON completo nel title (stesso pattern di last_error).
                 * I nomi degli agenti NON sono disponibili in questa vista (la cache
                 * /agents vive in admin.js e qui non viene caricata): solo id, come da
                 * contratto. Restituisce '' quando args è vuoto o non è un oggetto. */
                function argsSummary(args) {
                        if (!args || typeof args !== 'object') {
                                return '';
                        }
                        var parts = [];
                        if (args.agent_id !== undefined && args.agent_id !== null && args.agent_id !== '') {
                                parts.push('agente #' + args.agent_id);
                        }
                        if (args.article_id !== undefined && args.article_id !== null && args.article_id !== '') {
                                parts.push('articolo #' + args.article_id);
                        }
                        if (args.topic !== undefined && args.topic !== null && String(args.topic) !== '') {
                                parts.push('“' + String(args.topic) + '”');
                        }
                        for (var key in args) {
                                if (!Object.prototype.hasOwnProperty.call(args, key) || key === 'agent_id' || key === 'article_id' || key === 'topic') {
                                        continue;
                                }
                                var value = args[key];
                                if (value === null || value === undefined || typeof value === 'object') {
                                        continue;
                                }
                                parts.push(key + ': ' + String(value));
                        }
                        return parts.join(' · ');
                }

                /* Riga tabella: badge con classe presa da lista chiusa (nessuna classe dinamica libera).
                 * v1.1.16 (WE3): 'dead' ri usa la variante --failed (nessun colore dedicato
                 * in nexus-jobs.css, che non è toccabile da questa wave) con etichetta
                 * dedicata "Dead letter"; sotto l'hook arriva argsSummary (se presente). */
                function rowHtml(job) {
                        var status = job && job.status ? String(job.status) : '';
                        var known = STATUS_LABELS.hasOwnProperty(status);
                        var label = known ? STATUS_LABELS[status] : status;
                        var badgeVariant = status === 'dead' ? 'failed' : status;
                        var badgeClass = known ? 'nxa-jb-badge nxa-jb-badge--' + badgeVariant : 'nxa-jb-badge nxa-jb-badge--cancelled';
                        var errorText = job && job.last_error ? String(job.last_error) : '';
                        var argsText = argsSummary(job && job.args);
                        var argsJson = (job && job.args && typeof job.args === 'object') ? JSON.stringify(job.args) : '';
                        var actions = '';

                        if (status === 'complete' || status === 'failed' || status === 'cancelled') {
                                actions = '<button type="button" class="button button-small nxa-jb-act" data-nxa-jb-action="retry" data-nxa-jb-id="' + esc(job.id) + '">Riprova</button>';
                        } else if (status === 'pending') {
                                actions = '<button type="button" class="button button-small nxa-jb-act nxa-jb-act--cancel" data-nxa-jb-action="cancel" data-nxa-jb-id="' + esc(job.id) + '">Annulla</button>';
                        }

                        return '<tr>' +
                                '<td>' + esc(job.id) + '</td>' +
                                '<td><code>' + esc(job.hook) + '</code>' +
                                        (argsText ? '<div class="nxa-jb-args" style="margin-top:3px;font-size:11px;line-height:1.5;color:var(--nxa-muted);overflow-wrap:anywhere" title="' + esc(argsJson) + '">' + esc(clip(argsText, 60)) + '</div>' : '') +
                                '</td>' +
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
                                /* v1.1.16 (WE5): errore onesto con bottone "Riprova" — mai
                                   riga di messaggio senza via d'uscita. */
                                setTableMessage('Impossibile caricare la coda: ' + message, false, true);
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

                /* v1.1.13.1 (Q2): conferma INLINE uniforme — riusa l'helper di
                 * admin.js via il ponte pubblico window.NexusAdminUi (UNA sola
                 * implementazione del pattern in tutta l'admin, una conferma
                 * aperta per pagina). admin.js gira sempre su questa pagina
                 * (stessa guardia NexusAdminData di questa IIFE): se il ponte
                 * manca lo stato è degradato — l'azione NON parte e resta un
                 * warn in console, mai un dialogo nativo fuori pattern.
                 * Messaggi/etichette restano in italiano: vengono tradotti in EN
                 * dall'observer di i18n.js (testi esatti o pattern già nel dict). */
                function confirmInline(btn, message, yesLabel, onYes) {
                        var ui = window.NexusAdminUi;
                        if (ui && typeof ui.inlineConfirmOpen === 'function') {
                                ui.inlineConfirmOpen(btn, message, yesLabel, onYes);
                                return;
                        }
                        if (window.console && window.console.warn) {
                                window.console.warn('[Nexus News AI] Ponte conferme inline (NexusAdminUi) non disponibile: azione annullata.');
                        }
                }

                /* Click delegato sulle azioni di riga (Riprova / Annulla) e, dalla
                 * v1.1.16 (WE5), sul bottone "Riprova" del messaggio d'errore. */
                function onRowsClick(event) {
                        var target = event.target;
                        if (!target || !target.closest) {
                                return;
                        }
                        var retryBtn = target.closest('.nxa-jb-retry');
                        if (retryBtn && rowsBody.contains(retryBtn) && !retryBtn.disabled && !busy) {
                                retryBtn.disabled = true;
                                load();
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
                                /* v1.1.13.1 (Q2): conferma inline (ponte admin.js) al
                                 * posto del window.confirm nativo; stessa richiesta POST. */
                                confirmInline(btn, 'Annullare il lavoro #' + id + '?', 'Sì', function () {
                                        runAction(btn, function () {
                                                return api('/jobs/' + id + '/cancel', { method: 'POST' });
                                        }, function () {
                                                toast('Lavoro #' + id + ' annullato', 'success');
                                        });
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
                                /* v1.1.13.1 (Q2): conferma inline (ponte admin.js) al
                                 * posto del window.confirm nativo; stessa richiesta POST. */
                                confirmInline(cleanupBtn, 'Eliminare i lavori completati, falliti o annullati più vecchi di 7 giorni?', 'Sì, elimina', function () {
                                        runAction(cleanupBtn, function () {
                                                return api('/jobs/cleanup', { method: 'POST', body: JSON.stringify({ days: 7 }) });
                                        }, function (data) {
                                                var deleted = data && data.deleted !== undefined ? parseInt(data.deleted, 10) || 0 : 0;
                                                toast('Eliminati ' + deleted + ' lavori', 'success');
                                        });
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

                /* v1.1.13 (O4): auto-refresh ogni 15 secondi SOLO con flag attivo,
                 * senza azioni in corso e a pagina VISIBILE. Il guard
                 * document.hidden nel tick e visibilitychange che ferma/riprende
                 * l'interval replicano il pattern della live-strip dashboard in
                 * assets/js/admin.js (initLiveRedazionale): a tab nascosta zero
                 * richieste; al ritorno ripresa immediata con lettura silenziosa. */
                var POLL_MS = 15000;
                var pollTimer = null;

                function jbPollTick() {
                        if (document.hidden) {
                                return;
                        }
                        if (autoCheckbox && autoCheckbox.checked && !busy) {
                                load({ silent: true });
                        }
                }

                function startPolling() {
                        if (pollTimer) {
                                return;
                        }
                        pollTimer = window.setInterval(jbPollTick, POLL_MS);
                }

                function stopPolling() {
                        if (pollTimer) {
                                window.clearInterval(pollTimer);
                                pollTimer = null;
                        }
                }

                function onVisibilityChange() {
                        if (document.hidden) {
                                stopPolling(); // pausa: zero richieste a tab nascosta
                        } else {
                                startPolling();
                                jbPollTick(); // aggiornamento immediato al ritorno
                        }
                }

                document.addEventListener('visibilitychange', onVisibilityChange);
                startPolling();
                }

                /* v1.1.7: avvio differito al DOM ready quando lo script inline viene
                 * eseguito durante il parsing (NexusAdminData vive nei footer). */
                if ('loading' === document.readyState) {
                        document.addEventListener('DOMContentLoaded', avvia);
                } else {
                        avvia();
                }
        })();
</script>
