<?php
/**
 * REST-API unter /wp-json/pcr/v1 – nur für eingeloggte Nutzer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCR_Rest {

	const NS = 'pcr/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function logged_in() {
		return is_user_logged_in() ? true : new WP_Error( 'pcr_auth', 'Bitte einloggen.', array( 'status' => 401 ) );
	}

	private static function set_arg() {
		return array(
			'required'          => true,
			'type'              => 'string',
			'validate_callback' => static function ( $v ) {
				return isset( PCR_Settings::sets()[ $v ] );
			},
		);
	}

	private static function route( $path, $method, $cb, $args = array(), $perm = null ) {
		register_rest_route(
			self::NS,
			$path,
			array(
				'methods'             => $method,
				'callback'            => $cb,
				'args'                => $args,
				'permission_callback' => $perm ? $perm : array( __CLASS__, 'logged_in' ),
			)
		);
	}

	public static function routes() {
		$set = self::set_arg();

		self::route(
			'/sets',
			'GET',
			static function () {
				$out = array();
				foreach ( PCR_Settings::sets() as $s ) {
					$out[] = array( 'key' => $s->key, 'label' => $s->def['label'] );
				}
				return $out;
			}
		);

		self::route(
			'/state',
			'GET',
			static function ( WP_REST_Request $r ) {
				update_user_meta( get_current_user_id(), 'pcr_last_set', $r['set'] );
				return PCR_Engine::state( $r['set'], get_current_user_id(), '1' === (string) $r['all'] );
			},
			array( 'set' => $set, 'all' => array( 'type' => 'string' ) )
		);

		self::route(
			'/toggle',
			'POST',
			static function ( WP_REST_Request $r ) {
				$uid   = get_current_user_id();
				$s     = PCR_Set::get( $r['set'] );
				$num   = (int) $r['num'];
				$owned = PCR_Engine::owned( $uid, $s->key );
				$owned = $owned ? $owned : array();
				if ( ! isset( $s->cards[ $num ] ) ) {
					return new WP_Error( 'pcr_num', 'Unbekannte Kartennummer.', array( 'status' => 400 ) );
				}
				$owned = $r['owned'] ? array_merge( $owned, array( $num ) ) : array_diff( $owned, array( $num ) );
				return array( 'owned' => count( PCR_Engine::set_owned( $uid, $s->key, $owned ) ) );
			},
			array(
				'set'   => $set,
				'num'   => array( 'required' => true, 'type' => 'integer' ),
				'owned' => array( 'required' => true, 'type' => 'boolean' ),
			)
		);

		self::route(
			'/collection',
			'POST',
			static function ( WP_REST_Request $r ) {
				$s    = PCR_Set::get( $r['set'] );
				$nums = PCR_Matcher::parse_ranges( $r['text'], max( $s->nums() ) );
				$nums = array_values( array_intersect( $nums, $s->nums() ) );
				$own  = 'missing' === $r['mode'] ? array_diff( $s->nums(), $nums ) : $nums;
				return array( 'owned' => count( PCR_Engine::set_owned( get_current_user_id(), $s->key, $own ) ) );
			},
			array(
				'set'  => $set,
				'mode' => array( 'required' => true, 'type' => 'string', 'enum' => array( 'missing', 'owned' ) ),
				'text' => array( 'required' => true, 'type' => 'string' ),
			)
		);

		self::route(
			'/hide',
			'POST',
			static function ( WP_REST_Request $r ) {
				$uid    = get_current_user_id();
				$hidden = get_user_meta( $uid, 'pcr_hidden', true );
				$hidden = is_array( $hidden ) ? $hidden : array();
				$hidden[ $r['set'] . '|' . $r['id'] ] = time();
				if ( count( $hidden ) > 3000 ) {
					asort( $hidden );
					$hidden = array_slice( $hidden, -3000, null, true );
				}
				update_user_meta( $uid, 'pcr_hidden', $hidden );
				return array( 'ok' => true );
			},
			array(
				'set' => $set,
				'id'  => array( 'required' => true, 'type' => 'string', 'pattern' => '^[a-z]+:[A-Za-z0-9_-]{1,60}$' ),
			)
		);

		self::route(
			'/viewed',
			'POST',
			static function ( WP_REST_Request $r ) {
				update_user_meta( get_current_user_id(), 'pcr_seen_' . $r['set'], gmdate( 'Y-m-d H:i:s' ) );
				return array( 'ok' => true );
			},
			array( 'set' => $set )
		);

		self::route(
			'/telegram',
			'POST',
			static function ( WP_REST_Request $r ) {
				$chat = trim( (string) $r['chat'] );
				if ( '' === $chat ) {
					delete_user_meta( get_current_user_id(), 'pcr_telegram_chat' );
				} else {
					update_user_meta( get_current_user_id(), 'pcr_telegram_chat', $chat );
				}
				return array( 'ok' => true );
			},
			array( 'chat' => array( 'required' => true, 'type' => 'string', 'pattern' => '^(-?\d{1,20})?$' ) )
		);

		self::route(
			'/scan',
			'POST',
			static function () {
				PCR_Engine::request_scan();
				return array( 'ok' => true );
			},
			array(),
			static function () {
				return current_user_can( 'manage_options' );
			}
		);
	}
}
