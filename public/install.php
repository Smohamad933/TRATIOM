<?php

declare(strict_types=1);

/**
 * Web installer: environment checks → database connection → .env → migrations → admin.
 * Locks itself after a successful install (storage/installed.lock). Delete that file to run it again.
 */

use Terrarium\Application\UseCases\Auth\OtpService;
use Terrarium\Infrastructure\Bale\BaleBotClient;
use Terrarium\Infrastructure\Persistence\Database;
use Terrarium\Infrastructure\Persistence\Migrator;
use Terrarium\Infrastructure\Persistence\Repositories\UserRepository;

$base = dirname(__DIR__);
$lockFile = $base . '/storage/installed.lock';
$envFile = $base . '/.env';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

require $base . '/bootstrap/app.php'; // autoloader only; nothing here depends on .env being valid

function h(mixed $v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

$locked = is_file($lockFile);
$messages = [];   // [type, html]
$log = [];        // step log of the install run

// ------------------------------------------------------------------ environment checks
$checks = [];
$add = function (string $label, bool $ok, string $fix = '', bool $required = true) use (&$checks) {
    $checks[] = compact('label', 'ok', 'fix', 'required');
};
$phpIni = php_ini_loaded_file() ?: 'php.ini (پیدا نشد! php.ini-production را به php.ini کپی کنید)';
$add('نسخه PHP ≥ 8.3 (فعلی: ' . PHP_VERSION . ')', PHP_VERSION_ID >= 80300, 'PHP 8.3 یا جدیدتر (x64 NTS) نصب کنید.');
foreach (['pdo_mysql' => true, 'pdo_sqlite' => false, 'curl' => true, 'mbstring' => true, 'openssl' => true] as $ext => $req) {
    $add("افزونه {$ext}", extension_loaded($ext), "در فایل <code>" . h($phpIni) . "</code> نقطه‌ویرگول اول خط <code>extension={$ext}</code> را پاک کنید و Application Pool را ری‌استارت کنید.", $req);
}
$storageOk = is_writable($base . '/storage') && is_writable($base . '/storage/logs');
$add('پوشه storage قابل نوشتن است', $storageOk, 'در CMD (Administrator):<br><code dir="ltr">icacls "' . h($base . '\\storage') . '" /grant "IIS_IUSRS:(OI)(CI)M" /T</code>');
$envWritable = is_file($envFile) ? is_writable($envFile) : is_writable($base);
$add('امکان نوشتن فایل .env', $envWritable, 'یا دسترسی بدهید:<br><code dir="ltr">icacls "' . h($base) . '" /grant "IIS_IUSRS:(M)"</code><br>یا بعد از نصب، متنی که نمایش داده می‌شود را دستی در فایل .env بگذارید.', false);
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$add('اتصال HTTPS', $https, 'برای درگاه پرداخت لازم است (win-acme یا SSL در CDN).', false);

// ------------------------------------------------------------------ current values (from existing .env or defaults)
$current = [];
foreach ([$base . '/.env.example', $envFile] as $f) {
    if (is_file($f)) {
        foreach (file($f, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/u', $line, $m)) {
                $v = trim($m[2]);
                if ($v !== '' && ($v[0] === '"' || $v[0] === "'")) { $v = substr($v, 1, -1); } else { $v = trim(preg_replace('/\s+#.*$/u', '', $v)); }
                $current[$m[1]] = $v;
            }
        }
    }
}
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$defaults = [
    'app_url' => ($https ? 'https://' : 'http://') . $host,
    'app_env' => 'production',
    'db_driver' => $current['DB_CONNECTION'] ?? 'mysql',
    'db_host' => $current['DB_HOST'] ?? '127.0.0.1',
    'db_port' => $current['DB_PORT'] ?? '3306',
    'db_name' => ($current['DB_DATABASE'] ?? '') === 'terrarium_db' ? 'terrarium' : ($current['DB_DATABASE'] ?? 'terrarium'),
    'db_user' => ($current['DB_USERNAME'] ?? '') === 'terrarium_user' ? 'root' : ($current['DB_USERNAME'] ?? 'root'),
    'db_pass' => '',
    'admin_mobile' => '',
    'sms_provider' => ($current['SMS_DEFAULT_PROVIDER'] ?? 'log') === 'kavenegar' ? 'kavenegar' : 'log',
    'sms_key' => $current['SMS_API_KEY'] ?? '',
    'sms_template' => $current['SMS_OTP_TEMPLATE'] ?? 'verify',
    'gateway' => 'bale',
    'merchant' => '',
    'sandbox' => '',
    'shipping' => '50000',
    'bale_token' => $current['BALE_BOT_TOKEN'] ?? '',
    'bale_safir' => $current['BALE_SAFIR_API_KEY'] ?? '',
    'bale_wallet' => $current['BALE_WALLET_TOKEN'] ?? '',
    'bale_otp' => '1',
    'bale_pay' => '1',
];
if (preg_match('/your|here|xxx/i', $defaults['sms_key'])) { $defaults['sms_key'] = ''; }
$in = $defaults;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($defaults as $k => $_) { $in[$k] = trim((string) ($_POST[$k] ?? '')); }
    $in['sandbox'] = isset($_POST['sandbox']) ? '1' : '';
    $in['bale_otp'] = isset($_POST['bale_otp']) ? '1' : '';
    $in['bale_pay'] = isset($_POST['bale_pay']) ? '1' : '';
}

// ------------------------------------------------------------------ helpers
function dbHint(Throwable $e): string
{
    $m = $e->getMessage();
    return match (true) {
        str_contains($m, 'could not find driver') => 'افزونه <b>pdo_mysql</b> در php.ini فعال نیست.',
        str_contains($m, '[1045]') || str_contains($m, 'Access denied') => 'نام کاربری یا رمز MySQL اشتباه است.',
        str_contains($m, '[2002]') || str_contains($m, '[2006]') || str_contains($m, 'gone away') || str_contains($m, 'refused') || str_contains($m, 'actively refused') => 'MySQL در دسترس نیست. سرویس MySQL80 در services.msc روشن است؟ آدرس و پورت درست است؟',
        str_contains($m, '[1049]') || str_contains($m, 'Unknown database') => 'دیتابیس وجود ندارد و این کاربر اجازه ساخت آن را ندارد. آن را در MySQL بسازید یا کاربر root بدهید.',
        str_contains($m, '[1044]') => 'این کاربر به دیتابیس دسترسی ندارد. GRANT ALL ON نام_دیتابیس.* TO کاربر.',
        str_contains($m, '2054') || str_contains($m, 'caching_sha2') => 'روش احراز هویت MySQL پشتیبانی نمی‌شود. PHP را به‌روز کنید یا کاربر را با mysql_native_password بسازید.',
        default => '',
    };
}

function dbConfig(array $in, string $base): array
{
    return $in['db_driver'] === 'sqlite'
        ? ['driver' => 'sqlite', 'database' => $base . '/storage/database/terrarium.sqlite']
        : ['driver' => 'mysql', 'host' => $in['db_host'], 'port' => (int) $in['db_port'], 'database' => $in['db_name'], 'username' => $in['db_user'], 'password' => $in['db_pass'], 'charset' => 'utf8mb4'];
}

function connectAndCreate(array $in, string $base, array &$log): Database
{
    if ($in['db_driver'] === 'mysql') {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $in['db_name'])) {
            throw new RuntimeException('نام دیتابیس فقط می‌تواند شامل حروف انگلیسی، عدد و _ باشد.');
        }
        $pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $in['db_host'], (int) $in['db_port']), $in['db_user'], $in['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $log[] = ['ok', 'اتصال به سرور MySQL ' . h($pdo->getAttribute(PDO::ATTR_SERVER_VERSION))];
        try {
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$in['db_name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $log[] = ['ok', 'دیتابیس <b>' . h($in['db_name']) . '</b> آماده است'];
        } catch (PDOException $e) {
            $log[] = ['warn', 'ساخت دیتابیس ممکن نشد (اگر از قبل وجود دارد مشکلی نیست): ' . h($e->getMessage())];
        }
    }
    $db = new Database(dbConfig($in, $base));
    $db->first('SELECT 1 AS ok');
    $log[] = ['ok', 'اتصال به دیتابیس ' . h($in['db_driver'] === 'sqlite' ? 'SQLite' : $in['db_name']) . ' برقرار شد'];
    return $db;
}

function envValue(string $v): string
{
    if ($v === '' || preg_match('/^[A-Za-z0-9_.:\/@,+-]+$/', $v)) { return $v; }
    return str_contains($v, '"') ? "'" . $v . "'" : '"' . $v . '"';
}

function buildEnv(array $in, string $template, string $existingKey): string
{
    $gw = $in['gateway'];
    $baleUser = $in['bale_username'] ?? '';
    $balePay = $gw !== 'bale' && $in['bale_pay'] && $in['bale_token'] !== '' && $in['bale_wallet'] !== '';
    $values = [
        'APP_ENV' => $in['app_env'] === 'local' ? 'local' : 'production',
        'APP_DEBUG' => 'false',
        'APP_URL' => rtrim($in['app_url'], '/'),
        'APP_KEY' => $existingKey !== '' && !str_contains($existingKey, 'GENERATE') ? $existingKey : 'base64:' . base64_encode(random_bytes(32)),
        'ADMIN_MOBILES' => $in['admin_mobile'],
        'SHIPPING_FLAT_RATE' => (string) ((int) $in['shipping'] * 10),
        'CORS_ALLOWED_ORIGINS' => '',
        'DB_CONNECTION' => $in['db_driver'],
        'DB_HOST' => $in['db_host'],
        'DB_PORT' => $in['db_port'],
        'DB_DATABASE' => $in['db_driver'] === 'sqlite' ? '' : $in['db_name'],
        'DB_USERNAME' => $in['db_user'],
        'DB_PASSWORD' => $in['db_pass'],
        'DB_CHARSET' => 'utf8mb4',
        'SMS_DEFAULT_PROVIDER' => $in['sms_provider'],
        'SMS_API_KEY' => $in['sms_key'],
        'SMS_OTP_TEMPLATE' => $in['sms_template'],
        'ENABLED_PAYMENT_GATEWAYS' => $balePay ? $gw . ',bale' : $gw,
        'DEFAULT_PAYMENT_GATEWAY' => $gw,
        'BALE_BOT_TOKEN' => $in['bale_token'],
        'BALE_BOT_USERNAME' => $baleUser,
        'BALE_WALLET_TOKEN' => $in['bale_wallet'],
        'BALE_SAFIR_API_KEY' => $in['bale_safir'],
        'BALE_OTP_ENABLED' => 'false',
    ];
    $sandbox = $in['sandbox'] ? 'true' : 'false';
    if ($gw === 'zarinpal') { $values += ['ZARINPAL_MERCHANT_ID' => $in['merchant'], 'ZARINPAL_SANDBOX' => $sandbox]; }
    if ($gw === 'zibal') { $values += ['ZIBAL_MERCHANT_ID' => $in['merchant'], 'ZIBAL_SANDBOX' => $sandbox]; }
    if ($gw === 'idpay') { $values += ['IDPAY_API_KEY' => $in['merchant'], 'IDPAY_SANDBOX' => $sandbox]; }

    foreach ($values as $k => $v) {
        $line = $k . '=' . envValue($v);
        $count = 0;
        $template = (string) preg_replace_callback('/^' . $k . '=.*$/mu', fn () => $line, $template, 1, $count);
        if ($count === 0) { $template = rtrim($template) . "\n" . $line . "\n"; }
    }
    return $template;
}

// ------------------------------------------------------------------ actions
$envPreview = null;
$done = false;
if (!$locked && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'test';
    try {
        if ($in['db_driver'] === 'mysql' && $in['db_host'] === '') { throw new RuntimeException('آدرس سرور دیتابیس را وارد کنید.'); }
        $mobile = $in['admin_mobile'] !== '' ? OtpService::normalizeMobile($in['admin_mobile']) : '';
        if ($action === 'install' && $mobile === '') { throw new RuntimeException('شماره موبایل مدیر را وارد کنید.'); }
        $in['admin_mobile'] = $mobile;
        if ($action === 'install' && $in['gateway'] === 'bale' && ($in['bale_token'] === '' || $in['bale_wallet'] === '')) { throw new RuntimeException('برای پرداخت با کیف پول بله، «توکن ربات» و «توکن کیف پول» را در بخش ربات بله وارد کنید.'); }
        if ($action === 'install' && !preg_match('#^https?://[^/\s]+$#', rtrim($in['app_url'], '/'))) { throw new RuntimeException('آدرس سایت باید مثل https://example.ir باشد.'); }

        $db = connectAndCreate($in, $base, $log);

        if ($action === 'install') {
            $ran = (new Migrator($db, $base . '/database/migrations'))->migrate();
            $log[] = ['ok', $ran ? 'جداول ساخته شد: ' . h(implode('، ', $ran)) : 'جداول از قبل وجود داشتند (به‌روز هستند)'];

            (new UserRepository($db))->setAdmin($mobile, true);
            $log[] = ['ok', 'شماره <b dir="ltr">' . h($mobile) . '</b> مدیر سایت شد'];

            if ($in['bale_token'] !== '') {
                try {
                    $me = (new BaleBotClient($in['bale_token']))->getMe();
                    $in['bale_username'] = (string) ($me['username'] ?? '');
                    $log[] = ['ok', 'ربات بله: <b dir="ltr">@' . h($in['bale_username']) . '</b>'];
                } catch (Throwable $e) {
                    $log[] = ['warn', 'اتصال به ربات بله ممکن نشد (توکن یا اینترنت سرور را بررسی کنید): ' . h($e->getMessage())];
                }
            }

            $template = is_file($envFile) ? (string) file_get_contents($envFile) : (string) file_get_contents($base . '/.env.example');
            $env = buildEnv($in, $template, $current['APP_KEY'] ?? '');
            if (@file_put_contents($envFile, $env) === false) {
                $envPreview = $env;
                $log[] = ['err', 'نوشتن فایل .env ممکن نشد (دسترسی). متن پایین را در فایل <code dir="ltr">' . h($envFile) . '</code> ذخیره کنید.'];
            } else {
                $log[] = ['ok', 'فایل تنظیمات .env ذخیره شد'];
            }

            if ($envPreview === null && ($in['bale_username'] ?? '') !== '') {
                if (str_starts_with($in['app_url'], 'https://')) {
                    try {
                        preg_match('/^APP_KEY=(.*)$/m', $env, $km);
                        $key = trim($km[1] ?? '', " \t\"'");
                        $key = str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key;
                        $hookUrl = rtrim($in['app_url'], '/') . '/api/v1/bale/webhook/' . \Terrarium\Kernel\Application::deriveBaleSecret($key);
                        (new BaleBotClient($in['bale_token']))->setWebhook($hookUrl);
                        $log[] = ['ok', 'ربات بله به سایت متصل شد (وب‌هوک)'];
                    } catch (Throwable $e) {
                        $log[] = ['warn', 'اتصال وب‌هوک بله ممکن نشد؛ بعداً از پنل مدیریت ← سیستم ← «اتصال ربات به سایت» بزنید. (' . h($e->getMessage()) . ')'];
                    }
                } else {
                    $log[] = ['warn', 'وب‌هوک بله فقط با https کار می‌کند. پس از نصب SSL از پنل مدیریت ← سیستم ← «اتصال ربات به سایت» را بزنید.'];
                }
            }

            if (@file_put_contents($lockFile, gmdate('c')) !== false) {
                $log[] = ['ok', 'نصب‌کننده قفل شد (برای اجرای دوباره، فایل storage\\installed.lock را پاک کنید)'];
            } else {
                $log[] = ['warn', 'قفل نصب‌کننده ساخته نشد؛ دسترسی نوشتن storage را بررسی کنید و بعداً install.php را پاک کنید.'];
            }
            $done = $envPreview === null;
            $messages[] = $done ? ['ok', 'نصب با موفقیت انجام شد 🎉'] : ['warn', 'نصب انجام شد، ولی فایل .env را باید دستی ذخیره کنید.'];
        } else {
            $messages[] = ['ok', 'اتصال به دیتابیس موفق بود. حالا «نصب» را بزنید.'];
        }
    } catch (Throwable $e) {
        $hint = $e instanceof PDOException ? dbHint($e) : '';
        $log[] = ['err', h($e->getMessage())];
        $messages[] = ['err', '<b>خطا:</b> ' . h($e->getMessage()) . ($hint ? '<br><b>راه‌حل:</b> ' . $hint : '')];
    }
}

$sel = fn (string $k, string $v) => $in[$k] === $v ? 'selected' : '';
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>نصب سایت تراریوم</title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <style>
    .wrap { max-width: 860px; margin: 32px auto; padding: 0 16px; }
    .check { display:flex; gap:10px; align-items:flex-start; padding:8px 0; border-bottom:1px solid var(--border, #e5e7eb); }
    .check:last-child { border-bottom:0; }
    .check .ic { width:22px; flex:none; text-align:center; }
    .fix { font-size:.85rem; color:#6b7280; margin-top:4px; }
    code { background:#f3f4f6; padding:1px 6px; border-radius:4px; font-size:.85em; word-break:break-all; }
    .fs { border:1px solid var(--border, #e5e7eb); border-radius:12px; padding:16px; margin:0 0 16px; }
    .fs legend { font-weight:700; padding:0 6px; }
    .grid-form { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .grid-form .full { grid-column:1/-1; }
    @media (max-width:640px){ .grid-form{ grid-template-columns:1fr; } }
    .grid-form label { display:flex; flex-direction:column; gap:4px; font-size:.9rem; }
    .grid-form input, .grid-form select { width:100%; }
    .log li { margin:4px 0; }
    textarea.envbox { width:100%; min-height:260px; direction:ltr; font-family:monospace; font-size:.8rem; }
    .hidden { display:none !important; }
  </style>
</head>
<body>
<div class="wrap">
  <h1>🌱 نصب سایت تراریوم</h1>

<?php if ($locked && !$done): ?>
  <div class="card">
    <div class="alert ok">سایت قبلاً نصب شده است.</div>
    <p>برای امنیت، این صفحه بعد از نصب قفل می‌شود. برای اجرای دوباره، فایل <code dir="ltr"><?= h($lockFile) ?></code> را روی سرور پاک کنید.</p>
    <p><a class="btn primary" href="/">فروشگاه</a> <a class="btn" href="/admin/">پنل مدیریت</a></p>
  </div>
<?php else: ?>

  <?php foreach ($messages as [$t, $html]): ?>
    <div class="alert <?= $t === 'ok' ? 'ok' : ($t === 'warn' ? 'warn' : 'err') ?>"><?= $html ?></div>
  <?php endforeach; ?>

  <?php if ($log): ?>
  <div class="card mb">
    <h3>گزارش مراحل</h3>
    <ul class="log">
      <?php foreach ($log as [$t, $html]): ?><li><?= $t === 'ok' ? '✅' : ($t === 'warn' ? '⚠️' : '❌') ?> <?= $html ?></li><?php endforeach; ?>
    </ul>
    <?php if ($envPreview !== null): ?>
      <textarea class="envbox" readonly onclick="this.select()"><?= h($envPreview) ?></textarea>
    <?php endif; ?>
    <?php if ($done): ?>
      <p class="mt"><a class="btn primary" href="/admin/">ورود به پنل مدیریت</a> <a class="btn" href="/">مشاهده فروشگاه</a></p>
      <p class="muted">در پنل مدیریت با شماره <b dir="ltr"><?= h($in['admin_mobile']) ?></b> وارد شوید. تب «وضعیت سیستم» جزئیات را نشان می‌دهد.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (!$done): ?>
  <div class="card mb">
    <h3>۱. بررسی سرور</h3>
    <?php foreach ($checks as $c): ?>
      <div class="check">
        <div class="ic"><?= $c['ok'] ? '✅' : ($c['required'] ? '❌' : '⚠️') ?></div>
        <div><?= h($c['label']) ?><?php if (!$c['required']): ?> <span class="muted">(اختیاری)</span><?php endif; ?>
          <?php if (!$c['ok'] && $c['fix']): ?><div class="fix"><?= $c['fix'] ?></div><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <p class="muted" dir="ltr" style="text-align:left;font-size:.8rem">php.ini: <?= h($phpIni) ?></p>
  </div>

  <form method="post" class="card" autocomplete="off">
    <h3>۲. تنظیمات</h3>

    <fieldset class="fs">
      <legend>سایت</legend>
      <div class="grid-form">
        <label class="full">آدرس سایت <input name="app_url" dir="ltr" value="<?= h($in['app_url']) ?>" placeholder="https://cloud.mohusyn.ir" required></label>
        <label>شماره موبایل مدیر <input name="admin_mobile" dir="ltr" value="<?= h($in['admin_mobile']) ?>" placeholder="09121234567"></label>
        <label>هزینه ارسال (تومان) <input name="shipping" type="number" min="0" dir="ltr" value="<?= h($in['shipping']) ?>"></label>
        <label class="full">حالت اجرا
          <select name="app_env">
            <option value="production" <?= $sel('app_env', 'production') ?>>Production (سایت اصلی)</option>
            <option value="local" <?= $sel('app_env', 'local') ?>>آزمایشی (کد ورود روی صفحه نمایش داده می‌شود)</option>
          </select>
        </label>
      </div>
    </fieldset>

    <fieldset class="fs">
      <legend>پایگاه داده</legend>
      <div class="grid-form">
        <label class="full">نوع
          <select name="db_driver" id="db_driver">
            <option value="mysql" <?= $sel('db_driver', 'mysql') ?>>MySQL / MariaDB (پیشنهادی)</option>
            <option value="sqlite" <?= $sel('db_driver', 'sqlite') ?>>SQLite (بدون نصب، فایل داخل storage)</option>
          </select>
        </label>
        <label class="my">آدرس سرور <input name="db_host" dir="ltr" value="<?= h($in['db_host']) ?>"></label>
        <label class="my">پورت <input name="db_port" dir="ltr" value="<?= h($in['db_port']) ?>"></label>
        <label class="my">نام دیتابیس (اگر نباشد ساخته می‌شود) <input name="db_name" dir="ltr" value="<?= h($in['db_name']) ?>"></label>
        <label class="my">نام کاربری <input name="db_user" dir="ltr" value="<?= h($in['db_user']) ?>"></label>
        <label class="my full">رمز عبور <input name="db_pass" type="password" dir="ltr" value="<?= h($in['db_pass']) ?>"></label>
      </div>
    </fieldset>

    <fieldset class="fs">
      <legend>پیامک ورود (فقط اگر ربات بله تنظیم نشود)</legend>
      <div class="grid-form">
        <label class="full">سرویس
          <select name="sms_provider" id="sms_provider">
            <option value="kavenegar" <?= $sel('sms_provider', 'kavenegar') ?>>کاوه‌نگار</option>
            <option value="log" <?= $sel('sms_provider', 'log') ?>>بدون پیامک (کد در لاگ ذخیره می‌شود؛ فقط آزمایش)</option>
          </select>
        </label>
        <label class="kv">API Key <input name="sms_key" dir="ltr" value="<?= h($in['sms_key']) ?>"></label>
        <label class="kv">نام قالب Verify <input name="sms_template" dir="ltr" value="<?= h($in['sms_template']) ?>"></label>
      </div>
    </fieldset>

    <fieldset class="fs">
      <legend>درگاه پرداخت</legend>
      <div class="grid-form">
        <label>درگاه
          <select name="gateway" id="gateway">
            <option value="bale" <?= $sel('gateway', 'bale') ?>>کیف پول بله (از طریق ربات بله)</option>
            <option value="zarinpal" <?= $sel('gateway', 'zarinpal') ?>>زرین‌پال</option>
            <option value="zibal" <?= $sel('gateway', 'zibal') ?>>زیبال</option>
            <option value="idpay" <?= $sel('gateway', 'idpay') ?>>آیدی‌پی</option>
            <option value="test" <?= $sel('gateway', 'test') ?>>درگاه تست (فقط آزمایش، پول واقعی نمی‌گیرد)</option>
          </select>
        </label>
        <label class="gw">مرچنت‌کد / API Key <input name="merchant" dir="ltr" value="<?= h($in['merchant']) ?>"></label>
        <label class="gw full" style="flex-direction:row;align-items:center;gap:8px"><input type="checkbox" name="sandbox" style="width:auto" <?= $in['sandbox'] ? 'checked' : '' ?>> حالت Sandbox (آزمایشی درگاه)</label>
      </div>
    </fieldset>

    <fieldset class="fs">
      <legend>ربات بله — ورود و پرداخت</legend>
      <div class="grid-form">
        <label class="full">توکن ربات (از @botfather در بله) <input name="bale_token" dir="ltr" placeholder="123456789:AbCd..." value="<?= h($in['bale_token']) ?>"></label>
        <label>کلید API سفیر (ارسال پیام بدون استارت) <input name="bale_safir" dir="ltr" value="<?= h($in['bale_safir']) ?>"></label>
        <label>توکن کیف پول (پرداخت) <input name="bale_wallet" dir="ltr" placeholder="WALLET-..." value="<?= h($in['bale_wallet']) ?>"></label>
        <p class="full fix">با وارد کردن توکن ربات، ورود سایت با «تأیید در ربات بله» انجام می‌شود (بدون کد و پیامک). سفیر اختیاری است: فقط برای ارسال خودکار پیام پرداخت به کسی که ربات را استارت نکرده.</p>
        <label class="full" style="flex-direction:row;align-items:center;gap:8px"><input type="checkbox" name="bale_pay" style="width:auto" <?= $in['bale_pay'] ? 'checked' : '' ?>> اگر درگاه دیگری انتخاب شد، کیف پول بله هم کنارش فعال باشد</label>
      </div>
    </fieldset>

    <div class="row gap wrap">
      <button class="btn" name="action" value="test">🔌 تست اتصال دیتابیس</button>
      <button class="btn primary" name="action" value="install">🚀 نصب</button>
    </div>
  </form>
  <?php endif; ?>
<?php endif; ?>
</div>
<script>
  const toggle = () => {
    const my = document.getElementById('db_driver')?.value === 'mysql';
    document.querySelectorAll('.my').forEach(e => e.classList.toggle('hidden', !my));
    const kv = document.getElementById('sms_provider')?.value === 'kavenegar';
    document.querySelectorAll('.kv').forEach(e => e.classList.toggle('hidden', !kv));
    const gv = document.getElementById('gateway')?.value; const gw = gv !== 'test' && gv !== 'bale';
    document.querySelectorAll('.gw').forEach(e => e.classList.toggle('hidden', !gw));
  };
  document.querySelectorAll('select').forEach(s => s.addEventListener('change', toggle));
  toggle();
</script>
</body>
</html>
