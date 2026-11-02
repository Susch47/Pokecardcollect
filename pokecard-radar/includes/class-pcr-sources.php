<?php
/**
 * Quellen: Kleinanzeigen.de (HTML) und tutti.ch (interne GraphQL-API).
 * Port von pitchtracker/sources.py. Beide liefern einheitliche Arrays:
 *   id, source, title, body, url, image, price, currency, price_raw, location, posted
 *
 * Das Parsen ist reines PHP (testbar ohne WordPress); HTTP läuft über die WP-HTTP-API.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'PCR_TESTING' ) ) {
	exit;
}

class PCR_Source_Blocked extends RuntimeException {}

abstract class PCR_Source {
	const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

	public $name;

	/** @return array{0: array[], 1: bool} Treffer, und ob es eine weitere Seite gibt */
	abstract public function search( $query, $page = 1 );

	/** Pause in Sekunden vor der nächsten Anfrage */
	abstract public function delay();

	protected static function check_response( $res, $label ) {
		if ( is_wp_error( $res ) ) {
			throw new RuntimeException( $label . ': ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 403 === $code || 429 === $code ) {
			throw new PCR_Source_Blocked( $label . ' blockt (' . $code . ')' );
		}
		if ( $code < 200 || $code >= 300 ) {
			throw new RuntimeException( $label . ': HTTP ' . $code );
		}
		return wp_remote_retrieve_body( $res );
	}
}

class PCR_Kleinanzeigen extends PCR_Source {
	const BASE = 'https://www.kleinanzeigen.de';

	public $name = 'kleinanzeigen';
	private $min_delay;
	private $max_delay;

	public function __construct( $min_delay = 4, $max_delay = 9 ) {
		$this->min_delay = (float) $min_delay;
		$this->max_delay = max( (float) $min_delay, (float) $max_delay );
	}

	public function delay() {
		return $this->min_delay + ( mt_rand() / mt_getrandmax() ) * ( $this->max_delay - $this->min_delay );
	}

	public static function url( $query, $page = 1 ) {
		$slug  = implode( '-', preg_split( '/[\s\/]+/', mb_strtolower( trim( $query ), 'UTF-8' ) ) );
		$slug  = str_replace( '%2D', '-', rawurlencode( $slug ) );
		$parts = array( 's-anzeige:angebote' );
		if ( $page > 1 ) {
			$parts[] = 'seite:' . (int) $page;
		}
		return self::BASE . '/' . implode( '/', $parts ) . '/' . $slug . '/k0';
	}

	public function search( $query, $page = 1 ) {
		$res   = wp_remote_get(
			self::url( $query, $page ),
			array(
				'timeout' => 25,
				'headers' => array(
					'User-Agent'      => self::UA,
					'Accept-Language' => 'de-DE,de;q=0.9',
					'Accept'          => 'text/html,application/xhtml+xml,*/*;q=0.8',
				),
			)
		);
		$items = self::parse( self::check_response( $res, 'Kleinanzeigen' ) );
		return array( $items, count( $items ) >= 20 );
	}

	private static function cls( $c ) {
		return "contains(concat(' ', normalize-space(@class), ' '), ' $c ')";
	}

	private static function txt( $node ) {
		return $node ? trim( preg_replace( '/\s+/u', ' ', $node->textContent ) ) : '';
	}

	public static function parse( $html ) {
		if ( '' === trim( (string) $html ) ) {
			return array();
		}
		$doc = new DOMDocument();
		$old = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $old );
		$xp  = new DOMXPath( $doc );
		$one = static function ( $expr, $ctx ) use ( $xp ) {
			$r = $xp->query( $expr, $ctx );
			return $r && $r->length ? $r->item( 0 ) : null;
		};

		$out  = array();
		$seen = array();
		foreach ( $xp->query( '//article[' . self::cls( 'aditem' ) . ' or @data-adid]' ) as $art ) {
			/** @var DOMElement $art */
			$a     = $one( './/h2//a | .//a[' . self::cls( 'ellipsis' ) . "] | .//a[contains(@href, '/s-anzeige/')]", $art );
			$href  = $art->getAttribute( 'data-href' );
			$href  = $href ? $href : ( $a ? $a->getAttribute( 'href' ) : '' );
			$ad_id = $art->getAttribute( 'data-adid' );
			if ( ! $ad_id && preg_match( '#/(\d{6,})-#', $href, $mm ) ) {
				$ad_id = $mm[1];
			}
			if ( ! $ad_id || isset( $seen[ $ad_id ] ) ) {
				continue;
			}
			$seen[ $ad_id ] = true;

			$box = $one( './/*[@data-imgsrc]', $art );
			$img = $box ? $box->getAttribute( 'data-imgsrc' ) : '';
			if ( ! $img ) {
				$im = $one( './/*[' . self::cls( 'aditem-image' ) . ']//img | .//*[' . self::cls( 'imagebox' ) . ']//img', $art );
				if ( $im ) {
					$img = $im->getAttribute( 'src' ) ? $im->getAttribute( 'src' ) : explode( ' ', $im->getAttribute( 'srcset' ) )[0];
				}
			}
			$price_raw = self::txt( $one( './/*[' . self::cls( 'aditem-main--middle--price-shipping--price' ) . '] | .//*[' . self::cls( 'aditem-main--middle--price' ) . ']', $art ) );
			$tags      = array();
			foreach ( $xp->query( './/*[' . self::cls( 'simpletag' ) . ']', $art ) as $tg ) {
				$tags[] = mb_strtolower( self::txt( $tg ), 'UTF-8' );
			}
			$tags     = implode( ' ', $tags );
			$shipping = null;
			if ( false !== strpos( $tags, 'nur abholung' ) ) {
				$shipping = false;
			} elseif ( false !== strpos( $tags, 'versand möglich' ) ) {
				$shipping = true;
			}
			$title = self::txt( $a );
			if ( '' === $title ) {
				$title = self::txt( $one( './/h2', $art ) );
			}
			$out[] = array(
				'id'        => 'ka:' . $ad_id,
				'source'    => 'kleinanzeigen',
				'title'     => $title,
				'body'      => self::txt( $one( './/*[' . self::cls( 'aditem-main--middle--description' ) . ']', $art ) ),
				'url'       => 0 === strpos( $href, 'http' ) ? $href : self::BASE . $href,
				'image'     => (string) $img,
				'price_raw' => $price_raw,
				'price'     => PCR_Matcher::parse_amount( $price_raw ),
				'currency'  => 'EUR',
				'location'  => self::txt( $one( './/*[' . self::cls( 'aditem-main--top--left' ) . ']', $art ) ),
				'posted'    => self::txt( $one( './/*[' . self::cls( 'aditem-main--top--right' ) . ']', $art ) ),
				'shipping'  => $shipping,
			);
		}
		return $out;
	}
}

class PCR_Tutti extends PCR_Source {
	const API = 'https://www.tutti.ch/api/v10/graphql';

	// Abfrage aus der Bibliothek tutti-scraper (MIT), die der Python-Radar nutzt
	const QUERY = 'query Search($q: String, $category: ID, $constraints: ListingSearchConstraints, $first: Int!, $offset: Int!, $sort: ListingSortMode!, $direction: SortDirection!) {
  searchListingsByQuery(query: $q, category: $category, constraints: $constraints) {
    listings(first: $first, offset: $offset, sort: $sort, direction: $direction) {
      totalCount
      edges { node {
        listingID title body timestamp formattedPrice
        postcodeInformation { postcode locationName canton { shortName } }
        thumbnail { normalRendition: rendition(width: 235, height: 167) { src } retinaRendition: rendition(width: 470, height: 334) { src } }
        seoInformation { deSlug: slug(language: DE) }
      } }
    }
  }
}';

	public $name = 'tutti';
	private $wait;
	private $page_size;

	public function __construct( $delay = 2.0, $page_size = 60 ) {
		$this->wait      = (float) $delay;
		$this->page_size = (int) $page_size;
	}

	public function delay() {
		return $this->wait;
	}

	public function search( $query, $page = 1 ) {
		$res  = wp_remote_post(
			self::API,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'              => 'application/json',
					'User-Agent'                => self::UA,
					'Accept-Language'           => 'de-CH',
					'X-Tutti-Source'            => 'web LIVE',
					'X-Tutti-Client-Identifier' => 'web/1.0.0+env-live.git-0000000',
					'X-Tutti-Hash'              => wp_generate_uuid4(),
				),
				'body'    => wp_json_encode(
					array(
						'query'     => self::QUERY,
						'variables' => array(
							'q'           => $query,
							'category'    => null,
							'constraints' => null,
							'first'       => $this->page_size,
							'offset'      => 0,
							'sort'        => 'TIMESTAMP',
							'direction'   => 'DESCENDING',
						),
					)
				),
			)
		);
		$data = json_decode( self::check_response( $res, 'tutti' ), true );
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'tutti: ungültige Antwort' );
		}
		if ( ! empty( $data['errors'] ) ) {
			throw new RuntimeException( 'tutti: ' . substr( wp_json_encode( $data['errors'] ), 0, 160 ) );
		}
		$edges = $data['data']['searchListingsByQuery']['listings']['edges'] ?? array();
		$out   = array();
		foreach ( $edges as $e ) {
			if ( ! empty( $e['node']['listingID'] ) ) {
				$out[] = self::convert( $e['node'] );
			}
		}
		return array( $out, false );
	}

	public static function convert( array $node ) {
		$pc     = $node['postcodeInformation'] ?? array();
		$canton = $pc['canton']['shortName'] ?? '';
		$thumb  = $node['thumbnail']['retinaRendition']['src'] ?? ( $node['thumbnail']['normalRendition']['src'] ?? '' );
		$slug   = $node['seoInformation']['deSlug'] ?? '';
		$id     = (string) $node['listingID'];
		$price  = (string) ( $node['formattedPrice'] ?? '' );
		$loc    = array_filter( array( $pc['postcode'] ?? '', $pc['locationName'] ?? '', $canton ? '(' . $canton . ')' : '' ) );
		return array(
			'id'        => 'tutti:' . $id,
			'source'    => 'tutti',
			'title'     => (string) ( $node['title'] ?? '' ),
			'body'      => (string) ( $node['body'] ?? '' ),
			'url'       => $slug ? "https://www.tutti.ch/de/vi/{$slug}/{$id}" : "https://www.tutti.ch/de/vi/{$id}",
			'image'     => (string) $thumb,
			'price_raw' => $price,
			'price'     => PCR_Matcher::parse_amount( $price ),
			'currency'  => 'CHF',
			'location'  => implode( ' ', $loc ),
			'posted'    => str_replace( 'T', ' ', substr( (string) ( $node['timestamp'] ?? '' ), 0, 16 ) ),
			'shipping'  => null,
		);
	}
}
