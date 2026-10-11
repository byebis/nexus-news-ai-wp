# Nexus News AI — Plugin WordPress

![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759B?logo=wordpress&logoColor=white)
![Licenza](https://img.shields.io/badge/Licenza-MIT-green)

> Il giornale di nuova generazione, scritto da intelligenze artificiali.

**Nexus News AI** è un plugin WordPress che porta nel tuo sito una redazione autonoma: **7 agenti giornalisti AI**, ognuno specializzato in una categoria editoriale, che raccolgono notizie da fonti RSS, le valutano, le riscrivono con l'AI, le sottopongono ad approvazione e le pubblicano su più canali (blog reale, social reali su Telegram/Discord/X/LinkedIn con fallback simulato onesto per i canali non configurati, newsletter via email). Include admin panel completo, magazine frontend con reazioni, **Nexus Wire** (redazione collettiva multi-agente), ping di indicizzazione automatici, cron automatico e REST API.

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
11. [Installazione](#installazione)
12. [FAQ tecniche](#faq-tecniche)
13. [Licenza e crediti](#licenza-e-crediti)
14. [Changelog](#changelog)

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
- **Zero dipendenze da compilare**: nessun build step, nessun framework, solo PHP + JS/CSS vanilla.
- **i18n**: text domain `nexus-news-ai`, file `.pot` in `languages/`.
- **Disinstallazione pulita**: `uninstall.php` rimuove tabelle (inclusa `wp_nexus_subscribers`), opzioni, transients, eventi cron (`nexus_weekly_digest`, `nexus_ping_article`) e i post WordPress reali `nexus_article` con i loro meta `_nexus_*`.

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
| Pipeline `collect-all.ts` / `processCategoryForAgent` | `class-nexus-pipeline.php` (le 5 fasi, eseguibili anche singolarmente) |
| Framer Motion / Tailwind / shadcn/ui | CSS vanilla (`public/css/`) con animazioni native |

---

## Architettura dei file

```
nexus-news-ai/
├── nexus-news-ai.php              # Bootstrap: header plugin, costanti, require, hook attivazione/disattivazione
├── uninstall.php                  # Disinstallazione: drop tabelle wp_nexus_* (inclusa subscribers), opzioni, transients, eventi cron, post nexus_article + meta
├── readme.txt                     # Readme formato WordPress (repository.org)
├── README.md                      # Questo file
├── LICENSE                        # Licenza MIT
│
├── includes/                      # Classi core (nessun output, solo logica)
│   ├── class-nexus-db.php         # Layer dati: schema dbDelta, CRUD, settings, seed agenti, stats, drop_tables()
│   ├── class-nexus-utils.php      # Utility condivise (sanitizzazione, slug, formattazioni)
│   ├── class-nexus-rss.php        # Raccolta RSS: fetch feed, parse, cache transients nexus_rss_*
│   ├── class-nexus-ai-engine.php  # Client AI OpenRouter-compatible: chat/completions, prompt editoriali IT, JSON repair (nessun contenuto finto: senza chiave ritorna errore)
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
│   │   ├── activity.php           # Feed attività
│   │   ├── wire.php               # Nexus Wire (redazione collettiva)
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
│   ├── js/                        # admin.js: script dell'interfaccia admin (vanilla JS, consuma le REST route nexus/v1)
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
| GET | `/articles` | Lista articoli; filtri `status`, `category`, `agent_id`, `search`, `page`, `per_page` |
| GET | `/articles/{id}` | Dettaglio articolo (inclusi log pubblicazione) |
| DELETE | `/articles/{id}` | Elimina l'articolo e i log correlati |
| POST | `/articles/{id}/approve` | Approva l'articolo: body `{ "note": "…" }` opzionale |
| POST | `/articles/{id}/reject` | Rifiuta l'articolo: body `{ "note": "…" }` |
| POST | `/pipeline/collect` | Esegue la raccolta RSS: body `{ "agent_id": 0 }` (0 = tutti gli agenti attivi) |
| POST | `/pipeline/run` | Esegue la pipeline completa per agente o per tutti |
| POST | `/pipeline/publish` | Pubblica un singolo articolo approvato `{article_id}` |
| GET | `/settings` | Impostazioni correnti |
| PUT | `/settings` | Aggiorna le impostazioni (merge parziale sui campi in whitelist) |
| GET | `/activity` | Feed attività; args `limit`, `agent_id` |
| GET | `/stats` | Statistiche dashboard (totali, stati, views, agenti attivi, attività 24h) |
| POST | `/wire/run` | Avvia una run del Nexus Wire: body `{ "topic": "…" }` (max 200 caratteri) |
| GET | `/wire/runs` | Storico completo delle run del Wire; arg `limit` |

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

Nella v1.0.1 l'admin aggiunge la sezione **«Pubblicazione WordPress & SEO»** con le 7 chiavi sopra: determinano se (e come) gli articoli approvati diventano post WordPress reali, con immagine in evidenza AI e ottimizzazione SEO. Nella v1.0.2 si aggiungono tre sezioni: **«Distribuzione sociale»** (credenziali Telegram, Discord, X e LinkedIn), **«Newsletter»** (iscrizioni, destinatari, digest) e **«Indicizzazione»** (IndexNow e WebSub). Le credenziali social vivono solo nell'option `nexus_settings`: non vengono mai loggate né esposte nei messaggi di errore in chiaro.

---

## Cron automatico

- Il plugin registra uno **schedule WP-Cron custom** (`nexus_interval`, durata = `collect_interval` secondi) e vi pianifica l'evento **`nexus_cron_tick`**.
- Ogni tick esegue le fasi abilitate dalle impostazioni (`auto_collect`, `auto_evaluate`, `auto_rewrite`, `auto_publish`), rispettando `max_articles_per_day`; gli agenti nello stato `paused`/`error` vengono saltati.
- Se cambi `collect_interval` o `auto_collect` nelle impostazioni, l'evento viene **risincronizzato automaticamente** (hook `update_option_nexus_settings`).
- Alla **disattivazione** l'evento pianificato viene rimosso (`Nexus_Cron::deactivate()`); alla riattivazione viene ripianificato.
- Con la **newsletter attiva** (`enable_newsletter=1`) viene pianificato l'evento **`nexus_weekly_digest`** sullo schedule builtin `weekly`: al tick genera il digest AI degli articoli pubblicati negli ultimi 7 giorni e lo invia via `wp_mail` ai destinatari fissi e agli iscritti confermati.
- Alla **pubblicazione di un post** `nexus_article` viene schedulato l'evento singolo **`nexus_ping_article`** (con l'ID del post come argomento): il ping IndexNow/WebSub gira fuori banda, senza mai bloccare la pubblicazione.
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

---

## Pubblicazione multi-piattaforma

> **Nota onesta (v1.0.2):** per **blog la pubblicazione è reale** — l'articolo diventa un **post WordPress vero** (CPT `nexus_article`, permalink `home_url('/nexus/{slug}/')`, collegato al record Nexus via meta `_nexus_article_id`); solo in caso di errore (o con `enable_wp_posts=0`) si ricade sul front-end Nexus legacy con URL `home_url('/?nexus_article={id}')`. I canali **Telegram, Discord, X e LinkedIn sono reali dalla v1.0.2**: se il canale è abilitato nei settings con le proprie credenziali (sezione «Distribuzione sociale»), il publisher invia il messaggio con le API ufficiali (Bot API, webhook, OAuth 1.0a firmato in PHP senza librerie, UGC post via Bearer token) e registra nei log l'id e l'URL remoti veri; se il canale non è configurato il log registra lo stato `skipped` con nota onesta (es. «Telegram non configurato»), senza fingere invii. **Facebook, Instagram e qualunque altra piattaforma restano simulati**: post_id e URL demo dichiarati come tali («Pubblicazione simulata (demo)»). Ogni invio, reale o simulato, genera una riga in `wp_nexus_publish_logs` consultabile dall'admin, e i segreti (token/key) non compaiono mai in chiaro nei log.

---

## Installazione

1. Copia la cartella `nexus-news-ai` in `wp-content/plugins/`.
2. Attiva il plugin: vengono create le tabelle `wp_nexus_agents`, `wp_nexus_articles`, `wp_nexus_publish_logs`, `wp_nexus_approval_logs`, `wp_nexus_activity_logs`, `wp_nexus_subscribers` e inseriti i 7 agenti.
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
Nelle 6 tabelle `wp_nexus_*` con prefisso del tuo WordPress (agenti, articoli, log di pubblicazione/approvazione/attività e iscritti newsletter in `wp_nexus_subscribers`) + l'option `nexus_settings`. Dalla v1.0.1 gli articoli approvati diventano **anche** post WordPress reali del CPT `nexus_article` (se `enable_wp_posts` è `1`), collegati al record Nexus via meta `_nexus_article_id`: la redazione resta nel database Nexus, i post pubblicati vivono nel blog con permalink `/nexus/{slug}/`.

**La disattivazione cancella i dati?**
No: solo la disinstallazione (cancellazione del plugin) esegue `uninstall.php`, che elimina le 6 tabelle `wp_nexus_*` (inclusa `wp_nexus_subscribers`), le opzioni `nexus_settings`, `nexus_db_version`, `nexus_wire_runs`, i transients `nexus_rss_*`, gli eventi cron `nexus_weekly_digest` e `nexus_ping_article` e i post `nexus_article` (con i loro meta `_nexus_*`). Se vuoi conservare gli articoli, fai un backup del database prima di eliminare il plugin.

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
