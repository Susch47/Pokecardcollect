<?php
/**
 * Set-Definitionen. Reines PHP ohne WordPress-Abhängigkeit (für Tests).
 *
 * Kartenzeilen: Nummer|englischer Name|Seltenheit[|Aliase, kommagetrennt][|g]
 *   g = allgemeiner Name (Trainer/Energie) -> nur mit Set-Bezug erkennen
 *
 * Deutsche Pokémon-Namen stehen in 'de' (Kernname ohne "Mega"/"ex").
 * Trainer-Namen auf Deutsch lädt der Radar zusätzlich aus TCGdex nach.
 */

if ( ! defined( 'PCR_SETS_LOADED' ) ) {
	define( 'PCR_SETS_LOADED', true );
}

/** Deutsche Namen der Gen-1-Pokémon (Base Set, Jungle, Fossil) */
function pcr_de_gen1() {
	return array(
		'alakazam' => 'simsala', 'blastoise' => 'turtok', 'chansey' => 'chaneira', 'charizard' => 'glurak',
		'clefairy' => 'piepi', 'gyarados' => 'garados', 'hitmonchan' => 'nockchan', 'machamp' => 'machomei',
		'magneton' => 'magneton', 'mewtwo' => 'mewtu', 'nidoking' => 'nidoking', 'ninetales' => 'vulnona',
		'poliwrath' => 'quappo', 'raichu' => 'raichu', 'venusaur' => 'bisaflor', 'zapdos' => 'zapdos',
		'beedrill' => 'bibor', 'dragonair' => 'dragonir', 'dugtrio' => 'digdri', 'electabuzz' => 'elektek',
		'electrode' => 'lektrobal', 'pidgeotto' => 'tauboga', 'arcanine' => 'arkani', 'charmeleon' => 'glutexo',
		'dewgong' => 'jugong', 'dratini' => 'dratini', "farfetch'd" => 'porenta', 'growlithe' => 'fukano',
		'haunter' => 'alpollo', 'ivysaur' => 'bisaknosp', 'jynx' => 'rossana', 'kadabra' => 'kadabra',
		'kakuna' => 'kokuna', 'machoke' => 'maschock', 'magikarp' => 'karpador', 'magmar' => 'magmar',
		'nidorino' => 'nidorino', 'poliwhirl' => 'quaputzi', 'porygon' => 'porygon', 'raticate' => 'rattikarl',
		'seel' => 'jurob', 'wartortle' => 'schillok', 'abra' => 'abra', 'bulbasaur' => 'bisasam',
		'caterpie' => 'raupy', 'charmander' => 'glumanda', 'diglett' => 'digda', 'doduo' => 'dodu',
		'drowzee' => 'traumato', 'gastly' => 'nebulak', 'koffing' => 'smogon', 'machop' => 'machollo',
		'magnemite' => 'magnetilo', 'metapod' => 'safcon', 'onix' => 'onix', 'pidgey' => 'taubsi',
		'pikachu' => 'pikachu', 'poliwag' => 'quapsel', 'ponyta' => 'ponita', 'rattata' => 'rattfratz',
		'sandshrew' => 'sandan', 'squirtle' => 'schiggy', 'starmie' => 'starmie', 'staryu' => 'sterndu',
		'tangela' => 'tangela', 'voltorb' => 'voltobal', 'vulpix' => 'vulpix', 'weedle' => 'hornliu',
		// Jungle
		'clefable' => 'pixi', 'flareon' => 'flamara', 'jolteon' => 'blitza', 'kangaskhan' => 'kangama',
		'mr. mime' => 'pantimos', 'nidoqueen' => 'nidoqueen', 'pidgeot' => 'tauboss', 'pinsir' => 'pinsir',
		'scyther' => 'sichlor', 'snorlax' => 'relaxo', 'vaporeon' => 'aquana', 'venomoth' => 'omot',
		'victreebel' => 'sarzenia', 'vileplume' => 'giflor', 'wigglytuff' => 'knuddeluff',
		'butterfree' => 'smettbo', 'dodrio' => 'dodri', 'exeggutor' => 'kokowei', 'fearow' => 'ibitak',
		'gloom' => 'duflor', 'lickitung' => 'schlurp', 'marowak' => 'knogga', 'nidorina' => 'nidorina',
		'parasect' => 'parasek', 'persian' => 'snobilikat', 'primeape' => 'rasaff', 'rapidash' => 'gallopa',
		'rhydon' => 'rizeros', 'seaking' => 'golking', 'tauros' => 'tauros', 'weepinbell' => 'ultrigaria',
		'bellsprout' => 'knofensa', 'cubone' => 'tragosso', 'eevee' => 'evoli', 'exeggcute' => 'owei',
		'goldeen' => 'goldini', 'jigglypuff' => 'pummeluff', 'mankey' => 'menki', 'meowth' => 'mauzi',
		'oddish' => 'myrapla', 'paras' => 'paras', 'rhyhorn' => 'rihorn', 'spearow' => 'habitak',
		'venonat' => 'bluzuk',
		// Fossil
		'aerodactyl' => 'aerodactyl', 'articuno' => 'arktos', 'ditto' => 'ditto', 'dragonite' => 'dragoran',
		'gengar' => 'gengar', 'hitmonlee' => 'kicklee', 'hypno' => 'hypno', 'kabutops' => 'kabutops',
		'lapras' => 'lapras', 'moltres' => 'lavados', 'muk' => 'sleimok', 'arbok' => 'arbok',
		'cloyster' => 'austos', 'golbat' => 'golbat', 'golduck' => 'entoron', 'golem' => 'geowaz',
		'graveler' => 'georok', 'kingler' => 'kingler', 'omastar' => 'amoroso', 'sandslash' => 'sandamer',
		'seadra' => 'seemon', 'slowbro' => 'lahmus', 'tentacruel' => 'tentoxa', 'weezing' => 'smogmog',
		'ekans' => 'rettan', 'geodude' => 'kleinstein', 'grimer' => 'sleima', 'horsea' => 'seeper',
		'kabuto' => 'kabuto', 'krabby' => 'krabby', 'omanyte' => 'amonitas', 'psyduck' => 'enton',
		'shellder' => 'muschas', 'slowpoke' => 'flegmon', 'tentacool' => 'tentacha', 'zubat' => 'zubat',
	);
}

function pcr_vintage_hints() {
	// Reihenfolge = Priorität. "nicht holo" muss vor "holo" stehen.
	return array(
		array( 'R', '/non[\s-]?holo|nicht[\s-]?holo|kein(e)?[\s-]?holo|ohne[\s-]?holo/i' ),
		array( 'H', '/\bholo(grafisch|graphisch)?\b|holo ?rare/i' ),
	);
}

function pcr_set_definitions() {
	$vintage_labels = array( 'H' => 'Holo Rare', 'R' => 'Rare', 'U' => 'Uncommon', 'C' => 'Common', 'E' => 'Energie' );

	return array(
		'pitch-black' => array(
			'label'        => 'Pitch Black / Dunkelnacht',
			'title'        => 'Dunkelnacht-Radar',
			'subtitle'     => 'ME05 Pitch Black',
			'printed'      => 84,
			'pad'          => true,
			'tcgdex'       => 'me05',
			'context'      => '/dunkel ?nacht|pitch ?black|\bme ?0?5\b|\bpbl\b|\/\s*0?84\b/i',
			'exclude'      => array( array( '/abyss ?eye/i', 'andere Sprache' ) ),
			'queries'      => array( 'dunkelnacht pokemon', 'pitch black pokemon', 'pokemon me05' ),
			'targeted'     => array( 'RR', 'H', 'IR', 'UR', 'SIR', 'MHR' ),
			'targeted_fmt' => '{name} {num}',
			'number_alone' => true,
			'hints'        => array(
				array( 'MHR', '/hyper ?rare|\bmhr\b|\bmur\b|\bgold(en|ene|ener)?\b/i' ),
				array( 'SIR', '/\bsir\b|\bsar\b|special ?illustration|spezial[\s-]?illustration/i' ),
				array( 'IR', '/\bir\b|\bar\b|illustration ?rare|art ?rare/i' ),
				array( 'UR', '/\bur\b|ultra ?rare|full ?art|\bfa\b|\bsr\b/i' ),
			),
			'rarity_labels' => array(
				'C' => 'Common', 'U' => 'Uncommon', 'H' => 'Holo Rare', 'RR' => 'Double Rare',
				'IR' => 'Illustration Rare', 'UR' => 'Ultra Rare (Full Art)',
				'SIR' => 'Special Illustration Rare', 'MHR' => 'Mega Hyper Rare',
			),
			'de'           => array(
				'tropius' => 'tropius', 'grubbin' => 'mabula', 'fomantis' => 'imantis', 'lurantis' => 'mantidea',
				'poltchageist' => 'mortcha', 'sinistcha' => 'fatalitcha', 'heatran' => 'heatran',
				'delphox' => 'fennexis', 'sizzlipede' => 'thermopod', 'centiskorch' => 'infernopod',
				'charcadet' => 'knarbon', 'armarouge' => 'crimanzo', 'goldeen' => 'goldini', 'seaking' => 'golking',
				'wailmer' => 'wailmer', 'wailord' => 'wailord', 'relicanth' => 'relicanth', 'popplio' => 'robball',
				'brionne' => 'marikeck', 'primarina' => 'primarene', 'finizen' => 'normifin',
				'palafin' => 'delfinator', 'electrike' => 'frizelbliz', 'manectric' => 'voltenso',
				'charjabug' => 'akkup', 'vikavolt' => 'donarion', 'zeraora' => 'zeraora', 'miraidon' => 'miraidon',
				'slowpoke' => 'flegmon', 'slowbro' => 'lahmus', 'jynx' => 'rossana', 'shuppet' => 'shuppet',
				'banette' => 'banette', 'spiritomb' => 'kryppuk', 'litwick' => 'lichtel', 'lampent' => 'laternecto',
				'chandelure' => 'skelabra', 'dhelmise' => 'moruda', 'marshadow' => 'marshadow',
				'annihilape' => 'epitaff', 'mankey' => 'menki', 'primeape' => 'rasaff', 'cranidos' => 'koknodon',
				'rampardos' => 'rameidon', 'drilbur' => 'rotomurf', 'excadrill' => 'stalobor',
				'koraidon' => 'koraidon', 'darkrai' => 'darkrai', 'vullaby' => 'skallyk',
				'mandibuzz' => 'grypheldis', 'inkay' => 'iscalar', 'malamar' => 'calamanero',
				'nickit' => 'kleptifux', 'thievul' => 'gaunux', 'morpeko' => 'morpeko', 'zarude' => 'zarude',
				'maschiff' => 'mobtiff', 'mabosstiff' => 'mastifioso', 'chi-yu' => 'chi-yu',
				'skarmory' => 'panzaeron', 'shieldon' => 'schilterus', 'bastiodon' => 'bollterus',
				'bronzor' => 'bronzel', 'bronzong' => 'bronzong', 'pikipek' => 'peppeck',
				'trumbeak' => 'trompeck', 'toucannon' => 'tukanon', 'type: null' => 'typ:null',
				'silvally' => 'amigento', 'bombirdier' => 'adebom',
			),
			'cards'        => <<<'CARDS'
1|Tropius|C
2|Grubbin|C
3|Fomantis|C
4|Lurantis ex|RR
5|Poltchageist|C
6|Sinistcha|U
7|Heatran|U
8|Mega Delphox ex|RR
9|Sizzlipede|C
10|Centiskorch|C
11|Charcadet|C
12|Armarouge|H
13|Goldeen|C
14|Seaking|U
15|Wailmer|C
16|Wailord ex|RR
17|Relicanth|U
18|Popplio|C
19|Brionne|C
20|Primarina|H
21|Finizen|C
22|Palafin|U
23|Electrike|C
24|Manectric|U
25|Charjabug|C
26|Vikavolt|U
27|Mega Zeraora ex|RR
28|Miraidon|H
29|Slowpoke|C
30|Slowbro|U
31|Mega Slowbro ex|RR
32|Jynx|C
33|Shuppet|C
34|Banette|C
35|Spiritomb|H
36|Litwick|C
37|Lampent|U
38|Mega Chandelure ex|RR
39|Dhelmise|U
40|Marshadow|U
41|Annihilape|U
42|Mankey|C
43|Primeape|C
44|Cranidos|C
45|Rampardos ex|RR
46|Drilbur|C
47|Koraidon|H
48|Mega Darkrai ex|RR
49|Vullaby|C
50|Mandibuzz|C
51|Inkay|C
52|Malamar|U
53|Nickit|C
54|Thievul|U
55|Morpeko ex|RR
56|Zarude|H
57|Maschiff|C
58|Mabosstiff|C
59|Chi-Yu|H
60|Skarmory|C
61|Shieldon|C
62|Bastiodon|H
63|Bronzor|C
64|Bronzong|U
65|Mega Excadrill ex|RR
66|Pikipek|C
67|Trumbeak|C
68|Toucannon|U
69|Type: Null|C
70|Silvally|H
71|Bombirdier|C
72|Antique Armor Fossil|C||g
73|Antique Skull Fossil|C||g
74|Backtrack Badge|U||g
75|Dark Bell|U||g
76|Fossil Quarry|U||g
77|Gladion's Final Battle|U
78|Gwynn|U
79|Jett|U||g
80|Misty's Vitality|U
81|Rust Syndicate Grunt|U
82|Tremendous Bomb|U||g
83|Shadowy Energy|H||g
84|Voltaic Energy|H||g
85|Fomantis|IR
86|Armarouge|IR
87|Goldeen|IR
88|Primarina|IR
89|Manectric|IR
90|Slowbro|IR
91|Dhelmise|IR
92|Thievul|IR
93|Bastiodon|IR
94|Toucannon|IR
95|Silvally|IR
96|Lurantis ex|UR
97|Wailord ex|UR
98|Mega Zeraora ex|UR
99|Mega Chandelure ex|UR
100|Rampardos ex|UR
101|Mega Darkrai ex|UR
102|Morpeko ex|UR
103|Mega Excadrill ex|UR
104|Brave Bangle|UR||g
105|Crushing Hammer|UR||g
106|Dark Bell|UR||g
107|Energy Switch|UR||g
108|Gladion's Final Battle|UR
109|Gwynn|UR
110|Iron Defender|UR||g
111|Misty's Vitality|UR
112|Rust Syndicate Grunt|UR
113|Tremendous Bomb|UR||g
114|Mega Zeraora ex|SIR
115|Mega Chandelure ex|SIR
116|Mega Darkrai ex|SIR
117|Morpeko ex|SIR
118|Gladion's Final Battle|SIR
119|Gwynn|SIR
120|Mega Darkrai ex|MHR
CARDS
		),

		'base1' => array(
			'label'         => 'Base Set (Basis-Set)',
			'title'         => 'Base-Set-Radar',
			'subtitle'      => 'Base Set 1999 · 102 Karten',
			'printed'       => 102,
			'pad'           => false,
			'tcgdex'        => 'base1',
			'context'       => '/base ?set(?! ?2)|basis[\s-]?set(?! ?2)|grundset|basis[\s-]?edition|\/\s*102\b/i',
			'exclude'       => array(
				array( '/base ?set ?2|basis[\s-]?set ?2|legendary collection|celebrations|25th|25 ?jahre|classic collection|\bevolutions\b|\/\s*(108|110|130)\b/i', 'Neuauflage/anderes Set' ),
			),
			'queries'       => array( 'pokemon base set', 'pokemon basis set', 'pokemon grundset' ),
			'targeted'      => array( 'R', 'H' ), // aufsteigend nach Seltenheit
			'targeted_fmt'  => '{name} {num}/{printed}',
			'number_alone'  => false,
			'vintage'       => true,
			'hints'         => pcr_vintage_hints(),
			'rarity_labels' => $vintage_labels,
			'de'            => pcr_de_gen1(),
			'cards'         => <<<'CARDS'
1|Alakazam|H
2|Blastoise|H
3|Chansey|H
4|Charizard|H
5|Clefairy|H
6|Gyarados|H
7|Hitmonchan|H
8|Machamp|H
9|Magneton|H
10|Mewtwo|H
11|Nidoking|H
12|Ninetales|H
13|Poliwrath|H
14|Raichu|H
15|Venusaur|H
16|Zapdos|H
17|Beedrill|R
18|Dragonair|R
19|Dugtrio|R
20|Electabuzz|R
21|Electrode|R
22|Pidgeotto|R
23|Arcanine|U
24|Charmeleon|U
25|Dewgong|U
26|Dratini|U
27|Farfetch'd|U
28|Growlithe|U
29|Haunter|U
30|Ivysaur|U
31|Jynx|U
32|Kadabra|U
33|Kakuna|U
34|Machoke|U
35|Magikarp|U
36|Magmar|U
37|Nidorino|U
38|Poliwhirl|U
39|Porygon|U
40|Raticate|U
41|Seel|U
42|Wartortle|U
43|Abra|C
44|Bulbasaur|C
45|Caterpie|C
46|Charmander|C
47|Diglett|C
48|Doduo|C
49|Drowzee|C
50|Gastly|C
51|Koffing|C
52|Machop|C
53|Magnemite|C
54|Metapod|C
55|Nidoran ♂|C|nidoran
56|Onix|C
57|Pidgey|C
58|Pikachu|C
59|Poliwag|C
60|Ponyta|C
61|Rattata|C
62|Sandshrew|C
63|Squirtle|C
64|Starmie|C
65|Staryu|C
66|Tangela|C
67|Voltorb|C
68|Vulpix|C
69|Weedle|C
70|Clefairy Doll|R|piepi puppe,piepi-puppe|g
71|Computer Search|R|computersuche|g
72|Devolution Spray|R||g
73|Impostor Professor Oak|R|imposter professor oak|g
74|Item Finder|R||g
75|Lass|R||g
76|Pokémon Breeder|R||g
77|Pokémon Trader|R||g
78|Scoop Up|R||g
79|Super Energy Removal|R||g
80|Defender|U||g
81|Energy Retrieval|U||g
82|Full Heal|U||g
83|Maintenance|U||g
84|PlusPower|U||g
85|Pokémon Center|U||g
86|Pokémon Flute|U||g
87|Pokédex|U||g
88|Professor Oak|U|professor eich|g
89|Revive|U||g
90|Super Potion|U||g
91|Bill|C||g
92|Energy Removal|C||g
93|Gust of Wind|C||g
94|Potion|C||g
95|Switch|C||g
96|Double Colorless Energy|U||g
97|Fighting Energy|E||g
98|Fire Energy|E||g
99|Grass Energy|E||g
100|Lightning Energy|E||g
101|Psychic Energy|E||g
102|Water Energy|E||g
CARDS
		),

		'base2' => array(
			'label'         => 'Jungle (Dschungel)',
			'title'         => 'Jungle-Radar',
			'subtitle'      => 'Jungle / Dschungel 1999 · 64 Karten',
			'printed'       => 64,
			'pad'           => false,
			'tcgdex'        => 'base2',
			'context'       => '/\bjungle\b|dschungel|\/\s*64\b/i',
			'exclude'       => array(),
			'queries'       => array( 'pokemon jungle', 'pokemon dschungel' ),
			'targeted'      => array( 'R', 'H' ), // aufsteigend nach Seltenheit
			'targeted_fmt'  => '{name} {num}/{printed}',
			'number_alone'  => false,
			'vintage'       => true,
			'hints'         => pcr_vintage_hints(),
			'rarity_labels' => $vintage_labels,
			'de'            => pcr_de_gen1(),
			'cards'         => <<<'CARDS'
1|Clefable|H
2|Electrode|H
3|Flareon|H
4|Jolteon|H
5|Kangaskhan|H
6|Mr. Mime|H
7|Nidoqueen|H
8|Pidgeot|H
9|Pinsir|H
10|Scyther|H
11|Snorlax|H
12|Vaporeon|H
13|Venomoth|H
14|Victreebel|H
15|Vileplume|H
16|Wigglytuff|H
17|Clefable|R
18|Electrode|R
19|Flareon|R
20|Jolteon|R
21|Kangaskhan|R
22|Mr. Mime|R
23|Nidoqueen|R
24|Pidgeot|R
25|Pinsir|R
26|Scyther|R
27|Snorlax|R
28|Vaporeon|R
29|Venomoth|R
30|Victreebel|R
31|Vileplume|R
32|Wigglytuff|R
33|Butterfree|U
34|Dodrio|U
35|Exeggutor|U
36|Fearow|U
37|Gloom|U
38|Lickitung|U
39|Marowak|U
40|Nidorina|U
41|Parasect|U
42|Persian|U
43|Primeape|U
44|Rapidash|U
45|Rhydon|U
46|Seaking|U
47|Tauros|U
48|Weepinbell|U
49|Bellsprout|C
50|Cubone|C
51|Eevee|C
52|Exeggcute|C
53|Goldeen|C
54|Jigglypuff|C
55|Mankey|C
56|Meowth|C
57|Nidoran ♀|C|nidoran
58|Oddish|C
59|Paras|C
60|Pikachu|C
61|Rhyhorn|C
62|Spearow|C
63|Venonat|C
64|Poké Ball|C|pokeball,poke ball|g
CARDS
		),

		'base3' => array(
			'label'         => 'Fossil',
			'title'         => 'Fossil-Radar',
			'subtitle'      => 'Fossil 1999 · 62 Karten',
			'printed'       => 62,
			'pad'           => false,
			'tcgdex'        => 'base3',
			'context'       => '/fossil[\s-]?(set|serie|edition|erweiterung|holo|1st|1\.)|(set|serie|erweiterung|wotc|pokemon)[\s-]?fossil\b|\/\s*62\b/i',
			'exclude'       => array(),
			'queries'       => array( 'pokemon fossil set', 'pokemon fossil holo' ),
			'targeted'      => array( 'R', 'H' ), // aufsteigend nach Seltenheit
			'targeted_fmt'  => '{name} {num}/{printed}',
			'number_alone'  => false,
			'vintage'       => true,
			'hints'         => pcr_vintage_hints(),
			'rarity_labels' => $vintage_labels,
			'de'            => pcr_de_gen1(),
			'cards'         => <<<'CARDS'
1|Aerodactyl|H
2|Articuno|H
3|Ditto|H
4|Dragonite|H
5|Gengar|H
6|Haunter|H
7|Hitmonlee|H
8|Hypno|H
9|Kabutops|H
10|Lapras|H
11|Magneton|H
12|Moltres|H
13|Muk|H
14|Raichu|H
15|Zapdos|H
16|Aerodactyl|R
17|Articuno|R
18|Ditto|R
19|Dragonite|R
20|Gengar|R
21|Haunter|R
22|Hitmonlee|R
23|Hypno|R
24|Kabutops|R
25|Lapras|R
26|Magneton|R
27|Moltres|R
28|Muk|R
29|Raichu|R
30|Zapdos|R
31|Arbok|U
32|Cloyster|U
33|Gastly|U
34|Golbat|U
35|Golduck|U
36|Golem|U
37|Graveler|U
38|Kingler|U
39|Magmar|U
40|Omastar|U
41|Sandslash|U
42|Seadra|U
43|Slowbro|U
44|Tentacruel|U
45|Weezing|U
46|Ekans|C
47|Geodude|C
48|Grimer|C
49|Horsea|C
50|Kabuto|C
51|Krabby|C
52|Omanyte|C
53|Psyduck|C
54|Shellder|C
55|Slowpoke|C
56|Tentacool|C
57|Zubat|C
58|Mr. Fuji|U||g
59|Energy Search|C||g
60|Gambler|C||g
61|Recall|C||g
62|Mysterious Fossil|C|geheimnisvolles fossil|g
CARDS
		),
	);
}
