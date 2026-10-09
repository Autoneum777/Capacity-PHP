$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$router = Join-Path $PSScriptRoot 'dev_router.php'
$php = 'C:\php-8.5.11\php.exe'
if (-not (Test-Path $php)) {
    $php = 'php'
}
$phpDir = Split-Path -Parent $php
Set-Location $root
& $php -d "extension_dir=$phpDir\ext" -d extension=sqlite3 -d extension=pdo_sqlite -d extension=zip -d post_max_size=512M -d upload_max_filesize=512M -d memory_limit=512M -d display_errors=0 -d log_errors=1 -S 127.0.0.1:8765 -t $root $router
