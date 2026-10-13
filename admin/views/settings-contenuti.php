<?php
/**
 * Vista Admin: Impostazioni contenuti (pagina "settings-contenuti" di Nexus News AI).
 *
 * v1.1.6 (contratto T-02): le Impostazioni diventano 4 sottomenu WP; questa è
 * la pagina del gruppo "Pipeline editoriale" (accordion: pipeline, limits) e
 * raccoglie la prima parte della vecchia settings.php (rimossa in v1.1.6).
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
 * - I 4 toggle (auto_collect, auto_evaluate, auto_rewrite, auto_publish) sono
 *   checkbox value="1": admin.js li serializza come 0/1 (0 quando deselezionati).
 * - Gli altri campi usano name = chiave esatta di Nexus_DB::default_settings():
 *   mode, collect_interval, max_articles_per_day, auto_approve_score.
 *   v1.1.11 (R1): + global_rss_sources (textarea "Fonti RSS globali", sezione
 *   "fonti"): chiave STRINGA di Nexus_DB::default_settings(), una URL http(s)
 *   per riga, whitelist REST già estesa in Nexus_Rest::sanitize_settings_payload.
 *   Il salvataggio ora è quello STANDARD di admin.js: dalla v1.1.11 (integrazione
 *   merge) la chiave 'global_rss_sources' è inclusa nell'array `simple` di
 *   collectSettings, quindi il micro-script PONTE temporaneo della wave è stato
 *   rimosso (era solo un wrapper fetch una-tantum in fondo a questa vista).
 * - Bottone SALVA IN CIMA (subito sotto l'h1) OLTRE quello in fondo: entrambi
 *   type="submit" nella stessa form; admin.js v1.1.6 mostra lo stato di
 *   caricamento su tutti i submit della form.
 * - Sezioni accordion section.nxa-acc[data-nxa-acc] con
 *   button.nxa-acc-toggle[aria-expanded][aria-controls], span.nxa-acc-label,
 *   span.nxa-acc-chevron, div.nxa-acc-panel[hidden]. Lo stato dei gruppi
 *   (array slug aperti) è persistito in localStorage['nxa-settings-acc-contenuti']
 *   (chiave propria della pagina, JSON con try/catch) dall'IIFE ES5 in fondo
 *   al file (solo toggle accordion, nessun fetch); default senza localStorage:
 *   pannello "pipeline" aperto, gli altri chiusi (stato renderizzato anche
 *   server-side, così senza JS la prima sezione resta leggibile; v1.1.11 (R1):
 *   il gruppo nuovo "fonti" segue la stessa regola, chiuso di default). Le
 *   classi accordion sono definite nel CSS condiviso: qui solo il micro-stile
 *   della barra Salva in cima.
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

$settings = Nexus_DB::get_settings();

// v1.1.11 (R1): fonti RSS globali (STRINGA, una URL http(s) per riga; get_settings
// mergia già i default, il guard isset è difensivo).
$global_rss_sources = isset( $settings['global_rss_sources'] ) ? (string) $settings['global_rss_sources'] : '';
?>
<div class="wrap nexus-admin" data-page="settings">
        <h1><?php esc_html_e( 'Impostazioni contenuti', 'nexus-news-ai' ); ?></h1>

        <form id="nxa-settings-form" class="nxa-settings-form">
                <p class="submit nxa-submit-top">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Salva impostazioni', 'nexus-news-ai' ); ?></button>
                </p>

                <section class="nxa-acc" data-nxa-acc="pipeline">
                        <button type="button" class="nxa-acc-toggle" aria-expanded="true" aria-controls="nxa-acc-panel-pipeline">
                                <span class="nxa-acc-label"><?php esc_html_e( 'Pipeline editoriale', 'nexus-news-ai' ); ?></span>
                                <span class="nxa-acc-chevron" aria-hidden="true"></span>
                        </button>
                        <div class="nxa-acc-panel" id="nxa-acc-panel-pipeline">
                                <table class="form-table" role="presentation">
                                        <tbody>
                                                <tr>
                                                        <th scope="row"><label for="nxa-mode"><?php esc_html_e( 'Modalità operativa', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <select name="mode" id="nxa-mode">
                                                                        <option value="semi_autonomous" <?php selected( $settings['mode'], 'semi_autonomous' ); ?>><?php esc_html_e( 'Semi-autonoma (approvazione umana)', 'nexus-news-ai' ); ?></option>
                                                                        <option value="fully_autonomous" <?php selected( $settings['mode'], 'fully_autonomous' ); ?>><?php esc_html_e( 'Completamente autonoma', 'nexus-news-ai' ); ?></option>
                                                                </select>
                                                                <p class="description"><?php esc_html_e( "Semi-autonoma: la redazione AI lavora ma gli articoli attendono l'approvazione umana. Completamente autonoma: pubblicazione automatica oltre la soglia di qualità.", 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Raccolta automatica', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <label for="nxa-auto-collect">
                                                                        <input type="checkbox" name="auto_collect" id="nxa-auto-collect" value="1" <?php checked( (int) $settings['auto_collect'], 1 ); ?> />
                                                                        <?php esc_html_e( 'Attiva', 'nexus-news-ai' ); ?>
                                                                </label>
                                                                <p class="description"><?php esc_html_e( 'La pipeline raccoglie automaticamente nuovi articoli dalle fonti RSS configurate.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Valutazione automatica', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <label for="nxa-auto-evaluate">
                                                                        <input type="checkbox" name="auto_evaluate" id="nxa-auto-evaluate" value="1" <?php checked( (int) $settings['auto_evaluate'], 1 ); ?> />
                                                                        <?php esc_html_e( 'Attiva', 'nexus-news-ai' ); ?>
                                                                </label>
                                                                <p class="description"><?php esc_html_e( "Gli articoli raccolti vengono valutati automaticamente dall'AI (qualità e pertinenza editoriale).", 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Riscrittura automatica', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <label for="nxa-auto-rewrite">
                                                                        <input type="checkbox" name="auto_rewrite" id="nxa-auto-rewrite" value="1" <?php checked( (int) $settings['auto_rewrite'], 1 ); ?> />
                                                                        <?php esc_html_e( 'Attiva', 'nexus-news-ai' ); ?>
                                                                </label>
                                                                <p class="description"><?php esc_html_e( 'Gli articoli valutati vengono riscritti in stile editoriale dalla redazione AI.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Pubblicazione automatica', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <label for="nxa-auto-publish">
                                                                        <input type="checkbox" name="auto_publish" id="nxa-auto-publish" value="1" <?php checked( (int) $settings['auto_publish'], 1 ); ?> />
                                                                        <?php esc_html_e( 'Attiva', 'nexus-news-ai' ); ?>
                                                                </label>
                                                                <p class="description"><?php esc_html_e( 'Gli articoli riscritti vengono pubblicati automaticamente, senza attesa umana.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                        </tbody>
                                </table>
                        </div>
                </section>

                <section class="nxa-acc" data-nxa-acc="fonti">
                        <button type="button" class="nxa-acc-toggle" aria-expanded="false" aria-controls="nxa-acc-panel-fonti">
                                <span class="nxa-acc-label"><?php esc_html_e( 'Fonti RSS globali', 'nexus-news-ai' ); ?></span>
                                <span class="nxa-acc-chevron" aria-hidden="true"></span>
                        </button>
                        <div class="nxa-acc-panel" id="nxa-acc-panel-fonti" hidden>
                                <table class="form-table" role="presentation">
                                        <tbody>
                                                <tr>
                                                        <th scope="row"><label for="nxa-global-rss-sources"><?php esc_html_e( 'Feed RSS globali (una URL per riga)', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <textarea name="global_rss_sources" id="nxa-global-rss-sources" rows="5" class="large-text code" placeholder="https://esempio.it/feed/"><?php echo esc_textarea( $global_rss_sources ); ?></textarea>
                                                                <p class="description"><?php esc_html_e( 'Usate dagli agenti che non hanno fonti nella propria redazione (una URL http(s) per riga; le righe che iniziano con # sono commenti). Se vuote, nessuna raccolta automatica: configura le fonti delle redazioni o queste globali.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                        </tbody>
                                </table>
                        </div>
                </section>

                <section class="nxa-acc" data-nxa-acc="limits">
                        <button type="button" class="nxa-acc-toggle" aria-expanded="false" aria-controls="nxa-acc-panel-limits">
                                <span class="nxa-acc-label"><?php esc_html_e( 'Limiti e qualità', 'nexus-news-ai' ); ?></span>
                                <span class="nxa-acc-chevron" aria-hidden="true"></span>
                        </button>
                        <div class="nxa-acc-panel" id="nxa-acc-panel-limits" hidden>
                                <table class="form-table" role="presentation">
                                        <tbody>
                                                <tr>
                                                        <th scope="row"><label for="nxa-collect-interval"><?php esc_html_e( 'Intervallo di raccolta (secondi)', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="number" name="collect_interval" id="nxa-collect-interval" value="<?php echo esc_attr( $settings['collect_interval'] ); ?>" min="300" step="1" class="small-text" />
                                                                <p class="description"><?php esc_html_e( 'Intervallo in secondi tra i cicli di raccolta automatica degli RSS (minimo 300 = 5 minuti, predefinito 3600 = 1 ora).', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-max-articles"><?php esc_html_e( 'Articoli massimi al giorno', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="number" name="max_articles_per_day" id="nxa-max-articles" value="<?php echo esc_attr( $settings['max_articles_per_day'] ); ?>" min="1" step="1" class="small-text" />
                                                                <p class="description"><?php esc_html_e( 'Numero massimo di articoli pubblicati automaticamente in un giorno.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-auto-approve-score"><?php esc_html_e( 'Soglia di auto-approvazione', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="number" name="auto_approve_score" id="nxa-auto-approve-score" value="<?php echo esc_attr( $settings['auto_approve_score'] ); ?>" min="0" max="100" step="1" class="small-text" />
                                                                <p class="description"><?php esc_html_e( 'In modalità autonoma, approva e pubblica oltre questa soglia (0-100).', 'nexus-news-ai' ); ?></p>
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

        var STORAGE_KEY = 'nxa-settings-acc-contenuti';
        var DEFAULT_OPEN = 'pipeline';

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
