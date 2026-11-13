<?php
/**
 * Unit tests for the pure classes. No WordPress, no database.
 *
 *   php tests/run.php
 *
 * @package RycmConsent
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

require __DIR__ . '/../includes/class-core.php';
require __DIR__ . '/../includes/class-config.php';

final class T {
	public static int $pass = 0;
	public static array $fail = array();

	public static function eq( $expected, $actual, string $what ): void {
		if ( $expected === $actual ) {
			self::$pass++;
			return;
		}
		self::$fail[] = $what . "\n    expected: " . var_export( $expected, true ) . "\n    actual:   " . var_export( $actual, true );
	}

	public static function ok( bool $cond, string $what ): void {
		self::eq( true, $cond, $what );
	}

	public static function finish(): void {
		foreach ( self::$fail as $f ) {
			fwrite( STDERR, "FAIL {$f}\n" );
		}
		printf( "%d passed, %d failed\n", self::$pass, count( self::$fail ) );
		exit( self::$fail ? 1 : 0 );
	}
}

$C = 'Rycm_Consent_Core';
$d = Rycm_Consent_Core::defaults();
$opt = array( 'functional', 'analytics', 'marketing' );

// ---- derive_action: worked out server-side, never trusted from the browser.
T::eq( 'accept', $C::derive_action( array( 'necessary', 'functional', 'analytics', 'marketing' ), $opt ), 'all optional accepted = accept' );
T::eq( 'reject', $C::derive_action( array( 'necessary' ), $opt ), 'only necessary = reject' );
T::eq( 'reject', $C::derive_action( array(), $opt ), 'nothing = reject' );
T::eq( 'custom', $C::derive_action( array( 'necessary', 'analytics' ), $opt ), 'some = custom' );
T::eq( 'accept', $C::derive_action( array( 'necessary', 'analytics' ), array( 'analytics' ) ), 'single optional accepted = accept' );
T::eq( 'reject', $C::derive_action( array( 'necessary', 'analytics' ), array() ), 'no optional categories = reject' );
T::eq( 'reject', $C::derive_action( array( 'bogus' ), $opt ), 'unknown categories ignored' );

// ---- clean_categories
T::eq( array( 'necessary', 'analytics' ), $C::clean_categories( array( 'analytics', 'evil', 'marketing' ), array( 'analytics' ) ), 'only active optional categories kept, necessary added' );
T::eq( array( 'necessary', 'analytics', 'marketing' ), $C::clean_categories( array( 'marketing', 'analytics', 'necessary' ), array( 'analytics', 'marketing' ) ), 'canonical order' );

// ---- UUID
T::ok( $C::is_uuid( '0b8f3a52-5c0e-4a5b-9a39-2f6c1f0d9e11' ), 'uuid accepted' );
T::ok( ! $C::is_uuid( '0b8f3a52-5c0e-4a5b-9a39-2f6c1f0d9e1' ), 'short uuid refused' );
T::ok( ! $C::is_uuid( "x' OR 1=1 --" ), 'injection refused' );

// ---- IP hash
$h = $C::hash_ip( '203.0.113.9', 'salt-a' );
T::eq( 64, strlen( $h ), 'hash is sha256 hex' );
T::eq( $h, $C::hash_ip( ' 203.0.113.9 ', 'salt-a' ), 'hash stable and trimmed' );
T::ok( $h !== $C::hash_ip( '203.0.113.9', 'salt-b' ), 'salt changes hash' );
T::ok( false === strpos( $h, '203' ), 'raw IP not in hash' );

// ---- UA family
$uas = array(
	'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36' => 'Chrome / macOS',
	'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0' => 'Edge / Windows',
	'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1' => 'Safari / iOS',
	'Mozilla/5.0 (Linux; Android 14; SM-S911B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36' => 'Samsung Internet / Android',
	'Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0' => 'Firefox / Linux',
	'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/130.0 Mobile/15E148 Safari/604.1' => 'Chrome / iOS',
	'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' => 'Bot',
	'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/130.0.0.0 Safari/537.36' => 'Bot',
	'' => 'Unknown',
);
foreach ( $uas as $ua => $want ) {
	T::eq( $want, $C::ua_family( $ua ), 'ua family: ' . ( $want ) );
}

// ---- Consent Mode
$defaults = $C::consent_mode_defaults( 'all' );
T::eq( 1, count( $defaults ), 'one default for "all"' );
foreach ( array( 'ad_storage', 'ad_user_data', 'ad_personalization', 'analytics_storage', 'functionality_storage', 'personalization_storage' ) as $sig ) {
	T::eq( 'denied', $defaults[0][ $sig ], "default denies {$sig}" );
}
T::eq( 'granted', $defaults[0]['security_storage'], 'security_storage granted' );
T::eq( 500, $defaults[0]['wait_for_update'], 'wait_for_update set' );
T::ok( ! isset( $defaults[0]['region'] ), 'no region in "all" mode' );

$regional = $C::consent_mode_defaults( 'uk_eea' );
T::eq( 2, count( $regional ), 'two defaults for uk_eea' );
T::ok( in_array( 'GB', $regional[0]['region'], true ) && in_array( 'DE', $regional[0]['region'], true ) && in_array( 'CH', $regional[0]['region'], true ), 'regional list has GB, DE, CH' );
T::eq( 32, count( $regional[0]['region'] ), 'UK + EEA(30) + CH = 32 regions' );
T::eq( 'denied', $regional[0]['analytics_storage'], 'UK/EEA denied' );
T::eq( 'granted', $regional[1]['analytics_storage'], 'rest of world granted' );
T::ok( ! isset( $regional[1]['region'] ), 'fallback has no region' );

$u = $C::consent_mode_update( array( 'necessary', 'analytics' ) );
T::eq( 'granted', $u['analytics_storage'], 'analytics grants analytics_storage' );
T::eq( 'denied', $u['ad_storage'], 'analytics alone keeps ads denied' );
T::eq( 'denied', $u['ad_user_data'], 'ad_user_data denied' );
$u = $C::consent_mode_update( array( 'marketing' ) );
T::eq( array( 'granted', 'granted', 'granted', 'denied' ), array( $u['ad_storage'], $u['ad_user_data'], $u['ad_personalization'], $u['analytics_storage'] ), 'marketing grants the three ad signals only' );
T::eq( 6, count( $C::consent_mode_update( array() ) ), 'update always lists every signal so narrowing revokes' );

// ---- Script blocking
T::eq(
	'<script type="text/plain" data-category="analytics" src="https://x.test/a.js" id="a-js"></script>',
	$C::block_script_tag( '<script src="https://x.test/a.js" id="a-js"></script>', 'analytics' ),
	'src script held'
);
T::eq(
	'<script type="text/plain" data-category="marketing" src="a.js"></script>',
	$C::block_script_tag( '<script type="text/javascript" src="a.js"></script>', 'marketing' ),
	'existing text/javascript replaced'
);
T::eq(
	'<script type="text/plain" data-category="analytics" data-type="module" src="m.js"></script>',
	$C::block_script_tag( '<script type="module" src="m.js"></script>', 'analytics' ),
	'module keeps its type via data-type'
);
$multi = "<script id=\"x-before\">var a=1;</script>\n<script src=\"x.js\" id=\"x-js\"></script>\n<script id=\"x-after\">go();</script>";
T::eq( 3, substr_count( $C::block_script_tag( $multi, 'analytics' ), 'type="text/plain" data-category="analytics"' ), 'inline before/after blocks held too' );
T::eq( '<script type="application/ld+json">{}</script>', $C::block_script_tag( '<script type="application/ld+json">{}</script>', 'analytics' ), 'JSON-LD left alone' );
T::eq( '<script src="a.js"></script>', $C::block_script_tag( '<script src="a.js"></script>', 'necessary' ), 'necessary never blocked' );
T::eq( '<script src="a.js"></script>', $C::block_script_tag( '<script src="a.js"></script>', 'bogus' ), 'unknown category ignored' );
$already = '<script type="text/plain" data-category="marketing" src="a.js"></script>';
T::eq( $already, $C::block_script_tag( $already, 'analytics' ), 'already-held tag untouched' );

// ---- Cookie patterns
T::eq( array( '^_ga$', '^_ga_.*$' ), $C::cookie_patterns( '_ga, _ga_*' ), 'wildcards become regex' );
T::eq( array( '^_hjSession.*$', '^_hjid$' ), $C::cookie_patterns( "_hjSession*\n_hjid _hjid" ), 'whitespace split, deduped' );
T::eq( array( '^a\\.b$' ), $C::cookie_patterns( 'a.b, <script>, (x)' ), 'dots escaped, junk dropped' );

// ---- Colours and contrast
T::eq( '#aabbcc', $C::hex_colour( '#ABC', '#000000' ), 'short hex expanded' );
T::eq( '#000000', $C::hex_colour( 'red; background:url(x)', '#000000' ), 'non-hex refused' );
T::eq( 21.0, $C::contrast_ratio( '#000000', '#ffffff' ), 'black on white 21:1' );
T::eq( 1.0, $C::contrast_ratio( '#777777', '#777777' ), 'same colour 1:1' );
T::eq( array(), $C::contrast_warnings( $d['colours'] ), 'RYCM defaults (#d6262a, #070630) pass AA' );
T::eq( 1, count( $C::contrast_warnings( array( 'text' => '#ffffff', 'background' => '#070630', 'primary' => '#ffff00', 'primary_text' => '#ffffff' ) ) ), 'white on yellow flagged' );

// ---- GA4 id
T::eq( 'G-ABC123XYZ9', $C::ga4_id( ' g-abc123xyz9 ' ), 'GA4 id normalised' );
T::eq( '', $C::ga4_id( 'UA-12345-1' ), 'UA id refused' );
T::eq( '', $C::ga4_id( 'G-1"><script>' ), 'injection refused' );

// ---- CSV
T::eq( "'=HYPERLINK(\"x\")", $C::csv_cell( '=HYPERLINK("x")' ), 'formula neutralised' );
T::eq( "'+1", $C::csv_cell( '+1' ), 'plus neutralised' );
T::eq( 'accept', $C::csv_cell( 'accept' ), 'plain value unchanged' );
T::eq( '', $C::csv_cell( '' ), 'empty unchanged' );

// ---- Token
$now = 1_800_000_000;
$tok = $C::token( 'secret', $now );
T::ok( $C::verify_token( $tok, 'secret', $now ), 'token valid now' );
T::ok( $C::verify_token( $tok, 'secret', $now + 43200 ), 'token valid in the next window (cached page)' );
T::ok( ! $C::verify_token( $tok, 'secret', $now + 86400 * 2 ), 'token expires after two windows' );
T::ok( ! $C::verify_token( $tok, 'other', $now ), 'token bound to the site secret' );
T::ok( ! $C::verify_token( '', 'secret', $now ), 'empty token refused' );

// ---- Settings merge and sanitise
$m = $C::merge( array( 'categories' => array( 'necessary' => array( 'enabled' => false ), 'analytics' => array( 'title' => 'Stats' ) ), 'nonsense' => 1 ) );
T::ok( true === $m['categories']['necessary']['enabled'], 'necessary cannot be disabled' );
T::eq( 'Stats', $m['categories']['analytics']['title'], 'nested merge keeps override' );
T::eq( $d['categories']['analytics']['description'], $m['categories']['analytics']['description'], 'nested merge keeps defaults' );
T::ok( ! isset( $m['nonsense'] ), 'unknown keys dropped' );

$in = array(
	'banner'      => array( 'title' => '<b>Hi</b>', 'description' => 'Read <a href="/c" onclick="x()">this</a><script>alert(1)</script>', 'accept_all' => '' ),
	'layout'      => 'evil',
	'position'    => 'bottom left',
	'colours'     => array( 'primary' => '#123', 'primary_text' => 'nope', 'background' => '#070630', 'text' => '#ffffff' ),
	'categories'  => array( 'necessary' => array(), 'analytics' => array( 'enabled' => '1' ) ),
	'services'    => array(
		array( 'name' => 'Hotjar', 'provider' => 'Hotjar Ltd', 'purpose' => 'Heatmaps', 'cookies' => '_hj*', 'expiry' => '1 year', 'category' => 'analytics' ),
		array( 'name' => '', 'category' => 'analytics' ),
		array( 'name' => 'X', 'category' => 'made-up' ),
	),
	'ga4_id'      => 'G-TEST1234',
	'retention_months' => '999',
	'storage_key' => 'My Site!',
	'policy_version' => 99,
);
$s = $C::sanitize( $in, array( 'policy_version' => 3 ) );
T::eq( 'Hi', $s['banner']['title'], 'title stripped of tags' );
// Rich-text cleaning goes through wp_kses, so it is asserted in tests/integration/run.php.
T::eq( 'Accept all', $s['banner']['accept_all'], 'empty button label falls back to default' );
T::eq( 'box', $s['layout'], 'bad layout falls back' );
T::eq( 'bottom left', $s['position'], 'valid position kept' );
T::eq( '#112233', $s['colours']['primary'], 'colour normalised' );
T::eq( '#ffffff', $s['colours']['primary_text'], 'bad colour falls back' );
T::ok( true === $s['categories']['analytics']['enabled'] && false === $s['categories']['marketing']['enabled'], 'category toggles from checkboxes' );
T::eq( 2, count( $s['services'] ), 'nameless service dropped' );
T::eq( 'necessary', $s['services'][1]['category'], 'unknown service category becomes necessary' );
T::eq( 120, $s['retention_months'], 'retention capped' );
T::eq( 'mysite', $s['storage_key'], 'storage key cleaned' );
T::eq( 3, $s['policy_version'], 'policy version cannot be set from the form' );
T::ok( false === $s['consent_mode'], 'unticked checkbox = off' );

// ---- CookieConsent config
$set               = $C::merge( array( 'ga4_id' => 'G-TEST1234', 'policy_version' => 4 ) );
$set['services'][] = array( 'name' => 'Hotjar', 'provider' => 'Hotjar <Ltd>', 'purpose' => 'x', 'cookies' => '_hjSession*', 'expiry' => '1 year', 'category' => 'analytics' );
$cfg               = Rycm_Consent_Config::build( $set, 'https://example.test/cookies/', '' );
T::eq( 'opt-in', $cfg['mode'], 'opt-in mode' );
T::eq( 4, $cfg['revision'], 'revision = policy version' );
T::ok( true === $cfg['cookie']['useLocalStorage'], 'stored in localStorage, not a cookie (COOKIE_PRACTICE rule 4)' );
T::eq( 365, $cfg['cookie']['expiresAfterDays'], '12 month re-consent' );
T::eq( array( 'necessary', 'analytics', 'marketing' ), array_keys( $cfg['categories'] ), 'functional hidden by default' );
T::ok( true === $cfg['categories']['necessary']['readOnly'], 'necessary read-only' );
T::ok( false === $cfg['categories']['analytics']['enabled'] && false === $cfg['categories']['marketing']['enabled'], 'no pre-ticked categories' );
T::ok( true === $cfg['categories']['analytics']['autoClear']['reloadPage'], 'withdrawal reloads' );
$pats = array_column( $cfg['categories']['analytics']['autoClear']['cookies'], 'pattern' );
T::ok( in_array( '^_ga$', $pats, true ) && in_array( '^_ga_TEST1234$', $pats, true ) && in_array( '^_hjSession.*$', $pats, true ), 'GA4 and declared cookies cleared on withdrawal' );
T::ok( true === $cfg['guiOptions']['consentModal']['equalWeightButtons'], 'equal-weight buttons' );
$en = $cfg['language']['translations']['en'];
T::eq( 'Reject all', $en['consentModal']['acceptNecessaryBtn'], 'Reject is a first-layer button' );
T::ok( false !== strpos( $en['consentModal']['footer'], 'https://example.test/cookies/' ), 'policy link in banner footer' );
$json = json_encode( $cfg );
T::ok( false === strpos( $json, 'Hotjar <Ltd>' ) && false !== strpos( $json, 'Hotjar &lt;Ltd&gt;' ), 'service text escaped for innerHTML' );
$tables = array_filter( array_column( $en['preferencesModal']['sections'], 'cookieTable' ) );
T::eq( 2, count( $tables ), 'cookie tables for necessary and analytics' );
$css = Rycm_Consent_Config::css_vars( $set['colours'] );
T::ok( false !== strpos( $css, '--cc-btn-secondary-bg:#d6262a' ) && false !== strpos( $css, '--cc-btn-primary-bg:#d6262a' ), 'Reject styled exactly like Accept' );

// ---- Release parsing (updater)
$rel = array(
	'tag_name'     => 'v0.2.0',
	'draft'        => false,
	'prerelease'   => false,
	'body'         => 'Changes',
	'published_at' => '2026-11-20T10:00:00Z',
	'assets'       => array(
		array( 'name' => 'other.zip', 'browser_download_url' => 'https://github.com/martingfisher/rycm-consent/releases/download/v0.2.0/other.zip' ),
		array( 'name' => 'rycm-consent.zip', 'browser_download_url' => 'https://github.com/martingfisher/rycm-consent/releases/download/v0.2.0/rycm-consent.zip' ),
	),
);
$up  = $C::parse_release( $rel, 'martingfisher/rycm-consent', 'rycm-consent/rycm-consent.php' );
T::eq( '0.2.0', $up['version'] ?? null, 'version from tag' );
T::eq( 'https://github.com/martingfisher/rycm-consent/releases/download/v0.2.0/rycm-consent.zip', $up['package'] ?? null, 'package is the attached zip' );
T::ok( isset( $up['id'], $up['slug'], $up['url'] ), 'fields WordPress requires are present' );
$bad = $rel;
$bad['assets'][1]['browser_download_url'] = 'https://evil.test/rycm-consent.zip';
T::eq( null, $C::parse_release( $bad, 'martingfisher/rycm-consent', 'x' ), 'package from another host refused' );
$bad = $rel;
$bad['prerelease'] = true;
T::eq( null, $C::parse_release( $bad, 'martingfisher/rycm-consent', 'x' ), 'prerelease ignored' );
$bad = $rel;
$bad['tag_name'] = 'latest';
T::eq( null, $C::parse_release( $bad, 'martingfisher/rycm-consent', 'x' ), 'non-semver tag ignored' );
T::eq( null, $C::parse_release( array( 'tag_name' => 'v1.0.0' ), 'martingfisher/rycm-consent', 'x' ), 'release without zip ignored' );

// ---- Copy rules: no em dashes, no "honest" in anything a visitor sees.
$visible = json_encode( $d ) . json_encode( Rycm_Consent_Config::build( $C::merge( array() ), '', '' ) );
T::ok( false === strpos( $visible, '—' ) && false === strpos( $visible, '—' ), 'no em dashes in default copy' );
T::ok( ! preg_match( '/honest/i', $visible ), 'no "honest" in default copy' );
T::ok( ! preg_match( '/by (continuing|browsing|using)/i', $visible ), 'no implied consent wording' );

T::finish();
