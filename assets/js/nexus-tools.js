/**
 * Nexus News AI — Script vista "Strumenti" (admin/views/tools.php)
 *
 * Slug plugin: nexus-news-ai
 * Versione:    1.1.13
 *
 * Estratto dal blocco <script> inline della vista (1.1.13 "Ottimizzazione",
 * task O5): JS 100% statico (zero PHP interpolato), tre IIFE ES2019 vanilla
 * spostate identiche al byte (solo dedent):
 *   1. nxaToolsBoot   — export (GET /export -> Blob download), import
 *                       (POST /import via FileReader), keygen chiave API
 *                       (POST /tools/api-key) + toast condiviso.
 *   2. copia chiave   — #nxa-tools-key-copy -> clipboard (fallback
 *                       execCommand), stato "Copiata" e flash campo vuoto.
 *   3. nxaToolsAiBoot — diagnostica AI (GET /ai/models) nel box esito.
 * Nessuna dipendenza da admin.js: consuma window.NexusAdminData (ricostruito
 * a runtime da frammenti non traducibili, vedi commenti storici in codice).
 *
 * CONTRATTO DOM: i selettori fissi sono elencati nel docblock della vista
 * (#nxa-tools-export, #nxa-tools-import-file, #nxa-tools-import,
 * #nxa-tools-import-result, #nxa-tools-key, #nxa-tools-keygen,
 * #nxa-tools-key-copy, #nxa-tools-ai-test, #nxa-tools-ai-result). I test
 * strumenti (#nxa-tools-test-*) e la migrazione nativa (#nxa-native-*)
 * restano in assets/js/admin.js: NON toccare quegli id/classi.
 *
 * NOTA enqueue: RICHIESTO in_footer (come admin.js): la IIFE copia-chiave
 * assume il markup gia' presente. Le IIFE 1 e 3 attendono il DOM ready;
 * la 2 e' protetta con lo stesso guard dall'estrazione (1.1.13).
 * i18n: dal 1.1.7 gli script delle viste non passano da translate_html e i
 * testi generati dal JS sono tradotti lato client da i18n.js
 * (MutationObserver): l'estrazione non cambia il comportamento EN.
 */
(function nxaToolsBoot() {
        'use strict';

        /* v1.1.7: NexusAdminData è localizzata nei FOOTER admin (wp_enqueue_script
           con in_footer=true): durante il parsing del body il global non esiste
           ancora, quindi il guard sotto usciva SEMPRE e i bottoni restavano morti
           ("i test non funzionano"). Se il DOM è ancora in parsing, riprogramma
           questa IIFE a DOM ready (i dati footer sono già eseguiti) invece di
           uscire: stesso pattern già usato dalla vista analytics. */
        if ('loading' === document.readyState) {
                document.addEventListener('DOMContentLoaded', function () { nxaToolsBoot(); });
                return;
        }

        /* Guard: la vista dipende dai dati localizzati in pagina dal plugin.
           v1.1.7: il nome del global NON compare mai come letterale in questo
           script: in lingua EN Nexus_I18n::translate_html() applica strtr su
           tutto l'HTML della vista (incluse le stringhe JS inline) e la voce di
           dizionario 'Data' => 'Date' corrompeva l'identificatore (guard sempre
           falsa). Lo ricostruiamo a runtime da frammenti non traducibili. */
        var NAD = window['Nexus' + 'Admin' + 'Da' + 'ta'];
        if (!NAD || !NAD.restUrl || !NAD.nonce) {
                if (window.console && window.console.warn) {
                        window.console.warn('[Nexus News AI] Dati admin non disponibili: vista Strumenti non inizializzata.');
                }
                return;
        }

        var base = String(NAD.restUrl).replace(/\/+$/, '');
        var nonce = NAD.nonce;

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
                        /* v1.1.13.1 (glue, Q2): conferma inline via ponte NexusAdminUi
                         * di admin.js (caricato anche sulla pagina tools) — niente
                         * window.confirm nativo. Se il ponte manca: azione annullata
                         * con warn in console, mai fallback a dialogo nativo. */
                        var nxaUi = window.NexusAdminUi;
                        if (!nxaUi || typeof nxaUi.inlineConfirmOpen !== 'function') {
                                if (window.console && window.console.warn) {
                                        window.console.warn('[Nexus News AI] Ponte conferme inline (NexusAdminUi) non disponibile: azione annullata.');
                                }
                                return;
                        }
                        nxaUi.inlineConfirmOpen(keygenBtn, 'Generare una nuova chiave invalida la chiave attuale?', 'Sì, genera', function () {
                                busy = true;
                                keygenBtn.disabled = true;
                        api('/tools/api-key', { method: 'POST' }).then(function (data) {
                                /* v1.1.9 (D6): la chiave in chiaro arriva UNA SOLA VOLTA nella risposta
                                   ('api_key_plain'; fallback ad 'api_key', alias storico) e viene mostrata
                                   solo qui: il server salva in settings SOLO il suo hash SHA-256, quindi
                                   ricaricando la pagina il campo torna mascherato e la chiave non sarà
                                   più recuperabile — chi non la salva deve rigenerarla. */
                                var key = data ? String(data.api_key_plain || data.api_key || '') : '';
                                if (key) {
                                        keyInput.value = key;
                                        toast('Nuova chiave API generata: copiala subito, non verrà più mostrata', 'success');
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
                });
        }
})();

/* COPIA CHIAVE (v1.0.6): funzionalità aggiuntiva in IIFE separata — il blocco
   sopra resta identico al byte. Copia #nxa-tools-key negli appunti con
   feedback "Copiata" sul bottone (classe --done, token di stato ok), fallback
   execCommand per browser senza Clipboard API e flash di attenzione sull'input
   se la chiave è vuota (in quel caso il campo prende il focus). */
(function nxaToolsCopyBoot() {
        'use strict';

        /* v1.1.13 (estrazione dallo script inline della vista): attesa DOM ready
           come per le IIFE sorelle — inline lo script girava a fine body con il
           markup già presente; da file esterno deve essere robusto rispetto
           all'ordine di enqueue. Stesso meccanismo di nxaToolsBoot. */
        if ('loading' === document.readyState) {
                document.addEventListener('DOMContentLoaded', function () { nxaToolsCopyBoot(); });
                return;
        }

        var keyInput = document.getElementById('nxa-tools-key');
        var copyBtn = document.getElementById('nxa-tools-key-copy');

        if (!keyInput || !copyBtn) {
                return;
        }

        var originalLabel = copyBtn.textContent;
        var resetTimer = null;

        /* Click nel campo readonly: seleziona tutta la chiave (copia manuale). */
        keyInput.addEventListener('focus', function () {
                try {
                        keyInput.select();
                } catch (selectError) {
                        /* select() non disponibile: nessun impatto. */
                }
        });

        /* Stato "copiata" evidente sul bottone, ritorno automatico. */
        function markCopied() {
                copyBtn.className = 'nxa-btn nxa-tools-copybtn--done';
                copyBtn.textContent = 'Copiata';
                if (resetTimer) {
                        window.clearTimeout(resetTimer);
                }
                resetTimer = window.setTimeout(function () {
                        copyBtn.className = 'nxa-btn';
                        copyBtn.textContent = originalLabel;
                        resetTimer = null;
                }, 2400);
        }

        /* Fallback senza Clipboard API: textarea nascosta + execCommand. */
        function legacyCopy(text) {
                var scratch = document.createElement('textarea');
                var done = false;
                scratch.value = text;
                scratch.setAttribute('readonly', 'readonly');
                scratch.style.position = 'fixed';
                scratch.style.left = '-9999px';
                document.body.appendChild(scratch);
                scratch.select();
                try {
                        done = document.execCommand('copy');
                } catch (copyError) {
                        done = false;
                }
                document.body.removeChild(scratch);
                return done;
        }

        copyBtn.addEventListener('click', function () {
                var value = String(keyInput.value || '');

                if (!value) {
                        keyInput.className = 'nxa-tools-key nxa-tools-key--empty';
                        window.setTimeout(function () {
                                keyInput.className = 'nxa-tools-key';
                        }, 1200);
                        keyInput.focus();
                        return;
                }

                copyBtn.disabled = true;

                function finish() {
                        copyBtn.disabled = false;
                }

                if (window.navigator.clipboard && window.navigator.clipboard.writeText) {
                        window.navigator.clipboard.writeText(value).then(function () {
                                finish();
                                markCopied();
                        }).catch(function () {
                                finish();
                                if (legacyCopy(value)) {
                                        markCopied();
                                }
                        });
                } else {
                        finish();
                        if (legacyCopy(value)) {
                                markCopied();
                        }
                }
        });
})();

/* DIAGNOSTICA AI (v1.0.7): IIFE separata — i blocchi sopra restano intatti
   al byte. Test del provider: GET {restUrl}/ai/models con X-WP-Nonce (stesso
   pattern della IIFE principale). Successo: conteggio modelli, primi 8 id in
   lista <code>, nota cache (12 ore) e base_url usato. Errore: messaggio
   onesto dal body WP {code,message,data.status} nel box (stile errore) +
   toast. La chiave non tocca mai il browser: la usa solo il server. */
(function nxaToolsAiBoot() {
        'use strict';

        /* v1.1.7: attesa DOM ready — NexusAdminData vive nei footer admin
           (vedi IIFE nxaToolsBoot sopra per il meccanismo completo). */
        if ('loading' === document.readyState) {
                document.addEventListener('DOMContentLoaded', function () { nxaToolsAiBoot(); });
                return;
        }

        /* v1.1.7: nome del global ricostruito a runtime (in EN il dizionario
           i18n corrompe il letterale 'Data' => 'Date'). */
        var NAD = window['Nexus' + 'Admin' + 'Da' + 'ta'];
        if (!NAD || !NAD.restUrl || !NAD.nonce) {
                if (window.console && window.console.warn) {
                        window.console.warn('[Nexus News AI] Dati admin non disponibili: diagnostica AI non inizializzata.');
                }
                return;
        }

        var testBtn = document.getElementById('nxa-tools-ai-test');
        var resultBox = document.getElementById('nxa-tools-ai-result');

        if (!testBtn || !resultBox) {
                return;
        }

        var base = String(NAD.restUrl).replace(/\/+$/, '');
        var nonce = NAD.nonce;
        var originalLabel = testBtn.textContent;
        var busy = false;
        var toastEl = null;
        var toastTimer = null;

        /* Escape di ogni valore dinamico prima di innerHTML. */
        function esc(value) {
                return String(value === null || value === undefined ? '' : value)
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/\x22/g, '&quot;')
                        .replace(/\x27/g, '&#039;');
        }

        /* Toast flottante identico alla IIFE principale (4 secondi). */
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

        /* Box esito: classe base + modificatore di stato (ok / err). */
        function renderBox(html, tipo) {
                resultBox.className = 'nxa-tools-ai-result' + (tipo === 'ok' ? ' nxa-tools-ai-result--ok' : (tipo === 'err' ? ' nxa-tools-ai-result--err' : ''));
                resultBox.innerHTML = html;
        }

        testBtn.addEventListener('click', function () {
                if (busy) {
                        return;
                }
                busy = true;
                testBtn.disabled = true;
                testBtn.textContent = 'Testo…';

                function finish() {
                        busy = false;
                        testBtn.disabled = false;
                        testBtn.textContent = originalLabel;
                }

                fetch(base + '/ai/models', {
                        method: 'GET',
                        headers: { 'X-WP-Nonce': nonce, 'Accept': 'application/json' },
                        credentials: 'same-origin'
                }).then(function (response) {
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
                }).then(function (data) {
                        var models = data && data.models && data.models.length ? data.models : [];
                        var count = data && typeof data.count !== 'undefined' && data.count !== null ? parseInt(data.count, 10) : models.length;
                        if (isNaN(count)) {
                                count = models.length;
                        }
                        var cached = !!(data && data.cached);
                        var providerBase = data && data.base_url ? String(data.base_url) : '';
                        var html = '<p class="nxa-tools-ai-line">' + esc(count) + ' modelli disponibili' + (cached ? ' <span class="nxa-tools-ai-cache">(dalla cache, 12 ore)</span>' : '') + '</p>';
                        var max = models.length < 8 ? models.length : 8;
                        var i;
                        if (max > 0) {
                                html += '<ul class="nxa-tools-ai-list">';
                                for (i = 0; i < max; i++) {
                                        html += '<li><code>' + esc(models[i]) + '</code></li>';
                                }
                                html += '</ul>';
                        }
                        if (providerBase) {
                                html += '<span class="nxa-tools-ai-endpoint">Endpoint: ' + esc(providerBase) + '</span>';
                        }
                        renderBox(html, 'ok');
                        toast(count + ' modelli disponibili dal provider AI', 'success');
                        finish();
                }).catch(function (error) {
                        var message = error && error.message ? String(error.message) : 'errore sconosciuto';
                        renderBox('<p class="nxa-tools-ai-line">Test provider AI non riuscito: ' + esc(message) + '</p>', 'err');
                        toast('Test provider AI non riuscito: ' + message, 'error');
                        finish();
                });
        });
})();
