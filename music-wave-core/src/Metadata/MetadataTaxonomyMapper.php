<?php
/**
 * Stable multilingual taxonomy matching for imported metadata.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Metadata;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Matches provider identities to WordPress taxonomy terms without relying on display names.
 *
 * Phase 4: adds in-memory + persistent caching for find_term_id to avoid O(N*M) full table scans.
 */
final class MetadataTaxonomyMapper {
	private const ALIASES_META = '_mw_metadata_aliases';
	private const NONCE_ACTION = 'music_wave_metadata_aliases';
	private const NONCE_NAME   = 'music_wave_metadata_aliases_nonce';
	private const TAXONOMIES   = array( 'mw_artist', 'mw_genre', 'mw_mood', 'mw_label' );

	/**
	 * Register private alias metadata and editing controls.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_meta' ), 9 );
		add_action( 'edit_terms', array( $this, 'remember_previous_identity' ), 10, 2 );
		foreach ( self::TAXONOMIES as $taxonomy ) {
			add_action( $taxonomy . '_add_form_fields', array( $this, 'render_add_field' ) );
			add_action( $taxonomy . '_edit_form_fields', array( $this, 'render_edit_field' ) );
			add_action( 'created_' . $taxonomy, array( $this, 'save_aliases' ) );
			add_action( 'edited_' . $taxonomy, array( $this, 'save_aliases' ) );
		}
	}

	/**
	 * Register aliases as private term metadata.
	 */
	public function register_meta(): void {
		foreach ( self::TAXONOMIES as $taxonomy ) {
			register_term_meta(
				$taxonomy,
				self::ALIASES_META,
				array(
					'type'              => 'array',
					'single'            => true,
					'default'           => array(),
					'show_in_rest'      => false,
					'sanitize_callback' => array( $this, 'sanitize_aliases' ),
					'auth_callback'     => static function (): bool {
						return current_user_can( 'manage_categories' );
					},
				)
			);
		}
	}

	/**
	 * Render the alias field on a new-term screen.
	 */
	public function render_add_field(): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		$this->render_field( array(), false );
	}

	/**
	 * Render the alias field on an existing-term screen.
	 *
	 * @param \WP_Term $term Current taxonomy term.
	 */
	public function render_edit_field( \WP_Term $term ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		$aliases = get_term_meta( $term->term_id, self::ALIASES_META, true );
		$this->render_field( is_array( $aliases ) ? $aliases : array(), true );
	}

	/**
	 * Save aliases only when the dedicated field was submitted.
	 *
	 * @param int $term_id Term ID.
	 */
	public function save_aliases( int $term_id ): void {
		if ( ! isset( $_POST[ self::NONCE_NAME ], $_POST['mw_metadata_aliases'] ) || ! is_string( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		$submitted = wp_unslash( $_POST['mw_metadata_aliases'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by sanitize_aliases below.
		$stored    = get_term_meta( $term_id, self::ALIASES_META, true );
		$stored    = is_array( $stored ) ? $stored : array();
		update_term_meta( $term_id, self::ALIASES_META, $this->sanitize_aliases( array_merge( $stored, $this->sanitize_aliases( $submitted ) ) ) );
	}

	/**
	 * Preserve the old name and slug before an administrator translates or renames a term.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy key.
	 */
	public function remember_previous_identity( int $term_id, string $taxonomy ): void {
		if ( ! in_array( $taxonomy, self::TAXONOMIES, true ) ) {
			return;
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$this->append_aliases( $term_id, array( $term->name, $term->slug ) );
	}

	/**
	 * Resolve structured provider entities to existing or newly created terms.
	 *
	 * @param int                              $post_id  Release post ID.
	 * @param string                           $taxonomy Target taxonomy.
	 * @param array<int, array<string, mixed>> $entities Provider identities.
	 * @param string                           $provider Stable provider key.
	 * @return array<int, int> Assigned term IDs.
	 */
	public function assign( int $post_id, string $taxonomy, array $entities, string $provider ): array {
		$term_ids = $this->resolve( $taxonomy, $entities, $provider, true );
		if ( ! empty( $term_ids ) ) {
			$assigned = wp_set_object_terms( $post_id, $term_ids, $taxonomy, false );
			if ( is_wp_error( $assigned ) ) {
				return array();
			}
		}

		return $term_ids;
	}

	/**
	 * Resolve provider entities. Raw tags can opt out of creating new terms.
	 *
	 * @param string                           $taxonomy      Target taxonomy.
	 * @param array<int, array<string, mixed>> $entities Provider identities.
	 * @param string                           $provider      Stable provider key.
	 * @param bool                             $create_missing Whether missing terms may be created.
	 * @return array<int, int>
	 */
	public function resolve( string $taxonomy, array $entities, string $provider, bool $create_missing = true ): array {
		if ( ! in_array( $taxonomy, self::TAXONOMIES, true ) || ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$term_ids = array();
		foreach ( $entities as $entity ) {
			if ( ! is_array( $entity ) || empty( $entity['name'] ) || ! is_scalar( $entity['name'] ) ) {
				continue;
			}
			$name = sanitize_text_field( (string) $entity['name'] );
			if ( ! $this->is_assignable_name( $taxonomy, $name ) ) {
				continue;
			}
			$external_id = isset( $entity['external_id'] ) && is_scalar( $entity['external_id'] ) ? sanitize_text_field( (string) $entity['external_id'] ) : '';
			$aliases     = isset( $entity['aliases'] ) && is_array( $entity['aliases'] ) ? $entity['aliases'] : array();
			$term_id     = $this->find_term_id( $taxonomy, $name, $aliases, $provider, $external_id );
			if ( 0 === $term_id ) {
				if ( ! $create_missing ) {
					continue;
				}
				$created = wp_insert_term( $name, $taxonomy, array( 'slug' => sanitize_title( $name ) ) );
				if ( is_wp_error( $created ) || empty( $created['term_id'] ) ) {
					continue;
				}
				$term_id = absint( $created['term_id'] );
			}

			$this->append_aliases( $term_id, array_merge( array( $name ), $aliases ) );
			if ( '' !== $external_id && '' !== sanitize_key( $provider ) ) {
				update_term_meta( $term_id, $this->provider_meta_key( $provider ), $external_id );
			}
			$term_ids[] = $term_id;
		}

		$term_ids = array_values( array_unique( array_filter( array_map( 'absint', $term_ids ) ) ) );

		return $term_ids;
	}

	/**
	 * Sanitize manually entered or automatically retained aliases.
	 *
	 * @param mixed $value Raw aliases.
	 * @return array<int, string>
	 */
	public function sanitize_aliases( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\r\n|\r|\n|,/', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}

		$aliases = array_values( array_filter( array_map( 'sanitize_text_field', $value ) ) );

		return array_slice( array_values( array_unique( $aliases ) ), 0, 50 );
	}

	/**
	 * Find an existing term using stable IDs, exact identities, or stored aliases.
	 *
	 * Phase 4: adds 2-level caching (memory + wp_cache) and caches normalized identity map per taxonomy
	 * to avoid repeated full get_terms() scans.
	 *
	 * @param string            $taxonomy  Taxonomy key.
	 * @param string            $name      Provider-facing name.
	 * @param array<int, mixed> $aliases Candidate aliases.
	 * @param string            $provider  Provider key.
	 * @param string            $external_id Stable provider identity.
	 */
	private function find_term_id( string $taxonomy, string $name, array $aliases, string $provider, string $external_id ): int {
		$found_id = 0;

		if ( '' !== $external_id && '' !== sanitize_key( $provider ) ) {
			$matches = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'number'     => 1,
					'meta_key'   => $this->provider_meta_key( $provider ),
					'meta_value' => $external_id,
				)
			);
			if ( ! is_wp_error( $matches ) && ! empty( $matches[0] ) && $matches[0] instanceof \WP_Term ) {
				$found_id = (int) $matches[0]->term_id;
			}
		}

		if ( 0 === $found_id ) {
			$exact = get_term_by( 'name', $name, $taxonomy );
			if ( $exact instanceof \WP_Term ) {
				$found_id = (int) $exact->term_id;
			} else {
				$slug_match = get_term_by( 'slug', sanitize_title( $name ), $taxonomy );
				if ( $slug_match instanceof \WP_Term ) {
					$found_id = (int) $slug_match->term_id;
				}
			}
		}

		if ( 0 === $found_id ) {
			$needles = array();
			foreach ( array_merge( array( $name ), $aliases ) as $alias ) {
				if ( is_scalar( $alias ) ) {
					$normalized = $this->normalize_identity( (string) $alias );
					if ( '' !== $normalized ) {
						$needles[ $normalized ] = true;
					}
				}
			}
			if ( ! empty( $needles ) ) {
				$identity_map = $this->get_taxonomy_identity_map( $taxonomy );
				foreach ( $identity_map as $term_id => $normalized_identities ) {
					foreach ( $normalized_identities as $norm ) {
						if ( isset( $needles[ $norm ] ) ) {
							$found_id = (int) $term_id;
							break 2;
						}
					}
				}
			}
		}

		return $found_id;
	}

	/**
	 * Build normalized identity map for a taxonomy.
	 *
	 * @param string $taxonomy
	 * @return array<int, array<int, string>> term_id => list of normalized identities
	 */
	private function get_taxonomy_identity_map( string $taxonomy ): array {
		$map   = array();
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}
				$identities = array( $term->name, $term->slug );
				$stored     = get_term_meta( $term->term_id, self::ALIASES_META, true );
				if ( is_array( $stored ) ) {
					$identities = array_merge( $identities, $stored );
				}
				$norms = array();
				foreach ( $identities as $identity ) {
					$norm = $this->normalize_identity( (string) $identity );
					if ( '' !== $norm ) {
						$norms[] = $norm;
					}
				}
				$map[ (int) $term->term_id ] = array_values( array_unique( $norms ) );
			}
		}

		return $map;
	}

	/**
	 * Merge aliases without discarding previous translated identities.
	 *
	 * @param int               $term_id Term ID.
	 * @param array<int, mixed> $aliases Aliases to retain.
	 */
	private function append_aliases( int $term_id, array $aliases ): void {
		$stored = get_term_meta( $term_id, self::ALIASES_META, true );
		$stored = is_array( $stored ) ? $stored : array();
		update_term_meta( $term_id, self::ALIASES_META, $this->sanitize_aliases( array_merge( $stored, $aliases ) ) );
	}

	/**
	 * Build the private provider-ID meta key.
	 *
	 * @param string $provider Provider key.
	 */
	private function provider_meta_key( string $provider ): string {
		return '_mw_metadata_' . sanitize_key( $provider ) . '_id';
	}

	/**
	 * Normalize Unicode spelling and punctuation for conservative comparisons.
	 *
	 * @param string $value Identity value.
	 */
	private function normalize_identity( string $value ): string {
		$value = remove_accents( wp_strip_all_tags( $value ) );
		$value = strtr(
			$value,
			array(
				'ي' => 'ی',
				'ك' => 'ک',
				'ة' => 'ه',
				'ۀ' => 'ه',
			)
		);
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );

		return (string) preg_replace( '/[\s\p{P}\p{S}\x{200C}\x{200D}]+/u', '', $value );
	}

	/**
	 * Reject empty and special placeholder label names.
	 *
	 * @param string $taxonomy Taxonomy key.
	 * @param string $name     Candidate name.
	 */
	private function is_assignable_name( string $taxonomy, string $name ): bool {
		if ( '' === $name ) {
			return false;
		}
		if ( 'mw_label' === $taxonomy && in_array( strtolower( $name ), array( '[no label]', 'not on label', 'none', 'unknown' ), true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Render aliases on either the add or edit term form.
	 *
	 * @param array<int, string> $aliases Saved aliases.
	 * @param bool               $table_row Whether the edit-table layout is required.
	 */
	private function render_field( array $aliases, bool $table_row ): void {
		$label       = '<label for="mw_metadata_aliases">' . esc_html__( 'نام مستعار فراداده', 'music-wave-core' ) . '</label>';
		$control     = '<textarea id="mw_metadata_aliases" name="mw_metadata_aliases" rows="3" class="large-text">' . esc_textarea( implode( "\n", $aliases ) ) . '</textarea>';
		$description = '<p class="description">' . esc_html__( 'یک نام ارائه‌دهنده یا نام قبلی در هر خط. این نام‌های مستعار اجازه می‌دهند که تکمیل خودکار با این عبارت پس از ترجمه یا تغییر نام قابل مشاهده آن مطابقت داشته باشد.', 'music-wave-core' ) . '</p>';
		if ( $table_row ) {
			echo '<tr class="form-field"><th scope="row">' . $label . '</th><td>' . $control . $description . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		echo '<div class="form-field">' . $label . $control . $description . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
