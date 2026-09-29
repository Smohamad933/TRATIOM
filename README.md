# راهنمای جامع استقرار و نصب پایگاه‌داده MySQL Server روی Windows Server + IIS
## پروژه پیکربندی تراریوم (Terrarium Configurator)

این سند راهنمای گام‌به‌گام و استاندارد سازمانی برای نصب، پیکربندی امن، بهینه‌سازی و اتصال پایگاه‌داده **MySQL 8.0+** روی محیط **Windows Server** به همراه وب‌سرور **IIS** و **PHP FastCGI** بدون نیاز به Docker یا هرگونه ابزار مجازی‌سازی است.

---

## ۱. پیش‌نیازهای سیستمی (System Requirements)
* **سیستم‌عامل:** Windows Server 2019 / 2022 (x64)
* **بسته‌های سیستمی مایکروسافت:** Microsoft Visual C++ 2015-2022 Redistributable (x64)
* **پایگاه‌داده:** MySQL Community Server 8.0.x یا 8.4 LTS (Windows MSI Installer یا ZIP Archive)
* **پی‌اچ‌پی:** PHP 8.3 / 8.4 x64 NTS (Non-Thread Safe) به همراه اکستنشن‌های `php_pdo_mysql.dll` و `php_mysqli.dll`

---

## ۲. مراحل نصب گام‌به‌گام MySQL Server در Windows Server

### روش اول: استفاده از MySQL Installer (پیشنهادی)
1. فایل **MySQL Community Installer** را دانلود کرده و اجرا کنید.
2. نوع نصب را **Custom** یا **Server only** انتخاب کنید.
3. در مرحله **Type and Networking**:
   * **Config Type:** روی `Server Computer` یا `Dedicated Computer` تنظیم شود.
   * **Connectivity:** پروتکل `TCP/IP` فعال و پورت پیش‌فرض `3306` تعیین شود.
   * گزینه `Open Windows Firewall ports for network access` تیک بخورد.
4. در مرحله **Authentication Method**:
   * گزینه **Use Strong Password Encryption (RECOMMENDED)** (پلاگین `caching_sha2_password`) یا در صورت تمایل `Legacy Authentication Method` را برگزینید. (PHP 8.2+ و `pdo_mysql` به طور کامل از `caching_sha2_password` پشتیبانی می‌کنند).
5. در مرحله **Accounts and Roles**:
   * یک رمز عبور فوق‌العاده قوی برای کاربر `root` تعیین کنید.
   * یک کاربر اختصاصی برای اپلیکیشن با نام `terrarium_user` بسازید (دسترسی محدود به هاست محلی `localhost` یا IP وب‌سرور).
6. در مرحله **Windows Service**:
   * نام سرویس را `MySQL80` بگذارید.
   * تیک گزینه `Start the MySQL Server at System Startup` را فعال کنید.
   * گزینه `Standard System Account` (یا کاربر اختصاصی ویندوز با دسترسی کنترل‌شده) را انتخاب نمایید.
7. روی **Execute** کلیک کنید تا نصب و راه‌اندازی سرویس ویندوز انجام شود.

---

## ۳. بهینه‌سازی فایل تنظیمات (`my.ini`) در Windows Server

فایل تنظیمات MySQL معمولاً در مسیر زیر قرار دارد:
`C:\ProgramData\MySQL\MySQL Server 8.0\my.ini`

تنظیمات حیاتی زیر را در بخش `[mysqld]` اعمال یا ویرایش کنید:

```ini
[mysqld]
# پورت و دایرکتوری‌ها
port=3306
basedir="C:/Program Files/MySQL/MySQL Server 8.0/"
datadir="C:/ProgramData/MySQL/MySQL Server 8.0/Data/"

# انکودینگ کاراکترها برای پشتیبانی کامل از زبان فارسی و ایموجی‌ها
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci

# موتور پیش‌فرض ذخیره‌سازی
default-storage-engine=INNODB

# بهینه‌سازی بافرها برای سرور تولیدی
innodb_buffer_pool_size=2G         # متناسب با حافظه رم سرور (بین ۵۰٪ تا ۷۰٪ کل رم)
innodb_log_file_size=512M
innodb_flush_log_at_trx_commit=1   # تضمین ۱۰۰٪ سازگاری ACID در تراکنش‌های مالی
innodb_file_per_table=1

# حداکثر اتصالات هم‌زمان
max_connections=300
max_connect_errors=10000

# مدیریت فرمت نام جداول در ویندوز (پیشگیری از باگ‌های Case-Sensitivity)
lower_case_table_names=1

# حالت سخت‌گیرانه SQL
sql_mode="STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE"
```

پس از ویرایش فایل `my.ini`، سرویس ویندوز را با دستور زیر در PowerShell (Run as Administrator) ری‌استارت کنید:
```powershell
Restart-Service -Name MySQL80
```

---

## ۴. ایجاد پایگاه‌داده و کاربر اختصاصی با حداقل دسترسی (Least Privilege)

وارد MySQL Command Line یا محیط کنسول شوید:
```bash
mysql -u root -p
```

دستورات زیر را به ترتیب اجرا نمایید:

```sql
-- ایجاد دیتابیس با انکودینگ استاندارد UTF8MB4
CREATE DATABASE terrarium_db 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

-- ایجاد کاربر اختصاصی اپلیکیشن
CREATE USER 'terrarium_user'@'localhost' IDENTIFIED BY 'YourStrongRandomPassword123#$';

-- اعطای دسترسی‌های لازم تنها به دیتابیس تراریوم
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER, REFERENCES, LOCK TABLES, EXECUTE 
ON terrarium_db.* TO 'terrarium_user'@'localhost';

-- اعمال تغییرات
FLUSH PRIVILEGES;
```

---

## ۵. فعال‌سازی اکستنشن‌های موردنیاز در `php.ini` برای IIS FastCGI

مسیر فایل `php.ini` خود را باز کرده و خطوط زیر را از حالت کامنت خارج (حذف `;` اول خط) کنید:

```ini
extension=curl
extension=fileinfo
extension=intl
extension=mbstring
extension=openssl
extension=pdo_mysql
extension=mysqli
```

سپس در خط فرمان ویندوز اطمینان حاصل کنید که درایور لود شده است:
```cmd
php -m | findstr pdo_mysql
```

---

## ۶. تنظیم متغیرهای محیطی در فایل `.env` پروژه لاراول

در فایل `.env` پروژه تنظیمات زیر را قرار دهید:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=terrarium_db
DB_USERNAME=terrarium_user
DB_PASSWORD=YourStrongRandomPassword123#$
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
```

سپس برای اعمال تغییرات و ساخت جداول دستورات زیر را اجرا کنید:
```cmd
php artisan config:clear
php artisan migrate --force
```

---

## ۷. ممیزی امنیتی دیتابیس در محیط Windows

1. **غیرفعال‌سازی دسترسی ریموت به Root:** اطمینان حاصل کنید کاربر `root` تنها از طریق `localhost` مجاز به اتصال باشد.
2. **پشتیبان‌گیری خودکار (Automated Backup):** ایجاد یک Task در Windows Task Scheduler با دستور زیر جهت تهیه نسخه پشتیبان روزانه:
   ```cmd
   mysqldump -u terrarium_user -pYourStrongPassword terrarium_db --single-transaction --quick > C:\Backups\MySQL\terrarium_db_%date:~-4,4%%date:~-10,2%%date:~-7,2%.sql
   ```
3. **محدودسازی مجوزهای فایل دیتابیس در NTFS:** مسیر `C:\ProgramData\MySQL\MySQL Server 8.0\Data` تنها باید توسط کاربر سرویس MySQL (مانند `Network Service` یا کاربر اختصاصی) قابل خواندن/نوشتن باشد و دسترسی سایر کاربران ویندوز مسدود شود.
