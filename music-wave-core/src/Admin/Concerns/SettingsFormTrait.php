<?php
/**
 * Shared form rendering helpers for MusicWave admin pages.
 *
 * Provides DRY implementations of common table-row helpers used across
 * SettingsPage, DiagnosticsPage and VIP SettingsPage. All output is
 * escaped at render time; callers supply raw values.
 *
 * @package ManaCore\MusicWave\Core\Admin\Concerns
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Admin\Concerns;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait SettingsFormTrait {

	protected function settings_form_start( string $option_group = 'music_wave_core' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( $option_group );
	}

	/**
	 * @param array<string,string> $options
	 */
	protected function select_row( string $key, string $label, string $value, array $options, string $description, string $option_name = '' ): void {
		$option_name = '' !== $option_name ? $option_name : \ManaCore\MusicWave\Core\Support\Settings::OPTION;
		echo '<tr><th scope="row"><label for="mw-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><select id="mw-' . esc_attr( $key ) . '" name="' . esc_attr( $option_name ) . '[' . esc_attr( $key ) . ']">';
		foreach ( $options as $option => $option_label ) {
			echo '<option value="' . esc_attr( (string) $option ) . '" ' . selected( $value, (string) $option, false ) . '>' . esc_html( (string) $option_label ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html( $description ) . '</p></td></tr>';
	}

	protected function number_row( string $key, string $label, int $value, int $min, int $max, string $description, string $option_name = '' ): void {
		$option_name = '' !== $option_name ? $option_name : \ManaCore\MusicWave\Core\Support\Settings::OPTION;
		echo '<tr><th scope="row"><label for="mw-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input id="mw-' . esc_attr( $key ) . '" name="' . esc_attr( $option_name ) . '[' . esc_attr( $key ) . ']" type="number" min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '" value="' . esc_attr( (string) $value ) . '"><p class="description">' . esc_html( $description ) . '</p></td></tr>';
	}

	protected function text_row( string $key, string $label, string $value, string $option_name = '' ): void {
		$option_name = '' !== $option_name ? $option_name : \ManaCore\MusicWave\Core\Support\Settings::OPTION;
		echo '<tr><th scope="row"><label for="mw-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input class="regular-text" id="mw-' . esc_attr( $key ) . '" name="' . esc_attr( $option_name ) . '[' . esc_attr( $key ) . ']" type="text" value="' . esc_attr( $value ) . '"></td></tr>';
	}

	protected function url_row( string $key, string $label, string $value, string $option_name = '' ): void {
		$option_name = '' !== $option_name ? $option_name : \ManaCore\MusicWave\Core\Support\Settings::OPTION;
		echo '<tr><th scope="row"><label for="mw-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input class="regular-text code" id="mw-' . esc_attr( $key ) . '" name="' . esc_attr( $option_name ) . '[' . esc_attr( $key ) . ']" type="url" value="' . esc_attr( $value ) . '" placeholder="https://"></td></tr>';
	}

	/**
	 * @param array<string,string> $options
	 */
	protected function plain_select( string $name, string $label, array $options, string $selected_value ): void {
		echo '<label><span>' . esc_html( $label ) . '</span><select name="' . esc_attr( $name ) . '">';
		foreach ( $options as $value => $option_label ) {
			echo '<option value="' . esc_attr( (string) $value ) . '" ' . selected( $selected_value, (string) $value, false ) . '>' . esc_html( (string) $option_label ) . '</option>';
		}
		echo '</select></label>';
	}

	protected function stat( string $label, string $value, string $icon ): void {
		echo '<section class="mw-stat"><span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span><div><strong>' . esc_html( $value ) . '</strong><span>' . esc_html( $label ) . '</span></div></section>';
	}

	protected function status_row( string $label, bool $active, string $description ): void {
		echo '<div class="mw-status-row"><span class="mw-status mw-status--' . esc_attr( $active ? 'active' : 'inactive' ) . '">' . esc_html( $active ? __( 'آماده', 'music-wave-core' ) : __( 'در دسترس نیست', 'music-wave-core' ) ) . '</span><div><strong>' . esc_html( $label ) . '</strong><small>' . esc_html( $description ) . '</small></div></div>';
	}

	protected function action_link( string $url, string $label, string $description ): void {
		echo '<a class="mw-action-link" href="' . esc_url( $url ) . '"><strong>' . esc_html( $label ) . '</strong><small>' . esc_html( $description ) . '</small><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span></a>';
	}

	protected function job_form( string $action, string $label, string $class_name ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><button class="' . esc_attr( $class_name ) . '" type="submit">' . esc_html( $label ) . '</button></form>';
	}
}
