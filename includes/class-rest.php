<?php
/**
 * REST routes the banner calls.
 *
 *   POST /wp-json/rycm-consent/v1/record  write one consent record
 *   GET  /wp-json/rycm-consent/v1/token   fresh token for a page served from a cache older than the token
 *
 * Guards on the write: a signed token printed into the page, a same-origin
 * check, and a per-visitor rate limit.
 *
 * @package RycmConsent
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

final class Rycm_Consent_Rest {

	public const NS         = 'rycm-consent/v1';
	public const RATE_LIMIT = 30;  // writes per window per visitor
	public const RATE_WINDOW = 600; // seconds

	public static function boot(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			self::NS,
			'/record',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'record' ),
				'permission_callback' => array( __CLASS__, 'can_record' ),
				'args'                => array(
					'consent_id' => array( 'type' => 'string', 'required' => true ),
					'categories' => array( 'type' => 'array', 'required' => true, 'items' => array( 'type' => 'string' ) ),
					'revision'   => array( 'type' => 'integer', 'required' => true ),
					'token'      => array( 'type' => 'string', 'required' => true ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/token',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'token' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function client_ip(): string {
		// Cloudflare fronts most RYCM sites; without its header every visitor would share Cloudflare's address.
		$ip = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? (string) $_SERVER['HTTP_CF_CONNECTING_IP'] : (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$ip = filter_var( trim( $ip ), FILTER_VALIDATE_IP ) ? trim( $ip ) : '';
		return (string) apply_filters( 'rycm_consent_client_ip', $ip );
	}

	public static function same_origin( WP_REST_Request $req ): bool {
		$origin = (string) $req->get_header( 'origin' );
		if ( '' === $origin ) {
			return true; // Same-origin fetches from older browsers omit it; the token still applies.
		}
		$host = wp_parse_url( $origin, PHP_URL_HOST );
		return in_array( $host, array( wp_parse_url( home_url(), PHP_URL_HOST ), wp_parse_url( site_url(), PHP_URL_HOST ) ), true );
	}

	/** @return true|WP_Error */
	public static function can_record( WP_REST_Request $req ) {
		if ( ! self::same_origin( $req ) ) {
			return new WP_Error( 'rycm_consent_origin', 'Cross-origin request refused.', array( 'status' => 403 ) );
		}
		if ( ! Rycm_Consent_Core::verify_token( (string) $req->get_param( 'token' ), Rycm_Consent_Plugin::secret(), time() ) ) {
			return new WP_Error( 'rycm_consent_token', 'Token expired or invalid.', array( 'status' => 403 ) );
		}
		$key   = 'rycm_cr_' . substr( Rycm_Consent_Core::hash_ip( self::client_ip(), Rycm_Consent_Plugin::secret() ), 0, 32 );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT ) {
			return new WP_Error( 'rycm_consent_rate', 'Too many consent records from this address. Try again later.', array( 'status' => 429 ) );
		}
		set_transient( $key, $count + 1, self::RATE_WINDOW );
		return true;
	}

	public static function record( WP_REST_Request $req ): WP_REST_Response {
		$settings   = Rycm_Consent_Plugin::settings();
		$consent_id = (string) $req->get_param( 'consent_id' );
		$revision   = (int) $req->get_param( 'revision' );
		if ( ! Rycm_Consent_Core::is_uuid( $consent_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'bad_consent_id' ), 400 );
		}
		if ( $revision < 1 || $revision > (int) $settings['policy_version'] ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'bad_revision' ), 400 );
		}
		$optional   = Rycm_Consent_Core::active_optional( $settings );
		$categories = Rycm_Consent_Core::clean_categories( array_map( 'strval', (array) $req->get_param( 'categories' ) ), $optional );
		$action     = Rycm_Consent_Core::derive_action( $categories, $optional );
		$ok         = Rycm_Consent_Records::insert(
			$consent_id,
			$action,
			$categories,
			$revision,
			Rycm_Consent_Core::hash_ip( self::client_ip(), Rycm_Consent_Plugin::secret() ),
			Rycm_Consent_Core::ua_family( (string) $req->get_header( 'user_agent' ) )
		);
		do_action( 'rycm_consent_recorded', $consent_id, $action, $categories, $revision );
		$res = new WP_REST_Response( array( 'ok' => $ok, 'action' => $action ), $ok ? 201 : 500 );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	public static function token(): WP_REST_Response {
		$res = new WP_REST_Response( array( 'token' => Rycm_Consent_Core::token( Rycm_Consent_Plugin::secret(), time() ) ) );
		$res->header( 'Cache-Control', 'no-store, max-age=0' );
		return $res;
	}
}
