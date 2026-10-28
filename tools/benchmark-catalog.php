<?php
/**
 * Large-dataset query and cache benchmark for MusicWave list surfaces.
 *
 * Runs inside a real WordPress install (wp-env, staging, or production clone)
 * and measures database queries plus wall time for the surfaces that grow with
 * catalog and account size. Budgets are enforced, so this doubles as a
 * regression gate rather than a report (PROJECT_PLAN.md Stage 5 deliverable 7).
 *
 * Usage:
 *   wp eval-file tools/benchmark-catalog.php
 *   wp eval-file tools/benchmark-catalog.php seed=500
 *   wp eval-file tools/benchmark-catalog.php seed=500 cleanup=1
 *
 * `seed=<n>` creates n throwaway releases (plus terms, a 500-item library, and
 * a 500-item playlist) before measuring. `cleanup=1` deletes them afterwards.
 *
 * `wp eval-file` evaluates this file inside a method (`eval( '?>' . $code )`),
 * so a `declare(strict_types=1)` statement would be a fatal error here — the
 * script relies on explicit casts instead.
 *
 * @package ManaCore\MusicWave\Core
 */

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Discovery\CatalogSearch;
use ManaCore\MusicWave\Core\Discovery\Recommendations;
use ManaCore\MusicWave\Core\Library\LibraryCatalog;
use ManaCore\MusicWave\Core\Library\LibraryRepository;
use ManaCore\MusicWave\Core\Playlists\PlaylistRepository;

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this file through WP-CLI: wp eval-file tools/benchmark-catalog.php\n" );
	exit( 1 );
}

/**
 * Per-scenario budgets: maximum database queries and maximum milliseconds.
 *
 * Budgets are intentionally generous for wall time (CI runners are noisy) and
 * strict for query counts, which is where list surfaces actually regress.
 */
const MW_BENCH_BUDGETS = array(
	'catalog_archive'    => array( 40, 1500 ),
	'catalog_facets'     => array( 25, 2500 ),
	'catalog_facets_hot' => array( 2, 200 ),
	'catalog_suggest'    => array( 20, 1500 ),
	'library_page'       => array( 40, 1500 ),
	'playlist_view'      => array( 40, 1500 ),
	'recommendations'    => array( 40, 1500 ),
);

$mw_bench_args = array(
	'seed'    => 0,
	'cleanup' => 0,
);
foreach ( isset( $args ) && is_array( $args ) ? $args : array() as $mw_bench_arg ) {
	$mw_bench_pair = explode( '=', (string) $mw_bench_arg, 2 );
	if ( 2 === count( $mw_bench_pair ) && array_key_exists( $mw_bench_pair[0], $mw_bench_args ) ) {
		$mw_bench_args[ $mw_bench_pair[0] ] = (int) $mw_bench_pair[1];
	}
}

/**
 * Measure one scenario.
 *
 * @param callable $callback Scenario body.
 * @return array{queries: int, ms: float, detail: string}
 */
function mw_bench_measure( callable $callback ): array {
	global $wpdb;

	$queries_before = (int) $wpdb->num_queries;
	$started        = microtime( true );
	$detail         = (string) $callback();

	return array(
		'queries' => (int) $wpdb->num_queries - $queries_before,
		'ms'      => ( microtime( true ) - $started ) * 1000,
		'detail'  => $detail,
	);
}

/**
 * Seed throwaway catalog, library, and playlist data.
 *
 * @return array{release_ids: array<int, int>, user_id: int, playlist_id: int}
 */
function mw_bench_seed( int $count ): array {
	$genres  = array( 'bench-ambient', 'bench-techno', 'bench-jazz', 'bench-rock' );
	$moods   = array( 'bench-calm', 'bench-energetic' );
	$artists = array( 'Bench Artist A', 'Bench Artist B', 'Bench Artist C' );
	$types   = array( 'album', 'track', 'ep' );

	$release_ids = array();
	for ( $index = 0; $index < $count; $index++ ) {
		$release_id = wp_insert_post(
			array(
				'post_type'   => ReleasePostType::KEY,
				'post_status' => 'publish',
				'post_title'  => sprintf( 'Benchmark release %d', $index + 1 ),
				'post_name'   => 'mw-bench-' . ( $index + 1 ),
				'meta_input'  => array(
					'mw_bench'         => '1',
					'mw_release_year'  => (string) ( 2000 + ( $index % 25 ) ),
					'mw_release_date'  => gmdate( 'Y-m-d', time() - ( $index * DAY_IN_SECONDS ) ),
					'mw_access_mode'   => 'public',
				),
			)
		);
		if ( is_wp_error( $release_id ) || 0 === (int) $release_id ) {
			continue;
		}
		$release_id = (int) $release_id;
		wp_set_object_terms( $release_id, array( $genres[ $index % count( $genres ) ] ), 'mw_genre' );
		wp_set_object_terms( $release_id, array( $moods[ $index % count( $moods ) ] ), 'mw_mood' );
		wp_set_object_terms( $release_id, array( $artists[ $index % count( $artists ) ] ), 'mw_artist' );
		wp_set_object_terms( $release_id, array( $types[ $index % count( $types ) ] ), 'mw_release_type' );
		$release_ids[] = $release_id;
	}

	$user = get_user_by( 'login', 'mw_bench_user' );
	if ( false === $user ) {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'mw_bench_user',
				'user_pass'  => wp_generate_password( 24, true, true ),
				'user_email' => 'mw-bench@example.invalid',
				'role'       => 'subscriber',
			)
		);
		$user_id = is_wp_error( $user_id ) ? 0 : (int) $user_id;
	} else {
		$user_id = (int) $user->ID;
	}

	$library     = new LibraryRepository();
	$playlists   = new PlaylistRepository();
	$playlist_id = 0;
	if ( $user_id > 0 ) {
		foreach ( array_slice( $release_ids, 0, LibraryRepository::MAX_ITEMS ) as $release_id ) {
			$library->add( $user_id, LibraryRepository::TYPE_RELEASE, $release_id );
		}
		$playlist_id = $playlists->create( $user_id, 'Benchmark playlist', PlaylistRepository::VISIBILITY_PRIVATE );
		foreach ( array_slice( $release_ids, 0, PlaylistRepository::MAX_ITEMS ) as $release_id ) {
			$playlists->add_item( $user_id, $playlist_id, $release_id );
		}
	}

	return array(
		'release_ids' => $release_ids,
		'user_id'     => $user_id,
		'playlist_id' => $playlist_id,
	);
}

/**
 * Delete seeded benchmark data.
 *
 * @return int Number of deleted releases.
 */
function mw_bench_cleanup(): int {
	$deleted  = 0;
	$user     = get_user_by( 'login', 'mw_bench_user' );
	$releases = get_posts(
		array(
			'post_type'      => ReleasePostType::KEY,
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'meta_key'       => 'mw_bench', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);
	foreach ( is_array( $releases ) ? $releases : array() as $release_id ) {
		wp_delete_post( (int) $release_id, true );
		++$deleted;
	}
	if ( false !== $user && function_exists( 'wp_delete_user' ) ) {
		wp_delete_user( (int) $user->ID );
	}

	return $deleted;
}

/**
 * Clear MusicWave discovery caches so the next scenario measures a cold path.
 *
 * @return void
 */
function mw_bench_flush_caches(): void {
	global $wpdb;

	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_mw_catalog_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_mw_catalog_' ) . '%'
		)
	);
	wp_cache_flush();
}

$mw_bench_seeded = array(
	'release_ids' => array(),
	'user_id'     => 0,
	'playlist_id' => 0,
);
if ( $mw_bench_args['seed'] > 0 ) {
	$mw_bench_seeded = mw_bench_seed( $mw_bench_args['seed'] );
	printf( "Seeded %d releases.%s", count( $mw_bench_seeded['release_ids'] ), PHP_EOL );
}

$mw_bench_user_id     = $mw_bench_seeded['user_id'];
$mw_bench_playlist_id = $mw_bench_seeded['playlist_id'];
if ( $mw_bench_user_id < 1 ) {
	$mw_bench_existing = get_user_by( 'login', 'mw_bench_user' );
	$mw_bench_user_id  = false !== $mw_bench_existing ? (int) $mw_bench_existing->ID : 0;
}

$mw_bench_search    = new CatalogSearch();
$mw_bench_library   = new LibraryCatalog( new LibraryRepository() );
$mw_bench_playlists = new PlaylistRepository();
$mw_bench_results   = array();

$mw_bench_results['catalog_archive'] = mw_bench_measure(
	static function (): string {
		$query = new WP_Query(
			array(
				'post_type'      => ReleasePostType::KEY,
				'post_status'    => 'publish',
				'posts_per_page' => 24,
				'no_found_rows'  => false,
			)
		);

		return sprintf( '%d of %d releases', count( $query->posts ), (int) $query->found_posts );
	}
);

// Cold facet scan (cache cleared) and the cached repeat.
mw_bench_flush_caches();
$mw_bench_results['catalog_facets'] = mw_bench_measure(
	static function () use ( $mw_bench_search ): string {
		$facets = $mw_bench_search->facets( array() );

		return sprintf( '%d matched, approximate=%s', (int) $facets['matched'], $facets['approximate'] ? 'yes' : 'no' );
	}
);
$mw_bench_results['catalog_facets_hot'] = mw_bench_measure(
	static function () use ( $mw_bench_search ): string {
		$facets = $mw_bench_search->facets( array() );

		return sprintf( '%d matched (cached)', (int) $facets['matched'] );
	}
);

$mw_bench_results['catalog_suggest'] = mw_bench_measure(
	static function () use ( $mw_bench_search ): string {
		return sprintf( '%d suggestions', count( $mw_bench_search->suggest( 'Benchmark', 10 ) ) );
	}
);

$mw_bench_results['library_page'] = mw_bench_measure(
	static function () use ( $mw_bench_library, $mw_bench_user_id ): string {
		$page = $mw_bench_library->paged_summaries( $mw_bench_user_id, LibraryCatalog::FILTER_ALL, 24, 1 );

		return sprintf( '%d items on page 1', count( $page['items'] ) );
	}
);

$mw_bench_results['playlist_view'] = mw_bench_measure(
	static function () use ( $mw_bench_playlists, $mw_bench_user_id, $mw_bench_playlist_id ): string {
		$view = $mw_bench_playlists->view( $mw_bench_playlist_id, $mw_bench_user_id );

		return null === $view ? 'no playlist' : sprintf( '%d playlist items', (int) $view['count'] );
	}
);

$mw_bench_results['recommendations'] = mw_bench_measure(
	static function () use ( $mw_bench_user_id ): string {
		return sprintf( '%d recommendations', count( ( new Recommendations() )->recommend( $mw_bench_user_id, 12 ) ) );
	}
);

$mw_bench_failed = 0;
printf( '%-22s %8s %8s %10s %10s  %s%s', 'Scenario', 'queries', 'budget', 'ms', 'budget', 'detail', PHP_EOL );
foreach ( $mw_bench_results as $mw_bench_name => $mw_bench_result ) {
	list( $mw_bench_query_budget, $mw_bench_ms_budget ) = MW_BENCH_BUDGETS[ $mw_bench_name ];
	$mw_bench_ok                                       = $mw_bench_result['queries'] <= $mw_bench_query_budget && $mw_bench_result['ms'] <= $mw_bench_ms_budget;
	$mw_bench_failed                                  += $mw_bench_ok ? 0 : 1;
	printf(
		"%-22s %8d %8d %10.1f %10d  %s %s%s",
		$mw_bench_name,
		$mw_bench_result['queries'],
		$mw_bench_query_budget,
		$mw_bench_result['ms'],
		$mw_bench_ms_budget,
		$mw_bench_ok ? 'PASS' : 'FAIL',
		$mw_bench_result['detail'],
		PHP_EOL
	);
}

if ( $mw_bench_args['cleanup'] > 0 ) {
	printf( "Cleaned up %d seeded releases.%s", mw_bench_cleanup(), PHP_EOL );
}

if ( $mw_bench_failed > 0 ) {
	fwrite( STDERR, sprintf( "%d scenario(s) exceeded their budget.%s", $mw_bench_failed, PHP_EOL ) );
	exit( 1 );
}

echo 'All benchmark scenarios passed their budgets.' . PHP_EOL;
