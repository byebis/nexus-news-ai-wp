<?php
/**
 * Vista Admin: Impostazioni (pagina unica a tab "settings" di Nexus News AI).
 *
 * v1.1.11 (R2): le 4 pagine Impostazioni (contenuti|ai|pubblicazione|sistema)
 * diventano UNA sola pagina "Impostazioni" con navigazione a tab. I 4 partial
 * STORICI (admin/views/settings-contenuti.php, settings-ai.php,
 * settings-pubblicazione.php, settings-sistema.php) vengono INCLUSI così come
 * sono, uno per pannello: il loro contenuto non è toccato (altri agenti li
 * stanno modificando in parallelo) e i loro campi restano precompilati
 * server-side con Nexus_DB::get_settings().
 *
 * CONTRATTO FISSO CON admin.js (non rinominare id e classi):
 * - div.wrap.nexus-admin con data-page="settings" INVARIATO: il boot di
 *   admin.js riconosce la pagina logica "settings" e aggancia la form
 *   #nxa-settings-form.
 * - Le 4 form dei partial portano lo STESSO id contrattuale
 *   #nxa-settings-form. Su questa pagina l'id deve restare UNICO (admin.js
 *   fa qs('#nxa-settings-form') una sola volta e su quella form registra
 *   submit + handler delegati provider/custom). Meccanismo "form viaggiante"
 *   (script inline in fondo alla vista, nessun tocco ad admin.js):
 *     1. all'avvio l'id resta SOLO sulla form del tab attivo (le altre tre
 *        form lo perdono: niente id duplicati nel DOM);
 *     2. al cambio tab i campi del tab corrente tornano nella form-shell del
 *        loro pannello e i campi del nuovo tab vengono adottati dalla form
 *        con id, che si posiziona al posto della shell;
 *     3. effetto: collectSettings() serializza SOLO i campi del tab attivo
 *        (PUT /settings parziale con whitelist, identico alle 4 pagine
 *        storiche) e i bottoni Salva/provider viaggiano con i campi (stati
 *        di caricamento e handler delegati .nxa-prov-* / #nxa-custprov-*
 *        continuano a funzionare perché i nodi restano dentro la form
 *        bindingata).
 * - Senza JS i tab sono link reali (?page=nexus-settings&tab=<slug>): la
 *   pagina si ricarica col tab richiesto renderizzato server-side.
 *
 * TAB ATTIVO (risoluzione, in ordine di priorità):
 *   1. slug storico della pagina (page=nexus-settings-contenuti|ai|
 *      pubblicazione|sistema → rispettivo tab, BC v1.1.11: i vecchi URL
 *      aprono la pagina unica sul tab giusto);
 *   2. query param ?tab= (sanitize_key + whitelist, solo sulla pagina
 *      canonica nexus-settings);
 *   3. hash #nxa-tab-<slug> via JS (link condivisi / cronologia);
 *   4. default "contenuti".
 * Solo UN pannello è visibile: gli altri hanno l'attributo hidden
 * (display:none via CSS, regola .nxa-settab-panel[hidden]) e restano però
 * renderizzati server-side (nessuna chiamata aggiuntiva al cambio tab).
 *
 * ACCORDION (v1.1.13 O3, unificato): le IIFE accordion dei 4 partial sono
 * state RIMOSE dai partial (v1.1.11 le annullavano a vicenda e questa vista
 * le "riparava" con un layer capture/bubble). Resta UN solo handler delegato
 * in questa vista (click su .nxa-acc-toggle dentro .nxa-settab-panels:
 * toggle di aria-expanded/hidden + snapshot nello storage del TAB del gruppo
 * nxa-settings-acc-<tab>) + restore iniziale per pannello dalla PROPRIA
 * chiave (filtrata sui gruppi del pannello) con fallback al default storico.
 * Il markup dei gruppi NON cambia: section.nxa-acc[data-nxa-acc] con
 * button.nxa-acc-toggle[aria-expanded][aria-controls] + div.nxa-acc-panel.
 *
 * DIRTY-GUARD (v1.1.13 O3): la pagina salva per-tab con la form viaggiante,
 * quindi modifiche non salvate su un tab possono perdersi cambiando tab o
 * navigando via. Lo script traccia lo stato dirty per tab (eventi input/change
 * delegati sui pannelli), mostra: (1) indicatore "non salvato" sui tab con
 * modifiche (span.nxa-settab-dirty, renderizzato server-side, visibile via
 * classe .is-dirty); (2) banner inline in testa al pannello dirty con bottone
 * "Salva ora" (requestSubmit sulla form viaggiante); (3) beforeunload quando
 * c'è ALMENO un tab dirty. Nessun blocco forzato: il cambio tab è sempre
 * permesso (i campi restano nella shell con i valori editati) ma l'avviso
 * resta visibile. Al salvataggio RIUSCITO (wrapper di fetch su PUT /settings,
 * stesso driver admin.js) lo stato dirty del tab salvato si azzera e i badge
 * connessioni del tab Sistema si aggiornano via GET /settings/status.
 *
 * BRIDGE CHIAVI 1.1.13 (O3): i 7 campi nuovi (6 numerici "Limiti e scala" nel
 * tab Sistema + nexus_notify_telegram_chat nel tab Pubblicazione) NON sono
 * negli array di collectSettings() in admin.js (file di altro agente) e vivono
 * come OPZIONI WP INDIPENDENTI (contratto opzioni 1.1.13: i consumatori
 * Nexus_Dedup/Interlink/Evergreen/Notify leggono get_option() dedicate): il
 * wrapper di fetch li fonde nel body del PUT /settings del form submit e la
 * whitelist REST v1.1.13 li sanifica e li persiste come opzioni
 * (Nexus_Rest::persist_scale_limits, absint+clamp / sanitize_text_field).
 *
 * Stile: assets/css/admin-tabs.css (SOLO token var(--nxa-*): il tema dark
 * body.nexus-theme-dark viene ereditato automaticamente). Gli h1 dei partial
 * sono nascosti via CSS (il tab è già l'etichetta del gruppo); il titolo
 * pagina è l'unico h1 di questa vista.
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

/* Tab whitelist: slug => etichetta. L'ordine è l'ordine di navigazione. */
$nxa_settab_tabs = array(
        'contenuti'     => __( 'Contenuti', 'nexus-news-ai' ),
        'ai'            => __( 'AI', 'nexus-news-ai' ),
        'pubblicazione' => __( 'Pubblicazione', 'nexus-news-ai' ),
        'sistema'       => __( 'Sistema', 'nexus-news-ai' ),
);

/* Partial per tab: file fissi, nessun percorso costruito da input utente. */
$nxa_settab_files = array(
        'contenuti'     => 'settings-contenuti',
        'ai'            => 'settings-ai',
        'pubblicazione' => 'settings-pubblicazione',
        'sistema'       => 'settings-sistema',
);

/*
 * Tab attivo server-side.
 *
 * phpcs:ignore warning: sola lettura di parametri GET di navigazione, poi
 * whitelistati (nessun output non escapato, nessuna scrittura).
 */
$nxa_settab_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'nexus-settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

/* v1.1.11 (R2) BC: i 4 slug storici aprono la pagina unica sul proprio tab. */
$nxa_settab_by_page = array(
        'nexus-settings'               => '',
        'nexus-settings-contenuti'     => 'contenuti',
        'nexus-settings-ai'            => 'ai',
        'nexus-settings-pubblicazione' => 'pubblicazione',
        'nexus-settings-sistema'       => 'sistema',
);

$nxa_settab_active = isset( $nxa_settab_by_page[ $nxa_settab_page ] ) ? $nxa_settab_by_page[ $nxa_settab_page ] : '';

if ( '' === $nxa_settab_active ) {
        /* Solo sulla pagina canonica ?tab= è onorato (sanitize_key + whitelist). */
        $nxa_settab_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( $nxa_settab_tab && isset( $nxa_settab_tabs[ $nxa_settab_tab ] ) ) {
                $nxa_settab_active = $nxa_settab_tab;
        } else {
                $nxa_settab_active = 'contenuti';
        }
}
?>
<div class="wrap nexus-admin" data-page="settings">
        <h1><?php esc_html_e( 'Impostazioni', 'nexus-news-ai' ); ?></h1>

        <nav class="nxa-settab-bar" aria-label="<?php esc_attr_e( 'Sezioni impostazioni', 'nexus-news-ai' ); ?>">
                <div class="nxa-settab-list" role="tablist">
                        <?php foreach ( $nxa_settab_tabs as $nxa_settab_slug => $nxa_settab_label ) : ?>
                                <?php
                                $nxa_settab_is_active = ( $nxa_settab_slug === $nxa_settab_active );
                                ?>
                                <a class="nxa-settab-tab<?php echo $nxa_settab_is_active ? ' is-active' : ''; ?>"
                                        role="tab"
                                        id="nxa-settab-tab-<?php echo esc_attr( $nxa_settab_slug ); ?>"
                                        href="<?php echo esc_url( admin_url( 'admin.php?page=nexus-settings&tab=' . rawurlencode( $nxa_settab_slug ) ) ); ?>"
                                        aria-selected="<?php echo $nxa_settab_is_active ? 'true' : 'false'; ?>"
                                        aria-controls="nxa-settab-panel-<?php echo esc_attr( $nxa_settab_slug ); ?>"
                                        tabindex="<?php echo $nxa_settab_is_active ? '0' : '-1'; ?>"
                                        data-nxa-settab-tab="<?php echo esc_attr( $nxa_settab_slug ); ?>"><?php echo esc_html( $nxa_settab_label ); ?><span class="nxa-settab-dirty" aria-hidden="true"><span class="nxa-settab-dirty-text"><?php esc_html_e( 'non salvato', 'nexus-news-ai' ); ?></span></span></a>
                        <?php endforeach; ?>
                </div>
                <?php
                /*
                 * v1.1.19 («Primi Passi») — modalità Semplice/Avanzata: il toggle
                 * è solo UI (stato in localStorage 'nxa-settings-mode', stesso
                 * pattern degli snapshot accordion). Le sezioni avanzate vengono
                 * MARCATE dallo script inline qui sotto (classe .nxa-acc--advanced,
                 * mappa slug per tab) e nascoste dal CSS solo in modalità semplice:
                 * i 4 partial settings-*.php NON sono toccati, tutti i campi restano
                 * nel DOM e nel salvataggio per-tab. Senza JS: tutto visibile.
                 */
                ?>
                <div class="nxa-settab-mode">
                        <label class="nxa-settab-mode__label" for="nxa-settab-mode-toggle">
                                <input type="checkbox" id="nxa-settab-mode-toggle" />
                                <span><?php esc_html_e( 'Mostra sezioni avanzate', 'nexus-news-ai' ); ?></span>
                        </label>
                </div>
        </nav>

        <div class="nxa-settab-panels" data-nxa-settab-active="<?php echo esc_attr( $nxa_settab_active ); ?>">
                <?php foreach ( $nxa_settab_files as $nxa_settab_slug => $nxa_settab_view ) : ?>
                        <div class="nxa-settab-panel"
                                role="tabpanel"
                                id="nxa-settab-panel-<?php echo esc_attr( $nxa_settab_slug ); ?>"
                                aria-labelledby="nxa-settab-tab-<?php echo esc_attr( $nxa_settab_slug ); ?>"
                                tabindex="0"
                                data-nxa-settab-panel="<?php echo esc_attr( $nxa_settab_slug ); ?>"
                                <?php echo ( $nxa_settab_slug !== $nxa_settab_active ) ? 'hidden="hidden"' : ''; ?>>
                                <div class="nxa-settab-dirtybanner" role="status" hidden>
                                        <span class="nxa-settab-dirtybanner-text"><?php esc_html_e( 'Hai modifiche non salvate in questa sezione.', 'nexus-news-ai' ); ?></span>
                                        <button type="button" class="button button-small nxa-settab-dirtybanner-save"><?php esc_html_e( 'Salva ora', 'nexus-news-ai' ); ?></button>
                                </div>
                                <?php
                                $nxa_settab_file = NEXUS_PLUGIN_DIR . 'admin/views/' . $nxa_settab_view . '.php';
                                if ( file_exists( $nxa_settab_file ) ) {
                                        /* Partial storico incluso così com'è (contratto R2: nessuna modifica). */
                                        include $nxa_settab_file;
                                } else {
                                        printf(
                                                '<div class="notice notice-warning"><p>%s</p></div>',
                                                sprintf(
                                                        /* translators: %s: nome della vista mancante. */
                                                        esc_html__( 'Vista "%s" non ancora disponibile (admin/views/).', 'nexus-news-ai' ),
                                                        esc_html( $nxa_settab_view )
                                                )
                                        );
                                }
                                ?>
                        </div>
                        <?php
                        /* Reset variabili di loop: i partial definiscono $settings e altre
                         * variabili proprie, non devono mai vedere queste. */
                        unset( $nxa_settab_file, $nxa_settab_is_active );
                        ?>
                <?php endforeach; ?>
        </div>
</div>

<script>
( function () {
        'use strict';

        var TABS = [ 'contenuti', 'ai', 'pubblicazione', 'sistema' ];
        var FORM_ID = 'nxa-settings-form';
        var HASH_PREFIX = '#nxa-tab-';

        /* Chiavi localStorage storiche dei 4 partial (contratto docblock v1.1.6). */
        var ACC_KEYS = {
                contenuti: 'nxa-settings-acc-contenuti',
                ai: 'nxa-settings-acc-ai',
                pubblicazione: 'nxa-settings-acc-pubblicazione',
                sistema: 'nxa-settings-acc-sistema'
        };
        /* Gruppo aperto di default di ciascuna pagina storica (stesso contratto). */
        var ACC_DEFAULT = {
                contenuti: 'pipeline',
                ai: 'ai',
                pubblicazione: 'wp-seo',
                sistema: 'scala'
        };

        var root = document.querySelector( '.nxa-settab-panels' );
        if ( ! root ) {
                return;
        }

        var tablist = document.querySelector( '.nxa-settab-list' );
        var panels = {};
        var tabs = {};
        var shells = {};      /* slug → destinazione dei campi quando il tab è inattivo. */
        var boundForm = null; /* LA form con id contrattuale: submit + handler admin.js. */
        var active = root.getAttribute( 'data-nxa-settab-active' ) || 'contenuti';
        if ( TABS.indexOf( active ) === -1 ) {
                active = 'contenuti';
        }

        function toArray( list ) {
                return Array.prototype.slice.call( list || [] );
        }

        function panelOf( el ) {
                return el && el.closest ? el.closest( '.nxa-settab-panel' ) : null;
        }

        /* ==================================================================
         * 1. Form "viaggiante" (v1.1.11 R2)
         *
         * I 4 partial portano ciascuno una form #nxa-settings-form (contratto
         * admin.js, id non rinominabile). Su questa pagina l'id resta su UNA
         * sola form: quella bindingata da admin.js all'avvio (la form del tab
         * attivo). Al cambio tab i campi del tab corrente tornano nella shell
         * del loro pannello e quelli del nuovo tab vengono adottati dalla form
         * con id, che si posiziona al posto della shell. collectSettings()
         * serializza così SOLO i campi del tab attivo (PUT /settings parziale)
         * e i bottoni Salva/provider viaggiano con i campi.
         * ================================================================== */

        function hostChildren( fromEl, toEl ) {
                if ( ! fromEl || ! toEl ) {
                        return;
                }
                while ( fromEl.firstChild ) {
                        toEl.appendChild( fromEl.firstChild );
                }
        }

        function setupForms() {
                var forms = toArray( root.querySelectorAll( 'form' ) );
                var byPanel = {};
                var i;

                for ( i = 0; i < forms.length; i++ ) {
                        var panelEl = panelOf( forms[ i ] );
                        var slug = panelEl ? panelEl.getAttribute( 'data-nxa-settab-panel' ) : '';
                        if ( slug && ! byPanel[ slug ] ) {
                                byPanel[ slug ] = forms[ i ];
                        }
                }

                boundForm = byPanel[ active ] || ( forms.length ? forms[ 0 ] : null );
                for ( var s in byPanel ) {
                        if ( Object.prototype.hasOwnProperty.call( byPanel, s ) ) {
                                shells[ s ] = byPanel[ s ];
                        }
                }

                /* L'id contrattuale resta SOLO sulla form viaggiante: niente id
                 * duplicati nel DOM (admin.js fa qs('#nxa-settings-form') una volta). */
                for ( i = 0; i < forms.length; i++ ) {
                        forms[ i ].removeAttribute( 'id' );
                }
                if ( boundForm ) {
                        boundForm.setAttribute( 'id', FORM_ID );

                        /* Parcheggio dei campi del tab di boot: la sua form originale È la
                         * form viaggiante, quindi quando questa lascia il pannello i campi
                         * devono poter tornare in un contenitore della stessa posizione. */
                        var host = document.createElement( 'div' );
                        host.className = 'nxa-settab-formhost';
                        host.setAttribute( 'hidden', 'hidden' );
                        boundForm.parentNode.insertBefore( host, boundForm );
                        shells[ active ] = host;
                }
        }

        function moveFieldsToActive( nextSlug ) {
                if ( ! boundForm || ! nextSlug || ! shells[ nextSlug ] ) {
                        return;
                }
                if ( shells[ nextSlug ] === boundForm ) {
                        return;
                }

                var currentPanel = panelOf( boundForm );
                var currentSlug = currentPanel ? currentPanel.getAttribute( 'data-nxa-settab-panel' ) : '';

                /* 1) i campi del tab corrente tornano nella shell del loro pannello e
                 *    la form viaggiante (vuota) si parcheggia accanto alla shell. */
                if ( currentSlug && shells[ currentSlug ] && shells[ currentSlug ] !== boundForm ) {
                        hostChildren( boundForm, shells[ currentSlug ] );
                        shells[ currentSlug ].parentNode.insertBefore( boundForm, shells[ currentSlug ] );
                }

                /* 2) i campi del nuovo tab vengono adottati dalla form viaggiante,
                 *    che si posiziona al posto della shell (posizione originale). */
                hostChildren( shells[ nextSlug ], boundForm );
                shells[ nextSlug ].parentNode.insertBefore( boundForm, shells[ nextSlug ] );
        }

        /* ==================================================================
         * 2. Tab: UI, URL, tastiera
         * ================================================================== */

        function applyTabUi( slug ) {
                for ( var i = 0; i < TABS.length; i++ ) {
                        var t = TABS[ i ];
                        var isActive = ( t === slug );

                        if ( panels[ t ] ) {
                                if ( isActive ) {
                                        panels[ t ].removeAttribute( 'hidden' );
                                } else {
                                        panels[ t ].setAttribute( 'hidden', 'hidden' );
                                }
                        }
                        if ( tabs[ t ] ) {
                                tabs[ t ].setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
                                tabs[ t ].setAttribute( 'tabindex', isActive ? '0' : '-1' );
                                if ( isActive ) {
                                        tabs[ t ].classList.add( 'is-active' );
                                } else {
                                        tabs[ t ].classList.remove( 'is-active' );
                                }
                        }
                }
        }

        function setTabQueryParam( slug ) {
                try {
                        var search = String( window.location.search || '' );
                        if ( search.charAt( 0 ) === '?' ) {
                                search = search.substring( 1 );
                        }
                        var parts = search ? search.split( '&' ) : [];
                        var kept = [];
                        for ( var i = 0; i < parts.length; i++ ) {
                                if ( ! parts[ i ] || parts[ i ].indexOf( 'tab=' ) === 0 ) {
                                        continue;
                                }
                                /* Da una pagina storica (page=nexus-settings-*) il switch deve
                                 * ricaricare la pagina CANONICA col tab scelto: altrimenti il
                                 * reload ripartirebbe dal tab fissato dallo slug storico. */
                                if ( parts[ i ].indexOf( 'page=nexus-settings' ) === 0 ) {
                                        parts[ i ] = 'page=nexus-settings';
                                }
                                kept.push( parts[ i ] );
                        }
                        kept.push( 'tab=' + encodeURIComponent( slug ) );
                        window.history.replaceState(
                                null,
                                '',
                                window.location.pathname + '?' + kept.join( '&' ) + HASH_PREFIX + slug
                        );
                } catch ( err ) {
                        /* URL non aggiornabile: il tab funziona comunque. */
                }
        }

        function tabFromHash() {
                var hash = String( window.location.hash || '' );
                if ( hash.indexOf( HASH_PREFIX ) !== 0 ) {
                        return '';
                }
                var slug = hash.substring( HASH_PREFIX.length );
                return TABS.indexOf( slug ) !== -1 ? slug : '';
        }

        function tabFromQuery() {
                try {
                        var match = /(^|[?&])tab=([^&]+)/.exec( String( window.location.search || '' ) );
                        if ( ! match ) {
                                return '';
                        }
                        var slug = decodeURIComponent( String( match[ 2 ] ).replace( /\+/g, ' ' ) );
                        return TABS.indexOf( slug ) !== -1 ? slug : '';
                } catch ( err ) {
                        return '';
                }
        }

        function switchTo( slug, opts ) {
                if ( TABS.indexOf( slug ) === -1 || slug === active || ! panels[ slug ] ) {
                        return;
                }
                moveFieldsToActive( slug );
                active = slug;
                applyTabUi( slug );
                if ( ! opts || ! opts.keepUrl ) {
                        setTabQueryParam( slug );
                }
        }

        function focusTab( slug ) {
                if ( tabs[ slug ] && typeof tabs[ slug ].focus === 'function' ) {
                        tabs[ slug ].focus();
                }
        }

        /* ==================================================================
         * 3. Accordion (v1.1.13 O3, UN solo handler delegato)
         *
         * Le 4 IIFE accordion dei partial sono state RIMOSE dai partial: si
         * annullavano a vicenda (ogni click gestito 4 volte) e in v1.1.11
         * richiedevano un layer di riparazione capture/bubble qui sotto.
         * Ora resta solo questo handler su .nxa-settab-panels (markup dei
         * gruppi INVARIATO: .nxa-acc[data-nxa-acc] + .nxa-acc-toggle +
         * .nxa-acc-panel) e lo snapshot va nello storage DEL TAB del gruppo
         * (chiavi storiche nxa-settings-acc-<tab>, contratto v1.1.6).
         * ================================================================== */

        function accApply( group, isOpen ) {
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

        function accSave( panelEl ) {
                var slug = panelEl ? panelEl.getAttribute( 'data-nxa-settab-panel' ) : '';
                var key = ( slug && ACC_KEYS[ slug ] ) ? ACC_KEYS[ slug ] : '';
                if ( ! key ) {
                        return;
                }
                var groups = panelEl.querySelectorAll( '.nxa-acc[data-nxa-acc]' );
                var open = [];
                for ( var i = 0; i < groups.length; i++ ) {
                        var toggle = groups[ i ].querySelector( '.nxa-acc-toggle' );
                        if ( toggle && toggle.getAttribute( 'aria-expanded' ) === 'true' ) {
                                var accSlug = groups[ i ].getAttribute( 'data-nxa-acc' );
                                if ( accSlug ) {
                                        open.push( accSlug );
                                }
                        }
                }
                try {
                        window.localStorage.setItem( key, JSON.stringify( open ) );
                } catch ( err ) {
                        window.console.warn( '[Nexus] impossibile salvare lo stato dei gruppi impostazioni (localStorage).' );
                }
        }

        function accReadKey( key ) {
                try {
                        var raw = window.localStorage.getItem( key );
                        if ( ! raw ) {
                                return null;
                        }
                        var parsed = JSON.parse( raw );
                        return Array.isArray( parsed ) ? parsed : null;
                } catch ( err ) {
                        return null;
                }
        }

        function accRestoreInitial() {
                for ( var i = 0; i < TABS.length; i++ ) {
                        var slug = TABS[ i ];
                        var panelEl = panels[ slug ];
                        if ( ! panelEl ) {
                                continue;
                        }
                        var groups = panelEl.querySelectorAll( '.nxa-acc[data-nxa-acc]' );
                        if ( ! groups.length ) {
                                continue;
                        }

                        var own = {};
                        for ( var g = 0; g < groups.length; g++ ) {
                                var s = groups[ g ].getAttribute( 'data-nxa-acc' );
                                if ( s ) {
                                        own[ s ] = groups[ g ];
                                }
                        }

                        var saved = accReadKey( ACC_KEYS[ slug ] );
                        var openMap = {};
                        if ( saved ) {
                                for ( var k = 0; k < saved.length; k++ ) {
                                        if ( typeof saved[ k ] === 'string' && Object.prototype.hasOwnProperty.call( own, saved[ k ] ) ) {
                                                openMap[ saved[ k ] ] = true;
                                        }
                                }
                        } else {
                                openMap[ ACC_DEFAULT[ slug ] ] = true;
                        }

                        for ( var s2 in own ) {
                                if ( Object.prototype.hasOwnProperty.call( own, s2 ) ) {
                                        accApply( own[ s2 ], openMap[ s2 ] === true );
                                }
                        }
                }
        }

        /* ==================================================================
         * 4. Dirty-guard "modifiche non salvate" (v1.1.13 O3)
         *
         * La pagina salva per-tab (form viaggiante): modifiche su un tab non
         * salvato si perderebbero ricaricando o navigando via. Stato dirty
         * per tab (eventi input/change delegati sui pannelli), indicatore sul
         * tab (span.nxa-settab-dirty + classe .is-dirty), banner inline nel
         * pannello (bottone "Salva ora" = requestSubmit sulla form viaggiante)
         * e beforeunload quando c'è ALMENO un tab dirty. Nessun blocco forzato.
         * ================================================================== */

        var dirty = {};
        var saveArmed = false; /* submit della form viaggiante in volo (per il wrapper fetch). */
        var nativeFetch = null; /* fetch nativa, memoizzata dal bridge. */

        /* Chiavi 1.1.13 (O3) NON ancora negli array di collectSettings() in
         * admin.js (file di altro agente): il bridge le fonde nel body del
         * PUT /settings del submit. Le numeriche partono solo se compilate
         * (il clamp finale è nella whitelist REST); la chat viaggia sempre
         * (vuota = svuota, non è un segreto). */
        var BRIDGE_NUMBERS = [
                'nexus_dedup_window_days',
                'nexus_dedup_lookup_max',
                'nexus_interlink_window_days',
                'nexus_interlink_candidates',
                'nexus_evergreen_max_per_run',
                'nexus_notify_max_per_hour'
        ];
        var BRIDGE_TEXT = [ 'nexus_notify_telegram_chat' ];

        function anyDirty() {
                for ( var s in dirty ) {
                        if ( Object.prototype.hasOwnProperty.call( dirty, s ) && dirty[ s ] === true ) {
                                return true;
                        }
                }
                return false;
        }

        function renderDirty() {
                for ( var i = 0; i < TABS.length; i++ ) {
                        var slug = TABS[ i ];
                        var isDirty = ( dirty[ slug ] === true );
                        if ( tabs[ slug ] ) {
                                if ( isDirty ) {
                                        tabs[ slug ].classList.add( 'is-dirty' );
                                } else {
                                        tabs[ slug ].classList.remove( 'is-dirty' );
                                }
                        }
                        if ( panels[ slug ] ) {
                                var banner = panels[ slug ].querySelector( '.nxa-settab-dirtybanner' );
                                if ( banner ) {
                                        if ( isDirty ) {
                                                banner.removeAttribute( 'hidden' );
                                        } else {
                                                banner.setAttribute( 'hidden', 'hidden' );
                                        }
                                }
                        }
                }
        }

        function markDirty( el ) {
                /* Solo i campi con name (contratto settings): esclude i campi
                 * del form provider personalizzati (senza name, salvati via
                 * REST dedicata) e i bottoni. */
                if ( ! el || ! el.name ) {
                        return;
                }
                var panel = el.closest ? el.closest( '.nxa-settab-panel' ) : null;
                if ( ! panel ) {
                        return;
                }
                var slug = panel.getAttribute( 'data-nxa-settab-panel' );
                if ( slug && dirty[ slug ] !== true ) {
                        dirty[ slug ] = true;
                        renderDirty();
                }
        }

        function resetDirty( slug ) {
                if ( slug && dirty[ slug ] === true ) {
                        dirty[ slug ] = false;
                        renderDirty();
                }
        }

        /* ==================================================================
         * 5. Bridge PUT /settings (v1.1.13 O3)
         *
         * Wrapper di window.fetch: quando passa il PUT /settings del submit
         * della form viaggiante (admin.js), fonde nel body le chiavi 1.1.13
         * presenti nella form attiva e, se la risposta è ok, azzera lo stato
         * dirty del tab salvato e aggiorna i badge connessioni del tab
         * Sistema via GET /settings/status (endpoint di sola lettura O3,
         * solo booleani). Gli altri fetch (GET, wizard, provider) passano
         * intatti.
         * ================================================================== */

        function collectBridgeExtras() {
                var extras = {};
                if ( ! boundForm ) {
                        return extras;
                }
                var i;
                var field;
                for ( i = 0; i < BRIDGE_NUMBERS.length; i++ ) {
                        field = boundForm.elements[ BRIDGE_NUMBERS[ i ] ];
                        if ( field && typeof field.value !== 'undefined' && String( field.value ).trim() !== '' ) {
                                var parsed = parseInt( field.value, 10 );
                                if ( ! isNaN( parsed ) ) {
                                        extras[ BRIDGE_NUMBERS[ i ] ] = parsed;
                                }
                        }
                }
                for ( i = 0; i < BRIDGE_TEXT.length; i++ ) {
                        field = boundForm.elements[ BRIDGE_TEXT[ i ] ];
                        if ( field && typeof field.value !== 'undefined' ) {
                                extras[ BRIDGE_TEXT[ i ] ] = String( field.value ).trim();
                        }
                }
                return extras;
        }

        function isSettingsPut( input, init ) {
                var method = ( init && init.method ) ? String( init.method ) : ( input && input.method ? String( input.method ) : '' );
                if ( method.toUpperCase() !== 'PUT' ) {
                        return false;
                }
                var url = ( typeof input === 'string' ) ? input : ( input && input.url ? String( input.url ) : '' );
                /* Taglia query e fragment, poi confronta la coda del path:
                 * riconosce ".../settings" (con o senza slash finale) ma NON
                 * ".../settings/status" (che è GET di sola lettura). */
                var cut = url.indexOf( '?' );
                if ( cut !== -1 ) {
                        url = url.substring( 0, cut );
                }
                cut = url.indexOf( '#' );
                if ( cut !== -1 ) {
                        url = url.substring( 0, cut );
                }
                return endsWithStr( url, '/settings' ) || endsWithStr( url, '/settings/' );
        }

        function endsWithStr( str, suffix ) {
                return str.length >= suffix.length && str.substring( str.length - suffix.length ) === suffix;
        }

        function installFetchBridge() {
                if ( typeof window.fetch !== 'function' || window.fetch.__nxaBridge ) {
                        return;
                }
                nativeFetch = window.fetch;
                var wrapped = function ( input, init ) {
                        var isSave = ( saveArmed && isSettingsPut( input, init ) );
                        var savedSlug = '';
                        if ( isSave ) {
                                saveArmed = false;
                                savedSlug = active;
                        }
                        if ( isSave && init && typeof init.body === 'string' ) {
                                try {
                                        var body = JSON.parse( init.body );
                                        if ( body && typeof body === 'object' && ! Array.isArray( body ) ) {
                                                var extras = collectBridgeExtras();
                                                for ( var k in extras ) {
                                                        if ( Object.prototype.hasOwnProperty.call( extras, k ) ) {
                                                                body[ k ] = extras[ k ];
                                                        }
                                                }
                                                init.body = JSON.stringify( body );
                                        }
                                } catch ( err ) {
                                        /* body non-JSON: pass-through (il PUT resta quello di admin.js). */
                                }
                        }
                        var promise = nativeFetch.call( window, input, init );
                        if ( isSave ) {
                                promise.then( function ( res ) {
                                        if ( res && res.ok ) {
                                                resetDirty( savedSlug );
                                                refreshStatusBadges();
                                        }
                                } ).catch( function () {
                                        /* salvataggio fallito: lo stato dirty resta visibile. */
                                } );
                        }
                        return promise;
                };
                wrapped.__nxaBridge = true;
                window.fetch = wrapped;
        }

        function applyStatusBadge( key, isOk ) {
                var badge = root.querySelector( '[data-nxa-status-badge="' + key + '"]' );
                if ( ! badge ) {
                        return;
                }
                if ( isOk ) {
                        badge.classList.add( 'nxa-status-badge--ok' );
                        badge.classList.remove( 'nxa-status-badge--off' );
                } else {
                        badge.classList.add( 'nxa-status-badge--off' );
                        badge.classList.remove( 'nxa-status-badge--ok' );
                }
        }

        function refreshStatusBadges() {
                var data = window.NexusAdminData;
                if ( ! data || ! data.restUrl || ! data.nonce || ! nativeFetch ) {
                        return;
                }
                var url = String( data.restUrl ).replace( /\/+$/, '' ) + '/settings/status';
                nativeFetch.call( window, url, {
                        headers: { 'X-WP-Nonce': data.nonce },
                        credentials: 'same-origin'
                } ).then( function ( res ) {
                        if ( ! res || ! res.ok ) {
                                return null;
                        }
                        return res.json();
                } ).then( function ( body ) {
                        if ( ! body || typeof body !== 'object' ) {
                                return;
                        }
                        applyStatusBadge( 'google', body.google_connected === true );
                        applyStatusBadge( 'telegram', body.telegram_configured === true );
                        applyStatusBadge( 'ai', body.ai_provider_active === true );
                } ).catch( function () {
                        /* stato non aggiornabile: restano i valori server-side. */
                } );
        }

        /* ==================================================================
         * 5b. Modalità Semplice/Avanzata (v1.1.19 «Primi Passi»)
         *
         * Mappa tab → slug degli accordion avanzati (data-nxa-acc dei partial,
         * NON toccati): a ogni sezione elencata viene aggiunta la classe
         * .nxa-acc--advanced; in modalità semplice il root porta
         * .nxa-mode-simple e il CSS le nasconde. Stato in localStorage
         * (chiave 'nxa-settings-mode', stesso pattern degli snapshot
         * accordion). Tutti i campi restano nel DOM: il salvataggio per-tab
         * (form viaggiante) non cambia. Senza JS tutto resta visibile.
         * ================================================================== */

        var ADVANCED = {
                contenuti: [ 'interlink', 'limits' ],
                ai: [ 'chief', 'tools', 'intelligence', 'dedup', 'learning' ],
                pubblicazione: [ 'social', 'newsletter', 'indexing', 'news-sitemap', 'notify' ],
                sistema: [ 'scala', 'limiti', 'evergreen', 'google-feedback', 'language-api' ]
        };
        var MODE_KEY = 'nxa-settings-mode';

        function readMode() {
                try {
                        return window.localStorage.getItem( MODE_KEY ) === 'advanced' ? 'advanced' : 'simple';
                } catch ( err ) {
                        return 'simple';
                }
        }

        function applyMode( mode ) {
                var wrap = document.querySelector( '.wrap.nexus-admin[data-page="settings"]' );
                var toggle = document.getElementById( 'nxa-settab-mode-toggle' );
                var isSimple = mode !== 'advanced';
                var total = 0;
                var slug;
                var i;

                /* Marcatura idempotente delle sezioni avanzate. */
                for ( slug in ADVANCED ) {
                        if ( ! Object.prototype.hasOwnProperty.call( ADVANCED, slug ) || ! panels[ slug ] ) {
                                continue;
                        }
                        for ( i = 0; i < ADVANCED[ slug ].length; i++ ) {
                                var group = panels[ slug ].querySelector( '.nxa-acc[data-nxa-acc="' + ADVANCED[ slug ][ i ] + '"]' );
                                if ( group ) {
                                        group.classList.add( 'nxa-acc--advanced' );
                                        total++;
                                }
                        }
                }

                if ( wrap ) {
                        if ( isSimple ) {
                                wrap.classList.add( 'nxa-mode-simple' );
                        } else {
                                wrap.classList.remove( 'nxa-mode-simple' );
                        }
                }
                if ( toggle ) {
                        toggle.checked = ! isSimple;
                        var label = toggle.closest( 'label' );
                        var text = label ? label.querySelector( 'span' ) : null;
                        if ( text ) {
                                text.textContent = toggle.getAttribute( 'data-label' ) + ' (' + total + ')';
                        }
                }
        }

        function bindModeToggle() {
                var toggle = document.getElementById( 'nxa-settab-mode-toggle' );
                if ( ! toggle ) {
                        return;
                }
                /* Etichetta base salvata una volta: applyMode aggiunge il conteggio. */
                var label = toggle.closest( 'label' );
                var text = label ? label.querySelector( 'span' ) : null;
                if ( text && ! toggle.getAttribute( 'data-label' ) ) {
                        toggle.setAttribute( 'data-label', text.textContent );
                }
                toggle.addEventListener( 'change', function () {
                        var mode = toggle.checked ? 'advanced' : 'simple';
                        try {
                                window.localStorage.setItem( MODE_KEY, mode );
                        } catch ( err ) {
                                /* localStorage non disponibile: la modalità vale per questa vista. */
                        }
                        applyMode( mode );
                } );
                applyMode( readMode() );
        }

        /* ==================================================================
         * 6. Avvio
         * ================================================================== */

        function init() {
                var panelEls = root.querySelectorAll( '.nxa-settab-panel' );
                for ( var i = 0; i < panelEls.length; i++ ) {
                        var slug = panelEls[ i ].getAttribute( 'data-nxa-settab-panel' );
                        if ( slug ) {
                                panels[ slug ] = panelEls[ i ];
                        }
                }

                var tabEls = document.querySelectorAll( '.nxa-settab-tab' );
                for ( var j = 0; j < tabEls.length; j++ ) {
                        var tslug = tabEls[ j ].getAttribute( 'data-nxa-settab-tab' );
                        if ( tslug ) {
                                tabs[ tslug ] = tabEls[ j ];
                        }
                }

                setupForms();

                /* Modalità Semplice/Avanzata (v1.1.19): marcatura sezioni +
                 * classe sul root, PRIMA del restore accordion (indipendenti:
                 * la visibilità accordion è gestita da hidden, quella di
                 * modalità da classe CSS). */
                bindModeToggle();

                /* Dirty-guard: input/change delegati in capture sui pannelli (i
                 * campi viaggiano fra form e shell ma restano DENTRO il proprio
                 * pannello, quindi il tab dirty è sempre risolvibile). */
                root.addEventListener( 'input', function ( ev ) {
                        markDirty( ev.target );
                }, true );
                root.addEventListener( 'change', function ( ev ) {
                        markDirty( ev.target );
                }, true );

                /* Banner "Salva ora": submit della form viaggiante (il bottone è
                 * fuori dalla form: requestSubmit + fallback click sul primo submit). */
                root.addEventListener( 'click', function ( ev ) {
                        var btn = ev.target && ev.target.closest ? ev.target.closest( '.nxa-settab-dirtybanner-save' ) : null;
                        if ( ! btn || ! boundForm ) {
                                return;
                        }
                        var panel = panelOf( btn );
                        if ( ! panel || panel.getAttribute( 'data-nxa-settab-panel' ) !== active ) {
                                return;
                        }
                        if ( typeof boundForm.requestSubmit === 'function' ) {
                                boundForm.requestSubmit();
                        } else {
                                var sub = boundForm.querySelector( 'button[type="submit"], input[type="submit"]' );
                                if ( sub ) {
                                        sub.click();
                                }
                        }
                } );

                /* Submit della form viaggiante in volo: arma il bridge PUT (consumato
                 * dalla prima PUT /settings che passa; il flag evita che PUT estranei
                 * azzerino lo stato dirty o fondano campi estranei). */
                document.addEventListener( 'submit', function ( ev ) {
                        if ( ev.target === boundForm ) {
                                saveArmed = true;
                        }
                }, true );

                /* Navigazione via con modifiche non salvate: avviso del browser
                 * (nessun blocco forzato, solo l'onestà del confirm nativo). */
                window.addEventListener( 'beforeunload', function ( ev ) {
                        if ( ! anyDirty() ) {
                                return;
                        }
                        ev.preventDefault();
                        ev.returnValue = '';
                        return '';
                } );

                installFetchBridge();

                /* Il server ha già renderizzato il tab attivo (slug storico o ?tab=).
                 * L'hash #nxa-tab-<slug> vince solo se la query non indica già un tab. */
                if ( ! tabFromQuery() ) {
                        var hashTab = tabFromHash();
                        if ( hashTab && hashTab !== active ) {
                                switchTo( hashTab );
                        }
                }
                applyTabUi( active );

                accRestoreInitial();

                /* Click sui tab: link reali (?tab=) ma il cambio è istantaneo via JS. */
                if ( tablist ) {
                        tablist.addEventListener( 'click', function ( ev ) {
                                var tabEl = ev.target && ev.target.closest ? ev.target.closest( '.nxa-settab-tab' ) : null;
                                if ( ! tabEl ) {
                                        return;
                                }
                                ev.preventDefault();
                                switchTo( tabEl.getAttribute( 'data-nxa-settab-tab' ) || '' );
                        } );

                        /* Pattern ARIA tabs: roving tabindex, frecce/Home/End con attivazione
                         * automatica (selection follows focus). */
                        tablist.addEventListener( 'keydown', function ( ev ) {
                                var key = String( ev.key || '' );
                                var index = TABS.indexOf( active );
                                var next = '';

                                if ( 'ArrowRight' === key || 'Right' === key ) {
                                        next = TABS[ ( index + 1 ) % TABS.length ];
                                } else if ( 'ArrowLeft' === key || 'Left' === key ) {
                                        next = TABS[ ( index - 1 + TABS.length ) % TABS.length ];
                                } else if ( 'Home' === key ) {
                                        next = TABS[ 0 ];
                                } else if ( 'End' === key ) {
                                        next = TABS[ TABS.length - 1 ];
                                }

                                if ( ! next ) {
                                        return;
                                }
                                ev.preventDefault();
                                switchTo( next );
                                focusTab( next );
                        } );
                }

                /* Back/forward o hash modificato a mano: segue il tab dell'hash. */
                window.addEventListener( 'hashchange', function () {
                        var slug = tabFromHash();
                        if ( slug && slug !== active ) {
                                switchTo( slug, { keepUrl: true } );
                        }
                } );

                /* Accordion (v1.1.13 O3): UN solo handler delegato sui pannelli.
                 * Le IIFE dei partial sono state rimosse; lo snapshot va nella
                 * chiave localStorage del TAB del gruppo cliccato. */
                root.addEventListener( 'click', function ( ev ) {
                        var toggle = ev.target && ev.target.closest ? ev.target.closest( '.nxa-acc-toggle' ) : null;
                        if ( ! toggle ) {
                                return;
                        }
                        var group = toggle.closest ? toggle.closest( '.nxa-acc' ) : null;
                        var panel = group ? panelOf( group ) : null;
                        if ( ! group || ! panel ) {
                                return;
                        }
                        accApply( group, toggle.getAttribute( 'aria-expanded' ) !== 'true' );
                        accSave( panel );
                } );
        }

        init();
} )();
</script>
