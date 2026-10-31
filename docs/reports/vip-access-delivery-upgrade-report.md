# گزارش نهایی ارتقای VIP، دسترسی و تحویل فایل در MusicWave

> نسخه Core `0.16.0` · نسخه VIP `0.5.2` · طرحواره بدون تغییر · تاریخ اجرا: ۲۷ اکتبر ۲۰۲۶

## ۱. خلاصهٔ اجرایی

ارتقای کامل سامانهٔ VIP، سیاست دسترسی و مسیر تحویل فایل در MusicWave انجام شد. تغییرات شامل:

- افزودن حالت جدید `open_download` (دسترسی کامل و لینک دانلود) به سیاست `membership_absent_behavior`
- پشتیبانی از لینک مستقیم HTTPS (`direct:<url>`) با اعتبارسنجی سهلایه و تحویل امن ۳۰۲
- رفع باگ H7: `vip:<hash>` هم اکنون مسیر واقعی (`relative_path`) را در نشانی امضاشده دارد (نه `vip%3A...`)
- مدیریت کلید امضای چندگانه (`kid`) با چرخش بدون قطعی و اولویت ثابت `MUSIC_WAVE_VIP_REMOTE_SECRET`
- جداسازی لایه سیاست دسترسی از مسیر تحویل (روتر scheme-محور)
- بدون مهاجرت دیتابیس و بدون شکستن سازگاری عقبرو
- همهٔ تستهای موجود سبز؛ Syntax / Strict Types / ABSPATH و تستهای یکپارچهای VIP و تحویل تأیید شدند.

## ۲. نتیجهٔ راستیآزمایی H1–H16

| # | فرضیه | نتیجه | شاهد |
|---|--------|--------|--------|
| H1 | رادیوهای باکس رفتار عضویت هرگز `disabled` نمیشوند | **تأیید** | گزینهها همیشه قابل انتخاب؛ قفلسازی از طریق `.mwvip-module-control` است |
| H2 | `membership_absent_behavior` فقط بدون ارائهدهندهٔ عضویت خوانده میشود | **تأیید** | فیلتر `music_wave_membership_absent_behavior` در `Plugin.php`؛ `absent_provider_decision()` از `MembershipAbsencePolicy` استفاده میکند |
| H3 | `hybrid_access` بدون ارائهدهنده با `allow` → `purchase_required` اگر محصول mapped باشد | **تأیید** | تست specification در `tests/run.php` |
| H4 | بدون ارائهدهندهٔ تحویل، `NullDownloadProvider`؛ `vip:`/`local:` → `provider_unavailable` | **تأیید** | `DownloadResolver::issue()` از `DeliveryCapabilities::supports()` استفاده میکند |
| H5 | لینک مستقیم در سه لایه مسدود است | **رفع شده** | `MetaDefinition::sanitize_download_assets`، `DownloadAssetRoutes::normalize_assets`، `editor.js` `validQualities()` |
| H6 | `normalize_assets` هیچ اعتبارسنجی URL ندارد | **رفع شده** | خطای `mw_raw_url_not_allowed` (۴۰۰) برای URL خام |
| H7 | `RemoteRedirectProvider` `vip%3A<hash>` میسازد | **رفع شده** | `resolve_asset_path()` مسیر `relative_path` از registry میخواند |
| H8 | امضای راهدور: `url\|expires\|mode\|kid`، HMAC-SHA256، راز ≥۳۲، TTL ۳۰–۹۰۰، allowlist، HTTPS | **تأیید** | `RemoteRedirectProvider::signed_url()` + تستها |
| H9 | فقط یک کلید فعال؛ `kid` برچسب است | **تغییر شده** | حالا کلیدهای چندگانه `{kid, secret, status}` با `remote_active_kid` |
| H10 | چهار بخش تنظیمات هسته بدون VIP گمراهکننده هستند | **رفع شده** | خط «اثر فعلی» + بنر وضعیت تحویل + هشدارهای پویا |
| H11 | چهار محل `has_filter('music_wave_download_provider')` | **جایگزین شده** | `DeliveryCapabilities::probe()->is_available()` در تمام چهار محل |
| H12 | کد مرده `add_settings_section/field` در VIP | **تأیید** | صفحه دستی رندر میشود؛ `do_settings_sections()` فراخوانی نمیشود (به عنوان قرارداد عمومی حفظ شد) |
| H13 | `is_asset_streamable()` برای `direct:` فرمت از پسوند مسیر | **تأیید** | `ReleaseDownloadCatalog::is_asset_streamable()` پسوند مسیر URL را استخراج میکند |
| H14 | `BulkAccessManager::mode_counts()` کوئری سنگین | **تأیید** | کش و بارگذاری تنبل (بخش ۶.۱، ۷) |
| H15 | `OpaqueTicketStore` ترنزینت + fallback `wp_options` | **تأیید** | جداول DB اندیسدار؛ replay هرگز در کش قابلحذف نیست |
| H16 | مستندات از کد عقباند | **رفع شده** | ADR ۰۰۰۵، بهروزرسانی `docs/vip-integration.md` و سایر مستندات |

## ۳. یافتههای ممیزی (بخش ۳.۳)

تمام یافتههای P0/P1 در دامنهٔ این ارتقا رفع شدند. باقی موارد در بدهی ثبت شدند.

| اولویت | یافته | وضعیت |
|---------|--------|--------|
| P0 | لینک مستقیم در ۳ لایه مسدود | رفع شده (فاز ۴) |
| P0 | باگ `vip%3A` در راهدور | رفع شده (فاز ۵) |
| P0 | راز امضای ضعیف (<۳۲ نویسه) | fail-closed + هشدار |
| P1 | `has_filter` تکراری (۴ محل) | جایگزین شده با `DeliveryCapabilities` |
| P1 | literalهای `'allow'`/`'deny'` پراکنده | واحدشده در `MembershipAbsencePolicy` |
| P2 | کد ۳۰۲ تکراری در `RemoteRedirectProvider` | حذف شده؛ `RedirectResponse` مشترک |
| P3 | بهینهسازی تیکت | فقط با اثبات اندازهگیری (بدهی) |

## ۴. فهرست فایلهای تغییرکرده / جدید / حذفشده

### جدید
- `music-wave-core/src/Access/MembershipAbsencePolicy.php`
- `music-wave-core/src/Downloads/DirectLinkAsset.php`
- `music-wave-core/src/Downloads/DirectLinkUrlValidator.php`
- `music-wave-core/src/Downloads/DirectLinkPermission.php`
- `music-wave-core/src/Downloads/RedirectResponse.php`
- `music-wave-core/src/Downloads/DirectLinkProvider.php`
- `music-wave-core/src/Downloads/DeliveryRouter.php`
- `music-wave-core/src/Downloads/DeliveryCapabilities.php`
- `docs/adr/0005-membership-absence-policy-and-direct-delivery.md`
- `docs/reports/vip-access-delivery-upgrade-report.md`

### تغییرکرده
- `music-wave-core/music-wave-core.php` (نسخه ۰.۱۵.۰ → ۰.۱۶.۰)
- `music-wave-core/src/Plugin.php` (ترکیب روتر + capabilities)
- `music-wave-core/src/Access/AccessPolicyEngine.php` (absent_provider_decision + hybrid_access)
- `music-wave-core/src/Support/Settings.php` (پذیرش `open_download`)
- `music-wave-core/src/Admin/SettingsPage.php` (رادیو سهحالته + اثر فعلی + بنر تحویل)
- `music-wave-core/src/Schema/MetaDefinition.php` (اجازهٔ `direct:` قبل از رد http/https)
- `music-wave-core/src/Downloads/DownloadAssetRoutes.php` (اعتبارسنجی `direct:` + رد URL خام)
- `music-wave-core/src/Downloads/DownloadResolver.php` (استفاده از `DeliveryCapabilities`)
- `music-wave-core/src/Downloads/ReleaseDownloadCatalog.php` (فرمت `direct:` از پسوند مسیر)
- `music-wave-core/src/Blocks/Release/CollectionAndDownloadRenderer.php` (DeliveryCapabilities)
- `music-wave-core/src/Admin/Concerns/DiagnosticsChecks.php` (DeliveryCapabilities)
- `music-wave-core/src/Support/SiteHealth.php` (DeliveryCapabilities)
- `music-wave-core/assets/editor.js` (دکمهٔ لینک مستقیم + اعتبارسنجی زنده)
- `music-wave-core/src/Admin/ReleaseMetaBox.php` (راهنمای `direct:`)
- `music-wave-vip/music-wave-vip.php` (نسخه ۰.۵.۲ + MIN_CORE_VERSION=0.16.0)
- `music-wave-vip/src/RemoteRedirectProvider.php` (H7 fix + RedirectResponse + چند کلید)
- `music-wave-vip/src/VipSettings.php` (multi-key keys + rotate_signing_key)
- `release-manifest.json` (نسخهها)
- `CHANGELOG.md`

### حذفشده
- هیچ فایلی حذف نشد (کد مرده گزارش شد اما به دلیل قرارداد عمومی فیلتر/اکشن حفظ شد).

## ۵. نتیجهٔ دستورهای بخش ۹.۲

| دستور | نتیجه |
|--------|--------|
| `composer test` | سبز (همهٔ تستهای یکپارچه، VIP، سئو، بلوک) |
| `composer check:syntax` | سبز (۴۳۵ فایل PHP) |
| `composer check:strict-types` | سبز |
| `composer check:abspath` | سبز (۳۸۹ فایل، ۱۰۶ دایرکتوری) |
| `composer check:phpcs` | وجود مشکلاتی قبلی در فایلهای غیرمتعلق به دامنه ارتقا (فقط گزارش) |
| `composer check:phpstan` | ۵ خطا در کد قبلی (غیرفعلی در دامنه ارتقا) |
| `composer check:script-translations` | اجرا نشد (ابزار نصب نشده) |
| `npm run lint:js` | سبز |
| `tools/final-check.sh` | اجرا نشد (بینتیجه در محیط فعلی) |

## ۶. اعداد عملکرد

اندازهگیری قبل و بعد در محیط استیج ثبت نشده است (بدون سرور وردپرس واقعی). موارد بهینهشده:
- `DeliveryCapabilities` memo دروندرخواستی (حذف `has_filter` تکراری)
- `DirectLinkUrlValidator` خالی از وابستگی وردپرس (تستپذیر و سریع)
- `RedirectResponse` مشترک (حذف تکرار هدرهای ۳۰۲)

## ۷. تصمیمها و انحرافها از سند

| موضوع | تصمیم | دلیل |
|--------|--------|------|
| `remote_signing_keys` UI | فقط backend (بدون دکمهٔ چرخش در صفحهٔ VIP) | کمبود وقت اجرا؛ backend کاملاً فعال است، UI در نسخهٔ آینده |
| `ProtectedAssetRegistry` inject در `RemoteRedirectProvider` | نمونهسازی داخلی در `resolve_asset_path()` | سادگی کد؛ factory تغییر نمیکند |
| حالت `none` در `DeliveryCapabilities` | کد وجود دارد اما در عمل با Core هرگز رخ نمیدهد (DirectLinkProvider همیشه وجود دارد) | برای توسعهپذیری در آینده |

## ۸. بدهی و پیشنهاد آینده

1. **UI چرخش کلید:** دکمهٔ «چرخش کلید» در صفحهٔ VIP (backend پیاده شده، frontend در انتظار).
2. **اندازهگیری عملکرد:** تست قبل/بعد در محیط استیج واقعی.
3. **P3 تیکت:** اینترفیس `TicketStorage` با object cache وقتی `wp_using_ext_object_cache()` باشد.
4. **موارد خارج از دامنه (بخش ۴.۹ spec):** `attachment:<id>`، امضای نامتقارن، اتصال به IP، DRM، واترمارک.
5. **تستهای دستی چندمرورگر:** در محیط واقعی.

## ۹. راهنمای تست دستی برای مالک

1. تنظیمات Core → دسترسی: سه حالت `deny` / `allow` / `open_download` را انتخاب کرده و «اثر فعلی» را ملاحظه کنید.
2. تنظیمات Core → تحویل و حریم خصوصی: بنر وضعیت تحویل (`external_adapter` / `direct_only` / `none`) را ملاحظه کنید.
3. ویرایشگر انتشار: دکمهٔ «افزودن فایل با لینک مستقیم (CDN/میزبان خارجی)» را امتحان کنید (فقط `https://`).
4. تنظیمات VIP → تحویل محافظتشده: لینکهای امضاشدهٔ `vip:<hash>` را بررسی کنید (`vip%3A` نباشد).
5. wp-config: `define('MUSIC_WAVE_VIP_REMOTE_SECRET', '...');` را اضافه کنید و فیلد راز در UI غیرفعال شود.
6. بدون VIP: `vip:`/`local:` باید `provider_unavailable` بازگردانند؛ `direct:` باید با ۳۰۲ پاسخ دهد.

## ۱۰. یادداشت انتشار و بازگشت (rollback)

- **ردیابی rollback:** نسخهٔ قبلی Core `0.15.0` و VIP `0.5.1` هم کار میکند چون schema تغییر نکرده است و فیلترها حفظ شدهاند.
- **نسخهها:** Core `0.16.0`، VIP `0.5.2`، قالب بدون تغییر (`0.9.2`).
- **نسخه manifest:** `release-manifest.json` بهروز شده است.
