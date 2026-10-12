<?php
/**
 * Nexus I18n — dizionario base IT => EN (v1.0.5).
 *
 * Espone nexus_i18n_default_dict(): array di traduzioni a match esatto per le
 * etichette e i messaggi principali dell'admin. Le voci extra vivono in
 * includes/class-nexus-i18n-dict-extra.php (caricato da Nexus_I18n::dictionary()).
 *
 * Regole: chiave = stringa ESATTA nel codice (punteggiatura e apostrofi tipografici
 * compresi); placeholder %s / %d invariati; niente HTML o emoji.
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dizionario base dell'admin (IT => EN).
 *
 * @return array
 */
function nexus_i18n_default_dict() {
        return array(
                /* ---- Menu e pagine ---- */
                'Dashboard' => 'Dashboard',
                'Agenti' => 'Agents',
                'Coda Approvazioni' => 'Approval Queue',
                'Coda Lavori' => 'Jobs Queue',
                'Attività' => 'Activity',
                'Nexus Wire' => 'Nexus Wire',
                'Analytics' => 'Analytics',
                'Strumenti' => 'Tools',
                'Impostazioni' => 'Settings',
                'Impostazioni generali' => 'General settings',

                /* ---- Azioni comuni ---- */
                'Salva' => 'Save',
                'Salva impostazioni' => 'Save settings',
                'Impostazioni salvate.' => 'Settings saved.',
                'Processa ora' => 'Process now',
                'Pulizia' => 'Cleanup',
                'Riprova' => 'Retry',
                'Annulla' => 'Cancel',
                'Ricarica' => 'Reload',
                'Esporta' => 'Export',
                'Importa' => 'Import',
                'Genera' => 'Generate',
                'Copia' => 'Copy',
                'Cerca' => 'Search',
                'Filtra' => 'Filter',
                'Tutte' => 'All',
                'Tutti' => 'All',
                'Scarica JSON' => 'Download JSON',
                'Genera nuova chiave' => 'Generate new key',
                'Scarica' => 'Download',
                'Aggiorna' => 'Update',
                'Chiudi' => 'Close',
                'Vedi tutto' => 'View all',
                'Dettagli' => 'Details',
                'Esegui pipeline completa' => 'Run full pipeline',
                'Raccogli notizie ora' => 'Collect news now',
                'Vedi magazine' => 'View magazine',
                'Metti in pausa' => 'Pause',
                'Riattiva' => 'Reactivate',
                'Esegui ora' => 'Run now',

                /* ---- Stati e badge ---- */
                'In attesa' => 'Pending',
                'In esecuzione' => 'Running',
                'Completato' => 'Completed',
                'Completati' => 'Completed',
                'Fallito' => 'Failed',
                'Falliti' => 'Failed',
                'Annullato' => 'Cancelled',
                'Pubblicato' => 'Published',
                'Pubblicati' => 'Published',
                'Bozza' => 'Draft',
                'Bozze' => 'Drafts',
                'Approvato' => 'Approved',
                'Rifiutato' => 'Rejected',
                'Attivo' => 'Active',
                'In pausa' => 'Paused',
                'Errore' => 'Error',
                'Creati' => 'Created',
                'Articoli creati' => 'Articles created',
                'Oggi' => 'Today',

                /* ---- Colonne tabelle ---- */
                'Titolo' => 'Title',
                'Categoria' => 'Category',
                'Agente' => 'Agent',
                'Stato' => 'Status',
                'Qualità' => 'Quality',
                'Qualità media' => 'Average quality',
                'Viste' => 'Views',
                'Visualizzazioni' => 'Views',
                'Data' => 'Date',
                'Azioni' => 'Actions',
                'Lavoro' => 'Job',
                'Priorità' => 'Priority',
                'Tentativi' => 'Attempts',
                'Programmatore' => 'Scheduled',
                'Iniziato' => 'Started',
                'Completato il' => 'Completed at',
                'Errore (ultime esecuzioni)' => 'Error (last runs)',
                'Piattaforma' => 'Platform',
                'Reazioni' => 'Reactions',

                /* ---- Dashboard ---- */
                'Agenti attivi' => 'Active agents',
                'Uso AI (mese corrente)' => 'AI usage (current month)',
                'Caricamento uso AI…' => 'Loading AI usage…',
                'Feed attività' => 'Activity feed',
                'Caricamento attività…' => 'Loading activity…',
                'Stato agenti' => 'Agents status',
                'Caricamento agenti…' => 'Loading agents…',
                'Il lavoro degli agenti apparirà qui.' => 'Agents’ work will appear here.',
                'Nessun consumo AI questo mese' => 'No AI usage this month',
                'Le chiamate AI degli agenti appariranno qui con il tracking v1.0.3.' => 'Agents’ AI calls will appear here with v1.0.3 tracking.',
                'Pipeline completata' => 'Pipeline completed',
                'Pipeline non completata' => 'Pipeline not completed',
                'Redazione al lavoro' => 'Newsroom at work',
                'Raccolta non completata' => 'Collection not completed',
                'Attiva il plugin per creare i 7 agenti predefiniti.' => 'Activate the plugin to create the 7 default agents.',
                'Impossibile caricare gli agenti' => 'Unable to load agents',
                'Agente riattivato.' => 'Agent reactivated.',
                'Agente messo in pausa.' => 'Agent paused.',
                'Stato aggiornato' => 'Status updated',
                'Impossibile aggiornare lo stato' => 'Unable to update status',
                'Modello aggiornato' => 'Model updated',
                'Modello dedicato rimosso: verrà usato il modello globale.' => 'Dedicated model removed: the global model will be used.',
                'Salvataggio modello non riuscito' => 'Model save failed',
                'Ultimo run: %s' => 'Last run: %s',
                'mai eseguito' => 'never run',

                /* ---- Coda approvazioni / lavori ---- */
                'Nessun articolo in coda' => 'No articles in queue',
                'Nessun articolo con lo stato selezionato.' => 'No articles with the selected status.',
                'Esegui la raccolta per generare nuovi articoli.' => 'Run a collection to generate new articles.',
                'Impossibile caricare la coda' => 'Unable to load the queue',
                'Nessun lavoro in coda' => 'No jobs in queue',
                'Coda aggiornata' => 'Queue updated',
                'Approvazione non riuscita' => 'Approval failed',
                'Rifiuto non riuscito' => 'Rejection failed',
                'Articolo pubblicato' => 'Article published',
                'Articolo non trovato' => 'Article not found',
                'Potrebbe essere stato eliminato.' => 'It may have been deleted.',
                'Impossibile caricare l’articolo' => 'Unable to load the article',

                /* ---- Attività e Wire ---- */
                'Nessuna attività registrata' => 'No activity recorded',
                'L’attività degli agenti apparirà qui in tempo reale.' => 'Agents’ activity will appear here in real time.',
                'Impossibile caricare l’attività' => 'Unable to load activity',
                'Nessuna esecuzione del Wire' => 'No Wire runs yet',
                'Avvia il Wire per vedere qui le idee degli agenti.' => 'Start the Wire to see agents’ ideas here.',

                /* ---- Analytics ---- */
                'Periodo' => 'Period',
                'Ultimi 7 giorni' => 'Last 7 days',
                'Ultimi 14 giorni' => 'Last 14 days',
                'Ultimi 30 giorni' => 'Last 30 days',
                'Ultimi 90 giorni' => 'Last 90 days',
                'Errore nel caricamento analytics' => 'Analytics load error',
                'Chiamate AI' => 'AI calls',
                'Costo AI' => 'AI cost',
                'Serie giornaliera' => 'Daily series',
                'Pubblicazioni per piattaforma' => 'Publications by platform',
                'Attività per agente' => 'Activity by agent',
                'Articoli più visti' => 'Most viewed articles',
                'Iscritti newsletter' => 'Newsletter subscribers',
                'Confermati' => 'Confirmed',
                'Disiscritti' => 'Unsubscribed',
                'Nuovi nel periodo' => 'New in period',
                'Nessun dato nel periodo' => 'No data in period',

                /* ---- Distribuzione e canali ---- */
                'Blog (WordPress)' => 'Blog (WordPress)',
                'Twitter / X' => 'Twitter / X',
                'Facebook' => 'Facebook',
                'LinkedIn' => 'LinkedIn',
                'Instagram' => 'Instagram',
                'Telegram' => 'Telegram',
                'Discord' => 'Discord',
        );
}
