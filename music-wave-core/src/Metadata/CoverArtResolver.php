<?php
/**
 * Resolves the best cover art from multiple candidates.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Metadata;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CoverArtResolver {
	/**
	 * Pick the best candidate by score.
	 *
	 * @param CoverArtCandidate[] $candidates
	 */
	public function best( array $candidates ): ?CoverArtCandidate {
		$candidates = array_filter(
			$candidates,
			static function ( CoverArtCandidate $c ): bool {
				return '' !== $c->url;
			}
		);
		if ( empty( $candidates ) ) {
			return null;
		}

		usort(
			$candidates,
			static function ( CoverArtCandidate $a, CoverArtCandidate $b ): int {
				return $b->score() <=> $a->score();
			}
		);

		return $candidates[0];
	}

	/**
	 * Collect candidates from a MetadataResult's existing cover_url.
	 *
	 * @return CoverArtCandidate[]
	 */
	public function from_result( MetadataResult $result ): array {
		if ( '' === $result->cover_url ) {
			return array();
		}

		// Try to infer dimensions from URL patterns when possible, else 0.
		$width = 0;
		if ( preg_match( '/\/(\d+)x(\d+)\//', $result->cover_url, $m ) ) {
			$width = (int) $m[1];
		} elseif ( false !== strpos( $result->cover_url, 'coverartarchive.org' ) ) {
			// CAA 1200 thumbnail URL contains -1200.
			if ( false !== strpos( $result->cover_url, '-1200' ) ) {
				$width = 1200;
			} elseif ( false !== strpos( $result->cover_url, '-500' ) ) {
				$width = 500;
			}
		} elseif ( false !== strpos( $result->cover_url, 'i.scdn.co' ) ) {
			$width = 640; // Spotify typical.
		}

		return array(
			new CoverArtCandidate(
				$result->cover_url,
				$width,
				0,
				$result->provider,
				true,
				false,
				false
			),
		);
	}

	/**
	 * Try to resolve cover via all enabled providers (fallback chain).
	 *
	 * @param MetadataResult     $result   Current result (may lack cover).
	 * @param MetadataProvider[] $providers Enabled providers.
	 * @return CoverArtCandidate[]
	 */
	public function collect_from_providers( MetadataResult $result, array $providers ): array {
		$candidates = $this->from_result( $result );

		foreach ( $providers as $provider ) {
			if ( ! $provider instanceof MetadataProvider || ! $provider->is_enabled() ) {
				continue;
			}
			// Skip the provider that already produced the result if it already has cover.
			if ( $provider->name() === $result->provider && '' !== $result->cover_url ) {
				continue;
			}

			try {
				$url = $provider->cover_for( $result );
				if ( '' !== $url && $url !== $result->cover_url ) {
					$candidates[] = new CoverArtCandidate(
						$url,
						0,
						0,
						$provider->name(),
						true,
						false,
						false
					);
				}
			} catch ( \Throwable $e ) {
				continue;
			}
		}

		return $candidates;
	}
}
