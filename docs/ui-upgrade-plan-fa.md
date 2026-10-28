# تحلیل و برنامهٔ ارتقای رابط کاربری MusicWave (سربرگ، حالت روشن، پخش‌کنندهٔ سراسری)

**تاریخ:** ۵ سپتامبر ۲۰۲۶  
**commit مبنا:** `5681995` (`main`)  
**دامنه:** قالب `musicwave` و افزونهٔ `music-wave-core` (بخش پخش‌کنندهٔ سراسری)  
**مراجع فنی بررسی‌شده:** سورس بلوک‌های `core/navigation` و `core/search` (Gutenberg trunk)، پشتیبانی `position` در `wordpress-develop`، `design-reference.html` طرح SonicStream، تست‌های `tests/template-integrity.php` و ابزارهای `tools/check-*.php`.

---

## ۱. تحلیل وضعیت موجود (یافته‌های تأییدشده از کد)

### ۱.۱ حالت روشن — چرا متن سیاه نیست؟

| مورد | یافته |
|---|---|
| منبع رنگ‌ها | همهٔ توکن‌ها در `assets/css/tokens.css` از `--wp--preset--color--*` (پالت `theme.json`) مشتق می‌شوند. پالت پیش‌فرض تیره است (`text: #f7f8fb`). |
| ریشهٔ باگ | در حالت روشن، متن با `color-mix(in srgb, #f7f8fb 88%, black)` ساخته می‌شود که تقریباً `#dadbdd` (خاکستری روشن) است، نه سیاه. رنگ کم‌رنگ هم `#737373` می‌شود. |
| اثر جانبی | چون `--wp--preset--color--*` هرگز در حالت روشن بازنویسی نمی‌شد، هر بلوکی که با کلاس `has-*-color` یا استایل‌های `theme.json` (دکمه، پیوند، کپشن، جداکننده) رنگ می‌گرفت، همچنان مقدار تیره را داشت. |
| کنتراست | هاور دکمهٔ تغییر پوسته (`theme-toggle.css`) از `--mw-color-canvas` روی پس‌زمینهٔ تأکیدی استفاده می‌کرد؛ در حالت روشن کنتراست از بین می‌رفت. رنگ پیوند (`accent-strong = #1ed760`) روی سفید کنتراست ۱٫۹:۱ دارد. |
| تنوع‌های سبک | `cassette` و `sunrise` ذاتاً روشن‌اند؛ بقیه تیره. هیچ‌کدام `settings.custom` ندارند، پس نمی‌توانند نسخهٔ مقابل (روشن/تیره) خود را اعلام کنند. |

### ۱.۲ سربرگ و منوی موبایل

| # | مشکل | شواهد |
|---|---|---|
| H1 | `header-stream` ناوبری را زیر `64rem` مخفی می‌کند و هیچ همبرگری جایگزین آن نمی‌شود. | `navigation.css` خطوط ۸۰–۸۸ |
| H2 | نقطهٔ شکست همبرگر هسته (`overlayMenu: "mobile"`) **۶۰۰px** است، ولی استایل اورلی قالب تا `47.99rem` (۷۶۷px) نوشته شده بود. بین ۶۰۰ تا ۷۶۷px هسته منو را «درون‌خطی» نشان می‌دهد ولی قاعدهٔ پایهٔ قالب روی `.wp-block-navigation__responsive-container` مقدار `visibility: hidden; opacity: 0` می‌گذاشت ⇒ **منو در این بازه نامرئی بود.** | `navigation.css` خطوط ۱۹۸–۲۱۶ |
| H3 | سربرگ پیش‌فرض با `flexWrap: wrap` و جست‌وجوی ۱۸۰px در عرض‌های کوچک به دو خط می‌شکست. | `parts/header.html` |
| H4 | دکمهٔ همبرگر و بستن بدون سطح/کادر بودند، شورون زیرمنو استایل نداشت، و زیرمنوی دسکتاپ با رنگ پیش‌فرض هسته (پس‌زمینهٔ سفید/متن سیاه) در پوستهٔ تیره ظاهر می‌شد. | CSS هسته: `.wp-block-navigation:not(.has-background) .wp-block-navigation__submenu-container { background:#fff }` |
| H5 | آفست نوار مدیریت به‌صورت دستی (۳۲/۴۶px) نوشته شده بود؛ در `≤600px` که نوار مدیریت `absolute` است اشتباه می‌شود. هسته متغیر `--wp-admin--admin-bar--position-offset` را دقیقاً برای همین می‌سازد. | `navigation.css` ۴۳۳–۴۴۷، `layout.css` `.mw-sidebar { top: 6.25rem }` |
| H6 | `scroll-padding-top` برای لنگرها زیر سربرگ چسبان تعریف نشده بود. | grep در کل CSS |
| H7 | `theme-preference.js` فقط اولین `.mw-theme-toggle` را فعال می‌کرد (سربرگ + پابرگ همزمان کار نمی‌کردند). | `theme-preference.js` خط ۶۶ |
| H8 | `header-centered` و `header-minimal` فاقد `openSubmenusOnClick` بودند (رفتار لمسی زیرمنو ناسازگار). | `parts/header-*.html` |
| H9 | `z-index` پشتیبانی `position: sticky` هسته `10` است و بعد از CSS قالب چاپ می‌شود؛ قاعدهٔ `z-index: 60` قالب با همان ویژگی خنثی می‌شد. | `wp-includes/block-supports/position.php` |

### ۱.۳ پخش‌کنندهٔ سراسری `mw-global-player`

| # | مشکل | شواهد |
|---|---|---|
| P1 | شبکهٔ ۶ ستونی در `≤52rem` به ۳ ستون می‌رفت؛ دکمهٔ بستن و صدا به ردیف‌های بعدی می‌ریختند و چیدمان می‌شکست. | `global-player.css` ۵۱۵–۵۲۹ |
| P2 | آیکون‌ها کاراکتر متنی بودند (`▶ ❚❚ ⏮ ⏭ ♫ ×`) و بسته به قلم فارسی/سیستم ناهماهنگ رندر می‌شدند؛ حالت «در حال بارگذاری» با `…` نشان داده می‌شد. | `PreviewPlayer.php::render_global_player`, `preview-player.js::playGlyph/setLoading` |
| P3 | زمان (`__time`) در `≤52rem` مخفی می‌شد؛ در موبایل هیچ بازخورد زمانی نبود. | `global-player.css` ۵۲۶ |
| P4 | ضخامت دستگیرهٔ نوار پیشرفت روی لمس هم فقط با hover ظاهر می‌شد؛ `aria-valuetext` نداشت. | `global-player.css` ۲۱۶–۲۳۵ |
| P5 | وقتی پخش‌کننده باز بود، هیچ فاصلهٔ پایینی به `body` اضافه نمی‌شد و محتوای انتهای صفحه/پابرگ زیر آن می‌ماند. | grep `has-player` |
| P6 | از `left/right` فیزیکی به‌جای ویژگی‌های منطقی استفاده شده بود (RTL). | `global-player.css` ۸۴–۸۶ |
| P7 | قاعدهٔ `.mw-global-player__toggle[aria-pressed="true"]` هرگز فعال نمی‌شد؛ JS این ویژگی را روی دکمهٔ پخش نمی‌گذارد. | `preview-player.js` |
| P8 | قطع/وصل صدا (mute) وجود نداشت. | — |

### ۱.۴ سایر باگ‌ها

- CSS کلاس‌های `.mw-release-credits__list--grid/--inline` وجود نداشت (تست `template-integrity` قرمز بود) — **رفع شد** (`collections.css`).
- رنگ آیکون‌های اجتماعی پابرگ با هگز قدیمی (`#a9b0c0`) هاردکد شده بود.

---

## ۲. تصمیم‌های طراحی

1. **رنگ‌ها داده‌محور و قابل‌ویرایش در Site Editor می‌مانند.** به‌جای مشتق‌کردن حالت روشن با `color-mix`، هر تنوع سبک نسخهٔ روشن/تیرهٔ خود را در `settings.custom.scheme.{light,dark}` اعلام می‌کند؛ `tokens.css` در حالت روشن خودِ `--wp--preset--color--*` را بازنویسی می‌کند تا بلوک‌های هسته، کلاس‌های `has-*-color` و توکن‌های `--mw-*` همگی یک‌جا سوئیچ شوند. متن روشن: `#0f1115`.
2. **در حالت روشن رنگ تأکیدی تیره‌تر می‌شود** (`#15803d` به‌جای `#1db954`) تا متن پیوند و دکمه‌ها به کنتراست AA برسند؛ `on-accent` سفید می‌شود.
3. **یک نقطهٔ شکست واحد برای سربرگ: `64rem`.** ناوبری سربرگ با `overlayMenu: "always"` رندر می‌شود و قالب در `≥64rem` آن را درون‌خطی نشان می‌دهد (کلاس‌های پایدار `hidden-by-default`/`always-shown` هسته). نتیجه: همبرگر برای هر ۴ سربرگ زیر `64rem` و منوی کامل بالای آن؛ بازهٔ نامرئی ۶۰۰–۷۶۷px حذف می‌شود.
4. **استایل اورلی فقط روی `.is-menu-open` اعمال می‌شود؛** حالت بسته دست‌نخورده به هسته سپرده می‌شود تا ناوبری‌های دیگر (پابرگ، الگوها، منوهای کاربر) هرگز ناپدید نشوند.
5. **آفست نوار مدیریت با متغیرهای هسته** (`--wp-admin--admin-bar--position-offset` / `--wp-admin--admin-bar--height`) محاسبه می‌شود.
6. **جست‌وجوی سربرگ به حالت `button-only` هسته** می‌رود: آیکون ۴۴px که با کلیک باز می‌شود (Interactivity API هسته، Escape/focusout آن را می‌بندد). ورودی بازشده به‌صورت لایه روی سربرگ می‌نشیند تا layout shift ایجاد نشود.
7. **پخش‌کننده: آیکون SVG درون‌خطی، سه ناحیهٔ منطقی (قطعه / کنترل‌ها + خط زمان / ابزارها)، حالت‌ها با `data-*`** به‌جای تعویض متن. در `<48rem` نوار به لبهٔ پایین می‌چسبد، زمان‌ها همیشه دیده می‌شوند، نشانگر بارگذاری SVG است، `aria-valuetext` تنظیم می‌شود و `html.mw-has-player` فاصلهٔ پایینی صفحه را تضمین می‌کند.
8. **همهٔ selectorهایی که `preview-player.js` می‌شناسد حفظ می‌شوند** تا وابستگی‌های `playlists.js` / `download.js` نشکنند.

---

## ۳. برنامهٔ اجرا (ترتیب انجام)

| گام | فایل‌ها | خروجی |
|---|---|---|
| ۱ | `tokens.css`, `theme.json`, `styles/*.json`, `theme-toggle.css` | حالت روشن با متن تیره، پالت روشن/تیرهٔ اختصاصی برای هر ۶ تنوع، هاور دکمهٔ پوسته |
| ۲ | `parts/header*.html`, `components/navigation.css`, `catalog.css` (انتقال استایل جست‌وجوی سربرگ), `layout.css` | سربرگ درست در همهٔ ابعاد، منوی کامل موبایل، زیرمنوی توکن‌محور، جست‌وجوی بازشونده |
| ۳ | `functions.php` (`musicwave_render_theme_toggle`), `theme-preference.js`, `theme-toggle.css` | آیکون SVG سه‌حالته، فعال‌سازی همهٔ دکمه‌ها |
| ۴ | `PreviewPlayer.php`, `preview-player.js`, `components/global-player.css` | پخش‌کنندهٔ واکنش‌گرا با آیکون SVG، mute، زمان دوگانه، `aria-valuetext`، فاصلهٔ پایین صفحه |
| ۵ | `parts/footer*.html`, `patterns/footer-widget-columns.php` | مقدار آیکون‌های اجتماعی هم‌راستا با پالت |
| ۶ | `style.css`, `readme.txt`, `music-wave-core.php`, `release-manifest.json`, `PROJECT_PLAN.md` | نسخهٔ قالب `0.8.0`، هسته `0.11.3` |
| ۷ | `php tests/run.php`, `php tools/check-site-editor.php`, `php tools/check-templates.php`, `php tools/check-script-translations.php`, `php -l` | سبز |

## ۴. معیارهای پذیرش

- در `html[data-mw-theme="light"]` و در حالت سیستم روشن، `--mw-color-text` برابر `#0f1115` (یا مقدار روشن تنوع) است و `.has-text-color`/`.has-muted-color` هم روشن‌محور رندر می‌شوند.
- در ۳۶۰، ۶۰۰، ۷۶۸، ۱۰۲۴ و ۱۴۴۰px هر ۴ سربرگ یک ردیفه می‌مانند؛ زیر `64rem` همبرگر دیده می‌شود و اورلی تمام‌صفحه با دکمهٔ بستن قابل‌دسترس (زیر نوار مدیریت) باز می‌شود؛ زیرمنوها در اورلی باز و در دسکتاپ با کلیک باز می‌شوند.
- پخش‌کننده در همهٔ عرض‌ها بدون هم‌پوشانی کنترل‌ها رندر می‌شود؛ زمان جاری/کل همیشه دیده می‌شود؛ محتوای صفحه زیر نوار پنهان نمی‌شود.
- تست‌ها و ابزارهای یکپارچگی سبز هستند.

---

## ۵. گزارش اجرا (۶ سپتامبر ۲۰۲۶)

همهٔ گام‌های جدول بخش ۳ اجرا شد. نسخه‌ها: قالب `0.8.0`، هسته `0.11.3` (`release-manifest.json`، `style.css`، `readme.txt`ها، `PROJECT_PLAN.md`).

### ۵.۱ فایل‌های تغییریافته

| حوزه | فایل‌ها | خلاصه |
|---|---|---|
| رنگ / حالت روشن | `tokens.css`, `theme.json`, `styles/*.json` | نگاشت `--wp--preset--color--*` در حالت روشن؛ `settings.custom.scheme.{light,dark}` برای ۶ تنوع؛ `data-mw-scheme` روی `<html>` از `musicwave_language_attributes()` |
| سربرگ | `parts/header*.html`, `components/navigation.css`, `catalog.css`, `layout.css` | `overlayMenu:"always"` + نقطهٔ شکست واحد `64rem`، اورلی کامل (اسکرول، safe-area، آفست نوار مدیریت، `100dvh`)، زیرمنوی توکن‌محور، جست‌وجوی `button-only`، sticky با `:has()` روی wrapper و `z-index` بالاتر از پشتیبانی هسته |
| تغییر پوسته | `functions.php`, `theme-preference.js`, `theme-toggle.css` | سه آیکون SVG، همگام‌سازی همهٔ نمونه‌ها و تب‌ها (`storage`) |
| پخش‌کننده | `PreviewPlayer.php`, `preview-player.js`, `components/global-player.css` | نشانه‌گذاری سه‌ناحیه‌ای با SVG، `data-state` روی دکمهٔ پخش (`paused/playing/loading`)، `data-mw-volume`، mute، زمان جاری/کل، `aria-valuetext`، `html.mw-has-player` + `--mw-player-offset`، چیدمان داک‌شده در `≤48rem` |
| پابرگ | `parts/footer*.html`, `patterns/footer-widget-columns.php` | `iconColorValue` از پیش‌تنظیم پالت |
| باگ‌های جانبی | `collections.css`, `playlists.css` | کلاس‌های `__list--grid/--inline` (تست قرمز)؛ selector خراب `input[type=""search""]` |

### ۵.۲ قراردادهای جدید برای توسعه‌دهندگان

- **`html.mw-has-player`** و **`--mw-player-offset`** (تعریف در `global-player.css`) — هر عنصر چسبان/ثابت پایینی باید این آفست را لحاظ کند (`layout.css` برای `.wp-site-blocks` و `.mw-sidebar` انجام می‌دهد).
- **`--mw-sticky-offset`** — فاصلهٔ امن از بالای صفحه (نوار مدیریت + سربرگ چسبان در صورت فعال‌بودن).
- **وضعیت پخش‌کننده فقط با ویژگی‌ها** (`.mw-global-player[data-mw-state|data-mw-volume]`, `.mw-global-player__toggle[data-state]`) استایل می‌گیرد؛ هیچ کدی نباید `textContent` دکمه‌های پخش‌کننده را بازنویسی کند. قرارداد گلیف دکمه‌های بیرونی (`.mw-preview-button__icon`, `.mw-card-play__icon`, …) بدون تغییر مانده است.
- **حالت روشن:** رنگ جدید را فقط در `theme.json`/`styles/*.json` (پالت + `settings.custom.scheme`) اضافه کنید؛ CSS نیازی به ویرایش ندارد.

### ۵.۳ نتیجهٔ دروازه‌های کیفیت

- `php tests/run.php` → سبز (شامل `template-integrity.php`)
- `php tools/check-site-editor.php` / `check-templates.php` / `check-script-translations.php` → سبز
- `php -l` روی PHPهای ویرایش‌شده، `node --check` + ESLint (قواعد هسته) + wp-prettier روی JS ویرایش‌شده → بدون خطای جدید
- تجزیهٔ همهٔ CSS قالب با `css-tree` → فقط هشدار قدیمی `editor.css:29` (`@supports not (selector(:has(> *)))` که مرورگرها می‌پذیرند و پیش از این تغییر وجود داشت)
- تست دود jsdom روی `preview-player.js` با نشانه‌گذاری واقعی PHP (۲۵ بررسی) و `theme-preference.js` (۸ بررسی) → همه سبز

### ۵.۴ محدودیت‌های شناخته‌شده

- بوم ویرایشگر (`editor-styles-wrapper`) ویژگی‌های `<html>` را ندارد؛ بنابراین همیشه پالت **بومی** تنوع فعال را نمایش می‌دهد (رفتار مطلوب برای ویرایش رنگ‌ها). پیش‌نمایش حالت مقابل فقط در سایت عمومی است.
- ناوبری نرم پخش‌کنندهٔ سراسری (`persistentNav`) اکنون سربرگ hydrate‌شده را بین صفحه‌ها حمل می‌کند (بخش ۵.۵)؛ تنها محدودیت باقی‌مانده این است که اگر مدیر سربرگ را در صفحه‌ای متفاوت بسازد (منوی متفاوت)، همان کلیک به بارگذاری بومی تبدیل می‌شود — رفتار امن و قابل‌مشاهده، نه منوی خراب.
- بررسی بصری با مرورگر واقعی در این محیط ممکن نبود (بدون Chromium)؛ چیدمان‌ها بر اساس قراردادهای CSS هسته که در بخش ۱ مستند شده‌اند نوشته شده‌اند و باید در محیط staging در ۳۶۰/۶۰۰/۷۶۸/۱۰۲۴/۱۴۴۰px بازبینی شوند.

### ۵.۵ تکمیل: ناوبری نرم و بلوک‌های تعاملی هسته (افزودهٔ ۶ سپتامبر ۲۰۲۶)

**مسئله (ریشه‌یابی‌شده در کد هسته):** `@wordpress/interactivity` ناحیه‌های `data-wp-interactive` را فقط یک بار و در `DOMContentLoaded` hydrate می‌کند (`packages/interactivity/src/index.ts` → `onDOMReady(hydrateRegions)`). `navRenderPage` کل `<body>` را جایگزین می‌کرد؛ بنابراین سربرگ تازه (منوی همبرگر `core/navigation` و جست‌وجوی بازشوندهٔ `core/search`) پس از اولین ناوبری نرم بی‌اثر می‌شد. همچنین ورودی‌های تاریخچهٔ ما `wpInteractivityId` نداشتند و هسته در `popstate` صفحه را اجباراً reload می‌کرد.

**راه‌حل پیاده‌شده در `music-wave-core/assets/preview-player.js`:**

1. **حمل سربرگ زنده:** اگر سربرگ صفحهٔ مقصد همان منو را داشته باشد (امضای `navChromeSignature`: کلاس‌ها + برچسب آیتم‌ها)، گرهٔ سربرگ hydrate‌شدهٔ فعلی به‌جای نسخهٔ تازه در جای خود می‌ماند و فقط `href`ها، `aria-current` و کلاس‌های `current-menu-*` از صفحهٔ تازه همگام می‌شوند (`navSyncChromeState`). اگر overlay باز باشد، از طریق دکمهٔ بستن خودِ بلوک بسته می‌شود تا وضعیت داخلی هسته (`aria-expanded`، بازگشت فوکوس، `html.has-modal-open`) سازگار بماند.
2. **قرارداد ایمنی:** اگر صفحهٔ مقصد ناحیهٔ تعاملی دیگری خارج از سربرگ داشته باشد (لایت‌باکس تصویر، صفحه‌بندی پیشرفتهٔ Query، بلوک‌های ووکامرس) یا سربرگ متفاوتی داشته باشد، `navHydrationSafe` ناوبری را به مرورگر برمی‌گرداند (بارگذاری بومی؛ پخش‌کننده از `sessionStorage` بازیابی می‌شود). صفحاتی که خودشان `data-wp-router-region` دارند اصلاً رهگیری نمی‌شوند.
3. **استایل‌ها:** قالب‌های بلوکی CSS هر بلوک/چیدمان را فقط برای همان مسیر چاپ می‌کنند؛ `navMergeStyles` شیت‌های جدید را قبل از نقاشی اضافه می‌کند (بدون حذف قبلی‌ها) و `<style id="…">`های درون‌خطی (مثل `core-block-supports-inline-css`) را بر اساس id ادغام می‌کند.
4. **اسکریپت‌ها:** `navRunScripts` اسکریپت‌های تازه را به ترتیب سند و با انتظار برای بارگذاری هر فایل اجرا می‌کند (حفظ ترتیب وابستگی WordPress) و رویداد `mw-page-rendered` فقط پس از اتمام همهٔ آن‌ها ارسال می‌شود.
5. **`<html>`:** `lang`، `dir` و `data-mw-scheme` از صفحهٔ تازه همگام می‌شوند تا تشخیص حالت روشن/تیره در صفحات با تنوع سبک متفاوت درست بماند.
6. **تاریخچه:** ورودی‌های `pushState` مقدار `wpInteractivityId` هسته را حمل می‌کنند؛ بازگشت/جلو دوباره ناوبری نرم است، نه reload اجباری.

**آزمون:** اسکریپت دود jsdom با ۳۳ بررسی (حمل سربرگ، همگام‌سازی آیتم جاری، بستن overlay، ادغام استایل، اجرای اسکریپت جدید و حذف تکراری، ترتیب رویداد، fallback برای لایت‌باکس/سربرگ متفاوت/router-region، بازگشت با popstate) + دود پخش‌کننده (۲۷) + دود سوییچ پوسته (۸) همه سبز؛ `npm run lint:js` (ESLint + wp-prettier واقعی پروژه) بدون خطا.


---

## ۶. دور سوم — نشانی‌ها، صفحهٔ تک‌انتشار، قفسه و گالری فهرست‌های پخش (۶ سپتامبر ۲۰۲۶)

### ۶.۱ تحلیل (یافته‌های تأییدشده از کد)

| موضوع | ریشهٔ مشکل (فایل:خط) |
| --- | --- |
| همهٔ انتشارها زیر `/music/…` | `ReleasePostType.php:64` فقط `rewrite.slug = music` را ثبت می‌کند؛ هیچ فیلتر `post_type_link`، `request` یا `add_rewrite_rule` سفارشی در هسته وجود ندارد. نوع انتشار یک طبقه‌بندی سلسله‌مراتبی (`mw_release_type`) با اسلاگ‌های `track single ep album mix playlist podcast_show podcast_episode` است (`ReleaseTaxonomies.php:98–105`). |
| صفحهٔ تک‌انتشار در موبایل/تبلت | `collections.css:104–120` در ≤۴۸rem ردیف را به ستون تبدیل می‌کند و `__main` را wrap می‌کند؛ نتیجه: نام هنرمند و مدت‌زمان به‌صورت نامنظم زیر هم می‌افتند و اکشن‌ها (پیش‌نمایش + گروه دانلود) با فاصلهٔ اضافه در خط جدا می‌نشینند. سربرگ جدول فقط در ≥۴۸rem نمایش داده می‌شود و ستون‌های `22% / 4.2rem / 6.5rem` برای تبلت (۴۸–۶۴rem) تنگ است. |
| بخش دیدگاه‌ها | `ReleasePostType.php:68` ویژگی `comments` را پشتیبانی نمی‌کند؛ `single-mw_release.html` هیچ بلوک `core/comments` ندارد؛ در کل پوسته هیچ CSS برای `.wp-block-comments`، `.comment-form` و `.wp-block-post-comments-form` وجود ندارد. |
| دکمه‌های `.mw-release-shelf__nav-button` | `slider.js:292–313` با فرض LTR کار می‌کند: `atStart = scrollLeft <= 2` و `move()` مقصد را در `[0, max]` قفل می‌کند. در RTL مقدار `scrollLeft` از `0` (ابتدا) به سمت **منفی** می‌رود؛ پس «قبلی» همیشه غیرفعال و «بعدی» بی‌اثر است. اسلایدر کلاسیک به‌دلیل `direction:ltr` روی `.mw-release-slider__viewport` (`slider.css:67`) از این باگ در امان مانده؛ قفسه چنین override‌ای ندارد. |
| گالری `.mw-playlists__art-grid` | یک شبکهٔ ۲×۲ ساده (`playlists.css:247–295`) که در سه جا استفاده می‌شود (فهرست حساب کاربری، بلوک `public-playlists` با چهار شکل تصویر، و بازسازی سمت کلاینت). باگ: `playlists.js:503–505` کلاس‌های ناموجود `mw-public-playlists__art-grid(--empty)` تولید می‌کند → پس از جست‌وجو/صفحه‌بندی کارت‌ها بدون استایل می‌شوند. |

### ۶.۲ تصمیم‌های طراحی

1. **نشانی‌های نوع‌محور (استاندارد و قابل پیش‌بینی):** نگاشت اسلاگ نوع → پیشوند نشانی به‌صورت فیلترپذیر (`music_wave_release_permalink_bases`):

   | نوع انتشار | پیشوند نشانی | نمونه |
   | --- | --- | --- |
   | `track`, `single` | `/track/` | `/track/midnight-drive/` |
   | `album` | `/album/` | `/album/night-signals/` |
   | `ep` | `/ep/` | |
   | `mix` | `/mix/` | |
   | `playlist` | `/playlist/` | |
   | `podcast_show` | `/podcast/` | |
   | `podcast_episode` | `/episode/` | |
   | بدون نوع / نوع ناشناخته | `/music/` (پیشوند قبلی) | سازگاری کامل با گذشته |

   انتخاب نوع: اولین اصطلاح شناخته‌شده در ترتیب اولویت بالا (اگر یک قطعه هم `single` و هم `track` داشته باشد، نتیجه یکسان است). قوانین بازنویسی برای هر پیشوند دقیقاً مثل قوانین هستهٔ وردپرس (`/base/slug/`, `/page/N`, `/embed`, `/feed`, `/trackback`, `attachment`) با `add_rewrite_rule(...,'top')` ثبت می‌شود و مسیرهای قبلی `/music/<slug>/` همچنان resolve می‌شوند و با **۳۰۱** به نشانی کانونیک منتقل می‌گردند (الگوی `ReleaseArchiveQuery::redirect_release_search`؛ فقط برای `is_singular('mw_release')` و نه برای feed/embed/preview/صفحه‌بندی داخلی). آرشیو نوع (`/release-type/<slug>/`) دست‌نخورده می‌ماند.
   **ثبات نشانی:** به‌جای مهاجرت پایگاه داده (طرح‌واره تغییری نمی‌کند)، `ReleasePermalinks` اثر انگشت نقشهٔ پایه‌ها را در گزینهٔ `music_wave_release_permalink_rules` نگه می‌دارد و روی `wp_loaded` در صورت تغییر یک بار `flush_rewrite_rules(false)` می‌زند؛ پس نصب‌های موجود در همان درخواست اول پس از به‌روزرسانی بدون «ذخیرهٔ دستی پیوندهای یکتا» به‌روز می‌شوند. `Activator` هم قوانین را پیش از flush ثبت می‌کند و اثر انگشت را می‌نویسد. تغییر نوع یک انتشار در ویرایشگر خودبه‌خود نشانی جدید می‌گیرد و نشانی قدیمی ۳۰۱ می‌شود.
2. **صفحهٔ تک‌انتشار:** طراحی موبایل‌محور برای لیست قطعه با سه حالت — موبایل (≤۴۸rem): کارت دو خطی «شماره/جلد | عنوان + هنرمند · مدت» با اکشن‌ها در انتهای همان ردیف؛ تبلت (۴۸–۶۴rem): ستون کیفیت پنهان، ستون هنرمند انعطاف‌پذیر؛ دسکتاپ: جدول کامل. فقط CSS تغییر می‌کند (مارک‌آپ PHP و قلاب‌های `data-mw-playing`، `.mw-equalizer`، `__position-play` حفظ می‌شوند). دیدگاه‌ها با بلوک‌های استاندارد `core/comments` (قالب دیدگاه، صفحه‌بندی، فرم) به `single-mw_release.html` اضافه و در ماژول جدید `components/comments.css` با توکن‌های پوسته (دو حالت روشن/تیره) استایل می‌شوند؛ پشتیبانی `comments` به CPT اضافه می‌شود (مدیر می‌تواند از «تنظیمات > گفت‌وگو» یا هر انتشار، دیدگاه را ببندد).
3. **قفسهٔ اسکرولی:** محاسبات منطقی جهت‌آگاه در `slider.js` (`Math.abs(scrollLeft)` به‌عنوان موقعیت منطقی؛ `scrollTo({left: rtl ? -x : x})`) برای هر دو مسیر اسلایدر و قفسه؛ آیکون‌های SVG شِورون (مانند هیرو اسلایدر) به‌جای گلیف متنی و آینه‌سازی در RTL؛ در دستگاه‌های لمسی دکمه‌های فعال دیده می‌شوند.
4. **گالری فهرست پخش:** طرح «پشتهٔ جلدهای بادبزنی» تماماً با CSS — چهار جلد به‌صورت کارت‌های چیده‌شده روی هم با چرخش/جابه‌جایی ظریف، جلد اول در جلو و بزرگ‌تر، سایهٔ عمقی، انیمیشن باز شدن بادبزن در hover/focus (با `prefers-reduced-motion`)، حالت خالی با نُت و گرادیان آکسنت. برای شکل‌های `landscape`/`portrait` و حالت فهرستی ۵rem همان پشته با پارامترهای کوچک‌تر، برای `circle` پشتهٔ دایره‌ای. باگ کلاس‌های `playlists.js` رفع می‌شود و REST `GET /playlists/public` فیلد `covers` (حداکثر ۴ نشانی بندانگشتی منتشرشده) را برمی‌گرداند تا کارت‌های بازسازی‌شده هم جلد واقعی داشته باشند.

### ۶.۳ برنامهٔ اجرا

| # | فایل‌ها | کار |
| --- | --- | --- |
| ۱ | `src/Catalog/ReleasePermalinks.php` (جدید)، `Modules/Catalog.php`، `Lifecycle/Activator.php`، `Plugin.php`، `tests/run.php` | نشانی‌های نوع‌محور + قوانین + ۳۰۱ + flush خودکار (بدون مهاجرت طرح‌واره؛ CI دست‌نخورده می‌ماند) |
| ۲ | `ReleasePostType.php`، `templates/single-mw_release.html`، `assets/css/components/comments.css` (جدید)، `functions.php` (ثبت ماژول) | دیدگاه‌ها |
| ۳ | `collections.css`، `catalog.css` | لیست قطعه و هیرو/اکشن‌ها در موبایل و تبلت |
| ۴ | `slider.js`، `shelf.css`، `functions.php` (آیکون‌ها) | قفسهٔ اسکرولی RTL |
| ۵ | `playlists.css`، `playlists.js`، `PlaylistRoutes.php` | گالری بادبزنی + رفع باگ کلاینت + `covers` |
| ۶ | نسخه‌ها (`music-wave-core` → `0.12.0`، `musicwave` → `0.8.1`)، `release-manifest.json`، readmeها، `PROJECT_PLAN.md`، `docs/local-testing-fa.md`، PO/MO/POT | مستندسازی و انتشار |

### ۶.۴ معیارهای پذیرش

- `get_permalink()` یک آلبوم → `/album/<slug>/`، قطعه/تک‌قطعه → `/track/<slug>/`، بدون نوع → `/music/<slug>/`؛ درخواست `/music/<slug>/` برای آلبوم با ۳۰۱ به `/album/<slug>/` می‌رود؛ آرشیو `/music/` و `/release-type/album/` بدون تغییر.
- در عرض ۳۶۰px هیچ سرریز افقی در صفحهٔ تک‌انتشار نیست؛ هر ردیف قطعه حداکثر دو خط متن + ردیف اکشن‌ها با هدف لمسی ≥۴۰px.
- بخش دیدگاه‌ها زیر محتوای انتشار، با فرم استایل‌شده، دکمهٔ ارسال پیل‌شکل، آواتار، پاسخ‌ها و صفحه‌بندی؛ در حالت روشن متن تیره.
- در قفسهٔ اسکرولی RTL «بعدی» به سمت قطعه‌های بعدی می‌رود، «قبلی» پس از حرکت فعال می‌شود و در انتها «بعدی» غیرفعال می‌شود.
- گالری فهرست پخش در حساب کاربری، بلوک عمومی (چهار شکل) و کارت‌های بازسازی‌شده با JS یکسان و استایل‌دار است.
- همهٔ دروازه‌ها سبز: `tests/run.php`، `template-integrity.php`، `check-templates.php`، `check-site-editor.php`، `check-script-translations.php`، `npm run lint:js`، بررسی نحو PHP.

## ۷. دور چهارم — جست‌وجوی فارسی و ماژول «درخواست آهنگ سفارشی و همکاری» (۷ سپتامبر ۲۰۲۶)

### ۷.۱ تحلیل (یافته‌های تأییدشده از کد و هستهٔ وردپرس ۶.۸)

- **چرا جست‌وجوی فارسی «نتیجه‌ای پیدا نشد» می‌داد؟** `WP_Query::parse_search()` هر کلمه را فقط با `LIKE '%کلمه%'` روی `post_title/post_excerpt/post_content` می‌سنجد و مقایسه بایت‌به‌بایت است. در متن فارسی همان کلمه چند شکل بایتی دارد: «ي/ی» و «ك/ک» (کیبورد عربی در برابر فارسی)، نیم‌فاصله در برابر فاصله («آهنگ‌های» ↔ «آهنگ های»)، اعداد فارسی/عربی/لاتین، اعراب و کشیده، و «ۀ/ه». هر ناهمخوانی یعنی صفر نتیجه. علاوه بر آن، کلمه‌ای که فقط در نام هنرمند/ژانر/حال‌وهوا یا متادیتای انتشار (آلبوم، شمارهٔ کاتالوگ) وجود دارد اصلاً جست‌وجو نمی‌شد، چون هسته ترم و متا را نمی‌گردد. قالب و افزونه هیچ لایه‌ای برای این کار نداشتند؛ فقط اتوکامپلیت `CatalogSearch` وجود داشت که آن هم با `get_posts()` (و `suppress_filters` روشن) جست‌وجوی خام می‌کرد.
- **ماژول درخواست** از صفر لازم بود: هیچ CPT، فرم، ایمیل یا صفحهٔ مدیریتی برای «درخواست» وجود نداشت. الگوهای موجود که باید حفظ می‌شد: ماژول‌ها پیاده‌سازی `Contracts\Module` و ثبت در `Plugin::boot()`؛ فرم‌های بدون JS با `admin-post.php` + نانس + PRG (`PlaylistFormHandler`)؛ محدودکنندهٔ نرخ `DiscoveryRateLimiter`؛ بلوک‌های پویا با `block.json` + ثبت در `Rendering::editor_blocks()` + `blocks.js` (شمارش ۲۷ بلوک در دو دروازه)؛ زیرمنوی مدیریت زیر `edit.php?post_type=mw_release` (`SettingsPage`)؛ VIP زیرمنوی خود را با اولویت ۱۰ و اسلاگ `music-wave-vip` اضافه می‌کند.

### ۷.۲ تصمیم‌های طراحی

- **جست‌وجو در لایهٔ SQL، نه در PHP پس از کوئری.** `ScriptAwareSearchQuery` روی `posts_search` (و `posts_search_orderby`) فقط برای کوئری اصلی، کوئری‌های محدود به `mw_release` و کوئری‌هایی که با `mw_script_search` opt-in کرده‌اند اجرا می‌شود؛ فقط وقتی عبارت خط عربی دارد (یا کوئری کلیدهای متای خودش را داده) — عبارت لاتین دست‌نخورده می‌ماند. هر کلمه به حداکثر ۶ املای هم‌ارز (`PersianSearchNormalizer::variants()`) گسترش می‌یابد که با OR ترکیب و کلمات با AND حفظ می‌شوند؛ `-کلمه`، `exact`، `search_columns`، فیلتر پیشوند حذف و شرط `post_password` مهمان‌ها همگی مطابق هسته حفظ شده‌اند. ترم‌ها و متا با `ID IN (SELECT …)` جست‌وجو می‌شوند تا JOIN بیرونی، `GROUP BY` و صفحه‌بندی هیچ افزونهٔ دیگری به هم نریزد. همه‌چیز از `$wpdb->prepare()`/`esc_like()` می‌گذرد و سقف ۸ کلمه × ۶ املا دارد. فهرست تاکسونومی‌ها و کلیدهای متا با دو فیلتر عمومی و دو متغیر کوئری (برای محدودکردن به یک کوئری خاص) قابل تنظیم است — همان مکانیزم برای جست‌وجوی صندوق درخواست‌ها روی نام/ایمیل/تلفن استفاده شده است.
- **ذخیره‌سازی درخواست‌ها با CPT خصوصی + وضعیت‌های ثبت‌شده** (نه جدول جدید): نیازی به مهاجرت و تغییر نسخهٔ اسکیمای `0.11.0` نیست، از پشتیبان‌گیری/صادرات/ابزار حریم خصوصی وردپرس بهره می‌برد، و وضعیت روی ستون ایندکس‌شدهٔ `post_status` فیلتر می‌شود. CPT هیچ UI/REST/بازنویسی/جست‌وجویی ندارد؛ تنها راه دیدن آن صفحهٔ مدیریت با قابلیت متای `manage_mw_requests` است (پیش‌فرض `manage_options`، قابل تغییر با فیلتر).
- **فرم عمومی progressive**: بدون JS کامل کار می‌کند (POST به `admin-post.php`, PRG با ۳۰۳)، خطاها و مقادیر در ترنزینت ۱۰ دقیقه‌ای با کلید تصادفی می‌مانند (نه در URL)، honeypot + زمان‌سنج حداقل پرکردن + محدودیت نرخ هر بازیگر، اعتبارسنجی خالص در `RequestSubmission::validate()` (تست‌پذیر بدون وردپرس)، نانس منقضی به‌جای `wp_die()` با پیام دوستانه برمی‌گردد (کش کامل‌صفحه). طراحی: کارت شیشه‌ای دوستونه با پنل کناری (مزایا + مراحل)، چیپ‌های نوع درخواست، فیلدهای بزرگ، دکمهٔ ارسال گرادیانی با آیکون، حالت موفقیت با شمارهٔ پیگیری؛ همه با توکن‌های قالب و پشتیبانی روشن/تیره و `prefers-reduced-motion`.
- **ایمیل از یک نقطه** (`RequestMailer`): رسید برای درخواست‌دهنده، هشدار برای مدیران با Reply-To درخواست‌دهنده، و پاسخ مدیر؛ همه از فیلتر `music_wave_request_message` می‌گذرند تا بتوان به هر سرویس دیگری متصل کرد یا خاموش کرد. شکست `wp_mail` در تاریخچه ثبت می‌شود و مانع ذخیرهٔ درخواست نمی‌شود.
- **صفحهٔ مدیریت**: زیرمنوی MusicWave در اولویت ۹ ثبت و در اولویت ۹۹ با تابع خالص `reorder()` دقیقاً بالای `music-wave-vip` (یا انتهای منو اگر VIP نباشد) قرار می‌گیرد؛ نشان تعداد جدیدها؛ آمار؛ تب وضعیت‌ها؛ فیلتر نوع/نقش/اولویت/جست‌وجو؛ اقدام گروهی؛ صفحهٔ جزئیات با پاسخ ایمیلی و قالب‌های آماده، یادداشت داخلی، تغییر وضعیت/اولویت، پذیرش/رد/بایگانی، ویرایش فیلدها، تاریخچه، حذف دومرحله‌ای (بدون `confirm()`); ثبت دستی؛ تب تنظیمات با Settings API؛ راهنمای بالا‌ی صفحه روی `load-{hook}` (چون `render_screen_meta()` در هدر ادمین قبل از callback اجرا می‌شود). CSS ادمین با توکن‌های خود پیشخوان وردپرس، بدون وابستگی به قالب.

### ۷.۳ برنامهٔ اجرا

۱. `Discovery\PersianSearchNormalizer` (تا‌کردن، گونه‌ها، توکن‌ساز) و `Discovery\ScriptAwareSearchQuery` (بازنویسی `WHERE`/`ORDER BY`)، سیم‌کشی در `Modules\Catalog`؛ `CatalogSearch` با `suppress_filters => false` و تطبیق نرمال‌شده.
۲. ماژول `Requests`: `RequestPostType`, `RequestSubmission`, `RequestRepository`, `RequestSettings`, `RequestMailer`, `RequestFormHandler`, `RequestsAdminPage`, `RequestPrivacy`; بلوک `music-wave/request-form` (`block.json` + `RequestFormBlock` + `Rendering` + `blocks.js`)؛ دارایی‌های `request-form.*` و `admin-requests.*`.
۳. قالب: الگوی `page-requests.html`، پترن `request-cta`، `theme.json` و نقشهٔ عنوان/تعمیر در `functions.php`.
۴. تست‌ها در `tests/run.php` (دو بخش جدید)، به‌روزرسانی شمارش بلوک در دروازه‌ها، اسناد، نسخه‌ها، POT/PO/JSON ترجمه.

### ۷.۴ معیارهای پذیرش

- جست‌وجوی «آهنگ‌های پاییزی» با «ي» عربی، با فاصلهٔ معمولی یا با اعداد فارسی همان انتشار را برمی‌گرداند؛ کلمه‌ای که فقط نام هنرمند/ژانر است انتشارهایش را می‌آورد؛ عبارت لاتین بدون تغییر رفتار می‌کند؛ SQL تولیدی فقط زیرکوئری دارد و شرط رمز مهمان حفظ می‌شود.
- فرم درخواست بدون JS کار می‌کند، خطاها فیلد‌به‌فیلد نمایش داده می‌شوند، مقادیر حفظ می‌شوند، رسید و هشدار ارسال می‌شود، نرخ ۳/ساعت اعمال می‌شود و نانس منقضی `wp_die` نمی‌دهد.
- آیتم «درخواست‌ها و همکاری» با نشان، دقیقاً بالای «MusicWave VIP»؛ همهٔ عملیات فهرست/جزئیات/گروهی/تنظیمات با نانس و قابلیت محافظت شده‌اند؛ صادرات/پاک‌کردن حریم خصوصی کار می‌کند.
- هیچ تغییری در اسکیمای پایگاه داده (`0.11.0`)؛ ۲۷ بلوک در دروازه‌ها؛ همهٔ دروازه‌ها سبز: `tests/run.php`، `template-integrity.php`، `check-templates.php`، `check-site-editor.php`، `check-script-translations.php`، `npm run lint:js`، بررسی نحو PHP.

## ۸. دور پنجم — لایهٔ طراحی «سرمقاله‌ای × وینیل» و انتخاب سبک در تنظیمات هر بلوک (۱۰ سپتامبر ۲۰۲۶)

### ۸.۱ تحلیل (یافته‌های تأییدشده از کد)

- **زبان طراحی مرجع فقط در فایل‌های HTML ریشه وجود داشت.** پنج ماکاپ ریشه (`home_editorial_music_streaming.html`، `wave_neo_seoul_soundscapes_single_album_vinyl_tracklist.html`، `wave_neo_seoul_soundscapes_single_album_mobile.html`، `wave_search_browse_mobile.html`، `wave_midnight_city_lights_single_track_live_lyrics.html`) سه ایدهٔ تکرارشونده دارند: (۱) سرصفحهٔ مجله‌ای با «برچسب ریز فاصله‌دار + خط گرادیانی»، (۲) وینیل فیزیکی (غلاف + صفحهٔ بیرون‌زننده، نوارهای فرکانس/اکولایزر روی ردیف در حال پخش، فهرست قطعهٔ شماره‌دار)، (۳) کارت‌های شیشه‌ای با دادهٔ فنی (Bento و Spec strip). هیچ‌کدام از این‌ها در CSS قالب وجود نداشت.
- **سیستم «سبک بلوک» فقط برای بلوک‌های افزونه بود.** `Rendering::register_block_styles()` در هسته شش گروه سبک داشت (پیش‌نمایش‌پخش‌کننده، دکمهٔ پیش‌نمایش، داشبورد، متادیتا، فیلترها، فهرست‌های پخش) اما بلوک‌های نمایشی قالب (`music-wave/release-shelf` و `music-wave/release-slider`) هیچ سبکی نداشتند؛ فقط `layout` داشتند. یعنی ادمین نمی‌توانست ظاهر کارت‌ها را انتخاب کند.
- **دو نقص واقعی که در مسیر پیدا شد:** (الف) سبک «stacked» داشبورد کاربری هرگز CSS نداشت و رندرکننده به‌جای `mw-user-dashboard--stacked` کلاس بی‌معنی `stacked` چاپ می‌کرد؛ (ب) در `tests/run.php` انتظار `class="mw-request-form mw-request-form--split"` (با گیومهٔ بسته در انتهای کلاس) مانده بود، در حالی که رندرکننده از مدت‌ها پیش `mw-request-form--mode-{mode}` را هم می‌افزاید؛ همین assertion باعث می‌شد `composer test` (و در نتیجه CI) قرمز بماند.

### ۸.۲ تصمیم‌های طراحی

- **لایهٔ افزودنی، نه بازنویسی.** دو فایل تازه (`components/editorial.css` و `components/vinyl.css`) فقط واژگان تازه را اضافه می‌کنند و هیچ کامپوننت SonicStream را بازتعریف نمی‌کنند؛ همه‌چیز از توکن‌های `tokens.css` می‌آید و از پالت قابل ویرایش در ویرایشگر سایت استفاده می‌کند. توکن‌های تازه: `--mw-color-editorial(-soft/-line)`، `--mw-color-gold`، خانوادهٔ `--mw-color-vinyl(-deep/-groove/-label)`، `--mw-color-glass(-border)`، `--mw-gradient-editorial`، `--mw-shadow-lift`، `--mw-eyebrow-tracking` و `--mw-speed-slow`؛ همه در هر دو حالت روشن/تیره بازتنظیم می‌شوند.
- **بدون CSS یتیم.** هر کلاس این دو فایل توسط یک رندرکننده، پوسته یا الگوی همراه تولید می‌شود؛ اکولایزر «در حال پخش» همان `.mw-equalizer` موجود در `global-player.css` است و نسخهٔ دومی ندارد، و توکن‌های بی‌مصرف (`--mw-eq-speed`، `--mw-gradient-vinyl-sheen`، `--mw-ring-focus`، `--mw-color-glass-strong`) و بخش‌های بی‌تولیدکننده (Spotlight، Bento، Tracklist، Side-divider، Sleeve standalone، Equalizer دوم) حذف شدند. واژگانی که ماند، مصرف واقعی دارد: چیپها روی `core/post-terms`، نوار مشخصات و متابار در رندرکنندههای زندهٔ `release-meta`/`collection-list` (که الگوهای `demo-*.php` میچینند)، و آستین وینیل روی کاور صفحهٔ تکانتشار.
- **یک منبع حقیقت برای هر دو سطح انتخاب.** سبک‌ها هم در پنل «سبک‌ها» (Styles) ثبت می‌شوند (`register_block_style`) و هم به‌صورت یک `SelectControl` در پنل تنظیمات خودِ بلوک ظاهر می‌شوند؛ هر دو سطح همان کلاس `is-style-<slug>` را می‌نویسند تا هیچ‌وقت با هم اختلاف نداشته باشند. صفت `styleVariant` به‌عنوان «پیش‌تنظیم الگو» باقی مانده است (الگوها می‌توانند ظاهر اولیه بدهند) و رندرکننده اول کلاس و بعد صفت را می‌خواند.
- **سبک بدون CSS ممنوع.** نگاشت سبک‌ها از رجیستری PHP خوانده می‌شود و `tests/template-integrity.php` برای هر اسلاگ ثبت‌شده، وجود قاعدهٔ CSS و برچسب ترجمه‌شدنی را الزام می‌کند؛ بنابراین یک برچسب بدون ظاهر نمی‌تواند منتشر شود.
- **سبک‌ها روی همهٔ چیدمان‌ها اثر می‌گذارند.** برای چیدمان `feature` و `slider` هم قاعده نوشته شد تا انتخاب سبک هرگز بی‌اثر نباشد.
- **RTL و حرکت.** همه‌جا ویژگی‌های منطقی (`inset-inline`, `border-inline-start`) و بودجهٔ حرکت در انتهای هر دو فایل (`prefers-reduced-motion`) رعایت شده است؛ در وینیل، جهت بیرون‌آمدن صفحه با `--mw-vinyl-out` و `html[dir="rtl"]` قرینه می‌شود.

### ۸.۳ برنامهٔ اجرا (انجام‌شده)

۱. توکن‌های تازه در `tokens.css` (شامل بازتنظیم‌های روشن/تیره).
۲. `components/editorial.css`: برچسب ریز، سرصفحهٔ بخش، نوار چیپ، Metabar، Spec strip، Liner notes، Spotlight، Bento و سبک‌های `--editorial/--minimal` قفسه و `--cinema/--editorial` اسلایدر + بخش «سبک‌های بلوک‌های هسته» (مهر متادیتا، خط نتیجهٔ سرمقاله‌ای، ردیف بخش‌های مرتبط، نورافکن هنرمند).
۳. `components/vinyl.css`: کامپوننت غلاف+صفحه، اکولایزر، فهرست قطعه، جداکنندهٔ وینیل، قفسهٔ `--vinyl` و `--bento`، فهرست `--tracklist`، دکمهٔ `--vinyl`، فهرست‌های پخش `--vinyl` و میکروحرکت‌های کاهش‌یافته.
۴. `functions.php`: تابع `musicwave_presentation_style_variations()` (منبع حقیقت)، `musicwave_style_variant()` و `musicwave_style_variant_class()`، ثبت سبک‌ها برای نام کانونیک و نام قدیمی، تزریق کلاس تغییر در رندرکننده‌های قفسه/ویژه‌نامه/اسلایدر هیرو/ویترین فهرست پخش، ثبت دو ماژول CSS تازه.
۵. `assets/editor-blocks.js`: پنل «استایل و ظاهر» با `SelectControl` که همان کلاس `is-style-*` را می‌نویسد (خریدار بدون دانش فنی هم سبک را می‌بیند).
۶. هسته: استخراج `Rendering::style_variations()` به‌عنوان منبع واحد، ثبت سبک‌های تازه (`tracklist`, `stamp`, `editorial`, `spotlight`, `vinyl`) و محلی‌سازی نقشه برای ویرایشگر؛ `assets/blocks.js` پنل «استایل و ظاهر» را برای هر بلوکی که سبک دارد می‌سازد.
۷. رندرکننده‌های هسته: `mw-collection-list--tracklist`، `mw-release-meta--stamp`، `mw-catalog-results--editorial`، `mw-related-releases--editorial/--vinyl`، `mw-artist-profile--spotlight`، `mw-preview-button--vinyl`، `mw-public-playlists--vinyl` و اصلاح `mw-user-dashboard--stacked`.
۸. الگوها و پوسته: در آن دور الگوهای `music-home-layout` (اسلایدر سینمایی + قفسهٔ وینیل + ویژهنامهٔ سرمقالهای)، `editorial-feature`، `curated-release-shelf`، `horizontal-release-shelf`، `featured-release-slider`، `musicwave/vinyl-record-shelf` و `musicwave/album-spec-sheet` (چیپ تاکسونومی + نوار مشخصات + یادداشت انتشار، همه از بلوکهای پویا) اضافه شدند. آن هفت الگو بعداً حذف شدند. کتابخانهٔ فعلی سیزده الگو دارد: هفت الگوی بخش (`home-hero`، `home-showcase-rail`، `genre-mood-browse`، `music-service-callout`، `release-card`، `release-grid`، `section-faq`) و شش «دمو»ی تمامصفحه که هرکدام یک سبک کامل از سایت را نشان میدهد (`demo-account-studio`، `demo-account-commerce`، `demo-release-showcase`، `demo-artists-directory`، `demo-discovery-browse`، `demo-community-requests`)؛ دموها با دستهٔ «دموهای MusicWave» در درجکننده میآیند و از `src/Patterns/DemoComposer.php` برای سربرگ مشترک استفاده میکنند. `single-mw_release.html` برگهٔ مشخصات را زیر قهرمان صفحه می‌گذارد، فهرست قطعه‌ها را با `is-style-tracklist` رندر می‌کند و کاور را به آستین وینیل با صفحهٔ بیرون‌زننده تبدیل می‌کند (CSS-محور، بدون تغییر نشانه‌گذاری).
۹. تست‌ها: بخش «قرارداد سبک‌ها» در `tests/template-integrity.php` (هر اسلاگ ⇒ CSS + برچسب + حضور در هر دو سطح ویرایشگر، و نگاشت بلوک‌های هسته) و اصلاح assertion قدیمی فرم درخواست.
۱۰. ترجمه‌ها: ۲۶ پیام تازه در `musicwave-en_US.po` / `music-wave-core-en_US.po`، بازتولید POT/MO و شش کاتالوگ JSON.

### ۸.۴ معیارهای پذیرش

- ادمین در هر بلوک، هم از پنل «سبک‌ها» و هم از پنل «استایل و ظاهر» در تنظیمات همان بلوک، می‌تواند ظاهر را انتخاب کند و هر دو سطح هم‌زمان عوض می‌شوند.
- هر سبک ثبت‌شده یک قاعدهٔ CSS واقعی دارد؛ انتخاب سبک در چیدمان‌های grid/list/scroll/feature/slider دیده می‌شود.
- هر دو حالت روشن و تیره، RTL و `prefers-reduced-motion` پوشش داده شده‌اند؛ رنگ‌ها از پالت ویرایشگر سایت می‌آیند.
- دروازه‌ها سبز: `tests/run.php` (شامل `template-integrity.php`)، `tools/check-templates.php`، `tools/check-site-editor.php`، `tools/check-script-translations.php`، بررسی نحو PHP و `npm run lint:js`.
