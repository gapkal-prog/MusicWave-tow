<?php
/**
 * Non-intrusive editor-only diagnostic notice presenter for dynamic blocks.
 *
 * Strictly inert on public frontend requests (`! BlockSupport::is_editor_context()`
 * always returns an empty string) so internal editorial diagnostics never leak
 * to visitors, while Site/Block Editor previews clearly explain missing release
 * assets, tracks, audio streams, lyrics, credits, or access gating.
 *
 * @package MusicWaveCore
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks\Concerns;

use ManaCore\MusicWave\Core\Blocks\BlockSupport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders accessible, theme-aware diagnostic badges inside editor block previews.
 */
final class EditorDiagnosticNotice {
	/**
	 * Render an editor-only diagnostic message, or empty string on public pages.
	 *
	 * @param string $message Translated diagnostic reason.
	 * @param bool   $inline  True for compact action buttons (`<span>`), false for panels/sections (`<p>`).
	 */
	public static function render( string $message, bool $inline = false ): string {
		$clean = trim( $message );
		if ( '' === $clean || ! BlockSupport::is_editor_context() ) {
			return '';
		}

		$icon = '<svg class="mw-editor-diagnostic__icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>';

		if ( $inline ) {
			return '<span class="mw-editor-diagnostic mw-editor-diagnostic--inline" role="note" title="' . esc_attr( $clean ) . '">'
				. $icon
				. '<span class="mw-editor-diagnostic__text">' . esc_html( $clean ) . '</span>'
				. '</span>';
		}

		return '<p class="mw-editor-diagnostic" role="note">'
			. $icon
			. '<span class="mw-editor-diagnostic__text">' . esc_html( $clean ) . '</span>'
			. '</p>';
	}
}
