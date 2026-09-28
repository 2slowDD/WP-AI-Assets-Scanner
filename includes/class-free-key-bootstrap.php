<?php
namespace CUScanner;

use CUScanner\Api\WpserviceClient;

defined( 'ABSPATH' ) || exit;

class FreeKeyBootstrap {

    /** @var callable|null */
    private $client_factory;

    /** Why the last run() ended 'pending', for the Settings screen to show. */
    private ?\RuntimeException $last_error = null;

    public function __construct(
        private ?Settings $settings = null,
        ?callable $client_factory = null
    ) {
        $this->settings       = $settings ?? new Settings();
        $this->client_factory = $client_factory;
    }

    /** run() outcomes that callers act on. */
    public const OUTCOME_UNUSABLE = 'unusable';

    /**
     * @return string 'stored', 'pending', 'kept' (a paid key is saved) or
     *                self::OUTCOME_UNUSABLE (the service returned this site's
     *                converted or revoked free key; see Settings::get_free_key_unusable()).
     */
    public function run(): string {
        $this->last_error = null;
        $current = $this->settings->get_api_key();
        if ( '' !== $current && ! $this->settings->is_free_key( $current ) && ! $this->settings->is_pending_free_key( $current ) ) {
            return 'kept';
        }

        try {
            $client  = $this->make_client( $current );
            $result  = $client->register_free_key( $current );
            $api_key = (string) ( $result['api_key'] ?? '' );
            if ( function_exists( 'sanitize_text_field' ) ) {
                $sanitized = sanitize_text_field( $api_key );
                $api_key   = is_string( $sanitized ) ? $sanitized : $api_key;
            }

            // The service allows one free key per domain and returns the domain's
            // existing key on every request. If that key was converted to a paid key
            // or revoked, /auth refuses it forever: storing it would leave the site
            // with a dead key, no balance, and an hourly retry that never succeeds.
            $status = (string) ( $result['status'] ?? '' );
            if ( in_array( $status, [ 'converted', 'revoked' ], true ) ) {
                if ( $this->settings->is_pending_free_key( $current ) ) {
                    $this->settings->set_api_key( '' );
                    $this->settings->clear_pending_free_key();
                }
                $this->settings->set_free_key_unusable( $status );
                if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
                    wp_clear_scheduled_hook( 'cu_scanner_free_key_retry' );
                }
                return self::OUTCOME_UNUSABLE;
            }

            if ( $this->settings->is_free_key( $api_key ) ) {
                $this->settings->set_api_key( $api_key );
                $this->settings->clear_pending_free_key();
                $this->settings->clear_free_key_unusable();
                try {
                    $this->cache_railway_url( $api_key );
                } catch ( \RuntimeException $e ) {
                    self::schedule_retry();
                }
                return 'stored';
            }
            $this->last_error = new \RuntimeException( 'The service replied without a free API key.' );
            return 'pending';
        } catch ( \RuntimeException $e ) {
            $this->last_error = $e;
            if ( '' === $current ) {
                $this->settings->set_pending_free_key();
            }
            self::schedule_retry();
            return 'pending';
        }
    }

    /**
     * The failure behind the last 'pending' outcome, or null. An Api\HttpException
     * carries the HTTP status (0 = the service could not be reached).
     */
    public function last_error(): ?\RuntimeException {
        return $this->last_error;
    }

    public static function schedule_retry(): void {
        if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_single_event' ) && ! wp_next_scheduled( 'cu_scanner_free_key_retry' ) ) {
            wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'cu_scanner_free_key_retry' );
        }
    }

    /**
     * Whether the "Get free credits" opt-in can be offered: no key saved yet, or an
     * earlier opt-in is still waiting for the service. Never true for a real key.
     */
    public static function can_request( Settings $settings ): bool {
        $current = $settings->get_api_key();
        return '' === $current || $settings->is_pending_free_key( $current );
    }

    private function make_client( string $current_key ): object {
        if ( $this->client_factory ) {
            return ( $this->client_factory )( $current_key );
        }
        return new WpserviceClient( AIAS_WPSERVICE_URL, $current_key );
    }

    private function cache_railway_url( string $api_key ): void {
        $client = $this->make_client( $api_key );
        if ( ! method_exists( $client, 'authenticate' ) ) {
            return;
        }

        $auth        = $client->authenticate();
        $railway_url = (string) ( $auth['railway_url'] ?? '' );
        if ( '' === $railway_url ) {
            throw new \RuntimeException( 'SaaS auth response did not include Railway URL.' );
        }

        $this->settings->set_railway_url( $railway_url );
    }
}
