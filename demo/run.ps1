param([switch]$NoBrowser)
$ErrorActionPreference = 'Stop'
$repo = Split-Path $PSScriptRoot -Parent
$xampp = if ($env:XAMPP_HOME) { $env:XAMPP_HOME } else { 'C:\xampp' }
$php = Join-Path $xampp 'php\php.exe'
$apache = Join-Path $xampp 'apache\bin\httpd.exe'
$runtime = Join-Path $env:LOCALAPPDATA 'WildlifeSentinelDemo'
New-Item -ItemType Directory -Force $runtime | Out-Null
foreach ($file in @($php, $apache, (Join-Path $xampp 'mysql\bin\mysqld.exe'))) {
    if (!(Test-Path -LiteralPath $file)) { throw "Missing XAMPP component: $file. Set XAMPP_HOME if installed elsewhere." }
}
function PortOpen([int]$port) {
    $client = New-Object Net.Sockets.TcpClient
    try { $client.Connect('127.0.0.1', $port); return $true } catch { return $false } finally { $client.Dispose() }
}
if (!(PortOpen 3306)) {
    Start-Process -FilePath (Join-Path $xampp 'mysql\bin\mysqld.exe') -ArgumentList ('--defaults-file="' + (Join-Path $xampp 'mysql\bin\my.ini') + '" --standalone') -WindowStyle Hidden
    for ($i=0; $i -lt 30 -and !(PortOpen 3306); $i++) { Start-Sleep -Seconds 1 }
    if (!(PortOpen 3306)) { throw 'MySQL did not start. Check the XAMPP control panel.' }
}
& $php (Join-Path $PSScriptRoot 'setup.php')
if ($LASTEXITCODE -ne 0) { throw 'Database setup did not complete. See the message above.' }
$conf = Join-Path $runtime 'apache.conf'
$url = 'http://localhost:8088/wildlife-sentinel/login.php'
if (PortOpen 8088) {
    $running = Get-CimInstance Win32_Process -Filter "Name='httpd.exe'" | Where-Object { $_.CommandLine -like "*$conf*" }
    if (!$running) { throw 'Port 8088 is occupied by another application. Nothing was stopped.' }
} else {
    $x = $xampp.Replace('\','/'); $r = $repo.Replace('\','/'); $t = $runtime.Replace('\','/')
    $config = @"
ServerRoot "$x/apache"
Listen 127.0.0.1:8088
ServerName localhost:8088
PidFile "$t/apache.pid"
ErrorLog "$t/apache-error.log"
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule dir_module modules/mod_dir.so
LoadModule mime_module modules/mod_mime.so
LoadModule alias_module modules/mod_alias.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule headers_module modules/mod_headers.so
LoadModule expires_module modules/mod_expires.so
LoadModule filter_module modules/mod_filter.so
LoadModule deflate_module modules/mod_deflate.so
LoadFile "$x/php/php8ts.dll"
LoadFile "$x/php/libpq.dll"
LoadFile "$x/php/libsqlite3.dll"
LoadModule php_module "$x/php/php8apache2_4.dll"
PHPIniDir "$x/php"
TypesConfig conf/mime.types
DirectoryIndex index.php index.html
DocumentRoot "$r"
Alias /wildlife-sentinel "$r"
<Directory "$r">
    Options FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
<Directory "$r/demo">
    AllowOverride None
    Require all denied
</Directory>
<FilesMatch "\.php$">
    SetHandler application/x-httpd-php
</FilesMatch>
"@
    [IO.File]::WriteAllText($conf, $config, (New-Object Text.UTF8Encoding($false)))
    & $apache -t -f $conf
    if ($LASTEXITCODE -ne 0) { throw 'Demo Apache configuration check failed.' }
    Start-Process -FilePath $apache -ArgumentList ('-f "' + $conf + '"') -WindowStyle Hidden
    for ($i=0; $i -lt 15 -and !(PortOpen 8088); $i++) { Start-Sleep -Seconds 1 }
    if (!(PortOpen 8088)) { throw "Demo server did not start. See $runtime\apache-error.log" }
}
$response = Invoke-WebRequest -UseBasicParsing $url
if ($response.StatusCode -ne 200 -or $response.Content -notmatch 'name="csrf"') { throw 'Login page health check failed.' }
Write-Host "Demo running: $url"
Write-Host 'Local PC access only. Close demo Apache through its process or restart Windows when finished.'
if (!$NoBrowser) { Start-Process $url }
