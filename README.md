# Nexus News AI — Plugin WordPress

![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759B?logo=wordpress&logoColor=white)
![Licenza](https://img.shields.io/badge/Licenza-MIT-green)
![Versione](https://img.shields.io/badge/versione-1.1.3-blue)

> Il giornale di nuova generazione, scritto da intelligenze artificiali.

**Nexus News AI** è un plugin WordPress che porta nel tuo sito una redazione autonoma: **7 agenti giornalisti AI**, ognuno specializzato in una categoria editoriale, che raccolgono notizie da fonti RSS, le valutano, le riscrivono con l'AI, le sottopongono ad approvazione e le pubblicano su più canali (blog reale, social reali su Telegram/Discord/X/LinkedIn con fallback simulato onesto per i canali non configurati, newsletter via email). Include admin panel completo, magazine frontend con reazioni, **Nexus Wire** (redazione collettiva multi-agente), ping di indicizzazione automatici, cron automatico, REST API e — dalla 1.0.3 — la **redazione intelligente**: modello AI per-agente con modello economico per lo screening, tracking dell'uso AI (token e costo stimato in USD) con budget mensile, fact-check automatico con citazioni, anti-duplicato via hash del titolo e traduzioni AI multi-lingua. Dalla 1.0.4 aggiunge **Scala & Pro**: coda lavori stile Action Scheduler con retry automatico, comando WP-CLI `wp nexus`, blocchi Gutenberg nativi e una pagina Analytics per misurare l'intera redazione. Dalla 1.0.5 la redazione si governa e si apre: pagina **Strumenti** (informazioni di sistema, esporta/importa configurazione JSON), **API pubblica** `nexus-public/v1` con chiave `X-Api-Key` e rate limit, supporto **multisite** con installazione automatica delle tabelle e interfaccia admin **bilingue** italiano/inglese (sperimentale). Dalla 1.0.6 tutta la UI è **chiara e curata**: pannello admin chiaro di default (il tema scuro resta opzionale da Impostazioni → Interfaccia) e frontend pubblico stilizzato dal foglio light editoriale `nexus-public.css`, con empty state e dettagli curati. Dalla 1.0.7 la redazione si comanda al dettaglio (**Impostazioni & Agenti Pro**): Impostazioni riorganizzate in **4 gruppi** con sezioni ad accordion, **modelli AI caricati dal provider** con suggerimenti nei campi, **editor agenti completo** (creare, modificare, clonare), **diagnostica AI** nella pagina Strumenti e fix overflow mobile del pannello. Dalla 1.1.0 la redazione è **tutto nativo**: i contenuti nuovi pubblicano come **post WordPress nativi** (post type `post`), gli agenti firmano i post come **utenti bot** con ruolo `nexus_ai_journalist` (byline e pagina autore reali del tema), le categorie sono **categorie reali** della testata con **tag automatici deterministici**, una **migrazione 1-click** in Strumenti converte i vecchi articoli del CPT e la pubblicazione può passare per lo stato **«In attesa di revisione»** (`pending`). Dalla 1.1.1 la testata si organizza in **redazioni gerarchiche**: una tassonomia WordPress nativa (`nexus_redazione`) con redazioni madri, sub-redazioni annidate e agenti assegnati ai rami (es. Redazione Sport → Calcio, Basket e F1), pagina admin dedicata **Redazioni**, archivi pubblici `/redazione/{slug}/` col subtree incluso, colonna e filtro nativi nella lista Articoli, shortcode e API pubblica con attributo `redazione` e **capabilities per ramo** per gli editori — tutto WP nativo, zero tabelle nuove. Dalla 1.1.2 la redazione parla tutte le lingue con il **multilingua nativo**: le traduzioni AI diventano **post WordPress veri e collegati** tra loro (permalink, revisioni e SEO propri) con driver automatico **Polylang / WPML / collegamento interno Nexus** (post meta, zero tabelle nuove), switcher lingua in frontend, `hreflang` e `og:locale` automatici, pubblicazione automatica delle traduzioni dopo il publish e compatibilità totale con Polylang e WPML tramite le loro API ufficiali. Dalla 1.1.3 l'admin si pulisce e il dettaglio si legge davvero su mobile: il menu **«Nexus News AI»** del CPT legacy sparisce da solo quando non restano più articoli legacy (permalink legacy e archivio preservati, filtro per forzarlo), il modal di dettaglio non taglia più il contenuto su smartphone e coda e dettaglio guadagnano il bottone **«Vedi bozza WordPress»** verso il post nativo collegato.

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
13. [Strumenti, API pubblica, Multisite, Admin bilingue (v1.0.5)](#v105--strumenti-api-pubblica-multisite-admin-bilingue)
14. [UI chiara & curata (v1.0.6)](#v106--ui-chiara--curata)
15. [Impostazioni & Agenti Pro (v1.0.7)](#v107--impostazioni--agenti-pro)
16. [Tutto nativo (v1.1.0)](#v110--tutto-nativo)
17. [Redazioni gerarchiche (v1.1.1)](#v111--redazioni-gerarchiche)
18. [Multilingua nativo (v1.1.2)](#v112--multilingua-nativo)
19. [Pulizia menu legacy e bozza WP (v1.1.3)](#v113--pulizia-menu-legacy-e-bozza-wp)
20. [Installazione](#installazione)
21. [FAQ tecniche](#faq-tecniche)
22. [Licenza e crediti](#licenza-e-crediti)
23. [Changelog](#changelog)

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
- **Strumenti** (v1.0.5): pagina nel menu con informazioni di sistema (versioni, 9 tabelle con righe, eventi WP-Cron con prossima esecuzione, stat coda lavori), esporta configurazione JSON (avviso: contiene chiavi API e token) e importa configurazione (match agenti per categoria, sovrascrive impostazioni e agenti).
- **API pubblica** (v1.0.5): namespace REST `nexus-public/v1` con articoli pubblicati, agenti e statistiche; autenticazione header `X-Api-Key` (o `?api_key=`), rate limit 120 richieste/minuto (HTTP 429) e CORS aperto.
- **Multisite** (v1.0.5): installazione automatica delle tabelle sui nuovi siti della rete (hook `wp_initialize_site`) e comandi `wp nexus multisite status|install [--sites=1,2]`; ogni sito ha tabelle e impostazioni proprie.
- **Admin bilingue** (v1.0.5, sperimentale): lingua dell'interfaccia admin (Automatica/Italiano/English) con traduzione delle etichette principali lato PHP (viste) e lato JS (contenuti dinamici).
- **UI chiara & curata** (v1.0.6): pannello admin con **tema chiaro di default** e tema scuro opzionale (`admin_theme` in Impostazioni → Interfaccia); frontend pubblico stilizzato dal foglio **light editoriale** `public/css/nexus-public.css` (caricato solo dove shortcode/blocchi Nexus renderizzano): card articoli con griglia responsive 1/2/3 colonne, form iscrizione curato, tipografia di lettura sulla pagina articolo, empty state e dettagli curati.
- **Impostazioni & Agenti Pro** (v1.0.7): Impostazioni riorganizzate in **4 gruppi** (Pipeline editoriale, Intelligenza artificiale, Pubblicazione & distribuzione, Sito & sistema) con sezioni ad accordion e stato aperto/chiuso ricordato nel browser; **modelli AI auto-load** dal provider (endpoint compatibile OpenAI `{base_url}/models`) con lista suggerita nei campi modello (anche nell'editor agenti) e cache 12 ore; **editor agenti completo** (creare nuovi agenti, modificare tutti i campi, clonare — il clone nasce in pausa con categoria resa univoca); **diagnostica AI** in Strumenti (la chiave non viene mai mostrata) e **fix overflow mobile** (griglia agenti fluida, tabelle scrollabili, toolbar a capo, input full-width).
- **Tutto nativo** (v1.1.0): gli articoli nuovi pubblicano come **post nativi** (`post`, in home/archivio/feed del tema) invece che sul CPT `nexus_article`; ogni agente diventa un **utente bot** con ruolo `nexus_ai_journalist` (sola capability `read`, niente wp-admin) e firma i post con **byline e pagina autore reali**; la categoria dell'agente è una **categoria WP reale** (mappata su `wp_terms`) e gli articoli ricevono **tag automatici deterministici** (3-5 tag da titolo e riassunto, nessuna chiamata AI); **migrazione 1-click** in Strumenti con barra di progresso batched per convertire i vecchi articoli CPT; nuovo stato `pending` («In attesa di revisione») per `post_status`; nuove rotte REST admin `GET /wp/categories`, `POST /native/migrate`, `GET /native/migrate/status`, `POST /native/migrate/reset`. Le tabelle Nexus restano per l'operativo (coda, costi, log, iscritti).
- **Redazioni gerarchiche** (v1.1.1): ogni testata può avere più **redazioni** contemporanee con **sub-redazioni annidate** come le categorie WP (es. Redazione Sport → Calcio, Basket, F1) e gli **agenti assegnati ai termini** (term meta `_nexus_agents`); tassonomia nativa `nexus_redazione` con archivi pubblici `/redazione/{slug}/` che includono il subtree, pagina admin **Redazioni** con albero gerarchico, colonna e filtro nativi nella lista Articoli, shortcode e API pubblica con attributo `redazione`, **capabilities per ramo** (profilo utente con guardie: solo `manage_options` assegna la redazione, l'editore scoped vede solo il suo ramo), migrazione che assegna le redazioni dal suo agente, REST nativa `wp/v2/redazioni` + 4 rotte admin. Zero tabelle nuove.
- **Multilingua nativo** (v1.1.2): le traduzioni AI diventano **post WordPress collegati** (non più solo righe nella tabella storico `wp_nexus_translations`): permalink, revisioni, SEO e tema propri per ogni lingua. Driver automatico con **Polylang** o **WPML** attivi (le associazioni tra lingue usano le loro API ufficiali) oppure **collegamento interno Nexus** via post meta (`_nexus_lang`, `_nexus_trid`, `_nexus_source_post_id`) — zero tabelle nuove; nuovo shortcode **`[nexus_lang_switcher]`**, attributo `lang` per `[nexus_magazine]`/`[nexus_articles]` e parametro `lang` sull'API pubblica, **`hreflang` e `og:locale` automatici** sul single, pubblicazione automatica delle traduzioni come post dopo il publish (eventi WP-Cron sfalsati, setting `enable_autotranslate_posts`), rotte REST admin `/multilang` e card dashboard «Multilingua».
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
│   ├── class-nexus-publisher.php  # Pubblicazione: post WordPress (post NATIVI dalla v1.1.0, CPT nexus_article nelle versioni precedenti) + fallback legacy + social reali/simulati, log in publish_logs, assegnazione termini redazione dell'agente (v1.1.1), auto-translate post-publish con eventi WP-Cron sfalsati (v1.1.2)
│   ├── class-nexus-cpt.php        # v1.0.1 — CPT nexus_article: registrazione post type, tassonomie native (categorie/tag), rewrite /nexus/, template single, meta box «Nexus» (dalla v1.1.0 resta per i permalink legacy); menu admin auto-hide quando non restano articoli legacy (legacy_count + transient 5 minuti, filtro nexus_cpt_force_menu — v1.1.3)
│   ├── class-nexus-native.php     # v1.1.0 — Nexus_Native: utenti bot degli agenti (ruolo nexus_ai_journalist), categorie native (term_id), tag deterministici, migrazione 1-click CPT → post nativi (dalla v1.1.1 assegna anche i termini redazione dell'agente), letture dual-mode
│   ├── class-nexus-redazioni.php  # v1.1.1 — Nexus_Redazioni: tassonomia gerarchica nexus_redazione (parent/child come le categorie), term meta _nexus_agents, albero/agenti/contatori per ramo, filtro articoli in lista admin
│   ├── class-nexus-caps.php       # v1.1.1 — Nexus_Caps: campo profilo «Redazione assegnata» e restrizioni per ramo (map_meta_cap + parse_query, guardie manage_options)
│   ├── class-nexus-multilang.php  # v1.1.2 — Nexus_Multilang: adattatore multilingua (driver Polylang/WPML/nexus interno con meta _nexus_lang, _nexus_trid, _nexus_source_post_id), gruppi di traduzione, statistiche per lingua
│   ├── class-nexus-image.php      # v1.0.1 — Immagini in evidenza: generazione AI con fallback, sideload in media library, set_post_thumbnail
│   ├── class-nexus-seo.php        # v1.0.1 — SEO: meta AI, Open Graph, JSON-LD NewsArticle, compatibilità Yoast/RankMath, title tag; hreflang e og:locale per lingua sul single (v1.1.2)
│   ├── class-nexus-social-telegram.php # v1.0.2 — Telegram: Bot API sendMessage/sendPhoto con credenziali dai settings
│   ├── class-nexus-social-discord.php  # v1.0.2 — Discord: webhook con embed e thumbnail
│   ├── class-nexus-social-x.php        # v1.0.2 — X/Twitter: POST /2/tweets con firma OAuth 1.0a nativa (nessuna libreria)
│   ├── class-nexus-social-linkedin.php # v1.0.2 — LinkedIn: ugcPosts via Bearer token
│   ├── class-nexus-newsletter.php      # v1.0.2 — Newsletter: shortcode [nexus_subscribe], double opt-in, digest settimanale AI via wp_mail
│   ├── class-nexus-ping.php            # v1.0.2 — Ping: IndexNow (chiave + key file) e WebSub alla pubblicazione
│   ├── class-nexus-translate.php       # v1.0.3 — Traduzioni AI: rotte REST translate/translations, prompt multi-lingua, upsert in wp_nexus_translations; dalla v1.1.2 pubblica le traduzioni come post nativi collegati (translate_to_post, native_post_for_article)
│   ├── class-nexus-queue.php           # v1.0.4 — Coda lavori stile Action Scheduler: dispatch, run_batch (claim atomico, retry con backoff), retry, cancel, requeue_stale, runner WP-Cron nexus_queue_runner
│   ├── class-nexus-cli.php             # v1.0.4 — WP-CLI: comando wp nexus (run, collect, evaluate, rewrite, publish, approve, reject, translate, agents, articles, stats, queue, settings)
│   ├── class-nexus-blocks.php          # v1.0.4 — Blocchi Gutenberg server-side: nexus/latest-articles e nexus/subscribe-form
│   ├── class-nexus-analytics.php       # v1.0.4 — Analytics: overview() con serie giornaliera, costi AI, per piattaforma, per agente, top articoli, iscritti
│   ├── class-nexus-i18n.php            # v1.0.5 — Admin bilingue IT/EN: Nexus_I18n (current_lang, translate_html sulle viste, enqueue di i18n.js)
│   ├── class-nexus-i18n-dict.php       # v1.0.5 — Dizionario i18n base: nexus_i18n_default_dict() con le voci IT=>EN dell'admin
│   ├── class-nexus-i18n-dict-extra.php # v1.0.5 — Dizionario i18n esteso: nexus_i18n_extra_dict() con voci aggiuntive (caricato da Nexus_I18n)
│   ├── class-nexus-public-api.php      # v1.0.5 — API pubblica nexus-public/v1: articoli/agenti/stats con X-Api-Key, rate limit 120/min e CORS, parametro redazione (v1.1.1), parametro lang e campo lingua negli item (v1.1.2)
│   ├── class-nexus-multisite.php       # v1.0.5 — Multisite: installazione tabelle sui nuovi siti (wp_initialize_site), status(), install_all()
│   ├── class-nexus-cron.php       # WP-Cron: scheduling nexus_cron_tick, esecuzione fasi automatiche
│   ├── class-nexus-rest.php       # REST API nexus/v1: route, permessi, schema args; serialize_article espone sempre anche wp_post_status, wp_edit_url e wp_preview_url (v1.1.3)
│   └── index.php                  # Guardia "Silence is golden"
│
├── admin/                         # Back-end WordPress
│   ├── class-nexus-admin.php      # Menu admin, enqueue assets, handler, gateway alle viste
│   ├── css/                       # nexus-admin.css: stili delle pagine admin (tema chiaro di default, dark opzionale — v1.0.6)
│   ├── views/                     # Template HTML delle pagine admin
│   │   ├── dashboard.php          # Dashboard statistiche (contatori «Contenuti nativi» — v1.1.0; card «Redazioni» — v1.1.1; card «Multilingua» con articoli per lingua — v1.1.2)
│   │   ├── agents.php             # Gestione agenti (+ colonna «Redazioni» con chip agenti assegnati — v1.1.1)
│   │   ├── redazioni.php          # v1.1.1 — Redazioni: form redazione madre/sub (nome, slug, madre, agenti) + albero gerarchico con contatori e archivio nativo
│   │   ├── queue.php              # Coda di approvazione
│   │   ├── jobs.php               # v1.0.4 — Coda Lavori: stat, filtri stato, riprova/annulla, «Processa ora», pulizia, auto-refresh 15s
│   │   ├── activity.php           # Feed attività
│   │   ├── wire.php               # Nexus Wire (redazione collettiva)
│   │   ├── analytics.php          # v1.0.4 — Analytics: serie giornaliera, costi AI, per piattaforma/agente, top articoli, iscritti
│   │   ├── tools.php              # v1.0.5 — Strumenti: informazioni di sistema, esporta/importa configurazione, API pubblica (chiave), diagnostica AI (v1.0.7), migrazione contenuti nativi (v1.1.0)
│   │   └── settings.php           # Impostazioni: 4 gruppi con sezioni ad accordion (v1.0.7), opzione post_status «In attesa di revisione» (v1.1.0), sezione «Multilingua — post tradotti nativi» con driver, lingue del sito e auto-translate (v1.1.2)
│   └── index.php
│
├── public/                        # Front-end magazine
│   ├── class-nexus-shortcodes.php # [nexus_magazine], [nexus_articles], [nexus_wire] + render + enqueue, attributo redazione (v1.1.1), attributo lang e [nexus_lang_switcher] (v1.1.2)
│   ├── css/                       # nexus-public.css: stili light editoriali del frontend (token --nxp-*, classi nxa-*/nexus-* — v1.0.6)
│   ├── js/                        # nexus-public.js: interazioni (modal, reazioni, categorie) via REST + nonce wp_rest
│   └── index.php
│
├── assets/                        # Asset condivisi admin
│   ├── js/                        # admin.js (interfaccia admin: riga «Pubblica come post» e chips delle traduzioni per lingua nell'articolo — v1.1.2; modal dettaglio mobile-safe e link «Vedi bozza WordPress» — v1.1.3) + blocks.js (v1.0.4: registrazione blocchi Gutenberg nell'editor) + i18n.js (v1.0.5: traduzione IT→EN dei contenuti dinamici admin) — vanilla JS, consuma le REST route nexus/v1
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

Gli agenti vengono inseriti automaticamente alla prima attivazione (solo se la tabella è vuota) e sono modificabili dall'admin panel: dalla **1.0.7** si gestiscono dall'editor della pagina Agenti — si creano nuovi agenti, si modificano tutti i campi e si clonano quelli esistenti (il clone nasce in pausa). Dettagli nella sezione [Impostazioni & Agenti Pro](#v107--impostazioni--agenti-pro). Dalla **1.1.0** ogni agente ha anche un utente bot WordPress con ruolo `nexus_ai_journalist` che firma i suoi post: dettagli nella sezione [Tutto nativo](#v110--tutto-nativo).

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
5. **Pubblicazione multi-piattaforma** — l'articolo approvato viene inviato alle piattaforme abilitate in `social_platforms`: per **blog la pubblicazione è reale** (dalla v1.0.1 l'articolo diventa un post WordPress; **dalla v1.1.0 è un post nativo** `post` con autore bot, categoria nativa e tag deterministici, mentre i vecchi articoli restano serviti come CPT `nexus_article` con permalink `/nexus/{slug}/`; in fallback resta nel database Nexus con URL legacy `/?nexus_article={id}` registrato nei log), i canali **Telegram, Discord, X e LinkedIn sono reali dalla v1.0.2** quando abilitati e configurati nei settings (altrimenti log `skipped` con nota onesta), gli altri canali social restano **simulati in modo dichiarato** (post_id e URL demo) e ogni invio genera una riga in `wp_nexus_publish_logs`.

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
| `lang` | (tutte) | codice lingua (es. `en`): con il driver multilingua interno filtra gli articoli per lingua (v1.1.2, dettagli nella sezione [Multilingua nativo](#v112--multilingua-nativo)); con Polylang/WPML il contenuto è già filtrato per lingua corrente |

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

### `[nexus_lang_switcher]`

Lo **switcher delle lingue del sito** (v1.1.2): lista delle lingue attive con nome italiano; su una pagina singola ogni voce punta al permalink della traduzione, sulle altre pagine a `home_url('/')` con query var `?lang={code}`. La lingua corrente è evidenziata (`nexus-lang-current`); le lingue senza traduzione appaiono non cliccabili con la nota onesta «Traduzione non disponibile». Dettagli nella sezione [Multilingua nativo](#v112--multilingua-nativo).

```
[nexus_lang_switcher]
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
| GET | `/agents` | Lista degli agenti (nome, emoji, categoria, personalità, stato, last_run) |
| POST | `/agents/{id}/status` | Cambia stato agente: body `{ "status": "active\|paused\|error" }` |
| POST | `/agents/{id}/model` | Imposta il modello AI dedicato dell'agente (v1.0.3): body `{ "model": "openai/gpt-4o-mini" }` (stringa vuota = torna al modello globale) |
| POST | `/agents` | Crea un nuovo agente (v1.0.7): body `{ "name", "category", "avatar", "status", "ai_model", "description", "personality" }` (nome e categoria obbligatori, categoria unica per agente) |
| PUT | `/agents/{id}/update` | Aggiorna un agente (v1.0.7): stessi campi, tutti opzionali (vengono aggiornati solo quelli presenti nel body); la categoria deve restare unica |
| POST | `/agents/{id}/clone` | Clona un agente (v1.0.7): nome «{originale} (copia)», categoria `{categoria}-copia` (poi `-copia-2`, `-copia-3`…), il clone nasce **in pausa** |
| GET | `/ai/models` | Modelli disponibili dal provider AI (v1.0.7): chiama `{ai_base_url}/models` con la chiave salvata, cache 12 ore per base URL → `{ models, count, cached }` |
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
| GET | `/export` | Esporta la configurazione (v1.0.5): `{ version, exported_at, site_url, settings, agents }`. ATTENZIONE: il file contiene chiavi API e token |
| POST | `/import` | Importa una configurazione (v1.0.5): body JSON `{ "settings": {…}, "agents": […] }`, agenti abbinati per `category` (aggiornati) o creati se mancanti → `{ settings_updated, agents_updated, agents_inserted, errors }` |
| POST | `/tools/api-key` | Genera una nuova chiave API pubblica (v1.0.5): la salva in `public_api_key` invalidando la precedente → `{ "api_key": "…" }` |
| GET | `/wp/categories` | Categorie WordPress native (v1.1.0): `{ "categories": [ { "id", "name", "slug", "count" } ] }` ordinate per nome; alimenta i suggerimenti categoria dell'editor agenti |
| POST | `/native/migrate` | Migrazione contenuti nativi (v1.1.0): converte fino a `limit` post CPT `nexus_article` in post nativi — body `{ "limit": 20 }` (1-100, default 20) → stato completo `{ remaining, done, total, agents, finished, errors }` |
| GET | `/native/migrate/status` | Stato corrente della migrazione (v1.1.0): stesso shape, senza eseguire batch |
| POST | `/native/migrate/reset` | Ripristina lo stato della migrazione (v1.1.0, uso amministrativo) e ritorna lo stato corrente |
| GET | `/redazioni` | Redazioni gerarchiche (v1.1.1): lista piatta ordinata ad albero (madri prima, per nome dentro ogni livello) → `{ "redazioni": [ { "id", "name", "slug", "parent", "depth", "agents": [ { "id", "name", "avatar" } ], "count", "count_subtree" } ] }`, dove `count` è il numero di articoli direttamente assegnati al termine e `count_subtree` gli articoli del ramo completo |
| POST | `/redazioni` | Crea una redazione (v1.1.1): body `{ "name", "parent", "agent_ids": [] }` (`parent` = term ID della madre, `0` = redazione di primo livello) → `{ "term_id", "agents" }` o errore onesto |
| POST | `/redazioni/{id}` | Aggiorna una redazione (v1.1.1): body parziale `{ "name"?, "slug"?, "parent"?, "agent_ids"? }` (solo i campi presenti; `parent` non può essere se stesso né un proprio discendente) → `{ "ok": true, "term": { "id", "name", "slug", "parent", "agents" } }` |
| DELETE | `/redazioni/{id}` | Elimina una redazione (v1.1.1): `wp_delete_term` e WordPress riparenta da solo le sub-redazioni alla madre → `{ "ok": true, "message": "…" }` |
| GET | `/multilang/info` | Driver e lingue del sito (v1.1.2): `{ "driver": "nexus\|polylang\|wpml", "default": "it", "current": "it", "langs": [ { "code": "it", "name": "Italiano", "default": true } ] }` |
| POST | `/multilang/translate-post` | Pubblica la traduzione di un articolo come post nativo collegato (v1.1.2): body `{ "article_id": 12, "lang": "en" }` → `{ "ok": true, "already": false, "lang": "en", "post_id": 123, "edit_url": "…", "permalink": "…" }` oppure errore onesto (`already=true` se il post esiste già: upsert, mai duplicati) |
| GET | `/multilang/translations-posts` | Stato traduzioni-post di un articolo (v1.1.2): arg `article_id` → `{ "article_id": 12, "source_post_id": 45, "source_lang": "it", "note": "", "translations": [ { "lang": "it", "post_id": 45, "edit_url": "…", "permalink": "…", "status": "publish", "modified": "2026-01-01T10:00:00+00:00", "is_source": true } ] }` — prima la riga sorgente (solo se `source_post_id` > 0, else `note` onesta e 200), poi le traduzioni in ordine di codice lingua; permalink valorizzato solo per i post pubblicati |

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
| `enable_wp_posts` | `1` | Pubblica gli articoli approvati come post WordPress reali (dalla v1.1.0 post **nativi** `post` con autore bot, categoria e tag; fino alla 1.0.7 CPT `nexus_article`); `0` = solo front-end Nexus legacy |
| `post_status` | `publish` | Stato dei post creati: `publish`, `draft` oppure `pending` («In attesa di revisione», v1.1.0) |
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
| `enable_autotranslate_posts` | `0` | Pubblica automaticamente le traduzioni come **post WP collegati** (v1.1.2): al publish di un articolo, per ogni lingua attiva parte un evento WP-Cron sfalsato che crea/aggiorna il post tradotto (richiede `enable_translations=1`) |
| `enable_queue` | `0` | Usa la coda lavori (stile Action Scheduler): le azioni pesanti vengono accodate in `wp_nexus_jobs` ed eseguite in batch ogni 60 secondi dal runner WP-Cron |
| `queue_batch_size` | `5` | Lavori per batch del runner della coda (1-50) |
| `queue_max_attempts` | `3` | Tentativi massimi per lavoro prima di passare a `failed` (1-10) |
| `admin_language` | `auto` | Lingua dell'interfaccia admin (v1.0.5, sperimentale): `auto` (segue la lingua del sito), `it` o `en` |
| `enable_public_api` | `0` | Attiva la REST pubblica `nexus-public/v1` (v1.0.5): articoli pubblicati, agenti e statistiche con autenticazione `X-Api-Key` |
| `public_api_key` | (vuota) | Chiave API pubblica; si genera dalla pagina **Strumenti → API pubblica** (v1.0.5) |
| `admin_theme` | `light` | Tema dell'interfaccia admin (v1.0.6): `light` (chiaro, consigliato) oppure `dark` (aspetto notturno delle versioni precedenti); selezione in Impostazioni → Interfaccia |

Nella v1.0.1 l'admin aggiunge la sezione **«Pubblicazione WordPress & SEO»** con le 7 chiavi sopra: determinano se (e come) gli articoli approvati diventano post WordPress reali, con immagine in evidenza AI e ottimizzazione SEO. Nella v1.0.2 si aggiungono tre sezioni: **«Distribuzione sociale»** (credenziali Telegram, Discord, X e LinkedIn), **«Newsletter»** (iscrizioni, destinatari, digest) e **«Indicizzazione»** (IndexNow e WebSub). Le credenziali social vivono solo nell'option `nexus_settings`: non vengono mai loggate né esposte nei messaggi di errore in chiaro. Nella v1.0.3 si aggiunge la sezione **«Redazione intelligente»** con le 7 chiavi della tabella sopra (dettagli nella sezione [Redazione intelligente](#redazione-intelligente-v103)). Nella v1.0.4 si aggiunge la sezione **«Scala & Pro»** con le 3 chiavi della coda lavori (dettagli nella sezione [Scala & Pro](#scala--pro-v104)). Nella v1.0.5 si aggiunge la sezione **«Lingua e API pubblica»** con le 3 chiavi della tabella sopra (dettagli nella sezione [Strumenti, API pubblica, Multisite, Admin bilingue](#v105--strumenti-api-pubblica-multisite-admin-bilingue)). Nella v1.0.6 si aggiunge la sezione **«Interfaccia»** con la chiave `admin_theme` della tabella sopra: tema chiaro attivo di default, dark opzionale (dettagli nella sezione [UI chiara & curata](#v106--ui-chiara--curata)). Nella v1.0.7 nessun setting nuovo: le sezioni esistenti sono riorganizzate in **4 gruppi** (Pipeline editoriale, Intelligenza artificiale, Pubblicazione & distribuzione, Sito & sistema) con pannelli ad accordion e stato aperto/chiuso ricordato dal browser (dettagli nella sezione [Impostazioni & Agenti Pro](#v107--impostazioni--agenti-pro)). Nella v1.1.0 nessun setting nuovo: `post_status` guadagna l'opzione `pending` («In attesa di revisione») e `enable_wp_posts` pubblica ora post nativi (dettagli nella sezione [Tutto nativo](#v110--tutto-nativo)). Nella v1.1.1 nessun setting nuovo. Nella v1.1.2 arriva la chiave `enable_autotranslate_posts` nella sub-sezione **«Multilingua — post tradotti nativi»** del gruppo «Intelligenza artificiale» (dettagli nella sezione [Multilingua nativo](#v112--multilingua-nativo)).

---

## Cron automatico

- Il plugin registra uno **schedule WP-Cron custom** (`nexus_interval`, durata = `collect_interval` secondi) e vi pianifica l'evento **`nexus_cron_tick`**.
- Ogni tick esegue le fasi abilitate dalle impostazioni (`auto_collect`, `auto_evaluate`, `auto_rewrite`, `auto_publish`), rispettando `max_articles_per_day`; gli agenti nello stato `paused`/`error` vengono saltati.
- Se cambi `collect_interval` o `auto_collect` nelle impostazioni, l'evento viene **risincronizzato automaticamente** (hook `update_option_nexus_settings`).
- Alla **disattivazione** l'evento pianificato viene rimosso (`Nexus_Cron::deactivate()`); alla riattivazione viene ripianificato.
- Con la **newsletter attiva** (`enable_newsletter=1`) viene pianificato l'evento **`nexus_weekly_digest`** sullo schedule builtin `weekly`: al tick genera il digest AI degli articoli pubblicati negli ultimi 7 giorni e lo invia via `wp_mail` ai destinatari fissi e agli iscritti confermati.
- Alla **pubblicazione di un post** `nexus_article` viene schedulato l'evento singolo **`nexus_ping_article`** (con l'ID del post come argomento): il ping IndexNow/WebSub gira fuori banda, senza mai bloccare la pubblicazione.
- Con **`enable_autotranslate_posts=1`** (v1.1.2) la pubblicazione di un articolo pianifica un evento singolo **`nexus_ml_autotranslate`** per ogni lingua attiva, **sfalsato di un minuto** (`time() + 60 * i`): ogni evento pubblica la traduzione come post nativo collegato (`Nexus_Translate::translate_to_post`, vedi la sezione [Multilingua nativo](#v112--multilingua-nativo)); la schedulazione è best-effort, la pubblicazione non fallisce mai per un problema di traduzioni.
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

> **Aggiornamento (v1.1.0 — «Tutto nativo»):** per blog la pubblicazione avviene ora come **post WordPress nativo** (post type `post`, filtrabile con `nexus_native_post_type`): in home, negli archivi e nel feed del tema come qualunque articolo della redazione. Il post ha **autore bot** (utente con ruolo `nexus_ai_journalist` → byline e pagina autore reali), **categoria nativa** della testata (prima quella dell'agente) e **tag deterministici**. Il CPT `nexus_article` resta registrato per i permalink legacy `/nexus/{slug}/` degli articoli non ancora migrati e le letture sono dual-mode (`post` + `nexus_article`) finché la migrazione non è completa. Nuovo stato `pending` per passare dalla bozza alla revisione editoriale prima di pubblicare.

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

## v1.0.5 — Strumenti, API pubblica, Multisite, Admin bilingue

Dalla v1.0.5 la redazione si governa meglio e si apre al mondo: una pagina **Strumenti** per diagnostica, backup e ripristino della configurazione, una **API pubblica** con chiave per consumare articoli e statistiche da qualunque applicazione, il supporto **multisite** e un'interfaccia admin **bilingue** (italiano/inglese, sperimentale).

### Strumenti

Nuova pagina **Strumenti** nel menu **Nexus News** (tra Analytics e Impostazioni), in quattro sezioni:

- **Informazioni di sistema**: versione del plugin, di WordPress e di PHP, locale, multisite (sì/no), `memory_limit`, `max_execution_time`, stato delle **9 tabelle** `wp_nexus_*` (presenza e numero di righe per ognuna), eventi WP-Cron con la **prossima esecuzione** (`nexus_cron_tick`, `nexus_queue_runner`, `nexus_weekly_digest`, `nexus_ping_article`) e statistiche della coda lavori.
- **Esporta configurazione**: scarica un file JSON (`nexus-config-AAAA-MM-GG.json`) con versione, data di esportazione, URL del sito, **impostazioni e agenti** (NON include articoli, iscritti o log).

  > **Attenzione:** il file esportato contiene **chiavi API e token**: conservalo in modo sicuro.

- **Importa configurazione**: carica un file JSON esportato; le impostazioni vengono **sovrascritte** e gli agenti vengono abbinati per **categoria** (l'agente esistente con la stessa categoria viene aggiornato, le categorie mancanti creano nuovi agenti). Esito: «Impostazioni aggiornate: N · Agenti aggiornati: N · Agenti creati: N».
- **API pubblica**: stato corrente (attiva/disattivata), chiave corrente in un campo di sola lettura e bottone **Genera nuova chiave** (crea una chiave nuova e **invalida la precedente**).

Le stesse operazioni sono disponibili via REST admin: `GET /export`, `POST /import`, `POST /tools/api-key` (vedi la tabella [Route admin](#route-admin-manage_options)).

### API pubblica (`nexus-public/v1`)

Namespace REST **`/wp-json/nexus-public/v1/`** (classe `Nexus_Public_API` in `class-nexus-public-api.php`) per consumare i contenuti del giornale da app esterne, widget o automazioni.

- **Attivazione**: **Impostazioni → Lingua e API pubblica → «Attiva API pubblica»** (`enable_public_api`).
- **Chiave**: si genera dalla pagina **Strumenti → API pubblica** e viene salvata nel setting `public_api_key`.
- **Autenticazione**: header **`X-Api-Key`** oppure query string **`?api_key=`** (da JavaScript cross-origin è la via più semplice, perché un header custom può richiedere il preflight).
- **Rate limit**: **120 richieste al minuto per chiave** (finestra fissa di 60 secondi); oltre la soglia la risposta è HTTP **429** con header `Retry-After: 60`.
- **CORS**: dopo l'autenticazione la risposta porta `Access-Control-Allow-Origin: *` — i dati sono pubblici e aperti a qualunque origine.
- **Errori**: `503` API disattivata, `401` chiave mancante o non valida, `503` chiave non configurata (va generata in Strumenti).

| Metodo | Endpoint | Descrizione |
|---|---|---|
| GET | `/articles` | Articoli `published`; args `page` (default 1), `per_page` (default 12, **max 50**), `category` → `{ items, total, page, pages }` |
| GET | `/articles/{id}` | Dettaglio di un articolo pubblicato (titolo, sottotitolo, riassunto, contenuto, categoria, agente, URL, punteggi qualità/fact-check); 404 se inesistente o non pubblicato |
| GET | `/agents` | Agenti attivi con il numero di articoli pubblicati |
| GET | `/stats` | Contatori pubblici della redazione: articoli totali, pubblicati, agenti attivi, viste |

Esempi concreti:

```bash
# Lista articoli pubblicati (paginata, filtrata per categoria)
curl -H "X-Api-Key: LA_TUA_CHIAVE" "https://tuosito.it/wp-json/nexus-public/v1/articles?page=1&per_page=12&category=Economia"

# Dettaglio di un singolo articolo
curl -H "X-Api-Key: LA_TUA_CHIAVE" "https://tuosito.it/wp-json/nexus-public/v1/articles/42"

# Statistiche pubbliche della redazione
curl -H "X-Api-Key: LA_TUA_CHIAVE" "https://tuosito.it/wp-json/nexus-public/v1/stats"
```

### Multisite

Su installazioni **WordPress multisite** (`is_multisite`) il plugin gestisce la rete:

- **Nuovo sito della rete** → installazione **automatica** delle tabelle Nexus e seed agenti all'evento `wp_initialize_site` (hook `Nexus_Multisite::on_new_site`): nessun passaggio manuale.
- **Comandi WP-CLI**:

  ```bash
  # Stato della rete: per ogni sito, tabelle presenti (n/9), agenti, impostazioni
  wp nexus multisite status

  # Installa tabelle e agenti sui siti indicati (senza --sites: tutti i siti)
  wp nexus multisite install --sites=1,2
  ```

- **Nota**: ogni sito della rete ha **tabelle e impostazioni proprie** (option `nexus_settings` separata per blog): configurazioni e chiavi restano indipendenti.

### Admin bilingue (sperimentale)

- **Impostazioni → Lingua e API pubblica → «Lingua dell'interfaccia admin»** (`admin_language`): `Automatica` (segue la lingua del sito: italiano se `get_locale()` inizia per `it`, altrimenti inglese), `Italiano`, `English`.
- **Come funziona**: un dizionario di traduzione IT→EN (base + esteso, filtrabile) applicato a due livelli — lato **PHP** sull'HTML renderizzato dalle viste admin (`Nexus_I18n::translate_html()`) e lato **JS** sui contenuti dinamici generati da admin.js (MutationObserver in `assets/js/i18n.js`). Copre le etichette principali: menu, bottoni, stati, colonne, messaggi e toast.
- **Sperimentale**: le stringhe non presenti nel dizionario restano in italiano; la lingua del frontend e dei contenuti editoriali non cambia.

---

## v1.0.6 — UI chiara & curata

Dalla v1.0.6 tutta l'interfaccia del plugin passa a un tema **chiaro e curato nei minimi dettagli**: il pannello admin abbandona il dark di default (che resta disponibile come opzione) e il frontend pubblico riceve un foglio di stile light editoriale che si adatta a qualunque tema del sito.

### Admin: tema chiaro di default, dark opzionale

- Il tema chiaro è attivo **di default dalla 1.0.6**: tutti i componenti del pannello (bottoni, badge, tabelle, grafici, modale, toast) usano superfici bianche, bordi sottili e ombre leggere.
- Il tema scuro delle versioni precedenti resta disponibile: **Impostazioni → Interfaccia → «Tema del pannello»** (setting `admin_theme`: `light` | `dark`), selezione precompilata server-side e salvata con le altre impostazioni.
- Meccanica: token CSS su `:root` = light; override su `body.nexus-theme-dark` = dark. Gli elementi flottanti (modale, toast) sono figli del body ed ereditano automaticamente il tema attivo.

### Frontend pubblico: `nexus-public.css`

- Foglio **light editoriale** (`public/css/nexus-public.css`) per magazine, griglia articoli, Nexus Wire, form newsletter e pagina articolo singolo: superfici chiare, bordi sottili, raggi 14px, ombre leggere, accenti violet/cyan. Nessuno sfondo scuro forzato: ogni componente è una superficie chiara autosufficiente che si sposa con qualunque tema.
- **Caricamento selettivo**: il foglio (handle `nexus-public`) viene messo in enqueue solo dove renderizzano gli shortcode/blocchi Nexus — `[nexus_magazine]`, `[nexus_articles]`, `[nexus_wire]`, blocco *Ultimi articoli*, blocco *Form iscrizione* — e nel template single; nessun CSS aggiuntivo sulle altre pagine.
- **Griglia articoli**: card bianche con bordo sottile e angoli 14px, immagine 16:10 con `object-fit: cover`, titolo con dimensione fluida (`clamp`), excerpt su 3 righe, meta (data · agente · tempo · fact-check) in grigio, badge categoria con tinte tenui, hover delicato (lift + ombra leggera); griglia responsive a 1 colonna su mobile, 2 colonne da 600px, 3 da 1024px, con variante `layout="list"` orizzontale da 768px.
- **Form iscrizione** (`[nexus_subscribe]` e blocco *Form iscrizione*): input e bottone in fila (incolonnati su mobile), bottone con gradiente brand `linear-gradient(135deg, #7c3aed, #06b6d4)`, messaggi di successo/errore con tinte verde/rosse chiare, label e placeholder curati, focus con anello visibile.
- **Pagina articolo** (template single): tipografia di lettura (interlinea 1.7, contenuto max 720px), meta sopra il titolo, byline agente con badge «Redazione AI», blockquote con bordo accent, immagini fluide, box «Fonte originale» e «Fonti verificate» coordinati, reazioni coerenti col modal.
- **Dettagli curati**: empty state con bordo tratteggiato, focus visibile ovunque (`:focus-visible`), numeri con cifre tabulari, spaziatura coerente, `prefers-reduced-motion` rispettato, contrasto AA, stili di stampa; i token del nuovo strato usano il prefisso `--nxp-*` (zero collisioni con i temi del sito), mentre le classi di markup esistenti (`nxa-*`, `nexus-single-*`, `nexus-news-*`) sono restilate in place perché fanno contratto con `nexus-public.js` e i template PHP.

---

## v1.0.7 — Impostazioni & Agenti Pro

Dalla v1.0.7 il pannello admin si comanda al dettaglio: le **Impostazioni** si leggono per gruppi con sezioni ad accordion, i **modelli AI** si caricano direttamente dal provider, gli **agenti** si creano, si modificano e si clonano da un editor dedicato, la pagina **Strumenti** guadagna la **diagnostica AI** e griglie e tabelle del pannello non traboccano più su mobile.

### Impostazioni in 4 gruppi, con accordion

- Le sezioni di **Impostazioni** sono riorganizzate in **4 gruppi**: **Pipeline editoriale** (Pipeline editoriale, Limiti e qualità), **Intelligenza artificiale** (Configurazione AI, Redazione intelligente), **Pubblicazione & distribuzione** (Pubblicazione WordPress & SEO, Pubblicazione social, Distribuzione sociale, Newsletter, Indicizzazione) e **Sito & sistema** (Identità del sito, Scala & Pro, Lingua e API pubblica, Interfaccia).
- Ogni sezione è un **pannello ad accordion**: si apre e si chiude con un click, i campi continuano a essere inviati anche con il pannello chiuso e lo **stato aperto/chiuso viene ricordato dal browser** (localStorage): tornando in pagina si ritrova ogni gruppo come lo si era lasciato.
- **Nessun setting nuovo**: nomi, default e comportamento delle chiavi esistenti non cambiano (la tabella [Impostazioni](#impostazioni) resta valida così com'è).

### Modelli AI auto-load dal provider

- In Impostazioni → Configurazione AI il bottone **«Carica modelli»** interroga il provider all'endpoint compatibile OpenAI **`{ai_base_url}/models`** con la chiave salvata e riporta quanti modelli sono disponibili; l'elenco si aggiorna anche **automaticamente all'uscita (blur) dal campo chiave**.
- I modelli diventano **suggerimenti (datalist)** nel campo «Modello globale» e nel campo «Modello AI» dell'editor agenti: si digita e si sceglie dall'elenco reale del proprio provider invece di digitare l'id a memoria.
- La risposta è **cacheata 12 ore per base URL**: le richieste ripetute non toccano il provider. Gli errori sono **onesti** e mostrati così come arrivano (chiave mancante da salvare, provider non raggiungibile, risposta HTTP in errore): mai modelli inventati.

### Editor agenti: creare, modificare, clonare

- Nella pagina **Agenti** il bottone **«Nuovo agente»** apre un editor dedicato: nome, avatar (emoji), **categoria (unica per agente)**, stato, modello AI, descrizione e personalità.
- Ogni agente esistente è **modificabile in tutti i campi** dal bottone «Modifica» sulla card; i 7 agenti predefiniti restano naturalmente editabili.
- Il bottone **«Clona»** duplica l'agente: il clone nasce con nome «{originale} (copia)», **categoria resa univoca** (`{categoria}-copia`, poi `-copia-2`, `-copia-3`…) e **stato «in pausa»**, per non partire a sorpresa con la pipeline.
- Le stesse operazioni sono disponibili via REST admin: `POST /agents`, `PUT /agents/{id}/update`, `POST /agents/{id}/clone` (vedi la tabella [Route admin](#route-admin-manage_options)).

### Diagnostica AI in Strumenti

- La pagina **Strumenti** aggiunge la card **«Diagnostica AI»**: mostra il provider configurato (URL base API o default OpenRouter), il modello globale e il modello economico e lo **stato della chiave** («Configurata» / «Mancante») — **la chiave non viene mai mostrata**, nemmeno in parte.
- Il bottone **«Testa provider AI»** chiama `GET /ai/models` e riporta l'esito: il numero di modelli esposti dal provider (con anteprima dei primi id) e la nota «dalla cache, 12h» quando la risposta arriva dalla cache; in caso di errore il messaggio del provider viene riportato così com'è.

### Fix overflow mobile

- **Griglia agenti fluida**: le card si distribuiscono con `repeat(auto-fill, minmax(...))` e si adattano a qualunque larghezza, senza overflow orizzontale né righe spezzate.
- **Tabelle scrollabili**: le tabelle di dati del pannello vivono dentro wrapper `.nxa-table-wrap` con scroll orizzontale touch su mobile, invece di spingere il layout oltre lo schermo.
- **Toolbar e campi**: le toolbar vanno a capo (flex-wrap) e input, select e textarea passano a larghezza piena (`max-width: 100%`) sotto i breakpoint WordPress (782px/600px).

---

## v1.1.0 — Tutto nativo

Dalla v1.1.0 Nexus smette di vivere in una «testata parallela»: **tutto ciò che WordPress offre già lo usiamo nativamente** (post, utenti, categorie, tag, stati editoriali) e le tabelle Nexus restano **solo per l'operativo** che WordPress non ha — coda lavori, costi AI, log, iscritti.

### Post WordPress nativi

- Gli articoli nuovi pubblicano come post del **post type nativo `post`** (filtrabile con `nexus_native_post_type`): compaiono in home, negli archivi e nel feed del tema come qualunque contenuto della redazione, con tutte le funzioni di WordPress (editor a blocchi, commenti, plugin di cache/SEO).
- Il setting `enable_wp_posts` continua a governare la pubblicazione blog; con `0` resta il fallback front-end Nexus legacy.

### Agenti come utenti bot

- Ogni agente riceve un **utente WordPress reale** (login canonico `nexus_agent_{id}`, email sul dominio del sito) con il ruolo dedicato **`nexus_ai_journalist`** («Giornalista AI», unica capability `read`): niente accesso a wp-admin, niente privilegi.
- I post pubblicati hanno l'agente come **`post_author` vero**: byline e **pagina autore** sono quelle del tema, con descrizione e personalità dell'agente nel profilo.
- Il mapping vive su `wp_nexus_agents` con le nuove colonne `wp_user_id` (utente bot) e `term_id` (categoria), aggiunte automaticamente dall'upgrade delle tabelle; ogni agente creato o modificato dall'editor viene sincronizzato subito.

### Categorie e tag nativi

- La categoria editoriale dell'agente è **agganciata a una categoria reale della testata** (`wp_terms`): creata se manca, riusata se esiste. Gli articoli pubblicano nella sezione del giornale, non più in una stringa privata del plugin.
- **Tag automatici deterministici**: nessuna chiamata AI — 3-5 tag derivati da titolo e riassunto (parole filtrate per stopwords IT/EN, ordinate per frequenza), come farebbe un redattore.

### Stato «In attesa di revisione»

- `post_status` ammette ora **`publish`**, **`draft`** e **`pending`**: il flusso WordPress completo — bozza → **revisione** → pubblicato — passa dalla revisione editoriale nativa di WordPress.

### Migrazione 1-click in Strumenti

- La pagina **Strumenti** aggiunge la card **«Migrazione contenuti nativi»**: tre contatori (post CPT ancora da migrare, agenti senza utente bot, agenti senza categoria nativa), bottone **«Avvia migrazione»**, barra di progresso e report onesto degli errori.
- La migrazione è **batched via REST**: `POST /native/migrate` con `limit` (1-100, default 20) finché la risposta riporta `finished: true`; lo stato si consulta con `GET /native/migrate/status`, il reset amministrativo è `POST /native/migrate/reset`.
- Ogni batch **prima sincronizza tutti gli agenti** (utenti bot + categorie) e poi converte i post CPT in post nativi: cambia il `post_type`, assegna autore bot e categoria nativa, **conserva** il meta di collegamento `_nexus_article_id` e lo stato WordPress del post.

### Compatibilità: il CPT resta per i permalink legacy

- Il CPT `nexus_article` **resta registrato**: i permalink `/nexus/{slug}/` e il template `templates/single-nexus_article.php` continuano a servire gli articoli non ancora migrati.
- Durante la transizione le letture sono **dual-mode** (`Nexus_Native::query_post_types()` → `post` + `nexus_article`): blocchi, shortcode, API pubblica, SEO e ping vedono entrambi i tipi, senza buchi di contenuto.

### Le tabelle Nexus restano per l'operativo

Coda lavori (`wp_nexus_jobs`), costi e uso AI (`wp_nexus_usage_logs`), log di pubblicazione/approvazione/attività (`wp_nexus_publish_logs`, `wp_nexus_approval_logs`, `wp_nexus_activity_logs`), iscritti newsletter (`wp_nexus_subscribers`), traduzioni (`wp_nexus_translations`) e il registro editoriale articoli/agenti (collegato ai post via meta `_nexus_article_id`): è lo stato operativo che WordPress non offre nativamente e resta in casa Nexus.

Le nuove rotte REST admin sono documentate nella tabella [Route admin](#route-admin-manage_options).

---

## v1.1.1 — Redazioni gerarchiche

Dalla v1.1.1 la testata si organizza in **redazioni gerarchiche** come una redazione vera: ogni testata può avere più redazioni contemporanee e ogni redazione può avere sub-redazioni annidate, con gli **agenti assegnati a ogni ramo**. La risposta architetturale è tutta WordPress nativo: una **tassonomia gerarchica** `nexus_redazione` (parent/child esattamente come le categorie) + term meta `_nexus_agents` con gli agent ID assegnati a ogni termine. **Zero tabelle nuove**: usiamo `wp_terms`, `wp_term_taxonomy`, i meta dei termini, gli archivi pubblici, la REST nativa e la colonna automatica della lista Articoli di WordPress.

### Il modello: testata → redazione → sub-redazioni → agenti

- La **testata** (il sito WordPress) definisce l'albero: redazioni madri, sub-redazioni a qualunque profondità e agenti assegnati dove servono — la madre può avere agenti propri e ogni sub-redazione i suoi.
- Esempio di Anton (richiesta di prodotto per la 1.1.1):

  ```
  Redazione Sport            (redazione madre)
    ├── Redazione Calcio     (3 agenti assegnati)
    ├── Redazione Basket     (2 agenti)
    └── Redazione F1         (1 agente)
  ```

- **Ereditarietà in lettura, non in scrittura**: ogni assegnazione di agente sta sul termine dove la mette l'editore; per il ramo completo si usa `Nexus_Redazioni::agents_for_term()`, che somma termine + discendenti (Sport = Calcio + Basket + F1 uniti).

### Tassonomia WordPress nativa: archivi `/redazione/{slug}/`

- La tassonomia `nexus_redazione` è **gerarchica, pubblica e REST-native**: esposta su `wp/v2/redazioni` (term meta `_nexus_agents` incluso, con schema) e creata automaticamente all'attivazione/aggiornamento del plugin — nessuna migrazione di schema.
- Gli archivi pubblici **`/redazione/{slug}/`** sono quelli del tema attivo (rewrite nativo, `with_front` disattivato, slug gerarchico): l'archivio di una redazione madre mostra **anche gli articoli delle sub-redazioni** (`include_children`, identico al comportamento delle categorie) — l'archivio di Sport contiene anche Calcio, Basket e F1.
- Le redazioni si vedono e si gestiscono anche dal menu **Redazioni** di WordPress e dall'editor a blocchi (quick edit incluso), come le categorie.

### Assegnazione agenti ai termini (term meta)

- Ogni termine redazione porta il term meta **`_nexus_agents`**: un array di agent ID (type `array`, `show_in_rest` con schema, sanitizzazione dedicata che accetta solo interi positivi unici).
- Quando un agente **pubblica**, il post viene tassonomizzato **automaticamente ai termini del suo agente** (`Nexus_Redazioni::assign_post_terms()`): se l'agente è assegnato a Calcio (figlia di Sport), l'articolo finisce in Calcio ed è coperto anche da Sport per archivi e gerarchia.
- Gli helper della classe `Nexus_Redazioni` (`all_terms`, `agents_for_term`, `descendant_ids`, `count_subtree`, `assign_agents`, `create`, …) sono l'API del modulo: chi vuole estendere non tocca il database.

### Colonne e filtri nativi nella lista Articoli di WordPress

- **Colonna automatica «Redazioni»** (`show_admin_column`) nella lista Articoli e nella lista legacy del CPT `nexus_article`: a colpo d'occhio si vede a quale redazione appartiene ogni articolo.
- **Dropdown nativo «Tutte le redazioni»** sopra la lista (filtro gerarchico indentato con i trattini, come le categorie): selezionando una redazione si filtrano gli articoli del **ramo completo** (subtree incluso).
- L'assegnazione si corregge a mano dall'editor a blocchi o dalla quick edit: è la tassonomia standard di WordPress.

### Shortcode e API pubblica con attributo `redazione`

- **`[nexus_articles redazione="sport"]`** e **`[nexus_magazine redazione="sport"]`**: il valore accetta slug o nome della redazione (risolto prima per slug, poi per nome) e filtra la lista sul **ramo completo** della redazione — semantica nativa `include_children`: `redazione="sport"` include Calcio, Basket e F1. Se la redazione non esiste, messaggio onesto nel markup («Redazione non trovata: …», nessun contenuto inventato).
- **Nota di coerenza sul magazine**: con il filtro `redazione` attivo `[nexus_magazine]` non stampa la barra categorie né il bottone «Carica altri» — il refetch client-side passa dalla REST pubblica senza quel parametro e mostrerebbe articoli fuori dal ramo; senza l'attributo il comportamento resta identico a prima.
- **API pubblica** `nexus-public/v1`: `GET /articles` accetta il parametro **`redazione`** (slug o nome, sanitizzato con `sanitize_title`) con la stessa semantica di subtree; con redazione valida la risposta porta il metadato `"redazione": { "id", "name", "slug" }`, con redazione inesistente la lista è vuota e `"redazione"` è `null`.

### Capabilities per ramo: editori scoped alla loro redazione

- Nel **profilo utente** compare il campo **«Redazione assegnata»** (select indentata per profondità, voce «(tutte — nessuna restrizione)»): la user meta `nexus_redazione_id` lega l'utente a un ramo.
- **Guardie**: il campo è visibile e salvabile **solo da chi ha `manage_options`** (con nonce dedicato) — un editore non può togliersi da solo la restrizione; gli amministratori non vengono mai limitati; le altre capability non vengono toccate.
- Un utente con redazione assegnata **edita ed elimina solo gli articoli del suo ramo**: il filtro `map_meta_cap` nega `edit_post`/`delete_post` per i post (nativi o CPT legacy) i cui termini redazione non intersecano il subtree dell'utente, e la lista Articoli viene forzata allo stesso subtree. Esempio: l'editore sportivo assegnato a Redazione Sport edita solo gli articoli di Sport, Calcio, Basket e F1 — gli articoli fuori dal suo ramo non li vede.
- L'albero delle redazioni si governa dalla pagina admin **Redazioni** (e dalle 4 rotte REST admin, permission `manage_options`):

  | Voce del menu `Nexus News` | Vista | Novità v1.1.1 |
  |---|---|---|
  | Dashboard | `dashboard.php` | con nuova card «Redazioni» (v1.1.1) |
  | Agenti | `agents.php` | con nuova colonna «Redazioni» (chip degli agenti assegnati, v1.1.1) |
  | **Redazioni** (v1.1.1) | `redazioni.php` | **nuova voce**, tra Agenti e Coda Approvazioni |
  | Coda Approvazioni | `queue.php` | |
  | Coda Lavori | `jobs.php` | |
  | Attività | `activity.php` | |
  | Nexus Wire | `wire.php` | |
  | Analytics | `analytics.php` | |
  | Strumenti | `tools.php` | |
  | Impostazioni | `settings.php` | |

  La pagina mostra le stat aggregate (numero di redazioni, agenti assegnati, articoli coperti), il form di creazione/modifica (nome, slug opzionale, redazione madre, checkbox degli agenti con avatar) e l'**albero gerarchico** con contatori di articoli per termine, chips degli agenti assegnati e apertura dell'archivio nativo `/redazione/{slug}/` in una nuova scheda; l'eliminazione riparenta le sub-redazioni alla madre (comportamento nativo `wp_delete_term`).

### Migrazione: ogni articolo eredita la redazione del suo agente

- La **migrazione 1-click** in Strumenti (v1.1.0) fa ora anche questo: convertendo ogni post CPT in post nativo, se l'articolo di origine ha un `agent_id` vengono assegnati al post **i termini redazione dell'agente** (assegnazione best-effort: un errore di tassonomizzazione non fa mai fallire la pubblicazione né la migrazione).
- Anche la **pipeline normale** assegna le redazioni alla pubblicazione, per post nativi e CPT legacy: le redazioni si popolano da sole man mano che gli agenti lavorano, senza configurazione extra.

### Multisite: la testata è il sito

- Come dalla v1.0.5, su **multisite** ogni sito della rete ha le sue redazioni: le tassonomie WP sono **per-sito**, quindi testata = sito e ogni testata costruisce il proprio albero (madri, sub-redazioni, agenti e permessi per ramo) indipendentemente dagli altri.

Le 4 rotte REST admin sono documentate nella tabella [Route admin](#route-admin-manage_options); la tassonomia è esposta anche nella REST nativa di WordPress su `wp/v2/redazioni`.

---

## v1.1.2 — Multilingua nativo

Dalla v1.1.2 la redazione parla tutte le lingue secondo il principio che guida il plugin dalla 1.1.0: **tutto quello che può essere nativo in WordPress lo integriamo**. Le traduzioni AI non sono più solo righe nella tabella storico `wp_nexus_translations`: diventano **post WordPress veri e propri** (post type `post`, con permalink, revisioni, immagine in evidenza, SEO e tema propri) **collegati tra loro** esattamente come fa il multilingua di WordPress. Se sul sito c'è già **Polylang** o **WPML** il plugin usa le **loro API ufficiali** (zero conflitti, zero duplicazioni); se non c'è niente, un **collegamento interno Nexus** basato su post meta tiene insieme i post — **zero tabelle nuove**. La tabella `wp_nexus_translations` resta come **storico e contatore dei costi** (la macchina che traduce), mentre il contenuto tradotto pubblicato è nativo (WordPress).

### Il modello: traduzioni come post WordPress collegati

- Ogni articolo Nexus può essere tradotto in più lingue; per ogni lingua la traduzione pubblicata è un **post nativo** (`post`) con **titolo, contenuto e riassunto tradotti dall'AI**, lo stesso stato del post sorgente, autore bot, categoria e tag nativi della testata, immagine in evidenza ereditata (best-effort) e meta di collegamento `_nexus_article_id`.
- Il post tradotto è **collegato al post sorgente** nel gruppo di traduzione della lingua: dalla pagina articolo dell'admin (riga «Pubblica come post») o via REST (`POST /multilang/translate-post`) vedi per ogni lingua se il post esiste già, lo aggiorni (upsert: nessun duplicato, `already=true`) o lo crei.
- La traduzione resta **aggiornabile**: rilanciando la traduzione per la stessa lingua il post esistente viene aggiornato (titolo, contenuto, riassunto), non duplicato; i costi AI continuano a essere tracciati come sempre in `wp_nexus_usage_logs`.

### I 3 driver: Polylang, WPML, collegamento interno Nexus

Il driver si seleziona **automaticamente** (filtrabile con il filtro `nexus_multilang_driver`) e l'API statica `Nexus_Multilang` (metodi `driver()`, `site_langs()`, `current_lang()`, `default_lang()`, `lang_of_post()`, `translations_of()`, `post_for_lang()`, `register_translation()`, `stats_counts()` e compagnia) è sempre **guard-rata**: mai fatale se il plugin di lingua manca o cambia API.

| Driver | Quando | Come collega le traduzioni |
|---|---|---|
| `polylang` | Polylang attivo | API ufficiali `pll_*`: `pll_set_post_language`, `pll_save_post_translations`, `pll_get_post_translations`, `pll_languages_list`, `pll_count_posts` |
| `wpml` | WPML attivo | Filtri WPML: `wpml_set_element_language_details`, `wpml_get_element_translations`, `wpml_active_languages`, `wpml_default_language` |
| `nexus` | Nessun plugin di lingua attivo | Collegamento interno del plugin via **post meta**: `_nexus_lang` (lingua del post), `_nexus_trid` (chiave del gruppo di traduzione, es. `nexus-g42`), `_nexus_source_post_id` (ID del post sorgente) |

Con Polylang o WPML le associazioni tra lingue sono **quelle dei plugin** (visibili e modificabili anche dalle loro UI); il meta `_nexus_trid` resta sincronizzato come traccia interna per report e query veloci, mai come verità (che sta nei driver). Il driver `nexus` è pronto a convivere con Polylang/WPML se li installerai in futuro: nessuna migrazione, le associazioni si costruiscono con le loro API al prossimo collegamento.

### Le rotte REST admin `/multilang`

Tre nuove rotte (namespace `nexus/v1`, permission `manage_options`) documentate con shape reali nella tabella [Route admin](#route-admin-manage_options):

- `GET /multilang/info` — driver attivo, lingua predefinita, lingua corrente e lingue del sito con nome italiano;
- `POST /multilang/translate-post` — pubblica la traduzione di un articolo come post nativo collegato (upsert, errori onesti);
- `GET /multilang/translations-posts?article_id=N` — stato delle traduzioni-post di un articolo: post sorgente con la sua lingua e lista delle traduzioni con ID, link modifica, permalink, stato e marca «sorgente».

Nella pagina articolo l'admin (riga **«Pubblica come post»** con i bottoni delle lingue attive e la lista dei chip con link «Modifica»/«Vedi») consuma queste rotte; se il driver è Polylang o WPML compare la nota discreta «Associazioni lingua gestite da Polylang/WPML».

### Shortcode `[nexus_lang_switcher]`

Lista delle **lingue del sito** (`Nexus_Multilang::site_langs()` con nome italiano) come switcher in frontend: su una pagina singola ogni voce punta al **permalink della traduzione** (lingue senza traduzione → voce non cliccabile con title onesto «Traduzione non disponibile»), sulle altre pagine rimanda a `home_url('/')` con query var `?lang={code}` (driver nexus: filtra i post per lingua; con Polylang/WPML attivi il comportamento dei link resta ai loro switcher se li preferisci, lo shortcode mostra comunque l'elenco delle lingue). La lingua corrente ha la classe `nexus-lang-current`.

```
[nexus_lang_switcher]
```

### Attributo `lang` per gli shortcode e parametro `lang` dell'API pubblica

- **`[nexus_articles lang="en"]`** e **`[nexus_magazine lang="en"]`**: con il driver `nexus` filtra la lista per lingua (meta query su `_nexus_lang` che include i post senza meta — sono di default della lingua del sito); con Polylang/WPML il contenuto è già filtrato per lingua corrente dai plugin stessi (attributo accettato e senza effetto). Lingua richiesta inesistente → messaggio onesto nel markup («Lingua non trovata: …», nessun contenuto inventato).
- **Nota di coerenza sul magazine**: con il filtro `lang` attivo `[nexus_magazine]` non stampa la barra categorie né il bottone «Carica altri» — il refetch client-side passa dalla REST pubblica senza quel parametro e mostrerebbe articoli fuori dal filtro; senza l'attributo il comportamento resta identico a prima.
- **API pubblica** `nexus-public/v1`: gli endpoint di listing accettano il parametro **`lang`** (stessa semantica; lingua inesistente → lista vuota onesta) e **ogni item** della risposta porta il nuovo campo **`lang`** (`Nexus_Multilang::lang_of_post`); con Polylang/WPML attivi il contenuto è già filtrato per lingua corrente dai plugin e la risposta include il campo `lang_note` onesto («contenuto già filtrato da Polylang/WPML»).

### SEO: `hreflang` e `og:locale` automatici

Sul single di un post che ha **più di una lingua pubblicata** (sono contate solo le traduzioni in stato `publish`), `Nexus_Seo` aggiunge automaticamente in `<head>`:

- `<link rel="alternate" hreflang="{code}" href="{permalink}" />` per ogni lingua, con **`x-default`** sul permalink della lingua predefinita del sito;
- **`og:locale`** del post corrente (mappa it_IT, en_US, es_ES, fr_FR, de_DE, pt_PT, ar_AR, zh_CN, ja_JP) e **`og:locale:alternate`** per le altre lingue pubblicate.

Funziona identico con tutti e tre i driver (usa `Nexus_Multilang::translations_of`, che parla già Polylang/WPML): zero lavoro extra quando i plugin di lingua sono attivi.

### Auto-translate: le traduzioni si pubblicano da sole dopo il publish

Con il setting **`enable_autotranslate_posts=1`** (Impostazioni → Intelligenza artificiale → «Multilingua — post tradotti nativi») ogni articolo pubblicato pianifica per ogni lingua attiva un **evento WP-Cron nativo** (`nexus_ml_autotranslate`, `wp_schedule_single_event`) **sfalsato di un minuto** (`time() + 60 * i`, con i progressivo): le traduzioni partono una alla volta, senza saturare il modello AI, senza toccare il cron di sistema e senza mai far fallire la pubblicazione (schedulazione best-effort). Ogni evento chiama `Nexus_Translate::translate_to_post()` e registra l'esito nei log attività; se la lingua è già stata tradotta il metodo risponde `already=true`: nessun costo AI duplicato. La schedulazione è **tutto WP**: eventi standard visibili con `wp cron event list` (o dalla pagina Strumenti), rimossi senza residui.

### Nell'admin: Impostazioni e dashboard

- **Impostazioni → Intelligenza artificiale**: sub-sezione **«Multilingua — post tradotti nativi»** con il badge del driver attivo («Polylang è attivo: le associazioni tra lingue usano Polylang.» / WPML / «Collegamento interno Nexus: nessun plugin di lingua attivo (compatibile con Polylang e WPML se li installerai).»), le lingue del sito con marcatura della lingua predefinita e la checkbox `enable_autotranslate_posts`.
- **Dashboard**: nuova card **«Multilingua»** con badge driver e i chip «{LINGUA} — N articoli» per ogni lingua con articoli pubblicati (`Nexus_Multilang::stats_counts()`); se non c'è ancora nulla, stato vuoto onesto con link alla pagina Articoli.

### Compatibilità Polylang e WPML

Se i plugin di lingua sono attivi Nexus **non crea strutture parallele**: le lingue del sito, la lingua corrente e le associazioni tra traduzioni passano dalle **API ufficiali** (`pll_*` per Polylang, i filtri `wpml_*` per WPML); i post tradotti compaiono nelle UI di Polylang/WPML come qualunque traduzione, e gli switcher, gli archivi per lingua e i filtri di quei plugin continuano a funzionare sui post di Nexus (sono post normali). Attivare Polylang o WPML **dopo** aver già tradotto col driver interno non richiede nulla: le associazioni si costruiscono con le loro API alla prossima registrazione di una traduzione.

### Disinstallazione: i post tradotti restano

Le traduzioni sono **contenuti nativi WordPress** (post `post` con i loro meta): alla disinstallazione del plugin **non vengono cancellati** — sopravvivono come i post e le categorie della testata. Il flusso di disinstallazione esistente rimuove tabelle, opzioni, transients, eventi cron e i post del CPT legacy `nexus_article`: i post tradotti nativi non ne fanno parte.

---

## v1.1.3 — Pulizia menu legacy e bozza WP

Dalla 1.1.3 l'admin si pulisce e la lettura su mobile migliora: la voce di menu **«Nexus News AI»** (quella del CPT `nexus_article`) sparisce da sola quando non restano più articoli legacy da gestire, il modal di dettaglio articolo non taglia più il contenuto su smartphone e coda di approvazione e dettaglio guadagnano il link **«Vedi bozza WordPress»** per aprire il post nativo collegato. Nessuna migrazione di schema e nessun setting nuovo: l'aggiornamento è tutto WordPress.

### Il menu «Nexus News AI» sparisce da solo quando non restano articoli legacy

- Dalla 1.1.0 i contenuti nuovi pubblicano come **post nativi** (`post`) e il CPT `nexus_article` ospita solo gli **articoli legacy** creati prima della 1.1.0. Dalla 1.1.3, quando il conteggio degli articoli legacy arriva a **0** — tipicamente dopo la migrazione 1-click in **Strumenti → «Migrazione contenuti nativi»** — il CPT viene registrato con `show_in_menu => false`: la voce **«Nexus News AI» sparisce dal menu admin** e l'admin resta pulito.
- Il conteggio (`Nexus_Cpt::legacy_count()`) conta i post del CPT **esclusi cestino e auto-draft** con una query protetta (`$wpdb->prepare`) e vive nel **transient `nexus_cpt_legacy_count` con TTL di 5 minuti** (300 secondi): la registrazione del CPT gira ad ogni richiesta e non deve interrogare il database a ogni load. Il transient è invalidato sugli **hook core `save_post`, `transition_post_status` e `deleted_post`** — la migrazione cambia `post_type` via `wp_update_post` e NON lancia `do_action` custom, quindi gli hook core sono l'unico segnale affidabile: appena converti, elimini o cesti un articolo legacy il conteggio si aggiorna (cache invalidata o scaduta) e al load successivo il menu si allinea.
- **Il CPT resta registrato**: sparire dal menu tocca solo l'admin — i **permalink legacy** `/?nexus_article={id}` e l'**archivio pubblico** `/nexus/` continuano a funzionare esattamente come prima.
- **Filtro `nexus_cpt_force_menu`**: applicato prima del confronto con il conteggio e con priorità su di esso. `add_filter( 'nexus_cpt_force_menu', '__return_true' )` tiene il menu **sempre visibile** anche senza legacy, `__return_false` lo nasconde **sempre**; senza filtro (default `null`) vale il comportamento automatico da `legacy_count()`.

### Il dettaglio articolo non taglia più il contenuto su mobile

Il bug (screenshot su Chrome Android): con un contenuto più alto del viewport il modal centrato verticalmente (`align-items:center` + `max-height:85vh`) tagliava sopra e sotto e, su mobile, l'altezza `vh` che ignora le barre dinamiche del browser rendeva il fondo del contenuto irraggiungibile; le righe lunghe (URL, titoli lunghi) venivano tagliate a destra. Il blocco CSS iniettato da admin.js ora:

- **`.nxa-modal`**: `align-items:flex-start` (il box parte dall'alto e il centro flessibile non taglia più nulla) con `overflow-y:auto` e `-webkit-overflow-scrolling:touch` per lo scorrimento fluido; padding ridotto a 16px con 32px in fondo.
- **`.nxa-modal__box`**: `max-height` con **doppia dichiarazione** — prima `calc(100vh - 32px)` (fallback per i browser senza supporto) poi `calc(100dvh - 32px)`, che vince dove supportato: con le **unità `dvh`** l'altezza tiene conto delle barre dinamiche di mobile e il fondo del contenuto è raggiungibile; `overflow:auto` resta sulla box, quindi la **testa sticky** continua a funzionare (il contenitore di scroll non cambia).
- **`.nxa-modal__body`**: `overflow-wrap:anywhere` + `word-break:break-word` — URL e righe lunghissime vanno a capo invece di uscire dal bordo destro.
- **Nuova `@media (max-width:600px)`**: padding 8px (20px in fondo), box `max-height:calc(100vh - 16px)` con fallback `dvh` e border-radius 10px, body 14px, testa 12px 14px e titolo a 15px: su smartphone il dettaglio respira e si legge tutto.

### Il bottone «Vedi bozza WordPress» in coda e nel dettaglio

- Accanto al badge «Post WP» — in **coda di approvazione** e nella riga di chip del **modal di dettaglio** — compare ora il link **«Vedi bozza WordPress»** (helper `wpViewLink`): apre il post WordPress nativo collegato all'articolo nel contesto giusto, in una nuova scheda (`target="_blank" rel="noopener noreferrer"`, classe `.nxa-wp-view`).
- **Comportamento**: post **pubblicato** (`wp_post_status` = `publish`) → «Vedi in WordPress» sul permalink pubblico; post **in bozza o pending** → «Vedi bozza WordPress» sull'URL di **anteprima** (`wp_preview_url`) o, se assente, sull'**editor** (`wp_edit_url`); come ultima spiaggia il permalink o l'editor se esistono. Se non c'è alcun link utile — o l'articolo è **legacy senza post WP** (`wp_post_id` assente o 0) — il bottone non compare.
- **Shape REST estesa, retrocompatibile**: `Nexus_Rest::serialize_article()` — l'unica shape di tutti gli endpoint che restituiscono articoli (admin e pubblico) — include **sempre** anche i tre nuovi campi **`wp_post_status`** (stato del post WP via `get_post_status`, stringa vuota se il post non esiste), **`wp_edit_url`** (link editor `post.php?post={id}&action=edit`, valorizzato solo se l'utente corrente può modificare il post) e **`wp_preview_url`** (link anteprima `get_preview_post_link` con fallback `?p={id}&preview=true`, solo per i post **non pubblicati**: per i pubblicati c'è già `post_permalink`). Chi consuma oggi i campi `wp_post_id` e `post_permalink` non deve cambiare nulla: i campi nuovi si aggiungono, non sostituiscono.

---

## Installazione

1. Copia la cartella `nexus-news-ai` in `wp-content/plugins/`.
2. Attiva il plugin: vengono create le tabelle `wp_nexus_agents`, `wp_nexus_articles`, `wp_nexus_publish_logs`, `wp_nexus_approval_logs`, `wp_nexus_activity_logs`, `wp_nexus_subscribers`, `wp_nexus_usage_logs`, `wp_nexus_translations`, `wp_nexus_jobs` (v1.0.4) e inseriti i 7 agenti; dalla 1.1.0 viene registrato anche il ruolo «Giornalista AI» per gli utenti bot degli agenti.
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
Nelle 9 tabelle `wp_nexus_*` con prefisso del tuo WordPress (agenti, articoli, log di pubblicazione/approvazione/attività, iscritti newsletter in `wp_nexus_subscribers`, uso AI in `wp_nexus_usage_logs`, traduzioni in `wp_nexus_translations` e — dalla v1.0.4 — lavori in coda in `wp_nexus_jobs`) + l'option `nexus_settings`. Dalla v1.0.1 gli articoli approvati diventano **anche** post WordPress reali del CPT `nexus_article` (se `enable_wp_posts` è `1`), collegati al record Nexus via meta `_nexus_article_id`: la redazione resta nel database Nexus, i post pubblicati vivono nel blog con permalink `/nexus/{slug}/`. Dalla v1.1.0 i contenuti nuovi pubblicano come **post nativi** `post` (sezione [Tutto nativo](#v110--tutto-nativo)).

**La disattivazione cancella i dati?**
No: solo la disinstallazione (cancellazione del plugin) esegue `uninstall.php`, che elimina le 8 tabelle `wp_nexus_*` (incluse `wp_nexus_subscribers`, `wp_nexus_usage_logs` e `wp_nexus_translations`), le opzioni `nexus_settings`, `nexus_db_version`, `nexus_wire_runs`, i transients `nexus_rss_*`, gli eventi cron `nexus_weekly_digest` e `nexus_ping_article` e i post `nexus_article` (con i loro meta `_nexus_*`). I **post tradotti nativi** (v1.1.2) **non vengono cancellati**: sono post WordPress veri, contenuti nativi che sopravvivono al plugin. Se vuoi conservare gli articoli, fai un backup del database prima di eliminare il plugin.

**Posso modificare gli articoli generati?**
Sì, dalla coda di approvazione prima della pubblicazione; via REST puoi approvare, rifiutare o eliminare (`POST /articles/{id}/approve`, `POST /articles/{id}/reject`, `DELETE /articles/{id}`).

**I feed RSS dove si configurano?**
Nell'admin panel, sezione agenti/impostazioni: ogni agente raccoglie dalla propria categoria. I risultati sono in cache nei transients `nexus_rss_*` per non martellare le fonti.

**È compatibile con la cache/l'object caching?**
Sì: non usa sessioni né stato globale; le risposte REST pubbliche sono cacheabili e le pagine shortcode sono output standard.

**Cosa cambia con la v1.1.0 «Tutto nativo»?**
I contenuti nuovi pubblicano come **post WordPress nativi** (post type `post`) invece che sul CPT `nexus_article`: home, archivi e feed del tema li trattano come qualunque post della redazione, con categoria reale della testata e tag deterministici derivati da titolo e riassunto (nessuna chiamata AI). Ogni agente firma i propri post come **utente bot** (ruolo `nexus_ai_journalist`, unica capability `read`: nessun accesso a wp-admin) e la byline e la pagina autore sono quelle del tema. Le tabelle Nexus restano per l'operativo (coda lavori, costi AI, log, iscritti) e il registro editoriale resta collegato ai post via meta `_nexus_article_id`.

**Come converto i vecchi articoli del CPT?**
Con la **migrazione 1-click** in **Strumenti → «Migrazione contenuti nativi»**: la card mostra quanti post CPT restano da migrare e gli agenti privi di utente bot o categoria nativa; il bottone «Avvia migrazione» converte i post a batch (autore bot, categoria nativa, meta di collegamento e stato WordPress conservati) con barra di progresso e report onesto degli errori. Via REST: `POST /native/migrate` (batch, `limit` 1-100, default 20), `GET /native/migrate/status` per lo stato e `POST /native/migrate/reset` (uso amministrativo). Gli articoli non migrati continuano a funzionare con i permalink legacy `/nexus/{slug}/` e tutte le letture sono dual-mode (`post` + `nexus_article`).

---

## Licenza e crediti

Rilasciato sotto **licenza MIT** (vedi `LICENSE`). © 2026 **byebis**.

Porting WordPress dell'app Next.js originale *Nexus News AI* di byebis: stesso spirito, stessa redazione di intelligenze artificiali, nuova casa in `wp-content/plugins`.

---

## Changelog

### 1.1.3 — Pulizia menu legacy e bozza WP
- Overlay del modal dettaglio ora scrollabile e allineato in alto anche con il foglio stile admin reale (regola role=dialog) e pannelli con margini automatici: nessun fondo irraggiungibile su mobile

- **Menu CPT auto-hide**: quando non restano più articoli legacy nel CPT `nexus_article` (conteggio esclusi cestino/auto-draft in transient 5 minuti, invalidato sugli hook core `save_post`/`transition_post_status`/`deleted_post`) la voce «Nexus News AI» sparisce dal menu admin; il post type resta registrato e i permalink legacy `/?nexus_article={id}` e l'archivio `/nexus/` continuano a funzionare; filtro `nexus_cpt_force_menu` per tenere il menu sempre visibile o sempre nascosto.
- **Fix overflow del dettaglio su mobile**: modal allineato in alto (`align-items:flex-start`) con scorrimento proprio e padding ridotto, `max-height` in `dvh` con fallback `vh` sulla box (testa sticky intatta), `overflow-wrap:anywhere` + `word-break:break-word` nel body e nuova media query 600px compatta: niente più contenuto tagliato sopra/sotto o oltre il bordo destro (Chrome Android).
- **Bottone «Vedi bozza WordPress»** in coda di approvazione e nel modal di dettaglio (helper `wpViewLink` accanto al badge «Post WP»): post pubblicato → «Vedi in WordPress» sul permalink, bozza/pending → «Vedi bozza WordPress» sull'anteprima o sull'editor; gli articoli legacy senza post WP non mostrano il bottone.
- **Shape REST estesa, retrocompatibile**: `serialize_article` (unica shape di tutti gli endpoint articoli) espone sempre anche `wp_post_status`, `wp_edit_url` (valorizzato solo se l'utente corrente può modificare il post) e `wp_preview_url` (solo per i post non pubblicati, `get_preview_post_link` con fallback `?p={id}&preview=true`), stringa vuota quando il dato non è disponibile.
- **Nessuna migrazione di schema e nessun setting nuovo**: conteggio legacy + transient + filtro nel CPT, CSS/JS dell'admin e tre campi REST in più; l'aggiornamento è tutto WordPress.

### 1.1.2 — Multilingua nativo

- **Traduzioni come post WordPress collegati**: `Nexus_Translate::translate_to_post()` pubblica la traduzione AI di un articolo come **post nativo** `post` (titolo, contenuto e riassunto tradotti, stato del sorgente, autore bot, categoria e tag nativi, immagine in evidenza ereditata) collegato al gruppo di traduzione della lingua; upsert sempre: il post esiste già? viene aggiornato (`already=true`), mai duplicato.
- **3 driver automatici**: **Polylang** (API `pll_*`) e **WPML** (`wpml_set_element_language_details` e compagnia) quando attivi — le associazioni tra lingue usano le loro API ufficiali — oppure **collegamento interno Nexus** via post meta `_nexus_lang`, `_nexus_trid` e `_nexus_source_post_id`: zero tabelle nuove, classe `Nexus_Multilang` guard-rata mai fatale.
- **3 nuove rotte REST admin**: `GET /multilang/info` (driver, lingua predefinita, lingua corrente, lingue del sito con nome), `POST /multilang/translate-post` (pubblica la traduzione come post collegato con `edit_url` e `permalink`), `GET /multilang/translations-posts` (stato per articolo: sorgente, lingua e lista traduzioni con stato e link); permission `manage_options`.
- **Switcher e filtri lingua in frontend**: nuovo shortcode **`[nexus_lang_switcher]`** (lingue del sito, permalink della traduzione per lingua, lingue senza traduzione non cliccabili con nota onesta), attributo `lang` per `[nexus_articles]` e `[nexus_magazine]`, parametro `lang` sull'API pubblica `nexus-public/v1` con nuovo campo `lang` su ogni item.
- **SEO multilingua**: sul single con più di una lingua pubblicata `hreflang` per ogni lingua (con `x-default` sulla lingua predefinita) e `og:locale` / `og:locale:alternate` automatici.
- **Auto-translate post-publish**: setting `enable_autotranslate_posts` — al publish di un articolo ogni lingua attiva riceve un evento WP-Cron nativo `nexus_ml_autotranslate` **sfalsato di un minuto** che pubblica la traduzione come post collegato (best-effort, `already=true` se già tradotta: nessun costo duplicato); card dashboard «Multilingua» con articoli per lingua.
- **Nessuna migrazione di schema**: le traduzioni sono post nativi + meta (l'aggiornamento è tutto WordPress); la tabella `wp_nexus_translations` resta come storico/costi; alla disinstallazione i post tradotti **restano** (contenuti nativi, sopravvivono al plugin).

### 1.1.1 — «Redazioni gerarchiche»

- **Tassonomia nativa `nexus_redazione`**: redazioni gerarchiche parent/child esattamente come le categorie (Redazione Sport → Calcio, Basket, F1), archivi pubblici `/redazione/{slug}/` col tema attivo con subtree incluso (`include_children`), REST nativa `wp/v2/redazioni` con term meta esposto e colonna automatica nella lista Articoli; zero tabelle nuove, nessuna migrazione di schema.
- **Agenti assegnati ai termini**: term meta `_nexus_agents` (array di agent ID); alla pubblicazione il post riceve automaticamente i termini redazione del suo agente — in pipeline (post nativi e CPT legacy) e durante la migrazione 1-click.
- **Pagina admin «Redazioni»**: nuova voce nel menu tra Agenti e Coda Approvazioni, con stat aggregate, form madre/sub-redazioni (nome, slug, madre, checkbox agenti), albero gerarchico con chips agenti, contatori per ramo e apertura dell'archivio nativo.
- **4 nuove rotte REST admin**: `GET /redazioni` (lista ad albero con agenti, count e count_subtree), `POST /redazioni` (creazione), `POST /redazioni/{id}` (aggiornamento, con validazione parent contro cicli), `DELETE /redazioni/{id}` (eliminazione con riparenting nativo delle sub-redazioni); tutte con permission `manage_options`.
- **Colonne e filtri nativi in Articoli**: colonna automatica «Redazioni» e dropdown gerarchico «Tutte le redazioni» con filtro per ramo completo (post nativi e CPT `nexus_article` legacy), correggibile da editor a blocchi e quick edit.
- **Shortcode e API pubblica con attributo `redazione`**: `[nexus_articles redazione="sport"]` e `[nexus_magazine redazione="…"]` (slug o nome, subtree incluso) e parametro `redazione` sugli endpoint di listing di `nexus-public/v1`.
- **Capabilities per ramo**: campo profilo «Redazione assegnata» (user meta `nexus_redazione_id`) visibile e salvabile solo da `manage_options`; un editore scoped edita solo gli articoli del suo ramo (`map_meta_cap` + filtro lista Articoli); su multisite ogni sito (testata) ha le sue redazioni.

### 1.1.0 — «Tutto nativo»

- **Post WordPress nativi**: gli articoli nuovi pubblicano come post del post type nativo `post` (filtrabile con `nexus_native_post_type`) invece che sul CPT `nexus_article`: home, archivi e feed del tema li trattano come qualunque contenuto della redazione; il CPT resta registrato per i permalink legacy `/nexus/{slug}/` e le letture sono dual-mode (`post` + `nexus_article`) durante la transizione.
- **Agenti come utenti bot**: ogni agente riceve un utente WordPress reale (login `nexus_agent_{id}`) con ruolo dedicato `nexus_ai_journalist` («Giornalista AI», unica capability `read`, nessun accesso a wp-admin) e firma i propri post come `post_author`: byline e pagina autore reali del tema; mapping su `wp_nexus_agents` con le nuove colonne `wp_user_id`/`term_id` (upgrade automatico delle tabelle al bump di versione).
- **Categorie e tag nativi**: la categoria dell'agente è agganciata a una categoria reale della testata (creata se manca) e gli articoli ricevono tag automatici deterministici (3-5 tag da titolo e riassunto, stopwords IT/EN filtrate, nessuna chiamata AI).
- **Migrazione 1-click**: nuova card «Migrazione contenuti nativi» in Strumenti con contatori (post CPT residui, agenti senza utente bot/categoria), bottone «Avvia migrazione», barra di progresso e report onesto; conversione batched via REST (autore bot + categoria nativa, meta `_nexus_article_id` e stato WP conservati).
- **Nuovo stato `pending`**: `post_status` ammette ora `publish` | `draft` | `pending` («In attesa di revisione»: flusso WP bozza → revisione → pubblicato).
- **Nuove rotte REST admin**: `GET /wp/categories` (categorie native), `POST /native/migrate` (batch di migrazione, `limit` 1-100 default 20), `GET /native/migrate/status` e `POST /native/migrate/reset` (uso amministrativo).
- **Le tabelle Nexus restano** per l'operativo: coda lavori, costi AI, log di pubblicazione/approvazione/attività, iscritti newsletter e registro editoriale collegato ai post via meta `_nexus_article_id`.

### 1.0.7 — «Impostazioni & Agenti Pro»

- **Impostazioni in 4 gruppi con accordion**: le sezioni sono raggruppate in Pipeline editoriale, Intelligenza artificiale, Pubblicazione & distribuzione e Sito & sistema; ogni sezione si apre e chiude con un click, i valori restano inviati anche a pannello chiuso e lo stato aperto/chiuso è ricordato dal browser (localStorage). Nessun setting nuovo.
- **Modelli AI auto-load dal provider**: bottone «Carica modelli» in Impostazioni → Configurazione AI e auto-load al blur del campo chiave; l'elenco arriva dall'endpoint compatibile OpenAI `{ai_base_url}/models` con la chiave salvata, popola i suggerimenti (datalist) del campo modello globale e del campo modello nell'editor agenti, con cache 12 ore per base URL ed errori onesti (chiave mancante, provider non raggiungibile, risposta in errore).
- **Editor agenti completo**: bottone «Nuovo agente» e modal editor per creare un agente (nome, avatar, categoria unica, stato, modello, descrizione, personalità), modificare in tutti i campi quelli esistenti e clonarli; il clone nasce in pausa con nome «{originale} (copia)» e categoria resa univoca (`-copia`, `-copia-2`, …). Nuove rotte REST admin: `POST /agents`, `PUT /agents/{id}/update`, `POST /agents/{id}/clone` e `GET /ai/models`.
- **Diagnostica AI in Strumenti**: card con provider configurato (URL base, modello globale ed economico) e stato della chiave (mai mostrata), più bottone «Testa provider AI» che chiama `GET /ai/models` e mostra quanti modelli espone il provider (con nota cache 12h).
- **Fix overflow mobile**: griglia agenti fluida auto-fill, tabelle dati in wrapper scrollabili `.nxa-table-wrap`, toolbar a capo e input full-width su mobile (breakpoint 782px/600px).

### 1.0.6 — «UI chiara & curata»

- **Admin chiaro di default**: tutto il pannello passa al tema chiaro (superfici bianche, bordi sottili, ombre leggere, focus visibile, reduced-motion); il dark delle versioni precedenti resta disponibile con il nuovo setting `admin_theme` (`light` | `dark`) in **Impostazioni → Interfaccia**; meccanica a token CSS (`:root` light, override su `body.nexus-theme-dark`).
- **Frontend pubblico light editoriale**: nuovo `public/css/nexus-public.css` (token `--nxp-*`) che restyla magazine, griglia articoli (card bianche 14px, immagine 16:10, titolo fluido, meta in grigio, badge categoria tenui, hover delicato, griglia 1/2/3 colonne a 600/1024px, variante lista), Nexus Wire (card chiare e badge di stato in tinte light), form `[nexus_subscribe]` (input + bottone gradiente in fila, stack su mobile, messaggi successo/errore verdi/rossi chiari) e pagina articolo singolo (interlinea 1.7, contenuto max 720px, blockquote accent, meta sopra il titolo); empty state, cifre tabulari e stampa curati.
- **Enqueue selettivo del foglio pubblico**: caricato solo dove renderizzano shortcode e blocchi Nexus (`Nexus_Shortcodes::enqueue_assets()` già operativo, nuovo enqueue con guardia in `Nexus_Blocks::render_subscribe()`, template single con hook dedicato); nessun peso aggiuntivo sulle altre pagine.
- **1 nuovo setting** nella sezione admin «Interfaccia»: `admin_theme`.

### 1.0.5 — «Strumenti, API pubblica, Multisite, Admin bilingue»

- **Strumenti**: nuova pagina nel menu **Nexus News** con informazioni di sistema (versioni plugin/WordPress/PHP, 9 tabelle `wp_nexus_*` con numero di righe, eventi WP-Cron con prossima esecuzione, stat della coda lavori), **esporta configurazione** in JSON (attenzione: il file contiene chiavi API e token) e **importa configurazione** (impostazioni sovrascritte, agenti abbinati per categoria: aggiornati o creati).
- **API pubblica**: nuovo namespace REST **`nexus-public/v1`** (classe `Nexus_Public_API`) con `GET /articles` (`page`, `per_page` max 50, `category`), `GET /articles/{id}`, `GET /agents` e `GET /stats`; autenticazione via header `X-Api-Key` (o `?api_key=`), **rate limit 120 richieste al minuto** (HTTP 429 con `Retry-After: 60`) e **CORS aperto**; si attiva da Impostazioni → Lingua e API pubblica, la chiave si genera in Strumenti → API pubblica.
- **Multisite**: nuova classe `Nexus_Multisite` con installazione automatica delle tabelle sui nuovi siti della rete (hook `wp_initialize_site`) e comando WP-CLI **`wp nexus multisite status|install [--sites=1,2]`**; ogni sito ha tabelle e impostazioni proprie.
- **Admin bilingue (sperimentale)**: nuova classe `Nexus_I18n` con dizionario IT→EN (base + esteso) applicato lato **PHP** all'HTML delle viste admin e lato **JS** ai contenuti dinamici (`assets/js/i18n.js` con MutationObserver); selezione lingua in Impostazioni → Lingua e API pubblica (`admin_language`: Automatica/Italiano/English).
- **3 nuove rotte REST admin**: `GET /export` (configurazione in JSON), `POST /import` (ripristino, match agenti per categoria), `POST /tools/api-key` (generazione chiave API pubblica).
- **3 nuovi setting** nella sezione admin «Lingua e API pubblica»: `admin_language`, `enable_public_api`, `public_api_key`.

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
