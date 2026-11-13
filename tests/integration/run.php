<?php
/**
 * Integration suite: runs inside a real WordPress with the plugin active.
 *
 *   bin/setup-test-wp.sh <dir>
 *   cd <dir>/core && php <dir>/wp-cli.phar --allow-root eval-file <plugin>/tests/integration/run.php
 *
 * Only for throwaway installs: it rewrites settings and the records table.
 *
 * @package RycmConsent
 */

if ( ! defined( 'RYCM_CONSENT_TEST_SITE' ) ) {
	fwrite( STDERR, "Refusing to run: RYCM_CONSENT_TEST_SITE is not defined (throwaway installs only).\n" );
	exit( 1 );
}

$pass = 0;
$fail = array();
$eq   = static function ( $expected, $actual, string $what ) use ( &$pass, &$fail ): void {
	if ( $expected === $actual ) {
		$pass++;
	} else {
		$fail[] = $what . "\n    expected: " . var_export( $expected, true ) . "\n    actual:   " . var_export( $actual, true );
	}
};
$ok   = static function ( bool $c, string $what ) use ( $eq ): void {
	$eq( true, $c, $what );
};

$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';

global $wpdb;
$table = Rycm_Consent_Records::table();
$wpdb->query( "DELETE FROM {$table}" );
delete_option( Rycm_Consent_Plugin::OPTION );
add_option( Rycm_Consent_Plugin::OPTION, Rycm_Consent_Core::defaults() );
Rycm_Consent_Plugin::flush();

foreach ( array( '203.0.113.7', '198.51.100.99' ) as $ip ) {
	delete_transient( 'rycm_cr_' . substr( Rycm_Consent_Core::hash_ip( $ip, Rycm_Consent_Plugin::secret() ), 0, 32 ) );
}

// ---- Install
$ok( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), 'records table exists' );
$ok( (bool) wp_next_scheduled( Rycm_Consent_Plugin::CRON ), 'daily purge scheduled' );
$ok( strlen( Rycm_Consent_Plugin::secret() ) >= 32, 'site secret generated' );

// ---- Settings through the real register_setting sanitiser (wp_kses)
wp_set_current_user( 1 );
Rycm_Consent_Admin::register();
update_option(
	Rycm_Consent_Plugin::OPTION,
	array(
		'banner'      => array( 'description' => 'Read <a href="/c" onclick="x()">this</a><script>alert(1)</script>' ),
		'ga4_id'      => 'G-TEST1234',
		'consent_mode' => '1',
		'ads_data_redaction' => '1',
		'floating_button' => '1',
		'categories'  => array( 'analytics' => array( 'enabled' => '1' ), 'marketing' => array( 'enabled' => '1' ) ),
		'services'    => array( array( 'name' => 'Hotjar', 'provider' => 'Hotjar Ltd', 'purpose' => 'Heatmaps', 'cookies' => '_hj*', 'expiry' => '1 year', 'category' => 'analytics' ) ),
		'policy_version' => 50,
	)
);
$s = Rycm_Consent_Plugin::settings();
$ok( false === strpos( $s['banner']['description'], '<script' ) && false === strpos( $s['banner']['description'], 'onclick' ), 'wp_kses strips script and handlers' );
$ok( false !== strpos( $s['banner']['description'], '<a href="/c">this</a>' ), 'wp_kses keeps the link' );
$eq( 'G-TEST1234', $s['ga4_id'], 'GA4 id saved' );
$eq( 1, $s['policy_version'], 'policy version not writable from the form' );

// ---- Front end: head order and held tags
$GLOBALS['wp_query'] = new WP_Query( array( 'p' => 1 ) );
ob_start();
wp_enqueue_scripts();
wp_head();
$head = ob_get_clean();
$cm   = strpos( $head, 'id="rycm-consent-mode"' );
$ok( false !== $cm, 'Consent Mode script printed' );
$ok( (bool) preg_match( '/gtag\("consent","default",\{[^}]*"ad_storage":"denied"[^}]*"ad_user_data":"denied"[^}]*"ad_personalization":"denied"/', $head ), 'default denies the three ad signals' );
$ok( false !== strpos( $head, '"analytics_storage":"denied"' ), 'default denies analytics' );
$ok( false !== strpos( $head, '"wait_for_update":500' ), 'wait_for_update present' );
$ok( false !== strpos( $head, 'gtag("set","ads_data_redaction",true)' ), 'ads_data_redaction set' );
$first_script = strpos( $head, '<script' );
$ok( $first_script === strpos( $head, '<script data-cfasync="false" data-no-optimize="1" data-no-defer="1" id="rycm-consent-mode">' ), 'Consent Mode is the first script in <head>' );
$ok( false !== strpos( $head, '<script type="text/plain" data-category="analytics" data-service="ga4" async data-src="https://www.googletagmanager.com/gtag/js?id=G-TEST1234"' ), 'GA4 held as text/plain with data-src' );
$ok( ! preg_match( '/<script[^>]+\ssrc="https:\/\/www\.googletagmanager\.com/', $head ), 'no live gtag.js src before consent' );

// ---- Blocking a registered handle, including its inline blocks
wp_register_script( 'test-tracker', 'https://tracker.test/t.js', array(), '1', true );
wp_add_inline_script( 'test-tracker', 'window.trackerBefore=1;', 'before' );
rycm_consent_block_handle( 'test-tracker', 'marketing' );
wp_enqueue_script( 'test-tracker' );
ob_start();
wp_print_scripts( 'test-tracker' );
$out = ob_get_clean();
$eq( 2, substr_count( $out, 'type="text/plain" data-category="marketing"' ), 'handle and its inline block held' );
$ok( false === strpos( $out, "<script src=\"https://tracker.test" ), 'tracker src not live' );
add_filter(
	'rycm_consent_blocked_handles',
	static function ( array $map ): array {
		$map['test-filtered'] = 'analytics';
		return $map;
	}
);
wp_register_script( 'test-filtered', 'https://f.test/f.js', array(), '1', true );
ob_start();
wp_print_scripts( 'test-filtered' );
$ok( false !== strpos( ob_get_clean(), 'data-category="analytics"' ), 'filter-registered handle held' );

// ---- Footer data for the bootstrap
$data = wp_scripts()->get_data( 'rycm-consent', 'before' );
$data = is_array( $data ) ? implode( '', $data ) : (string) $data;
$ok( false !== strpos( $data, 'window.rycmConsentData=' ), 'bootstrap data attached' );
$ok( false !== strpos( $data, '"useLocalStorage":true' ), 'localStorage store' );
$ok( false !== strpos( $data, '\/wp-json\/rycm-consent\/v1\/record' ) || false !== strpos( $data, 'rest_route=' ), 'record URL present' );

// ---- REST: record
$uuid  = wp_generate_uuid4();
$token = Rycm_Consent_Core::token( Rycm_Consent_Plugin::secret(), time() );
$post  = static function ( array $body, array $headers = array() ) {
	$req = new WP_REST_Request( 'POST', '/rycm-consent/v1/record' );
	$req->set_header( 'content-type', 'application/json' );
	$req->set_header( 'user_agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36' );
	foreach ( $headers as $k => $v ) {
		$req->set_header( $k, $v );
	}
	$req->set_body( wp_json_encode( $body ) );
	return rest_do_request( $req );
};
wp_set_current_user( 0 );
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

$r = $post( array( 'consent_id' => $uuid, 'categories' => array( 'necessary', 'analytics', 'marketing' ), 'revision' => 1, 'token' => $token ) );
$eq( 201, $r->get_status(), 'accept recorded (201)' );
$eq( 'accept', $r->get_data()['action'] ?? null, 'action derived as accept' );
$r = $post( array( 'consent_id' => $uuid, 'categories' => array( 'necessary' ), 'revision' => 1, 'token' => $token ) );
$eq( 'reject', $r->get_data()['action'] ?? null, 'reject recorded' );
$r = $post( array( 'consent_id' => $uuid, 'categories' => array( 'necessary', 'analytics', 'made-up' ), 'revision' => 1, 'token' => $token ) );
$eq( 'custom', $r->get_data()['action'] ?? null, 'custom recorded, unknown category dropped' );

$rows = Rycm_Consent_Records::latest( 10, $uuid );
$eq( 3, count( $rows ), 'three rows for this consent id' );
$eq( 'necessary,analytics', $rows[0]['categories'], 'clean categories stored' );
$eq( Rycm_Consent_Core::hash_ip( '203.0.113.7', Rycm_Consent_Plugin::secret() ), $rows[0]['ip_hash'], 'salted IP hash stored' );
$ok( false === strpos( wp_json_encode( $rows ), '203.0.113.7' ), 'raw IP never stored' );
$eq( 'Chrome / macOS', $rows[0]['ua_family'], 'UA family stored, not full UA' );
$eq( '1', (string) $rows[0]['policy_version'], 'policy version stored' );

$eq( 403, $post( array( 'consent_id' => $uuid, 'categories' => array(), 'revision' => 1, 'token' => 'nope' ) )->get_status(), 'bad token refused' );
$eq( 403, $post( array( 'consent_id' => $uuid, 'categories' => array(), 'revision' => 1, 'token' => $token ), array( 'origin' => 'https://evil.test' ) )->get_status(), 'cross-origin refused' );
$eq( 201, $post( array( 'consent_id' => $uuid, 'categories' => array(), 'revision' => 1, 'token' => $token ), array( 'origin' => home_url() ) )->get_status(), 'same origin accepted' );
$eq( 400, $post( array( 'consent_id' => 'not-a-uuid', 'categories' => array(), 'revision' => 1, 'token' => $token ) )->get_status(), 'bad consent id refused' );
$eq( 400, $post( array( 'consent_id' => $uuid, 'categories' => array(), 'revision' => 9, 'token' => $token ) )->get_status(), 'future revision refused' );

// Token refresh route.
$tr = rest_do_request( new WP_REST_Request( 'GET', '/rycm-consent/v1/token' ) );
$ok( Rycm_Consent_Core::verify_token( (string) ( $tr->get_data()['token'] ?? '' ), Rycm_Consent_Plugin::secret(), time() ), 'token route returns a valid token' );
$eq( 'no-store, max-age=0', $tr->get_headers()['Cache-Control'] ?? '', 'token route not cacheable' );

// Rate limit.
$_SERVER['REMOTE_ADDR'] = '198.51.100.99';
$codes = array();
for ( $i = 0; $i < Rycm_Consent_Rest::RATE_LIMIT + 2; $i++ ) {
	$codes[] = $post( array( 'consent_id' => wp_generate_uuid4(), 'categories' => array(), 'revision' => 1, 'token' => $token ) )->get_status();
}
$eq( Rycm_Consent_Rest::RATE_LIMIT, count( array_keys( $codes, 201, true ) ), 'rate limit allows the quota' );
$eq( 429, end( $codes ), 'rate limit then answers 429' );

// ---- Retention purge
$wpdb->insert( $table, array( 'consent_id' => wp_generate_uuid4(), 'action' => 'accept', 'categories' => 'necessary', 'policy_version' => 1, 'created_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-13 months' ) ), 'ip_hash' => str_repeat( 'a', 64 ), 'ua_family' => 'x' ) );
$wpdb->insert( $table, array( 'consent_id' => wp_generate_uuid4(), 'action' => 'accept', 'categories' => 'necessary', 'policy_version' => 1, 'created_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-11 months' ) ), 'ip_hash' => str_repeat( 'b', 64 ), 'ua_family' => 'x' ) );
$before = Rycm_Consent_Records::count();
$eq( 1, Rycm_Consent_Records::purge(), 'purge removes only rows past 12 months' );
$eq( $before - 1, Rycm_Consent_Records::count(), 'row count drops by one' );

// ---- CSV
$wpdb->insert( $table, array( 'consent_id' => wp_generate_uuid4(), 'action' => 'accept', 'categories' => 'necessary', 'policy_version' => 1, 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'ip_hash' => str_repeat( 'c', 64 ), 'ua_family' => '=cmd()' ) );
$fh = fopen( 'php://memory', 'w+' );
Rycm_Consent_Records::export_csv( $fh );
rewind( $fh );
$csv = stream_get_contents( $fh );
$ok( 0 === strpos( $csv, 'id,consent_id,action,categories,policy_version,created_at_utc,ip_hash_sha256,user_agent_family' ), 'CSV header' );
$eq( Rycm_Consent_Records::count() + 1, substr_count( trim( $csv ), "\n" ) + 1, 'CSV has a line per row plus header' );
$ok( false !== strpos( $csv, "'=cmd()" ), 'CSV neutralises formulas' );

// ---- Shortcodes
$link = do_shortcode( '[rycm_cookie_settings]' );
$ok( false !== strpos( $link, 'data-rycm-cookie-settings' ) && false !== strpos( $link, 'Cookie settings' ), 'settings shortcode' );
$ok( false !== strpos( do_shortcode( '[rycm_cookie_settings style="button" text="Change" class="foo bar!x"]' ), '<button type="button" class="rycm-cookie-settings foo barx"' ), 'button style with clean classes' );
$pol = do_shortcode( '[rycm_cookie_policy]' );
$ok( false !== strpos( $pol, 'Google Analytics 4' ) && false !== strpos( $pol, 'Hotjar' ) && false !== strpos( $pol, 'rycm_consent (browser local storage)' ), 'policy table lists built-in and declared services' );
$ok( false !== strpos( $pol, '<th scope="col">Expires</th>' ), 'policy table has headers' );

// ---- Policy version bump re-asks everyone
$s                   = Rycm_Consent_Plugin::settings();
$s['policy_version'] = 2;
$ref                 = new ReflectionProperty( 'Rycm_Consent_Admin', 'internal_write' );
$ref->setAccessible( true );
$ref->setValue( null, true );
update_option( Rycm_Consent_Plugin::OPTION, $s );
$ref->setValue( null, false );
$eq( 2, Rycm_Consent_Plugin::settings()['policy_version'], 'bump stores new version' );
$cfg = Rycm_Consent_Config::build( Rycm_Consent_Plugin::settings(), '', '' );
$eq( 2, $cfg['revision'], 'CookieConsent revision follows the bump' );

// ---- WP Consent API
$ok( true === apply_filters( 'wp_consent_api_registered_' . plugin_basename( RYCM_CONSENT_FILE ), false ), 'registered with WP Consent API' );
$eq( 'optin', apply_filters( 'wp_get_consent_type', false ), 'consent type optin' );

// ---- Updater against a mocked GitHub response
delete_site_transient( Rycm_Consent_Updater::CACHE );
$mock = static function ( $pre, $args, $url ) {
	if ( false === strpos( $url, 'api.github.com/repos/martingfisher/rycm-consent/releases/latest' ) ) {
		return $pre;
	}
	return array(
		'headers'  => array(),
		'response' => array( 'code' => 200, 'message' => 'OK' ),
		'body'     => wp_json_encode(
			array(
				'tag_name'     => 'v9.9.9',
				'draft'        => false,
				'prerelease'   => false,
				'body'         => 'Test release',
				'published_at' => '2026-11-13T00:00:00Z',
				'assets'       => array( array( 'name' => 'rycm-consent.zip', 'browser_download_url' => 'https://github.com/martingfisher/rycm-consent/releases/download/v9.9.9/rycm-consent.zip' ) ),
			)
		),
		'cookies'  => array(),
	);
};
add_filter( 'pre_http_request', $mock, 10, 3 );
// wordpress.org answers "nothing to update", as it does for a plugin it does not host.
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false === strpos( $url, 'api.wordpress.org/plugins/update-check' ) ) {
			return $pre;
		}
		return array( 'headers' => array(), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => '{"plugins":[],"translations":[],"no_update":[]}', 'cookies' => array() );
	},
	11,
	3
);
delete_site_transient( 'update_plugins' );
wp_update_plugins();
$t    = get_site_transient( 'update_plugins' );
$mine = $t->response[ plugin_basename( RYCM_CONSENT_FILE ) ] ?? null;
$ok( is_object( $mine ), 'WordPress lists an update for rycm-consent' );
$eq( '9.9.9', $mine->new_version ?? null, 'update version from the GitHub release' );
$eq( 'https://github.com/martingfisher/rycm-consent/releases/download/v9.9.9/rycm-consent.zip', $mine->package ?? null, 'update package is the release zip' );
require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
$info = plugins_api( 'plugin_information', array( 'slug' => 'rycm-consent' ) );
$eq( '9.9.9', is_object( $info ) ? $info->version : null, 'View details works' );
remove_filter( 'pre_http_request', $mock, 10 );
delete_site_transient( Rycm_Consent_Updater::CACHE );
delete_site_transient( 'update_plugins' );

// ---- Leave the site in a known state for the browser check
$wpdb->query( "DELETE FROM {$table}" );
delete_transient( 'rycm_cr_' . substr( Rycm_Consent_Core::hash_ip( '198.51.100.99', Rycm_Consent_Plugin::secret() ), 0, 32 ) );

foreach ( $fail as $f ) {
	WP_CLI::log( 'FAIL ' . $f );
}
WP_CLI::log( sprintf( '%d passed, %d failed', $pass, count( $fail ) ) );
if ( $fail ) {
	exit( 1 );
}
