<?php
/**
 * PHPStan bootstrap: define runtime constants that WordPress and the plugin
 * normally provide, and load sharded WordPress/WooCommerce stubs so full-repo
 * Level 5 analysis completes rapidly without PHP-Parser memory exhaustion.
 *
 * @package ManaCore\MusicWave\Core
 */

$mw_root = dirname( __DIR__ );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/stubs/' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'EP_ROOT' ) ) {
	define( 'EP_ROOT', 1 );
}
if ( ! defined( 'EP_PAGES' ) ) {
	define( 'EP_PAGES', 1 );
}
if ( ! defined( 'MUSIC_WAVE_CORE_FILE' ) ) {
	define( 'MUSIC_WAVE_CORE_FILE', $mw_root . '/music-wave-core/music-wave-core.php' );
}
if ( ! defined( 'MUSIC_WAVE_CORE_PATH' ) ) {
	define( 'MUSIC_WAVE_CORE_PATH', $mw_root . '/music-wave-core/' );
}
if ( ! defined( 'MUSIC_WAVE_CORE_URL' ) ) {
	define( 'MUSIC_WAVE_CORE_URL', 'http://example.com/wp-content/plugins/music-wave-core/' );
}
if ( ! defined( 'MUSIC_WAVE_CORE_VERSION' ) ) {
	define( 'MUSIC_WAVE_CORE_VERSION', '1.0.0' );
}
if ( ! defined( 'MUSIC_WAVE_VIP_FILE' ) ) {
	define( 'MUSIC_WAVE_VIP_FILE', $mw_root . '/music-wave-vip/music-wave-vip.php' );
}
if ( ! defined( 'MUSIC_WAVE_VIP_PATH' ) ) {
	define( 'MUSIC_WAVE_VIP_PATH', $mw_root . '/music-wave-vip/' );
}
if ( ! defined( 'MUSIC_WAVE_VIP_URL' ) ) {
	define( 'MUSIC_WAVE_VIP_URL', 'http://example.com/wp-content/plugins/music-wave-vip/' );
}
if ( ! defined( 'MUSIC_WAVE_VIP_VERSION' ) ) {
	define( 'MUSIC_WAVE_VIP_VERSION', '0.5.1' );
}
if ( ! defined( 'MUSICWAVE_VERSION' ) ) {
	define( 'MUSICWAVE_VERSION', '0.9.0' );
}
if ( ! defined( 'MUSICWAVE_DIR' ) ) {
	define( 'MUSICWAVE_DIR', $mw_root . '/musicwave' );
}
if ( ! defined( 'MUSICWAVE_URI' ) ) {
	define( 'MUSICWAVE_URI', 'http://example.com/wp-content/themes/musicwave' );
}

/**
 * Split a monolithic PHP stub file at token depth 0/1 into ~120 KB chunks
 * so PHPStan lazy-parses only referenced symbol files on demand.
 */
$mw_shard_stub = static function ( string $source_file, string $target_dir, string $prefix ): void {
	if ( ! is_file( $source_file ) ) {
		return;
	}
	$sentinel = $target_dir . '/' . $prefix . '.ready';
	$sig      = sha1_file( $source_file );
	if ( is_file( $sentinel ) && trim( (string) file_get_contents( $sentinel ) ) === $sig ) {
		return;
	}
	if ( ! is_dir( $target_dir ) ) {
		mkdir( $target_dir, 0777, true );
	}
	foreach ( glob( $target_dir . '/' . $prefix . '-*.php' ) ?: array() as $old ) {
		unlink( $old );
	}

	$tokens    = token_get_all( (string) file_get_contents( $source_file ) );
	$depth     = 0;
	$in_ns_blk = false;
	$ns_header = '';
	$capturing = false;
	$chunk     = "<?php\n";
	$idx       = 0;
	$flush     = static function () use ( &$chunk, &$idx, $target_dir, $prefix, &$in_ns_blk, &$ns_header ): void {
		if ( "<?php\n" === $chunk || ( $in_ns_blk && "<?php\n" . $ns_header === $chunk ) ) {
			return;
		}
		$out = $in_ns_blk ? ( $chunk . "\n}\n" ) : $chunk;
		file_put_contents( sprintf( '%s/%s-%04d.php', $target_dir, $prefix, $idx++ ), $out );
		$chunk = $in_ns_blk ? ( "<?php\n" . $ns_header ) : "<?php\n";
	};

	foreach ( $tokens as $tok ) {
		$id   = is_array( $tok ) ? $tok[0] : 0;
		$text = is_array( $tok ) ? $tok[1] : $tok;
		if ( T_OPEN_TAG === $id ) {
			continue;
		}
		if ( T_NAMESPACE === $id && 0 === $depth ) {
			$flush();
			$ns_header = 'namespace';
			$capturing = true;
			continue;
		}
		if ( $capturing ) {
			$ns_header .= $text;
			if ( '{' === $text ) {
				$in_ns_blk = true;
				$capturing = false;
				$depth     = 1;
				$chunk     = "<?php\n" . $ns_header;
			} elseif ( ';' === $text ) {
				$in_ns_blk = false;
				$capturing = false;
				$chunk     = "<?php\n" . $ns_header;
			}
			continue;
		}
		$chunk .= $text;
		if ( '{' === $text || T_CURLY_OPEN === $id || T_DOLLAR_OPEN_CURLY_BRACES === $id ) {
			++$depth;
		} elseif ( '}' === $text ) {
			--$depth;
			if ( $in_ns_blk && 0 === $depth ) {
				$in_ns_blk = false;
				$ns_header = '';
				file_put_contents( sprintf( '%s/%s-%04d.php', $target_dir, $prefix, $idx++ ), $chunk . "\n" );
				$chunk = "<?php\n";
				continue;
			}
		}
		$split_depth = $in_ns_blk ? 1 : 0;
		if ( $depth === $split_depth && ( '}' === $text || ';' === $text ) && strlen( $chunk ) >= 120000 ) {
			$flush();
		}
	}
	$flush();
	unset( $tokens, $chunk );
	gc_collect_cycles();
	file_put_contents( $sentinel, (string) $sig );
};

$mw_stubs_dir = sys_get_temp_dir() . '/musicwave-phpstan-stubs-' . substr( md5( $mw_root ), 0, 8 );
$mw_shard_stub( $mw_root . '/vendor/php-stubs/wordpress-stubs/wordpress-stubs.php', $mw_stubs_dir, 'wp' );
$mw_shard_stub( $mw_root . '/vendor/php-stubs/woocommerce-stubs/woocommerce-stubs.php', $mw_stubs_dir, 'wc' );

foreach ( glob( $mw_stubs_dir . '/wp-*.php' ) ?: array() as $mw_stub_file ) {
	require_once $mw_stub_file;
}
foreach ( glob( $mw_stubs_dir . '/wc-*.php' ) ?: array() as $mw_stub_file ) {
	require_once $mw_stub_file;
}

require_once $mw_root . '/music-wave-core/src/Support/Autoloader.php';
\ManaCore\MusicWave\Core\Support\Autoloader::register();

require_once $mw_root . '/music-wave-vip/src/Autoloader.php';
\ManaCore\MusicWave\Vip\Autoloader::register();

require_once $mw_root . '/musicwave/src/Autoloader.php';
\ManaCore\MusicWave\Theme\Autoloader::register( $mw_root . '/musicwave/src' );

require_once $mw_root . '/tools/stubs/wp-cli.php';
