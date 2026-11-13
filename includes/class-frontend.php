<?php
/**
 * Everything the visitor's browser gets.
 *
 * Order matters and is the point of this class:
 *   1. wp_head, first: Consent Mode v2 defaults (all denied) and, for a
 *      returning visitor, an immediate update from their stored choice.
 *   2. wp_head: the GA4 tag, held as text/plain until analytics is accepted.
 *   3. Any script handle registered for blocking is rewritten the same way.
 *   4. Footer: CookieConsent and the bootstrap that runs it once the DOM,
 *      including every held script, is in place.
 *
 * @package RycmConsent
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

final class Rycm_Consent_Frontend {

	/** @var array<string,string> handle => category registered through rycm_consent_block_handle(). */
	private static array $blocked = array();

	public static function boot(): void {
		add_action( 'wp_head', array( __CLASS__, 'consent_mode_head' ), -1000 );
		add_action( 'wp_head', array( __CLASS__, 'ga4_tag' ), 5 );
		add_action( 'wp_head', array( __CLASS__, 'cf_beacon' ), 6 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 5 );
		add_filter( 'script_loader_tag', array( __CLASS__, 'filter_script_tag' ), 999, 2 );
		add_action( 'wp_footer', array( __CLASS__, 'reopen_button' ), 5 );
	}

	public static function enabled(): bool {
		$on = ! is_admin() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && ! is_feed();
		return (bool) apply_filters( 'rycm_consent_enabled', $on );
	}

	public static function block_handle( string $handle, string $category ): void {
		self::$blocked[ $handle ] = $category;
	}

	/** @return array<string,string> */
	public static function blocked_handles(): array {
		return (array) apply_filters( 'rycm_consent_blocked_handles', self::$blocked );
	}

	public static function consent_mode_head(): void {
		$s = Rycm_Consent_Plugin::settings();
		if ( ! self::enabled() || ! $s['consent_mode'] ) {
			return;
		}
		$js  = 'window.dataLayer=window.dataLayer||[];window.gtag=window.gtag||function(){window.dataLayer.push(arguments);};';
		foreach ( Rycm_Consent_Core::consent_mode_defaults( $s['region_mode'] ) as $args ) {
			$js .= 'gtag("consent","default",' . wp_json_encode( $args ) . ');';
		}
		$js .= 'gtag("set","ads_data_redaction",' . ( $s['ads_data_redaction'] ? 'true' : 'false' ) . ');';
		$js .= 'gtag("set","url_passthrough",' . ( $s['url_passthrough'] ? 'true' : 'false' ) . ');';
		// Returning visitor: apply their stored choice before any tag reads the defaults.
		$js .= '(function(){try{var r=JSON.parse(localStorage.getItem(' . wp_json_encode( $s['storage_key'] ) . ')||"null");'
			. 'if(!r||r.revision!==' . (int) $s['policy_version'] . '||!(r.expirationTime>Date.now())||!Array.isArray(r.categories))return;'
			. 'var m=' . wp_json_encode( Rycm_Consent_Core::CONSENT_MODE_MAP ) . ',u={};'
			. 'for(var k in m)for(var i=0;i<m[k].length;i++)u[m[k][i]]=r.categories.indexOf(k)>-1?"granted":"denied";'
			. 'gtag("consent","update",u);window.rycmConsentEarly=r.categories;}catch(e){}})();';
		echo "<script data-cfasync=\"false\" data-no-optimize=\"1\" data-no-defer=\"1\" id=\"rycm-consent-mode\">" . $js . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** GA4, held until analytics is accepted. data-src means the browser does not even fetch it before then. */
	public static function ga4_tag(): void {
		$s = Rycm_Consent_Plugin::settings();
		if ( ! self::enabled() || '' === $s['ga4_id'] ) {
			return;
		}
		$id = esc_js( $s['ga4_id'] );
		echo '<script type="text/plain" data-category="analytics" data-service="ga4" async data-src="' . esc_url( 'https://www.googletagmanager.com/gtag/js?id=' . $s['ga4_id'] ) . "\"></script>\n";
		echo '<script type="text/plain" data-category="analytics" data-service="ga4">'
			. 'window.dataLayer=window.dataLayer||[];window.gtag=window.gtag||function(){window.dataLayer.push(arguments);};'
			. 'gtag("js",new Date());gtag("config","' . $id . '");'
			. "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Cloudflare Web Analytics: cookieless aggregate statistics, which PECR (as amended by the
	 * Data (Use and Access) Act 2025) allows without consent provided visitors can object.
	 * So it runs by default, before any choice and after Accept, and stays off once the
	 * visitor has chosen Reject all or switched analytics off. rycm-consent.js starts it
	 * when analytics is allowed later, and reloads the page when it is withdrawn.
	 */
	public static function cf_beacon(): void {
		$s = Rycm_Consent_Plugin::settings();
		if ( ! self::enabled() || '' === $s['cf_beacon_token'] ) {
			return;
		}
		$js = '(function(){var on=true;try{var r=JSON.parse(localStorage.getItem(' . wp_json_encode( $s['storage_key'] ) . ')||"null");'
			. 'if(r&&r.revision===' . (int) $s['policy_version'] . '&&r.expirationTime>Date.now()&&Array.isArray(r.categories))on=r.categories.indexOf("analytics")>-1;}catch(e){}'
			. 'window.rycmCfBeacon=function(){if(window.rycmCfLoaded)return;window.rycmCfLoaded=true;var s=document.createElement("script");s.defer=true;'
			. 's.src="https://static.cloudflareinsights.com/beacon.min.js";s.setAttribute("data-cf-beacon",' . wp_json_encode( wp_json_encode( array( 'token' => $s['cf_beacon_token'] ) ) ) . ');document.head.appendChild(s);};'
			. 'if(on)window.rycmCfBeacon();})();';
		echo '<script data-cfasync="false" data-no-optimize="1" id="rycm-cf-beacon">' . $js . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function filter_script_tag( string $tag, string $handle ): string {
		$map = self::blocked_handles();
		if ( isset( $map[ $handle ] ) && self::enabled() ) {
			return Rycm_Consent_Core::block_script_tag( $tag, $map[ $handle ] );
		}
		return $tag;
	}

	public static function enqueue(): void {
		if ( ! self::enabled() ) {
			return;
		}
		$s    = Rycm_Consent_Plugin::settings();
		$base = plugins_url( 'assets/', RYCM_CONSENT_FILE );

		wp_enqueue_style( 'rycm-cookieconsent', $base . 'vendor/cookieconsent/cookieconsent.css', array(), RYCM_CONSENT_CC_VERSION );
		wp_enqueue_style( 'rycm-consent', $base . 'css/rycm-consent.css', array( 'rycm-cookieconsent' ), RYCM_CONSENT_VERSION );
		wp_add_inline_style( 'rycm-consent', Rycm_Consent_Config::css_vars( $s['colours'] ) );

		wp_enqueue_script( 'rycm-cookieconsent', $base . 'vendor/cookieconsent/cookieconsent.umd.js', array(), RYCM_CONSENT_CC_VERSION, true );
		wp_enqueue_script( 'rycm-consent', $base . 'js/rycm-consent.js', array( 'rycm-cookieconsent' ), RYCM_CONSENT_VERSION, true );

		$data = array(
			'cc'          => Rycm_Consent_Config::build(
				$s,
				Rycm_Consent_Plugin::page_url( (int) $s['cookie_policy_page'] ),
				Rycm_Consent_Plugin::page_url( (int) $s['privacy_page'] )
			),
			'consentMode' => (bool) $s['consent_mode'],
			'cfBeacon'    => '' !== $s['cf_beacon_token'],
			'cmMap'       => Rycm_Consent_Core::CONSENT_MODE_MAP,
			'wpMap'       => Rycm_Consent_Core::WP_CONSENT_MAP,
			'recordUrl'   => esc_url_raw( rest_url( Rycm_Consent_Rest::NS . '/record' ) ),
			'tokenUrl'    => esc_url_raw( rest_url( Rycm_Consent_Rest::NS . '/token' ) ),
			'token'       => Rycm_Consent_Core::token( Rycm_Consent_Plugin::secret(), time() ),
		);
		wp_add_inline_script( 'rycm-consent', 'window.rycmConsentData=' . wp_json_encode( $data ) . ';', 'before' );
	}

	public static function reopen_button(): void {
		$s = Rycm_Consent_Plugin::settings();
		if ( ! self::enabled() || ! $s['floating_button'] ) {
			return;
		}
		$label = esc_attr( $s['banner']['reopen_label'] );
		$side  = false !== strpos( $s['position'], 'right' ) ? 'left' : ( false !== strpos( $s['position'], 'left' ) ? 'right' : 'left' );
		echo '<button type="button" class="rycm-cc-reopen rycm-cc-reopen--' . esc_attr( $side ) . '" data-rycm-cookie-settings aria-label="' . $label . '" title="' . $label . '" hidden>'
			. '<svg aria-hidden="true" focusable="false" width="22" height="22" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5Zm-3.5 7a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Zm6 6a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Zm-7 1a1 1 0 1 1 0 2 1 1 0 0 1 0-2Z"/></svg>'
			. "</button>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
