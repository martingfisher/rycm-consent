<?php
/**
 * Pure logic: no database, no hooks, no output.
 *
 * Everything in here can be exercised by tests/run.php without WordPress, so
 * the rules that decide what a visitor sees and what gets recorded are tested
 * in isolation from the plumbing around them.
 *
 * @package RycmConsent
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

final class Rycm_Consent_Core {

	/** The four categories from COOKIE_PRACTICE.md. Keys are fixed: Consent Mode and the WP Consent API map from them. */
	public const CATEGORIES = array( 'necessary', 'functional', 'analytics', 'marketing' );

	/** Categories a visitor can switch on or off. */
	public const OPTIONAL = array( 'functional', 'analytics', 'marketing' );

	/** Google Consent Mode v2 signals each category grants. */
	public const CONSENT_MODE_MAP = array(
		'functional' => array( 'functionality_storage', 'personalization_storage' ),
		'analytics'  => array( 'analytics_storage' ),
		'marketing'  => array( 'ad_storage', 'ad_user_data', 'ad_personalization' ),
	);

	/** WP Consent API category names for each of ours. */
	public const WP_CONSENT_MAP = array(
		'necessary'  => array( 'functional' ),
		'functional' => array( 'preferences' ),
		'analytics'  => array( 'statistics', 'statistics-anonymous' ),
		'marketing'  => array( 'marketing' ),
	);

	/** UK, the EEA (EU 27 plus Iceland, Liechtenstein, Norway) and Switzerland. */
	public const UK_EEA_REGIONS = array(
		'GB', 'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT',
		'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'IS', 'LI', 'NO', 'CH',
	);

	public const LAYOUTS   = array( 'box', 'box wide', 'cloud', 'bar' );
	public const POSITIONS = array( 'bottom left', 'bottom center', 'bottom right', 'middle center', 'top center' );

	/**
	 * Defaults. Wording follows rycm-platform's CookieConsent.astro so Astro and
	 * WordPress sites read the same.
	 */
	public static function defaults(): array {
		return array(
			'policy_version'      => 1,
			'storage_key'         => 'rycm_consent',
			'banner'              => array(
				'title'       => 'Cookies on this site',
				'description' => 'We use a small set of cookies to make this site work. With your permission we also use analytics cookies to see, in aggregate, how the site is being used. None of it is shared or sold. You can change your mind at any time via "Cookie settings".',
				'accept_all'  => 'Accept all',
				'reject_all'  => 'Reject all',
				'customise'   => 'Customise',
				'save'        => 'Save preferences',
				'prefs_title' => 'Cookie preferences',
				'prefs_intro' => 'Choose which cookies you allow. Strictly necessary cookies are always on because the site cannot work without them. Everything else is off until you switch it on.',
				'close_label' => 'Close',
				'reopen_label' => 'Cookie settings',
			),
			'layout'              => 'box',
			'position'            => 'bottom center',
			'disable_interaction' => false,
			'floating_button'     => true,
			'respect_gpc'         => false,
			'colours'             => array(
				'primary'      => '#d6262a',
				'primary_text' => '#ffffff',
				'background'   => '#070630',
				'text'         => '#ffffff',
			),
			'categories'          => array(
				'necessary'  => array(
					'enabled'     => true,
					'title'       => 'Strictly necessary',
					'description' => 'Required for the site to work, including remembering this choice. These cannot be switched off.',
				),
				'functional' => array(
					'enabled'     => false,
					'title'       => 'Preferences',
					'description' => 'Remember choices you make, such as language or region, so you do not have to set them again.',
				),
				'analytics'  => array(
					'enabled'     => true,
					'title'       => 'Analytics',
					'description' => 'Help us see which pages are useful so we can improve them. Counted in aggregate, with no advertising personalisation.',
				),
				'marketing'  => array(
					'enabled'     => true,
					'title'       => 'Marketing',
					'description' => 'Used to measure and personalise advertising. If none are in use, switching this on changes nothing, and we will ask again before adding any.',
				),
			),
			'services'            => array(),
			'ga4_id'              => '',
			'consent_mode'        => true,
			'region_mode'         => 'all',
			'ads_data_redaction'  => true,
			'url_passthrough'     => false,
			'cookie_policy_page'  => 0,
			'privacy_page'        => 0,
			'retention_months'    => 12,
			'delete_on_uninstall' => false,
		);
	}

	/**
	 * Merge stored settings over the defaults, one level deep for the nested groups.
	 */
	public static function merge( array $stored ): array {
		$out = self::defaults();
		foreach ( $stored as $key => $value ) {
			if ( ! array_key_exists( $key, $out ) ) {
				continue;
			}
			if ( 'categories' === $key && is_array( $value ) ) {
				foreach ( self::CATEGORIES as $cat ) {
					if ( isset( $value[ $cat ] ) && is_array( $value[ $cat ] ) ) {
						$out['categories'][ $cat ] = array_merge( $out['categories'][ $cat ], $value[ $cat ] );
					}
				}
			} elseif ( is_array( $out[ $key ] ) && is_array( $value ) && 'services' !== $key ) {
				$out[ $key ] = array_merge( $out[ $key ], $value );
			} else {
				$out[ $key ] = $value;
			}
		}
		$out['categories']['necessary']['enabled'] = true;
		return $out;
	}

	/**
	 * Clean an admin form submission. $current supplies values the form does not post (policy_version).
	 */
	public static function sanitize( array $in, array $current ): array {
		$d   = self::defaults();
		$out = self::merge( $current );

		foreach ( array_keys( $d['banner'] ) as $k ) {
			if ( isset( $in['banner'][ $k ] ) ) {
				$out['banner'][ $k ] = 'description' === $k || 'prefs_intro' === $k
					? self::clean_rich( (string) $in['banner'][ $k ] )
					: self::clean_text( (string) $in['banner'][ $k ] );
				if ( '' === trim( $out['banner'][ $k ] ) ) {
					$out['banner'][ $k ] = $d['banner'][ $k ];
				}
			}
		}

		$out['layout']   = in_array( $in['layout'] ?? '', self::LAYOUTS, true ) ? $in['layout'] : $d['layout'];
		$out['position'] = in_array( $in['position'] ?? '', self::POSITIONS, true ) ? $in['position'] : $d['position'];

		foreach ( array( 'disable_interaction', 'floating_button', 'respect_gpc', 'consent_mode', 'ads_data_redaction', 'url_passthrough', 'delete_on_uninstall' ) as $flag ) {
			$out[ $flag ] = ! empty( $in[ $flag ] );
		}

		foreach ( array_keys( $d['colours'] ) as $k ) {
			$out['colours'][ $k ] = self::hex_colour( (string) ( $in['colours'][ $k ] ?? '' ), $d['colours'][ $k ] );
		}

		foreach ( self::CATEGORIES as $cat ) {
			$row = $in['categories'][ $cat ] ?? array();
			$out['categories'][ $cat ]['enabled'] = 'necessary' === $cat ? true : ! empty( $row['enabled'] );
			foreach ( array( 'title', 'description' ) as $f ) {
				if ( isset( $row[ $f ] ) ) {
					$v = 'title' === $f ? self::clean_text( (string) $row[ $f ] ) : self::clean_rich( (string) $row[ $f ] );
					$out['categories'][ $cat ][ $f ] = '' === trim( $v ) ? $d['categories'][ $cat ][ $f ] : $v;
				}
			}
		}

		$out['services'] = array();
		foreach ( (array) ( $in['services'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$svc = array(
				'name'     => self::clean_text( (string) ( $row['name'] ?? '' ) ),
				'provider' => self::clean_text( (string) ( $row['provider'] ?? '' ) ),
				'purpose'  => self::clean_text( (string) ( $row['purpose'] ?? '' ) ),
				'cookies'  => self::clean_text( (string) ( $row['cookies'] ?? '' ) ),
				'expiry'   => self::clean_text( (string) ( $row['expiry'] ?? '' ) ),
				'category' => in_array( $row['category'] ?? '', self::CATEGORIES, true ) ? $row['category'] : 'necessary',
			);
			if ( '' !== $svc['name'] ) {
				$out['services'][] = $svc;
			}
		}

		$out['ga4_id']      = self::ga4_id( (string) ( $in['ga4_id'] ?? '' ) );
		$out['region_mode'] = in_array( $in['region_mode'] ?? '', array( 'all', 'uk_eea' ), true ) ? $in['region_mode'] : 'all';
		$out['cookie_policy_page'] = max( 0, (int) ( $in['cookie_policy_page'] ?? 0 ) );
		$out['privacy_page']       = max( 0, (int) ( $in['privacy_page'] ?? 0 ) );
		$out['retention_months']   = min( 120, max( 1, (int) ( $in['retention_months'] ?? 12 ) ) );

		$key                = strtolower( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $in['storage_key'] ?? $out['storage_key'] ) ) );
		$out['storage_key'] = '' === $key ? $d['storage_key'] : substr( $key, 0, 64 );

		$out['policy_version'] = max( 1, (int) ( $current['policy_version'] ?? 1 ) );
		return $out;
	}

	public static function clean_text( string $v ): string {
		return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $v ) : trim( strip_tags( $v ) );
	}

	/** Descriptions allow links and emphasis only. */
	public static function clean_rich( string $v ): string {
		$allowed = array(
			'a'      => array( 'href' => true, 'target' => true, 'rel' => true ),
			'strong' => array(),
			'em'     => array(),
			'br'     => array(),
		);
		return function_exists( 'wp_kses' ) ? trim( wp_kses( $v, $allowed ) ) : trim( strip_tags( $v, '<a><strong><em><br>' ) );
	}

	public static function hex_colour( string $v, string $fallback ): string {
		$v = strtolower( trim( $v ) );
		if ( preg_match( '/^#([0-9a-f]{3})$/', $v, $m ) ) {
			$v = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
		}
		return preg_match( '/^#[0-9a-f]{6}$/', $v ) ? $v : $fallback;
	}

	public static function ga4_id( string $v ): string {
		$v = strtoupper( trim( $v ) );
		return preg_match( '/^G-[A-Z0-9]{4,20}$/', $v ) ? $v : '';
	}

	/** WCAG 2.x contrast ratio between two #rrggbb colours. */
	public static function contrast_ratio( string $a, string $b ): float {
		$lum = static function ( string $hex ): float {
			$hex = ltrim( $hex, '#' );
			$c   = array();
			foreach ( array( 0, 2, 4 ) as $i ) {
				$s   = hexdec( substr( $hex, $i, 2 ) ) / 255;
				$c[] = $s <= 0.04045 ? $s / 12.92 : ( ( $s + 0.055 ) / 1.055 ) ** 2.4;
			}
			return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
		};
		$l1 = $lum( $a );
		$l2 = $lum( $b );
		return round( ( max( $l1, $l2 ) + 0.05 ) / ( min( $l1, $l2 ) + 0.05 ), 2 );
	}

	/**
	 * Colour pairs that fail WCAG AA for normal text (4.5:1), as human-readable warnings.
	 *
	 * @return string[]
	 */
	public static function contrast_warnings( array $colours ): array {
		$pairs = array(
			array( 'text', 'background', 'Banner text on banner background' ),
			array( 'primary_text', 'primary', 'Button text on button colour' ),
		);
		$out   = array();
		foreach ( $pairs as list( $fg, $bg, $label ) ) {
			$r = self::contrast_ratio( $colours[ $fg ], $colours[ $bg ] );
			if ( $r < 4.5 ) {
				$out[] = sprintf( '%s is %.2f:1. WCAG AA needs at least 4.5:1.', $label, $r );
			}
		}
		return $out;
	}

	/** Optional categories switched on for this site. */
	public static function active_optional( array $settings ): array {
		$out = array();
		foreach ( self::OPTIONAL as $cat ) {
			if ( ! empty( $settings['categories'][ $cat ]['enabled'] ) ) {
				$out[] = $cat;
			}
		}
		return $out;
	}

	/**
	 * accept / reject / custom, worked out from what was accepted rather than trusted from the browser.
	 */
	public static function derive_action( array $accepted, array $optional ): string {
		$granted = array_values( array_intersect( $optional, $accepted ) );
		if ( array() === $optional || array() === $granted ) {
			return 'reject';
		}
		return count( $granted ) === count( $optional ) ? 'accept' : 'custom';
	}

	/** Keep only known category keys, necessary always present, in canonical order. */
	public static function clean_categories( array $cats, array $optional ): array {
		$out = array( 'necessary' );
		foreach ( $optional as $cat ) {
			if ( in_array( $cat, $cats, true ) ) {
				$out[] = $cat;
			}
		}
		return $out;
	}

	public static function is_uuid( string $v ): bool {
		return (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v );
	}

	/** SHA-256 of the IP and a per-site salt: proves "same visitor" without storing the address. */
	public static function hash_ip( string $ip, string $salt ): string {
		return hash( 'sha256', $salt . '|' . trim( $ip ) );
	}

	/** "Chrome / macOS" style family, never the full user agent string. */
	public static function ua_family( string $ua ): string {
		if ( '' === trim( $ua ) ) {
			return 'Unknown';
		}
		if ( preg_match( '/bot|crawl|spider|slurp|headless|lighthouse|curl|wget|python|httpclient/i', $ua ) ) {
			return 'Bot';
		}
		$browsers = array(
			'/Edg(e|A|iOS)?\//'   => 'Edge',
			'/OPR\/|Opera/'       => 'Opera',
			'/SamsungBrowser/'    => 'Samsung Internet',
			'/Firefox|FxiOS/'     => 'Firefox',
			'/CriOS|Chrome|Chromium/' => 'Chrome',
			'/Safari/'            => 'Safari',
		);
		$browser  = 'Other';
		foreach ( $browsers as $re => $name ) {
			if ( preg_match( $re, $ua ) ) {
				$browser = $name;
				break;
			}
		}
		$systems = array(
			'/Windows/'                 => 'Windows',
			'/Android/'                 => 'Android',
			'/iPhone|iPad|iPod|CPU OS/' => 'iOS',
			'/CrOS/'                    => 'ChromeOS',
			'/Mac OS X|Macintosh/'      => 'macOS',
			'/Linux/'                   => 'Linux',
		);
		$os       = 'Other';
		foreach ( $systems as $re => $name ) {
			if ( preg_match( $re, $ua ) ) {
				$os = $name;
				break;
			}
		}
		return $browser . ' / ' . $os;
	}

	/**
	 * Consent Mode v2 update object for a set of accepted categories. Every
	 * signal is listed so a later, narrower choice also revokes.
	 */
	public static function consent_mode_update( array $accepted ): array {
		$out = array();
		foreach ( self::CONSENT_MODE_MAP as $cat => $signals ) {
			foreach ( $signals as $signal ) {
				$out[ $signal ] = in_array( $cat, $accepted, true ) ? 'granted' : 'denied';
			}
		}
		return $out;
	}

	/**
	 * The `gtag('consent','default',…)` calls for the head, as [args] lists.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function consent_mode_defaults( string $region_mode ): array {
		$denied                     = self::consent_mode_update( array() );
		$denied['security_storage'] = 'granted';
		$denied['wait_for_update']  = 500;
		if ( 'uk_eea' !== $region_mode ) {
			return array( $denied );
		}
		$granted                    = self::consent_mode_update( array( 'functional', 'analytics', 'marketing' ) );
		$granted['security_storage'] = 'granted';
		$regional                   = $denied;
		$regional['region']         = self::UK_EEA_REGIONS;
		// Google applies the most specific region match, so order does not matter; regional first for readability.
		return array( $regional, $granted );
	}

	/**
	 * Cookie name pattern from the admin ("_ga, _ga_*") to the regex source CookieConsent's autoClear gets.
	 *
	 * @return string[]
	 */
	public static function cookie_patterns( string $list ): array {
		$out = array();
		foreach ( preg_split( '/[\s,]+/', $list ) ?: array() as $name ) {
			$name = trim( $name );
			if ( '' === $name || ! preg_match( '/^[A-Za-z0-9_.\-*]+$/', $name ) ) {
				continue;
			}
			$out[] = '^' . str_replace( '\*', '.*', preg_quote( $name, '/' ) ) . '$';
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Turn every <script> in a tag string into one CookieConsent holds until the category is accepted.
	 * Works on WordPress's script_loader_tag output, which can include translations and inline before/after blocks.
	 */
	public static function block_script_tag( string $html, string $category ): string {
		if ( ! in_array( $category, self::OPTIONAL, true ) ) {
			return $html;
		}
		return (string) preg_replace_callback(
			'/<script\b([^>]*)>/i',
			static function ( array $m ) use ( $category ): string {
				$attrs = $m[1];
				if ( preg_match( '/\sdata-category\s*=/i', $attrs ) ) {
					return $m[0];
				}
				$type = '';
				if ( preg_match( '/\stype\s*=\s*(["\']?)([^"\'\s>]+)\1/i', $attrs, $t ) ) {
					$type  = strtolower( $t[2] );
					$attrs = str_replace( $t[0], '', $attrs );
				}
				if ( '' !== $type && ! in_array( $type, array( 'text/javascript', 'module', 'application/javascript' ), true ) ) {
					return $m[0]; // JSON, templates and the like: not executable, leave alone.
				}
				$extra = 'module' === $type ? ' data-type="module"' : '';
				return '<script type="text/plain" data-category="' . $category . '"' . $extra . $attrs . '>';
			},
			$html
		);
	}

	/** Stop a CSV cell being read as a spreadsheet formula. */
	public static function csv_cell( string $v ): string {
		return ( '' !== $v && false !== strpos( "=+-@\t\r", $v[0] ) ) ? "'" . $v : $v;
	}

	/**
	 * Stateless form token. WordPress nonces are tied to the logged-in user and break on cached pages served
	 * to someone else; this one only proves the page came from this site within the last 12 to 24 hours.
	 */
	public static function token( string $secret, int $now ): string {
		return substr( hash_hmac( 'sha256', 'rycm-consent|' . intdiv( $now, 43200 ), $secret ), 0, 24 );
	}

	public static function verify_token( string $token, string $secret, int $now ): bool {
		if ( '' === $token ) {
			return false;
		}
		foreach ( array( $now, $now - 43200 ) as $t ) {
			if ( hash_equals( self::token( $secret, $t ), $token ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Read a GitHub "latest release" payload into the array WordPress's update_plugins_{host} filter expects.
	 * Only accepts a package from this repository's own release downloads.
	 */
	public static function parse_release( array $release, string $repo, string $plugin_file ): ?array {
		$tag     = (string) ( $release['tag_name'] ?? '' );
		$version = ltrim( $tag, 'vV' );
		if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) || ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) {
			return null;
		}
		$prefix  = 'https://github.com/' . $repo . '/releases/download/';
		$package = '';
		foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
			$url = (string) ( $asset['browser_download_url'] ?? '' );
			if ( 'rycm-consent.zip' === ( $asset['name'] ?? '' ) && 0 === strpos( $url, $prefix ) ) {
				$package = $url;
				break;
			}
		}
		if ( '' === $package ) {
			return null;
		}
		return array(
			'id'           => 'https://github.com/' . $repo,
			'slug'         => 'rycm-consent',
			'plugin'       => $plugin_file,
			'version'      => $version,
			'new_version'  => $version,
			'url'          => 'https://github.com/' . $repo,
			'package'      => $package,
			'changelog'    => (string) ( $release['body'] ?? '' ),
			'published_at' => (string) ( $release['published_at'] ?? '' ),
		);
	}
}
