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
 * - Gli 8 toggle (auto_collect, auto_evaluate, auto_rewrite, auto_publish,
 *   enable_wp_posts, enable_ai_images, enable_seo, seo_generate) sono
 *   checkbox value="1": admin.js li serializza come 0/1 (0 quando deselezionati).
 * - Le 5 piattaforme social sono checkbox name="social_platforms[]" con
 *   value = blog|twitter|facebook|linkedin|instagram: admin.js serializza le
 *   voci selezionate in stringa CSV nel campo social_platforms.
 * - Tutti gli altri campi usano name = chiave esatta di Nexus_DB::default_settings():
 *   mode, collect_interval, max_articles_per_day, auto_approve_score,
 *   site_name, site_tagline, ai_base_url, ai_model, ai_api_key,
 *   post_status, image_model, image_size.
 * - ai_api_key: input type="password" (mai mostrata in chiaro a schermo);
 *   il value è stampato con esc_attr.
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

$settings         = Nexus_DB::get_settings();
$active_platforms = array_filter( array_map( 'trim', explode( ',', (string) $settings['social_platforms'] ) ) );

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
                <h2 class="title"><?php esc_html_e( 'Pipeline editoriale', 'nexus-news-ai' ); ?></h2>
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

                <h2 class="title"><?php esc_html_e( 'Limiti e qualità', 'nexus-news-ai' ); ?></h2>
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

                <h2 class="title"><?php esc_html_e( 'Pubblicazione social', 'nexus-news-ai' ); ?></h2>
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

                <h2 class="title"><?php esc_html_e( 'Identità del sito', 'nexus-news-ai' ); ?></h2>
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

                <h2 class="title"><?php esc_html_e( 'Configurazione AI', 'nexus-news-ai' ); ?></h2>
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
                                                <input type="text" name="ai_model" id="nxa-ai-model" value="<?php echo esc_attr( $settings['ai_model'] ); ?>" class="regular-text code" />
                                                <p class="description"><?php esc_html_e( "Identificativo del modello (es. openai/gpt-4o-mini).", 'nexus-news-ai' ); ?></p>
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

                <h2 class="title"><?php esc_html_e( 'Pubblicazione WordPress & SEO', 'nexus-news-ai' ); ?></h2>
                <table class="form-table" role="presentation">
                        <tbody>
                                <tr>
                                        <th scope="row"><?php esc_html_e( 'Pubblicazione WordPress', 'nexus-news-ai' ); ?></th>
                                        <td>
                                                <label for="nxa-enable-wp-posts">
                                                        <input type="checkbox" name="enable_wp_posts" id="nxa-enable-wp-posts" value="1" <?php checked( (int) $settings['enable_wp_posts'], 1 ); ?> />
                                                        <?php esc_html_e( 'Pubblica come post WordPress reali (CPT nexus_article)', 'nexus-news-ai' ); ?>
                                                </label>
                                                <p class="description"><?php esc_html_e( 'Se attivo, gli articoli pubblicati diventano post reali del CPT "nexus_article"; se disattivo resta il front-end legacy di Nexus.', 'nexus-news-ai' ); ?></p>
                                        </td>
                                </tr>
                                <tr>
                                        <th scope="row"><label for="nxa-post-status"><?php esc_html_e( 'Stato dei post creati', 'nexus-news-ai' ); ?></label></th>
                                        <td>
                                                <select name="post_status" id="nxa-post-status">
                                                        <option value="publish" <?php selected( $settings['post_status'], 'publish' ); ?>><?php esc_html_e( 'Pubblicati subito', 'nexus-news-ai' ); ?></option>
                                                        <option value="draft" <?php selected( $settings['post_status'], 'draft' ); ?>><?php esc_html_e( 'Salvati come bozze', 'nexus-news-ai' ); ?></option>
                                                </select>
                                                <p class="description"><?php esc_html_e( 'Stato WordPress assegnato ai post creati dal publisher (publish = pubblicati, draft = bozze da revisionare).', 'nexus-news-ai' ); ?></p>
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

                <p class="submit">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Salva impostazioni', 'nexus-news-ai' ); ?></button>
                </p>
        </form>
</div>
