<?php
/**
 * Sammlung pro Nutzer (User-Meta): Karten-ID => Anzahl.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCS_Collection {

	const META = 'pcs_collection';
	const MAX  = 999;

	/**
	 * @return array<string,int>
	 */
	public static function get( $user_id ) {
		$data = get_user_meta( $user_id, self::META, true );
		return is_array( $data ) ? array_map( 'intval', $data ) : array();
	}

	/**
	 * Setzt die Anzahl einer Karte (0 entfernt sie).
	 *
	 * @return array<string,int>
	 */
	public static function set_qty( $user_id, $card_id, $qty ) {
		$data = self::get( $user_id );
		$qty  = max( 0, min( self::MAX, (int) $qty ) );
		if ( 0 === $qty ) {
			unset( $data[ $card_id ] );
		} else {
			$data[ $card_id ] = $qty;
		}
		update_user_meta( $user_id, self::META, $data );
		return $data;
	}

	public static function valid_card_id( $id ) {
		return is_string( $id ) && (bool) preg_match( '/^[A-Za-z0-9._-]{1,40}$/', $id );
	}
}
