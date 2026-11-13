<?php
/**
 * Updates from GitHub Releases.
 *
 * The plugin header carries `Update URI: https://github.com/martingfisher/rycm-consent`,
 * so WordPress (5.8+) never asks wordpress.org about it and instead fires
 * `update_plugins_github.com`. We answer from the repository's latest
 * Release, offering its attached rycm-consent.zip as a normal update: the
 * Updates screen, the plugin row and auto-updates all work as usual.
 *
 * The repository is public, so no token is involved. The response is cached
 * for six hours to stay well inside GitHub's anonymous rate limit.
 *
 * @package RycmConsent
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

final class Rycm_Consent_Updater {

	public const CACHE = 'rycm_consent_release';

	public static function boot(): void {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear' ), 10, 0 );
	}

	public static function release(): ?array {
		$cached = get_site_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached ?: null;
		}
		$url = (string) apply_filters( 'rycm_consent_release_url', 'https://api.github.com/repos/' . RYCM_CONSENT_REPO . '/releases/latest' );
		$res = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'rycm-consent-updater',
				),
			)
		);
		$data = null;
		if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
			$json = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( is_array( $json ) ) {
				$data = Rycm_Consent_Core::parse_release( $json, RYCM_CONSENT_REPO, plugin_basename( RYCM_CONSENT_FILE ) );
			}
		}
		// Cache failures too (as an empty array) so a GitHub outage does not slow every admin page.
		set_site_transient( self::CACHE, $data ?? array(), $data ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * @param array|false $update
	 * @param array       $plugin_data
	 * @param string      $plugin_file
	 * @return array|false
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( RYCM_CONSENT_FILE ) !== $plugin_file ) {
			return $update;
		}
		$release = self::release();
		return $release ?: $update;
	}

	/** "View details" modal on the Plugins screen. */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || 'rycm-consent' !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$release = self::release();
		if ( ! $release ) {
			return $result;
		}
		return (object) array(
			'name'          => 'RYCM Cookie Consent',
			'slug'          => 'rycm-consent',
			'version'       => $release['version'],
			'author'        => '<a href="https://resultsyoucanmeasure.com">Results You Can Measure</a>',
			'homepage'      => $release['url'],
			'download_link' => $release['package'],
			'last_updated'  => $release['published_at'],
			'requires'      => '6.3',
			'requires_php'  => '7.4',
			'sections'      => array(
				'description' => '<p>UK GDPR and PECR cookie consent for RYCM client sites.</p>',
				'changelog'   => wpautop( esc_html( $release['changelog'] ) ),
			),
		);
	}

	public static function clear(): void {
		delete_site_transient( self::CACHE );
	}
}
