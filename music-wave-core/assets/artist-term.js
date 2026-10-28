( function ( $, wp ) {
	'use strict';

	$( document ).on( 'click', '.mw-artist-image-select', function ( event ) {
		event.preventDefault();

		var frame = wp.media( {
			title: window.musicWaveArtistTerm.title,
			button: { text: window.musicWaveArtistTerm.button },
			library: { type: 'image' },
			multiple: false,
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			var imageUrl =
				attachment.sizes && attachment.sizes.thumbnail
					? attachment.sizes.thumbnail.url
					: attachment.url;
			$( '#mw_artist_image_id' ).val( attachment.id );
			$( '.mw-artist-image-preview' )
				.empty()
				.append( $( '<img>', { src: imageUrl, alt: '' } ) );
		} );
		frame.open();
	} );

	$( document ).on( 'click', '.mw-artist-image-remove', function ( event ) {
		event.preventDefault();
		$( '#mw_artist_image_id' ).val( '' );
		$( '.mw-artist-image-preview' ).empty();
	} );
} )( jQuery, window.wp );
