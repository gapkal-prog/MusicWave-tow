# راهنمای کاربر MusicWave — User Guide

**نسخه ۱.۰ — شامل Phase 4 داشبورد جدید**
**Source language: Persian (fa_IR without catalog works), en_US from catalogs. Headers English per FIX-09.**

---

## فارسی — راهنمای خریدار غیرفنی

### ۱. نصب

1. **پیش‌نیازها:** WordPress 6.6 تا 6.8، PHP 7.4 تا 8.3، WooCommerce 8.x یا 9.x برای فروش.
2. **افزونه‌ها:** `music-wave-core.zip` و `music-wave-vip.zip` را از Packages دانلود کنید. هر دو را از افزونه‌ها → افزودن → بارگذاری نصب و فعال کنید. Core اول، VIP بعد.
3. **قالب:** `musicwave.zip` را از نمایش → پوسته‌ها → افزودن → بارگذاری نصب و فعال کنید. `screenshot.png` 1200x900 باید تایل را نشان دهد.
4. **بررسی:** نمایش → ویرایشگر (Site Editor) باید الگوهای `musicwave/home-hero`, `musicwave/release-card`, `musicwave/section-faq` را در بخش الگوها لیست کند. بدون notice.
5. **WooCommerce:** اگر فروش دارید، WooCommerce را نصب کنید. Core به صورت خودکار mapping محصول را نشان می‌دهد.

**Uninstall تمیز (FIX-05):** در تنظیمات MusicWave → تحویل و حریم خصوصی، گزینه "حذف داده‌ها هنگام حذف" پیش‌فرض خاموش است (امن). فقط وقتی روشن باشد، با حذف افزونه جداول `mw_*`, options `music_wave_`, transients, user meta, cron پاک می‌شود. محتوای مشتری `mw_release`, `mw_request`, ترم‌ها، رسانه‌ها و فایل‌های protected هرگز پاک نمی‌شود.

### ۲. فعال‌سازی Core / VIP / Woo

- **Core:** بعد فعال‌سازی، منو MusicWave → تنظیمات → تحویل. `download_daily_quota` پیش‌فرض 0 (بدون سقف). `discovery_rate_limit` 60 در 60 ثانیه.
- **VIP:** MusicWave VIP → تنظیمات → پلن‌ها. هر پلن دارای `level`, `duration_days`, `product_id` (محصول WooCommerce مجازی). محصول باید virtual و downloadable باشد.
- **Woo:** محصولات اشتراک را virtual-only کنید. Checkout خودکار shipping/address را حذف می‌کند (کلاس `mw-checkout--virtual-only`). `cart_url` با `?add-to-cart=X&mw_direct_checkout=1` مستقیم به checkout می‌رود.

### ۳. تنظیمات

- **تحویل:** `download_rate_limit` 30 درخواست توکن در هر پنجره، `download_rate_window` 60 ثانیه، `download_daily_quota` 0=نامحدود (قابل تنظیم per FIX-21). Site Health → سهمیه دانلود و شمارنده بازدید را نشان می‌دهد.
- **جست‌وجو:** `discovery_rate_limit` 60، `discovery_cache_ttl` 300 ثانیه. فیلتر `music_wave_client_ip` برای Cloudflare (مستند در `extension-contracts.md`).
- **کتابخانه:** `max_playlists_per_user` 20، `max_playlist_items` 100 (فیلتر `music_wave_max_playlists_per_user`).
- **حریم خصوصی:** `public_playlists_indexable` پیش‌فرض disabled = noindex + sitemap exclude. `disable_author_enumeration` پیش‌فرض disabled = خاموش، روشن = block `?author=1` و REST `/wp/v2/users`.
- **نمایش:** Site Editor → `theme.json` توکن‌ها `--mw-color-*`, `--mw-radius-*`. تغییر توکن داشبورد را نمی‌شکند.

### ۴. ایجاد Release, Artist, Genre

- **Release:** انتشارها → افزودن. عنوان، هنرمند (taxonomy `mw_artist`), ژانر `mw_genre`, مود `mw_mood`, لیبل `mw_label`, نوع `mw_release_type`. متای تاریخ انتشار `mw_release_date` برای pre-save.
- **Artist:** هنرمندان → ترم جدید. نام، توضیح، تصویر (term meta).
- **Genre:** مشابه.
- **Cover:** متادیتا lookup از Spotify/MusicBrainz/Discogs (admin-initiated). `import_cover` با cap `upload_files`, MIME check, byte cap 10MB, pixel cap 5000, temp cleanup (FIX-23).

### ۵. آپلود Protected Assets (nginx/Apache)

- فایل‌های دانلودی در `wp-content/uploads/music-wave-protected/` (یا مسیر تنظیم‌شده) ذخیره می‌شود. لینک مستقیم مسدود.
- **Apache:** `.htaccess` داخل پوشه `Deny from all` (Core خودکار می‌سازد).
- **Nginx:** 
```
location /wp-content/uploads/music-wave-protected/ {
  internal;
  # یا deny all و X-Accel-Redirect via DownloadResolver
}
```
- Resolver توکن امضادار با expiry برمی‌گرداند، `X-Accel-Redirect` برای nginx.

### ۶. ایجاد Playlist, Library, Request Form, VIP Plans

- **Playlist:** بلوک `music-wave/playlist` (شخصی) و `public-playlists` (عمومی). عمومی `author_id` ندارد (FIX-14)، فقط `author_name`. محدودیت تعداد via settings.
- **Library:** `mw_library_index` جدول ایندکس (FIX-26) برای کارایی. کاربر از دکمه قلب ذخیره می‌کند. Wishlist/pre-save via `LibraryRepository::TYPE_*`.
- **Request Form:** بلوک `music-wave/request-form`. honeypot `mw_request_website` hidden, timer 3 ثانیه, rate limit bucket `requests`, PRG با token random (FIX-24). نوع درخواست `mw_request` private (public false, publicly_queryable false) — anonymous نمی‌بیند (FIX-10).
- **VIP Plans:** VIP → پلن‌ها → افزودن پلن. `granted_at`, `duration_days`, `expires_at` محاسبه خودکار. `detailed_active_grants()` مرتب expiry-first، `percent_used`, `remaining_days`. Renewal بعد 90% (فیلتر `music_wave_subscription_renewal_threshold`). دکمه تمدید با `cart_url` واقعی. Order note `_mw_vip_granted_levels/_expiries/_granted_at`.

### ۷. داشبورد جدید (Phase 4)

- **Welcome:** `dashboard-welcome` بلوک با avatar 56px, badge VIP gradient, meta `کاربر عادی` یا `سطح/انقضا/مانده`, دکمه تمدید.
- **Stats:** `dashboard-stats` compact default true, toggle `showEntitled/Saved/Artists`.
- **Subscription:** `dashboard-subscription` progress bar gradient accent→gold, order link `view-order/{id}`, lifetime → "مادام‌العمر" بدون progress.
- **صفحهٔ حساب:** پوستهٔ `templates/page-account.html` خودِ `account-dashboard` را با همهٔ پنلها روشن، کنار `release-shelf` در ستونهای ۶۸/۳۲ میچیند؛ بدون الگوی پوششی و بدون دروازهٔ ورود دوم. هر بلوک در Site Editor قابل جابجایی است.
- **CSS:** `account-library.css` minimal, tabs scrollable `gap 0.375rem`, `::-webkit-scrollbar display:none`, active `background 10% accent + border-bottom accent`, focus-visible `outline 2px accent`.

### ۸. ترجمه, RTL, حریم خصوصی

- **ترجمه:** رشته‌ها فارسی مبدأ، `en_US` از `languages/*.mo`. `composer make-translations` برای POT. `wp_set_script_translations` برای JS. زبان سوم از انگلیسی شروع شود (مستند `translations.md`).
- **RTL:** توکن‌ها logical properties (`inline-start/end`, `block-start/end`). `dir="auto"` برای title/artist inputs، `unicode-bidi: plaintext` برای نمایش (FIX-32).
- **حریم خصوصی:** Settings → Privacy → Policy Guide بخش MusicWave (FIX-08). جمع‌آوری: فرم درخواست (name,email,phone,message)، تاریخچه گوش‌دادن (consent + retention 180 روز), playlists/library, rate-limiter keys از IP hashed transient, IP handling via `ClientIp::get()` filter `music_wave_client_ip`, third parties metadata lookups admin-initiated بدون visitor data, Woo data handled by Woo. Exporter/Eraser پوشش.

### ۹. عیب‌یابی

- **View counter +0 بدون JS:** beacon بعد 10 ثانیه visible با `sendBeacon` + fetch keepalive fallback. Dedupe 10 دقیقه via `ClientIp::hashed_actor`, bot filter `music_wave_bot_ua_patterns`, rate limiter bucket `views` 429 `mw_view_rate_limited`. جدول `mw_release_views` + cron hourly sync 200 ردیف به meta (FIX-11).
- **Search combobox:** نقش `combobox`, `listbox`, `option`, `aria-expanded`, `aria-activedescendant`, Esc/Enter/Arrow. Single container داخل popup (FIX-31).
- **Shelf nav:** دکمه‌ها `aria-label`, `aria-controls`, keyboard arrows, Home/End, `aria-live` count, hidden وقتی overflow ندارد (FIX-30).
- **Focus:** `:focus-visible` با `outline 2px accent + offset` برای tabs/buttons/links (FIX-29).
- **Skip link:** `<a href="#main-content" class="skip-link screen-reader-text">` + main `id="main-content"` via `wp_body_open` و `render_block` filter (FIX-28).
- **Lighthouse:** بودجه Performance >=90, A11y >=95, Best Practices >=95, SEO >=95 روی دموی کنترل‌شده. `tools/run-audits.sh` گزارش در `docs/audits/`.

### ۱۰. پشتیبانی, بازگشت وجه

- پشتیبانی via تیکت (ایمیل). متن پشتیبانی و بازگشت وجه فقط مالک می‌نویسد (OWNER-CONFIRM).
- آپدیت: فعلاً marketplace/دانلود مجدد، updater خودی بعد 1.0 (FIX-49).

---

## English — Buyer Summary

**Installation:** WP 6.6-6.8, PHP 7.4-8.3, Woo 8/9. Install core, vip, theme zips. Screenshot 1200x900 must appear. Site Editor must list patterns.

**Core/VIP/Woo:** Core first, VIP second. VIP plans map to virtual Woo products. Virtual-only checkout removes shipping/address fields, class `mw-checkout--virtual-only`, direct checkout via `?add-to-cart=X&mw_direct_checkout=1`.

**Settings:** Download quota 0=unlimited (FIX-21), discovery rate limit 60/60s, cache TTL 300s, listening retention 180d, max playlists 20/user, max items 100/playlist (filterable), public playlists noindex default + sitemap exclude (FIX-27), author enumeration optional default off (FIX-22).

**Content:** Releases CPT `mw_release` with taxonomies artist/genre/mood/label/type. Cover import hardened: upload_files cap, MIME, byte 10MB, pixel 5000, temp cleanup (FIX-23).

**Playlists/Library/Requests:** Public playlists no author_id, only author_name (FIX-14). Library indexed table `mw_library_index` for performance (FIX-26). Request form honeypot + timing 3s + rate limit + PRG random token (FIX-24), private post type `mw_request` (FIX-10).

**Dashboard Phase 4:** Granular blocks `dashboard-welcome` (avatar 56px, VIP badge, subscription meta), `dashboard-stats` (compact), `dashboard-subscription` (progress bar gradient, renewal after 90% threshold filter `music_wave_subscription_renewal_threshold`, order link). Template `page-account.html` composes `account-dashboard` with every panel enabled beside a `release-shelf`, and stays fully editable in the Site Editor.

**Translations/RTL/Privacy:** Source Persian, en_US from catalogs, fa_IR works without catalog. Logical CSS, dir auto + unicode-bidi plaintext (FIX-32). Privacy policy via `PolicyContent.php` (FIX-08), exporter/eraser coverage.

**Troubleshooting:** View counter beacon after dwell 10s (filter `music_wave_view_dwell_seconds`), dedupe 10min hashed IP, bot filter, rate limit 429. Search combobox a11y, shelf nav accessible, focus-visible, skip link.

**Support/Refund:** Owner writes policy.

**Links:** `docs/installation.md`, `docs/translations.md`, `docs/extension-contracts.md`, `docs/third-party-notices.md`, `docs/audits/README.md`.

---

## چک‌لیست ارائه (از FIX-GUIDE-PRO)

- [ ] FIX-01 ABSPATH + index.php + check:abspath
- [ ] FIX-02 screenshot.png 1200x900 <400KB
- [ ] FIX-03 OFL.txt Vazirmatn + notices
- [ ] FIX-04 self-host Plus Jakarta Sans + Syne woff2 zero Google
- [ ] FIX-05 uninstall OFF default + multisite
- [ ] FIX-11 view counter beacon + table + cron
- [ ] FIX-21 quota + Site Health
- [ ] FIX-22 author enumeration option
- [ ] FIX-23 cover hardening
- [ ] FIX-24 request honeypot + rate limit + PRG
- [ ] FIX-25 playlist limits
- [ ] FIX-26 library index table
- [ ] FIX-27 public playlists noindex + sitemap
- [ ] FIX-28 skip link
- [ ] FIX-29 focus-visible
- [ ] FIX-30 shelf nav accessible
- [ ] FIX-31 search combobox + single container
- [ ] FIX-32 bidi dir auto
- [ ] FIX-33 form labels + aria
- [ ] FIX-34 translations RTL
- [ ] FIX-35 conditional CSS/JS
- [ ] FIX-36 run-audits.sh + reports
- [ ] FIX-37 user-guide.md
- [ ] FIX-38 package-release.php MANIFEST
- [ ] FIX-39 error_log cleanup
- [ ] FIX-51..60 dashboard VIP real data

*End of user guide v1.0*
