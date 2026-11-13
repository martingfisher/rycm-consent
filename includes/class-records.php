<?php
/**
 * Consent records: the table, writes, retention purge and CSV export.
 *
 * One row per choice a visitor makes. Enough to show the ICO what was asked,
 * what was chosen and when, without keeping the IP address or the full user
 * agent.
 *
 * @package RycmConsent
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

final class Rycm_Consent_Records {

	public const SCHEMA = '1';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'rycm_consent_records';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				consent_id char(36) NOT NULL,
				action varchar(10) NOT NULL,
				categories varchar(191) NOT NULL,
				policy_version int(10) unsigned NOT NULL,
				created_at datetime NOT NULL,
				ip_hash char(64) NOT NULL,
				ua_family varchar(64) NOT NULL,
				PRIMARY KEY  (id),
				KEY consent_id (consent_id),
				KEY created_at (created_at)
			) {$charset};"
		);
		update_option( Rycm_Consent_Plugin::DB_VERSION, self::SCHEMA, false );
	}

	/**
	 * @param string[] $categories Clean category keys.
	 */
	public static function insert( string $consent_id, string $action, array $categories, int $version, string $ip_hash, string $ua_family ): bool {
		global $wpdb;
		return false !== $wpdb->insert(
			self::table(),
			array(
				'consent_id'     => strtolower( $consent_id ),
				'action'         => $action,
				'categories'     => implode( ',', $categories ),
				'policy_version' => $version,
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
				'ip_hash'        => $ip_hash,
				'ua_family'      => substr( $ua_family, 0, 64 ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/** Daily cron: delete rows older than the retention setting. */
	public static function purge(): int {
		global $wpdb;
		$months = (int) Rycm_Consent_Plugin::settings()['retention_months'];
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . max( 1, $months ) . ' months' ) );
		$table  = self::table();
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function count(): int {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function latest( int $limit = 50, string $consent_id = '' ): array {
		global $wpdb;
		$table = self::table();
		if ( '' !== $consent_id ) {
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE consent_id = %s ORDER BY id DESC LIMIT %d", strtolower( $consent_id ), $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function summary(): array {
		global $wpdb;
		$table = self::table();
		$rows  = (array) $wpdb->get_results( "SELECT action, COUNT(*) AS n FROM {$table} GROUP BY action", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array( 'accept' => 0, 'reject' => 0, 'custom' => 0 );
		foreach ( $rows as $r ) {
			$out[ $r['action'] ] = (int) $r['n'];
		}
		return $out;
	}

	/** Stream every row as CSV, in batches so a large table does not exhaust memory. */
	public static function export_csv( $out ): void {
		global $wpdb;
		$table = self::table();
		fputcsv( $out, array( 'id', 'consent_id', 'action', 'categories', 'policy_version', 'created_at_utc', 'ip_hash_sha256', 'user_agent_family' ), ',', '"', '\\' );
		$last = 0;
		do {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT 1000", $last ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $rows as $r ) {
				$last = (int) $r['id'];
				fputcsv(
					$out,
					array_map(
						array( 'Rycm_Consent_Core', 'csv_cell' ),
						array( (string) $r['id'], $r['consent_id'], $r['action'], $r['categories'], (string) $r['policy_version'], $r['created_at'], $r['ip_hash'], $r['ua_family'] )
					),
					',',
					'"',
					'\\'
				);
			}
		} while ( count( $rows ) === 1000 );
	}
}
