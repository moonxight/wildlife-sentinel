$ErrorActionPreference = 'Stop'
$conf = Join-Path $env:LOCALAPPDATA 'WildlifeSentinelDemo\apache.conf'
$running = @(Get-CimInstance Win32_Process -Filter "Name='httpd.exe'" | Where-Object { $_.CommandLine -like "*$conf*" })
if ($running.Count) {
    # This console Apache is not an installed Windows service. Stop only the
    # processes whose command lines identify this demo configuration.
    foreach ($process in $running) { Stop-Process -Id $process.ProcessId -ErrorAction SilentlyContinue }
    Write-Host 'Demo Apache stopped. MySQL and other XAMPP sites remain running.'
} else { Write-Host 'Demo Apache is already stopped.' }
