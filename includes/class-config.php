<?php
/**
 * Builds the CookieConsent v3 configuration from the plugin's settings.
 *
 * Pure: takes settings and two URLs, returns arrays. The bootstrap script
 * (assets/js/rycm-consent.js) adds the callbacks and turns cookie patterns
 * into RegExp objects, since neither survives JSON.
 *
 * @package RycmConsent
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

final class Rycm_Consent_Config {

	private static function e( string $v ): string {
		return htmlspecialchars( $v, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Declared services plus the ones the plugin knows about by itself:
	 * its own consent store, and GA4 when a measurement ID is set.
	 */
	public static function services( array $s ): array {
		$out = array(
			array(
				'name'     => 'Cookie consent',
				'provider' => 'This website',
				'purpose'  => 'Remembers the cookie choices you made, so we do not ask again on every page.',
				'cookies'  => $s['storage_key'] . ' (browser local storage)',
				'expiry'   => '12 months',
				'category' => 'necessary',
				'builtin'  => true,
			),
		);
		if ( '' !== $s['ga4_id'] ) {
			$out[] = array(
				'name'     => 'Google Analytics 4',
				'provider' => 'Google',
				'purpose'  => 'Counts visits and shows which pages are used, in aggregate.',
				'cookies'  => '_ga, _ga_' . substr( $s['ga4_id'], 2 ),
				'expiry'   => '2 years',
				'category' => 'analytics',
				'builtin'  => true,
			);
		}
		if ( '' !== ( $s['cf_beacon_token'] ?? '' ) ) {
			$out[] = array(
				'name'     => 'Cloudflare Web Analytics',
				'provider' => 'Cloudflare',
				'purpose'  => 'Counts page views and page loading speed in total, without cookies and without identifying you. Runs unless you choose Reject all or switch analytics off.',
				'cookies'  => 'None (no cookies or storage)',
				'expiry'   => 'Not stored on your device',
				'category' => 'analytics',
				'builtin'  => true,
			);
		}
		foreach ( $s['services'] as $svc ) {
			$out[] = $svc + array( 'builtin' => false );
		}
		return $out;
	}

	/**
	 * @param array  $s          Merged settings.
	 * @param string $policy_url Cookie policy URL, or ''.
	 * @param string $privacy_url Privacy notice URL, or ''.
	 */
	public static function build( array $s, string $policy_url, string $privacy_url ): array {
		$optional = Rycm_Consent_Core::active_optional( $s );
		$services = self::services( $s );
		$b        = $s['banner'];

		$categories = array(
			'necessary' => array(
				'enabled'  => true,
				'readOnly' => true,
			),
		);
		foreach ( $optional as $cat ) {
			$patterns = array();
			foreach ( $services as $svc ) {
				if ( $svc['category'] === $cat ) {
					$patterns = array_merge( $patterns, Rycm_Consent_Core::cookie_patterns( preg_replace( '/\(.*?\)/', '', $svc['cookies'] ) ) );
				}
			}
			$categories[ $cat ] = array(
				'enabled'   => false,
				'readOnly'  => false,
				'autoClear' => array(
					'cookies'    => array_map(
						static function ( string $p ): array {
							return array( 'pattern' => $p );
						},
						array_values( array_unique( $patterns ) )
					),
					// Scripts already running cannot be unloaded, so a withdrawal reloads the page without them.
					'reloadPage' => true,
				),
			);
		}

		$links = array();
		if ( '' !== $policy_url ) {
			$links[] = '<a href="' . self::e( $policy_url ) . '">Cookie policy</a>';
		}
		if ( '' !== $privacy_url ) {
			$links[] = '<a href="' . self::e( $privacy_url ) . '">Privacy notice</a>';
		}

		$sections = array(
			array(
				'description' => $b['prefs_intro'] . ( $links ? ' ' . implode( ' &middot; ', $links ) : '' ),
			),
		);
		foreach ( array_merge( array( 'necessary' ), $optional ) as $cat ) {
			$rows = array();
			foreach ( $services as $svc ) {
				if ( $svc['category'] !== $cat ) {
					continue;
				}
				$rows[] = array(
					'name'     => self::e( $svc['name'] ),
					'provider' => self::e( $svc['provider'] ),
					'cookies'  => self::e( $svc['cookies'] ),
					'expiry'   => self::e( $svc['expiry'] ),
				);
			}
			$section = array(
				'title'          => self::e( $s['categories'][ $cat ]['title'] ),
				'description'    => $s['categories'][ $cat ]['description'],
				'linkedCategory' => $cat,
			);
			if ( $rows ) {
				$section['cookieTable'] = array(
					'caption' => self::e( $s['categories'][ $cat ]['title'] ) . ' services',
					'headers' => array(
						'name'     => 'Service',
						'provider' => 'Set by',
						'cookies'  => 'Cookies',
						'expiry'   => 'Expires',
					),
					'body'    => $rows,
				);
			}
			$sections[] = $section;
		}

		return array(
			'mode'                   => 'opt-in',
			'revision'               => (int) $s['policy_version'],
			'autoShow'               => true,
			'manageScriptTags'       => true,
			'autoClearCookies'       => true,
			'hideFromBots'           => true,
			'respectGpc'             => (bool) $s['respect_gpc'],
			'disablePageInteraction' => (bool) $s['disable_interaction'],
			'cookie'                 => array(
				'name'             => $s['storage_key'],
				'useLocalStorage'  => true,
				'expiresAfterDays' => 365,
			),
			'guiOptions'             => array(
				'consentModal'     => array(
					'layout'             => $s['layout'],
					'position'           => $s['position'],
					'equalWeightButtons' => true,
					'flipButtons'        => false,
				),
				'preferencesModal' => array(
					'layout'             => 'box',
					'equalWeightButtons' => true,
					'flipButtons'        => false,
				),
			),
			'categories'             => $categories,
			'language'               => array(
				'default'      => 'en',
				'translations' => array(
					'en' => array(
						'consentModal'     => array(
							'title'              => self::e( $b['title'] ),
							'description'        => $b['description'],
							'acceptAllBtn'       => self::e( $b['accept_all'] ),
							'acceptNecessaryBtn' => self::e( $b['reject_all'] ),
							'showPreferencesBtn' => self::e( $b['customise'] ),
							'closeIconLabel'     => '',
							'footer'             => implode( ' ', $links ),
						),
						'preferencesModal' => array(
							'title'              => self::e( $b['prefs_title'] ),
							'acceptAllBtn'       => self::e( $b['accept_all'] ),
							'acceptNecessaryBtn' => self::e( $b['reject_all'] ),
							'savePreferencesBtn' => self::e( $b['save'] ),
							'closeIconLabel'     => self::e( $b['close_label'] ),
							'sections'           => $sections,
						),
					),
				),
			),
		);
	}

	/** CSS custom properties for CookieConsent's theme, from the colour settings. */
	public static function css_vars( array $c ): string {
		$vars = array(
			'--cc-bg'                          => $c['background'],
			'--cc-primary-color'               => $c['text'],
			'--cc-secondary-color'             => $c['text'],
			'--cc-btn-primary-bg'              => $c['primary'],
			'--cc-btn-primary-color'           => $c['primary_text'],
			'--cc-btn-primary-border-color'    => $c['primary'],
			'--cc-btn-primary-hover-bg'        => $c['primary'],
			'--cc-btn-primary-hover-color'     => $c['primary_text'],
			'--cc-btn-primary-hover-border-color' => $c['primary_text'],
			// Equal weight: Reject and Customise look exactly like Accept (ICO, COOKIE_PRACTICE.md rule 2).
			'--cc-btn-secondary-bg'            => $c['primary'],
			'--cc-btn-secondary-color'         => $c['primary_text'],
			'--cc-btn-secondary-border-color'  => $c['primary'],
			'--cc-btn-secondary-hover-bg'      => $c['primary'],
			'--cc-btn-secondary-hover-color'   => $c['primary_text'],
			'--cc-btn-secondary-hover-border-color' => $c['primary_text'],
			'--cc-toggle-on-bg'                => $c['primary'],
			'--cc-link-color'                  => $c['text'],
			'--rycm-cc-focus'                  => $c['text'],
		);
		$out  = '';
		foreach ( $vars as $k => $v ) {
			$out .= $k . ':' . $v . ';';
		}
		return '#cc-main{' . $out . '}.rycm-cc-reopen{--rycm-cc-reopen-bg:' . $c['background'] . ';--rycm-cc-reopen-color:' . $c['text'] . ';}';
	}
}
