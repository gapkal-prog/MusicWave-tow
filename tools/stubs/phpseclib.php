<?php
/**
 * PHPStan stub for the optional phpseclib SFTP client.
 *
 * `phpseclib` is not a bundled dependency: the SFTP adapter only uses it when
 * the host already provides the library (otherwise the `ssh2` extension is
 * used). The stub mirrors just the surface the adapter calls so static analysis
 * can resolve the class without adding a runtime dependency.
 *
 * @package ManaCore\MusicWave\Tools
 */

declare(strict_types=1);

namespace phpseclib\Net;

if ( ! class_exists( __NAMESPACE__ . '\\SFTP' ) ) {
	/**
	 * Minimal phpseclib SFTP surface used by SftpUploadAdapter.
	 */
	class SFTP {
		/** Copy the local file to the remote path. */
		public const SOURCE_LOCAL_FILE = 1;

		/**
		 * @param string $host Remote host.
		 * @param int    $port Remote port.
		 */
		public function __construct( string $host, int $port = 22 ) {
			unset( $host, $port );
		}

		/**
		 * @param string $user     Remote user.
		 * @param string $password Remote password.
		 */
		public function login( string $user, string $password = '' ): bool {
			unset( $user, $password );

			return true;
		}

		/**
		 * @param string $remote_file Destination path.
		 * @param string $data        Data or local path.
		 * @param int    $mode        One of the SOURCE_* constants.
		 */
		public function put( string $remote_file, string $data, int $mode = 0 ): bool {
			unset( $remote_file, $data, $mode );

			return true;
		}

		/**
		 * @param string $path Remote path.
		 * @return array<string, int|string>|false
		 */
		public function stat( string $path ) {
			unset( $path );

			return false;
		}

		/**
		 * Base64-encoded server host key, used to pin the fingerprint.
		 */
		public function getServerPublicHostKey(): string {
			return '';
		}
	}
}
