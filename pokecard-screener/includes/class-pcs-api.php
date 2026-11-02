<?php
/**
 * Client für die Pokémon TCG API (api.pokemontcg.io) inkl. Cardmarket-Preisen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCS_Api {

	const BASE         = 'https://api.pokemontcg.io/v2/';
	const CACHE_PREFIX = 'pcs_cards_';

	/**
	 * Alle Karten eines konfigurierten Sets, normalisiert und gecacht.
	 *
	 * @return array|WP_Error
	 */
	public static function cards_for_set( $set_key ) {
		$sets = PCS_Settings::sets();
		if ( ! isset( $sets[ $set_key ] ) ) {
			return new WP_Error( 'pcs_unknown_set', 'Unbekanntes Set.', array( 'status' => 404 ) );
		}

		$query     = $sets[ $set_key ]['query'];
		$cache_key = self::CACHE_PREFIX . md5( $query );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$cards = array();
		$page  = 1;
		do {
			$data = self::request(
				'cards',
				array(
					'q'        => $query,
					'page'     => $page,
					'pageSize' => 250,
				)
			);
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			foreach ( (array) ( $data['data'] ?? array() ) as $raw ) {
				$cards[] = self::normalize( $raw );
			}
			$total = (int) ( $data['totalCount'] ?? 0 );
			$page++;
		} while ( count( $cards ) < $total && $page <= 10 );

		if ( empty( $cards ) ) {
			return new WP_Error( 'pcs_empty', 'Für dieses Set wurden (noch) keine Karten in der Pokémon TCG API gefunden. Prüfe die Suchanfrage in den Einstellungen.', array( 'status' => 404 ) );
		}

		usort(
			$cards,
			static function ( $a, $b ) {
				return array( $a['numberSort'], $a['number'] ) <=> array( $b['numberSort'], $b['number'] );
			}
		);

		set_transient( $cache_key, $cards, HOUR_IN_SECONDS * (int) PCS_Settings::get( 'cache_hours' ) );
		return $cards;
	}

	public static function flush_cache() {
		global $wpdb;
		$like = $wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$like = $wpdb->esc_like( '_transient_timeout_' . self::CACHE_PREFIX ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * @return array|WP_Error
	 */
	private static function request( $endpoint, array $args ) {
		$headers = array( 'Accept' => 'application/json' );
		$key     = PCS_Settings::get( 'api_key' );
		if ( $key ) {
			$headers['X-Api-Key'] = $key;
		}
		$res = wp_remote_get(
			add_query_arg( array_map( 'rawurlencode', $args ), self::BASE . $endpoint ),
			array(
				'timeout' => 30,
				'headers' => $headers,
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'pcs_http', 'Pokémon TCG API nicht erreichbar: ' . $res->get_error_message(), array( 'status' => 502 ) );
		}
		$code = wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			return new WP_Error( 'pcs_http', 'Pokémon TCG API antwortete mit HTTP ' . $code . '.', array( 'status' => 502 ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		return is_array( $data ) ? $data : new WP_Error( 'pcs_json', 'Ungültige Antwort der Pokémon TCG API.', array( 'status' => 502 ) );
	}

	/**
	 * Reduziert die API-Antwort auf das, was das Frontend braucht.
	 */
	private static function normalize( array $c ) {
		$cm     = $c['cardmarket'] ?? array();
		$prices = $cm['prices'] ?? array();
		$num    = (string) ( $c['number'] ?? '' );

		$price = static function ( $k ) use ( $prices ) {
			return isset( $prices[ $k ] ) && is_numeric( $prices[ $k ] ) ? round( (float) $prices[ $k ], 2 ) : null;
		};

		return array(
			'id'         => (string) $c['id'],
			'name'       => (string) ( $c['name'] ?? '' ),
			'number'     => $num,
			'numberSort' => (int) preg_replace( '/\D/', '', $num ),
			'printed'    => (string) ( $c['set']['printedTotal'] ?? '' ),
			'setName'    => (string) ( $c['set']['name'] ?? '' ),
			'rarity'     => (string) ( $c['rarity'] ?? 'Unbekannt' ),
			'supertype'  => (string) ( $c['supertype'] ?? '' ),
			'types'      => array_values( (array) ( $c['types'] ?? array() ) ),
			'image'      => (string) ( $c['images']['small'] ?? '' ),
			'imageLarge' => (string) ( $c['images']['large'] ?? '' ),
			'cardmarket' => array(
				'url'       => (string) ( $cm['url'] ?? '' ),
				'updatedAt' => (string) ( $cm['updatedAt'] ?? '' ),
				'trend'     => $price( 'trendPrice' ),
				'avg'       => $price( 'averageSellPrice' ),
				'low'       => $price( 'lowPrice' ),
				'avg30'     => $price( 'avg30' ),
				'reverse'   => $price( 'reverseHoloTrend' ),
			),
		);
	}
}
