# سند مرجع کامل سیستم VIP و تحویل فایل — MusicWave

> **هدف این سند:** یک مرجع واحد و دقیق که با خواندن آن (توسط انسان یا هوش مصنوعی) وضعیت کامل و واقعی معماری VIP، لایهٔ دسترسی، لایهٔ تحویل فایل، افزونهٔ VIP، تنظیمات، مسیرهای REST، جداول دیتابیس، کنترل‌های امنیتی و نقاط ضعف شناخته‌شدهٔ پروژهٔ MusicWave روشن شود.
>
> **مبنای صحت:** تمام مطالب این سند از روی کد واقعی همین مخزن استخراج شده است. مسیر فایل‌ها و نام کلاس‌ها/توابع/فیلترها عیناً از کد نقل شده‌اند. هرجا بین کد و مستندات تناقض بوده، صریحاً علامت‌گذاری شده است.

---

## ۰. راهنمای سریع برای مصرف‌کنندهٔ این سند (AI)

اگر فقط یک تصویر کلی می‌خواهی، این پنج گزاره کل سیستم را خلاصه می‌کند:

1. **دو لایهٔ کاملاً مستقل وجود دارد:** «سیاست دسترسی» (چه کسی مجاز است) و «مسیر تحویل» (فایل چطور می‌رسد). مثبت بودن تصمیم دسترسی، هیچ‌گاه خودش یک URL نیست. (`docs/adr/0005-...md`، `AccessPolicyEngine` در برابر `DownloadProvider`/`DeliveryRouter`)
2. **هسته deny-by-default است:** تا افزونهٔ مجتمع‌کننده یک `DownloadProvider` از طریق فیلتر `music_wave_download_provider` تزریق نکند، هیچ فایل `vip:`/`local:` تحویل داده نمی‌شود. این محدودیت در `DeliveryCapabilities::supports()` (بازگشت `false` وقتی ارائه‌دهندهٔ خارجی `NullDownloadProvider` است) و سپس در `DownloadResolver::issue()` اعمال می‌شود. توجه: ثابت `STATE_NONE` تعریف شده اما `state()` هرگز آن را برنمی‌گرداند؛ حالت واقعیِ بدون آداپتور `direct_only` است و `is_available()` همیشه `true` است (چون `direct:` همیشه در دسترس است).
3. **افزونهٔ VIP سه نقش دارد:** (الف) منبع تصمیم عضویت (`ConfigurableMembershipProvider`)، (ب) ارائه‌دهندهٔ تحویل محلی برای فایل‌های خصوصی (`ProtectedFileProvider` با Range)، (ج) ارائه‌دهندهٔ تحویل راه‌دور با URL امضاشدهٔ کوتاه‌عمر (`RemoteRedirectProvider` → 302).
4. **در حالت «سرور خارجی» (`delivery_provider = remote_redirect`)، کاربر یک ۳۰۲ می‌گیرد** که مقصد آن میزبان راه‌دور است. توکن/امضا فقط یکپارچگی و انقضا را تضمین می‌کند، نه محرمانگی مبدأ. این یک محدودیت ذاتی معماری است، نه باگ.
5. **پخش پیش‌رونده با HTTP Range فقط در تحویل محلی است.** `ProtectedFileProvider` با `Accept-Ranges`، کد ۲۰۶ و `X-Accel-Buffering: no` پاسخ می‌دهد؛ در حالت `remote_redirect` کاربر یک ۳۰۲ به میزبان راهدور می‌گیرد و رفتار Range به آن سرور واگذار می‌شود. در کل مخزن HLS/DASH/MSE/ترنسکد وجود ندارد.

---

## ۱. فهرست موجودیت‌ها و نسخه‌ها

| موجودیت | مسیر | نسخهٔ فعلی | توضیح |
|---|---|---|---|
| افزونهٔ هسته | `music-wave-core/` | **0.16.0** (`MUSIC_WAVE_CORE_VERSION`) | لایهٔ دسترسی، تحویل، REST، بلوک‌ها، اسکیمای متا |
| افزونهٔ VIP | `music-wave-vip/` | **0.5.2** (`MUSIC_WAVE_VIP_VERSION`) | عضویت، فایل محافظت‌شده، تحویل راه‌دور |
| پوسته | `musicwave/` (قالب) | 0.9.2 | قالب‌های front-end (`single-mw_release.html` و …) |
| سازگاری | PHP ≥ 7.4، WordPress ≥ 6.6، Tested up to 6.8 | — | از `readme.txt` هر دو افزونه |

### ۱.۱. نکتهٔ مهم و متناقض در مورد حداقل نسخهٔ هسته (تناقض مستندشده)

سه عدد متفاوت برای «حداقل نسخهٔ هستهٔ موردنیاز افزونهٔ VIP» در کد وجود دارد:

| منبع | مقدار |
|---|---|
| `music-wave-vip/music-wave-vip.php` → `MUSIC_WAVE_VIP_MIN_CORE_VERSION` | `'0.16.0'` |
| `music-wave-vip/src/Plugin.php` → `Plugin::MIN_CORE_VERSION` | `'0.9.0'` |
| `music-wave-vip/readme.txt` (توضیح) | «Requires MusicWave Core **0.11.0+**» |

**دام اجرایی واقعی:** تابعی که در عمل تصمیم می‌گیرد VIP بالا بیاید یا نه، `Plugin::core_meets_minimum()` است که از **`self::MIN_CORE_VERSION = '0.9.0'`** استفاده می‌کند (نه ثابت `MUSIC_WAVE_VIP_MIN_CORE_VERSION` در فایل اصلی افزونه). یعنی عملاً VIP با هستهٔ ۰٫۹٫۰ به بالا بارگذاری می‌شود؛ اما ثابت فایل اصلی و readme عدد دیگری تبلیغ می‌کنند. **این ناهم‌راستایی باید در ارتقاء بعدی یکسان‌سازی شود.**

### ۱.۲. وابستگی یک‌طرفه (اصل معماری)

- VIP → Core وابسته است (VIP ثابت `MUSIC_WAVE_VIP_FILE` را ست می‌کند).
- Core هرگز کلاس VIP را `require` نمی‌کند؛ فقط از طریق `ManaCore\MusicWave\Core\Support\VipModuleStatus::is_active()` فعّال بودن VIP را **کاوش** می‌کند.
- Core بدون VIP و بدون WooCommerce کاملاً کار می‌کند (حالت‌های خرید غیرفعال می‌شوند؛ حالت‌های عضویت از طریق تنظیم `membership_absent_behavior` به allow/deny می‌رسند).
- VIP بدون WooCommerce هم کار می‌کند (فقط منبع نقش/فیلتر عضویت).

---

## ۲. کاتالوگ تنظیمات کامل

### ۲.۱. تنظیمات هسته (Core)

مسیر: `music-wave-core/src/Support/Settings.php` (کلاس `Settings`، گزینهٔ `music_wave_settings`).

نکات کلیدی:
- `AccessModePolicy` «منبع یگانهٔ حقیقت» برای حالت‌های دسترسی، برچسب‌ها، sanitize سطح VIP و مقادیر پیش‌فرض است.
- `DEFAULT_VIP_LEVEL = 'mw_vip'`.

تنظیمات مرتبط با تحویل/دسترسی که در `AccessModePolicy` و `Settings` می‌آیند:

| کلید | معنا | پیش‌فرض / محدوده |
|---|---|---|
| حالت دسترسی پیش‌فرض انتشار | کدام سیاست دسترسی برای انتشار اعمال شود | یکی از `public` / `logged_in` / `purchase` / `membership` / `purchase_or_membership` / `restricted` |
| `membership_absent_behavior` | رفتار وقتی ارائه‌دهندهٔ عضویت وجود ندارد (فقط با Null provider) | `allow` (پیش‌فرض) / `deny` / `open_download` |
| سطح VIP پیش‌فرض | سطح لازم برای دسترسی | `mw_vip` |

### ۲.۲. تنظیمات افزونهٔ VIP

مسیر: `music-wave-vip/src/VipSettings.php`، گزینهٔ `music_wave_vip_settings`.
گزینهٔ قدیمی ریشه: `music_wave_vip_protected_root` (LEGACY_ROOT_OPTION).

**پیش‌فرض‌های مهم (طبق `VipSettings::defaults()`):**

| کلید | معنا | پیش‌فرض | محدوده/sanitize |
|---|---|---|---|
| `module_enabled` | کلید اصلی ماژول VIP | `enabled` | `enabled`/`disabled` |
| `delivery_provider` | ارائه‌دهندهٔ تحویل | `local` | فقط `local` یا `remote_redirect` |
| `delivery_access` | چه کسی اجازهٔ تحویل بگیرد | `logged_in` | `logged_in` (پیش‌فرض) یا `everyone` |
| `protected_root` | ریشهٔ فایل‌های محافظت‌شده | — | مسیر مطلق، باید خارج از web root باشد |
| `remote_base_url` | نشانی پایهٔ میزبان راه‌دور | `''` | باید HTTPS |
| `remote_path_prefix` | پیشوند مسیر راه‌دور | `''` | متن |
| `remote_signing_secret` | راز امضای HMAC راه‌دور | `''` | اگر غیرخالی، حداقل ۳۲ کاراکتر |
| `remote_signature_param` | پارامتر query امضا | `signature` | متن |
| `remote_expires_param` | پارامتر query انقضا | `expires` | متن |
| `remote_ttl` | طول عمر URL راه‌دور (ثانیه) | `300` | clamp ۳۰ تا ۹۰۰ |
| `remote_allowed_hosts` | میزبان‌های مجاز اضافی برای ریدایرکت | `[]` | آرایهٔ هاست، `*.` wildcard |
| `remote_key_id` | شناسهٔ کلید امضا (`kid`) | `''` | برای چرخش کلید |
| `sendfile_mode` | روش ارسال فایل | `none` | `none` / `xsendfile` / `xaccel` |
| `xaccel_prefix` | پیشوند داخلی X-Accel | — | مسیر داخلی nginx |
| `membership_sources` | منابع عضویت | `[role, filter, woocommerce_plans]` | زیرمجموعهٔ این پنج |
| `promote_vip_role` | ارتقای نقش VIP | `enabled` | `enabled`/`disabled` |
| `delete_data_on_uninstall` | پاکسازی داده هنگام حذف افزونه | `disabled` | `enabled`/`disabled` |

**قواعد نرمال‌سازی (`VipSettings::normalize()`):**
- `delivery_provider` فقط در مجموعهٔ `{local, remote_redirect}`.
- `remote_ttl` بین ۳۰ و ۹۰۰.
- راز غیرخالی باید حداقل ۳۲ کاراکتر باشد (وگرنه امضا fail-closed).
- در هر لحظه **دقیقاً یک کلید `active`** در مجموعهٔ کلیدها مجاز است.
- `sendfile_mode` فقط در `{none, xsendfile, xaccel}`.

**چرخش کلید (`VipSettings::rotate_signing_key()`):** یک `kid` به شکل `k<8hex>` و یک راز ۳۲ بایتی base64url تولید می‌کند. ساختار چندکلیدی: `{kid, secret, created, status: active|retired}`. امضا فقط با کلید `active` انجام می‌شود و `kid` در URL می‌آید تا چرخش بدون قطعی انجام شود. اگر ثابت `MUSIC_WAVE_VIP_REMOTE_SECRET` در `wp-config.php` تعریف شود، بر تنظیمات دیتابیس **اولویت** دارد.

**صفحهٔ تنظیمات VIP** (`music-wave-vip/src/SettingsPage.php`، `PAGE_SLUG = 'music-wave-vip'`, `OPTION_GROUP = 'music_wave_vip'`) — چهار تب واقعی (`render_tabs()`، خط ۵۶۱):
1. **عمومی:** `module_enabled` (کلید اصلی اعمال قوانین VIP) و `delivery_access` (سیاست پخش/دانلود مهمان).
2. **تحویل حفاظتشده:** `delivery_provider`؛ `protected_root` به‌همراه دکمهٔ «ایجاد، ایمن‌سازی و همگام‌سازی خودکار پوشه»، ماتریس پیش‌بررسی ۶گانه و مسیر فعال؛ `remote_base_url`, `remote_path_prefix`, `remote_signing_secret` (فیلد password با دکمهٔ ایجاد/پاک‌سازی و `clear_remote_signing_secret`), `remote_signature_param`, `remote_expires_param`, `remote_ttl`, `remote_allowed_hosts`, `remote_key_id`؛ پیش‌نمایش URL امضاشده؛ `sendfile_mode`, `xaccel_prefix`؛ بارگذار مخزن خصوصی با جست‌وجو/کپی `vip:<hash>`/حذف.
3. **عضویت و طرح‌ها:** `membership_sources`, جدول محصولات طرح VIP (`plan_rows` / `VipPlanCatalog`), `promote_vip_role`.
4. **پیشرفته و حسابرسی:** لاگ حسابرسی عضویت (`VipAuditLog::entries(50)`) و `delete_data_on_uninstall`.

اعتبارسنجی‌ها در `sanitize_settings()`: اگر `delivery_provider = remote_redirect` و `remote_base_url` یا `remote_signing_secret` خالی باشد هشدار خطا؛ راز کوتاه‌تر از ۳۲ کاراکتر نامعتبر؛ checkbox `clear_remote_signing_secret` برای پاک‌کردن راز ذخیره‌شده.

---

## ۳. لایهٔ سیاست دسترسی (Access Policy)

### ۳.۱. حالت‌های دسترسی

منبع یگانه: `music-wave-core/src/Access/AccessModePolicy.php`.

حالت‌ها (`public`, `logged_in`, `purchase`, `membership`, `purchase_or_membership`, `restricted`) با برچسب فارسی، sanitize و پیش‌فرض‌ها در همین کلاس متمرکزند. تصمیم‌گیری در `AccessPolicyEngine` (`music-wave-core/src/Access/AccessPolicyEngine.php`) انجام می‌شود و خروجی یک `AccessDecision` است (شامل allow/deny، سطح لازم، دلیل، و پیام/CTA ارتقاء).

**اصول:**
- **deny-by-default**: هر حالت ناشناخته یا ورودی مشکوک → deny.
- یک تصمیم «allow» **هرگز** شامل URL نیست.

### ۳.۲. رفتار نبود ارائه‌دهندهٔ عضویت

`music-wave-core/src/Access/MembershipAbsencePolicy.php`:

| مقدار | معنا | `opens_membership()` | `opens_hybrid()` |
|---|---|---|---|
| `deny` | دسترسی عضویتی بسته | نه | نه |
| `allow` (پیش‌فرض) | دسترسی عضویتی باز (بدون فایل مخصوص) | بله | نه |
| `open_download` | دسترسی کامل + لینک دانلود باز | بله | بله |

- فیلتر: `music_wave_membership_absent_behavior`.
- دلیل باز شدن: `REASON_OPEN = 'membership_provider_absent_open'`.
- **فقط وقتی اعمال می‌شود که ارائه‌دهندهٔ عضویت `Null` باشد** (یعنی VIP غیرفعال/غایب).
- `open_download` فقط انتشارهای عضویتی و دوگانه (`purchase_or_membership`) را باز می‌کند؛ **خرید تکی (`purchase`) دست‌نخورده می‌ماند**.
- گزینه‌های UI سه‌گانه در `choices()` با برچسب/توضیح فارسی.

### ۳.۳. کلید اصلی ماژول VIP (`module_enabled`)

وقتی ماژول VIP در حالت `disabled` باشد، فقط این دو حالت «باز» می‌شوند: `membership_required` و `purchase_or_membership_required`، با دلیل `vip_module_disabled`. اما حالت `purchase` و `restricted` هرگز به‌خاطر غیرفعال بودن VIP باز نمی‌شوند.

### ۳.۴. دسترسی مهمان به تحویل

تنظیم `delivery_access` (پیش‌فرض `logged_in`):
- VIP از طریق فیلتر `music_wave_guest_delivery_allowed` مقدار `'everyone' === delivery_access` را برمی‌گرداند.
- یعنی فقط اگر ادمین `everyone` را انتخاب کند، مهمان هم می‌تواند تحویل بگیرد.

### ۳.۵. عضویت configurable (VIP)

`music-wave-vip/src/ConfigurableMembershipProvider.php` — منابع:
- `role`: کاربر دارای نقش (پیش‌فرض `mw_vip`).
- `filter`: قابلیت override با فیلتر `music_wave_vip_membership_access`.
- `woocommerce_plans`: از طریق `VipPlans::from_settings()->user_has_access()` با **بازبینی زنده** (پس استرداد/ریفاند، دسترسی باطل می‌شود).
- `woocommerce_memberships`: پیشوند `plan-`.
- `woocommerce_subscriptions`: پیشوند `subscription-`.

متدها: `has_access()`، `has_any_active()`، `source_has_access()`.

---

## ۴. لایهٔ تحویل فایل (Delivery)

### ۴.۱. قابلیت‌های تحویل (`DeliveryCapabilities`)

`music-wave-core/src/Downloads/DeliveryCapabilities.php` — جایگزین پراکندهٔ `has_filter('music_wave_download_provider')`:

| حالت | معنا |
|---|---|
| `external_adapter` | یک ارائه‌دهندهٔ غیر-Null تزریق شده (VIP/third-party) |
| `direct_only` | فقط لینک‌های `direct:` در دسترس؛ حالت بازگشتی وقتی هیچ آداپتور خارجی نیست |

> `STATE_NONE = 'none'` در کلاس تعریف شده اما `state()` هرگز آن را برنمی‌گرداند. به همین دلیل `is_available()` عملاً همیشه `true` است و deny-by-default از مسیر دیگری می‌آید: `supports()` برای `vip:`/`local:` وقتی آداپتور خارجی `NullDownloadProvider` باشد `false` برمی‌گرداند و `DownloadResolver::issue()` بر همین اساس رد می‌کند.

`probe()` فیلتر `music_wave_download_provider` را اعمال می‌کند (پیش‌فرض `NullDownloadProvider`). `supports()`: شناسه‌های `direct:` همیشه؛ `vip:`/`local:` فقط وقتی ارائه‌دهندهٔ خارجی غیر-Null باشد. `state()` حافظه‌دار (memoized) است.

### ۴.۲. روتر آگاه از scheme (`DeliveryRouter`)

`music-wave-core/src/Downloads/DeliveryRouter.php`:
- `direct:<https-url>` → `DirectLinkProvider` (۳۰۲ با هدرهای امنیتی).
- بقیه (مثل `vip:` / `local:`) → ارائه‌دهندهٔ خارجی (VIP یا third-party).

### ۴.۳. ارائه‌دهندگان (Providers)

| ارائه‌دهنده | فایل | نقش |
|---|---|---|
| `NullDownloadProvider` | Core | deny-by-default |
| `DirectLinkProvider` | `music-wave-core/src/Downloads/DirectLinkProvider.php` | تحویل لینک مستقیم HTTPS با ۳۰۲ |
| `ProtectedFileProvider` | VIP | فایل خصوصی محلی، Range/206/416، X-Sendfile/X-Accel، تکه‌های ۱ مگابایتی |
| `RemoteRedirectProvider` | `music-wave-vip/src/RemoteRedirectProvider.php` | ساخت URL امضاشدهٔ کوتاه‌عمر روی میزبان راه‌دور و ۳۰۲ |

**`RemoteRedirectProvider`** (۲۴۵ خط):
- `redirect()` → `(new RedirectResponse($url))->send()`.
- `signed_url()`: payload امضا = `url|expires|mode|kid`، HMAC-SHA256، خروجی base64url.
- TTL: `max(30, min(900, remote_ttl))`، پیش‌فرض ۳۰۰ ثانیه.
- `harden_path()`؛ `effective_signing_secret()` با اولویت: `MUSIC_WAVE_VIP_REMOTE_SECRET` → کلید فعال چندگانه → کلید قدیمی (legacy).

**`RedirectResponse`** (`music-wave-core/src/Downloads/RedirectResponse.php`, ۸۹ خط) — نقطهٔ کلیدی امنیتی:
- هدرها: `Cache-Control: no-store, private`، `Referrer-Policy: no-referrer`، `X-Content-Type-Options: nosniff`، `X-Robots-Tag: noindex`.
- در محیط تست (`MUSIC_WAVE_TESTING`) ارسال شبیه‌سازی می‌شود؛ وگرنه `status_header` + هدرها + `Location: $url` + `exit`.
- **این همان کدی است که ثابت می‌کند یک ۳۰۲، میزبان مقصد را به کلاینت نشان می‌دهد.**

### ۴.۴. توکن‌ها، تیکت‌ها و claims

| قطعه | فایل | توضیح |
|---|---|---|
| `DownloadTokenService` | Core | امضای HMAC-SHA256 روی payload |
| `DownloadTokenClaims` | Core | کلیدهای payload: `r`/`u`/`e`/`j`/`b`/`a`/`p` (release/user/expiry/nonce/binding/asset/purpose) |
| `OpaqueTicketStore` | Core | تیکت مات `mwt_` (۱۶۰ بیت تصادفی)؛ transient + fallback به آپشن `mw_ticket_<sha256>`؛ هنگام خواندن **حذف نمی‌شود** |

- TTL در `DownloadResolver::ttl_for()`: استریم پیش‌فرض ۹۰۰ ثانیه (clamp ۶۰–۷۲۰۰)؛ دانلود پیش‌فرض ۳۰۰ ثانیه (clamp ۳۰–۳۶۰۰).
- **سقف مؤثر واقعی ۹۰۰ ثانیه است:** `DownloadTokenService::issue()` و `OpaqueTicketStore::wrap()` هر دو با `max(30, min($ttl, 900))` مقدار را دوباره محدود می‌کنند؛ پس فیلتر استریم با مقدار ۷۲۰۰ هم نمی‌تواند عمر توکن/تیکت را از ۹۰۰ ثانیه بیشتر کند. این دو clamp تکراری یک بدهی فنی است.
- TTL امضای راهدور: پیش‌فرض ۳۰۰ (clamp ۳۰–۹۰۰).
- اتصال به nonce وردپرس: `music_wave_download_<id>`.
- توکن دانلود **یک‌بارمصرف**؛ توکن استریم در طول عمر کوتاهش **قابل استفادهٔ مجدد** است (برای seek با Range)، اما تصمیم دسترسی در هر درخواست دوباره ارزیابی می‌شود.

### ۴.۵. ضد‌تکرار (Replay)، سهمیه و محدودیت نرخ

- **ضد تکرار:** جدول ایندکس‌دار `{prefix}mw_download_replays`؛ مصرف با یک `INSERT IGNORE` اتمیک؛ کرون روزانه `music_wave_replay_cleanup` رکوردهای منقضی را پاک می‌کند. اگر مهاجرت ۰٫۹٫۰ اجرا نشده باشد، به رفتار قبلی (آپشن‌محور) برمی‌گردد.
- **سهمیهٔ روزانه:** `music_wave_download_daily_quota` — **پیش‌فرض ۰ یعنی غیرفعال**. سهمیه **پیش از** مصرف replay بررسی می‌شود (`DownloadResolver::deliver`).
- **محدودیت نرخ:** `music-wave-core/src/Downloads/DownloadRateLimiter.php`:
  - `DEFAULT_LIMIT = 30`، `DEFAULT_WINDOW = 60`.
  - فیلترها: `music_wave_download_rate_limit` / `music_wave_download_rate_window`.
  - limit < 1 (فقط از طریق فیلتر) → غیرفعال؛ مقدار ذخیره‌شده در تنظیمات حداقل ۱ است، پس با UI نمی‌توان غیرفعال کرد.
  - window حداقل ۱۰ ثانیه (هم در مقدار ذخیره‌شده و هم پس از فیلتر).
  - پنجرهٔ ثابت: `mw_dl_rate_<user>_<floor(time/window)>`؛ سبد مهمان: `mw_dl_rate_g_<sha256(IP)>_<floor(time/window)>` (با `ClientIp::get()`).
  - تجاوز → `mw_download_rate_limited` (HTTP 429).

### ۴.۶. مسیرهای REST (Routes)

| متد | مسیر | فایل | نکته |
|---|---|---|---|
| POST | `/music-wave/v1/releases/{id}/download-token?quality=&purpose=` | `DownloadRoutes.php` | صدور توکن؛ rate-limit → nonce → resolver → نگاشت خطا |
| GET | `/music-wave/v1/downloads/{id}?token=&nonce=` | `DownloadRoutes.php` | تحویل دانلود؛ nonce + deliver → ۲۰۴ |
| GET | `/music-wave/v1/streams/{id}?token=&nonce=` | `DownloadRoutes.php` | پخش (Range)؛ همان ساختار، بدون سهمیه/بدون replay |
| GET | `/music-wave/v1/releases/{id}/playback-queue` | Core | صف پخش (`TrackStreamPresenter`) |
| GET/POST | `/music-wave/v1/releases/{id}/download-assets` | `DownloadAssetRoutes.php` | فقط ویرایشگر؛ مدیریت دارایی‌های دانلود |
| GET/POST | `/music-wave/v1/protected-assets` | VIP | فقط ویرایشگر؛ فهرست/بارگذاری دارایی محافظت‌شده |

`DownloadRoutes.php` (۲۰۹ خط): `may_request`، `issue` (rate-limit → nonce → resolver → error mapping)، `download` (nonce + deliver → 204)، `stream` (همان ساختار).

`DownloadResolver.php` (۳۳۱ خط): `can_request`، `ttl_for`، `issue`، `deliver` (سهمیه قبل از مصرف replay + `count_delivery` + audit)، `stream` (بدون سهمیه و بدون replay).

`DownloadAssetRoutes.php` (۳۳۹ خط): `MAX_ASSETS = 20`؛ `can_edit()` = `edit_post` یا `edit_mw_releases`/`manage_options`؛ `update()` پس از نرمال‌سازی `secure_direct_link_assets()` را صدا می‌زند:
- رد ID خام `http/https` با خطای `mw_raw_url_not_allowed` (400).
- اعتبارسنجی `direct:` با `DirectLinkUrlValidator` → خطای `mw_direct_link_invalid` (400).
- نیاز به `DirectLinkPermission::can_assign()` → خطای `mw_direct_link_forbidden` (403).
- `deny_unauthorized_assignments()`: فیلتر `music_wave_can_assign_download_asset` سپس fallback به `manage_mw_protected_assets`/`manage_options` → خطای `mw_download_asset_forbidden` (403).
- ذخیرهٔ `mw_download_assets` و حذف `mw_download_asset_id` قدیمی.

### ۴.۷. لینک مستقیم (`direct:`) و اعتبارسنجی

- **`DirectLinkAsset`** (`music-wave-core/src/Downloads/DirectLinkAsset.php`): `PREFIX = 'direct:'`؛ `is()`، `encode()` (اعتبارسنجی با validator)، `decode()`، `url()`، `from_url()`.
- **هرگز URL خام ذخیره نمی‌شود.**
- **`DirectLinkUrlValidator`** (`music-wave-core/src/Downloads/DirectLinkUrlValidator.php`، ۱۹۴ خط، `MAX_LENGTH = 2048`):
  - رد کاراکترهای کنترلی `[\x00-\x1F\x7F]`، فاصله، backslash.
  - فقط scheme = `https`.
  - هاست اجباری و lowercase؛ رد userinfo؛ پورت فقط خالی یا ۴۴۳.
  - هاست فقط ASCII/punycode.
  - مسدودسازی IPv4 dotted/`[IPv6]`/decimal/`0x`/octal، `localhost`، تک‌برچسبی، `.local`/`.internal`/`.localhost`.
  - allowlist اختیاری با پیشوند `*.` (self-suffix + `<sub>.suffix`؛ `suffix.evil.net` را رد می‌کند).
  - حذف fragment.
  - `rebuild_url()` **کد‌گذاری درصدی را حفظ می‌کند** (هرگز دوبار encode نمی‌کند).
- **`AssetIdSanitizer`** (`music-wave-core/src/Downloads/AssetIdSanitizer.php`، ۴۳ خط): `sanitize()` — اگر `direct:` باشد **بدون تغییر** برمی‌گرداند (حفظ percent-encoding)؛ وگرنه `sanitize_text_field()`.

### ۴.۸. پخش پیش‌رونده (Range/Streaming)

- `ProtectedFileProvider` از تک‌Range پشتیبانی می‌کند: `Accept-Ranges`، ۲۰۶، ۴۱۶، تکه‌های ۱ مگابایتی، `X-Accel-Buffering: no`.
- بلوک پخش از `<audio preload="metadata">` استفاده می‌کند (`PreviewPlayer::render_global_player()`).
- **در کل مخزن HLS/DASH/MSE/ترنسکد وجود ندارد.** یعنی پخش «تکه‌ای تطبیقی» (ABR) و کیفیت‌های چندگانهٔ جریانی وجود ندارد؛ فقط Range روی یک فایل واحد.

---

## ۵. جزئیات افزونهٔ VIP (فایل و موجودی)

### ۵.۱. رجیستری دارایی محافظت‌شده (`ProtectedAssetRegistry`)

`music-wave-vip/src/ProtectedAssetRegistry.php` (۲۳۳ خط)، جدول `mw_vip_assets`، پیشوند شناسه `vip:`.

اسکیمای جدول (`ensure_table()` با SHOW TABLES وگرنه `dbDelta`):

| ستون | نوع |
|---|---|
| `asset_key` | `char(32)` PRIMARY KEY |
| `path_hash` | `char(64)` UNIQUE |
| `relative_path` | `text` |
| `file_size` | `bigint(20) unsigned` |
| `checksum` | `char(64) DEFAULT ''` |
| `mime` | `varchar(100) DEFAULT ''` |
| `registered_at` | `bigint(20) unsigned` |

- `register()`: idempotent بر اساس `path_hash = sha256(relative)`؛ در صورت تغییر اندازه، اثر انگشت را تازه می‌کند؛ کلید تصادفی ۱۶ بایتی (۳۲ hex) می‌سازد.
- `find()` فقط `/^[a-f0-9]{32}$/` را می‌پذیرد (نامعتبر → fail closed).
- `mime_type()`: نقشهٔ mp3/m4a/aac/ogg/wav/flac/zip.

### ۵.۲. مخزن/ذخیره‌سازی (`ProtectedAssetStorage`)

`music-wave-vip/src/ProtectedAssetStorage.php` (۵۰۵ خط) — مرکب از `ProtectedAssetRegistry` + `Storage\RootResolver`/`PathValidator`/`MimeValidator`.

- `root()` / `is_web_reachable()` / `preflight()` / `harden_directory()` / `provision()`.
- `sync_inventory()`: فایل‌های deny، پسوند غیرمجاز و لینک‌ها را رد می‌کند؛ برمی‌گرداند: scanned/registered/total_bytes.
- `stats()`: اول دیتابیس، بعد فایل‌سیستم.
- `delete_asset()`: ایمن داخل root، هم `local:` و هم `vip:`.
- `resolve()`: برای `vip:` به `resolve_registered()` واگذار می‌کند؛ برای `local:` (legacy) فقط ایمنی مسیر نسبی، `realpath` داخل root، `is_file` و خوانا بودن را بررسی می‌کند — **بدون** مقایسهٔ اندازه یا checksum.
- `resolve_registered()` (فقط `vip:`): افزون بر مسیر امن، `filesize` را با `file_size` ثبت‌شده مقایسه و در صورت غیرخالی بودن `checksum`، SHA-256 را با `hash_equals` بازبینی می‌کند → **fail closed**. MIME در این مسیر دوباره بازبینی نمی‌شود؛ اعتبارسنجی MIME فقط هنگام `upload()`/`sync_inventory()` انجام می‌شود.
- `list_assets()` صفحه‌بندی‌شده (ثبت شناسه‌های مات).
- `upload()`: اعتبارسنجی خطا/tmp/نام/پسوند/اندازه در برابر `wp_max_upload_size`، MIME با finfo، حالت ویژهٔ zip، رد `\0` و نام‌های `.php.|.phtml.|.phar.|.exe.|.sh.`، `wp_unique_filename`، `move_uploaded_file`، ثبت.
- `import_attachment()`: کپی از uploads داخل ریشهٔ uploads.
- `audio_metadata()`: کش ۱۲ ساعته، `wp_read_audio_metadata`.
- `allowed_extensions()` از طریق MimeValidator.

### ۵.۳. تنظیمات و ساختار افزونه

- `music-wave-vip/src/Plugin.php` (۴۸ خط): `MIN_CORE_VERSION = '0.9.0'`، `core_meets_minimum()`.
- `music-wave-vip/src/ProviderFactory.php` (۴۲ خط): ساخت provider بر اساس `delivery_provider`.
- `music-wave-vip/music-wave-vip.php` (۳۸۲ خط): ثابت‌ها `MUSIC_WAVE_VIP_VERSION = '0.5.2'`، `MUSIC_WAVE_VIP_MIN_CORE_VERSION = '0.16.0'`، `MUSIC_WAVE_VIP_FILE`.
- پوشهٔ `music-wave-vip/src/`: `Plans\VipPlanCatalog`، `Admin\VipSettingsUi`، `Delivery\ProtectedDeliveryDiagnostics`، `Storage\{RootResolver, PathValidator, MimeValidator}`.

### ۵.۴. مجوزها (Entitlements) و WooCommerce

- دارایی‌های خرید با **بازبینی زنده**: سفارش استردادشده، عضویت منقضی یا مجوز دستی باطل‌شده نمی‌تواند صرفاً چون قبلاً در کتابخانه نمایش داده شده ادامه دهد.
- کتابخانهٔ «My music library» (My Account ووکامرس): فقط انتشارهایی که تصمیم دسترسی فعلی را رد کنند و حداقل یک دارایی محافظت‌شده داشته باشند.
- نسخه‌های کیفیت چندگانه: مشتری برچسبی مثل `MP3 320 kbps` یا `FLAC lossless` را انتخاب می‌کند؛ همان مسیر صدور توکن استفاده می‌شود.

---

## ۶. کاتالوگ کاربرپسند دارایی‌ها

`music-wave-core/src/Downloads/ReleaseDownloadCatalog.php` (۱۲۴ خط):
- `STREAMABLE_FORMATS = ['mp3','m4a','aac','ogg','wav','flac']`.
- `is_asset_streamable()`: اول از کلید `format`؛ برای `direct:` از پسوند مسیر URL (query حذف می‌شود)؛ وگرنه `pathinfo(asset_id)`.
- `assets()`: می‌خواند `mw_download_assets` (+ legacy `mw_download_asset_id` → کلید `standard`، برچسب «دانلود استاندارد») و برمی‌گرداند key/label/file_key/file_label/streamable **بدون شناسه‌های ذخیره‌سازی خصوصی**.
- `group_files()`: گروه‌بندی بر اساس `file_key` (پیش‌فرض `main-download`، برچسب «دانلود اصلی»).

---

## ۷. ارائهٔ پخش و صف (Presenter)

`music-wave-core/src/Playback/TrackStreamPresenter.php` (۴۴۲ خط):
- `MAX_EXPANSION_DEPTH = 3`.
- `expand_release_tracks()`: بسط بازگشتی با محافظت چرخه/حد.
- انتشار چندفایلی: یک ورودی صف به‌ازای هر گروه فایل قابل استریم (`fileKey`، `quality`، `format_file_track_title()`).
- `track_payload()`: `releaseId`/`rootId`/`title`/`artist`/`image`/`link`/`previewUrl`/`previewLimit`/`full`/`quality`/`lyricsLrc`/`lyricsOffset`/`hasLyrics`.
- `is_valid_preview_url()`: هر https؛ http فقط هم‌میزبان وقتی `home_url` خودش http باشد.
- `preview_limit()`: clamp ۱۰–۱۲۰، پیش‌فرض ۳۰.
- `upsell_payload()`: پیام خرید/عضویت/محدود + URL ورود مهمان.
- `default_membership_cta_url()`: فیلتر `music_wave_membership_cta_url_fallback` → my-account ووکامرس → `home_url('/account/')`.

---

## ۸. فرانت‌اند: جریان دقیق پخش و دانلود

### ۸.۱. ورودی دکمهٔ پخش

- `music-wave-core/src/Blocks/PreviewPlayer.php` (۴۴۶ خط): بلوک `music-wave/preview-button`.
- `enqueue_assets()`: `assets/runtime.js` + `assets/preview-player.js` (+ `lyrics.js` قالب) و localize `musicWavePreviewPlayer` با `{restUrl, restNonce: wp_create_nonce('wp_rest'), loggedIn, listeningEnabled, persistentNav, labels{...}}`.
- `card_play_button()`: `<button class="... mw-card-play" data-mw-release-id="...">`.
- `button_markup()`: `data-preview-url` + `data-preview-limit`.
- `render_global_player()`: پخش‌کنندهٔ پایدار با `<audio preload="metadata">`، صف/متن/نوتیس/کنترل‌ها/تایم‌لاین/صدا/بستن، آیکون‌های SVG درون‌خطی.

### ۸.۲. جریان پخش (progressive) در `preview-player.js`

۱. ساخت هدر nonce (خطوط ۲۵۹–۲۶۹): `X-WP-Nonce` از `cfg.restNonce` یا منابع دیگر.
۲. دریافت صف: `GET /music-wave/v1/releases/{id}/playback-queue` (خط ۲۷۵–۲۷۹) با `restHeaders()`.
۳. صدور توکن استریم (خط ۳۰۵–۳۱۶):
   ```
   POST /music-wave/v1/releases/{releaseId}/download-token?quality=<q>&purpose=stream
   ```
۴. ساخت URL استریم (خط ۳۱۸–۳۳۵):
   ```
   {restUrl}music-wave/v1/streams/{releaseId}?token=<token>&nonce=<nonce>[&_wpnonce=<rest_nonce>]
   ```
۵. ست‌کردن `audio.src` به این URL → پخش پیش‌رونده با Range و seek (استفادهٔ گسترده از `audio.currentTime` خطوط ۴۴۱، ۸۹۸، ۹۲۲، ۱۱۹۷، ۱۲۳۰–۱۲۶۵، ۱۴۵۶–۱۴۵۹، ۱۹۵۱–۲۲۶۰).
- play button یک `mw-card-play` با `data-mw-release-id` است.
- تابع `streamUrl()` مسیر `/music-wave/v1/streams/` را می‌سازد، **نه** آدرس فایل مستر.

### ۸.۳. جریان دانلود در `download.js` (۲۶۸ خط)

۱. `requestToken()` (خط ۷۳–۹۳): `POST /music-wave/v1/releases/{id}/download-token?quality=&purpose=download` با هدر `X-WP-Nonce`.
۲. روی کلیک دکمهٔ `.mw-download-button` (خط ۱۸۰–۲۲۴): غیرفعال‌سازی دکمه، `aria-busy`، پاک‌کردن وضعیت، سپس `requestToken(..., 'download')`.
۳. `redemptionUrl()` (خط ۵۹–۷۱):
   ```
   {restUrl}music-wave/v1/downloads/{releaseId}?token=<token>&nonce=<nonce>[&_wpnonce=<rest_nonce>]
   ```
۴. `window.location.assign(redemptionUrl(...))` — یعنی مرورگر به مسیر تحویل هدایت می‌شود.
۵. `setPlayLabel()` متن و `aria-label` را با هم جابه‌جا می‌کند (هرگز ناهم‌خوانی دسترس‌پذیری).
۶. `selectedQuality()` / `rowFor()` / `syncDownloadRows()`: هم‌گام‌سازی وضعیت دکمهٔ پخش بر اساس `data-streamable` گزینهٔ کیفیت.

### ۸.۴. بلوک‌ها

- `music-wave/download-button`: سه صفت برچسب — `downloadLabel` (کلاس `.mw-download-button__label`)، `playLabel` (`.mw-secure-play-button__label`)، `loginLabel` (فقط opt-in، وقتی کاربر خارج‌شده، تصمیم deny و انتشار دارای دارایی دانلود باشد).
- هر دو دکمه توسط هلپرهای مشترک `secure_play_button()` / `secure_download_button()` ساخته می‌شوند.
- بلوک‌های مرتبط: `music-wave/access-panel`، `CollectionAndDownloadRenderer`، `PlaylistBlocks`، `QueueBlock`، `ListeningBlocks`.

---

## ۹. جدول‌های دیتابیس و گزینه‌ها

| نام | نوع | کاربرد |
|---|---|---|
| `{prefix}mw_download_replays` | جدول | نشانگر یک‌بارمصرف (ضد‌تکرار)؛ ایندکس‌دار |
| `{prefix}mw_vip_assets` | جدول | موجودی مات دارایی محافظت‌شده (`vip:<32hex>`) |
| `music_wave_settings` | option | تنظیمات هسته |
| `music_wave_vip_settings` | option | تنظیمات VIP |
| `music_wave_vip_protected_root` | option | legacy ریشهٔ محافظت‌شده |
| `mw_ticket_<sha256>` | option | fallback مخزن تیکت مات |
| `mw_dl_rate_*` | transient | پنجرهٔ ثابت محدودیت نرخ |
| `mw_download_*` / `mw_vip_*` | meta | دارایی‌ها و سطح VIP روی انتشار |

**کرون:** `music_wave_replay_cleanup` (روزانه، پاکسازی رکوردهای منقضی و آپشن‌های legacy).

---

## ۱۰. فیلترها و اکشن‌های کلیدی

| نام | نوع | محل / نقش |
|---|---|---|
| `music_wave_download_provider` | filter | تزریق ارائه‌دهندهٔ تحویل (VIP/third-party)؛ پیش‌فرض `NullDownloadProvider` |
| `music_wave_vip_download_provider` | filter | VIP: جایگزینی provider ساخته‌شده (`$provider, $config, $storage`) |
| `music_wave_vip_membership_provider` | filter | VIP: جایگزینی provider عضویت (`$provider, $config`) |
| `music_wave_download_event` | action | رویدادهای تحویل/استریم؛ پارامترها: `$event, $release_id, $user_id, $context`. شش مقدار واقعی: `token_issued`, `token_denied`, `download_delivered`, `download_denied`, `stream_delivered`, `stream_denied`. `$context` = `timestamp` (همیشه) + `reason` (روی deny) یا `asset_key`/`purpose` (روی issuance) |
| `music_wave_download_rate_limit` | filter | حد نرخ (`$limit, $user_id`) |
| `music_wave_download_rate_window` | filter | پنجرهٔ نرخ (`$window, $user_id`) |
| `music_wave_download_daily_quota` | filter | سهمیهٔ روزانه (پیش‌فرض ۰ = خاموش) |
| `music_wave_stream_token_ttl` | filter | TTL توکن استریم؛ در نهایت با سقف ۹۰۰ ثانیه محدود می‌شود |
| `music_wave_download_token_ttl` | filter | TTL توکن دانلود؛ در نهایت با سقف ۹۰۰ ثانیه محدود می‌شود |
| `music_wave_client_ip` | filter | IP مؤثر کلاینت (`$ip, $raw`)؛ برای CDN/proxy و bucket مهمان |
| `music_wave_guest_delivery_allowed` | filter | VIP `'everyone' === delivery_access` |
| `music_wave_access_decision` | filter | بازبینی/override تصمیم دسترسی (`$decision, $release_id, $subject`) |
| `music_wave_membership_absent_behavior` | filter | deny/allow/open_download |
| `music_wave_vip_membership_access` | filter | override دسترسی عضویت |
| `music_wave_vip_remote_download_url` | filter | override URL راهدور (توسط allowlist محدود می‌شود) |
| `music_wave_vip_signing_key_rotated` | action | پس از چرخش کلید (`$new_kid, $current`) |
| `music_wave_can_assign_download_asset` | filter | اجازهٔ تخصیص دارایی |
| `music_wave_membership_cta_url_fallback` | filter | URL CTA عضویت |

---

## ۱۱. موجودی کنترل‌های امنیتی

- **ذخیره‌سازی:** `mw_download_asset_id` یا شناسهٔ کیفیت هرگز به‌عنوان URL افشا نمی‌شود؛ شناسهٔ مات `vip:<key>` جای مسیر افشاکننده `local:` را گرفته (legacy `local:` هنوز deprecated-resolve می‌شود).
- **توکن:** HMAC-SHA256 روی کل payload؛ اتصال release/user/quality/nonce؛ انقضا؛ یک‌بارمصرفی دانلود؛ بازبینی مجدد دسترسی در هر درخواست.
- **تیکت:** فقط یک تیکت `mwt_` ۱۶۰ بیتی به مرورگر می‌رسد؛ claims امضاشده هرگز سرور را ترک نمی‌کنند.
- **ریدایرکت:** HTTPS + allowlist هاست که **بعد از همهٔ فیلترها** اعمال می‌شود؛ امضای payload کامل `url|expires|mode|kid` (حالت تحویل قابل دست‌کاری مستقل نیست).
- **کلید:** حداقل ۳۲ کاراکتر وگرنه fail-closed؛ چرخش با `kid`؛ `MUSIC_WAVE_VIP_REMOTE_SECRET` اولویت دارد.
- **یکپارچگی فایل (فقط دارایی‌های `vip:` در تحویل محلی):** `ProtectedFileProvider` فایل را با `resolve()` می‌گیرد که برای `vip:` به `resolve_registered()` می‌رسد؛ آنجا اندازه با `file_size` ثبت‌شده و در صورت وجود، SHA-256 با `hash_equals` بازبینی می‌شود؛ فایل جایگزین/بریده → fail closed. **این کنترل روی `local:` (legacy) اعمال نمی‌شود** و آن مسیر فقط ایمنی مسیر/خوانا بودن را بررسی می‌کند. `RemoteRedirectProvider` هیچ بازبینی مجددی روی فایل مقصد انجام نمی‌دهد؛ اعتبار فایل در سرور راهدور مسئولیت همان سرور است.
- **هدرها:** `private, no-store`، `no-referrer`، `nosniff`، `X-Robots-Tag: noindex`.
- **ریشهٔ خصوصی:** باید خارج از ABSPATH و `DOCUMENT_ROOT` باشد؛ فایل‌های deny (`.htaccess`، `web.config`، `index.html`) به‌عنوان لایهٔ دوم.
- **آپلود:** اعتبارسنجی پسوند/MIME/اندازه، رد نام‌های اجرایی، `wp_unique_filename`، خروج از public root.
- **آفلات ورکر PHP:** X-Sendfile / X-Accel-Redirect.
- **ضد سوءاستفاده:** محدودیت نرخ ۳۰/۶۰ (۴۲۹) + سهمیهٔ روزانهٔ اختیاری.

### ۱۱.۱. جدول «حمله → کنترل → پوشش» (خلاصهٔ threat model)

| حمله | کنترل |
|---|---|
| دسترسی وب مستقیم به مستر | ریشهٔ خصوصی خارج از web root + فایل‌های deny |
| حدس مسیر/شناسه | شناسهٔ مات ۱۲۸ بیتی؛ نامعتبر → fail closed |
| حدس تیکت | فقط `mwt_` ۱۶۰ بیتی |
| تکرار توکن | `mw_download_replays` + `INSERT IGNORE` اتمیک |
| دست‌کاری توکن | HMAC روی کل payload + اتصال nonce/expiry/purpose/user |
| انتقال بین کاربران | اتصال به user ID + nonce و بازبینی دسترسی |
| دست‌کاری حالت/پارامتر URL راه‌دور | امضای payload کامل |
| ریدایرکت ناایمن | HTTPS + allowlist صریح هاست |
| کلید ضعیف/چرخش | fail-closed حداقل ۳۲ کاراکتر + `kid` |
| مستر جایگزین/بریده (دارایی `vip:`) | بازبینی اندازه/هش در `resolve_registered()`؛ `local:` legacy این کنترل را ندارد و حالت راهدور هم ندارد |
| سیل صدور توکن | محدودیت نرخ کاربر (۴۲۹) |
| استخراج انبوه با حساب آلوده | سهمیهٔ روزانه (پیش‌فرض خاموش) |
| اشباع ورکر PHP | X-Sendfile/X-Accel |

**تست بار** (صدور هم‌زمان تیکت + تحویل + رقابت replay) به مرحلهٔ ۷ موکول شده است.

---

## ۱۲. جریان‌های سرتاسری (End-to-End)

### ۱۲.۱. دریافت فایل توسط کاربر — حالت محلی (`delivery_provider = local`)

۱. کاربر کلیک دانلود → `POST /releases/{id}/download-token?quality=…&purpose=download` (با `X-WP-Nonce`).
۲. Core: محدودیت نرخ → nonce → `AccessPolicyEngine` → `DownloadResolver::issue` → توکن امضاشدهٔ یک‌بارمصرف + تیکت `mwt_`.
۳. مرورگر به `GET /downloads/{id}?token=…&nonce=…` می‌رود.
۴. Core: HMAC، انقضا، اتصال، تصمیم دسترسی مجدد، سهمیه (اختیاری)، مصرف replay → مسیر `vip:` → `ProtectedFileProvider`.
۵. `ProtectedFileProvider` فایل را با `Cache-Control: private, no-store` و … **خودش استریم می‌کند** (یا با X-Sendfile/X-Accel به وب‌سرور می‌سپارد). **آدرس فایل هرگز به کاربر نمی‌رسد.**

### ۱۲.۲. دریافت فایل — حالت راه‌دور (`delivery_provider = remote_redirect`)

۱. همان مراحل ۱–۴ بالا، اما تحویل به `RemoteRedirectProvider` می‌رسد.
۲. Provider URL کامل میزبان راه‌دور را با HMAC-SHA256 (payload `url|expires|mode|kid`) و TTL ≤ ۹۰۰ می‌سازد و **به کاربر ۳۰۲ می‌دهد**.
۳. **کاربر (مرورگر) میزبان راه‌دور را می‌بیند**؛ امضا فقط یکپارچگی و انقضا را تضمین می‌کند، نه پنهان‌سازی مبدأ. (مبنای کد: `RedirectResponse::send()` یک `Location:` می‌فرستد.)

### ۱۲.۳. پخش

۱. کلیک پخش → `GET /releases/{id}/playback-queue`.
۲. `POST /download-token?purpose=stream` → توکن استریم (TTL ۹۰۰، قابل استفادهٔ مجدد).
۳. `audio.src = /streams/{id}?token=…&nonce=…`.
۴. `ProtectedFileProvider` با Range/206 پاسخ می‌دهد → پخش پیش‌رونده و seek.

### ۱۲.۴. لینک مستقیم (`direct:`)

- تحویل به `DirectLinkProvider` می‌رسد که ۳۰۲ به URL https معتبر می‌زند (اعتبارسنجی‌شده و دوباره در لحظهٔ تحویل بازبینی‌شده).

---

## ۱۳. نقاط ضعف، بدهی و محدودیت‌های شناخته‌شده

۱. **افشای میزبان راهدور در حالت خارجی:** ذاتی ۳۰۲؛ `RedirectResponse::send()` هدر `Location` را می‌فرستد و مقصد برای کلاینت قابل مشاهده است.
۲. **لیچ در حالت محلی هم ممکن است:** `open_download` یا تصمیم‌های باز می‌توانند لینک/فایل را در دسترس بگذارند (که برای محتوای رایگان پذیرفتنی است).
۳. **بدون ABR/HLS/ترنسکد:** فقط Range روی فایل واحد → کیفیت تطبیقی (که در Spotify/YouTube Music هست) ندارد.
۴. **بدون DRM / واترمارک / اتصال IP/نشست:** طبق ADR ۰۰۰۵ صریحاً خارج از دامنه.
۵. **سهمیهٔ روزانه پیش‌فرض خاموش است.**
۶. **لینک مستقیم پس از تحویل قابل اشتراک و کپی است** (هشدار صریح در UI موجود است).
۷. **ناهمراستایی حداقل نسخهٔ هسته** (۰٫۱۶٫۰ در برابر ۰٫۹٫۰ در برابر ۰٫۱۱٫۰) — نیازمند یکسان‌سازی.
۸. **بدون تست بار مرحلهٔ ۷؛ برخی موارد wp-env هنوز تأییدنشده.**
۹. **سرور محلی می‌تواند فایل را از مسیر اشتباه یا از `DOCUMENT_ROOT` ارائه دهد اگر preflight نادیده گرفته شود.**
۱۰. **هشدار مهم دربارهٔ checksum کهنه:** `ProtectedAssetRegistry::register()` فقط وقتی `file_size` تغییر کرده باشد checksum را دوباره محاسبه می‌کند. اگر فایل با همان اندازه جایگزین شود، اثر انگشت قدیمی باقی می‌ماند و `resolve_registered()` به‌صورت fail-closed رد می‌کند؛ اما هیچ مسیر خودکار برای تازه‌سازی آن وجود ندارد. راه‌حل فعلی: حذف/ثبت مجدد دارایی یا همگام‌سازی پس از تغییر محتوا. این باید در ارتقاء اصلاح شود (مقایسهٔ `stat`/mtime یا محاسبهٔ مجدد در sync).

---

## ۱۴. نقاط تغییر برای ارتقاء (Change Points)

| هدف | فایل/کلاس نقطهٔ تغییر |
|---|---|
| ماسک‌کردن مبدأ (نمایش دامنهٔ سایت/CDN) | معماری: CDN با hostname سفارشی (CNAME) یا Worker پروکسی؛ `ProviderFactory` + حالت جدید «edge/proxy» |
| اتصال به نشست کاربر (عدم اشتراک) | `DownloadTokenService`/`DownloadTokenClaims`، `DownloadResolver::stream/deliver`، کوکی امضاشدهٔ HttpOnly + `StreamSessionStore` جدید |
| HLS (نردبان ثابت کیفیت) و پیش‌بارگذاری | `preview-player.js` (hls.js)، مسیرهای `manifest/segment/key/heartbeat` جدید |
| DRM / واترمارک | لایهٔ جدید ترنسکد + provider ویژه |
| مدل «مدیریت سرور خارجی» | `SettingsPage` (تب تحویل)، `VipSettings`، `Delivery\ProtectedDeliveryDiagnostics` |
| یکسان‌سازی حداقل نسخه | `music-wave-vip.php`، `src/Plugin.php`، `readme.txt` |
| ADR پوشش ارتقاء | `docs/adr/0006-*.md` |

---

## ۱۵. مراجع داخلی مرتبط

- `docs/secure-downloads.md` — روایت کامل جریان تحویل و سخت‌سازی مرحلهٔ ۲.
- `docs/delivery-threat-model.md` — جدول حمله/کنترل/پوشش.
- `docs/adr/0005-membership-absence-policy-and-direct-delivery.md` — تصمیم ADR در مورد دو لایه و `direct:`.
- `docs/access-policy.md`, `docs/vip-integration.md`, `docs/download-qualities-and-hosting.md`, `docs/extension-contracts.md`, `docs/data-dictionary.md`, `docs/compatibility-matrix.md`.

---

## پیوست: پاسخ کوتاه به سوالات کلیدی

1. **پنهان‌کردن دامنه/IP سرور خارجی از کاربر؟** در حالت ۳۰۲ «هرگز» نه — کاربر مقصد را می‌بیند. برای پنهان‌سازی، بایت‌ها باید از دامنهٔ خودتان/لبه (CDN با hostname سفارشی یا Worker پروکسی) عبور کنند.
2. **آدرس یکتا و غیرقابل‌لو‌رفتن برای هر کاربر؟** بله؛ با «نشست پخش» سمت سرور متصل به user+IP+UA+CSRF و کوکی امضاشدهٔ HttpOnly به‌جای توکن در URL، به‌علاوهٔ شناسه‌های مات رجیستری.
3. **رفتار شبیه سرور محلی (عدم کپی/ارسال فایل)؟** بله، به‌شرط ندادن هیچ لینک دائمی + تکه‌بندی/HLS + اتصال نشست؛ DRM فقط برای هم‌ترازی با پلتفرم‌های بزرگ لازم است.
4. **پر کردن همهٔ شکاف‌ها نسبت به Spotify/YouTube Music/Apple Music؟** بله در سطح «سخت‌سازی تحویل» (۳ ستون: ماسک مبدأ، نشست کاربرمحور، پخش تکه‌ای). DRM/ABR پیشرفته/واترمارک برای هم‌ترازی کامل لازم است.
5. **مدیریت سرور خارجی از داخل تنظیمات VIP؟** بله؛ می‌توان تب تحویل را به یک «مدیر اتصال/لبه» تبدیل کرد که اتصال را پیکربندی و اعتبارسنجی کند (مهلت HMAC، پیش‌بررسی اتصال، مسیر تکه‌ها/کلید) — نه فقط ذخیرهٔ یک base URL.
