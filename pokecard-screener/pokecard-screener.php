<?php
/**
 * Plugin Name:       PokéCard Screener
 * Description:       Pokémon-Karten-Screener für eingeloggte Nutzer: Sets durchsuchen und filtern, eigene Sammlung pflegen, Cardmarket-Preise sehen und Anzeigen für Kleinanzeigen/tutti erstellen.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Pokecardcollect
 * License:           GPL-2.0-or-later
 * Text Domain:       pokecard-screener
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PCS_VERSION', '0.1.0' );
define( 'PCS_FILE', __FILE__ );
define( 'PCS_DIR', plugin_dir_path( __FILE__ ) );
define( 'PCS_URL', plugin_dir_url( __FILE__ ) );

require_once PCS_DIR . 'includes/class-pcs-settings.php';
require_once PCS_DIR . 'includes/class-pcs-api.php';
require_once PCS_DIR . 'includes/class-pcs-collection.php';
require_once PCS_DIR . 'includes/class-pcs-rest.php';
require_once PCS_DIR . 'includes/class-pcs-shortcode.php';

PCS_Settings::init();
PCS_Rest::init();
PCS_Shortcode::init();

register_activation_hook(
	__FILE__,
	static function () {
		if ( false === get_option( PCS_Settings::OPTION ) ) {
			add_option( PCS_Settings::OPTION, PCS_Settings::defaults() );
		}
	}
);
