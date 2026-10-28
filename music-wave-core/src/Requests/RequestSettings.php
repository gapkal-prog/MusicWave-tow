<?php
/**
 * Options for the request/collaboration workflow.
 *
 * One autoloaded option keeps notification recipients, the reply-to address,
 * the requester receipt copy, the public form switch, and the requester chat
 * toggle. Values are normalized on read and on write so callers never see raw
 * input (SOLID SRP + Single Source of Truth).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Requests;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RequestSettings {
	public const OPTION = 'music_wave_requests';

	/**
	 * Defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'form_enabled'   => 'enabled',
			'recipients'     => '',
			'reply_to'       => '',
			'send_receipts'  => 'enabled',
			'receipt_intro'  => '',
			'signature'      => '',
			'rate_limit'     => 3,
			'rate_window'    => 3600,
			'enabled_types'  => array( 'song', 'collab', 'advertising', 'event', 'other' ),
			'success_notice' => '',
			'chat_enabled'   => 'disabled',
		);
	}

	/**
	 * Normalized stored settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		return self::normalize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Sanitize a Settings API payload.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $value ): array {
		return self::normalize( is_array( $value ) ? $value : array() );
	}

	/**
	 * Persist the chat switch while preserving every other request setting.
	 */
	public static function set_chat_enabled( bool $enabled ): void {
		$current                 = self::all();
		$current['chat_enabled'] = $enabled ? 'enabled' : 'disabled';
		update_option( self::OPTION, self::normalize( $current ) );
	}

	public function form_enabled(): bool {
		return 'enabled' === self::all()['form_enabled'];
	}

	public function chat_enabled(): bool {
		return 'enabled' === self::all()['chat_enabled'];
	}

	public function receipts_enabled(): bool {
		return 'enabled' === self::all()['send_receipts'];
	}

	public function receipt_intro(): string {
		return (string) self::all()['receipt_intro'];
	}

	public function signature(): string {
		return (string) self::all()['signature'];
	}

	public function reply_to(): string {
		return (string) self::all()['reply_to'];
	}

	public function success_notice(): string {
		return (string) self::all()['success_notice'];
	}

	public function rate_limit(): int {
		return (int) self::all()['rate_limit'];
	}

	public function rate_window(): int {
		return (int) self::all()['rate_window'];
	}

	/**
	 * Request kinds offered on the public form.
	 *
	 * @return array<string, string>
	 */
	public function enabled_types(): array {
		$enabled = self::all()['enabled_types'];
		$labels  = RequestPostType::type_labels();
		$types   = array();
		foreach ( $labels as $key => $label ) {
			if ( in_array( $key, (array) $enabled, true ) ) {
				$types[ $key ] = $label;
			}
		}

		return array() !== $types ? $types : $labels;
	}

	/**
	 * Manager alert recipients; falls back to the site admin address.
	 *
	 * @return array<int, string>
	 */
	public function notification_recipients(): array {
		$recipients = self::parse_recipients( (string) self::all()['recipients'] );
		if ( array() === $recipients && function_exists( 'get_bloginfo' ) ) {
			$admin = (string) get_bloginfo( 'admin_email' );
			if ( '' !== $admin ) {
				$recipients[] = $admin;
			}
		}

		return $recipients;
	}

	/**
	 * Comma/newline separated addresses -> validated list.
	 *
	 * @return array<int, string>
	 */
	public static function parse_recipients( string $raw ): array {
		$recipients = array();
		$candidates = preg_split( '/[\s,;]+/', $raw );
		foreach ( is_array( $candidates ) ? $candidates : array() as $candidate ) {
			$candidate = strtolower( trim( $candidate ) );
			if ( '' === $candidate ) {
				continue;
			}
			$valid = function_exists( 'is_email' ) ? false !== is_email( $candidate ) : false !== filter_var( $candidate, FILTER_VALIDATE_EMAIL );
			if ( $valid && ! in_array( $candidate, $recipients, true ) ) {
				$recipients[] = $candidate;
			}
			if ( count( $recipients ) >= 10 ) {
				break;
			}
		}

		return $recipients;
	}

	/**
	 * @param array<string, mixed> $value Candidate settings.
	 * @return array<string, mixed>
	 */
	private static function normalize( array $value ): array {
		$defaults = self::defaults();
		$result   = array();

		foreach ( array( 'form_enabled', 'send_receipts', 'chat_enabled' ) as $key ) {
			$toggle         = isset( $value[ $key ] ) && is_scalar( $value[ $key ] ) ? sanitize_key( (string) $value[ $key ] ) : '';
			$result[ $key ] = in_array( $toggle, array( 'enabled', 'disabled' ), true ) ? $toggle : $defaults[ $key ];
		}

		$result['recipients'] = isset( $value['recipients'] ) && is_scalar( $value['recipients'] ) ? implode( ', ', self::parse_recipients( (string) $value['recipients'] ) ) : '';

		$reply_to           = isset( $value['reply_to'] ) && is_scalar( $value['reply_to'] ) ? strtolower( trim( (string) $value['reply_to'] ) ) : '';
		$reply_valid        = '' !== $reply_to && ( function_exists( 'is_email' ) ? false !== is_email( $reply_to ) : false !== filter_var( $reply_to, FILTER_VALIDATE_EMAIL ) );
		$result['reply_to'] = $reply_valid ? $reply_to : '';

		foreach ( array( 'receipt_intro', 'signature', 'success_notice' ) as $key ) {
			$text = isset( $value[ $key ] ) && is_scalar( $value[ $key ] ) ? (string) $value[ $key ] : '';
			if ( function_exists( 'sanitize_textarea_field' ) ) {
				$text = sanitize_textarea_field( $text );
			} else {
				$text = trim( strip_tags( $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			$result[ $key ] = mb_substr( $text, 0, 1000 );
		}

		$limit                = isset( $value['rate_limit'] ) ? absint( $value['rate_limit'] ) : 0;
		$result['rate_limit'] = $limit >= 1 && $limit <= 100 ? $limit : $defaults['rate_limit'];

		$window                = isset( $value['rate_window'] ) ? absint( $value['rate_window'] ) : 0;
		$result['rate_window'] = $window >= 60 && $window <= 86400 ? $window : $defaults['rate_window'];

		$types = isset( $value['enabled_types'] ) ? (array) $value['enabled_types'] : $defaults['enabled_types'];
		$clean = array();
		foreach ( $types as $type ) {
			$type = is_scalar( $type ) ? sanitize_key( (string) $type ) : '';
			if ( isset( RequestPostType::type_labels()[ $type ] ) && ! in_array( $type, $clean, true ) ) {
				$clean[] = $type;
			}
		}
		$result['enabled_types'] = array() !== $clean ? $clean : $defaults['enabled_types'];

		return $result;
	}
}
