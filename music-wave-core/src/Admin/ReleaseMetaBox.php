<?php
/**
 * Schema-driven release metadata editor with bidirectional WooCommerce product
 * selector, inline single-release product builder, and non-destructive access mode handling.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Admin;

use ManaCore\MusicWave\Core\Access\AccessModePolicy;
use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Commerce\ProductMapper;
use ManaCore\MusicWave\Core\Contracts\ReleaseRepository;
use ManaCore\MusicWave\Core\Downloads\DirectLinkAsset;
use ManaCore\MusicWave\Core\Downloads\DirectLinkPermission;
use ManaCore\MusicWave\Core\Schema\MetaDefinition;
use ManaCore\MusicWave\Core\Schema\ReleaseMetaSchema;
use WP_Post;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReleaseMetaBox {
	private const NONCE_ACTION = 'music_wave_save_release';
	private const NONCE_NAME   = 'music_wave_release_nonce';
	private const NOTICE_KEY   = 'music_wave_release_notice_';

	/** Row index placeholder the credits repeater template carries for the browser to substitute. */
	private const CREDIT_INDEX_PLACEHOLDER = '__mw_credit_index__';

	private const GRID_FIELDS = array(
		'mw_catalog_number',
		'mw_album',
		'mw_release_year',
		'mw_release_date',
		'mw_isrc',
		'mw_duration',
		'mw_preview_duration',
		'mw_bpm',
		'mw_musical_key',
		'mw_lyrics_offset',
		'mw_explicit',
		'mw_preview_url',
		'mw_track_number',
		'mw_episode_number',
		'mw_season_number',
		'mw_access_mode',
	);

	/** @var ReleaseMetaSchema */
	private $schema;

	/** @var ReleaseRepository */
	private $repository;

	/** @var ProductMapper */
	private $product_mapper;

	public function __construct( ReleaseMetaSchema $schema, ReleaseRepository $repository, ProductMapper $product_mapper ) {
		$this->schema         = $schema;
		$this->repository     = $repository;
		$this->product_mapper = $product_mapper;
	}

	public function register(): void {
		add_meta_box( 'music-wave-release-details', __( 'جزئیات انتشار MusicWave', 'music-wave-core' ), array( $this, 'render' ), ReleasePostType::KEY, 'normal', 'high' );
	}

	public function enqueue_assets(): void {
		$screen = get_current_screen();
		if ( null === $screen || ReleasePostType::KEY !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style(
			'music-wave-admin-release-meta',
			MUSIC_WAVE_CORE_URL . 'assets/admin-release-meta.css',
			array(),
			MUSIC_WAVE_CORE_VERSION
		);
		wp_enqueue_script(
			'music-wave-admin-release-meta',
			MUSIC_WAVE_CORE_URL . 'assets/admin-release-meta.js',
			array(),
			MUSIC_WAVE_CORE_VERSION,
			true
		);

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );

		// Ensure `wc_enhanced_select_params` (including `search_products_nonce`) is present on `mw_release` screens too.
		if ( function_exists( 'wp_scripts' ) ) {
			$scripts = wp_scripts();
			if ( $scripts && empty( $scripts->get_data( 'wc-enhanced-select', 'data' ) ) ) {
				wp_localize_script(
					'wc-enhanced-select',
					'wc_enhanced_select_params',
					array(
						'ajax_url'              => admin_url( 'admin-ajax.php' ),
						'search_products_nonce' => wp_create_nonce( 'search-products' ),
					)
				);
			}
		}
	}

	public function render_notice(): void {
		$user_id = get_current_user_id();
		$notice  = get_transient( self::NOTICE_KEY . $user_id );
		if ( ! is_string( $notice ) || '' === $notice ) {
			return;
		}

		delete_transient( self::NOTICE_KEY . $user_id );
		echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
	}

	public function render( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		echo '<div class="mw-admin-fields">';
		echo '<p class="description">' . esc_html__( 'اطلاعات کاتالوگ مستقل از WooCommerce است. محصولات فقط این انتشار را می‌فروشند یا اجازهٔ دسترسی به آن را می‌دهند.', 'music-wave-core' ) . '</p>';

		$grid_open = false;
		foreach ( $this->schema->admin_fields() as $definition ) {
			if ( in_array( $definition->admin()['type'] ?? '', array( 'protected_asset', 'protected_assets' ), true ) ) {
				continue;
			}
			$in_grid = in_array( $definition->key(), self::GRID_FIELDS, true );
			if ( $in_grid && ! $grid_open ) {
				echo '<div class="mw-admin-fields__grid">';
				$grid_open = true;
			} elseif ( ! $in_grid && $grid_open ) {
				echo '</div>';
				$grid_open = false;
			}
			$this->render_field( $definition, $this->repository->get( $post->ID, $definition->key() ) );
		}
		if ( $grid_open ) {
			echo '</div>';
		}

		if ( $post->ID > 0 ) {
			$types         = wp_get_post_terms( $post->ID, 'mw_release_type', array( 'fields' => 'slugs' ) );
			$types         = is_array( $types ) ? array_map( 'sanitize_key', $types ) : array();
			$is_collection = ! empty( array_intersect( array( 'album', 'ep', 'mix', 'playlist', 'podcast_show' ), $types ) );
			echo '<hr class="mw-release-details-divider">';
			echo '<div id="music-wave-delivery-manager" data-release-id="' . esc_attr( (string) $post->ID ) . '" data-is-collection="' . esc_attr( $is_collection ? '1' : '0' ) . '"></div>';
			if ( $is_collection ) {
				$role = in_array( 'podcast_show', $types, true ) ? 'episode' : 'track';
				echo '<div id="music-wave-collection-manager" data-release-id="' . esc_attr( (string) $post->ID ) . '" data-role="' . esc_attr( $role ) . '"></div>';
			}
		} else {
			echo '<p class="description">' . esc_html__( 'این انتشار را یک بار ذخیره کنید تا فایل‌ها و موارد مجموعه حفاظت‌شده را اضافه کنید.', 'music-wave-core' ) . '</p>';
		}

		echo '</div>';
	}

	public function save( int $post_id, WP_Post $post ): void {
		if ( ReleasePostType::KEY !== $post->post_type || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_NAME ] ) && is_string( $_POST[ self::NONCE_NAME ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$old_product_ids = $this->repository->product_ids( $post_id );
		foreach ( $this->schema->admin_fields() as $definition ) {
			$key = $definition->key();
			if ( ! array_key_exists( $key, $_POST ) ) {
				if ( 'mw_explicit' === $key ) {
					$this->repository->update( $post_id, $key, false );
				}
				continue;
			}

			$value = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( 'mw_download_assets' === $key ) {
				$value = $this->download_assets_from_submission( $value, $post_id );
			}
			$this->repository->update( $post_id, $key, $value );
		}

		// Optional inline creation of a single-release WooCommerce product during save.
		$quick_price = isset( $_POST['mw_quick_product_price'] ) && is_scalar( $_POST['mw_quick_product_price'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['mw_quick_product_price'] ) )
			: '';
		$quick_sale  = isset( $_POST['mw_quick_product_sale_price'] ) && is_scalar( $_POST['mw_quick_product_sale_price'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['mw_quick_product_sale_price'] ) )
			: '';
		if ( '' !== trim( $quick_price ) ) {
			$this->product_mapper->create_product_for_release( $post_id, $quick_price, $quick_sale );
		}

		$this->enforce_access_invariants( $post_id );
		$this->product_mapper->sync_reverse_index( $post_id, $old_product_ids, $this->repository->product_ids( $post_id ) );
	}

	private function enforce_access_invariants( int $post_id ): void {
		$requested = (string) $this->repository->get( $post_id, 'mw_access_mode' );
		$resolved  = AccessModePolicy::resolve_for_release( $this->repository, $post_id, $requested );

		if ( null === $resolved['mode'] ) {
			$this->restrict( $post_id, $resolved['notice'] );
			return;
		}

		if ( $resolved['mode'] !== $requested ) {
			$this->repository->update( $post_id, 'mw_access_mode', $resolved['mode'] );
		}

		if ( '' !== $resolved['notice'] ) {
			set_transient( self::NOTICE_KEY . get_current_user_id(), $resolved['notice'], MINUTE_IN_SECONDS );
		}
	}

	private function restrict( int $post_id, string $notice ): void {
		$this->repository->update( $post_id, 'mw_access_mode', 'restricted' );
		if ( '' !== $notice ) {
			set_transient( self::NOTICE_KEY . get_current_user_id(), $notice, MINUTE_IN_SECONDS );
		}
	}

	/** @param mixed $value */
	private function render_field( MetaDefinition $definition, $value ): void {
		$config     = $definition->admin();
		$key        = $definition->key();
		$type       = (string) $config['type'];
		$field_attr = ' data-mw-field-key="' . esc_attr( $key ) . '"';

		echo '<div class="mw-admin-field mw-admin-field--' . esc_attr( $type ) . '"' . $field_attr . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $field_attr escaped immediately above.
		echo '<label for="' . esc_attr( $key ) . '"><strong>' . esc_html( (string) $config['label'] ) . '</strong></label><br>';

		if ( 'select' === $type ) {
			echo '<select id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">';
			foreach ( $config['options'] as $option_value => $option_label ) {
				echo '<option value="' . esc_attr( (string) $option_value ) . '" ' . selected( $value, $option_value, false ) . '>' . esc_html( (string) $option_label ) . '</option>';
			}
			echo '</select>';
			if ( 'mw_access_mode' === $key ) {
				echo '<span class="description mw-access-mode-hint" data-mw-access-hint aria-live="polite"></span>';
			}
		} elseif ( 'checkbox' === $type ) {
			echo '<input type="checkbox" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="1" ' . checked( (bool) $value, true, false ) . '>';
		} elseif ( 'products' === $type ) {
			$this->render_products( $key, is_array( $value ) ? $value : array() );
		} elseif ( 'credits' === $type ) {
			$this->render_credits_repeater( $key, is_array( $value ) ? $value : array() );
		} elseif ( 'protected_asset' === $type ) {
			$this->render_protected_asset( $key, $value );
		} elseif ( 'protected_assets' === $type ) {
			$this->render_protected_assets( $key, $value );
		} elseif ( 'textarea' === $type ) {
			echo '<textarea class="widefat" rows="10" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" dir="auto">' . esc_textarea( is_scalar( $value ) ? (string) $value : '' ) . '</textarea>';
		} else {
			$input_type = in_array( $type, array( 'date', 'number', 'url' ), true ) ? $type : 'text';
			$display    = 'key_list' === $type && is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			echo '<input class="widefat" type="' . esc_attr( $input_type ) . '" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $display ) . '"';
			foreach ( array( 'min', 'max' ) as $attribute ) {
				if ( isset( $config[ $attribute ] ) ) {
					echo ' ' . esc_attr( $attribute ) . '="' . esc_attr( (string) $config[ $attribute ] ) . '"';
				}
			}
			echo '>';
			if ( 'mw_membership_levels' === $key ) {
				$this->render_membership_level_picker( is_array( $value ) ? $value : array() );
			}
		}

		if ( isset( $config['help'] ) && is_string( $config['help'] ) && '' !== $config['help'] ) {
			echo '<span class="description">' . esc_html( $config['help'] ) . '</span>';
		}

		echo '</div>';
	}

	/**
	 * Render the ordered «عوامل انتشار» repeater bound to `mw_credits`.
	 *
	 * Rows post as `mw_credits[<index>][name|role]` so the payload reaches the
	 * sanitizer as the same list of pairs the REST route and the renderer read.
	 * The empty scalar input printed first is what lets an emptied repeater clear
	 * the stored value: with no rows left, PHP parses it as `''` instead of the key
	 * being absent, which `save()` treats as "leave the meta alone".
	 *
	 * @param string              $key     Meta key.
	 * @param array<int, mixed>   $credits Stored credits.
	 */
	private function render_credits_repeater( string $key, array $credits ): void {
		echo '<div class="mw-credits-repeater" data-mw-credits-repeater="' . esc_attr( $key ) . '">';
		echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="">';
		echo '<div class="mw-credits-repeater__rows" data-mw-credit-rows>';

		$position = 0;
		foreach ( $credits as $credit ) {
			$this->render_credit_row( $key, (string) $position, is_array( $credit ) ? $credit : array() );
			++$position;
		}

		echo '</div>';
		echo '<template data-mw-credit-template>';
		$this->render_credit_row( $key, self::CREDIT_INDEX_PLACEHOLDER, array() );
		echo '</template>';
		echo '<p class="mw-credits-repeater__actions"><button type="button" class="button" data-mw-credit-add>' . esc_html__( 'افزودن عامل', 'music-wave-core' ) . '</button></p>';
		echo '</div>';
	}

	/**
	 * Render one credit row; the template reuses it with a placeholder index.
	 *
	 * @param string              $key     Meta key.
	 * @param string              $index   Numeric row index, or the template placeholder.
	 * @param array<string, mixed> $credit Stored credit, possibly malformed.
	 */
	private function render_credit_row( string $key, string $index, array $credit ): void {
		$name = isset( $credit['name'] ) && is_scalar( $credit['name'] ) ? (string) $credit['name'] : '';
		$role = isset( $credit['role'] ) && is_scalar( $credit['role'] ) ? (string) $credit['role'] : '';

		echo '<span class="mw-credits-repeater__row" data-mw-credit-row data-mw-credit-index="' . esc_attr( $index ) . '">';
		echo '<input type="text" class="mw-credits-repeater__name" name="' . esc_attr( $key . '[' . $index . '][name]' ) . '" value="' . esc_attr( $name ) . '" placeholder="' . esc_attr__( 'نام عامل تولید', 'music-wave-core' ) . '" dir="auto">';
		echo '<input type="text" class="mw-credits-repeater__role" name="' . esc_attr( $key . '[' . $index . '][role]' ) . '" value="' . esc_attr( $role ) . '" placeholder="' . esc_attr__( 'نقش، مثلاً تهیهکننده', 'music-wave-core' ) . '" dir="auto">';
		echo '<button type="button" class="button-link mw-credits-repeater__remove" data-mw-credit-remove aria-label="' . esc_attr__( 'حذف این عامل', 'music-wave-core' ) . '"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>';
		echo '</span>';
	}

	/**
	 * Render interactive VIP plan level quick-select chips beneath `#mw_membership_levels`.
	 *
	 * @param array<int, mixed> $selected Currently saved level slugs.
	 */
	private function render_membership_level_picker( array $selected ): void {
		$available = apply_filters( 'music_wave_available_membership_levels', array() );
		if ( ! is_array( $available ) || empty( $available ) ) {
			$available = array(
				AccessModePolicy::DEFAULT_VIP_LEVEL => __( 'همه مشترکین فعال VIP (mw_vip)', 'music-wave-core' ),
			);
		}
		$active = array_map( 'sanitize_key', array_map( 'strval', $selected ) );
		echo '<span class="mw-membership-picker" data-mw-level-picker="mw_membership_levels">';
		foreach ( $available as $slug => $label ) {
			$clean = sanitize_key( (string) $slug );
			if ( '' === $clean ) {
				continue;
			}
			$is_on = in_array( $clean, $active, true );
			echo '<button type="button" class="button button-small mw-level-chip' . ( $is_on ? ' is-active' : '' ) . '" data-mw-level-slug="' . esc_attr( $clean ) . '" aria-pressed="' . ( $is_on ? 'true' : 'false' ) . '">' . esc_html( (string) $label ) . '</button>';
		}
		echo '</span>';
		echo '<span class="description">' . esc_html__( 'با کلیک روی هر طرح، کلید آن اضافه یا حذف می‌شود. انتخاب «mw_vip» دسترسی را به تمام مشترکین فعال VIP می‌دهد و انتخاب کلید یک طرح خاص (مانند vip-12m) آن را به همان طرح محدود می‌کند.', 'music-wave-core' ) . '</span>';
	}

	/**
	 * @param mixed $value Stored asset identifier.
	 */
	private function render_protected_asset( string $key, $value ): void {
		echo '<input class="widefat" type="text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( is_scalar( $value ) ? (string) $value : '' ) . '" autocomplete="off">';
		echo '<span class="description">' . esc_html__( 'شناسهٔ حفاظت‌شده (مانند vip:... یا local:album/track.zip) یا لینک مستقیم کنترل‌شده با پیشوند direct:https://... را وارد کنید؛ نشانی عمومی خام پذیرفته نمی‌شود.', 'music-wave-core' ) . '</span>';
	}

	/**
	 * @param mixed $value Stored variants.
	 */
	private function render_protected_assets( string $key, $value ): void {
		$lines = array();
		if ( is_array( $value ) ) {
			foreach ( $value as $asset ) {
				if ( ! is_array( $asset ) || empty( $asset['key'] ) || empty( $asset['label'] ) || empty( $asset['asset_id'] ) ) {
					continue;
				}
				$lines[] = (string) $asset['key'] . ' | ' . (string) $asset['label'] . ' | ' . (string) $asset['asset_id'];
			}
		}

		echo '<textarea class="widefat" rows="5" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">' . esc_textarea( implode( "\n", $lines ) ) . '</textarea>';
		echo '<span class="description">' . esc_html__( 'در هر خط یک کیفیت: quality-key | برچسب مشتری | شناسهٔ دارایی. برای مخزن VIP از vip:... یا local:... و برای لینک مستقیم کنترل‌شده از direct:https://cdn.example.com/track.mp3 استفاده کنید (توجه: در حالت direct: محافظت سطح مخزن VIP روی سرور مقصد اعمال نمی‌شود).', 'music-wave-core' ) . '</span>';
	}

	/**
	 * @param mixed $value   Posted textarea value.
	 * @param int   $post_id Release post ID being saved.
	 * @return array<int, array<string, string>>
	 */
	private function download_assets_from_submission( $value, int $post_id = 0 ): array {
		if ( ! is_scalar( $value ) ) {
			return array();
		}

		$existing_ids = array();
		if ( $post_id > 0 ) {
			$stored = $this->repository->get( $post_id, 'mw_download_assets' );
			foreach ( is_array( $stored ) ? $stored : array() as $row ) {
				if ( is_array( $row ) && isset( $row['asset_id'] ) && is_scalar( $row['asset_id'] ) ) {
					$existing_ids[] = (string) $row['asset_id'];
				}
			}
		}

		$assets = array();
		$rows   = preg_split( '/\r\n|\r|\n/', (string) $value );
		foreach ( is_array( $rows ) ? $rows : array() as $line ) {
			$parts = array_map( 'trim', explode( '|', $line, 3 ) );
			if ( 3 !== count( $parts ) ) {
				continue;
			}
			$raw_id = $parts[2];
			if ( DirectLinkAsset::has_direct_scheme( $raw_id ) ) {
				$validation = DirectLinkAsset::validate( $raw_id );
				if ( ! $validation['valid'] ) {
					set_transient( self::NOTICE_KEY . get_current_user_id(), $validation['message'], MINUTE_IN_SECONDS );
					continue;
				}
				if ( ! in_array( $validation['asset_id'], $existing_ids, true ) && ! DirectLinkPermission::can_manage( $post_id ) && ! current_user_can( 'manage_options' ) ) {
					set_transient( self::NOTICE_KEY . get_current_user_id(), __( 'شما مجوز ثبت یا تغییر لینک مستقیم HTTPS را ندارید.', 'music-wave-core' ), MINUTE_IN_SECONDS );
					continue;
				}
				$raw_id = $validation['asset_id'];
			} elseif ( 1 === preg_match( '/^https?:\/\//i', $raw_id ) ) {
				set_transient(
					self::NOTICE_KEY . get_current_user_id(),
					__( 'نشانی عمومی خام پذیرفته نشد؛ برای لینک مستقیم کنترل‌شده از پیشوند direct:https://... استفاده کنید.', 'music-wave-core' ),
					MINUTE_IN_SECONDS
				);
				continue;
			}

			$assets[] = array(
				'key'      => $parts[0],
				'label'    => $parts[1],
				'asset_id' => $raw_id,
			);
		}

		return $assets;
	}

	/** @param array<int, int> $selected_ids */
	private function render_products( string $key, array $selected_ids ): void {
		if ( ! post_type_exists( 'product' ) ) {
			echo '<span class="description">' . esc_html__( 'WooCommerce را برای نگاشت محصولات یا فروش تکی انتشار فعال کنید.', 'music-wave-core' ) . '</span>';
			return;
		}

		$selected_ids = array_values( array_filter( array_map( 'absint', $selected_ids ) ) );
		$options      = $this->candidate_products( $selected_ids );

		echo '<input type="hidden" name="' . esc_attr( $key ) . '[]" value="0">';
		echo '<select class="wc-product-search widefat" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '[]" multiple="multiple" size="' . esc_attr( (string) min( 6, max( 3, count( $options ) ) ) ) . '" data-action="woocommerce_json_search_products" data-placeholder="' . esc_attr__( 'جست‌وجو یا انتخاب محصول WooCommerce…', 'music-wave-core' ) . '">';
		foreach ( $options as $product_id => $label ) {
			echo '<option value="' . esc_attr( (string) $product_id ) . '" ' . selected( in_array( (int) $product_id, $selected_ids, true ), true, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';

		if ( ! empty( $selected_ids ) ) {
			echo '<div class="mw-linked-products">';
			foreach ( $selected_ids as $pid ) {
				if ( 'product' !== get_post_type( $pid ) ) {
					continue;
				}
				$edit_url = get_edit_post_link( $pid );
				$view_url = get_permalink( $pid );
				echo '<span class="mw-linked-product-chip"><strong>#' . esc_html( (string) $pid ) . ' ' . esc_html( get_the_title( $pid ) ) . '</strong>';
				if ( is_string( $edit_url ) && '' !== $edit_url ) {
					echo ' <a href="' . esc_url( $edit_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'ویرایش', 'music-wave-core' ) . '</a>';
				}
				if ( is_string( $view_url ) && '' !== $view_url ) {
					echo ' · <a href="' . esc_url( $view_url ) . '" target="_blank" rel="noopener">↗</a>';
				}
				echo '</span>';
			}
			echo '</div>';
		}

		echo '<div class="mw-quick-product-box">';
		echo '<strong>' . esc_html__( 'ساخت سریع محصول تکی WooCommerce برای این انتشار (اختیاری):', 'music-wave-core' ) . '</strong> ';
		echo '<input type="text" name="mw_quick_product_price" value="" placeholder="' . esc_attr__( 'قیمت عادی (مثلاً 99000)', 'music-wave-core' ) . '" class="regular-text" style="max-width:185px;"> ';
		echo '<input type="text" name="mw_quick_product_sale_price" value="" placeholder="' . esc_attr__( 'قیمت فروش ویژه (اختیاری)', 'music-wave-core' ) . '" class="regular-text" style="max-width:185px;">';
		echo '<span class="description" style="display:block;margin-top:4px;">' . esc_html__( 'در صورت وارد کردن قیمت و ذخیرهٔ انتشار، یک محصول مجازی WooCommerce با عنوان و تصویر جلد همین انتشار ساخته و به‌طور خودکار دوطرفه به آن پیوند داده می‌شود.', 'music-wave-core' ) . '</span>';
		echo '</div>';
	}

	/**
	 * Load up to 150 WooCommerce products so the selector always works immediately
	 * even before or without Select2 AJAX initialization.
	 *
	 * @param array<int, int> $selected_ids Always included first.
	 * @return array<int, string>
	 */
	private function candidate_products( array $selected_ids ): array {
		$query = new WP_Query(
			array(
				'post_type'              => 'product',
				'post_status'            => array( 'publish', 'private', 'draft' ),
				'posts_per_page'         => 100,
				'orderby'                => 'ID',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$ids = array_values( array_unique( array_merge( $selected_ids, array_map( 'absint', is_array( $query->posts ) ? $query->posts : array() ) ) ) );
		$out = array();
		foreach ( $ids as $product_id ) {
			if ( $product_id < 1 || 'product' !== get_post_type( $product_id ) ) {
				continue;
			}
			$title     = trim( (string) get_the_title( $product_id ) );
			$raw_price = get_post_meta( $product_id, '_price', true );
			$vip_level = get_post_meta( $product_id, '_mw_vip_plan_level', true );
			$suffix    = '';
			if ( is_string( $vip_level ) && '' !== $vip_level ) {
				$suffix = ' [VIP: ' . $vip_level . ']';
			} elseif ( '' !== $raw_price && is_numeric( $raw_price ) ) {
				$suffix = ' (' . number_format_i18n( (float) $raw_price ) . ')';
			}
			$out[ $product_id ] = sprintf(
				'#%d — %s%s',
				$product_id,
				'' !== $title ? $title : __( '(بدون عنوان)', 'music-wave-core' ),
				$suffix
			);
		}

		return $out;
	}
}
