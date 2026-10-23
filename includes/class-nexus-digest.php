<?php
/**
 * Nexus Digest — builder dei contenuti extra del digest settimanale (v1.1.14, P5).
 *
 * ESTENSIONE del digest esistente (Nexus_Newsletter::send_weekly_digest NON è
 * duplicata): questa classe fornisce
 *
 * 1. le SEZIONI EXTRA dell'email digest, costruite SOLO quando i dati esistono
 *    (mai blocchi vuoti finti) e solo se il relativo toggle admin è attivo:
 *    - Top performance della settimana: top articoli per click/viste con titolo
 *      reale, via Nexus_Analytics::insight(7) (API pubblica read-only con
 *      contratto docblock Q1: query indicizzata su metric_date, top con
 *      title/link/ctr/views; nessuna nuova query sulle metriche da qui);
 *    - Uso AI della settimana: via Nexus_DB::usage_stats('week') (read-only):
 *      chiamate, token, costo stimato se tracciato;
 *    - Errori della settimana: conteggi per tipo da nexus_activity_logs
 *      (status='error', indice created_at) e nexus_jobs (status='failed',
 *      indice status_sched) — READ-ONLY, query scritte qui con $wpdb->prepare.
 * 2. le PREFERENZE admin del digest, salvate in un'OPZIONE WP INDIPENDENTE
 *    ('nexus_digest_prefs', contratto opzioni 1.1.13: non è una chiave di
 *    Nexus_DB::default_settings() e il salvataggio NON passa dal PUT /settings
 *    né da class-nexus-admin.php, entrambi fuori perimetro);
 * 3. i DUE handler admin_post (salvataggio preferenze + invio anteprima),
 *    protetti da capability 'manage_options' + nonce dedicati.
 *
 * Nota di caricamento: il file è richiesto da class-nexus-newsletter.php
 * (il bootstrap nexus-news-ai.php è fuori dal perimetro dell'agente digest).
 *
 * Sicurezza: ogni output email passa da esc_html/esc_url; i numeri via
 * number_format_i18n; nessun dato utente grezzo nell'HTML; le azioni admin
 * richiedono manage_options + nonce; l'anteprima è l'UNICO invio fuori dal
 * cron ed è sempre innescato da un'azione admin esplicita (mai loop, mai
 * invii automatici da qui).
 *
 * @package Nexus_News_AI
 */

defined( 'ABSPATH' ) || exit;

class Nexus_Digest {

        /**
         * Opzione WP con le preferenze del digest (array). Opzione indipendente:
         * NON è una chiave di Nexus_DB::default_settings() (contratto opzioni 1.1.13).
         */
        const OPT_PREFS = 'nexus_digest_prefs';

        /**
         * Azione admin_post: salvataggio preferenze digest.
         */
        const ACTION_SAVE = 'nexus_digest_save';

        /**
         * Azione admin_post: invio anteprima del digest all'admin corrente.
         */
        const ACTION_PREVIEW = 'nexus_digest_preview';

        /**
         * Righe massime per le liste errori / lavori falliti nella sezione errori.
         */
        const ERRORS_LIMIT = 8;

        /**
         * Finestra (giorni) dei dati delle sezioni: la settimana del digest.
         */
        const WINDOW_DAYS = 7;

        /* ========================= BOOTSTRAP ========================= */

        /**
         * Registra i due handler admin_post. Chiamato da Nexus_Newsletter::init()
         * (plugins_loaded: i hook admin_post_{azione} sono registrati in tempo).
         *
         * @return void
         */
        public static function init() {
                add_action( 'admin_post_' . self::ACTION_SAVE, array( __CLASS__, 'handle_save_prefs' ) );
                add_action( 'admin_post_' . self::ACTION_PREVIEW, array( __CLASS__, 'handle_preview' ) );
        }

        /* ========================= PREFERENZE ========================= */

        /**
         * Preferenze del digest con default e sanitizzazione alla lettura.
         *
         * digest_show_performance — sezione top articoli (default 1);
         * digest_show_ai_usage    — sezione uso AI (default 1);
         * digest_show_errors      — sezione errori interni (default 0: gli errori
         *                           interni non vengono mostrati agli iscritti
         *                           se l'admin non lo chiede esplicitamente);
         * digest_recipient        — email della copia garantita ('' = admin_email).
         *
         * @return array
         */
        public static function digest_prefs() {
                $defaults = array(
                        'digest_show_performance' => 1,
                        'digest_show_ai_usage'    => 1,
                        'digest_show_errors'      => 0,
                        'digest_recipient'        => '',
                );

                $saved = get_option( self::OPT_PREFS, array() );
                if ( ! is_array( $saved ) ) {
                        $saved = array();
                }

                $prefs = array_merge( $defaults, $saved );

                foreach ( array( 'digest_show_performance', 'digest_show_ai_usage', 'digest_show_errors' ) as $key ) {
                        $prefs[ $key ] = empty( $prefs[ $key ] ) ? 0 : 1;
                }

                $prefs['digest_recipient'] = sanitize_email( (string) ( isset( $prefs['digest_recipient'] ) ? $prefs['digest_recipient'] : '' ) );
                if ( ! is_email( $prefs['digest_recipient'] ) ) {
                        $prefs['digest_recipient'] = '';
                }

                return $prefs;
        }

        /**
         * Email della copia garantita del digest: preferenza admin se valida,
         * altrimenti l'email amministratore del sito (default del mandato).
         *
         * @param array $prefs Preferenze già lette (facoltative: rilette se vuote).
         * @return string Email valida oppure '' se nessuna configurabile.
         */
        public static function admin_copy_recipient( $prefs = array() ) {
                if ( ! $prefs ) {
                        $prefs = self::digest_prefs();
                }

                $email = sanitize_email( (string) ( isset( $prefs['digest_recipient'] ) ? $prefs['digest_recipient'] : '' ) );
                if ( ! $email || ! is_email( $email ) ) {
                        $email = sanitize_email( (string) get_bloginfo( 'admin_email' ) );
                }

                return is_email( $email ) ? $email : '';
        }

        /* ========================= SEZIONI EXTRA DEL DIGEST ========================= */

        /**
         * HTML delle sezioni extra del digest (performance, uso AI, errori).
         *
         * Regola di onestà: una sezione compare SOLO se il toggle admin è attivo
         * E i dati esistono (metriche sincronizzate, chiamate AI registrate,
         * errori loggati). Nessun blocco vuoto finto: se non c'è tracciamento
         * la sezione è omessa del tutto.
         *
         * @return string HTML ('' se nessuna sezione da mostrare).
         */
        public static function sections_html() {
                $prefs = self::digest_prefs();
                $out   = '';

                if ( ! empty( $prefs['digest_show_performance'] ) ) {
                        $out .= self::performance_html();
                }

                if ( ! empty( $prefs['digest_show_ai_usage'] ) ) {
                        $out .= self::ai_usage_html();
                }

                if ( ! empty( $prefs['digest_show_errors'] ) ) {
                        $out .= self::errors_html();
                }

                return $out;
        }

        /**
         * Sezione "Top performance della settimana": top articoli per click/viste
         * con titolo reale, via Nexus_Analytics::insight(7) (READ-ONLY, stessa
         * API della card analytics; le query restano lì, su indice metric_date).
         * Tabella email-safe (table-based, stili inline). Google non connesso o
         * sync non ancora arrivato → sezione omessa (niente zeri finti).
         *
         * @return string
         */
        private static function performance_html() {
                if ( ! class_exists( 'Nexus_Analytics' ) || ! method_exists( 'Nexus_Analytics', 'insight' ) ) {
                        return '';
                }

                try {
                        $data = Nexus_Analytics::insight( self::WINDOW_DAYS );
                } catch ( Exception $e ) {
                        return ''; /* Degradazione grazia: la sezione non deve mai rompere il digest. */
                } catch ( Throwable $e ) {
                        return ''; /* Degradazione grazia. */
                }

                if ( ! is_array( $data ) || empty( $data['has_data'] ) || empty( $data['top'] ) || ! is_array( $data['top'] ) ) {
                        return '';
                }

                $rows = '';
                foreach ( $data['top'] as $row ) {
                        if ( ! is_array( $row ) ) {
                                continue;
                        }
                        $title = wp_strip_all_tags( (string) ( isset( $row['title'] ) ? $row['title'] : '' ) );
                        if ( '' === $title ) {
                                continue;
                        }
                        $link  = (string) ( isset( $row['link'] ) ? $row['link'] : '' );
                        $first = '' !== $link
                                ? '<a href="' . esc_url( $link ) . '" style="color:#1d4ed8;text-decoration:none;">' . esc_html( $title ) . '</a>'
                                : esc_html( $title );

                        $ctr      = isset( $row['ctr'] ) ? $row['ctr'] : null;
                        $ctr_html = ( null === $ctr ) ? '—' : esc_html( number_format_i18n( (float) $ctr, 1 ) . '%' );

                        $rows .= '<tr>'
                                . '<td style="padding:8px;border-bottom:1px solid #e5e7eb;">' . $first . '</td>'
                                . '<td align="right" style="padding:8px;border-bottom:1px solid #e5e7eb;text-align:right;white-space:nowrap;">' . esc_html( number_format_i18n( (int) ( isset( $row['clicks'] ) ? $row['clicks'] : 0 ) ) ) . '</td>'
                                . '<td align="right" style="padding:8px;border-bottom:1px solid #e5e7eb;text-align:right;white-space:nowrap;">' . esc_html( number_format_i18n( (int) ( isset( $row['views'] ) ? $row['views'] : 0 ) ) ) . '</td>'
                                . '<td align="right" style="padding:8px;border-bottom:1px solid #e5e7eb;text-align:right;white-space:nowrap;">' . $ctr_html . '</td>'
                                . '</tr>';
                }

                if ( '' === $rows ) {
                        return '';
                }

                $head = '<tr>'
                        . '<th align="left" style="padding:8px;border-bottom:2px solid #e5e7eb;text-align:left;font-size:12px;color:#6b7280;">' . esc_html__( 'Articolo', 'nexus-news-ai' ) . '</th>'
                        . '<th align="right" style="padding:8px;border-bottom:2px solid #e5e7eb;text-align:right;font-size:12px;color:#6b7280;">' . esc_html__( 'Click', 'nexus-news-ai' ) . '</th>'
                        . '<th align="right" style="padding:8px;border-bottom:2px solid #e5e7eb;text-align:right;font-size:12px;color:#6b7280;">' . esc_html__( 'Viste', 'nexus-news-ai' ) . '</th>'
                        . '<th align="right" style="padding:8px;border-bottom:2px solid #e5e7eb;text-align:right;font-size:12px;color:#6b7280;">' . esc_html__( 'CTR', 'nexus-news-ai' ) . '</th>'
                        . '</tr>';

                $out  = '<h2 style="font-size:16px;margin:24px 0 10px;color:#111827;">🏆 ' . esc_html__( 'Top performance della settimana', 'nexus-news-ai' ) . '</h2>';
                $out .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;color:#111827;">' . $head . $rows . '</table>';

                return $out;
        }

        /**
         * Sezione "Uso AI della settimana": riepilogo onesto (chiamate, token,
         * costo se esiste) via Nexus_DB::usage_stats('week') (READ-ONLY).
         * Zero chiamate nella settimana → sezione omessa; costo a zero → la
         * frase sul costo è omessa (il provider potrebbe non tracciare il costo).
         *
         * @return string
         */
        private static function ai_usage_html() {
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'usage_stats' ) ) {
                        return '';
                }

                try {
                        $stats = Nexus_DB::usage_stats( 'week' );
                } catch ( Exception $e ) {
                        return '';
                } catch ( Throwable $e ) {
                        return '';
                }

                $totals = ( is_array( $stats ) && isset( $stats['totals'] ) && is_array( $stats['totals'] ) ) ? $stats['totals'] : array();
                $calls  = (int) ( isset( $totals['calls'] ) ? $totals['calls'] : 0 );
                if ( $calls <= 0 ) {
                        return '';
                }

                $line = esc_html( sprintf(
                        /* Traduttori: %1$d = numero chiamate AI, %2$s = token totali, %3$s = token richiesta, %4$s = token risposta. */
                        __( 'Chiamate AI questa settimana: %1$d. Token totali: %2$s (richiesta %3$s + risposta %4$s).', 'nexus-news-ai' ),
                        $calls,
                        number_format_i18n( (int) ( isset( $totals['total_tokens'] ) ? $totals['total_tokens'] : 0 ) ),
                        number_format_i18n( (int) ( isset( $totals['prompt_tokens'] ) ? $totals['prompt_tokens'] : 0 ) ),
                        number_format_i18n( (int) ( isset( $totals['completion_tokens'] ) ? $totals['completion_tokens'] : 0 ) )
                ) );

                $cost = (float) ( isset( $totals['cost'] ) ? $totals['cost'] : 0 );
                if ( $cost > 0 ) {
                        $cost_str = number_format_i18n( $cost, ( $cost < 1 ) ? 4 : 2 );
                        $line    .= ' ' . esc_html( sprintf( __( 'Costo stimato: %s USD.', 'nexus-news-ai' ), '$' . $cost_str ) );
                }

                $out  = '<h2 style="font-size:16px;margin:24px 0 10px;color:#111827;">🤖 ' . esc_html__( 'Uso AI della settimana', 'nexus-news-ai' ) . '</h2>';
                $out .= '<p style="margin:0 0 14px;line-height:1.6;font-size:13px;color:#374151;">' . $line . '</p>';

                return $out;
        }

        /**
         * Sezione "Errori della settimana": conteggi per tipo da nexus_activity_logs
         * (status='error') e nexus_jobs (status='failed', ultimi 7 giorni). READ-ONLY.
         * Query su indici esistenti: activity_logs KEY created_at; jobs KEY
         * status_sched (filtro per status, poi finestra temporale in WHERE).
         * Zero errori → sezione omessa (nessun "0 errori" di guscio).
         *
         * @return string
         */
        private static function errors_html() {
                if ( ! class_exists( 'Nexus_DB' ) || ! method_exists( 'Nexus_DB', 'tables' ) ) {
                        return '';
                }

                global $wpdb;
                $tables = Nexus_DB::tables();
                $act    = ( isset( $tables['activity_logs'] ) && is_string( $tables['activity_logs'] ) ) ? $tables['activity_logs'] : '';
                $jobs   = ( isset( $tables['jobs'] ) && is_string( $tables['jobs'] ) ) ? $tables['jobs'] : '';

                if ( '' === $act && '' === $jobs ) {
                        return '';
                }

                // Finestra in ORARIO SITO (v1.1.16 WA1): created_at/updated_at di
                // activity_logs e jobs sono scritti con current_time('mysql').
                $since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::WINDOW_DAYS * DAY_IN_SECONDS );
                $items = array();

                if ( '' !== $act ) {
                        $rows = (array) $wpdb->get_results( $wpdb->prepare(
                                "SELECT action, COUNT(*) AS events FROM {$act} WHERE status = 'error' AND created_at >= %s GROUP BY action ORDER BY events DESC, action ASC LIMIT %d",
                                $since,
                                self::ERRORS_LIMIT
                        ) );
                        foreach ( $rows as $r ) {
                                $items[] = array(
                                        'kind'   => 'activity',
                                        'label'  => (string) $r->action,
                                        'events' => (int) $r->events,
                                );
                        }
                }

                if ( '' !== $jobs ) {
                        $rows = (array) $wpdb->get_results( $wpdb->prepare(
                                "SELECT hook, COUNT(*) AS events FROM {$jobs} WHERE status = 'failed' AND updated_at >= %s GROUP BY hook ORDER BY events DESC, hook ASC LIMIT %d",
                                $since,
                                self::ERRORS_LIMIT
                        ) );
                        foreach ( $rows as $r ) {
                                $items[] = array(
                                        'kind'   => 'job',
                                        'label'  => (string) $r->hook,
                                        'events' => (int) $r->events,
                                );
                        }
                }

                if ( ! $items ) {
                        return '';
                }

                /* Unisci i due gruppi e tieni i più frequenti (max ERRORS_LIMIT). */
                usort( $items, array( __CLASS__, 'sort_error_items' ) );
                $items = array_slice( $items, 0, self::ERRORS_LIMIT );

                $list = '';
                foreach ( $items as $item ) {
                        $label = ( '' !== $item['label'] ) ? $item['label'] : ( ( 'job' === $item['kind'] ) ? __( 'lavoro senza hook', 'nexus-news-ai' ) : __( 'evento senza tipo', 'nexus-news-ai' ) );
                        $list .= '<li style="margin:0 0 6px;">';
                        $list .= ( 'job' === $item['kind'] )
                                ? esc_html( sprintf( _n( 'Lavoro fallito: %2$s (%1$d volta)', 'Lavoro fallito: %2$s (%1$d volte)', $item['events'], 'nexus-news-ai' ), $item['events'], $label ) )
                                : esc_html( sprintf( _n( 'Errore: %2$s (%1$d volta)', 'Errore: %2$s (%1$d volte)', $item['events'], 'nexus-news-ai' ), $item['events'], $label ) );
                        $list .= '</li>';
                }

                $out  = '<h2 style="font-size:16px;margin:24px 0 10px;color:#111827;">⚠️ ' . esc_html__( 'Errori della settimana', 'nexus-news-ai' ) . '</h2>';
                $out .= '<p style="margin:0 0 8px;line-height:1.6;font-size:13px;color:#374151;">' . esc_html__( 'Problemi registrati negli ultimi 7 giorni:', 'nexus-news-ai' ) . '</p>';
                $out .= '<ul style="list-style:none;margin:0 0 14px;padding:0;font-size:13px;color:#374151;">' . $list . '</ul>';

                return $out;
        }

        /**
         * Callback usort per la lista errori: più eventi prima, poi etichetta.
         *
         * @param array $a Prima voce.
         * @param array $b Seconda voce.
         * @return int
         */
        private static function sort_error_items( $a, $b ) {
                if ( (int) $a['events'] === (int) $b['events'] ) {
                        return strcmp( (string) $a['label'], (string) $b['label'] );
                }
                return ( (int) $a['events'] < (int) $b['events'] ) ? 1 : -1;
        }

        /* ========================= HANDLER ADMIN (admin_post) ========================= */

        /**
         * Salvataggio preferenze digest (admin_post nexus_digest_save):
         * capability manage_options + nonce dedicato + sanitizzazione campo per
         * campo + opzione indipendente. Redirect di ritorno alla pagina
         * Impostazioni (tab pubblicazione) con notice whitelistata.
         *
         * @return void
         */
        public static function handle_save_prefs() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_die( esc_html__( 'Non hai i permessi per gestire le preferenze del digest.', 'nexus-news-ai' ) );
                }

                check_admin_referer( self::ACTION_SAVE, 'nexus_digest_save_nonce' );

                $prefs = self::digest_prefs();

                /* I checkbox deselezionati non arrivano in POST: assenza = 0. */
                $prefs['digest_show_performance'] = empty( $_POST['digest_show_performance'] ) ? 0 : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verificato sopra.
                $prefs['digest_show_ai_usage']    = empty( $_POST['digest_show_ai_usage'] ) ? 0 : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verificato sopra.
                $prefs['digest_show_errors']      = empty( $_POST['digest_show_errors'] ) ? 0 : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verificato sopra.

                $email = '';
                if ( isset( $_POST['digest_recipient'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verificato sopra.
                        $email = sanitize_email( sanitize_text_field( wp_unslash( (string) $_POST['digest_recipient'] ) ) );
                }
                $prefs['digest_recipient'] = ( $email && is_email( $email ) ) ? $email : '';

                update_option( self::OPT_PREFS, $prefs, false );

                self::log( 'Preferenze digest aggiornate', 'success' );
                self::redirect_back( 'saved' );
        }

        /**
         * Anteprima digest (admin_post nexus_digest_preview): UNICA via di invio
         * fuori dal cron, sempre innescata da un'azione admin esplicita
         * (capability manage_options + nonce). Il corpo è lo stesso del digest
         * reale (Nexus_Newsletter::build_preview_email) con banner ANTEPRIMA e
         * footer generico; destinatario = utente corrente (fallback copia
         * garantita/admin_email). Log attività best-effort senza PII.
         *
         * @return void
         */
        public static function handle_preview() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_die( esc_html__( 'Non hai i permessi per inviare l\'anteprima del digest.', 'nexus-news-ai' ) );
                }

                check_admin_referer( self::ACTION_PREVIEW, 'nexus_digest_preview_nonce' );

                if ( ! class_exists( 'Nexus_Newsletter' ) || ! method_exists( 'Nexus_Newsletter', 'build_preview_email' ) || ! method_exists( 'Nexus_Newsletter', 'mail_headers' ) ) {
                        self::redirect_back( 'error' );
                }

                $settings = array();
                if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'get_settings' ) ) {
                        $settings = Nexus_DB::get_settings();
                        if ( ! is_array( $settings ) ) {
                                $settings = array();
                        }
                }

                $email = Nexus_Newsletter::build_preview_email( $settings );
                $subject = ( is_array( $email ) && isset( $email['subject'] ) ) ? (string) $email['subject'] : '';
                $body    = ( is_array( $email ) && isset( $email['body'] ) ) ? (string) $email['body'] : '';

                if ( '' === $subject || '' === $body ) {
                        self::redirect_back( 'error' );
                }

                $user = wp_get_current_user();
                $to   = sanitize_email( (string) ( isset( $user->user_email ) ? $user->user_email : '' ) );
                if ( ! $to || ! is_email( $to ) ) {
                        $to = self::admin_copy_recipient();
                }
                if ( ! $to || ! is_email( $to ) ) {
                        self::redirect_back( 'error' );
                }

                $sent = false;
                try {
                        $sent = (bool) wp_mail( $to, $subject, $body, Nexus_Newsletter::mail_headers() );
                } catch ( Exception $e ) {
                        $sent = false;
                } catch ( Throwable $e ) {
                        $sent = false;
                }

                /* Log attività onesto, senza PII (nessun indirizzo nel dettaglio). */
                self::log( $sent ? "Anteprima digest inviata all'amministratore" : 'Invio anteprima digest fallito', $sent ? 'success' : 'error' );
                self::redirect_back( $sent ? 'sent' : 'error' );
        }

        /* ========================= SUPPORTO VISTA ADMIN ========================= */

        /**
         * Numero di iscritti che riceveranno il prossimo digest (confermati con
         * preferenza digest attiva, vedi Nexus_Newsletter::digest_subscribers).
         *
         * @return int
         */
        public static function optin_subscribers_count() {
                if ( ! class_exists( 'Nexus_Newsletter' ) || ! method_exists( 'Nexus_Newsletter', 'digest_subscribers' ) ) {
                        return 0;
                }

                try {
                        return count( (array) Nexus_Newsletter::digest_subscribers() );
                } catch ( Exception $e ) {
                        return 0;
                } catch ( Throwable $e ) {
                        return 0;
                }
        }

        /**
         * Notice admin dopo salvataggio preferenze / invio anteprima (query var
         * GET whitelistata: sola lettura dell'esito di un POST admin già
         * protetto da nonce, nessuna azione eseguita qui).
         *
         * @return void
         */
        public static function render_admin_notice() {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sola lettura dell'esito (whitelist), l'azione è protetta dal suo nonce.
                $notice = isset( $_GET['nexus_digest_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['nexus_digest_notice'] ) ) : '';

                $map = array(
                        'saved' => array(
                                'level'   => 'success',
                                'message' => __( 'Preferenze digest salvate.', 'nexus-news-ai' ),
                        ),
                        'sent'  => array(
                                'level'   => 'success',
                                'message' => __( 'Anteprima del digest inviata: controlla la tua casella email.', 'nexus-news-ai' ),
                        ),
                        'error' => array(
                                'level'   => 'error',
                                'message' => __( 'Invio dell\'anteprima non riuscito: verifica la configurazione email del sito.', 'nexus-news-ai' ),
                        ),
                );

                if ( ! isset( $map[ $notice ] ) ) {
                        return;
                }

                echo '<div class="notice notice-' . esc_attr( $map[ $notice ]['level'] ) . ' is-dismissible"><p>' . esc_html( $map[ $notice ]['message'] ) . '</p></div>';
        }

        /* ========================= HELPER PRIVATI ========================= */

        /**
         * Redirect di ritorno alla pagina Impostazioni (tab pubblicazione)
         * con notice whitelistata, dopo una azione admin_post.
         *
         * @param string $notice Esito whitelistato (saved|sent|error).
         * @return void
         */
        private static function redirect_back( $notice ) {
                $url = add_query_arg(
                        array(
                                'page'                => 'nexus-settings',
                                'tab'                 => 'pubblicazione',
                                'nexus_digest_notice' => sanitize_key( (string) $notice ),
                        ),
                        admin_url( 'admin.php' )
                );

                wp_safe_redirect( $url );
                exit;
        }

        /**
         * Log attività best-effort (maiuscole/minuscole mai nel log, nessun PII,
         * nessun segreto): mai bloccare il flusso per un log.
         *
         * @param string $message Dettaglio sintetico in italiano (log, non UI).
         * @param string $status  info|success|error.
         * @return void
         */
        private static function log( $message, $status = 'info' ) {
                try {
                        if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                                Nexus_DB::insert_activity( 0, 'newsletter', sanitize_text_field( (string) $message ), sanitize_key( (string) $status ) );
                        }
                } catch ( Exception $e ) {
                        /* Silenzio. */
                } catch ( Throwable $e ) {
                        /* Silenzio. */
                }
        }
}
