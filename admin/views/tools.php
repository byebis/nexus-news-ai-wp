<?php
/**
 * Vista Admin: "Strumenti" (pagina "tools") — v1.0.5, restyle v1.0.6.
 *
 * SELF-CONTAINED (contratto CONTRATTO-v1.0.5.md §2 T-02): markup + <style> inline
 * (SOLO classi prefisso .nxa-tools-, nessun tocco a admin/css/nexus-admin.css) e
 * <script> IIFE ES2019 inline (nessun tocco ad assets/js/admin.js). La sezione 1
 * (informazioni di sistema) è renderizzata in PHP puro con esc_html; le sezioni
 * 2-4 consumano le REST admin di nexus/v1.
 *
 * CONTRATTO v1.0.6 ("UI chiara & curata"): lo <style> inline consuma SOLO i
 * token del tema (var(--nxa-*) su :root, override dark su body.nexus-theme-dark)
 * senza colori esadecimali fissi: la vista segue il tema chiaro di default e lo
 * scuro opzionale senza modifiche. Le informazioni di sistema usano una griglia
 * di definizione (.nxa-tools-defs/.nxa-tools-def); i pulsanti usano .nxa-btn /
 * .nxa-btn-primary del foglio condiviso. Nuovo in 1.0.6: bottone #nxa-tools-key-copy
 * (IIFE separata in fondo: il blocco JS preesistente è intatto al byte).
 *
 * CONTRATTO v1.0.7 ("Impostazioni & Agenti Pro"): nuova card "Diagnostica AI"
 * (SEZIONE 1b, tra informazioni di sistema ed Esporta): righe informative
 * server-side da Nexus_DB::get_settings() (URL base API, modello globale,
 * modello economico se presente, stato chiave come booleano — la chiave non è
 * MAI stampata, nemmeno parzialmente) + bottone #nxa-tools-ai-test con IIFE
 * separata aggiuntiva (i blocchi JS preesistenti restano intatti al byte) che
 * chiama GET {restUrl}/ai/models (cache transient 12 ore lato server).
 *
 * CONTRATTO REST (rotte definite nel §2 T-03, namespace nexus/v1, perm admin):
 * - GET  {restUrl}/export        → { version, exported_at, site_url, settings, agents }
 *                                  ATTENZIONE: il file esportato contiene segreti
 *                                  (chiavi API/token): conservalo in modo sicuro.
 * - POST {restUrl}/import        body: JSON prodotto da GET /export
 *                                  → { settings_updated, agents_updated,
 *                                      agents_inserted, errors: [] }
 * - POST {restUrl}/tools/api-key → { api_key } (la chiave precedente diventa invalida)
 * - GET  {restUrl}/ai/models     → { models:[id...], base_url, cached, count,
 *                                  fetched_at } (v1.0.7; errori: 400 key_missing,
 *                                  502 con messaggio onesto dal provider)
 * Autenticazione: header X-WP-Nonce = NexusAdminData.nonce (localizzato lato admin).
 *
 * CONTRATTO DOM (selettori fissi consumati dallo script inline in fondo al file):
 * - #nxa-tools-export         bottone "Scarica JSON" (sezione Esporta).
 * - #nxa-tools-import-file    input file accept=".json,application/json".
 * - #nxa-tools-import         bottone "Importa".
 * - #nxa-tools-import-result  box esiti dell'importazione.
 * - #nxa-tools-key            input readonly con la chiave API pubblica corrente.
 * - #nxa-tools-keygen         bottone "Genera nuova chiave" (con window.confirm).
 * - #nxa-tools-key-copy       bottone "Copia" (v1.0.6): copia #nxa-tools-key negli
 *                             appunti; stato "Copiata" temporaneo sul bottone.
 * - #nxa-tools-ai-test        bottone "Testa provider AI" (v1.0.7, Diagnostica AI).
 * - #nxa-tools-ai-result      box esito del test (aria-live="polite"; classi
 *                             nxa-tools-ai-result + modificatore --ok / --err).
 * - Toast: .nxa-tools-toast (--success / --error) su body, rimosso dopo 4 secondi.
 *
 * NOTE: la sezione 1 usa SOLO PHP (nessun fetch): versione plugin/WP/PHP, locale,
 * multisite, memory_limit, max_execution_time, lock WP-Cron, stato tabelle con
 * conteggio righe, eventi WP-Cron con prossima esecuzione, stat coda lavori.
 *
 * @package Nexus_News_AI
 * @since   1.0.5
 */

defined( 'ABSPATH' ) || exit;

$settings = class_exists( 'Nexus_DB' ) ? Nexus_DB::get_settings() : array();

/* Dati della sezione 1 con guardie: degradazione grazia, mai errori fatali. */
$nexus_tables = array();
$job_stats    = array();
if ( class_exists( 'Nexus_DB' ) ) {
        if ( method_exists( 'Nexus_DB', 'tables' ) ) {
                $nexus_tables = (array) Nexus_DB::tables();
        }
        if ( method_exists( 'Nexus_DB', 'job_stats' ) ) {
                $job_stats = (array) Nexus_DB::job_stats();
        }
}

/* API pubblica: stato e chiave corrente (sezione 4). */
$api_enabled = ! empty( $settings['enable_public_api'] );
$api_key     = isset( $settings['public_api_key'] ) ? (string) $settings['public_api_key'] : '';

/* Diagnostica AI (v1.0.7): configurazione AI corrente. La chiave non viene MAI
   esposta (nemmeno parzialmente): solo un booleano configurata/manca. */
$ai_base   = isset( $settings['ai_base_url'] ) ? trim( (string) $settings['ai_base_url'] ) : '';
$ai_model  = isset( $settings['ai_model'] ) ? trim( (string) $settings['ai_model'] ) : '';
$ai_cheap  = isset( $settings['ai_model_cheap'] ) ? trim( (string) $settings['ai_model_cheap'] ) : '';
$ai_key_ok = isset( $settings['ai_api_key'] ) && '' !== trim( (string) $settings['ai_api_key'] );

/* Riga ambiente: ini_get può ritornare false o '' (mostro un trattino). */
$mem_raw   = ini_get( 'memory_limit' );
$exe_raw   = ini_get( 'max_execution_time' );
$mem_val   = ( is_string( $mem_raw ) && '' !== $mem_raw ) ? $mem_raw : '—';
$exe_val   = ( is_string( $exe_raw ) && '' !== $exe_raw ) ? $exe_raw : '—';
$cron_lock = defined( 'WP_CRON_LOCK_TIMEOUT' ) ? (string) WP_CRON_LOCK_TIMEOUT : '—';

/* Righe "Ambiente" (etichetta, valore). */
$sys_rows = array(
        array( 'Versione plugin', NEXUS_VERSION ),
        array( 'Versione WordPress', get_bloginfo( 'version' ) ),
        array( 'Versione PHP', PHP_VERSION ),
        array( 'Locale del sito', get_locale() ),
        array( 'Multisite', is_multisite() ? 'Sì' : 'No' ),
        array( 'Limite memoria (memory_limit)', $mem_val ),
        array( 'Tempo massimo di esecuzione (max_execution_time)', $exe_val ),
        array( 'Lock WP-Cron (WP_CRON_LOCK_TIMEOUT)', $cron_lock ),
);

/* Righe "Tabelle database": esistenza + conteggio righe. */
global $wpdb;

$table_rows = array();
foreach ( $nexus_tables as $tname ) {
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tname ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( $exists ) {
                $count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $tname ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
                $table_rows[] = array( $tname, 'on', 'Presente — ' . $count . ' righe' );
        } else {
                $table_rows[] = array( $tname, 'off', 'Assente' );
        }
}

/* Righe "Eventi WP-Cron": prossima esecuzione formattata o trattino. */
$cron_events = array( 'nexus_cron_tick', 'nexus_queue_runner', 'nexus_weekly_digest', 'nexus_ping_article' );
$cron_rows   = array();
foreach ( $cron_events as $evt ) {
        $next_ts = wp_next_scheduled( $evt );
        $cron_rows[] = array( $evt, $next_ts ? date_i18n( 'd/m/Y H:i', $next_ts ) : '—' );
}

/* Righe "Coda lavori" (Nexus_DB::job_stats()). */
$queue_rows = array(
        array( 'In attesa', isset( $job_stats['pending'] ) ? (int) $job_stats['pending'] : 0 ),
        array( 'In esecuzione', isset( $job_stats['running'] ) ? (int) $job_stats['running'] : 0 ),
        array( 'Completati', isset( $job_stats['complete'] ) ? (int) $job_stats['complete'] : 0 ),
        array( 'Falliti', isset( $job_stats['failed'] ) ? (int) $job_stats['failed'] : 0 ),
        array( 'Totale', isset( $job_stats['total'] ) ? (int) $job_stats['total'] : 0 ),
);
?>
<div class="wrap nexus-tools nxa-tools" data-page="tools">

        <h1><?php esc_html_e( 'Strumenti', 'nexus-news-ai' ); ?></h1>

        <p class="nxa-tools-sub"><?php esc_html_e( "Diagnostica del sistema, esportazione e importazione della configurazione, gestione dell'API pubblica.", 'nexus-news-ai' ); ?></p>

        <!-- SEZIONE 1: informazioni di sistema (PHP puro, esc_html su tutto). -->
        <div class="nxa-tools-section">
                <h2 class="nxa-tools-section-title"><?php esc_html_e( 'Informazioni di sistema', 'nexus-news-ai' ); ?></h2>

                <h3 class="nxa-tools-group-title"><?php esc_html_e( 'Ambiente', 'nexus-news-ai' ); ?></h3>
                <dl class="nxa-tools-defs">
                        <?php foreach ( $sys_rows as $sys_row ) : ?>
                                <div class="nxa-tools-def">
                                        <dt><?php echo esc_html( $sys_row[0] ); ?></dt>
                                        <dd><code><?php echo esc_html( $sys_row[1] ); ?></code></dd>
                                </div>
                        <?php endforeach; ?>
                </dl>

                <h3 class="nxa-tools-group-title"><?php esc_html_e( 'Tabelle database', 'nexus-news-ai' ); ?></h3>
                <div class="nxa-tools-tablewrap">
                        <table class="widefat striped nxa-tools-table">
                                <thead>
                                        <tr>
                                                <th><?php esc_html_e( 'Tabella', 'nexus-news-ai' ); ?></th>
                                                <th><?php esc_html_e( 'Stato', 'nexus-news-ai' ); ?></th>
                                        </tr>
                                </thead>
                                <tbody>
                                <?php if ( $table_rows ) : ?>
                                        <?php foreach ( $table_rows as $table_row ) : ?>
                                                <tr>
                                                        <td><code><?php echo esc_html( $table_row[0] ); ?></code></td>
                                                        <td><span class="nxa-tools-badge nxa-tools-badge--<?php echo esc_attr( $table_row[1] ); ?>"><?php echo esc_html( $table_row[2] ); ?></span></td>
                                                </tr>
                                        <?php endforeach; ?>
                                <?php else : ?>
                                        <tr>
                                                <td colspan="2"><?php esc_html_e( 'Nexus_DB non disponibile: stato tabelle non verificabile.', 'nexus-news-ai' ); ?></td>
                                        </tr>
                                <?php endif; ?>
                                </tbody>
                        </table>
                </div>

                <h3 class="nxa-tools-group-title"><?php esc_html_e( 'Eventi WP-Cron', 'nexus-news-ai' ); ?></h3>
                <div class="nxa-tools-tablewrap">
                        <table class="widefat striped nxa-tools-table">
                                <thead>
                                        <tr>
                                                <th><?php esc_html_e( 'Evento', 'nexus-news-ai' ); ?></th>
                                                <th><?php esc_html_e( 'Prossima esecuzione', 'nexus-news-ai' ); ?></th>
                                        </tr>
                                </thead>
                                <tbody>
                                <?php foreach ( $cron_rows as $cron_row ) : ?>
                                        <tr>
                                                <td><code><?php echo esc_html( $cron_row[0] ); ?></code></td>
                                                <td><?php echo esc_html( $cron_row[1] ); ?></td>
                                        </tr>
                                <?php endforeach; ?>
                                </tbody>
                        </table>
                </div>

                <h3 class="nxa-tools-group-title"><?php esc_html_e( 'Coda lavori', 'nexus-news-ai' ); ?></h3>
                <dl class="nxa-tools-defs">
                        <?php foreach ( $queue_rows as $queue_row ) : ?>
                                <div class="nxa-tools-def">
                                        <dt><?php echo esc_html( $queue_row[0] ); ?></dt>
                                        <dd><?php echo esc_html( $queue_row[1] ); ?></dd>
                                </div>
                        <?php endforeach; ?>
                </dl>
        </div>

        <!-- SEZIONE 1b: diagnostica AI (v1.0.7) — configurazione AI e test provider.
             La chiave API non compare MAI in questa card: solo il badge di stato. -->
        <div class="nxa-tools-section">
                <h2 class="nxa-tools-section-title"><?php esc_html_e( 'Diagnostica AI', 'nexus-news-ai' ); ?></h2>
                <p class="nxa-tools-desc"><?php esc_html_e( 'Mostra a quale provider è connesso il plugin e quanti modelli espone.', 'nexus-news-ai' ); ?></p>
                <dl class="nxa-tools-defs">
                        <div class="nxa-tools-def">
                                <dt><?php esc_html_e( 'URL base API AI', 'nexus-news-ai' ); ?></dt>
                                <dd><code><?php echo esc_html( '' !== $ai_base ? $ai_base : __( 'Predefinito OpenRouter', 'nexus-news-ai' ) ); ?></code></dd>
                        </div>
                        <div class="nxa-tools-def">
                                <dt><?php esc_html_e( 'Modello globale', 'nexus-news-ai' ); ?></dt>
                                <dd><code><?php echo esc_html( '' !== $ai_model ? $ai_model : __( 'Non impostato', 'nexus-news-ai' ) ); ?></code></dd>
                        </div>
                        <?php if ( '' !== $ai_cheap ) : ?>
                                <div class="nxa-tools-def">
                                        <dt><?php esc_html_e( 'Modello economico (screening)', 'nexus-news-ai' ); ?></dt>
                                        <dd><code><?php echo esc_html( $ai_cheap ); ?></code></dd>
                                </div>
                        <?php endif; ?>
                        <div class="nxa-tools-def">
                                <dt><?php esc_html_e( 'Chiave API AI', 'nexus-news-ai' ); ?></dt>
                                <dd><span class="nxa-tools-badge nxa-tools-badge--<?php echo esc_attr( $ai_key_ok ? 'on' : 'off' ); ?>"><?php echo esc_html( $ai_key_ok ? __( 'Configurata', 'nexus-news-ai' ) : __( 'Mancante', 'nexus-news-ai' ) ); ?></span></dd>
                        </div>
                </dl>
                <p class="nxa-tools-actions">
                        <button type="button" id="nxa-tools-ai-test" class="nxa-btn nxa-btn-primary"><?php esc_html_e( 'Testa provider AI', 'nexus-news-ai' ); ?></button>
                </p>
                <div id="nxa-tools-ai-result" class="nxa-tools-ai-result" aria-live="polite"></div>
        </div>

        <!-- SEZIONE 2: esporta configurazione (GET /export → download JSON lato client). -->
        <div class="nxa-tools-section">
                <h2 class="nxa-tools-section-title"><?php esc_html_e( 'Esporta configurazione', 'nexus-news-ai' ); ?></h2>
                <p class="nxa-tools-desc"><?php esc_html_e( 'Esporta impostazioni e agenti in un file JSON. NON include articoli, iscritti o log.', 'nexus-news-ai' ); ?></p>
                <p class="nxa-tools-warn"><?php esc_html_e( 'ATTENZIONE: il file contiene chiavi API e token: conservalo in modo sicuro.', 'nexus-news-ai' ); ?></p>
                <p class="nxa-tools-actions">
                        <button type="button" id="nxa-tools-export" class="nxa-btn nxa-btn-primary"><?php esc_html_e( 'Scarica JSON', 'nexus-news-ai' ); ?></button>
                </p>
        </div>

        <!-- SEZIONE 3: importa configurazione (POST /import con JSON del file scelto). -->
        <div class="nxa-tools-section">
                <h2 class="nxa-tools-section-title"><?php esc_html_e( 'Importa configurazione', 'nexus-news-ai' ); ?></h2>
                <p class="nxa-tools-desc"><?php esc_html_e( 'Carica un file JSON esportato da Nexus News AI per ripristinare impostazioni e agenti.', 'nexus-news-ai' ); ?></p>
                <p class="nxa-tools-warn"><?php esc_html_e( "L'import sovrascrive impostazioni e agenti esistenti (match per categoria).", 'nexus-news-ai' ); ?></p>
                <p class="nxa-tools-actions">
                        <label class="screen-reader-text" for="nxa-tools-import-file"><?php esc_html_e( 'File di configurazione JSON', 'nexus-news-ai' ); ?></label>
                        <input type="file" id="nxa-tools-import-file" class="nxa-tools-file" accept=".json,application/json">
                        <button type="button" id="nxa-tools-import" class="nxa-btn nxa-btn-primary"><?php esc_html_e( 'Importa', 'nexus-news-ai' ); ?></button>
                </p>
                <div id="nxa-tools-import-result" class="nxa-tools-result" role="status" aria-live="polite"><?php esc_html_e( 'Nessuna importazione eseguita in questa sessione.', 'nexus-news-ai' ); ?></div>
        </div>

        <!-- SEZIONE 4: API pubblica (stato, chiave, keygen, esempio curl). -->
        <div class="nxa-tools-section">
                <h2 class="nxa-tools-section-title"><?php esc_html_e( 'API pubblica', 'nexus-news-ai' ); ?></h2>
                <p class="nxa-tools-desc">
                        <span class="nxa-tools-badge nxa-tools-badge--<?php echo esc_attr( $api_enabled ? 'on' : 'off' ); ?>"><?php echo esc_html( $api_enabled ? 'Attiva' : 'Disattivata' ); ?></span>
                </p>
                <p class="nxa-tools-actions">
                        <label class="nxa-tools-key-label" for="nxa-tools-key"><?php esc_html_e( 'Chiave API corrente', 'nexus-news-ai' ); ?></label>
                        <input type="text" id="nxa-tools-key" class="nxa-tools-key" readonly value="<?php echo esc_attr( $api_key ); ?>" placeholder="<?php esc_attr_e( 'Nessuna chiave generata', 'nexus-news-ai' ); ?>">
                        <button type="button" id="nxa-tools-key-copy" class="nxa-btn"><?php esc_html_e( 'Copia', 'nexus-news-ai' ); ?></button>
                        <button type="button" id="nxa-tools-keygen" class="nxa-btn"><?php esc_html_e( 'Genera nuova chiave', 'nexus-news-ai' ); ?></button>
                </p>
                <p class="nxa-tools-desc"><?php esc_html_e( "Attiva o disattiva l'API in Impostazioni → Lingua e API pubblica.", 'nexus-news-ai' ); ?></p>
                <h3 class="nxa-tools-group-title"><?php esc_html_e( 'Esempio di richiesta', 'nexus-news-ai' ); ?></h3>
                <p class="nxa-tools-desc"><?php esc_html_e( 'Sostituisci TUA_CHIAVE con la chiave generata qui.', 'nexus-news-ai' ); ?></p>
                <p><code class="nxa-tools-code">curl -H &quot;X-Api-Key: TUA_CHIAVE&quot; <?php echo esc_url( home_url( '/' ) ); ?>wp-json/nexus-public/v1/articles</code></p>
        </div>

        <noscript>
                <div class="notice notice-error inline">
                        <p><?php esc_html_e( 'JavaScript è disattivato: esportazione, importazione e generazione della chiave richiedono JavaScript.', 'nexus-news-ai' ); ?></p>
                </div>
        </noscript>

</div>

<style>
  /* Vista Strumenti v1.0.6 — stile locale self-contained su classi prefisso
     .nxa-tools-. SOLO token del tema (CONTRATTO-v1.0.6.md §1): il tema chiaro è
     il default e lo scuro opzionale (body.nexus-theme-dark) eredita il flip delle
     variabili; nessun colore esadecimale fisso. Indentazione CSS: 2 spazi. */
  .nxa-tools-sub {
    max-width: 780px;
    margin: 8px 0 20px;
    color: var(--nxa-muted);
  }

  .nxa-tools-section {
    background: var(--nxa-surface);
    border: 1px solid var(--nxa-border);
    border-radius: var(--nxa-radius);
    padding: 20px 24px;
    margin: 0 0 16px;
    box-shadow: var(--nxa-shadow);
  }

  .nxa-tools-section-title {
    margin: 0 0 12px;
    font-size: 16px;
    font-weight: 700;
    letter-spacing: -0.01em;
    color: var(--nxa-text);
  }

  .nxa-tools-group-title {
    margin: 20px 0 8px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--nxa-muted);
  }

  .nxa-tools-section-title + .nxa-tools-group-title {
    margin-top: 4px;
  }

  /* Griglia di definizione (Ambiente, Coda lavori): etichette muted a sinistra,
     valori con cifre tabulari allineati a destra in colonna. */
  .nxa-tools-defs {
    margin: 0 0 8px;
    border: 1px solid var(--nxa-border);
    border-radius: var(--nxa-radius-sm);
    background: var(--nxa-surface);
  }

  .nxa-tools-def {
    display: grid;
    grid-template-columns: minmax(220px, 340px) minmax(0, 1fr);
    gap: 2px 16px;
    padding: 9px 16px;
    border-bottom: 1px solid var(--nxa-border-soft);
    transition: background-color 0.15s ease;
  }

  .nxa-tools-def:last-child {
    border-bottom: 0;
  }

  .nxa-tools-def:hover {
    background: var(--nxa-surface-2);
  }

  .nxa-tools-def dt {
    margin: 0;
    color: var(--nxa-muted);
    font-size: 13px;
    font-weight: 600;
    line-height: 1.6;
  }

  .nxa-tools-def dd {
    margin: 0;
    color: var(--nxa-text);
    font-variant-numeric: tabular-nums;
    word-break: break-word;
  }

  /* Tabelle (Tabelle database, Eventi WP-Cron): card con bordo token, intestazioni
     leggibili, righe hover. */
  .nxa-tools-tablewrap {
    margin: 0 0 8px;
    border: 1px solid var(--nxa-border);
    border-radius: var(--nxa-radius-sm);
    background: var(--nxa-surface);
    overflow: auto;
  }

  .nxa-tools-table {
    margin: 0;
    width: 100%;
    border: 0;
    background: var(--nxa-surface);
  }

  .nxa-tools-table th,
  .nxa-tools-table td {
    padding: 10px 14px;
    border: 0;
    border-bottom: 1px solid var(--nxa-border-soft);
    background: transparent;
    color: var(--nxa-text-body);
    font-size: 13px;
    text-align: left;
    vertical-align: middle;
  }

  .nxa-tools-table tbody tr:last-child th,
  .nxa-tools-table tbody tr:last-child td {
    border-bottom: 0;
  }

  .nxa-tools-table td code {
    white-space: nowrap;
  }

  .nxa-tools-table thead th {
    border-bottom: 1px solid var(--nxa-border);
    color: var(--nxa-muted);
    font-size: 11.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
  }

  .nxa-tools-table thead th:first-child {
    width: 340px;
  }

  .nxa-tools-table tbody tr {
    background: transparent;
    transition: background-color 0.15s ease;
  }

  .nxa-tools-table tbody tr:nth-child(odd) {
    background: var(--nxa-surface-2);
  }

  .nxa-tools-table tbody tr:hover {
    background: var(--nxa-surface-3);
  }

  .nxa-tools-desc {
    max-width: 780px;
    margin: 0 0 10px;
    color: var(--nxa-muted);
    line-height: 1.6;
  }

  .nxa-tools-warn {
    display: inline-block;
    margin: 0 0 12px;
    padding: 8px 12px;
    background: var(--nxa-warn-bg);
    border: 1px solid var(--nxa-warn-line);
    border-radius: var(--nxa-radius-sm);
    color: var(--nxa-warn-text);
    font-size: 13px;
    line-height: 1.5;
  }

  .nxa-tools-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    margin: 0 0 12px;
  }

  .nxa-tools-key-label {
    flex-basis: 100%;
    color: var(--nxa-text);
    font-size: 13px;
    font-weight: 600;
  }

  /* Chiave API: fondello "code" leggibile su chiaro (surface-3, bordo token). */
  .nxa-tools-key {
    flex: 1 1 320px;
    max-width: 560px;
    min-width: 0;
    padding: 9px 12px;
    background: var(--nxa-surface-3);
    border: 1px solid var(--nxa-border);
    border-radius: var(--nxa-radius-sm);
    color: var(--nxa-text);
    font-family: Consolas, Monaco, monospace;
    font-size: 13px;
    letter-spacing: 0.02em;
    font-variant-numeric: tabular-nums;
    box-shadow: none;
  }

  .nxa-tools-key:focus-visible {
    outline: 2px solid var(--nxa-focus);
    outline-offset: 2px;
    border-color: var(--nxa-focus);
    box-shadow: none;
  }

  /* Flash di attenzione quando si copia senza chiave (gestito dal JS). */
  .nxa-tools-key--empty {
    border-color: var(--nxa-warn-line);
    outline: 2px solid var(--nxa-warn-line);
    outline-offset: 2px;
  }

  /* Stato "copiata" del bottone Copia: token di stato ok, stabile anche in hover. */
  .nxa-btn.nxa-tools-copybtn--done,
  .nxa-btn.nxa-tools-copybtn--done:hover,
  .nxa-btn.nxa-tools-copybtn--done:active {
    color: var(--nxa-ok-text);
    background: var(--nxa-ok-bg);
    border-color: var(--nxa-ok-line);
    box-shadow: none;
  }

  .nxa-tools-section code {
    padding: 2px 8px;
    border-radius: 6px;
    background: var(--nxa-surface-3);
    border: 1px solid var(--nxa-border);
    color: var(--nxa-text);
    font-size: 12.5px;
    font-variant-numeric: tabular-nums;
  }

  code.nxa-tools-code {
    display: block;
    max-width: 100%;
    margin: 0;
    padding: 10px 14px;
    border-radius: var(--nxa-radius-sm);
    overflow-x: auto;
    white-space: pre-wrap;
    word-break: break-all;
    line-height: 1.6;
  }

  .nxa-tools-file {
    max-width: 100%;
    padding: 6px 12px;
    background: var(--nxa-surface-2);
    border: 1px solid var(--nxa-border);
    border-radius: var(--nxa-radius-sm);
    color: var(--nxa-text-body);
    font-size: 13px;
    line-height: 1.5;
    cursor: pointer;
  }

  .nxa-tools-file::file-selector-button {
    margin-right: 12px;
    padding: 6px 14px;
    border: 0;
    border-right: 1px solid var(--nxa-border);
    border-radius: calc(var(--nxa-radius-sm) - 3px) 0 0 calc(var(--nxa-radius-sm) - 3px);
    background: var(--nxa-surface-3);
    color: var(--nxa-text);
    font-family: inherit;
    font-size: 13px;
    font-weight: 600;
    line-height: 1.5;
    cursor: pointer;
    transition: background-color 0.15s ease;
  }

  .nxa-tools-file:hover::file-selector-button {
    background: var(--nxa-border);
  }

  .nxa-tools-file:focus-visible {
    outline: 2px solid var(--nxa-focus);
    outline-offset: 2px;
  }

  .nxa-tools-badge {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 999px;
    border: 1px solid transparent;
    font-size: 12px;
    font-weight: 600;
    line-height: 1.7;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
  }

  .nxa-tools-badge--on {
    color: var(--nxa-ok-text);
    background: var(--nxa-ok-bg);
    border-color: var(--nxa-ok-line);
  }

  .nxa-tools-badge--off {
    color: var(--nxa-err-text);
    background: var(--nxa-err-bg);
    border-color: var(--nxa-err-line);
  }

  .nxa-tools-result {
    margin: 4px 0 0;
    padding: 10px 14px;
    border: 1px solid var(--nxa-border);
    border-radius: var(--nxa-radius-sm);
    background: var(--nxa-surface-2);
    color: var(--nxa-text-body);
    font-size: 13px;
    line-height: 1.55;
  }

  .nxa-tools-result--ok {
    border-color: var(--nxa-ok-line);
    background: var(--nxa-ok-bg);
    color: var(--nxa-ok-text);
  }

  .nxa-tools-result--warn {
    border-color: var(--nxa-err-line);
    background: var(--nxa-err-bg);
    color: var(--nxa-err-text);
  }

  /* Diagnostica AI (v1.0.7): box esito del test provider, nascosto finché vuoto.
     Stessi token di .nxa-tools-result: chiaro default, scuro via flip variabili. */
  .nxa-tools-ai-result {
    margin: 4px 0 0;
    padding: 10px 14px;
    border: 1px solid var(--nxa-border);
    border-radius: var(--nxa-radius-sm);
    background: var(--nxa-surface-2);
    color: var(--nxa-text-body);
    font-size: 13px;
    line-height: 1.55;
  }

  .nxa-tools-ai-result:empty {
    display: none;
  }

  .nxa-tools-ai-result--ok {
    border-color: var(--nxa-ok-line);
    background: var(--nxa-ok-bg);
    color: var(--nxa-ok-text);
  }

  .nxa-tools-ai-result--err {
    border-color: var(--nxa-err-line);
    background: var(--nxa-err-bg);
    color: var(--nxa-err-text);
  }

  .nxa-tools-ai-line {
    margin: 0;
  }

  /* Nota cache inline dopo il conteggio: leggermente più piccola, colore ereditato. */
  .nxa-tools-ai-cache {
    font-size: 12px;
  }

  /* Primi 8 id modello come chip <code> (stile ereditato da .nxa-tools-section code). */
  .nxa-tools-ai-list {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin: 8px 0 0;
    padding: 0;
    list-style: none;
  }

  .nxa-tools-ai-list li {
    margin: 0;
  }

  .nxa-tools-ai-endpoint {
    display: block;
    margin: 8px 0 0;
    font-size: 12px;
    word-break: break-word;
  }

  /* Toast appeso a body: figlio di body, eredita i token (chiaro e scuro). */
  .nxa-tools-toast {
    position: fixed;
    right: 24px;
    bottom: 24px;
    z-index: 99999;
    max-width: 360px;
    padding: 12px 16px;
    border-radius: var(--nxa-radius-sm);
    background: var(--nxa-surface);
    border: 1px solid var(--nxa-ok-line);
    border-left: 4px solid var(--nxa-ok-text);
    box-shadow: var(--nxa-shadow-lg);
    color: var(--nxa-ok-text);
    font-size: 13px;
    line-height: 1.5;
  }

  .nxa-tools-toast--error {
    border-color: var(--nxa-err-line);
    border-left-color: var(--nxa-err-text);
    color: var(--nxa-err-text);
  }

  /* Scrollbar discreta nei contenitori scrollabili. */
  .nxa-tools-tablewrap::-webkit-scrollbar,
  .nxa-tools-code::-webkit-scrollbar {
    width: 8px;
    height: 8px;
  }

  .nxa-tools-tablewrap::-webkit-scrollbar-thumb,
  .nxa-tools-code::-webkit-scrollbar-thumb {
    background: var(--nxa-border);
    border-radius: 999px;
  }

  @media (max-width: 782px) {
    .nxa-tools-section {
      padding: 16px;
    }

    .nxa-tools-def {
      grid-template-columns: 1fr;
      gap: 2px;
    }

    .nxa-tools-table thead th:first-child {
      width: auto;
    }

    .nxa-tools-key {
      max-width: 100%;
    }
  }

  @media (prefers-reduced-motion: reduce) {
    .nxa-tools-def,
    .nxa-tools-table tbody tr,
    .nxa-tools-file::file-selector-button {
      transition: none;
    }
  }

  @media print {
    .nxa-tools-toast {
      display: none;
    }

    .nxa-tools-section {
      box-shadow: none;
    }
  }
</style>

<script>
        (function () {
                'use strict';

                /* Guard: la vista dipende dai dati localizzati in pagina dal plugin. */
                if (!window.NexusAdminData || !window.NexusAdminData.restUrl || !window.NexusAdminData.nonce) {
                        if (window.console && window.console.warn) {
                                window.console.warn('[Nexus News AI] NexusAdminData non disponibile: vista Strumenti non inizializzata.');
                        }
                        return;
                }

                var base = String(window.NexusAdminData.restUrl).replace(/\/+$/, '');
                var nonce = window.NexusAdminData.nonce;

                /* Riferimenti DOM (selettori fissi del markup sopra). */
                var exportBtn = document.getElementById('nxa-tools-export');
                var importBtn = document.getElementById('nxa-tools-import');
                var importFile = document.getElementById('nxa-tools-import-file');
                var importBox = document.getElementById('nxa-tools-import-result');
                var keyInput = document.getElementById('nxa-tools-key');
                var keygenBtn = document.getElementById('nxa-tools-keygen');

                /* Stato pagina: true mentre gira una chiamata (blocca i doppi click). */
                var busy = false;
                var toastEl = null;
                var toastTimer = null;

                /* Escape di ogni valore dinamico prima di innerHTML (contratto §2 T-02). */
                function esc(value) {
                        return String(value === null || value === undefined ? '' : value)
                                .replace(/&/g, '&amp;')
                                .replace(/</g, '&lt;')
                                .replace(/>/g, '&gt;')
                                .replace(/\x22/g, '&quot;')
                                .replace(/\x27/g, '&#039;');
                }

                /* Toast flottante in basso a destra; si rimuove da solo dopo 4 secondi. */
                function toast(message, tipo) {
                        if (!toastEl) {
                                toastEl = document.createElement('div');
                                document.body.appendChild(toastEl);
                        }
                        toastEl.className = 'nxa-tools-toast ' + (tipo === 'error' ? 'nxa-tools-toast--error' : 'nxa-tools-toast--success');
                        toastEl.textContent = String(message);
                        if (toastTimer) {
                                window.clearTimeout(toastTimer);
                        }
                        toastTimer = window.setTimeout(function () {
                                if (toastEl && toastEl.parentNode) {
                                        toastEl.parentNode.removeChild(toastEl);
                                }
                                toastEl = null;
                                toastTimer = null;
                        }, 4000);
                }

                /* Wrapper fetch: nonce + JSON; su errore HTTP lancia Error col messaggio del body. */
                function api(path, opts) {
                        opts = opts || {};
                        var headers = { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' };
                        var init = { method: opts.method || 'GET', headers: headers, credentials: 'same-origin' };
                        var key;
                        if (opts.headers) {
                                for (key in opts.headers) {
                                        if (Object.prototype.hasOwnProperty.call(opts.headers, key)) {
                                                headers[key] = opts.headers[key];
                                        }
                                }
                        }
                        if (opts.body) {
                                init.body = opts.body;
                        }
                        return fetch(base + path, init).then(function (response) {
                                return response.text().then(function (text) {
                                        var data = null;
                                        try {
                                                data = text ? JSON.parse(text) : null;
                                        } catch (parseError) {
                                                data = null;
                                        }
                                        if (!response.ok) {
                                                var message = 'Errore HTTP ' + response.status;
                                                if (data && data.message) {
                                                        message = String(data.message);
                                                }
                                                throw new Error(message);
                                        }
                                        return data;
                                });
                        });
                }

                /* Data locale odierna "YYYY-MM-DD" per il nome file di export. */
                function localDate() {
                        var d = new Date();
                        var m = d.getMonth() + 1;
                        var day = d.getDate();
                        var mm = m < 10 ? '0' + m : String(m);
                        var dd = day < 10 ? '0' + day : String(day);
                        return String(d.getFullYear()) + '-' + mm + '-' + dd;
                }

                /* Intero difensivo dai payload REST. */
                function toInt(value) {
                        var n = parseInt(value, 10);
                        return isNaN(n) ? 0 : n;
                }

                /* SEZIONE 2: esportazione → GET /export → Blob JSON → download locale. */
                if (exportBtn) {
                        exportBtn.addEventListener('click', function () {
                                if (busy) {
                                        return;
                                }
                                busy = true;
                                exportBtn.disabled = true;
                                api('/export').then(function (json) {
                                        var payload = null;
                                        try {
                                                payload = JSON.stringify(json, null, 2);
                                        } catch (stringifyError) {
                                                payload = null;
                                        }
                                        if (!payload) {
                                                payload = '{}';
                                        }
                                        var blob = new Blob([payload], { type: 'application/json' });
                                        var urlObj = URL.createObjectURL(blob);
                                        var link = document.createElement('a');
                                        link.href = urlObj;
                                        link.download = 'nexus-config-' + localDate() + '.json';
                                        document.body.appendChild(link);
                                        link.click();
                                        document.body.removeChild(link);
                                        window.setTimeout(function () {
                                                URL.revokeObjectURL(urlObj);
                                        }, 1000);
                                        toast('Configurazione esportata in nexus-config-' + localDate() + '.json', 'success');
                                }).catch(function (error) {
                                        toast('Esportazione non riuscita: ' + (error && error.message ? String(error.message) : 'errore sconosciuto'), 'error');
                                }).then(function () {
                                        busy = false;
                                        exportBtn.disabled = false;
                                });
                        });
                }

                /* SEZIONE 3: importazione → FileReader → POST /import → box esiti. */
                if (importBtn && importFile) {
                        importBtn.addEventListener('click', function () {
                                if (busy) {
                                        return;
                                }
                                var file = importFile.files && importFile.files.length > 0 ? importFile.files[0] : null;
                                if (!file) {
                                        toast('Seleziona prima un file JSON da importare.', 'error');
                                        return;
                                }
                                busy = true;
                                importBtn.disabled = true;

                                /* Rilascio stato e bottone a fine operazione (async). */
                                function finish() {
                                        busy = false;
                                        importBtn.disabled = false;
                                }

                                /* Box esiti: contenuto dinamico sempre escapato via esc(). */
                                function renderResult(line, tipo) {
                                        if (!importBox) {
                                                return;
                                        }
                                        importBox.className = 'nxa-tools-result' + (tipo === 'ok' ? ' nxa-tools-result--ok' : (tipo === 'warn' ? ' nxa-tools-result--warn' : ''));
                                        importBox.innerHTML = esc(line);
                                }

                                var reader = new FileReader();
                                reader.onerror = function () {
                                        finish();
                                        toast('Lettura del file non riuscita.', 'error');
                                };
                                reader.onload = function (ev) {
                                        var parsed = null;
                                        try {
                                                parsed = JSON.parse(String(ev && ev.target ? ev.target.result : ''));
                                        } catch (parseError) {
                                                parsed = null;
                                        }
                                        if (!parsed || typeof parsed !== 'object' || (parsed instanceof Array)) {
                                                finish();
                                                renderResult('File JSON non valido: atteso un oggetto con le chiavi settings e agents.', 'warn');
                                                toast('File JSON non valido.', 'error');
                                                return;
                                        }
                                        api('/import', { method: 'POST', body: JSON.stringify(parsed) }).then(function (data) {
                                                var su = data ? toInt(data.settings_updated) : 0;
                                                var au = data ? toInt(data.agents_updated) : 0;
                                                var ai = data ? toInt(data.agents_inserted) : 0;
                                                var errs = data && data.errors && data.errors.length ? data.errors : [];
                                                var line = 'Impostazioni aggiornate: ' + su + ' · Agenti aggiornati: ' + au + ' · Agenti creati: ' + ai;
                                                var i;
                                                if (errs.length) {
                                                        line += ' · Errori: ';
                                                        for (i = 0; i < errs.length; i++) {
                                                                if (i > 0) {
                                                                        line += ' — ';
                                                                }
                                                                line += String(errs[i]);
                                                        }
                                                }
                                                renderResult(line, errs.length ? 'warn' : 'ok');
                                                finish();
                                                toast(errs.length ? 'Import completato con errori' : 'Import completato', errs.length ? 'error' : 'success');
                                        }).catch(function (error) {
                                                finish();
                                                renderResult('Import non riuscito: ' + (error && error.message ? String(error.message) : 'errore sconosciuto'), 'warn');
                                                toast('Import non riuscito: ' + (error && error.message ? String(error.message) : 'errore sconosciuto'), 'error');
                                        });
                                };
                                reader.readAsText(file);
                        });
                }

                /* SEZIONE 4: generazione chiave API pubblica → POST /tools/api-key. */
                if (keygenBtn && keyInput) {
                        keygenBtn.addEventListener('click', function () {
                                if (busy) {
                                        return;
                                }
                                if (!window.confirm('Generare una nuova chiave invalida la chiave attuale?')) {
                                        return;
                                }
                                busy = true;
                                keygenBtn.disabled = true;
                                api('/tools/api-key', { method: 'POST' }).then(function (data) {
                                        var key = data && data.api_key ? String(data.api_key) : '';
                                        if (key) {
                                                keyInput.value = key;
                                                toast('Nuova chiave API generata', 'success');
                                        } else {
                                                toast('Risposta senza chiave API', 'error');
                                        }
                                }).catch(function (error) {
                                        toast('Generazione chiave non riuscita: ' + (error && error.message ? String(error.message) : 'errore sconosciuto'), 'error');
                                }).then(function () {
                                        busy = false;
                                        keygenBtn.disabled = false;
                                });
                        });
                }
        })();

        /* COPIA CHIAVE (v1.0.6): funzionalità aggiuntiva in IIFE separata — il blocco
           sopra resta identico al byte. Copia #nxa-tools-key negli appunti con
           feedback "Copiata" sul bottone (classe --done, token di stato ok), fallback
           execCommand per browser senza Clipboard API e flash di attenzione sull'input
           se la chiave è vuota (in quel caso il campo prende il focus). */
        (function () {
                'use strict';

                var keyInput = document.getElementById('nxa-tools-key');
                var copyBtn = document.getElementById('nxa-tools-key-copy');

                if (!keyInput || !copyBtn) {
                        return;
                }

                var originalLabel = copyBtn.textContent;
                var resetTimer = null;

                /* Click nel campo readonly: seleziona tutta la chiave (copia manuale). */
                keyInput.addEventListener('focus', function () {
                        try {
                                keyInput.select();
                        } catch (selectError) {
                                /* select() non disponibile: nessun impatto. */
                        }
                });

                /* Stato "copiata" evidente sul bottone, ritorno automatico. */
                function markCopied() {
                        copyBtn.className = 'nxa-btn nxa-tools-copybtn--done';
                        copyBtn.textContent = 'Copiata';
                        if (resetTimer) {
                                window.clearTimeout(resetTimer);
                        }
                        resetTimer = window.setTimeout(function () {
                                copyBtn.className = 'nxa-btn';
                                copyBtn.textContent = originalLabel;
                                resetTimer = null;
                        }, 2400);
                }

                /* Fallback senza Clipboard API: textarea nascosta + execCommand. */
                function legacyCopy(text) {
                        var scratch = document.createElement('textarea');
                        var done = false;
                        scratch.value = text;
                        scratch.setAttribute('readonly', 'readonly');
                        scratch.style.position = 'fixed';
                        scratch.style.left = '-9999px';
                        document.body.appendChild(scratch);
                        scratch.select();
                        try {
                                done = document.execCommand('copy');
                        } catch (copyError) {
                                done = false;
                        }
                        document.body.removeChild(scratch);
                        return done;
                }

                copyBtn.addEventListener('click', function () {
                        var value = String(keyInput.value || '');

                        if (!value) {
                                keyInput.className = 'nxa-tools-key nxa-tools-key--empty';
                                window.setTimeout(function () {
                                        keyInput.className = 'nxa-tools-key';
                                }, 1200);
                                keyInput.focus();
                                return;
                        }

                        copyBtn.disabled = true;

                        function finish() {
                                copyBtn.disabled = false;
                        }

                        if (window.navigator.clipboard && window.navigator.clipboard.writeText) {
                                window.navigator.clipboard.writeText(value).then(function () {
                                        finish();
                                        markCopied();
                                }).catch(function () {
                                        finish();
                                        if (legacyCopy(value)) {
                                                markCopied();
                                        }
                                });
                        } else {
                                finish();
                                if (legacyCopy(value)) {
                                        markCopied();
                                }
                        }
                });
        })();

        /* DIAGNOSTICA AI (v1.0.7): IIFE separata — i blocchi sopra restano intatti
           al byte. Test del provider: GET {restUrl}/ai/models con X-WP-Nonce (stesso
           pattern della IIFE principale). Successo: conteggio modelli, primi 8 id in
           lista <code>, nota cache (12 ore) e base_url usato. Errore: messaggio
           onesto dal body WP {code,message,data.status} nel box (stile errore) +
           toast. La chiave non tocca mai il browser: la usa solo il server. */
        (function () {
                'use strict';

                if (!window.NexusAdminData || !window.NexusAdminData.restUrl || !window.NexusAdminData.nonce) {
                        if (window.console && window.console.warn) {
                                window.console.warn('[Nexus News AI] NexusAdminData non disponibile: diagnostica AI non inizializzata.');
                        }
                        return;
                }

                var testBtn = document.getElementById('nxa-tools-ai-test');
                var resultBox = document.getElementById('nxa-tools-ai-result');

                if (!testBtn || !resultBox) {
                        return;
                }

                var base = String(window.NexusAdminData.restUrl).replace(/\/+$/, '');
                var nonce = window.NexusAdminData.nonce;
                var originalLabel = testBtn.textContent;
                var busy = false;
                var toastEl = null;
                var toastTimer = null;

                /* Escape di ogni valore dinamico prima di innerHTML. */
                function esc(value) {
                        return String(value === null || value === undefined ? '' : value)
                                .replace(/&/g, '&amp;')
                                .replace(/</g, '&lt;')
                                .replace(/>/g, '&gt;')
                                .replace(/\x22/g, '&quot;')
                                .replace(/\x27/g, '&#039;');
                }

                /* Toast flottante identico alla IIFE principale (4 secondi). */
                function toast(message, tipo) {
                        if (!toastEl) {
                                toastEl = document.createElement('div');
                                document.body.appendChild(toastEl);
                        }
                        toastEl.className = 'nxa-tools-toast ' + (tipo === 'error' ? 'nxa-tools-toast--error' : 'nxa-tools-toast--success');
                        toastEl.textContent = String(message);
                        if (toastTimer) {
                                window.clearTimeout(toastTimer);
                        }
                        toastTimer = window.setTimeout(function () {
                                if (toastEl && toastEl.parentNode) {
                                        toastEl.parentNode.removeChild(toastEl);
                                }
                                toastEl = null;
                                toastTimer = null;
                        }, 4000);
                }

                /* Box esito: classe base + modificatore di stato (ok / err). */
                function renderBox(html, tipo) {
                        resultBox.className = 'nxa-tools-ai-result' + (tipo === 'ok' ? ' nxa-tools-ai-result--ok' : (tipo === 'err' ? ' nxa-tools-ai-result--err' : ''));
                        resultBox.innerHTML = html;
                }

                testBtn.addEventListener('click', function () {
                        if (busy) {
                                return;
                        }
                        busy = true;
                        testBtn.disabled = true;
                        testBtn.textContent = 'Testo…';

                        function finish() {
                                busy = false;
                                testBtn.disabled = false;
                                testBtn.textContent = originalLabel;
                        }

                        fetch(base + '/ai/models', {
                                method: 'GET',
                                headers: { 'X-WP-Nonce': nonce, 'Accept': 'application/json' },
                                credentials: 'same-origin'
                        }).then(function (response) {
                                return response.text().then(function (text) {
                                        var data = null;
                                        try {
                                                data = text ? JSON.parse(text) : null;
                                        } catch (parseError) {
                                                data = null;
                                        }
                                        if (!response.ok) {
                                                var message = 'Errore HTTP ' + response.status;
                                                if (data && data.message) {
                                                        message = String(data.message);
                                                }
                                                throw new Error(message);
                                        }
                                        return data;
                                });
                        }).then(function (data) {
                                var models = data && data.models && data.models.length ? data.models : [];
                                var count = data && typeof data.count !== 'undefined' && data.count !== null ? parseInt(data.count, 10) : models.length;
                                if (isNaN(count)) {
                                        count = models.length;
                                }
                                var cached = !!(data && data.cached);
                                var providerBase = data && data.base_url ? String(data.base_url) : '';
                                var html = '<p class="nxa-tools-ai-line">' + esc(count) + ' modelli disponibili' + (cached ? ' <span class="nxa-tools-ai-cache">(dalla cache, 12 ore)</span>' : '') + '</p>';
                                var max = models.length < 8 ? models.length : 8;
                                var i;
                                if (max > 0) {
                                        html += '<ul class="nxa-tools-ai-list">';
                                        for (i = 0; i < max; i++) {
                                                html += '<li><code>' + esc(models[i]) + '</code></li>';
                                        }
                                        html += '</ul>';
                                }
                                if (providerBase) {
                                        html += '<span class="nxa-tools-ai-endpoint">Endpoint: ' + esc(providerBase) + '</span>';
                                }
                                renderBox(html, 'ok');
                                toast(count + ' modelli disponibili dal provider AI', 'success');
                                finish();
                        }).catch(function (error) {
                                var message = error && error.message ? String(error.message) : 'errore sconosciuto';
                                renderBox('<p class="nxa-tools-ai-line">Test provider AI non riuscito: ' + esc(message) + '</p>', 'err');
                                toast('Test provider AI non riuscito: ' + message, 'error');
                                finish();
                        });
                });
        })();
</script>
