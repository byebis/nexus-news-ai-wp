=== Nexus News AI ===
Contributors: byebis
Tags: ai, news, magazine, rss, agents, editoriale, giornale
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Il giornale di nuova generazione, scritto da 7 agenti giornalisti AI. Pipeline editoriale completa: raccolta RSS, valutazione, riscrittura AI, approvazione e pubblicazione multi-piattaforma. Gli articoli approvati diventano post WordPress reali (CPT nexus_article) con immagini in evidenza AI e SEO automatico.

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
5. **Pubblicazione multi-piattaforma**: per **blog la pubblicazione è reale** — dalla 1.0.1 l'articolo diventa un **post WordPress vero** (CPT `nexus_article`, permalink `/nexus/{slug}/`), collegato al database Nexus e con fallback legacy `/?nexus_article={id}`; i canali social **Twitter/X, Facebook, LinkedIn e Instagram sono simulati in modo onesto**, con log consultabili per ogni articolo.

Il tutto gestibile da un **admin panel completo** (dashboard, agenti, coda di approvazione, attività, Nexus Wire, impostazioni) e fruibile in frontend con uno **shortcode magazine** moderno, reazioni emoji e modal di lettura.

**Nexus Wire** è la redazione collettiva: una sessione multi-agente in cui gli agenti selezionano le fonti, preparano un dossier, scrivono una bozza, la criticano a due voci e fondono tutto in un articolo definitivo firmato dalla redazione.

== Features ==

* **Pipeline editoriale completa**: raccolta RSS → valutazione → riscrittura AI → approvazione → pubblicazione multi-piattaforma.
* **Modalità semi-autonoma**: gli articoli richiedono l'approvazione umana prima della pubblicazione.
* **Modalità completamente autonoma**: auto-approvazione con soglia di qualità configurabile, la pipeline gira da sola via cron.
* **Admin panel completo**: dashboard con statistiche, gestione agenti (emoji, personalità, stato), coda di approvazione, feed attività, Nexus Wire e impostazioni.
* **Magazine frontend**: shortcode [nexus_magazine] con hero section, barre categoria, griglia articoli, modal di dettaglio e reazioni emoji.
* **Shortcode**: [nexus_magazine], [nexus_articles category="Tecnologia" limit="6" layout="list"], [nexus_wire].
* **Cron automatico**: WP-Cron pianifica raccolta, valutazione, riscrittura e pubblicazione secondo l'intervallo configurato.
* **REST API nexus/v1**: tutti i dati e le azioni della redazione sono esposti via REST (agents, articles, approve/reject, pipeline collect/run, settings, activity, stats, wire, route pubbliche per il magazine, health).
* **Pubblicazione come post WordPress reali**: CPT `nexus_article` con permalink `/nexus/{slug}/`, categorie e tag native e template single dedicato (fallback al front-end Nexus legacy).
* **Immagini in evidenza AI**: featured image generata con l'AI (modello e dimensione configurabili), con fallback su immagine articolo e placeholder locale.
* **SEO**: meta title/description generate con l'AI, Open Graph, JSON-LD NewsArticle e compatibilità Yoast SEO / RankMath.
* **Pubblicazione multi-piattaforma**: blog reale (post WP del CPT `nexus_article`, fallback legacy `/?nexus_article={id}`) + social simulati in modo dichiarato (Twitter/X, Facebook, LinkedIn, Instagram) — log onesti e consultabili per ogni articolo.
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

**No.** La *disattivazione* conserva tabelle, articoli, agenti e impostazioni: puoi riattivare quando vuoi senza perdere nulla. I dati vengono eliminati **solo alla disinstallazione** (cancellazione del plugin dalla pagina Plugin), quando `uninstall.php` rimuove le 5 tabelle `wp_nexus_*`, le opzioni `nexus_settings`, `nexus_db_version`, `nexus_wire_runs`, i transients `nexus_rss_*` e i post `nexus_article` (con i loro meta `_nexus_*`). Se vuoi conservare gli articoli, fai un backup del database prima di eliminare il plugin.

== Changelog ==

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

= 1.0.1 =
"Contenuti veri": gli articoli diventano post WordPress reali (CPT `nexus_article`) con permalink `/nexus/{slug}/`, immagini in evidenza AI e SEO automatico compatibile con Yoast e RankMath.

= 1.0.0 =
Prima versione del plugin WordPress.
