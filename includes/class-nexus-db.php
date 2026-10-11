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
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

class Nexus_DB {

        const OPTION_SETTINGS = 'nexus_settings';
        const DB_VERSION_KEY  = 'nexus_db_version';

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
                        'social_platforms'   => 'blog,twitter,facebook,linkedin,instagram',
                        'site_name'          => 'Nexus News AI',
                        'site_tagline'       => 'Il giornale di nuova generazione',
                        // Config AI (specifico plugin WP).
                        'ai_api_key'         => '',
                        'ai_base_url'        => 'https://openrouter.ai/api/v1',
                        'ai_model'           => 'openai/gpt-4o-mini',
                        // Qualità minima per auto-approvazione in modalità fully_autonomous.
                        'auto_approve_score' => 75,
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

                return array(
                        "CREATE TABLE {$agents} (
                                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                                name VARCHAR(100) NOT NULL,
                                avatar VARCHAR(255) NOT NULL DEFAULT '',
                                category VARCHAR(100) NOT NULL,
                                description TEXT NULL,
                                status VARCHAR(20) NOT NULL DEFAULT 'active',
                                personality TEXT NULL,
                                last_run DATETIME NULL DEFAULT NULL,
                                created_at DATETIME NOT NULL,
                                updated_at DATETIME NOT NULL,
                                PRIMARY KEY  (id),
                                UNIQUE KEY category (category(100))
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
                                image_url TEXT NULL,
                                status VARCHAR(30) NOT NULL DEFAULT 'draft',
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
                                KEY agent_id (agent_id)
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
                );
        }

        /**
         * Crea tabelle + seed.
         */
        public static function install() {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                $sqls = self::schema();
                foreach ( $sqls as $sql ) {
                        dbDelta( $sql );
                }
                update_option( self::DB_VERSION_KEY, NEXUS_VERSION );
                if ( ! get_option( self::OPTION_SETTINGS ) ) {
                        update_option( self::OPTION_SETTINGS, self::default_settings(), false );
                }
                self::seed_agents();
        }

        /**
         * Upgrade schema quando la versione cambia.
         */
        public static function maybe_upgrade() {
                if ( get_option( self::DB_VERSION_KEY ) !== NEXUS_VERSION ) {
                        self::install();
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
                );
        }

        /**
         * Seed dei 7 agenti giornalisti (solo se tabella vuota).
         *
         * @return int numero agenti inseriti
         */
        public static function seed_agents() {
                global $wpdb;
                $table = self::tables()['agents'];
                if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0 ) {
                        return 0;
                }
                $now  = current_time( 'mysql' );
                $aquo = "\u{2019}"; // apostrofo tipografico
                $seed = array(
                        array( 'TechBot',      '🤖', 'Tecnologia', 'Analitico e orientato all' . $aquo . 'impatto pratico', 'Agente AI specializzato in tecnologia, AI, gadget e innovazione.', 'active', 'Analitico e orientato all' . $aquo . 'impatto pratico per lettori tech-savvy' ),
                        array( 'PolicyWatch',  '🏛️', 'Politica',   'Equilibrato con contesto geopolitico',           'Agente AI che copre politica italiana ed estera con contesto storico.', 'active', 'Equilibrato e oggettivo con contesto storico e geopolitico' ),
                        array( 'MarketPulse',  '📈', 'Economia',   'Rigoroso ma accessibile',                        'Agente AI su mercati, imprese e finanza personale.', 'active', 'Rigoroso ma accessibile con dati concreti e implicazioni per investitori' ),
                        array( 'SciExplorer',  '🔬', 'Scienza',    'Divulgativo ma preciso',                         'Agente AI su ricerca, spazio, ambiente e scoperte scientifiche.', 'active', 'Divulgativo ma preciso, con spiegazioni chiare per un pubblico colto' ),
                        array( 'SportArena',   '⚽', 'Sport',      'Emozionante e narrativo',                        'Agente AI su calcio, sport nazionali e internazionali.', 'active', 'Emozionante e narrativo con statistiche e analisi tattiche' ),
                        array( 'CultureHub',   '🎭', 'Cultura',    'Elegante e riflessivo',                          'Agente AI su cinema, libri, musica e eventi culturali.', 'active', 'Elegante e riflessivo con riferimenti storici e artistici' ),
                        array( 'HealthDesk',   '🩺', 'Salute',     'Rassicurante e basato su evidenze',              'Agente AI su salute, benessere e medicina basata su evidenze.', 'active', 'Rassicurante e basato su evidenze scientifiche con consigli pratici' ),
                );
                $n = 0;
                foreach ( $seed as $row ) {
                        $ins = $wpdb->insert(
                                $table,
                                array(
                                        'name'        => $row[0],
                                        'avatar'      => $row[1],
                                        'category'    => $row[2],
                                        'description' => $row[4],
                                        'status'      => $row[5],
                                        'personality' => $row[6],
                                        'created_at'  => $now,
                                        'updated_at'  => $now,
                                ),
                                array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
                        );
                        if ( $ins ) {
                                $n++;
                        }
                }
                return $n;
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

        public static function update_agent( $id, $fields ) {
                global $wpdb;
                $allowed = array_intersect_key(
                        (array) $fields,
                        array_flip( array( 'name', 'avatar', 'category', 'description', 'status', 'personality', 'last_run' ) )
                );
                if ( ! $allowed ) {
                        return false;
                }
                $allowed['updated_at'] = current_time( 'mysql' );
                $formats               = array_fill( 0, count( $allowed ), '%s' );
                if ( isset( $allowed['last_run'] ) && null === $allowed['last_run'] ) {
                        $formats[ count( $allowed ) - 1 ] = 'NULL';
                }
                return $wpdb->update( self::tables()['agents'], $allowed, array( 'id' => absint( $id ) ), $formats, array( '%d' ) );
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
                $row = array(
                        'title'         => sanitize_text_field( $data['title'] ?? '' ),
                        'subtitle'      => sanitize_text_field( $data['subtitle'] ?? '' ),
                        'content'       => wp_kses_post( $data['content'] ?? '' ),
                        'summary'       => sanitize_textarea_field( $data['summary'] ?? '' ),
                        'category'      => sanitize_text_field( $data['category'] ?? '' ),
                        'agent_id'      => absint( $data['agent_id'] ?? 0 ),
                        'source_name'   => sanitize_text_field( $data['source_name'] ?? '' ),
                        'source_url'    => esc_url_raw( $data['source_url'] ?? '' ),
                        'image_url'     => esc_url_raw( $data['image_url'] ?? '' ),
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
                        array_flip( array( 'title', 'subtitle', 'content', 'summary', 'category', 'agent_id', 'source_name', 'source_url', 'image_url', 'status', 'quality_score', 'read_time', 'views', 'reactions', 'published_at' ) )
                );
                if ( ! $allowed ) {
                        return false;
                }
                if ( isset( $allowed['content'] ) ) {
                        $allowed['content'] = wp_kses_post( $allowed['content'] );
                }
                $allowed['updated_at'] = current_time( 'mysql' );
                $int_fields = array( 'agent_id', 'read_time', 'views' );
                $float_fields = array( 'quality_score' );
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

        /* ============ ACTIVITY ============ */

        public static function insert_activity( $agent_id, $action, $detail = '', $status = 'info' ) {
                global $wpdb;
                return $wpdb->insert(
                        self::tables()['activity_logs'],
                        array(
                                'agent_id'   => absint( $agent_id ),
                                'action'     => sanitize_key( $action ),
                                'detail'     => sanitize_textarea_field( (string) $detail ),
                                'status'     => sanitize_key( $status ),
                                'created_at' => current_time( 'mysql' ),
                        ),
                        array( '%d', '%s', '%s', '%s' )
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

        /* ============ UNINSTALL ============ */

        public static function drop_tables() {
                global $wpdb;
                foreach ( self::tables() as $table ) {
                        $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore
                }
                delete_option( self::OPTION_SETTINGS );
                delete_option( self::DB_VERSION_KEY );
                delete_option( 'nexus_wire_runs' );
        }
}
