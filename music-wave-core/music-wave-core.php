<?php
/**
 * Plugin Name:       MusicWave Core
 * Plugin URI:        https://manacore.dev/musicwave
 * Description:       Content, catalog, player, commerce, and integration foundations for MusicWave.
 * Version:           0.15.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            ManaCore
 * License:            GPL-2.0-or-later
 * License URI:        https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       music-wave-core
 * Domain Path:       /languages
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MUSIC_WAVE_CORE_VERSION', '0.15.0' );
define( 'MUSIC_WAVE_CORE_FILE', __FILE__ );
define( 'MUSIC_WAVE_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'MUSIC_WAVE_CORE_URL', plugin_dir_url( __FILE__ ) );

require_once MUSIC_WAVE_CORE_PATH . 'src/Support/Autoloader.php';

ManaCore\MusicWave\Core\Support\Autoloader::register();

register_activation_hook(
	MUSIC_WAVE_CORE_FILE,
	array( ManaCore\MusicWave\Core\Lifecycle\Activator::class, 'activate' )
);

register_deactivation_hook(
	MUSIC_WAVE_CORE_FILE,
	array( ManaCore\MusicWave\Core\Lifecycle\Deactivator::class, 'deactivate' )
);

$music_wave_core = ManaCore\MusicWave\Core\Plugin::instance();
if ( function_exists( 'did_action' ) && ! did_action( 'plugins_loaded' ) ) {
	add_action( 'plugins_loaded', array( $music_wave_core, 'boot' ), 20 );
} else {
	$music_wave_core->boot();
}
