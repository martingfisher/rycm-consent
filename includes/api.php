<?php
/**
 * Public functions for themes and other plugins.
 *
 * @package RycmConsent
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'rycm_consent_block_handle' ) ) {
	/**
	 * Hold an enqueued script until the visitor accepts a category.
	 *
	 *   wp_enqueue_script( 'hotjar', 'https://static.hotjar.com/…' );
	 *   rycm_consent_block_handle( 'hotjar', 'analytics' );
	 *
	 * Inline before/after blocks attached to the handle are held too. The same map
	 * can be extended with the `rycm_consent_blocked_handles` filter.
	 *
	 * @param string $handle   Registered script handle.
	 * @param string $category functional, analytics or marketing.
	 */
	function rycm_consent_block_handle( string $handle, string $category ): void {
		Rycm_Consent_Frontend::block_handle( $handle, $category );
	}
}

if ( ! function_exists( 'rycm_consent_wrap' ) ) {
	/**
	 * Return raw script HTML (a pasted vendor snippet, say) held until a category is accepted.
	 *
	 *   echo rycm_consent_wrap( '<script src="https://…"></script>', 'marketing' );
	 */
	function rycm_consent_wrap( string $html, string $category ): string {
		return Rycm_Consent_Core::block_script_tag( $html, $category );
	}
}

if ( ! function_exists( 'rycm_consent_settings_link' ) ) {
	/** Markup for a link that reopens the cookie preferences. */
	function rycm_consent_settings_link( string $text = '' ): string {
		return Rycm_Consent_Shortcodes::settings_link( '' === $text ? array() : array( 'text' => $text ) );
	}
}
