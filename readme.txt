=== Nexus News AI ===
Contributors: byebis
Tags: ai, news, magazine, rss, agents, editoriale, giornale
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.7
License: MIT
License URI: https://opensource.org/licenses/MIT

Il giornale di nuova generazione, scritto da 7 agenti giornalisti AI. Pipeline editoriale completa: raccolta RSS, valutazione, riscrittura AI, approvazione e pubblicazione multi-piattaforma. Gli articoli approvati diventano post WordPress reali (CPT nexus_article) con immagini in evidenza AI, SEO automatico, pubblicazione social reale su Telegram/Discord/X/LinkedIn e newsletter con double opt-in. Dalla 1.0.3 la redazione è «intelligente»: modello AI per-agente con modello economico per screening/valutazione/fact-check, tracking dell'uso AI (token e costo stimato in USD) con budget mensile stop/warn, fact-check automatico con punteggio e citazioni (blocco «Fonti verificate» sugli articoli), anti-duplicato via hash del titolo e traduzioni AI multi-lingua via REST. Dalla 1.0.4 la redazione scala: coda lavori stile Action Scheduler con retry automatico e pagina «Coda Lavori», comandi WP-CLI (`wp nexus`), blocchi Gutenberg nativi (ultimi articoli e form newsletter) e pagina Analytics (serie giornaliera, costi AI, breakdown per piattaforma e per agente, top articoli, iscritti). Dalla 1.0.5 la redazione si governa e si apre: pagina **Strumenti** con informazioni di sistema, esporta/importa configurazione JSON e gestione della chiave, **API pubblica** `nexus-public/v1` (articoli, agenti e statistiche con chiave `X-Api-Key` e rate limit 120 richieste/minuto), supporto **multisite** con installazione automatica delle tabelle sui nuovi siti e interfaccia admin **bilingue** italiano/inglese (sperimentale). Dalla 1.1.0 la redazione è «tutto nativo»: gli articoli pubblicati diventano post WordPress NATIVI (post) con autore bot (utenti con ruolo nexus_ai_journalist: byline e pagina autore reali), categorie native della testata e tag automatici deterministici, i vecchi articoli CPT si convertono con la migrazione 1-click in Strumenti (barra di progresso batched) e la pubblicazione può passare per lo stato «In attesa di revisione» (post_status pending). Dalla 1.1.1 la testata si organizza in REDAZIONI GERARCHICHE: tassonomia WordPress nativa nexus_redazione con redazioni madri e sub-redazioni annidate (es. Sport → Calcio/Basket/F1), agenti assegnati ai termini (term meta), archivi pubblici /redazione/{slug}/ col subtree incluso, pagina admin Redazioni, colonna e filtro nativi nella lista Articoli, shortcode e API pubblica con attributo redazione e capabilities per ramo per gli editori: tutta struttura WP nativa, nessuna migrazione di schema. Dalla 1.1.2 la redazione parla tutte le lingue con il MULTILINGUA NATIVO: le traduzioni AI diventano post WordPress veri e collegati tra loro (permalink, revisioni e SEO propri) con driver automatico Polylang / WPML / collegamento interno Nexus via post meta (zero tabelle nuove), shortcode [nexus_lang_switcher], attributo lang per gli shortcode e parametro lang sull'API pubblica, hreflang e og:locale automatici sul single, pubblicazione automatica delle traduzioni dopo il publish con eventi WP-Cron sfalsati e compatibilità totale con Polylang e WPML tramite le loro API ufficiali; alla disinstallazione i post tradotti restano, come tutti i contenuti nativi. Dalla 1.1.4 la redazione funziona come una testata giornalistica vera: il CAPO REDATTORE AI (con ruolo e ambito a scelta globale, per categoria o per redazione) legge gli articoli in attesa di revisione e li approva, li fa riscrivere col suo feedback o li rifiuta — con auto-pubblicazione opzionale oppure umano sovrano col badge del capo — e gli agenti guadagnano STRUMENTI DI RICERCA reali (ricerca web Tavily/Serper/Brave, lettura pagine, scoperta e lettura RSS, client MCP) con tool-use loop controllato; ogni agente può usare un PROVIDER diverso oltre al modello, i nuovi giornalisti si creano con 8 PRESET 1-click e ogni redazione può avere le sue FONTI RSS in priorità sulle globali. Dalla 1.1.5 l'admin RESPIRA: tutta la UI amministrativa è più arieggonte (meno riquadri annidati, più spazio, separazione con whitespace e hairline, mobile senza doppio gutter), la sezione "Identità del sito" sparisce dalle impostazioni perché nome e tagline del sito sono GIÀ di WordPress nativo e il magazine li legge da lì, si avvia CHI VUOI con un click — il singolo giornalista dalla sua card, la singola redazione dal suo ramo o l'intera testata dalla dashboard con riepilogo per agente (raccolti, pubblicati, errori) — e il pannello live "In redazione ora" mostra in tempo reale cosa sta facendo ogni agente (raccoglie, valuta, scrive, pubblica o attende), con aggiornamento automatico ogni 5 secondi e ultima azione per ciascuno.

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
5. **Pubblicazione multi-piattaforma**: per **blog la pubblicazione è reale** — l'articolo diventa un **post WordPress vero** (dalla 1.1.0 un **post nativo** con autore bot, categoria e tag reali; il CPT `nexus_article` resta per i permalink legacy `/nexus/{slug}/`), collegato al database Nexus e con fallback legacy `/?nexus_article={id}`; dalla 1.0.2 **Telegram, Discord, X e LinkedIn sono reali** quando abilitati con le credenziali nei settings (altrimenti log "skipped" onesto), mentre Facebook e Instagram restano **simulati in modo onesto**, con log consultabili per ogni articolo.

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
* **Pubblicazione come post WordPress reali**: dalla 1.1.0 post NATIVI (`post`) con autore bot, categorie e tag native; il CPT `nexus_article` resta per i permalink legacy `/nexus/{slug}/` e il template single dedicato (fallback al front-end Nexus legacy).
* **Tutto nativo (v1.1.0)**: ogni agente è anche un utente bot WordPress con ruolo `nexus_ai_journalist` (solo `read`, niente wp-admin: byline e pagina autore reali), la sua categoria è agganciata a una categoria WP reale della testata, gli articoli ricevono tag automatici deterministici (nessuna chiamata AI), la migrazione 1-click in Strumenti converte i vecchi articoli CPT con barra di progresso batched, `post_status` ammette `pending` («In attesa di revisione») e 4 nuove rotte REST admin: GET /wp/categories, POST /native/migrate, GET /native/migrate/status, POST /native/migrate/reset. Le tabelle Nexus restano per l'operativo (coda, costi, log, iscritti).
* **Redazioni gerarchiche (v1.1.1)**: tassonomia WP nativa `nexus_redazione` (parent/child come le categorie) con sub-redazioni annidate a qualunque profondità e archivi pubblici /redazione/{slug}/ che includono il subtree; agenti assegnati ai termini (term meta _nexus_agents) e articoli tassonomizzati in automatico col ramo del proprio agente; pagina admin «Redazioni» con albero gerarchico e 4 rotte REST admin (GET/POST /redazioni, POST/DELETE /redazioni/{id}); colonna automatica e dropdown filtro «Tutte le redazioni» nella lista Articoli; attributo redazione per [nexus_articles], [nexus_magazine] e API pubblica; capabilities per ramo con guardie (solo manage_options assegna la redazione di un utente, l'editore scoped edita solo il suo ramo).
* **Multilingua nativo (v1.1.2)**: le traduzioni AI diventano post WordPress collegati tra loro (permalink, revisioni e SEO propri per ogni lingua), non più solo righe nella tabella storico wp_nexus_translations; driver automatico Polylang / WPML / collegamento interno Nexus (post meta _nexus_lang, _nexus_trid, _nexus_source_post_id; le associazioni con Polylang e WPML usano le loro API ufficiali); shortcode [nexus_lang_switcher], attributo lang per [nexus_articles] e [nexus_magazine], parametro lang sull'API pubblica con campo lingua su ogni item; hreflang (con x-default) e og:locale automatici sul single; rotte REST admin GET /multilang/info, POST /multilang/translate-post, GET /multilang/translations-posts; auto-translate post-publish (setting enable_autotranslate_posts, eventi WP-Cron sfalsati di un minuto) e card dashboard «Multilingua»; zero tabelle nuove, i post tradotti restano alla disinstallazione.
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
2. Attiva il plugin dalla pagina **Plugin** di WordPress: all'attivazione vengono create le 9 tabelle `wp_nexus_*` e i 7 agenti vengono inseriti automaticamente; dalla 1.1.0 viene registrato anche il ruolo «Giornalista AI» per gli utenti bot degli agenti.
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

= Come funziona la pubblicazione «tutto nativo»? =

Dalla 1.1.0 gli articoli pubblicati sono **post WordPress nativi** (post type `post`): compaiono in home, negli archivi e nel feed del tema come qualunque post, con la categoria reale della testata e tag deterministici derivati da titolo e riassunto (nessuna chiamata AI). Ogni agente ha un **utente bot** con ruolo `nexus_ai_journalist` (sola capability `read`, nessun accesso a wp-admin) e firma i post: byline e pagina autore sono quelle del tema. I vecchi articoli del CPT `nexus_article` restano funzionanti (permalink `/nexus/{slug}/`) e si convertono con la **migrazione 1-click** in Strumenti → «Migrazione contenuti nativi»: conversione a batch con barra di progresso, autore bot e categoria nativa assegnati, stato WordPress e meta di collegamento conservati. Le tabelle Nexus restano per l'operativo: coda lavori, costi AI, log, iscritti.

= Come funzionano le traduzioni dalla 1.1.2? =

Le traduzioni AI diventano **post WordPress veri e collegati**: per ogni lingua la traduzione pubblicata è un post nativo (permalink, revisioni e SEO propri) legato al post sorgente nel gruppo di traduzione della lingua. Con Polylang o WPML attivi le associazioni tra lingue usano le loro API ufficiali (i post compaiono nelle loro interfacce come qualunque traduzione); senza plugin di lingua il collegamento è interno al plugin via post meta (zero tabelle nuove). Nella pagina articolo la riga «Pubblica come post» crea o aggiorna la traduzione per lingua (upsert: mai duplicati); con «Pubblica automaticamente le traduzioni come post» attivo nelle Impostazioni ogni articolo pubblicato pianifica le sue traduzioni come eventi WP-Cron sfalsati di un minuto. In frontend lo shortcode [nexus_lang_switcher] elenca le lingue e i link alle traduzioni, e il single emette da solo i meta hreflang e og:locale quando ci sono più lingue pubblicate. I post tradotti sono contenuti nativi: alla disinstallazione del plugin restano nel sito.

== Changelog ==

= 1.1.7 =
* UCCISA LA VERA CAUSA della "doppia dashboard": la voce "Dashboard" veniva registrata come sottopagina con lo stesso slug del menu — WordPress la attaccava allo stesso hook del pannello principale e la vista veniva stampata DUE volte nella pagina (2× pannelli, statistiche, feed). Ora una sola voce "Dashboard" nel menu e un solo render, verificato e2e su WordPress reale.
* Pagina Strumenti risanata (i "test non funzionano"): gli script inline partivano prima che i dati di sessione fossero disponibili e in inglese una voce del dizionario corrompeva il nome dell'oggetto JS — i bottoni Test AI, Esporta, Importa, Genera chiave e Migrazione ora rispondono tutti (12/12 verificati e2e su WordPress reale, anche con cache AI fredda e in italiano).
* Coda Lavori di nuovo viva: la guardia degli script inline usciva per sempre (stessa malattia degli Strumenti) e la pagina restava morta in ogni lingua; ora si avvia al momento giusto.
* Analytics di nuovo vivo anche in inglese: un identificatore italiano nel codice del grafico veniva rinominato dal traduttore (ReferenceError silenzioso). Fix strutturale: la traduzione non tocca MAI gli script inline (i testi che producono sono tradotti lato client da i18n.js) né il contenuto utente (la tagline del sito non diventa più "Il giornale of nuova generazione").
* Nuova card "Test degli strumenti" (promessa v1.1.4): prova ricerca web, lettura pagina e RSS direttamente dalla pagina Strumenti.
* Ripristinato il link "Vedi articoli dell'agente" nelle card della pagina Agenti (esisteva solo nel fallback noscript).
* Rilevazione e2e su WORDPRESS REALE (nuovo ambiente di test automatico con browser): avvio testata con 9 agenti in parallelo → articoli → coda → approvazione → pubblicazione → post WordPress vero + log; salvataggio verificato di tutti i 79 campi delle 4 pagine Impostazioni; analytics, attività, lavori e i 12 controlli di Strumenti; tutto verde in italiano e in inglese.

= 1.1.6 =
* ZERO MOCK (richiesta esplicita): eliminata la pubblicazione social simulata — un canale non configurato ora produce un errore onesto ("Canale social non configurato") senza più ID/URL finti ("sim_xxx", social.example) né status "published" finto; Facebook e Instagram escono dalla lista piattaforme (non hanno integrazione reale: Telegram, Discord, X, LinkedIn e Blog restano i canali veri); sweep completo del codice con report hit-by-hit nel changelog del repo.
* Timestamp veri nell'attività: fix del bug che scriveva "0000-00-00 00:00:00" in tutti i log (formato wpdb incompleto nell'insert) + pulizia one-time delle righe senza data vera + le API non emettono mai più date zero (stringa vuota: la UI mostra "—", non uno zero finto); fixato anche un bug latente sull'update agenti che poteva azzerare updated_at.
* Interfaccia IT/EN COMPLETA al 100%: dizionari integrati (775 voci + 186 pattern) con audit automatico a zero residui sulle stringhe renderizzate; i messaggi di log con parti variabili vengono tradotti a pattern (es. "Riscrittura articolo #10 fallita" → "Rewrite of article #10 failed"); tradotti anche placeholder, aria-label, valori dei bottoni e i messaggi dei dialoghi di conferma; self-test su 61 messaggi di log reali 61/61.
* Impostazioni divise in 4 SOTTOMENU WordPress veri (dopo Strumenti): Impostazioni contenuti / Impostazioni AI / Impostazioni pubblicazione / Impostazioni sistema — ogni pagina ha il proprio form e il bottone SALVA ANCHE IN CIMA (niente più scroll infinito per salvare); i vecchi link a page=nexus-settings continuano a funzionare.
* "Pubblicazione social" e "Distribuzione sociale" UNIFICATE in un'unica sezione Social (erano quasi la stessa cosa), con tutti i campi mantenuti identici.
* Fix "dashboard doppia": rimosso il bottone duplicato che faceva la stessa identica azione dell'Avvia testata ("Esegui pipeline completa"), guardia anti-doppio-boot in JS e pannello live compatto (in dettaglio solo gli agenti al lavoro, gli altri in una riga di conteggio).
* Design "respira" esteso anche alle pagine autocontenute (Analytics, Lavori, Strumenti): superfici piatte con hairline e token di spaziatura, via i box dentro box residui.

= 1.1.5 =
* UI che respira: pass design completo dell'admin — meno riquadri annidati (il contenitore "scatola" resta uno per pagina, il resto si separa con whitespace e hairline), scala di spaziatura a token (4-64px), tipografia più comoda (corpo 15px, titoli più arieggonti), accordion impostazioni come righe testuali pulite senza box attorno, tabelle con più respiro e mobile con gutter unico (addio doppio padding visibile su telefono); tema scuro aggiornato con gli stessi principi.
* "Identità del sito" rimossa dalle Impostazioni: nome e tagline del sito sono GIÀ nativi WordPress (Impostazioni → Generali) e ora il magazine li legge da lì (bloginfo), con fallback retrocompatibile ai vecchi valori; la whitelist REST conserva i vecchi campi per BC ma nessun input resta in UI.
* Avvii mirati: bottone "Avvia" su ogni card agente (singolo giornalista), "Avvia redazione" su ogni ramo dell'albero Redazioni e "Avvia testata" in dashboard con conferma che indica quanti agenti verranno avviati; POST pipeline/run esteso retrocompatibile con nuovi parametri redazione_id e agent_ids[] e risposta con riepilogo per agente (raccolti, valutati, riscritti, pubblicati, errori) mostrato in toast e blocco riepilogo; un agente in errore non blocca gli altri.
* Pannello live "In redazione ora" in dashboard: ogni agente con pallino di stato (al lavoro, in pausa, errore, in attesa), cosa sta facendo adesso (raccoglie le fonti, valuta i materiali, scrive l'articolo, pubblica, revisione del capo redattore) con titolo dell'articolo in corso, ultima azione con tempo umano e conteggio articoli di oggi; aggiornamento automatico ogni 5 secondi solo con la pagina visibile (pausa quando la scheda è nascosta, endpoint assente → pannello nascosto in silenzio); nuova rotta REST GET /live leggera (3 query raggruppate, pensata per il polling).
* Stato runtime agenti: nuova opzione nexus_agent_runtime scritta dalla pipeline nei punti chiave del flusso e pulita a fine lavoro (anche in errore) — alimenta il pannello live senza tabelle nuove.
* Sgrassata markup nelle viste secondarie (Strumenti, Wire, Analytics, Lavori, Coda, Activity): rimossi i wrapper decorativi annidati rimasti, niente logica toccata.
* Nessuna tabella nuova; nessuna modifica allo schema; retrocompatibilità totale: senza usare i nuovi bottoni o il pannello live il flusso resta identico alla 1.1.4.

= 1.1.4 =
* Capo redattore AI: nuovo ruolo "capo_redattore" che legge gli articoli in attesa di revisione (pending_approval) ed emette un verdetto motivato — approva, revisiona o rifiuta — con nota; ambito di competenza a scelta globale, per categoria o per singola redazione; con "Auto-pubblica" attivo approva e pubblica da solo, altrimenti resta tutto all'umano che trova in coda e nel dettaglio il badge del capo (visto/modificato/rifiutato/revisione) e la nota; ciclo di revisione vero: la nota del capo viene allegata al prompt del giornalista che riscrive, con numero di giri configurabile (1-5) e protezioni anti-loop (lock, contatori, budget AI rispettato).
* Provider AI per ogni agente: oltre al modello, ogni giornalista e ogni capo redattore può usare un provider AI proprio (vuoto = provider globale); select nel editor agente con i provider configurati, campo REST ai_provider retrocompatibile.
* Strumenti di ricerca per gli agenti: la generazione editoriale può usare un tool-use loop controllato — ricerca web (Tavily, Serper o Brave con chiave dedicata), lettura pagine (estrazione pulita di titolo e testo), scoperta dei feed di un sito e lettura RSS, client MCP (protocollo Streamable HTTP: handshake, tools/list, tools/call con sessione, token Bearer opzionale e parsing SSE) — il modello riceve il catalogo degli strumenti attivi nel prompt, chiama gli strumenti che gli servono e riceve i risultati («RISULTATO STRUMENTO») finché non conclude; limiti configurabili (max risultati, max caratteri letti, max giri 0-6, timeout) con guard 90 secondi, anti-ripetizione e trace dei tool usati nei log attività.
* Preset giornalisti 1-click: barra "Creazione rapida" in Agenti con 8 preset (tech, sport, economia, politica, scienza, cultura, salute e capo redattore) che precompilano nome, avatar e personalità; creare una redazione semplice è un click.
* Fonti RSS per redazione: ogni termine della tassonomia Redazioni può avere le sue fonti (term meta); gli agenti assegnati a quella redazione le usano in priorità rispetto alle fonti globali (dedup per link, massimo 10 elementi).
* Impostazioni nuove: sezione "Capo redattore AI" (attiva, auto-pubblica, capo predefinito, max giri di revisione) e sezione "Strumenti di ricerca" (attiva, provider e chiave di ricerca, lettura pagine on/off, max risultati, max caratteri, max giri, timeout, server MCP come righe nome|url|token opzionale) con bottone di test strumenti dalla UI; nuove rotte REST admin GET /tools/catalog, POST /tools/test e POST /chief/run/{id}.
* Schema: colonne nuove con upgrade automatico — su agenti role, chief_scope e chief_term_id; su articoli chief_status, chief_note, chief_agent_id, chief_reviewed_at e chief_rounds. Nessuna tabella nuova.

= 1.1.3 =
* Dettaglio articolo: overlay scrollabile e pannelli centrati senza tagli anche con il foglio stile admin reale
* Menu CPT auto-hide: quando non restano più articoli legacy nel CPT nexus_article (conteggio esclusi cestino/auto-draft, cachato nel transient nexus_cpt_legacy_count per 5 minuti e invalidato sugli hook core save_post/transition_post_status/deleted_post) la voce "Nexus News AI" sparisce automaticamente dal menu admin; il post type resta registrato e i permalink legacy /?nexus_article= e l'archivio /nexus/ continuano a funzionare; filtro nexus_cpt_force_menu per tenere il menu sempre visibile o sempre nascosto.
* Fix overflow del dettaglio articolo su mobile: modal allineato in alto (align-items:flex-start) con scorrimento proprio e padding ridotto, altezza in dvh con fallback vh (testa sticky intatta), overflow-wrap:anywhere nel body e nuova media query 600px più compatta: niente più contenuto tagliato sopra/sotto o oltre il bordo destro (Chrome Android).
* Bottone "Vedi bozza WordPress" in coda di approvazione e nel modal di dettaglio (accanto al badge «Post WP»): apre l'anteprima o l'editor del post WP collegato (post pubblicato = "Vedi in WordPress" sul permalink pubblico); gli articoli legacy senza post WP non mostrano il bottone.
* Shape REST estesa e retrocompatibile: serialize_article (unica shape di tutti gli endpoint articoli) espone sempre anche wp_post_status, wp_edit_url (valorizzato solo se l'utente corrente può modificare il post) e wp_preview_url (solo per i post non pubblicati), stringa vuota quando il dato non è disponibile. Nessuna migrazione di schema e nessun setting nuovo.

= 1.1.2 =
* Traduzioni come post WordPress collegati: Nexus_Translate::translate_to_post pubblica la traduzione AI di un articolo come post nativo (titolo, contenuto e riassunto tradotti, stato del sorgente, autore bot, categoria e tag nativi, immagine in evidenza ereditata) collegato al gruppo di traduzione della lingua; upsert sempre — se il post della lingua esiste già viene aggiornato (already=true), mai duplicato; storico e costi restano su wp_nexus_translations e wp_nexus_usage_logs.
* Tre driver automatici (classe Nexus_Multilang, mai fatale): Polylang attivo = associazioni via API ufficiali pll_*; WPML attivo = filtri wpml_*; nessun plugin di lingua = collegamento interno Nexus via post meta _nexus_lang (lingua), _nexus_trid (gruppo), _nexus_source_post_id (sorgente). Zero tabelle nuove.
* Tre nuove rotte REST admin (permission manage_options): GET /multilang/info (driver, lingua predefinita, lingua corrente, lingue del sito con nome), POST /multilang/translate-post (pubblica la traduzione come post collegato con edit_url e permalink, errori onesti), GET /multilang/translations-posts (stato per articolo: post sorgente, lingua e lista traduzioni con stato, data di modifica e marca «sorgente»). Nella pagina articolo la riga «Pubblica come post» con i bottoni lingua e i chip di stato.
* Frontend multilingua: nuovo shortcode [nexus_lang_switcher] (lingue del sito, permalink della traduzione pubblicata per lingua, lingue senza traduzione non cliccabili con nota onesta), attributo lang per [nexus_articles] e [nexus_magazine] (driver nexus: filtro per lingua; Polylang/WPML: contenuto già filtrato dai plugin) e parametro lang sull'API pubblica nexus-public/v1 con nuovo campo lang su ogni item (e lang_note quando il filtro è delegato ai plugin).
* SEO multilingua: sul single con più di una lingua pubblicata hreflang per ogni lingua (x-default sulla lingua predefinita) e og:locale con og:locale:alternate, automatici con tutti i driver.
* Auto-translate post-publish e dashboard: setting enable_autotranslate_posts — al publish di un articolo ogni lingua attiva riceve un evento WP-Cron nativo nexus_ml_autotranslate sfalsato di un minuto (traduzioni una alla volta, already=true se già tradotta: nessun costo AI duplicato); nuova card «Multilingua» in dashboard con driver attivo e articoli pubblicati per lingua. Nessuna migrazione di schema: l'aggiornamento è tutto WordPress e i post tradotti restano alla disinstallazione.

= 1.1.1 =
* Redazioni gerarchiche: nuova tassonomia WordPress nativa nexus_redazione, gerarchica parent/child esattamente come le categorie, per organizzare la testata in redazioni madri e sub-redazioni annidate (es. Redazione Sport → Calcio con 3 agenti, Basket con 2, F1 con 1); zero tabelle nuove.
* Agenti assegnati ai termini con term meta _nexus_agents: alla pubblicazione (pipeline e migrazione 1-click) l'articolo riceve automaticamente i termini redazione del suo agente; gli archivi pubblici /redazione/{slug}/ col tema includono il subtree come le categorie (l'archivio di Sport mostra anche Calcio, Basket e F1).
* Pagina admin «Redazioni» nel menu Nexus News (tra Agenti e Coda Approvazioni) con stat aggregate, form madre/sub-redazioni (nome, slug, madre, checkbox agenti), albero gerarchico con chips agenti e contatori; 4 nuove rotte REST admin: GET /redazioni, POST /redazioni, POST /redazioni/{id}, DELETE /redazioni/{id} (permission manage_options).
* Lista Articoli nativa: colonna automatica «Redazioni» e dropdown gerarchico «Tutte le redazioni» con filtro per ramo completo (post nativi e CPT nexus_article legacy), assegnazione correggibile da editor a blocchi e quick edit.
* Attributo redazione="slug-or-name" per [nexus_articles] e [nexus_magazine] e parametro redazione sugli endpoint di listing dell'API pubblica nexus-public/v1: filtro con subtree incluso.
* Capabilities per ramo: campo profilo «Redazione assegnata» (user meta nexus_redazione_id) visibile e salvabile solo da chi ha manage_options; un editore scoped edita ed elimina solo gli articoli del suo ramo (map_meta_cap + filtro lista Articoli); su multisite ogni sito della rete ha le sue redazioni.

= 1.1.0 =
* Contenuti "tutto nativo": gli articoli nuovi pubblicano come post WordPress NATIVI (post type 'post', filtrabile con nexus_native_post_type) nella home, negli archivi e nel feed del tema, con categorie e tag reali; il CPT nexus_article resta registrato per i permalink legacy /nexus/{slug}/ e le letture sono dual-mode (post + nexus_article) finché la migrazione non è completata.
* Agenti come autori reali: ogni agente diventa un utente bot WordPress (login nexus_agent_{id}) con ruolo dedicato nexus_ai_journalist («Giornalista AI», sola capability read, nessun accesso a wp-admin) e firma i post come post_author: byline e pagina autore del tema; nuove colonne wp_user_id/term_id su wp_nexus_agents con upgrade automatico delle tabelle.
* Categorie native agganciate agli agenti (categoria WP reale creata se manca) e tag automatici deterministici (3-5 tag da titolo e riassunto, nessuna chiamata AI).
* Migrazione 1-click in Strumenti: card «Migrazione contenuti nativi» con contatori (post CPT residui, agenti senza utente bot/categoria), bottone «Avvia migrazione», barra di progresso batched e report onesto degli errori; conversione CPT → post nativi con autore bot, categoria nativa, meta di collegamento e stato WP conservati.
* Nuovo stato post_status «pending» («In attesa di revisione»): flusso editoriale bozza → revisione → pubblicato.
* 4 nuove rotte REST admin: GET /wp/categories, POST /native/migrate, GET /native/migrate/status, POST /native/migrate/reset.

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

= 1.1.3 =
Admin più pulito e fix mobile: il menu "Nexus News AI" sparisce automaticamente quando non restano più articoli legacy nel vecchio CPT — è il comportamento atteso, non un errore: i contenuti nuovi vivono nei post nativi e i permalink legacy restano validi. Vuoi ripulire subito? Usa la migrazione 1-click in Strumenti → «Migrazione contenuti nativi» (oppure il filtro nexus_cpt_force_menu se vuoi tenere il menu sempre visibile). In più: il dettaglio articolo non taglia più il contenuto su smartphone e in coda e dettaglio trovi il bottone «Vedi bozza WordPress» per aprire anteprima o editor del post collegato. Nessuna migrazione di schema, nessun setting nuovo.

= 1.1.2 =
«Multilingua nativo»: le traduzioni AI diventano post WordPress veri e collegati tra loro (permalink, revisioni e SEO propri per ogni lingua) con driver automatico Polylang, WPML o collegamento interno Nexus via post meta. Niente migrazione di schema: le traduzioni sono post nativi + post meta, l'aggiornamento è tutto WordPress e nessun contenuto viene toccato (la tabella storico wp_nexus_translations resta come storico/costi e i post tradotti sopravvivono alla disinstallazione, come tutti i contenuti nativi). Nuovo shortcode [nexus_lang_switcher], attributo lang per gli shortcode e API pubblica, hreflang e og:locale automatici. Se vuoi che le traduzioni si pubblichino da sole dopo ogni articolo, attiva «Pubblica automaticamente le traduzioni come post» in Impostazioni → Intelligenza artificiale → Multilingua.

= 1.1.1 =
«Redazioni gerarchiche»: organizza la testata in redazioni e sub-redazioni annidate con gli agenti assegnati ai rami (es. Sport → Calcio/Basket/F1), archivi pubblici /redazione/{slug}/ e editori scoped al proprio ramo. Nessuna migrazione di schema e nessun setting nuovo: la funzione è tutta WordPress nativa — la tassonomia nexus_redazione viene creata automaticamente all'aggiornamento e i termini sopravvivono al plugin come categorie e post.

= 1.1.0 =
«Tutto nativo»: i contenuti nuovi diventano post WordPress nativi con autore bot (byline e pagina autore reali del tema), categorie e tag della testata; la migrazione 1-click in Strumenti converte i vecchi articoli CPT con barra di progresso e i permalink legacy /nexus/{slug}/ restano validi. Nessun setting nuovo: solo l'opzione post_status guadagna «In attesa di revisione» (pending). L'aggiornamento aggiunge due colonne a wp_nexus_agents e registra il ruolo «Giornalista AI» per gli utenti bot degli agenti.

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
