<?php
/**
 * Vista Admin: Impostazioni sistema (pagina "settings-sistema" di Nexus News AI).
 *
 * v1.1.6 (contratto T-02): le Impostazioni diventano 4 sottomenu WP; questa è
 * la pagina del gruppo "Sito & sistema" (accordion: scala, language-api,
 * interface) e raccoglie l'ultima parte della vecchia settings.php (rimossa
 * in v1.1.6).
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
?>
<div class="wrap nexus-admin" data-page="settings">
        <h1><?php esc_html_e( 'Impostazioni sistema', 'nexus-news-ai' ); ?></h1>

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
