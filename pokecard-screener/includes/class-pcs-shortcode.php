<?php
/**
 * Shortcode [pokecard_screener]: Login-Formular für Gäste, sonst die Screener-App.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCS_Shortcode {

	public static function init() {
		add_shortcode( 'pokecard_screener', array( __CLASS__, 'render' ) );
	}

	public static function render() {
		if ( ! is_user_logged_in() ) {
			$html  = '<div class="pcs-login">';
			$html .= '<p>Bitte logge dich ein, um den Karten-Screener zu nutzen.</p>';
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

		wp_enqueue_style( 'pcs-screener', PCS_URL . 'assets/screener.css', array(), PCS_VERSION );
		wp_enqueue_script( 'pcs-screener', PCS_URL . 'assets/screener.js', array(), PCS_VERSION, true );
		wp_localize_script(
			'pcs-screener',
			'PCS_CONFIG',
			array(
				'restUrl'    => esc_url_raw( rest_url( PCS_Rest::NS ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'eurToChf'   => (float) PCS_Settings::get( 'eur_to_chf' ),
				'sellerNote' => (string) PCS_Settings::get( 'seller_note' ),
				'userName'   => wp_get_current_user()->display_name,
				'logoutUrl'  => wp_logout_url( get_permalink() ),
			)
		);

		return '<div id="pcs-app" class="pcs-app"><p>Lade Screener …</p></div>';
	}
}
