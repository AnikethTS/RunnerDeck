# Launches the dashboard on Windows via WSL2.
#
# Windows has no native posix_kill/pcntl (SIGTERM) support and this project's
# process-control layer depends on both, so there is no native-Windows code
# path — this script just forwards into WSL, which is Linux underneath.
# See README.md's "Platform support" section.

param(
    [int]$Port = 8090
)

$wsl = Get-Command wsl -ErrorAction SilentlyContinue
if (-not $wsl) {
    Write-Error "WSL2 is required to run this on Windows. Install it with: wsl --install"
    exit 1
}

$repoDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$wslPath = (wsl wslpath -a $repoDir.Replace('\', '/')).Trim()
if ([string]::IsNullOrWhiteSpace($wslPath)) {
    Write-Error "Could not resolve this repo's path inside WSL."
    exit 1
}

wsl bash -c "cd '$wslPath' && ./run.sh $Port"
