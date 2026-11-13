=== Nexus News AI ===
Contributors: ai4wp
Tags: ai, news, magazine, rss, agents
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.34
License: MIT
License URI: https://opensource.org/licenses/MIT

Agenti giornalisti AI che raccolgono, valutano, riscrivono e pubblicano notizie come post WordPress nativi, con la tua approvazione.

== Description ==

Nexus News AI costruisce una redazione di agenti giornalisti AI dentro WordPress. Ogni agente ha nome, categoria, personalità e modello AI propri; tu scegli le fonti, le regole di qualità e il livello di autonomia, e la redazione lavora da sola tramite WP-Cron.

Il plugin parte da zero agenti: il wizard «Crea la tua redazione» propone pacchetti pronti (Testata generalista, News veloci, Solo Tecnologia, Agenzia completa) oppure la creazione manuale. Ogni agente creato resta modificabile e clonabile.

= La pipeline editoriale =

1. Raccolta — gli agenti leggono le fonti configurate e preparano le bozze con una selezione fatta dal modello AI.
2. Valutazione — ogni notizia riceve un punteggio di qualità da 0 a 100.
3. Riscrittura — il modello riscrive le notizie migliori nello stile editoriale dell'agente, con fact-check e controllo anti-duplicato.
4. Approvazione — in modalità semi-autonoma l'articolo attende un revisore umano nella Coda Approvazioni; in modalità autonoma viene pubblicato da solo se supera la soglia di qualità. Il Capo Redattore AI può approvare, correggere o rifiutare gli articoli.
5. Pubblicazione — l'articolo diventa un post WordPress nativo con autore bot, categoria e tag reali, immagine in evidenza e SEO automatico. Se configurata, parte anche la distribuzione su Telegram, Discord, X e LinkedIn; i canali non configurati vengono saltati con un log onesto.

= Cosa puoi configurare =

* Redazioni gerarchiche — organizza la testata in redazioni e sub-redazioni (es. Sport, con Calcio, Basket e F1), con archivi pubblici /redazione/{slug}/ che includono i rami figli.
* Fonti per redazione — 8 tipi di fonte: RSS, siti web, YouTube, Telegram, Reddit, ricerche web, server MCP e pagine singole. Una redazione senza fonti proprie usa le fonti RSS globali.
* Provider e modello per agente — 10 provider AI integrati (OpenRouter, OpenAI, Anthropic, Google, Groq, DeepSeek, Mistral, xAI, Ollama e provider custom illimitati), più chiavi multiple con rotazione e provider dedicato per ogni agente.
* Strumenti di ricerca — ricerca web, lettura pagine, scoperta feed e client MCP disponibili agli agenti durante la scrittura.
* Multilingua — le traduzioni AI diventano post WordPress collegati, con Polylang o WPML se presenti, hreflang e og:locale automatici.
* Autonomia e controllo — modalità semi-autonoma o completamente autonoma, budget AI mensile con avviso o blocco, cap giornaliero, coda lavori con retry e dead letter.
* Newsletter — iscrizioni con double opt-in e digest settimanale.
* Indicizzazione — IndexNow, WebSub, Google News sitemap e sitemap immagini/video.
* Contenuti nativi — tutti gli articoli sono post WordPress veri: permalink, revisioni, SEO e pagina autore del tema.

= Nel pannello admin =

* Dashboard — stato del sistema, prossima azione suggerita, checklist dei primi passi, statistiche e pannello live «In redazione ora».
* Agenti — creazione, modifica, clonazione, avvio singolo e diagnostica per agente.
* Redazioni — albero gerarchico con agenti e fonti assegnati.
* Coda Approvazioni — articoli da rivedere, approvare o rifiutare.
* Attività e Coda Lavori — log e stato dei job di raccolta, scrittura e pubblicazione.
* Analytics — serie giornaliere, costi AI, performance da Google Search Console e GA4, opportunità di ricerca.
* Calendario editoriale — articoli programmati e slot di pubblicazione.
* Impostazioni — una pagina a tab (Contenuti, AI, Pubblicazione, Sistema) con modalità Semplice e Avanzata.

= Nel sito =

Shortcode disponibili:

* [nexus_magazine] — magazine con hero, categorie, griglia articoli, modal di lettura e reazioni.
* [nexus_articles category="Tecnologia" limit="6" layout="list"] — lista di articoli filtrabile.
* [nexus_subscribe] — form newsletter.
* [nexus_lang_switcher] — selettore delle traduzioni.
* [nexus_wire] — redazione collettiva multi-agente.

Sono disponibili anche i blocchi Gutenberg «Ultimi articoli» e «Form newsletter», le API REST nexus/v1 (admin) e nexus-public/v1 (pubblica, con chiave API) e i comandi WP-CLI wp nexus.

= Requisiti =

* WordPress 6.0 o superiore.
* PHP 7.4 o superiore.
* Nessuna dipendenza esterna: solo PHP puro e JavaScript vanilla, senza framework da compilare.
* Una chiave AI compatibile OpenAI/OpenRouter per generare gli articoli. Non serve per installare il plugin o esplorare il pannello.

== Installation ==

1. Carica la cartella nexus-news-ai-wp in wp-content/plugins/, oppure installa lo ZIP da Plugin → Aggiungi nuovo → Carica plugin.
2. Attiva il plugin: vengono create le tabelle wp_nexus_* e il ruolo «Giornalista AI»; la redazione parte da zero agenti.
3. Apri Nexus News → Impostazioni → AI e inserisci la chiave del provider, oppure lascia che il wizard te la chieda al primo avvio.
4. Imposta un cron di sistema che richiama wp-cron.php ogni 5-15 minuti: WP-Cron scatta solo quando qualcuno visita il sito.
5. Crea una pagina e inserisci lo shortcode [nexus_magazine].

== Frequently Asked Questions ==

= Serve una chiave AI per usare il plugin? =

Sì, se vuoi che la redazione produca articoli: ogni fase della pipeline, raccolta inclusa, chiama il modello. La chiave si imposta in Impostazioni → AI o nel primo passo del wizard. Per installare il plugin, configurare il pannello e usare magazine, shortcode e REST API non serve alcuna chiave.

= Dove finiscono gli articoli generati? =

Durante raccolta, valutazione e scrittura l'articolo esiste solo dentro Nexus, nella tabella interna wp_nexus_articles. Gli articoli in attesa di revisione stanno nella Coda Approvazioni: è il flusso normale. Il post WordPress nasce alla pubblicazione finale e il suo stato dipende da Impostazioni → Pubblicazione (bozza, in attesa di revisione o pubblicato).

= L'agente non produce nulla, cosa controllo? =

In ordine di frequenza: fonti configurate (redazione o fonti RSS globali), chiave di ricerca web se usi fonti di tipo search, cap giornaliero raggiunto, modalità semi-autonoma con articoli fermi in coda, cron di sistema non configurato. Pagina Attività, card dell'agente e pannello diagnostico mostrano gli ultimi errori.

= Come funziona il cron? =

Il plugin usa WP-Cron con l'evento nexus_cron_tick. WP-Cron è «pigro»: scatta solo quando qualcuno visita il sito, quindi su un sito con poco traffico i tick possono ritardare di ore. Per una redazione costante imposta un cron di sistema che chiama wp-cron.php a intervalli regolari, disattivando WP_CRON per evitare doppi scatti.

= Cosa succede se disattivo o disinstallo il plugin? =

La disattivazione conserva tabelle, articoli, agenti e impostazioni. La disinstallazione rimuove le tabelle wp_nexus_*, i dati del plugin, gli utenti bot e gli eventi cron; restano nel sito i contenuti nativi (post pubblicati, redazioni e traduzioni), per design.

== Changelog ==

= 1.1.34 =
* UI: sistema di design tokens condivisi (assets/css/nexus-tokens.css, prefisso --nxa-), stati vuoti/error/loading coerenti (nexus-ux.css con skeleton shimmer e CTA), focus-visible globale WCAG AA, miglioramento contrasto e responsive admin (griglie 2→1 colonna).
* Onboarding: nuovo endpoint POST /pipeline/test-article per generare un primo articolo di prova reale + bottone "Genera il primo articolo" nel wizard.
* Diagnostica agenti: nuovi verdict ai_key_missing e pending_in_queue con messaggi azionabili.
* Coda Approvazioni: filtro multiplo (stato/fonte/agente), azioni batch, anteprima inline per riga.
* Analytics: costo medio AI per articolo, produttività per fonte, andamento qualità nel tempo.
* Multilingua: hreflang coerente (link_set, x-default), endpoint /multilang/coverage, metodi copertura/traduzioni mancanti.
* Automazione: nuova classe Nexus_Automation con cruscotto tick (ultimo/prossimo/ritardo), retry con backoff esponenziale, alert redazione ferma (default 12h).
* Notifiche: canale Discord aggiunto e digest giornaliero di attività.
* Sicurezza: verifica cifratura at-rest chiavi, route nuove con capability check e nonce, segreti sempre mascherati nelle risposte REST.

= 1.1.33 =
* UI: barra dei tab in Impostazioni ripulita — rimossa la banda grigia piena e la linea orizzontale a tutta larghezza; neutralizzati pseudo-elementi spurii (menu kebab/frecce) nella fascia tab; nascosto il badge "non salvato" dentro le tab (resta il banner funzionale nel pannello); eliminata la scrollbar verticale spurie nella tablist (overflow-y hidden, scrollbar orizzontale sottile e trasparente). Solo CSS, nessun dato toccato.

= 1.1.32 =
* New: API pubblica esterna nexus-public/v1 — endpoint sources (fonti configurate) e schedule (programmazione, gated Pro); autenticazione con chiave API (hash SHA-256), header X-Nexus-Key, rate limit 120/min; documentazione docs/API-PUBLIC.md.
* UI: polish completo — bordi più visibili su card/sezioni/tabelle/campi (token bordo più scuro), focus states più chiari, spaziatura corretta tra titolo e barra tab in Impostazioni con barra sticky e più respiro.

= 1.1.31 =
* New: ingest multi-formato — supporto XML, JSON, CSV e HTML oltre RSS (parser Nexus_Ingest, SSRF guard, dedup, endpoint test /sources/test-ingest).
* New: MCP server — integrazione con agenti esterni (Claude/ChatGPT) via discovery /mcp/tools e /mcp/call; strumenti status/articoli/programmazione/impostazioni (gated Pro, docs/MCP.md).
* New: SEO avanzato — meta keywords da categoria+tag, schema JSON-LD configurabile, TOC opzionale, <news:keywords> in sitemap news.
* Media: selettore immagine anteprima negli articoli (carica/rigenera).
* Automazione: workflow visuali a regole (condizione Fonte/Categoria/Punteggio/Stato → azione Approva/Programma/Pubblica/Notifica).

= 1.1.30 =
* New: auto-pubblicazione a soglia — toggle che approva e pubblica automaticamente gli articoli la cui valutazione AI supera la soglia, anche in modalità semi-autonoma.
* New: notifiche — canale email (wp_mail) e Telegram (chat OPS), configurabili via endpoint admin /nexus/v1/notify/channels.
* New: gestione fonti RSS migliorata — test feed con validazione e conteggio (endpoint /sources/test-feed), dedup per hash titolo, filtri per categoria (inclusione/esclusione).
* UI: azioni Annulla/Riprogramma sugli articoli pianificati, stato canali notifiche, toggle dedup e filtro categoria per fonte, editor visuale dei prompt AI (rewrite/collect/fact-check).

= 1.1.29 =
* New: programmazione della pubblicazione — pianifica un articolo in data/ora futura (coda), via endpoint /nexus/v1/schedule e WP-Cron.
* New: statistiche avanzate in dashboard — articoli pubblicati/mese, errori AI recenti, costo API stimato per articolo (endpoint /nexus/v1/stats).
* UI: selettore "Programma pubblicazione", vista coda con filtro "Programmati", card statistiche con mini-grafico.

= 1.1.25 =
* Fixed: promozione a pending_approval per articoli sopra soglia che non veniva più saltata dal time budget (il budget resta applicato solo alle azioni con costi AI/rete).
* Rimossi i log diagnostici temporanei di debug della soglia.

= 1.1.24 =
* Fixed: promozione draft → pending_approval per articoli sopra soglia (rimozione del «continue» post-riscrittura che bloccava la catena di promozione).

= 1.1.23 =
* Fixed: soglia auto_approve_score ora coerente — sopra soglia si promuove a pending_approval, sotto soglia resta in bozza.
* Fixed: rimozione dei BOM UTF-8 che corrompevano le risposte REST.
* Fixed: polyfill i18n per gli harness di test standalone (quality gate CI).

= 1.1.22 =
* Qualità contenuti AI: istruzioni linguistiche rafforzate nei prompt (italiano corretto, niente refusi) e fase di polish leggera su titolo/riassunto prima del salvataggio, senza chiamate AI aggiuntive.

= 1.1.21 =
* Sicurezza: le route REST pubbliche espongono solo i dati strettamente necessari (whitelist); /public/wire/runs protetta da capability admin.
* Cifratura at-rest delle chiavi API/integrazioni (AES-256, chiave da AUTH_KEY) con fallback legacy.
* Transazioni DB atomiche per le sequenze multi-scrittura della pipeline.
* Prestazioni: transient caching per dashboard/stats; autoloader al posto dei require_once eager; eliminazione di query N+1; sitemap news/video non bloccanti (query snelle + cache).

= 1.1.20 =
* Restyling editoriale di pannello admin e sito pubblico: superfici neutre, un solo accento blu, titoli in serif e componenti piatti, senza gradienti né effetti decorativi.
* Copy riscritto in linguaggio di redazione: «batch» diventa «gruppi», «dead letter» diventa «lavori bloccati», «Scala & Pro» diventa «Coda e prestazioni»; preset agenti con nomi di sezione.
* Firme degli articoli con iniziale del giornalista al posto delle emoji, disclosure AI sobria e fact-check compatto con dettagli espandibili.
* Voce editoriale condivisa per riscrittura, Nexus Wire e digest: titoli di circa 70 caratteri, lede di 25-40 parole e divieto di frasi fatte. Nuovi flag diagnostici non bloccanti per frasi cliché, aperture generiche e formule da assistente.
* Digest: la sezione uso AI è disattivata di default perché dato operativo interno.
* Corretto il testo dei bottoni-link primari che poteva diventare invisibile per una regola CSS troppo generica.

= 1.1.19.3 =
* Autore del plugin aggiornato a ai4wp (header, readme e copyright di LICENSE/POT).

= 1.1.19.2 =
* Nuova palette UI: rimossi gli accenti indaco/viola, ora le sfumature vanno dal blu scuro all'azzurro in pannello admin, frontend, email e categoria Scienza.

= 1.1.19.1 =
* Correzioni emerse dalla revisione approfondita della 1.1.19.0.
* Wizard: lo Step 0 «Collega il provider AI» ora controlla la chiave del canale globale effettivamente usato dagli agenti creati dal wizard (prima poteva essere saltato con una chiave per-provider, lasciando i nuovi agenti senza AI).
* Dashboard: la checklist «Fonti RSS» considera anche le fonti delle singole redazioni; lo stato raccolta automatica distingue attiva, in ritardo e in pausa; la pill «Coda» e la prossima azione sono tradotte per intero anche in inglese.
* Impostazioni: in modalità Semplice la pagina Sistema si apre su una sezione visibile; le tab non escono più dal contenitore su schermi stretti; il toggle avanzate mostra il conteggio del tab attivo.
* Wizard: dopo il salvataggio della chiave lo Step 0 non ricompare nella stessa sessione.

= 1.1.19.0 =
* Onboarding guidato: nuovo Step 0 del wizard «Collega il provider AI» per chi non ha ancora una chiave.
* Dashboard operativa: strip «Stato del sistema», card «Prossima azione suggerita» e checklist «Primi passi» con dismiss per utente.
* Impostazioni: modalità Semplice di default con toggle «Mostra sezioni avanzate»; nessuna opzione rimossa.
* Ereditarietà visibile: agenti e redazioni indicano quale valore globale stanno usando quando un campo è vuoto.

Le note delle versioni precedenti sono disponibili nella cronologia del repository.
