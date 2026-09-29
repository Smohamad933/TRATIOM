# TRATIOM — پیکربند تراریوم (Terrarium Configurator)

اپلیکیشن PHP 8.3 با معماری DDD / Clean Architecture برای طراحی، قیمت‌گذاری و سفارش تراریوم.

## ساختار پروژه

```
config/                 تنظیمات درگاه پرداخت و پیامک
database/migrations/    مایگریشن‌های جداول
docs/                   راهنماهای استقرار (IIS، MySQL)
public/                 نقطه ورود وب (index.php، web.config، پنل ادمین)
src/
  Domain/               منطق اصلی کسب‌وکار (بدون وابستگی بیرونی)
    Catalog/            گیاه، سنگ، فیگور، اندازه شیشه، قوانین سازگاری
    Common/             Value Object ها و Enum ها (Money، Volume، OrderStatus، ...)
    Compatibility/      موتور بررسی سازگاری
    Configurator/       مدل پیکربندی تراریوم
    Ordering/           سفارش و اقلام سفارش (State Machine)
    Payment/            اینترفیس درگاه پرداخت
    Pricing/            موتور قیمت‌گذاری
  Application/          Use Case ها و DTO ها
  Infrastructure/       پیاده‌سازی درگاه‌ها (ZarinPal، Zibal، IDPay، Stripe) و پیامک (Kavenegar)
tests/Unit/             تست‌های واحد
```

## اجرا (محیط توسعه)

```bash
composer install
cp .env.example .env
php -S 0.0.0.0:8000 -t public
```

- API: `http://localhost:8000/`
- Health: `http://localhost:8000/health`
- پنل ادمین: `http://localhost:8000/admin`

## تست

```bash
vendor/bin/phpunit
```

## استقرار

- [راهنمای استقرار روی IIS](docs/IIS_DEPLOYMENT_GUIDE.md)
- [راهنمای نصب MySQL روی Windows Server](docs/MYSQL_SETUP_GUIDE.md)
