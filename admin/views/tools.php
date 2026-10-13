<?php
/**
 * Vista Admin: "Strumenti" (pagina "tools") — v1.0.5, restyle v1.0.6.
 *
 * SELF-CONTAINED (contratto CONTRATTO-v1.0.5.md §2 T-02): markup + stili con
 * SOLO classi prefisso .nxa-tools- (nessun tocco a admin/css/nexus-admin.css) e
 * script IIFE ES2019 (nessun tocco ad assets/js/admin.js). Dalla 1.1.13 stile e
 * script vivono in admin/css/nexus-tools.css e assets/js/nexus-tools.js (task
 * O5): markup, selettori e logica invariati. La sezione 1
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
 * CONTRATTO v1.1.0 ("Tutto nativo"): nuova card "Migrazione contenuti nativi"
 * (SEZIONE 1c, tra Diagnostica AI ed Esporta): contatori server-side noscript-safe
 * (articoli CPT rimanenti via Nexus_Native::cpt_remaining(), gap mappature agenti
 * via Nexus_Native::agent_mapping_gaps()) + bottone di avvio con hint. NESSUN
 * JavaScript inline per questa card: la logica vive in assets/js/admin.js
 * (v1.1.0 §2 T-03) e consuma i selettori fissi elencati nel CONTRATTO DOM sotto.
 * La card non si renderizza se Nexus_Native non esiste (guard difensiva PHP).
 *
 * CONTRATTO v1.1.5 ("Respira", CONTRATTO-v1.1.5.md §6 T-05): sgrassata delle
 * superfici annidate — dentro le card di sezione, .nxa-tools-defs e
 * .nxa-tools-tablewrap non disegnano più una box dentro la box (niente bordo,
 * raggio o sfondo duplicati rispetto alla sezione che le contiene): restano
 * come griglia di definizione e contenimento scroll orizzontale. Nessun id,
 * data-*, classe o selettore rinominato o rimosso; markup e script invariati.
 *
 * CONTRATTO v1.1.13 ("Ottimizzazione", task O5): il blocco <style> inline e le
 * tre IIFE <script> inline sono estratti in admin/css/nexus-tools.css e
 * assets/js/nexus-tools.js (enqueue in class-nexus-admin.php, in_footer).
 * Nessun id, classe, selettore o logica cambiata; unica variazione: la IIFE
 * copia-chiave eredita il guard DOM-ready delle sorelle (da file esterno non
 * è più garantita l'esecuzione a fine body).
 *
 * CONTRATTO REST (rotte definite nel §2 T-03, namespace nexus/v1, perm admin):
 * - GET  {restUrl}/export        → { version, exported_at, site_url, settings, agents }
 *                                  NOTA v1.1.9 (D2): i segreti sono MASCHERATI
 *                                  ('' + x_*_set): l'export non contiene più
 *                                  chiavi API/token e non ripristina i segreti
 *                                  al re-import (vanno ricaricati a mano).
 * - POST {restUrl}/import        body: JSON prodotto da GET /export
 *                                  → { settings_updated, agents_updated,
 *                                      agents_inserted, errors: [] }
 * - POST {restUrl}/tools/api-key → { api_key_plain, api_key, message } (v1.1.9, D6:
 *                                  la chiave IN CHIARO è restituita UNA SOLA VOLTA
 *                                  — 'api_key_plain' è il nome canonico, 'api_key'
 *                                  resta come alias per compatibilità; in settings
 *                                  è salvato solo l'hash SHA-256, quindi la chiave
 *                                  precedente diventa invalida e non è più leggibile).
 * - GET  {restUrl}/ai/models     → { models:[id...], base_url, cached, count,
 *                                  fetched_at } (v1.0.7; errori: 400 key_missing,
 *                                  502 con messaggio onesto dal provider)
 * - POST {restUrl}/tools/test   body: { tool: search|read|feeds, query|url }
 *                                  → { ok, tool, result, error } (v1.1.7: UI
 *                                  "Test degli strumenti"; handler in admin.js,
 *                                  NON nello script inline; errori 400 onesti per
 *                                  tool/query/url mancanti, result clip 4000).
 * Autenticazione: header X-WP-Nonce = NexusAdminData.nonce (localizzato lato admin).
 *
 * CONTRATTO DOM (selettori fissi consumati dallo script della vista:
 * assets/js/nexus-tools.js dalla 1.1.13, prima dallo script inline in fondo al file):
 * - #nxa-tools-export         bottone "Scarica JSON" (sezione Esporta).
 * - #nxa-tools-import-file    input file accept=".json,application/json".
 * - #nxa-tools-import         bottone "Importa".
 * - #nxa-tools-import-result  box esiti dell'importazione.
 * - #nxa-tools-key            input readonly con la chiave API pubblica corrente
 *                             (v1.1.9, D6: vuoto con placeholder di stato quando
 *                             in DB c'è solo l'hash — la chiave in chiaro esiste
 *                             solo al momento della generazione).
 * - #nxa-tools-keygen         bottone "Genera nuova chiave" (con window.confirm).
 * - #nxa-tools-key-copy       bottone "Copia" (v1.0.6): copia #nxa-tools-key negli
 *                             appunti; stato "Copiata" temporaneo sul bottone.
 * - #nxa-tools-ai-test        bottone "Testa provider AI" (v1.0.7, Diagnostica AI).
 * - #nxa-tools-ai-result      box esito del test (aria-live="polite"; classi
 *                             nxa-tools-ai-result + modificatore --ok / --err).
 * - #nxa-native-cpt-remaining contatore server-side: articoli CPT da migrare.
 * - #nxa-native-agents-user-gap contatore server-side: agenti senza utente WP.
 * - #nxa-native-agents-term-gap contatore server-side: agenti senza categoria
 *                             nativa (categoria reale del sito).
 * - #nxa-native-run           bottone "Avvia migrazione" (v1.1.0): il click è
 *                             gestito da admin.js, NON dallo script inline qui.
 * - #nxa-native-progress      blocco progresso, hidden finché admin.js non mostra.
 * - #nxa-native-progress-bar  traccia della barra; fill interno con classe
 *                             nxa-native-progress-fill (larghezza percentuale
 *                             impostata da admin.js).
 * - #nxa-native-progress-label label testuale di avanzamento ("X di Y").
 * - #nxa-native-report        lista esiti ed errori, hidden finché admin.js la
 *                             riempie.
 * - #nxa-native-status        esito finale della migrazione, aria-live="polite".
 * - #nxa-tools-test-tool      select "Strumento da testare" (v1.1.7:
 *                             search|read|feeds; handler in admin.js, NON qui).
 * - #nxa-tools-test-input     input query/URL del test (placeholder dinamico
 *                             impostato da admin.js al cambio strumento).
 * - #nxa-tools-test-run       bottone "Esegui test" (v1.1.7): POST /tools/test
 *                             via admin.js (bindToolsTest, loader "tools").
 * - #nxa-tools-test-result    box esito del test (aria-live="polite"; classe
 *                             nxa-tools-result + modificatore --ok / --warn).
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

/* v1.1.9 (D6): la chiave è memorizzata come hash SHA-256 (hex-64): l'hash NON
   è la chiave e non va mai mostrato/copiato — si indica solo che esiste. Le
   chiavi legacy in chiaro (pre-migrazione) restano mostrate come prima finché
   non vengono rigenerate o migrate dall'upgrade-on-use dell'API pubblica. */
$api_key_is_hash = ( '' !== $api_key ) && (bool) preg_match( '/^[0-9a-f]{64}$/i', $api_key );

/* Diagnostica AI (v1.0.7): configurazione AI corrente. La chiave non viene MAI
   esposta (nemmeno parzialmente): solo un booleano configurata/manca. */
$ai_base   = isset( $settings['ai_base_url'] ) ? trim( (string) $settings['ai_base_url'] ) : '';
$ai_model  = isset( $settings['ai_model'] ) ? trim( (string) $settings['ai_model'] ) : '';
$ai_cheap  = isset( $settings['ai_model_cheap'] ) ? trim( (string) $settings['ai_model_cheap'] ) : '';
$ai_key_ok = isset( $settings['ai_api_key'] ) && '' !== trim( (string) $settings['ai_api_key'] );

/* Migrazione contenuti nativi (v1.1.0): contatori server-side della card 1c.
   Guard difensiva: se Nexus_Native non esiste (impossibile dopo il require del
   bootstrap, ma difensivo) la card non si renderizza affatto. */
$nxa_native_ok        = class_exists( 'Nexus_Native' );
$nxa_native_remaining = 0;
$nxa_native_user_gap  = 0;
$nxa_native_term_gap  = 0;
if ( $nxa_native_ok ) {
        if ( method_exists( 'Nexus_Native', 'cpt_remaining' ) ) {
                $nxa_native_remaining = absint( Nexus_Native::cpt_remaining() );
        }
        if ( method_exists( 'Nexus_Native', 'agent_mapping_gaps' ) ) {
                $nxa_native_gaps = Nexus_Native::agent_mapping_gaps();
                if ( is_array( $nxa_native_gaps ) ) {
                        $nxa_native_user_gap = isset( $nxa_native_gaps['without_user'] ) ? absint( $nxa_native_gaps['without_user'] ) : 0;
                        $nxa_native_term_gap = isset( $nxa_native_gaps['without_term'] ) ? absint( $nxa_native_gaps['without_term'] ) : 0;
                }
        }
}

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

        <!-- SEZIONE 1c: migrazione contenuti nativi (v1.1.0) — contatori server-side
             noscript-safe e avvio migrazione. NESSUN JavaScript inline in questa
             card: la logica vive in assets/js/admin.js, che consuma i selettori
             fissi elencati nel CONTRATTO DOM in testa al file. La card compare
             solo se Nexus_Native esiste (guard difensiva PHP sopra). -->
        <?php if ( $nxa_native_ok ) : ?>
        <div class="nxa-tools-section">
                <h2 class="nxa-tools-section-title"><?php esc_html_e( 'Migrazione contenuti nativi', 'nexus-news-ai' ); ?></h2>
                <p class="nxa-tools-desc"><?php esc_html_e( 'Stato della conversione al contenuto nativo: articoli rimasti e agenti senza mappatura nativa.', 'nexus-news-ai' ); ?></p>
                <dl class="nxa-tools-defs">
                        <div class="nxa-tools-def">
                                <dt><?php esc_html_e( 'Articoli Nexus da migrare', 'nexus-news-ai' ); ?></dt>
                                <dd><span id="nxa-native-cpt-remaining"><?php echo esc_html( $nxa_native_remaining ); ?></span></dd>
                        </div>
                        <div class="nxa-tools-def">
                                <dt><?php esc_html_e( 'Agenti senza utente WP', 'nexus-news-ai' ); ?></dt>
                                <dd><span id="nxa-native-agents-user-gap"><?php echo esc_html( $nxa_native_user_gap ); ?></span></dd>
                        </div>
                        <div class="nxa-tools-def">
                                <dt><?php esc_html_e( 'Agenti senza categoria nativa', 'nexus-news-ai' ); ?></dt>
                                <dd><span id="nxa-native-agents-term-gap"><?php echo esc_html( $nxa_native_term_gap ); ?></span></dd>
                        </div>
                </dl>
                <p class="nxa-tools-actions">
                        <button type="button" id="nxa-native-run" class="nxa-btn nxa-btn-primary"><?php esc_html_e( 'Avvia migrazione', 'nexus-news-ai' ); ?></button>
                </p>
                <p class="nxa-tools-desc"><?php esc_html_e( 'La migrazione converte i vecchi articoli Nexus in post WordPress nativi (autore = agente, categoria nativa).', 'nexus-news-ai' ); ?></p>
                <div id="nxa-native-progress" hidden>
                        <div id="nxa-native-progress-bar">
                                <div class="nxa-native-progress-fill"></div>
                        </div>
                        <p id="nxa-native-progress-label"></p>
                </div>
                <ul id="nxa-native-report" hidden></ul>
                <p id="nxa-native-status" aria-live="polite"></p>
        </div>
        <?php endif; ?>

        <!-- SEZIONE 1-ter (v1.1.7): test degli strumenti di ricerca. La rotta
             POST /tools/test esiste da v1.1.4 (callback rest_tools_test) ma era
             ORFANA: nessuna UI la chiamava. Il click è gestito da admin.js
             (bindToolsTest nel loader "tools"), NON dallo script inline qui:
             stesso pattern della migrazione nativa. search vuole query, read e
             feeds vogliono url (il body lo costruisce admin.js); gli errori 400
             della rotta finiscono onestamente nel box esito con modificatore --warn. -->
        <div class="nxa-tools-section">
                <h2 class="nxa-tools-section-title"><?php esc_html_e( 'Test degli strumenti', 'nexus-news-ai' ); ?></h2>
                <p class="nxa-tools-desc"><?php esc_html_e( 'Prova la configurazione degli strumenti senza attivarli: ricerca web, lettura pagina e scoperta feed.', 'nexus-news-ai' ); ?></p>
                <p class="nxa-tools-actions">
                        <label class="screen-reader-text" for="nxa-tools-test-tool"><?php esc_html_e( 'Strumento da testare', 'nexus-news-ai' ); ?></label>
                        <select id="nxa-tools-test-tool" class="nxa-tools-test-tool">
                                <option value="search" selected><?php esc_html_e( 'Ricerca web (search)', 'nexus-news-ai' ); ?></option>
                                <option value="read"><?php esc_html_e( 'Lettura pagina (read)', 'nexus-news-ai' ); ?></option>
                                <option value="feeds"><?php esc_html_e( 'Scoperta feed RSS (feeds)', 'nexus-news-ai' ); ?></option>
                        </select>
                        <label class="screen-reader-text" for="nxa-tools-test-input"><?php esc_html_e( 'Query o URL da testare', 'nexus-news-ai' ); ?></label>
                        <input type="text" id="nxa-tools-test-input" class="nxa-tools-test-input" placeholder="<?php esc_attr_e( 'es. intelligenza artificiale', 'nexus-news-ai' ); ?>">
                        <button type="button" id="nxa-tools-test-run" class="nxa-btn nxa-btn-primary"><?php esc_html_e( 'Esegui test', 'nexus-news-ai' ); ?></button>
                </p>
                <div id="nxa-tools-test-result" class="nxa-tools-result" role="status" aria-live="polite"><?php esc_html_e( 'Nessun test eseguito in questa sessione.', 'nexus-news-ai' ); ?></div>
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
                        <?php /* v1.1.9 (D6): chiave hashata → campo vuoto con placeholder di stato (l'hash non è la chiave, non va copiato); legacy in chiaro → mostrata come prima finché non migrata. */ ?>
                        <input type="text" id="nxa-tools-key" class="nxa-tools-key" readonly value="<?php echo esc_attr( $api_key_is_hash ? '' : $api_key ); ?>" placeholder="<?php echo esc_attr( $api_key_is_hash ? __( '•••• (salvata, hashing) — rigenera per ottenerne una nuova', 'nexus-news-ai' ) : __( 'Nessuna chiave generata', 'nexus-news-ai' ) ); ?>">
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

<?php
/*
 * Stili della vista estratti in admin/css/nexus-tools.css (1.1.13, task O5):
 * stessi selettori al byte, gia' SOLO token var(--nxa-*) del foglio condiviso.
 * Enqueue in class-nexus-admin.php, pagina tools.
 */
?>

<?php
/*
 * Script della vista estratto in assets/js/nexus-tools.js (1.1.13, task O5):
 * tre IIFE statiche (boot, copia chiave, diagnostica AI), zero PHP interpolato.
 * La IIFE copia-chiave ha ereditato il guard DOM-ready delle sorelle per essere
 * robusta rispetto all'enqueue. Enqueue in class-nexus-admin.php (in_footer).
 */
?>
