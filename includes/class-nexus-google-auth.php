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
 * - Token nell'option 'nexus_gsc_token' CIFRATO: AES-256-CBC via openssl, chiave
 *   hash_hmac('sha256', 'nexus-gsc-token', wp_salt('auth'), true), IV random a
 *   ogni messaggio, payload JSON {access_token, refresh_token, expires_at,
 *   scopes, created_at} marcato con prefisso 'NEXGSC1:'. Se l'estensione openssl
 *   è assente → degrado DOCUMENTATO: payload JSON in chiaro + insert_activity di
 *   warning (host senza openssl; la lettura di un option cifrato senza openssl
 *   fallisce in modo sicuro e riporta token assente).
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
         * Option WP che contiene il token (cifrato o JSON in chiaro come fallback).
         */
        const OPTION_TOKEN = 'nexus_gsc_token';

        /**
         * Prefisso che marca il valore dell'option come cifrato (AES-256-CBC):
         * tutto ciò che segue è base64( IV . ciphertext ).
         */
        const CIPHER_MARKER = 'NEXGSC1:';

        /**
         * Cifratura del token (valori openssl accettati da PHP 7.4).
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
         * Azioni admin-post (parametro action) e nonce associati.
         */
        const ACTION_AUTHORIZE  = 'nexus_gsc_authorize';
        const ACTION_CALLBACK   = 'nexus_gsc_oauth_callback';
        const ACTION_DISCONNECT = 'nexus_gsc_disconnect';

        const NONCE_AUTHORIZE  = 'nexus_gsc_oauth';
        const NONCE_STATE      = 'nexus_gsc_oauth_state';
        const NONCE_DISCONNECT = 'nexus_gsc_disconnect';

        /**
         * Bootstrap modulo: registra i tre handler admin-post.
         * NON fa chiamate di rete né letture di option.
         *
         * @return void
         */
        public static function init() {
                add_action( 'admin_post_' . self::ACTION_AUTHORIZE, array( __CLASS__, 'handle_authorize' ) );
                add_action( 'admin_post_' . self::ACTION_CALLBACK, array( __CLASS__, 'handle_callback' ) );
                add_action( 'admin_post_' . self::ACTION_DISCONNECT, array( __CLASS__, 'handle_disconnect' ) );
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

                self::save_payload( (string) $data['access_token'], $refresh, $expires, $scopes );

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

                $new_scopes  = isset( $data['scope'] ) ? (string) $data['scope'] : ( isset( $payload['scopes'] ) ? (string) $payload['scopes'] : self::default_scopes() );
                $new_expires = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : self::DEFAULT_EXPIRES_IN;

                self::save_payload( $new_access, $new_refresh, $new_expires, $new_scopes );

                return $new_access;
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
         * @return bool Esito di delete_option (false anche se il token non c'era).
         */
        public static function disconnect() {
                return delete_option( self::OPTION_TOKEN );
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

        /* ============ PERSISTENZA TOKEN (cifratura AES-256-CBC) ============ */

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
         * @param string $access_token  Access token Google.
         * @param string $refresh_token Refresh token ('' se non ancora ricevuto).
         * @param int    $expires_in    Durata in secondi (da Google).
         * @param string $scopes        Scopes concessi (stringa separata da spazi).
         * @return void
         */
        private static function save_payload( $access_token, $refresh_token, $expires_in, $scopes ) {
                $payload = array(
                        'access_token'  => (string) $access_token,
                        'refresh_token' => (string) $refresh_token,
                        'expires_at'    => time() + max( 60, (int) $expires_in ),
                        'scopes'        => (string) $scopes,
                        'created_at'    => time(),
                );

                update_option( self::OPTION_TOKEN, self::encrypt_payload( (string) wp_json_encode( $payload ) ), false );
        }

        /**
         * Cifra il payload JSON: AES-256-CBC, IV random a ogni messaggio, output
         * CIPHER_MARKER . base64( IV . ciphertext ).
         *
         * Fallback DOCUMENTATO (host senza openssl): ritorna il JSON in chiaro con
         * insert_activity di warning; la decifratura di un option cifrato su un
         * host senza openssl fallisce in modo sicuro (token considerato assente).
         *
         * @param string $json Payload JSON del token.
         * @return string Valore da persistere nell'option.
         */
        private static function encrypt_payload( $json ) {
                if ( ! self::openssl_available() ) {
                        self::log_activity( 'Estensione openssl assente: token salvato NON cifrato (degrado documentato)', 'warning' );

                        return $json;
                }

                $iv = openssl_random_pseudo_bytes( 16 );

                if ( ! is_string( $iv ) || 16 !== strlen( $iv ) ) {
                        self::log_activity( 'Generazione IV fallita: token salvato NON cifrato (degrado documentato)', 'warning' );

                        return $json;
                }

                $cipher = openssl_encrypt( $json, self::CIPHER_METHOD, self::cipher_key(), OPENSSL_RAW_DATA, $iv );

                if ( false === $cipher || '' === $cipher ) {
                        self::log_activity( 'Cifratura token fallita: token salvato NON cifrato (degrado documentato)', 'warning' );

                        return $json;
                }

                return self::CIPHER_MARKER . base64_encode( $iv . $cipher );
        }

        /**
         * Legge e decifra il payload dell'option del token.
         *
         * Riconosce il prefisso CIPHER_MARKER (valore cifrato) oppure il JSON in
         * chiaro del fallback. Qualunque problema (option corrotta, openssl
         * assente su valore cifrato, taglio/garbage) → null, senza eccezioni.
         *
         * @return array|null Payload {access_token, refresh_token, expires_at, scopes, created_at}.
         */
        private static function load_payload() {
                $stored = get_option( self::OPTION_TOKEN, '' );

                if ( ! is_string( $stored ) || '' === $stored ) {
                        return null;
                }

                // Valore cifrato: richiede openssl (senza → token inaccessibile, in modo sicuro).
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

                // Fallback in chiaro (host senza openssl): JSON diretto.
                $payload = json_decode( $stored, true );

                return is_array( $payload ) ? $payload : null;
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
