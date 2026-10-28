<?php
/**
 * Resolves, validates, provisions, and hardens the protected asset root directory.
 *
 * SRP: root resolution, safe directory provisioning, hardening files, and web-reachability checks.
 *
 * @package ManaCore\MusicWave\Vip\Storage
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Storage;

use ManaCore\MusicWave\Vip\VipSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RootResolver {

	/**
	 * @return string|false Absolute path or false when unsafe/unavailable.
	 */
	public function resolve() {
		$settings        = VipSettings::all();
		$configured_root = defined( 'MUSIC_WAVE_VIP_PROTECTED_ROOT' ) ? (string) constant( 'MUSIC_WAVE_VIP_PROTECTED_ROOT' ) : (string) $settings['protected_root'];
		$root            = '' === $configured_root ? $this->default_root() : realpath( $configured_root );

		if ( false === $root || ! is_dir( $root ) || ! is_readable( $root ) || $this->is_web_reachable( $root ) ) {
			return false;
		}
		return $root;
	}

	/**
	 * Provision (create if absent) and harden either a candidate directory or the active configured/default root.
	 *
	 * Refuses to create or return any path inside `ABSPATH` or `$_SERVER['DOCUMENT_ROOT']`.
	 *
	 * @return string|false Canonical hardened realpath on success, or `false` when unsafe/unwritable.
	 */
	public function provision( string $candidate = '' ) {
		$target = trim( $candidate );
		if ( '' === $target ) {
			$settings = VipSettings::all();
			$target   = defined( 'MUSIC_WAVE_VIP_PROTECTED_ROOT' ) ? (string) constant( 'MUSIC_WAVE_VIP_PROTECTED_ROOT' ) : (string) $settings['protected_root'];
		}
		if ( '' === $target ) {
			return $this->default_root();
		}

		$normalized  = function_exists( 'wp_normalize_path' ) ? wp_normalize_path( $target ) : str_replace( '\\', '/', $target );
		$parent      = dirname( rtrim( $normalized, '/' ) );
		$parent_real = '' !== $parent ? realpath( $parent ) : false;
		if ( false !== $parent_real && $this->is_web_reachable( $parent_real ) ) {
			return false;
		}

		if ( ! is_dir( $target ) && ! wp_mkdir_p( $target ) ) {
			return false;
		}

		$resolved = realpath( $target );
		if ( false === $resolved || ! is_dir( $resolved ) || ! is_readable( $resolved ) || $this->is_web_reachable( $resolved ) ) {
			return false;
		}

		$this->harden_directory( $resolved );

		return $resolved;
	}

	public function is_web_reachable( string $directory ): bool {
		$public_root = defined( 'ABSPATH' ) ? realpath( ABSPATH ) : false;
		if ( false !== $public_root && ( $directory === $public_root || $this->is_within( $directory, $public_root ) ) ) {
			return true;
		}

		$doc_root_raw       = isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) ? (string) $_SERVER['DOCUMENT_ROOT'] : ''; // phpcs:ignore
		$doc_root_unslashed = function_exists( 'wp_unslash' ) ? wp_unslash( $doc_root_raw ) : $doc_root_raw; // phpcs:ignore
		if ( '' !== $doc_root_raw && false !== strpos( $doc_root_raw, '\\' ) && false === strpos( $doc_root_unslashed, '\\' ) && false === strpos( $doc_root_unslashed, '/' ) ) {
			$doc_root_unslashed = $doc_root_raw;
		}
		$doc_root_sanitized = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $doc_root_unslashed ) : $doc_root_unslashed;
		if ( function_exists( 'wp_normalize_path' ) ) {
			$doc_root_sanitized = wp_normalize_path( $doc_root_sanitized );
		} else {
			$doc_root_sanitized = str_replace( '\\', '/', $doc_root_sanitized );
		}
		$document_root = '' !== $doc_root_sanitized ? realpath( $doc_root_sanitized ) : false;
		if ( false !== $document_root && ( $directory === $document_root || $this->is_within( $directory, $document_root ) ) ) {
			return true;
		}
		return false;
	}

	/**
	 * @return array<string, bool>
	 */
	public function preflight(): array {
		$settings        = VipSettings::all();
		$configured_root = defined( 'MUSIC_WAVE_VIP_PROTECTED_ROOT' ) ? (string) constant( 'MUSIC_WAVE_VIP_PROTECTED_ROOT' ) : (string) $settings['protected_root'];
		$candidate       = '' === $configured_root ? $this->default_root() : realpath( $configured_root );

		$exists      = false !== $candidate && is_dir( $candidate );
		$readable    = $exists && is_readable( $candidate );
		$writable    = $exists && wp_is_writable( $candidate );
		$outside_web = $exists && ! $this->is_web_reachable( (string) $candidate );
		$deny_files  = $exists
			&& file_exists( $candidate . DIRECTORY_SEPARATOR . '.htaccess' )
			&& file_exists( $candidate . DIRECTORY_SEPARATOR . 'web.config' );

		return array(
			'root_resolved'    => false !== $candidate,
			'directory_exists' => $exists,
			'readable'         => $readable,
			'writable'         => $writable,
			'outside_web_root' => $outside_web,
			'deny_files'       => $deny_files,
		);
	}

	/**
	 * @return string|false
	 */
	private function default_root() {
		if ( ! defined( 'ABSPATH' ) ) {
			return false;
		}
		$public_root = rtrim( ABSPATH, '/\\' );
		$parent      = dirname( $public_root );
		$root        = $parent . DIRECTORY_SEPARATOR . 'musicwave-private';

		$parent_real = realpath( $parent );
		if ( false !== $parent_real && $this->is_web_reachable( $parent_real ) ) {
			return false;
		}
		if ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) {
			return false;
		}
		$resolved = realpath( $root );
		if ( false !== $resolved ) {
			$this->harden_directory( $resolved );
		}
		return $resolved;
	}

	public function harden_directory( string $root ): void {
		$deny_files = array(
			'.htaccess'  => "Require all denied\nDeny from all\n",
			'web.config' => "<?xml version=\"1.0\"?><configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
			'index.html' => '',
		);
		foreach ( $deny_files as $name => $contents ) {
			$path = $root . DIRECTORY_SEPARATOR . $name;
			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, $contents ); // phpcs:ignore
			}
		}
	}

	private function is_within( string $path, string $root ): bool {
		$prefix = rtrim( $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		return 0 === strpos( $path, $prefix );
	}
}
