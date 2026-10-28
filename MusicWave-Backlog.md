# Backlog اجرایی پروژه MusicWave

این backlog برای تبدیل مستقیم به Jira / Trello / Notion نوشته شده و بر اساس معماری نهایی پروژه تنظیم شده است:

- `Theme`: `MusicWave`
- `Core Plugin`: `music-wave-core`
- `Target Integration Plugin`: `music-wave-vip`
- `Architecture`: `plugin for data/logic + theme for presentation`
- `Theme Type`: `Block Theme`
- `Stack`: `PHP 7.4+`, `WordPress 6.6+`, `WooCommerce 9.x+`

## وضعیت سند و قرارداد محصول

| مورد | مقدار |
|---|---|
| مالک محصول و توسعه | `ManaCore` |
| نسخه سند | `1.2.0` |
| آخرین بازبینی | `2026-08-06` |
| وضعیت فعلی | `M1 تا M6 - Code Complete؛ WordPress/WooCommerce staging smoke-tested؛ M7 Next` |
| بازار هدف | قالب و افزونه تجاری وردپرس برای فروشگاه‌ها و پلتفرم‌های موسیقی |
| جهت رابط | RTL و LTR از روز اول، بدون fork یا stylesheet جداگانه |
| تجربه بصری | Dark/Light/System با هویت مستقل؛ الهام از الگوهای رایج، بدون کپی از برندها |
| اصل سفارشی‌سازی | تنظیمات سراسری در `theme.json` و Site Editor؛ تنظیمات دامنه در افزونه Core |

> تصمیم نسخه‌ای: کد توزیعی باید با PHP 7.4 سازگار بماند. محیط CI علاوه بر 7.4، روی PHP 8.2 و نسخه پایدار جاری PHP اجرا می‌شود. استفاده از قابلیت‌های نحوی PHP 8 در کد محصول ممنوع است تا baseline اعلام‌شده واقعی بماند.

## اهداف قابل‌اندازه‌گیری محصول

| حوزه | معیار پذیرش نسخه قابل انتشار |
|---|---|
| Performance | بودجه اولیه Lighthouse موبایل: Performance >= 90، Accessibility >= 95، Best Practices >= 95 و SEO >= 95 روی دموی کنترل‌شده |
| Web Vitals | هدف p75: `LCP <= 2.5s`، `INP <= 200ms` و `CLS <= 0.1`؛ اندازه‌گیری قبل از انتشار الزامی است |
| Accessibility | هم‌راستا با WCAG 2.2 AA؛ navigation با کیبورد، focus واضح، reduced motion و contrast معتبر |
| SEO | HTML معنایی، title/meta سازگار با افزونه‌های SEO، canonical استاندارد و JSON-LD نوع MusicRecording/MusicAlbum بدون schema تکراری |
| Security | deny-by-default، nonce/capability checks، validation ورودی، escape در خروجی و بدون URL مستقیم فایل خصوصی |
| Privacy | analytics و telemetry فقط opt-in؛ export/erase داده‌های شخصی و retention قابل تنظیم برای logها |
| Compatibility | WordPress 6.6 تا نسخه پایدار جاری، WooCommerce 9.x تا نسخه پایدار جاری و آخرین دو نسخه مرورگرهای اصلی |
| Internationalization | همه رشته‌ها قابل ترجمه، تاریخ/عدد locale-aware و بررسی عملی RTL/LTR |
| Marketplace | بسته‌های theme/core/vip مستقل، مجوزها و attribution شفاف، بدون secret یا asset بدون مجوز و نصب clean قابل تکرار |

## مرزبندی تجربه و قابلیت‌های محصول

| قابلیت | مالک فنی | فاز |
|---|---|---|
| Design system، Dark/Light/System، layout و responsive UI | `MusicWave` | MVP |
| آهنگ، آلبوم، هنرمند، taxonomy، metadata و API | `music-wave-core` | MVP |
| پلیر preview پایه، queue سبک و Media Session | Core + Theme presentation | MVP |
| خرید، entitlement، CTA و My Account | Core adapter + WooCommerce | MVP |
| عضویت و دانلود امن provider-specific | `music-wave-vip` | MVP |
| تنظیم رنگ، تایپوگرافی، radius، spacing، header/footer و card styles | Site Editor / `theme.json` | MVP |
| تنظیم schema، player، catalog، integrations و privacy | Core settings API | MVP |
| علاقه‌مندی، playlist کاربر، history و recommendation | ماژول‌های اختیاری Core | پس از MVP |
| import دموی امن، onboarding و diagnostics | Theme/Core | قبل از انتشار مارکت |

## تصمیم‌های داده‌ای فریز اولیه

- `mw_release` موجودیت مستقل موسیقی است و نباید به `product` وابسته باشد؛ محصول WooCommerce صرفاً از طریق mapping به release متصل می‌شود.
- `mw_artist` در MVP taxonomy سلسله‌مراتبی نیست؛ صفحه هنرمند از term meta و template اختصاصی ساخته می‌شود. تبدیل آن به CPT فقط پس از اثبات نیاز به محتوای پیچیده انجام می‌شود.
- `mw_album`, `mw_track`, `mw_playlist` به عنوان نوع release یا رابطه ساختاریافته مدل می‌شوند؛ schema نهایی در M2 و قبل از Admin UI فریز خواهد شد.
- شناسه‌ها در API عدد صحیح وردپرس هستند و شناسه خارجی provider در meta namespaced جدا ذخیره می‌شود.
- اطلاعات حساس دانلود، entitlement و credential هرگز با `show_in_rest=true` عمومی ثبت نمی‌شوند.

## صف اجرای نزدیک

| ترتیب | آیتم | وضعیت | خروجی |
|---|---|---|---|
| 1 | E1-S1 Core skeleton | Completed | bootstrap، autoload، module registry و lifecycle امن |
| 2 | E1-S2 Block Theme skeleton | Completed | theme.json، templateها، parts و design tokens روشن/تیره |
| 3 | E1-S3 Quality baseline | In Progress | PHPCS/PHPStan، syntax check و مستندات توسعه |
| 4 | E2-S1/S2/S3 Data Model | Completed | ADR، taxonomy/meta dictionary، registration، repository و migration `0.2.0` |
| 5 | E4 Admin Authoring | Code Complete | فرم schema-driven، save امن، validation و mapping UI |
| 6 | E5 WooCommerce Integration | Code Complete | mapping دوطرفه، پاک‌سازی حذف محصول و purchase ownership adapter |
| 7 | WordPress/Woo staging QA | Completed | Local WordPress 7.0.2 + WooCommerce 11.0.0 staging و smoke suite |
| 8 | E6 Access Policy Engine | Completed | decision service مستقل با deny-by-default، membership/manual contracts و policy matrix |
| 9 | E2-S4 Editorial relationships | Planned | ADR 0002؛ collection items، type-aware editor و podcast/artist extension قبل از M7 |

### گزارش تکمیل M2 و فاز ۲

| حوزه | خروجی | شواهد پذیرش |
|---|---|---|
| Content model | `mw_release` مستقل از WooCommerce و پنج taxonomy رسمی | `docs/adr/0001-catalog-content-model.md` |
| Schema | ۱۳ کلید meta عمومی/خصوصی با sanitizer و REST policy | `docs/data-dictionary.md` و `ReleaseMetaSchema` |
| Persistence | repository فقط برای release معتبر و کلید ثبت‌شده | `WordPressReleaseRepository` |
| Migration | runner ترتیبی و idempotent با schema version `0.2.0` | `MigrationRunner` و `Schema020` |
| Admin | nonce، capability، autosave/revision guard و فرم مبتنی بر schema | `ReleaseMetaBox` |
| Access validation | mapping ناقص به `restricted` تبدیل می‌شود، نه public | `ReleaseMetaBox::enforce_access_invariants` |
| Woo mapping | canonical relation روی release و reverse index خصوصی روی product | `ProductMapper` |
| Purchase detection | تشخیص مالکیت با API رسمی WooCommerce و fail-closed بدون Woo/user | `PurchaseChecker` |
| Automated QA | syntax تمام PHPها + domain smoke tests برای schema/migration/mapping/ownership | `tests/run.php` |

> محدودیت پذیرش: اجرای واقعی CRUD در Gutenberg و ماتریس WooCommerce روی WordPress fresh install نیازمند محیط staging است. در محیط فعلی Docker، WP-CLI و WordPress نصب‌شده وجود ندارد؛ بنابراین وضعیت کد M2/E4/E5 کامل است اما UAT سازگاری تا ایجاد staging باز می‌ماند.

---

## 0) اصول معماری و منطق توسعه از 0 تا 100

## 0.1) اصول کلان معماری

| موضوع | تصمیم معماری | توضیح اجرایی |
|---|---|---|
| تفکیک مسئولیت | `music-wave-core` برای data/domain/business logic و `MusicWave` برای presentation | هیچ منطق تجاری مهمی داخل theme قرار نگیرد |
| توسعه‌پذیری | همه قابلیت‌های قابل استفاده مجدد داخل plugin | theme فقط renderer, templates, styles, patterns, template parts |
| یکپارچگی | `music-wave-vip` به عنوان لایه integration برای دانلود و membership خاص | منطق اختصاصی اتصال به سرورهای دانلود و مدل‌های اشتراک در افزونه جداگانه |
| سازگاری آینده | پیش‌بینی افزونه Elementor extension | تمام schema ها، API ها و service layer ها مستقل از Gutenberg باشند |
| پایداری داده | تمام post meta / term meta / options با schema مشخص | نام‌گذاری یکنواخت، sanitize/validate اجباری |
| امنیت | deny by default در دانلود، capability check، nonce، tokenized access | لینک مستقیم فایل نباید بدون کنترل قابل دسترسی باشد |
| کیفیت کد | OOP ماژولار، service classes، repository-style access، interface-first در integration | از function dumping و فایل‌های procedural حجیم جلوگیری شود |
| قابلیت تست | business ruleها در service layer قابل unit test باشند | callback های وردپرس thin wrapper باشند |
| سازگاری وردپرس | استفاده از APIهای استاندارد WP/WooCommerce | از bypass کردن lifecycle وردپرس خودداری شود |
| i18n | تمام متن‌ها translatable | textdomain جدا برای theme و plugin |
| performance | query محدود، cache-aware، lazy loading، asset loading هدفمند | بارگذاری asset به ازای صفحه/بلوک |
| migration safety | نسخه‌گذاری schema و upgrade routines | تغییر meta structure بدون migration ممنوع |

---

## 0.2) قواعد فنی اجباری برای برنامه‌نویسان

| حوزه | قواعد |
|---|---|
| PHP | `strict_types=1` در فایل‌های مناسب، تایپ‌گذاری ورودی/خروجی، استفاده از namespace |
| OOP | هر ماژول شامل `Service`, `Repository`, `Controller`, `Validator`, `DTO/Value Object` در صورت نیاز |
| Hooking | ثبت hookها فقط در bootstrap/provider کلاس‌ها |
| Data Access | دسترسی مستقیم پراکنده به `get_post_meta` و `update_post_meta` ممنوع؛ از wrapper/repository استفاده شود |
| Validation | sanitize در input، escape در output، validate در boundary |
| REST/AJAX | فقط برای عملیات واقعی async؛ nonce + capability + schema validation |
| Blocks | بلوک‌های dynamic فقط وقتی لازم است؛ در غیر این صورت static block + server-side support |
| Templates | هیچ query سنگین داخل template/theme file نباشد |
| CSS | design token-based، ترجیحاً `theme.json` + block styles |
| JS | بسته به نیاز، ESNext build pipeline سبک و قابل نگهداری |
| Naming | prefix ثابت: `mw_` برای meta/key/function های shared |
| Errors | logging قابل کنترل، fail-safe behavior، بدون افشای path/file |
| Security | همه endpointها، save handlerها و download resolverها threat-modeled شوند |
| DB Changes | تا حد ممکن از post meta / options / taxonomy استفاده شود؛ جدول سفارشی فقط در صورت نیاز اثبات‌شده |
| Backward Compatibility | هر تغییر schema با migration function و version bump همراه باشد |
| QA | هر feature بدون acceptance criteria و test notes merge نشود |

---

## 0.3) ساختار پیشنهادی ماژول‌ها

| لایه | مسئولیت | محل |
|---|---|---|
| Domain | entity contract, business rules, access rules | `music-wave-core` |
| Application | orchestration services, use-cases | `music-wave-core` |
| Infrastructure | WP hooks, REST, meta registration, Woo hooks | `music-wave-core` / `music-wave-vip` |
| Integration | membership/download server adapters | `music-wave-vip` |
| Presentation | templates, patterns, theme.json, template parts, block styling | `MusicWave` |
| Editor UX | block variations, editor styles, pattern registration | `MusicWave` + در صورت نیاز `core` |
| Compatibility | WooCommerce account/product hooks, membership hook mapping | `music-wave-vip` |

---

# 1) Epicها

| Epic ID | Epic | اولویت | هدف | وابستگی | ریسک | خروجی |
|---|---|---|---|---|---|---|
| E1 | Foundation Architecture & Dev Environment | Must Have | ایجاد اسکلت فنی theme/plugin و استانداردهای توسعه | ندارد | متوسط | ساختار اولیه پروژه، bootstrap، tooling، coding standards |
| E2 | Content Model & Meta Schema | Must Have | طراحی مدل داده محصولات/آهنگ/آلبوم/دانلود/اشتراک | E1 | بالا | CPT/Taxonomy/Meta schema/versioning |
| E3 | Block Theme System | Must Have | پیاده‌سازی قالب Block Theme و تجربه نمایش | E1, E2 | بالا | templates, patterns, theme.json, block styles |
| E4 | Admin Content Management UX | Must Have | تجربه مدیریت داده در ادمین | E2 | متوسط | meta UI، validation، editorial workflow |
| E5 | WooCommerce Product & Purchase Integration | Must Have | اتصال محتوا به محصول/خرید و access flow | E2 | بالا | product mapping، purchase-aware rules |
| E6 | Membership & Access Control | Must Have | تعریف لایه دسترسی بر اساس عضویت/خرید | E5 | بسیار بالا | access policy engine |
| E7 | Secure Download Delivery | Must Have | ارائه لینک دانلود امن و کنترل‌شده | E5, E6 | بسیار بالا | tokenized download resolver |
| E8 | music-wave-vip Integration Layer | Must Have | سازگاری با مدل‌های سرور دانلود و membership | E6, E7 | بسیار بالا | adapter architecture + first provider support |
| E9 | Archive / Single Experience | Should Have | تجربه کامل فرانت برای آرشیو و صفحات تکی | E3, E5 | متوسط | single/archive/search/filter views |
| E10 | Search, Filter & Discovery | Should Have | کشف سریع محتوا | E2, E9 | متوسط | taxonomy filters, query UI |
| E11 | QA, Security Hardening & Performance | Must Have | تست، سخت‌سازی و بهینه‌سازی | همه اپیک‌ها | بالا | regression/security/performance checklist |
| E12 | Documentation & Release Packaging | Must Have | مستندات توسعه و آماده‌سازی انتشار | همه اپیک‌ها | متوسط | install docs, dev docs, release package |

---

# 2) User Storyها + 3) Taskهای فنی

## E1 - Foundation Architecture & Dev Environment

### Epic DoD
| مورد | Definition of Done |
|---|---|
| تست | lint/build/test پایه بدون خطا اجرا شود |
| مستندات | README توسعه، ساختار پروژه، naming conventions ثبت شده باشد |
| امنیت | dependency review اولیه انجام شده باشد |
| سازگاری | PHP 7.4+ / WP 6.6+ / Woo 9.x baseline و CI روی PHP 7.4/8.2/current بررسی شده باشد |
| کیفیت کد | bootstrap و module boundaries مشخص و قابل توسعه باشند |

### Story E1-S1
| فیلد | مقدار |
|---|---|
| عنوان | راه‌اندازی اسکلت افزونه `music-wave-core` |
| شرح | به عنوان تیم توسعه، نیاز داریم افزونه هسته با ساختار ماژولار قابل توسعه راه‌اندازی شود |
| نقش کاربر | Developer |
| ارزش کسب‌وکار | کاهش ریسک توسعه و نگهداری بلندمدت |
| وابستگی‌ها | ندارد |
| اولویت | Must Have |

#### Taskها
| دسته | Task |
|---|---|
| backend | ایجاد bootstrap plugin و autoloading استاندارد |
| backend | تعریف namespace و service provider pattern |
| backend | ایجاد module registry برای feature loading |
| backend | پیاده‌سازی constants/versioning/plugin paths |
| integration | تعریف activation/deactivation hooks |
| QA | بررسی load order و conflict baseline در WordPress fresh install |

### Story E1-S2
| فیلد | مقدار |
|---|---|
| عنوان | راه‌اندازی اسکلت Block Theme `MusicWave` |
| شرح | به عنوان تیم توسعه، نیاز داریم قالب block-based با ساختار استاندارد وردپرس ایجاد شود |
| نقش کاربر | Developer |
| ارزش کسب‌وکار | آماده‌سازی سریع لایه نمایش |
| وابستگی‌ها | ندارد |
| اولویت | Must Have |

#### Taskها
| دسته | Task |
|---|---|
| frontend | ایجاد `style.css`, `theme.json`, template hierarchy پایه |
| frontend | ساخت `templates`, `parts`, `patterns` folders |
| frontend | ثبت editor styles و global style tokens |
| integration | تعریف dependency awareness نسبت به `music-wave-core` |
| QA | تست فعال‌سازی theme و fallback behavior بدون plugin |

### Story E1-S3
| فیلد | مقدار |
|---|---|
| عنوان | تعریف استانداردهای توسعه و CI پایه |
| شرح | به عنوان لید فنی، نیاز داریم استانداردهای lint/test/build مشخص شوند |
| نقش کاربر | Tech Lead |
| ارزش کسب‌وکار | جلوگیری از افت کیفیت و کاهش friction تیم |
| وابستگی‌ها | E1-S1, E1-S2 |
| اولویت | Must Have |

#### Taskها
| دسته | Task |
|---|---|
| backend | پیکربندی PHPCS با WordPress standards |
| backend | پیکربندی PHPStan/Psalm در سطح مناسب |
| frontend | پیکربندی lint برای CSS/JS |
| integration | تعریف Git hooks یا CI workflow |
| QA | اجرای baseline checks روی theme و plugin |

---

## E2 - Content Model & Meta Schema

### Epic DoD
| مورد | Definition of Done |
|---|---|
| تست | registration و save flows تست شده باشند |
| مستندات | schema dictionary برای meta/taxonomy منتشر شده باشد |
| امنیت | register_meta با sanitize/auth callbacks کامل باشد |
| سازگاری | schema migration strategy نسخه‌گذاری شده باشد |
| کیفیت کد | هیچ meta key بدون registry رسمی استفاده نشود |

### Story E2-S1
| فیلد | مقدار |
|---|---|
| عنوان | طراحی مدل داده محتوای موسیقی |
| شرح | به عنوان معمار محصول، نیاز داریم موجودیت‌های اصلی و ارتباطات آن‌ها مشخص شوند |
| نقش کاربر | Product/Developer |
| ارزش کسب‌وکار | جلوگیری از بی‌ثباتی داده و توسعه پراکنده |
| وابستگی‌ها | E1 |
| اولویت | Must Have |

#### Taskها
| دسته | Task |
|---|---|
| backend | تعیین اینکه از Woo product به عنوان entity اصلی استفاده شود یا CPT مجزا با mapping |
| backend | تعریف taxonomyهای لازم مثل genre, artist, mood, label |
| backend | تعریف meta schema برای release info, media info, access info |
| integration | هم‌راستاسازی schema با WooCommerce product meta |
| QA | review مدل داده با سناریوهای single, archive, download, membership |

### Story E2-S2
| فیلد | مقدار |
|---|---|
| عنوان | ثبت CPT/Taxonomy/Meta با schema پایدار |
| شرح | به عنوان توسعه‌دهنده، نیاز داریم تمام ساختار داده به صورت استاندارد ثبت شود |
| نقش کاربر | Developer |
| ارزش کسب‌وکار | کاهش bugهای داده و افزایش قابلیت توسعه |
| وابستگی‌ها | E2-S1 |
| اولویت | Must Have |

#### Taskها
| دسته | Task |
|---|---|
| backend | پیاده‌سازی registration classes برای CPT/Taxonomy |
| backend | پیاده‌سازی meta registry با type/schema/defaults |
| backend | تعریف repository access layer |
| integration | سازگاری meta registration با REST/editor exposure |
| QA | تست CRUD برای meta ها و term assignments |

### Story E2-S3
| فیلد | مقدار |
|---|---|
| عنوان | نسخه‌گذاری schema و migration framework |
| شرح | به عنوان تیم توسعه، نیاز داریم تغییرات آینده schema قابل migration باشند |
| نقش کاربر | Developer |
| ارزش کسب‌وکار | جلوگیری از شکستن داده در آپدیت‌ها |
| وابستگی‌ها | E2-S2 |
| اولویت | Must Have |

#### Taskها
| دسته | Task |
|---|---|
| backend | تعریف option version برای schema/plugin |
| backend | پیاده‌سازی migration runner |
| backend | ساخت الگوی migration class-based |
| QA | تست migration dry-run و upgrade path |

---

## E3 - Block Theme System

### Epic DoD
| مورد | Definition of Done |
|---|---|
| تست | template rendering در صفحات اصلی و singleها بدون خطا باشد |
| مستندات | نقشه template و patternها مستند باشد |
| امنیت | تمام dynamic outputs escaped باشند |
| سازگاری | block editor/frontend parity برقرار باشد |
| کیفیت کد | rendering logic در theme حداقلی و قابل نگهداری باشد |

### Story E3-S1
| فیلد | مقدار |
|---|---|
| عنوان | پیاده‌سازی template hierarchy پایه Block Theme |
| شرح | به عنوان بازدیدکننده، می‌خواهم صفحات اصلی سایت ساختار منسجم داشته باشند |
| نقش کاربر | Visitor |
| ارزش کسب‌وکار | ارائه تجربه پایه محصول |
| وابستگی‌ها | E1-S2, E2 |
| اولویت | Must Have |

#### Taskها
| دسته | Task |
|---|---|
| frontend | ساخت `index`, `home`, `archive`, `single`, `page`, `search`, `404` templates |
| frontend | ساخت template parts برای header/footer/sidebar sections |
| frontend | تعریف layout tokens در `theme.json` |
| QA | تست render برای routeهای اصلی |

### Story E3-S2
| فیلد | مقدار |
|---|---|
| عنوان | ساخت patternها و style variationهای MusicWave |
| شرح | به عنوان مدیر سایت، می‌خواهم بتوانم صفحات را سریع و سازگار بچینم |
| نقش کاربر | Site Admin |
| ارزش کسب‌وکار | افزایش سرعت ساخت محتوا |
| وابستگی‌ها | E3-S1 |
| اولویت | Should Have |

#### Taskها
| دسته | Task |
|---|---|
| frontend | ساخت patterns برای hero archive, featured releases, artist strip, download CTA |
| frontend | تعریف block styles اختصاصی |
| integration | اطمینان از سازگاری patternها با داده‌های dynamic |
| QA | تست pattern insertion در editor |

### Story E3-S3
| فیلد | مقدار |
|---|---|
| عنوان | پیاده‌سازی بلوک‌های dynamic موردنیاز |
| شرح | به عنوان مدیر سایت، می‌خواهم اجزای dynamic موسیقی را در صفحات قرار دهم |
| نقش کاربر | Site Admin |
| ارزش کسب‌وکار | نمایش داده اختصاصی بدون نیاز به shortcode legacy |
| وابستگی‌ها | E2, E3-S1 |
| اولویت | Must Have |

#### Taskها
| دسته | Task |
|---|---|
| backend | ثبت block server-side برای release meta summary |
| backend | ثبت block server-side برای gated download panel |
| frontend | ساخت editor preview و block controls |
| integration | اتصال blocks به repository/service layer |
| QA | تست rendering در editor و frontend |

---

## E4 - Admin Content Management UX

### Epic DoD
| مورد | Definition of Done |
|---|---|
| تست | save/update/edit workflows بدون از دست رفتن داده کار کنند |
| مستندات | راهنمای فیلدها و editorial flow ثبت شده باشد |
| امنیت | nonce, capability, validation کامل باشد |
| سازگاری | Gutenberg editor با meta exposure صحیح کار کند |
| کیفیت کد | UI و schema sync باشند |

### Story E4-S1
| فیلد | مقدار |
|---|---|
| عنوان | طراحی UI مدیریت متادیتای موسیقی |
| شرح | به عنوان ادمین، می‌خواهم اطلاعات آهنگ/آلبوم را ساختاریافته وارد کنم |
| نقش کاربر | Admin/Editor |
| ارزش کسب‌وکار | کاهش خطای ورود اطلاعات |
| وابستگی‌ها | E2 |
| اولویت | Must Have |

#### Taskها
| دسته | Task |
|---|---|
| backend | پیاده‌سازی meta box یا sidebar panel architecture |
| backend | ساخت save handlers با validation |
| frontend | ساخت UI فیلدها برای release info, credits, media, access |
| QA | تست create/edit/update/delete برای داده‌ها |

### Story E4-S2
| فیلد | مقدار |
|---|---|
| عنوان | مدیریت روابط محتوا با محصول و پلن دسترسی |
| شرح | به عنوان ادمین، می‌خواهم هر محتوای موسیقی را به محصول یا پلن مناسب متصل کنم |
| نقش کاربر | Admin |
| ارزش کسب‌وکار | آماده‌سازی خرید و دسترسی |
| وابستگی‌ها | E2, E5 |
| اولویت | Must Have |

#### Taskها
| دسته | Task |
|---|---|
| backend | ایجاد fields برای product mapping |
| backend | ایجاد fields برای access mode و membership mapping |
| integration | sync با Woo product IDs و access rules |
| QA | تست اعتبارسنجی mappingهای ناقص یا ناسازگار |

---

## E5 - WooCommerce Product & Purchase Integration

### Epic DoD
| مورد | Definition of Done |
|---|---|
| تست | سناریوهای خرید و اتصال به محتوا تست شده باشند |
| مستندات | product mapping و access triggers مستند باشند |
| امنیت | دسترسی فقط بر اساس ownership معتبر فعال شود |
| سازگاری | WooCommerce 9.x hook compatibility تایید شود |
| کیفیت کد | Woo hooks در adapter/service layer ایزوله باشند |

### Story E5-S1
| فیلد | مقدار |
|---|---|
| عنوان | اتصال محتوای موسیقی به محصولات WooCommerce |
| شرح | به عنوان مدیر فروش، می‌خواهم هر release به یک یا چند محصول متصل شود |
| نقش کاربر | Store Manager |
| ارزش کسب‌وکار | امکان monetization مستقیم |
| وابستگی‌ها | E2, E4 |
| اولویت | Must Have |

#### Taskها
| دسته | Task |
|---|---|
| backend | طراحی mapping relation بین content و product |
| backend | ساخت helperهای resolve product-from-content و reverse |
| integration | hook به product admin fields در WooCommerce |
| QA | تست relation integrity برای حذف/ویرایش product |

### Story E5-S2
| فیلد | مقدار |
|---|---|
| عنوان | تشخیص وضعیت خرید کاربر برای دسترسی محتوا |
| شرح | به عنوان کاربر خریدار، می‌خواهم پس از خرید دسترسی‌ام تشخیص داده شود |
| نقش کاربر | Customer |
| ارزش کسب‌وکار | فعال‌سازی خودکار تجربه پس از خرید |
| وابستگی‌ها | E5-S1 |
| اولویت | Must Have |

#### Taskها
| دسته | Task |
|---|---|
| backend | پیاده‌سازی purchase checker service |
| integration | اتصال به order statuses و completed/processing rules |
| integration | پشتیبانی از guest-to-user edge cases در صورت نیاز |
| QA | تست سناریوهای refunded/cancelled/pending |

---

## E6 - Membership & Access Control

### Epic DoD
| مورد | Definition of Done |
|---|---|
| تست | ماتریس دسترسی برای حالت‌های مختلف کاربر پاس شود |
| مستندات | access policy matrix مستند شود |
| امنیت | deny-by-default و no direct bypass |
| سازگاری | قابلیت extension برای membership engines مختلف وجود داشته باشد |
| کیفیت کد | policy engine مستقل از UI و download delivery باشد |

### Story E6-S1
| فیلد | مقدار |
|---|---|
| عنوان | طراحی policy engine برای access control |
| شرح | به عنوان سیستم، باید بر اساس خرید، عضویت و نقش کاربر تصمیم دسترسی بگیرم |
| نقش کاربر | System |
| ارزش کسب‌وکار | هسته مدل درآمدی و کنترل محتوا |
| وابستگی‌ها | E5 |
| اولویت | Must Have |

#### Taskها
| دسته | Task |
|---|---|
| backend | تعریف access decision service |
| backend | طراحی policy interfaces برای purchase/membership/manual access |
| backend | پیاده‌سازی deny/default fallback |
| QA | تست ماتریس دسترسی برای anonymous/member/customer/admin |

### Story E6-S2
| فیلد | مقدار |
|---|---|
| عنوان | پشتیبانی از مدل‌های مختلف عضویت |
| شرح | به عنوان مدیر سایت، می‌خواهم بتوانم دسترسی را بر اساس membership تعیین کنم |
| نقش کاربر | Site Admin |
| ارزش کسب‌وکار | انعطاف در فروش اشتراک |
| وابستگی‌ها | E6-S1, E8 |
| اولویت | Must Have |

#### Taskها
| دسته | Task |
|---|---|
| backend | تعریف membership provider interface |
| integration | پیاده‌سازی adapter اولیه برای provider هدف |
| integration | mapping membership levels به access rules |
| QA | تست تغییر status عضویت و refresh access |

---

## E7 - Secure Download Delivery

### Epic DoD
| مورد | Definition of Done |
|---|---|
| تست | دانلود مجاز/غیرمجاز، expiry، token tampering تست شده باشد |
| مستندات | flow دانلود و محدودیت‌ها مستند باشد |
| امنیت | لینک مستقیم فایل مسدود، token signed/expiring، logging پایه فعال |
| سازگاری | با Woo purchase و membership policy engine کار کند |
| کیفیت کد | resolver و storage adapter جدا باشند |

### Story E7-S1
| فیلد | مقدار |
|---|---|
| عنوان | طراحی resolver دانلود امن |
| شرح | به عنوان کاربر مجاز، می‌خواهم لینک دانلود امن و معتبر دریافت کنم |
| نقش کاربر | Customer/Member |
| ارزش کسب‌وکار | حفاظت از محتوای پولی |
| وابستگی‌ها | E6 |
| اولویت | Must Have |

#### Taskها
| دسته | Task |
|---|---|
| backend | طراحی download request lifecycle |
| backend | پیاده‌سازی token generation/verification |
| backend | تعریف expiry و replay mitigation strategy |
| integration | اتصال resolver به access policy engine |
| QA | تست unauthorized access, expired token, modified token |

### Story E7-S2
| فیلد | مقدار |
|---|---|
| عنوان | ثبت و مانیتورینگ رویدادهای دانلود |
| شرح | به عنوان مدیر سایت، می‌خواهم دانلودها قابل پیگیری باشند |
| نقش کاربر | Admin |
| ارزش کسب‌وکار | تحلیل سوءاستفاده و پشتیبانی بهتر |
| وابستگی‌ها | E7-S1 |
| اولویت | Should Have |

#### Taskها
| دسته | Task |
|---|---|
| backend | طراحی download log schema |
| backend | ثبت eventهای مجاز/غیرمجاز |
| integration | در صورت نیاز اتصال به Woo customer context |
| QA | تست log accuracy و عدم افشای داده حساس |

---

## E8 - music-wave-vip Integration Layer

### Epic DoD
| مورد | Definition of Done |
|---|---|
| تست | حداقل یک provider واقعی end-to-end کار کند |
| مستندات | contractهای integration و نحوه افزودن provider جدید ثبت شده باشد |
| امنیت | credential handling و secret storage امن باشد |
| سازگاری | adapterها بدون تغییر core/theme قابل توسعه باشند |
| کیفیت کد | interface-based architecture و fail-safe fallback وجود داشته باشد |

### Story E8-S1
| فیلد | مقدار |
|---|---|
| عنوان | طراحی adapter architecture برای سرورهای دانلود |
| شرح | به عنوان تیم توسعه، نیاز داریم اتصال به مدل‌های مختلف دانلود قابل تعویض باشد |
| نقش کاربر | Developer |
| ارزش کسب‌وکار | توسعه‌پذیری بالا و کاهش lock-in |
| وابستگی‌ها | E7 |
| اولویت | Must Have |

#### Taskها
| دسته | Task |
|---|---|
| backend | تعریف download provider interface |
| backend | تعریف DTO برای download asset/resource |
| integration | پیاده‌سازی provider registry |
| QA | تست swapping provider بدون تغییر business logic |

### Story E8-S2
| فیلد | مقدار |
|---|---|
| عنوان | پیاده‌سازی provider اولیه membership/download |
| شرح | به عنوان محصول، نیاز داریم اولین integration واقعی عملیاتی شود |
| نقش کاربر | Business/Admin |
| ارزش کسب‌وکار | قابلیت استفاده واقعی MVP |
| وابستگی‌ها | E8-S1, E6-S2 |
| اولویت | Must Have |

#### Taskها
| دسته | Task |
|---|---|
| integration | توسعه adapter برای provider دانلود هدف |
| integration | توسعه adapter membership/Woo bridge موردنیاز |
| backend | ذخیره تنظیمات provider در admin settings |
| QA | تست end-to-end با داده واقعی staging |

---

## E9 - Archive / Single Experience

### Epic DoD
| مورد | Definition of Done |
|---|---|
| تست | صفحات single/archive در desktop/mobile درست رندر شوند |
| مستندات | content placement rules مشخص باشند |
| امنیت | داده‌های restricted لو نروند |
| سازگاری | block theme templates با Woo/product context سازگار باشند |
| کیفیت کد | presentation از business logic جدا باشد |

### Story E9-S1
| فیلد | مقدار |
|---|---|
| عنوان | طراحی صفحه Single Release |
| شرح | به عنوان بازدیدکننده، می‌خواهم اطلاعات کامل release را ببینم |
| نقش کاربر | Visitor/Customer |
| ارزش کسب‌وکار | بهبود conversion و مصرف محتوا |
| وابستگی‌ها | E3, E5, E6 |
| اولویت | Should Have |

#### Taskها
| دسته | Task |
|---|---|
| frontend | طراحی template single release |
| frontend | نمایش metadata, cover, credits, track info, CTA |
| integration | نمایش gated state بر اساس access checker |
| QA | تست guest/member/customer/admin views |

### Story E9-S2
| فیلد | مقدار |
|---|---|
| عنوان | طراحی آرشیو releaseها |
| شرح | به عنوان کاربر، می‌خواهم آرشیو موسیقی را مرور کنم |
| نقش کاربر | Visitor |
| ارزش کسب‌وکار | افزایش discoverability |
| وابستگی‌ها | E3, E2 |
| اولویت | Should Have |

#### Taskها
| دسته | Task |
|---|---|
| frontend | طراحی archive cards و grid/list views |
| frontend | نمایش taxonomy/meta snippets |
| QA | تست archive performance و pagination |

---

## E10 - Search, Filter & Discovery

### Epic DoD
| مورد | Definition of Done |
|---|---|
| تست | فیلترها و جستجو نتایج صحیح برگردانند |
| مستندات | query rules و filter logic ثبت شده باشد |
| امنیت | query vars sanitize شوند |
| سازگاری | با archive templates و taxonomyها هم‌راستا باشد |
| کیفیت کد | query building قابل نگهداری باشد |

### Story E10-S1
| فیلد | مقدار |
|---|---|
| عنوان | فیلتر بر اساس taxonomy و metadata |
| شرح | به عنوان کاربر، می‌خواهم آرشیو را بر اساس ژانر/هنرمند/نوع دسترسی فیلتر کنم |
| نقش کاربر | Visitor |
| ارزش کسب‌وکار | بهبود تجربه کشف محتوا |
| وابستگی‌ها | E2, E9 |
| اولویت | Should Have |

#### Taskها
| دسته | Task |
|---|---|
| backend | ساخت query builder برای filters |
| frontend | طراحی UI فیلترها در archive |
| integration | sync query state با URL |
| QA | تست ترکیب فیلترها و edge cases |

### Story E10-S2
| فیلد | مقدار |
|---|---|
| عنوان | جستجوی بهینه محتوا |
| شرح | به عنوان کاربر، می‌خواهم سریع به release موردنظر برسم |
| نقش کاربر | Visitor |
| ارزش کسب‌وکار | افزایش retention و usability |
| وابستگی‌ها | E9 |
| اولویت | Could Have |

#### Taskها
| دسته | Task |
|---|---|
| backend | بهبود search integration برای CPT/product context |
| frontend | نمایش فرم جستجو و result template مناسب |
| QA | تست relevance baseline |

---

## E11 - QA, Security Hardening & Performance

### Epic DoD
| مورد | Definition of Done |
|---|---|
| تست | test plan MVP کامل اجرا شود |
| مستندات | test report و known limitations ثبت شود |
| امنیت | security checklist پاس شود |
| سازگاری | PHP/WP/Woo matrix smoke-tested باشد |
| کیفیت کد | performance baseline و code review کامل شده باشد |

### Story E11-S1
| فیلد | مقدار |
|---|---|
| عنوان | سخت‌سازی امنیتی featureهای حساس |
| شرح | به عنوان مالک محصول، می‌خواهم ریسک نشت دانلود و bypass دسترسی کاهش یابد |
| نقش کاربر | Product Owner |
| ارزش کسب‌وکار | حفاظت از درآمد و داده |
| وابستگی‌ها | E6, E7, E8 |
| اولویت | Must Have |

#### Taskها
| دسته | Task |
|---|---|
| backend | review همه nonce/capability/auth callbackها |
| backend | audit escape/sanitize/validate coverage |
| integration | بررسی direct file exposure در providerها |
| QA | تست penetration-style برای access bypass و URL tampering |

### Story E11-S2
| فیلد | مقدار |
|---|---|
| عنوان | بهینه‌سازی عملکرد فرانت و کوئری‌ها |
| شرح | به عنوان بازدیدکننده، می‌خواهم صفحات سریع لود شوند |
| نقش کاربر | Visitor |
| ارزش کسب‌وکار | تجربه بهتر و SEO بهتر |
| وابستگی‌ها | E3, E9, E10 |
| اولویت | Should Have |

#### Taskها
| دسته | Task |
|---|---|
| backend | بازبینی query count و meta query usage |
| frontend | بهینه‌سازی asset loading و CSS delivery |
| integration | cache strategy برای resolverهای امن غیرحساس |
| QA | اندازه‌گیری baseline TTFB و page load |

---

## E12 - Documentation & Release Packaging

### Epic DoD
| مورد | Definition of Done |
|---|---|
| تست | بسته انتشار روی staging clean install شود |
| مستندات | admin/developer/install docs تکمیل باشد |
| امنیت | secrets در package نباشند |
| سازگاری | نسخه‌ها و dependencies دقیق اعلام شده باشند |
| کیفیت کد | package reproducible و versioned باشد |

### Story E12-S1
| فیلد | مقدار |
|---|---|
| عنوان | مستندسازی نصب و پیکربندی |
| شرح | به عنوان ادمین سایت، می‌خواهم نحوه نصب و راه‌اندازی را بدانم |
| نقش کاربر | Admin |
| ارزش کسب‌وکار | کاهش هزینه استقرار و پشتیبانی |
| وابستگی‌ها | همه اپیک‌های MVP |
| اولویت | Must Have |

#### Taskها
| دسته | Task |
|---|---|
| backend | مستندسازی نصب plugin/theme |
| integration | مستندسازی تنظیمات Woo/membership/download provider |
| QA | اعتبارسنجی مستندات روی staging clean setup |

### Story E12-S2
| فیلد | مقدار |
|---|---|
| عنوان | آماده‌سازی release package |
| شرح | به عنوان تیم محصول، می‌خواهیم بسته قابل تحویل و نسخه‌گذاری‌شده داشته باشیم |
| نقش کاربر | Product/Release Manager |
| ارزش کسب‌وکار | تحویل پایدار و حرفه‌ای |
| وابستگی‌ها | E12-S1 |
| اولویت | Must Have |

#### Taskها
| دسته | Task |
|---|---|
| backend | version bump و changelog |
| integration | بررسی dependency packaging |
| QA | تست install/upgrade/uninstall baseline |

---

# 4) Definition of Done خلاصه برای همه Epicها

| Epic | Definition of Done |
|---|---|
| E1 | ساختار theme/plugin، استاندارد کدنویسی، CI و bootstrap کامل |
| E2 | schema ثبت‌شده، migration-ready، مستند و تست‌شده |
| E3 | templateها، blocks، patternها و rendering پایدار |
| E4 | فرم‌های ادمین معتبر، امن و هماهنگ با schema |
| E5 | mapping به Woo و تشخیص خرید بدون خطا |
| E6 | policy engine با deny-by-default و ماتریس دسترسی کامل |
| E7 | لینک دانلود امن، expiring، signed و مقاوم در برابر tampering |
| E8 | adapter-based integration با حداقل یک provider واقعی |
| E9 | صفحات single/archive عملیاتی و سازگار با access state |
| E10 | فیلتر و جستجو دقیق و قابل نگهداری |
| E11 | امنیت، performance، regression و compatibility review تکمیل |
| E12 | مستندات کامل، release package نسخه‌گذاری‌شده و قابل نصب |

---

# 5) اولویت‌بندی نهایی Epicها و Storyها

## Epic Priority

| Epic | اولویت |
|---|---|
| E1 | Must Have |
| E2 | Must Have |
| E3 | Must Have |
| E4 | Must Have |
| E5 | Must Have |
| E6 | Must Have |
| E7 | Must Have |
| E8 | Must Have |
| E9 | Should Have |
| E10 | Should Have |
| E11 | Must Have |
| E12 | Must Have |

## Story Priority

| Story | اولویت |
|---|---|
| E1-S1 | Must Have |
| E1-S2 | Must Have |
| E1-S3 | Must Have |
| E2-S1 | Must Have |
| E2-S2 | Must Have |
| E2-S3 | Must Have |
| E3-S1 | Must Have |
| E3-S2 | Should Have |
| E3-S3 | Must Have |
| E4-S1 | Must Have |
| E4-S2 | Must Have |
| E5-S1 | Must Have |
| E5-S2 | Must Have |
| E6-S1 | Must Have |
| E6-S2 | Must Have |
| E7-S1 | Must Have |
| E7-S2 | Should Have |
| E8-S1 | Must Have |
| E8-S2 | Must Have |
| E9-S1 | Should Have |
| E9-S2 | Should Have |
| E10-S1 | Should Have |
| E10-S2 | Could Have |
| E11-S1 | Must Have |
| E11-S2 | Should Have |
| E12-S1 | Must Have |
| E12-S2 | Must Have |

---

# 6) MVP Scope Freeze

## داخل MVP

| بخش | داخل MVP |
|---|---|
| معماری | `music-wave-core` + `MusicWave` + `music-wave-vip` با مرزبندی روشن |
| داده | schema پایدار برای release/product/access/download |
| فرانت | block theme templates پایه + single/archive ضروری |
| بلاک‌ها | حداقل blockهای dynamic ضروری برای release info و gated download |
| WooCommerce | mapping محصول، تشخیص خرید، CTAهای خرید |
| Membership | حداقل یک مدل عضویت/provider عملیاتی |
| Download | resolver امن، token expiry، access validation |
| Admin | UI مدیریت متای ضروری و mappingها |
| QA | تست امنیت دسترسی و دانلود + smoke test سازگاری |
| Docs | نصب، پیکربندی، توسعه و release docs پایه |

## خارج از MVP

| بخش | خارج از MVP |
|---|---|
| Elementor Addon | کامل خارج از MVP |
| چندین provider دانلود | فقط یک provider اولیه داخل MVP؛ بقیه بعداً |
| analytics پیشرفته | خارج از MVP |
| dashboard گزارش‌گیری پیشرفته دانلود | خارج از MVP |
| wishlist / favorites | خارج از MVP |
| playlist builder | خارج از MVP |
| audio player پیشرفته اختصاصی | خارج از MVP مگر نیاز پایه نمایش preview |
| recommendation engine | خارج از MVP |
| multilingual strategy کامل | خارج از MVP |
| custom database tables غیرضروری | خارج از MVP مگر در فاز log اگر واقعاً لازم شود |
| advanced faceted search | خارج از MVP |
| mobile app API layer اختصاصی | خارج از MVP |

---

# 7) Milestone Plan

| Milestone | عنوان | اولویت | وضعیت | خروجی قابل تحویل |
|---|---|---|---|---|
| M1 | Foundation Setup | Must Have | Code Complete | اسکلت plugin/theme، استانداردها، CI، bootstrap |
| M2 | Data Model Freeze | Must Have | Code Complete | content model، meta schema، taxonomyها، migration base |
| M3 | Admin Authoring Flow | Must Have | Code Complete / Staging-tested | ورود و مدیریت داده در ادمین، mapping اولیه |
| M4 | Theme Rendering Base | Must Have | Code Complete / Staging-tested | block theme templates، dynamic release blocks، restricted state و polish اولیه |
| M5 | Commerce Integration | Must Have | Code Complete / Staging-tested | اتصال release به product، purchase detection |
| M6 | Access Control Engine | Must Have | Code Complete / Staging-tested | policy engine، membership/manual abstraction و deny-by-default |
| M7 | Secure Download MVP | Must Have | Next | tokenized resolver + access enforcement |
| M8 | VIP Integration MVP | Must Have | Pending | provider adapter و integration واقعی staging-tested |
| M9 | Frontend Completion | Should Have | Pending | single/archive polished + filter پایه |
| M10 | Hardening & Release | Must Have | Pending | امنیت، performance، docs، package نهایی |

---

# 8) Risk-based Development Notes

| حوزه پرریسک | چرا پرریسک است | چرا باید زودتر ساخته شود | اقدام پیشنهادی |
|---|---|---|---|
| Download Security | ریسک نشت فایل، bypass، share شدن لینک | اگر دیر ساخته شود، UI و data model روی فرض اشتباه بنا می‌شوند | در فاز M5-M7 prototype امنیتی ساخته و attack scenarios تست شود |
| Membership Integration | تفاوت providerها و status mapping | منطق access اگر دیر مشخص شود باعث refactor وسیع می‌شود | membership interface را قبل از پیاده‌سازی UI نهایی کنید |
| Block Rendering | dynamic/static boundary و data loading complexity | اگر دیر حل شود template و editor UX ناسازگار می‌شوند | از ابتدا block contract و render context تعریف شود |
| Meta Structure Consistency | بیشترین ریسک فنی در پروژه‌های WP همین بی‌ثباتی schema است | تغییر دیرهنگام schema migration و UI و queryها را می‌شکند | قبل از توسعه فرانت، meta dictionary freeze شود |
| Woo Purchase Rules | وضعیت سفارش، refund، guest purchase edge cases | access policy بدون این بخش ناقص است | ماتریس وضعیت سفارش در ابتدای E5 نهایی شود |
| Provider-Specific Download Logic | تفاوت URL signing، path access، remote storage behavior | coupling شدید با core ممکن است رخ دهد | adapter architecture پیش از provider implementation نهایی شود |
| Restricted Content Rendering | لو رفتن اطلاعات restricted در HTML/cache | bug امنیتی و تجربه بد کاربر | state-aware rendering policy در single/blockها از ابتدا اعمال شود |

---

# پیشنهاد ترتیب اجرای واقعی توسعه

| فاز | ترتیب |
|---|---|
| فاز 1 | E1 -> E2 |
| فاز 2 | E4 + E5 |
| فاز 3 | E6 + E7 + E8 |
| فاز 4 | E3 + E9 |
| فاز 5 | E10 + E11 + E12 |

نکته اجرایی مهم: از نظر ریسک، بهتر است پیاده‌سازی واقعی به این شکل باشد:
1. اول `schema + policy + download resolver contracts`
2. بعد `Woo/membership integration`
3. بعد `admin UI`
4. بعد `theme/block rendering polish`

یعنی ترتیب معماری با ترتیب ظاهری UI یکی نباشد.

---

# پیشنهاد ساختار Jira

| نوع آیتم | الگو |
|---|---|
| Epic | برای 12 بخش اصلی |
| Story | برای هر قابلیت قابل تحویل |
| Task | برای کار توسعه backend/frontend/integration/QA |
| Bug | فقط برای defectهای کشف‌شده در QA/UAT |
| Spike | برای بررسی provider membership/download ناشناخته |

---

# پیشنهاد Labelها برای مدیریت تسک

| Label | کاربرد |
|---|---|
| `mw-core` | مربوط به افزونه هسته |
| `mw-theme` | مربوط به قالب |
| `mw-vip` | مربوط به افزونه integration |
| `backend` | توسعه backend |
| `frontend` | توسعه frontend |
| `integration` | Woo/membership/download integration |
| `security` | موارد امنیتی |
| `schema` | meta/data model |
| `block-theme` | block theme and rendering |
| `mvp` | داخل MVP |
| `post-mvp` | خارج از MVP |

---

# جمع‌بندی اجرایی

اگر بخواهم این پروژه را به شکل مهندسی‌شده فریز کنم، ستون فقرات MVP این‌ها هستند:

| ستون اصلی MVP | وضعیت |
|---|---|
| schema پایدار داده | اجباری |
| Woo mapping و purchase detection | اجباری |
| membership abstraction | اجباری |
| secure download resolver | اجباری |
| block theme rendering پایه | اجباری |
| admin UX برای مدیریت داده | اجباری |
| provider integration اولیه در `music-wave-vip` | اجباری |

بخش‌هایی مثل فیلتر پیشرفته، گزارش‌گیری، analytics، Elementor addon و providerهای متعدد باید عمداً از MVP خارج بمانند تا تیم وارد فرسایش scope نشود.
