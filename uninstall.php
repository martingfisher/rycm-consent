<?php
/**
 * Runs when the plugin is deleted from wp-admin.
 *
 * Consent records are evidence, so they are only removed when the site owner
 * ticked "Delete data on uninstall".
 *
 * @package RycmConsent
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'rycm_consent_purge' );
delete_site_transient( 'rycm_consent_release' );

$rycm_settings = get_option( 'rycm_consent_settings', array() );
if ( is_array( $rycm_settings ) && ! empty( $rycm_settings['delete_on_uninstall'] ) ) {
	global $wpdb;
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rycm_consent_records" ); // phpcs:ignore WordPress.DB
	delete_option( 'rycm_consent_settings' );
	delete_option( 'rycm_consent_secret' );
	delete_option( 'rycm_consent_db_version' );
}
