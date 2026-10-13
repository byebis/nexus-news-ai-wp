<?php
/**
 * Nexus News AI — Disinstallazione.
 *
 * Eseguito SOLO quando il plugin viene cancellato dalla pagina Plugin di
 * WordPress (WP_UNINSTALL_PLUGIN definito). La semplice *disattivazione*
 * NON passa da qui: tabelle, articoli e impostazioni restano al loro posto.
 *
 * Attenzione: uninstall.php gira senza il bootstrap del plugin (nessun
 * plugins_loaded), quindi NEXUS_PLUGIN_DIR non esiste. Il path della classe
 * Nexus_DB viene calcolato dal basename del plugin (WP_UNINSTALL_PLUGIN)
 * con fallback robusto su __DIR__; se la classe non è caricabile si passa
 * alle query DROP TABLE dirette via $wpdb.
 *
 * Dalla v1.0.1 rimuove anche i post WordPress reali del CPT nexus_article
 * (wp_delete_post con force_delete, che elimina post e meta associati) e i
 * meta Nexus residui sui post (_nexus_article_id, _nexus_seo_title,
 * _nexus_seo_description) via delete_post_meta_by_key.
 *
 * Dalla v1.0.2 ripulisce anche la newsletter e i ping: drop esplicito della
 * tabella wp_nexus_subscribers (il percorso Nexus_DB::drop_tables() la include
 * già, il fallback diretto qui sotto la aggiunge) e cancellazione degli eventi
 * cron del plugin (nexus_weekly_digest, nexus_ping_article). Nessuna nuova
 * delete_option: le 19 chiavi settings v1.0.2 vivono in nexus_settings,
 * già rimossa.
 *
 * Dalla v1.0.3 il drop comprende anche le due tabelle della "redazione
 * intelligente": wp_nexus_usage_logs (tracking uso AI: token e costo stimato)
 * e wp_nexus_translations (traduzioni AI degli articoli).
 *
 * Dalla v1.1.0 "Tutto nativo": rimuove anche gli UTENTI BOT degli agenti
 * (user_login 'nexus_agent_{id}', ruolo nexus_ai_journalist) con le loro
 * capabilities, il ruolo "Giornalista AI" e l'opzione di stato migrazione
 * nexus_native_migration + cache transients dei modelli (nexus_ai_models_*).
 * I post NATIVI 'post' generati dal plugin NON vengono cancellati: dalla
 * v1.1.0 sono articoli a tutti gli effetti del sito (autore bot, categorie
 * native) — disinstallare il plugin non deve cancellare la testata.
 *
 * Dalla v1.1.1 "Redazioni gerarchiche": rimuove lo user meta
 * nexus_redazione_id (assegnazione utente→redazione). I TERMINI della
 * tassonomia nexus_redazione (redazioni e sub-redazioni) e il loro term
 * meta _nexus_agents NON vengono cancellati: sono struttura WP nativa,
 * come categorie e tag — sopravvivono al plugin esattamente come i post.
 * Reinstallare il plugin ritrova le redazioni al loro posto.
 *
 * Dalla v1.1.10.1 la pulizia dei transients è COMPLETA (audit 1.1.10): tutte
 * le famiglie transient del plugin (feed, fact-check fonti, rate limit,
 * full-content, lock traduzioni, OAuth, circuit breaker provider) e i
 * transients singoli nominati vengono rimossi alla cancellazione — vedi
 * nexus_uninstall_delete_transients(). Dalla v1.1.13 entra anche la famiglia
 * rate limit delle notifiche (nexus_notify_rl_, v1.1.12 S7).
 *
 * Dalla v1.1.13 (O2): completamento moduli 1.1.12 (token OAuth Google
 * nexus_gsc_token, briefing learning nexus_learn_briefing, eventi cron
 * metrics/learn/evergreen non ancora cancellati, tabella nexus_metrics nel
 * fallback DROP, meta _nexus_simhash/_nexus_interlinked/_nexus_interlink_ids/
 * _nexus_evergreen_at) + opzioni dei NUOVI moduli 1.1.13 (dedup window/
 * lookup, interlink window/candidates, evergreen max per run, notify max
 * per hour/telegram chat).
 *
 * @package Nexus_News_AI
 */

// Esci se non siamo in un contesto di disinstallazione valido
// (WP_UNINSTALL_PLUGIN definito e coincidente col basename di questo plugin).
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || 'nexus-news-ai/nexus-news-ai.php' !== WP_UNINSTALL_PLUGIN ) {
        exit;
}

/**
 * Elimina le opzioni del plugin.
 *
 * @return void
 */
function nexus_uninstall_delete_options() {
        delete_option( 'nexus_settings' );
        delete_option( 'nexus_db_version' );
        delete_option( 'nexus_wire_runs' );
        delete_option( 'nexus_native_migration' ); // v1.1.0 — stato migrazione nativa
        // v1.1.10 — provider custom illimitati (WP11) e cursore fair-share fonti (WP2).
        delete_option( 'nexus_custom_providers' );
        delete_option( 'nexus_source_cursor' );
        // v1.1.10 — flag log-once imagery (C2).
        delete_option( 'nexus_pixabay_key_missing' );
        // v1.1.12 — moduli "redazione che impara": token OAuth Google CIFRATO (S1)
        // e briefing performance (S2b). Il token è un segreto: DEVE sparire alla
        // disinstallazione (già assente dal percorso Nexus_DB::drop_tables, qui
        // coperto anche nel fallback diretto, insieme alle chiavi DB runtime).
        delete_option( 'nexus_gsc_token' );
        delete_option( 'nexus_learn_briefing' );
        delete_option( 'nexus_db_revision' );   // revisione schema (parità col percorso drop_tables)
        delete_option( 'nexus_agent_runtime' ); // runtime agenti (parità col percorso drop_tables)
        // v1.1.13 — opzioni dei nuovi moduli (contratto O2/uninstall): nessuna
        // vive dentro nexus_settings, sono chiavi dedicate scritte dalle viste
        // / REST 1.1.13.
        delete_option( 'nexus_dedup_window_days' );
        delete_option( 'nexus_dedup_lookup_max' );
        delete_option( 'nexus_interlink_window_days' );
        delete_option( 'nexus_interlink_candidates' );
        delete_option( 'nexus_evergreen_max_per_run' );
        delete_option( 'nexus_notify_max_per_hour' );
        delete_option( 'nexus_notify_telegram_chat' );
}

/**
 * Rimuove gli utenti bot degli agenti e il ruolo "Giornalista AI" (v1.1.0).
 *
 * Gli utenti bot sono identificati dal login canonico 'nexus_agent_{id}'
 * (Nexus_Native::ensure_bot_user): cancellazione diretta via $wpdb per non
 * dipendere da wp_delete_user (non sempre caricato in uninstall) e dalle
 * classi del plugin. Vengono rimossi anche i transients di cache modelli.
 *
 * @return void
 */
function nexus_uninstall_delete_bot_users() {
        global $wpdb;

        // Utenti bot: solo i login canonici del plugin, mai altri utenti del sito.
        $bot_ids = $wpdb->get_col(
                $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_login LIKE %s", 'nexus_agent\_%' )
        );

        if ( ! empty( $bot_ids ) ) {
                foreach ( $bot_ids as $bot_id ) {
                        $bot_id = (int) $bot_id;
                        $wpdb->delete( $wpdb->usermeta, array( 'user_id' => $bot_id ), array( '%d' ) );
                        $wpdb->delete( $wpdb->users, array( 'ID' => $bot_id ), array( '%d' ) );
                }
        }

        // Ruolo dedicato (se il registro ruoli è disponibile).
        if ( function_exists( 'remove_role' ) ) {
                remove_role( 'nexus_ai_journalist' );
        }

        // Transients cache modelli AI (v1.0.7) + voci di scadenza.
        $like_transient = $wpdb->esc_like( '_transient_nexus_ai_models_' ) . '%';
        $like_timeout   = $wpdb->esc_like( '_transient_timeout_nexus_ai_models_' ) . '%';
        // v1.1.10: stati OAuth one-shot (WP10) + cache modelli per-provider (WP12) +
        // stato cursori/cooldown fonti (WP2) — stesso pattern di prefisso.
        $like_transient2 = $wpdb->esc_like( '_transient_nexus_oauth_state_' ) . '%';
        $like_timeout2   = $wpdb->esc_like( '_transient_timeout_nexus_oauth_state_' ) . '%';
        $wpdb->query(
                $wpdb->prepare(
                        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
                        $like_transient,
                        $like_timeout,
                        $like_transient2,
                        $like_timeout2
                )
        );
}

/**
 * Elimina i transients di cache RSS (nexus_rss_*) e le voci di scadenza.
 *
 * Dalla v1.1.10.1 la pulizia copre TUTTE le famiglie transient del plugin
 * (censimento audit 1.1.10): nexus_rss_ (cache feed), nexus_src_ (cache
 * fact-check fonti, include le sottofamiglie nexus_src_cool_ e
 * nexus_src_fail_), nexus_rl_ (rate limit REST), nexus_full_ (cache
 * full-content Readability), nexus_tr_lock_ (lock traduzioni),
 * nexus_oauth_rl_ (rate limit OAuth) e nexus_ai_breaker_ (circuit breaker
 * provider, chiave + suffisso _cooldown). Tutte hanno TTL: qui vengono
 * rimosse subito per lasciare l'installazione davvero pulita. I singoli
 * transients nominati (prezzi AI, conteggio CPT legacy, featured viste,
 * token Reddit) vengono rimossi esplicitamente. nexus_ai_models_* e
 * nexus_oauth_state_* sono già puliti da nexus_uninstall_delete_bot_users().
 *
 * @return void
 */
function nexus_uninstall_delete_transients() {
        global $wpdb;

        // Famiglie transient con prefisso (anche dinamiche): ciascuna viene
        // rimossa insieme alle proprie voci di scadenza (_transient_timeout_*).
        $prefixes = array(
                'nexus_rss_',        // v1.1.x — cache feed RSS (collect).
                'nexus_src_',        // v1.1.9/10 — cache fact-check + cooldown/fail fonti.
                'nexus_rl_',         // v1.1.9 — rate limit REST.
                'nexus_full_',       // v1.1.10 — cache full-content Readability.
                'nexus_tr_lock_',    // v1.1.9 — lock traduzioni multilang.
                'nexus_oauth_rl_',   // v1.1.10 — rate limit OAuth OpenRouter.
                'nexus_ai_breaker_', // v1.1.9 — circuit breaker provider (+ _cooldown).
                'nexus_notify_rl_',  // v1.1.12 (S7) — rate limit orario notifiche.
        );

        foreach ( $prefixes as $prefix ) {
                $like_transient = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
                $like_timeout   = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';
                $wpdb->query(
                        $wpdb->prepare(
                                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                                $like_transient,
                                $like_timeout
                        )
                );
        }

        // Transients singoli nominati (fuori da qualunque famiglia).
        delete_transient( 'nexus_ai_live_prices' );
        delete_transient( 'nexus_cpt_legacy_count' );
        delete_transient( 'nexus_pixabay_seen' );
        delete_transient( 'nexus_reddit_token' );
}

/**
 * Elimina tutti i post WordPress reali del CPT nexus_article (v1.0.1)
 * e i meta Nexus correlati.
 *
 * wp_delete_post() con force_delete=true bypassa il cestino e rimuove
 * il post insieme ai suoi meta. delete_post_meta_by_key() viene poi
 * eseguita comunque: è sicura anche a post già eliminati (meta orfani).
 *
 * @return void
 */
function nexus_uninstall_delete_wp_posts() {
        global $wpdb;

        // Tutti i post del CPT nexus_article: cancellazione definitiva.
        $post_ids = $wpdb->get_col(
                $wpdb->prepare(
                        "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
                        'nexus_article'
                )
        );

        if ( ! empty( $post_ids ) ) {
                foreach ( $post_ids as $post_id ) {
                        wp_delete_post( (int) $post_id, true ); // force_delete: bypassa il cestino.
                }
        }

        // Meta Nexus sui post (residui o orfani): pulizia per chiave.
        delete_post_meta_by_key( '_nexus_article_id' );
        delete_post_meta_by_key( '_nexus_seo_title' );
        delete_post_meta_by_key( '_nexus_seo_description' );
        // v1.1.12 — meta dei moduli "redazione che impara" (simhash dedup S3,
        // interlink S4, evergreen refresh S6): orfani sicuri anche a post già
        // cancellati (delete_post_meta_by_key non richiede che il post esista).
        delete_post_meta_by_key( '_nexus_simhash' );
        delete_post_meta_by_key( '_nexus_interlinked' );
        delete_post_meta_by_key( '_nexus_interlink_ids' );
        delete_post_meta_by_key( '_nexus_evergreen_at' );
}

/**
 * Cancella gli eventi cron del plugin (v1.0.2).
 *
 * nexus_weekly_digest è l'evento ricorrente del digest settimanale della
 * newsletter; nexus_ping_article è schedulato come evento singolo con
 * l'ID del post come argomento: wp_clear_scheduled_hook rimuove TUTTI gli
 * eventi con quel hook, qualunque sia l'argomento.
 *
 * @return void
 */
function nexus_uninstall_clear_cron_events() {
        wp_clear_scheduled_hook( 'nexus_weekly_digest' );
        wp_clear_scheduled_hook( 'nexus_ping_article' );
        wp_clear_scheduled_hook( 'nexus_queue_runner' ); // v1.0.4 — runner coda lavori
        // v1.1.12 — cron dei moduli "redazione che impara" (non erano ancora
        // coperti qui): sync metriche GSC/GA4, briefing learning, evergreen.
        wp_clear_scheduled_hook( 'nexus_metrics_sync' );
        wp_clear_scheduled_hook( 'nexus_learn_refresh' );
        wp_clear_scheduled_hook( 'nexus_evergreen_refresh' );
        // v1.1.13 (O2) — cron giornaliero di retention/cleanup.
        wp_clear_scheduled_hook( 'nexus_retention_cleanup' );
}

/**
 * Drop diretto delle 11 tabelle wp_nexus_* (fallback se Nexus_DB non caricabile:
 * le 10 storiche + nexus_metrics della v1.1.12, schema v4).
 *
 * @return void
 */
function nexus_uninstall_drop_tables_direct() {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $tables = array(
                $prefix . 'nexus_agents',
                $prefix . 'nexus_articles',
                $prefix . 'nexus_publish_logs',
                $prefix . 'nexus_approval_logs',
                $prefix . 'nexus_activity_logs',
                $prefix . 'nexus_subscribers',
                $prefix . 'nexus_usage_logs',   // v1.0.3 — tracking uso AI
                $prefix . 'nexus_translations', // v1.0.3 — traduzioni AI degli articoli
                $prefix . 'nexus_jobs',         // v1.0.4 — coda lavori Scala & Pro
                $prefix . 'nexus_article_revisions', // v1.1.9 — snapshot capo redattore
                $prefix . 'nexus_metrics',      // v1.1.12 — metriche performance GSC/GA4 (schema v4)
        );
        foreach ( $tables as $table ) {
                $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
}

/**
 * Rimuove lo user meta nexus_redazione_id (v1.1.1).
 *
 * L'assegnazione utente→redazione (restrict per ramo) è legata al plugin:
 * disinstallando, ogni utente torna a pieni permessi secondo il proprio
 * ruolo WP. I termini redazione restano (struttura nativa, vedi header).
 *
 * @return void
 */
function nexus_uninstall_delete_user_meta() {
        global $wpdb;
        $wpdb->delete( $wpdb->usermeta, array( 'meta_key' => 'nexus_redazione_id' ), array( '%s' ) );
}

/* ---------------- 0) Post WordPress reali (CPT nexus_article, v1.0.1) ---------------- */

nexus_uninstall_delete_wp_posts();

/* ---------------- 0b) Utenti bot agenti + ruolo + cache modelli (v1.1.0) ---------------- */

nexus_uninstall_delete_bot_users();

/* ---------------- 0c) User meta redazione utente (v1.1.1) ---------------- */

nexus_uninstall_delete_user_meta();

/* ---------------- 1) Eventi cron (newsletter + ping, v1.0.2) ---------------- */

nexus_uninstall_clear_cron_events();

/* ---------------- 2) Try via Nexus_DB ---------------- */

$candidates = array();
$loaded     = false;

if ( defined( 'WP_PLUGIN_DIR' ) ) {
        $candidates[] = WP_PLUGIN_DIR . '/' . dirname( WP_UNINSTALL_PLUGIN ) . '/includes/class-nexus-db.php';
}
// Il file uninstall.php vive nella root del plugin: __DIR__ è il path più affidabile.
$candidates[] = __DIR__ . '/includes/class-nexus-db.php';

foreach ( array_unique( $candidates ) as $db_class_file ) {
        if ( file_exists( $db_class_file ) ) {
                require_once $db_class_file;
                $loaded = true;
                break;
        }
}

if ( $loaded && class_exists( 'Nexus_DB' ) ) {
        // Nexus_DB::drop_tables() fa il drop delle 11 tabelle wp_nexus_* (inclusa
        // nexus_subscribers dalla v1.0.2, nexus_usage_logs e nexus_translations
        // dalla v1.0.3, nexus_jobs dalla v1.0.4, nexus_metrics dalla v1.1.12) e
        // cancella le opzioni nexus_settings, nexus_db_version, nexus_wire_runs,
        // nexus_db_revision, nexus_agent_runtime e nexus_native_migration.
        Nexus_DB::drop_tables();

        // Le opzioni fuori da Nexus_DB e i transients non sono gestiti dalla
        // classe: li rimuoviamo qui.
        nexus_uninstall_delete_options();
        nexus_uninstall_delete_transients();
} else {
        /* ---------------- 3) Fallback: query dirette ---------------- */

        nexus_uninstall_drop_tables_direct();
        nexus_uninstall_delete_options();
        nexus_uninstall_delete_transients();
}
