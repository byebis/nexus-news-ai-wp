<?php
/**
 * Vista admin: "Agenti giornalisti" — data-page="agents".
 *
 * SKELETON STATICO. La lista live NON viene renderizzata qui: la riempie
 * assets/js/admin.js (altro agente) via REST (namespace nexus/v1, header
 * X-WP-Nonce con NexusAdminData.nonce). Questo file fornisce solo:
 *  - il guscio pagina con ID/classi/data-attributes FISSI (contratto sotto);
 *  - lo skeleton di caricamento dentro la griglia;
 *  - un fallback <noscript> con card statiche generate server-side da
 *    Nexus_DB::agents() + Nexus_Utils (dati statici consentiti, sola lettura).
 *
 * =====================================================================
 * CONTRATTO JS (admin.js) — PAGINA AGENTI
 * =====================================================================
 *
 * 1) LETTURA LISTA
 *    GET /agents
 *    Risposta attesa: array di oggetti agente:
 *      { id, name, avatar (emoji), category, description, personality,
 *        status: 'active'|'paused'|'error', last_run: 'Y-m-d H:i:s'|null }
 *
 * 2) RENDER — una card per agente dentro #nxa-agents-grid (selettori FISSI):
 *      article.nxa-agent-card[data-agent-id="{id}"][data-status="{status}"]
 *        > .nxa-agent-avatar        → avatar (emoji, testo semplice)
 *        > .nxa-agent-name          → name
 *        > .nxa-agent-category      → category; colore da Nexus_Utils::category_color(category)
 *                                     (JS: inline style color + border-color con l'hex restituito)
 *        > .nxa-agent-status        → badge stato, classe nxa-status-{active|paused|error};
 *                                     testo = mappa ANALOGA a Nexus_Utils::status_label_it
 *                                     (che copre gli stati ARTICOLO, non gli agenti):
 *                                     active = "Attivo", paused = "In pausa", error = "Errore"
 *        > .nxa-agent-description   → description
 *        > .nxa-agent-personality   → personality
 *        > .nxa-agent-last-run      → "Ultimo run: {X}" dove X = equivalente JS di
 *                                     Nexus_Utils::time_ago_it(last_run); se last_run è
 *                                     null/vuoto → "mai eseguito"
 *        > .nxa-agent-actions
 *            > button.nxa-agent-toggle[data-agent-id="{id}"][data-next-status]
 *                data-next-status = "paused" se status è "active", altrimenti "active";
 *                label = "Metti in pausa" (active) / "Riattiva" (paused|error);
 *                azione: POST /agents/{id}/status  body JSON { status: data-next-status }
 *                → al successo ricaricare la lista (GET /agents).
 *            > a.nxa-agent-articles-link[data-agent-id="{id}"]
 *                testo: "Vedi articoli dell'agente";
 *                href compilato da JS verso la pagina articoli/coda filtrata per agente,
 *                es. admin.php?page=nexus-queue&agent_id={id} (adattare allo slug menu reale).
 *
 * 3) BOTTONI / INTERAZIONI FISSE
 *    button.nxa-agents-refresh → ricarica la lista (GET /agents).
 *
 * 4) STATI DELLA GRIGLIA
 *    - Loading iniziale: #nxa-agents-grid contiene <p class="nxa-loading"> (spinner WP
 *      + testo "Caricamento agenti…", stessa convenzione di dashboard.php) e ha la
 *      classe .is-loading; JS deve svuotare il container e rimuovere .is-loading
 *      prima di iniettare le card.
 *    - Lista vuota: inserire .nxa-agents-empty con testo "Nessun agente trovato."
 *    - Errore REST: inserire .nxa-agents-error (o una .notice.notice-error WP) con
 *      testo "Impossibile caricare gli agenti."
 *
 * 5) MODELLO AI PER AGENTE (v1.0.3) — SOLO contratto, NESSUN markup qui
 *    La griglia è popolata da admin.js: questa view NON genera l'input del modello
 *    (né nel caricamento live, né nel fallback <noscript> in sola lettura).
 *    Contratto per ogni card generata da JS:
 *      > input text .nxa-agent-model[data-agent-id="{id}"]
 *          value = ai_model dell'agente (stringa, '' se vuoto — serialize_agent
 *          della REST include già il campo ai_model);
 *          placeholder = "vuoto = modello globale";
 *          descrizione utente = "Il modello verrà usato per la riscrittura di
 *          QUESTO agente; vuoto = modello globale impostato nelle opzioni."
 *          (nota visibile server-side: p.nxa-agents-model-note in questa pagina).
 *      > bottone salvataggio accanto al campo (nome/classe a cura di admin.js)
 *          azione: POST /agents/{id}/model con body JSON { model: valore }
 *          (vuoto = torna al modello globale); al successo aggiornare lo stato
 *          locale e ricaricare la lista (GET /agents).
 *   admin.js (altro agente) è proprietario del rendering e del salvataggio:
 *    eventuali variazioni di contratto vanno coordinate con quel file.
 *
 * Il markup <noscript> in fondo al file è un esempio vivente del contratto card sopra.
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

/*
 * Dati statici server-side (consentiti dal task): elenco agenti da tabella
 * e helper presentazionali. Se Nexus_Utils non fosse ancora caricato il
 * fallback statico viene semplicemente omesso, senza errori fatali.
 */
$nxa_has_utils = class_exists( 'Nexus_Utils' )
        && method_exists( 'Nexus_Utils', 'category_color' )
        && method_exists( 'Nexus_Utils', 'time_ago_it' );
$nxa_agents     = class_exists( 'Nexus_DB' ) ? Nexus_DB::agents() : array();
$nxa_categories = array();
foreach ( $nxa_agents as $nxa_agent ) {
        if ( ! empty( $nxa_agent->category ) ) {
                $nxa_categories[ $nxa_agent->category ] = true;
        }
}
$nxa_categories = array_keys( $nxa_categories );

/* Nexus_Utils::status_label_it() copre gli stati ARTICOLO: per gli STATI AGENTE
 * si usa questa mappa analoga (contratto identico lato JS). */
$nxa_agent_status_labels = array(
        'active' => __( 'Attivo', 'nexus-news-ai' ),
        'paused' => __( 'In pausa', 'nexus-news-ai' ),
        'error'  => __( 'Errore', 'nexus-news-ai' ),
);
?>
<div class="wrap nexus-admin" data-page="agents">

        <div class="nxa-page-header">
                <h1 class="nxa-page-title"><?php esc_html_e( 'Agenti giornalisti', 'nexus-news-ai' ); ?></h1>
                <button type="button" class="button button-secondary nxa-agents-refresh">
                        <?php esc_html_e( 'Aggiorna', 'nexus-news-ai' ); ?>
                </button>
        </div>

        <p class="nxa-legend">
                <?php esc_html_e( 'Gli agenti raccolgono notizie RSS reali della loro categoria, le valutano e le riscrivono con AI.', 'nexus-news-ai' ); ?>
        </p>

        <!-- Modello AI per agente (v1.0.3): contratto JS nel docblock di testa.
             Il campo input.nxa-agent-model[data-agent-id] e il bottone di salvataggio
             sono generati da admin.js dentro ogni card (POST /agents/{id}/model).
             Qui SOLO la nota utente: nessun markup duplicato. -->
        <p class="nxa-legend nxa-agents-model-note">
                <?php esc_html_e( 'Modello AI per agente: il modello verrà usato per la riscrittura di QUESTO agente; vuoto = modello globale impostato nelle opzioni.', 'nexus-news-ai' ); ?>
        </p>

        <?php if ( $nxa_has_utils && ! empty( $nxa_categories ) ) : ?>
                <p class="nxa-categories-legend">
                        <?php foreach ( $nxa_categories as $nxa_category ) : ?>
                                <?php $nxa_cat_color = Nexus_Utils::category_color( $nxa_category ); ?>
                                <span class="nxa-chip" style="border-color: <?php echo esc_attr( $nxa_cat_color ); ?>; color: <?php echo esc_attr( $nxa_cat_color ); ?>;">
                                        <?php echo esc_html( $nxa_category ); ?>
                                </span>
                        <?php endforeach; ?>
                </p>
        <?php endif; ?>

        <!-- GRIGLIA AGENTI: riempita da admin.js (GET /agents). Contratto selettori nel docblock di testa. -->
        <div id="nxa-agents-grid" class="nxa-agents-grid is-loading">
                <p class="nxa-loading">
                        <span class="spinner is-active"></span>
                        <?php esc_html_e( 'Caricamento agenti…', 'nexus-news-ai' ); ?>
                </p>
        </div>

        <?php if ( empty( $nxa_agents ) ) : ?>
                <div class="notice notice-warning">
                        <p><?php esc_html_e( 'Nessun agente in tabella: riattiva il plugin per rigenerare il set iniziale dei 7 agenti.', 'nexus-news-ai' ); ?></p>
                </div>
        <?php elseif ( $nxa_has_utils ) : ?>
                <?php
                /*
                 * FALLBACK NOSCRIT (sola lettura): stesse classi del contratto JS,
                 * renderizzate server-side da Nexus_DB::agents() + Nexus_Utils.
                 */
                ?>
                <noscript>
                        <div class="notice notice-info">
                                <p><?php esc_html_e( 'JavaScript è disattivato: elenco statico in sola lettura.', 'nexus-news-ai' ); ?></p>
                        </div>
                        <div class="nxa-agents-grid">
                                <?php foreach ( $nxa_agents as $nxa_agent ) : ?>
                                        <?php
                                        $nxa_color  = Nexus_Utils::category_color( $nxa_agent->category );
                                        $nxa_status = (string) $nxa_agent->status;
                                        ?>
                                        <article class="nxa-agent-card" data-agent-id="<?php echo esc_attr( $nxa_agent->id ); ?>" data-status="<?php echo esc_attr( $nxa_status ); ?>">
                                                <div class="nxa-agent-avatar"><?php echo esc_html( $nxa_agent->avatar ); ?></div>
                                                <h2 class="nxa-agent-name"><?php echo esc_html( $nxa_agent->name ); ?></h2>
                                                <span class="nxa-agent-category" style="border-color: <?php echo esc_attr( $nxa_color ); ?>; color: <?php echo esc_attr( $nxa_color ); ?>;">
                                                        <?php echo esc_html( $nxa_agent->category ); ?>
                                                </span>
                                                <span class="nxa-agent-status nxa-status-<?php echo esc_attr( $nxa_status ); ?>">
                                                        <?php
                                                        echo esc_html(
                                                                isset( $nxa_agent_status_labels[ $nxa_status ] )
                                                                        ? $nxa_agent_status_labels[ $nxa_status ]
                                                                        : $nxa_status
                                                        );
                                                        ?>
                                                </span>
                                                <p class="nxa-agent-description"><?php echo esc_html( $nxa_agent->description ); ?></p>
                                                <p class="nxa-agent-personality"><?php echo esc_html( $nxa_agent->personality ); ?></p>
                                                <p class="nxa-agent-last-run">
                                                        <?php
                                                        printf(
                                                                /* translators: %s: tempo trascorso dall'ultimo run. */
                                                                esc_html__( 'Ultimo run: %s', 'nexus-news-ai' ),
                                                                esc_html( ! empty( $nxa_agent->last_run ) ? Nexus_Utils::time_ago_it( $nxa_agent->last_run ) : __( 'mai eseguito', 'nexus-news-ai' ) )
                                                        );
                                                        ?>
                                                </p>
                                                <div class="nxa-agent-actions">
                                                        <button type="button" class="button nxa-agent-toggle" data-agent-id="<?php echo esc_attr( $nxa_agent->id ); ?>" data-next-status="<?php echo esc_attr( 'active' === $nxa_status ? 'paused' : 'active' ); ?>" disabled>
                                                                <?php echo 'active' === $nxa_status ? esc_html__( 'Metti in pausa', 'nexus-news-ai' ) : esc_html__( 'Riattiva', 'nexus-news-ai' ); ?>
                                                        </button>
                                                        <a class="nxa-agent-articles-link" data-agent-id="<?php echo esc_attr( $nxa_agent->id ); ?>" href="#">
                                                                <?php esc_html_e( 'Vedi articoli dell’agente', 'nexus-news-ai' ); ?>
                                                        </a>
                                                </div>
                                        </article>
                                <?php endforeach; ?>
                        </div>
                </noscript>
        <?php endif; ?>

</div>
