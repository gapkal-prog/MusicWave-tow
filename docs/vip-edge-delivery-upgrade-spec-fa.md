# سند ارتقاء کامل: تحویل امن فایل با لبهٔ امضاشده، آپلود خودکار به سرور دانلود و HLS رمزنگاری‌شده (AES-128)

> **وضعیت:** پذیرفتهشده و پیادهسازیشده (Accepted & Implemented) — ADR 0006: `docs/adr/0006-edge-signed-delivery-and-encrypted-hls.md`؛ گزارش: `docs/reports/vip-edge-delivery-upgrade-report.md`.
> **دامنه:** افزونهٔ هسته (`music-wave-core`) + افزونهٔ VIP (`music-wave-vip`) + پوستهٔ `musicwave`.
> **مبنای صحت:** تمام نام فایل‌ها، کلاس‌ها، فیلترها، تنظیمات و مسیرهای «وضعیت فعلی» از کد واقعی همین مخزن استخراج شده‌اند. بخش‌های «جدید» صریحاً با برچسب **[جدید]** مشخص شده‌اند.
> **هدف محصول:** این سند برای محصولی است که خریدار آن را روی وردپرس خودش نصب می‌کند و باید **همه‌چیز را از بخش تنظیمات** بی‌کدنویسی انتخاب و پیکربندی کند، با راهنمای سادهٔ فارسی برای هر گزینه، بدون نیاز به هزینهٔ غیرمنطقی (بدون DRM، بدون سرویس اشتراکی اجباری).

---

## ۰. چکیدهٔ اجرایی

هدف این ارتقاء چهار چیز است:

1. در حالت «سرور خارجی / CDN»، آدرس واقعی فایل هرگز به کاربر نرسد، لینک بعد از مدت کوتاه بمیرد و قابل اشتراک نباشد — بدون DRM.
2. وقتی حالت تحویل «سرور خارجی» است، خریدار محصول یک‌بار **لینک/اعتبارنامهٔ آپلود سرور دانلود خود** را در تنظیمات VIP وارد کند و از آن پس **سایت به‌صورت خودکار** فایل‌های ثبت‌شده را روی همان سرور دانلود بارگذاری و همگام نگه می‌دارد — بدون FTP دستی.
3. تحویل محلی (وقتی فایل روی همان سرور وردپرس می‌ماند) هم هوشمندتر و خودترمیم‌گر شود: تشخیص خودکار بهترین روش offload، ترمیم خودکار فایل‌های deny، و رفع نقطه‌ضعف شناخته‌شدهٔ checksum کهنه (بند ۱۳.۱۰ سند مرجع).
4. همهٔ موارد بالا **اختیاری، پویا و از پنل تنظیمات** باشند؛ خریدار هزینه/پیچیدگی را متناسب با نیاز خودش انتخاب می‌کند، نه اینکه به او تحمیل شود.

راهکار پیشنهادی برای بند ۱، ترکیب سه چیز است:

1. **تحویل از لبهٔ امضاشده** (`edge_signed`) یا **پروکسی از سایت** (`proxy`) تا مبدأ واقعی پنهان بماند.
2. **اتصال به نشست کاربر** (user + IP، و در صورت فعال‌بودن UA) با کوکی HttpOnly و TTL کوتاه تا لینک غیرقابل‌اشتراک شود. ساخت/تمدید/بستن نشست با nonce وردپرس محافظت می‌شود (CSRF)؛ مسیرهای خواندنی کلید/قطعه فقط با امضای نشست کار می‌کنند.
3. **رمزنگاری HLS با AES-128** (بدون DRM) تا حتی اگر قطعه‌ای لو رفت، بدون کلید محتوا بی‌ارزش باشد؛ کلید فقط در نشست معتبر تحویل می‌شود.

نکتهٔ کلیدی معماری: **کلید AES هرگز به CDN نمی‌رود و فقط از سایت و به‌صورت متصل به نشست صادر می‌شود.** پس قطعات می‌توانند روی CDN باشند، باز هم بی‌کلید=بی‌ارزش. این محافظت در برابر اشتراک لینک و کپی ساده است، نه جایگزین DRM؛ محدودیت‌های واقعی در بخش ۹ صریح آمده‌اند.

برای بند ۲ (آپلود خودکار)، معماری **آداپتور** (بخش ۴.۲.۱) انتخاب شده: یک قرارداد واحد (`RemoteUploadAdapter`) و چند پیاده‌سازی (webhook عمومی با همان «یک لینک» که ادمین می‌دهد، S3-سازگار، WebDAV، و اختیاری SFTP). این الگو با همان اصل تک‌مسئولیتی/SOLID بقیهٔ معماری هم‌راستا است و هزینهٔ اضافه‌ای جز پهنای باند و CPU آپلود یک‌بارهٔ خود سرور وردپرس تحمیل نمی‌کند — بدون سرویس شخص‌ثالث اجباری.

همهٔ حالت‌ها و همهٔ پارامترها از پنل تنظیمات VIP قابل انتخاب‌اند و حالت‌های فعلی (`local`, `remote_redirect`) دست‌نخورده باقی می‌مانند (سازگاری عقب‌رو).

---

## ۱. وضعیت فعلی (خلاصهٔ کد واقعی)

برای جزئیات کامل به `docs/vip-and-delivery-system-reference-fa.md` مراجعه کنید. خلاصهٔ آنچه امروز وجود دارد:

**دو لایهٔ مستقل:** سیاست دسترسی (`music-wave-core/src/Access/AccessPolicyEngine.php`) ≠ مسیر تحویل (`music-wave-core/src/Downloads/DeliveryRouter.php` + Providerها).

**تنظیم `delivery_provider`** (`music-wave-vip/src/VipSettings.php`) فقط دو مقدار دارد: `local` و `remote_redirect`.

**ارائه‌دهندگان موجود:**

| نام | فایل | رفتار |
|---|---|---|
| `NullDownloadProvider` | Core | deny-by-default |
| `DirectLinkProvider` | `music-wave-core/src/Downloads/DirectLinkProvider.php` | ۳۰۲ به `direct:<https-url>` |
| `ProtectedFileProvider` | `music-wave-vip/src/ProtectedFileProvider.php` | فایل خصوصی محلی، Range/206/416، X-Sendfile/X-Accel، تکهٔ ۱MB |
| `RemoteRedirectProvider` | `music-wave-vip/src/RemoteRedirectProvider.php` | URL امضاشدهٔ HMAC (`url\|expires\|mode\|kid`) → ۳۰۲ با `RedirectResponse` |

**نقطهٔ افشا:** `music-wave-core/src/Downloads/RedirectResponse.php::send()` یک هدر `Location:` می‌فرستد؛ لذا حالت `remote_redirect` ناگزیر میزبان راه‌دور را به کاربر نشان می‌دهد.

**زیرساخت قابل استفادهٔ مجدد:** `DownloadTokenService`, `DownloadTokenClaims`, `OpaqueTicketStore`, `DownloadRateLimiter`, `DownloadResolver`, `DownloadRoutes`, جدول `{prefix}mw_vip_assets`, `ProtectedAssetRegistry`, `ProtectedAssetStorage`.

**مسیرهای REST موجود:** `/music-wave/v1/releases/{id}/download-token`، `/downloads/{id}`، `/streams/{id}`، `/releases/{id}/playback-queue`، `/releases/{id}/download-assets`، `/protected-assets`.

**پخش فعلی:** `music-wave-core/assets/preview-player.js` توکن می‌گیرد و `audio.src` را روی `/streams/{id}` می‌گذارد (Range پیش‌رونده). هیچ HLS/DASH/MSE/ترنسکد/رمزنگاری در مخزن وجود ندارد.

---

## ۲. اهداف و دامنه

### در دامنه (In scope)
- حالت‌های تحویل جدید: `edge_signed`، `proxy` و `edge_hls`.
- **الزام HTTPS:** هر سه حالت جدید فقط با `https` فعال می‌شوند (کوکی `Secure`، امضای URL و مبدأ امن)؛ اگر سایت یا لبه `http` باشد، پنل خطا می‌دهد و حالت جدید فعال نمی‌شود.
- اتصال به نشست کاربر + TTL کوتاه + محدودیت همزمانی.
- بسته‌بندی HLS + رمزنگاری AES-128 + endpoint کلید متصل به نشست.
- جایگزینی `audio.src` با `hls.js` وقتی HLS روشن است (با fallback).
- مدیریت اتصال لبه/سرور خارجی در تنظیمات VIP با «تست اتصال».
- **[جدید] خط‌لولهٔ آپلود خودکار به سرور دانلود خارجی:** با یک لینک/اعتبارنامهٔ آپلود که ادمین در تنظیمات VIP وارد می‌کند، فایل‌های تازه‌ثبت‌شده (و خروجی بسته‌بندی HLS) بدون دخالت دستی روی سرور دانلود همگام می‌شوند؛ صف پس‌زمینه، جلوگیری از آپلود تکراری، تلاش مجدد خودکار و تاییدیهٔ checksum (بخش ۴.۲.۱ و ۶.۵).
- **[جدید/ارتقا] تحویل محلی هوشمند و خودترمیم‌گر:** پیشنهاد خودکار بهترین `sendfile_mode`، ترمیم خودکار فایل‌های deny، و تازه‌سازی خودکار checksum هنگام تغییر محتوای فایل با همان اندازه (بخش ۴.۵).
- راهنمای سادهٔ فارسی برای هر گزینه.

### خارج از دامنه (Out of scope — آگاهانه)
- **DRM** (Widevine/FairPlay/PlayReady): نیازمند سرور لایسنس/گواهی/قرارداد بیرونی؛ در این نسخه نیست (به‌عنوان افزونهٔ اختیاری آینده مستند می‌شود).
- **واترمارک صوتی فورنزیک** و **حسابرسی/هشدار تخلف**: طبق تصمیم محصول در این نسخه نیست (به‌عنوان گزینهٔ اختیاری خاموش پیش‌فرض آینده علامت‌گذاری می‌شود).
- **حفرهٔ آنالوگ** (ضبط خروجی): با هیچ روشی بسته نمی‌شود؛ صادقانه مستند می‌شود.

---

## ۳. معماری هدف

سه حالت تحویل که خریدار از پنل انتخاب می‌کند (به‌علاوهٔ دو حالت فعلی برای سازگاری):

| حالت | مبدأ برای کاربر | بایت‌ها از کجا | بار سرور | کاربرد |
|---|---|---|---|---|
| `local` (موجود) | فقط سایت | سرور سایت | متوسط | فایل روی همان سرور |
| `remote_redirect` (موجود) | **میزبان واقعی لو می‌رود** | سرور خارجی | سبک (فقط ۳۰۲) | سازگاری عقب‌رو |
| **`edge_signed`** [جدید] | فقط دامنهٔ لبه/CDN | CDN | سبک | پخش ساده بدون بسته‌بندی |
| **`proxy`** [جدید] | فقط دامنهٔ سایت | سرور سایت (یا X-Sendfile) | زیاد | بالاترین مخفی‌کاری |
| **`edge_hls`** [جدید] | فقط دامنهٔ لبه/CDN | CDN | سبک | AES-128 + نردبان کیفیت ثابت (توصیه‌شده) |

> **دربارهٔ «کیفیت تطبیقی»:** آنچه در این سند پیاده می‌شود، همان نردبان rendition ثابت (۶۴/۱۲۸/۱۹۲/۳۲۰ kbps) با master playlist است و انتخاب کیفیت در سمت پخش‌کننده انجام می‌شود. ABR پیشرفته/CBOR/LL-HLS در بخش ۱۲ خارج از دامنه است.

### ۳.۱. لایه‌ها

```
کاربر
  │  کوکی HttpOnly نشست + URL امضاشدهٔ کوتاه‌عمر
  ▼
[لایهٔ ۱: لبه/CDN]  ← دامنهٔ خودتان یا دامنهٔ اختصاصی CDN
  │  هدر مخفی مبدأ + IP-allowlist
  ▼
[لایهٔ ۲: سرور خصوصی/دانلود]  ← فایل اصلی و قطعات HLS
                                  (در edge_hls قطعات رمزنگاری‌شده)
                                  آدرس/مسیر واقعی هرگز به کاربر نمی‌رسد

[وردپرس: مدیر نشست + کلید AES]
  ← مستقل از مسیر بایت‌های رسانه؛ مرورگر فقط کلید را same-origin می‌گیرد
  ← کلید هرگز روی لبه/CDN قرار نمی‌گیرد
```

**بینش کلیدی امنیتی:** در حالت `edge_hls`، قطعات رمزنگاری‌شده روی CDN هستند اما **کلید روی وردپرس و وابسته به نشست** است. پس لو رفتن آدرس قطعه بی‌فایده است (چون رمزنگاری‌شده است) و اشتراک لینک بی‌فایده است (چون کلید فقط به نشست معتبر و متصل به همان کاربر/IP و، در صورت فعال‌بودن، همان UA تحویل می‌شود).

**[جدید] لایهٔ چهارم مستقل — «چگونه بایت به سرور دانلود می‌رسد»:** سه لایهٔ بالا دربارهٔ تحویل فایل **از** سرور دانلود/لبه **به کاربر** هستند. یک لایهٔ کاملاً جداگانه و پیش از آن وجود دارد: فایل **چگونه از وردپرس به سرور دانلود می‌رسد**. امروز این کار دستی (FTP/آپلود مستقیم روی سرور خارجی) است. خط‌لولهٔ آپلود خودکار (بخش ۴.۲.۱) این شکاف را پر می‌کند و کاملاً مستقل از حالت تحویل انتخابی عمل می‌کند — همان اصل «دو لایهٔ مستقل» که در سند مرجع برای سیاست دسترسی/مسیر تحویل آمده، اینجا هم تکرار می‌شود: «چگونگی رسیدن فایل به سرور دانلود» هرگز با «چگونگی تحویل فایل به کاربر» قاطی نمی‌شود.

---

## ۴. اجزای جدید

### ۴.۱. کلاس‌های هسته (Core) [جدید]

| کلاس | مسیر پیشنهادی | مسئولیت |
|---|---|---|
| `StreamSession` | `music-wave-core/src/Playback/StreamSession.php` | مقدار ارزشی نشست (session_id/token_hash/user/release/asset/ip_hash/ua_hash/expires/lease) |
| `StreamSessionStore` | `music-wave-core/src/Playback/StreamSessionStore.php` | ساخت/خواندن/تمدید/ابطال نشست + شمارش همزمانی |
| `StreamSessionCookie` | `music-wave-core/src/Playback/StreamSessionCookie.php` | کوکی HttpOnly امضاشده (`SameSite=Lax`, `Secure`, `Path=/wp-json/music-wave/v1/streams/`)؛ مقدارش توکن تصادفی مستقل از `sid` است و فقط `token_hash` آن در DB ذخیره می‌شود |
| `SegmentSigner` | `music-wave-core/src/Downloads/SegmentSigner.php` | امضای مسیر manifest/segment/key (HMAC با `kid`، `exp` مستقل از `session_ttl`، اتصال `sid`/نشست) |

### ۴.۲. کلاس‌های VIP (تحویل، بسته‌بندی و کلید) [جدید]

| کلاس | مسیر پیشنهادی | مسئولیت |
|---|---|---|
| `EdgeSignedProvider` | `music-wave-vip/src/EdgeSignedProvider.php` | حالت `edge_signed` (URL امضاشده روی دامنهٔ لبه، بدون ۳۰۲ به مبدأ) |
| `EdgeHlsProvider` | `music-wave-vip/src/EdgeHlsProvider.php` | حالت `edge_hls` برای **دانلود کامل** (URL امضاشدهٔ لبه)؛ پخش HLS را `HlsRoutes` انجام می‌دهد، نه این provider |
| `ProxyStreamProvider` | `music-wave-vip/src/ProxyStreamProvider.php` | حالت `proxy` (جریان بایت از سایت، با X-Sendfile/X-Accel؛ پیاده‌ساز `DownloadProvider` و `StreamableDownloadProvider`) |
| `HlsPackager` | `music-wave-vip/src/Hls/HlsPackager.php` | بسته‌بندی یک‌بارهٔ فایل به renditions + قطعات + رمزنگاری AES-128 |
| `HlsRenditions` | `music-wave-vip/src/Hls/HlsRenditions.php` | تعریف کیفیت‌های هدف (۶۴/۱۲۸/۱۹۲/۳۲۰ kbps) و نگاشت آن‌ها |
| `AssetKeyStore` | `music-wave-vip/src/Hls/AssetKeyStore.php` | تولید و **ذخیرهٔ سربستهٔ** کلید AES هر دارایی (رمزنگاری‌شده با راز سایت) |
| `StreamKeyEndpoint` | `music-wave-vip/src/Hls/StreamKeyEndpoint.php` | منطق صدور کلید AES فقط برای نشست معتبر (`/streams/{id}/key`) |
| `HlsRoutes` | `music-wave-vip/src/Hls/HlsRoutes.php` | ثبت REST مسیرهای `manifest`/`segment`/`key`؛ اعتبارسنجی نشست از کلاس‌های Core |
| `EdgeConnectionManager` | `music-wave-vip/src/Delivery/EdgeConnectionManager.php` | پیکربندی و **تست اتصال** به لبه/CDN، اعتبارسنجی هدر مبدأ |
| `HlsDiagnostics` | `music-wave-vip/src/Delivery/HlsDiagnostics.php` | گزارش آمادگی (ffmpeg موجود؟ پکیج شده؟ کلید سالم؟ اتصال لبه OK؟) |

> **مرز وابستگی:** Core هرگز کلاس VIP را لود نمیکند. به همین دلیل مسیرهای HLS (`manifest`/`segment`/`key`) را VIP ثبت میکند و برای اعتبارسنجی نشست فقط از `StreamSessionStore`/`SegmentSigner` هسته استفاده میکند (جهت مجاز VIP → Core). `DownloadRoutes` هسته تنها مسیرهای عمومی نشست (`session`/`heartbeat`/`close`) را ثبت میکند. برای تعیین `effective_mode` (اینکه پاسخ نشست `edge_hls` باشد یا fallback به `edge_signed`) Core از فیلتر `music_wave_stream_effective_mode` استفاده میکند که VIP آن را تأمین میکند؛ Core هیچ کلاس VIP را لود نمیکند.

### ۴.۲.۱. آپلود خودکار به سرور دانلود خارجی (Remote Upload Pipeline) [جدید]

**مسئله:** امروز، در حالت `remote_redirect`/`edge_signed`/`edge_hls`، فرض بر این است که فایل از قبل روی سرور/CDN راه‌دور وجود دارد؛ رساندن فایل به آنجا کار دستی خریدار است (FTP/پنل CDN). هدف این بخش، بستن این شکاف با یک خط‌لولهٔ آپلود **اختیاری، خودکار و پویا** است که از همان لحظهٔ ثبت یک دارایی محافظت‌شده (`vip:<32hex>`) در وردپرس شروع می‌شود.

**الگوی معماری (Adapter، مطابق SOLID/OCP):** یک قرارداد واحد و چند پیاده‌سازی قابل‌جایگزین، تا افزودن روش آپلود جدید در آینده بدون دست‌زدن به منطق صف/همگام‌سازی ممکن باشد.

| کلاس/اینترفیس | مسیر پیشنهادی | مسئولیت |
|---|---|---|
| `RemoteUploadAdapter` (interface) | `music-wave-vip/src/Delivery/RemoteUploadAdapter.php` | قرارداد یکسان: `upload()`, `exists()`, `remote_checksum()`, `delete()`, `test_connection()` |
| `WebhookUploadAdapter` | `music-wave-vip/src/Delivery/Adapters/WebhookUploadAdapter.php` | **همان «یک لینک» که ادمین می‌دهد**: `POST`/`PUT` استریم‌شده به `remote_upload_endpoint` با هدر احراز هویت (bearer token یا هدر مخفی)؛ مناسب هر سرور دانلود سفارشی که یک endpoint آپلود دارد |
| `S3CompatibleUploadAdapter` | `music-wave-vip/src/Delivery/Adapters/S3CompatibleUploadAdapter.php` | امضای SigV4 محلی (بدون SDK سنگین) + presigned PUT روی هر سرویس سازگار با S3 (AWS S3، Cloudflare R2، Backblaze B2، MinIO خودمیزبان، Filebase …)؛ برای فایل‌های بزرگ‌تر از `s3_multipart_threshold_mb` از Multipart Upload استفاده می‌کند |
| `WebdavUploadAdapter` | `music-wave-vip/src/Delivery/Adapters/WebdavUploadAdapter.php` | `MKCOL` برای ساخت پوشه + `PUT` استریم‌شده (در صورت پشتیبانی سرور، partial-PUT برای ازسرگیری) روی هر فضای WebDAV (Nextcloud، هاست اشتراکی با WebDAV، …) |
| `SftpUploadAdapter` [اختیاری] | `music-wave-vip/src/Delivery/Adapters/SftpUploadAdapter.php` | فقط اگر افزونهٔ `ssh2`/کتابخانهٔ phpseclib موجود باشد؛ برای خریدارانی که فقط دسترسی SSH به سرور دانلود دارند. نبودِ پیش‌نیاز → در `RemoteUploadDiagnostics` علامت‌گذاری و در لیست انتخاب پنل غیرفعال نشان داده می‌شود |
| `RemoteUploadManager` | `music-wave-vip/src/Delivery/RemoteUploadManager.php` | هماهنگ‌کنندهٔ اصلی: تصمیم «آیا این دارایی باید آپلود شود»، انتخاب آداپتور بر اساس `remote_upload_mode`، dedupe با checksum، فراخوانی adapter، ثبت نتیجه |
| `RemoteUploadQueue` | `music-wave-vip/src/Delivery/RemoteUploadQueue.php` | صف پس‌زمینه روی جدول `mw_vip_upload_queue`؛ enqueue/claim/complete/retry با backoff نمایی |
| `RemoteUploadDiagnostics` | `music-wave-vip/src/Delivery/RemoteUploadDiagnostics.php` | گزارش آمادگی (endpoint معتبر؟ اعتبارنامه تنظیم شده؟ پیش‌نیاز SFTP هست؟ آخرین خطا چیست؟) برای نمایش در تب تنظیمات |

**جریان کلی (رویدادمحور + صف، نه هم‌زمان با درخواست کاربر):**

1. وقتی `ProtectedAssetRegistry::register()` یک دارایی را ثبت می‌کند (یا `hls_state` به `ready` می‌رسد)، اکشن `music_wave_vip_asset_registered`/`music_wave_vip_hls_ready` شلیک می‌شود.
2. اگر `remote_upload_enabled = enabled` **و** `delivery_provider` فعلی به بایت‌های روی سرور خارجی نیاز دارد (`remote_redirect`، `edge_signed`، یا `edge_hls` با `hls_proxy_segments=disabled`)، `RemoteUploadManager` یک ردیف در `mw_vip_upload_queue` می‌سازد (idempotent: اگر ردیف `pending`/`uploading` برای همان `asset_key` از قبل هست، تکراری ساخته نمی‌شود). اگر `delivery_provider` نیاز به بایت خارجی نداشته باشد (`local`، `proxy`، یا `edge_hls` با قطعات عبوری از سایت)، هیچ صفی ساخته نمی‌شود — **آپلود فقط وقتی لازم است انجام می‌شود، نه همیشه.**
3. یک کرون سبک (`music_wave_vip_upload_worker`، هر دقیقه) ردیف‌های `pending` را با سقف `remote_upload_concurrency` برمی‌دارد و `RemoteUploadManager::sync()` را صدا می‌زند؛ علاوه‌بر کرون، برای تأخیر کمتر بلافاصله پس از ثبت یک `wp_schedule_single_event(time(), ...)` هم زمان‌بندی می‌شود (کرون دوره‌ای فقط پشتیبان/ترمیم‌کننده است، نه تنها مسیر اجرا).
4. **عدم آپلود تکراری (dedupe):** پیش از انتقال بایت، checksum محلی (همان SHA-256 ثبت‌شده در `mw_vip_assets.checksum`) با مقدار گزارش‌شدهٔ ریموت (`exists()`/`remote_checksum()` آداپتور، یا ردیف ذخیره‌شدهٔ قبلی در صف) مقایسه می‌شود؛ اگر یکسان بود، آپلود رد می‌شود و فقط وضعیت `synced` ثبت می‌شود.
5. **استریم، نه بارگذاری کامل در حافظه:** انتقال با `fopen`/chunk خوانده و نوشته می‌شود تا فایل‌های بزرگ FLAC/ZIP بدون توجه به `memory_limit` قابل آپلود باشند.
6. **تایید پس از آپلود:** اگر آداپتور اندازه/ETag/checksum ریموت را برمی‌گرداند (S3-سازگار معمولاً ETag می‌دهد)، با مقدار محلی مقایسه می‌شود؛ fail-closed یعنی تا تاییدشدن، دارایی `synced` علامت نمی‌خورد و (در صورت فعال‌بودن `remote_upload_keep_local=disabled`) نسخهٔ محلی حذف نمی‌شود. اگر پروتکل تاییدیهٔ قوی ندهد (مثلاً برخی WebDAV)، این محدودیت صادقانه در UI و مستندات نوشته می‌شود؛ حداقل اندازهٔ فایل دوباره‌خوانی و مقایسه می‌شود.
7. **شکست و تلاش مجدد:** شکست شبکه/اعتبارسنجی → `attempts++`، `next_retry_at = now + backoff(attempts)` (تصاعدی، سقف `remote_upload_max_retries`، پیش‌فرض ۵)؛ پس از سقف، ردیف `failed` می‌شود و در `RemoteUploadDiagnostics` با دلیل خطا نمایش داده می‌شود (بدون توقف بقیهٔ سایت یا صف — خطای یک دارایی بقیه را مسدود نمی‌کند).
8. **پاکسازی محلی اختیاری:** `remote_upload_keep_local` پیش‌فرض `enabled` (ایمن) است؛ فقط پس از تایید موفق آپلود و اگر ادمین آگاهانه `disabled` را انتخاب کرده باشد، نسخهٔ محلی حذف می‌شود — هرگز پیش از تایید.

**امنیت اعتبارنامه و endpoint آپلود:**
- اعتبارنامه (`remote_upload_auth_token` یا `remote_upload_secret_key`) مثل `remote_signing_secret`/`edge_signing_secret` موجود، فیلد password با دکمهٔ «ایجاد/پاک‌سازی»، و در DB **سربسته** با همان الگوی `sodium_crypto_secretbox` + HKDF از `AUTH_KEY`/`SECURE_AUTH_SALT` ذخیره می‌شود که برای کلید HLS استفاده شده (بدون کد تکراری: همان کلاس کمکی wrap/unwrap بین `AssetKeyStore` و اینجا به یک سرویس مشترک `SecretBoxCipher` در Core منتقل می‌شود — رفع تکرار/DRY نسبت به نسخهٔ اول این سند).
- **اعتبارسنجی میزبان endpoint:** `remote_upload_endpoint` با همان قواعد `DirectLinkUrlValidator` اعتبارسنجی می‌شود (فقط HTTPS، رد IP خصوصی/loopback/لینک‌محلی مثل `169.254.169.254`، رد userinfo) — استفادهٔ مجدد از کلاس موجود به‌جای نوشتن اعتبارسنج تازه (DRY) و همان سطح محافظت در برابر SSRF که برای `direct:` وجود دارد.
- هیچ اعتبارنامه‌ای در لاگ/خطا/رویداد `music_wave_download_event` نوشته نمی‌شود.

### ۴.۳. جداول دیتابیس [جدید]

| جدول | ستون‌ها | کاربرد |
|---|---|---|
| `{prefix}mw_stream_sessions` | `session_id char(64) NOT NULL` (شناسهٔ عمومی در URL), `token_hash char(64) NOT NULL` (SHA-256 توکن کوکی), `user_id bigint unsigned NOT NULL DEFAULT 0`, `release_id bigint unsigned NOT NULL DEFAULT 0`, `asset_key char(32) NOT NULL`, `ip_hash char(64) NOT NULL`, `ua_hash char(64) NOT NULL`, `created bigint unsigned NOT NULL DEFAULT 0`, `expires bigint unsigned NOT NULL DEFAULT 0`, `last_seen bigint unsigned NOT NULL DEFAULT 0`, `revoked tinyint unsigned NOT NULL DEFAULT 0`؛ `PRIMARY KEY (session_id)`، `UNIQUE KEY token_hash (token_hash)`، `KEY user_id (user_id)`، `KEY user_active (user_id, revoked, expires)`، `KEY expires (expires)` | نشست پخش + شمارش همزمانی + ابطال |
| `{prefix}mw_vip_asset_keys` | `asset_key char(32) NOT NULL`, `key_id char(32) NOT NULL`, `key_version smallint unsigned NOT NULL DEFAULT 1`, `wrapped_key text NOT NULL` (base64 خروجی secretbox), `wrap_nonce char(32) NOT NULL` (base64 نانس ۲۴ بایتی؛ ۳۲ کاراکتر), `created bigint unsigned NOT NULL DEFAULT 0`؛ `PRIMARY KEY (asset_key, key_id)` | کلید AES-128 سربسته (چند ردیف در زمان چرخش کلید؛ آخرین `key_version` فعال) |
| توسعهٔ `{prefix}mw_vip_assets` | افزودن `hls_state varchar(20) NOT NULL DEFAULT ''`, `hls_manifest longtext NULL`, `hls_bytes bigint unsigned NOT NULL DEFAULT 0`, `hls_duration int unsigned NOT NULL DEFAULT 0`, `packaged_at bigint unsigned NOT NULL DEFAULT 0`, `upload_state varchar(20) NOT NULL DEFAULT ''`, `remote_path text NULL`, `uploaded_at bigint unsigned NOT NULL DEFAULT 0` | وضعیت بسته‌بندی HLS + وضعیت همگام‌سازی روی سرور دانلود خارجی |
| `{prefix}mw_vip_upload_queue` **[جدید]** | `id bigint unsigned AUTO_INCREMENT`, `asset_key char(32) NOT NULL`, `status varchar(20) NOT NULL DEFAULT 'pending'` (`pending`/`uploading`/`synced`/`failed`), `attempts smallint unsigned NOT NULL DEFAULT 0`, `last_error text NULL`, `remote_checksum char(64) DEFAULT ''`, `enqueued_at bigint unsigned NOT NULL DEFAULT 0`, `updated_at bigint unsigned NOT NULL DEFAULT 0`, `next_retry_at bigint unsigned NOT NULL DEFAULT 0`؛ `PRIMARY KEY (id)`، `UNIQUE KEY asset_key (asset_key)` (هر دارایی حداکثر یک ردیف صف — جلوگیری از آپلود تکراری همزمان)، `KEY status_retry (status, next_retry_at)` | صف پس‌زمینهٔ آپلود خودکار به سرور دانلود؛ پاک‌سازی ردیف‌های `synced` قدیمی با همان کرون روزانهٔ موجود |

> **معنی ستون‌ها:** `hls_state` یکی از `''` (بسته‌بندی‌نشده)، `queued`، `packaging`، `ready` یا `failed` است. `hls_manifest` فقط متن **master playlist** را نگه می‌دارد؛ variant playlistها و قطعات، فایل‌های روی ذخیره‌سازی محافظت‌شده/CDN هستند و با URL امضاشده در master به آن‌ها اشاره می‌شود. `hls_duration` مدت دارایی به ثانیه است و برای محاسبهٔ `exp` قطعات استفاده می‌شود. اگر بخواهید همهٔ playlistها در DB باشند، `hls_manifest` را JSON کنید؛ طرح پیشنهادی master-only است و URIهای ذخیره‌شده نسبی/placeholder هستند.

**نکتهٔ امنیت در حالت سکون:** `wrapped_key` با `sodium_crypto_secretbox` رمز می‌شود. کلید بسته‌بندی با `hash_hkdf('sha256', AUTH_KEY . SECURE_AUTH_SALT, 32, 'mw-hls-key-wrap')` مشتق می‌شود. این منطق wrap/unwrap در یک کلاس مشترک `music-wave-core/src/Support/SecretBoxCipher.php` **[جدید]** قرار می‌گیرد (نه فقط داخل `AssetKeyStore`) تا اعتبارنامهٔ آپلود خودکار (بخش ۴.۲.۱) هم همان کد را با `context` متفاوت (`'mw-hls-key-wrap'` در برابر `'mw-upload-credential-wrap'`) فراخوانی کند — رفع تکرار کد (DRY) بین دو نیاز مشابه. اگر `AUTH_KEY` یا `SECURE_AUTH_SALT` تعریف نشده باشد، بسته‌بندی/صدور کلید **fail-closed** می‌شود. libsodium از PHP 7.2 داخل هستهٔ PHP است و پروژه حداقل 7.4 می‌خواهد؛ پس افزونهٔ دیگری لازم نیست. `IV` هر قطعه یک `iv_base` ثابت نیست: یا صفت `IV` در `#EXT-X-KEY` حذف می‌شود و پخش‌کننده طبق استاندارد HLS از شمارهٔ ترتیب قطعه استفاده می‌کند، یا برای هر قطعه ۱۶ بایت تصادفی یکتا در همان manifest نوشته می‌شود؛ ذخیره در کنار فایل قطعه لازم نیست. یک `iv_base` مشترک باعث افشای تساوی بلوک‌های اول در قطعات با کلید یکسان می‌شود. **چرخش راز سایت:** اگر `AUTH_KEY`/`SECURE_AUTH_SALT` عوض شوند، کلیدهای wrapped موجود دیگر باز نمی‌شوند؛ پیش از چرخش باید re-wrap با `key_version` جدید انجام شود یا دارایی‌ها دوباره بسته‌بندی شوند (بخش ۱۰).

> **جایگزین سبک:** اگر ادمین نخواهد جدول جدید بسازد، `StreamSessionStore` می‌تواند روی transient (`mw_session_<hash>`) کار کند؛ اما شمارش همزمانی اتمیک با transient شکننده است، لذا برای حالت تولید جدول توصیه می‌شود. هر دو از یک اینترفیس استفاده می‌کنند (استراتژی قابل انتخاب در تنظیمات). کلید AES در هر حالت باید در جدول بماند؛ نگهداری کلید در transient با انقضای transient ناسازگار است.

> **قواعد الزامی پیاده‌سازی نشست و HLS:**
> - **تفکیک `sid` از توکن کوکی:** `session_id` یک شناسهٔ عمومی تصادفی است که در URL می‌آید؛ کوکی مقدار جداگانهٔ تصادفی دارد و DB فقط `token_hash = hash('sha256', token)` را نگه می‌دارد. لو رفتن `sid` به‌تنهایی دسترسی نمی‌دهد (امضا + کوکی لازم است) و کوکی HttpOnly هرگز داخل URL قرار نمی‌گیرد.
> - **شمارش همزمانی اتمیک:** فعال‌سازی lease در یک تراکنش با `SELECT ... FOR UPDATE` (یا ایندکس یگانه روی نشست فعال) انجام شود؛ وگرنه دو درخواست همزمان می‌توانند سقف `session_max_concurrent` را دور بزنند.
> - **مهمان:** برای `user_id=0` سقف همزمانی بر اساس `ip_hash` (و سقف کلی IP) شمرده شود؛ حتی اگر `session_bind_ip=disabled` باشد، کلید شمارش از هش IP+UA ساخته شود. ساخت نشست مهمان با `DownloadRateLimiter` محدود شود تا ساخت انبوه نشست، جدول و CPU را اشغال نکند.
> - **بازبینی دسترسی:** `AccessPolicyEngine` هنگام ساخت نشست، هر `heartbeat` و هر درخواست `key` دوباره اجرا شود؛ لغو/انقضای اشتراک باید نشست فعال را فوراً باطل کند (نه فقط در شروع پخش).
> - **پاک‌سازی:** یک cron روزانه (`music_wave_stream_session_cleanup`) ردیف‌های `expires < now - session_lease_grace` یا `revoked=1` قدیمی را حذف کند؛ مشابه `music_wave_replay_cleanup`.
> - **پلی‌لیست و URL تازه:** در DB/ذخیره‌سازی فقط URIهای نسبی/placeholder نگه داشته شود؛ master/variant playlist **در هر درخواست** با URLهای تازه‌امضاشده رندر شود. ذخیرهٔ playlist با URL امضاشدهٔ ثابت باعث می‌شود `exp` داخل آن منقضی شود و پخش نیمه‌کاره بماند.
> - **محدودهٔ بسته‌بندی:** فقط دارایی‌های ثبت‌شدهٔ `vip:<32hex>` بسته‌بندی شوند تا `asset_key char(32)` معتبر بماند؛ دارایی‌های `local:` legacy وارد مسیر HLS نمی‌شوند.

### ۴.۴. مسیرهای REST [جدید]

| متد | مسیر | نقش | ثبت‌کننده |
|---|---|---|---|
| POST | `/wp-json/music-wave/v1/streams/{id}/session` | ساخت نشست + صدور کوکی + برگرداندن `sid` عمومی و `effective_mode` و manifest/playback URL (نیازمند nonce) | Core `DownloadRoutes` |
| GET | `/wp-json/music-wave/v1/streams/{id}/manifest` | برگرداندن `index.m3u8` (نشستمحور، امضای مستقل از `session_ttl`) | VIP `HlsRoutes` |
| GET | `/wp-json/music-wave/v1/streams/{id}/segment` | فقط وقتی `hls_proxy_segments=enabled`؛ وگرنه قطعه از CDN می‌آید | VIP `HlsRoutes` |
| GET | `/wp-json/music-wave/v1/streams/{id}/key` | برگرداندن کلید AES-128 فقط برای نشست معتبر | VIP `HlsRoutes` → `StreamKeyEndpoint` |
| POST | `/wp-json/music-wave/v1/streams/{id}/heartbeat` | تمدید کشویی TTL + تازه‌سازی lease (نیازمند nonce) | Core `DownloadRoutes` |
| POST | `/wp-json/music-wave/v1/streams/{id}/close` | آزادسازی lease هنگام پایان پخش (نیازمند nonce) | Core `DownloadRoutes` |
| POST | `/wp-json/music-wave/v1/vip/remote-upload/test` **[جدید]** | ارسال یک شیء آزمایشی کوچک به endpoint آپلود و حذف فوری آن («تست اتصال»)؛ فقط ویرایشگر/مدیر | VIP `RemoteUploadRoutes` |
| POST | `/wp-json/music-wave/v1/vip/remote-upload/sync-now` **[جدید]** | صف‌کردن فوری یک دارایی یا کل کتابخانه برای همگام‌سازی؛ فقط ویرایشگر/مدیر | VIP `RemoteUploadRoutes` |
| GET | `/wp-json/music-wave/v1/vip/remote-upload/status` **[جدید]** | وضعیت صف (تعداد pending/failed/synced) برای نوار پیشرفت پنل؛ فقط ویرایشگر/مدیر | VIP `RemoteUploadRoutes` |

> حالت `proxy` یک جریان پیشروندهٔ Range از `/streams/{id}` است و از مسیر `segment` استفاده نمیکند. مسیر `segment` فقط برای HLSِ عبوری از سایت (`edge_hls` + `hls_proxy_segments=enabled`) است. در `edge_hls` مسیر پیشروندهٔ `/streams/{id}` استفاده نمیشود و پخشکننده باید از `session` → `manifest` برود؛ اگر کلاینت قدیمی `/streams/{id}` را صدا بزند، پاسخ روشن ۴۰۹ (بدون بایت رسانه و بدون ۳۰۲ به مبدأ) برگردانده میشود.
>
> پاسخ `session` باید `effective_mode` (`edge_hls`، `edge_signed` یا `proxy`) و URL متناظر (`manifest_url` یا `playback_url`) را برگرداند تا پخشکننده هنگام fallback سردرگم نشود؛ تعیین این مقدار در Core از طریق فیلتر `music_wave_stream_effective_mode` انجام میشود و VIP آن را تأمین میکند.

> **مجوز مسیرهای مدیریتی آپلود:** سه مسیر `remote-upload/*` همان قاعدهٔ موجود `/protected-assets` را تکرار می‌کنند: `can_edit()` = `edit_post` یا `edit_mw_releases`/`manage_options`، و در نبود فیلتر `music_wave_can_assign_download_asset` به `manage_mw_protected_assets`/`manage_options` برمی‌گردند — بدون تعریف سطح مجوز تازه (DRY).

مسیرهای موجود (`/streams/{id}`, `/downloads/{id}`, `/download-token`) دست‌نخورده می‌مانند؛ حالت‌های جدید مسیرهای افزودنی هستند.

> **دانلود کامل:** در هر سه حالت جدید، دانلود کامل از مسیرهای موجود `POST /download-token` → `GET /downloads/{id}` انجام میشود و providerهای جدید همان قرارداد `DownloadProvider` را پیاده میکنند. `ProxyStreamProvider` علاوه بر `DownloadProvider`، `StreamableDownloadProvider` را هم پیاده میکند تا مسیر `/streams/{id}` را پوشش دهد. `EdgeHlsProvider` و `EdgeSignedProvider` برای دانلود، URL امضاشدهٔ لبه میدهند (بدون بایت از وردپرس). دانلود کامل فقط وقتی انجام میشود که سیاست دسترسی اجازه دهد؛ فایل دانلودشده طبق ماهیتش قابل نگهداری است و محافظت HLS/AES فقط مسیر پخش را پوشش میدهد.

### ۴.۵. ارتقای تحویل محلی و پوشهٔ محافظت‌شده (هوشمند و خودترمیم‌گر) [جدید/ارتقا]

این بخش `local` (حالت تحویل موجود، وقتی فایل روی همان سرور وردپرس می‌ماند) را هدف می‌گیرد — بدون نیاز به لبه/CDN/سرور دانلود خارجی، و بدون تغییر رفتار برای کسی که این گزینه‌ها را روشن نمی‌کند.

1. **پیشنهاد خودکار بهترین `sendfile_mode` (نه تغییر خودکار):** `LocalDeliveryDiagnostics::detect()` از `$_SERVER['SERVER_SOFTWARE']` و وجود ماژول‌های شناخته‌شده (nginx → پیشنهاد `xaccel`، Apache با `mod_xsendfile` → پیشنهاد `xsendfile`، وگرنه `none`) یک پیشنهاد می‌سازد و در تب «تحویل حفاظت‌شده» کنار گزینهٔ فعلی نشان می‌دهد؛ تغییر فقط با کلیک صریح ادمین ذخیره می‌شود (بخش ۶.۶).
2. **خودترمیمی ریشهٔ خصوصی:** کرون روزانهٔ جدید `music_wave_vip_root_selfheal` وجود فایل‌های deny (`.htaccess`, `web.config`, `index.html`) در `protected_root` را بازبینی می‌کند؛ اگر یکی (مثلاً پس از بازگردانی بک‌آپ) گم شده باشد، دوباره می‌سازد و رویداد را در `VipAuditLog` ثبت می‌کند تا ادمین متوجهٔ ریشهٔ مشکل شود، نه فقط رفعِ خاموش آن.
3. **رفع نقطه‌ضعف checksum کهنه (بند ۱۳.۱۰ سند مرجع):** امروز `ProtectedAssetRegistry::register()` فقط وقتی `file_size` تغییر کند دوباره هش می‌زند؛ جایگزینی فایل با همان حجم باعث رد دائمی (`fail closed`) در `resolve_registered()` می‌شود بدون مسیر خودکار اصلاح. با `local_checksum_autorefresh=enabled`، `sync_inventory()`/`preflight()` علاوه بر `file_size`، `filemtime` را هم با `registered_at` مقایسه می‌کند؛ اگر فایل از ثبت قبلی تازه‌تر باشد (حتی با اندازهٔ یکسان)، checksum خودکار دوباره محاسبه و رجیستری به‌روزرسانی می‌شود — بدون نیاز به حذف/ثبت دستی دوباره.
4. **یتیم‌یابی بدون حذف خاموش:** `sync_inventory()` ردیف‌هایی که فایل‌شان دیگر روی دیسک نیست را `missing` علامت می‌زند (نه حذف خودکار از دیتابیس) و در دیاگنوستیک نشان می‌دهد؛ حذف قطعی فقط با تایید دستی ادمین انجام می‌شود تا یک جابه‌جایی موقت فایل باعث از دست رفتن دائمی رکورد نشود.
5. **اندازهٔ تکهٔ قابل‌تنظیم:** `local_stream_chunk_kb` به‌جای عدد ثابت ۱ مگابایتی فعلی `ProtectedFileProvider`، امکان کوچک‌کردن تکه برای هاست‌های اشتراکی کم‌حافظه را می‌دهد (بخش ۶.۶).

این پنج مورد کاملاً مستقل از حالت‌های جدید لبه/HLS/آپلود هستند و حتی خریداری که هیچ‌کدام از آن‌ها را روشن نمی‌کند هم از ترمیم و تازه‌سازی خودکار بهره می‌برد — چون این ارتقا به ماهیت لایهٔ `local` موجود بازمی‌گردد، نه فقط حالت‌های جدید.

---

## ۵. نقشهٔ تغییر فایل‌به‌فایل

| فایل موجود | تغییر |
|---|---|
| `music-wave-vip/src/VipSettings.php` | افزودن کلیدهای جدید (بخش ۶)؛ `normalize()` برای مجموعهٔ providerها و clamp مقادیر؛ نگهداری راز جلسه |
| `music-wave-vip/src/ProviderFactory.php` | نگاشت `edge_signed`→`EdgeSignedProvider`، `proxy`→`ProxyStreamProvider`، `edge_hls`→`EdgeHlsProvider`؛ حفظ دو حالت فعلی (`local`/`remote_redirect`) |
| `music-wave-vip/src/SettingsPage.php` | افزودن فیلدهای بخش‌های ۶.۱–۶.۴ داخل تب موجود «تحویل حفاظت‌شده» (بدون تب جدید، برای کم‌کردن تغییر UI) + دکمه‌های «تست اتصال» و «بسته‌بندی HLS» |
| `music-wave-vip/src/Admin/VipSettingsUi.php` | ویجت‌های UI (tooltip راهنمای ساده، وضعیت آمادگی) |
| `music-wave-vip/src/Hls/HlsRoutes.php` | **[جدید]** ثبت REST مسیرهای `manifest`/`segment`/`key`؛ تنها نقطهٔ اتصال VIP به نشست Core |
| `music-wave-vip/src/Delivery/RemoteUploadManager.php` | **[جدید]** هماهنگ‌کنندهٔ آپلود خودکار (بخش ۴.۲.۱) |
| `music-wave-vip/src/Delivery/RemoteUploadAdapter.php` | **[جدید]** قرارداد آداپتور آپلود |
| `music-wave-vip/src/Delivery/Adapters/WebhookUploadAdapter.php` | **[جدید]** آپلود با «یک لینک» ساده (POST/PUT) |
| `music-wave-vip/src/Delivery/Adapters/S3CompatibleUploadAdapter.php` | **[جدید]** presigned PUT سازگار با S3/R2/B2/MinIO |
| `music-wave-vip/src/Delivery/Adapters/WebdavUploadAdapter.php` | **[جدید]** آپلود WebDAV (MKCOL + PUT) |
| `music-wave-vip/src/Delivery/Adapters/SftpUploadAdapter.php` | **[جدید، اختیاری]** فقط با پیش‌نیاز SSH2/phpseclib |
| `music-wave-vip/src/Delivery/RemoteUploadQueue.php` | **[جدید]** صف پس‌زمینه روی `mw_vip_upload_queue` |
| `music-wave-vip/src/Delivery/RemoteUploadDiagnostics.php` | **[جدید]** آمادگی/آخرین خطای آپلود برای پنل |
| `music-wave-vip/src/Delivery/RemoteUploadRoutes.php` | **[جدید]** ثبت REST مسیرهای `remote-upload/*` |
| `music-wave-vip/src/Delivery/LocalDeliveryDiagnostics.php` | **[جدید]** پیشنهاد خودکار `sendfile_mode` + وضعیت سلامت ریشهٔ محافظت‌شده (بخش ۴.۵) |
| `music-wave-core/src/Support/SecretBoxCipher.php` | **[جدید]** wrap/unwrap مشترک `sodium_crypto_secretbox` برای کلید HLS و اعتبارنامهٔ آپلود (DRY) |
| `music-wave-core/src/Downloads/DeliveryCapabilities.php` | شناخت حالت‌های جدید در `probe()`/`supports()` |
| `music-wave-core/src/Downloads/DeliveryRouter.php` | مسیردهی حالت‌های جدید (در صورت نیاز به تفکیک) |
| `music-wave-core/src/Downloads/DownloadRoutes.php` | رجیستر مسیرهای `session`/`heartbeat`/`close` (نه `manifest`/`key` که مالکیت VIP دارند) |
| `music-wave-core/src/Downloads/DownloadResolver.php` | اتصال به `StreamSessionStore` (ساخت/اعتبارسنجی نشست پیش از تحویل) |
| `music-wave-core/src/Downloads/DownloadTokenService.php` | افزودن claims نشست (`sid`) به payload؛ پشتیبانی امضای مسیر قطعه/کلید در `SegmentSigner` |
| `music-wave-core/src/Blocks/PreviewPlayer.php` | بارگذاری اختیاری `hls.js` و localize پرچم `hlsEnabled` + مسیر `session`/`manifest` |
| `music-wave-core/assets/preview-player.js` | مسیر HLS با `hls.js`؛ درخواست‌های same-origin کلید کوکی را خودکار می‌فرستند (بدون `withCredentials`)؛ fallback به `<audio src>` |
| `music-wave-core/assets/download.js` | بدون تغییر اساسی (مسیر دانلود همان است) |
| `music-wave-vip/src/ProtectedAssetStorage.php` | افزودن `package_hls()` (فراخوانی `HlsPackager`)، `sync` برای نگهداشت renditions، و ارتقای `sync_inventory()`/`preflight()` برای تازه‌سازی خودکار checksum (بخش ۴.۵) |
| `music-wave-vip/src/ProtectedAssetRegistry.php` | افزودن متدهای وضعیت HLS/کلید (`set_hls_state()`, `hls_manifest()`, `hls_duration()`) و وضعیت آپلود (`set_upload_state()`, `remote_path()`) |
| `music-wave-core/src/Downloads/DirectLinkUrlValidator.php` | **بدون تغییر در رفتار `direct:` موجود**؛ فقط یک متد عمومی `assert_safe_host()` از منطق داخلی آن استخراج و توسط `RemoteUploadManager` برای اعتبارسنجی `remote_upload_endpoint` دوباره استفاده می‌شود (DRY، بدون اعتبارسنج جدید) |
| `music-wave-vip/src/Admin/VipSettingsUi.php` | افزودن ویجت «تست اتصال آپلود» و نوار پیشرفت همگام‌سازی کتابخانه |
| `docs/secure-downloads.md` | افزودن بخش حالت‌های لبه/HLS و خط‌لولهٔ آپلود خودکار |
| `docs/delivery-threat-model.md` | افزودن ردیف‌های جدول «حمله → کنترل» برای آپلود خودکار (بخش ۸) |
| `docs/adr/0006-edge-signed-delivery-and-encrypted-hls.md` | **[جدید]** ثبت تصمیم (متن پیشنهادی در بخش ۱۳) |

**فایل‌های بدون تغییر رفتاری (تأیید عمدی):** `AccessPolicyEngine`, `AccessModePolicy`, `MembershipAbsencePolicy`, `AssetIdSanitizer`, `OpaqueTicketStore`, `DownloadRateLimiter`, `DirectLinkProvider`, `NullDownloadProvider`. (`DirectLinkUrlValidator` فقط refactor داخلی برای استخراج متد مشترک می‌گیرد؛ قرارداد و رفتار بیرونی‌اش برای `direct:` عوض نمی‌شود.)

---

## ۶. کاتالوگ تنظیمات جدید (همه از پنل، با راهنمای ساده)

همه در گزینهٔ `music_wave_vip_settings` و با راهنمای فارسی یک‌خطی در `VipSettingsUi::tooltip()`.

### ۶.۱. انتخاب حالت تحویل
| کلید | پیش‌فرض | مقادیر | راهنمای سادهٔ پنل |
|---|---|---|---|
| `delivery_provider` (گسترش‌یافته) | `local` | `local` \| `remote_redirect` \| `edge_signed` \| `proxy` \| `edge_hls` | «برای پخش سبک و مخفی‌ماندن مبدأ، لبهٔ امضاشده؛ برای بالاترین مخفی‌کاری، پروکسی سایت؛ برای امنیت بالاتر با رمزنگاری، `edge_hls`» |

### ۶.۲. لبه/سرور خارجی
| کلید | پیش‌فرض | clamp | راهنمای ساده |
|---|---|---|---|
| `edge_public_host` | `''` | دامنهٔ معتبر | «دامنه‌ای که کاربر می‌بیند (مثلاً files.mysite.com)» |
| `edge_base_url` | `''` | HTTPS | «آدرس پایهٔ مسیر فایل روی لبه» |
| `edge_origin_header_name` | `X-Origin-Token` | هدر HTTP معتبر و غیررزروشده | «نام هدر مخفی برای احراز مبدأ» |
| `edge_origin_header_value` | `''` | ≥۳۲ کاراکتر | «مقدار هدر مخفی (با دکمهٔ ایجاد)» |
| `edge_allowed_hosts` | `[]` | آرایه | «میزبان‌های مجاز اضافی» |
| `edge_signing_secret` | `''` | ≥۳۲ کاراکتر | «راز امضای لینک لبه (با دکمهٔ ایجاد)» |
| `edge_signing_key_id` | `k1` | متن کوتاه | «شناسهٔ کلید امضا (در URL می‌آید؛ برای چرخش بدون قطعی)» |
| `signed_url_ttl` | `3600` | ۳۰۰–۱۴۴۰۰ | «عمر امضای URL لبه/قطعه (ثانیه)؛ باید از طول آهنگ بیشتر باشد» |

> **چرخش راز لبه:** هنگام تغییر `edge_signing_secret`، `edge_signing_key_id` را عوض کنید و در پنجرهٔ مهاجرت هر دو راز قدیم/جدید را بپذیرید؛ URLهای در جریان با `kid` قدیم تا انقضا کار میکنند. لبه باید نقشهٔ `kid → secret` را داشته باشد؛ در نبود `kid` یا ناشناخته‌بودن آن، درخواست ۴۰۳ شود. مقدار `edge_origin_header_name` نباید از هدرهای رزروشده مثل `Host`، `Authorization`، `Cookie`، `Content-Length` یا `X-Forwarded-*` باشد؛ لبه باید هر هدر هم‌نام ورودی از کلاینت را حذف کند. اگر `edge_signing_secret` خالی یا کوتاه باشد، حالت‌های لبه fail-closed و غیرفعال می‌شوند.

### ۶.۳. نشست و ضد‌اشتراک‌گذاری
| کلید | پیش‌فرض | clamp | راهنمای ساده |
|---|---|---|---|
| `session_enabled` | `enabled` | — | «اتصال پخش به نشست کاربر (جلوگیری از اشتراک لینک)» |
| `session_bind_ip` | `enabled` | — | «اگر روشن باشد، لینک در آی‌پی دیگر کار نمی‌کند» |
| `session_bind_ua` | `disabled` | — | «اتصال به مرورگر (ممکن است پخش‌کننده‌های ثالث را محدود کند)» |
| `session_ttl` | `60` | ۳۰–۹۰۰ | «مدت اعتبار کوکی/lease نشست (ثانیه)؛ عمر URL جداست» |
| `session_sliding_renewal` | `enabled` | — | «تمدید خودکار تا وقتی پخش ادامه دارد» |
| `session_max_concurrent` | `1` | ۱–۱۰ | «حداکثر پخش همزمان برای هر حساب» |
| `session_lease_grace` | `120` | ۳۰–۶۰۰ | «مهلت آزادسازی خودکار lease پس از توقف heartbeat (ثانیه)» |
| `session_store` | `table` | `table` \| `transient` | «محل نگهداری نشست (جدول = دقیق‌تر، transient = سبک‌تر)» |

> **CSRF و کوکی:** درخواست‌های `POST` مسیرهای `session`/`heartbeat`/`close` باید nonce وردپرس (`X-WP-Nonce` با action `wp_rest`) را اعتبارسنجی کنند. کوکی نشست `HttpOnly`+`Secure`+`SameSite=Lax` است، مقدارش توکن تصادفی مستقل از `sid` است و DB فقط `hash` آن را نگه می‌دارد. مسیرهای `GET` کلید/manifest/segment وضعیت را تغییر نمی‌دهند و فقط با امضای نشست معتبر پاسخ می‌دهند. `session_bind_ua` اختیاری است و پیش‌فرض خاموش می‌ماند تا پخش‌کننده‌های ثالث محدود نشوند.

> **نکتهٔ شبکهٔ موبایل:** با `session_bind_ip=enabled`، تغییر IP (جابه‌جایی Wi-Fi/داده یا CGNAT) می‌تواند پخش را قطع کند؛ کاربر باید پخش را دوباره شروع کند. اگر این برای مخاطب آزارنده است، `session_bind_ip` را خاموش کنید و به `edge_hls`/`proxy` تکیه کنید.

> نشست فقط بر سه حالت جدید (`edge_signed`، `proxy`، `edge_hls`) اثر دارد. `local` و `remote_redirect` رفتار فعلی خود را دارند و نشست برایشان ساخته نمی‌شود؛ بنابراین خریداری که ارتقا نمی‌دهد هیچ تغییر رفتاری نمی‌بیند.

> **IP و پروکسی:** برای `ip_hash` از `ClientIp::get()` استفاده شود. `X-Forwarded-For` فقط وقتی معتبر است که `REMOTE_ADDR` در allowlist پروکسی‌های مورد اعتماد باشد؛ وگرنه مهاجم با هدر جعلی قید IP را دور می‌زند.
> **عمر نشست ≠ عمر URL:** `session_ttl` عمر کوکی/lease است و با heartbeat کشویی تمدید می‌شود؛ `signed_url_ttl` عمر امضای URL است و باید از مدت آهنگ بیشتر باشد، وگرنه پخش آهنگ بلندتر از TTL قطع می‌شود. کلید همچنان فقط با نشست زنده صادر می‌شود.

### ۶.۴. رمزنگاری و HLS
| کلید | پیش‌فرض | clamp | راهنمای ساده |
|---|---|---|---|
| `stream_encryption` | `aes128` | `off` \| `aes128` | «رمزنگاری قطعات؛ بدون DRM و بدون لایسنس بیرونی» |
| `hls_enabled` | `disabled` | — | «تحویل تکه‌تکه؛ با انتخاب `edge_hls` خودکار روشن می‌شود» |
| `hls_segment_seconds` | `6` | ۲–۱۵ | «طول هر تکه (ثانیه)» |
| `hls_renditions` | `[128,320]` | زیرمجموعهٔ `[64,128,192,320]` | «کیفیت‌های ساخته‌شده (هزینهٔ CPU و فضا)» |
| `hls_packaging` | `on_upload` | `on_upload` \| `on_demand` | «هنگام آپلود (ارزان) یا در لحظهٔ پخش (سنگین)» |
| `hls_proxy_segments` | `disabled` | — | «قطعات از سایت عبور کنند (کندتر) یا از CDN (سبک‌تر)» |
| `ffmpeg_binary` | `ffmpeg` | مسیر فایل | «مسیر اجرایی ffmpeg اگر در PATH نیست» |
| `ffprobe_binary` | `ffprobe` | مسیر فایل | «مسیر اجرایی ffprobe برای خواندن مدت آهنگ» |

> **پروکسی از تنظیم موجود استفاده می‌کند:** برای حالت `proxy` همان `sendfile_mode` موجود در `VipSettings` (`none`/`xsendfile`/`xaccel`) به کار می‌رود؛ `none` یعنی جریان PHP (fallback اضطراری). کلید جدیدی برای offload پروکسی ساخته نشود تا دو منبع حقیقت نداشته باشیم.
> **هشدار `stream_encryption=off`:** قطعهٔ لو رفته بدون کلید هم قابل پخش است و فقط امضای URL محافظت می‌کند؛ پنل باید هشدار قرمز نشان دهد و `off` را فقط برای سازگاری اضطراری مجاز بداند.
> **پیش‌نیاز ffmpeg:** `HlsPackager` به `ffmpeg` و `ffprobe` روی سرور نیاز دارد (مسیرها با `ffmpeg_binary`/`ffprobe_binary` قابل تنظیم است). اگر موجود نباشند، `HlsDiagnostics` خطا می‌دهد و `edge_hls` نباید فعال شود؛ خریدار باید `edge_signed`/`proxy` را انتخاب کند.

> **بسته‌بندی `on_demand`:** این حالت بسته‌بندی را در صف قرار می‌دهد و درخواست پخش با `425 Too Early` + `Retry-After` برمی‌گردد؛ هرگز بسته‌بندی هم‌زمان و سنگین داخل درخواست پخش انجام نشود.
> **بسته‌بندی پس‌زمینه:** بسته‌بندی (به‌ویژه `on_upload`) باید در یک job پس‌زمینه/WP-Cron اجرا شود و درخواست آپلود یا پخش را معطل نکند؛ وضعیت `queued`/`packaging` در پنل نمایش داده شود.

> **قاعدهٔ فعال‌سازی (مهم):** `hls_enabled` و `stream_encryption` فقط وقتی اثر دارند که `delivery_provider = edge_hls` و دارایی موردنظر واقعاً بسته‌بندی شده باشد (`hls_state = ready`). در `local` و `remote_redirect` مسیر پخش همان رفتار فعلی است؛ در `edge_signed` پخش از URL امضاشدهٔ لبه و در `proxy` از جریان Range سایت انجام می‌شود؛ در هیچ‌یک از این چهار حالت HLS/رمزنگاری اثر ندارد. پنل باید در همان تب وضعیت «بی‌اثر در حالت فعلی» را نشان دهد و مقدار ذخیره‌شده را پاک نکند. اگر `edge_hls` انتخاب شود ولی دارایی بسته‌بندی نشده باشد، تحویل به `edge_signed` (اگر لبه پیکربندی شده) برمی‌گردد؛ در غیر این صورت پاسخ ۵۰۳ امن می‌دهد و هرگز ۳۰۲ به مبدأ یا فایل محلی خام نمی‌زند.

> **راهنمای پیشنهاد پیش‌فرض برای خریدار:** اگر می‌خواهی بایت‌های رسانه روی CDN بروند و فقط کلید روی سایت باشد → `edge_hls` با `hls_proxy_segments=disabled`. اگر می‌خواهی حتی CDN هم مبدأ را نبیند → `proxy` با `sendfile_mode=xaccel`. اگر پخش ساده و بدون بسته‌بندی می‌خواهی → `edge_signed`.

### ۶.۵. آپلود خودکار به سرور دانلود خارجی [جدید]

| کلید | پیش‌فرض | clamp/مقادیر | راهنمای ساده |
|---|---|---|---|
| `remote_upload_enabled` | `disabled` | `enabled`/`disabled` | «فایل‌های جدید خودکار روی سرور دانلود خارجی بارگذاری شوند» |
| `remote_upload_mode` | `webhook` | `webhook` \| `s3` \| `webdav` \| `sftp` | «روش اتصال به سرور دانلودت؛ اگر فقط یک لینک آپلود داری، webhook را انتخاب کن» |
| `remote_upload_endpoint` | `''` | HTTPS، همان قواعد میزبان `direct:` | «لینک آپلودی که از سرور دانلودت گرفته‌ای» |
| `remote_upload_auth_token` | `''` | فیلد password، با دکمهٔ ایجاد/پاک‌سازی | «توکن/کلید دسترسی سرور دانلود (اگر لازم دارد)» |
| `remote_upload_access_key` / `remote_upload_secret_key` | `''` | فقط برای `s3` | «کلید دسترسی/رمز S3-سازگار (مثل R2، B2، MinIO)» |
| `remote_upload_bucket` | `''` | فقط برای `s3` | «نام باکت» |
| `remote_upload_path_prefix` | `''` | متن، بدون `..`/کاراکتر کنترلی | «پیشوند مسیر روی سرور دانلود (اختیاری)» |
| `remote_upload_max_file_mb` | `0` (نامحدود) | ۰ یا عدد مثبت | «حداکثر حجم هر فایل برای آپلود خودکار (۰ = بدون محدودیت)» |
| `remote_upload_keep_local` | `enabled` | `enabled`/`disabled` | «بعد از تایید آپلود موفق، نسخهٔ محلی هم نگه داشته شود یا حذف شود تا فضا آزاد شود» |
| `remote_upload_verify` | `enabled` | `enabled`/`disabled` | «بعد از آپلود، اندازه/checksum با سرور مقصد تطبیق داده شود» |
| `remote_upload_max_retries` | `5` | ۱–۱۰ | «تعداد تلاش مجدد خودکار در صورت قطع شبکه» |
| `remote_upload_concurrency` | `2` | ۱–۵ | «چند فایل هم‌زمان آپلود شوند (عدد بالاتر = سریع‌تر ولی فشار بیشتر روی سرور وردپرس)» |
| `s3_multipart_threshold_mb` | `100` | ۱۰–۱۰۲۴، فقط `s3` | «فایل‌های بزرگ‌تر از این حد با Multipart Upload ارسال شوند» |
| `remote_upload_insecure_tls` | `disabled` | `enabled`/`disabled` | «فقط برای آزمایش محلی با گواهی خودامضا؛ در سایت واقعی هرگز روشن نکن» |

> **قاعدهٔ فعال‌سازی:** این بخش فقط وقتی اثر دارد که `remote_upload_enabled=enabled` **و** `delivery_provider` فعلی به بایت‌های خارجی نیاز داشته باشد (`remote_redirect`/`edge_signed`/`edge_hls` بدون `hls_proxy_segments`). در `local` و `proxy` این تنظیمات بی‌اثرند و پنل همین را در همان تب نشان می‌دهد؛ مقدار ذخیره‌شده پاک نمی‌شود (دقیقاً همان قاعدهٔ موجود برای `hls_enabled`/`stream_encryption`، بخش ۶.۴).
> **اگر اعتبارنامه کوتاه/خالی باشد:** مثل `remote_signing_secret`/`edge_signing_secret` موجود، آپلود خودکار fail-closed می‌شود (در صف `failed` با پیام روشن می‌ماند) و هرگز تلاش نمی‌کند با اعتبارنامهٔ ضعیف/خالی داده بفرستد.
> **محدودیت صادقانه:** تایید checksum پس از آپلود به توانایی پروتکل مقصد بستگی دارد؛ S3-سازگار معمولاً ETag قابل‌اتکا می‌دهد، webhook سفارشی ممکن است فقط کد ۲۰۰ بدهد. در حالت دوم، تایید به «اندازهٔ فایل دوباره‌خوانی‌شده» تنزل می‌یابد و این صراحتاً در UI نوشته می‌شود — وعدهٔ تایید رمزنگاری‌شده برای هر پروتکل داده نمی‌شود.

### ۶.۶. تحویل محلی هوشمند [جدید/ارتقا]

| کلید | پیش‌فرض | clamp/مقادیر | راهنمای ساده |
|---|---|---|---|
| `local_sendfile_autodetect` | `enabled` | `enabled`/`disabled` | «پیشنهاد خودکار بهترین روش offload (nginx/آپاچی) هنگام باز کردن تب تنظیمات» |
| `local_selfheal_enabled` | `enabled` | `enabled`/`disabled` | «ترمیم خودکار فایل‌های محافظتی (.htaccess و…) اگر روزی حذف شدند» |
| `local_checksum_autorefresh` | `enabled` | `enabled`/`disabled` | «اگر فایلی با همان اندازهٔ قبلی جایگزین شد، اثر انگشت آن خودکار دوباره محاسبه شود» |
| `local_stream_chunk_kb` | `1024` | ۶۴–۸۱۹۲ | «اندازهٔ هر تکه در پخش Range محلی (برای هاست کم‌حافظه کوچک‌تر کن)» |

> **چرا `local_sendfile_autodetect` فقط «پیشنهاد» می‌دهد، نه تغییر خودکار:** تغییر خودکار `sendfile_mode` می‌تواند یک تنظیم دستی سالم را بشکند (مثلاً وقتی ادمین خودش nginx را برای `xaccel` پیکربندی کرده). پیشنهاد در `LocalDeliveryDiagnostics` نمایش داده می‌شود و ادمین با یک کلیک می‌پذیرد یا رد می‌کند؛ هیچ تغییری بدون تایید دستی ذخیره نمی‌شود.

---

## ۷. جریان‌های سرتاسری

### ۷.۱. `edge_signed` (بدون HLS)
```
POST /streams/{id}/session                 → ساخت نشست + کوکی HttpOnly + URL امضاشده
GET  {edge_host}/s/{sid}/{opaque}?exp=<unix-ts>&iph=<hash>&kid=<key-id>&sig=...
     CDN --(X-Origin-Token)--> سرور خصوصی (آدرس واقعی فقط در تنظیم pull مبدأ)
```
- `{opaque}` مسیر تصادفی است و **نام یا پسوند فایل واقعی را ندارد**؛ دامنه و مسیر مبدأ هرگز در HTML/JSON کاربر نمی‌آید.
- `exp` **زمان مطلق یونیکس** است (نه «ثانیهٔ باقی‌مانده») تا لبه بتواند بدون اختلاف ساعت و بدون حالت مبهم اعتبارسنجی کند.
- `sig = HMAC(edge_signing_secret, method|sid|opaque|exp|iph|kid)` و کوتاه‌شده؛ تغییر هر پارامتر امضا را باطل می‌کند. `kid` کلید امضا را مشخص می‌کند تا چرخش راز بدون قطعی انجام شود.
- **اتصال IP در لبه:** `iph` هش IP با نمک سایت است (نه خود IP). لبه برای اعمال واقعی «لینک در IP دیگر کار نمی‌کند» باید `iph` را با هش IP کلاینت مقایسه کند (Cloudflare Worker/NGINX Lua یا معادل). اگر لبه این قابلیت را ندارد، `edge_signed` فقط امضا/انقضا را در لبه اعمال می‌کند؛ در این حالت برای ضداشتراک‌گذاری واقعی `edge_hls` (کلید IP-bound روی وردپرس) یا `proxy` را انتخاب کنید.
- **جهت هدر مبدأ:** لبه امضای کاربر، `exp` و در صورت پشتیبانی `iph` را بررسی می‌کند و سپس درخواست را با هدر `X-Origin-Token` به مبدأ می‌فرستد. **مبدأ** باید این هدر (و در صورت امکان IP-allowlist لبه) را بررسی کند؛ اگر هدر نباشد پاسخ ۴۰۳ می‌دهد. لبه باید هر هدر ورودی هم‌نام از سمت کلاینت را حذف/بازنویسی کند و مبدأ نباید جز از طریق لبه قابل دسترسی باشد. اگر مبدأ یک فایل‌سرور ساده (nginx/Apache) است، بررسی هدر و IP-allowlist باید در config آن تنظیم شود؛ صرفِ فرستادن هدر توسط لبه بدون بررسی مبدأ محافظتی نمی‌دهد.

### ۷.۲. `proxy`
```
POST /streams/{id}/session            → کوکی نشست
GET  /streams/{id}?token=<تیکت مات>          → ProxyStreamProvider (پیاده‌ساز DownloadProvider + StreamableDownloadProvider)
     → X-Accel-Redirect/X-Sendfile به وب‌سرور → فایل واقعی
```
هیچ هدر `Location` به کاربر فرستاده نمی‌رود؛ فقط دامنهٔ سایت دیده می‌شود. حالت `proxy` از نظر مصرف سرور سنگین‌ترین حالت است؛ وقتی بالاترین مخفی‌کاری مبدأ مهم است آن را انتخاب کن، نه به‌عنوان پیش‌فرض. `sendfile_mode` باید `xaccel` یا `xsendfile` باشد و `none` (جریان PHP) فقط fallback اضطراری است.

> **هدرهای offload:** `X-Accel-Redirect`/`X-Sendfile` را وب‌سرور مصرف می‌کند و نباید به کلاینت برسد. location داخلی باید `internal` باشد و دسترسی مستقیم HTTP به مسیر فایل بسته شود؛ وگرنه مسیر واقعی لو می‌رود. با `sendfile_mode=none`، PHP خودش بایت‌ها را می‌فرستد و باید `set_time_limit(0)` و خاموش‌کردن بافر خروجی تنظیم شود.

### ۷.۳. `edge_hls` + AES-128 (کامل‌ترین)
```
1) آپلود فایل → HlsPackager: renditions + قطعات رمزنگاری‌شده با AES-128
   ├─ کلید در AssetKeyStore (wrapped با راز سایت؛ فقط روی وردپرس)
   └─ قطعات رمزنگاری‌شده روی CDN
2) پخش: POST /streams/{id}/session → کوکی HttpOnly + manifest URL
3) GET /wp-json/music-wave/v1/streams/{id}/manifest?sid=...&exp=<unix-ts>&kid=<key-id>&sig=...  (same-origin، private/no-store)

      #EXT-X-KEY:METHOD=AES-128,URI="https://site.example/wp-json/music-wave/v1/streams/{id}/key?sid=...&exp=<unix-ts>&kid=<key-id>&sig=..."

4) hls.js / Safari: قطعهٔ رمزنگاری‌شده را از CDN می‌گیرد،
   کلید را از /key (same-origin، کوکی خودکار ارسال می‌شود) می‌گیرد
5) /heartbeat هر ~۳۰ثانیه TTL را تمدید می‌کند؛ /close هنگام پایان lease را آزاد می‌کند
```
**جزئیات لازم برای درست کار کردن:**
- URI کلید باید **مطلق و same-origin با سایت** باشد؛ هرگز روی CDN قرار نگیرد. چون same-origin است، کوکی HttpOnly بدون تنظیم `withCredentials` هم ارسال می‌شود و CORS لازم نیست.
- URI هر قطعه و هر variant playlist در manifest باید **مطلق و امضاشده به دامنهٔ لبه/CDN** باشد (مثلاً `https://files.mysite.com/...?exp=...&iph=...&kid=<key-id>&sig=...`)؛ مسیر/میزبان مبدأ هرگز در manifest نوشته نمی‌شود. اگر `hls_proxy_segments=enabled` باشد، این URIها به مسیر same-origin `/segment` اشاره می‌کنند.

- قطعات cross-origin از CDN می‌آیند: CDN باید `Access-Control-Allow-Origin` را دقیقاً برای دامنهٔ سایت (نه `*` وقتی چند دامنه ممکن است) بدهد، `Vary: Origin` بفرستد و `Access-Control-Expose-Headers: Content-Range, Content-Length, Accept-Ranges`. برای درخواست قطعه اعتبارنامه لازم نیست (URL امضاشده است)، پس `withCredentials` روی قطعات روشن نشود. اگر از دامنهٔ لبهٔ اختصاصی استفاده می‌شود، همان دامنه در allowlist باشد.

- `IV` هر قطعه باید یکتا باشد. امن‌ترین انتخاب سازگار: **بدون صفت `IV`** و اتکا به قاعدهٔ استاندارد HLS (IV = شمارهٔ ترتیب قطعه) که خودش یکتاست، یا اگر بسته‌بند صفت `IV` می‌نویسد، مقدار تصادفی ۱۶ بایتی یکتا برای هر قطعه. چون `IV` صفت `#EXT-X-KEY` است، برای `IV` تصادفی باید **برای هر قطعه یک تگ `#EXT-X-KEY` جداگانه** نوشته شود؛ یک تگ مشترک با `IV` ثابت ممنوع است. ذخیرهٔ یک `iv_base` ثابت برای همهٔ قطعات نیز ممنوع است.

- manifest: `Cache-Control: private, no-store` + `Vary: Cookie`؛ نباید روی CDN کش شود.
- پاسخ `/key`: `Cache-Control: no-store`، `Content-Type: application/octet-stream`، طول دقیقاً ۱۶ بایت، فقط با نشست معتبر و امضای معتبر؛ در غیر این صورت ۴۰۳ بدون بدنه.
- **URL تازه در هر درخواست:** master/variant playlist از URIهای نسبی/placeholder ذخیره‌شده ساخته شود و در هر درخواست با URL تازه‌امضاشده رندر شود؛ playlist ذخیره‌شده با URL ثابت بعد از `exp` بی‌اعتبار می‌شود.
- **عمر امضا:** `exp` هر segment/variant برابر `now + max(signed_url_ttl, duration + 300)` (سقف ۱۴۴۰۰) باشد تا آهنگ بلندتر از TTL قطع نشود؛ `session_ttl` کوتاه مستقل از این مقدار است.
- **هدرهای پاسخ:** manifest با `Content-Type: application/vnd.apple.mpegurl`، قطعه با `video/mp2t` (یا `audio/mp4` برای fMP4)، کلید با `Content-Type: application/octet-stream` و `Content-Length: 16`؛ همه با `X-Content-Type-Options: nosniff` و `Referrer-Policy: no-referrer` تا URL امضاشده در Referer به مقصد دیگر نرود. اگر خود سایت پشت CDN است، مسیرهای `/manifest` و `/key` را از کش CDN مستثنی کنید (bypass). قطعات رمزنگاری‌شده می‌توانند `Cache-Control: public, max-age=...` بگیرند چون بدون کلید بی‌ارزش‌اند؛ manifest و key هرگز کش نشوند.

- **کلید و دسترسی:** `/key` پیش از صدور، نشست را زنده و `AccessPolicyEngine` را دوباره بررسی می‌کند؛ برای «نشست نامعتبر» و «دسترسی ندارد» پاسخ ۴۰۳ یکسان بدهید تا وضعیت اشتراک لو نرود.
- **فایل key-info موقت:** اگر `HlsPackager` از `ffmpeg -hls_key_info_file` استفاده می‌کند، فایل را در `sys_get_temp_dir()` بیرون web root با مجوز `0600` بسازد و پس از اتمام حذف کند؛ مسیر کلید در آرگومان قابل مشاهده یا log ننشیند.
**اگر لینک قطعه لو برود:** رمزنگاری‌شده است → بدون کلید بی‌ارزش.
**اگر لینک/کوکی کپی شود:** کلید برای نشست/آی‌پی دیگر صادر نمی‌شود.
**معماری:** بایت روی CDN، کلید فقط روی وردپرس.

### ۷.۴. آپلود خودکار یک دارایی تازه به سرور دانلود [جدید]
```
1) ادمین فایل را در وردپرس آپلود/ثبت می‌کند (همان مسیر فعلی `upload()`/`import_attachment()`)
   → ProtectedAssetRegistry::register() → رویداد music_wave_vip_asset_registered

2) RemoteUploadManager:
   - آیا remote_upload_enabled=enabled؟ آیا delivery_provider فعلی به بایت خارجی نیاز دارد؟
     نه → پایان (هیچ آپلودی رخ نمی‌دهد)
     بله ↓
   - ردیف pending در mw_vip_upload_queue (idempotent بر اساس asset_key)

3) wp_schedule_single_event فوری + کرون دقیقه‌ای پشتیبان
   → RemoteUploadQueue::claim() تا سقف remote_upload_concurrency

4) RemoteUploadManager::sync(asset_key):
   checksum محلی == checksum/وضعیت ریموت؟
     بله → status=synced (بدون انتقال بایت)
     نه  ↓
   آداپتور انتخاب‌شده (webhook/s3/webdav/sftp) → استریم فایل با احراز هویت

5) موفق + تایید (verify) → status=synced، remote_path/uploaded_at ثبت می‌شود
                          → اگر remote_upload_keep_local=disabled، نسخهٔ محلی حذف می‌شود
   ناموفق → attempts++, next_retry_at با backoff؛ پس از سقف → status=failed (در دیاگنوستیک نمایش)
```
هیچ‌کدام از این مراحل روی ترد درخواست HTTP ادمین اجرا نمی‌شود (مثل بسته‌بندی HLS، کاملاً پس‌زمینه است)، و شکست آپلود یک فایل، تحویل فایل‌های از پیش همگام‌شدهٔ دیگر را مسدود نمی‌کند — چون هر دارایی مستقلاً resolve/serve می‌شود.

---

## ۸. امنیت به‌ازای هر نیاز

| نیاز کاربر | کنترل در این ارتقاء |
|---|---|
| آدرس واقعی فایل لو نرود | حالت `edge_signed`/`proxy`/`edge_hls` — مبدأ پشت لبه/پروکسی |
| لینک بعد از مدتی بمیرد | `session_ttl` کوتاه برای کوکی/lease + `signed_url_ttl` و امضای `exp` روی manifest/قطعه/کلید؛ کلید فقط با نشست زنده |
| لینک قابل اشتراک نباشد | کوکی HttpOnly با توکن مستقل + اتصال نشست به user/ip (و در صورت فعال‌بودن UA)؛ `sid` در URL به‌تنهایی دسترسی نمی‌دهد |
| محدودیت اشتراک‌گذاری حساب | `session_max_concurrent` (lease) |
| حتی لو رفتن قطعه بی‌خطر باشد | `stream_encryption=aes128` (کلید نشست‌محور) |
| دستکاری پارامترها | امضای کامل مسیر (HMAC) روی `method`، `sid`، مسیر مات، `exp`، `iph` و `kid` |
| فشار روی سرور کم باشد | بایت‌ها روی CDN؛ کلید فقط JSON کوچک؛ پکیج یک‌باره در آپلود |
| سازگاری با WP/رابط‌های فعلی | حفظ `local`/`remote_redirect` و فیلترهای موجود |
| بدون دخالت دستی، فایل روی سرور دانلود قرار بگیرد | خط‌لولهٔ آپلود خودکار (بخش ۴.۲.۱/۷.۴)؛ صف پس‌زمینه + تلاش مجدد خودکار |
| آپلود تکراری/اسراف پهنای باند نشود | dedupe با مقایسهٔ checksum محلی/ریموت پیش از انتقال بایت |
| اعتبارنامهٔ آپلود در دیتابیس لو نرود | `sodium_crypto_secretbox` + HKDF از `AUTH_KEY`/`SECURE_AUTH_SALT` (همان `SecretBoxCipher` مشترک با کلید HLS) |
| endpoint آپلود برای SSRF سوءاستفاده نشود | همان اعتبارسنج میزبان `DirectLinkUrlValidator` (HTTPS اجباری، رد IP خصوصی/loopback/متادیتا) |
| نسخهٔ محلی زودهنگام حذف نشود | `remote_upload_keep_local` پیش‌فرض روشن؛ حذف فقط پس از تایید موفق آپلود |
| فایل جایگزین‌شده با همان حجم درست تشخیص داده شود | تازه‌سازی خودکار checksum بر اساس `filemtime` در تحویل محلی (بخش ۴.۵) |
| فایل محافظتی گم‌شدهٔ پوشهٔ محلی به‌تنهایی خطر نشود | خودترمیمی روزانهٔ `.htaccess`/`web.config`/`index.html` با ثبت در لاگ حسابرسی |

---

## ۹. جزئیات فنی AES-128 در HLS

- **روش استاندارد HLS:** هر قطعه با AES-128 در حالت CBC رمز می‌شود؛ `#EXT-X-KEY` به URI کلید اشاره می‌کند؛ کلید ۱۶ بایتی.
- **پشتیبانی مرورگر:** Safari بومی؛ Chrome/Firefox با `hls.js` (رمزگشایی سمت JS). پس بدون DRM، روی همهٔ مرورگرها کار می‌کند.
- **IV هر قطعه:** باید یکتا باشد. حالت سازگار و پیشنهادی، حذف صفت `IV` و اتکا به قاعدهٔ استاندارد HLS است (IV = شمارهٔ ترتیب قطعه در همان rendition). اگر صفت `IV` نوشته می‌شود، باید ۱۶ بایت تصادفی یکتا در هر قطعه باشد. یک `iv_base` ثابت برای همهٔ قطعات ممنوع است؛ با کلید یکسان، بلوک‌های اول یکسان، متن رمزشدهٔ یکسان می‌دهند و الگو لو می‌رود.
- **محل کلید:** فقط `StreamKeyEndpoint` روی وردپرس؛ هرگز روی CDN. کلید در DB **wrapped** با `sodium_crypto_secretbox` ذخیره می‌شود و کلید بسته‌بندی از `AUTH_KEY`+`SECURE_AUTH_SALT` با HKDF مشتق می‌شود. کلید خام فقط در حافظهٔ همان درخواست وجود دارد.
- **چرخش کلید:** `key_id`/`key_version` مانند الگوی موجود `remote_key_id`/`kid`؛ چند کلید هم‌زمان معتبر، آخرین نسخه برای بسته‌بندی جدید، نسخه‌های قبلی فقط برای پخش بسته‌های قدیمی. چرخش بدون قطعی.
- **تمامیت (محدودیت واقعی):** AES-128-CBC در HLS فقط **محرمانگی** می‌دهد و هیچ MAC/AEAD ندارد؛ پخش‌کننده دستکاری قطعه را رمزنگاری‌ای تشخیص نمی‌دهد. کنترل دستکاری از مسیر HTTPS، URL امضاشده و CDN می‌آید، نه از الگوریتم. این را در سند به خریدار صریح بگویید و وعدهٔ «ضد دستکاری رمزنگاری‌شده» ندهید.
- **محدودیت مدل کلید (صادقانه):** کلید محتوا برای هر دارایی یکی است و برای نشست‌های مختلف فقط **مسیر تحویل** فرق می‌کند. یعنی کاربر مجاز و متخصص می‌تواند کلید را استخراج کند و قطعات را برای خودش نگه دارد. این محافظت در برابر اشتراک لینک و کپی ساده است، نه در برابر مشترک مصمم. برای آن سطح، DRM لازم است که در این نسخه و در دامنهٔ این سند نیست.
- **بار پردازشی:** رمزگشایی AES روی دستگاه کاربر انجام می‌شود؛ وردپرس برای هر قطعه فقط یک پاسخ ۱۶ بایتی کلید می‌دهد و خودِ بایت‌ها از CDN می‌آیند.
- **fallback امن:** پخش `edge_hls` به `HlsRoutes` + دارایی `ready` + کلید سالم وابسته است؛ دانلود کامل در این حالت با `EdgeHlsProvider` مثل `EdgeSignedProvider` URL امضاشدهٔ لبه می‌دهد (و `StreamableDownloadProvider` فقط برای حالت `proxy` لازم است)، مشروط به اینکه سیاست دانلود اجازه دهد؛ فایل دانلودشده قابل نگهداری است. اگر `edge_hls` انتخاب شده ولی دارایی `ready` نیست یا ffmpeg موجود نیست: اگر لبه پیکربندی شده باشد به `edge_signed` برمی‌گردد، وگرنه پاسخ ۵۰۳ امن؛ در این حالت مسیر `/streams/{id}` عمداً ۴۰۹ می‌دهد و فایل محلی خام سرو نمی‌شود. هرگز ۳۰۲ به مبدأ یا فایل خام با مسیر مبدأ به کاربر داده نمی‌شود. حالت‌های فعلی `local`/`remote_redirect` رفتار قبلی خود را حفظ می‌کنند.

- **fallback سمت پخش‌کننده:** پخش‌کننده بر اساس `effective_mode` پاسخ `session` تصمیم می‌گیرد؛ اگر `edge_signed` باشد از `playback_url` استفاده می‌کند و اگر `edge_hls` باشد از `manifest_url`؛ برای `proxy` نیز از `playback_url` استفاده شود. اگر `hls.js` بارگذاری نشد یا دارایی `ready` نبود، سرور در پاسخ `session` حالت `edge_signed` و `playback_url` را برمی‌گرداند؛ اگر لبه پیکربندی نشده باشد، پیام روشن «پخش HLS در این مرورگر پشتیبانی نمی‌شود» نمایش داده می‌شود. هرگز در حالت `edge_hls` درخواست به `/streams/{id}` فرستاده نمی‌شود (طبق §۴.۴ پاسخ ۴۰۹ است).

---

## ۱۰. سازگاری عقب‌رو و مهاجرت

- مقادیر فعلی `local` و `remote_redirect` **بدون تغییر** باقی می‌مانند.
- **مالکیت جداول:** `mw_stream_sessions` را Core (`StreamSessionStore::ensure_table`/`dbDelta`) و `mw_vip_asset_keys` و ستون‌های HLS روی `mw_vip_assets` را VIP (`AssetKeyStore`/`ProtectedAssetRegistry`) می‌سازد و مهاجرت می‌دهد؛ Core هیچ کلاس VIP را لود نمی‌کند.
- جداول موجود (`mw_vip_assets`, `mw_download_replays`) با افزودن ستون/جدول **افزودنی** توسعه می‌یابند (`ensure_table` + `dbDelta`). بدون rename مخرب.
- **چرخش راز سایت:** اگر `AUTH_KEY`/`SECURE_AUTH_SALT` عوض شوند، کلیدهای wrapped موجود باز نمی‌شوند؛ پیش از چرخش باید re-wrap با `key_version` جدید انجام شود یا دارایی‌ها دوباره بسته‌بندی شوند.
- **چرخش راز لبه:** با `edge_signing_key_id` انجام شود و در پنجرهٔ مهاجرت دو راز پذیرفته شود؛ URLهای در جریان با `kid` قدیم تا انقضا معتبر می‌مانند.
- شناسه‌های `vip:<32hex>` و `local:` فعلی همچنان resolve می‌شوند.
- فیلترهای عمومی موجود (`music_wave_download_provider`, `music_wave_download_event`, …) با امضاهای فعلی کار می‌کنند؛ پارامترهای جدید افزودنی‌اند.
- اگر ادمین هیچ‌کدام از حالت‌های جدید را روشن نکند، رفتار پروژه **بی‌تغییر** است (opt-in کامل).
- **مالکیت جدول آپلود:** `mw_vip_upload_queue` و ستون‌های `upload_state`/`remote_path`/`uploaded_at` روی `mw_vip_assets` را VIP می‌سازد (Core نمی‌داند آپلود خودکار وجود دارد؛ فقط رویدادهای عمومی موجود را شلیک می‌کند که VIP به آن‌ها گوش می‌دهد).
- **چرخش اعتبارنامهٔ آپلود:** مثل راز لبه/راز HLS، تغییر `remote_upload_auth_token`/`remote_upload_secret_key` فوری اعمال می‌شود؛ ردیف‌های `pending`/`failed` موجود در صف با اعتبارنامهٔ جدید دوباره تلاش می‌کنند (اعتبارنامه در لحظهٔ اجرای هر job خوانده می‌شود، نه در لحظهٔ enqueue).
- **فعال‌کردن آپلود خودکار روی کتابخانهٔ موجود:** `remote_upload_enabled=enabled` باعث آپلود گذشته‌نگر خودکار نمی‌شود؛ خریدار باید یک‌بار دکمهٔ «همگام‌سازی کل کتابخانه» را بزند تا دارایی‌های از قبل ثبت‌شده هم صف شوند — تا فعال‌سازی ناخواسته باعث آپلود انبوه و مصرف پهنای باند غافلگیرکننده نشود.

---

## ۱۱. فازبندی و معیار پذیرش

| فاز | کار | معیار پذیرش |
|---|---|---|
| **F0 — تحویل محلی هوشمند** | `LocalDeliveryDiagnostics`، خودترمیمی ریشه، تازه‌سازی checksum (بخش ۴.۵) | جایگزینی فایل با همان حجم دیگر fail-closed نمی‌ماند؛ حذف `.htaccess` خودکار ترمیم می‌شود؛ بدون وابستگی به F1–F5 |
| **F1 — نشست** | `StreamSession*`, کوکی، TTL، concurrency، مسیر `session`/`heartbeat`/`close` | لینک در مرورگر/آی‌پی دیگر کار نمی‌کند؛ پخش دوم حساب مسدود می‌شود |
| **F2 — لبه امضاشده** | `EdgeSignedProvider`, `EdgeConnectionManager`, دکمهٔ تست اتصال | مبدأ در DevTools دیده نمی‌شود؛ `exp` می‌میرد |
| **F3 — پروکسی** | `ProxyStreamProvider` + X-Sendfile/X-Accel | فقط دامنهٔ سایت؛ ورکر PHP آزاد |
| **F4 — HLS+AES** | `HlsPackager`, `AssetKeyStore`, `StreamKeyEndpoint`, `HlsRoutes`, `hls.js` | قطعهٔ دانلودشده بدون کلید بی‌مصرف است؛ کلید بدون نشست معتبر صادر نمی‌شود |
| **F5 — UI/راهنما** | فیلدها، tooltipها، `HlsDiagnostics`, مستندات | خریدار بدون کدنویسی همه را از پنل تنظیم می‌کند |
| **F6 — آپلود خودکار** | `RemoteUploadManager`, آداپتورها (`Webhook`/`S3`/`WebDAV`/`SFTP`), `RemoteUploadQueue`, `RemoteUploadDiagnostics`, `SecretBoxCipher` مشترک | فایل تازه‌ثبت‌شده بدون دخالت دستی روی سرور دانلود قرار می‌گیرد؛ آپلود تکراری رخ نمی‌دهد؛ اعتبارنامه در DB سربسته است؛ قطع شبکه باعث تلاش مجدد خودکار می‌شود نه خطای کشنده؛ نیاز به F2 دارد (برای تعیین اینکه کدام حالت به بایت خارجی نیاز دارد) و می‌تواند موازی با F3/F4 ساخته شود |

F0 هیچ وابستگی‌ای به فازهای دیگر ندارد و می‌تواند زودتر از همه (یا موازی) تحویل داده شود. هر فاز باید تست‌های `tests/run.php` را سبز نگه دارد و رگرسیون `tests/template-integrity.php` را نشکند.

---

## ۱۲. خارج از دامنه (اختیاری‌های آینده)

- **DRM** (Widevine/FairPlay): هم‌ترازی کامل با اپل/اسپاتیفای، اما لایسنس/سرور/گواهی بیرونی لازم دارد.
- **واترمارک صوتی فورنزیک:** ردگیری منبع نشت؛ هزینهٔ CPU.
- **حسابرسی تخلف + هشدار ادمین:** زیرساخت آن با action فعلی `music_wave_download_event` آماده است؛ به‌عنوان گزینهٔ خاموش پیش‌فرض می‌تواند اضافه شود.
- **ABR پیشرفته (کیفیت‌های بیشتر/الگوریتم‌های پیچیده)، CBOR manifests و Low-Latency HLS:** بهینه‌سازی‌های آینده؛ نردبان ثابت کیفیت در دامنه است.

این موارد در این نسخه عامدانه بیرون‌اند تا دامنهٔ نسخهٔ اول محدود و قابل نگهداری بماند — اما معماری اجازهٔ افزودنشان را بدون بازنویسی می‌دهد.

---

## ۱۳. ADR پیشنهادی (0006)

**عنوان:** تحویل از لبهٔ امضاشده، آپلود خودکار به سرور دانلود و HLS رمزنگاری‌شدهٔ AES-128 بدون DRM
**تصمیم:**
1. افزودن حالت‌های `edge_signed`, `proxy`, `edge_hls` در کنار `local`/`remote_redirect`.
2. اتصال تحویل به «نشست کاربر» (کوکی HttpOnly، TTL کوتاه، سقف همزمانی).
3. رمزنگاری AES-128 قطعات HLS؛ کلید فقط از سایت و وابسته به نشست.
4. افزودن خط‌لولهٔ آپلود خودکار به سرور دانلود خارجی با الگوی آداپتور (webhook/S3-سازگار/WebDAV/SFTP اختیاری) پشت یک صف پس‌زمینه؛ idempotent، با dedupe بر اساس checksum و تلاش مجدد خودکار.
5. ارتقای تحویل محلی موجود با خودترمیمی ریشهٔ خصوصی و تازه‌سازی خودکار checksum (رفع بدهی فنی مستندشدهٔ سند مرجع).
6. همه‌چیز opt-in و از پنل تنظیمات؛ بدون هزینهٔ اجباری شخص‌ثالث.
**گزینه‌های رد‌شده:** DRM (هزینهٔ لایسنس بیرونی)، ۳۰۲ به مبدأ به‌عنوان پیش‌فرض (افشای مبدأ)، واترمارک/حسابرسی (تصمیم محصول این نسخه)، آپلود هم‌زمان با درخواست HTTP ادمین (ریسک timeout برای فایل‌های بزرگ — در عوض صف پس‌زمینه انتخاب شد)، SDK کامل AWS برای S3 (وابستگی سنگین روی هاست اشتراکی — در عوض امضای SigV4 سبک محلی).
**پیامد منفی:** بسته‌بندی HLS مصرف CPU/فضای یک‌باره دارد؛ `proxy` پهنای باند می‌خواهد؛ آپلود خودکار پهنای باند خروجی سرور وردپرس را موقتاً اشغال می‌کند (قابل‌تنظیم با `remote_upload_concurrency`)؛ حفرهٔ آنالوگ باقی می‌ماند.

**مراجع:** `docs/secure-downloads.md`, `docs/delivery-threat-model.md`, `docs/adr/0005-*.md`, OWASP SSRF/Unvalidated Redirect، OWASP File Upload Cheat Sheet، مستندات presigned URL سرویس‌های سازگار با S3.

---

## ۱۴. راهنمای سادهٔ کاربر نهایی (خریدار محصول)

**قدم‌به‌قدم، بدون کدنویسی:**

1. **حالت تحویل را انتخاب کن:** پیشنهاد ما `edge_hls` (امن + سبک) یا `proxy` (مخفی‌ترین).
2. **آدرس لبه را وارد کن:** `edge_public_host` = دامنه‌ای که می‌خواهی کاربر ببیند (مثل `files.mysite.com`) و `edge_base_url` = آدرس پایهٔ HTTPS همان لبه.
3. **دو راز بساز:** با دکمهٔ «ایجاد» برای «کلید مخفی امضای لینک (Edge secret)» و «هدر مخفی احراز هویت مبدأ».
4. **تنظیم هدر مبدأ را روی CDN/سرور دانلود اعمال کن** (یک‌بار؛ مقدار همان هدر مخفی).
5. **دکمهٔ «تست اتصال» را بزن:** اگر سبز شد، لبه درست وصل است.
6. **پخش را روی «نشست» بگذار:** `session_enabled=enabled`، `session_ttl=60`، `session_max_concurrent=1`، و `signed_url_ttl` را دست‌کم به مدت طولانی‌ترین آهنگ (مثلاً ۳۶۰۰) بگذار.
7. **پس از انتخاب `edge_hls`، HLS و رمزنگاری را روشن کن:** با انتخاب `edge_hls` مقدار `hls_enabled` خودکار `enabled` می‌شود؛ `stream_encryption` را روی `aes128` بگذار. اگر حالت دیگری انتخاب کرده‌ای، این دو کلید را دست نزن (در آن حالت‌ها بی‌اثرند). کیفیت‌ها را فقط اگر لازم داری اضافه کن.
8. **دکمهٔ «بسته‌بندی HLS» را روی کتابخانه بزن** تا فایل‌ها یک‌باره آماده شوند (بهترین زمان: یک‌بار در آپلود). سرور باید `ffmpeg`/`ffprobe` داشته باشد؛ `HlsDiagnostics` نبود آن‌ها را نشان می‌دهد. وضعیت هر دارایی باید `ready` شود؛ اگر `failed` شد، دلیل (ffmpeg/فضا/مجوز) را نشان می‌دهد.
9. **اگر فایل‌ها باید روی یک سرور دانلود جداگانه قرار بگیرند، آپلود خودکار را روشن کن:** `remote_upload_enabled=enabled` → `remote_upload_mode` را انتخاب کن (اگر از سرور دانلودت فقط «یک لینک آپلود» گرفته‌ای، `webhook` را بگذار) → آن لینک را در `remote_upload_endpoint` بچسبان → اگر توکن/رمز دادند، در فیلد اعتبارنامه بگذار و دکمهٔ «تست اتصال آپلود» را بزن. اگر سبز شد، دکمهٔ «همگام‌سازی کل کتابخانه» را بزن تا فایل‌های موجود هم یک‌بار منتقل شوند؛ فایل‌های تازه از این پس خودکار منتقل می‌شوند، بدون نیاز به FTP یا کار دستی.
10. تمام. از این پس کاربر فقط دامنهٔ خودت (یا دامنهٔ لبه) را می‌بیند؛ نشست/کوکی بعد از `session_ttl` (با heartbeat زنده می‌ماند) و URLها بعد از `signed_url_ttl` می‌میرند، و حتی قطعهٔ دانلودشده بدون کلید بی‌مصرف است.

**اگر فقط روی سرور خودت نگه می‌داری (بدون سرور دانلود جدا):** حالت `local` را دست نزن؛ همین حالا هم ترمیم خودکار فایل‌های محافظتی و تازه‌سازی خودکار اثر انگشت فایل روشن است (`local_selfheal_enabled`/`local_checksum_autorefresh`، پیش‌فرض هر دو روشن) و نیازی به هیچ تنظیم اضافه نیست.

**چه چیزی را انتخاب نکنم؟** `remote_redirect` را فقط اگر می‌خواهی آدرس سرور دانلود عمومی باشد (برای سازگاری قدیمی).

**چه چیزی هیچ‌وقت ممکن نیست؟** کسی که صدا را ضبط می‌کند (حفرهٔ آنالوگ) را هیچ روشی نمی‌شود بست — برای آن فقط DRM با لایسنس بیرونی کمی سخت‌ترش می‌کند. همچنین آپلود خودکار جایگزین پشتیبان‌گیری نیست؛ همیشه یک نسخهٔ پشتیبان از کتابخانه جدا نگه دار.
