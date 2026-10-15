<?php
/**
 * Dizionario i18n EXTRA (v1.0.5) — admin bilingue IT/EN.
 *
 * CONTRATTO v1.0.5 §2 T-08 — dizionario esteso del motore Nexus_I18n.
 *
 * Scopo: completare il dizionario base (class-nexus-i18n-dict.php, funzione
 * nexus_i18n_default_dict() di T-01) con ALMENO 200 termini IT => EN extra,
 * scansionando le viste admin (dashboard, agents, queue, activity, wire,
 * settings, jobs, analytics, tools), assets/js/admin.js e assets/js/blocks.js.
 *
 * Caricamento: il file viene incluso automaticamente da Nexus_I18n::dictionary()
 * (se il file esiste viene caricato con require_once e mergiato; le voci EXTRA
 * hanno la precedenza sul dizionario base) e da Nexus_I18n::enqueue(), che passa
 * i pattern a i18n.js come NexusI18n.patterns. Il file NON registra filtri e non
 * definisce altro: SOLO le due funzioni qui sotto + questo docblock.
 *
 * v1.1.6 (T-01, contratto §2): completamento dell'audit esauriente (viste,
 * admin.js, caps/cpt) + terza mappa nexus_i18n_extra_patterns() per i messaggi
 * di log con parti variabili (regex → template EN con $1/$2).
 *
 * Regole rispettate:
 * - la chiave è la stringa ESATTA presente nel codice (stessa punteggiatura,
 *   stessi apostrofi tipografici/dritti, stessa capitalizzazione);
 * - i placeholder (%%s, %%d) e i numeri restano identici nella traduzione;
 * - niente emoji, niente HTML: solo testo;
 * - solo UI admin (viste, admin.js, blocks.js, menu): nessuna stringa di
 *   frontend, template, social o pipeline;
 * - traduzioni in inglese naturale e corretto;
 * - voci ordinate alfabeticamente per chiave.
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dizionario extra IT => EN per Nexus_I18n (vince sul dizionario base).
 *
 * @return array<string,string> Mappa stringa italiana esatta => traduzione inglese.
 */
function nexus_i18n_extra_dict() {
        return array(
            ' (cache)' => ' (cache)',
            ' articoli convertiti' => ' articles converted',
            // NOTA v1.1.7 (T-C): la chiave frammentaria con la sola preposizione
            // "di" (con spazi laterali) mappata su "of" e' stata RIMOSSA. Era il
            // colpevole del "Frankenstein" in e2e: lo strtr su tutto l'HTML
            // sostituiva la preposizione DENTRO la tagline utente del sito
            // ("Il giornale di nuova generazione" diventava "Il giornale of
            // nuova generazione"). Le chiavi del dizionario devono essere FRASI
            // INTERE della UI, mai parole/preposizioni generiche; il contenuto
            // utente non va tradotto (v. Nexus_I18n::translate_html).
            ' Gli articoli programmati a più di %d giorni da oggi non vengono spostati.' => ' Articles scheduled more than %d days from today are not moved.',
            '%1$d fonti RSS monitorate: nessuna ferma da oltre %2$d giorni.' => '%1$d RSS sources monitored: none stalled for more than %2$d days.',
            '%1$d fonti RSS senza nuovi articoli da %2$d+ giorni:' => '%1$d RSS sources with no new articles for %2$d+ days:',
            '%1$d lavori "running" da oltre %2$d ore.' => '%1$d jobs "running" for more than %2$d hours.',
            '%1$d lavoro "running" da oltre %2$d ore.' => '%1$d job "running" for more than %2$d hours.',
            '%1$d righe su %2$d articoli' => '%1$d rows across %2$d articles',
            '%1$s — %2$d righe (%3$s)' => '%1$s — %2$d rows (%3$s)',
            '%d approvati da pubblicare' => '%d approved for publishing',
            '%d approvato da pubblicare' => '%d approved for publishing',
            '%d bozza in lavorazione' => '%d draft in progress',
            '%d bozze in lavorazione' => '%d drafts in progress',
            '%d in attesa di approvazione' => '%d awaiting approval',
            '%d lavori in coda' => '%d jobs in queue',
            '%d lavoro in coda' => '%d job in queue',
            '%d riga scartata (orario non valido: inizio prima della fine?).' => '%d discarded row (invalid time: start before end?).',
            '%d righe scartate (orario non valido: inizio prima della fine?).' => '%d discarded rows (invalid time: start before end?).',
            '%s eventi WP-Cron verificati: tutto in orario.' => '%s WP-Cron events checked: all on time.',
            '%s evento WP-Cron verificato: tutto in orario.' => '%s WP-Cron event checked: all on time.',
            '(dalla cache, 12 ore)' => '(from cache, 12 hours)',
            '(senza titolo)' => '(untitled)',
            '0 agenti' => '0 agents',
            '0 = nessun limite. Le stime si basano sui token usati.' => '0 = no limit. Estimates are based on the tokens used.',
            '1 agente' => '1 agent',
            '1 articolo in coda' => '1 article in queue',
            '1 giorno fa' => '1 day ago',
            // v1.1.11 (R3): conteggi dei pacchetti preset del wizard onboarding.
            '3 agenti' => '3 agents',
            '5 agenti' => '5 agents',
            '7 agenti + capo redattore' => '7 agents + chief editor',
            '1 ora fa' => '1 hour ago',
            // v1.1.13.1 (Q3): placeholder del campo Chat Telegram ops (settings-pubblicazione.php).
            '@ops o -1001234567890' => '@ops or -1001234567890',
            // v1.1.11 (R2): tab della pagina Impostazioni unica (settings.php).
            // 'AI' è identica in EN: la sostituzione è identità, nessun rischio
            // di corruzione strtr (chiave = traduzione).
            'Access token OAuth 2.0 con permesso w_member_social (mascherato).' => 'OAuth 2.0 access token with the w_member_social permission (masked).',
            'Accesso negato dall\'API Search Console (403): verifica che l\'API sia abilitata in Google Cloud Console e che l\'utente abbia accesso alla property.' => 'Access denied by the Search Console API (403): check that the API is enabled in Google Cloud Console and that the user has access to the property.',
            'Agente non collegato' => 'Agent not linked',
            'Agente non trovato: aggiorna la pagina e riprova.' => 'Agent not found: reload the page and try again.',
            'Agenti:' => 'Agents:',
            'aggiungi slot (es. 09:00–11:00)' => 'add slot (e.g. 09:00–11:00)',
            'aggiungi slot (es. 17:00–19:00)' => 'add slot (e.g. 17:00–19:00)',
            'AI' => 'AI',
            'Access secret X' => 'X access secret',
            'Access token LinkedIn' => 'LinkedIn access token',
            'Access token OAuth 1.0a dell’account che pubblica.' => 'OAuth 1.0a access token of the publishing account.',
            'Access token OAuth 2.0 con permesso w_member_social.' => 'OAuth 2.0 access token with w_member_social permission.',
            'Access token secret OAuth 1.0a dell’account.' => 'OAuth 1.0a access token secret of the account.',
            'Access token X' => 'X access token',
            'Adesso' => 'Just now',
            'Agente AI che copre politica italiana ed estera con contesto storico.' => 'AI agent covering Italian and international politics with historical context.',
            'Agente AI specializzato in tecnologia, AI, gadget e innovazione.' => 'AI agent specialized in technology, AI, gadgets and innovation.',
            'Agente AI su calcio, sport nazionali e internazionali.' => 'AI agent for football, national and international sports.',
            'Agente AI su cinema, libri, musica e eventi culturali.' => 'AI agent for cinema, books, music and cultural events.',
            'Agente AI su mercati, imprese e finanza personale.' => 'AI agent for markets, business and personal finance.',
            'Agente AI su salute, benessere e medicina basata su evidenze.' => 'AI agent for health, wellness and evidence-based medicine.',
            'Agente clonato: nasce in pausa.' => 'Agent cloned: it starts paused.',
            // v1.1.7 (T-C2): toast di eliminazione agente (admin.js bindAgents, DELETE /agents/{id}).
            'Agente eliminato.' => 'Agent deleted.',
            'Agente messo in pausa.' => 'Agent paused.',
            'Agente non trovato: ricarica la pagina.' => 'Agent not found: reload the page.',
            'Agente riattivato.' => 'Agent reactivated.',
            'Agente salvato.' => 'Agent saved.',
            'Agenti attivi' => 'Active agents',
            'Agenti giornalisti' => 'Journalist agents',
            'Agenti senza categoria nativa' => 'Agents without a native category',
            'Agenti senza utente WP' => 'Agents without a WP user',
            // v1.1.11 (R3): wizard onboarding — pacchetti preset.
            'Agenzia completa' => 'Full agency',
            'Aggiungi un capo redattore AI' => 'Add an AI chief editor',
            'Aggiorna' => 'Refresh',
            'Aggiorno…' => 'Refreshing…',
            'Alcuni giorni mostrano al massimo 30 articoli: l\'elenco è troncato per leggibilità.' => 'Some days show at most 30 articles: the list is truncated for readability.',
            'Ambiente' => 'Environment',
            'Analytics' => 'Analytics',
            'Annullati' => 'Cancelled',
            'Anteprima del digest inviata: controlla la tua casella email.' => 'Digest preview sent: check your inbox.',
            'Anteprima non disponibile: aggiorna WordPress o controlla la pagina pubblicata.' => 'Preview not available: update WordPress or check the published page.',
            'API key dell’app dal Developer Portal di X.' => 'App API key from the X Developer Portal.',
            'API key X' => 'X API key',
            'API pubblica' => 'Public API',
            'API secret dell’app (consumer secret).' => 'App API secret (consumer secret).',
            'API secret X' => 'X API secret',
            'Applica' => 'Apply',
            'Approva' => 'Approve',
            'Approvati' => 'Approved',
            'Approvazione non riuscita' => 'Approval failed',
            'Approvo…' => 'Approving…',
            'Apri il wizard' => 'Open the wizard',
            'Apri Impostazioni → Sistema' => 'Open Settings → System',
            'Apri la Coda Approvazioni' => 'Open the Approval Queue',
            'Apri la Coda Lavori' => 'Open the Jobs Queue',
            'Argomenti mancanti' => 'Missing topics',
            'Articoli degli ultimi N giorni confrontati dal dedup semantico (7-730, predefinito 90).' => 'Articles from the last N days compared by the semantic dedup (7-730, default 90).',
            'Articoli massimi al giorno' => 'Max articles per day',
            'Articoli massimi confrontati' => 'Maximum articles compared',
            'Articoli Nexus da migrare' => 'Nexus articles to migrate',
            'Articoli più visti' => 'Most viewed articles',
            'Articoli programmati giorno per giorno, slot orari di pubblicazione e suggerimento orari basato sulle tue pubblicazioni passate.' => 'Day-by-day scheduled articles, publishing time slots and time suggestions based on your past publishing.',
            'Articoli proposti dagli agenti: valuta qualità e fonte, poi approva, rifiuta o pubblica.' => 'Articles proposed by the agents: review quality and source, then approve, reject or publish.',
            'Articoli pubblicati' => 'Published articles',
            'Articolo' => 'Article',
            'Articolo approvato.' => 'Article approved.',
            'Articolo già pubblicato in precedenza.' => 'Article already published before.',
            'Articolo non trovato' => 'Article not found',
            'Articolo pubblicato: log multi-piattaforma generati.' => 'Article published: multi-platform logs generated.',
            'Articolo rifiutato.' => 'Article rejected.',
            'Assegna' => 'Assign',
            'Assegnazione completata.' => 'Assignment completed.',
            'Assegnazione in corso…' => 'Assignment in progress…',
            'Assegnazione non riuscita.' => 'Assignment failed.',
            'Attendi…' => 'Please wait…',
            'Attenzione' => 'Warning',
            'ATTENZIONE: il file contiene chiavi API e token: conservalo in modo sicuro.' => 'WARNING: the file contains API keys and tokens: keep it safe.',
            'Attiva' => 'Activate',
            'Attiva API pubblica (nexus-public/v1)' => 'Enable public API (nexus-public/v1)',
            'Attiva il plugin per creare i 7 agenti predefiniti.' => 'Activate the plugin to create the 7 default agents.',
            'Attiva JavaScript per avviare la redazione collettiva e visualizzare lo storico.' => 'Enable JavaScript to start the collective newsroom and view the history.',
            'Attiva JavaScript per visualizzare lo storico delle attività.' => 'Enable JavaScript to view the activity history.',
            'Attiva newsletter e digest settimanale' => 'Enable newsletter and weekly digest',
            'Attiva o disattiva l\'API in Impostazioni → Lingua e API pubblica.' => 'Enable or disable the API in Settings → Language and public API.',
            'Attivala con "Sincronizza GSC e GA4 una volta al giorno" in Impostazioni → Sistema.' => 'Enable it with "Sync GSC and GA4 once a day" in Settings → System.',
            'Attività per agente' => 'Activity by agent',
            'Attivo' => 'Active',
            'Author URN LinkedIn' => 'LinkedIn author URN',
            'Auto-aggiornamento (15s)' => 'Auto-refresh (15s)',
            'Automatica (segue la lingua del sito)' => 'Automatic (follows the site language)',
            'Autore WP attivo' => 'Active WP author',
            'Avatar (emoji)' => 'Avatar (emoji)',
            'Avvia la prima redazione collettiva qui sopra.' => 'Start the first collaborative run above.',
            'Avvia migrazione' => 'Start migration',
            'Avvia redazione collettiva' => 'Start collaborative newsroom',
            'Azione' => 'Action',
            'Azioni' => 'Actions',
            'Benvenuto: parti con il pacchetto di agenti che vuoi. Potrai modificare, aggiungere o eliminare agenti in ogni momento.' => 'Welcome: start with the agent package you prefer. You can edit, add or remove agents at any time.',
            'Blog (WordPress)' => 'Blog (WordPress)',
            'Bozze' => 'Drafts',
            'Budget AI' => 'AI budget',
            'Budget mensile AI (USD)' => 'Monthly AI budget (USD)',
            'Budget mensile esaurito (%s%%).' => 'Monthly budget exhausted (%s%%).',
            'Calendario' => 'Calendar',
            'Calendario editoriale' => 'Editorial calendar',
            'Calendario mensile degli articoli programmati' => 'Monthly calendar of scheduled articles',
            'Candidati link interni per giro' => 'Internal link candidates per run',
            'Carica modelli' => 'Load models',
            'Carica un file JSON esportato da Nexus News AI per ripristinare impostazioni e agenti.' => 'Upload a JSON file exported by Nexus News AI to restore settings and agents.',
            'Caricamento agenti…' => 'Loading agents…',
            'Caricamento articoli…' => 'Loading articles…',
            'Caricamento attività…' => 'Loading activity…',
            'Caricamento dello storico…' => 'Loading history…',
            'Caricamento dettaglio…' => 'Loading details…',
            'Caricamento lavori…' => 'Loading jobs…',
            'Caricamento lingue…' => 'Loading languages…',
            'Caricamento modelli fallito' => 'Model loading failed',
            'Caricamento uso AI…' => 'Loading AI usage…',
            'Carico…' => 'Loading…',
            'Categoria (vuoto = tutte)' => 'Category (empty = all)',
            'Chat ID Telegram' => 'Telegram chat ID',
            'Chat Telegram ops' => 'Telegram ops chat',
            'Chat Telegram ops per le notifiche interne (vuoto = canale social attuale).' => 'Telegram ops chat for internal notifications (empty = current social channel).',
            'Chiamate AI' => 'AI calls',
            'Chiamate AI questa settimana: %1$d. Token totali: %2$s (richiesta %3$s + risposta %4$s).' => 'AI calls this week: %1$d. Total tokens: %2$s (request %3$s + response %4$s).',
            'Chiamate, token e costo stimato della settimana (quando tracciati). Nessuna chiamata nella settimana: sezione omessa.' => 'Calls, tokens and estimated cost for the week (when tracked). No calls in the week: section omitted.',
            'Chiaro (consigliato)' => 'Light (recommended)',
            'Chiave API AI' => 'AI API key',
            'Chiave API corrente' => 'Current API key',
            'Chiave API del provider AI (OpenRouter o endpoint compatibile OpenAI). Salvata nel database del sito, mai mostrata in chiaro.' => 'AI provider API key (OpenRouter or OpenAI-compatible endpoint). Stored in the site database, never shown in plain text.',
            'Chiave IndexNow' => 'IndexNow key',
            'Chiudi' => 'Close',
            'Chiudi finestra' => 'Close window',
            'Chiudi notifica' => 'Close notification',
            'Citazioni verificate' => 'Verified quotes',
            'Cinque giornalisti pronti: tecnologia, politica, economia, sport e cultura.' => 'Five ready journalists: technology, politics, economy, sports and culture.',
            'Click e viste dei migliori articoli (Search Console / Analytics 4). Se i dati non ci sono ancora, la sezione è omessa.' => 'Clicks and views of the top articles (Search Console / Analytics 4). If the data is not there yet, the section is omitted.',
            'Click giorno 0' => 'Clicks (day 0)',
            'Clona' => 'Clone',
            'Clonazione non riuscita' => 'Cloning failed',
            'Clono…' => 'Cloning…',
            'Coda aggiornata' => 'Queue updated',
            'Click' => 'Clicks',
            'Coda Approvazioni' => 'Approval queue',
            'Coda di approvazione' => 'Approval queue',
            'Coda Lavori' => 'Jobs queue',
            'Coda lavori' => 'Jobs queue',
            'Coda lavori non disponibile: impossibile creare il lavoro.' => 'Jobs queue not available: cannot create the job.',
            'Codici separati da virgola. Supportati: en, es, fr, de, pt, ar, zh, ja.' => 'Comma-separated codes. Supported: en, es, fr, de, pt, ar, zh, ja.',
            'Colonne' => 'Columns',
            'Completamente autonoma' => 'Fully autonomous',
            'Completata' => 'Completed',
            'Completati' => 'Completed',
            'Completato' => 'Completed',
            'Conferma rifiuto' => 'Confirm rejection',
            'Confermati' => 'Confirmed',
            'Configurata' => 'Configured',
            'Configurato' => 'Configured',
            'Configurazione AI' => 'AI configuration',
            'Connessioni:' => 'Connections:',
            'Conteggi per tipo di errori interni (attività e lavori falliti). Disattiva di default: gli errori interni non vengono mostrati agli iscritti.' => 'Counts by type of internal errors (activities and failed jobs). Off by default: internal errors are not shown to subscribers.',
            'Contenuti' => 'Content',
            'Contenuti e anteprima' => 'Content and preview',
            'Contenuti nativi' => 'Native content',
            'Continua' => 'Next',
            'Controlli eseguiti adesso.' => 'Checks run just now.',
            'Controllo non riuscito: riprova con "Ricontrolla ora".' => 'Check failed: try again with "Recheck now".',
            'Copia garantita' => 'Guaranteed copy',
            'Copiata' => 'Copied',
            'Costo AI' => 'AI cost',
            'Costo stimato (mese)' => 'Estimated cost (month)',
            'Costo stimato: %s USD.' => 'Estimated cost: %s USD.',
            'Crea gli agenti' => 'Create the agents',
            'Crea la tua redazione' => 'Create your newsroom',
            'Creati' => 'Created',
            'Creazione completata.' => 'Creation completed.',
            'Creazione del lavoro non riuscita: riprova.' => 'Job creation failed: try again.',
            'Creazione in corso…' => 'Creation in progress…',
            'Creazione completata con errori.' => 'Creation completed with errors.',
            'Cron' => 'Cron',
            'Cronologia del lavoro degli agenti: raccolte, valutazioni, riscritture e pubblicazioni.' => 'History of the agents\' work: collections, evaluations, rewrites and publications.',
            'CTR' => 'CTR',
            'da migrare' => 'to migrate',
            'Dati opportunità temporaneamente non disponibili (errore interno). Il resto della pagina resta funzionante.' => 'Opportunity data temporarily unavailable (internal error). The rest of the page keeps working.',
            'Dati performance temporaneamente non disponibili (errore interno). Il resto della pagina resta funzionante.' => 'Performance data temporarily unavailable (internal error). The rest of the page keeps working.',
            'Dalla 1.0.6 il pannello Nexus è chiaro di default; scegli Scuro per tornare all\'aspetto notturno della 1.0.5. Il tema si applica a tutte le pagine Nexus dopo il salvataggio.' => 'From 1.0.6 the Nexus panel is light by default; choose Dark to go back to the night look of 1.0.5. The theme applies to every Nexus page after saving.',
            'Dashboard' => 'Dashboard',
            'Descrizione' => 'Description',
            'Destinatari fissi' => 'Fixed recipients',
            'Dettagli' => 'Details',
            'Dettaglio articolo' => 'Article details',
            'Diagnostica AI' => 'AI diagnostics',
            'Diagnostica del sistema, esportazione e importazione della configurazione, gestione dell\'API pubblica.' => 'System diagnostics, configuration export and import, public API management.',
            'Digest AI' => 'AI digest',
            'Digest saltato: nessun articolo' => 'Digest skipped: no articles',
            'Digest saltato: nessun destinatario' => 'Digest skipped: no recipients',
            'Digest settimanale — contenuti e anteprima' => 'Weekly digest — content and preview',
            'Digest testuale generato con l\'AI' => 'Text digest generated with AI',
            'Dimensione immagini' => 'Image size',
            'Dimensione richiesta all\'API immagini del provider AI.' => 'Size requested from the AI provider image API.',
            'Disiscritti' => 'Unsubscribed',
            'Distribuzione' => 'Distribution',
            'Distribuzione delle pubblicazioni per ora del giorno' => 'Publishing distribution by hour of day',
            'Distribuzione sociale' => 'Social distribution',
            'Dopo N fallimenti il lavoro viene marcato come Fallito (default 3). Tra un tentativo e l\'altro il ritardo raddoppia (backoff esponenziale, max 1 ora).' => 'After N failures the job is marked as Failed (default 3). Between attempts the delay doubles (exponential backoff, max 1 hour).',
            'Due revisori stanno valutando la bozza…' => 'Two reviewers are evaluating the draft…',
            'e altre %s.' => 'and %s more.',
            'Economia: tassi e inflazione' => 'Economy: rates and inflation',
            'Editor agente' => 'Agent editor',
            'Editor responsabile sta fondendo le revisioni…' => 'Managing editor is merging the revisions…',
            'Edizione completata' => 'Edition completed',
            'Elenco statico in sola lettura.' => 'Read-only static list.',
            'Elezioni e politica digitale' => 'Elections and digital politics',
            'Eliminare i lavori completati, falliti o annullati più vecchi di 7 giorni?' => 'Delete completed, failed or cancelled jobs older than 7 days?',
            'Email che riceve sempre una copia del digest (vuoto = %s).' => 'Email that always receives a copy of the digest (empty = %s).',
            'Email fisse separate da virgola, destinatarie del digest oltre agli iscritti confermati.' => 'Comma-separated fixed email addresses that receive the digest in addition to confirmed subscribers.',
            'Endpoint compatibile OpenAI. Predefinito: https://openrouter.ai/api/v1 (OpenRouter).' => 'OpenAI-compatible endpoint. Default: https://openrouter.ai/api/v1 (OpenRouter).',
            'Endpoint: ' => 'Endpoint: ',
            'English' => 'English',
            'Errore' => 'Error',
            'Errore di rete: riprova.' => 'Network error: try again.',
            'Errore nel caricamento analytics' => 'Error loading analytics',
            'Errore sconosciuto' => 'Unknown error',
            'errore sconosciuto' => 'unknown error',
            'Errore: %2$s (%1$d volta)' => 'Error: %2$s (%1$d time)',
            'Errore: %2$s (%1$d volte)' => 'Error: %2$s (%1$d times)',
            'Errori della settimana' => 'Errors of the week',
            'Esegui la raccolta per generare nuovi articoli.' => 'Run a collection to generate new articles.',
            'Esegui pipeline completa' => 'Run full pipeline',
            // v1.1.7 (T-C): pannello "Test degli strumenti" (tools.php v1.1.7, T-B2).
            'Esegui test' => 'Run test',
            'Esempi: https://esempio.com/ (property URL) oppure sc-domain:esempio.com (property dominio).' => 'Examples: https://example.com/ (URL property) or sc-domain:example.com (domain property).',
            'Esempio di richiesta' => 'Request example',
            'Espone articoli pubblicati, agenti e statistiche via REST pubblica con autenticazione X-Api-Key (rate limit 120 richieste/minuto). Genera la chiave nella pagina Strumenti.' => 'Exposes published articles, agents and stats via a public REST API with X-Api-Key authentication (rate limit 120 requests/minute). Generate the key on the Tools page.',
            'Esporta configurazione' => 'Export configuration',
            'Esporta impostazioni e agenti in un file JSON. NON include articoli, iscritti o log.' => 'Export settings and agents to a JSON file. Does NOT include articles, subscribers or logs.',
            'Eventi WP-Cron' => 'WP-Cron events',
            'Evento' => 'Event',
            'evento senza tipo' => 'event without type',
            'Evergreen per giro settimanale' => 'Evergreen per weekly run',
            'Fact-check' => 'Fact-check',
            'Fact-check automatico con citazioni' => 'Automatic fact-check with quotes',
            'Fallita' => 'Failed',
            'Falliti' => 'Failed',
            'Feed attività' => 'Activity feed',
            'Feed RSS' => 'RSS feeds',
            'Ferma le fasi AI' => 'Stop AI phases',
            'Ferma le fasi AI: le fasi successive del tick vengono saltate. Solo avviso: registra un avviso nel feed attività e prosegui.' => 'Stop AI phases: the following phases of the tick are skipped. Warning only: log a warning in the activity feed and continue.',
            'File di configurazione JSON' => 'JSON configuration file',
            'File JSON non valido.' => 'Invalid JSON file.',
            'File JSON non valido: atteso un oggetto con le chiavi settings e agents.' => 'Invalid JSON file: an object with the settings and agents keys was expected.',
            'Filtra articoli per stato' => 'Filter articles by status',
            'Filtra lavori per stato' => 'Filter jobs by status',
            'Finestra dedup (giorni)' => 'Dedup window (days)',
            'Finestra link interni (giorni)' => 'Internal link window (days)',
            'Fonti' => 'Sources',
            'Fonti RSS di esempio (una URL per riga)' => 'Sample RSS sources (one URL per line)',
            'Fonti RSS salvate nelle impostazioni.' => 'RSS sources saved in the settings.',
            'Form di iscrizione alla newsletter della redazione AI.' => 'Newsletter signup form for the AI newsroom.',
            'ga4 %d righe' => 'ga4 %d rows',
            'Genera immagini in evidenza con l\'AI' => 'Generate featured images with AI',
            'Genera meta title/description con l\'AI' => 'Generate meta title/description with AI',
            'Genera nuova chiave' => 'Generate new key',
            'Generare una nuova chiave invalida la chiave attuale?' => 'Generating a new key will invalidate the current key?',
            'Generata automaticamente alla prima pubblicazione.' => 'Generated automatically on first publish.',
            'Generazione' => 'Generation',
            'Gestisci agenti' => 'Manage agents',
            'Giornalista AI' => 'AI Journalist',
            'Giorni di articoli candidati come link interni (7-730, predefinito 180).' => 'Days of articles eligible as internal links (7-730, default 180).',
            'Giro automatico non schedulato: verrà attivato al prossimo caricamento del plugin.' => 'Automatic run not scheduled: it will be enabled on the next plugin load.',
            'Gli agenti raccolgono notizie RSS reali della loro categoria, le valutano e le riscrivono con AI.' => 'The agents collect real RSS news in their category, evaluate it and rewrite it with AI.',
            'Gli agenti raccolgono notizie RSS reali della loro categoria, le valutano e le riscrivono con AI. Puoi creare, modificare e clonare gli agenti: i 7 agenti predefiniti restano modificabili.' => 'The agents collect real RSS news for their category, evaluate and rewrite it with AI. You can create, edit and clone agents: the 7 predefined agents remain editable.',
            'Gli agenti stanno scegliendo le fonti migliori…' => 'The agents are picking the best sources…',
            'Gli articoli compaiono qui quando il flusso di pubblicazione li programma (stato future). Nel frattempo puoi controllare la coda di approvazione e la coda lavori.' => 'Articles appear here when the publishing flow schedules them (future status). In the meantime you can check the approval queue and the jobs queue.',
            'Gli articoli nuovi nascono programmati dal flusso esistente: il calendario li riallinea agli slot entro %d minuti. Gli articoli già dentro uno slot non vengono toccati.' => 'New articles are scheduled by the existing flow: the calendar realigns them to the slots within %d minutes. Articles already inside a slot are left untouched.',
            'Gli articoli raccolti vengono valutati automaticamente dall\'AI (qualità e pertinenza editoriale).' => 'Collected articles are automatically evaluated by the AI (quality and editorial relevance).',
            'Gli articoli riscritti vengono pubblicati automaticamente, senza attesa umana.' => 'Rewritten articles are published automatically, without human review.',
            'Gli articoli valutati vengono riscritti in stile editoriale dalla redazione AI.' => 'Evaluated articles are rewritten in editorial style by the AI newsroom.',
            // v1.1.13.1 (Q3): badge connessioni (settings-sistema.php) — nome di servizio
            // identico in EN: sostituzione identità, stessa scelta di 'AI'.
            'Google' => 'Google',
            'Google ha risposto con un errore HTTP %d: riprova più tardi.' => 'Google responded with HTTP error %d: try again later.',
            'Google non collegato: controllo non applicabile.' => 'Google not connected: check not applicable.',
            'Google non raggiungibile (errore di rete o timeout): riprova tra qualche istante.' => 'Google unreachable (network error or timeout): try again in a moment.',
            'Google non è ancora connesso: collega Search Console nelle Impostazioni per raccogliere le search queries.' => 'Google is not connected yet: connect Search Console in Settings to collect the search queries.',
            'Google non è connesso: collega Search Console nelle Impostazioni (flusso OAuth) e riprova.' => 'Google is not connected: connect Search Console in Settings (OAuth flow) and try again.',
            'Google:' => 'Google:',
            'Griglia degli ultimi articoli della redazione AI.' => 'Grid of the latest articles from the AI newsroom.',
            'gsc %d righe' => 'gsc %d rows',
            'Hai modifiche non salvate in questa sezione.' => 'You have unsaved changes in this section.',
            'Health check redazione' => 'Newsroom health check',
            'I risultati compaiono dopo il primo giro del cron orario: nel frattempo puoi avviare i controlli subito col bottone qui sopra.' => 'Results appear after the first hourly cron run: in the meantime you can run the checks right away with the button above.',
            'ID' => 'ID',
            'ID della chat o del canale di destinazione (es. -1001234567890).' => 'ID of the target chat or channel (e.g. -1001234567890).',
            'Identificativo del modello (es. openai/gpt-4o-mini).' => 'Model identifier (e.g. openai/gpt-4o-mini).',
            'Identificativo del modello (es. openai/gpt-4o-mini). I modelli si caricano dal provider con la chiave salvata: automaticamente all\'uscita dal campo Chiave API AI oppure col bottone Carica modelli (cache 12 ore).' => 'Model identifier (e.g. openai/gpt-4o-mini). Models are loaded from the provider with the saved key: automatically when leaving the API key field or with the Load models button (12h cache).',
            'Identificativo del modello immagini del provider (es. gpt-image-1). Lascia vuoto per usare il modello predefinito.' => 'Provider image model identifier (e.g. gpt-image-1). Leave empty to use the default model.',
            'Identità del sito' => 'Site identity',
            'Il campo "query" è obbligatorio.' => 'The "query" field is required.',
            'Il collegamento si gestisce dal flusso OAuth esistente (Impostazioni → tab Sistema). Questa pagina è di sola lettura: non tocca il token.' => 'The connection is managed by the existing OAuth flow (Settings → System tab). This page is read-only: it never touches the token.',
            'Il lavoro degli agenti apparirà qui.' => 'The agents’ work will appear here.',
            'Il modulo di migrazione nativa non è disponibile: verifica che il plugin sia aggiornato.' => 'The native migration module is not available: make sure the plugin is up to date.',
            'Il sync usa la dimension "query" di Search Console: la pagina che copre ogni query non è disponibile, quindi gli argomenti mancanti sono le query con zero click. Ordinate per impressioni, poi query alfabetico.' => 'The sync uses the Search Console "query" dimension: the page covering each query is not available, so the missing topics are the queries with zero clicks. Sorted by impressions, then by alphabetical query.',
            'Immagini in evidenza AI' => 'AI featured images',
            'Import completato' => 'Import completed',
            'Import completato con errori' => 'Import completed with errors',
            'Importa configurazione' => 'Import configuration',
            'Impossibile aggiornare lo stato' => 'Unable to update the status',
            // v1.1.7 (T-C2): titolo del toast di errore per DELETE /agents/{id}
            // (admin.js apiErrorToast): apostrofo TIPOGRAFICO come nel sorgente JS.
            'Impossibile eliminare l’agente' => 'Unable to delete the agent',
            'Impossibile caricare gli agenti' => 'Unable to load the agents',
            'Impossibile caricare gli articoli' => 'Unable to load the articles',
            'Impossibile caricare la coda' => 'Unable to load the queue',
            'Impossibile caricare lo storico' => 'Unable to load the history',
            'Impossibile caricare l’articolo' => 'Unable to load the article',
            'Impossibile caricare l’attività' => 'Unable to load the activity',
            'Impossibile leggere le categorie WordPress.' => 'Unable to read WordPress categories.',
            'Impostazioni' => 'Settings',
            'Impostazioni blocco' => 'Block settings',
            'Impostazioni generali' => 'General settings',
            'Impostazioni salvate.' => 'Settings saved.',
            'In attesa (non ancora programmati)' => 'Pending (not scheduled yet)',
            'In attesa del primo controllo.' => 'Waiting for the first check.',
            'In attesa di revisione' => 'Pending review',
            'In corso' => 'Running',
            'In modalità autonoma, approva e pubblica oltre questa soglia (0-100).' => 'In autonomous mode, approve and publish above this threshold (0-100).',
            'In pausa' => 'Paused',
            'in ritardo di %s' => 'late by %s',
            'Includi gli errori della settimana' => 'Include the errors of the week',
            'Includi i top articoli della settimana' => 'Include the top articles of the week',
            'Includi il riepilogo uso AI' => 'Include the AI usage summary',
            'IndexNow' => 'IndexNow',
            'IndexNow (Bing e altri)' => 'IndexNow (Bing and others)',
            'Indicizzazione' => 'Indexing',
            'Indietro' => 'Back',
            'Info' => 'Info',
            'Informazioni di sistema' => 'System information',
            'Iniziato' => 'Started',
            'Inserisci un argomento di almeno 3 caratteri.' => 'Enter a topic of at least 3 characters.',
            // v1.1.7 (T-C2): guard input vuoto del pannello "Test degli strumenti"
            // (admin.js bindToolsTest, box.textContent).
            'Inserisci una query o un URL da testare.' => 'Enter a query or a URL to test.',
            'Intelligenza artificiale' => 'Artificial intelligence',
            'Intelligenza artificiale e lavoro' => 'Artificial intelligence and work',
            'Interfaccia' => 'Interface',
            'Intervallo di raccolta (secondi)' => 'Collection interval (seconds)',
            'Intervallo in secondi tra i cicli di raccolta automatica degli RSS (minimo 300 = 5 minuti, predefinito 3600 = 1 ora).' => 'Interval in seconds between automatic RSS collection cycles (minimum 300 = 5 minutes, default 3600 = 1 hour).',
            'Invia alla tua email una copia di prova del digest di questa settimana.' => 'Sends a trial copy of this week\'s digest to your email.',
            'Invia anteprima a me' => 'Send preview to me',
            'Invio dell\'anteprima non riuscito: verifica la configurazione email del sito.' => 'Preview sending failed: check the site email configuration.',
            'Invio in coda di approvazione…' => 'Sending to the approval queue…',
            'Iscritti confermati: %1$d. Riceveranno il prossimo digest: %2$d.' => 'Confirmed subscribers: %1$d. They will receive the next digest: %2$d.',
            'Iscritti newsletter' => 'Newsletter subscribers',
            'Iscriviti alla newsletter' => 'Subscribe to the newsletter',
            'Italiano' => 'Italian',
            'JavaScript è disattivato' => 'JavaScript is disabled',
            'JavaScript è disattivato: elenco statico in sola lettura.' => 'JavaScript is disabled: static read-only list.',
            'JavaScript è disattivato: esportazione, importazione e generazione della chiave richiedono JavaScript.' => 'JavaScript is disabled: export, import and key generation require JavaScript.',
            'JavaScript è disattivato: la coda di approvazione richiede JavaScript per caricare gli articoli.' => 'JavaScript is disabled: the approval queue needs JavaScript to load articles.',
            'JavaScript è disattivato: la dashboard di Nexus News AI richiede JavaScript per caricare statistiche, attività e stato degli agenti.' => 'JavaScript is disabled: the Nexus News AI dashboard needs JavaScript to load stats, activity and agent status.',
            'JavaScript è disattivato: la pagina Coda Lavori richiede JavaScript per caricare lo stato della coda.' => 'JavaScript is disabled: the Jobs Queue page needs JavaScript to load the queue status.',
            'L\'agente scelto non è attivo: attivalo nella pagina Agenti e riprova.' => 'The selected agent is not active: enable it in the Agents page and try again.',
            'L\'assegnazione avvia il Nexus Wire (redazione collettiva) sul tema della query: l\'articolo finisce in coda di approvazione e l\'agente scelto resta il referente nei log attività.' => 'The assignment starts the Nexus Wire (collective newsroom) on the query topic: the article ends up in the approval queue and the selected agent remains the referent in the activity logs.',
            'L\'import sovrascrive impostazioni e agenti esistenti (match per categoria).' => 'The import overwrites existing settings and agents (matched by category).',
            'L\'istogramma compare dopo le prime pubblicazioni: per ora non ci sono dati veri da mostrare e nessun orario viene suggerito.' => 'The histogram appears after the first publishing: for now there is no real data to show and no time is suggested.',
            'La categoria diventa una categoria reale della testata e l’articolo erediterà autore e sezione.' => 'The category becomes a real publication category and the article will inherit author and section.',
            'La chiave si genera nella pagina Strumenti → API pubblica.' => 'The key is generated on the Tools → Public API page.',
            'La coda di approvazione richiede JavaScript per caricare gli articoli.' => 'The approval queue requires JavaScript to load articles.',
            'La coda esegue i lavori pesanti in batch ogni 60 secondi tramite WP-Cron quando l\'opzione \'Usa la coda\' è attiva in Impostazioni → Scala & Pro. Il pulsante \'Processa ora\' esegue subito un batch.' => 'The queue runs heavy jobs in batches every 60 seconds via WP-Cron when the \'Use the jobs queue\' option is enabled in Settings → Scale & Pro. The \'Process now\' button runs a batch right away.',
            'La dashboard di Nexus News AI richiede JavaScript per caricare statistiche, attività e stato degli agenti.' => 'The Nexus News AI dashboard requires JavaScript to load statistics, activity and agent status.',
            'La migrazione converte i vecchi articoli Nexus in post WordPress nativi (autore = agente, categoria nativa).' => 'Migration converts old Nexus articles into native WordPress posts (author = agent, native category).',
            'La migrazione non ha restituito uno stato valido.' => 'The migration did not return a valid status.',
            'La migrazione non sta progredendo: interrotta dopo un batch senza movimenti.' => 'The migration is not progressing: stopped after a batch with no movement.',
            'La pipeline raccoglie automaticamente nuovi articoli dalle fonti RSS configurate.' => 'The pipeline automatically collects new articles from the configured RSS sources.',
            'La property compare nell\'account ma NON è verificata (permesso "siteUnverifiedUser"): completa la verifica della proprietà in Search Console e riprova.' => 'The property appears in the account but is NOT verified ("siteUnverifiedUser" permission): complete property verification in Search Console and try again.',
            'La property esiste ma NON è verificata per questo account Google (403): completa la verifica della proprietà in Search Console e riprova.' => 'The property exists but is NOT verified for this Google account (403): complete property verification in Search Console and try again.',
            'La redazione AI in un colpo d\'occhio: articoli pubblicati, agenti al lavoro e consumo AI del mese.' => 'The AI newsroom at a glance: published articles, agents at work and monthly AI usage.',
            'La redazione collettiva multi-agente: tre agenti propongono i loro angoli editoriali su un tema e il direttore AI fonde tutto in un articolo.' => 'The multi-agent collaborative newsroom: three agents propose their editorial angles on a topic and the AI editor merges everything into a single article.',
            'La sincronizzazione manuale esegue lo stesso giro del cron (può richiedere qualche decina di secondi).' => 'Manual sync runs the same job as the cron (it may take a few dozen seconds).',
            'Lavori per batch' => 'Jobs per batch',
            'Lavoro' => 'Job',
            'Lavoro fallito: %2$s (%1$d volta)' => 'Failed job: %2$s (%1$d time)',
            'Lavoro fallito: %2$s (%1$d volte)' => 'Failed job: %2$s (%1$d times)',
            'Lavoro già in coda per questo hook.' => 'Job already queued for this hook.',
            'lavoro senza hook' => 'job without hook',
            'Layer dati non disponibile: impossibile leggere le search queries archiviate.' => 'Data layer not available: cannot read the stored search queries.',
            'Le azioni pesanti (raccolta, valutazione, pubblicazione, traduzioni) vengono accodate nella tabella nexus_jobs ed eseguite in batch ogni 60 secondi dal runner WP-Cron. Consigliato su siti con molto traffico o hosting lento. Lo stato live è visibile nella pagina Coda Lavori.' => 'Heavy actions (collection, evaluation, publishing, translations) are queued in the nexus_jobs table and run in batches every 60 seconds by the WP-Cron runner. Recommended on high-traffic sites or slow hosting. Live status is visible on the Jobs Queue page.',
            'Le categorie WordPress non sono disponibili in questo contesto.' => 'WordPress categories are not available in this context.',
            'Le chiamate AI degli agenti appariranno qui con il tracking v1.0.3.' => 'AI agent calls will appear here with v1.0.3 tracking.',
            'Le colonne Click/Views "giorno 0" sommano le metriche nexus_metrics del giorno di pubblicazione (granularità giornaliera): nessuna metrica oraria esiste e nessuna viene stimata.' => 'The Clicks/Views "day 0" columns sum the nexus_metrics of the publication day (daily granularity): no hourly metric exists and none is estimated.',
            'Le tue pubblicazioni degli ultimi %1$d giorni si concentrano alle ore %2$s.' => 'Your publishing of the last %1$d days concentrates around %2$s.',
            'legacy' => 'legacy',
            'Lettura del file non riuscita.' => 'Could not read the file.',
            'Lettura delle fonti in arrivo…' => 'Reading incoming sources…',
            // v1.1.7 (T-C): pannello "Test degli strumenti" (tools.php v1.1.7, T-B2).
            'Lettura pagina (read)' => 'Page reading (read)',
            'Limite di notifiche inviate per ora (1-60, predefinito 10).' => 'Maximum notifications sent per hour (1-60, default 10).',
            'Limite memoria (memory_limit)' => 'Memory limit (memory_limit)',
            'Limiti e qualità' => 'Limits and quality',
            'Limiti e scala' => 'Scale limits',
            'Lingua dell\'interfaccia admin' => 'Admin interface language',
            'Lingua e API pubblica' => 'Language and public API',
            'Lingue di traduzione' => 'Translation languages',
            'Locale del sito' => 'Site locale',
            'Lock WP-Cron (WP_CRON_LOCK_TIMEOUT)' => 'WP-Cron lock (WP_CRON_LOCK_TIMEOUT)',
            'Log di pubblicazione' => 'Publishing log',
            'L’attività degli agenti apparirà qui in tempo reale.' => 'Agent activity will appear here in real time.',
            'Mai' => 'Never',
            'mai eseguito' => 'never run',
            'Mai eseguito: nessuna riga nella tabella metriche.' => 'Never run: no rows in the metrics table.',
            'mai schedulato' => 'never scheduled',
            'Mancante' => 'Missing',
            'Mese precedente' => 'Previous month',
            'Mese successivo' => 'Next month',
            'Meta tag AI' => 'AI meta tags',
            'Metti in pausa' => 'Pause',
            'Migrazione completata: ' => 'Migration completed: ',
            'Migrazione contenuti nativi' => 'Native content migration',
            'Migrazione in corso…' => 'Migration in progress…',
            'Migrazione interrotta: ' => 'Migration interrupted: ',
            'Missione lunare Artemis' => 'Artemis lunar mission',
            'Modalità operativa' => 'Operating mode',
            'Modelli disponibili' => 'Available models',
            'modelli disponibili' => 'models available',
            'modelli disponibili dal provider' => 'models available from the provider',
            'modelli disponibili dal provider AI' => 'models available from the AI provider',
            'Modello aggiornato' => 'Model updated',
            'Modello AI' => 'AI model',
            'Modello AI (vuoto = globale)' => 'AI model (empty = global)',
            'Modello AI per agente: il modello verrà usato per la riscrittura di QUESTO agente; vuoto = modello globale impostato nelle opzioni.' => 'Per-agent AI model: the model will be used to rewrite THIS agent’s articles; empty = the global model set in the options.',
            'Modello dedicato rimosso: verrà usato il modello globale.' => 'Dedicated model removed: the global model will be used.',
            'Modello economico (screening)' => 'Economy model (screening)',
            'Modello globale' => 'Global model',
            'Modello immagini (vuoto = default)' => 'Image model (empty = default)',
            'Modifica' => 'Edit',
            'Modifica agente: ' => 'Edit agent: ',
            'Moduli AI' => 'AI modules',
            'Modulo analytics non disponibile: impossibile leggere le metriche di performance.' => 'Analytics module unavailable: unable to read the performance metrics.',
            'Modulo Google Auth non disponibile: controlla che il plugin sia aggiornato.' => 'Google Auth module not available: check that the plugin is up to date.',
            'Modulo Google Wizard non disponibile: controlla che il plugin sia aggiornato.' => 'Google Wizard module not available: check that the plugin is up to date.',
            'Modulo metriche non disponibile: controlla che il plugin sia aggiornato.' => 'Metrics module not available: check that the plugin is up to date.',
            'Modulo opportunità non disponibile: impossibile leggere le search queries.' => 'Opportunities module not available: cannot read the search queries.',
            'Mostra a quale provider è connesso il plugin e quanti modelli espone.' => 'Shows which AI provider the plugin is connected to and how many models it exposes.',
            'Mostra immagini' => 'Show images',
            'Nessun agente attivo' => 'No active agent',
            'Nessun agente attivo in redazione: creane uno dalla pagina Agenti per assegnare le opportunità.' => 'No active agent in the newsroom: create one from the Agents page to assign the opportunities.',
            'Nessun agente configurato' => 'No agents configured',
            'Nessun agente configurato: crea gli agenti dalla pagina Agenti per usare gli slot dedicati.' => 'No agent configured: create agents from the Agents page to use dedicated slots.',
            'Nessun agente in tabella' => 'No agents in the table',
            'Nessun agente in tabella: riattiva il plugin per rigenerare il set iniziale dei 7 agenti.' => 'No agents in the table: reactivate the plugin to regenerate the initial set of 7 agents.',
            // v1.1.11 (R3): wizard onboarding — empty state e chiusura "a mano".
            'Nessun agente ancora: apri il wizard e parti da un pacchetto pronto (1, 3 o più giornalisti), tutto personalizzabile.' => 'No agents yet: open the wizard and start from a ready package (1, 3 or more journalists), fully customizable.',
            'Nessun agente creato: controlla gli errori nel riepilogo.' => 'No agents created: check the errors in the summary.',
            'Nessun agente ora: chiudi il wizard e usa i preset o il modal nuovo agente.' => 'No agents now: close the wizard and use the presets or the new agent modal.',
            'Nessun agente: crea il tuo primo giornalista con un preset o apri il wizard.' => 'No agents yet: create your first journalist with a preset or open the wizard.',
            // v1.1.7 (T-C): titolo empty-state del modal articoli (admin.js, emptyState).
            'Nessun argomento mancante con le soglie attuali.' => 'No missing topic with the current thresholds.',
            'Nessun articolo' => 'No articles',
            'Nessun articolo con lo stato selezionato.' => 'No articles with the selected status.',
            'Nessun articolo in attesa' => 'No pending articles',
            'Nessun articolo in coda' => 'No articles in queue',
            'Nessun articolo programmato in questo mese' => 'No articles scheduled in this month',
            'Nessun articolo spostato finora: gli articoli programmati cadono già dentro gli slot.' => 'No article moved so far: scheduled articles already fall inside the slots.',
            'Nessun budget mensile configurato: controllo non applicabile.' => 'No monthly budget configured: check not applicable.',
            'Nessun consumo AI questo mese' => 'No AI usage this month',
            'Nessun dato ancora sincronizzato.' => 'No data synced yet.',
            'Nessun dato nel periodo' => 'No data in the period',
            'Nessun evento WP-Cron attivo nelle impostazioni: controllo non applicabile.' => 'No WP-Cron events active in the settings: check not applicable.',
            'Nessun giro registrato: il log appare al primo riallineamento (automatico o manuale).' => 'No run logged: the log appears at the first realignment (automatic or manual).',
            'Nessun lavoro bloccato in coda.' => 'No stuck jobs in the queue.',
            'Nessun lavoro in coda' => 'No jobs in queue',
            // v1.1.7 (T-C): box esito pannello "Test degli strumenti" (tools.php v1.1.7).
            'Nessun test eseguito in questa sessione.' => 'No tests run in this session.',
            'Nessun titolo da migliorare con le soglie attuali.' => 'No title to improve with the current thresholds.',
            'Nessun token salvato: collega Google per iniziare.' => 'No token saved: connect Google to start.',
            'Nessuna attività registrata' => 'No activity recorded',
            'Nessuna chiave generata' => 'No key generated yet',
            'Nessuna esecuzione del Wire' => 'No Wire runs yet',
            'Nessuna fonte RSS configurata.' => 'No RSS sources configured.',
            'Nessuna importazione eseguita in questa sessione.' => 'No import performed in this session.',
            'Nessuna metrica GSC/GA4 disponibile per i giorni osservati: sincronizza i dati da Analytics per arricchire la tabella.' => 'No GSC/GA4 metrics available for the observed days: sync data from Analytics to enrich the table.',
            'Nessuna property configurata: verifica e salva una property GSC in questa pagina, o inserisci un Property ID GA4.' => 'No property configured: verify and save a GSC property on this page, or enter a GA4 Property ID.',
            'Nessuna pubblicazione passata da analizzare' => 'No past publishing to analyze',
            'Nessuna raccolta ancora eseguita: i controlli fonti partono dopo il primo giro della pipeline.' => 'No collection run yet: source checks start after the first pipeline run.',
            'Nessuna search query nel periodo scelto.' => 'No search query in the selected period.',
            'Nessuno slot dedicato per questo agente.' => 'No dedicated slots for this agent.',
            'Nessuno slot globale configurato: finché non ne aggiungi almeno uno, nessun articolo viene spostato.' => 'No global slots configured: until you add at least one, no article is moved.',
            'Newsletter' => 'Newsletter',
            'Nexus News AI' => 'Nexus News AI',
            'Nexus Wire' => 'Nexus Wire',
            'Nexus: Iscrizione Newsletter' => 'Nexus: Newsletter Signup',
            'Nexus: Ultimi Articoli' => 'Nexus: Latest Articles',
            'Nexus_DB non disponibile: stato tabelle non verificabile.' => 'Nexus_DB not available: table status cannot be verified.',
            'News veloci' => 'Fast news',
            'Nome' => 'Name',
            'Nome del sito' => 'Site name',
            'Nome e categoria sono obbligatori.' => 'Name and category are required.',
            'Non ci sono bozze, articoli da approvare o lavori in coda in questo momento.' => 'There are no drafts, articles to approve or queued jobs right now.',
            'Non configurata' => 'Not configured',
            'Non connesso' => 'Not connected',
            'Non hai i permessi per gestire le preferenze del digest.' => 'You are not allowed to manage digest preferences.',
            'Non hai i permessi per inviare l\'anteprima del digest.' => 'You are not allowed to send the digest preview.',
            'Non impostato' => 'Not set',
            'non salvato' => 'unsaved',
            'Non schedulata' => 'Not scheduled',
            'Nonce di sicurezza non valido: ricarica la pagina e riprova.' => 'Security nonce invalid: reload the page and try again.',
            'Nonce non disponibile: ricarica la pagina.' => 'Nonce not available: reload the page.',
            'Nonce non valido: ricarica la dashboard e riprova.' => 'Invalid nonce: reload the dashboard and try again.',
            'Nonce REST mancante o non valido: ricarica la pagina e riprova.' => 'REST nonce missing or invalid: reload the page and try again.',
            'Nota di rifiuto (opzionale)' => 'Rejection note (optional)',
            'Notifiche massime per ora' => 'Maximum notifications per hour',
            'Numero articoli' => 'Number of articles',
            'Numero massimo di articoli pubblicati automaticamente in un giorno.' => 'Maximum number of articles automatically published in one day.',
            'Nuova chiave API generata' => 'New API key generated',
            'Nuove terapie nel settore salute' => 'New therapies in healthcare',
            'Nuovi nel periodo' => 'New in the period',
            'Nuovo agente' => 'New agent',
            'Oggi' => 'Today',
            'Opportunità di ricerca' => 'Search opportunities',
            'Ora' => 'Hour',
            'Ora fine nuovo slot' => 'New slot end hour',
            'Ora fine slot' => 'Slot end hour',
            'Ora inizio nuovo slot' => 'New slot start hour',
            'Ora inizio slot' => 'Slot start hour',
            'Ordinati per click Search Console, poi per viste Analytics 4.' => 'Sorted by Search Console clicks, then Analytics 4 views.',
            'Operazione non riuscita' => 'Operation failed',
            'Orizzontale (1792x1024)' => 'Landscape (1792x1024)',
            'Ottimizzazione SEO' => 'SEO optimization',
            'Opzioni (facoltative)' => 'Options (optional)',
            'Periodo' => 'Period',
            'Permessi insufficienti per eseguire i controlli.' => 'Insufficient permissions to run the checks.',
            'Personalità / tono di voce' => 'Personality / tone of voice',
            'Piattaforma' => 'Platform',
            'Posizione media' => 'Average position',
            'Piattaforme di diffusione' => 'Distribution platforms',
            'Pipeline completata' => 'Pipeline completed',
            'Pipeline editoriale' => 'Editorial pipeline',
            'Pipeline in corso…' => 'Pipeline running…',
            'Pipeline non completata' => 'Pipeline not completed',
            'Potrebbe essere stato eliminato.' => 'It may have been deleted.',
            'Precarica fonti RSS globali di esempio' => 'Preload sample global RSS sources',
            'Predefinito OpenRouter' => 'OpenRouter default',
            'Preferenze digest salvate.' => 'Digest preferences saved.',
            'Preferisco crearli a mano' => 'I prefer to create them manually',
            'Primo controllo entro circa 5 minuti dall\'attivazione.' => 'First check within about 5 minutes from activation.',
            'Problemi registrati negli ultimi 7 giorni:' => 'Issues recorded in the last 7 days:',
            'Programmatore' => 'Scheduled',
            'Property GA4' => 'GA4 property',
            'Property GSC' => 'GSC property',
            'Property non trovata nell\'account collegato (404): controlla l\'URL esatto registrato in Search Console (protocollo incluso, eventuale sottocartella, dominio sc-domain).' => 'Property not found in the connected account (404): check the exact URL registered in Search Console (protocol included, possible subfolder, sc-domain).',
            'Property salvata:' => 'Saved property:',
            'Property verificata e salvata nelle impostazioni.' => 'Property verified and saved in the settings.',
            'Property verificata ma salvataggio fallito: salvala a mano nelle Impostazioni (tab Sistema).' => 'Property verified but saving failed: save it manually in Settings (System tab).',
            'Prossima esecuzione' => 'Next run',
            // v1.1.7 (T-C): pannello "Test degli strumenti" (tools.php v1.1.7, T-B2).
            'Prossimo giro automatico: %s.' => 'Next automatic run: %s.',
            'Prossimo giro: %s' => 'Next run: %s',
            'Prova la configurazione degli strumenti senza attivarli: ricerca web, lettura pagina e scoperta feed.' => 'Try the tools configuration without activating it: web search, page reading and feed discovery.',
            'Provider' => 'Providers',
            'Provider AI:' => 'AI provider:',
            'Pubblica' => 'Publish',
            'Pubblica post WordPress nativi' => 'Publish native WordPress posts',
            'Pubblicati' => 'Published',
            'Pubblicati subito' => 'Published immediately',
            'Pubblicazione' => 'Publishing',
            'Pubblicazione & distribuzione' => 'Publishing & distribution',
            'Pubblicazione automatica' => 'Automatic publishing',
            'Pubblicazione non riuscita' => 'Publishing failed',
            'Pubblicazione social' => 'Social publishing',
            'Pubblicazione WordPress' => 'WordPress publishing',
            'Pubblicazione WordPress & SEO' => 'WordPress publishing and SEO',
            'Pubblicazioni per piattaforma' => 'Publishing by platform',
            'Pubblico…' => 'Publishing…',
            'Pulizia (>7 giorni)' => 'Cleanup (>7 days)',
            'Punteggio fact-check AI' => 'AI fact-check score',
            'Punteggio qualità' => 'Quality score',
            'Quadrata (1024x1024)' => 'Square (1024x1024)',
            'Quando un agente ha l\'override attivo con almeno uno slot, i SUOI articoli usano solo i suoi slot e ignorano quelli globali.' => 'When an agent has the override active with at least one slot, THEIR articles use only their own slots and ignore the global ones.',
            'Quanti articoli pillar vengono rinfrescati al massimo a ogni giro settimanale (1-50, predefinito 5).' => 'How many pillar articles are refreshed at most in each weekly run (1-50, default 5).',
            'Quanti articoli pubblicati al massimo vengono confrontati a ogni giro (100-5000, predefinito 400).' => 'How many published articles are compared at most on each run (100-5000, default 400).',
            'Quanti candidati vengono valutati a ogni giro (5-100, predefinito 20).' => 'How many candidates are evaluated on each run (5-100, default 20).',
            // v1.1.7 (T-C): pannello "Test degli strumenti" (tools.php v1.1.7, T-B2).
            'Query' => 'Query',
            'Query con almeno %1$s impressioni e CTR sotto il %2$s: il sito appare ma il titolo non converte.' => 'Queries with at least %1$s impressions and a CTR below %2$s: the site appears but the title does not convert.',
            'Query con almeno %1$s impressioni e zero click: nessuna pagina nostra che converte — idea per un nuovo articolo.' => 'Queries with at least %1$s impressions and zero clicks: none of our pages converts — idea for a new article.',
            'Query mancante: ricarica la pagina.' => 'Missing query: reload the page.',
            'Query o URL da testare' => 'Query or URL to test',
            'Qualità media' => 'Average quality',
            'Quando il budget è esaurito' => 'When the budget is exhausted',
            'Quanti lavori al massimo vengono eseguiti a ogni tick del runner (default 5).' => 'How many jobs at most are executed at each runner tick (default 5).',
            'Queste preferenze usano un salvataggio dedicato: premi il pulsante qui sotto, non il pulsante \'Salva impostazioni\' del tab.' => 'These preferences use a dedicated save: press the button below, not the "Save settings" button of the tab.',
            'Raccogli notizie ora' => 'Collect news now',
            'Raccolta' => 'Collection',
            'Raccolta automatica' => 'Automatic collection',
            'Raccolta completata: nessuna notizia abbastanza rilevante in questo momento.' => 'Collection completed: no news relevant enough at the moment.',
            'Raccolta in corso…' => 'Collection running…',
            'Raccolta non completata' => 'Collection not completed',
            'Reazioni' => 'Reactions',
            'Riallinea ora' => 'Realign now',
            'Riallineamento slot eseguito: %1$d articoli controllati, %2$d spostati.' => 'Slot realignment done: %1$d articles checked, %2$d moved.',
            'Ricalcolare ora gli orari degli articoli programmati in base agli slot attivi?' => 'Recalculate now the times of scheduled articles based on the active slots?',
            'Ricontrolla ora' => 'Recheck now',
            'Rilegge gli articoli dei giornalisti prima della pubblicazione.' => 'Reviews the journalists’ articles before publication.',
            'Redattore capo sta scrivendo la bozza…' => 'The editor-in-chief is writing the draft…',
            'Redazione al lavoro' => 'Newsroom at work',
            'Redazione al lavoro… (3 agenti + direttore)' => 'Newsroom at work… (3 agents + editor)',
            'Redazione intelligente' => 'Smart newsroom',
            'Redazione interrotta' => 'Newsroom stopped',
            'Riattiva' => 'Reactivate',
            'Riattiva il plugin per rigenerare il set iniziale dei 7 agenti.' => 'Reactivate the plugin to regenerate the initial set of 7 agents.',
            'Ricercatore al lavoro sul dossier…' => 'Researcher working on the dossier…',
            'Rifiuta' => 'Reject',
            'Rifiutati' => 'Rejected',
            'Rifiuto non riuscito' => 'Rejection failed',
            'Rifiuto…' => 'Rejecting…',
            'Rimuovere lo slot %1$s–%2$s?' => 'Remove the %1$s–%2$s slot?',
            'Rimuovi' => 'Remove',
            'Riscrittura' => 'Rewriting',
            'Riscrittura automatica' => 'Automatic rewriting',
            'Risposta inattesa dall\'API Search Console: nessuna lista property leggibile.' => 'Unexpected response from the Search Console API: no readable property list.',
            'Risposta senza chiave API' => 'Response without API key',
            'Salva agente' => 'Save agent',
            'Salva le impostazioni e riprova.' => 'Save the settings and try again.',
            'Salva modello' => 'Save model',
            'Salva ora' => 'Save now',
            'Salva preferenze digest' => 'Save digest preferences',
            'Salva slot agente' => 'Save agent slots',
            'Salva slot globali' => 'Save global slots',
            'Salvataggio agente fallito' => 'Agent save failed',
            'Salvataggio modello non riuscito' => 'Model save failed',
            'Salvataggio non riuscito' => 'Save failed',
            'Salvataggio non riuscito: agente non valido o richiesta incompleta.' => 'Saving failed: invalid agent or incomplete request.',
            'Salvataggio…' => 'Saving…',
            'Salvati come bozze' => 'Saved as drafts',
            'Salvo…' => 'Saving…',
            'Scala & Pro' => 'Scale & Pro',
            'Scarica JSON' => 'Download JSON',
            'Scuro (aspetto delle versioni precedenti)' => 'Dark (previous releases look)',
            'Se attivo, a ogni pubblicazione viene creato un post UGC sull\'account LinkedIn configurato.' => 'If enabled, a UGC post is created on the configured LinkedIn account on every publish.',
            'Se attivo, a ogni pubblicazione viene inviato un messaggio (o una foto con didascalia) nella chat Telegram configurata.' => 'If enabled, a message (or a photo with caption) is sent to the configured Telegram chat on every publish.',
            'Se attivo, a ogni pubblicazione viene inviato un messaggio con embed nel canale Discord configurato.' => 'If enabled, a message with embed is sent to the configured Discord channel on every publish.',
            'Se attivo, a ogni pubblicazione viene pubblicato un tweet (max 280 caratteri) con il link all\'articolo.' => 'If enabled, a tweet (max 280 characters) with the article link is posted on every publish.',
            'Se attivo, alla pubblicazione viene generata un\'immagine in evidenza via AI (fallback: immagine dell\'articolo o placeholder locale).' => 'If enabled, a featured image is generated via AI on publishing (fallback: the article image or a local placeholder).',
            'Se attivo, dopo la riscrittura l\'AI valuta il draft rispetto alla fonte (punteggio 0-100) e raccoglie le citazioni.' => 'If enabled, after rewriting the AI evaluates the draft against the source (score 0-100) and collects the quotes.',
            'Se attivo, gli articoli pubblicati diventano post WordPress nativi del sito: compaiono nella home, con categorie, tag e autore reali; se disattivo resta il front-end legacy di Nexus.' => 'If enabled, published articles become native WordPress posts of the site: they appear on the home page, with real categories, tags and author; if disabled the legacy Nexus front-end remains.',
            'Se attivo, gli articoli pubblicati possono essere tradotti con l\'AI nelle lingue configurate.' => 'If enabled, published articles can be translated with AI into the configured languages.',
            'Se attivo, i lettori possono iscriversi con lo shortcode [nexus_subscribe] e il digest settimanale viene inviato via email.' => 'If enabled, readers can subscribe with the [nexus_subscribe] shortcode and the weekly digest is sent by email.',
            'Se attivo, i permalink dei nuovi post vengono inviati ai motori che supportano il protocollo IndexNow.' => 'If enabled, permalinks of new posts are sent to engines that support the IndexNow protocol.',
            'Se attivo, i post Nexus ricevono meta description, Open Graph e dati strutturati JSON-LD (compatibile con Yoast e RankMath).' => 'If enabled, Nexus posts get a meta description, Open Graph tags and JSON-LD structured markup (compatible with Yoast and RankMath).',
            'Se attivo, il feed del sito viene notificato agli hub WebSub a ogni pubblicazione.' => 'If enabled, the site feed is pinged to WebSub hubs on every publish.',
            'Se attivo, l\'introduzione del digest settimanale viene scritta dall\'AI (elenco titoli e link comunque incluso).' => 'If enabled, the weekly digest introduction is written by AI (the list of titles and links is included anyway).',
            'Se attivo, title e description SEO vengono generati con l\'AI; in caso contrario usano titolo e riassunto dell\'articolo.' => 'If enabled, the SEO title and description are generated with AI; otherwise they use the article title and summary.',
            'Seleziona dove diffondere gli articoli pubblicati.' => 'Select where to distribute the published articles.',
            'Seleziona prima un file JSON da importare.' => 'Select a JSON file to import first.',
            'Seleziona un agente a cui assegnare l\'opportunità.' => 'Select an agent to assign the opportunity to.',
            'Seleziona un agente.' => 'Select an agent.',
            'Semi-autonoma (approvazione umana)' => 'Semi-autonomous (human approval)',
            'Semi-autonoma: la redazione AI lavora ma gli articoli attendono l\'approvazione umana. Completamente autonoma: pubblicazione automatica oltre la soglia di qualità.' => 'Semi-autonomous: the AI newsroom works but articles wait for human approval. Fully autonomous: automatic publishing above the quality threshold.',
            'SEO on-page' => 'On-page SEO',
            'Serie giornaliera' => 'Daily series',
            'Sessione scaduta o permessi insufficienti: ricarica la pagina e riprova.' => 'Session expired or insufficient permissions: reload the page and try again.',
            'Sezione errori' => 'Errors section',
            'Sezione performance' => 'Performance section',
            'Sezione uso AI' => 'AI usage section',
            'Sezioni AI' => 'AI sections',
            'Sezioni impostazioni' => 'Settings sections',
            'Sezioni performance, uso AI ed errori del digest, copia garantita e anteprima si gestiscono nella card "Digest settimanale" in fondo alla pagina (salvataggio dedicato).' => 'The digest performance, AI usage and errors sections, the guaranteed copy and the preview are managed in the "Weekly digest" card at the bottom of the page (dedicated save).',
            'Si è verificato un errore inatteso.' => 'An unexpected error occurred.',
            'Sincronizza ora' => 'Sync now',
            'Sincronizzazione completata ma nessuna riga scritta: controlla le righe di log qui sotto (articoli recenti assenti o dati GSC/GA4 non ancora disponibili).' => 'Synchronization completed but no rows written: check the log lines below (no recent articles or GSC/GA4 data not yet available).',
            'Sincronizzazione completata: %d righe nuove scritte.' => 'Synchronization completed: %d new rows written.',
            'Sincronizzazione completata: nessuna riga nuova (dati già sincronizzati o nessun articolo recente da attribuire).' => 'Synchronization completed: no new rows (data already synced or no recent articles to attribute).',
            'Sincronizzazione interrotta da un errore fatale: guarda il log Attività per il dettaglio.' => 'Synchronization stopped by a fatal error: check the Activity log for details.',
            'Sincronizzazione interrotta da un errore: guarda il log Attività per il dettaglio.' => 'Synchronization stopped by an error: check the Activity log for details.',
            'Sistema' => 'System',
            'Sistema Google' => 'Google system',
            'Sito & sistema' => 'Site & system',
            'Slot disattivati: nessun riallineamento eseguito. Attiva almeno uno slot e riprova.' => 'Slots disabled: no realignment performed. Enable at least one slot and try again.',
            'Slot globali' => 'Global slots',
            'Slot globali attivi (si applicano a tutti gli articoli senza slot propri)' => 'Global slots active (they apply to all articles without their own slots)',
            'Slot orari di pubblicazione' => 'Publishing time slots',
            'Slot per agente (override)' => 'Per-agent slots (override)',
            'Slot salvati.' => 'Slots saved.',
            'Soglia di auto-approvazione' => 'Auto-approval threshold',
            'Soglia minima fact-check (0-100)' => 'Minimum fact-check score (0-100)',
            'Sette giornalisti e il capo redattore: la redazione completa.' => 'Seven journalists and the chief editor: the complete newsroom.',
            'Solo avviso' => 'Warning only',
            'Solo Tecnologia' => 'Technology only',
            'Sostituisci TUA_CHIAVE con la chiave generata qui.' => 'Replace TUA_CHIAVE with the key generated here.',
            'Sotto questa soglia l\'articolo torna in bozza.' => 'Below this threshold the article goes back to draft.',
            'Statistiche reali della redazione AI: articoli creati e pubblicati, uso e costi AI, piattaforme, agenti e iscritti newsletter.' => 'Real AI newsroom statistics: articles created and published, AI usage and costs, platforms, agents and newsletter subscribers.',
            'Stato agenti' => 'Agent status',
            'Stato aggiornato' => 'Status updated',
            'Stato connessione' => 'Connection status',
            'Stato dei post creati' => 'Created posts status',
            'Stato della connessione Google, verifica guidata della property Search Console e sincronizzazione manuale delle metriche.' => 'Google connection status, guided verification of the Search Console property and manual metrics synchronization.',
            'Stato della conversione al contenuto nativo: articoli rimasti e agenti senza mappatura nativa.' => 'Native content conversion status: remaining articles and agents without native mapping.',
            // v1.1.7 (T-C2): messaggio REST di validazione post_status
            // (class-nexus-rest.php sanitize_settings_payload, esito 400 onesto
            // mostrato nel toast da apiErrorToast).
            'Stato non disponibile.' => 'Status not available.',
            'Stato pipeline non disponibile (modulo database non caricato).' => 'Pipeline status not available (database module not loaded).',
            'Stato post non valido. Valori ammessi: publish, draft, pending.' => 'Invalid post status. Allowed values: publish, draft, pending.',
            'Stato WordPress assegnato ai post creati dal publisher (publish = pubblicati, draft = bozze, pending = in attesa di revisione).' => 'WordPress status assigned to the posts created by the publisher (publish = published, draft = drafts, pending = pending review).',
            'Storico delle redazioni collettive' => 'Collective newsroom history',
            'Successo' => 'Success',
            'Suggerimento orari (dalle tue pubblicazioni passate)' => 'Time suggestions (from your past publishing)',
            'Sync giornaliera (cron)' => 'Daily sync (cron)',
            'Sync query Search Console ancora non arrivato: le search queries compaiono dopo il primo giro del cron metriche (entro 24 ore).' => 'Search Console query sync has not run yet: search queries appear after the first metrics cron run (within 24 hours).',
            'Sì, ricalcola' => 'Yes, recalculate',
            'Sì, rimuovi' => 'Yes, remove',
            'Tabella' => 'Table',
            'Tabelle database' => 'Database tables',
            'Tagline del sito' => 'Site tagline',
            // v1.1.13.1 (Q3): badge connessioni (settings-sistema.php) — nome di servizio identico in EN.
            'Telegram:' => 'Telegram:',
            'Telegram non ha restituito message_id.' => 'Telegram did not return a message_id.',
            'Tema da sviluppare' => 'Topic to develop',
            'Tema da sviluppare (es. l\'impatto dell\'AI sulle notizie elettorali)' => 'Topic to develop (e.g. the impact of AI on election news)',
            'Tema del pannello' => 'Panel theme',
            'Tempo massimo di esecuzione (max_execution_time)' => 'Max execution time (max_execution_time)',
            'Tentativi massimi per lavoro' => 'Max attempts per job',
            'Test provider AI non riuscito: ' => 'AI provider test failed: ',
            'Testa provider AI' => 'Test AI provider',
            'Testata generalista' => 'Generalist newsroom',
            'Testo…' => 'Testing…',
            'Titoli da migliorare' => 'Titles to improve',
            'Titolo del modulo' => 'Module title',
            'Token del bot Telegram' => 'Telegram bot token',
            'Token del bot Telegram da @BotFather.' => 'Telegram bot token from @BotFather.',
            'Token di accesso presente e valido.' => 'Access token present and valid.',
            'Token di accesso rifiutato da Google (401): riconnetti Google dalle Impostazioni.' => 'Access token rejected by Google (401): reconnect Google in Settings.',
            'Token Google' => 'Google token',
            'Token Google assente o scaduto: collega Google dalle Impostazioni (flusso OAuth) e riprova.' => 'Google token missing or expired: connect Google in Settings (OAuth flow) and try again.',
            'Token Google valido.' => 'Google token valid.',
            'Token non rinnovabile: riavvia il collegamento Google.' => 'Token not renewable: restart the Google connection.',
            'Token scaduto e rinnovo automatico fallito: riconnetti Google dal flusso OAuth.' => 'Token expired and automatic renewal failed: reconnect Google via the OAuth flow.',
            'Token totali' => 'Total tokens',
            'Top performance della settimana' => 'Top performance of the week',
            'Totale' => 'Total',
            'Totale righe metriche' => 'Total metric rows',
            'Traduci' => 'Translate',
            'Traduco…' => 'Translating…',
            'Traduzione dell\'interfaccia admin in inglese (sperimentale): copre etichette e messaggi principali dell\'admin Nexus.' => 'English translation of the admin interface (experimental): covers the main Nexus admin labels and messages.',
            'Traduzioni' => 'Translations',
            'Traduzioni AI' => 'AI translations',
            'Traduzioni AI degli articoli' => 'AI article translations',
            'Transizione energetica in Europa' => 'Energy transition in Europe',
            'Tre giornalisti per partire subito con l’essenziale.' => 'Three journalists to start right away with the essentials.',
            'Ultimi %1$d giorni (%2$s → %3$s).' => 'Last %1$d days (%2$s → %3$s).',
            'Ultimi 14 giorni' => 'Last 14 days',
            'Ultimi 30 giorni' => 'Last 30 days',
            'Ultimi 7 giorni' => 'Last 7 days',
            'Ultimi 90 giorni' => 'Last 90 days',
            'Ultimi %1$d giorni (%2$s → %3$s) vs periodo precedente uguale.' => 'Last %1$d days (%2$s → %3$s) vs the previous equal-length period.',
            'Ultimi spostamenti' => 'Latest moves',
            'Ultimo controllo: %s fa.' => 'Last check: %s ago.',
            'Ultimo giro %1$s — %2$s' => 'Last run %1$s — %2$s',
            'Ultimo giro di metriche' => 'Last metrics run',
            'Ultimo run: %s' => 'Last run: %s',
            'Un giornalista tech per partire dal necessario.' => 'One tech journalist to start with the essentials.',
            'Un iscritto può rinunciare al solo digest dal link "Non ricevere il digest" in fondo alle email.' => 'A subscriber can opt out of the digest only via the "Do not receive the digest" link at the bottom of the emails.',
            'Una URL per riga. Puoi modificare l’elenco qui sotto: verrà salvato tra le impostazioni globali.' => 'One URL per line. You can edit the list below: it will be saved in the global settings.',
            'Unica per agente (es. tecnologia, sport).' => 'Unique per agent (e.g. technology, sports).',
            'URL base API AI' => 'AI API base URL',
            'URL del webhook del canale Discord (discord.com/api/webhooks/…).' => 'Discord channel webhook URL (discord.com/api/webhooks/…).',
            'URL property' => 'Property URL',
            'URN dell\'autore del post (es. urn:li:person:xxxx).' => 'URN of the post author (e.g. urn:li:person:xxxx).',
            'Usa la coda lavori (stile Action Scheduler)' => 'Use the jobs queue (Action Scheduler style)',
            'usa slot propri' => 'uses own slots',
            'Usato per valutazione qualità, fact-check e raccolta: conviene un modello economico.' => 'Used for quality evaluation, fact-checking and collection: an economy model is a good fit.',
            'Uso AI' => 'AI usage',
            'Uso AI (mese corrente)' => 'AI usage (current month)',
            'Uso AI del mese: %s%% del budget.' => 'AI usage of the month: %s%% of the budget.',
            'Uso AI della settimana' => 'AI usage of the week',
            'Vai alla Coda Approvazioni' => 'Go to the Approval Queue',
            'Vai alla Coda Lavori' => 'Go to the Jobs Queue',
            'Valutazione' => 'Evaluation',
            'Valutazione automatica' => 'Automatic evaluation',
            'Vedi articoli dell’agente' => 'View the agent’s articles',
            'Vedi articolo' => 'View article',
            'Vedi la pagina Coda Lavori per statistiche, retry e pulizia. I comandi WP-CLI wp nexus queue stats|process sono disponibili.' => 'See the Jobs Queue page for stats, retries and cleanup. The WP-CLI commands wp nexus queue stats|process are available.',
            'Vedi la redazione' => 'View the newsroom',
            'Vedi magazine' => 'View magazine',
            'Verifica live che la property GSC sia raggiungibile dall\'account Google collegato. Il valore viene salvato SOLO se la verifica ha esito positivo; poi la sync delle metriche lo usa senza altre configurazioni.' => 'Live check that the GSC property is reachable from the connected Google account. The value is saved ONLY if the check succeeds; the metrics sync then uses it with no further configuration.',
            'Verifica ora' => 'Verify now',
            'Versione PHP' => 'PHP version',
            'Versione plugin' => 'Plugin version',
            'Versione WordPress' => 'WordPress version',
            'Verticale (1024x1792)' => 'Portrait (1024x1792)',
            'Views giorno 0' => 'Views (day 0)',
            'Vista "google-wizard" non ancora disponibile (admin/views/).' => 'View "google-wizard" not yet available (admin/views/).',
            'Visualizzazioni' => 'Views',
            'Webhook Discord' => 'Discord webhook',
            'WebSub' => 'WebSub',
            'WebSub (PubSubHubbub)' => 'WebSub (PubSubHubbub)',
            'WP-Cron ferma:' => 'WP-Cron stalled:',
            'X (Twitter)' => 'X (Twitter)',

            /* ---- v1.1.6 (T-01): completamento audit esauriente — viste (incluse
             * settings-* di T-02), assets/js/admin.js, class-nexus-caps.php,
             * class-nexus-cpt.php. Sezione ordinata alfabeticamente. ---- */
            ' (nessuna — redazione madre)' => ' (none — parent desk)',
            ' (tutte — nessuna restrizione)' => ' (all — no restriction)',
            '«%1$s» spostato da %2$s a %3$s' => '"%1$s" moved from %2$s to %3$s',
            '— Seleziona agente —' => '— Select agent —',
            '▶ Avvia' => '▶ Run',
            '▶ Avvia redazione' => '▶ Run desk',
            // v1.1.7 (T-C): il bottone dashboard concatena "▶ " fuori dalla chiamata
            // di traduzione: senza la chiave con il prefisso strtr matchava prima
            // '▶ Avvia' e lasciava "testata" in italiano ("▶ Run testata").
            '▶ Avvia testata' => '▶ Run masthead',
            '⛔ Capo redattore: rifiutato' => '⛔ Editor-in-chief: rejected',
            '✏ Capo redattore: modificato' => '✏ Editor-in-chief: edited',
            '🎖 Capo redattore: visto' => '🎖 Editor-in-chief: approved',
            '🎖 Revisione capo redattore' => '🎖 Editor-in-chief review',
            '🔄 Revisione capo redattore' => '🔄 Editor-in-chief review',
            'Agente autore:' => 'Author agent:',
            'Agente capo redattore (0 = automatico)' => 'Editor-in-chief agent (0 = automatic)',
            'Agente non disponibile.' => 'Agent not available.',
            'Agenti assegnati' => 'Assigned agents',
            'Aggiungi nuovo' => 'Add new',
            'Aggiungi nuovo articolo Nexus' => 'Add new Nexus article',
            'Al publish di un articolo, per ogni lingua attiva viene creato il post tradotto collegato (traduzioni AI attive richieste).' => 'When an article is published, a linked translated post is created for each active language (AI translations must be enabled).',
            'Apri la lista Articoli.' => 'Open the Articles list.',
            'Archivio ↗' => 'Archive ↗',
            'Archivio articoli Nexus' => 'Nexus articles archive',
            'Articoli Nexus' => 'Nexus articles',
            'Articolo Nexus' => 'Nexus article',
            'Articolo Nexus di origine:' => 'Source Nexus article:',
            'Articolo aggiornato.' => 'Article updated.',
            'Articolo programmato.' => 'Article scheduled.',
            'Articolo pubblicato in privato.' => 'Article published privately.',
            'Articolo riportato a bozza.' => 'Article reverted to draft.',
            'Associazioni gestite da Polylang.' => 'Associations managed by Polylang.',
            'Associazioni gestite da WPML.' => 'Associations managed by WPML.',
            'Associazioni lingua gestite da Polylang.' => 'Language associations managed by Polylang.',
            'Associazioni lingua gestite da WPML.' => 'Language associations managed by WPML.',
            'Attiva gli strumenti di ricerca per gli agenti' => 'Enable search tools for the agents',
            'Attiva il capo redattore AI' => 'Enable the AI editor-in-chief',
            'Attributi dell’articolo' => 'Article attributes',
            'Avvia' => 'Run',
            'Avvia testata' => 'Run masthead',
            'Avvio' => 'Start',
            'Avvio redazione…' => 'Starting desk…',
            'Avvio…' => 'Starting…',
            'Capo redattore' => 'Editor-in-chief',
            'Capo redattore AI' => 'AI editor-in-chief',
            'Caratteri massimi per pagina (500-20000)' => 'Max characters per page (500-20000)',
            'Caricamento redazioni…' => 'Loading desks…',
            'Caricato in questo articolo' => 'Uploaded to this article',
            'Cerca articoli Nexus' => 'Search Nexus articles',
            'Chiave API del provider di ricerca' => 'Search provider API key',
            'Chiave del servizio di ricerca scelto (Tavily, Serper o Brave). Salvata nel database del sito, mai mostrata in chiaro.' => 'API key of the selected search service (Tavily, Serper or Brave). Stored in the site database, never shown in plain text.',
            'Chiamate' => 'Calls',
            'Collegamento interno Nexus.' => 'Internal Nexus linking.',
            'Collegamento interno Nexus: nessun plugin di lingua attivo (compatibile con Polylang e WPML se li installerai).' => 'Internal Nexus linking: no language plugin active (compatible with Polylang and WPML if you install them).',
            'Consenti lettura pagine web' => 'Allow web page reading',
            'Costo' => 'Cost',
            'Crea la prima redazione con il form accanto: potrai annidarvi sub-redazioni e assegnare gli agenti (es. Sport con Calcio, Basket e F1).' => 'Create the first desk with the form next to it: you can nest sub-desks in it and assign agents (e.g. Sport with Calcio, Basket and F1).',
            'Crea la prima redazione dalla pagina Redazioni.' => 'Create the first desk from the Desks page.',
            'Crea gli agenti dalla pagina Agenti per vedere la redazione al lavoro.' => 'Create the agents from the Agents page to see the newsroom at work.',
            'Crea redazione' => 'Create desk',
            // v1.1.7 (T-C): barra preset agenti (agents.php v1.1.4) — etichetta e
            // aria-label resi da PHP fuori da __(): tradotti dal buffer.
            'Creazione rapida:' => 'Quick creation:',
            'Creazione rapida agenti' => 'Quick creation agents',
            'Deselezionato = human-in-the-loop: l\'articolo resta in bozza con il badge del capo redattore e l\'approvazione resta a te.' => 'Unchecked = human-in-the-loop: the article stays in draft with the editor-in-chief badge and approval stays with you.',
            'Driver di lingua' => 'Language driver',
            'Elenco non disponibile: il modulo multilingua non risulta caricato.' => 'List not available: the multilanguage module does not appear to be loaded.',
            'Elimina' => 'Delete',
            'Eliminazione non riuscita' => 'Deletion failed',
            'Elimino…' => 'Deleting…',
            'Endpoint compatibile OpenAI usato dal provider Personalizzato (es. proxy interno o modello self-hosted).' => 'OpenAI-compatible endpoint used by the Custom provider (e.g. internal proxy or self-hosted model).',
            'Es. Redazione Sport, Redazione Calcio.' => 'E.g. Sport desk, Calcio desk.',
            'Fatto' => 'Done',
            'Filtra la lista articoli' => 'Filter the articles list',
            'Fonti della redazione' => 'Desk sources',
            'Generale' => 'General',
            'Giri di strumenti per risposta (0-6)' => 'Tool rounds per response (0-6)',
            'Giri massimi di revisione (1-5)' => 'Max review rounds (1-5)',
            'Gli agenti possono aprire le pagine trovate dalla ricerca (o indicate nei prompt) per leggerne il testo completo.' => 'The agents can open the pages found by the search (or given in the prompts) to read their full text.',
            'I post tradotti restano contenuti nativi di WordPress: sopravvivono al plugin. Le associazioni lingua con Polylang o WPML si gestiscono dalle loro interfacce.' => 'Translated posts remain native WordPress content: they survive the plugin. Language associations with Polylang or WPML are managed from their own interfaces.',
            'ID dell\'agente con ruolo capo redattore da usare sempre. 0 = automatico: viene scelto il primo capo redattore attivo.' => 'ID of the agent with the editor-in-chief role to always use. 0 = automatic: the first active editor-in-chief is picked.',
            'Il giornalista AI scrive l\'articolo e il capo redattore AI lo legge: lo approva, lo modifica, richiede una revisione o lo rifiuta. Con gli strumenti di ricerca attivi il capo verifica anche le fonti online. L\'umano resta sempre sovrano: può approvare o rifiutare dalla coda come oggi.' => 'The AI journalist writes the article and the AI editor-in-chief reads it: approves it, edits it, requests a revision or rejects it. With search tools enabled the editor-in-chief also verifies the online sources. The human always stays sovereign: you can approve or reject from the queue as today.',
            'Il modulo multilingua non risulta caricato: stato non verificabile.' => 'The multilanguage module does not appear to be loaded: status cannot be verified.',
            'Immagine in evidenza' => 'Featured image',
            'Impossibile caricare le redazioni' => 'Unable to load the desks',
            'Imposta immagine in evidenza' => 'Set featured image',
            'Impressioni' => 'Impressions',
            'Impostazioni AI' => 'AI settings',
            'Impostazioni contenuti' => 'Content settings',
            'Impostazioni pubblicazione' => 'Publishing settings',
            'Impostazioni sistema' => 'System settings',
            'In attesa di avvio' => 'Waiting to start',
            'Inserisci il nome della redazione.' => 'Enter the desk name.',
            'Inserisci nell’articolo' => 'Insert into article',
            'Insight performance' => 'Performance insight',
            'Intera testata' => 'Entire masthead',
            'JavaScript è disattivato: la gestione delle redazioni richiede JavaScript (form e albero sono riempiti via REST).' => 'JavaScript is disabled: desk management requires JavaScript (the form and the tree are filled via REST).',
            'L\'utente edita solo gli articoli della redazione scelta e delle sue sub-redazioni (esempio: Redazione Sport include Calcio, Basket e F1). Solo un amministratore puo\' modificare questo campo.' => 'The user edits only the articles of the selected desk and its sub-desks (example: Sport desk includes Calcio, Basket and F1). Only an administrator can change this field.',
            'La redazione collettiva impiega 1-3 minuti: non chiudere la pagina.' => 'The collaborative newsroom takes 1-3 minutes: do not close the page.',
            'Lascia la prima voce per una redazione di primo livello; scegli una redazione madre per annidare una sub-redazione (es. Calcio dentro Sport).' => 'Leave the first option for a top-level desk; choose a parent desk to nest a sub-desk (e.g. Calcio inside Sport).',
            'Lettura pagine web' => 'Web page reading',
            'Lingue del sito' => 'Site languages',
            'Lista articoli' => 'Articles list',
            'Log di pubblicazione' => 'Publishing log',
            'Modifica articolo Nexus' => 'Edit Nexus article',
            'Multilingua' => 'Multilanguage',
            'Multilingua — post tradotti nativi' => 'Multilanguage — native translated posts',
            'Navigazione lista articoli' => 'Articles list navigation',
            'Nessun agente configurato.' => 'No agents configured.',
            'Nessun articolo Nexus nel cestino.' => 'No Nexus articles found in trash.',
            'Nessun articolo Nexus trovato.' => 'No Nexus articles found.',
            'Nessun collegamento (post non generato da Nexus).' => 'No link (post not generated by Nexus).',
            'Nessun post tradotto ancora.' => 'No translated posts yet.',
            'Nessuna lingua attiva rilevata.' => 'No active language detected.',
            'Nessuna redazione' => 'No desks',
            'Nessuno' => 'None',
            'Nome redazione' => 'Desk name',
            'Nota' => 'Note',
            'Nota del capo redattore' => 'Editor-in-chief note',
            'Numero massimo di risultati restituiti da ogni ricerca web (predefinito 5).' => 'Maximum number of results returned by each web search (default 5).',
            'Nuovo articolo Nexus' => 'New Nexus article',
            'Ogni agente può scegliere provider e modello nella pagina Agenti; senza chiave qui il provider non è selezionabile. Il provider globale (sopra) resta il fallback.' => 'Each agent can choose provider and model on the Agents page; without a key here the provider cannot be selected. The global provider (above) remains the fallback.',
            'Organizza la testata in redazioni gerarchiche: una redazione madre può contenere sub-redazioni (es. Sport → Calcio, Basket, F1) e ogni redazione ha i suoi agenti assegnati. Ogni redazione ha anche un archivio pubblico con il tema attivo.' => 'Organize the masthead into hierarchical desks: a parent desk can contain sub-desks (e.g. Sport → Calcio, Basket, F1) and every desk has its own assigned agents. Each desk also has a public archive with the active theme.',
            'Permalink Nexus:' => 'Nexus permalink:',
            'Più agenti' => 'More agents',
            'Polylang è attivo: le associazioni tra lingue usano Polylang.' => 'Polylang is active: language associations use Polylang.',
            'Post WP ↗' => 'WP post ↗',
            'Provider AI per agente' => 'Per-agent AI provider',
            'Provider di ricerca web' => 'Web search provider',
            'Pubblica come post' => 'Publish as post',
            'Pubblica automaticamente le traduzioni come post' => 'Publish translations as posts automatically',
            'Pubblica l’articolo per attivare le traduzioni come post.' => 'Publish the article to enable translations as posts.',
            'Pubblicazione automatica dopo approvazione del capo redattore' => 'Automatic publishing after editor-in-chief approval',
            'Quante volte il capo può richiedere una revisione al giornalista AI prima di lasciare l\'articolo all\'umano (predefinito 2).' => 'How many times the editor-in-chief can request a revision from the AI journalist before leaving the article to the human (default 2).',
            'Quante volte l\'AI può usare uno strumento in fila prima della risposta finale. 0 = loop disattivato (predefinito 3).' => 'How many times the AI can use a tool in a row before the final answer. 0 = loop disabled (default 3).',
            'Redazione aggiornata.' => 'Desk updated.',
            'Redazione assegnata' => 'Assigned desk',
            'Redazione creata.' => 'Desk created.',
            'Redazione eliminata.' => 'Desk deleted.',
            'Redazione madre' => 'Parent desk',
            'Redazione non trovata: ricarica la pagina.' => 'Desk not found: reload the page.',
            'Redazione salvata ma fonti non salvate.' => 'Desk saved but sources not saved.',
            'Redazioni' => 'Desks',
            'Redazioni:' => 'Desks:',
            'Ricerca web (search)' => 'Web search (search)',
            'Ricerca web, lettura pagine, scoperta feed RSS e client MCP: gli agenti li usano per raccogliere fonti durante la raccolta e il capo redattore per verificare le notizie.' => 'Web search, page reading, RSS feed discovery and MCP client: the agents use them to collect sources during collection and the editor-in-chief to verify the news.',
            'Rimuovi immagine in evidenza' => 'Remove featured image',
            'Risultati per ricerca (1-10)' => 'Results per search (1-10)',
            'Rigoroso, sintetico e difensore della qualità editoriale' => 'Rigorous, concise and a defender of editorial quality',
            'Salva le impostazioni e riprova.' => 'Save the settings and try again.',
            // v1.1.7 (T-C): pannello "Test degli strumenti" (tools.php v1.1.7, T-B2).
            'Scoperta feed RSS (feeds)' => 'RSS feed discovery (feeds)',
            'Seleziona redazione…' => 'Select desk…',
            'Segna gli agenti che lavorano in questa redazione: la lista delle caselle è riempita da admin.js.' => 'Check the agents working in this desk: the checkbox list is filled by admin.js.',
            'Server MCP (uno per riga)' => 'MCP servers (one per line)',
            'Servizio usato per cercare notizie online. Nessuno disattiva la ricerca web (lettura pagine e client MCP restano disponibili).' => 'Service used to search news online. None turns the web search off (page reading and MCP client remain available).',
            'Singolo agente' => 'Single agent',
            'Slug (opzionale)' => 'Slug (optional)',
            'Stato delle traduzioni non disponibile.' => 'Translations status not available.',
            'Strumenti di ricerca' => 'Search tools',
            'Strumenti di ricerca per gli agenti' => 'Search tools for the agents',
            // v1.1.7 (T-C): pannello "Test degli strumenti" (tools.php v1.1.7, T-B2).
            'Strumento da testare' => 'Tool to test',
            'Tempo massimo per ogni richiesta di ricerca o lettura di pagina (predefinito 15 secondi).' => 'Max time for each search or page reading request (default 15 seconds).',
            // v1.1.7 (T-C): pannello "Test degli strumenti" (tools.php v1.1.7, T-B2).
            'Test degli strumenti' => 'Tools test',
            // v1.1.7 (T-C2): stato di caricamento del pannello test (admin.js
            // bindToolsTest: setBtnLoading + box.textContent).
            'Test in corso…' => 'Test running…',
            'Testata in corso…' => 'Masthead running…',
            'Testo massimo estratto da ogni pagina web letta da un agente (predefinito 6000).' => 'Max text extracted from each web page read by an agent (default 6000).',
            'Timeout richieste (secondi, 5-30)' => 'Request timeout (seconds, 5-30)',
            'Token' => 'Tokens',
            'Traduzioni come post' => 'Translations as posts',
            'Tutti gli articoli Nexus' => 'All Nexus articles',
            'URL base del provider Personalizzato' => 'Custom provider base URL',
            'Un server per riga nel formato nome|URL|tokenOpzionale (le righe che iniziano con # sono commenti). Solo server MCP compatibili con il trasporto Streamable HTTP (JSON-RPC 2.0 su HTTP): il token, se presente, viene inviato come Authorization: Bearer.' => 'One server per line in the name|URL|optionalToken format (lines starting with # are comments). Only MCP servers compatible with the Streamable HTTP transport (JSON-RPC 2.0 over HTTP): the token, if present, is sent as Authorization: Bearer.',
            'Una riga per fonte: rss|URL per un feed RSS diretto, site|URL per un sito di cui scoprire il feed automaticamente. Le fonti sono opzionali: i giornalisti della redazione le useranno in raccolta e il capo redattore per verificare.' => 'One line per source: rss|URL for a direct RSS feed, site|URL for a site whose feed is discovered automatically. Sources are optional: the desk journalists will use them for collection and the editor-in-chief for verification.',
            'Usa come immagine in evidenza' => 'Use as featured image',
            'Vedi' => 'View',
            'Vedi bozza WordPress' => 'View WordPress draft',
            'Vedi in WordPress' => 'View in WordPress',
            'Visualizza articoli' => 'View articles',
            'Visualizza articolo Nexus' => 'View Nexus article',
            'WPML è attivo: le associazioni tra lingue usano WPML.' => 'WPML is active: language associations use WPML.',
            'Avviare l\'intera testata? Verranno avviati tutti gli agenti.' => 'Run the entire masthead? All the agents will be started.',
            // v1.1.7 (T-C): hint empty-state del modal articoli (admin.js, emptyState).
            'Questo agente non ha ancora prodotto articoli.' => 'This agent has not produced any articles yet.',
            'Questo articolo è già passato dal capo redattore. Eseguire una nuova verifica?' => 'This article has already been reviewed by the editor-in-chief. Run a new review?',
            'Distribuzione social' => 'Social distribution',
            'errore di rete' => 'network error',
            // v1.1.7 (T-C): placeholder input pannello "Test degli strumenti" (tools.php).
            'es. intelligenza artificiale' => 'e.g. artificial intelligence',
            // v1.1.7 (T-C2): placeholder JS del pannello test (admin.js
            // toolsTestPlaceholder) e fallback di errore del box esito
            // (admin.js renderToolsTestResult, nodo testo).
            'https://esempio.it/pagina' => 'https://example.com/page',
            'https://esempio.it' => 'https://example.com',
            'lo strumento ha restituito un esito negativo.' => 'the tool returned a negative outcome.',
            'predefinita' => 'default',
            'salva prima la chiave' => 'save the key first',
            'sorgente' => 'source',
            'vedi post ↗' => 'view post ↗',
            'vuoto = modello globale' => 'empty = global model',
            'Avviare la pipeline editoriale completa?

Gli agenti raccoglieranno le notizie, le valuteranno, le riscriveranno e pubblicheranno gli articoli secondo le impostazioni correnti.' => 'Run the full editorial pipeline?

The agents will collect the news, evaluate it, rewrite it and publish the articles according to the current settings.',

            /* ---- v1.1.6 (T-01): messaggi esatti senza variabili (REST, pipeline) ---- */
            'Impostazioni aggiornate via API.' => 'Settings updated via API.',
            'Nessun candidato valido prodotto dall\'analisi evergreen.' => 'No valid candidate produced by the evergreen analysis.',
            'Avvio non riuscito' => 'Start failed',
            'Traduzione non riuscita' => 'Translation failed',
            'Pubblicazione traduzione non riuscita' => 'Translation publishing failed',
            'modelli disponibili dal provider' => 'models available from the provider',

            /* ---- v1.1.6 (T-01b): live strip compatta (admin.js) e frammenti compositi ---- */
            'Altri agenti: ' => 'More agents: ',
            'Nessun agente al lavoro.' => 'No agents at work.',
            'In redazione ora: ' => 'In the newsroom now: ',
            'stato non disponibile' => 'status not available',
            ' in attesa' => ' pending',
            ' in pausa' => ' paused',
            ' in errore' => ' error',
            ' agenti' => ' agents',
            ' agenti.' => ' agents.',
            ' agenti al lavoro' => ' agents at work',
            ' articoli' => ' articles',
            ' articolo' => ' article',
            ' errori' => ' errors',
            ' errore' => ' error',
            ' fonti' => ' sources',
            ' raccolti' => ' collected',
            ' raccolto' => ' collected',
            ' pubblicati' => ' published',
            ' nuovi articoli' => ' new articles',
            ' (qualità ' => ' (quality ',
            ' · Valutati ' => ' · Evaluated ',
            ' · Valutate: ' => ' · Evaluated: ',
            ' · Riscritti ' => ' · Rewritten ',
            ' · Riscritte: ' => ' · Rewritten: ',
            ' · Pubblicati ' => ' · Published ',
            ' · Pubblicate: ' => ' · Published: ',
            ' · aggiornato alle ' => ' · updated at ',
            ' · Agenti aggiornati: ' => ' · Agents updated: ',
            ' · Agenti creati: ' => ' · Agents created: ',
            ' · Errori: ' => ' · Errors: ',
            'Raccolta completata: ' => 'Collection completed: ',
            'Raccolte: ' => 'Collected: ',
            'Errore ' => 'Error ',
            ' Salva le impostazioni e riprova.' => ' Save the settings and try again.',
            'Chiave API AI non configurata: salva prima la chiave nelle Impostazioni.' => 'AI API key not configured: save the key in the settings first.',
            'Raccolti' => 'Collected',
            'Valutati' => 'Evaluated',
            'Riscritti' => 'Rewritten',
            ' agenti al lavoro' => ' agents at work',
            ' agente al lavoro' => ' agent at work',
            ' min fa' => ' min ago',
            ' ore fa' => ' hours ago',
            ' giorni fa' => ' days ago',
            ' fallito' => ' failed',
            ' falliti' => ' failed',
            ' riuscito' => ' succeeded',
            ' riusciti' => ' succeeded',
            ' pubblicato' => ' published',
            ' pubblicati' => ' published',

            /* ---- v1.1.6 (T-01b): frammenti dei confirm di admin.js (via window.NexusI18nTranslate) ----
             * NB: la chiave della seconda parte del confirm di eliminazione redazione
             * contiene DUE newline reali (stringa PHP su più righe), esattamente come
             * la stringa JS '”?\n\nLe sub-redazioni…' di admin.js. */
            'Avviare l’intera testata? Verranno avviati ' => 'Run the entire masthead? ',
            'Avviare l’intera testata? Verranno avviati tutti gli agenti.' => 'Run the entire masthead? All agents will be started.',
            'Eliminare la redazione “' => 'Delete desk "',
            '”?

Le sub-redazioni figlie verranno riassegnate alla redazione madre. Gli articoli non vengono cancellati.' => '"?

Child desks will be reassigned to the parent desk. Articles are not deleted.',
            // v1.1.7 (T-C2): frammenti del confirm di eliminazione agente
            // (admin.js bindAgents: 'Eliminare l’agente "' + name + '"? Gli
            // articoli restano pubblicati ma senza autore assegnato.'):
            // apostrofo TIPOGRAFICO in "l’agente" e virgolette dritte attorno
            // al nome, esattamente come nel sorgente JS (stessa via di nxaT
            // usata dai frammenti del confirm redazione).
            'Eliminare l’agente "' => 'Delete agent "',
            '"? Gli articoli restano pubblicati ma senza autore assegnato.' => '"? Articles stay published but without an assigned author.',

            /* ---- v1.1.6 (T-01b): frammenti degli <script> inline delle viste (strtr lato PHP) ---- */
            ' annullato' => ' cancelled',
            ' creati, ' => ' created, ',
            ' lavori' => ' jobs',
            ' rimesso in coda' => ' requeued',
            'Annullare il lavoro #' => 'Cancel job #',
            'Lavoro #' => 'Job #',
            'Redazione #' => 'Desk #',
            'Eliminati ' => 'Deleted ',
            'Errore HTTP ' => 'HTTP error ',
            'Impostazioni aggiornate: ' => 'Settings updated: ',
            'Impossibile caricare la coda: ' => 'Unable to load the queue: ',
            'Batch eseguito: ' => 'Batch run: ',
            ' falliti' => ' failed',
            'Test provider AI non riuscito: ' => 'AI provider test failed: ',
            'Import non riuscito: ' => 'Import failed: ',
            'Esportazione non riuscita: ' => 'Export failed: ',
            'Generazione chiave non riuscita: ' => 'Key generation failed: ',

            /* ---- v1.1.6 (T-01b): frammenti compositi riepilogo redazioni / traduzioni (admin.js) ---- */
            'agenti assegnati' => 'agents assigned',
            'agenti: ' => 'agents: ',
            'articoli coperti' => 'articles covered',
            'qualità ' => 'quality ',
            '” è in coda di approvazione' => '" is in the approval queue',
            'Traduzione completata (' => 'Translation completed (',
            'Traduzione pubblicata come post (' => 'Translation published as a post (',
            'Traduzione già pubblicata come post (' => 'Translation already published as a post (',
            'Salvataggio redazione non riuscito' => 'Desk save failed',
            'Verifico…' => 'Checking…',
            '🎖 Revisione capo redattore' => '🎖 Editor-in-chief review',

            /* ---- v1.1.6 (T-01b): viste PHP (dashboard, redazioni, caps, cpt) ---- */
            '%s articolo' => '%s article',
            '%s articoli' => '%s articles',
            'Agente #%d' => 'Agent #%d',
            'Agenti assegnati: %s' => 'Assigned agents: %s',
            '(tutte — nessuna restrizione)' => '(all — no restrictions)',
            '(nessuna — redazione madre)' => '(none — parent desk)',
            'Articolo pubblicato.' => 'Article published.',
            'Vuoto = slug generato dal nome.' => 'Empty = slug generated from the name.',

            /* ---- v1.1.6 (T-01b): UI residua di admin.js (card agenti, redazioni, traduzioni) ---- */
            'Nessun agente da avviare in questo ambito.' => 'No agents to start in this scope.',
            'Provider globale (impostazioni)' => 'Global provider (settings)',
            'Revisione capo redattore completata.' => 'Editor-in-chief review completed.',
            'Revisione capo redattore non riuscita' => 'Editor-in-chief review failed',
            'Mostra o nascondi le sub-redazioni' => 'Show or hide the sub-desks',
            'Punteggio fact-check AI' => 'AI fact-check score',
            'Punteggio qualità' => 'Quality score',
            '▶ Avvia redazione' => '▶ Run desk',
            '▶ Avvia' => '▶ Run',
            'Nessuna traduzione pubblicata come post.' => 'No translation published as a post.',
            'Agente AI su ricerca, spazio, ambiente e scoperte scientifiche.' => 'AI agent for research, space, environment and scientific discoveries.',
            'Analitico e orientato all’impatto pratico' => 'Analytical and focused on practical impact',
            'Legge gli articoli dei giornalisti e li approva, modifica, manda in revisione o rifiuta.' => 'Reads the journalists’ articles and approves, edits, sends them for review or rejects them.',

            /* ---- v1.1.10.1 — audit v1.1.10: 64 stringhe nuove senza EN
             * (provider custom illimitati, OAuth OpenRouter PKCE, model picker,
             * strumenti/fonti per agente, editor fonti redazioni, messaggi REST).
             * Tre voci sono identiche in EN ('Brave Search', 'Serper (Google Search)',
             * 'Test'): dichiarate coperte perché la traduzione corretta è quella stessa. ---- */
            ' Se l’endpoint è in rete locale o sull’host di WordPress verifica che sia attivo e raggiungibile.' => ' If the endpoint is on the local network or on the WordPress host, check that it is running and reachable.',
            'Aggiungi fonte' => 'Add source',
            'Aggiungi le fonti della redazione: feed RSS, siti, canali YouTube o Telegram, subreddit, ricerche, server MCP o pagine web. Usa “Test” per provare una fonte prima di salvare. Le fonti sono opzionali: i giornalisti della redazione le useranno in raccolta e il capo redattore per verificare.' => 'Add the desk sources: RSS feeds, sites, YouTube or Telegram channels, subreddits, searches, MCP servers or web pages. Use “Test” to try a source before saving. Sources are optional: the desk journalists will use them for collection and the editor-in-chief for fact-checking.',
            'Aggiungi provider personalizzato' => 'Add custom provider',
            'Avvio del login web non riuscito: riprova.' => 'Web login could not be started: try again.',
            'Brave Search' => 'Brave Search',
            'Chiave API' => 'API key',
            'Chiave API mancante per il provider ' => 'Missing API key for provider ',
            'Chiave API mancante per il provider %1$s: connettiti con il login web o salva una chiave e riprova.' => 'Missing API key for provider %1$s: connect with the web login or save a key and try again.',
            'Chiave API mancante per il provider %1$s: salvala in Impostazioni AI e riprova.' => 'Missing API key for provider %1$s: save it in AI Settings and try again.',
            'Chiave configurata' => 'Key configured',
            'Chiave non configurata' => 'Key not configured',
            'Chiave ricerca web dell’agente' => 'Agent web search key',
            'Connessione OAuth non riuscita: riavvia il login.' => 'OAuth connection failed: restart the login.',
            'Connetti con %s' => 'Connect with %s',
            'Consenti endpoint AI su IP privati/localhost' => 'Allow AI endpoints on private IPs/localhost',
            'Digita per cercare tra i modelli del provider scelto: clic o Invio per scegliere, Esc per chiudere.' => 'Type to search the models of the selected provider: click or Enter to choose, Esc to close.',
            'Endpoint compatibile OpenAI usato dal provider Personalizzato (es. proxy interno o modello self-hosted). Il bottone Test può verificarla anche prima del salvataggio.' => 'OpenAI-compatible endpoint used by the Custom provider (e.g. internal proxy or self-hosted model). The Test button can verify it even before saving.',
            'Endpoint modelli non configurato per questo provider: il test live non è disponibile.' => 'Models endpoint not configured for this provider: the live test is unavailable.',
            'Endpoint su IP privati/localhost' => 'Endpoints on private IPs/localhost',
            'Etichetta' => 'Label',
            'I server MCP dell’agente si sommano a quelli globali (in caso di conflitto vince l’agente). Formato: nome|URL|token opzionale; le righe che iniziano con # sono commenti.' => 'The agent’s MCP servers are added to the global ones (in case of conflict the agent wins). Format: name|URL|optional token; lines starting with # are comments.',
            'Identificativo del provider personalizzato non valido: deve avere la forma “cust_” + 8 caratteri esadecimali.' => 'Invalid custom provider identifier: it must have the form “cust_” + 8 hexadecimal characters.',
            'Il catalogo provider non è disponibile: il plugin non è caricato correttamente.' => 'The provider catalog is unavailable: the plugin is not loaded correctly.',
            'Il modulo fonti non è disponibile: verifica che il plugin sia aggiornato.' => 'The sources module is unavailable: make sure the plugin is up to date.',
            'Il provider ha risposto con errore HTTP ' => 'The provider responded with HTTP error ',
            'Il provider non ha restituito un elenco di modelli riconoscibile.' => 'The provider did not return a recognizable list of models.',
            'Il registry dei provider AI non è disponibile: verifica che il plugin sia aggiornato.' => 'The AI provider registry is unavailable: make sure the plugin is up to date.',
            'Il salvataggio della chiave per questo agente arriva con un prossimo aggiornamento: attualmente viene usata la chiave di ricerca globale (impostazioni → Strumenti).' => 'Saving a key for this agent will arrive in a future update: currently the global search key is used (settings → Tools).',
            'Login web OAuth non disponibile per questo provider: usa la chiave API manuale.' => 'OAuth web login unavailable for this provider: use the manual API key.',
            'Mai mostrata in chiaro. Su modifica, vuota = nessun cambio della chiave salvata.' => 'Never shown in plain text. When editing, empty = no change to the saved key.',
            'Nessun adapter disponibile per questo tipo di fonte.' => 'No adapter available for this source type.',
            'Nessun provider personalizzato: aggiungine uno per usare qualsiasi servizio compatibile OpenAI (Together, Fireworks, Perplexity, Cerebras, LM Studio, vLLM…).' => 'No custom providers: add one to use any OpenAI-compatible service (Together, Fireworks, Perplexity, Cerebras, LM Studio, vLLM…).',
            'Ollama locale (127.0.0.1:11434): il rilevamento funziona se WordPress gira sulla stessa macchina di Ollama (o su Ollama esposto in LAN con l’opzione qui sotto).' => 'Local Ollama (127.0.0.1:11434): detection works if WordPress runs on the same machine as Ollama (or on Ollama exposed on the LAN with the option below).',
            'Ottieni chiave' => 'Get key',
            'Parametri OAuth mancanti (state o code): riavvia il login da Impostazioni AI.' => 'Missing OAuth parameters (state/code): restart the login from AI Settings.',
            'Preset (precompila i campi)' => 'Preset (pre-fills the fields)',
            'Provider AI sconosciuto.' => 'Unknown AI provider.',
            'Provider AI sconosciuto: ' => 'Unknown AI provider: ',
            'Provider non raggiungibile: ' => 'Provider unreachable: ',
            'Provider personalizzati' => 'Custom providers',
            'Provider personalizzato non trovato: forse è già stato eliminato.' => 'Custom provider not found: it may have already been deleted.',
            'Provider personalizzato sconosciuto o eliminato (' => 'Unknown or deleted custom provider (',
            'Provider ricerca web (vuoto = globale)' => 'Web search provider (empty = global)',
            'Questo provider non ha una chiave configurabile nelle impostazioni.' => 'This provider has no configurable key in the settings.',
            'Rate limit del provider raggiunto (HTTP 429): riprova tra qualche minuto.' => 'Provider rate limit reached (HTTP 429): try again in a few minutes.',
            'Ricerca web globale (impostazioni)' => 'Global web search (settings)',
            'Rileva Ollama' => 'Detect Ollama',
            'Salva provider' => 'Save provider',
            'Salvataggio del provider personalizzato non riuscito.' => 'Custom provider could not be saved.',
            'Scollega' => 'Disconnect',
            'Se impostato, questo agente usa il proprio motore di ricerca invece di quello globale; se la chiave di ricerca globale non è configurata la ricerca dell’agente restituisce un errore chiaro.' => 'If set, this agent uses its own search engine instead of the global one; if the global search key is not configured, the agent’s search returns a clear error.',
            'Serper (Google Search)' => 'Serper (Google Search)',
            'Server MCP personali (una riga per server)' => 'Personal MCP servers (one per line)',
            'Solo se usi LM Studio/vLLM su LAN; Ollama è sempre ammesso.' => 'Only if you use LM Studio/vLLM on LAN; Ollama is always allowed.',
            'Strumenti personali (opzionali)' => 'Personal tools (optional)',
            'Test' => 'Test',
            'Tipo di fonte non valido. Valori ammessi: rss, site, youtube, telegram, reddit, search, mcp, web.' => 'Invalid source type. Allowed values: rss, site, youtube, telegram, reddit, search, mcp, web.',
            'URL base (compatibile OpenAI)' => 'Base URL (OpenAI-compatible)',
            'URL base mancante per il provider ' => 'Missing base URL for provider ',
            'URL base mancante per il provider Personalizzato: inseriscila nel campo “URL base” e riprova.' => 'Missing base URL for the Custom provider: enter it in the “Base URL” field and try again.',
            'URL base mancante per il provider personalizzato ' => 'Missing base URL for custom provider ',
            'Valore della fonte mancante.' => 'Missing source value.',
            '— Nessun preset: compilazione manuale —' => '— No preset: fill in manually —',

            // v1.1.11 (R1): sezione "Fonti RSS globali" (settings-contenuti.php).
            'Feed RSS globali (una URL per riga)' => 'Global RSS feeds (one URL per line)',
            'Fonti RSS globali' => 'Global RSS sources',
            'Usate dagli agenti che non hanno fonti nella propria redazione (una URL http(s) per riga; le righe che iniziano con # sono commenti). Se vuote, nessuna raccolta automatica: configura le fonti delle redazioni o queste globali.' => 'Used by agents that have no sources in their own desk (one http(s) URL per line; lines starting with # are comments). When empty, no automatic collection: configure the desk sources or these global ones.',

            /* v1.1.11 (R5) — guardia fetch catalogo modelli: voci nuove APPENDITE
             * in coda (fuori dall'ordine alfabetico) per minimizzare i conflitti
             * con lo sweep i18n della stessa release; chi riordina può reintegrarle. */
            ' (cache scaduta: il provider non ha risposto)' => ' (stale cache: the provider did not respond)',
            ' (fetch disattivato)' => ' (fetch disabled)',
            'Caricamento modelli disattivato dalle impostazioni: nessun catalogo in cache.' => 'Model loading disabled in settings: no cached catalog.',
            'Catalogo modelli' => 'Model catalog',
            'Disattivalo per lavorare solo con i modelli memorizzati in cache: nessuna richiesta extra ai provider.' => 'Turn it off to work only with the models stored in the cache: no extra requests to providers.',
            'Scarica catalogo modelli dal provider' => 'Download model catalog from the provider',

            /* v1.1.11 (R4-1) — class-nexus-rest.php (messaggi REST). Voci nuove
             * APPENDITE in coda (fuori dall'ordine alfabetico) per minimizzare i
             * conflitti con gli altri sweep i18n della stessa release. Le stringhe
             * con placeholder (%s/%d) vivono in nexus_i18n_extra_patterns(). */
            'Non hai i permessi per accedere a questa risorsa.' => 'You are not allowed to access this resource.',
            'Nonce non valido: ricarica la pagina e riprova.' => 'Invalid nonce: reload the page and try again.',
            'Il nome dell\'agente è obbligatorio.' => 'The agent name is required.',
            'La categoria dell\'agente è obbligatoria.' => 'The agent category is required.',
            'Impossibile creare l\'agente (verifica nome e categoria).' => 'Unable to create the agent (check name and category).',
            'Nessun campo da aggiornare.' => 'No fields to update.',
            'Il nome dell\'agente non può essere vuoto.' => 'The agent name cannot be empty.',
            'La categoria dell\'agente non può essere vuota.' => 'The agent category cannot be empty.',
            'Impossibile clonare l\'agente.' => 'Unable to clone the agent.',
            'Impossibile eliminare l\'agente.' => 'Unable to delete the agent.',
            'Il provider AI non ha restituito un elenco di modelli riconoscibile.' => 'The AI provider did not return a recognizable list of models.',
            '). Ricontrolla la sezione Provider personalizzati.' => '). Check the Custom providers section.',
            ': correggilo in Impostazioni → Provider AI.' => ': fix it in Settings → Provider AI.',
            '. Scegli un provider del catalogo (o un id cust_*).' => '. Choose a provider from the catalog (or a cust_* id).',
            ': compila l\'URL base del provider Personalizzato in Impostazioni → Provider AI.' => ': fill in the Custom provider base URL in Settings → Provider AI.',
            ': connetti il provider prima (Impostazioni → Provider AI) e salva la chiave, poi ricarica i modelli.' => ': connect the provider first (Settings → Provider AI), save the key, then reload the models.',
            'Errore di connessione al provider AI: ' => 'AI provider connection error: ',
            '). Verifica la chiave nelle Impostazioni.' => '). Check the key in Settings.',
            'Il provider AI ha risposto con errore HTTP ' => 'The AI provider responded with HTTP error ',
            ' sul controllo della chiave.' => ' on the key check.',
            '): verifica la chiave nelle impostazioni o riconnettiti.' => '): check the key in the settings or reconnect.',
            'Impossibile eliminare l\'articolo.' => 'Unable to delete the article.',
            'Modulo pipeline non disponibile.' => 'Pipeline module not available.',
            'Nessun agente valido tra quelli indicati.' => 'No valid agents among the given ones.',
            'Il modulo redazioni non è disponibile: verifica che il plugin sia aggiornato.' => 'The newsrooms module is not available: make sure the plugin is up to date.',
            'Redazione non trovata.' => 'Desk not found.',
            'La redazione selezionata non ha agenti assegnati: nessun avvio.' => 'The selected desk has no assigned agents: nothing to start.',
            'Nessun agente attivo in redazione: nessun avvio.' => 'No active agents in the desk: nothing to start.',
            'La pipeline non ha restituito un risultato valido.' => 'The pipeline did not return a valid result.',
            'Errore sconosciuto durante la pipeline.' => 'Unknown error during the pipeline.',
            'Parametro article_id mancante.' => 'Missing article_id parameter.',
            'Nessun campo da aggiornare nel corpo della richiesta.' => 'No fields to update in the request body.',
            'Nessuna chiave riconosciuta tra i campi inviati.' => 'No recognized key among the submitted fields.',
            'Il modulo database non è disponibile: verifica che il plugin sia aggiornato.' => 'The database module is not available: make sure the plugin is up to date.',
            'Il campo "topic" è obbligatorio.' => 'The "topic" field is required.',
            'Il campo "emoji" è obbligatorio.' => 'The "emoji" field is required.',
            'Emoji non ammessa: scegli una delle reazioni disponibili.' => 'Emoji not allowed: choose one of the available reactions.',
            'Troppe reazioni: riprova tra poco.' => 'Too many reactions: try again shortly.',
            'Impossibile registrare la reazione.' => 'Unable to record the reaction.',
            'Coda lavori non disponibile.' => 'Jobs queue not available.',
            'Lavoro non trovato.' => 'Job not found.',
            'Lo stato attuale non consente il retry.' => 'The current status does not allow a retry.',
            'Solo i lavori in attesa possono essere annullati.' => 'Only pending jobs can be cancelled.',
            'Analytics non disponibile.' => 'Analytics not available.',
            'Azione budget non valida. Valori ammessi: stop, warn.' => 'Invalid budget action. Allowed values: stop, warn.',
            'Modalità non valida. Valori ammessi: semi_autonomous, fully_autonomous.' => 'Invalid mode. Allowed values: semi_autonomous, fully_autonomous.',
            'Dimensione immagini non valida. Valori ammessi: 1024x1024, 1792x1024, 1024x1792.' => 'Invalid image size. Allowed values: 1024x1024, 1792x1024, 1024x1792.',
            'Provider di ricerca non valido. Valori ammessi: tavily, serper, brave, none.' => 'Invalid search provider. Allowed values: tavily, serper, brave, none.',
            'DB non disponibile.' => 'DB not available.',
            'Corpo JSON non valido.' => 'Invalid JSON body.',
            'Il campo "settings" deve essere un array.' => 'The "settings" field must be an array.',
            'Il campo "agents" deve essere una lista di array.' => 'The "agents" field must be a list of arrays.',
            'Agente non valido: ' => 'Invalid agent: ',
            'Chiave generata: copiala subito, non verrà più mostrata (nel database è salvato solo il suo hash SHA-256).' => 'Key generated: copy it now, it will not be shown again (only its SHA-256 hash is stored in the database).',
            'Il nome della redazione è obbligatorio.' => 'The desk name is required.',
            'Il nome della redazione non può essere vuoto.' => 'The desk name cannot be empty.',
            'Una redazione non può essere madre di se stessa.' => 'A desk cannot be its own parent.',
            'La redazione madre scelta è una sub-redazione di questa: lo spostamento creerebbe un ciclo.' => 'The selected parent desk is a sub-desk of this one: the move would create a cycle.',
            'Eliminazione della redazione non riuscita.' => 'Desk deletion failed.',
            'Il modulo multilingua non è disponibile: verifica che il plugin sia aggiornato.' => 'The multilanguage module is not available: make sure the plugin is up to date.',
            'Parametro lang mancante o non valido.' => 'Missing or invalid lang parameter.',
            'Il modulo traduzioni non è disponibile: verifica che il plugin sia aggiornato.' => 'The translations module is not available: make sure the plugin is up to date.',
            'Il modulo traduzioni non ha restituito un risultato valido.' => 'The translations module did not return a valid result.',
            'Traduzione non riuscita per un motivo non specificato dal modulo traduzioni.' => 'Translation failed for a reason not specified by the translations module.',
            'L\'articolo non ha ancora un post WordPress nativo: pubblicalo per attivare le traduzioni come post.' => 'The article does not have a native WordPress post yet: publish it to enable translations as posts.',
            'Il modulo multilingua non è disponibile: impossibile elencare le traduzioni collegate.' => 'The multilanguage module is not available: unable to list the linked translations.',
            'Gli strumenti di ricerca non sono disponibili: verifica che il plugin sia aggiornato.' => 'The search tools are not available: make sure the plugin is up to date.',
            'Strumento non valido. Valori ammessi: search, read, feeds, mcp.' => 'Invalid tool. Allowed values: search, read, feeds, mcp.',
            'Parametro query mancante per il test di ricerca.' => 'Missing query parameter for the search test.',
            'Parametro url mancante per il test di lettura pagina.' => 'Missing url parameter for the page read test.',
            'Parametro url mancante per il test di RSS discovery.' => 'Missing url parameter for the RSS discovery test.',
            'Parametro server mancante per il test MCP.' => 'Missing server parameter for the MCP test.',
            'Parametro tool_name mancante per il test MCP.' => 'Missing tool_name parameter for the MCP test.',
            'Il modulo capo redattore non è disponibile: verifica che il plugin sia aggiornato.' => 'The chief editor module is not available: make sure the plugin is up to date.',
            'Capo redattore non disponibile: attivalo nelle impostazioni.' => 'Chief editor not available: enable it in the settings.',
            /* v1.1.11 (R4-2) — class-nexus-pipeline.php (log attività/fasi) */
            'Agente non trovato.' => 'Agent not found.',
            'Agente non attivo: pipeline saltata.' => 'Agent not active: pipeline skipped.',
            'Approvazione automatica prima della pubblicazione (modalità fully autonomous).' => 'Automatic approval before publication (fully autonomous mode).',
            'Articolo già pubblicato in precedenza: nessuna nuova pubblicazione.' => 'Article already published previously: no new publication.',
            'Errore pipeline: ' => 'Pipeline error: ',
            'Fase di valutazione saltata: budget mensile esaurito.' => 'Evaluation phase skipped: monthly budget exhausted.',
            'In attesa del capo redattore.' => 'Awaiting the editor-in-chief.',
            'La risposta AI non contiene un JSON valido con il contenuto dell\'articolo.' => 'The AI response does not contain valid JSON with the article content.',
            'La direzione non ha prodotto un articolo valido (JSON mancante o incompleto).' => 'The editorial desk did not produce a valid article (missing or incomplete JSON).',
            'Nessun agente attivo in redazione.' => 'No active agents in the newsroom.',
            'Nessun nuovo candidato raccolto: pipeline terminata.' => 'No new candidates collected: pipeline finished.',
            'Nessuna piattaforma di pubblicazione configurata o articolo non valido.' => 'No publishing platform configured or invalid article.',
            'Non è possibile rifiutare un articolo già pubblicato.' => 'A published article cannot be rejected.',
            'Raccoglie le fonti' => 'Collecting sources',
            'Raccolta automatica disabilitata nelle impostazioni: pipeline non avviata.' => 'Automatic collection disabled in settings: pipeline not started.',
            'Revisione del capo redattore' => 'Editor-in-chief review',
            'Salvataggio dell\'articolo Nexus Wire fallito.' => 'Nexus Wire article could not be saved.',
            'Scrive l\'articolo' => 'Writing the article',
            'Tema mancante: specifica un argomento per il Nexus Wire.' => 'Missing topic: specify a subject for the Nexus Wire.',
            'approvazione/pubblicazione' => 'approval/publishing',
            'nessun dettaglio fornito dal modello' => 'no details provided by the model',
            'nuovo' => 'new',
            /* v1.1.11 (R4-3) — class-nexus-chief.php / class-nexus-tools.php:
             * messaggi di errore, note e log utente (i messaggi con variabili
             * sono in nexus_i18n_extra_patterns()). */
            'Troppe revisioni concatenate in questa richiesta: interrotto per prevenire loop.' => 'Too many chained revisions in this request: stopped to prevent a loop.',
            'Layer dati Nexus_DB non disponibile.' => 'Nexus_DB database layer unavailable.',
            'Articolo non trovato.' => 'Article not found.',
            'Capo redattore non attivo nelle impostazioni.' => 'Editor-in-chief not enabled in the settings.',
            'Nessun capo redattore disponibile per questa categoria/redazione.' => 'No editor-in-chief available for this category/desk.',
            'Revisione capo redattore gia\' in corso per questo articolo.' => 'Editor-in-chief review already in progress for this article.',
            'budget AI di revisione esaurito per questo articolo' => 'review AI budget exhausted for this article',
            'Budget AI di revisione esaurito per questo articolo: revisione sospesa.' => 'Review AI budget exhausted for this article: review suspended.',
            'Motore AI non disponibile.' => 'AI engine unavailable.',
            'Risposta del capo redattore non interpretabile (JSON mancante o non valido).' => 'Editor-in-chief response could not be parsed (missing or invalid JSON).',
            'Contatore giri di revisione non persistito: revisione interrotta per prevenire loop.' => 'Review round counter not persisted: review stopped to prevent a loop.',
            'article_id mancante o non valido.' => 'Missing or invalid article_id.',
            'aggiornamento database fallito (colonne chief_ della v1.1.4 mancanti?)' => 'database update failed (missing chief_ columns from v1.1.4?)',
            'Query di ricerca vuota: specifica il testo da cercare (argomento "query").' => 'Empty search query: specify the text to search (argument "query").',
            'La ricerca web non è attiva: il provider di ricerca è impostato su "none" nelle impostazioni del plugin.' => 'Web search is not active: the search provider is set to "none" in the plugin settings.',
            'Chiave ricerca agente non configurata: l\'agente usa un provider di ricerca personale ma non esiste una chiave utilizzabile (configura la chiave personale nel modal dell\'agente o una chiave globale nelle impostazioni del plugin).' => 'Agent search key not configured: the agent uses a personal search provider but no usable key exists (set the personal key in the agent modal or a global key in the plugin settings).',
            'Chiave API di ricerca web mancante: compila il campo della chiave di ricerca nelle impostazioni del plugin.' => 'Missing web search API key: fill in the search key field in the plugin settings.',
            'Brave Search: URL di richiesta non valido.' => 'Brave Search: invalid request URL.',
            'Brave Search: la risposta non è un JSON valido.' => 'Brave Search: the response is not valid JSON.',
            'URL mancante: specifica la pagina da leggere (argomento "url").' => 'Missing URL: specify the page to read (argument "url").',
            'Lettura pagina: la pagina ha risposto con un corpo vuoto.' => 'Page read: the page returned an empty body.',
            'Lettura pagina: nessun testo leggibile estratto dalla pagina.' => 'Page read: no readable text extracted from the page.',
            'Chiamata MCP incompleta: servono il nome del server e il nome del tool.' => 'Incomplete MCP call: both the server name and the tool name are required.',
            'Le funzioni HTTP di WordPress non sono disponibili.' => 'WordPress HTTP functions are not available.',
            'Impossibile preparare la richiesta MCP (encoding JSON fallito).' => 'Unable to prepare the MCP request (JSON encoding failed).',
            'La risposta del server MCP non contiene un JSON valido (neppure come righe "data:" di un event-stream).' => 'The MCP server response does not contain valid JSON (not even as "data:" lines of an event-stream).',
            'Nessuno (solo lettura pagine e feed)' => 'None (page and feed reading only)',
            'Il motore AI Nexus_AI_Engine non è disponibile: impossibile eseguire il ciclo strumenti.' => 'The Nexus_AI_Engine AI engine is unavailable: cannot run the tool loop.',
            'Nessun messaggio valido da inviare al modello nel ciclo strumenti.' => 'No valid message to send to the model in the tool loop.',
            'La chiamata al modello AI non ha restituito una risposta valida.' => 'The AI model call did not return a valid response.',
            'Il motore AI Nexus_AI_Engine non è disponibile: il plugin Nexus News AI non è caricato correttamente.' => 'The Nexus_AI_Engine AI engine is unavailable: the Nexus News AI plugin is not loaded correctly.',
            'La lettura di pagine web non è attiva nelle impostazioni del plugin.' => 'Web page reading is not active in the plugin settings.',
            'Nessun elemento letto dal feed: URL non valido, feed irraggiungibile o vuoto.' => 'No items read from the feed: invalid URL, unreachable or empty feed.',
            'Impossibile preparare il corpo della richiesta (encoding JSON fallito).' => 'Unable to prepare the request body (JSON encoding failed).',
            'La risposta del servizio non è un JSON valido.' => 'The service response is not valid JSON.',

            /* v1.1.11 (R4-4) — assets/js/admin.js (stringhe UI client). Voci nuove
             * APPENDITE in coda (nessuna voce esistente modificata): wizard
             * onboarding, ricerca modelli, OAuth/login web, chiavi provider,
             * provider personalizzati, redazioni. */
            '(senza nome)' => '(unnamed)',
            'Avvio del login non riuscito' => 'Login start failed',
            'Avvio del login web in corso…' => 'Starting web login…',
            'Caricamento dei provider personalizzati non riuscito.' => 'Custom providers could not be loaded.',
            'Cerca modello' => 'Search models',
            'Cerca modello per id o nome…' => 'Search models by id or name…',
            'Chiave rimossa: il provider è scollegato.' => 'Key removed: the provider is disconnected.',
            'Chiave salvata' => 'Key saved',
            'Completamento della connessione in corso…' => 'Completing the connection…',
            'Connessione OpenRouter' => 'OpenRouter connection',
            'Connessione OpenRouter non riuscita' => 'OpenRouter connection failed',
            'Connessione non riuscita' => 'Connection failed',
            'Connessione…' => 'Connecting…',
            'Connesso via login web: la chiave è stata salvata.' => 'Connected via web login: the key has been saved.',
            /* v1.1.17 («Pong Onesto») — esito del test provider (frammenti JS,
             * tradotti client-side via nxaT/mappa esatta). */
            'Generazione OK' => 'Generation OK',
            'Generazione fallita' => 'Generation failed',
            'risposta troncata' => 'truncated response',
            'Attenzione: il modello impostato non è nella lista modelli di questo provider.' => 'Warning: the configured model is not in this provider\'s model list.',
            'Direzione' => 'Leadership',
            'Divulgativo ma preciso, con spiegazioni chiare' => 'Accessible but precise, with clear explanations',
            'Eliminare definitivamente? Gli agenti che lo usavano mostreranno un errore finché non riassegni un provider.' => 'Delete permanently? Agents that were using it will show an error until you reassign a provider.',
            'Etichetta e URL base sono obbligatori.' => 'Label and base URL are required.',
            'Etichetta e URL base sono obbligatori per il provider personalizzato.' => 'Label and base URL are required for the custom provider.',
            'Modifica in corso: la chiave salvata resta invariata se lasci vuota.' => 'Editing in progress: the saved key stays unchanged if left empty.',
            'Nessuna chiave' => 'No key',
            'OpenRouter connesso: la chiave è stata salvata.' => 'OpenRouter connected: the key has been saved.',
            'OpenRouter non ha completato il login: ' => 'OpenRouter did not complete the login: ',
            'Provider personalizzato salvato: ora è selezionabile dagli agenti.' => 'Custom provider saved: agents can now select it.',
            'Redazione' => 'Desk',
            'Rimuovere la chiave salvata per questo provider? Gli agenti non potranno più usarlo finché non la riconfiguri o ti riconnetti.' => 'Remove the saved key for this provider? Agents will not be able to use it until you reconfigure it or sign in again.',
            'Ritorno dal login incompleto (state o code mancanti): riavvia la connessione da Impostazioni AI.' => 'Incomplete login return (missing state/code): restart the connection from AI Settings.',
            'Salvo le fonti RSS…' => 'Saving RSS sources…',
            'Scollegamento' => 'Disconnect',
            'Scollegamento non riuscito' => 'Disconnect failed',
            'Sì, elimina' => 'Yes, delete',
            'Test connessione in corso…' => 'Connection test in progress…',
            'Test non riuscito' => 'Test failed',
            'URL di autorizzazione mancante nella risposta del server.' => 'Authorization URL missing from the server response.',

            /* v1.1.11 (R4-5) — class-nexus-image.php / class-nexus-translate.php.
             * Voci APPENDITE in coda (nessuna voce esistente modificata): note ed
             * errori della pipeline featured image (AI, image_url, Pixabay, gate
             * qualità, guardie SSRF/SVG/MIME) ed errori REST/activity delle
             * traduzioni. Escluso il prompt AI verso il provider; i messaggi con
             * variabili sono coperti dai pattern in coda a nexus_i18n_extra_patterns(). */
            'Aggiornamento del post tradotto non riuscito.' => 'Updating the translated post failed.',
            'Aggiornamento del post tradotto non riuscito: ambiente WordPress incompleto.' => 'Updating the translated post failed: incomplete WordPress environment.',
            'AI: chiamata di traduzione non riuscita.' => 'AI: translation call failed.',
            'AI: risposta non valida, JSON atteso non ricevuto.' => 'AI: invalid response, expected JSON not received.',
            'AI: traduzione incompleta, titolo mancante.' => 'AI: incomplete translation, missing title.',
            'AI engine non disponibile: traduzione impossibile.' => 'AI engine unavailable: translation impossible.',
            'Articolo o lingua non validi.' => 'Invalid article or language.',
            'chiave Pixabay (setting pixabay_key) non configurata: anello ③ disattivato' => 'Pixabay key (setting pixabay_key) not configured: ring ③ disabled',
            'decodifica base64 dell\'immagine generata fallita' => 'Base64 decoding of the generated image failed',
            'download: contenuto vuoto' => 'Download: empty content',
            'generazione AI saltata (chiave API non configurata)' => 'AI generation skipped (API key not configured)',
            'ID articolo non valido.' => 'Invalid article ID.',
            'immagine dall\'articolo (image_url)' => 'Image from article (image_url)',
            'il file remoto è un SVG: formato non ammesso per le immagini degli articoli' => 'The remote file is an SVG: format not allowed for article images',
            'il file scaricato non è un\'immagine riconosciuta' => 'The downloaded file is not a recognized image',
            'il file scaricato non è un\'immagine valida' => 'The downloaded file is not a valid image',
            'il servizio immagini non ha restituito né b64_json né url' => 'The image service returned neither b64_json nor url',
            'l\'immagine generata non è in un formato riconosciuto' => 'The generated image is not in a recognized format',
            'L\'articolo non ha un post WordPress nativo: pubblica o migra l\'articolo prima.' => 'The article has no native WordPress post: publish or migrate the article first.',
            'L\'articolo è già in questa lingua.' => 'The article is already in this language.',
            'Layer dati Nexus_DB non disponibile: traduzione impossibile.' => 'Nexus_DB database layer unavailable: translation impossible.',
            'Lingua richiesta mancante o non valida.' => 'Missing or invalid requested language.',
            'pixabay: nessuna keyword utilizzabile (titolo e categoria vuoti o composti solo da stopwords)' => 'pixabay: no usable keyword (title and category empty or made of stopwords only)',
            'pixabay: risposta API non valida' => 'pixabay: invalid API response',
            'Pubblicazione del post tradotto non riuscita.' => 'Publishing the translated post failed.',
            'Pubblicazione del post tradotto non riuscita: ambiente WordPress incompleto.' => 'Publishing the translated post failed: incomplete WordPress environment.',
            'risposta del servizio immagini non valida' => 'Invalid image service response',
            'Salvataggio della traduzione non riuscito.' => 'Saving the translation failed.',
            'Servizio multilingua non disponibile al momento.' => 'Multilingual service currently unavailable.',
            'Servizio traduzioni non disponibile al momento.' => 'Translation service currently unavailable.',
            'Traduzione già in corso per questo articolo e questa lingua: riprova più tardi.' => 'Translation already running for this article and language: try again later.',
            'Traduzione non riuscita.' => 'Translation failed.',
            'Traduzione non riuscita: titolo tradotto mancante.' => 'Translation failed: translated title missing.',
            'Traduzione saltata: budget AI esaurito.' => 'Translation skipped: AI budget exhausted.',
            'Traduzioni disattivate.' => 'Translations disabled.',
            'URL immagine vuoto o non valido' => 'Image URL empty or invalid',
            'URL non consentito: host mancante.' => 'URL not allowed: missing host.',
            'URL non consentito: impossibile risolvere il nome host.' => 'URL not allowed: unable to resolve the host name.',
            'URL non consentito: l\'host risolve a un indirizzo privato o riservato.' => 'URL not allowed: the host resolves to a private or reserved address.',
            'URL non consentito: sono ammessi solo URL http/https con host valido.' => 'URL not allowed: only http/https URLs with a valid host are accepted.',
            /* v1.1.11 (R4-6) — ai-engine / providers / sources: errori fissi del
             * motore AI (Nexus_AI_Engine), risoluzione provider (Nexus_Providers:
             * custom e login web OAuth) e log fonti (Nexus_Sources). Messaggi
             * compositi con variabili: vedi i pattern in coda qui sotto. */
            'Attendere qualche secondo prima di riavviare il login web (limite di frequenza).' => 'Wait a few seconds before restarting the web login (rate limit).',
            'Base URL del provider AI non valida.' => 'Invalid AI provider base URL.',
            'Base URL del provider AI non valida: sono ammessi solo http e https.' => 'Invalid AI provider base URL: only http and https are allowed.',
            'Chiave API AI non configurata: imposta la chiave API (ai_api_key) nelle impostazioni del plugin Nexus News AI.' => 'AI API key not configured: set the API key (ai_api_key) in the Nexus News AI plugin settings.',
            'Codice OAuth mancante o non valido: riavvia il login da Impostazioni AI.' => 'Missing or invalid OAuth code: restart the login from AI Settings.',
            'Dati del provider personalizzato mancanti o non validi.' => 'Custom provider info missing or invalid.',
            'Endpoint AI su IP privati/localhost non consentito: attiva l’opzione “Consenti endpoint AI su IP privati/localhost” nelle impostazioni se l’endpoint è il tuo server locale (LM Studio, vLLM, Ollama su LAN).' => 'AI endpoints on private IPs/localhost are not allowed: enable the “Allow AI endpoints on private IPs/localhost” option in the settings if the endpoint is your local server (LM Studio, vLLM, Ollama on LAN).',
            'Etichetta del provider mancante: dai un nome riconoscibile (es. “Together”).' => 'Missing provider label: give it a recognizable name (e.g. “Together”).',
            'Full-content: Nexus_Readability non disponibile: le fonti full=1 sono servite con l\'estratto RSS.' => 'Full-content: Nexus_Readability unavailable: full=1 sources are served with the RSS excerpt.',
            'Generazione dell’identificativo non riuscita: riprova.' => 'Could not generate the identifier: try again.',
            'Il layer dati Nexus_DB non è disponibile: chiave non salvata.' => 'The Nexus_DB database layer is unavailable: key not saved.',
            'Il layer dati Nexus_DB non è disponibile: il plugin Nexus News AI non è caricato correttamente.' => 'The Nexus_DB database layer is unavailable: the Nexus News AI plugin is not loaded correctly.',
            'Il login web OAuth non è disponibile per questo provider: inserisci la chiave API manualmente.' => 'OAuth web login is not available for this provider: enter the API key manually.',
            'Impossibile preparare la richiesta per il modello AI (encoding JSON fallito).' => 'Unable to prepare the AI model request (JSON encoding failed).',
            'La risposta del servizio AI non è un JSON valido.' => 'The AI service response is not valid JSON.',
            'Limite di richieste verso il servizio AI raggiunto (HTTP 429). Riprova più tardi.' => 'AI service request limit reached (HTTP 429). Try again later.',
            'MCP: Nexus_Tools::mcp_call non disponibile: fonte MCP ignorata.' => 'MCP: Nexus_Tools::mcp_call unavailable: MCP source ignored.',
            'Nessun messaggio valido da inviare al modello AI.' => 'No valid message to send to the AI model.',
            'Provider AI non disponibile per l\'agente.' => 'AI provider unavailable for the agent.',
            'Provider OAuth non più valido: riavvia il login da Impostazioni AI.' => 'OAuth provider no longer valid: restart the login from AI Settings.',
            'Provider personalizzato non trovato: forse è stato eliminato. Ricarica la pagina e riprova.' => 'Custom provider not found: it may have been deleted. Reload the page and try again.',
            'Risposta inattesa da OpenRouter (chiave di provisioning mancante o dal formato inatteso). Riavvia il login da Impostazioni AI.' => 'Unexpected response from OpenRouter (provisioning key missing or in an unexpected format). Restart the login from AI Settings.',
            'Salvataggio del provider personalizzato non riuscito: verifica i permessi del database.' => 'Custom provider could not be saved: check the database permissions.',
            'Search: chiave API non impostata (setting tools_search_key): fonte ignorata.' => 'Search: API key not set (setting tools_search_key): source ignored.',
            'Sessione OAuth legata a un altro utente: riavvia il login dalla tua sessione.' => 'OAuth session belongs to another user: restart the login from your own session.',
            'Sessione OAuth scaduta o già usata (validità 10 minuti): riavvia il login da Impostazioni AI.' => 'OAuth session expired or already used (10-minute validity): restart the login from AI Settings.',
            'Stato OAuth mancante o malformato: riavvia il login da Impostazioni AI.' => 'Missing or malformed OAuth state: restart the login from AI Settings.',
            'URL base mancante o non valido: inserisci l’endpoint compatibile OpenAI (es. https://api.together.xyz/v1).' => 'Missing or invalid base URL: enter the OpenAI-compatible endpoint (e.g. https://api.together.xyz/v1).',
            'URL del provider AI non valida.' => 'Invalid AI provider URL.',
            'URL del provider AI non valida: sono ammessi solo http e https.' => 'Invalid AI provider URL: only http and https are allowed.',
            'YouTube: API key non impostata (setting youtube_api_key): fonte ignorata.' => 'YouTube: API key not set (setting youtube_api_key): source ignored.',
            'response_format json_object non supportato dal provider: risposta ottenuta senza json mode.' => 'response_format json_object is not supported by the provider: response obtained without JSON mode.',

            /* v1.1.11 (R4-7) — viste admin (redazioni/agents/settings/tools/jobs/wizard) */
            ' Testo completo' => ' Full text',
            '•••• (salvata, hashing) — rigenera per ottenerne una nuova' => '•••• (saved, hashed) — regenerate to get a new one',
            '✗ Inserisci prima il valore della fonte.' => '✗ Enter the source value first.',
            '… provo la fonte' => '… testing the source',
            '@canale o URL del canale' => '@channel or channel URL',
            '[Nexus News AI] Dati admin non disponibili: diagnostica AI non inizializzata.' => '[Nexus News AI] Admin info unavailable: AI diagnostics not initialized.',
            '[Nexus News AI] Dati admin non disponibili: vista Strumenti non inizializzata.' => '[Nexus News AI] Admin info unavailable: Tools view not initialized.',
            '[Nexus News AI] NexusAdminData non disponibile: vista Coda Lavori non inizializzata.' => '[Nexus News AI] NexusAdminData unavailable: Jobs queue view not initialized.',
            'Access token OAuth 1.0a dell’account che pubblica (mascherato).' => 'OAuth 1.0a access token of the publishing account (masked).',
            'Access token secret OAuth 1.0a dell’account (mascherato).' => 'OAuth 1.0a access token secret of the account (masked).',
            'API key dell’app dal Developer Portal di X (mascherata).' => 'App API key from the X Developer Portal (masked).',
            'API secret dell’app, consumer secret (mascherato).' => 'App API secret, consumer secret (masked).',
            'Assente' => 'Missing',
            'Categoria dell’agente' => 'Agent category',
            'Creo gli agenti…' => 'Creating the agents…',
            'Errore sconosciuto.' => 'Unknown error.',
            'Estrae l’articolo completo con Readability (solo rss, site e web).' => 'Extracts the full article with Readability (rss, site and web only).',
            'Forza l\'aggiornamento della lista dal provider (invalida la cache di 12 ore). I badge del picker mostrano gratuità, contesto e prezzo per 1M token quando il provider li espone.' => 'Forces a list refresh from the provider (invalidates the 12-hour cache). The picker badges show free tier, context and price per 1M tokens when the provider exposes them.',
            'Gli agenti raccolgono notizie reali, le valutano e le riscrivono con AI. Crea quanti agenti vuoi: da 1 a una redazione completa, con preset pronti.' => 'Agents collect real news, evaluate it and rewrite it with AI. Create as many agents as you want: from 1 to a full desk, with ready-made presets.',
            'Identificativo del modello (es. openai/gpt-4o-mini). Al focus si apre il picker cercabile: digita per filtrare i modelli del provider, clic o Invio per scegliere, Esc per chiudere.' => 'Model identifier (e.g. openai/gpt-4o-mini). A searchable picker opens on focus: type to filter the provider’s models, click or Enter to select, Esc to close.',
            'La lista dei provider arriva dalle impostazioni; senza chiave in Impostazioni → Provider AI il provider non è utilizzabile.' => 'The provider list comes from the settings; without a key in Settings → Provider AI the provider cannot be used.',
            'nome|https://server-url|token-opzionale' => 'name|https://server-url|optional-token',
            'Nessun agente: usa il wizard o i preset per creare il tuo primo giornalista.' => 'No agents yet: use the wizard or the presets to create your first journalist.',
            'Nuova chiave API generata: copiala subito, non verrà più mostrata' => 'New API key generated: copy it now, it will not be shown again',
            'Pagina web' => 'Web page',
            'Parola chiave da cercare' => 'Keyword to search for',
            'Presente — ' => 'Present — ',
            'Provider AI (vuoto = globale)' => 'AI provider (empty = global)',
            'Redazione salvata ma fonti non salvate' => 'Desk saved but sources not saved',
            'Redazione specifica' => 'Specific desk',
            'RSS (feed diretto)' => 'RSS (direct feed)',
            'Scegli il pacchetto di agenti' => 'Choose the agent package',
            'su modifica, vuota = nessun cambio' => 'on change, empty = no change',
            'Sì' => 'Yes',
            'test non riuscito' => 'test failed',
            'Token del bot Telegram da @BotFather (mascherato: il valore salvato non è visibile a schermo).' => 'Telegram bot token from @BotFather (masked: the saved value is not shown on screen).',
            'URL del feed (https://…)' => 'Feed URL (https://…)',
            'URL del sito (https://…)' => 'Site URL (https://…)',
            'URL del webhook del canale Discord (discord.com/api/webhooks/…, mascherato).' => 'Discord channel webhook URL (discord.com/api/webhooks/…, masked).',
            'URL della pagina (https://…)' => 'Page URL (https://…)',
            'URL o valore della fonte' => 'Source URL or value',
            'Visibile solo per il ruolo Capo redattore: dice al capo quali articoli verifica (intero giornale, solo la sua categoria o una redazione specifica).' => 'Visible only for the Editor-in-chief role: tells the chief which articles to review (whole newspaper, own category only, or a specific desk).',
            'vuoto = modello principale' => 'empty = main model',
            /* v1.1.11 (R4-8) — queue/publisher/readability/cron/social/native/newsletter:
             * voci fisse residue delle classi varie (class-nexus-queue, publisher,
             * readability, cron, social discord/linkedin/telegram/x, redazioni,
             * native). Messaggi compositi con variabili: vedi i pattern in coda
             * a nexus_i18n_extra_patterns(). */
            'Contenuto vuoto: niente da validare.' => 'Empty content: nothing to validate.',
            'Creazione redazione non riuscita.' => 'Desk creation failed.',
            'Discord non configurato' => 'Discord not configured',
            'Fonti della redazione (feed RSS o siti da cui scoprire i feed).' => 'Desk sources (RSS feeds or sites to discover feeds from).',
            'Il titolo non contiene parole significative (solo stopwords o parole di ≤ 3 caratteri): validazione titolo→contenuto impossibile.' => 'The title contains no significant words (only stopwords or words of ≤ 3 characters): title→content validation impossible.',
            'Impossibile preparare il messaggio Telegram (encoding JSON fallito).' => 'Unable to prepare the Telegram message (JSON encoding failed).',
            'Impossibile preparare il payload JSON per Discord.' => 'Unable to prepare the JSON payload for Discord.',
            'Impossibile preparare il payload JSON per LinkedIn (encoding fallito).' => 'Unable to prepare the JSON payload for LinkedIn (encoding failed).',
            'Impostazioni raccolta modificate: sincronizzo la schedulazione.' => 'Collection settings changed: syncing the schedule.',
            'Inviato al canale Discord' => 'Sent to the Discord channel',
            'json_encode del testo del tweet fallito.' => 'json_encode of the tweet text failed.',
            'LinkedIn non configurato' => 'LinkedIn not configured',
            'Nessuna parola significativa del titolo compare nel contenuto: probabile paywall, pagina vuota o contenuto non pertinente.' => 'No significant title word appears in the content: probable paywall, empty page or irrelevant content.',
            'Nexus: runner coda lavori (ogni 60 secondi)' => 'Nexus: jobs queue runner (every 60 seconds)',
            'Nexus_DB non disponibile.' => 'Nexus_DB not available.',
            'Nexus_Pipeline non ancora caricata: tick ignorato.' => 'Nexus_Pipeline not loaded yet: tick ignored.',
            'Nexus_Translate::translate_to_post non disponibile: traduzione automatica saltata.' => 'Nexus_Translate::translate_to_post unavailable: automatic translation skipped.',
            'Nome redazione mancante o tassonomia non attiva.' => 'Missing desk name or taxonomy not active.',
            'Pubblicazione Discord non riuscita.' => 'Discord publishing failed.',
            'Readability: HTML in ingresso vuoto.' => 'Readability: empty input HTML.',
            'Readability: il parser DOM non è riuscito a leggere l\'HTML (documento indigeribile).' => 'Readability: the DOM parser failed to read the HTML (indigestible document).',
            'Readability: la risposta non è HTML testuale (binario o SVG): parse bloccato dalla guard.' => 'Readability: the response is not textual HTML (binary or SVG): parsing blocked by the guard.',
            'Readability: nessun contenuto leggibile estratto dalla pagina.' => 'Readability: no readable content extracted from the page.',
            'Redazione madre non trovata.' => 'Parent desk not found.',
            'Runner coda lavori rimosso (coda disattivata).' => 'Jobs queue runner removed (queue disabled).',
            'Runner coda lavori schedulato (ogni 60 secondi).' => 'Jobs queue runner scheduled (every 60 seconds).',
            'Telegram non configurato' => 'Telegram not configured',
            'Testo del tweet vuoto: nessun titolo o summary valido.' => 'Tweet text is empty: no valid title or summary.',
            'Tick già presente in coda: dispatch saltato (unique).' => 'Tick already in queue: dispatch skipped (unique).',
            'Titolo e summary dell\'articolo sono vuoti: nessun messaggio da inviare.' => 'Article title and summary are empty: no message to send.',
            'Titolo vuoto: impossibile validare la corrispondenza titolo→contenuto.' => 'Empty title: cannot validate the title→content match.',
            'Token LinkedIn scaduto o non valido (HTTP 401)' => 'LinkedIn token expired or invalid (HTTP 401)',
            'Traduzione automatica fallita senza dettagli.' => 'Automatic translation failed without details.',
            'URN LinkedIn non valido' => 'Invalid LinkedIn URN',
            'Webhook Discord non valido' => 'Invalid Discord webhook',
            'X non configurato' => 'X not configured',

        /* v1.1.11 (R4-9) — chiusura residui: voci esatte per frammenti standalone,
         * formati sprintf grezzi e messaggi con placeholder (%d/%s/%1$d) che i
         * pattern con (\d+) non catturano nel censimento. */
            ' agente creato.' => ' agent created.',
            ' agenti creati.' => ' agents created.',
            ' vuoto.' => ' empty.',
            'AI: traduzione incompleta, segmento ' => 'AI: incomplete translation, segment ',
            'Articolo #%d "%s" %s dal revisore.' => 'Article #%d "%s" %s by the reviewer.',
            'Articolo #%d lasciato in stato bozza (auto_rewrite disabilitato).' => 'Article #%d left in draft status (auto_rewrite disabled).',
            'Avvio completato con errori: %1$d di %2$d agenti hanno riportato problemi.' => 'Start completed with errors: %1$d of %2$d agents reported problems.',
            'Cancel job #%d: job già in esecuzione o stato cambiato, operazione annullata.' => 'Cancel job #%d: job already running or status changed, operation cancelled.',
            'Capo redattore: %s (articolo #%d)' => 'Editor-in-chief: %s (article #%d)',
            'Capo redattore: articolo #%d approvato%s.' => 'Editor-in-chief: article #%d approved%s.',
            'Capo redattore: articolo #%d in stato approved (modulo di pubblicazione non disponibile).' => 'Editor-in-chief: article #%d in approved status (publish module unavailable).',
            'Capo redattore: articolo #%d visto%s. In attesa di approvazione umana.' => 'Editor-in-chief: article #%d reviewed%s. Awaiting human approval.',
            'Digest inviato a %d destinatari' => 'Digest sent to %d recipients',
            'Errore di connessione: ' => 'Connection error: ',
            'Errore imprevisto: ' => 'Unexpected error: ',
            'Inviato in chat ' => 'Sent to chat ',
            'L\'articolo #%d non è pubblicabile nello stato attuale (%s): serve lo stato "approved".' => 'Article #%d cannot be published in its current status (%s): the "approved" status is required.',
            'Lettura pagina: ' => 'Page read: ',
            'Reset dead job #%d: stato cambiato durante l\'operazione, reset annullato.' => 'Reset dead job #%d: status changed during the operation, reset cancelled.',
            'Retry job #%d: stato cambiato durante l\'operazione, retry annullato.' => 'Retry job #%d: status changed during the operation, retry cancelled.',
            ' modelli: affina la ricerca.</span>' => ' models: refine your search.</span>',
            'il server MCP ha risposto con un errore JSON-RPC' => 'the MCP server returned a JSON-RPC error',
            'requeue_stale: impossibile marcare dead il job #' => 'requeue_stale: could not mark job #',
            'requeue_stale: impossibile rimettere in coda il job #' => 'requeue_stale: could not re-enqueue job #',

        /* v1.1.12 (S8) — viste settings-* (contenuti/ai/pubblicazione/sistema):
         * sezioni impostazioni dei moduli v1.1.12 (feedback Google GSC/GA4,
         * evergreen, dedup semantico, learning, Google News sitemap, notifiche
         * webhook, link interni). Voci APPENDITE in coda (fuori dall'ordine
         * alfabetico, stile R4) per minimizzare i conflitti di merge; nessun
         * pattern: tutte le stringhe nuove sono statiche (nessun placeholder). */
            'Articoli evergreen' => 'Evergreen articles',
            'Articoli per ciclo' => 'Articles per cycle',
            'Attiva i link interni automatici' => 'Enable automatic internal links',
            'Attiva la sitemap Google News' => 'Enable the Google News sitemap',
            'Aggiorna periodicamente gli articoli evergreen' => 'Refresh evergreen articles periodically',
            'Briefing performance' => 'Performance briefing',
            'Briefing performance nel prompt dei capi' => 'Performance briefing in the chiefs prompt',
            'Campo mascherato: compila il campo solo per sostituire il client secret salvato (vuoto = nessun cambio).' => 'Masked field: fill it in only to replace the saved client secret (empty = no change).',
            'Campo mascherato: compila il campo solo per sostituire il webhook salvato (vuoto = nessun cambio).' => 'Masked field: fill it in only to replace the saved webhook (empty = no change).',
            'Client ID OAuth' => 'OAuth client ID',
            'Client ID del client OAuth 2.0 "App web" creato in Google Cloud Console.' => 'Client ID of the OAuth 2.0 "Web application" client created in Google Cloud Console.',
            'Client secret OAuth' => 'OAuth client secret',
            'Connesso' => 'Connected',
            'Connessione a Google completata.' => 'Google connection completed.',
            'Connessione a Google non riuscita: riprova dal pulsante Connetti Google.' => 'Google connection failed: try again from the Connect Google button.',
            'Connetti Google' => 'Connect Google',
            'Contenuti simili' => 'Similar content',
            'Dedup semantico' => 'Semantic dedup',
            'Distanza Hamming massima tra due simhash (0-24); 6 = conservativo, valori più alti tollerano articoli più simili.' => 'Maximum Hamming distance between two simhashes (0-24); 6 = conservative, higher values tolerate more similar articles.',
            'Disconnetti' => 'Disconnect',
            'Elenco separato da virgola degli eventi da notificare. Valori ammessi: error, pending, published.' => 'Comma-separated list of events to notify. Allowed values: error, pending, published.',
            'Eventi notificati' => 'Notified events',
            'Evergreen (pillar)' => 'Evergreen (pillar)',
            'Feedback performance (Google)' => 'Performance feedback (Google)',
            'Finestra di apprendimento (giorni)' => 'Learning window (days)',
            'Frequenza di aggiornamento (giorni)' => 'Refresh frequency (days)',
            'Giorni di metriche aggregati nel briefing (7-90).' => 'Days of metrics aggregated in the briefing (7-90).',
            'Giorni minimi tra due aggiornamenti dello stesso articolo evergreen (minimo 7).' => 'Minimum days between two refreshes of the same evergreen article (at least 7).',
            'Google disconnesso.' => 'Google disconnected.',
            'Google News' => 'Google News',
            'Google non è ancora connesso: collega Search Console o Analytics 4 nelle Impostazioni per vedere l\'insight performance.' => 'Google is not connected yet: connect Search Console or Analytics 4 in the Settings to see the performance insight.',
            'Learning (redazione che impara)' => 'Learning (newsroom that learns)',
            'Link interni' => 'Internal links',
            'Link interni automatici' => 'Automatic internal links',
            'Link interni per articolo' => 'Internal links per article',
            'Nome della testata' => 'Publication name',
            'Nome usato nel tag <news:name> della sitemap Google News; vuoto = nome del sito.' => 'Name used in the <news:name> tag of the Google News sitemap; empty = site name.',
            'Nota: se Telegram è configurato nella sezione Distribuzione social, le notifiche arrivano anche in chat.' => 'Note: if Telegram is configured in the Social distribution section, notifications arrive in chat as well.',
            'Notifiche' => 'Notifications',
            'Numero massimo di link a post correlati aggiunti in coda all\'articolo (1-10).' => 'Maximum number of related-post links appended to the article (1-10).',
            'Property ID GA4' => 'GA4 property ID',
            'Property Search Console' => 'Search Console property',
            'Quanti articoli evergreen al massimo vengono aggiornati a ogni ciclo (1-10).' => 'How many evergreen articles at most are refreshed per cycle (1-10).',
            'Redirect URI da registrare in Google Cloud Console (client OAuth "App web"):' => 'Redirect URI to register in Google Cloud Console (OAuth client "Web application"):',
            'Scarta candidati con contenuto simile' => 'Discard candidates with similar content',
            'Se attivo, gli articoli pillar vengono ricandidati a aggiornamento (dati, link e riferimenti freschi) invece di invecchiare nell\'archivio.' => 'If enabled, pillar articles become refresh candidates again (fresh data, links and references) instead of aging in the archive.',
            'Se attivo, il briefing delle performance degli articoli pubblicati entra nel prompt dei capi redattori: la redazione impara da ciò che funziona.' => 'If enabled, the performance briefing of published articles is included in the editors-in-chief prompt: the newsroom learns from what works.',
            'Se attivo, in coda all\'articolo vengono aggiunti link ai post correlati dello stesso sito.' => 'If enabled, links to related posts of the same site are appended to the article.',
            'Se attivo, viene generata la sitemap nexus-news-sitemap.xml con gli articoli pubblicati nelle ultime 48 ore.' => 'If enabled, the nexus-news-sitemap.xml sitemap is generated with the articles published in the last 48 hours.',
            'Simhash 64-bit: i candidati raccolti con contenuto simile ad articoli già presenti vengono scartati prima della valutazione.' => '64-bit simhash: collected candidates whose content is similar to existing articles are discarded before evaluation.',
            'Sincronizzazione giornaliera via WP-Cron: le metriche degli articoli finiscono nella tabella nexus_metrics.' => 'Daily sync via WP-Cron: article metrics land in the nexus_metrics table.',
            'Sincronizzazione metriche' => 'Metrics sync',
            'Sincronizza GSC e GA4 una volta al giorno' => 'Sync GSC and GA4 once a day',
            'Solo cifre (es. 123456789), come mostrato in Admin GA4 → Proprietà.' => 'Digits only (e.g. 123456789), as shown in GA4 Admin → Property.',
            'Soglia di somiglianza (distanza Hamming)' => 'Similarity threshold (Hamming distance)',
            'Sitemap Google News' => 'Google News sitemap',
            'Stato connessione Google' => 'Google connection status',
            'Sì, annulla' => 'Yes, cancel',
            'Sì, avvia' => 'Yes, start',
            'Sì, genera' => 'Yes, generate',
            'Sì, verifica' => 'Yes, re-check',
            'Sync ancora non arrivato: i dati compaiono dopo il primo sync giornaliero.' => 'Sync has not run yet: data appears after the first daily sync.',
            'URL esatta della property GSC (es. https://esempio.com/): deve coincidere con quella registrata in Search Console.' => 'Exact GSC property URL (e.g. https://example.com/): it must match the one registered in Search Console.',
            'Webhook notifiche' => 'Notification webhook',
            'Webhook Slack-compatibile che riceve gli eventi della pipeline (errori, pending, pubblicazioni).' => 'Slack-compatible webhook that receives pipeline events (errors, pending, publishing).',
            '•••• salvata — compila per sostituire' => '•••• saved — fill in to replace',

            /* ---- v1.1.16 (WE1/WE2/WE3/WE4/WE5) — drawer agente "Cosa sta facendo",
             * fasi Wire reali, dead letter, wizard onesto ("Dove finiscono gli
             * articoli" + Prossimi passi) e retry degli errori. Chiavi ESATTE:
             * le frasi con numeri/passi variabili sono nei pattern IN CIMA a
             * nexus_i18n_extra_patterns() (regola CIMA: i compositi specifici
             * corrono prima dei frammenti generici). I codici verdict (PC1)
             * sono qui sotto come testi base: le varianti con {param} interpolato
             * passano dai pattern. ---- */
            'Agente non presente nello stato live (eliminato o runtime non ancora avviato).' => 'Agent not present in the live status (deleted or runtime not started yet).',
            'Al lavoro' => 'Working',
            'Apri la pagina Agenti' => 'Open the Agents page',
            'Auto-pubblicazione disattivata: gli articoli restano in coda finché non li approvi tu.' => 'Auto-publish disabled: articles stay in the queue until you approve them.',
            'Avvio della pipeline…' => 'Starting the pipeline…',
            'Bozze WordPress' => 'WordPress drafts',
            'Budget AI mensile esaurito: aumenta il limite in Impostazioni → Redazione AI o attendi il mese prossimo.' => 'Monthly AI budget exhausted: raise the limit in Settings → AI Newsroom or wait for next month.',
            'Ci sono fonti di ricerca ma manca la chiave search: per ora vengono ignorate.' => 'There are search sources but the search key is missing: they are ignored for now.',
            'Comparirà appena l\'agente lavora.' => 'It will appear as soon as the agent works.',
            'Cosa sta facendo' => 'What it is doing',
            'Dead letter' => 'Dead letter',
            'Destinazione articoli non salvata: ' => 'Article destination not saved: ',
            'Destinazione articoli salvata nelle impostazioni.' => 'Article destination saved in the settings.',
            'Diagnostica non disponibile' => 'Diagnostics not available',
            'Dove finiscono gli articoli' => 'Where the articles end up',
            'Endpoint diagnostica non presente in questa versione del plugin.' => 'Diagnostics endpoint not present in this plugin version.',
            'Gli articoli approvati diventano Bozze in WordPress: le trovi in Articoli → Bozze, pronte per la tua revisione finale.' => 'Approved articles become WordPress drafts: find them in Posts → Drafts, ready for your final review.',
            'Gli articoli approvati finiranno come Bozze in WordPress: le trovi in Articoli → Bozze.' => 'Approved articles will become WordPress drafts: find them in Posts → Drafts.',
            'Gli articoli approvati verranno pubblicati direttamente su WordPress.' => 'Approved articles will be published directly to WordPress.',
            'Gli articoli approvati vengono pubblicati subito sul sito: li vedi in Articoli → Tutti e nel magazine pubblico.' => 'Approved articles are published right away: you see them in Posts → All and on the public magazine.',
            'Gli articoli finiranno nella Coda Approvazioni di Nexus: li approvi tu, uno a uno.' => 'Articles will land in the Nexus Approval Queue: you approve them one by one.',
            'Gli articoli restano in Nexus: li vedi nella Coda Approvazioni e decidi tu cosa pubblicare. Su WordPress non arriva nulla finché non approvi.' => 'Articles stay in Nexus: you see them in the Approval Queue and decide what gets published. Nothing reaches WordPress until you approve.',
            'Impossibile caricare le statistiche' => 'Unable to load the statistics',
            'I tuoi agenti partiranno da soli entro il prossimo giro di raccolta.' => 'Your agents will start on their own within the next collection cycle.',
            'Modalità semi-autonoma: gli articoli aspettano la tua approvazione nella Coda Approvazioni.' => 'Semi-autonomous mode: articles wait for your approval in the Approval Queue.',
            'Nessuna attività per questo agente' => 'No activity for this agent',
            'Nessuna fonte configurata: l\'agente genera contenuti evergreen, non notizie vere.' => 'No sources configured: the agent produces evergreen content, not real news.',
            'Nessun run registrato: premi ▶ Avvia sulla card per il primo giro.' => 'No runs recorded: press ▶ Run on the card for the first cycle.',
            'Nessuna verifica disponibile.' => 'No checks available.',
            'Nota: la redazione è semi-autonoma ma le impostazioni attuali pubblicano direttamente su WordPress. Con "Solo Nexus" gli articoli restano comunque nella Coda Approvazioni di Nexus finché non li approvi tu.' => 'Note: the newsroom is semi-autonomous but the current settings publish directly to WordPress. With "Nexus only" articles still wait in the Nexus Approval Queue until you approve them.',
            'Parziale' => 'Partial',
            'Pubblica direttamente' => 'Publish directly',
            'Pubblicazione diretta attiva: gli articoli approvati escono come post pubblicati su WordPress.' => 'Direct publishing active: approved articles go out as published posts on WordPress.',
            'Prossimi passi' => 'Next steps',
            'Salvo la destinazione degli articoli…' => 'Saving the article destination…',
            'Se non la spunti, nessuna fonte viene salvata: puoi aggiungere le tue fonti dopo, dalla redazione di ogni agente o da Impostazioni. Se la spunti aggiunge queste 5 fonti d’esempio: ANSA (generale), ANSA (politica), Il Post, Il Sole 24 Ore (economia) e Wired.it.' => 'If you leave it unchecked, no source is saved: you can add your own sources later, from each agent’s newsroom or from Settings. If you check it, these 5 sample sources are added: ANSA (general), ANSA (politics), Il Post, Il Sole 24 Ore (economy) and Wired.it.',
            'Scegli dove vedi il risultato del lavoro degli agenti: puoi cambiarlo in ogni momento da Impostazioni → Contenuti e Pubblicazione.' => 'Choose where you see the agents’ work: you can change it at any time in Settings → Content and Publishing.',
            'Solo Nexus (approvazione manuale)' => 'Nexus only (manual approval)',
            'Stato live non disponibile' => 'Live status not available',
            'Stato live non disponibile in questa versione del plugin.' => 'Live status not available in this plugin version.',
            'Tutto ok: la redazione è operativa e pronta a produrre.' => 'All good: the newsroom is up and ready to produce.',
            'Ultime run' => 'Latest runs',
            'Ultimi errori' => 'Latest errors',
            'Attività recenti' => 'Recent activity',
            'Verifica non disponibile' => 'Check not available',
            'Verifica: perché non vedi articoli?' => 'Check: why aren’t you seeing articles?',
            'Vuoi un run subito? Apri la pagina Agenti e premi ▶ Avvia su un agente (o "Dettagli" per vedere cosa sta facendo).' => 'Want a run right away? Open the Agents page and press ▶ Run on an agent (or "Details" to see what it is doing).',
            'WP-Cron non ha mai eseguito la redazione: il primo giro partirà appena il sito riceve traffico.' => 'WP-Cron has never run the newsroom: the first cycle starts as soon as the site gets traffic.',
            /* ---- v1.1.18 «Recupero e Segreti» — messaggi dei fix audit (FIX-1..FIX-10) ---- */
            'AI: risposta troncata (max_tokens esauriti): la traduzione è incompleta e NON viene salvata. Riprova o scegli un modello con più token di output.' => 'AI: response truncated (max_tokens exhausted): the translation is incomplete and is NOT saved. Try again or choose a model with a larger output budget.',
            'Cifratura token fallita: salvataggio RIFIUTATO (nessun token in chiaro, v1.1.18 AL-15)' => 'Token encryption failed: save REFUSED (no plaintext token, v1.1.18 AL-15)',
            'Estensione openssl assente: salvataggio del token RIFIUTATO (nessun token in chiaro, v1.1.18 AL-15)' => 'openssl extension missing: token save REFUSED (no plaintext token, v1.1.18 AL-15)',
            'Estensione PHP openssl assente: openssl è richiesto per salvare le credenziali Google in sicurezza. Il collegamento a Search Console/GA4 resta bloccato (nessun token viene salvato in chiaro) e un eventuale token salvato da versioni precedenti è conservato nel database senza cifratura. Attiva l\'estensione openssl, poi ricollega Google dalle Impostazioni: il token verrà cifrato automaticamente (AES-256-GCM).' => 'PHP openssl extension missing: openssl is required to store Google credentials securely. The Search Console/GA4 connection stays blocked (no token is saved in plaintext) and any token saved by previous versions is kept in the database unencrypted. Enable the openssl extension, then reconnect Google from Settings: the token will be encrypted automatically (AES-256-GCM).',
            'Generazione IV fallita: salvataggio del token RIFIUTATO (nessun token in chiaro, v1.1.18 AL-15)' => 'IV generation failed: token save REFUSED (no plaintext token, v1.1.18 AL-15)',
            'La direzione ha prodotto una risposta troncata per max_tokens esauriti: articolo incompleto NON salvato. Aumenta max_tokens o scegli un modello con più contesto.' => 'The chief editor produced a response truncated because max_tokens ran out: the incomplete article has NOT been saved. Increase max_tokens or choose a model with more context.',
            'message.content è arrivato come array senza parti testuali riconoscibili.' => 'message.content arrived as an array with no recognizable text parts.',
            'Nexus Wire già in corso su questo tema: attendere il completamento del giro attuale.' => 'Nexus Wire already running on this topic: wait for the current round to complete.',
            'openssl richiesto per salvare le credenziali Google in sicurezza: attiva l\'estensione PHP openssl e ricollega Search Console.' => 'openssl required to store Google credentials securely: enable the PHP openssl extension and reconnect Search Console.',
            'openssl richiesto per salvare le credenziali Google in sicurezza: attiva l\'estensione PHP openssl sul server e riprova il collegamento dalle Impostazioni.' => 'openssl required to store Google credentials securely: enable the PHP openssl extension on the server and retry the connection from Settings.',
            'openssl richiesto per salvare le credenziali Google in sicurezza: collegamento bloccato (openssl assente)' => 'openssl required to store Google credentials securely: connection blocked (openssl missing)',
            'openssl richiesto per salvare le credenziali Google in sicurezza: scambio token bloccato (openssl assente)' => 'openssl required to store Google credentials securely: token exchange blocked (openssl missing)',
            'Questa operazione è già in esecuzione: attendi che termini prima di rilanciarla.' => 'This operation is already running: wait for it to finish before running it again.',
            'response_format json_object non supportato dal provider: risposta ottenuta senza json mode.' => 'response_format json_object not supported by the provider: response obtained without json mode.',
            'reaper: run fantasma (timeout)' => 'reaper: ghost run (timeout)',
            'Riscrittura troncata: la risposta AI è stata interrotta per max_tokens esauriti e il contenuto incompleto NON è stato salvato. Aumenta max_tokens o scegli un modello con più contesto.' => 'Rewrite truncated: the AI response was cut off because max_tokens ran out and the incomplete content has NOT been saved. Increase max_tokens or choose a model with more context.',
            'Social saltati: articolo in bozza WP.' => 'Social skipped: the article is a WordPress draft.',
            'Tentativi AI interrotti: superato il budget di tempo per questa chiamata (limite anti-blocco). Riprova più tardi.' => 'AI attempts stopped: the time budget for this call has been exceeded (anti-lock limit). Please try again later.',
            'Test generazione non disponibile da questa versione del server' => 'Generation test not available from this server version',
            'Token Google salvato in chiaro da versioni precedenti ri-cifrato (openssl disponibile)' => 'Google token saved in plaintext by previous versions has been re-encrypted (openssl available)',
            'URL non consentito: la verifica di sicurezza del dominio è fallita subito prima della richiesta.' => 'URL not allowed: the domain safety check failed right before the request.',
        );
}

/**
 * Pattern i18n EXTRA (v1.1.6) — messaggi con parti variabili IT => EN.
 *
 * CONTRATTO v1.1.6 §2 T-01 (punto 2): seconda mappa del motore Nexus_I18n
 * per i messaggi di log dell'activity feed e per le frasi composite di
 * admin.js/viste che il match ESATTO non cattura (es. "Riscrittura
 * articolo #10 fallita: risposta AI non interpretabile.").
 *
 * Formato: lista di coppie array( 're' => regex PCRE-like (senza
 * delimitatori, consumata da new RegExp in i18n.js), 'tpl' => template
 * EN con segnaposto $1/$2 ). Applicati in i18n.js con String.replace
 * SOLO se il match esatto fallisce; l'ORDINE conta: dal messaggio più
 * specifico al frammento più generico (i18n.js ri-applica i pattern a
 * catena, max 4 giri, solo finché il testo cambia — anti-loop).
 *
 * I pattern REALI sono stati estratti con grep dai punti di insert_activity
 * di includes/ (pipeline, rest, chief, queue, publisher, native, cron,
 * translate, newsletter, ping, image) e dai punti di composizione di
 * admin.js (toast riepilogo, live strip, redazioni, migrazione, jobs).
 *
 * Filtro: 'nexus_i18n_patterns' (stessa firma dell'array).
 *
 * @return array<int,array{re:string,tpl:string}>
 */
function nexus_i18n_extra_patterns() {
    return array(
        /* ---- v1.1.16 (WE1/WE3/WE4): compositi drawer agente + wizard + jobs args.
         * IN CIMA (regola CIMA): i compositi specifici con numeri/parametri devono
         * correre PRIMA di qualunque frammento generico pre-esistente (' articoli',
         * 'N articoli', ...). I codici verdict PC1 con {param} interpolato dal JS
         * arrivano qui già "cuciti": i pattern catturano il valore variabile. ---- */
        array(
            're'  => 'Cosa sta facendo: (.+)',
            'tpl' => 'What $1 is doing',
        ),
        array(
            're'  => 'Il cron è in ritardo: il prossimo giro era previsto (.+)\\.',
            'tpl' => 'The cron is late: the next run was expected $1.',
        ),
        array(
            're'  => '(\\d+) fonti stanno segnalando errori: controllale nella redazione dell\'agente\\.',
            'tpl' => '$1 sources are reporting errors: check them in the agent’s newsroom.',
        ),
        array(
            're'  => 'Limite giornaliero raggiunto: (\\d+) articoli creati su (\\d+) consentiti\\.',
            'tpl' => 'Daily limit reached: $1 articles created out of $2 allowed.',
        ),
        array(
            're'  => 'La generazione AI sta fallendo: (.+)',
            'tpl' => 'AI generation is failing: $1',
        ),
        array(
            're'  => 'L\'ultimo run è fallito: (.+)',
            'tpl' => 'The last run failed: $1',
        ),
        array(
            're'  => 'I tuoi agenti partiranno da soli entro il prossimo giro di raccolta \\(circa ogni (\\d+) minuti\\)\\.',
            'tpl' => 'Your agents will start on their own within the next collection cycle (about every $1 minutes).',
        ),
        array(
            're'  => '(\\d+) articoli oggi · (\\d+) run oggi',
            'tpl' => '$1 articles today · $2 runs today',
        ),
        array(
            're'  => '(\\d+) candidati',
            'tpl' => '$1 candidates',
        ),
        array(
            're'  => 'fonti (\\d+)/(\\d+)',
            'tpl' => 'sources $1/$2',
        ),
        array(
            're'  => 'agente #(\\d+)',
            'tpl' => 'agent #$1',
        ),
        array(
            're'  => 'articolo #(\\d+)',
            'tpl' => 'article #$1',
        ),
        /* ---- v1.1.15 (WD10): stringhe log introdotte/alterate da WE5/WE6/WE7
         * (digest, telegram, notify) — prefissi compositi da concatenazione
         * PHP e contatore fallimenti. IN CIMA: copertura deterministica prima
         * di qualunque frammento generico (regola v1.1.6/v1.1.14). ---- */
        array(
            're'  => 'Digest: invio fallito a ',
            'tpl' => 'Digest: delivery failed to ',
        ),
        array(
            're'  => ' — invii falliti: (\\d+)',
            'tpl' => ' — failed deliveries: $1',
        ),
        array(
            're'  => 'Webhook notifica fallito \\(errore di connessione\\): ',
            'tpl' => 'Notification webhook failed (connection error): ',
        ),
        array(
            're'  => 'Notifica Telegram ops fallita \\(errore di connessione\\): ',
            'tpl' => 'Telegram ops notification failed (connection error): ',
        ),
        /* ---- v1.1.15 (WD2): due compositi spostati IN CIMA — i frammenti
         * generici pre-esistenti li spezzavano prima che firassero (regola
         * v1.1.6/v1.1.14: le frasi intere prima dei frammenti). Stringhe
         * re/tpl identiche alle posizioni precedenti, cambiata SOLO la
         * posizione. ---- */
        array(
            're'  => 'Capo redattore: query articoli in attesa fallita \(verifica l\'aggiornamento dello schema\)\.',
            'tpl' => 'Editor-in-chief: query for pending articles failed (check the schema update).',
        ),
        /* ---- v1.1.13 (O6): avviso dedup con finestra troncata (activity log,
         * numeri già sostituiti da sprintf al momento dell'insert). In coda
         * veniva spezzato dal generico "N articoli" => "N articles" (v1.1.6). ---- */
        array(
            're'  => 'Dedup: confronto limitato a (\d+) articoli \(finestra (\d+) gg\) — duplicati recenti oltre il limite non verificati\. Alzalo in Impostazioni → Sistema → Limiti e scala\.',
            'tpl' => 'Dedup: comparison limited to $1 articles (window $2 days) — recent duplicates beyond the limit are not verified. Raise it in Settings → System → Scale limits.',
        ),
        /* ---- v1.1.15 (WD3): messaggi di stato runtime della sezione
         * opportunità (analytics.php, setStatus con concatenazione JS).
         * IN CIMA: copertura deterministica prima di qualunque frammento. ---- */
        array(
            're'  => 'Job #(\\d+) accodato: il Wire partirà al prossimo giro del runner\\.',
            'tpl' => 'Job #$1 queued: the Wire will start on the next runner pass.',
        ),
        array(
            're'  => 'Articolo #(\\d+) in coda di approvazione\\.',
            'tpl' => 'Article #$1 in the approval queue.',
        ),
        /* ---- v1.1.14 (P1-P6): compositi numerici dei nuovi moduli. POSIZIONE
         * IN CIMA: i compositi specifici devono correre PRIMA dei frammenti
         * generici pre-esistenti (' in attesa', 'N articoli', ...) che altrimenti
         * frammentano le frasi (stessa regola della nota v1.1.6). Il generico
         * "N righe" chiude il blocco, dopo i suoi specifici. ---- */
        array(
            're'  => '(\\d+) righe su (\\d+) articoli',
            'tpl' => '$1 rows across $2 articles',
        ),
        array(
            're'  => 'Prossimo giro: (.+)',
            'tpl' => 'Next run: $1',
        ),
        array(
            're'  => '(\\d+) righe scartate \\(orario non valido: inizio prima della fine\\?\\)\\.',
            'tpl' => '$1 discarded rows (invalid time: start before end?).',
        ),
        array(
            're'  => '(\\d+) riga scartata \\(orario non valido: inizio prima della fine\\?\\)\\.',
            'tpl' => '$1 discarded row (invalid time: start before end?).',
        ),
        array(
            're'  => '(\\d+) lavoro "running" da oltre (\\d+) ore\\.',
            'tpl' => '$1 job "running" for more than $2 hours.',
        ),
        array(
            're'  => '(\\d+) lavori "running" da oltre (\\d+) ore\\.',
            'tpl' => '$1 jobs "running" for more than $2 hours.',
        ),
        array(
            're'  => '(\\d+) evento WP-Cron verificato: tutto in orario\\.',
            'tpl' => '$1 WP-Cron event checked: all on time.',
        ),
        array(
            're'  => '(\\d+) eventi WP-Cron verificati: tutto in orario\\.',
            'tpl' => '$1 WP-Cron events checked: all on time.',
        ),
        array(
            're'  => 'Lavoro fallito: (.+) \\((\\d+) volta\\)',
            'tpl' => 'Failed job: $1 ($2 time)',
        ),
        array(
            're'  => 'Lavoro fallito: (.+) \\((\\d+) volte\\)',
            'tpl' => 'Failed job: $1 ($2 times)',
        ),
        array(
            're'  => 'Errore: (.+) \\((\\d+) volta\\)',
            'tpl' => 'Error: $1 ($2 time)',
        ),
        array(
            're'  => 'Errore: (.+) \\((\\d+) volte\\)',
            'tpl' => 'Error: $1 ($2 times)',
        ),
        array(
            're'  => '(\\d+) in attesa di approvazione',
            'tpl' => '$1 awaiting approval',
        ),
        array(
            're'  => '(\\d+) bozza in lavorazione',
            'tpl' => '$1 draft in progress',
        ),
        array(
            're'  => '(\\d+) bozze in lavorazione',
            'tpl' => '$1 drafts in progress',
        ),
        array(
            're'  => '(\\d+) approvato da pubblicare',
            'tpl' => '$1 approved for publishing',
        ),
        array(
            're'  => '(\\d+) approvati da pubblicare',
            'tpl' => '$1 approved for publishing',
        ),
        array(
            're'  => '(\\d+) lavoro in coda',
            'tpl' => '$1 job in queue',
        ),
        array(
            're'  => '(\\d+) lavori in coda',
            'tpl' => '$1 jobs in queue',
        ),
        array(
            're'  => '(\\d+) righe',
            'tpl' => '$1 rows',
        ),
        /* ---- v1.1.6 (T-01b): frasi intere e frammenti compositi, PRIMA dei
         * frammenti generici (l'ordine conta: i pattern sono applicati in
         * sequenza a ogni giro e i frammenti generici come "N parole"
         * diverterebbero le frasi intere se venissero prima). ---- */
        array(
            're'  => 'Articolo #(\d+) riscritto \(([\d.,]+) parole, ([\d.,]+) min di lettura\) e messo in coda di approvazione\.',
            'tpl' => 'Article #$1 rewritten ($2 words, $3 min read) and queued for approval.',
        ),
        array(
            're'  => 'Chiave API AI non configurata: salva prima la chiave nelle Impostazioni\.',
            'tpl' => 'AI API key not configured: save the key in the settings first.',
        ),
        array(
            're'  => 'Salva le impostazioni e riprova\.',
            'tpl' => 'Save the settings and try again.',
        ),
        array(
            're'  => 'Redazione al lavoro',
            'tpl' => 'Newsroom at work',
        ),
        array(
            're'  => 'errore di rete',
            'tpl' => 'network error',
        ),
        array(
            're'  => 'Altri agenti: ',
            'tpl' => 'More agents: ',
        ),
        array(
            're'  => ' in attesa',
            'tpl' => ' pending',
        ),
        array(
            're'  => ' in pausa',
            'tpl' => ' paused',
        ),
        array(
            're'  => ' in errore',
            'tpl' => ' error',
        ),
        array(
            're'  => 'agenti: ',
            'tpl' => 'agents: ',
        ),
        array(
            're'  => 'Batch eseguito: ([\d.,]+) processati, ([\d.,]+) falliti',
            'tpl' => 'Batch run: $1 processed, $2 failed',
        ),
        array(
            're'  => 'Eliminati ([\d.,]+) lavori',
            'tpl' => 'Deleted $1 jobs',
        ),
        array(
            're'  => 'Annullare il lavoro #(\d+)\?',
            'tpl' => 'Cancel job #$1?',
        ),
        array(
            're'  => 'Lavoro #(\d+) rimesso in coda',
            'tpl' => 'Job #$1 requeued',
        ),
        array(
            're'  => 'Lavoro #(\d+) annullato',
            'tpl' => 'Job #$1 cancelled',
        ),
        array(
            're'  => 'Errore HTTP (\d+)',
            'tpl' => 'HTTP error $1',
        ),
        array(
            're'  => 'Errore (\d+)',
            'tpl' => 'Error $1',
        ),
        array(
            're'  => 'Import non riuscito: ',
            'tpl' => 'Import failed: ',
        ),
        array(
            're'  => 'Impostazioni aggiornate: ([\d.,]+) · Agenti aggiornati: ([\d.,]+) · Agenti creati: ([\d.,]+)',
            'tpl' => 'Settings updated: $1 · Agents updated: $2 · Agents created: $3',
        ),
        array(
            're'  => '· Errori: ',
            'tpl' => '· Errors: ',
        ),
        array(
            're'  => '([\d.,]+) gen — ([\d.,]+) creati, ([\d.,]+) pubblicati',
            'tpl' => '$1 jan — $2 created, $3 published',
        ),
        array(
            're'  => '([\d.,]+) feb — ([\d.,]+) creati, ([\d.,]+) pubblicati',
            'tpl' => '$1 feb — $2 created, $3 published',
        ),
        array(
            're'  => '([\d.,]+) mar — ([\d.,]+) creati, ([\d.,]+) pubblicati',
            'tpl' => '$1 mar — $2 created, $3 published',
        ),
        array(
            're'  => '([\d.,]+) apr — ([\d.,]+) creati, ([\d.,]+) pubblicati',
            'tpl' => '$1 apr — $2 created, $3 published',
        ),
        array(
            're'  => '([\d.,]+) mag — ([\d.,]+) creati, ([\d.,]+) pubblicati',
            'tpl' => '$1 may — $2 created, $3 published',
        ),
        array(
            're'  => '([\d.,]+) giu — ([\d.,]+) creati, ([\d.,]+) pubblicati',
            'tpl' => '$1 jun — $2 created, $3 published',
        ),
        array(
            're'  => '([\d.,]+) lug — ([\d.,]+) creati, ([\d.,]+) pubblicati',
            'tpl' => '$1 jul — $2 created, $3 published',
        ),
        array(
            're'  => '([\d.,]+) ago — ([\d.,]+) creati, ([\d.,]+) pubblicati',
            'tpl' => '$1 aug — $2 created, $3 published',
        ),
        array(
            're'  => '([\d.,]+) set — ([\d.,]+) creati, ([\d.,]+) pubblicati',
            'tpl' => '$1 sep — $2 created, $3 published',
        ),
        array(
            're'  => '([\d.,]+) ott — ([\d.,]+) creati, ([\d.,]+) pubblicati',
            'tpl' => '$1 oct — $2 created, $3 published',
        ),
        array(
            're'  => '([\d.,]+) nov — ([\d.,]+) creati, ([\d.,]+) pubblicati',
            'tpl' => '$1 nov — $2 created, $3 published',
        ),
        array(
            're'  => '([\d.,]+) dic — ([\d.,]+) creati, ([\d.,]+) pubblicati',
            'tpl' => '$1 dec — $2 created, $3 published',
        ),
        array(
            're'  => '([\d.,]+) creati, ([\d.,]+) pubblicati',
            'tpl' => '$1 created, $2 published',
        ),
        array(
            're'  => 'Agenti assegnati: ([\d.,]+)',
            'tpl' => 'Assigned agents: $1',
        ),
        array(
            're'  => '([\d.,]+) articoli convertiti',
            'tpl' => '$1 articles converted',
        ),
        array(
            're'  => '([\d.,]+) articoli coperti',
            'tpl' => '$1 articles covered',
        ),
        array(
            're'  => '([\d.,]+) articolo coperto',
            'tpl' => '$1 article covered',
        ),
        // v1.1.7 (T-C2): contatore coda approvazioni (#nxa-queue-count, admin.js
        // loadQueueList: '1 articolo in coda' / total + ' articoli in coda') e
        // messaggi di eliminazione agente (log attività DB + confirm composto).
        // PRIMA dei frammenti generici "N articolo/i": l'ordine conta, il
        // generico trasformerebbe "articoli" lasciando "in coda" in italiano
        // (e il pattern deve poter tradurre anche il frammento puro
        // ' articoli in coda' concatenato da admin.js).
        array(
            're'  => ' articoli in coda',
            'tpl' => ' articles in queue',
        ),
        array(
            're'  => ' articolo in coda',
            'tpl' => ' article in queue',
        ),
        array(
            're'  => 'Agente "(.*?)" eliminato\.',
            'tpl' => 'Agent "$1" deleted.',
        ),
        array(
            're'  => 'Eliminare l’agente "(.*?)"\? Gli articoli restano pubblicati ma senza autore assegnato\.',
            'tpl' => 'Delete agent "$1"? Articles stay published but without an assigned author.',
        ),
        array(
            're'  => '([\d.,]+) articoli\b(?! convertiti| coperti)',
            'tpl' => '$1 articles',
        ),
        array(
            're'  => '([\d.,]+) articolo\b',
            'tpl' => '$1 article',
        ),
        array(
            're'  => '([\d.,]+) agenti al lavoro',
            'tpl' => '$1 agents at work',
        ),
        array(
            're'  => '1 agente al lavoro',
            'tpl' => '1 agent at work',
        ),
        array(
            're'  => '([\d.,]+) agenti assegnati',
            'tpl' => '$1 agents assigned',
        ),
        array(
            're'  => '([\d.,]+) agente assegnato',
            'tpl' => '$1 agent assigned',
        ),
        array(
            're'  => '([\d.,]+) modelli disponibili dal provider',
            'tpl' => '$1 models available from the provider',
        ),
        array(
            're'  => '([\d.,]+) min fa',
            'tpl' => '$1 min ago',
        ),
        array(
            're'  => '([\d.,]+) ore fa',
            'tpl' => '$1 hours ago',
        ),
        array(
            're'  => '([\d.,]+) giorni fa',
            'tpl' => '$1 days ago',
        ),
        array(
            're'  => 'Agente #(\d+)',
            'tpl' => 'Agent #$1',
        ),
        array(
            're'  => 'Redazione #(\d+)',
            'tpl' => 'Desk #$1',
        ),
        array(
            're'  => '([\d.,]+) redazioni\b',
            'tpl' => '$1 desks',
        ),
        array(
            're'  => '([\d.,]+) redazione\b',
            'tpl' => '$1 desk',
        ),
        array(
            're'  => '([\d.,]+) fonti',
            'tpl' => '$1 sources',
        ),
        array(
            're'  => 'qualità ([\d.,]+)%',
            'tpl' => 'quality $1%',
        ),
        array(
            're'  => '([\d.,]+) parole',
            'tpl' => '$1 words',
        ),
        array(
            're'  => '([\d.,]+) min di lettura',
            'tpl' => '$1 min read',
        ),
        array(
            're'  => '([\d.,]+) destinatari',
            'tpl' => '$1 recipients',
        ),
        array(
            're'  => 'Raccolto (\d+) candidato \(analisi evergreen: RSS non disponibile\)\.',
            'tpl' => 'Collected $1 candidate (evergreen analysis: RSS not available).',
        ),
        array(
            // v1.1.7 (T-C): variante singolare del messaggio di raccolta (il
            // codice pipeline usa il plurale anche con n=1: "Raccolti 1 nuovi
            // candidati da …"). Deve precedere il pattern generico.
            're'  => 'Raccolti 1 nuovi candidati da (.+?)\.',
            'tpl' => 'Collected 1 new candidate from $1.',
        ),
        array(
            're'  => 'Raccolti ([\d.,]+) nuovi candidati da (.+?)\.',
            'tpl' => 'Collected $1 new candidates from $2.',
        ),
        array(
            're'  => 'Raccolta completata: ([\d.,]+) nuovi articoli( da ([\d.,]+) agenti)?\.',
            'tpl' => 'Collection completed: $1 new articles$2.',
        ),
        array(
            're'  => ' da ([\d.,]+) agenti\b(?! in)',
            'tpl' => ' from $1 agents',
        ),
        array(
            're'  => 'Nessun candidato con punteggio sufficiente \(>= 50\) da (.+?)\.',
            'tpl' => 'No candidate with a sufficient score (>= 50) from $1.',
        ),
        array(
            're'  => 'Raccolta evergreen fallita: ',
            'tpl' => 'Evergreen collection failed: ',
        ),
        array(
            're'  => 'Raccolta fallita da (.+?): ',
            'tpl' => 'Collection failed from $1: ',
        ),
        array(
            're'  => 'Duplicato scartato: ',
            'tpl' => 'Duplicate discarded: ',
        ),
        array(
            're'  => 'Valutazione articolo #(\d+) fallita: ',
            'tpl' => 'Evaluation of article #$1 failed: ',
        ),
        array(
            're'  => 'Articolo #(\d+) "(.*?)" valutato: punteggio ([\d.,]+)/100\.',
            'tpl' => 'Article #$1 "$2" evaluated: score $3/100.',
        ),
        array(
            're'  => 'punteggio ([\d.,]+)/100',
            'tpl' => 'score $1/100',
        ),
        array(
            're'  => 'approvato dal revisore\.',
            'tpl' => 'approved by the reviewer.',
        ),
        array(
            're'  => 'rifiutato dal revisore\.',
            'tpl' => 'rejected by the reviewer.',
        ),
        array(
            're'  => 'Riscrittura articolo #(\d+) fallita nel giro di revisione: ',
            'tpl' => 'Rewriting of article #$1 failed during the review round: ',
        ),
        array(
            're'  => 'Riscrittura articolo #(\d+) fallita: ',
            'tpl' => 'Rewriting of article #$1 failed: ',
        ),
        array(
            're'  => 'Articolo #(\d+) riscritto \(([\d.,]+) parole, ([\d.,]+) min di lettura\) e messo in coda di approvazione\.',
            'tpl' => 'Article #$1 rewritten ($2 words, $3 min read) and queued for approval.',
        ),
        array(
            're'  => 'messo in coda di approvazione',
            'tpl' => 'queued for approval',
        ),
        array(
            're'  => 'Fact-check articolo #(\d+) non eseguito: ',
            'tpl' => 'Fact-check of article #$1 not run: ',
        ),
        array(
            're'  => 'Fact-check articolo #(\d+): risposta non interpretabile, verifica saltata\.',
            'tpl' => 'Fact-check of article #$1: response could not be parsed, check skipped.',
        ),
        array(
            're'  => 'Fact-check ([\d.,]+)/100 sotto soglia ([\d.,]+): articolo #(\d+) in bozza\. Problemi: ',
            'tpl' => 'Fact-check $1/100 below threshold $2: article #$3 back to draft. Issues: ',
        ),
        array(
            're'  => 'Fact-check articolo #(\d+) superato: ([\d.,]+)/100 \(soglia ([\d.,]+)\)\.',
            'tpl' => 'Fact-check of article #$1 passed: $2/100 (threshold $3).',
        ),
        array(
            're'  => 'Fact-check: ([\d.,]+)/100',
            'tpl' => 'Fact-check: $1/100',
        ),
        array(
            're'  => 'motore AI non disponibile',
            'tpl' => 'AI engine not available',
        ),
        array(
            're'  => 'risposta AI non interpretabile',
            'tpl' => 'AI response could not be parsed',
        ),
        array(
            're'  => 'risposta non interpretabile',
            'tpl' => 'response could not be parsed',
        ),
        array(
            're'  => 'Capo redattore: revisione articolo #(\d+) rimandata: budget mensile esaurito\.',
            'tpl' => 'Editor-in-chief: review of article #$1 postponed: monthly budget exhausted.',
        ),
        array(
            're'  => 'Capo redattore: nessun capo disponibile per l\'articolo #(\d+) \(sezione (.+?)\)\.',
            'tpl' => 'Editor-in-chief: no chief available for article #$1 (section $2).',
        ),
        array(
            're'  => 'Capo redattore: revisione articolo #(\d+) fallita: ',
            'tpl' => 'Editor-in-chief: review of article #$1 failed: ',
        ),
        array(
            're'  => 'Capo redattore: revisione articolo #(\d+) con risposta non interpretabile\.',
            'tpl' => 'Editor-in-chief: review of article #$1 returned an unparsable response.',
        ),
        array(
            're'  => 'Capo redattore: verdetto non riconosciuto \(\"(.*?)\"\) sull\'articolo #(\d+): trattato come richiesta di revisione\.',
            'tpl' => 'Editor-in-chief: unrecognized verdict ("$1") on article #$2: treated as a revision request.',
        ),
        array(
            're'  => 'Capo redattore ha modificato l\'articolo #(\d+) \(versione originale nel log\)\. Titolo precedente: \"(.*?)\"\. Nota: ',
            'tpl' => 'Editor-in-chief edited article #$1 (original version in the log). Previous title: "$2". Note: ',
        ),
        array(
            're'  => 'Capo redattore: salvataggio esito su articolo #(\d+) fallito: ',
            'tpl' => 'Editor-in-chief: failed to save the outcome for article #$1: ',
        ),
        array(
            're'  => 'Capo redattore: articolo #(\d+) approvato nella versione modificata\.',
            'tpl' => 'Editor-in-chief: article #$1 approved in the edited version.',
        ),
        array(
            're'  => 'Capo redattore: articolo #(\d+) approvato\.',
            'tpl' => 'Editor-in-chief: article #$1 approved.',
        ),
        array(
            're'  => 'Pubblicato dal capo redattore AI: articolo #(\d+) \(gia\' pubblicato in precedenza\)\.',
            'tpl' => 'Published by the AI editor-in-chief: article #$1 (already published before).',
        ),
        array(
            're'  => 'Pubblicato dal capo redattore AI: articolo #(\d+)\.',
            'tpl' => 'Published by the AI editor-in-chief: article #$1.',
        ),
        array(
            're'  => 'Pubblicazione automatica dell\'articolo #(\d+) fallita dopo approvazione del capo: ',
            'tpl' => 'Automatic publication of article #$1 failed after editor-in-chief approval: ',
        ),
        array(
            're'  => 'Capo redattore: articolo #(\d+) in stato approved \(modulo di pubblicazione non disponibile\)\.',
            'tpl' => 'Editor-in-chief: article #$1 in approved state (publishing module not available).',
        ),
        array(
            're'  => 'Capo redattore: articolo #(\d+) visto e modificato\.',
            'tpl' => 'Editor-in-chief: article #$1 reviewed and edited.',
        ),
        array(
            're'  => 'Capo redattore: articolo #(\d+) visto\.',
            'tpl' => 'Editor-in-chief: article #$1 reviewed.',
        ),
        array(
            're'  => 'In attesa di approvazione umana\.',
            'tpl' => 'Awaiting human approval.',
        ),
        array(
            're'  => 'Capo redattore: impossibile salvare il giro di revisione sull\'articolo #(\d+): ',
            'tpl' => 'Editor-in-chief: unable to save the review round for article #$1: ',
        ),
        array(
            're'  => 'Capo redattore: modulo riscrittura non disponibile, articolo #(\d+) resta in revisione con la nota del capo\.',
            'tpl' => 'Editor-in-chief: rewriting module not available, article #$1 stays in review with the editor\'s note.',
        ),
        array(
            're'  => 'Capo redattore: giri di revisione esauriti sull\'articolo #(\d+): richiesta intervento umano\.',
            'tpl' => 'Editor-in-chief: review rounds exhausted for article #$1: human intervention requested.',
        ),
        array(
            're'  => 'Rifiuto automatico articolo #(\d+) fallito: ',
            'tpl' => 'Automatic rejection of article #$1 failed: ',
        ),
        array(
            're'  => 'Capo redattore: articolo #(\d+) rifiutato: resta in coda per la decisione umana\.',
            'tpl' => 'Editor-in-chief: article #$1 rejected: it stays in the queue for the human decision.',
        ),
        array(
            're'  => 'Capo redattore: articolo #(\d+) rifiutato\.',
            'tpl' => 'Editor-in-chief: article #$1 rejected.',
        ),
        array(
            're'  => 'Capo redattore: lavoro di revisione senza article_id valido\.',
            'tpl' => 'Editor-in-chief: review job without a valid article_id.',
        ),
        array(
            're'  => 'Capo redattore: articolo #(\d+) in revisione \(giro ([\d.,]+)/([\d.,]+)\)\. Nota al giornalista: ',
            'tpl' => 'Editor-in-chief: article #$1 in review (round $2/$3). Note to the journalist: ',
        ),
        array(
            're'  => 'Capo redattore: ',
            'tpl' => 'Editor-in-chief: ',
        ),
        array(
            're'  => 'nessuna nota',
            'tpl' => 'no note',
        ),
        array(
            're'  => 'Pubblicazione articolo #(\d+) fallita: nessuna piattaforma configurata\.',
            'tpl' => 'Publishing of article #$1 failed: no platform configured.',
        ),
        array(
            're'  => 'Pubblicazione articolo #(\d+) fallita su tutte le piattaforme\.',
            'tpl' => 'Publishing of article #$1 failed on all platforms.',
        ),
        array(
            're'  => 'Articolo #(\d+) "(.*?)" pubblicato su: (.*?)\.',
            'tpl' => 'Article #$1 "$2" published on: $3.',
        ),
        array(
            're'  => 'Pubblicato su: (.*?)\.',
            'tpl' => 'Published on: $1.',
        ),
        array(
            're'  => 'Pubblicazione fallita su tutte le piattaforme\.',
            'tpl' => 'Publishing failed on all platforms.',
        ),
        array(
            're'  => 'Pubblicazione completata\.',
            'tpl' => 'Publishing completed.',
        ),
        array(
            're'  => 'Pubblicazione fallita senza dettagli\.',
            'tpl' => 'Publishing failed without details.',
        ),
        array(
            're'  => 'Canale social non configurato: nessuna integrazione disponibile per (.*?)\.',
            'tpl' => 'Social channel not configured: no integration available for $1.',
        ),
        array(
            're'  => 'Canale ([A-Za-z0-9_]+): ',
            'tpl' => 'Channel $1: ',
        ),
        array(
            're'  => 'Post WP #(\d+) per articolo Nexus #(\d+)',
            'tpl' => 'WP post #$1 for Nexus article #$2',
        ),
        array(
            're'  => 'Articolo Nexus #(\d+)',
            'tpl' => 'Nexus article #$1',
        ),
        array(
            're'  => 'Programmate ([\d.,]+) traduzioni automatiche per articolo #(\d+) \(post WP #(\d+)\)\.',
            'tpl' => 'Scheduled $1 automatic translations for article #$2 (WP post #$3).',
        ),
        array(
            're'  => 'Post #(\d+) aggiornato in lingua ([a-z]+) per articolo #(\d+) \(già tradotto\)\.',
            'tpl' => 'Post #$1 updated in language $2 for article #$3 (already translated).',
        ),
        array(
            're'  => 'Post #(\d+) pubblicato in lingua ([a-z]+) per articolo #(\d+)\.',
            'tpl' => 'Post #$1 published in language $2 for article #$3.',
        ),
        array(
            're'  => 'Traduzione ([a-z]{2}) articolo #(\d+): ',
            'tpl' => 'Translation $1 of article #$2: ',
        ),
        array(
            're'  => 'Traduzione ([a-z]{2}) articolo #(\d+)',
            'tpl' => 'Translation $1 of article #$2',
        ),
        array(
            're'  => 'Traduzione completata \((.*?)\)\.',
            'tpl' => 'Translation completed ($1).',
        ),
        array(
            're'  => 'Traduzione già pubblicata come post \((.*?)\)\.',
            'tpl' => 'Translation already published as a post ($1).',
        ),
        array(
            're'  => 'Traduzione pubblicata come post \((.*?)\)\.',
            'tpl' => 'Translation published as a post ($1).',
        ),
        array(
            're'  => 'Stato agente aggiornato a "(.*?)" via API\.',
            'tpl' => 'Agent status updated to "$1" via API.',
        ),
        array(
            're'  => 'Modello AI aggiornato: ',
            'tpl' => 'AI model updated: ',
        ),
        array(
            're'  => 'Agente "(.*?)" creato via editor\.',
            'tpl' => 'Agent "$1" created via the editor.',
        ),
        array(
            're'  => 'Agente "(.*?)" aggiornato via editor \((.*?)\)\.',
            'tpl' => 'Agent "$1" updated via the editor ($2).',
        ),
        array(
            're'  => 'Agente clonato da "(.*?)" \(id (\d+)\) come "(.*?)"\.',
            'tpl' => 'Agent cloned from "$1" (id $2) as "$3".',
        ),
        array(
            're'  => 'Sync nativo agente fallito: ',
            'tpl' => 'Native agent sync failed: ',
        ),
        array(
            're'  => 'Coda: ([\d.,]+) eseguiti, ([\d.,]+) falliti nel batch',
            'tpl' => 'Queue: $1 processed, $2 failed in the batch',
        ),
        array(
            're'  => 'Impossibile caricare la coda: ',
            'tpl' => 'Unable to load the queue: ',
        ),
        array(
            're'  => 'Budget mensile esaurito: la fase ([a-z_]+) prosegue comunque \(budget_action=warn\)\.',
            'tpl' => 'Monthly budget exhausted: the $1 phase continues anyway (budget_action=warn).',
        ),
        array(
            're'  => 'Fase ([a-z_]+) saltata: budget mensile esaurito\.',
            'tpl' => 'The $1 phase was skipped: monthly budget exhausted.',
        ),
        array(
            're'  => 'Budget mensile: ',
            'tpl' => 'Monthly budget: ',
        ),
        array(
            're'  => 'Nexus Wire avviato sul tema "(.*?)" con (\d+) agenti in redazione\.',
            'tpl' => 'Nexus Wire started on the topic "$1" with $2 agents in the newsroom.',
        ),
        array(
            're'  => 'Nexus Wire "(.*?)" fallito: ',
            'tpl' => 'Nexus Wire "$1" failed: ',
        ),
        array(
            're'  => 'Nexus Wire completato sul tema "(.*?)": articolo #(\d+) in coda di approvazione \(redazione: (.*?)\)\.',
            'tpl' => 'Nexus Wire completed on the topic "$1": article #$2 in the approval queue (desk: $3).',
        ),
        array(
            're'  => 'salvataggio articolo non riuscito',
            'tpl' => 'article save failed',
        ),
        array(
            're'  => '“(.+?)” è in coda di approvazione \(qualità ([\d.,]+)\)',
            'tpl' => '"$1" is in the approval queue (quality $2)',
        ),
        array(
            're'  => 'Avviare l’intera testata\? Verranno avviati ([\d.,]+) agenti\.',
            'tpl' => 'Run the entire masthead? $1 agents will be started.',
        ),
        array(
            're'  => 'Avvio completato: ',
            'tpl' => 'Run completed: ',
        ),
        array(
            're'  => 'Esito avvio — ',
            'tpl' => 'Run outcome — ',
        ),
        array(
            're'  => '([\d.,]+) riusciti',
            'tpl' => '$1 succeeded',
        ),
        array(
            're'  => '([\d.,]+) riuscito\b',
            'tpl' => '$1 succeeded',
        ),
        array(
            're'  => '([\d.,]+) falliti',
            'tpl' => '$1 failed',
        ),
        array(
            're'  => '([\d.,]+) fallito\b',
            'tpl' => '$1 failed',
        ),
        array(
            're'  => '([\d.,]+) raccolti',
            'tpl' => '$1 collected',
        ),
        array(
            're'  => '([\d.,]+) raccolto\b',
            'tpl' => '$1 collected',
        ),
        array(
            're'  => '([\d.,]+) pubblicati\b',
            'tpl' => '$1 published',
        ),
        array(
            're'  => '([\d.,]+) pubblicato\b(?! su)',
            'tpl' => '$1 published',
        ),
        array(
            're'  => '([\d.,]+) errori',
            'tpl' => '$1 errors',
        ),
        array(
            're'  => '([\d.,]+) errore\b',
            'tpl' => '$1 error',
        ),
        array(
            're'  => '([\d.,]+) agenti\b',
            'tpl' => '$1 agents',
        ),
        array(
            're'  => '([\d.,]+) agente\b',
            'tpl' => '$1 agent',
        ),
        array(
            're'  => 'Raccolti ([\d.,]+) · Valutati ([\d.,]+) · Riscritti ([\d.,]+) · Pubblicati ([\d.,]+)',
            'tpl' => 'Collected $1 · Evaluated $2 · Rewritten $3 · Published $4',
        ),
        array(
            're'  => 'Raccolte: ([\d.,]+) · Valutate: ([\d.,]+) · Riscritte: ([\d.,]+) · Pubblicate: ([\d.,]+)',
            'tpl' => 'Collected: $1 · Evaluated: $2 · Rewritten: $3 · Published: $4',
        ),
        array(
            're'  => 'In redazione ora: stato non disponibile \((.*?)\)',
            'tpl' => 'In the newsroom now: status not available ($1)',
        ),
        array(
            're'  => 'In redazione ora: ',
            'tpl' => 'In the newsroom now: ',
        ),
        array(
            're'  => '· aggiornato alle ([\d.,:]+)',
            'tpl' => '· updated at $1',
        ),
        array(
            're'  => 'Ultima azione: ',
            'tpl' => 'Last action: ',
        ),
        array(
            're'  => 'Ultimo run: ',
            'tpl' => 'Last run: ',
        ),
        array(
            're'  => 'Eliminare la redazione “(.*?)”\?\n\nLe sub-redazioni figlie verranno riassegnate alla redazione madre\. Gli articoli non vengono cancellati\.',
            'tpl' => 'Delete desk "$1"?\n\nChild desks will be reassigned to the parent desk. Articles are not deleted.',
        ),
        array(
            're'  => 'Articolo non valido: featured image non elaborabile\.',
            'tpl' => 'Invalid article: featured image cannot be processed.',
        ),
        array(
            're'  => 'Titolo dell\'articolo assente: featured image non generabile\.',
            'tpl' => 'Article title missing: featured image cannot be generated.',
        ),
        array(
            're'  => 'ID post WordPress mancante: featured image non impostabile\.',
            'tpl' => 'WordPress post ID missing: featured image cannot be set.',
        ),
        array(
            're'  => 'Impossibile salvare la featured image per il post #(\d+) \(upload o attachment falliti\)\.',
            'tpl' => 'Unable to save the featured image for post #$1 (upload or attachment failed).',
        ),
        array(
            're'  => 'set_post_thumbnail fallito per il post #(\d+)\.',
            'tpl' => 'set_post_thumbnail failed for post #$1.',
        ),
        array(
            're'  => 'Featured image impostata sul post #(\d+) \(origine: (.*?)\)\.',
            'tpl' => 'Featured image set on post #$1 (source: $2).',
        ),
        array(
            're'  => 'Errore imprevisto nella gestione della featured image: ',
            'tpl' => 'Unexpected error while handling the featured image: ',
        ),
        array(
            're'  => 'IndexNow errore fatale: ',
            'tpl' => 'IndexNow fatal error: ',
        ),
        array(
            're'  => 'IndexNow errore: ',
            'tpl' => 'IndexNow error: ',
        ),
        array(
            're'  => 'IndexNow salvataggio chiave fallito',
            'tpl' => 'IndexNow key save failed',
        ),
        array(
            're'  => 'IndexNow host del sito non disponibile',
            'tpl' => 'IndexNow site host not available',
        ),
        array(
            're'  => 'WebSub errore fatale: ',
            'tpl' => 'WebSub fatal error: ',
        ),
        array(
            're'  => 'WebSub errore: ',
            'tpl' => 'WebSub error: ',
        ),
        array(
            're'  => 'Digest inviato a ([\d.,]+) destinatari',
            'tpl' => 'Digest sent to $1 recipients',
        ),
        array(
            're'  => 'Invio digest fallito: ',
            'tpl' => 'Digest sending failed: ',
        ),
        array(
            're'  => 'Migrazione completata: ',
            'tpl' => 'Migration completed: ',
        ),
        array(
            're'  => 'Migrazione interrotta: ',
            'tpl' => 'Migration interrupted: ',
        ),
        array(
            're'  => 'Errore capo redattore nel tick del cron: ',
            'tpl' => 'Editor-in-chief error in the cron tick: ',
        ),
        array(
            're'  => 'Errore pipeline automatica per (.*?): ',
            'tpl' => 'Automatic pipeline error for $1: ',
        ),
        array(
            're'  => 'Errore durante il caricamento del pannello: ',
            'tpl' => 'Error while loading the panel: ',
        ),
        // v1.1.6 (integrazione): compositi runtime di admin.js — riepiloghi
        // dell'avvio, fallback "Agente #N", modello assegnato, stats redazioni.
        // Ordine: varianti con "falliti"/singolari (piu' specifiche) prima.
        array(
            're'  => 'Avvio completato: 1 agente · (\d+) riuscit[oi] · (\d+) fallit[oi]',
            'tpl' => 'Run complete: 1 agent · $1 succeeded · $2 failed',
        ),
        array(
            're'  => 'Avvio completato: (\d+) agenti · (\d+) riuscit[oi] · (\d+) fallit[oi]',
            'tpl' => 'Run complete: $1 agents · $2 succeeded · $3 failed',
        ),
        array(
            're'  => 'Avvio completato: 1 agente · (\d+) riuscit[oi]',
            'tpl' => 'Run complete: 1 agent · $1 succeeded',
        ),
        array(
            're'  => 'Avvio completato: (\d+) agenti · (\d+) riuscit[oi]',
            'tpl' => 'Run complete: $1 agents · $2 succeeded',
        ),
        array(
            're'  => 'Avvio completato: 1 agente · (\d+) raccolt[oi] · (\d+) pubblicat[oi] · (\d+) error[ei]',
            'tpl' => 'Run complete: 1 agent · $1 collected · $2 published · $3 errors',
        ),
        array(
            're'  => 'Avvio completato: (\d+) agenti · (\d+) raccolt[oi] · (\d+) pubblicat[oi] · (\d+) error[ei]',
            'tpl' => 'Run complete: $1 agents · $2 collected · $3 published · $4 errors',
        ),
        array(
            're'  => 'Agente #(\d+)',
            'tpl' => 'Agent #$1',
        ),
        array(
            're'  => 'Modello "(.+?)" assegnato all\'agente\.',
            'tpl' => 'Model "$1" assigned to the agent.',
        ),
        array(
            're'  => '(\d+) agente assegnato',
            'tpl' => '$1 assigned agent',
        ),
        array(
            're'  => '(\d+) agenti assegnati',
            'tpl' => '$1 assigned agents',
        ),
        array(
            're'  => '(\d+) articolo coperto',
            'tpl' => '$1 covered article',
        ),
        array(
            're'  => '(\d+) articoli coperti',
            'tpl' => '$1 covered articles',
        ),
        array(
            're'  => '(\d+) redazioni',
            'tpl' => '$1 desks',
        ),
        array(
            're'  => '(\d+) redazione\b',
            'tpl' => '$1 desk',
        ),
        // v1.1.7 (T-C): aria-label dell'input modello per agente (admin.js,
        // agentCardHtml): "Modello AI per " + nome agente (contenuto utente).
        array(
            're'  => 'Modello AI per (.+)$',
            'tpl' => 'AI model for $1',
        ),
        // v1.1.7 (T-C): esiti del pannello "Test degli strumenti" (admin.js
        // v1.1.7, zona tools-test di T-B2): messaggi compositi con nome
        // strumento/esito variabile renderizzati come nodi testo del DOM.
        array(
            're'  => 'Test riuscito \(([^)]+)\)\. Esito dello strumento:',
            'tpl' => 'Test succeeded ($1). Tool output:',
        ),
        array(
            're'  => 'Test non riuscito \(([^)]+)\): ',
            'tpl' => 'Test failed ($1): ',
        ),
        array(
            're'  => 'Test non riuscito: ',
            'tpl' => 'Test failed: ',
        ),
        array(
            're'  => '\(nessun output\)',
            'tpl' => '(no output)',
        ),
        /* ---- v1.1.10.1 — audit v1.1.10: messaggi compositi REST (model picker,
         * OAuth, provider custom). I frammenti esatti del dict non bastano: il
         * messaggio completo arriva da concatenazione PHP con variabili. Ordine:
         * i pattern più specifici PRIMA dei generici (URL base “personalizzato”
         * prima di URL base generico). ---- */
        array(
            're'  => 'URL base mancante per il provider personalizzato (.+)$',
            'tpl' => 'Missing base URL for custom provider $1',
        ),
        array(
            're'  => 'URL base mancante per il provider (.+)$',
            'tpl' => 'Missing base URL for provider $1',
        ),
        array(
            're'  => 'Chiave API mancante per il provider (.+)$',
            'tpl' => 'Missing API key for provider $1',
        ),
        array(
            're'  => 'Provider personalizzato sconosciuto o eliminato \\((.+)\\)$',
            'tpl' => 'Unknown or deleted custom provider ($1)',
        ),
        array(
            're'  => 'Provider AI sconosciuto: (.+)$',
            'tpl' => 'Unknown AI provider: $1',
        ),
        array(
            're'  => 'Provider non raggiungibile: (.+)$',
            'tpl' => 'Provider unreachable: $1',
        ),
        array(
            're'  => 'Il provider(?: AI)? ha risposto con errore HTTP (\\d+)(.*)$',
            'tpl' => 'The provider responded with HTTP error $1$2',
        ),
        /* ---- v1.1.11 (R3) — wizard onboarding: progresso e riepilogo con
         * numeri (admin.js, sezione v1.1.11). Ordine: la variante singolare
         * "1 agente creato." prima di quella con \d+ agenti. ---- */
        array(
            're'  => 'Creo agente (\\d+) di (\\d+)…',
            'tpl' => 'Creating agent $1 of $2…',
        ),
        array(
            're'  => 'Wizard completato: 1 agente creato\\.',
            'tpl' => 'Wizard completed: 1 agent created.',
        ),
        array(
            're'  => 'Wizard completato: (\\d+) agenti creati\\.',
            'tpl' => 'Wizard completed: $1 agents created.',
        ),
        array(
            're'  => 'Agenti creati: (\\d+)',
            'tpl' => 'Agents created: $1',
        ),
        array(
            're'  => 'Errori: (\\d+)',
            'tpl' => 'Errors: $1',
        ),
        array(
            're'  => 'Fonti RSS non salvate: ',
            'tpl' => 'RSS sources not saved: ',
        ),
        /* ---- v1.1.11 (R4-1) — class-nexus-rest.php (messaggi REST con
         * placeholder %s/%d/%1$s): messaggi completi interpolati da sprintf.
         * I pattern più specifici prima dei generici. ---- */
        array(
            're'  => 'Stato non valido\. Valori ammessi: (.+)\.$',
            'tpl' => 'Invalid status. Allowed values: $1.',
        ),
        array(
            're'  => 'Categoria già in uso dall\'agente "(.+)". Scegiline un\'altra\.$',
            'tpl' => 'Category already in use by the agent "$1". Choose a different one.',
        ),
        array(
            're'  => 'Agente clonato da "(.+)" \(id (\d+)\) come "(.+)"\.$',
            'tpl' => 'Agent cloned from "$1" (id $2) as "$3".',
        ),
        array(
            're'  => 'Provider (.+) scollegato: chiave API rimossa dalle impostazioni\.$',
            'tpl' => 'Provider $1 disconnected: API key removed from the settings.',
        ),
        array(
            're'  => 'Provider personalizzato rimosso \((.+)\)\.$',
            'tpl' => 'Custom provider removed ($1).',
        ),
        array(
            're'  => 'Test connessione provider (.+) riuscito: (\d+) modelli in (\d+) ms\.$',
            'tpl' => 'Provider $1 connection test succeeded: $2 models in $3 ms.',
        ),
        array(
            're'  => 'La redazione "(.+)" non ha agenti assegnati: nessun avvio\.$',
            'tpl' => 'The desk "$1" has no assigned agents: nothing to start.',
        ),
        array(
            're'  => 'Avvio completato con errori: (\d+) di (\d+) agenti hanno riportato problemi\.$',
            'tpl' => 'Run completed with errors: $1 of $2 agents reported problems.',
        ),
        array(
            're'  => 'Redazione "(.+)" eliminata\. Le (\d+) sub-redazioni sono state riassegnate da WordPress alla redazione madre precedente \(o al primo livello se non esisteva\); gli articoli hanno perso solo questo termine e mantengono le altre redazioni assegnate\.$',
            'tpl' => 'Desk "$1" deleted. The $2 sub-desks have been reassigned by WordPress to the previous parent desk (or to the first level if none existed); the articles only lost this term and keep their other assigned desks.',
        ),
        array(
            're'  => 'Redazione "(.+)" eliminata\. Gli articoli hanno perso solo questo termine e mantengono le altre redazioni assegnate\.$',
            'tpl' => 'Desk "$1" deleted. The articles only lost this term and keep their other assigned desks.',
        ),

        /* v1.1.11 (R4-2) — class-nexus-pipeline.php (log attività/fasi) */
        array(
            're'  => '(\d+) fonti redazione',
            'tpl' => '$1 desk sources',
        ),
        array(
            're'  => '(\d+) sources redazione',
            'tpl' => '$1 desk sources',
        ),
        array(
            're'  => 'Inserimento articolo fallito: (.+)$',
            'tpl' => 'Article insert failed: $1',
        ),
        array(
            're'  => 'Articolo #(\d+) valutato col full-content della fonte \((\d+) caratteri di testo nel prompt\)\.',
            'tpl' => 'Article #$1 evaluated with the full content of the source ($2 characters of text in the prompt).',
        ),
        array(
            're'  => 'Output AI articolo #(\d+): flag ([^)]+) \(contenuto ripulito con Nexus_Utils::clean_ai_content prima del salvataggio\)\.',
            'tpl' => 'AI output for article #$1: flag $2 (content cleaned with Nexus_Utils::clean_ai_content before saving).',
        ),
        array(
            're'  => 'Articolo #(\d+) pending del capo redattore: auto-approvazione non eseguita, resta in coda di revisione\.',
            'tpl' => 'Article #$1 awaiting the editor-in-chief: auto-approval not performed, remains in the review queue.',
        ),
        array(
            're'  => 'Articolo #(\d+) pending del capo redattore: auto-approvazione saltata, resta in coda di revisione\.',
            'tpl' => 'Article #$1 awaiting the editor-in-chief: auto-approval skipped, remains in the review queue.',
        ),
        array(
            're'  => 'Articolo #(\d+) pending del capo redattore: auto-approvazione e pubblicazione automatiche saltate\.',
            'tpl' => 'Article #$1 awaiting the editor-in-chief: auto-approval and automatic publication skipped.',
        ),
        array(
            're'  => 'Articolo #(\d+) pending del capo redattore: auto-approvazione saltata \(modalità fully autonomous\)\.',
            'tpl' => 'Article #$1 awaiting the editor-in-chief: auto-approval skipped (fully autonomous mode).',
        ),
        array(
            're'  => 'L\'articolo #(\d+) non è pubblicabile nello stato attuale \(([^)]+)\): serve lo stato "approved"\.',
            'tpl' => 'Article #$1 cannot be published in its current state ($2): the "approved" status is required.',
        ),
        array(
            're'  => 'Agente (.+) non attivo: pipeline saltata\.',
            'tpl' => 'Agent $1 not active: pipeline skipped.',
        ),
        array(
            're'  => 'Limite giornaliero raggiunto \((\d+)/(\d+) articoli oggi\): pipeline non avviata\.',
            'tpl' => 'Daily limit reached ($1/$2 articles today): pipeline not started.',
        ),
        array(
            're'  => 'Limite giornaliero raggiunto \((\d+)/(\d+) articles oggi\): pipeline non avviata\.',
            'tpl' => 'Daily limit reached ($1/$2 articles today): pipeline not started.',
        ),
        array(
            're'  => 'Raccolti (\d+) nuovi candidati\.',
            'tpl' => 'Collected $1 new candidates.',
        ),
        array(
            're'  => 'Articolo #(\d+) rimesso in bozza dal fact-check: serve revisione umana\.',
            'tpl' => 'Article #$1 sent back to draft by fact-check: human review required.',
        ),
        array(
            're'  => 'approvazione/pubblicazione',
            'tpl' => 'approval/publishing',
        ),
        array(
            're'  => 'Approvazione automatica: punteggio (\d+) >= (\d+) \(modalità fully autonomous\)\.',
            'tpl' => 'Automatic approval: score $1 >= $2 (fully autonomous mode).',
        ),
        array(
            're'  => 'Articolo #(\d+) approvato automaticamente \(punteggio (\d+) >= (\d+)\)\.',
            'tpl' => 'Article #$1 approved automatically (score $2 >= $3).',
        ),
        array(
            're'  => 'Articolo #(\d+) pubblicato automaticamente\.',
            'tpl' => 'Article #$1 published automatically.',
        ),
        array(
            're'  => 'Articolo #(\d+) published automaticamente\.',
            'tpl' => 'Article #$1 published automatically.',
        ),
        array(
            're'  => 'Pubblicazione automatica articolo #(\d+) fallita: (.+)$',
            'tpl' => 'Automatic publication of article #$1 failed: $2',
        ),
        array(
            're'  => 'Approvazione automatica articolo #(\d+) fallita: (.+)$',
            'tpl' => 'Automatic approval of article #$1 failed: $2',
        ),
        array(
            're'  => 'Articolo #(\d+) in coda di revisione: punteggio (\d+) sotto la soglia di auto-approvazione \((\d+)\)\.',
            'tpl' => 'Article #$1 in the review queue: score $2 below the auto-approval threshold ($3).',
        ),
        array(
            're'  => 'Articolo #(\d+) pubblicato \(auto_publish attivo\)\.',
            'tpl' => 'Article #$1 published (auto_publish enabled).',
        ),
        array(
            're'  => 'Articolo #(\d+) published \(auto_publish attivo\)\.',
            'tpl' => 'Article #$1 published (auto_publish enabled).',
        ),
        array(
            're'  => 'Articolo #(\d+) in coda di approvazione \(modalità semi_autonomous\)\.',
            'tpl' => 'Article #$1 in the approval queue (semi_autonomous mode).',
        ),
        array(
            're'  => 'Articolo #(\d+) lasciato in stato bozza \(auto_rewrite disabilitato\)\.',
            'tpl' => 'Article #$1 left in draft state (auto_rewrite disabled).',
        ),
        array(
            're'  => 'Resume articoli bloccati fallito: (.+)$',
            'tpl' => 'Stalled articles resume failed: $1',
        ),
        array(
            're'  => 'Errore pipeline: (.+)$',
            'tpl' => 'Pipeline error: $1',
        ),
        array(
            're'  => 'Resume dopo stallo: articolo #(\d+) in bozza da oltre (\d+)h: rilancio valutazione/riscrittura\.',
            'tpl' => 'Resume after stall: article #$1 in draft for over $2h: restarting evaluation/rewrite.',
        ),
        array(
            're'  => 'Articolo #(\d+): valutazione fallita nel resume: (.+)$',
            'tpl' => 'Article #$1: evaluation failed on resume: $2',
        ),
        array(
            're'  => 'Articolo #(\d+) ripreso dopo stallo: riscritto e messo in coda di approvazione\.',
            'tpl' => 'Article #$1 resumed after stall: rewritten and queued for approval.',
        ),
        array(
            're'  => 'Articolo #(\d+) ripreso dopo stallo: riscritto e queued for approval\.',
            'tpl' => 'Article #$1 resumed after stall: rewritten and queued for approval.',
        ),
        array(
            're'  => 'Articolo #(\d+): riscrittura fallita nel resume: (.+)$',
            'tpl' => 'Article #$1: rewrite failed on resume: $2',
        ),
        array(
            're'  => 'Articolo #(\d+) ripreso dopo stallo: rivalutato\.',
            'tpl' => 'Article #$1 resumed after stall: re-evaluated.',
        ),
        array(
            're'  => 'Resume articoli bloccati: (\d+) bozze riprese \(ferme da oltre (\d+)h\)\.',
            'tpl' => 'Stalled articles resume: $1 drafts resumed (stalled for over $2h).',
        ),
        array(
            're'  => 'La direzione non ha completato la fusione: (.+)$',
            'tpl' => 'The editorial desk did not complete the merge: $1',
        ),
        array(
            're'  => 'Stato non valido per l\'approvazione: "([^"]+)" \(richiesti "pending_approval" o "approved"\)\.',
            'tpl' => 'Invalid status for approval: "$1" ("pending_approval" or "approved" required).',
        ),
        array(
            're'  => 'Articolo #(\d+) "(.*?)" approved by the reviewer\.',
            'tpl' => 'Article #$1 "$2" approved by the reviewer.',
        ),
        array(
            're'  => 'Articolo #(\d+) "(.*?)" rejected by the reviewer\.',
            'tpl' => 'Article #$1 "$2" rejected by the reviewer.',
        ),
        array(
            're'  => 'Articolo #(\d+): time budget per articolo esaurito \((\d+)s\) prima della fase "(.*?)": fasi successive interrotte con grazia, i cicli successivi \(resume/coda\) recuperano\.',
            'tpl' => 'Article #$1: per-article time budget exhausted ($2s) before the "$3" phase: subsequent phases gracefully interrupted, later cycles (resume/queue) recover.',
        ),
        array(
            're'  => 'Fact-check articolo #(\d+): fonte non raggiungibile, fact-check sul riassunto\.',
            'tpl' => 'Fact-check of article #$1: source unreachable, fact-check on the summary.',
        ),
        array(
            're'  => 'nessun dettaglio fornito dal modello',
            'tpl' => 'no details provided by the model',
        ),
        /* v1.1.11 (R4-3) — class-nexus-chief.php / class-nexus-tools.php.
         * Ordine: dal più specifico al più generico; le alternative (?:a|b)
         * coprono anche lo stato già pre-tradotto dai frammenti generici. */
        array(
            're'  => 'Articolo in stato "([^"]+)": revisione capo redattore non necessaria\.',
            'tpl' => 'Article in status "$1": editor-in-chief review not needed.',
        ),
        array(
            're'  => 'Articolo in stato "([^"]+)": non e\' in coda di revisione\.',
            'tpl' => 'Article in status "$1": not in the review queue.',
        ),
        array(
            're'  => 'Articolo gia\' rivisto dal capo redattore \(stato: ([^)]+)\)\.',
            'tpl' => 'Article already reviewed by the editor-in-chief (status: $1).',
        ),
        array(
            're'  => 'Contatore giri di revisione non persistito: (?:revisione interrotta per prevenire loop\.|review stopped to prevent a loop\.) \(articolo #(\d+)\)',
            'tpl' => 'Review round counter not persisted: review stopped to prevent a loop (article #$1).',
        ),
        array(
            're'  => 'budget AI di revisione esaurito per l\'articolo #(\d+) \((\d+) revisioni in 24h\): sospeso, serve intervento umano\.',
            'tpl' => 'review AI budget exhausted for article #$1 ($2 reviews in 24h): suspended, human intervention required.',
        ),
        array(
            're'  => 'revisione articolo #(\d+) con (?:risposta non interpretabile|response could not be parsed)\.',
            'tpl' => 'review of article #$1: response could not be parsed.',
        ),
        array(
            're'  => 'modulo riscrittura non disponibile, (?:articolo|article) #(\d+) resta in revisione con la nota del capo\.',
            'tpl' => 'rewrite module unavailable, article #$1 stays in review with the chief note.',
        ),
        array(
            're'  => 'Nota del capo redattore: (.+)$',
            'tpl' => 'Editor-in-chief note: $1',
        ),
        array(
            're'  => 'Query articoli (?:in attesa|pending) non riuscita \(schema database non aggiornato\?\)\.',
            'tpl' => 'Pending articles query failed (database schema not updated?).',
        ),
        array(
            're'  => 'query articoli (?:in attesa|pending) fallita \(verifica l\'aggiornamento dello schema\)\.',
            'tpl' => 'query for pending articles failed (check the schema update).',
        ),
        array(
            're'  => 'Provider di ricerca web non riconosciuto \("([^"]+)"\): valori ammessi tavily, serper, brave, none\.',
            'tpl' => 'Unrecognized web search provider ("$1"): allowed values are tavily, serper, brave, none.',
        ),
        array(
            're'  => 'URL del server MCP "([^"]+)" non valido o non ammesso \(solo http/https\): (.+)$',
            'tpl' => 'MCP server URL "$1" invalid or disallowed (http/https only): $2',
        ),
        array(
            're'  => 'URL non valido o non ammesso \(solo http/https\): (.+)$',
            'tpl' => 'Invalid or disallowed URL (http/https only): $1',
        ),
        array(
            're'  => 'Server MCP "([^"]+)" non presente tra i server configurati \(impostazioni globali o strumenti personali dell\'agente\)\.',
            'tpl' => 'MCP server "$1" not found among the configured servers (global settings or the agent\'s personal tools).',
        ),
        array(
            're'  => 'Il tool MCP "([^"]+)" ha segnalato un errore: (.+)$',
            'tpl' => 'MCP tool "$1" reported an error: $2',
        ),
        array(
            're'  => 'Il tool MCP "([^"]+)" ha segnalato un errore\.',
            'tpl' => 'MCP tool "$1" reported an error.',
        ),
        array(
            're'  => 'Lettura pagina: (.+)$',
            'tpl' => 'Page read: $1',
        ),
        array(
            're'  => 'Errore interno del client MCP: (.+)$',
            'tpl' => 'MCP client internal error: $1',
        ),
        array(
            're'  => 'Inizializzazione MCP fallita: (.+)$',
            'tpl' => 'MCP initialization failed: $1',
        ),
        array(
            're'  => 'Errore di connessione al server MCP: (.+)$',
            'tpl' => 'MCP server connection error: $1',
        ),
        array(
            're'  => '[Ii]l server MCP ha risposto con errore HTTP (\d+): (.+)$',
            'tpl' => 'The MCP server returned HTTP error $1: $2',
        ),
        array(
            're'  => '[Ii]l server MCP ha risposto con errore HTTP (\d+)\.$',
            'tpl' => 'The MCP server returned HTTP error $1.',
        ),
        array(
            're'  => '[Ii]l server MCP ha risposto con un errore JSON-RPC \(codice (\d+)\): (.+)$',
            'tpl' => 'The MCP server returned a JSON-RPC error (code $1): $2',
        ),
        array(
            're'  => '[Ii]l server MCP ha risposto con un errore JSON-RPC: (.+)$',
            'tpl' => 'The MCP server returned a JSON-RPC error: $1',
        ),
        array(
            're'  => '[Ii]l server MCP ha risposto con un errore JSON-RPC\.$',
            'tpl' => 'The MCP server returned a JSON-RPC error.',
        ),
        array(
            're'  => 'Il modello non ha concluso entro il numero massimo di giri \((\d+)\) del ciclo strumenti\.',
            'tpl' => 'The model did not finish within the maximum number of rounds ($1) of the tool loop.',
        ),
        array(
            're'  => 'Tempo massimo per il ciclo strumenti raggiunto \((\d+) secondi\): esecuzione interrotta entro (\d+) giri\.',
            'tpl' => 'Maximum time for the tool loop reached ($1 seconds): execution stopped within $2 rounds.',
        ),
        array(
            're'  => 'Errore interno del ciclo strumenti: (.+)$',
            'tpl' => 'Tool loop internal error: $1',
        ),
        array(
            're'  => 'Errore di connessione: (.+)$',
            'tpl' => 'Connection error: $1',
        ),
        array(
            're'  => '[Ii]l server ha risposto con errore HTTP (\d+): (.+)$',
            'tpl' => 'The server returned HTTP error $1: $2',
        ),
        array(
            're'  => '[Ii]l server ha risposto con errore HTTP (\d+)\.$',
            'tpl' => 'The server returned HTTP error $1.',
        ),
        array(
            're'  => '[Ii]l servizio ha risposto con errore HTTP (\d+): (.+)$',
            'tpl' => 'The service returned HTTP error $1: $2',
        ),
        array(
            're'  => '[Ii]l servizio ha risposto con errore HTTP (\d+)\.$',
            'tpl' => 'The service returned HTTP error $1.',
        ),
        array(
            're'  => 'Errore imprevisto: (.+)$',
            'tpl' => 'Unexpected error: $1',
        ),
        array(
            're'  => 'Articolo #(\d+): (.+)$',
            'tpl' => 'Article #$1: $2',
        ),

        /* v1.1.11 (R4-4) — assets/js/admin.js (stringhe UI client composte a
         * runtime, nodi testo/attributi dinamici). Pattern APPENDITI in coda:
         * riepilogo modelli, toast login OpenRouter, stats redazioni, fallback
         * errori pannello. L'ultimo non ha variabili: serve come pattern per
         * sostituire il frammento dentro messaggi più lunghi. */
        array(
            're'  => 'Mostrati (\d+) di (\d+) modelli: affina la ricerca\.',
            'tpl' => 'Showing $1 of $2 models: refine your search.',
        ),
        array(
            're'  => 'OpenRouter non ha completato il login: (.+)$',
            'tpl' => 'OpenRouter did not complete the login: $1',
        ),
        array(
            're'  => 'articolo coperto',
            'tpl' => 'article covered',
        ),
        array(
            're'  => 'riprova ricaricando la pagina\.',
            'tpl' => 'try reloading the page.',
        ),

        /* v1.1.11 (R4-5) — class-nexus-image.php / class-nexus-translate.php
         * (messaggi composti a runtime). Pattern APPENDITI in coda. Ordine
         * voluto: i wrapper (generazione AI fallita/p […], pixabay non
         * utilizzabile/p […], download immagine generata fallito/p […])
         * precedono gli errori annidati che essi avvolgono; i pattern senza $
         * finale traducono FRAMMENTI dentro messaggi più lunghi (note pipeline
         * featured image), quelli con $ catturano il messaggio intero
         * (errori REST e log activity delle traduzioni). */
        array(
            're'  => 'generazione AI \(modello ([^)]+)\)',
            'tpl' => 'AI generation (model $1)',
        ),
        array(
            're'  => 'generazione AI fallita \((.+?)\)',
            'tpl' => 'AI generation failed ($1)',
        ),
        array(
            're'  => 'image_url scartata dal gate qualità: larghezza (\d+)px sotto il minimo di (\d+)px',
            'tpl' => 'image_url rejected by quality gate: width $1px below the minimum of $2px',
        ),
        array(
            're'  => 'image_url non utilizzabile \((.+?)\)',
            'tpl' => 'image_url not usable ($1)',
        ),
        array(
            're'  => 'pixabay scartata dal gate qualità: larghezza reale (\d+)px sotto il minimo di (\d+)px',
            'tpl' => 'pixabay rejected by quality gate: actual width $1px below the minimum of $2px',
        ),
        array(
            're'  => 'immagine Pixabay \(query "([^"]+)"\)',
            'tpl' => 'Pixabay image (query "$1")',
        ),
        array(
            're'  => 'pixabay non utilizzabile \((.+?)\)',
            'tpl' => 'pixabay not usable ($1)',
        ),
        array(
            're'  => 'Impossibile salvare la featured image per il post #(\d+) \(upload o attachment falliti; featured_source: ([a-z_]*)\)\.$',
            'tpl' => 'Unable to save the featured image for post #$1 (upload or attachment failed; featured_source: $2).',
        ),
        array(
            're'  => 'errore di connessione al servizio immagini: (.+)',
            'tpl' => 'image service connection error: $1',
        ),
        array(
            're'  => 'il servizio immagini ha risposto con errore HTTP (\d+)',
            'tpl' => 'the image service returned HTTP error $1',
        ),
        array(
            're'  => 'download immagine generata fallito: (.+)',
            'tpl' => 'generated image download failed: $1',
        ),
        array(
            're'  => 'errore di download: (.+)',
            'tpl' => 'download error: $1',
        ),
        array(
            're'  => 'download con errore HTTP (\d+)',
            'tpl' => 'download returned HTTP error $1',
        ),
        array(
            're'  => 'pixabay: zero risultati utilizzabili per la query "([^"]+)" \(nessun esito o tutti sotto il gate di (\d+)px\)',
            'tpl' => 'pixabay: no usable results for query "$1" (no hits or all below the $2px gate)',
        ),
        array(
            're'  => 'pixabay: tutti i risultati per la query "([^"]+)" sono già stati usati',
            'tpl' => 'pixabay: all results for query "$1" have already been used',
        ),
        array(
            're'  => 'pixabay: errore di connessione all\'API \((.+?)\)',
            'tpl' => 'pixabay: API connection error ($1)',
        ),
        array(
            're'  => 'pixabay: API ha risposto con errore HTTP (\d+)',
            'tpl' => 'pixabay: API returned HTTP error $1',
        ),
        array(
            're'  => 'Lingua "([^"]+)" non consentita: lingue attive (.+)\.$',
            'tpl' => 'Language "$1" not allowed: active languages $2.',
        ),
        array(
            're'  => 'Eccezione durante la traduzione: (.+)$',
            'tpl' => 'Exception during translation: $1',
        ),
        array(
            're'  => 'L\'articolo #(\d+) non ha titolo né contenuto da tradurre\.$',
            'tpl' => 'Article #$1 has neither title nor content to translate.',
        ),
        array(
            're'  => 'Traduzione interrotta: budget AI esaurito dopo (\d+) di (\d+) segmenti\.$',
            'tpl' => 'Translation stopped: AI budget exhausted after $1 of $2 segments.',
        ),
        array(
            're'  => 'Traduzione saltata: budget AI esaurito \(articolo #(\d+), ([a-z0-9_-]+)\)$',
            'tpl' => 'Translation skipped: AI budget exhausted (article #$1, $2)',
        ),
        array(
            're'  => 'AI: traduzione incompleta, segmento (\d+) vuoto\.$',
            'tpl' => 'AI: incomplete translation, segment $1 is empty.',
        ),
        array(
            're'  => 'Traduzione ([a-z0-9_-]+) articolo #(\d+) completata ma contenuto parzialmente tradotto \(limite di (\d+) segmenti\)\.$',
            'tpl' => 'Translation $1 of article #$2 completed but content partially translated (limit of $3 segments).',
        ),
        array(
            're'  => 'Aggiornamento del post tradotto non riuscito: (.+)$',
            'tpl' => 'Updating the translated post failed: $1',
        ),
        array(
            're'  => 'Pubblicazione del post tradotto non riuscita: (.+)$',
            'tpl' => 'Publishing the translated post failed: $1',
        ),
        array(
            're'  => 'Traduzione ([a-z0-9_-]+) articolo #(\d+)$',
            'tpl' => 'Translation $1 of article #$2',
        ),

        /* v1.1.11 (R4-6) — ai-engine / providers / sources. Messaggi compositi del
         * motore AI (errori HTTP, rate limit/breaker, prompt cache, prezzi),
         * risoluzione provider custom/OAuth e log fonti (YouTube, Telegram,
         * Reddit, Search, MCP, Web, Full-content). Le voci di coda delle code
         * IT già aperte dai pattern v1.1.10.1 vengono completate al giro
         * successivo della catena i18n.js. */
        array(
            're'  => 'Risposta servita dalla prompt cache \(TTL 12h, modello (.+)\): nessuna chiamata API effettuata\.',
            'tpl' => 'Response served from the prompt cache (12h TTL, model $1): no API call made.',
        ),
        array(
            're'  => 'Provider AI non disponibile: (.+)\.',
            'tpl' => 'AI provider unavailable: $1.',
        ),
        array(
            're'  => 'Rate limit AI attivo per il provider (.+): riprova tra circa un minuto \(cooldown automatico\)\.',
            'tpl' => 'AI rate limit active for provider $1: try again in about a minute (automatic cooldown).',
        ),
        array(
            're'  => 'Rate limit AI attivo per il provider (.+): cooldown automatico di 60s dopo 429 consecutivi\.',
            'tpl' => 'AI rate limit active for provider $1: automatic 60s cooldown after consecutive 429s.',
        ),
        array(
            're'  => 'Provider primario non disponibile \((.+)\): ritenta con il provider di fallback\.',
            'tpl' => 'Primary provider unavailable ($1): retrying with the fallback provider.',
        ),
        array(
            're'  => 'Chiave API non valida o non autorizzata \(HTTP (\d+)\)\. Verifica ai_api_key nelle impostazioni del plugin\. Dettaglio: (.+)$',
            'tpl' => 'Invalid or unauthorized API key (HTTP $1). Check ai_api_key in the plugin settings. Detail: $2',
        ),
        array(
            're'  => 'Chiave API non valida o non autorizzata \(HTTP (\d+)\)\. Verifica ai_api_key nelle impostazioni del plugin\.',
            'tpl' => 'Invalid or unauthorized API key (HTTP $1). Check ai_api_key in the plugin settings.',
        ),
        array(
            're'  => 'Limite di richieste verso il servizio AI raggiunto \(HTTP 429\)\. Riprova più tardi\. Dettaglio: (.+)$',
            'tpl' => 'AI service request limit reached (HTTP 429). Try again later. Detail: $1',
        ),
        array(
            're'  => 'Il servizio AI ha risposto con errore HTTP (\d+): (.+)$',
            'tpl' => 'The AI service returned HTTP error $1: $2',
        ),
        array(
            're'  => 'Il servizio AI ha risposto con errore HTTP (\d+)\.',
            'tpl' => 'The AI service returned HTTP error $1.',
        ),
        array(
            're'  => 'Risposta del modello (.+) troncata per max_tokens \(finish_reason=length\): aument(o|a) max_tokens se il problema persiste\.',
            'tpl' => 'Response from model $1 truncated due to max_tokens (finish_reason=length): increase max_tokens if the problem persists.',
        ),
        array(
            're'  => 'Il modello AI \((.+)\) ha restituito una risposta vuota\.',
            'tpl' => 'The AI model ($1) returned an empty response.',
        ),
        /* v1.1.17 («Pong Onesto») — errori «vuoto» distinti del motore: token
         * esauriti prima del contenuto (modelli reasoning) vs vuoto vero con
         * altro finish_reason; i vecchi messaggi qui sopra restano per le righe
         * attività già in database. */
        array(
            're'  => 'Il modello AI \((.+)\) ha restituito una risposta vuota: max_tokens esauriti prima del contenuto \(tipico dei modelli reasoning\)\. Aumenta max_tokens e riprova\.',
            'tpl' => 'The AI model ($1) returned an empty response: max_tokens ran out before any content (typical of reasoning models). Increase max_tokens and try again.',
        ),
        array(
            're'  => 'Il modello AI \((.+)\) ha restituito una risposta vuota \(finish_reason=(\w+)\)\. Riprova; se persiste, prova un altro modello\.',
            'tpl' => 'The AI model ($1) returned an empty response (finish_reason=$2). Try again; if it persists, try a different model.',
        ),
        array(
            're'  => ' Il modello ha prodotto solo ragionamento \((\d+) caratteri\) senza contenuto finale\.',
            'tpl' => ' The model produced only reasoning ($1 characters) with no final content.',
        ),
        array(
            're'  => 'Errore di connessione al servizio AI: (.+)$',
            'tpl' => 'AI service connection error: $1',
        ),
        array(
            're'  => 'Prezzo non in mappa per il modello (.+): usata la stima generica \(0\.50/1\.50 USD per 1M token\)\.',
            'tpl' => 'Price not mapped for model $1: using the generic estimate (0.50/1.50 USD per 1M tokens).',
        ),
        array(
            're'  => 'Provider personalizzato sconosciuto o eliminato \(([^)]+)\): scegli un altro provider per gli agenti che lo usavano\.',
            'tpl' => 'Unknown or deleted custom provider ($1): choose a different provider for the agents that were using it.',
        ),
        array(
            're'  => '\): scegli un altro provider per gli agenti che lo usavano\.',
            'tpl' => '): choose a different provider for the agents that were using it.',
        ),
        array(
            're'  => 'for provider personalizzato (.+)$',
            'tpl' => 'for custom provider $1',
        ),
        array(
            're'  => ': correggilo in Nexus News AI → Impostazioni → Provider AI\.',
            'tpl' => ': fix it in Nexus News AI → Settings → Provider AI.',
        ),
        array(
            're'  => ': inseriscila in Nexus News AI → Impostazioni → Provider AI\.',
            'tpl' => ': enter it in Nexus News AI → Settings → Provider AI.',
        ),
        array(
            're'  => ': impostala in Nexus News AI → Impostazioni → Provider AI\.',
            'tpl' => ': set it in Nexus News AI → Settings → Provider AI.',
        ),
        array(
            're'  => 'Errore di rete verso OpenRouter durante lo scambio del codice: (.+)$',
            'tpl' => 'Network error toward OpenRouter during the code exchange: $1',
        ),
        array(
            're'  => 'OpenRouter ha rifiutato lo scambio del codice \(HTTP (\d+): (.+)\)\. Riavvia il login da Impostazioni AI\.',
            'tpl' => 'OpenRouter rejected the code exchange (HTTP $1: $2). Restart the login from AI Settings.',
        ),
        array(
            're'  => 'OpenRouter ha rifiutato lo scambio del codice \(HTTP (\d+)\)\. Riavvia il login da Impostazioni AI\.',
            'tpl' => 'OpenRouter rejected the code exchange (HTTP $1). Restart the login from AI Settings.',
        ),
        array(
            're'  => 'Provider (.+) connesso via login web OAuth: chiave di provisioning salvata\.',
            'tpl' => 'Provider $1 connected via the web OAuth login: provisioning key saved.',
        ),
        array(
            're'  => 'Fonte tipo "(.+)" senza adapter disponibile: ignorata\.',
            'tpl' => 'Source of type "$1" with no adapter available: ignored.',
        ),
        array(
            're'  => 'YouTube: handle canale non riconosciuto da "(.+)"\.',
            'tpl' => 'YouTube: channel handle not recognized from "$1".',
        ),
        array(
            're'  => 'Telegram: canale non valido "(.+)"\.',
            'tpl' => 'Telegram: invalid channel "$1".',
        ),
        array(
            're'  => 'Reddit: subreddit non valido "(.+)"\.',
            'tpl' => 'Reddit: invalid subreddit "$1".',
        ),
        array(
            're'  => 'Search: provider "(.+)" non supportato\.',
            'tpl' => 'Search: unsupported provider "$1".',
        ),
        array(
            're'  => 'Search \((.+)\): nessun risultato per "(.+)"\.',
            'tpl' => 'Search ($1): no results for "$2".',
        ),
        array(
            're'  => 'MCP: specifica fonte non valida "(.+)"\.',
            'tpl' => 'MCP: invalid source specification "$1".',
        ),
        array(
            're'  => 'MCP ([^.]+)\.([^:]+): risposta vuota\.',
            'tpl' => 'MCP $1.$2: empty response.',
        ),
        array(
            're'  => 'Web: URL non consentito "(.+)"\.',
            'tpl' => 'Web: URL not allowed "$1".',
        ),
        array(
            're'  => 'Web: URL non valido "(.+)"\.',
            'tpl' => 'Web: invalid URL "$1".',
        ),
        array(
            're'  => 'Full-content: budget di (\d+) fetch per run esaurito: item restanti serviti con l\'estratto RSS\.',
            'tpl' => 'Full-content: budget of $1 fetches per run exhausted: remaining items served with the RSS excerpt.',
        ),
        array(
            're'  => 'Full-content: URL item non consentito \((.+)\): estratto RSS mantenuto\.',
            'tpl' => 'Full-content: item URL not allowed ($1): RSS excerpt kept.',
        ),
        array(
            're'  => 'Full-content: pagina non raggiungibile \((.+)\): estratto RSS mantenuto\.',
            'tpl' => 'Full-content: page unreachable ($1): RSS excerpt kept.',
        ),
        array(
            're'  => 'Full-content: risposta non è HTML testuale \((.+)\): estratto RSS mantenuto\.',
            'tpl' => 'Full-content: response is not textual HTML ($1): RSS excerpt kept.',
        ),
        array(
            're'  => 'Full-content: estrazione non valida da (.+): estratto RSS mantenuto\.',
            'tpl' => 'Full-content: invalid extraction from $1: RSS excerpt kept.',
        ),
        array(
            're'  => 'Fonte (.+) senza novita\' \(early-exit 6h\): skip\.',
            'tpl' => 'Source $1 has nothing new (early-exit 6h): skipping.',
        ),
        array(
            're'  => 'Fonte (.+) in cooldown 1h dopo (\d+) fallimenti consecutivi\.',
            'tpl' => 'Source $1 in 1h cooldown after $2 consecutive failures.',
        ),

        /* v1.1.11 (R4-7) — viste admin (redazioni/agents/settings/tools/jobs/wizard) */
        array(
            're'  => 'Vista "(.+)" non ancora disponibile \(admin/views/\)\.',
            'tpl' => 'View "$1" not yet available (admin/views/).',
        ),

        /* v1.1.11 (R4-8) — queue/publisher/readability/cron/social/native/newsletter:
         * messaggi compositi residui delle classi varie. Regex senza
         * delimitatori (consumata da new RegExp in i18n.js), template EN con
         * $1/$2/$3. Dal più specifico al più generico ('Eccezione:' in coda). */
        array(
            're'  => 'Retry job #(\d+): stato cambiato durante l\'operazione, retry annullato\.',
            'tpl' => 'Retry job #$1: state changed during the operation, retry cancelled.',
        ),
        array(
            're'  => 'Cancel job #(\d+): job già in esecuzione o stato cambiato, operazione annullata\.',
            'tpl' => 'Cancel job #$1: job already running or state changed, operation cancelled.',
        ),
        array(
            're'  => 'Reset dead job #(\d+): stato cambiato durante l\'operazione, reset annullato\.',
            'tpl' => 'Reset dead job #$1: state changed during the operation, reset cancelled.',
        ),
        array(
            're'  => 'requeue_stale: impossibile marcare dead il job #(\d+) \(riprossima alla prossima passata\)\.',
            'tpl' => 'requeue_stale: cannot mark job #$1 as dead (retrying on the next pass).',
        ),
        array(
            're'  => 'requeue_stale: impossibile rimettere in coda il job #(\d+) \(riprossima alla prossima passata\)\.',
            'tpl' => 'requeue_stale: cannot requeue job #$1 (retrying on the next pass).',
        ),
        array(
            're'  => 'Dead letter: (\d+) lavori bloccati \(> (\d+) min\) marcati come dead\.',
            'tpl' => 'Dead letter: $1 stuck jobs (> $2 min) marked as dead.',
        ),
        array(
            're'  => 'Budget tempo/memoria esaurito: (\d+) job claimati rimessi in coda\.',
            'tpl' => 'Time/memory budget exhausted: $1 claimed jobs requeued.',
        ),
        array(
            're'  => 'Manutenzione coda fallita: (.+)$',
            'tpl' => 'Queue maintenance failed: $1',
        ),
        array(
            're'  => 'Deattivazione coda: requeue_stale fallito: (.+)$',
            'tpl' => 'Queue deactivation: requeue_stale failed: $1',
        ),
        array(
            're'  => 'Traduzione ([a-z]+) articolo #(\d+): (.+)$',
            'tpl' => 'Translation $1 article #$2: $3',
        ),
        array(
            're'  => 'Readability: errore inatteso durante l\'estrazione \((.+)\)\.',
            'tpl' => 'Readability: unexpected error during extraction ($1).',
        ),
        array(
            're'  => 'Contenuto troppo corto: (\d+) caratteri di testo contro il minimo di (\d+)\.',
            'tpl' => 'Content too short: $1 text characters against the minimum of $2.',
        ),
        array(
            're'  => 'ok: titolo coerente col contenuto e (\d+) caratteri di testo \(minimo richiesto: (\d+)\)\.',
            'tpl' => 'ok: title consistent with content and $1 text characters (required minimum: $2).',
        ),
        array(
            're'  => 'Raccolta automatica schedulata \(intervallo (\d+)s\)\.',
            'tpl' => 'Automatic collection scheduled (interval $1s).',
        ),
        array(
            're'  => 'Raccolta automatica rischedulata con nuovo intervallo (\d+)s\.',
            'tpl' => 'Automatic collection rescheduled with new interval $1s.',
        ),
        array(
            're'  => 'Errore fatale durante cron tick: (.+)$',
            'tpl' => 'Fatal error during cron tick: $1',
        ),
        array(
            're'  => 'Errore durante cron tick: (.+)$',
            'tpl' => 'Error during cron tick: $1',
        ),
        array(
            're'  => 'Discord ha risposto con errore HTTP (\d+): (.+)$',
            'tpl' => 'Discord responded with HTTP error $1: $2',
        ),
        array(
            're'  => 'Discord ha risposto con errore HTTP (\d+)\.$',
            'tpl' => 'Discord responded with HTTP error $1.',
        ),
        array(
            're'  => 'Errore inatteso Discord: (.+)$',
            'tpl' => 'Unexpected Discord error: $1',
        ),
        array(
            're'  => 'Errore di connessione a Discord: (.+)$',
            'tpl' => 'Discord connection error: $1',
        ),
        array(
            're'  => 'LinkedIn non ha restituito l\'identificativo del post \(HTTP (\d+)\)\.',
            'tpl' => 'LinkedIn did not return the post identifier (HTTP $1).',
        ),
        array(
            're'  => 'Errore inatteso durante la pubblicazione su LinkedIn: (.+)$',
            'tpl' => 'Unexpected error while publishing to LinkedIn: $1',
        ),
        array(
            're'  => 'Errore di connessione a LinkedIn: (.+)$',
            'tpl' => 'LinkedIn connection error: $1',
        ),
        array(
            're'  => 'Risposta di Telegram non interpretabile \(HTTP (\d+)\)\.',
            'tpl' => 'Telegram response could not be parsed (HTTP $1).',
        ),
        array(
            're'  => 'Errore inatteso durante l\'invio a Telegram: (.+)$',
            'tpl' => 'Unexpected error while sending to Telegram: $1',
        ),
        array(
            're'  => 'Errore di connessione a Telegram: (.+)$',
            'tpl' => 'Telegram connection error: $1',
        ),
        array(
            're'  => 'Inviato in chat (.+)$',
            'tpl' => 'Sent to chat $1',
        ),
        array(
            're'  => 'Risposta X inattesa \(HTTP 201 senza data\.id\): (.+)$',
            'tpl' => 'Unexpected X response (HTTP 201 without data.id): $1',
        ),
        array(
            're'  => 'Errore inatteso invio X: (.+)$',
            'tpl' => 'Unexpected error sending to X: $1',
        ),
        array(
            're'  => 'Errore di rete verso X: (.+)$',
            'tpl' => 'Network error toward X: $1',
        ),
        array(
            're'  => 'Post #(\d+) non trovato, saltato\.',
            'tpl' => 'Post #$1 not found, skipped.',
        ),
        array(
            're'  => 'Eccezione: (.+)$',
            'tpl' => 'Exception: $1',
        ),

        /* v1.1.11 (R4-9) — chiusura residui: pattern senza gruppi per i frammenti
         * concatenati (prefissi/suffissi di messaggi compositi in includes/ e
         * admin.js); la catena i18n li applica in sequenza, max 4 giri. */
        array(
            're'  => 'vedi post ↗',
            'tpl' => 'view post ↗',
        ),
        array(
            're'  => 'Test non riuscito \(',
            'tpl' => 'Test failed (',
        ),
        array(
            're'  => '\)\. Esito dello strumento:',
            'tpl' => '). Tool output:',
        ),
        array(
            're'  => 'Provider AI non disponibile: ',
            'tpl' => 'AI provider unavailable: ',
        ),
        array(
            're'  => 'Risposta servita dalla prompt cache \(TTL 12h, modello ',
            'tpl' => 'Response served from the prompt cache (12h TTL, model ',
        ),
        array(
            're'  => '\): nessuna chiamata API effettuata\.',
            'tpl' => '): no API call performed.',
        ),
        array(
            're'  => 'Rate limit AI attivo per il provider ',
            'tpl' => 'AI rate limit active for provider ',
        ),
        array(
            're'  => ': riprova tra circa un minuto \(cooldown automatico\)\.',
            'tpl' => ': try again in about a minute (automatic cooldown).',
        ),
        array(
            're'  => ': cooldown automatico di 60s dopo 429 consecutivi\.',
            'tpl' => ': automatic 60s cooldown after 429 consecutive responses.',
        ),
        array(
            're'  => 'Provider primario non disponibile \(',
            'tpl' => 'Primary provider unavailable (',
        ),
        array(
            're'  => '\): ritenta con il provider di fallback\.',
            'tpl' => '): retrying with the fallback provider.',
        ),
        array(
            're'  => 'Chiave API non valida o non autorizzata \(HTTP ',
            'tpl' => 'Invalid or unauthorized API key (HTTP ',
        ),
        array(
            're'  => '\)\. Verifica ai_api_key nelle impostazioni del plugin\.',
            'tpl' => '). Check the ai_api_key setting in the plugin settings.',
        ),
        array(
            're'  => 'Il servizio AI ha risposto con errore HTTP ',
            'tpl' => 'The AI service responded with HTTP error ',
        ),
        array(
            're'  => 'Risposta del modello ',
            'tpl' => 'Response from model ',
        ),
        array(
            're'  => ' troncata per max_tokens \(finish_reason=length\): aument(o|a) max_tokens se il problema persiste\.',
            'tpl' => ' truncated for max_tokens (finish_reason=length): increase max_tokens if the problem persists.',
        ),
        array(
            're'  => 'Il modello AI \(',
            'tpl' => 'The AI model (',
        ),
        array(
            're'  => '\) ha restituito una risposta vuota\.',
            'tpl' => ') returned an empty response.',
        ),
        array(
            're'  => 'Errore di connessione al servizio AI: ',
            'tpl' => 'Connection error to the AI service: ',
        ),
        array(
            're'  => 'Prezzo non in mappa per il modello ',
            'tpl' => 'Price not found in map for model ',
        ),
        array(
            're'  => ': usata la stima generica \(0\.50/1\.50 USD per 1M token\)\.',
            'tpl' => ': using the generic estimate (0.50/1.50 USD per 1M tokens).',
        ),
        array(
            're'  => 'revisione articolo #',
            'tpl' => 'article revision #',
        ),
        array(
            're'  => ' Nota del capo redattore: ',
            'tpl' => ' Note from the editor-in-chief: ',
        ),
        array(
            're'  => 'Raccolta automatica schedulata \(intervallo ',
            'tpl' => 'Automatic collection scheduled (interval ',
        ),
        array(
            're'  => 'Raccolta automatica rischedulata con nuovo intervallo ',
            'tpl' => 'Automatic collection rescheduled with new interval ',
        ),
        array(
            're'  => 'Errore durante cron tick: ',
            'tpl' => 'Error during cron tick: ',
        ),
        array(
            're'  => 'Errore fatale durante cron tick: ',
            'tpl' => 'Fatal error during cron tick: ',
        ),
        array(
            're'  => 'generazione AI \(modello ',
            'tpl' => 'AI generation (model ',
        ),
        array(
            're'  => 'generazione AI fallita \(',
            'tpl' => 'AI generation failed (',
        ),
        array(
            're'  => 'image_url scartata dal gate qualità: larghezza ',
            'tpl' => 'image_url rejected by the quality gate: width ',
        ),
        array(
            're'  => 'px sotto il minimo di ',
            'tpl' => 'px below the minimum of ',
        ),
        array(
            're'  => 'image_url non utilizzabile \(',
            'tpl' => 'image_url not usable (',
        ),
        array(
            're'  => 'pixabay scartata dal gate qualità: larghezza reale ',
            'tpl' => 'pixabay rejected by the quality gate: actual width ',
        ),
        array(
            're'  => 'immagine Pixabay \(query "',
            'tpl' => 'Pixabay image (query "',
        ),
        array(
            're'  => 'pixabay non utilizzabile \(',
            'tpl' => 'pixabay not usable (',
        ),
        array(
            're'  => 'Impossibile salvare la featured image per il post #',
            'tpl' => 'Unable to save the featured image for post #',
        ),
        array(
            're'  => 'set_post_thumbnail fallito per il post #',
            'tpl' => 'set_post_thumbnail failed for post #',
        ),
        array(
            're'  => 'errore di connessione al servizio immagini: ',
            'tpl' => 'connection error to the image service: ',
        ),
        array(
            're'  => 'il servizio immagini ha risposto con errore HTTP ',
            'tpl' => 'the image service responded with HTTP error ',
        ),
        array(
            're'  => 'download immagine generata fallito: ',
            'tpl' => 'generated image download failed: ',
        ),
        array(
            're'  => 'errore di download: ',
            'tpl' => 'download error: ',
        ),
        array(
            're'  => 'download con errore HTTP ',
            'tpl' => 'download failed with HTTP error ',
        ),
        array(
            're'  => 'pixabay: zero risultati utilizzabili per la query "',
            'tpl' => 'pixabay: no usable results for the query "',
        ),
        array(
            're'  => '" \(nessun esito o tutti sotto il gate di ',
            'tpl' => '" (no results or all below the gate of ',
        ),
        array(
            're'  => 'pixabay: tutti i risultati per la query "',
            'tpl' => 'pixabay: all results for the query "',
        ),
        array(
            're'  => '" sono già stati usati',
            'tpl' => '" have already been used',
        ),
        array(
            're'  => 'pixabay: errore di connessione all\'API \(',
            'tpl' => 'pixabay: API connection error (',
        ),
        array(
            're'  => 'pixabay: API ha risposto con errore HTTP ',
            'tpl' => 'pixabay: API responded with HTTP error ',
        ),
        array(
            're'  => 'Errore di rete verso OpenRouter durante lo scambio del codice: ',
            'tpl' => 'Network error to OpenRouter during the code exchange: ',
        ),
        array(
            're'  => 'OpenRouter ha rifiutato lo scambio del codice \(HTTP ',
            'tpl' => 'OpenRouter rejected the code exchange (HTTP ',
        ),
        array(
            're'  => '\)\. Riavvia il login da Impostazioni AI\.',
            'tpl' => '). Restart the login from AI Settings.',
        ),
        array(
            're'  => 'Manutenzione coda fallita: ',
            'tpl' => 'Queue maintenance failed: ',
        ),
        array(
            're'  => 'Deattivazione coda: requeue_stale fallito: ',
            'tpl' => 'Queue deactivation: requeue_stale failed: ',
        ),
        array(
            're'  => 'Readability: errore inatteso durante l\'estrazione \(',
            'tpl' => 'Readability: unexpected error during extraction (',
        ),
        array(
            're'  => 'Contenuto troppo corto: ',
            'tpl' => 'Content too short: ',
        ),
        array(
            're'  => ' caratteri di testo contro il minimo di ',
            'tpl' => ' characters of text against the minimum of ',
        ),
        array(
            're'  => 'ok: titolo coerente col contenuto e ',
            'tpl' => 'ok: title consistent with the content and ',
        ),
        array(
            're'  => 'Errore inatteso Discord: ',
            'tpl' => 'Unexpected Discord error: ',
        ),
        array(
            're'  => 'Errore di connessione a Discord: ',
            'tpl' => 'Connection error to Discord: ',
        ),
        array(
            're'  => 'Discord ha risposto con errore HTTP ',
            'tpl' => 'Discord responded with HTTP error ',
        ),
        array(
            're'  => 'Errore di connessione a LinkedIn: ',
            'tpl' => 'Connection error to LinkedIn: ',
        ),
        array(
            're'  => 'LinkedIn non ha restituito l\'identificativo del post \(HTTP ',
            'tpl' => 'LinkedIn did not return the post identifier (HTTP ',
        ),
        array(
            're'  => 'Errore inatteso durante la pubblicazione su LinkedIn: ',
            'tpl' => 'Unexpected error while publishing to LinkedIn: ',
        ),
        array(
            're'  => 'Errore inatteso durante l\'invio a Telegram: ',
            'tpl' => 'Unexpected error while sending to Telegram: ',
        ),
        array(
            're'  => 'Errore di connessione a Telegram: ',
            'tpl' => 'Connection error to Telegram: ',
        ),
        array(
            're'  => 'Risposta di Telegram non interpretabile \(HTTP ',
            'tpl' => 'Telegram response not interpretable (HTTP ',
        ),
        array(
            're'  => 'Errore inatteso invio X: ',
            'tpl' => 'Unexpected error sending to X: ',
        ),
        array(
            're'  => 'Errore di rete verso X: ',
            'tpl' => 'Network error to X: ',
        ),
        array(
            're'  => 'Risposta X inattesa \(HTTP 201 senza data\.id\): ',
            'tpl' => 'Unexpected X response (HTTP 201 without data.id): ',
        ),
        array(
            're'  => 'Provider di ricerca web non riconosciuto \("',
            'tpl' => 'Web search provider not recognized ("',
        ),
        array(
            're'  => '"\): valori ammessi tavily, serper, brave, none\.',
            'tpl' => '"): allowed values are tavily, serper, brave, none.',
        ),
        array(
            're'  => 'URL non valido o non ammesso \(solo http/https\): ',
            'tpl' => 'Invalid or disallowed URL (http/https only): ',
        ),
        array(
            're'  => '" non presente tra i server configurati \(impostazioni globali o strumenti personali dell\'agente\)\.',
            'tpl' => '" not among the configured servers (global settings or the agent personal tools).',
        ),
        array(
            're'  => 'URL del server MCP "',
            'tpl' => 'MCP server URL "',
        ),
        array(
            're'  => '" non valido o non ammesso \(solo http/https\): ',
            'tpl' => '" invalid or disallowed (http/https only): ',
        ),
        array(
            're'  => 'Il tool MCP "',
            'tpl' => 'The MCP tool "',
        ),
        array(
            're'  => '" ha segnalato un errore',
            'tpl' => '" reported an error',
        ),
        array(
            're'  => 'Errore interno del client MCP: ',
            'tpl' => 'Internal MCP client error: ',
        ),
        array(
            're'  => 'Inizializzazione MCP fallita: ',
            'tpl' => 'MCP initialization failed: ',
        ),
        array(
            're'  => 'Errore di connessione al server MCP: ',
            'tpl' => 'Connection error to the MCP server: ',
        ),
        array(
            're'  => 'il server MCP ha risposto con errore HTTP ',
            'tpl' => 'the MCP server responded with HTTP error ',
        ),
        array(
            're'  => 'Il modello non ha concluso entro il numero massimo di giri \(',
            'tpl' => 'The model did not finish within the maximum number of rounds (',
        ),
        array(
            're'  => '\) del ciclo strumenti\.',
            'tpl' => ') of the tools loop.',
        ),
        array(
            're'  => 'Errore interno del ciclo strumenti: ',
            'tpl' => 'Internal error in the tools loop: ',
        ),
        array(
            're'  => 'Tempo massimo per il ciclo strumenti raggiunto \(',
            'tpl' => 'Maximum time for the tools loop reached (',
        ),
        array(
            're'  => ' secondi\): esecuzione interrotta entro ',
            'tpl' => ' seconds): execution stopped within ',
        ),
        array(
            're'  => 'il server ha risposto con errore HTTP ',
            'tpl' => 'the server responded with HTTP error ',
        ),
        array(
            're'  => 'il servizio ha risposto con errore HTTP ',
            'tpl' => 'the service responded with HTTP error ',
        ),
        array(
            're'  => 'Eccezione durante la traduzione: ',
            'tpl' => 'Exception during translation: ',
        ),
        array(
            're'  => 'Traduzione saltata: budget AI esaurito \(articolo #',
            'tpl' => 'Translation skipped: AI budget exhausted (article #',
        ),
        /* ---- v1.1.13.1 (Q3): riepilogo wizard "completato" — correttivi in
         * coda per i compositi runtime di admin.js (nxaT, toast di fine wizard).
         * I pattern dedicati "Wizard completato: N agente/i creato/i." (v1.1.11
         * R3) non arrivano mai a firare: i generici "([\d.,]+) agenti/agente"
         * (v1.1.6, posizione precedente) traducono prima il solo nucleo
         * "N agenti/agente" e la frase resta mista IT/EN ("Wizard completato:
         * 3 agents creati."). Qui si intercetta la forma GIÀ parzialmente
         * tradotta dai generici (append-only: non si riordinano le voci
         * esistenti). Verificato in node: catena completa => EN intero; le
         * frasi già in EN non matchano. ---- */
        array(
            're'  => 'Wizard completato: 1 agent creato\.',
            'tpl' => 'Wizard completed: 1 agent created.',
        ),
        array(
            're'  => 'Wizard completato: (\d+) agents creati\.',
            'tpl' => 'Wizard completed: $1 agents created.',
        ),
        /* ---- v1.1.18 «Recupero e Segreti» — messaggi variabili dei fix audit (FIX-1..FIX-10) ---- */
        array(
            're'  => 'Reaper: chiuse (\d+) run fantasma \(stato "running" da oltre (\d+) min: crash/timeout del processo, telemetria ripristinata\)\.',
            'tpl' => 'Reaper: closed $1 ghost runs (state "running" for over $2 min: process crash/timeout, telemetry restored).',
        ),
        array(
            're'  => 'Reaper run fantasma fallito: ',
            'tpl' => 'Reaper ghost-run cleanup failed: ',
        ),
        array(
            're'  => 'Agente "([^"]+)" riattivato automaticamente: l\'errore era stato registrato alle ',
            'tpl' => 'Agent "$1" automatically reactivated: the error had been recorded at ',
        ),
        array(
            're'  => 'Riscrittura articolo #(\d+) interrotta: risposta AI troncata per max_tokens \(contenuto incompleto non salvato, articolo non marcato completo\)\.',
            'tpl' => 'Rewrite of article #$1 stopped: the AI response was cut off by max_tokens (incomplete content not saved, article not marked complete).',
        ),
        array(
            're'  => 'Nexus Wire "(.*?)" non avviato: un altro Wire è già in corso sullo stesso tema \(lock attivo\)\.',
            'tpl' => 'Nexus Wire "$1" not started: another Wire is already running on the same topic (active lock).',
        ),
        array(
            're'  => 'Nexus Wire "(.*?)" fallito: risposta del direttore troncata per max_tokens \(articolo incompleto non salvato\)\.',
            'tpl' => 'Nexus Wire "$1" failed: the chief editor response was truncated by max_tokens (incomplete article not saved).',
        ),
        array(
            're'  => 'Nexus Wire avviato sul tema "(.*?)" con (\d+) agenti in redazione\.',
            'tpl' => 'Nexus Wire started on topic "$1" with $2 agents in the newsroom.',
        ),
        array(
            're'  => 'Nexus Wire completato sul tema "(.*?)": articolo #(\d+) in coda di approvazione \(redazione: (.*?)\)\.',
            'tpl' => 'Nexus Wire completed on topic "$1": article #$2 in the approval queue (newsroom: $3).',
        ),
        array(
            're'  => '(.*?): angolo proposto \((\d+) caratteri\)\.',
            'tpl' => '$1: angle proposed ($2 characters).',
        ),
        array(
            're'  => 'Articolo fuso da (\d+) angoli \((\d+) parole\)\.',
            'tpl' => 'Article merged from $1 angles ($2 words).',
        ),
        array(
            're'  => 'Articolo #(\d+) salvato in coda di approvazione\.',
            'tpl' => 'Article #$1 saved to the approval queue.',
        ),
        array(
            're'  => 'Risposta troncata per max_tokens \(articolo incompleto non salvato\)\.',
            'tpl' => 'Response truncated by max_tokens (incomplete article not saved).',
        ),
        array(
            're'  => 'Social saltati: post WordPress non pubblicato \(stato: (.*?)\)\.',
            'tpl' => 'Social skipped: WordPress post not published (status: $1).',
        ),
        array(
            're'  => 'Richieste troppo frequenti su questa operazione: riprova tra (\d+) secondi\.',
            'tpl' => 'Requests too frequent for this operation: try again in $1 seconds.',
        ),
        array(
            're'  => 'Budget di tempo AI esaurito \((\d+) secondi\): ulteriori tentativi di retry non effettuati per questa chiamata\.',
            'tpl' => 'AI time budget exhausted ($1 seconds): no further retries made for this call.',
        ),
        array(
            're'  => 'Tentativo extra ×1\.5 \(max_tokens (\d+)\) completato: risposta sostituita con una più lunga \(da (\d+) a (\d+) caratteri\)\.',
            'tpl' => 'Extra ×1.5 attempt (max_tokens $1) completed: response replaced with a longer one (from $2 to $3 characters).',
        ),
        array(
            're'  => 'Tentativo extra ×1\.5 \(max_tokens (\d+)\) non ha prodotto una risposta migliore: mantenuta la risposta precedente \((\d+) caratteri\)\.',
            'tpl' => 'Extra ×1.5 attempt (max_tokens $1) did not produce a better response: kept the previous response ($2 characters).',
        ),
        array(
            're'  => 'Tentativo extra ×1\.5 non effettuato: il tentativo precedente ha già usato (\d+) max_tokens \(cap 8000\): un aumento non produrrebbe più contenuto\.',
            'tpl' => 'Extra ×1.5 attempt not made: the previous attempt already used $1 max_tokens (cap 8000): an increase would not produce more content.',
        ),
        array(
            're'  => 'Digest: giro (\d+) parziale — il resto degli iscritti parte al prossimo giro \(inviati finora: (\d+), falliti: (\d+)\)\.',
            'tpl' => 'Digest: round $1 partial — the remaining subscribers will be sent in the next round (sent so far: $2, failed: $3).',
        ),
        array(
            're'  => 'Digest: invio fallito a ',
            'tpl' => 'Digest: sending failed to ',
        ),
        array(
            're'  => ' — invio ripartito su (\d+) giri',
            'tpl' => ' — sending split across $1 rounds',
        ),
        array(
            're'  => ' — invii falliti: (\d+)',
            'tpl' => ' — failed sends: $1',
        ),
    );
}
