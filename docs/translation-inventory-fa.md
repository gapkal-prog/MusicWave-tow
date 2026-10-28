# فهرست و راهبرد فارسی‌سازی محصول

این سند مرز بین متن قابل‌نمایش و دادهٔ فنی را برای فارسی‌سازی پایهٔ MusicWave ثبت می‌کند. زبان source محصول فارسی است و نسخهٔ انگلیسی فقط از کاتالوگ‌های WordPress با locale `en_US` می‌آید.

## وضعیت فهرست در ۳۰ اوت ۲۰۲۶

| بسته | text domain | ورودی‌های POT | PHP | JavaScript | metadata بلوک/قالب | وضعیت source فارسی | وضعیت `en_US` |
|---|---|---:|---:|---:|---:|---|---|
| Theme | `musicwave` | ۳۱۸ | ۱۴۸ | ۱۰۶ | ۷۰ | انجام شده | PO/MO و ۳ JSON هش‌شده آماده و بررسی شده |
| Core | `music-wave-core` | ۱٬۳۲۰ | ۸۸۷ | ۴۵۵ | ۱۱۴ | انجام شده | PO/MO و ۳ JSON هش‌شده آماده و بررسی شده |
| VIP | `music-wave-vip` | ۲۱۶ | ۲۱۵ | ۰ | ۰ | انجام شده | PO/MO آماده و بررسی شده |

تعداد ورودی‌های POT/PO با احتساب header گزارش شده‌اند. تعدادهای PHP و JavaScript بر اساس رشته‌هایی است که در referenceهای POT دیده می‌شوند؛ یک رشته ممکن است چند reference داشته باشد و دسته‌ها نیز ممکن است هم‌پوشانی داشته باشند. تعداد metadata شامل `block.json`، عنوان‌های `theme.json` و metadataهایی است که در catalog همان بسته ثبت شده‌اند.

## مرزهای ترجمه

### باید به فارسی طبیعی تبدیل شوند

- متن‌های عمومی، عنوان‌ها، labelها، توضیحات، پیام‌های خطا و موفقیت، راهنمای فیلدها و اعلان‌های accessibility.
- عنوان و description و keywordهای قابل‌نمایش بلوک‌ها در Inserter و Site Editor.
- متن‌های پیش‌فرض Starter Content، Template، Template Part و Pattern.
- پیام‌های JavaScript و labelهای پنل تنظیمات؛ ترجمه باید از `wp.i18n` عبور کند.
- پیام‌های دارای placeholder و plural با حفظ دقیق `%s`، `%1$d`، `%2$s` و شکل plural مناسب فارسی/انگلیسی.

### نباید ترجمه یا rename شوند

- نام تابع، کلاس، namespace، constant، text domain و block name/slug.
- نام CPT و taxonomy و slug آن‌ها، meta key، option key، REST namespace/route، query variable و CSS class.
- مقادیر داخلی API مانند `views`، `mw_views`، `mw_release_type`، `ASC` و `DESC`.
- شناسهٔ فایل، token دانلود، provider asset ID، membership level slug و اطلاعاتی که کاربر وارد کرده است؛ از جمله نام هنرمند، عنوان انتشار و نام Playlist.

## فهرست فایل‌های اصلی تحت پوشش

### Core

- `src/Admin/SettingsPage.php`: بزرگ‌ترین سطح PHP؛ صفحهٔ تنظیمات، labelها، توضیحات امنیتی و پیام‌های عملیات.
- `src/Admin/DiagnosticsPage.php`: راه‌اندازی، Site Health، import و پیام‌های وضعیت.
- `src/Blocks/PlaylistBlocks.php` و `src/Blocks/ReleaseBlocks.php`: متن‌های runtime، فیلترها، accessibility و pluralها.
- `src/Modules/Rendering.php`: labelهای بلوک‌های dynamic و متن‌های پیش‌فرض render.
- `src/Commerce/AccountLibrary.php`: حساب کاربری، کتابخانه، سفارش و دسترسی.
- `src/Metadata/MetadataResolver.php`: متن‌های توصیفی metadata؛ دادهٔ واردشدهٔ هنرمند/عنوان ترجمه نمی‌شود.
- `assets/blocks.js`: پنل‌های Site Editor و labelهای JavaScript؛ JSON آن باید با hash مسیر `assets/blocks.js` تولید شود.
- `assets/editor.js`: editor meta-box و labelهای JavaScript؛ JSON آن باید با hash مسیر `assets/editor.js` تولید شود.
- `assets/metadata-lookup.js`: برچسب‌های اولیه از PHP با `wp_localize_script` می‌آیند و fallbackهای JS نیز باید فارسی و هم‌تراز با همان catalog بمانند؛ اگر متن جدید در JS اضافه شود، `wp.i18n` و JSON همان handle را تکمیل کنید.
- `blocks/*/block.json`: metadata قابل‌نمایش؛ block name، attribute key و valueهای فنی ثابت می‌مانند.

### VIP

- `src/SettingsPage.php`: کل ۲۱۵ ورودی فعلی؛ راهنمای مبتدی، تنظیمات storage، membership، redirect و diagnostics.
- `src/PlanProducts.php`: نام planهای پیش‌فرض، پیام‌های مدیریت و plural مدت‌زمان.
- `src/ProtectedAssetStorage.php`: خطاهای مسیر، MIME، ZIP و فایل؛ کدهای خطای فنی ثابت می‌مانند.
- `src/Plugin.php` و `src/VipPlans.php`: اعلان وابستگی و پیام‌های plan.

## راهبرد اجرایی

1. sourceهای PHP/JS و metadata هر بسته جداگانه، با map صریح `English → Persian` و بدون replacement سراسری تغییر می‌کنند.
2. پس از هر بسته، POT بازسازی می‌شود؛ collisionهای singular/plural باید با context یا mergeٔ reference حل شوند.
3. فایل `domain-fa_IR.po` برای اطمینان از فارسی‌بودن source نگهداری نمی‌شود؛ source فارسی است و فایل‌های تحویلی مستقل فقط `domain-en_US.po/.mo` هستند.
4. برای هر script دارای `wp.i18n`، `wp_set_script_translations()` با مسیر صریح `languages` و JSON با الگوی `domain-locale-md5(relative-script-path).json` ثبت می‌شود.
5. محتوای FSE که باید با تغییر locale عوض شود، literal ثابت در HTML نیست؛ از `music-wave/theme-text` یا الگوی language-aware استفاده می‌کند. محتوای ویرایش‌شدهٔ merchant هرگز خودکار ترجمه یا بازنویسی نمی‌شود.
6. بعد از هر بسته، این gateها اجرا می‌شوند: `php -l`، PHPCS، PHPStan در صورت امکان، `node --check`، ESLint، `msgfmt`/`msgcmp`، integrity تست‌های FSE و smoke test.

## معیار پایان هر بسته

- هیچ متن قابل‌نمایش انگلیسیِ source در scope بسته باقی نمانده باشد، مگر brand، دادهٔ فنی یا مقدار داخلی مستثناشده.
- تمام placeholderها، pluralها و contextها سالم باشند.
- PO و MO با POT دقیقاً همسان باشند.
- JSONهای JavaScript از مسیر نسبی واقعی asset hash شده باشند و در enqueue واقعی WordPress بارگذاری شوند.
- locale فارسی و `en_US` هر دو در runtime بررسی شوند؛ تست `en_US` نباید به «داشتن فایل» محدود شود.
- خروجی RTL، اعداد، تاریخ، فاصله‌گذاری و سازگاری WooCommerce بدون regression باقی بماند.
