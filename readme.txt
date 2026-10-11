=== Nexus News AI ===
Contributors: byebis
Tags: ai, news, magazine, rss, agents, editoriale, giornale
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.7
License: MIT
License URI: https://opensource.org/licenses/MIT

Il giornale di nuova generazione, scritto da 7 agenti giornalisti AI. Pipeline editoriale completa: raccolta RSS, valutazione, riscrittura AI, approvazione e pubblicazione multi-piattaforma. Gli articoli approvati diventano post WordPress reali (CPT nexus_article) con immagini in evidenza AI, SEO automatico, pubblicazione social reale su Telegram/Discord/X/LinkedIn e newsletter con double opt-in. Dalla 1.0.3 la redazione è «intelligente»: modello AI per-agente con modello economico per screening/valutazione/fact-check, tracking dell'uso AI (token e costo stimato in USD) con budget mensile stop/warn, fact-check automatico con punteggio e citazioni (blocco «Fonti verificate» sugli articoli), anti-duplicato via hash del titolo e traduzioni AI multi-lingua via REST. Dalla 1.0.4 la redazione scala: coda lavori stile Action Scheduler con retry automatico e pagina «Coda Lavori», comandi WP-CLI (`wp nexus`), blocchi Gutenberg nativi (ultimi articoli e form newsletter) e pagina Analytics (serie giornaliera, costi AI, breakdown per piattaforma e per agente, top articoli, iscritti). Dalla 1.0.5 la redazione si governa e si apre: pagina **Strumenti** con informazioni di sistema, esporta/importa configurazione JSON e gestione della chiave, **API pubblica** `nexus-public/v1` (articoli, agenti e statistiche con chiave `X-Api-Key` e rate limit 120 richieste/minuto), supporto **multisite** con installazione automatica delle tabelle sui nuovi siti e interfaccia admin **bilingue** italiano/inglese (sperimentale).

== Description ==

Nexus News AI è il giornale di nuova generazione, scritto da intelligenze artificiali: **7 agenti giornalisti AI**, ognuno specializzato in una categoria editoriale, che raccolgono le notizie, le valutano, le riscrivono e le pubblicano dopo approvazione.

**I 7 agenti giornalisti**

* 🤖 **TechBot** — Tecnologia: analitico e orientato all'impatto pratico
* 🏛️ **PolicyWatch** — Politica: equilibrato, con contesto storico e geopolitico
* 📈 **MarketPulse** — Economia: rigoroso ma accessibile
* 🔬 **SciExplorer** — Scienza: divulgativo ma preciso
* ⚽ **SportArena** — Sport: emozionante e narrativo
* 🎭 **CultureHub** — Cultura: elegante e riflessivo
* 🩺 **HealthDesk** — Salute: rassicurante e basato su evidenze

**Come funziona la pipeline editoriale**

1. **Raccolta**: gli agenti monitorano fonti RSS (o percorso evergreen) e, con una selezione AI, creano le bozze (draft).
2. **Valutazione**: ogni notizia riceve un punteggio di qualità (0-100).
3. **Riscrittura AI**: le bozze migliori vengono riscritte in stile editoriale dal modello AI configurato (OpenRouter-compatible).
4. **Approvazione**: in modalità semi-autonoma l'articolo entra in coda di approvazione e aspetta un revisore umano; in modalità completamente autonoma viene auto-approvato se supera la soglia di qualità.
5. **Pubblicazione multi-piattaforma**: per **blog la pubblicazione è reale** — l'articolo diventa un **post WordPress vero** (CPT `nexus_article`, permalink `/nexus/{slug}/`), collegato al database Nexus e con fallback legacy `/?nexus_article={id}`; dalla 1.0.2 **Telegram, Discord, X e LinkedIn sono reali** quando abilitati con le credenziali nei settings (altrimenti log "skipped" onesto), mentre Facebook e Instagram restano **simulati in modo onesto**, con log consultabili per ogni articolo.

Il tutto gestibile da un **admin panel completo** (dashboard, agenti, coda di approvazione, attività, Nexus Wire, impostazioni) e fruibile in frontend con uno **shortcode magazine** moderno, reazioni emoji e modal di lettura.

**Nexus Wire** è la redazione collettiva: una sessione multi-agente in cui gli agenti selezionano le fonti, preparano un dossier, scrivono una bozza, la criticano a due voci e fondono tutto in un articolo definitivo firmato dalla redazione.

== Features ==

* **Pipeline editoriale completa**: raccolta RSS → valutazione → riscrittura AI → approvazione → pubblicazione multi-piattaforma.
* **Modalità semi-autonoma**: gli articoli richiedono l'approvazione umana prima della pubblicazione.
* **Modalità completamente autonoma**: auto-approvazione con soglia di qualità configurabile, la pipeline gira da sola via cron.
* **Admin panel completo**: dashboard con statistiche, gestione agenti (emoji, personalità, stato), coda di approvazione, feed attività, Nexus Wire e impostazioni.
* **Magazine frontend**: shortcode [nexus_magazine] con hero section, barre categoria, griglia articoli, modal di dettaglio e reazioni emoji.
* **Shortcode**: [nexus_magazine], [nexus_articles category="Tecnologia" limit="6" layout="list"], [nexus_wire], [nexus_subscribe].
* **Cron automatico**: WP-Cron pianifica raccolta, valutazione, riscrittura e pubblicazione secondo l'intervallo configurato.
* **REST API nexus/v1**: tutti i dati e le azioni della redazione sono esposti via REST (agents, articles, approve/reject, pipeline collect/run, settings, activity, stats, wire, route pubbliche per il magazine, health).
* **Pubblicazione come post WordPress reali**: CPT `nexus_article` con permalink `/nexus/{slug}/`, categorie e tag native e template single dedicato (fallback al front-end Nexus legacy).
* **Immagini in evidenza AI**: featured image generata con l'AI (modello e dimensione configurabili), con fallback su immagine articolo e placeholder locale.
* **SEO**: meta title/description generate con l'AI, Open Graph, JSON-LD NewsArticle e compatibilità Yoast SEO / RankMath.
* **Pubblicazione multi-piattaforma**: blog reale (post WP del CPT `nexus_article`, fallback legacy `/?nexus_article={id}`) + social reali su Telegram, Discord, X e LinkedIn (credenziali nei settings; canali non configurati = log "skipped" onesto) + social simulati dichiarati (Facebook, Instagram) — log onesti e consultabili per ogni articolo.
* **Newsletter con double opt-in**: shortcode [nexus_subscribe] con iscrizione via REST (rate limit per IP), email di conferma e disiscrizione via token, più digest settimanale AI inviato via wp_mail ai destinatari fissi e agli iscritti confermati.
* **Ping di indicizzazione automatici**: IndexNow (Bing e altri, chiave auto-generata con key file servito dal plugin) e WebSub/PubSubHubbub sul feed alla pubblicazione dei post, fuori banda via WP-Cron.
* **Nexus Wire**: redazione collettiva multi-agente (raccolta candidati → selezione → dossier → bozza → revisioni → editor).
* **Nessuna dipendenza esterna**: solo PHP puro + vanilla JS, nessun framework da compilare.
* **Pronto per la traduzione**: text domain `nexus-news-ai` con file .pot incluso.

== Installation ==

1. Carica la cartella `nexus-news-ai` dentro `wp-content/plugins/` (via FTP o "Plugin → Aggiungi nuovo → Carica plugin" con lo zip).
2. Attiva il plugin dalla pagina **Plugin** di WordPress: all'attivazione vengono create le 5 tabelle `wp_nexus_*` e i 7 agenti vengono inseriti automaticamente.
3. Vai in **Nexus News → Impostazioni** e inserisci la tua **chiave AI** (API OpenRouter-compatible, es. openrouter.ai): la pipeline la richiede in ogni fase (anche la raccolta seleziona i candidati con il modello). Senza chiave magazine, admin panel, coda, REST e pubblicazione funzionano comunque.
4. [Opzionale] Per una schedulazione affidabile imposta un **cron di sistema** che chiama `wp-cron.php` ogni ora (WP-Cron integrato funziona già, ma su siti a basso traffico può ritardare).
5. Crea una pagina (es. "Magazine") e inserisci lo shortcode **[nexus_magazine]**: il tuo giornale è online.

== Frequently Asked Questions ==

= Serve una chiave AI per usare il plugin? =

Sì se vuoi che la redazione produca articoli: **ogni fase della pipeline (raccolta inclusa) chiama il modello** per selezionare, valutare e riscrivere le notizie, e il Nexus Wire è interamente AI. La chiave **OpenRouter-compatible** si imposta in *Impostazioni*. Per installare, configurare, usare admin panel, magazine, shortcode e REST API non serve alcuna chiave.

= Cosa succede senza chiave AI? =

Le esecuzioni della pipeline si fermano: ogni fase registra nei log attività l'errore «Chiave API AI non configurata» e nessun articolo nuovo viene creato. Niente si rompe: magazine, admin panel, coda, pubblicazione degli articoli esistenti e REST API restano operativi. Appena inserisci la chiave, le nuove esecuzioni partono regolarmente.

= Come funziona il cron? =

Il plugin usa **WP-Cron**: un evento pianificato (`nexus_cron_tick`, schedule custom `nexus_interval`) gira ogni `collect_interval` secondi (default: 1 ora) ed esegue le fasi automatiche abilitate (raccolta, valutazione, riscrittura, pubblicazione). WP-Cron si attiva ai visitatori del sito: per redazioni serie conviene un cron di sistema (es. `crontab` ogni ora su `wp-cron.php` con `DISABLE_WP_CRON`, o `wp cron event run nexus_cron_tick` via WP-CLI). La disattivazione del plugin rimuove l'evento pianificato.

= Come mostro il magazine sul sito? =

Inserisci lo shortcode `[nexus_magazine]` in una qualsiasi pagina: riceverai hero section, categorie, griglia articoli, modal di lettura e reazioni. Per liste più mirate usa `[nexus_articles category="Tecnologia" limit="6" layout="list"]`, per la redazione collettiva `[nexus_wire]`.

= I dati vengono cancellati quando disattivo il plugin? =

**No.** La *disattivazione* conserva tabelle, articoli, agenti e impostazioni: puoi riattivare quando vuoi senza perdere nulla. I dati vengono eliminati **solo alla disinstallazione** (cancellazione del plugin dalla pagina Plugin), quando `uninstall.php` rimuove le 8 tabelle `wp_nexus_*` (incluse `wp_nexus_subscribers`, `wp_nexus_usage_logs` e `wp_nexus_translations`), le opzioni `nexus_settings`, `nexus_db_version`, `nexus_wire_runs`, i transients `nexus_rss_*`, gli eventi cron `nexus_weekly_digest` e `nexus_ping_article` e i post `nexus_article` (con i loro meta `_nexus_*`). Se vuoi conservare gli articoli, fai un backup del database prima di eliminare il plugin.

== Changelog ==

= 1.0.7 =
* Impostazioni in 4 gruppi con accordion: Pipeline editoriale, Intelligenza artificiale, Pubblicazione & distribuzione e Sito & sistema; ogni sezione si apre e chiude con un click, i valori restano salvati anche a pannello chiuso e lo stato aperto/chiuso viene ricordato dal browser (localStorage). Nessun setting nuovo.
* Modelli AI auto-load: il bottone "Carica modelli" (o l'uscita dal campo chiave) interroga il provider all'endpoint compatibile OpenAI {base_url}/models, mostra quanti modelli sono disponibili e li suggerisce nei campi modello (globale e editor agenti) con cache 12 ore ed errori onesti.
* Editor agenti: bottone "Nuovo agente" e modal editor per creare agenti, modificare tutti i campi (nome, avatar, categoria unica, stato, modello, descrizione, personalità) e clonare gli esistenti; il clone nasce in pausa con categoria resa univoca. Nuove rotte REST admin: POST /agents, PUT /agents/{id}/update, POST /agents/{id}/clone e GET /ai/models.
* Diagnostica AI in Strumenti: card con provider configurato (URL base, modello globale ed economico) e stato della chiave (mai mostrata), più bottone "Testa provider AI" che mostra quanti modelli espone il provider.
* Fix overflow mobile: griglia agenti fluida auto-fill, tabelle dati scrollabili (wrapper .nxa-table-wrap), toolbar a capo e input full-width su smartphone.

= 1.0.6 =
* UI chiara & curata: il pannello admin passa al tema chiaro di default (superfici bianche, bordi sottili, ombre leggere, focus visibile); il tema scuro delle versioni precedenti resta disponibile con il nuovo setting admin_theme (light|dark) nella nuova sezione "Interfaccia" di Impostazioni.
* Frontend pubblico: nuovo foglio light editoriale nexus-public.css (token --nxp-*), caricato solo dove renderizzano shortcode e blocchi Nexus; card articoli bianche con immagine 16:10, titolo fluido, meta in grigio e badge categoria tenui, griglia responsive 1/2/3 colonne (600/1024px), hover delicato; form [nexus_subscribe] con input e bottone gradiente in fila (stack su mobile) e messaggi successo/errore con tinte verdi/rosse chiare; pagina articolo con tipografia di lettura (interlinea 1.7, contenuto max 720px), blockquote con bordo accent, immagini fluide; empty state, cifre tabulari, prefers-reduced-motion, contrasto AA e stampa curati.
* 1 nuovo setting nella sezione admin "Interfaccia": admin_theme.

= 1.0.5 =
* Strumenti: nuova pagina admin nel menu Nexus News con informazioni di sistema (versioni plugin/WordPress/PHP, 9 tabelle wp_nexus_* con numero di righe, eventi WP-Cron con prossima esecuzione, stat della coda lavori), esporta configurazione in JSON (attenzione: il file contiene chiavi API e token) e importa configurazione (impostazioni sovrascritte, agenti abbinati per categoria: aggiornati o creati).
* API pubblica: nuovo namespace REST nexus-public/v1 (classe Nexus_Public_API) con GET /articles (page, per_page max 50, category), GET /articles/{id}, GET /agents e GET /stats; autenticazione via header X-Api-Key (o ?api_key=), rate limit 120 richieste al minuto per chiave (HTTP 429 con Retry-After: 60) e CORS aperto; attivazione in Impostazioni → Lingua e API pubblica, chiave generata in Strumenti → API pubblica.
* Multisite: installazione automatica delle tabelle sui nuovi siti della rete (hook wp_initialize_site) e comando WP-CLI `wp nexus multisite status|install [--sites=1,2]`; ogni sito ha tabelle e impostazioni proprie.
* Admin bilingue (sperimentale): nuova sezione «Lingua e API pubblica» con la lingua dell'interfaccia admin (Automatica/Italiano/English) e traduzione delle etichette principali dell'admin lato PHP (viste) e lato JS (contenuti dinamici, assets/js/i18n.js).
* 3 nuove rotte REST admin: GET /export, POST /import, POST /tools/api-key.
* 3 nuovi setting nella sezione admin «Lingua e API pubblica»: admin_language, enable_public_api, public_api_key.

= 1.0.4 =
* Coda lavori stile Action Scheduler: tabella wp_nexus_jobs con claim atomico, retry automatico con backoff esponenziale (60s*2^n, cap 1 ora), requeue dei lavori bloccati oltre 30 minuti e pulizia automatica dei lavori finiti da più di 7 giorni; runner WP-Cron ogni 60 secondi (solo con enable_queue=1), hook accodabili (pipeline_run_full, pipeline_collect, pipeline_evaluate, pipeline_rewrite, pipeline_publish, pipeline_wire_run, article_translate, pipeline_cron_tick) e nuova pagina admin «Coda Lavori» con stat, filtri di stato, riprova/annulla, «Processa ora», pulizia manuale e auto-refresh ogni 15 secondi.
* WP-CLI: comando `wp nexus` con run (--agent/--all), collect, evaluate, rewrite, publish, approve/reject con nota, translate --lang, agents, articles, stats, queue stats|process|retry|cancel|cleanup e settings get/set.
* Blocchi Gutenberg: nexus/latest-articles (numero articoli 1-24, categoria, colonne 1-4, mostra immagini) e nexus/subscribe-form (titolo personalizzabile), dinamici server-side con preview nell'editor e nessun build step; alternativa a [nexus_articles] e [nexus_subscribe].
* Analytics: nuova pagina con serie giornaliera 7-90 giorni (creati/pubblicati), chiamate AI e costo stimato del periodo, breakdown per piattaforma di pubblicazione e per agente (qualità media), top 10 articoli per viste e iscritti newsletter (confermati/pending/nuovi nel periodo).
* 3 nuovi setting nella sezione admin «Scala & Pro»: enable_queue, queue_batch_size, queue_max_attempts.

= 1.0.3 =
* «Redazione intelligente»: modello AI per-agente (POST /agents/{id}/model, campo «Modello AI» nella pagina Agenti) e modello economico per screening/valutazione/fact-check; anti-duplicato via hash del titolo in fase di raccolta.
* Uso AI sotto controllo: ogni chiamata logga token e costo stimato in USD (tabella wp_nexus_usage_logs), la dashboard mostra «Uso AI» del mese e il budget mensile (monthly_budget_usd) con azione stop o warn protegge le spese.
* Fact-check automatico: punteggio 0-100 e citazioni claim→fonte salvati sull'articolo; sotto soglia (factcheck_min_score) l'articolo torna in bozza, sopra la soglia il post WordPress pubblica con il blocco «Fonti verificate».
* Traduzioni AI multi-lingua via REST (POST /articles/{id}/translate, GET /articles/{id}/translations) con lingue configurabili (default en,es,fr); 7 nuovi setting nella sezione admin «Redazione intelligente».

= 1.0.2 =
* Distribuzione sociale reale: pubblicazione su Telegram (Bot API), Discord (webhook), X/Twitter (OAuth 1.0a in PHP) e LinkedIn (Bearer token) con le credenziali nei settings; canali non configurati = log "skipped" onesti, Facebook/Instagram restano simulati.
* Newsletter: shortcode [nexus_subscribe] con iscrizioni double opt-in (email di conferma + disiscrizione via token), tabella iscritti e digest settimanale AI via wp_mail.
* Ping di indicizzazione automatici alla pubblicazione: IndexNow (chiave auto-generata) e WebSub (PubSubHubbub), fuori banda via WP-Cron. 19 nuovi setting nelle sezioni "Distribuzione sociale", "Newsletter" e "Indicizzazione".

= 1.0.1 =
* Pubblicazione come post WordPress reali: nuovo CPT `nexus_article` (permalink `/nexus/{slug}/`, categorie e tag native, template single dedicato) con fallback al front-end Nexus legacy.
* Immagini in evidenza generate con l'AI (modello e dimensione configurabili), con fallback su immagine articolo e placeholder locale.
* SEO: meta title/description AI, Open Graph, JSON-LD NewsArticle, compatibilità Yoast SEO / RankMath. 7 nuovi setting nella sezione "Pubblicazione WordPress & SEO".

= 1.0.0 =
* Prima versione: porting WordPress dell'app Next.js "Nexus News AI".
* 7 agenti giornalisti AI con personalità per categoria.
* Pipeline editoriale completa (RSS → valutazione → riscrittura AI → approvazione → pubblicazione: blog reale + social simulati).
* Admin panel: dashboard, agenti, coda di approvazione, attività, Nexus Wire, impostazioni.
* Shortcode magazine con reazioni e modal, cron automatico, REST API nexus/v1.
* Modalità semi-autonoma e completamente autonoma.

== Upgrade Notice ==

= 1.0.7 =
«Impostazioni & Agenti Pro»: le Impostazioni si leggono in 4 gruppi ad accordion (stato ricordato nel browser), i modelli AI si caricano dal provider con suggerimenti nei campi, gli agenti si creano, modificano e clonano dall'editor dedicato (il clone nasce in pausa) e la pagina Strumenti guadagna la diagnostica AI. Nessun setting nuovo: l'aggiornamento non richiede ritocchi alla configurazione.

= 1.0.6 =
«UI chiara & curata»: il pannello Nexus diventa chiaro di default (il tema scuro resta disponibile in Impostazioni → Interfaccia) e il frontend pubblico riceve uno stile light editoriale adattivo a qualunque tema: card articoli, form newsletter e pagina articolo ridisegnati nei minimi dettagli.

= 1.0.5 =
«Strumenti, API pubblica, Multisite, Admin bilingue»: dalla nuova pagina Strumenti esporti e importi la configurazione (attenzione: il JSON contiene chiavi API e token), la REST pubblica nexus-public/v1 espone articoli, agenti e statistiche con chiave X-Api-Key (rate limit 120 richieste/minuto), i nuovi siti multisite ricevono le tabelle in automatico e l'interfaccia admin diventa bilingue italiano/inglese (sperimentale).

= 1.0.4 =
«Scala & Pro»: attiva la coda lavori stile Action Scheduler con retry automatico, guida la redazione da terminale con `wp nexus`, inserisci articoli e newsletter come blocchi Gutenberg e misura tutto dalla nuova pagina Analytics.

= 1.0.3 =
«Redazione intelligente»: scegli il modello AI di ogni agente, contieni i costi col modello economico per lo screening e il budget mensile (stop o warn), pubblica solo articoli verificati dal fact-check con il blocco «Fonti verificate» e apri il magazine alle traduzioni AI multi-lingua via REST.

= 1.0.2 =
"Distribuzione reale": pubblica davvero su Telegram, Discord, X e LinkedIn con le tue credenziali, apri la newsletter con double opt-in (shortcode [nexus_subscribe] e digest settimanale AI) e segnala i nuovi articoli a IndexNow e WebSub. I canali non configurati restano onestamente "skipped" nei log.

= 1.0.1 =
"Contenuti veri": gli articoli diventano post WordPress reali (CPT `nexus_article`) con permalink `/nexus/{slug}/`, immagini in evidenza AI e SEO automatico compatibile con Yoast e RankMath.

= 1.0.0 =
Prima versione del plugin WordPress.
