# گزارش ممیزی کامل بلوک‌های اختصاصی MusicWave

**تاریخ:** ۳۰ اوت ۲۰۲۶  
**محدوده:** `music-wave-core`، قالب `musicwave`، Site Editor، Template/Patternها، CSS و ترجمه‌های JavaScript

## نتیجهٔ اجرایی

در پروژه ۲۸ بلوک اختصاصی وجود دارد:

- ۲۴ بلوک Core با namespace رسمی `music-wave/*`
- ۴ بلوک Theme با namespace رسمی `music-wave/*`
- category همهٔ آن‌ها: `music-wave`
- API همهٔ metadataها: نسخهٔ ۳
- زبان پایهٔ metadata و متن‌های قابل‌نمایش: فارسی
- textdomain بلوک‌های Core: `music-wave-core`
- textdomain بلوک‌های Theme: `musicwave`

چهار بلوک Theme از namespace قدیمی `musicwave/*` به namespace canonical منتقل شدند:

| بلوک | namespace رسمی | PHP renderer | کنترل‌های Editor | وضعیت fallback/نمایش |
|---|---|---|---|---|
| ویترین انتشارها | `music-wave/release-shelf` | `musicwave_render_release_shelf` | پرس‌وجو، منبع، چیدمان، کارت، فراخوان اقدام و ظاهر | محدودسازی امن query، placeholder تصویر، حالت‌های grid/scroll/list/feature |
| اسلایدر انتشارها | `music-wave/release-slider` | `musicwave_render_release_slider` | محتوا، تعداد، رفتار اسلایدر و نمایش metadata | خروجی خالی برای نبود داده، placeholder تصویر، scroll-snap و reduced-motion |
| متن قالب | `music-wave/theme-text` | `musicwave_render_theme_text` | داخلی و مخفی از inserter؛ مناسب متن‌های پیش‌فرض قالب | کلید allow-list، tag allow-list و پاک‌سازی class |
| ترجیح نمایش | `music-wave/theme-toggle` | `musicwave_render_theme_toggle` | داخلی و مخفی از inserter | دکمهٔ semantic با `type`، `aria-label` و عنوان ترجمه‌شده |

## ماتریس کنترل و registration

### Core — ۲۴ بلوک

metadata هر بلوک در `music-wave-core/blocks/*/block.json` شامل `$schema`، `apiVersion`، نام canonical، عنوان، توضیح، category، icon، keyword فارسی، textdomain، attributes، supports و example ویرایشگر است. نام هر ۲۴ بلوک در هر دو registry زیر تطبیق داده شد:

1. `music-wave-core/src/Modules/Rendering.php` برای registration و metadata سمت PHP
2. `music-wave-core/assets/blocks.js` برای registration و Inspector سمت Editor

فهرست بلوک‌های بررسی‌شده:

- `access-panel`
- `account-dashboard`
- `add-to-playlist`
- `add-to-queue`
- `artist-profile`
- `artists-shelf`
- `catalog-filters`
- `catalog-results`
- `collection-list`
- `continue-listening`
- `download-button`
- `library-button`
- `membership-panel`
- `music-library`
- `playback-queue`
- `playlists`
- `preview-button`
- `preview-player`
- `public-playlists`
- `related-releases`
- `release-credits`
- `release-meta`
- `taxonomy-shelf`
- `term-hero`

attributeهای حساس بین renderer و editor نیز در بررسی‌های قبلی به‌صورت source-level تطبیق داده شده‌اند؛ از جمله گزینه‌های access/VIP، secure download، query/filter، card display، taxonomy، artist، credits، queue و preview.

### Theme — ۴ بلوک

`block.json` منبع واحد metadata، attributes و supports است. PHP فقط render callback را اضافه می‌کند و JS metadata را از PHP localization دریافت می‌کند؛ نقشهٔ موازی title/description/attribute وجود ندارد.

- `release-shelf` تمام گزینه‌های query و presentation خود را در metadata دارد و Inspector شامل منبع انتشار/فهرست‌های پخش عمومی، order، taxonomy، تعداد، layout، shape، کارت، CTA و feature settings است.
- `release-slider` با همان query contract مشترک shelf کار می‌کند و Inspector برای order، taxonomy، release ID، تعداد، autoplay، loop، pause، arrows، dots و metadata دارد.
- `theme-text` و `theme-toggle` بلوک‌های زیرساختی هستند و عمداً از inserter مخفی‌اند تا کاربر غیرفنی نمونهٔ اشتباه درج نکند.

## backward compatibility و Site Editor

تغییر نام مستقیم و حذف namespace قدیمی انجام نشده است. هنگام `init` برای هر چهار بلوک این aliasها ثبت می‌شوند:

- `musicwave/release-shelf`
- `musicwave/release-slider`
- `musicwave/theme-text`
- `musicwave/theme-toggle`

این aliasها:

- همان attributes، supports، title، description، keyword و render callback بلوک canonical را دارند؛
- با `supports.inserter = false` از درج محتوای جدید مخفی می‌شوند؛
- در `enqueue_block_editor_assets` نیز به‌صورت metadata در دسترس Editor هستند تا محتوای قدیمی unsupported نشود؛
- title، description و keyword آن‌ها با textdomain فعال ترجمه می‌شود.

`theme.json` هم settings/styles canonical را دارد و هم کلیدهای legacy لازم برای حفظ تنظیمات ظاهری محتوای قدیمی. Templateها و Patternهای bundled فقط از `wp:music-wave/*` استفاده می‌کنند. نامک Patternها مانند `musicwave/release-chart` تغییر نکرده، چون namespace Pattern است نه namespace Block.

## accessibility، RTL و responsive

- ساختار خروجی shelf/slider بر پایهٔ `section`، `header`، heading و لینک‌های واقعی است.
- کنترل‌های slider دکمهٔ واقعی، `aria-label`، وضعیت disabled و live status دارند.
- تصاویر decorative با `alt` خالی و لینک/heading قابل‌فهم همراه‌اند.
- نبود تصویر در shelf و slider به initial حرفیِ امن و decorative fallback تبدیل می‌شود.
- CSS shelf از container query، grid/rail/list و logical properties استفاده می‌کند.
- CSS slider در RTL جهت محتوای کارت را RTL نگه می‌دارد و scroll viewport را برای محاسبهٔ پایدار LTR می‌کند؛ محل دکمهٔ پخش با `inset-inline-end` تعریف شده است.
- `prefers-reduced-motion` برای shelf و slider پوشش داده شده است.
- اندازه‌ها و ستون‌ها bounded هستند تا attribute دست‌کاری‌شده layout را از بین نبرد.

## localization

- رشته‌های جدید و metadata قابل‌نمایش فارسی هستند؛ identifier، slug، taxonomy، option value و نامک‌های persistent تغییر نکرده‌اند.
- textdomain قالب و Core از هم جدا باقی مانده است.
- فایل‌های واقعی `en_US` برای PHP و JavaScript در مسیر languages وجود دارند و نام فایل‌های JSON بر اساس hash مسیر نسبی script تولید شده است.
- ابزار `tools/check-script-translations.php` وجود فایل، source path، JED catalog، locale header، نمونه ترجمه و plural را بررسی می‌کند.

## بازبینی و ارتقای Header و Navigation

- `navigation.css` بازنویسی شد و `position: sticky` اجباری از حالت پایهٔ هدر حذف شد.
- sticky شدن هدر اکنون فقط با `is-position-sticky` یا style معادل تولیدشده توسط WordPress فعال می‌شود؛ در نتیجه گزینهٔ Position در Site Editor واقعاً روشن/خاموش عمل می‌کند.
- `overflow: clip` از `.wp-site-blocks` حذف شد تا ancestor مانع sticky positioning نشود.
- منوی موبایل در حالت باز یک overlay تمام‌صفحه با `100dvh`، safe-area، قفل اسکرول، focus state، Escape/focus-trap داخلی WordPress، RTL و reduced-motion دارد.
- submenu visibility به منطق داخلی Navigation وردپرس واگذار شد و CSS دیگر همهٔ submenuها را به‌صورت اجباری باز نمی‌کند.
- قواعد تکراری overlay، close button و responsive navigation یکپارچه شدند.
- هدرهای معمولی، centered و minimal از همین قرارداد مشترک استفاده می‌کنند.

## اصلاح Query Loop، تغییرهای سبک و برچسب‌های دانلود

### ۱) پیش‌نمایش Query Loop

`ServerSideRender` تنها `attributes` را به `/wp/v2/block-renderer` می‌فرستد؛ context هر ردیف
(`postId`/`postType` که `core/post-template` در اختیار بلوک‌های داخلی می‌گذارد) در آن درخواست
جایی نداشت. `editorPreview()` این کمبود را با سنجاق‌کردن `options[0].value` (جدیدترین انتشار
کاتالوگ) جبران می‌کرد؛ یعنی هرگاه context در دسترس نبود، دادهٔ یک انتشار واقعی داخل ردیفی
می‌رفت که به آن تعلق نداشت — از جمله در Query Loop روی `post` یا روی یک taxonomy.

اکنون:

- `useEditorContextPost()` پست جاری را نخست از context بلوک و در نبود آن از `core/editor`
  می‌خواند. هر دو `useSelect` مقدار اولیه (عدد/رشته) برمی‌گردانند، چون `@wordpress/data`
  نتیجهٔ selector را با هویت مقایسه می‌کند و بازگرداندن آبجکت تازه باعث حلقهٔ re-render می‌شد.
- همان شناسه به‌شکل `urlQueryArgs: { post_id }` به endpoint فرستاده می‌شود.
  `WP_REST_Block_Renderer_Controller` با آن پست سراسری را دقیقاً مانند frontend جای‌گزین می‌کند،
  پس `release_id()`، permalinkها، تصمیم دسترسی و `BlockSupport::current_url()` برای هر ردیف
  درست حل می‌شوند — نه فقط `releaseId`.
- fallback «انتشار نمونه» تنها وقتی اجرا می‌شود که هیچ پست context وجود نداشته باشد
  (Site Editor روی یک Template). داخل Query Loop هرگز اجرا نمی‌شود و مقدار آن هیچ‌وقت در
  attributeها نوشته نمی‌شود.

پیام «This block has an error and cannot be previewed» خروجی ErrorBoundary گوتنبرگ است، نه
پاسخ خطای `ServerSideRender` (آن یک `Notice` فارسی جداگانه دارد). یکی از مسیرهای رسیدن به آن
خطا در کد خود پروژه بود: حلقهٔ registration در `blocks.js` بلوک را `unregisterBlockType` می‌کرد
و اگر ثبت مجدد ناموفق می‌ماند — `registerBlockType()` در صورت رد شدن settings مقدار
`undefined` برمی‌گرداند — بلوک برای همیشه unregister می‌ماند. در آن حالت
`sanitizeBlockAttributes()` داخل `ServerSideRender` استثنا پرتاب می‌کند و چون بلوک‌های MusicWave
داخل `core/post-template` رندر می‌شوند، خطا به نام خودِ Query Loop ثبت می‌شد. اکنون اگر ثبت
ناموفق باشد، تعریف server بازگردانده می‌شود تا بلوک هیچ‌وقت بی‌تعریف نماند.

### ۲) دو سبک Catalog Filters

دو ایراد مستقل روی هم اثر می‌کردند:

1. **دو کنترل برای یک نتیجه.** هم attribute `layout` (با مقدارهای `inline`/`stacked`) و هم
   block style «انباشته شده» وجود داشت و در renderer مقدار style بر `layout` غلبه می‌کرد.
   در نتیجه اگر `layout` روی `stacked` بود، تغییر Styles panel هیچ اثر قابل دیدنی نداشت.
   اکنون `attributeUpdate()` در `blocks.js` این دو را همگام نگه می‌دارد: تغییر هرکدام دیگری
   را هم می‌نویسد، پس هیچ‌کدام بی‌اثر نمی‌ماند.
2. **CSS ناکافی.** قاعدهٔ `--stacked` فقط `flex-direction: column` بود و چون
   `@media (max-width: 42rem)` همان کار را برای حالت inline هم می‌کرد، در عرض‌های کوچک دو سبک
   کاملاً یکسان می‌شدند. همچنین `.mw-catalog-suggest` و لینک بازنشانی زیر پوشش selectorها
   نبودند.

حالا `stacked` یک **پنل** است نه یک نوار ابزار: گرید پاسخ‌گو، سطح و شعاع متفاوت،
`border-block-start` با رنگ accent، کنترل‌های بلندتر و کادر‌دار، جست‌وجو و گروه اقدامات روی
`grid-column: 1 / -1`، و footer خط‌کشی‌شده. `inline` همان نوار ابزار شیشه‌ای فشرده می‌ماند، پس
دو سبک در هر عرضی از هم قابل تشخیص‌اند. renderer هم `submit` و `reset` را داخل
`.mw-catalog-filters__actions` می‌گذارد تا هر دو چیدمان بتوانند آن‌ها را یک‌جا جابه‌جا کنند
(همهٔ selectorهای `catalog-filters.js` از نوع descendant هستند، پس این تغییر برای instant
filtering بی‌خطر است). chevron انتخابگرها هم که با `background-position` فیزیکی کشیده می‌شد،
برای RTL آینه شد.

### ۳) دو سبک Collection Track List

`--tracklist` تا پیش از این فقط رنگ حاشیه، padding و اندازهٔ قلم را تغییر می‌داد؛ ساختار
(ردیف‌های باز و بدون سطح) دقیقاً همان حالت پیش‌فرض بود. اکنون variation هویت ساختاری خودش را
دارد و از همان hookهای موجود markup استفاده می‌کند: پنل کادردار با `--mw-shadow-card`، عنوان
با خط accent، ستون شمارهٔ tabular پهن‌تر، **leader نقطه‌چین** بین عنوان و زمان اجرا،
quality با حروف ریز فاصله‌دار، اکشن‌های همیشه نمایان (به‌جای `opacity: 0.6`)، جمع مدت‌زمان به
شکل footer خط‌کشی‌شده، و در عرض‌های کوچک فقط leader جمع می‌شود تا هویت پنل حفظ بماند.

### ۴) برچسب‌های Secure Download

`downloadLabel`، `playLabel` و `loginLabel` در `block.json`، در `Rendering.php` و در پنل تنظیمات
حضور داشتند، ولی renderer هیچ‌وقت متن قابل دیدن چاپ نمی‌کرد: فقط `aria-label` و یک آیکون.
`loginLabel` اساساً هیچ مصرفی نداشت.

- هر دو دکمهٔ دانلود و پخش (در `download_file_rows()` و `download_inline_markup()`) اکنون از
  دو helper مشترک ساخته می‌شوند و `<span class="mw-download-button__label">` /
  `<span class="mw-secure-play-button__label">` را به‌عنوان متن قابل دیدن کنار آیکون
  چاپ می‌کنند. `download.js` هنگام پخش، هم `aria-label` و هم متن قابل دیدن را با هم
  جابه‌جا می‌کند تا نام دسترس‌پذیر و متن روی صفحه هیچ‌وقت از هم جدا نیفتند.
- `loginLabel` به یک **فراخوان ورود opt-in** وصل شد (`download_sign_in_markup()`): فقط برای
  بازدیدکنندهٔ خارج‌شده از سیستم و فقط وقتی تصمیم دسترسی رد شده و `loginLabel` پر باشد و
  دارایی دانلود واقعاً وجود داشته باشد. پیش‌فرض آن خالی است، پس رفتار
  `single-mw_release.html` (که هم `download-button` و هم `access-panel` دارد) بدون تغییر می‌ماند
  و پیام دوباره نمایش داده نمی‌شود. این prompt هیچ رشتهٔ ترجمه‌شدهٔ تازه‌ای اضافه نمی‌کند و
  از heading خود بلوک و `wp_login_url( BlockSupport::current_url() )` — همان الگوی
  `PlaylistBlocks`/`QueueBlock`/`ListeningBlocks` — استفاده می‌کند.

### contract تست‌های تازه

`tests/template-integrity.php` حالا علاوه بر parity قبلی، این قراردادها را هم assertion می‌کند:
ارسال `post_id` در پیش‌نمایش، ممنوعیت fallback وقتی context وجود دارد، بازگرداندن تعریف server
پس از ثبت ناموفق، وجود قواعد ساختاری `--stacked` و `--tracklist` در stylesheetها، گروه
`.mw-catalog-filters__actions` در renderer، چاپ کلاس‌های `__label` هم در PHP و هم در CSS، و
همگام ماندن برچسب پخش در `download.js`.

## تست‌های انجام‌شده در این محیط

موفق:

- parse همهٔ ۲۸ `block.json` و `theme.json`
- inventory namespace: ۲۴ Core + ۴ Theme، بدون duplicate
- parity استاتیک نام بلوک‌های Core در metadata/PHP/JS
- عدم وجود `wp:musicwave/*` در Template/Part/Patternهای bundled
- `npm run lint:js`
- `node --check` برای فایل‌های JavaScript مرتبط
- `git diff --check`

قابل اجرا نبود:

- PHP lint، `tests/template-integrity.php`، `tools/check-site-editor.php` و تست runtime WordPress؛ چون PHP CLI در sandbox موجود نیست.
- تست واقعی Gutenberg/Site Editor، ذخیره و reload overrideهای database، RTL بصری، WooCommerce، VIP، secure download و browser accessibility به WordPress runtime نیاز دارند.

در CI/محیط توسعهٔ دارای PHP این gateها باید اجرا شوند:

```bash
composer check:site-editor
php tests/template-integrity.php
composer test
composer check:script-translations
npm run lint:js
```
