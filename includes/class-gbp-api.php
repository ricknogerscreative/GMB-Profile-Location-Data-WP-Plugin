<?php
/**
 * Google Business Profile API client.
 * Handles OAuth2 token management and all GBP API v1 calls.
 */
defined( 'ABSPATH' ) || exit;

class GBP_API {

	private const TOKEN_URL    = 'https://oauth2.googleapis.com/token';
	private const AUTH_URL     = 'https://accounts.google.com/o/oauth2/v2/auth';
	private const SCOPE        = 'https://www.googleapis.com/auth/business.manage';
	private const ACCT_API     = 'https://mybusinessaccountmanagement.googleapis.com/v1/';
	private const BIZ_API      = 'https://mybusinessbusinessinformation.googleapis.com/v1/';
	private const REVIEW_API   = 'https://mybusiness.googleapis.com/v4/';

	// Fields pulled on each location sync.
	private const LOC_READMASK = 'name,title,phoneNumbers,storefrontAddress,websiteUri,regularHours,specialHours,openInfo,metadata';

	private string $client_id;
	private string $client_secret;
	private ?string $access_token  = null;
	private int    $token_expires  = 0;

	public function __construct() {
		$this->client_id     = get_option( 'gbp_sync_client_id', '' );
		$this->client_secret = get_option( 'gbp_sync_client_secret', '' );
	}

	// -------------------------------------------------------------------------
	// OAuth2
	// -------------------------------------------------------------------------

	public function get_auth_url( string $redirect_uri ): string {
		return self::AUTH_URL . '?' . http_build_query( [
			'client_id'     => $this->client_id,
			'redirect_uri'  => $redirect_uri,
			'response_type' => 'code',
			'scope'         => self::SCOPE,
			'access_type'   => 'offline',
			'prompt'        => 'consent',
		] );
	}

	public function exchange_code( string $code, string $redirect_uri ): bool {
		$response = wp_remote_post( self::TOKEN_URL, [
			'body' => [
				'code'          => $code,
				'client_id'     => $this->client_id,
				'client_secret' => $this->client_secret,
				'redirect_uri'  => $redirect_uri,
				'grant_type'    => 'authorization_code',
			],
		] );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $data['refresh_token'] ) ) {
			return false;
		}

		update_option( 'gbp_sync_refresh_token', $this->encrypt( $data['refresh_token'] ) );
		$this->store_access_token( $data );
		return true;
	}

	private function ensure_access_token(): bool {
		if ( $this->access_token && time() < $this->token_expires - 60 ) {
			return true;
		}

		// Try cached access token first.
		$cached = get_transient( 'gbp_sync_access_token' );
		if ( $cached ) {
			$this->access_token   = $cached['token'];
			$this->token_expires  = $cached['expires'];
			if ( time() < $this->token_expires - 60 ) {
				return true;
			}
		}

		$refresh_token = $this->decrypt( get_option( 'gbp_sync_refresh_token', '' ) );
		if ( ! $refresh_token ) {
			return false;
		}

		$response = wp_remote_post( self::TOKEN_URL, [
			'body' => [
				'refresh_token' => $refresh_token,
				'client_id'     => $this->client_id,
				'client_secret' => $this->client_secret,
				'grant_type'    => 'refresh_token',
			],
		] );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $data['access_token'] ) ) {
			return false;
		}

		$this->store_access_token( $data );
		return true;
	}

	private function store_access_token( array $data ): void {
		$this->access_token  = $data['access_token'];
		$this->token_expires = time() + ( (int) ( $data['expires_in'] ?? 3600 ) );

		set_transient( 'gbp_sync_access_token', [
			'token'   => $this->access_token,
			'expires' => $this->token_expires,
		], $data['expires_in'] ?? 3600 );
	}

	public function is_connected(): bool {
		return ! empty( get_option( 'gbp_sync_refresh_token' ) );
	}

	public function disconnect(): void {
		delete_option( 'gbp_sync_refresh_token' );
		delete_transient( 'gbp_sync_access_token' );
		$this->access_token = null;
	}

	// -------------------------------------------------------------------------
	// API calls
	// -------------------------------------------------------------------------

	public function get_accounts(): array {
		$data = $this->get( self::ACCT_API . 'accounts' );
		error_log( 'GBP Sync get_accounts response: ' . wp_json_encode( $data ) );
		return $data['accounts'] ?? [];
	}

	/**
	 * Get all locations for an account. Handles pagination automatically.
	 */
	public function get_locations( string $account_name ): array {
		$locations  = [];
		$page_token = null;

		do {
			$params = [
				'readMask'  => self::LOC_READMASK,
				'pageSize'  => 100,
			];
			if ( $page_token ) {
				$params['pageToken'] = $page_token;
			}

			$data       = $this->get( self::BIZ_API . $account_name . '/locations', $params );
			$locations  = array_merge( $locations, $data['locations'] ?? [] );
			$page_token = $data['nextPageToken'] ?? null;

		} while ( $page_token );

		return $locations;
	}

	/**
	 * Get single location with full read mask.
	 */
	public function get_location( string $location_name ): array {
		return $this->get( self::BIZ_API . $location_name, [ 'readMask' => self::LOC_READMASK ] );
	}

	/**
	 * Get reviews for a location. Returns up to $max reviews.
	 */
	public function get_reviews( string $location_name, int $max = 50 ): array {
		$reviews    = [];
		$page_token = null;
		$fetched    = 0;

		do {
			$params = [ 'pageSize' => min( 50, $max - $fetched ) ];
			if ( $page_token ) {
				$params['pageToken'] = $page_token;
			}

			// Reviews API still uses v4 format.
			$v4_name    = str_replace( 'accounts/', 'accounts/', $location_name );
			$data       = $this->get( self::REVIEW_API . $v4_name . '/reviews', $params );
			$batch      = $data['reviews'] ?? [];
			$reviews    = array_merge( $reviews, $batch );
			$fetched   += count( $batch );
			$page_token = $data['nextPageToken'] ?? null;

		} while ( $page_token && $fetched < $max );

		return $reviews;
	}

	// -------------------------------------------------------------------------
	// HTTP helpers
	// -------------------------------------------------------------------------

	private function get( string $url, array $params = [] ): array {
		if ( ! $this->ensure_access_token() ) {
			return [];
		}

		if ( $params ) {
			$url = add_query_arg( $params, $url );
		}

		$response = wp_remote_get( $url, [
			'headers' => [ 'Authorization' => 'Bearer ' . $this->access_token ],
			'timeout' => 30,
		] );

		if ( is_wp_error( $response ) ) {
			error_log( 'GBP Sync API error: ' . $response->get_error_message() );
			return [];
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			error_log( 'GBP Sync API HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
			return [];
		}

		return json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
	}

	// -------------------------------------------------------------------------
	// Encryption helpers (AES-256-CBC via openssl)
	// -------------------------------------------------------------------------

	private function encrypt( string $value ): string {
		if ( ! $value ) {
			return '';
		}
		$key = $this->get_encryption_key();
		$iv  = openssl_random_pseudo_bytes( 16 );
		$enc = openssl_encrypt( $value, 'AES-256-CBC', $key, 0, $iv );
		return base64_encode( $iv . $enc );
	}

	private function decrypt( string $value ): string {
		if ( ! $value ) {
			return '';
		}
		try {
			$key  = $this->get_encryption_key();
			$data = base64_decode( $value );
			$iv   = substr( $data, 0, 16 );
			$enc  = substr( $data, 16 );
			return (string) openssl_decrypt( $enc, 'AES-256-CBC', $key, 0, $iv );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	private function get_encryption_key(): string {
		$secret = defined( 'AUTH_KEY' ) ? AUTH_KEY : wp_generate_password( 64, true, true );
		return substr( hash( 'sha256', $secret ), 0, 32 );
	}
}
