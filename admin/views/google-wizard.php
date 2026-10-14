<?php
/**
 * Vista Admin: pagina "Google" (wizard guidato, slug nexus-google-wizard).
 *
 * v1.1.14 (P1): pagina unica con 3 sezioni —
 *  1. Stato connessione Google (badge + stato token onesto, link al flusso
 *     OAuth esistente in Impostazioni → tab sistema; READ-ONLY);
 *  2. Validazione property Search Console LIVE: input URL + "Verifica ora" →
 *     GET /nexus/v1/google/wizard/property (lista property + match +
 *     salvataggio SOLO dopo verifica OK sulla chiave 'gsc_site_url');
 *  3. Stato sincronizzazione metriche (cron, property GSC/GA4, ultimo giro da
 *     nexus_metrics, totali) + bottone "Sincronizza ora" → POST
 *     /nexus/v1/google/wizard/sync (chiama Nexus_Metrics::run_sync(), API
 *     pubblica; esito onesto con righe nuove + log attività del giro).
 *
 * STATI INIZIALI SERVER-SIDE: la pagina nasce già corretta da
 * Nexus_Google_Wizard::page_state() (nessuno skeleton vuoto, MAI zeri finti);
 * lo script inline ES5 aggiorna SOLO dopo le azioni dell'utente.
 *
 * CONTRATTO CON admin.js (pagina nexus): lo script usa il ponte pubblico
 * window.NexusAdminUi (conferme inline) e NexusAdminData (restUrl + nonce
 * 'wp_rest' per X-WP-Nonce). Nessun window.confirm/alert/prompt.
 * Le stringhe italiane nello script vengono tradotte in EN dall'observer di
 * i18n.js (dict esatto + pattern, v1.0.5): qui restano ITALIANE (fonte).
 *
 * SICUREZZA: ogni valore dinamico stampato passa da esc_html/esc_attr/esc_url
 * o dall'helper JS esc() prima di innerHTML; il token OAuth non compare MAI
 * in pagina.
 *
 * Container root: <div class="wrap nexus-admin nxa-gw" data-page="google-wizard">
 * (data-page con valore dedicato: admin.js non ha loader per questa pagina e
 * resta fermo — le interazioni sono tutte di questo script inline).
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Nexus_Google_Wizard' ) || ! method_exists( 'Nexus_Google_Wizard', 'page_state' ) ) {
        echo '<div class="notice notice-warning"><p>' .
                esc_html__( 'Modulo Google Wizard non disponibile: controlla che il plugin sia aggiornato.', 'nexus-news-ai' ) .
        '</p></div>';
        return;
}

$nxa_gw_state = Nexus_Google_Wizard::page_state();

$nxa_gw_token_labels = array(
        'ok'             => __( 'Token di accesso presente e valido.', 'nexus-news-ai' ),
        'refresh_failed' => __( 'Token scaduto e rinnovo automatico fallito: riconnetti Google dal flusso OAuth.', 'nexus-news-ai' ),
        'missing'        => __( 'Nessun token salvato: collega Google per iniziare.', 'nexus-news-ai' ),
);

$nxa_gw_token_label = isset( $nxa_gw_token_labels[ $nxa_gw_state['token_state'] ] )
        ? $nxa_gw_token_labels[ $nxa_gw_state['token_state'] ]
        : $nxa_gw_token_labels['missing'];
?>
<div class="wrap nexus-admin nxa-gw" data-page="google-wizard">
        <h1><?php esc_html_e( 'Google', 'nexus-news-ai' ); ?></h1>
        <p class="nxa-gw-sub"><?php esc_html_e( 'Stato della connessione Google, verifica guidata della property Search Console e sincronizzazione manuale delle metriche.', 'nexus-news-ai' ); ?></p>

        <?php /* ============ 1. STATO CONNESSIONE (READ-ONLY) ============ */ ?>
        <section class="nxa-gw-card" id="nxa-gw-conn-card">
                <h2><?php esc_html_e( 'Stato connessione', 'nexus-news-ai' ); ?></h2>
                <p class="nxa-gw-conn-row">
                        <span class="nxa-gw-badge<?php echo $nxa_gw_state['google_connected'] ? ' nxa-gw-badge--ok' : ' nxa-gw-badge--err'; ?>" id="nxa-gw-conn-badge">
                                <?php echo $nxa_gw_state['google_connected'] ? esc_html__( 'Connesso', 'nexus-news-ai' ) : esc_html__( 'Non connesso', 'nexus-news-ai' ); ?>
                        </span>
                        <span class="nxa-gw-token-note" id="nxa-gw-token-note"><?php echo esc_html( $nxa_gw_token_label ); ?></span>
                </p>
                <p class="nxa-gw-hint">
                        <?php esc_html_e( 'Il collegamento si gestisce dal flusso OAuth esistente (Impostazioni → tab Sistema). Questa pagina è di sola lettura: non tocca il token.', 'nexus-news-ai' ); ?>
                        <a href="<?php echo esc_url( $nxa_gw_state['settings_url'] ); ?>"><?php esc_html_e( 'Apri Impostazioni → Sistema', 'nexus-news-ai' ); ?></a>
                </p>
        </section>

        <?php /* ============ 2. VALIDAZIONE PROPERTY LIVE ============ */ ?>
        <section class="nxa-gw-card" id="nxa-gw-prop-card">
                <h2><?php esc_html_e( 'Property Search Console', 'nexus-news-ai' ); ?></h2>
                <p class="nxa-gw-hint"><?php esc_html_e( 'Verifica live che la property GSC sia raggiungibile dall\'account Google collegato. Il valore viene salvato SOLO se la verifica ha esito positivo; poi la sync delle metriche lo usa senza altre configurazioni.', 'nexus-news-ai' ); ?></p>

                <p class="nxa-gw-current">
                        <strong><?php esc_html_e( 'Property salvata:', 'nexus-news-ai' ); ?></strong>
                        <code id="nxa-gw-saved"><?php echo '' !== $nxa_gw_state['gsc_site_url'] ? esc_html( $nxa_gw_state['gsc_site_url'] ) : esc_html__( 'Non configurata', 'nexus-news-ai' ); ?></code>
                </p>

                <label class="nxa-gw-label" for="nxa-gw-input"><?php esc_html_e( 'URL property', 'nexus-news-ai' ); ?></label>
                <p class="nxa-gw-input-row">
                        <input type="text" id="nxa-gw-input" class="nxa-gw-input" value="<?php echo esc_attr( $nxa_gw_state['gsc_site_url'] ); ?>" placeholder="https://esempio.com/" />
                        <button type="button" class="button button-primary" id="nxa-gw-verify"><?php esc_html_e( 'Verifica ora', 'nexus-news-ai' ); ?></button>
                </p>
                <p class="nxa-gw-hint"><?php esc_html_e( 'Esempi: https://esempio.com/ (property URL) oppure sc-domain:esempio.com (property dominio).', 'nexus-news-ai' ); ?></p>

                <div class="nxa-gw-result" id="nxa-gw-result" aria-live="polite"></div>
                <div class="nxa-gw-list" id="nxa-gw-list" aria-live="polite"></div>
        </section>

        <?php /* ============ 3. STATO SYNC + SINCRONIZZA ORA ============ */ ?>
        <section class="nxa-gw-card" id="nxa-gw-sync-card">
                <h2><?php esc_html_e( 'Sincronizzazione metriche', 'nexus-news-ai' ); ?></h2>

                <dl class="nxa-gw-stats">
                        <dt><?php esc_html_e( 'Sync giornaliera (cron)', 'nexus-news-ai' ); ?></dt>
                        <dd id="nxa-gw-cron">
                                <?php if ( $nxa_gw_state['cron_scheduled'] ) : ?>
                                        <span class="nxa-gw-badge nxa-gw-badge--ok"><?php esc_html_e( 'Attiva', 'nexus-news-ai' ); ?></span>
                                        <span class="nxa-gw-next" id="nxa-gw-cron-next"><?php echo esc_html( sprintf( __( 'Prossimo giro: %s', 'nexus-news-ai' ), $nxa_gw_state['cron_next_run'] ) ); ?></span>
                                <?php else : ?>
                                        <span class="nxa-gw-badge nxa-gw-badge--muted"><?php esc_html_e( 'Non schedulata', 'nexus-news-ai' ); ?></span>
                                        <span class="nxa-gw-next"><?php esc_html_e( 'Attivala con "Sincronizza GSC e GA4 una volta al giorno" in Impostazioni → Sistema.', 'nexus-news-ai' ); ?></span>
                                <?php endif; ?>
                        </dd>

                        <dt><?php esc_html_e( 'Property GSC', 'nexus-news-ai' ); ?></dt>
                        <dd id="nxa-gw-gsc-state">
                                <?php echo '' !== $nxa_gw_state['gsc_site_url'] ? '<code>' . esc_html( $nxa_gw_state['gsc_site_url'] ) . '</code>' : '<span class="nxa-gw-empty">' . esc_html__( 'Non configurata', 'nexus-news-ai' ) . '</span>'; ?>
                        </dd>

                        <dt><?php esc_html_e( 'Property GA4', 'nexus-news-ai' ); ?></dt>
                        <dd id="nxa-gw-ga4-state">
                                <?php echo '' !== $nxa_gw_state['ga4_property_id'] ? '<code>' . esc_html( $nxa_gw_state['ga4_property_id'] ) . '</code>' : '<span class="nxa-gw-empty">' . esc_html__( 'Non configurata', 'nexus-news-ai' ) . '</span>'; ?>
                        </dd>

                        <dt><?php esc_html_e( 'Ultimo giro di metriche', 'nexus-news-ai' ); ?></dt>
                        <dd id="nxa-gw-last">
                                <?php if ( $nxa_gw_state['sync']['has_data'] ) : ?>
                                        <?php
                                        $nxa_gw_src = array();
                                        if ( isset( $nxa_gw_state['sync']['last_date_by_source']['gsc'] ) ) {
                                                /* translators: %d: numero di righe GSC del giorno. */
                                                $nxa_gw_src[] = sprintf( __( 'gsc %d righe', 'nexus-news-ai' ), absint( $nxa_gw_state['sync']['last_date_by_source']['gsc'] ) );
                                        }
                                        if ( isset( $nxa_gw_state['sync']['last_date_by_source']['ga4'] ) ) {
                                                /* translators: %d: numero di righe GA4 del giorno. */
                                                $nxa_gw_src[] = sprintf( __( 'ga4 %d righe', 'nexus-news-ai' ), absint( $nxa_gw_state['sync']['last_date_by_source']['ga4'] ) );
                                        }
                                        echo esc_html( sprintf(
                                                /* translators: 1: data metriche, 2: numero righe del giorno, 3: dettaglio sorgenti. */
                                                __( '%1$s — %2$d righe (%3$s)', 'nexus-news-ai' ),
                                                $nxa_gw_state['sync']['last_date'],
                                                absint( $nxa_gw_state['sync']['last_date_rows'] ),
                                                implode( ', ', $nxa_gw_src )
                                        ) );
                                        ?>
                                <?php else : ?>
                                        <span class="nxa-gw-empty"><?php esc_html_e( 'Mai eseguito: nessuna riga nella tabella metriche.', 'nexus-news-ai' ); ?></span>
                                <?php endif; ?>
                        </dd>

                        <dt><?php esc_html_e( 'Totale righe metriche', 'nexus-news-ai' ); ?></dt>
                        <dd id="nxa-gw-total">
                                <?php if ( $nxa_gw_state['sync']['has_data'] ) : ?>
                                        <?php echo esc_html( sprintf(
                                                /* translators: 1: totale righe, 2: numero articoli con metriche. */
                                                __( '%1$d righe su %2$d articoli', 'nexus-news-ai' ),
                                                absint( $nxa_gw_state['sync']['total_rows'] ),
                                                absint( $nxa_gw_state['sync']['posts_total'] )
                                        ) ); ?>
                                <?php else : ?>
                                        <span class="nxa-gw-empty"><?php esc_html_e( 'Nessun dato ancora sincronizzato.', 'nexus-news-ai' ); ?></span>
                                <?php endif; ?>
                        </dd>
                </dl>

                <p class="nxa-gw-input-row">
                        <button type="button" class="button button-primary" id="nxa-gw-sync"><?php esc_html_e( 'Sincronizza ora', 'nexus-news-ai' ); ?></button>
                </p>
                <p class="nxa-gw-hint"><?php esc_html_e( 'La sincronizzazione manuale esegue lo stesso giro del cron (può richiedere qualche decina di secondi).', 'nexus-news-ai' ); ?></p>

                <div class="nxa-gw-result" id="nxa-gw-sync-result" aria-live="polite"></div>
        </section>
</div>
<script>
(function () {
        'use strict';

        /* Guardia sulla piattaforma admin (restUrl + nonce sono di Nexus_Admin). */
        if ( typeof window.NexusAdminData === 'undefined' ) {
                if ( window.console && window.console.warn ) {
                        window.console.warn( '[Nexus News AI] NexusAdminData non disponibile: wizard Google inattivo.' );
                }
                return;
        }

        var base = String( window.NexusAdminData.restUrl || '' );
        var nonce = String( window.NexusAdminData.nonce || '' );

        if ( ! base || ! nonce ) {
                if ( window.console && window.console.warn ) {
                        window.console.warn( '[Nexus News AI] REST/nonce mancanti: wizard Google inattivo.' );
                }
                return;
        }

        var input = document.getElementById( 'nxa-gw-input' );
        var verifyBtn = document.getElementById( 'nxa-gw-verify' );
        var resultBox = document.getElementById( 'nxa-gw-result' );
        var listBox = document.getElementById( 'nxa-gw-list' );
        var savedNode = document.getElementById( 'nxa-gw-saved' );
        var syncBtn = document.getElementById( 'nxa-gw-sync' );
        var syncResult = document.getElementById( 'nxa-gw-sync-result' );
        var connBadge = document.getElementById( 'nxa-gw-conn-badge' );
        var tokenNote = document.getElementById( 'nxa-gw-token-note' );
        var cronNode = document.getElementById( 'nxa-gw-cron' );
        var gscState = document.getElementById( 'nxa-gw-gsc-state' );
        var ga4State = document.getElementById( 'nxa-gw-ga4-state' );
        var lastNode = document.getElementById( 'nxa-gw-last' );
        var totalNode = document.getElementById( 'nxa-gw-total' );

        var busy = false;

        /* Escape di ogni valore dinamico prima di innerHTML (contratto T-05). */
        function esc( value ) {
                return String( value === null || value === undefined ? '' : value )
                        .replace( /&/g, '&amp;' )
                        .replace( /</g, '&lt;' )
                        .replace( />/g, '&gt;' )
                        .replace( /\x22/g, '&quot;' )
                        .replace( /\x27/g, '&#039;' );
        }

        /* Wrapper fetch: nonce X-WP-Nonce + JSON; errore HTTP → Error col messaggio. */
        function api( path, opts ) {
                opts = opts || {};
                var init = {
                        method: opts.method || 'GET',
                        headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
                        credentials: 'same-origin'
                };
                if ( opts.body ) {
                        init.body = opts.body;
                }
                return fetch( base + path, init ).then( function ( response ) {
                        return response.text().then( function ( text ) {
                                var data = null;
                                try {
                                        data = text ? JSON.parse( text ) : null;
                                } catch ( parseError ) {
                                        data = null;
                                }
                                if ( ! response.ok ) {
                                        var message = 'Errore HTTP ' + response.status;
                                        if ( data && data.message ) {
                                                message = String( data.message );
                                        }
                                        throw new Error( message );
                                }
                                return data;
                        } );
                } );
        }

        /* Blocco di esito con severità fissa (ok / warn / err / info). */
        function alertBox( box, severity, message ) {
                if ( ! box ) {
                        return;
                }
                var cls = 'nxa-gw-alert nxa-gw-alert--' + ( severity === 'ok' || severity === 'warn' || severity === 'info' ? severity : 'err' );
                box.innerHTML = '<p class="' + cls + '">' + esc( message ) + '</p>';
        }

        /* Nota di log attività (righe oneste scritte dal modulo metrics). */
        function activityHtml( rows ) {
                if ( ! rows || ! rows.length ) {
                        return '';
                }
                var html = '<p class="nxa-gw-log-title">Log del giro:</p><ul class="nxa-gw-log">';
                var i;
                for ( i = 0; i < rows.length && i < 5; i++ ) {
                        html += '<li><span class="nxa-gw-log-status nxa-gw-log-status--' + esc( rows[i].status ) + '">' + esc( rows[i].status ) + '</span> ' + esc( rows[i].detail ) + '</li>';
                }
                return html + '</ul>';
        }

        /* Lista property disponibili con selezione 1-click. */
        function renderList( properties, savedUrl ) {
                if ( ! listBox ) {
                        return;
                }
                if ( ! properties || ! properties.length ) {
                        listBox.innerHTML = '<p class="nxa-gw-list-note">Nessuna property disponibile nell\'account collegato: verifica la proprietà in Search Console o usa un account diverso.</p>';
                        return;
                }
                var html = '<p class="nxa-gw-list-title">Property disponibili nell\'account:</p><ul class="nxa-gw-list-items">';
                var i;
                var item;
                for ( i = 0; i < properties.length; i++ ) {
                        item = properties[i];
                        html += '<li class="nxa-gw-list-item' + ( item.matched ? ' is-match' : '' ) + ( savedUrl === item.site_url ? ' is-saved' : '' ) + '">' +
                                '<code class="nxa-gw-list-url">' + esc( item.site_url ) + '</code>' +
                                '<span class="nxa-gw-list-perm">' + esc( item.permission || '—' ) + '</span>' +
                                ( item.matched ? '<span class="nxa-gw-badge nxa-gw-badge--ok">Trovata</span>' : '' ) +
                                '<button type="button" class="button button-small nxa-gw-use" data-nxa-gw-site="' + esc( item.site_url ) + '">Usa questa property</button>' +
                                '</li>';
                }
                listBox.innerHTML = html + '</ul>';
        }

        /* Ristampa i valori salvati in pagina dopo una verifica riuscita. */
        function applySaved( siteUrl ) {
                if ( savedNode ) {
                        savedNode.textContent = siteUrl;
                }
                if ( input && siteUrl ) {
                        input.value = siteUrl;
                }
                if ( gscState ) {
                        gscState.innerHTML = '<code>' + esc( siteUrl ) + '</code>';
                }
        }

        /* Aggiorna i pannelli di stato con lo state completo delle REST. */
        function applyState( state ) {
                if ( ! state ) {
                        return;
                }

                var sync = state.sync || {};
                var src = sync.last_date_by_source || {};
                var parts = [];

                if ( connBadge ) {
                        connBadge.className = 'nxa-gw-badge ' + ( state.google_connected ? 'nxa-gw-badge--ok' : 'nxa-gw-badge--err' );
                        connBadge.textContent = state.google_connected ? 'Connesso' : 'Non connesso';
                }
                if ( tokenNote ) {
                        if ( state.token_state === 'ok' ) {
                                tokenNote.textContent = 'Token di accesso presente e valido.';
                        } else if ( state.token_state === 'refresh_failed' ) {
                                tokenNote.textContent = 'Token scaduto e rinnovo automatico fallito: riconnetti Google dal flusso OAuth.';
                        } else {
                                tokenNote.textContent = 'Nessun token salvato: collega Google per iniziare.';
                        }
                }
                if ( cronNode ) {
                        if ( state.cron_scheduled ) {
                                cronNode.innerHTML = '<span class="nxa-gw-badge nxa-gw-badge--ok">Attiva</span> <span class="nxa-gw-next">Prossimo giro: ' + esc( state.cron_next_run || '—' ) + '</span>';
                        } else {
                                cronNode.innerHTML = '<span class="nxa-gw-badge nxa-gw-badge--muted">Non schedulata</span> <span class="nxa-gw-next">Attivala con "Sincronizza GSC e GA4 una volta al giorno" in Impostazioni → Sistema.</span>';
                        }
                }
                if ( gscState ) {
                        gscState.innerHTML = state.gsc_site_url ? '<code>' + esc( state.gsc_site_url ) + '</code>' : '<span class="nxa-gw-empty">Non configurata</span>';
                }
                if ( ga4State ) {
                        ga4State.innerHTML = state.ga4_property_id ? '<code>' + esc( state.ga4_property_id ) + '</code>' : '<span class="nxa-gw-empty">Non configurata</span>';
                }
                if ( lastNode ) {
                        if ( sync.has_data ) {
                                if ( src.gsc !== undefined ) {
                                        parts.push( 'gsc ' + parseInt( src.gsc, 10 ) + ' righe' );
                                }
                                if ( src.ga4 !== undefined ) {
                                        parts.push( 'ga4 ' + parseInt( src.ga4, 10 ) + ' righe' );
                                }
                                lastNode.textContent = sync.last_date + ' — ' + ( parseInt( sync.last_date_rows, 10 ) || 0 ) + ' righe (' + parts.join( ', ' ) + ')';
                        } else {
                                lastNode.innerHTML = '<span class="nxa-gw-empty">Mai eseguito: nessuna riga nella tabella metriche.</span>';
                        }
                }
                if ( totalNode ) {
                        if ( sync.has_data ) {
                                totalNode.textContent = ( parseInt( sync.total_rows, 10 ) || 0 ) + ' righe su ' + ( parseInt( sync.posts_total, 10 ) || 0 ) + ' articoli';
                        } else {
                                totalNode.innerHTML = '<span class="nxa-gw-empty">Nessun dato ancora sincronizzato.</span>';
                        }
                }
        }

        /* Azione con bottone in busy-state (disabilitato + etichetta provvisoria);
         * gli errori finiscono nel box di esito della sezione chiamante. */
        function withBusy( btn, label, errorBox, run ) {
                if ( busy ) {
                        return Promise.resolve();
                }
                busy = true;
                var original = btn ? btn.textContent : '';
                if ( btn ) {
                        btn.disabled = true;
                        btn.textContent = label;
                }
                return Promise.resolve()
                        .then( run )
                        .catch( function ( error ) {
                                alertBox( errorBox, 'err', error && error.message ? String( error.message ) : 'Errore sconosciuto' );
                        } )
                        .then( function () {
                                busy = false;
                                if ( btn ) {
                                        btn.disabled = false;
                                        btn.textContent = original;
                                }
                        } );
        }

        /* Verifica property: con url='' interroga solo la lista. */
        function verify( url, btn ) {
                var path = '/google/wizard/property';
                if ( url ) {
                        path += '?property=' + encodeURIComponent( url );
                }
                return withBusy( btn, 'Verifica in corso…', resultBox, function () {
                        alertBox( resultBox, 'info', 'Interrogo Search Console…' );
                        return api( path ).then( function ( data ) {
                                if ( ! data ) {
                                        throw new Error( 'Risposta vuota dal server.' );
                                }
                                renderList( data.properties, data.saved ? data.site_url : ( data.state && data.state.gsc_site_url ? data.state.gsc_site_url : '' ) );
                                if ( ! data.ok ) {
                                        var severity = ( data.code === 'not_found' || data.code === 'forbidden' ) ? 'warn' : 'err';
                                        alertBox( resultBox, severity, data.message || 'Verifica non riuscita.' );
                                        return;
                                }
                                if ( data.found ) {
                                        alertBox( resultBox, data.saved ? 'ok' : 'warn', data.message || ( data.saved ? 'Property verificata e salvata.' : 'Property verificata ma salvataggio non riuscito.' ) );
                                        if ( data.saved && data.site_url ) {
                                                applySaved( data.site_url );
                                        }
                                        if ( data.state ) {
                                                applyState( data.state );
                                        }
                                        return;
                                }
                                alertBox( resultBox, 'info', 'Lista property caricata: seleziona quella del sito o digita l\'URL e verifica.' );
                        } );
                } );
        }

        /* Sincronizzazione manuale con esito onesto (righe + log del giro). */
        function runSync() {
                return withBusy( syncBtn, 'Sincronizzazione in corso…', syncResult, function () {
                        alertBox( syncResult, 'info', 'Sincronizzazione avviata: può richiedere qualche decina di secondi…' );
                        return api( '/google/wizard/sync', { method: 'POST' } ).then( function ( data ) {
                                if ( ! data ) {
                                        throw new Error( 'Risposta vuota dal server.' );
                                }
                                if ( ! data.ok ) {
                                        alertBox( syncResult, 'err', data.message || 'Sincronizzazione non riuscita.' );
                                        return;
                                }
                                syncResult.innerHTML = '<p class="nxa-gw-alert nxa-gw-alert--ok">' + esc( data.message || 'Sincronizzazione completata.' ) + '</p>' + activityHtml( data.activities );
                                if ( data.state ) {
                                        applyState( data.state );
                                }
                        } );
                } );
        }

        if ( verifyBtn ) {
                verifyBtn.addEventListener( 'click', function () {
                        verify( input ? String( input.value || '' ).replace( /^\s+|\s+$/g, '' ) : '', verifyBtn );
                } );
        }

        if ( listBox ) {
                listBox.addEventListener( 'click', function ( event ) {
                        var target = event.target;
                        if ( ! target || ! target.closest ) {
                                return;
                        }
                        var btn = target.closest( '[data-nxa-gw-site]' );
                        if ( ! btn || btn.disabled ) {
                                return;
                        }
                        /* Selezione 1-click: la property scelta passa dalla STESSA
                         * verifica live (salvataggio SOLO a esito OK, lato server). */
                        verify( String( btn.getAttribute( 'data-nxa-gw-site' ) || '' ), btn );
                } );
        }

        if ( syncBtn ) {
                syncBtn.addEventListener( 'click', runSync );
        }
})();
</script>
