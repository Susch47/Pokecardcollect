<?php
if ( 'cli' !== PHP_SAPI ) {
	exit; // Tests nur auf der Kommandozeile
}
/**
 * Minimaler WordPress-Ersatz für Tests der Scanner-Logik (Optionen, User-Meta, $wpdb über SQLite).
 * Nur was der Radar braucht – kein vollständiges WordPress.
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

class WP_Error {
	private $msg;
	public function __construct( $code = '', $msg = '' ) { $this->msg = $msg; }
	public function get_error_message() { return $this->msg; }
}

class Fake_WPDB {
	public $prefix = 'wp_';
	public $usermeta = 'wp_usermeta';
	public $options = 'wp_options';
	public $pdo;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->exec( 'CREATE TABLE wp_usermeta (umeta_id INTEGER PRIMARY KEY, user_id INT, meta_key TEXT, meta_value TEXT)' );
	}
	public function get_charset_collate() { return ''; }
	public function prepare( $sql, ...$args ) {
		$args = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
		$i    = 0;
		$pdo  = $this->pdo;
		return preg_replace_callback(
			'/%[sdf]/',
			static function ( $m ) use ( &$i, $args, $pdo ) {
				$v = $args[ $i++ ];
				return '%s' === $m[0] ? $pdo->quote( (string) $v ) : ( '%d' === $m[0] ? (int) $v : (float) $v );
			},
			$sql
		);
	}
	public function query( $sql ) { return $this->pdo->exec( $sql ); }
	public function get_results( $sql, $output = 'OBJECT' ) {
		return $this->pdo->query( $sql )->fetchAll( 'ARRAY_A' === $output ? PDO::FETCH_ASSOC : PDO::FETCH_OBJ );
	}
	public function get_var( $sql ) {
		$v = $this->pdo->query( $sql )->fetchColumn();
		return false === $v ? null : $v;
	}
	public function insert( $table, array $data ) {
		$cols = array_keys( $data );
		$st   = $this->pdo->prepare( "INSERT INTO $table (" . implode( ',', $cols ) . ') VALUES (' . implode( ',', array_fill( 0, count( $cols ), '?' ) ) . ')' );
		return $st->execute( array_values( $data ) );
	}
	public function update( $table, array $data, array $where ) {
		$set = implode( ',', array_map( static function ( $c ) { return "$c = ?"; }, array_keys( $data ) ) );
		$wh  = implode( ' AND ', array_map( static function ( $c ) { return "$c = ?"; }, array_keys( $where ) ) );
		$st  = $this->pdo->prepare( "UPDATE $table SET $set WHERE $wh" );
		return $st->execute( array_merge( array_values( $data ), array_values( $where ) ) );
	}
}
$GLOBALS['wpdb'] = new Fake_WPDB();

function dbDelta( $sql ) { // MySQL-Index-Zeilen für SQLite entfernen
	$sql = preg_replace( '/,\s*KEY [^\n]+/', '', $sql );
	$GLOBALS['wpdb']->pdo->exec( $sql );
}

$GLOBALS['__opts']  = array();
$GLOBALS['__http']  = array();
$GLOBALS['__admin'] = array( 1 );

function add_action() {}
function add_filter() {}
function wp_next_scheduled() { return 1; }
function wp_schedule_event() {}
function wp_clear_scheduled_hook() {}
function spawn_cron() {}
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d; }
function update_option( $k, $v ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function add_option( $k, $v ) {
	if ( array_key_exists( $k, $GLOBALS['__opts'] ) ) {
		return false;
	}
	$GLOBALS['__opts'][ $k ] = $v;
	return true;
}
function delete_option( $k ) { unset( $GLOBALS['__opts'][ $k ] ); return true; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function maybe_unserialize( $v ) { return is_string( $v ) && ( 'b:0;' === $v || false !== @unserialize( $v ) ) ? unserialize( $v ) : $v; }
function get_user_meta( $uid, $key, $single = false ) {
	$v = $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT meta_value FROM wp_usermeta WHERE user_id = %d AND meta_key = %s', $uid, $key ) );
	return null === $v ? '' : maybe_unserialize( $v );
}
function update_user_meta( $uid, $key, $val ) {
	delete_user_meta( $uid, $key );
	$GLOBALS['wpdb']->insert( 'wp_usermeta', array( 'user_id' => $uid, 'meta_key' => $key, 'meta_value' => is_array( $val ) ? serialize( $val ) : $val ) );
	return true;
}
function delete_user_meta( $uid, $key ) {
	$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( 'DELETE FROM wp_usermeta WHERE user_id = %d AND meta_key = %s', $uid, $key ) );
}
function user_can( $uid ) { return in_array( $uid, $GLOBALS['__admin'], true ); }
function wp_date( $f ) { return date( $f ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000000'; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_remote_get( $url ) {
	$GLOBALS['__http'][] = array( 'GET', $url );
	return new WP_Error( 'offline', 'offline (Test)' );
}
function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['__http'][] = array( 'POST', $url, $args['body'] ?? '' );
	return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
}
