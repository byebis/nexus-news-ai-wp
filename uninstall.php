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
 * Dalla v1.1.15 (WF1): la disinstallazione è MULTISITE-AWARE. Su una rete
 * WordPress la pulizia per-blog (post CPT + meta, eventi cron, registro
 * ruoli, tabelle col prefisso del sito, opzioni, transients) gira su TUTTI
 * i siti della rete: get_sites() con number => 0 (nessun limite implicito)
 * e fields => 'ids', un switch_to_blog per sito con restore_current_blog
 * SEMPRE eseguito (anche in caso di eccezione, via try/catch). Le tabelle
 * utenti CONDIVISE (utenti bot degli agenti, user meta redazione) vengono
 * invece pulite UNA sola volta fuori dal loop. Il ramo single-site mantiene
 * il comportamento storico passando per la STESSA funzione per-blog
 * riusabile nexus_uninstall_clean_current_blog(). Nota multisite: il
 * registro ruoli è per-sito (option {prefix}user_roles), quindi il ruolo
 * "Giornalista AI" viene rimosso DENTRO la pulizia per-blog; le famiglie
 * transient nexus_ai_models_* e nexus_oauth_state_* (ex nexus_uninstall_
 * delete_bot_users) sono passate a nexus_uninstall_delete_transients() che
 * ora gira per-blog.
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
 * Rimuove gli utenti bot degli agenti (v1.1.0).
 *
 * Gli utenti bot sono identificati dal login canonico 'nexus_agent_{id}'
 * (Nexus_Native::ensure_bot_user): cancellazione diretta via $wpdb per non
 * dipendere da wp_delete_user (non sempre caricato in uninstall) e dalle
 * classi del plugin. Le tabelle users/usermeta sono CONDIVISE su multisite:
 * questa funzione gira UNA sola volta, fuori dal loop per-blog (v1.1.15 WF1).
 * Il ruolo dedicato è rimosso da nexus_uninstall_remove_role() (per-blog,
 * vedi nota multisite nel docblock di testa) e le famiglie transient
 * nexus_ai_models_* / nexus_oauth_state_* da nexus_uninstall_delete_transients().
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
}

/**
 * Rimuove il ruolo dedicato "Giornalista AI" (v1.1.0).
 *
 * v1.1.15 (WF1): estratto da nexus_uninstall_delete_bot_users() perché su
 * multisite il registro ruoli NON è network-wide: vive nell'option
 * {prefix}user_roles del singolo sito, quindi remove_role va eseguito
 * DENTRO la pulizia per-blog (dopo switch_to_blog), sito per sito.
 *
 * @return void
 */
function nexus_uninstall_remove_role() {
        if ( function_exists( 'remove_role' ) ) {
                remove_role( 'nexus_ai_journalist' );
        }
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
 * token Reddit) vengono rimossi esplicitamente. Dalla v1.1.15 (WF1) entrano
 * anche nexus_ai_models_ e nexus_oauth_state_ (cache modelli AI e stati
 * OAuth one-shot, prima puliti da nexus_uninstall_delete_bot_users()): sono
 * option del blog corrente, quindi vanno pulite per-blog su multisite.
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
                'nexus_ai_models_',  // v1.1.15 (WF1) — cache modelli AI (ex bot users).
                'nexus_oauth_state_', // v1.1.15 (WF1) — stati OAuth one-shot (WP10).
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
 * La tabella usermeta è CONDIVISA su multisite: una sola passata, fuori
 * dal loop per-blog (v1.1.15 WF1).
 *
 * @return void
 */
function nexus_uninstall_delete_user_meta() {
        global $wpdb;
        $wpdb->delete( $wpdb->usermeta, array( 'meta_key' => 'nexus_redazione_id' ), array( '%s' ) );
}

/**
 * Drop delle tabelle wp_nexus_* del sito corrente.
 *
 * Percorso preferenziale: Nexus_DB::drop_tables() (fa il drop di tutte le
 * tabelle di Nexus_DB::tables() col prefisso del blog corrente — dopo
 * switch_to_blog $wpdb->prefix è quello del sito selezionato — e cancella
 * le opzioni DB runtime). Fallback: drop diretto via $wpdb se la classe non
 * è caricabile.
 *
 * @return void
 */
function nexus_uninstall_drop_plugin_tables() {
        if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'drop_tables' ) ) {
                Nexus_DB::drop_tables();

                return;
        }

        nexus_uninstall_drop_tables_direct();
}

/**
 * Pulizia COMPLETA del blog corrente (per-blog, v1.1.15 WF1).
 *
 * Funzione riusabile da ENTRAMBI i rami: multisite (una chiamata per sito,
 * dentro switch_to_blog/restore_current_blog) e single-site (una sola
 * chiamata, comportamento storico). Copre tutto ciò che vive nel blog
 * corrente: post CPT + meta, eventi cron (la option cron è per-sito),
 * registro ruoli (option {prefix}user_roles, per-sito), tabelle wp_nexus_*
 * col prefisso corrente, opzioni e transients. Le tabelle utenti condivise
 * (utenti bot, user meta redazione) NON sono qui: una sola passata dopo il
 * loop, vedi nexus_uninstall_delete_bot_users()/delete_user_meta().
 *
 * @return void
 */
function nexus_uninstall_clean_current_blog() {
        nexus_uninstall_delete_wp_posts();
        nexus_uninstall_clear_cron_events();
        nexus_uninstall_remove_role();
        nexus_uninstall_drop_plugin_tables();
        nexus_uninstall_delete_options();
        nexus_uninstall_delete_transients();
}

/* ---------------- 0) Carica Nexus_DB se possibile (una sola volta) ---------------- */

// Il percorso Nexus_DB è preferenziale (drop_tables + opzioni DB runtime);
// se il file non è raggiungibile si passa alle query DROP dirette dentro
// nexus_uninstall_drop_plugin_tables().
$candidates = array();

if ( defined( 'WP_PLUGIN_DIR' ) ) {
        $candidates[] = WP_PLUGIN_DIR . '/' . dirname( WP_UNINSTALL_PLUGIN ) . '/includes/class-nexus-db.php';
}
// Il file uninstall.php vive nella root del plugin: __DIR__ è il path più affidabile.
$candidates[] = __DIR__ . '/includes/class-nexus-db.php';

foreach ( array_unique( $candidates ) as $db_class_file ) {
        if ( file_exists( $db_class_file ) ) {
                require_once $db_class_file;
                break;
        }
}

/* ---------------- 1) Pulizia per-blog (multisite: TUTTI i siti della rete) ---------------- */

if ( is_multisite() ) {
        // v1.1.15 (WF1): su multisite la pulizia per-blog gira su TUTTI i siti.
        // get_sites() con number => 0 = NESSUN limite implicito (il default di
        // get_sites è 100 siti); fields => 'ids' evita di istanziare WP_Site.
        // switch_to_blog/restore_current_blog SEMPRE in coppia: il restore è
        // fuori dal try/catch, quindi avviene anche a pulizia fallita.
        $site_ids = get_sites( array( 'number' => 0, 'fields' => 'ids' ) );

        foreach ( (array) $site_ids as $site_id ) {
                $site_id = (int) $site_id;

                if ( $site_id < 1 ) {
                        continue;
                }

                $switched = switch_to_blog( $site_id );

                if ( ! $switched ) {
                        continue;
                }

                try {
                        nexus_uninstall_clean_current_blog();
                } catch ( Exception $e ) {
                        // Pulizia best-effort per sito: un errore su un blog non
                        // blocca gli altri né lascia lo switch pendente.
                } catch ( Throwable $e ) {
                        // Idem per errori PHP 7+.
                }

                restore_current_blog();
        }
} else {
        /* ---------------- Ramo single-site: stesso percorso per-blog ---------------- */

        nexus_uninstall_clean_current_blog();
}

/* ---------------- 2) Tabelle utenti CONDIVISE (una sola passata, fuori dal loop) ---------------- */

// Su multisite users/usermeta sono network-wide: utenti bot e user meta
// redazione vengono rimossi UNA sola volta, qui (v1.1.15 WF1 — prima la
// pulizia toccava solo il blog corrente lasciando post/tabelle/opzioni
// orfane su tutti gli altri siti della rete).
nexus_uninstall_delete_bot_users();
nexus_uninstall_delete_user_meta();
