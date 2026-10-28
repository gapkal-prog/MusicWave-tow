<?php
/** Provider must stream the private asset and terminate the request on success. */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Downloads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface DownloadProvider {
	public function deliver( string $asset_id, DownloadTokenClaims $claims ): bool;
}
