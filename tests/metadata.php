<?php
/**
 * Offline provider regression tests. Run directly: php tests/metadata.php.
 * The HTTP queue fails on unexpected requests; no external API or keys needed.
 */

declare(strict_types=1);

use ManaCore\MusicWave\Core\Metadata\MetadataQuery;
use ManaCore\MusicWave\Core\Metadata\MetadataResolver;
use ManaCore\MusicWave\Core\Metadata\MetadataResult;
use ManaCore\MusicWave\Core\Metadata\MusicBrainzProvider;
use ManaCore\MusicWave\Core\Metadata\SpotifyProvider;
use ManaCore\MusicWave\Core\Metadata\DiscogsProvider;

const MINUTE_IN_SECONDS = 60;
const HOUR_IN_SECONDS = 3600;
class WP_Error {
	public $code;
	public function __construct( string $code = '', string $message = '' ) { $this->code = $code; }
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function __( string $value, string $domain = '' ): string { return $value; }
function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
function sanitize_key( string $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
function wp_strip_all_tags( string $value ): string { return strip_tags( $value ); }
function wp_kses_post( string $value ): string { return strip_tags( $value ); }
function esc_url_raw( string $url, array $protocols = array( 'http', 'https' ) ): string {
	return in_array( parse_url( $url, PHP_URL_SCHEME ), $protocols, true ) ? $url : '';
}
function home_url( string $path = '' ): string { return 'https://example.test' . $path; }
function trailingslashit( string $value ): string { return rtrim( $value, '/' ) . '/'; }
function wp_json_encode( $value ): string { return json_encode( $value ); }
// WordPress add_query_arg() does NOT URL-encode newly supplied values.
function add_query_arg( array $args, string $url ): string {
	$pairs = array();
	foreach ( $args as $key => $value ) { $pairs[] = $key . '=' . $value; }
	return $url . '?' . implode( '&', $pairs );
}
function get_transient( string $key ) { return $GLOBALS['cache'][ $key ] ?? false; }
function set_transient( string $key, $value, int $ttl ): bool { $GLOBALS['cache'][ $key ] = $value; return true; }
function wp_remote_retrieve_response_code( array $response ): int { return $response['response']['code']; }
function wp_remote_retrieve_body( array $response ): string { return $response['body']; }
function wp_remote_get( string $url, array $args = array() ) {
	$GLOBALS['requests'][] = array( $url, $args );
	if ( empty( $GLOBALS['responses'] ) ) { throw new LogicException( 'Unexpected HTTP request: ' . $url ); }
	return array_shift( $GLOBALS['responses'] );
}
function apply_filters( string $hook, $value ) { return $GLOBALS['budgets'] ?? $value; }
function wp_tempnam( string $name ): string {
	$dir = dirname( __DIR__ ) . '/.staging';
	if ( ! is_dir( $dir ) ) { mkdir( $dir, 0775, true ); }
	return $GLOBALS['cover_tmp'] = tempnam( $dir, 'cover-test-' );
}
function wp_delete_file( string $path ): void { unlink( $path ); }
function wp_safe_remote_get( string $url, array $args ) {
	$response = wp_remote_get( $url, $args );
	if ( ! is_wp_error( $response ) ) { file_put_contents( $args['filename'], substr( $response['body'], 0, $args['limit_response_size'] ) ); }
	return $response;
}
function wp_remote_post( string $url, array $args = array() ) { return wp_remote_get( $url, $args ); }
function fixture( $body, int $status = 200 ): array {
	return array( 'response' => array( 'code' => $status ), 'body' => is_string( $body ) ? $body : json_encode( $body ) );
}
function reset_http( array $responses ): void {
	$GLOBALS['requests'] = array();
	$GLOBALS['responses'] = $responses;
	$GLOBALS['cache'] = array();
}
function same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		fwrite( STDERR, 'FAIL: ' . $message . '\n' . var_export( $actual, true ) . PHP_EOL );
		exit( 1 );
	}
	$GLOBALS['assertions'] = ( $GLOBALS['assertions'] ?? 0 ) + 1;
}
function request_params( int $index = 0 ): array {
	parse_str( (string) parse_url( $GLOBALS['requests'][ $index ][0], PHP_URL_QUERY ), $params );
	return $params;
}

foreach ( array( 'MetadataProvider', 'MetadataEnrichmentProvider', 'MetadataQuery', 'MetadataResult', 'RateLimitException', 'MusicBrainzProvider', 'SpotifyProvider', 'DiscogsProvider', 'MetadataResolver' ) as $class ) {
	require dirname( __DIR__ ) . '/music-wave-core/src/Metadata/' . $class . '.php';
}

$provider = new MusicBrainzProvider();
$resolver = new MetadataResolver( array( $provider ) );
$query = MetadataQuery::from_strings( 'Artist — Song (۲۰۲۴)' );
same( 'Artist', $query->artist, 'Unicode dash separates artist and title.' );
same( 'Song', $query->track, 'Year is removed from the title.' );
same( '2024', $query->year, 'Persian year is normalized.' );
same( '2024', MetadataQuery::from_strings( '', 'Song', '', '', '٢٠٢٤' )->year, 'Arabic digits are accepted.' );
same( '', MetadataQuery::from_strings( '', 'Song', '', '', '20245' )->year, 'Invalid year is not truncated.' );
same( 'AC\\DC', MetadataQuery::from_strings( '', 'AC\\DC' )->track, 'REST values must not be unslashed twice.' );

$recording = array(
	'id' => 'recording-1', 'title' => 'Song', 'length' => 215000,
	'artist-credit' => array( array( 'artist' => array( 'id' => 'artist-1', 'name' => 'Artist' ) ) ),
	'releases' => array( array( 'id' => 'release-1', 'title' => 'Album', 'date' => '2024-01-01' ) ),
);
$search = fixture( array( 'recordings' => array( $recording ) ) );
reset_http( array( $search ) );
$payload = $resolver->search( MetadataQuery::from_strings( '', 'A & B + C', 'Artist' ) );
same( true, $payload['success'], 'Valid provider response produces results.' );
same( 'recording:"A & B + C" AND artistname:"Artist"', request_params()['query'], 'Lucene query survives one URL decode, including ampersand and plus.' );
same( 1, count( $GLOBALS['requests'] ), 'Searching must not fan out to cover requests.' );
same( 215, $payload['results'][0]['duration'], 'Milliseconds normalize to seconds.' );
same( true, $resolver->search( MetadataQuery::from_strings( '', 'A & B + C', 'Artist' ) )['cached'], 'Identical search uses cache.' );
same( 1, count( $GLOBALS['requests'] ), 'Cached searches do not call providers.' );

reset_http( array( $search, $search ) );
$resolver->search( MetadataQuery::from_strings( 'Artist' ) );
same( '(recording:"Artist" OR artist:"Artist")', request_params()['query'], 'Free text can match an artist, not only a track.' );
$resolver->search( MetadataQuery::from_strings( '', 'Artist' ) );
same( 'recording:"Artist"', request_params( 1 )['query'], 'Explicit title is not broadened or served a free-text cached result.' );

reset_http( array( fixture( array( 'releases' => array() ) ) ) );
$provider->search( MetadataQuery::from_strings( '', 'Album', 'Artist', '', '2024', array( 'album' ) ) );
same( 'release:"Album" AND artist:"Artist" AND date:2024*', request_params()['query'], 'Album searches target releases with explicit filters.' );

foreach ( array( new WP_Error(), fixture( '', 403 ), fixture( '', 500 ), fixture( '<html>blocked</html>' ), fixture( array( 'error' => 'bad query' ) ) ) as $failure ) {
	reset_http( array( $failure ) );
	$payload = $resolver->search( MetadataQuery::from_strings( 'Artist' ) );
	same( 'provider_unavailable', $payload['code'], 'Transport, HTTP and malformed responses are never reported as no matches.' );
	same( 'unavailable', $payload['errors']['musicbrainz'], 'Diagnostics contain only safe error classifications.' );
	same( array(), $GLOBALS['cache'], 'Transient provider errors are not cached as empty results.' );
}
foreach ( array( 429, 503 ) as $status ) {
	reset_http( array( fixture( '', $status ) ) );
	$payload = $resolver->search( MetadataQuery::from_strings( 'Artist' ) );
	same( 'rate_limited', $payload['errors']['musicbrainz'], 'Throttling is actionable.' );
}
reset_http( array( fixture( array( 'recordings' => array() ) ) ) );
same( 'no_results', $resolver->search( MetadataQuery::from_strings( 'Missing' ) )['code'], 'A valid empty response remains no_results.' );
reset_http( array() );
same( 'invalid_query', $resolver->search( MetadataQuery::from_strings() )['code'], 'Empty input is rejected before HTTP.' );
same( 0, count( $GLOBALS['requests'] ), 'Invalid input causes no requests.' );

reset_http( array( fixture( '', 403 ), $search ) );
$fallback = new MetadataResolver( array( new DiscogsProvider( 'test-token' ), $provider ) );
$payload = $fallback->search( MetadataQuery::from_strings( 'A & B' ) );
same( true, $payload['success'], 'Fallback continues after an upstream failure.' );
same( array( 'discogs', 'musicbrainz' ), $payload['attempts'], 'Fallback order is preserved.' );
same( 'A & B', request_params()['q'], 'Discogs uses the documented q argument, safely encoded.' );

reset_http( array( fixture( '', 404 ), fixture( array( 'annotation' => 'Album notes' ) ), fixture( '', 404 ), fixture( array() ) ) );
$first = MetadataResult::from_array( array( 'provider' => 'musicbrainz', 'reference_id' => 'same-release', 'title' => 'Track A', 'artist' => 'Artist', 'duration' => 100 ) );
$second = MetadataResult::from_array( array( 'provider' => 'musicbrainz', 'reference_id' => 'same-release', 'title' => 'Track B', 'artist' => 'Artist', 'duration' => 200 ) );
same( 'Album notes', $resolver->enrich( $first )->description, 'Missing cover does not discard metadata.' );
same( 'artist-credits+labels+release-groups+genres+annotation', request_params( 1 )['inc'], 'MusicBrainz include separators remain literal plus signs.' );
$enriched = $resolver->enrich( $second );
same( 'Track B', $enriched->title, 'Detail cache cannot overwrite a different track on the same album.' );
same( 200, $enriched->duration, 'Detail cache preserves track-specific duration.' );
same( 4, count( $GLOBALS['requests'] ), 'Only selected results request artwork and detail.' );

reset_http( array( fixture( array( 'images' => array( array( 'front' => true, 'thumbnails' => array( '500' => 'http://archive.org/cover.jpg' ) ) ) ) ), fixture( array() ) ) );
$enriched = $resolver->enrich( clone $first );
same( 'https://archive.org/cover.jpg', $enriched->cover_url, 'Selected result receives an HTTPS cover.' );

reset_http( array( fixture( array( 'access_token' => 'first-token', 'expires_in' => 3600 ) ), fixture( array( 'tracks' => array( 'items' => array() ) ) ), fixture( array( 'access_token' => 'second-token', 'expires_in' => 3600 ) ), fixture( array( 'tracks' => array( 'items' => array() ) ) ) ) );
( new SpotifyProvider( 'first-client', 'secret' ) )->search( MetadataQuery::from_strings( '', 'A & B' ) );
( new SpotifyProvider( 'second-client', 'secret' ) )->search( MetadataQuery::from_strings( '', 'Song' ) );
same( 4, count( $GLOBALS['requests'] ), 'Spotify credential changes must not reuse the previous application token.' );
same( 'Bearer second-token', $GLOBALS['requests'][3][1]['headers']['Authorization'], 'Second client gets its own token.' );
same( 'track:A & B', request_params( 1 )['q'], 'Spotify title survives encoding.' );

// Bounded streaming and temp cleanup run against a tiny local byte budget.
$GLOBALS['budgets'] = array( 'max_bytes' => 4, 'timeout' => 999, 'max_pixels' => 10 );
reset_http( array( fixture( 'too many bytes' ) ) );
same( 'cover_too_large', $resolver->import_cover( 'https://example.test/cover' )->code, 'Oversized cover is rejected.' );
same( 5, $GLOBALS['requests'][0][1]['limit_response_size'], 'HTTP read is capped at budget plus one byte.' );
same( 30, $GLOBALS['requests'][0][1]['timeout'], 'Filter cannot create unbounded HTTP timeouts.' );
same( false, file_exists( $GLOBALS['cover_tmp'] ), 'Oversized temporary file is removed.' );
reset_http( array( new WP_Error() ) );
same( 'cover_download_failed', $resolver->import_cover( 'https://example.test/cover' )->code, 'Transport errors produce safe import failures.' );
same( false, file_exists( $GLOBALS['cover_tmp'] ), 'Failed download temporary file is removed.' );
reset_http( array( fixture( '', 404 ) ) );
same( 'cover_download_failed', $resolver->import_cover( 'https://example.test/missing' )->code, 'Missing cover is distinct from an empty image.' );
same( false, file_exists( $GLOBALS['cover_tmp'] ), 'Missing cover temporary file is removed.' );
reset_http( array() );
same( 'invalid_cover', $resolver->import_cover( 'file:///etc/passwd' )->code, 'Non-HTTPS cover input is rejected before download.' );
same( 0, count( $GLOBALS['requests'] ), 'Invalid cover input cannot issue HTTP requests.' );
unset( $GLOBALS['budgets'] );

echo 'Metadata regression tests passed (' . $GLOBALS['assertions'] . ' assertions).' . PHP_EOL;
