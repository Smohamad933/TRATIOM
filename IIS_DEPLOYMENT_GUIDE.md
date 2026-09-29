# راهنمای جامع استقرار پروژه تراریوم در Windows Server و IIS
## بدون نیاز به Docker، Apache یا Nginx

این راهنما فرآیند کامل استقرار وب‌سایت، تنظیم FastCGI، پیکربندی صف‌ها (Queue) و وظایف زمان‌بندی‌شده (Scheduler) را در محیط Windows Server تشریح می‌کند.

---

### ۱. نصب Roleها و Featureهای لازم در Windows Server
از طریق **Server Manager** یا دستور PowerShell زیر، وب‌سرور IIS و پیش‌نیازهای CGI را نصب کنید:

```powershell
Install-WindowsFeature -Name Web-Server, Web-CGI, Web-Http-Errors, Web-Http-Logging, Web-Stat-Compression, Web-Dyn-Compression, Web-Filtering -IncludeManagementTools
```

سپس ماژول **URL Rewrite 2.1** مایکروسافت را دانلود و نصب کنید.

---

### ۲. پیکربندی PHP FastCGI در IIS
1. نسخه **PHP 8.3/8.4 x64 NTS** را در مسیر `C:\PHP83` اکسترکت کنید.
2. فایل `php.ini-production` را به `php.ini` تغییر نام داده و موارد زیر را تنظیم کنید:
   ```ini
   cgi.fix_pathinfo=0
   fastcgi.impersonate=1
   memory_limit=256M
   upload_max_filesize=32M
   post_max_size=32M
   extension_dir="C:\PHP83\ext"
   extension=curl
   extension=fileinfo
   extension=intl
   extension=mbstring
   extension=openssl
   extension=pdo_mysql
   ```
3. در کنسول IIS Manager وارد **FastCGI Settings** شوید و یک Handler جدید تعریف کنید:
   * **Full Path:** `C:\PHP83\php-cgi.exe`
   * **Activity Timeout:** `300`
   * **Instance Max Requests:** `10000`
   * **Max Instances:** `10`
   * **Environment Variables:**
     * `PHP_FCGI_MAX_REQUESTS` = `10000`

---

### ۳. ایجاد وب‌سایت و تنظیم Application Pool اختصاصی
1. در IIS Manager روی **Application Pools** راست‌کلیک کرده و **Add Application Pool** را بزنید:
   * **Name:** `TerrariumPool`
   * **.NET CLR Version:** `No Managed Code`
   * **Managed Pipeline Mode:** `Integrated`
2. روی `TerrariumPool` کلیک راست کرده و وارد **Advanced Settings** شوید:
   * **Identity:** `ApplicationPoolIdentity`
   * **Idle Time-out (minutes):** `0` (برای جلوگیری از خاموش شدن پروسه در ساعات کم‌ترافیک)
3. ایجاد سایت در **Sites -> Add Website**:
   * **Site Name:** `TerrariumConfigurator`
   * **Application Pool:** `TerrariumPool`
   * **Physical Path:** `C:\inetpub\wwwroot\terrarium\public` (**دقیقاً پوشه public به عنوان Web Root**)
   * **Binding:** پورت `80` یا `443` همراه با SSL Certificate.

---

### ۴. تنظیم مجوزهای امنیتی سیستم فایل (NTFS Permissions)
برای امنیت بالا، دسترسی نوشتن فقط به پوشه‌های خاص اعطا می‌شود. دستورات زیر را در PowerShell در مسیر پروژه اجرا نمایید:

```powershell
$AppPoolUser = "IIS AppPool\TerrariumPool"
$ProjectPath = "C:\inetpub\wwwroot\terrarium"

# دسترسی خواندن برای کل پروژه
icacls $ProjectPath /grant "${AppPoolUser":(OI)(CI)R" /T

# دسترسی نوشتن منحصراً برای storage و bootstrap/cache
icacls "$ProjectPath\storage" /grant "${AppPoolUser":(OI)(CI)M" /T
icacls "$ProjectPath\bootstrap\cache" /grant "${AppPoolUser":(OI)(CI)M" /T
```

---

### ۵. راه‌اندازی دائمی Queue Worker در ویندوز با NSSM
جهت پردازش پیامک‌های OTP و تراکنش‌های بانکی بدون نیاز به Docker، از ابزار **NSSM (Non-Sucking Service Manager)** استفاده کنید:

```cmd
nssm.exe install TerrariumQueueWorker "C:\PHP83\php.exe" "C:\inetpub\wwwroot\terrarium\artisan queue:work --sleep=3 --tries=3 --max-time=3600"
nssm.exe set TerrariumQueueWorker AppDirectory "C:\inetpub\wwwroot\terrarium"
nssm.exe set TerrariumQueueWorker Start SERVICE_AUTO_START
nssm.exe start TerrariumQueueWorker
```

---

### ۶. تنظیم Task Scheduler برای Laravel Scheduler در ویندوز
یک وظیفه در Windows Task Scheduler بسازید تا هر ۱ دقیقه دستور زیر را اجرا کند:

* **Action:** Start a program
* **Program/script:** `C:\PHP83\php.exe`
* **Add arguments:** `artisan schedule:run`
* **Start in:** `C:\inetpub\wwwroot\terrarium`
