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
 *               data-include-chief="0"), #nxa-obs-rss (checkbox fonti, dalla
 *               v1.1.16 NON pre-selezionata: le fonti di esempio diventano
 *               config di produzione solo con una spunta esplicita) e
 *               #nxa-obs-rss-textarea (EDITABILE, precompilata qui sotto
 *               con feed d'esempio REALI: i dati sono visibili e modificabili,
 *               MAI applicati in silenzio lato PHP — il salvataggio avviene
 *               SOLO se la checkbox è attiva, via PUT /settings con la chiave
 *               `global_rss_sources`, STRINGA una URL per riga — contratto
 *               con la branch fonti R1);
 *  - Step 2bis (v1.1.16 WE4): radio name="nxa-obs-destination" — "Dove
 *               finiscono gli articoli" con mapping ai setting ESISTENTI
 *               (whitelist PUT /settings): nexus → mode=semi_autonomous;
 *               draft → post_status=draft + enable_wp_posts=1 +
 *               mode=fully_autonomous; publish → post_status=publish +
 *               enable_wp_posts=1 + mode=fully_autonomous. La nota
 *               #nxa-obs-dest-note (hidden) viene mostrata da admin.js SOLO
 *               per la combinazione semi_autonomous + post_status=publish;
 *  - Step 3:    #nxa-obs-progress (testo "Creo agente X di N…") e
 *               #nxa-obs-log (righe per agente, una per POST /agents);
 *  - Step 4:    #nxa-obs-summary (riepilogo ONESTO: N creati, eventuali
 *               errori col messaggio REST reale, esito fonti RSS),
 *               #nxa-obs-next (v1.1.16: pannello "Prossimi passi" — primo
 *               giro cron reale dall'intervallo di raccolta, dove vedere gli
 *               articoli, ▶ Avvia per un run immediato) e
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

/*
 * v1.1.19 («Primi Passi») — STEP 0 "Collega il provider AI": renderizzato SOLO
 * se nessuna chiave AI è configurata (stessa logica di GET /settings/status,
 * via Nexus_Rest::settings_status_flags() — solo presenza, mai valori).
 *
 * Il salvataggio (admin.js, nxaObsSaveKey) scrive sul canale GLOBALE legacy
 * (ai_api_key + ai_base_url + ai_model): gli agenti creati dal wizard nascono
 * SENZA provider dedicato e usano proprio quel canale (Nexus_Providers::
 * resolve_for_agent → legacy). I provider 'custom' e 'ollama' restano fuori
 * dallo step (richiedono campi extra: si configurano da Impostazioni → AI).
 * I modelli di default per provider vivono nella mappa qui sotto: sono solo
 * il punto di partenza, modificabili in ogni momento dalle Impostazioni.
 */
$nxa_obs_has_ai_key = false;
if ( class_exists( 'Nexus_Rest' ) && method_exists( 'Nexus_Rest', 'settings_status_flags' ) ) {
        $nxa_obs_flags     = (array) Nexus_Rest::settings_status_flags();
        $nxa_obs_has_ai_key = ! empty( $nxa_obs_flags['ai_provider_active'] );
}

$nxa_obs_providers = array();
if ( ! $nxa_obs_has_ai_key && class_exists( 'Nexus_Providers' ) && method_exists( 'Nexus_Providers', 'catalog' ) ) {
        $nxa_obs_default_models = array(
                'openrouter' => 'openai/gpt-4o-mini',
                'openai'     => 'gpt-4o-mini',
                'anthropic'  => 'claude-3-5-haiku-20241022',
                'google'     => 'gemini-2.0-flash',
                'groq'       => 'llama-3.3-70b-versatile',
                'deepseek'   => 'deepseek-chat',
                'mistral'    => 'mistral-small-latest',
                'xai'        => 'grok-2-latest',
        );
        foreach ( (array) Nexus_Providers::catalog() as $nxa_obs_prov ) {
                $nxa_obs_pid = ( is_array( $nxa_obs_prov ) && isset( $nxa_obs_prov['id'] ) ) ? (string) $nxa_obs_prov['id'] : '';
                if ( '' === $nxa_obs_pid || 'custom' === $nxa_obs_pid || 'ollama' === $nxa_obs_pid || 0 === strpos( $nxa_obs_pid, 'cust_' ) ) {
                        continue;
                }
                $nxa_obs_prov['default_model'] = isset( $nxa_obs_default_models[ $nxa_obs_pid ] ) ? $nxa_obs_default_models[ $nxa_obs_pid ] : '';
                $nxa_obs_providers[]           = $nxa_obs_prov;
        }
}
?>
<div id="nxa-onboarding" class="nxa-modal nxa-onboarding" hidden role="dialog" aria-modal="true" aria-labelledby="nxa-onboarding-title">
        <div class="nxa-modal-panel nxa-onboarding-panel" role="document">
                <button type="button" class="nxa-modal-close" data-nxa-onboarding-close aria-label="<?php esc_attr_e( 'Chiudi', 'nexus-news-ai' ); ?>">&times;</button>
                <h2 id="nxa-onboarding-title"><?php esc_html_e( 'Crea la tua redazione', 'nexus-news-ai' ); ?></h2>
                <p class="nxa-obs-sub"><?php esc_html_e( 'Benvenuto: parti con il pacchetto di agenti che vuoi. Potrai modificare, aggiungere o eliminare agenti in ogni momento.', 'nexus-news-ai' ); ?></p>

                <!-- STEP 0 (v1.1.19) — chiave AI: SOLO se nessuna chiave è
                     configurata (guard PHP in testa). Se assente il wizard parte
                     direttamente dallo step 1 (admin.js: nxaOnboardingReset). -->
                <?php if ( ! $nxa_obs_has_ai_key && ! empty( $nxa_obs_providers ) ) : ?>
                <div class="nxa-obs-step" data-nxa-obs-step="0">
                        <h3 class="nxa-obs-step-title"><?php esc_html_e( 'Collega il provider AI', 'nexus-news-ai' ); ?></h3>
                        <p class="nxa-obs-sub"><?php esc_html_e( 'Gli agenti scrivono e valutano con l’AI: serve una chiave API. Incollala qui e varrà per tutta la redazione (potrai collegare altri provider da Impostazioni → AI).', 'nexus-news-ai' ); ?></p>
                        <p>
                                <label for="nxa-obs-provider"><strong><?php esc_html_e( 'Provider', 'nexus-news-ai' ); ?></strong></label><br />
                                <select id="nxa-obs-provider">
                                        <?php foreach ( $nxa_obs_providers as $nxa_obs_prov ) : ?>
                                                <option value="<?php echo esc_attr( (string) $nxa_obs_prov['id'] ); ?>"
                                                        data-base="<?php echo esc_attr( isset( $nxa_obs_prov['base_url'] ) ? (string) $nxa_obs_prov['base_url'] : '' ); ?>"
                                                        data-model="<?php echo esc_attr( (string) $nxa_obs_prov['default_model'] ); ?>"
                                                        data-keypage="<?php echo esc_attr( isset( $nxa_obs_prov['key_page'] ) ? (string) $nxa_obs_prov['key_page'] : '' ); ?>"><?php echo esc_html( (string) $nxa_obs_prov['label'] ); ?></option>
                                        <?php endforeach; ?>
                                </select>
                                <a href="#" target="_blank" rel="noopener" id="nxa-obs-keypage"><?php esc_html_e( 'Dove trovo la chiave?', 'nexus-news-ai' ); ?></a>
                        </p>
                        <p>
                                <label for="nxa-obs-key"><strong><?php esc_html_e( 'Chiave API', 'nexus-news-ai' ); ?></strong></label><br />
                                <input type="password" id="nxa-obs-key" class="regular-text code" autocomplete="new-password" />
                        </p>
                        <p class="nxa-obs-key-error" id="nxa-obs-key-error" role="alert" hidden></p>
                        <p class="nxa-obs-key-actions">
                                <button type="button" class="nxa-btn nxa-btn-primary" id="nxa-obs-key-save"><?php esc_html_e( 'Salva e continua', 'nexus-news-ai' ); ?></button>
                                <button type="button" class="button-link" id="nxa-obs-key-skip"><?php esc_html_e( 'Lo farò dopo', 'nexus-news-ai' ); ?></button>
                        </p>
                </div>
                <?php endif; ?>

                <!-- STEP 1 — pacchetti preset (radio card, conteggio visibile). -->
                <div class="nxa-obs-step" data-nxa-obs-step="1">
                        <div class="nxa-obs-grid" role="radiogroup" aria-label="<?php esc_attr_e( 'Scegli il pacchetto di agenti', 'nexus-news-ai' ); ?>">
                                <label class="nxa-obs-card is-selected">
                                        <input type="radio" name="nxa-obs-preset" value="generalista" data-presets="tech,politica,economia,sport,cultura" data-include-chief="0" checked="checked" />
                                        <span class="nxa-obs-emoji" aria-hidden="true">📰</span>
                                        <span class="nxa-obs-card-title"><?php esc_html_e( 'Testata generalista', 'nexus-news-ai' ); ?></span>
                                        <span class="nxa-obs-card-desc"><?php esc_html_e( 'Cinque giornalisti pronti: tecnologia, politica, economia, sport e cultura.', 'nexus-news-ai' ); ?></span>
                                        <span class="nxa-obs-card-count"><?php echo esc_html__( '5 agenti', 'nexus-news-ai' ); ?></span>
                                </label>
                                <label class="nxa-obs-card">
                                        <input type="radio" name="nxa-obs-preset" value="veloci" data-presets="tech,politica,economia" data-include-chief="0" />
                                        <span class="nxa-obs-emoji" aria-hidden="true">🚀</span>
                                        <span class="nxa-obs-card-title"><?php esc_html_e( 'News veloci', 'nexus-news-ai' ); ?></span>
                                        <span class="nxa-obs-card-desc"><?php esc_html_e( 'Tre giornalisti per partire subito con l’essenziale.', 'nexus-news-ai' ); ?></span>
                                        <span class="nxa-obs-card-count"><?php echo esc_html__( '3 agenti', 'nexus-news-ai' ); ?></span>
                                </label>
                                <label class="nxa-obs-card">
                                        <input type="radio" name="nxa-obs-preset" value="tech" data-presets="tech" data-include-chief="0" />
                                        <span class="nxa-obs-emoji" aria-hidden="true">🤖</span>
                                        <span class="nxa-obs-card-title"><?php esc_html_e( 'Solo Tecnologia', 'nexus-news-ai' ); ?></span>
                                        <span class="nxa-obs-card-desc"><?php esc_html_e( 'Un giornalista tech per partire dal necessario.', 'nexus-news-ai' ); ?></span>
                                        <span class="nxa-obs-card-count"><?php echo esc_html__( '1 agente', 'nexus-news-ai' ); ?></span>
                                </label>
                                <label class="nxa-obs-card">
                                        <input type="radio" name="nxa-obs-preset" value="agenzia" data-presets="tech,politica,economia,sport,cultura,scienza,salute,capo" data-include-chief="1" />
                                        <span class="nxa-obs-emoji" aria-hidden="true">🏛️</span>
                                        <span class="nxa-obs-card-title"><?php esc_html_e( 'Agenzia completa', 'nexus-news-ai' ); ?></span>
                                        <span class="nxa-obs-card-desc"><?php esc_html_e( 'Sette giornalisti e il capo redattore: la redazione completa.', 'nexus-news-ai' ); ?></span>
                                        <span class="nxa-obs-card-count"><?php echo esc_html__( '7 agenti + capo redattore', 'nexus-news-ai' ); ?></span>
                                </label>
                                <label class="nxa-obs-card nxa-obs-card--manual">
                                        <input type="radio" name="nxa-obs-preset" value="manual" data-presets="" data-include-chief="0" />
                                        <span class="nxa-obs-emoji" aria-hidden="true">✍️</span>
                                        <span class="nxa-obs-card-title"><?php esc_html_e( 'Preferisco crearli a mano', 'nexus-news-ai' ); ?></span>
                                        <span class="nxa-obs-card-desc"><?php esc_html_e( 'Nessun agente ora: chiudi il wizard e usa i preset o il modal nuovo agente.', 'nexus-news-ai' ); ?></span>
                                        <span class="nxa-obs-card-count"><?php echo esc_html__( '0 agenti', 'nexus-news-ai' ); ?></span>
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

                        <!-- v1.1.16 (WE4): "Dove finiscono gli articoli" — scelta esplicita
                             con mapping ai setting ESISTENTI (mode/post_status/enable_wp_posts,
                             whitelist PUT /settings — vedi docblock). Salvataggio in
                             admin.js (nxaOnboardingRun → nxaObsSaveDestination). Nessuna
                             classe CSS nuova (nexus-admin.css fuori perimetro): riuso
                             .nxa-obs-option e stili inline a token del design system. -->
                        <div class="nxa-obs-option" style="display:block;cursor:default">
                                <span class="nxa-obs-option-text" style="width:100%">
                                        <strong><?php esc_html_e( 'Dove finiscono gli articoli', 'nexus-news-ai' ); ?></strong>
                                        <span><?php esc_html_e( 'Scegli dove vedi il risultato del lavoro degli agenti: puoi cambiarlo in ogni momento da Impostazioni → Contenuti e Pubblicazione.', 'nexus-news-ai' ); ?></span>
                                        <span style="display:flex;flex-direction:column;gap:10px;margin-top:8px" role="radiogroup" aria-label="<?php esc_attr_e( 'Dove finiscono gli articoli', 'nexus-news-ai' ); ?>">
                                                <label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer">
                                                        <input type="radio" name="nxa-obs-destination" value="nexus" checked="checked" />
                                                        <span style="display:block">
                                                                <strong><?php esc_html_e( 'Solo Nexus (approvazione manuale)', 'nexus-news-ai' ); ?></strong><br />
                                                                <span style="font-size:0.8rem;line-height:1.5;color:var(--nxa-muted)"><?php esc_html_e( 'Gli articoli restano in Nexus: li vedi nella Coda Approvazioni e decidi tu cosa pubblicare. Su WordPress non arriva nulla finché non approvi.', 'nexus-news-ai' ); ?></span>
                                                        </span>
                                                </label>
                                                <label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer">
                                                        <input type="radio" name="nxa-obs-destination" value="draft" />
                                                        <span style="display:block">
                                                                <strong><?php esc_html_e( 'Bozze WordPress', 'nexus-news-ai' ); ?></strong><br />
                                                                <span style="font-size:0.8rem;line-height:1.5;color:var(--nxa-muted)"><?php esc_html_e( 'Gli articoli approvati diventano Bozze in WordPress: le trovi in Articoli → Bozze, pronte per la tua revisione finale.', 'nexus-news-ai' ); ?></span>
                                                        </span>
                                                </label>
                                                <label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer">
                                                        <input type="radio" name="nxa-obs-destination" value="publish" />
                                                        <span style="display:block">
                                                                <strong><?php esc_html_e( 'Pubblica direttamente', 'nexus-news-ai' ); ?></strong><br />
                                                                <span style="font-size:0.8rem;line-height:1.5;color:var(--nxa-muted)"><?php esc_html_e( 'Gli articoli approvati vengono pubblicati subito sul sito: li vedi in Articoli → Tutti e nel magazine pubblico.', 'nexus-news-ai' ); ?></span>
                                                        </span>
                                                </label>
                                        </span>
                                        <!-- Nota combo semi_autonomous + post_status=publish: mostrata
                                             da admin.js (nxaObsSyncDestNote) SOLO in quel caso. -->
                                        <span id="nxa-obs-dest-note" hidden style="display:block;margin-top:10px;padding:8px 10px;border-radius:8px;background:var(--nxa-warn-bg);color:var(--nxa-warn-text);font-size:0.8rem;line-height:1.5"><?php esc_html_e( 'Nota: la redazione è semi-autonoma ma le impostazioni attuali pubblicano direttamente su WordPress. Con "Solo Nexus" gli articoli restano comunque nella Coda Approvazioni di Nexus finché non li approvi tu.', 'nexus-news-ai' ); ?></span>
                                </span>
                        </div>

                        <!-- v1.1.16 (WE4): checkbox NON più pre-selezionata (le fonti di
                             esempio non diventano config di produzione in silenzio) e
                             etichetta esplicita con le 5 fonti che aggiunge. -->
                        <label class="nxa-obs-option">
                                <input type="checkbox" id="nxa-obs-rss" value="1" />
                                <span class="nxa-obs-option-text">
                                        <strong><?php esc_html_e( 'Precarica fonti RSS globali di esempio', 'nexus-news-ai' ); ?></strong>
                                        <span><?php esc_html_e( 'Se non la spunti, nessuna fonte viene salvata: puoi aggiungere le tue fonti dopo, dalla redazione di ogni agente o da Impostazioni. Se la spunti aggiunge queste 5 fonti d’esempio: ANSA (generale), ANSA (politica), Il Post, Il Sole 24 Ore (economia) e Wired.it.', 'nexus-news-ai' ); ?></span>
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

                <!-- STEP 4 — riepilogo onesto + prossimi passi. -->
                <div class="nxa-obs-step" data-nxa-obs-step="4" hidden>
                        <div id="nxa-obs-summary" class="nxa-obs-summary" aria-live="polite"></div>
                        <!-- v1.1.16 (WE4): "Prossimi passi" — compilato da admin.js
                             (nxaObsRenderNextSteps): primo giro cron reale (intervallo
                             di raccolta), dove vedere gli articoli in base alla
                             destinazione salvata e run immediato con ▶ Avvia. -->
                        <h3 class="nxa-obs-step-title"><?php esc_html_e( 'Prossimi passi', 'nexus-news-ai' ); ?></h3>
                        <div id="nxa-obs-next"></div>
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
