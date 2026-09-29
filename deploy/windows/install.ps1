<#
.SYNOPSIS
  Installs / updates the Terrarium site on Windows Server + IIS.
.DESCRIPTION
  Run in an ELEVATED PowerShell from the project root, e.g.:
    powershell -ExecutionPolicy Bypass -File deploy\windows\install.ps1 -HostName shop.example.ir
  Prerequisites (see docs/IIS_DEPLOYMENT_GUIDE.md): PHP 8.3 x64 NTS in C:\PHP83, IIS URL Rewrite 2.1, MySQL 8.
  Safe to re-run: existing site/pool/handlers are reused.
#>
param(
    [string]$SiteName = "Terrarium",
    [string]$HostName = "",
    [int]$Port = 80,
    [string]$PhpPath = "C:\PHP83",
    [string]$AppRoot = (Resolve-Path "$PSScriptRoot\..\..").Path
)

$ErrorActionPreference = "Stop"
function Step($m) { Write-Host "==> $m" -ForegroundColor Cyan }
function Fail($m) { Write-Host "ERROR: $m" -ForegroundColor Red; exit 1 }

if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Fail "Run this script as Administrator."
}

$phpCgi = Join-Path $PhpPath "php-cgi.exe"
$phpExe = Join-Path $PhpPath "php.exe"
if (-not (Test-Path $phpCgi)) { Fail "php-cgi.exe not found in $PhpPath. Install PHP 8.3 x64 NTS there or pass -PhpPath." }
$publicDir = Join-Path $AppRoot "public"
if (-not (Test-Path (Join-Path $publicDir "index.php"))) { Fail "public\index.php not found under $AppRoot" }

Step "Installing IIS features (Web-Server, CGI)"
Install-WindowsFeature -Name Web-Server, Web-CGI, Web-Http-Errors, Web-Http-Logging, Web-Stat-Compression, Web-Dyn-Compression, Web-Filtering -IncludeManagementTools | Out-Null

if (-not (Test-Path "$env:SystemRoot\System32\inetsrv\rewrite.dll")) {
    Fail "IIS URL Rewrite 2.1 is not installed. Download: https://www.iis.net/downloads/microsoft/url-rewrite then re-run."
}

Import-Module WebAdministration
$appcmd = "$env:SystemRoot\System32\inetsrv\appcmd.exe"

Step "Registering PHP FastCGI application"
$fcgi = & $appcmd list config -section:system.webServer/fastCgi
if ($fcgi -notmatch [regex]::Escape($phpCgi)) {
    & $appcmd set config -section:system.webServer/fastCgi /+"[fullPath='$phpCgi',maxInstances='0',instanceMaxRequests='10000',activityTimeout='300',requestTimeout='300']" /commit:apphost | Out-Null
    & $appcmd set config -section:system.webServer/fastCgi /+"[fullPath='$phpCgi'].environmentVariables.[name='PHP_FCGI_MAX_REQUESTS',value='10000']" /commit:apphost | Out-Null
}

Step "Creating application pool"
$pool = "$SiteName`Pool"
if (-not (Test-Path "IIS:\AppPools\$pool")) { New-WebAppPool -Name $pool | Out-Null }
Set-ItemProperty "IIS:\AppPools\$pool" -Name managedRuntimeVersion -Value ""
Set-ItemProperty "IIS:\AppPools\$pool" -Name processModel.idleTimeout -Value ([TimeSpan]::Zero)
Set-ItemProperty "IIS:\AppPools\$pool" -Name startMode -Value "AlwaysRunning"

Step "Creating website -> $publicDir"
if (-not (Test-Path "IIS:\Sites\$SiteName")) {
    New-Website -Name $SiteName -PhysicalPath $publicDir -ApplicationPool $pool -Port $Port -HostHeader $HostName | Out-Null
} else {
    Set-ItemProperty "IIS:\Sites\$SiteName" -Name physicalPath -Value $publicDir
    Set-ItemProperty "IIS:\Sites\$SiteName" -Name applicationPool -Value $pool
}

Step "Adding PHP handler mapping for the site"
$handlers = & $appcmd list config "$SiteName" -section:system.webServer/handlers
if ($handlers -notmatch "PHP_via_FastCGI_Terrarium") {
    & $appcmd set config "$SiteName" -section:system.webServer/handlers /+"[name='PHP_via_FastCGI_Terrarium',path='*.php',verb='GET,HEAD,POST',modules='FastCgiModule',scriptProcessor='$phpCgi',resourceType='Either']" | Out-Null
}

Step "Setting folder permissions"
# App pool identity: read on the whole app, modify on storage (logs, sqlite)
$identity = "IIS AppPool\$pool"
icacls $AppRoot /grant "${identity}:(OI)(CI)RX" /T /Q | Out-Null
icacls (Join-Path $AppRoot "storage") /grant "${identity}:(OI)(CI)M" /T /Q | Out-Null
icacls (Join-Path $AppRoot "storage") /grant "IIS_IUSRS:(OI)(CI)M" /T /Q | Out-Null

Step "Preparing .env"
$envFile = Join-Path $AppRoot ".env"
if (-not (Test-Path $envFile)) {
    Copy-Item (Join-Path $AppRoot ".env.example") $envFile
    & $phpExe (Join-Path $AppRoot "bin\console") key:generate
    Write-Host "  .env created. EDIT IT NOW (APP_URL, DB_*, SMS_*, ZARINPAL_*, ADMIN_MOBILES) then re-run this script." -ForegroundColor Yellow
    notepad $envFile
    exit 0
}
# Protect .env: remove inherited permissions for the web users
icacls $envFile /inheritance:r /grant "Administrators:F" "SYSTEM:F" "${identity}:R" /Q | Out-Null

Step "Running database migrations"
& $phpExe (Join-Path $AppRoot "bin\console") migrate
if ($LASTEXITCODE -ne 0) { Fail "Migration failed. Check DB_* settings in .env and that MySQL is running." }

Step "Scheduling daily cleanup task"
$action = New-ScheduledTaskAction -Execute $phpExe -Argument "`"$(Join-Path $AppRoot 'bin\console')`" cleanup"
$trigger = New-ScheduledTaskTrigger -Daily -At 4am
Register-ScheduledTask -TaskName "$SiteName Cleanup" -Action $action -Trigger $trigger -User "SYSTEM" -Force | Out-Null

Step "Restarting site"
Restart-WebAppPool -Name $pool
Start-Website -Name $SiteName -ErrorAction SilentlyContinue

Step "Environment check"
& $phpExe (Join-Path $AppRoot "bin\console") check

Write-Host "`nDone. Open http://$(if ($HostName) { $HostName } else { 'localhost' }):$Port/  and  /admin/" -ForegroundColor Green
Write-Host "Next: bind an SSL certificate (e.g. win-acme), then enable the 'Force HTTPS' rule in public\web.config." -ForegroundColor Green
