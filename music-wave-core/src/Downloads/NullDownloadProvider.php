<?php
declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Downloads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NullDownloadProvider implements DownloadProvider {
	public function deliver( string $asset_id, DownloadTokenClaims $claims ): bool {
		unset( $asset_id, $claims );
		return false; }
}
