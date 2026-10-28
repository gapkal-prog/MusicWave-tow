# سند مرجع جامع معماری، امنیت، کارایی و پیاده‌سازی ارتقای VIP، سیاست دسترسی و سیستم تحویل فایل در MusicWave

> **نسخه سند:** `2.0.0` (بازبینی‌شده، ممیزی‌شده با کد واقعی مخزن در کامیت پایه `e0882d9`، و ارتقایافته در سطح معماری، امنیت سرور و مهندسی وردپرس)
> **تاریخ به‌روزرسانی:** `2026-10-28`
> **پکیج‌های هدف:** `music-wave-core` (`0.15.0` ← `0.16.0`، نسخه اسکیمای دیتابیس ثابت روی `0.15.0`) و `music-wave-vip` (`0.5.1` ← `0.5.2` با `MIN_CORE_VERSION = '0.16.0'`)، سازگار با قالب `musicwave` (`0.9.2`)
> **کف پشتیبانی اجرایی (طبق ADR-0003):** `PHP >= 7.4` (آزمون‌شده روی `7.4`، `8.0`، `8.3`، `8.4`؛ بدون سینتکس اختصاصی PHP 8)، `WordPress >= 6.6` (تا `6.8`)، `WooCommerce >= 9.0` (اختیاری)

---

## بخش ۰ — خلاصه مدیریتی، ممیزی کدبیس واقعی و قوانین قطعی اجرا

### ۰.۱. قوانین قطعی اجرا (الزامی و غیرقابل تخطی)

1. این سند پس از ممیزی خط‌به‌خط کدبیس واقعی پروژه `MusicWave-tow` بازبینی و نهایی شده است؛ تمامی فرضیه‌های اولیه (`H1` تا `H16`) با فایل و خط دقیق کد تطبیق داده شده‌اند و یافته‌های امنیتی/معماری جدید کدبیس نیز به بک‌لاگ رسمی افزوده شده‌اند.
2. معماری فعلی MusicWave بر تفکیک سخت‌گیرانه سه لایه استوار است (ADR-0001 و ADR-0002):
   - **`music-wave-core`**: مالک مدل داده کاتالوگ (`mw_release`)، موتور سیاست دسترسی (`AccessPolicyEngine`)، صدور و مصرف بلیت/توکن یک‌بارمصرف (`OpaqueTicketStore` + `DownloadTokenService` + `DatabaseReplayStore`)، محدودیت نرخ و سهمیه روزانه، و مسیریابی تحویل فایل.
   - **`music-wave-vip`**: افزونه الحاقی اختیاری برای ارائه‌دهنده عضویت (`ConfigurableMembershipProvider` + `VipPlans`) و تحویل فایل حفاظت‌شده (`ProtectedFileProvider` محلی و `RemoteRedirectProvider` با امضای HMAC).
   - **`musicwave`**: قالب بلوکی نمایشی بدون منطق تصمیم‌گیری امنیتی یا دسترسی.
   هیچ تغییری نباید باعث نشت منطق VIP به قالب یا وابسته شدن اجباری هسته به VIP یا WooCommerce شود.
3. کف پشتیبانی `PHP >= 7.4` در سراسر کد جدید و اصلاح‌شده الزامی است: استفاده از `match`، `readonly`، `enum`، آرگومان‌های نام‌گذاری‌شده، `str_starts_with`/`str_contains` بدون پلی‌فیل، union types و constructor property promotion ممنوع است و تمامی فایل‌های PHP باید دارای `declare(strict_types=1);` و محافظ `defined( 'ABSPATH' ) || exit;` باشند.
4. زبان منبع رشته‌های ترجمه در این مخزن **فارسی** است (`msgid` فارسی در `.pot` و ترجمه انگلیسی کامل در `*-en_US.po`، کامپایل‌شده به `.mo` و کاتالوگ‌های اسکریپتی JED `.json`). هر رشته کاربری جدید در PHP یا JS باید دارای ترجمه دقیق `en_US` باشد تا گیت `tools/check-translation-coverage.php` و `tools/check-script-translations.php` بدون خطا عبور کنند.
5. ارتقا باید **بدون مهاجرت اسکیمای پایگاه‌داده** (حفظ `MigrationRunner::LATEST_VERSION = '0.15.0'`) و با حفظ سازگاری کامل رو به عقب برای سایت‌های فعلی انجام شود.

---

### ۰.۲. خلاصه مدیریتی مسئله و راه‌حل مهندسی

در معماری پیش از ارتقا (`core 0.15.0` / `vip 0.5.1`)، پنج گلوگاه کلیدی عملیاتی، امنیتی و تجربه کاربری وجود داشت:

1. **عدم تقارن در رفتار غیاب افزونه VIP (`AccessPolicyEngine.php:100-154`):**
   - وقتی افزونه `music-wave-vip` فعال باشد ولی کلید اصلی آن (`module_enabled`) خاموش شود، فیلتر `music_wave_access_decision` در `music-wave-vip.php:68-89` هر دو حالت `membership_required` و `purchase_or_membership_required` را با دلیل `vip_module_disabled` باز می‌کند.
   - اما وقتی خود افزونه `music-wave-vip` از وردپرس **غیرفعال** شود (`NullMembershipProvider`)، تنظیم `membership_absent_behavior` (با مقادیر قبلی `allow` | `deny` و پیش‌فرض `allow` در `Settings.php:35`) فقط حالت `membership` و حالت ترکیبی فاقد محصول را باز می‌کرد؛ در حالی که انتشارهای `purchase_or_membership` متصل به محصول WooCommerce در `hybrid_access()` (`AccessPolicyEngine.php:142-147`) پیش از بررسی غیاب VIP روی `purchase_required` قفل می‌شدند و مدیر سایت راهی برای باز کردن یکپارچه دانلود اشتراکی بدون دست‌کاری تک‌تک انتشارها نداشت.
2. **نبود مسیر تحویل استاندارد بدون VIP برای لینک مستقیم HTTPS کنترل‌شده:**
   - هسته به‌درستی URLهای خام `http://` و `https://` را در `MetaDefinition.php:290-339`، `DownloadAssetRoutes.php:147-188`، `ReleaseMetaBox.php:374-403` و `assets/editor.js:763-780` رد می‌کند تا لینک خام فایل لو نرود.
   - اما اگر ادمین بخواهد بدون نصب VIP (یا در کنار VIP برای برخی فایل‌ها مثل نسخه‌های رایگان/آرشیوی روی CDN عمومی) از لینک مستقیم HTTPS پشت دروازه احراز دسترسی، بلیت یک‌بارمصرف، محدودیت نرخ و سهمیه روزانه هسته استفاده کند، هیچ طرح‌واره (`scheme`) رسمی و ایمنی وجود نداشت و `NullDownloadProvider` تحویل را مسدود می‌کرد.
3. **باگ بحرانی نگاشت `vip:<32hex>` و `local:` در `RemoteRedirectProvider` (`RemoteRedirectProvider.php:92-137`):**
   - فایل‌های آپلودشده از طریق رسانه خصوصی VIP با شناسه کدر `vip:<32hex>` در جدول `{prefix}mw_vip_assets` ثبت می‌شوند، اما `RemoteRedirectProvider::signed_url()` شناسه را از `ProtectedAssetRegistry` استخراج نمی‌کرد و رشته خام `vip%3A...` یا `local%3A...` را وارد مسیر URL امضاشده می‌کرد که منجر به خطای `404` در CDN/سرور مقصد و نشت ساختار شناسه داخلی می‌شد.
4. **مدیریت تک‌کلیدی راز امضای راه‌دور در گزینه Autoloaded (`VipSettings.php:29,213`):**
   - `remote_signing_secret` مستقیماً درون آرایه `music_wave_vip_settings` (که در وردپرس به‌طور پیش‌فرض autoload می‌شود) نگهداری می‌شد، از ثابت امنیتی `MUSIC_WAVE_VIP_REMOTE_SECRET` در `wp-config.php` پشتیبانی نمی‌کرد و هنگام چرخش کلید (`key rotation`) امکان حفظ موقت کلید قبلی در وضعیت `retired` برای لینک‌های امضاشده در حال انقضا وجود نداشت.
5. **ابهام وضعیت مؤثر در پنل تنظیمات، قفل بودن کنترل‌های VIP در حالت خاموش، و هزینه سنگین کوئری در صفحه تنظیمات هسته:**
   - در `BulkAccessManager::mode_counts()` (`BulkAccessManager.php:118-146`) که در رندر صفحه تنظیمات فراخوانی می‌شود، تا ۵۰ صفحه کوئری `WP_Query` (۵۰۰۰ پست) و هزاران فراخوانی متا بدون کش اجرا می‌شد.
   - در تنظیمات VIP (`music-wave-vip/src/SettingsPage.php:251-253,519-541`) وقتی `module_enabled === 'disabled'` بود، فیلدهای منابع عضویت در سمت سرور `disabled` رندر می‌شدند و ادمین پیش از روشن کردن کلید اصلی قادر به پیش‌پیکربندی منابع نبود؛ همچنین لینک مدیریت دسترسی انبوه در خط `834` به صفحه ناموجود `page=music-wave-bulk-access` اشاره می‌کرد.

---

## بخش ۱ — نتایج ممیزی کدبیس واقعی: تعیین تکلیف H1 تا H16 و باگ‌های جدید کشف‌شده

### ۱.۱. جدول تطبیق فرضیه‌های H1 تا H16 با کد واقعی مخزن

| کد | موضوع فرضیه | وضعیت پس از ممیزی کد | مرجع دقیق در کدبیس و تصمیم نهایی مهندسی |
|---|---|---|---|
| **H1** | وجود تنظیم رفتار غیاب عضویت (`allow` / `deny`) در هسته | **تأیید شد (با کشف نکته کلیدی)** | `music-wave-core/src/Support/Settings.php:35,113` و `AccessPolicyEngine.php:108,148,162`. نکته مهم: مقدار پیش‌فرض فعلی در کد `'allow'` است (نه `'deny'`) و تست `tests/run.php:4228` صراحتاً به آن وابسته است؛ در حالی که تست `tests/vip-access-delivery-suite.php:91-94` تضمین می‌کند در همان حالت پیش‌فرض `'allow'`، انتشار پولی `purchase_or_membership` نشت نکند. بنابراین پیش‌فرض برای حفظ ۱۰۰٪ سازگاری `'allow'` باقی می‌ماند، `'deny'` حالت fail-closed کامل است و `'open_download'` حالت صریح جدید برای باز کردن هر دو حالت اشتراکی و ترکیبی خواهد بود. |
| **H2** | تفاوت رفتار `purchase_or_membership` هنگام غیرفعال بودن VIP | **تأیید شد** | در `AccessPolicyEngine::hybrid_access()` (`خطوط 129-154`)، شرط `$has_products` در خطوط `142-147` پیش از بررسی غیاب ارائه‌دهنده عضویت اجرا می‌شود و بلافاصله `deny('purchase_required')` برمی‌گرداند. کلاس جدید `MembershipAbsencePolicy` این منطق را شفاف، یکپارچه و بدون ابهام تفکیک می‌کند. |
| **H3** | وابستگی دانلود پس از مجاز شدن دسترسی به ارائه‌دهنده تحویل | **تأیید شد** | در `DownloadResolver.php:98-102`، وقتی ارائه‌دهنده `NullDownloadProvider` باشد، فایل‌های `vip:` و `local:` با خطای `provider_unavailable` (کد HTTP `503`) رد می‌شوند. با معرفی `DeliveryRouter` و `DirectLinkProvider`، فایل‌های `direct:` بدون نیاز به VIP تحویل می‌شوند و فایل‌های `vip:`/`local:` همچنان در غیاب VIP با پیام دقیق `503` مسدود می‌مانند. |
| **H4** | ثبت فیلتر بازکننده توسط VIP هنگام خاموش بودن `module_enabled` | **تأیید شد** | `music-wave-vip/music-wave-vip.php:48-90` همیشه `ConfigurableMembershipProvider` را ثبت می‌کند و هنگام خاموش بودن `module_enabled`، در فیلتر `music_wave_access_decision` (اولویت ۲۰) دلایل `membership_required` و `purchase_or_membership_required` را به `allow('vip_module_disabled')` تبدیل می‌کند. |
| **H5** | رد شدن URL مستقیم در متای فایل دانلود در ویرایشگر و بک‌اند | **تأیید شد (همراه با کشف حفره بالقوه)** | رد URLهای `http://` و `https://` در سه لایه `MetaDefinition.php:290-339`، `DownloadAssetRoutes.php:147-188,226-275`، `ReleaseMetaBox.php:374-403` و `assets/editor.js:763-780` پیاده شده است. نکته امنیتی کشف‌شده: در PHP، خروجی `wp_parse_url('direct:http://127.0.0.1/a.mp3', PHP_URL_SCHEME)` برابر `'direct'` است و بدون اعتبارسنج اختصاصی `DirectLinkUrlValidator` در `MetaDefinition`، طرح‌واره `direct:` می‌توانست اعتبارسنج قدیمی `http/https` را دور بزند! |
| **H6** | مصرف توکن یک‌بارمصرف در مسیر دانلود هسته پیش از تحویل | **تأیید شد** | `DownloadResolver::deliver()` (`خطوط 133-170`) ابتدا توکن، تطابق کاربر/انتشار، وضعیت دسترسی زنده و سهمیه روزانه را بررسی می‌کند، سپس در خط `157` توکن را در `ReplayStore::consume()` مصرف کرده و در خط `162` به `provider->deliver()` می‌سپارد. |
| **H7** | عدم نیاز مسیر استریم امن به مصرف تک‌بار توکن به‌دلیل Range Request | **تأیید شد** | `DownloadResolver::stream()` (`خطوط 198-227`) توکن با `purpose === 'stream'` را بدون فراخوانی `replays->consume()` راستی‌آزمایی می‌کند تا درخواست‌های چندگانه `Range` مرورگر در طول پخش قطع نشوند. |
| **H8** | تشخیص وضعیت تحویل امن با `has_filter('music_wave_download_provider')` | **تأیید شد (۴ نقطه دقیق)** | دقیقاً در ۴ فایل هسته یافت شد: ۱) `Admin/Concerns/DiagnosticsChecks.php:35`، ۲) `Admin/SettingsPage.php:231`، ۳) `Blocks/Release/CollectionAndDownloadRenderer.php:540`، ۴) `Support/SiteHealth.php:133` (به‌علاوه نقطه تزریق در `Plugin.php:156`). تمامی این نقاط به سرویس واحد `DeliveryCapabilities` متصل می‌شوند. |
| **H9** | استفاده از شناسه خام در `RemoteRedirectProvider` بدون رجوع به رجیستری | **تأیید شد (باگ قطعی)** | در `RemoteRedirectProvider.php:92-137` هیچ تزریق یا فراخوانی از `ProtectedAssetRegistry::find()` وجود ندارد و حتی پیشوند `local:` نیز حذف نمی‌شود (`vip%3A...` و `local%3A...` در URL تولید می‌شدند). |
| **H10** | جدول رجیستری فایل‌های حفاظت‌شده حاوی نگاشت شناسه کدر به مسیر نسبی | **تأیید شد** | `ProtectedAssetRegistry.php` جدول `{prefix}mw_vip_assets` را مدیریت می‌کند و متد `find( 'vip:<32hex>' )` آرایه شامل `relative_path`، `file_size`، `mime_type` و `checksum_sha256` برمی‌گرداند. همچنین `ProviderFactory::download_provider( ProtectedAssetStorage $storage )` از قبل شیء `$storage` (و `$storage->registry()`) را در اختیار دارد. |
| **H11** | ذخیره کلید امضای ریموت در `wp_options` به‌صورت تک‌کلیدی | **تأیید شد** | `VipSettings::OPTION` (`music_wave_vip_settings`) کلید `remote_signing_secret` را به‌صورت تک‌رشته‌ای درون آرایه اصلی ذخیره می‌کند که در وردپرس autoload می‌شود. |
| **H12** | وجود فیلتر سفارشی‌سازی URL ریموت برای یکپارچه‌سازی S3/CDN | **تأیید شد** | فیلتر `music_wave_vip_remote_download_url` در `RemoteRedirectProvider.php:88` وجود دارد و خروجی آن نیز از صافی امنیتی `allowed_url()` (الزام HTTPS + تطابق دامنه مجاز) عبور می‌کند. |
| **H13** | تشخیص قابلیت استریم بر اساس پسوند فایل/فرمت | **تأیید شد** | در `ReleaseDownloadCatalog::is_asset_streamable()` (`خطوط 38-55`)، ابتدا فیلد `format`، سپس پسوند `file_name` و در نهایت `pathinfo($asset['asset_id'], PATHINFO_EXTENSION)` بررسی می‌شود که روی `direct:https://cdn.example.com/a.mp3?x=1` بدون استخراج `PHP_URL_PATH` خراب می‌شود. |
| **H14** | محاسبه پرهزینه شمارش حالت‌های دسترسی در صفحه تنظیمات | **تأیید شد** | `SettingsPage::render_bulk_form()` (`خط 466`) در هر بار رندر تب دسترسی، `BulkAccessManager::mode_counts()` (`خطوط 118-146`) را بدون کش صدا می‌زند (تا ۵۰ کوئری صفحه‌بندی‌شده + هزاران `get_post_meta`). |
| **H15** | غیرفعال شدن فیلدهای وابسته VIP در DOM هنگام خاموش بودن ماژول | **تأیید شد + کشف مسیر دقیق فایل JS** | در `music-wave-vip/src/SettingsPage.php:251-253,519-541,683-686` صفت `disabled` در PHP چاپ می‌شود و فایل واقعی جاوااسکریپت `music-wave-vip/assets/admin-vip-settings.js` (خطوط `567-588`؛ نه `vip-admin.js`) آن را مدیریت می‌کند. همچنین مکانیزم `mwvip_controls_unlocked` از قبل در `sanitize_settings` پیش‌بینی شده و در `tests/vip-access-delivery-suite.php:180-194` تست دارد. |
| **H16** | مستندات هم‌راستا اما نیازمند به‌روزرسانی برای قابلیت‌های جدید | **تأیید شد** | فایل‌های `docs/access-policy.md`، `docs/secure-downloads.md`، `docs/vip-integration.md`، `docs/delivery-threat-model.md`، `docs/extension-contracts.md` و ADRهای `0001` تا `0004` موجودند؛ ADR جدید باید با شماره `docs/adr/0005-membership-absence-policy-and-direct-delivery.md` ایجاد شود. |

---

### ۱.۲. باگ‌ها و ریسک‌های امنیتی/عملیاتی جدید کشف‌شده در ممیزی عمیق کدبیس (افزوده‌شده به اسکوپ اصلاح)

علاوه بر موارد اولیه سند، ممیزی خط‌به‌خط کدبیس **۷ نقص واقعی دیگر** را آشکار کرد که مستقیماً بر امنیت، سهمیه، استریم و صحت تنظیمات اثر دارند و در همین ارتقا برطرف می‌شوند:

- **B-NEW-1 (سطح P1 — یکپارچگی داده دسترسی در REST/Schema):** در `music-wave-core/src/Schema/MetaDefinition.php:139-144`، لیست سفید حالت‌های `mw_access_mode` شامل `'logged_in'` نیست (`array('public', 'purchase', 'membership', 'purchase_or_membership', 'restricted')`)؛ در نتیجه ذخیره انتشاری با حالت «فقط کاربران واردشده» از طریق REST/ویرایشگر بلوکی، بی‌صدا آن را به `'restricted'` بازنویسی می‌کرد! باید به `AccessModePolicy::sanitize_mode()` متصل شود.
- **B-NEW-2 (سطح P1 — دور خوردن سهمیه روزانه و رویداد ممیزی در تحویل واقعی HTTP):** در `DownloadResolver::deliver()` (`خطوط 162-168`)، فراخوانی `count_delivery()` و `audit('download_delivered')` **بعد از** بازگشت `$this->provider->deliver()` قرار گرفته بود؛ اما هم `ProtectedFileProvider` (`خطوط 77, 110`) و هم `RemoteRedirectProvider` (`خط 48`) در درخواست‌های واقعی وردپرس پس از ارسال فایل/هدر `302` دستور `exit;` اجرا می‌کنند! در نتیجه در محیط پروداکشن واقعی، سهمیه روزانه دانلود (`download_daily_quota`) و هوک ممیزی `download_delivered` هرگز ثبت نمی‌شدند. با ثبت محافظ نهایی‌سازی پیش از خروج (`shutdown/termination finalizer` ایمن در برابر خطای مرگبار PHP)، ثبت سهمیه و ممیزی در ۱۰۰٪ تحویل‌های موفق تضمین می‌شود.
- **B-NEW-3 (سطح P1 — ناپایداری سهمیه روزانه در برابر پاک‌سازی Object Cache):** در `DownloadResolver.php:176-191`، شمارنده سهمیه روزانه (`mw_dl_quota_*`) در Transient ذخیره می‌شد که در حضور Redis/Memcached با یک `wp_cache_flush()` صفر می‌شد (برخلاف الزام P4 و آزمون شماره ۲۲). ذخیره‌سازی سهمیه روزانه به گزینه غیر-autoload در پایگاه‌داده (`wp_options` با `autoload = false` و انقضای روزانه + پاک‌سازی دسته‌ای در کران و `uninstall.php`) منتقل می‌شود.
- **B-NEW-4 (سطح P1 — برش پنهان TTL توکن استریم و دانلود روی ۹۰۰ ثانیه):** تنظیمات هسته (`Settings.php` و `DownloadResolver::ttl_for()`) اجازه تنظیم `download_token_ttl` تا `3600` ثانیه و `stream_token_ttl` تا `7200` ثانیه را می‌دهند، اما `DownloadTokenService::issue()` (`خط 22`) و `OpaqueTicketStore::wrap()` (`خط 30`) به‌طور پنهانی مقدار TTL را روی `min($ttl, 900)` سقف می‌زدند که باعث قطع پخش فایل‌های بلند (پادکست/میکس بالای ۱۵ دقیقه) در درخواست‌های Range بعدی می‌شد! سقف سرویس به `7200` ثانیه هم‌تراز می‌شود.
- **B-NEW-5 (سطح P2 — کوئری DELETE بدون LIMIT در کران پاک‌سازی و کسری هوک در Uninstall):** در `DatabaseReplayStore::cleanup()` (`خطوط 79-82`) حذف ردیف‌های منقضی بدون `LIMIT` انجام می‌شد که روی کاتالوگ‌های پرترافیک می‌تواند قفل جدول ایجاد کند؛ همچنین هوک کران `music_wave_replay_cleanup` در `music-wave-core/uninstall.php` پاک نمی‌شد. هر دو مورد اصلاح می‌شوند (حذف دسته‌ای با `LIMIT` ایمن و پاک‌سازی کامل هوک در `uninstall.php`).
- **B-NEW-6 (سطح P2 — لینک شکسته مدیریت دسترسی انبوه در تنظیمات VIP):** در `music-wave-vip/src/SettingsPage.php:834` پیوند به `page=music-wave-bulk-access` (صفحه ناموجود) اشاره می‌کرد؛ به مسیر صحیح `edit.php?post_type=mw_release&page=music-wave-settings&tab=access` اصلاح می‌شود.
- **B-NEW-7 (سطح P2 — عدم تطابق `schema_version` در `release-manifest.json`):** فایل `release-manifest.json` مقدار `"schema_version": "0.14.0"` را نگه داشته بود در حالی که `MigrationRunner::LATEST_VERSION` برابر `'0.15.0'` است و خود مانیفست در بخش `notes` تطابق این دو را الزامی اعلام کرده است. هم‌زمان با ارتقای نسخه پکیج‌ها به `0.16.0` و `0.5.2`، این مقدار روی `"0.15.0"` همگام می‌شود.
- **B-NEW-8 (سطح P1 — خواندن مستقیم تنظیم سیاست غیاب عضویت و پشتیبانی از `open_download` در بوت هسته):** در کد قبلی `AccessPolicyEngine` مقدار `membership_absent_behavior` را مستقیماً از `Settings::get()` نمی‌خواند و تنها زمانی عمل می‌کرد که `Plugin::boot()` فیلتر `music_wave_membership_absent_behavior` را ثبت کرده باشد؛ علاوه بر آن، همان فیلتر در `Plugin.php` فقط دو رشته `'allow'` و `'deny'` را مجاز می‌شمرد و `'open_download'` را رد می‌کرد. با انتقال منطق به `MembershipAbsencePolicy::configured_mode()` (که ابتدا `Settings::get('membership_absent_behavior', 'allow')` را خوانده و از فیلتر عبور می‌دهد) و همگام‌سازی `Plugin.php` با `MembershipAbsencePolicy::allowed_modes()`، این گسست در تمامی مسیرهای اجرایی (از جمله فراخوانی مستقیم موتور در تست‌ها و CLI) برطرف شد.

---

## بخش ۲ — اهداف، معیارهای موفقیت و غیرهدف‌ها

### ۲.۱. معیارهای موفقیت قابل آزمون

1. **سیاست غیاب عضویت (`MembershipAbsencePolicy`):**
   - پشتیبانی از سه حالت صریح در تنظیم `membership_absent_behavior`:
     - `deny`: مسدود کردن حالت `membership` (`membership_provider_absent`) و حفظ الزام خرید در `purchase_or_membership` دارای محصول (`purchase_required`) یا مسدودسازی در صورت عدم پیکربندی (`purchase_or_membership_unconfigured`).
     - `allow` (پیش‌فرض سازگار با نسخه‌های قبل): باز کردن حالت صرفاً `membership` (`membership_provider_absent`) و حفظ الزام خرید روی انتشارهای پولی `purchase_or_membership` متصل به محصول WooCommerce تا هیچ محتوای پولی به‌طور ناخواسته رایگان نشود.
     - `open_download` (حالت صریح جدید): باز کردن دانلود و استریم برای **هر دو** حالت `membership` و `purchase_or_membership` (حتی اگر محصول WooCommerce متصل باشد و کاربر نخریده باشد) با کد دلیل متمایز `membership_absent_open_download`، بدون دست زدن به متای هیچ انتشاری.
   - حالت‌های `purchase` (فقط خرید تکی)، `restricted` (کاملاً محدود) و `logged_in` (نیازمند ورود) در هیچ‌یک از سه حالت بالا به‌طور خودکار برای مهمان باز نمی‌شوند.
   - هنگام بازگشت و فعال‌سازی مجدد `music-wave-vip`، قواعد اشتراک و ترکیبی بلافاصله بر اساس متای دست‌نخورده انتشارها از سر گرفته می‌شوند.
2. **تحویل مبتنی بر طرح‌واره (`Scheme-Based Delivery Routing`) و لینک مستقیم HTTPS:**
   - پشتیبانی بومی در `music-wave-core` از شناسه‌های `direct:<https-url>` از طریق `DirectLinkAsset`، `DirectLinkUrlValidator`، `DirectLinkPermission`، `DirectLinkProvider`، `RedirectResponse` و `DeliveryRouter`.
   - عبور ۱۰۰٪ دانلود و استریم فایل‌های `direct:` از مسیر استاندارد هسته: بررسی `AccessPolicyEngine`، بررسی مجوز مهمان، محدودیت نرخ (`DownloadRateLimiter`)، صدور بلیت کدر `mwt_...`، مصرف یک‌بارمصرف در `DatabaseReplayStore` (برای دانلود)، ثبت سهمیه روزانه پایدار در دیتابیس، و ارسال پاسخ `302` با هدرهای `Referrer-Policy: no-referrer`، `Cache-Control: private, no-store, max-age=0` و `X-Content-Type-Options: nosniff`.
   - هم‌زیستی کامل در یک انتشار واحد: یک کیفیت می‌تواند `vip:<32hex>` یا `local:...` باشد و کیفیت دیگر `direct:https://...`؛ مسیریاب تحویل بر اساس پیشوند تصمیم می‌گیرد و هرگز بین ارائه‌دهنده‌ها fallback ناامن انجام نمی‌دهد.
3. **اصلاح کامل `RemoteRedirectProvider` و چرخش کلید چندکلیدی در `music-wave-vip`:**
   - حل کردن شناسه‌های `vip:<32hex>` از طریق `ProtectedAssetRegistry::find()` به `relative_path` نرمال‌شده، حذف پیشوند `local:` در شناسه‌های میراثی، و fail-closed کامل (`''`) در صورت نامعتبر بودن یا یافت نشدن رکورد (عدم تولید هرگونه `vip%3A` یا `local%3A` در URL نهایی).
   - پشتیبانی از ثابت `MUSIC_WAVE_VIP_REMOTE_SECRET` (و `MUSIC_WAVE_VIP_REMOTE_KEY_ID` اختیاری) در `wp-config.php` با بالاترین اولویت، به‌علاوه مخزن کلید چندکلیدی `RemoteSigningKeyring` در گزینه غیر-autoload (`music_wave_vip_remote_keyring` با `autoload = false`) با وضعیت‌های `active` و `retired` و مقایسه زمان‌ثابت `hash_equals`.
4. **شفافیت UX پنل‌های ادمین، عیب‌یابی هوشمند و بهبود کارایی:**
   - نمایش زنده «وضعیت مؤثر فعلی» در تنظیمات هسته و VIP، رفع قفل ظاهری کنترل‌های عضویت VIP در حالت خاموش، کش و بهینه‌سازی SQL در `BulkAccessManager::mode_counts()`، و جایگزینی چک‌های خام `has_filter('music_wave_download_provider')` با `DeliveryCapabilities`.

### ۲.۲. غیرهدف‌ها (خارج از دامنه این ارتقا)

- عدم ایجاد جدول جدید در پایگاه‌داده یا تغییر نسخه اسکیمای دیتابیس (`0.15.0`).
- عدم بازنویسی قالب `musicwave` یا تغییر استایل/چیدمان بلوک‌های فرانت‌اند (به‌جز اصلاح دقیق نشان و پیام وضعیت امنیتی پنل دانلود در `CollectionAndDownloadRenderer.php`).
- عدم پروکسی کردن ترافیک فایل‌های حجیم خارجی از داخل پردازش PHP هسته در حالت `direct:` (تحویل `direct:` منحصراً از طریق تغییرمسیر امن `302` پس از بررسی مجوز و مصرف توکن انجام می‌شود تا کارگرهای PHP اشغال نشوند و خطر SSRF مستقیم از سمت سرور حذف گردد).

---

## بخش ۳ — طراحی دقیق معماری هدف

### ۳.۱. لایه سیاست دسترسی در غیاب ماژول عضویت (`MembershipAbsencePolicy`)

کلاس جدید `ManaCore\MusicWave\Core\Access\MembershipAbsencePolicy` مسئولیت متمرکز تفسیر تنظیم `membership_absent_behavior` و فیلتر `music_wave_membership_absent_behavior` را بر عهده می‌گیرد:

| حالت انتشار (`mw_access_mode`) | وضعیت خرید محصول WooCommerce | خروجی با `deny` | خروجی با `allow` (پیش‌فرض سازگار) | خروجی با `open_download` (جدید) |
|---|---|---|---|---|
| `public` | — | `allow('public')` | `allow('public')` | `allow('public')` |
| `logged_in` | — | مهمان: `deny('login_required')` / کاربر: `allow('logged_in')` | مهمان: `deny('login_required')` / کاربر: `allow('logged_in')` | مهمان: `deny('login_required')` / کاربر: `allow('logged_in')` |
| `purchase` | خریده / نخریده / بدون محصول | `allow('purchase')` / `deny('purchase_required')` / `deny('purchase_unconfigured')` | بدون تغییر (همیشه نیازمند خرید) | **بدون تغییر (هرگز باز نمی‌شود)** |
| `restricted` | — | `deny('restricted')` | `deny('restricted')` | **بدون تغییر (هرگز باز نمی‌شود)** |
| `membership` | — | `deny('membership_provider_absent')` | `allow('membership_provider_absent')` | `allow('membership_absent_open_download')` |
| `purchase_or_membership` (کاربر محصول را خریده) | خریده (`true`) | `allow('purchase')` | `allow('purchase')` | `allow('purchase')` |
| `purchase_or_membership` (کاربر نخریده، محصول متصل است) | نخریده (`has_products = true`) | `deny('purchase_required')` | `deny('purchase_required')` | **`allow('membership_absent_open_download')`** |
| `purchase_or_membership` (بدون محصول متصل) | `has_products = false` | `deny('purchase_or_membership_unconfigured')` | `allow('membership_provider_absent')` | **`allow('membership_absent_open_download')`** |

> **هم‌آهنگی با کلید اصلی VIP هنگام نصب بودن افزونه VIP:** وقتی افزونه `music-wave-vip` فعال است، ارائه‌دهنده عضویت غایب نیست (`ConfigurableMembershipProvider` ثبت است)؛ بنابراین `MembershipAbsencePolicy` وارد عمل نمی‌شود و همان کلید اصلی `module_enabled` در VIP تعیین‌کننده است. این تفکیک صراحتاً در کارت وضعیت زنده هر دو پنل ادمین نمایش داده می‌شود.

---

### ۳.۲. لایه مسیریابی تحویل فایل مبتنی بر طرح‌واره (`DeliveryRouter` و کلاس‌های همکار)

در `music-wave-core/src/Downloads/` و `Support/` کلاس‌های زیر با مسئولیت تک‌منظوره (SRP) اضافه می‌شوند:

1. **`DirectLinkUrlValidator`**:
   - اعتبارسنجی سخت‌گیرانه نشانی HTTPS مقصد:
     - رد رشته‌های خالی، بلندتر از `2048` بایت، یا دارای بایت NUL و کاراکترهای کنترلی (`[\x00-\x1F\x7F]`).
     - الزام قطعی طرح‌واره `https` (رد `http`، `javascript`، `data`، `file`، `ftp`، `php`، `phar` و غیره).
     - رد کامل اطلاعات کاربری در URL (`user`/`pass` مانند `https://user:pass@host`) و رد `fragment` (`#...`).
     - رد میزبان‌های محلی و داخلی: `localhost`، `*.localhost`، `*.local`، `*.internal`، `metadata.google.internal`، آدرس‌های IP غیرعمومی IPv4 و IPv6 شامل `127.0.0.0/8`، `10.0.0.0/8`، `172.16.0.0/12`، `192.168.0.0/16`، `169.254.0.0/16` (متادیتای ابری)، `100.64.0.0/10` (CGNAT)، `0.0.0.0/8`، `::1`، `fe80::/10`، `fc00::/7`، آدرس‌های IPv4-mapped IPv6 (`::ffff:127.0.0.1`) و فرم‌های عددی/مبنای ۸ یا ۱۶ IPv4 (مانند `2130706433` یا `0x7f000001`).
     - بررسی لیست سفید دامنه‌های مجاز (`direct_link_allowed_hosts` در تنظیمات هسته + فیلتر `music_wave_direct_link_allowed_hosts`) در صورت پیکربندی توسط ادمین (با پشتیبانی از تطابق دقیق دامنه و زیرشاخه صریح `*.example.com`).
2. **`DirectLinkAsset`**:
   - مدیریت پیشوند استاندارد `direct:`، تشخیص حساس و غیرحساس به حروف بزرگ/کوچک (`has_direct_scheme` برای مسدودسازی `DIRECT:http://...`)، استخراج URL و تولید شناسه نرمال `direct:<normalized-https-url>`.
3. **`DirectLinkPermission`**:
   - بررسی مجوز انتساب لینک مستقیم جدید یا تغییریافته بر پایه قابلیت `manage_mw_protected_assets` (قابل سخت‌تر شدن توسط مدیر شبکه/سایت از طریق فیلتر `music_wave_direct_link_capability` به `manage_options` و فیلتر `music_wave_can_manage_direct_links`).
4. **`RedirectResponse`**:
   - ارسال‌کننده مشترک و ایمن تغییرمسیر HTTP `302` برای هر دو کلاس `DirectLinkProvider` (در هسته) و `RemoteRedirectProvider` (در VIP):
     - پاک‌سازی کاراکترهای تزریق هدر (`\r`، `\n`، `\0`) و راستی‌آزمایی مجدد طرح‌واره `https`.
     - ارسال هدرهای امنیتی استاندارد: `Cache-Control: private, no-store, max-age=0` (به‌همراه `nocache_headers()`)، `Referrer-Policy: no-referrer`، `X-Content-Type-Options: nosniff` و `X-Robots-Tag: noindex, nofollow`.
     - پشتیبانی از حالت تست بدون کشتن پردازش CLI (`MUSIC_WAVE_TESTING` یا فیلتر `music_wave_redirect_response_terminate`) با ثبت آخرین تغییرمسیر (`RedirectResponse::last_redirect()`) جهت تست‌های E2E دقیق هدرها و کد وضعیت.
5. **`DirectLinkProvider`** (پیاده‌ساز `DownloadProvider` و `StreamableDownloadProvider`):
   - اعتبارسنجی مجدد URL در لحظه تحویل/استریم (Defense-in-Depth: اگر ادمین بعد از ذخیره انتشار، لیست سفید دامنه را محدود کرده باشد، لینک‌های قدیمی خارج از لیست سفید بلافاصله در زمان تحویل مسدود می‌شوند!) و ارسال از طریق `RedirectResponse::send()`.
6. **`DeliveryRouter`** (پیاده‌ساز `DownloadProvider` و `StreamableDownloadProvider`):
   - پوشاننده ارائه‌دهنده خارجی در `Plugin.php`:
     - شناسه‌های دارای طرح‌واره `direct:` منحصراً به `DirectLinkProvider` هدایت می‌شوند (بدون fallback به ارائه‌دهنده دیگر).
     - شناسه‌های `vip:`، `local:` و شناسه‌های کدر سفارشی منحصراً به ارائه‌دهنده خارجی (`ProtectedFileProvider`، `RemoteRedirectProvider` یا `NullDownloadProvider`) هدایت می‌شوند.
7. **`DeliveryCapabilities`** (`music-wave-core/src/Support/DeliveryCapabilities.php`):
   - گزارش‌دهنده یکپارچه قابلیت‌های تحویل فعال سایت (`vip_local`، `vip_remote`، `external_provider`، `direct_only`) برای صفحه تنظیمات، صفحه راه‌اندازی و عیب‌یابی، Site Health وردپرس و پنل دانلود فرانت‌اند.

---

### ۳.۳. اصلاح معماری `RemoteRedirectProvider` و مخزن کلید `RemoteSigningKeyring` در `music-wave-vip`

1. **حل کامل شناسه‌های `vip:<32hex>` و `local:` در `RemoteRedirectProvider`:**
   - سازنده `RemoteRedirectProvider::__construct( array $config, ?ProtectedAssetRegistry $registry = null )` رجیستری را به‌صورت اختیاری می‌پذیرد (سازگاری کامل با فراخوانی‌های موجود در تست‌ها) و `ProviderFactory::download_provider()` نمونه `$storage->registry()` را به آن تزریق می‌کند.
   - متد `resolve_relative_path( string $asset_id )`:
     - برای `vip:<32hex>`: رکورد را از `ProtectedAssetRegistry::find()` می‌خواند؛ اگر رکورد وجود نداشت یا `relative_path` خالی/نامعتبر بود، **به‌صورت fail-closed رشته خالی `''` برمی‌گرداند** و هرگز `vip%3A...` را امضا نمی‌کند.
     - برای `local:<path>`: پیشوند `local:` را حذف کرده و مسیر نسبی را از `PathValidator::is_safe_relative()` عبور می‌دهد.
     - برای مسیر نسبی ساده (`album/track.flac`): مستقیماً با `PathValidator::is_safe_relative()` اعتبارسنجی و نرمال می‌کند.
     - برای `direct:...` یا هر رشته دارای `..` یا بایت کنترلی: بلافاصله `''` برمی‌گرداند.
2. **مخزن کلید امضای چندکلیدی غیر-Autoload (`RemoteSigningKeyring`):**
   - کلاس جدید `ManaCore\MusicWave\Vip\Security\RemoteSigningKeyring` کلیدهای امضا را در گزینه مستقل `music_wave_vip_remote_keyring` با `autoload = false` ذخیره می‌کند و کلید متنی قدیمی را هنگام ذخیره از گزینه autoloaded `music_wave_vip_settings` پاک‌سازی می‌کند.
   - ترتیب اولویت کلید فعال امضا:
     1. ثابت `MUSIC_WAVE_VIP_REMOTE_SECRET` در `wp-config.php` (در صورت تعریف و طول حداقل ۳۲ بایت؛ بالاترین اولویت و غیرقابل بازنویسی از دیتابیس/ادمین).
     2. کلید با وضعیت `active` در `music_wave_vip_remote_keyring`.
     3. مقدار میراثی `remote_signing_secret` در `music_wave_vip_settings` (برای سازگاری بدون مهاجرت با نصب‌های قبلی تا اولین ذخیره تنظیمات).
   - چرخش کلید بدون قطعی (Zero-Downtime Key Rotation):
     - هنگام ثبت کلید جدید با گزینه چرخش کلید، کلید فعال قبلی با شناسه `kid` خود به وضعیت `retired` (همراه با زمان بازنشستگی `retired_at`) منتقل می‌شود و کلید جدید با `kid` جدید وضعیت `active` می‌گیرد.
     - متد `RemoteSigningKeyring::verify_signature()` با استفاده از `hash_equals()` امکان راستی‌آزمایی امضا در برابر کلید `active` و کلیدهای `retired` معتبر را فراهم می‌کند و ادمین پس از پایان پنجره TTL می‌تواند با یک تیک کلیدهای `retired` را ابطال و پاک کند.

---

## بخش ۴ — استانداردهای سخت‌گیرانه امنیت (الزامات S1 تا S10)

- **S1 (جلوگیری از Open Redirect):** تغییرمسیر فقط به مقاصد ذخیره‌شده در متای انتشار معتبر پس از احراز دسترسی و مصرف توکن مجاز است؛ هیچ پارامتر ورودی کاربر در کوئری‌استرینگ/بدنه درخواست REST هرگز مستقیماً مقصد `Location` قرار نمی‌گیرد.
- **S2 (پیشگیری از SSRF و دسترسی به شبکه داخلی):** رد کامل طرح‌واره‌های غیرایمن، `localhost`، دامنه‌های داخلی و تمامی رنج‌های IP خصوصی/رزروشده/ابری (`127/8`، `10/8`، `172.16/12`، `192.168/16`، `169.254/16`، `100.64/10`، `0/8`، `::1`، `fc00::/7`، `fe80::/10` و IPv4-mapped IPv6) هم در زمان ذخیره و هم در زمان تحویل.
- **S3 (کنترل دسترسی و Nonce در تمام مرزهای نوشتن):** بررسی `current_user_can()`، قابلیت اختصاصی `DirectLinkPermission` (`manage_mw_protected_assets` + فیلتر ارتقا به `manage_options`) و بررسی کامل Nonce در متاباکس، اکشن‌های تنظیمات و مسیرهای REST.
- **S4 (هدرهای امنیتی پاسخ تغییرمسیر و تحویل):** ارسال اجباری `Referrer-Policy: no-referrer`، `Cache-Control: private, no-store, max-age=0`، `X-Content-Type-Options: nosniff` و `X-Robots-Tag: noindex, nofollow` در `RedirectResponse`.
- **S5 (حفاظت در برابر دستکاری مسیر — Path Traversal):** عبور تمامی مسیرهای نسبی در `MetaDefinition`، `ProtectedFileProvider` و `RemoteRedirectProvider` از نرمال‌ساز سخت‌گیرانه و رد قطعی `..`، بک‌اسلش غیرمجاز، بایت NUL و کاراکترهای کنترلی.
- **S6 (مقایسه زمان‌ثابت امضاها):** استفاده انحصاری از `hash_equals()` در `DownloadTokenService` و `RemoteSigningKeyring` و حداقل طول ۳۲ بایت برای کلید امضای HMAC.
- **S7 (عدم نشت کلید و جزئیات حساس در پاسخ خطا و لاگ):** پیام‌های خطای کاربر عمومی و امن هستند؛ مقادیر راز امضا هرگز در HTML صفحه تنظیمات، خروجی REST، عیب‌یابی یا لاگ چاپ نمی‌شوند.
- **S8 (پایداری ضدتکرار و سهمیه در برابر پاک‌سازی کش):** مصرف یک‌بارمصرف بلیت دانلود در جدول `{prefix}mw_download_replays` و شمارش سهمیه روزانه در گزینه غیر-autoload دیتابیس ذخیره می‌شوند تا پاک‌سازی کش شیء (`wp_cache_flush()`) هرگز موجب دور خوردن replay یا بازنشانی سهمیه روزانه نشود.
- **S9 (ثبت صحیح سهمیه و رویداد ممیزی حتی با `exit` ارائه‌دهنده):** تضمین اجرای شمارش سهمیه و هوک `music_wave_download_event` در تحویل‌های واقعی استریم/ریدایرکت.
- **S10 (حذف ایمن در `uninstall.php`):** پاک‌سازی کامل گزینه‌های جدید (`music_wave_vip_remote_keyring`، `mw_dl_quota_*`، کش شمارش حالت‌ها و کران `music_wave_replay_cleanup`) صرفاً در صورت فعال بودن صریح `delete_data_on_uninstall = enabled`، بدون دست زدن به پست‌های انتشار یا فایل‌های خصوصی روی دیسک.

---

## بخش ۵ — استانداردهای کارایی و مقیاس‌پذیری (الزامات P1 تا P7)

- **P1 (صفر کوئری و بار اضافی در فرانت‌اند عمومی):** منطق تنظیمات، شمارش حالت‌ها و کلیدهای امضای راه‌دور در درخواست‌های عادی صفحات عمومی سایت هیچ کوئری اضافه‌ای ایجاد نمی‌کنند.
- **P2 (عدم autoload برای داده‌های حساس و موقت):** گزینه `music_wave_vip_remote_keyring` و شمارنده‌های سهمیه روزانه `mw_dl_quota_*` همگی با `autoload = false` ذخیره می‌شوند.
- **P3 (تغییرمسیر سبک `302` بدون اشغال PHP Worker):** در هر دو حالت `direct:` و `remote_redirect`، سرور وردپرس فایل صوتی را دانلود یا پروکسی نمی‌کند و بلافاصله پس از احراز هویت/توکن پاسخ `302` برمی‌گرداند.
- **P4 (کش و تجمیع SQL در `BulkAccessManager::mode_counts()`):** تبدیل حلقه ۵۰ صفحه‌ای `WP_Query` + متا به یک کوئری تجمیعی `GROUP BY` همراه با کش Transient پنج‌دقیقه‌ای (`mw_bulk_access_mode_counts`) و ابطال خودکار هنگام ذخیره/حذف انتشار یا اجرای تغییر دسترسی انبوه، به‌علاوه اجرای تنبل فقط در تب `access`.
- **P5 (پاک‌سازی دسته‌ای محدود در کران):** محدود کردن کوئری‌های `DELETE` در `DatabaseReplayStore::cleanup()` با `LIMIT` ایمن (مثلاً دسته‌های ۵۰۰تایی) برای جلوگیری از قفل جدول در سایت‌های پرترافیک.
- **P6 (پشتیبانی کامل از استریم طولانی با Range Request):** هم‌ترازسازی سقف TTL توکن/بلیت تا `7200` ثانیه برای پخش بدون قطعی فایل‌های صوتی بلند.

---

## بخش ۶ — برنامه اجرایی فازبه‌فاز و فهرست دقیق فایل‌ها

### فاز ۱: سیاست دسترسی در غیاب ماژول عضویت و یکپارچگی اسکیمای دسترسی (`music-wave-core`)
- **ایجاد:** `music-wave-core/src/Access/MembershipAbsencePolicy.php`
- **اصلاح:**
  - `music-wave-core/src/Access/AccessPolicyEngine.php`
  - `music-wave-core/src/Access/AccessCtaResolver.php`
  - `music-wave-core/src/Support/Settings.php`
  - `music-wave-core/src/Schema/MetaDefinition.php` (رفع باگ `'logged_in'` + اتصال اعتبارسنجی `direct:`)

### فاز ۲: مسیریابی تحویل مبتنی بر طرح‌واره، لینک مستقیم HTTPS و پایداری سهمیه/TTL (`music-wave-core`)
- **ایجاد:**
  - `music-wave-core/src/Downloads/DirectLinkUrlValidator.php`
  - `music-wave-core/src/Downloads/DirectLinkAsset.php`
  - `music-wave-core/src/Downloads/DirectLinkPermission.php`
  - `music-wave-core/src/Downloads/RedirectResponse.php`
  - `music-wave-core/src/Downloads/DirectLinkProvider.php`
  - `music-wave-core/src/Downloads/DeliveryRouter.php`
  - `music-wave-core/src/Support/DeliveryCapabilities.php`
- **اصلاح:**
  - `music-wave-core/src/Plugin.php`
  - `music-wave-core/src/Downloads/DownloadResolver.php` (مسیریابی، پایداری سهمیه در دیتابیس، محافظ شمارش قبل از `exit`)
  - `music-wave-core/src/Downloads/DownloadRoutes.php` (پیام‌های خطای دقیق برای `provider_unavailable` و `invalid_direct_url`)
  - `music-wave-core/src/Downloads/DownloadTokenService.php` و `OpaqueTicketStore.php` (رفع سقف ۹۰۰ ثانیه به ۷۲۰۰ ثانیه)
  - `music-wave-core/src/Downloads/ReleaseDownloadCatalog.php` (تشخیص استریم‌پذیری روی مسیر URL در `direct:`)
  - `music-wave-core/src/Downloads/DatabaseReplayStore.php` و `music-wave-core/uninstall.php` (پاک‌سازی دسته‌ای محدود + هوک کران و سهمیه)

### فاز ۳: تجربه نویسندگی و اعتبارسنجی سه‌لایه در متاباکس و ویرایشگر بلوکی (`music-wave-core`)
- **اصلاح:**
  - `music-wave-core/src/Downloads/DownloadAssetRoutes.php`
  - `music-wave-core/src/Admin/ReleaseMetaBox.php` و `music-wave-core/assets/admin-release-meta.js`
  - `music-wave-core/src/Admin/EditorAssets.php` و `music-wave-core/assets/editor.js`

### فاز ۴: اصلاح `RemoteRedirectProvider` (`vip:<hash>`)، چرخش کلید و ثابت `wp-config.php` (`music-wave-vip`)
- **ایجاد:** `music-wave-vip/src/Security/RemoteSigningKeyring.php`
- **اصلاح:**
  - `music-wave-vip/src/ProviderFactory.php`
  - `music-wave-vip/src/RemoteRedirectProvider.php`
  - `music-wave-vip/src/VipSettings.php`
  - `music-wave-vip/src/Delivery/ProtectedDeliveryDiagnostics.php`
  - `music-wave-vip/uninstall.php`

### فاز ۵: هوشمندسازی پنل‌های تنظیمات، عیب‌یابی، نشان فرانت‌اند و بهینه‌سازی `mode_counts`
- **اصلاح:**
  - `music-wave-core/src/Admin/SettingsPage.php`
  - `music-wave-core/src/Admin/BulkAccessManager.php`
  - `music-wave-core/src/Admin/Concerns/DiagnosticsChecks.php`
  - `music-wave-core/src/Support/SiteHealth.php`
  - `music-wave-core/src/Blocks/Release/CollectionAndDownloadRenderer.php`
  - `music-wave-vip/src/SettingsPage.php` (رفع قفل کنترل‌ها در حالت خاموش، کارت وضعیت زنده، رابط چرخش کلید، اصلاح لینک شکسته خط `834`)
  - `music-wave-vip/assets/admin-vip-settings.js`

### فاز ۶ تا ۸: آزمون‌های جامع (واحد، یکپارچه‌سازی، امنیتی، کش و کارایی)، ترجمه‌ها، مستندات، نسخه‌گذاری و گزارش نهایی
- **ایجاد/توسعه آزمون‌ها:**
  - `tests/vip-access-delivery-suite.php` (پوشش کامل ۲۲+ سناریوی ماتریس دسترسی، لینک مستقیم، بردارهای حمله امنیتی، چرخش کلید، کش شیء و کارایی)
  - حفظ سبز بودن ۱۰۰٪ تمامی تست‌های موجود (`tests/run.php`، `tests/download-e2e.php`، `tests/protected-asset-storage.php`، `tests/template-integrity.php`، `tests/wordpress-runtime-audit.php`، `npm run test:unit`).
- **به‌روزرسانی ترجمه‌ها:** اجرای `generate-pot.php`، `sync-po.php`، تکمیل ۱۰۰٪ ترجمه‌های انگلیسی در `music-wave-core-en_US.po` و `music-wave-vip-en_US.po`، کامپایل `generate-mo.php` و `generate-script-json.php`.
- **نسخه‌گذاری، مانیفست‌ها و مستندات:**
  - ارتقای `music-wave-core` به `0.16.0` و `music-wave-vip` به `0.5.2` (`MIN_CORE_VERSION = '0.16.0'`).
  - به‌روزرسانی `release-manifest.json`، `CHANGELOG.md`، `music-wave-core/readme.txt`، `music-wave-vip/readme.txt`، و بازتولید `MANIFEST.sha256` هر پکیج.
  - ایجاد `docs/adr/0005-membership-absence-policy-and-direct-delivery.md` و به‌روزرسانی `docs/access-policy.md`، `docs/secure-downloads.md`، `docs/vip-integration.md`، `docs/delivery-threat-model.md`، `docs/extension-contracts.md`.
  - تدوین گزارش جامع مهندسی، امنیتی و بنچمارک کارایی در `docs/reports/vip-access-delivery-upgrade-report.md`.

---

## بخش ۷ — ماتریس جامع آزمون‌های الزامی (۲۴ سناریو)

1. انتشار `membership` + افزونه VIP غایب + حالت `deny` ← رد دسترسی (`membership_provider_absent`).
2. انتشار `membership` + افزونه VIP غایب + حالت `allow` ← مجاز (`membership_provider_absent`).
3. انتشار `membership` + افزونه VIP غایب + حالت `open_download` ← مجاز (`membership_absent_open_download`).
4. انتشار پولی `purchase_or_membership` (دارای محصول WooCommerce) + کاربر فاقد خرید + VIP غایب + حالت `deny` ← رد (`purchase_required`).
5. انتشار پولی `purchase_or_membership` (دارای محصول WooCommerce) + کاربر فاقد خرید + VIP غایب + حالت `allow` ← رد (`purchase_required` — عدم نشت محتوای پولی در پیش‌فرض).
6. انتشار پولی `purchase_or_membership` (دارای محصول WooCommerce) + کاربر دارای خرید معتبر + VIP غایب + حالت `deny` یا `allow` ← مجاز (`purchase`).
7. انتشار پولی `purchase_or_membership` (دارای محصول WooCommerce) + کاربر فاقد خرید + VIP غایب + حالت `open_download` ← مجاز (`membership_absent_open_download`).
8. انتشار `purchase` (فقط خرید تکی) و `restricted` و `logged_in` + VIP غایب + حالت `open_download` ← عدم باز شدن خودکار برای مهمان (`purchase_required` / `restricted` / `login_required`).
9. حفظ `mw_access_mode = 'logged_in'` در `MetaDefinition::sanitize()` بدون بازنویسی به `restricted`.
10. فعال‌سازی مجدد VIP پس از دوره غیاب ← بازگشت فوری قواعد سطح عضویت بدون تغییر در متای انتشارها.
11. انتشار با دارایی `direct:https://cdn.example.com/audio/track.mp3` بدون نصب VIP ← صدور بلیت `mwt_...`، مصرف یک‌بارمصرف در دانلود، پاسخ `302` با هدرهای امنیتی کامل، و رد استفاده مجدد از همان بلیت (Replay).
12. استریم امن دارایی صوتی `direct:https://cdn.example.com/audio/track.mp3?ver=1` بدون VIP ← مجاز و چندبارمصرف در طول TTL؛ عدم استریم‌پذیری `direct:https://cdn.example.com/archive/album.zip` (فقط دانلود).
13. پشتیبانی از TTLهای بالای ۹۰۰ ثانیه (تا ۳۶۰۰ برای دانلود و ۷۲۰۰ برای استریم) در `DownloadTokenService` و `OpaqueTicketStore`.
14. انتشار ترکیبی دارای یک کیفیت `vip:<32hex>` و یک کیفیت `direct:https://...`: با VIP فعال هر دو کار می‌کنند؛ با VIP غایب، کیفیت `direct:` تحویل می‌شود و کیفیت `vip:` با `503 provider_unavailable` رد می‌شود.
15. رد تمامی ورودی‌های مخرب در `DirectLinkUrlValidator` و `MetaDefinition` و `DownloadAssetRoutes`: `http://`، `javascript:`، `data:`، `file://`، `https://localhost/...`، `https://127.0.0.1/...`، `https://10.0.0.1/...`، `https://172.16.5.4/...`، `https://192.168.1.1/...`، `https://169.254.169.254/...`، `https://100.64.0.1/...`، `https://[::1]/...`، `https://[::ffff:127.0.0.1]/...`، `https://user:pass@cdn.example.com/...`، کاراکترهای `\r\n\0` و پیشوند بزرگ `DIRECT:http://...`.
16. اجرای لیست سفید دامنه (`direct_link_allowed_hosts`): پذیرش دامنه مجاز و رد دامنه غیرمجاز هم در زمان ذخیره و هم در زمان تحویل (حتی اگر لیست سفید بعد از ذخیره محدود شده باشد).
17. کنترل قابلیت `DirectLinkPermission`: کاربر فاقد `manage_mw_protected_assets` قادر به ثبت لینک مستقیم جدید نیست (`403`)، اما قابلیت ویرایش برچسب دارایی موجود حفظ می‌شود.
18. نگاشت `vip:<32hex>` در `RemoteRedirectProvider`: تولید URL امضاشده حاوی `relative_path` واقعی از رجیستری، حذف `local:` در شناسه‌های میراثی، عدم وجود `vip%3A` یا `local%3A` در خروجی، و بازگشت `''` (fail-closed) برای `vip:` ناموجود در رجیستری.
19. اولویت ثابت `MUSIC_WAVE_VIP_REMOTE_SECRET` و چرخش کلید در `RemoteSigningKeyring`: ذخیره با `autoload = false`، انتقال کلید قبلی به `retired`، صحت راستی‌آزمایی امضای هر دو کلید `active` و `retired` با `hash_equals`، و ابطال قطعی پس از پاک‌سازی کلیدهای بازنشسته.
20. ویرایش و ذخیره منابع عضویت VIP در تب طرح‌ها هنگامی که `module_enabled = disabled` است، بدون پاک شدن تنظیمات سایر تب‌ها.
21. صحت خروجی `DeliveryCapabilities` و عدم نمایش نشان گمراه‌کننده «VIP حفاظت‌شده» روی انتشارهای صرفاً `direct:`.
22. پایداری ضدتکرار توکن (`DatabaseReplayStore`) و سهمیه روزانه دانلود (`mw_dl_quota_*`) پس از پاک‌سازی کش شیء (`wp_cache_flush()`).
23. ثبت دقیق شمارنده سهمیه روزانه دانلود و رویداد ممیزی `download_delivered` در سناریوی واقعی تغییرمسیر/تحویل.
24. بنچمارک کارایی `BulkAccessManager::mode_counts()` (کاهش چشمگیر تعداد کوئری و زمان اجرا با کش و ابطال خودکار) و پاک‌سازی دسته‌ای محدود (`LIMIT`) در کران `DatabaseReplayStore::cleanup()`.

---

## بخش ۸ — نتایج بنچمارک اندازه‌گیری‌شده و چک‌لیست نهایی پذیرش

### ۸.۱. نتایج بنچمارک واقعی اجرای کد (PHP 8.3 WASM Runtime)

| عملیات مورد سنجش | حجم نمونه | زمان کل | میانگین هر عملیات | سقف بودجه مجاز | وضعیت |
|---|---:|---:|---:|---:|---|
| `DirectLinkUrlValidator::validate()` | ۱٬۰۰۰ بار | `95.19 ms` | `0.0952 ms/op` | `< 0.50 ms/op` | PASS |
| `DeliveryRouter::can_deliver_asset()` | ۱٬۰۰۰ بار | `52.17 ms` | `0.0522 ms/op` | `< 0.25 ms/op` | PASS |
| `AccessPolicyEngine::decide()` (با `MembershipAbsencePolicy`) | ۱٬۰۰۰ بار | `69.79 ms` | `0.0698 ms/op` | `< 0.50 ms/op` | PASS |
| `RemoteSigningKeyring::verify_signature()` (`hash_equals`) | ۱٬۰۰۰ بار | `62.57 ms` | `0.0626 ms/op` | `< 0.25 ms/op` | PASS |
| `DatabaseReplayStore::cleanup()` (حذف دسته‌ای ۵۰۰ ردیف منقضی) | ۱ دسته | `32.33 ms` | `32.33 ms/batch` | `< 100 ms/batch` | PASS |
| `BulkAccessManager::mode_counts()` (کش سرد — `GROUP BY` روی ۵۰۰ انتشار) | ۱ بار | `5.68 ms` | `5.68 ms` (۱ کوئری) | `< 50 ms` | PASS |
| `BulkAccessManager::mode_counts()` (کش گرم Transient) | ۱٬۰۰۰ بار | `7.74 ms` | `0.0077 ms/op` | `< 0.05 ms/op` | PASS |

### ۸.۲. چک‌لیست نهایی پذیرش پیش از تحویل

- [x] سند مرجع با کد واقعی مخزن ممیزی و تمامی ابهام‌ها و باگ‌های جانبی (`B-NEW-1` تا `B-NEW-8`) مستند شدند.
- [x] پیاده‌سازی کامل فازهای ۱ تا ۵ مطابق استانداردهای کدنویسی وردپرس (WPCS) و سازگار با PHP 7.4 تا 8.4.
- [x] عبور کامل از گیت‌های کیفیت: `check-syntax.php` (روی PHP 7.4/8.3/8.4)، `check-strict-types.php`، `check-abspath.php`، `check-nul-bytes.php`، `phpcs` (صفر خطا روی کل مخزن)، `phpstan` سطح ۵ (صفر خطا روی Core و VIP)، `npm run lint:js` و `npm run test:unit`.
- [x] پوشش کامل ترجمه‌های فارسی به انگلیسی (`generate-pot.php`، `sync-po.php`، `generate-mo.php`، `generate-script-json.php`، `check-translation-coverage.php`، `check-script-translations.php`).
- [x] اجرای موفقیت‌آمیز تمامی سوئیت‌های تست PHP از جمله ۲۴ سناریوی جدید در `tests/vip-access-delivery-suite.php`.
- [x] به‌روزرسانی مستندات، ADR-0005، نسخه‌ها (`0.16.0` و `0.5.2`)، `release-manifest.json`، `MANIFEST.sha256` و ثبت گزارش نهایی در `docs/reports/vip-access-delivery-upgrade-report.md`.
