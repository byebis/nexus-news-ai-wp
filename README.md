# Nexus News AI — Plugin WordPress

![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759B?logo=wordpress&logoColor=white)
![Licenza](https://img.shields.io/badge/Licenza-MIT-green)
![Versione](https://img.shields.io/badge/versione-1.0.4-blue)

> Il giornale di nuova generazione, scritto da intelligenze artificiali.

**Nexus News AI** è un plugin WordPress che porta nel tuo sito una redazione autonoma: **7 agenti giornalisti AI**, ognuno specializzato in una categoria editoriale, che raccolgono notizie da fonti RSS, le valutano, le riscrivono con l'AI, le sottopongono ad approvazione e le pubblicano su più canali (blog reale, social reali su Telegram/Discord/X/LinkedIn con fallback simulato onesto per i canali non configurati, newsletter via email). Include admin panel completo, magazine frontend con reazioni, **Nexus Wire** (redazione collettiva multi-agente), ping di indicizzazione automatici, cron automatico, REST API e — dalla 1.0.3 — la **redazione intelligente**: modello AI per-agente con modello economico per lo screening, tracking dell'uso AI (token e costo stimato in USD) con budget mensile, fact-check automatico con citazioni, anti-duplicato via hash del titolo e traduzioni AI multi-lingua. Dalla 1.0.4 aggiunge **Scala & Pro**: coda lavori stile Action Scheduler con retry automatico, comando WP-CLI `wp nexus`, blocchi Gutenberg nativi e una pagina Analytics per misurare l'intera redazione.

Il plugin è il porting WordPress (PHP puro + vanilla JS) dell'app Next.js originale **Nexus News AI**.

---

## Indice

1. [Caratteristiche](#caratteristiche)
2. [Mapping Next.js → WordPress](#mapping-nextjs--wordpress)
3. [Architettura dei file](#architettura-dei-file)
4. [I 7 agenti giornalisti](#i-7-agenti-giornalisti)
5. [La pipeline editoriale](#la-pipeline-editoriale)
6. [Shortcode](#shortcode)
7. [REST API `nexus/v1`](#rest-api-nexusv1)
8. [Impostazioni](#impostazioni)
9. [Cron automatico](#cron-automatico)
10. [Pubblicazione multi-piattaforma](#pubblicazione-multi-piattaforma)
11. [Redazione intelligente (v1.0.3)](#redazione-intelligente-v103)
12. [Scala & Pro (v1.0.4)](#scala--pro-v104)
13. [Installazione](#installazione)
14. [FAQ tecniche](#faq-tecniche)
15. [Licenza e crediti](#licenza-e-crediti)
16. [Changelog](#changelog)

---

## Caratteristiche

- **7 agenti giornalisti AI** con nome, emoji, categoria e personalità distinti (seed automatici all'attivazione).
- **Pipeline editoriale completa**: raccolta RSS → valutazione → riscrittura AI → approvazione → pubblicazione multi-piattaforma.
- **Modalità semi-autonoma**: gli articoli entrano in coda di approvazione e vengono pubblicati solo dopo il via libera umano.
- **Modalità completamente autonoma**: auto-approvazione basata sul punteggio di qualità (`auto_approve_score`), la pipeline gira da sola via WP-Cron.
- **Admin panel completo**: dashboard statistiche, gestione agenti, coda di approvazione, feed attività, Nexus Wire, impostazioni.
- **Magazine frontend**: hero section, barre categoria, griglia articoli, modal di lettura, reazioni emoji e contatore visualizzazioni.
- **Shortcode**: `[nexus_magazine]`, `[nexus_articles]`, `[nexus_wire]`, `[nexus_subscribe]`.
- **Cron automatico** con intervallo configurabile e limite giornaliero di articoli.
- **REST API `nexus/v1`**: dati e azioni della redazione accessibili programmaticamente.
- **Nexus Wire**: pipeline multi-agente (raccolta candidati → selezione fonti → dossier del ricercatore → bozza del redattore → revisioni a 2 voci → editor finale) con storia delle run e bacheca commenti.
- **Pubblicazione come post WordPress reali** (v1.0.1): gli articoli approvati diventano POST veri del CPT `nexus_article`, con categorie e tag nativi di WordPress e permalink `/nexus/{slug}/`; in fallback resta il front-end Nexus legacy (`/?nexus_article={id}`).
- **Immagini in evidenza AI** (v1.0.1): featured image generata con l'AI (modello e dimensione configurabili) con fallback sull'immagine dell'articolo e infine su un placeholder locale; la pubblicazione non si blocca mai per un'immagine.
- **SEO automatico** (v1.0.1): meta title/description generate con l'AI, meta description + Open Graph + JSON-LD `NewsArticle` sul single, compatibilità con Yoast SEO e RankMath.
- **Pubblicazione social reale** (v1.0.2): invio vero degli articoli su **Telegram** (Bot API, `sendMessage`/`sendPhoto`), **Discord** (webhook con embed), **X/Twitter** (`/2/tweets` con firma OAuth 1.0a in PHP puro) e **LinkedIn** (UGC post via Bearer token), con le credenziali nei settings; i canali non configurati producono un log `skipped` onesto e Facebook/Instagram restano simulati in modo dichiarato.
- **Newsletter con double opt-in** (v1.0.2): shortcode `[nexus_subscribe]` per l'iscrizione via REST con conferma via email (link `/?nexus_confirm={token}`) e disiscrizione via token, tabella `wp_nexus_subscribers` e **digest settimanale AI** inviato con `wp_mail` ai destinatari fissi e agli iscritti confermati.
- **Ping di indicizzazione automatici** (v1.0.2): alla pubblicazione di un post `nexus_article` partono **IndexNow** (Bing e altri, chiave auto-generata con key file servito dal plugin) e **WebSub/PubSubHubbub** sul feed, fuori banda via WP-Cron senza mai bloccare la pubblicazione.
- **Modello AI per-agente + modello economico** (v1.0.3): ogni agente può usare il proprio modello (`ai_model`, vuoto = modello globale) mentre raccolta (screening), valutazione e fact-check girano sul modello economico `ai_model_cheap` per contenere i costi.
- **Tracking uso AI** (v1.0.3): ogni chiamata AI logga in `wp_nexus_usage_logs` i token (prompt/completion/total) e il **costo stimato in USD**; la dashboard mostra «Uso AI» del mese e il **budget mensile** (`monthly_budget_usd`) con azione `stop` (salta le fasi AI) o `warn` (avviso e prosegui).
- **Fact-check automatico** (v1.0.3): dopo la riscrittura l'articolo viene verificato contro la fonte con **punteggio 0-100** e **citazioni** claim→fonte; sotto la soglia `factcheck_min_score` torna in bozza, sopra pubblica con il blocco **«Fonti verificate»** nel post WordPress.
- **Anti-duplicato via hash del titolo** (v1.0.3): in fase di raccolta ogni candidato passa per l'hash md5 del titolo normalizzato (`wp_nexus_articles.content_hash`); i duplicati vengono saltati con log onesto.
- **Traduzioni AI multi-lingua** (v1.0.3): traduzione di titolo, riassunto e contenuto via REST (`POST /articles/{id}/translate`) nelle lingue configurate (`translation_langs`, default `en,es,fr`) e lettura delle traduzioni via `GET /articles/{id}/translations`; upsert in `wp_nexus_translations`.
- **Coda lavori stile Action Scheduler** (v1.0.4): le azioni pesanti (pipeline, traduzioni, tick del cron) vengono accodate in `wp_nexus_jobs` con claim atomico, priorità e retry automatico con backoff esponenziale (60s × 2^n, cap 1 ora), requeue dei lavori bloccati oltre 30 minuti e pulizia dei lavori finiti da più di 7 giorni; runner WP-Cron ogni 60 secondi (solo con `enable_queue=1`) e pagina admin «Coda Lavori» con stat, filtri, riprova/annulla, «Processa ora» e auto-refresh.
- **WP-CLI** (v1.0.4): comando `wp nexus` per pilotare la redazione da terminale e da cron di sistema: `run --all/--agent`, `collect`, `evaluate`, `rewrite`, `publish`, `approve`/`reject`, `translate`, `agents`, `articles`, `stats`, `queue stats|process|retry|cancel|cleanup`, `settings get/set`.
- **Blocchi Gutenberg** (v1.0.4): blocchi dinamici server-side `nexus/latest-articles` (numero articoli 1-24, categoria, colonne 1-4, mostra immagini) e `nexus/subscribe-form` (titolo personalizzabile) con preview nell'editor, senza build step; alternativa a `[nexus_articles]` e `[nexus_subscribe]`.
- **Analytics** (v1.0.4): pagina Analytics con serie giornaliera (7-90 giorni) di articoli creati/pubblicati, chiamate AI e costo stimato del periodo, breakdown per piattaforma e per agente (con qualità media), top 10 articoli per viste e iscritti newsletter.
- **Zero dipendenze da compilare**: nessun build step, nessun framework, solo PHP + JS/CSS vanilla.
- **i18n**: text domain `nexus-news-ai`, file `.pot` in `languages/`.
- **Disinstallazione pulita**: `uninstall.php` rimuove tabelle (incluse `wp_nexus_subscribers`, `wp_nexus_usage_logs`, `wp_nexus_translations`), opzioni, transients, eventi cron (`nexus_weekly_digest`, `nexus_ping_article`) e i post WordPress reali `nexus_article` con i loro meta `_nexus_*`.

---

## Mapping Next.js → WordPress

| Componente originale (Next.js) | Equivalente WordPress (plugin) |
|---|---|
| Supabase (PostgreSQL): tabelle `agents`, `articles`, `publish_logs`, `approval_logs`, `activity_logs`, `settings` | Tabelle MySQL custom `wp_nexus_agents`, `wp_nexus_articles`, `wp_nexus_publish_logs`, `wp_nexus_approval_logs`, `wp_nexus_activity_logs` + option `nexus_settings` (schema via `dbDelta` in `Nexus_DB`) |
| API Routes `/api/*` (App Router) | REST API `nexus/v1` registrate in `class-nexus-rest.php` |
| Admin panel React (`components/admin/*`) | Admin WP nativo (menu `Nexus News`) + viste PHP in `admin/views/` + vanilla JS |
| Magazine React (`components/magazine/*`) | Output shortcode PHP + vanilla JS/CSS in `public/` |
| Cron route `/api/cron` (scheduler esterni) | **WP-Cron** (`class-nexus-cron.php`): evento `nexus_cron_tick` su schedule custom `nexus_interval`, auto-sincronizzato con le impostazioni |
| Zustand store (`store.ts`) | **Nessuno**: lo stato vive nel DB; le pagine admin interrogano la REST API con fetch |
| Motore AI `ai-engine.ts` + `openrouter.ts` | `class-nexus-ai-engine.php` (client OpenRouter-compatible, prompt editoriali IT, JSON repair) |
| RSS `rss.ts` | `class-nexus-rss.php` (DOMDocument, cache via transients `nexus_rss_*`) |
| Pubblicazione `publish` API | `class-nexus-publisher.php` (post WP reali + social reali Telegram/Discord/X/LinkedIn o simulati, log onesti) |
| (nessun equivalente) | **Newsletter** `[nexus_subscribe]` con double opt-in e digest settimanale AI (`class-nexus-newsletter.php`) e ping **IndexNow/WebSub** (`class-nexus-ping.php`) — v1.0.2 |
| (nessun equivalente) | **Scala & Pro** — coda lavori `wp_nexus_jobs` (`class-nexus-queue.php`), WP-CLI `wp nexus` (`class-nexus-cli.php`), blocchi Gutenberg (`class-nexus-blocks.php` + `assets/js/blocks.js`) e Analytics (`class-nexus-analytics.php`) — v1.0.4 |
| Pipeline `collect-all.ts` / `processCategoryForAgent` | `class-nexus-pipeline.php` (le 5 fasi, eseguibili anche singolarmente) |
| Framer Motion / Tailwind / shadcn/ui | CSS vanilla (`public/css/`) con animazioni native |

---

## Architettura dei file

```
nexus-news-ai/
├── nexus-news-ai.php              # Bootstrap: header plugin, costanti, require, hook attivazione/disattivazione
├── uninstall.php                  # Disinstallazione: drop tabelle wp_nexus_* (incluse subscribers, usage_logs, translations), opzioni, transients, eventi cron, post nexus_article + meta
├── readme.txt                     # Readme formato WordPress (repository.org)
├── README.md                      # Questo file
├── LICENSE                        # Licenza MIT
│
├── includes/                      # Classi core (nessun output, solo logica)
│   ├── class-nexus-db.php         # Layer dati: schema dbDelta, CRUD, settings, seed agenti, stats, drop_tables()
│   ├── class-nexus-utils.php      # Utility condivise (sanitizzazione, slug, formattazioni)
│   ├── class-nexus-rss.php        # Raccolta RSS: fetch feed, parse, cache transients nexus_rss_*
│   ├── class-nexus-ai-engine.php  # Client AI OpenRouter-compatible: chat/completions, prompt editoriali IT, JSON repair, tracking uso AI (token/costo stimato) e budget mensile, fact-check, estimate_cost (v1.0.3)
│   ├── class-nexus-pipeline.php   # Le 5 fasi editoriali: collect → evaluate → rewrite → approve → publish
│   ├── class-nexus-publisher.php  # Pubblicazione: post WordPress reali (CPT nexus_article, v1.0.1) + fallback legacy + social reali/simulati, log in publish_logs
│   ├── class-nexus-cpt.php        # v1.0.1 — CPT nexus_article: registrazione post type, tassonomie native (categorie/tag), rewrite /nexus/, template single, meta box «Nexus»
│   ├── class-nexus-image.php      # v1.0.1 — Immagini in evidenza: generazione AI con fallback, sideload in media library, set_post_thumbnail
│   ├── class-nexus-seo.php        # v1.0.1 — SEO: meta AI, Open Graph, JSON-LD NewsArticle, compatibilità Yoast/RankMath, title tag
│   ├── class-nexus-social-telegram.php # v1.0.2 — Telegram: Bot API sendMessage/sendPhoto con credenziali dai settings
│   ├── class-nexus-social-discord.php  # v1.0.2 — Discord: webhook con embed e thumbnail
│   ├── class-nexus-social-x.php        # v1.0.2 — X/Twitter: POST /2/tweets con firma OAuth 1.0a nativa (nessuna libreria)
│   ├── class-nexus-social-linkedin.php # v1.0.2 — LinkedIn: ugcPosts via Bearer token
│   ├── class-nexus-newsletter.php      # v1.0.2 — Newsletter: shortcode [nexus_subscribe], double opt-in, digest settimanale AI via wp_mail
│   ├── class-nexus-ping.php            # v1.0.2 — Ping: IndexNow (chiave + key file) e WebSub alla pubblicazione
│   ├── class-nexus-translate.php       # v1.0.3 — Traduzioni AI: rotte REST translate/translations, prompt multi-lingua, upsert in wp_nexus_translations
│   ├── class-nexus-queue.php           # v1.0.4 — Coda lavori stile Action Scheduler: dispatch, run_batch (claim atomico, retry con backoff), retry, cancel, requeue_stale, runner WP-Cron nexus_queue_runner
│   ├── class-nexus-cli.php             # v1.0.4 — WP-CLI: comando wp nexus (run, collect, evaluate, rewrite, publish, approve, reject, translate, agents, articles, stats, queue, settings)
│   ├── class-nexus-blocks.php          # v1.0.4 — Blocchi Gutenberg server-side: nexus/latest-articles e nexus/subscribe-form
│   ├── class-nexus-analytics.php       # v1.0.4 — Analytics: overview() con serie giornaliera, costi AI, per piattaforma, per agente, top articoli, iscritti
│   ├── class-nexus-cron.php       # WP-Cron: scheduling nexus_cron_tick, esecuzione fasi automatiche
│   ├── class-nexus-rest.php       # REST API nexus/v1: route, permessi, schema args
│   └── index.php                  # Guardia "Silence is golden"
│
├── admin/                         # Back-end WordPress
│   ├── class-nexus-admin.php      # Menu admin, enqueue assets, handler, gateway alle viste
│   ├── css/                       # nexus-admin.css: stili delle pagine admin
│   ├── views/                     # Template HTML delle pagine admin
│   │   ├── dashboard.php          # Dashboard statistiche
│   │   ├── agents.php             # Gestione agenti
│   │   ├── queue.php              # Coda di approvazione
│   │   ├── jobs.php               # v1.0.4 — Coda Lavori: stat, filtri stato, riprova/annulla, «Processa ora», pulizia, auto-refresh 15s
│   │   ├── activity.php           # Feed attività
│   │   ├── wire.php               # Nexus Wire (redazione collettiva)
│   │   ├── analytics.php          # v1.0.4 — Analytics: serie giornaliera, costi AI, per piattaforma/agente, top articoli, iscritti
│   │   └── settings.php           # Impostazioni
│   └── index.php
│
├── public/                        # Front-end magazine
│   ├── class-nexus-shortcodes.php # [nexus_magazine], [nexus_articles], [nexus_wire] + render + enqueue
│   ├── css/                       # nexus-public.css: stili del magazine (classi nxa-*)
│   ├── js/                        # nexus-public.js: interazioni (modal, reazioni, categorie) via REST + nonce wp_rest
│   └── index.php
│
├── assets/                        # Asset condivisi admin
│   ├── js/                        # admin.js (interfaccia admin) + blocks.js (v1.0.4: registrazione blocchi Gutenberg nell'editor) — vanilla JS, consuma le REST route nexus/v1
│   └── index.php
│
├── templates/                     # Template front-end per i post WordPress reali (v1.0.1)
│   └── single-nexus_article.php   # Single del CPT nexus_article: header/footer del tema, byline agente, contenuto, fonti, reazioni Nexus
│
└── languages/                     # Internazionalizzazione
    ├── nexus-news-ai.pot          # Template di traduzione (gettext)
    └── index.php
```

Ogni directory contiene un `index.php` di silenzio per impedire il directory listing.

---

## I 7 agenti giornalisti

Gli agenti vengono inseriti automaticamente alla prima attivazione (solo se la tabella è vuota) e sono modificabili dall'admin panel.

| Agente | Emoji | Categoria | Personalità |
|---|---|---|---|
| TechBot | 🤖 | Tecnologia | Analitico e orientato all'impatto pratico per lettori tech-savvy |
| PolicyWatch | 🏛️ | Politica | Equilibrato e oggettivo, con contesto storico e geopolitico |
| MarketPulse | 📈 | Economia | Rigoroso ma accessibile, con dati concreti e implicazioni per investitori |
| SciExplorer | 🔬 | Scienza | Divulgativo ma preciso, spiegazioni chiare per un pubblico colto |
| SportArena | ⚽ | Sport | Emozionante e narrativo, con statistiche e analisi tattiche |
| CultureHub | 🎭 | Cultura | Elegante e riflessivo, con riferimenti storici e artistici |
| HealthDesk | 🩺 | Salute | Rassicurante e basato su evidenze scientifiche, con consigli pratici |

---

## La pipeline editoriale

La pipeline è orchestrata da `class-nexus-pipeline.php` (contratto: `collect`, `evaluate`, `rewrite`, `approve`, `reject`, `publish`, `run_full`, `cron_tick`, `wire_run`, `wire_runs`) e può essere eseguita per intero ("Esegui pipeline completa" nell'admin o `POST /pipeline/run`), a fasi singole, o automaticamente via cron. **Tutte le fasi generative richiedono la chiave AI**: senza chiave ogni fase registra nei log attività l'errore «Chiave API AI non configurata».

1. **Raccolta (collect)** — `Nexus_RSS` scarica gli item dai feed RSS della categoria dell'agente (se non ci sono candidati, percorso **evergreen**); una chiamata AI (`collect_prompt`) seleziona i **massimo 2 candidati migliori** (score ≥ 50), salvati come articoli in stato `draft` con titolo, fonte, URL e riassunto. Richiede il modello AI configurato.
2. **Valutazione (evaluate)** — una chiamata AI (`evaluate_prompt`) assegna il `quality_score` 0-100, salvato sull'articolo; l'esito finisce nel feed `activity_logs`.
3. **Riscrittura AI (rewrite)** — una chiamata AI (`rewrite_prompt`) produce titolo, sottotitolo, contenuto e riassunto definitivi in stile editoriale (categoria + personalità dell'agente): l'articolo passa a `pending_approval` con `read_time` calcolato.
4. **Approvazione (approve)** —
   - *Modalità semi-autonoma* (default): l'articolo passa a `pending_approval` e compare nella **coda di approvazione**; il revisore umano può approvare o rifiutare con nota (tabella `wp_nexus_approval_logs`).
   - *Modalità completamente autonoma*: gli articoli con `quality_score >= auto_approve_score` vengono auto-approvati, gli altri restano in coda come rete di sicurezza.
5. **Pubblicazione multi-piattaforma** — l'articolo approvato viene inviato alle piattaforme abilitate in `social_platforms`: per **blog la pubblicazione è reale** (dalla v1.0.1 l'articolo diventa un post WordPress del CPT `nexus_article` con permalink `/nexus/{slug}/`; in fallback resta nel database Nexus con URL legacy `/?nexus_article={id}` registrato nei log), i canali **Telegram, Discord, X e LinkedIn sono reali dalla v1.0.2** quando abilitati e configurati nei settings (altrimenti log `skipped` con nota onesta), gli altri canali social restano **simulati in modo dichiarato** (post_id e URL demo) e ogni invio genera una riga in `wp_nexus_publish_logs`.

Stati dell'articolo: `draft` → `pending_approval` → `approved` → `published` (o `rejected`).

---

## Shortcode

### `[nexus_magazine]`

Il magazine completo: hero con articolo in evidenza, barre categorie, griglia articoli, modal di dettaglio con reazioni emoji.

```
[nexus_magazine]
```

### `[nexus_articles]`

Lista o griglia di articoli filtrabili. Attributi:

| Attributo | Default | Valori |
|---|---|---|
| `category` | (tutte) | `Tecnologia`, `Politica`, `Economia`, `Scienza`, `Sport`, `Cultura`, `Salute` |
| `limit` | `9` | numero massimo di articoli (1-24) |
| `layout` | `grid` | `grid` (card) o `list` (elenco compatto) |

```
[nexus_articles category="Tecnologia" limit="6" layout="list"]
[nexus_articles category="Sport" limit="3"]
[nexus_articles limit="12" layout="grid"]
```

### `[nexus_wire]`

La bacheca del **Nexus Wire**: storico delle run della redazione collettiva (argomento, agenti coinvolti, fonti usate, commenti di revisione) e articolo prodotto dall'ultima sessione. Attributo opzionale `limit` (default `10`, max `20`).

```
[nexus_wire]
```

### `[nexus_subscribe]`

Il **form di iscrizione alla newsletter** (v1.0.2): campo email + bottone, invio via REST (`POST /nexus/v1/subscribe`, rate limit 1 richiesta al minuto per IP) e **double opt-in** — dopo l'iscrizione parte un'email con il link di conferma (`/?nexus_confirm={token}`); con il link di disiscrizione (`/?nexus_unsub={token}`) l'iscritto si rimuove da solo. I messaggi sono in italiano e traducibili via `.pot`.

```
[nexus_subscribe]
```

> **Nota (v1.0.1):** gli articoli pubblicati come **post WordPress reali** non passano dagli shortcode: ogni post del CPT `nexus_article` è servito dal template dedicato del plugin (`templates/single-nexus_article.php`), che usa header e footer del tema attivo e mostra byline dell'agente, contenuto, fonti e reazioni Nexus, con permalink `/nexus/{slug}/`. Lo shortcode `[nexus_magazine]` continua invece a leggere il database Nexus (articoli legacy e magazine).

> **Nota (v1.0.4):** nell'editor a blocchi puoi usare i blocchi Gutenberg **`nexus/latest-articles`** e **`nexus/subscribe-form`**, alternativi a `[nexus_articles]` e `[nexus_subscribe]`: vedi la sezione [Scala & Pro](#scala--pro-v104).

---

## REST API `nexus/v1`

Namespace REST: **`/wp-json/nexus/v1/`** (registrate in `class-nexus-rest.php`).

- **Route admin**: richiedono un utente con capability `manage_options` e nonce WordPress (`X-WP-Nonce` con cookie di sessione, o Application Password).
- **Route pubbliche**: aperte in lettura/scrittura limitata (usate dal frontend magazine, che invia comunque il nonce `wp_rest`), espongono solo dati pubblicabili.

### Route admin (`manage_options`)

| Metodo | Endpoint | Descrizione |
|---|---|---|
| GET | `/agents` | Lista dei 7 agenti (nome, emoji, categoria, personalità, stato, last_run) |
| POST | `/agents/{id}/status` | Cambia stato agente: body `{ "status": "active\|paused\|error" }` |
| POST | `/agents/{id}/model` | Imposta il modello AI dedicato dell'agente (v1.0.3): body `{ "model": "openai/gpt-4o-mini" }` (stringa vuota = torna al modello globale) |
| GET | `/articles` | Lista articoli; filtri `status`, `category`, `agent_id`, `search`, `page`, `per_page` |
| GET | `/articles/{id}` | Dettaglio articolo (inclusi log pubblicazione) |
| DELETE | `/articles/{id}` | Elimina l'articolo e i log correlati |
| POST | `/articles/{id}/approve` | Approva l'articolo: body `{ "note": "…" }` opzionale |
| POST | `/articles/{id}/reject` | Rifiuta l'articolo: body `{ "note": "…" }` |
| POST | `/articles/{id}/translate` | Traduce l'articolo con l'AI (v1.0.3, registrata da `Nexus_Translate`): body `{ "lang": "en" }` (una delle lingue in `translation_langs`); ritorna titolo e riassunto tradotti |
| POST | `/pipeline/collect` | Esegue la raccolta RSS: body `{ "agent_id": 0 }` (0 = tutti gli agenti attivi) |
| POST | `/pipeline/run` | Esegue la pipeline completa per agente o per tutti |
| POST | `/pipeline/publish` | Pubblica un singolo articolo approvato `{article_id}` |
| GET | `/settings` | Impostazioni correnti |
| PUT | `/settings` | Aggiorna le impostazioni (merge parziale sui campi in whitelist) |
| GET | `/activity` | Feed attività; args `limit`, `agent_id` |
| GET | `/stats` | Statistiche dashboard (totali, stati, views, agenti attivi, attività 24h) |
| POST | `/wire/run` | Avvia una run del Nexus Wire: body `{ "topic": "…" }` (max 200 caratteri) |
| GET | `/wire/runs` | Storico completo delle run del Wire; arg `limit` |
| GET | `/jobs` | Lavori della coda (v1.0.4): args `status`, `hook`, `page`, `per_page` (default 50, max 200) → `{ items, total, pages, stats }` |
| GET | `/jobs/stats` | Statistiche della coda: `{ pending, running, complete, failed, cancelled, total }` |
| POST | `/jobs/process` | Processa subito un batch della coda (`Nexus_Queue::run_batch()`) → `{ processed, failed }` |
| POST | `/jobs/cleanup` | Elimina i lavori finiti più vecchi di `days` giorni (default 7, 1-90) → `{ deleted }` |
| POST | `/jobs/{id}/retry` | Riprova un lavoro: solo da `failed`/`cancelled`/`complete` → `pending` |
| POST | `/jobs/{id}/cancel` | Annulla un lavoro: solo se `pending` → `cancelled` |
| GET | `/analytics` | Analytics della redazione (v1.0.4); arg `days` (default 30, cap 90) |

### Route pubbliche

| Metodo | Endpoint | Descrizione |
|---|---|---|
| GET | `/public/articles` | Solo articoli `published`; args `category`, `search`, `page`, `per_page` (max 24) |
| GET | `/public/articles/{id}` | Dettaglio articolo, solo se pubblicato |
| POST | `/public/articles/{id}/reaction` | Registra una reazione emoji: body `{ "emoji": "👍" }` |
| POST | `/public/articles/{id}/view` | Incrementa il contatore visualizzazioni |
| GET | `/public/wire/runs` | Run Wire completate, senza dati interni |
| POST | `/subscribe` | Iscrizione newsletter: body `{ "email": "…", "nonce": "…" }` — invia l'email di conferma (double opt-in); registrata da `Nexus_Newsletter` |
| POST | `/unsubscribe` | Disiscrizione newsletter: body (o query) `{ "token": "…" }`; registrata da `Nexus_Newsletter` |
| GET | `/articles/{id}/translations` | Lista delle traduzioni disponibili per un articolo, senza il contenuto completo (v1.0.3); registrata da `Nexus_Translate` |
| GET | `/health` | Stato del plugin (versione, tabelle, AI configurata) |

Esempio:

```bash
curl "https://tuosito.it/wp-json/nexus/v1/public/articles?category=Scienza&per_page=6"
```

---

## Impostazioni

Salvate nell'option `nexus_settings` (gestite da `Nexus_DB::get_settings()` / `update_settings()`, modificabili dall'admin panel o via REST). Valori uniti ai default al volo: una chiave assente ricade sul default.

| Chiave | Default | Descrizione |
|---|---|---|
| `mode` | `semi_autonomous` | Modalità pipeline: `semi_autonomous` (approvazione umana) oppure `fully_autonomous` (auto-approvazione) |
| `auto_collect` | `1` | Esegue la raccolta RSS nelle esecuzioni automatiche |
| `auto_evaluate` | `1` | Esegue la valutazione nelle esecuzioni automatiche |
| `auto_rewrite` | `1` | Esegue la riscrittura AI nelle esecuzioni automatiche |
| `auto_publish` | `0` | Pubblica automaticamente gli articoli approvati |
| `collect_interval` | `3600` | Intervallo del cron in secondi |
| `max_articles_per_day` | `10` | Limite di articoli creati al giorno (protezione anti-flood) |
| `social_platforms` | `blog,twitter,facebook,linkedin,instagram` | Piattaforme su cui la pubblicazione invia gli articoli |
| `site_name` | `Nexus News AI` | Nome del magazine mostrato nel frontend |
| `site_tagline` | `Il giornale di nuova generazione` | Tagline del magazine |
| `ai_api_key` | (vuota) | Chiave API del servizio AI OpenRouter-compatible |
| `ai_base_url` | `https://openrouter.ai/api/v1` | Base URL dell'API AI (compatibile OpenAI/OpenRouter) |
| `ai_model` | `openai/gpt-4o-mini` | Modello usato per riscrittura e Nexus Wire |
| `auto_approve_score` | `75` | Punteggio minimo per l'auto-approvazione in `fully_autonomous` |
| `enable_wp_posts` | `1` | Pubblica gli articoli approvati come post WordPress reali (CPT `nexus_article`); `0` = solo front-end Nexus legacy |
| `post_status` | `publish` | Stato dei post creati: `publish` oppure `draft` |
| `enable_ai_images` | `1` | Genera l'immagine in evidenza con l'AI (fallback: immagine dell'articolo → placeholder locale) |
| `image_model` | (vuoto) | Modello immagini AI; vuoto = modello di default del provider configurato |
| `image_size` | `1024x1024` | Dimensione delle immagini generate: `1024x1024`, `1792x1024`, `1024x1792` |
| `enable_seo` | `1` | Ottimizzazione SEO: meta description, Open Graph, JSON-LD `NewsArticle` |
| `seo_generate` | `1` | Genera meta title/description con l'AI (fallback: titolo articolo + riassunto) |
| `enable_telegram` | `0` | Abilita la pubblicazione reale su Telegram |
| `telegram_bot_token` | (vuoto) | Token del bot Telegram da @BotFather |
| `telegram_chat_id` | (vuoto) | Chat ID di destinazione dei messaggi Telegram |
| `enable_discord` | `0` | Abilita la pubblicazione reale su Discord |
| `discord_webhook_url` | (vuoto) | URL del webhook Discord del canale |
| `enable_x` | `0` | Abilita la pubblicazione reale su X (Twitter) |
| `x_api_key` | (vuoto) | API key dell'app X (OAuth 1.0a) |
| `x_api_secret` | (vuoto) | API secret dell'app X |
| `x_access_token` | (vuoto) | Access token dell'account X |
| `x_access_secret` | (vuoto) | Access secret dell'account X |
| `enable_linkedin` | `0` | Abilita la pubblicazione reale su LinkedIn |
| `linkedin_access_token` | (vuoto) | Access token LinkedIn (Bearer) |
| `linkedin_author_urn` | (vuoto) | URN dell'autore del post (es. `urn:li:person:xxx`) |
| `enable_newsletter` | `0` | Attiva la newsletter: shortcode `[nexus_subscribe]`, digest settimanale e invio agli iscritti |
| `newsletter_recipients` | (vuoto) | Email fisse destinatarie del digest, separate da virgola (gli iscritti confermati le ricevono comunque) |
| `enable_digest` | `1` | Digest settimanale con testo generato dall'AI; `0` = nessun digest |
| `enable_indexnow` | `0` | Ping IndexNow (Bing e altri) alla pubblicazione dei post |
| `indexnow_key` | (vuota) | Chiave IndexNow; generata automaticamente se vuota (key file servito dal plugin) |
| `enable_websub` | `0` | Ping WebSub (PubSubHubbub) sul feed alla pubblicazione |
| `ai_model_cheap` | (vuoto) | Modello economico per screening (raccolta), valutazione e fact-check; vuoto = usa `ai_model` |
| `enable_factcheck` | `1` | Fact-check automatico dopo la riscrittura: punteggio 0-100 e citazioni salvati sull'articolo |
| `factcheck_min_score` | `60` | Soglia minima fact-check (0-100): sotto soglia l'articolo torna in bozza |
| `monthly_budget_usd` | `0` | Budget mensile AI in USD sul costo stimato delle chiamate; `0` = nessun limite |
| `budget_action` | `stop` | Quando il budget è esaurito: `stop` = le fasi AI vengono saltate con log attività, `warn` = avviso ma la pipeline prosegue |
| `enable_translations` | `1` | Abilita le traduzioni AI degli articoli via REST (`POST /articles/{id}/translate`) |
| `translation_langs` | `en,es,fr` | Lingue di traduzione ammesse, separate da virgola (en, es, fr, de, pt, ar, zh, ja) |
| `enable_queue` | `0` | Usa la coda lavori (stile Action Scheduler): le azioni pesanti vengono accodate in `wp_nexus_jobs` ed eseguite in batch ogni 60 secondi dal runner WP-Cron |
| `queue_batch_size` | `5` | Lavori per batch del runner della coda (1-50) |
| `queue_max_attempts` | `3` | Tentativi massimi per lavoro prima di passare a `failed` (1-10) |

Nella v1.0.1 l'admin aggiunge la sezione **«Pubblicazione WordPress & SEO»** con le 7 chiavi sopra: determinano se (e come) gli articoli approvati diventano post WordPress reali, con immagine in evidenza AI e ottimizzazione SEO. Nella v1.0.2 si aggiungono tre sezioni: **«Distribuzione sociale»** (credenziali Telegram, Discord, X e LinkedIn), **«Newsletter»** (iscrizioni, destinatari, digest) e **«Indicizzazione»** (IndexNow e WebSub). Le credenziali social vivono solo nell'option `nexus_settings`: non vengono mai loggate né esposte nei messaggi di errore in chiaro. Nella v1.0.3 si aggiunge la sezione **«Redazione intelligente»** con le 7 chiavi della tabella sopra (dettagli nella sezione [Redazione intelligente](#redazione-intelligente-v103)). Nella v1.0.4 si aggiunge la sezione **«Scala & Pro»** con le 3 chiavi della coda lavori (dettagli nella sezione [Scala & Pro](#scala--pro-v104)).

---

## Cron automatico

- Il plugin registra uno **schedule WP-Cron custom** (`nexus_interval`, durata = `collect_interval` secondi) e vi pianifica l'evento **`nexus_cron_tick`**.
- Ogni tick esegue le fasi abilitate dalle impostazioni (`auto_collect`, `auto_evaluate`, `auto_rewrite`, `auto_publish`), rispettando `max_articles_per_day`; gli agenti nello stato `paused`/`error` vengono saltati.
- Se cambi `collect_interval` o `auto_collect` nelle impostazioni, l'evento viene **risincronizzato automaticamente** (hook `update_option_nexus_settings`).
- Alla **disattivazione** l'evento pianificato viene rimosso (`Nexus_Cron::deactivate()`); alla riattivazione viene ripianificato.
- Con la **newsletter attiva** (`enable_newsletter=1`) viene pianificato l'evento **`nexus_weekly_digest`** sullo schedule builtin `weekly`: al tick genera il digest AI degli articoli pubblicati negli ultimi 7 giorni e lo invia via `wp_mail` ai destinatari fissi e agli iscritti confermati.
- Alla **pubblicazione di un post** `nexus_article` viene schedulato l'evento singolo **`nexus_ping_article`** (con l'ID del post come argomento): il ping IndexNow/WebSub gira fuori banda, senza mai bloccare la pubblicazione.
- Con la **coda attiva** (`enable_queue=1`, sezione «Scala & Pro») viene pianificato l'evento **`nexus_queue_runner`** sullo schedule custom `nexus_queue_60s` (60 secondi): ogni pass processa un batch di `queue_batch_size` lavori (retry con backoff, requeue dei lavori bloccati oltre 30 minuti, pulizia periodica) e ogni tick del cron principale viene accodato come lavoro `pipeline_cron_tick`; con la coda disattivata l'evento viene rimosso e il comportamento resta quello classico.
- WP-Cron è "pigro": scatta solo con visite al sito. Per un giornale vero è consigliato un cron di sistema:
  1. definisci `define( 'DISABLE_WP_CRON', true );` in `wp-config.php`;
  2. aggiungi una riga crontab, es. ogni ora:
     ```cron
     0 * * * * curl -s https://tuosito.it/wp-cron.php?doing_wp_cron > /dev/null
     ```
  3. oppure con WP-CLI dispara il tick a mano quando serve:
     ```bash
     wp cron event run nexus_cron_tick
     ```
  4. con la **coda attiva** (`enable_queue=1`) e `DISABLE_WP_CRON`, aggiungi al crontab anche il runner della coda, es. ogni 2 minuti:
     ```cron
     */2 * * * * wp nexus queue process --path=/var/www/site/htdocs
     ```

---

## Pubblicazione multi-piattaforma

> **Nota onesta (v1.0.2):** per **blog la pubblicazione è reale** — l'articolo diventa un **post WordPress vero** (CPT `nexus_article`, permalink `home_url('/nexus/{slug}/')`, collegato al record Nexus via meta `_nexus_article_id`); solo in caso di errore (o con `enable_wp_posts=0`) si ricade sul front-end Nexus legacy con URL `home_url('/?nexus_article={id}')`. I canali **Telegram, Discord, X e LinkedIn sono reali dalla v1.0.2**: se il canale è abilitato nei settings con le proprie credenziali (sezione «Distribuzione sociale»), il publisher invia il messaggio con le API ufficiali (Bot API, webhook, OAuth 1.0a firmato in PHP senza librerie, UGC post via Bearer token) e registra nei log l'id e l'URL remoti veri; se il canale non è configurato il log registra lo stato `skipped` con nota onesta (es. «Telegram non configurato»), senza fingere invii. **Facebook, Instagram e qualunque altra piattaforma restano simulati**: post_id e URL demo dichiarati come tali («Pubblicazione simulata (demo)»). Ogni invio, reale o simulato, genera una riga in `wp_nexus_publish_logs` consultabile dall'admin, e i segreti (token/key) non compaiono mai in chiaro nei log.

---

## Redazione intelligente (v1.0.3)

Dalla v1.0.3 la pipeline diventa una redazione «intelligente»: un modello su misura per ogni agente e per ogni compito, controllo dei costi, verifica dei fatti, duplicati bloccati e traduzioni automatiche.

### Modelli AI per compito

- **Modello per-agente**: ogni agente può dichiarare il proprio modello dalla pagina Agenti o via `POST /agents/{id}/model` (stringa vuota = modello globale `ai_model`); la fase di **riscrittura** usa il modello dell'agente, le altre fasi quello configurato a livello globale o economico.
- **Modello economico** (`ai_model_cheap`): se impostato, le fasi a basso contenuto creativo — **raccolta (screening)**, **valutazione** e **fact-check** — girano su un modello più economico (es. `openai/gpt-4o-mini`), riducendo la spesa senza toccare la qualità editoriale.

### Uso AI: token, costi, budget

- Ogni chiamata AI registra in **`wp_nexus_usage_logs`** l'agente, l'azione (`collect|evaluate|rewrite|factcheck|translate|digest`), il modello, i token (prompt/completion/total) e il **costo stimato in USD** (mappa prezzi per 1M token, arrotondato a 4 decimali).
- La **dashboard admin** mostra la sezione «Uso AI»: totale chiamate, token, costo stimato e ripartizione per agente del mese corrente.
- **Budget mensile**: con `monthly_budget_usd > 0`, quando il costo stimato del mese raggiunge la soglia il plugin si comporta secondo `budget_action` — `stop`: le fasi AI della pipeline vengono saltate con log attività («Fase X saltata: budget mensile esaurito») e il tick continua con le fasi successive; `warn`: viene registrato un avviso ma la pipeline prosegue. `0` = nessun limite.

### Fact-check automatico

1. Dopo una **riscrittura** riuscita (se `enable_factcheck=1` e l'articolo ha una fonte) una chiamata AI con il modello economico valuta coerenza col testo originale, allucinazioni e dati non supportati.
2. L'esito — **punteggio 0-100**, elenco problemi e **citazioni** claim→fonte — viene salvato sull'articolo (`factcheck_score`, `citations`).
3. Se il punteggio è **sotto** `factcheck_min_score` l'articolo torna in **bozza** con log attività; sopra soglia prosegue nel flusso normale di approvazione/pubblicazione.
4. Alla pubblicazione, gli articoli con citazioni mostrano il blocco **«Fonti verificate»** nel post WordPress (claim + link alla fonte con `rel="noopener nofollow"`), con badge fact-check nell'admin (modal articolo) e nel frontend (shortcode e single).

### Anti-duplicato

In fase di **raccolta** ogni candidato viene normalizzato e hashato (md5 del titolo in minuscolo, spazi compressi, tag rimossi): se l'hash è già presente in `wp_nexus_articles.content_hash` l'item viene **saltato** con log attività «duplicato».

### Traduzioni AI multi-lingua

- Con `enable_translations=1`, `POST /nexus/v1/articles/{id}/translate` con body `{"lang":"en"}` traduce titolo, riassunto e contenuto con l'AI e salva la traduzione in **`wp_nexus_translations`** (upsert per articolo+lingua); le lingue ammesse sono quelle di `translation_langs` (default `en,es,fr`; nomi supportati: en, es, fr, de, pt, ar, zh, ja).
- `GET /nexus/v1/articles/{id}/translations` (pubblica) elenca le traduzioni disponibili senza il contenuto completo; dall'admin il modal articolo offre i bottoni «Traduci» per le lingue configurate con esito in italiano.

### Nuove strutture dati (v1.0.3)

- `wp_nexus_agents` + colonna `ai_model` (modello dedicato dell'agente).
- `wp_nexus_articles` + colonne `content_hash` (anti-duplicato, indicizzata), `factcheck_score` (0-100) e `citations` (JSON claim/source).
- Tabelle **`wp_nexus_usage_logs`** (uso AI per chiamata: agent_id, action, model, token, cost, created_at) e **`wp_nexus_translations`** (una riga per articolo+lingua, UNIQUE `article_lang`).

---

## Scala & Pro (v1.0.4)

Dalla v1.0.4 la redazione scala: le azioni pesanti possono passare da una **coda lavori** con retry automatico, la riga di comando **WP-CLI** pilota tutto da terminale e da cron di sistema, due **blocchi Gutenberg** portano articoli e newsletter nell'editor e una pagina **Analytics** misura l'intera redazione.

### Coda lavori (stile Action Scheduler)

- **Tabella `wp_nexus_jobs`**: ogni azione pesante diventa un lavoro con hook logico, argomenti JSON, priorità (più bassa = eseguita prima), numero di tentativi e stato (`pending`, `running`, `complete`, `failed`, `cancelled`).
- **Claim atomico**: il runner «prende» i lavori in attesa (pending o la cui programmazione è scaduta) con un claim atomico (`Nexus_DB::claim_jobs()`), che li passa subito a `running`: nessuna doppia esecuzione anche con più processi paralleli.
- **Retry automatico con backoff esponenziale**: se un lavoro fallisce torna in coda con riprogrammazione `60s × 2^n` (cap 1 ora) e l'errore salvato in `last_error`, fino al massimo di tentativi (`queue_max_attempts`, default 3); oltre, passa a `failed` e resta ispezionabile dalla pagina admin.
- **Requeue dei lavori bloccati**: i lavori rimasti `running` da più di 30 minuti vengono rimessi in coda con `last_error = timeout`.
- **Pulizia automatica**: periodicamente (ogni 20 batch circa) i lavori finiti (`complete`/`failed`/`cancelled`) più vecchi di 7 giorni vengono eliminati; la pulizia si può lanciare a mano dalla pagina admin o con `wp nexus queue cleanup`.
- **Attivazione**: **Impostazioni → Scala & Pro → «Usa la coda lavori»** (`enable_queue`). Con la coda attiva il runner gira ogni 60 secondi (evento WP-Cron `nexus_queue_runner`, schedule custom `nexus_queue_60s`) e processa batch da `queue_batch_size` lavori (default 5); anche il tick del cron principale viene accodato come `pipeline_cron_tick` ed eseguito dal runner. Con la coda disattivata tutto continua a girare in sincrono come nelle versioni precedenti.
- **Hook accodabili**: `pipeline_run_full`, `pipeline_collect`, `pipeline_evaluate`, `pipeline_rewrite`, `pipeline_publish`, `pipeline_wire_run`, `article_translate`, `pipeline_cron_tick` (registrati tramite il filtro `nexus_job_handlers`).
- **Pagina admin «Coda Lavori»**: 5 stat-card (in attesa, in esecuzione, completati, falliti, totale), filtro per stato, azioni **Riprova** e **Annulla** per ogni lavoro, bottone **Processa ora** (esegue subito un batch), **Pulizia (>7 giorni)** e auto-refresh ogni 15 secondi.

### WP-CLI

Il comando **`wp nexus`** (classe `Nexus_CLI`, registrata solo quando WP-CLI è attivo) pilota la redazione da terminale:

| Comando | Descrizione |
|---|---|
| `wp nexus run [--agent=<id>] [--all]` | Pipeline completa di un agente o di tutti gli agenti attivi (senza flag: errore d'uso) |
| `wp nexus collect <agent_id>` | Raccolta RSS di un agente |
| `wp nexus evaluate <article_id>` | Valutazione (punteggio di qualità) di un articolo |
| `wp nexus rewrite <article_id>` | Riscrittura AI di un articolo |
| `wp nexus publish <article_id>` | Pubblicazione multi-piattaforma di un articolo |
| `wp nexus approve <article_id> [--note=...]` | Approva un articolo, con nota opzionale |
| `wp nexus reject <article_id> [--note=...]` | Rifiuta un articolo, con nota |
| `wp nexus translate <article_id> --lang=<code>` | Traduzione AI dell'articolo (lingue da `translation_langs`) |
| `wp nexus agents` | Tabella agenti: id, nome, categoria, stato, modello, articoli |
| `wp nexus articles [--status=...] [--agent=<id>] [--limit=20]` | Tabella articoli: id, titolo, stato, agente, qualità, viste |
| `wp nexus stats` | Statistiche della redazione (chiave → valore) |
| `wp nexus queue stats` | Statistiche della coda lavori |
| `wp nexus queue process` | Processa subito un batch della coda |
| `wp nexus queue retry <id>` | Riprova un lavoro (failed/cancelled/complete → pending) |
| `wp nexus queue cancel <id>` | Annulla un lavoro in attesa (pending → cancelled) |
| `wp nexus queue cleanup [--days=7]` | Elimina i lavori finiti più vecchi di N giorni |
| `wp nexus settings get [chiave]` | Legge tutte le impostazioni o una singola chiave |
| `wp nexus settings set <chiave> <valore>` | Aggiorna una impostazione (solo chiavi esistenti; i valori numerici vengono castati) |

Esempi reali:

```bash
# Pipeline completa di tutti gli agenti ogni 6 ore, via cron di sistema
0 */6 * * * wp nexus run --all --path=/var/www/site/htdocs

# Flusso manuale su un articolo
wp nexus rewrite 123
wp nexus approve 123 --note="Titolo ok, pubblica"
wp nexus publish 123

# Coda lavori
wp nexus queue stats
wp nexus queue process
wp nexus queue retry 42
wp nexus queue cleanup --days=30

# Impostazioni
wp nexus settings get auto_publish
wp nexus settings set auto_publish 1
```

> **Nota (runner della coda):** la coda usa WP-Cron come trigger (evento `nexus_queue_runner` ogni 60 secondi). Se usi `DISABLE_WP_CRON` con un cron di sistema, aggiungi anche una riga per la coda, es. `*/2 * * * * wp nexus queue process --path=/var/www/site/htdocs` (oppure `wp cron event run nexus_queue_runner`). Con `enable_queue=1` i comandi di pipeline lanciati a mano restano sincroni: l'output mostra una nota che la coda è attiva e consiglia `wp nexus queue process`.

### Blocchi Gutenberg

Due blocchi **dinamici server-side** (categoria **widget**, preview renderizzata dal server nell'editor, nessun build step, nessun framework), alternativi agli shortcode:

- **`nexus/latest-articles`** — gli ultimi articoli pubblicati, con lo stesso motore di rendering di `[nexus_articles]`. Attributi nel pannello laterale: **Numero articoli** (1-24, default 6), **Categoria**, **Colonne** (1-4, default 3), **Mostra immagini** (on/off).
- **`nexus/subscribe-form`** — il form di iscrizione alla newsletter (equivalente di `[nexus_subscribe]`) con **titolo personalizzabile** mostrato sopra il form (default «Iscriviti alla newsletter»).

Inseriscili con il selettore blocchi dell'editor (cerca «Nexus»): la preview nell'editor è generata dal server (`ServerSideRender`), quindi mostra esattamente l'output che avrà la pagina pubblicata.

### Analytics

Nuova pagina **Analytics** nel menu **Nexus News** (tra Nexus Wire e Impostazioni), con periodo selezionabile (7, 14, 30 o 90 giorni) e dati serviti in sola lettura dalla rotta REST `GET /nexus/v1/analytics?days=N` (classe `Nexus_Analytics`, cap 90 giorni):

- **Serie giornaliera** con grafico a barre (due barre per giorno): articoli **creati** e **pubblicati** per giorno.
- **4 stat-card**: articoli creati, pubblicati, **chiamate AI** e **costo stimato** del periodo.
- **Breakdown per piattaforma di pubblicazione**: pubblicati e falliti per piattaforma (da `wp_nexus_publish_logs`).
- **Per agente**: articoli creati, pubblicati e **qualità media** (media `quality_score`).
- **Top 10 articoli per viste**.
- **Iscritti newsletter**: confermati, pending, disiscritti e nuovi nel periodo.

---

## Installazione

1. Copia la cartella `nexus-news-ai` in `wp-content/plugins/`.
2. Attiva il plugin: vengono create le tabelle `wp_nexus_agents`, `wp_nexus_articles`, `wp_nexus_publish_logs`, `wp_nexus_approval_logs`, `wp_nexus_activity_logs`, `wp_nexus_subscribers`, `wp_nexus_usage_logs`, `wp_nexus_translations`, `wp_nexus_jobs` (v1.0.4) e inseriti i 7 agenti.
3. Vai su **Nexus News → Impostazioni** e configura la chiave AI (opzionale ma consigliata) e le modalità.
4. Crea una pagina con `[nexus_magazine]`.
5. (Opzionale) Configura un cron di sistema come descritto sopra.

Requisiti: **WordPress 6.0+**, **PHP 7.4+**, MySQL/MariaDB. Nessuna estensione PHP particolare oltre a quelle standard di WP (`DOMDocument` inclusa di serie).

---

## FAQ tecniche

**Senza chiave AI il plugin funziona?**
Per tutto ciò che non produce contenuti: magazine, admin panel, coda di approvazione, pubblicazione degli articoli esistenti, cron, REST API. La pipeline invece si ferma — ogni fase (raccolta inclusa) chiama il modello — e nei log attività compare l'errore «Chiave API AI non configurata». Appena inserisci la chiave nelle impostazioni, le nuove esecuzioni partono regolarmente.

**Quale servizio AI è supportato?**
Qualsiasi endpoint **OpenAI-compatible** (`/chat/completions`): OpenRouter di default (`https://openrouter.ai/api/v1`) ma anche OpenAI, Groq, Ollama locale, ecc. — basta cambiare `ai_base_url` e `ai_model`.

**Dove sono salvati i dati?**
Nelle 9 tabelle `wp_nexus_*` con prefisso del tuo WordPress (agenti, articoli, log di pubblicazione/approvazione/attività, iscritti newsletter in `wp_nexus_subscribers`, uso AI in `wp_nexus_usage_logs`, traduzioni in `wp_nexus_translations` e — dalla v1.0.4 — lavori in coda in `wp_nexus_jobs`) + l'option `nexus_settings`. Dalla v1.0.1 gli articoli approvati diventano **anche** post WordPress reali del CPT `nexus_article` (se `enable_wp_posts` è `1`), collegati al record Nexus via meta `_nexus_article_id`: la redazione resta nel database Nexus, i post pubblicati vivono nel blog con permalink `/nexus/{slug}/`.

**La disattivazione cancella i dati?**
No: solo la disinstallazione (cancellazione del plugin) esegue `uninstall.php`, che elimina le 8 tabelle `wp_nexus_*` (incluse `wp_nexus_subscribers`, `wp_nexus_usage_logs` e `wp_nexus_translations`), le opzioni `nexus_settings`, `nexus_db_version`, `nexus_wire_runs`, i transients `nexus_rss_*`, gli eventi cron `nexus_weekly_digest` e `nexus_ping_article` e i post `nexus_article` (con i loro meta `_nexus_*`). Se vuoi conservare gli articoli, fai un backup del database prima di eliminare il plugin.

**Posso modificare gli articoli generati?**
Sì, dalla coda di approvazione prima della pubblicazione; via REST puoi approvare, rifiutare o eliminare (`POST /articles/{id}/approve`, `POST /articles/{id}/reject`, `DELETE /articles/{id}`).

**I feed RSS dove si configurano?**
Nell'admin panel, sezione agenti/impostazioni: ogni agente raccoglie dalla propria categoria. I risultati sono in cache nei transients `nexus_rss_*` per non martellare le fonti.

**È compatibile con la cache/l'object caching?**
Sì: non usa sessioni né stato globale; le risposte REST pubbliche sono cacheabili e le pagine shortcode sono output standard.

---

## Licenza e crediti

Rilasciato sotto **licenza MIT** (vedi `LICENSE`). © 2026 **byebis**.

Porting WordPress dell'app Next.js originale *Nexus News AI* di byebis: stesso spirito, stessa redazione di intelligenze artificiali, nuova casa in `wp-content/plugins`.

---

## Changelog

### 1.0.4 — «Scala & Pro»

- **Coda lavori stile Action Scheduler**: nuova classe `Nexus_Queue` e tabella `wp_nexus_jobs` con claim atomico, priorità e retry automatico con backoff esponenziale (60s × 2^n, cap 1 ora); requeue dei lavori bloccati oltre 30 minuti e pulizia automatica dei lavori finiti da più di 7 giorni; runner WP-Cron `nexus_queue_runner` (ogni 60 secondi, schedulato solo con `enable_queue=1`) con batch da `queue_batch_size` lavori; hook accodabili: `pipeline_run_full`, `pipeline_collect`, `pipeline_evaluate`, `pipeline_rewrite`, `pipeline_publish`, `pipeline_wire_run`, `article_translate`, `pipeline_cron_tick`; con la coda attiva anche il tick del cron viene accodato.
- **Pagina admin «Coda Lavori» e REST**: 5 stat-card, filtro per stato, azioni riprova/annulla per lavoro, bottone «Processa ora», pulizia manuale (>7 giorni) e auto-refresh ogni 15 secondi; nuove rotte admin `GET /jobs`, `GET /jobs/stats`, `POST /jobs/process`, `POST /jobs/cleanup`, `POST /jobs/{id}/retry`, `POST /jobs/{id}/cancel`.
- **WP-CLI**: comando `wp nexus` (run/collect/evaluate/rewrite/publish, approve/reject con nota, translate, agents, articles, stats, queue stats|process|retry|cancel|cleanup, settings get/set) per pilotare la redazione da terminale e da cron di sistema.
- **Blocchi Gutenberg**: blocchi dinamici server-side `nexus/latest-articles` (numero articoli 1-24, categoria, colonne 1-4, mostra immagini) e `nexus/subscribe-form` (titolo personalizzabile) con preview nell'editor e nessun build step; alternativa a `[nexus_articles]` e `[nexus_subscribe]`.
- **Analytics**: nuova pagina Analytics (serie giornaliera 7-90 giorni di creati/pubblicati, chiamate AI e costo stimato del periodo, breakdown per piattaforma e per agente con qualità media, top 10 articoli per viste, iscritti newsletter) alimentata da `Nexus_Analytics::overview()` via `GET /analytics`.
- **3 nuovi setting** nella sezione admin «Scala & Pro»: `enable_queue`, `queue_batch_size`, `queue_max_attempts`.

### 1.0.3 — «Redazione intelligente»

- **Modello AI per-agente**: ogni agente può avere il proprio modello (`POST /agents/{id}/model`, campo «Modello AI» nella pagina Agenti; vuoto = modello globale); la riscrittura usa il modello dell'agente, mentre **raccolta (screening), valutazione e fact-check** girano sul modello economico `ai_model_cheap`.
- **Uso AI e budget mensile**: ogni chiamata AI logga in `wp_nexus_usage_logs` i token (prompt/completion/total) e il **costo stimato in USD**; la dashboard mostra la sezione «Uso AI» (totale chiamate, token, costo, ripartizione per agente) e con `monthly_budget_usd` + `budget_action` (`stop`/`warn`) le fasi AI vengono saltate o avvisate quando il budget è esaurito.
- **Fact-check automatico**: dopo la riscrittura una chiamata AI valuta coerenza con la fonte, allucinazioni e dati non supportati, salvando `factcheck_score` (0-100) e citazioni claim→fonte; sotto `factcheck_min_score` l'articolo torna in **bozza**, sopra la soglia il post WordPress pubblica con il blocco **«Fonti verificate»** (e badge fact-check in admin e frontend).
- **Anti-duplicato via hash del titolo**: in raccolta l'hash md5 del titolo normalizzato (`content_hash`) blocca gli item già presenti con log «duplicato».
- **Traduzioni AI multi-lingua**: nuova classe `Nexus_Translate` con `POST /articles/{id}/translate` (admin) e `GET /articles/{id}/translations` (pubblica); upsert in `wp_nexus_translations`, lingue ammesse da `translation_langs` (default `en,es,fr`).
- **7 nuovi setting** nella sezione admin «Redazione intelligente»: `ai_model_cheap`, `enable_factcheck`, `factcheck_min_score`, `monthly_budget_usd`, `budget_action`, `enable_translations`, `translation_langs`.
- **Uninstall**: drop delle nuove tabelle `wp_nexus_usage_logs` e `wp_nexus_translations`.

### 1.0.2 — «Distribuzione reale»

- **Pubblicazione social reale**: Telegram (Bot API, `sendMessage`/`sendPhoto` con immagine), Discord (webhook con embed e thumbnail), X/Twitter (`POST /2/tweets` con firma OAuth 1.0a implementata in PHP, nessuna libreria) e LinkedIn (UGC post via Bearer token), con le credenziali nella nuova sezione admin «Distribuzione sociale»; i canali non configurati producono log `skipped` onesti e Facebook/Instagram restano simulati in modo dichiarato.
- **Newsletter**: shortcode `[nexus_subscribe]` con iscrizioni **double opt-in** (REST `POST /nexus/v1/subscribe` con rate limit per IP, email di conferma con link, disiscrizione via token), nuova tabella `wp_nexus_subscribers` e **digest settimanale AI** inviato via `wp_mail` ai destinatari fissi e agli iscritti confermati.
- **Ping di indicizzazione**: alla pubblicazione dei post `nexus_article` partono automaticamente i ping **IndexNow** (Bing e altri, chiave auto-generata con key file servito dal plugin) e **WebSub** (PubSubHubbub) sul feed, in un evento cron separato (`nexus_ping_article`) che non blocca mai la pubblicazione.
- **19 nuovi setting** nelle sezioni admin «Distribuzione sociale», «Newsletter» e «Indicizzazione».
- **Uninstall**: rimozione della tabella `wp_nexus_subscribers` e degli eventi cron `nexus_weekly_digest`/`nexus_ping_article`.

### 1.0.1 — «Contenuti veri»

- **Pubblicazione come post WordPress reali**: nuovo CPT `nexus_article` (permalink `/nexus/{slug}/`, categorie e tag nativi, template single dedicato, meta box «Nexus»); il publisher crea/aggiorna il post via `wp_insert_post`/`wp_update_post` e collega `wp_post_id` all'articolo Nexus, con fallback legacy `/?nexus_article={id}` in caso di errore o con `enable_wp_posts=0`.
- **Immagini in evidenza AI**: generazione della featured image con l'AI (modello e dimensione configurabili), fallback sull'immagine dell'articolo e infine placeholder SVG locale; sideload nella media library; la pubblicazione non si blocca mai se l'immagine fallisce.
- **SEO**: meta title/description generate con l'AI (fallback su titolo e riassunto), meta description + Open Graph + JSON-LD `NewsArticle` sul single `nexus_article`, title tag dedicato, compatibilità Yoast SEO e RankMath.
- **7 nuovi setting** (sezione admin «Pubblicazione WordPress & SEO»): `enable_wp_posts`, `post_status`, `enable_ai_images`, `image_model`, `image_size`, `enable_seo`, `seo_generate`.
- **Admin**: link «Post WP» su dashboard e coda di approvazione; gestione dei nuovi campi impostazioni in admin.js.
- Nota: nella **1.0.0** la pubblicazione "blog" restava sul front-end Nexus legacy (`/?nexus_article={id}`): gli articoli non diventavano `wp_posts`.

### 1.0.0

- Prima versione: porting WordPress dell'app Next.js *Nexus News AI* (7 agenti AI, pipeline editoriale, magazine con reazioni, Nexus Wire, cron automatico, REST API `nexus/v1`).
