<?php
/**
 * Vista Admin: Impostazioni (pagina "settings" di Nexus News AI).
 *
 * I campi sono PRECOMPILATI server-side con Nexus_DB::get_settings()
 * (value / checked / selected): la pagina è già corretta prima che admin.js risponda.
 *
 * CONTRATTO FISSO CON admin.js (non rinominare id e name):
 * - form#nxa-settings-form.nxa-settings-form: submit intercettato; admin.js
 *   serializza i campi come name=value ed esegue PUT /settings (nexus/v1).
 * - I 16 toggle (auto_collect, auto_evaluate, auto_rewrite, auto_publish,
 *   enable_wp_posts, enable_ai_images, enable_seo, seo_generate,
 *   enable_telegram, enable_discord, enable_x, enable_linkedin,
 *   enable_newsletter, enable_digest, enable_indexnow, enable_websub) sono
 *   checkbox value="1": admin.js li serializza come 0/1 (0 quando deselezionati).
 * - Le 5 piattaforme social sono checkbox name="social_platforms[]" con
 *   value = blog|twitter|facebook|linkedin|instagram: admin.js serializza le
 *   voci selezionate in stringa CSV nel campo social_platforms.
 * - Tutti gli altri campi usano name = chiave esatta di Nexus_DB::default_settings():
 *   mode, collect_interval, max_articles_per_day, auto_approve_score,
 *   site_name, site_tagline, ai_base_url, ai_model, ai_api_key,
 *   post_status, image_model, image_size,
 *   telegram_bot_token, telegram_chat_id, discord_webhook_url,
 *   x_api_key, x_api_secret, x_access_token, x_access_secret,
 *   linkedin_access_token, linkedin_author_urn, newsletter_recipients,
 *   indexnow_key.
 * - ai_api_key: input type="password" (mai mostrata in chiaro a schermo);
 *   il value è stampato con esc_attr.
 * - Le credenziali social (v1.0.2) sono input type="text" (scelta consapevole,
 *   coerente con il resto della pagina) con autocomplete="new-password";
 *   anche il loro value è stampato con esc_attr.
 * - indexnow_key è readonly: la chiave è generata automaticamente alla prima
 *   pubblicazione (Nexus_Ping) e non è modificabile da schermo.
 * - CONTRATTO v1.0.3 ("Redazione intelligente"): 5 campi simple con name =
 *   chiave esatta di Nexus_DB::get_settings() (ai_model_cheap,
 *   factcheck_min_score, monthly_budget_usd, budget_action, translation_langs)
 *   serializzati come name=value; 2 nuovi toggle checkbox value="1"
 *   (enable_factcheck, enable_translations) che admin.js gestisce nel blocco
 *   intelFlags serializzandoli 0/1 (0 quando deselezionati), stesso pattern
 *   dei 16 toggle v1.0.2. Campi precompilati con get_settings() (value /
 *   checked / selected) come le sezioni esistenti.
 * - CONTRATTO v1.0.4 ("Scala & Pro"): 1 toggle checkbox value="1"
 *   (enable_queue) serializzato da admin.js nel blocco scalaFlags come 0/1
 *   (0 quando deselezionato), stesso pattern dei toggle esistenti; 2 campi
 *   numerici (queue_batch_size, queue_max_attempts) raccolti nel blocco
 *   numbers. Campi precompilati con get_settings() (value / checked) come
 *   le sezioni esistenti.
 * - CONTRATTO v1.0.5 ("Lingua e API pubblica"): 1 select simple
 *   (admin_language: auto|it|en) raccolto da admin.js nell'array simple;
 *   1 toggle checkbox value="1" (enable_public_api) serializzato da admin.js
 *   nel blocco scalaFlags come 0/1 (0 quando deselezionato), stesso pattern
 *   dei toggle esistenti. Campi precompilati con get_settings() (selected /
 *   checked, con fallback ?? sulle chiavi nuove) come le sezioni esistenti.
 * - CONTRATTO v1.0.6 ("UI chiara & curata"): 1 select simple (admin_theme:
 *   light|dark, sezione "Interfaccia") raccolto da admin.js nell'array
 *   simple leggendo il select #nxa-admin-theme; campo precompilato con
 *   get_settings() (selected, con fallback ?? 'light') come le sezioni
 *   esistenti. Il tema scuro aggiunge la classe nexus-theme-dark al body
 *   dell'admin (filtro admin_body_class); il tema chiaro è il default.
 * - CONTRATTO v1.0.7 ("Impostazioni & Agenti Pro", T-01): le 13 sezioni
 *   <h2 class="title"> sono riorganizzate in 4 GRUPPI con accordion
 *   (h2.nxa-settings-group-title + section.nxa-acc[data-nxa-acc] con
 *   button.nxa-acc-toggle[aria-expanded][aria-controls], span.nxa-acc-label,
 *   span.nxa-acc-chevron, div.nxa-acc-panel[hidden]). Slug data-nxa-acc /
 *   id pannello: pipeline, limits, ai, intelligence, wp-seo, social-publish,
 *   social-distrib, newsletter, indexing, site-identity, scala,
 *   language-api, interface. Gruppi: "Pipeline editoriale" (pipeline,
 *   limits), "Intelligenza artificiale" (ai, intelligence), "Pubblicazione &
 *   distribuzione" (wp-seo, social-publish, social-distrib, newsletter,
 *   indexing), "Sito & sistema" (site-identity, scala, language-api,
 *   interface). Il testo delle vecchie sezioni h2 è conservato ESATTO nei
 *   .nxa-acc-label; le table.form-table sono SPOSTATE nei pannelli e restano
 *   IDENTICHE al loro interno. NESSUN id/name/for/value dei campi è
 *   cambiato: collectSettings (admin.js) usa form.elements[name] e
 *   qsa(input[name=…]) e i campi nei pannelli chiusi (hidden = display:none,
 *   non disabled) restano serializzati. LO STATO dei gruppi (array slug
 *   aperti) è persistito in localStorage['nxa-settings-acc'] (JSON, con
 *   try/catch) dall'IIFE ES2019 in fondo al file (solo toggle accordion,
 *   nessun fetch); default senza localStorage: pannello "pipeline" aperto,
 *   gli altri chiusi (stato renderizzato anche server-side, così senza JS
 *   la prima sezione resta leggibile). Le classi accordion sono definite
 *   nel CSS condiviso (T-04): qui NIENTE style inline. Sezione
 *   "Configurazione AI": input #nxa-ai-model riceve list=
 *   "nxa-ai-models-datalist" (id invariato) e nuova riga con bottone
 *   #nxa-ai-models-load, span #nxa-ai-models-status (aria-live="polite") e
 *   datalist #nxa-ai-models-datalist: i modelli si caricano dalla REST
 *   route /ai/models (admin.js T-03, cache 12h per base_url) con la chiave
 *   salvata, al click sul bottone o al blur del campo chiave — qui solo
 *   markup statico.
 *
 * - CONTRATTO v1.1.0 ("Tutto nativo", T-06): la checkbox enable_wp_posts
 *   (id e name invariati) ora pubblica post WordPress NATIVI del sito
 *   (post type "post", visibili in home, con categorie, tag e autore reali):
 *   label e descrizione aggiornate di conseguenza. Il select post_status
 *   (#nxa-post-status) ammette anche "pending" (In attesa di revisione):
 *   flusso WordPress bozza, revisione, pubblicato. Nessun name o id
 *   rinominato: collectSettings (admin.js) resta valido.
 *
 * - CONTRATTO v1.1.2 ("Multilingua nativo", T-06): nel pannello "Redazione
 *   intelligente" (gruppo "Intelligenza artificiale") nuova sub-sezione di
 *   righe "Multilingua — post tradotti nativi": badge testuale del driver di
 *   lingua (Nexus_Multilang::driver(), guardia class_exists: degrada senza
 *   fatal), elenco delle lingue del sito con marcatura della predefinita
 *   (site_langs + lang_name + default_lang, tutto esc_html), 1 toggle
 *   checkbox value="1" (enable_autotranslate_posts, id
 *   nxa-enable-autotranslate-posts, checked da get_settings con accesso
 *   difensivo isset) e nota onesta sui contenuti nativi. La checkbox e'
 *   serializzata 0 o 1 da admin.js v1.1.2 nel blocco intelFlags (contratto
 *   T-03) e la chiave esiste gia' in Nexus_DB::default_settings(). Le righe
 *   informative sono renderizzate server-side a ogni caricamento pagina
 *   (nessuna REST). Stile locale in <style> di coda: SOLO classi prefisso
 *   .nxa-ml- via token design system (stesso pattern delle viste jobs.php
 *   e tools.php).
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

$settings         = Nexus_DB::get_settings();
$active_platforms = array_filter( array_map( 'trim', explode( ',', (string) $settings['social_platforms'] ) ) );

/* Sub-sezione v1.1.2 "Multilingua — post tradotti nativi": valori dal driver
 * Nexus_Multilang con guardia class_exists (mai fatale, degradazione onesta).
 * La chiave enable_autotranslate_posts esiste gia' in
 * Nexus_DB::default_settings(): accesso difensivo con isset per robustezza. */
$ml_on      = class_exists( 'Nexus_Multilang' );
$ml_driver  = $ml_on ? Nexus_Multilang::driver() : 'nexus';
$ml_default = $ml_on ? Nexus_Multilang::default_lang() : '';

$ml_lang_labels = array();
if ( $ml_on ) {
        foreach ( Nexus_Multilang::site_langs() as $ml_code ) {
                $ml_code = sanitize_key( (string) $ml_code );
                if ( '' === $ml_code ) {
                        continue;
                }
                $ml_label = (string) Nexus_Multilang::lang_name( $ml_code );
                if ( $ml_code === $ml_default ) {
                        $ml_label .= ' (' . __( 'predefinita', 'nexus-news-ai' ) . ')';
                }
                $ml_lang_labels[] = $ml_label;
        }
}

if ( $ml_on && 'polylang' === $ml_driver ) {
        $ml_driver_badge = __( 'Polylang è attivo: le associazioni tra lingue usano Polylang.', 'nexus-news-ai' );
} elseif ( $ml_on && 'wpml' === $ml_driver ) {
        $ml_driver_badge = __( 'WPML è attivo: le associazioni tra lingue usano WPML.', 'nexus-news-ai' );
} elseif ( $ml_on ) {
        $ml_driver_badge = __( 'Collegamento interno Nexus: nessun plugin di lingua attivo (compatibile con Polylang e WPML se li installerai).', 'nexus-news-ai' );
} else {
        $ml_driver_badge = __( 'Il modulo multilingua non risulta caricato: stato non verificabile.', 'nexus-news-ai' );
}

if ( $ml_on ) {
        $ml_langs_text = ! empty( $ml_lang_labels ) ? implode( ', ', $ml_lang_labels ) : __( 'Nessuna lingua attiva rilevata.', 'nexus-news-ai' );
} else {
        $ml_langs_text = __( 'Elenco non disponibile: il modulo multilingua non risulta caricato.', 'nexus-news-ai' );
}

$ml_autotranslate = isset( $settings['enable_autotranslate_posts'] ) ? (int) $settings['enable_autotranslate_posts'] : 0;

$social_platforms = array(
        'blog'      => __( 'Blog (WordPress)', 'nexus-news-ai' ),
        'twitter'   => __( 'Twitter / X', 'nexus-news-ai' ),
        'facebook'  => __( 'Facebook', 'nexus-news-ai' ),
        'linkedin'  => __( 'LinkedIn', 'nexus-news-ai' ),
        'instagram' => __( 'Instagram', 'nexus-news-ai' ),
);
?>
<div class="wrap nexus-admin" data-page="settings">
        <h1><?php esc_html_e( 'Impostazioni', 'nexus-news-ai' ); ?></h1>

        <form id="nxa-settings-form" class="nxa-settings-form">
                <h2 class="nxa-settings-group-title"><?php esc_html_e( 'Pipeline editoriale', 'nexus-news-ai' ); ?></h2>

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

                <h2 class="nxa-settings-group-title"><?php esc_html_e( 'Intelligenza artificiale', 'nexus-news-ai' ); ?></h2>

                <section class="nxa-acc" data-nxa-acc="ai">
                        <button type="button" class="nxa-acc-toggle" aria-expanded="false" aria-controls="nxa-acc-panel-ai">
                                <span class="nxa-acc-label"><?php esc_html_e( 'Configurazione AI', 'nexus-news-ai' ); ?></span>
                                <span class="nxa-acc-chevron" aria-hidden="true"></span>
                        </button>
                        <div class="nxa-acc-panel" id="nxa-acc-panel-ai" hidden>
                                <table class="form-table" role="presentation">
                                        <tbody>
                                                <tr>
                                                        <th scope="row"><label for="nxa-ai-base-url"><?php esc_html_e( 'URL base API AI', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="url" name="ai_base_url" id="nxa-ai-base-url" value="<?php echo esc_attr( $settings['ai_base_url'] ); ?>" class="regular-text code" />
                                                                <p class="description"><?php esc_html_e( 'Endpoint compatibile OpenAI. Predefinito: https://openrouter.ai/api/v1 (OpenRouter).', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-ai-model"><?php esc_html_e( 'Modello AI', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="text" name="ai_model" id="nxa-ai-model" value="<?php echo esc_attr( $settings['ai_model'] ); ?>" list="nxa-ai-models-datalist" class="regular-text code" />
                                                                <p class="description"><?php esc_html_e( "Identificativo del modello (es. openai/gpt-4o-mini). I modelli si caricano dal provider con la chiave salvata: automaticamente all'uscita dal campo Chiave API AI oppure col bottone Carica modelli (cache 12 ore).", 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Modelli disponibili', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <button type="button" id="nxa-ai-models-load" class="nxa-btn is-sm"><?php esc_html_e( 'Carica modelli', 'nexus-news-ai' ); ?></button>
                                                                <span id="nxa-ai-models-status" class="nxa-ai-models-status" aria-live="polite"></span>
                                                                <datalist id="nxa-ai-models-datalist"></datalist>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-ai-api-key"><?php esc_html_e( 'Chiave API AI', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="password" name="ai_api_key" id="nxa-ai-api-key" value="<?php echo esc_attr( $settings['ai_api_key'] ); ?>" class="regular-text code" autocomplete="new-password" />
                                                                <p class="description"><?php esc_html_e( 'Chiave API del provider AI (OpenRouter o endpoint compatibile OpenAI). Salvata nel database del sito, mai mostrata in chiaro.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                        </tbody>
                                </table>
                        </div>
                </section>

                <section class="nxa-acc" data-nxa-acc="intelligence">
                        <button type="button" class="nxa-acc-toggle" aria-expanded="false" aria-controls="nxa-acc-panel-intelligence">
                                <span class="nxa-acc-label"><?php esc_html_e( 'Redazione intelligente', 'nexus-news-ai' ); ?></span>
                                <span class="nxa-acc-chevron" aria-hidden="true"></span>
                        </button>
                        <div class="nxa-acc-panel" id="nxa-acc-panel-intelligence" hidden>
                                <?php /* Sezione v1.0.3 "Redazione intelligente": campi contrattuali con admin.js (5 chiavi simple + blocco checkbox intelFlags). */ ?>
                                <table class="form-table" role="presentation">
                                        <tbody>
                                                <tr>
                                                        <th scope="row"><label for="nxa-ai-model-cheap"><?php esc_html_e( 'Modello economico (screening)', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="text" name="ai_model_cheap" id="nxa-ai-model-cheap" value="<?php echo esc_attr( $settings['ai_model_cheap'] ); ?>" placeholder="vuoto = modello principale" class="regular-text code" />
                                                                <p class="description"><?php esc_html_e( 'Usato per valutazione qualità, fact-check e raccolta: conviene un modello economico.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Fact-check', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <label for="nxa-enable-factcheck">
                                                                        <input type="checkbox" name="enable_factcheck" id="nxa-enable-factcheck" value="1" <?php checked( (int) $settings['enable_factcheck'], 1 ); ?> />
                                                                        <?php esc_html_e( 'Fact-check automatico con citazioni', 'nexus-news-ai' ); ?>
                                                                </label>
                                                                <p class="description"><?php esc_html_e( "Se attivo, dopo la riscrittura l'AI valuta il draft rispetto alla fonte (punteggio 0-100) e raccoglie le citazioni.", 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-factcheck-min-score"><?php esc_html_e( 'Soglia minima fact-check (0-100)', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="number" name="factcheck_min_score" id="nxa-factcheck-min-score" value="<?php echo esc_attr( $settings['factcheck_min_score'] ); ?>" min="0" max="100" step="1" class="small-text" />
                                                                <p class="description"><?php esc_html_e( "Sotto questa soglia l'articolo torna in bozza.", 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-monthly-budget-usd"><?php esc_html_e( 'Budget mensile AI (USD)', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="number" name="monthly_budget_usd" id="nxa-monthly-budget-usd" value="<?php echo esc_attr( $settings['monthly_budget_usd'] ); ?>" min="0" step="0.01" class="small-text" />
                                                                <p class="description"><?php esc_html_e( '0 = nessun limite. Le stime si basano sui token usati.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-budget-action"><?php esc_html_e( 'Quando il budget è esaurito', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <select name="budget_action" id="nxa-budget-action">
                                                                        <option value="stop" <?php selected( $settings['budget_action'], 'stop' ); ?>><?php esc_html_e( 'Ferma le fasi AI', 'nexus-news-ai' ); ?></option>
                                                                        <option value="warn" <?php selected( $settings['budget_action'], 'warn' ); ?>><?php esc_html_e( 'Solo avviso', 'nexus-news-ai' ); ?></option>
                                                                </select>
                                                                <p class="description"><?php esc_html_e( 'Ferma le fasi AI: le fasi successive del tick vengono saltate. Solo avviso: registra un avviso nel feed attività e prosegui.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Traduzioni AI', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <label for="nxa-enable-translations">
                                                                        <input type="checkbox" name="enable_translations" id="nxa-enable-translations" value="1" <?php checked( (int) $settings['enable_translations'], 1 ); ?> />
                                                                        <?php esc_html_e( 'Traduzioni AI degli articoli', 'nexus-news-ai' ); ?>
                                                                </label>
                                                                <p class="description"><?php esc_html_e( "Se attivo, gli articoli pubblicati possono essere tradotti con l'AI nelle lingue configurate.", 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-translation-langs"><?php esc_html_e( 'Lingue di traduzione', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="text" name="translation_langs" id="nxa-translation-langs" value="<?php echo esc_attr( $settings['translation_langs'] ); ?>" placeholder="en,es,fr" class="regular-text code" />
                                                                <p class="description"><?php esc_html_e( 'Codici separati da virgola. Supportati: en, es, fr, de, pt, ar, zh, ja.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <?php /* Sub-sezione v1.1.2 "Multilingua — post tradotti nativi": righe informative dal driver di lingua + toggle enable_autotranslate_posts (serializzato dal blocco intelFlags di admin.js, contratto T-03). */ ?>
                                                <tr class="nxa-ml-head">
                                                        <th colspan="2"><?php esc_html_e( 'Multilingua — post tradotti nativi', 'nexus-news-ai' ); ?></th>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Driver di lingua', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <p><span class="nxa-ml-badge"><?php echo esc_html( $ml_driver_badge ); ?></span></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Lingue del sito', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <p class="description"><?php echo esc_html( $ml_langs_text ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Traduzioni come post', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <label for="nxa-enable-autotranslate-posts">
                                                                        <input type="checkbox" name="enable_autotranslate_posts" id="nxa-enable-autotranslate-posts" value="1" <?php checked( $ml_autotranslate, 1 ); ?> />
                                                                        <?php esc_html_e( 'Pubblica automaticamente le traduzioni come post', 'nexus-news-ai' ); ?>
                                                                </label>
                                                                <p class="description"><?php esc_html_e( 'Al publish di un articolo, per ogni lingua attiva viene creato il post tradotto collegato (traduzioni AI attive richieste).', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Nota', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <p class="description"><?php esc_html_e( 'I post tradotti restano contenuti nativi di WordPress: sopravvivono al plugin. Le associazioni lingua con Polylang o WPML si gestiscono dalle loro interfacce.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                        </tbody>
                                </table>
                        </div>
                </section>

                <h2 class="nxa-settings-group-title"><?php esc_html_e( 'Pubblicazione & distribuzione', 'nexus-news-ai' ); ?></h2>

                <section class="nxa-acc" data-nxa-acc="wp-seo">
                        <button type="button" class="nxa-acc-toggle" aria-expanded="false" aria-controls="nxa-acc-panel-wp-seo">
                                <span class="nxa-acc-label"><?php esc_html_e( 'Pubblicazione WordPress & SEO', 'nexus-news-ai' ); ?></span>
                                <span class="nxa-acc-chevron" aria-hidden="true"></span>
                        </button>
                        <div class="nxa-acc-panel" id="nxa-acc-panel-wp-seo" hidden>
                                <table class="form-table" role="presentation">
                                        <tbody>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Pubblicazione WordPress', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <label for="nxa-enable-wp-posts">
                                                                        <input type="checkbox" name="enable_wp_posts" id="nxa-enable-wp-posts" value="1" <?php checked( (int) $settings['enable_wp_posts'], 1 ); ?> />
                                                                        <?php esc_html_e( 'Pubblica post WordPress nativi', 'nexus-news-ai' ); ?>
                                                                </label>
                                                                <p class="description"><?php esc_html_e( 'Se attivo, gli articoli pubblicati diventano post WordPress nativi del sito: compaiono nella home, con categorie, tag e autore reali; se disattivo resta il front-end legacy di Nexus.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-post-status"><?php esc_html_e( 'Stato dei post creati', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <select name="post_status" id="nxa-post-status">
                                                                        <option value="publish" <?php selected( $settings['post_status'], 'publish' ); ?>><?php esc_html_e( 'Pubblicati subito', 'nexus-news-ai' ); ?></option>
                                                                        <option value="draft" <?php selected( $settings['post_status'], 'draft' ); ?>><?php esc_html_e( 'Salvati come bozze', 'nexus-news-ai' ); ?></option>
                                                                        <option value="pending" <?php selected( $settings['post_status'], 'pending' ); ?>><?php esc_html_e( 'In attesa di revisione', 'nexus-news-ai' ); ?></option>
                                                                </select>
                                                                <p class="description"><?php esc_html_e( 'Stato WordPress assegnato ai post creati dal publisher (publish = pubblicati, draft = bozze, pending = in attesa di revisione).', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Immagini in evidenza AI', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <label for="nxa-enable-ai-images">
                                                                        <input type="checkbox" name="enable_ai_images" id="nxa-enable-ai-images" value="1" <?php checked( (int) $settings['enable_ai_images'], 1 ); ?> />
                                                                        <?php esc_html_e( "Genera immagini in evidenza con l'AI", 'nexus-news-ai' ); ?>
                                                                </label>
                                                                <p class="description"><?php esc_html_e( "Se attivo, alla pubblicazione viene generata un'immagine in evidenza via AI (fallback: immagine dell'articolo o placeholder locale).", 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-image-model"><?php esc_html_e( 'Modello immagini (vuoto = default)', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="text" name="image_model" id="nxa-image-model" value="<?php echo esc_attr( $settings['image_model'] ); ?>" placeholder="gpt-image-1" class="regular-text code" />
                                                                <p class="description"><?php esc_html_e( 'Identificativo del modello immagini del provider (es. gpt-image-1). Lascia vuoto per usare il modello predefinito.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-image-size"><?php esc_html_e( 'Dimensione immagini', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <select name="image_size" id="nxa-image-size">
                                                                        <option value="1024x1024" <?php selected( $settings['image_size'], '1024x1024' ); ?>><?php esc_html_e( 'Quadrata (1024x1024)', 'nexus-news-ai' ); ?></option>
                                                                        <option value="1792x1024" <?php selected( $settings['image_size'], '1792x1024' ); ?>><?php esc_html_e( 'Orizzontale (1792x1024)', 'nexus-news-ai' ); ?></option>
                                                                        <option value="1024x1792" <?php selected( $settings['image_size'], '1024x1792' ); ?>><?php esc_html_e( 'Verticale (1024x1792)', 'nexus-news-ai' ); ?></option>
                                                                </select>
                                                                <p class="description"><?php esc_html_e( "Dimensione richiesta all'API immagini del provider AI.", 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'SEO on-page', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <label for="nxa-enable-seo">
                                                                        <input type="checkbox" name="enable_seo" id="nxa-enable-seo" value="1" <?php checked( (int) $settings['enable_seo'], 1 ); ?> />
                                                                        <?php esc_html_e( 'Ottimizzazione SEO', 'nexus-news-ai' ); ?>
                                                                </label>
                                                                <p class="description"><?php esc_html_e( 'Se attivo, i post Nexus ricevono meta description, Open Graph e dati strutturati JSON-LD (compatibile con Yoast e RankMath).', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Meta tag AI', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <label for="nxa-seo-generate">
                                                                        <input type="checkbox" name="seo_generate" id="nxa-seo-generate" value="1" <?php checked( (int) $settings['seo_generate'], 1 ); ?> />
                                                                        <?php esc_html_e( "Genera meta title/description con l'AI", 'nexus-news-ai' ); ?>
                                                                </label>
                                                                <p class="description"><?php esc_html_e( "Se attivo, title e description SEO vengono generati con l'AI; in caso contrario usano titolo e riassunto dell'articolo.", 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                        </tbody>
                                </table>
                        </div>
                </section>

                <section class="nxa-acc" data-nxa-acc="social-publish">
                        <button type="button" class="nxa-acc-toggle" aria-expanded="false" aria-controls="nxa-acc-panel-social-publish">
                                <span class="nxa-acc-label"><?php esc_html_e( 'Pubblicazione social', 'nexus-news-ai' ); ?></span>
                                <span class="nxa-acc-chevron" aria-hidden="true"></span>
                        </button>
                        <div class="nxa-acc-panel" id="nxa-acc-panel-social-publish" hidden>
                                <table class="form-table" role="presentation">
                                        <tbody>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Piattaforme di diffusione', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <fieldset>
                                                                        <?php foreach ( $social_platforms as $platform_key => $platform_label ) : ?>
                                                                                <label class="nxa-social-toggle">
                                                                                        <input type="checkbox" name="social_platforms[]" value="<?php echo esc_attr( $platform_key ); ?>" <?php checked( in_array( $platform_key, $active_platforms, true ) ); ?> />
                                                                                        <?php echo esc_html( $platform_label ); ?>
                                                                                </label><br />
                                                                        <?php endforeach; ?>
                                                                </fieldset>
                                                                <p class="description"><?php esc_html_e( 'Seleziona dove diffondere gli articoli pubblicati.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                        </tbody>
                                </table>
                        </div>
                </section>

                <section class="nxa-acc" data-nxa-acc="social-distrib">
                        <button type="button" class="nxa-acc-toggle" aria-expanded="false" aria-controls="nxa-acc-panel-social-distrib">
                                <span class="nxa-acc-label"><?php esc_html_e( 'Distribuzione sociale', 'nexus-news-ai' ); ?></span>
                                <span class="nxa-acc-chevron" aria-hidden="true"></span>
                        </button>
                        <div class="nxa-acc-panel" id="nxa-acc-panel-social-distrib" hidden>
                                <table class="form-table" role="presentation">
                                        <tbody>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Telegram', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <label for="nxa-enable-telegram">
                                                                        <input type="checkbox" name="enable_telegram" id="nxa-enable-telegram" value="1" <?php checked( (int) $settings['enable_telegram'], 1 ); ?> />
                                                                        <?php esc_html_e( 'Attiva', 'nexus-news-ai' ); ?>
                                                                </label>
                                                                <p class="description"><?php esc_html_e( "Se attivo, a ogni pubblicazione viene inviato un messaggio (o una foto con didascalia) nella chat Telegram configurata.", 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-telegram-bot-token"><?php esc_html_e( 'Token del bot Telegram', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="text" name="telegram_bot_token" id="nxa-telegram-bot-token" value="<?php echo esc_attr( $settings['telegram_bot_token'] ); ?>" class="regular-text code" autocomplete="new-password" />
                                                                <p class="description"><?php esc_html_e( 'Token del bot Telegram da @BotFather.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-telegram-chat-id"><?php esc_html_e( 'Chat ID Telegram', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="text" name="telegram_chat_id" id="nxa-telegram-chat-id" value="<?php echo esc_attr( $settings['telegram_chat_id'] ); ?>" class="regular-text code" />
                                                                <p class="description"><?php esc_html_e( 'ID della chat o del canale di destinazione (es. -1001234567890).', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Discord', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <label for="nxa-enable-discord">
                                                                        <input type="checkbox" name="enable_discord" id="nxa-enable-discord" value="1" <?php checked( (int) $settings['enable_discord'], 1 ); ?> />
                                                                        <?php esc_html_e( 'Attiva', 'nexus-news-ai' ); ?>
                                                                </label>
                                                                <p class="description"><?php esc_html_e( 'Se attivo, a ogni pubblicazione viene inviato un messaggio con embed nel canale Discord configurato.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-discord-webhook-url"><?php esc_html_e( 'Webhook Discord', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="text" name="discord_webhook_url" id="nxa-discord-webhook-url" value="<?php echo esc_attr( $settings['discord_webhook_url'] ); ?>" class="regular-text code" autocomplete="new-password" />
                                                                <p class="description"><?php esc_html_e( 'URL del webhook del canale Discord (discord.com/api/webhooks/…).', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'X (Twitter)', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <label for="nxa-enable-x">
                                                                        <input type="checkbox" name="enable_x" id="nxa-enable-x" value="1" <?php checked( (int) $settings['enable_x'], 1 ); ?> />
                                                                        <?php esc_html_e( 'Attiva', 'nexus-news-ai' ); ?>
                                                                </label>
                                                                <p class="description"><?php esc_html_e( "Se attivo, a ogni pubblicazione viene pubblicato un tweet (max 280 caratteri) con il link all'articolo.", 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-x-api-key"><?php esc_html_e( 'API key X', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="text" name="x_api_key" id="nxa-x-api-key" value="<?php echo esc_attr( $settings['x_api_key'] ); ?>" class="regular-text code" autocomplete="new-password" />
                                                                <p class="description"><?php esc_html_e( 'API key dell’app dal Developer Portal di X.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-x-api-secret"><?php esc_html_e( 'API secret X', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="text" name="x_api_secret" id="nxa-x-api-secret" value="<?php echo esc_attr( $settings['x_api_secret'] ); ?>" class="regular-text code" autocomplete="new-password" />
                                                                <p class="description"><?php esc_html_e( 'API secret dell’app (consumer secret).', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-x-access-token"><?php esc_html_e( 'Access token X', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="text" name="x_access_token" id="nxa-x-access-token" value="<?php echo esc_attr( $settings['x_access_token'] ); ?>" class="regular-text code" autocomplete="new-password" />
                                                                <p class="description"><?php esc_html_e( 'Access token OAuth 1.0a dell’account che pubblica.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-x-access-secret"><?php esc_html_e( 'Access secret X', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="text" name="x_access_secret" id="nxa-x-access-secret" value="<?php echo esc_attr( $settings['x_access_secret'] ); ?>" class="regular-text code" autocomplete="new-password" />
                                                                <p class="description"><?php esc_html_e( 'Access token secret OAuth 1.0a dell’account.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'LinkedIn', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <label for="nxa-enable-linkedin">
                                                                        <input type="checkbox" name="enable_linkedin" id="nxa-enable-linkedin" value="1" <?php checked( (int) $settings['enable_linkedin'], 1 ); ?> />
                                                                        <?php esc_html_e( 'Attiva', 'nexus-news-ai' ); ?>
                                                                </label>
                                                                <p class="description"><?php esc_html_e( "Se attivo, a ogni pubblicazione viene creato un post UGC sull'account LinkedIn configurato.", 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-linkedin-access-token"><?php esc_html_e( 'Access token LinkedIn', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="text" name="linkedin_access_token" id="nxa-linkedin-access-token" value="<?php echo esc_attr( $settings['linkedin_access_token'] ); ?>" class="regular-text code" autocomplete="new-password" />
                                                                <p class="description"><?php esc_html_e( 'Access token OAuth 2.0 con permesso w_member_social.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-linkedin-author-urn"><?php esc_html_e( 'Author URN LinkedIn', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="text" name="linkedin_author_urn" id="nxa-linkedin-author-urn" value="<?php echo esc_attr( $settings['linkedin_author_urn'] ); ?>" placeholder="urn:li:person:xxxx" class="regular-text code" />
                                                                <p class="description"><?php esc_html_e( "URN dell'autore del post (es. urn:li:person:xxxx).", 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                        </tbody>
                                </table>
                        </div>
                </section>

                <section class="nxa-acc" data-nxa-acc="newsletter">
                        <button type="button" class="nxa-acc-toggle" aria-expanded="false" aria-controls="nxa-acc-panel-newsletter">
                                <span class="nxa-acc-label"><?php esc_html_e( 'Newsletter', 'nexus-news-ai' ); ?></span>
                                <span class="nxa-acc-chevron" aria-hidden="true"></span>
                        </button>
                        <div class="nxa-acc-panel" id="nxa-acc-panel-newsletter" hidden>
                                <table class="form-table" role="presentation">
                                        <tbody>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Newsletter', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <label for="nxa-enable-newsletter">
                                                                        <input type="checkbox" name="enable_newsletter" id="nxa-enable-newsletter" value="1" <?php checked( (int) $settings['enable_newsletter'], 1 ); ?> />
                                                                        <?php esc_html_e( 'Attiva newsletter e digest settimanale', 'nexus-news-ai' ); ?>
                                                                </label>
                                                                <p class="description"><?php esc_html_e( 'Se attivo, i lettori possono iscriversi con lo shortcode [nexus_subscribe] e il digest settimanale viene inviato via email.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-newsletter-recipients"><?php esc_html_e( 'Destinatari fissi', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="text" name="newsletter_recipients" id="nxa-newsletter-recipients" value="<?php echo esc_attr( $settings['newsletter_recipients'] ); ?>" class="regular-text code" />
                                                                <p class="description"><?php esc_html_e( 'Email fisse separate da virgola, destinatarie del digest oltre agli iscritti confermati.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Digest AI', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <label for="nxa-enable-digest">
                                                                        <input type="checkbox" name="enable_digest" id="nxa-enable-digest" value="1" <?php checked( (int) $settings['enable_digest'], 1 ); ?> />
                                                                        <?php esc_html_e( "Digest testuale generato con l'AI", 'nexus-news-ai' ); ?>
                                                                </label>
                                                                <p class="description"><?php esc_html_e( "Se attivo, l'introduzione del digest settimanale viene scritta dall'AI (elenco titoli e link comunque incluso).", 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                        </tbody>
                                </table>
                        </div>
                </section>

                <section class="nxa-acc" data-nxa-acc="indexing">
                        <button type="button" class="nxa-acc-toggle" aria-expanded="false" aria-controls="nxa-acc-panel-indexing">
                                <span class="nxa-acc-label"><?php esc_html_e( 'Indicizzazione', 'nexus-news-ai' ); ?></span>
                                <span class="nxa-acc-chevron" aria-hidden="true"></span>
                        </button>
                        <div class="nxa-acc-panel" id="nxa-acc-panel-indexing" hidden>
                                <table class="form-table" role="presentation">
                                        <tbody>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'IndexNow', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <label for="nxa-enable-indexnow">
                                                                        <input type="checkbox" name="enable_indexnow" id="nxa-enable-indexnow" value="1" <?php checked( (int) $settings['enable_indexnow'], 1 ); ?> />
                                                                        <?php esc_html_e( 'IndexNow (Bing e altri)', 'nexus-news-ai' ); ?>
                                                                </label>
                                                                <p class="description"><?php esc_html_e( "Se attivo, i permalink dei nuovi post vengono inviati ai motori che supportano il protocollo IndexNow.", 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-indexnow-key"><?php esc_html_e( 'Chiave IndexNow', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="text" name="indexnow_key" id="nxa-indexnow-key" value="<?php echo esc_attr( $settings['indexnow_key'] ); ?>" class="regular-text code" readonly="readonly" />
                                                                <p class="description"><?php esc_html_e( 'Generata automaticamente alla prima pubblicazione.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'WebSub', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <label for="nxa-enable-websub">
                                                                        <input type="checkbox" name="enable_websub" id="nxa-enable-websub" value="1" <?php checked( (int) $settings['enable_websub'], 1 ); ?> />
                                                                        <?php esc_html_e( 'WebSub (PubSubHubbub)', 'nexus-news-ai' ); ?>
                                                                </label>
                                                                <p class="description"><?php esc_html_e( 'Se attivo, il feed del sito viene notificato agli hub WebSub a ogni pubblicazione.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                        </tbody>
                                </table>
                        </div>
                </section>

                <h2 class="nxa-settings-group-title"><?php esc_html_e( 'Sito & sistema', 'nexus-news-ai' ); ?></h2>

                <section class="nxa-acc" data-nxa-acc="site-identity">
                        <button type="button" class="nxa-acc-toggle" aria-expanded="false" aria-controls="nxa-acc-panel-site-identity">
                                <span class="nxa-acc-label"><?php esc_html_e( 'Identità del sito', 'nexus-news-ai' ); ?></span>
                                <span class="nxa-acc-chevron" aria-hidden="true"></span>
                        </button>
                        <div class="nxa-acc-panel" id="nxa-acc-panel-site-identity" hidden>
                                <table class="form-table" role="presentation">
                                        <tbody>
                                                <tr>
                                                        <th scope="row"><label for="nxa-site-name"><?php esc_html_e( 'Nome del sito', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="text" name="site_name" id="nxa-site-name" value="<?php echo esc_attr( $settings['site_name'] ); ?>" class="regular-text" />
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-site-tagline"><?php esc_html_e( 'Tagline del sito', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="text" name="site_tagline" id="nxa-site-tagline" value="<?php echo esc_attr( $settings['site_tagline'] ); ?>" class="regular-text" />
                                                        </td>
                                                </tr>
                                        </tbody>
                                </table>
                        </div>
                </section>

                <section class="nxa-acc" data-nxa-acc="scala">
                        <button type="button" class="nxa-acc-toggle" aria-expanded="false" aria-controls="nxa-acc-panel-scala">
                                <span class="nxa-acc-label"><?php esc_html_e( 'Scala & Pro', 'nexus-news-ai' ); ?></span>
                                <span class="nxa-acc-chevron" aria-hidden="true"></span>
                        </button>
                        <div class="nxa-acc-panel" id="nxa-acc-panel-scala" hidden>
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
  /* Stile locale della vista Impostazioni (v1.1.2): sub-sezione "Multilingua —
     post tradotti nativi". SOLO classi prefisso .nxa-ml- (pattern viste
     jobs.php e tools.php): nessun colore fisso, tutto via token del design
     system (tema chiaro di default, scuro via body.nexus-theme-dark). */
  .nxa-ml-head th {
    padding-top: 26px;
    font-weight: 600;
  }
  .nxa-ml-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 999px;
    border: 1px solid var(--nxa-border);
    background: var(--nxa-surface-2);
    color: var(--nxa-text-body);
    font-size: 12px;
    font-weight: 600;
    line-height: 1.6;
  }
</style>
<script>
( function () {
        'use strict';

        var STORAGE_KEY = 'nxa-settings-acc';
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
