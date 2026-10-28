<?php
declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Downloads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TransientReplayStore implements ReplayStore {
	public function consume( string $token_id, int $expires_at ): bool {
		$key           = 'mw_download_replay_' . hash( 'sha256', $token_id );
		$stored_expiry = get_option( $key, false );
		if ( false !== $stored_expiry && (int) $stored_expiry >= time() ) {
			return false; }
		if ( false !== $stored_expiry ) {
			delete_option( $key ); }
		return add_option( $key, $expires_at, '', false );
	}
}
