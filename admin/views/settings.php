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
 * RIPARAZIONE ACCORDION (v1.1.11 R2, senza toccare i partial): ogni partial
 * registra la propria IIFE accordion su document (listener click + query
 * globali). Incluse insieme, ogni click su un toggle verrebbe gestito 4
 * volte (ogni handler rilegge aria-expanded e ri-toggle → lo stato finale
 * torna a quello iniziale) e ogni restoreState() sovrascriverebbe i gruppi
 * di tutti i pannelli con la chiave localStorage della propria pagina.
 * Lo script inline di questa vista corregge: memo in capture dello stato
 * PRE-click, riapplicazione in bubble (registrato per ultimo) dello stato
 * post-UN-toggle + snapshot salvato su tutte e 4 le chiavi storiche
 * (nxa-settings-acc-*), restore iniziale per pannello dalla PROPRIA chiave
 * (filtrata sui gruppi del pannello) con fallback al default storico.
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
                                        data-nxa-settab-tab="<?php echo esc_attr( $nxa_settab_slug ); ?>"><?php echo esc_html( $nxa_settab_label ); ?></a>
                        <?php endforeach; ?>
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
         * 3. Riparazione accordion (v1.1.11 R2)
         *
         * I 4 partial registrano ciascuno la propria IIFE accordion su document:
         * insieme, ogni click verrebbe gestito 4 volte (stato finale = stato
         * iniziale) e i restore iniziali si sovrascrivono a vicenda. Correzione
         * senza toccare i partial: memo in capture dello stato PRE-click,
         * riapplicazione in bubble (questo listener è registrato PER ULTIMO, gira
         * dopo i 4 handler dei partial) dello stato post-UN-toggle + snapshot
         * corretto salvato su tutte e 4 le chiavi storiche.
         * ================================================================== */

        var pendingAcc = null;

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

        function accSave() {
                var groups = root.querySelectorAll( '.nxa-acc[data-nxa-acc]' );
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
                for ( var key in ACC_KEYS ) {
                        if ( Object.prototype.hasOwnProperty.call( ACC_KEYS, key ) ) {
                                try {
                                        window.localStorage.setItem( ACC_KEYS[ key ], JSON.stringify( open ) );
                                } catch ( err ) {
                                        window.console.warn( '[Nexus] impossibile salvare lo stato dei gruppi impostazioni (localStorage).' );
                                }
                        }
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
         * 4. Avvio
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

                /* Accordion: capture = memo pre-click; bubble (registrato per ultimo,
                 * dopo le IIFE dei partial) = stato definitivo + snapshot corretto. */
                document.addEventListener( 'click', function ( ev ) {
                        pendingAcc = null;
                        var toggle = ev.target && ev.target.closest ? ev.target.closest( '.nxa-acc-toggle' ) : null;
                        var group = toggle && toggle.closest ? toggle.closest( '.nxa-acc' ) : null;
                        if ( ! group || ! root.contains( group ) ) {
                                return;
                        }
                        pendingAcc = {
                                group: group,
                                open: toggle.getAttribute( 'aria-expanded' ) !== 'true'
                        };
                }, true );

                document.addEventListener( 'click', function () {
                        if ( ! pendingAcc ) {
                                return;
                        }
                        accApply( pendingAcc.group, pendingAcc.open );
                        accSave();
                        pendingAcc = null;
                }, false );
        }

        init();
} )();
</script>
