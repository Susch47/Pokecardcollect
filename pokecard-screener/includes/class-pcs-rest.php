<?php
/**
 * REST-Endpunkte unter /wp-json/pcs/v1 – nur für eingeloggte Nutzer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCS_Rest {

	const NS = 'pcs/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function logged_in() {
		return is_user_logged_in()
			? true
			: new WP_Error( 'pcs_auth', 'Bitte einloggen.', array( 'status' => 401 ) );
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/sets',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( __CLASS__, 'logged_in' ),
				'callback'            => static function () {
					return array_values(
						array_map(
							static function ( $s ) {
								return array(
									'key'   => $s['key'],
									'label' => $s['label'],
								);
							},
							PCS_Settings::sets()
						)
					);
				},
			)
		);

		register_rest_route(
			self::NS,
			'/sets/(?P<key>[a-z0-9_-]+)/cards',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( __CLASS__, 'logged_in' ),
				'callback'            => static function ( WP_REST_Request $req ) {
					$cards = PCS_Api::cards_for_set( $req['key'] );
					return is_wp_error( $cards ) ? $cards : rest_ensure_response( $cards );
				},
			)
		);

		register_rest_route(
			self::NS,
			'/collection',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => array( __CLASS__, 'logged_in' ),
					'callback'            => static function () {
						return (object) PCS_Collection::get( get_current_user_id() );
					},
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'permission_callback' => array( __CLASS__, 'logged_in' ),
					'args'                => array(
						'cardId' => array(
							'required'          => true,
							'type'              => 'string',
							'validate_callback' => static function ( $v ) {
								return PCS_Collection::valid_card_id( $v );
							},
						),
						'qty'    => array(
							'required' => true,
							'type'     => 'integer',
							'minimum'  => 0,
							'maximum'  => PCS_Collection::MAX,
						),
					),
					'callback'            => static function ( WP_REST_Request $req ) {
						return (object) PCS_Collection::set_qty( get_current_user_id(), $req['cardId'], $req['qty'] );
					},
				),
			)
		);
	}
}
