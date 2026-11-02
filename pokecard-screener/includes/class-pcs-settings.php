<?php
/**
 * Admin-Einstellungen: API-Key, verfügbare Sets, Preis-Optionen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCS_Settings {

	const OPTION = 'pcs_settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Standardwerte. Sets: eine Zeile pro Set im Format  schluessel|Anzeigename|Pokémon-TCG-API-Suchanfrage
	 */
	public static function defaults() {
		return array(
			'api_key'       => '',
			'sets'          => implode(
				"\n",
				array(
					'pitch-black|Pitch Black (Mega Evolution)|set.name:"Pitch Black"',
					'base1|Base Set|set.id:base1',
					'base2|Jungle|set.id:base2',
					'base3|Fossil|set.id:base3',
				)
			),
			'eur_to_chf'    => '0.94',
			'cache_hours'   => '12',
			'seller_note'   => 'Versand möglich, Abholung nach Absprache. Privatverkauf, keine Garantie/Rücknahme.',
		);
	}

	public static function get( $key = null ) {
		$opts = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		return null === $key ? $opts : ( $opts[ $key ] ?? null );
	}

	/**
	 * @return array<string, array{key:string,label:string,query:string}>
	 */
	public static function sets() {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) self::get( 'sets' ) ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line, 3 ) );
			if ( 3 !== count( $parts ) || '' === $parts[0] || '' === $parts[2] ) {
				continue;
			}
			$key         = sanitize_key( $parts[0] );
			$out[ $key ] = array(
				'key'   => $key,
				'label' => $parts[1],
				'query' => $parts[2],
			);
		}
		return $out;
	}

	public static function menu() {
		add_options_page( 'PokéCard Screener', 'PokéCard Screener', 'manage_options', 'pokecard-screener', array( __CLASS__, 'render' ) );
	}

	public static function register() {
		register_setting(
			'pcs',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function sanitize( $input ) {
		$input = (array) $input;
		$d     = self::defaults();
		$out   = array(
			'api_key'     => sanitize_text_field( $input['api_key'] ?? '' ),
			'sets'        => sanitize_textarea_field( $input['sets'] ?? $d['sets'] ),
			'eur_to_chf'  => (string) max( 0.01, (float) str_replace( ',', '.', $input['eur_to_chf'] ?? $d['eur_to_chf'] ) ),
			'cache_hours' => (string) max( 1, (int) ( $input['cache_hours'] ?? $d['cache_hours'] ) ),
			'seller_note' => sanitize_textarea_field( $input['seller_note'] ?? $d['seller_note'] ),
		);
		PCS_Api::flush_cache();
		return $out;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o = self::get();
		$n = self::OPTION;
		?>
		<div class="wrap">
			<h1>PokéCard Screener</h1>
			<p>Shortcode für die Seite: <code>[pokecard_screener]</code>. Kartendaten und Cardmarket-Preise kommen von der <a href="https://pokemontcg.io" target="_blank" rel="noopener">Pokémon TCG API</a>.</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'pcs' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pcs_api_key">Pokémon TCG API-Key</label></th>
						<td><input type="text" class="regular-text" id="pcs_api_key" name="<?php echo esc_attr( $n ); ?>[api_key]" value="<?php echo esc_attr( $o['api_key'] ); ?>">
						<p class="description">Optional, aber empfohlen (höheres Limit). Kostenlos auf dev.pokemontcg.io.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="pcs_sets">Sets</label></th>
						<td><textarea id="pcs_sets" rows="8" class="large-text code" name="<?php echo esc_attr( $n ); ?>[sets]"><?php echo esc_textarea( $o['sets'] ); ?></textarea>
						<p class="description">Eine Zeile pro Set: <code>schluessel|Anzeigename|Suchanfrage</code>. Beispiel: <code>base1|Base Set|set.id:base1</code> oder <code>xy|Mein Set|set.name:"Name"</code>.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="pcs_chf">Kurs EUR → CHF</label></th>
						<td><input type="text" id="pcs_chf" name="<?php echo esc_attr( $n ); ?>[eur_to_chf]" value="<?php echo esc_attr( $o['eur_to_chf'] ); ?>">
						<p class="description">Für Preisvorschläge auf tutti.ch (Cardmarket-Preise sind in EUR).</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="pcs_cache">Cache (Stunden)</label></th>
						<td><input type="number" min="1" id="pcs_cache" name="<?php echo esc_attr( $n ); ?>[cache_hours]" value="<?php echo esc_attr( $o['cache_hours'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="pcs_note">Standard-Hinweis in Anzeigen</label></th>
						<td><textarea id="pcs_note" rows="3" class="large-text" name="<?php echo esc_attr( $n ); ?>[seller_note]"><?php echo esc_textarea( $o['seller_note'] ); ?></textarea></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
