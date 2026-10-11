/**
 * Nexus I18n — traduzione JS dei contenuti dinamici (v1.0.5, sperimentale).
 *
 * Riceve via wp_localize_script:
 *   window.NexusI18n = { enabled: true, map: { 'Stringa IT': 'IT → EN…' } }
 *
 * Meccanismo: traduce i nodi TESTO e alcuni attributi (placeholder/title/value di
 * bottoni) renderizzati dinamicamente da admin.js, con match ESATTO della stringa
 * trimmata. La traduzione lato PHP delle viste avviene in Nexus_I18n::translate_html().
 *
 * Anti-loop: durante la sostituzione l'observer è disconnesso; le mutazioni generate
 * dalla traduzione stessa non vengono riprocessate. Skip su script/style/textarea/input testuali.
 */
( function () {
        'use strict';

        if ( ! window.NexusI18n || ! NexusI18n.enabled || ! NexusI18n.map ) {
                return;
        }

        var map = NexusI18n.map || {};
        var hasKeys = false;
        for ( var k in map ) {
                if ( Object.prototype.hasOwnProperty.call( map, k ) ) {
                        hasKeys = true;
                        break;
                }
        }
        if ( ! hasKeys ) {
                return;
        }

        var SKIP = { SCRIPT: 1, STYLE: 1, TEXTAREA: 1, NOSCRIPT: 1, CODE: 1, PRE: 1 };
        var busy = false;
        var pending = false;
        var observer = null;

        /* ---------------- utilità ---------------- */

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

        /* ---------------- nodi testo ---------------- */

        function translateTextNode( node ) {
                var original = node.nodeValue;
                if ( ! original ) {
                        return;
                }
                var hit = lookup( original );
                if ( ! hit ) {
                        return;
                }
                // Preserva gli spazi originali: sostituisce solo la parte trimmata.
                var replaced = original.replace( hit.trimmed, hit.translated );
                if ( replaced !== original ) {
                        node.nodeValue = replaced;
                }
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

        /* ---------------- attributi (placeholder/title/value di bottoni) ---------------- */

        function translateAttrs( root ) {
                try {
                        var els = root.querySelectorAll ? root.querySelectorAll( '[placeholder], [title], input[type="button"], input[type="submit"]' ) : [];
                        for ( var i = 0; i < els.length; i++ ) {
                                var el = els[ i ];
                                if ( isSkip( el ) ) {
                                        continue;
                                }
                                var tag = el.nodeName;
                                var type = ( el.getAttribute( 'type' ) || '' ).toLowerCase();

                                // value: SOLO bottoni (input button/submit), mai input testo/hidden.
                                if ( ( tag === 'INPUT' ) && ( type === 'button' || type === 'submit' ) ) {
                                        var vHit = lookup( el.value );
                                        if ( vHit ) {
                                                el.value = el.value.replace( vHit.trimmed, vHit.translated );
                                        }
                                }
                                if ( tag !== 'INPUT' && el.hasAttribute( 'placeholder' ) ) {
                                        var ph = el.getAttribute( 'placeholder' );
                                        var phHit = lookup( ph );
                                        if ( phHit ) {
                                                el.setAttribute( 'placeholder', ph.replace( phHit.trimmed, phHit.translated ) );
                                        }
                                }
                                if ( el.hasAttribute( 'title' ) ) {
                                        var ti = el.getAttribute( 'title' );
                                        var tiHit = lookup( ti );
                                        if ( tiHit ) {
                                                el.setAttribute( 'title', ti.replace( tiHit.trimmed, tiHit.translated ) );
                                        }
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
                observer.observe( rootEl(), { childList: true, subtree: true } );
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
                observer.observe( root, { childList: true, subtree: true, attributes: true, attributeFilter: [ 'placeholder', 'title' ] } );
        }

        if ( 'loading' !== document.readyState ) {
                start();
        } else {
                document.addEventListener( 'DOMContentLoaded', start );
        }
}() );
