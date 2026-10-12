/**
 * Nexus I18n — traduzione JS dei contenuti dinamici (v1.1.6).
 *
 * Riceve via wp_localize_script:
 *   window.NexusI18n = {
 *       enabled: true,
 *       map: { 'Stringa IT': 'EN…' },
 *       patterns: [ { re: 'regex IT (senza delimitatori)', tpl: 'EN con $1/$2' } ]
 *   }
 *
 * Meccanismo (contratto v1.1.6 §2 T-01):
 * 1. Traduce i nodi TESTO e gli attributi (placeholder/title/aria-label/value di
 *    bottoni) renderizzati dinamicamente da admin.js. Per ognuno: PRIMA il match
 *    ESATTO sulla mappa (stringa trimmata); SOLO se fallisce, i PATTERN in
 *    sequenza (RegExp compilati una volta; pattern invalido → skip silenzioso e
 *    un solo console.warn; sostituzione con String.replace e template $1/$2
 *    nativi JS; al massimo 4 giri, solo finché il testo cambia — anti-loop).
 * 2. Espone window.NexusI18nTranslate( text ): match esatto (stringa intera,
 *    poi stringa trimmata) → traduzione; altrimenti pattern; altrimenti testo
 *    originale. Usata da admin.js (nxaT) per i frammenti dei confirm().
 * 3. La traduzione lato PHP delle viste avviene in Nexus_I18n::translate_html().
 *
 * Anti-loop: durante la sostituzione l'observer è disconnesso; le mutazioni generate
 * dalla traduzione stessa non vengono riprocessate. Skip su script/style/textarea/input testuali.
 */
( function () {
        'use strict';

        if ( ! window.NexusI18n || ! NexusI18n.enabled ) {
                return;
        }

        var map = NexusI18n.map || {};
        var SKIP = { SCRIPT: 1, STYLE: 1, TEXTAREA: 1, NOSCRIPT: 1, CODE: 1, PRE: 1 };
        var MAX_ROUNDS = 4;
        var busy = false;
        var pending = false;
        var observer = null;

        /* ---------------- utilità ---------------- */

        function hasKeys( obj ) {
                for ( var k in obj ) {
                        if ( Object.prototype.hasOwnProperty.call( obj, k ) ) {
                                return true;
                        }
                }
                return false;
        }

        /**
         * Compila i pattern ricevuti (array di {re, tpl}). Un pattern invalido
         * viene scartato in silenzioso con UN SOLO console.warn per pattern.
         */
        var patterns = [];
        var warnedPatterns = {};

        function compilePatterns() {
                var list = NexusI18n.patterns;
                if ( ! list || typeof list.length !== 'number' ) {
                        return;
                }
                for ( var i = 0; i < list.length; i++ ) {
                        var item = list[ i ];
                        if ( ! item || typeof item.re !== 'string' || typeof item.tpl !== 'string' || ! item.re ) {
                                continue;
                        }
                        try {
                                patterns.push( { re: new RegExp( item.re, 'g' ), tpl: item.tpl } );
                        } catch ( e ) {
                                if ( ! warnedPatterns[ item.re ] ) {
                                        warnedPatterns[ item.re ] = 1;
                                        if ( window.console && window.console.warn ) {
                                                window.console.warn( 'Nexus i18n: pattern non valido, ignorato (' + item.re + ')' );
                                        }
                                }
                        }
                }
        }

        function rootEl() {
                return document.getElementById( 'wpbody-content' ) || document.body;
        }

        function isSkip( el ) {
                var node = el;
                while ( node ) {
                        if ( SKIP[ node.nodeName ] ) {
                                return true;
                        }
                        node = node.parentNode;
                }
                return false;
        }

        /**
         * Applica tutti i pattern in sequenza, a catena, per al massimo MAX_ROUNDS
         * giri e solo finché il testo cambia (anti-loop). Nessun pattern → input intatto.
         */
        function applyPatterns( text ) {
                if ( ! patterns.length ) {
                        return text;
                }
                var out = text;
                for ( var round = 0; round < MAX_ROUNDS; round++ ) {
                        var changed = false;
                        for ( var i = 0; i < patterns.length; i++ ) {
                                var next;
                                try {
                                        next = out.replace( patterns[ i ].re, patterns[ i ].tpl );
                                } catch ( e ) {
                                        continue;
                                }
                                if ( next !== out ) {
                                        out = next;
                                        changed = true;
                                }
                        }
                        if ( ! changed ) {
                                break;
                        }
                }
                return out;
        }

        /**
         * Traduce una stringa con match esatto. Ritorna null se nessun match.
         */
        function lookup( text ) {
                if ( ! text ) {
                        return null;
                }
                var trimmed = text.trim();
                if ( ! trimmed ) {
                        return null;
                }
                if ( Object.prototype.hasOwnProperty.call( map, trimmed ) ) {
                        var translated = map[ trimmed ];
                        if ( translated && translated !== trimmed ) {
                                return { trimmed: trimmed, translated: translated };
                        }
                }
                return null;
        }

        /**
         * Traduzione completa di una stringa arbitraria (match esatto sulla
         * stringa intera, poi sulla trimmata, poi pattern); ritorna l'originale
         * se nessuna traduzione produce un risultato diverso.
         */
        function translateString( text ) {
                if ( typeof text !== 'string' || ! text ) {
                        return text;
                }
                if ( Object.prototype.hasOwnProperty.call( map, text ) ) {
                        var direct = map[ text ];
                        if ( direct && direct !== text ) {
                                return direct;
                        }
                }
                var hit = lookup( text );
                if ( hit ) {
                        return hit.translated;
                }
                return applyPatterns( text );
        }

        window.NexusI18nTranslate = translateString;

        /* ---------------- nodi testo ---------------- */

        function translateTextNode( node ) {
                var original = node.nodeValue;
                if ( ! original ) {
                        return;
                }
                var hit = lookup( original );
                if ( hit ) {
                        // Preserva gli spazi originali: sostituisce solo la parte trimmata.
                        var replaced = original.replace( hit.trimmed, hit.translated );
                        if ( replaced !== original ) {
                                node.nodeValue = replaced;
                        }
                        return;
                }
                // Match esatto fallito: prova i pattern sulla parte trimmata,
                // ricomponendo il nodo con gli spazi originali di testa e coda.
                var trimmed = original.trim();
                if ( ! trimmed ) {
                        return;
                }
                var translated = applyPatterns( trimmed );
                if ( translated === trimmed ) {
                        return;
                }
                var leadMatch = original.match( /^\s*/ );
                var trailMatch = original.match( /\s*$/ );
                var lead = leadMatch ? leadMatch[ 0 ] : '';
                var trail = trailMatch ? trailMatch[ 0 ] : '';
                node.nodeValue = lead + translated + trail;
        }

        function walkText( root ) {
                try {
                        var walker = document.createTreeWalker( root, NodeFilter.SHOW_TEXT, null, false );
                        var nodes = [];
                        var current = walker.nextNode();
                        while ( current ) {
                                if ( ! isSkip( current.parentNode ) ) {
                                        nodes.push( current );
                                }
                                current = walker.nextNode();
                        }
                        for ( var i = 0; i < nodes.length; i++ ) {
                                translateTextNode( nodes[ i ] );
                        }
                } catch ( e ) {
                        /* degrada con grazia: nessun blocco dell'admin */
                }
        }

        /* ---------------- attributi (placeholder/title/aria-label/value di bottoni) ---------------- */

        function setAttrTranslated( el, name ) {
                var value = el.getAttribute( name );
                if ( ! value ) {
                        return;
                }
                var hit = lookup( value );
                if ( hit ) {
                        el.setAttribute( name, value.replace( hit.trimmed, hit.translated ) );
                        return;
                }
                var translated = applyPatterns( value );
                if ( translated !== value ) {
                        el.setAttribute( name, translated );
                }
        }

        function translateAttrs( root ) {
                try {
                        var els = root.querySelectorAll ? root.querySelectorAll( '[placeholder], [title], [aria-label], input[type="button"], input[type="submit"]' ) : [];
                        for ( var i = 0; i < els.length; i++ ) {
                                var el = els[ i ];
                                if ( isSkip( el ) ) {
                                        continue;
                                }
                                var tag = el.nodeName;
                                var type = ( el.getAttribute( 'type' ) || '' ).toLowerCase();

                                // value: SOLO bottoni (input button/submit), mai input testo/hidden.
                                if ( ( tag === 'INPUT' ) && ( type === 'button' || type === 'submit' ) ) {
                                        var v = el.value;
                                        var vHit = lookup( v );
                                        if ( vHit ) {
                                                el.value = v.replace( vHit.trimmed, vHit.translated );
                                        } else {
                                                var vTranslated = applyPatterns( v );
                                                if ( vTranslated !== v ) {
                                                        el.value = vTranslated;
                                                }
                                        }
                                }
                                if ( tag !== 'INPUT' && el.hasAttribute( 'placeholder' ) ) {
                                        setAttrTranslated( el, 'placeholder' );
                                }
                                if ( el.hasAttribute( 'title' ) ) {
                                        setAttrTranslated( el, 'title' );
                                }
                                if ( el.hasAttribute( 'aria-label' ) ) {
                                        setAttrTranslated( el, 'aria-label' );
                                }
                        }
                } catch ( e ) {
                        /* degrada con grazia */
                }
        }

        /* ---------------- batch di mutazioni ---------------- */

        function processNodes( nodes ) {
                for ( var i = 0; i < nodes.length; i++ ) {
                        var n = nodes[ i ];
                        if ( ! n ) {
                                continue;
                        }
                        if ( n.nodeType === 3 ) {
                                if ( ! isSkip( n.parentNode ) ) {
                                        translateTextNode( n );
                                }
                        } else if ( n.nodeType === 1 ) {
                                if ( ! isSkip( n ) ) {
                                        walkText( n );
                                        translateAttrs( n );
                                }
                        }
                }
        }

        function flush() {
                if ( busy || ! pending || ! observer ) {
                        return;
                }
                pending = false;
                busy = true;
                observer.disconnect();
                try {
                        // Riprocessa l'intera root: semplice, idempotente e immune a nodi spostati.
                        walkText( rootEl() );
                        translateAttrs( rootEl() );
                } catch ( e ) {
                        /* degrada con grazia */
                }
                observer.observe( rootEl(), { childList: true, subtree: true, attributes: true, attributeFilter: [ 'placeholder', 'title', 'aria-label', 'value' ] } );
                busy = false;
        }

        function schedule() {
                pending = true;
                if ( window.requestAnimationFrame ) {
                        window.requestAnimationFrame( flush );
                } else {
                        window.setTimeout( flush, 50 );
                }
        }

        /* ---------------- avvio ---------------- */

        function start() {
                var root = rootEl();
                if ( ! root ) {
                        return;
                }

                compilePatterns();

                // Nessuna traduzione disponibile: niente observer.
                if ( ! hasKeys( map ) && ! patterns.length ) {
                        return;
                }

                // Prima passata completa.
                busy = true;
                walkText( root );
                translateAttrs( root );
                busy = false;

                if ( ! window.MutationObserver ) {
                        return;
                }

                observer = new MutationObserver( function () {
                        schedule();
                } );
                observer.observe( root, { childList: true, subtree: true, attributes: true, attributeFilter: [ 'placeholder', 'title', 'aria-label', 'value' ] } );
        }

        compilePatterns();

        if ( 'loading' !== document.readyState ) {
                start();
        } else {
                document.addEventListener( 'DOMContentLoaded', start );
        }
}() );
