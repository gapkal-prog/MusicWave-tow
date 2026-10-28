# راهنمای تست نهایی و گزارش خطا — MusicWave

**برای زمانی که Fatal Error یا مشکل دیگر می‌بینید**

## 1. ارور `strict_types declaration must be the very first statement`

**علت:** در PHP، `declare(strict_types=1);` باید **اولین دستور** بعد از `<?php` باشد. اگر قبلش `if (!defined('ABSPATH'))` یا هر کد دیگری باشد، Fatal می‌دهد.

**رفع شد:** در این نسخه 158 فایل اصلاح شد — `declare` به خط دوم بعد از `<?php` منتقل شد، گارد ABSPATH بعدش.

**اگر باز هم دیدید:**
```bash
grep -n "ABSPATH" wp-content/plugins/music-wave-core/src/Support/Autoloader.php | head
# باید خط declare قبل از ABSPATH باشد:
# <?php
# declare(strict_types=1);
# if (!defined('ABSPATH')) exit;
```

اگر فایل قدیمی روی سرور دارید، پلاگین را دوباره از `dist/*.zip` نصب کنید (package-release.php).

---

## 2. چک‌لیست تست محلی (wp-env)

```bash
# 1. محیط را بالا بیاورید
npx @wordpress/env start
npx @wordpress/env run cli wp core version

# 2. سینتکس همه PHP ها
php tools/check-syntax.php
# یا
find music-wave-core music-wave-vip musicwave -name "*.php" -exec php -l {} \; | grep -v "No syntax errors"

# 3. ABSPATH و index.php
php tools/check-abspath.php

# 4. PHPCS و PHPStan (نیاز composer)
composer install
composer check:phpcs
composer check:phpstan

# 5. ترجمه‌ها
composer check:translations
php tools/check-translation-coverage.php
php tools/check-script-translations.php

# 6. Site Editor
composer check:site-editor || php tools/check-site-editor.php

# 7. تست‌های دامنه
composer test
php tests/run.php
php tests/download-e2e.php
php tests/dashboard-vip-test.php

# 8. بسته‌بندی و نصب تمیز
php tools/package-release.php --destination=dist
# سپس در wp-env:
npx @wordpress/env run cli wp plugin uninstall music-wave-core --deactivate
npx @wordpress/env run cli wp plugin install /path/to/dist/music-wave-core.zip --activate
npx @wordpress/env run cli wp theme install /path/to/dist/musicwave.zip --activate
```

---

## 3. فعال‌سازی WP_DEBUG برای دیدن خطاها

در `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
define('SCRIPT_DEBUG', true);
```

سپس:
```bash
npx @wordpress/env run cli wp eval "error_log('test');"
tail -f wp-content/debug.log
```

**هیچ `error_log` بدون شرط WP_DEBUG در کد نهایی نیست** (FIX-39) — فقط در Activator و MigrationRunner با شرط `if (defined('WP_DEBUG') && WP_DEBUG)`.

---

## 4. تست صفحات کلیدی (دستی)

| صفحه | حالت | چک |
|------|------|----|
| / | guest fa_IR en_US light/dark | فونت self-host، skip link Tab اول، search combobox، rail toggle، بدون ارور کنسول |
| /releases/ | guest | view counter beacon در Network → POST `music-wave/v1/releases/{id}/view` بعد 10 ثانیه، 200، جدول `mw_release_views` |
| /releases/{slug} | guest + VIP | دانلود token، lyrics meta 403 برای contributor، focus-visible |
| /my-account/ | standard + VIP 90%+ expiry | welcome VIP badge واقعی، subscription meta واقعی، renewal button، stats compact، tabs keyboard |
| /cart/ /checkout/ | virtual-only | بدون shipping/address، email required، direct checkout از طرح، subscription details در order |
| /playlists/ | guest | بدون author_id در JSON، covers، noindex default |
| /request-song/ | guest | honeypot hidden، rate limit، PRG token |

---

## 5. چگونه خطا را گزارش دهید تا سریع درست کنم

**لطفاً این اطلاعات را بفرستید:**

1. **متن کامل ارور** (کپی از debug.log یا صفحه):
   ```
   PHP Fatal error: ... in ... on line ...
   ```

2. **مسیر فایل و خط**:
   - مثلاً `wp-content/plugins/music-wave-core/src/Support/Autoloader.php on line 12`

3. **مراحل تکرار**:
   - مثلاً "بعد فعال‌سازی Core، صفحه سفید شد"

4. **محیط**:
   - PHP version (`php -v` یا Site Health → Info → Server)
   - WP version (Site Health یا `wp core version`)
   - Woo version
   - آیا multisite؟

5. **آیا zip جدید از `tools/package-release.php` نصب کردید یا فایل‌های قدیمی روی سرور مانده؟**

**من با این اطلاعات:**
- فایل دقیق را باز می‌کنم
- `declare(strict_types)` یا ABSPATH یا namespace order را چک می‌کنم
- با اسکریپت پایتون همه فایل‌های مشابه را فیکس می‌کنم
- commit + push می‌کنم

---

## 6. اسکریپت نهایی خودکار

```bash
./tools/final-check.sh
```

این اسکریپت (FIX-60) همه gateها را اجرا می‌کند و گزارش در `docs/audits/` می‌سازد.

---

## 7. ارورهای رایج دیگر و رفع

| ارور | علت | رفع |
|------|-----|-----|
| `strict_types must be first` | ABSPATH قبل declare | فیکس شد در 158 فایل — zip جدید نصب کنید |
| `Class not found` | Autoloader قبل فیکس | همان بالا |
| `Call to undefined function` | پلاگین Core غیرفعال | Core اول، VIP بعد فعال شود |
| `Table doesn't exist` | migration اجرا نشده | `wp musicwave migrate` یا فعال‌سازی مجدد |
| `403 download` | بدون خرید یا guest روی restricted | طبیعی — public کنید یا محصول بخرید |
| `429 rate limited` | درخواست زیاد | صبر 60 ثانیه یا تنظیم `download_rate_limit` |

---

*این راهنما برای تست نهایی و گزارش سریع خطاها نوشته شده.*
