<?php
/**
 * Bounded operational audit trail for MusicWave VIP grant, renewal, revocation, and expiry events.
 *
 * Stored in `music_wave_vip_audit_log` (autoload disabled, capped at 500 entries)
 * and cleaned up automatically when `delete_data_on_uninstall === 'enabled'`.
 *
 * @package ManaCore\MusicWave\Vip
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VipAuditLog {
	public const OPTION      = 'music_wave_vip_audit_log';
	private const MAX_EVENTS = 500;

	/**
	 * Append an operational VIP audit record.
	 *
	 * @param array<string, int|string> $context Metadata (`level`, `expires_at`, `order_id`, `actor_id`).
	 */
	public static function record( string $event, int $user_id, array $context = array() ): void {
		$log = get_option( self::OPTION, array() );
		$log = is_array( $log ) ? $log : array();

		array_unshift(
			$log,
			array(
				'at'         => time(),
				'event'      => sanitize_key( $event ),
				'user_id'    => absint( $user_id ),
				'level'      => isset( $context['level'] ) ? sanitize_key( (string) $context['level'] ) : '',
				'expires_at' => isset( $context['expires_at'] ) ? absint( $context['expires_at'] ) : 0,
				'order_id'   => isset( $context['order_id'] ) ? absint( $context['order_id'] ) : 0,
				'actor_id'   => isset( $context['actor_id'] ) ? absint( $context['actor_id'] ) : 0,
			)
		);

		if ( count( $log ) > self::MAX_EVENTS ) {
			$log = array_slice( $log, 0, self::MAX_EVENTS );
		}

		update_option( self::OPTION, $log, false );
	}

	/**
	 * Read recent audit entries, optionally filtered by user or event slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function entries( int $limit = 50, int $user_id = 0, string $event_type = '' ): array {
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$filtered = array();
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( $user_id > 0 && absint( $item['user_id'] ?? 0 ) !== $user_id ) {
				continue;
			}
			if ( '' !== $event_type && (string) ( $item['event'] ?? '' ) !== $event_type ) {
				continue;
			}
			$filtered[] = $item;
			if ( count( $filtered ) >= max( 1, $limit ) ) {
				break;
			}
		}

		return $filtered;
	}

	public static function event_label( string $event ): string {
		$labels = array(
			'order_grant'                   => __( 'خرید / تمدید اشتراک', 'music-wave-vip' ),
			'order_granted'                 => __( 'خرید / تمدید اشتراک', 'music-wave-vip' ),
			'manual_grant'                  => __( 'اعطای دستی مدیر', 'music-wave-vip' ),
			'admin_granted'                 => __( 'اعطای دستی مدیر', 'music-wave-vip' ),
			'admin_extended'                => __( 'تمدید دستی مدیر', 'music-wave-vip' ),
			'manual_revoke'                 => __( 'لغو دستی مدیر', 'music-wave-vip' ),
			'admin_revoked'                 => __( 'لغو دستی مدیر', 'music-wave-vip' ),
			'order_revoke'                  => __( 'لغو / استرداد سفارش', 'music-wave-vip' ),
			'order_revoked'                 => __( 'لغو / استرداد سفارش', 'music-wave-vip' ),
			'expired'                       => __( 'انقضای زمانی', 'music-wave-vip' ),
			'local_root_selfheal_htaccess'  => __( 'بازسازی خودکار .htaccess پوشهٔ محافظتشده', 'music-wave-vip' ),
			'local_root_selfheal_webconfig' => __( 'بازسازی خودکار web.config پوشهٔ محافظتشده', 'music-wave-vip' ),
		);

		return isset( $labels[ $event ] ) ? $labels[ $event ] : $event;
	}
}
