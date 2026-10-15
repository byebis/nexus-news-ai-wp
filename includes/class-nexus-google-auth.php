<?php
/**
 * Nexus Google Auth — OAuth 2.0 per Google Search Console + Google Analytics 4.
 *
 * Gestisce l'intero ciclo OAuth del plugin verso Google (client "Desktop/Web"
 * configurato dall'utente nelle Impostazioni) e espone il token agli altri
 * moduli (metrics, analytics, GSC) tramite una piccola API statica.
 *
 * CONTRATTO PUBBLICO (v1.1.12, pacchetto S1 — consumer: metrics/analytics):
 * Nexus_Google_Auth::get_access_token() → string|WP_Error — access token
 *     valido (refresh automatico se mancano < 60s alla scadenza); WP_Error con
 *     messaggio breve se non collegato o refresh fallito. MAI dettagli token.
 * Nexus_Google_Auth::is_connected()  → bool — token salvato con refresh_token
 *     oppure access_token non scaduto.
 * Nexus_Google_Auth::disconnect()    → bool — rimuove l'option del token.
 * Nexus_Google_Auth::get_redirect_uri() → string — URI di callback da
 *     registrare su Google Cloud Console:
 *     admin_url( 'admin-post.php?action=nexus_gsc_oauth_callback' ).
 *
 * Flusso admin-post (nessuna UI qui: i link li renderizza la vista settings):
 *   1. nexus_gsc_authorize  (nonce 'nexus_gsc_oauth')      → redirect a Google
 *      con access_type=offline e prompt=consent (refresh token garantito);
 *   2. nexus_gsc_oauth_callback (protezione via parametro state) → scambio
 *      code→token su https://oauth2.googleapis.com/token, salvataggio cifrato,
 *      redirect a Impostazioni con nexus_gsc_oauth=ok|err&reason=<chiave breve>;
 *   3. nexus_gsc_disconnect (nonce 'nexus_gsc_disconnect') → rimozione token,
 *      redirect con nexus_gsc_oauth=disconnected.
 *
 * SICUREZZA:
 * - Token nell'option 'nexus_gsc_token' CIFRATO (v1.1.15, WC5a): AES-256-GCM via
 *   openssl (chiave hash_hmac('sha256', 'nexus-gsc-token', wp_salt('auth'), true),
 *   IV random a ogni messaggio, TAG di autenticazione salvato nel payload),
 *   payload JSON {access_token, refresh_token, expires_at, scopes, created_at}
 *   marcato con prefisso 'NEXGSC2:'. FORMATO: 'NEXGSC2:' + base64( IV(12) . TAG(16) .
 *   ciphertext ) — il GCM dà integrità oltre alla confidenzialità (CBC senza MAC
 *   accettava payload manipolabili). LEGACY READ-ONLY: i valori 'NEXGSC1:'
 *   (AES-256-CBC delle versioni precedenti) restano decifrabili; ogni
 *   salvataggio (refresh o reconnect) riscrive in GCM. FAIL-CLOSED (v1.1.18,
 *   AL-15): se l'estensione openssl è assente NESSUN token viene salvato in
 *   chiaro — il flusso di collegamento (authorize/callback) fallisce con errore
 *   esplicito e il rinnovo non persiste (WP_Error); un notice admin persistente
 *   (option 'nexus_gsc_plaintext_notice' + hook standard admin_notices) spiega
 *   il requisito. Token in chiaro di installazioni precedenti: restano LEGGIBILI
 *   (mai lock-out) e vengono RI-CIFRATI al primo caricamento con openssl
 *   disponibile (migrazione in load_payload); se openssl resta assente il
 *   notice resta visibile. La lettura di un option cifrato senza openssl
 *   fallisce in modo sicuro e riporta token assente.
 * - RINNOVO CON LOCK (v1.1.15, WC5b): get_access_token() acquisisce un lock
 *   atomico (add_option 'nexus_gsc_refresh_lock', TTL 30s) prima di chiamare
 *   Google; il processo che non ottiene il lock attende brevemente e riusa il
 *   token fresco scritto dall'altro processo. Cron metriche e wizard simultanei
 *   NON rinnovano più due volte.
 * - Nessun segreto in log o redirect: le reason di errore sono chiavi brevi
 *   sanitize_key (es. 'exchange', 'access_denied'); i messaggi WP_Error sono
 *   brevi e senza token; il client_secret non viene mai scritto nei log.
 * - state = nonce 'nexus_gsc_oauth_state' + '|' + host breve del sito: doppio
 *   binding CSRF (utente+sessione e sito).
 *
 * PHP 7.4 puro: niente match/enum/nullsafe/constructor promotion/readonly.
 *
 * @package Nexus_News_AI
 * @since   1.1.12 (S1)
 */

defined( 'ABSPATH' ) || exit;

final class Nexus_Google_Auth {

        /**
         * Id del lock correntemente posseduto da questo processo ('' = nessuno).
         * Serve al rilascio per NON cancellare il lock di un altro processo
         * (caso: nostro TTL scaduto, un altro ha ripreso il mutex).
         *
         * @var string
         */
        private static $refresh_lock_id = '';

        /**
         * v1.1.18 (AL-15): guardia per-request per la notice "token in chiaro"
         * (load_payload gira anche nel loop di polling del lock: il flag evita
         * query ripetute sull'option della notice nello stesso request).
         *
         * @var bool
         */
        private static $plaintext_notice_requested = false;

        /**
         * Option WP che contiene il token (cifrato con openssl; per i soli token
         * di installazioni <= 1.1.17 può essere un JSON in chiaro legacy).
         */
        const OPTION_TOKEN = 'nexus_gsc_token';

        /**
         * v1.1.18 (AL-15): flag persistente "openssl richiesto" (1 = mostra il
         * notice admin). Armato quando un salvataggio token viene RIFIUTATO
         * per openssl assente o quando si rileva un token legacy in chiaro senza
         * openssl; rimosso alla disconnessione o al ritorno di openssl (il token
         * legacy viene ri-cifrato da load_payload). Standard admin_notices.
         */
        const OPTION_PLAINTEXT_NOTICE = 'nexus_gsc_plaintext_notice';

        /**
         * LEGACY (v1.1.15, WC5a): prefisso AES-256-CBC delle versioni precedenti
         * ('NEXGSC1:' + base64( IV(16) . ciphertext )). SOLO LETTURA: i valori
         * esistenti restano decifrabili; ogni nuova scrittura usa NEXGSC2 (GCM).
         */
        const CIPHER_MARKER = 'NEXGSC1:';

        /**
         * Prefisso del formato CORRENTE (v1.1.15, WC5a): AES-256-GCM.
         * Tutto ciò che segue è base64( IV(12) . TAG(16) . ciphertext ).
         */
        const CIPHER_MARKER_GCM = 'NEXGSC2:';

        /**
         * Metodo di cifratura CORRENTE (GCM: confidenzialità + integrità via tag).
         * $tag by-ref di openssl_encrypt/decrypt è PHP 7.1+ (base minima dichiarata).
         */
        const CIPHER_METHOD_GCM = 'aes-256-gcm';

        /** Lunghezza (byte) dell'IV GCM (12: raccomandazione NIST per GCM). */
        const GCM_IV_BYTES = 12;

        /** Lunghezza (byte) del TAG di autenticazione GCM (16 = 128 bit). */
        const GCM_TAG_BYTES = 16;

        /**
         * LEGACY (v1.1.15, WC5a): metodo della versione precedente, usato SOLO
         * per decifrare i valori NEXGSC1: già presenti nell'option.
         */
        const CIPHER_METHOD = 'aes-256-cbc';

        /**
         * Contesto della chiave HMAC (domain separation rispetto ad altri usi di wp_salt).
         */
        const CIPHER_CONTEXT = 'nexus-gsc-token';

        /**
         * Scopes OAuth: Search Console readonly + Analytics (GA4) readonly.
         */
        const SCOPE_GSC = 'https://www.googleapis.com/auth/webmasters.readonly';
        const SCOPE_GA4 = 'https://www.googleapis.com/auth/analytics.readonly';

        /**
         * Endpoint OAuth Google (authorization + token).
         */
        const OAUTH_AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
        const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';

        /**
         * Timeout HTTP (secondi) per scambio/refresh del token.
         */
        const HTTP_TIMEOUT = 20;

        /**
         * Anticipo (secondi) sul rinnovo: se expires_at - 60 < time() si refresha.
         */
        const EXPIRY_SLACK = 60;

        /**
         * Durata di default (secondi) usata se la risposta token omette expires_in.
         */
        const DEFAULT_EXPIRES_IN = 3600;

        /**
         * Lock anti-doppio-rinnovo (v1.1.15, WC5b): option utilizzata come mutex
         * (add_option è atomica: INSERT che fallisce se l'option esiste). Valore
         * '<scadenza-epoch>|<id-random>' del holder; autoload disattivato.
         */
        const LOCK_OPTION = 'nexus_gsc_refresh_lock';

        /** TTL del lock (secondi): un holder morto senza rilascio è recuperabile dopo. */
        const LOCK_TTL = 30;

        /** Attesa massima (secondi) del processo che NON ottiene il lock. */
        const LOCK_WAIT_SECONDS = 5;

        /** Intervallo di polling (microsecondi) durante l'attesa. */
        const LOCK_POLL_MICRO = 250000;

        /**
         * Azioni admin-post (parametro action) e nonce associati.
         */
        const ACTION_AUTHORIZE  = 'nexus_gsc_authorize';
        const ACTION_CALLBACK   = 'nexus_gsc_oauth_callback';
        const ACTION_DISCONNECT = 'nexus_gsc_disconnect';

        const NONCE_AUTHORIZE  = 'nexus_gsc_oauth';
        const NONCE_STATE      = 'nexus_gsc_oauth_state';
        const NONCE_DISCONNECT = 'nexus_gsc_disconnect';

        /**
         * Bootstrap modulo: registra i tre handler admin-post e il notice admin
         * persistente (v1.1.18, AL-15). NON fa chiamate di rete né letture di
         * option a bootstrap time.
         *
         * @return void
         */
        public static function init() {
                add_action( 'admin_post_' . self::ACTION_AUTHORIZE, array( __CLASS__, 'handle_authorize' ) );
                add_action( 'admin_post_' . self::ACTION_CALLBACK, array( __CLASS__, 'handle_callback' ) );
                add_action( 'admin_post_' . self::ACTION_DISCONNECT, array( __CLASS__, 'handle_disconnect' ) );

                // v1.1.18 (AL-15): notice persistente "openssl richiesto"; il
                // callback filtra per capability e per flag option.
                add_action( 'admin_notices', array( __CLASS__, 'render_admin_notice' ) );
        }

        /**
         * Handler admin-post 'nexus_gsc_authorize': avvia il flusso OAuth.
         *
         * Guard: capability manage_options + nonce 'nexus_gsc_oauth' (da $_GET),
         * altrimenti wp_die. Costruisce l'URL di consenso Google con
         * access_type=offline + prompt=consent e state firmato, poi redirige.
         *
         * @return void
         */
        public static function handle_authorize() {
                self::guard_admin( self::NONCE_AUTHORIZE );

                $settings  = self::plugin_settings();
                $client_id = isset( $settings['gsc_oauth_client_id'] ) ? trim( (string) $settings['gsc_oauth_client_id'] ) : '';

                if ( '' === $client_id ) {
                        wp_die( 'Client ID OAuth Google non configurato: compila i campi OAuth nelle Impostazioni prima di collegare Search Console.' );
                }

                // v1.1.18 (AL-15): fail-closed — senza openssl il token NON viene
                // salvato in chiaro: il flusso si blocca qui, prima del redirect
                // verso Google (nessuno scambio di code destinato a fallire).
                if ( ! self::openssl_available() ) {
                        self::log_activity( 'openssl richiesto per salvare le credenziali Google in sicurezza: collegamento bloccato (openssl assente)', 'error' );
                        self::mark_plaintext_notice();

                        wp_die( 'openssl richiesto per salvare le credenziali Google in sicurezza: attiva l\'estensione PHP openssl sul server e riprova il collegamento dalle Impostazioni.' );
                }

                $params = array(
                        'response_type' => 'code',
                        'client_id'     => $client_id,
                        'redirect_uri'  => self::get_redirect_uri(),
                        'scope'         => self::default_scopes(),
                        'access_type'   => 'offline',
                        'prompt'        => 'consent',
                        'state'         => self::create_state(),
                );

                wp_redirect( self::OAUTH_AUTH_URL . '?' . build_query( $params ) );
                exit;
        }

        /**
         * Handler admin-post 'nexus_gsc_oauth_callback': ritorno da Google.
         *
         * Guard: capability manage_options; il CSRF è coperto dal parametro state
         * (nonce + host), non da un nonce GET della pagina (Google riporta l'utente
         * qui via redirect). Scambia code→token, salva il payload cifrato e
         * redirige a Impostazioni con esito ok|err (reason = chiave breve, MAI
         * dettagli del token o della risposta Google).
         *
         * @return void
         */
        public static function handle_callback() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_die( 'Permesso negato: serve la capability manage_options.' );
                }

                // Google riporta l'annullamento del consenso come parametro 'error'.
                $denied = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( (string) $_GET['error'] ) ) : '';
                if ( '' !== $denied ) {
                        self::log_activity( 'Collegamento Google annullato (' . $denied . ')', 'error' );
                        self::redirect_settings( 'err', $denied );
                }

                $state = isset( $_GET['state'] ) ? (string) wp_unslash( $_GET['state'] ) : '';
                if ( ! self::verify_state( $state ) ) {
                        wp_die( 'Stato OAuth non valido o scaduto: riavvia il collegamento dalla pagina Impostazioni.' );
                }

                $code = isset( $_GET['code'] ) ? trim( (string) wp_unslash( $_GET['code'] ) ) : '';
                if ( '' === $code ) {
                        self::redirect_settings( 'err', 'code' );
                }

                $settings      = self::plugin_settings();
                $client_id     = isset( $settings['gsc_oauth_client_id'] ) ? trim( (string) $settings['gsc_oauth_client_id'] ) : '';
                $client_secret = isset( $settings['gsc_oauth_client_secret'] ) ? trim( (string) $settings['gsc_oauth_client_secret'] ) : '';

                if ( '' === $client_id || '' === $client_secret ) {
                        self::redirect_settings( 'err', 'config' );
                }

                // v1.1.18 (AL-15): fail-closed anche qui (difesa in profondità: la
                // guardia è già in handle_authorize) — nessun token salvato in chiaro.
                if ( ! self::openssl_available() ) {
                        self::log_activity( 'openssl richiesto per salvare le credenziali Google in sicurezza: scambio token bloccato (openssl assente)', 'error' );
                        self::mark_plaintext_notice();

                        wp_die( 'openssl richiesto per salvare le credenziali Google in sicurezza: attiva l\'estensione PHP openssl sul server e riprova il collegamento dalle Impostazioni.' );
                }

                $data = self::token_request(
                        array(
                                'code'          => $code,
                                'grant_type'    => 'authorization_code',
                                'client_id'     => $client_id,
                                'client_secret' => $client_secret,
                                'redirect_uri'  => self::get_redirect_uri(),
                        )
                );

                if ( is_wp_error( $data ) ) {
                        self::log_activity( 'Scambio token Google fallito: ' . $data->get_error_message(), 'error' );
                        self::redirect_settings( 'err', 'exchange' );
                }

                // Con prompt=consent Google ridà sempre il refresh_token; in ogni caso
                // si preserva quello precedente se la risposta non lo include.
                $refresh  = isset( $data['refresh_token'] ) ? trim( (string) $data['refresh_token'] ) : '';
                $previous = self::load_payload();

                if ( '' === $refresh && is_array( $previous ) && isset( $previous['refresh_token'] ) ) {
                        $refresh = (string) $previous['refresh_token'];
                }

                $scopes  = isset( $data['scope'] ) ? (string) $data['scope'] : self::default_scopes();
                $expires = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : self::DEFAULT_EXPIRES_IN;

                if ( ! self::save_payload( (string) $data['access_token'], $refresh, $expires, $scopes ) ) {
                        // v1.1.18 (AL-15): fail-closed — nessun token in chiaro nel DB
                        // (openssl assente o cifratura fallita): esito onesto, il
                        // notice persistente spiega il requisito.
                        wp_die( 'openssl richiesto per salvare le credenziali Google in sicurezza: attiva l\'estensione PHP openssl sul server e riprova il collegamento dalle Impostazioni.' );
                }

                self::log_activity( 'Collegamento Google (Search Console + GA4) completato', 'success' );
                self::redirect_settings( 'ok' );
        }

        /**
         * Handler admin-post 'nexus_gsc_disconnect': rimuove il token salvato.
         *
         * Guard: capability manage_options + nonce 'nexus_gsc_disconnect' (da
         * $_GET['_wpnonce']). Redirect a Impostazioni con nexus_gsc_oauth=disconnected.
         *
         * @return void
         */
        public static function handle_disconnect() {
                self::guard_admin( self::NONCE_DISCONNECT );

                $removed = self::disconnect();

                self::log_activity(
                        $removed ? 'Token Google rimosso (disconnessione richiesta dall\'utente)' : 'Nessun token Google da rimuovere',
                        $removed ? 'success' : 'info'
                );

                self::redirect_settings( 'disconnected' );
        }

        /* ============ API PUBBLICA PER GLI ALTRI MODULI ============ */

        /**
         * Access token Google valido, con refresh automatico se in scadenza.
         *
         * Legge l'option cifrata; se expires_at - 60 < time() rinnova via
         * grant_type=refresh_token e ripersiste il payload (preservando il
         * refresh_token precedente se Google non lo rispedisce).
         *
         * v1.1.15 (WC5b): il rinnovo è protetto da un LOCK atomico
         * (add_option 'nexus_gsc_refresh_lock', TTL 30s, recupero dei lock morti):
         * dopo l'acquisizione il payload viene RI-LETTO — se un altro processo ha
         * appena completato il rinnovo si usa il suo token senza una seconda
         * chiamata a Google. Chi non ottiene il lock attende fino a 5s e riusa il
         * token fresco; il lock è rilasciato in finally (ogni caso d'uscita).
         *
         * @return string|WP_Error Access token oppure WP_Error con messaggio breve
         *                        (nessun segreto nel messaggio).
         */
        public static function get_access_token() {
                $payload = self::load_payload();

                if ( ! is_array( $payload ) ) {
                        return new WP_Error( 'nexus_gsc_oauth', 'Token Google non presente o non leggibile: collega Search Console dalle Impostazioni.' );
                }

                $access  = isset( $payload['access_token'] ) ? (string) $payload['access_token'] : '';
                $refresh = isset( $payload['refresh_token'] ) ? (string) $payload['refresh_token'] : '';
                $expires = isset( $payload['expires_at'] ) ? (int) $payload['expires_at'] : 0;

                if ( '' === $access && '' === $refresh ) {
                        return new WP_Error( 'nexus_gsc_oauth', 'Token Google incompleto: riavvia il collegamento dalle Impostazioni.' );
                }

                // Ancora valido (con margine di 60 secondi): nessuna chiamata di rete.
                if ( '' !== $access && $expires - self::EXPIRY_SLACK > time() ) {
                        return $access;
                }

                if ( '' === $refresh ) {
                        return new WP_Error( 'nexus_gsc_oauth', 'Sessione Google scaduta: riavvia il collegamento per rinnovare il consenso offline.' );
                }

                // ===== LOCK ANTI-DOPPIO-RINNOVO (v1.1.15, WC5b) =====
                // Cron metriche e wizard possono arrivare simultanei: senza mutex
                // entrambi rinnovavano (due chiamate a Google per lo stesso refresh).
                // Acquisizione atomica via add_option (INSERT che fallisce se l'option
                // esiste: primitiva atomica, non un transient). Chi NON ottiene il
                // lock attende fino a 5s e riusa il token fresco dell'altro processo.
                if ( ! self::acquire_refresh_lock() ) {
                        $waited = self::wait_fresh_access_token();

                        if ( '' !== $waited ) {
                                return $waited;
                        }

                        // Ultimo tentativo: l'holder potrebbe aver appena rilasciato.
                        if ( ! self::acquire_refresh_lock() ) {
                                return new WP_Error( 'nexus_gsc_oauth', 'Rinnovo del token Google già in corso da un altro processo: riprova tra qualche secondo.' );
                        }
                }

                try {
                        // Ri-lettura DOPO l'acquisizione del lock: mentre aspettavamo un
                        // altro processo può aver COMPLETATO il rinnovo (token di nuovo
                        // valido: usiamo quello senza chiamare Google) oppure un reconnect
                        // può aver cambiato il payload (nuovo refresh_token da usare).
                        $payload = self::load_payload();
                        $fresh   = self::valid_access_token_from( $payload );

                        if ( '' !== $fresh ) {
                                return $fresh;
                        }

                        if ( ! is_array( $payload ) || '' === trim( (string) ( $payload['refresh_token'] ?? '' ) ) ) {
                                return new WP_Error( 'nexus_gsc_oauth', 'Token Google non presente o non leggibile: collega Search Console dalle Impostazioni.' );
                        }
                        $refresh = trim( (string) $payload['refresh_token'] );

                        $settings      = self::plugin_settings();
                        $client_id     = isset( $settings['gsc_oauth_client_id'] ) ? trim( (string) $settings['gsc_oauth_client_id'] ) : '';
                        $client_secret = isset( $settings['gsc_oauth_client_secret'] ) ? trim( (string) $settings['gsc_oauth_client_secret'] ) : '';

                        if ( '' === $client_id || '' === $client_secret ) {
                                return new WP_Error( 'nexus_gsc_oauth', 'Credenziali OAuth Google mancanti nelle Impostazioni: impossibile rinnovare il token.' );
                        }

                        $data = self::token_request(
                                array(
                                        'grant_type'    => 'refresh_token',
                                        'refresh_token' => $refresh,
                                        'client_id'     => $client_id,
                                        'client_secret' => $client_secret,
                                )
                        );

                        if ( is_wp_error( $data ) ) {
                                return $data;
                        }

                        $new_access = isset( $data['access_token'] ) ? trim( (string) $data['access_token'] ) : '';
                        if ( '' === $new_access ) {
                                return new WP_Error( 'nexus_gsc_oauth', 'Google non ha restituito un nuovo access token.' );
                        }

                        $new_refresh = isset( $data['refresh_token'] ) ? trim( (string) $data['refresh_token'] ) : '';
                        if ( '' === $new_refresh ) {
                                $new_refresh = $refresh;
                        }

                        $old_scopes  = is_array( $payload ) && isset( $payload['scopes'] ) ? (string) $payload['scopes'] : self::default_scopes();
                        $new_scopes  = isset( $data['scope'] ) ? (string) $data['scope'] : $old_scopes;
                        $new_expires = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : self::DEFAULT_EXPIRES_IN;

                        if ( ! self::save_payload( $new_access, $new_refresh, $new_expires, $new_scopes ) ) {
                                // v1.1.18 (AL-15): fail-closed — il rinnovo NON viene
                                // persistito in chiaro; l'option precedente resta intatta.
                                return new WP_Error( 'nexus_gsc_oauth', 'openssl richiesto per salvare le credenziali Google in sicurezza: attiva l\'estensione PHP openssl e ricollega Search Console.' );
                        }

                        return $new_access;
                } finally {
                        // Rilascio in OGNI caso di uscita (token rinnovato o errore):
                        // mai un lock appeso fino a scadenza per un errore transitorio.
                        self::release_refresh_lock();
                }
        }

        /**
         * Stato di collegamento: esiste un token usabile?
         *
         * @return bool True se l'option contiene un refresh_token oppure un
         *              access_token non ancora scaduto.
         */
        public static function is_connected() {
                $payload = self::load_payload();

                if ( ! is_array( $payload ) ) {
                        return false;
                }

                $access  = isset( $payload['access_token'] ) ? (string) $payload['access_token'] : '';
                $refresh = isset( $payload['refresh_token'] ) ? (string) $payload['refresh_token'] : '';
                $expires = isset( $payload['expires_at'] ) ? (int) $payload['expires_at'] : 0;

                if ( '' !== $refresh ) {
                        return true;
                }

                return '' !== $access && $expires > time();
        }

        /**
         * Disconnessione: rimozione dell'option del token.
         *
         * v1.1.18 (AL-15): rimuove anche il flag della notice "openssl/token in
         * chiaro" — senza token non c'è più nulla da proteggere.
         *
         * @return bool Esito di delete_option (false anche se il token non c'era).
         */
        public static function disconnect() {
                $removed = delete_option( self::OPTION_TOKEN );

                self::clear_plaintext_notice();

                return $removed;
        }

        /**
         * URI di callback da registrare su Google Cloud Console (Authorized
         * redirect URIs) e usato in authorize + scambio token.
         *
         * @return string
         */
        public static function get_redirect_uri() {
                return admin_url( 'admin-post.php?action=' . self::ACTION_CALLBACK );
        }

        /* ============ LOCK ANTI-DOPPIO-RINNOVO (v1.1.15, WC5b) ============ */

        /**
         * Acquisisce il lock di rinnovo (atomica): true se ottenuto.
         *
         * Primitiva: add_option è un INSERT — fallisce se l'option esiste già,
         * quindi SOLO un processo "entra". Valore '<scadenza-epoch>|<id-random>'.
         * Recupero: un lock la cui scadenza è nel passato indica un holder morto
         * senza rilascio (fatal, OOM, kill): viene rimosso e l'acquisizione
         * ritentata (finestra di gara residua coperta dalla ri-lettura del
         * payload: il peggiore scenario resta un solo rinnovo in più).
         *
         * @return bool
         */
        private static function acquire_refresh_lock() {
                if ( ! self::try_add_refresh_lock() ) {
                        if ( self::lock_expires_at() >= time() ) {
                                return false; // holder vivo: non è nostro.
                        }

                        delete_option( self::LOCK_OPTION );

                        if ( ! self::try_add_refresh_lock() ) {
                                return false;
                        }
                }

                return true;
        }

        /**
         * Tentativo atomico di creazione del lock. Registra l'id-random del
         * holder in $refresh_lock_id (solo se acquisito) per il rilascio.
         *
         * @return bool
         */
        private static function try_add_refresh_lock() {
                self::$refresh_lock_id = wp_generate_password( 16, false );

                $value = ( time() + self::LOCK_TTL ) . '|' . self::$refresh_lock_id;

                if ( add_option( self::LOCK_OPTION, $value, '', 'no' ) ) {
                        return true;
                }

                self::$refresh_lock_id = '';

                return false;
        }

        /**
         * Scadenza (epoch) del lock attualmente presente, 0 se assente.
         *
         * @return int
         */
        private static function lock_expires_at() {
                $held  = (string) get_option( self::LOCK_OPTION, '' );
                $parts = explode( '|', $held, 2 );

                return (int) $parts[0];
        }

        /**
         * Rilascio del lock SOLO se siamo ancora noi l'holder: se il nostro TTL
         * è scaduto e un altro processo ha ripreso il mutex, NON cancelliamo il
         * lock altrui. Nessun errore se il lock è già sparito.
         *
         * @return void
         */
        private static function release_refresh_lock() {
                if ( '' === self::$refresh_lock_id ) {
                        return;
                }

                $suffix = '|' . self::$refresh_lock_id;
                $held   = (string) get_option( self::LOCK_OPTION, '' );

                if ( '' !== $held && substr( $held, -strlen( $suffix ) ) === $suffix ) {
                        delete_option( self::LOCK_OPTION );
                }

                self::$refresh_lock_id = '';
        }

        /**
         * Token valido da un payload (o '' se il payload è assente/non valido o
         * il token è scaduto): stessa regola di validità del fast-path di
         * get_access_token (margine EXPIRY_SLACK di 60s).
         *
         * @param array|mixed $payload Payload dell'option (o null).
         * @return string
         */
        private static function valid_access_token_from( $payload ) {
                if ( ! is_array( $payload ) ) {
                        return '';
                }

                $access  = isset( $payload['access_token'] ) ? trim( (string) $payload['access_token'] ) : '';
                $expires = isset( $payload['expires_at'] ) ? (int) $payload['expires_at'] : 0;

                if ( '' !== $access && $expires - self::EXPIRY_SLACK > time() ) {
                        return $access;
                }

                return '';
        }

        /**
         * Attesa limitata (max LOCK_WAIT_SECONDS) del processo che NON ha
         * ottenuto il lock: poll ogni 250ms su payload e lock. Ritorna il token
         * fresco appena l'altro processo completa il rinnovo, '' se l'attesa
         * scade o il lock viene liberato senza rinnovo (il chiamante riprova
         * l'acquisizione).
         *
         * @return string
         */
        private static function wait_fresh_access_token() {
                $deadline = microtime( true ) + (float) self::LOCK_WAIT_SECONDS;

                while ( microtime( true ) < $deadline ) {
                        usleep( self::LOCK_POLL_MICRO );

                        $token = self::valid_access_token_from( self::load_payload() );

                        if ( '' !== $token ) {
                                return $token;
                        }

                        if ( self::lock_expires_at() < time() ) {
                                break; // lock liberato o scaduto: riprova nel chiamante.
                        }
                }

                return '';
        }

        /* ============ HELPER PRIVATI (settings, log, redirect, guardie) ============ */

        /**
         * Impostazioni del plugin con guardia class_exists/method_exists.
         *
         * v1.1.13.1 (Q4): delega all'helper condiviso Nexus_Dedup::settings_guarded()
         * (semantica identica: guardie class_exists/method_exists su Nexus_DB,
         * ritorna sempre un array). Il modulo è richiesto dal bootstrap PRIMA di
         * class-nexus-dedup.php: il corpo storico resta SOLO come fallback per un
         * eventuale caricamento senza Nexus_Dedup.
         *
         * @return array
         */
        private static function plugin_settings() {
                if ( class_exists( 'Nexus_Dedup' ) && method_exists( 'Nexus_Dedup', 'settings_guarded' ) ) {
                        return Nexus_Dedup::settings_guarded();
                }

                // Fallback locale (caricamento senza Nexus_Dedup): comportamento storico.
                if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'get_settings' ) ) {
                        $settings = Nexus_DB::get_settings();

                        return is_array( $settings ) ? $settings : array();
                }

                return array();
        }

        /**
         * Log attività best-effort nella tabella activity_logs di Nexus.
         *
         * @param string $detail Descrizione dell'esito (italiano plain, mai segreti).
         * @param string $status 'success'|'error'|'warning'|'info'.
         * @return void
         */
        private static function log_activity( $detail, $status ) {
                try {
                        if ( class_exists( 'Nexus_DB' ) && method_exists( 'Nexus_DB', 'insert_activity' ) ) {
                                Nexus_DB::insert_activity( 0, 'gsc_oauth', $detail, $status );
                        }
                } catch ( Exception $e ) {
                        // Best-effort: mai interrompere il flusso per un errore di log.
                } catch ( Throwable $e ) {
                        // Idem per errori PHP 7+.
                }
        }

        /**
         * Guardia comune agli admin-post: capability e (opzionale) nonce da $_GET.
         *
         * @param string|null $nonce_action Azione nonce da verificare su
         *                                  $_GET['_wpnonce']; null = solo capability
         *                                  (usato dal callback, protetto dallo state).
         * @return void
         */
        private static function guard_admin( $nonce_action ) {
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_die( 'Permesso negato: serve la capability manage_options.' );
                }

                if ( null !== $nonce_action ) {
                        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ) : '';

                        if ( '' === $nonce || false === wp_verify_nonce( $nonce, $nonce_action ) ) {
                                wp_die( 'Nonce di sicurezza non valido o scaduto: riparti dalla pagina Impostazioni.' );
                        }
                }
        }

        /**
         * Redirect standard verso la pagina Impostazioni con l'esito OAuth.
         *
         * @param string $outcome 'ok'|'err'|'disconnected'.
         * @param string $reason  Chiave breve dell'errore (sanitize_key, mai dettagli token).
         * @return void
         */
        private static function redirect_settings( $outcome, $reason = '' ) {
                $args = array(
                        'nexus_gsc_oauth' => (string) $outcome,
                );

                if ( '' !== (string) $reason ) {
                        $args['reason'] = sanitize_key( (string) $reason );
                }

                wp_redirect( add_query_arg( $args, admin_url( 'admin.php?page=nexus-settings' ) ) );
                exit;
        }

        /**
         * Scopes richiesti come stringa separata da spazi (formato OAuth).
         *
         * @return string
         */
        private static function default_scopes() {
                return self::SCOPE_GSC . ' ' . self::SCOPE_GA4;
        }

        /* ============ STATE OAUTH (doppio binding CSRF) ============ */

        /**
         * Costruisce il parametro state: nonce utente + '|' + host breve del sito.
         *
         * @return string
         */
        private static function create_state() {
                return wp_create_nonce( self::NONCE_STATE ) . '|' . self::short_site_url();
        }

        /**
         * Verifica il parametro state di ritorno: nonce valido e host coincidente.
         *
         * @param string $state Valore ricevuto da Google in $_GET['state'].
         * @return bool
         */
        private static function verify_state( $state ) {
                $state = trim( (string) $state );

                if ( '' === $state || false === strpos( $state, '|' ) ) {
                        return false;
                }

                $parts = explode( '|', $state, 2 );

                // wp_verify_nonce ritorna 1/2 (tick precedente) o false.
                $check = wp_verify_nonce( $parts[0], self::NONCE_STATE );

                if ( 1 !== $check && 2 !== $check ) {
                        return false;
                }

                $expected = self::short_site_url();
                $given    = trim( (string) $parts[1] );

                return '' !== $expected && '' !== $given && 0 === strcasecmp( $expected, $given );
        }

        /**
         * Host breve del sito (da home_url): componente sito del parametro state.
         *
         * @return string
         */
        private static function short_site_url() {
                return strtolower( trim( (string) parse_url( home_url(), PHP_URL_HOST ) ) );
        }

        /* ============ PERSISTENZA TOKEN (cifratura AES-256-GCM, v1.1.15 WC5a) ============ */

        /**
         * Chiave di cifratura: HMAC-SHA256 binario (32 byte) su wp_salt('auth').
         *
         * @return string
         */
        private static function cipher_key() {
                return hash_hmac( 'sha256', self::CIPHER_CONTEXT, wp_salt( 'auth' ), true );
        }

        /**
         * openssl è utilizzabile (estensione caricata + funzioni richieste)?
         *
         * @return bool
         */
        private static function openssl_available() {
                return extension_loaded( 'openssl' ) && function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
        }

        /**
         * Salva il payload del token cifrato nell'option (autoload off).
         *
         * v1.1.18 (AL-15) FAIL-CLOSED: senza openssl (o se la cifratura fallisce)
         * il payload NON viene salvato in chiaro — ritorna false lasciando
         * intatto il valore precedente dell'option, arma il notice admin
         * persistente e scrive il log di warning. I chiamanti (flusso di
         * connessione e rinnovo) gestiscono false come errore esplicito.
         *
         * @param string $access_token  Access token Google.
         * @param string $refresh_token Refresh token ('' se non ancora ricevuto).
         * @param int    $expires_in    Durata in secondi (da Google).
         * @param string $scopes        Scopes concessi (stringa separata da spazi).
         * @return bool True se salvato CIFRATO, false se il salvataggio è stato rifiutato.
         */
        private static function save_payload( $access_token, $refresh_token, $expires_in, $scopes ) {
                $payload = array(
                        'access_token'  => (string) $access_token,
                        'refresh_token' => (string) $refresh_token,
                        'expires_at'    => time() + max( 60, (int) $expires_in ),
                        'scopes'        => (string) $scopes,
                        'created_at'    => time(),
                );

                $cipher = self::encrypt_payload( (string) wp_json_encode( $payload ) );

                if ( ! is_string( $cipher ) || '' === $cipher ) {
                        self::mark_plaintext_notice();

                        return false;
                }

                update_option( self::OPTION_TOKEN, $cipher, false );

                return true;
        }

        /**
         * Cifra il payload JSON (v1.1.15, WC5a): AES-256-GCM, IV random a ogni
         * messaggio, output CIPHER_MARKER_GCM . base64( IV(12) . TAG(16) .
         * ciphertext ). Il TAG di autenticazione dà integrità: un payload
         * manipolato non viene decifrato (CBC senza MAC lo accettava).
         *
         * Ogni salvataggio (refresh o reconnect) riscrive così il token anche se
         * era NEXGSC1 (CBC legacy): migrazione automatica al primo uso.
         *
         * v1.1.18 (AL-15) FAIL-CLOSED: nessun fallback in chiaro — se openssl non
         * è disponibile o la cifratura fallisce ritorna null e il chiamante NON
         * deve persistere il payload (il vecchio fallback "JSON in chiaro + log"
         * delle versioni <= 1.1.17 è stato rimosso; resta il notice admin).
         *
         * @param string $json Payload JSON del token.
         * @return string|null Valore cifrato da persistere, null se la cifratura
         *                     sicura non è possibile.
         */
        private static function encrypt_payload( $json ) {
                if ( ! self::openssl_available() ) {
                        self::log_activity( 'Estensione openssl assente: salvataggio del token RIFIUTATO (nessun token in chiaro, v1.1.18 AL-15)', 'warning' );

                        return null;
                }

                $iv = openssl_random_pseudo_bytes( self::GCM_IV_BYTES );

                if ( ! is_string( $iv ) || self::GCM_IV_BYTES !== strlen( $iv ) ) {
                        self::log_activity( 'Generazione IV fallita: salvataggio del token RIFIUTATO (nessun token in chiaro, v1.1.18 AL-15)', 'warning' );

                        return null;
                }

                // GCM: $tag by-ref (PHP 7.1+), tag di autenticazione a 128 bit.
                $tag    = '';
                $cipher = openssl_encrypt( $json, self::CIPHER_METHOD_GCM, self::cipher_key(), OPENSSL_RAW_DATA, $iv, $tag );

                if ( false === $cipher || '' === $cipher || ! is_string( $tag ) || self::GCM_TAG_BYTES !== strlen( $tag ) ) {
                        self::log_activity( 'Cifratura token fallita: salvataggio RIFIUTATO (nessun token in chiaro, v1.1.18 AL-15)', 'warning' );

                        return null;
                }

                return self::CIPHER_MARKER_GCM . base64_encode( $iv . $tag . $cipher );
        }

        /**
         * Legge e decifra il payload dell'option del token.
         *
         * Riconosce tre formati (v1.1.15, WC5a):
         *  - CIPHER_MARKER_GCM 'NEXGSC2:' (corrente): base64( IV(12) . TAG(16) . ciphertext ),
         *    AES-256-GCM — un tag mismatch (payload manomesso o corrotto) → null;
         *  - CIPHER_MARKER 'NEXGSC1:' (LEGACY, sola lettura): base64( IV(16) .
         *    ciphertext ), AES-256-CBC delle versioni precedenti;
         *  - JSON in chiaro: SOLO token legacy di installazioni <= 1.1.17 (host
         *    senza openssl): resta leggibile, MA (v1.1.18, AL-15) con openssl
         *    disponibile viene ri-cifrato al primo caricamento (migrazione) e
         *    senza openssl arma il notice admin persistente.
         * Qualunque problema (option corrotta, openssl assente su valore cifrato,
         * taglio/garbage) → null, senza eccezioni.
         *
         * @return array|null Payload {access_token, refresh_token, expires_at, scopes, created_at}.
         */
        private static function load_payload() {
                $stored = get_option( self::OPTION_TOKEN, '' );

                if ( ! is_string( $stored ) || '' === $stored ) {
                        return null;
                }

                // Formato corrente GCM: richiede openssl (senza → token inaccessibile, in modo sicuro).
                if ( 0 === strpos( $stored, self::CIPHER_MARKER_GCM ) ) {
                        if ( ! self::openssl_available() ) {
                                return null;
                        }

                        $raw = base64_decode( substr( $stored, strlen( self::CIPHER_MARKER_GCM ) ), true );

                        if ( ! is_string( $raw ) || strlen( $raw ) <= ( self::GCM_IV_BYTES + self::GCM_TAG_BYTES ) ) {
                                return null;
                        }

                        $iv     = substr( $raw, 0, self::GCM_IV_BYTES );
                        $tag    = substr( $raw, self::GCM_IV_BYTES, self::GCM_TAG_BYTES );
                        $cipher = substr( $raw, self::GCM_IV_BYTES + self::GCM_TAG_BYTES );
                        $json   = openssl_decrypt( $cipher, self::CIPHER_METHOD_GCM, self::cipher_key(), OPENSSL_RAW_DATA, $iv, $tag );

                        if ( false === $json || '' === $json ) {
                                return null; // tag mismatch: payload manomesso o corrotto.
                        }

                        $payload = json_decode( $json, true );

                        return is_array( $payload ) ? $payload : null;
                }

                // LEGACY (v1.1.9-1.1.14): CBC senza MAC, sola lettura; il prossimo
                // salvataggio riscrive il token in GCM.
                if ( 0 === strpos( $stored, self::CIPHER_MARKER ) ) {
                        if ( ! self::openssl_available() ) {
                                return null;
                        }

                        $raw = base64_decode( substr( $stored, strlen( self::CIPHER_MARKER ) ), true );

                        if ( ! is_string( $raw ) || strlen( $raw ) <= 16 ) {
                                return null;
                        }

                        $iv     = substr( $raw, 0, 16 );
                        $cipher = substr( $raw, 16 );
                        $json   = openssl_decrypt( $cipher, self::CIPHER_METHOD, self::cipher_key(), OPENSSL_RAW_DATA, $iv );

                        if ( false === $json || '' === $json ) {
                                return null;
                        }

                        $payload = json_decode( $json, true );

                        return is_array( $payload ) ? $payload : null;
                }

                // Token in chiaro LEGACY (installazioni <= 1.1.17 su host senza
                // openssl): resta LEGGIBILE (mai lock-out sui dati esistenti).
                // v1.1.18 (AL-15): con openssl disponibile → ri-cifratura al primo
                // successo; senza openssl → notice admin persistente in dashboard.
                $payload = json_decode( $stored, true );

                if ( ! is_array( $payload ) ) {
                        return null;
                }

                if ( self::openssl_available() ) {
                        self::migrate_plaintext_payload( $payload );
                } else {
                        self::mark_plaintext_notice();
                }

                return $payload;
        }

        /**
         * v1.1.18 (AL-15): ri-cifra un token legacy salvato in chiaro (il
         * chiamante ha già verificato openssl_available()). Scrittura idempotente:
         * al successo l'option diventa NEXGSC2 e il notice viene rimosso; un
         * eventuale doppio migrate concorrente produce comunque un payload GCM
         * valido (stesso contenuto, IV diversi).
         *
         * @param array $payload Payload già decodificato dall'option.
         * @return bool True se l'option è stata riscritta cifrata.
         */
        private static function migrate_plaintext_payload( $payload ) {
                $json = wp_json_encode( $payload );

                if ( ! is_string( $json ) || '' === $json ) {
                        return false;
                }

                $cipher = self::encrypt_payload( $json );

                if ( ! is_string( $cipher ) || '' === $cipher ) {
                        return false; // cifratura fallita: il chiaro resta, log già scritto.
                }

                update_option( self::OPTION_TOKEN, $cipher, false );
                self::clear_plaintext_notice();
                self::log_activity( 'Token Google salvato in chiaro da versioni precedenti ri-cifrato (openssl disponibile)', 'success' );

                return true;
        }

        /**
         * v1.1.18 (AL-15): arma il notice admin persistente "openssl richiesto /
         * token in chiaro". Idempotente: guardia per-request + add_option (che
         * non sovrascrive un flag già presente).
         *
         * @return void
         */
        private static function mark_plaintext_notice() {
                if ( self::$plaintext_notice_requested ) {
                        return;
                }

                self::$plaintext_notice_requested = true;

                if ( false === get_option( self::OPTION_PLAINTEXT_NOTICE, false ) ) {
                        add_option( self::OPTION_PLAINTEXT_NOTICE, 1, '', 'no' );
                }
        }

        /**
         * v1.1.18 (AL-15): rimuove il notice persistente (ri-cifratura avvenuta o
         * token disconnesso: non c'è più nulla di esposto).
         *
         * @return void
         */
        private static function clear_plaintext_notice() {
                self::$plaintext_notice_requested = false;

                delete_option( self::OPTION_PLAINTEXT_NOTICE );
        }

        /**
         * v1.1.18 (AL-15): notice admin persistente (standard WP admin_notices,
         * solo utenti manage_options) che spiega il requisito openssl. Rimane
         * visibile finché l'estensione non torna disponibile (nessuna chiusura
         * manuale): al ritorno di openssl il flag viene rimosso qui e un
         * eventuale token legacy in chiaro viene ri-cifrato da load_payload.
         *
         * @return void
         */
        public static function render_admin_notice() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        return;
                }

                if ( ! get_option( self::OPTION_PLAINTEXT_NOTICE, false ) ) {
                        return;
                }

                if ( self::openssl_available() ) {
                        // Conflitto risolto: con openssl di nuovo disponibile un
                        // eventuale token in chiaro viene ri-cifrato da load_payload
                        // al primo uso; il flag non serve più (una cifratura fallita
                        // resta tracciata nel log attività).
                        self::clear_plaintext_notice();

                        return;
                }

                echo '<div class="notice notice-warning"><p><strong>Nexus News AI:</strong> ';
                echo esc_html( 'Estensione PHP openssl assente: openssl è richiesto per salvare le credenziali Google in sicurezza. Il collegamento a Search Console/GA4 resta bloccato (nessun token viene salvato in chiaro) e un eventuale token salvato da versioni precedenti è conservato nel database senza cifratura. Attiva l\'estensione openssl, poi ricollega Google dalle Impostazioni: il token verrà cifrato automaticamente (AES-256-GCM).' );
                echo '</p></div>';
        }

        /* ============ HTTP OAUTH (token endpoint) ============ */

        /**
         * Chiamata al token endpoint Google (scambio code o refresh).
         *
         * @param array $params Parametri form-encoded (grant_type, credenziali, ...).
         * @return array|WP_Error Dati decodificati di Google oppure WP_Error breve
         *                        (solo codici d'errore, mai token/credenziali).
         */
        private static function token_request( $params ) {
                $response = wp_remote_post(
                        self::OAUTH_TOKEN_URL,
                        array(
                                'timeout' => self::HTTP_TIMEOUT,
                                'headers' => array(
                                        'Content-Type' => 'application/x-www-form-urlencoded',
                                ),
                                'body'    => (array) $params,
                        )
                );

                if ( is_wp_error( $response ) ) {
                        return new WP_Error( 'nexus_gsc_oauth', 'Connessione a Google non riuscita: ' . $response->get_error_message() );
                }

                $code = (int) wp_remote_retrieve_response_code( $response );
                $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

                if ( ! is_array( $data ) ) {
                        return new WP_Error( 'nexus_gsc_oauth', 'Risposta di Google non interpretabile (HTTP ' . $code . ')' );
                }

                $error = isset( $data['error'] ) ? trim( (string) $data['error'] ) : '';

                if ( '' !== $error ) {
                        return new WP_Error( 'nexus_gsc_oauth', 'Google ha rifiutato la richiesta: ' . sanitize_key( $error ) . ' (HTTP ' . $code . ')' );
                }

                $access = isset( $data['access_token'] ) ? trim( (string) $data['access_token'] ) : '';

                if ( '' === $access ) {
                        return new WP_Error( 'nexus_gsc_oauth', 'Risposta di Google senza access token (HTTP ' . $code . ')' );
                }

                return $data;
        }
}

/* v1.1.12 (S1) — init self-registrato: solo add_action, NESSUNA chiamata di rete. */
add_action( 'nexus_modules_init', array( 'Nexus_Google_Auth', 'init' ) );
