<?php
/**
 * Scanner: läuft über WP-Cron in kurzen Häppchen (Job-Warteschlange), speichert Treffer
 * in einer eigenen Tabelle und baut den Zustand pro Nutzer. Port von pitchtracker/engine.py.
 *
 * Gescannt wird einmal pro Set (nicht pro Nutzer). Jeder Nutzer sieht nur die Angebote
 * zu den Karten, die ihm selbst fehlen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCR_Engine {

	const HOOK       = 'pcr_tick';
	const SCHEDULE   = 'pcr_every_minute';
	const RUNTIME    = 'pcr_runtime';
	const LOCK       = 'pcr_lock';
	const FORCE      = 'pcr_force_scan';
	const DB_VERSION = '1';
	const TCGDEX     = 'https://api.tcgdex.net/v2/de';

	/** @var PCR_Source[]|null Für Tests austauschbar */
	public static $sources = null;

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	// ------------------------------------------------------------------ setup
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'pcr_hits';
	}

	public static function install() {
		global $wpdb;
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $t (
  set_key varchar(40) NOT NULL,
  id varchar(80) NOT NULL,
  source varchar(20) NOT NULL DEFAULT '',
  title text,
  body text,
  url text,
  image text,
  price double DEFAULT NULL,
  currency varchar(3) NOT NULL DEFAULT '',
  price_raw varchar(80) NOT NULL DEFAULT '',
  location varchar(160) NOT NULL DEFAULT '',
  posted varchar(40) NOT NULL DEFAULT '',
  nums text,
  confidence varchar(10) NOT NULL DEFAULT '',
  ambiguous tinyint(1) NOT NULL DEFAULT 0,
  is_lot tinyint(1) NOT NULL DEFAULT 0,
  graded varchar(20) NOT NULL DEFAULT '',
  language varchar(4) NOT NULL DEFAULT '',
  edition varchar(20) NOT NULL DEFAULT '',
  first_seen datetime NOT NULL,
  last_seen datetime NOT NULL,
  first_price double DEFAULT NULL,
  query varchar(200) NOT NULL DEFAULT '',
  PRIMARY KEY  (set_key,id),
  KEY last_seen (last_seen)
) $charset;"
		);
		update_option( 'pcr_db_version', self::DB_VERSION, false );
		self::ensure_scheduled();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::HOOK );
		delete_option( self::LOCK );
	}

	public static function schedules( $s ) {
		$s[ self::SCHEDULE ] = array(
			'interval' => 60,
			'display'  => 'Jede Minute (PokéCard Radar)',
		);
		return $s;
	}

	public static function ensure_scheduled() {
		if ( get_option( 'pcr_db_version' ) !== self::DB_VERSION ) {
			self::install();
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 30, self::SCHEDULE, self::HOOK );
		}
	}

	// ---------------------------------------------------------------- runtime
	public static function runtime() {
		$rt = get_option( self::RUNTIME );
		return wp_parse_args(
			is_array( $rt ) ? $rt : array(),
			array(
				'queue'         => array(),
				'cycle'         => 0,
				'cycle_running' => false,
				'cycle_new'     => 0,
				'last_scan'     => 0,
				'next_cycle_at' => 0,
				'status'        => array(),
				'last_request'  => array(),
				'rotation'      => array(),
				'log'           => array(),
			)
		);
	}

	private static function save_runtime( array $rt ) {
		update_option( self::RUNTIME, $rt, false );
	}

	private static function log( array &$rt, $msg ) {
		$rt['log']   = array_slice( array_merge( $rt['log'], array( wp_date( 'd.m. H:i:s' ) . ' ' . $msg ) ), -60 );
	}

	public static function request_scan() {
		update_option( self::FORCE, 1, false );
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/** @return PCR_Source[] */
	public static function sources() {
		if ( null !== self::$sources ) {
			return self::$sources;
		}
		$o   = PCR_Settings::get();
		$out = array();
		if ( $o['ka_enabled'] ) {
			$out['kleinanzeigen'] = new PCR_Kleinanzeigen( $o['ka_delay_min'], $o['ka_delay_max'] );
		}
		if ( $o['tutti_enabled'] ) {
			$out['tutti'] = new PCR_Tutti( $o['tutti_delay'] );
		}
		return $out;
	}

	private static function lock() {
		if ( add_option( self::LOCK, time(), '', false ) ) {
			return true;
		}
		$since = (int) get_option( self::LOCK );
		if ( time() - $since > 300 ) { // abgestürzter Lauf
			update_option( self::LOCK, time(), false );
			return true;
		}
		return false;
	}

	private static function unlock() {
		delete_option( self::LOCK );
	}

	// ------------------------------------------------------------------ users
	/** Besitz eines Nutzers; null = Nutzer hat für dieses Set noch nichts eingetragen */
	public static function owned( $user_id, $set_key ) {
		$v = get_user_meta( $user_id, 'pcr_owned_' . $set_key, true );
		return is_array( $v ) ? array_map( 'intval', $v ) : null;
	}

	public static function set_owned( $user_id, $set_key, array $owned ) {
		$owned = array_values( array_unique( array_map( 'intval', $owned ) ) );
		sort( $owned );
		update_user_meta( $user_id, 'pcr_owned_' . $set_key, $owned );
		return $owned;
	}

	/** @return array<int,int[]> user_id => fehlende Nummern (nur Nutzer, die das Set pflegen) */
	public static function tracking_users( PCR_Set $set ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", 'pcr_owned_' . $set->key ) );
		$out  = array();
		foreach ( $rows as $r ) {
			$owned                       = maybe_unserialize( $r->meta_value );
			$out[ (int) $r->user_id ] = array_values( array_diff( $set->nums(), is_array( $owned ) ? array_map( 'intval', $owned ) : array() ) );
		}
		return $out;
	}

	// ------------------------------------------------------------------- cycle
	public static function queries_for_cycle( PCR_Set $set, array &$rt ) {
		$q     = PCR_Settings::queries( $set );
		$users = self::tracking_users( $set );
		$miss  = $users ? array_unique( array_merge( ...array_values( $users ) ) ) : $set->nums();
		$rar   = $set->def['targeted'];
		$t     = array_values( array_filter( $miss, static function ( $n ) use ( $set, $rar ) { return in_array( $set->cards[ $n ]['rarity'], $rar, true ); } ) );
		// Wertvollste zuerst: Marktpreis, dann Seltenheit (in 'targeted' aufsteigend sortiert), dann hohe Nummer
		usort(
			$t,
			static function ( $a, $b ) use ( $set, $rar ) {
				$ca = $set->cards[ $a ];
				$cb = $set->cards[ $b ];
				return array( $cb['market'] ?? -1, array_search( $cb['rarity'], $rar, true ), $b )
					<=> array( $ca['market'] ?? -1, array_search( $ca['rarity'], $rar, true ), $a );
			}
		);
		$k = (int) PCR_Settings::get( 'targeted_per_cycle' );
		if ( $t && $k ) {
			$rot   = (int) ( $rt['rotation'][ $set->key ] ?? 0 );
			$start = ( $rot * $k ) % count( $t );
			$pick  = array_slice( array_merge( $t, $t ), $start, min( $k, count( $t ) ) );
			foreach ( $pick as $n ) {
				$q[] = $set->targeted_query( $n );
			}
			$rt['rotation'][ $set->key ] = $rot + 1;
		}
		return array_values( array_unique( $q ) );
	}

	private static function start_cycle( array &$rt ) {
		$jobs    = array();
		$sources = array_keys( self::sources() );
		foreach ( PCR_Settings::sets() as $set ) {
			foreach ( self::queries_for_cycle( $set, $rt ) as $q ) {
				foreach ( $sources as $src ) { // abwechselnd -> Pausen überlappen
					$jobs[] = array( 'type' => 'search', 'set' => $set->key, 'source' => $src, 'q' => $q, 'page' => 1 );
				}
			}
			$prices = get_option( 'pcr_prices_' . $set->key );
			if ( ! is_array( $prices ) || time() - (int) ( $prices['ts'] ?? 0 ) > 20 * HOUR_IN_SECONDS ) {
				$jobs[] = array( 'type' => 'prices', 'set' => $set->key, 'todo' => null );
			}
		}
		foreach ( $sources as $src ) {
			$rt['status'][ $src ]          = wp_parse_args( $rt['status'][ $src ] ?? array(), array( 'ok' => null, 'error' => '', 'last' => 0 ) );
			$rt['status'][ $src ]['found'] = 0;
		}
		$rt['queue']         = $jobs;
		$rt['cycle_running'] = true;
		$rt['cycle_new']     = 0;
		self::log( $rt, 'Scan #' . ( $rt['cycle'] + 1 ) . ' gestartet: ' . count( $jobs ) . ' Jobs' );
	}

	private static function finish_cycle( array &$rt ) {
		global $wpdb;
		$rt['cycle']++;
		$rt['cycle_running'] = false;
		$rt['last_scan']     = time();
		$rt['next_cycle_at'] = time() + 60 * (int) PCR_Settings::get( 'interval_minutes' );
		self::log( $rt, 'Scan #' . $rt['cycle'] . ' fertig: ' . $rt['cycle_new'] . ' neue Treffer' );
		$cut = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * ( 14 + (int) PCR_Settings::get( 'keep_days' ) ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE last_seen < %s', $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Cron-Einstieg: arbeitet Jobs ab, bis das Zeitbudget erschöpft ist. */
	public static function tick() {
		if ( ! self::lock() ) {
			return;
		}
		try {
			$budget = (int) PCR_Settings::get( 'time_budget' );
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( $budget + 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			$rt    = self::runtime();
			$force = get_option( self::FORCE );
			if ( ! $rt['queue'] && ( $force || time() >= (int) $rt['next_cycle_at'] ) ) {
				delete_option( self::FORCE );
				self::start_cycle( $rt );
				self::save_runtime( $rt );
			}
			$start   = microtime( true );
			$sources = self::sources();
			while ( $rt['queue'] && microtime( true ) - $start < $budget ) {
				$job = $rt['queue'][0];
				if ( 'search' === $job['type'] ) {
					if ( ! isset( $sources[ $job['source'] ] ) ) {
						array_shift( $rt['queue'] );
						continue;
					}
					$src  = $sources[ $job['source'] ];
					$wait = $src->delay() - ( microtime( true ) - (float) ( $rt['last_request'][ $src->name ] ?? 0 ) );
					if ( $wait > 0 ) {
						if ( microtime( true ) - $start + $wait > $budget ) {
							break; // nächster Cron-Lauf
						}
						usleep( (int) ( $wait * 1e6 ) );
					}
				}
				array_shift( $rt['queue'] );
				self::run_job( $job, $rt );
				self::save_runtime( $rt );
			}
			if ( ! $rt['queue'] && $rt['cycle_running'] ) {
				self::finish_cycle( $rt );
				self::save_runtime( $rt );
			}
		} finally {
			self::unlock();
		}
	}

	private static function run_job( array $job, array &$rt ) {
		if ( 'prices' === $job['type'] ) {
			self::run_prices_job( $job, $rt );
			return;
		}
		$set = PCR_Set::get( $job['set'] );
		$src = self::sources()[ $job['source'] ];
		$st  = &$rt['status'][ $src->name ];
		try {
			list( $items, $more ) = $src->search( $job['q'], $job['page'] );
			$rt['last_request'][ $src->name ] = microtime( true );
			$st['found']                     += count( $items );
			foreach ( $items as $item ) {
				$rt['cycle_new'] += count( self::ingest_everywhere( $set, $item, $job['q'] ) );
			}
			if ( $more && $job['page'] < (int) PCR_Settings::get( 'ka_pages' ) ) {
				array_unshift( $rt['queue'], array_merge( $job, array( 'page' => $job['page'] + 1 ) ) );
			}
			$st['ok']    = true;
			$st['error'] = '';
			$st['last']  = time();
		} catch ( PCR_Source_Blocked $e ) {
			$rt['last_request'][ $src->name ] = microtime( true );
			$st['ok']                         = false;
			$st['error']                      = $e->getMessage();
			$st['last']                       = time();
			// Rest dieser Quelle in diesem Zyklus überspringen
			$rt['queue'] = array_values( array_filter( $rt['queue'], static function ( $j ) use ( $src ) { return ( $j['source'] ?? '' ) !== $src->name; } ) );
			self::log( $rt, $src->name . ': ' . $e->getMessage() . ' → pausiert bis zum nächsten Zyklus' );
		} catch ( Exception $e ) {
			$rt['last_request'][ $src->name ] = microtime( true );
			$st['ok']                         = false;
			$st['error']                      = substr( $e->getMessage(), 0, 200 );
			$st['last']                       = time();
			self::log( $rt, $src->name . ': Fehler bei „' . $job['q'] . '“ – ' . $st['error'] );
		}
		unset( $st );
	}

	/**
	 * Ordnet eine Anzeige dem Such-Set zu und zusätzlich jedem anderen aktiven Set,
	 * auf das sie sich ausdrücklich bezieht (z. B. "Base Set + Jungle Sammlung").
	 *
	 * @return array neue Treffer
	 */
	public static function ingest_everywhere( PCR_Set $origin, array $item, $query ) {
		$new = array();
		foreach ( PCR_Settings::sets() as $set ) {
			$h = self::ingest( $set, $item, $query, $set->key !== $origin->key );
			if ( $h ) {
				$new[] = $h;
				self::notify( $set, $h );
			}
		}
		return $new;
	}

	/** @return array|null neuer Treffer oder null */
	public static function ingest( PCR_Set $set, array $item, $query, $needs_context = false ) {
		global $wpdb;
		$m = PCR_Matcher::match( $set, $item['title'], $item['body'] ?? '' );
		if ( $m['excluded'] || ! $m['nums'] || ( $needs_context && ! $m['set_context'] ) ) {
			return null;
		}
		$t   = self::table();
		$now = gmdate( 'Y-m-d H:i:s' );
		$ex  = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE set_key = %s AND id = %s", $set->key, $item['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = array(
			'last_seen' => $now,
			'price'     => $item['price'],
			'price_raw' => mb_substr( (string) $item['price_raw'], 0, 80 ),
			'title'     => $item['title'],
		);
		if ( $ex ) {
			$wpdb->update( $t, $row, array( 'set_key' => $set->key, 'id' => $item['id'] ) );
			return null;
		}
		$row += array(
			'set_key'     => $set->key,
			'id'          => $item['id'],
			'source'      => $item['source'],
			'body'        => mb_substr( (string) ( $item['body'] ?? '' ), 0, 600 ),
			'url'         => $item['url'],
			'image'       => $item['image'] ?? '',
			'currency'    => $item['currency'],
			'location'    => mb_substr( (string) ( $item['location'] ?? '' ), 0, 160 ),
			'posted'      => mb_substr( (string) ( $item['posted'] ?? '' ), 0, 40 ),
			'nums'        => wp_json_encode( $m['nums'] ),
			'confidence'  => $m['confidence'],
			'ambiguous'   => (int) $m['ambiguous'],
			'is_lot'      => (int) $m['is_lot'],
			'graded'      => $m['graded'],
			'language'    => $m['language'],
			'edition'     => $m['edition'],
			'first_seen'  => $now,
			'first_price' => $item['price'],
			'query'       => mb_substr( (string) $query, 0, 200 ),
		);
		if ( null === $row['price'] ) {
			unset( $row['price'], $row['first_price'] ); // NULL-Default der Tabelle
		}
		$wpdb->insert( $t, $row );
		return array_merge( $item, $m );
	}

	// ----------------------------------------------------------- notifications
	private static function notify( PCR_Set $set, array $h ) {
		$token = PCR_Settings::get( 'telegram_token' );
		if ( ! $token || 'niedrig' === $h['confidence'] ) {
			return;
		}
		$names = self::names( $set );
		foreach ( self::tracking_users( $set ) as $uid => $missing ) {
			$chat = get_user_meta( $uid, 'pcr_telegram_chat', true );
			$rel  = array_values( array_intersect( $h['nums'], $missing ) );
			if ( ! $chat || ! $rel ) {
				continue;
			}
			$cards = implode(
				', ',
				array_map(
					static function ( $n ) use ( $set, $names ) {
						$c = $set->cards[ $n ];
						return '#' . $set->code( $n ) . ' ' . ( $names[ $n ] ?? $c['de'] ) . ' (' . $c['rarity'] . ')';
					},
					$rel
				)
			);
			wp_remote_post(
				'https://api.telegram.org/bot' . rawurlencode( $token ) . '/sendMessage',
				array(
					'timeout'  => 10,
					'blocking' => false,
					'headers'  => array( 'Content-Type' => 'application/json' ),
					'body'     => wp_json_encode(
						array(
							'chat_id' => $chat,
							'text'    => "🃏 {$cards}\n{$h['title']}\n{$h['price_raw']} · {$h['source']} · {$h['location']}\n{$h['url']}",
						)
					),
				)
			);
		}
	}

	// ------------------------------------------------------------ TCGdex prices
	private static function names( PCR_Set $set ) {
		$n = get_option( 'pcr_names_' . $set->key );
		return is_array( $n ) ? $n : array();
	}

	/** Set mit deutschen TCGdex-Namen und Cardmarket-Preisen anreichern */
	public static function enriched_set( $key ) {
		static $done = array();
		$set         = PCR_Set::get( $key );
		if ( ! $set || isset( $done[ $key ] ) ) {
			return $set;
		}
		$done[ $key ] = true;
		$set->apply_names( self::names( $set ) );
		$p = get_option( 'pcr_prices_' . $key );
		if ( is_array( $p ) && ! empty( $p['eur'] ) ) {
			$set->apply_market( $p['eur'] );
		}
		return $set;
	}

	/** Preis aus TCGdex-Karte: konservativ min(Trend, Ø30), Holo-Variante bei Holo-Karten bevorzugt */
	public static function extract_price( array $card, $prefer_holo ) {
		$cm   = $card['pricing']['cardmarket'] ?? array();
		$norm = array_filter( array( $cm['trend'] ?? null, $cm['avg30'] ?? null ) );
		$holo = array_filter( array( $cm['trend-holo'] ?? null, $cm['avg30-holo'] ?? null ) );
		$pick = $prefer_holo ? ( $holo ? $holo : $norm ) : ( $norm ? $norm : $holo );
		return $pick ? round( (float) min( $pick ), 2 ) : null;
	}

	private static function tcgdex_get( $path ) {
		$res = wp_remote_get( self::TCGDEX . $path, array( 'timeout' => 20 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			throw new RuntimeException( 'TCGdex nicht erreichbar' . ( is_wp_error( $res ) ? ': ' . $res->get_error_message() : ' (HTTP ' . wp_remote_retrieve_response_code( $res ) . ')' ) );
		}
		return json_decode( wp_remote_retrieve_body( $res ), true );
	}

	private static function run_prices_job( array $job, array &$rt ) {
		$set   = PCR_Set::get( $job['set'] );
		$store = get_option( 'pcr_prices_' . $set->key );
		$store = is_array( $store ) ? $store : array( 'ts' => 0, 'eur' => array(), 'ids' => array() );
		try {
			if ( null === $job['todo'] ) {
				$data  = self::tcgdex_get( '/sets/' . rawurlencode( $set->def['tcgdex'] ) );
				$names = array();
				foreach ( (array) ( $data['cards'] ?? array() ) as $c ) {
					$n = (int) ( $c['localId'] ?? 0 );
					if ( isset( $set->cards[ $n ] ) && (string) $n === ltrim( (string) $c['localId'], '0' ) ) {
						$store['ids'][ $n ] = $c['id'];
						$names[ $n ]        = $c['name'] ?? '';
					}
				}
				update_option( 'pcr_names_' . $set->key, array_filter( $names ), false );
				update_option( 'pcr_prices_' . $set->key, $store, false );
				self::log( $rt, 'TCGdex ' . $set->key . ': ' . count( $names ) . ' deutsche Namen geladen' );
				$todo = array_keys( $store['ids'] );
			} else {
				$todo = $job['todo'];
				foreach ( array_splice( $todo, 0, 10 ) as $n ) {
					try {
						$card                = self::tcgdex_get( '/cards/' . rawurlencode( $store['ids'][ $n ] ) );
						$store['eur'][ $n ] = self::extract_price( $card, ! empty( $set->def['vintage'] ) && 'H' === $set->cards[ $n ]['rarity'] );
					} catch ( Exception $e ) {
						continue;
					}
					usleep( 300000 );
				}
				if ( ! $todo ) {
					$store['ts'] = time();
					self::log( $rt, 'TCGdex ' . $set->key . ': Cardmarket-Preise aktualisiert' );
				}
				update_option( 'pcr_prices_' . $set->key, $store, false );
			}
			if ( $todo ) {
				$rt['queue'][] = array( 'type' => 'prices', 'set' => $set->key, 'todo' => $todo );
			}
		} catch ( Exception $e ) {
			self::log( $rt, $e->getMessage() . ' – Namen/Preise aus eingebauter Liste' );
		}
	}

	// ------------------------------------------------------------- dashboard
	public static function iso( $mysql_gmt ) {
		return $mysql_gmt ? str_replace( ' ', 'T', $mysql_gmt ) . 'Z' : null;
	}

	public static function state( $set_key, $user_id, $show_all = false ) {
		global $wpdb;
		$set = self::enriched_set( $set_key );
		$o   = PCR_Settings::get();
		$rt  = self::runtime();

		$owned_raw = self::owned( $user_id, $set_key );
		$owned     = $owned_raw ? $owned_raw : array();
		$missing   = array_flip( array_diff( $set->nums(), $owned ) );
		$hidden    = get_user_meta( $user_id, 'pcr_hidden', true );
		$hidden    = is_array( $hidden ) ? $hidden : array();
		$seen_at   = (string) get_user_meta( $user_id, 'pcr_seen_' . $set_key, true );
		$eur_chf   = (float) $o['eur_chf'];
		$since     = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * (int) $o['keep_days'] );
		$stale_cut = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS * (float) $o['stale_hours'] );

		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE set_key = %s AND last_seen >= %s ORDER BY first_seen DESC LIMIT 600', $set_key, $since ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$hits     = array();
		$per_card = array();
		foreach ( $rows as $r ) {
			if ( isset( $hidden[ $set_key . '|' . $r['id'] ] ) ) {
				continue;
			}
			$nums    = array_map( 'intval', (array) json_decode( $r['nums'], true ) );
			$mnums   = array_values( array_filter( $nums, static function ( $n ) use ( $missing ) { return isset( $missing[ $n ] ); } ) );
			$price   = null === $r['price'] ? null : (float) $r['price'];
			$first   = null === $r['first_price'] ? null : (float) $r['first_price'];
			$pchf    = null === $price ? null : round( $price * ( 'EUR' === $r['currency'] ? $eur_chf : 1 ), 2 );
			$stale   = $r['last_seen'] < $stale_cut;
			$single  = 1 === count( $mnums ) && ! $r['is_lot'];
			$market  = $single ? $set->cards[ $mnums[0] ]['market'] : null;
			$mchf    = ( $market && ! $r['edition'] ) ? round( $market * $eur_chf, 2 ) : null;
			$suspect = $mchf && null !== $pchf && $pchf > 0 && ! $r['graded'] && $pchf < 0.35 * $mchf;

			if ( ! $mnums && ! $show_all ) {
				continue;
			}
			$hits[] = array(
				'id'           => $r['id'],
				'source'       => $r['source'],
				'title'        => $r['title'],
				'url'          => $r['url'],
				'image'        => $r['image'],
				'price'        => $price,
				'price_raw'    => $r['price_raw'],
				'currency'     => $r['currency'],
				'location'     => $r['location'],
				'nums'         => $nums,
				'missing_nums' => $mnums,
				'confidence'   => $r['confidence'],
				'ambiguous'    => (bool) $r['ambiguous'],
				'is_lot'       => (bool) $r['is_lot'],
				'graded'       => $r['graded'],
				'language'     => $r['language'],
				'edition'      => $r['edition'],
				'first_seen'   => self::iso( $r['first_seen'] ),
				'first_price'  => $first,
				'price_chf'    => $pchf,
				'price_drop'   => $first && null !== $price && $price < $first,
				'stale'        => $stale,
				'market_chf'   => $mchf,
				'suspicious'   => $suspect,
				'viewed'       => $seen_at && $r['first_seen'] <= $seen_at,
			);
			if ( 'niedrig' !== $r['confidence'] && ! $stale ) {
				foreach ( $mnums as $n ) {
					$per_card[ $n ] = ( $per_card[ $n ] ?? 0 ) + 1;
				}
			}
		}

		$cards = array();
		foreach ( $set->cards as $c ) {
			$cards[] = array(
				'num'          => $c['num'],
				'code'         => $c['code'],
				'de'           => $c['de'],
				'en'           => $c['en'],
				'rarity'       => $c['rarity'],
				'rarity_label' => $c['rarity_label'],
				'secret'       => $c['secret'],
				'market'       => $c['market'],
				'owned'        => ! isset( $missing[ $c['num'] ] ),
				'hits'         => $per_card[ $c['num'] ] ?? 0,
			);
		}

		$is_admin = user_can( $user_id, 'manage_options' );
		$status   = array();
		foreach ( self::sources() as $name => $src ) {
			$st              = $rt['status'][ $name ] ?? array();
			$status[ $name ] = array(
				'ok'    => $st['ok'] ?? null,
				'error' => $st['error'] ?? '',
				'last'  => ! empty( $st['last'] ) ? gmdate( 'c', $st['last'] ) : null,
			);
		}

		return array(
			'set'          => $set_key,
			'title'        => $set->def['title'],
			'subtitle'     => $set->def['subtitle'],
			'cards'        => $cards,
			'hits'         => $hits,
			'owned'        => count( $set->nums() ) - count( $missing ),
			'total'        => $set->total(),
			'configured'   => null !== $owned_raw,
			'scanning'     => ! empty( $rt['queue'] ),
			'last_scan'    => $rt['last_scan'] ? gmdate( 'c', $rt['last_scan'] ) : null,
			'next_scan'    => $rt['next_cycle_at'] ? gmdate( 'c', $rt['next_cycle_at'] ) : null,
			'status'       => $status,
			'cycle'        => $rt['cycle'],
			'log'          => $is_admin ? array_slice( $rt['log'], -15 ) : array(),
			'eur_chf'      => $eur_chf,
			'is_admin'     => $is_admin,
			'telegram'     => (bool) $o['telegram_token'],
			'telegram_chat' => (string) get_user_meta( $user_id, 'pcr_telegram_chat', true ),
		);
	}
}
