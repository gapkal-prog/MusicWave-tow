<?php
/**
 * Editor-only routes for private release download qualities.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Downloads;

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Contracts\ReleaseRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DownloadAssetRoutes {
	/** Maximum number of quality variants stored per release. */
	public const MAX_ASSETS = 20;

	/** @var ReleaseRepository */
	private $releases;

	public function __construct( ReleaseRepository $releases ) {
		$this->releases = $releases;
	}

	/**
	 * Register editor-only quality management routes.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register authenticated asset-variant routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'music-wave/v1',
			'/releases/(?P<id>\d+)/download-assets',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_edit' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'can_edit' ),
				),
			)
		);
	}

	/**
	 * Check that the requested release is editable by the current user.
	 *
	 * Phase 5 fix: robust check — allow if user can edit_post OR has edit_mw_releases + manage_options fallback.
	 *
	 * @param WP_REST_Request $request Route request.
	 * @return bool
	 */
	public function can_edit( WP_REST_Request $request ): bool {
		$release_id = absint( $request->get_param( 'id' ) );
		if ( $release_id < 1 || ReleasePostType::KEY !== get_post_type( $release_id ) ) {
			return false;
		}
		if ( current_user_can( 'edit_post', $release_id ) ) {
			return true;
		}
		// Fallback for cases where primitive caps not yet granted (pre-migration).
		if ( current_user_can( 'edit_mw_releases' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Return private variants only to authorized editors.
	 *
	 * @param WP_REST_Request $request Route request.
	 * @return WP_REST_Response
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( array( 'items' => $this->assets( absint( $request->get_param( 'id' ) ) ) ), 200 );
	}

	/**
	 * Validate and persist opaque provider variants.
	 *
	 * @param WP_REST_Request $request Route request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $request ) {
		$assets = $request->get_param( 'assets' );
		if ( ! is_array( $assets ) ) {
			return new WP_Error( 'mw_download_assets_invalid', __( 'کیفیت‌های دانلود باید یک آرایه باشد.', 'music-wave-core' ), array( 'status' => 400 ) );
		}

		// Normalize before anything else so authorization and persistence both
		// operate on exactly the same bounded, sanitized variant list.
		$assets = array_slice( $this->normalize_assets( $assets ), 0, self::MAX_ASSETS );

		// Secure direct-link assignments: raw http/https URLs are rejected;
		// `direct:` URLs are validated and require the assign capability.
		$secure_error = $this->secure_direct_link_assets( $assets );
		if ( null !== $secure_error ) {
			return $secure_error;
		}

		$release_id = absint( $request->get_param( 'id' ) );
		$denied     = $this->deny_unauthorized_assignments( $release_id, $assets );
		if ( null !== $denied ) {
			return $denied;
		}

		$this->releases->update( $release_id, 'mw_download_assets', $assets );
		$this->releases->delete( $release_id, 'mw_download_asset_id' );

		return new WP_REST_Response( array( 'items' => $this->assets( $release_id ) ), 200 );
	}

	/**
	 * Authorization stopgap: editors may keep or reorder the asset IDs already
	 * assigned to a release, but introducing a new protected asset identifier
	 * requires provider-side authorization or the dedicated asset capability,
	 * so guessed identifiers cannot be attached to editable releases
	 * (PROJECT_PLAN.md Stage 1 deliverable 5).
	 *
	 * @param int                  $release_id Release being edited.
	 * @param array<int, mixed>    $assets     Submitted variant definitions.
	 * @return WP_Error|null Error when an assignment is not authorized.
	 */
	private function deny_unauthorized_assignments( int $release_id, array $assets ): ?WP_Error {
		$existing = $this->assigned_asset_ids( $release_id );

		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || ! isset( $asset['asset_id'] ) || ! is_scalar( $asset['asset_id'] ) ) {
				continue;
			}
			$raw_asset_id = (string) $asset['asset_id'];
			$asset_id     = AssetIdSanitizer::sanitize( $raw_asset_id );
			if ( '' === $asset_id || in_array( $asset_id, $existing, true ) ) {
				continue;
			}
			// `direct:` assets are authorized by secure_direct_link_assets(),
			// not by the provider-assignment gate (they are not protected assets).
			if ( 0 === strpos( $asset_id, 'direct:' ) ) {
				continue;
			}

			/**
			 * Allow the active download provider to authorize one asset assignment.
			 *
			 * Return true to authorize, false to reject, or null when the
			 * provider has no opinion (the dedicated capability then decides).
			 *
			 * @param bool|null $authorized Provider decision.
			 * @param string    $asset_id   Opaque asset identifier.
			 * @param int       $release_id Release being edited.
			 * @param int       $user_id    Acting user.
			 */
			$authorized = apply_filters( 'music_wave_can_assign_download_asset', null, $asset_id, $release_id, get_current_user_id() );
			if ( true === $authorized ) {
				continue;
			}
			// Phase 5 fix: fallback to manage_options for admin.
			if ( false === $authorized || ( ! current_user_can( 'manage_mw_protected_assets' ) && ! current_user_can( 'manage_options' ) ) ) {
				return new WP_Error(
					'mw_download_asset_forbidden',
					__( 'شما مجاز به اختصاص این دارایی حفاظت‌شده نیستید.', 'music-wave-core' ),
					array(
						'status'   => 403,
						'asset_id' => $asset_id,
					)
				);
			}
		}

		return null;
	}

	/**
	 * Validate `direct:` asset URLs and reject raw http/https asset IDs.
	 *
	 * @return WP_Error|null
	 */
	private function secure_direct_link_assets( array $assets ): ?WP_Error {
		$validator = new DirectLinkUrlValidator();

		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || ! isset( $asset['asset_id'] ) || ! is_scalar( $asset['asset_id'] ) ) {
				continue;
			}
			$asset_id = AssetIdSanitizer::sanitize( (string) $asset['asset_id'] );

			// Raw public URLs are never stored as asset IDs.
			$scheme = wp_parse_url( $asset_id, PHP_URL_SCHEME );
			if ( in_array( strtolower( (string) $scheme ), array( 'http', 'https' ), true ) ) {
				return new WP_Error(
					'mw_raw_url_not_allowed',
					__( 'نشانی عمومی مجاز نیست. از شناسهٔ دارایی EOP (مثل vip:…) یا پیشوند direct:<https-url> استفاده کنید.', 'music-wave-core' ),
					array( 'status' => 400 )
				);
			}

			// Validate and normalize `direct:` URLs; require the assign capability.
			if ( 0 === strpos( $asset_id, 'direct:' ) ) {
				$url = substr( $asset_id, strlen( 'direct:' ) );
				if ( null === $validator->validate( $url ) ) {
					return new WP_Error(
						'mw_direct_link_invalid',
						__( 'لینک مستقیم نامعتبر است: فقط HTTPS، بدون کاراکتر کنترل، بدون userinfo/IP/سرور محلی، و port ۴۴۳.', 'music-wave-core' ),
						array( 'status' => 400 )
					);
				}
				if ( ! DirectLinkPermission::can_assign() ) {
					return new WP_Error(
						'mw_direct_link_forbidden',
						__( 'شما مجاز به اختصاص لینک مستقیم نیستید.', 'music-wave-core' ),
						array( 'status' => 403 )
					);
				}
			}
		}

		return null;
	}

	/**
	 * Collect the asset identifiers already stored on a release.
	 *
	 * @return array<int, string>
	 */
	private function assigned_asset_ids( int $release_id ): array {
		$ids   = array();
		$value = $this->releases->get( $release_id, 'mw_download_assets' );
		foreach ( is_array( $value ) ? $value : array() as $asset ) {
			if ( is_array( $asset ) && isset( $asset['asset_id'] ) && is_scalar( $asset['asset_id'] ) ) {
				$raw   = (string) $asset['asset_id'];
				$ids[] = AssetIdSanitizer::sanitize( $raw );
			}
		}

		$legacy = $this->releases->get( $release_id, 'mw_download_asset_id' );
		if ( is_string( $legacy ) && '' !== $legacy ) {
			$ids[] = AssetIdSanitizer::sanitize( $legacy );
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Read only complete variants for the editor response.
	 *
	 * @return array<int, array<string, int|string>>
	 */
	private function assets( int $release_id ): array {
		$value  = $this->releases->get( $release_id, 'mw_download_assets' );
		$assets = is_array( $value ) ? $value : array();
		if ( empty( $assets ) ) {
			$legacy = $this->releases->get( $release_id, 'mw_download_asset_id' );
			if ( is_string( $legacy ) && '' !== $legacy ) {
				$assets[] = array(
					'key'      => 'standard',
					'label'    => __( 'دانلود استاندارد', 'music-wave-core' ),
					'asset_id' => $legacy,
				);
			}
		}

		return $this->normalize_assets( $assets );
	}

	/**
	 * Sanitize raw variant definitions, dropping incomplete entries.
	 *
	 * @param array<int, mixed> $assets Raw stored or submitted variants.
	 * @return array<int, array<string, int|string>>
	 */
	private function normalize_assets( array $assets ): array {
		$result = array();

		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || empty( $asset['key'] ) || empty( $asset['label'] ) || empty( $asset['asset_id'] ) ) {
				continue;
			}
			$raw_asset_id = (string) $asset['asset_id'];
			$normalized   = array(
				'key'      => sanitize_key( (string) $asset['key'] ),
				'label'    => sanitize_text_field( (string) $asset['label'] ),
				'asset_id' => AssetIdSanitizer::sanitize( $raw_asset_id ),
			);
			$format       = isset( $asset['format'] ) ? sanitize_key( strtolower( (string) $asset['format'] ) ) : '';
			$file_name    = isset( $asset['file_name'] ) ? sanitize_file_name( (string) $asset['file_name'] ) : '';
			$file_key     = isset( $asset['file_key'] ) ? sanitize_key( (string) $asset['file_key'] ) : '';
			$file_label   = isset( $asset['file_label'] ) ? sanitize_text_field( (string) $asset['file_label'] ) : '';
			$bitrate      = isset( $asset['bitrate'] ) ? absint( $asset['bitrate'] ) : 0;
			$duration     = isset( $asset['duration'] ) ? absint( $asset['duration'] ) : 0;
			$file_size    = isset( $asset['file_size'] ) ? absint( $asset['file_size'] ) : 0;

			if ( '' !== $format ) {
				$normalized['format'] = $format;
			}
			if ( '' !== $file_name ) {
				$normalized['file_name'] = $file_name;
			}
			if ( '' !== $file_key && '' !== $file_label ) {
				$normalized['file_key']   = $file_key;
				$normalized['file_label'] = $file_label;
			}
			if ( $bitrate > 0 ) {
				$normalized['bitrate'] = $bitrate;
			}
			if ( $duration > 0 ) {
				$normalized['duration'] = $duration;
			}
			if ( $file_size > 0 ) {
				$normalized['file_size'] = $file_size;
			}

			$result[] = $normalized;
		}

		return $result;
	}
}
