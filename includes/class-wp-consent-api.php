<?php
/**
 * WP Consent API integration (https://wordpress.org/plugins/wp-consent-api/).
 *
 * Declares this plugin as the site's consent manager, sets the consent type
 * to opt-in, and (from the browser, in rycm-consent.js) calls
 * wp_set_consent() for each WP Consent API category whenever the visitor
 * chooses. Plugins that read the API, such as Site Kit and WooCommerce,
 * then hold back their tracking until the right category is allowed.
 *
 * @package RycmConsent
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

final class Rycm_Consent_Wp_Consent_Api {

	public static function boot(): void {
		add_filter( 'wp_consent_api_registered_' . plugin_basename( RYCM_CONSENT_FILE ), '__return_true' );
		add_filter( 'wp_get_consent_type', array( __CLASS__, 'consent_type' ), 20 );
		add_action( 'plugins_loaded', array( __CLASS__, 'set_type' ), 20 );
	}

	public static function consent_type(): string {
		return 'optin';
	}

	public static function set_type(): void {
		if ( function_exists( 'wp_set_consent_type' ) ) {
			wp_set_consent_type( 'optin' );
		}
	}

	public static function active(): bool {
		return function_exists( 'wp_has_consent' );
	}
}
