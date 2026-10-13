<?php
/**
 * Vista partial: WIZARD DI ONBOARDING "Crea la tua redazione" (v1.1.11, R3).
 *
 * Incluso SOLO da admin/views/agents.php (in fondo, come il modal editor
 * #nxa-agent-modal): è un pannello modal statico servito dal PHP e gestito
 * interamente da assets/js/admin.js — sezione v1.1.11 (R3) "Onboarding
 * wizard". Non viene mai incluso da dashboard.php: lì vive SOLO la card CTA
 * "Crea la tua redazione" con link alla pagina Agenti + hash #nxa-onboarding.
 *
 * CONTRATTO CON admin.js (sezione v1.1.11, verificata sul sorgente):
 *  - apertura:  rimozione [hidden] da #nxa-onboarding + body overflow hidden
 *               (stesso pattern di openAgentEditor); trigger: 0 agenti
 *               (auto-open una volta per vista, dalla griglia vuota), hash
 *               #nxa-onboarding, click su .nxa-onboarding-open (delegato su
 *               document, quindi funziona anche dalla card CTA della
 *               dashboard dopo il redirect);
 *  - chiusura:  click su [data-nxa-onboarding-close], #nxa-obs-cancel o
 *               #nxa-obs-done, oppure tasto ESC con il pannello aperto;
 *  - Step 1:    radio name="nxa-obs-preset" dentro .nxa-obs-card; la CARD
 *               scelta porta la classe is-selected (aggiornata dal JS al
 *               change); il valore del pacchetto è letto dagli attributi
 *               data-presets (chiavi preset separate da virgola: STESSA
 *               grammatica della barra "Creazione rapida": tech|politica|
 *               economia|sport|cultura|scienza|salute|capo) e
 *               data-include-chief ("1" = il capo è GIÀ nel pacchetto);
 *               value="manual" → il wizard si chiude senza creare nulla;
 *  - Step 2:    #nxa-obs-chief (checkbox capo redattore: mostrato solo se
 *               data-include-chief="0"), #nxa-obs-rss (checkbox fonti) e
 *               #nxa-obs-rss-textarea (EDITABILE, precompilata qui sotto
 *               con feed d'esempio REALI: i dati sono visibili e modificabili,
 *               MAI applicati in silenzio lato PHP — il salvataggio avviene
 *               SOLO se la checkbox è attiva, via PUT /settings con la chiave
 *               `global_rss_sources`, STRINGA una URL per riga — contratto
 *               con la branch fonti R1);
 *  - Step 3:    #nxa-obs-progress (testo "Creo agente X di N…") e
 *               #nxa-obs-log (righe per agente, una per POST /agents);
 *  - Step 4:    #nxa-obs-summary (riepilogo ONESTO: N creati, eventuali
 *               errori col messaggio REST reale, esito fonti RSS) e
 *               #nxa-obs-done (chiude; la griglia è già stata ricaricata).
 *
 * Tutti gli id/classi qui portano prefisso nxa- / nxa-obs- (zero collisioni).
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

/*
 * Fonti RSS di ESEMPIO mostrate nel wizard (v1.1.11): feed reali e
 * aggiornabili, già usati nel progetto come fonti d'esempio. Sono SOLO il
 * contenuto iniziale VISIBILE della textarea: nulla viene salvato se
 * l'utente non spunta la relativa opzione nel wizard.
 */
$nxa_obs_sample_rss = implode(
        "\n",
        array(
                'https://www.ansa.it/sito/ansait_rss.xml',
                'https://www.ilpost.it/feed/',
                'https://www.ilsole24ore.com/rss/economia.xml',
                'https://www.wired.it/feed/rss',
                'https://www.ansa.it/sito/notizie/politica/politica_rss.xml',
        )
);
?>
<div id="nxa-onboarding" class="nxa-modal nxa-onboarding" hidden role="dialog" aria-modal="true" aria-labelledby="nxa-onboarding-title">
        <div class="nxa-modal-panel nxa-onboarding-panel" role="document">
                <button type="button" class="nxa-modal-close" data-nxa-onboarding-close aria-label="<?php esc_attr_e( 'Chiudi', 'nexus-news-ai' ); ?>">&times;</button>
                <h2 id="nxa-onboarding-title"><?php esc_html_e( 'Crea la tua redazione', 'nexus-news-ai' ); ?></h2>
                <p class="nxa-obs-sub"><?php esc_html_e( 'Benvenuto: parti con il pacchetto di agenti che vuoi. Potrai modificare, aggiungere o eliminare agenti in ogni momento.', 'nexus-news-ai' ); ?></p>

                <!-- STEP 1 — pacchetti preset (radio card, conteggio visibile). -->
                <div class="nxa-obs-step" data-nxa-obs-step="1">
                        <div class="nxa-obs-grid" role="radiogroup" aria-label="<?php esc_attr_e( 'Scegli il pacchetto di agenti', 'nexus-news-ai' ); ?>">
                                <label class="nxa-obs-card is-selected">
                                        <input type="radio" name="nxa-obs-preset" value="generalista" data-presets="tech,politica,economia,sport,cultura" data-include-chief="0" checked="checked" />
                                        <span class="nxa-obs-emoji" aria-hidden="true">📰</span>
                                        <span class="nxa-obs-card-title"><?php esc_html_e( 'Testata generalista', 'nexus-news-ai' ); ?></span>
                                        <span class="nxa-obs-card-desc"><?php esc_html_e( 'Cinque giornalisti pronti: tecnologia, politica, economia, sport e cultura.', 'nexus-news-ai' ); ?></span>
                                        <span class="nxa-obs-card-count"><?php echo esc_html( '5 agenti' ); ?></span>
                                </label>
                                <label class="nxa-obs-card">
                                        <input type="radio" name="nxa-obs-preset" value="veloci" data-presets="tech,politica,economia" data-include-chief="0" />
                                        <span class="nxa-obs-emoji" aria-hidden="true">🚀</span>
                                        <span class="nxa-obs-card-title"><?php esc_html_e( 'News veloci', 'nexus-news-ai' ); ?></span>
                                        <span class="nxa-obs-card-desc"><?php esc_html_e( 'Tre giornalisti per partire subito con l’essenziale.', 'nexus-news-ai' ); ?></span>
                                        <span class="nxa-obs-card-count"><?php echo esc_html( '3 agenti' ); ?></span>
                                </label>
                                <label class="nxa-obs-card">
                                        <input type="radio" name="nxa-obs-preset" value="tech" data-presets="tech" data-include-chief="0" />
                                        <span class="nxa-obs-emoji" aria-hidden="true">🤖</span>
                                        <span class="nxa-obs-card-title"><?php esc_html_e( 'Solo Tecnologia', 'nexus-news-ai' ); ?></span>
                                        <span class="nxa-obs-card-desc"><?php esc_html_e( 'Un giornalista tech per partire dal necessario.', 'nexus-news-ai' ); ?></span>
                                        <span class="nxa-obs-card-count"><?php echo esc_html( '1 agente' ); ?></span>
                                </label>
                                <label class="nxa-obs-card">
                                        <input type="radio" name="nxa-obs-preset" value="agenzia" data-presets="tech,politica,economia,sport,cultura,scienza,salute,capo" data-include-chief="1" />
                                        <span class="nxa-obs-emoji" aria-hidden="true">🏛️</span>
                                        <span class="nxa-obs-card-title"><?php esc_html_e( 'Agenzia completa', 'nexus-news-ai' ); ?></span>
                                        <span class="nxa-obs-card-desc"><?php esc_html_e( 'Sette giornalisti e il capo redattore: la redazione completa.', 'nexus-news-ai' ); ?></span>
                                        <span class="nxa-obs-card-count"><?php echo esc_html( '7 agenti + capo redattore' ); ?></span>
                                </label>
                                <label class="nxa-obs-card nxa-obs-card--manual">
                                        <input type="radio" name="nxa-obs-preset" value="manual" data-presets="" data-include-chief="0" />
                                        <span class="nxa-obs-emoji" aria-hidden="true">✍️</span>
                                        <span class="nxa-obs-card-title"><?php esc_html_e( 'Preferisco crearli a mano', 'nexus-news-ai' ); ?></span>
                                        <span class="nxa-obs-card-desc"><?php esc_html_e( 'Nessun agente ora: chiudi il wizard e usa i preset o il modal nuovo agente.', 'nexus-news-ai' ); ?></span>
                                        <span class="nxa-obs-card-count"><?php echo esc_html( '0 agenti' ); ?></span>
                                </label>
                        </div>
                </div>

                <!-- STEP 2 — opzioni. -->
                <div class="nxa-obs-step" data-nxa-obs-step="2" hidden>
                        <h3 class="nxa-obs-step-title"><?php esc_html_e( 'Opzioni (facoltative)', 'nexus-news-ai' ); ?></h3>

                        <label class="nxa-obs-option" id="nxa-obs-chief-row">
                                <input type="checkbox" id="nxa-obs-chief" value="1" />
                                <span class="nxa-obs-option-text">
                                        <strong><?php esc_html_e( 'Aggiungi un capo redattore AI', 'nexus-news-ai' ); ?></strong>
                                        <span><?php esc_html_e( 'Rilegge gli articoli dei giornalisti prima della pubblicazione.', 'nexus-news-ai' ); ?></span>
                                </span>
                        </label>

                        <label class="nxa-obs-option">
                                <input type="checkbox" id="nxa-obs-rss" value="1" checked="checked" />
                                <span class="nxa-obs-option-text">
                                        <strong><?php esc_html_e( 'Precarica fonti RSS globali di esempio', 'nexus-news-ai' ); ?></strong>
                                        <span><?php esc_html_e( 'Una URL per riga. Puoi modificare l’elenco qui sotto: verrà salvato tra le impostazioni globali.', 'nexus-news-ai' ); ?></span>
                                </span>
                        </label>
                        <textarea id="nxa-obs-rss-textarea" rows="6" class="code nxa-obs-rss-textarea" aria-label="<?php esc_attr_e( 'Fonti RSS di esempio (una URL per riga)', 'nexus-news-ai' ); ?>"><?php echo esc_textarea( $nxa_obs_sample_rss ); ?></textarea>
                </div>

                <!-- STEP 3 — esecuzione: una riga per agente + barra progresso. -->
                <div class="nxa-obs-step" data-nxa-obs-step="3" hidden>
                        <p class="nxa-obs-progress" id="nxa-obs-progress"><?php esc_html_e( 'Creo gli agenti…', 'nexus-news-ai' ); ?></p>
                        <div class="nxa-obs-progressbar" aria-hidden="true"><span id="nxa-obs-progressbar-fill"></span></div>
                        <ul class="nxa-obs-log" id="nxa-obs-log" aria-live="polite"></ul>
                </div>

                <!-- STEP 4 — riepilogo onesto. -->
                <div class="nxa-obs-step" data-nxa-obs-step="4" hidden>
                        <div id="nxa-obs-summary" class="nxa-obs-summary" aria-live="polite"></div>
                </div>

                <p class="nxa-obs-actions">
                        <button type="button" id="nxa-obs-continue" class="nxa-btn nxa-btn-primary"><?php esc_html_e( 'Continua', 'nexus-news-ai' ); ?></button>
                        <button type="button" id="nxa-obs-back" class="nxa-btn" hidden><?php esc_html_e( 'Indietro', 'nexus-news-ai' ); ?></button>
                        <button type="button" id="nxa-obs-run" class="nxa-btn nxa-btn-primary" hidden><?php esc_html_e( 'Crea gli agenti', 'nexus-news-ai' ); ?></button>
                        <button type="button" id="nxa-obs-done" class="nxa-btn nxa-btn-primary" hidden><?php esc_html_e( 'Vedi la redazione', 'nexus-news-ai' ); ?></button>
                        <button type="button" id="nxa-obs-cancel" class="nxa-btn"><?php esc_html_e( 'Annulla', 'nexus-news-ai' ); ?></button>
                </p>
        </div>
</div>
