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
 * - Le credenziali social (v1.0.2) sono input type="text" (scelta consapevole,
 *   coerente con il resto della pagina) con autocomplete="new-password";
 *   anche il loro value è stampato con esc_attr.
 * - indexnow_key è readonly: la chiave è generata automaticamente alla prima
 *   pubblicazione (Nexus_Ping) e non è modificabile da schermo.
 * - Bottone SALVA IN CIMA (subito sotto l'h1) OLTRE quello in fondo: entrambi
 *   type="submit" nella stessa form; admin.js v1.1.6 mostra lo stato di
 *   caricamento su tutti i submit della form.
 * - Sezioni accordion section.nxa-acc[data-nxa-acc] con
 *   button.nxa-acc-toggle[aria-expanded][aria-controls], span.nxa-acc-label,
 *   span.nxa-acc-chevron, div.nxa-acc-panel[hidden]. Lo stato dei gruppi
 *   (array slug aperti) è persistito in
 *   localStorage['nxa-settings-acc-pubblicazione'] (chiave propria della
 *   pagina, JSON con try/catch) dall'IIFE ES5 in fondo al file (solo toggle
 *   accordion, nessun fetch); default senza localStorage: pannello "wp-seo"
 *   aperto, gli altri chiusi (stato renderizzato anche server-side, così
 *   senza JS la prima sezione resta leggibile). Le classi accordion sono
 *   definite nel CSS condiviso: qui solo il micro-stile della barra Salva
 *   in cima.
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
</style>
<script>
( function () {
        'use strict';

        var STORAGE_KEY = 'nxa-settings-acc-pubblicazione';
        var DEFAULT_OPEN = 'wp-seo';

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
