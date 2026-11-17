# Installs a portable Grafana k6 binary into load-testing/.tools (no admin required).
#
#   powershell -ExecutionPolicy Bypass -File .\scripts\install-k6.ps1
#
# The binary is NOT committed (see ../.gitignore); this script can be re-run to
# update it. k6 can also be installed globally with `winget install k6` - in that
# case the runner scripts pick it up from PATH automatically.

param(
    [string]$Version = "latest"
)

$ErrorActionPreference = "Stop"

$root = Split-Path -Parent $PSScriptRoot
$toolsDir = Join-Path $root ".tools"
$k6Path = Join-Path $toolsDir "k6.exe"

if ($Version -eq "latest") {
    $release = Invoke-RestMethod -Uri "https://api.github.com/repos/grafana/k6/releases/latest" -Headers @{ "User-Agent" = "gims-load-testing" }
    $tag = $release.tag_name
} else {
    $tag = if ($Version.StartsWith("v")) { $Version } else { "v$Version" }
    $release = Invoke-RestMethod -Uri "https://api.github.com/repos/grafana/k6/releases/tags/$tag" -Headers @{ "User-Agent" = "gims-load-testing" }
}

$asset = $release.assets | Where-Object { $_.name -match "windows-amd64\.zip$" } | Select-Object -First 1
if (-not $asset) {
    throw "No windows-amd64 zip found in k6 release $tag. Install manually from https://github.com/grafana/k6/releases."
}

New-Item -ItemType Directory -Force -Path $toolsDir | Out-Null
$zipPath = Join-Path $toolsDir "k6.zip"

Write-Host "Downloading k6 $tag ..."
Invoke-WebRequest -Uri $asset.browser_download_url -OutFile $zipPath -UseBasicParsing

# Verify checksum when the release publishes one.
$checksumAsset = $release.assets | Where-Object { $_.name -match "checksums\.txt$" } | Select-Object -First 1
if ($checksumAsset) {
    $checksums = (Invoke-WebRequest -Uri $checksumAsset.browser_download_url -UseBasicParsing).Content
    $line = ($checksums -split "`n") | Where-Object { $_ -match [regex]::Escape($asset.name) } | Select-Object -First 1
    if ($line -and $line -match "^([0-9a-fA-F]{64})") {
        $expected = $Matches[1].ToLower()
        $actual = (Get-FileHash -Path $zipPath -Algorithm SHA256).Hash.ToLower()
        if ($expected -ne $actual) {
            Remove-Item $zipPath -Force
            throw "Checksum mismatch for $($asset.name) - download aborted."
        }
        Write-Host "Checksum verified."
    }
}

$extractDir = Join-Path $toolsDir "extract"
if (Test-Path $extractDir) { Remove-Item $extractDir -Recurse -Force }
Expand-Archive -Path $zipPath -DestinationPath $extractDir -Force

$exe = Get-ChildItem -Path $extractDir -Recurse -Filter "k6.exe" | Select-Object -First 1
if (-not $exe) { throw "k6.exe was not found in the downloaded archive." }

Move-Item -Path $exe.FullName -Destination $k6Path -Force
Remove-Item $zipPath -Force
Remove-Item $extractDir -Recurse -Force

Write-Host ""
Write-Host "k6 installed: $k6Path"
Write-Host (& $k6Path version)
Write-Host ""
Write-Host "Run the tests with: powershell -ExecutionPolicy Bypass -File .\scripts\run.ps1 -Script load -Profile smoke"
