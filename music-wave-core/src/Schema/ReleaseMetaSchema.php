<?php
/**
 * Canonical release metadata schema.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReleaseMetaSchema {
	/** @var array<string, MetaDefinition>|null */
	private $definitions;

	/** @return array<string, MetaDefinition> */
	public function all(): array {
		if ( null === $this->definitions ) {
			$this->definitions = array(
				'mw_catalog_number'    => new MetaDefinition(
					'mw_catalog_number',
					'string',
					'',
					true,
					array(
						'label' => __( 'شماره کاتالوگ', 'music-wave-core' ),
						'type'  => 'text',
						'group' => 'release',
					)
				),
				'mw_album'             => new MetaDefinition(
					'mw_album',
					'string',
					'',
					true,
					array(
						'label' => __( 'آلبوم', 'music-wave-core' ),
						'type'  => 'text',
						'group' => 'release',
					)
				),
				'mw_release_year'      => new MetaDefinition(
					'mw_release_year',
					'integer',
					0,
					true,
					array(
						'label' => __( 'سال انتشار', 'music-wave-core' ),
						'type'  => 'number',
						'min'   => 1000,
						'max'   => 9999,
						'group' => 'release',
					)
				),
				'mw_release_date'      => new MetaDefinition(
					'mw_release_date',
					'string',
					'',
					true,
					array(
						'label' => __( 'تاریخ انتشار', 'music-wave-core' ),
						'type'  => 'date',
						'group' => 'release',
					)
				),
				'mw_isrc'              => new MetaDefinition(
					'mw_isrc',
					'string',
					'',
					true,
					array(
						'label' => __( 'ISRC', 'music-wave-core' ),
						'type'  => 'text',
						'group' => 'release',
					)
				),
				'mw_duration'          => new MetaDefinition(
					'mw_duration',
					'integer',
					0,
					true,
					array(
						'label' => __( 'مدت زمان بر حسب ثانیه', 'music-wave-core' ),
						'type'  => 'number',
						'min'   => 0,
						'group' => 'media',
					)
				),
				'mw_preview_duration'  => new MetaDefinition(
					'mw_preview_duration',
					'integer',
					30,
					true,
					array(
						'label' => __( 'مدت زمان پیش‌نمایش بر حسب ثانیه', 'music-wave-core' ),
						'type'  => 'number',
						'min'   => 10,
						'max'   => 120,
						'group' => 'media',
					)
				),
				'mw_bpm'               => new MetaDefinition(
					'mw_bpm',
					'integer',
					0,
					true,
					array(
						'label' => __( 'BPM', 'music-wave-core' ),
						'type'  => 'number',
						'min'   => 20,
						'max'   => 300,
						'group' => 'media',
					)
				),
				'mw_musical_key'       => new MetaDefinition(
					'mw_musical_key',
					'string',
					'',
					true,
					array(
						'label' => __( 'کلید موسیقی', 'music-wave-core' ),
						'type'  => 'text',
						'group' => 'media',
					)
				),
				'mw_lyrics_lrc'        => new MetaDefinition(
					'mw_lyrics_lrc',
					'string',
					'',
					true,
					array(
						'label' => __( 'متن هم‌زمان (LRC)', 'music-wave-core' ),
						'type'  => 'textarea',
						'group' => 'track',
						'help'  => __( 'هر خط: [mm:ss.xx] متن. ترجمهٔ اختیاری بعد از | یا // . نمونه: [00:12.40] شب شهر | Midnight city', 'music-wave-core' ),
					)
				),
				'mw_lyrics_offset'     => new MetaDefinition(
					'mw_lyrics_offset',
					'integer',
					0,
					true,
					array(
						'label' => __( 'آفست متن (میلی‌ثانیه)', 'music-wave-core' ),
						'type'  => 'number',
						'min'   => -10000,
						'max'   => 10000,
						'group' => 'track',
					)
				),
				'mw_explicit'          => new MetaDefinition(
					'mw_explicit',
					'boolean',
					false,
					true,
					array(
						'label' => __( 'محتوای صریح', 'music-wave-core' ),
						'type'  => 'checkbox',
						'group' => 'release',
					)
				),
				'mw_preview_url'       => new MetaDefinition(
					'mw_preview_url',
					'string',
					'',
					true,
					array(
						'label' => __( 'نشانی HTTPS پیش‌نمایش', 'music-wave-core' ),
						'type'  => 'url',
						'group' => 'media',
					)
				),
				'mw_track_number'      => new MetaDefinition(
					'mw_track_number',
					'integer',
					0,
					true,
					array(
						'label' => __( 'شماره قطعه', 'music-wave-core' ),
						'type'  => 'number',
						'min'   => 0,
						'group' => 'track',
					)
				),
				'mw_total_tracks'      => new MetaDefinition(
					'mw_total_tracks',
					'integer',
					0,
					true,
					array(
						'label' => __( 'تعداد کل قطعات', 'music-wave-core' ),
						'type'  => 'number',
						'min'   => 0,
						'group' => 'track',
					)
				),
				'mw_country'           => new MetaDefinition(
					'mw_country',
					'string',
					'',
					true,
					array(
						'label' => __( 'کشور', 'music-wave-core' ),
						'type'  => 'text',
						'group' => 'release',
					)
				),
				'mw_barcode'           => new MetaDefinition(
					'mw_barcode',
					'string',
					'',
					true,
					array(
						'label' => __( 'بارکد', 'music-wave-core' ),
						'type'  => 'text',
						'group' => 'release',
					)
				),
				'mw_publisher'         => new MetaDefinition(
					'mw_publisher',
					'string',
					'',
					true,
					array(
						'label' => __( 'ناشر', 'music-wave-core' ),
						'type'  => 'text',
						'group' => 'release',
					)
				),
				'mw_language'          => new MetaDefinition(
					'mw_language',
					'string',
					'',
					true,
					array(
						'label' => __( 'زبان', 'music-wave-core' ),
						'type'  => 'text',
						'group' => 'release',
					)
				),
				'mw_episode_number'    => new MetaDefinition(
					'mw_episode_number',
					'integer',
					0,
					true,
					array(
						'label' => __( 'شماره قسمت', 'music-wave-core' ),
						'type'  => 'number',
						'min'   => 0,
						'group' => 'podcast',
					)
				),
				'mw_season_number'     => new MetaDefinition(
					'mw_season_number',
					'integer',
					0,
					true,
					array(
						'label' => __( 'شماره فصل', 'music-wave-core' ),
						'type'  => 'number',
						'min'   => 0,
						'group' => 'podcast',
					)
				),
				'mw_credits'           => new MetaDefinition(
					'mw_credits',
					'array',
					array(),
					array(
						'schema' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'name' => array( 'type' => 'string' ),
									'role' => array( 'type' => 'string' ),
								),
							),
						),
					),
					array(
						'label' => __( 'عوامل انتشار', 'music-wave-core' ),
						'type'  => 'credits',
						'group' => 'release',
						'help'  => __( 'هر ردیف یک عامل تولید است: نام (الزامی) و نقش (اختیاری). همین نقش است که کنار نام نمایش داده میشود و در حالت «گروهبندی بر اساس نقش» عنوان هر گروه قرار میگیرد؛ مثلاً تهیهکننده.', 'music-wave-core' ),
					)
				),
				'mw_collection_items'  => new MetaDefinition(
					'mw_collection_items',
					'array',
					array(),
					array(
						'schema' => array(
							'type'    => 'array',
							'context' => array( 'view', 'edit' ),
							'items'   => array(
								'type'       => 'object',
								'required'   => array( 'release_id', 'position', 'role' ),
								'properties' => array(
									'release_id' => array(
										'type'    => 'integer',
										'minimum' => 1,
									),
									'position'   => array(
										'type'    => 'integer',
										'minimum' => 1,
									),
									'disc'       => array(
										'type'    => array( 'integer', 'null' ),
										'minimum' => 1,
									),
									'role'       => array(
										'type' => 'string',
										'enum' => array( 'track', 'episode' ),
									),
								),
							),
						),
					)
				),
				'mw_access_mode'       => new MetaDefinition(
					'mw_access_mode',
					'string',
					'public',
					true,
					array(
						'label'   => __( 'حالت دسترسی', 'music-wave-core' ),
						'type'    => 'select',
						'group'   => 'access',
						'options' => \ManaCore\MusicWave\Core\Access\AccessModePolicy::labels(),
					)
				),
				'mw_product_ids'       => new MetaDefinition(
					'mw_product_ids',
					'array',
					array(),
					false,
					array(
						'label' => __( 'محصولات WooCommerce', 'music-wave-core' ),
						'type'  => 'products',
						'group' => 'access',
					)
				),
				'mw_membership_levels' => new MetaDefinition(
					'mw_membership_levels',
					'array',
					array(),
					false,
					array(
						'label' => __( 'کلیدهای سطح عضویت', 'music-wave-core' ),
						'type'  => 'key_list',
						'group' => 'access',
					)
				),
				'mw_external_id'       => new MetaDefinition( 'mw_external_id', 'string', '', false ),
				'mw_metadata_source'   => new MetaDefinition( 'mw_metadata_source', 'string', '', false ),
				'mw_metadata_provider' => new MetaDefinition( 'mw_metadata_provider', 'string', '', false ),
				'mw_download_asset_id' => new MetaDefinition( 'mw_download_asset_id', 'string', '', false ),
				'mw_download_assets'   => new MetaDefinition(
					'mw_download_assets',
					'array',
					array(),
					false,
					array(
						'label' => __( 'فایل‌های حفاظت‌شده و کیفیت دانلود', 'music-wave-core' ),
						'type'  => 'protected_assets',
						'group' => 'access',
					)
				),
			);
		}

		return $this->definitions;
	}

	public function get( string $key ): ?MetaDefinition {
		$definitions = $this->all();

		return isset( $definitions[ $key ] ) ? $definitions[ $key ] : null;
	}

	/** @return array<string, MetaDefinition> */
	public function admin_fields(): array {
		return array_filter(
			$this->all(),
			static function ( MetaDefinition $definition ): bool {
				return $definition->is_admin_field();
			}
		);
	}
}
