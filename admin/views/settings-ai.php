<?php
/**
 * Vista Admin: Impostazioni AI (pagina "settings-ai" di Nexus News AI).
 *
 * v1.1.6 (contratto T-02): le Impostazioni diventano 4 sottomenu WP; questa è
 * la pagina del gruppo "Intelligenza artificiale" (accordion: ai, providers,
 * chief, tools, intelligence) e raccoglie la seconda parte della vecchia
 * settings.php (rimossa in v1.1.6).
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
 * - Sezione "Configurazione AI": input #nxa-ai-model (name="ai_model") è il
 *   COMBOBOX cercabile WP12 (admin.js nexusModelPicker): dropdown .nxa-mp-panel
 *   con box di ricerca interna (match su id/name, case-insensitive), badge
 *   GRATIS/contesto/prezzo, max 60 risultati con scroll, frecce+Invio per
 *   scegliere, Esc/blur chiude. La lista arriva da GET /ai/models (cache
 *   server 12h per provider, cache client 60s); il provider è '' = globale
 *   (ai_base_url). Bottone #nxa-ai-models-load ("Carica modelli", refresh=1:
 *   invalida la cache server) + span #nxa-ai-models-status (aria-live) invariati
 *   nel contratto; la vecchia <datalist> #nxa-ai-models-datalist è RIMOSSA
 *   (sostituita dal picker WP12).
 * - ai_api_key: input type="password" (mai mostrata in chiaro a schermo);
 *   il value è stampato con esc_attr e inviato SOLO se compilato (password
 *   vuota = nessun cambio). Stesso pattern per le chiavi prov_key_* e per
 *   tools_search_key.
 * - Sezione "Provider AI per agente" (v1.1.10, WP10 — wizard provider): la
 *   lista arriva da Nexus_Providers::catalog() (fallback elenco statico se la
 *   classe non è caricata); le voci CUSTOM (chiave 'cust_*') sono SALTATE
 *   server-side nel loop built-in (la loro chiave vive nell'entry, non nei
 *   settings) e sono gestite dalla sub-sezione dedicata qui sotto. CONTRATTO CON admin.js (non rinominare classi e
 *   data-attribute):
 *     input prov_key_{id}                    — chiave del provider (password);
 *     .nxa-prov-dot[data-nxa-dot="{id}"]     — badge stato (verde = chiave
 *                                              salvata, grigio = vuota; la UI
 *                                              iniziale è renderizzata
 *                                              server-side, admin.js la
 *                                              aggiorna dopo OAuth/scollega);
 *     .nxa-prov-oauth[data-provider]         — login web OAuth PKCE (solo
 *                                              provider con oauth=true nel
 *                                              catalog): POST /ai/oauth/start →
 *                                              redirect auth_url; il ritorno
 *                                              OpenRouter (?nexus_oauth=1&state&
 *                                              code) è completato da admin.js;
 *     .nxa-prov-test[data-provider]          — test connessione vivo: POST
 *                                              /ai/test/{provider} ({base_url}
 *                                              per custom dal campo
 *                                              #nxa-prov-base-custom), esito
 *                                              inline nel
 *                                              .nxa-prov-status[data-nxa-status=
 *                                              "{id}"] (verde/rosso, aria-live);
 *     .nxa-prov-disconnect[data-provider]    — scollega: POST
 *                                              /ai/disconnect/{provider} (per
 *                                              i provider OAuth; hidden se la
 *                                              chiave non è impostata);
 *     "Ottieni chiave"                       — link esterno a key_page del
 *                                              catalog (target _blank);
 *     checkbox allow_private_providers       — flag 0/1 serializzato da
 *                                              admin.js (endpoint privati/LAN).
 * - Sub-sezione "Provider personalizzati" (v1.1.10, F2b/WP11): QUALSIASI
 *   servizio compatibile OpenAI come provider con id 'cust_' + 8 caratteri
 *   esadecimali, storage nell'option 'nexus_custom_providers' gestita SOLO
 *   da Nexus_Providers. La lista è renderizzata da admin.js (GET
 *   /ai/custom-providers: {id, label, base_url, auth, has_key} — api_key
 *   MAI in chiaro). Il form add/edit NON ha attributi name: i campi NON
 *   finiscono mai nel PUT /settings, l'unica via è la REST dedicata.
 *   CONTRATTO CON admin.js (non rinominare id e classi):
 *     #nxa-custprov-list                     — container delle righe
 *                                              .nxa-custprov-row (render JS);
 *                                              il bottone Test di ogni riga
 *                                              riusa .nxa-prov-test[data-
 *                                              provider="cust_…"] del wizard
 *                                              WP10 (POST /ai/test/cust_{id});
 *     #nxa-custprov-add / .nxa-custprov-edit — apre il form (#nxa-custprov-form,
 *                                              hidden di default) per creare
 *                                              o modificare;
 *     .nxa-custprov-del / .nxa-custprov-del-yes / .nxa-custprov-del-no
 *                                            — Elimina con CONFERMA INLINE
 *                                              nella riga (non window.confirm):
 *                                              DELETE /ai/custom-providers/{id};
 *     #nxa-custprov-preset                   — preset 1-click che precompila
 *                                              etichetta + URL base (Together,
 *                                              Fireworks, Perplexity, Cerebras,
 *                                              LM Studio, vLLM);
 *     #nxa-custprov-id / #nxa-custprov-label / #nxa-custprov-base-url /
 *     #nxa-custprov-api-key                  — campi del form (password: vuota
 *                                              su modifica = nessun cambio);
 *     #nxa-custprov-save / #nxa-custprov-cancel — POST /ai/custom-providers
 *                                              (upsert) e chiusura form;
 *     #nxa-custprov-status                   — esito operazioni (aria-live).
 * - Toggle checkbox value="1" (enable_factcheck, enable_translations,
 *   enable_autotranslate_posts, chief_enabled, chief_auto_publish,
 *   tools_enabled, tools_read_page): admin.js li serializza 0/1 (0 quando
 *   deselezionati). Campi numerici: factcheck_min_score, monthly_budget_usd,
 *   chief_max_rounds, chief_id, tools_max_results, tools_read_max_chars,
 *   tools_max_rounds, tools_timeout. Campi testuali/select: ai_base_url,
 *   ai_model, ai_model_cheap, budget_action, translation_langs,
 *   tools_search_provider, mcp_servers_text, prov_base_custom.
 * - Bottone SALVA IN CIMA (subito sotto l'h1) OLTRE quello in fondo: entrambi
 *   type="submit" nella stessa form; admin.js v1.1.6 mostra lo stato di
 *   caricamento su tutti i submit della form.
 * - Sezioni accordion section.nxa-acc[data-nxa-acc] con
 *   button.nxa-acc-toggle[aria-expanded][aria-controls], span.nxa-acc-label,
 *   span.nxa-acc-chevron, div.nxa-acc-panel[hidden]. Lo stato dei gruppi
 *   (array slug aperti) è persistito in localStorage['nxa-settings-acc-ai']
 *   (chiave propria della pagina, JSON con try/catch) dall'IIFE ES5 in fondo
 *   al file (solo toggle accordion, nessun fetch); default senza localStorage:
 *   pannello "ai" aperto, gli altri chiusi (stato renderizzato anche
 *   server-side, così senza JS la prima sezione resta leggibile). Le classi
 *   accordion sono definite nel CSS condiviso: qui solo il micro-stile della
 *   barra Salva in cima e delle classi locali .nxa-ml- (v1.1.2).
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

$settings = Nexus_DB::get_settings();

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

/* Sub-sezioni v1.1.4 "Provider AI per agente", "Capo redattore AI" e
 * "Strumenti di ricerca per gli agenti": valori da Nexus_DB::get_settings()
 * con accesso difensivo (default del contratto v1.1.4 finché le chiavi nuove
 * non esistono in DB).
 * v1.1.10 (WP10): la lista dei provider arriva dal registry
 * Nexus_Providers::catalog() (metadati key_page/oauth per il wizard); l'elenco
 * statico qui sotto resta SOLO come fallback degradato se la classe non è
 * caricata (mai fatal, coerente con lo stile difensivo della vista). */
$prov_keys = array(
        'openrouter' => 'OpenRouter',
        'openai'     => 'OpenAI',
        'anthropic'  => 'Anthropic',
        'google'     => 'Google Gemini',
        'groq'       => 'Groq',
        'deepseek'   => 'DeepSeek',
        'mistral'    => 'Mistral',
        'xai'        => 'xAI (Grok)',
        'custom'     => 'Personalizzato / compatibile OpenAI',
);

$prov_catalog = array();
if ( class_exists( 'Nexus_Providers' ) && method_exists( 'Nexus_Providers', 'catalog' ) ) {
        $prov_catalog = Nexus_Providers::catalog();
}
if ( ! is_array( $prov_catalog ) || empty( $prov_catalog ) ) {
        $prov_catalog = array();
        foreach ( $prov_keys as $prov_fallback_id => $prov_fallback_label ) {
                $prov_catalog[ $prov_fallback_id ] = array(
                        'id'       => $prov_fallback_id,
                        'label'    => $prov_fallback_label,
                        'key_page' => '',
                        'oauth'    => false,
                );
        }
}

$prov_base_custom        = isset( $settings['prov_base_custom'] ) ? (string) $settings['prov_base_custom'] : '';
$allow_private_providers = isset( $settings['allow_private_providers'] ) ? (int) $settings['allow_private_providers'] : 0;

$chief_enabled         = isset( $settings['chief_enabled'] ) ? (int) $settings['chief_enabled'] : 0;
$chief_auto_publish    = isset( $settings['chief_auto_publish'] ) ? (int) $settings['chief_auto_publish'] : 0;
$chief_max_rounds      = isset( $settings['chief_max_rounds'] ) ? (int) $settings['chief_max_rounds'] : 2;
$chief_id              = isset( $settings['chief_id'] ) ? (int) $settings['chief_id'] : 0;

$tools_enabled         = isset( $settings['tools_enabled'] ) ? (int) $settings['tools_enabled'] : 0;
$tools_search_provider = isset( $settings['tools_search_provider'] ) ? (string) $settings['tools_search_provider'] : 'tavily';
$tools_search_key      = isset( $settings['tools_search_key'] ) ? (string) $settings['tools_search_key'] : '';
$tools_max_results     = isset( $settings['tools_max_results'] ) ? (int) $settings['tools_max_results'] : 5;
$tools_read_page       = isset( $settings['tools_read_page'] ) ? (int) $settings['tools_read_page'] : 1;
$tools_read_max_chars  = isset( $settings['tools_read_max_chars'] ) ? (int) $settings['tools_read_max_chars'] : 6000;
$tools_max_rounds      = isset( $settings['tools_max_rounds'] ) ? (int) $settings['tools_max_rounds'] : 3;
$tools_timeout         = isset( $settings['tools_timeout'] ) ? (int) $settings['tools_timeout'] : 15;
$mcp_servers_text      = isset( $settings['mcp_servers_text'] ) ? (string) $settings['mcp_servers_text'] : '';
?>
<div class="wrap nexus-admin" data-page="settings">
        <h1><?php esc_html_e( 'Impostazioni AI', 'nexus-news-ai' ); ?></h1>

        <form id="nxa-settings-form" class="nxa-settings-form">
                <p class="submit nxa-submit-top">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Salva impostazioni', 'nexus-news-ai' ); ?></button>
                </p>

                <section class="nxa-acc" data-nxa-acc="ai">
                        <button type="button" class="nxa-acc-toggle" aria-expanded="true" aria-controls="nxa-acc-panel-ai">
                                <span class="nxa-acc-label"><?php esc_html_e( 'Configurazione AI', 'nexus-news-ai' ); ?></span>
                                <span class="nxa-acc-chevron" aria-hidden="true"></span>
                        </button>
                        <div class="nxa-acc-panel" id="nxa-acc-panel-ai">
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
                                                                <?php /* WP12: combobox cercabile — admin.js (nexusModelPicker) avvolge l'input in span.nxa-mp e apre il dropdown .nxa-mp-panel al focus: ricerca su id/name, badge GRATIS/contesto/prezzo, max 60 risultati, frecce+Invio. Il provider della lista è '' = globale (chiave+base URL qui a fianco). */ ?>
                                                                <input type="text" name="ai_model" id="nxa-ai-model" value="<?php echo esc_attr( $settings['ai_model'] ); ?>" class="regular-text code" autocomplete="off" />
                                                                <p class="description"><?php esc_html_e( "Identificativo del modello (es. openai/gpt-4o-mini). Al focus si apre il picker cercabile: digita per filtrare i modelli del provider, clic o Invio per scegliere, Esc per chiudere.", 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Modelli disponibili', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <button type="button" id="nxa-ai-models-load" class="nxa-btn is-sm"><?php esc_html_e( 'Carica modelli', 'nexus-news-ai' ); ?></button>
                                                                <span id="nxa-ai-models-status" class="nxa-ai-models-status" aria-live="polite"></span>
                                                                <p class="description"><?php esc_html_e( "Forza l'aggiornamento della lista dal provider (invalida la cache di 12 ore). I badge del picker mostrano gratuità, contesto e prezzo per 1M token quando il provider li espone.", 'nexus-news-ai' ); ?></p>
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

                <section class="nxa-acc" data-nxa-acc="providers">
                        <button type="button" class="nxa-acc-toggle" aria-expanded="false" aria-controls="nxa-acc-panel-providers">
                                <span class="nxa-acc-label"><?php esc_html_e( 'Provider AI per agente', 'nexus-news-ai' ); ?></span>
                                <span class="nxa-acc-chevron" aria-hidden="true"></span>
                        </button>
                        <div class="nxa-acc-panel" id="nxa-acc-panel-providers" hidden>
                                <?php /* Sezione v1.1.4 "Provider AI per agente", wizard v1.1.10 (WP10): una riga per provider del catalog Nexus_Providers (input chiave + badge stato + azioni wizard: OAuth OpenRouter, deep link key_page, test connessione, scollega). Dalla v1.1.10 (F2b/WP11) le voci custom del catalog (chiave 'cust_*') sono SALTATE qui: la loro chiave vive nell'entry (non in prov_key_*) e le renderizza la sub-sezione "Provider personalizzati" più sotto. Contratto classi/data-attribute con admin.js: vedi docblock in testa alla vista. */ ?>
                                <table class="form-table" role="presentation">
                                        <tbody>
                                                <?php foreach ( $prov_catalog as $prov_loop_id => $prov_entry ) :
                                                        // v1.1.10 (F2b/WP11): i provider PERSONALIZZATI (chiave 'cust_*')
                                                        // non hanno la riga prov_key_* (la chiave vive nell'entry
                                                        // dell'option nexus_custom_providers, non nei settings).
                                                        if ( 0 === strpos( (string) $prov_loop_id, 'cust_' ) ) {
                                                                continue;
                                                        }
                                                        $prov_id      = is_array( $prov_entry ) && isset( $prov_entry['id'] ) && '' !== trim( (string) $prov_entry['id'] ) ? sanitize_key( (string) $prov_entry['id'] ) : sanitize_key( (string) $prov_loop_id );
                                                        $prov_label   = is_array( $prov_entry ) && isset( $prov_entry['label'] ) ? (string) $prov_entry['label'] : $prov_id;
                                                        $prov_keypage = is_array( $prov_entry ) && isset( $prov_entry['key_page'] ) ? (string) $prov_entry['key_page'] : '';
                                                        $prov_oauth   = is_array( $prov_entry ) && ! empty( $prov_entry['oauth'] );
                                                        $prov_set     = isset( $settings[ 'prov_key_' . $prov_id ] ) && '' !== trim( (string) $settings[ 'prov_key_' . $prov_id ] );
                                                        /* "Rileva Ollama" = il test connessione dell'endpoint locale (auto-detect Ollama, WP10). */
                                                        $prov_test_label = 'ollama' === $prov_id ? __( 'Rileva Ollama', 'nexus-news-ai' ) : __( 'Test', 'nexus-news-ai' );
                                                        ?>
                                                        <tr>
                                                                <th scope="row">
                                                                        <label for="nxa-prov-key-<?php echo esc_attr( $prov_id ); ?>"><?php echo esc_html( $prov_label ); ?></label>
                                                                        <?php /* Badge stato: verde = chiave salvata, grigio = vuota (admin.js aggiorna dopo OAuth/test/scollega). */ ?>
                                                                        <span class="nxa-prov-dot <?php echo $prov_set ? 'nxa-prov-dot--on' : 'nxa-prov-dot--off'; ?>" data-nxa-dot="<?php echo esc_attr( $prov_id ); ?>" title="<?php echo $prov_set ? esc_attr__( 'Chiave configurata', 'nexus-news-ai' ) : esc_attr__( 'Chiave non configurata', 'nexus-news-ai' ); ?>" aria-hidden="true"></span>
                                                                        <span class="screen-reader-text"><?php echo $prov_set ? esc_html__( 'Chiave configurata', 'nexus-news-ai' ) : esc_html__( 'Chiave non configurata', 'nexus-news-ai' ); ?></span>
                                                                </th>
                                                                <td>
                                                                        <input type="password" name="prov_key_<?php echo esc_attr( $prov_id ); ?>" id="nxa-prov-key-<?php echo esc_attr( $prov_id ); ?>" value="<?php echo esc_attr( $settings[ 'prov_key_' . $prov_id ] ?? '' ); ?>" class="regular-text code" autocomplete="new-password" />
                                                                        <p class="nxa-prov-actions">
                                                                                <?php if ( $prov_oauth ) : ?>
                                                                                        <button type="button" class="button button-secondary nxa-prov-oauth" data-provider="<?php echo esc_attr( $prov_id ); ?>">🔗 <?php echo esc_html( sprintf( __( 'Connetti con %s', 'nexus-news-ai' ), $prov_label ) ); ?></button>
                                                                                <?php endif; ?>
                                                                                <?php if ( '' !== $prov_keypage ) : ?>
                                                                                        <a class="button" href="<?php echo esc_url( $prov_keypage ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Ottieni chiave', 'nexus-news-ai' ); ?></a>
                                                                                <?php endif; ?>
                                                                                <button type="button" class="button nxa-prov-test" data-provider="<?php echo esc_attr( $prov_id ); ?>"><?php echo esc_html( $prov_test_label ); ?></button>
                                                                                <?php if ( $prov_oauth ) : ?>
                                                                                        <?php /* Scollega (solo provider OAuth): hidden se la chiave non è impostata; admin.js lo mostra dopo la connessione. */ ?>
                                                                                        <button type="button" class="button-link nxa-prov-disconnect" data-provider="<?php echo esc_attr( $prov_id ); ?>" <?php echo $prov_set ? '' : 'hidden'; ?>><?php esc_html_e( 'Scollega', 'nexus-news-ai' ); ?></button>
                                                                                <?php endif; ?>
                                                                        </p>
                                                                        <?php /* Esito inline del test (verde/rosso, aria-live): riempito da admin.js, mai server-side. */ ?>
                                                                        <p class="description nxa-prov-status" data-nxa-status="<?php echo esc_attr( $prov_id ); ?>" aria-live="polite"></p>
                                                                        <?php if ( 'ollama' === $prov_id ) : ?>
                                                                                <p class="description"><?php esc_html_e( 'Ollama locale (127.0.0.1:11434): il rilevamento funziona se WordPress gira sulla stessa macchina di Ollama (o su Ollama esposto in LAN con l’opzione qui sotto).', 'nexus-news-ai' ); ?></p>
                                                                        <?php endif; ?>
                                                                </td>
                                                        </tr>
                                                <?php endforeach; ?>
                                                <?php /* Sub-sezione v1.1.10 (F2b/WP11) "Provider personalizzati": QUALSIASI servizio compatibile OpenAI. Lista renderizzata da admin.js (GET /ai/custom-providers; api_key MAI in chiaro, solo has_key). Il form NON ha attributi name (i campi NON finiscono nel PUT /settings: l'unica via è la REST dedicata). Contratto completo con admin.js nel docblock in testa alla vista. */ ?>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Provider personalizzati', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <div id="nxa-custprov-list" class="nxa-custprov-list"></div>
                                                                <p class="description" id="nxa-custprov-hint"><?php esc_html_e( 'Nessun provider personalizzato: aggiungine uno per usare qualsiasi servizio compatibile OpenAI (Together, Fireworks, Perplexity, Cerebras, LM Studio, vLLM…).', 'nexus-news-ai' ); ?></p>
                                                                <div id="nxa-custprov-form" class="nxa-custprov-form" hidden>
                                                                        <p>
                                                                                <label for="nxa-custprov-preset"><?php esc_html_e( 'Preset (precompila i campi)', 'nexus-news-ai' ); ?></label>
                                                                                <select id="nxa-custprov-preset">
                                                                                        <option value=""><?php esc_html_e( '— Nessun preset: compilazione manuale —', 'nexus-news-ai' ); ?></option>
                                                                                        <option value="together"><?php echo esc_html( 'Together — https://api.together.xyz/v1' ); ?></option>
                                                                                        <option value="fireworks"><?php echo esc_html( 'Fireworks — https://api.fireworks.ai/inference/v1' ); ?></option>
                                                                                        <option value="perplexity"><?php echo esc_html( 'Perplexity — https://api.perplexity.ai' ); ?></option>
                                                                                        <option value="cerebras"><?php echo esc_html( 'Cerebras — https://api.cerebras.ai/v1' ); ?></option>
                                                                                        <option value="lmstudio"><?php echo esc_html( 'LM Studio — http://localhost:1234/v1' ); ?></option>
                                                                                        <option value="vllm"><?php echo esc_html( 'vLLM — http://localhost:8000/v1' ); ?></option>
                                                                                </select>
                                                                        </p>
                                                                        <p>
                                                                                <label for="nxa-custprov-label"><?php esc_html_e( 'Etichetta', 'nexus-news-ai' ); ?></label>
                                                                                <input type="text" id="nxa-custprov-label" class="regular-text" maxlength="100" placeholder="<?php echo esc_attr( 'es. Together' ); ?>" />
                                                                        </p>
                                                                        <p>
                                                                                <label for="nxa-custprov-base-url"><?php esc_html_e( 'URL base (compatibile OpenAI)', 'nexus-news-ai' ); ?></label>
                                                                                <input type="url" id="nxa-custprov-base-url" class="regular-text code" placeholder="https://api.together.xyz/v1" />
                                                                        </p>
                                                                        <p>
                                                                                <label for="nxa-custprov-api-key"><?php esc_html_e( 'Chiave API', 'nexus-news-ai' ); ?></label>
                                                                                <input type="password" id="nxa-custprov-api-key" class="regular-text code" autocomplete="new-password" placeholder="<?php echo esc_attr( 'su modifica, vuota = nessun cambio' ); ?>" />
                                                                                <span class="description"><?php esc_html_e( 'Mai mostrata in chiaro. Su modifica, vuota = nessun cambio della chiave salvata.', 'nexus-news-ai' ); ?></span>
                                                                        </p>
                                                                        <p class="nxa-prov-actions">
                                                                                <input type="hidden" id="nxa-custprov-id" value="" />
                                                                                <button type="button" class="button button-primary" id="nxa-custprov-save"><?php esc_html_e( 'Salva provider', 'nexus-news-ai' ); ?></button>
                                                                                <button type="button" class="button" id="nxa-custprov-cancel" hidden><?php esc_html_e( 'Annulla', 'nexus-news-ai' ); ?></button>
                                                                        </p>
                                                                        <p class="description nxa-prov-status" id="nxa-custprov-status" aria-live="polite"></p>
                                                                </div>
                                                                <p class="nxa-prov-actions">
                                                                        <button type="button" class="button button-secondary" id="nxa-custprov-add">+ <?php esc_html_e( 'Aggiungi provider personalizzato', 'nexus-news-ai' ); ?></button>
                                                                </p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Endpoint su IP privati/localhost', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <label for="nxa-allow-private-providers" title="<?php esc_attr_e( 'Solo se usi LM Studio/vLLM su LAN; Ollama è sempre ammesso.', 'nexus-news-ai' ); ?>">
                                                                        <input type="checkbox" name="allow_private_providers" id="nxa-allow-private-providers" value="1" <?php checked( $allow_private_providers, 1 ); ?> />
                                                                        <?php esc_html_e( 'Consenti endpoint AI su IP privati/localhost', 'nexus-news-ai' ); ?>
                                                                </label>
                                                                <p class="description"><?php esc_html_e( 'Solo se usi LM Studio/vLLM su LAN; Ollama è sempre ammesso.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-prov-base-custom"><?php esc_html_e( 'URL base del provider Personalizzato', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="text" name="prov_base_custom" id="nxa-prov-base-custom" value="<?php echo esc_attr( $prov_base_custom ); ?>" placeholder="https://esempio.it/v1" class="regular-text code" />
                                                                <p class="description"><?php esc_html_e( 'Endpoint compatibile OpenAI usato dal provider Personalizzato (es. proxy interno o modello self-hosted). Il bottone Test può verificarla anche prima del salvataggio.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Nota', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <p class="description"><?php esc_html_e( 'Ogni agente può scegliere provider e modello nella pagina Agenti; senza chiave qui il provider non è selezionabile. Il provider globale (sopra) resta il fallback.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                        </tbody>
                                </table>
                        </div>
                </section>

                <section class="nxa-acc" data-nxa-acc="chief">
                        <button type="button" class="nxa-acc-toggle" aria-expanded="false" aria-controls="nxa-acc-panel-chief">
                                <span class="nxa-acc-label"><?php esc_html_e( 'Capo redattore AI', 'nexus-news-ai' ); ?></span>
                                <span class="nxa-acc-chevron" aria-hidden="true"></span>
                        </button>
                        <div class="nxa-acc-panel" id="nxa-acc-panel-chief" hidden>
                                <?php /* Sezione v1.1.4 "Capo redattore AI": 2 toggle (value="1", serializzati 0/1 dal blocco flag di admin.js) più 2 campi numerici. */ ?>
                                <table class="form-table" role="presentation">
                                        <tbody>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Capo redattore AI', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <label for="nxa-chief-enabled">
                                                                        <input type="checkbox" name="chief_enabled" id="nxa-chief-enabled" value="1" <?php checked( $chief_enabled, 1 ); ?> />
                                                                        <?php esc_html_e( 'Attiva il capo redattore AI', 'nexus-news-ai' ); ?>
                                                                </label>
                                                                <p class="description"><?php esc_html_e( "Il giornalista AI scrive l'articolo e il capo redattore AI lo legge: lo approva, lo modifica, richiede una revisione o lo rifiuta. Con gli strumenti di ricerca attivi il capo verifica anche le fonti online. L'umano resta sempre sovrano: può approvare o rifiutare dalla coda come oggi.", 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Pubblicazione automatica', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <label for="nxa-chief-auto-publish">
                                                                        <input type="checkbox" name="chief_auto_publish" id="nxa-chief-auto-publish" value="1" <?php checked( $chief_auto_publish, 1 ); ?> />
                                                                        <?php esc_html_e( 'Pubblicazione automatica dopo approvazione del capo redattore', 'nexus-news-ai' ); ?>
                                                                </label>
                                                                <p class="description"><?php esc_html_e( "Deselezionato = human-in-the-loop: l'articolo resta in bozza con il badge del capo redattore e l'approvazione resta a te.", 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-chief-max-rounds"><?php esc_html_e( 'Giri massimi di revisione (1-5)', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="number" name="chief_max_rounds" id="nxa-chief-max-rounds" value="<?php echo esc_attr( $chief_max_rounds ); ?>" min="1" max="5" step="1" class="small-text" />
                                                                <p class="description"><?php esc_html_e( "Quante volte il capo può richiedere una revisione al giornalista AI prima di lasciare l'articolo all'umano (predefinito 2).", 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-chief-id"><?php esc_html_e( 'Agente capo redattore (0 = automatico)', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="number" name="chief_id" id="nxa-chief-id" value="<?php echo esc_attr( $chief_id ); ?>" min="0" step="1" class="small-text" />
                                                                <p class="description"><?php esc_html_e( "ID dell'agente con ruolo capo redattore da usare sempre. 0 = automatico: viene scelto il primo capo redattore attivo.", 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                        </tbody>
                                </table>
                        </div>
                </section>

                <section class="nxa-acc" data-nxa-acc="tools">
                        <button type="button" class="nxa-acc-toggle" aria-expanded="false" aria-controls="nxa-acc-panel-tools">
                                <span class="nxa-acc-label"><?php esc_html_e( 'Strumenti di ricerca per gli agenti', 'nexus-news-ai' ); ?></span>
                                <span class="nxa-acc-chevron" aria-hidden="true"></span>
                        </button>
                        <div class="nxa-acc-panel" id="nxa-acc-panel-tools" hidden>
                                <?php /* Sezione v1.1.4 "Strumenti di ricerca per gli agenti": 2 toggle (value="1"), select provider di ricerca, chiave API, 4 numerici e textarea dei server MCP. */ ?>
                                <table class="form-table" role="presentation">
                                        <tbody>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Strumenti di ricerca', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <label for="nxa-tools-enabled">
                                                                        <input type="checkbox" name="tools_enabled" id="nxa-tools-enabled" value="1" <?php checked( $tools_enabled, 1 ); ?> />
                                                                        <?php esc_html_e( 'Attiva gli strumenti di ricerca per gli agenti', 'nexus-news-ai' ); ?>
                                                                </label>
                                                                <p class="description"><?php esc_html_e( 'Ricerca web, lettura pagine, scoperta feed RSS e client MCP: gli agenti li usano per raccogliere fonti durante la raccolta e il capo redattore per verificare le notizie.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-tools-search-provider"><?php esc_html_e( 'Provider di ricerca web', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <select name="tools_search_provider" id="nxa-tools-search-provider">
                                                                        <option value="tavily" <?php selected( $tools_search_provider, 'tavily' ); ?>><?php esc_html_e( 'Tavily', 'nexus-news-ai' ); ?></option>
                                                                        <option value="serper" <?php selected( $tools_search_provider, 'serper' ); ?>><?php esc_html_e( 'Serper', 'nexus-news-ai' ); ?></option>
                                                                        <option value="brave" <?php selected( $tools_search_provider, 'brave' ); ?>><?php esc_html_e( 'Brave', 'nexus-news-ai' ); ?></option>
                                                                        <option value="none" <?php selected( $tools_search_provider, 'none' ); ?>><?php esc_html_e( 'Nessuno', 'nexus-news-ai' ); ?></option>
                                                                </select>
                                                                <p class="description"><?php esc_html_e( 'Servizio usato per cercare notizie online. Nessuno disattiva la ricerca web (lettura pagine e client MCP restano disponibili).', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-tools-search-key"><?php esc_html_e( 'Chiave API del provider di ricerca', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="password" name="tools_search_key" id="nxa-tools-search-key" value="<?php echo esc_attr( $tools_search_key ); ?>" class="regular-text code" autocomplete="new-password" />
                                                                <p class="description"><?php esc_html_e( 'Chiave del servizio di ricerca scelto (Tavily, Serper o Brave). Salvata nel database del sito, mai mostrata in chiaro.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-tools-max-results"><?php esc_html_e( 'Risultati per ricerca (1-10)', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="number" name="tools_max_results" id="nxa-tools-max-results" value="<?php echo esc_attr( $tools_max_results ); ?>" min="1" max="10" step="1" class="small-text" />
                                                                <p class="description"><?php esc_html_e( 'Numero massimo di risultati restituiti da ogni ricerca web (predefinito 5).', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Lettura pagine web', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <label for="nxa-tools-read-page">
                                                                        <input type="checkbox" name="tools_read_page" id="nxa-tools-read-page" value="1" <?php checked( $tools_read_page, 1 ); ?> />
                                                                        <?php esc_html_e( 'Consenti lettura pagine web', 'nexus-news-ai' ); ?>
                                                                </label>
                                                                <p class="description"><?php esc_html_e( 'Gli agenti possono aprire le pagine trovate dalla ricerca (o indicate nei prompt) per leggerne il testo completo.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-tools-read-max-chars"><?php esc_html_e( 'Caratteri massimi per pagina (500-20000)', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="number" name="tools_read_max_chars" id="nxa-tools-read-max-chars" value="<?php echo esc_attr( $tools_read_max_chars ); ?>" min="500" max="20000" step="1" class="small-text" />
                                                                <p class="description"><?php esc_html_e( 'Testo massimo estratto da ogni pagina web letta da un agente (predefinito 6000).', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-tools-max-rounds"><?php esc_html_e( 'Giri di strumenti per risposta (0-6)', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="number" name="tools_max_rounds" id="nxa-tools-max-rounds" value="<?php echo esc_attr( $tools_max_rounds ); ?>" min="0" max="6" step="1" class="small-text" />
                                                                <p class="description"><?php esc_html_e( "Quante volte l'AI può usare uno strumento in fila prima della risposta finale. 0 = loop disattivato (predefinito 3).", 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-tools-timeout"><?php esc_html_e( 'Timeout richieste (secondi, 5-30)', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="number" name="tools_timeout" id="nxa-tools-timeout" value="<?php echo esc_attr( $tools_timeout ); ?>" min="5" max="30" step="1" class="small-text" />
                                                                <p class="description"><?php esc_html_e( 'Tempo massimo per ogni richiesta di ricerca o lettura di pagina (predefinito 15 secondi).', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-mcp-servers-text"><?php esc_html_e( 'Server MCP (uno per riga)', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <textarea name="mcp_servers_text" id="nxa-mcp-servers-text" rows="3" class="large-text code" placeholder="mioserver|https://esempio.it/mcp|tokenOpzionale"><?php echo esc_textarea( $mcp_servers_text ); ?></textarea>
                                                                <p class="description"><?php esc_html_e( 'Un server per riga nel formato nome|URL|tokenOpzionale (le righe che iniziano con # sono commenti). Solo server MCP compatibili con il trasporto Streamable HTTP (JSON-RPC 2.0 su HTTP): il token, se presente, viene inviato come Authorization: Bearer.', 'nexus-news-ai' ); ?></p>
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
                                                <?php /* Sub-sezione v1.1.2 "Multilingua — post tradotti nativi": righe informative dal driver di lingua + toggle enable_autotranslate_posts (serializzato dal blocco intelFlags di admin.js). */ ?>
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
  /* Stile locale della sub-sezione "Multilingua — post tradotti nativi"
     (v1.1.2): SOLO classi prefisso .nxa-ml- : nessun colore fisso, tutto via
     token del design system (tema chiaro di default, scuro via
     body.nexus-theme-dark). */
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
  /* WP10 (v1.1.10): wizard provider — SOLO classi locali .nxa-prov-*; nessun
     colore fisso: tutto via token del design system (tema chiaro di default,
     scuro via body.nexus-theme-dark). */
  .nxa-prov-dot {
    display: inline-block;
    width: 10px;
    height: 10px;
    margin-left: 6px;
    border-radius: 50%;
    vertical-align: middle;
  }
  .nxa-prov-dot--on {
    background: var(--nxa-ok-text);
    box-shadow: 0 0 0 3px var(--nxa-ok-bg);
  }
  .nxa-prov-dot--off {
    background: var(--nxa-border);
  }
  .nxa-prov-actions {
    margin: 6px 0 0;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
  }
  .nxa-prov-actions .button-link {
    text-decoration: none;
  }
  .nxa-prov-status {
    margin: 4px 0 0;
  }
  .nxa-prov-status--ok {
    color: var(--nxa-ok-text);
    font-weight: 600;
  }
  .nxa-prov-status--err {
    color: var(--nxa-err-text);
    font-weight: 600;
  }
  /* F2b (v1.1.10): provider personalizzati — lista righe + form add/edit.
     SOLO classi .nxa-custprov-*; nessun colore fisso: tutto via token del
     design system (tema chiaro di default, scuro via body.nexus-theme-dark). */
  .nxa-custprov-list {
    margin: 0 0 10px;
  }
  .nxa-custprov-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    padding: 8px 0;
    border-top: 1px solid var(--nxa-border);
  }
  .nxa-custprov-row:first-child {
    border-top: 0;
  }
  .nxa-custprov-label {
    font-weight: 600;
  }
  .nxa-custprov-url {
    max-width: 320px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--nxa-muted);
    font-family: Consolas, Monaco, monospace;
    font-size: 12px;
  }
  .nxa-custprov-badge {
    display: inline-block;
    padding: 1px 8px;
    border-radius: 999px;
    border: 1px solid var(--nxa-border);
    background: var(--nxa-surface-2);
    color: var(--nxa-text-body);
    font-size: 11px;
    font-weight: 600;
    line-height: 1.6;
    white-space: nowrap;
  }
  .nxa-custprov-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
  }
  .nxa-custprov-row .nxa-prov-status {
    flex-basis: 100%;
    margin: 0;
  }
  .nxa-custprov-confirm {
    display: inline-flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
    font-weight: 600;
    color: var(--nxa-err-text);
  }
  .nxa-custprov-form {
    border: 1px solid var(--nxa-border);
    border-radius: 6px;
    background: var(--nxa-surface-2);
    padding: 4px 14px 10px;
    margin: 0 0 10px;
    max-width: 640px;
  }
  .nxa-custprov-form p {
    margin: 10px 0 0;
  }
  .nxa-custprov-form label {
    display: block;
    font-weight: 600;
    margin-bottom: 2px;
  }
</style>
<script>
( function () {
        'use strict';

        var STORAGE_KEY = 'nxa-settings-acc-ai';
        var DEFAULT_OPEN = 'ai';

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
