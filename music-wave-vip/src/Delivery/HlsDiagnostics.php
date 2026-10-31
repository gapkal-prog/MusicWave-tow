<?php
/**
 * HLS readiness diagnostics for the admin UI.
 *
 * Reports whether ffmpeg/ffprobe exist, assets are packaged, keys are
 * wrapped, and edge connectivity is OK — without revealing secrets.
 *
 * @package ManaCore\MusicWave\Vip\Delivery
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Delivery;

use ManaCore\MusicWave\Vip\Hls\AssetKeyStore;
use ManaCore\MusicWave\Vip\Hls\HlsPackager;
use ManaCore\MusicWave\Vip\VipSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @immutable
 */
final class HlsDiagnostics {
	/** @var HlsPackager */
	private $packager;
	/** @var AssetKeyStore */
	private $key_store;
	/** @var array<string, mixed> */
	private $config;

	public function __construct( HlsPackager $packager, AssetKeyStore $key_store, array $config ) {
		$this->packager  = $packager;
		$this->key_store = $key_store;
		$this->config    = $config;
	}

	/**
	 * Full readiness snapshot.
	 *
	 * @return array<string, mixed>
	 */
	public function snapshot(): array {
		$config      = $this->config;
		$ffmpeg_ok   = $this->packager->available();
		$hls_enabled = 'enabled' === (string) ( $config['hls_enabled'] ?? 'disabled' );
		$encryption  = (string) ( $config['stream_encryption'] ?? 'off' );
		$provider    = (string) ( $config['delivery_provider'] ?? 'local' );
		$key_ready   = $this->key_store->has_any_key();
		$edge_ok     = $this->edge_ready();

		$effective_hls = 'edge_hls' === $provider && $hls_enabled && 'aes128' === $encryption;

		return array(
			'hls_enabled'      => $hls_enabled,
			'effective_hls'    => $effective_hls,
			'encryption'       => $encryption,
			'encryption_ok'    => 'aes128' === $encryption,
			'ffmpeg_available' => $ffmpeg_ok,
			'key_store_ready'  => $key_ready,
			'edge_ready'       => $edge_ok,
			'provider'         => $provider,
			'overall_ready'    => $effective_hls && $ffmpeg_ok && $key_ready,
			'notice'           => $this->notice( $effective_hls, $ffmpeg_ok, $key_ready, $edge_ok ),
		);
	}

	/**
	 * Edge signing configured for edge_hls/edge_signed.
	 */
	private function edge_ready(): bool {
		$secret = isset( $this->config['edge_signing_secret'] ) ? (string) $this->config['edge_signing_secret'] : '';
		$base   = isset( $this->config['edge_base_url'] ) ? (string) $this->config['edge_base_url'] : '';

		return '' !== $secret && strlen( $secret ) >= 32 && '' !== $base && 'https' === wp_parse_url( $base, PHP_URL_SCHEME );
	}

	/**
	 * Human-readable readiness notice (Persian).
	 */
	private function notice( bool $effective_hls, bool $ffmpeg_ok, bool $key_ready, bool $edge_ok ): string {
		if ( ! $effective_hls ) {
			return 'HLS/رمزنگاری در حالت تحویل فعلی اثر ندارد یا غیرفعال است.';
		}
		if ( ! $ffmpeg_ok ) {
			return 'ffmpeg/ffprobe روی سرور در دسترس نیست؛ بسته‌بندی HLS مسدود است.';
		}
		if ( ! $key_ready ) {
			return 'کلید HLS بسته‌بندی نشده است؛ ابتدا فایل‌ها را بسته‌بندی کنید.';
		}
		if ( ! $edge_ok ) {
			return 'اتصال لبه پیکربندی نشده است؛ edge_signing_secret و edge_base_url را بررسی کنید.';
		}

		return 'آماده: ffmpeg، کلید رمزنگاری و اتصال لبه فعال است.';
	}

	private function __clone() {}
}
