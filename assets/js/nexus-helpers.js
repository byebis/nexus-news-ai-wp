/**
 * Nexus News AI — Helper JavaScript CON DIVISI tra le viste admin.
 *
 * Consolidamento: le implementazioni identiche (o quasi) di
 * esc()/api() che in precedenza erano ridefinite inline in ogni vista
 * (admin/views/google-wizard.php, admin/views/jobs.php, ecc.) sono state
 * centralizzate qui per eliminare il rischio di collisione segnalato da QA
 * (LOW-MEDIUM). Anche qs() (troncato, duplicato in redazioni.php) è
 * disponibile qui per il riuso.
 *
 * Apposizione: namespace globale sobrio `window.NexusHelpers`. Guardia: se
 * il namespace esiste già, NON viene riscritto (primo che carica vince).
 *
 * Ordine di caricamento (vedi Nexus_Admin::enqueue_assets):
 *   - il file è enqueuato con in_footer=FALSE (head), così che risulti
 *     disponibile PRIMA di ogni <script> inline delle viste, che girano
 *     DURANTE il parsing del body (quindi prima dei footer).
 *   - api() legge restUrl/nonce da window.NexusAdminData AL MOMENTO della
 *     chiamata (non al load), perché NexusAdminData viene localizzato su
 *     admin.js, che sta nei footer.
 *
 * @package Nexus_News_AI
 */

( function () {
        'use strict';

        /* Guardia: se il namespace esiste già (es. caricato due volte o da
         * un altro stream), NON riscrivere. */
        if ( window.NexusHelpers ) {
                return;
        }

        /* Escape di ogni valore dinamico prima di innerHTML (contratto T-05).
         * Stessa semantica delle copie storiche di google-wizard.php/jobs.php. */
        function esc( value ) {
                return String( value === null || value === undefined ? '' : value )
                        .replace( /&/g, '&amp;' )
                        .replace( /</g, '&lt;' )
                        .replace( />/g, '&gt;' )
                        .replace( /\x22/g, '&quot;' )
                        .replace( /\x27/g, '&#039;' );
        }

        /* Wrapper fetch: nonce X-WP-Nonce + JSON; errore HTTP → Error col
         * messaggio del body. Superset delle copie storiche (include il merge
         * degli header extra di jobs.php e lo stripping dello slash finale di
         * base, che è innocuo per gli altri chiamanti). restUrl/nonce vengono
         * letti da window.NexusAdminData al momento della chiamata. */
        function api( path, opts ) {
                opts = opts || {};
                var D = window.NexusAdminData;
                var base = D && D.restUrl ? String( D.restUrl ).replace( /\/+$/, '' ) : '';
                var nonce = D && D.nonce ? D.nonce : '';
                var headers = { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' };
                var init = { method: opts.method || 'GET', headers: headers, credentials: 'same-origin' };
                var key;
                if ( opts.headers ) {
                        for ( key in opts.headers ) {
                                if ( Object.prototype.hasOwnProperty.call( opts.headers, key ) ) {
                                        headers[ key ] = opts.headers[ key ];
                                }
                        }
                }
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

        /* qs: scorciatoia querySelector (troncata, duplicata in redazioni.php). */
        function qs( selector, root ) {
                return ( root || document ).querySelector( selector );
        }

        window.NexusHelpers = { esc: esc, api: api, qs: qs };
} )();
