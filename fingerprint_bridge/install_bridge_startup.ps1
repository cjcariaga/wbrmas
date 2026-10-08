$ErrorActionPreference = 'Stop'
$bridgeRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$launcher = Join-Path $bridgeRoot 'start_bridge_hidden.vbs'
$releaseExe = Join-Path $bridgeRoot 'bin\x64\Release\FingerprintBridge.exe'
$startup = [Environment]::GetFolderPath('Startup')
$shortcutPath = Join-Path $startup 'WBRMAS Fingerprint Bridge.lnk'

if (-not (Test-Path $releaseExe)) {
    throw "Bridge executable not found: $releaseExe"
}

$shell = New-Object -ComObject WScript.Shell
$shortcut = $shell.CreateShortcut($shortcutPath)
$shortcut.TargetPath = Join-Path $env:WINDIR 'System32\wscript.exe'
$shortcut.Arguments = '"' + $launcher + '"'
$shortcut.WorkingDirectory = $bridgeRoot
$shortcut.Description = 'Starts the WBRMAS ZK9500 fingerprint bridge at Windows sign-in.'
$shortcut.Save()

Start-Process -FilePath $shortcut.TargetPath -ArgumentList $shortcut.Arguments -WorkingDirectory $bridgeRoot
Write-Host 'WBRMAS fingerprint bridge startup installed.' -ForegroundColor Green
Write-Host "Startup shortcut: $shortcutPath"
Write-Host 'The bridge was started in the background.'
