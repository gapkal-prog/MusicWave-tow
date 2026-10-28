# پرامپت اجرای PHPStan سطح ۵ روی سیستم محلی

این پرونده برای دو کار است:

1. اجرای دقیق همان دروازه‌ای که در محیط توسعهٔ ابری قابل اجرا نیست (`phpstan analyse`).
2. یک **پرامپت آمادهٔ کپی** که به یک عامل هوشمند (یا خودتان) بدهید تا یافته‌ها را به‌صورت استاندارد برطرف کند.

> چرا محلی؟ نصب `phpstan.phar` و وابستگی‌های Composer در سندباکس مسدود است. بنابراین سطح ۵ تنها دروازه‌ای است که باید روی ماشین خودتان اجرا شود.

---

## ۱) پیش‌نیازها

| نیاز | نسخه |
| --- | --- |
| PHP | ۸.۱ یا بالاتر (با افزونه‌های `mbstring`, `json`, `tokenizer`) |
| Composer | ۲.x |
| Node | فقط برای دروازه‌های JS (`node --check` کافی است) |

```bash
cd /path/to/MusicWave-project-tow
composer install            # phpstan + wordpress/woocommerce stubs + phpcs
```

`phpstan.neon` از قبل در ریشهٔ پروژه هست (سطح ۵، `bootstrapFiles: tools/phpstan-bootstrap.php`، `treatPhpDocTypesAsCertain: false`). نیازی به ساختن تنظیمات تازه نیست.

---

## ۲) اجرای دروازه

```bash
composer check:phpstan
```

معادل دستی:

```bash
vendor/bin/phpstan analyse \
  --configuration=phpstan.neon \
  --memory-limit=-1 \
  --no-progress \
  --error-format=table
```

خروجی ماشین‌خوان برای ارسال به عامل:

```bash
vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=-1 --no-progress \
  --error-format=json > phpstan-report.json
```

سپس دروازه‌های دیگر (همان‌ها که در سندباکس سبز بودند) را هم یک‌بار محلی بزنید:

```bash
composer check:syntax
composer check:templates
composer check:site-editor
composer check:strict-types
composer check:nul-bytes
composer check:abspath
composer check:js
composer check:translations
composer check:script-translations
composer test
php tools/generate-pot.php && php tools/sync-po.php && php tools/generate-mo.php && php tools/generate-script-json.php
```

---

## ۳) پرامپت آمادهٔ کپی

> متن زیر را کامل کپی کنید و همراه با `phpstan-report.json` (یا خروجی متنی) به عامل بدهید.

```text
نقش: مهندس ارشد PHP روی پروژهٔ WordPress «MusicWave».
هدف: رساندن کل مخزن به PHPStan سطح ۵ با صفر خطا، بدون تغییر رفتار عمومی.

ورودی: گزارش PHPStan سطح ۵ (فایل phpstan-report.json یا خروجی جدولی).

قواعد اجباری:
1. هر یافته را در ریشه حل کن؛ هیچ ignore، baseline، `@phpstan-ignore`،
   `assert()` تزئینی یا `mixed` گریز اضافه نکن. اگر یافته از نقص stub است،
   در `tools/stubs/` یا `tools/phpstan-bootstrap.php` قرارداد درست را اضافه کن،
   نه اینکه کد تولید را با cast بی‌معنی آلوده کنی.
2. کد باید strict باشد: `declare(strict_types=1);` در همهٔ فایل‌های PHP،
   ترتیب `declare` → `namespace` → `use` حفظ شود (دروازهٔ check-strict-types).
3. نوع‌ها را دقیق بنویس: `array<string, mixed>` برای آرایه‌های ورودی وردپرس،
   `array{...}` برای شکل‌های ثابت، `?WP_Term` برای خروجی‌های nullable،
   و `@return array<int, array<string, mixed>>` برای درخت‌های بلوک.
4. الگوها: Clean Code، OOP، DRY، SOLID، Refactoring. کد تکراری یا مرده را
   حذف کن؛ کلاس/متد تازه فقط وقتی که یک مسئولیت واقعی را جدا کند.
5. هیچ رفتار کاربرپسندی عوض نشود: خروجی رندر بلوک‌ها، مارک‌آپ کلاس‌ها،
   i18n (`__()`, `esc_html__()`, textdomain درست)، و قراردادهای ARIA/آیکون.
6. امنیت: هر ورودی با `sanitize_*`/`absint`/`wp_unslash` پاک شود، هر خروجی با
   `esc_*` یا `wp_kses_post`؛ nonce و capability در مسیرهای نوشتن حفظ شود.
7. وردپرس: از APIهای موجود (`wp_json_encode`, `parse_blocks`, `serialize_blocks`,
   `get_block_template*`) استفاده کن؛ فراخوانی مستقیم `do_blocks()` در فیلتر
   `render_block` فقط با محافظ بازگشتی مجاز است.
8. پس از هر دستهٔ اصلاح، این‌ها باید سبز بمانند:
   composer check:syntax | check:templates | check:site-editor |
   check:strict-types | check:nul-bytes | check:abspath | check:translations |
   check:script-translations | composer test
9. اگر رشتهٔ ترجمه‌پذیر تازه/تغییری اضافه کردی:
   php tools/generate-pot.php && php tools/sync-po.php
   سپس msgstr انگلیسی را در `*/languages/*-en_US.po` پر کن (msgstr خالی =
   شکست check-translation-coverage) و بعد:
   php tools/generate-mo.php && php tools/generate-script-json.php
10. اگر فایل‌های بستهٔ `music-wave-core` تغییر کرد:
    `php tools/make-manifest.php` (یا اسکریپت معادل در مخزن) را اجرا کن تا
    `music-wave-core/MANIFEST.sha256` هم‌گام شود.
11. `theme.json` را هرگز دوباره سریالایز نکن؛ فقط درج جراحی‌شده.
    توکن رنگ پس‌زمینه `--mw-color-canvas` است، نه `--mw-color-background`.
12. در پایان یک جدول بده: فایل | شمارهٔ خط | خطای PHPStan | اصلاح | دلیل.
    هیچ یافته‌ای را «بعداً» رها نکن.

ترتیب پیشنهادی کار:
الف) یافته‌های `Access to an undefined property/function` → تکمیل stub یا
   استفاده از تابع درست وردپرس.
ب) یافته‌های `Parameter #n $x of ... expects ..., ... given` → تایپ دقیق
   پارامتر/بازگشت و حذف castهای اضافی.
ج) یافته‌های `Cannot access offset ... on mixed` → تنگ‌کردن نوع با
   `is_array`/`is_scalar`/`isset` پیش از دسترسی (نه `@var`).
د) یافته‌های `Method should return ... but returns ...` → مسیرهای بازگشتی
   زودهنگام را کامل کن.
ه) در آخر: کد مرده، پارامتر بی‌استفاده، و ارجاع‌های تکراری را پاک کن.

معیار پذیرش:
- `composer check:phpstan` با «[OK] No errors» تمام شود.
- همهٔ دروازه‌های بند ۸ سبز باشند.
- diff هیچ ignore/baseline تازه‌ای نداشته باشد.
```

---

## ۴) نکات مربوط به همین مخزن

- `tools/phpstan-bootstrap.php` ثابت‌های وردپرس (`ABSPATH`, `MINUTE_IN_SECONDS`,
  `MUSIC_WAVE_CORE_PATH`, …) را تعریف می‌کند و stubهای تقسیم‌شده را بار می‌کند؛
  اگر خطای «Constant … not found» دیدید، ثابت را همان‌جا اضافه کنید.
- کلاس‌هایی که به‌صورت داینامیک از وردپرس می‌آیند (`WP_Term`, `WP_Post`,
  `WP_Block_Type`) از stub می‌آیند؛ برای `WP_Block`/`WP_Block_Type_Registry`
  هم از همان مسیر استفاده کنید.
- یافته‌های مربوط به `SiteHeaderManager::shape_header_part_content()` باید با
  قرارداد `parse_blocks()`/`serialize_blocks()` سازگار بمانند؛ هر تغییر در آن
  مسیر باید درخت بلوک را متوازن نگه دارد (`innerContent` هم‌گام با `innerBlocks`).
