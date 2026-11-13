<?php
/**
 * [rycm_cookie_settings]  link or button that reopens the preferences (for footers).
 * [rycm_cookie_policy]    table of every declared service, grouped by category.
 *
 * @package RycmConsent
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

final class Rycm_Consent_Shortcodes {

	public static function boot(): void {
		add_shortcode( 'rycm_cookie_settings', array( __CLASS__, 'settings_link' ) );
		add_shortcode( 'rycm_cookie_policy', array( __CLASS__, 'policy_table' ) );
	}

	public static function settings_link( $atts ): string {
		$s    = Rycm_Consent_Plugin::settings();
		$atts = shortcode_atts(
			array(
				'text'  => $s['banner']['reopen_label'],
				'class' => '',
				'style' => 'link',
			),
			$atts,
			'rycm_cookie_settings'
		);
		$extra = implode( ' ', array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) $atts['class'] ) ?: array() ) );
		$class = trim( 'rycm-cookie-settings ' . $extra );
		if ( 'button' === $atts['style'] ) {
			return '<button type="button" class="' . esc_attr( $class ) . '" data-rycm-cookie-settings>' . esc_html( $atts['text'] ) . '</button>';
		}
		return '<a href="#rycm-cookie-settings" class="' . esc_attr( $class ) . '" data-rycm-cookie-settings role="button">' . esc_html( $atts['text'] ) . '</a>';
	}

	public static function policy_table( $atts ): string {
		$s        = Rycm_Consent_Plugin::settings();
		$atts     = shortcode_atts( array( 'heading_level' => '3' ), $atts, 'rycm_cookie_policy' );
		$h        = in_array( (string) $atts['heading_level'], array( '2', '3', '4' ), true ) ? 'h' . $atts['heading_level'] : 'h3';
		$services = Rycm_Consent_Config::services( $s );
		$cats     = array_merge( array( 'necessary' ), Rycm_Consent_Core::active_optional( $s ) );

		wp_enqueue_style( 'rycm-consent' );

		$html = '<div class="rycm-cookie-policy">';
		foreach ( $cats as $cat ) {
			$rows = array_filter(
				$services,
				static function ( array $svc ) use ( $cat ): bool {
					return $svc['category'] === $cat;
				}
			);
			$html .= '<' . $h . '>' . esc_html( $s['categories'][ $cat ]['title'] ) . '</' . $h . '>';
			$html .= '<p>' . wp_kses_post( $s['categories'][ $cat ]['description'] ) . '</p>';
			if ( ! $rows ) {
				$html .= '<p>' . esc_html__( 'None in use.', 'rycm-consent' ) . '</p>';
				continue;
			}
			$labels = array(
				'name'     => __( 'Service', 'rycm-consent' ),
				'provider' => __( 'Set by', 'rycm-consent' ),
				'purpose'  => __( 'Purpose', 'rycm-consent' ),
				'cookies'  => __( 'Cookies', 'rycm-consent' ),
				'expiry'   => __( 'Expires', 'rycm-consent' ),
			);
			$html .= '<table><caption>' . esc_html( $s['categories'][ $cat ]['title'] ) . '</caption><thead><tr>';
			foreach ( $labels as $label ) {
				$html .= '<th scope="col">' . esc_html( $label ) . '</th>';
			}
			$html .= '</tr></thead><tbody>';
			foreach ( $rows as $svc ) {
				$html .= '<tr>';
				foreach ( $labels as $key => $label ) {
					$html .= '<td data-label="' . esc_attr( $label ) . '">' . esc_html( (string) $svc[ $key ] ) . '</td>';
				}
				$html .= '</tr>';
			}
			$html .= '</tbody></table>';
		}
		$html .= '<p>' . sprintf(
			/* translators: %s: link that reopens the cookie preferences */
			esc_html__( 'You can change or withdraw your consent at any time: %s.', 'rycm-consent' ),
			self::settings_link( array( 'text' => __( 'open cookie settings', 'rycm-consent' ) ) )
		) . '</p>';
		$html .= '<p class="rycm-cookie-policy__version">' . esc_html( sprintf( __( 'Policy version %d.', 'rycm-consent' ), (int) $s['policy_version'] ) ) . '</p>';
		return $html . '</div>';
	}
}
