# TRATIOM — پیکربند تراریوم (Terrarium Configurator)

اپلیکیشن PHP 8.3 با معماری DDD / Clean Architecture برای طراحی، قیمت‌گذاری و سفارش تراریوم.

## ساختار پروژه

```
bin/console             ابزار خط فرمان (migrate، admin:add، check، ...)
bootstrap/              راه‌اندازی برنامه و تعریف مسیرهای API
config/                 تنظیمات برنامه، دیتابیس، درگاه پرداخت و پیامک
deploy/windows/         اسکریپت نصب خودکار روی IIS
database/migrations/    مایگریشن‌های SQL (mysql / sqlite)
docs/                   راهنماهای استقرار (IIS، MySQL)
public/                 ریشه‌ی وب: index.php، فروشگاه، پنل ادمین (admin/)، web.config
src/
  Domain/               منطق اصلی کسب‌وکار (بدون وابستگی بیرونی)
    Catalog/            گیاه، سنگ، فیگور، اندازه شیشه، قوانین سازگاری
    Common/             Value Object ها و Enum ها (Money، Volume، OrderStatus، ...)
    Compatibility/      موتور بررسی سازگاری
    Configurator/       مدل پیکربندی تراریوم
    Ordering/           سفارش و اقلام سفارش (State Machine)
    Payment/            اینترفیس درگاه پرداخت
    Pricing/            موتور قیمت‌گذاری
  Application/          Use Case ها (ورود OTP، ثبت سفارش، پرداخت، مدیریت)
  Infrastructure/       دیتابیس (PDO)، درگاه‌ها، پیامک، HTTP، لاگ
  Kernel/ Presentation/ هسته‌ی HTTP، روتر، کنترلرها و میدل‌ورها
  Support/              Env، Config و توابع کمکی
tests/Unit/             تست‌های واحد
```

## امکانات

- **فروشگاه** (`/`): پیکربند تعاملی تراریوم با بررسی زنده‌ی سازگاری، ورود با کد پیامکی، ثبت سفارش، پرداخت آنلاین و پیگیری سفارش
- **پنل مدیریت** (`/admin/`): داشبورد، مدیریت سفارش‌ها و وضعیت‌ها، قیمت و موجودی، قوانین سازگاری، وضعیت سیستم
- **API** (`/api/v1/...`): JSON، احراز هویت با Bearer Token
- درگاه‌ها: زرین‌پال، زیبال، آیدی‌پی، Stripe و درگاه تست (فقط برای توسعه)
- پیامک: کاوه‌نگار (در حالت توسعه، کد در لاگ ذخیره می‌شود)
- دیتابیس: MySQL در سرور اصلی، SQLite برای توسعه

## اجرا (محیط توسعه)

```bash
cp .env.example .env        # DB_CONNECTION=sqlite و SMS_DEFAULT_PROVIDER=log و ENABLED_PAYMENT_GATEWAYS=test
php bin/console key:generate
php bin/console migrate
php bin/console admin:add 09120000000
php -S 0.0.0.0:8000 -t public public/index.php
```

- فروشگاه: `http://localhost:8000/`
- پنل مدیریت: `http://localhost:8000/admin/`
- Health: `http://localhost:8000/health`

در حالت `APP_ENV=local`، کد ورود روی صفحه نمایش داده می‌شود.

## تست

```bash
composer install && vendor/bin/phpunit
```

## استقرار روی Windows Server + IIS

```powershell
powershell -ExecutionPolicy Bypass -File deploy\windows\install.ps1 -HostName shop.example.ir
```

- [راهنمای کامل استقرار روی IIS](docs/IIS_DEPLOYMENT_GUIDE.md)
- [راهنمای نصب MySQL روی Windows Server](docs/MYSQL_SETUP_GUIDE.md)
