$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$dist = Join-Path $root 'client\dist'
if (-not (Test-Path (Join-Path $dist 'index.html'))) {
    throw "Build output missing. Run npm run build first."
}

$assets = Join-Path $root 'assets'
if (Test-Path $assets) {
    Remove-Item -Recurse -Force $assets
}
New-Item -ItemType Directory -Force -Path $assets | Out-Null
Copy-Item -Force (Join-Path $dist 'index.html') (Join-Path $root 'index.html')
Copy-Item -Force -Recurse (Join-Path $dist 'assets\*') $assets
Copy-Item -Force (Join-Path $dist 'favicon-32.png') (Join-Path $root 'favicon-32.png') -ErrorAction SilentlyContinue
Copy-Item -Force (Join-Path $dist 'apple-touch-icon.png') (Join-Path $root 'apple-touch-icon.png') -ErrorAction SilentlyContinue
Copy-Item -Force (Join-Path $dist 'logo-autoneum.png') (Join-Path $root 'logo-autoneum.png') -ErrorAction SilentlyContinue
Copy-Item -Force (Join-Path $dist 'flag-en.png') (Join-Path $root 'flag-en.png') -ErrorAction SilentlyContinue
Write-Output "Published UI into $root"
