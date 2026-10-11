/**
 * Nexus Blocks — registrazione blocchi Gutenberg lato editor (NESSUN build step).
 *
 * Blocchi registrati (server-side, il markup reale arriva dal render_callback PHP):
 *   - nexus/latest-articles : griglia ultimi articoli (ServerSideRender + InspectorControls)
 *   - nexus/subscribe-form  : form iscrizione newsletter (ServerSideRender + TextControl titolo)
 *
 * Regole di stile (CONTRATTO v1.0.4 §0.8): ES2019, IIFE, var/function, niente JSX,
 * niente optional chaining, commenti in italiano. createElement via wp.element.
 * Nota: nessun valore dinamico remoto viene renderizzato via innerHTML/dangerouslySetInnerHTML,
 * quindi non serve escapare (il markup e' generato dal PHP lato server).
 */
(function () {
        'use strict';

        /* Guardia iniziale: l'editor a blocchi deve essere presente (pacchetto wp.*). */
        if (!window.wp || !wp.blocks || !wp.element) { return; }

        var el = wp.element.createElement;
        var registerBlockType = wp.blocks.registerBlockType;

        /* wp.i18n e' nelle dipendenze, ma resta difensivo: fallback identita' (stringhe IT fisse). */
        var __ = (wp.i18n && typeof wp.i18n.__ === 'function') ? wp.i18n.__ : function (s) { return s; };

        if (typeof registerBlockType !== 'function') { return; }

        /* wp.blockEditor nelle versioni recenti, wp.editor nelle vecchie: usa quella disponibile. */
        var blockEditor = wp.blockEditor || wp.editor || null;
        var components = wp.components || null;
        var serverSideRender = wp.serverSideRender || null;

        /**
         * Anteprima server-side con fallback: se wp.serverSideRender non esiste
         * (editor molto vecchio) mostra un messaggio invece di crashare.
         */
        function renderPreview(blockName, attributes) {
                if (serverSideRender) {
                        return el(serverSideRender, { block: blockName, attributes: attributes });
                }
                return el(
                        'p',
                        { className: 'nexus-block-preview-fallback' },
                        __('Anteprima non disponibile: aggiorna WordPress o controlla la pagina pubblicata.')
                );
        }

        /**
         * Wrapper difensivo per InspectorControls: ritorna null se il pacchetto
         * non e' disponibile (i children null sono innocui per createElement).
         */
        function inspector(children) {
                if (!blockEditor || !blockEditor.InspectorControls) { return null; }
                return el(blockEditor.InspectorControls, {}, children);
        }

        /** Wrapper difensivo per il pannello impostazioni nella sidebar. */
        function settingsPanel(children) {
                if (!components || !components.PanelBody) { return null; }
                return el(components.PanelBody, { title: __('Impostazioni blocco'), initialOpen: true }, children);
        }

        /** Render difensivo di un singolo controllo: componente assente → null (mai crash). */
        function control(component, props) {
                if (!component) { return null; }
                return el(component, props);
        }

        /* =====================================================================
         * Blocco 1: nexus/latest-articles — griglia ultimi articoli.
         * ===================================================================== */
        function latestEdit(props) {
                var attributes = props.attributes;

                var settings = inspector(
                        settingsPanel([
                                control(components && components.RangeControl, {
                                        label: __('Numero articoli'),
                                        value: attributes.count,
                                        min: 1,
                                        max: 24,
                                        onChange: function (v) { props.setAttributes({ count: v }); }
                                }),
                                control(components && components.TextControl, {
                                        label: __('Categoria (vuoto = tutte)'),
                                        value: attributes.category,
                                        onChange: function (v) { props.setAttributes({ category: v }); }
                                }),
                                control(components && components.RangeControl, {
                                        label: __('Colonne'),
                                        value: attributes.columns,
                                        min: 1,
                                        max: 4,
                                        onChange: function (v) { props.setAttributes({ columns: v }); }
                                }),
                                control(components && components.ToggleControl, {
                                        label: __('Mostra immagini'),
                                        checked: !!attributes.show_images,
                                        onChange: function (v) { props.setAttributes({ show_images: v }); }
                                })
                        ])
                );

                return el(
                        wp.element.Fragment,
                        {},
                        settings,
                        el('div', { className: 'nexus-block-preview' }, renderPreview('nexus/latest-articles', props.attributes))
                );
        }

        /* =====================================================================
         * Blocco 2: nexus/subscribe-form — form iscrizione newsletter.
         * ===================================================================== */
        function subscribeEdit(props) {
                var settings = inspector(
                        settingsPanel([
                                control(components && components.TextControl, {
                                        label: __('Titolo del modulo'),
                                        value: props.attributes.title,
                                        onChange: function (v) { props.setAttributes({ title: v }); }
                                })
                        ])
                );

                return el(
                        wp.element.Fragment,
                        {},
                        settings,
                        el('div', { className: 'nexus-block-preview' }, renderPreview('nexus/subscribe-form', props.attributes))
                );
        }

        registerBlockType('nexus/latest-articles', {
                title: __('Nexus: Ultimi Articoli'),
                description: __('Griglia degli ultimi articoli della redazione AI.'),
                icon: 'megaphone',
                category: 'widgets',
                keywords: [__('nexus'), __('articoli'), __('news')],
                supports: {},
                attributes: {
                        count: { type: 'integer', default: 6 },
                        category: { type: 'string', default: '' },
                        columns: { type: 'integer', default: 3 },
                        show_images: { type: 'boolean', default: true }
                },
                edit: latestEdit,
                save: function () { return null; }
        });

        registerBlockType('nexus/subscribe-form', {
                title: __('Nexus: Iscrizione Newsletter'),
                description: __('Form di iscrizione alla newsletter della redazione AI.'),
                icon: 'email',
                category: 'widgets',
                keywords: [__('nexus'), __('newsletter'), __('iscrizione')],
                supports: {},
                attributes: {
                        title: { type: 'string', default: 'Iscriviti alla newsletter' }
                },
                edit: subscribeEdit,
                save: function () { return null; }
        });
})();
