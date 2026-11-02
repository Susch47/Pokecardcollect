<?php
/**
 * Erkennt, welche Karte(n) eines Sets eine Anzeige anbietet.
 * Port von pitchtracker/matcher.py, verallgemeinert für mehrere Sets.
 *
 * Stufen:
 *   sicher  – Kartennummer (z. B. 116/084, 4/102) gefunden
 *   mittel  – Name + Set-Bezug, Variante eindeutig
 *   niedrig – Name ohne Set-Bezug oder mehrere Varianten möglich
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'PCR_TESTING' ) ) {
	exit;
}

class PCR_Matcher {

	const EXCLUDE = array(
		array( '/\bproxy\b|\bfake\b|replika|replica|\borica\b|\bcustom\b|nicht original|kein original|nachdruck|\bfan ?art\b/i', 'Fake/Proxy' ),
		// Hinweis: bewusst nicht \bch\b – "CH" heisst auf tutti meist "Schweiz"
		array( '/japan|\bjpn?\b|chines|\bchn\b|korean|\bkor\b|\bthai\b/i', 'andere Sprache' ),
	);

	const TITLE_EXCLUDE = array(
		array( '/^\s*(suche|gesucht|tausche|ich suche)\b|\bwtb\b/i', 'Gesuch/Tausch' ),
		array( '/\bdisplay\b|booster ?(box|bundle|display)|\betb\b|elite ?trainer|top ?trainer|blister|\bsealed\b|\bversiegelt\b|\bovp\b|build ?& ?battle|\bcollection\b|kollektion|\btin\b|^\s*(\d+\s*x\s*)?booster(s| ?packs?)?\b/i', 'Sealed/Produkt' ),
	);

	const LOT_RE    = '/sammlung|konvolut|\blot\b|bundle|paket|\bbulk\b|playset|\b\d{2,}\s*(karten|stück|stk)\b|komplett|masterset|set komplett/i';
	const GRADED_RE = '/\b(psa|cgc|bgs|beckett|tag|pca|gsg)\s*(10|[1-9](?:[.,]5)?)\b/i';
	const LANG_DE   = '/\bdeutsch\b|\bger\b|\bde\b/i';
	const LANG_EN   = '/\benglisch\b|\benglish\b|\beng?\b/i';
	const EDITION   = array(
		array( '/\b1st\b|1\.\s*(ed|edition|auflage)|erste\s*(edition|auflage)|edition\s*1\b|first edition/i', '1. Edition' ),
		array( '/shadowless|ohne schatten/i', 'Shadowless' ),
	);

	public static function empty_match( $set_context = false ) {
		return array(
			'nums'        => array(),
			'confidence'  => '',
			'ambiguous'   => false,
			'excluded'    => '',
			'is_lot'      => false,
			'graded'      => '',
			'language'    => '',
			'edition'     => '',
			'set_context' => (bool) $set_context,
		);
	}

	public static function has_name( $text, $name ) {
		if ( '' === $name ) {
			return false;
		}
		// strtr ersetzt in einem Durchgang (keine Ersetzung in bereits ersetztem Text)
		$pat = strtr(
			preg_quote( $name, '/' ),
			array(
				' '   => '[\s\-]*',
				'\\-' => '[\s\-]*',
				"'"   => "'?",
				'\\.' => '\.?',
				'\\:' => ':?',
				':'   => ':?',
			)
		);
		return (bool) preg_match( '/(?<![a-zäöü])' . $pat . '(?![a-zäöü])/u', $text );
	}

	private static function names( array $c ) {
		return array_values( array_unique( array_filter( array_merge( array( $c['core'], $c['core_de'] ), $c['aliases'] ) ) ) );
	}

	/**
	 * @return array siehe empty_match()
	 */
	public static function match( PCR_Set $set, $title, $body = '' ) {
		$def  = $set->def;
		$t    = PCR_Set::norm( $title );
		$b    = PCR_Set::norm( $body );
		$full = $t . " \n " . $b;
		$m    = self::empty_match( preg_match( $def['context'], $full ) );

		foreach ( self::TITLE_EXCLUDE as list( $pat, $why ) ) {
			if ( preg_match( $pat, $t ) ) {
				$m['excluded'] = $why;
				return $m;
			}
		}
		foreach ( array_merge( self::EXCLUDE, $def['exclude'] ) as list( $pat, $why ) ) {
			if ( preg_match( $pat, $full ) ) {
				$m['excluded'] = $why;
				return $m;
			}
		}

		if ( preg_match( self::GRADED_RE, $full, $g ) ) {
			$m['graded'] = strtoupper( $g[1] ) . ' ' . $g[2];
		}
		if ( preg_match( self::LANG_DE, $full ) ) {
			$m['language'] = 'DE';
		} elseif ( preg_match( self::LANG_EN, $full ) ) {
			$m['language'] = 'EN';
		}
		foreach ( self::EDITION as list( $pat, $label ) ) {
			if ( preg_match( $pat, $full ) ) {
				$m['edition'] = $label;
				break;
			}
		}

		$max = max( $set->nums() );

		// --- 1. Kartennummern --------------------------------------------
		$num_re = '/(?<![\d.,])(\d{1,3})\s*\/\s*' . ( $def['pad'] ? '0?' : '' ) . (int) $def['printed'] . '(?!\d)/';
		preg_match_all( $num_re, $full, $mm );
		$nums = self::valid_nums( $mm[1], $max );
		if ( ! $nums && $m['set_context'] ) {
			preg_match_all( '/(?:#|nr\.?\s*|no\.?\s*)(\d{1,3})\b/i', $full, $mm );
			$nums = self::valid_nums( $mm[1], $max );
		}
		if ( $nums ) {
			$conf = 'sicher';
			if ( empty( $def['number_alone'] ) ) {
				// Alte Sets: "x/64" gibt es auch in anderen Sets → Name muss passen
				$named = array_values(
					array_filter(
						$nums,
						static function ( $n ) use ( $set, $full ) {
							foreach ( self::names( $set->cards[ $n ] ) as $name ) {
								if ( self::has_name( $full, $name ) ) {
									return true;
								}
							}
							return false;
						}
					)
				);
				if ( $named ) {
					$nums = count( $nums ) > 3 ? $nums : $named;
				} else {
					$conf = count( $nums ) > 3 && $m['set_context'] ? 'mittel' : 'niedrig';
				}
			}
			$m['nums']       = $nums;
			$m['confidence'] = $conf;
			$m['is_lot']     = count( $nums ) > 3 || (bool) preg_match( self::LOT_RE, $t );
			return $m;
		}

		// --- 2. Namen -------------------------------------------------------
		$hint = null;
		foreach ( $def['hints'] as list( $rar, $pat ) ) {
			if ( preg_match( $pat, $full ) ) {
				$hint = $rar;
				break;
			}
		}
		$wants_mega = (bool) preg_match( '/\bmega\b|\bm[\s-]/', $full );
		$wants_ex   = (bool) preg_match( '/\bex\b|-ex\b/', $full );

		$groups  = array(); // core => [cards]
		$matched = array(); // core => längster gefundener Name
		foreach ( $set->cards as $c ) {
			if ( $c['generic'] && ! $m['set_context'] ) {
				continue;
			}
			foreach ( self::names( $c ) as $name ) {
				if ( self::has_name( $full, $name ) ) {
					$groups[ $c['core'] ][] = $c;
					if ( strlen( $name ) > strlen( $matched[ $c['core'] ] ?? '' ) ) {
						$matched[ $c['core'] ] = $name;
					}
					break;
				}
			}
		}
		// Längere Namen schlucken kürzere ("impostor professor oak" ⊃ "professor oak")
		foreach ( $matched as $core => $name ) {
			foreach ( $matched as $other => $oname ) {
				if ( $other !== $core && strlen( $oname ) > strlen( $name ) && false !== strpos( $oname, $name ) ) {
					unset( $groups[ $core ] );
				}
			}
		}
		if ( ! $groups ) {
			return $m;
		}

		$chosen    = array();
		$ambiguous = false;
		foreach ( $groups as $cands ) {
			$sel = $cands;
			if ( $hint ) {
				$f   = array_filter( $cands, static function ( $c ) use ( $hint ) { return $c['rarity'] === $hint; } );
				$sel = $f ? $f : $sel;
			} elseif ( count( $cands ) > 1 ) {
				$has_mega = (bool) array_filter( $cands, static function ( $c ) { return $c['mega']; } );
				$has_ex   = (bool) array_filter( $cands, static function ( $c ) { return $c['ex']; } );
				if ( $wants_mega && $has_mega ) {
					$sel = array_filter( $sel, static function ( $c ) { return $c['mega']; } );
				} elseif ( $has_ex && ! $wants_ex ) {
					$f   = array_filter( $sel, static function ( $c ) { return ! $c['ex']; } );
					$sel = $f ? $f : $sel;
				}
			}
			if ( count( $sel ) > 1 ) {
				$ambiguous = true;
			}
			foreach ( $sel as $c ) {
				$chosen[] = $c['num'];
			}
		}
		$chosen = array_values( array_unique( $chosen ) );
		sort( $chosen );

		$m['nums']       = $chosen;
		$m['ambiguous']  = $ambiguous;
		$m['is_lot']     = count( $groups ) > 3 || (bool) preg_match( self::LOT_RE, $t );
		$m['confidence'] = ( $m['set_context'] && ! $ambiguous ) ? 'mittel' : 'niedrig';
		return $m;
	}

	private static function valid_nums( array $raw, $max ) {
		$out = array();
		foreach ( $raw as $x ) {
			$n = (int) $x;
			if ( $n >= 1 && $n <= $max ) {
				$out[ $n ] = $n;
			}
		}
		ksort( $out );
		return array_values( $out );
	}

	/** "1-30, 45 48;116" -> [1..30, 45, 48, 116] (begrenzt auf 1..$max) */
	public static function parse_ranges( $text, $max ) {
		$out = array();
		preg_match_all( '/(\d{1,3})(?:\s*-\s*(\d{1,3}))?/', (string) $text, $mm, PREG_SET_ORDER );
		foreach ( $mm as $x ) {
			$lo = (int) $x[1];
			$hi = isset( $x[2] ) && '' !== $x[2] ? (int) $x[2] : $lo;
			for ( $n = min( $lo, $hi ); $n <= max( $lo, $hi ); $n++ ) {
				if ( $n >= 1 && $n <= $max ) {
					$out[ $n ] = $n;
				}
			}
		}
		ksort( $out );
		return array_values( $out );
	}

	/** '1.250 € VB' -> 1250.0 | "CHF 1'250.–" -> 1250.0 | '25.-' -> 25.0 | 'VB' -> null */
	public static function parse_amount( $text ) {
		$t = strtr( (string) $text, array( '’' => "'", '–' => '-' ) );
		if ( preg_match( '/verschenken|gratis/i', $t ) ) {
			return 0.0;
		}
		if ( ! preg_match( "/(\d{1,3}(?:[.']\d{3})+|\d+)(?:[.,](\d{1,2}))?(?!\d)/", $t, $m ) ) {
			return null;
		}
		$whole = preg_replace( "/[.']/", '', $m[1] );
		return (float) ( $whole . '.' . ( isset( $m[2] ) && '' !== $m[2] ? $m[2] : '0' ) );
	}
}
