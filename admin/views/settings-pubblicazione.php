<?php
/**
 * Vista Admin: Impostazioni pubblicazione (pagina "settings-pubblicazione" di Nexus News AI).
 *
 * v1.1.6 (contratto T-02): le Impostazioni diventano 4 sottomenu WP; questa è
 * la pagina del gruppo "Pubblicazione & distribuzione" (accordion: wp-seo,
 * social, newsletter, indexing) e raccoglie la terza parte della vecchia
 * settings.php (rimossa in v1.1.6). Le due sezioni social della vecchia
 * pagina ("Pubblicazione social" e "Distribuzione sociale") sono UNIFICATE
 * in un'unica sezione accordion data-nxa-acc="social".
 *
 * v1.1.13 (O3): l'handler accordion storico (IIFE ES5) è RIMOSSO: si annullava
 * con le IIFE degli altri 3 partial ed è sostituito dall'UNICO handler
 * delegato nello script inline di admin/views/settings.php (toggle + snapshot
 * nella stessa chiave localStorage['nxa-settings-acc-pubblicazione']). Il
 * blocco di stile inline è stato spostato in assets/css/admin-tabs.css.
 * Nuova riga nella sezione "Notifiche": nexus_notify_telegram_chat (testo,
 * NON segreto — identificatore di chat, mai mascherato; whitelist REST e
 * bridge PUT della pagina unica v1.1.13 O3).
 *
 * v1.1.12 (S8): due nuove sezioni accordion — "Google News" e "Notifiche".
 * I name dei campi = chiavi ESATTE di Nexus_DB::default_settings() v1.1.12:
 * news_sitemap_enabled, news_sitemap_name (modulo S5), notify_webhook_url,
 * notify_events (modulo S7). Il webhook segue il pattern segreti v1.1.9
 * (type="password", value stampato esc_attr per re-invio idempotente,
 * placeholder "•••• salvata — compila per sostituire" quando valorizzato,
 * autocomplete="new-password"); la chiave entra nella mascheratura REST
 * Nexus_Rest::secret_settings_keys() (v1.1.12 S8). I gruppi nuovi nascono
 * chiusi, stesso contratto localStorage/IIFE degli altri. DEBITO
 * INTEGRAZIONE (S8): i name nuovi non sono ancora negli array di
 * collectSettings() in admin.js né nella whitelist REST (file fuori perimetro
 * S8): il markup segue già il contratto, il salvataggio effettivo arriva con
 * l'estensione del glue (stesso percorso di global_rss_sources in 1.1.11).
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
 * - I toggle (enable_wp_posts, enable_ai_images, enable_seo, seo_generate,
 *   enable_telegram, enable_discord, enable_x, enable_linkedin,
 *   enable_newsletter, enable_digest, enable_indexnow, enable_websub) sono
 *   checkbox value="1": admin.js li serializza come 0/1 (0 quando deselezionati).
 * - Le piattaforme social sono checkbox name="social_platforms[]" con
 *   value = blog|telegram|discord|twitter|linkedin: admin.js serializza le
 *   voci selezionate in stringa CSV nel campo social_platforms.
 * - Tutti gli altri campi usano name = chiave esatta di Nexus_DB::default_settings():
 *   post_status, image_model, image_size, telegram_bot_token,
 *   telegram_chat_id, discord_webhook_url, x_api_key, x_api_secret,
 *   x_access_token, x_access_secret, linkedin_access_token,
 *   linkedin_author_urn, newsletter_recipients, indexnow_key.
 * - CREDENZIALI SOCIAL (v1.1.9, WP2): i 7 campi segreti (telegram_bot_token,
 *   discord_webhook_url, x_api_key, x_api_secret, x_access_token, x_access_secret,
 *   linkedin_access_token) sono type="password" con autocomplete="new-password" e
 *   placeholder '•••• salvata — compila per sostituire' quando il valore esiste.
 *   IL VALUE RESTA STAMPATO (esc_attr): verificato admin.js (solo lettura),
 *   collectSettings() include questi campi nel blocco "simple" e li invia SEMPRE
 *   al PUT /settings anche vuoti — con value vuoto ogni salvataggio pagina
 *   azzererebbe i segreti. Il value stampato rende il re-invio idempotente;
 *   il masking REST (v1.1.9) protegge comunque i valori via API.
 * - indexnow_key è readonly: la chiave è generata automaticamente alla prima
 *   pubblicazione (Nexus_Ping) e non è modificabile da schermo.
 * - Bottone SALVA IN CIMA (subito sotto l'h1) OLTRE quello in fondo: entrambi
 *   type="submit" nella stessa form; admin.js v1.1.6 mostra lo stato di
 *   caricamento su tutti i submit della form.
 * - Sezioni accordion section.nxa-acc[data-nxa-acc] con
 *   button.nxa-acc-toggle[aria-expanded][aria-controls], span.nxa-acc-label,
 *   span.nxa-acc-chevron, div.nxa-acc-panel[hidden]. Lo stato dei gruppi
 *   (array slug aperti) è persistito in
 *   localStorage['nxa-settings-acc-pubblicazione'] (chiave propria del tab,
 *   JSON con try/catch) dall'UNICO handler delegato in
 *   admin/views/settings.php (v1.1.13 O3: nessuna IIFE locale); default
 *   senza localStorage: pannello "wp-seo" aperto, gli altri chiusi (stato
 *   renderizzato anche server-side, così senza JS la prima sezione resta
 *   leggibile). Le classi accordion sono definite nel CSS condiviso
 *   assets/css/admin-tabs.css.
 *
 * - CONTRATTO v1.1.6 ("zero mock", T-02): la lista dei canali selezionabili
 *   $social_platforms contiene SOLO i canali con integrazione reale
 *   (blog, telegram, discord, twitter, linkedin). Facebook e Instagram NON
 *   hanno alcuna API implementata (erano la fonte della pubblicazione
 *   simulata bandita dal contratto v1.1.6) e NON sono più selezionabili; un
 *   eventuale valore residuo salvato in DB non compare più come checkbox e
 *   viene rimosso dal CSV al prossimo salvataggio della pagina.
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

$settings = Nexus_DB::get_settings();

$active_platforms = array_filter( array_map( 'trim', explode( ',', (string) $settings['social_platforms'] ) ) );

/* v1.1.13 (O3): chat Telegram ops per le notifiche interne (contratto opzioni
 * 1.1.13). È un'OPZIONE WP INDIPENDENTE (non chiave dell'array nexus_settings):
 * il consumatore Nexus_Notify v1.1.13 la legge con get_option() dedicata e la
 * whitelist PUT v1.1.13 la scrive allo stesso modo
 * (Nexus_Rest::persist_scale_limits). NON è un segreto: testo normale. */
$nxa_notify_chat = (string) get_option( 'nexus_notify_telegram_chat', '' );

/* Canali con integrazione reale (v1.1.6): i valori sono le chiavi esatte
 * consumate da Nexus_Publisher::publish() (social_map: telegram, discord,
 * x con alias storico twitter, linkedin; blog = post WordPress nativi).
 * Facebook e Instagram non hanno API reale e sono stati rimossi. */
$social_platforms = array(
        'blog'     => __( 'Blog (WordPress)', 'nexus-news-ai' ),
        'telegram' => __( 'Telegram', 'nexus-news-ai' ),
        'discord'  => __( 'Discord', 'nexus-news-ai' ),
        'twitter'  => __( 'Twitter / X', 'nexus-news-ai' ),
        'linkedin' => __( 'LinkedIn', 'nexus-news-ai' ),
);
?>
<div class="wrap nexus-admin" data-page="settings">
        <h1><?php esc_html_e( 'Impostazioni pubblicazione', 'nexus-news-ai' ); ?></h1>

        <form id="nxa-settings-form" class="nxa-settings-form">
                <p class="submit nxa-submit-top">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Salva impostazioni', 'nexus-news-ai' ); ?></button>
                </p>

                <section class="nxa-acc" data-nxa-acc="wp-seo">
                        <button type="button" class="nxa-acc-toggle" aria-expanded="true" aria-controls="nxa-acc-panel-wp-seo">
                                <span class="nxa-acc-label"><?php esc_html_e( 'Pubblicazione WordPress & SEO', 'nexus-news-ai' ); ?></span>
                                <span class="nxa-acc-chevron" aria-hidden="true"></span>
                        </button>
                        <div class="nxa-acc-panel" id="nxa-acc-panel-wp-seo">
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

                <section class="nxa-acc" data-nxa-acc="social">
                        <button type="button" class="nxa-acc-toggle" aria-expanded="false" aria-controls="nxa-acc-panel-social">
                                <span class="nxa-acc-label"><?php esc_html_e( 'Distribuzione social', 'nexus-news-ai' ); ?></span>
                                <span class="nxa-acc-chevron" aria-hidden="true"></span>
                        </button>
                        <div class="nxa-acc-panel" id="nxa-acc-panel-social" hidden>
                                <?php /* Sezione unificata v1.1.6 (ex "Pubblicazione social" + "Distribuzione sociale"): selezione canali, gate per canale e credenziali. TUTTI i name e gli id dei campi restano IDENTICI (contratto fisso con admin.js). */ ?>
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
                                                                <input type="password" name="telegram_bot_token" id="nxa-telegram-bot-token" value="<?php echo esc_attr( $settings['telegram_bot_token'] ); ?>" placeholder="<?php echo esc_attr( '' !== trim( (string) $settings['telegram_bot_token'] ) ? __( '•••• salvata — compila per sostituire', 'nexus-news-ai' ) : '' ); ?>" class="regular-text code" autocomplete="new-password" />
                                                                <p class="description"><?php esc_html_e( 'Token del bot Telegram da @BotFather (mascherato: il valore salvato non è visibile a schermo).', 'nexus-news-ai' ); ?></p>
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
                                                                <input type="password" name="discord_webhook_url" id="nxa-discord-webhook-url" value="<?php echo esc_attr( $settings['discord_webhook_url'] ); ?>" placeholder="<?php echo esc_attr( '' !== trim( (string) $settings['discord_webhook_url'] ) ? __( '•••• salvata — compila per sostituire', 'nexus-news-ai' ) : '' ); ?>" class="regular-text code" autocomplete="new-password" />
                                                                <p class="description"><?php esc_html_e( 'URL del webhook del canale Discord (discord.com/api/webhooks/…, mascherato).', 'nexus-news-ai' ); ?></p>
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
                                                                <input type="password" name="x_api_key" id="nxa-x-api-key" value="<?php echo esc_attr( $settings['x_api_key'] ); ?>" placeholder="<?php echo esc_attr( '' !== trim( (string) $settings['x_api_key'] ) ? __( '•••• salvata — compila per sostituire', 'nexus-news-ai' ) : '' ); ?>" class="regular-text code" autocomplete="new-password" />
                                                                <p class="description"><?php esc_html_e( 'API key dell’app dal Developer Portal di X (mascherata).', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-x-api-secret"><?php esc_html_e( 'API secret X', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="password" name="x_api_secret" id="nxa-x-api-secret" value="<?php echo esc_attr( $settings['x_api_secret'] ); ?>" placeholder="<?php echo esc_attr( '' !== trim( (string) $settings['x_api_secret'] ) ? __( '•••• salvata — compila per sostituire', 'nexus-news-ai' ) : '' ); ?>" class="regular-text code" autocomplete="new-password" />
                                                                <p class="description"><?php esc_html_e( 'API secret dell’app, consumer secret (mascherato).', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-x-access-token"><?php esc_html_e( 'Access token X', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="password" name="x_access_token" id="nxa-x-access-token" value="<?php echo esc_attr( $settings['x_access_token'] ); ?>" placeholder="<?php echo esc_attr( '' !== trim( (string) $settings['x_access_token'] ) ? __( '•••• salvata — compila per sostituire', 'nexus-news-ai' ) : '' ); ?>" class="regular-text code" autocomplete="new-password" />
                                                                <p class="description"><?php esc_html_e( 'Access token OAuth 1.0a dell’account che pubblica (mascherato).', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-x-access-secret"><?php esc_html_e( 'Access secret X', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="password" name="x_access_secret" id="nxa-x-access-secret" value="<?php echo esc_attr( $settings['x_access_secret'] ); ?>" placeholder="<?php echo esc_attr( '' !== trim( (string) $settings['x_access_secret'] ) ? __( '•••• salvata — compila per sostituire', 'nexus-news-ai' ) : '' ); ?>" class="regular-text code" autocomplete="new-password" />
                                                                <p class="description"><?php esc_html_e( 'Access token secret OAuth 1.0a dell’account (mascherato).', 'nexus-news-ai' ); ?></p>
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
                                                                <input type="password" name="linkedin_access_token" id="nxa-linkedin-access-token" value="<?php echo esc_attr( $settings['linkedin_access_token'] ); ?>" placeholder="<?php echo esc_attr( '' !== trim( (string) $settings['linkedin_access_token'] ) ? __( '•••• salvata — compila per sostituire', 'nexus-news-ai' ) : '' ); ?>" class="regular-text code" autocomplete="new-password" />
                                                                <p class="description"><?php esc_html_e( 'Access token OAuth 2.0 con permesso w_member_social (mascherato).', 'nexus-news-ai' ); ?></p>
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

                <section class="nxa-acc" data-nxa-acc="news-sitemap">
                        <button type="button" class="nxa-acc-toggle" aria-expanded="false" aria-controls="nxa-acc-panel-news-sitemap">
                                <span class="nxa-acc-label"><?php esc_html_e( 'Google News', 'nexus-news-ai' ); ?></span>
                                <span class="nxa-acc-chevron" aria-hidden="true"></span>
                        </button>
                        <div class="nxa-acc-panel" id="nxa-acc-panel-news-sitemap" hidden>
                                <?php /* Sezione v1.1.12 (S8) "Google News": sitemap dedicata (modulo S5), name = chiavi esatte default_settings v1.1.12. */ ?>
                                <table class="form-table" role="presentation">
                                        <tbody>
                                                <tr>
                                                        <th scope="row"><?php esc_html_e( 'Sitemap Google News', 'nexus-news-ai' ); ?></th>
                                                        <td>
                                                                <label for="nxa-news-sitemap-enabled">
                                                                        <input type="checkbox" name="news_sitemap_enabled" id="nxa-news-sitemap-enabled" value="1" <?php checked( (int) $settings['news_sitemap_enabled'], 1 ); ?> />
                                                                        <?php esc_html_e( 'Attiva la sitemap Google News', 'nexus-news-ai' ); ?>
                                                                </label>
                                                                <p class="description"><?php esc_html_e( 'Se attivo, viene generata la sitemap nexus-news-sitemap.xml con gli articoli pubblicati nelle ultime 48 ore.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-news-sitemap-name"><?php esc_html_e( 'Nome della testata', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="text" name="news_sitemap_name" id="nxa-news-sitemap-name" value="<?php echo esc_attr( $settings['news_sitemap_name'] ); ?>" class="regular-text code" />
                                                                <p class="description"><?php esc_html_e( 'Nome usato nel tag <news:name> della sitemap Google News; vuoto = nome del sito.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                        </tbody>
                                </table>
                        </div>
                </section>

                <section class="nxa-acc" data-nxa-acc="notify">
                        <button type="button" class="nxa-acc-toggle" aria-expanded="false" aria-controls="nxa-acc-panel-notify">
                                <span class="nxa-acc-label"><?php esc_html_e( 'Notifiche', 'nexus-news-ai' ); ?></span>
                                <span class="nxa-acc-chevron" aria-hidden="true"></span>
                        </button>
                        <div class="nxa-acc-panel" id="nxa-acc-panel-notify" hidden>
                                <?php /* Sezione v1.1.12 (S8) "Notifiche": webhook Slack-compatibile (modulo S7), name = chiavi esatte default_settings v1.1.12. */ ?>
                                <table class="form-table" role="presentation">
                                        <tbody>
                                                <tr>
                                                        <th scope="row"><label for="nxa-notify-webhook-url"><?php esc_html_e( 'Webhook notifiche', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="password" name="notify_webhook_url" id="nxa-notify-webhook-url" value="<?php echo esc_attr( $settings['notify_webhook_url'] ); ?>" placeholder="<?php echo esc_attr( '' !== trim( (string) $settings['notify_webhook_url'] ) ? __( '•••• salvata — compila per sostituire', 'nexus-news-ai' ) : '' ); ?>" class="regular-text code" autocomplete="new-password" />
                                                                <p class="description"><?php esc_html_e( 'Webhook Slack-compatibile che riceve gli eventi della pipeline (errori, pending, pubblicazioni).', 'nexus-news-ai' ); ?></p>
                                                                <p class="description"><?php esc_html_e( 'Campo mascherato: compila il campo solo per sostituire il webhook salvato (vuoto = nessun cambio).', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-notify-events"><?php esc_html_e( 'Eventi notificati', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <input type="text" name="notify_events" id="nxa-notify-events" value="<?php echo esc_attr( $settings['notify_events'] ); ?>" placeholder="error,pending,published" class="regular-text code" />
                                                                <p class="description"><?php esc_html_e( 'Elenco separato da virgola degli eventi da notificare. Valori ammessi: error, pending, published.', 'nexus-news-ai' ); ?></p>
                                                                <p class="description"><?php esc_html_e( 'Nota: se Telegram è configurato nella sezione Distribuzione social, le notifiche arrivano anche in chat.', 'nexus-news-ai' ); ?></p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="nxa-notify-telegram-chat"><?php esc_html_e( 'Chat Telegram ops', 'nexus-news-ai' ); ?></label></th>
                                                        <td>
                                                                <?php /* v1.1.13 (O3): chat ops per le notifiche interne della pipeline (contratto opzioni 1.1.13). NON è un segreto: testo normale, il vuoto la svuota. */ ?>
                                                                <input type="text" name="nexus_notify_telegram_chat" id="nxa-notify-telegram-chat" value="<?php echo esc_attr( $nxa_notify_chat ); ?>" placeholder="@ops o -1001234567890" class="regular-text code" />
                                                                <p class="description"><?php esc_html_e( 'Chat Telegram ops per le notifiche interne (vuoto = canale social attuale).', 'nexus-news-ai' ); ?></p>
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
