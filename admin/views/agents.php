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
 *      - #nxa-agent-model-datalist viene riempito da admin.js dalla cache
 *        GET /ai/models (nessun fetch automatico dalla modal);
 *      - i bottoni "Modifica"/"Clona" sulle card (.nxa-agent-edit /
 *        .nxa-agent-clone, con data-agent-id) sono generati da admin.js
 *        dentro #nxa-agents-grid e NON sono nel markup di questa vista.
 * Tutti gli id, data-attributi e classi esistenti consumati da admin.js
 * restano intatti.
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

        <p class="nxa-page-subtitle"><?php esc_html_e( 'Gli agenti raccolgono notizie RSS reali della loro categoria, le valutano e le riscrivono con AI. Puoi creare, modificare e clonare gli agenti: i 7 agenti predefiniti restano modificabili.', 'nexus-news-ai' ); ?></p>

        <div class="nxa-toolbar">
                <button type="button" class="nxa-btn nxa-agents-refresh"><?php esc_html_e( 'Aggiorna', 'nexus-news-ai' ); ?></button>
                <button type="button" id="nxa-agent-new" class="nxa-btn nxa-btn-primary"><?php esc_html_e( 'Nuovo agente', 'nexus-news-ai' ); ?></button>
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
                <div class="nxa-empty">
                        <span class="dashicons dashicons-groups" aria-hidden="true"></span>
                        <p class="nxa-empty__title"><?php esc_html_e( 'Nessun agente in tabella', 'nexus-news-ai' ); ?></p>
                        <p><?php esc_html_e( 'Riattiva il plugin per rigenerare il set iniziale dei 7 agenti.', 'nexus-news-ai' ); ?></p>
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
             #nxa-agent-new / #nxa-agent-cancel / [data-nxa-agent-close]; datalist dei
             modelli riempito da admin.js dalla cache GET /ai/models). -->
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
                                                <input type="text" id="nxa-agent-category" name="category" maxlength="100" required />
                                                <span class="nxa-agent-field-hint"><?php esc_html_e( 'Unica per agente (es. tecnologia, sport).', 'nexus-news-ai' ); ?></span></p>
                                        <p class="nxa-agent-field"><label for="nxa-agent-status"><?php esc_html_e( 'Stato', 'nexus-news-ai' ); ?></label>
                                                <select id="nxa-agent-status" name="status"><option value="active"><?php esc_html_e( 'Attivo', 'nexus-news-ai' ); ?></option><option value="paused"><?php esc_html_e( 'In pausa', 'nexus-news-ai' ); ?></option></select></p>
                                        <p class="nxa-agent-field nxa-agent-field--full"><label for="nxa-agent-model"><?php esc_html_e( 'Modello AI (vuoto = globale)', 'nexus-news-ai' ); ?></label>
                                                <input type="text" id="nxa-agent-model" name="ai_model" maxlength="100" list="nxa-agent-model-datalist" class="code" />
                                                <datalist id="nxa-agent-model-datalist"></datalist></p>
                                        <p class="nxa-agent-field nxa-agent-field--full"><label for="nxa-agent-description"><?php esc_html_e( 'Descrizione', 'nexus-news-ai' ); ?></label>
                                                <textarea id="nxa-agent-description" name="description" rows="3"></textarea></p>
                                        <p class="nxa-agent-field nxa-agent-field--full"><label for="nxa-agent-personality"><?php esc_html_e( 'Personalità / tono di voce', 'nexus-news-ai' ); ?></label>
                                                <textarea id="nxa-agent-personality" name="personality" rows="3"></textarea></p>
                                </div>
                                <p class="nxa-agent-form-actions">
                                        <button type="submit" id="nxa-agent-save" class="nxa-btn nxa-btn-primary"><?php esc_html_e( 'Salva agente', 'nexus-news-ai' ); ?></button>
                                        <button type="button" id="nxa-agent-cancel" class="nxa-btn"><?php esc_html_e( 'Annulla', 'nexus-news-ai' ); ?></button>
                                        <span id="nxa-agent-form-status" aria-live="polite"></span>
                                </p>
                        </form>
                </div>
        </div>

</div>
