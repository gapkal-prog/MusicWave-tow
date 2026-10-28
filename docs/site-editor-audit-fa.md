# گزارش ممیزی و برنامه ارتقای WordPress Site Editor در MusicWave

**تاریخ ممیزی:** ۳۰ اوت ۲۰۲۶  
**مخزن بررسی‌شده:** `https://github.com/gapkal-prog/MusicWave-project`  
**commit مبنا:** `b82d045` (`main`)  
**دامنه:** قالب `musicwave`، افزونه `music-wave-core`، افزونه `music-wave-vip` و تست‌ها/ابزارهای مرتبط با Gutenberg و Full Site Editing

## ۱. جمع‌بندی اجرایی

MusicWave از نظر جهت معماری، یک محصول WordPress مدرن است: قالب Block Theme دارد، از `theme.json` نسخه ۳ استفاده می‌کند، Template و Template Partهای فایل‌محور دارد، Patternهای قابل درج ارائه می‌دهد و Blockهای داینامیک را در افزونه Core نگه می‌دارد. منطق دامنه و امنیت نیز در قالب قرار نگرفته و مرز Theme/Plugin تا حد زیادی رعایت شده است.

مسئله اصلی پروژه کمبود قابلیت نیست؛ بلکه **همگام‌سازی و نگهداری‌پذیری سطح ارائه** است. قبل از این مرحله چند مسیر موازی برای ثبت Blockها، متن‌های ویرایشگر، کارت Release، Footer و وضعیت Templateها وجود داشت. این حالت در محصولی که قرار است در مارکت فروخته شود، احتمال خطای «بلوک پشتیبانی نمی‌شود»، خروجی تکراری، تفاوت Preview و Frontend و سردرگمی خریدار را بالا می‌برد.

### وضعیت عددی فعلی

| بخش | وضعیت بررسی‌شده |
|---|---:|
| Templateهای فایل‌محور | ۲۴ فایل |
| Template Partها | ۹ فایل |
| Patternهای PHP | ۲۴ فایل |
| Blockهای Theme | ۴ `block.json` |
| Blockهای Core | ۲۴ `block.json` |
| نسخه `theme.json` | ۳ |

## ۲. ساختار و مرزبندی فعلی

```text
musicwave/                     # Block Theme: فقط presentation و Site Editor
  theme.json
  templates/*.html
  parts/*.html
  patterns/*.php
  blocks/*/block.json
  assets/css/**
  assets/editor-blocks.js

music-wave-core/               # Plugin: domain, catalog, access, REST و dynamic blocks
  blocks/*/block.json
  src/Blocks/**
  src/Modules/Rendering.php
  assets/blocks.js

music-wave-vip/                # Integration اختیاری membership و protected delivery
```

این مرزبندی حفظ شده است. Theme مالک Release، Access، Download یا داده‌های حساس نیست و Core نیز برای layout عمومی Theme به PHP قالب وابسته نشده است.

## ۳. یافته‌های مهم و اصلاحات انجام‌شده

### P1 — Template صفحه Public Playlists در رابط انتخاب Template ثبت نشده بود — اصلاح شد

`templates/page-playlists.html` وجود داشت و محتوای آن از Block رسمی `music-wave/public-playlists` استفاده می‌کرد، اما slug آن در `theme.json > customTemplates` ثبت نشده بود. در نتیجه خریدار می‌توانست فایل را داشته باشد ولی آن را به‌صورت واضح در رابط Template Assignment نبیند.

**اقدام:** `page-playlists` به‌عنوان Custom Template با عنوان قابل فهم `Public playlists` ثبت شد. همین بررسی برای صفحات `page-cart` و `page-checkout` نیز انجام شد و آن‌ها برای انتخاب در Site Editor ثبت شدند.

### P1 — Footer دوبار در `page-with-sidebar` رندر می‌شد — اصلاح شد

`parts/footer.html` خودش `footer-widgets` را Compose می‌کند. `templates/page-with-sidebar.html` هر دو Part را صدا می‌زد؛ بنابراین Footer Widget Area در این Template دوبار خروجی می‌داد.

**اقدام:** مرجع مستقیم `footer-widgets` از Template حذف شد و فقط `footer` به‌عنوان مالک Composition باقی ماند.

### P1 — Category و Metadata بین Theme، PHP و JavaScript یکسان نبود — اصلاح شد

`block.json`های Theme از category پیش‌فرض `theme` استفاده می‌کردند، Runtime آن‌ها را از روی JSON ثبت می‌کرد، اما Registry سمت JavaScript category را به‌صورت hard-code روی `widgets` می‌گذاشت. این برای خریدار به معنی دسته‌بندی ناهماهنگ در Inserter و افزایش ریسک drift بود.

**اقدام:**

- هر چهار Block Theme به category مشترک `music-wave` منتقل شدند.
- `musicwave_enqueue_presentation_editor_blocks()` اکنون `apiVersion`، `category`، `keywords`، `textdomain`، `example`، `attributes` و `supports` را مستقیماً از `block.json` به Editor می‌دهد.
- نقشه‌های تکراری title/description حذف شدند؛ `block.json` منبع واحد Metadata است.
- `editor-blocks.js` دیگر category یا keywords را hard-code نمی‌کند.

### P1 — Namespace چهار Block Theme با Core یکسان نبود — اصلاح شد

در inventory کامل مشخص شد که چهار Block presentation قالب هنوز با `musicwave/*` ثبت می‌شدند، در حالی که ۲۴ Block Core با `music-wave/*` کار می‌کردند. این اختلاف برای Templateهای ذخیره‌شده و Site Editor هم‌زمان یک مشکل consistency و یک ریسک compatibility بود.

**اقدام:**

- نام canonical هر چهار metadata به `music-wave/*` تغییر کرد.
- Templateها، Partها، Patternها، `theme.json` و شرط‌های Editor به نام canonical منتقل شدند.
- برای هر نام قدیمی `musicwave/*` یک registration سازگار با همان attributes، supports و render callback اضافه شد؛ alias با `inserter: false` مخفی است تا محتوای جدید فقط canonical تولید کند.
- metadata alias در Editor نیز localize می‌شود تا overrideهای قدیمی به بلوک unsupported تبدیل نشوند.
- کلیدهای style قدیمی در `theme.json` برای حفظ appearance محتوای persistent قبلی باقی مانده‌اند و به‌عنوان compatibility surface در نظر گرفته می‌شوند.

### ممیزی کامل metadata و presentation — انجام شد

اکنون ۲۸ `block.json` شامل schema رسمی WordPress، API v3، عنوان، توضیح، icon، keyword، textdomain، attributes و supports هستند. Registryهای PHP/JS Core با ۲۴ metadata parity دارند و چهار Theme Block از مسیر واحد metadata قالب register/localize می‌شوند. fallback تصویر، کنترل keyboard، reduced-motion، RTL logical positioning و bounded responsive layout برای shelf/slider نیز بررسی و تکمیل شد.

### P1 — Layout Sidebar فقط با شکل DOM خاص کار می‌کرد — اصلاح شد

Selector قبلی `.mw-app-shell:has(> .mw-sidebar)` فقط زمانی Match می‌شد که Sidebar مستقیماً فرزند Shell باشد. در Templateهای bundled، Sidebar عمداً داخل `core/columns` قرار دارد؛ پس آن selector برای مسیر اصلی Templateها کار نمی‌کرد.

**اقدام:**

- منطق `:has()` برای حالت سفارشیِ Sidebar مستقیم حفظ شد.
- Templateهای bundled با modifier رسمی `.mw-app-shell--with-sidebar` و قواعد مشخص برای `core/columns` پشتیبانی شدند.
- قواعد Editor نیز به selectorهای flat تبدیل شدند تا به CSS nesting وابسته نباشند.

### P1 — Feature Shelf کار تکراری انجام می‌داد — اصلاح شد

در `musicwave_render_release_shelf()` ابتدا کارت‌های معمولی برای تمام Releaseها ساخته می‌شد، سپس در حالت `feature` همان داده‌ها دور ریخته می‌شدند و `musicwave_render_feature_shelf()` دوباره title، taxonomy و thumbnail را می‌خواند.

**اقدام:**

- مسیر `feature` پیش از حلقه عمومی کارت‌ها resolve می‌شود.
- helper مشترک `musicwave_release_presentation_data()` برای permalink، title، artist و initial اضافه شد.
- Header عمومی Shelf در `musicwave_render_shelf_header()` متمرکز شد و Shelf معمولی و Playlist Shelf از همان helper استفاده می‌کنند.

این تغییر رفتار ظاهری را عوض نمی‌کند؛ فقط مسیر اجرا را سبک‌تر و احتمال drift بین Card و Slider را کمتر می‌کند.

### P2 — کنترل Layout در Site Editor برای خریدار صریح نبود — اصلاح شد

`theme.json` از قبل Appearance Tools را فعال می‌کرد، اما `settings.layout.allowEditing` صریح نبود.

**اقدام:** این گزینه فعال شد تا کنترل Layout در رابط استاندارد و بدون ویرایش کد در دسترس باشد.

## ۴. تصمیم درباره Template Partها و Patternها

هر فایل مشابه، لزوماً duplicate نیست. تصمیم معماری فعلی:

- `header` و `footer`، Composition اصلی سایت هستند.
- `sidebar` و `sidebar-shop` دو Context واقعی و متفاوت دارند و ادغام آن‌ها UX را بدتر می‌کند.
- `footer-widgets` یک Part قابل ویرایش است که توسط `footer` مصرف می‌شود؛ نباید مستقیماً کنار `footer` در Template دیگری درج شود.
- `header-centered`، `header-minimal`، `footer-simple` و `hero` گزینه‌های presentation برای استفاده خریدار هستند و فعلاً به‌عنوان Partهای قابل انتخاب نگه داشته شده‌اند؛ اما نباید در Composition پیش‌فرض به‌صورت هم‌زمان استفاده شوند.
- Patternهای تک‌Block مانند `new-releases-shelf` یا `featured-release-slider` به‌عنوان **preset آماده برای کاربر غیرفنی** حفظ شده‌اند، نه به‌عنوان پیاده‌سازی دوم. منطق آن‌ها داخل Block است و Pattern فقط Attributeهای preset را می‌سازد.
- تجربه‌های Account و Public Playlists یک Composition رسمی دارند؛ برای Public Playlists Pattern wrapper جداگانه ایجاد نشده تا Block و Pattern دوبار در Inserter ظاهر نشوند.

به این ترتیب، حذف کورکورانه Patternهای preset انجام نشد؛ چون باعث کاهش قابلیت مدیریت بصری محصول می‌شد. Duplicate واقعی Footer و مسیر اجرای تکراری Feature Shelf حذف شد.

## ۵. برنامه مرحله‌بندی‌شده پیشنهادی

### مرحله ۰ — Baseline و Release Gate (انجام‌شده در این increment)

- [x] ثبت commit و snapshot ساختار Theme/Plugin
- [x] یکسان‌سازی category و منبع Metadata Blockهای Theme
- [x] یکسان‌سازی namespace canonical همهٔ ۲۸ Block با `music-wave/*` و نگه‌داشتن aliasهای legacy
- [x] تکمیل schema/keywords و ممیزی metadata، registration، controls، renderer و presentation هر ۲۸ Block
- [x] ثبت Custom Templateهای فراموش‌شده
- [x] جلوگیری از Footer دوباره
- [x] اضافه‌کردن static FSE integrity gate به Composer و CI
- [ ] اجرای واقعی PHP/WordPress fixture در محیطی که PHP و Docker در دسترس باشد

### مرحله ۱ — تثبیت Composition (گام بعدی پیشنهادی)

1. اجرای `composer check:site-editor` و `php tests/template-integrity.php` در CI و fixture واقعی.
2. تست Site Editor برای Insert، تغییر Inspector، ذخیره و Reload هر Block Theme و Core.
3. تست Assignment برای `page-account`، `page-playlists`، `page-cart`، `page-checkout` و `page-no-sidebar`.
4. تست عدم رندر دوباره Footer در تمام Templateهای سفارشی.
5. بررسی Templateهای ذخیره‌شده در دیتابیس؛ چون نسخه DB بر فایل Theme اولویت دارد.

### مرحله ۲ — ساده‌سازی UX و Authoring

1. اضافه‌کردن راهنمای کوتاه درون Inspector برای Source، Query و Layout.
2. استفاده بیشتر از `ColorPalette` و presetهای `theme.json` به‌جای ورودی خام Hex در کنترل‌های سفارشی.
3. ارائه Patternهای کامل با عنوان، توضیح، Category و preview ثابت.
4. حذف URLهای brittle مانند `/browse/` از Patternهای عمومی یا جایگزینی آن‌ها با Page/Navigation قابل ویرایش.
5. بررسی RTL، ترجمه و طول متن در Editor و Frontend.

### مرحله ۳ — Performance و Asset Loading

1. اندازه‌گیری CSS فعلی در Frontend و Editor.
2. انتقال CSS اختصاصی Blockهای Theme به `style`/`editorStyle` metadata یا enqueue شرطی، بدون شکستن Template Preview.
3. جلوگیری از بارگذاری Slider JS در صفحاتی که Slider ندارند؛ این مسیر در کد فعلی تا حدی پیاده شده و باید در fixture تأیید شود.
4. ثبت بودجه CSS/JS در تست Performance و بررسی تصاویر LCP/ lazy loading.

### مرحله ۴ — Compatibility و Marketplace QA

1. تست با WordPress حداقل پشتیبانی‌شده و آخرین نسخه پایدار.
2. تست با و بدون WooCommerce و با/بدون Core فعال.
3. تست نصب ZIP تمیز، فعال‌سازی، Upgrade و rollback.
4. بررسی PHP 7.4، PHP 8.2، multisite، زبان فارسی/RTL، accessibility و keyboard-only.
5. کنترل License، ترجمه POT، نام‌گذاری handleها، screenshot، readme و archive contents.

## ۶. قرارداد معماری که از این مرحله به بعد باید حفظ شود

- `block.json` تنها منبع attributes، supports، metadata و inserter data هر Block است.
- PHP فقط render callback و منطق runtime را اضافه می‌کند؛ attribute map موازی ساخته نمی‌شود.
- Theme فقط layout/presentation را مالک است؛ داده، Access، Commerce و REST در Plugin باقی می‌مانند.
- Templateها Composition هستند و نباید markup داینامیک Block را دوباره پیاده کنند.
- Template Partی که توسط Part دیگر Compose شده است، مستقیماً کنار آن درج نمی‌شود.
- Query، card data و section header مشترک باید helper/service مشترک داشته باشند.
- هر dynamic block در Template باید self-closing باشد و fallback HTML دستی نداشته باشد.
- هر اصلاح FSE باید هم Frontend و هم Editor preview و هم DB-saved template override را در نظر بگیرد.

## ۷. وضعیت اعتبارسنجی این محیط

در محیط Agent، `git clone` و بررسی استاتیک/JSON/JavaScript انجام شد. PHP CLI در sandbox نصب نبود (`php: command not found`)، بنابراین اجرای تست‌های PHP و WordPress fixture در این محیط ممکن نبود. به همین دلیل gate جدید در CI ثبت شده است تا در محیط PHP واقعی اجرا شود؛ این محدودیت به‌عنوان «تست موفق» گزارش نمی‌شود.

دستورهای تأیید در محیط توسعه/CI:

```bash
composer validate --strict
composer check:syntax
composer check:site-editor
composer test
npm ci
npm run lint:js
```
