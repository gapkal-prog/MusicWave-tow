# ADR 0005 — سیاست دسترسی ≠ مسیر تحویل؛ روتر scheme-محور؛ پیشوند direct:

## الحالة (Status)

پذیرفتهشده (Accepted) — نسخه هسته `0.16.0`، افزونه VIP `0.5.2`.

## زمینه (Context)

سامانهٔ MusicWave دارای دو لایه مستقل است:

1. **سیاست دسترسی** (`AccessPolicyEngine`): پاسخ به «چه کسی مجاز به مشاهدهٔ یک انتشار است؟» (deny-by-default).
2. **مسیر تحویل** (`DownloadProvider` و روترها): پاسخ به «فایل چگونه به کاربر میرسد؟» (فایل خصوصی محلی، تغییرمسیر راهدور امضاشده، لینک مستقیم HTTPS).

قبل از این ارتقا، هر دو لایه در یک مجموعهٔ درهمتنیده قرار داشتند و لینک مستقیم HTTPS در سه لایه (ویرایشگر، طرحواره، پاسخ) مسدود بود. علاوه بر این، `RemoteRedirectProvider` برای شناسههای `vip:<hash>` نشانی `.../vip%3A<hash>` میساخت که CDN/میزبان دانلود نمیتوانست به فایل واقعی نگاشت کند (باگ H7).

## تصمیم (Decision)

1. **جداسازی لایهها:** سیاست دسترسی همیشه مرجع است؛ تحویل فقط پس از تأیید دسترسی، توکن، سهمیه و replay انجام میشود. deny-by-default حفظ میشود.
2. **روتر scheme-محور (`DeliveryRouter`):** `direct:<https-url>` به `DirectLinkProvider` (302 ریدایرکت با هدرهای امنیتی) و بقیه به ارائهدهنده خارجی (VIP/Third-party) هدایت میشود.
3. **پیشوند `direct:`:** هرگز URL خام ذخیره نمیشود. اعتبارسنجی فقط HTTPS، بدون userinfo/IP/localhost، port ۴۴۳، allowlist میزبان اختیاری، و اعتبارسنجی دوباره در لحظهٔ تحویل (defense in depth).
4. **رفع باگ H7:** `vip:<hash>` به `relative_path` از `ProtectedAssetRegistry` نگاشت میشود؛ هرگز `vip%3A...` تولید نمیشود.
5. **مدیریت کلید چندگانه:** `{kid, secret, created, status: active|retired}`؛ امضا فقط با کلید `active`؛ `kid` در URL؛ `MUSIC_WAVE_VIP_REMOTE_SECRET` در wp-config اولویت دارد؛ چرخش کلید بدون قطعی.
6. **سیاست `membership_absent_behavior`:** سه مقدار `deny` / `allow` / `open_download` (جدید: دسترسی کامل و لینک دانلود). `open_download` فقط انتشارهای عضویتی و دوگانه را باز میکند؛ خرید تکی محفوظ میماند.
7. **بدون مهاجرت دیتابیس:** مقادیر قدیمی `'allow'`/`'deny'` بدون تغییر نام باقی میمانند؛ schema بدون تغییر.

## گزینههای رد شده

- `attachment:<id>` (رسانهٔ وردپرس): پذیرفته نشد (بخش ۴.۹ spec) چون فایل عمومی را شبیه محافظتشده نشان میدهد.
- `AssetAwareProvider` اینترفیس: YAGNI — تنها دو پیادهسازی واقعی وجود دارد (محلی و راهدور).
- امضای نامتقارن، اتصال به IP/نشست، سهمیهٔ بایتی، DRM: خارج از دامنهٔ این نسخه.

## پیامدها (Consequences)

مثبت:
- لینک مستقیم HTTPS برای CDN/میزبان خارجی پشتیبانی میشود.
- `vip:<hash>` کار میکند (مسیر واقعی در نشانی امضاشده).
- چرخش کلید بدون قطعی امکانپذیر است.
- جداسازی نگرانیها (دسترسی ≠ تحویل) کد را تمیزتر و تستپذیرتر میکند.

منفی:
- لینک مستقیم پس از تحویل قابل اشتراک است (هشدار صریح در UI برای محتوای پولی).
- اضافه شدن کلاسهای جدید (سطح کد کمی افزایش مییابد).

## مراجع

- spec: `docs/musicwave-vip-master-upgrade-spec-fa.md` بخش ۴–۶
- OWASP Unvalidated Redirects: https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html
- OWASP SSRF: https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html
