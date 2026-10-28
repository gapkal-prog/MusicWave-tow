<?php
/**
 * Outgoing email for the request workflow.
 *
 * Three messages exist: the requester's receipt, the manager alert for a new
 * request, and a manager reply. Every message passes through one filter so
 * sites can re-route delivery (transactional providers, queues) or change the
 * copy without touching the workflow; a `false` return suppresses delivery.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Requests;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RequestMailer {
	/** @var RequestSettings */
	private $settings;

	public function __construct( ?RequestSettings $settings = null ) {
		$this->settings = null !== $settings ? $settings : new RequestSettings();
	}

	/**
	 * Confirmation to the requester right after submission.
	 *
	 * @param array<string, mixed> $request Normalized request.
	 */
	public function send_receipt( array $request ): bool {
		if ( ! $this->settings->receipts_enabled() ) {
			return false;
		}

		$site    = $this->site_name();
		$subject = sprintf(
			/* translators: 1: site name, 2: request reference. */
			__( '%1$s — درخواست شما ثبت شد (%2$s)', 'music-wave-core' ),
			$site,
			(string) $request['reference']
		);
		$lines = array(
			sprintf(
				/* translators: %s: requester name. */
				__( 'سلام %s،', 'music-wave-core' ),
				(string) $request['name']
			),
			'',
			__( 'درخواست شما با موفقیت ثبت شد و تیم ما آن را بررسی می‌کند. معمولاً ظرف چند روز کاری از همین نشانی ایمیل با شما تماس می‌گیریم.', 'music-wave-core' ),
			'',
			sprintf(
				/* translators: %s: request reference. */
				__( 'شمارهٔ پیگیری: %s', 'music-wave-core' ),
				(string) $request['reference']
			),
			sprintf(
				/* translators: %s: request subject. */
				__( 'موضوع: %s', 'music-wave-core' ),
				(string) $request['subject']
			),
			sprintf(
				/* translators: %s: request type label. */
				__( 'نوع درخواست: %s', 'music-wave-core' ),
				$this->label( RequestPostType::type_labels(), (string) $request['type'] )
			),
			'',
			__( 'خلاصهٔ پیام شما:', 'music-wave-core' ),
			$this->quote( (string) $request['message'] ),
		);
		$intro = $this->settings->receipt_intro();
		if ( '' !== $intro ) {
			array_splice( $lines, 2, 0, array( $intro, '' ) );
		}
		$lines[] = '';
		$lines[] = $this->signature();

		return $this->deliver(
			'receipt',
			(string) $request['email'],
			$subject,
			implode( "\n", $lines ),
			array( 'Reply-To: ' . $this->reply_to() ),
			$request
		);
	}

	/**
	 * Alert to the managers about a new request.
	 *
	 * @param array<string, mixed> $request Normalized request.
	 */
	public function send_manager_alert( array $request, string $admin_url ): bool {
		$recipients = $this->settings->notification_recipients();
		if ( array() === $recipients ) {
			return false;
		}

		$subject = sprintf(
			/* translators: 1: request type label, 2: requester name. */
			__( 'درخواست جدید: %1$s از %2$s', 'music-wave-core' ),
			$this->label( RequestPostType::type_labels(), (string) $request['type'] ),
			(string) $request['name']
		);
		$lines = array(
			sprintf(
				/* translators: %s: request reference. */
				__( 'یک درخواست تازه ثبت شد (%s).', 'music-wave-core' ),
				(string) $request['reference']
			),
			'',
			__( 'نام:', 'music-wave-core' ) . ' ' . (string) $request['name'],
			__( 'ایمیل:', 'music-wave-core' ) . ' ' . (string) $request['email'],
			__( 'نقش:', 'music-wave-core' ) . ' ' . $this->label( RequestPostType::role_labels(), (string) $request['role'] ),
			__( 'موضوع:', 'music-wave-core' ) . ' ' . (string) $request['subject'],
			__( 'بودجه:', 'music-wave-core' ) . ' ' . $this->label( RequestPostType::budget_labels(), (string) $request['budget'] ),
			__( 'زمان مورد نظر:', 'music-wave-core' ) . ' ' . ( '' !== (string) $request['deadline'] ? (string) $request['deadline'] : __( 'مشخص نشده', 'music-wave-core' ) ),
			'',
			(string) $request['message'],
			'',
			__( 'مدیریت درخواست:', 'music-wave-core' ) . ' ' . $admin_url,
		);

		$sent = false;
		foreach ( $recipients as $recipient ) {
			if ( $this->deliver( 'alert', $recipient, $subject, implode( "\n", $lines ), array( 'Reply-To: ' . $this->format_address( (string) $request['name'], (string) $request['email'] ) ), $request ) ) {
				$sent = true;
			}
		}

		return $sent;
	}

	/**
	 * A manager's reply to the requester.
	 *
	 * @param array<string, mixed> $request Normalized request.
	 */
	public function send_reply( array $request, string $body, string $custom_subject = '' ): bool {
		$body = trim( $body );
		if ( '' === $body || '' === (string) $request['email'] ) {
			return false;
		}

		$subject = '' !== trim( $custom_subject )
			? trim( $custom_subject )
			: sprintf(
				/* translators: 1: site name, 2: request subject. */
				__( '%1$s — پاسخ به درخواست شما: %2$s', 'music-wave-core' ),
				$this->site_name(),
				(string) $request['subject']
			);
		$lines = array(
			sprintf(
				/* translators: %s: requester name. */
				__( 'سلام %s،', 'music-wave-core' ),
				(string) $request['name']
			),
			'',
			$body,
			'',
			sprintf(
				/* translators: %s: request reference. */
				__( 'شمارهٔ پیگیری: %s', 'music-wave-core' ),
				(string) $request['reference']
			),
			'',
			$this->signature(),
		);

		return $this->deliver(
			'reply',
			(string) $request['email'],
			$subject,
			implode( "\n", $lines ),
			array( 'Reply-To: ' . $this->reply_to() ),
			$request
		);
	}

	/**
	 * Alert to managers when the requester sends a follow-up message in chat.
	 *
	 * @param array<string, mixed> $request Normalized request.
	 */
	public function send_user_reply_alert( array $request, string $body, string $admin_url ): bool {
		$recipients = $this->settings->notification_recipients();
		$body       = trim( $body );
		if ( array() === $recipients || '' === $body ) {
			return false;
		}

		$subject = sprintf(
			/* translators: 1: request reference, 2: requester name. */
			__( 'پیام جدید در درخواست %1$s از %2$s', 'music-wave-core' ),
			(string) $request['reference'],
			(string) $request['name']
		);
		$lines = array(
			sprintf(
				/* translators: %s: request subject. */
				__( 'پاسخ جدیدی در گفتگو برای «%s» ارسال شد.', 'music-wave-core' ),
				(string) $request['subject']
			),
			sprintf(
				/* translators: %s: request reference. */
				__( 'شمارهٔ پیگیری: %s', 'music-wave-core' ),
				(string) $request['reference']
			),
			'',
			$body,
			'',
			__( 'مشاهده و پاسخ در پیشخوان:', 'music-wave-core' ) . ' ' . $admin_url,
		);

		$sent = false;
		foreach ( $recipients as $recipient ) {
			if ( $this->deliver( 'user_reply', $recipient, $subject, implode( "\n", $lines ), array( 'Reply-To: ' . $this->format_address( (string) $request['name'], (string) $request['email'] ) ), $request ) ) {
				$sent = true;
			}
		}

		return $sent;
	}

	/**
	 * Deliver one message through wp_mail() after the extension filter.
	 *
	 * @param array<int, string>   $headers Extra headers.
	 * @param array<string, mixed> $request Request context.
	 */
	private function deliver( string $kind, string $to, string $subject, string $body, array $headers, array $request ): bool {
		$to = trim( $to );
		if ( '' === $to ) {
			return false;
		}

		/**
		 * Filter one outgoing request email.
		 *
		 * Return false to suppress delivery, or replace to/subject/body/headers
		 * to route the message through another transport.
		 *
		 * @param array<string, mixed>|false $message Message parts.
		 * @param string                     $kind    receipt|alert|reply.
		 * @param array<string, mixed>       $request Normalized request.
		 */
		$message = apply_filters(
			'music_wave_request_message',
			array(
				'to'      => $to,
				'subject' => $subject,
				'body'    => $body,
				'headers' => $headers,
			),
			$kind,
			$request
		);
		return \ManaCore\MusicWave\Core\Support\MailDispatcher::send( $message );
	}

	private function site_name(): string {
		$name = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
		if ( function_exists( 'wp_specialchars_decode' ) ) {
			$name = wp_specialchars_decode( $name, ENT_QUOTES );
		}

		return '' !== $name ? $name : 'MusicWave';
	}

	private function reply_to(): string {
		$address = $this->settings->reply_to();
		if ( '' === $address && function_exists( 'get_bloginfo' ) ) {
			$address = (string) get_bloginfo( 'admin_email' );
		}

		return $this->format_address( $this->site_name(), $address );
	}

	private function format_address( string $name, string $email ): string {
		$name = trim( str_replace( array( '"', "\r", "\n", '<', '>' ), '', $name ) );

		return '' !== $name ? '"' . $name . '" <' . $email . '>' : $email;
	}

	private function signature(): string {
		$signature = $this->settings->signature();

		return '' !== $signature ? $signature : sprintf(
			/* translators: %s: site name. */
			__( 'با احترام، تیم %s', 'music-wave-core' ),
			$this->site_name()
		);
	}

	/**
	 * @param array<string, string> $labels Label map.
	 */
	private function label( array $labels, string $key ): string {
		return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
	}

	private function quote( string $text ): string {
		$lines = explode( "\n", wordwrap( $text, 90, "\n", true ) );

		return implode(
			"\n",
			array_map(
				static function ( string $line ): string {
					return '> ' . $line;
				},
				$lines
			)
		);
	}
}
