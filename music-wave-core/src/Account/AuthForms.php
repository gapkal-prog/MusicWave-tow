<?php
/**
 * Accessible, server-rendered sign-in / sign-up panels.
 *
 * Rendered through the `[music_wave_auth]` shortcode used by the bundled
 * `page-login` template. No JavaScript is required: every state is a real
 * link or a real POST, so the forms keep working with scripts disabled and
 * degrade gracefully on slow connections (progressive enhancement).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Account;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AuthForms {
	/** Shortcode tag consumed by the bundled login template. */
	public const SHORTCODE = 'music_wave_auth';

	/** Sign-in panel slug. */
	public const PANEL_LOGIN = 'login';

	/** Sign-up panel slug. */
	public const PANEL_REGISTER = 'register';

	/**
	 * Register the shortcode.
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes (unused).
	 */
	public function shortcode( $atts = array() ): string {
		unset( $atts );

		return $this->render();
	}

	/**
	 * Render the active panel.
	 */
	public function render(): string {
		if ( is_user_logged_in() ) {
			return $this->signed_in_panel();
		}

		$requested = AuthPolicy::request_value( AuthPolicy::PANEL_ARG );
		$panel     = self::PANEL_REGISTER === $requested && self::registration_open() ? self::PANEL_REGISTER : self::PANEL_LOGIN;
		$redirect  = AuthPolicy::safe_redirect( AuthPolicy::request_value( 'redirect_to' ), '' );

		$is_register = self::PANEL_REGISTER === $panel;

		return '<div class="mw-auth" id="mw-auth"><div class="mw-auth__card">'
			. '<header class="mw-auth__head">'
			. '<span class="mw-auth__eyebrow">' . esc_html__( 'حساب کاربری', 'music-wave-core' ) . '</span>'
			. '<h2 class="mw-auth__title">' . esc_html( $is_register ? __( 'ساخت حساب تازه', 'music-wave-core' ) : __( 'ورود به حساب', 'music-wave-core' ) ) . '</h2>'
			. '<p class="mw-auth__lede">' . esc_html( $is_register
				? __( 'در چند ثانیه حساب بسازید و کتابخانه، صف شنیدن و دانلودهای خود را در یک‌جا نگه دارید.', 'music-wave-core' )
				: __( 'برای ادامه، نام کاربری یا ایمیل و گذرواژه خود را وارد کنید.', 'music-wave-core' ) ) . '</p>'
			. '</header>'
			. $this->notices()
			. $this->panel_switch( $panel, $redirect )
			. ( $is_register ? $this->register_form( $redirect ) : $this->login_form( $redirect ) )
			. '</div></div>';
	}

	/**
	 * Whether self-service registration is currently allowed.
	 */
	public static function registration_open(): bool {
		return (bool) get_option( 'users_can_register' );
	}

	/**
	 * Error / success banner driven by validated query arguments.
	 */
	private function notices(): string {
		$markup = '';

		$error = sanitize_key( AuthPolicy::request_value( AuthPolicy::ERROR_ARG ) );
		if ( '' !== $error ) {
			$markup .= '<div class="mw-auth__notice mw-auth__notice--error" role="alert">'
				. '<span class="mw-auth__notice-icon" aria-hidden="true">!</span>'
				. '<p>' . esc_html( AuthPolicy::message( $error ) ) . '</p></div>';
		}

		$notice = sanitize_key( AuthPolicy::request_value( AuthPolicy::NOTICE_ARG ) );
		if ( '' !== $notice ) {
			$markup .= '<div class="mw-auth__notice mw-auth__notice--success" role="status">'
				. '<span class="mw-auth__notice-icon" aria-hidden="true">&#10003;</span>'
				. '<p>' . esc_html( AuthPolicy::message( $notice ) ) . '</p></div>';
		}

		return $markup;
	}

	/**
	 * Segmented control switching between the two panels.
	 *
	 * @param string $panel    Active panel slug.
	 * @param string $redirect Validated post-login destination.
	 */
	private function panel_switch( string $panel, string $redirect ): string {
		$login_url    = $this->panel_url( self::PANEL_LOGIN, $redirect );
		$register_url = $this->panel_url( self::PANEL_REGISTER, $redirect );
		$is_register  = self::PANEL_REGISTER === $panel;

		$markup = '<nav class="mw-auth__switch" aria-label="' . esc_attr__( 'انتخاب ورود یا ثبت‌نام', 'music-wave-core' ) . '">'
			. '<a class="mw-auth__switch-link' . ( $is_register ? '' : ' is-active' ) . '" href="' . esc_url( $login_url ) . '"' . ( $is_register ? '' : ' aria-current="page"' ) . '>'
			. esc_html__( 'ورود', 'music-wave-core' ) . '</a>';

		if ( self::registration_open() ) {
			$markup .= '<a class="mw-auth__switch-link' . ( $is_register ? ' is-active' : '' ) . '" href="' . esc_url( $register_url ) . '"' . ( $is_register ? ' aria-current="page"' : '' ) . '>'
				. esc_html__( 'ثبت‌نام', 'music-wave-core' ) . '</a>';
		}

		return $markup . '</nav>';
	}

	/**
	 * Build an internal panel URL while preserving the post-login target.
	 *
	 * @param string $panel    Panel slug.
	 * @param string $redirect Validated destination.
	 */
	private function panel_url( string $panel, string $redirect ): string {
		$url = AuthPolicy::login_page_url();
		$url = add_query_arg( AuthPolicy::PANEL_ARG, $panel, $url );

		return '' === $redirect ? $url : add_query_arg( 'redirect_to', $redirect, $url );
	}

	/**
	 * Sign-in form.
	 *
	 * @param string $redirect Validated destination.
	 */
	private function login_form( string $redirect ): string {
		return '<form class="mw-auth__form" action="' . esc_url( $this->endpoint() ) . '" method="post" novalidate="novalidate">'
			. $this->hidden_fields( AuthPolicy::LOGIN_NONCE, self::PANEL_LOGIN, $redirect )
			. $this->field( 'mw_login', array(
				'label'        => __( 'نام کاربری یا ایمیل', 'music-wave-core' ),
				'type'         => 'text',
				'autocomplete' => 'username',
				'required'     => true,
				'inputmode'    => 'text',
			) )
			. $this->field( 'mw_password', array(
				'label'        => __( 'گذرواژه', 'music-wave-core' ),
				'type'         => 'password',
				'autocomplete' => 'current-password',
				'required'     => true,
			) )
			. '<div class="mw-auth__row">'
			. '<label class="mw-auth__check" for="mw-remember"><input type="checkbox" id="mw-remember" name="mw_remember" value="1" checked="checked"> <span>' . esc_html__( 'مرا به خاطر بسپار', 'music-wave-core' ) . '</span></label>'
			. '<a class="mw-auth__link" href="' . esc_url( wp_lostpassword_url( AuthPolicy::login_page_url() ) ) . '">' . esc_html__( 'گذرواژه را فراموش کرده‌اید؟', 'music-wave-core' ) . '</a>'
			. '</div>'
			. $this->submit( __( 'ورود به حساب', 'music-wave-core' ) )
			. $this->trap()
			. '</form>';
	}

	/**
	 * Sign-up form.
	 *
	 * @param string $redirect Validated destination.
	 */
	private function register_form( string $redirect ): string {
		$privacy_url = function_exists( 'get_privacy_policy_url' ) ? (string) get_privacy_policy_url() : '';

		$privacy_link = '' !== $privacy_url
			? '<a class="mw-auth__link" href="' . esc_url( $privacy_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'سیاست حریم خصوصی', 'music-wave-core' ) . '</a>'
			: esc_html__( 'سیاست حریم خصوصی', 'music-wave-core' );

		return '<form class="mw-auth__form" action="' . esc_url( $this->endpoint() ) . '" method="post" novalidate="novalidate">'
			. $this->hidden_fields( AuthPolicy::REGISTER_NONCE, self::PANEL_REGISTER, $redirect )
			. $this->field( 'mw_register_login', array(
				'label'        => __( 'نام کاربری', 'music-wave-core' ),
				'type'         => 'text',
				'autocomplete' => 'username',
				'required'     => true,
				'hint'         => __( 'حروف لاتین، عدد، نقطه، خط تیره و زیرخط.', 'music-wave-core' ),
			) )
			. $this->field( 'mw_register_email', array(
				'label'        => __( 'ایمیل', 'music-wave-core' ),
				'type'         => 'email',
				'autocomplete' => 'email',
				'required'     => true,
				'inputmode'    => 'email',
			) )
			. $this->field( 'mw_register_password', array(
				'label'        => __( 'گذرواژه', 'music-wave-core' ),
				'type'         => 'password',
				'autocomplete' => 'new-password',
				'required'     => true,
				'minlength'    => 8,
				'hint'         => __( 'دست‌کم ۸ نویسه؛ ترکیبی از حروف، عدد و نماد.', 'music-wave-core' ),
			) )
			. $this->field( 'mw_register_confirm', array(
				'label'        => __( 'تکرار گذرواژه', 'music-wave-core' ),
				'type'         => 'password',
				'autocomplete' => 'new-password',
				'required'     => true,
				'minlength'    => 8,
			) )
			. '<label class="mw-auth__check mw-auth__check--stacked" for="mw-terms">'
			. '<input type="checkbox" id="mw-terms" name="mw_terms" value="1" required="required">'
			. '<span>' . sprintf(
				/* translators: %s: link to the privacy policy. */
				esc_html__( 'قوانین سایت و %s را می‌پذیرم.', 'music-wave-core' ),
				$privacy_link
			) . '</span></label>'
			. $this->submit( __( 'ساخت حساب', 'music-wave-core' ) )
			. $this->trap()
			. '</form>';
	}

	/**
	 * Panel shown to visitors who are already authenticated.
	 */
	private function signed_in_panel(): string {
		$user     = wp_get_current_user();
		$name     = $user instanceof \WP_User ? (string) $user->display_name : '';
		$greeting = '' !== $name
			/* translators: %s: current user display name. */
			? sprintf( __( 'خوش آمدید، %s', 'music-wave-core' ), $name )
			: __( 'شما وارد حساب کاربری خود شده‌اید.', 'music-wave-core' );

		return '<div class="mw-auth" id="mw-auth"><div class="mw-auth__card mw-auth__card--signed-in">'
			. '<header class="mw-auth__head">'
			. '<span class="mw-auth__eyebrow">' . esc_html__( 'حساب کاربری', 'music-wave-core' ) . '</span>'
			. '<h2 class="mw-auth__title">' . esc_html( $greeting ) . '</h2>'
			. '<p class="mw-auth__lede">' . esc_html__( 'کتابخانه، صف شنیدن و دانلودهای شما آماده است.', 'music-wave-core' ) . '</p>'
			. '</header>'
			. '<div class="mw-auth__actions">'
			. '<a class="mw-action-button mw-action-button--accent mw-action-button--md mw-action-button--block" href="' . esc_url( AuthPolicy::account_url() ) . '">' . esc_html__( 'رفتن به حساب من', 'music-wave-core' ) . '</a>'
			. '<a class="mw-auth__link" href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">' . esc_html__( 'خروج از حساب', 'music-wave-core' ) . '</a>'
			. '</div>'
			. '</div></div>';
	}

	/**
	 * Local admin-post endpoint shared by both forms.
	 */
	private function endpoint(): string {
		return admin_url( 'admin-post.php' );
	}

	/**
	 * Hidden fields: routed action, CSRF nonce, context marker, destination.
	 *
	 * @param string $nonce_action Nonce action for the panel.
	 * @param string $panel        Panel slug (routed action suffix).
	 * @param string $redirect     Validated destination.
	 */
	private function hidden_fields( string $nonce_action, string $panel, string $redirect ): string {
		$markup = '<input type="hidden" name="action" value="' . esc_attr( 'mw_auth_' . $panel ) . '">'
			. wp_nonce_field( $nonce_action, AuthPolicy::NONCE_FIELD, false, false )
			. '<input type="hidden" name="' . esc_attr( AuthPolicy::MARKER_FIELD ) . '" value="' . esc_attr( $panel ) . '">';

		return '' === $redirect ? $markup : $markup . '<input type="hidden" name="redirect_to" value="' . esc_attr( $redirect ) . '">';
	}

	/**
	 * Render one labelled input with optional hint.
	 *
	 * @param string              $name Field name (also the id base).
	 * @param array<string,mixed> $args Field configuration.
	 */
	private function field( string $name, array $args ): string {
		$id          = 'mw-' . str_replace( '_', '-', $name );
		$type        = isset( $args['type'] ) && is_string( $args['type'] ) ? $args['type'] : 'text';
		$label       = isset( $args['label'] ) && is_string( $args['label'] ) ? $args['label'] : '';
		$hint        = isset( $args['hint'] ) && is_string( $args['hint'] ) ? $args['hint'] : '';
		$hint_id     = $id . '-hint';
		$autocomplete = isset( $args['autocomplete'] ) && is_string( $args['autocomplete'] ) ? $args['autocomplete'] : 'off';
		$required    = ! empty( $args['required'] );
		$minlength   = isset( $args['minlength'] ) ? (int) $args['minlength'] : 0;
		$inputmode   = isset( $args['inputmode'] ) && is_string( $args['inputmode'] ) ? $args['inputmode'] : '';

		$attributes = ' type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"'
			. ' autocomplete="' . esc_attr( $autocomplete ) . '"'
			. ' spellcheck="false"'
			. ( $required ? ' required="required"' : '' )
			. ( $minlength > 0 ? ' minlength="' . esc_attr( (string) $minlength ) . '"' : '' )
			. ( '' !== $inputmode ? ' inputmode="' . esc_attr( $inputmode ) . '"' : '' )
			. ( '' !== $hint ? ' aria-describedby="' . esc_attr( $hint_id ) . '"' : '' );

		return '<div class="mw-auth__field">'
			. '<label class="mw-auth__label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . ( $required ? '<span class="mw-auth__required" aria-hidden="true">*</span>' : '' ) . '</label>'
			. '<input class="mw-auth__input"' . $attributes . '>'
			. ( '' !== $hint ? '<p class="mw-auth__hint" id="' . esc_attr( $hint_id ) . '">' . esc_html( $hint ) . '</p>' : '' )
			. '</div>';
	}

	/**
	 * Submit control using the shared action-button system.
	 *
	 * @param string $label Button label.
	 */
	private function submit( string $label ): string {
		return '<button type="submit" class="mw-action-button mw-action-button--accent mw-action-button--md mw-action-button--block mw-auth__submit">'
			. esc_html( $label ) . '</button>';
	}

	/**
	 * Honeypot: invisible to humans and assistive technology, irresistible to bots.
	 */
	private function trap(): string {
		return '<div class="mw-auth__trap" aria-hidden="true">'
			. '<label for="mw-auth-website">' . esc_html__( 'وب‌سایت', 'music-wave-core' ) . '</label>'
			. '<input type="text" id="mw-auth-website" name="mw_auth_website" value="" tabindex="-1" autocomplete="off">'
			. '</div>';
	}
}
