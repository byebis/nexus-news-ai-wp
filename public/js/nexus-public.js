/*!
 * Nexus News AI — frontend pubblico (vanilla JS, ES2019, IIFE, no jQuery)
 *
 * Si aggancia al markup dello shortcode [nexus_magazine]:
 *   .nxa-magazine
 *     .nxa-cat-btn[data-category]        filtri categoria (uno con data-category="" = "Tutte")
 *     .nxa-card[data-article-id]         card articolo (server-rendered)
 *     .nxa-load-more[data-nxa="load-more"][data-page]
 *     .nxa-wire-run a[data-article-id]   sezioni Wire (stesso comportamento delle card)
 *   #nxa-modal-root                      contenitore vuoto per il modal
 *
 * Pagina single (v1.0.1 — templates/single-nexus_article.php):
 *   .nexus-single-reactions[data-article-id]  barra reazioni (data-article-id = ID
 *                                             Nexus, stesso markup dei bottoni modal)
 *   .nexus-single-reaction-hint               messaggi di stato delle reazioni
 *   .nexus-single-views                       contatore letture (aggiornato dal POST view)
 *
 * Config iniettata da PHP: window.NexusPublicData = { restUrl, nonce, emojis: [6 emoji] }
 *
 * Espone una sola variabile globale: window.NexusPublic = { open, close } (debug).
 */
(function () {
        'use strict';

        /* ================================================================
         * Configurazione
         * ================================================================ */
        var CFG = window.NexusPublicData;

        if (!CFG || !CFG.restUrl) {
                console.warn('[Nexus News AI] window.NexusPublicData non disponibile: frontend magazine disattivato.');
                return;
        }
        if (typeof window.fetch !== 'function') {
                console.warn('[Nexus News AI] fetch() non supportato da questo browser: frontend magazine disattivato.');
                return;
        }

        var REST = String(CFG.restUrl).replace(/\/+$/, '');
        var EMOJIS = (Array.isArray(CFG.emojis) && CFG.emojis.length)
                ? CFG.emojis
                : ['🔥', '👏', '🤯', '😢', '🤖', '❤️'];

        var LS_REACTIONS = 'nexus_wp_reactions_v1'; // { articleId: [emoji, …] }
        var SS_VIEWED = 'nexus_wp_viewed_v1';       // [articleId, …] (sessione corrente)
        var REACTION_THROTTLE_MS = 800;
        var HTTP_TIMEOUT_HINT = 'Impossibile caricare gli articoli, riprova.';

        /* Emoji di copertina per articolo senza immagine (mappa categoria → emoji). */
        var CAT_EMOJI = {
                tecnologia: '🤖', ai: '🤖', politica: '🏛️', economia: '📈', finanza: '📈',
                scienza: '🔬', sport: '⚽', cultura: '🎭', salute: '🩺', ambiente: '🌱',
                mondo: '🌍', intrattenimento: '🎬', lifestyle: '✨', viaggi: '✈️',
                'default': '📰'
        };

        /* Colori categoria: stessa mappa di Nexus_Utils::category_color(). */
        var CAT_COLORS = {
                'tecnologia': '#38bdf8',
                'politica': '#f59e0b',
                'economia': '#34d399',
                'scienza': '#a78bfa',
                'sport': '#f87171',
                'cultura': '#f472b6',
                'salute': '#4ade80'
        };

        function catColor(category) {
                var key = String(category || '').toLowerCase().trim();
                return CAT_COLORS[key] || '#94a3b8';
        }

        /** Tempo trascorso in italiano (stesso formato di Nexus_Utils::time_ago_it). */
        function timeAgoIt(value) {
                if (value === null || value === undefined || value === '') return '';
                var raw = String(value).trim();
                var d = new Date(raw);
                if (isNaN(d.getTime())) {
                        var m = raw.match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2}):(\d{2})/);
                        if (m) d = new Date(m[1] + '-' + m[2] + '-' + m[3] + 'T' + m[4] + ':' + m[5] + ':' + m[6]);
                }
                if (isNaN(d.getTime())) return '';
                var diff = Math.floor((Date.now() - d.getTime()) / 1000);
                if (diff < 0) diff = 0;
                if (diff < 60) return 'adesso';
                if (diff < 3600) {
                        var mi = Math.floor(diff / 60);
                        return mi === 1 ? '1 minuto fa' : mi + ' minuti fa';
                }
                if (diff < 86400) {
                        var h = Math.floor(diff / 3600);
                        return h === 1 ? '1 ora fa' : h + ' ore fa';
                }
                if (diff < 604800) {
                        var g = Math.floor(diff / 86400);
                        return g === 1 ? '1 giorno fa' : g + ' giorni fa';
                }
                if (diff < 2592000) {
                        var w = Math.floor(diff / 604800);
                        return w === 1 ? '1 settimana fa' : w + ' settimane fa';
                }
                if (diff < 31536000) {
                        var mo = Math.floor(diff / 2592000);
                        return mo === 1 ? '1 mese fa' : mo + ' mesi fa';
                }
                var y = Math.floor(diff / 31536000);
                return y === 1 ? '1 anno fa' : y + ' anni fa';
        }

        /** Excerpt a N parole (fallback client-side se il REST non lo fornisce). */
        function excerptN(text, n) {
                var words = String(text || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().split(' ');
                if (words.length <= n) return words.join(' ');
                return words.slice(0, n).join(' ') + ' …';
        }

        /* Etichette accessibili per i bottoni reazione. */
        var EMOJI_LABELS = {
                '🔥': 'Fuoco', '👏': 'Applauso', '🤯': 'Wow', '😢': 'Triste',
                '🤖': 'Robot', '❤️': 'Cuore', '❤': 'Cuore', '😍': 'Adoro',
                '😂': 'Divertente', '👍': 'D\'accordo', '🙌': 'Evviva', '🚀': 'Razzo',
                '😮': 'Stupito', '💔': 'Deluso', '🌍': 'Mondo', '🧠': 'Mente'
        };

        var MONTHS_IT = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno',
                'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

        /* ================================================================
         * Utility base
         * ================================================================ */

        /** Escape HTML: usare per OGNI valore dinamico inserito via innerHTML. */
        function esc(value) {
                if (value === null || value === undefined) return '';
                return String(value)
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;')
                        .replace(/'/g, '&#39;');
        }

        /** Data in italiano leggibile: accetta ISO o MySQL DATETIME. */
        function dateIt(value) {
                if (value === null || value === undefined || value === '') return '';
                var raw = String(value).trim();
                var d = new Date(raw);
                if (isNaN(d.getTime())) {
                        var m = raw.match(/^(\d{4})-(\d{2})-(\d{2})/); // MySQL "YYYY-MM-DD HH:MM:SS"
                        if (m) d = new Date(m[1] + '-' + m[2] + '-' + m[3] + 'T00:00:00');
                }
                if (isNaN(d.getTime())) return raw; // fallback: stringa originale
                try {
                        return new Intl.DateTimeFormat('it-IT', { day: 'numeric', month: 'long', year: 'numeric' }).format(d);
                } catch (e) {
                        return d.getDate() + ' ' + MONTHS_IT[d.getMonth()] + ' ' + d.getFullYear();
                }
        }

        /** Solo URL immagine sicuri (http(s), //, /, data:image). */
        function safeSrc(url) {
                var s = String(url || '').trim();
                if (!s) return '';
                if (/^(https?:)?\/\//i.test(s) || s.charAt(0) === '/' || /^data:image\//i.test(s)) return s;
                return '';
        }

        /** Solo link http(s) — blocca javascript: e simili. */
        function safeHref(url) {
                var s = String(url || '').trim();
                return /^https?:\/\//i.test(s) ? s : '';
        }

        function catEmoji(category) {
                var key = String(category || '').toLowerCase().trim();
                return CAT_EMOJI[key] || CAT_EMOJI['default'];
        }

        function emojiLabel(emoji) {
                return EMOJI_LABELS[emoji] || 'Reazione';
        }

        function readTimeLabel(readTime) {
                if (readTime === null || readTime === undefined || readTime === '') return '';
                var raw = String(readTime).trim();
                if (/^\d+$/.test(raw)) {
                        var n = parseInt(raw, 10);
                        return n === 1 ? '1 min di lettura' : n + ' min di lettura';
                }
                return raw; // già formattato lato server
        }

        function viewsLabel(views) {
                var n = parseInt(views, 10);
                if (isNaN(n) || n < 0) n = 0;
                if (n === 1) return '1 lettura';
                try { return n.toLocaleString('it-IT') + ' letture'; } catch (e) { return n + ' letture'; }
        }

        /** Avatar: emoji testuale, immagine se URL, iniziale come fallback. */
        function avatarHTML(avatar, name) {
                var a = String(avatar || '').trim();
                if (/^(https?:)?\/\//i.test(a) || a.charAt(0) === '/') {
                        return '<img class="nxa-avatar" src="' + esc(a) + '" alt="" loading="lazy" decoding="async">';
                }
                if (a) return '<span class="nxa-avatar" aria-hidden="true">' + esc(a) + '</span>';
                var initial = String(name || 'N').trim().charAt(0).toUpperCase() || 'N';
                return '<span class="nxa-avatar" aria-hidden="true">' + esc(initial) + '</span>';
        }

        /* ================================================================
         * Storage (reazioni "soft" + view una tantum)
         * ================================================================ */

        function readReactionsMap() {
                try {
                        var obj = JSON.parse(window.localStorage.getItem(LS_REACTIONS) || '{}');
                        return (obj && typeof obj === 'object' && !Array.isArray(obj)) ? obj : {};
                } catch (e) { return {}; }
        }

        function writeReactionsMap(map) {
                try { window.localStorage.setItem(LS_REACTIONS, JSON.stringify(map)); } catch (e) { /* storage pieno/bloccato: resta morbido */ }
        }

        function getMine(articleId) {
                var map = readReactionsMap();
                return Array.isArray(map[articleId]) ? map[articleId] : [];
        }

        function addMine(articleId, emoji) {
                var map = readReactionsMap();
                var arr = Array.isArray(map[articleId]) ? map[articleId] : [];
                if (arr.indexOf(emoji) === -1) arr.push(emoji);
                map[articleId] = arr;
                writeReactionsMap(map);
        }

        var viewed = (function () {
                try {
                        var arr = JSON.parse(window.sessionStorage.getItem(SS_VIEWED) || '[]');
                        return Array.isArray(arr) ? arr.map(String) : [];
                } catch (e) { return []; }
        })();

        function hasViewed(articleId) {
                return viewed.indexOf(String(articleId)) !== -1;
        }

        function markViewed(articleId) {
                if (viewed.length > 200) viewed.shift();
                viewed.push(String(articleId));
                try { window.sessionStorage.setItem(SS_VIEWED, JSON.stringify(viewed.slice(-100))); } catch (e) { /* ignora */ }
        }

        /* ================================================================
         * REST client (nexus/v1, pubblico; nonce solo sui POST)
         * ================================================================ */

        function handleResponse(res) {
                if (!res.ok) {
                        var err = new Error('HTTP ' + res.status);
                        err.status = res.status;
                        throw err;
                }
                return res.json();
        }

        function apiGet(path) {
                return fetch(REST + path, {
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json' }
                }).then(handleResponse);
        }

        function apiPost(path, body) {
                return fetch(REST + path, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                                'Accept': 'application/json',
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': CFG.nonce || ''
                        },
                        body: JSON.stringify(body || {})
                }).then(handleResponse);
        }

        function fetchArticles(params) {
                var q = ['page=' + (parseInt(params.page, 10) || 1), 'per_page=' + (parseInt(params.per_page, 10) || 9)];
                if (params.category) q.push('category=' + encodeURIComponent(params.category));
                if (params.search) q.push('search=' + encodeURIComponent(params.search));
                return apiGet('/public/articles?' + q.join('&'));
        }

        /* ================================================================
         * Modal articolo
         * ================================================================ */

        var modal = {
                built: false,
                overlay: null,
                box: null,
                scroll: null,
                closeBtn: null,
                isOpen: false,
                currentId: null,
                reqId: 0,
                lastFocus: null,
                busyReaction: false,
                lastReactionAt: 0,
                hintTimer: null
        };

        var MODAL_HTML =
                '<div class="nxa-modal-overlay" hidden>' +
                        '<div class="nxa-modal" role="dialog" aria-modal="true" aria-labelledby="nxa-modal-title">' +
                                '<button type="button" class="nxa-modal-close" aria-label="Chiudi la finestra dell\'articolo">×</button>' +
                                '<div class="nxa-modal-scroll"></div>' +
                        '</div>' +
                '</div>';

        function ensureModal() {
                if (modal.built) return;

                var root = document.getElementById('nxa-modal-root');
                if (!root) {
                        root = document.createElement('div');
                        root.id = 'nxa-modal-root';
                        document.body.appendChild(root);
                }
                root.innerHTML = MODAL_HTML;

                modal.overlay = root.querySelector('.nxa-modal-overlay');
                modal.box = root.querySelector('.nxa-modal');
                modal.scroll = root.querySelector('.nxa-modal-scroll');
                modal.closeBtn = root.querySelector('.nxa-modal-close');
                modal.built = true;

                modal.closeBtn.addEventListener('click', function () { closeModal(); });
                modal.overlay.addEventListener('click', function (e) {
                        if (e.target === modal.overlay) closeModal(); // click sull'overlay, non sul contenuto
                });
                modal.scroll.addEventListener('click', function (e) {
                        if (!e.target || typeof e.target.closest !== 'function') return;
                        var rbtn = e.target.closest('.nxa-reaction-btn');
                        if (rbtn) { onReactionClick(rbtn); return; }
                        var retry = e.target.closest('.nxa-modal-retry');
                        if (retry && modal.currentId) openArticle(modal.currentId, null);
                });
        }

        function renderSkeleton() {
                var lines = '';
                for (var i = 0; i < 5; i++) lines += '<div class="nxa-skel nxa-skel-line"></div>';
                modal.scroll.innerHTML =
                        '<div class="nxa-modal-skel" aria-hidden="true">' +
                                '<div class="nxa-skel nxa-skel-media"></div>' +
                                '<div class="nxa-modal-content">' +
                                        '<div class="nxa-skel nxa-skel-badge"></div>' +
                                        '<div class="nxa-skel nxa-skel-line nxa-skel-lg"></div>' +
                                        '<div class="nxa-skel nxa-skel-line nxa-skel-lg nxa-skel-short"></div>' +
                                        lines +
                                '</div>' +
                        '</div>' +
                        '<p class="nxa-sr-only">Caricamento articolo in corso…</p>';
        }

        function renderError(message) {
                modal.scroll.innerHTML =
                        '<div class="nxa-modal-error" role="alert">' +
                                '<p>' + esc(message) + '</p>' +
                                '<button type="button" class="nxa-modal-retry">Riprova</button>' +
                        '</div>';
        }

        /** Barra reazioni: bottoni per ogni emoji configurata, conteggi dal server. */
        function reactionsBarHTML(article, articleId) {
                var counts = (article.reactions && typeof article.reactions === 'object') ? article.reactions : {};
                var mine = getMine(articleId);
                var btns = EMOJIS.map(function (emoji) {
                        var count = parseInt(counts[emoji], 10) || 0;
                        var pressed = mine.indexOf(emoji) !== -1;
                        return '<button type="button" class="nxa-reaction-btn' + (pressed ? ' nxa-pressed' : '') + '"' +
                                ' data-emoji="' + esc(emoji) + '"' +
                                ' aria-pressed="' + (pressed ? 'true' : 'false') + '"' +
                                ' aria-label="Reagisci: ' + esc(emojiLabel(emoji)) + '">' +
                                '<span class="nxa-reaction-emoji" aria-hidden="true">' + esc(emoji) + '</span>' +
                                '<span class="nxa-reaction-count">' + count + '</span>' +
                        '</button>';
                }).join('');
                return '<div class="nxa-modal-reactions" role="group" aria-label="Reazioni all\'articolo">' + btns + '</div>' +
                        '<p class="nxa-reaction-hint" role="status" hidden></p>';
        }

        function metaHTML(article) {
                var bits = [];
                if (article.agent_name) {
                        bits.push('<span class="nxa-modal-agent">' + avatarHTML(article.agent_avatar, article.agent_name) +
                                '<span>' + esc(article.agent_name) + '</span></span>');
                }
                var d = dateIt(article.published_at);
                if (d) bits.push('<span class="nxa-modal-date">' + esc(d) + '</span>');
                var rt = readTimeLabel(article.read_time);
                if (rt) bits.push('<span class="nxa-modal-readtime">' + esc(rt) + '</span>');
                bits.push('<span class="nxa-modal-views">' + esc(viewsLabel(article.views)) + '</span>');
                return bits.join('<span class="nxa-modal-sep" aria-hidden="true">·</span>');
        }

        function sourceHTML(article) {
                var name = String(article.source_name || '').trim();
                var href = safeHref(article.source_url);
                if (!name && !href) return '';
                var label = name || href;
                var link = href
                        ? '<a href="' + esc(href) + '" target="_blank" rel="noopener noreferrer">' + esc(label) + '</a>'
                        : '<strong>' + esc(label) + '</strong>';
                return '<div class="nxa-modal-source">Fonte originale: ' + link + '</div>';
        }

        function renderArticle(article, articleId) {
                var a = article || {};
                var title = String(a.title || 'Senza titolo');
                var subtitle = String(a.subtitle || a.excerpt || '');
                var src = safeSrc(a.image_url);

                var media = src
                        ? '<img class="nxa-modal-img" src="' + esc(src) + '" alt="" data-cat="' + esc(a.category || '') + '" decoding="async">'
                        : '<div class="nxa-modal-cover" aria-hidden="true">' + esc(catEmoji(a.category)) + '</div>';

                // contenuto splittato in paragrafi su doppio newline
                var paras = String(a.content || '').replace(/\r\n/g, '\n').split('\n\n')
                        .map(function (p) { return p.trim(); })
                        .filter(function (p) { return p.length > 0; })
                        .map(function (p) { return '<p class="nxa-modal-p">' + esc(p).replace(/\n/g, '<br>') + '</p>'; })
                        .join('');
                if (!paras) paras = subtitle ? '<p class="nxa-modal-p">' + esc(subtitle) + '</p>' : '<p class="nxa-modal-p">Contenuto non disponibile.</p>';

                modal.scroll.innerHTML =
                        '<article class="nxa-modal-article">' +
                                '<div class="nxa-modal-media">' + media + '</div>' +
                                '<div class="nxa-modal-content">' +
                                        (a.category ? '<span class="nxa-modal-badge">' + esc(a.category) + '</span>' : '') +
                                        '<h2 class="nxa-modal-title" id="nxa-modal-title">' + esc(title) + '</h2>' +
                                        (subtitle ? '<p class="nxa-modal-subtitle">' + esc(subtitle) + '</p>' : '') +
                                        '<div class="nxa-modal-meta">' + metaHTML(a) + '</div>' +
                                        '<div class="nxa-modal-body">' + paras + '</div>' +
                                        sourceHTML(a) +
                                        reactionsBarHTML(a, articleId) +
                                '</div>' +
                        '</article>';
        }

        /** scope: radice della barra reazioni (modal di default; barra single in pagina). */
        function applyReactions(reactions, articleId, scope) {
                var counts = (reactions && typeof reactions === 'object') ? reactions : {};
                var mine = getMine(articleId);
                var root = scope || modal.scroll;
                var btns = root.querySelectorAll('.nxa-reaction-btn');
                for (var i = 0; i < btns.length; i++) {
                        var btn = btns[i];
                        var emoji = btn.getAttribute('data-emoji') || '';
                        var countEl = btn.querySelector('.nxa-reaction-count');
                        if (countEl) countEl.textContent = String(parseInt(counts[emoji], 10) || 0);
                        var pressed = mine.indexOf(emoji) !== -1;
                        btn.classList.toggle('nxa-pressed', pressed);
                        btn.setAttribute('aria-pressed', pressed ? 'true' : 'false');
                }
        }

        function showHint(message) {
                var hint = modal.scroll.querySelector('.nxa-reaction-hint');
                if (!hint) return;
                if (modal.hintTimer) { clearTimeout(modal.hintTimer); modal.hintTimer = null; }
                hint.textContent = message;
                hint.hidden = false;
                modal.hintTimer = setTimeout(function () { hint.hidden = true; }, 2600);
        }

        /** Click su una reazione: throttle 800ms + dedup via localStorage + rollback. */
        function onReactionClick(btn) {
                var emoji = btn.getAttribute('data-emoji') || '';
                var articleId = modal.currentId;
                if (!emoji || !articleId || modal.busyReaction) return;
                if (Date.now() - modal.lastReactionAt < REACTION_THROTTLE_MS) return; // doppio click rapido
                modal.lastReactionAt = Date.now();

                if (getMine(articleId).indexOf(emoji) !== -1) {
                        showHint('Hai già reagito con questa emoji a questo articolo.');
                        return;
                }

                var countEl = btn.querySelector('.nxa-reaction-count');
                var before = countEl ? (parseInt(countEl.textContent, 10) || 0) : 0;

                // aggiornamento ottimistico
                modal.busyReaction = true;
                btn.classList.add('nxa-pressed');
                btn.setAttribute('aria-pressed', 'true');
                if (countEl) countEl.textContent = String(before + 1);

                apiPost('/public/articles/' + encodeURIComponent(articleId) + '/reaction', { emoji: emoji })
                        .then(function (data) {
                                addMine(articleId, emoji);
                                if (data && data.reactions) applyReactions(data.reactions, articleId);
                                showHint('Grazie per la tua reazione!');
                        })
                        .catch(function () {
                                // rollback
                                if (countEl) countEl.textContent = String(before);
                                var stillMine = getMine(articleId).indexOf(emoji) !== -1;
                                btn.classList.toggle('nxa-pressed', stillMine);
                                btn.setAttribute('aria-pressed', stillMine ? 'true' : 'false');
                                showHint('Reazione non registrata, riprova.');
                        })
                        .then(function () { modal.busyReaction = false; });
        }

        /** Al primo open di sessione: POST view (best effort). */
        function trackView(articleId) {
                if (hasViewed(articleId)) return;
                markViewed(articleId);
                apiPost('/public/articles/' + encodeURIComponent(articleId) + '/view', {})
                        .then(function (data) {
                                if (data && typeof data.views !== 'undefined') {
                                        var el = modal.scroll.querySelector('.nxa-modal-views');
                                        if (el) el.textContent = viewsLabel(data.views);
                                }
                        })
                        .catch(function () { /* silenzioso: il conteggio viste non è critico */ });
        }

        function openArticle(id, trigger) {
                id = String(id || '').trim();
                if (!id) return;
                ensureModal();

                if (!modal.isOpen) {
                        modal.lastFocus = trigger || document.activeElement;
                        modal.isOpen = true;
                        modal.overlay.hidden = false;
                        document.body.classList.add('nxa-noscroll');
                }

                modal.currentId = id;
                modal.box.setAttribute('aria-busy', 'true');
                modal.box.setAttribute('aria-label', 'Caricamento articolo in corso');
                modal.box.removeAttribute('aria-labelledby');
                try { modal.closeBtn.focus(); } catch (e) { /* noop */ }
                renderSkeleton();

                var req = ++modal.reqId;
                apiGet('/public/articles/' + encodeURIComponent(id))
                        .then(function (article) {
                                if (!modal.isOpen || req !== modal.reqId) return; // chiuso o superato da un'altra apertura
                                var artId = String((article && article.id) || id);
                                modal.box.removeAttribute('aria-busy');
                                modal.box.removeAttribute('aria-label');
                                modal.box.setAttribute('aria-labelledby', 'nxa-modal-title');
                                renderArticle(article || {}, artId);
                                trackView(artId);
                        })
                        .catch(function (err) {
                                if (!modal.isOpen || req !== modal.reqId) return;
                                modal.box.removeAttribute('aria-busy');
                                var message = (err && err.status === 404)
                                        ? 'Articolo non trovato. Potrebbe essere stato rimosso.'
                                        : 'Impossibile caricare l\'articolo, riprova.';
                                renderError(message);
                        });
        }

        function closeModal() {
                if (!modal.isOpen) return;
                modal.isOpen = false;
                modal.reqId++;               // invalida risposte in volo
                modal.overlay.hidden = true;
                document.body.classList.remove('nxa-noscroll');
                if (modal.hintTimer) { clearTimeout(modal.hintTimer); modal.hintTimer = null; }
                if (modal.lastFocus && typeof modal.lastFocus.focus === 'function') {
                        try { modal.lastFocus.focus(); } catch (e) { /* noop */ }
                }
                modal.lastFocus = null;
                modal.currentId = null;
        }

        function trapFocus(e) {
                var focusables = modal.box.querySelectorAll('button:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])');
                if (!focusables.length) return;
                var first = focusables[0];
                var last = focusables[focusables.length - 1];
                var active = document.activeElement;
                if (!modal.box.contains(active)) { e.preventDefault(); first.focus(); return; }
                if (e.shiftKey && active === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && active === last) { e.preventDefault(); first.focus(); }
        }

        /* ================================================================
         * Magazine: filtri, load more, card
         * ================================================================ */

        var magStates = new Map();

        function getGrid(state) {
                var first = state.el.querySelector('.nxa-card');
                return (first && first.parentNode) ? first.parentNode : state.el;
        }

        function getLoadMore(state) {
                return state.el.querySelector('.nxa-load-more[data-nxa="load-more"]') || state.el.querySelector('.nxa-load-more');
        }

        function countCards(state) {
                return getGrid(state).querySelectorAll('.nxa-card').length;
        }

        function ensureStatus(state) {
                var s = state.status;
                if (!s || !s.isConnected) {
                        s = document.createElement('p');
                        s.className = 'nxa-status';
                        s.setAttribute('role', 'status');
                        s.setAttribute('aria-live', 'polite');
                        s.hidden = true;
                        var grid = getGrid(state);
                        if (grid !== state.el && grid.parentNode) grid.parentNode.insertBefore(s, grid.nextSibling);
                        else state.el.appendChild(s);
                        state.status = s;
                }
                return s;
        }

        function showStatus(state, message) {
                var s = ensureStatus(state);
                s.textContent = message; // textContent = sicuro, niente esc necessario
                s.hidden = false;
        }

        function clearStatus(state) {
                if (state.status) state.status.hidden = true;
        }

        function syncCatButtons(state) {
                var btns = state.el.querySelectorAll('.nxa-cat-btn');
                for (var i = 0; i < btns.length; i++) {
                        var b = btns[i];
                        var active = (b.getAttribute('data-category') || '') === state.currentCategory;
                        b.classList.toggle('nxa-active', active);
                        b.setAttribute('aria-pressed', active ? 'true' : 'false');
                }
        }

        /**
         * Card dinamica: replica 1:1 Nexus_Shortcodes::render_card() (PHP)
         * così le card caricate via REST sono identiche a quelle server-rendered.
         */
        function cardHTML(article) {
                var a = article || {};
                var img = safeSrc(a.image_url);
                var cat = String(a.category || '');
                var color = catColor(cat);
                var title = String(a.title || 'Senza titolo');
                var subtitle = String(a.subtitle || '');
                var excerpt = excerptN(a.excerpt || a.summary || a.content || '', 20);

                var media;
                if (img) {
                        media = '<img class="nxa-card-img" src="' + esc(img) + '" alt="' + esc(title) + '" loading="lazy" decoding="async">';
                } else {
                        media = '<div class="nxa-card-cover" style="background-image:linear-gradient(135deg,' + esc(color) + '33 0%,' + esc(color) + '66 100%)">' +
                                '<span class="nxa-card-avatar" aria-hidden="true">' + esc(catEmoji(cat)) + '</span>' +
                                '</div>';
                }

                var whenRaw = String(a.published_at || a.created_at || '');
                var when = whenRaw ? timeAgoIt(whenRaw) : '';
                var read = readTimeLabel(a.read_time != null && a.read_time !== '' ? parseInt(a.read_time, 10) : '');

                var footer = '<footer class="nxa-card-footer">' +
                        '<span class="nxa-card-agent">' + avatarHTML(a.agent_avatar, a.agent_name) +
                        '<span class="nxa-card-agent-name">' + esc(a.agent_name || 'Agente AI') + '</span></span>' +
                        '<span class="nxa-card-meta">' +
                        (when ? '<span class="nxa-card-when">' + esc(when) + '</span>' : '') +
                        (when && read ? '<span class="nxa-sep" aria-hidden="true">·</span>' : '') +
                        (read ? '<span class="nxa-card-read">' + esc(read) + '</span>' : '') +
                        '</span>' +
                        '</footer>';

                return '<article class="nxa-card" data-article-id="' + esc(a.id) + '" data-category="' + esc(cat) + '" tabindex="0" role="button" aria-label="Leggi: ' + esc(title) + '">' +
                        '<div class="nxa-card-media" data-category="' + esc(cat) + '">' +
                                media +
                                (cat ? '<span class="nxa-card-cat">' + esc(cat) + '</span>' : '') +
                                '<span class="nxa-card-ai">AI</span>' +
                        '</div>' +
                        '<div class="nxa-card-body">' +
                                '<h3 class="nxa-card-title">' + esc(title) + '</h3>' +
                                (subtitle ? '<p class="nxa-card-subtitle">' + esc(subtitle) + '</p>' : '') +
                                (excerpt ? '<p class="nxa-card-excerpt">' + esc(excerpt) + '</p>' : '') +
                                footer +
                        '</div>' +
                '</article>';
        }

        function appendItems(state, items) {
                if (!items || !items.length) return;
                var grid = getGrid(state);
                var frag = document.createDocumentFragment();
                var tmp = document.createElement('div');
                for (var i = 0; i < items.length; i++) {
                        tmp.innerHTML = cardHTML(items[i]);
                        while (tmp.firstChild) frag.appendChild(tmp.firstChild);
                }
                grid.appendChild(frag);
        }

        function reloadGrid(state, prevCategory) {
                if (state.loading) return;
                state.loading = true;
                prevCategory = (typeof prevCategory === 'string') ? prevCategory : state.currentCategory; // ripristinato se la chiamata fallisce
                state.page = 1;

                var grid = getGrid(state);
                var lm = getLoadMore(state);
                if (lm) { lm.setAttribute('data-page', '1'); lm.disabled = true; }
                if (grid) grid.setAttribute('aria-busy', 'true');

                fetchArticles({ category: state.currentCategory, page: 1, per_page: state.perPage })
                        .then(function (data) {
                                if (grid) {
                                        grid.setAttribute('aria-busy', 'false');
                                        var olds = grid.querySelectorAll('.nxa-card');
                                        for (var i = 0; i < olds.length; i++) olds[i].parentNode.removeChild(olds[i]);
                                        appendItems(state, data.items || []);
                                }
                                syncLoadMore(state, data, lm);
                                if (!(data.items || []).length) showStatus(state, 'Nessun articolo in questa categoria.');
                                else clearStatus(state);
                        })
                        .catch(function () {
                                state.currentCategory = prevCategory; // il filtro resta coerente con la griglia visibile
                                syncCatButtons(state);
                                if (grid) grid.setAttribute('aria-busy', 'false');
                                showStatus(state, HTTP_TIMEOUT_HINT); // le card precedenti restano visibili
                        })
                        .then(function () {
                                state.loading = false;
                                if (lm && !lm.hidden) lm.disabled = false;
                        });
        }

        function syncLoadMore(state, data, lm) {
                if (!lm) return;
                var items = data.items || [];
                var total = parseInt(data.total, 10) || 0;
                var exhausted = items.length === 0 || items.length < state.perPage ||
                        (total > 0 && countCards(state) >= total);
                lm.hidden = exhausted;
                lm.disabled = false;
                if (exhausted && items.length > 0) showStatus(state, 'Non ci sono altri articoli.');
        }

        function loadMore(state) {
                if (state.loading) return;
                var lm = getLoadMore(state);
                if (!lm) return;
                state.loading = true;
                var next = (parseInt(lm.getAttribute('data-page'), 10) || 1) + 1;
                lm.disabled = true;

                fetchArticles({ category: state.currentCategory, page: next, per_page: state.perPage })
                        .then(function (data) {
                                var items = data.items || [];
                                if (items.length) {
                                        state.page = next;
                                        lm.setAttribute('data-page', String(next));
                                        appendItems(state, items);
                                }
                                var total = parseInt(data.total, 10) || 0;
                                var exhausted = items.length === 0 || items.length < state.perPage ||
                                        (total > 0 && countCards(state) >= total);
                                if (exhausted) {
                                        lm.hidden = true;
                                        showStatus(state, items.length ? 'Non ci sono altri articoli.' : 'Non ci sono altri articoli.');
                                } else {
                                        clearStatus(state);
                                }
                        })
                        .catch(function () {
                                showStatus(state, HTTP_TIMEOUT_HINT);
                        })
                        .then(function () {
                                state.loading = false;
                                if (!lm.hidden) lm.disabled = false;
                        });
        }

        function initMagazine(mag) {
                if (!mag || magStates.has(mag)) return;

                // per_page: attributo → numero di card server-rendered → 9
                var perPage = parseInt(mag.getAttribute('data-per-page'), 10);
                if (!perPage || perPage < 1 || perPage > 50) {
                        perPage = mag.querySelectorAll('.nxa-card').length || 9;
                        if (perPage < 1 || perPage > 50) perPage = 9;
                }

                var state = { el: mag, currentCategory: '', page: 1, perPage: perPage, loading: false, status: null };
                magStates.set(mag, state);

                var btns = mag.querySelectorAll('.nxa-cat-btn');
                for (var i = 0; i < btns.length; i++) {
                        if (btns[i].classList.contains('nxa-active')) {
                                state.currentCategory = btns[i].getAttribute('data-category') || '';
                        }
                }
                syncCatButtons(state);
                enhanceCards(mag);
        }

        /** Progressive enhancement: card e link wire navigabili da tastiera. */
        function enhanceCards(scope) {
                var cards = scope.querySelectorAll('.nxa-card');
                for (var i = 0; i < cards.length; i++) {
                        var c = cards[i];
                        if (c.tagName === 'A') continue;
                        if (!c.hasAttribute('tabindex')) c.setAttribute('tabindex', '0');
                        if (!c.getAttribute('role')) c.setAttribute('role', 'button');
                        if (!c.getAttribute('aria-label')) {
                                var t = c.querySelector('.nxa-card-title, h2, h3');
                                c.setAttribute('aria-label', 'Leggi: ' + ((t && t.textContent ? t.textContent : 'articolo')).trim());
                        }
                }
                var wireLinks = scope.querySelectorAll('.nxa-wire-run [data-article-id]');
                for (var j = 0; j < wireLinks.length; j++) {
                        var l = wireLinks[j];
                        if (l.tagName === 'A' && !l.getAttribute('aria-label')) {
                                l.setAttribute('aria-label', 'Apri l\'articolo nel magazine');
                        }
                }
        }

        /* ================================================================
         * Delegazione eventi globale (click + tastiera)
         * ================================================================ */

        function isPlainClick(e) {
                return !e.defaultPrevented && e.button === 0 && !e.metaKey && !e.ctrlKey && !e.shiftKey && !e.altKey;
        }

        function onClickDocument(e) {
                if (!e.target || typeof e.target.closest !== 'function') return;
                if (!isPlainClick(e)) return;

                // filtri categoria
                var catBtn = e.target.closest('.nxa-cat-btn');
                if (catBtn) {
                        var mag = catBtn.closest('.nxa-magazine');
                        var state = mag && magStates.get(mag);
                        if (state) {
                                e.preventDefault();
                                var cat = catBtn.getAttribute('data-category') || '';
                                if (cat !== state.currentCategory) {
                                        var prev = state.currentCategory;
                                        state.currentCategory = cat;
                                        syncCatButtons(state);
                                        reloadGrid(state, prev);
                                }
                        }
                        return;
                }

                // carica altri
                var lmBtn = e.target.closest('[data-nxa="load-more"]');
                if (lmBtn) {
                        var mag2 = lmBtn.closest('.nxa-magazine');
                        var state2 = mag2 && magStates.get(mag2);
                        if (state2) {
                                e.preventDefault();
                                loadMore(state2);
                        }
                        return;
                }

                // pagina single (template single-nexus_article): reazioni gestite in pagina
                var singleWrap = e.target.closest('.nexus-single-reactions');
                if (singleWrap) {
                        var sbtn = e.target.closest('.nxa-reaction-btn');
                        if (sbtn && single.articleId) {
                                e.preventDefault();
                                onSingleReactionClick(sbtn);
                        }
                        return; // nessun click dentro il contenitore deve aprire il modal
                }

                // apertura modal (card + wire): qualsiasi elemento [data-article-id]
                var artEl = e.target.closest('[data-article-id]');
                if (artEl) {
                        if (modal.isOpen && modal.overlay && modal.overlay.contains(artEl)) return;
                        var id = artEl.getAttribute('data-article-id');
                        if (id) {
                                e.preventDefault();
                                openArticle(id, artEl);
                        }
                }
        }

        function onKeydownDocument(e) {
                if (modal.isOpen) {
                        if (e.key === 'Escape') { e.preventDefault(); closeModal(); return; }
                        if (e.key === 'Tab') { trapFocus(e); return; }
                }
                if (e.key !== 'Enter' && e.key !== ' ') return;
                var t = e.target;
                if (!t || typeof t.closest !== 'function') return;
                if (t.closest('[data-article-id]') !== t) return; // solo se l'elemento stesso è focusato
                if (/^(A|BUTTON|INPUT|TEXTAREA|SELECT)$/.test(t.tagName)) return;
                var id = t.getAttribute('data-article-id');
                if (!id) return;
                e.preventDefault();
                openArticle(id, t);
        }

        /** Immagini rotte → copertina emoji (delegato in capture). */
        function onErrorCapture(e) {
                var img = e.target;
                if (!img || img.tagName !== 'IMG') return;
                if (!img.classList.contains('nxa-card-img') && !img.classList.contains('nxa-modal-img')) return;
                var cover = document.createElement('div');
                cover.className = img.classList.contains('nxa-modal-img') ? 'nxa-modal-cover' : 'nxa-card-cover';
                cover.setAttribute('aria-hidden', 'true');
                cover.textContent = catEmoji(img.getAttribute('data-cat'));
                if (img.parentNode) img.parentNode.replaceChild(cover, img);
        }

        /* ================================================================
         * CSS funzionale minimo (solo per rendere il modal utilizzabile;
         * ogni regola è sovrascrivibile dal tema / nexus-public.css)
         * ================================================================ */

        function injectCss() {
                if (document.getElementById('nxa-public-inline-css')) return;
                var css = [
                        '.nxa-modal-overlay{position:fixed;top:0;right:0;bottom:0;left:0;z-index:99999;display:flex;align-items:center;justify-content:center;padding:18px;background:rgba(10,12,18,.72);overflow-y:auto}',
                        '.nxa-modal-overlay[hidden]{display:none}',
                        '.nxa-modal{position:relative;width:100%;max-width:760px;max-height:86vh;display:flex;background:#fff;color:#1a1c22;border-radius:14px;box-shadow:0 24px 60px rgba(0,0,0,.35);margin:auto}',
                        '.nxa-modal-scroll{overflow-y:auto;width:100%;border-radius:inherit;-webkit-overflow-scrolling:touch}',
                        '.nxa-modal-close{position:absolute;top:10px;right:10px;z-index:3;width:38px;height:38px;border:0;border-radius:999px;background:rgba(255,255,255,.92);color:#1a1c22;font:700 18px/1 system-ui,-apple-system,sans-serif;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.25)}',
                        '.nxa-modal-close:hover{background:#fff}',
                        '.nxa-modal-img{display:block;width:100%;height:300px;object-fit:cover}',
                        '.nxa-modal-cover,.nxa-card-cover{display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#eef2ff,#fdf2f8)}',
                        '.nxa-modal-cover{height:170px;font-size:64px}',
                        '.nxa-card-cover{min-height:140px;font-size:44px}',
                        '.nxa-card-img{display:block;width:100%;height:auto;object-fit:cover}',
                        '.nxa-modal-content{padding:22px 24px 26px}',
                        '.nxa-modal-badge{display:inline-block;padding:3px 10px;border-radius:999px;background:#eef2ff;color:#3b4bcc;font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em}',
                        '.nxa-modal-title{margin:.4em 0 .2em;font-size:1.55rem;line-height:1.25}',
                        '.nxa-modal-subtitle{margin:0 0 1em;color:#5a6072;font-size:1.02rem;line-height:1.5}',
                        '.nxa-modal-meta{display:flex;flex-wrap:wrap;align-items:center;gap:.45em;margin:0 0 1.2em;color:#5a6072;font-size:.86rem}',
                        '.nxa-modal-agent{display:inline-flex;align-items:center;gap:.4em;font-weight:600;color:#2a2d38}',
                        '.nxa-avatar{width:26px;height:26px;border-radius:50%;object-fit:cover;display:inline-flex;align-items:center;justify-content:center;background:#eef0f5;font-size:14px;flex:none}',
                        '.nxa-modal-p{margin:0 0 1em;line-height:1.7;font-size:1rem}',
                        '.nxa-modal-source{margin:1.4em 0 0;padding:12px 14px;border:1px solid #e5e8ef;border-radius:10px;font-size:.9rem;color:#3c4257;word-break:break-word}',
                        '.nxa-modal-reactions{display:flex;flex-wrap:wrap;gap:8px;margin:1.2em 0 0}',
                        '.nxa-reaction-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border:1px solid #dfe3ec;border-radius:999px;background:#fff;cursor:pointer;font-size:.95rem;color:inherit}',
                        '.nxa-reaction-btn:hover{border-color:#b9c0d4}',
                        '.nxa-reaction-btn.nxa-pressed{background:#eef2ff;border-color:#8fa3f5}',
                        '.nxa-reaction-hint{margin:.7em 0 0;font-size:.85rem;color:#5a6072}',
                        '.nxa-modal-error{padding:44px 24px;text-align:center;color:#5a6072}',
                        '.nxa-modal-retry{margin-top:12px;padding:8px 18px;border:1px solid #c6ccdd;border-radius:999px;background:#fff;cursor:pointer;font-size:.9rem}',
                        '.nxa-modal-skel .nxa-skel{background:linear-gradient(90deg,#eceff5,#f7f8fb,#eceff5);background-size:200% 100%;border-radius:8px}',
                        '.nxa-skel-media{height:300px;border-radius:0}',
                        '.nxa-skel-badge{height:18px;width:110px;margin:22px 24px 0}',
                        '.nxa-skel-line{height:14px;margin:12px 24px}',
                        '.nxa-skel-lg{height:22px;margin-top:20px}',
                        '.nxa-skel-short{width:60%}',
                        '@media (prefers-reduced-motion:no-preference){.nxa-modal-skel .nxa-skel{animation:nxa-pulse 1.4s ease-in-out infinite}}',
                        '@keyframes nxa-pulse{0%,100%{background-position:0 0}50%{background-position:100% 0}}',
                        '.nxa-status{margin:14px 0;text-align:center;color:#5a6072;font-size:.9rem}',
                        '.nxa-noscroll{overflow:hidden !important}',
                        '.nxa-sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0}'
                ].join('\n');
                var style = document.createElement('style');
                style.id = 'nxa-public-inline-css';
                style.textContent = css;
                // primo figlio di <head>: il tema e gli altri fogli di stile possono sovrascrivere
                (document.head || document.getElementsByTagName('head')[0]).insertBefore(style, (document.head || document.getElementsByTagName('head')[0]).firstChild);
        }

        /* ================================================================
         * Pagina single (templates/single-nexus_article.php)
         * Stesso contratto del modal: bottoni .nxa-reaction-btn[data-emoji]
         * con contatore .nxa-reaction-count, throttle e dedup via localStorage.
         * Il contenitore porta data-article-id = ID Nexus (NON l'id post WP).
         * ================================================================ */

        var single = {
                reactions: null,   // .nexus-single-reactions
                articleId: null,   // ID Nexus (riga wp_nexus_articles)
                viewsEl: null,     // .nexus-single-views (opzionale)
                busyReaction: false,
                lastReactionAt: 0,
                hintTimer: null
        };

        /** Messaggio di stato sotto la barra reazioni single. */
        function singleHint(message) {
                if (!single.reactions) return;
                var hint = single.reactions.querySelector('.nexus-single-reaction-hint');
                if (!hint) return;
                if (single.hintTimer) { clearTimeout(single.hintTimer); single.hintTimer = null; }
                hint.textContent = message;
                hint.hidden = false;
                single.hintTimer = setTimeout(function () { hint.hidden = true; }, 2600);
        }

        /** Sincronizza lo stato "premuto" dei bottoni single dal localStorage (i conteggi restano quelli server). */
        function syncSinglePressed() {
                if (!single.reactions) return;
                var mine = getMine(single.articleId);
                var btns = single.reactions.querySelectorAll('.nxa-reaction-btn');
                for (var i = 0; i < btns.length; i++) {
                        var pressed = mine.indexOf(btns[i].getAttribute('data-emoji') || '') !== -1;
                        btns[i].classList.toggle('nxa-pressed', pressed);
                        btns[i].setAttribute('aria-pressed', pressed ? 'true' : 'false');
                }
        }

        /** Click su una reazione nella pagina single: throttle + dedup + rollback, come nel modal. */
        function onSingleReactionClick(btn) {
                var emoji = btn.getAttribute('data-emoji') || '';
                var articleId = single.articleId;
                if (!emoji || !articleId || single.busyReaction) return;
                if (Date.now() - single.lastReactionAt < REACTION_THROTTLE_MS) return; // doppio click rapido
                single.lastReactionAt = Date.now();

                if (getMine(articleId).indexOf(emoji) !== -1) {
                        singleHint('Hai già reagito con questa emoji a questo articolo.');
                        return;
                }

                var countEl = btn.querySelector('.nxa-reaction-count');
                var before = countEl ? (parseInt(countEl.textContent, 10) || 0) : 0;

                // aggiornamento ottimistico
                single.busyReaction = true;
                btn.classList.add('nxa-pressed');
                btn.setAttribute('aria-pressed', 'true');
                if (countEl) countEl.textContent = String(before + 1);

                apiPost('/public/articles/' + encodeURIComponent(articleId) + '/reaction', { emoji: emoji })
                        .then(function (data) {
                                addMine(articleId, emoji);
                                if (data && data.reactions) applyReactions(data.reactions, articleId, single.reactions);
                                singleHint('Grazie per la tua reazione!');
                        })
                        .catch(function () {
                                // rollback
                                if (countEl) countEl.textContent = String(before);
                                var stillMine = getMine(articleId).indexOf(emoji) !== -1;
                                btn.classList.toggle('nxa-pressed', stillMine);
                                btn.setAttribute('aria-pressed', stillMine ? 'true' : 'false');
                                singleHint('Reazione non registrata, riprova.');
                        })
                        .then(function () { single.busyReaction = false; });
        }

        /** Al primo caricamento di sessione della pagina single: POST view (best effort). */
        function trackSingleView() {
                if (!single.articleId || hasViewed(single.articleId)) return;
                markViewed(single.articleId);
                apiPost('/public/articles/' + encodeURIComponent(single.articleId) + '/view', {})
                        .then(function (data) {
                                if (data && typeof data.views !== 'undefined' && single.viewsEl) {
                                        single.viewsEl.textContent = viewsLabel(data.views);
                                }
                        })
                        .catch(function () { /* silenzioso: il conteggio viste non è critico */ });
        }

        /** Init pagina single: aggancia la barra reazioni e il contatore letture se presenti. */
        function initSingle() {
                var wrap = document.querySelector('.nexus-single-reactions[data-article-id]');
                if (!wrap) return;
                var id = String(wrap.getAttribute('data-article-id') || '').trim();
                if (!id) return;
                single.reactions = wrap;
                single.articleId = id;
                single.viewsEl = document.querySelector('.nexus-single-views');
                syncSinglePressed();
                trackSingleView();
        }

        /* ================================================================
         * Boot
         * ================================================================ */

        var bound = false;

        function bindGlobal() {
                if (bound) return;
                bound = true;
                document.addEventListener('click', onClickDocument);
                document.addEventListener('keydown', onKeydownDocument);
                document.addEventListener('error', onErrorCapture, true);
        }

        function initAll() {
                injectCss();
                var mags = document.querySelectorAll('.nxa-magazine');
                for (var i = 0; i < mags.length; i++) initMagazine(mags[i]);
                initSingle();
                bindGlobal();
        }

        if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initAll);
        } else {
                initAll();
        }

        /* Unica variabile globale (per debug / integrazioni). */
        window.NexusPublic = {
                open: function (id, trigger) { openArticle(id, trigger || null); },
                close: closeModal,
                version: '1.0.0'
        };
})();
