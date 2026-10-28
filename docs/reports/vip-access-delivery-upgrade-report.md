# گزارش جامع مهندسی، امنیتی و عملکردی ارتقای سیستم VIP، سیاست دسترسی و تحویل فایل MusicWave

- **نسخه هسته (`music-wave-core`):** `0.16.0` (اسکیمای دیتابیس: `0.15.0` — بدون نیاز به مهاجرت جدول)
- **نسخه افزونه الحاقی (`music-wave-vip`):** `0.5.2` (`MIN_CORE_VERSION = 0.16.0`)
- **نسخه قالب (`musicwave`):** `0.9.2`
- **تاریخ تکمیل:** `2026-10-28`
- **شاخه گیت فعال جلسه:** `arena/63688393-musicwave-tow` *(یادداشت زیرساخت: در محیط Arena نام شاخه کاری به شناسه جلسه مقید است و تمام کامیت‌ها و تغییرات روی همین شاخه رسمی ثبت می‌شوند).*

---

## ۱. خلاصه مدیریتی (Executive Summary)

این ارتقا دو چالش کلیدی معماری و عملیاتی در اکوسیستم **MusicWave** را بدون هیچ‌گونه تغییر مخرب در داده‌های موجود یا نیاز به مهاجرت پایگاه‌داده (Zero Schema Migration) برطرف کرد:

1. **جداسازی کامل سیاست غیاب عضویت (`MembershipAbsencePolicy`) از متادیتای انتشارها:** پیش از این، غیرفعال بودن یا حذف موقت افزونه `music-wave-vip` نیازمند ویرایش دستی یا انبوه انتشارهای `purchase_or_membership` بود تا کاربران بدون اشتراک بتوانند فایل‌ها را دریافت کنند (یا برعکس، در حالت پیش‌فرض رفتار سیستم برای مدیر سایت شفاف نبود). اکنون سه حالت صریح و برگشت‌پذیر (`allow` پیش‌فرض سازگار، `open_download` برای بازگشایی موقت بدون دستکاری متا، و `deny` برای قفل کامل) پیاده‌سازی شده است؛ در هیچ حالتی انتشارهای صرفاً خریدنی (`purchase`)، محدود (`restricted`) یا مخصوص اعضا (`logged_in` برای مهمان) به‌طور ناخواسته باز نمی‌شوند.
2. **مسیریابی تحویل فایل مبتنی بر طرح‌واره (`DeliveryRouter`) + رفع باگ بحرانی امضای از راه دور VIP و چرخش کلید (`RemoteSigningKeyring`):**
   - هسته اکنون علاوه بر ارائه‌دهندگان محافظت‌شده خارجی/VIP (`vip:<32hex>` و `local:<path>`)، به صورت بومی از طرح‌واره کنترل‌شده `direct:https://...` برای دانلود و استریم امن پشتیبانی می‌کند (با عبور کامل از `AccessPolicyEngine`، محدودیت نرخ، بلیت کدر یک‌بارمصرف `mwt_...`، سهمیه روزانه پایدار در دیتابیس و هدرهای امنیتی `RedirectResponse`).
   - باگ بحرانی `RemoteRedirectProvider` در افزونه VIP که شناسه کدر `vip:<32hex>` را بدون مراجعه به `ProtectedAssetRegistry` به شکل `vip%3A...` در URL امضاشده قرار می‌داد، به طور کامل برطرف شد و مکانیزم مخزن کلید چندکلیدی غیر-autoload (`music_wave_vip_remote_keyring`) با پشتیبانی از ثابت `MUSIC_WAVE_VIP_REMOTE_SECRET` در `wp-config.php` و چرخش کلید بدون قطعی (`active` / `retired`) اضافه گردید.

---

## ۲. معماری پیاده‌سازی‌شده و تغییرات کلیدی به تفکیک ماژول

### ۲.۱. سیاست دسترسی و یکپارچگی اسکیمای متا (`music-wave-core`)
- **`src/Access/MembershipAbsencePolicy.php` (جدید):**
  - متمرکزسازی کامل تصمیم‌گیری در غیاب ارائه‌دهنده عضویت (`NullMembershipProvider`) با سه حالت مجاز:
    - `allow` (پیش‌فرض سازگار با عقب): باز کردن `membership` (`membership_provider_absent`) و حفظ الزام خرید برای `purchase_or_membership` متصل به محصول WooCommerce (`purchase_required`).
    - `open_download`: باز کردن هر دو حالت `membership` و `purchase_or_membership` با کد دلیل صریح `membership_absent_open_download` بدون دست زدن به متای پست‌ها.
    - `deny`: رد کامل (`membership_provider_absent` / `purchase_required`).
- **`src/Access/AccessPolicyEngine.php` و `src/Plugin.php`:**
  - رفع باگ `B-NEW-8`: خواندن مستقیم تنظیم `membership_absent_behavior` همراه با فیلتر `music_wave_membership_absent_behavior` و پشتیبانی از هر سه حالت مجاز.
- **`src/Schema/MetaDefinition.php`:**
  - رفع باگ `B-NEW-1`: اتصال پاک‌سازی `mw_access_mode` به `AccessModePolicy::sanitize_mode(..., 'restricted')` تا حالت `'logged_in'` هنگام ذخیره REST/گوتنبرگ به `'restricted'` بازنویسی نشود.
  - افزودن اعتبارسنجی سطح اسکیما برای `mw_download_asset_id` و ردیف‌های `mw_download_assets` جهت رد طرح‌واره‌های مخرب (`http:`، `javascript:`، `data:`، آدرس‌های شبکه داخلی و غیره) در کلیه مسیرهای نوشتن متا.

### ۲.۲. زیرساخت مسیریابی تحویل و لینک مستقیم کنترل‌شده (`music-wave-core`)
- **`src/Downloads/DirectLinkUrlValidator.php` (جدید):**
  - الزام قطعی `https://`، حداکثر طول `2048` کاراکتر، رد بایت NUL و کاراکترهای کنترلی، رد اطلاعات کاربری (`user:pass@`) و `fragment` (`#`).
  - مسدودسازی کامل SSRF و آدرس‌های داخلی: `localhost`، `*.localhost`، `*.local`، `*.internal`، `*.home.arpa`، `metadata.google.internal`، تمامی رنج‌های خصوصی/رزروشده IPv4 و IPv6 شامل `127.0.0.0/8`، `10.0.0.0/8`، `172.16.0.0/12`، `192.168.0.0/16`، `169.254.0.0/16`، CGNAT `100.64.0.0/10`، بنچمارک `198.18.0.0/15`، Multicast، آدرس‌های IPv4-mapped IPv6 (`::ffff:...`)، پیشوند NAT64 (`64:ff9b::/96`) و فرم‌های عددی/مبنای ۸ و ۱۶ IPv4.
  - اعمال لیست سفید اختیاری دامنه‌های مجاز (`direct_link_allowed_hosts` + فیلتر `music_wave_direct_link_allowed_hosts`) با پشتیبانی از دامنه دقیق و `*.example.com` هم در زمان ذخیره و هم در لحظه تحویل.
- **`src/Downloads/DirectLinkAsset.php` و `DirectLinkPermission.php` (جدید):**
  - تشخیص حساس و غیرحساس به حروف بزرگ/کوچک پیشوند `direct:` (مسدودسازی `DIRECT:http://...`) و الزام قابلیت `manage_mw_protected_assets` برای ثبت یا تغییر URL مستقیم (در حالی که ویرایشگر عادی همچنان می‌تواند برچسب کیفیت ردیف موجود را بدون تغییر URL ویرایش کند).
- **`src/Downloads/RedirectResponse.php`، `DirectLinkProvider.php`، `DeliveryRouter.php` و `Support/DeliveryCapabilities.php` (جدید):**
  - پاسخ تغییرمسیر مشترک `302` با هدرهای `Cache-Control: private, no-store, max-age=0`، `Referrer-Policy: no-referrer`، `X-Content-Type-Options: nosniff` و `X-Robots-Tag: noindex, nofollow`.
  - بررسی پیش از مصرف توکن یک‌بارمصرف در `DownloadRoutes::download()` و `DownloadResolver::deliver()` از طریق `DeliveryRouter::can_deliver_asset()` (برگرداندن `503 provider_unavailable` برای `vip:`/`local:` بدون VIP و `403 invalid_direct_url` برای لینک مستقیم نامعتبر پیش از سوزاندن بلیت).
  - پشتیبانی استریم (`StreamableDownloadProvider`) برای دارایی‌های صوتی `direct:` (`mp3`, `m4a`, `aac`, `ogg`, `opus`, `wav`, `flac`, `webm`) و رد استریم آرشیوهای غیرصوتی مانند `.zip`.

### ۲.۳. پایداری سهمیه روزانه، سقف TTL و پاک‌سازی کران (`music-wave-core`)
- رفع باگ `B-NEW-2`: ثبت محافظ `register_shutdown_function` پیش از اجرای `deliver()`/`stream()` در `DownloadResolver` تا حتی با اجرای `exit;` در ارائه‌دهنده‌های HTTP، شمارش سهمیه روزانه و رویداد ممیزی `music_wave_download_event` (`download_delivered`) با اطمینان ۱۰۰٪ ثبت شوند.
- رفع باگ `B-NEW-3`: انتقال شمارنده سهمیه روزانه دانلود (`mw_dl_quota_*`) از Transient فرّار به گزینه غیر-autoload در پایگاه‌داده (`autoload = false`) مقاوم در برابر `wp_cache_flush()`.
- رفع باگ `B-NEW-4`: افزایش `MAX_TTL` از `900` به `7200` ثانیه در `DownloadTokenService` و `OpaqueTicketStore` متناسب با سقف تنظیمات استریم (`stream_token_ttl`).
- رفع باگ `B-NEW-5`: حذف دسته‌ای محدود (`LIMIT 500`) ردیف‌های منقضی در `DatabaseReplayStore::cleanup()` به همراه پاک‌سازی گزینه‌های سهمیه روزانه تاریخ‌گذشته و حذف کامل هوک کران `music_wave_replay_cleanup` در `music-wave-core/uninstall.php`.

### ۲.۴. اصلاح `RemoteRedirectProvider` و مخزن کلید `RemoteSigningKeyring` (`music-wave-vip`)
- **`src/RemoteRedirectProvider.php` و `src/ProviderFactory.php`:**
  - تزریق `$storage->registry()` به `RemoteRedirectProvider` و پیاده‌سازی `resolve_relative_path()`:
    - واکشی رکورد `vip:<32hex>` از `ProtectedAssetRegistry::find()` و اعتبارسنجی `relative_path` با `PathValidator::is_safe_relative()`؛ بازگشت fail-closed (`''`) در صورت نبود رکورد.
    - حذف پیشوند `local:` در شناسه‌های میراثی و تضمین عدم نشت `vip%3A` یا `local%3A` در URL نهایی CDN.
    - مهاجرت ارسال تغییرمسیر به `RedirectResponse::send()` مشترک هسته.
- **`src/Security/RemoteSigningKeyring.php` (جدید):**
  - ذخیره کلیدهای امضای HMAC در گزینه مستقل `music_wave_vip_remote_keyring` با `autoload = false` و حذف مقدار متنی کلید از گزینه autoloaded `music_wave_vip_settings`.
  - ترتیب اولویت کلید فعال: ۱) ثابت `MUSIC_WAVE_VIP_REMOTE_SECRET` در `wp-config.php`، ۲) کلید `active` در `RemoteSigningKeyring`، ۳) کلید میراثی در تنظیمات قبلی تا پیش از اولین ذخیره.
  - پشتیبانی از چرخش کلید بدون قطعی (`active` + `retired` به همراه `kid`) و متد `verify_signature()` مبتنی بر `hash_equals()`.

### ۲.۵. تجربه کاربری پنل مدیریت، ویرایشگرها، عیب‌یابی و i18n
- **ویرایشگر بلوکی (`assets/editor.js`)، REST (`DownloadAssetRoutes.php`) و متاباکس کلاسیک (`ReleaseMetaBox.php`):** پشتیبانی کامل از حالت منبع دارایی («فایل محافظت‌شده VIP» در برابر «لینک مستقیم HTTPS») با اعتبارسنجی بلادرنگ سمت کلاینت و سرور.
- **صفحات تنظیمات Core و VIP، Site Health و عیب‌یابی:** نمایش بنر زنده «وضعیت مؤثر فعلی»، رفع قفل کنترل‌های عضویت VIP در حالت غیرفعال بودن سوئیچ اصلی، اصلاح لینک شکسته مدیریت دسترسی انبوه (`B-NEW-6`)، اصلاح نشان امنیتی پنل دانلود فرانت‌اند روی انتشارهای صرفاً `direct:`، و کش ۵ دقیقه‌ای `BulkAccessManager::mode_counts()` با کوئری تجمیعی `GROUP BY`.
- **خط لوله کامل ترجمه (i18n):** استخراج، ترجمه و کامپایل ۸۳ رشته جدید (۷۶ رشته در `music-wave-core` و ۷ رشته در `music-wave-vip`) در فایل‌های `.pot`، `.po`، `.mo` و ۶ کاتالوگ JSON اسکریپت‌ها (`3178` رشته منبع تاییدشده بدون هیچ کسری).

---

## ۳. ممیزی امنیتی (OWASP & WordPress Hardening Audit)

| بردار تهدید | کنترل دفاعی پیاده‌سازی‌شده | وضعیت تایید |
|---|---|---|
| Open Redirect / SSRF از طریق `direct:` | الزام `https://`، رد اطلاعات کاربری و فرگمنت، مسدودسازی کامل `localhost`، پسوندهای داخلی، رنج‌های خصوصی IPv4/IPv6، CGNAT، Multicast، `::ffff:`، NAT64 و IPهای عددی/هگز/اکتال + لیست سفید دامنه در زمان ذخیره و تحویل | تاییدشده (`T7`–`T11`) |
| ارتقای سطح دسترسی ویرایشگر عادی | الزام قابلیت `manage_mw_protected_assets` در `DirectLinkPermission` هنگام افزودن یا تغییر هر دارایی `direct:` | تاییدشده (`T12`) |
| افشای توکن در هدر `Referer` یا کش پروکسی | ارسال هدرهای `Referrer-Policy: no-referrer`، `Cache-Control: private, no-store, max-age=0`، `X-Content-Type-Options: nosniff` در `RedirectResponse` | تاییدشده (`T7`, `T15`) |
| نشت شناسه کدر `vip:<hash>` به CDN خارجی | نگاشت اجباری از طریق `ProtectedAssetRegistry` و fail-closed روی شناسه‌های نامعتبر/پیدانشده | تاییدشده (`T15`–`T17`) |
| افشای کلید امضا در حافظه Autoload وردپرس | ذخیره در `music_wave_vip_remote_keyring` با `autoload = false` + اولویت ثابت `MUSIC_WAVE_VIP_REMOTE_SECRET` در `wp-config.php` | تاییدشده (`T18`) |
| حملات زمان‌بندی (Timing Attacks) روی امضای HMAC | استفاده انحصاری از `hash_equals()` هم در `DownloadTokenService` و هم در `RemoteSigningKeyring::verify_signature()` | تاییدشده (`T18`) |
| دور زدن سهمیه روزانه با پاک شدن کش شیء یا `exit` | ذخیره سهمیه روزانه در گزینه‌های غیر-autoload دیتابیس (`mw_dl_quota_*`) + ثبت شمارنده در محافظ `register_shutdown_function` پیش از خروج پردازش | تاییدشده (`T14`) |
| سوزاندن بلیت یک‌بارمصرف روی ارائه‌دهنده خاموش | بررسی `DeliveryRouter::can_deliver_asset()` پیش از مصرف توکن در `DatabaseReplayStore` | تاییدشده (`T10`) |

---

## ۴. نتایج بنچمارک کارایی (PHP 8.3 Runtime)

| بخش / متد | تعداد تکرار | زمان کل | میانگین هر عملیات | وضعیت |
|---|---:|---:|---:|---|
| `DirectLinkUrlValidator::validate()` | ۱٬۰۰۰ | `95.19 ms` | `0.0952 ms/op` | عالی |
| `DeliveryRouter::can_deliver_asset()` | ۱٬۰۰۰ | `52.17 ms` | `0.0522 ms/op` | عالی |
| `AccessPolicyEngine::decide()` (با `MembershipAbsencePolicy`) | ۱٬۰۰۰ | `69.79 ms` | `0.0698 ms/op` | عالی |
| `RemoteSigningKeyring::verify_signature()` | ۱٬۰۰۰ | `62.57 ms` | `0.0626 ms/op` | عالی |
| `DatabaseReplayStore::cleanup()` (حذف دسته‌ای ۵۰۰ ردیف منقضی) | ۱ دسته | `32.33 ms` | `32.33 ms/batch` | عالی |
| `BulkAccessManager::mode_counts()` (کش سرد — `GROUP BY` روی ۵۰۰ انتشار) | ۱ بار | `5.68 ms` | `5.68 ms` (تک‌کوئری) | عالی |
| `BulkAccessManager::mode_counts()` (کش گرم Transient) | ۱٬۰۰۰ | `7.74 ms` | `0.0077 ms/op` | عالی |

---

## ۵. خلاصه ماتریس آزمون و گیت‌های کیفیت اجراشده

- **سوئیت تخصصی جدید `tests/vip-access-delivery-suite.php` (`T1` تا `T24`):** پوشش ۱۰۰٪ سناریوهای سیاست غیاب عضویت (`deny`، `allow`، `open_download`، بازگشت VIP)، حفظ `logged_in` در اسکیما، تحویل و استریم `direct:`، رد کامل بردارهای SSRF/XSS/Header Injection، لیست سفید دامنه در ذخیره و تحویل، کنترل دسترسی `DirectLinkPermission`، هم‌زیستی دارایی‌های `vip:` و `direct:`، سقف TTL تا ۷۲۰۰ ثانیه، پایداری سهمیه روزانه در دیتابیس، نگاشت `vip:<32hex>` در `RemoteRedirectProvider`، چرخش کلید `RemoteSigningKeyring`، فرم تنظیمات VIP در حالت خاموش، کش `BulkAccessManager`، پاک‌سازی Uninstall و بنچمارک بار.
- **گیت‌های استاتیک، واحد، یکپارچگی و بسته‌بندی:**
  - `tools/check-syntax.php` روی PHP `7.4`، `8.3` و `8.4`: **PASS**
  - `tools/check-strict-types.php`، `check-abspath.php`، `check-nul-bytes.php`، `check-js.php`، `check-templates.php`، `check-site-editor.php`: **PASS**
  - `php vendor/bin/phpcs` (استاندارد کامل WPCS در سراسر مخزن): **0 خطا**
  - `phpstan analyse` (سطح ۵ روی `music-wave-core` و `music-wave-vip`): **0 خطا**
  - `npm run lint:js` و `npm run test:unit`: **PASS**
  - `tools/check-translation-coverage.php` (`3178` رشته) و `tools/check-script-translations.php` (`6` کاتالوگ، `2798` پیام): **PASS**
  - `tests/run.php`، `tests/download-e2e.php`، `tests/template-integrity.php`، `tests/wordpress-runtime-audit.php`، `tests/staging-smoke.php`: **PASS**
  - بازتولید `MANIFEST.sha256` هر سه پکیج و اجرای `tools/package-release.php`: **PASS**
