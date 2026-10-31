# مشخصات و پرامپت اجرایی کامل ارتقای VIP، دسترسی و تحویل فایل در MusicWave

> این فایل **یک سند واحد و خودکفاست**. هوش مصنوعی اجراکننده باید همهٔ پروژه را بررسی کند، ارتقا را از صفر تا صد خودش انجام دهد و نتیجهٔ کامل، تست‌شده و مستند تحویل بدهد. هیچ سند دیگری لازم نیست.

**تاریخ:** ۲۷ اکتبر ۲۰۲۶
**نسخه‌های مبنا (طبق manifest پروژه):** هسته `music-wave-core 0.15.0` (schema `0.14.0`) · `music-wave-vip 0.5.1` · قالب `0.9.2`
**نسخه‌های هدف:** هسته `0.16.0` · VIP `0.5.2` · قالب بدون تغییر نسخه مگر نیاز واقعی · schema بدون تغییر

---

## ۱. ماموریت و تعریف «تمام‌شده»

### ۱.۱ نقش
تو معمار ارشد وردپرس/PHP و مهندس امنیت هستی. مالک پروژه کدنویسی را تمام کرده و می‌خواهد محصول (قالب + افزونه‌ها) حرفه‌ای، امن و قابل‌فروش شود.

### ۱.۲ هدف
ارتقای **تنظیمات، معماری، امنیت و عملکرد** سامانهٔ VIP و مسیر «دسترسی و تحویل فایل» تا:
- حرفه‌ای، **پویا و هوشمند** باشد (رابط، وضعیت واقعی را نشان بدهد، نه فقط گزینه‌های بی‌اثر).
- امن باشد (استانداردهای OWASP، امضای قوی، اعتبارسنجی چندلایه).
- تمیز و استاندارد باشد (SOLID، DRY، شیءگرا، بدون کد تکراری/مرده).
- بدون مهاجرت دیتابیس و بدون شکستن سازگاری عقب‌رو.

### ۱.۳ نتایج مشخص مورد انتظار
1. **سیاست دسترسی بدون ماژول عضویت** با سه حالت روشن و گزینهٔ جدید «دسترسی کامل و لینک دانلود».
2. **لینک مستقیم** (CDN/میزبان خارجی) به‌عنوان منبع فایل در بخش «فایل‌ها و کیفیت دانلود»، با اعتبارسنجی چندلایه و تحویل امن.
3. **رفع باگ نگاشت `vip:`** در حالت تغییرمسیر راه‌دور.
4. **تنظیمات هوشمند:** هر بخش تنظیمات وضعیت واقعی و اثر عملی خود را (با/بدون VIP) نشان بدهد.
5. **سخت‌سازی امنیتی** (اعتبارسنجی، دسترسی‌ها، مدیریت کلید امضا) و **بهبود عملکرد** (کش، کوئری، پاک‌سازی).
6. **پاک‌سازی کامل** کد/فایل/کلید/استایل/ترجمهٔ تکراری و بی‌مصرف.
7. **تست، مستندات، نسخه‌ها و گزارش نهایی** کامل.

### ۱.۴ تعریف «تمام‌شده» (Definition of Done)
همهٔ معیارهای بخش ۱۱ برقرار، همهٔ دروازه‌های کیفیت بخش ۹ سبز (یا با دلیل واقعی «اجرا نشد»)، و گزارش نهایی بخش ۱۳ تحویل شده باشد.

---

## ۲. پروتکل کار مستقل (Autonomy Protocol)

1. **سؤال نپرس؛ تصمیم بگیر و ثبت کن.** تصمیم‌های اصلی در بخش ۱۰ از قبل گرفته شده‌اند. برای هر ابهام دیگر، امن‌ترین و ساده‌ترین گزینهٔ سازگار با استانداردها را انتخاب کن و در بخش «تصمیم‌ها و انحراف‌ها» گزارش نهایی بنویس.
2. **اول کشف و راستی‌آزمایی، بعد کدنویسی.** بخش ۳ «فرضیه‌هاست»، نه حقیقت قطعی؛ شماره خط‌ها ممکن است کهنه باشند. هر فرضیه را در کد واقعی بسنج. اگر کد واقعی فرق داشت، **طراحی را با همان روح و اصول تطبیق بده** و انحراف را ثبت کن.
3. **توقف فقط در این موارد:** (الف) عملیات مخرب/برگشت‌ناپذیر روی داده یا حذف فایل‌های غیرقابل‌بازیابی خارج از مخزن git، (ب) تناقض مستقیم بین دو الزام این سند که هیچ تفسیر امنی ندارد، (ج) نبود امکان اجرای هر نوع تست و اعتبارسنجی. در غیر این صورت کار را ادامه بده.
4. **گردش git:** شاخهٔ جدید `feature/vip-access-delivery-upgrade`؛ یک commit برای هر گام منطقی (پیام شفاف)؛ هرگز push/merge نکن. پیش از هر refactor، تست‌های مشخصه‌سازی (بخش ۵، فاز ۰) سبز باشند.
5. **دامنه:** هر چیز خارج از اهداف بخش ۱ را فقط در «بدهی/پیشنهاد آینده» گزارش بده، نساز. موارد «خارج از دامنه» بخش ۴.۹ را نساز.
6. **صداقت:** نتیجهٔ واقعی هر دستور را ثبت کن. چیزی که اجرا نشد یا ناموفق بود را «سبز» نگو. تستی را برای سبز شدن تضعیف یا حذف نکن؛ مگر آنکه خودش رفتار عمداً‌تغییرکرده را بسنجد و دلیلش در گزارش باشد.

---

## ۳. زمینه و یافته‌های اولیهٔ تحلیل (فرضیه‌هایی که باید راستی‌آزمایی شوند)

### ۳.۱ ساختار
- `music-wave-core`: سیاست دسترسی (`Access/*`)، تحویل (`Downloads/*`)، تنظیمات (`Admin/SettingsPage.php`، `Support/Settings.php`)، متادیتا (`Schema/*`)، ویرایشگر (`assets/editor.js`)، بلوک‌ها.
- `music-wave-vip`: عضویت، مخزن خصوصی دارایی‌ها (`vip:<hash>`)، ارائه‌دهندهٔ محلی (`ProtectedFileProvider`)، ارائه‌دهندهٔ تغییرمسیر راه‌دور امضاشده (`RemoteRedirectProvider`)، صفحهٔ تنظیمات VIP.
- هسته هیچ‌جا به کلاس‌های VIP وابستگی مستقیم ندارد (VIP به هسته وابسته است). این جهت وابستگی **باید حفظ شود**.
- ابزارهای پروژه: `composer test`، `check:syntax`، `check:strict-types`، `check:abspath`، `check:phpcs`، `check:phpstan`، `check:script-translations`، `npm run lint:js`، `tests/run.php`، `tests/vip-access-delivery-suite.php`، `tests/staging-smoke.php`، `tools/final-check.sh`. (وجود/نام دقیق را در فاز ۰ تأیید کن.)

### ۳.۲ فرضیه‌ها (H1–H16) و روش راستی‌آزمایی

| # | فرضیه | روش |
|---|---|---|
| H1 | رادیوهای باکس «رفتار ماژول عضویت» در `Admin/SettingsPage.php` هرگز `disabled` نمی‌شوند؛ حس «قفل بودن» از کنترل‌های `.mwvip-module-control` در صفحهٔ VIP می‌آید (وقتی کلید اصلی خاموش است) | grep `disabled` در `Admin/` هسته + بررسی `music-wave-vip` `SettingsPage.php` و `assets/admin-vip-settings.js` |
| H2 | مقدار `membership_absent_behavior` (`allow`/`deny`) فقط در مسیر «هیچ ارائه‌دهندهٔ عضویتی ثبت نیست» (`AccessPolicyEngine::absent_provider_decision`) خوانده می‌شود؛ با VIP روشن یا نصب‌شدهٔ خاموش بی‌اثر است | خواندن `AccessPolicyEngine` + `music-wave-vip.php` (فیلتر `music_wave_access_decision` با دلیل `vip_module_disabled`) |
| H3 | در `hybrid_access` با محصول WooCommerce نگاشت‌شده، بدون ارائه‌دهنده هم حتی با `allow` تصمیم `purchase_required` می‌ماند | تست مشخصه‌سازی |
| H4 | بدون ارائه‌دهندهٔ تحویل، `Plugin.php` از `NullDownloadProvider` استفاده می‌کند و `DownloadResolver::issue()` برای `vip:`/`local:` دلیل `provider_unavailable` می‌دهد؛ برای شناسه‌های دیگر `provider_failed` | خواندن `Plugin.php` و `DownloadResolver.php` |
| H5 | لینک مستقیم در **سه لایه** مسدود است: (۱) `assets/editor.js` (`validQualities()` رد `^https?://`)، (۲) `Schema/MetaDefinition::sanitize_download_assets` (حذف asset_idهای با scheme http/https)، (۳) متن راهنما در `editor.js` و `Admin/ReleaseMetaBox.php` | خواندن + تست‌های ورودی |
| H6 | مسیر REST `Downloads/DownloadAssetRoutes::normalize_assets` هیچ اعتبارسنجی URL ندارد؛ پس درخواست دست‌ساز مدیر می‌تواند URL خام را به‌عنوان «دارایی حفاظت‌شده» ذخیره کند و لایهٔ اول را دور بزند | تست REST با payload دست‌ساز |
| H7 | `RemoteRedirectProvider` نشانی را از `base + prefix + asset_id` با `rawurlencode` هر بخش می‌سازد؛ برای `vip:<hash>` نتیجه `…/vip%3A<hash>` است که CDN/میزبان دانلود نمی‌تواند به فایل واقعی نگاشت کند | **تست قرمز** که نشانی ساخته‌شده را بسنجد |
| H8 | امضای راه‌دور: payload `url|expires|mode|kid`، HMAC-SHA256 + base64url، راز ≥۳۲ نویسه، TTL ۳۰–۹۰۰ ثانیه، allowlist میزبان بعد از همهٔ فیلترها، HTTPS اجباری، fail-closed | خواندن و تأیید؛ اگر فرق داشت گزارش |
| H9 | فقط یک کلید امضای فعال؛ `kid` صرفاً برچسب است؛ چرخش کلید بدون قطعی ممکن نیست؛ راز در DB است | خواندن تنظیمات و `RemoteRedirectProvider` |
| H10 | هر چهار بخش تنظیمات هسته (پیام‌های دسترسی، حالت دسترسی انبوه، دانلودهای ایمن، پیش‌فرض‌های انتشار جدید) بدون شرط رندر می‌شوند و بدون VIP کار می‌کنند، اما بدون VIP سه‌تایشان گمراه‌کننده‌اند (ذخیره و تأیید می‌شوند ولی نتیجه در فرانت شکست می‌خورد یا معنای دیگری دارد) | اجرای واقعی صفحه‌ها با/بدون VIP |
| H11 | چهار محل `has_filter('music_wave_download_provider')` برای تشخیص «تحویل فعال است؟»: `Admin/SettingsPage.php`، `Admin/Concerns/DiagnosticsChecks.php`، `Blocks/Release/CollectionAndDownloadRenderer.php`، `Support/SiteHealth.php` | grep |
| H12 | کد مرده: `add_settings_section`/`add_settings_field` در `music-wave-vip/src/SettingsPage.php` (صفحه دستی رندر می‌شود، `do_settings_sections()` فراخوانی نمی‌شود) و ناهمخوانی نام‌های `music_wave_vip_membership`/`music-wave-vip_membership` | grep + اجرای صفحه |
| H13 | `ReleaseDownloadCatalog::is_asset_streamable()` و `TrackStreamPresenter::streamable_quality()` برای دارایی‌های غیر `vip:`/`local:` فرمت را از کجا می‌گیرند و آیا `direct:` را پخش‌پذیر می‌کنند | خواندن + تست |
| H14 | `BulkAccessManager::mode_counts()` (یا معادل) کوئری سنگین روی `postmeta` است | `EXPLAIN` روی دادهٔ نمونه |
| H15 | ذخیرهٔ تیکت غیرشفاف (`OpaqueTicketStore`) از ترنزینت + fallback در `wp_options` استفاده می‌کند (یک نوشتن DB به‌ازای هر تیکت)؛ replay در جدول DB اندیس‌دار است | خواندن + اندازه‌گیری |
| H16 | مستندات از کد عقب‌اند (مثلاً `docs/vip-integration.md` امضای راه‌دور را با فرمت قدیمی توصیف می‌کند) | مقایسه |

### ۳.۳ ممیزی عمومی (علاوه بر فرضیه‌ها — اجباری)
کل سطح VIP و تحویل را با این چک‌لیست بررسی کن و یافته‌ها را **P0 (بحرانی) تا P3** طبقه‌بندی کن. P0/P1 مرتبط با دامنه را همین‌جا رفع کن؛ بقیه را در «بدهی» گزارش بده.

- **امنیت:** nonce و capability در همهٔ اکشن‌ها/فرم‌ها/REST؛ `permission_callback` واقعی؛ `$wpdb->prepare`؛ escaping خروجی در لحظهٔ چاپ؛ sanitization ورودی؛ path traversal و symlink در مخزن فایل؛ هدرهای پاسخ؛ مقایسهٔ توکن/امضا/nonce فقط با `hash_equals` (مقدار مورد اعتماد اول، ورودی کاربر دوم)؛ بازتاب ورودی در HTML/هدر؛ افشای خطا؛ `ABSPATH` guard؛ لاگ‌کردن داده‌های حساس.
- **معماری:** کلاس‌های خدایی، وابستگی چرخه‌ای، `static`/سراسری بی‌دلیل، منطق در تمپلیت، تکرار قواعد، literalهای پراکنده، نشت انتزاع.
- **عملکرد:** کوئری‌های سنگین/N+1، option با `autoload` بی‌جا، نبود کش/بی‌اعتبارسازی، بارگذاری JS/CSS در صفحه‌های نامربوط، cron بی‌حد.
- **تنظیمات و چرخهٔ عمر:** defaults، sanitize همهٔ کلیدها، `uninstall`/پاک‌سازی، سازگاری نسخه‌ها.
- **سازگاری و i18n:** PHP 7.4، RTL، text domainها، رشته‌های ترجمه‌ناپذیر، دسترس‌پذیری رابط مدیریت (برچسب فرم، `aria`، تباین).

---

## ۴. طراحی هدف (معماری نهایی)

### ۴.۱ اصل بنیادی
**سیاست دسترسی («چه کسی مجاز است») از مسیر تحویل («فایل چگونه می‌رسد») جدا است.** تصمیم دسترسی همیشه مرجع است؛ تحویل فقط پس از تأیید دسترسی، توکن، سهمیه و replay انجام می‌شود. اصل **deny-by-default** حفظ می‌شود.

### ۴.۲ سیاست دسترسی بدون ماژول عضویت

- کلید ذخیره: `membership_absent_behavior` (بدون تغییر). مقادیر: `deny` (قفل)، `allow` (باز بودن انتشارهای فقط-عضویتی)، **جدید:** `open_download` (دسترسی کامل و لینک دانلود). مقادیر قدیمی بدون تغییر نام و بدون مهاجرت.
- کلاس جدید `Access/MembershipAbsencePolicy` (منبع یگانهٔ حقیقت، هم‌الگو با `AccessModePolicy` موجود): ثابت‌های مدها، `mode()` (با فیلتر موجود `music_wave_membership_absent_behavior`)، `choices()` (برچسب + توضیح)، `sanitize()` (نامعتبر → `allow`)، `opens_membership()`، `opens_hybrid()`. همهٔ literalهای پراکندهٔ `'allow'`/`'deny'` (در `Settings`، `Plugin`، `AccessPolicyEngine`، `SettingsPage`) به آن ارجاع بدهند.
- `AccessPolicyEngine::absent_provider_decision()` از این کلاس بخواند؛ دلیل‌های موجود (`allow`/`deny` و…) برای گزارش‌ها و عملیات انبوه حفظ شوند؛ دلیل جدید `membership_provider_absent_open`.

**جدول صدق (فقط وقتی هیچ ارائه‌دهندهٔ عضویتی ثبت نیست). ستون‌های `deny` و `allow` باید دقیقاً برابر رفتار فعلی باشند (با تست مشخصه‌سازی قفل شود):**

| نوع دسترسی انتشار | `deny` | `allow` | `open_download` |
|---|---|---|---|
| `membership` | قفل | باز | باز (دلیل `membership_provider_absent_open`) |
| `purchase_or_membership` | رفتار فعلی | رفتار فعلی (با محصول نگاشت‌شده هنوز `purchase_required`) | **باز** (سمت عضویت) |
| `purchase` | بدون تغییر | بدون تغییر | بدون تغییر (فروش تکی رایگان نمی‌شود) |
| `restricted` | بدون تغییر | بدون تغییر | بدون تغییر |

تفاوت واقعی `open_download` با `allow`، بازشدن انتشارهای «خرید-یا-عضویت» است؛ **تحویل فایل به این مقدار وابسته نیست** (بخش ۴.۳). برچسب‌های UI باید همین را صادقانه بگویند (بخش ۶). اگر راستی‌آزمایی (H2/H3) نشان داد رفتار فعلی با این جدول فرق دارد، جدول را با رفتار واقعی فعلی تطبیق بده و فقط ستون جدید را اضافه کن.

### ۴.۳ تحویل: روتر scheme-محور

کلاس‌های جدید در `music-wave-core/src/Downloads/` (هر کدام یک مسئولیت):

| کلاس | مسئولیت |
|---|---|
| `DirectLinkAsset` | هویت `direct:<https-url>`: `is()`، `encode()`، `decode()`. نشانگر `direct:` قبل از هر چیز تشخیص داده می‌شود تا قاعدهٔ «URL خام هرگز به‌عنوان asset_id ذخیره نشود» واقعاً برقرار بماند |
| `DirectLinkUrlValidator` | **تنها** محل قواعد اعتبارسنجی URL (بخش ۴.۴)؛ خالص و تست‌پذیر بدون وردپرس |
| `DirectLinkPermission` | `can_assign()`: پیش‌فرض `current_user_can('manage_options')`، قابل گسترش با فیلتر `music_wave_can_assign_direct_link` (بدون نوشتن capability جدید در DB) |
| `RedirectResponse` | پاسخ ۳۰۲ مشترک: `Cache-Control: no-store, private`، `Referrer-Policy: no-referrer`، `X-Content-Type-Options: nosniff`، `X-Robots-Tag: noindex`، سپس `exit`؛ قابل تزریق برای تست |
| `DirectLinkProvider` | `implements DownloadProvider, StreamableDownloadProvider`؛ **بعد از** تأیید توسط `DownloadResolver`، URL را **دوباره** اعتبارسنجی می‌کند (دادهٔ DB قابل اعتماد نیست: import، WP-CLI، `update_post_meta` مستقیم) و با `RedirectResponse` هدایت می‌کند. فیلتر `music_wave_direct_link_url` برای امضای CDN. `stream()` توکن replay را مصرف نمی‌کند (Rangeهای متعدد) |
| `DeliveryRouter` | `implements DownloadProvider, StreamableDownloadProvider`؛ دارایی `direct:` → `DirectLinkProvider`؛ غیر آن → ارائه‌دهندهٔ خارجی (فیلتر) |
| `DeliveryCapabilities` | `is_available()`، `supports(string $asset_id)`، `state()` سه‌حالته (آداپتور فعال / فقط لینک مستقیم هسته / هیچ)؛ با memo درون‌درخواستی (خصوصیت نمونه، نه وضعیت سراسری) |

**سیم‌کشی:** `Plugin.php`: `$external = apply_filters('music_wave_download_provider', new NullDownloadProvider())` و سپس `new DeliveryRouter(new DirectLinkProvider(...), $external)`. **قرارداد فیلتر `music_wave_download_provider` برای توسعه‌دهندگان ثالث بدون تغییر می‌ماند.**

- `DownloadResolver::issue()`: شرط سخت `instanceof NullDownloadProvider` با `DeliveryCapabilities::supports($asset_id)` جایگزین شود تا `provider_unavailable` برای `vip:`/`local:` بدون آداپتور دقیقاً مثل امروز بماند.
- `DeliveryCapabilities` جایگزین هر محلی شود که `has_filter('music_wave_download_provider')` دارد (H11).
- `ReleaseDownloadCatalog::is_asset_streamable()` / `TrackStreamPresenter`: برای `direct:` فرمت از فیلد `format` یا پسوند مسیر (بدون query و فقط از فهرست فرمت‌های مجاز موجود پروژه) تعیین شود تا فایل در پخش‌کننده بیاید.
- پیام امنیتی در `CollectionAndDownloadRenderer` بر اساس نوع دارایی صادق باشد: برای `direct:`: «تحویل از میزبان خارجی؛ پس از بررسی دسترسی صادر می‌شود اما نشانی قابل اشتراک است.»

| دارایی | VIP نصب‌نشده | VIP فعال |
|---|---|---|
| `vip:` / `local:` | `provider_unavailable` (بدون تغییر) | ارائه‌دهندهٔ VIP |
| `direct:` | تحویل (اگر تصمیم دسترسی اجازه دهد) | تحویل (همان شرط) |
| URL خام / scheme ناشناخته | رد در نگارش؛ در تحویل `provider_failed` | همان |

`vip:`/`local:` هرگز از مسیر هسته سرو نمی‌شوند.

### ۴.۴ قواعد `DirectLinkUrlValidator` (همه الزامی، با تست واحد)
1. trim؛ طول ≤ ۲۰۴۸؛ بدون کاراکتر کنترلی (`0x00–0x1F`، `0x7F`)، بدون فاصله/بک‌اسلش (ضد CRLF injection و header splitting).
2. با `wp_parse_url`؛ scheme دقیقاً `https` (غیرحساس به حروف).
3. host موجود و lowercase؛ **بدون userinfo**؛ port فقط خالی یا ۴۴۳.
4. host فقط ASCII/punycode (غیر ASCII رد، ضد homograph).
5. **IP literal رد شود** (v4/v6/دهدهی/هگز/اکتال)، `localhost`، میزبان تک‌برچسبی، پسوندهای `.local`، `.internal`، `.localhost`.
6. اگر تنظیم `direct_link_allowed_hosts` خالی نیست: host باید یکی از موارد باشد (wildcard فقط پیشوندی مثل `*.cdn.example.com`؛ `cdn.example.com.evil.net` و `evil-cdn.example.com` باید رد شوند).
7. fragment حذف؛ خروجی نهایی با `esc_url_raw($url, ['https'])`.

### ۴.۵ لایه‌های نگارش: سه لایه، یک منبع حقیقت
هر سه از `DirectLinkUrlValidator` و `DirectLinkAsset` استفاده کنند (JS فقط آینهٔ UX است؛ مرجع سرور است):

1. **`Schema/MetaDefinition::sanitize_download_assets`:** شاخهٔ `direct:` **قبل** از قاعدهٔ حذف http/https؛ رفتار `vip:`/`local:` بدون تغییر.
2. **`Downloads/DownloadAssetRoutes::normalize_assets`:** `direct:` فقط با اعتبارسنجی + `DirectLinkPermission::can_assign()`؛ هر `asset_id` با scheme خام http/https → خطای 400 با کد ثابت (مثل `mw_raw_url_not_allowed`). `deny_unauthorized_assignments()` برای `direct:` اعمال نشود (ارجاع به گاوصندوق نیست) ولی `current_user_can('edit_post', $release_id)` همچنان لازم است. طبق راهنمای REST وردپرس، `validate_callback`/`sanitize_callback` پیش از `permission_callback` اجرا می‌شوند؛ در آن‌ها هیچ نوشتن DB یا کوئری سنگینی نباشد.
3. **`assets/editor.js` (`DeliveryManager`، `validQualities()`):** دکمهٔ سوم «افزودن فایل با لینک مستقیم (CDN/میزبان خارجی)»؛ دکمهٔ فعلی به «افزودن فایل با شناسهٔ حفاظت‌شده (VIP)» تغییر نام دهد؛ در ردیف کیفیت برای `source=direct` یک `TextControl` «لینک مستقیم فایل (HTTPS)» با اعتبارسنجی زنده؛ ذخیره به‌صورت `direct:<url>`؛ قاعدهٔ «رد هر `^https?://`» فقط برای ردیف‌های غیر direct بماند. نمایش دکمه فقط وقتی `can_assign` درست است (مقدار از داده‌های ویرایشگر).
4. **متاباکس کلاسیک و متن راهنما:** یک راهنمای واحد وابسته به حالت تحویل (نه چند متن جدا).

### ۴.۶ حالت راه‌دور: نگاشت `vip:` → مسیر نسبی (رفع باگ H7)
- `RemoteRedirectProvider` یک وابستگی اختیاری به رجیستری دارایی بگیرد؛ برای `vip:<hash>` مقدار `relative_path` را از `mw_vip_assets` بخواند و در **مسیر امضاشده** بگذارد؛ هرگز `vip%3A…` تولید نشود.
- fail-closed: رکورد نبود / اثر انگشت اندازه نامطابق / مسیر نامعتبر → رشتهٔ خالی.
- سخت‌سازی مسیر: رد `..`، `.`، `//`، مسیر مطلق، `\`، `%2e%2e`، `%2f` رمزگشایی‌شده، NUL؛ هر segment جداگانه `rawurlencode` شود.
- ۳۰۲ را به `RedirectResponse` مشترک بسپار و کد تکراری را حذف کن؛ `MIN_CORE_VERSION` در VIP به `0.16.0` برود.

### ۴.۷ مدیریت کلید امضا (الگوی صنعتی: چند کلید هم‌زمان برای چرخش بدون قطعی)
- راز در ثابت `wp-config.php` (مثلاً `MUSIC_WAVE_VIP_REMOTE_SECRET`) اولویت دارد؛ اگر تعریف شد، فیلد DB در UI غیرفعال و «مدیریت‌شده با wp-config» نمایش داده شود.
- `remote_signing_keys`: فهرست `{kid, secret, created, status: active|retired}` با `autoload=no`؛ امضا فقط با کلید `active` و `kid` همان در URL؛ کلید `retired` تا حذف دستی برای overlap سمت CDN نگه داشته می‌شود.
- دکمهٔ «چرخش کلید»: `random_bytes(32)` → base64url، قبلی `retired`، رویداد حسابرسی.
- حداقل طول راز ≥ ۳۲ نویسه و fail-closed حفظ شود؛ مهاجرت بی‌صدا: راز فعلی به‌عنوان کلید `active` با `kid` فعلی ثبت شود.
- مستند: نمونهٔ مرجع تأییدکنندهٔ سمت CDN/وب‌سرور باید چند `kid` را بپذیرد.

### ۴.۸ نکات مرورگر و شبکه
- **دانلود در برابر باز شدن در تب:** مرورگرها ویژگی `download` را برای منبع cross-origin نادیده می‌گیرند مگر میزبان `Content-Disposition: attachment` بفرستد. پس: UI/مستندات بگویند میزبان باید این هدر را بدهد یا از طریق فیلتر `music_wave_direct_link_url` پارامتر معادل (مثل `response-content-disposition` در S3/CloudFront) اضافه شود.
- **CORS:** اگر پخش‌کننده برای نشانی استریم از `crossorigin`، `fetch` یا Web Audio استفاده می‌کند (در کد بررسی کن)، میزبان باید CORS بدهد؛ در مستندات و تشخیص ثبت شود.
- **مرز SSRF:** هسته هرگز خودش URL لینک مستقیم را fetch نکند (نه HEAD، نه اعتبارسنجی شبکه‌ای). اگر روزی لازم شد: `wp_safe_remote_*` + allowlist + بازبینی IP پس از DNS + غیرفعال‌کردن redirect (OWASP SSRF).

### ۴.۹ خارج از دامنه (نساز)
`attachment:<id>` (رسانهٔ وردپرس؛ فایل عمومی را شبیه حفاظت‌شده نشان می‌دهد و مسیر «کپی به مخزن خصوصی» از قبل هست)، امضای نامتقارن، اتصال نشانی به IP/نشست، سهمیهٔ بایتی، DRM/واترمارک، fetch سمت سرور از لینک مستقیم، اینترفیس `AssetAwareProvider` (YAGNI: دو پیاده‌سازی واقعی ندارد). این‌ها فقط در بدهی/پیشنهاد آینده ثبت شوند.

---

## ۵. فازهای اجرا و دروازه‌ها

| فاز | کار | دروازهٔ خروج |
|---|---|---|
| **۰** | کشف: نقشهٔ فایل‌ها، راستی‌آزمایی H1–H16، ممیزی عمومی (۳.۳)، خط پایه (`composer test` و همهٔ دستورهای بخش ۹ — phpcs/phpstan را با `composer install` نصب و اجرا کن)، **تست‌های مشخصه‌سازی** برای جدول تصمیم فعلی (۴ نوع دسترسی × `allow`/`deny` × ارائه‌دهنده هست/نیست) و تست‌های قرمز باگ‌ها (H6، H7) | گزارش فاز ۰ در گزارش نهایی؛ مشخصه‌سازی سبز |
| **۱** | زیرساخت: `DirectLinkUrlValidator`، `DirectLinkAsset`، `DirectLinkPermission`، `RedirectResponse` | تست‌های واحد validator (شامل موارد حمله) سبز |
| **۲** | سیاست: `MembershipAbsencePolicy`، `Settings`، `AccessPolicyEngine`، `Plugin.php` (بخش سیاست) | مشخصه‌سازی قدیمی سبز + تست‌های `open_download` سبز |
| **۳** | تحویل: `DirectLinkProvider`، `DeliveryRouter`، `DeliveryCapabilities`، `DownloadResolver`، `ReleaseDownloadCatalog`/`TrackStreamPresenter`، پیام‌ها | `vip:` همچنان بسته؛ `direct:` با و بدون VIP تحویل می‌شود |
| **۴** | نگارش: `MetaDefinition`، `DownloadAssetRoutes`، `editor.js`، `ReleaseMetaBox`، i18n | سه لایه هم‌رفتار؛ URL خام همه‌جا رد؛ دادهٔ قدیمی دست‌نخورده |
| **۵** | VIP: نگاشت `vip:` راه‌دور (۴.۶)، `RedirectResponse`، **مدیریت کلید (۴.۷)**، رفع یافته‌های P0/P1 ممیزی | نشانی بدون `vip%3A`؛ چرخش کلید تست‌شده |
| **۶** | UI و تشخیص: باکس سیاست، خط «اثر فعلی»، بنر تحویل، بنرهای زمینه‌ای، پنل تشخیص (کش‌شده/تنبل)، صفحهٔ VIP با کلید خاموش | چهار ترکیب وضعیت درست نمایش داده می‌شود |
| **۷** | عملکرد (بخش ۷): اندازه‌گیری قبل/بعد، پیاده‌سازی موارد لازم | اعداد واقعی در گزارش |
| **۸** | پاک‌سازی (بخش ۸)، مستندات و ADR، نسخه‌ها، changelog، اجرای کامل دروازه‌ها، گزارش نهایی | بخش ۱۱ و ۹ برقرار |

وابستگی: ۲ و ۳ پس از ۱؛ ۴ پس از ۳؛ ۵ مستقل از ۴؛ ۶ پس از ۲ و ۳؛ ۷ و ۸ آخر.

---

## ۶. رابط کاربری: تنظیمات هوشمند و متن‌ها

### ۶.۱ رفتار
1. **هیچ قفل مصنوعی:** گزینه‌های هسته همیشه قابل انتخاب‌اند؛ به‌جای قفل، **وضعیت واقعی و اثر عملی** نشان داده شود.
2. **خط «اثر فعلی»** زیر باکس سیاست (بر اساس وضعیت واقعی).
3. **بنر تحویل** در تب دانلودهای ایمن بر اساس `DeliveryCapabilities::state()`: آداپتور فعال / فقط لینک مستقیم / هیچ ارائه‌دهنده.
4. **یادداشت زمینه‌ای دسترسی انبوه** وقتی حالت هدف `membership`/`purchase_or_membership` است و ارائه‌دهنده نیست.
5. **صفحهٔ VIP با کلید اصلی خاموش:** `notice` درجا + دکمهٔ «بازکردن کنترل‌های عضویت برای ویرایش» با همان پرچم موجود `mwvip_controls_unlocked` و منطق JS موجود (راستی‌آزمایی کن `sanitize_settings()` پرچم را می‌پذیرد).
6. **پنل تشخیص:** شمارش انتشارها تنبل (درخواست جدا پس از رندر) و کش‌شده (مثلاً ۵ دقیقه، `wp_cache_get/set` با گروه اختصاصی) و بی‌اعتبار پس از عملیات انبوه؛ هرگز کوئری سنگین در رندر اولیه.
7. **هشدارهای پویا:** (الف) انتشار عضویتی هست ولی سیاست `deny` و VIP نیست؛ (ب) راه‌دور فعال ولی راز ضعیف/خالی؛ (ج) `direct:` روی انتشار پولی (هشدار، نه مسدودسازی)؛ (د) `direct:` فعال ولی میزبان مجاز تنظیم نشده (توصیه).
8. همهٔ رشته‌ها با text domain موجود، RTL-safe، دسترس‌پذیر (label/aria).

### ۶.۲ متن‌ها (فارسی)

**عنوان باکس:** سیاست دسترسی بدون ماژول عضویت
**توضیح:** «تعیین می‌کند وقتی هیچ ماژول عضویتی (مثل MusicWave VIP) فعال نیست، انتشارهای دارای محدودیت عضویت چه رفتاری داشته باشند. خریدهای تکی WooCommerce همیشه مستقل و محفوظ می‌مانند. این گزینه‌ها همیشه قابل انتخاب‌اند، حتی وقتی VIP نصب نیست.»

| مقدار | برچسب | توضیح |
|---|---|---|
| `open_download` | **دسترسی کامل و لینک دانلود** | «همهٔ انتشارهای عضویتی و «خرید یا عضویت» برای همه باز می‌شوند. فایل‌های با لینک مستقیم پخش و دانلود می‌شوند. فایل‌های مخزن حفاظت‌شدهٔ VIP همچنان به ماژول VIP نیاز دارند.» |
| `allow` | **مشاهدهٔ آزاد انتشارهای عضویتی** | «انتشارهای فقط-عضویتی باز می‌شوند؛ «خرید یا عضویت» با محصول نگاشت‌شده همچنان خرید می‌خواهد. فایل‌های حفاظت‌شدهٔ VIP بدون ماژول تحویل نمی‌شوند.» |
| `deny` | **قفل کامل** | «تا فعال‌شدن یک ماژول عضویت، انتشارهای عضویتی مسدود می‌مانند.» |

**خط اثر فعلی:**
- بدون ارائه‌دهنده: «اکنون: هیچ ماژول عضویتی فعال نیست؛ این سیاست همین حالا اعمال می‌شود.»
- VIP روشن: «اکنون: MusicWave VIP فعال است؛ این سیاست تا غیرفعال‌شدن ماژول اثری ندارد.»
- VIP نصب ولی کلید خاموش: «اکنون: VIP نصب است اما اعمال قوانین خاموش است؛ محتوای عضویتی باز است و تحویل امن همچنان کار می‌کند.»

**راهنمای فیلد دارایی (وابسته به حالت تحویل):**
- محلی: «شناسهٔ مخزن را از تب «تحویل حفاظت‌شده» کپی کنید (`vip:…`). مسیر قدیمی `local:…` فقط برای سازگاری است.»
- راه‌دور: «شناسهٔ `vip:…` به‌طور خودکار به مسیر نگاشت می‌شود، یا مسیر نسبی روی میزبان دانلود (مثل `album/track-320.mp3`).»
- لینک مستقیم: «نشانی HTTPS فایل روی CDN/میزبان خارجی. پس از بررسی دسترسی، کاربر به آن هدایت می‌شود و نشانی در مرورگر او دیده و قابل اشتراک است؛ برای محتوای پولی از مخزن حفاظت‌شدهٔ VIP استفاده کنید. برای دانلود (نه باز شدن در تب) میزبان باید هدر `Content-Disposition: attachment` بفرستد.»

---

## ۷. عملکرد (اول اندازه‌گیری، بعد بهینه‌سازی)

**اندازه‌گیری پایه:** تعداد کوئری و زمان برای بار صفحهٔ تنظیمات هسته، صدور توکن، تحویل، و یک پخش با seek (چند درخواست به endpoint استریم می‌رسد؟). ابزار: Query Monitor / `SAVEQUERIES` / لاگ زمان. اعداد قبل و بعد در گزارش نهایی.

| # | مورد | اقدام |
|---|---|---|
| P1 | شمارش انتشارها روی `postmeta` | کش + بارگذاری تنبل (۶.۱) — **الزامی** |
| P2 | `DeliveryCapabilities` چندبار در یک درخواست | memo درون‌درخواستی — **الزامی** |
| P3 | ذخیرهٔ تیکت: ترنزینت + fallback `wp_options` = یک نوشتن DB به‌ازای هر تیکت. ترنزینت‌ها «بهینه‌سازی عملکرد»اند و با object cache دائمی ممکن است هر لحظه حذف شوند | اگر اندازه‌گیری نیاز را ثابت کرد: اینترفیس `TicketStorage` با دو پیاده‌سازی (object cache وقتی `wp_using_ext_object_cache()`؛ جدول اندیس‌دار با `expires_at`) |
| P4 | **وضعیت امنیتی (replay/سهمیه) هرگز در کش قابل‌حذف نباشد** | همان جدول DB بماند؛ تستی ثابت کند پاک‌شدن object cache replay را بازنشانی نمی‌کند — **الزامی** |
| P5 | رشد ردیف‌های منقضی (تیکت/replay) | cron دسته‌ای با `LIMIT`؛ هر option جانبی `autoload=no` — **الزامی** |
| P6 | هر Range/seek ممکن است دوباره به endpoint استریم وردپرس برسد | اندازه‌گیری؛ در صورت بار زیاد: کش درون‌درخواستی تصمیم دسترسی و توکن استریم کوتاه‌عمر |
| P7 | بارگذاری JS/CSS تنظیمات VIP در صفحه‌های نامربوط | `enqueue` فقط روی screen خودش — **الزامی** اگر نقض شود |

---

## ۸. پاک‌سازی کد و فایل تکراری/بی‌مصرف

**روش:**
1. نامزدها: grep ارجاع‌ها + تحلیل ایستا (PHPStan unused / Psalm `--find-unused-code`؛ ESLint `no-unused-vars`).
2. هر نامزد: جست‌وجو در کد، `apply_filters`/`do_action`، تمپلیت‌ها، JS، تست‌ها، مستندات، فایل‌های ترجمه.
3. «بدون ارجاع و بدون قرارداد عمومی مستند» → حذف. «قرارداد عمومی (فیلتر/اکشن مستند)» → حذف نکن، فقط گزارش بده.
4. هر حذف در commit جدا با تست سبز.

**نامزدهای شناخته‌شده:** کد مردهٔ `add_settings_section/field` و ناهمخوانی نام‌ها در VIP (H12)؛ چهار `has_filter` تکراری (H11)؛ literalهای `'allow'`/`'deny'` پراکنده؛ کد ۳۰۲ تکراری در `RemoteRedirectProvider`؛ متن‌های ترجمه/`.pot`/`.json` بی‌مصرف پس از تغییر برچسب‌ها؛ هر چیز دیگری که ممیزی پیدا کند.

---

## ۹. استانداردهای کد و دروازه‌های کیفیت

### ۹.۱ استانداردها (الزامی)
- **PHP:** نحو سازگار با ۷.۴ (بدون enum، readonly، `match`، named arguments، union types، constructor promotion، nullsafe)؛ `declare(strict_types=1)` مطابق چک‌کنندهٔ پروژه؛ تست روی ۷.۴ (کف)، ۸.۳ (توصیهٔ رسمی وردپرس)، ۸.۴.
- **معماری:** شیءگرا؛ `final` برای کلاس‌های بدون ارث‌بری؛ DI از طریق سازنده؛ بدون وضعیت سراسری جدید؛ جهت وابستگی VIP → هسته حفظ شود.
- **اصول:** SOLID، DRY (هر قاعده یک منبع حقیقت)، KISS، YAGNI؛ اینترفیس جدید فقط با دو پیاده‌سازی واقعی.
- **Refactoring ایمن:** ابتدا تست مشخصه‌سازی، سپس تغییر ساختار؛ تغییر رفتار و تغییر ساختار در یک commit قاطی نشود.
- **وردپرس:** WordPress Coding Standards؛ ورودی با `sanitize_*`/`esc_url_raw`، خروجی با `esc_*` در لحظهٔ چاپ؛ هر مسیر REST با `permission_callback` واقعی؛ nonce برای فرم‌ها؛ `$wpdb->prepare`؛ مقایسهٔ امضا/توکن فقط `hash_equals`.
- **JS:** ESLint پروژه، بدون وابستگی تولیدی جدید، i18n با `@wordpress/i18n`.
- **بدون وابستگی تولیدی جدید و بدون مهاجرت DB.**

### ۹.۲ دستورها
`composer test` · `composer check:syntax` · `composer check:strict-types` · `composer check:abspath` · `composer check:phpcs` · `composer check:phpstan` · `composer check:script-translations` · `npm ci && npm run lint:js` · `php tests/run.php` · `tools/final-check.sh` (هر کدام در پروژه وجود داشت). اگر ابزاری نصب نیست، نصب کن؛ اگر نشد، «اجرا نشد + دلیل».

### ۹.۳ آزمون‌های جدید (حداقل)

*سیاست دسترسی*
1. `Settings::sanitize` مقدار `open_download` را حفظ و نامعتبر را به `allow` برمی‌گرداند.
2. جدول صدق ۴.۲ برای هر سلول (بدون ارائه‌دهنده)؛ ستون‌های `allow`/`deny` برابر مشخصه‌سازی.
3. با ارائه‌دهندهٔ ثبت‌شده، تصمیم ارائه‌دهنده حاکم است و `open_download` اثری ندارد.

*اعتبارسنجی لینک مستقیم*
4. رد: `http://`، `javascript:`، `data:`، `//host/x`، userinfo، IP literal (v4/v6/دهدهی/هگز)، `localhost`، `.internal`، host غیر ASCII، `\r\n` و `%0d%0a`، طول > ۲۰۴۸، port غیر ۴۴۳.
5. پذیرش `https://cdn.example.com/a/b.mp3?x=1`؛ با allowlist فقط میزبان‌های منطبق؛ `evil-cdn.example.com` و `cdn.example.com.evil.net` در برابر `*.cdn.example.com` رد شوند.
6. round-trip `encode/decode`؛ `is()` برای `vip:`/`local:` false.

*سه لایهٔ نگارش*
7. REST: `direct:https://…` با مدیر پذیرفته، با کاربر غیرمجاز رد؛ URL خام همیشه 400.
8. `MetaDefinition`: `direct:` حفظ؛ URL خام حذف؛ `vip:`/`local:` بدون تغییر.
9. JS: `validQualities()` ردیف direct معتبر را می‌پذیرد و URL خام را رد می‌کند.

*تحویل*
10. بدون VIP و انتشار رایگان: `direct:` دانلود می‌شود (۳۰۲ + هدرهای `no-store`/`no-referrer`/`nosniff`/`noindex`).
11. **با VIP فعال:** `direct:` همچنان کار می‌کند و `vip:` به VIP می‌رود.
12. بدون VIP: `vip:`/`local:` → `provider_unavailable`.
13. URL دستکاری‌شده در DB (مثل `http://` یا `javascript:`) در لحظهٔ تحویل رد می‌شود.
14. `DirectLinkProvider` هرگز `vip:`/`local:` را سرو نمی‌کند.
15. `stream()` توکن replay را مصرف نمی‌کند؛ Rangeهای متعدد مشکلی ندارند.
16. ارائه‌دهندهٔ ثالث ثبت‌شده با `music_wave_download_provider` برای دارایی غیر-direct بدون تغییر کار می‌کند.

*حالت راه‌دور و کلید*
17. `vip:<hash>` → مسیر `relative_path` در نشانی امضاشده، بدون `vip%3A`.
18. رکورد ناموجود / اثر انگشت نامطابق / مسیر با `..` یا `%2e%2e` → رشتهٔ خالی.
19. چرخش کلید: امضا با کلید `active` و `kid` درست؛ ثابت `wp-config` اولویت دارد؛ مهاجرت بی‌صدای راز فعلی.

*UI/عملکرد*
20. بنر/خط وضعیت در ترکیب (VIP نصب‌نشده / نصب-خاموش / روشن) × (۳ مقدار سیاست).
21. `mode_counts` کش می‌شود و بعد از عملیات انبوه بی‌اعتبار می‌شود؛ رندر اولیهٔ تنظیمات کوئری شمارش اجرا نمی‌کند.
22. پاک‌شدن object cache replay/سهمیه را بازنشانی نمی‌کند.

**دستی (چندمرورگره):** دانلود و پخش `direct:` در Chrome/Firefox/Safari دسکتاپ و موبایل، با و بدون `Content-Disposition` و CORS در میزبان؛ صفحهٔ تنظیمات هسته و VIP در RTL، با/بدون VIP. اگر امکان اجرای دستی نداری، این مرحله را در گزارش «اجرا نشد» ثبت کن و مراحل را برای مالک بنویس.

---

## ۱۰. تصمیم‌های نهایی (از قبل گرفته شده — بازگشایی نکن)

| موضوع | تصمیم |
|---|---|
| تحویل در برابر سیاست دسترسی | مستقل؛ روتر scheme-محور؛ با VIP فعال هم `direct:` کار می‌کند |
| پیشوند لینک مستقیم | `direct:<https-url>`؛ هرگز URL خام ذخیره نشود |
| `attachment:` | ساخته نمی‌شود (۴.۹) |
| چه کسی لینک مستقیم ثبت کند | فقط `manage_options` + فیلتر `music_wave_can_assign_direct_link` |
| allowlist میزبان | تنظیم اختیاری `direct_link_allowed_hosts`، پیش‌فرض خالی؛ در UI/مستندات توصیه برای محصول فروشی |
| `direct:` روی انتشار پولی | هشدار، نه مسدودسازی |
| مقادیر قدیمی `allow`/`deny` | بدون تغییر نام و بدون مهاجرت |
| مدیریت کلید چندگانه + ثابت `wp-config` | در این نسخه انجام شود (۴.۷) |
| بهینه‌سازی تیکت (P3) | فقط با اثبات اندازه‌گیری |

---

## ۱۱. معیارهای پذیرش نهایی

1. بدون VIP و با `open_download`: انتشار `membership` برای مهمان باز است و `direct:` پخش/دانلود (۳۰۲) می‌شود.
2. بدون VIP و با `allow`: انتشار فقط-عضویتی باز؛ `vip:` با پیام روشن تحویل نمی‌شود؛ رفتار قدیمی بدون تغییر.
3. بدون VIP و با `deny`: انتشار `membership` مسدود.
4. **با VIP فعال:** `direct:` و `vip:` هر دو درست کار می‌کنند.
5. `vip:`/`local:` در هیچ حالتی بدون ارائه‌دهندهٔ VIP تحویل نمی‌شوند.
6. URL خام در JS، REST و متادیتا رد می‌شود؛ `direct:http://…` رد؛ `direct:https://…` از مدیر پذیرفته.
7. URL دستکاری‌شده در DB در لحظهٔ تحویل رد می‌شود.
8. حالت راه‌دور: نشانی امضاشدهٔ `vip:` مسیر واقعی دارد و `vip%3A` ندارد؛ چرخش کلید بدون قطعی ممکن است.
9. هر چهار بخش تنظیمات بدون VIP وضعیت واقعی خود را نشان می‌دهند؛ شمارش‌ها کش و تنبل‌اند.
10. هیچ تست موجودی حذف/تضعیف نشده؛ همهٔ دروازه‌های ۹.۲ سبز یا با دلیل «اجرا نشد».
11. دادهٔ موجود (`mw_download_assets`، `mw_download_asset_id`، `mw_vip_assets`، تنظیمات) بدون مهاجرت خوانده می‌شود.
12. کد مرده/تکراری فهرست بخش ۸ حذف شده و گزارش «حذف‌شده/نگه‌داشته‌شده با دلیل» آمده است.
13. یافته‌های P0/P1 ممیزی (۳.۳) در دامنه رفع شده و بقیه در بدهی ثبت شده‌اند.
14. مستندات، ADR، نسخه‌ها و changelog به‌روزند.

---

## ۱۲. ریسک‌ها و کاهش

| ریسک | کاهش |
|---|---|
| سوءاستفاده از `direct:` برای محتوای پولی (پس از تحویل قابل اشتراک است) | هشدار صریح، فقط مدیر، توصیهٔ `vip:` برای محتوای پولی |
| Open redirect / فیشینگ از روی دامنهٔ سایت | https-only، رد userinfo/IP/کنترل‌کاراکتر، allowlist اختیاری، اعتبارسنجی در لحظهٔ تحویل |
| رفتار متفاوت مرورگرها در دانلود cross-origin | ۴.۸، تست دستی، مستند میزبان |
| تغییر معنای تنظیم موجود | مقادیر قدیمی بدون تغییر؛ تست مشخصه‌سازی |
| شکستن ارائه‌دهندگان ثالث | قرارداد فیلتر حفظ؛ تست ۱۶ |
| ۴۰۴ در CDN به‌خاطر نگاشت راه‌دور | fail-closed + تشخیص در تب تحویل |
| حذف اشتباه کد «به‌ظاهر مرده» | روش بخش ۸، commit جدا |
| Downgrade هسته با دارایی‌های `direct:` ذخیره‌شده | باید fail-closed شوند؛ در changelog ثبت شود |
| مهاجرت کلید امضا | ثبت بی‌صدای راز فعلی به‌عنوان `active`؛ تست ۱۹ |

---

## ۱۳. مستندات، نسخه‌ها و گزارش نهایی

### ۱۳.۱ مستندات
- ADR: `docs/adr/0005-membership-absence-policy-and-direct-delivery.md` (سیاست دسترسی ≠ مسیر تحویل؛ روتر scheme-محور؛ پیشوند `direct:`؛ چرا `attachment:` و `AssetAwareProvider` نیامدند).
- به‌روزرسانی: `docs/access-policy.md`، `docs/secure-downloads.md`، `docs/download-qualities-and-hosting.md`، `docs/extension-contracts.md` (فیلترهای `music_wave_direct_link_url` و `music_wave_can_assign_direct_link`)، `docs/data-dictionary.md`، `docs/compatibility-matrix.md`، `docs/delivery-threat-model.md` (لینک مستقیم قابل اشتراک؛ نشانی امضاشدهٔ قابل تکرار تا انقضا)، `docs/vip-integration.md` (**رفع drift:** امضای واقعی `url|expires|mode|kid` است) و نمونهٔ مرجع تأییدکنندهٔ CDN با چند `kid`.
- هر مستندی که پس از تغییرات ناسازگار شد، اصلاح شود.

### ۱۳.۲ نسخه‌ها
هسته `0.16.0`؛ VIP `0.5.2` با `MIN_CORE_VERSION=0.16.0`؛ `release-manifest.json` و changelog هر دو افزونه؛ schema بدون تغییر.

### ۱۳.۳ گزارش نهایی (فایل `docs/reports/vip-access-delivery-upgrade-report.md` + خلاصه در پاسخ)
1. **خلاصهٔ اجرایی** (چه شد، چه نشد).
2. **نتیجهٔ راستی‌آزمایی H1–H16:** تأیید/رد هر مورد با شاهد.
3. **یافته‌های ممیزی** با اولویت P0–P3 و وضعیت (رفع‌شده/بدهی).
4. **فهرست فایل‌های تغییرکرده/جدید/حذف‌شده** با دلیل.
5. **نتیجهٔ واقعی هر دستور بخش ۹.۲** (سبز/قرمز/اجرا نشد + دلیل).
6. **اعداد عملکرد قبل/بعد.**
7. **تصمیم‌ها و انحراف‌ها** از این سند با دلیل.
8. **بدهی و پیشنهاد آینده** (از جمله موارد خارج از دامنه).
9. **راهنمای تست دستی** برای مالک (مراحل و نتیجهٔ انتظار).
10. **یادداشت انتشار/ارتقا و بازگشت (rollback).**

---

## پیوست — استانداردها و منابع مرجع
- OWASP Unvalidated Redirects and Forwards Cheat Sheet — https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html
- OWASP Server-Side Request Forgery Prevention Cheat Sheet — https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html
- Amazon CloudFront: signed URLs و trusted key groups (چرخش کلید) — https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/private-content-trusted-signers.html
- PHP Manual: `hash_equals` — https://www.php.net/hash_equals
- WordPress REST API: `permission_callback`، `validate_callback`، `sanitize_callback` — https://developer.wordpress.org/rest-api/extending-the-rest-api/routes-and-endpoints/#permissions-callback
- WordPress Developer Blog: Transients API و object cache — https://developer.wordpress.org/news/2024/06/an-introduction-to-the-transients-api/
- HTML Standard: دانلود cross-origin و `download` — https://html.spec.whatwg.org/multipage/semantics.html#as-a-download
- نیازمندی‌های PHP وردپرس: کف ۷.۴، توصیه ۸.۳ (طبق منابع ثانویه ۲۰۲۶؛ پیش از انتشار با صفحهٔ رسمی wordpress.org/about/requirements تطبیق بده).
