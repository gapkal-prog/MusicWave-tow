<?php
/**
 * Authentication module: wires the sign-in panels and the hardening layer.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Modules;

use ManaCore\MusicWave\Core\Account\AuthForms;
use ManaCore\MusicWave\Core\Account\AuthHandler;
use ManaCore\MusicWave\Core\Contracts\Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Auth implements Module {
	/** Template slug of the bundled auth page (theme.json `customTemplates`). */
	private const TEMPLATE = 'page-login';

	/** @var AuthForms */
	private $forms;

	/** @var AuthHandler */
	private $handler;

	public function __construct( AuthForms $forms, AuthHandler $handler ) {
		$this->forms   = $forms;
		$this->handler = $handler;
	}

	/**
	 * Register hooks owned by the authentication module.
	 */
	public function register(): void {
		$this->forms->register();
		$this->handler->register();

		add_action( 'template_redirect', array( $this, 'protect_auth_page' ), 1 );
		add_filter( 'wp_robots', array( $this, 'noindex_auth_page' ) );
	}

	/**
	 * Keep credentialed pages out of caches and frames (clickjacking defense).
	 */
	public function protect_auth_page(): void {
		if ( ! $this->is_auth_page() ) {
			return;
		}

		// Nonce-bearing screens must never be served from a page cache.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		nocache_headers();

		if ( function_exists( 'send_frame_options_header' ) ) {
			send_frame_options_header();
		}
	}

	/**
	 * Ask search engines not to index the auth page.
	 *
	 * @param array<string, bool|string> $robots Robots directives.
	 * @return array<string, bool|string>
	 */
	public function noindex_auth_page( $robots ) {
		if ( ! is_array( $robots ) || ! $this->is_auth_page() ) {
			return $robots;
		}

		$robots['noindex']  = true;
		$robots['nofollow'] = true;

		return $robots;
	}

	/**
	 * Whether the current request renders the bundled auth template.
	 */
	private function is_auth_page(): bool {
		return function_exists( 'is_page_template' ) && is_page_template( self::TEMPLATE );
	}
}
