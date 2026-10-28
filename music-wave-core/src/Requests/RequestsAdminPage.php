<?php
/**
 * Manager screen for song requests and collaboration proposals.
 *
 * Lives under the MusicWave menu, directly above "MusicWave VIP": the
 * submenu is registered early (admin_menu priority 9) and pinned again at
 * the end of the menu pass so third-party items never slip in front of it.
 *
 * Two views share one hook suffix:
 *
 * - the inbox: status tabs with counts, type/role/priority/search filters,
 *   paginated table, per-row quick actions and bulk actions;
 * - the detail view: the full request, the requester card, a reply form that
 *   emails the requester, internal notes, status/priority controls, a
 *   chronological activity log and a delete action.
 *
 * Every mutation is a nonce-protected `admin-post.php` action guarded by
 * {@see RequestPostType::MANAGE_CAP}; the screen itself never writes.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Requests;

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RequestsAdminPage {
	public const PAGE       = 'music-wave-requests';
	public const PARENT     = 'edit.php?post_type=' . ReleasePostType::KEY;
	public const ACTION     = 'music_wave_manage_request';
	public const NONCE      = 'music_wave_manage_request';
	public const NOTICE_ARG = 'mw-notice';
	public const PER_PAGE   = 20;

	/** @var RequestRepository */
	private $repository;

	/** @var RequestMailer */
	private $mailer;

	/** @var RequestSettings */
	private $settings;

	/** @var string Hook suffix returned by add_submenu_page(). */
	private $hook_suffix = '';

	public function __construct( RequestRepository $repository, ?RequestMailer $mailer = null, ?RequestSettings $settings = null ) {
		$this->repository = $repository;
		$this->settings   = null !== $settings ? $settings : new RequestSettings();
		$this->mailer     = null !== $mailer ? $mailer : new RequestMailer( $this->settings );
	}

	/**
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ), 9 );
		add_action( 'admin_menu', array( $this, 'pin_menu_position' ), 99 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_action' ) );
	}

	/**
	 * Register the submenu with an unread badge.
	 *
	 * @return void
	 */
	public function add_page(): void {
		$hook = add_submenu_page(
			self::PARENT,
			__( 'درخواست‌های آهنگ و همکاری', 'music-wave-core' ),
			$this->menu_title(),
			RequestPostType::MANAGE_CAP,
			self::PAGE,
			array( $this, 'render' )
		);
		if ( is_string( $hook ) && '' !== $hook ) {
			$this->hook_suffix = $hook;
			// Help tabs must exist before admin-header.php renders the screen
			// meta, i.e. on load-{hook}, not inside the page callback.
			add_action( 'load-' . $hook, array( $this, 'register_help_tabs' ) );
		}
	}

	/**
	 * Keep the item immediately above the VIP entry (or at the end of the
	 * MusicWave group when VIP is not installed).
	 *
	 * @return void
	 */
	public function pin_menu_position(): void {
		global $submenu;

		if ( ! isset( $submenu[ self::PARENT ] ) || ! is_array( $submenu[ self::PARENT ] ) ) {
			return;
		}

		$submenu[ self::PARENT ] = self::reorder( $submenu[ self::PARENT ], self::PAGE, 'music-wave-vip' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intentional submenu ordering.
	}

	/**
	 * Move the item with slug $slug directly before $before (pure, tested).
	 *
	 * @param array<int, array<int, string>> $items  Submenu rows.
	 * @return array<int, array<int, string>>
	 */
	public static function reorder( array $items, string $slug, string $before ): array {
		$own  = null;
		$rest = array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) && isset( $item[2] ) && $slug === $item[2] ) {
				$own = $item;
				continue;
			}
			$rest[] = $item;
		}
		if ( null === $own ) {
			return array_values( $items );
		}

		$ordered  = array();
		$inserted = false;
		foreach ( $rest as $item ) {
			if ( ! $inserted && is_array( $item ) && isset( $item[2] ) && $before === $item[2] ) {
				$ordered[] = $own;
				$inserted  = true;
			}
			$ordered[] = $item;
		}
		if ( ! $inserted ) {
			$ordered[] = $own;
		}

		return $ordered;
	}

	/**
	 * Settings API registration for the workflow options.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'music_wave_requests',
			RequestSettings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( RequestSettings::class, 'sanitize' ),
				'default'           => RequestSettings::defaults(),
			)
		);
	}

	/**
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		$expected = '' !== $this->hook_suffix ? $this->hook_suffix : ReleasePostType::KEY . '_page_' . self::PAGE;
		if ( $expected !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style( 'music-wave-admin-settings', MUSIC_WAVE_CORE_URL . 'assets/admin-settings.css', array(), MUSIC_WAVE_CORE_VERSION );
		wp_enqueue_style( 'music-wave-admin-requests', MUSIC_WAVE_CORE_URL . 'assets/admin-requests.css', array( 'music-wave-admin-settings' ), MUSIC_WAVE_CORE_VERSION );
		wp_enqueue_script( 'music-wave-admin-requests', MUSIC_WAVE_CORE_URL . 'assets/admin-requests.js', array(), MUSIC_WAVE_CORE_VERSION, true );
		wp_localize_script(
			'music-wave-admin-requests',
			'musicWaveRequestsAdmin',
			array(
				'confirmDelete' => __( 'برای حذف دائمی دوباره کلیک کنید', 'music-wave-core' ),
				'confirmBulk'   => __( 'برای اجرا روی موارد انتخاب‌شده دوباره کلیک کنید', 'music-wave-core' ),
			)
		);
	}

	/**
	 * Admin URL for the screen with optional extra arguments.
	 *
	 * @param array<string, string|int> $args Query arguments.
	 */
	public static function url( array $args = array() ): string {
		$base = array(
			'post_type' => ReleasePostType::KEY,
			'page'      => self::PAGE,
		);
		$args = array_merge( $base, array_map( 'strval', $args ) );

		return add_query_arg( $args, admin_url( 'edit.php' ) );
	}

	/**
	 * Route the screen to the inbox, a request or the settings tab.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( RequestPostType::MANAGE_CAP ) ) {
			return;
		}

		$view       = $this->query_key( 'view' );
		$request_id = $this->query_int( 'request' );

		echo '<div class="wrap mw-settings mw-requests">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'درخواست‌های آهنگ و همکاری', 'music-wave-core' ) . '</h1>';
		if ( 'new' !== $view && $request_id < 1 ) {
			echo ' <a href="' . esc_url( self::url( array( 'view' => 'new' ) ) ) . '" class="page-title-action">' . esc_html__( 'ثبت درخواست دستی', 'music-wave-core' ) . '</a>';
		}
		if ( 'settings' !== $view ) {
			echo ' <a href="' . esc_url( self::url( array( 'view' => 'settings' ) ) ) . '" class="page-title-action">' . esc_html__( 'تنظیمات و چت', 'music-wave-core' ) . '</a>';
		}
		echo '<hr class="wp-header-end">';
		$this->render_notice();

		if ( $request_id > 0 ) {
			$request = $this->repository->find( $request_id );
			if ( null === $request ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'این درخواست پیدا نشد یا حذف شده است.', 'music-wave-core' ) . '</p></div>';
				$this->render_inbox();
			} else {
				$this->render_detail( $request );
			}
		} elseif ( 'new' === $view ) {
			$this->render_manual_form();
		} elseif ( 'settings' === $view ) {
			$this->render_settings();
		} else {
			$this->render_inbox();
		}

		echo '</div>';
	}

	/**
	 * Handle every manager mutation.
	 *
	 * @return void
	 */
	public function handle_action(): void {
		if ( ! current_user_can( RequestPostType::MANAGE_CAP ) ) {
			wp_die( esc_html__( 'شما اجازهٔ مدیریت درخواست‌ها را ندارید.', 'music-wave-core' ), 403 );
		}
		check_admin_referer( self::NONCE );

		$operation  = $this->post_key( 'mw_operation' );
		$request_id = isset( $_POST['mw_request_id'] ) ? absint( wp_unslash( (string) $_POST['mw_request_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above.
		$ids        = array();
		if ( isset( $_POST['mw_request_ids'] ) && is_array( $_POST['mw_request_ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$ids = array_values( array_filter( array_map( 'absint', wp_unslash( $_POST['mw_request_ids'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint per value.
		}
		$payload = array(
			'status'   => $this->post_key( 'mw_status' ),
			'priority' => $this->post_key( 'mw_priority' ),
			'body'     => $this->post_text( 'mw_body', true ),
			'subject'  => $this->post_text( 'mw_subject' ),
			'bulk'     => $this->post_key( 'mw_bulk_action' ),
		);
		$fields  = array();
		foreach ( RequestSubmission::fields() as $field ) {
			if ( isset( $_POST[ 'mw_field_' . $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$raw              = wp_unslash( $_POST[ 'mw_field_' . $field ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by RequestSubmission::validate().
				$fields[ $field ] = is_array( $raw ) ? array_map( 'strval', $raw ) : (string) $raw;
			}
		}

		$result = $this->run( $operation, $request_id, $ids, $payload, $fields, get_current_user_id() );

		$target = $result['request_id'] > 0 && 'deleted' !== $result['notice'] ? self::url( array( 'request' => $result['request_id'] ) ) : $this->return_url();
		wp_safe_redirect( add_query_arg( self::NOTICE_ARG, $result['notice'], $target ), 303 );
		if ( ! defined( 'MUSIC_WAVE_TESTING' ) ) {
			exit;
		}
	}

	/**
	 * Execute one manager operation (test seam: no superglobals, no output).
	 *
	 * @param array<int, int>      $ids     Bulk selection.
	 * @param array<string, string> $payload status|priority|body|subject|bulk.
	 * @param array<string, mixed>  $fields  Manual-entry / edit fields.
	 * @return array{notice: string, request_id: int}
	 */
	public function run( string $operation, int $request_id, array $ids, array $payload, array $fields, int $actor_id ): array {
		switch ( $operation ) {
			case 'reply':
				$request = $this->repository->find( $request_id );
				if ( null === $request || '' === trim( $payload['body'] ) ) {
					return $this->result( 'reply-empty', $request_id );
				}
				$sent = $this->mailer->send_reply( $request, $payload['body'], $payload['subject'] );
				$this->repository->append_log(
					$request_id,
					RequestRepository::LOG_REPLY,
					$payload['body'],
					$actor_id,
					array(
						'sent' => $sent,
						'by'   => 'admin',
					)
				);
				if ( in_array( $request['status'], array( RequestPostType::STATUS_NEW, RequestPostType::STATUS_REVIEW ), true ) ) {
					$this->repository->set_status( $request_id, RequestPostType::STATUS_REPLIED, $actor_id );
				}

				return $this->result( $sent ? 'replied' : 'reply-logged', $request_id );

			case 'note':
				if ( '' === trim( $payload['body'] ) ) {
					return $this->result( 'note-empty', $request_id );
				}

				return $this->result( null !== $this->repository->append_log( $request_id, RequestRepository::LOG_NOTE, $payload['body'], $actor_id ) ? 'noted' : 'not-found', $request_id );

			case 'status':
				return $this->result( $this->repository->set_status( $request_id, $payload['status'], $actor_id ) ? 'status-updated' : 'status-failed', $request_id );

			case 'priority':
				return $this->result( $this->repository->set_priority( $request_id, $payload['priority'] ) ? 'priority-updated' : 'status-failed', $request_id );

			case 'delete':
				return $this->result( $this->repository->delete( $request_id ) ? 'deleted' : 'not-found', 0 );

			case 'create':
			case 'update':
				$validated = RequestSubmission::validate( array_merge( $fields, array( 'consent' => '1' ) ) );
				if ( array() !== $validated['errors'] ) {
					return $this->result( 'form-invalid', 'update' === $operation ? $request_id : 0 );
				}
				if ( 'update' === $operation ) {
					return $this->result( $this->repository->update_fields( $request_id, $validated['data'] ) ? 'updated' : 'not-found', $request_id );
				}
				$created = $this->repository->create( $validated['data'], $actor_id, 'manual' );
				if ( $created < 1 ) {
					return $this->result( 'status-failed', 0 );
				}
				$this->repository->append_log( $created, RequestRepository::LOG_EVENT, __( 'به‌صورت دستی توسط مدیر ثبت شد.', 'music-wave-core' ), $actor_id );

				return $this->result( 'created', $created );

			case 'bulk':
				$done = 0;
				foreach ( $ids as $id ) {
					if ( 'delete' === $payload['bulk'] ) {
						$done += $this->repository->delete( $id ) ? 1 : 0;
					} elseif ( 0 === strpos( $payload['bulk'], 'status:' ) ) {
						$done += $this->repository->set_status( $id, substr( $payload['bulk'], 7 ), $actor_id ) ? 1 : 0;
					} elseif ( 0 === strpos( $payload['bulk'], 'priority:' ) ) {
						$done += $this->repository->set_priority( $id, substr( $payload['bulk'], 9 ) ) ? 1 : 0;
					}
				}

				return $this->result( $done > 0 ? 'bulk-done' : 'bulk-none', 0 );
		}

		return $this->result( 'unknown', $request_id );
	}

	/**
	 * Translated notice copy.
	 */
	public function notice_message( string $notice ): string {
		$messages = array(
			'replied'          => __( 'پاسخ برای درخواست‌کننده ایمیل شد و در سابقه ثبت شد.', 'music-wave-core' ),
			'reply-logged'     => __( 'پاسخ در سابقه ثبت شد اما ارسال ایمیل ناموفق بود؛ تنظیمات ایمیل سایت را بررسی کنید.', 'music-wave-core' ),
			'reply-empty'      => __( 'متن پاسخ خالی است.', 'music-wave-core' ),
			'noted'            => __( 'یادداشت داخلی ذخیره شد.', 'music-wave-core' ),
			'note-empty'       => __( 'متن یادداشت خالی است.', 'music-wave-core' ),
			'status-updated'   => __( 'وضعیت درخواست به‌روزرسانی شد.', 'music-wave-core' ),
			'priority-updated' => __( 'اولویت درخواست به‌روزرسانی شد.', 'music-wave-core' ),
			'status-failed'    => __( 'به‌روزرسانی انجام نشد.', 'music-wave-core' ),
			'deleted'          => __( 'درخواست برای همیشه حذف شد.', 'music-wave-core' ),
			'not-found'        => __( 'درخواست پیدا نشد.', 'music-wave-core' ),
			'created'          => __( 'درخواست ثبت شد.', 'music-wave-core' ),
			'updated'          => __( 'اطلاعات درخواست ذخیره شد.', 'music-wave-core' ),
			'form-invalid'     => __( 'برخی فیلدها معتبر نیستند؛ نام، ایمیل، عنوان و توضیحات (حداقل ۲۰ نویسه) الزامی‌اند.', 'music-wave-core' ),
			'bulk-done'        => __( 'عملیات گروهی انجام شد.', 'music-wave-core' ),
			'bulk-none'        => __( 'هیچ موردی برای عملیات گروهی انتخاب نشده بود.', 'music-wave-core' ),
			'settings-saved'   => __( 'تنظیمات ذخیره شد.', 'music-wave-core' ),
			'unknown'          => __( 'عملیات ناشناخته بود.', 'music-wave-core' ),
		);

		return isset( $messages[ $notice ] ) ? $messages[ $notice ] : '';
	}

	/**
	 * Whether a notice is an error.
	 */
	public function notice_is_error( string $notice ): bool {
		return in_array( $notice, array( 'reply-logged', 'reply-empty', 'note-empty', 'status-failed', 'not-found', 'form-invalid', 'bulk-none', 'unknown' ), true );
	}

	/**
	 * Menu label with the count of new requests.
	 */
	private function menu_title(): string {
		$label = __( 'درخواست‌ها و همکاری', 'music-wave-core' );
		$open  = 0;
		foreach ( $this->repository->counts() as $status => $count ) {
			if ( in_array( $status, RequestPostType::open_statuses(), true ) ) {
				$open += $count;
			}
		}
		if ( $open < 1 ) {
			return $label;
		}

		return $label . ' <span class="awaiting-mod count-' . (int) $open . '"><span class="pending-count" aria-hidden="true">' . number_format_i18n( $open ) . '</span><span class="screen-reader-text">' . esc_html(
			sprintf(
				/* translators: %s: number of new requests. */
				_n( '%s درخواست جدید', '%s درخواست جدید', $open, 'music-wave-core' ),
				number_format_i18n( $open )
			)
		) . '</span></span>';
	}

	// ---------------------------------------------------------------------
	// Inbox
	// ---------------------------------------------------------------------

	/**
	 * @return void
	 */
	private function render_inbox(): void {
		$counts   = $this->repository->counts();
		$status   = $this->query_key( 'status' );
		$filters  = array(
			'status'   => RequestPostType::is_status( $status ) ? $status : '',
			'type'     => $this->query_key( 'type' ),
			'role'     => $this->query_key( 'role' ),
			'priority' => $this->query_key( 'priority' ),
			'search'   => $this->query_text( 's' ),
		);
		$page     = max( 1, $this->query_int( 'paged' ) );
		$listing  = $this->repository->query( $filters, $page, self::PER_PAGE );
		$total    = array_sum( $counts );
		$new      = isset( $counts[ RequestPostType::STATUS_NEW ] ) ? $counts[ RequestPostType::STATUS_NEW ] : 0;
		$accepted = isset( $counts[ RequestPostType::STATUS_ACCEPTED ] ) ? $counts[ RequestPostType::STATUS_ACCEPTED ] : 0;
		$replied  = isset( $counts[ RequestPostType::STATUS_REPLIED ] ) ? $counts[ RequestPostType::STATUS_REPLIED ] : 0;

		echo '<p class="mw-settings__lead">' . esc_html__( 'درخواست‌های آهنگ اختصاصی، پیشنهادهای همکاری و تبلیغات که از فرم عمومی سایت می‌رسند. از همین‌جا پاسخ دهید، وضعیت را پیش ببرید و سابقه را نگه دارید.', 'music-wave-core' ) . '</p>';

		echo '<div class="mw-settings__grid mw-settings__grid--stats">';
		$this->stat( 'dashicons-email-alt', number_format_i18n( $new ), __( 'در انتظار بررسی', 'music-wave-core' ) );
		$this->stat( 'dashicons-format-chat', number_format_i18n( $replied ), __( 'پاسخ داده شده', 'music-wave-core' ) );
		$this->stat( 'dashicons-yes-alt', number_format_i18n( $accepted ), __( 'همکاری پذیرفته‌شده', 'music-wave-core' ) );
		$this->stat( 'dashicons-portfolio', number_format_i18n( $total ), __( 'کل درخواست‌ها', 'music-wave-core' ) );
		echo '</div>';

		$this->render_status_tabs( $counts, $filters['status'] );
		$this->render_filter_bar( $filters );

		if ( array() === $listing['items'] ) {
			echo '<div class="mw-settings__panel mw-requests__empty"><span class="dashicons dashicons-format-audio" aria-hidden="true"></span><h2>' . esc_html__( 'هنوز درخواستی اینجا نیست', 'music-wave-core' ) . '</h2>';
			echo '<p>' . esc_html__( 'وقتی بازدیدکننده‌ای فرم «درخواست آهنگ و همکاری» را پر کند، اینجا نمایش داده می‌شود. بلوک «فرم درخواست همکاری» را در هر برگه‌ای قرار دهید یا از الگوی آمادهٔ پوسته استفاده کنید.', 'music-wave-core' ) . '</p>';
			echo '<p><a class="button button-secondary" href="' . esc_url( self::url( array( 'view' => 'settings' ) ) ) . '">' . esc_html__( 'تنظیمات اعلان و فرم', 'music-wave-core' ) . '</a> <a class="button" href="' . esc_url( self::url( array( 'view' => 'new' ) ) ) . '">' . esc_html__( 'ثبت درخواست دستی', 'music-wave-core' ) . '</a></p></div>';

			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mw-requests__bulk" data-mw-bulk>';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="mw_operation" value="bulk"><input type="hidden" name="mw_return" value="' . esc_attr( $this->current_admin_url() ) . '">';
		echo '<div class="tablenav top"><div class="alignleft actions bulkactions"><label for="mw-bulk-action" class="screen-reader-text">' . esc_html__( 'عملیات گروهی', 'music-wave-core' ) . '</label><select name="mw_bulk_action" id="mw-bulk-action"><option value="">' . esc_html__( 'عملیات گروهی', 'music-wave-core' ) . '</option>';
		foreach ( RequestPostType::status_labels() as $key => $label ) {
			echo '<option value="' . esc_attr( 'status:' . $key ) . '">' . esc_html(
				sprintf(
					/* translators: %s: status label. */
					__( 'تغییر وضعیت به «%s»', 'music-wave-core' ),
					$label
				)
			) . '</option>';
		}
		echo '<option value="priority:high">' . esc_html__( 'علامت‌گذاری به‌عنوان فوری', 'music-wave-core' ) . '</option><option value="priority:normal">' . esc_html__( 'برداشتن نشان فوری', 'music-wave-core' ) . '</option><option value="delete">' . esc_html__( 'حذف دائمی', 'music-wave-core' ) . '</option></select> ';
		echo '<button type="submit" class="button action">' . esc_html__( 'اجرا', 'music-wave-core' ) . '</button></div>';
		$this->render_pagination( $listing['total'], $listing['pages'], $page );
		echo '</div>';

		echo '<table class="wp-list-table widefat fixed striped mw-requests__table"><thead><tr>';
		echo '<td class="manage-column column-cb check-column"><label class="screen-reader-text" for="mw-select-all">' . esc_html__( 'انتخاب همه', 'music-wave-core' ) . '</label><input type="checkbox" id="mw-select-all" data-mw-select-all></td>';
		echo '<th scope="col" class="column-primary">' . esc_html__( 'درخواست', 'music-wave-core' ) . '</th><th scope="col">' . esc_html__( 'درخواست‌کننده', 'music-wave-core' ) . '</th><th scope="col">' . esc_html__( 'نوع', 'music-wave-core' ) . '</th><th scope="col">' . esc_html__( 'وضعیت', 'music-wave-core' ) . '</th><th scope="col">' . esc_html__( 'تاریخ', 'music-wave-core' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $listing['items'] as $request ) {
			$this->render_row( $request );
		}
		echo '</tbody></table>';
		echo '<div class="tablenav bottom">';
		$this->render_pagination( $listing['total'], $listing['pages'], $page );
		echo '</div></form>';
	}

	/**
	 * @param array<string, int> $counts Per-status counts.
	 */
	private function render_status_tabs( array $counts, string $active ): void {
		echo '<ul class="subsubsub mw-requests__tabs">';
		$all = self::url();
		echo '<li><a href="' . esc_url( $all ) . '"' . ( '' === $active ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html__( 'همه', 'music-wave-core' ) . ' <span class="count">(' . esc_html( number_format_i18n( array_sum( $counts ) ) ) . ')</span></a></li>';
		foreach ( RequestPostType::status_labels() as $status => $label ) {
			$count = isset( $counts[ $status ] ) ? (int) $counts[ $status ] : 0;
			echo '<li> | <a href="' . esc_url( self::url( array( 'status' => $status ) ) ) . '"' . ( $active === $status ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $label ) . ' <span class="count">(' . esc_html( number_format_i18n( $count ) ) . ')</span></a></li>';
		}
		echo '</ul>';
	}

	/**
	 * @param array<string, string> $filters Active filters.
	 */
	private function render_filter_bar( array $filters ): void {
		echo '<form method="get" action="' . esc_url( admin_url( 'edit.php' ) ) . '" class="mw-requests__filters">';
		echo '<input type="hidden" name="post_type" value="' . esc_attr( ReleasePostType::KEY ) . '"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
		if ( '' !== $filters['status'] ) {
			echo '<input type="hidden" name="status" value="' . esc_attr( $filters['status'] ) . '">';
		}
		$this->filter_select( 'type', __( 'همهٔ انواع', 'music-wave-core' ), RequestPostType::type_labels(), $filters['type'] );
		$this->filter_select( 'role', __( 'همهٔ نقش‌ها', 'music-wave-core' ), RequestPostType::role_labels(), $filters['role'] );
		$this->filter_select( 'priority', __( 'هر اولویتی', 'music-wave-core' ), RequestPostType::priority_labels(), $filters['priority'] );
		echo '<label class="screen-reader-text" for="mw-requests-search">' . esc_html__( 'جست‌وجو در درخواست‌ها', 'music-wave-core' ) . '</label><input type="search" id="mw-requests-search" name="s" value="' . esc_attr( $filters['search'] ) . '" placeholder="' . esc_attr__( 'نام، ایمیل یا عنوان…', 'music-wave-core' ) . '">';
		echo '<button type="submit" class="button">' . esc_html__( 'اعمال فیلتر', 'music-wave-core' ) . '</button>';
		if ( '' !== $filters['type'] || '' !== $filters['role'] || '' !== $filters['priority'] || '' !== $filters['search'] ) {
			echo ' <a class="button-link" href="' . esc_url( '' !== $filters['status'] ? self::url( array( 'status' => $filters['status'] ) ) : self::url() ) . '">' . esc_html__( 'پاک کردن فیلترها', 'music-wave-core' ) . '</a>';
		}
		echo '</form>';
	}

	/**
	 * @param array<string, string> $options Options.
	 */
	private function filter_select( string $name, string $placeholder, array $options, string $current ): void {
		echo '<label class="screen-reader-text" for="mw-filter-' . esc_attr( $name ) . '">' . esc_html( $placeholder ) . '</label><select name="' . esc_attr( $name ) . '" id="mw-filter-' . esc_attr( $name ) . '"><option value="">' . esc_html( $placeholder ) . '</option>';
		foreach ( $options as $value => $label ) {
			if ( '' === (string) $value ) {
				continue;
			}
			echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( $current, (string) $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * @param array<string, mixed> $request Normalized request.
	 */
	private function render_row( array $request ): void {
		$id        = (int) $request['id'];
		$detail    = self::url( array( 'request' => $id ) );
		$is_new    = RequestPostType::STATUS_NEW === $request['status'];
		$row_class = 'mw-requests__row' . ( $is_new ? ' mw-requests__row--new' : '' ) . ( 'high' === $request['priority'] ? ' mw-requests__row--high' : '' );

		echo '<tr class="' . esc_attr( $row_class ) . '">';
		echo '<th scope="row" class="check-column"><label class="screen-reader-text" for="mw-cb-' . (int) $id . '">' . esc_html__( 'انتخاب', 'music-wave-core' ) . '</label><input type="checkbox" name="mw_request_ids[]" id="mw-cb-' . (int) $id . '" value="' . (int) $id . '"></th>';
		echo '<td class="column-primary has-row-actions"><strong><a class="row-title" href="' . esc_url( $detail ) . '">' . esc_html( (string) $request['subject'] ) . '</a></strong>';
		if ( 'high' === $request['priority'] ) {
			echo ' <span class="mw-status mw-status--attention">' . esc_html__( 'فوری', 'music-wave-core' ) . '</span>';
		}
		echo '<div class="mw-requests__excerpt">' . esc_html( $this->excerpt( (string) $request['message'] ) ) . '</div>';
		echo '<div class="row-actions"><span class="view"><a href="' . esc_url( $detail ) . '">' . esc_html__( 'مشاهده و پاسخ', 'music-wave-core' ) . '</a> | </span>';
		echo '<span class="mail"><a href="' . esc_url( 'mailto:' . (string) $request['email'] ) . '">' . esc_html__( 'ایمیل', 'music-wave-core' ) . '</a> | </span>';
		echo '<span class="ref">' . esc_html( (string) $request['reference'] ) . '</span></div>';
		echo '<button type="button" class="toggle-row"><span class="screen-reader-text">' . esc_html__( 'نمایش جزئیات بیشتر', 'music-wave-core' ) . '</span></button></td>';
		echo '<td data-colname="' . esc_attr__( 'درخواست‌کننده', 'music-wave-core' ) . '"><strong>' . esc_html( (string) $request['name'] ) . '</strong><br><span class="mw-requests__muted">' . esc_html( $this->label( RequestPostType::role_labels(), (string) $request['role'] ) ) . '</span></td>';
		echo '<td data-colname="' . esc_attr__( 'نوع', 'music-wave-core' ) . '">' . esc_html( $this->label( RequestPostType::type_labels(), (string) $request['type'] ) );
		if ( '' !== (string) $request['budget'] ) {
			echo '<br><span class="mw-requests__muted">' . esc_html( $this->label( RequestPostType::budget_labels(), (string) $request['budget'] ) ) . '</span>';
		}
		echo '</td>';
		echo '<td data-colname="' . esc_attr__( 'وضعیت', 'music-wave-core' ) . '">' . $this->status_badge( (string) $request['status'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		echo '<td data-colname="' . esc_attr__( 'تاریخ', 'music-wave-core' ) . '">' . esc_html( $this->date( $id ) );
		if ( '' !== (string) $request['deadline'] ) {
			echo '<br><span class="mw-requests__muted">' . esc_html(
				sprintf(
					/* translators: %s: requested deadline. */
					__( 'مهلت: %s', 'music-wave-core' ),
					(string) $request['deadline']
				)
			) . '</span>';
		}
		echo '</td></tr>';
	}

	private function render_pagination( int $total, int $pages, int $page ): void {
		echo '<div class="tablenav-pages"><span class="displaying-num">' . esc_html(
			sprintf(
				/* translators: %s: number of requests. */
				_n( '%s مورد', '%s مورد', $total, 'music-wave-core' ),
				number_format_i18n( $total )
			)
		) . '</span>';
		if ( $pages > 1 ) {
			$base = remove_query_arg( 'paged', $this->current_admin_url() );
			echo ' <span class="pagination-links">';
			if ( $page > 1 ) {
				echo '<a class="prev-page button" href="' . esc_url( add_query_arg( 'paged', $page - 1, $base ) ) . '"><span aria-hidden="true">‹</span><span class="screen-reader-text">' . esc_html__( 'صفحهٔ قبل', 'music-wave-core' ) . '</span></a> ';
			}
			echo '<span class="paging-input">' . esc_html(
				sprintf(
					/* translators: 1: current page, 2: total pages. */
					__( '%1$s از %2$s', 'music-wave-core' ),
					number_format_i18n( $page ),
					number_format_i18n( $pages )
				)
			) . '</span>';
			if ( $page < $pages ) {
				echo ' <a class="next-page button" href="' . esc_url( add_query_arg( 'paged', $page + 1, $base ) ) . '"><span aria-hidden="true">›</span><span class="screen-reader-text">' . esc_html__( 'صفحهٔ بعد', 'music-wave-core' ) . '</span></a>';
			}
			echo '</span>';
		}
		echo '</div>';
	}

	// ---------------------------------------------------------------------
	// Detail
	// ---------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $request Normalized request.
	 */
	private function render_detail( array $request ): void {
		$id = (int) $request['id'];
		echo '<p class="mw-requests__back"><a href="' . esc_url( $this->return_url() ) . '">&larr; ' . esc_html__( 'بازگشت به فهرست درخواست‌ها', 'music-wave-core' ) . '</a></p>';

		echo '<div class="mw-requests__detail">';

		// Main column.
		echo '<div class="mw-requests__main">';
		echo '<section class="mw-settings__panel mw-requests__message"><div class="mw-requests__title"><h2>' . esc_html( (string) $request['subject'] ) . '</h2>' . $this->status_badge( (string) $request['status'] ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		echo '<p class="mw-requests__meta"><span>' . esc_html( (string) $request['reference'] ) . '</span> · <span>' . esc_html( $this->label( RequestPostType::type_labels(), (string) $request['type'] ) ) . '</span> · <span>' . esc_html( $this->date( $id, true ) ) . '</span>';
		if ( 'manual' === $request['source'] ) {
			echo ' · <span>' . esc_html__( 'ثبت دستی', 'music-wave-core' ) . '</span>';
		}
		echo '</p>';
		echo '<div class="mw-requests__body">' . wp_kses_post( wpautop( esc_html( (string) $request['message'] ) ) ) . '</div>';
		if ( array() !== $request['links'] ) {
			echo '<h3>' . esc_html__( 'نمونه‌کارها و پیوندها', 'music-wave-core' ) . '</h3><ul class="mw-requests__links">';
			foreach ( $request['links'] as $link ) {
				echo '<li><a href="' . esc_url( (string) $link ) . '" target="_blank" rel="noopener noreferrer nofollow">' . esc_html( (string) $link ) . '</a></li>';
			}
			echo '</ul>';
		}
		echo '</section>';

		// Reply.
		echo '<section class="mw-settings__panel mw-requests__reply"><h2>' . esc_html__( 'پاسخ به درخواست‌کننده', 'music-wave-core' ) . '</h2>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: requester email. */
				__( 'پاسخ به %s ایمیل می‌شود و در سابقهٔ همین درخواست می‌ماند. با ارسال پاسخ، وضعیت «جدید» یا «در حال بررسی» به «پاسخ داده شده» می‌رود.', 'music-wave-core' ),
				(string) $request['email']
			)
		) . '</p>';
		$this->open_form( 'reply', $id );
		echo '<p><label for="mw-reply-subject">' . esc_html__( 'موضوع ایمیل (اختیاری)', 'music-wave-core' ) . '</label><input type="text" id="mw-reply-subject" name="mw_subject" class="large-text" placeholder="' . esc_attr(
			sprintf(
				/* translators: %s: request subject. */
				__( 'پاسخ به درخواست شما: %s', 'music-wave-core' ),
				(string) $request['subject']
			)
		) . '"></p>';
		echo '<p><label for="mw-reply-body">' . esc_html__( 'متن پاسخ', 'music-wave-core' ) . '</label><textarea id="mw-reply-body" name="mw_body" rows="7" class="large-text" required></textarea></p>';
		echo '<div class="mw-requests__templates" data-mw-templates><span>' . esc_html__( 'متن‌های آماده:', 'music-wave-core' ) . '</span>';
		foreach ( $this->reply_templates( $request ) as $template_label => $template_body ) {
			echo ' <button type="button" class="button-link" data-mw-template="' . esc_attr( $template_body ) . '">' . esc_html( (string) $template_label ) . '</button>';
		}
		echo '</div>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'ارسال پاسخ', 'music-wave-core' ) . '</button></p></form></section>';

		// Notes.
		echo '<section class="mw-settings__panel mw-requests__notes"><h2>' . esc_html__( 'یادداشت داخلی', 'music-wave-core' ) . '</h2><p class="description">' . esc_html__( 'فقط برای تیم شما؛ برای درخواست‌کننده ارسال نمی‌شود.', 'music-wave-core' ) . '</p>';
		$this->open_form( 'note', $id );
		echo '<p><label class="screen-reader-text" for="mw-note-body">' . esc_html__( 'متن یادداشت', 'music-wave-core' ) . '</label><textarea id="mw-note-body" name="mw_body" rows="3" class="large-text" required></textarea></p><p><button type="submit" class="button">' . esc_html__( 'ذخیرهٔ یادداشت', 'music-wave-core' ) . '</button></p></form></section>';

		// Log.
		echo '<section class="mw-settings__panel mw-requests__log"><h2>' . esc_html__( 'سابقه و گفت‌وگو', 'music-wave-core' ) . '</h2>';
		$log = array_reverse( $request['log'] );
		if ( array() === $log ) {
			echo '<p class="mw-requests__muted">' . esc_html__( 'هنوز پاسخ یا یادداشتی ثبت نشده است.', 'music-wave-core' ) . '</p>';
		} else {
			echo '<ol class="mw-requests__timeline">';
			foreach ( $log as $entry ) {
				$this->render_log_entry( is_array( $entry ) ? $entry : array() );
			}
			echo '</ol>';
		}
		echo '</section>';
		echo '</div>';

		// Sidebar.
		echo '<aside class="mw-requests__side">';
		echo '<section class="mw-settings__panel mw-requests__person"><h2>' . esc_html__( 'درخواست‌کننده', 'music-wave-core' ) . '</h2>';
		echo '<div class="mw-requests__avatar" aria-hidden="true">' . esc_html( $this->initial( (string) $request['name'] ) ) . '</div>';
		echo '<p class="mw-requests__person-name">' . esc_html( (string) $request['name'] ) . '</p>';
		echo '<p class="mw-requests__muted">' . esc_html( $this->label( RequestPostType::role_labels(), (string) $request['role'] ) ) . '</p>';
		echo '<dl class="mw-requests__facts">';
		$this->fact( __( 'ایمیل', 'music-wave-core' ), '<a href="' . esc_url( 'mailto:' . (string) $request['email'] ) . '">' . esc_html( (string) $request['email'] ) . '</a>' );
		if ( '' !== (string) $request['phone'] ) {
			$this->fact( __( 'تلفن', 'music-wave-core' ), '<a href="' . esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', (string) $request['phone'] ) ) . '" dir="ltr">' . esc_html( (string) $request['phone'] ) . '</a>' );
		}
		$this->fact( __( 'بودجه', 'music-wave-core' ), esc_html( $this->label( RequestPostType::budget_labels(), (string) $request['budget'] ) ) );
		$this->fact( __( 'زمان مورد نظر', 'music-wave-core' ), esc_html( '' !== (string) $request['deadline'] ? (string) $request['deadline'] : __( 'مشخص نشده', 'music-wave-core' ) ) );
		if ( (int) $request['user_id'] > 0 ) {
			$user = get_userdata( (int) $request['user_id'] );
			if ( is_object( $user ) && isset( $user->display_name ) ) {
				$this->fact( __( 'حساب کاربری', 'music-wave-core' ), '<a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . (int) $request['user_id'] ) ) . '">' . esc_html( (string) $user->display_name ) . '</a>' );
			}
		}
		echo '</dl></section>';

		echo '<section class="mw-settings__panel mw-requests__workflow"><h2>' . esc_html__( 'گردش کار', 'music-wave-core' ) . '</h2>';
		$this->open_form( 'status', $id );
		echo '<p><label for="mw-status-select">' . esc_html__( 'وضعیت', 'music-wave-core' ) . '</label><select id="mw-status-select" name="mw_status">';
		foreach ( RequestPostType::status_labels() as $status => $label ) {
			echo '<option value="' . esc_attr( $status ) . '"' . selected( (string) $request['status'], $status, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> <button type="submit" class="button">' . esc_html__( 'ذخیره', 'music-wave-core' ) . '</button></p></form>';
		$this->open_form( 'priority', $id );
		$next = 'high' === $request['priority'] ? 'normal' : 'high';
		echo '<input type="hidden" name="mw_priority" value="' . esc_attr( $next ) . '"><p><button type="submit" class="button' . ( 'high' === $request['priority'] ? '' : ' button-secondary' ) . '"><span class="dashicons dashicons-flag" aria-hidden="true"></span> ' . esc_html( 'high' === $request['priority'] ? __( 'برداشتن نشان فوری', 'music-wave-core' ) : __( 'علامت‌گذاری به‌عنوان فوری', 'music-wave-core' ) ) . '</button></p></form>';
		echo '<div class="mw-requests__quick">';
		foreach ( array(
			RequestPostType::STATUS_ACCEPTED => array( __( 'پذیرش همکاری', 'music-wave-core' ), 'button-primary' ),
			RequestPostType::STATUS_DECLINED => array( __( 'رد درخواست', 'music-wave-core' ), '' ),
			RequestPostType::STATUS_ARCHIVED => array( __( 'بایگانی', 'music-wave-core' ), '' ),
		) as $status => $config ) {
			if ( $status === $request['status'] ) {
				continue;
			}
			$this->open_form( 'status', $id, 'mw-requests__inline' );
			echo '<input type="hidden" name="mw_status" value="' . esc_attr( $status ) . '"><button type="submit" class="button ' . esc_attr( (string) $config[1] ) . '">' . esc_html( (string) $config[0] ) . '</button></form>';
		}
		echo '</div></section>';

		echo '<section class="mw-settings__panel mw-requests__edit"><h2>' . esc_html__( 'ویرایش اطلاعات', 'music-wave-core' ) . '</h2>';
		$this->render_fields_form( 'update', $request );
		echo '</section>';

		echo '<section class="mw-settings__panel mw-settings__panel--danger"><h2>' . esc_html__( 'حذف درخواست', 'music-wave-core' ) . '</h2><p class="description">' . esc_html__( 'درخواست، اطلاعات تماس و کل سابقه برای همیشه حذف می‌شود. برای نگهداری بدون نمایش، به‌جای حذف «بایگانی» کنید.', 'music-wave-core' ) . '</p>';
		$this->open_form( 'delete', $id, 'mw-requests__delete' );
		echo '<button type="submit" class="button button-link-delete" data-mw-confirm="delete">' . esc_html__( 'حذف دائمی', 'music-wave-core' ) . '</button></form></section>';
		echo '</aside>';

		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $entry Log entry.
	 */
	private function render_log_entry( array $entry ): void {
		$kind    = isset( $entry['kind'] ) ? (string) $entry['kind'] : RequestRepository::LOG_EVENT;
		$is_user = RequestRepository::LOG_REPLY === $kind && isset( $entry['by'] ) && 'user' === $entry['by'];
		$actor   = isset( $entry['actor'] ) ? (int) $entry['actor'] : 0;
		$time    = isset( $entry['time'] ) ? (int) $entry['time'] : 0;
		$body    = isset( $entry['body'] ) ? (string) $entry['body'] : '';
		$name    = __( 'سیستم', 'music-wave-core' );
		if ( $actor > 0 ) {
			$user = get_userdata( $actor );
			$name = is_object( $user ) && isset( $user->display_name )
				? (string) $user->display_name
				: ( $is_user ? __( 'کاربر', 'music-wave-core' ) : __( 'مدیر', 'music-wave-core' ) );
		}
		$labels      = array(
			RequestRepository::LOG_REPLY => $is_user ? __( 'پیام کاربر در گفتگو', 'music-wave-core' ) : __( 'پاسخ پشتیبانی', 'music-wave-core' ),
			RequestRepository::LOG_NOTE  => __( 'یادداشت داخلی', 'music-wave-core' ),
			RequestRepository::LOG_EVENT => __( 'رویداد', 'music-wave-core' ),
		);
		$entry_class = 'mw-requests__entry mw-requests__entry--' . $kind . ( $is_user ? ' mw-requests__entry--user' : '' );

		echo '<li class="' . esc_attr( $entry_class ) . '"><div class="mw-requests__entry-head"><strong>' . esc_html( isset( $labels[ $kind ] ) ? $labels[ $kind ] : $kind ) . '</strong> <span class="mw-requests__muted">' . esc_html( $name ) . ( $time > 0 ? ' · ' . esc_html( $this->format_time( $time ) ) : '' ) . '</span>';
		if ( RequestRepository::LOG_REPLY === $kind && ! $is_user && isset( $entry['sent'] ) && false === $entry['sent'] ) {
			echo ' <span class="mw-status mw-status--warn">' . esc_html__( 'ایمیل ارسال نشد', 'music-wave-core' ) . '</span>';
		}
		echo '</div><div class="mw-requests__entry-body">' . wp_kses_post( wpautop( esc_html( $body ) ) ) . '</div></li>';
	}

	/**
	 * Canned replies pre-filled with the request context.
	 *
	 * @param array<string, mixed> $request Normalized request.
	 * @return array<string, string>
	 */
	private function reply_templates( array $request ): array {
		$templates = array(
			__( 'دریافت شد', 'music-wave-core' )      => __( 'درخواست شما را با دقت خواندیم و در حال بررسی جزئیات آن هستیم. طی چند روز آینده پیشنهاد و زمان‌بندی دقیق را برایتان می‌فرستیم.', 'music-wave-core' ),
			__( 'نیاز به جزئیات', 'music-wave-core' ) => __( 'برای اینکه بتوانیم پیشنهاد دقیقی بدهیم، لطفاً چند نکته را برایمان روشن کنید: سبک و حال‌وهوای مورد نظر، مدت زمان تقریبی، نمونه‌های الهام‌بخش و مهلت نهایی.', 'music-wave-core' ),
			__( 'پذیرش همکاری', 'music-wave-core' )   => __( 'خوشحالیم که اعلام کنیم می‌توانیم این همکاری را انجام دهیم. در ادامه جزئیات قرارداد، مراحل کار و زمان‌بندی را با هم نهایی می‌کنیم.', 'music-wave-core' ),
			__( 'عدم امکان', 'music-wave-core' )      => __( 'از اعتماد شما سپاسگزاریم. متأسفانه در حال حاضر امکان پذیرش این درخواست را نداریم، اما خوشحال می‌شویم در آینده دوباره با ما در تماس باشید.', 'music-wave-core' ),
		);

		/**
		 * Filter the canned reply templates offered on the request screen.
		 *
		 * @param array<string, string> $templates Label => body.
		 * @param array<string, mixed>  $request   Normalized request.
		 */
		$filtered = apply_filters( 'music_wave_request_reply_templates', $templates, $request );

		return is_array( $filtered ) ? array_map( 'strval', $filtered ) : $templates;
	}

	// ---------------------------------------------------------------------
	// Manual entry / edit
	// ---------------------------------------------------------------------

	private function render_manual_form(): void {
		echo '<p class="mw-requests__back"><a href="' . esc_url( self::url() ) . '">&larr; ' . esc_html__( 'بازگشت به فهرست درخواست‌ها', 'music-wave-core' ) . '</a></p>';
		echo '<section class="mw-settings__panel mw-requests__manual"><h2>' . esc_html__( 'ثبت درخواست دستی', 'music-wave-core' ) . '</h2><p class="description">' . esc_html__( 'برای درخواست‌هایی که تلفنی، حضوری یا از شبکه‌های اجتماعی رسیده‌اند. ایمیل تأیید برای درخواست‌کننده فرستاده نمی‌شود.', 'music-wave-core' ) . '</p>';
		$this->render_fields_form( 'create', null );
		echo '</section>';
	}

	/**
	 * @param array<string, mixed>|null $request Existing request for edits.
	 */
	private function render_fields_form( string $operation, ?array $request ): void {
		$value = static function ( string $key ) use ( $request ): string {
			if ( null === $request || ! isset( $request[ $key ] ) ) {
				return '';
			}

			return is_array( $request[ $key ] ) ? implode( "\n", $request[ $key ] ) : (string) $request[ $key ];
		};

		$this->open_form( $operation, null !== $request ? (int) $request['id'] : 0, 'mw-requests__fields' );
		echo '<div class="mw-requests__fields-grid">';
		$this->field_input( 'name', __( 'نام', 'music-wave-core' ), $value( 'name' ), 'text', true );
		$this->field_input( 'email', __( 'ایمیل', 'music-wave-core' ), $value( 'email' ), 'email', true );
		$this->field_input( 'phone', __( 'تلفن', 'music-wave-core' ), $value( 'phone' ), 'tel', false );
		$this->field_select( 'role', __( 'نقش', 'music-wave-core' ), RequestPostType::role_labels(), $value( 'role' ) );
		$this->field_select( 'type', __( 'نوع درخواست', 'music-wave-core' ), RequestPostType::type_labels(), $value( 'type' ) );
		$this->field_select( 'budget', __( 'بودجه', 'music-wave-core' ), RequestPostType::budget_labels(), $value( 'budget' ) );
		$this->field_input( 'deadline', __( 'زمان مورد نظر (سال-ماه-روز)', 'music-wave-core' ), $value( 'deadline' ), 'date', false );
		$this->field_input( 'subject', __( 'عنوان', 'music-wave-core' ), $value( 'subject' ), 'text', true, true );
		echo '</div>';
		echo '<p><label for="mw-field-message">' . esc_html__( 'توضیحات', 'music-wave-core' ) . ' <span class="required">*</span></label><textarea id="mw-field-message" name="mw_field_message" rows="6" class="large-text" required>' . esc_textarea( $value( 'message' ) ) . '</textarea></p>';
		echo '<p><label for="mw-field-links">' . esc_html__( 'پیوندها (هر خط یک نشانی)', 'music-wave-core' ) . '</label><textarea id="mw-field-links" name="mw_field_links" rows="2" class="large-text" dir="ltr">' . esc_textarea( $value( 'links' ) ) . '</textarea></p>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html( 'create' === $operation ? __( 'ثبت درخواست', 'music-wave-core' ) : __( 'ذخیرهٔ تغییرات', 'music-wave-core' ) ) . '</button></p></form>';
	}

	private function field_input( string $key, string $label, string $value, string $type, bool $required, bool $wide = false ): void {
		echo '<p class="mw-requests__field' . ( $wide ? ' mw-requests__field--wide' : '' ) . '"><label for="mw-field-' . esc_attr( $key ) . '">' . esc_html( $label ) . ( $required ? ' <span class="required">*</span>' : '' ) . '</label><input type="' . esc_attr( $type ) . '" id="mw-field-' . esc_attr( $key ) . '" name="mw_field_' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" class="regular-text"' . ( $required ? ' required' : '' ) . ( in_array( $type, array( 'email', 'tel', 'date' ), true ) ? ' dir="ltr"' : '' ) . '></p>';
	}

	/**
	 * @param array<string, string> $options Options.
	 */
	private function field_select( string $key, string $label, array $options, string $value ): void {
		echo '<p class="mw-requests__field"><label for="mw-field-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><select id="mw-field-' . esc_attr( $key ) . '" name="mw_field_' . esc_attr( $key ) . '">';
		foreach ( $options as $option => $option_label ) {
			echo '<option value="' . esc_attr( (string) $option ) . '"' . selected( $value, (string) $option, false ) . '>' . esc_html( $option_label ) . '</option>';
		}
		echo '</select></p>';
	}

	// ---------------------------------------------------------------------
	// Settings
	// ---------------------------------------------------------------------

	private function render_settings(): void {
		$settings = RequestSettings::all();
		echo '<p class="mw-requests__back"><a href="' . esc_url( self::url() ) . '">&larr; ' . esc_html__( 'بازگشت به فهرست درخواست‌ها', 'music-wave-core' ) . '</a></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '" class="mw-requests__settings">';
		settings_fields( 'music_wave_requests' );
		echo '<input type="hidden" name="_wp_http_referer" value="' . esc_attr( add_query_arg( self::NOTICE_ARG, 'settings-saved', self::url( array( 'view' => 'settings' ) ) ) ) . '">';

		echo '<section class="mw-settings__panel"><h2>' . esc_html__( 'فرم عمومی', 'music-wave-core' ) . '</h2><table class="form-table" role="presentation">';
		$this->toggle_row( 'form_enabled', __( 'پذیرش درخواست', 'music-wave-core' ), (string) $settings['form_enabled'], __( 'با غیرفعال کردن، فرم عمومی پیام «در حال حاضر درخواست جدیدی پذیرفته نمی‌شود» را نشان می‌دهد.', 'music-wave-core' ) );
		echo '<tr><th scope="row">' . esc_html__( 'انواع درخواست فعال', 'music-wave-core' ) . '</th><td><fieldset>';
		foreach ( RequestPostType::type_labels() as $type => $label ) {
			echo '<label class="mw-requests__check"><input type="checkbox" name="' . esc_attr( RequestSettings::OPTION ) . '[enabled_types][]" value="' . esc_attr( $type ) . '"' . checked( in_array( $type, (array) $settings['enabled_types'], true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo '</fieldset></td></tr>';
		$this->number_row( 'rate_limit', __( 'حداکثر ارسال هر بازدیدکننده', 'music-wave-core' ), (int) $settings['rate_limit'], 1, 100, __( 'تعداد فرم‌هایی که یک بازدیدکننده می‌تواند در بازهٔ زیر بفرستد.', 'music-wave-core' ) );
		$this->number_row( 'rate_window', __( 'بازهٔ محدودیت (ثانیه)', 'music-wave-core' ), (int) $settings['rate_window'], 60, 86400, __( 'پیش‌فرض ۳۶۰۰ ثانیه (یک ساعت).', 'music-wave-core' ) );
		$this->textarea_row( 'success_notice', __( 'پیام موفقیت سفارشی', 'music-wave-core' ), (string) $settings['success_notice'], __( 'خالی بگذارید تا پیام پیش‌فرض نمایش داده شود.', 'music-wave-core' ) );
		echo '</table></section>';

		echo '<section class="mw-settings__panel"><h2>' . esc_html__( 'گفتگو و چت', 'music-wave-core' ) . '</h2><table class="form-table" role="presentation">';
		$this->toggle_row( 'chat_enabled', __( 'چت و گفتگو با کاربر', 'music-wave-core' ), (string) ( $settings['chat_enabled'] ?? 'enabled' ), __( 'اگر فعال باشد، کاربر در تب پیگیری درخواست‌ها می‌تواند پاسخ داده شده را ببیند و به گفتگو ادامه دهد.', 'music-wave-core' ) );
		echo '</table></section>';

		echo '<section class="mw-settings__panel"><h2>' . esc_html__( 'اعلان‌ها و ایمیل', 'music-wave-core' ) . '</h2><table class="form-table" role="presentation">';
		$this->textarea_row( 'recipients', __( 'گیرندگان اعلان درخواست جدید', 'music-wave-core' ), (string) $settings['recipients'], __( 'نشانی‌ها را با ویرگول یا خط جدید جدا کنید. خالی = ایمیل مدیر سایت.', 'music-wave-core' ), 2 );
		echo '<tr><th scope="row"><label for="mw-reply_to">' . esc_html__( 'نشانی پاسخ (Reply-To)', 'music-wave-core' ) . '</label></th><td><input type="email" id="mw-reply_to" name="' . esc_attr( RequestSettings::OPTION ) . '[reply_to]" value="' . esc_attr( (string) $settings['reply_to'] ) . '" class="regular-text" dir="ltr"><p class="description">' . esc_html__( 'وقتی درخواست‌کننده به ایمیل‌های شما پاسخ می‌دهد به این نشانی می‌رسد. خالی = ایمیل مدیر سایت.', 'music-wave-core' ) . '</p></td></tr>';
		$this->toggle_row( 'send_receipts', __( 'ایمیل تأیید برای درخواست‌کننده', 'music-wave-core' ), (string) $settings['send_receipts'], __( 'بلافاصله پس از ثبت، شمارهٔ پیگیری و خلاصهٔ درخواست ایمیل می‌شود.', 'music-wave-core' ) );
		$this->textarea_row( 'receipt_intro', __( 'متن اضافی ایمیل تأیید', 'music-wave-core' ), (string) $settings['receipt_intro'], __( 'مثلاً زمان معمول پاسخ‌گویی یا راه‌های تماس دیگر.', 'music-wave-core' ) );
		$this->textarea_row( 'signature', __( 'امضای ایمیل‌ها', 'music-wave-core' ), (string) $settings['signature'], __( 'خالی = «با احترام، تیم [نام سایت]».', 'music-wave-core' ), 2 );
		echo '</table></section>';

		submit_button( __( 'ذخیرهٔ تنظیمات', 'music-wave-core' ) );
		echo '</form>';
	}

	private function toggle_row( string $key, string $label, string $value, string $description ): void {
		echo '<tr><th scope="row"><label for="mw-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><select id="mw-' . esc_attr( $key ) . '" name="' . esc_attr( RequestSettings::OPTION ) . '[' . esc_attr( $key ) . ']"><option value="enabled"' . selected( $value, 'enabled', false ) . '>' . esc_html__( 'فعال', 'music-wave-core' ) . '</option><option value="disabled"' . selected( $value, 'disabled', false ) . '>' . esc_html__( 'غیرفعال', 'music-wave-core' ) . '</option></select><p class="description">' . esc_html( $description ) . '</p></td></tr>';
	}

	private function number_row( string $key, string $label, int $value, int $min, int $max, string $description ): void {
		echo '<tr><th scope="row"><label for="mw-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input id="mw-' . esc_attr( $key ) . '" name="' . esc_attr( RequestSettings::OPTION ) . '[' . esc_attr( $key ) . ']" type="number" min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '" value="' . esc_attr( (string) $value ) . '"><p class="description">' . esc_html( $description ) . '</p></td></tr>';
	}

	private function textarea_row( string $key, string $label, string $value, string $description, int $rows = 3 ): void {
		echo '<tr><th scope="row"><label for="mw-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><textarea id="mw-' . esc_attr( $key ) . '" name="' . esc_attr( RequestSettings::OPTION ) . '[' . esc_attr( $key ) . ']" rows="' . (int) $rows . '" class="large-text">' . esc_textarea( $value ) . '</textarea><p class="description">' . esc_html( $description ) . '</p></td></tr>';
	}

	// ---------------------------------------------------------------------
	// Shared helpers
	// ---------------------------------------------------------------------

	private function open_form( string $operation, int $request_id, string $form_class = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( '' !== $form_class ? ' class="' . esc_attr( $form_class ) . '"' : '' ) . '>';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="mw_operation" value="' . esc_attr( $operation ) . '"><input type="hidden" name="mw_request_id" value="' . (int) $request_id . '"><input type="hidden" name="mw_return" value="' . esc_attr( $this->return_url() ) . '">';
	}

	private function render_notice(): void {
		$notice = $this->query_key( self::NOTICE_ARG );
		if ( '' === $notice && isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag.
			$notice = 'settings-saved';
		}
		$message = '' !== $notice ? $this->notice_message( $notice ) : '';
		if ( '' === $message ) {
			return;
		}
		echo '<div class="notice ' . ( $this->notice_is_error( $notice ) ? 'notice-error' : 'notice-success' ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	private function stat( string $icon, string $value, string $label ): void {
		echo '<div class="mw-stat"><span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span><div><strong>' . esc_html( $value ) . '</strong><span>' . esc_html( $label ) . '</span></div></div>';
	}

	private function fact( string $label, string $value_html ): void {
		echo '<dt>' . esc_html( $label ) . '</dt><dd>' . $value_html . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- callers pass escaped markup.
	}

	private function status_badge( string $status ): string {
		$classes = array(
			RequestPostType::STATUS_NEW      => 'mw-status--attention',
			RequestPostType::STATUS_REVIEW   => 'mw-status--warn',
			RequestPostType::STATUS_REPLIED  => 'mw-status--info',
			RequestPostType::STATUS_ACCEPTED => 'mw-status--active',
			RequestPostType::STATUS_DECLINED => 'mw-status--inactive',
			RequestPostType::STATUS_ARCHIVED => 'mw-status--inactive',
		);
		$labels  = RequestPostType::status_labels();

		return '<span class="mw-status ' . esc_attr( isset( $classes[ $status ] ) ? $classes[ $status ] : 'mw-status--inactive' ) . '">' . esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : $status ) . '</span>';
	}

	/**
	 * @param array<string, string> $labels Label map.
	 */
	private function label( array $labels, string $key ): string {
		return isset( $labels[ $key ] ) ? $labels[ $key ] : ( '' !== $key ? $key : '—' );
	}

	private function excerpt( string $text ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		return mb_strlen( $text ) > 120 ? mb_substr( $text, 0, 120 ) . '…' : $text;
	}

	private function initial( string $name ): string {
		$name = trim( $name );

		if ( '' === $name ) {
			return '?';
		}
		$initial = mb_substr( $name, 0, 1 );

		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $initial, 'UTF-8' ) : strtoupper( $initial );
	}

	private function date( int $post_id, bool $with_time = false ): string {
		$format = $with_time ? get_option( 'date_format', 'Y/m/d' ) . ' ' . get_option( 'time_format', 'H:i' ) : get_option( 'date_format', 'Y/m/d' );
		$date   = get_the_date( $format, $post_id );

		return is_string( $date ) ? $date : '';
	}

	private function format_time( int $timestamp ): string {
		if ( function_exists( 'wp_date' ) ) {
			return (string) wp_date( get_option( 'date_format', 'Y/m/d' ) . ' ' . get_option( 'time_format', 'H:i' ), $timestamp );
		}

		return gmdate( 'Y-m-d H:i', $timestamp );
	}

	private function current_admin_url(): string {
		$request = isset( $_SERVER['REQUEST_URI'] ) && is_scalar( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $request ) {
			return self::url();
		}
		$path = (string) wp_parse_url( $request, PHP_URL_PATH );
		$args = array();
		wp_parse_str( (string) wp_parse_url( $request, PHP_URL_QUERY ), $args );
		unset( $args[ self::NOTICE_ARG ] );

		return add_query_arg( array_map( 'strval', array_filter( $args, 'is_scalar' ) ), admin_url( basename( $path ) ) );
	}

	/**
	 * Where a completed action returns: the list the manager came from.
	 */
	private function return_url(): string {
		$return = isset( $_POST['mw_return'] ) && is_scalar( $_POST['mw_return'] ) ? esc_url_raw( wp_unslash( (string) $_POST['mw_return'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- destination only; wp_safe_redirect() restricts the host.
		if ( '' !== $return && false !== strpos( $return, 'page=' . self::PAGE ) ) {
			return remove_query_arg( array( 'request', 'view', self::NOTICE_ARG ), $return );
		}
		if ( isset( $_GET['page'] ) && isset( $_SERVER['REQUEST_URI'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return remove_query_arg( array( 'request', 'view', self::NOTICE_ARG ), $this->current_admin_url() );
		}

		return self::url();
	}

	private function query_key( string $key ): string {
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_key( wp_unslash( (string) $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
	}

	private function query_text( string $key ): string {
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
	}

	private function query_int( string $key ): int {
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? absint( wp_unslash( (string) $_GET[ $key ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
	}

	private function post_key( string $key ): string {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? sanitize_key( wp_unslash( (string) $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() runs first.
	}

	private function post_text( string $key, bool $multiline = false ): string {
		if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return '';
		}
		$raw = wp_unslash( (string) $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.

		return $multiline ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
	}

	/**
	 * Contextual help for the screen (runs on load-{hook}).
	 *
	 * @return void
	 */
	public function register_help_tabs(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! is_object( $screen ) || ! method_exists( $screen, 'add_help_tab' ) ) {
			return;
		}
		$screen->add_help_tab(
			array(
				'id'      => 'music-wave-requests-guide',
				'title'   => __( 'گردش کار درخواست‌ها', 'music-wave-core' ),
				'content' =>
					'<p>' . esc_html__( 'هر درخواست از «جدید» شروع می‌شود. با ارسال پاسخ به «پاسخ داده شده» می‌رود و شما آن را به «پذیرفته شده»، «رد شده» یا «بایگانی» می‌برید.', 'music-wave-core' ) . '</p>' .
					'<ul><li>' . esc_html__( 'پاسخ: برای درخواست‌کننده ایمیل می‌شود و در سابقه می‌ماند.', 'music-wave-core' ) . '</li>' .
					'<li>' . esc_html__( 'یادداشت داخلی: فقط تیم شما می‌بیند.', 'music-wave-core' ) . '</li>' .
					'<li>' . esc_html__( 'فوری: درخواست را در فهرست برجسته می‌کند و با فیلتر اولویت پیدا می‌شود.', 'music-wave-core' ) . '</li>' .
					'<li>' . esc_html__( 'حذف دائمی برگشت‌ناپذیر است؛ برای نگهداری بدون نمایش، بایگانی کنید.', 'music-wave-core' ) . '</li></ul>',
			)
		);
	}

	/**
	 * @return array{notice: string, request_id: int}
	 */
	private function result( string $notice, int $request_id ): array {
		return array(
			'notice'     => $notice,
			'request_id' => $request_id,
		);
	}
}
