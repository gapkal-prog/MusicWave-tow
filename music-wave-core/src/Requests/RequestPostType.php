<?php
/**
 * Storage model for custom-song requests and collaboration proposals.
 *
 * Requests are a private custom post type (`mw_request`): never public,
 * never queryable, never exposed through REST or the standard edit screens.
 * Their lifecycle is a set of registered post statuses so the admin list can
 * filter on the indexed `post_status` column instead of meta lookups.
 *
 * Access is a single meta capability ({@see self::MANAGE_CAP}) mapped to
 * `manage_options` by default and filterable per site, mirroring the
 * protected-asset capability pattern in Modules\Foundation.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Requests;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RequestPostType {
	public const KEY = 'mw_request';

	/** Meta capability guarding every request management action. */
	public const MANAGE_CAP = 'manage_mw_requests';

	public const STATUS_NEW      = 'mw_req_new';
	public const STATUS_REVIEW   = 'mw_req_review';
	public const STATUS_REPLIED  = 'mw_req_replied';
	public const STATUS_ACCEPTED = 'mw_req_accepted';
	public const STATUS_DECLINED = 'mw_req_declined';
	public const STATUS_ARCHIVED = 'mw_req_archived';

	/**
	 * Register the post type, its statuses and the capability mapping.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ), 5 );
		add_action( 'init', array( $this, 'register_statuses' ), 5 );
		add_filter( 'map_meta_cap', array( $this, 'map_capability' ), 10, 2 );
	}

	/**
	 * Register the private post type.
	 *
	 * @return void
	 */
	public function register_post_type(): void {
		if ( ! function_exists( 'register_post_type' ) ) {
			return;
		}

		$capabilities = array();
		foreach ( array( 'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts', 'create_posts' ) as $primitive ) {
			$capabilities[ $primitive ] = self::MANAGE_CAP;
		}

		register_post_type(
			self::KEY,
			array(
				'labels'              => array(
					'name'          => _x( 'درخواست‌های همکاری', 'post type general name', 'music-wave-core' ),
					'singular_name' => _x( 'درخواست', 'post type singular name', 'music-wave-core' ),
				),
				'description'         => __( 'درخواست آهنگ سفارشی و پیشنهاد همکاری که از فرم عمومی سایت ثبت می‌شود.', 'music-wave-core' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'hierarchical'        => false,
				'supports'            => array( 'title' ),
				'can_export'          => true,
				'delete_with_user'    => false,
				'capability_type'     => array( 'mw_request', 'mw_requests' ),
				'capabilities'        => $capabilities,
				'map_meta_cap'        => false,
			)
		);
	}

	/**
	 * Register the request lifecycle statuses.
	 *
	 * @return void
	 */
	public function register_statuses(): void {
		if ( ! function_exists( 'register_post_status' ) ) {
			return;
		}

		foreach ( self::status_labels() as $status => $label ) {
			register_post_status(
				$status,
				array(
					'label'                     => $label,
					'public'                    => false,
					'internal'                  => true,
					'protected'                 => true,
					'exclude_from_search'       => true,
					'show_in_admin_all_list'    => false,
					'show_in_admin_status_list' => false,
				)
			);
		}
	}

	/**
	 * Resolve the request capability to the site's chosen primitives.
	 *
	 * @param mixed $caps Required primitive capabilities.
	 * @param mixed $cap  Capability being checked.
	 * @return mixed
	 */
	public function map_capability( $caps, $cap ) {
		if ( self::MANAGE_CAP !== $cap ) {
			return $caps;
		}

		/**
		 * Filter the primitive capabilities that grant request management.
		 *
		 * Return e.g. `array( 'edit_others_posts' )` to let editors handle
		 * collaboration requests without full administrator rights.
		 *
		 * @param array<int, string> $required Primitive capabilities.
		 */
		$required = apply_filters( 'music_wave_manage_request_caps', array( 'manage_options' ) );

		return is_array( $required ) && array() !== $required ? array_values( $required ) : array( 'manage_options' );
	}

	/**
	 * Every lifecycle status with its translated label, in workflow order.
	 *
	 * @return array<string, string>
	 */
	public static function status_labels(): array {
		return array(
			self::STATUS_NEW      => __( 'جدید', 'music-wave-core' ),
			self::STATUS_REVIEW   => __( 'در حال بررسی', 'music-wave-core' ),
			self::STATUS_REPLIED  => __( 'پاسخ داده شده', 'music-wave-core' ),
			self::STATUS_ACCEPTED => __( 'پذیرفته شده', 'music-wave-core' ),
			self::STATUS_DECLINED => __( 'رد شده', 'music-wave-core' ),
			self::STATUS_ARCHIVED => __( 'بایگانی', 'music-wave-core' ),
		);
	}

	/**
	 * Registered status keys.
	 *
	 * @return array<int, string>
	 */
	public static function statuses(): array {
		return array_keys( self::status_labels() );
	}

	/**
	 * Statuses that count as "open" work for the admin menu badge.
	 *
	 * @return array<int, string>
	 */
	public static function open_statuses(): array {
		return array( self::STATUS_NEW );
	}

	/**
	 * Whether a status key is one of ours.
	 */
	public static function is_status( string $status ): bool {
		return in_array( $status, self::statuses(), true );
	}

	/**
	 * Request kinds offered on the public form.
	 *
	 * @return array<string, string>
	 */
	public static function type_labels(): array {
		return array(
			'song'        => __( 'سفارش آهنگ اختصاصی', 'music-wave-core' ),
			'collab'      => __( 'همکاری هنری', 'music-wave-core' ),
			'advertising' => __( 'تبلیغات و برند', 'music-wave-core' ),
			'event'       => __( 'اجرا و رویداد', 'music-wave-core' ),
			'other'       => __( 'موضوع دیگر', 'music-wave-core' ),
		);
	}

	/**
	 * Who the requester is; drives the copy and the admin filters.
	 *
	 * @return array<string, string>
	 */
	public static function role_labels(): array {
		return array(
			'singer'     => __( 'خواننده', 'music-wave-core' ),
			'producer'   => __( 'تهیه‌کننده / آهنگساز', 'music-wave-core' ),
			'band'       => __( 'گروه موسیقی', 'music-wave-core' ),
			'advertiser' => __( 'برند / آژانس تبلیغاتی', 'music-wave-core' ),
			'business'   => __( 'کسب‌وکار / رویداد', 'music-wave-core' ),
			'fan'        => __( 'شنونده و علاقه‌مند', 'music-wave-core' ),
			'other'      => __( 'سایر', 'music-wave-core' ),
		);
	}

	/**
	 * Budget bands; kept coarse on purpose so the form never asks for figures.
	 *
	 * @return array<string, string>
	 */
	public static function budget_labels(): array {
		return array(
			''           => __( 'هنوز مشخص نیست', 'music-wave-core' ),
			'starter'    => __( 'اقتصادی', 'music-wave-core' ),
			'standard'   => __( 'استاندارد', 'music-wave-core' ),
			'premium'    => __( 'حرفه‌ای', 'music-wave-core' ),
			'enterprise' => __( 'سازمانی / ویژه', 'music-wave-core' ),
		);
	}

	/**
	 * Priority flags settable by managers.
	 *
	 * @return array<string, string>
	 */
	public static function priority_labels(): array {
		return array(
			'normal' => __( 'عادی', 'music-wave-core' ),
			'high'   => __( 'فوری', 'music-wave-core' ),
		);
	}
}
