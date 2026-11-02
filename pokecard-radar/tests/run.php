<?php
if ( 'cli' !== PHP_SAPI ) {
	exit; // Tests nur auf der Kommandozeile
}
/**
 * Offline-Tests ohne WordPress:  php pokecard-radar/tests/run.php
 * (Port der Testfälle aus pitchtracker/tests/test_radar.py + Tests für Base Set/Jungle/Fossil)
 */
define( 'PCR_TESTING', true );
require __DIR__ . '/../includes/class-pcr-set.php';
require __DIR__ . '/../includes/class-pcr-matcher.php';
require __DIR__ . '/../includes/class-pcr-sources.php';

$fails = 0;
function check( $cond, $label ) {
	global $fails;
	if ( ! $cond ) {
		$fails++;
		echo "FAIL $label\n";
	}
}
function nums( $set, $title, $body = '' ) {
	return PCR_Matcher::match( PCR_Set::get( $set ), $title, $body )['nums'];
}
function m( $set, $title, $body = '' ) {
	return PCR_Matcher::match( PCR_Set::get( $set ), $title, $body );
}

// --- Karten ---------------------------------------------------------------
$pb = PCR_Set::get( 'pitch-black' );
check( 120 === $pb->total() && 'SIR' === $pb->cards[116]['rarity'] && 'MHR' === $pb->cards[120]['rarity'], 'pb cards' );
check( 'Mega-Darkrai-ex' === $pb->cards[48]['de'] && 'Lahmus' === $pb->cards[30]['de'], 'pb de names' );
check( '116/084' === $pb->cards[116]['code'], 'pb code' );
check( 102 === PCR_Set::get( 'base1' )->total() && 64 === PCR_Set::get( 'base2' )->total() && 62 === PCR_Set::get( 'base3' )->total(), 'vintage totals' );
check( 'Glurak' === PCR_Set::get( 'base1' )->cards[4]['de'] && '4/102' === PCR_Set::get( 'base1' )->cards[4]['code'], 'base1 glurak' );
check( 'glurak 4/102' === PCR_Set::get( 'base1' )->targeted_query( 4 ) && 'darkrai 120' === $pb->targeted_query( 120 ), 'targeted query' );

// --- Beträge & Bereiche -----------------------------------------------------
check( 1250.0 === PCR_Matcher::parse_amount( '1.250 € VB' ) && 1250.0 === PCR_Matcher::parse_amount( "CHF 1'250.–" ), 'amount 1' );
check( 25.0 === PCR_Matcher::parse_amount( '25.-' ) && 12.5 === PCR_Matcher::parse_amount( '12,50 €' ), 'amount 2' );
check( null === PCR_Matcher::parse_amount( 'VB' ) && 0.0 === PCR_Matcher::parse_amount( 'Gratis' ), 'amount 3' );
check( array( 85, 86, 87, 88, 116, 120 ) === PCR_Matcher::parse_ranges( '85-88, 116;120', 120 ), 'ranges' );
check( array( 1, 2 ) === PCR_Matcher::parse_ranges( '0-2, 500', 120 ), 'ranges clamp' );

// --- Matcher Pitch Black (Fälle aus test_radar.py) ----------------------------
check( array( 116 ) === nums( 'pitch-black', 'Mega Darkrai ex 116/084 SIR Dunkelnacht' ), 'pb number' );
check( array( 119 ) === nums( 'pitch-black', 'Pokemon Dunkelnacht Gwynn SIR' ), 'pb gwynn sir' );
check( array( 90 ) === nums( 'pitch-black', 'Lahmus Illustration Rare Dunkelnacht' ), 'pb lahmus ir' );
check( (bool) m( 'pitch-black', 'Suche Mega Darkrai 116/084' )['excluded'], 'pb wanted' );
check( (bool) m( 'pitch-black', 'Mega Darkrai SIR japanisch' )['excluded'], 'pb japanese' );
check( (bool) m( 'pitch-black', 'Dunkelnacht Display' )['excluded'], 'pb display' );
check( array() === nums( 'pitch-black', 'Glurak ex 199/165' ), 'pb other set' );
$x = m( 'pitch-black', 'Mega Darkrai ex Dunkelnacht' );
check( $x['ambiguous'] && 'niedrig' === $x['confidence'], 'pb ambiguous' );
$x = m( 'pitch-black', 'Dunkelnacht Sammlung 40 Karten', 'u.a. 001/084, 002/084, 090/084, 119/084' );
check( array( 1, 2, 90, 119 ) === $x['nums'] && $x['is_lot'], 'pb lot' );
// "CH" (Schweiz) darf nicht als Chinesisch gelten
check( ! m( 'pitch-black', 'Gwynn SIR Dunkelnacht', 'Versand in die ganze CH' )['excluded'], 'ch is not chinese' );

// --- Matcher Base Set / Jungle / Fossil -------------------------------------
check( array( 4 ) === nums( 'base1', 'Glurak 4/102 Holo' ), 'base glurak number' );
check( array( 4 ) === nums( 'base1', 'Charizard Base Set Holo' ), 'base charizard name' );
$x = m( 'base1', 'Glurak 4/102 1st Edition' );
check( '1. Edition' === $x['edition'] && 'sicher' === $x['confidence'], 'base 1st edition' );
check( 'Shadowless' === m( 'base1', 'Turtok 2/102 Shadowless' )['edition'], 'base shadowless' );
check( (bool) m( 'base1', 'Glurak 4/102 Celebrations Classic Collection' )['excluded'], 'base celebrations reprint' );
check( (bool) m( 'base1', 'Glurak Base Set 2 Holo' )['excluded'], 'base set 2' );
check( array( 11 ) === nums( 'base2', 'Relaxo Holo Jungle' ), 'jungle holo' );
check( array( 27 ) === nums( 'base2', 'Relaxo nicht holo Jungle' ), 'jungle non-holo' );
$x = m( 'base2', 'Relaxo Jungle Pokemon' );
check( array( 11, 27 ) === $x['nums'] && $x['ambiguous'], 'jungle ambiguous' );
// Neo Revelation ist auch /64 – ohne passenden Namen nur "niedrig"
check( 'niedrig' === m( 'base2', 'Ho-Oh 7/64 Holo' )['confidence'], 'jungle /64 other set' );
check( array( 3 ) === nums( 'base3', 'Ditto Holo Fossil Set 1999' ), 'fossil ditto' );
check( array( 62 ) === nums( 'base3', 'Mysterious Fossil Pokemon Fossil Set' ), 'fossil trainer with context' );
check( array() === nums( 'base3', 'Antikes Fossil Dunkelnacht' ), 'fossil no false positive from pb' );
check( array( 73 ) === nums( 'base1', 'Impostor Professor Oak Base Set' ), 'longer name wins' );
check( array( 6 ) === nums( 'base2', 'Mr Mime Holo Jungle' ), 'mr mime without dot' );
check( array( 55 ) === nums( 'base1', 'Nidoran 55/102' ), 'nidoran alias' );

// --- Quellen ----------------------------------------------------------------
$items = PCR_Kleinanzeigen::parse( file_get_contents( __DIR__ . '/fixtures/ka_search.html' ) );
check( 2 === count( $items ) && 89.0 === $items[0]['price'] && '' !== $items[0]['image'], 'ka parse' );
check( 'ka:3101' === $items[0]['id'] && true === $items[0]['shipping'] && null === $items[1]['price'], 'ka parse 2' );
check( 'https://www.kleinanzeigen.de/s-anzeige:angebote/seite:2/glurak-4-102/k0' === PCR_Kleinanzeigen::url( 'Glurak 4/102', 2 ), 'ka url' );
$t = PCR_Tutti::convert(
	array(
		'listingID' => '77001', 'title' => 'Gwynn SIR Dunkelnacht', 'body' => 'neu', 'formattedPrice' => '45.-',
		'timestamp' => '2026-11-01T14:10:00+01:00',
		'postcodeInformation' => array( 'postcode' => '8157', 'locationName' => 'Dielsdorf', 'canton' => array( 'shortName' => 'ZH' ) ),
		'thumbnail' => array( 'normalRendition' => array( 'src' => 'https://c.tutti.ch/x.jpg' ) ),
		'seoInformation' => array( 'deSlug' => 'gwynn-sir' ),
	)
);
check( 45.0 === $t['price'] && 'CHF' === $t['currency'] && '/gwynn-sir/77001' === substr( $t['url'], -16 ), 'tutti convert' );
check( false !== strpos( $t['location'], 'Dielsdorf (ZH)' ) && 'https://c.tutti.ch/x.jpg' === $t['image'], 'tutti convert 2' );

echo $fails ? "\n$fails Test(s) fehlgeschlagen\n" : "Alle Tests ok\n";
exit( $fails ? 1 : 0 );
