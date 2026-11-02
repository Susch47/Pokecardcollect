<?php
/**
 * Ein Kartenset mit allen Karten (reines PHP, ohne WordPress-Abhängigkeit).
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'PCR_TESTING' ) ) {
	exit;
}

require_once __DIR__ . '/sets.php';

class PCR_Set {

	/** @var array<string,PCR_Set> */
	private static $cache = array();

	public $key;
	public $def;
	/** @var array<int,array> Nummer => Karte */
	public $cards = array();

	public static function all() {
		$out = array();
		foreach ( array_keys( pcr_set_definitions() ) as $key ) {
			$out[ $key ] = self::get( $key );
		}
		return $out;
	}

	/** @return PCR_Set|null */
	public static function get( $key ) {
		if ( ! isset( self::$cache[ $key ] ) ) {
			$defs = pcr_set_definitions();
			if ( ! isset( $defs[ $key ] ) ) {
				return null;
			}
			self::$cache[ $key ] = new self( $key, $defs[ $key ] );
		}
		return self::$cache[ $key ];
	}

	public static function reset_cache() {
		self::$cache = array();
	}

	public static function norm( $s ) {
		$s = mb_strtolower( (string) $s, 'UTF-8' );
		$s = strtr( $s, array( '’' => "'", '‘' => "'", '´' => "'", '`' => "'", 'é' => 'e', 'è' => 'e', 'ê' => 'e', '–' => '-' ) );
		return trim( preg_replace( '/\s+/u', ' ', $s ) );
	}

	public static function core_name( $name ) {
		$n = self::norm( $name );
		if ( 0 === strpos( $n, 'mega ' ) ) {
			$n = substr( $n, 5 );
		}
		if ( ' ex' === substr( $n, -3 ) ) {
			$n = substr( $n, 0, -3 );
		}
		return trim( $n );
	}

	private function __construct( $key, array $def ) {
		$this->key = $key;
		$this->def = $def;
		$de_map    = $def['de'];
		$printed   = (int) $def['printed'];

		foreach ( preg_split( '/\R/', trim( $def['cards'] ) ) as $line ) {
			$p       = explode( '|', $line );
			$num     = (int) $p[0];
			$en      = $p[1];
			$rar     = $p[2];
			$aliases = isset( $p[3] ) && '' !== $p[3] ? array_map( array( __CLASS__, 'norm' ), explode( ',', $p[3] ) ) : array();
			$generic = isset( $p[4] ) && 'g' === $p[4];
			$core    = self::core_name( $en );
			$mega    = 0 === strpos( $en, 'Mega ' );
			$ex      = ' ex' === substr( $en, -3 );
			$de_core = $de_map[ $core ] ?? '';
			$de      = $en;
			if ( $de_core ) {
				$de = ( $mega ? 'Mega-' : '' ) . ucwords( $de_core, " -:" ) . ( $ex ? '-ex' : '' );
			}
			$this->cards[ $num ] = array(
				'num'          => $num,
				'code'         => $this->code( $num ),
				'en'           => $en,
				'de'           => $de,
				'rarity'       => $rar,
				'rarity_label' => $def['rarity_labels'][ $rar ] ?? $rar,
				'core'         => $core,
				'core_de'      => $de_core,
				'aliases'      => $aliases,
				'generic'      => $generic,
				'mega'         => $mega,
				'ex'           => $ex,
				'secret'       => $num > $printed,
				'market'       => null,
			);
		}
	}

	public function code( $num ) {
		$n = $this->def['pad'] ? sprintf( '%03d', $num ) : (string) $num;
		$t = $this->def['pad'] ? sprintf( '%03d', $this->def['printed'] ) : (string) $this->def['printed'];
		return $n . '/' . $t;
	}

	public function total() {
		return count( $this->cards );
	}

	public function nums() {
		return array_keys( $this->cards );
	}

	/** Deutsche Namen aus TCGdex übernehmen (v. a. Trainer). */
	public function apply_names( array $de_names ) {
		foreach ( $de_names as $num => $name ) {
			if ( ! isset( $this->cards[ $num ] ) || ! $name ) {
				continue;
			}
			$c       = &$this->cards[ $num ];
			$c['de'] = $name;
			$cd      = self::core_name( $name );
			if ( $cd && $cd !== $c['core'] && $cd !== $c['core_de'] ) {
				if ( '' === $c['core_de'] ) {
					$c['core_de'] = $cd;
				} elseif ( ! in_array( $cd, $c['aliases'], true ) ) {
					$c['aliases'][] = $cd;
				}
			}
			unset( $c );
		}
	}

	public function apply_market( array $prices ) {
		foreach ( $prices as $num => $eur ) {
			if ( isset( $this->cards[ $num ] ) ) {
				$this->cards[ $num ]['market'] = null === $eur ? null : (float) $eur;
			}
		}
	}

	/** Suchbegriff für eine einzelne Karte, z. B. "glurak 4/102" */
	public function targeted_query( $num ) {
		$c    = $this->cards[ $num ];
		$name = $c['core_de'] ? $c['core_de'] : $c['core'];
		return strtr(
			$this->def['targeted_fmt'],
			array(
				'{name}'    => $name,
				'{num}'     => $num,
				'{printed}' => $this->def['printed'],
			)
		);
	}
}
