<?php
/**
 * Plugin Name:       PokéCard Radar
 * Description:       Sucht auf Kleinanzeigen.de und tutti.ch nach Pokémon-Karten, die deinen Nutzern fehlen (Pitch Black, Base Set, Jungle, Fossil). Mit Login, Sammlungs-Raster, Cardmarket-Richtpreisen und Telegram-Push.
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Pokecardcollect
 * License:           GPL-2.0-or-later
 * Text Domain:       pokecard-radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PCR_VERSION', '0.2.0' );
define( 'PCR_DIR', plugin_dir_path( __FILE__ ) );
define( 'PCR_URL', plugin_dir_url( __FILE__ ) );

require_once PCR_DIR . 'includes/class-pcr-set.php';
require_once PCR_DIR . 'includes/class-pcr-matcher.php';
require_once PCR_DIR . 'includes/class-pcr-sources.php';
require_once PCR_DIR . 'includes/class-pcr-settings.php';
require_once PCR_DIR . 'includes/class-pcr-engine.php';
require_once PCR_DIR . 'includes/class-pcr-rest.php';
require_once PCR_DIR . 'includes/class-pcr-shortcode.php';

PCR_Settings::init();
PCR_Engine::init();
PCR_Rest::init();
PCR_Shortcode::init();

register_activation_hook( __FILE__, array( 'PCR_Engine', 'install' ) );
register_deactivation_hook( __FILE__, array( 'PCR_Engine', 'deactivate' ) );
