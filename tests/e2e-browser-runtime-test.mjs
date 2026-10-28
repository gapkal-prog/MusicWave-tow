/**
 * Real JSDOM browser runtime end-to-end verification using ACTUAL HTML rendered
 * directly by PHP (`SettingsPage::render()`, `PlaylistBlocks::render_public()`, `ReleaseMetaBox`):
 * 1. `MusicWave VIP` admin settings tabs (`.mwvip-tabs`), delivery/sendfile row toggles,
 *    master switch, and CSPRNG remote secret generator in RTL (`dir="rtl"`) with deferred script load.
 * 2. `.mw-shared-playlist` Play All (`[data-mw-playlist-play]`) AND individual row play
 *    (`.mw-public-playlists__track-play`) across multi-type releases (single track + album + VIP stream).
 * 3. `ReleaseMetaBox` interactive VIP level chips (`admin-release-meta.js`).
 */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import vm from 'node:vm';
import { JSDOM } from 'jsdom';

const PHP_BIN = fs.existsSync( '/tmp/phpwasm/php' )
	? '/tmp/phpwasm/php'
	: 'php';

const rawPhpOutput = execFileSync( PHP_BIN, [ 'tests/e2e-dump-dom.php' ], {
	encoding: 'utf8',
	maxBuffer: 20 * 1024 * 1024,
} );
const renderedFromPhp = JSON.parse(
	rawPhpOutput
		.split( '__MW_JSON_START__' )[ 1 ]
		.split( '__MW_JSON_END__' )[ 0 ]
);

// =========================================================================
// 1. REAL PHP `SettingsPage::render()` + `admin-vip-settings.css` + `admin-vip-settings.js`
// =========================================================================
{
	const css = fs.readFileSync(
		'music-wave-vip/assets/admin-vip-settings.css',
		'utf8'
	);
	const html = `<!doctype html><html dir="rtl" lang="fa-IR"><head><style>${ css }</style></head><body>${ renderedFromPhp.vip }</body></html>`;
	const dom = new JSDOM( html, {
		url: 'https://musicwave.test/wp-admin/edit.php?post_type=mw_release&page=music-wave-vip',
		runScripts: 'outside-only',
	} );
	await new Promise( ( r ) => setTimeout( r, 0 ) );
	assert.equal( dom.window.document.readyState, 'complete' );

	vm.runInContext(
		fs.readFileSync(
			'music-wave-vip/assets/admin-vip-settings.js',
			'utf8'
		),
		dom.getInternalVMContext()
	);

	const doc = dom.window.document;
	const win = dom.window;
	const generalPanel = doc.querySelector( '[data-panel="general"]' );
	const deliveryTab = doc.querySelector( '[data-tab="delivery"]' );
	const deliveryPanel = doc.querySelector( '[data-panel="delivery"]' );
	const membershipPanel = doc.querySelector( '[data-panel="membership"]' );

	assert.equal( win.getComputedStyle( generalPanel ).display, 'block' );
	assert.equal( win.getComputedStyle( deliveryPanel ).display, 'none' );

	// Click "تحویل فایل" tab -> general hides, delivery opens cleanly via both CSS `.active` and `.hidden`!
	deliveryTab.click();
	assert.equal( win.getComputedStyle( generalPanel ).display, 'none' );
	assert.equal( win.getComputedStyle( deliveryPanel ).display, 'block' );
	assert.equal( deliveryTab.getAttribute( 'aria-selected' ), 'true' );

	// Switch provider select to `remote_redirect` -> `.mwvip-local-row` hides & `.mwvip-remote-row` shows!
	const providerSelect = doc.getElementById( 'mwvip-delivery-provider' );
	providerSelect.value = 'remote_redirect';
	providerSelect.dispatchEvent( new win.Event( 'change' ) );
	assert.equal(
		doc.querySelector( '.mwvip-local-row' ).style.display,
		'none'
	);
	assert.equal( doc.querySelector( '.mwvip-remote-row' ).style.display, '' );

	// Click `#mwvip-gen-secret` -> populates 64-hex-char CSPRNG secret!
	doc.getElementById( 'mwvip-gen-secret' ).click();
	assert.equal(
		doc.getElementById( 'mwvip-remote-secret' ).value.length,
		64
	);

	// RTL ArrowLeft advances from `delivery` -> `membership`!
	deliveryTab.dispatchEvent(
		new win.KeyboardEvent( 'keydown', { key: 'ArrowLeft', bubbles: true } )
	);
	assert.equal( win.getComputedStyle( deliveryPanel ).display, 'none' );
	assert.equal( win.getComputedStyle( membershipPanel ).display, 'block' );
}

// =========================================================================
// 2. REAL PHP `PlaylistBlocks::render_public()` + `preview-player.js` + `playlists.js`
// =========================================================================
{
	const pid = renderedFromPhp.pid;
	assert.equal(
		renderedFromPhp.shared.includes( 'همه فهرست‌های پخش عمومی' ),
		false,
		'Shared playlist HTML must NOT include removed back button'
	);

	const html = `<!doctype html><html dir="rtl" lang="fa-IR"><body>
		${ renderedFromPhp.shared }
		${ renderedFromPhp.player }
	</body></html>`;

	const dom = new JSDOM( html, {
		url: `https://musicwave.test/playlists/?mw-playlist=${ pid }`,
		runScripts: 'outside-only',
	} );
	await new Promise( ( r ) => setTimeout( r, 0 ) );
	assert.equal( dom.window.document.readyState, 'complete' );

	const win = dom.window;
	let playedSrc = '';
	win.HTMLMediaElement.prototype.load = function () {};
	win.HTMLMediaElement.prototype.play = function () {
		playedSrc = this.src;
		Object.defineProperty( this, 'paused', {
			value: false,
			configurable: true,
		} );
		this.dispatchEvent( new win.Event( 'play' ) );
		return Promise.resolve();
	};
	win.HTMLMediaElement.prototype.pause = function () {
		Object.defineProperty( this, 'paused', {
			value: true,
			configurable: true,
		} );
		this.dispatchEvent( new win.Event( 'pause' ) );
	};

	win.musicWavePreview = {
		restUrl: 'https://musicwave.test/wp-json/',
		restNonce: 'nonce-123',
		labels: { error: 'خطا', noPlayable: 'خالی' },
	};
	win.musicWavePlaylists = {
		restUrl: 'https://musicwave.test/wp-json/',
		restNonce: 'nonce-123',
		isLoggedIn: true,
		labels: { playAll: 'پخش همه', noPlayable: 'خالی', error: 'خطا' },
	};
	win.wp = {
		apiFetch( opts ) {
			if (
				opts.path === `/music-wave/v1/playlists/${ pid }/playback-queue`
			) {
				return Promise.resolve( {
					playlist: { id: pid, title: 'منتخب تابستان' },
					previewOnly: false,
					tracks: [
						{
							releaseId: 501,
							rootId: 501,
							title: 'تک‌آهنگ ۵۰۱',
							artist: 'خواننده اول',
							full: false,
							previewUrl: 'https://cdn.example.test/501.mp3',
						},
						{
							releaseId: 503,
							rootId: 505,
							title: 'قطعه اول آلبوم ۵۰۳',
							artist: 'خواننده دوم',
							full: true,
							quality: 'high',
							previewUrl: 'https://cdn.example.test/503-prev.mp3',
						},
					],
				} );
			}
			if ( opts.path === '/music-wave/v1/releases/505/playback-queue' ) {
				return Promise.resolve( {
					release: { id: 505, title: 'آلبوم ۵۰۵' },
					previewOnly: false,
					tracks: [
						{
							releaseId: 503,
							rootId: 505,
							title: 'قطعه اول آلبوم ۵۰۳',
							artist: 'خواننده دوم',
							full: true,
							quality: 'high',
							previewUrl: 'https://cdn.example.test/503-prev.mp3',
						},
					],
				} );
			}
			if (
				opts.path.indexOf(
					'/music-wave/v1/releases/503/download-token'
				) === 0
			) {
				return Promise.resolve( {
					token: 'signed-tok-503',
					nonce: 'stream-nonce-503',
				} );
			}
			return Promise.reject(
				new Error( 'Unexpected path ' + opts.path )
			);
		},
	};

	// Execute BOTH scripts AFTER readyState === 'complete'!
	vm.runInContext(
		fs.readFileSync( 'music-wave-core/assets/playlists.js', 'utf8' ),
		dom.getInternalVMContext()
	);
	vm.runInContext(
		fs.readFileSync( 'music-wave-core/assets/preview-player.js', 'utf8' ),
		dom.getInternalVMContext()
	);

	// 2a. Click "پخش همه" -> plays Track 501 immediately!
	const playAllBtn = win.document.querySelector( '[data-mw-playlist-play]' );
	playAllBtn.click();
	await new Promise( ( r ) => setTimeout( r, 30 ) );
	assert.equal( playedSrc, 'https://cdn.example.test/501.mp3' );
	assert.equal(
		playAllBtn.querySelector( '.mw-playlists__play-icon' ).textContent,
		'❚❚'
	);

	// 2b. Click single-track row button (`.mw-public-playlists__track-play.mw-card-play`) ->
	// MUST play ONLY that music (NOT «پخش همه»!): queue drops to 1 track and Play All resets to `▶`!
	const rowButtons = win.document.querySelectorAll(
		'.mw-public-playlists__track-play'
	);
	rowButtons[ 1 ].click();
	await new Promise( ( r ) => setTimeout( r, 30 ) );
	assert.ok(
		playedSrc.includes( '/music-wave/v1/streams/503?token=signed-tok-503' ),
		'Expected signed VIP full stream URL, got: ' + playedSrc
	);
	assert.equal(
		playAllBtn.getAttribute( 'aria-pressed' ),
		'false',
		'Single-track play button MUST NOT activate Play All button!'
	);
	assert.equal(
		playAllBtn.querySelector( '.mw-playlists__play-icon' ).textContent,
		'▶',
		'Play All icon MUST revert to ▶ when playing a single track!'
	);
	assert.equal(
		win.document.querySelector( '.mw-global-player__queue-toggle' ).hidden,
		true,
		'Global player queue MUST hold only that single track!'
	);
	assert.equal(
		win.document
			.querySelectorAll( '.mw-public-playlists__track' )[ 1 ]
			.getAttribute( 'data-mw-playing' ),
		'true'
	);
}

// =========================================================================
// 3. `ReleaseMetaBox` VIP Level Chips (`admin-release-meta.js`)
// =========================================================================
{
	const html = `<!doctype html><html><body>
		<select id="mw_access_mode"><option value="public">عمومی</option><option value="membership">عضویت</option></select>
		<input type="text" id="mw_membership_levels" value="" />
		<span class="mw-membership-picker" data-mw-level-picker="mw_membership_levels">
			<button type="button" class="mw-level-chip" data-mw-level-slug="mw_vip">همه مشترکین VIP</button>
			<button type="button" class="mw-level-chip" data-mw-level-slug="vip-12m">VIP 12m</button>
		</span>
	</body></html>`;
	const dom = new JSDOM( html, { runScripts: 'outside-only' } );
	await new Promise( ( r ) => setTimeout( r, 0 ) );
	vm.runInContext(
		fs.readFileSync(
			'music-wave-core/assets/admin-release-meta.js',
			'utf8'
		),
		dom.getInternalVMContext()
	);
	const doc = dom.window.document;
	const select = doc.getElementById( 'mw_access_mode' );
	const input = doc.getElementById( 'mw_membership_levels' );
	select.value = 'membership';
	select.dispatchEvent( new dom.window.Event( 'change' ) );
	assert.equal( input.value, 'mw_vip' );
	doc.querySelector( '[data-mw-level-slug="vip-12m"]' ).click();
	assert.equal( input.value, 'mw_vip, vip-12m' );
}

// =========================================================================
// 4. UNINTERRUPTED BACKGROUND PLAYBACK ACROSS DELAYED PAGE NAVIGATIONS
//    (Query Loop `[data-wp-router-region]` -> Interactive Page -> Library Link -> `popstate`)
// =========================================================================
{
	const page1Html = `<!doctype html><html dir="rtl" lang="fa-IR"><head><title>خانه — موج موسیقی</title></head><body class="home">
		<header class="mw-site-header"><nav><a href="https://musicwave.test/">خانه</a><a href="https://musicwave.test/release/501/">قطعه</a></nav></header>
		<main id="wp--skip-link--target">
			<div class="wp-block-query" data-wp-interactive="core/query" data-wp-router-region="query-0">
				<article class="mw-release-card">
					<button type="button" class="mw-card-play" data-mw-release-id="501" aria-pressed="false"><span class="mw-card-play__icon">▶</span></button>
					<h3><a id="query-loop-card-link" href="https://musicwave.test/release/501/">تک‌آهنگ ۵۰۱</a></h3>
				</article>
			</div>
		</main>
		${ renderedFromPhp.player }
	</body></html>`;

	const page2Html = `<!doctype html><html dir="rtl" lang="fa-IR"><head><title>تک‌آهنگ ۵۰۱ — موج موسیقی</title></head><body class="single-mw_release postid-501">
		<header class="mw-site-header"><nav><a href="https://musicwave.test/">خانه</a><a href="https://musicwave.test/release/501/" aria-current="page">قطعه</a></nav></header>
		<main id="wp--skip-link--target">
			<div data-wp-interactive="woocommerce/product-button"><button type="button">افزودن به سبد</button></div>
			<button type="button" class="mw-card-play" data-mw-release-id="501" aria-pressed="false"><span class="mw-card-play__icon">▶</span></button>
			<section data-mw-library-panel>
				<a id="library-song-link" href="https://musicwave.test/account/">مشاهده در کتابخانه</a>
			</section>
		</main>
		${ renderedFromPhp.player }
	</body></html>`;

	const page3Html = `<!doctype html><html dir="rtl" lang="fa-IR"><head><title>حساب کاربری — موج موسیقی</title></head><body class="page-account">
		<header class="mw-site-header"><nav><a href="https://musicwave.test/">خانه</a><a href="https://musicwave.test/release/501/">قطعه</a></nav></header>
		<main id="wp--skip-link--target"><h1>کتابخانه من</h1></main>
		${ renderedFromPhp.player }
	</body></html>`;

	const dom = new JSDOM( page1Html, {
		url: 'https://musicwave.test/',
		pretendToBeVisual: true,
		runScripts: 'outside-only',
	} );
	await new Promise( ( r ) => setTimeout( r, 0 ) );

	const win = dom.window;
	const doc = win.document;
	win.scrollTo = () => {};

	let pauseEvents = 0;
	win.HTMLMediaElement.prototype.load = function () {
		Object.defineProperty( this, 'readyState', {
			value: 4,
			configurable: true,
		} );
		Object.defineProperty( this, 'duration', {
			value: 240,
			configurable: true,
		} );
		this.dispatchEvent( new win.Event( 'loadedmetadata' ) );
	};
	win.HTMLMediaElement.prototype.play = function () {
		Object.defineProperty( this, 'paused', {
			value: false,
			configurable: true,
		} );
		this.dispatchEvent( new win.Event( 'play' ) );
		return Promise.resolve();
	};
	win.HTMLMediaElement.prototype.pause = function () {
		pauseEvents += 1;
		Object.defineProperty( this, 'paused', {
			value: true,
			configurable: true,
		} );
		this.dispatchEvent( new win.Event( 'pause' ) );
	};

	// Simulate real slow server HTML response (120ms network latency per route)!
	win.fetch = ( input ) => {
		const targetUrl = String(
			typeof input === 'string' ? input : input.url
		);
		return new Promise( ( resolve ) => {
			setTimeout( () => {
				const body = targetUrl.includes( '/account/' )
					? page3Html
					: page2Html;
				resolve( {
					ok: true,
					status: 200,
					url: targetUrl,
					headers: {
						get( h ) {
							return h.toLowerCase() === 'content-type'
								? 'text/html; charset=UTF-8'
								: null;
						},
					},
					text: () => Promise.resolve( body ),
				} );
			}, 120 );
		} );
	};

	win.musicWavePreview = {
		restUrl: 'https://musicwave.test/wp-json/',
		restNonce: 'nonce-nav',
		persistentNav: true,
		labels: { error: 'خطا', noPlayable: 'خالی' },
	};
	win.wp = {
		apiFetch( opts ) {
			if ( opts.path === '/music-wave/v1/releases/501/playback-queue' ) {
				return Promise.resolve( {
					tracks: [
						{
							releaseId: 501,
							rootId: 501,
							title: 'تک‌آهنگ ۵۰۱',
							artist: 'خواننده اول',
							full: false,
							previewUrl: 'https://cdn.example.test/501.mp3',
							url: 'https://musicwave.test/release/501/',
						},
					],
				} );
			}
			return Promise.resolve( {} );
		},
	};

	vm.runInContext(
		fs.readFileSync( 'music-wave-core/assets/runtime.js', 'utf8' ),
		dom.getInternalVMContext()
	);
	vm.runInContext(
		fs.readFileSync( 'music-wave-core/assets/preview-player.js', 'utf8' ),
		dom.getInternalVMContext()
	);
	vm.runInContext(
		fs.readFileSync( 'music-wave-core/assets/view-counter.js', 'utf8' ),
		dom.getInternalVMContext()
	);
	vm.runInContext(
		fs.readFileSync( 'music-wave-core/assets/catalog-filters.js', 'utf8' ),
		dom.getInternalVMContext()
	);

	const originalPlayer = doc.querySelector( '[data-mw-preview-player]' );
	const originalAudio = originalPlayer.querySelector( 'audio' );

	// Start streaming Track 501 on Home page and seek to 44.5s.
	doc.querySelector( '.mw-card-play[data-mw-release-id="501"]' ).click();
	await new Promise( ( r ) => setTimeout( r, 25 ) );
	originalAudio.currentTime = 44.5;
	pauseEvents = 0;
	assert.equal( originalAudio.paused, false );

	// Click release link inside WP Query Loop `[data-wp-router-region]`!
	doc.getElementById( 'query-loop-card-link' ).click();

	// MID-FLIGHT ASSERTION AT 45ms WHILE NETWORK IS STILL FETCHING NEXT PAGE:
	await new Promise( ( r ) => setTimeout( r, 45 ) );
	assert.equal(
		originalAudio.paused,
		false,
		'Audio MUST stay playing while next page HTML is still loading over the network!'
	);
	assert.equal(
		pauseEvents,
		0,
		'Audio pause() MUST NEVER be called during page loading!'
	);
	originalAudio.currentTime = 46.2; // Simulate playback advancing smoothly during load!

	// Wait for network response + DOM swap completion:
	await new Promise( ( r ) => setTimeout( r, 130 ) );
	assert.equal( win.location.pathname, '/release/501/' );
	assert.equal( doc.title, 'تک‌آهنگ ۵۰۱ — موج موسیقی' );
	assert.equal(
		doc.querySelector( '[data-mw-preview-player]' ),
		originalPlayer,
		'Exact live global player DOM node MUST survive page swap untouched!'
	);
	assert.equal(
		doc.querySelector( '[data-mw-preview-player] audio' ),
		originalAudio,
		'Exact live <audio> element MUST survive page swap untouched!'
	);
	assert.equal( originalAudio.paused, false );
	assert.equal( originalAudio.currentTime, 46.2 );
	assert.equal(
		pauseEvents,
		0,
		'Zero audio interruptions before, during, or after page swap!'
	);
	assert.equal(
		doc
			.querySelector( 'main .mw-card-play[data-mw-release-id="501"]' )
			.getAttribute( 'aria-pressed' ),
		'true',
		'Play button on newly rendered page MUST automatically reflect active track state!'
	);

	// Navigate from inside `[data-mw-library-panel]` to `/account/` -> ALSO zero pause events!
	doc.getElementById( 'library-song-link' ).click();
	await new Promise( ( r ) => setTimeout( r, 160 ) );
	assert.equal( win.location.pathname, '/account/' );
	assert.equal( doc.querySelector( 'main h1' ).textContent, 'کتابخانه من' );
	assert.equal( originalAudio.paused, false );
	assert.equal( pauseEvents, 0 );

	// Browser Back navigation (`popstate`) -> ALSO soft-navigates with zero pause events!
	win.history.back();
	await new Promise( ( r ) => setTimeout( r, 160 ) );
	assert.equal( originalAudio.paused, false );
	assert.equal( pauseEvents, 0 );
}

// =========================================================================
// 5. UPGRADED `music-wave/continue-listening` («به گوش‌دادن ادامه دهید») E2E
//    5A: Guest Local Live Rail | 5B: One-Click Consent -> Live Shelf Swap |
//    5C: Exact Timestamp Resume (`currentTime === 142`) | 5D: Dismiss & Clear
// =========================================================================
{
	// --- 5A: Guest session live local browser rail ---
	const guestHtml = `<!doctype html><html dir="rtl" lang="fa-IR"><body>
		${ renderedFromPhp.listeningGuest }
		<button type="button" class="mw-card-play" data-mw-release-id="501" aria-pressed="false"><span class="mw-card-play__icon">▶</span></button>
		${ renderedFromPhp.player }
	</body></html>`;
	const guestDom = new JSDOM( guestHtml, {
		url: 'https://musicwave.test/',
		runScripts: 'outside-only',
	} );
	await new Promise( ( r ) => setTimeout( r, 0 ) );
	const gWin = guestDom.window;
	gWin.HTMLMediaElement.prototype.load = function () {
		Object.defineProperty( this, 'readyState', {
			value: 4,
			configurable: true,
		} );
		Object.defineProperty( this, 'duration', {
			value: 200,
			configurable: true,
		} );
		this.dispatchEvent( new gWin.Event( 'loadedmetadata' ) );
	};
	gWin.HTMLMediaElement.prototype.play = function () {
		Object.defineProperty( this, 'paused', {
			value: false,
			configurable: true,
		} );
		this.dispatchEvent( new gWin.Event( 'play' ) );
		return Promise.resolve();
	};
	gWin.HTMLMediaElement.prototype.pause = function () {
		Object.defineProperty( this, 'paused', {
			value: true,
			configurable: true,
		} );
		this.dispatchEvent( new gWin.Event( 'pause' ) );
	};
	gWin.musicWavePreview = {
		restUrl: 'https://musicwave.test/wp-json/',
		restNonce: '',
		loggedIn: false,
		hasListeningConsent: false,
		labels: { error: 'خطا', noPlayable: 'خالی' },
	};
	gWin.musicWaveListening = {
		restUrl: 'https://musicwave.test/wp-json/',
		restNonce: '',
		loggedIn: false,
		hasConsent: false,
		labels: {
			clear: 'پاک‌سازی سابقه',
			cleared: 'سابقهٔ پخش پاک شد.',
			removeItem: 'حذف',
			resumeFrom: 'ادامه از %s',
			playTitle: 'پخش %s',
			openTitle: 'باز کردن %s',
			justNow: 'همین الان',
			untitled: 'انتشار بدون عنوان',
		},
	};
	gWin.wp = {
		apiFetch() {
			return Promise.resolve( {
				tracks: [
					{
						releaseId: 501,
						rootId: 501,
						title: 'تک‌آهنگ ۵۰۱',
						artist: 'خواننده اول',
						full: false,
						previewUrl: 'https://cdn.example.test/501.mp3',
						url: 'https://musicwave.test/release/501/',
					},
				],
			} );
		},
	};
	vm.runInContext(
		fs.readFileSync( 'music-wave-core/assets/preview-player.js', 'utf8' ),
		guestDom.getInternalVMContext()
	);
	vm.runInContext(
		fs.readFileSync( 'music-wave-core/assets/listening.js', 'utf8' ),
		guestDom.getInternalVMContext()
	);

	const guestRail = gWin.document.querySelector(
		'[data-mw-listening-guest-rail]'
	);
	assert.equal( guestRail.hidden, true );

	// Guest plays Track 501 & pauses at 95s -> Guest rail lights up automatically!
	gWin.document
		.querySelector( '.mw-card-play[data-mw-release-id="501"]' )
		.click();
	await new Promise( ( r ) => setTimeout( r, 25 ) );
	const gAudio = gWin.document.querySelector(
		'[data-mw-preview-player] audio'
	);
	gAudio.currentTime = 95;
	gAudio.pause();
	await new Promise( ( r ) => setTimeout( r, 20 ) );

	assert.equal(
		guestRail.hidden,
		false,
		'Guest continue-listening rail MUST unhide as soon as local playback progress exists!'
	);
	assert.equal(
		guestRail
			.querySelector( '.mw-card-play' )
			.getAttribute( 'data-mw-resume-position' ),
		'95'
	);

	// --- 5B + 5C + 5D: Logged-in Consent -> Auto Flush -> Exact Seek (142s) -> Dismiss -> Clear ---
	const apiCalls = [];
	const memberHtml = `<!doctype html><html dir="rtl" lang="fa-IR"><body>
		${ renderedFromPhp.listeningConsent }
		${ renderedFromPhp.player }
	</body></html>`;
	const memberDom = new JSDOM( memberHtml, {
		url: 'https://musicwave.test/account/',
		runScripts: 'outside-only',
	} );
	await new Promise( ( r ) => setTimeout( r, 0 ) );
	const mWin = memberDom.window;
	// Seed guest pre-login local item at 95s so consent flush upgrades seamlessly!
	mWin.localStorage.setItem(
		'mw-continue-listening',
		gWin.localStorage.getItem( 'mw-continue-listening' )
	);
	mWin.HTMLMediaElement.prototype.load = function () {
		Object.defineProperty( this, 'readyState', {
			value: 4,
			configurable: true,
		} );
		Object.defineProperty( this, 'duration', {
			value: 300,
			configurable: true,
		} );
		this.dispatchEvent( new mWin.Event( 'loadedmetadata' ) );
	};
	mWin.HTMLMediaElement.prototype.play = function () {
		Object.defineProperty( this, 'paused', {
			value: false,
			configurable: true,
		} );
		this.dispatchEvent( new mWin.Event( 'play' ) );
		return Promise.resolve();
	};
	mWin.HTMLMediaElement.prototype.pause = function () {
		Object.defineProperty( this, 'paused', {
			value: true,
			configurable: true,
		} );
		this.dispatchEvent( new mWin.Event( 'pause' ) );
	};
	mWin.musicWavePreview = {
		restUrl: 'https://musicwave.test/wp-json/',
		restNonce: 'member-nonce',
		loggedIn: true,
		hasListeningConsent: false,
		labels: { error: 'خطا', noPlayable: 'خالی' },
	};
	mWin.musicWaveListening = {
		restUrl: 'https://musicwave.test/wp-json/',
		restNonce: 'member-nonce',
		loggedIn: true,
		hasConsent: false,
		labels: {
			saving: 'در حال فعال‌سازی…',
			enabled: 'سابقهٔ گوش‌دادن روشن است.',
			error: 'خطا',
			clear: 'پاک‌سازی سابقه',
			cleared: 'سابقهٔ پخش پاک شد.',
			removeItem: 'حذف',
			resumeFrom: 'ادامه از %s',
			playTitle: 'پخش %s',
			openTitle: 'باز کردن %s',
			justNow: 'همین الان',
			untitled: 'انتشار بدون عنوان',
		},
	};
	mWin.wp = {
		apiFetch( opts ) {
			apiCalls.push( `${ opts.method || 'GET' } ${ opts.path }` );
			if ( opts.path === '/music-wave/v1/listening/consent' ) {
				const granted = Boolean( opts.data && opts.data.consent );
				return Promise.resolve( { consent: granted } );
			}
			if ( opts.path === '/music-wave/v1/listening/progress' ) {
				return Promise.resolve( { saved: true } );
			}
			if ( opts.path.startsWith( '/music-wave/v1/listening/continue' ) ) {
				return Promise.resolve( {
					consent: true,
					source: 'continue',
					items: [ { release_id: 501, position: 142 } ],
					cards_html: renderedFromPhp.cardsHtml,
				} );
			}
			if ( opts.path === '/music-wave/v1/releases/501/playback-queue' ) {
				return Promise.resolve( {
					tracks: [
						{
							releaseId: 501,
							rootId: 501,
							title: 'تک‌آهنگ ۵۰۱',
							artist: 'خواننده اول',
							full: false,
							previewUrl: 'https://cdn.example.test/501-full.mp3',
						},
					],
				} );
			}
			if (
				opts.path.startsWith( '/music-wave/v1/listening/items/501' )
			) {
				return Promise.resolve( { removed: true, release_id: 501 } );
			}
			if ( opts.path.startsWith( '/music-wave/v1/listening/history' ) ) {
				return Promise.resolve( { cleared: true } );
			}
			return Promise.resolve( {} );
		},
	};

	vm.runInContext(
		fs.readFileSync( 'music-wave-core/assets/preview-player.js', 'utf8' ),
		memberDom.getInternalVMContext()
	);
	vm.runInContext(
		fs.readFileSync( 'music-wave-core/assets/listening.js', 'utf8' ),
		memberDom.getInternalVMContext()
	);

	// Click one-click consent opt-in button!
	mWin.document.querySelector( '[data-mw-listening-consent]' ).click();
	await new Promise( ( r ) => setTimeout( r, 40 ) );

	assert.ok( apiCalls.includes( 'POST /music-wave/v1/listening/consent' ) );
	assert.ok(
		apiCalls.includes( 'POST /music-wave/v1/listening/progress' ),
		'Pre-login local playback history MUST automatically flush to server upon consent opt-in!'
	);
	const resumeBtn = mWin.document.querySelector(
		'.mw-continue-listening [data-mw-resume-position="142"]'
	);
	assert.ok(
		resumeBtn,
		'Consent panel MUST swap live in place into server-rendered continue shelf with exact 142s resume button!'
	);

	// 5C: Click the card's play button (`data-mw-resume-position="142"`) -> Player seeks straight to 142s!
	const mAudio = mWin.document.querySelector(
		'[data-mw-preview-player] audio'
	);
	resumeBtn.click();
	await new Promise( ( r ) => setTimeout( r, 35 ) );
	assert.equal( mAudio.paused, false );
	assert.equal(
		mAudio.currentTime,
		142,
		'Clicking Continue Listening play button MUST seek directly to saved timestamp 142s!'
	);

	// 5D: Dismiss item 501 (`×`), then click Clear History -> clean empty state!
	mWin.document.querySelector( '[data-mw-listening-remove="501"]' ).click();
	await new Promise( ( r ) => setTimeout( r, 260 ) );
	assert.ok(
		apiCalls.some( ( c ) =>
			c.startsWith( 'DELETE /music-wave/v1/listening/items/501' )
		)
	);
	assert.equal(
		mWin.document.querySelector( '[data-mw-listening-remove="501"]' ),
		null,
		'Dismissed item 501 MUST be removed from the DOM!'
	);
	assert.ok(
		mWin.document.querySelector( '[data-mw-listening-remove="504"]' ),
		'Remaining item 504 MUST stay visible!'
	);

	mWin.document.querySelector( '[data-mw-listening-clear]' ).click();
	await new Promise( ( r ) => setTimeout( r, 40 ) );
	assert.ok(
		apiCalls.some( ( c ) =>
			c.startsWith( 'DELETE /music-wave/v1/listening/history' )
		)
	);
	assert.equal(
		mWin.document
			.querySelector( '.mw-continue-listening' )
			.classList.contains( 'mw-continue-listening--empty' ),
		true,
		'Clearing history MUST transition shelf into clean empty state!'
	);

	// 5E: Turn OFF listening history (`[data-mw-listening-disable]`) -> switches live back to consent opt-in state and stops tracking!
	const disableBtn = mWin.document.querySelector(
		'[data-mw-listening-disable]'
	);
	assert.ok(
		disableBtn,
		'Empty listening shelf MUST render `[data-mw-listening-disable]` so user can turn listening history OFF!'
	);
	apiCalls.length = 0;
	disableBtn.click();
	await new Promise( ( r ) => setTimeout( r, 40 ) );
	assert.ok(
		apiCalls.some( ( c ) =>
			c.startsWith( 'POST /music-wave/v1/listening/consent' )
		)
	);
	assert.equal(
		mWin.document
			.querySelector( '.mw-continue-listening' )
			.classList.contains( 'mw-continue-listening--consent' ),
		true,
		'Disabling listening history MUST switch shelf back to opt-in consent panel without reload!'
	);
	assert.ok(
		mWin.document.querySelector( '[data-mw-listening-consent]' ),
		'Re-enable CTA button MUST be rendered after disabling!'
	);
	apiCalls.length = 0;
	mAudio.currentTime = 44;
	mAudio.dispatchEvent( new mWin.Event( 'pause' ) );
	await new Promise( ( r ) => setTimeout( r, 30 ) );
	assert.equal(
		apiCalls.some( ( c ) =>
			c.startsWith( 'POST /music-wave/v1/listening/progress' )
		),
		false,
		'Once disabled, playback MUST NOT send progress events to server!'
	);
	assert.equal(
		mWin._mwPreviewController.getLocalHistory( 'continue', 8 ).length,
		0,
		'Once disabled by logged-in user, playback MUST NOT record local history either!'
	);
}

// =========================================================================
// 6. PUBLIC PLAYLISTS PAGE (`/playlists/`): SPA NAV + «نمایش قطعه‌ها» INLINE + SINGLE TRACK PLAY
// =========================================================================
{
	const pPid = renderedFromPhp.pid;
	const homeHtml = `<!doctype html><html><body>
		<header class="wp-block-template-part"><div class="mw-site-header" data-wp-interactive="core/navigation">
			<ul class="wp-block-navigation__container">
				<li class="wp-block-navigation-item current-menu-item"><a href="https://musicwave.test/">خانه</a></li>
				<li class="wp-block-navigation-item"><a href="https://musicwave.test/playlists/">فهرست‌های پخش</a></li>
			</ul>
		</div></header>
		<main>
			<a id="nav-to-playlists" href="https://musicwave.test/playlists/">رفتن به فهرست‌های پخش</a>
			<button type="button" class="mw-preview-button" data-preview-url="https://cdn.example.test/bg-home.mp3" data-preview-title="زمینه خانه">پخش</button>
		</main>
		${ renderedFromPhp.player }
	</body></html>`;

	const playlistsPageHtml = `<!doctype html><html><body>
		<header class="wp-block-template-part"><div class="mw-site-header" data-wp-interactive="core/navigation">
			<ul class="wp-block-navigation__container">
				<li class="wp-block-navigation-item"><a href="https://musicwave.test/">خانه</a></li>
				<li class="wp-block-navigation-item current-menu-item"><a href="https://musicwave.test/playlists/" aria-current="page">فهرست‌های پخش</a></li>
			</ul>
		</div></header>
		<main>${ renderedFromPhp.publicBrowse }</main>
		${ renderedFromPhp.player }
	</body></html>`;

	const pDom = new JSDOM( homeHtml, {
		url: 'https://musicwave.test/',
		pretendToBeVisual: true,
		runScripts: 'outside-only',
	} );
	await new Promise( ( r ) => setTimeout( r, 0 ) );
	const pWin = pDom.window;
	let navFetches = 0;

	pWin.fetch = ( input ) => {
		const target = String( input );
		navFetches += 1;
		if ( target === 'https://musicwave.test/playlists/' ) {
			return Promise.resolve( {
				ok: true,
				status: 200,
				headers: { get: () => 'text/html; charset=utf-8' },
				text: () => Promise.resolve( playlistsPageHtml ),
			} );
		}
		return Promise.reject(
			new Error( 'Unexpected navigation fetch: ' + target )
		);
	};

	pWin.scrollTo = () => {};
	pWin.HTMLMediaElement.prototype.load = function () {};
	pWin.HTMLMediaElement.prototype.play = function () {
		Object.defineProperty( this, 'paused', {
			value: false,
			configurable: true,
		} );
		this.dispatchEvent( new pWin.Event( 'play' ) );
		return Promise.resolve();
	};
	pWin.HTMLMediaElement.prototype.pause = function () {
		Object.defineProperty( this, 'paused', {
			value: true,
			configurable: true,
		} );
		this.dispatchEvent( new pWin.Event( 'pause' ) );
	};

	pWin.musicWavePreviewPlayer = {
		restUrl: 'https://musicwave.test/wp-json/',
		restNonce: 'nonce-pub',
		persistentNav: true,
		labels: { error: 'خطا', noPlayable: 'خالی' },
	};
	pWin.musicWavePlaylists = {
		restUrl: 'https://musicwave.test/wp-json/',
		restNonce: 'nonce-pub',
		isLoggedIn: false,
		labels: {
			playAll: 'پخش همه',
			showTracks: 'نمایش قطعه‌ها',
			hideTracks: 'مخفی کردن قطعه‌ها',
			noPlayable: 'خالی',
			error: 'خطا',
		},
	};
	pWin.wp = {
		apiFetch( opts ) {
			if (
				opts.path ===
				`/music-wave/v1/playlists/${ pPid }/playback-queue`
			) {
				return Promise.resolve( {
					playlist: { id: pPid, title: 'منتخب تابستان' },
					previewOnly: false,
					tracks: [
						{
							releaseId: 501,
							rootId: 501,
							title: 'تک‌آهنگ اول',
							artist: 'خواننده اول',
							full: false,
							previewUrl: 'https://cdn.example.test/501.mp3',
						},
						{
							releaseId: 504,
							rootId: 504,
							title: 'تک‌آهنگ دوم',
							artist: 'خواننده دوم',
							full: false,
							previewUrl: 'https://cdn.example.test/504.mp3',
						},
					],
				} );
			}
			if ( opts.path === '/music-wave/v1/releases/504/playback-queue' ) {
				return Promise.resolve( {
					release: { id: 504, title: 'تک‌آهنگ دوم' },
					previewOnly: false,
					tracks: [
						{
							releaseId: 504,
							rootId: 504,
							title: 'تک‌آهنگ دوم',
							artist: 'خواننده دوم',
							full: false,
							previewUrl: 'https://cdn.example.test/504.mp3',
						},
					],
				} );
			}
			return Promise.reject(
				new Error( 'Unexpected API: ' + opts.path )
			);
		},
	};

	// Load runtime + player + playlists on Home BEFORE navigating to `/playlists/`!
	vm.runInContext(
		fs.readFileSync( 'music-wave-core/assets/runtime.js', 'utf8' ),
		pDom.getInternalVMContext()
	);
	vm.runInContext(
		fs.readFileSync( 'music-wave-core/assets/preview-player.js', 'utf8' ),
		pDom.getInternalVMContext()
	);
	vm.runInContext(
		fs.readFileSync( 'music-wave-core/assets/playlists.js', 'utf8' ),
		pDom.getInternalVMContext()
	);

	// Start background audio on Home, then soft-navigate to `/playlists/`
	pWin.document.querySelector( '.mw-preview-button' ).click();
	await new Promise( ( r ) => setTimeout( r, 25 ) );
	pWin.document.getElementById( 'nav-to-playlists' ).click();
	await new Promise( ( r ) => setTimeout( r, 45 ) );

	assert.equal( pWin.location.href, 'https://musicwave.test/playlists/' );
	assert.equal(
		pWin.document.querySelector( '.mw-shared-playlist' ),
		null,
		'`/playlists/` MUST NOT display `.mw-shared-playlist`!'
	);

	// Now hover & click «نمایش قطعه‌ها» (`[data-mw-public-toggle]`) on playlist `pPid`!
	const fetchesBeforeToggle = navFetches;
	const showTracksBtn = pWin.document.querySelector(
		`[data-mw-public-toggle][data-playlist-id="${ pPid }"]`
	);
	assert.equal( showTracksBtn.tagName, 'BUTTON' );
	showTracksBtn.dispatchEvent(
		new pWin.Event( 'pointerover', { bubbles: true } )
	);
	showTracksBtn.click();
	await new Promise( ( r ) => setTimeout( r, 45 ) );

	assert.equal(
		navFetches,
		fetchesBeforeToggle,
		'Clicking «نمایش قطعه‌ها» MUST NEVER trigger a router fetch or reload!'
	);
	assert.equal(
		pWin.location.href,
		'https://musicwave.test/playlists/',
		'URL MUST stay clean without `?mw-playlist=`!'
	);
	assert.equal(
		pWin.document.querySelector( '.mw-shared-playlist' ),
		null,
		'Clicking «نمایش قطعه‌ها» MUST NEVER render `.mw-shared-playlist` at the top!'
	);

	const expandedPanel = pWin.document.getElementById(
		`mw-public-playlist-panel-${ pPid }`
	);
	assert.equal( expandedPanel.hidden, false );
	assert.equal( showTracksBtn.getAttribute( 'aria-expanded' ), 'true' );

	// Click single-track `.mw-public-playlists__track-play.mw-card-play` inside `.mw-public-playlists__track`:
	// MUST play ONLY track 504 — NOT «پخش همه»!
	const trackButtons = expandedPanel.querySelectorAll(
		'.mw-public-playlists__track-play.mw-card-play'
	);
	assert.equal( trackButtons.length, 2 );
	trackButtons[ 1 ].click();
	await new Promise( ( r ) => setTimeout( r, 35 ) );

	assert.equal(
		pWin.document.querySelector( 'audio' ).src,
		'https://cdn.example.test/504.mp3'
	);
	assert.equal(
		pWin.document
			.querySelector( '[data-mw-playlist-play]' )
			.getAttribute( 'aria-pressed' ),
		'false',
		'Track play button MUST NOT activate Play All!'
	);
	assert.equal(
		pWin.document.querySelector( '.mw-global-player__queue-toggle' )
			.hidden,
		true,
		'Global player queue MUST only contain the clicked single track!'
	);
}

// =========================================================================
// E2E 6: `.mw-secure-play-button` single-file playback & quality isolation
// vs «پخش همه» across multi-file release posts & single preview buttons.
// =========================================================================
{
	const requestedTokens = [];
	let queueFetches = 0;

	const releasePageHtml = `<!DOCTYPE html><html dir="rtl" lang="fa-IR"><head><title>آلبوم سپیده</title></head><body>
		<main>
			<section class="mw-preview-player">
				<button class="mw-preview-button" type="button" data-mw-play-mode="all" data-mw-release-id="900" aria-pressed="false">
					<span class="mw-preview-button__icon" aria-hidden="true">▶</span>
					<span>پخش همه</span>
				</button>
			</section>
			<aside class="mw-download-action mw-download-action--panel mw-download-action--unified">
				<div class="mw-download-action__heading"><strong>دانلود این انتشار</strong></div>
				<div class="mw-download-file-list" role="list">
					<div class="mw-download-file-row" data-mw-file-key="track-1" role="listitem">
						<div class="mw-download-file-row__title"><strong>قطعه اول</strong></div>
						<div class="mw-download-action__quality">
							<select class="mw-download-quality">
								<option value="track-1-mp3" data-streamable="1">MP3 320 kbps</option>
								<option value="track-1-flac" data-streamable="1">FLAC</option>
							</select>
						</div>
						<div class="mw-download-file-row__actions">
							<button class="mw-secure-play-button" type="button" aria-pressed="false" aria-label="پخش آلبوم سپیده — قطعه اول" data-release-id="900" data-mw-file-key="track-1" data-mw-track-title="آلبوم سپیده — قطعه اول" data-mw-track-artist="هنرمند نمونه" data-play-label="پخش" data-pause-label="مکث"><span class="mw-secure-play-button__icon" aria-hidden="true">▶</span><span class="mw-secure-play-button__label">پخش</span></button>
							<button class="wp-element-button mw-download-button" type="button" data-release-id="900"><span class="mw-download-button__label">دانلود ایمن</span></button>
						</div>
					</div>
					<div class="mw-download-file-row" data-mw-file-key="track-2" role="listitem">
						<div class="mw-download-file-row__title"><strong>قطعه دوم</strong></div>
						<div class="mw-download-action__quality">
							<select class="mw-download-quality">
								<option value="track-2-mp3" data-streamable="1">MP3 320 kbps</option>
								<option value="track-2-flac" data-streamable="1">FLAC</option>
							</select>
						</div>
						<div class="mw-download-file-row__actions">
							<button class="mw-secure-play-button" type="button" aria-pressed="false" aria-label="پخش آلبوم سپیده — قطعه دوم" data-release-id="900" data-mw-file-key="track-2" data-mw-track-title="آلبوم سپیده — قطعه دوم" data-mw-track-artist="هنرمند نمونه" data-play-label="پخش" data-pause-label="مکث"><span class="mw-secure-play-button__icon" aria-hidden="true">▶</span><span class="mw-secure-play-button__label">پخش</span></button>
							<button class="wp-element-button mw-download-button" type="button" data-release-id="900"><span class="mw-download-button__label">دانلود ایمن</span></button>
						</div>
					</div>
					<div class="mw-download-file-row" data-mw-file-key="track-3" role="listitem">
						<div class="mw-download-file-row__title"><strong>قطعه سوم</strong></div>
						<div class="mw-download-action__quality">
							<select class="mw-download-quality">
								<option value="track-3-mp3" data-streamable="1">MP3 320 kbps</option>
								<option value="track-3-zip" data-streamable="0">ZIP Archive</option>
							</select>
						</div>
						<div class="mw-download-file-row__actions">
							<button class="mw-secure-play-button" type="button" aria-pressed="false" aria-label="پخش آلبوم سپیده — قطعه سوم" data-release-id="900" data-mw-file-key="track-3" data-mw-track-title="آلبوم سپیده — قطعه سوم" data-mw-track-artist="هنرمند نمونه" data-play-label="پخش" data-pause-label="مکث"><span class="mw-secure-play-button__icon" aria-hidden="true">▶</span><span class="mw-secure-play-button__label">پخش</span></button>
							<button class="wp-element-button mw-download-button" type="button" data-release-id="900"><span class="mw-download-button__label">دانلود ایمن</span></button>
						</div>
					</div>
				</div>
				<span class="mw-download-status" role="status" aria-live="polite"></span>
			</aside>
			<div class="mw-related-previews">
				<button class="mw-preview-button" type="button" aria-pressed="false" data-preview-url="https://cdn.example.test/p1.mp3" data-preview-title="پیش‌نمایش ۱"><span class="mw-preview-button__icon">▶</span><span>پیش‌نمایش</span></button>
				<button class="mw-preview-button" type="button" aria-pressed="false" data-preview-url="https://cdn.example.test/p2.mp3" data-preview-title="پیش‌نمایش ۲"><span class="mw-preview-button__icon">▶</span><span>پیش‌نمایش</span></button>
			</div>
		</main>
		<div id="mw-global-player" class="mw-global-player" data-mw-preview-player hidden>
			<audio preload="metadata"></audio>
			<img class="mw-global-player__art" alt="" hidden />
			<span class="mw-global-player__title"></span>
			<span class="mw-global-player__artist"></span>
			<button type="button" class="mw-global-player__previous"></button>
			<button type="button" class="mw-global-player__toggle"></button>
			<button type="button" class="mw-global-player__next"></button>
			<div class="mw-global-player__progress"><input type="range" min="0" max="100" value="0" /></div>
			<span class="mw-global-player__time">0:00</span>
			<span class="mw-global-player__duration">0:00</span>
			<button type="button" class="mw-global-player__mute"></button>
			<input type="range" class="mw-global-player__volume" min="0" max="100" value="100" />
			<button type="button" class="mw-global-player__queue-toggle" hidden></button>
			<div data-mw-queue hidden><button type="button" class="mw-global-player__queue-close"></button><ol class="mw-global-player__queue-list"></ol></div>
			<button type="button" class="mw-global-player__lyrics-toggle" hidden></button>
			<div data-mw-player-lyrics hidden><button type="button" class="mw-global-player__lyrics-close"></button><div data-mw-lyrics><div data-mw-lyrics-stage></div></div></div>
			<div class="mw-global-player__notice" hidden><span class="mw-global-player__notice-message"></span><a class="mw-global-player__notice-cta" hidden></a><a class="mw-global-player__notice-login" hidden></a><button type="button" class="mw-global-player__notice-close"></button></div>
			<button type="button" class="mw-global-player__close"></button>
		</div>
	</body></html>`;

	const sDom = new JSDOM( releasePageHtml, {
		url: 'https://musicwave.test/release/900/',
		runScripts: 'outside-only',
	} );
	const sWin = sDom.window;
	const sDoc = sWin.document;
	sWin.HTMLMediaElement.prototype.load = function () {
		Object.defineProperty( this, 'readyState', {
			value: 4,
			configurable: true,
		} );
		Object.defineProperty( this, 'duration', {
			value: 210,
			configurable: true,
		} );
		this.dispatchEvent( new sWin.Event( 'loadedmetadata' ) );
	};
	sWin.HTMLMediaElement.prototype.play = function () {
		Object.defineProperty( this, 'paused', {
			value: false,
			configurable: true,
		} );
		this.dispatchEvent( new sWin.Event( 'play' ) );
		return Promise.resolve();
	};
	sWin.HTMLMediaElement.prototype.pause = function () {
		Object.defineProperty( this, 'paused', {
			value: true,
			configurable: true,
		} );
		this.dispatchEvent( new sWin.Event( 'pause' ) );
	};
	sWin.musicWaveDownload = {
		restUrl: 'https://musicwave.test/wp-json/',
		restNonce: 'nonce-dl',
		playLabel: 'پخش',
		pauseLabel: 'مکث',
	};
	sWin.musicWavePreviewPlayer = {
		restUrl: 'https://musicwave.test/wp-json/',
		restNonce: 'nonce-dl',
		labels: { error: 'خطا', streamError: 'خطای پخش امن' },
	};
	sWin.wp = {
		apiFetch( opts ) {
			if ( opts.path.indexOf( '/download-token?' ) !== -1 ) {
				requestedTokens.push( opts.path );
				const qMatch = opts.path.match( /quality=([^&]+)/ );
				const q = qMatch ? decodeURIComponent( qMatch[ 1 ] ) : 'none';
				return Promise.resolve( {
					token: 'tok-' + q,
					nonce: 'n-' + q,
				} );
			}
			if ( opts.path === '/music-wave/v1/releases/900/playback-queue' ) {
				queueFetches += 1;
				return Promise.resolve( {
					release: { id: 900, title: 'آلبوم سپیده' },
					previewOnly: false,
					tracks: [
						{
							releaseId: 900,
							rootId: 900,
							title: 'آلبوم سپیده — قطعه اول',
							artist: 'هنرمند نمونه',
							full: true,
							quality: 'track-1-mp3',
						},
						{
							releaseId: 900,
							rootId: 900,
							title: 'آلبوم سپیده — قطعه دوم',
							artist: 'هنرمند نمونه',
							full: true,
							quality: 'track-2-mp3',
						},
						{
							releaseId: 900,
							rootId: 900,
							title: 'آلبوم سپیده — قطعه سوم',
							artist: 'هنرمند نمونه',
							full: true,
							quality: 'track-3-mp3',
						},
					],
				} );
			}
			return Promise.resolve( {} );
		},
	};

	vm.runInContext(
		fs.readFileSync( 'music-wave-core/assets/download.js', 'utf8' ),
		sDom.getInternalVMContext()
	);
	vm.runInContext(
		fs.readFileSync( 'music-wave-core/assets/preview-player.js', 'utf8' ),
		sDom.getInternalVMContext()
	);

	const heroPlayAll = sDoc.querySelector(
		'.mw-preview-button[data-mw-play-mode="all"]'
	);
	const rowButtons = sDoc.querySelectorAll( '.mw-secure-play-button' );
	const rows = sDoc.querySelectorAll( '.mw-download-file-row' );
	const playerAudio = sDoc.querySelector( '#mw-global-player audio' );
	const playerTitle = sDoc.querySelector( '.mw-global-player__title' );
	const playerNext = sDoc.querySelector( '.mw-global-player__next' );
	const playerQueueToggle = sDoc.querySelector(
		'.mw-global-player__queue-toggle'
	);

	// 1. Click Row 2's `.mw-secure-play-button` («پخش»): ONLY `track-2-mp3` streams!
	rowButtons[ 1 ].click();
	await new Promise( ( r ) => setTimeout( r, 30 ) );

	assert.equal(
		queueFetches,
		0,
		'Clicking single-file `.mw-secure-play-button` MUST NOT fetch whole release queue!'
	);
	assert.equal( requestedTokens.length, 1 );
	assert.ok( requestedTokens[ 0 ].includes( 'quality=track-2-mp3' ) );
	assert.ok( playerAudio.src.includes( 'token=tok-track-2-mp3' ) );
	assert.equal( playerTitle.textContent, 'آلبوم سپیده — قطعه دوم' );
	assert.equal( playerQueueToggle.hidden, true );
	assert.equal( playerNext.disabled, true );
	assert.equal( rowButtons[ 0 ].getAttribute( 'aria-pressed' ), 'false' );
	assert.equal(
		rowButtons[ 0 ].querySelector( '.mw-secure-play-button__label' )
			.textContent,
		'پخش'
	);
	assert.equal( rowButtons[ 1 ].getAttribute( 'aria-pressed' ), 'true' );
	assert.equal(
		rowButtons[ 1 ].querySelector( '.mw-secure-play-button__label' )
			.textContent,
		'مکث'
	);
	assert.equal( rows[ 0 ].hasAttribute( 'data-mw-playing' ), false );
	assert.equal( rows[ 1 ].getAttribute( 'data-mw-playing' ), 'true' );
	assert.equal(
		heroPlayAll.getAttribute( 'aria-pressed' ),
		'false',
		'Single-file playback MUST keep hero «پخش همه» unpressed!'
	);

	// 2. Switch Row 2 quality dropdown to `track-2-flac` -> button resets to `▶ پخش`, click streams `track-2-flac`!
	const row2Select = rows[ 1 ].querySelector( '.mw-download-quality' );
	row2Select.value = 'track-2-flac';
	row2Select.dispatchEvent( new sWin.Event( 'change', { bubbles: true } ) );
	assert.equal( rowButtons[ 1 ].getAttribute( 'aria-pressed' ), 'false' );
	assert.equal(
		rowButtons[ 1 ].querySelector( '.mw-secure-play-button__label' )
			.textContent,
		'پخش'
	);
	rowButtons[ 1 ].click();
	await new Promise( ( r ) => setTimeout( r, 30 ) );
	assert.ok( playerAudio.src.includes( 'token=tok-track-2-flac' ) );
	assert.equal( rowButtons[ 1 ].getAttribute( 'aria-pressed' ), 'true' );

	// 3. Switch Row 3 quality dropdown to non-streamable ZIP -> disables Row 3 play button!
	const row3Select = rows[ 2 ].querySelector( '.mw-download-quality' );
	row3Select.value = 'track-3-zip';
	row3Select.dispatchEvent( new sWin.Event( 'change', { bubbles: true } ) );
	assert.equal( rowButtons[ 2 ].disabled, true );

	// 4. Click hero «پخش همه» while single file is active -> loads ALL 3 files of post 900!
	heroPlayAll.click();
	await new Promise( ( r ) => setTimeout( r, 30 ) );
	assert.equal( queueFetches, 1 );
	assert.equal( heroPlayAll.getAttribute( 'aria-pressed' ), 'true' );
	assert.equal( playerQueueToggle.hidden, false );
	assert.equal( playerNext.disabled, false );
	assert.equal( playerTitle.textContent, 'آلبوم سپیده — قطعه اول' );
	assert.equal( rowButtons[ 0 ].getAttribute( 'aria-pressed' ), 'true' );
	assert.equal( rowButtons[ 1 ].getAttribute( 'aria-pressed' ), 'false' );

	// 5. Click single `.mw-preview-button[data-preview-url]` #2 -> plays ONLY `p2.mp3` (never queues `p1.mp3`)!
	const singlePreviewButtons = sDoc.querySelectorAll(
		'.mw-related-previews .mw-preview-button'
	);
	singlePreviewButtons[ 1 ].click();
	await new Promise( ( r ) => setTimeout( r, 25 ) );
	assert.equal( playerAudio.src, 'https://cdn.example.test/p2.mp3' );
	assert.equal( playerQueueToggle.hidden, true );
	assert.equal( playerNext.disabled, true );
	assert.equal(
		singlePreviewButtons[ 0 ].getAttribute( 'aria-pressed' ),
		'false'
	);
	assert.equal(
		singlePreviewButtons[ 1 ].getAttribute( 'aria-pressed' ),
		'true'
	);

	// 6. Verify legacy `min="0" max="100"` volume markup ALSO scrubs smoothly forward & backward!
	const legacyVolume = sDoc.querySelector( 'input.mw-global-player__volume' );
	for ( const pct of [ 47, 83, 18, 0, 65, 100 ] ) {
		legacyVolume.value = String( pct );
		legacyVolume.dispatchEvent( new sWin.Event( 'input', { bubbles: true } ) );
		legacyVolume.dispatchEvent(
			new sWin.Event( 'change', { bubbles: true } )
		);
		assert.equal( Number( legacyVolume.value ), pct );
		assert.equal( Number( playerAudio.volume.toFixed( 2 ) ), pct / 100 );
	}
}

// =========================================================================
// 7. REAL JSDOM E2E FOR PILLARS 1, 2, 3 & 4 AGAINST REAL PHP HTML + CSS:
//    - Uninterrupted `#mw-global-player` soft navigation (`Home -> /account/ -> Home -> /account/`)
//      with topological inline `<head>` config + auto-loaded `dashboard.js` & `library.js`
//      and `.mw-user-dashboard__tabs` FIRST TAB OPEN BY DEFAULT without refresh!
//    - Smooth forward & backward scrubbing on `input.mw-global-player__volume` across `[0..1]`!
//    - Guest `.mw-playlist-picker__trigger--guest` circular icon disc parity with `LibraryButton`!
// =========================================================================
{
	const globalPlayerCss = fs.readFileSync(
		'musicwave/assets/css/components/global-player.css',
		'utf8'
	);
	const playlistsCss = fs.readFileSync(
		'musicwave/assets/css/components/playlists.css',
		'utf8'
	);
	const catalogCss = fs.readFileSync(
		'musicwave/assets/css/components/catalog.css',
		'utf8'
	);
	const libraryCss = fs.readFileSync(
		'music-wave-core/assets/library.css',
		'utf8'
	);
	const releasePageCss = fs.readFileSync(
		'musicwave/assets/css/components/release-page.css',
		'utf8'
	);
	const dashboardScriptSrc = fs.readFileSync(
		'music-wave-core/assets/dashboard.js',
		'utf8'
	);
	const libraryScriptSrc = fs.readFileSync(
		'music-wave-core/assets/library.js',
		'utf8'
	);
	const runtimeSrc = fs.readFileSync(
		'music-wave-core/assets/runtime.js',
		'utf8'
	);
	const previewPlayerSrc = fs.readFileSync(
		'music-wave-core/assets/preview-player.js',
		'utf8'
	);
	const headerSearchSrc = fs.readFileSync(
		'musicwave/assets/header-search.js',
		'utf8'
	);
	const heroSliderSrc = fs.readFileSync(
		'musicwave/assets/hero-slider.js',
		'utf8'
	);

	const sanitizeCssForJsdom = ( cssText ) =>
		cssText.replace( /color-mix\([^;{}]+\)/g, '#222222' );
	const combinedCss = sanitizeCssForJsdom(
		[
			globalPlayerCss,
			playlistsCss,
			catalogCss,
			libraryCss,
			releasePageCss,
		].join( '\n' )
	);

	const sharedHeaderHtml = ( activeSlug ) => `
		<header class="wp-block-template-part">
			<div class="mw-site-header">
				<nav>
					<ul class="wp-block-navigation__container">
						<li class="wp-block-navigation-item${ activeSlug === 'home' ? ' current-menu-item' : '' }">
							<a id="nav-to-home" href="https://musicwave.test/"${ activeSlug === 'home' ? ' aria-current="page"' : '' }>خانه</a>
						</li>
						<li class="wp-block-navigation-item${ activeSlug === 'account' ? ' current-menu-item' : '' }">
							<a id="nav-to-account" href="https://musicwave.test/account/"${ activeSlug === 'account' ? ' aria-current="page"' : '' }>حساب کاربری</a>
						</li>
					</ul>
				</nav>
				<div class="mw-header-search-block mw-header-search-block--popup">
					<button type="button" class="mw-header-search-block__toggle" aria-expanded="false">جستجو</button>
					<div class="mw-header-search-block__popup" hidden>
						<div class="mw-header-search-block__popup-inner">
							<button type="button" class="mw-header-search-block__close">بستن</button>
							<input type="search" value="" />
							<a id="popup-search-account-link" href="https://musicwave.test/account/">نتیجه حساب کاربری</a>
						</div>
					</div>
				</div>
			</div>
		</header>`;

	const homeHtml = `<!doctype html><html dir="rtl" lang="fa-IR"><head><title>خانه — موج موسیقی</title><style>${ combinedCss }</style></head><body class="home">
		<div class="wp-site-blocks">
			${ sharedHeaderHtml( 'home' ) }
			<main id="main-content" class="wp-block-group">
				<div data-mw-hero-slider data-autoplay="1" data-interval="5000">
					<div class="mw-hero-slide is-active">۱</div>
					<div class="mw-hero-slide">۲</div>
				</div>
				<button type="button" class="mw-preview-button" id="home-track-btn" data-preview-url="https://cdn.example.test/home-ambient.mp3" data-release-title="آرامش شب" aria-pressed="false">پخش</button>
				<div class="wp-block-group mw-release-hero__specs">${ renderedFromPhp.fullSpecsHtml }</div>
				<div class="wp-block-group mw-release-actions">${ renderedFromPhp.guestLibraryHtml }${ renderedFromPhp.guestPickerHtml }</div>
			</main>
		</div>
		${ renderedFromPhp.player }
	</body></html>`;

	const accountPageHtml = `<!doctype html><html dir="rtl" lang="fa-IR"><head>
		<title>حساب کاربری — موج موسیقی</title>
		<script id="global-wp-inline-boot">
			const topLevelWpConst = 1;
			window._headInlineReexecCount = ( window._headInlineReexecCount || 0 ) + 1;
			if ( window.wp && window.wp.apiFetch ) {
				window.wp.apiFetch.use( function ( opts, next ) { return next( opts ); } );
			}
		</script>
		<script id="music-wave-library-js-extra">window.mwLibrary = { restUrl: "https://musicwave.test/wp-json/music-wave/v1/library", nonce: "fresh-soft-nonce" };</script>
		<script id="music-wave-library-js" src="https://musicwave.test/wp-content/plugins/music-wave-core/assets/library.js?ver=1.0.0" defer></script>
		<script id="music-wave-dashboard-js" src="https://musicwave.test/wp-content/plugins/music-wave-core/assets/dashboard.js?ver=1.0.0" defer></script>
	</head><body class="page page-account">
		<div class="wp-site-blocks">
			${ sharedHeaderHtml( 'account' ) }
			<main id="main-content" class="wp-block-group">${ renderedFromPhp.dashboardHtml }</main>
		</div>
		${ renderedFromPhp.routeStateHtml }
		${ renderedFromPhp.player }
	</body></html>`;

	const navDom = new JSDOM( homeHtml, {
		url: 'https://musicwave.test/',
		runScripts: 'dangerously',
		pretendToBeVisual: true,
	} );
	const nWin = navDom.window;
	const nDoc = nWin.document;

	// Track scroll position + `wp.apiFetch` middleware count + injected script loads!
	let simulatedScrollY = 0;
	Object.defineProperty( nWin, 'scrollY', {
		configurable: true,
		get: () => simulatedScrollY,
	} );
	nWin.scrollTo = ( _x, y ) => {
		simulatedScrollY = Number( y ) || 0;
	};

	const apiMiddlewares = [];
	const nonceMiddleware = { nonce: 'initial-guest-nonce' };
	nWin.wp = {
		apiFetch: Object.assign(
			async ( opts ) => ( { ok: true, opts } ),
			{
				nonceMiddleware,
				use( fn ) {
					apiMiddlewares.push( fn );
				},
			}
		),
	};

	nWin.HTMLMediaElement.prototype.load = function () {
		Object.defineProperty( this, 'readyState', {
			value: 4,
			configurable: true,
		} );
		Object.defineProperty( this, 'duration', {
			value: 180,
			configurable: true,
		} );
		this.dispatchEvent( new nWin.Event( 'loadedmetadata' ) );
	};

	let libraryLoadCount = 0;
	let dashboardLoadCount = 0;
	const origAppend = nWin.Node.prototype.appendChild;
	function maybeExecInjectedScript( node ) {
		if ( ! node || node.nodeName !== 'SCRIPT' ) {
			return;
		}
		const src = node.getAttribute && node.getAttribute( 'src' );
		if ( ! src ) {
			return;
		}
		if ( src.includes( '/library.js' ) ) {
			libraryLoadCount += 1;
			nWin._orderLog = nWin._orderLog || [];
			nWin._orderLog.push(
				'external-library:' +
					( nWin.mwLibrary && nWin.mwLibrary.nonce )
			);
			vm.runInContext( libraryScriptSrc, navDom.getInternalVMContext() );
		} else if ( src.includes( '/dashboard.js' ) ) {
			dashboardLoadCount += 1;
			vm.runInContext(
				dashboardScriptSrc,
				navDom.getInternalVMContext()
			);
		}
		if ( typeof node.onload === 'function' ) {
			node.onload();
		}
		node.dispatchEvent( new nWin.Event( 'load' ) );
	}
	nWin.Node.prototype.appendChild = function ( child ) {
		const res = origAppend.call( this, child );
		maybeExecInjectedScript( child );
		return res;
	};

	let audioPaused = true;
	Object.defineProperty( nWin.HTMLMediaElement.prototype, 'paused', {
		configurable: true,
		get: () => audioPaused,
	} );
	nWin.HTMLMediaElement.prototype.play = function () {
		audioPaused = false;
		this.dispatchEvent( new nWin.Event( 'play' ) );
		return Promise.resolve();
	};
	nWin.HTMLMediaElement.prototype.pause = function () {
		audioPaused = true;
		this.dispatchEvent( new nWin.Event( 'pause' ) );
	};

	nWin.fetch = async ( url ) => ( {
		ok: true,
		status: 200,
		url: String( url ).split( '#' )[ 0 ],
		headers: {
			get: ( h ) =>
				h.toLowerCase() === 'content-type'
					? 'text/html; charset=UTF-8'
					: null,
		},
		text: async () =>
			String( url ).includes( '/account/' ) ? accountPageHtml : homeHtml,
	} );

	await new Promise( ( r ) => setTimeout( r, 0 ) );
	vm.runInContext( runtimeSrc, navDom.getInternalVMContext() );
	vm.runInContext( previewPlayerSrc, navDom.getInternalVMContext() );
	vm.runInContext( headerSearchSrc, navDom.getInternalVMContext() );
	vm.runInContext( heroSliderSrc, navDom.getInternalVMContext() );

	const middlewareCountAfterBoot = apiMiddlewares.length;
	assert.equal(
		middlewareCountAfterBoot,
		1,
		'Runtime installs exactly one mutation prefetch-invalidation middleware on boot'
	);

	// Register a diagnostic lifecycle controller to prove deterministic mount/unmount + AbortSignal cleanup!
	const lifecycleEvents = [];
	nWin.MusicWaveApp.registerController( 'test-probe', {
		mount( ctx ) {
			const observedPath = nWin.location.pathname;
			lifecycleEvents.push( 'mount:' + observedPath );
			ctx.signal.addEventListener( 'abort', () => {
				lifecycleEvents.push( 'signal-abort:' + observedPath );
			} );
			ctx.onCleanup( () => {
				lifecycleEvents.push( 'cleanup:' + observedPath );
			} );
		},
		unmount() {
			lifecycleEvents.push( 'unmount' );
		},
	} );

	const audioEl = nDoc.querySelector( '[data-mw-preview-player] audio' );
	assert.ok( audioEl, 'Global player must create persistent <audio> element' );

	// --- Pillar 3 computed CSS parity check on guest buttons ---
	const guestLibEl = nDoc.querySelector( '.mw-library-button--guest' );
	const guestPlaylistEl = nDoc.querySelector(
		'.mw-playlist-picker__trigger--guest'
	);
	assert.ok( guestLibEl && guestPlaylistEl );
	assert.equal( nWin.getComputedStyle( guestPlaylistEl ).width, '2.75rem' );
	assert.equal( nWin.getComputedStyle( guestPlaylistEl ).height, '2.75rem' );
	assert.ok(
		[ '50%', '999px' ].includes(
			nWin.getComputedStyle( guestPlaylistEl ).borderRadius
		)
	);

	// --- Pillar 2 forward & backward scrubbing on `input.mw-global-player__volume` ---
	const globalPlayerRef = nDoc.querySelector( '[data-mw-preview-player]' );
	const persistentHeaderRef = nDoc
		.querySelector( '.mw-site-header' )
		.closest( '.wp-block-template-part' );
	const volumeSlider = nDoc.querySelector(
		'.mw-global-player__bar input.mw-global-player__volume'
	);
	assert.ok( volumeSlider, 'Volume input must exist inside .mw-global-player__bar' );
	assert.equal(
		nWin.getComputedStyle( volumeSlider ).width,
		'5.5rem',
		'Volume input width must stay constant (never jump only on hover)'
	);

	for ( const targetVol of [ 0.85, 0.32, 0.74, 0.19, 0.61, 0, 0.53, 1 ] ) {
		volumeSlider.value = String( targetVol );
		volumeSlider.dispatchEvent(
			new nWin.Event( 'input', { bubbles: true } )
		);
		volumeSlider.dispatchEvent(
			new nWin.Event( 'change', { bubbles: true } )
		);
		assert.equal(
			Number( volumeSlider.value ),
			targetVol,
			`Volume slider must hold exact float ${ targetVol } (never clamp to 1!)`
		);
		assert.equal( Number( audioEl.volume.toFixed( 2 ) ), targetVol );
		assert.equal(
			volumeSlider.getAttribute( 'aria-valuenow' ),
			String( Math.round( targetVol * 100 ) )
		);
	}

	// Start audio playback at 42.5s before soft navigation!
	nDoc.getElementById( 'home-track-btn' ).click();
	await new Promise( ( r ) => setTimeout( r, 20 ) );
	audioEl.currentTime = 42.5;
	assert.equal( audioPaused, false );

	// Watch DOM mutations across the ENTIRE session to prove neither `#mw-global-player`
	// NOR persistent `.mw-site-header` ever detaches from DOM even transiently!
	let playerDetachedEver = false;
	let headerDetachedEver = false;
	const shellObserver = new nWin.MutationObserver( ( records ) => {
		for ( const rec of records ) {
			for ( const removed of Array.from( rec.removedNodes ) ) {
				if ( removed === globalPlayerRef ) {
					playerDetachedEver = true;
				}
				if ( removed === persistentHeaderRef ) {
					headerDetachedEver = true;
				}
			}
		}
	} );
	shellObserver.observe( nDoc, { childList: true, subtree: true } );

	// Open the header search popup (which locks `body.style.overflow = 'hidden'`),
	// then click a search result link inside the popup — proving search links bubble
	// cleanly to `MusicWaveApp.router`, close the popup, restore scroll, and keep audio playing!
	nDoc.querySelector( '.mw-header-search-block__toggle' ).click();
	assert.equal( nDoc.body.style.overflow, 'hidden' );
	nDoc.getElementById( 'popup-search-account-link' ).click();
	await new Promise( ( r ) => setTimeout( r, 50 ) );

	// 1. Persistent Site Shell proof: ZERO detachment of player or header!
	assert.equal(
		playerDetachedEver,
		false,
		'#mw-global-player MUST NEVER detach from DOM even transiently during outlet swap!'
	);
	assert.equal(
		headerDetachedEver,
		false,
		'Persistent .mw-site-header MUST remain mounted in place across outlet swaps!'
	);
	assert.strictEqual(
		nDoc.querySelector( '[data-mw-preview-player]' ),
		globalPlayerRef
	);
	assert.strictEqual(
		nDoc
			.querySelector( '.mw-site-header' )
			.closest( '.wp-block-template-part' ),
		persistentHeaderRef
	);
	assert.equal(
		nDoc.body.style.overflow,
		'',
		'Header search popup scroll lock must clear automatically on route transition!'
	);
	assert.equal(
		nDoc
			.getElementById( 'nav-to-account' )
			.getAttribute( 'aria-current' ),
		'page',
		'Carried persistent header must synchronize active `aria-current="page"` state!'
	);
	assert.equal( audioPaused, false );
	assert.equal( audioEl.currentTime, 42.5 );

	// 2. Layer 4 proof: ZERO `<head>` inline script re-execution, zero middleware stacking,
	//    AND pure JSON state synchronized BEFORE external `library.js` ran!
	assert.equal(
		nWin._headInlineReexecCount || 0,
		0,
		'Global inline <head> scripts MUST NEVER re-execute on soft navigation!'
	);
	assert.equal(
		apiMiddlewares.length,
		middlewareCountAfterBoot,
		'wp.apiFetch middlewares MUST NEVER duplicate across soft navigations!'
	);
	assert.deepEqual( Array.from( nWin._orderLog ), [
		'external-library:fresh-soft-nonce',
	] );
	assert.equal( libraryLoadCount, 1 );
	assert.equal( dashboardLoadCount, 1 );

	// 3. Layer 3 proof: `.mw-user-dashboard__tabs` hydrated automatically with ZERO refresh,
	//    AND FIRST TAB IS OPEN BY DEFAULT!
	const tabs = Array.from(
		nDoc.querySelectorAll(
			'.mw-user-dashboard__tabs [data-mw-dashboard-tab]'
		)
	);
	const panels = Array.from(
		nDoc.querySelectorAll( '[data-mw-dashboard-panel]' )
	);
	assert.ok( tabs.length >= 2, 'Account dashboard must expose multiple tabs' );
	assert.equal( tabs[ 0 ].classList.contains( 'is-active' ), true );
	assert.equal( tabs[ 0 ].getAttribute( 'aria-selected' ), 'true' );
	assert.equal( panels[ 0 ].hidden, false );
	for ( let i = 1; i < panels.length; i++ ) {
		assert.equal( tabs[ i ].classList.contains( 'is-active' ), false );
		assert.equal( panels[ i ].hidden, true );
	}

	// 4. Clicking second tab opens second panel cleanly without router interference or losing history.state!
	tabs[ 1 ].click();
	assert.equal( tabs[ 0 ].classList.contains( 'is-active' ), false );
	assert.equal( tabs[ 1 ].classList.contains( 'is-active' ), true );
	assert.equal( panels[ 0 ].hidden, true );
	assert.equal( panels[ 1 ].hidden, false );
	assert.ok(
		nWin.history.state && nWin.history.state.mwNav === true,
		'Dashboard tab click must preserve router history.state!'
	);

	// 5. Re-clicking active tab keeps it open (never collapses all panels to blank!).
	tabs[ 1 ].click();
	assert.equal( panels[ 1 ].hidden, false );

	// 6. Scroll to 380px on `/account/`, soft-navigate Home, then soft-navigate back via `popstate`
	//    AND via deep-link `/account/` round-trip — proving scroll restoration + 0 duplicate script loads!
	simulatedScrollY = 380;
	nDoc.getElementById( 'nav-to-home' ).click();
	await new Promise( ( r ) => setTimeout( r, 45 ) );
	assert.equal( simulatedScrollY, 0, 'Forward navigation scrolls cleanly to top' );

	// Browser Back (`popstate`) to `/account/` restores exact `scrollY = 380`!
	nWin.history.back();
	await new Promise( ( r ) => setTimeout( r, 60 ) );
	assert.equal(
		simulatedScrollY,
		380,
		'Browser Back (popstate) must restore exact saved scrollY of previous entry!'
	);
	assert.equal(
		libraryLoadCount,
		1,
		'External library.js must NEVER reload on subsequent soft navigations!'
	);
	assert.equal(
		dashboardLoadCount,
		1,
		'External dashboard.js must NEVER reload on subsequent soft navigations!'
	);

	const tabsRound2 = Array.from(
		nDoc.querySelectorAll(
			'.mw-user-dashboard__tabs [data-mw-dashboard-tab]'
		)
	);
	const panelsRound2 = Array.from(
		nDoc.querySelectorAll( '[data-mw-dashboard-panel]' )
	);
	assert.ok( tabsRound2.length >= 2 );
	assert.equal(
		panelsRound2.some( ( p ) => p.hidden === false ),
		true
	);
	assert.equal( playerDetachedEver, false );
	assert.equal( headerDetachedEver, false );
	assert.equal( audioPaused, false );
	assert.equal( audioEl.currentTime, 42.5 );
	assert.ok( lifecycleEvents.includes( 'mount:/account/' ) );
	assert.ok( lifecycleEvents.includes( 'signal-abort:/' ) );
	assert.ok( lifecycleEvents.includes( 'cleanup:/' ) );
	assert.ok( lifecycleEvents.includes( 'unmount' ) );

	shellObserver.disconnect();
}

// =========================================================================
// 7. HORIZONTAL SHELF `.mw-release-shelf__nav-button` (RTL + LTR + ResizeObserver
//    + Auto-Injected Core Shelves + Keyboard Focus Transfer) & HERO `inert` A11Y
//    + `wp-api-fetch` Lazy Load on Soft Navigation ("بدون رفرش")
// =========================================================================
{
	const sliderCode = fs.readFileSync( 'musicwave/assets/slider.js', 'utf8' );
	const heroCode = fs.readFileSync(
		'musicwave/assets/hero-slider.js',
		'utf8'
	);
	const runtimeCode = fs.readFileSync(
		'music-wave-core/assets/runtime.js',
		'utf8'
	);

	// 7A. RTL Shelf + Off-Screen ResizeObserver Activation + Auto-Enhanced Core Shelf + Hero Slider `inert`
	const dom = new JSDOM(
		`<!doctype html><html dir="rtl" lang="fa-IR"><head><title>خانه</title></head><body>
		<div class="wp-site-blocks">
			<header class="wp-block-template-part"><a id="nav-to-release" href="/release/night-drive/">پخش شبانه</a></header>
			<main>
				<!-- Shelf 1: Standard SSR horizontal scroll shelf -->
				<section id="shelf-rtl" class="mw-release-shelf mw-release-shelf--scroll">
					<div class="mw-release-shelf__nav">
						<button type="button" class="mw-release-shelf__nav-button" data-mw-shelf-previous aria-controls="vp-rtl" aria-label="قبلی" disabled></button>
						<button type="button" class="mw-release-shelf__nav-button" data-mw-shelf-next aria-controls="vp-rtl" aria-label="بعدی" disabled></button>
					</div>
					<div id="vp-rtl" class="mw-release-shelf__items" data-mw-shelf-viewport tabindex="0" role="region" aria-label="جدیدترین‌ها">
						<article class="mw-release-shelf__item">1</article>
						<article class="mw-release-shelf__item">2</article>
						<article class="mw-release-shelf__item">3</article>
						<article class="mw-release-shelf__item">4</article>
					</div>
					<p class="screen-reader-text" aria-live="polite" data-mw-shelf-status></p>
				</section>

				<!-- Shelf 2: Off-screen unmeasured shelf whose scrollWidth starts at 0 and expands via ResizeObserver -->
				<section id="shelf-lazy" class="mw-release-shelf mw-release-shelf--scroll">
					<div class="mw-release-shelf__nav">
						<button type="button" class="mw-release-shelf__nav-button" data-mw-shelf-previous aria-controls="vp-lazy" aria-label="قبلی" disabled></button>
						<button type="button" class="mw-release-shelf__nav-button" data-mw-shelf-next aria-controls="vp-lazy" aria-label="بعدی" disabled></button>
					</div>
					<div id="vp-lazy" class="mw-release-shelf__items" data-mw-shelf-viewport tabindex="0" role="region" aria-label="پیشنهادی">
						<article class="mw-release-shelf__item">A</article>
						<article class="mw-release-shelf__item">B</article>
					</div>
				</section>

				<!-- Shelf 3: Bare mw-release-shelf--scroll without server buttons -->
				<section id="shelf-bare" class="mw-release-shelf mw-release-shelf--scroll">
					<div id="vp-bare" class="mw-release-shelf__items">
						<article class="mw-release-shelf__item">X</article>
						<article class="mw-release-shelf__item">Y</article>
					</div>
				</section>

				<!-- Hero Slider with 2 slides verifying inert and aria-hidden sync -->
				<div id="hero-1" class="mw-hero-slider" data-mw-hero-slider data-autoplay="0" data-pause-hover="1" data-interval="5000">
					<div class="mw-hero-slider__viewport" tabindex="0">
						<article class="mw-hero-slide is-active" role="group" aria-label="Slide 1"><a href="/a/">A</a></article>
						<article class="mw-hero-slide" role="group" aria-label="Slide 2" aria-hidden="true" inert><a href="/b/">B</a></article>
					</div>
					<button type="button" data-mw-hero-previous aria-label="قبلی"></button>
					<button type="button" data-mw-hero-next aria-label="بعدی"></button>
					<div data-mw-hero-dots>
						<button type="button" class="mw-hero-slider__dot is-active" data-mw-hero-dot="0" aria-current="true"></button>
						<button type="button" class="mw-hero-slider__dot" data-mw-hero-dot="1"></button>
					</div>
					<p data-mw-hero-status></p>
				</div>
			</main>
		</div>
		<div id="mw-global-player" data-mw-preview-player><audio src="https://cdn.example.test/live.mp3"></audio></div>
		<script type="application/json" id="mw-route-state">{"restUrl":"https://musicwave.test/wp-json/","restNonce":"init-nonce","loggedIn":true,"releaseId":0,"configs":{}}</script>
		</body></html>`,
		{
			url: 'https://musicwave.test/',
			runScripts: 'dangerously',
		}
	);
	await new Promise( ( r ) => setTimeout( r, 0 ) );

	const win = dom.window;
	const doc = win.document;

	// Realistic browser geometry + ResizeObserver mock
	const resizeCallbacks = [];
	win.ResizeObserver = class MockResizeObserver {
		constructor( cb ) {
			this.cb = cb;
			resizeCallbacks.push( cb );
		}
		observe() {}
		disconnect() {}
	};

	const vpRtl = doc.getElementById( 'vp-rtl' );
	Object.defineProperties( vpRtl, {
		clientWidth: { value: 600, configurable: true },
		scrollWidth: { value: 1200, configurable: true },
	} );
	Array.from( vpRtl.children ).forEach( ( item, idx ) => {
		item.getBoundingClientRect = () => ( {
			left: 600 - idx * 300,
			right: 900 - idx * 300,
			width: 280,
			height: 280,
			top: 0,
			bottom: 280,
		} );
	} );
	vpRtl.scrollTo = ( opts ) => {
		vpRtl.scrollLeft = opts.left;
		vpRtl.dispatchEvent( new win.Event( 'scroll' ) );
	};

	let lazyScrollWidth = 600;
	const vpLazy = doc.getElementById( 'vp-lazy' );
	Object.defineProperties( vpLazy, {
		clientWidth: { value: 600, configurable: true },
		scrollWidth: { get: () => lazyScrollWidth, configurable: true },
	} );
	vpLazy.scrollTo = ( opts ) => {
		vpLazy.scrollLeft = opts.left;
		vpLazy.dispatchEvent( new win.Event( 'scroll' ) );
	};

	const vpBare = doc.getElementById( 'vp-bare' );
	Object.defineProperties( vpBare, {
		clientWidth: { value: 500, configurable: true },
		scrollWidth: { value: 1100, configurable: true },
	} );
	vpBare.scrollTo = ( opts ) => {
		vpBare.scrollLeft = opts.left;
		vpBare.dispatchEvent( new win.Event( 'scroll' ) );
	};

	// Track lazy loading of `/wp-includes/js/dist/api-fetch.min.js` on soft navigation!
	let apiFetchLoaded = false;
	const origAppendChild = win.HTMLHeadElement.prototype.appendChild;
	win.HTMLHeadElement.prototype.appendChild = function ( node ) {
		const res = origAppendChild.call( this, node );
		if (
			node &&
			node.tagName === 'SCRIPT' &&
			node.src &&
			node.src.includes( '/wp-includes/js/dist/api-fetch.min.js' )
		) {
			apiFetchLoaded = true;
			let activeRoot = '';
			const middlewares = [];
			win.wp = win.wp || {};
			win.wp.apiFetch = {
				use( mw ) {
					middlewares.push( mw );
				},
				createRootURLMiddleware( root ) {
					activeRoot = root;
					return function () {};
				},
				createNonceMiddleware( nonce ) {
					return { nonce };
				},
				getConfiguredRoot() {
					return activeRoot;
				},
			};
			node.dispatchEvent( new win.Event( 'load' ) );
		}
		return res;
	};

	win.scrollTo = () => {};

	win.fetch = ( input ) => {
		const url = String( input );
		return Promise.resolve( {
			status: 200,
			url,
			headers: { get: () => 'text/html; charset=UTF-8' },
			text: () =>
				Promise.resolve( `<!doctype html><html dir="rtl" lang="fa-IR"><head>
				<title>پخش شبانه</title>
				<script src="https://musicwave.test/wp-includes/js/dist/api-fetch.min.js"></script>
			</head><body>
				<div class="wp-site-blocks">
					<header class="wp-block-template-part"><a id="nav-to-release" href="/release/night-drive/">پخش شبانه</a></header>
					<main><h1 id="release-h1">پخش شبانه</h1></main>
				</div>
				<div id="mw-global-player" data-mw-preview-player><audio src="https://cdn.example.test/live.mp3"></audio></div>
				<script type="application/json" id="mw-route-state">{"restUrl":"https://musicwave.test/wp-json/","restNonce":"fresh-soft-nonce-999","loggedIn":true,"releaseId":77,"configs":{"musicWaveDownload":{"errorMessage":"خطا }; آزمایشی"}}}</script>
			</body></html>` ),
		} );
	};

	vm.runInContext( runtimeCode, dom.getInternalVMContext() );
	vm.runInContext( sliderCode, dom.getInternalVMContext() );
	vm.runInContext( heroCode, dom.getInternalVMContext() );
	await new Promise( ( r ) => setTimeout( r, 20 ) );

	// Verify RTL Shelf 1 prev/next buttons!
	const shelfRtl = doc.getElementById( 'shelf-rtl' );
	const prevBtn = shelfRtl.querySelector( '[data-mw-shelf-previous]' );
	const nextBtn = shelfRtl.querySelector( '[data-mw-shelf-next]' );
	assert.equal(
		shelfRtl.classList.contains( 'mw-release-shelf--scrollable' ),
		true
	);
	assert.equal( prevBtn.disabled, true );
	assert.equal( nextBtn.disabled, false );

	// Focus next button, click next -> scrolls negative in RTL (`-600`), enables `prevBtn`, disables `nextBtn`, and moves focus cleanly to `prevBtn`!
	nextBtn.focus();
	nextBtn.click();
	await new Promise( ( r ) => setTimeout( r, 25 ) );
	assert.equal( vpRtl.scrollLeft, -600 );
	assert.equal( prevBtn.disabled, false );
	assert.equal( nextBtn.disabled, true );
	assert.equal( doc.activeElement, prevBtn );

	// Click previous -> returns cleanly to `0`!
	prevBtn.click();
	await new Promise( ( r ) => setTimeout( r, 25 ) );
	assert.equal( vpRtl.scrollLeft, 0 );
	assert.equal( prevBtn.disabled, true );
	assert.equal( nextBtn.disabled, false );

	// Verify Off-Screen Shelf 2 activates automatically via ResizeObserver without page refresh!
	const shelfLazy = doc.getElementById( 'shelf-lazy' );
	const lazyNext = shelfLazy.querySelector( '[data-mw-shelf-next]' );
	assert.equal( lazyNext.disabled, true );
	lazyScrollWidth = 1400;
	resizeCallbacks.forEach( ( cb ) => cb() );
	assert.equal(
		shelfLazy.classList.contains( 'mw-release-shelf--scrollable' ),
		true
	);
	assert.equal( lazyNext.disabled, false );
	lazyNext.click();
	await new Promise( ( r ) => setTimeout( r, 25 ) );
	assert.ok( vpLazy.scrollLeft < 0 );

	// Verify Bare Core Shelf 3 automatically received `.mw-release-shelf__nav-button` arrows and scrolls on click!
	const shelfBare = doc.getElementById( 'shelf-bare' );
	const bareNext = shelfBare.querySelector(
		'.mw-release-shelf__nav-button[data-mw-shelf-next]'
	);
	assert.ok( bareNext, 'Bare scroll shelf must auto-receive .mw-release-shelf__nav-button!' );
	assert.equal( bareNext.disabled, false );
	bareNext.click();
	await new Promise( ( r ) => setTimeout( r, 25 ) );
	assert.ok( vpBare.scrollLeft < 0 );

	// Verify Hero Slider keeps `inert` & `aria-hidden` strictly synced across transitions!
	const slides = doc.querySelectorAll( '#hero-1 .mw-hero-slide' );
	assert.equal( slides[ 0 ].hasAttribute( 'inert' ), false );
	assert.equal( slides[ 1 ].hasAttribute( 'inert' ), true );
	doc.querySelector( '#hero-1 [data-mw-hero-next]' ).click();
	assert.equal( slides[ 0 ].getAttribute( 'aria-hidden' ), 'true' );
	assert.equal( slides[ 0 ].hasAttribute( 'inert' ), true );
	assert.equal( slides[ 1 ].hasAttribute( 'aria-hidden' ), false );
	assert.equal( slides[ 1 ].hasAttribute( 'inert' ), false );

	// Verify Soft Navigation ("بدون رفرش") loads missing `wp-api-fetch` AND configures root + nonce middleware!
	await win.MusicWaveApp.navigate( '/release/night-drive/' );
	await new Promise( ( r ) => setTimeout( r, 25 ) );
	assert.equal(
		doc.getElementById( 'release-h1' )?.textContent,
		'پخش شبانه'
	);
	assert.equal(
		apiFetchLoaded,
		true,
		'Soft navigation must load missing wp-api-fetch when absent on initial landing page!'
	);
	assert.equal(
		win.wp.apiFetch.nonceMiddleware.nonce,
		'fresh-soft-nonce-999'
	);
	assert.equal(
		win.wp.apiFetch.getConfiguredRoot(),
		'https://musicwave.test/wp-json/'
	);
	assert.equal(
		win.musicWaveDownload.errorMessage,
		'خطا }; آزمایشی'
	);
}

// =========================================================================
// 8. Responsive `music-wave/header-search` Popup Modal, `/` Shortcut & Tabs E2E
// =========================================================================
{
	const headerSearchPhp = `
		define('ABSPATH', __DIR__ . '/');
		function __( $t ) { return $t; }
		function _x( $t ) { return $t; }
		function esc_html( $t ) { return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); }
		function esc_html__( $t ) { return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); }
		function esc_attr( $t ) { return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); }
		function esc_attr__( $t ) { return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); }
		function esc_url( $t ) { return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); }
		function sanitize_key( $k ) { return preg_replace('/[^a-z0-9_\\-]/', '', strtolower((string)$k)); }
		function sanitize_html_class( $c ) { return preg_replace('/[^A-Za-z0-9_\\-]/', '', (string)$c); }
		function sanitize_text_field( $s ) { return trim(strip_tags((string)$s)); }
		function home_url( $p = '/' ) { return 'https://musicwave.test' . $p; }
		function get_search_query() { return ''; }
		function get_terms() { return array(); }
		function taxonomy_exists() { return false; }
		function apply_filters( $tag, $value ) { return $value; }
		function wp_unique_id( $prefix = '' ) { static $n = 0; $n++; return $prefix . $n; }
		function get_block_wrapper_attributes( $extra = array() ) {
			$out = array();
			foreach ( $extra as $k => $v ) {
				$out[] = $k . '="' . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '"';
			}
			return implode(' ', $out);
		}
		require 'musicwave/src/Autoloader.php';
		ManaCore\\MusicWave\\Theme\\Autoloader::register( __DIR__ . '/musicwave/src' );
		$renderer = new ManaCore\\MusicWave\\Theme\\Blocks\\ChromeBlocksRenderer();
		echo $renderer->render_header_search( array(
			'desktopMode' => 'bar',
			'tabletMode'  => 'compact',
			'mobileMode'  => 'popup',
			'shape'       => 'pill',
			'size'        => 'md',
		) );
	`;
	const renderedSearchHtml = execFileSync( PHP_BIN, [ '-r', headerSearchPhp ], {
		encoding: 'utf8',
	} );
	const headerSearchJs = fs.readFileSync(
		'musicwave/assets/header-search.js',
		'utf8'
	);

	const searchDom = new JSDOM(
		`<!doctype html><html dir="rtl" lang="fa-IR"><body>${ renderedSearchHtml }<div id="mw-global-player"><audio src="https://cdn.example.test/live.mp3"></audio></div></body></html>`,
		{ url: 'https://musicwave.test/', runScripts: 'outside-only' }
	);
	await new Promise( ( r ) => setTimeout( r, 0 ) );
	const swin = searchDom.window;
	const sdoc = swin.document;
	swin.eval( headerSearchJs );

	const openTrigger = sdoc.querySelector( '.mw-header-search-block__toggle' );
	const paletteBtn = sdoc.querySelector( '[data-mw-search-palette-trigger]' );
	const modal = sdoc.querySelector( '.mw-header-search-block__popup' );
	const inlineInput = sdoc.querySelector( '[data-mw-search-input]' );
	const dialogInput = sdoc.querySelector( '[data-mw-search-dialog-input]' );
	assert.ok( openTrigger && paletteBtn && modal && inlineInput && dialogInput );

	// 8A. Desktop (1280px): Ctrl+K badge trigger opens Command Palette modal cleanly
	swin.innerWidth = 1280;
	paletteBtn.click();
	await new Promise( ( r ) => setTimeout( r, 25 ) );
	assert.equal( modal.hidden, false );
	sdoc.dispatchEvent(
		new swin.KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } )
	);
	assert.equal( modal.hidden, true );

	// 8B. Mobile (375px): Search icon button opens floating popup dialog cleanly
	swin.innerWidth = 375;
	openTrigger.click();
	await new Promise( ( r ) => setTimeout( r, 25 ) );
	assert.equal( modal.hidden, false );
	assert.equal( openTrigger.getAttribute( 'aria-expanded' ), 'true' );
	assert.equal(
		sdoc.documentElement.hasAttribute( 'data-mw-search-open' ),
		true
	);

	// Scope tab click updates active state & syncs post_type hidden input
	const tabs = modal.querySelectorAll( '[data-mw-search-scope]' );
	assert.ok( tabs.length >= 2 );
	tabs[ 1 ].click();
	assert.equal( tabs[ 1 ].getAttribute( 'aria-selected' ), 'true' );

	// Clicking a curated quick discovery tag populates the search dialog input immediately
	const firstTag = modal.querySelector( '[data-mw-search-tag]' );
	assert.ok( firstTag );
	firstTag.click();
	assert.equal( dialogInput.value, firstTag.getAttribute( 'data-mw-search-tag' ) );

	// Escape closes popup dialog, restores clean document state, and leaves #mw-global-player untouched
	sdoc.dispatchEvent(
		new swin.KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } )
	);
	assert.equal( modal.hidden, true );
	assert.equal( openTrigger.getAttribute( 'aria-expanded' ), 'false' );
	assert.equal(
		sdoc.documentElement.hasAttribute( 'data-mw-search-open' ),
		false
	);
	assert.equal(
		sdoc.querySelector( '#mw-global-player audio' )?.getAttribute( 'src' ),
		'https://cdn.example.test/live.mp3'
	);
	// Verify full-viewport body portal while open & clean docking back into block on close
	openTrigger.click();
	await new Promise( ( r ) => setTimeout( r, 25 ) );
	assert.strictEqual(
		modal.parentNode,
		sdoc.body,
		'Open header-search popup + backdrop MUST portal directly onto document.body to cover full viewport outside <header> backdrop-filter!'
	);
	sdoc.dispatchEvent(
		new swin.KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } )
	);
	assert.strictEqual(
		modal.parentNode,
		sdoc.querySelector( '.mw-header-search-block' ),
		'Closed header-search popup MUST dock back inside .mw-header-search-block for persistent SPA shell parity!'
	);

	// Verify `.mw-header-search-block__field-icon` is a real clickable submit <button>
	const inlineFieldIcon = sdoc.querySelector(
		'.mw-header-search-block__inline button.mw-header-search-block__field-icon[type="submit"]'
	);
	assert.ok(
		inlineFieldIcon,
		'.mw-header-search-block__field-icon MUST be a real clickable <button type="submit">!'
	);

	// Verify `collection-list` per-row quality `<select class="mw-download-quality">` dynamically drives download & stream quality
	const rowContainer = sdoc.createElement( 'li' );
	rowContainer.className = 'mw-collection-list__row';
	rowContainer.innerHTML =
		'<div class="mw-collection-list__actions">' +
		'<select class="mw-download-quality mw-collection-list__quality-select">' +
		'<option value="mp3_320" selected>MP3 320 kbps</option>' +
		'<option value="flac">FLAC Lossless</option>' +
		'</select>' +
		'<button type="button" class="mw-collection-list__action mw-download-button" data-release-id="99" data-file-id="main">دانلود</button>' +
		'</div>';
	sdoc.body.appendChild( rowContainer );
	swin.eval( fs.readFileSync( 'music-wave-core/assets/download.js', 'utf8' ) );
	const rowDownloadBtn = rowContainer.querySelector( '.mw-download-button' );
	const rowQualitySelect = rowContainer.querySelector(
		'.mw-download-quality'
	);
	assert.equal(
		swin._mwDownloadControls.selectedQuality( rowDownloadBtn ),
		'mp3_320'
	);
	rowQualitySelect.value = 'flac';
	assert.equal(
		swin._mwDownloadControls.selectedQuality( rowDownloadBtn ),
		'flac',
		'Changing per-track quality selector in collection-list MUST dynamically drive selected download/stream quality!'
	);

	swin.close();
}

console.log(
	'OK: All browser runtime end-to-end tests passed against REAL PHP HTML!'
);
