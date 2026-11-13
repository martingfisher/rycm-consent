<?php
/**
 * Plugin Name:       RYCM Cookie Consent
 * Plugin URI:        https://github.com/martingfisher/rycm-consent
 * Description:       UK GDPR and PECR cookie consent: equal-weight Accept and Reject, Google Consent Mode v2, script blocking by category, WP Consent API, consent records you can show the ICO, and a cookie policy table. Built on CookieConsent v3 by Orest Bida.
 * Version:           0.1.2
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Results You Can Measure
 * Author URI:        https://resultsyoucanmeasure.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rycm-consent
 * Update URI:        https://github.com/martingfisher/rycm-consent
 *
 * @package RycmConsent
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'RYCM_CONSENT_VERSION', '0.1.2' );
define( 'RYCM_CONSENT_FILE', __FILE__ );
define( 'RYCM_CONSENT_DIR', __DIR__ );
define( 'RYCM_CONSENT_REPO', 'martingfisher/rycm-consent' );
define( 'RYCM_CONSENT_CC_VERSION', '3.3.0' );

require_once __DIR__ . '/includes/class-core.php';
require_once __DIR__ . '/includes/class-config.php';
require_once __DIR__ . '/includes/class-plugin.php';
require_once __DIR__ . '/includes/class-records.php';
require_once __DIR__ . '/includes/class-rest.php';
require_once __DIR__ . '/includes/class-frontend.php';
require_once __DIR__ . '/includes/class-wp-consent-api.php';
require_once __DIR__ . '/includes/class-shortcodes.php';
require_once __DIR__ . '/includes/class-admin.php';
require_once __DIR__ . '/includes/class-updater.php';
require_once __DIR__ . '/includes/api.php';

register_activation_hook( __FILE__, array( 'Rycm_Consent_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Rycm_Consent_Plugin', 'deactivate' ) );

Rycm_Consent_Plugin::boot();
