<?php
/**
 * Nexus DB — layer dati: tabelle custom, CRUD, settings, seed agenti.
 *
 * CONTRATTO PUBBLICO (usato da tutti gli altri moduli):
 * Nexus_DB::install()                  — crea tabelle (dbDelta) + seed agenti
 * Nexus_DB::maybe_upgrade()            — upgrade schema se NEXUS_VERSION cambia
 * Nexus_DB::tables()                   — array nomi tabelle con prefisso wp
 * Nexus_DB::agents()                   — tutti gli agenti (array oggetti)
 * Nexus_DB::get_agent( $id )
 * Nexus_DB::update_agent( $id, $fields )
 * Nexus_DB::articles( $args )          — args: status, category, agent_id, search, page, per_page, orderby, order
 * Nexus_DB::count_articles( $args )    — totale senza paginazione
 * Nexus_DB::get_article( $id )
 * Nexus_DB::insert_article( $data )    — ritorna id int
 * Nexus_DB::update_article( $id, $fields )
 * Nexus_DB::delete_article( $id )
 * Nexus_DB::insert_activity( $agent_id, $action, $detail, $status )
 * Nexus_DB::activity( $args )          — args: agent_id, limit (default 20)
 * Nexus_DB::insert_publish_log( $article_id, $platform, $status, $post_id, $post_url, $error )
 * Nexus_DB::publish_logs( $article_id )
 * Nexus_DB::set_approval( $article_id, $action, $note )
 * Nexus_DB::get_approval( $article_id )
 * Nexus_DB::get_settings()             — array completo con default merged
 * Nexus_DB::update_settings( $fields ) — merge parziale + persist (option nexus_settings)
 * Nexus_DB::stats()                    — conteggi dashboard
 * Nexus_DB::bump_view( $id )           — incrementa views
 * Nexus_DB::add_reaction( $id, $emoji )
 * Nexus_DB::drop_tables()              — usato da uninstall.php
 * Nexus_DB::bump_job_failure()         — incremento atomico tentativi job (v1.1.9)
 * Nexus_DB::insert_article_revision()  — snapshot articolo pre-modifica (v1.1.9)
 *
 * v1.1.4 — NUOVE COLONNE (contratto T-02):
 *   nexus_agents:  ai_provider (provider AI dedicato dell'agente, '' = provider globale
 *                  dalle impostazioni), role ('giornalista' | 'capo_redattore'),
 *                  chief_scope ('' | 'global' | 'category' | 'term'), chief_term_id
 *                  (redazione assegnata al capo redattore, 0 = nessuna). ZERO indici
 *                  nuovi: cardinalità bassa, bastano le scansioni (le KEY esistenti
 *                  restano intatte per dbDelta).
 *   nexus_articles: chief_status ('' | 'visto' | 'modificato' | 'rifiutato' | 'revisione'),
 *                  chief_note (nota del capo redattore), chief_agent_id, chief_reviewed_at
 *                  (data revisione capo), chief_rounds (giri di revisione effettuati).
 *   Le colonne vengono aggiunte automaticamente da dbDelta() invocato da install() e
 *   maybe_upgrade() quando NEXUS_VERSION cambia (bump 1.1.4 riservato all'integratore).
 * v1.1.4 — NUOVE CHIAVI SETTINGS (contratto T-02, default_settings()):
 *   Provider per agente: prov_key_openrouter, prov_key_openai, prov_key_anthropic,
 *   prov_key_google, prov_key_groq, prov_key_deepseek, prov_key_mistral, prov_key_xai,
 *   prov_key_custom (chiavi API per provider) e prov_base_custom (base URL del provider
 *   "custom" compatibile OpenAI).
 *   Capo redattore: chief_enabled, chief_auto_publish (0 = human-in-the-loop con badge,
 *   1 = pubblicazione automatica se il capo approva), chief_max_rounds, chief_id
 *   (0 = automatico: primo capo redattore attivo).
 *   Strumenti di ricerca: tools_enabled, tools_search_provider ('tavily' | 'serper' |
 *   'brave' | 'none'), tools_search_key, tools_max_results, tools_read_page,
 *   tools_read_max_chars, tools_max_rounds, tools_timeout e mcp_servers_text
 *   (righe "nome|url|tokenOpzionale").
 *   update_settings() itera default_settings(): le chiavi nuove sono subito
 *   persistibili senza modifiche ulteriori (la whitelist REST è di T-05).
 * v1.1.11 (R1) — FONTI RSS GLOBALI ZERO-HARDCODE:
 *   'global_rss_sources' (STRINGA, default '', stesso pattern di
 *   mcp_servers_text): una URL http(s) per riga (righe vuote e che iniziano
 *   con '#' ignorate da Nexus_Rss::global_sources()). Sostituisce la vecchia
 *   mappa categoria→feed hardcoded di Nexus_Rss::sources_for_category:
 *   senza configurazione utente la raccolta RSS globale non produce item.
 *   La chiave NON è un segreto (nessun token dentro: solo URL feed) — resta
 *   leggibile in GET /settings e nell'export.
 * v1.1.6 — TIMESTAMP VERI + ZERO MOCK (contratto T-03):
 *   insert_activity(): QUINTO formato '%s' per created_at. Prima: 5 campi con
 *   4 soli formati → wpdb riciclava il primo formato ('%d') per l'ultimo campo
 *   → la stringa data veniva valutata come numero (2024) → DATETIME invalido →
 *   '0000-00-00 00:00:00' nel feed attività (screenshot di Anton).
 *   install(): pulizia one-time v1.1.6 delle righe danneggiate (cleanup_broken_rows):
 *   activity_logs con created_at '0000-00-00 00:00:00' (date non recuperabili)
 *   e publish_logs generati dal vecchio fallback simulato (post_id 'sim_%',
 *   status 'published' che non ha mai pubblicato nulla): conservarli sarebbe
 *   esporre dati falsi nel feed, nei log e in analytics.
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

class Nexus_DB {

        const OPTION_SETTINGS = 'nexus_settings';
        const DB_VERSION_KEY  = 'nexus_db_version';
        const DB_REVISION_KEY = 'nexus_db_revision';
        const DB_REVISION     = 4; // v1.1.12 — schema v4: nexus_metrics (feedback performance GSC/GA4)

        /**
         * Default settings (portati da Prisma Settings model).
         *
         * @return array
         */
        public static function default_settings() {
                return array(
                        'mode'               => 'semi_autonomous', // semi_autonomous | fully_autonomous
                        'auto_collect'       => 1,
                        'auto_evaluate'      => 1,
                        'auto_rewrite'       => 1,
                        'auto_publish'       => 0,
                        'collect_interval'   => 3600,
                        'max_articles_per_day' => 10,
                        // v1.1.6: solo canali con integrazione reale (niente facebook/instagram,
                        // che non avevano API e finivano nella pubblicazione simulata rimossa).
                        'social_platforms'   => 'blog,twitter,telegram,discord,linkedin',
                        'site_name'          => 'Nexus News AI',
                        'site_tagline'       => 'Il giornale di nuova generazione',
                        // Config AI (specifico plugin WP).
                        'ai_api_key'         => '',
                        'ai_base_url'        => 'https://openrouter.ai/api/v1',
                        'ai_model'           => 'openai/gpt-4o-mini',
                        // Qualità minima per auto-approvazione in modalità fully_autonomous.
                        'auto_approve_score' => 75,
                        // v1.0.1 — Post WordPress reali (CPT nexus_article).
                        'enable_wp_posts'    => 1, // 1 = pubblica post WP reali, 0 = solo front-end Nexus legacy
                        'post_status'        => 'publish', // publish | draft (stato del post WP creato)
                        // v1.0.1 — Immagini in evidenza.
                        'enable_ai_images'   => 1,
                        'image_model'        => '', // vuoto = default del provider
                        'image_size'         => '1024x1024',
                        // v1.0.1 — SEO.
                        'enable_seo'         => 1,
                        'seo_generate'       => 1, // genera meta title/description con l'AI alla pubblicazione
                        // v1.0.2 — Distribuzione reale: Telegram.
                        'enable_telegram'    => 0,
                        'telegram_bot_token' => '',
                        'telegram_chat_id'   => '',
                        // v1.0.2 — Discord.
                        'enable_discord'     => 0,
                        'discord_webhook_url' => '',
                        // v1.0.2 — X (Twitter) via OAuth 1.0a.
                        'enable_x'           => 0,
                        'x_api_key'          => '',
                        'x_api_secret'       => '',
                        'x_access_token'     => '',
                        'x_access_secret'    => '',
                        // v1.0.2 — LinkedIn.
                        'enable_linkedin'    => 0,
                        'linkedin_access_token' => '',
                        'linkedin_author_urn'   => '',
                        // v1.0.2 — Newsletter digest settimanale.
                        'enable_newsletter'  => 0,
                        'newsletter_recipients' => '', // email separate da virgola (invio fisso) — gli iscritti vengono sempre inclusi
                        'enable_digest'      => 1, // digest settimanale AI se newsletter attiva
                        // v1.0.2 — Ping di indicizzazione.
                        'enable_indexnow'    => 0,
                        'indexnow_key'       => '', // generata automaticamente se vuota all'attivazione
                        'enable_websub'      => 0,
                        // v1.0.3 — Redazione intelligente.
                        'ai_model_cheap'     => '', // modello economico per screening/valutazione (vuoto = ai_model)
                        'enable_factcheck'   => 1,
                        'factcheck_min_score' => 60, // sotto questa soglia l'articolo torna in bozza
                        'monthly_budget_usd' => 0, // 0 = nessun limite
                        'budget_action'      => 'stop', // stop | warn
                        'enable_translations' => 1,
                        'translation_langs'  => 'en,es,fr', // codici lingua separati da virgola
                        // v1.1.2 — Multilingua nativo: pubblica le traduzioni AI come post WP collegati.
                        'enable_autotranslate_posts' => 0,
                        // v1.0.4 — Scala & Pro: coda lavori (stile Action Scheduler).
                        'enable_queue'       => 0, // 1 = le azioni pesanti passano dalla coda wp_nexus_jobs
                        'queue_batch_size'   => 5, // lavori eseguiti per ogni runner tick
                        'queue_max_attempts' => 3, // tentativi massimi per lavoro prima di 'failed'
                        // v1.0.5 — Strumenti, API pubblica, bilingue.
                        'admin_language'     => 'auto', // auto | it | en (traduzione interfaccia admin)
                        'enable_public_api'  => 0, // 1 = REST pubblica nexus-public/v1 attiva
                        'public_api_key'     => '', // chiave generata dalla pagina Strumenti
                        // v1.0.6 — Tema visuale del pannello admin.
                        'admin_theme'        => 'light', // light | dark (chiaro di default dalla 1.0.6)
                        // v1.1.4 — Provider AI per agente: chiave API per ciascun provider
                        // (chiave vuota = provider non selezionabile; il provider globale
                        // "ai_api_key" sopra resta il fallback per gli agenti senza provider).
                        'prov_key_openrouter' => '',
                        'prov_key_openai'     => '',
                        'prov_key_anthropic'  => '',
                        'prov_key_google'     => '',
                        'prov_key_groq'       => '',
                        'prov_key_deepseek'   => '',
                        'prov_key_mistral'    => '',
                        'prov_key_xai'        => '',
                        'prov_key_custom'     => '',
                        'prov_key_ollama'     => '', // v1.1.10 (G2): opzionale anche per Ollama (server protetti)
                        'prov_base_custom'    => '', // base URL del provider "custom" (compatibile OpenAI)
                        // v1.1.10 — nuove chiavi globali del motore fonti multi-tipo e provider v2.
                        'youtube_api_key'     => '', // YouTube Data API v3 (gratuita, quota 10k unit/giorno)
                        'pixabay_key'         => '', // Pixabay per fallback featured image (gratuita)
                        'reddit_client_id'    => '', // Reddit OAuth app-only (opzionale: senza = RSS nativo)
                        'reddit_client_secret' => '', // Reddit OAuth secret (segreto)
                        'allow_private_providers' => 0, // 1 = consenti base_url AI su IP privati/localhost (LM Studio/vLLM su LAN); Ollama è sempre ammesso
                        // v1.1.4 — Capo redattore AI: legge gli articoli in pending_approval
                        // e approva/modifica/richiede revisione/rifiuta (Nexus_Chief, T-03).
                        'chief_enabled'       => 0, // 1 = revisione capo redattore attiva
                        'chief_auto_publish'  => 0, // 0 = human-in-the-loop (badge, approvi tu), 1 = pubblica in automatico se il capo approva
                        'chief_max_rounds'    => 2, // giri di revisione max prima di richiedere l'intervento umano
                        'chief_id'            => 0, // 0 = automatico: primo agente attivo con ruolo capo_redattore
                        // v1.1.4 — Strumenti di ricerca per gli agenti (tool-use loop, Nexus_Tools, T-04).
                        'tools_enabled'         => 0, // 1 = gli agenti possono usare gli strumenti nel loop
                        'tools_search_provider' => 'tavily', // tavily | serper | brave | none
                        'tools_search_key'      => '', // chiave API del motore di ricerca scelto
                        'tools_max_results'     => 5, // risultati max per ogni ricerca web
                        'tools_read_page'       => 1, // 1 = lettura pagine web attiva
                        'tools_read_max_chars'  => 6000, // caratteri max estratti per pagina
                        'tools_max_rounds'      => 3, // giri max del tool-use loop
                        'tools_timeout'         => 15, // timeout in secondi delle richieste HTTP degli strumenti
                        'mcp_servers_text'      => '', // righe "nome|url|tokenOpzionale" (server MCP Streamable HTTP)
                        // v1.1.11 (R1) — fonti RSS globali zero-hardcode: una URL
                        // http(s) per riga (stesso pattern multi-riga di
                        // mcp_servers_text); usate da Nexus_Rss::sources_for_category
                        // quando l'agente non ha fonti nella propria redazione. Vuota =
                        // nessuna raccolta RSS globale (nessun fallback predefinito).
                        'global_rss_sources'    => '',
                        // v1.1.11 (R5): guardia opzionale fetch catalogo modelli —
                        // 0 = GET /ai/models NON fa richieste remote ai provider
                        // (serve solo la cache esistente; anche refresh=1 è no-op).
                        'models_fetch_enabled' => 1,
                        // v1.1.12 (S0) — PACCHETTO FEEDBACK (S1/S2): credenziali Google
                        // OAuth (client "App web" dell'utente, come Site Kit), token
                        // salvati cifrati in opzioni dedicate (nexus_gsc_token),
                        // property Search Console (URL esatto della property) e GA4.
                        'gsc_oauth_client_id'     => '',
                        'gsc_oauth_client_secret' => '', // segreto: mascherato in GET /settings
                        'gsc_site_url'            => '', // es. https://example.com/ (property GSC)
                        'ga4_property_id'         => '', // es. 123456789 (solo cifre)
                        'metrics_sync_enabled'    => 1, // sync giornaliero GSC/GA4 via WP-Cron
                        // v1.1.12 (S0) — PACCHETTO SEO (S5): Google News sitemap.
                        'news_sitemap_enabled'    => 0,
                        'news_sitemap_name'       => '', // nome testata <news:name> (default: site_name)
                        // v1.1.12 (S0) — PACCHETTO DEDUP (S3): simhash 64-bit su
                        // 4-grammi; distanza Hamming <= soglia = contenuto simile.
                        'dedup_similar_enabled'   => 1,
                        'dedup_similar_threshold' => 6, // 0..24; 6 = conservativo
                        // v1.1.12 (S0) — PACCHETTO INTERLINK (S4): link interni
                        // automatici a post correlati dello stesso sito.
                        'interlink_enabled'       => 1,
                        'interlink_max'           => 3, // max link per articolo
                        // v1.1.12 (S0) — PACCHETTO LEARNING (S2): guida performance
                        // nel prompt del capo redattore / valutazione.
                        'learn_enabled'           => 1,
                        'learn_window_days'       => 30, // finestra aggregazione metriche
                        // v1.1.12 (S0) — PACCHETTO NOTIFICHE (S7): webhook generico
                        // (Slack-compatible) per eventi pipeline; Telegram riutilizza
                        // le credenziali enable_telegram/telegram_bot_token esistenti.
                        'notify_webhook_url'      => '', // segreto-like: mascherato in GET /settings
                        'notify_events'           => 'error,pending', // csv: error,pending,published
                        // v1.1.12 (S6) — PACCHETTO EVERGREEN (S6): refresh pillar dei migliori
                        // articoli pubblicati da più di evergreen_refresh_days giorni.
                        'evergreen_enabled'       => 0,
                        'evergreen_refresh_days'  => 30,
                        'evergreen_max_posts'     => 5,
                );
        }

        /**
         * Schema tabelle.
         *
         * @return array
         */
        public static function schema() {
                global $wpdb;
                $charset = $wpdb->get_charset_collate();
                $agents  = $wpdb->prefix . 'nexus_agents';
                $arts    = $wpdb->prefix . 'nexus_articles';
                $plogs   = $wpdb->prefix . 'nexus_publish_logs';
                $alogs   = $wpdb->prefix . 'nexus_approval_logs';
                $act     = $wpdb->prefix . 'nexus_activity_logs';
                $subs    = $wpdb->prefix . 'nexus_subscribers';
                $usage   = $wpdb->prefix . 'nexus_usage_logs';
                $transl  = $wpdb->prefix . 'nexus_translations';
                $jobs    = $wpdb->prefix . 'nexus_jobs';
                $revs    = $wpdb->prefix . 'nexus_article_revisions';
                $metrics = $wpdb->prefix . 'nexus_metrics'; // v1.1.12 schema v4

                return array(
                        "CREATE TABLE {$agents} (
                                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                                name VARCHAR(100) NOT NULL,
                                avatar VARCHAR(255) NOT NULL DEFAULT '',
                                category VARCHAR(100) NOT NULL,
                                description TEXT NULL,
                                status VARCHAR(20) NOT NULL DEFAULT 'active',
                                personality TEXT NULL,
                                ai_model VARCHAR(100) NOT NULL DEFAULT '',
                                ai_provider VARCHAR(50) NOT NULL DEFAULT '',
                                search_provider VARCHAR(30) NOT NULL DEFAULT '',
                                search_key_hash CHAR(64) NOT NULL DEFAULT '',
                                mcp_servers_text MEDIUMTEXT NULL,
                                role VARCHAR(20) NOT NULL DEFAULT 'giornalista',
                                chief_scope VARCHAR(20) NOT NULL DEFAULT '',
                                chief_term_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                                wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                                term_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                                last_run DATETIME NULL DEFAULT NULL,
                                created_at DATETIME NOT NULL,
                                updated_at DATETIME NOT NULL,
                                PRIMARY KEY  (id),
                                UNIQUE KEY category (category(100)),
                                KEY wp_user_id (wp_user_id),
                                KEY term_id (term_id)
                        ) {$charset};",
                        "CREATE TABLE {$arts} (
                                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                                title TEXT NOT NULL,
                                subtitle TEXT NULL,
                                content LONGTEXT NULL,
                                summary TEXT NULL,
                                category VARCHAR(100) NOT NULL DEFAULT '',
                                agent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                                source_name VARCHAR(255) NOT NULL DEFAULT '',
                                source_url TEXT NULL,
                                source_type VARCHAR(20) NOT NULL DEFAULT '',
                                image_url TEXT NULL,
                                wp_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                                content_hash CHAR(32) NULL DEFAULT NULL,
                                factcheck_score DECIMAL(5,2) NOT NULL DEFAULT 0,
                                citations LONGTEXT NULL,
                                status VARCHAR(30) NOT NULL DEFAULT 'draft',
                                chief_status VARCHAR(20) NOT NULL DEFAULT '',
                                chief_note TEXT NULL,
                                chief_agent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                                chief_reviewed_at DATETIME NULL DEFAULT NULL,
                                chief_rounds TINYINT UNSIGNED NOT NULL DEFAULT 0,
                                quality_score DECIMAL(5,2) NOT NULL DEFAULT 0,
                                read_time INT NOT NULL DEFAULT 3,
                                views INT UNSIGNED NOT NULL DEFAULT 0,
                                reactions LONGTEXT NULL,
                                published_at DATETIME NULL DEFAULT NULL,
                                created_at DATETIME NOT NULL,
                                updated_at DATETIME NOT NULL,
                                PRIMARY KEY  (id),
                                KEY status (status(30)),
                                KEY category (category(100)),
                                KEY agent_id (agent_id),
                                KEY wp_post_id (wp_post_id),
                                UNIQUE KEY content_hash (content_hash)
                        ) {$charset};",
                        "CREATE TABLE {$plogs} (
                                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                                article_id BIGINT UNSIGNED NOT NULL,
                                platform VARCHAR(50) NOT NULL,
                                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                                post_id VARCHAR(255) NOT NULL DEFAULT '',
                                post_url TEXT NULL,
                                error TEXT NULL,
                                published_at DATETIME NULL DEFAULT NULL,
                                created_at DATETIME NOT NULL,
                                PRIMARY KEY  (id),
                                KEY article_id (article_id)
                        ) {$charset};",
                        "CREATE TABLE {$alogs} (
                                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                                article_id BIGINT UNSIGNED NOT NULL,
                                reviewer_action VARCHAR(20) NOT NULL DEFAULT '',
                                reviewer_note TEXT NULL,
                                reviewed_at DATETIME NULL DEFAULT NULL,
                                created_at DATETIME NOT NULL,
                                PRIMARY KEY  (id),
                                KEY article_id (article_id)
                        ) {$charset};",
                        "CREATE TABLE {$act} (
                                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                                agent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                                action VARCHAR(50) NOT NULL DEFAULT '',
                                detail TEXT NULL,
                                status VARCHAR(20) NOT NULL DEFAULT 'info',
                                created_at DATETIME NOT NULL,
                                PRIMARY KEY  (id),
                                KEY agent_id (agent_id),
                                KEY created_at (created_at)
                        ) {$charset};",
                        "CREATE TABLE {$subs} (
                                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                                email VARCHAR(191) NOT NULL,
                                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                                token VARCHAR(64) NOT NULL DEFAULT '',
                                created_at DATETIME NOT NULL,
                                confirmed_at DATETIME NULL DEFAULT NULL,
                                PRIMARY KEY  (id),
                                UNIQUE KEY email (email),
                                KEY status (status(20)),
                                KEY token (token(64))
                        ) {$charset};",
                        "CREATE TABLE {$usage} (
                                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                                agent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                                action VARCHAR(50) NOT NULL DEFAULT '',
                                model VARCHAR(120) NOT NULL DEFAULT '',
                                provider VARCHAR(64) NOT NULL DEFAULT '',
                                prompt_tokens INT UNSIGNED NOT NULL DEFAULT 0,
                                completion_tokens INT UNSIGNED NOT NULL DEFAULT 0,
                                total_tokens INT UNSIGNED NOT NULL DEFAULT 0,
                                cost DECIMAL(10,4) NOT NULL DEFAULT 0,
                                created_at DATETIME NOT NULL,
                                PRIMARY KEY  (id),
                                KEY agent_id (agent_id),
                                KEY created_at (created_at)
                        ) {$charset};",
                        "CREATE TABLE {$transl} (
                                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                                article_id BIGINT UNSIGNED NOT NULL,
                                lang VARCHAR(10) NOT NULL DEFAULT '',
                                title TEXT NULL,
                                summary TEXT NULL,
                                content LONGTEXT NULL,
                                created_at DATETIME NOT NULL,
                                PRIMARY KEY  (id),
                                UNIQUE KEY article_lang (article_id, lang),
                                KEY article_id (article_id)
                        ) {$charset};",
                        "CREATE TABLE {$jobs} (
                                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                                hook VARCHAR(100) NOT NULL DEFAULT '',
                                args LONGTEXT NULL,
                                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                                claim_token VARCHAR(64) NOT NULL DEFAULT '',
                                priority INT NOT NULL DEFAULT 10,
                                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                                max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
                                scheduled_at DATETIME NOT NULL,
                                started_at DATETIME NULL DEFAULT NULL,
                                completed_at DATETIME NULL DEFAULT NULL,
                                last_error TEXT NULL,
                                created_at DATETIME NOT NULL,
                                updated_at DATETIME NOT NULL,
                                PRIMARY KEY  (id),
                                KEY status_sched (status(20), scheduled_at),
                                KEY hook (hook(100)),
                                KEY priority (priority),
                                KEY claim_token (claim_token)
                        ) {$charset};",
                        "CREATE TABLE {$revs} (
                                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                                article_id BIGINT UNSIGNED NOT NULL,
                                editor VARCHAR(100) NOT NULL DEFAULT '',
                                title TEXT NULL,
                                subtitle TEXT NULL,
                                summary TEXT NULL,
                                content LONGTEXT NULL,
                                created_at DATETIME NOT NULL,
                                PRIMARY KEY  (id),
                                KEY article_id (article_id)
                        ) {$charset};",
                        // v1.1.12 (schema v4) — metriche performance giornaliere per post
                        // (feedback loop): una riga per post+giorno+sorgente. 'source'
                        // distingue il provider dei dati ('gsc' = Search Console,
                        // 'ga4' = Google Analytics 4). Post_id = ID del post WordPress
                        // pubblicato (articoli Nexus mappati via _nexus_article_id).
                        "CREATE TABLE {$metrics} (
                                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                                post_id BIGINT UNSIGNED NOT NULL,
                                metric_date DATE NOT NULL,
                                source VARCHAR(16) NOT NULL DEFAULT 'gsc',
                                clicks INT UNSIGNED NOT NULL DEFAULT 0,
                                impressions INT UNSIGNED NOT NULL DEFAULT 0,
                                position DECIMAL(6,2) NOT NULL DEFAULT 0,
                                views INT UNSIGNED NOT NULL DEFAULT 0,
                                synced_at DATETIME NOT NULL,
                                PRIMARY KEY  (id),
                                UNIQUE KEY post_metric (post_id, metric_date, source),
                                KEY metric_date (metric_date)
                        ) {$charset};",
                );
        }

        /**
         * Crea tabelle + seed + pulizia righe danneggiate.
         */
        public static function install() {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                $sqls = self::schema();
                foreach ( $sqls as $sql ) {
                        dbDelta( $sql );
                }
                // v1.1.6 (contratto T-03, ZERO MOCK): dopo dbDelta si rimuovono le
                // righe senza data vera e le pubblicazioni finte delle versioni
                // scorse. Idempotente: gira a ogni attivazione/upgrade e non fa
                // nulla quando non ci sono righe da rimuovere.
                self::cleanup_broken_rows();
                self::migrate_schema_v2();
                self::migrate_schema_v3();
                update_option( self::DB_VERSION_KEY, NEXUS_VERSION );
                update_option( self::DB_REVISION_KEY, (string) self::DB_REVISION );
                if ( ! get_option( self::OPTION_SETTINGS ) ) {
                        update_option( self::OPTION_SETTINGS, self::default_settings(), false );
                }
                self::seed_agents();
        }

        /**
         * Upgrade schema quando la versione cambia.
         */
        public static function maybe_upgrade() {
                if ( get_option( self::DB_VERSION_KEY ) !== NEXUS_VERSION
                        || (int) get_option( self::DB_REVISION_KEY ) < self::DB_REVISION ) {
                        self::install();
                }
        }

        /**
         * Migrazione schema v2 (v1.1.9) per installazioni esistenti — idempotente.
         *
         * dbDelta aggiunge colonne e indici nuovi ma NON converte in modo affidabile
         * una KEY esistente in UNIQUE né cambia l'nullabilità su tutti i setup: qui
         * lo si fa a mano con guardie (ogni passo gira solo se serve). Effetto:
         * l'anti-duplicato degli articoli diventa atomico a livello DB (UNIQUE) e
         * i legacy con hash vuoto diventano NULL (più NULL ammessi da UNIQUE).
         *
         * @return void
         */
        private static function migrate_schema_v2() {
                global $wpdb;
                $arts = self::tables()['articles'];

                // 1) content_hash nullable.
                $nullable = $wpdb->get_row( $wpdb->prepare(
                        "SELECT IS_NULLABLE AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'content_hash'",
                        DB_NAME, $arts
                ) );
                if ( $nullable && 'NO' === $nullable->n ) {
                        $wpdb->query( "ALTER TABLE {$arts} MODIFY content_hash CHAR(32) NULL DEFAULT NULL" ); // phpcs:ignore WordPress.DB
                }
                $wpdb->query( "UPDATE {$arts} SET content_hash = NULL WHERE content_hash = ''" ); // phpcs:ignore WordPress.DB

                // 2) dedupe preventivo: resta l'hash solo all'articolo più recente.
                $wpdb->query( "UPDATE {$arts} a JOIN {$arts} b ON a.content_hash = b.content_hash AND a.id < b.id SET a.content_hash = NULL WHERE a.content_hash IS NOT NULL" ); // phpcs:ignore WordPress.DB

                // 3) UNIQUE KEY content_hash, solo se assente o non unique.
                $not_unique = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'content_hash' AND NON_UNIQUE = 1",
                        DB_NAME, $arts
                ) );
                $any = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'content_hash'",
                        DB_NAME, $arts
                ) );
                if ( $not_unique ) {
                        $wpdb->query( "ALTER TABLE {$arts} DROP INDEX content_hash" ); // phpcs:ignore WordPress.DB
                        $any = 0;
                }
                if ( ! $any ) {
                        $wpdb->query( "ALTER TABLE {$arts} ADD UNIQUE KEY content_hash (content_hash)" ); // phpcs:ignore WordPress.DB
                }
        }

        /**
         * Migrazione schema v3 (v1.1.10) per installazioni esistenti — idempotente.
         *
         * dbDelta a volte non aggiunge in modo affidabile colonne nuove su tutti
         * i setup: qui lo si fa a mano con guardie (ogni ALTER gira solo se la
         * colonna manca, verificata su information_schema). Colonne:
         *
         *  - articles.source_type: tipo della fonte che ha generato l'articolo
         *    ('rss'|'site'|'youtube'|'telegram'|'reddit'|'search'|'mcp'|'web'|''),
         *    per analytics e provenienza (v1.1.10 motore fonti multi-tipo).
         *  - agents.search_provider / agents.search_key_hash / agents.mcp_servers_text:
         *    strumenti personali per-agente (ricerca web e server MCP custom con
         *    risoluzione a cascata agente → redazione → globale, WP4 Parte 1 /
         *    WP10-12 Parte 2 del piano v1.1.10). La chiave di ricerca è salvata
         *    SOLO come hash SHA-256 hex-64 (stesso contratto della public_api_key).
         *
         * @return void
         */
        private static function migrate_schema_v3() {
                global $wpdb;
                $t = self::tables();

                $add_columns = array(
                        $t['articles'] => array(
                                'source_type'      => "ALTER TABLE {$t['articles']} ADD COLUMN source_type VARCHAR(20) NOT NULL DEFAULT ''",
                        ),
                        $t['agents'] => array(
                                'search_provider'   => "ALTER TABLE {$t['agents']} ADD COLUMN search_provider VARCHAR(30) NOT NULL DEFAULT ''",
                                'search_key_hash'   => "ALTER TABLE {$t['agents']} ADD COLUMN search_key_hash CHAR(64) NOT NULL DEFAULT ''",
                                'mcp_servers_text'  => "ALTER TABLE {$t['agents']} ADD COLUMN mcp_servers_text MEDIUMTEXT NULL",
                        ),
                );

                foreach ( $add_columns as $table => $cols ) {
                        foreach ( $cols as $column => $sql ) {
                                $exists = (int) $wpdb->get_var( $wpdb->prepare(
                                        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                                        DB_NAME, $table, $column
                                ) );
                                if ( ! $exists ) {
                                        $wpdb->query( $sql ); // phpcs:ignore WordPress.DB
                                }
                        }
                }
        }

        /**
         * Pulizia one-time v1.1.6 delle righe danneggiate (contratto T-03, ZERO MOCK).
         *
         *  - activity_logs con created_at '0000-00-00 00:00:00': causate dal bug
         *    dei formati di insert_activity (5 campi, 4 formati: wpdb riciclava il
         *    primo formato '%d' sull'ultimo campo e la data veniva valutata come
         *    numero). Il momento reale non è recuperabile: conservare le righe
         *    significherebbe mostrare date finte nel feed attività.
         *  - publish_logs con post_id 'sim_%': pubblicazioni simulate generate dal
         *    vecchio fallback del publisher (status 'published', id e URL finti che
         *    non corrispondono ad alcuna chiamata API). Conservarle falserebbe i log
         *    di pubblicazione e le statistiche analytics.
         *
         * Chiamata da install() (attivazione e ogni upgrade via maybe_upgrade):
         * query idempotenti e best-effort, nessuna eccezione propagata.
         *
         * @return void
         */
        private static function cleanup_broken_rows() {
                global $wpdb;
                $t = self::tables();
                if ( ! empty( $t['activity_logs'] ) ) {
                        $wpdb->query( $wpdb->prepare( "DELETE FROM {$t['activity_logs']} WHERE created_at = %s", '0000-00-00 00:00:00' ) ); // phpcs:ignore WordPress.DB
                }
                if ( ! empty( $t['publish_logs'] ) ) {
                        // Nella LIKE l'underscore è escapato: deve restare letterale
                        // (non jolly), così si coprono SOLO i prefissi sim_ generati
                        // dal vecchio fallback simulato, mai id reali delle API social.
                        $wpdb->query( "DELETE FROM {$t['publish_logs']} WHERE post_id LIKE 'sim\\_%'" ); // phpcs:ignore WordPress.DB
                }
        }

        /**
         * Nomi tabelle.
         *
         * @return array
         */
        public static function tables() {
                global $wpdb;
                return array(
                        'agents'        => $wpdb->prefix . 'nexus_agents',
                        'articles'      => $wpdb->prefix . 'nexus_articles',
                        'publish_logs'  => $wpdb->prefix . 'nexus_publish_logs',
                        'approval_logs' => $wpdb->prefix . 'nexus_approval_logs',
                        'activity_logs' => $wpdb->prefix . 'nexus_activity_logs',
                        'subscribers'   => $wpdb->prefix . 'nexus_subscribers',
                        'usage_logs'    => $wpdb->prefix . 'nexus_usage_logs',
                        'translations'  => $wpdb->prefix . 'nexus_translations',
                        'jobs'          => $wpdb->prefix . 'nexus_jobs',
                        'revisions'     => $wpdb->prefix . 'nexus_article_revisions',
                        'metrics'       => $wpdb->prefix . 'nexus_metrics', // v1.1.12 schema v4
                );
        }

        /**
         * Seed automatico degli agenti — NO-OP dalla v1.1.11 (BC conservata).
         *
         * Fino alla 1.1.10.x questo metodo inseriva i 7 agenti giornalisti
         * predefiniti SOLO se la tabella era vuota (chiamato da install()).
         * Dalla v1.1.11 il proprietario ha richiesto di partire da ZERO agenti:
         * una installazione nuova nasce con la redazione vuota e la scelta
         * passa dal wizard di onboarding con preset one-click
         * (admin/views/onboarding-wizard.php + sezione v1.1.11 di admin.js),
         * oppure dalla barra "Creazione rapida" e dal modal editor.
         *
         * Compatibilità:
         *  - il metodo resta (stessa firma, stesso tipo di ritorno) perché
         *    install() e codice terzo possono continuare a chiamarlo;
         *  - ritorna sempre 0 (zero agenti inseriti), così ogni contatore
         *    sul valore di ritorno resta coerente;
         *  - le installazioni esistenti NON vengono toccate: il vecchio seed
         *    girava solo su tabella vuota, quindi gli agenti già creati
         *    restano esattamente dove sono.
         *
         * @return int numero agenti inseriti (sempre 0).
         */
        public static function seed_agents() {
                return 0;
        }

        /* ============ AGENTS ============ */

        public static function agents() {
                global $wpdb;
                $table = self::tables()['agents'];
                return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" );
        }

        public static function get_agent( $id ) {
                global $wpdb;
                $table = self::tables()['agents'];
                return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ) );
        }

        /**
         * Ritorna l'agente con la categoria indicata (case-insensitive, la colonna è UNIQUE), o null.
         * Usato da REST v1.0.7 per validare categoria in create/update/clone.
         *
         * @param string $category Categoria da cercare.
         * @return object|null
         */
        public static function get_agent_by_category( $category ) {
                global $wpdb;
                $table = self::tables()['agents'];
                return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE LOWER(category) = LOWER(%s) LIMIT 1", (string) $category ) );
        }

        /**
         * Aggiorna un agente (solo colonne ammesse dalla whitelist).
         *
         * v1.1.4: ammesse anche ai_provider (sanitize_text_field), role
         * ('giornalista' | 'capo_redattore', valore non valido = 'giornalista'),
         * chief_scope ('' | 'global' | 'category' | 'term', valore non valido = '')
         * e chief_term_id (sempre intero).
         *
         * v1.1.10 (schema v3): ammessi anche search_provider (sanitize_key),
         * search_key_hash (solo hex-64: la chiave di ricerca dell'agente è
         * salvata SOLO come hash SHA-256, mai in chiaro — stesse regole della
         * public_api_key v1.1.9) e mcp_servers_text (sanitize_textarea_field,
         * formato righe 'nome|url|tokenOpzionale' come il setting globale).
         *
         * @param int   $id     Id agente.
         * @param array $fields Campi da aggiornare: name, avatar, category, description,
         *                      status, personality, ai_model, ai_provider, search_provider,
         *                      search_key_hash, mcp_servers_text, role, chief_scope,
         *                      chief_term_id, last_run, wp_user_id, term_id.
         * @return int|false
         */
        public static function update_agent( $id, $fields ) {
                global $wpdb;
                $allowed = array_intersect_key(
                        (array) $fields,
                        array_flip( array( 'name', 'avatar', 'category', 'description', 'status', 'personality', 'ai_model', 'ai_provider', 'search_provider', 'search_key_hash', 'mcp_servers_text', 'role', 'chief_scope', 'chief_term_id', 'last_run', 'wp_user_id', 'term_id' ) )
                );
                if ( ! $allowed ) {
                        return false;
                }
                // v1.1.4: sanizione colonne provider per agente + capo redattore.
                if ( isset( $allowed['ai_provider'] ) ) {
                        $allowed['ai_provider'] = sanitize_text_field( $allowed['ai_provider'] );
                }
                // v1.1.10 (schema v3): strumenti personali per-agente.
                if ( isset( $allowed['search_provider'] ) ) {
                        $allowed['search_provider'] = sanitize_key( $allowed['search_provider'] );
                }
                if ( isset( $allowed['search_key_hash'] ) ) {
                        // v1.1.10 (wave 4): la colonna memorizza la CHIAVE di ricerca
                        // personale dell'agente IN CHIARO (le chiavi Tavily/Serper/Brave
                        // servono in chiaro a runtime per chiamare le API — stesse ragioni
                        // delle prov_key_*; il nome colonnale resta per stabilità dello
                        // schema). Charset prudente, max 64 (CHAR(64)).
                        $sk = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $allowed['search_key_hash'] );
                        $allowed['search_key_hash'] = ( 0 < strlen( $sk ) ) ? substr( $sk, 0, 64 ) : '';
                }
                if ( isset( $allowed['mcp_servers_text'] ) ) {
                        $allowed['mcp_servers_text'] = sanitize_textarea_field( $allowed['mcp_servers_text'] );
                }
                if ( isset( $allowed['role'] ) ) {
                        $allowed['role'] = in_array( $allowed['role'], array( 'giornalista', 'capo_redattore' ), true ) ? $allowed['role'] : 'giornalista';
                }
                if ( isset( $allowed['chief_scope'] ) ) {
                        $allowed['chief_scope'] = in_array( $allowed['chief_scope'], array( '', 'global', 'category', 'term' ), true ) ? $allowed['chief_scope'] : '';
                }
                $allowed['updated_at'] = current_time( 'mysql' );
                $formats               = array_fill( 0, count( $allowed ), '%s' );
                // v1.1.0: colonne di integrazione nativa sempre intere (bot user + categoria WP).
                // v1.1.4: anche chief_term_id (redazione assegnata al capo redattore) resta intera.
                foreach ( array( 'wp_user_id', 'term_id', 'chief_term_id' ) as $nxa_int_field ) {
                        $nxa_pos = array_search( $nxa_int_field, array_keys( $allowed ), true );
                        if ( false !== $nxa_pos ) {
                                $allowed[ $nxa_int_field ] = absint( $allowed[ $nxa_int_field ] );
                                $formats[ $nxa_pos ]       = '%d';
                        }
                }
                if ( isset( $allowed['last_run'] ) && null === $allowed['last_run'] ) {
                        // v1.1.6: il formato va sulla POSIZIONE di last_run. Prima il
                        // 'NULL' finiva sull'ultimo campo (updated_at, che ha un valore
                        // reale): updated_at veniva scritto NULL e wpdb::prepare segnalava
                        // placeholder/argomenti disallineati. last_run=null viene comunque
                        // scritto NULL da wpdb (il valore null non è mai quotato).
                        $nxa_last_run_pos = array_search( 'last_run', array_keys( $allowed ), true );
                        if ( false !== $nxa_last_run_pos ) {
                                $formats[ $nxa_last_run_pos ] = 'NULL';
                        }
                }
                return $wpdb->update( self::tables()['agents'], $allowed, array( 'id' => absint( $id ) ), $formats, array( '%d' ) );
        }

        /**
         * Inserisce un agente (usato da import configurazione v1.0.5 e da REST create/clone
         * agenti). Ritorna id o 0.
         *
         * v1.1.4: accetta anche ai_provider (sanitize_text_field), role ('giornalista' |
         * 'capo_redattore', valore mancante o non valido = 'giornalista'), chief_scope
         * ('' | 'global' | 'category' | 'term', valore non valido = '') e chief_term_id
         * (sempre intero).
         *
         * @param array $data name (richiesto), avatar, category, description, status, personality, ai_model, ai_provider, role, chief_scope, chief_term_id, wp_user_id, term_id.
         * @return int
         */
        public static function insert_agent( $data ) {
                global $wpdb;
                $now = current_time( 'mysql' );
                // v1.1.4: ruolo e ambito capo redattore in whitelist (fallback = default colonna).
                $role = $data['role'] ?? '';
                if ( ! in_array( $role, array( 'giornalista', 'capo_redattore' ), true ) ) {
                        $role = 'giornalista';
                }
                $chief_scope = $data['chief_scope'] ?? '';
                if ( ! in_array( $chief_scope, array( '', 'global', 'category', 'term' ), true ) ) {
                        $chief_scope = '';
                }
                $row = array(
                        'name'          => sanitize_text_field( $data['name'] ?? '' ),
                        'avatar'        => sanitize_text_field( $data['avatar'] ?? '' ),
                        'category'      => sanitize_text_field( $data['category'] ?? '' ),
                        'description'   => sanitize_textarea_field( $data['description'] ?? '' ),
                        'status'        => sanitize_key( $data['status'] ?? 'active' ),
                        'personality'   => sanitize_textarea_field( $data['personality'] ?? '' ),
                        'ai_model'      => sanitize_text_field( $data['ai_model'] ?? '' ),
                        'ai_provider'   => sanitize_text_field( $data['ai_provider'] ?? '' ),
                        'role'          => $role,
                        'chief_scope'   => $chief_scope,
                        'chief_term_id' => absint( $data['chief_term_id'] ?? 0 ),
                        'wp_user_id'    => absint( $data['wp_user_id'] ?? 0 ),
                        'term_id'       => absint( $data['term_id'] ?? 0 ),
                        'created_at'    => $now,
                        'updated_at'    => $now,
                );
                if ( '' === $row['name'] || '' === $row['category'] ) {
                        return 0;
                }
                $ok = $wpdb->insert( self::tables()['agents'], $row );
                return $ok ? (int) $wpdb->insert_id : 0;
        }

        /**
         * Elimina un agente (v1.1.7). Il contenuto resta: gli articoli dell'agente
         * vengono solo scollegati (agent_id = 0), le righe attività restano come
         * storia. Pulizia coerente dei riferimenti operativi:
         * - job in coda che puntano all'agente (args JSON) → eliminati;
         * - assegnazioni nelle redazioni (term_meta _nexus_agents) → rimosse;
         * - capo redattore selezionato nelle impostazioni (chief_id) → azzerato
         *   se puntava a questo agente;
         * - l'eventuale utente WP nativo (wp_user_id) e il termine categoria
         *   (term_id) NON vengono toccati: sono oggetti WordPress, non del plugin.
         *
         * @param int $id
         * @return bool true se una riga agente è stata eliminata.
         */
        public static function delete_agent( $id ) {
                global $wpdb;
                $id = absint( $id );
                if ( ! $id ) {
                        return false;
                }
                $agent = self::get_agent( $id );
                if ( ! $agent ) {
                        return false;
                }
                $deleted = $wpdb->delete( self::tables()['agents'], array( 'id' => $id ), array( '%d' ) );
                if ( ! $deleted ) {
                        return false;
                }
                // Articoli: solo scollegamento, il contenuto editoriale non si cancella.
                $wpdb->update( self::tables()['articles'], array( 'agent_id' => 0 ), array( 'agent_id' => $id ), array( '%d' ), array( '%d' ) );
                // Job non ancora eseguiti che referenziano l'agente negli args JSON.
                $wpdb->query( $wpdb->prepare( "DELETE FROM " . self::tables()['jobs'] . " WHERE status IN ( 'pending', 'running' ) AND args LIKE %s", '%"agent_id":' . $id . '%' ) );
                // Assegnazioni nelle redazioni (WP nativo: term_meta della tassonomia nexus_redazione).
                if ( class_exists( 'Nexus_Redazioni' ) && taxonomy_exists( Nexus_Redazioni::TAX ) ) {
                        $terms = get_terms( array(
                                'taxonomy'   => Nexus_Redazioni::TAX,
                                'hide_empty' => false,
                                'fields'     => 'ids',
                                'meta_key'   => Nexus_Redazioni::META_AGENTS,
                                'meta_value' => $id,
                                'number'     => 0,
                        ) );
                        if ( is_array( $terms ) ) {
                                foreach ( $terms as $term_id ) {
                                        $ids = get_term_meta( $term_id, Nexus_Redazioni::META_AGENTS, true );
                                        if ( ! is_array( $ids ) ) {
                                                continue;
                                        }
                                        $ids = array_values( array_diff( array_map( 'absint', $ids ), array( $id ) ) );
                                        update_term_meta( $term_id, Nexus_Redazioni::META_AGENTS, $ids );
                                }
                        }
                }
                // Capo redattore globale nelle impostazioni: se era questo agente, azzera.
                $settings = self::get_settings();
                if ( ! empty( $settings['chief_id'] ) && absint( $settings['chief_id'] ) === $id ) {
                        $settings['chief_id'] = 0;
                        self::update_settings( $settings );
                }
                self::insert_activity( 0, 'agent', sprintf( 'Agente "%s" eliminato.', $agent->name ), 'info' );
                return true;
        }

        /* ============ ARTICLES ============ */

        /**
         * Query articoli con filtri.
         *
         * @param array $args {status, category, agent_id, search, page, per_page, orderby, order}
         * @return array
         */
        public static function articles( $args = array() ) {
                global $wpdb;
                $table = self::tables()['articles'];
                $where = array( '1=1' );
                $params = array();

                if ( ! empty( $args['status'] ) ) {
                        $where[]  = 'status = %s';
                        $params[] = sanitize_key( $args['status'] );
                }
                if ( ! empty( $args['category'] ) ) {
                        $where[]  = 'category = %s';
                        $params[] = sanitize_text_field( $args['category'] );
                }
                if ( ! empty( $args['agent_id'] ) ) {
                        $where[]  = 'agent_id = %d';
                        $params[] = absint( $args['agent_id'] );
                }
                if ( ! empty( $args['search'] ) ) {
                        $like     = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
                        $where[]  = '(title LIKE %s OR summary LIKE %s OR content LIKE %s)';
                        $params[] = $like;
                        $params[] = $like;
                        $params[] = $like;
                }

                $orderby  = in_array( (string) ( $args['orderby'] ?? '' ), array( 'id', 'created_at', 'published_at', 'quality_score', 'views' ), true )
                        ? $args['orderby'] : 'created_at';
                $order    = ( 'asc' === strtolower( (string) ( $args['order'] ?? 'desc' ) ) ) ? 'ASC' : 'DESC';
                $page     = max( 1, absint( $args['page'] ?? 1 ) );
                $per_page = min( 100, max( 1, absint( $args['per_page'] ?? 12 ) ) );
                $offset   = ( $page - 1 ) * $per_page;

                $sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . " ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
                $params[] = $per_page;
                $params[] = $offset;

                if ( $params ) {
                        $sql = $wpdb->prepare( $sql, ...$params );
                }
                return $wpdb->get_results( $sql );
        }

        /**
         * Conta articoli con gli stessi filtri di articles().
         *
         * @param array $args
         * @return int
         */
        public static function count_articles( $args = array() ) {
                global $wpdb;
                $table = self::tables()['articles'];
                $where = array( '1=1' );
                $params = array();

                if ( ! empty( $args['status'] ) ) {
                        $where[]  = 'status = %s';
                        $params[] = sanitize_key( $args['status'] );
                }
                if ( ! empty( $args['category'] ) ) {
                        $where[]  = 'category = %s';
                        $params[] = sanitize_text_field( $args['category'] );
                }
                if ( ! empty( $args['agent_id'] ) ) {
                        $where[]  = 'agent_id = %d';
                        $params[] = absint( $args['agent_id'] );
                }
                $sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $where );
                if ( $params ) {
                        $sql = $wpdb->prepare( $sql, ...$params );
                }
                return (int) $wpdb->get_var( $sql );
        }

        public static function get_article( $id ) {
                global $wpdb;
                $table = self::tables()['articles'];
                return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ) );
        }

        /**
         * Inserisce un articolo. Ritorna id o 0.
         *
         * @param array $data title (richiesto), subtitle, content, summary, category, agent_id, source_name, source_url, image_url, status, quality_score, read_time
         */
        public static function insert_article( $data ) {
                global $wpdb;
                $now = current_time( 'mysql' );
                $content_hash = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) ( $data['content_hash'] ?? '' ) ) );
                $row = array(
                        'title'         => sanitize_text_field( $data['title'] ?? '' ),
                        'subtitle'      => sanitize_text_field( $data['subtitle'] ?? '' ),
                        'content'       => wp_kses_post( $data['content'] ?? '' ),
                        'summary'       => sanitize_textarea_field( $data['summary'] ?? '' ),
                        'category'      => sanitize_text_field( $data['category'] ?? '' ),
                        'agent_id'      => absint( $data['agent_id'] ?? 0 ),
                        'source_name'   => sanitize_text_field( $data['source_name'] ?? '' ),
                        'source_url'    => esc_url_raw( $data['source_url'] ?? '' ),
                        'source_type'   => sanitize_key( $data['source_type'] ?? '' ),
                        'image_url'     => esc_url_raw( $data['image_url'] ?? '' ),
                        'content_hash'  => '' !== $content_hash ? $content_hash : null,
                        'status'        => sanitize_key( $data['status'] ?? 'draft' ),
                        'quality_score' => (float) ( $data['quality_score'] ?? 0 ),
                        'read_time'     => absint( $data['read_time'] ?? 3 ),
                        'created_at'    => $now,
                        'updated_at'    => $now,
                );
                if ( '' === $row['title'] ) {
                        return 0;
                }
                $ok = $wpdb->insert( self::tables()['articles'], $row );
                return $ok ? (int) $wpdb->insert_id : 0;
        }

        /**
         * Aggiorna campi di un articolo (solo colonne ammesse).
         */
        public static function update_article( $id, $fields ) {
                global $wpdb;
                $allowed = array_intersect_key(
                        (array) $fields,
                        array_flip( array( 'title', 'subtitle', 'content', 'summary', 'category', 'agent_id', 'source_name', 'source_url', 'source_type', 'image_url', 'wp_post_id', 'content_hash', 'factcheck_score', 'citations', 'status', 'quality_score', 'read_time', 'views', 'reactions', 'published_at' ) )
                );
                if ( ! $allowed ) {
                        return false;
                }
                if ( isset( $allowed['content'] ) ) {
                        $allowed['content'] = wp_kses_post( $allowed['content'] );
                }
                $allowed['updated_at'] = current_time( 'mysql' );
                $int_fields = array( 'agent_id', 'read_time', 'views', 'wp_post_id' );
                $float_fields = array( 'quality_score', 'factcheck_score' );
                $nonce_fields = array( 'published_at' );
                $formats = array();
                foreach ( $allowed as $k => $v ) {
                        if ( in_array( $k, $int_fields, true ) ) {
                                $formats[] = '%d';
                        } elseif ( in_array( $k, $float_fields, true ) ) {
                                $formats[] = '%f';
                        } elseif ( in_array( $k, $nonce_fields, true ) && null === $v ) {
                                $formats[] = 'NULL';
                                $allowed[ $k ] = null;
                        } else {
                                $formats[] = '%s';
                        }
                }
                return $wpdb->update( self::tables()['articles'], $allowed, array( 'id' => absint( $id ) ), $formats, array( '%d' ) );
        }

        public static function delete_article( $id ) {
                global $wpdb;
                $t = self::tables();
                $wpdb->delete( $t['publish_logs'], array( 'article_id' => absint( $id ) ), array( '%d' ) );
                $wpdb->delete( $t['approval_logs'], array( 'article_id' => absint( $id ) ), array( '%d' ) );
                return $wpdb->delete( $t['articles'], array( 'id' => absint( $id ) ), array( '%d' ) );
        }

        /* ============ SUBSCRIBERS (v1.0.2) ============ */

        /**
         * Lista iscritti newsletter con filtri base.
         *
         * @param array $args status (string), limit, offset.
         * @return array|null
         */
        public static function subscribers( $args = array() ) {
                global $wpdb;
                $t      = self::tables()['subscribers'];
                $limit  = min( 500, max( 1, absint( $args['limit'] ?? 100 ) ) );
                $offset = absint( $args['offset'] ?? 0 );
                $status = sanitize_key( (string) ( $args['status'] ?? '' ) );
                if ( '' !== $status ) {
                        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d", $status, $limit, $offset ) );
                }
                return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ) );
        }

        /**
         * Conta iscritti (per stato).
         */
        public static function count_subscribers( $status = '' ) {
                global $wpdb;
                $t = self::tables()['subscribers'];
                if ( '' !== $status ) {
                        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status = %s", $status ) );
                }
                return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
        }

        /**
         * Singolo iscritto per id.
         */
        public static function get_subscriber( $id ) {
                global $wpdb;
                $t = self::tables()['subscribers'];
                return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", absint( $id ) ) );
        }

        /**
         * Singolo iscritto per email.
         */
        public static function get_subscriber_by_email( $email ) {
                global $wpdb;
                $t = self::tables()['subscribers'];
                return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE email = %s", sanitize_email( (string) $email ) ) );
        }

        /**
         * Singolo iscritto per token (double opt-in / disiscrizione).
         */
        public static function get_subscriber_by_token( $token ) {
                global $wpdb;
                $t = self::tables()['subscribers'];
                return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE token = %s", preg_replace( '/[^a-zA-Z0-9]/', '', (string) $token ) ) );
        }

        /**
         * Inserisce un nuovo iscritto (email validata qui). Se l'email esiste già
         * ritorna l'id esistente senza duplicare.
         *
         * @param string $email Email iscritto.
         * @return int id iscritto o 0 su errore.
         */
        public static function insert_subscriber( $email ) {
                global $wpdb;
                $email = sanitize_email( (string) $email );
                if ( ! is_email( $email ) ) {
                        return 0;
                }
                $existing = self::get_subscriber_by_email( $email );
                if ( $existing ) {
                        return (int) $existing->id;
                }
                $ok = $wpdb->insert(
                        self::tables()['subscribers'],
                        array(
                                'email'      => $email,
                                'status'     => 'pending',
                                'token'      => wp_generate_password( 32, false, false ),
                                'created_at' => current_time( 'mysql' ),
                        ),
                        array( '%s', '%s', '%s', '%s' )
                );
                return $ok ? (int) $wpdb->insert_id : 0;
        }

        /**
         * Aggiorna campi di un iscritto (solo colonne ammesse).
         */
        public static function update_subscriber( $id, $fields ) {
                global $wpdb;
                $allowed = array_intersect_key(
                        (array) $fields,
                        array_flip( array( 'email', 'status', 'token', 'confirmed_at' ) )
                );
                if ( ! $allowed ) {
                        return false;
                }
                if ( isset( $allowed['email'] ) ) {
                        $allowed['email'] = sanitize_email( $allowed['email'] );
                        if ( ! is_email( $allowed['email'] ) ) {
                                return false;
                        }
                }
                if ( isset( $allowed['status'] ) ) {
                        $allowed['status'] = sanitize_key( $allowed['status'] );
                        if ( ! in_array( $allowed['status'], array( 'pending', 'confirmed', 'unsubscribed' ), true ) ) {
                                return false;
                        }
                }
                if ( isset( $allowed['token'] ) ) {
                        $allowed['token'] = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $allowed['token'] );
                }
                $formats = array();
                foreach ( $allowed as $v ) {
                        $formats[] = is_int( $v ) ? '%d' : '%s';
                }
                return false !== $wpdb->update( self::tables()['subscribers'], $allowed, array( 'id' => absint( $id ) ), $formats, array( '%d' ) );
        }

        /**
         * Elimina un iscritto.
         */
        public static function delete_subscriber( $id ) {
                global $wpdb;
                return $wpdb->delete( self::tables()['subscribers'], array( 'id' => absint( $id ) ), array( '%d' ) );
        }

        /* ============ USAGE AI (v1.0.3) ============ */

        /**
         * Registra un utilizzo del motore AI (token + costo stimato).
         *
         * @param array $data agent_id, action, model, prompt_tokens, completion_tokens, total_tokens, cost.
         * @return int id inserito o 0.
         */
        public static function insert_usage( $data ) {
                global $wpdb;
                $row = array(
                        'agent_id'          => absint( $data['agent_id'] ?? 0 ),
                        'action'            => sanitize_key( $data['action'] ?? '' ),
                        'model'             => sanitize_text_field( (string) ( $data['model'] ?? '' ) ),
                        'provider'          => sanitize_key( (string) ( $data['provider'] ?? '' ) ),
                        'prompt_tokens'     => absint( $data['prompt_tokens'] ?? 0 ),
                        'completion_tokens' => absint( $data['completion_tokens'] ?? 0 ),
                        'total_tokens'      => absint( $data['total_tokens'] ?? 0 ),
                        'cost'              => (float) ( $data['cost'] ?? 0 ),
                        'created_at'        => current_time( 'mysql' ),
                );
                $ok = $wpdb->insert(
                        self::tables()['usage_logs'],
                        $row,
                        array( '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%f', '%s' )
                );
                return $ok ? (int) $wpdb->insert_id : 0;
        }

        /**
         * Statistiche uso AI: totali + per-agente.
         *
         * @param string $period 'month' (default) | 'week' | 'all'.
         * @return array{totals:array,by_agent:array}
         */
        public static function usage_stats( $period = 'month' ) {
                global $wpdb;
                $t = self::tables()['usage_logs'];
                if ( 'week' === $period ) {
                        $since = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
                } elseif ( 'all' === $period ) {
                        $since = '';
                } else {
                        $since = gmdate( 'Y-m-01 00:00:00', current_time( 'timestamp' ) ); // inizio mese corrente.
                }
                $where = '' !== $since ? $wpdb->prepare( 'WHERE created_at >= %s', $since ) : '';
                $totals = $wpdb->get_row( "SELECT COUNT(*) AS calls, COALESCE(SUM(prompt_tokens),0) AS prompt_tokens, COALESCE(SUM(completion_tokens),0) AS completion_tokens, COALESCE(SUM(total_tokens),0) AS total_tokens, COALESCE(SUM(cost),0) AS cost FROM {$t} {$where}" ); // phpcs:ignore
                $by_agent = $wpdb->get_results( "SELECT agent_id, COUNT(*) AS calls, COALESCE(SUM(total_tokens),0) AS total_tokens, COALESCE(SUM(cost),0) AS cost FROM {$t} {$where} GROUP BY agent_id ORDER BY cost DESC" ); // phpcs:ignore
                return array(
                        'totals'   => array(
                                'calls'             => (int) ( $totals->calls ?? 0 ),
                                'prompt_tokens'     => (int) ( $totals->prompt_tokens ?? 0 ),
                                'completion_tokens' => (int) ( $totals->completion_tokens ?? 0 ),
                                'total_tokens'      => (int) ( $totals->total_tokens ?? 0 ),
                                'cost'              => (float) ( $totals->cost ?? 0 ),
                        ),
                        'by_agent' => is_array( $by_agent ) ? $by_agent : array(),
                );
        }

        /* ============ DUPLICATI (v1.0.3) ============ */

        /**
         * Verifica se esiste già un articolo con lo stesso hash del contenuto/titolo.
         */
        public static function article_hash_exists( $hash ) {
                global $wpdb;
                $t = self::tables()['articles'];
                $hash = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $hash ) );
                if ( '' === $hash ) {
                        return 0;
                }
                return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE content_hash = %s LIMIT 1", $hash ) );
        }

        /* ============ TRADUZIONI (v1.0.3) ============ */

        /**
         * Traduzioni di un articolo.
         */
        public static function get_translations( $article_id ) {
                global $wpdb;
                $t = self::tables()['translations'];
                return $wpdb->get_results( $wpdb->prepare( "SELECT id, article_id, lang, title, summary, created_at FROM {$t} WHERE article_id = %d ORDER BY lang ASC", absint( $article_id ) ) );
        }

        /**
         * Singola traduzione (contenuto completo).
         */
        public static function get_translation( $article_id, $lang ) {
                global $wpdb;
                $t = self::tables()['translations'];
                return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE article_id = %d AND lang = %s LIMIT 1", absint( $article_id ), sanitize_key( (string) $lang ) ) );
        }

        /**
         * Inserisce o aggiorna una traduzione (upsert per articolo+lingua).
         *
         * @return int id riga o 0 su errore.
         */
        public static function insert_translation( $data ) {
                global $wpdb;
                $article_id = absint( $data['article_id'] ?? 0 );
                $lang       = sanitize_key( (string) ( $data['lang'] ?? '' ) );
                if ( $article_id <= 0 || '' === $lang ) {
                        return 0;
                }
                $existing = self::get_translation( $article_id, $lang );
                if ( $existing ) {
                        $wpdb->update(
                                self::tables()['translations'],
                                array(
                                        'title'   => sanitize_text_field( $data['title'] ?? '' ),
                                        'summary' => sanitize_textarea_field( $data['summary'] ?? '' ),
                                        'content' => wp_kses_post( $data['content'] ?? '' ),
                                ),
                                array( 'id' => (int) $existing->id ),
                                array( '%s', '%s', '%s' ),
                                array( '%d' )
                        );
                        return (int) $existing->id;
                }
                $ok = $wpdb->insert(
                        self::tables()['translations'],
                        array(
                                'article_id' => $article_id,
                                'lang'       => $lang,
                                'title'      => sanitize_text_field( $data['title'] ?? '' ),
                                'summary'    => sanitize_textarea_field( $data['summary'] ?? '' ),
                                'content'    => wp_kses_post( $data['content'] ?? '' ),
                                'created_at' => current_time( 'mysql' ),
                        ),
                        array( '%d', '%s', '%s', '%s', '%s', '%s' )
                );
                return $ok ? (int) $wpdb->insert_id : 0;
        }

        /**
         * Elimina una traduzione.
         */
        public static function delete_translation( $article_id, $lang ) {
                global $wpdb;
                return $wpdb->delete(
                        self::tables()['translations'],
                        array(
                                'article_id' => absint( $article_id ),
                                'lang'       => sanitize_key( (string) $lang ),
                        ),
                        array( '%d', '%s' )
                );
        }

        /* ============ ACTIVITY ============ */

        public static function insert_activity( $agent_id, $action, $detail = '', $status = 'info' ) {
                global $wpdb;
                // v1.1.6: quinto formato '%s' per created_at. Prima manava (4 formati
                // per 5 campi): wpdb ricicla il primo formato quando finiscono, quindi
                // created_at riceveva '%d' e la data diventava '0000-00-00 00:00:00'.
                return $wpdb->insert(
                        self::tables()['activity_logs'],
                        array(
                                'agent_id'   => absint( $agent_id ),
                                'action'     => sanitize_key( $action ),
                                'detail'     => sanitize_textarea_field( (string) $detail ),
                                'status'     => sanitize_key( $status ),
                                'created_at' => current_time( 'mysql' ),
                        ),
                        array( '%d', '%s', '%s', '%s', '%s' )
                );
        }

        public static function activity( $args = array() ) {
                global $wpdb;
                $table  = self::tables()['activity_logs'];
                $limit  = min( 200, max( 1, absint( $args['limit'] ?? 20 ) ) );
                $agent  = absint( $args['agent_id'] ?? 0 );
                $sql    = $agent
                        ? $wpdb->prepare( "SELECT * FROM {$table} WHERE agent_id = %d ORDER BY id DESC LIMIT %d", $agent, $limit )
                        : $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit );
                return $wpdb->get_results( $sql );
        }

        /* ============ PUBLISH / APPROVAL LOGS ============ */

        public static function insert_publish_log( $article_id, $platform, $status = 'pending', $post_id = '', $post_url = '', $error = '' ) {
                global $wpdb;
                return $wpdb->insert(
                        self::tables()['publish_logs'],
                        array(
                                'article_id'   => absint( $article_id ),
                                'platform'     => sanitize_key( $platform ),
                                'status'       => sanitize_key( $status ),
                                'post_id'      => sanitize_text_field( (string) $post_id ),
                                'post_url'     => esc_url_raw( (string) $post_url ),
                                'error'        => sanitize_textarea_field( (string) $error ),
                                'published_at' => ( 'published' === $status ) ? current_time( 'mysql' ) : null,
                                'created_at'   => current_time( 'mysql' ),
                        ),
                        array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
                );
        }

        public static function publish_logs( $article_id ) {
                global $wpdb;
                $table = self::tables()['publish_logs'];
                return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE article_id = %d ORDER BY id ASC", absint( $article_id ) ) );
        }

        public static function set_approval( $article_id, $action, $note = '' ) {
                global $wpdb;
                $existing = self::get_approval( $article_id );
                $now      = current_time( 'mysql' );
                $data     = array(
                        'article_id'     => absint( $article_id ),
                        'reviewer_action' => sanitize_key( $action ),
                        'reviewer_note'  => sanitize_textarea_field( (string) $note ),
                        'reviewed_at'    => $now,
                        'created_at'     => $now,
                );
                if ( $existing ) {
                        return $wpdb->update(
                                self::tables()['approval_logs'],
                                $data,
                                array( 'id' => (int) $existing->id ),
                                array( '%d', '%s', '%s', '%s', '%s' ),
                                array( '%d' )
                        );
                }
                return $wpdb->insert( self::tables()['approval_logs'], $data, array( '%d', '%s', '%s', '%s', '%s' ) );
        }

        public static function get_approval( $article_id ) {
                global $wpdb;
                $table = self::tables()['approval_logs'];
                return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE article_id = %d", absint( $article_id ) ) );
        }

        /* ============ SETTINGS ============ */

        public static function get_settings() {
                $saved = get_option( self::OPTION_SETTINGS, array() );
                if ( ! is_array( $saved ) ) {
                        $saved = array();
                }
                return array_merge( self::default_settings(), $saved );
        }

        public static function update_settings( $fields ) {
                $current = self::get_settings();
                $keys    = array_keys( self::default_settings() );
                foreach ( (array) $fields as $k => $v ) {
                        if ( in_array( $k, $keys, true ) ) {
                                $current[ $k ] = is_bool( $v ) ? (int) $v : $v;
                        }
                }
                update_option( self::OPTION_SETTINGS, $current, false );
                return self::get_settings();
        }

        /* ============ STATS ============ */

        public static function stats() {
                global $wpdb;
                $a    = self::tables()['articles'];
                $act  = self::tables()['activity_logs'];
                $ag   = self::tables()['agents'];
                $today_start = substr( current_time( 'mysql' ), 0, 10 ) . ' 00:00:00';
                $ago_24h     = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - DAY_IN_SECONDS );

                return array(
                        'total'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$a}" ),
                        'published'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$a} WHERE status = 'published'" ),
                        'pending'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$a} WHERE status = 'pending_approval'" ),
                        'draft'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$a} WHERE status = 'draft'" ),
                        'rejected'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$a} WHERE status = 'rejected'" ),
                        'approved'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$a} WHERE status = 'approved'" ),
                        'today'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$a} WHERE created_at >= %s", $today_start ) ),
                        'views'      => (int) $wpdb->get_var( "SELECT COALESCE(SUM(views),0) FROM {$a}" ),
                        'agents_active' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ag} WHERE status = 'active'" ),
                        'activity_24h'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$act} WHERE created_at >= %s", $ago_24h ) ),
                );
        }

        /* ============ VIEWS & REACTIONS ============ */

        public static function bump_view( $id ) {
                global $wpdb;
                $table = self::tables()['articles'];
                return $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET views = views + 1 WHERE id = %d", absint( $id ) ) );
        }

        /**
         * Registra una reazione emoji su un articolo (JSON in colonna reactions).
         *
         * @return array|false conteggio aggiornato {emoji: count}
         */
        public static function add_reaction( $id, $emoji ) {
                global $wpdb;
                $article = self::get_article( $id );
                if ( ! $article ) {
                        return false;
                }
                $emoji  = mb_substr( sanitize_text_field( (string) $emoji ), 0, 8 );
                if ( '' === $emoji ) {
                        return false;
                }
                $counts = json_decode( (string) $article->reactions, true );
                if ( ! is_array( $counts ) ) {
                        $counts = array();
                }
                $counts[ $emoji ] = ( isset( $counts[ $emoji ] ) ? (int) $counts[ $emoji ] : 0 ) + 1;
                self::update_article( $id, array( 'reactions' => wp_json_encode( $counts ) ) );
                return $counts;
        }

        /* ============ JOBS (coda, v1.0.4) ============ */

        /**
         * Inserisce un lavoro in coda. Ritorna id o 0.
         *
         * @param string $hook  Nome logico del lavoro (es. 'pipeline_run_full').
         * @param array  $args  Argomenti serializzati passati all'handler.
         * @param array  $opts  priority (int, min = primo), delay (secondi), max_attempts (int).
         * @return int
         */
        public static function insert_job( $hook, $args = array(), $opts = array() ) {
                global $wpdb;
                $now   = current_time( 'mysql' );
                $delay = isset( $opts['delay'] ) ? absint( $opts['delay'] ) : 0;
                $ok    = $wpdb->insert(
                        self::tables()['jobs'],
                        array(
                                'hook'         => substr( sanitize_key( (string) $hook ), 0, 100 ),
                                'args'         => (string) wp_json_encode( (array) $args ),
                                'status'       => 'pending',
                                'priority'     => isset( $opts['priority'] ) ? absint( $opts['priority'] ) : 10,
                                'max_attempts' => isset( $opts['max_attempts'] ) ? max( 1, absint( $opts['max_attempts'] ) ) : 3,
                                'scheduled_at' => gmdate( 'Y-m-d H:i:s', strtotime( $now ) + $delay ),
                                'created_at'   => $now,
                                'updated_at'   => $now,
                        ),
                        array( '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
                );
                return $ok ? (int) $wpdb->insert_id : 0;
        }

        public static function get_job( $id ) {
                global $wpdb;
                $table = self::tables()['jobs'];
                return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ) );
        }

        /**
         * Aggiorna un lavoro (solo colonne ammesse).
         */
        public static function update_job( $id, $fields ) {
                global $wpdb;
                $allowed = array_intersect_key(
                        (array) $fields,
                        array_flip( array( 'status', 'priority', 'attempts', 'max_attempts', 'scheduled_at', 'started_at', 'completed_at', 'last_error', 'args', 'claim_token' ) )
                );
                if ( ! $allowed ) {
                        return false;
                }
                $allowed['updated_at'] = current_time( 'mysql' );
                $int_fields = array( 'priority', 'attempts', 'max_attempts' );
                $formats    = array();
                foreach ( $allowed as $k => $v ) {
                        $formats[] = in_array( $k, $int_fields, true ) ? '%d' : '%s';
                }
                return $wpdb->update( self::tables()['jobs'], $allowed, array( 'id' => absint( $id ) ), $formats, array( '%d' ) );
        }

        /**
         * Incremento ATOMICO dei tentativi di un job fallito (v1.1.9).
         *
         * Sostituisce il read-then-write di update_job per il contatore attempts:
         * due runner che falliscono lo stesso job nello stesso istante non possono
         * più sovrascriversi a vicenda il contatore. Aggiorna solo i job 'running'
         * (guardia di stato) e ritorna il nuovo valore di attempts, 0 se il job non
         * era in esecuzione (già ripreso da un altro runner).
         *
         * @param int    $id    ID del job.
         * @param string $error Errore da registrare (troncato a 1000 caratteri).
         * @return int
         */
        public static function bump_job_failure( $id, $error = '' ) {
                global $wpdb;
                $table = self::tables()['jobs'];
                $wpdb->query( $wpdb->prepare(
                        "UPDATE {$table} SET attempts = attempts + 1, last_error = %s, updated_at = %s WHERE id = %d AND status = 'running'",
                        mb_substr( (string) $error, 0, 1000 ),
                        current_time( 'mysql' ),
                        absint( $id )
                ) ); // phpcs:ignore WordPress.DB
                if ( ! $wpdb->rows_affected ) {
                        return 0;
                }
                return (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM {$table} WHERE id = %d", absint( $id ) ) );
        }

        /**
         * Claim atomico dei lavori pendenti in scadenza: li marca 'running' e li ritorna.
         *
         * v1.1.9 — davvero atomico. Il vecchio schema (SELECT id → UPDATE → SELECT
         * per started_at) faceva eseguire lo stesso job a due runner nello stesso
         * secondo: il SELECT finale non era vincolato agli id reclamati. Ora un
         * UNICO statement UPDATE con claim_token univoco per runner: la sottoquery
         * materializza i candidati, ma la guardia esterna `AND j.status = 'pending'`
         * viene rivalutata da InnoDB al momento del lock di ogni riga, quindi chi
         * arriva secondo su righe già prese le salta; il SELECT finale restituisce
         * SOLO le righe aggiornate da QUESTO runner (claim_token corrispondente).
         *
         * @param int $limit Numero massimo di lavori da rivendicare.
         * @return array
         */
        public static function claim_jobs( $limit = 5 ) {
                global $wpdb;
                $table = self::tables()['jobs'];
                $now   = current_time( 'mysql' );
                $limit = max( 1, absint( $limit ) );
                $token = wp_generate_password( 32, false, false );

                $claimed = $wpdb->query( $wpdb->prepare(
                        "UPDATE {$table} j
                         SET j.status = 'running', j.started_at = %s, j.updated_at = %s, j.claim_token = %s
                         WHERE j.id IN (
                             SELECT id FROM (
                                 SELECT id FROM {$table}
                                 WHERE status = 'pending' AND scheduled_at <= %s
                                 ORDER BY priority ASC, id ASC
                                 LIMIT %d
                             ) c
                         ) AND j.status = 'pending'",
                        $now,
                        $now,
                        $token,
                        $now,
                        $limit
                ) ); // phpcs:ignore WordPress.DB
                if ( ! $claimed ) {
                        return array();
                }

                return (array) $wpdb->get_results( $wpdb->prepare(
                        "SELECT * FROM {$table} WHERE claim_token = %s ORDER BY priority ASC, id ASC",
                        $token
                ) );
        }

        /**
         * Lista lavori con filtri base.
         *
         * @param array $args status, hook, limit, offset.
         * @return array|null
         */
        public static function jobs( $args = array() ) {
                global $wpdb;
                $t      = self::tables()['jobs'];
                $limit  = min( 500, max( 1, absint( $args['limit'] ?? 50 ) ) );
                $offset = absint( $args['offset'] ?? 0 );
                $status = sanitize_key( (string) ( $args['status'] ?? '' ) );
                $hook   = sanitize_key( (string) ( $args['hook'] ?? '' ) );

                $where  = array( '1=1' );
                $params = array();
                if ( '' !== $status ) {
                        $where[]  = 'status = %s';
                        $params[] = $status;
                }
                if ( '' !== $hook ) {
                        $where[]  = 'hook = %s';
                        $params[] = $hook;
                }
                $sql = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
                $params[] = $limit;
                $params[] = $offset;
                return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
        }

        /**
         * Conta lavori con filtri base (status, hook).
         */
        public static function count_jobs( $args = array() ) {
                global $wpdb;
                $t      = self::tables()['jobs'];
                $status = sanitize_key( (string) ( $args['status'] ?? '' ) );
                $hook   = sanitize_key( (string) ( $args['hook'] ?? '' ) );

                $where  = array( '1=1' );
                $params = array();
                if ( '' !== $status ) {
                        $where[]  = 'status = %s';
                        $params[] = $status;
                }
                if ( '' !== $hook ) {
                        $where[]  = 'hook = %s';
                        $params[] = $hook;
                }
                $sql = "SELECT COUNT(*) FROM {$t} WHERE " . implode( ' AND ', $where );
                if ( $params ) {
                        $sql = $wpdb->prepare( $sql, ...$params );
                }
                return (int) $wpdb->get_var( $sql );
        }

        /**
         * Statistiche coda: conteggi per stato.
         *
         * @return array {pending, running, complete, failed, cancelled, total}
         */
        public static function job_stats() {
                global $wpdb;
                $t     = self::tables()['jobs'];
                $rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$t} GROUP BY status" ); // phpcs:ignore
                $stats = array( 'pending' => 0, 'running' => 0, 'complete' => 0, 'failed' => 0, 'cancelled' => 0, 'total' => 0 );
                foreach ( (array) $rows as $row ) {
                        if ( isset( $stats[ $row->status ] ) ) {
                                $stats[ $row->status ] = (int) $row->n;
                        }
                        $stats['total'] += (int) $row->n;
                }
                return $stats;
        }

        /**
         * Elimina lavori completati/falliti/annullati più vecchi di N giorni.
         *
         * @param int $days Età minima in giorni (default 7).
         * @return int Righe eliminate.
         */
        public static function cleanup_jobs( $days = 7 ) {
                global $wpdb;
                $t     = self::tables()['jobs'];
                $days  = max( 1, absint( $days ) );
                $limit = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - $days * DAY_IN_SECONDS );
                return (int) $wpdb->query( $wpdb->prepare(
                        "DELETE FROM {$t} WHERE status IN ('complete','failed','cancelled') AND updated_at < %s",
                        $limit
                ) );
        }

        /* ============ UNINSTALL ============ */

        /* ============ REVISIONI ARTICOLI (v1.1.9) ============ */

        /**
         * Snapshot dell'articolo PRIMA di una modifica che sovrascrive il testo
         * (es. edit del capo redattore): preserva l'originale per un eventuale
         * ripristino/diff in admin. Ritorna l'id della revisione, 0 se fallisce.
         *
         * @param int    $article_id ID articolo.
         * @param string $editor     Etichetta di chi sta per modificare (es. "capo redattore: Nome").
         * @return int
         */
        public static function insert_article_revision( $article_id, $editor = '' ) {
                global $wpdb;
                $article = self::get_article( absint( $article_id ) );
                if ( ! $article ) {
                        return 0;
                }
                $ok = $wpdb->insert(
                        self::tables()['revisions'],
                        array(
                                'article_id' => absint( $article_id ),
                                'editor'     => sanitize_text_field( (string) $editor ),
                                'title'      => (string) $article->title,
                                'subtitle'   => (string) $article->subtitle,
                                'summary'    => (string) $article->summary,
                                'content'    => (string) $article->content,
                                'created_at' => current_time( 'mysql' ),
                        ),
                        array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
                );
                return $ok ? (int) $wpdb->insert_id : 0;
        }

        /**
         * Revisioni di un articolo, dalla più recente.
         *
         * @param int $article_id ID articolo.
         * @param int $limit      Max revisioni ritornate (1-100).
         * @return array
         */
        public static function get_article_revisions( $article_id, $limit = 20 ) {
                global $wpdb;
                $t = self::tables()['revisions'];
                return (array) $wpdb->get_results( $wpdb->prepare(
                        "SELECT * FROM {$t} WHERE article_id = %d ORDER BY id DESC LIMIT %d",
                        absint( $article_id ),
                        min( 100, max( 1, absint( $limit ) ) )
                ) );
        }

        /**
         * Singola revisione.
         *
         * @param int $revision_id ID revisione.
         * @return object|null
         */
        public static function get_article_revision( $revision_id ) {
                global $wpdb;
                $t = self::tables()['revisions'];
                return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", absint( $revision_id ) ) );
        }

        public static function drop_tables() {
                global $wpdb;
                foreach ( self::tables() as $table ) {
                        $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore
                }
                delete_option( self::OPTION_SETTINGS );
                delete_option( self::DB_VERSION_KEY );
                delete_option( 'nexus_wire_runs' );
                delete_option( self::DB_REVISION_KEY );
                delete_option( 'nexus_agent_runtime' );
                delete_option( 'nexus_native_migration' );
        }
}
