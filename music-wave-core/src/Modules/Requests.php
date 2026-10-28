<?php
/**
 * Custom-song requests and collaboration proposals.
 *
 * Wires the private `mw_request` post type, the public no-JS form (block +
 * admin-post handler), the manager screen under the MusicWave menu and the
 * privacy exporter/eraser. Everything is optional-dependency friendly: the
 * module never touches the database schema.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Modules;

use ManaCore\MusicWave\Core\Blocks\RequestFormBlock;
use ManaCore\MusicWave\Core\Contracts\Module;
use ManaCore\MusicWave\Core\Requests\RequestChatHandler;
use ManaCore\MusicWave\Core\Requests\RequestFormHandler;
use ManaCore\MusicWave\Core\Requests\RequestPostType;
use ManaCore\MusicWave\Core\Requests\RequestPrivacy;
use ManaCore\MusicWave\Core\Requests\RequestsAdminPage;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Requests implements Module {
	/** @var RequestPostType */
	private $post_type;

	/** @var RequestFormHandler */
	private $forms;

	/** @var RequestFormBlock */
	private $block;

	/** @var RequestsAdminPage */
	private $admin_page;

	/** @var RequestPrivacy|null */
	private $privacy;

	/** @var RequestChatHandler|null */
	private $chat;

	public function __construct( RequestPostType $post_type, RequestFormHandler $forms, RequestFormBlock $block, RequestsAdminPage $admin_page, ?RequestPrivacy $privacy = null, ?RequestChatHandler $chat = null ) {
		$this->post_type  = $post_type;
		$this->forms      = $forms;
		$this->block      = $block;
		$this->admin_page = $admin_page;
		$this->privacy    = $privacy;
		$this->chat       = $chat;
	}

	public function register(): void {
		$this->post_type->register();
		$this->forms->register();
		if ( null !== $this->chat ) {
			$this->chat->register();
		}
		add_action( 'init', array( $this->block, 'register' ), 22 );
		// Expose repository for dashboard tracking block via filter – SOLID, no direct container coupling.
		add_filter(
			'music_wave_request_repository',
			function () {
				return new \ManaCore\MusicWave\Core\Requests\RequestRepository();
			}
		);
		// Expose chat settings for dashboard.
		add_filter(
			'music_wave_request_chat_enabled',
			function () {
				$settings = new \ManaCore\MusicWave\Core\Requests\RequestSettings();
				return $settings->chat_enabled();
			}
		);
		if ( null !== $this->privacy ) {
			$this->privacy->register();
		}
		if ( is_admin() ) {
			$this->admin_page->register();
		}
	}
}
