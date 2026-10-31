<?php
/**
 * Dependency-free domain smoke tests.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$schema = new ManaCore\MusicWave\Core\Schema\ReleaseMetaSchema();
mw_assert_same( 31, count( $schema->all() ), 'Schema must expose every canonical release and metadata lookup field.' );
mw_assert_same( false, $schema->get( 'mw_product_ids' )->show_in_rest(), 'Product mappings must remain private.' );
mw_assert_same( 'Discovery', $schema->get( 'mw_album' )->sanitize( ' Discovery ' ), 'Album names must be safely normalized.' );
mw_assert_same( 2026, $schema->get( 'mw_release_year' )->sanitize( '2026' ), 'Release years must be stored as valid integers.' );
mw_assert_same( 0, $schema->get( 'mw_release_year' )->sanitize( '26' ), 'Malformed release years must be rejected.' );
mw_assert_same( '2026-08-06', $schema->get( 'mw_release_date' )->sanitize( '2026-08-06' ), 'Valid ISO dates must survive.' );
mw_assert_same( '', $schema->get( 'mw_release_date' )->sanitize( '2026-02-30' ), 'Invalid calendar dates must be rejected.' );
mw_assert_same( 'USRC17607839', $schema->get( 'mw_isrc' )->sanitize( 'US-RC1-76-07839' ), 'ISRC values must be normalized to their canonical form.' );
mw_assert_same( '', $schema->get( 'mw_isrc' )->sanitize( 'invalid' ), 'Malformed ISRC values must be rejected.' );
mw_assert_same( 0, $schema->get( 'mw_bpm' )->sanitize( 999 ), 'Out-of-range BPM must fail safely.' );
mw_assert_same( 12, $schema->get( 'mw_track_number' )->sanitize( '12' ), 'Track numbers must be normalized as integers.' );
mw_assert_same( array( 10, 11 ), $schema->get( 'mw_product_ids' )->sanitize( array( 10, '11', 10, 0 ) ), 'Product IDs must be normalized.' );
mw_assert_same( 'restricted', $schema->get( 'mw_access_mode' )->sanitize( 'unknown' ), 'Unknown access modes must fail closed.' );
mw_assert_same( 'restricted', $schema->get( 'mw_access_mode' )->sanitize( array( 'purchase' ) ), 'Malformed scalar access input must fail closed.' );
mw_assert_same( 30, $schema->get( 'mw_preview_duration' )->sanitize( '3' ), 'Preview limits below the safe range must use the default.' );
mw_assert_same(
	array( array( 'name' => 'سارا احمدی', 'role' => 'تهیهکننده' ) ),
	$schema->get( 'mw_credits' )->sanitize( array( array( 'name' => 'سارا احمدی', 'role' => 'تهیهکننده' ) ) ),
	'Credit roles are free text and must survive sanitization: the credits block prints them verbatim and groups by them.'
);
mw_assert_same(
	array( array( 'name' => 'Ali', 'role' => '' ) ),
	$schema->get( 'mw_credits' )->sanitize( array( array( 'role' => 'producer' ), array( 'name' => 'Ali' ), 'junk' ) ),
	'Nameless credits must be dropped and a missing role must stay empty rather than become a placeholder.'
);
mw_assert_same( 'credits', $schema->get( 'mw_credits' )->admin()['type'] ?? '', '«عوامل انتشار» must be fillable from the release metabox, not REST-only.' );
$year_only_result = ManaCore\MusicWave\Core\Metadata\MetadataResult::from_array(
	array(
		'title'        => 'Year-only release',
		'artist'       => 'Example Artist',
		'release_date' => '2024',
		'duration'     => 95,
	)
);
mw_assert_same( '2024', $year_only_result->year, 'A provider year must remain available for the dedicated year field.' );
mw_assert_same( '', $year_only_result->release_date, 'A provider year must not be converted into a fabricated January 1 date.' );
mw_assert_same( '', $year_only_result->date_for_meta(), 'Year-only results must not populate the full release date field.' );
mw_assert_same( 95, $year_only_result->duration, 'Normalized provider durations must remain in seconds.' );
$secure_cover_result = ManaCore\MusicWave\Core\Metadata\MetadataResult::from_array(
	array(
		'title'     => 'Secure cover',
		'artist'    => 'Example Artist',
		'cover_url' => 'http://coverartarchive.org/release/example/front-1200',
	)
);
mw_assert_same( 'https://coverartarchive.org/release/example/front-1200', $secure_cover_result->cover_url, 'Cover URLs must be upgraded to HTTPS for secure editor previews and imports.' );
$structured_result = ManaCore\MusicWave\Core\Metadata\MetadataResult::from_array(
	array(
		'title'       => 'Structured metadata',
		'artist'      => '',
		'description' => '<p>Useful factual description.</p><script>unsafe()</script>',
		'artists'     => array(
			array(
				'name'        => 'Artist One',
				'external_id' => 'artist-1',
			),
			array(
				'name'        => 'Artist One',
				'external_id' => 'duplicate',
			),
			array(
				'name'        => 'Artist Two',
				'external_id' => 'artist-2',
			),
		),
		'genres'      => array( 'Rock', 'Electronic' ),
		'moods'       => array( 'Energetic', 'Dreamy' ),
		'tags'        => array( 'seen live' ),
		'labels'      => array(
			array(
				'name'        => 'Example Label',
				'external_id' => 'label-1',
			),
		),
	)
);
mw_assert_same( 'Artist One, Artist Two', $structured_result->artist, 'Structured artists must populate the legacy display field without duplicates.' );
mw_assert_same( 2, count( $structured_result->genres ), 'Structured genre identities must be retained for taxonomy mapping.' );
mw_assert_same( 2, count( $structured_result->moods ), 'Structured mood identities must be retained for taxonomy mapping.' );
mw_assert_same( 1, count( $structured_result->tags ), 'Raw provider tags must remain separate from genres and moods.' );
mw_assert_same( false, false !== strpos( $structured_result->description, '<script>' ), 'Imported descriptions must remove unsafe markup.' );
$GLOBALS['mw_test_terms'][100]                                    = new WP_Term( 100, 'mw_genre', 'Rock', 'rock' );
$GLOBALS['mw_test_terms'][101]                                    = new WP_Term( 101, 'mw_artist', 'هنرمند نمونه', 'sample-artist-fa' );
$GLOBALS['mw_test_term_meta'][101]['_mw_metadata_musicbrainz_id'] = 'artist-mbid-1';
$taxonomy_mapper = new ManaCore\MusicWave\Core\Metadata\MetadataTaxonomyMapper();
$taxonomy_mapper->remember_previous_identity( 100, 'mw_genre' );
$GLOBALS['mw_test_terms'][100]->name = 'راک';
$GLOBALS['mw_test_terms'][100]->slug = 'rock-fa';
mw_assert_same( array( 'Rock', 'rock' ), get_term_meta( 100, '_mw_metadata_aliases', true ), 'Renaming a term must retain its previous visible identity automatically.' );
mw_assert_same(
	array( 100 ),
	$taxonomy_mapper->assign(
		1,
		'mw_genre',
		array(
			array(
				'name'        => 'Rock',
				'external_id' => 'genre-id-1',
			),
		),
		'musicbrainz'
	),
	'A translated genre must be matched through its stable metadata alias instead of creating a duplicate.'
);
mw_assert_same(
	array( 101 ),
	$taxonomy_mapper->assign(
		1,
		'mw_artist',
		array(
			array(
				'name'        => 'Completely Different Display Name',
				'external_id' => 'artist-mbid-1',
			),
		),
		'musicbrainz'
	),
	'A translated artist must be matched through its stable provider identity.'
);
$new_genre_ids = $taxonomy_mapper->assign(
	1,
	'mw_genre',
	array(
		array(
			'name'        => 'Synthwave',
			'external_id' => '',
		),
	),
	'musicbrainz'
);
mw_assert_same( 1, count( $new_genre_ids ), 'A confirmed provider genre must be created when it does not exist.' );
mw_assert_same( 'Synthwave', get_term( $new_genre_ids[0], 'mw_genre' )->name, 'The newly created genre must preserve the provider name.' );
$GLOBALS['mw_test_terms'][103]                             = new WP_Term( 103, 'mw_mood', 'آرام', 'calm-fa' );
$GLOBALS['mw_test_term_meta'][103]['_mw_metadata_aliases'] = array( 'Calm' );
mw_assert_same(
	array( 103 ),
	$taxonomy_mapper->resolve( 'mw_mood', array( array( 'name' => 'Calm' ) ), 'musicbrainz', false ),
	'An existing translated mood must be selected through its metadata alias.'
);
$term_count_before_raw_tag = count( $GLOBALS['mw_test_terms'] );
mw_assert_same(
	array(),
	$taxonomy_mapper->resolve( 'mw_mood', array( array( 'name' => 'seen live' ) ), 'musicbrainz', false ),
	'An unclassified raw provider tag must not create a mood.'
);
mw_assert_same( $term_count_before_raw_tag, count( $GLOBALS['mw_test_terms'] ), 'Resolve-only raw tags must not pollute taxonomies.' );
$query_with_types = ManaCore\MusicWave\Core\Metadata\MetadataQuery::from_strings( '', 'Example', '', '', '', array( 'podcast_episode', 'invalid' ) );
mw_assert_same( array( 'podcast_episode' ), $query_with_types->release_types, 'Release-type context must accept only canonical MusicWave types.' );
mw_assert_same( true, $query_with_types->is_podcast(), 'Podcast release types must be detectable by metadata providers.' );
$GLOBALS['mw_test_options'][ ManaCore\MusicWave\Core\Support\Settings::OPTION ] = array(
	'default_access_mode' => 'restricted',
	'archive_per_page'    => 24,
	'purchase_message'    => 'Existing purchase message',
);
$settings_update = ManaCore\MusicWave\Core\Support\Settings::sanitize(
	array(
		'archive_per_page' => 36,
	)
);
mw_assert_same( 'restricted', $settings_update['default_access_mode'], 'Saving one settings tab must preserve values from other tabs.' );
mw_assert_same( 36, $settings_update['archive_per_page'], 'Settings updates must sanitize and apply the submitted value.' );
mw_assert_same( 'Existing purchase message', $settings_update['purchase_message'], 'Partial settings submissions must preserve customer-facing messages.' );
$GLOBALS['mw_test_options'][ ManaCore\MusicWave\Core\Support\Settings::OPTION ] = array();
mw_assert_same( 30, ManaCore\MusicWave\Core\Support\Settings::all()['download_rate_limit'], 'Download rate limiting must default to the documented limit.' );
mw_assert_same( 0, ManaCore\MusicWave\Core\Support\Settings::all()['download_daily_quota'], 'The daily delivery quota must stay disabled by default.' );
mw_assert_same( 300, ManaCore\MusicWave\Core\Support\Settings::all()['discovery_cache_ttl'], 'Catalog discovery caching must default to five minutes.' );
mw_assert_same( 180, ManaCore\MusicWave\Core\Support\Settings::all()['listening_retention_days'], 'Listening history must default to 180 days of retention.' );
$delivery_out_of_bounds = ManaCore\MusicWave\Core\Support\Settings::sanitize(
	array(
		'download_rate_limit'      => 0,
		'download_rate_window'     => 5,
		'download_daily_quota'     => 99999,
		'discovery_rate_limit'     => 999999,
		'discovery_rate_window'    => 999999,
		'discovery_cache_ttl'      => 10,
		'listening_retention_days' => 0,
	)
);
mw_assert_same( 30, $delivery_out_of_bounds['download_rate_limit'], 'Out-of-bounds download rate limits must fall back to the default.' );
mw_assert_same( 60, $delivery_out_of_bounds['download_rate_window'], 'Out-of-bounds download rate windows must fall back to the default.' );
mw_assert_same( 0, $delivery_out_of_bounds['download_daily_quota'], 'Out-of-bounds delivery quotas must fall back to the default.' );
mw_assert_same( 60, $delivery_out_of_bounds['discovery_rate_limit'], 'Out-of-bounds discovery rate limits must fall back to the default.' );
mw_assert_same( 60, $delivery_out_of_bounds['discovery_rate_window'], 'Out-of-bounds discovery rate windows must fall back to the default.' );
mw_assert_same( 300, $delivery_out_of_bounds['discovery_cache_ttl'], 'Out-of-bounds discovery cache lifetimes must fall back to the default.' );
mw_assert_same( 180, $delivery_out_of_bounds['listening_retention_days'], 'A zero retention window must fall back to the default.' );
$delivery_valid = ManaCore\MusicWave\Core\Support\Settings::sanitize(
	array(
		'download_rate_limit'      => 60,
		'download_daily_quota'     => 25,
		'discovery_cache_ttl'      => 600,
		'listening_retention_days' => 90,
	)
);
mw_assert_same( 60, $delivery_valid['download_rate_limit'], 'A configured download rate limit must be stored.' );
mw_assert_same( 25, $delivery_valid['download_daily_quota'], 'A configured daily delivery quota must be stored.' );
mw_assert_same( 600, $delivery_valid['discovery_cache_ttl'], 'A configured discovery cache lifetime must be stored.' );
mw_assert_same( 90, $delivery_valid['listening_retention_days'], 'A configured retention window must be stored.' );
$GLOBALS['mw_test_options'][ ManaCore\MusicWave\Core\Support\Settings::OPTION ] = array();

$GLOBALS['mw_test_types'][4] = 'mw_release';
$GLOBALS['mw_test_options'][ ManaCore\MusicWave\Core\Support\Settings::OPTION ] = array(
	'default_access_mode'      => 'restricted',
	'default_preview_duration' => 45,
);
$default_repository = new ManaCore\MusicWave\Core\Infrastructure\WordPressReleaseRepository( $schema );
$release_defaults   = new ManaCore\MusicWave\Core\Catalog\ReleaseDefaults( $default_repository );
$release_defaults->apply( 4, new WP_Post( 4, 'mw_release' ), false );
mw_assert_same( 'restricted', get_post_meta( 4, 'mw_access_mode', true ), 'New releases must persist the configured access default.' );
mw_assert_same( 45, get_post_meta( 4, 'mw_preview_duration', true ), 'New releases must persist the configured preview default.' );
$GLOBALS['mw_test_options'][ ManaCore\MusicWave\Core\Support\Settings::OPTION ] = array(
	'default_access_mode'      => 'public',
	'default_preview_duration' => 30,
);
$release_defaults->apply( 4, new WP_Post( 4, 'mw_release' ), true );
mw_assert_same( 'restricted', get_post_meta( 4, 'mw_access_mode', true ), 'Changing defaults must not rewrite an existing release.' );
$GLOBALS['mw_test_options'][ ManaCore\MusicWave\Core\Support\Settings::OPTION ] = array();
mw_assert_same(
	array(
		array(
			'key'      => 'mp3-320',
			'label'    => 'MP3 320 kbps',
			'asset_id' => 'local:downloads/track-320.mp3',
		),
	),
	$schema->get( 'mw_download_assets' )->sanitize(
		array(
			array(
				'key'      => 'mp3-320',
				'label'    => 'MP3 320 kbps',
				'asset_id' => 'local:downloads/track-320.mp3',
			),
			array(
				'key'      => 'mp3-320',
				'label'    => 'Duplicate',
				'asset_id' => 'local:downloads/duplicate.mp3',
			),
			array(
				'key'      => 'public',
				'label'    => 'Public URL',
				'asset_id' => 'https://example.test/file.mp3',
			),
		)
	),
	'Download quality variants must reject duplicates and public URLs.'
);
$quality_assets = array();
for ( $quality_index = 1; $quality_index <= 13; $quality_index++ ) {
	$quality_assets[] = array(
		'key'       => 'mp3-' . $quality_index,
		'label'     => 'MP3 ' . $quality_index,
		'asset_id'  => 'local:downloads/track-' . $quality_index . '.mp3',
		'file_name' => 'track ' . $quality_index . '.mp3',
		'format'    => 'MP3',
		'bitrate'   => '320',
		'duration'  => '225',
		'file_size' => '123456',
	);
}
$sanitized_quality_assets = $schema->get( 'mw_download_assets' )->sanitize( $quality_assets );
mw_assert_same( 13, count( $sanitized_quality_assets ), 'Download qualities must not have an arbitrary editor limit.' );
mw_assert_same(
	array(
		'key'       => 'mp3-1',
		'label'     => 'MP3 1',
		'asset_id'  => 'local:downloads/track-1.mp3',
		'format'    => 'mp3',
		'file_name' => 'track-1.mp3',
		'bitrate'   => 320,
		'duration'  => 225,
		'file_size' => 123456,
	),
	$sanitized_quality_assets[0],
	'Download quality metadata must be normalized and remain private.'
);
mw_assert_same(
	array(
		array(
			'key'        => 'song-a-mp3',
			'label'      => 'MP3 320 kbps',
			'asset_id'   => 'local:downloads/song-a.mp3',
			'file_key'   => 'song-a',
			'file_label' => 'Song A',
		),
		array(
			'key'      => 'incomplete-file-group',
			'label'    => 'Broken group',
			'asset_id' => 'local:downloads/broken.mp3',
		),
	),
	$schema->get( 'mw_download_assets' )->sanitize(
		array(
			array(
				'key'        => 'song-a-mp3',
				'label'      => 'MP3 320 kbps',
				'asset_id'   => 'local:downloads/song-a.mp3',
				'file_key'   => 'song-a',
				'file_label' => 'Song A',
			),
			array(
				'key'      => 'incomplete-file-group',
				'label'    => 'Broken group',
				'asset_id' => 'local:downloads/broken.mp3',
				'file_key' => 'broken',
			),
		)
	),
	'Each downloadable file must retain a valid title and stable group key without breaking legacy variants.'
);
$direct_asset_url = 'https://dl.rozmusic.com/Music/1405/08/04/Sina%20Derakhshande%20-%20Delam%20Tangete%20%28128%29.mp3';
mw_assert_same(
	array(
		array(
			'key'      => 'direct-128',
			'label'    => 'MP3 128',
			'asset_id' => 'direct:' . $direct_asset_url,
		),
	),
	$schema->get( 'mw_download_assets' )->sanitize(
		array(
			array(
				'key'      => 'direct-128',
				'label'    => 'MP3 128',
				'asset_id' => 'direct:' . $direct_asset_url,
			),
		)
	),
	'Direct-link asset IDs must preserve percent-encoding exactly (%20, %28, %29, hyphens).'
);
mw_assert_same(
	'direct:' . $direct_asset_url,
	$schema->get( 'mw_download_asset_id' )->sanitize( 'direct:' . $direct_asset_url ),
	'Legacy single direct-link asset IDs must preserve percent-encoding exactly.'
);
mw_assert_same(
	array(
		array(
			'release_id' => 3,
			'position'   => 4,
			'disc'       => null,
			'role'       => 'track',
		),
	),
	$schema->get( 'mw_collection_items' )->sanitize(
		array(
			array(
				'release_id' => '3',
				'position'   => '4',
				'disc'       => '0',
				'role'       => 'track',
			),
			array(
				'release_id' => 'bad',
				'position'   => 2,
				'role'       => 'unknown',
			),
		)
	),
	'Collection relation values must be strictly normalized.'
);

mw_assert_same( 'latest', ManaCore\MusicWave\Core\Catalog\ReleaseArchiveQuery::normalize_sort( 'not-a-sort' ), 'Unknown archive sorting must fall back safely.' );
mw_assert_same( 'title_desc', ManaCore\MusicWave\Core\Catalog\ReleaseArchiveQuery::normalize_sort( 'TITLE_DESC' ), 'Archive sorting must normalize allow-listed values.' );
mw_assert_same(
	array(
		'orderby' => 'title',
		'order'   => 'ASC',
	),
	ManaCore\MusicWave\Core\Catalog\ReleaseArchiveQuery::sort_arguments( 'title_asc' ),
	'Title sorting must use native WordPress ordering.'
);
mw_assert_same(
	array(
		'orderby' => 'date',
		'order'   => 'DESC',
	),
	ManaCore\MusicWave\Core\Catalog\ReleaseArchiveQuery::sort_arguments( 'unexpected' ),
	'Invalid sorting arguments must use the default ordering.'
);

$mw_catalog_search                         = new WP_Query();
$mw_catalog_search->is_search              = true;
$mw_catalog_search->mw_test_archive_types  = array( 'mw_release' );
( new ManaCore\MusicWave\Core\Catalog\ReleaseArchiveQuery() )->scope_archive_search( $mw_catalog_search );
mw_assert_same( false, $mw_catalog_search->is_search, 'Catalog search phrases must stay inside the release archive view.' );

$mw_global_search             = new WP_Query();
$mw_global_search->is_search  = true;
( new ManaCore\MusicWave\Core\Catalog\ReleaseArchiveQuery() )->scope_archive_search( $mw_global_search );
mw_assert_same( true, $mw_global_search->is_search, 'Global searches must keep the standard search behavior.' );

$mw_secondary_search                         = new WP_Query();
$mw_secondary_search->is_search              = true;
$mw_secondary_search->mw_test_is_main        = false;
$mw_secondary_search->mw_test_archive_types  = array( 'mw_release' );
( new ManaCore\MusicWave\Core\Catalog\ReleaseArchiveQuery() )->scope_archive_search( $mw_secondary_search );
mw_assert_same( true, $mw_secondary_search->is_search, 'Secondary queries must never be adjusted by the catalog scoping.' );

mw_assert_same(
	array( 'mw_artist', 'mw_genre', 'mw_mood', 'mw_release_type' ),
	ManaCore\MusicWave\Core\Catalog\ReleaseTaxonomies::catalog_taxonomies(),
	'The catalog filter taxonomies must be declared once in display order.'
);
mw_assert_same(
	ManaCore\MusicWave\Core\Catalog\ReleaseTaxonomies::catalog_taxonomies(),
	array_keys( ManaCore\MusicWave\Core\Catalog\ReleaseTaxonomies::catalog_labels() ),
	'Catalog filter labels must cover exactly the catalog taxonomies.'
);

$mw_tax_search                              = new WP_Query();
$mw_tax_search->is_search                   = true;
$mw_tax_search->mw_test_tax_archives        = array( 'mw_artist' );
( new ManaCore\MusicWave\Core\Catalog\ReleaseArchiveQuery() )->scope_archive_search( $mw_tax_search );
mw_assert_same( false, $mw_tax_search->is_search, 'Catalog search phrases must stay inside a release taxonomy archive too.' );

$mw_tax_sort                                                               = new WP_Query();
$mw_tax_sort->mw_test_tax_archives                                         = array( 'mw_artist' );
$_GET[ ManaCore\MusicWave\Core\Catalog\ReleaseArchiveQuery::SORT_QUERY_VAR ] = 'title_asc';
( new ManaCore\MusicWave\Core\Catalog\ReleaseArchiveQuery() )->apply_sorting( $mw_tax_sort );
unset( $_GET[ ManaCore\MusicWave\Core\Catalog\ReleaseArchiveQuery::SORT_QUERY_VAR ] );
mw_assert_same( 'title', $mw_tax_sort->get( 'orderby' ), 'Taxonomy archives must honor the catalog sort control.' );
mw_assert_same( 'ASC', $mw_tax_sort->get( 'order' ), 'Taxonomy archives must apply the allow-listed sort direction.' );
mw_assert_same(
	(int) ManaCore\MusicWave\Core\Support\Settings::get( 'archive_per_page' ),
	$mw_tax_sort->get( 'posts_per_page' ),
	'Taxonomy archives must paginate with the theme catalog page size so the artist pager works.'
);

$mw_non_catalog                         = new WP_Query();
$mw_non_catalog->mw_test_archive_types  = array( 'post' );
( new ManaCore\MusicWave\Core\Catalog\ReleaseArchiveQuery() )->apply_sorting( $mw_non_catalog );
mw_assert_same( '', $mw_non_catalog->get( 'posts_per_page' ), 'Non-catalog listings must keep the site-wide page size untouched.' );

// --- Release-type permalinks: URL bases follow the release type (PROJECT_PLAN.md Stage 4 UX) ---

final class TestRewrite {
	/** @var array<string, string> */
	public $structs = array();

	public function get_extra_permastruct( string $name ) {
		return isset( $this->structs[ $name ] ) ? $this->structs[ $name ] : false;
	}
}

$mw_permalinks = new ManaCore\MusicWave\Core\Catalog\ReleasePermalinks();
mw_assert_same( 'album', $mw_permalinks->base_for_types( array( 'album' ) ), 'Albums must live under /album/.' );
mw_assert_same( 'track', $mw_permalinks->base_for_types( array( 'single' ) ), 'Singles must share the /track/ base with tracks.' );
mw_assert_same( 'track', $mw_permalinks->base_for_types( array( 'track' ) ), 'Tracks must live under /track/.' );
mw_assert_same( 'album', $mw_permalinks->base_for_types( array( 'track', 'album' ) ), 'Collections must win over track types when a release carries both.' );
mw_assert_same( 'podcast', $mw_permalinks->base_for_types( array( 'podcast_show' ) ), 'Podcast shows must live under /podcast/.' );
mw_assert_same( 'episode', $mw_permalinks->base_for_types( array( 'podcast_episode' ) ), 'Podcast episodes must live under /episode/.' );
mw_assert_same( 'music', $mw_permalinks->base_for_types( array() ), 'Untyped releases must keep the legacy /music/ base.' );
mw_assert_same( 'music', $mw_permalinks->base_for_types( array( 'unknown-type' ) ), 'Unknown release types must keep the legacy /music/ base.' );
mw_assert_same( 'album', $mw_permalinks->base_for( 1 ), 'The base must resolve from the release-type terms of a release.' );
mw_assert_same( 'track', $mw_permalinks->base_for( 2 ), 'Track releases must resolve to the /track/ base.' );
mw_assert_same( 'music', $mw_permalinks->base_for( 4 ), 'Releases without release-type terms must resolve to the legacy base.' );
mw_assert_same( false, in_array( 'music', $mw_permalinks->extra_bases(), true ), 'The post type base must not be re-registered as an extra permastruct.' );
mw_assert_same( array( 'album', 'ep', 'mix', 'playlist', 'podcast', 'episode', 'track' ), $mw_permalinks->extra_bases(), 'Every distinct mapped base must get its own permastruct exactly once.' );
mw_assert_same( 'mw_release_base_podcast', ManaCore\MusicWave\Core\Catalog\ReleasePermalinks::permastruct_name( 'podcast' ), 'Permastruct names must be derived from the base.' );
mw_assert_same( 'music/album', ManaCore\MusicWave\Core\Catalog\ReleasePermalinks::sanitize_base( '/Music/Album/' ), 'Multi-segment bases must be lower-cased and trimmed.' );
mw_assert_same( '', ManaCore\MusicWave\Core\Catalog\ReleasePermalinks::sanitize_base( 'bad base!' ), 'Bases with unsafe characters must be rejected.' );
mw_assert_same( '', ManaCore\MusicWave\Core\Catalog\ReleasePermalinks::sanitize_base( 'a//b' ), 'Bases with empty segments must be rejected.' );

$GLOBALS['mw_test_filters']['music_wave_release_permalink_bases'] = array(
	'album'   => 'Records/',
	'track'   => 'bad base!',
	''        => 'ignored',
	'podcast' => '',
);
$mw_filtered_permalinks = new ManaCore\MusicWave\Core\Catalog\ReleasePermalinks();
mw_assert_same( array( 'album' => 'records' ), $mw_filtered_permalinks->bases(), 'Filtered bases must be sanitized and invalid entries dropped.' );
mw_assert_same( 'music', $mw_filtered_permalinks->base_for_types( array( 'track' ) ), 'Types dropped by the filter must fall back to the legacy base.' );
unset( $GLOBALS['mw_test_filters']['music_wave_release_permalink_bases'] );

// Rewrite rules regenerate themselves once per base map, so the first request
// after an update resolves the new bases without a manual permalink re-save.
$GLOBALS['mw_test_rewrite_flushes'] = array();
unset( $GLOBALS['mw_test_options'][ ManaCore\MusicWave\Core\Catalog\ReleasePermalinks::OPTION_RULES_SIGNATURE ] );
$mw_permalinks->maybe_flush_rewrite_rules();
mw_assert_same( array( false ), $GLOBALS['mw_test_rewrite_flushes'], 'An unknown base map must trigger exactly one soft rewrite flush.' );
mw_assert_same( $mw_permalinks->rules_signature(), (string) get_option( ManaCore\MusicWave\Core\Catalog\ReleasePermalinks::OPTION_RULES_SIGNATURE ), 'The flushed base map must be remembered.' );
$mw_permalinks->maybe_flush_rewrite_rules();
mw_assert_same( 1, count( $GLOBALS['mw_test_rewrite_flushes'] ), 'An unchanged base map must not flush again.' );
$GLOBALS['mw_test_filters']['music_wave_release_permalink_bases'] = array( 'album' => 'records' );
( new ManaCore\MusicWave\Core\Catalog\ReleasePermalinks() )->maybe_flush_rewrite_rules();
mw_assert_same( 2, count( $GLOBALS['mw_test_rewrite_flushes'] ), 'Changing the base map through the filter must flush once more.' );
unset( $GLOBALS['mw_test_filters']['music_wave_release_permalink_bases'] );
mw_assert_same( $mw_permalinks->rules_signature(), ( new ManaCore\MusicWave\Core\Catalog\ReleasePermalinks() )->rules_signature(), 'Signatures must be deterministic for the same map.' );

$GLOBALS['wp_rewrite']          = new TestRewrite();
$GLOBALS['wp_rewrite']->structs = array( 'mw_release_base_album' => '/album/%mw_release%', 'mw_release_base_track' => '/track/%mw_release%' );
$mw_album_post                  = new WP_Post( 1, 'mw_release', 'night-signals' );
$mw_track_post                  = new WP_Post( 2, 'mw_release', 'midnight-drive' );
$mw_untyped_post                = new WP_Post( 4, 'mw_release', 'untitled' );
mw_assert_same( 'https://example.test/album/night-signals/', $mw_permalinks->filter_post_type_link( 'https://example.test/music/night-signals/', $mw_album_post, false ), 'Album permalinks must swap the post type base for /album/.' );
mw_assert_same( 'https://example.test/track/%mw_release%/', $mw_permalinks->filter_post_type_link( 'https://example.test/music/%mw_release%/', $mw_track_post, true ), 'Sample permalinks must keep the post name token for the editor slug UI.' );
mw_assert_same( 'https://example.test/music/untitled/', $mw_permalinks->filter_post_type_link( 'https://example.test/music/untitled/', $mw_untyped_post, false ), 'Untyped releases must keep their legacy permalink.' );
mw_assert_same( 'https://example.test/?mw_release=night-signals', $mw_permalinks->filter_post_type_link( 'https://example.test/?mw_release=night-signals', $mw_album_post, false ), 'Plain permalinks must never be rewritten.' );
mw_assert_same( 'https://example.test/music/night-signals/', $mw_permalinks->filter_post_type_link( 'https://example.test/music/night-signals/', new WP_Post( 10, 'product', 'night-signals' ), false ), 'Only release permalinks may be rewritten.' );
$GLOBALS['wp_rewrite']->structs = array();
mw_assert_same( 'https://example.test/music/night-signals/', $mw_permalinks->filter_post_type_link( 'https://example.test/music/night-signals/', $mw_album_post, false ), 'Permalinks must stay untouched until the permastruct is registered.' );
unset( $GLOBALS['wp_rewrite'] );

mw_assert_same( 'https://example.test/album/night-signals/', ManaCore\MusicWave\Core\Catalog\ReleasePermalinks::redirect_target( '/music/night-signals/', 'https://example.test/album/night-signals/', 'night-signals' ), 'Legacy /music/ requests must redirect to the typed permalink.' );
mw_assert_same( 'https://example.test/album/night-signals/2/?utm=x', ManaCore\MusicWave\Core\Catalog\ReleasePermalinks::redirect_target( '/music/night-signals/2/?utm=x', 'https://example.test/album/night-signals/', 'night-signals' ), 'Redirects must keep sub-routes and the query string.' );
mw_assert_same( 'https://example.test/album/night-signals/comment-page-2/', ManaCore\MusicWave\Core\Catalog\ReleasePermalinks::redirect_target( '/track/night-signals/comment-page-2', 'https://example.test/album/night-signals/', 'night-signals' ), 'Stale typed bases must redirect after a release changes type.' );
mw_assert_same( null, ManaCore\MusicWave\Core\Catalog\ReleasePermalinks::redirect_target( '/album/night-signals/', 'https://example.test/album/night-signals/', 'night-signals' ), 'Canonical requests must not redirect.' );
mw_assert_same( null, ManaCore\MusicWave\Core\Catalog\ReleasePermalinks::redirect_target( '/album/night-signals/2/', 'https://example.test/album/night-signals/', 'night-signals' ), 'Canonical paginated requests must not redirect.' );
mw_assert_same( null, ManaCore\MusicWave\Core\Catalog\ReleasePermalinks::redirect_target( '/music/night-signals/', 'https://example.test/?mw_release=night-signals', 'night-signals' ), 'Plain permalinks must never trigger a redirect.' );
mw_assert_same( null, ManaCore\MusicWave\Core\Catalog\ReleasePermalinks::redirect_target( '/music/other-release/', 'https://example.test/album/night-signals/', 'night-signals' ), 'Requests that do not contain the release slug must be left to WordPress.' );
mw_assert_same( 'https://example.test/album/%D8%A2%D9%84%D8%A8%D9%88%D9%85/', ManaCore\MusicWave\Core\Catalog\ReleasePermalinks::redirect_target( '/music/%d8%a2%d9%84%d8%a8%d9%88%d9%85/', 'https://example.test/album/%D8%A2%D9%84%D8%A8%D9%88%D9%85/', '%d8%a2%d9%84%d8%a8%d9%88%d9%85' ), 'Percent-encoded non-ASCII slugs must match regardless of hex case.' );


$GLOBALS['mw_test_types'][2] = 'mw_release';
$GLOBALS['mw_test_types'][3] = 'mw_release';
$relation_repository         = new ManaCore\MusicWave\Core\Infrastructure\WordPressReleaseRepository( $schema );
mw_assert_same(
	true,
	$relation_repository->replace_collection_items(
		1,
		array(
			array(
				'release_id' => 2,
				'position'   => 1,
				'role'       => 'track',
			),
		)
	),
	'Collection relations must be persisted.'
);
mw_assert_same( array( 1 ), $relation_repository->collection_ids( 2 ), 'Collection reverse index must be maintained.' );
mw_assert_same( true, $relation_repository->remove_collection_item( 1, 2 ), 'Collection children must be removable.' );
mw_assert_same( array(), $relation_repository->collection_ids( 2 ), 'Removing a child must clear its reverse index.' );
$duplicate_rejected = false;
try {
	$relation_repository->replace_collection_items(
		1,
		array(
			array(
				'release_id' => 2,
				'position'   => 1,
				'role'       => 'track',
			),
			array(
				'release_id' => 2,
				'position'   => 2,
				'role'       => 'track',
			),
		)
	);
} catch ( InvalidArgumentException $exception ) {
	$duplicate_rejected = true;
}
mw_assert_same( true, $duplicate_rejected, 'Duplicate collection children must be rejected.' );

// --- Collection REST mutation regressions (PROJECT_PLAN.md Stage 1 deliverable 4) ---

$create_shape_rejected = false;
try {
	$relation_repository->validate_collection_items_for_create(
		array(
			array(
				'release_id' => 999,
				'position'   => 1,
				'role'       => 'track',
			),
		)
	);
} catch ( InvalidArgumentException $exception ) {
	$create_shape_rejected = true;
}
mw_assert_same( true, $create_shape_rejected, 'Creation-path validation must reject unknown child releases before insert.' );
mw_assert_same(
	1,
	count(
		$relation_repository->validate_collection_items_for_create(
			array(
				array(
					'release_id' => 2,
					'position'   => 1,
					'role'       => 'track',
				),
			)
		)
	),
	'Creation-path validation must accept well-formed relation input.'
);

$GLOBALS['mw_test_capabilities'] = array( 'edit_mw_releases', 'edit_post' );
$rest_collections                = new ManaCore\MusicWave\Core\Infrastructure\CollectionRestPolicy( $relation_repository );

$create_request = new WP_REST_Request(
	array(
		'meta' => array(
			'mw_collection_items' => array(
				array(
					'release_id' => 999,
					'position'   => 1,
					'role'       => 'track',
				),
			),
		),
	)
);
$create_result  = $rest_collections->pre_insert( new stdClass(), $create_request );
mw_assert_same( true, is_wp_error( $create_result ), 'REST collection creation must fail before insert when relation input is invalid.' );

// Simulate the after-insert failure path: parent-dependent validation fails,
// the previous relations are restored, and the response must become an error.
$GLOBALS['mw_test_types'][8] = 'mw_release';
$discard_request             = new WP_REST_Request(
	array(
		'meta' => array(
			'mw_collection_items' => array(
				array(
					'release_id' => 3,
					'position'   => 1,
					'role'       => 'track',
				),
			),
		),
	)
);
$rest_collections->after_insert( new WP_Post( 8, 'mw_release' ), $discard_request, true );
mw_assert_same( array(), $relation_repository->collection_items( 8 ), 'Discarded relation mutations must restore the previous stored state.' );
$converted = $rest_collections->fail_discarded_mutation( 'would-be-success' );
mw_assert_same( true, is_wp_error( $converted ), 'A discarded relation mutation must never surface as a successful REST response.' );
mw_assert_same( 'would-be-success', $rest_collections->fail_discarded_mutation( 'would-be-success' ), 'The deferred relation error must clear after one response.' );
$GLOBALS['mw_test_capabilities'] = array();

// --- Protected asset assignment stopgap (PROJECT_PLAN.md Stage 1 deliverable 5) ---

$foundation = new ManaCore\MusicWave\Core\Modules\Foundation();
mw_assert_same( array( 'manage_options' ), $foundation->map_asset_capability( array(), 'manage_mw_protected_assets' ), 'The dedicated asset capability must map to administrators by default.' );
mw_assert_same( array( 'edit_posts' ), $foundation->map_asset_capability( array( 'edit_posts' ), 'edit_post' ), 'Unrelated capabilities must pass through the asset capability mapping untouched.' );

$asset_repository = new TestPolicyRepository();
$asset_routes     = new ManaCore\MusicWave\Core\Downloads\DownloadAssetRoutes( $asset_repository );
$asset_request    = new WP_REST_Request(
	array(
		'id'     => 1,
		'assets' => array(
			array(
				'key'      => 'flac',
				'label'    => 'Lossless',
				'asset_id' => 'local:guessed/master.flac',
			),
		),
	)
);
$GLOBALS['mw_test_capabilities'] = array( 'edit_post', 'edit_mw_releases' );
$asset_denied                    = $asset_routes->update( $asset_request );
mw_assert_same( true, is_wp_error( $asset_denied ), 'Editors without the asset capability must not assign new protected asset identifiers.' );

$GLOBALS['mw_test_capabilities'] = array( 'edit_post', 'edit_mw_releases', 'manage_mw_protected_assets' );
$asset_allowed                   = $asset_routes->update( $asset_request );
mw_assert_same( false, is_wp_error( $asset_allowed ), 'Users holding the dedicated asset capability may assign new protected assets.' );

// Keeping an already-assigned identifier must not require the capability.
$asset_repository->values['mw_download_assets'] = array(
	array(
		'key'      => 'std',
		'label'    => 'Standard',
		'asset_id' => 'local:existing/track.mp3',
	),
);
$GLOBALS['mw_test_capabilities']                = array( 'edit_post', 'edit_mw_releases' );
$asset_keep_request                             = new WP_REST_Request(
	array(
		'id'     => 1,
		'assets' => array(
			array(
				'key'      => 'std',
				'label'    => 'Renamed standard',
				'asset_id' => 'local:existing/track.mp3',
			),
		),
	)
);
mw_assert_same( false, is_wp_error( $asset_routes->update( $asset_keep_request ) ), 'Editors may relabel or reorder assets already assigned to the release.' );
$GLOBALS['mw_test_capabilities'] = array();

// --- Canonical access gate (PROJECT_PLAN.md Stage 1 deliverable 6) ---

$gate_repository = new TestPolicyRepository();
$gate_repository->values['mw_access_mode'] = 'restricted';
$gate_engine  = new ManaCore\MusicWave\Core\Access\AccessPolicyEngine( $gate_repository, new ManaCore\MusicWave\Core\Commerce\PurchaseChecker( $gate_repository ), new TestMembershipProvider() );
$gate_blocks  = new ManaCore\MusicWave\Core\Blocks\ReleaseBlocks( $gate_engine, $gate_repository );
$GLOBALS['mw_test_post'] = new WP_Post( 1, 'mw_release' );
$gated_body   = $gate_blocks->filter_content( 'PRIVATE RELEASE BODY' );
mw_assert_same( false, strpos( $gated_body, 'PRIVATE RELEASE BODY' ), 'Restricted body content must never reach the frontend.' );
mw_assert_same( true, false !== strpos( $gated_body, 'mw-access-panel' ), 'The canonical access panel must replace restricted body content.' );
mw_assert_same( '', $gate_blocks->render_access_panel( array( 'releaseId' => 1 ) ), 'The canonical access gate must render only once per release request.' );
mw_assert_same( '', $gate_blocks->render_meta( array( 'releaseId' => 1 ) ), 'Gated release metadata must render nothing instead of a duplicate gate.' );
unset( $GLOBALS['mw_test_post'] );

$token_service   = new ManaCore\MusicWave\Core\Downloads\DownloadTokenService( 'test-download-secret' );
$download_token  = $token_service->issue( 1, 7, 60, 'test-nonce', 'mp3-320' );
$download_claims = $token_service->verify( $download_token, 'test-nonce' );
mw_assert_same( 1, null === $download_claims ? 0 : $download_claims->release_id(), 'Signed download tokens must retain the release binding.' );
mw_assert_same( 'mp3-320', null === $download_claims ? '' : $download_claims->asset_key(), 'Signed download tokens must retain the selected quality.' );
mw_assert_same( null, $token_service->verify( $download_token . 'tampered', 'test-nonce' ), 'Modified download tokens must be rejected.' );
mw_assert_same( null, $token_service->verify( $download_token, 'wrong-nonce' ), 'Download tokens must be nonce-bound.' );
$replays = new ManaCore\MusicWave\Core\Downloads\TransientReplayStore();
mw_assert_same( true, $replays->consume( 'one-time-token', time() + 60 ), 'The first use of a download token must be accepted.' );
mw_assert_same( false, $replays->consume( 'one-time-token', time() + 60 ), 'Replay use of a download token must be rejected.' );

$runner  = new ManaCore\MusicWave\Core\Migrations\MigrationRunner( array( new TestMigration( '0.3.0' ), new TestMigration( '0.2.0' ) ) );
$pending = $runner->pending( '0.2.0' );
mw_assert_same( 1, count( $pending ), 'Only newer migrations should be pending.' );
mw_assert_same( '0.3.0', $pending[0]->version(), 'Migrations must be version sorted.' );
mw_assert_same( '0.15.0', ManaCore\MusicWave\Core\Migrations\MigrationRunner::LATEST_VERSION, 'Health checks must compare against the schema version rather than the plugin release version.' );
mw_assert_same( '0.9.0', ( new ManaCore\MusicWave\Core\Migrations\Schema090() )->version(), 'The replay-table migration must carry the 0.9.0 schema version.' );
mw_assert_same( '0.10.0', ( new ManaCore\MusicWave\Core\Migrations\Schema0100() )->version(), 'The listening-activity migration must carry the 0.10.0 schema version.' );

// Adding a SchemaXxx class without bumping LATEST_VERSION leaves every readiness
// check (Site Health, Diagnostics, Settings) reporting a migrated site while the
// new table is missing, so the two must be asserted equal (review finding B4).
mw_assert_same(
	ManaCore\MusicWave\Core\Migrations\MigrationRunner::LATEST_VERSION,
	ManaCore\MusicWave\Core\Migrations\MigrationSet::latest_version(),
	'MigrationRunner::LATEST_VERSION must equal the highest version in MigrationSet::all().'
);

// A SchemaXxx file that nobody registered never runs, and the failure is silent:
// compare the shipped classes against the directory instead of trusting the list.
$migration_files = glob( dirname( __DIR__ ) . '/music-wave-core/src/Migrations/Schema*.php' );
$migration_files = false === $migration_files ? array() : $migration_files;
$expected_set    = array();
foreach ( $migration_files as $migration_file ) {
	$expected_set[] = 'ManaCore\MusicWave\Core\Migrations\\' . basename( $migration_file, '.php' );
}
$registered_set = array();
foreach ( ManaCore\MusicWave\Core\Migrations\MigrationSet::all() as $migration ) {
	$registered_set[] = get_class( $migration );
}
sort( $expected_set );
sort( $registered_set );
mw_assert_same( $expected_set, $registered_set, 'Every SchemaXxx class on disk must be registered in MigrationSet::all().' );

// --- Secure delivery foundation (PROJECT_PLAN.md Stage 2) ---

// Replay store: without the dedicated table the store must fall back to the
// legacy behavior while staying single-use.
$database_replays = new ManaCore\MusicWave\Core\Downloads\DatabaseReplayStore();
mw_assert_same( true, $database_replays->consume( 'stage2-token', time() + 60 ), 'The database replay store must accept the first token use (fallback path).' );
mw_assert_same( false, $database_replays->consume( 'stage2-token', time() + 60 ), 'The database replay store must reject replays (fallback path).' );

// Token issuance rate limiting.
$rate_limiter = new ManaCore\MusicWave\Core\Downloads\DownloadRateLimiter();
$rate_allowed = 0;
for ( $i = 0; $i < ManaCore\MusicWave\Core\Downloads\DownloadRateLimiter::DEFAULT_LIMIT + 5; $i++ ) {
	if ( $rate_limiter->allow( 7 ) ) {
		++$rate_allowed;
	}
}
mw_assert_same( ManaCore\MusicWave\Core\Downloads\DownloadRateLimiter::DEFAULT_LIMIT, $rate_allowed, 'Token issuance must stop at the configured per-window rate limit.' );

// Remote redirect provider: complete-payload signing, strong keys, host allowlist.
require_once dirname( __DIR__ ) . '/music-wave-vip/src/Autoloader.php';
ManaCore\MusicWave\Vip\Autoloader::register();
$remote_claims = new ManaCore\MusicWave\Core\Downloads\DownloadTokenClaims( 1, 7, time() + 300, 'token-id', 'binding' );
$weak_remote   = new ManaCore\MusicWave\Vip\RemoteRedirectProvider(
	array(
		'remote_base_url'        => 'https://cdn.example.com/files',
		'remote_signing_secret'  => 'short-secret',
		'remote_signature_param' => 'signature',
		'remote_expires_param'   => 'expires',
	)
);
mw_assert_same( '', $weak_remote->create_url( 'album/track.flac', $remote_claims, false ), 'Remote signing must fail closed when the shared secret is weaker than 32 characters.' );

$strong_config = array(
	'remote_base_url'        => 'https://cdn.example.com/files',
	'remote_signing_secret'  => str_repeat( 'k', 40 ),
	'remote_signature_param' => 'signature',
	'remote_expires_param'   => 'expires',
	'remote_ttl'             => 300,
	'remote_allowed_hosts'   => array( 'mirror.example.net' ),
	'remote_key_id'          => 'k2026',
);
$strong_remote = new ManaCore\MusicWave\Vip\RemoteRedirectProvider( $strong_config );
$download_url  = $strong_remote->create_url( 'album/track.flac', $remote_claims, false );
$stream_url    = $strong_remote->create_url( 'album/track.flac', $remote_claims, true );
mw_assert_same( true, false !== strpos( $download_url, 'mode=download' ), 'Signed remote URLs must carry the delivery mode.' );
mw_assert_same( true, false !== strpos( $download_url, 'kid=k2026' ), 'Signed remote URLs must carry the rotation key id.' );
parse_str( (string) parse_url( $download_url, PHP_URL_QUERY ), $download_args );
parse_str( (string) parse_url( $stream_url, PHP_URL_QUERY ), $stream_args );
mw_assert_same( true, isset( $download_args['signature'], $stream_args['signature'] ) && $download_args['signature'] !== $stream_args['signature'], 'The delivery mode must be covered by the signature (complete-payload signing).' );
mw_assert_same( true, $strong_remote->allowed_url( 'https://cdn.example.com/files/a.flac?x=1' ), 'The configured remote host must pass the redirect allowlist.' );
mw_assert_same( true, $strong_remote->allowed_url( 'https://mirror.example.net/a.flac' ), 'Explicitly allowlisted hosts must pass the redirect check.' );
mw_assert_same( false, $strong_remote->allowed_url( 'https://attacker.example.org/a.flac' ), 'Unlisted hosts must be refused even over HTTPS.' );
mw_assert_same( false, $strong_remote->allowed_url( 'http://cdn.example.com/a.flac' ), 'Plain HTTP redirects must always be refused.' );

// Opaque asset registry and server-offload delivery (Stage 2 deliverables 2, 6, 7).
$vip_registry = new ManaCore\MusicWave\Vip\ProtectedAssetRegistry();
mw_assert_same( null, $vip_registry->find( 'local:album/track.flac' ), 'The registry must only resolve opaque vip: identifiers.' );
mw_assert_same( null, $vip_registry->find( 'vip:not-a-valid-key' ), 'Malformed opaque identifiers must never resolve.' );
mw_assert_same( false, $vip_registry->exists( 'vip:' . str_repeat( 'a', 32 ) ), 'Unknown opaque identifiers must not validate for assignment.' );
mw_assert_same( 'audio/flac', $vip_registry->mime_type( 'x/master.FLAC' ), 'The registry MIME map must classify audio files case-insensitively.' );
mw_assert_same( 'application/octet-stream', $vip_registry->mime_type( 'x/master.exe' ), 'Unknown extensions must fall back to a generic binary type.' );

mw_assert_same( null, ManaCore\MusicWave\Vip\ProtectedFileProvider::sendfile_headers( 'none', '/p/a.flac', 'a.flac', '/mw', 'audio/flac', 'inline; filename="a.flac"' ), 'PHP streaming mode must not emit acceleration headers.' );
$xsendfile_headers = ManaCore\MusicWave\Vip\ProtectedFileProvider::sendfile_headers( 'xsendfile', '/private/a b.flac', 'a b.flac', '', 'application/octet-stream', 'attachment; filename="a_b.flac"' );
mw_assert_same( true, is_array( $xsendfile_headers ) && in_array( 'X-Sendfile: /private/a b.flac', $xsendfile_headers, true ), 'X-Sendfile mode must hand the absolute path to the web server.' );
$xaccel_headers = ManaCore\MusicWave\Vip\ProtectedFileProvider::sendfile_headers( 'xaccel', '/private/album/a b.flac', 'album/a b.flac', '/musicwave-protected', 'application/octet-stream', 'attachment; filename="a_b.flac"' );
mw_assert_same( true, is_array( $xaccel_headers ) && in_array( 'X-Accel-Redirect: /musicwave-protected/album/a%20b.flac', $xaccel_headers, true ), 'X-Accel mode must emit the URL-encoded internal redirect path.' );
mw_assert_same( null, ManaCore\MusicWave\Vip\ProtectedFileProvider::sendfile_headers( 'xaccel', '/private/a.flac', 'a.flac', '', 'audio/flac', 'inline' ), 'X-Accel mode without an internal prefix must fall back to PHP streaming.' );

// Opaque browser tickets (Stage 2 deliverable 5): claims never reach the browser.
$ticket_store = new ManaCore\MusicWave\Core\Downloads\OpaqueTicketStore();
$wrapped      = $ticket_store->wrap( 'signed-token-value', 300 );
mw_assert_same( 0, strpos( $wrapped, 'mwt_' ), 'Browser tickets must use the opaque mwt_ namespace.' );
mw_assert_same( 'signed-token-value', $ticket_store->unwrap( $wrapped ), 'A stored ticket must exchange back into its signed token.' );
mw_assert_same( null, $ticket_store->unwrap( 'mwt_' . str_repeat( '0', 40 ) ), 'Unknown tickets must not exchange into tokens.' );
mw_assert_same( null, $ticket_store->unwrap( 'not-a-ticket' ), 'Malformed tickets must be rejected before storage lookup.' );

$ticket_repository = new TestPolicyRepository();
$ticket_repository->values['mw_access_mode']      = 'public';
$ticket_repository->values['mw_download_assets']  = array(
	array(
		'key'      => 'mp3-320',
		'label'    => 'MP3 320',
		'asset_id' => 'vip:' . str_repeat( 'b', 32 ),
	),
);
$ticket_provider  = new TestDownloadProvider();
$ticket_resolver  = new ManaCore\MusicWave\Core\Downloads\DownloadResolver(
	$ticket_repository,
	new ManaCore\MusicWave\Core\Access\AccessPolicyEngine( $ticket_repository, new ManaCore\MusicWave\Core\Commerce\PurchaseChecker( $ticket_repository ), new TestMembershipProvider() ),
	new ManaCore\MusicWave\Core\Downloads\DownloadTokenService( 'ticket-test-secret' ),
	new ManaCore\MusicWave\Core\Downloads\TransientReplayStore(),
	$ticket_provider
);
$issued_ticket    = $ticket_resolver->issue( 1, new ManaCore\MusicWave\Core\Access\AccessSubject( 7 ), 'ticket-binding', 'mp3-320' );
mw_assert_same( true, is_string( $issued_ticket ) && 0 === strpos( $issued_ticket, 'mwt_' ), 'Issued download credentials must be opaque tickets.' );
mw_assert_same( false, strpos( (string) $issued_ticket, '.' ), 'Opaque tickets must not contain readable signed-token segments.' );
mw_assert_same( true, $ticket_resolver->deliver( 1, 7, (string) $issued_ticket, 'ticket-binding' ), 'A valid opaque ticket must deliver through the provider.' );
mw_assert_same( array( 'vip:' . str_repeat( 'b', 32 ) ), $ticket_provider->delivered, 'Delivery must resolve the opaque ticket to the assigned asset.' );
mw_assert_same( false, $ticket_resolver->deliver( 1, 7, (string) $issued_ticket, 'ticket-binding' ), 'Replaying a consumed opaque ticket must fail closed.' );
mw_assert_same( false, $ticket_resolver->deliver( 1, 7, 'mwt_' . str_repeat( '1', 40 ), 'ticket-binding' ), 'Guessed opaque tickets must fail closed.' );

// --- Application boundaries and data consistency (PROJECT_PLAN.md Stage 3) ---

// Locked, resumable migrations.
$lock_runner = new ManaCore\MusicWave\Core\Migrations\MigrationRunner( array( new TestMigration( '9.1.0' ), new TestMigration( '9.2.0' ) ) );
$GLOBALS['mw_test_options'][ ManaCore\MusicWave\Core\Migrations\MigrationRunner::OPTION ] = '9.0.0';
$GLOBALS['mw_test_options'][ ManaCore\MusicWave\Core\Migrations\MigrationRunner::LOCK_OPTION ] = time();
mw_assert_same( 0, $lock_runner->run_pending(), 'A held migration lock must prevent concurrent migration runs.' );
unset( $GLOBALS['mw_test_options'][ ManaCore\MusicWave\Core\Migrations\MigrationRunner::LOCK_OPTION ] );
mw_assert_same( 2, $lock_runner->run_pending(), 'Pending migrations must run in order once the lock is free.' );
mw_assert_same( '9.2.0', $GLOBALS['mw_test_options'][ ManaCore\MusicWave\Core\Migrations\MigrationRunner::OPTION ], 'Each migration step must persist its version for resumability.' );
mw_assert_same( false, array_key_exists( ManaCore\MusicWave\Core\Migrations\MigrationRunner::LOCK_OPTION, $GLOBALS['mw_test_options'] ), 'The migration lock must be released after a run.' );
$GLOBALS['mw_test_options'][ ManaCore\MusicWave\Core\Migrations\MigrationRunner::LOCK_OPTION ] = time() - 9999;
mw_assert_same( 0, $lock_runner->run_pending(), 'A stale lock must be reclaimed and the run must proceed (no pending steps remain).' );

// Derived-index reconciliation from canonical metadata.
$GLOBALS['mw_test_meta'][2]['mw_product_ids'] = array( 10 );
$GLOBALS['mw_test_meta'][10][ ManaCore\MusicWave\Core\Commerce\ProductMapper::REVERSE_META_KEY ] = array( 999 );
$GLOBALS['mw_test_meta'][3]['mw_collection_items'] = array(
	array(
		'release_id' => 2,
		'position'   => 1,
		'role'       => 'track',
	),
);
$reconcile_result = ( new ManaCore\MusicWave\Core\Support\IndexReconciler() )->reconcile();
mw_assert_same( array( 2 ), $GLOBALS['mw_test_meta'][10][ ManaCore\MusicWave\Core\Commerce\ProductMapper::REVERSE_META_KEY ], 'Reconciliation must rebuild product reverse indexes from canonical release metadata.' );
mw_assert_same( array( 3 ), $GLOBALS['mw_test_meta'][2]['_mw_collection_ids'], 'Reconciliation must rebuild collection reverse indexes from canonical relation metadata.' );
mw_assert_same( true, $reconcile_result['releases'] >= 2 && 1 === $reconcile_result['product_links'], 'Reconciliation must report auditable counts.' );
unset( $GLOBALS['mw_test_meta'][2]['mw_product_ids'], $GLOBALS['mw_test_meta'][10][ ManaCore\MusicWave\Core\Commerce\ProductMapper::REVERSE_META_KEY ], $GLOBALS['mw_test_meta'][3]['mw_collection_items'], $GLOBALS['mw_test_meta'][2]['_mw_collection_ids'] );

// Privacy exporter and eraser for the personal library.
$privacy_library = new ManaCore\MusicWave\Core\Library\LibraryRepository();
$privacy_library->add( 7, 'release', 2 );
$personal_data = new ManaCore\MusicWave\Core\Privacy\PersonalData( $privacy_library );
$export_result = $personal_data->export( 'buyer@example.test' );
mw_assert_same( true, $export_result['done'] && count( $export_result['data'] ) >= 1, 'The privacy exporter must return stored library items for the account email.' );
mw_assert_same( 'music-wave-library', $export_result['data'][0]['group_id'], 'Exported items must use the MusicWave library group.' );
mw_assert_same( array( 'data' => array(), 'done' => true ), $personal_data->export( 'unknown@example.test' ), 'Unknown accounts must export an empty completed dataset.' );
$erase_result = $personal_data->erase( 'buyer@example.test' );
mw_assert_same( true, $erase_result['items_removed'] && $erase_result['done'], 'The privacy eraser must delete the stored library.' );
mw_assert_same( false, isset( $GLOBALS['mw_test_user_meta'][7]['mw_music_library'] ) && array() !== $GLOBALS['mw_test_user_meta'][7]['mw_music_library'], 'Erased libraries must not persist user metadata.' );

// Library pagination keeps large collections reachable (PROJECT_PLAN.md Stage 4 deliverable 5).
$paging_repository = new ManaCore\MusicWave\Core\Library\LibraryRepository();
$paging_repository->add( 9, 'release', 2 );
$paging_repository->add( 9, 'release', 3 );
$paging_repository->add( 9, 'release', 4 );
$paging_catalog = new ManaCore\MusicWave\Core\Library\LibraryCatalog( $paging_repository );
$page_one       = $paging_catalog->paged_summaries( 9, 'all', 2, 1 );
$page_two       = $paging_catalog->paged_summaries( 9, 'all', 2, 2 );
mw_assert_same( 2, count( $page_one['items'] ), 'The first library page must contain exactly the page size.' );
mw_assert_same( true, $page_one['has_more'], 'The first library page must report that more items exist.' );
mw_assert_same( 1, count( $page_two['items'] ), 'The second library page must contain the remaining items.' );
mw_assert_same( false, $page_two['has_more'], 'The final library page must not report more items.' );

// --- Listening, durable queue, and explainable discovery (PROJECT_PLAN.md Stages 5–6) ---

$listening = new ManaCore\MusicWave\Core\Listening\ListeningRepository();
mw_assert_same( false, $listening->has_consent( 7 ), 'Listening history must be opt-in and default to no consent.' );
mw_assert_same( false, $listening->record( 7, 2, 'progress', 30 ), 'No listening event may be recorded without explicit consent.' );
$listening->set_consent( 7, true );
mw_assert_same( true, $listening->has_consent( 7 ), 'Explicit opt-in must persist.' );
mw_assert_same( false, $listening->record( 7, 5, 'progress', 30 ), 'Unreadable releases must never enter the listening history.' );
mw_assert_same( array(), $listening->recent( 7, 'progress' ), 'Without the activity table the repository must degrade to an empty history.' );

$queue_payload = array(
	'ids'      => array( 2, 2, 5, 999, 3 ),
	'position' => 9,
	'shuffle'  => 1,
	'repeat'   => 'ALL',
);
mw_assert_same( true, $listening->save_queue( 7, $queue_payload ), 'The durable queue must persist for signed-in users.' );
$saved_queue = $listening->queue( 7 );
mw_assert_same( array( 2, 3 ), $saved_queue['ids'], 'The durable queue must deduplicate and drop unreadable or unknown releases.' );
mw_assert_same( 1, $saved_queue['position'], 'The queue position must clamp to the validated item range.' );
mw_assert_same( true, $saved_queue['shuffle'], 'The shuffle preference must persist.' );
mw_assert_same( 'off', $saved_queue['repeat'], 'Invalid repeat modes must normalize to off.' );

$listening->set_consent( 7, false );
mw_assert_same( false, $listening->has_consent( 7 ), 'Withdrawing consent must persist.' );
mw_assert_same( array( 'ids' => array(), 'position' => 0, 'shuffle' => false, 'repeat' => 'off' ), $listening->queue( 7 ), 'Withdrawing consent must erase the stored queue.' );

$recommendations = new ManaCore\MusicWave\Core\Discovery\Recommendations( $listening );
$recommended     = $recommendations->recommend( 0, 8 );
mw_assert_same( true, count( $recommended ) > 0, 'Anonymous visitors must receive editorial recommendations.' );
$recommended_ids = array();
foreach ( $recommended as $recommendation ) {
	$recommended_ids[] = (int) $recommendation['release_id'];
	mw_assert_same( ManaCore\MusicWave\Core\Discovery\Recommendations::REASON_EDITORIAL, (string) $recommendation['reason'], 'Anonymous recommendations must be editorial only.' );
	mw_assert_same( true, '' !== (string) $recommendation['explanation'], 'Every recommendation must carry a human explanation.' );
}
mw_assert_same( false, in_array( 5, $recommended_ids, true ), 'Unpublished releases must never be recommended.' );

// --- Playlists: ownership, ordering, privacy, and share rules (PROJECT_PLAN.md Stage 5 deliverable 4) ---

mw_assert_same( '0.11.0', ( new ManaCore\MusicWave\Core\Migrations\Schema0110() )->version(), 'The playlist migration must carry the 0.11.0 schema version.' );

$playlist_store = new TestPlaylistStore();
$playlists      = new ManaCore\MusicWave\Core\Playlists\PlaylistRepository( $playlist_store );
$playlist_id    = $playlists->create( 7, '  Late night mixtape  ' );
mw_assert_same( true, $playlist_id > 0, 'Signed-in customers must be able to create a playlist.' );
$created = $playlists->find( $playlist_id );
mw_assert_same( 'Late night mixtape', (string) $created['title'], 'Playlist titles must be sanitized and trimmed.' );
mw_assert_same( 'private', (string) $created['visibility'], 'Playlists must be private by default.' );
mw_assert_same( '', (string) $created['share_token'], 'A private playlist must not carry a share token.' );
mw_assert_same( 0, $playlists->create( 7, '   ' ), 'Empty playlist titles must be rejected.' );
mw_assert_same( 0, $playlists->create( 0, 'Anonymous list' ), 'Anonymous callers must not create playlists.' );

mw_assert_same( true, $playlists->add_item( 7, $playlist_id, 2 ), 'Owners may add readable releases to their playlist.' );
mw_assert_same( true, $playlists->add_item( 7, $playlist_id, 3 ), 'Playlists must accept multiple releases.' );
mw_assert_same( true, $playlists->add_item( 7, $playlist_id, 4 ), 'Playlists must accept multiple releases.' );
mw_assert_same( false, $playlists->add_item( 7, $playlist_id, 2 ), 'Playlists must reject duplicate releases.' );
mw_assert_same( false, $playlists->add_item( 7, $playlist_id, 10 ), 'Non-release posts must not enter a playlist.' );
mw_assert_same( false, $playlists->add_item( 8, $playlist_id, 2 ), 'Only the owner may add playlist items.' );
mw_assert_same( false, $playlists->remove_item( 8, $playlist_id, 2 ), 'Only the owner may remove playlist items.' );
mw_assert_same( false, $playlists->reorder( 8, $playlist_id, array( 3, 2 ) ), 'Only the owner may reorder a playlist.' );
mw_assert_same( false, $playlists->delete( 8, $playlist_id ), 'Only the owner may delete a playlist.' );

mw_assert_same( true, $playlists->reorder( 7, $playlist_id, array( 4, 999, 3 ) ), 'Owners may reorder their playlist.' );
$playlist_order = array();
foreach ( $playlists->items_for_viewer( $playlist_id, 7 ) as $playlist_item ) {
	$playlist_order[] = (int) $playlist_item['release_id'];
}
mw_assert_same( array( 4, 3, 2 ), $playlist_order, 'Reordering must apply the requested order, ignore unknown IDs, and keep omitted items.' );

mw_assert_same( array(), $playlists->items_for_viewer( $playlist_id, 8 ), 'Private playlists must be invisible to other signed-in users.' );
mw_assert_same( null, $playlists->view( $playlist_id, 0 ), 'Anonymous visitors must not read a private playlist.' );

mw_assert_same( true, $playlists->update( 7, $playlist_id, array( 'visibility' => 'unlisted' ) ), 'Owners may share a playlist through an unlisted link.' );
$share_token = (string) $playlists->find( $playlist_id )['share_token'];
mw_assert_same( true, strlen( $share_token ) >= 24, 'Sharing a playlist must mint a high-entropy share token.' );
mw_assert_same( null, $playlists->view( $playlist_id, 0, '' ), 'Unlisted playlists must stay hidden without the share token.' );
mw_assert_same( null, $playlists->view( $playlist_id, 0, 'wrongtokenwrongtokenwrongtoken' ), 'A wrong share token must be refused.' );
$shared_view = $playlists->view( $playlist_id, 0, $share_token );
mw_assert_same( 3, (int) $shared_view['count'], 'The exact share token must unlock the unlisted playlist.' );
mw_assert_same( '', (string) $shared_view['share_token'], 'Share tokens must never be echoed back to non-owners.' );
mw_assert_same( false, (bool) $shared_view['owner'], 'A shared view must not claim ownership.' );

$GLOBALS['mw_test_types'][9]      = 'mw_release';
$GLOBALS['mw_test_statuses'][9]   = 'draft';
$GLOBALS['mw_test_capabilities']  = array( 'read_post' );
mw_assert_same( true, $playlists->add_item( 7, $playlist_id, 9 ), 'A privileged owner may place an unpublished release in their own playlist.' );
mw_assert_same( 4, count( $playlists->items_for_viewer( $playlist_id, 7 ) ), 'Owners see every playlist release they may read.' );
$GLOBALS['mw_test_capabilities'] = array();
mw_assert_same( 3, (int) $playlists->view( $playlist_id, 0, $share_token )['count'], 'Shared playlist views must expose published releases only.' );

mw_assert_same( true, $playlists->update( 7, $playlist_id, array( 'visibility' => 'private' ) ), 'Owners may revoke sharing.' );
mw_assert_same( null, $playlists->find_shared( $share_token ), 'A revoked share link must stop resolving.' );
mw_assert_same( true, $playlists->update( 7, $playlist_id, array( 'visibility' => 'unlisted' ) ), 'Owners may share a playlist again.' );
mw_assert_same( false, $share_token === (string) $playlists->find( $playlist_id )['share_token'], 'Re-sharing must mint a fresh token instead of restoring the revoked link.' );

$public_playlist_id = $playlists->create( 7, 'Editorial picks', 'public' );
$playlists->add_item( 7, $public_playlist_id, 2 );
mw_assert_same( true, $playlists->can_view( $playlists->find( $public_playlist_id ), 0 ), 'Public playlists must be readable by anyone.' );
mw_assert_same( 1, (int) $playlists->view( $public_playlist_id, 0 )['count'], 'Public playlist views must list published items.' );

// The community listing ships cover previews for the theme's fanned artwork
// stack: published items only (a draft the owner may read must not leak its
// cover to anonymous callers), thumbnail URL when present, empty otherwise.
$playlists->add_item( 7, $public_playlist_id, 3 );
$playlists->add_item( 7, $public_playlist_id, 4 );
$GLOBALS['mw_test_capabilities'] = array( 'read_post' );
mw_assert_same( true, $playlists->add_item( 7, $public_playlist_id, 9 ), 'The owner may keep an unpublished release in a public playlist.' );
$GLOBALS['mw_test_capabilities']  = array();
$GLOBALS['mw_test_thumbnails'][2] = 'https://cdn.example.test/covers/2.jpg';
$GLOBALS['mw_test_titles'][3]     = 'Night Drive';
$GLOBALS['mw_test_current_user']  = 0;
$public_listing                   = ( new ManaCore\MusicWave\Core\Playlists\PlaylistRoutes( $playlists ) )->public_index( new WP_REST_Request( array( 'per_page' => 12 ) ) );
$public_listing_data              = $public_listing->get_data();
$public_listing_card              = null;
foreach ( $public_listing_data['items'] as $public_listing_item ) {
	if ( (int) $public_listing_item['id'] === $public_playlist_id ) {
		$public_listing_card = $public_listing_item;
	}
}
mw_assert_same( true, is_array( $public_listing_card ), 'The public playlists listing must include public playlists.' );
mw_assert_same( false, array_key_exists( 'share_token', (array) $public_listing_card ), 'The public listing must never expose share tokens.' );
mw_assert_same(
	array(
		array(
			'title' => 'Release 2',
			'image' => 'https://cdn.example.test/covers/2.jpg',
		),
		array(
			'title' => 'Night Drive',
			'image' => '',
		),
		array(
			'title' => 'Release 4',
			'image' => '',
		),
	),
	$public_listing_card['covers'],
	'Public playlist cards must carry cover previews for published items only, with thumbnail URLs when available.'
);
mw_assert_same( 3, (int) $public_listing_card['count'], 'Public card counts must match the publicly visible items.' );
unset( $GLOBALS['mw_test_thumbnails'][2], $GLOBALS['mw_test_titles'][3] );
$playlists->remove_item( 7, $public_playlist_id, 3 );
$playlists->remove_item( 7, $public_playlist_id, 4 );
$playlists->remove_item( 7, $public_playlist_id, 9 );

mw_assert_same( 2, count( $playlists->export( 7 ) ), 'Playlists must be exportable for privacy requests.' );
$playlists->handle_deleted_post( 2 );
mw_assert_same( 0, (int) $playlists->view( $public_playlist_id, 0 )['count'], 'Deleting a release must remove it from every playlist.' );
mw_assert_same( true, $playlists->erase( 7 ), 'Privacy erasure must delete every playlist a user owns.' );
mw_assert_same( array(), $playlists->for_user( 7 ), 'Erased playlists must not come back.' );

// The database-backed store must degrade instead of failing before its migration runs.
mw_assert_same( false, ( new ManaCore\MusicWave\Core\Playlists\DatabasePlaylistStore() )->available(), 'Playlist storage must report unavailable until the migration has run.' );
mw_assert_same( 0, ( new ManaCore\MusicWave\Core\Playlists\PlaylistRepository( new ManaCore\MusicWave\Core\Playlists\DatabasePlaylistStore() ) )->create( 7, 'Pending schema' ), 'Playlist creation must degrade safely without its tables.' );

// --- Wishlist and pre-save (PROJECT_PLAN.md Stage 5 deliverable 5) ---

$GLOBALS['mw_test_meta'][3]['mw_release_date'] = gmdate( 'Y-m-d', time() + ( 30 * 86400 ) );
$GLOBALS['mw_test_meta'][4]['mw_release_date'] = gmdate( 'Y-m-d', time() - ( 30 * 86400 ) );

$wishlist_library = new ManaCore\MusicWave\Core\Library\LibraryRepository();
mw_assert_same( true, $wishlist_library->add( 9, 'wishlist', 4 ), 'Released items must be wishlistable.' );
mw_assert_same( false, $wishlist_library->add( 9, 'wishlist', 4 ), 'Duplicate wishlist entries must be rejected.' );
mw_assert_same( true, $wishlist_library->add( 9, 'presave', 3 ), 'Upcoming releases must be pre-savable.' );
mw_assert_same( false, $wishlist_library->add( 9, 'presave', 4 ), 'Already-released items must not be pre-saved.' );
mw_assert_same( false, $wishlist_library->add( 9, 'presave', 10 ), 'Non-release posts must not be pre-saved.' );

$wishlist_catalog = new ManaCore\MusicWave\Core\Library\LibraryCatalog( $wishlist_library );
$wishlist_counts  = $wishlist_catalog->counts( 9 );
mw_assert_same( 1, isset( $wishlist_counts['wishlist'] ) ? (int) $wishlist_counts['wishlist'] : 0, 'Wishlist items must count under their own filter.' );
mw_assert_same( 1, isset( $wishlist_counts['presaves'] ) ? (int) $wishlist_counts['presaves'] : 0, 'Pre-saves must count under their own filter.' );
$presave_summaries = $wishlist_catalog->summaries( 9, 'presaves' );
mw_assert_same( 1, count( $presave_summaries ), 'The pre-save filter must list pre-saved releases only.' );
mw_assert_same( 3, (int) $presave_summaries[0]['id'], 'Pre-save summaries must resolve the pre-saved release.' );
mw_assert_same( 'presave', (string) $presave_summaries[0]['type'], 'Pre-save summaries must keep their item type.' );

$presave_scheduler = new ManaCore\MusicWave\Core\Library\PreSaveScheduler( $wishlist_library );
mw_assert_same( false, $presave_scheduler->fulfill_for_user( 3, 9 ), 'A pre-save must not be fulfilled before its release date.' );
mw_assert_same( 0, $presave_scheduler->fulfill( 3 ), 'Fulfillment must stay pending while the release date is in the future.' );
$GLOBALS['mw_test_meta'][3]['mw_release_date'] = gmdate( 'Y-m-d', time() - 86400 );
mw_assert_same( true, $presave_scheduler->fulfill_for_user( 3, 9 ), 'When the release date arrives the pre-save must become a saved release.' );
mw_assert_same( false, $wishlist_library->has( 9, 'presave', 3 ), 'A fulfilled pre-save must be cleared.' );
mw_assert_same( true, $wishlist_library->has( 9, 'release', 3 ), 'A fulfilled pre-save must appear as a saved library release.' );
mw_assert_same( true, in_array( 'music_wave_presave_fulfilled', $GLOBALS['mw_test_actions'], true ), 'Fulfillment must fire the notification action for integrations.' );
mw_assert_same( false, $presave_scheduler->fulfill_for_user( 3, 9 ), 'Pre-save fulfillment must be idempotent.' );
mw_assert_same( array(), $wishlist_library->users_with( 'presave', 3 ), 'Pre-save lookups must degrade to an empty list without the usermeta index.' );

// --- Catalog autocomplete, facets, and the search adapter boundary (PROJECT_PLAN.md Stage 5 deliverable 6) ---

$catalog_search = new ManaCore\MusicWave\Core\Discovery\CatalogSearch();
mw_assert_same( '', $catalog_search->sanitize_term( ' a ' ), 'Search terms shorter than the minimum must be refused.' );
mw_assert_same( 'Release 3', $catalog_search->sanitize_term( "  Release\n  3  " ), 'Search terms must be sanitized and whitespace-collapsed.' );
mw_assert_same( array(), $catalog_search->suggest( 'x' ), 'Autocomplete must not query the catalog for a too-short term.' );

$suggestions = $catalog_search->suggest( 'Release 3', 8 );
$suggested   = array();
foreach ( $suggestions as $suggestion ) {
	if ( 'release' === (string) $suggestion['type'] ) {
		$suggested[] = (int) $suggestion['id'];
	}
}
mw_assert_same( array( 3 ), $suggested, 'Autocomplete must return releases whose public title matches the term.' );
mw_assert_same( 'https://example.test/?p=3', (string) $suggestions[0]['url'], 'Suggestions must carry a public permalink.' );

$GLOBALS['mw_test_types'][12]    = 'mw_release';
$GLOBALS['mw_test_statuses'][12] = 'draft';
$search_adapter                  = new TestCatalogSearchAdapter();
$search_adapter->proposed        = array( 12, 4, 4, 0 );
$adapter_search                  = new ManaCore\MusicWave\Core\Discovery\CatalogSearch( null, $search_adapter );
$GLOBALS['mw_test_transients']   = array();
$adapter_suggestions             = $adapter_search->suggest( 'Release', 8 );
$adapter_ids                     = array();
foreach ( $adapter_suggestions as $suggestion ) {
	if ( 'release' === (string) $suggestion['type'] ) {
		$adapter_ids[] = (int) $suggestion['id'];
	}
}
mw_assert_same( array( 4 ), $adapter_ids, 'An external search adapter must never bypass the release visibility policy.' );
mw_assert_same( array( 'Release' ), $search_adapter->terms, 'The adapter must receive the sanitized term exactly once per uncached query.' );
$adapter_search->suggest( 'Release', 8 );
mw_assert_same( 1, count( $search_adapter->terms ), 'Repeated autocomplete queries must be served from cache.' );

$search_adapter->proposed      = null;
$GLOBALS['mw_test_transients'] = array();
$fallback_suggestions          = $adapter_search->suggest( 'Release 4', 8 );
mw_assert_same( true, count( $fallback_suggestions ) > 0, 'A null adapter result must fall back to native catalog search.' );

$GLOBALS['mw_test_transients'] = array();
$facets                        = $catalog_search->facets( array( 'mw_genre' => 'rock,rock, ', 'unknown_tax' => 'x' ) );
mw_assert_same( array( 'mw_genre' => array( 'rock' ) ), $facets['filters'], 'Facet filters must be deduplicated and restricted to known taxonomies.' );
mw_assert_same( false, $facets['approximate'], 'Small result sets must report exact facet counts.' );
mw_assert_same( true, isset( $facets['facets']['mw_release_type'] ), 'Facets must cover every catalog taxonomy.' );
$release_type_counts = array();
foreach ( $facets['facets']['mw_release_type'] as $facet_term ) {
	$release_type_counts[ (string) $facet_term['slug'] ] = (int) $facet_term['count'];
}
mw_assert_same( true, isset( $release_type_counts['album'] ) && $release_type_counts['album'] >= 1, 'Facet counts must aggregate release-type terms of the matched releases.' );
mw_assert_same( false, isset( $release_type_counts['podcast-episode'] ) && $release_type_counts['podcast-episode'] > 1, 'Facet counts must not double-count a release.' );

$discovery_limiter = new ManaCore\MusicWave\Core\Discovery\DiscoveryRateLimiter( 2, 60 );
mw_assert_same( true, $discovery_limiter->allow( 'suggest' ), 'The first public discovery request must be allowed.' );
mw_assert_same( true, $discovery_limiter->allow( 'suggest' ), 'Requests below the limit must be allowed.' );
mw_assert_same( false, $discovery_limiter->allow( 'suggest' ), 'Public discovery requests must be rate limited per actor and window.' );
mw_assert_same( true, $discovery_limiter->allow( 'facets' ), 'Rate-limit buckets must be independent per route.' );

$discovery_routes = new ManaCore\MusicWave\Core\Discovery\DiscoveryRoutes( $recommendations, $catalog_search, $discovery_limiter );
$short_term_error = $discovery_routes->suggest( new WP_REST_Request( array( 'term' => 'a' ) ) );
mw_assert_same( true, is_wp_error( $short_term_error ), 'The autocomplete route must reject a too-short term with an actionable error.' );

// --- Batched taxonomy index and list-query budgets (PROJECT_PLAN.md Stage 5 deliverable 7) ---

$GLOBALS['mw_test_term_queries']['object'] = 0;
$term_index                                = new ManaCore\MusicWave\Core\Catalog\ReleaseTermIndex();
$term_index->prime( array( 1, 2, 3, 4 ), array( 'mw_release_type', 'mw_genre' ) );
mw_assert_same( 1, $GLOBALS['mw_test_term_queries']['object'], 'Priming a release set must use exactly one batched taxonomy query.' );
mw_assert_same( array( 'album' ), $term_index->slugs( 1, 'mw_release_type' ), 'The batched index must map terms back to the right release.' );
mw_assert_same( array( 'track' ), $term_index->slugs( 2, 'mw_release_type' ), 'The batched index must map terms back to the right release.' );
mw_assert_same( array(), $term_index->slugs( 1, 'mw_genre' ), 'A primed taxonomy with no terms must resolve to an empty list.' );
mw_assert_same( 1, $GLOBALS['mw_test_term_queries']['object'], 'Primed lookups, including empty ones, must never re-query.' );
$term_index->slugs( 8, 'mw_release_type' );
mw_assert_same( 2, $GLOBALS['mw_test_term_queries']['object'], 'An unprimed release must resolve lazily with a single query.' );

$GLOBALS['mw_test_transients']             = array();
$GLOBALS['mw_test_term_queries']['object'] = 0;
$facet_budget                              = ( new ManaCore\MusicWave\Core\Discovery\CatalogSearch() )->facets( array() );
mw_assert_same( true, $facet_budget['matched'] > 1, 'The facet scan must cover the published catalog.' );
mw_assert_same( 1, $GLOBALS['mw_test_term_queries']['object'], 'Facet counts must resolve the whole scanned set in one batched taxonomy query.' );

$perf_library = new ManaCore\MusicWave\Core\Library\LibraryRepository();
$perf_library->add( 20, 'release', 2 );
$perf_library->add( 20, 'release', 3 );
$perf_library->add( 20, 'wishlist', 4 );
$GLOBALS['mw_test_term_queries']['object'] = 0;
$perf_catalog                              = new ManaCore\MusicWave\Core\Library\LibraryCatalog( $perf_library );
mw_assert_same( 3, count( $perf_catalog->summaries( 20 ) ), 'Library summaries must render every stored item.' );
mw_assert_same( 1, $GLOBALS['mw_test_term_queries']['object'], 'Library summaries must batch taxonomy lookups for the whole page.' );
$GLOBALS['mw_test_term_queries']['object'] = 0;
$perf_counts                               = $perf_catalog->counts( 20 );
mw_assert_same( 3, (int) $perf_counts['all'], 'Library counts must include every stored item type.' );
mw_assert_same( 0, $GLOBALS['mw_test_term_queries']['object'], 'Repeated library rendering in one request must reuse the primed index.' );

$mapper = new ManaCore\MusicWave\Core\Commerce\ProductMapper();
$mapper->sync_reverse_index( 1, array(), array( 10, 11 ) );
mw_assert_same( array( 1 ), get_post_meta( 10, '_mw_release_ids', true ), 'Product reverse index must be created.' );
$GLOBALS['mw_test_meta'][1]['mw_product_ids'] = array( 10, 11 );
$mapper->handle_deleted_post( 11 );
mw_assert_same( array( 10 ), get_post_meta( 1, 'mw_product_ids', true ), 'Deleting a product must clean release mappings.' );

$repository                           = new TestReleaseRepository();
$repository->products                 = array( 10 );
$checker                              = new ManaCore\MusicWave\Core\Commerce\PurchaseChecker( $repository );
$GLOBALS['mw_test_purchases']['7:10'] = true;
mw_assert_same( true, $checker->user_owns_release( 7, 1 ), 'A paid mapped product must grant ownership.' );
$GLOBALS['mw_test_purchases']['7:10'] = false;
mw_assert_same( false, $checker->user_owns_release( 7, 1 ), 'A refunded product must not grant ownership.' );
mw_assert_same( false, $checker->user_owns_release( 0, 1 ), 'Anonymous users must not gain purchase ownership.' );

$policy_repository = new TestPolicyRepository();
$membership        = new TestMembershipProvider();
$engine            = new ManaCore\MusicWave\Core\Access\AccessPolicyEngine( $policy_repository, $checker, $membership );
$anonymous         = new ManaCore\MusicWave\Core\Access\AccessSubject();
$customer          = new ManaCore\MusicWave\Core\Access\AccessSubject( 7 );
$administrator     = new ManaCore\MusicWave\Core\Access\AccessSubject( 1, array( 'manage_options' ) );
mw_assert_same( true, $engine->decide( 1, $anonymous )->is_allowed(), 'Public releases must allow anonymous visitors.' );
$policy_repository->values['mw_access_mode'] = 'restricted';
mw_assert_same( false, $engine->decide( 1, $anonymous )->is_allowed(), 'Restricted releases must deny anonymous visitors.' );
$policy_repository->values['mw_access_mode'] = 'purchase';
mw_assert_same( false, $engine->decide( 1, $customer )->is_allowed(), 'Unpurchased releases must deny customers.' );
$GLOBALS['mw_test_purchases']['7:10'] = true;
mw_assert_same( true, $engine->decide( 1, $customer )->is_allowed(), 'Purchased releases must allow customers.' );
$GLOBALS['mw_test_purchases']['7:10']              = false;
$policy_repository->values['mw_access_mode']       = 'membership';
$policy_repository->values['mw_membership_levels'] = array( 'gold' );
$membership->granted                               = true;
mw_assert_same( true, $engine->decide( 1, $customer )->is_allowed(), 'Membership provider grants membership releases.' );
$membership->granted                         = false;
$policy_repository->values['mw_access_mode'] = 'unknown';
mw_assert_same( false, $engine->decide( 1, $customer )->is_allowed(), 'Unknown access modes must deny by default.' );
mw_assert_same( true, $engine->decide( 1, $administrator )->is_allowed(), 'Administrator override must be explicit and auditable.' );

$library_repository = new ManaCore\MusicWave\Core\Library\LibraryRepository();
mw_assert_same( true, $library_repository->add( 7, 'release', 2 ), 'Valid releases must be addable to the personal library.' );
mw_assert_same( false, $library_repository->add( 7, 'release', 2 ), 'Duplicate personal library items must be rejected.' );
mw_assert_same( false, $library_repository->add( 7, 'release', 10 ), 'Non-release posts must not enter the personal library.' );
mw_assert_same( false, $library_repository->add( 7, 'unknown', 2 ), 'Unknown personal library item types must be rejected.' );
mw_assert_same( true, $library_repository->add( 7, 'artist', 101 ), 'Artist terms must be addable to the personal library.' );
mw_assert_same( false, $library_repository->add( 7, 'artist', 999 ), 'Unknown artists must not enter the personal library.' );
mw_assert_same( array( 2 ), $library_repository->ids( 7, 'release' ), 'Stored library release IDs must remain queryable by type.' );
mw_assert_same( 2, $library_repository->count( 7 ), 'Library counts must include every stored item type.' );
mw_assert_same( true, $library_repository->remove( 7, 'release', 2 ), 'Stored library items must be removable.' );
mw_assert_same( false, $library_repository->has( 7, 'release', 2 ), 'Removed library items must no longer report as saved.' );

// --- Confidentiality regressions: shared release visibility policy (PROJECT_PLAN.md Stage 1) ---

$visibility = new ManaCore\MusicWave\Core\Catalog\ReleaseVisibility();

$GLOBALS['mw_test_types'][5]    = 'mw_release';
$GLOBALS['mw_test_statuses'][5] = 'draft';
mw_assert_same( true, $visibility->is_public( 2 ), 'Published releases must be publicly visible.' );
mw_assert_same( false, $visibility->is_public( 5 ), 'Draft releases must never be publicly visible.' );
mw_assert_same( false, $visibility->is_public( 10 ), 'Non-release posts must not pass the release visibility policy.' );
mw_assert_same( false, $visibility->can_read( 5 ), 'Anonymous actors must not read unpublished releases.' );
$GLOBALS['mw_test_capabilities'] = array( 'read_post' );
mw_assert_same( true, $visibility->can_read( 5 ), 'Actors holding the object read capability may read unpublished releases.' );
$GLOBALS['mw_test_capabilities'] = array();

// Library persistence must not accept or expose unpublished releases for unprivileged actors.
mw_assert_same( false, $library_repository->add( 7, 'release', 5 ), 'Draft releases must not enter the personal library without read capability.' );
$GLOBALS['mw_test_capabilities'] = array( 'read_post' );
mw_assert_same( true, $library_repository->add( 7, 'release', 5 ), 'Privileged actors may store an unpublished release in their library.' );
$GLOBALS['mw_test_capabilities'] = array();

$GLOBALS['mw_test_terms_by_tax'][2] = array(
	'mw_artist' => array(),
	'mw_genre'  => array(),
);
$GLOBALS['mw_test_terms_by_tax'][5] = array(
	'mw_artist' => array(),
	'mw_genre'  => array(),
);
$library_repository->add( 7, 'release', 2 );
$library_catalog   = new ManaCore\MusicWave\Core\Library\LibraryCatalog( $library_repository );
$library_summaries = $library_catalog->summaries( 7 );
$summary_ids       = array();
foreach ( $library_summaries as $library_summary ) {
	if ( 'release' === $library_summary['type'] ) {
		$summary_ids[] = (int) $library_summary['id'];
	}
}
mw_assert_same( true, in_array( 2, $summary_ids, true ), 'Published stored releases must appear in library summaries.' );
mw_assert_same( false, in_array( 5, $summary_ids, true ), 'Unpublished stored releases must be hidden from unprivileged library summaries.' );

// JSON-LD must not disclose unpublished child releases inside collection track lists.
$jsonld_repository        = new TestCollectionRepository();
$jsonld_repository->items = array(
	array(
		'release_id' => 2,
		'position'   => 1,
	),
	array(
		'release_id' => 5,
		'position'   => 2,
	),
);
$GLOBALS['mw_test_types'][6]        = 'mw_release';
$GLOBALS['mw_test_release_types'][6] = array( 'album' );
$GLOBALS['mw_test_terms_by_tax'][6] = array(
	'mw_artist' => array(),
	'mw_genre'  => array(),
);
$jsonld = new ManaCore\MusicWave\Core\Seo\ReleaseJsonLd( $jsonld_repository, $visibility );
$schema = $jsonld->schema( 6 );
$track_names = array();
foreach ( isset( $schema['track'] ) && is_array( $schema['track'] ) ? $schema['track'] : array() as $track ) {
	$track_names[] = isset( $track['url'] ) ? (string) $track['url'] : '';
}
mw_assert_same( 1, count( $track_names ), 'Collection JSON-LD must list only published child releases.' );
mw_assert_same( false, in_array( 'https://example.test/?p=5', $track_names, true ), 'Unpublished child releases must never appear in public JSON-LD.' );
mw_assert_same( 1, isset( $schema['numTracks'] ) ? (int) $schema['numTracks'] : 0, 'JSON-LD track counts must exclude unpublished children.' );

$GLOBALS['mw_test_release_types'][6] = array( 'playlist' );
mw_assert_same( 'MusicPlaylist', $jsonld->schema( 6 )['@type'], 'A curated playlist must not claim to be a published MusicAlbum.' );
$GLOBALS['mw_test_release_types'][6] = array( 'album' );
mw_assert_same( 'MusicAlbum', $jsonld->schema( 6 )['@type'], 'Album schema remains MusicAlbum.' );

// Public REST projections must redact gated release bodies for denied actors.
$rest_policy_repository = new TestPolicyRepository();
$rest_policy_repository->values['mw_access_mode'] = 'restricted';
$rest_engine   = new ManaCore\MusicWave\Core\Access\AccessPolicyEngine( $rest_policy_repository, $checker, new TestMembershipProvider() );
$rest_policy   = new ManaCore\MusicWave\Core\Infrastructure\ReleaseRestVisibilityPolicy( $rest_engine );
$rest_response = new TestRestResponse(
	array(
		'id'      => 1,
		'content' => array(
			'rendered'  => 'SECRET BODY',
			'protected' => false,
		),
		'excerpt' => array(
			'rendered'  => 'SECRET EXCERPT',
			'protected' => false,
		),
	)
);
$rest_result   = $rest_policy->prepare( $rest_response, new WP_Post( 1, 'mw_release' ), null );
$rest_data     = $rest_result->get_data();
mw_assert_same( '', $rest_data['content']['rendered'], 'Restricted release bodies must be redacted from public REST responses.' );
mw_assert_same( true, $rest_data['content']['protected'], 'Redacted REST content must be marked protected.' );
mw_assert_same( '', $rest_data['excerpt']['rendered'], 'Restricted release excerpts must be redacted from public REST responses.' );
mw_assert_same( false, $rest_data['music_wave_access']['allowed'], 'Redacted REST responses must expose the denial marker.' );

$rest_policy_repository->values['mw_access_mode'] = 'public';
$open_response = new TestRestResponse(
	array(
		'content' => array(
			'rendered'  => 'PUBLIC BODY',
			'protected' => false,
		),
	)
);
$open_result   = $rest_policy->prepare( $open_response, new WP_Post( 1, 'mw_release' ), null );
$open_data     = $open_result->get_data();
mw_assert_same( 'PUBLIC BODY', $open_data['content']['rendered'], 'Public release bodies must remain readable through REST.' );

require dirname( __DIR__ ) . '/music-wave-core/music-wave-core.php';
mw_assert_same( true, in_array( 'music_wave_core_loaded', $GLOBALS['mw_test_actions'], true ), 'Plugin composition root must boot successfully.' );

// --- Playlist UI layer: no-JS operations and accessible markup (PROJECT_PLAN.md Stage 4 deliverable 2, Stage 5 deliverable 4) ---

$ui_store      = new TestPlaylistStore();
$ui_playlists  = new ManaCore\MusicWave\Core\Playlists\PlaylistRepository( $ui_store );
$ui_forms      = new ManaCore\MusicWave\Core\Playlists\PlaylistFormHandler( $ui_playlists );

list( $ui_notice, $ui_created ) = $ui_forms->run( 'create', 7, 0, 0, 'Road trip', 'private' );
mw_assert_same( 'created', $ui_notice, 'The no-JS create operation must create a playlist.' );
mw_assert_same( true, $ui_created > 0, 'The create operation must return the new playlist for focus.' );
mw_assert_same( array( 'create-failed', 0 ), $ui_forms->run( 'create', 7, 0, 0, '   ', '' ), 'An empty title must fail without creating a playlist.' );
mw_assert_same( array( 'invalid', 0 ), $ui_forms->run( 'wat', 7, 0, 0, '', '' ), 'Unknown operations must be rejected.' );
mw_assert_same( array( 'item-added', $ui_created ), $ui_forms->run( 'add-item', 7, $ui_created, 2, '', '' ), 'The no-JS add operation must add a readable release.' );
$ui_forms->run( 'add-item', 7, $ui_created, 3, '', '' );
$ui_forms->run( 'add-item', 7, $ui_created, 4, '', '' );
mw_assert_same( array( 'item-add-failed', $ui_created ), $ui_forms->run( 'add-item', 8, $ui_created, 2, '', '' ), 'A non-owner must not mutate a playlist through the form handler.' );

mw_assert_same( array( 'moved', $ui_created ), $ui_forms->run( 'move-down', 7, $ui_created, 2, '', '' ), 'Move down must reorder the playlist.' );
$ui_order = array();
foreach ( $ui_playlists->items_for_viewer( $ui_created, 7 ) as $ui_item ) {
	$ui_order[] = (int) $ui_item['release_id'];
}
mw_assert_same( array( 3, 2, 4 ), $ui_order, 'Move down must swap exactly one position.' );
mw_assert_same( array( 'moved', $ui_created ), $ui_forms->run( 'move-up', 7, $ui_created, 2, '', '' ), 'Move up must reorder the playlist.' );
mw_assert_same( array( 'move-failed', $ui_created ), $ui_forms->run( 'move-up', 7, $ui_created, 2, '', '' ), 'Moving the first item up must fail instead of wrapping around.' );
mw_assert_same( array( 'move-failed', $ui_created ), $ui_forms->run( 'move-down', 8, $ui_created, 2, '', '' ), 'A non-owner must not reorder a playlist.' );
mw_assert_same( array( 'item-removed', $ui_created ), $ui_forms->run( 'remove-item', 7, $ui_created, 4, '', '' ), 'The no-JS remove operation must remove an item.' );
mw_assert_same( array( 'updated', $ui_created ), $ui_forms->run( 'update', 7, $ui_created, 0, 'Road trip 2026', 'unlisted' ), 'The no-JS update operation must rename and share.' );
mw_assert_same( 'unlisted', (string) $ui_playlists->find( $ui_created )['visibility'], 'The update operation must persist the visibility change.' );
mw_assert_same( array( 'update-failed', $ui_created ), $ui_forms->run( 'update', 8, $ui_created, 0, 'Hijacked', '' ), 'A non-owner must not rename a playlist.' );
mw_assert_same( true, '' !== $ui_forms->notice_message( 'created' ), 'Every notice code must carry a translated message.' );
mw_assert_same( true, $ui_forms->notice_is_error( 'item-add-failed' ), 'Failure notices must be flagged as errors.' );
mw_assert_same( false, $ui_forms->notice_is_error( 'item-added' ), 'Success notices must not be flagged as errors.' );

$ui_blocks = new ManaCore\MusicWave\Core\Blocks\PlaylistBlocks( $ui_playlists, $ui_forms );
$GLOBALS['mw_test_current_user'] = 7;
$ui_markup                       = $ui_blocks->render_manager( array( 'heading' => 'My lists' ) );
mw_assert_same( true, false !== strpos( $ui_markup, 'Road trip 2026' ), 'The playlist manager must render the listener\'s playlists.' );
mw_assert_same( true, false !== strpos( $ui_markup, 'method="post"' ), 'Every playlist control must work as a plain form post without JavaScript.' );
mw_assert_same( true, false !== strpos( $ui_markup, 'name="mw_operation" value="create"' ), 'The manager must expose the create operation.' );
mw_assert_same( true, false !== strpos( $ui_markup, 'aria-expanded=' ), 'The track toggle must expose its expanded state.' );
mw_assert_same( true, false !== strpos( $ui_markup, 'mw_nonce_field' ) || false !== strpos( $ui_markup, '_wpnonce' ), 'Playlist forms must carry a nonce field.' );
mw_assert_same( false, false !== strpos( $ui_markup, (string) $ui_playlists->find( $ui_created )['share_token'] ) && 7 !== $GLOBALS['mw_test_current_user'], 'Share tokens must only render for the owner.' );

$ui_picker = $ui_blocks->render_picker( array( 'releaseId' => 2 ) );
mw_assert_same( true, false !== strpos( $ui_picker, 'name="mw_operation" value="add-item"' ), 'The picker must post the add-item operation.' );
mw_assert_same( true, false !== strpos( $ui_picker, 'name="mw_release_id" value="2"' ), 'The picker must target the rendered release.' );
mw_assert_same( true, false !== strpos( $ui_picker, '<label for=' ), 'The picker select must have an associated label.' );

$GLOBALS['mw_test_current_user'] = 0;
$ui_guest                        = $ui_blocks->render_manager( array() );
mw_assert_same( true, false !== strpos( $ui_guest, 'mw-playlists--guest' ), 'Signed-out visitors must see the sign-in state instead of controls.' );
mw_assert_same( false, false !== strpos( $ui_guest, 'mw_operation' ), 'Signed-out visitors must not receive playlist mutation forms.' );
$GLOBALS['mw_test_current_user'] = 0;

// Wishlist and pre-save buttons reuse the shared library button.
ManaCore\MusicWave\Core\Library\LibraryButton::bind( $wishlist_library );
$GLOBALS['mw_test_current_user']               = 9;
$GLOBALS['mw_test_meta'][3]['mw_release_date'] = gmdate( 'Y-m-d', time() + ( 10 * 86400 ) );
$presave_button                                = ManaCore\MusicWave\Core\Library\LibraryButton::markup( 'presave', 3 );
mw_assert_same( true, false !== strpos( $presave_button, 'data-mw-library-type="presave"' ), 'The pre-save button must post the presave item type.' );
mw_assert_same( true, false !== strpos( $presave_button, 'aria-pressed=' ), 'Library toggles must expose their pressed state.' );
$GLOBALS['mw_test_meta'][3]['mw_release_date'] = gmdate( 'Y-m-d', time() - 86400 );
mw_assert_same( '', ManaCore\MusicWave\Core\Library\LibraryButton::markup( 'presave', 3 ), 'A released item must not render a pre-save button.' );
mw_assert_same( true, false !== strpos( ManaCore\MusicWave\Core\Library\LibraryButton::markup( 'wishlist', 4 ), 'data-mw-library-type="wishlist"' ), 'The wishlist button must post the wishlist item type.' );
mw_assert_same( false, false !== strpos( ManaCore\MusicWave\Core\Library\LibraryButton::markup( 'wishlist', 4 ), 'mw-library-button__count' ), 'The saved-by badge must stay opt-in.' );

// Public "saved by" aggregate: totals only, served from a short-lived cache.
$GLOBALS['mw_test_transients']['mw_library_count_wishlist_4'] = 27;
mw_assert_same( 27, $wishlist_library->count_for_item( 'wishlist', 4 ), 'The saved-by aggregate must read the cached count.' );
mw_assert_same( 0, $wishlist_library->count_for_item( 'review', 4 ), 'Unknown item types must never produce an aggregate.' );
mw_assert_same( 0, $wishlist_library->count_for_item( 'wishlist', 0 ), 'Missing item IDs must never produce an aggregate.' );
$count_markup = ManaCore\MusicWave\Core\Library\LibraryButton::markup( 'wishlist', 4, array( 'showCount' => true ) );
mw_assert_same( true, false !== strpos( $count_markup, 'mw-library-button--has-count' ), 'A visible badge must flag the button layout.' );
mw_assert_same( true, false !== strpos( $count_markup, 'data-mw-library-count-value="27"' ), 'The badge must expose the aggregate for client-side sync.' );
mw_assert_same( true, false !== strpos( $count_markup, ' aria-hidden="true">27</span>' ), 'The badge must print the localized total.' );
mw_assert_same( false, false !== strpos( $count_markup, 'data-mw-library-user' ), 'The badge must never leak member identities.' );
$zero_markup = ManaCore\MusicWave\Core\Library\LibraryButton::markup( 'wishlist', 5, array( 'showCount' => true ) );
mw_assert_same( true, false !== strpos( $zero_markup, 'data-mw-library-count-value="0"' ), 'Zero totals must still render a syncable badge.' );
mw_assert_same( true, false !== strpos( $zero_markup, ' hidden>' ), 'Zero totals must be hidden until the first save.' );
$GLOBALS['mw_test_current_user'] = 0;

// --- Artists shelf and continue-listening presentation surfaces ---

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

$shelf_terms_backup   = $GLOBALS['mw_test_terms'];
$shelf_term_meta_bak  = isset( $GLOBALS['mw_test_term_meta'] ) ? $GLOBALS['mw_test_term_meta'] : array();
$artists_shelf        = new ManaCore\MusicWave\Core\Blocks\ArtistShelfBlock();
$shelf_aria           = wp_insert_term( 'Aria', 'mw_artist', array( 'slug' => 'aria' ) );
$shelf_bo             = wp_insert_term( 'Bo', 'mw_artist', array( 'slug' => 'bo' ) );
$shelf_caius          = wp_insert_term( 'Caius', 'mw_artist', array( 'slug' => 'cai' ) );
$GLOBALS['mw_test_terms'][ $shelf_aria['term_id'] ]->count  = 12;
$GLOBALS['mw_test_terms'][ $shelf_bo['term_id'] ]->count    = 3;
$GLOBALS['mw_test_terms'][ $shelf_caius['term_id'] ]->count = 7;
update_term_meta( (int) $shelf_aria['term_id'], 'mw_artist_biography', '<strong>Aria</strong> sings over waves of synth.' );

$GLOBALS['mw_test_current_user'] = 9;
$shelf_markup                    = $artists_shelf->render(
	array(
		'source'     => 'all',
		'heading'    => 'Popular artists',
		'imageShape' => 'circle',
	)
);
$GLOBALS['mw_test_current_user'] = 0;
mw_assert_same( true, false !== strpos( $shelf_markup, 'mw-artists-shelf mw-release-shelf mw-release-shelf--grid mw-release-shelf--columns-4' ), 'The artists shelf must reuse the shared release-shelf chrome with grid columns.' );
mw_assert_same( true, false !== strpos( $shelf_markup, '>Popular artists</h2>' ), 'The artists shelf must render its heading chrome.' );
foreach ( array( 'Aria', 'Bo', 'Caius' ) as $shelf_name ) {
	mw_assert_same( true, false !== strpos( $shelf_markup, '>' . $shelf_name . '</a>' ), 'The artists shelf must render the artist name ' . $shelf_name . '.' );
}
mw_assert_same( true, false !== strpos( $shelf_markup, 'https://example.test/artist/' . $shelf_aria['term_id'] ), 'Artist cards must link to their archive pages.' );
mw_assert_same( true, false !== strpos( $shelf_markup, 'mw-artists-shelf__avatar--circle' ), 'The avatar shape attribute must reach the markup.' );
mw_assert_same( true, false !== strpos( $shelf_markup, 'mw-artists-shelf__initial' ), 'Artists without images must fall back to an initial-letter avatar.' );
mw_assert_same( true, false !== strpos( $shelf_markup, 'data-mw-library-type="artist"' ), 'Every artist card must expose the shared follow-artist button.' );
mw_assert_same( true, false !== strpos( $shelf_markup, '12 اثر' ), 'Release counts must render from core term counts.' );
mw_assert_same( true, false !== strpos( $shelf_markup, '<div class="mw-artists-shelf__media"><a class="mw-artists-shelf__avatar' ), 'Artist cards must keep the cover directly inside the media shell.' );
mw_assert_same( false, false !== strpos( $shelf_markup, 'mw-artists-shelf__disc' ), 'The artist cover must not render the retired vinyl disc.' );
mw_assert_same( true, false !== strpos( $shelf_markup, 'Aria sings over waves of synth.' ), 'Biography excerpts must strip inline tags.' );

$limited_shelf = $artists_shelf->render( array( 'source' => 'all', 'itemsToShow' => 2, 'showFollowButton' => false, 'showHeading' => false ) );
mw_assert_same( false, false !== strpos( $limited_shelf, '>Caius</a>' ), 'itemsToShow must bound how many artists render.' );
mw_assert_same( false, false !== strpos( $limited_shelf, 'data-mw-library-type="artist"' ), 'Disabling the follow button must remove it from every card.' );

$manual_shelf = $artists_shelf->render( array( 'source' => 'manual', 'artistIds' => $shelf_caius['term_id'] . ', ' . $shelf_aria['term_id'] . ', not-an-id', 'orderBy' => 'name' ) );
mw_assert_same( true, strpos( $manual_shelf, '>Caius</a>' ) < strpos( $manual_shelf, '>Aria</a>' ), 'Hand-picked shelves must preserve the editor-chosen order.' );

$GLOBALS['mw_test_filters']['music_wave_artists_shelf_terms'] = array( 'junk-not-a-term' );
$junk_filtered = $artists_shelf->render( array() );
mw_assert_same( true, false !== strpos( $junk_filtered, 'mw-artists-shelf__empty' ), 'Filtered-out junk must leave the shelf empty instead of fataling.' );
mw_assert_same( true, false !== strpos( $junk_filtered, 'هنوز هنرمندی برای نمایش وجود ندارد.' ), 'The empty state must carry a translated default message.' );
$GLOBALS['mw_test_filters']['music_wave_artists_shelf_terms'] = array();
$_GET['context']                                              = 'edit';
$shelf_placeholder                                            = $artists_shelf->render( array() );
mw_assert_same( true, false !== strpos( $shelf_placeholder, 'mw-artists-shelf--placeholder' ), 'The editor must receive a placeholder instead of an error notice.' );
unset( $_GET['context'], $GLOBALS['mw_test_filters']['music_wave_artists_shelf_terms'] );

/*
 * Paginated artists index (page-artists.html). The shelf pages the *all
 * artists* source only, so these assertions lock the arithmetic (offset +
 * page size), the accessible current-page marker, and the guards that keep
 * curated or shuffled shelves single-page.
 */
$paged_backup = $GLOBALS['mw_test_terms'];
$GLOBALS['mw_test_terms'] = array();
for ( $paged_i = 1; $paged_i <= 25; $paged_i++ ) {
	wp_insert_term( 'Paged Artist ' . str_pad( (string) $paged_i, 2, '0', STR_PAD_LEFT ), 'mw_artist', array( 'slug' => 'paged-artist-' . $paged_i ) );
}
$paged_args = array(
	'source'           => 'all',
	'orderBy'          => 'name',
	'itemsToShow'      => 24,
	'pagination'       => true,
	'perPage'          => 10,
	'showHeading'      => false,
	'showFollowButton' => false,
);

unset( $_GET[ ManaCore\MusicWave\Core\Blocks\ArtistShelfBlock::PAGE_QUERY_VAR ], $_SERVER['REQUEST_URI'] );
$paged_first = $artists_shelf->render( $paged_args );
mw_assert_same( true, false !== strpos( $paged_first, 'mw-artists-shelf__pager' ), 'A paginated artists index must render the pager under the shelf.' );
mw_assert_same( true, false !== strpos( $paged_first, 'aria-current="page" class="page-numbers current">1</span>' ), 'The active page must be marked with aria-current instead of a link.' );
mw_assert_same( false, false !== strpos( $paged_first, 'class="prev page-numbers"' ), 'The first page must not render a previous link.' );
mw_assert_same( true, false !== strpos( $paged_first, 'class="next page-numbers"' ), 'The first page must offer a next link when more pages exist.' );
mw_assert_same( true, false !== strpos( $paged_first, '>2</a>' ) && false !== strpos( $paged_first, '>3</a>' ), 'The pager must link every reachable page number (the current one stays a span).' );
mw_assert_same( true, false !== strpos( $paged_first, 'Paged Artist 10' ), 'Page one must render the first slice of artists.' );
mw_assert_same( false, false !== strpos( $paged_first, 'Paged Artist 11' ), 'Page one must stop at the page size.' );

$_GET[ ManaCore\MusicWave\Core\Blocks\ArtistShelfBlock::PAGE_QUERY_VAR ] = '2';
$paged_second = $artists_shelf->render( $paged_args );
mw_assert_same( true, false !== strpos( $paged_second, 'Paged Artist 11' ), 'Page two must continue after the page-one slice (offset query).' );
mw_assert_same( false, false !== strpos( $paged_second, 'Paged Artist 10<' ), 'Page two must not repeat page-one artists.' );
mw_assert_same( true, false !== strpos( $paged_second, 'class="prev page-numbers"' ), 'Page two must offer a previous link.' );
mw_assert_same( true, false !== strpos( $paged_second, 'class="page-numbers current">2</span>' ), 'Page two must be the marked current page.' );

$_GET[ ManaCore\MusicWave\Core\Blocks\ArtistShelfBlock::PAGE_QUERY_VAR ] = '99';
$paged_clamped = $artists_shelf->render( $paged_args );
mw_assert_same( true, false !== strpos( $paged_clamped, 'class="page-numbers current">3</span>' ), 'An out-of-range page must clamp to the last page instead of rendering an empty shelf.' );

unset( $_GET[ ManaCore\MusicWave\Core\Blocks\ArtistShelfBlock::PAGE_QUERY_VAR ] );
$paged_random = $artists_shelf->render( array_merge( $paged_args, array( 'orderBy' => 'rand' ) ) );
mw_assert_same( false, false !== strpos( $paged_random, 'mw-artists-shelf__pager' ), 'A shuffled shelf must not paginate: pages would repeat or drop artists.' );
$paged_manual = $artists_shelf->render(
	array_merge(
		$paged_args,
		array(
			'source'    => 'manual',
			'artistIds' => '1,2,3',
		)
	)
);
mw_assert_same( false, false !== strpos( $paged_manual, 'mw-artists-shelf__pager' ), 'Hand-picked shelves must never paginate.' );
$_GET[ ManaCore\MusicWave\Core\Blocks\ArtistShelfBlock::PAGE_QUERY_VAR ] = '2';
mw_assert_same(
	'https://example.test/artists/?mw_artists_page=2',
	$artists_shelf->filter_canonical_url( 'https://example.test/artists/', null ),
	'A paged artists index must canonicalise to the page being read, not always page one.'
);
unset( $_GET[ ManaCore\MusicWave\Core\Blocks\ArtistShelfBlock::PAGE_QUERY_VAR ] );
mw_assert_same(
	'https://example.test/artists/',
	$artists_shelf->filter_canonical_url( 'https://example.test/artists/', null ),
	'The first page must keep the clean canonical URL.'
);
$GLOBALS['mw_test_terms'] = $paged_backup;

/*
 * Artists index browsing surface (page-artists.html). The hero, toolbar,
 * alphabet, shelf and pagination are separate blocks, so each surface is
 * asserted on its own while all of them read the same request state. A
 * Persian fixture is required: the alphabet filter and the search normalise
 * Arabic letter variants (ي ك ة) onto their Persian buckets.
 */
$artists_surface_backup   = $GLOBALS['mw_test_terms'];
$GLOBALS['mw_test_terms'] = array();
$idx_aria                 = wp_insert_term( 'آریا', 'mw_artist', array( 'slug' => 'aria' ) );
$idx_babak                = wp_insert_term( 'بابک', 'mw_artist', array( 'slug' => 'babak' ) );
$idx_pouya                = wp_insert_term( 'پویا', 'mw_artist', array( 'slug' => 'pouya' ) );
$idx_yasin                = wp_insert_term( 'ياسمين', 'mw_artist', array( 'slug' => 'yasmin' ) );
$GLOBALS['mw_test_terms'][ $idx_aria['term_id'] ]->count  = 9;
$GLOBALS['mw_test_terms'][ $idx_babak['term_id'] ]->count = 4;
$GLOBALS['mw_test_terms'][ $idx_pouya['term_id'] ]->count = 6;
$GLOBALS['mw_test_terms'][ $idx_yasin['term_id'] ]->count = 2;

$mw_artists_query_keys = array( 'mw_artists_page', 'mw_artists_search', 'mw_artists_letter', 'mw_artists_sort', 'context' );
foreach ( $mw_artists_query_keys as $mw_artists_query_key ) {
	unset( $_GET[ $mw_artists_query_key ] );
}
unset( $_SERVER['REQUEST_URI'] );

$artists_index = new ManaCore\MusicWave\Core\Catalog\ArtistIndex();
mw_assert_same( 33, count( ManaCore\MusicWave\Core\Catalog\ArtistIndex::ALPHABET ), 'The alphabet filter must cover the full Persian alphabet.' );
mw_assert_same( 'ی', ManaCore\MusicWave\Core\Catalog\ArtistIndex::term_letter( 'ياسمين' ), 'An Arabic yeh must bucket under the Persian yeh.' );
mw_assert_same( 'ک', ManaCore\MusicWave\Core\Catalog\ArtistIndex::term_letter( 'كاميار' ), 'An Arabic kaf must bucket under the Persian kaf.' );
mw_assert_same( '', ManaCore\MusicWave\Core\Catalog\ArtistIndex::term_letter( '123' ), 'A name without letters must not claim an alphabet bucket.' );
mw_assert_same( 4, count( $artists_index->filtered( ManaCore\MusicWave\Core\Catalog\ArtistIndex::SORT_POPULAR, '', '' ) ), 'An empty filter must return every artist.' );
mw_assert_same( 1, count( $artists_index->filtered( ManaCore\MusicWave\Core\Catalog\ArtistIndex::SORT_POPULAR, '', 'آ' ) ), 'The letter filter must bucket names by their first letter.' );
mw_assert_same( 1, count( $artists_index->filtered( ManaCore\MusicWave\Core\Catalog\ArtistIndex::SORT_POPULAR, 'باب', '' ) ), 'Search must match on the artist name.' );
mw_assert_same( 1, count( $artists_index->filtered( ManaCore\MusicWave\Core\Catalog\ArtistIndex::SORT_POPULAR, 'pouya', '' ) ), 'Search must match on the artist slug too.' );
$artists_letters = $artists_index->letters();
mw_assert_same( 1, (int) $artists_letters['آ'], 'The alphabet row must count the artists behind every bucket.' );
mw_assert_same( 1, (int) $artists_letters['ی'], 'Artists written with an Arabic yeh must still count under ی.' );
mw_assert_same( 0, (int) $artists_letters['ژ'], 'A letter with no artists must count zero.' );
mw_assert_same( 4, $artists_index->total(), 'The hero total must come from the published artist count.' );
mw_assert_same( 21, $artists_index->release_total(), 'The hero release total must sum the published release counts.' );
mw_assert_same( 2, $artists_index->page_count( 3 ), 'The page count must follow the matching total and the page size.' );
mw_assert_same( 'https://example.test/', $artists_index->url(), 'The default browsing state must build a clean URL.' );
mw_assert_same( 'https://example.test/?mw_artists_sort=alphabetical', $artists_index->url( array( 'mw_artists_sort' => 'alphabetical' ) ), 'A non-default ordering must appear in the URL.' );
mw_assert_same( 'https://example.test/?mw_artists_page=3', $artists_index->url( array( 'mw_artists_page' => 3 ) ), 'Only pages after the first need a page query var.' );

$artists_hero = new ManaCore\MusicWave\Core\Blocks\ArtistsHeroBlock();
$hero_markup  = $artists_hero->render( array( 'level' => '2' ) );
mw_assert_same( true, false !== strpos( $hero_markup, '<h2 class="mw-artists-hero__title">خوانندگان</h2>' ), 'The hero heading must honour the level attribute and default to the page title.' );
mw_assert_same( true, false !== strpos( $hero_markup, 'کتابخانهٔ هنرمندان' ), 'The hero must render its eyebrow copy.' );
mw_assert_same( false, false !== strpos( $hero_markup, 'mw-artists-hero__collage' ), 'The hero banner must not render the retired artist collage.' );
mw_assert_same( true, false !== strpos( $hero_markup, '<strong class="mw-artists-hero__stat-value">4</strong>' ), 'The hero must report the artist total.' );
mw_assert_same( true, false !== strpos( $hero_markup, '<strong class="mw-artists-hero__stat-value">21</strong>' ), 'The hero must report the published release total.' );

$hero_minimal = $artists_hero->render(
	array(
		'showStats'   => false,
		'eyebrow'     => '',
		'heading'     => 'کتابخانهٔ خوانندگان',
		'description' => '',
	)
);
mw_assert_same( false, false !== strpos( $hero_minimal, 'mw-artists-hero__stats' ), 'showStats must be able to drop the stats list.' );
mw_assert_same( false, false !== strpos( $hero_minimal, 'mw-artists-hero__eyebrow' ), 'Clearing the eyebrow must hide the element instead of printing an empty paragraph.' );
mw_assert_same( true, false !== strpos( $hero_minimal, '>کتابخانهٔ خوانندگان</h1>' ), 'An edited heading must render at the default level.' );

$hero_backup              = $GLOBALS['mw_test_terms'];
$GLOBALS['mw_test_terms'] = array();
$_GET['context']          = 'edit';
mw_assert_same( true, false !== strpos( $artists_hero->render( array() ), 'mw-artists-hero--placeholder' ), 'An empty artists catalogue must give the editor a placeholder, not an error.' );
unset( $_GET['context'] );
$GLOBALS['mw_test_terms'] = $hero_backup;

$artists_toolbar = new ManaCore\MusicWave\Core\Blocks\ArtistsToolbarBlock();
$toolbar_markup  = $artists_toolbar->render( array() );
mw_assert_same( true, false !== strpos( $toolbar_markup, 'name="mw_artists_search"' ), 'The toolbar must post a GET search form.' );
mw_assert_same( true, false !== strpos( $toolbar_markup, 'mw_artists_sort=alphabetical' ), 'The ordering tabs must link to their own query state.' );
mw_assert_same( false, false !== strpos( $toolbar_markup, 'mw-artists-toolbar__views' ), 'The retired grid/list toggle must not render.' );
mw_assert_same( false, false !== strpos( $toolbar_markup, 'mw-artists-toolbar__menu' ), 'The retired alphabet jump link must not render.' );
mw_assert_same( true, false !== strpos( $toolbar_markup, 'class="mw-artists-toolbar__sort is-active" href="https://example.test/"' ), 'The default ordering tab must be the active one and need no query var.' );
mw_assert_same( 1, substr_count( $toolbar_markup, 'aria-current="true"' ), 'Exactly the active sort tab must be marked current.' );

$_GET['mw_artists_sort'] = 'alphabetical';
$toolbar_active          = $artists_toolbar->render( array() );
mw_assert_same( true, false !== strpos( $toolbar_active, 'name="mw_artists_sort" value="alphabetical"' ), 'Searching must preserve the active ordering.' );
mw_assert_same( true, false !== strpos( $toolbar_active, 'class="mw-artists-toolbar__sort is-active" href="https://example.test/?mw_artists_sort=alphabetical"' ), 'The active ordering tab must point at the current state.' );
unset( $_GET['mw_artists_sort'] );

$_GET['mw_artists_search'] = 'پو';
$toolbar_search            = $artists_toolbar->render( array() );
mw_assert_same( true, false !== strpos( $toolbar_search, 'value="پو"' ), 'The search field must echo the active query.' );
mw_assert_same( true, false !== strpos( $toolbar_search, 'mw-artists-toolbar__clear' ), 'An active search must offer a way to clear it.' );
unset( $_GET['mw_artists_search'] );

$artists_alphabet = new ManaCore\MusicWave\Core\Blocks\ArtistsAlphabetBlock();
$alphabet_markup  = $artists_alphabet->render( array() );
mw_assert_same( true, false !== strpos( $alphabet_markup, 'id="mw-artists-alphabet"' ), 'The alphabet row must keep its stable anchor id.' );
mw_assert_same( true, false !== strpos( $alphabet_markup, 'class="mw-artists-alphabet__item is-active" href="https://example.test/" data-count="4" aria-current="true">همه</a>' ), 'Without an active letter the "all" chip must be current.' );
mw_assert_same( true, false !== strpos( $alphabet_markup, 'href="https://example.test/?mw_artists_letter=%D8%A2"' ), 'Letters with artists must link to their filter.' );
mw_assert_same( true, false !== strpos( $alphabet_markup, 'data-count="1">آ</a>' ), 'Each letter link must carry its artist count.' );
mw_assert_same( true, false !== strpos( $alphabet_markup, 'class="mw-artists-alphabet__item is-empty" data-count="0">ژ</span>' ), 'A letter with no artists must render inert instead of a dead link.' );
mw_assert_same( false, false !== strpos( $artists_alphabet->render( array( 'showAll' => false ) ), '>همه</a>' ), 'showAll must be able to drop the "all" chip.' );
mw_assert_same( true, false !== strpos( $artists_alphabet->render( array( 'showCounts' => true ) ), 'mw-artists-alphabet__count' ), 'showCounts must print the count badge.' );

$surface_shelf = $artists_shelf->render(
	array(
		'source'           => 'all',
		'showHeading'      => false,
		'showFollowButton' => false,
		'showResultCount'  => true,
		'showCrowns'       => true,
		'itemsToShow'      => 12,
	)
);
mw_assert_same( true, false !== strpos( $surface_shelf, 'نمایش 4 از 4 خواننده' ), 'The shelf must print the "showing X of Y" line when asked.' );
mw_assert_same( 3, substr_count( $surface_shelf, 'mw-artists-shelf__crown--' ), 'Only the three most popular artists may wear a crown.' );
mw_assert_same( true, false !== strpos( $surface_shelf, 'mw-artists-shelf__crown--1' ) && false !== strpos( $surface_shelf, 'aria-label="رتبهٔ 1 در محبوبیت"' ), 'Crowns must be ranked and labelled for assistive tech.' );
mw_assert_same( false, false !== strpos( $surface_shelf, 'mw-artists-shelf__disc' ), 'Artist cards must not render the retired vinyl disc.' );

$plain_shelf = $artists_shelf->render(
	array(
		'source'           => 'all',
		'showHeading'      => false,
		'showFollowButton' => false,
	)
);
mw_assert_same( false, false !== strpos( $plain_shelf, 'mw-artists-shelf__summary' ), 'showResultCount=false must remove the summary line.' );

$curated_shelf = $artists_shelf->render(
	array(
		'source'           => 'manual',
		'artistIds'        => (string) $idx_pouya['term_id'],
		'showHeading'      => false,
		'showFollowButton' => false,
	)
);
mw_assert_same( true, false !== strpos( $curated_shelf, 'mw-release-shelf--grid' ), 'A hand-picked shelf must keep the layout its editor chose.' );

$_GET['mw_artists_letter'] = 'آ';
$letter_shelf              = $artists_shelf->render(
	array(
		'source'           => 'all',
		'showHeading'      => false,
		'showFollowButton' => false,
		'showResultCount'  => true,
	)
);
mw_assert_same( true, false !== strpos( $letter_shelf, '>آریا</a>' ) && false === strpos( $letter_shelf, '>پویا</a>' ), 'The alphabet filter must narrow the shelf to one letter.' );
mw_assert_same( true, false !== strpos( $letter_shelf, 'نمایش 1 از 1 خواننده' ), 'The summary must report the filtered total.' );
$letter_alphabet = $artists_alphabet->render( array() );
mw_assert_same( true, false !== strpos( $letter_alphabet, 'class="mw-artists-alphabet__item is-active" href="https://example.test/?mw_artists_letter=%D8%A2"' ), 'The active letter must be marked current in the alphabet row.' );
mw_assert_same( 1, substr_count( $letter_alphabet, 'aria-current="true"' ), 'Only the active letter may be marked current.' );
unset( $_GET['mw_artists_letter'] );

$_GET['mw_artists_search'] = 'پویا';
$search_shelf              = $artists_shelf->render(
	array(
		'source'           => 'all',
		'showHeading'      => false,
		'showFollowButton' => false,
		'showResultCount'  => true,
	)
);
mw_assert_same( true, false !== strpos( $search_shelf, 'نمایش 1 از 1 خواننده' ) && false === strpos( $search_shelf, '>بابک</a>' ), 'The search box must filter the artists shelf.' );

$_GET['mw_artists_search'] = 'هیچکس';
$no_match_shelf            = $artists_shelf->render( array( 'source' => 'all', 'showHeading' => false, 'showFollowButton' => false ) );
mw_assert_same( true, false !== strpos( $no_match_shelf, 'با این فیلترها پیدا نشد' ), 'An empty filtered result must explain how to recover.' );
mw_assert_same( false, false !== strpos( $no_match_shelf, 'هنوز هنرمندی برای نمایش وجود ندارد.' ), 'The "no artists at all" copy must not stand in for an empty filter result.' );
unset( $_GET['mw_artists_search'] );

$GLOBALS['mw_test_terms'] = $artists_surface_backup;

/*
 * Artists pagination surface: the standalone pager block and the shelf's
 * browseIndex mode must slice the same index with the same page size.
 */
$paging_backup            = $GLOBALS['mw_test_terms'];
$GLOBALS['mw_test_terms'] = array();
for ( $index_slot = 1; $index_slot <= 25; $index_slot++ ) {
	wp_insert_term( 'Artist P' . str_pad( (string) $index_slot, 2, '0', STR_PAD_LEFT ), 'mw_artist', array( 'slug' => 'artist-p-' . $index_slot ) );
}
foreach ( $mw_artists_query_keys as $mw_artists_query_key ) {
	unset( $_GET[ $mw_artists_query_key ] );
}
unset( $_SERVER['REQUEST_URI'] );

$_GET['mw_artists_page'] = '1';
$paged_shelf             = $artists_shelf->render(
	array(
		'source'           => 'all',
		'showHeading'      => false,
		'showFollowButton' => false,
		'pagination'       => true,
		'perPage'          => 4,
		'showResultCount'  => true,
	)
);
mw_assert_same( true, false !== strpos( $paged_shelf, '<nav class="wp-block-query-pagination' ), 'A paginated artists shelf must render the standard WordPress pager under its items.' );
mw_assert_same( true, false !== strpos( $paged_shelf, 'class="page-numbers current">1</span>' ), 'The pager must mark the active page with aria-current.' );
mw_assert_same( true, false !== strpos( $paged_shelf, 'Artist P01' ) && false === strpos( $paged_shelf, 'Artist P05' ), 'Page one must slice the shelf to the page size.' );
mw_assert_same( true, false !== strpos( $paged_shelf, 'نمایش 4 از 25 خواننده' ), 'A paged shelf must report the page slice against the full matching total.' );

// Saved content from before the pager block was removed stored `browseIndex`.
$legacy_paged_shelf = $artists_shelf->render(
	array(
		'source'           => 'all',
		'showHeading'      => false,
		'showFollowButton' => false,
		'browseIndex'      => true,
		'perPage'          => 4,
	)
);
mw_assert_same( true, false !== strpos( $legacy_paged_shelf, '<nav class="wp-block-query-pagination' ), 'The legacy browseIndex attribute must still page the artists index with the standard pager.' );

$_GET['mw_artists_page'] = '7';
$last_page_shelf         = $artists_shelf->render(
	array(
		'source'           => 'all',
		'showHeading'      => false,
		'showFollowButton' => false,
		'pagination'       => true,
		'perPage'          => 4,
	)
);
mw_assert_same( true, false !== strpos( $last_page_shelf, 'class="page-numbers current">7</span>' ), 'The last page must be the marked current page.' );
mw_assert_same( true, false !== strpos( $last_page_shelf, 'class="prev page-numbers"' ) && false === strpos( $last_page_shelf, 'class="next page-numbers"' ), 'The last page must offer a previous link and no next link.' );

// The pager has to keep the browsing state the visitor arrived with.
$_GET['mw_artists_letter'] = 'آ';
$filtered_pager_shelf      = $artists_shelf->render(
	array(
		'source'           => 'all',
		'showHeading'      => false,
		'showFollowButton' => false,
		'pagination'       => true,
		'perPage'          => 4,
	)
);
unset( $_GET['mw_artists_letter'] );
mw_assert_same( false, false !== strpos( $filtered_pager_shelf, 'mw_artists_page=' ), 'A single-page filtered index must not render pager links.' );

unset( $_GET['mw_artists_page'] );
mw_assert_same( false, false !== strpos( $artists_shelf->render( array( 'source' => 'all', 'showHeading' => false, 'showFollowButton' => false ) ), '<nav class="wp-block-query-pagination' ), 'A non-paged shelf must not render a pager.' );

$GLOBALS['mw_test_terms'] = $paging_backup;

$taxonomy_shelf                      = new ManaCore\MusicWave\Core\Blocks\TaxonomyShelfBlock();
$shelf_pop                           = wp_insert_term( 'Pop', 'mw_genre', array( 'slug' => 'pop' ) );
$shelf_rock                          = wp_insert_term( 'Rock', 'mw_genre', array( 'slug' => 'rock' ) );
$GLOBALS['mw_test_terms'][ $shelf_pop['term_id'] ]->count  = 21;
$GLOBALS['mw_test_terms'][ $shelf_rock['term_id'] ]->count = 4;

$terms_markup = $taxonomy_shelf->render(
	array(
		'taxonomy'  => 'mw_genre',
		'source'    => 'all',
		'heading'   => 'Explore every genre',
		'cardStyle' => 'colorful',
	)
);
mw_assert_same( true, false !== strpos( $terms_markup, 'mw-terms-shelf mw-release-shelf mw-release-shelf--grid mw-release-shelf--columns-4' ), 'The taxonomy shelf must reuse the shared release-shelf chrome.' );
mw_assert_same( true, false !== strpos( $terms_markup, '>Pop</span>' ), 'The taxonomy shelf must render term names.' );
mw_assert_same( true, false !== strpos( $terms_markup, 'انتشار 21' ), 'The taxonomy shelf must render release counts from term counts.' );
$pop_hue  = ( (int) $shelf_pop['term_id'] % 8 ) + 1;
$rock_hue = ( (int) $shelf_rock['term_id'] % 8 ) + 1;
mw_assert_same( true, $pop_hue !== $rock_hue, 'The fixture must exercise two distinct term hues.' );
mw_assert_same( true, false !== strpos( $terms_markup, 'mw-term-hue-' . $pop_hue ) && false !== strpos( $terms_markup, 'mw-term-hue-' . $rock_hue ), 'Colorful tiles must carry each term\'s stable hue derived from its ID, not its position.' );
mw_assert_same( true, false !== strpos( $terms_markup, 'aria-label="مرور Pop"' ), 'Tiles must carry accessible browse labels.' );
mw_assert_same( true, false !== strpos( $terms_markup, 'https://example.test/artist/' ) || false !== strpos( $terms_markup, 'href=' ), 'Tiles must link to their archives.' );

$shelf_calm = wp_insert_term( 'Calm', 'mw_mood', array( 'slug' => 'calm' ) );
$GLOBALS['mw_test_terms'][ $shelf_calm['term_id'] ]->count = 9;
$plain_terms = $taxonomy_shelf->render( array( 'taxonomy' => 'mw_mood', 'cardStyle' => 'plain', 'showCount' => false, 'itemsToShow' => 24, 'layout' => 'scroll' ) );
mw_assert_same( true, false !== strpos( $plain_terms, '>Calm</span>' ), 'The taxonomy attribute must scope which terms render.' );
mw_assert_same( true, false !== strpos( $plain_terms, 'mw-terms-shelf__tile--style-plain' ), 'The plain tile style must reach the markup.' );
mw_assert_same( false, false !== strpos( $plain_terms, '>Rock</span>' ), 'Genre terms must never leak into mood shelves.' );
mw_assert_same( false, false !== strpos( $plain_terms, 'mw-terms-shelf__count' ), 'Disabling counts must remove them from tiles.' );

$manual_terms = $taxonomy_shelf->render( array( 'taxonomy' => 'mw_genre', 'source' => 'manual', 'termIds' => $shelf_rock['term_id'] . ', ' . $shelf_pop['term_id'], 'layout' => 'list', 'cardStyle' => 'plain' ) );
mw_assert_same( true, strpos( $manual_terms, '>Rock</span>' ) < strpos( $manual_terms, '>Pop</span>' ), 'Hand-picked taxonomy shelves must preserve editor order.' );
mw_assert_same( true, false !== strpos( $manual_terms, 'mw-release-shelf--list' ), 'List layout must apply its modifier class.' );

$manual_hues = $taxonomy_shelf->render( array( 'taxonomy' => 'mw_genre', 'source' => 'manual', 'termIds' => $shelf_rock['term_id'] . ', ' . $shelf_pop['term_id'] ) );
mw_assert_same( true, false !== strpos( $manual_hues, '>Rock</span>' ) && false !== strpos( $manual_hues, '>Pop</span>' ), 'The manual hue shelf must render both terms.' );
$rock_tile = substr( $manual_hues, max( 0, (int) strpos( $manual_hues, '>Rock</span>' ) - 320 ), 380 );
$pop_tile  = substr( $manual_hues, max( 0, (int) strpos( $manual_hues, '>Pop</span>' ) - 320 ), 380 );
mw_assert_same( true, false !== strpos( $rock_tile, 'mw-term-hue-' . $rock_hue ) && false === strpos( $rock_tile, 'mw-term-hue-' . $pop_hue ), 'Reordering a shelf must not repaint Rock: its tile keeps its own hue.' );
mw_assert_same( true, false !== strpos( $pop_tile, 'mw-term-hue-' . $pop_hue ) && false === strpos( $pop_tile, 'mw-term-hue-' . $rock_hue ), 'Reordering a shelf must not repaint Pop: its tile keeps its own hue.' );

/*
 * «حداکثر کاشی» is an author decision for every supported taxonomy, and it has
 * to bind before the `music_wave_terms_shelf_terms` filter can widen it.
 */
$mw_cap_term_ids = array();
foreach ( array( 'mw_genre' => 'mw-genre-cap-', 'mw_mood' => 'mw-mood-cap-', 'mw_label' => 'mw-label-cap-' ) as $cap_taxonomy => $cap_slug_prefix ) {
	for ( $cap_index = 1; $cap_index <= 5; $cap_index++ ) {
		$cap_term = wp_insert_term( $cap_taxonomy . ' Cap ' . $cap_index, $cap_taxonomy, array( 'slug' => $cap_slug_prefix . $cap_index ) );
		$GLOBALS['mw_test_terms'][ $cap_term['term_id'] ]->count = 3;
		$mw_cap_term_ids[]                                       = (int) $cap_term['term_id'];
	}
	$GLOBALS['mw_test_filters']['music_wave_terms_shelf_terms'] = static function ( $terms ) {
		// A hook that tries to widen the shelf must not win over the cap.
		return array_merge( $terms, $terms );
	};
	$capped_terms = $taxonomy_shelf->render( array( 'taxonomy' => $cap_taxonomy, 'itemsToShow' => 2, 'showHeading' => false, 'cardStyle' => 'plain' ) );
	unset( $GLOBALS['mw_test_filters']['music_wave_terms_shelf_terms'] );
	mw_assert_same( 2, substr_count( $capped_terms, 'mw-terms-shelf__name' ), '«حداکثر کاشی» must cap the ' . $cap_taxonomy . ' shelf at the requested tile count, even after a filter hook.' );
	foreach ( $mw_cap_term_ids as $mw_cap_term_id ) {
		unset( $GLOBALS['mw_test_terms'][ $mw_cap_term_id ] );
	}
	$mw_cap_term_ids = array();
}

$GLOBALS['mw_test_filters']['music_wave_terms_shelf_terms'] = array();
$empty_terms                                                = $taxonomy_shelf->render( array( 'emptyMessage' => 'No genres yet.', 'taxonomy' => 'mw_label' ) );
mw_assert_same( true, false !== strpos( $empty_terms, 'mw-terms-shelf__empty' ), 'Empty taxonomy shelves must render an empty state.' );
mw_assert_same( true, false !== strpos( $empty_terms, 'No genres yet.' ), 'The empty-state override must apply to taxonomy shelves.' );
unset( $GLOBALS['mw_test_filters']['music_wave_terms_shelf_terms'] );

$term_hero = new ManaCore\MusicWave\Core\Blocks\TermHeroBlock();
$hero_aria = $GLOBALS['mw_test_terms'][ $shelf_aria['term_id'] ];
update_term_meta( (int) $shelf_aria['term_id'], 'mw_artist_biography', 'Aria sings over deep synth waves every night on stage.' );

// Archive mode: the queried artist term drives the banner automatically.
$GLOBALS['mw_test_queried_object'] = $hero_aria;
$GLOBALS['mw_test_current_user']   = 9;
$hero_markup                       = $term_hero->render( array( 'layout' => 'banner', 'size' => 'tall' ) );
$GLOBALS['mw_test_current_user']   = 0;
mw_assert_same( true, false !== strpos( $hero_markup, 'mw-term-hero mw-term-hero--banner mw-term-hero--size-tall' ), 'The term hero must render its banner layout classes.' );
mw_assert_same( true, false !== strpos( $hero_markup, '<h1 class="mw-term-hero__name">Aria</h1>' ), 'The hero must render the term name as the page heading.' );
mw_assert_same( true, false !== strpos( $hero_markup, '>هنرمند</p>' ), 'The taxonomy eyebrow must be translated and rendered.' );
mw_assert_same( true, false !== strpos( $hero_markup, 'انتشار 12' ), 'The hero must carry the release count badge.' );
mw_assert_same( true, false !== strpos( $hero_markup, 'Aria sings over deep synth waves every night on stage.' ), 'Artist biography must feed the description.' );
mw_assert_same( true, false !== strpos( $hero_markup, 'data-mw-library-type="artist"' ), 'Artist heroes must expose the follow control.' );

$GLOBALS['mw_test_current_user'] = 9;
$hero_follow                     = $term_hero->render( array() );
$GLOBALS['mw_test_current_user'] = 0;
mw_assert_same( true, false !== strpos( $hero_follow, 'aria-pressed=' ), 'The hero follow button must expose its pressed state for signed-in listeners.' );

$hero_excerpt = $term_hero->render( array( 'descriptionLength' => 5 ) );
mw_assert_same( true, false !== strpos( $hero_excerpt, 'Aria sings over deep synth…' ), 'Description length must trim long biographies.' );

// Genre heroes: no biography meta, no follow button, hue fallback media.
$GLOBALS['mw_test_queried_object'] = $GLOBALS['mw_test_terms'][ $shelf_pop['term_id'] ];
$hero_pop                          = $term_hero->render( array() );
mw_assert_same( true, is_string( $hero_pop ) && false !== strpos( (string) $hero_pop, '>سبک</p>' ), 'Genre heroes must use the genre eyebrow.' );
mw_assert_same( true, false === strpos( (string) $hero_pop, 'data-mw-library-type="artist"' ), 'Non-artist terms must not render a follow control.' );
mw_assert_same( true, false !== strpos( (string) $hero_pop, 'mw-term-hero--hue-' ), 'Terms without cover art must fall back to curated hues.' );
mw_assert_same( true, false !== strpos( (string) $hero_pop, 'mw-term-media mw-term-hue-' . $pop_hue ), 'Genre heroes must paint their media with the term\'s own canonical hue.' );

// Pinned term anywhere: explicit taxonomy + ID resolve off-archive.
unset( $GLOBALS['mw_test_queried_object'] );
$hero_pinned = $term_hero->render( array( 'taxonomy' => 'mw_artist', 'termId' => $shelf_aria['term_id'], 'layout' => 'compact' ) );
mw_assert_same( true, false !== strpos( $hero_pinned, 'mw-term-hero--compact' ), 'The compact layout must render when selected.' );
mw_assert_same( true, false !== strpos( $hero_pinned, '<h1 class="mw-term-hero__name">Aria</h1>' ), 'Pinned heroes must resolve their term by ID.' );

// Silence on unrelated routes keeps other templates intact.
mw_assert_same( '', $term_hero->render( array() ), 'Without a supported queried term the hero must stay silent on the frontend.' );
$_GET['context']  = 'edit';
$hero_live_editor = $term_hero->render( array() );
mw_assert_same( true, false !== strpos( $hero_live_editor, 'mw-term-hero__name' ), 'In the editor, term-hero automatically resolves an existing site term for realistic preview.' );
$mw_saved_terms            = $GLOBALS['mw_test_terms'];
$GLOBALS['mw_test_terms']  = array();
$hero_placeholder          = $term_hero->render( array() );
$GLOBALS['mw_test_terms']  = $mw_saved_terms;
mw_assert_same( true, false !== strpos( $hero_placeholder, 'mw-term-hero--placeholder' ), 'On an empty site with zero terms, the editor must still receive a fallback hero preview instead of an empty string.' );
unset( $_GET['context'] );

// --- Catalog filters keep the visitor inside the listing they are browsing ---

$catalog_filters = new ManaCore\MusicWave\Core\Blocks\Release\CatalogFilterRenderer();
$artist_term_url = 'https://example.test/artist/' . (int) $shelf_aria['term_id'];
$GLOBALS['mw_test_queried_object'] = $GLOBALS['mw_test_terms'][ $shelf_aria['term_id'] ];
$GLOBALS['mw_test_tax_archives']   = array( 'mw_artist' );
$artist_filter_form                = $catalog_filters->render_catalog_filters(
	array(
		'showSearch'       => false,
		'showArtistFilter' => false,
		'showGenreFilter'  => false,
		'showMoodFilter'   => false,
		'showTypeFilter'   => false,
		'showSort'         => true,
		'showReset'        => true,
	)
);
mw_assert_same( true, false !== strpos( $artist_filter_form, 'action="' . $artist_term_url . '"' ), 'The catalog filter form on an artist page must post back to that artist, not the release archive.' );
mw_assert_same( true, false !== strpos( $artist_filter_form, 'href="' . $artist_term_url . '"' ), 'The catalog reset link on an artist page must return to that artist page.' );
unset( $GLOBALS['mw_test_tax_archives'], $GLOBALS['mw_test_queried_object'] );

// --- Playback queue: no-JS manager over the durable listening queue ---

$queue_repo   = new ManaCore\MusicWave\Core\Listening\ListeningRepository( $visibility );
$queue_forms  = new ManaCore\MusicWave\Core\Listening\QueueFormHandler( $queue_repo );
$queue_block  = new ManaCore\MusicWave\Core\Blocks\QueueBlock( $queue_repo, $queue_forms );

mw_assert_same( 'guest', $queue_forms->run( 'clear', 0 ), 'Signed-out visitors must receive the guest notice code.' );

$GLOBALS['mw_test_current_user'] = 7;
$queue_repo->save_queue(
	7,
	array(
		'ids'      => array( 2, 3, 5 ),
		'position' => 1,
	)
);
mw_assert_same( array( 2, 3 ), $queue_repo->queue( 7 )['ids'], 'The queue must keep only readable releases before any mutation.' );

mw_assert_same( 'moved', $queue_forms->run( 'move-up', 7, 3 ), 'Move up must succeed inside bounds.' );
mw_assert_same( array( 3, 2 ), $queue_repo->queue( 7 )['ids'], 'Move up must swap exactly one position.' );
mw_assert_same( 'move-failed', $queue_forms->run( 'move-up', 7, 3 ), 'Moving the first item up must fail instead of wrapping.' );
mw_assert_same( 'move-failed', $queue_forms->run( 'move-down', 7, 2 ), 'Moving the last item down must fail instead of wrapping.' );
mw_assert_same( 'moved', $queue_forms->run( 'move-down', 7, 3 ), 'Move down must succeed inside bounds.' );
mw_assert_same( 'item-removed', $queue_forms->run( 'remove-item', 7, 2 ), 'Remove must drop one queued release.' );
mw_assert_same( array( 3 ), $queue_repo->queue( 7 )['ids'], 'Removal must persist exactly one change.' );
mw_assert_same( 'item-remove-failed', $queue_forms->run( 'remove-item', 7, 2 ), 'Removing an unqueued release must fail cleanly.' );

mw_assert_same( 'shuffle-updated', $queue_forms->run( 'shuffle', 7, 0, 'on' ), 'Shuffle must persist as a preference.' );
mw_assert_same( true, $queue_repo->queue( 7 )['shuffle'], 'Shuffle preference must survive a reload.' );
mw_assert_same( 'repeat-updated', $queue_forms->run( 'repeat', 7, 0, 'all' ), 'Repeat must accept its documented modes.' );
mw_assert_same( 'all', $queue_repo->queue( 7 )['repeat'], 'Repeat mode must survive a reload.' );
mw_assert_same( 'repeat-failed', $queue_forms->run( 'repeat', 7, 0, 'sometimes' ), 'Unknown repeat modes must fail closed.' );
mw_assert_same( 'invalid', $queue_forms->run( 'explode', 7 ), 'Unknown operations must be rejected.' );

mw_assert_same( true, '' !== $queue_forms->notice_message( 'cleared' ) && '' === $queue_forms->notice_message( 'unknown-code' ), 'Notice codes must resolve to translated text or nothing.' );
mw_assert_same( true, $queue_forms->notice_is_error( 'move-failed' ) && ! $queue_forms->notice_is_error( 'moved' ), 'Failure notices must be flagged while successes are not.' );

$queue_repo->save_queue(
	7,
	array(
		'ids'      => array( 2, 3 ),
		'position' => 0,
	)
);
$queue_markup_user = $queue_block->render( array() );
mw_assert_same( true, false !== strpos( $queue_markup_user, 'method="post"' ), 'Every queue control must work as a plain form post without JavaScript.' );
mw_assert_same( true, false !== strpos( $queue_markup_user, '_wpnonce' ), 'Queue forms must carry a nonce field.' );
mw_assert_same( true, false !== strpos( $queue_markup_user, 'name="mw_operation" value="move-up"' ), 'Rows must expose reorder controls.' );
mw_assert_same( true, false !== strpos( $queue_markup_user, 'aria-label="' ), 'Row buttons must carry per-title screen-reader labels.' );
mw_assert_same( true, false !== strpos( $queue_markup_user, 'value="shuffle"' ) && false !== strpos( $queue_markup_user, 'value="repeat"' ), 'Preference controls must post shuffle and repeat.' );
mw_assert_same( true, false !== strpos( $queue_markup_user, '>پاک کردن صف</button>' ), 'The clear control must be rendered when enabled.' );

$queue_minimal = $queue_block->render( array( 'showControls' => false, 'showClear' => false, 'showPosition' => false ) );
mw_assert_same( false, false !== strpos( $queue_minimal, 'mw-playback-queue__controls' ), 'Disabling preferences must remove them.' );
mw_assert_same( false, false !== strpos( $queue_minimal, 'پاک کردن صف' ), 'Disabling clear must remove it.' );
mw_assert_same( false, false !== strpos( $queue_minimal, 'mw-playback-queue__position' ), 'Disabling positions must remove them.' );

mw_assert_same( 'cleared', $queue_forms->run( 'clear', 7 ), 'Clearing must empty the whole queue.' );
$queue_empty = $queue_block->render( array( 'emptyMessage' => 'Nothing queued right now.' ) );
mw_assert_same( true, false !== strpos( $queue_empty, 'Nothing queued right now.' ), 'The empty-state override must apply to the queue.' );

$GLOBALS['mw_test_current_user'] = 0;
$queue_guest                     = $queue_block->render( array() );
mw_assert_same( true, false !== strpos( $queue_guest, 'mw-playback-queue--guest' ), 'Guests must see the queue sign-in panel.' );
mw_assert_same( false, false !== strpos( $queue_guest, 'mw_operation' ), 'Guests must not receive queue mutation forms.' );

// --- Add-to-queue control: "Play next" from any release surface ---

$GLOBALS['mw_test_current_user'] = 7;
$queue_repo->save_queue( 7, array( 'ids' => array( 2 ), 'position' => 0 ) );
mw_assert_same( 'added', $queue_forms->run( 'add', 7, 3, 'next' ), 'Play next must insert right after the current position.' );
mw_assert_same( array( 2, 3 ), $queue_repo->queue( 7 )['ids'], 'The next-position insert must land after the current item.' );
mw_assert_same( 'already-queued', $queue_forms->run( 'add', 7, 2 ), 'A duplicate enqueue must report an honest already-queued notice.' );
mw_assert_same( 'added', $queue_forms->run( 'add', 7, 4, 'end' ), 'End inserts append to the queue tail.' );
mw_assert_same( array( 2, 3, 4 ), $queue_repo->queue( 7 )['ids'], 'End inserts must keep existing order.' );
mw_assert_same( 'add-failed', $queue_forms->run( 'add', 7, 5, 'next' ), 'Unreadable releases must fail instead of reporting fake success.' );
mw_assert_same( 'add-failed', $queue_forms->run( 'add', 7, 0, 'next' ), 'Missing release IDs must fail cleanly.' );

$GLOBALS['mw_test_types'][21]       = 'mw_release';
$GLOBALS['mw_test_statuses'][21]    = 'publish';
$GLOBALS['mw_test_titles'][21]      = 'Queueable single';
$GLOBALS['mw_test_terms_by_tax'][21] = array(
	'mw_artist' => array(),
	'mw_genre'  => array(),
);

$add_markup = $queue_block->render_add( array( 'releaseId' => 21 ) );
mw_assert_same( true, false !== strpos( $add_markup, 'name="mw_operation" value="add"' ), 'The add control must post the add operation.' );
mw_assert_same( true, false !== strpos( $add_markup, 'name="mw_release_id" value="21"' ), 'The add control must carry its target release.' );
mw_assert_same( true, false !== strpos( $add_markup, 'name="mw_value" value="next"' ), 'The default position must be play-next.' );
mw_assert_same( true, false !== strpos( $add_markup, '_wpnonce' ), 'The add control must carry a nonce.' );

$add_end = $queue_block->render_add( array( 'releaseId' => 21, 'position' => 'end', 'label' => 'Queue it' ) );
mw_assert_same( true, false !== strpos( $add_end, 'name="mw_value" value="end"' ), 'The position override must reach the form.' );
mw_assert_same( true, false !== strpos( $add_end, '>Queue it</button>' ), 'Custom labels must override the defaults.' );

$queue_forms->run( 'add', 7, 21, 'next' );
$add_queued = $queue_block->render_add( array( 'releaseId' => 21 ) );
mw_assert_same( true, false !== strpos( $add_queued, 'mw-add-to-queue--queued' ), 'Queued releases must show the in-queue badge.' );
mw_assert_same( false, false !== strpos( $add_queued, 'mw_operation' ), 'Queued releases must not render another mutation form.' );

$GLOBALS['mw_test_current_user'] = 0;
$add_guest                       = $queue_block->render_add( array( 'releaseId' => 21 ) );
mw_assert_same( true, false !== strpos( $add_guest, 'mw-add-to-queue--guest' ), 'Guests must see a sign-in button instead of mutation forms.' );
mw_assert_same( false, false !== strpos( $add_guest, 'mw_operation' ), 'Guests must never receive the add form.' );

$listening_blocks = new ManaCore\MusicWave\Core\Blocks\ListeningBlocks( new ManaCore\MusicWave\Core\Listening\ListeningRepository() );
$listening_guest  = $listening_blocks->render( array() );
mw_assert_same( true, false !== strpos( $listening_guest, 'mw-continue-listening--guest' ), 'Signed-out visitors must see the continue-listening sign-in state.' );
mw_assert_same( false, false !== strpos( $listening_guest, 'data-mw-listening-consent' ), 'Guests must never receive the consent control.' );

$GLOBALS['mw_test_current_user'] = 9;
$listening_consent               = $listening_blocks->render( array() );
mw_assert_same( true, false !== strpos( $listening_consent, 'mw-continue-listening--consent' ), 'Opted-out listeners must see the one-click consent panel.' );
$GLOBALS['mw_test_user_meta'][9][ ManaCore\MusicWave\Core\Listening\ListeningRepository::CONSENT_META ] = '1';
$listening_empty = $listening_blocks->render( array( 'emptyMessage' => 'Nothing here yet.' ) );
mw_assert_same( true, false !== strpos( $listening_empty, 'mw-continue-listening--empty' ), 'Consenting listeners without history must see the empty state.' );
mw_assert_same( true, false !== strpos( $listening_empty, 'Nothing here yet.' ), 'The empty-state message override must apply.' );
$GLOBALS['mw_test_current_user']                                                                    = 0;
$GLOBALS['mw_test_user_meta'][9][ ManaCore\MusicWave\Core\Listening\ListeningRepository::CONSENT_META ] = '0';

$GLOBALS['mw_test_terms']      = $shelf_terms_backup;
$GLOBALS['mw_test_term_meta']  = $shelf_term_meta_bak;

// --- Script-aware site search: Persian phrases must find the matching posts ---

use ManaCore\MusicWave\Core\Discovery\PersianSearchNormalizer;
use ManaCore\MusicWave\Core\Discovery\ScriptAwareSearchQuery;

mw_assert_same( 'موسیقی', PersianSearchNormalizer::normalize( 'موسيقي' ), 'Arabic yeh must fold to Persian yeh.' );
mw_assert_same( 'کتاب', PersianSearchNormalizer::normalize( 'كتاب' ), 'Arabic kaf must fold to Persian keheh.' );
mw_assert_same( 'می خواهم', PersianSearchNormalizer::normalize( "می\u{200C}خواهم" ), 'The zero-width non-joiner must normalize to a space.' );
mw_assert_same( 'سال 1403', PersianSearchNormalizer::normalize( 'سال ۱۴۰۳' ), 'Persian digits must normalize to ASCII digits.' );
mw_assert_same( 'سلام', PersianSearchNormalizer::normalize( "سَلاَمْ" ), 'Diacritics must be stripped.' );
mw_assert_same( 'release 3', PersianSearchNormalizer::normalize( '  Release   3 ' ), 'Latin phrases must be lower-cased and whitespace-collapsed.' );
mw_assert_same( true, PersianSearchNormalizer::equals( 'سیگنال نیمه‌شب', 'سيگنال نيمه شب' ), 'Equality must ignore script and spacing differences.' );
mw_assert_same( true, PersianSearchNormalizer::contains( 'جریان شیشه ای', 'شيشه' ), 'Containment must match across yeh variants.' );
mw_assert_same( false, PersianSearchNormalizer::contains( 'جریان شیشه ای', '' ), 'An empty needle never matches.' );
mw_assert_same( true, PersianSearchNormalizer::has_arabic_script( 'Glass جریان' ), 'Mixed phrases count as Arabic script.' );
mw_assert_same( false, PersianSearchNormalizer::has_arabic_script( 'Glass Current' ), 'Latin phrases must not trigger the expansion.' );

$mw_variants = PersianSearchNormalizer::variants( 'موسيقي' );
mw_assert_same( 'موسیقی', $mw_variants[0], 'The Persian spelling must be the primary variant.' );
mw_assert_same( true, in_array( 'موسيقي', $mw_variants, true ), 'The Arabic spelling must remain a variant so legacy content still matches.' );
$mw_zwnj_variants = PersianSearchNormalizer::variants( 'نیمه شب' );
mw_assert_same( true, in_array( "نیمه\u{200C}شب", $mw_zwnj_variants, true ), 'A spaced word must also try the ZWNJ spelling.' );
mw_assert_same( true, in_array( 'نیمه‌شب', $mw_zwnj_variants, true ) || in_array( 'نیمهشب', $mw_zwnj_variants, true ), 'A spaced word must also try the joined spelling.' );
$mw_digit_variants = PersianSearchNormalizer::variants( '۱۴۰۳' );
mw_assert_same( '1403', $mw_digit_variants[0], 'Digits fold to ASCII first.' );
mw_assert_same( true, in_array( '۱۴۰۳', $mw_digit_variants, true ), 'The Persian digit spelling stays available.' );
mw_assert_same( array(), PersianSearchNormalizer::variants( '   ' ), 'Blank words produce no variants.' );
mw_assert_same( 'cafe', PersianSearchNormalizer::normalize( 'CAFÉ' ), 'Latin diacritics must fold so accented content matches plain ASCII searches.' );
$mw_latin_accents = PersianSearchNormalizer::variants( 'café' );
mw_assert_same( true, in_array( 'cafe', $mw_latin_accents, true ) && in_array( 'café', $mw_latin_accents, true ), 'Both the folded and the typed spelling must stay searchable.' );
mw_assert_same( true, PersianSearchNormalizer::contains( 'musique naïve', 'NAIVE' ), 'Latin folding must apply to containment checks.' );
mw_assert_same( array( 'نیمه‌شب', 'signal', 'دو کلمه' ), PersianSearchNormalizer::words( 'نیمه‌شب signal "دو کلمه"' ), 'Words must keep the ZWNJ and quoted phrases intact.' );

$mw_script_search = new ScriptAwareSearchQuery();
$mw_plan          = $mw_script_search->plan( array( 'موسيقي', '-جاز' ) );
mw_assert_same( 2, count( $mw_plan ), 'Every search word must receive a plan entry.' );
mw_assert_same( false, $mw_plan[0]['exclude'], 'Plain words are inclusive.' );
mw_assert_same( true, in_array( 'موسیقی', $mw_plan[0]['variants'], true ) && in_array( 'موسيقي', $mw_plan[0]['variants'], true ), 'Both script spellings must be planned for a word.' );
mw_assert_same( true, $mw_plan[1]['exclude'], 'Prefixed words must stay exclusions.' );
mw_assert_same( 'جاز', $mw_plan[1]['variants'][0], 'The exclusion prefix must be stripped from the planned word.' );
mw_assert_same( true, count( $mw_plan[0]['variants'] ) <= ScriptAwareSearchQuery::MAX_VARIANTS, 'Variants per word must stay bounded.' );

$mw_main_search = new WP_Query( array( 's' => 'سيگنال', 'search_terms' => array( 'سيگنال' ) ) );
mw_assert_same( true, $mw_script_search->applies( $mw_main_search ), 'The main query with a Persian phrase must opt in.' );
$mw_latin_search = new WP_Query( array( 's' => 'glass', 'search_terms' => array( 'glass' ) ) );
mw_assert_same( true, $mw_script_search->applies( $mw_latin_search ), 'The main query must search catalog term names and meta for Latin phrases too.' );
$mw_secondary_plain                  = new WP_Query( array( 's' => 'سيگنال', 'search_terms' => array( 'سيگنال' ), 'post_type' => 'post' ) );
$mw_secondary_plain->mw_test_is_main = false;
mw_assert_same( false, $mw_script_search->applies( $mw_secondary_plain ), 'Unrelated secondary queries are never rewritten.' );
$mw_secondary_release                  = new WP_Query( array( 's' => 'سيگنال', 'search_terms' => array( 'سيگنال' ), 'post_type' => 'mw_release' ) );
$mw_secondary_release->mw_test_is_main = false;
mw_assert_same( true, $mw_script_search->applies( $mw_secondary_release ), 'Release-scoped secondary queries opt in automatically.' );
$mw_opted_in                  = new WP_Query( array( 's' => 'سيگنال', 'search_terms' => array( 'سيگنال' ), 'post_type' => 'post', ScriptAwareSearchQuery::QUERY_VAR => true ) );
$mw_opted_in->mw_test_is_main = false;
mw_assert_same( true, $mw_script_search->applies( $mw_opted_in ), 'The query var must opt any query in.' );

$GLOBALS['wpdb'] = new wpdb();
$mw_core_sql     = " AND (((wp_posts.post_title LIKE '%سيگنال%') OR (wp_posts.post_excerpt LIKE '%سيگنال%') OR (wp_posts.post_content LIKE '%سيگنال%'))) ";
$mw_sql          = $mw_script_search->filter_search( $mw_core_sql, $mw_main_search );
mw_assert_same( true, false !== strpos( $mw_sql, "wp_posts.post_title LIKE '%سیگنال%'" ), 'The rewritten clause must try the Persian spelling of the title.' );
mw_assert_same( true, false !== strpos( $mw_sql, "wp_posts.post_title LIKE '%سيگنال%'" ), 'The rewritten clause must keep the typed Arabic spelling.' );
mw_assert_same( true, false !== strpos( $mw_sql, 'wp_posts.post_excerpt LIKE' ) && false !== strpos( $mw_sql, 'wp_posts.post_content LIKE' ), 'Excerpt and content stay searchable.' );
mw_assert_same( true, false !== strpos( $mw_sql, 'wp_posts.ID IN (SELECT mw_tr.object_id FROM wp_term_relationships' ), 'Catalog term names must be searched through a bounded sub-query.' );
mw_assert_same( true, false !== strpos( $mw_sql, "mw_tt.taxonomy IN ('mw_artist', 'mw_genre', 'mw_mood', 'mw_label', 'mw_release_type')" ), 'Only the public catalog taxonomies are searched by name.' );
mw_assert_same( true, false !== strpos( $mw_sql, "mw_pm.meta_key IN ('mw_album', 'mw_catalog_number', 'mw_isrc', 'mw_credits')" ), 'Only allow-listed descriptive meta keys are searched.' );
mw_assert_same( false, false !== strpos( $mw_sql, 'mw_product_ids' ) || false !== strpos( $mw_sql, 'mw_download' ), 'Commerce and asset meta must never be searched.' );
$mw_latin_sql = $mw_script_search->filter_search( $mw_core_sql, $mw_latin_search );
mw_assert_same( true, false !== strpos( $mw_latin_sql, "wp_posts.post_title LIKE '%glass%'" ), 'Latin phrases still match the post columns.' );
mw_assert_same( true, false !== strpos( $mw_latin_sql, 'wp_posts.ID IN (SELECT mw_tr.object_id' ), 'Latin phrases must match catalog term names (artist, genre, label).' );
mw_assert_same( true, false !== strpos( $mw_latin_sql, "mw_pm.meta_key IN ('mw_album', 'mw_catalog_number', 'mw_isrc', 'mw_credits')" ), 'Latin phrases must match descriptive release meta too.' );
mw_assert_same( 2, substr_count( $mw_latin_sql, ' JOIN ' ), 'Term and meta matching stay inside the two bounded sub-queries for Latin too.' );
mw_assert_same( true, false !== strpos( $mw_sql, "wp_posts.post_password = ''" ), 'Password-protected posts stay hidden from anonymous searches.' );
mw_assert_same( false, false !== strpos( $mw_sql, ' JOIN ' ) && false === strpos( $mw_sql, 'INNER JOIN wp_term_taxonomy' ), 'The outer query must not gain joins.' );
mw_assert_same( true, $mw_core_sql !== $mw_script_search->filter_search( $mw_core_sql, $mw_latin_search ), 'Latin phrases receive the catalog-aware rewrite instead of the core clause.' );
mw_assert_same( '', $mw_script_search->filter_search( '', $mw_main_search ), 'An empty core clause (no search) must stay empty.' );

$mw_multi_search = new WP_Query( array( 's' => 'نیمه شب -جاز', 'search_terms' => array( 'نیمه', 'شب', '-جاز' ) ) );
$mw_multi_sql    = $mw_script_search->filter_search( $mw_core_sql, $mw_multi_search );
mw_assert_same( 2, substr_count( $mw_multi_sql, ') AND (' ) >= 2 ? 2 : substr_count( $mw_multi_sql, ') AND (' ), 'Multiple words must still be combined with AND.' );
mw_assert_same( true, false !== strpos( $mw_multi_sql, "wp_posts.post_title NOT LIKE '%جاز%'" ), 'Exclusions must stay exclusions.' );
mw_assert_same( true, false !== strpos( $mw_multi_sql, 'wp_posts.ID NOT IN (SELECT mw_tr.object_id' ), 'Excluded words must also exclude term matches.' );

$mw_exact_search = new WP_Query( array( 's' => 'شب', 'search_terms' => array( 'شب' ), 'exact' => true ) );
mw_assert_same( true, false !== strpos( $mw_script_search->filter_search( $mw_core_sql, $mw_exact_search ), "wp_posts.post_title LIKE 'شب'" ), 'Exact searches must not add wildcards.' );

$mw_injection = new WP_Query( array( 's' => "سیگنال' OR 1=1 --", 'search_terms' => array( "سیگنال'", 'OR', '1=1', '--' ) ) );
$mw_injected  = $mw_script_search->filter_search( $mw_core_sql, $mw_injection );
mw_assert_same( false, false !== strpos( $mw_injected, "'%سیگنال'%'" ), 'Quotes inside search words must be escaped through prepare().' );
mw_assert_same( true, false !== strpos( $mw_injected, "\\'%'" ), 'Escaped quotes must survive in the prepared SQL.' );

$mw_orderby = $mw_script_search->filter_search_orderby( 'wp_posts.post_title LIKE \'%سيگنال%\' DESC', $mw_main_search );
mw_assert_same( true, 0 === strpos( $mw_orderby, '(CASE WHEN (' ) && false !== strpos( $mw_orderby, "post_title LIKE '%سیگنال%'" ) && false !== strpos( $mw_orderby, 'ELSE 3 END) ASC' ), 'Relevance ordering must rank title matches of any spelling first.' );
mw_assert_same( '', $mw_script_search->filter_search_orderby( '', $mw_main_search ), 'Explicit sort choices (empty relevance clause) must be respected.' );

// Per-query scoping: an internal listing searches its own meta keys, no taxonomies, even for Latin phrases.
$mw_scoped_search                  = new WP_Query(
	array(
		's'                                       => 'sara@example.test',
		'search_terms'                            => array( 'sara@example.test' ),
		'post_type'                               => 'mw_request',
		ScriptAwareSearchQuery::QUERY_VAR         => true,
		ScriptAwareSearchQuery::TAXONOMIES_VAR    => array(),
		ScriptAwareSearchQuery::META_KEYS_VAR     => array( 'mw_request_name', 'mw_request_email', 'mw_request_email', 42 ),
	)
);
$mw_scoped_search->mw_test_is_main = false;
mw_assert_same( true, $mw_script_search->applies( $mw_scoped_search ), 'A query that supplies its own meta keys is rewritten even for Latin phrases (core cannot search meta).' );
$mw_scoped_sql = $mw_script_search->filter_search( " AND (((wp_posts.post_title LIKE '%sara@example.test%'))) ", $mw_scoped_search );
mw_assert_same( true, false !== strpos( $mw_scoped_sql, "mw_pm.meta_key IN ('mw_request_name', 'mw_request_email')" ), 'Scoped meta keys replace the catalog allow-list for that query; duplicates and non-strings are dropped.' );
mw_assert_same( false, false !== strpos( $mw_scoped_sql, 'wp_term_relationships' ), 'An empty taxonomy scope switches the term sub-query off.' );
mw_assert_same( false, false !== strpos( $mw_scoped_sql, 'mw_album' ), 'The catalog meta keys never leak into a scoped query.' );
mw_assert_same( true, false !== strpos( $mw_scoped_sql, "wp_posts.post_title LIKE '%sara@example.test%'" ), 'Post columns are still searched in a scoped query.' );
$mw_unscoped_latin                  = new WP_Query( array( 's' => 'glass', 'search_terms' => array( 'glass' ), 'post_type' => 'mw_release', ScriptAwareSearchQuery::QUERY_VAR => true ) );
$mw_unscoped_latin->mw_test_is_main = false;
mw_assert_same( true, $mw_script_search->applies( $mw_unscoped_latin ), 'Release-scoped queries receive the catalog search for every script.' );
unset( $GLOBALS['wpdb'] );

$mw_folded_search = new ManaCore\MusicWave\Core\Discovery\CatalogSearch();
mw_assert_same( 'موسیقی نیمه‌شب', $mw_folded_search->sanitize_term( 'موسيقي نیمه‌شب' ), 'Autocomplete terms must be folded to the Persian spelling while keeping the ZWNJ.' );
$GLOBALS['mw_test_types'][13]    = 'mw_release';
$GLOBALS['mw_test_statuses'][13] = 'publish';
$GLOBALS['mw_test_titles'][13]   = 'سیگنال بعد از نیمه شب';
$GLOBALS['mw_test_transients']   = array();
$mw_persian_ids                  = array();
foreach ( $mw_folded_search->suggest( 'سيگنال', 8 ) as $mw_suggestion ) {
	if ( 'release' === (string) $mw_suggestion['type'] ) {
		$mw_persian_ids[] = (int) $mw_suggestion['id'];
	}
}
mw_assert_same( array( 13 ), $mw_persian_ids, 'Autocomplete must match a Persian title typed with Arabic letters.' );
$GLOBALS['mw_test_transients'] = array();

// --- Song requests & collaboration: validation, storage, workflow, email, admin placement ---

use ManaCore\MusicWave\Core\Requests\RequestFormHandler;
use ManaCore\MusicWave\Core\Requests\RequestMailer;
use ManaCore\MusicWave\Core\Requests\RequestPostType;
use ManaCore\MusicWave\Core\Requests\RequestRepository;
use ManaCore\MusicWave\Core\Requests\RequestSettings;
use ManaCore\MusicWave\Core\Requests\RequestsAdminPage;
use ManaCore\MusicWave\Core\Requests\RequestSubmission;
use ManaCore\MusicWave\Core\Blocks\RequestFormBlock;

if ( ! defined( 'MUSIC_WAVE_TESTING' ) ) {
	define( 'MUSIC_WAVE_TESTING', true );
}

$mw_req_valid = array(
	'name'     => '  سارا  احمدی ',
	'email'    => 'Sara@Example.TEST',
	'phone'    => '۰۹۱۲ ۳۴۵ ۶۷۸۹',
	'role'     => 'singer',
	'type'     => 'song',
	'subject'  => 'قطعهٔ پاپ برای آلبوم جدید',
	'message'  => "یک قطعهٔ پاپ با حال‌وهوای شاد برای آلبوم بعدی می‌خواهم.\n\n\n\nمدت حدود سه دقیقه.",
	'budget'   => 'standard',
	'deadline' => '۲۰۹۹-۰۳-۱۵',
	'links'    => "soundcloud.com/sara\nhttps://instagram.com/sara, javascript:alert(1)",
	'consent'  => '1',
);
$mw_req_result = RequestSubmission::validate( $mw_req_valid, 1700000000 );
mw_assert_same( true, isset( $mw_req_result['errors']['links'] ), 'A javascript: pseudo-link must be reported, never stored.' );
unset( $mw_req_valid['links'] );
$mw_req_valid['links'] = "soundcloud.com/sara\nhttps://instagram.com/sara";
$mw_req_result         = RequestSubmission::validate( $mw_req_valid, 1700000000 );
mw_assert_same( array(), $mw_req_result['errors'], 'A complete submission must validate without errors.' );
mw_assert_same( 'سارا احمدی', $mw_req_result['data']['name'], 'Names are trimmed and whitespace-collapsed.' );
mw_assert_same( 'sara@example.test', $mw_req_result['data']['email'], 'Emails are lower-cased.' );
mw_assert_same( '0912 345 6789', $mw_req_result['data']['phone'], 'Persian digits in phone numbers are normalized to ASCII.' );
mw_assert_same( '2099-03-15', $mw_req_result['data']['deadline'], 'Persian digits in dates are normalized and the date is canonical.' );
mw_assert_same( array( 'https://soundcloud.com/sara', 'https://instagram.com/sara' ), $mw_req_result['data']['links'], 'Links are normalized to https and deduplicated.' );
mw_assert_same( false, false !== strpos( $mw_req_result['data']['message'], "\n\n\n" ), 'Excess blank lines in the message are collapsed.' );

$mw_req_bad = RequestSubmission::validate(
	array(
		'name'     => 'A',
		'email'    => 'not-an-email',
		'role'     => 'hacker',
		'type'     => '<script>',
		'subject'  => 'x',
		'message'  => 'short',
		'deadline' => '2001-01-01',
		'consent'  => '',
	),
	1700000000
);
mw_assert_same( array( 'name', 'email', 'subject', 'message', 'deadline', 'consent' ), array_keys( $mw_req_bad['errors'] ), 'Every invalid field must carry its own translated error.' );
mw_assert_same( 'other', $mw_req_bad['data']['role'], 'Unknown roles fall back to "other".' );
mw_assert_same( 'song', $mw_req_bad['data']['type'], 'Unknown types fall back to the default type.' );

$mw_req_settings = new RequestSettings();
mw_assert_same( 'enabled', RequestSettings::all()['form_enabled'], 'The public form is enabled by default.' );
mw_assert_same( array( 'a@example.test', 'b@example.test' ), RequestSettings::parse_recipients( "A@example.test, b@example.test\nnot-an-email, a@example.test" ), 'Recipients are validated, lower-cased and deduplicated.' );
$mw_req_sanitized = RequestSettings::sanitize( array( 'rate_limit' => '500', 'rate_window' => '10', 'reply_to' => 'bad', 'enabled_types' => array( 'song', 'nope' ), 'form_enabled' => 'disabled' ) );
mw_assert_same( 3, $mw_req_sanitized['rate_limit'], 'Out-of-range rate limits fall back to the default.' );
mw_assert_same( 3600, $mw_req_sanitized['rate_window'], 'Out-of-range windows fall back to the default.' );
mw_assert_same( '', $mw_req_sanitized['reply_to'], 'Invalid reply-to addresses are dropped.' );
mw_assert_same( array( 'song' ), $mw_req_sanitized['enabled_types'], 'Unknown request types are dropped.' );
mw_assert_same( 'disabled', $mw_req_sanitized['form_enabled'], 'The form switch persists.' );
mw_assert_same( array( 'owner@example.test' ), $mw_req_settings->notification_recipients(), 'Without configured recipients the site admin receives alerts.' );

$mw_req_repo = new RequestRepository();
$mw_req_id   = $mw_req_repo->create( $mw_req_result['data'], 0, 'form' );
mw_assert_same( true, $mw_req_id > 0, 'A validated submission must be stored.' );
mw_assert_same( RequestPostType::KEY, get_post_type( $mw_req_id ), 'Requests are stored as the private mw_request post type.' );
mw_assert_same( RequestPostType::STATUS_NEW, get_post_status( $mw_req_id ), 'New requests start in the "new" status.' );
$mw_req = $mw_req_repo->find( $mw_req_id );
mw_assert_same( 'sara@example.test', $mw_req['email'], 'Stored requests expose the requester email.' );
mw_assert_same( sprintf( 'MW-%s-%06d', gmdate( 'Y' ), $mw_req_id ), $mw_req['reference'], 'Every request gets a human reference.' );
mw_assert_same( 32, strlen( $mw_req['token'] ), 'Every request gets an unguessable token.' );
mw_assert_same( 'normal', $mw_req['priority'], 'Requests start with normal priority.' );
mw_assert_same( null, $mw_req_repo->find( 1 ), 'Releases are never readable as requests.' );

mw_assert_same( true, $mw_req_repo->set_status( $mw_req_id, RequestPostType::STATUS_REVIEW, 5 ), 'Status transitions must succeed for known statuses.' );
mw_assert_same( false, $mw_req_repo->set_status( $mw_req_id, 'publish', 5 ), 'Requests can never be moved to a public status.' );
mw_assert_same( RequestPostType::STATUS_REVIEW, $mw_req_repo->find( $mw_req_id )['status'], 'The status change persists.' );
mw_assert_same( RequestRepository::LOG_EVENT, $mw_req_repo->find( $mw_req_id )['log'][0]['kind'], 'Status changes are logged as events.' );
mw_assert_same( true, $mw_req_repo->set_priority( $mw_req_id, 'high' ), 'Priority can be raised.' );
mw_assert_same( false, $mw_req_repo->set_priority( $mw_req_id, 'critical' ), 'Unknown priorities are refused.' );
mw_assert_same( null, $mw_req_repo->append_log( $mw_req_id, RequestRepository::LOG_NOTE, '   ', 5 ), 'Blank log entries are refused.' );

$GLOBALS['mw_test_mail'] = array();
$mw_req_mailer           = new RequestMailer( $mw_req_settings );
mw_assert_same( true, $mw_req_mailer->send_receipt( $mw_req_repo->find( $mw_req_id ) ), 'The requester receives a receipt.' );
mw_assert_same( 'sara@example.test', $GLOBALS['mw_test_mail'][0]['to'], 'The receipt goes to the requester.' );
mw_assert_same( true, false !== strpos( $GLOBALS['mw_test_mail'][0]['message'], $mw_req['reference'] ), 'The receipt carries the reference number.' );
mw_assert_same( true, $mw_req_mailer->send_manager_alert( $mw_req_repo->find( $mw_req_id ), 'https://example.test/wp-admin/edit.php?page=music-wave-requests' ), 'Managers are alerted.' );
mw_assert_same( 'owner@example.test', $GLOBALS['mw_test_mail'][1]['to'], 'The alert goes to the configured recipients (admin email fallback).' );
mw_assert_same( true, in_array( 'Reply-To: "سارا احمدی" <sara@example.test>', (array) $GLOBALS['mw_test_mail'][1]['headers'], true ), 'Managers can reply directly to the requester.' );
$GLOBALS['mw_test_filters']['music_wave_request_message'] = false;
mw_assert_same( false, $mw_req_mailer->send_reply( $mw_req_repo->find( $mw_req_id ), 'سلام' ), 'The message filter can suppress delivery.' );
unset( $GLOBALS['mw_test_filters']['music_wave_request_message'] );

$GLOBALS['mw_test_transients'] = array();
$GLOBALS['mw_test_mail']       = array();
$mw_req_forms                  = new RequestFormHandler( $mw_req_repo, $mw_req_mailer, $mw_req_settings, new ManaCore\MusicWave\Core\Discovery\DiscoveryRateLimiter( 2, 60 ) );
$mw_req_input                  = array_merge( $mw_req_valid, array( RequestFormHandler::TIMER => (string) ( time() - 30 ) ) );
$mw_req_run                    = $mw_req_forms->run( $mw_req_input, 0, time() );
mw_assert_same( 'sent', $mw_req_run['notice'], 'A valid public submission is accepted.' );
mw_assert_same( true, $mw_req_run['request_id'] > 0, 'The stored request ID is returned.' );
mw_assert_same( 2, count( $GLOBALS['mw_test_mail'] ), 'A public submission sends the receipt and the manager alert.' );
mw_assert_same( true, in_array( 'music_wave_request_created', $GLOBALS['mw_test_actions'], true ), 'Integrations are notified about new requests.' );

$mw_req_bot = $mw_req_forms->run( array_merge( $mw_req_input, array( RequestFormHandler::HONEYPOT => 'http://spam' ) ), 0, time() );
mw_assert_same( 'sent', $mw_req_bot['notice'], 'Honeypot hits look like success to the bot.' );
mw_assert_same( 0, $mw_req_bot['request_id'], 'Honeypot hits are never stored.' );
$mw_req_fast = $mw_req_forms->run( array_merge( $mw_req_input, array( RequestFormHandler::TIMER => (string) time() ) ), 0, time() );
mw_assert_same( 'too-fast', $mw_req_fast['notice'], 'Instant submissions are refused.' );
$mw_req_errors = $mw_req_forms->run( array_merge( $mw_req_input, array( 'email' => 'nope' ) ), 0, time() );
mw_assert_same( 'errors', $mw_req_errors['notice'], 'Invalid submissions come back with field errors.' );
mw_assert_same( true, '' !== $mw_req_errors['token'], 'Field errors are stashed under a one-shot token.' );
$mw_req_stash = $mw_req_forms->stashed( $mw_req_errors['token'] );
mw_assert_same( array( 'email' ), array_keys( $mw_req_stash['errors'] ), 'The stash carries the field errors.' );
mw_assert_same( 'سارا احمدی', $mw_req_stash['values']['name'], 'The stash carries the sanitized values for refilling.' );
mw_assert_same( false, isset( $mw_req_stash['values']['consent'] ), 'Consent is never pre-checked from the stash.' );
mw_assert_same( array(), $mw_req_forms->stashed( '../etc' )['errors'], 'Malformed tokens read nothing.' );
$mw_req_throttled = $mw_req_forms->run( $mw_req_input, 0, time() );
mw_assert_same( 'throttled', $mw_req_throttled['notice'], 'The per-actor rate limit applies to public submissions.' );
mw_assert_same( true, $mw_req_forms->notice_is_error( 'throttled' ) && ! $mw_req_forms->notice_is_error( 'sent' ), 'Notice severities are classified.' );
mw_assert_same( '', $mw_req_forms->notice_message( 'bogus' ), 'Unknown notice codes render nothing.' );

$GLOBALS['mw_test_options'][ RequestSettings::OPTION ] = array( 'form_enabled' => 'disabled' );
mw_assert_same( 'closed', $mw_req_forms->run( $mw_req_input, 0, time() )['notice'], 'A disabled form refuses submissions server-side too.' );
unset( $GLOBALS['mw_test_options'][ RequestSettings::OPTION ] );

// The admin-post entry point: a stale nonce redirects with a notice instead of dying.
$GLOBALS['mw_test_redirects'] = array();
$_POST                        = array_merge( $mw_req_input, array( '_wpnonce' => 'stale', 'mw_redirect' => 'https://example.test/requests/' ) );
$mw_req_forms->handle();
$mw_req_last_redirect = end( $GLOBALS['mw_test_redirects'] );
mw_assert_same( true, is_array( $mw_req_last_redirect ) && false !== strpos( (string) $mw_req_last_redirect[0], 'mw-request=invalid' ) && 303 === $mw_req_last_redirect[1], 'A failed nonce check redirects back to the form with the expired notice (never wp_die()).' );
mw_assert_same( true, is_array( $mw_req_last_redirect ) && 0 === strpos( (string) $mw_req_last_redirect[0], 'https://example.test/requests/' ), 'The visitor returns to the page that hosted the form.' );
$_POST                        = array_merge( $mw_req_input, array( '_wpnonce' => wp_create_nonce( RequestFormHandler::NONCE ), 'mw_redirect' => 'https://example.test/requests/' ) );
$GLOBALS['mw_test_redirects'] = array();
$mw_req_forms->handle();
$mw_req_last_redirect = end( $GLOBALS['mw_test_redirects'] );
mw_assert_same( true, is_array( $mw_req_last_redirect ) && false !== strpos( (string) $mw_req_last_redirect[0], 'mw-request=throttled' ), 'A valid nonce reaches the pipeline (here the exhausted rate limit answers).' );
$_POST = array();

$mw_req_block = new RequestFormBlock( $mw_req_forms, $mw_req_settings );
$mw_req_html  = $mw_req_block->render( array() );
mw_assert_same( true, false !== strpos( $mw_req_html, 'class="mw-request-form mw-request-form--mode-both"' ), 'The pure block renders its standalone form wrapper in both access modes by default.' );
mw_assert_same( false, false !== strpos( $mw_req_html, 'mw-request-form__header' ) || false !== strpos( $mw_req_html, 'mw-request-form__side' ), 'Legacy baked-in header and side column HTML must never be emitted by RequestFormBlock.' );
mw_assert_same( true, false !== strpos( $mw_req_html, 'action="https://example.test/wp-admin/admin-post.php"' ), 'The form posts to admin-post.php for no-JS operation.' );
mw_assert_same( true, false !== strpos( $mw_req_html, 'name="' . RequestFormHandler::HONEYPOT . '"' ) && false !== strpos( $mw_req_html, 'name="' . RequestFormHandler::TIMER . '"' ), 'The form carries the honeypot and the timing field.' );
mw_assert_same( true, false !== strpos( $mw_req_html, 'name="consent"' ) && false !== strpos( $mw_req_html, 'name="_wpnonce"' ), 'The form carries consent and a nonce.' );
mw_assert_same( true, false !== strpos( $mw_req_html, 'mw-request-form__chip' ), 'Dual-mode form ships interactive request-type chips.' );
mw_assert_same( true, in_array( 'music-wave-request-form', $GLOBALS['mw_test_styles'], true ), 'The block enqueues its own stylesheet.' );
$mw_req_plain = $mw_req_block->render( array( 'mode' => 'song', 'showPhone' => false, 'submitLabel' => 'ثبت فوری سفارش' ) );
mw_assert_same( true, false !== strpos( $mw_req_plain, 'mw-request-form--mode-song' ) && false === strpos( $mw_req_plain, 'name="phone"' ) && false !== strpos( $mw_req_plain, 'ثبت فوری سفارش' ), 'Pure block attributes control fixed mode, optional fields, and submit CTA label.' );
$GLOBALS['mw_test_options'][ RequestSettings::OPTION ] = array( 'form_enabled' => 'disabled' );
mw_assert_same( true, false !== strpos( $mw_req_block->render( array() ), 'mw-request-form__closed' ), 'A disabled form shows the closed notice instead of inputs.' );
unset( $GLOBALS['mw_test_options'][ RequestSettings::OPTION ] );

$mw_req_admin = new RequestsAdminPage( $mw_req_repo, $mw_req_mailer, $mw_req_settings );
$mw_req_menu  = array(
	array( 'همهٔ انتشارها', 'edit_mw_releases', 'edit.php?post_type=mw_release' ),
	array( 'تنظیمات', 'manage_options', 'music-wave-settings' ),
	array( 'MusicWave VIP', 'manage_options', 'music-wave-vip' ),
	array( 'درخواست‌ها', RequestPostType::MANAGE_CAP, RequestsAdminPage::PAGE ),
);
$mw_req_order = array_column( RequestsAdminPage::reorder( $mw_req_menu, RequestsAdminPage::PAGE, 'music-wave-vip' ), 2 );
mw_assert_same( array( 'edit.php?post_type=mw_release', 'music-wave-settings', RequestsAdminPage::PAGE, 'music-wave-vip' ), $mw_req_order, 'The requests menu item must sit directly above MusicWave VIP.' );
$mw_req_no_vip = array_column( RequestsAdminPage::reorder( array( $mw_req_menu[3], $mw_req_menu[0], $mw_req_menu[1] ), RequestsAdminPage::PAGE, 'music-wave-vip' ), 2 );
mw_assert_same( array( 'edit.php?post_type=mw_release', 'music-wave-settings', RequestsAdminPage::PAGE ), $mw_req_no_vip, 'Without VIP the item goes to the end of the MusicWave group.' );
mw_assert_same( 'https://example.test/wp-admin/edit.php?post_type=mw_release&page=music-wave-requests&request=' . $mw_req_id, RequestsAdminPage::url( array( 'request' => $mw_req_id ) ), 'The screen lives under the MusicWave (mw_release) menu.' );

$GLOBALS['mw_test_mail'] = array();
$mw_req_reply            = $mw_req_admin->run( 'reply', $mw_req_id, array(), array( 'status' => '', 'priority' => '', 'body' => 'سلام سارا، درخواست شما را بررسی کردیم.', 'subject' => '', 'bulk' => '' ), array(), 5 );
mw_assert_same( 'replied', $mw_req_reply['notice'], 'A manager reply is emailed.' );
mw_assert_same( 'sara@example.test', $GLOBALS['mw_test_mail'][0]['to'], 'The reply goes to the requester.' );
mw_assert_same( RequestPostType::STATUS_REPLIED, $mw_req_repo->find( $mw_req_id )['status'], 'Replying moves an open request to "replied".' );
$mw_req_log_kinds = array_column( $mw_req_repo->find( $mw_req_id )['log'], 'kind' );
mw_assert_same( true, in_array( RequestRepository::LOG_REPLY, $mw_req_log_kinds, true ), 'Replies are kept in the conversation log.' );
$GLOBALS['mw_test_mail_fails'] = true;
mw_assert_same( 'reply-logged', $mw_req_admin->run( 'reply', $mw_req_id, array(), array( 'status' => '', 'priority' => '', 'body' => 'دوباره', 'subject' => '', 'bulk' => '' ), array(), 5 )['notice'], 'A failed email still keeps the reply in the log and tells the manager.' );
unset( $GLOBALS['mw_test_mail_fails'] );
mw_assert_same( 'reply-empty', $mw_req_admin->run( 'reply', $mw_req_id, array(), array( 'status' => '', 'priority' => '', 'body' => ' ', 'subject' => '', 'bulk' => '' ), array(), 5 )['notice'], 'Empty replies are refused.' );
mw_assert_same( 'noted', $mw_req_admin->run( 'note', $mw_req_id, array(), array( 'status' => '', 'priority' => '', 'body' => 'یادداشت داخلی', 'subject' => '', 'bulk' => '' ), array(), 5 )['notice'], 'Internal notes are stored.' );
mw_assert_same( 'status-updated', $mw_req_admin->run( 'status', $mw_req_id, array(), array( 'status' => RequestPostType::STATUS_ACCEPTED, 'priority' => '', 'body' => '', 'subject' => '', 'bulk' => '' ), array(), 5 )['notice'], 'Managers can accept a collaboration.' );
mw_assert_same( 'status-failed', $mw_req_admin->run( 'status', $mw_req_id, array(), array( 'status' => 'publish', 'priority' => '', 'body' => '', 'subject' => '', 'bulk' => '' ), array(), 5 )['notice'], 'Public statuses are refused by the admin action too.' );
$mw_req_created = $mw_req_admin->run( 'create', 0, array(), array( 'status' => '', 'priority' => '', 'body' => '', 'subject' => '', 'bulk' => '' ), array( 'name' => 'رضا', 'email' => 'reza@example.test', 'subject' => 'تبلیغ رادیویی', 'message' => 'یک جینگل سی‌ثانیه‌ای برای کمپین پاییزی می‌خواهیم.', 'type' => 'advertising', 'role' => 'advertiser' ), 5 );
mw_assert_same( 'created', $mw_req_created['notice'], 'Managers can add requests manually.' );
mw_assert_same( 'manual', $mw_req_repo->find( $mw_req_created['request_id'] )['source'], 'Manual requests are marked as such.' );
mw_assert_same( 'form-invalid', $mw_req_admin->run( 'create', 0, array(), array( 'status' => '', 'priority' => '', 'body' => '', 'subject' => '', 'bulk' => '' ), array( 'name' => 'x' ), 5 )['notice'], 'Manual entry is validated like the public form.' );
mw_assert_same( 'updated', $mw_req_admin->run( 'update', $mw_req_id, array(), array( 'status' => '', 'priority' => '', 'body' => '', 'subject' => '', 'bulk' => '' ), array_merge( $mw_req_valid, array( 'subject' => 'عنوان ویرایش‌شده' ) ), 5 )['notice'], 'Managers can edit request fields.' );
mw_assert_same( 'عنوان ویرایش‌شده', $mw_req_repo->find( $mw_req_id )['subject'], 'Edited subjects persist.' );

$mw_req_list = $mw_req_repo->query( array( 'type' => 'advertising' ), 1, 20 );
mw_assert_same( array( $mw_req_created['request_id'] ), array_column( $mw_req_list['items'], 'id' ), 'The inbox filters by request type.' );
$mw_req_list = $mw_req_repo->query( array( 'status' => RequestPostType::STATUS_ACCEPTED ), 1, 20 );
mw_assert_same( array( $mw_req_id ), array_column( $mw_req_list['items'], 'id' ), 'The inbox filters by status.' );
$mw_req_list = $mw_req_repo->query( array( 'priority' => 'high' ), 1, 20 );
mw_assert_same( array( $mw_req_id ), array_column( $mw_req_list['items'], 'id' ), 'The inbox filters by priority.' );
$mw_req_counts = $mw_req_repo->counts();
mw_assert_same( 1, $mw_req_counts[ RequestPostType::STATUS_ACCEPTED ], 'Status counts feed the tabs and badge.' );
mw_assert_same( array( $mw_req_id, $mw_req_run['request_id'] ), array_column( $mw_req_repo->find_by_email( 'sara@example.test' ), 'id' ), 'Privacy tooling can find every request submitted with one email.' );

$mw_req_bulk = $mw_req_admin->run( 'bulk', 0, array( $mw_req_id, $mw_req_created['request_id'] ), array( 'status' => '', 'priority' => '', 'body' => '', 'subject' => '', 'bulk' => 'status:' . RequestPostType::STATUS_ARCHIVED ), array(), 5 );
mw_assert_same( 'bulk-done', $mw_req_bulk['notice'], 'Bulk status changes apply to the selection.' );
mw_assert_same( RequestPostType::STATUS_ARCHIVED, $mw_req_repo->find( $mw_req_created['request_id'] )['status'], 'Bulk changes persist.' );
mw_assert_same( 'bulk-none', $mw_req_admin->run( 'bulk', 0, array(), array( 'status' => '', 'priority' => '', 'body' => '', 'subject' => '', 'bulk' => 'delete' ), array(), 5 )['notice'], 'Bulk actions without a selection do nothing.' );
mw_assert_same( 'deleted', $mw_req_admin->run( 'delete', $mw_req_created['request_id'], array(), array( 'status' => '', 'priority' => '', 'body' => '', 'subject' => '', 'bulk' => '' ), array(), 5 )['notice'], 'Managers can delete a request permanently.' );
mw_assert_same( null, $mw_req_repo->find( $mw_req_created['request_id'] ), 'Deleted requests are gone.' );
mw_assert_same( 'not-found', $mw_req_admin->run( 'delete', 1, array(), array( 'status' => '', 'priority' => '', 'body' => '', 'subject' => '', 'bulk' => '' ), array(), 5 )['notice'], 'Releases can never be deleted through the request screen.' );
mw_assert_same( 'mw_release', get_post_type( 1 ), 'Release 1 survives the request delete attempt.' );

$mw_req_privacy = new ManaCore\MusicWave\Core\Requests\RequestPrivacy( $mw_req_repo );
$mw_req_export  = $mw_req_privacy->export( 'sara@example.test' );
mw_assert_same( 2, count( $mw_req_export['data'] ), 'The privacy exporter returns every request of the requester.' );
mw_assert_same( true, $mw_req_privacy->erase( 'sara@example.test' )['items_removed'], 'The privacy eraser removes them.' );
mw_assert_same( array(), $mw_req_repo->find_by_email( 'sara@example.test' ), 'Nothing remains after erasure.' );
$GLOBALS['mw_test_transients'] = array();
$GLOBALS['mw_test_mail']       = array();

// --- WooCommerce plan products as VIP membership (PROJECT_PLAN.md Stage 3 lifecycle parity) ---

$parsed = ManaCore\MusicWave\Vip\VipPlans::parse_plan_rows( "vipgold:50\ngold365: 60 , 61 :365\n# comment\nbroken\n :99\nsilver:abc" );
mw_assert_same( 2, count( $parsed ), 'Plan rows must keep only well-formed level:level-to-product bindings.' );
mw_assert_same( 'vipgold', $parsed[0]['level'], 'Plan levels must stay verbatim after parsing.' );
mw_assert_same( array( 50 ), $parsed[0]['product_ids'], 'Single-product plans must normalize their IDs.' );
mw_assert_same( 0, $parsed[0]['duration_days'], 'Plans without a duration must mean lifetime membership.' );
mw_assert_same( array( 60, 61 ), $parsed[1]['product_ids'], 'Comma-separated product lists must normalize.' );
mw_assert_same( 365, $parsed[1]['duration_days'], 'Plan durations must parse as bounded days.' );
mw_assert_same(
	array( 'vipgold:50', 'gold365:60,61:365' ),
	ManaCore\MusicWave\Vip\VipPlans::plan_rows_for_display( $parsed ),
	'Plan rows must round-trip through the canonical display format.'
);

$vip_clock     = 1700000000;
$vip_plans_now = function () use ( &$vip_clock ): int {
	return $vip_clock;
};
$vip_engine    = new ManaCore\MusicWave\Vip\VipPlans(
	array(
		'vipgold' => array(
			'product_ids'   => array( 50 ),
			'duration_days' => 0,
		),
		'gold365' => array(
			'product_ids'   => array( 60, 61 ),
			'duration_days' => 365,
		),
	),
	false,
	$vip_plans_now
);

$GLOBALS['mw_test_orders'][501] = new TestWcOrder( 7, array( new TestWcOrderItem( 50 ), new TestWcOrderItem( 60, 62 ) ) );
$GLOBALS['mw_test_orders'][502] = new TestWcOrder( 0, array( new TestWcOrderItem( 50 ) ) );
mw_assert_same( 0, $vip_engine->grant_for_order( 502 ), 'Guest orders must never receive a VIP grant.' );
mw_assert_same( 0, $vip_engine->grant_for_order( 999 ), 'Unknown orders must fail closed.' );
mw_assert_same( 2, $vip_engine->grant_for_order( 501 ), 'One paid order must grant every matching plan level.' );
mw_assert_same( true, $vip_engine->user_has_access( 7, 'vipgold' ), 'A lifetime plan grant must be active immediately.' );
mw_assert_same( true, $vip_engine->user_has_access( 7, 'gold365' ), 'A timed plan grant must be active before expiry.' );
mw_assert_same( false, $vip_engine->user_has_access( 7, 'silver' ), 'Unpurchased levels must stay denied.' );
mw_assert_same( true, in_array( 'music_wave_vip_plan_granted', $GLOBALS['mw_test_actions'], true ), 'Plan grants must fire the notification action.' );
mw_assert_same( array( 'gold365', 'vipgold' ), $vip_engine->active_levels( 7 ), 'Active levels must list every live grant.' );

mw_assert_same( 2, $vip_engine->grant_for_order( 501 ), 'Reprocessing the same order must renew the purchase grants, not stack new ones.' );
$vip_stored_grants = $vip_engine->grants_for_user( 7 );
mw_assert_same( 1, count( $vip_stored_grants['gold365'] ), 'Grant rows must stay one-per-order.' );
mw_assert_same( array( 501 ), array_map( static function ( $entry ) {
	return $entry['order_id'];
}, $vip_stored_grants['gold365'] ), 'Re-granting must renew the existing order grant in place.' );

$vip_clock += 366 * 86400;
mw_assert_same( false, $vip_engine->user_has_access( 7, 'gold365' ), 'Timed plan grants must expire without a sweep.' );
mw_assert_same( true, $vip_engine->user_has_access( 7, 'vipgold' ), 'Lifetime grants must survive timed-grant expiry.' );
mw_assert_same( array( 'vipgold' ), $vip_engine->active_levels( 7 ), 'Expired grants must drop out of the active level list.' );
mw_assert_same( false, isset( $GLOBALS['mw_test_user_meta'][7][ ManaCore\MusicWave\Vip\VipPlans::GRANTS_META_KEY ]['gold365'] ), 'Expired grant rows must be pruned in place.' );

$GLOBALS['mw_test_orders'][501] = new TestWcOrder( 7, array( new TestWcOrderItem( 50 ) ) );
mw_assert_same( 1, $vip_engine->revoke_for_order( 501 ), 'Refunded orders must revoke their plan grants.' );
mw_assert_same( false, $vip_engine->user_has_access( 7, 'vipgold' ), 'Revoked plans must stop granting access immediately.' );
mw_assert_same( array(), $vip_engine->active_levels( 7 ), 'Revocation must empty the active level list.' );
mw_assert_same( false, isset( $GLOBALS['mw_test_user_meta'][7][ ManaCore\MusicWave\Vip\VipPlans::GRANTS_META_KEY ] ), 'Revoked grants must not linger in user meta.' );
mw_assert_same( true, in_array( 'music_wave_vip_plan_revoked', $GLOBALS['mw_test_actions'], true ), 'Plan revocation must fire the notification action.' );

$GLOBALS['mw_test_user_meta'][7][ ManaCore\MusicWave\Vip\VipPlans::GRANTS_META_KEY ] = 'corrupted-host-data';
mw_assert_same( false, $vip_engine->user_has_access( 7, 'vipgold' ), 'Corrupted grant payloads must fail closed.' );
mw_assert_same( false, isset( $GLOBALS['mw_test_user_meta'][7][ ManaCore\MusicWave\Vip\VipPlans::GRANTS_META_KEY ] ), 'Corrupted grant payloads must be removed.' );

// --- Reserved terms: a shorter plan bought during a longer one waits its turn ---

$reserve_clock  = 1710000000;
$reserve_now    = function () use ( &$reserve_clock ): int {
	return $reserve_clock;
};
$reserve_engine = new ManaCore\MusicWave\Vip\VipPlans(
	array(
		'halfyear' => array(
			'product_ids'   => array( 70 ),
			'duration_days' => 180,
		),
		'monthly'  => array(
			'product_ids'   => array( 71 ),
			'duration_days' => 30,
		),
	),
	false,
	$reserve_now
);

$halfyear_expiry = $reserve_clock + ( 180 * 86400 );
$monthly_expiry  = $halfyear_expiry + ( 30 * 86400 );

$GLOBALS['mw_test_orders'][701] = new TestWcOrder( 8, array( new TestWcOrderItem( 70 ) ) );
$GLOBALS['mw_test_orders'][702] = new TestWcOrder( 8, array( new TestWcOrderItem( 71 ) ) );

mw_assert_same( 1, $reserve_engine->grant_for_order( 701 ), 'The longer plan must grant immediately.' );
mw_assert_same( 1, $reserve_engine->grant_for_order( 702 ), 'Buying a shorter plan during a longer one must still grant.' );
mw_assert_same( true, $reserve_engine->user_has_access( 8, 'halfyear' ), 'The longer plan must stay active.' );
mw_assert_same( false, $reserve_engine->user_has_access( 8, 'monthly' ), 'A reserved term must not grant access before its start.' );
mw_assert_same( array( 'halfyear' ), $reserve_engine->active_levels( 8 ), 'Reserved terms must not appear in the active level list.' );

$reserved_monthly = array_values(
	array_filter(
		$reserve_engine->detailed_active_grants( 8 ),
		static function ( array $grant ): bool {
			return 'monthly' === $grant['level'];
		}
	)
);
mw_assert_same( 1, count( $reserved_monthly ), 'The reserved term must be reported exactly once.' );
mw_assert_same( true, $reserved_monthly[0]['is_reserved'], 'The shorter plan must be reported as a reserved term.' );
mw_assert_same( $halfyear_expiry, $reserved_monthly[0]['starts_at'], 'A reserved term must start exactly when the longer plan ends.' );
mw_assert_same( $monthly_expiry, $reserved_monthly[0]['expires_at'], 'A reserved term must keep its full duration after activation.' );
mw_assert_same( 180, $reserved_monthly[0]['reserved_days'], 'The reserved term must report its waiting period in days.' );

$reserve_clock = $halfyear_expiry;
mw_assert_same( false, $reserve_engine->user_has_access( 8, 'halfyear' ), 'The longer plan must end on its expiry date.' );
mw_assert_same( true, $reserve_engine->user_has_access( 8, 'monthly' ), 'The reserved plan must activate when the longer one ends.' );
mw_assert_same( array( 'monthly' ), $reserve_engine->active_levels( 8 ), 'Only the activated reserve must stay active.' );
mw_assert_same( true, $reserve_engine->user_has_access( 8, ManaCore\MusicWave\Vip\VipPlans::VIP_ROLE ), 'The umbrella VIP level must follow the active reserve.' );

$reserve_clock = $monthly_expiry;
mw_assert_same( false, $reserve_engine->user_has_access( 8, 'monthly' ), 'The activated reserve must expire after its own duration.' );

// Refunding the blocking term releases the reservation instead of leaving the
// paid shorter plan locked behind a start date that no longer exists.
$reserve_clock = 1710000000;
$GLOBALS['mw_test_orders'][703] = new TestWcOrder( 10, array( new TestWcOrderItem( 70 ) ) );
$GLOBALS['mw_test_orders'][704] = new TestWcOrder( 10, array( new TestWcOrderItem( 71 ) ) );
$reserve_engine->grant_for_order( 703 );
$reserve_engine->grant_for_order( 704 );
mw_assert_same( false, $reserve_engine->user_has_access( 10, 'monthly' ), 'The shorter plan must start out reserved.' );
mw_assert_same( 1, $reserve_engine->revoke_for_order( 703 ), 'Refunding the blocking plan must revoke it.' );
mw_assert_same( true, $reserve_engine->user_has_access( 10, 'monthly' ), 'Refunding the blocking plan must release the reservation.' );
$released_monthly = array_values(
	array_filter(
		$reserve_engine->detailed_active_grants( 10 ),
		static function ( array $grant ): bool {
			return 'monthly' === $grant['level'];
		}
	)
);
mw_assert_same( false, $released_monthly[0]['is_reserved'], 'A released reservation must stop being reported as reserved.' );
mw_assert_same( 1710000000 + ( 30 * 86400 ), $released_monthly[0]['expires_at'], 'A released reservation must run its full duration from today.' );
unset( $GLOBALS['mw_test_orders'][701], $GLOBALS['mw_test_orders'][702], $GLOBALS['mw_test_orders'][703], $GLOBALS['mw_test_orders'][704] );

// Settings round-trip: textarea parsing, preservation, and explicit clearing.
$GLOBALS['mw_test_options'][ ManaCore\MusicWave\Vip\VipSettings::OPTION ] = array();
$saved_vip_settings = ManaCore\MusicWave\Vip\VipSettings::sanitize(
	array(
		'plan_rows'        => "vipgold:50\ngold365:60:365",
		'promote_vip_role' => 'enabled',
	)
);
mw_assert_same( 2, count( $saved_vip_settings['vip_plans'] ), 'Saving VIP settings must normalize plan rows.' );
mw_assert_same( "vipgold:50\ngold365:60:365", $saved_vip_settings['plan_rows'], 'Saved settings must render back the canonical textarea value.' );
mw_assert_same( true, in_array( 'woocommerce_plans', (array) $saved_vip_settings['membership_sources'], true ), 'The plan source must be available by default.' );
$GLOBALS['mw_test_options'][ ManaCore\MusicWave\Vip\VipSettings::OPTION ] = $saved_vip_settings;

$partial_vip_settings = ManaCore\MusicWave\Vip\VipSettings::sanitize( array( 'delivery_provider' => 'local' ) );
mw_assert_same( 2, count( $partial_vip_settings['vip_plans'] ), 'Partial settings saves must preserve configured plans.' );
$cleared_vip_settings = ManaCore\MusicWave\Vip\VipSettings::sanitize( array( 'plan_rows' => '' ) );
mw_assert_same( array(), $cleared_vip_settings['vip_plans'], 'An emptied plan list must clear every plan.' );
$GLOBALS['mw_test_options'][ ManaCore\MusicWave\Vip\VipSettings::OPTION ] = $saved_vip_settings;

// The configurable provider exposes plan grants through the shared source.
// Stored grants remain authoritative even after a plan leaves the settings:
// config controls new purchases, live grant rows control access.
$GLOBALS['mw_test_orders'][503] = new TestWcOrder( 7, array( new TestWcOrderItem( 60 ) ) );
$vip_plan_provider  = new ManaCore\MusicWave\Vip\VipPlans(
	ManaCore\MusicWave\Vip\VipPlans::index_plans( $saved_vip_settings['vip_plans'] ),
	false,
	$vip_plans_now
);
$vip_plan_provider->grant_for_order( 503 );
$vip_membership_a = new ManaCore\MusicWave\Vip\ConfigurableMembershipProvider( array( 'woocommerce_plans' ), $vip_plan_provider );
mw_assert_same( true, $vip_membership_a->has_access( 7, array( 'gold365' ) ), 'Plan purchases must satisfy membership-gated releases.' );
$vip_membership_b = new ManaCore\MusicWave\Vip\ConfigurableMembershipProvider( array( 'role' ), $vip_plan_provider );
mw_assert_same( false, $vip_membership_b->has_access( 7, array( 'gold365' ) ), 'Disabling the plan source must withhold plan access.' );

// Fail closed: no plan source, or a user without grant rows.
$GLOBALS['mw_test_users'][6] = (object) array(
	'user_email' => 'plain@example.test',
	'roles'      => array(),
);
$vip_membership_c = new ManaCore\MusicWave\Vip\ConfigurableMembershipProvider( array( 'woocommerce_plans' ) );
mw_assert_same( false, $vip_membership_c->has_access( 6, array( 'gold365' ) ), 'Users without plan grants must stay denied.' );

// End-to-end: a plain registered user stays denied while a plan holder
// unlocks a membership-gated release through Core's policy engine.
$GLOBALS['mw_test_options'][ ManaCore\MusicWave\Vip\VipSettings::OPTION ] = $saved_vip_settings;
$vip_level_repository = new TestPolicyRepository();
$vip_level_repository->values['mw_access_mode']       = 'membership';
$vip_level_repository->values['mw_membership_levels'] = array( 'gold365' );
$vip_policy_engine    = new ManaCore\MusicWave\Core\Access\AccessPolicyEngine(
	$vip_level_repository,
	new ManaCore\MusicWave\Core\Commerce\PurchaseChecker( $vip_level_repository ),
	$vip_membership_a
);
$vip_plain_customer   = new ManaCore\MusicWave\Core\Access\AccessSubject( 6 );
mw_assert_same( false, $vip_policy_engine->decide( 1, $vip_plain_customer )->is_allowed(), 'Simple users must stay denied on membership-gated releases.' );
mw_assert_same( true, $vip_policy_engine->decide( 1, $customer )->is_allowed(), 'Plan purchasers must unlock membership-gated releases.' );
// --- Concurrency regression: two simultaneous order hooks must both persist ---
//
// The meta write hook re-enters the grant engine once, simulating a second
// completed order landing between the first hook's meta read and meta write.
// Without compare-and-set, the second write is clobbered by the first.
$GLOBALS['mw_test_orders'][601] = new TestWcOrder( 9, array( new TestWcOrderItem( 50 ) ) );
$GLOBALS['mw_test_orders'][602] = new TestWcOrder( 9, array( new TestWcOrderItem( 60 ) ) );
$vip_race_engine                = new ManaCore\MusicWave\Vip\VipPlans(
	ManaCore\MusicWave\Vip\VipPlans::index_plans( $saved_vip_settings['vip_plans'] ),
	false,
	$vip_plans_now
);
$GLOBALS['mw_test_meta_write_hook'] = static function ( string $operation, int $user_id, string $key, $value ): ?bool {
	unset( $operation, $value );
	static $reentered = false;
	if (
		! $reentered
		&& 9 === $user_id
		&& ManaCore\MusicWave\Vip\VipPlans::GRANTS_META_KEY === $key
	) {
		// A second completed order lands between the first hook's meta read
		// and meta write; both grants must survive.
		$reentered                        = true;
		$GLOBALS['mw_test_meta_write_hook'] = null;
		$second                           = ManaCore\MusicWave\Vip\VipPlans::from_settings( $GLOBALS['mw_test_options'][ ManaCore\MusicWave\Vip\VipSettings::OPTION ] );
		$second_grant                     = $second->grant_for_order( 602 );
		mw_assert_same( 1, $second_grant, 'The concurrent second order must still grant its level.' );
	}
	return null;
};
$vip_first_grant = $vip_race_engine->grant_for_order( 601 );
mw_assert_same( 1, $vip_first_grant, 'The first order must grant its level.' );
$vip_race_levels = $vip_race_engine->active_levels( 9 );
mw_assert_same( array( 'gold365', 'vipgold' ), $vip_race_levels, 'Concurrent order hooks must preserve every purchased level; compare-and-set prevents last-write-wins loss.' );
unset( $GLOBALS['mw_test_meta_write_hook'], $GLOBALS['mw_test_orders'][601], $GLOBALS['mw_test_orders'][602] );

unset( $GLOBALS['mw_test_users'][6], $GLOBALS['mw_test_orders'][501], $GLOBALS['mw_test_orders'][502], $GLOBALS['mw_test_orders'][503] );
$GLOBALS['mw_test_options'][ ManaCore\MusicWave\Vip\VipSettings::OPTION ] = array();

// --- Master switch: module_enabled / delivery_access normalization ---
$master_saved = ManaCore\MusicWave\Vip\VipSettings::sanitize(
	array(
		'module_enabled'  => 'disabled',
		'delivery_access' => 'everyone',
	)
);
mw_assert_same( 'disabled', $master_saved['module_enabled'], 'Saving VIP settings must persist a disabled master switch.' );
mw_assert_same( 'everyone', $master_saved['delivery_access'], 'Saving VIP settings must persist the everyone delivery-access mode.' );
mw_assert_same( 'enabled', ManaCore\MusicWave\Vip\VipSettings::all()['module_enabled'], 'The master switch must default to enabled.' );
mw_assert_same( 'logged_in', ManaCore\MusicWave\Vip\VipSettings::all()['delivery_access'], 'Delivery access must default to registered users only.' );
$master_garbage = ManaCore\MusicWave\Vip\VipSettings::sanitize( array( 'module_enabled' => 'yes', 'delivery_access' => 'guests' ) );
mw_assert_same( 'enabled', $master_garbage['module_enabled'], 'Unknown master-switch values must fall back to enabled.' );
mw_assert_same( 'logged_in', $master_garbage['delivery_access'], 'Unknown delivery-access values must fall back to logged-in.' );

// --- Full-form checkbox semantics depend on the master switch state ---
//
// While enforcement is on, the membership controls are editable, so absent
// checkboxes mean "unchecked". While it is off they are disabled in the UI
// and not submitted, so the stored configuration must survive untouched.
$GLOBALS['mw_test_options'][ ManaCore\MusicWave\Vip\VipSettings::OPTION ] = array(
	'module_enabled'     => 'enabled',
	'membership_sources' => array( 'role' ),
	'promote_vip_role'   => 'enabled',
);
$vip_full_on = ManaCore\MusicWave\Vip\SettingsPage::sanitize_settings(
	array(
		'mwvip_full_form' => '1',
		'module_enabled'  => 'enabled',
	)
);
mw_assert_same( 'enabled', $vip_full_on['module_enabled'], 'A checked master switch on a full-form save must keep enforcement on.' );
mw_assert_same( array(), $vip_full_on['membership_sources'], 'Unchecking every membership source while enforcement is on must clear them.' );
mw_assert_same( 'disabled', $vip_full_on['promote_vip_role'], 'An unchecked role-promotion box must disable promotion while enforcement is on.' );
$GLOBALS['mw_test_options'][ ManaCore\MusicWave\Vip\VipSettings::OPTION ] = array(
	'module_enabled'     => 'disabled',
	'membership_sources' => array( 'woocommerce_plans' ),
	'promote_vip_role'   => 'enabled',
	'delivery_access'    => 'everyone',
);
$vip_off_save = ManaCore\MusicWave\Vip\SettingsPage::sanitize_settings( array( 'mwvip_full_form' => '1' ) );
mw_assert_same( 'disabled', $vip_off_save['module_enabled'], 'An unchecked master switch on a full-form save must disable enforcement.' );
mw_assert_same( array( 'woocommerce_plans' ), $vip_off_save['membership_sources'], 'Membership sources must be preserved while enforcement is off because their controls are not submitted.' );
mw_assert_same( 'enabled', $vip_off_save['promote_vip_role'], 'Role promotion must be preserved while enforcement is off.' );
mw_assert_same( 'everyone', $vip_off_save['delivery_access'], 'Delivery access must be preserved while enforcement is off.' );
$vip_reenable = ManaCore\MusicWave\Vip\SettingsPage::sanitize_settings(
	array(
		'mwvip_full_form' => '1',
		'module_enabled'  => 'enabled',
	)
);
mw_assert_same( 'enabled', $vip_reenable['module_enabled'], 'Re-enabling enforcement on save must persist.' );
mw_assert_same( array( 'woocommerce_plans' ), $vip_reenable['membership_sources'], 'Re-enabling enforcement must not wipe the preserved membership configuration.' );
$GLOBALS['mw_test_options'][ ManaCore\MusicWave\Vip\VipSettings::OPTION ] = array();

// --- Core fail-open: no membership module means membership gates stay usable ---
$absent_repository = new TestPolicyRepository();
$absent_repository->values['mw_access_mode']       = 'membership';
$absent_repository->values['mw_membership_levels'] = array( 'gold365' );
$absent_engine = new ManaCore\MusicWave\Core\Access\AccessPolicyEngine(
	$absent_repository,
	new ManaCore\MusicWave\Core\Commerce\PurchaseChecker( $absent_repository )
);
$absent_guest = new ManaCore\MusicWave\Core\Access\AccessSubject( 0 );
mw_assert_same( true, $absent_engine->decide( 1, $absent_guest )->is_allowed(), 'Without any membership module, membership releases must stay usable by default.' );
mw_assert_same( 'membership_provider_absent', $absent_engine->decide( 1, $absent_guest )->reason(), 'The absent-provider allow must carry a distinct decision reason.' );

$GLOBALS['mw_test_filters']['music_wave_membership_absent_behavior'] = 'deny';
mw_assert_same( false, $absent_engine->decide( 1, $absent_guest )->is_allowed(), 'The deny absent-behavior must restore fail-closed membership gating.' );
unset( $GLOBALS['mw_test_filters']['music_wave_membership_absent_behavior'] );

// --- Default plan products: one-click presets map idempotently ---
$GLOBALS['mw_test_options'][ ManaCore\MusicWave\Vip\VipSettings::OPTION ] = array();
$presets_created = ManaCore\MusicWave\Vip\PlanProducts::create_defaults();
mw_assert_same( 3, $presets_created['created'], 'The default-plans button must create the 1/6/12-month products.' );
$presets_settings = ManaCore\MusicWave\Vip\VipSettings::all();
mw_assert_same( 3, count( $presets_settings['vip_plans'] ), 'Created presets must be mapped as VIP plans.' );
mw_assert_same( true, in_array( 'vip-1m', array_map( static function ( $plan ) { return $plan['level']; }, $presets_settings['vip_plans'] ), true ), 'The one-month preset level must be mapped.' );
$presets_again = ManaCore\MusicWave\Vip\PlanProducts::create_defaults();
mw_assert_same( 0, $presets_again['created'], 'Pressing the default-plans button again must not duplicate mappings.' );
mw_assert_same( 3, count( ManaCore\MusicWave\Vip\VipSettings::all()['vip_plans'] ), 'Idempotent preset creation must keep the plan list stable.' );

// --- Membership panel data: structure for the shared membership surfaces ---
$GLOBALS['mw_test_users'][12] = (object) array( 'user_email' => 'member@example.test' );
$panel_guest = ManaCore\MusicWave\Vip\PlanProducts::products_data( 0 );
mw_assert_same( true, $panel_guest['module_enabled'], 'Panel data must report the module as enabled while the switch is on.' );
mw_assert_same( array(), $panel_guest['active'], 'Guests must have no active membership rows.' );
mw_assert_same( 3, count( $panel_guest['plans'] ), 'Panel data must expose every mapped plan product.' );
$first_plan = $panel_guest['plans'][0];
mw_assert_same( true, isset( $first_plan['title'], $first_plan['duration'], $first_plan['url'], $first_plan['level'] ), 'Plan cards must carry title, duration, URL, and level.' );
mw_assert_same( '1 ماه', $first_plan['duration'], 'Durations must render a human label.' );
$panel_disabled = ManaCore\MusicWave\Vip\VipSettings::sanitize( array( 'module_enabled' => 'disabled' ) );
$GLOBALS['mw_test_options'][ ManaCore\MusicWave\Vip\VipSettings::OPTION ] = $panel_disabled;
mw_assert_same( false, ManaCore\MusicWave\Vip\PlanProducts::products_data( 12 )['module_enabled'], 'Panel data must reflect a disabled master switch.' );
mw_assert_same( 'مادام‌العمر', ManaCore\MusicWave\Vip\PlanProducts::duration_label( 0 ), 'Zero-duration plans must read as lifetime.' );
mw_assert_same( '6 ماه', ManaCore\MusicWave\Vip\PlanProducts::duration_label( 180 ), 'Durations must read naturally.' );
mw_assert_same( '1 سال', ManaCore\MusicWave\Vip\PlanProducts::duration_label( 365 ), 'Annual durations must read as years.' );
unset( $GLOBALS['mw_test_users'][12] );
$GLOBALS['mw_test_options'][ ManaCore\MusicWave\Vip\VipSettings::OPTION ] = array();

// --- Policy-driven anonymous delivery (the VIP "everyone" mode) ---
// Guests receive anonymous tokens exactly when the policy allows them;
// purchase and strict membership releases keep denying anonymous visitors.
$guest_token = ( new ManaCore\MusicWave\Core\Downloads\DownloadTokenService( 'test-download-secret' ) )->issue( 1, 0, 60, 'guest-nonce', 'mp3-320', 'stream' );
$guest_claims = ( new ManaCore\MusicWave\Core\Downloads\DownloadTokenService( 'test-download-secret' ) )->verify( (string) $guest_token, 'guest-nonce' );
mw_assert_same( 0, null === $guest_claims ? -1 : $guest_claims->user_id(), 'Anonymous tokens must verify with a zero user binding.' );

$guest_repository = new TestPolicyRepository();
$guest_repository->values['mw_access_mode']      = 'public';
$guest_repository->values['mw_download_assets']  = array(
	array(
		'key'      => 'mp3-320',
		'label'    => 'MP3 320',
		'asset_id' => 'local:guest/release.mp3',
	),
);
$guest_provider = new TestDownloadProvider();
$guest_resolver = new ManaCore\MusicWave\Core\Downloads\DownloadResolver(
	$guest_repository,
	new ManaCore\MusicWave\Core\Access\AccessPolicyEngine( $guest_repository, new ManaCore\MusicWave\Core\Commerce\PurchaseChecker( $guest_repository ) ),
	new ManaCore\MusicWave\Core\Downloads\DownloadTokenService( 'guest-secret' ),
	new ManaCore\MusicWave\Core\Downloads\TransientReplayStore(),
	$guest_provider
);
mw_assert_same( true, $guest_resolver->can_request( 1 ), 'Guests must reach delivery endpoints when the policy allows them.' );
$guest_ticket = $guest_resolver->issue( 1, new ManaCore\MusicWave\Core\Access\AccessSubject( 0 ), 'guest-binding', 'mp3-320' );
mw_assert_same( true, is_string( $guest_ticket ), 'Policy-allowed guests must receive an opaque delivery ticket.' );
mw_assert_same( true, $guest_resolver->deliver( 1, 0, (string) $guest_ticket, 'guest-binding' ), 'Anonymous tickets must deliver through the provider.' );

$guest_locked_repository = new TestPolicyRepository();
$guest_locked_repository->values['mw_access_mode']       = 'membership';
$guest_locked_repository->values['mw_membership_levels'] = array( 'gold365' );
$guest_locked_provider  = new TestMembershipProvider();
$guest_locked_provider->granted = true;
$guest_locked_resolver  = new ManaCore\MusicWave\Core\Downloads\DownloadResolver(
	$guest_locked_repository,
	new ManaCore\MusicWave\Core\Access\AccessPolicyEngine( $guest_locked_repository, new ManaCore\MusicWave\Core\Commerce\PurchaseChecker( $guest_locked_repository ), $guest_locked_provider ),
	new ManaCore\MusicWave\Core\Downloads\DownloadTokenService( 'guest-locked-secret' ),
	new ManaCore\MusicWave\Core\Downloads\TransientReplayStore(),
	new TestDownloadProvider()
);
mw_assert_same( null, $guest_locked_resolver->issue( 1, new ManaCore\MusicWave\Core\Access\AccessSubject( 0 ), 'guest-binding' ), 'Strict membership releases must keep denying anonymous visitors.' );
mw_assert_same( 'entitlement', $guest_locked_resolver->last_deny_reason(), 'Anonymous denials must carry the entitlement reason.' );

// Guest rate limiting: per-IP buckets, never a shared zero bucket.
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$guest_limiter = new ManaCore\MusicWave\Core\Downloads\DownloadRateLimiter();
mw_assert_same( true, $guest_limiter->allow( 0 ), 'Guest token requests must be rate limited per IP, not blocked outright.' );
mw_assert_same( true, $guest_limiter->allow( 8 ), 'Registered visitors must keep their own rate bucket.' );
unset( $_SERVER['REMOTE_ADDR'] );

// --- Regression tests: September 2026 upgrade round (review report section 17) ---

// P4: PlaylistRepository::for_user() must resolve item counts with ONE grouped
// store call instead of one items() query per playlist (the N+1).
$playlist_store  = new TestPlaylistStore();
$night_id        = $playlist_store->insert( array( 'user_id' => 7, 'title' => 'Night Drive', 'visibility' => 'private', 'share_token' => '', 'created_at' => 100, 'updated_at' => 200 ) );
$cafe_id         = $playlist_store->insert( array( 'user_id' => 7, 'title' => 'Cafe Set', 'visibility' => 'public', 'share_token' => 'shared-token', 'created_at' => 300, 'updated_at' => 400 ) );
$playlist_store->replace_items( $night_id, array( 21, 22, 23, 22 ) );
$playlist_store->replace_items( $cafe_id, array() );
$for_user_playlists = ( new ManaCore\MusicWave\Core\Playlists\PlaylistRepository( $playlist_store ) )->for_user( 7 );
mw_assert_same( 2, count( $for_user_playlists ), 'Playlist listings must return every owned playlist.' );
mw_assert_same( 3, isset( $for_user_playlists[0]['count'] ) ? $for_user_playlists[0]['count'] : null, 'Playlist item counts must come from the grouped store query and match the deduplicated item view.' );
mw_assert_same( 0, isset( $for_user_playlists[1]['count'] ) ? $for_user_playlists[1]['count'] : null, 'Playlists without items must report zero.' );
mw_assert_same( 1, count( $playlist_store->count_calls ), 'Item counts must be resolved with a single grouped store call, never per playlist.' );
mw_assert_same( array( $night_id, $cafe_id ), isset( $playlist_store->count_calls[0] ) ? $playlist_store->count_calls[0] : array(), 'The grouped count call must receive every listed playlist ID.' );
mw_assert_same( array(), ( new ManaCore\MusicWave\Core\Playlists\DatabasePlaylistStore() )->item_counts_for( array() ), 'Empty ID lists must short-circuit without touching storage.' );
mw_assert_same( array(), ( new ManaCore\MusicWave\Core\Playlists\DatabasePlaylistStore() )->item_counts_for( array( 11, 0, 'x' ) ), 'Count lookups must degrade to an empty map before the migration has run.' );

// S5/P6: the shared route rate limiter counts atomically per bucket and actor.
$GLOBALS['mw_test_transients'] = array();
$route_limiter                 = new ManaCore\MusicWave\Core\Support\RouteRateLimiter( 2, 60 );
mw_assert_same( true, $route_limiter->allow( 'playlist-write', 'u7' ), 'The first route request must be allowed.' );
mw_assert_same( true, $route_limiter->allow( 'playlist-write', 'u7' ), 'Requests below the route limit must be allowed.' );
mw_assert_same( false, $route_limiter->allow( 'playlist-write', 'u7' ), 'Route requests must stop at the configured per-actor limit.' );
mw_assert_same( true, $route_limiter->allow( 'playlist-write', 'u9' ), 'Route buckets must be independent per actor.' );
mw_assert_same( true, $route_limiter->allow( 'library-write', 'u7' ), 'Route buckets must be independent per route.' );
mw_assert_same( true, ( new ManaCore\MusicWave\Core\Support\RouteRateLimiter( 0, 60 ) )->allow( 'playlist-write', 'u7' ), 'A non-positive limit must disable route rate limiting.' );
$GLOBALS['mw_test_transients']                            = array();
$GLOBALS['mw_test_filters']['music_wave_route_rate_limit'] = 1;
mw_assert_same( true, ( new ManaCore\MusicWave\Core\Support\RouteRateLimiter() )->allow( 'listening-progress', 'u7' ), 'The shared limiter must honor the route rate limit filter.' );
mw_assert_same( false, ( new ManaCore\MusicWave\Core\Support\RouteRateLimiter() )->allow( 'listening-progress', 'u7' ), 'The filter-configured route limit must still cut requests off.' );
unset( $GLOBALS['mw_test_filters']['music_wave_route_rate_limit'] );

// S5: discovery counters delegate to the same atomic engine, buckets stay independent.
$GLOBALS['mw_test_transients'] = array();
$shared_discovery_limiter      = new ManaCore\MusicWave\Core\Discovery\DiscoveryRateLimiter( 2, 60 );
mw_assert_same( true, $shared_discovery_limiter->allow( 'suggest' ), 'The shared discovery counter must allow requests below the limit.' );
mw_assert_same( true, $shared_discovery_limiter->allow( 'suggest' ), 'The shared discovery counter must honor the constructor limit.' );
mw_assert_same( false, $shared_discovery_limiter->allow( 'suggest' ), 'The shared discovery counter must stop at the configured limit.' );
mw_assert_same( true, $shared_discovery_limiter->allow( 'facets' ), 'Discovery buckets must stay independent after delegating to the shared engine.' );

// S7: audio MIME types are additive, capability-gated, and never override.
$GLOBALS['mw_test_current_user'] = 7;
$GLOBALS['mw_test_capabilities'] = array( 'upload_files' );
$foundation_mimes                = new ManaCore\MusicWave\Core\Modules\Foundation();
$extended_mimes                  = $foundation_mimes->add_audio_mimes( array( 'jpg' => 'image/jpeg', 'mp3' => 'audio/custom' ) );
mw_assert_same( 'audio/custom', isset( $extended_mimes['mp3'] ) ? $extended_mimes['mp3'] : '', 'Existing MIME assignments must never be overridden.' );
mw_assert_same( 'image/jpeg', isset( $extended_mimes['jpg'] ) ? $extended_mimes['jpg'] : '', 'Audio MIME additions must keep the existing allow-list.' );
mw_assert_same( 'audio/flac', isset( $extended_mimes['flac'] ) ? $extended_mimes['flac'] : '', 'Users who may upload must gain the flac audio MIME type.' );
mw_assert_same( 'audio/ogg', isset( $extended_mimes['ogg'] ) ? $extended_mimes['ogg'] : '', 'Users who may upload must gain the ogg audio MIME type.' );
mw_assert_same( 'audio/wav', isset( $extended_mimes['wav'] ) ? $extended_mimes['wav'] : '', 'Users who may upload must gain the wav audio MIME type.' );
mw_assert_same( 'audio/mp4', isset( $extended_mimes['m4a'] ) ? $extended_mimes['m4a'] : '', 'Users who may upload must gain the m4a audio MIME type.' );
$GLOBALS['mw_test_capabilities'] = array();
mw_assert_same( array( 'jpg' => 'image/jpeg' ), $foundation_mimes->add_audio_mimes( array( 'jpg' => 'image/jpeg' ) ), 'Users without upload_files must not gain audio MIME types.' );

// P7: listening.js must enqueue only for signed-in visitors on pages with the block.
if ( ! defined( 'MUSIC_WAVE_CORE_URL' ) ) {
	define( 'MUSIC_WAVE_CORE_URL', 'https://example.test/wp-content/plugins/music-wave-core/' );
}
if ( ! defined( 'MUSIC_WAVE_CORE_VERSION' ) ) {
	define( 'MUSIC_WAVE_CORE_VERSION', '0.0.0-test' );
}
$GLOBALS['mw_test_current_user'] = 9;
$GLOBALS['mw_test_has_block']    = false;
$GLOBALS['mw_test_scripts']      = array();
$GLOBALS['mw_test_localized']    = array();
( new ManaCore\MusicWave\Core\Blocks\ListeningBlocks( new ManaCore\MusicWave\Core\Listening\ListeningRepository() ) )->enqueue_assets();
mw_assert_same( array(), $GLOBALS['mw_test_scripts'], 'listening.js must stay unloaded on pages without the continue-listening block.' );
$GLOBALS['mw_test_has_block'] = true;
( new ManaCore\MusicWave\Core\Blocks\ListeningBlocks( new ManaCore\MusicWave\Core\Listening\ListeningRepository() ) )->enqueue_assets();
mw_assert_same( array( 'music-wave-listening' ), $GLOBALS['mw_test_scripts'], 'The continue-listening block must load its consent handler when rendered.' );
$GLOBALS['mw_test_has_block'] = false;
$GLOBALS['mw_test_scripts']   = array();
$GLOBALS['mw_test_localized'] = array();

// SEO1: the social image chain falls back featured cover, then site icon, then the filter.
$seo_repository = new TestPolicyRepository();
$seo_metadata   = new ManaCore\MusicWave\Core\Seo\ReleaseMetadata( $seo_repository );
$GLOBALS['mw_test_terms_by_tax'][1]['mw_artist'] = array();
$GLOBALS['mw_test_thumbnails'][1]                = 'https://cdn.example.test/cover-full.jpg';
$metadata_with_cover                             = $seo_metadata->metadata( 1 );
mw_assert_same( 'https://cdn.example.test/cover-full.jpg', $metadata_with_cover['image'], 'Featured covers must remain the primary social share image.' );
mw_assert_same( true, $metadata_with_cover['image_large'], 'Featured covers must request the large summary card.' );
unset( $GLOBALS['mw_test_thumbnails'][1] );
$GLOBALS['mw_test_site_icon'] = 'https://example.test/icon-512.png';
$metadata_with_icon           = $seo_metadata->metadata( 1 );
mw_assert_same( 'https://example.test/icon-512.png', $metadata_with_icon['image'], 'Releases without a cover must fall back to the site icon for social cards.' );
mw_assert_same( false, $metadata_with_icon['image_large'], 'Site-icon fallbacks must keep the compact summary card.' );
$GLOBALS['mw_test_filters']['music_wave_og_image'] = 'https://cdn.example.test/generated-banner.jpg';
$metadata_filtered                                 = $seo_metadata->metadata( 1 );
mw_assert_same( 'https://cdn.example.test/generated-banner.jpg', $metadata_filtered['image'], 'The og:image filter must override the built-in fallback chain.' );
mw_assert_same( true, $metadata_filtered['image_large'], 'Filter-supplied share images must request the large summary card.' );
unset( $GLOBALS['mw_test_filters']['music_wave_og_image'] );
$GLOBALS['mw_test_site_icon'] = '';
$metadata_blank               = $seo_metadata->metadata( 1 );
mw_assert_same( '', $metadata_blank['image'], 'Releases without any image source must emit an empty og:image value.' );
unset( $GLOBALS['mw_test_site_icon'], $GLOBALS['mw_test_terms_by_tax'][1]['mw_artist'] );

// Account & Request Tracking cohesion checks:
// 1. All 11 unified account/membership blocks live in AccountLibrary::BLOCK_RENDERERS (dashboard-edit merged into dashboard-account).
// 2. Reply/conversation threads stay collapsed inside the drawer (`hidden` + `aria-expanded="false"`) and never render upfront `.mw-dashboard-requests__answered` boxes.
mw_assert_same( 11, count( ManaCore\MusicWave\Core\Commerce\AccountLibrary::BLOCK_RENDERERS ), 'AccountLibrary must register all 11 unified account & membership blocks.' );
mw_assert_same( false, isset( ManaCore\MusicWave\Core\Commerce\AccountLibrary::BLOCK_RENDERERS['music-wave/dashboard-edit'] ), 'Duplicate dashboard-edit must be merged into music-wave/dashboard-account.' );
mw_assert_same( 'render_requests_block', ManaCore\MusicWave\Core\Commerce\AccountLibrary::BLOCK_RENDERERS['music-wave/dashboard-requests'] ?? '', 'AccountLibrary must dispatch music-wave/dashboard-requests to render_requests_block.' );

$mw_prev_user_id = $GLOBALS['mw_test_current_user'] ?? 0;
$mw_req_repo     = new ManaCore\MusicWave\Core\Requests\RequestRepository();
$mw_req_post_id  = $mw_req_repo->create(
	array(
		'name'    => 'آرش',
		'email'   => 'arash@example.test',
		'type'    => 'song',
		'subject' => 'درخواست تست گفتگو',
		'message' => 'لطفاً این اثر را بررسی کنید.',
	),
	77
);
$mw_req_repo->append_log( $mw_req_post_id, ManaCore\MusicWave\Core\Requests\RequestRepository::LOG_REPLY, 'این قطعه تا فردا منتشر می‌شود.', 1 );
$mw_req_repo->append_log( $mw_req_post_id, ManaCore\MusicWave\Core\Requests\RequestRepository::LOG_REPLY, 'ممنون از پیگیری شما.', 77, array( 'by' => 'user' ) );

$mw_request_renderer             = new ManaCore\MusicWave\Core\Account\RequestTrackingRenderer( $mw_req_repo );
$GLOBALS['mw_test_current_user'] = 0;
$mw_guest_requests_html          = $mw_request_renderer->render( array() );
mw_assert_same( true, false !== strpos( $mw_guest_requests_html, 'mw-dashboard-requests__empty' ) && false !== strpos( $mw_guest_requests_html, 'برای مشاهده درخواست‌های خود وارد شوید.' ), 'Signed-out viewers must receive the compact guest sign-in prompt.' );

$GLOBALS['mw_test_current_user'] = 77;
$mw_user_requests_html           = $mw_request_renderer->render( array() );
mw_assert_same( false, strpos( $mw_user_requests_html, 'mw-dashboard-requests__answered' ), 'Request tracking must never render an upfront mw-dashboard-requests__answered box.' );
mw_assert_same( true, false !== strpos( $mw_user_requests_html, 'data-mw-chat-toggle="' . $mw_req_post_id . '"' ) && false !== strpos( $mw_user_requests_html, 'aria-expanded="false"' ), 'Request tracking toggle button must start collapsed with aria-expanded="false".' );
mw_assert_same( true, false !== strpos( $mw_user_requests_html, 'class="mw-request-chat__thread-wrap" hidden' ), 'Request conversation drawer must stay hidden until toggled underneath the item.' );
mw_assert_same( true, false !== strpos( $mw_user_requests_html, 'class="mw-request-chat__count"' ) && false !== strpos( $mw_user_requests_html, 'mw-request-chat__message--admin' ) && false !== strpos( $mw_user_requests_html, 'mw-request-chat__message--user' ), 'Answered requests must surface the message count badge and full user/support thread inside the collapsed drawer.' );

unset( $GLOBALS['mw_test_posts'][ $mw_req_post_id ], $GLOBALS['mw_test_meta'][ $mw_req_post_id ] );
$GLOBALS['mw_test_current_user'] = $mw_prev_user_id;

// Multi-file release vs single-file playback & «پخش همه» contract verification.
$mw_mf_single_id = 88901;
$mw_mf_album_id  = 88902;
$GLOBALS['mw_test_posts'][ $mw_mf_single_id ]    = new WP_Post( $mw_mf_single_id, 'mw_release', 'tak-ahang-shab' );
$GLOBALS['mw_test_posts'][ $mw_mf_album_id ]     = new WP_Post( $mw_mf_album_id, 'mw_release', 'album-sepideh' );
$GLOBALS['mw_test_titles'][ $mw_mf_single_id ]   = 'تک‌آهنگ شب';
$GLOBALS['mw_test_titles'][ $mw_mf_album_id ]    = 'آلبوم سپیده';
$GLOBALS['mw_test_types'][ $mw_mf_single_id ]    = 'mw_release';
$GLOBALS['mw_test_types'][ $mw_mf_album_id ]     = 'mw_release';
$GLOBALS['mw_test_statuses'][ $mw_mf_single_id ] = 'publish';
$GLOBALS['mw_test_statuses'][ $mw_mf_album_id ]  = 'publish';
$GLOBALS['mw_test_meta'][ $mw_mf_single_id ]     = array(
	'mw_access_mode'     => 'public',
	'mw_download_assets' => array(
		array(
			'key'        => 'single-mp3',
			'label'      => 'MP3 320 kbps',
			'asset_id'   => 'local:single/track-320.mp3',
			'format'     => 'mp3',
			'file_key'   => 'main-download',
			'file_label' => 'دانلود اصلی',
		),
		array(
			'key'        => 'single-flac',
			'label'      => 'FLAC lossless',
			'asset_id'   => 'local:single/track.flac',
			'format'     => 'flac',
			'file_key'   => 'main-download',
			'file_label' => 'دانلود اصلی',
		),
	),
);
$GLOBALS['mw_test_meta'][ $mw_mf_album_id ]      = array(
	'mw_access_mode'     => 'public',
	'mw_download_assets' => array(
		array(
			'key'        => 'track-1-mp3',
			'label'      => 'MP3 320 kbps',
			'asset_id'   => 'local:album/01-intro.mp3',
			'format'     => 'mp3',
			'file_key'   => 'track-1',
			'file_label' => 'قطعه اول',
		),
		array(
			'key'        => 'track-2-mp3',
			'label'      => 'MP3 320 kbps',
			'asset_id'   => 'local:album/02-main.mp3',
			'format'     => 'mp3',
			'file_key'   => 'track-2',
			'file_label' => 'قطعه دوم',
		),
		array(
			'key'        => 'track-3-mp3',
			'label'      => 'MP3 320 kbps',
			'asset_id'   => 'local:album/03-outro.mp3',
			'format'     => 'mp3',
			'file_key'   => 'track-3',
			'file_label' => 'قطعه سوم',
		),
	),
);

$mw_mf_repo    = new ManaCore\MusicWave\Core\Infrastructure\WordPressReleaseRepository( new ManaCore\MusicWave\Core\Schema\ReleaseMetaSchema() );
$mw_mf_checker = new ManaCore\MusicWave\Core\Commerce\PurchaseChecker( $mw_mf_repo );
$mw_mf_policy  = new ManaCore\MusicWave\Core\Access\AccessPolicyEngine( $mw_mf_repo, $mw_mf_checker );
$mw_mf_subject = new ManaCore\MusicWave\Core\Access\AccessSubject( 0 );

$mw_mf_single_expanded = ManaCore\MusicWave\Core\Playback\TrackStreamPresenter::expand_release_tracks(
	$mw_mf_single_id,
	$mw_mf_single_id,
	$mw_mf_subject,
	$mw_mf_policy,
	$mw_mf_repo
);
mw_assert_same( 1, count( $mw_mf_single_expanded ), 'A single song with multiple quality bitrates must expand to exactly 1 playback track.' );
mw_assert_same( 'single-mp3', $mw_mf_single_expanded[0]['quality'], 'Single song must default to its first streamable quality.' );

$mw_mf_album_expanded = ManaCore\MusicWave\Core\Playback\TrackStreamPresenter::expand_release_tracks(
	$mw_mf_album_id,
	$mw_mf_album_id,
	$mw_mf_subject,
	$mw_mf_policy,
	$mw_mf_repo
);
mw_assert_same( 3, count( $mw_mf_album_expanded ), 'A post with 3 distinct streamable files must expand all 3 files for Play All.' );
mw_assert_same( array( 'track-1-mp3', 'track-2-mp3', 'track-3-mp3' ), array_column( $mw_mf_album_expanded, 'quality' ), 'Expanded multi-file post queue must preserve exact file order and per-file quality keys.' );
mw_assert_same( 'آلبوم سپیده — قطعه دوم', $mw_mf_album_expanded[1]['title'], 'Expanded multi-file post tracks must include their distinct file label.' );

$mw_mf_release_blocks = new ManaCore\MusicWave\Core\Blocks\ReleaseBlocks( $mw_mf_policy, $mw_mf_repo );
$mw_mf_single_hero    = $mw_mf_release_blocks->render_preview_player( array( 'releaseId' => $mw_mf_single_id ) );
$mw_mf_album_hero     = $mw_mf_release_blocks->render_preview_player( array( 'releaseId' => $mw_mf_album_id ) );
$mw_mf_album_panel    = $mw_mf_release_blocks->render_download_button( array( 'releaseId' => $mw_mf_album_id ) );

mw_assert_same( true, false !== strpos( $mw_mf_single_hero, 'data-mw-play-mode="single"' ) && false !== strpos( $mw_mf_single_hero, 'پخش الان' ), 'Single-file post hero trigger must render single mode with پخش الان.' );
mw_assert_same( true, false !== strpos( $mw_mf_album_hero, 'data-mw-play-mode="all"' ) && false !== strpos( $mw_mf_album_hero, 'پخش همه' ), 'Multi-file post hero trigger must render all mode with پخش همه.' );
mw_assert_same( 3, substr_count( $mw_mf_album_panel, 'class="mw-secure-play-button ' ), 'Download panel must render one ActionButtonStyle-synchronized mw-secure-play-button per file row.' );
mw_assert_same( 3, substr_count( $mw_mf_album_panel, '<span class="mw-secure-play-button__label">پخش</span>' ), 'Every mw-secure-play-button inside دانلود این انتشار must display پخش (never پخش همه).' );
mw_assert_same( false, strpos( $mw_mf_album_panel, 'mw-secure-audio' ), 'Download panel must delegate streaming to the global player without hidden duplicate audio tags.' );

	unset(
		$GLOBALS['mw_test_posts'][ $mw_mf_single_id ],
		$GLOBALS['mw_test_posts'][ $mw_mf_album_id ],
		$GLOBALS['mw_test_titles'][ $mw_mf_single_id ],
		$GLOBALS['mw_test_titles'][ $mw_mf_album_id ],
		$GLOBALS['mw_test_meta'][ $mw_mf_single_id ],
		$GLOBALS['mw_test_meta'][ $mw_mf_album_id ],
		$GLOBALS['mw_test_types'][ $mw_mf_single_id ],
		$GLOBALS['mw_test_types'][ $mw_mf_album_id ],
		$GLOBALS['mw_test_statuses'][ $mw_mf_single_id ],
		$GLOBALS['mw_test_statuses'][ $mw_mf_album_id ]
	);

// Cross-repository OOP, SOLID, DRY, and directory-guard verification across Core, VIP, and Theme.
require_once dirname( __DIR__ ) . '/musicwave/src/Autoloader.php';
ManaCore\MusicWave\Theme\Autoloader::register( dirname( __DIR__ ) . '/musicwave/src' );

$mw_expected_oop_symbols = array(
	// Core shared DRY & SRP services.
	ManaCore\MusicWave\Core\Support\TransientCounter::class,
	ManaCore\MusicWave\Core\Support\Concerns\AuthenticatedRestPermissions::class,
	ManaCore\MusicWave\Core\Support\DatabaseTable::class,
	ManaCore\MusicWave\Core\Support\MailDispatcher::class,
	ManaCore\MusicWave\Core\Admin\AdminAssets::class,
	ManaCore\MusicWave\Core\Playback\TrackStreamPresenter::class,
	ManaCore\MusicWave\Core\Blocks\BlockMetadata::class,
	ManaCore\MusicWave\Core\Blocks\Concerns\TermShelfSupport::class,
	ManaCore\MusicWave\Core\Blocks\Release\ReleaseBlockContext::class,
	ManaCore\MusicWave\Core\Blocks\Release\ReleaseMetaRenderer::class,
	ManaCore\MusicWave\Core\Blocks\Release\CollectionAndDownloadRenderer::class,
	ManaCore\MusicWave\Core\Blocks\Release\CatalogFilterRenderer::class,
	ManaCore\MusicWave\Core\Blocks\Release\RelatedReleasesRenderer::class,
	ManaCore\MusicWave\Core\Access\AccessModePolicy::class,
	ManaCore\MusicWave\Core\Access\AccessCtaResolver::class,
	ManaCore\MusicWave\Core\Support\FrontendRuntime::class,
	// VIP SRP checkout, plan catalog, single-product presenter & WordPress user management services.
	ManaCore\MusicWave\Vip\Delivery\ProtectedDeliveryDiagnostics::class,
	ManaCore\MusicWave\Vip\Checkout\SubscriptionOrderInspector::class,
	ManaCore\MusicWave\Vip\Checkout\DirectCheckoutHandler::class,
	ManaCore\MusicWave\Vip\Checkout\VirtualCheckoutSimplifier::class,
	ManaCore\MusicWave\Vip\Checkout\SubscriptionOrderPresenter::class,
	ManaCore\MusicWave\Vip\Plans\VipPlanCatalog::class,
	ManaCore\MusicWave\Vip\Plans\VipProductPagePresenter::class,
	ManaCore\MusicWave\Vip\Plans\VipGrantsStore::class,
	ManaCore\MusicWave\Vip\Users\UserActivityInspector::class,
	ManaCore\MusicWave\Vip\Users\UsersListIntegration::class,
	ManaCore\MusicWave\Vip\Users\UserProfilePanel::class,
	ManaCore\MusicWave\Core\Account\MembershipPresenter::class,
	// Theme OOP services.
	ManaCore\MusicWave\Theme\Autoloader::class,
	ManaCore\MusicWave\Theme\Theme::class,
	ManaCore\MusicWave\Theme\Assets\AssetManager::class,
	ManaCore\MusicWave\Theme\Templates\TemplateManager::class,
	ManaCore\MusicWave\Theme\Blocks\BlockRegistry::class,
	ManaCore\MusicWave\Theme\Blocks\ShelfAndSliderRenderer::class,
	ManaCore\MusicWave\Theme\Blocks\ChromeBlocksRenderer::class,
	ManaCore\MusicWave\Theme\Header\SiteHeaderManager::class,
	ManaCore\MusicWave\Theme\Header\HeaderSearchEnhancer::class,
	ManaCore\MusicWave\Theme\Navigation\NavigationIconService::class,
	ManaCore\MusicWave\Theme\Routing\RouteManager::class,
	ManaCore\MusicWave\Theme\Widgets\WidgetAreaSeeder::class,
);
foreach ( $mw_expected_oop_symbols as $mw_symbol ) {
	mw_assert_same( true, class_exists( $mw_symbol ) || trait_exists( $mw_symbol ), 'Expected OOP symbol to autoload cleanly: ' . $mw_symbol );
}

mw_assert_same(
	ManaCore\MusicWave\Vip\Storage\MimeValidator::allowed_extensions(),
	( new ManaCore\MusicWave\Vip\ProtectedAssetStorage() )->allowed_extensions(),
	'ProtectedAssetStorage must share MimeValidator::allowed_extensions() as single source of truth.'
);
mw_assert_same(
	8,
	count( ManaCore\MusicWave\Core\Account\WooAccountBridge::VIRTUAL_ADDRESS_KEYS ),
	'WooAccountBridge::VIRTUAL_ADDRESS_KEYS must expose all 8 virtual checkout address keys shared with VIP.'
);

$mw_theme_kernel = new ManaCore\MusicWave\Theme\Theme();
mw_assert_same( true, count( $mw_theme_kernel->assets()->style_modules() ) >= 30, 'Theme AssetManager must expose modular stylesheets.' );
mw_assert_same( array( 'release-shelf', 'release-slider', 'breadcrumb', 'artist-profile', 'term-hero', 'header-search' ), array_keys( $mw_theme_kernel->blocks()->presentation_style_variations() ), 'Theme BlockRegistry must expose deduplicated presentation style variations including breadcrumb.' );

// --- BreadcrumbTrail, BreadcrumbRenderer & Site Editor Preview Suite ---
$mw_prev_rest_request = defined( 'REST_REQUEST' );
$mw_breadcrumb_renderer = $mw_theme_kernel->breadcrumbs();
$mw_preview_breadcrumb_html = $mw_breadcrumb_renderer->render(
	array(
		'homeLabel'     => 'خانه',
		'separator'     => 'chevron',
		'showCurrent'   => true,
		'showHomeIcon'  => true,
		'previewSample' => true,
		'styleVariant'  => 'pill',
	)
);
mw_assert_same( true, false !== strpos( $mw_preview_breadcrumb_html, 'mw-breadcrumb--pill' ), 'BreadcrumbRenderer must emit the selected pill style variation class.' );
mw_assert_same( true, false !== strpos( $mw_preview_breadcrumb_html, 'aria-current="page"' ) && false !== strpos( $mw_preview_breadcrumb_html, '<nav' ), 'BreadcrumbRenderer must render accessible nav + aria-current="page" markup.' );

/*
 * Query Loop bridge doubles.
 *
 * «نمایش همهٔ انتشارها» hands the listing to `core/query`. The shelf builds
 * real block markup and runs it through `do_blocks()`, so the double records
 * the markup it was handed instead of rendering blocks. Declared conditionally
 * so earlier suites (which rely on `do_blocks()` being absent) are unaffected.
 */
if ( ! function_exists( 'do_blocks' ) ) {
	/**
	 * Record the block markup a shelf hands to the block renderer.
	 *
	 * @param string $content Serialized block markup.
	 */
	function do_blocks( string $content ): string {
		$GLOBALS['mw_test_do_blocks'][] = $content;

		return '<div class="wp-block-query mw-release-shelf__loop"><ul class="mw-release-shelf__items"></ul></div>';
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	/**
	 * Drop a callback previously registered through `add_filter()`.
	 *
	 * @param string   $hook     Filter tag.
	 * @param callable $callback Registered callback (matched by equality).
	 * @param int      $priority Priority the callback was added with.
	 */
	function remove_filter( string $hook, callable $callback, int $priority = 10 ): bool {
		unset( $priority );
		$kept = array();
		foreach ( $GLOBALS['mw_test_action_callbacks'][ $hook ] ?? array() as $registered ) {
			if ( $registered !== $callback ) {
				$kept[] = $registered;
			}
		}
		$GLOBALS['mw_test_action_callbacks'][ $hook ] = $kept;

		return true;
	}
}

// --- ShelfAndSliderRenderer Settings, Display Styles, Filter Tabs & Slider Image Shape Suite ---
$mw_shelf_renderer = $mw_theme_kernel->shelves();
$mw_parsed_tabs    = $mw_shelf_renderer->parse_filter_tabs( "امروز|today\nهفته|week\nماه|month\nآلبوم|taxonomy:mw_release_type:album\nهمه|all" );
mw_assert_same( 5, count( $mw_parsed_tabs ), 'parse_filter_tabs must parse all 5 lines cleanly.' );
mw_assert_same( 'today', $mw_parsed_tabs[0]['period'], 'First filter tab period must be today.' );
mw_assert_same( 'week', $mw_parsed_tabs[1]['period'], 'Second filter tab period must be week.' );
mw_assert_same( 'month', $mw_parsed_tabs[2]['period'], 'Third filter tab period must be month.' );
mw_assert_same( 'album', $mw_parsed_tabs[3]['termSlug'], 'Fourth filter tab taxonomy term must be album.' );
mw_assert_same( true, $mw_parsed_tabs[4]['resetAll'], 'Fifth filter tab must flag resetAll=true.' );
$mw_period_query = $mw_shelf_renderer->release_query_args( array( 'datePeriod' => 'week' ), 8 );
mw_assert_same( '1 week ago', $mw_period_query['date_query'][0]['after'] ?? '', 'release_query_args must apply date_query time window for datePeriod=week.' );

$GLOBALS['mw_test_types'][1]    = 'mw_release';
$GLOBALS['mw_test_statuses'][1] = 'publish';
$GLOBALS['mw_test_titles'][1]   = 'آلبوم آزمایشی شبانه';
$mw_feature_html = $mw_shelf_renderer->render_release_shelf(
	array(
		'eyebrow'         => 'ویژه',
		'title'           => 'منتخب سردبیر',
		'asideTitle'      => 'لیست پخش ویژه',
		'layout'          => 'feature',
		'styleVariant'    => 'vinyl',
		'overlay'         => 72,
		'sideColumnWidth' => 460,
		'heroTitleColor'  => '#ffcc00',
		'heroTitleSize'   => 34,
		'heroTextColor'   => '#e5e7eb',
		'filterTabs'      => "امروز|today\nهفته|week\nهمه|all",
		'itemsToShow'     => 4,
	)
);
mw_assert_same( true, false !== strpos( $mw_feature_html, 'mw-release-shelf--feature' ) && false !== strpos( $mw_feature_html, 'mw-release-shelf--vinyl' ), 'Shelf must preserve layout=feature alongside styleVariant=vinyl without conflict.' );
mw_assert_same( true, false !== strpos( $mw_feature_html, '--mw-feature-overlay:0.72' ), 'Feature shelf must emit --mw-feature-overlay inline CSS custom property from overlay setting.' );
mw_assert_same( true, false !== strpos( $mw_feature_html, '--mw-feature-side-width:460px' ), 'Feature shelf must emit --mw-feature-side-width inline CSS custom property from sideColumnWidth setting.' );
mw_assert_same( true, false !== strpos( $mw_feature_html, 'لیست پخش ویژه' ), 'Feature shelf must render asideTitle heading inside the side column.' );
mw_assert_same( true, false !== strpos( $mw_feature_html, 'color:#ffcc00;font-size:34px;' ) && false !== strpos( $mw_feature_html, '--mw-feature-text-color:#e5e7eb;' ), 'Feature shelf must emit heroTitleColor, heroTitleSize, and heroTextColor CSS styles.' );
mw_assert_same( true, false !== strpos( $mw_feature_html, 'data-mw-filter-tab="today"' ) && false !== strpos( $mw_feature_html, 'data-mw-filter-tab="week"' ), 'Filter tabs must render interactive data-mw-filter-tab attributes for smooth switching.' );

$mw_hero_slider_html = $mw_shelf_renderer->render_release_shelf(
	array(
		'layout'         => 'slider',
		'styleVariant'   => 'editorial',
		'overlay'        => 68,
		'heroTitleColor' => '#ffcc00',
		'heroTitleSize'  => 36,
		'heroTextColor'  => '#e5e7eb',
		'filterTabs'     => "امروز|today\nهمه|all",
		'itemsToShow'    => 4,
	)
);
mw_assert_same( true, false !== strpos( $mw_hero_slider_html, 'mw-release-shelf--slider' ) && false !== strpos( $mw_hero_slider_html, 'mw-release-shelf--editorial' ) && false !== strpos( $mw_hero_slider_html, '--mw-feature-overlay:0.68' ) && false !== strpos( $mw_hero_slider_html, 'color:#ffcc00;font-size:36px;' ) && false !== strpos( $mw_hero_slider_html, 'data-mw-filter-tab="today"' ), 'Hero slider layout must preserve styleVariant, overlay, hero typography colors, and interactive filter tabs.' );


/*
 * Releases pagination: «نمایش همهٔ انتشارها» delegates to a real Query Loop and
 * WordPress' own pager, while keeping the shelf's section shell, layout, style
 * variant and card settings — switching the toggle must not restyle the shelf.
 */
$GLOBALS['mw_test_do_blocks'] = array();
$mw_loop_shelf               = $mw_shelf_renderer->render_release_shelf(
	array(
		'title'        => 'همهٔ انتشارها',
		'layout'       => 'grid',
		'columns'      => 4,
		'showAll'      => true,
		'taxonomy'     => 'mw_genre',
		'termSlug'     => 'pop',
		'perPage'      => 12,
	)
);
mw_assert_same( 1, count( $GLOBALS['mw_test_do_blocks'] ), 'A show-all shelf must hand exactly one Query Loop to the block renderer.' );
$mw_loop_markup = $GLOBALS['mw_test_do_blocks'][0];
foreach ( array( '<!-- wp:query ', '<!-- wp:post-template ', '<!-- wp:music-wave/release-card', '<!-- wp:query-no-results -->', '<!-- wp:query-pagination ' ) as $mw_loop_token ) {
	mw_assert_same( true, false !== strpos( $mw_loop_markup, $mw_loop_token ), 'The show-all shelf must compose the standard Query Loop (' . $mw_loop_token . ').' );
}
mw_assert_same( false, false !== strpos( $mw_loop_markup, 'mw-release-card' ), 'The Query Loop must render the shelf card block, not the archive card markup.' );
mw_assert_same( true, false !== strpos( $mw_loop_markup, '"perPage":12' ), 'The Query Loop must default to 12 releases per page.' );
mw_assert_same( true, false !== strpos( $mw_loop_markup, '"columnCount":4' ), 'The Query Loop post template must carry the author column count.' );
mw_assert_same( true, false !== strpos( $mw_loop_markup, '"queryId":' ), 'The Query Loop must carry an explicit queryId so the shelf can scope its query vars.' );
mw_assert_same( true, false !== strpos( $mw_loop_markup, '"className":"mw-release-shelf__items"' ), 'The Query Loop post template must reuse the shelf items class so layout CSS applies.' );
mw_assert_same( true, false !== strpos( $mw_loop_shelf, 'mw-release-shelf--grid mw-release-shelf--columns-4 mw-release-shelf--loop' ) && false !== strpos( $mw_loop_shelf, 'همهٔ انتشارها' ), 'The show-all shelf must keep the section chrome and layout classes.' );
mw_assert_same( array(), $GLOBALS['mw_test_action_callbacks']['query_loop_block_query_vars'] ?? array(), 'The shelf must remove its Query Loop filter once the loop has rendered.' );

/*
 * «چیدمان و سبک نمایش» must survive the toggle: the loop inherits the style
 * variant, the column count and every card setting.
 */
$GLOBALS['mw_test_do_blocks'] = array();
$mw_loop_styled_shelf        = $mw_shelf_renderer->render_release_shelf(
	array(
		'layout'       => 'grid',
		'columns'      => 5,
		'showAll'      => true,
		'styleVariant' => 'vinyl',
		'imageShape'   => 'circle',
		'showDate'     => true,
		'showArtist'   => false,
		'actionLabel'  => 'شنیدن',
	)
);
$mw_loop_styled_markup = (string) ( $GLOBALS['mw_test_do_blocks'][0] ?? '' );
preg_match( '/<!-- wp:music-wave\/release-card (\{.*?\}) \/-->/', $mw_loop_styled_markup, $mw_card_comment );
$mw_card_attributes = json_decode( (string) ( $mw_card_comment[1] ?? '{}' ), true );
mw_assert_same( true, is_array( $mw_card_attributes ), 'The loop card block must serialize readable attributes.' );
foreach ( array( 'imageShape' => 'circle', 'showDate' => true, 'showArtist' => false, 'actionLabel' => 'شنیدن' ) as $mw_card_key => $mw_card_value ) {
	mw_assert_same( $mw_card_value, $mw_card_attributes[ $mw_card_key ] ?? null, 'The show-all loop card must receive the shelf card setting ' . $mw_card_key . '.' );
}
mw_assert_same( true, false !== strpos( $mw_loop_styled_shelf, 'mw-release-shelf--vinyl' ), 'The show-all shelf must keep the selected display style.' );
mw_assert_same( true, false !== strpos( $mw_loop_styled_shelf, 'mw-release-shelf--columns-5' ) && false !== strpos( $mw_loop_styled_markup, '"columnCount":5' ), '«ستون‌های دسکتاپ» must drive both the section and the loop grid.' );

$GLOBALS['mw_test_do_blocks'] = array();
$mw_loop_paged_shelf         = $mw_shelf_renderer->render_release_shelf(
	array(
		'layout'  => 'list',
		'showAll' => true,
		'perPage' => 5,
	)
);
mw_assert_same( true, false !== strpos( $GLOBALS['mw_test_do_blocks'][0], '"perPage":5' ), '«انتشار در هر صفحه» must drive the Query Loop page size.' );
mw_assert_same( true, false !== strpos( $GLOBALS['mw_test_do_blocks'][0], '"columnCount":1' ), 'The compact list layout must render the loop as one column.' );
mw_assert_same( true, false !== strpos( $mw_loop_paged_shelf, 'mw-release-shelf--list mw-release-shelf--columns-' ), 'The compact list show-all shelf must keep its list layout class.' );

$GLOBALS['mw_test_do_blocks'] = array();
$mw_loop_bento_shelf         = $mw_shelf_renderer->render_release_shelf(
	array(
		'layout'  => 'bento',
		'showAll' => true,
		'columns' => 3,
	)
);
mw_assert_same( true, false !== strpos( $mw_loop_bento_shelf, 'mw-release-shelf--bento' ) && false !== strpos( $mw_loop_bento_shelf, 'mw-release-shelf--grid' ), 'The bento show-all shelf must keep both of its layout classes.' );

// Curated layouts stay bounded: a rail never loads every release, and it never
// hands its preview to the Query Loop.
$GLOBALS['mw_test_do_blocks'] = array();
$mw_rail_shelf               = $mw_shelf_renderer->render_release_shelf(
	array(
		'layout'      => 'scroll',
		'showAll'     => true,
		'itemsToShow' => 7,
		'columns'     => 5,
	)
);
mw_assert_same( 0, count( $GLOBALS['mw_test_do_blocks'] ), 'The horizontal shelf keeps its own bounded rail instead of paginating.' );
mw_assert_same( true, false !== strpos( $mw_rail_shelf, 'mw-release-shelf--scroll mw-release-shelf--columns-5' ), 'The horizontal shelf must keep the author column count in its section classes.' );
$mw_rail_query = $mw_shelf_renderer->release_query_args( array( 'showAll' => true ), min( 24, max( 1, 7 ) ) );
mw_assert_same( 7, $mw_rail_query['posts_per_page'], 'A rail must never widen «نمایش همه» into an unbounded `posts_per_page` query.' );

/*
 * «ستون‌های دسکتاپ» is the only control that sizes cards in a horizontal shelf,
 * and «تعداد انتشارهای اسلایدر» is the only control that sets the slide count.
 * Both rails must therefore read their card width from the column count.
 */
$mw_shelf_css  = (string) file_get_contents( dirname( __DIR__ ) . '/musicwave/assets/css/components/shelf.css' );
$mw_slider_css = (string) file_get_contents( dirname( __DIR__ ) . '/musicwave/assets/css/components/slider.css' );
mw_assert_same( true, false !== strpos( $mw_shelf_css, '.mw-release-shelf--scroll .mw-release-shelf__items' ) && false !== strpos( $mw_shelf_css, 'grid-auto-columns: minmax(0, calc((100% - (var(--mw-shelf-gap) * (var(--mw-shelf-columns) - 1))) / var(--mw-shelf-columns)));' ), 'The horizontal shelf must size its cards from --mw-shelf-columns.' );
mw_assert_same( false, 1 === preg_match( '/\.mw-release-shelf--scroll \.mw-release-shelf__items[^}]*minmax\(\s*clamp\(/', $mw_shelf_css ), 'The horizontal shelf must not pin a hard card-width floor that ignores the column count.' );
foreach ( array( 2, 3, 4, 5, 6 ) as $mw_shelf_column_step ) {
	mw_assert_same( true, false !== strpos( $mw_shelf_css, '.mw-release-shelf--columns-' . $mw_shelf_column_step ) && false !== strpos( $mw_slider_css, '.mw-release-slider--columns-' . $mw_shelf_column_step ), 'Both rails must accept a ' . $mw_shelf_column_step . '-column card size.' );
}
mw_assert_same( true, false !== strpos( $mw_slider_css, 'var(--mw-slider-columns)' ) && false !== strpos( $mw_slider_css, '.mw-release-slider--columns-6 { --mw-slider-columns: 6; }' ), 'The slider track must size its slides from --mw-slider-columns.' );

$mw_scroll_shelf = $mw_shelf_renderer->render_release_shelf(
	array(
		'layout'      => 'scroll',
		'columns'     => 6,
		'itemsToShow' => 9,
	)
);
mw_assert_same( true, false !== strpos( $mw_scroll_shelf, 'mw-release-shelf--scroll' ) && false !== strpos( $mw_scroll_shelf, 'mw-release-shelf--columns-6' ), 'A six-column horizontal shelf must publish its column count to the rail CSS.' );

$mw_slider_circle_html = $mw_shelf_renderer->render_release_slider(
	array(
		'eyebrow'      => 'هنرمندان و تک‌آهنگ‌ها',
		'title'        => 'اسلایدر دایره‌ای',
		'imageShape'   => 'circle',
		'styleVariant' => 'minimal',
		'filterTabs'   => "هفته|week\nهمه|all",
		'itemsToShow'  => 4,
	)
);
mw_assert_same( true, false !== strpos( $mw_slider_circle_html, 'mw-release-slider--shape-circle' ) && false !== strpos( $mw_slider_circle_html, 'mw-release-slider__art--circle' ), 'Release slider must emit wrapper and card circle shape classes for imageShape=circle.' );
mw_assert_same( true, false !== strpos( $mw_slider_circle_html, 'mw-release-slider--minimal' ), 'Release slider must emit style variation modifier cleanly.' );

// ---------------------------------------------------------------------------
// Comprehensive Regression Suite:
// 1. Unified ActionButtonStyle & Custom Icon Support (Library Key / CSS Class / Sanitized Inline SVG)
// 2. Preview Player (`icon` / `button` / `player`) + Automatic Editor Previews
// 3. Responsive Multi-Viewport `music-wave/header-search` + Header Parts Audit
// 4. Unified `music-wave/artist-profile` 1:1 Inspector & Avatar Fallback Parity
// 5. Responsive `music-wave/collection-list` 1:1 Grid Alignment & Track Actions
// ---------------------------------------------------------------------------
foreach ( array( 'play', 'play-filled', 'headphones', 'download', 'share', 'shuffle', 'heart', 'playlist-add', 'queue-add' ) as $mw_icon_key ) {
	$mw_svg = ManaCore\MusicWave\Core\Blocks\Concerns\ActionButtonStyle::icon_svg( $mw_icon_key, 18 );
	mw_assert_same( true, false !== strpos( $mw_svg, '<svg ' ) && false !== strpos( $mw_svg, 'width="18"' ), 'Every registered ActionButtonStyle icon must render valid inline SVG markup: ' . $mw_icon_key );
}
mw_assert_same(
	true,
	false !== strpos( ManaCore\MusicWave\Core\Blocks\Concerns\ActionButtonStyle::render_icon( array( 'icon' => 'dashicons dashicons-controls-play' ), 'play', 18 ), 'dashicons-controls-play' )
		&& false === strpos( ManaCore\MusicWave\Core\Blocks\Concerns\ActionButtonStyle::render_icon( array( 'icon' => '<svg viewBox="0 0 24 24" onload="alert(1)"><path d="M8 5v14l11-7z"/></svg>' ), 'play', 18 ), 'onload' ),
	'ActionButtonStyle::render_icon() must render safe CSS icon classes and sanitize inline SVG markup.'
);
$mw_btn_cls = ManaCore\MusicWave\Core\Blocks\Concerns\ActionButtonStyle::button_classes(
	array(
		'style'   => 'icon',
		'variant' => 'accent',
		'size'    => 'lg',
		'shape'   => 'pill',
	),
	'mw-preview-button'
);
mw_assert_same(
	true,
	false !== strpos( $mw_btn_cls, 'mw-action-button--icon' )
		&& false !== strpos( $mw_btn_cls, 'mw-action-button--variant-accent' )
		&& false !== strpos( $mw_btn_cls, 'mw-action-button--size-lg' )
		&& false !== strpos( $mw_btn_cls, 'mw-action-button--shape-pill' ),
	'ActionButtonStyle::button_classes() must compose mode, variant, size, and shape modifiers deterministically.'
);

// Header Search responsive SSR + header parts verification
$mw_chrome_renderer    = $mw_theme_kernel->chrome();
$mw_header_search_html = $mw_chrome_renderer->render_header_search(
	array(
		'desktopMode'        => 'bar',
		'tabletMode'         => 'compact',
		'mobileMode'         => 'popup',
		'enableShortcut'     => true,
		'showScopeTabs'      => true,
		'showRecentSearches' => true,
		'showFooterHints'    => true,
	)
);
mw_assert_same(
	true,
	false !== strpos( $mw_header_search_html, 'data-desktop-mode="bar"' )
		&& false !== strpos( $mw_header_search_html, 'data-tablet-mode="compact"' )
		&& false !== strpos( $mw_header_search_html, 'data-mobile-mode="popup"' )
		&& false !== strpos( $mw_header_search_html, '<button type="submit" class="mw-header-search-block__field-icon"' )
		&& 2 === substr_count( $mw_header_search_html, '<button type="submit" class="mw-header-search-block__field-icon"' )
		&& false === strpos( $mw_header_search_html, 'mw-header-search--shape-' )
		&& false === strpos( $mw_header_search_html, 'mw-header-search--size-' )
		&& false === strpos( $mw_header_search_html, 'mw-header-search--trigger-' )
		&& false !== strpos( $mw_header_search_html, 'mw-header-search-block__popup' )
		&& false !== strpos( $mw_header_search_html, 'mw-header-search-block__toggle' )
		&& false !== strpos( $mw_header_search_html, 'mw-header-search-block__scopes' ),
	'Header search must emit clickable submit field icons in both inline & popup forms without bloated visual modifier classes.'
);

foreach ( glob( dirname( __DIR__ ) . '/musicwave/parts/header*.html' ) ?: array() as $mw_header_part ) {
	$mw_part_contents = (string) file_get_contents( $mw_header_part );
	mw_assert_same( true, false !== strpos( $mw_part_contents, 'wp:music-wave/header-search' ), 'Every header template part must use wp:music-wave/header-search: ' . basename( $mw_header_part ) );
	mw_assert_same( false, strpos( $mw_part_contents, 'wp:search ' ), 'Legacy default wp:search must not remain in header template part: ' . basename( $mw_header_part ) );
	mw_assert_same( false, strpos( $mw_part_contents, 'FLAC بدون افت' ), 'Removed FLAC badge string must never reappear in header template part: ' . basename( $mw_header_part ) );
}

// Automatic Site Editor realistic previews (REST_REQUEST + context=edit without manual releaseId)
// Executed after frontend sub-suites so frontend empty-suppression tests run with REST_REQUEST undefined.
foreach ( array( 'music-wave-core/src', 'music-wave-vip/src', 'musicwave/src' ) as $mw_src_tree ) {
	$mw_dir_iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/' . $mw_src_tree, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	mw_assert_same( true, is_file( dirname( __DIR__ ) . '/' . $mw_src_tree . '/index.php' ), $mw_src_tree . ' root must contain an index.php guard.' );
	foreach ( $mw_dir_iterator as $mw_entry ) {
		if ( $mw_entry->isDir() ) {
			mw_assert_same( true, is_file( $mw_entry->getPathname() . '/index.php' ), 'Directory must contain an ABSPATH index.php guard: ' . $mw_entry->getPathname() );
		}
	}
}

require __DIR__ . '/template-integrity.php';
require __DIR__ . '/vip-playlists-users-test.php';
require __DIR__ . '/vip-access-delivery-suite.php';
require __DIR__ . '/final-audit-seo-cwv-a11y-suite.php';
require __DIR__ . '/block-architecture-refactor-test.php';

if ( ! defined( 'REST_REQUEST' ) ) {
	define( 'REST_REQUEST', true );
}
$mw_prev_get        = $_GET;
$_GET['context']    = 'edit';
$mw_editor_ctx_util = new ManaCore\MusicWave\Core\Blocks\Release\ReleaseBlockContext( null, $relation_repository );
$mw_col_renderer    = new ManaCore\MusicWave\Core\Blocks\Release\CollectionAndDownloadRenderer( $mw_editor_ctx_util );
$mw_preview_icon_ui = $mw_col_renderer->render_preview_player(
	array(
		'style'   => 'icon',
		'variant' => 'accent',
		'size'    => 'lg',
		'shape'   => 'pill',
		'icon'    => 'headphones',
	),
	null
);
mw_assert_same(
	true,
	false !== strpos( $mw_preview_icon_ui, 'mw-action-button--icon' )
		&& false !== strpos( $mw_preview_icon_ui, 'mw-action-button--shape-pill' )
		&& false !== strpos( $mw_preview_icon_ui, 'mw-btn-svg--headphones' )
		&& false === strpos( $mw_preview_icon_ui, 'mw-preview-player--card' ),
	'Preview player in icon mode must render a clean circular action button with custom icon and zero box chrome.'
);

/*
 * The block-style (Styles panel) selection must win over the `variant`
 * attribute default that block.json pre-fills, otherwise the "minimal, no
 * background" (ghost) style is unreachable from the Site Editor.
 */
$mw_preview_ghost_ui = $mw_col_renderer->render_preview_player(
	array(
		'variant'   => 'accent',
		'className' => 'is-style-ghost',
	),
	null
);
mw_assert_same(
	true,
	false !== strpos( $mw_preview_ghost_ui, 'mw-action-button--ghost' )
		&& false === strpos( $mw_preview_ghost_ui, 'mw-action-button--accent' ),
	'Preview player must honour an is-style-ghost block style over a default accent variant so the minimal no-background mode renders.'
);

$mw_col_auto_html = $mw_col_renderer->render_collection_list(
	array(
		'density'      => 'comfortable',
		'actionStyle'  => 'icon',
		'showHeader'   => true,
		'showArtist'   => true,
		'showBpm'      => true,
		'showQuality'  => true,
		'showDuration' => true,
		'showPreview'  => true,
		'showDownload' => true,
	),
	null
);
$mw_col_auto_rows = substr_count( $mw_col_auto_html, 'mw-collection-list__row' );
mw_assert_same(
	true,
	substr_count( $mw_col_auto_html, 'mw-collection-list__col ' ) === 7
		&& $mw_col_auto_rows >= 1
		&& substr_count( $mw_col_auto_html, 'mw-collection-list__cell ' ) === $mw_col_auto_rows * 7,
	'Collection list auto-resolved editor preview must align real album tracks with strict 1:1 grid parity.'
);

$mw_single_preview_post = wp_insert_post(
	array(
		'post_type'   => 'mw_release',
		'post_status' => 'publish',
		'post_title'  => 'تک‌آهنگ آزمایشی پیش‌نمایش',
	)
);
$mw_col_sample_html = $mw_col_renderer->render_collection_list(
	array(
		'releaseId'    => $mw_single_preview_post,
		'density'      => 'comfortable',
		'actionStyle'  => 'icon',
		'showHeader'   => true,
		'showArtist'   => true,
		'showBpm'      => true,
		'showQuality'  => true,
		'showDuration' => true,
		'showPreview'  => true,
		'showDownload' => true,
	),
	null
);
$mw_col_sample_rows = substr_count( $mw_col_sample_html, 'mw-collection-list__row' );
mw_assert_same(
	true,
	substr_count( $mw_col_sample_html, 'mw-collection-list__col ' ) === 7
		&& $mw_col_sample_rows >= 4
		&& substr_count( $mw_col_sample_html, 'mw-collection-list__cell ' ) === $mw_col_sample_rows * 7,
	'Collection list real-catalog fallback table header columns (7) and row cells (rows x 7) must align with strict 1:1 grid parity.'
);

$mw_artist_block_instance = new ManaCore\MusicWave\Core\Blocks\ArtistProfileBlock();
$mw_artist_spotlight_html = $mw_artist_block_instance->render(
	array(
		'layout'            => 'spotlight',
		'imageShape'        => 'square',
		'showBio'           => true,
		'showLibraryButton' => true,
		'showReleaseCount'  => true,
		'className'         => 'is-style-glass',
	)
);
mw_assert_same(
	true,
	false !== strpos( $mw_artist_spotlight_html, 'mw-artist-profile--spotlight' )
		&& false !== strpos( $mw_artist_spotlight_html, 'mw-artist-profile--shape-square' )
		&& false !== strpos( $mw_artist_spotlight_html, 'mw-artist-profile--glass' )
		&& false !== strpos( $mw_artist_spotlight_html, 'mw-library-button__follow-svg' )
		&& false === strpos( $mw_artist_spotlight_html, 'mw-library-button__heart-svg' ),
	'Artist profile block must honor layout, imageShape, style variation classes, and dedicated Follow icon (zero heart icon).'
);

// 7. Comprehensive 6-Part Live QA Verification (Collection List Play De-Dup, Quality Select, Custom Icons, Unified Download Panel, Release Actions Parity, & Dynamic Sample Switching)
$mw_col_no_dup_play = $mw_col_renderer->render_collection_list(
	array(
		'releaseId'           => $mw_single_preview_post,
		'showPreview'         => true,
		'showDownload'        => true,
		'showDownloadQuality' => true,
		'playIcon'            => 'waveform',
		'downloadIcon'        => 'headphones',
		'actionsVisibility'   => 'hover',
	),
	null
);
mw_assert_same(
	true,
	false === strpos( $mw_col_no_dup_play, 'mw-collection-list__lead-play' )
		&& false !== strpos( $mw_col_no_dup_play, 'mw-collection-list__lead-slot' )
		&& false !== strpos( $mw_col_no_dup_play, 'mw-collection-list--vis-hover' )
		&& false !== strpos( $mw_col_no_dup_play, 'mw-collection-list__quality-select' )
		&& false !== strpos( $mw_col_no_dup_play, 'mw-btn-svg--waveform' )
		&& false !== strpos( $mw_col_no_dup_play, 'mw-btn-svg--headphones' )
		&& false === strpos( $mw_col_no_dup_play, 'mw-collection-list__action--queue' )
		&& false === strpos( $mw_col_no_dup_play, 'mw-collection-list__action--library' )
		&& false === strpos( $mw_col_no_dup_play, 'mw-collection-list__queue-form' ),
	'Collection list rows must render exactly the play + download actions (no duplicate lead play button, no per-row queue/library buttons) plus the row quality selector.'
);

$mw_col_lead_only = $mw_col_renderer->render_collection_list(
	array(
		'releaseId'   => $mw_single_preview_post,
		'showPreview' => false,
	),
	null
);
mw_assert_same(
	true,
	false !== strpos( $mw_col_lead_only, 'mw-collection-list__lead-play--hover' ),
	'Collection list with trailing play button disabled MUST render clean hover lead play affordance in track number column.'
);

$mw_editor_download_box = $mw_col_renderer->render_download_button(
	array(
		'compact'     => false,
		'showQuality' => true,
		'showStream'  => true,
	),
	null
);
$mw_editor_download_inline = $mw_col_renderer->render_download_button(
	array(
		'compact' => true,
		'style'   => 'icon',
		'variant' => 'surface',
	),
	null
);
mw_assert_same(
	true,
	false !== strpos( $mw_editor_download_box, 'mw-download-action__heading' )
		&& false !== strpos( $mw_editor_download_box, 'mw-download-action__table-header' )
		&& false !== strpos( $mw_editor_download_box, 'mw-download-file-list' )
		&& false !== strpos( $mw_editor_download_box, 'mw-download-file-row' )
		&& false !== strpos( $mw_editor_download_box, 'mw-download-quality' )
		&& false !== strpos( $mw_editor_download_box, 'mw-download-action__security' )
		&& false !== strpos( $mw_editor_download_inline, 'mw-download-action--inline' )
		&& false !== strpos( $mw_editor_download_inline, 'mw-action-button--icon' ),
	'Download button in Editor must render full unified panel box when compact=false and crisp icon action button when compact=true.'
);

$mw_picker_editor_html = ( new ManaCore\MusicWave\Core\Blocks\PlaylistBlocks(
	new ManaCore\MusicWave\Core\Playlists\PlaylistRepository(),
	new ManaCore\MusicWave\Core\Playlists\PlaylistFormHandler( new ManaCore\MusicWave\Core\Playlists\PlaylistRepository() )
) )->render_picker(
	array(
		'style'   => 'icon',
		'variant' => 'surface',
	)
);
mw_assert_same(
	true,
	false === strpos( $mw_picker_editor_html, 'mw-playlist-picker__fallback' )
		&& false !== strpos( $mw_picker_editor_html, 'mw-action-button--icon' ),
	'Playlist picker in Editor context must render single icon button without unstyled noscript fallback form breaking .mw-release-actions flex row.'
);

$mw_public_playlists_editor = ( new ManaCore\MusicWave\Core\Blocks\PlaylistBlocks(
	new ManaCore\MusicWave\Core\Playlists\PlaylistRepository(),
	new ManaCore\MusicWave\Core\Playlists\PlaylistFormHandler( new ManaCore\MusicWave\Core\Playlists\PlaylistRepository() )
) )->render_public( array() );
mw_assert_same(
	true,
	false !== strpos( $mw_public_playlists_editor, 'mw-public-playlists' )
		&& false !== strpos( $mw_public_playlists_editor, 'mw-public-playlists__card' ),
	'Public playlists block in Editor must render real catalog-projected cards through single public_card_markup() pipeline.'
);

$mw_target_search_title    = (string) get_the_title( 1 );
$_GET['mw_preview_query']  = $mw_target_search_title;
$mw_search_sample_switched = ( new ManaCore\MusicWave\Core\Blocks\SearchUnifiedBlock() )->render( array() );
mw_assert_same(
	true,
	false !== strpos( $mw_search_sample_switched, $mw_target_search_title ),
	'Unified search block in Editor must dynamically reflect mw_preview_query sample parameter.'
);
$_GET = $mw_prev_get;

/*
 * Page-builder bridge: [musicwave_block] renders only the project namespace and
 * forwards just the attributes a block declares.
 */
$mw_builder_shortcode = new ManaCore\MusicWave\Core\Support\BlockShortcode(
	static function ( string $name ): array {
		unset( $name );
		return array( 'layout', 'itemsToShow' );
	}
);
$GLOBALS['mw_test_rendered_blocks'] = array();
$mw_bridge_html                     = $mw_builder_shortcode->shortcode(
	array(
		'name'       => 'music-wave/release-shelf',
		'attributes' => '{"layout":"grid","itemsToShow":6,"undeclared":"nope"}',
	)
);
mw_assert_same( 1, count( $GLOBALS['mw_test_rendered_blocks'] ), 'The builder shortcode must render exactly one block.' );
mw_assert_same(
	array(
		'layout'      => 'grid',
		'itemsToShow' => 6,
	),
	$GLOBALS['mw_test_rendered_blocks'][0]['attrs'],
	'The builder shortcode must forward declared attributes and drop undeclared keys.'
);
mw_assert_same(
	true,
	false !== strpos( $mw_bridge_html, 'data-block="music-wave/release-shelf"' ),
	'The builder shortcode must return the rendered block markup.'
);

$GLOBALS['mw_test_rendered_blocks'] = array();
mw_assert_same( '', $mw_builder_shortcode->shortcode( array( 'name' => 'core/paragraph' ) ), 'The builder shortcode must reject blocks outside the project namespace.' );
mw_assert_same( array(), $GLOBALS['mw_test_rendered_blocks'], 'A rejected block must never reach render_block().' );

mw_assert_same(
	'',
	$mw_builder_shortcode->shortcode(
		array(
			'name'       => 'music-wave/release-shelf',
			'attributes' => '{"layout":',
		)
	),
	'The builder shortcode must reject malformed attribute JSON instead of rendering partial data.'
);

$GLOBALS['mw_test_rendered_blocks'] = array();
$mw_builder_shortcode->shortcode( array( 'name' => 'music-wave/release-shelf' ) );
mw_assert_same(
	array(),
	$GLOBALS['mw_test_rendered_blocks'][0]['attrs'],
	'Omitting attributes must render the block with its defaults.'
);

echo 'MusicWave domain smoke tests passed.' . PHP_EOL;
