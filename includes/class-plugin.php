<?php
/**
 * Settings access, lifecycle and wiring.
 *
 * @package RycmConsent
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

final class Rycm_Consent_Plugin {

	public const OPTION      = 'rycm_consent_settings';
	public const SECRET      = 'rycm_consent_secret';
	public const DB_VERSION  = 'rycm_consent_db_version';
	public const CRON        = 'rycm_consent_purge';

	private static ?array $settings = null;

	public static function boot(): void {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( self::CRON, array( 'Rycm_Consent_Records', 'purge' ) );
		add_action( 'init', array( __CLASS__, 'ensure_cron' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'flush' ) );

		Rycm_Consent_Rest::boot();
		Rycm_Consent_Frontend::boot();
		Rycm_Consent_Wp_Consent_Api::boot();
		Rycm_Consent_Shortcodes::boot();
		Rycm_Consent_Updater::boot();
		if ( is_admin() ) {
			Rycm_Consent_Admin::boot();
		}
	}

	public static function settings(): array {
		if ( null === self::$settings ) {
			$stored         = get_option( self::OPTION, array() );
			self::$settings = Rycm_Consent_Core::merge( is_array( $stored ) ? $stored : array() );
		}
		return self::$settings;
	}

	public static function flush(): void {
		self::$settings = null;
	}

	/** Per-site secret: salts IP hashes and signs the record token. Never leaves the database. */
	public static function secret(): string {
		$secret = (string) get_option( self::SECRET, '' );
		if ( strlen( $secret ) < 32 ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( self::SECRET, $secret, false );
		}
		return $secret;
	}

	public static function activate(): void {
		Rycm_Consent_Records::install();
		self::secret();
		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, Rycm_Consent_Core::defaults(), '', true );
		}
		self::ensure_cron();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON );
	}

	/** Updates do not fire the activation hook, so check the table version on every load. */
	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION ) !== Rycm_Consent_Records::SCHEMA ) {
			Rycm_Consent_Records::install();
		}
	}

	public static function ensure_cron(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	public static function page_url( int $id ): string {
		if ( $id <= 0 || 'publish' !== get_post_status( $id ) ) {
			return '';
		}
		return (string) get_permalink( $id );
	}
}
