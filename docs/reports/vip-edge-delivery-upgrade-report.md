# گزارش ارتقای تحویل لبه: آپلود خودکار به سرور دانلود و HLS رمزنگاریشده (ADR 0006)

> نسخه Core `0.16.0` · نسخه VIP `0.5.2` · تاریخ اجرا: ۳۰ اکتبر ۲۰۲۶
> سند مرجع: `docs/vip-edge-delivery-upgrade-spec-fa.md` · ADR: `docs/adr/0006-edge-signed-delivery-and-encrypted-hls.md`

## ۱. خلاصهٔ اجرایی

ارتقای تحویل امن فایل در MusicWave، مطابق سند ADR 0006، پیاده و راستیآزمایی شد. تغییرات شامل:

- سه حالت تحویل جدید و اختیاری: `edge_signed`، `proxy`، و `edge_hls` — در کنار `local`/`remote_redirect` که **دستنخورده** ماندند.
- اتصال تحویل به **نشست کاربر**: کوکی `HttpOnly` + `Secure` + `SameSite=Lax`، TTL کوتاه، سقف همزمانی، و مسیرهای `session`/`heartbeat`/`close`.
- **HLS رمزنگاریشدهٔ AES-128**: کلید فقط از سایت و وابسته به نشست معتبر صادر میشود؛ کلید هرگز به CDN نمیرود.
- **آپلود خودکار به سرور دانلود خارجی** با الگوی آداپتور (`Webhook`/`S3Compatible`/`Webdav`/`Sftp`) پشت یک صف پسزمینهٔ idempotent و خودتکرارشونده.
- **تحویل محلی خودترمیمگر**: ترمیم خودکار ریشهٔ محافظتشده و تازهسازی خودکار checksum کهنه.
- همهچیز **opt-in** و از پنل تنظیمات؛ بدون فعالسازی هیچ رفتاری تغییر نمیکند (سازگاری عقبرو).
- هیچ خطای PHPCS و PHPStan باقی نماند؛ همهٔ تستهای موجود سبز.

## ۲. تحویل بهازای فاز (بخش ۱۱ سند)

| فاز | تحویلشده | شاهد کد |
|---|---|---|
| F0 — تحویل محلی هوشمند | تشخیص خودکار offload، خودترمیمی ریشه، تازهسازی checksum | `Delivery/LocalDeliveryDiagnostics.php`, `Delivery/ProtectedDeliveryDiagnostics.php` |
| F1 — نشست | `StreamSession`, `DbStreamSessionStore`, `StreamSessionCookie`, مسیرهای session/heartbeat/close | `src/Playback/`, `Downloads/SegmentSigner.php` |
| F2 — لبهٔ امضاشده | `EdgeProvider`, `EdgeConnectionManager`, تست اتصال | `EdgeProvider.php`, `Delivery/EdgeConnectionManager.php` |
| F3 — پروکسی | ارائهٔ فایل محافظتشده از دامنهٔ سایت (X-Sendfile/X-Accel) | `ProviderFactory::LOCAL_MODES`, `ProtectedFileProvider` |
| F4 — HLS + AES | `HlsPackager`, `AssetKeyStore`, `HlsRoutes`, `StreamKeyEndpoint`, `StreamUrl`, `SessionGate`, `HlsDiagnostics` | `src/Hls/` |
| F5 — UI/راهنما | گروههای تنظیمات لبه/نشست/HLS/آپلود/محلی با راهنمای فارسی | `SettingsPage.php`, `VipSettings.php` |
| F6 — آپلود خودکار | `RemoteUploadManager`, `RemoteUploadQueue`, `RemoteUploadRoutes`, چهار آداپتور, `SecretBoxCipher` | `src/Delivery/`, `Support/SecretBoxCipher.php` |

## ۳. تأیید امنیتی

- **کلید AES روی CDN نمیرود:** فقط `StreamKeyEndpoint` (متصل به `SessionGate`) کلید را برای نشست معتبر صادر میکند؛ قطعهٔ دانلودشده بدون کلید بیارزش است.
- **رازها سربسته و بیلاگ:** راز لبه/آپلود/استریم با `sodium_crypto_secretbox` + HKDF بستهبندی میشوند (`SecretBoxCipher`) و هرگز لاگ نمیشوند؛ `wrap()` روی کلید نامعتبر fail-closed میشود.
- **fail-closed در حالتهای لبه:** اگر راز/آدرس بیرونی نباشد، به تحویل محلی محافظتشده فرود میآید؛ هرگز URL عمومی بدون امضا صادر نمیشود.
- **کوکی نشست:** `HttpOnly` + `Secure` + `SameSite=Lax` و host-only.
- **اعتبارسنجی آپلود:** فقط HTTPS با `DirectLinkUrlValidator`؛ IP خصوصی/loopback/metadata و userinfo رد میشوند.
- **نشست با nonce وردپرس:** ساخت/تمدید/بستن نشست در برابر CSRF محافظت میشود.

## ۴. رفع اشکالات کشفشده در جریان راستیآزمایی

| # | اشکال | رفع |
|---|--------|-----|
| 1 | `trim(): null given` در `HlsPackager::binary_available()` (خروجی `shell_exec` میتواند null باشد) | محافظ `is_string()` + پروب `where`/`command -v` + ریدایرکت stderr |
| 2 | `EdgeConnectionManager`: متغیر تعریفنشدهٔ `$error_probe` | مقدارگذاری اولیه پیش از فراخوانی پروب |
| 3 | `RemoteUploadManager::mark_synced()` با امضای ۳-آرگومانی منسوخ | امضا و هر دو محل فراخوانی بهروز شد (حذف پارامتر بیاستفادهٔ `$checksum`) |
| 4 | `WP_REST_Request::get_url()` وجود ندارد → راستیآزمایی امضای manifest/variant/segment/key هرگز موفق نمیشد | کلاس جدید `Hls/StreamUrl` (تکمنبع حقیقت آدرسهای HLS) |
| 5 | `AssetKeyStore` پیش از مهاجرت schema روی جدول ناموجود کوئری میزد | محافظ فقطخواندنی `table_exists()` در `has_any_key()` |

## ۵. نتیجهٔ راستیآزمایی (اجرای محلی)

| دستور | نتیجه |
|--------|--------|
| `php tools/check-syntax.php` | سبز (۴۷۱ فایل PHP) |
| `php tools/check-strict-types.php` | سبز |
| `php tools/check-abspath.php` | سبز (۴۳۲ فایل، ۱۱۱ دایرکتوری) |
| `php tools/check-nul-bytes.php` | سبز |
| `phpcs --standard=phpcs.xml …` | **۰ خطا، ۰ هشدار** |
| `phpstan analyse` (سطح ۵) | **بدون خطا** |
| `tests/run.php` و ۶ تست دیگر | سبز (شامل feed-regression ۱۵ بررسی، protected-asset-storage ۳۱ بررسی، card-markup ۳۲ حالت، wordpress-runtime-audit ۴۳۲ فایل/۱۱۶ رندر) |
| `php tools/check-js.php` | سبز |
| `php tools/check-script-translations.php` | سبز |
| `php tools/check-site-editor.php` | سبز |

## ۶. سازگاری عقبرو و بازگشت

- `local` و `remote_redirect` بیتغییر؛ شناسههای `vip:<32hex>` و `local:` همچنان resolve میشوند.
- جداول فقط **افزودنی** توسعه یافتند (`dbDelta`)؛ بدون rename مخرب.
- مالکیت لایهها حفظ شد: `mw_stream_sessions` با Core، `mw_vip_asset_keys`/`mw_vip_upload_queue`/ستونهای HLS با VIP؛ Core هیچ کلاس VIP را لود نمیکند.
- بازگشت به `0.15.0`/`0.5.1` ممکن است چون schema تغییر مخربی نداشته و فیلترهای عمومی با امضای فعلی باقی ماندهاند.

## ۷. بدهی و پیشنهاد آینده

1. سنجش کارایی HLS/proxy روی محیط استیج واقعی با ffmpeg نصبشده.
2. رابط کامل چرخش راز لبه در UI (بکاند آماده است).
3. موارد خارج از دامنهٔ عمدی: DRM، واترمارک فورنزیک، ABR پیشرفته (بخش ۱۲ سند).

## ۸. فاز ارتقای رابط، سخت‌سازی و هماهنگی (اجرای نهایی)

رابط تنظیمات از یک لیست گزینه به یک «مرکز کنترل» هماهنگ تبدیل شد و چند بدهی پنهان بسته شد:

- **یک منبع داده برای حالت‌ها:** `mode_labels()` / `mode_guides()` / `mode_order()` تنها منبع
  نام و توضیح حالت‌ها هستند؛ سلکتور، راهنمای زندهٔ زیر آن و جدول «راهنمای سریع» از همین داده
  ساخته می‌شوند، پس هرگز نمی‌توانند با هم اختلاف پیدا کنند.
- **بخش‌های بدون شماره و بستهٔ یک‌دست:** ۸ کارت (مخزن، تحویل محلی، لبه/CDN، راهدور، نشست‌ها،
  HLS، آپلود خودکار، تحویل محلی هوشمند) با آیکن، برچسب «ضروری/اختیاری»، برچسب «فعال/بی‌اثر» و
  خط «مربوط به حالت‌های …»؛ بخش بی‌ربط جمع می‌شود ولی سرتیترش می‌ماند. ردیف حالت انتخاب‌شده در
  جدول راهنما زنده پررنگ می‌شود.
- **مخزن وابسته به حالت:** کارت مخزن/سلامت برای `local`، `proxy`، `edge_signed` و `edge_hls`
  باز است و برای `remote_redirect` تنها وقتی «آپلود خودکار» روشن باشد (وگرنه جمع).
- **کنترل کلید به‌ازای هر فیلد:** دکمهٔ «ایجاد» فقط برای رازهایی که MusicWave مالک آن‌هاست
  (کلید امضای لبه/راهدور، توکن هدر مبدأ، توکن Webhook)؛ کلید S3 و رمز WebDAV/SFTP با یادداشت
  «از کجا بیاورم» و بدون دکمه. `sftp_host_fingerprint` و نام کاربری WebDAV هم به UI اضافه شدند.
- **رفع باگ ارسال مقدار خالی:** ردیف‌های اعتبارنامهٔ حالت‌های دیگر `disabled` می‌شوند تا مقدار
  خالی‌شان توکن تایپ‌شدهٔ ردیف فعال را بازنویسی نکند (کلیدهای مشترک `remote_upload_endpoint`
  / `remote_upload_auth_token` / `remote_upload_access_key`).
- **رفع باگ لینک دانلود `edge_hls`** (مسیر `/hls` → `/s`)، **اجبار `hls_enabled`** در
  `edge_hls`، **نگهبان worker** برای ردیف‌های گیرافتاده، و **تک‌مالکیتی منطق clear/keep کلید**
  در `VipSettings::sanitize()`.
- **حذف کد مرده:** `mode_inactive_note`، گروه‌های پنهان‌شوندهٔ آدهاک، و هندلر قدیمی راز راهدور
  حذف شدند؛ ترجمهٔ انگلیسی (`en_US`) هم برای همهٔ رشته‌های جدید تکمیل شد.
- **مستندات همراه:** `docs/vip-buyer-guide-fa.md` (راهنمای خریدار)،
  `docs/vip-settings-testing-guide-fa.md` (چک‌لیست تست) و به‌روزرسانی
  `docs/vip-delivery-settings-guide-fa.md`.

### نتیجهٔ راستی‌آزمایی نهایی

| دستور | نتیجه |
|--------|--------|
| `php tools/check-syntax.php` | سبز (۴۷۲ فایل PHP) |
| `php tools/check-abspath.php` | سبز (۴۳۳ فایل، ۱۱۱ دایرکتوری) |
| `php tools/check-strict-types.php` | سبز |
| `php tools/check-nul-bytes.php` | سبز |
| `phpcs --standard=phpcs.xml music-wave-core music-wave-vip musicwave` | **۰ خطا** |
| `phpstan analyse` (سطح ۵) | **بدون خطا** |
| `tests/run.php` + تست‌های Core/VIP | سبز |
| `php tools/check-js.php` | سبز |
| `php tools/check-translation-coverage.php` | **سبز** (۳۴۷۳ رشته) |
| `php tools/check-script-translations.php` · `check-site-editor.php` | سبز |
