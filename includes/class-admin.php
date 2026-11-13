<?php
/**
 * Settings > Cookie consent.
 *
 * @package RycmConsent
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

final class Rycm_Consent_Admin {

	public const SLUG = 'rycm-consent';

	private static bool $internal_write = false;

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_rycm_consent_bump', array( __CLASS__, 'bump' ) );
		add_action( 'admin_post_rycm_consent_export', array( __CLASS__, 'export' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( RYCM_CONSENT_FILE ), array( __CLASS__, 'links' ) );
	}

	public static function links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Settings', 'rycm-consent' ) . '</a>' );
		return $links;
	}

	public static function menu(): void {
		add_options_page( __( 'Cookie consent', 'rycm-consent' ), __( 'Cookie consent', 'rycm-consent' ), 'manage_options', self::SLUG, array( __CLASS__, 'page' ) );
	}

	public static function register(): void {
		register_setting(
			'rycm_consent',
			Rycm_Consent_Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => Rycm_Consent_Core::defaults(),
			)
		);
	}

	public static function sanitize( $input ): array {
		$current = get_option( Rycm_Consent_Plugin::OPTION, array() );
		$current = is_array( $current ) ? $current : array();
		// The bump handler writes a complete, already clean settings array.
		if ( self::$internal_write && is_array( $input ) ) {
			return $input;
		}
		return Rycm_Consent_Core::sanitize( is_array( $input ) ? wp_unslash( $input ) : array(), $current );
	}

	public static function bump(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'rycm-consent' ), 403 );
		}
		check_admin_referer( 'rycm_consent_bump' );
		$s                    = Rycm_Consent_Plugin::settings();
		$s['policy_version']  = (int) $s['policy_version'] + 1;
		self::$internal_write = true;
		update_option( Rycm_Consent_Plugin::OPTION, $s );
		self::$internal_write = false;
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG . '&bumped=1' ) );
		exit;
	}

	public static function export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'rycm-consent' ), 403 );
		}
		check_admin_referer( 'rycm_consent_export' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="consent-records-' . sanitize_file_name( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		Rycm_Consent_Records::export_csv( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	private static function name( string ...$path ): string {
		$n = Rycm_Consent_Plugin::OPTION;
		foreach ( $path as $p ) {
			$n .= '[' . $p . ']';
		}
		return $n;
	}

	private static function text( string $label, string $name, string $value, string $help = '', bool $area = false ): void {
		$id = sanitize_key( str_replace( array( '[', ']' ), '_', $name ) );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( $area ) {
			echo '<textarea class="large-text" rows="3" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( $value ) . '</textarea>';
		} else {
			echo '<input type="text" class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	private static function check( string $label, string $name, bool $on, string $help = '' ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( $on, true, false ) . '> ' . esc_html( $help ) . '</label></td></tr>';
	}

	private static function select( string $label, string $name, string $value, array $options ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><select name="' . esc_attr( $name ) . '">';
		foreach ( $options as $k => $v ) {
			echo '<option value="' . esc_attr( (string) $k ) . '"' . selected( $value, (string) $k, false ) . '>' . esc_html( $v ) . '</option>';
		}
		echo '</select></td></tr>';
	}

	private static function service_row( string $i, array $svc ): string {
		$cats = '';
		foreach ( Rycm_Consent_Core::CATEGORIES as $c ) {
			$cats .= '<option value="' . esc_attr( $c ) . '"' . selected( $svc['category'] ?? 'necessary', $c, false ) . '>' . esc_html( ucfirst( $c ) ) . '</option>';
		}
		$cell = static function ( string $field, string $ph ) use ( $i, $svc ): string {
			return '<td><input type="text" class="widefat" name="' . esc_attr( self::name( 'services', $i, $field ) ) . '" value="' . esc_attr( (string) ( $svc[ $field ] ?? '' ) ) . '" placeholder="' . esc_attr( $ph ) . '" aria-label="' . esc_attr( $ph ) . '"></td>';
		};
		return '<tr>' . $cell( 'name', 'Service name' ) . $cell( 'provider', 'Set by' ) . $cell( 'purpose', 'Purpose' ) . $cell( 'cookies', '_hjSession*, _hjid' ) . $cell( 'expiry', '1 year' )
			. '<td><select name="' . esc_attr( self::name( 'services', $i, 'category' ) ) . '" aria-label="Category">' . $cats . '</select></td>'
			. '<td><button type="button" class="button-link rycm-remove">' . esc_html__( 'Remove', 'rycm-consent' ) . '</button></td></tr>';
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s        = Rycm_Consent_Plugin::settings();
		$warnings = Rycm_Consent_Core::contrast_warnings( $s['colours'] );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Cookie consent', 'rycm-consent' ); ?></h1>
			<?php if ( isset( $_GET['bumped'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success"><p><?php echo esc_html( sprintf( __( 'Policy version is now %d. Every visitor will be asked again on their next page view.', 'rycm-consent' ), (int) $s['policy_version'] ) ); ?></p></div>
			<?php endif; ?>
			<?php foreach ( $warnings as $w ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html( $w ); ?></p></div>
			<?php endforeach; ?>
			<?php if ( ! Rycm_Consent_Wp_Consent_Api::active() ) : ?>
				<div class="notice notice-info"><p><?php esc_html_e( 'Tip: install the free WP Consent API plugin so Site Kit, WooCommerce and other plugins that read it follow the visitor\'s choice automatically.', 'rycm-consent' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'rycm_consent' ); ?>

				<h2><?php esc_html_e( 'Banner text', 'rycm-consent' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::text( 'Title', self::name( 'banner', 'title' ), $s['banner']['title'] );
					self::text( 'Description', self::name( 'banner', 'description' ), $s['banner']['description'], 'Links, bold and italics allowed. No implied consent wording such as "by continuing you agree".', true );
					self::text( 'Accept button', self::name( 'banner', 'accept_all' ), $s['banner']['accept_all'] );
					self::text( 'Reject button', self::name( 'banner', 'reject_all' ), $s['banner']['reject_all'], 'Shown at the same size and colour as Accept.' );
					self::text( 'Customise button', self::name( 'banner', 'customise' ), $s['banner']['customise'] );
					self::text( 'Save button', self::name( 'banner', 'save' ), $s['banner']['save'] );
					self::text( 'Preferences title', self::name( 'banner', 'prefs_title' ), $s['banner']['prefs_title'] );
					self::text( 'Preferences introduction', self::name( 'banner', 'prefs_intro' ), $s['banner']['prefs_intro'], '', true );
					self::text( 'Close label', self::name( 'banner', 'close_label' ), $s['banner']['close_label'], 'Read by screen readers on the close icon.' );
					self::text( 'Re-open label', self::name( 'banner', 'reopen_label' ), $s['banner']['reopen_label'], 'Used by the floating button and [rycm_cookie_settings].' );
					?>
				</table>

				<h2><?php esc_html_e( 'Appearance', 'rycm-consent' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::select( 'Layout', self::name( 'layout' ), $s['layout'], array_combine( Rycm_Consent_Core::LAYOUTS, array( 'Box', 'Wide box', 'Cloud', 'Bar' ) ) );
					self::select( 'Position', self::name( 'position' ), $s['position'], array_combine( Rycm_Consent_Core::POSITIONS, array( 'Bottom left', 'Bottom centre', 'Bottom right', 'Middle', 'Top' ) ) );
					foreach ( array( 'background' => 'Banner background', 'text' => 'Banner text', 'primary' => 'Button colour', 'primary_text' => 'Button text' ) as $k => $label ) {
						echo '<tr><th scope="row"><label for="rycm-c-' . esc_attr( $k ) . '">' . esc_html( $label ) . '</label></th><td><input type="color" id="rycm-c-' . esc_attr( $k ) . '" name="' . esc_attr( self::name( 'colours', $k ) ) . '" value="' . esc_attr( $s['colours'][ $k ] ) . '"> <code>' . esc_html( $s['colours'][ $k ] ) . '</code></td></tr>';
					}
					self::check( 'Floating button', self::name( 'floating_button' ), (bool) $s['floating_button'], 'Show a small cookie button after a choice, so visitors can change their mind from any page.' );
					self::check( 'Block the page', self::name( 'disable_interaction' ), (bool) $s['disable_interaction'], 'Dim the page until a choice is made. The page stays usable after Reject (no cookie wall).' );
					self::check( 'Global Privacy Control', self::name( 'respect_gpc' ), (bool) $s['respect_gpc'], 'Treat a browser GPC signal as Reject all.' );
					?>
				</table>

				<h2><?php esc_html_e( 'Categories', 'rycm-consent' ); ?></h2>
				<p><?php esc_html_e( 'Strictly necessary is always on. Optional categories are off for every visitor until they switch them on.', 'rycm-consent' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					foreach ( Rycm_Consent_Core::CATEGORIES as $cat ) {
						$c = $s['categories'][ $cat ];
						echo '<tr><th scope="row">' . esc_html( ucfirst( $cat ) ) . '</th><td>';
						if ( 'necessary' !== $cat ) {
							echo '<label><input type="checkbox" name="' . esc_attr( self::name( 'categories', $cat, 'enabled' ) ) . '" value="1"' . checked( ! empty( $c['enabled'] ), true, false ) . '> ' . esc_html__( 'Show this category', 'rycm-consent' ) . '</label><br>';
						}
						echo '<input type="text" class="regular-text" aria-label="' . esc_attr( ucfirst( $cat ) . ' title' ) . '" name="' . esc_attr( self::name( 'categories', $cat, 'title' ) ) . '" value="' . esc_attr( $c['title'] ) . '"><br>';
						echo '<textarea class="large-text" rows="2" aria-label="' . esc_attr( ucfirst( $cat ) . ' description' ) . '" name="' . esc_attr( self::name( 'categories', $cat, 'description' ) ) . '">' . esc_textarea( $c['description'] ) . '</textarea></td></tr>';
					}
					?>
				</table>

				<h2><?php esc_html_e( 'Services and cookies', 'rycm-consent' ); ?></h2>
				<p><?php esc_html_e( 'Every third-party service that sets cookies or local storage. Listed in the preferences panel and in [rycm_cookie_policy]. Cookie names (use * as a wildcard) are deleted when a visitor withdraws consent for that category. The consent store and GA4 (when a measurement ID is set) are added automatically.', 'rycm-consent' ); ?></p>
				<table class="widefat striped" id="rycm-services">
					<thead><tr><th>Service</th><th>Set by</th><th>Purpose</th><th>Cookies</th><th>Expires</th><th>Category</th><th><span class="screen-reader-text">Remove</span></th></tr></thead>
					<tbody>
					<?php
					foreach ( array_values( $s['services'] ) as $i => $svc ) {
						echo self::service_row( (string) $i, $svc ); // phpcs:ignore WordPress.Security.EscapeOutput
					}
					?>
					</tbody>
				</table>
				<p><button type="button" class="button" id="rycm-add-service"><?php esc_html_e( 'Add service', 'rycm-consent' ); ?></button></p>
				<template id="rycm-service-template"><?php echo self::service_row( '__i__', array() ); // phpcs:ignore WordPress.Security.EscapeOutput ?></template>

				<h2><?php esc_html_e( 'Google', 'rycm-consent' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::text( 'GA4 measurement ID', self::name( 'ga4_id' ), $s['ga4_id'], 'G-XXXXXXXXXX. Leave empty if GA4 is added another way (Site Kit, GTM). The tag is not even downloaded until analytics is accepted.' );
					self::check( 'Consent Mode v2', self::name( 'consent_mode' ), (bool) $s['consent_mode'], 'Print gtag consent defaults (all denied) first in <head>, and send an update on every choice.' );
					self::select( 'Consent Mode region', self::name( 'region_mode' ), $s['region_mode'], array( 'all' => 'Denied by default for every visitor (recommended)', 'uk_eea' => 'Denied for UK, EEA and Switzerland; granted elsewhere' ) );
					self::check( 'Redact ads data', self::name( 'ads_data_redaction' ), (bool) $s['ads_data_redaction'], 'Strip ad click identifiers while marketing is denied.' );
					self::check( 'URL passthrough', self::name( 'url_passthrough' ), (bool) $s['url_passthrough'], 'Pass ad click information through URLs while cookies are denied.' );
					?>
				</table>

				<h2><?php esc_html_e( 'Policies and records', 'rycm-consent' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php esc_html_e( 'Cookie policy page', 'rycm-consent' ); ?></th><td><?php wp_dropdown_pages( array( 'name' => esc_attr( self::name( 'cookie_policy_page' ) ), 'selected' => (int) $s['cookie_policy_page'], 'show_option_none' => '(none)', 'option_none_value' => '0' ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Privacy notice page', 'rycm-consent' ); ?></th><td><?php wp_dropdown_pages( array( 'name' => esc_attr( self::name( 'privacy_page' ) ), 'selected' => (int) $s['privacy_page'], 'show_option_none' => '(none)', 'option_none_value' => '0' ) ); ?></td></tr>
					<tr><th scope="row"><label for="rycm-retention"><?php esc_html_e( 'Keep consent records for', 'rycm-consent' ); ?></label></th><td><input type="number" min="1" max="120" id="rycm-retention" name="<?php echo esc_attr( self::name( 'retention_months' ) ); ?>" value="<?php echo esc_attr( (string) $s['retention_months'] ); ?>" class="small-text"> <?php esc_html_e( 'months. Older rows are deleted daily.', 'rycm-consent' ); ?></td></tr>
					<?php
					self::text( 'Storage key', self::name( 'storage_key' ), $s['storage_key'], 'Name of the browser local storage entry. Changing it asks everyone again.' );
					self::check( 'Delete data on uninstall', self::name( 'delete_on_uninstall' ), (bool) $s['delete_on_uninstall'], 'Remove settings and consent records when the plugin is deleted. Leave off to keep records as evidence.' );
					?>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Policy version', 'rycm-consent' ); ?></h2>
			<p><?php echo esc_html( sprintf( __( 'Current version: %d. Raise it whenever you add a service or change how data is used: every visitor is asked again.', 'rycm-consent' ), (int) $s['policy_version'] ) ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Ask every visitor for consent again?', 'rycm-consent' ) ); ?>');">
				<input type="hidden" name="action" value="rycm_consent_bump">
				<?php wp_nonce_field( 'rycm_consent_bump' ); ?>
				<?php submit_button( __( 'Ask everyone again', 'rycm-consent' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php self::records_section(); ?>
		</div>
		<script>
		(function(){
			var body=document.querySelector('#rycm-services tbody'),tpl=document.getElementById('rycm-service-template'),n=body.rows.length;
			document.getElementById('rycm-add-service').addEventListener('click',function(){
				body.insertAdjacentHTML('beforeend',tpl.innerHTML.replace(/__i__/g,'n'+(n++)));
				body.lastElementChild.querySelector('input').focus();
			});
			body.addEventListener('click',function(e){if(e.target.classList.contains('rycm-remove')){e.target.closest('tr').remove();}});
		})();
		</script>
		<?php
	}

	private static function records_section(): void {
		$search  = isset( $_GET['consent_id'] ) ? sanitize_text_field( wp_unslash( $_GET['consent_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$rows    = Rycm_Consent_Records::latest( 50, Rycm_Consent_Core::is_uuid( $search ) ? $search : '' );
		$summary = Rycm_Consent_Records::summary();
		$export  = wp_nonce_url( admin_url( 'admin-post.php?action=rycm_consent_export' ), 'rycm_consent_export' );
		?>
		<h2><?php esc_html_e( 'Consent records', 'rycm-consent' ); ?></h2>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: total, 2: accept, 3: reject, 4: custom */
					__( '%1$d records: %2$d accepted all, %3$d rejected all, %4$d custom.', 'rycm-consent' ),
					array_sum( $summary ),
					$summary['accept'],
					$summary['reject'],
					$summary['custom']
				)
			);
			?>
			<a class="button" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Export CSV', 'rycm-consent' ); ?></a>
		</p>
		<p class="description"><?php esc_html_e( 'A visitor can find their consent ID in the cookie preferences panel by asking you; their browser keeps it in local storage. IP addresses are stored only as a salted SHA-256 hash.', 'rycm-consent' ); ?></p>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<label for="rycm-cid" class="screen-reader-text"><?php esc_html_e( 'Consent ID', 'rycm-consent' ); ?></label>
			<input type="search" id="rycm-cid" name="consent_id" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Find by consent ID', 'rycm-consent' ); ?>" class="regular-text">
			<?php submit_button( __( 'Search', 'rycm-consent' ), 'secondary', '', false ); ?>
		</form>
		<table class="widefat striped" style="margin-top:1em">
			<thead><tr><th>When (UTC)</th><th>Consent ID</th><th>Choice</th><th>Categories</th><th>Policy</th><th>Browser</th><th>IP hash</th></tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'No records yet.', 'rycm-consent' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $r ) : ?>
				<tr>
					<td><?php echo esc_html( $r['created_at'] ); ?></td>
					<td><code><?php echo esc_html( $r['consent_id'] ); ?></code></td>
					<td><?php echo esc_html( $r['action'] ); ?></td>
					<td><?php echo esc_html( $r['categories'] ); ?></td>
					<td><?php echo esc_html( (string) $r['policy_version'] ); ?></td>
					<td><?php echo esc_html( $r['ua_family'] ); ?></td>
					<td><code title="<?php echo esc_attr( $r['ip_hash'] ); ?>"><?php echo esc_html( substr( $r['ip_hash'], 0, 12 ) ); ?>…</code></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
