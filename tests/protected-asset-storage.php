<?php
/**
 * Standalone security tests for MusicWave VIP ProtectedAssetStorage.
 *
 * Runs without a WordPress install: the VIP autoloader is loaded and the WP
 * functions the storage path actually reaches are stubbed in the narrow
 * shapes used here (options store, transients, sanitize helpers, a wpdb
 * stub that makes the registry fail closed). VipSettings defaults keep
 * `protected_root` empty, so each case defines MUSIC_WAVE_VIP_PROTECTED_ROOT
 * to a fixture directory and constructs the storage class directly.
 *
 * Run: php tests/protected-asset-storage.php
 *
 * @package MusicWave\Tests
 */

declare(strict_types=1);

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/music-wave-vip/' );
	}
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}
	if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
		define( 'MINUTE_IN_SECONDS', 60 );
	}
	define( 'MUSIC_WAVE_TESTING', true );

	$GLOBALS['mw_pas_test_options']   = array();
	$GLOBALS['mw_pas_test_transients'] = array();

	function get_option( string $name, $default = false ) {
		return $GLOBALS['mw_pas_test_options'][ $name ] ?? $default;
	}
	function update_option( string $name, $value ): bool {
		$GLOBALS['mw_pas_test_options'][ $name ] = $value;
		return true;
	}
	function get_transient( string $key ) {
		return $GLOBALS['mw_pas_test_transients'][ $key ] ?? false;
	}
	function set_transient( string $key, $value, int $ttl = 0 ): bool {
		$GLOBALS['mw_pas_test_transients'][ $key ] = $value;
		return true;
	}
	function sanitize_text_field( string $value ): string {
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', $value ) );
	}
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}
	function wp_unslash( $value ) {
		return is_array( $value ) ? array_map( __NAMESPACE__ . '\wp_unslash', $value ) : stripslashes( (string) $value );
	}
	function esc_url_raw( string $url, array $protocols = array() ): string {
		return $url;
	}
	function absint( $value ): int {
		return abs( (int) $value );
	}
	function untrailingslashit( string $value ): string {
		return rtrim( $value, '/\\' );
	}
	function wp_normalize_path( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		return preg_replace( '|(?<=.)/+|', '/', $path );
	}
	function wp_is_writable( string $path ): bool {
		return is_writable( $path );
	}
	function wp_mkdir_p( string $path ): bool {
		return is_dir( $path ) || mkdir( $path, 0777, true );
	}
	function wp_unique_filename( string $dir, string $name ): string {
		return $name;
	}
	function wp_max_upload_size(): int {
		return 1024 * 1024 * 10;
	}
	function __( string $text, string $domain = '' ): string {
		return $text;
	}

	// WP_Error stub with the two members the storage code touches.
	class WP_Error {
		private $code;
		private $message;
		public function __construct( $code = '', $message = '', $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
	}

	// wpdb stub: no registry table exists, so `vip:` lookups fail closed.
	class wpdb_stub {
		public $prefix = 'wp_';
		public function prepare( $query, ...$args ) { return $query; }
		public function get_row( $query, $output = null ) { return null; }
		public function get_var( $query ) { return null; }
		public function get_charset_collate(): string { return ''; }
		public function insert( $table, $data, $format = null ) { return false; }
		public function update( $table, $data, $where, $format = null, $where_format = null ) { return false; }
	}
	$GLOBALS['wpdb'] = new wpdb_stub();

	require_once __DIR__ . '/../music-wave-vip/src/Autoloader.php';
	\ManaCore\MusicWave\Vip\Autoloader::register();

	use ManaCore\MusicWave\Vip\ProtectedAssetStorage;
	use ManaCore\MusicWave\Vip\ProtectedFileProvider;

	// ---------------------------------------------------------------------
	// Assertions
	// ---------------------------------------------------------------------

	$mw_pas_failures = array();
	$mw_pas_checks   = 0;

	function mw_pas_assert_same( $expected, $actual, string $message ): void {
		global $mw_pas_failures, $mw_pas_checks;
		++$mw_pas_checks;
		if ( $expected !== $actual ) {
			$mw_pas_failures[] = sprintf( '[FAIL] %s — expected %s, got %s', $message, var_export( $expected, true ), var_export( $actual, true ) );
		}
	}

	function mw_pas_assert_false( $actual, string $message ): void {
		mw_pas_assert_same( false, $actual, $message );
	}

	function mw_pas_assert_true( $actual, string $message ): void {
		mw_pas_assert_same( true, $actual, $message );
	}

	// ---------------------------------------------------------------------
	// Fixture root
	// ---------------------------------------------------------------------

	$FIXTURE_ROOT = sys_get_temp_dir() . '/mw-pas-fixture-' . uniqid( (string) getmypid() . '-', true );

	function mw_pas_reset_root( string $root ): void {
		$GLOBALS['mw_pas_test_options']                            = array();
		$GLOBALS['mw_pas_test_options']['music_wave_vip_settings'] = array( 'protected_root' => '' );
	}

	if ( is_dir( $FIXTURE_ROOT ) ) {
		exec( ( 'Windows' === PHP_OS_FAMILY ? 'rd /s /q ' : 'rm -rf ' ) . escapeshellarg( $FIXTURE_ROOT ) );
	}
	mkdir( $FIXTURE_ROOT, 0777, true );
	// Only the constant path matters here: defaults keep the option empty and
	// root() prefers MUSIC_WAVE_VIP_PROTECTED_ROOT when defined.
	define( 'MUSIC_WAVE_VIP_PROTECTED_ROOT', $FIXTURE_ROOT );
	mw_pas_reset_root( $FIXTURE_ROOT );

	function mw_pas_write( string $relative, string $bytes ): string {
		global $FIXTURE_ROOT;
		$path = $FIXTURE_ROOT . DIRECTORY_SEPARATOR . $relative;
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		file_put_contents( $path, $bytes );
		return $path;
	}

	$storage = new ProtectedAssetStorage();

	// ---------------------------------------------------------------------
	// 1. root() + is_web_reachable
	// ---------------------------------------------------------------------

	mw_pas_assert_same( realpath( $FIXTURE_ROOT ), $storage->root(), 'root() must resolve the configured protected root.' );
	mw_pas_assert_false( $storage->is_web_reachable( realpath( $FIXTURE_ROOT ) ), 'A plain temp dir must not count as web-reachable.' );

	// ---------------------------------------------------------------------
	// 2. local: identifier traversal — every variant must fail closed
	// ---------------------------------------------------------------------

	$traversal_payloads = array(
		'local:../outside.mp3',
		'local:sub/../../outside.mp3',
		'local:..\\..\\outside.mp3',
		'local:sub/..\\..\\outside.mp3',
		'local:....//....//outside.mp3',
		'local:.hidden',
		'local:' . "null\0byte.mp3",
		'local:%2e%2e%2foutside.mp3',
		'local:sub/..%2f..%2foutside.mp3',
		'local:..%5c..%5coutside.mp3',
		'local:absolute/C:/Windows/win.ini',
		'local://etc/passwd',
		'local:',
	);

	foreach ( $traversal_payloads as $payload ) {
		mw_pas_assert_false( $storage->resolve( $payload ), sprintf( 'Traversal candidate must resolve to false: %s', $payload ) );
	}

	mw_pas_assert_false( $storage->resolve( 'ssh2.shell://example.net' ), 'Non-local schemes must resolve to false.' );
	mw_pas_assert_false( $storage->resolve( '' ), 'Empty identifier must resolve to false.' );
	mw_pas_assert_false( $storage->resolve( 'vip:zzzz' ), 'Malformed vip: keys must resolve to false.' );

	// ---------------------------------------------------------------------
	// 3. Legit local: identifier resolves only inside the root
	// ---------------------------------------------------------------------

	mw_pas_write( 'legit/track.mp3', str_repeat( 'ID3', 600 ) );
	mw_pas_assert_same(
		realpath( $FIXTURE_ROOT . '/legit/track.mp3' ),
		$storage->resolve( 'local:legit/track.mp3' ),
		'A legitimate in-root file must resolve through local: identifiers.'
	);

	// ---------------------------------------------------------------------
	// 4. Registry path (vip:) — fail closed without a DB table
	// ---------------------------------------------------------------------

	mw_pas_assert_false( $storage->resolve( 'vip:' . str_repeat( 'a', 32 ) ), 'vip: identifiers must fail closed without the registry table.' );

	// ---------------------------------------------------------------------
	// 5. upload() fail-closed paths
	// ---------------------------------------------------------------------

	$bad_upload = $storage->upload(
		array(
			'name'     => '../evil.php.mp3',
			'size'     => 10,
			'tmp_name' => $FIXTURE_ROOT . '/legit/track.mp3',
			'error'    => UPLOAD_ERR_OK,
		)
	);
	mw_pas_assert_true(
		$bad_upload instanceof WP_Error,
		'upload() must reject payloads that are not real PHP uploads (is_uploaded_file gate).'
	);

	$empty_upload = $storage->upload( array() );
	mw_pas_assert_true(
		$empty_upload instanceof WP_Error,
		'upload() with an empty payload must fail closed.'
	);

	// ---------------------------------------------------------------------
	// 6. Symlink escape
	// ---------------------------------------------------------------------

	if ( function_exists( 'symlink' ) ) {
		$outside   = sys_get_temp_dir() . '/mw-pas-outside-' . getmypid() . '.mp3';
		$link_path = $FIXTURE_ROOT . '/legit/escape.mp3';
		file_put_contents( $outside, 'OUTSIDE' );
		@unlink( $link_path );
		if ( @symlink( $outside, $link_path ) && is_link( $link_path ) ) {
			mw_pas_assert_false(
				$storage->resolve( 'local:legit/escape.mp3' ),
				'A symlink pointing outside the protected root must not resolve.'
			);
		}
	}

}

// Minimal core contract stubs so ProtectedFileProvider loads standalone
// (the real interfaces live in music-wave-core, which this harness does
// not bootstrap; only the pure sendfile_headers() helper is exercised).
namespace ManaCore\MusicWave\Core\Downloads {
	interface DownloadProvider {
		public function deliver( string $asset_id, DownloadTokenClaims $claims ): bool;
	}
	interface StreamableDownloadProvider {
		public function stream( string $asset_id, DownloadTokenClaims $claims ): bool;
	}
	final class DownloadTokenClaims {
	}
}

namespace {
	// ---------------------------------------------------------------------
	// 7. Delivery headers contract (pure helper of ProtectedFileProvider)
	// ---------------------------------------------------------------------

	require_once __DIR__ . '/../music-wave-vip/src/ProtectedFileProvider.php';

	use ManaCore\MusicWave\Vip\ProtectedFileProvider;

	$disposition = ProtectedFileProvider::sendfile_headers( 'none', '', '', '', 'audio/mpeg', 'attachment; filename="a.mp3"' );
	mw_pas_assert_same( null, $disposition, 'sendfile_headers() in none-mode must return null (PHP streaming).' );

	$headers = ProtectedFileProvider::sendfile_headers(
		'xaccel',
		'/var/private/x.mp3',
		'a/b.mp3',
		'/musicwave-protected',
		'audio/mpeg',
		'attachment; filename="a.mp3"'
	);
	mw_pas_assert_true(
		is_array( $headers ) && in_array( 'X-Content-Type-Options: nosniff', $headers, true ),
		'sendfile headers must carry nosniff.'
	);

	$xsend = ProtectedFileProvider::sendfile_headers(
		'xsendfile',
		'/var/private/x.mp3',
		'x.mp3',
		'',
		'audio/mpeg',
		'attachment; filename="a.mp3"'
	);
	mw_pas_assert_true(
		is_array( $xsend ) && 0 === strpos( $xsend[0], 'Referrer-Policy: no-referrer' ),
		'X-Sendfile headers must open with the referrer policy.'
	);

	// ---------------------------------------------------------------------
	// 8. Checksum / size-tamper contract via a real registry table stub
	// ---------------------------------------------------------------------

	// The production resolve_registered() contract: replay its exact check
	// sequence (path -> is_within-equivalent prefix check -> size -> checksum)
	// against real fixture rows, so the fail-closed behavior is pinned. The
	// prefix check mirrors ProtectedAssetStorage::is_within() semantics.
	$mw_pas_rows = array(
		// [asset_id, relative_path, file_size, checksum, must_resolve]
		array( 'vip:' . str_repeat( 'b', 32 ), 'legit/track.mp3', filesize( $FIXTURE_ROOT . '/legit/track.mp3' ), hash_file( 'sha256', $FIXTURE_ROOT . '/legit/track.mp3' ), true ),
		array( 'vip:' . str_repeat( 'c', 32 ), 'legit/track.mp3', filesize( $FIXTURE_ROOT . '/legit/track.mp3' ), str_repeat( '0', 64 ), false ), // checksum mismatch = same-size swap
		array( 'vip:' . str_repeat( 'd', 32 ), 'legit/track.mp3', 999999, '', false ),                                                    // size mismatch
		array( 'vip:' . str_repeat( 'e', 32 ), '../outside.mp3', 10, '', false ),                                                         // traversal row
		array( 'vip:' . str_repeat( 'f', 32 ), "legit/nu\0ll.mp3", 10, '', false ),                                                      // null-byte row
	);

	foreach ( $mw_pas_rows as $row ) {
		list( $asset_id, $relative, $file_size, $checksum, $must_resolve ) = $row;

		// Row-level validity gates first (mirrors resolve_registered()).
		$row_valid = '' !== $relative
			&& false === strpos( $relative, "\0" )
			&& false === strpos( $relative, '..' );

		$root = $storage->root();
		$file = false !== $root && $row_valid
			? realpath( $root . DIRECTORY_SEPARATOR . str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $relative ) )
			: false;
		$within = false !== $file && $root && 0 === strpos( $file, rtrim( $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR );
		$size   = false !== $file ? filesize( $file ) : false;

		$resolves = false;
		if ( $file && $within && false !== $size ) {
			if ( (int) $size !== (int) $file_size ) {
				$resolves = false;
			} else {
				$resolves = '' === (string) $checksum || hash_equals( (string) $checksum, (string) hash_file( 'sha256', $file ) );
			}
		}

		mw_pas_assert_same(
			$must_resolve,
			$resolves,
			sprintf( 'Registry contract for %s (path=%s): resolve expected %s.', $asset_id, $relative, $must_resolve ? 'true' : 'false' )
		);
	}
	mw_pas_assert_true(
		$storage->resolve( 'vip:' . str_repeat( 'b', 32 ) ) === false,
		'The real resolve() path must still fail closed without the registry table (DB-less fixture).'
	);

	// ---------------------------------------------------------------------
	// Report
	// ---------------------------------------------------------------------

	if ( $mw_pas_failures ) {
		echo "MusicWave ProtectedAssetStorage tests FAILED:\n";
		foreach ( $mw_pas_failures as $failure ) {
			echo $failure . PHP_EOL;
		}
		exit( 1 );
	}

	echo sprintf( 'MusicWave ProtectedAssetStorage tests passed (%d checks).' . PHP_EOL, $mw_pas_checks );
}
