$ErrorActionPreference = 'Stop'
$projectDir = $PSScriptRoot
$workspaceDir = Split-Path (Split-Path $projectDir)
$localPhp = Join-Path $workspaceDir 'work\laravel-runtime\herd\php.exe'
$phpCommand = Get-Command php -ErrorAction SilentlyContinue
$phpBin = if (Test-Path -LiteralPath $localPhp) { $localPhp } elseif ($phpCommand) { $phpCommand.Source } else { throw 'Instala PHP 8.4.1 o superior para abrir este proyecto Laravel.' }
Set-Location -LiteralPath $projectDir
if (-not (Test-Path -LiteralPath 'vendor\autoload.php')) { throw 'Faltan dependencias. Ejecuta composer install y vuelve a iniciar.' }
if (-not (Test-Path -LiteralPath '.env')) { Copy-Item -LiteralPath '.env.example' -Destination '.env'; & $phpBin artisan key:generate }
if (-not (Test-Path -LiteralPath 'database\database.sqlite')) { New-Item -ItemType File -Path 'database\database.sqlite' | Out-Null }
& $phpBin artisan migrate --force
& $phpBin artisan db:seed --class=DemoSeeder --force
Start-Process -FilePath $phpBin -ArgumentList @('artisan','queue:work','--sleep=1','--tries=3') -WorkingDirectory $projectDir -WindowStyle Hidden
Start-Process -FilePath $phpBin -ArgumentList @('artisan','schedule:work') -WorkingDirectory $projectDir -WindowStyle Hidden
Start-Process 'http://127.0.0.1:8000'
& $phpBin artisan serve --host=127.0.0.1 --port=8000
