<?php
if ( 'cli' !== PHP_SAPI ) {
	exit; // Tests nur auf der Kommandozeile
}
/**
 * Ende-zu-Ende-Test der Scanner-Logik mit WordPress-Ersatz:  php pokecard-radar/tests/engine-test.php
 * (Port von test_engine_end_to_end aus pitchtracker/tests/test_radar.py, erweitert um mehrere Nutzer)
 */
require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../includes/class-pcr-set.php';
require __DIR__ . '/../includes/class-pcr-matcher.php';
require __DIR__ . '/../includes/class-pcr-sources.php';
require __DIR__ . '/../includes/class-pcr-settings.php';
require __DIR__ . '/../includes/class-pcr-engine.php';

$fails = 0;
function check( $cond, $label ) {
	global $fails;
	if ( ! $cond ) {
		$fails++;
		echo "FAIL $label\n";
	}
}

class Fake_Source extends PCR_Source {
	public $calls = array();
	public $items;
	public $blocked = false;
	public function __construct( $name, array $items ) { $this->name = $name; $this->items = $items; }
	public function delay() { return 0; }
	public function search( $q, $page = 1 ) {
		$this->calls[] = $q;
		if ( $this->blocked ) {
			throw new PCR_Source_Blocked( $this->name . ' blockt (429)' );
		}
		return array( $this->items, false );
	}
}

function ka( $i, $title, $price, $body = '' ) {
	return array( 'id' => "ka:$i", 'source' => 'kleinanzeigen', 'title' => $title, 'body' => $body, 'url' => "https://www.kleinanzeigen.de/s-anzeige/x/$i",
		'image' => '', 'price_raw' => $price, 'price' => PCR_Matcher::parse_amount( $price ), 'currency' => 'EUR', 'location' => '66424 Homburg', 'posted' => 'Heute' );
}
function tu( $i, $title, $price, $body = '' ) {
	return array( 'id' => "tutti:$i", 'source' => 'tutti', 'title' => $title, 'body' => $body, 'url' => "https://www.tutti.ch/de/vi/x/$i",
		'image' => '', 'price_raw' => $price, 'price' => PCR_Matcher::parse_amount( $price ), 'currency' => 'CHF', 'location' => '8157 Dielsdorf (ZH)', 'posted' => '' );
}
function ids( array $hits ) {
	$o = array();
	foreach ( $hits as $h ) {
		$o[ $h['id'] ] = $h;
	}
	return $o;
}

update_option( 'pcr_settings', array( 'sets' => array( 'pitch-black', 'base1' ), 'targeted_per_cycle' => 3, 'telegram_token' => 'TOKEN' ) );
PCR_Engine::install();

$s1 = new Fake_Source( 'kleinanzeigen', array(
	ka( 1, 'Mega Darkrai ex 116/084 SIR Dunkelnacht', '289 € VB' ),
	ka( 2, 'Dunkelnacht Sammlung 40 Karten', '35 €', 'u.a. 001/084, 002/084, 090/084, 119/084' ),
	ka( 3, 'Glurak ex 199/165', '120 €' ),
	ka( 4, 'Suche Gwynn SIR', '50 €' ),
	ka( 5, 'Base Set und Dunkelnacht Sammlung', '80 €', 'Glurak 4/102, Gwynn 119/084' ),
) );
$s2 = new Fake_Source( 'tutti', array(
	tu( 1, 'Pokemon Dunkelnacht Gwynn SIR', 'CHF 55.–' ),
	tu( 2, 'Lahmus Pokemon Karte', '3.-' ),
) );
PCR_Engine::$sources = array( 'kleinanzeigen' => $s1, 'tutti' => $s2 );

// Nutzer 1 hat das Pitch-Black-Hauptset komplett, Nutzer 2 hat noch nichts eingetragen
PCR_Engine::set_owned( 1, 'pitch-black', range( 1, 84 ) );
update_user_meta( 1, 'pcr_telegram_chat', '4242' );

// --- Scan 1 -------------------------------------------------------------------
PCR_Engine::tick();
$rt = PCR_Engine::runtime();
check( 1 === $rt['cycle'] && ! $rt['queue'] && ! $rt['cycle_running'], 'cycle finished' );
check( 'darkrai 120' === $s1->calls[3], 'targeted: secret rares first (' . ( $s1->calls[3] ?? '' ) . ')' );
check( in_array( 'zapdos 16/102', $s1->calls, true ) && ! in_array( 'super energy removal 79/102', $s1->calls, true ), 'base1 targeted: holos before trainers' );
check( true === $rt['status']['kleinanzeigen']['ok'] && true === $rt['status']['tutti']['ok'], 'status ok' );

$st = PCR_Engine::state( 'pitch-black', 1 );
$h  = ids( $st['hits'] );
check( isset( $h['ka:1'], $h['ka:2'], $h['tutti:1'] ) && ! isset( $h['ka:3'] ) && ! isset( $h['ka:4'] ), 'hits user1: ' . implode( ',', array_keys( $h ) ) );
check( 'niedrig' === $h['tutti:2']['confidence'] && array( 90 ) === $h['tutti:2']['missing_nums'], 'tutti:2 Lahmus ohne Set-Bezug = unsicher' );
check( array( 90, 119 ) === $h['ka:2']['missing_nums'] && $h['ka:2']['is_lot'], 'lot missing nums' );
check( round( 289 * 0.94, 2 ) === $h['ka:1']['price_chf'], 'price chf' );
$cards = array_column( $st['cards'], 'hits', 'num' );
check( 3 === $cards[119], 'card 119 hits = KA-Lot + tutti + Mix-Lot (' . $cards[119] . ')' );
check( 84 === $st['owned'] && $st['configured'] && $st['is_admin'], 'owned/configured/admin' );
check( ! $h['ka:1']['viewed'], 'new hits not viewed' );

// Anzeige mit Bezug auf zwei Sets landet in beiden
$b = ids( PCR_Engine::state( 'base1', 2 )['hits'] );
check( isset( $b['ka:5'] ) && array( 4 ) === $b['ka:5']['missing_nums'], 'cross-set lot in base1' );

// Nutzer 2 (nichts eingetragen) sieht alles als fehlend
$st2 = PCR_Engine::state( 'pitch-black', 2 );
check( ! $st2['configured'] && 0 === $st2['owned'] && isset( ids( $st2['hits'] )['tutti:2'] ) && ! $st2['is_admin'] && ! $st2['log'], 'user2 view' );

// Telegram nur an Nutzer 1, nur für sicher/mittel erkannte, ihm fehlende Karten
$tg = array_filter( $GLOBALS['__http'], static function ( $c ) { return 'POST' === $c[0] && false !== strpos( $c[1], 'api.telegram.org' ); } );
check( count( $tg ) >= 3, 'telegram sent (' . count( $tg ) . ')' );
foreach ( $tg as $c ) {
	check( false !== strpos( $c[2], '"chat_id":"4242"' ) && false === strpos( $c[2], 'Lahmus Pokemon Karte' ), 'telegram content' );
}
check( false !== strpos( implode( "\n", $rt['log'] ), 'TCGdex nicht erreichbar' ), 'tcgdex offline logged' );

// --- Scan 2: Intervall noch nicht um → nichts -------------------------------------
$before = count( $s1->calls );
PCR_Engine::tick();
check( count( $s1->calls ) === $before, 'no scan before interval' );

// --- Scan 3: erzwungen, Preis gesenkt ----------------------------------------------
$s1->items[0]['price']     = 249.0;
$s1->items[0]['price_raw'] = '249 €';
PCR_Engine::request_scan();
PCR_Engine::tick();
check( 2 === PCR_Engine::runtime()['cycle'] && 2 === PCR_Engine::runtime()['rotation']['pitch-black'], 'forced scan + rotation' );
$h = ids( PCR_Engine::state( 'pitch-black', 1 )['hits'] );
check( $h['ka:1']['price_drop'] && 249.0 === $h['ka:1']['price'] && 289.0 === $h['ka:1']['first_price'], 'price drop' );

// 119 als vorhanden → tutti:1 verschwindet; ausblenden; gesehen
PCR_Engine::set_owned( 1, 'pitch-black', array_merge( range( 1, 84 ), array( 119 ) ) );
$h = ids( PCR_Engine::state( 'pitch-black', 1 )['hits'] );
check( ! isset( $h['tutti:1'] ) && array( 90 ) === $h['ka:2']['missing_nums'], 'owned 119 removes tutti:1' );
update_user_meta( 1, 'pcr_hidden', array( 'pitch-black|ka:1' => time() ) );
update_user_meta( 1, 'pcr_seen_pitch-black', gmdate( 'Y-m-d H:i:s', time() + 5 ) );
$h = ids( PCR_Engine::state( 'pitch-black', 1 )['hits'] );
check( ! isset( $h['ka:1'] ) && $h['ka:2']['viewed'], 'hide + viewed' );
check( isset( ids( PCR_Engine::state( 'pitch-black', 2 )['hits'] )['ka:1'] ), 'hide is per user' );

// --- Sperre: Quelle wird für den Rest des Zyklus übersprungen ----------------------
$s1->blocked = true;
$calls       = count( $s1->calls );
PCR_Engine::request_scan();
PCR_Engine::tick();
$rt = PCR_Engine::runtime();
check( count( $s1->calls ) === $calls + 1, 'blocked source skipped after first 429' );
check( false === $rt['status']['kleinanzeigen']['ok'] && true === $rt['status']['tutti']['ok'], 'status after block' );

// --- Zeitbudget: Warteschlange wird über mehrere Läufe abgearbeitet -----------------
$slow = new class( 'tutti', array() ) extends Fake_Source {
	public function delay() { return 0.4; }
};
PCR_Engine::$sources = array( 'tutti' => $slow );
update_option( 'pcr_settings', array_merge( get_option( 'pcr_settings' ), array( 'time_budget' => 1 ) ) );
PCR_Engine::request_scan();
PCR_Engine::tick();
check( PCR_Engine::runtime()['queue'] && PCR_Engine::runtime()['cycle_running'], 'budget leaves queue for next tick' );
for ( $i = 0; $i < 30 && PCR_Engine::runtime()['queue']; $i++ ) {
	PCR_Engine::tick();
}
check( ! PCR_Engine::runtime()['queue'] && 4 === PCR_Engine::runtime()['cycle'], 'queue drained over several ticks' );

echo $fails ? "\n$fails Test(s) fehlgeschlagen\n" : "Engine-Tests ok\n";
exit( $fails ? 1 : 0 );
