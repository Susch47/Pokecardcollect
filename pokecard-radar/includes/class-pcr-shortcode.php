<?php
/**
 * Shortcode [pokecard_radar set="pitch-black"]: Login für Gäste, sonst das Radar-Dashboard.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCR_Shortcode {

	public static function init() {
		add_shortcode( 'pokecard_radar', array( __CLASS__, 'render' ) );
	}

	public static function render( $atts ) {
		$atts = shortcode_atts( array( 'set' => '' ), $atts, 'pokecard_radar' );

		if ( ! is_user_logged_in() ) {
			$html  = '<div class="pcr-login">';
			$html .= '<p>Bitte logge dich ein, um den Karten-Radar zu nutzen.</p>';
			$html .= wp_login_form(
				array(
					'echo'     => false,
					'redirect' => get_permalink(),
				)
			);
			if ( get_option( 'users_can_register' ) ) {
				$html .= '<p><a href="' . esc_url( wp_registration_url() ) . '">Noch kein Konto? Jetzt registrieren</a></p>';
			}
			return $html . '</div>';
		}

		$sets = PCR_Settings::sets();
		if ( ! $sets ) {
			return '<p>Es sind keine Sets aktiviert.</p>';
		}
		$last    = get_user_meta( get_current_user_id(), 'pcr_last_set', true );
		$default = isset( $sets[ $last ] ) ? $last : ( isset( $sets[ $atts['set'] ] ) ? $atts['set'] : array_key_first( $sets ) );
		if ( $atts['set'] && isset( $sets[ $atts['set'] ] ) && ! $last ) {
			$default = $atts['set'];
		}

		wp_enqueue_style( 'pcr-radar', PCR_URL . 'assets/radar.css', array(), PCR_VERSION );
		wp_enqueue_script( 'pcr-radar', PCR_URL . 'assets/radar.js', array(), PCR_VERSION, true );
		wp_localize_script(
			'pcr-radar',
			'PCR_CONFIG',
			array(
				'restUrl'   => esc_url_raw( rest_url( PCR_Rest::NS ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'sets'      => array_values(
					array_map(
						static function ( $s ) {
							return array( 'key' => $s->key, 'label' => $s->def['label'] );
						},
						$sets
					)
				),
				'set'       => $default,
				'userName'  => wp_get_current_user()->display_name,
				'logoutUrl' => wp_logout_url( get_permalink() ),
			)
		);
		return '<div id="pcr-app" class="pcr-app"><p class="pcr-loading">Radar wird geladen …</p></div>';
	}
}
