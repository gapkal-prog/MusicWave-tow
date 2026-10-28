<?php
/**
 * Public "custom song & collaboration request" form block.
 *
 * Pure single-responsibility form block: renders ONLY the progressive HTML form
 * (posting securely to `admin-post.php` without JavaScript, protected by dynamic
 * kind-bound nonces, timing guard, honeypot, and one-shot error/value stashing).
 *
 * Surrounding page headers, service callouts, step lists, and multi-column
 * layouts are composed modularly using native WordPress Gutenberg blocks
 * (`core/group`, `core/columns`, `core/heading`, `core/paragraph`, `core/list`).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks;

use ManaCore\MusicWave\Core\Requests\RequestFormHandler;
use ManaCore\MusicWave\Core\Requests\RequestPostType;
use ManaCore\MusicWave\Core\Requests\RequestSettings;
use ManaCore\MusicWave\Core\Requests\RequestSubmission;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RequestFormBlock {
	public const NAME = 'music-wave/request-form';

	/** Shared handle for the public stylesheet and the enhancement script. */
	public const ASSET_HANDLE = 'music-wave-request-form';

	/** Block modes and the request kinds each one accepts. */
	private const MODES = array(
		'both'   => array(),
		'song'   => array( 'song' ),
		'collab' => array( 'collab', 'advertising', 'event' ),
	);

	/** Default role chip per mode. */
	private const DEFAULT_ROLES = array(
		'both'   => 'singer',
		'song'   => 'singer',
		'collab' => 'producer',
	);

	/** @var RequestFormHandler */
	private $forms;

	/** @var RequestSettings */
	private $settings;

	public function __construct( RequestFormHandler $forms, ?RequestSettings $settings = null ) {
		$this->forms    = $forms;
		$this->settings = null !== $settings ? $settings : new RequestSettings();
	}

	/**
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$this->register_assets();

		BlockSupport::register_dynamic(
			self::NAME,
			function ( $attributes ): string {
				return $this->render( is_array( $attributes ) ? $attributes : array() );
			},
			array(
				'api_version' => 3,
				'supports'    => BlockSupport::appearance_tools(),
			)
		);
	}

	/**
	 * Supported block modes.
	 *
	 * @return array<int, string>
	 */
	public static function modes(): array {
		return array_keys( self::MODES );
	}

	/**
	 * Request kinds a mode may offer, ordered like the manager sees them.
	 *
	 * @return array<string, string> Kind => label.
	 */
	public function kinds_for_mode( string $mode ): array {
		$enabled = $this->settings->enabled_types();
		$allowed = isset( self::MODES[ $mode ] ) ? self::MODES[ $mode ] : array();
		if ( array() === $allowed ) {
			return $enabled;
		}

		return array_intersect_key( $enabled, array_flip( $allowed ) );
	}

	/**
	 * Render the pure request form block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render( array $attributes ): string {
		$mode   = BlockSupport::key_attribute( $attributes, 'mode', self::modes(), 'both' );
		$notice = $this->requested_notice();
		$stash  = $this->forms->stashed( $this->requested_token() );
		$errors = $stash['errors'];
		$values = $stash['values'];
		$open   = $this->settings->form_enabled() && array() !== $this->kinds_for_mode( $mode );

		$class = 'mw-request-form mw-request-form--mode-' . $mode;

		$html  = '<section ' . BlockSupport::wrapper_attributes( $class ) . ' id="mw-request-form" data-mw-request-mode="' . esc_attr( $mode ) . '">';
		$html .= '<div class="mw-request-form__panel">';
		$html .= $this->notice_markup( $notice );
		if ( 'sent' === $notice ) {
			$html .= $this->success_markup();
		} elseif ( ! $open ) {
			$html .= '<p class="mw-request-form__closed" role="status">' . esc_html( $this->forms->notice_message( 'closed' ) ) . '</p>';
		} else {
			$html .= $this->form_markup( $attributes, $mode, $errors, $values );
		}
		$html .= '</div></section>';

		$this->enqueue_assets();

		return $html;
	}

	/**
	 * Default submit button label per mode.
	 */
	private function default_submit_label( string $mode ): string {
		switch ( $mode ) {
			case 'song':
				return __( 'ثبت سفارش آهنگ', 'music-wave-core' );
			case 'collab':
				return __( 'ارسال پیشنهاد همکاری', 'music-wave-core' );
		}

		return __( 'ارسال درخواست', 'music-wave-core' );
	}

	/**
	 * Render `<form>` markup.
	 *
	 * @param array<string, mixed>  $attributes Block attributes.
	 * @param array<string, string> $errors     Field errors.
	 * @param array<string, mixed>  $values     Previous values.
	 */
	private function form_markup( array $attributes, string $mode, array $errors, array $values ): string {
		$value = static function ( string $key ) use ( $values ): string {
			if ( ! isset( $values[ $key ] ) ) {
				return '';
			}

			return is_array( $values[ $key ] ) ? implode( "\n", array_map( 'strval', $values[ $key ] ) ) : (string) $values[ $key ];
		};

		// Smart prefilling: if user is logged in, use their real profile and lock fields.
		$current_user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		$is_logged_in    = $current_user_id > 0 && function_exists( 'is_user_logged_in' ) && is_user_logged_in();
		$profile         = array(
			'name'  => '',
			'email' => '',
			'phone' => '',
		);
		if ( $is_logged_in ) {
			$user = function_exists( 'get_user_by' ) ? get_user_by( 'id', $current_user_id ) : null;
			if ( $user ) {
				$profile['name']  = '' !== $user->display_name ? $user->display_name : $user->user_login;
				$profile['email'] = strtolower( (string) $user->user_email );
				if ( function_exists( 'get_user_meta' ) ) {
					foreach ( array( 'billing_phone', '_billing_phone', 'phone', 'mw_phone' ) as $meta_key ) {
						$phone_candidate = get_user_meta( $current_user_id, $meta_key, true );
						if ( is_string( $phone_candidate ) && '' !== trim( $phone_candidate ) ) {
							$profile['phone'] = trim( $phone_candidate );
							break;
						}
					}
				}
				$values['name']  = $profile['name'];
				$values['email'] = $profile['email'];
				if ( '' !== $profile['phone'] ) {
					$values['phone'] = $profile['phone'];
				}
			}
		}

		$submit     = BlockSupport::text_attribute( $attributes, 'submitLabel', $this->default_submit_label( $mode ) );
		$types      = $this->kinds_for_mode( $mode );
		$type_keys  = array_map( 'strval', array_keys( $types ) );
		$default    = BlockSupport::key_attribute( $attributes, 'defaultType', $type_keys, (string) $type_keys[0] );
		$type       = in_array( $value( 'type' ), $type_keys, true ) ? $value( 'type' ) : $default;
		$show_chips = count( $types ) > 1;
		$role_keys  = array_map( 'strval', array_keys( RequestPostType::role_labels() ) );
		$role_start = BlockSupport::key_attribute( $attributes, 'defaultRole', $role_keys, self::DEFAULT_ROLES[ $mode ] );
		$role       = in_array( $value( 'role' ), $role_keys, true ) ? $value( 'role' ) : $role_start;

		$html  = '<form class="mw-request-form__form' . ( $is_logged_in ? ' mw-request-form__form--authed' : '' ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" novalidate data-mw-request-form>';
		$html .= wp_nonce_field( RequestFormHandler::nonce_action( $type_keys ), '_wpnonce', true, false );
		$html .= '<input type="hidden" name="action" value="' . esc_attr( RequestFormHandler::ACTION ) . '">';
		$html .= '<input type="hidden" name="mw_redirect" value="' . esc_attr( BlockSupport::current_url() ) . '">';
		$html .= '<input type="hidden" name="' . esc_attr( RequestFormHandler::TIMER ) . '" value="' . esc_attr( (string) time() ) . '">';
		$html .= '<input type="hidden" name="' . esc_attr( RequestFormHandler::KINDS ) . '" value="' . esc_attr( implode( ',', $type_keys ) ) . '">';
		$html .= '<div class="mw-request-form__hp" aria-hidden="true"><label for="mw-request-website">' . esc_html__( 'وب‌سایت', 'music-wave-core' ) . '</label><input type="text" id="mw-request-website" name="' . esc_attr( RequestFormHandler::HONEYPOT ) . '" value="" tabindex="-1" autocomplete="off"></div>';

		// Step 1: what.
		$legend = 'song' === $mode ? __( 'چه آهنگی می‌خواهید؟', 'music-wave-core' ) : ( 'collab' === $mode ? __( 'پیشنهاد شما چیست؟', 'music-wave-core' ) : __( 'چه چیزی می‌خواهید؟', 'music-wave-core' ) );
		$html  .= '<fieldset class="mw-request-form__group"><legend class="mw-request-form__legend"><span class="mw-request-form__legend-index" aria-hidden="true">۱</span>' . esc_html( $legend ) . '</legend>';
		if ( $show_chips ) {
			$html .= '<div class="mw-request-form__chips" role="radiogroup" aria-label="' . esc_attr__( 'نوع درخواست', 'music-wave-core' ) . '">';
			foreach ( $types as $key => $label ) {
				$html .= '<label class="mw-request-form__chip"><input type="radio" name="type" value="' . esc_attr( (string) $key ) . '"' . checked( $type, (string) $key, false ) . '><span>' . esc_html( $label ) . '</span></label>';
			}
			$html .= '</div>';
		} else {
			$html .= '<input type="hidden" name="type" value="' . esc_attr( $type ) . '">';
			if ( count( $types ) > 1 ) {
				$html .= '<p class="mw-request-form__kind">' . esc_html( $types[ $type ] ) . '</p>';
			}
		}
		if ( isset( $errors['type'] ) ) {
			$html .= '<span class="mw-request-form__error" id="mw-request-type-error">' . esc_html( $errors['type'] ) . '</span>';
		}
		$subject_hint = 'collab' === $mode ? __( 'مثلاً: فیچرینگ برای تک‌آهنگ بعدی', 'music-wave-core' ) : __( 'مثلاً: قطعهٔ پاپ برای تیزر تبلیغاتی', 'music-wave-core' );
		$html        .= $this->field( 'subject', __( 'عنوان درخواست', 'music-wave-core' ), '<input type="text" id="mw-request-subject" name="subject" value="' . esc_attr( $value( 'subject' ) ) . '" maxlength="' . (int) RequestSubmission::MAX_SUBJECT . '" required placeholder="' . esc_attr( $subject_hint ) . '"' . $this->described( 'subject', $errors ) . '>', $errors );
		$html        .= $this->field( 'message', __( 'توضیحات', 'music-wave-core' ), '<textarea id="mw-request-message" name="message" rows="6" minlength="' . (int) RequestSubmission::MIN_MESSAGE . '" maxlength="' . (int) RequestSubmission::MAX_MESSAGE . '" required placeholder="' . esc_attr__( 'سبک و حال‌وهوا، مدت زمان، نمونه‌های الهام‌بخش، محل استفاده…', 'music-wave-core' ) . '" data-mw-counter dir="auto"' . $this->described( 'message', $errors ) . '>' . esc_textarea( $value( 'message' ) ) . '</textarea><span class="mw-request-form__counter" data-mw-counter-output aria-live="polite"></span>', $errors, __( 'هرچه دقیق‌تر بنویسید، پاسخ سریع‌تر و دقیق‌تری می‌گیرید.', 'music-wave-core' ) );
		$optional     = array();
		if ( BlockSupport::bool_attribute( $attributes, 'showBudget', true ) ) {
			$options = '';
			foreach ( RequestPostType::budget_labels() as $key => $label ) {
				$options .= '<option value="' . esc_attr( (string) $key ) . '"' . selected( $value( 'budget' ), (string) $key, false ) . '>' . esc_html( $label ) . '</option>';
			}
			$optional[] = $this->field( 'budget', __( 'بودجهٔ تقریبی', 'music-wave-core' ), '<select id="mw-request-budget" name="budget">' . $options . '</select>', $errors );
		}
		if ( BlockSupport::bool_attribute( $attributes, 'showDeadline', true ) ) {
			$optional[] = $this->field( 'deadline', __( 'زمان مورد نظر', 'music-wave-core' ), '<input type="date" id="mw-request-deadline" name="deadline" value="' . esc_attr( $value( 'deadline' ) ) . '" dir="ltr"' . $this->described( 'deadline', $errors ) . '>', $errors );
		}
		if ( array() !== $optional ) {
			$html .= '<div class="mw-request-form__row">' . implode( '', $optional ) . '</div>';
		}
		if ( BlockSupport::bool_attribute( $attributes, 'showLinks', true ) ) {
			$html .= $this->field( 'links', __( 'نمونه‌کار یا پیوندهای مرتبط', 'music-wave-core' ), '<textarea id="mw-request-links" name="links" rows="2" dir="ltr" placeholder="https://…"' . $this->described( 'links', $errors ) . '>' . esc_textarea( $value( 'links' ) ) . '</textarea>', $errors, __( 'اختیاری؛ هر پیوند در یک خط (حداکثر ۵).', 'music-wave-core' ) );
		}
		$html .= '</fieldset>';

		// Step 2: who – smart locked for logged-in users.
		$html .= '<fieldset class="mw-request-form__group' . ( $is_logged_in ? ' mw-request-form__group--locked' : '' ) . '"><legend class="mw-request-form__legend"><span class="mw-request-form__legend-index" aria-hidden="true">۲</span>' . esc_html__( 'شما که هستید؟', 'music-wave-core' ) . '</legend>';
		if ( $is_logged_in ) {
			/* translators: %s: value 1. */
			$html .= '<p class="mw-request-form__authed-notice"><span class="mw-request-form__authed-dot" aria-hidden="true"></span>' . esc_html( sprintf( __( 'وارد شده به عنوان %s – اطلاعات از حساب شما پر شد و قابل ویرایش نیست.', 'music-wave-core' ), $profile['name'] ) ) . ' <a href="' . esc_url( wp_logout_url( BlockSupport::current_url() ) ) . '">' . esc_html__( 'خروج', 'music-wave-core' ) . '</a></p>';
		}
		if ( BlockSupport::bool_attribute( $attributes, 'showRoles', true ) ) {
			$html .= '<div class="mw-request-form__chips mw-request-form__chips--soft" role="radiogroup" aria-label="' . esc_attr__( 'نقش شما', 'music-wave-core' ) . '">';
			foreach ( RequestPostType::role_labels() as $key => $label ) {
				$html .= '<label class="mw-request-form__chip"><input type="radio" name="role" value="' . esc_attr( (string) $key ) . '"' . checked( $role, (string) $key, false ) . '><span>' . esc_html( $label ) . '</span></label>';
			}
			$html .= '</div>';
		} else {
			$html .= '<input type="hidden" name="role" value="' . esc_attr( $role ) . '">';
		}
		$html .= '<div class="mw-request-form__row">';
		$html .= $this->field( 'name', __( 'نام و نام خانوادگی', 'music-wave-core' ), '<input type="text" id="mw-request-name" name="name" value="' . esc_attr( $value( 'name' ) ) . '" maxlength="' . (int) RequestSubmission::MAX_NAME . '" autocomplete="name" required' . ( $is_logged_in ? ' readonly' : '' ) . $this->described( 'name', $errors ) . '>', $errors, $is_logged_in ? __( 'از حساب کاربری شما', 'music-wave-core' ) : '' );
		$html .= $this->field( 'email', __( 'ایمیل', 'music-wave-core' ), '<input type="email" id="mw-request-email" name="email" value="' . esc_attr( $value( 'email' ) ) . '" autocomplete="email" inputmode="email" dir="ltr" required' . ( $is_logged_in ? ' readonly' : '' ) . $this->described( 'email', $errors ) . '>', $errors, $is_logged_in ? __( 'از حساب کاربری شما – غیرقابل ویرایش', 'music-wave-core' ) : '' );
		if ( BlockSupport::bool_attribute( $attributes, 'showPhone', true ) ) {
			$phone_hint = ( $is_logged_in && '' !== $profile['phone'] ) ? __( 'از حساب شما – برای تغییر به ویرایش حساب بروید.', 'music-wave-core' ) : '';
			$html      .= $this->field( 'phone', __( 'شمارهٔ تماس (اختیاری)', 'music-wave-core' ), '<input type="tel" id="mw-request-phone" name="phone" value="' . esc_attr( $value( 'phone' ) ) . '" autocomplete="tel" inputmode="tel" dir="ltr"' . ( $is_logged_in && '' !== $profile['phone'] ? ' readonly' : '' ) . $this->described( 'phone', $errors ) . '>', $errors, $phone_hint );
		}
		$html .= '</div></fieldset>';

		// Consent + submit.
		$html .= '<div class="mw-request-form__footer">';
		$html .= '<label class="mw-request-form__consent' . ( isset( $errors['consent'] ) ? ' is-invalid' : '' ) . '"><input type="checkbox" name="consent" value="1" required' . $this->described( 'consent', $errors ) . '><span>' . esc_html__( 'موافقم که نام و راه تماس من برای پاسخ به این درخواست ذخیره شود.', 'music-wave-core' ) . '</span></label>';
		if ( isset( $errors['consent'] ) ) {
			$html .= '<span class="mw-request-form__error" id="mw-request-consent-error">' . esc_html( $errors['consent'] ) . '</span>';
		}
		$html .= '<button type="submit" class="wp-element-button mw-request-form__submit"><span>' . esc_html( $submit ) . '</span><span class="mw-request-form__submit-icon" aria-hidden="true">➜</span></button>';
		$html .= '</div>';

		return $html . '</form>';
	}

	/**
	 * One labelled field with its error and hint.
	 *
	 * @param array<string, string> $errors Field errors.
	 */
	private function field( string $key, string $label, string $control, array $errors, string $hint = '' ): string {
		$html = '<div class="mw-request-form__field mw-request-form__field--' . esc_attr( $key ) . ( isset( $errors[ $key ] ) ? ' is-invalid' : '' ) . '"><label for="mw-request-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>' . $control;
		if ( isset( $errors[ $key ] ) ) {
			$html .= '<span class="mw-request-form__error" id="mw-request-' . esc_attr( $key ) . '-error" role="alert">' . esc_html( $errors[ $key ] ) . '</span>';
		} elseif ( '' !== $hint ) {
			$html .= '<span class="mw-request-form__hint" id="mw-request-' . esc_attr( $key ) . '-hint">' . esc_html( $hint ) . '</span>';
		}

		return $html . '</div>';
	}

	/**
	 * aria-describedby / aria-invalid attributes for a field.
	 *
	 * @param array<string, string> $errors Field errors.
	 */
	private function described( string $key, array $errors ): string {
		$required = in_array( $key, array( 'name', 'email', 'subject', 'message', 'consent' ), true );
		$req_attr = $required ? ' aria-required="true"' : '';
		if ( isset( $errors[ $key ] ) ) {
			return $req_attr . ' aria-invalid="true" aria-describedby="mw-request-' . esc_attr( $key ) . '-error"';
		}
		if ( in_array( $key, array( 'message', 'links' ), true ) ) {
			return $req_attr . ' aria-describedby="mw-request-' . esc_attr( $key ) . '-hint"';
		}

		return $req_attr;
	}

	private function notice_markup( string $notice ): string {
		if ( '' === $notice || 'sent' === $notice ) {
			return '';
		}
		$message = $this->forms->notice_message( $notice );
		if ( '' === $message ) {
			return '';
		}

		return '<p class="mw-request-form__notice' . ( $this->forms->notice_is_error( $notice ) ? ' mw-request-form__notice--error' : '' ) . '" role="alert" id="mw-request-notice">' . esc_html( $message ) . '</p>';
	}

	private function success_markup(): string {
		$is_logged_in = function_exists( 'is_user_logged_in' ) && is_user_logged_in();
		$account_url  = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/account/' );
		$extra_link   = $is_logged_in ? '<a class="mw-request-form__track" href="' . esc_url( $account_url ) . '">' . esc_html__( 'پیگیری در حساب کاربری', 'music-wave-core' ) . '</a>' : '';
		return '<div class="mw-request-form__success" role="status" id="mw-request-notice"><span class="mw-request-form__success-icon" aria-hidden="true">✓</span><h3>' . esc_html__( 'درخواست شما رسید', 'music-wave-core' ) . '</h3><p>' . esc_html( $this->forms->notice_message( 'sent' ) ) . '</p><div class="mw-request-form__success-actions"><a class="mw-request-form__again" href="' . esc_url( remove_query_arg( array( RequestFormHandler::NOTICE_ARG, RequestFormHandler::TOKEN_ARG ), BlockSupport::current_url() ) ) . '">' . esc_html__( 'ثبت درخواست دیگر', 'music-wave-core' ) . '</a>' . $extra_link . '</div></div>';
	}

	private function requested_notice(): string {
		return isset( $_GET[ RequestFormHandler::NOTICE_ARG ] ) && is_scalar( $_GET[ RequestFormHandler::NOTICE_ARG ] ) ? sanitize_key( wp_unslash( (string) $_GET[ RequestFormHandler::NOTICE_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- post/redirect/get notice code.
	}

	private function requested_token(): string {
		return isset( $_GET[ RequestFormHandler::TOKEN_ARG ] ) && is_scalar( $_GET[ RequestFormHandler::TOKEN_ARG ] ) ? sanitize_key( wp_unslash( (string) $_GET[ RequestFormHandler::TOKEN_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- random one-shot token.
	}

	/**
	 * Register the public stylesheet and script once.
	 *
	 * @return void
	 */
	private function register_assets(): void {
		if ( ! function_exists( 'wp_register_style' ) || ! function_exists( 'wp_register_script' ) ) {
			return;
		}
		wp_register_style( self::ASSET_HANDLE, MUSIC_WAVE_CORE_URL . 'assets/request-form.css', array(), MUSIC_WAVE_CORE_VERSION );
		wp_register_script( self::ASSET_HANDLE, MUSIC_WAVE_CORE_URL . 'assets/request-form.js', array(), MUSIC_WAVE_CORE_VERSION, true );
		wp_localize_script(
			self::ASSET_HANDLE,
			'musicWaveRequestForm',
			array(
				/* translators: 1: characters typed, 2: maximum characters. */
				'counter' => __( '%1$s از %2$s نویسه', 'music-wave-core' ),
				'minimum' => sprintf(
					/* translators: %s: minimum number of characters. */
					__( 'حداقل %s نویسه بنویسید', 'music-wave-core' ),
					number_format_i18n( RequestSubmission::MIN_MESSAGE )
				),
			)
		);
	}

	/**
	 * Front-end style + enhancement script, enqueued on use only.
	 *
	 * @return void
	 */
	private function enqueue_assets(): void {
		if ( is_admin() || ! function_exists( 'wp_enqueue_style' ) ) {
			return;
		}
		wp_enqueue_style( self::ASSET_HANDLE );
		wp_enqueue_script( self::ASSET_HANDLE );
	}
}
