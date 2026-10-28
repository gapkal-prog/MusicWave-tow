<?php
/**
 * Validation and normalization of one public request submission.
 *
 * Pure: no superglobals, no WordPress state, no side effects. The form
 * handler feeds it raw (unslashed) input and receives either a clean payload
 * for the repository or a field-keyed list of translated errors that the
 * block renders next to the inputs.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Requests;

use ManaCore\MusicWave\Core\Discovery\PersianSearchNormalizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RequestSubmission {
	public const MAX_LINKS      = 5;
	public const MIN_MESSAGE    = 20;
	public const MAX_MESSAGE    = 4000;
	public const MAX_SUBJECT    = 140;
	public const MAX_NAME       = 80;
	public const MAX_PHONE      = 24;
	public const MAX_LINK_CHARS = 200;

	/**
	 * Fields accepted from the public form, in render order.
	 *
	 * @return array<int, string>
	 */
	public static function fields(): array {
		return array( 'name', 'email', 'phone', 'role', 'type', 'subject', 'message', 'budget', 'deadline', 'links', 'consent' );
	}

	/**
	 * Validate raw input.
	 *
	 * @param array<string, mixed> $input         Raw form values (already unslashed).
	 * @param int                  $today         Unix timestamp used for the deadline check.
	 * @param array<int, string>   $allowed_types Request kinds accepted by the caller; empty means every known kind.
	 * @return array{data: array<string, mixed>, errors: array<string, string>}
	 */
	public static function validate( array $input, int $today = 0, array $allowed_types = array() ): array {
		$errors = array();
		$data   = array();

		$name = self::text( $input, 'name' );
		if ( mb_strlen( $name ) < 2 ) {
			$errors['name'] = __( 'نام خود را وارد کنید (حداقل ۲ نویسه).', 'music-wave-core' );
		} elseif ( mb_strlen( $name ) > self::MAX_NAME ) {
			$errors['name'] = __( 'نام بیش از حد طولانی است.', 'music-wave-core' );
		}
		$data['name'] = mb_substr( $name, 0, self::MAX_NAME );

		$email = strtolower( self::text( $input, 'email' ) );
		if ( '' === $email || ! self::is_email( $email ) ) {
			$errors['email'] = __( 'یک نشانی ایمیل معتبر وارد کنید تا بتوانیم پاسخ دهیم.', 'music-wave-core' );
		}
		$data['email'] = $email;

		$phone = self::text( $input, 'phone' );
		$phone = (string) preg_replace( '/[^0-9+()\-\s]/u', '', PersianSearchNormalizer::digits_to_ascii( $phone ) );
		$phone = trim( (string) preg_replace( '/\s+/', ' ', $phone ) );
		if ( '' !== $phone && ( strlen( preg_replace( '/\D/', '', $phone ) ?? '' ) < 6 || strlen( $phone ) > self::MAX_PHONE ) ) {
			$errors['phone'] = __( 'شمارهٔ تماس معتبر نیست.', 'music-wave-core' );
		}
		$data['phone'] = $phone;

		$data['role'] = self::choice( $input, 'role', array_keys( RequestPostType::role_labels() ), 'other' );
		$known        = array_keys( RequestPostType::type_labels() );
		$data['type'] = self::choice( $input, 'type', $known, 'song' );
		$allowed      = array_values( array_intersect( $known, array_map( 'strval', $allowed_types ) ) );
		if ( array() !== $allowed && ! in_array( $data['type'], $allowed, true ) ) {
			$errors['type'] = __( 'این نوع درخواست در این فرم پذیرفته نمی‌شود.', 'music-wave-core' );
			$data['type']   = $allowed[0];
		}

		$subject = self::text( $input, 'subject' );
		if ( mb_strlen( $subject ) < 3 ) {
			$errors['subject'] = __( 'یک عنوان کوتاه برای درخواست بنویسید.', 'music-wave-core' );
		}
		$data['subject'] = mb_substr( $subject, 0, self::MAX_SUBJECT );

		$message = self::multiline( $input, 'message' );
		if ( mb_strlen( $message ) < self::MIN_MESSAGE ) {
			$errors['message'] = sprintf(
				/* translators: %d: minimum number of characters. */
				__( 'توضیحات را کامل‌تر بنویسید (حداقل %d نویسه) تا بتوانیم دقیق‌تر پاسخ دهیم.', 'music-wave-core' ),
				self::MIN_MESSAGE
			);
		} elseif ( mb_strlen( $message ) > self::MAX_MESSAGE ) {
			$errors['message'] = sprintf(
				/* translators: %d: maximum number of characters. */
				__( 'توضیحات نباید بیش از %d نویسه باشد.', 'music-wave-core' ),
				self::MAX_MESSAGE
			);
		}
		$data['message'] = mb_substr( $message, 0, self::MAX_MESSAGE );

		$data['budget'] = self::choice( $input, 'budget', array_keys( RequestPostType::budget_labels() ), '' );

		$deadline = PersianSearchNormalizer::digits_to_ascii( self::text( $input, 'deadline' ) );
		if ( '' !== $deadline ) {
			$parsed = self::parse_date( $deadline );
			if ( null === $parsed ) {
				$errors['deadline'] = __( 'تاریخ مورد نظر را به شکل سال-ماه-روز وارد کنید.', 'music-wave-core' );
				$deadline           = '';
			} elseif ( $today > 0 && $parsed < gmdate( 'Y-m-d', $today - 86400 ) ) {
				$errors['deadline'] = __( 'تاریخ مورد نظر نمی‌تواند در گذشته باشد.', 'music-wave-core' );
			} else {
				$deadline = $parsed;
			}
		}
		$data['deadline'] = $deadline;

		$links      = array();
		$raw        = isset( $input['links'] ) ? $input['links'] : '';
		$raw        = is_array( $raw ) ? implode( "\n", array_map( 'strval', $raw ) ) : (string) $raw;
		$candidates = preg_split( '/[\s,]+/u', $raw );
		foreach ( is_array( $candidates ) ? $candidates : array() as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' === $candidate ) {
				continue;
			}
			if ( ! preg_match( '#^https?://#i', $candidate ) ) {
				$candidate = 'https://' . $candidate;
			}
			$clean = self::clean_url( $candidate );
			if ( '' === $clean || strlen( $clean ) > self::MAX_LINK_CHARS ) {
				$errors['links'] = __( 'یکی از پیوندها معتبر نیست؛ فقط نشانی‌های http یا https پذیرفته می‌شوند.', 'music-wave-core' );
				continue;
			}
			if ( ! in_array( $clean, $links, true ) ) {
				$links[] = $clean;
			}
			if ( count( $links ) >= self::MAX_LINKS ) {
				break;
			}
		}
		$data['links'] = $links;

		$consent = isset( $input['consent'] ) ? $input['consent'] : '';
		if ( ! in_array( $consent, array( '1', 1, true, 'on', 'yes' ), true ) ) {
			$errors['consent'] = __( 'برای ثبت درخواست باید با ذخیرهٔ اطلاعات تماس خود موافقت کنید.', 'music-wave-core' );
		}
		$data['consent'] = array() === $errors || ! isset( $errors['consent'] );

		return array(
			'data'   => $data,
			'errors' => $errors,
		);
	}

	/**
	 * Single-line text field.
	 *
	 * @param array<string, mixed> $input Raw input.
	 */
	private static function text( array $input, string $key ): string {
		$value = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : '';
		$value = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( strip_tags( $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
	}

	/**
	 * Multi-line text field: tags stripped, line breaks kept.
	 *
	 * @param array<string, mixed> $input Raw input.
	 */
	private static function multiline( array $input, string $key ): string {
		$value = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : '';
		if ( function_exists( 'sanitize_textarea_field' ) ) {
			$value = sanitize_textarea_field( $value );
		} else {
			$value = trim( strip_tags( $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
		$value = (string) preg_replace( "/\n{3,}/", "\n\n", $value );

		return trim( $value );
	}

	/**
	 * Allow-listed choice with a fallback.
	 *
	 * @param array<string, mixed> $input   Raw input.
	 * @param array<int, string>   $allowed Accepted values.
	 */
	private static function choice( array $input, string $key, array $allowed, string $fallback ): string {
		$value = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? strtolower( trim( (string) $input[ $key ] ) ) : '';
		$value = (string) preg_replace( '/[^a-z0-9_\-]/', '', $value );

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Conservative email check that works with or without WordPress loaded.
	 */
	private static function is_email( string $email ): bool {
		if ( function_exists( 'is_email' ) ) {
			return false !== is_email( $email );
		}

		return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
	}

	/**
	 * Validate an http(s) URL.
	 */
	private static function clean_url( string $url ): string {
		$clean = function_exists( 'esc_url_raw' ) ? esc_url_raw( $url, array( 'http', 'https' ) ) : $url;
		if ( ! is_string( $clean ) || '' === $clean || false === filter_var( $clean, FILTER_VALIDATE_URL ) ) {
			return '';
		}
		$host = (string) parse_url( $clean, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url

		return false !== strpos( $host, '.' ) ? $clean : '';
	}

	/**
	 * Parse and normalize a Gregorian Y-m-d (also accepts Y/m/d) string without Y2038 overflow.
	 */
	private static function parse_date( string $value ): ?string {
		if ( ! preg_match( '/^(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})$/', $value, $match ) ) {
			return null;
		}
		$year  = (int) $match[1];
		$month = (int) $match[2];
		$day   = (int) $match[3];
		if ( $year < 2000 || $year > 2100 || ! checkdate( $month, $day, $year ) ) {
			return null;
		}

		return sprintf( '%04d-%02d-%02d', $year, $month, $day );
	}
}
