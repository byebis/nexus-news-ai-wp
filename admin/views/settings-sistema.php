<?php
/**
 * Vista Admin: Impostazioni sistema (pagina "settings-sistema" di Nexus News AI).
 *
 * v1.1.6 (contratto T-02): le Impostazioni diventano 4 sottomenu WP; questa è
 * la pagina del gruppo "Sito & sistema" (accordion: scala, language-api,
 * interface) e raccoglie l'ultima parte della vecchia settings.php (rimossa
 * in v1.1.6).
 *
 * v1.1.12 (S8): due nuove sezioni accordion — "Feedback performance (Google)"
 * (credenziali OAuth GSC/GA4, stato connessione, sync metriche) ed "Evergreen
 * (pillar)". I name dei campi = chiavi ESATTE di Nexus_DB::default_settings()
 * v1.1.12: gsc_oauth_client_id, gsc_oauth_client_secret, gsc_site_url,
 * ga4_property_id, metrics_sync_enabled, evergreen_enabled,
 * evergreen_refresh_days, evergreen_max_posts. Il client secret segue il
 * pattern segreti v1.1.9 (type="password", value stampato esc_attr per
 * re-invio idempotente, placeholder "•••• salvata — compila per sostituire"
 * quando valorizzato, autocomplete="new-password"); la chiave entra nella
 * mascheratura REST Nexus_Rest::secret_settings_keys() (v1.1.12 S8). Le tre
 * chiavi evergreen_* arrivano da default_settings() col pacchetto S6: finché
 * quel merge non arriva get_settings() non le restituisce (i ?? sono
 * difensivi, non mock).
 *
 * CONTRATTO OAUTH (v1.1.12 S8): Connetti/Disconnetti puntano a admin-post.php
 * con action nexus_gsc_authorize / nexus_gsc_disconnect e nonce dedicati
 * (nexus_gsc_oauth / nexus_gsc_disconnect); i guard class_exists/method_exists
 * rendono la vista robusta con lo stub S0 (finché S1 non implementa
 * is_connected()/get_redirect_uri() la vista mostra "Connetti Google" e il
 * redirect URI di fallback admin-post). L'esito del ritorno OAuth arriva via
 * query ?nexus_gsc_oauth=ok|err|disconnected: notice inline whitelisto con
 * sanitize_key (sola lettura, nessun nonce per un semplice messaggio di stato).
 *
 * DEBITO INTEGRAZIONE (S8): i name nuovi NON sono ancora negli array
 * simple/numbers/flags di collectSettings() in admin.js né nella whitelist
 * sanitize_settings_payload() di class-nexus-rest.php (file fuori perimetro
 * S8): il markup segue GIÀ il contratto (name = chiave esatta, come stabilito
 * per global_rss_sources in 1.1.11); il salvataggio effettivo arriva quando il
 * glue admin.js + whitelist REST viene esteso.
 *
 * I campi sono PRECOMPILATI server-side con Nexus_DB::get_settings()
 * (value / checked / selected): la pagina è già corretta prima che admin.js risponda.
 *
 * CONTRATTO FISSO CON admin.js (non rinominare id e name):
 * - div.wrap.nexus-admin con data-page="settings" INVARIATO: il boot di
 *   admin.js riconosce tutte e 4 le pagine impostazioni dalla stessa pagina
 *   logica "settings" e aggancia form#nxa-settings-form.nxa-settings-form;
 *   il submit è intercettato, i campi serializzati come name=value e inviati
 *   con PUT /settings (nexus/v1), che è PARZIALE con whitelist: ogni pagina
 *   invia solo le proprie chiavi (i campi assenti non toccano il setting).
 * - Toggle checkbox value="1" (enable_queue, enable_public_api): serializzati
 *   0/1 da admin.js nel blocco scalaFlags (0 quando deselezionati); campi
 *   numerici queue_batch_size e queue_max_attempts raccolti nel blocco numbers.
 * - 2 select simple: admin_language (auto|it|en, blocco simple) e admin_theme
 *   (light|dark, blocco simple, select #nxa-admin-theme). Campo precompilato
 *   con get_settings() (selected, con fallback ?? sulle chiavi nuove).
 * - Il tema scuro aggiunge la classe nexus-theme-dark al body dell'admin
 *   (filtro admin_body_class); il tema chiaro è il default.
 * - Bottone SALVA IN CIMA (subito sotto l'h1) OLTRE quello in fondo: entrambi
 *   type="submit" nella stessa form; admin.js v1.1.6 mostra lo stato di
 *   caricamento su tutti i submit della form.
 * - Sezioni accordion section.nxa-acc[data-nxa-acc] con
 *   button.nxa-acc-toggle[aria-expanded][aria-controls], span.nxa-acc-label,
 *   span.nxa-acc-chevron, div.nxa-acc-panel[hidden]. Lo stato dei gruppi
 *   (array slug aperti) è persistito in localStorage['nxa-settings-acc-sistema']
 *   (chiave propria della pagina, JSON con try/catch) dall'IIFE ES5 in fondo
 *   al file (solo toggle accordion, nessun fetch); default senza localStorage:
 *   pannello "scala" aperto, gli altri chiusi (stato renderizzato anche
 *   server-side, così senza JS la prima sezione resta leggibile). Le classi
 *   accordion sono definite nel CSS condiviso: qui solo il micro-stile della
 *   barra Salva in cima.
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

$settings = Nexus_DB::get_settings();

/* v1.1.12 (S8) — esito OAuth Google: query ?nexus_gsc_oauth=<stato> (sola
 * lettura, semplice messaggio di stato: nessun nonce richiesto). Valore
 * whitelisto con sanitize_key: solo ok | err | disconnected producono notice. */
$nxa_gsc_oauth = isset( $_GET['nexus_gsc_oauth'] ) ? sanitize_key( wp_unslash( $_GET['nexus_gsc_oauth'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $nxa_gsc_oauth, array( 'ok', 'err', 'disconnected' ), true ) ) {
        $nxa_gsc_oauth = '';
}

/* v1.1.12 (S8) — Evergreen (pillar): chiavi aggiunte a default_settings() dal
 * pacchetto S6; finché quel merge non arriva get_settings() non le include, i
 * fallback qui sotto sono solo difensivi (stessi limiti del markup). */
$evergreen_enabled      = isset( $settings['evergreen_enabled'] ) ? (int) $settings['evergreen_enabled'] : 0;
$evergreen_refresh_days = isset( $settings['evergreen_refresh_days'] ) ? (int) $settings['evergreen_refresh_days'] : 7;
$evergreen_max_posts    = isset( $settings['evergreen_max_posts'] ) ? (int) $settings['evergreen_max_posts'] : 1;
?>
<div class="wrap nexus-admin" data-page="settings">
        <h1><?php esc_html_e( 'Impostazioni sistema', 'nexus-news-ai' ); ?></h1>

        <?php if ( '' !== $nxa_gsc_oauth ) : ?>
                <?php $nxa_gsc_notice_class = 'notice-info'; ?>
                <?php if ( 'ok' === $nxa_gsc_oauth ) : ?>
                        <?php $nxa_gsc_notice_class = 'notice-success'; ?>
                <?php elseif ( 'err' === $nxa_gsc_oauth ) : ?>
                        <?php $nxa_gsc_notice_class = 'notice-error'; ?>
                <?php endif; ?>
                <div class="notice <?php echo esc_attr( $nxa_gsc_notice_class ); ?>"><p>
                        <?php
                        if ( 'ok' === $nxa_gsc_oauth ) {
                                esc_html_e( 'Connessione a Google completata.', 'nexus-news-ai' );
                        } elseif ( 'err' === $nxa_gsc_oauth ) {
                                esc_html_e( 'Connessione a Google non riuscita: riprova dal pulsante Connetti Google.', 'nexus-news-ai' );
                        } else {
                                esc_html_e( 'Google disconnesso.', 'nexus-news-ai' );
                        }
                        ?>
                </p></div>
        <?php endif; ?>

        <form id="nxa-settings-form" class="nxa-settings-form">
                <p class="submit nxa-submit-top">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Salva impostazioni', 'nexus-news-ai' ); ?></button>
                </p>

                <section class="nxa-acc" data-nxa-acc="scala">
                        <button type="button" class="nxa-acc-toggle" aria-expanded="true" aria-controls="nxa-acc-panel-scala">
                                <span class="nxa-acc-label"><?php esc_html_e( 'Scala & Pro', 'nexus-news-ai' ); ?></span>
                                <span class="nxa-acc-chevron" aria-hidden="true"></span>
                        </button>
                        <div class="nxa-acc-panel" id="nxa-acc-panel-scala">
                                <?php /* Sezione v1.0.4 "Scala & Pro": coda lavori stile Action Scheduler (1 toggle scalaFlags + 2 numerici). */ ?>
                                <table class="form-table" role="presentation">
                                        <tbody>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Coda lavori', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <label for="nxa-enable-queue">
                                                                        <input type="checkbox" name="enable_queue" id="nxa-enable-queue" value="1" <?php checked( (int) $settings['enable_queue'], 1 ); ?> />
                                                                        <?php esc_html_e( 'Usa la coda lavori (stile Action Scheduler)', 'nexus-news-ai' ); ?>
                                                                </label>
                                                                <p class="description"><?php esc_html_e( 'Le azioni pesanti (raccolta, valutazione, pubblicazione, traduzioni) vengono accodate nella tabella nexus_jobs ed eseguite in batch ogni 60 secondi dal runner WP-Cron. Consigliato su siti con molto traffico o hosting lento. Lo stato live è visibile nella pagina Coda Lavori.', 'nexus-news-ai' ); ?></p>
                                                                <p class="description"><?php esc_html_e( 'Vedi la pagina Coda Lavori per statistiche, retry e pulizia. I comandi WP-CLI wp nexus queue stats|process sono disponibili.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-queue-batch-size"><?php esc_html_e( 'Lavori per batch', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="number" name="queue_batch_size" id="nxa-queue-batch-size" value="<?php echo esc_attr( $settings['queue_batch_size'] ); ?>" min="1" max="50" step="1" class="small-text" />
                                                                <p class="description"><?php esc_html_e( 'Quanti lavori al massimo vengono eseguiti a ogni tick del runner (default 5).', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-queue-max-attempts"><?php esc_html_e( 'Tentativi massimi per lavoro', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="number" name="queue_max_attempts" id="nxa-queue-max-attempts" value="<?php echo esc_attr( $settings['queue_max_attempts'] ); ?>" min="1" max="10" step="1" class="small-text" />
                                                                <p class="description"><?php esc_html_e( "Dopo N fallimenti il lavoro viene marcato come Fallito (default 3). Tra un tentativo e l'altro il ritardo raddoppia (backoff esponenziale, max 1 ora).", 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                        </tbody>
                                </table>
                        </div>
                </section>

                <section class="nxa-acc" data-nxa-acc="language-api">
                        <button type="button" class="nxa-acc-toggle" aria-expanded="false" aria-controls="nxa-acc-panel-language-api">
                                <span class="nxa-acc-label"><?php esc_html_e( 'Lingua e API pubblica', 'nexus-news-ai' ); ?></span>
                                <span class="nxa-acc-chevron" aria-hidden="true"></span>
                        </button>
                        <div class="nxa-acc-panel" id="nxa-acc-panel-language-api" hidden>
                                <?php /* Sezione v1.0.5 "Lingua e API pubblica": select admin_language (blocco simple) + toggle enable_public_api (blocco scalaFlags). */ ?>
                                <table class="form-table" role="presentation">
                                        <tbody>
                                                <tr>
                                                        <th scope="row"><label for="nxa-admin-language"><?php esc_html_e( "Lingua dell'interfaccia admin", 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <select name="admin_language" id="nxa-admin-language">
                                                                        <option value="auto" <?php selected( $settings['admin_language'] ?? 'auto', 'auto' ); ?>><?php esc_html_e( 'Automatica (segue la lingua del sito)', 'nexus-news-ai' ); ?></option>
                                                                        <option value="it" <?php selected( $settings['admin_language'] ?? 'auto', 'it' ); ?>><?php esc_html_e( 'Italiano', 'nexus-news-ai' ); ?></option>
                                                                        <option value="en" <?php selected( $settings['admin_language'] ?? 'auto', 'en' ); ?>><?php esc_html_e( 'English', 'nexus-news-ai' ); ?></option>
                                                                </select>
                                                                <p class="description"><?php esc_html_e( "Traduzione dell'interfaccia admin in inglese (sperimentale): copre etichette e messaggi principali dell'admin Nexus.", 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'API pubblica', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <label for="nxa-enable-public-api">
                                                                        <input type="checkbox" name="enable_public_api" id="nxa-enable-public-api" value="1" <?php checked( (int) ( $settings['enable_public_api'] ?? 0 ), 1 ); ?> />
                                                                        <?php esc_html_e( 'Attiva API pubblica (nexus-public/v1)', 'nexus-news-ai' ); ?>
                                                                </label>
                                                                <p class="description"><?php esc_html_e( 'Espone articoli pubblicati, agenti e statistiche via REST pubblica con autenticazione X-Api-Key (rate limit 120 richieste/minuto). Genera la chiave nella pagina Strumenti.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                        </tbody>
                                </table>
                        </div>
                </section>

                <section class="nxa-acc" data-nxa-acc="interface">
                        <button type="button" class="nxa-acc-toggle" aria-expanded="false" aria-controls="nxa-acc-panel-interface">
                                <span class="nxa-acc-label"><?php esc_html_e( 'Interfaccia', 'nexus-news-ai' ); ?></span>
                                <span class="nxa-acc-chevron" aria-hidden="true"></span>
                        </button>
                        <div class="nxa-acc-panel" id="nxa-acc-panel-interface" hidden>
                                <?php /* Sezione v1.0.6 "Interfaccia": select admin_theme (blocco simple) — pannello chiaro di default dalla 1.0.6, tema scuro opzionale (body.nexus-theme-dark). */ ?>
                                <table class="form-table" role="presentation">
                                        <tbody>
                                                <tr>
                                                        <th scope="row"><label for="nxa-admin-theme"><?php esc_html_e( 'Tema del pannello', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <select name="admin_theme" id="nxa-admin-theme">
                                                                        <option value="light" <?php selected( $settings['admin_theme'] ?? 'light', 'light' ); ?>><?php esc_html_e( 'Chiaro (consigliato)', 'nexus-news-ai' ); ?></option>
                                                                        <option value="dark" <?php selected( $settings['admin_theme'] ?? 'light', 'dark' ); ?>><?php esc_html_e( 'Scuro (aspetto delle versioni precedenti)', 'nexus-news-ai' ); ?></option>
                                                                </select>
                                                                <p class="description"><?php esc_html_e( "Dalla 1.0.6 il pannello Nexus è chiaro di default; scegli Scuro per tornare all'aspetto notturno della 1.0.5. Il tema si applica a tutte le pagine Nexus dopo il salvataggio.", 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                        </tbody>
                                </table>
                        </div>
                </section>

                <section class="nxa-acc" data-nxa-acc="google-feedback">
                        <button type="button" class="nxa-acc-toggle" aria-expanded="false" aria-controls="nxa-acc-panel-google-feedback">
                                <span class="nxa-acc-label"><?php esc_html_e( 'Feedback performance (Google)', 'nexus-news-ai' ); ?></span>
                                <span class="nxa-acc-chevron" aria-hidden="true"></span>
                        </button>
                        <div class="nxa-acc-panel" id="nxa-acc-panel-google-feedback" hidden>
                                <?php /* Sezione v1.1.12 (S8) "Feedback performance (Google)": credenziali OAuth GSC/GA4, stato connessione e sync metriche (name = chiavi esatte default_settings v1.1.12). */ ?>
                                <table class="form-table" role="presentation">
                                        <tbody>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Stato connessione Google', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <?php $nxa_gsc_connected = class_exists( 'Nexus_Google_Auth' ) && method_exists( 'Nexus_Google_Auth', 'is_connected' ) && Nexus_Google_Auth::is_connected(); ?>
                                                                <?php if ( $nxa_gsc_connected ) : ?>
                                                                        <p class="nxa-gsc-status">
                                                                                <span class="nxa-gsc-badge"><?php esc_html_e( 'Connesso', 'nexus-news-ai' ); ?></span>
                                                                                <a class="button-link" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=nexus_gsc_disconnect' ), 'nexus_gsc_disconnect' ) ); ?>"><?php esc_html_e( 'Disconnetti', 'nexus-news-ai' ); ?></a>
                                                                        </p>
                                                                <?php else : ?>
                                                                        <p class="nxa-gsc-status">
                                                                                <a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=nexus_gsc_authorize' ), 'nexus_gsc_oauth' ) ); ?>"><?php esc_html_e( 'Connetti Google', 'nexus-news-ai' ); ?></a>
                                                                        </p>
                                                                <?php endif; ?>
                                                                <p class="description">
                                                                        <?php esc_html_e( 'Redirect URI da registrare in Google Cloud Console (client OAuth "App web"):', 'nexus-news-ai' ); ?>
                                                                        <br />
                                                                        <code><?php echo esc_html( method_exists( 'Nexus_Google_Auth', 'get_redirect_uri' ) ? Nexus_Google_Auth::get_redirect_uri() : admin_url( 'admin-post.php?action=nexus_gsc_oauth_callback' ) ); ?></code>
                                                                </p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-gsc-client-id"><?php esc_html_e( 'Client ID OAuth', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="text" name="gsc_oauth_client_id" id="nxa-gsc-client-id" value="<?php echo esc_attr( $settings['gsc_oauth_client_id'] ); ?>" class="regular-text code" />
                                                                <p class="description"><?php esc_html_e( 'Client ID del client OAuth 2.0 "App web" creato in Google Cloud Console.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-gsc-client-secret"><?php esc_html_e( 'Client secret OAuth', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="password" name="gsc_oauth_client_secret" id="nxa-gsc-client-secret" value="<?php echo esc_attr( $settings['gsc_oauth_client_secret'] ); ?>" placeholder="<?php echo esc_attr( '' !== trim( (string) $settings['gsc_oauth_client_secret'] ) ? __( '•••• salvata — compila per sostituire', 'nexus-news-ai' ) : '' ); ?>" class="regular-text code" autocomplete="new-password" />
                                                                <p class="description"><?php esc_html_e( 'Campo mascherato: compila il campo solo per sostituire il client secret salvato (vuoto = nessun cambio).', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-gsc-site-url"><?php esc_html_e( 'Property Search Console', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="text" name="gsc_site_url" id="nxa-gsc-site-url" value="<?php echo esc_attr( $settings['gsc_site_url'] ); ?>" placeholder="https://esempio.com/" class="regular-text code" />
                                                                <p class="description"><?php esc_html_e( 'URL esatta della property GSC (es. https://esempio.com/): deve coincidere con quella registrata in Search Console.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-ga4-property-id"><?php esc_html_e( 'Property ID GA4', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="text" name="ga4_property_id" id="nxa-ga4-property-id" value="<?php echo esc_attr( $settings['ga4_property_id'] ); ?>" placeholder="123456789" class="regular-text code" />
                                                                <p class="description"><?php esc_html_e( 'Solo cifre (es. 123456789), come mostrato in Admin GA4 → Proprietà.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Sincronizzazione metriche', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <label for="nxa-metrics-sync-enabled">
                                                                        <input type="checkbox" name="metrics_sync_enabled" id="nxa-metrics-sync-enabled" value="1" <?php checked( (int) $settings['metrics_sync_enabled'], 1 ); ?> />
                                                                        <?php esc_html_e( 'Sincronizza GSC e GA4 una volta al giorno', 'nexus-news-ai' ); ?>
                                                                </label>
                                                                <p class="description"><?php esc_html_e( 'Sincronizzazione giornaliera via WP-Cron: le metriche degli articoli finiscono nella tabella nexus_metrics.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                        </tbody>
                                </table>
                        </div>
                </section>

                <section class="nxa-acc" data-nxa-acc="evergreen">
                        <button type="button" class="nxa-acc-toggle" aria-expanded="false" aria-controls="nxa-acc-panel-evergreen">
                                <span class="nxa-acc-label"><?php esc_html_e( 'Evergreen (pillar)', 'nexus-news-ai' ); ?></span>
                                <span class="nxa-acc-chevron" aria-hidden="true"></span>
                        </button>
                        <div class="nxa-acc-panel" id="nxa-acc-panel-evergreen" hidden>
                                <?php /* Sezione v1.1.12 (S8) "Evergreen (pillar)": aggiornamento periodico degli articoli pillar (chiavi del pacchetto S6, ?? difensivi in testa alla vista). */ ?>
                                <table class="form-table" role="presentation">
                                        <tbody>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Articoli evergreen', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <label for="nxa-evergreen-enabled">
                                                                        <input type="checkbox" name="evergreen_enabled" id="nxa-evergreen-enabled" value="1" <?php checked( $evergreen_enabled, 1 ); ?> />
                                                                        <?php esc_html_e( 'Aggiorna periodicamente gli articoli evergreen', 'nexus-news-ai' ); ?>
                                                                </label>
                                                                <p class="description"><?php esc_html_e( "Se attivo, gli articoli pillar vengono ricandidati a aggiornamento (dati, link e riferimenti freschi) invece di invecchiare nell'archivio.", 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-evergreen-refresh-days"><?php esc_html_e( 'Frequenza di aggiornamento (giorni)', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="number" name="evergreen_refresh_days" id="nxa-evergreen-refresh-days" value="<?php echo esc_attr( $evergreen_refresh_days ); ?>" min="7" step="1" class="small-text" />
                                                                <p class="description"><?php esc_html_e( 'Giorni minimi tra due aggiornamenti dello stesso articolo evergreen (minimo 7).', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-evergreen-max-posts"><?php esc_html_e( 'Articoli per ciclo', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="number" name="evergreen_max_posts" id="nxa-evergreen-max-posts" value="<?php echo esc_attr( $evergreen_max_posts ); ?>" min="1" max="10" step="1" class="small-text" />
                                                                <p class="description"><?php esc_html_e( 'Quanti articoli evergreen al massimo vengono aggiornati a ogni ciclo (1-10).', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                        </tbody>
                                </table>
                        </div>
                </section>

                <p class="submit">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Salva impostazioni', 'nexus-news-ai' ); ?></button>
                </p>
        </form>
</div>
<style>
  /* Barra Salva in cima (v1.1.6): solo respiro sotto l'h1, nessun colore aggiunto. */
  .nxa-submit-top {
    margin: 8px 0 16px;
  }
  /* S8 (v1.1.12): stato connessione Google — badge inline + azione. Nessun
     colore fisso: solo token del design system (tema chiaro default, scuro
     via body.nexus-theme-dark). */
  .nxa-gsc-status {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    margin: 4px 0 0;
  }
  .nxa-gsc-badge {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 999px;
    border: 1px solid var(--nxa-ok-line);
    background: var(--nxa-ok-bg);
    color: var(--nxa-ok-text);
    font-size: 12px;
    font-weight: 600;
    line-height: 1.6;
  }
</style>
<script>
( function () {
        'use strict';

        var STORAGE_KEY = 'nxa-settings-acc-sistema';
        var DEFAULT_OPEN = 'scala';

        function readSavedSlugs() {
                try {
                        var raw = window.localStorage.getItem( STORAGE_KEY );
                        if ( ! raw ) {
                                return null;
                        }
                        var parsed = JSON.parse( raw );
                        if ( ! Array.isArray( parsed ) ) {
                                return null;
                        }
                        return parsed;
                } catch ( err ) {
                        return null;
                }
        }

        function applyState( group, isOpen ) {
                var toggle = group.querySelector( '.nxa-acc-toggle' );
                var panel = group.querySelector( '.nxa-acc-panel' );
                if ( ! toggle || ! panel ) {
                        return;
                }
                toggle.setAttribute( 'aria-expanded', isOpen ? 'true' : 'false' );
                if ( isOpen ) {
                        panel.removeAttribute( 'hidden' );
                } else {
                        panel.setAttribute( 'hidden', 'hidden' );
                }
        }

        function saveOpenSlugs() {
                var groups = document.querySelectorAll( '.nxa-acc[data-nxa-acc]' );
                var open = [];
                for ( var i = 0; i < groups.length; i++ ) {
                        var toggle = groups[ i ].querySelector( '.nxa-acc-toggle' );
                        if ( toggle && toggle.getAttribute( 'aria-expanded' ) === 'true' ) {
                                var slug = groups[ i ].getAttribute( 'data-nxa-acc' );
                                if ( slug ) {
                                        open.push( slug );
                                }
                        }
                }
                try {
                        window.localStorage.setItem( STORAGE_KEY, JSON.stringify( open ) );
                } catch ( err ) {
                        window.console.warn( '[Nexus] impossibile salvare lo stato dei gruppi impostazioni (localStorage).' );
                }
        }

        function restoreState() {
                var groups = document.querySelectorAll( '.nxa-acc[data-nxa-acc]' );
                if ( ! groups.length ) {
                        return;
                }
                var saved = readSavedSlugs();
                var openMap = {};
                if ( saved ) {
                        for ( var i = 0; i < saved.length; i++ ) {
                                if ( typeof saved[ i ] === 'string' ) {
                                        openMap[ saved[ i ] ] = true;
                                }
                        }
                } else {
                        openMap[ DEFAULT_OPEN ] = true;
                }
                for ( var j = 0; j < groups.length; j++ ) {
                        var slug = groups[ j ].getAttribute( 'data-nxa-acc' );
                        applyState( groups[ j ], openMap[ slug ] === true );
                }
        }

        function onClick( ev ) {
                var target = ev.target;
                var toggle = target && target.closest ? target.closest( '.nxa-acc-toggle' ) : null;
                if ( ! toggle ) {
                        return;
                }
                var group = toggle.closest ? toggle.closest( '.nxa-acc' ) : null;
                if ( ! group ) {
                        return;
                }
                applyState( group, toggle.getAttribute( 'aria-expanded' ) !== 'true' );
                saveOpenSlugs();
        }

        restoreState();
        document.addEventListener( 'click', onClick );
} )();
</script>
