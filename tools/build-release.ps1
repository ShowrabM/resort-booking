param(
  [string]$OutputPath = (Join-Path $PSScriptRoot "..\\resort-booking.zip")
)

$pluginRoot = Resolve-Path (Join-Path $PSScriptRoot "..")
$temp = Join-Path ([System.IO.Path]::GetTempPath()) ("rbw-release-" + [System.Guid]::NewGuid().ToString("N"))

New-Item -ItemType Directory -Path $temp | Out-Null

# Exclude bulky or non-release directories to keep the plugin zip small.
$excludeDirs = @(
  ".git",
  "node_modules",
  "vendor\\mpdf\\mpdf\\ttfonts",
  "vendor\\mpdf\\mpdf\\ttfontdata",
  "vendor\\mpdf\\mpdf\\tmp",
  "vendor\\mpdf\\mpdf\\.github"
)

$xd = $excludeDirs | ForEach-Object { Join-Path $pluginRoot $_ }

$robocopyArgs = @(
  $pluginRoot,
  $temp,
  "/E",
  "/NFL",
  "/NDL",
  "/NJH",
  "/NJS",
  "/NC",
  "/NS"
)

foreach ($d in $xd) {
  $robocopyArgs += @("/XD", $d)
}

$robocopyArgs += @("/XF", ".DS_Store", "Thumbs.db")

robocopy @robocopyArgs | Out-Null

if (Test-Path $OutputPath) {
  Remove-Item $OutputPath -Force
}

$outputDir = Split-Path -Parent $OutputPath
if ($outputDir -and !(Test-Path $outputDir)) {
  New-Item -ItemType Directory -Path $outputDir | Out-Null
}

Add-Type -AssemblyName System.IO.Compression.FileSystem
[System.IO.Compression.ZipFile]::CreateFromDirectory(
  $temp,
  $OutputPath,
  [System.IO.Compression.CompressionLevel]::Optimal,
  $false
)

Remove-Item $temp -Recurse -Force

Write-Host "Release zip created: $OutputPath"
