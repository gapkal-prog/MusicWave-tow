<?php
/**
 * Smart, self-healing local delivery diagnostics.
 *
 * - Auto-detects the best `sendfile_mode` (nginx → xaccel, Apache + mod_xsendfile → xsendfile, else none) as a *suggestion only*.
 * - Daily self-heal of the protected root (`.htaccess`, `web.config`, `index.html`).
 * - Auto-refresh of checksum when a file is replaced with the same size.
 *
 * @package ManaCore\MusicWave\Vip\Delivery
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Delivery;

use ManaCore\MusicWave\Vip\ProtectedAssetStorage;
use ManaCore\MusicWave\Vip\VipAuditLog;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @immutable
 */
final class LocalDeliveryDiagnostics {
	/** @var ProtectedAssetStorage */
	private $storage;
	/** @var array<string, mixed> */
	private $config;

	public function __construct( ?ProtectedAssetStorage $storage = null, array $config = array() ) {
		$this->storage = null !== $storage ? $storage : new ProtectedAssetStorage();
		$this->config  = $config;
	}

	/**
	 * Detect the recommended sendfile mode based on server software and
	 * loaded modules. Returns a suggestion — never auto-applies.
	 *
	 * @return array{mode: string, reason: string, confident: bool}
	 */
	public function detect_sendfile(): array {
		$server = $this->server_software();

		// nginx (or proxied behind nginx).
		if ( false !== stripos( $server, 'nginx' ) ) {
			return array(
				'mode'      => 'xaccel',
				'reason'    => 'وبسرور nginx شناسایی شد؛ X-Accel-Redirect پیشنهادی است.',
				'confident' => true,
			);
		}

		// Apache with mod_xsendfile hint (module name or env marker).
		if ( false !== stripos( $server, 'Apache' ) ) {
			if ( $this->has_xsendfile() ) {
				return array(
					'mode'      => 'xsendfile',
					'reason'    => 'آپاچی با mod_xsendfile شناسایی شد؛ X-Sendfile پیشنهادی است.',
					'confident' => true,
				);
			}
			return array(
				'mode'      => 'xsendfile',
				'reason'    => 'آپاچی شناسایی شد؛ X-Sendfile یک گزینهٔ مناسب است (تنظیم ماژول را بررسی کنید).',
				'confident' => false,
			);
		}

		return array(
			'mode'      => 'none',
			'reason'    => 'وبسرور شناسایی نشد؛ استریم درون PHP (حالت اضطراری).',
			'confident' => true,
		);
	}

	/**
	 * Daily self-heal: recreate missing protective files in the protected root.
	 *
	 * @return array{healed: int, errors: list<string>}
	 */
	public function selfheal_root(): array {
		$root = $this->storage->root();
		if ( false === $root || ! is_dir( $root ) || ! is_writable( $root ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- writability is only probed, never changed.
			return array(
				'healed' => 0,
				'errors' => array( 'پوشهٔ محافظت‌شده در دسترس یا قابل نوشتن نیست.' ),
			);
		}

		$healed = 0;
		$errors = array();

		// .htaccess — deny direct web access (Apache 2.4 and 2.2 compatible).
		$htaccess = $root . DIRECTORY_SEPARATOR . '.htaccess';
		if ( ! is_file( $htaccess ) ) {
			$content = "# MusicWave VIP protected root\n"
				. "Options -Indexes\n"
				. "<IfModule mod_authz_core.c>\n"
				. "\tRequire all denied\n"
				. "</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n"
				. "\tOrder allow,deny\n"
				. "\tDeny from all\n"
				. "</IfModule>\n";
			if ( @file_put_contents( $htaccess, $content ) !== false ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- protective file inside the plugin's own protected root.
				@chmod( $htaccess, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- lock down the file we just created.
				++$healed;
				VipAuditLog::record( 'local_root_selfheal_htaccess', 0, array( 'level' => 'info' ) );
			} else {
				$errors[] = 'نوشتن .htaccess ناموفق بود.';
			}
		}

		// web.config — IIS equivalent.
		$webconfig = $root . DIRECTORY_SEPARATOR . 'web.config';
		if ( ! is_file( $webconfig ) && $this->is_iis() ) {
			$content = '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><security><authorization><add accessType="Deny" users="?" /></authorization></security></system.webServer></configuration>';
			if ( @file_put_contents( $webconfig, $content ) !== false ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- protective file inside the plugin's own protected root.
				@chmod( $webconfig, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- lock down the file we just created.
				++$healed;
				VipAuditLog::record( 'local_root_selfheal_webconfig', 0, array( 'level' => 'info' ) );
			} else {
				$errors[] = 'نوشتن web.config ناموفق بود.';
			}
		}

		// index.html — prevent directory listing fallback.
		$index = $root . DIRECTORY_SEPARATOR . 'index.html';
		if ( ! is_file( $index ) ) {
			$content = '<!DOCTYPE html><html><body></body></html>';
			if ( @file_put_contents( $index, $content ) !== false ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- protective file inside the plugin's own protected root.
				@chmod( $index, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- lock down the file we just created.
				++$healed;
			} else {
				$errors[] = 'نوشتن index.html ناموفق بود.';
			}
		}

		return array(
			'healed' => $healed,
			'errors' => $errors,
		);
	}

	/**
	 * Whether the current server is IIS.
	 */
	private function is_iis(): bool {
		return false !== stripos( $this->server_software(), 'IIS' );
	}

	/**
	 * Sanitized `SERVER_SOFTWARE` banner (empty when the server does not expose one).
	 */
	private function server_software(): string {
		return isset( $_SERVER['SERVER_SOFTWARE'] ) && is_scalar( $_SERVER['SERVER_SOFTWARE'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_SOFTWARE'] ) )
			: '';
	}

	/**
	 * Heuristic for mod_xsendfile availability (env/header or module probe).
	 */
	private function has_xsendfile(): bool {
		if ( isset( $_SERVER['HTTP_X_SENDFILE'] ) || isset( $_SERVER['REDIRECT_X_SENDFILE'] ) ) {
			return true;
		}
		if ( function_exists( 'apache_get_modules' ) ) {
			return in_array( 'mod_xsendfile.c', apache_get_modules(), true );
		}

		return false;
	}

	/**
	 * Whether checksum auto-refresh is enabled.
	 */
	public function checksum_autorefresh_enabled(): bool {
		return 'enabled' === (string) ( $this->config['local_checksum_autorefresh'] ?? 'enabled' );
	}

	/**
	 * Whether self-heal is enabled.
	 */
	public function selfheal_enabled(): bool {
		return 'enabled' === (string) ( $this->config['local_selfheal_enabled'] ?? 'enabled' );
	}

	/**
	 * Configured stream chunk size (KB).
	 */
	public function stream_chunk_kb(): int {
		return max( 64, absint( $this->config['local_stream_chunk_kb'] ?? 1024 ) );
	}

	private function __clone() {}
}
