<?php
namespace CUScanner\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use CUScanner\Settings;
use CUScanner\Api\WpserviceClient;

class SettingsAjax {
    public function register(): void {
        add_action( 'wp_ajax_cu_scanner_save_settings', [ $this, 'save_settings' ] );
        add_action( 'wp_ajax_cu_scanner_fetch_balance', [ $this, 'fetch_balance' ] );
        add_action( 'wp_ajax_cu_scanner_ack_cdn', [ $this, 'ack_cdn' ] );
        add_action( 'wp_ajax_cu_scanner_regenerate_secret', [ $this, 'regenerate_secret' ] );
        add_action( 'wp_ajax_cu_scanner_request_free_key', [ $this, 'request_free_key' ] );
        add_action( 'wp_ajax_cu_scanner_replace_key', [ $this, 'replace_key' ] );
    }

    /**
     * Opt-in free-credit registration. Runs only when an administrator clicks
     * "Get free credits", after the Settings screen has said what is sent
     * (this site's domain and the plugin version) and to whom (wpservice.pro).
     */
    public function request_free_key(): void {
        check_ajax_referer( 'cu_scanner_settings_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $settings = new Settings();
        if ( ! \CUScanner\FreeKeyBootstrap::can_request( $settings ) ) {
            wp_send_json_error( __( 'An API key is already saved.', 'dr-speed-ai-assets-scanner' ) );
        }

        $bootstrap = new \CUScanner\FreeKeyBootstrap( $settings );
        $outcome   = $bootstrap->run();

        if ( \CUScanner\FreeKeyBootstrap::OUTCOME_UNUSABLE === $outcome ) {
            wp_send_json_error( self::unusable_free_key_message( $settings->get_free_key_unusable() ) );
        }
        if ( $settings->is_free_key( $settings->get_api_key() ) ) {
            wp_send_json_success();
        }
        wp_send_json_error( self::free_key_failure_message( $bootstrap->last_error() ) );
    }

    /**
     * Says why "Get free credits" failed, using the service's own reply, instead of
     * one message for every failure. The reply is plain text shown with textContent.
     */
    public static function free_key_failure_message( ?\RuntimeException $error ): string {
        $status = $error instanceof \CUScanner\Api\HttpException ? $error->get_status_code() : -1;
        $detail = null === $error ? '' : $error->getMessage();
        if ( function_exists( 'sanitize_text_field' ) ) {
            $detail = sanitize_text_field( $detail );
        }
        if ( strlen( $detail ) > 200 ) {
            $detail = substr( $detail, 0, 200 );
        }
        $detail = rtrim( trim( $detail ), '.' );

        if ( 429 === $status ) {
            return __( 'wpservice.pro refused the request: too many free-key requests for this site in the last hour. Wait an hour, then click Get free credits again.', 'dr-speed-ai-assets-scanner' );
        }
        if ( '' === $detail ) {
            return __( 'The free-credit service did not answer. The plugin will retry in about an hour.', 'dr-speed-ai-assets-scanner' );
        }
        if ( 0 === $status ) {
            /* translators: %s: the connection error, for example a timeout or DNS failure. */
            return sprintf( __( 'Could not reach wpservice.pro (%s). The plugin will retry in about an hour.', 'dr-speed-ai-assets-scanner' ), $detail );
        }
        /* translators: %s: the error returned by wpservice.pro, for example "HTTP 500: Could not allocate a free API key". */
        return sprintf( __( 'wpservice.pro could not issue a free key: %s. The plugin will retry in about an hour. If this keeps happening, contact wpservice.pro support.', 'dr-speed-ai-assets-scanner' ), $detail );
    }

    /** User-facing explanation for a converted or revoked free key. Shared with the Settings screen. */
    public static function unusable_free_key_message( string $status ): string {
        if ( 'revoked' === $status ) {
            return __( 'This site\'s free key has been revoked, so no more free credits can be issued here. Enter a paid API key, or contact wpservice.pro support.', 'dr-speed-ai-assets-scanner' );
        }
        return __( 'This site has already used its free key, and it was upgraded to a paid key. Enter your paid API key instead; you can find it in your wpservice.pro account.', 'dr-speed-ai-assets-scanner' );
    }

    public function regenerate_secret(): void {
        check_ajax_referer( 'cu_scanner_settings_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $secret = ( new Settings() )->regenerate_scanner_secret();
        wp_send_json_success( [ 'secret' => $secret ] );
    }

    public function ack_cdn(): void {
        check_ajax_referer( 'cu_scanner_settings_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        $cdn = sanitize_text_field( wp_unslash( $_POST['cdn'] ?? '' ) );
        ( new \CUScanner\Settings() )->set_acknowledged_cdn( $cdn );
        wp_send_json_success();
    }

    public function save_settings(): void {
        check_ajax_referer( 'cu_scanner_settings_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $settings = new Settings();

        // Checkbox: only its PRESENCE is read, never its value. FormData omits
        // unchecked boxes, so absence means unchecked — hence the unconditional
        // write. A conditional one could turn the option on but never off.
        // Persisted here, before the API-key block, because that block can
        // wp_send_json_error out (no key saved, or authenticate() throwing) and
        // this option has nothing to do with API-key validity.
        $settings->set_omit_cu_bypass( isset( $_POST['omit_cu_bypass'] ) );

        // Once a key is saved, this form never changes it: a new key goes through
        // replace_key(), which accepts paid keys only. Without this, typing a free
        // key into the field and pressing Save would bypass that rule. A pending
        // free-key placeholder is not a real key, so it may still be overwritten.
        $stored = $settings->get_api_key();
        $keep   = ! empty( $_POST['keep_api_key'] ) || ( '' !== $stored && ! $settings->is_pending_free_key( $stored ) );
        if ( $keep ) {
            $api_key = $settings->get_api_key();
        } else {
            $api_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
        }

        if ( '' === $api_key ) {
            wp_send_json_error( 'No API key is saved. Please enter your API key.' );
        }

        $http_user = sanitize_text_field( wp_unslash( $_POST['http_user'] ?? '' ) );
        $http_pass = sanitize_text_field( wp_unslash( $_POST['http_pass'] ?? '' ) );
        if ( $http_user && $http_pass ) {
            $settings->set_http_auth( $http_user, $http_pass );
        } elseif ( isset( $_POST['clear_http_auth'] ) ) {
            $settings->clear_http_auth();
        }

        // Authenticate FIRST, commit the key only after. A submitted key never
        // reaches cu_scanner_api_key until /auth has accepted it, so none of the
        // three ways a bad value arrives can destroy the stored one: the mask
        // this page renders back into the field, an empty submission, or a typo.
        // This handler deliberately knows NOTHING about the mask format (that
        // lives in admin/views/settings-page.php) — all three simply fail to
        // authenticate, which is the whole point of ordering it this way.
        //
        // The asymmetry with the writes above is deliberate, not an oversight:
        // omit_cu_bypass and the HTTP-auth credentials are not validated by
        // authenticate(), so gating them on it would let an unrelated call
        // decide whether they save. The API key is the one write whose validity
        // that call actually decides, so it is the one write that waits.
        //
        // HttpException::get_status_code() is deliberately NOT consulted here.
        // It can distinguish a transport failure (0) from an HTTP rejection, so
        // a "commit anyway, we merely could not reach wpservice.pro" carve-out
        // is available — and is refused. It would re-open the overwrite path
        // under exactly the condition where the user gets no feedback. The
        // accepted residual is that a good key cannot be saved while
        // wpservice.pro is unreachable: recoverable by retry, unlike key loss.
        try {
            $client = new WpserviceClient( AIAS_WPSERVICE_URL, $api_key );
            $auth   = $client->authenticate();
            if ( ! $keep ) {
                $settings->set_api_key( $api_key );
                $settings->clear_free_key_unusable();
            }
            // Guarded like fetch_balance() below. An auth response without
            // railway_url would pass null into set_railway_url( string ), and
            // the resulting TypeError is NOT a RuntimeException — it escapes
            // this catch as an uncaught fatal, and admin/js/settings.js has no
            // .catch() for the 500, so the form silently does nothing. The key
            // HAS authenticated by this point, so an absent railway_url is a
            // success that simply leaves the cached URL untouched.
            $railway_url = self::store_railway_url( $settings, $auth );
            // balance is guarded for the same reason and in the same style as
            // fetch_balance() below: an auth response without it would emit an
            // "undefined array key" warning and put null on the wire, which
            // admin/js/settings.js renders as "Credit balance: null". Not fatal
            // like the railway_url case, but the same defect class, so the two
            // dereferences of $auth are guarded together rather than one each.
            wp_send_json_success( [ 'credits' => $auth['balance'] ?? 0, 'railway_url' => $railway_url ] );
        } catch ( \RuntimeException $e ) {
            wp_send_json_error( $e->getMessage() );
        }
    }

    /**
     * Replace the saved API key with a new PAID key. The Settings screen reaches
     * this only after the user confirmed a warning that credits on the current
     * key are not transferred.
     *
     * Free keys are refused twice: by shape before any request, and by the /auth
     * answer (a paid key returns its account's user_id, a free key returns
     * key_type "free" and user_id 0). As in save_settings(), the new key is
     * committed only after /auth accepted it, so a rejected or unreachable
     * replacement never removes the key that works.
     */
    public function replace_key(): void {
        check_ajax_referer( 'cu_scanner_settings_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $settings = new Settings();
        $new_key  = sanitize_text_field( wp_unslash( $_POST['new_api_key'] ?? '' ) );

        if ( '' === $new_key ) {
            wp_send_json_error( __( 'Enter the new paid API key.', 'dr-speed-ai-assets-scanner' ) );
        }
        if ( $settings->is_free_key( $new_key ) || $settings->is_pending_free_key( $new_key ) ) {
            wp_send_json_error( __( 'Only a paid API key can replace your current key. Free keys cannot be used here.', 'dr-speed-ai-assets-scanner' ) );
        }
        if ( $new_key === $settings->get_api_key() ) {
            wp_send_json_error( __( 'That is already your current API key.', 'dr-speed-ai-assets-scanner' ) );
        }

        try {
            $auth = ( new WpserviceClient( AIAS_WPSERVICE_URL, $new_key ) )->authenticate();
        } catch ( \RuntimeException $e ) {
            wp_send_json_error( $e->getMessage() );
        }

        if ( 'free' === ( $auth['key_type'] ?? '' ) || (int) ( $auth['user_id'] ?? 0 ) < 1 ) {
            wp_send_json_error( __( 'Only a paid API key can replace your current key. Free keys cannot be used here.', 'dr-speed-ai-assets-scanner' ) );
        }

        $settings->set_api_key( $new_key );
        $settings->clear_pending_free_key();
        $settings->clear_free_key_unusable();
        wp_clear_scheduled_hook( 'cu_scanner_free_key_retry' );
        self::store_railway_url( $settings, $auth );

        wp_send_json_success( [ 'credits' => (int) ( $auth['balance'] ?? 0 ) ] );
    }

    /**
     * Cache the worker URL from an /auth answer. Returns what was stored, or ''.
     *
     * An absent value, or one the host allowlist refuses, leaves the cached URL
     * untouched and is not an error: the key has authenticated, and the value
     * came from the service, not the user, so there is nothing for them to fix.
     * Blanked rather than echoed back so the response cannot advertise a URL we
     * just declined to trust.
     */
    private static function store_railway_url( Settings $settings, array $auth ): string {
        $railway_url = ! empty( $auth['railway_url'] ) ? (string) $auth['railway_url'] : '';
        if ( '' === $railway_url ) {
            return '';
        }
        try {
            $settings->set_railway_url( $railway_url );
        } catch ( \RuntimeException $e ) {
            return '';
        }
        return $railway_url;
    }

    public function fetch_balance(): void {
        check_ajax_referer( 'cu_scanner_settings_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );

        $settings = new Settings();
        if ( '' === $settings->get_api_key() ) {
            // No key, no remote call: nothing identifies this site to the service yet.
            wp_send_json_error( __( 'No API key saved.', 'dr-speed-ai-assets-scanner' ) );
        }
        if ( $settings->has_pending_free_key() ) {
            ( new \CUScanner\FreeKeyBootstrap() )->run();
            if ( $settings->has_pending_free_key() ) {
                wp_send_json_error( 'Free API key activation is pending. Please try again later.' );
            }
        }
        try {
            $api_key = $settings->get_api_key();
            $updated = false;

            if ( $settings->is_free_key( $api_key ) ) {
                try {
                    $claim = ( new WpserviceClient( AIAS_WPSERVICE_URL, $api_key ) )
                        ->claim_paid_key( $api_key, $settings->get_paid_key_claim_token() );
                    $claimed_key = sanitize_text_field( (string) ( $claim['api_key'] ?? '' ) );
                    if ( '' !== $claimed_key && ! $settings->is_free_key( $claimed_key ) && ! $settings->is_pending_free_key( $claimed_key ) ) {
                        $settings->set_api_key( $claimed_key );
                        $api_key = $claimed_key;
                        $updated = true;
                    }
                } catch ( \RuntimeException $e ) {
                    // Paid-key claim is best-effort; balance fetch below reports the current key state.
                }
            }

            $client  = new WpserviceClient( AIAS_WPSERVICE_URL, $api_key );
            $balance = $client->get_credits();
            if ( $updated ) {
                $auth = $client->authenticate();
                if ( ! empty( $auth['railway_url'] ) ) {
                    $settings->set_railway_url( $auth['railway_url'] );
                }
                $balance = [ 'balance' => (int) ( $auth['balance'] ?? ( $balance['balance'] ?? 0 ) ) ];
            }
            $balance['api_key_updated'] = $updated;
            wp_send_json_success( $balance );
        } catch ( \RuntimeException $e ) {
            wp_send_json_error( $e->getMessage() );
        }
    }
}
