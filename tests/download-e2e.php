<?php
/**
 * Download e2e test (FIX-42): guest 403, subscriber without purchase 403, with purchase 200, rate limit 429, X-Accel-Redirect.
 *
 * Uses same harness as tests/run.php but focused on DownloadResolver and ProtectedFileProvider.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

// Minimal harness reused from run.php - we include run.php's bootstrap? Instead duplicate minimal.

$GLOBALS['mw_test_meta'] = array();
$GLOBALS['mw_test_options'] = array();
$GLOBALS['mw_test_types'] = array( 1 => 'mw_release' );
$GLOBALS['mw_test_statuses'] = array( 1 => 'publish' );
$GLOBALS['mw_test_transients'] = array();
$GLOBALS['mw_test_capabilities'] = array();
$GLOBALS['mw_test_actions'] = array();

final class WP_Post {
	public $ID;
	public $post_type;
	public function __construct( int $id, string $type ) { $this->ID=$id; $this->post_type=$type; }
}
final class WP_Error {
	private $message;
	public function __construct( string $code='', string $msg='' ){ $this->message=$msg; }
	public function get_error_message(): string { return $this->message; }
}
final class wpdb {
	public $posts='wp_posts';
	public $postmeta='wp_postmeta';
	public function esc_like( string $t ): string { return addcslashes($t,'_%\\'); }
	public function prepare( string $q, ...$args ): string {
		if (1===count($args) && is_array($args[0])) $args=$args[0];
		$esc=[];
		foreach($args as $a){ $esc[]=is_int($a)||is_float($a)?$a:"'".addslashes((string)$a)."'"; }
		$q=str_replace(array('%s','%d'),'%s',$q);
		return vsprintf($q,$esc);
	}
}
function is_wp_error( $v ): bool { return $v instanceof WP_Error; }
function add_action( string $h, callable $c, int $p=10, int $a=1 ): void { $GLOBALS['mw_test_actions'][]=$h; }
function add_filter( string $h, callable $c, int $p=10, int $a=1 ): void { add_action($h,$c,$p,$a); }
function apply_filters( string $h, $v ){ return $v; }
function do_action( string $h, ...$args ): void { $GLOBALS['mw_test_actions'][]=$h; }
function esc_html__( string $t, string $d='' ): string { return $t; }
function esc_attr__( string $t, string $d='' ): string { return $t; }
function __( string $t, string $d='' ): string { return $t; }
function sanitize_key( string $v ): string { return strtolower(preg_replace('/[^a-z0-9_\-]/','',$v)); }
function absint( $v ): int { return abs((int)$v); }
function get_post_type( int $id ): string { return $GLOBALS['mw_test_types'][$id] ?? ''; }
function get_post_meta( int $id, string $k, bool $s=false ){ return $GLOBALS['mw_test_meta'][$id][$k] ?? ''; }
function update_post_meta( int $id, string $k, $v ){ $GLOBALS['mw_test_meta'][$id][$k]=$v; return true; }
function get_option( string $k, $def=false ){ return $GLOBALS['mw_test_options'][$k] ?? $def; }
function update_option( string $k, $v ){ $GLOBALS['mw_test_options'][$k]=$v; return true; }
function get_transient( string $k ){ return $GLOBALS['mw_test_transients'][$k] ?? false; }
function set_transient( string $k, $v, int $e=0 ){ $GLOBALS['mw_test_transients'][$k]=$v; return true; }
function current_user_can( string $cap, ...$a ): bool { return in_array($cap,$GLOBALS['mw_test_capabilities']??[],true); }
function home_url( string $p='/' ): string { return 'https://example.test'.$p; }
function sanitize_text_field( string $v ): string { return trim( strip_tags( $v ) ); }
function get_userdata( int $id ) { return $id > 0 ? (object) array( 'ID' => $id, 'user_email' => 'user' . $id . '@example.test' ) : false; }

require dirname(__DIR__) . '/music-wave-core/src/Support/Autoloader.php';
ManaCore\MusicWave\Core\Support\Autoloader::register();

use ManaCore\MusicWave\Core\Access\AccessPolicyEngine;
use ManaCore\MusicWave\Core\Access\AccessSubject;
use ManaCore\MusicWave\Core\Commerce\PurchaseChecker;
use ManaCore\MusicWave\Core\Contracts\ReleaseRepository;
use ManaCore\MusicWave\Core\Downloads\DownloadRateLimiter;
use ManaCore\MusicWave\Core\Downloads\DownloadResolver;
use ManaCore\MusicWave\Core\Downloads\DownloadTokenService;
use ManaCore\MusicWave\Core\Downloads\TransientReplayStore;

final class TestRepo implements ReleaseRepository {
	public $values = array( 'mw_access_mode'=>'public', 'mw_download_assets'=>array( array('key'=>'mp3-320','label'=>'MP3','asset_id'=>'local:track.mp3') ) );
	public function get( int $id, string $k ){ return $this->values[$k] ?? null; }
	public function update( int $id, string $k, $v ): bool { $this->values[$k]=$v; return true; }
	public function delete( int $id, string $k ): bool { return true; }
	public function product_ids( int $id ): array { return array(10); }
	public function collection_ids( int $id ): array { return array(); }
	public function collection_items( int $id ): array { return array(); }
	public function replace_collection_items( int $id, array $items ): bool { return true; }
}
final class TestProvider implements ManaCore\MusicWave\Core\Downloads\DownloadProvider {
	public $delivered=array();
	public function deliver( string $asset_id, ManaCore\MusicWave\Core\Downloads\DownloadTokenClaims $claims ): bool { $this->delivered[]=$asset_id; return true; }
}

function assert_true( bool $cond, string $msg ): void {
	if (! $cond) { fwrite(STDERR,"FAIL: $msg\n"); exit(1); }
	echo "PASS: $msg\n";
}

// Setup
$repo = new TestRepo();
$checker = new PurchaseChecker($repo);
$engine = new AccessPolicyEngine($repo, $checker);
$provider = new TestProvider();
$token_service = new DownloadTokenService('test-secret');
$replay = new TransientReplayStore();
$resolver = new DownloadResolver($repo, $engine, $token_service, $replay, $provider);

$GLOBALS['mw_test_types'][1]='mw_release';
$GLOBALS['mw_test_statuses'][1]='publish';

// 1. Guest can request when public (FIX-42: guest 403 when restricted, 200 when public)
$repo->values['mw_access_mode']='public';
$guest = new AccessSubject(0);
$ticket = $resolver->issue(1, $guest, 'binding', 'mp3-320');
assert_true(is_string($ticket) && ''!==$ticket, 'Public release: guest gets ticket (200)');

// 2. Guest denied when restricted
$repo->values['mw_access_mode']='restricted';
$ticket2 = $resolver->issue(1, $guest, 'binding', 'mp3-320');
assert_true(null===$ticket2, 'Restricted release: guest denied (403)');
assert_true('entitlement'===$resolver->last_deny_reason(), 'Deny reason entitlement');

// 3. Subscriber without purchase denied when purchase mode
$repo->values['mw_access_mode']='purchase';
$repo->values['mw_product_ids']=array(10);
$subscriber = new AccessSubject(7);
$GLOBALS['mw_test_purchases']=array(); // no purchase
$checker2 = new PurchaseChecker($repo);
$engine2 = new AccessPolicyEngine($repo, $checker2);
$resolver2 = new DownloadResolver($repo, $engine2, $token_service, $replay, $provider);
$ticket3 = $resolver2->issue(1, $subscriber, 'binding', 'mp3-320');
assert_true(null===$ticket3, 'Purchase mode: subscriber without purchase denied (403)');

// 4. Subscriber with purchase allowed (200)
$GLOBALS['mw_test_purchases']['7:10']=true;
function wc_customer_bought_product( string $email, int $uid, int $pid ): bool {
	$key=$uid.':'.$pid;
	return !empty($GLOBALS['mw_test_purchases'][$key]);
}
final class TestWcOrderItem { private $pid; public function __construct(int $p){$this->pid=$p;} public function get_product_id():int{return $this->pid;} public function get_variation_id():int{return 0;} }
final class TestWcOrder { private $cid; private $items; public function __construct(int $c,array $i){$this->cid=$c;$this->items=$i;} public function get_customer_id():int{return $this->cid;} public function get_items():array{return $this->items;} }
$GLOBALS['mw_test_orders']=array( 501=>new TestWcOrder(7,array(new TestWcOrderItem(10))) );
function wc_get_order( int $id ){ return $GLOBALS['mw_test_orders'][$id] ?? false; }

$ticket4 = $resolver2->issue(1, $subscriber, 'binding', 'mp3-320');
assert_true(is_string($ticket4), 'Purchase mode: subscriber with purchase gets ticket (200)');

// 5. Rate limit 429
$_SERVER['REMOTE_ADDR']='203.0.113.5';
$limiter = new DownloadRateLimiter(2,60);
assert_true($limiter->allow(0), 'Rate limiter first request allowed');
assert_true($limiter->allow(0), 'Rate limiter second allowed');
assert_true(!$limiter->allow(0), 'Rate limiter third denied (429)');
unset($_SERVER['REMOTE_ADDR']);

// 6. X-Accel-Redirect header
require_once dirname(__DIR__) . '/music-wave-vip/src/Autoloader.php';
ManaCore\MusicWave\Vip\Autoloader::register();
$headers = ManaCore\MusicWave\Vip\ProtectedFileProvider::sendfile_headers('xaccel','/private/album/track.flac','album/track.flac','/musicwave-protected','audio/flac','attachment; filename="track.flac"');
assert_true(is_array($headers) && in_array('X-Accel-Redirect: /musicwave-protected/album/track.flac',$headers,true), 'X-Accel-Redirect header emitted for nginx');

echo "Download e2e tests passed (FIX-42)\n";
