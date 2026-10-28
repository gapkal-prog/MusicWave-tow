<?php
declare(strict_types=1);

namespace ManaCore\MusicWave\Theme\Patterns;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composes the shared chrome of the bundled demo patterns.
 *
 * The demo packs are ordinary block patterns: each file keeps its own literal
 * block markup, so the Site Editor can still parse, restyle and re-save every
 * section. Only what is genuinely repeated between them lives here — the pack
 * badge, the opening intro and the mid-page section headings — so the heading
 * vocabulary changes in one place instead of six.
 *
 * Every method returns a self-contained fragment whose block comments open and
 * close together. Pattern files therefore never have to balance a comment that
 * PHP produced, which keeps their raw markup readable by the template
 * integrity checks exactly as if it had been typed by hand.
 *
 * Callers pass unescaped text; escaping and attribute encoding happen here.
 *
 * No fragment carries `fontSize`: emitting only `className` keeps WordPress from
 * deriving a `has-{size}-font-size` class the surrounding stylesheet would then
 * have to fight, so the demo type scale stays owned by the `.mw-demo` rules.
 *
 * @package MusicWave
 */
final class DemoComposer {

	/**
	 * Compose the pack badge, eyebrow, heading and description.
	 *
	 * @param string $eyebrow     Short kicker above the heading.
	 * @param string $heading     Section heading, rendered at level 2.
	 * @param string $description Supporting copy under the heading.
	 * @return string
	 */
	public function intro( string $eyebrow, string $heading, string $description ): string {
		return $this->group(
			'mw-demo__intro',
			$this->paragraph( __( 'دموی قابلیتها', 'musicwave' ), 'mw-demo__badge' )
				. $this->paragraph( $eyebrow, 'mw-eyebrow mw-eyebrow--accent' )
				. $this->heading( $heading )
				. $this->paragraph( $description, 'mw-muted' )
		);
	}

	/**
	 * Compose a section heading for the middle of a demo.
	 *
	 * Mirrors the `music-wave/section-head` composition used by the shipped
	 * section patterns, so a demo section and a site section look identical.
	 *
	 * @param string $eyebrow     Short kicker above the heading.
	 * @param string $heading     Section heading, rendered at level 2.
	 * @param string $description Supporting copy under the heading.
	 * @return string
	 */
	public function section_head( string $eyebrow, string $heading, string $description ): string {
		return $this->block(
			'music-wave/section-head',
			array( 'align' => 'wide' ),
			'<div class="wp-block-music-wave-section-head alignwide mw-section-head">'
				. $this->group(
					'mw-section-head__text',
					$this->paragraph( $eyebrow, 'mw-eyebrow' )
						. $this->heading( $heading )
						. $this->paragraph( $description ),
					'default'
				)
				. '</div>'
		);
	}

	/**
	 * Wrap inner markup in a paired block comment.
	 *
	 * An empty payload yields a self-closing comment, which is what a dynamic
	 * block with no saved markup needs.
	 *
	 * @param string $name       Block name, e.g. `group`.
	 * @param array  $attributes Block attributes to serialise as JSON.
	 * @param string $markup     Saved markup, already including its wrapper.
	 * @return string
	 */
	private function block( string $name, array $attributes, string $markup ): string {
		$comment = '<!-- wp:' . $name . $this->attributes( $attributes );

		if ( '' === $markup ) {
			return $comment . ' /-->' . "\n";
		}

		return $comment . ' -->' . "\n" . $markup . '<!-- /wp:' . $name . ' -->' . "\n";
	}

	/**
	 * Serialise block attributes into a leading JSON payload.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	private function attributes( array $attributes ): string {
		if ( array() === $attributes ) {
			return '';
		}

		return ' ' . (string) wp_json_encode( $attributes );
	}

	/**
	 * Compose a group container.
	 *
	 * @param string $class_name Extra wrapper class.
	 * @param string $inner      Inner markup.
	 * @param string $layout     Layout type, `constrained` by default.
	 * @return string
	 */
	private function group( string $class_name, string $inner, string $layout = 'constrained' ): string {
		return $this->block(
			'group',
			array(
				'className' => $class_name,
				'layout'    => array( 'type' => $layout ),
			),
			'<div class="wp-block-group ' . $class_name . '">' . $inner . '</div>'
		);
	}

	/**
	 * Compose a paragraph, or nothing when the text is blank.
	 *
	 * @param string $text       Paragraph text.
	 * @param string $class_name Extra paragraph class.
	 * @return string
	 */
	private function paragraph( string $text, string $class_name = '' ): string {
		if ( '' === trim( $text ) ) {
			return '';
		}

		$attributes = '' === $class_name ? array() : array( 'className' => $class_name );
		$class_attr = '' === $class_name ? '' : ' class="' . esc_attr( $class_name ) . '"';

		return $this->block(
			'paragraph',
			$attributes,
			'<p' . $class_attr . '>' . esc_html( $text ) . '</p>'
		);
	}

	/**
	 * Compose a level-two heading.
	 *
	 * Templates already render the page title at level one, so demos never
	 * introduce a competing `h1`.
	 *
	 * @param string $text Heading text.
	 * @return string
	 */
	private function heading( string $text ): string {
		if ( '' === trim( $text ) ) {
			return '';
		}

		return $this->block(
			'heading',
			array( 'level' => 2 ),
			'<h2 class="wp-block-heading">' . esc_html( $text ) . '</h2>'
		);
	}
}