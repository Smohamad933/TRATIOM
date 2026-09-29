# راهنمای استقرار روی Windows Server + IIS

این راهنما سایت را از صفر روی **Windows Server 2019 / 2022** با **IIS** و **MySQL 8** راه‌اندازی می‌کند.

## ۱. پیش‌نیازها

| مورد | نسخه | لینک |
|---|---|---|
| PHP | 8.3 **x64 Non Thread Safe** | https://windows.php.net/download |
| Visual C++ Redistributable | 2015–2022 x64 | https://aka.ms/vs/17/release/vc_redist.x64.exe |
| IIS URL Rewrite | 2.1 | https://www.iis.net/downloads/microsoft/url-rewrite |
| MySQL | 8.0 یا 8.4 | [راهنمای MySQL](MYSQL_SETUP_GUIDE.md) |
| Composer (اختیاری) | 2.x | https://getcomposer.org — پروژه بدون Composer هم اجرا می‌شود |

### نصب PHP

1. فایل zip نسخه‌ی NTS x64 را در `C:\PHP83` باز کنید.
2. `php.ini-production` را به `php.ini` کپی کنید و این خطوط را فعال یا تنظیم کنید:

```ini
extension_dir = "ext"
extension=curl
extension=mbstring
extension=openssl
extension=pdo_mysql
extension=fileinfo
cgi.force_redirect = 0
cgi.fix_pathinfo = 1
fastcgi.impersonate = 1
date.timezone = Asia/Tehran
expose_php = Off
display_errors = Off
log_errors = On
upload_max_filesize = 2M
opcache.enable = 1
zend_extension=opcache
```

3. برای درگاه‌های پرداخت (HTTPS خروجی)، فایل [cacert.pem](https://curl.se/ca/cacert.pem) را در `C:\PHP83\extras\ssl\` قرار دهید و این دو خط را اضافه کنید:

```ini
curl.cainfo = "C:\PHP83\extras\ssl\cacert.pem"
openssl.cafile = "C:\PHP83\extras\ssl\cacert.pem"
```

4. آزمایش: `C:\PHP83\php.exe -m`. باید `curl`، `pdo_mysql`، `mbstring` و `openssl` در فهرست باشند.

## ۲. کپی پروژه

پروژه را مثلاً در `C:\inetpub\terrarium` قرار دهید (با `git clone` یا کپی فایل‌ها). **ریشه‌ی سایت فقط پوشه‌ی `public` است**؛ فایل `.env` و سورس‌ها بیرون از دسترس وب می‌مانند.

اگر Composer دارید: `composer install --no-dev --optimize-autoloader`. در غیر این صورت، لودر داخلی پروژه خودکار استفاده می‌شود.

## ۳. نصب خودکار (پیشنهادی)

در PowerShell **با دسترسی Administrator**:

```powershell
cd C:\inetpub\terrarium
powershell -ExecutionPolicy Bypass -File deploy\windows\install.ps1 -HostName shop.example.ir
```

اسکریپت این کارها را انجام می‌دهد:

- نصب قابلیت‌های IIS (CGI و غیره)
- ثبت PHP در FastCGI
- ساخت Application Pool و سایت
- تنظیم دسترسی پوشه‌ها (فقط `storage` قابل نوشتن است)
- ساخت `.env` و `APP_KEY`
- اجرای مایگریشن
- ساخت Scheduled Task برای پاک‌سازی روزانه
- اجرای `check`

**در اولین اجرا**، اسکریپت فایل `.env` را می‌سازد و در Notepad باز می‌کند. مقادیر را پر کنید (بخش ۴) و اسکریپت را **دوباره اجرا کنید**.

## ۴. تنظیم `.env`

| کلید | توضیح |
|---|---|
| `APP_ENV=production` `APP_DEBUG=false` | الزامی در سرور اصلی |
| `APP_URL` | آدرس کامل سایت، مثلاً `https://shop.example.ir`. آدرس بازگشت درگاه از همین ساخته می‌شود |
| `ADMIN_MOBILES` | شماره‌های مدیران، جدا شده با ویرگول: `09121234567,09351234567` |
| `DB_*` | اطلاعات MySQL |
| `SMS_DEFAULT_PROVIDER=kavenegar` `SMS_API_KEY` `SMS_OTP_TEMPLATE` | کلید API و نام قالب Verify در پنل کاوه‌نگار. قالب باید متغیر `%token%` داشته باشد |
| `ENABLED_PAYMENT_GATEWAYS` | مثلاً `zarinpal` یا `zarinpal,zibal`. **هرگز `test` را در production فعال نکنید** |
| `ZARINPAL_MERCHANT_ID` `ZARINPAL_SANDBOX=false` | مرچنت‌کد زرین‌پال |
| `SHIPPING_FLAT_RATE` | هزینه‌ی ارسال به **ریال** |

> تمام مبالغ داخل سیستم به **ریال** ذخیره می‌شوند و در سایت به تومان نمایش داده می‌شوند.

## ۵. SSL (الزامی برای درگاه پرداخت)

1. گواهی رایگان Let's Encrypt را با [win-acme](https://www.win-acme.com/) بگیرید: `wacs.exe` را اجرا کنید و سایت Terrarium را انتخاب کنید. تمدید خودکار است.
2. در `public\web.config`، قانون `Force HTTPS` را با `enabled="true"` فعال کنید.
3. `APP_URL` را به `https://...` تغییر دهید.

## ۶. بررسی نهایی

```powershell
C:\PHP83\php.exe bin\console check          # وضعیت محیط
C:\PHP83\php.exe bin\console migrate:status # مایگریشن‌ها
```

- `https://shop.example.ir/health` باید `{"status":"ok"}` برگرداند.
- `https://shop.example.ir/admin/`: با یکی از شماره‌های `ADMIN_MOBILES` وارد شوید. تب «وضعیت سیستم» همه چیز را نشان می‌دهد.
- یک سفارش آزمایشی با زرین‌پال sandbox (`ZARINPAL_SANDBOX=true`) ثبت کنید، سپس sandbox را خاموش کنید.

## ۷. دستورات مدیریتی

```powershell
php bin\console admin:add 09121234567     # افزودن مدیر
php bin\console admin:remove 09121234567  # حذف مدیر
php bin\console cleanup                   # پاک‌سازی کدهای OTP و توکن‌های منقضی (روزانه خودکار)
php bin\console key:generate              # ساخت APP_KEY جدید
```

## ۸. به‌روزرسانی سایت

```powershell
cd C:\inetpub\terrarium
git pull
C:\PHP83\php.exe bin\console migrate
Restart-WebAppPool TerrariumPool
```

## ۹. عیب‌یابی

| مشکل | راه‌حل |
|---|---|
| خطای 500.19 | URL Rewrite نصب نیست |
| خطای 500 بدون پیام | لاگ `storage\logs\app-YYYY-MM-DD.log` را ببینید. موقتاً `APP_DEBUG=true` بگذارید |
| `could not find driver` | `extension=pdo_mysql` در php.ini فعال نیست |
| درگاه وصل نمی‌شود / خطای SSL | `curl.cainfo` تنظیم نشده (بخش ۱) |
| پیامک ارسال نمی‌شود | IP سرور را در پنل کاوه‌نگار مجاز کنید؛ نام قالب را بررسی کنید |
| Permission denied در storage | اسکریپت نصب را دوباره اجرا کنید یا به `IIS AppPool\TerrariumPool` دسترسی Modify بدهید |
| 404 روی `/api/...` | ریشه‌ی سایت باید پوشه‌ی `public` باشد و `web.config` در آن وجود داشته باشد |

## امنیت

- `.env` خارج از `public` است و اسکریپت دسترسی آن را به Administrators و Application Pool محدود می‌کند.
- پرداخت فقط پس از **verify سمت سرور** با درگاه تأیید می‌شود. مبلغ از دیتابیس خوانده می‌شود، نه از درخواست کاربر. callback تکراری یا جعلی اثری ندارد.
- ورود با OTP محدودیت تعداد درخواست دارد (هر شماره، هر IP) و تعداد تلاش هر کد محدود است.
- پورت 3306 (MySQL) را در فایروال از بیرون باز نکنید؛ PHP از طریق localhost وصل می‌شود.
