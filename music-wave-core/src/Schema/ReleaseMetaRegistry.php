<?php
/**
 * WordPress release metadata registry.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Schema;

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReleaseMetaRegistry {
	/** @var ReleaseMetaSchema */
	private $schema;

	public function __construct( ReleaseMetaSchema $schema ) {
		$this->schema = $schema;
	}

	/**
	 * Register every release field from the canonical dictionary.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( $this->schema->all() as $definition ) {
			register_post_meta(
				ReleasePostType::KEY,
				$definition->key(),
				array(
					'type'              => $definition->type(),
					'single'            => true,
					'default'           => $definition->default_value(),
					'show_in_rest'      => $definition->show_in_rest(),
					'sanitize_callback' => array( $definition, 'sanitize' ),
					'auth_callback'     => array( self::class, 'can_edit' ),
				)
			);
		}
	}

	/**
	 * Restrict metadata writes to users who can edit the release.
	 *
	 * @param bool   $allowed Existing decision.
	 * @param string $meta_key Meta key.
	 * @param int    $post_id  Release ID.
	 * @return bool
	 */
	public static function can_edit( bool $allowed, string $meta_key, int $post_id ): bool {
		unset( $allowed, $meta_key );

		return current_user_can( 'edit_post', $post_id );
	}
}
