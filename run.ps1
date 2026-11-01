# Launches the dashboard on Windows. Manages native Windows runners.
# -Wsl forwards into WSL2 instead and manages Linux runners.

param(
    [int]$Port = 8090,
    [switch]$Wsl
)

$repoDir = Split-Path -Parent $MyInvocation.MyCommand.Path

if ($Wsl) {
    $wslCmd = Get-Command wsl -ErrorAction SilentlyContinue
    if (-not $wslCmd) {
        Write-Error "WSL2 is required for -Wsl. Install it with: wsl --install"
        exit 1
    }
    $wslPath = (wsl wslpath -a $repoDir.Replace('\', '/')).Trim()
    if ([string]::IsNullOrWhiteSpace($wslPath)) {
        Write-Error "Could not resolve this repo's path inside WSL."
        exit 1
    }
    wsl bash -c "cd '$wslPath' && ./run.sh $Port"
    exit $LASTEXITCODE
}

if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    Write-Error "php not found on PATH. Install PHP 8.1+ (winget install PHP.PHP) or use -Wsl."
    exit 1
}

$bindHost = if ($env:RUNNERDECK_BIND_HOST) { $env:RUNNERDECK_BIND_HOST } else { '127.0.0.1' }

if ($bindHost -ne '127.0.0.1' -and $bindHost -ne '::1') {
    php -r 'require $argv[1] . "/src/bootstrap.php"; exit(\RunnerDeck\Core\Config::authEnabled() ? 0 : 1);' -- $repoDir
    if ($LASTEXITCODE -ne 0) {
        Write-Error "Non-loopback bind requires TOTP login. Run: php bin/setup-totp.php"
        exit 1
    }
}

$watcher = Start-Process -FilePath php -ArgumentList "`"$repoDir\bin\watch-auto-restart.php`"" `
    -WindowStyle Hidden -PassThru

try {
    Write-Host "RunnerDeck on http://${bindHost}:${Port}"
    # php -S is single-threaded on Windows: an open live log blocks other requests.
    php -S "${bindHost}:${Port}" -t "$repoDir\public"
}
finally {
    if ($watcher -and -not $watcher.HasExited) {
        Stop-Process -Id $watcher.Id -Force -ErrorAction SilentlyContinue
    }
}
