<?php
/**
 * Vista admin: "Agenti giornalisti" — data-page="agents".
 *
 * SKELETON STATICO. La griglia live NON viene renderizzata qui: la riempie
 * assets/js/admin.js via REST (namespace nexus/v1, header X-WP-Nonce con
 * NexusAdminData.nonce). Questo file fornisce solo:
 *  - il guscio pagina con ID/classi/data-attributes FISSI (contratto sotto);
 *  - il placeholder .nxa-skeleton dentro la griglia (rimpiazzato dal primo
 *    render di admin.js);
 *  - un fallback <noscript> con card statiche in SOLA LETTURA generate da
 *    Nexus_DB::agents() (+ Nexus_Utils::time_ago_it per l'ultimo run).
 *
 * =====================================================================
 * CONTRATTO JS (admin.js 1.0.5, verificato sul sorgente) — PAGINA AGENTI
 * =====================================================================
 *
 * 1) HOOK CONSUMATI dal markup statico di questa vista:
 *      - .wrap.nexus-admin[data-page="agents"] → boot del pannello;
 *      - #nxa-agents-grid → container lista: admin.js lo svuota, vi inietta
 *        4 barre .nxa-skeleton (showSkeleton) e poi le card;
 *      - .nxa-agents-refresh (qsa: anche più bottoni) → ricarica GET /agents.
 *
 * 2) RENDER CARD (admin.js, una per agente di GET /agents) dentro
 *    #nxa-agents-grid, avvolta in un div.nxa-agents-grid interno:
 *      .nxa-card.nxa-agent-card[data-agent-id="{id}"]
 *        avatar, nome, chip categoria (.nxa-chip, colore inline), badge
 *        stato (.nxa-badge.nxa-badge--success|warning|error con .nxa-dot),
 *        description, personality tra virgolette, "Ultimo run: …",
 *        input modello AI .nxa-agent-model[data-agent-id] (placeholder
 *        "vuoto = modello globale") + bottone .nxa-agent-model-save[data-agent-id]
 *        (POST /agents/{id}/model {model}) e toggle .nxa-agent-toggle[data-agent-id][data-status]
 *        (POST /agents/{id}/status {status: active|paused}).
 *        Lista vuota o errore REST → admin.js inserisce una .nxa-empty.
 *
 * 3) FALLBACK NOSCRIPT (questa vista, sola lettura): usa le classi del
 *    design system — badge categoria .nxa-badge.nxa-agent-cat che eredita
 *    --nxa-cat da [data-category] sulla card (zero colori inline, tema
 *    light e dark gestiti dal CSS); badge stato .nxa-badge.is-active /
 *    .is-paused / .is-error; azioni .nxa-btn.is-sm disabilitate.
 *    Nessun input modello qui: la nota utente sta in p.nxa-agents-model-note.
 *
 * v1.0.6 — markup allineato al design system a tema chiaro: sottotitolo di
 * pagina, toolbar azioni (.nxa-toolbar + .nxa-btn), placeholder .nxa-skeleton,
 * empty state .nxa-empty con dashicon, badge categoria/stato via token
 * [data-category]. Nessuna modifica a id, data-* e selettori consumati da
 * admin.js.
 *
 * =====================================================================
 * v1.0.7 — EDITOR AGENTI (CONTRATTO-v1.0.7.md §2 T-02)
 * =====================================================================
 *
 * La toolbar aggiunge il bottone primario #nxa-agent-new e in fondo alla
 * vista il PHP serve la MODAL EDITOR statica #nxa-agent-modal (nessun JS
 * inline in questa vista: la gestisce interamente admin.js 1.0.7):
 *      - click #nxa-agent-new → openAgentEditor( null ) (nuovo agente);
 *      - openAgentEditor( agent ) popola #nxa-agent-id, #nxa-agent-name,
 *        #nxa-agent-avatar, #nxa-agent-category, #nxa-agent-status,
 *        #nxa-agent-model, #nxa-agent-description, #nxa-agent-personality
 *        e il titolo #nxa-agent-modal-title, poi rimuove [hidden] dal modal;
 *      - closeAgentEditor() rimette [hidden], ripristina il body overflow e
 *        svuota #nxa-agent-form-status;
 *      - submit #nxa-agent-form → id 0: POST /agents, id > 0: PUT
 *        /agents/{id}/update (esiti in #nxa-agent-form-status, aria-live);
 *      - click #nxa-agent-cancel, click [data-nxa-agent-close] e tasto ESC
 *        (con la modal aperta) → closeAgentEditor();
 *      - #nxa-agent-model è un COMBOBOX cercabile WP12 (admin.js
 *        nexusModelPicker: dropdown con ricerca su id/name, badge
 *        GRATIS/contesto/prezzo, max 60 risultati, frecce+Invio, Esc chiude):
 *        la lista segue il provider scelto in #nxa-agent-provider (reload al
 *        cambio, fix G5; GET /ai/models?provider=…, cache client 60s);
 *      - i bottoni "Modifica"/"Clona" sulle card (.nxa-agent-edit /
 *        .nxa-agent-clone, con data-agent-id) sono generati da admin.js
 *        dentro #nxa-agents-grid e NON sono nel markup di questa vista.
 * Tutti gli id, data-attributi e classi esistenti consumati da admin.js
 * restano intatti.
 *
 * =====================================================================
 * v1.1.0 — CATEGORIA NATIVA + AUTORE BOT (CONTRATTO-v1.1.0.md §2 T-02)
 * =====================================================================
 *
 * Il campo categoria #nxa-agent-category porta l'attributo
 * list="nxa-agent-category-list" ed è seguito subito dalla datalist VUOTA
 * #nxa-agent-category-list (admin.js T-03 la riempie via REST con
 * GET /nexus/v1/wp/categories quando la modal si apre; cache client 60s;
 * se la richiesta fallisce la datalist resta vuota e l'input resta libero).
 * La categoria salvata diventa una categoria reale della testata: l'articolo
 * pubblicato come post nativo eredita autore bot (utente con ruolo
 * nexus_ai_journalist) e sezione (term di categoria nativo).
 * I badge autore bot sulle card agente (riga "Autore WP attivo", classe
 * .nxa-agent-wp-author) sono generati da admin.js (T-03) dentro
 * #nxa-agents-grid e NON sono nel markup di questa vista.
 *
 * =====================================================================
 * v1.1.1 — GANCIO REDAZIONI (CONTRATTO-v1.1.1.md §2 T-01)
 * =====================================================================
 *
 * Ogni riga agente espone il gancio della colonna "Redazioni": un
 * elemento con class nxa-agent-redazioni e data-agent-id che admin.js
 * (T-03) riempie con le chips delle redazioni dell'agente (dalla risposta
 * GET nexus/v1/redazioni), oppure col testo "—" se l'agente non è
 * assegnato a nessuna redazione.
 *
 * Questa vista NON ha una tabella con thead (la griglia live è a card
 * generate da admin.js): il gancio statico sta nella card del fallback
 * noscript, subito dopo il badge di categoria; per le card generate da
 * JS admin.js aggiunge lo stesso elemento (stessa classe e stesso data
 * attributo) nel template delle righe nuove, come previsto dal
 * contratto. Il placeholder statico è "—" escaped.
 *
 * =====================================================================
 * v1.1.4 — CREAZIONE RAPIDA + RUOLO/PROVIDER/CAPO REDATTORE (T-06)
 * =====================================================================
 *
 * La toolbar è seguita dalla barra "Creazione rapida" (.nxa-preset-bar):
 * bottoni .nxa-preset-btn con data-preset (tech|sport|economia|politica|
 * scienza|cultura|salute|capo) che admin.js trasforma in prefill del form
 * #nxa-agent-form e apertura del modal nuovo agente (invio normale
 * POST /agents). Emoji dei preset: SOLO emoji già presenti nel progetto
 * (seed agenti in class-nexus-db.php e icone attività di admin.js); per il
 * capo redattore si usa 📰 perché il simbolo citato dal contratto non è
 * mai comparso nel progetto (nota in worklog).
 * Nel modal editor tre campi nuovi, gestiti interamente da admin.js:
 *  - #nxa-agent-role (name="role"): Giornalista / Capo redattore;
 *  - #nxa-agent-provider (name="ai_provider"): prima option "" = "Provider
 *    globale (impostazioni)", lista riempita da admin.js con GET
 *    /nexus/v1/tools/catalog (T-05; a catalogo assente resta solo il
 *    provider globale);
 *  - #nxa-agent-chief-field (hidden): visibile solo con ruolo capo
 *    redattore, contiene #nxa-agent-chief-scope (name="chief_scope",
 *    global|category|term) e, per l'ambito "term", #nxa-agent-chief-term
 *    (name="chief_term_id", opzioni dai termini redazioni via cache pagina
 *    GET /redazioni già usata dalla colonna "Redazioni").
 * La serializzazione dei 4 campi nuovi nei POST/PUT /agents resta in
 * admin.js. Tutti gli id, data-attributi e classi esistenti restano intatti.
 *
 * =====================================================================
 * v1.1.5 — BOTTONI AVVIA (CONTRATTO-v1.1.5.md §5 T-04)
 * =====================================================================
 *
 * Ogni card agente espone il bottone "Avvia" (classe nxa-btn-run,
 * data-run-agent="{id}", label "▶ Avvia"). Le card live sono generate da
 * admin.js (agentCardHtml: il bottone è PRIMO nella riga azioni) e il
 * fallback noscript qui sotto porta la STESSA azione DISABLED (sola
 * lettura: senza JavaScript nessun handler può partire). Handler unico
 * delegato su document con guardia di binding (sezione "AVVII MIRATI
 * (v1.1.5)" di admin.js): POST nexus/v1/pipeline/run {agent_id: id},
 * bottone .is-busy + spinner (setBtnLoading) durante l'attesa, esito in
 * toast riepilogo (shape §4.1 con results[], fallback alle chiavi BC).
 *
 * =====================================================================
 * v1.1.10 — STRUMENTI PER AGENTE (WP4 PARTE 1, Task C1)
 * =====================================================================
 *
 * Il modal editor aggiunge la sezione "Strumenti personali (opzionali)",
 * gestita interamente da admin.js (prefill al load + serializzazione nei
 * POST/PUT /agents):
 *  - #nxa-agent-search-provider (name="search_provider"): '' = "Ricerca web
 *    globale (impostazioni)", tavily, serper, brave — "none" NON è una scelta
 *    per-agente; risoluzione a cascata AGENTE → GLOBALE in Nexus_Tools
 *    (search_web_raw/tool_catalog_for_prompt);
 *  - #nxa-agent-search-key (name="search_key", type password): la chiave viene
 *    INVIATA in chiaro col form ma l'attuale whitelist di
 *    agent_fields_from_request (rest.php, NON questa vista) la scarta senza
 *    errori: il salvataggio della chiave per-agente richiede il patch REST
 *    della wave 4 (che deciderà hashing via Nexus_Tools::hash_search_key()
 *    oppure persistenza in chiaro — vedi worklog C1). Il campo NON viene mai
 *    precompilato (segreto);
 *  - #nxa-agent-mcp-servers (name="mcp_servers_text"): righe
 *    "nome|url|tokenOpzionale"; i server dell'agente si sommano a quelli
 *    globali e in caso di conflitto sul nome vince l'agente
 *    (Nexus_Tools::mcp_servers( $agent_id )).
 * serialize_agent (rest.php) NON espone ancora search_provider/mcp_servers_text:
 * admin.js applica un fallback vuoto client-side (la wave 4 aggiungerà i campi
 * al serializzatore per il prefill reale).
 *
 * =====================================================================
 * v1.1.11 — ZERO AGENTI DEFAULT + WIZARD ONBOARDING (Task R3)
 * =====================================================================
 *
 * Dalla v1.1.11 il plugin NON inserisce più agenti all'attivazione (il seed
 * class-nexus-db.php::seed_agents() è un no-op BC): una installazione nuova
 * nasce con la redazione vuota. Per questo:
 *  - il sottotitolo pagina e l'empty state NON citano più "i 7 agenti":
 *    la redazione è e resta quella che l'utente crea;
 *  - in fondo alla vista è incluso il partial onboarding-wizard.php
 *    (modal #nxa-onboarding, gestito da admin.js — sezione v1.1.11):
 *    quando la griglia agenti è vuota admin.js apre il wizard al posto
 *    dell'empty state (una sola volta per vista) e a ogni click su
 *    .nxa-onboarding-open o con hash #nxa-onboarding (la card CTA della
 *    dashboard punta a admin.php?page=nexus-agents#nxa-onboarding);
 *  - la barra "Creazione rapida" è INVARIATA (preset 1-click v1.1.4):
 *    resta il modo manuale/progressivo di creare agenti.
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

/*
 * Dati statici server-side per il fallback noscript (sola lettura): elenco
 * agenti da tabella e helper presentazionale. Se Nexus_Utils non fosse
 * ancora caricato il fallback viene semplicemente omesso, senza errori.
 */
$nxa_has_utils = class_exists( 'Nexus_Utils' ) && method_exists( 'Nexus_Utils', 'time_ago_it' );
$nxa_agents    = class_exists( 'Nexus_DB' ) ? Nexus_DB::agents() : array();

/* Categorie distinte degli agenti in tabella (per la legenda badge). */
$nxa_categories = array();
foreach ( $nxa_agents as $nxa_agent ) {
        if ( ! empty( $nxa_agent->category ) ) {
                $nxa_categories[ $nxa_agent->category ] = true;
        }
}
$nxa_categories = array_keys( $nxa_categories );

/* Nexus_Utils::status_label_it() copre gli stati ARTICOLO: per gli STATI
 * AGENTE si usa questa mappa (coerente con STATUS_META di admin.js). */
$nxa_agent_status_labels = array(
        'active' => __( 'Attivo', 'nexus-news-ai' ),
        'paused' => __( 'In pausa', 'nexus-news-ai' ),
        'error'  => __( 'Errore', 'nexus-news-ai' ),
);
?>
<div class="wrap nexus-admin" data-page="agents">

        <h1><?php esc_html_e( 'Agenti giornalisti', 'nexus-news-ai' ); ?></h1>

        <p class="nxa-page-subtitle"><?php esc_html_e( 'Gli agenti raccolgono notizie reali, le valutano e le riscrivono con AI. Crea quanti agenti vuoi: da 1 a una redazione completa, con preset pronti.', 'nexus-news-ai' ); ?></p>

        <div class="nxa-toolbar">
                <button type="button" class="nxa-btn nxa-agents-refresh"><?php esc_html_e( 'Aggiorna', 'nexus-news-ai' ); ?></button>
                <button type="button" id="nxa-agent-new" class="nxa-btn nxa-btn-primary"><?php esc_html_e( 'Nuovo agente', 'nexus-news-ai' ); ?></button>
        </div>

        <!-- Creazione rapida (v1.1.4): preset agenti in un click. I bottoni sono
             consumati da admin.js (T-06): click → prefill del form agente
             e apertura del modal editor, poi invio POST /agents standard.
             Emoji solo da quelle già presenti nel progetto (vedi docblock testa). -->
        <div class="nxa-preset-bar" role="group" aria-label="Creazione rapida agenti">
                <span class="nxa-preset-bar-label"><?php echo esc_html( 'Creazione rapida:' ); ?></span>
                <button type="button" class="nxa-btn nxa-preset-btn" data-preset="tech"><?php echo esc_html( '🤖 Giornalista tech' ); ?></button>
                <button type="button" class="nxa-btn nxa-preset-btn" data-preset="sport"><?php echo esc_html( '⚽ Giornalista sport' ); ?></button>
                <button type="button" class="nxa-btn nxa-preset-btn" data-preset="economia"><?php echo esc_html( '📈 Giornalista economia' ); ?></button>
                <button type="button" class="nxa-btn nxa-preset-btn" data-preset="politica"><?php echo esc_html( '🏛️ Giornalista politica' ); ?></button>
                <button type="button" class="nxa-btn nxa-preset-btn" data-preset="scienza"><?php echo esc_html( '🔬 Giornalista scienza' ); ?></button>
                <button type="button" class="nxa-btn nxa-preset-btn" data-preset="cultura"><?php echo esc_html( '🎭 Giornalista cultura' ); ?></button>
                <button type="button" class="nxa-btn nxa-preset-btn" data-preset="salute"><?php echo esc_html( '🩺 Giornalista salute' ); ?></button>
                <button type="button" class="nxa-btn nxa-preset-btn" data-preset="capo"><?php echo esc_html( '📰 Capo redattore' ); ?></button>
        </div>

        <!-- Modello AI per agente (v1.0.3): il campo input.nxa-agent-model[data-agent-id]
             e il bottone di salvataggio sono generati da admin.js dentro ogni card
             (POST /agents/{id}/model). Qui SOLO la nota utente. -->
        <p class="nxa-agents-model-note">
                <?php esc_html_e( 'Modello AI per agente: il modello verrà usato per la riscrittura di QUESTO agente; vuoto = modello globale impostato nelle opzioni.', 'nexus-news-ai' ); ?>
        </p>

        <?php if ( ! empty( $nxa_categories ) ) : ?>
                <p class="nxa-categories-legend">
                        <?php foreach ( $nxa_categories as $nxa_category ) : ?>
                                <span class="nxa-badge nxa-agent-cat" data-category="<?php echo esc_attr( $nxa_category ); ?>"><?php echo esc_html( $nxa_category ); ?></span>
                        <?php endforeach; ?>
                </p>
        <?php endif; ?>

        <!-- GRIGLIA AGENTI: riempita da admin.js (GET /agents). Contratto nel docblock di testa.
             Le barre .nxa-skeleton coprono il tempo prima del primo render REST. -->
        <div id="nxa-agents-grid" class="nxa-agents-grid" aria-live="polite" aria-busy="true">
                <p class="screen-reader-text"><?php esc_html_e( 'Caricamento agenti…', 'nexus-news-ai' ); ?></p>
                <div class="nxa-skeleton"></div>
                <div class="nxa-skeleton"></div>
                <div class="nxa-skeleton"></div>
                <div class="nxa-skeleton"></div>
        </div>

        <?php if ( empty( $nxa_agents ) ) : ?>
                <!-- v1.1.11: niente più "riattiva il plugin per i 7 agenti": con la
                     redazione vuota la via è il wizard (che admin.js apre in
                     automatico su 0 agenti) o i preset. Fallback no-JS onesto. -->
                <div class="nxa-empty">
                        <span class="dashicons dashicons-groups" aria-hidden="true"></span>
                        <p class="nxa-empty__title"><?php esc_html_e( 'Nessun agente in tabella', 'nexus-news-ai' ); ?></p>
                        <p><?php esc_html_e( 'Nessun agente: usa il wizard o i preset per creare il tuo primo giornalista.', 'nexus-news-ai' ); ?></p>
                </div>
        <?php elseif ( $nxa_has_utils ) : ?>
                <?php
                /*
                 * FALLBACK NOSCRIPT (sola lettura): card statiche generate da
                 * Nexus_DB::agents(); struttura e classi rispecchiano il contratto
                 * card di admin.js documentato nel docblock di testa.
                 */
                ?>
                <noscript>
                        <div class="nxa-empty">
                                <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                                <p class="nxa-empty__title"><?php esc_html_e( 'JavaScript è disattivato', 'nexus-news-ai' ); ?></p>
                                <p><?php esc_html_e( 'Elenco statico in sola lettura.', 'nexus-news-ai' ); ?></p>
                        </div>
                        <div class="nxa-agents-grid">
                                <?php foreach ( $nxa_agents as $nxa_agent ) : ?>
                                        <?php $nxa_status = (string) $nxa_agent->status; ?>
                                        <article class="nxa-agent-card" data-agent-id="<?php echo esc_attr( $nxa_agent->id ); ?>" data-status="<?php echo esc_attr( $nxa_status ); ?>" data-category="<?php echo esc_attr( $nxa_agent->category ); ?>">
                                                <div class="nxa-agent-avatar" aria-hidden="true"><?php echo esc_html( $nxa_agent->avatar ); ?></div>
                                                <h3 class="nxa-agent-name"><?php echo esc_html( $nxa_agent->name ); ?></h3>
                                                <?php if ( '' !== (string) $nxa_agent->description ) : ?>
                                                        <p class="nxa-agent-description"><?php echo esc_html( $nxa_agent->description ); ?></p>
                                                <?php endif; ?>
                                                <?php if ( '' !== (string) $nxa_agent->personality ) : ?>
                                                        <p class="nxa-agent-personality nxa-agent-persona">“<?php echo esc_html( $nxa_agent->personality ); ?>”</p>
                                                <?php endif; ?>
                                                <div class="nxa-agent-meta">
                                                        <span class="nxa-badge nxa-agent-cat"><?php echo esc_html( $nxa_agent->category ); ?></span>
                                                        <span class="nxa-agent-redazioni-label"><?php esc_html_e( 'Redazioni:', 'nexus-news-ai' ); ?></span>
                                                        <span class="nxa-agent-redazioni" data-agent-id="<?php echo esc_attr( $nxa_agent->id ); ?>"><?php echo esc_html( '—' ); ?></span>
                                                        <span class="nxa-badge nxa-agent-status is-<?php echo esc_attr( $nxa_status ); ?>">
                                                                <?php
                                                                echo esc_html(
                                                                        isset( $nxa_agent_status_labels[ $nxa_status ] )
                                                                                ? $nxa_agent_status_labels[ $nxa_status ]
                                                                                : $nxa_status
                                                                );
                                                                ?>
                                                        </span>
                                                        <span class="nxa-agent-last-run">
                                                                <?php
                                                                printf(
                                                                        /* translators: %s: tempo trascorso dall'ultimo run. */
                                                                        esc_html__( 'Ultimo run: %s', 'nexus-news-ai' ),
                                                                        esc_html( ! empty( $nxa_agent->last_run ) ? Nexus_Utils::time_ago_it( $nxa_agent->last_run ) : __( 'mai eseguito', 'nexus-news-ai' ) )
                                                                );
                                                                ?>
                                                        </span>
                                                </div>
                                                <div class="nxa-agent-actions">
                                                        <!-- v1.1.5 (T-04): "Avvia" disabilitato qui (fallback noscript in
                                                             sola lettura); attivo sulle card generate da admin.js
                                                             (agentCardHtml) con handler delegato POST pipeline/run. -->
                                                        <button type="button" class="nxa-btn is-sm nxa-btn-run" data-run-agent="<?php echo esc_attr( $nxa_agent->id ); ?>" disabled>▶ <?php esc_html_e( 'Avvia', 'nexus-news-ai' ); ?></button>
                                                        <button type="button" class="nxa-btn is-sm nxa-agent-toggle" data-agent-id="<?php echo esc_attr( $nxa_agent->id ); ?>" data-next-status="<?php echo esc_attr( 'active' === $nxa_status ? 'paused' : 'active' ); ?>" disabled>
                                                                <?php echo 'active' === $nxa_status ? esc_html__( 'Metti in pausa', 'nexus-news-ai' ) : esc_html__( 'Riattiva', 'nexus-news-ai' ); ?>
                                                        </button>
                                                        <a class="nxa-btn is-sm nxa-agent-articles-link" data-agent-id="<?php echo esc_attr( $nxa_agent->id ); ?>" href="#">
                                                                <?php esc_html_e( 'Vedi articoli dell’agente', 'nexus-news-ai' ); ?>
                                                        </a>
                                                </div>
                                        </article>
                                <?php endforeach; ?>
                        </div>
                </noscript>
        <?php endif; ?>

        <!-- EDITOR AGENTE (v1.0.7): modal statica servita dal PHP, gestita da admin.js
             (openAgentEditor/closeAgentEditor, submit #nxa-agent-form, click su
             #nxa-agent-new / #nxa-agent-cancel / [data-nxa-agent-close]; il campo
             modello #nxa-agent-model è un combobox cercabile WP12 che carica da
             solo la lista del provider scelto). -->
        <div id="nxa-agent-modal" class="nxa-modal" hidden role="dialog" aria-modal="true" aria-labelledby="nxa-agent-modal-title">
                <div class="nxa-modal-panel" role="document">
                        <button type="button" class="nxa-modal-close" data-nxa-agent-close aria-label="<?php esc_attr_e( 'Chiudi', 'nexus-news-ai' ); ?>">&times;</button>
                        <h2 id="nxa-agent-modal-title"><?php esc_html_e( 'Nuovo agente', 'nexus-news-ai' ); ?></h2>
                        <form id="nxa-agent-form" class="nxa-agent-form">
                                <input type="hidden" name="agent_id" id="nxa-agent-id" value="0" />
                                <div class="nxa-agent-form-grid">
                                        <p class="nxa-agent-field"><label for="nxa-agent-name"><?php esc_html_e( 'Nome', 'nexus-news-ai' ); ?></label>
                                                <input type="text" id="nxa-agent-name" name="name" maxlength="100" required /></p>
                                        <p class="nxa-agent-field"><label for="nxa-agent-avatar"><?php esc_html_e( 'Avatar (emoji)', 'nexus-news-ai' ); ?></label>
                                                <input type="text" id="nxa-agent-avatar" name="avatar" maxlength="20" placeholder="🤖" /></p>
                                        <p class="nxa-agent-field"><label for="nxa-agent-category"><?php esc_html_e( 'Categoria', 'nexus-news-ai' ); ?></label>
                                                <input type="text" id="nxa-agent-category" name="category" maxlength="100" list="nxa-agent-category-list" required />
                                                <datalist id="nxa-agent-category-list"></datalist>
                                                <span class="nxa-agent-field-hint"><?php esc_html_e( 'Unica per agente (es. tecnologia, sport).', 'nexus-news-ai' ); ?></span>
                                                <span class="nxa-agent-field-hint"><?php esc_html_e( 'La categoria diventa una categoria reale della testata e l’articolo erediterà autore e sezione.', 'nexus-news-ai' ); ?></span></p>
                                        <p class="nxa-agent-field"><label for="nxa-agent-status"><?php esc_html_e( 'Stato', 'nexus-news-ai' ); ?></label>
                                                <select id="nxa-agent-status" name="status"><option value="active"><?php esc_html_e( 'Attivo', 'nexus-news-ai' ); ?></option><option value="paused"><?php esc_html_e( 'In pausa', 'nexus-news-ai' ); ?></option></select></p>
                                        <p class="nxa-agent-field"><label for="nxa-agent-role"><?php echo esc_html( 'Ruolo' ); ?></label>
                                                <select id="nxa-agent-role" name="role">
                                                        <option value="giornalista"><?php echo esc_html( 'Giornalista' ); ?></option>
                                                        <option value="capo_redattore"><?php echo esc_html( 'Capo redattore' ); ?></option>
                                                </select></p>
                                        <p class="nxa-agent-field"><label for="nxa-agent-provider"><?php echo esc_html( 'Provider AI (vuoto = globale)' ); ?></label>
                                                <select id="nxa-agent-provider" name="ai_provider">
                                                        <option value=""><?php echo esc_html( 'Provider globale (impostazioni)' ); ?></option>
                                                </select>
                                                <span class="nxa-agent-field-hint"><?php echo esc_html( 'La lista dei provider arriva dalle impostazioni; senza chiave in Impostazioni → Provider AI il provider non è utilizzabile.' ); ?></span></p>
                                        <p class="nxa-agent-field nxa-agent-field--full nxa-agent-chief-field" id="nxa-agent-chief-field" hidden>
                                                <label for="nxa-agent-chief-scope"><?php echo esc_html( 'Ambito capo redattore' ); ?></label>
                                                <select id="nxa-agent-chief-scope" name="chief_scope">
                                                        <option value="global"><?php echo esc_html( 'Intero giornale' ); ?></option>
                                                        <option value="category"><?php echo esc_html( 'Categoria dell’agente' ); ?></option>
                                                        <option value="term"><?php echo esc_html( 'Redazione specifica' ); ?></option>
                                                </select>
                                                <select id="nxa-agent-chief-term" name="chief_term_id" hidden>
                                                        <option value="0"><?php echo esc_html( 'Caricamento redazioni…' ); ?></option>
                                                </select>
                                                <span class="nxa-agent-field-hint"><?php echo esc_html( 'Visibile solo per il ruolo Capo redattore: dice al capo quali articoli verifica (intero giornale, solo la sua categoria o una redazione specifica).' ); ?></span></p>
                                        <p class="nxa-agent-field nxa-agent-field--full"><label for="nxa-agent-model"><?php esc_html_e( 'Modello AI (vuoto = globale)', 'nexus-news-ai' ); ?></label>
                                                <?php /* WP12: combobox cercabile — admin.js (nexusModelPicker) avvolge l'input in span.nxa-mp e apre il dropdown al focus: la lista segue #nxa-agent-provider (reload al cambio, fix G5). La vecchia <datalist> #nxa-agent-model-datalist è rimossa. */ ?>
                                                <input type="text" id="nxa-agent-model" name="ai_model" maxlength="100" class="code" autocomplete="off" placeholder="vuoto = modello globale" />
                                                <span class="nxa-agent-field-hint"><?php esc_html_e( 'Digita per cercare tra i modelli del provider scelto: clic o Invio per scegliere, Esc per chiudere.', 'nexus-news-ai' ); ?></span></p>
                                        <p class="nxa-agent-field nxa-agent-field--full"><label for="nxa-agent-description"><?php esc_html_e( 'Descrizione', 'nexus-news-ai' ); ?></label>
                                                <textarea id="nxa-agent-description" name="description" rows="3"></textarea></p>
                                        <p class="nxa-agent-field nxa-agent-field--full"><label for="nxa-agent-personality"><?php esc_html_e( 'Personalità / tono di voce', 'nexus-news-ai' ); ?></label>
                                                <textarea id="nxa-agent-personality" name="personality" rows="3"></textarea></p>

                                        <!-- v1.1.10 WP4 (Task C1): strumenti personali per agente.
                                             Serializzati da admin.js nei POST/PUT /agents; search_key
                                             è inviata in chiaro ma l'attuale whitelist di
                                             agent_fields_from_request (rest.php) la scarta senza
                                             errori — persistenza chiave = patch REST wave 4. -->
                                        <h3 class="nxa-agent-tools-title nxa-agent-field--full"><?php esc_html_e( 'Strumenti personali (opzionali)', 'nexus-news-ai' ); ?></h3>
                                        <p class="nxa-agent-field nxa-agent-field--full"><label for="nxa-agent-search-provider"><?php esc_html_e( 'Provider ricerca web (vuoto = globale)', 'nexus-news-ai' ); ?></label>
                                                <select id="nxa-agent-search-provider" name="search_provider">
                                                        <option value=""><?php esc_html_e( 'Ricerca web globale (impostazioni)', 'nexus-news-ai' ); ?></option>
                                                        <option value="tavily"><?php esc_html_e( 'Tavily', 'nexus-news-ai' ); ?></option>
                                                        <option value="serper"><?php esc_html_e( 'Serper (Google Search)', 'nexus-news-ai' ); ?></option>
                                                        <option value="brave"><?php esc_html_e( 'Brave Search', 'nexus-news-ai' ); ?></option>
                                                </select>
                                                <span class="nxa-agent-field-hint"><?php esc_html_e( 'Se impostato, questo agente usa il proprio motore di ricerca invece di quello globale; se la chiave di ricerca globale non è configurata la ricerca dell’agente restituisce un errore chiaro.', 'nexus-news-ai' ); ?></span></p>
                                        <p class="nxa-agent-field nxa-agent-field--full"><label for="nxa-agent-search-key"><?php esc_html_e( 'Chiave ricerca web dell’agente', 'nexus-news-ai' ); ?></label>
                                                <input type="password" id="nxa-agent-search-key" name="search_key" maxlength="64" autocomplete="new-password" />
                                                <span class="nxa-agent-field-hint"><?php esc_html_e( 'Il salvataggio della chiave per questo agente arriva con un prossimo aggiornamento: attualmente viene usata la chiave di ricerca globale (impostazioni → Strumenti).', 'nexus-news-ai' ); ?></span></p>
                                        <p class="nxa-agent-field nxa-agent-field--full"><label for="nxa-agent-mcp-servers"><?php esc_html_e( 'Server MCP personali (una riga per server)', 'nexus-news-ai' ); ?></label>
                                                <textarea id="nxa-agent-mcp-servers" name="mcp_servers_text" rows="3" class="code" placeholder="nome|https://server-url|token-opzionale"></textarea>
                                                <span class="nxa-agent-field-hint"><?php esc_html_e( 'I server MCP dell’agente si sommano a quelli globali (in caso di conflitto vince l’agente). Formato: nome|URL|token opzionale; le righe che iniziano con # sono commenti.', 'nexus-news-ai' ); ?></span></p>
                                </div>
                                <p class="nxa-agent-form-actions">
                                        <button type="submit" id="nxa-agent-save" class="nxa-btn nxa-btn-primary"><?php esc_html_e( 'Salva agente', 'nexus-news-ai' ); ?></button>
                                        <button type="button" id="nxa-agent-cancel" class="nxa-btn"><?php esc_html_e( 'Annulla', 'nexus-news-ai' ); ?></button>
                                        <span id="nxa-agent-form-status" aria-live="polite"></span>
                                </p>
                        </form>
                </div>
        </div>

        <?php
        /* v1.1.11 (R3): WIZARD ONBOARDING — modal statica #nxa-onboarding,
         * gestita da admin.js (sezione v1.1.11). Contratto nel docblock del
         * partial. Non cambia il markup/ganci esistenti di questa vista. */
        include NEXUS_PLUGIN_DIR . 'admin/views/onboarding-wizard.php';
        ?>

</div>
