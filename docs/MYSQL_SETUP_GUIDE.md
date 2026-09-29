# راهنمای MySQL روی Windows Server

## نصب

1. [MySQL Installer](https://dev.mysql.com/downloads/installer/) را دانلود کنید و **Server only** را انتخاب کنید.
2. در مرحله‌ی پیکربندی:
   - Config Type: `Server Computer`
   - Authentication: **Strong Password Encryption** (PHP 8.3 پشتیبانی می‌کند)
   - سرویس ویندوز: `MySQL80`، با اجرای خودکار

## ساخت دیتابیس و کاربر

در `MySQL Command Line Client` با کاربر root:

```sql
CREATE DATABASE terrarium CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'terrarium'@'localhost' IDENTIFIED BY 'یک-رمز-قوی';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, DROP ON terrarium.* TO 'terrarium'@'localhost';
FLUSH PRIVILEGES;
```

## اتصال در `.env`

```ini
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=terrarium
DB_USERNAME=terrarium
DB_PASSWORD=یک-رمز-قوی
DB_CHARSET=utf8mb4
```

سپس جداول و داده‌های اولیه‌ی کاتالوگ را بسازید:

```powershell
C:\PHP83\php.exe bin\console migrate
```

## پشتیبان‌گیری روزانه

یک Scheduled Task بسازید که این دستور را اجرا کند (مسیر را با نسخه‌ی خود تطبیق دهید):

```bat
"C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe" -u terrarium -p"رمز" --single-transaction --routines terrarium > D:\backup\terrarium-%date:~-4%%date:~3,2%%date:~0,2%.sql
```

فایل‌های پشتیبان را روی درایو یا سرور دیگری هم کپی کنید.

## نکات

- پورت 3306 را در Windows Firewall از بیرون **باز نکنید**.
- برای تست روی کامپیوتر خودتان می‌توانید به‌جای MySQL از `DB_CONNECTION=sqlite` استفاده کنید. نیازی به نصب چیزی نیست.
