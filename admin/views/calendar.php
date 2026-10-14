<?php
/**
 * Vista admin: "Calendario editoriale" — pagina nexus-calendar (v1.1.14, P4).
 *
 * PAGINA SERVER-SIDE (differenza documentata rispetto alle altre viste):
 * qui il markup è renderizzato dal PHP (include da Nexus_Calendar::render_page)
 * perché i dati sono query MySQL dirette su post programmati e pipeline, non
 * route REST di admin.js. Le azioni (salvataggio slot, rimozione slot,
 * riallineamento manuale) sono FORM POST tradizionali verso admin-post.php
 * con nonce + capability gestiti in class-nexus-calendar.php — nessun
 * endpoint REST nuovo, nessun tocco ad assets/js/admin.js.
 *
 * PER QUESTO MOTIVO il wrapper NON porta data-page: admin.js non ha un
 * loader per questa pagina e non deve boottare nessun pannello REST.
 * admin.js resta comunque caricato (pagina nexus-*): è il ponte
 * window.NexusAdminUi.inlineConfirmOpen usato dallo script inline in fondo
 * (pattern v1.1.13.1 Q2, stesso approccio di jobs.php) per le conferme
 * inline — ZERO window.confirm/alert/prompt. Senza ponte, le azioni con
 * conferma restano disattivate (warn in console, mai dialoghi nativi).
 *
 * STATI ONESTI:
 *  - i pallini sui giorni sono SOLO post con post_status 'future' (WP core);
 *  - gli articoli pipeline in attesa NON hanno un giorno: vivono nel pannello
 *    "In attesa" (stati reali wp_nexus_articles: draft/pending_approval/
 *    approved) con CTA verso Coda Approvazioni e Coda Lavori;
 *  - l'istogramma ore usa HOUR(post_date) dei post pubblicati e metriche
 *    nexus_metrics SOLO a granularità giornaliera (giorno di pubblicazione):
 *    nessuna metrica oraria inventata;
 *  - stati vuoti espliciti con CTA, mai sezioni finte.
 *
 * PALLINO COLORE AGENTE/REDAZIONE: l'elemento porta data-category con la
 * categoria dell'agente autore; il foglio nexus-admin.css mappa
 * [data-category] sui token --nxa-cat* (stesso meccanismo dei chip del
 * design system). Categorie non mappate → tono neutro di default.
 *
 * MOBILE: sotto 782px la griglia 7 colonne diventa una lista di giorni
 * (admin/css/nexus-calendar.css, media query unica).
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Variabili fornite da Nexus_Calendar::render_page() (documentate lì):
 *
 * @var DateTimeImmutable $tz              Timezone del sito (non usato direttamente qui).
 * @var array             $window          start|end|prev|next|label del mese visualizzato.
 * @var array             $items           'Y-m-d' => lista articoli programmati del giorno.
 * @var bool              $truncated       True se qualche giorno ha superato il limite per riga.
 * @var array             $agents          agent_id => riga agente (name, category).
 * @var array             $pending         counts|list|jobs del pannello "in attesa".
 * @var array             $hist            rows|max|total|top|has_metrics|clicks|views|ok.
 * @var array             $moves           entries|summary|last_run|last_check|last_moved.
 * @var int|false         $next_run        Timestamp del prossimo giro cron (false se assente).
 * @var string            $notice_key      'saved'|'run'|'err'|'' (esito redirect).
 * @var int               $notice_moved    Articoli spostati (azione run).
 * @var int               $notice_checked  Articoli controllati (azione run).
 * @var int               $notice_bad      Righe slot scartate (salvataggio).
 * @var int               $notice_dis      1 se il giro manuale è stato skippato (slot spenti).
 */

global $wp_locale;

$cal_start     = $window['start']; // Primo giorno del mese, 00:00, timezone sito.
$cal_today     = current_time( 'Y-m-d' );
$cal_sow       = (int) get_option( 'start_of_week', 1 ); // 0 = domenica, 1 = lunedì.
$cal_offset    = ( ( (int) $cal_start->format( 'w' ) ) - $cal_sow + 7 ) % 7;
$cal_days      = (int) $cal_start->format( 't' );
$cal_week      = array();
$cal_cfg       = Nexus_Calendar::slots_config();
$cal_url_base  = admin_url( 'admin.php?page=nexus-calendar' );

// Intestazioni dei giorni della settimana (abbreviate), a partire da start_of_week.
for ( $cal_i = 0; $cal_i < 7; $cal_i++ ) {
        $cal_dow     = ( $cal_sow + $cal_i ) % 7;
        $cal_week[]  = isset( $wp_locale->weekday[ $cal_dow ] ) ? $wp_locale->weekday_abbrev[ $wp_locale->weekday[ $cal_dow ] ] : (string) $cal_dow;
}
?>
<div class="wrap nexus-admin nxa-cal">

        <h1><?php esc_html_e( 'Calendario editoriale', 'nexus-news-ai' ); ?></h1>

        <p class="nxa-page-subtitle">
                <?php esc_html_e( 'Articoli programmati giorno per giorno, slot orari di pubblicazione e suggerimento orari basato sulle tue pubblicazioni passate.', 'nexus-news-ai' ); ?>
        </p>

        <?php if ( 'saved' === $notice_key ) : ?>
                <div class="notice notice-success is-dismissible">
                        <p>
                                <?php esc_html_e( 'Slot salvati.', 'nexus-news-ai' ); ?>
                                <?php if ( $notice_bad > 0 ) : ?>
                                        <?php
                                        printf(
                                                /* translators: %d: numero di righe slot scartate. */
                                                esc_html( _n( '%d riga scartata (orario non valido: inizio prima della fine?).', '%d righe scartate (orario non valido: inizio prima della fine?).', $notice_bad, 'nexus-news-ai' ) ),
                                                (int) $notice_bad
                                        );
                                        ?>
                                <?php endif; ?>
                        </p>
                </div>
        <?php elseif ( 'run' === $notice_key ) : ?>
                <div class="notice notice-success is-dismissible">
                        <p>
                                <?php if ( $notice_dis > 0 ) : ?>
                                        <?php esc_html_e( 'Slot disattivati: nessun riallineamento eseguito. Attiva almeno uno slot e riprova.', 'nexus-news-ai' ); ?>
                                <?php else : ?>
                                        <?php
                                        printf(
                                                /* translators: 1: articoli controllati, 2: articoli spostati. */
                                                esc_html__( 'Riallineamento slot eseguito: %1$d articoli controllati, %2$d spostati.', 'nexus-news-ai' ),
                                                (int) $notice_checked,
                                                (int) $notice_moved
                                        );
                                        ?>
                                <?php endif; ?>
                        </p>
                </div>
        <?php elseif ( 'err' === $notice_key ) : ?>
                <div class="notice notice-error is-dismissible">
                        <p><?php esc_html_e( 'Salvataggio non riuscito: agente non valido o richiesta incompleta.', 'nexus-news-ai' ); ?></p>
                </div>
        <?php endif; ?>

        <!-- ================= VISTA CALENDARIO (mese) ================= -->
        <div class="nxa-cal-head">
                <div class="nxa-cal-nav">
                        <a class="button" href="<?php echo esc_url( $cal_url_base . '&amp;cal_month=' . rawurlencode( $window['prev'] ) ); ?>">&larr; <?php esc_html_e( 'Mese precedente', 'nexus-news-ai' ); ?></a>
                        <span class="nxa-cal-month"><?php echo esc_html( $window['label'] ); ?></span>
                        <a class="button" href="<?php echo esc_url( $cal_url_base . '&amp;cal_month=' . rawurlencode( $window['next'] ) ); ?>"><?php esc_html_e( 'Mese successivo', 'nexus-news-ai' ); ?> &rarr;</a>
                </div>

                <?php if ( ! empty( $agents ) ) : ?>
                        <div class="nxa-cal-legend">
                                <span class="nxa-cal-legend-title"><?php esc_html_e( 'Agenti:', 'nexus-news-ai' ); ?></span>
                                <?php foreach ( $agents as $cal_agent ) : ?>
                                        <span class="nxa-cal-legend-item" data-category="<?php echo esc_attr( (string) $cal_agent->category ); ?>">
                                                <span class="nxa-cal-dot" aria-hidden="true"></span>
                                                <?php echo esc_html( (string) $cal_agent->name ); ?>
                                        </span>
                                <?php endforeach; ?>
                        </div>
                <?php endif; ?>
        </div>

        <?php $cal_month_has_items = ! empty( $items ); ?>
        <?php if ( ! $cal_month_has_items ) : ?>

                <div class="nxa-empty">
                        <span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
                        <p class="nxa-empty__title"><?php esc_html_e( 'Nessun articolo programmato in questo mese', 'nexus-news-ai' ); ?></p>
                        <p>
                                <?php esc_html_e( 'Gli articoli compaiono qui quando il flusso di pubblicazione li programma (stato future). Nel frattempo puoi controllare la coda di approvazione e la coda lavori.', 'nexus-news-ai' ); ?>
                        </p>
                        <p class="nxa-btn-row">
                                <a class="nxa-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=nexus-queue' ) ); ?>"><?php esc_html_e( 'Vai alla Coda Approvazioni', 'nexus-news-ai' ); ?></a>
                                <a class="nxa-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=nexus-jobs' ) ); ?>"><?php esc_html_e( 'Vai alla Coda Lavori', 'nexus-news-ai' ); ?></a>
                        </p>
                </div>

        <?php else : ?>

                <div class="nxa-cal-grid" role="grid" aria-label="<?php esc_attr_e( 'Calendario mensile degli articoli programmati', 'nexus-news-ai' ); ?>">
                        <?php foreach ( $cal_week as $cal_label ) : ?>
                                <div class="nxa-cal-weekday" role="columnheader"><?php echo esc_html( $cal_label ); ?></div>
                        <?php endforeach; ?>

                        <?php for ( $cal_i = 0; $cal_i < $cal_offset; $cal_i++ ) : ?>
                                <div class="nxa-cal-day nxa-cal-day--pad" aria-hidden="true"></div>
                        <?php endfor; ?>

                        <?php for ( $cal_d = 1; $cal_d <= $cal_days; $cal_d++ ) : ?>
                                <?php
                                $cal_day_dt  = $cal_start->modify( '+' . ( $cal_d - 1 ) . ' days' );
                                $cal_day_key = $cal_day_dt->format( 'Y-m-d' );
                                $cal_day     = isset( $items[ $cal_day_key ] ) ? $items[ $cal_day_key ] : array();
                                $cal_is_today = ( $cal_day_key === $cal_today );
                                ?>
                                <div class="nxa-cal-day<?php echo $cal_is_today ? ' nxa-cal-day--today' : ''; ?>" role="gridcell">
                                        <span class="nxa-cal-daynum"><?php echo esc_html( (string) $cal_d ); ?></span>

                                        <?php if ( empty( $cal_day ) ) : ?>
                                                <span class="nxa-cal-noday"><?php esc_html_e( 'Nessun articolo', 'nexus-news-ai' ); ?></span>
                                        <?php else : ?>
                                                <ul class="nxa-cal-items">
                                                        <?php foreach ( $cal_day as $cal_item ) : ?>
                                                                <li class="nxa-cal-item" data-category="<?php echo esc_attr( strtolower( (string) $cal_item['category'] ) ); ?>">
                                                                        <span class="nxa-cal-dot" aria-hidden="true"></span>
                                                                        <span class="nxa-cal-item-time"><?php echo esc_html( (string) $cal_item['time'] ); ?></span>
                                                                        <?php $cal_edit = get_edit_post_link( $cal_item['id'], 'raw' ); ?>
                                                                        <?php if ( $cal_edit ) : ?>
                                                                                <a class="nxa-cal-item-title" href="<?php echo esc_url( $cal_edit ); ?>"
                                                                                        title="<?php echo esc_attr( '' !== $cal_item['agent'] ? $cal_item['agent'] : __( 'Agente non collegato', 'nexus-news-ai' ) ); ?>"><?php echo esc_html( (string) $cal_item['title'] ); ?></a>
                                                                        <?php else : ?>
                                                                                <span class="nxa-cal-item-title"><?php echo esc_html( (string) $cal_item['title'] ); ?></span>
                                                                        <?php endif; ?>
                                                                        <?php if ( $cal_item['is_legacy'] ) : ?>
                                                                                <span class="nxa-cal-legacy"><?php esc_html_e( 'legacy', 'nexus-news-ai' ); ?></span>
                                                                        <?php endif; ?>
                                                                </li>
                                                        <?php endforeach; ?>
                                                </ul>
                                        <?php endif; ?>
                                </div>
                        <?php endfor; ?>
                </div>

                <?php if ( $truncated ) : ?>
                        <p class="nxa-cal-note"><?php esc_html_e( 'Alcuni giorni mostrano al massimo 30 articoli: l\'elenco è troncato per leggibilità.', 'nexus-news-ai' ); ?></p>
                <?php endif; ?>

        <?php endif; ?>

        <!-- ================= IN ATTESA (non ancora programmati) ================= -->
        <?php
        $cal_wait_total = (int) $pending['counts']['draft'] + (int) $pending['counts']['pending_approval'] + (int) $pending['counts']['approved'];
        ?>
        <h2 class="nxa-cal-h2"><?php esc_html_e( 'In attesa (non ancora programmati)', 'nexus-news-ai' ); ?></h2>

        <?php if ( ! $pending['ok'] ) : ?>

                <p class="nxa-cal-note"><?php esc_html_e( 'Stato pipeline non disponibile (modulo database non caricato).', 'nexus-news-ai' ); ?></p>

        <?php elseif ( 0 === $cal_wait_total && 0 === $pending['jobs'] ) : ?>

                <div class="nxa-empty">
                        <span class="dashicons dashicons-yes" aria-hidden="true"></span>
                        <p class="nxa-empty__title"><?php esc_html_e( 'Nessun articolo in attesa', 'nexus-news-ai' ); ?></p>
                        <p><?php esc_html_e( 'Non ci sono bozze, articoli da approvare o lavori in coda in questo momento.', 'nexus-news-ai' ); ?></p>
                </div>

        <?php else : ?>

                <div class="nxa-cal-waiting">
                        <div class="nxa-cal-wait-badges">
                                <span class="nxa-badge nxa-badge--warning">
                                        <?php
                                        printf(
                                                /* translators: %d: numero di articoli in attesa di approvazione. */
                                                esc_html( _n( '%d in attesa di approvazione', '%d in attesa di approvazione', (int) $pending['counts']['pending_approval'], 'nexus-news-ai' ) ),
                                                (int) $pending['counts']['pending_approval']
                                        );
                                        ?>
                                </span>
                                <span class="nxa-badge nxa-badge--neutral">
                                        <?php
                                        printf(
                                                /* translators: %d: numero di bozze in lavorazione. */
                                                esc_html( _n( '%d bozza in lavorazione', '%d bozze in lavorazione', (int) $pending['counts']['draft'], 'nexus-news-ai' ) ),
                                                (int) $pending['counts']['draft']
                                        );
                                        ?>
                                </span>
                                <span class="nxa-badge nxa-badge--neutral">
                                        <?php
                                        printf(
                                                /* translators: %d: numero di articoli approvati in attesa di pubblicazione. */
                                                esc_html( _n( '%d approvato da pubblicare', '%d approvati da pubblicare', (int) $pending['counts']['approved'], 'nexus-news-ai' ) ),
                                                (int) $pending['counts']['approved']
                                        );
                                        ?>
                                </span>
                                <span class="nxa-badge nxa-badge--neutral">
                                        <?php
                                        printf(
                                                /* translators: %d: numero di lavori in coda. */
                                                esc_html( _n( '%d lavoro in coda', '%d lavori in coda', (int) $pending['jobs'], 'nexus-news-ai' ) ),
                                                (int) $pending['jobs']
                                        );
                                        ?>
                                </span>
                        </div>

                        <?php if ( ! empty( $pending['list'] ) ) : ?>
                                <ul class="nxa-cal-wait-list">
                                        <?php foreach ( $pending['list'] as $cal_wait ) : ?>
                                                <?php
                                                $cal_wait_agent  = isset( $agents[ (int) $cal_wait->agent_id ] ) ? (string) $agents[ (int) $cal_wait->agent_id ]->name : '';
                                                $cal_wait_ago    = class_exists( 'Nexus_Utils' ) && method_exists( 'Nexus_Utils', 'time_ago_it' ) ? Nexus_Utils::time_ago_it( (string) $cal_wait->created_at ) : '';
                                                ?>
                                                <li class="nxa-cal-wait-item" data-category="<?php echo esc_attr( isset( $agents[ (int) $cal_wait->agent_id ] ) ? strtolower( (string) $agents[ (int) $cal_wait->agent_id ]->category ) : '' ); ?>">
                                                        <span class="nxa-cal-dot" aria-hidden="true"></span>
                                                        <span class="nxa-cal-wait-title"><?php echo esc_html( (string) $cal_wait->title ); ?></span>
                                                        <?php if ( '' !== $cal_wait_agent ) : ?>
                                                                <span class="nxa-cal-wait-agent"><?php echo esc_html( $cal_wait_agent ); ?></span>
                                                        <?php endif; ?>
                                                        <?php if ( '' !== $cal_wait_ago ) : ?>
                                                                <span class="nxa-cal-wait-ago"><?php echo esc_html( $cal_wait_ago ); ?></span>
                                                        <?php endif; ?>
                                                </li>
                                        <?php endforeach; ?>
                                </ul>
                        <?php endif; ?>

                        <p class="nxa-btn-row">
                                <a class="nxa-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=nexus-queue' ) ); ?>"><?php esc_html_e( 'Apri la Coda Approvazioni', 'nexus-news-ai' ); ?></a>
                                <a class="nxa-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=nexus-jobs' ) ); ?>"><?php esc_html_e( 'Apri la Coda Lavori', 'nexus-news-ai' ); ?></a>
                        </p>
                </div>

        <?php endif; ?>

        <!-- ================= SLOT ORARI ================= -->
        <h2 class="nxa-cal-h2"><?php esc_html_e( 'Slot orari di pubblicazione', 'nexus-news-ai' ); ?></h2>

        <p class="nxa-cal-note">
                <?php
                printf(
                        /* translators: %d: intervallo in minuti del cron di riallineamento. */
                        esc_html__( 'Gli articoli nuovi nascono programmati dal flusso esistente: il calendario li riallinea agli slot entro %d minuti. Gli articoli già dentro uno slot non vengono toccati.', 'nexus-news-ai' ),
                        (int) ( Nexus_Calendar::INTERVAL / 60 )
                );
                ?>
                <?php
                if ( false !== $next_run && $next_run > 0 ) {
                        printf(
                                /* translators: %s: data e ora del prossimo giro automatico. */
                                esc_html__( 'Prossimo giro automatico: %s.', 'nexus-news-ai' ),
                                esc_html( wp_date( 'd/m H:i', (int) $next_run ) )
                        );
                } else {
                        esc_html_e( 'Giro automatico non schedulato: verrà attivato al prossimo caricamento del plugin.', 'nexus-news-ai' );
                }
                ?>
                <?php
                printf(
                        /* translators: %d: orizzonte massimo in giorni oltre il quale gli articoli non vengono spostati. */
                        esc_html__( ' Gli articoli programmati a più di %d giorni da oggi non vengono spostati.', 'nexus-news-ai' ),
                        (int) Nexus_Calendar::HORIZON_DAYS
                );
                ?>
        </p>

        <!-- Slot GLOBALI -->
        <form class="nxa-cal-slotbox" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr( Nexus_Calendar::ACTION_SLOTS ); ?>" />
                <input type="hidden" name="nxa_cal_scope" value="global" />
                <?php wp_nonce_field( Nexus_Calendar::NONCE_SLOTS, 'nxa_cal_nonce' ); ?>

                <h3 class="nxa-cal-h3"><?php esc_html_e( 'Slot globali', 'nexus-news-ai' ); ?></h3>

                <label class="nxa-cal-toggle">
                        <input type="checkbox" name="nxa_cal_g_enabled" value="1" <?php checked( 1, $cal_cfg['enabled'] ); ?> />
                        <?php esc_html_e( 'Slot globali attivi (si applicano a tutti gli articoli senza slot propri)', 'nexus-news-ai' ); ?>
                </label>

                <?php if ( empty( $cal_cfg['global'] ) ) : ?>
                        <p class="nxa-cal-noslots"><?php esc_html_e( 'Nessuno slot globale configurato: finché non ne aggiungi almeno uno, nessun articolo viene spostato.', 'nexus-news-ai' ); ?></p>
                <?php endif; ?>

                <ul class="nxa-cal-slots">
                        <?php foreach ( $cal_cfg['global'] as $cal_si => $cal_slot ) : ?>
                                <li class="nxa-cal-slot">
                                        <label class="screen-reader-text" for="nxa-cal-g-from-<?php echo esc_attr( (string) $cal_si ); ?>"><?php esc_html_e( 'Ora inizio slot', 'nexus-news-ai' ); ?></label>
                                        <input id="nxa-cal-g-from-<?php echo esc_attr( (string) $cal_si ); ?>" type="time" name="nxa_cal_g_from[]" value="<?php echo esc_attr( (string) $cal_slot['from'] ); ?>" />
                                        <span class="nxa-cal-slot-sep">&ndash;</span>
                                        <label class="screen-reader-text" for="nxa-cal-g-to-<?php echo esc_attr( (string) $cal_si ); ?>"><?php esc_html_e( 'Ora fine slot', 'nexus-news-ai' ); ?></label>
                                        <input id="nxa-cal-g-to-<?php echo esc_attr( (string) $cal_si ); ?>" type="time" name="nxa_cal_g_to[]" value="<?php echo esc_attr( (string) $cal_slot['to'] ); ?>" />
                                        <button type="submit" class="button-link nxa-cal-remove" name="nxa_cal_g_remove" value="<?php echo esc_attr( (string) $cal_si ); ?>"
                                                data-nxa-cal-confirm="<?php echo esc_attr( sprintf( /* translators: 1: ora inizio, 2: ora fine. */ __( 'Rimuovere lo slot %1$s–%2$s?', 'nexus-news-ai' ), $cal_slot['from'], $cal_slot['to'] ) ); ?>"
                                                data-nxa-cal-yes="<?php esc_attr_e( 'Sì, rimuovi', 'nexus-news-ai' ); ?>"><?php esc_html_e( 'Rimuovi', 'nexus-news-ai' ); ?></button>
                                </li>
                        <?php endforeach; ?>
                        <li class="nxa-cal-slot nxa-cal-slot--new">
                                <label class="screen-reader-text" for="nxa-cal-g-new-from"><?php esc_html_e( 'Ora inizio nuovo slot', 'nexus-news-ai' ); ?></label>
                                <input id="nxa-cal-g-new-from" type="time" name="nxa_cal_g_new_from" value="" />
                                <span class="nxa-cal-slot-sep">&ndash;</span>
                                <label class="screen-reader-text" for="nxa-cal-g-new-to"><?php esc_html_e( 'Ora fine nuovo slot', 'nexus-news-ai' ); ?></label>
                                <input id="nxa-cal-g-new-to" type="time" name="nxa_cal_g_new_to" value="" />
                                <span class="nxa-cal-slot-hint"><?php esc_html_e( 'aggiungi slot (es. 09:00–11:00)', 'nexus-news-ai' ); ?></span>
                        </li>
                </ul>

                <p class="nxa-btn-row">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Salva slot globali', 'nexus-news-ai' ); ?></button>
                </p>
        </form>

        <!-- Slot PER AGENTE -->
        <?php if ( empty( $agents ) ) : ?>
                <p class="nxa-cal-noslots"><?php esc_html_e( 'Nessun agente configurato: crea gli agenti dalla pagina Agenti per usare gli slot dedicati.', 'nexus-news-ai' ); ?></p>
        <?php else : ?>
                <h3 class="nxa-cal-h3"><?php esc_html_e( 'Slot per agente (override)', 'nexus-news-ai' ); ?></h3>
                <p class="nxa-cal-note"><?php esc_html_e( 'Quando un agente ha l\'override attivo con almeno uno slot, i SUOI articoli usano solo i suoi slot e ignorano quelli globali.', 'nexus-news-ai' ); ?></p>

                <?php foreach ( $agents as $cal_agent_id => $cal_agent ) : ?>
                        <?php
                        $cal_agent_cfg = isset( $cal_cfg['agents'][ (int) $cal_agent_id ] ) ? $cal_cfg['agents'][ (int) $cal_agent_id ] : array( 'enabled' => 0, 'slots' => array() );
                        ?>
                        <form class="nxa-cal-slotbox nxa-cal-slotbox--agent" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <input type="hidden" name="action" value="<?php echo esc_attr( Nexus_Calendar::ACTION_SLOTS ); ?>" />
                                <input type="hidden" name="nxa_cal_scope" value="agent" />
                                <input type="hidden" name="nxa_cal_agent_id" value="<?php echo esc_attr( (string) (int) $cal_agent_id ); ?>" />
                                <?php wp_nonce_field( Nexus_Calendar::NONCE_SLOTS, 'nxa_cal_nonce' ); ?>

                                <div class="nxa-cal-agent-head" data-category="<?php echo esc_attr( strtolower( (string) $cal_agent->category ) ); ?>">
                                        <span class="nxa-cal-dot" aria-hidden="true"></span>
                                        <strong><?php echo esc_html( (string) $cal_agent->name ); ?></strong>
                                        <label class="nxa-cal-toggle">
                                                <input type="checkbox" name="nxa_cal_a_enabled" value="1" <?php checked( 1, (int) $cal_agent_cfg['enabled'] ); ?> />
                                                <?php esc_html_e( 'usa slot propri', 'nexus-news-ai' ); ?>
                                        </label>
                                </div>

                                <?php if ( empty( $cal_agent_cfg['slots'] ) ) : ?>
                                        <p class="nxa-cal-noslots"><?php esc_html_e( 'Nessuno slot dedicato per questo agente.', 'nexus-news-ai' ); ?></p>
                                <?php endif; ?>

                                <ul class="nxa-cal-slots">
                                        <?php foreach ( $cal_agent_cfg['slots'] as $cal_si => $cal_slot ) : ?>
                                                <li class="nxa-cal-slot">
                                                        <label class="screen-reader-text" for="nxa-cal-a-<?php echo esc_attr( (string) $cal_agent_id ); ?>-from-<?php echo esc_attr( (string) $cal_si ); ?>"><?php esc_html_e( 'Ora inizio slot', 'nexus-news-ai' ); ?></label>
                                                        <input id="nxa-cal-a-<?php echo esc_attr( (string) $cal_agent_id ); ?>-from-<?php echo esc_attr( (string) $cal_si ); ?>" type="time" name="nxa_cal_a_from[]" value="<?php echo esc_attr( (string) $cal_slot['from'] ); ?>" />
                                                        <span class="nxa-cal-slot-sep">&ndash;</span>
                                                        <label class="screen-reader-text" for="nxa-cal-a-<?php echo esc_attr( (string) $cal_agent_id ); ?>-to-<?php echo esc_attr( (string) $cal_si ); ?>"><?php esc_html_e( 'Ora fine slot', 'nexus-news-ai' ); ?></label>
                                                        <input id="nxa-cal-a-<?php echo esc_attr( (string) $cal_agent_id ); ?>-to-<?php echo esc_attr( (string) $cal_si ); ?>" type="time" name="nxa_cal_a_to[]" value="<?php echo esc_attr( (string) $cal_slot['to'] ); ?>" />
                                                        <button type="submit" class="button-link nxa-cal-remove" name="nxa_cal_a_remove" value="<?php echo esc_attr( (string) $cal_si ); ?>"
                                                                data-nxa-cal-confirm="<?php echo esc_attr( sprintf( /* translators: 1: ora inizio, 2: ora fine. */ __( 'Rimuovere lo slot %1$s–%2$s?', 'nexus-news-ai' ), $cal_slot['from'], $cal_slot['to'] ) ); ?>"
                                                                data-nxa-cal-yes="<?php esc_attr_e( 'Sì, rimuovi', 'nexus-news-ai' ); ?>"><?php esc_html_e( 'Rimuovi', 'nexus-news-ai' ); ?></button>
                                                </li>
                                        <?php endforeach; ?>
                                        <li class="nxa-cal-slot nxa-cal-slot--new">
                                                <label class="screen-reader-text" for="nxa-cal-a-<?php echo esc_attr( (string) $cal_agent_id ); ?>-new-from"><?php esc_html_e( 'Ora inizio nuovo slot', 'nexus-news-ai' ); ?></label>
                                                <input id="nxa-cal-a-<?php echo esc_attr( (string) $cal_agent_id ); ?>-new-from" type="time" name="nxa_cal_a_new_from" value="" />
                                                <span class="nxa-cal-slot-sep">&ndash;</span>
                                                <label class="screen-reader-text" for="nxa-cal-a-<?php echo esc_attr( (string) $cal_agent_id ); ?>-new-to"><?php esc_html_e( 'Ora fine nuovo slot', 'nexus-news-ai' ); ?></label>
                                                <input id="nxa-cal-a-<?php echo esc_attr( (string) $cal_agent_id ); ?>-new-to" type="time" name="nxa_cal_a_new_to" value="" />
                                                <span class="nxa-cal-slot-hint"><?php esc_html_e( 'aggiungi slot (es. 17:00–19:00)', 'nexus-news-ai' ); ?></span>
                                        </li>
                                </ul>

                                <p class="nxa-btn-row">
                                        <button type="submit" class="button"><?php esc_html_e( 'Salva slot agente', 'nexus-news-ai' ); ?></button>
                                </p>
                        </form>
                <?php endforeach; ?>
        <?php endif; ?>

        <!-- Riallineamento manuale + log spostamenti -->
        <div class="nxa-cal-runcard">
                <form class="nxa-cal-runform" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr( Nexus_Calendar::ACTION_RUN ); ?>" />
                        <?php wp_nonce_field( Nexus_Calendar::NONCE_RUN, 'nxa_cal_nonce' ); ?>
                        <button type="submit" class="button button-primary"
                                data-nxa-cal-confirm="<?php echo esc_attr__( 'Ricalcolare ora gli orari degli articoli programmati in base agli slot attivi?', 'nexus-news-ai' ); ?>"
                                data-nxa-cal-yes="<?php esc_attr_e( 'Sì, ricalcola', 'nexus-news-ai' ); ?>"><?php esc_html_e( 'Riallinea ora', 'nexus-news-ai' ); ?></button>
                </form>

                <div class="nxa-cal-moves">
                        <h4 class="nxa-cal-h4"><?php esc_html_e( 'Ultimi spostamenti', 'nexus-news-ai' ); ?></h4>

                        <?php if ( '' === $moves['summary'] && empty( $moves['entries'] ) ) : ?>
                                <p class="nxa-cal-noslots"><?php esc_html_e( 'Nessun giro registrato: il log appare al primo riallineamento (automatico o manuale).', 'nexus-news-ai' ); ?></p>
                        <?php else : ?>
                                <p class="nxa-cal-moves-summary">
                                        <?php
                                        printf(
                                                /* translators: 1: data/ora ultimo giro, 2: riepilogo del giro. */
                                                esc_html__( 'Ultimo giro %1$s — %2$s', 'nexus-news-ai' ),
                                                esc_html( '' !== $moves['last_run'] ? wp_date( 'd/m H:i', (int) strtotime( str_replace( ' ', 'T', (string) $moves['last_run'] ) . wp_timezone()->getOffset( new DateTime( (string) $moves['last_run'], wp_timezone() ) ) ) ) : '—' ),
                                                esc_html( (string) $moves['summary'] )
                                        );
                                        ?>
                                </p>

                                <?php if ( empty( $moves['entries'] ) ) : ?>
                                        <p class="nxa-cal-noslots"><?php esc_html_e( 'Nessun articolo spostato finora: gli articoli programmati cadono già dentro gli slot.', 'nexus-news-ai' ); ?></p>
                                <?php else : ?>
                                        <ul class="nxa-cal-moves-list">
                                                <?php foreach ( $moves['entries'] as $cal_move ) : ?>
                                                        <li class="nxa-cal-moves-item">
                                                                <span class="nxa-cal-moves-when"><?php echo esc_html( (string) $cal_move['time'] ); ?></span>
                                                                <span class="nxa-cal-moves-what">
                                                                        <?php
                                                                        printf(
                                                                                /* translators: 1: titolo articolo, 2: data/ora originale, 3: nuova data/ora. */
                                                                                esc_html__( '«%1$s» spostato da %2$s a %3$s', 'nexus-news-ai' ),
                                                                                esc_html( (string) $cal_move['title'] ),
                                                                                esc_html( (string) $cal_move['from'] ),
                                                                                esc_html( (string) $cal_move['to'] )
                                                                        );
                                                                        ?>
                                                                </span>
                                                        </li>
                                                <?php endforeach; ?>
                                        </ul>
                                <?php endif; ?>
                        <?php endif; ?>
                </div>
        </div>

        <!-- ================= SUGGERIMENTO ORARI (ONESTO) ================= -->
        <h2 class="nxa-cal-h2"><?php esc_html_e( 'Suggerimento orari (dalle tue pubblicazioni passate)', 'nexus-news-ai' ); ?></h2>

        <?php if ( ! $hist['ok'] || 0 === $hist['total'] ) : ?>

                <div class="nxa-empty">
                        <span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>
                        <p class="nxa-empty__title"><?php esc_html_e( 'Nessuna pubblicazione passata da analizzare', 'nexus-news-ai' ); ?></p>
                        <p><?php esc_html_e( 'L\'istogramma compare dopo le prime pubblicazioni: per ora non ci sono dati veri da mostrare e nessun orario viene suggerito.', 'nexus-news-ai' ); ?></p>
                </div>

        <?php else : ?>

                <?php
                $cal_top_labels = array();
                foreach ( $hist['top'] as $cal_h ) {
                        $cal_top_labels[] = sprintf( '%02d:00', (int) $cal_h );
                }
                ?>
                <p class="nxa-cal-note">
                        <?php
                        printf(
                                /* translators: 1: numero di giorni osservati, 2: elenco ore (es. 09:00, 18:00). */
                                esc_html__( 'Le tue pubblicazioni degli ultimi %1$d giorni si concentrano alle ore %2$s.', 'nexus-news-ai' ),
                                (int) Nexus_Calendar::HIST_DAYS,
                                esc_html( implode( ', ', $cal_top_labels ) )
                        );
                        ?>
                </p>

                <div class="nxa-cal-hist nxa-table-wrap">
                        <table class="nxa-cal-hist-table">
                                <caption class="screen-reader-text"><?php esc_html_e( 'Distribuzione delle pubblicazioni per ora del giorno', 'nexus-news-ai' ); ?></caption>
                                <thead>
                                        <tr>
                                                <th scope="col"><?php esc_html_e( 'Ora', 'nexus-news-ai' ); ?></th>
                                                <th scope="col"><?php esc_html_e( 'Articoli pubblicati', 'nexus-news-ai' ); ?></th>
                                                <th scope="col"><?php esc_html_e( 'Distribuzione', 'nexus-news-ai' ); ?></th>
                                                <th scope="col"><?php esc_html_e( 'Click giorno 0', 'nexus-news-ai' ); ?></th>
                                                <th scope="col"><?php esc_html_e( 'Views giorno 0', 'nexus-news-ai' ); ?></th>
                                        </tr>
                                </thead>
                                <tbody>
                                        <?php foreach ( $hist['rows'] as $cal_h => $cal_row ) : ?>
                                                <?php
                                                if ( 0 === $cal_row['n'] ) {
                                                        continue; // Solo ore con pubblicazioni reali: niente righe a zero.
                                                }
                                                $cal_pct  = $hist['max'] > 0 ? (int) round( ( $cal_row['n'] / $hist['max'] ) * 10 ) : 0; // step 10%
                                                if ( $cal_pct < 1 ) {
                                                        $cal_pct = 1;
                                                }
                                                ?>
                                                <tr>
                                                        <th scope="row"><?php echo esc_html( sprintf( '%02d:00', (int) $cal_h ) ); ?></th>
                                                        <td class="nxa-cal-hist-n"><?php echo esc_html( (string) $cal_row['n'] ); ?></td>
                                                        <td class="nxa-cal-hist-barcell">
                                                                <span class="nxa-cal-hist-bar nxa-cal-hist-bar--<?php echo esc_attr( (string) ( $cal_pct * 10 ) ); ?>"></span>
                                                        </td>
                                                        <td class="nxa-cal-hist-num"><?php echo esc_html( (string) $cal_row['clicks'] ); ?></td>
                                                        <td class="nxa-cal-hist-num"><?php echo esc_html( (string) $cal_row['views'] ); ?></td>
                                                </tr>
                                        <?php endforeach; ?>
                                </tbody>
                        </table>
                </div>

                <p class="nxa-cal-note">
                        <?php esc_html_e( 'Le colonne Click/Views "giorno 0" sommano le metriche nexus_metrics del giorno di pubblicazione (granularità giornaliera): nessuna metrica oraria esiste e nessuna viene stimata.', 'nexus-news-ai' ); ?>
                        <?php if ( ! $hist['has_metrics'] ) : ?>
                                <?php esc_html_e( 'Nessuna metrica GSC/GA4 disponibile per i giorni osservati: sincronizza i dati da Analytics per arricchire la tabella.', 'nexus-news-ai' ); ?>
                        <?php endif; ?>
                </p>

        <?php endif; ?>

        <script>
        (function () {
                'use strict';
                var ui = window.NexusAdminUi;
                if (!ui || typeof ui.inlineConfirmOpen !== 'function') {
                        if (window.console && window.console.warn) {
                                window.console.warn('[Nexus News AI] Ponte conferme inline (NexusAdminUi) non disponibile: le azioni con conferma restano disattivate.');
                        }
                        return;
                }
                document.addEventListener('click', function (event) {
                        var el = event.target;
                        if (!el || !el.closest) {
                                return;
                        }
                        var btn = el.closest('[data-nxa-cal-confirm]');
                        if (!btn || btn.disabled) {
                                return;
                        }
                        event.preventDefault();
                        var form = btn.closest('form');
                        if (!form) {
                                return;
                        }
                        var msg = btn.getAttribute('data-nxa-cal-confirm') || '';
                        var yes = btn.getAttribute('data-nxa-cal-yes') || 'Sì';
                        ui.inlineConfirmOpen(btn, msg, yes, function () {
                                if (btn.name) {
                                        var hidden = document.createElement('input');
                                        hidden.type = 'hidden';
                                        hidden.name = btn.name;
                                        hidden.value = btn.value || '';
                                        form.appendChild(hidden);
                                }
                                form.submit();
                        });
                });
        }());
        </script>

</div>
