<#
.SYNOPSIS
  Gives a Windows PC a LINUX CI runner (Forgejo Actions) that lives inside WSL2: JumPHP's own builds, Android,
  the engine tests, anything that needs Docker. No Docker Desktop needed.

.DESCRIPTION
  Run it in an elevated PowerShell (Administrator), Windows PowerShell 5.1 or later, on Windows 10 2004+ / 11 with
  virtualization enabled in the BIOS. It is idempotent: after a reboot (WSL may ask for one) or an error, run the
  SAME command again and it continues where it stopped. The registration token is never stored, so it is asked
  again on a re-run (only needed while the runner is not registered yet).

  What it does:
    1. enables WSL and the Virtual Machine Platform (a reboot is usually needed the first time: the script stops
       and tells you; run it again afterwards)
    2. installs an Ubuntu distribution (default Ubuntu-24.04) with a non-root user, systemd enabled (/etc/wsl.conf)
    3. installs Docker Engine INSIDE that distribution from Docker's official apt repository (the repository key is
       checked against Docker's published fingerprint)
    4. gets JumPHP into the distribution (git clone of the forge) and runs
         bin/jumphp runner:install --scope <scope> --name <name>
       there: a Docker-based runner scoped to ONE user/organization, with its own Docker daemon for the jobs
    5. installs a scheduled task that keeps the distribution (and so dockerd and the runner containers) running
       after every logon (or every boot with -RunAtBoot)

  SECURITY: scope the runner to ONE user or organization. Jobs run in containers, inside a WSL2 virtual machine, but
  the runner's Docker daemon is privileged: do not register a runner for people you do not trust (docs/ci-runner.md).

.PARAMETER Scope       org/<name> or user/<login> (required). The runner serves only that owner's repositories.
.PARAMETER Token       Registration token (forge: Settings > Actions > Runners > Create new runner). Better: the
                       environment variable JUMPHP_RUNNER_TOKEN; otherwise the script asks (hidden input).
.PARAMETER Name        Runner name. Default wsl-<computer name>
.PARAMETER Instance    The forge. Default https://git.jumphp.net
.PARAMETER Labels      Labels for the runner (default: bin/jumphp's, ubuntu-latest and ubuntu-22.04 in Docker)
.PARAMETER Distro      WSL distribution to install. Default Ubuntu-24.04 (list: wsl --list --online)
.PARAMETER LinuxUser   The non-root user created in it. Default jumphp
.PARAMETER JumphpRepo  Where JumPHP is cloned from. Default https://git.jumphp.net/jumphp/jumPhp.git
.PARAMETER Memory      Optional WSL2 memory limit written to %USERPROFILE%\.wslconfig, e.g. 8GB (WSL's default is half the RAM)
.PARAMETER Processors  Optional WSL2 CPU limit written to .wslconfig
.PARAMETER RunAtBoot   Start at boot even when nobody is logged on (asks for your Windows password, kept by Windows'
                       Task Scheduler only). Default: start at your logon.
.PARAMETER Uninstall   Remove the runner (containers, files), its scheduled task. The distribution stays.
.PARAMETER RemoveDistro With -Uninstall: also DELETE the whole distribution (everything in it is lost)
.PARAMETER Force       With -RemoveDistro: no confirmation

.EXAMPLE
  $env:JUMPHP_RUNNER_TOKEN = '<token>'
  .\install-wsl-linux-runner.ps1 -Scope user/alice -Name alice-pc
#>
[CmdletBinding()]
param(
    [string]$Scope = '',
    [string]$Token = '',
    [string]$Name = '',
    [string]$Instance = 'https://git.jumphp.net',
    [string]$Labels = '',
    [string]$Distro = 'Ubuntu-24.04',
    [string]$LinuxUser = 'jumphp',
    [string]$JumphpRepo = 'https://git.jumphp.net/jumphp/jumPhp.git',
    [string]$Memory = '',
    [string]$Processors = '',
    [switch]$RunAtBoot,
    [switch]$Uninstall,
    [switch]$RemoveDistro,
    [switch]$Force
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
Set-StrictMode -Version 2

function Say($m)  { Write-Host ("[{0}] {1}" -f (Get-Date -Format 'HH:mm:ss'), $m) }
function Warn($m) { Write-Host ("[{0}] WARNING: {1}" -f (Get-Date -Format 'HH:mm:ss'), $m) -ForegroundColor Yellow }
function Fail($m) { throw $m }

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Fail 'Run this script in a PowerShell opened as Administrator (right click > Run as administrator).'
}
# WSL distributions belong to ONE Windows user: the one running this script. Do not run it as SYSTEM or another account.
if ($Name -eq '') { $Name = 'wsl-' + $env:COMPUTERNAME.ToLower() }
if ($Name -notmatch '^[A-Za-z0-9._-]+$') { Fail '-Name: letters, digits . _ - only' }
if ($Distro -notmatch '^[A-Za-z0-9._-]+$') { Fail '-Distro: a name from `wsl --list --online`' }
if ($LinuxUser -notmatch '^[a-z_][a-z0-9_-]{0,30}$') { Fail '-LinuxUser: a Linux user name (lowercase)' }
if ($Instance -notmatch '^https?://[^/\s]+$') { Fail '-Instance must look like https://git.jumphp.net' }
if ($JumphpRepo -notmatch '^https://[A-Za-z0-9._/-]+$') { Fail '-JumphpRepo: an https URL' }
if ($Labels -ne '' -and $Labels -notmatch '^[A-Za-z0-9._:/,@-]+$') { Fail '-Labels: unexpected characters' }
if ($Memory -ne '' -and $Memory -notmatch '^\d+(GB|MB)$') { Fail '-Memory like 8GB' }
if ($Processors -ne '' -and $Processors -notmatch '^\d+$') { Fail '-Processors is a number' }
$TaskName = "JumphpWslRunner-$Name"
$WorkDir  = Join-Path $env:ProgramData 'jumphp-runner'

# ---- helpers ------------------------------------------------------------------------------------------------
# wsl.exe prints UTF-16 for its own messages (lists, status): drop the NULs.
function Get-WslText([string[]]$WslArgs) {
    $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try { $out = & wsl.exe @WslArgs 2>&1 | ForEach-Object { ("$_" -replace "`0", '') } } finally { $ErrorActionPreference = $old }
    return @($out)
}
function Test-DistroInstalled {
    $l = Get-WslText @('--list', '--quiet')
    foreach ($e in $l) { if ($e.Trim() -ieq $Distro) { return $true } }
    return $false
}
function Convert-ToWslPath([string]$p) {
    $drive = $p.Substring(0, 1).ToLower()
    return '/mnt/' + $drive + ($p.Substring(2) -replace '\\', '/')
}
# Run a bash script (text) in the distribution as $User. The script is written to a file (LF line endings, no BOM)
# under %ProgramData% and run through /mnt/c: no quoting problems, and nothing secret goes on a command line.
function Invoke-WslScript([string]$Text, [string]$User, [string]$StepName) {
    New-Item -ItemType Directory -Force -Path $WorkDir | Out-Null
    $file = Join-Path $WorkDir ("$StepName.sh")
    [IO.File]::WriteAllText($file, ($Text -replace "`r`n", "`n"), (New-Object Text.UTF8Encoding $false))
    $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try {
        & wsl.exe -d $Distro -u $User --exec bash (Convert-ToWslPath $file) 2>&1 | ForEach-Object { Write-Host ("  " + ("$_" -replace "`0", '')) }
        $code = $LASTEXITCODE
    } finally { $ErrorActionPreference = $old; Remove-Item $file -Force -ErrorAction SilentlyContinue }
    if ($code -ne 0) { Fail "step '$StepName' failed in $Distro (exit code $code); see the lines above" }
}
function Get-WslOutput([string]$User, [string[]]$Command) {
    $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try { $o = & wsl.exe -d $Distro -u $User --exec @Command 2>&1 | ForEach-Object { ("$_" -replace "`0", '') } } finally { $ErrorActionPreference = $old }
    return @($o)
}
function Wait-Systemd {
    for ($i = 0; $i -lt 40; $i++) {
        $s = (Get-WslOutput 'root' @('systemctl', 'is-system-running')) -join ''
        if ($s -match '^(running|degraded)') { return }
        Start-Sleep -Seconds 2
    }
    Fail "systemd did not start in $Distro (is systemd=true in /etc/wsl.conf? wsl --shutdown, then run the script again)"
}
function Set-IniValue([string]$Path, [string]$Section, [string]$Key, [string]$Value) {
    $lines = @(); if (Test-Path $Path) { $lines = @(Get-Content $Path) }
    $out = New-Object System.Collections.ArrayList; $inSec = $false; $done = $false; $seen = $false
    foreach ($l in $lines) {
        if ($l -match '^\s*\[(.+)\]\s*$') {
            if ($inSec -and -not $done) { [void]$out.Add("$Key=$Value"); $done = $true }
            $inSec = ($Matches[1] -ieq $Section); if ($inSec) { $seen = $true }
        } elseif ($inSec -and $l -match ('^\s*' + [regex]::Escape($Key) + '\s*=')) { $l = "$Key=$Value"; $done = $true }
        [void]$out.Add($l)
    }
    if ($seen -and -not $done) { [void]$out.Add("$Key=$Value"); $done = $true }
    if (-not $seen) { [void]$out.Add("[$Section]"); [void]$out.Add("$Key=$Value") }
    Set-Content -Path $Path -Value $out -Encoding ASCII
}

# ---- uninstall ----------------------------------------------------------------------------------------------
if ($Uninstall) {
    Say "uninstalling the runner '$Name'"
    if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
        Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
        Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false; Say "scheduled task $TaskName removed"
    }
    if (Test-DistroInstalled) {
        if ($RemoveDistro) {
            if (-not $Force) { $a = Read-Host "This DELETES the distribution $Distro and everything in it. Type yes to continue"; if ($a -ne 'yes') { Fail 'cancelled' } }
            & wsl.exe --unregister $Distro
            Say "distribution $Distro deleted"
        } else {
            $rm = "set -e`nsystemctl start docker || true`nsu - $LinuxUser -c 'cd ~/jumphp 2>/dev/null && sg docker -c `"bin/jumphp runner:remove --name $Name`"' || echo 'no runner $Name to remove'`n"
            try { Invoke-WslScript $rm 'root' 'runner-remove' } catch { Warn $_.Exception.Message }
        }
    }
    Write-Host "Done. Still to do by hand: delete the runner '$Name' in the forge (Settings > Actions > Runners)."
    return
}

# ---- parameters ---------------------------------------------------------------------------------------------
if ($Scope -notmatch '^(org|user)/[A-Za-z0-9._-]+$') {
    Fail '-Scope org/<name> or user/<login> is required: the runner serves ONE organization or user, never the whole instance.'
}
if ($Token -eq '' -and $env:JUMPHP_RUNNER_TOKEN) { $Token = $env:JUMPHP_RUNNER_TOKEN }
$Token = $Token.Trim()
Say "Linux runner '$Name' for $Scope on $Instance, in WSL2 ($Distro)"
$build = [Environment]::OSVersion.Version.Build
if ($build -lt 19041) { Fail "WSL2 needs Windows 10 version 2004 (build 19041) or newer; this is build $build" }

# ---- 1. WSL + Virtual Machine Platform --------------------------------------------------------------------
$needReboot = $false
foreach ($f in @('Microsoft-Windows-Subsystem-Linux', 'VirtualMachinePlatform')) {
    $st = Get-WindowsOptionalFeature -Online -FeatureName $f
    if ($st.State -ne 'Enabled') {
        Say "enabling Windows feature $f"
        $r = Enable-WindowsOptionalFeature -Online -FeatureName $f -NoRestart
        if ($r.RestartNeeded) { $needReboot = $true }
    }
}
if ($needReboot) {
    Write-Host ''
    Warn 'WSL needs a restart of Windows to finish its setup.'
    Write-Host 'Restart now, then open an Administrator PowerShell and run THE SAME COMMAND again: it continues where it stopped.'
    Write-Host '(The token is not stored: set JUMPHP_RUNNER_TOKEN again, or paste it when asked.)'
    exit 3010
}
# the Store version of WSL (systemd support needs a recent one)
Say 'updating WSL'
$null = Get-WslText @('--update')
$null = Get-WslText @('--set-default-version', '2')

# ---- optional resource limits (.wslconfig) -------------------------------------------------------------------
if ($Memory -ne '' -or $Processors -ne '') {
    $cfg = Join-Path $env:USERPROFILE '.wslconfig'
    if ($Memory -ne '') { Set-IniValue $cfg 'wsl2' 'memory' $Memory }
    if ($Processors -ne '') { Set-IniValue $cfg 'wsl2' 'processors' $Processors }
    Say "limits written to $cfg (they apply after: wsl --shutdown)"
    $null = Get-WslText @('--shutdown')
}

# ---- 2. the distribution --------------------------------------------------------------------------------------
if (Test-DistroInstalled) { Say "$Distro : already installed" }
else {
    Say "installing $Distro (a few minutes: download of about 400 MB)"
    $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try { & wsl.exe --install -d $Distro --no-launch 2>&1 | ForEach-Object { Write-Host ("  " + ("$_" -replace "`0", '')) } } finally { $ErrorActionPreference = $old }
    if (-not (Test-DistroInstalled)) {
        # older WSL: the package is installed but not registered until its launcher runs once; "install --root" does it
        # without asking for a user name
        $launcher = @{ 'Ubuntu-24.04' = 'ubuntu2404.exe'; 'Ubuntu-22.04' = 'ubuntu2204.exe'; 'Ubuntu-20.04' = 'ubuntu2004.exe'; 'Ubuntu' = 'ubuntu.exe' }[$Distro]
        if ($launcher -and (Get-Command $launcher -ErrorAction SilentlyContinue)) {
            Say "registering $Distro with $launcher install --root"
            & $launcher install --root
        }
    }
    if (-not (Test-DistroInstalled)) {
        Fail "$Distro is not installed. Run 'wsl --install -d $Distro' once by hand (create a user when asked, then type exit) and run this script again."
    }
}
Say "preparing $Distro : user $LinuxUser, systemd"
$prep = @"
set -euo pipefail
id -u '$LinuxUser' >/dev/null 2>&1 || { useradd -m -s /bin/bash '$LinuxUser'; echo "user $LinuxUser created"; }
ensure() {   # ensure FILE SECTION KEY VALUE : set KEY=VALUE in [SECTION] of an ini file, creating what is missing
  local f=`$1 sec=`$2 key=`$3 val=`$4
  [ -f "`$f" ] || : > "`$f"
  if ! grep -q "^\[`$sec\]" "`$f"; then printf '\n[%s]\n%s=%s\n' "`$sec" "`$key" "`$val" >> "`$f"; return; fi
  awk -v sec="`$sec" -v key="`$key" -v val="`$val" '
    /^\[/ { if (insec && !done) { print key "=" val; done = 1 } insec = (`$0 == "[" sec "]") }
    insec && `$0 ~ "^" key "[ ]*=" { print key "=" val; done = 1; next }
    { print }
    END { if (insec && !done) print key "=" val }' "`$f" > "`$f.new" && mv "`$f.new" "`$f"
}
before=`$([ -f /etc/wsl.conf ] && cksum < /etc/wsl.conf || echo none)
ensure /etc/wsl.conf boot systemd true
ensure /etc/wsl.conf user default '$LinuxUser'
after=`$(cksum < /etc/wsl.conf)
[ "`$before" = "`$after" ] && echo WSLCONF-UNCHANGED || echo WSLCONF-CHANGED
"@
$o = Get-WslOutput 'root' @('true')   # starts the distribution
Invoke-WslScript $prep 'root' 'prepare'
if ((Get-WslOutput 'root' @('grep', '-c', '^systemd=true', '/etc/wsl.conf')) -notmatch '^1') { Fail '/etc/wsl.conf was not written' }
# /etc/wsl.conf is read when the distribution starts: restart it if systemd is not running yet
$sysd = (Get-WslOutput 'root' @('sh', '-c', 'ps -p 1 -o comm=')) -join ''
if ($sysd -notmatch 'systemd') {
    Say 'restarting the distribution to enable systemd'
    $null = Get-WslText @('--terminate', $Distro)
    Start-Sleep -Seconds 8
    $o = Get-WslOutput 'root' @('true')
}
Wait-Systemd

# ---- 3. Docker Engine inside the distribution ----------------------------------------------------------------
Say 'Docker Engine (official apt repository)'
$docker = @"
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
if ! command -v docker >/dev/null 2>&1; then
  apt-get update -qq
  apt-get install -y -qq ca-certificates curl gnupg git
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
  fp=`$(gpg --show-keys --with-colons /etc/apt/keyrings/docker.asc | awk -F: '/^fpr:/ {print `$10; exit}')
  if [ "`$fp" != 9DC858229FC7DD38854AE2D88D81803C0EBFCD88 ]; then
    echo "Docker's repository key has an unexpected fingerprint (`$fp): stopping"; rm -f /etc/apt/keyrings/docker.asc; exit 1
  fi
  chmod a+r /etc/apt/keyrings/docker.asc
  . /etc/os-release
  cat > /etc/apt/sources.list.d/docker.sources <<SRC
Types: deb
URIs: https://download.docker.com/linux/ubuntu
Suites: `${UBUNTU_CODENAME:-`$VERSION_CODENAME}
Components: stable
Signed-By: /etc/apt/keyrings/docker.asc
SRC
  apt-get update -qq
  apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
else
  echo "docker already installed: `$(docker --version)"
fi
apt-get install -y -qq git curl >/dev/null
systemctl enable --now docker
usermod -aG docker '$LinuxUser'
for i in 1 2 3 4 5 6 7 8 9 10; do docker info >/dev/null 2>&1 && break; sleep 2; done
docker info --format 'docker {{.ServerVersion}} running'
"@
Invoke-WslScript $docker 'root' 'docker'

# ---- 4. JumPHP + the runner ----------------------------------------------------------------------------------
$runnerDir = "/home/$LinuxUser/.jumphp/runners/$Name"
$have = (Get-WslOutput 'root' @('sh', '-c', "test -f '$runnerDir/compose.yaml' && echo yes || echo no")) -join ''
if ($have -match 'yes') {
    Say "runner '$Name' already installed in $Distro ($runnerDir): leaving it as it is (-Uninstall first to redo it)"
} else {
    if ($Token -eq '') {
        $sec = Read-Host -AsSecureString "Registration token for $Scope on $Instance (Settings > Actions > Runners > Create new runner)"
        $Token = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec)).Trim()
        if ($Token -eq '') { Fail 'no registration token' }
    }
    $labelArg = ''; if ($Labels -ne '') { $labelArg = " --labels '$Labels'" }
    $jum = @"
set -euo pipefail
cd ~
if [ -d jumphp/.git ]; then git -C jumphp pull --ff-only --quiet || true; else git clone --depth 1 --quiet '$JumphpRepo' jumphp; fi
cd jumphp
# the token comes from the environment (WSLENV), never from a command line
sg docker -c "bin/jumphp runner:install --forge '$Instance' --scope '$Scope' --name '$Name'$labelArg"
"@
    # hand the token to WSL through the environment only
    $oldEnv = $env:WSLENV
    $env:JUMPHP_RUNNER_TOKEN = $Token
    $env:WSLENV = if ($oldEnv) { "$oldEnv`:JUMPHP_RUNNER_TOKEN/u" } else { 'JUMPHP_RUNNER_TOKEN/u' }
    try { Invoke-WslScript $jum $LinuxUser 'jumphp-runner' }
    finally { $env:WSLENV = $oldEnv; Remove-Item Env:\JUMPHP_RUNNER_TOKEN -ErrorAction SilentlyContinue }
    $Token = $null
}

# ---- 5. survive reboots ----------------------------------------------------------------------------------------
# The task keeps ONE wsl.exe process alive (a distribution with no running process is stopped by WSL after a few
# seconds); it first makes sure dockerd runs, which restarts the runner containers (restart: unless-stopped).
Say "scheduled task $TaskName"
$inner = "systemctl start docker; exec sleep infinity"
$cmd = "& wsl.exe -d $Distro -u root --exec /bin/sh -c '$inner'"
$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -Command `"$cmd`""
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `
    -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew
$me = [Security.Principal.WindowsIdentity]::GetCurrent().Name
if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) { Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue; Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false }
if ($RunAtBoot) {
    $cred = Get-Credential -UserName $me -Message 'Windows password of this account (kept by Windows Task Scheduler, not by this script)'
    $trigger = New-ScheduledTaskTrigger -AtStartup
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -User $cred.UserName `
        -Password $cred.GetNetworkCredential().Password -RunLevel Limited | Out-Null
    $when = 'at every boot, even when nobody is logged on'
} else {
    $trigger = New-ScheduledTaskTrigger -AtLogOn -User $me
    $pr = New-ScheduledTaskPrincipal -UserId $me -LogonType Interactive -RunLevel Limited
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Principal $pr -Settings $settings | Out-Null
    $when = "at each logon of $me (the runner is off while nobody is logged on; use -RunAtBoot to change that)"
}
Start-ScheduledTask -TaskName $TaskName

# ---- check -----------------------------------------------------------------------------------------------------
Say 'waiting for the runner to connect'
$ok = $false
for ($i = 0; $i -lt 30 -and -not $ok; $i++) {
    Start-Sleep -Seconds 3
    $logs = (Get-WslOutput $LinuxUser @('sh', '-c', "docker logs jumphp-runner-$Name-runner-1 2>&1 | grep -c 'declared successfully'")) -join ''
    if ($logs -match '^\s*[1-9]') { $ok = $true }
}
Write-Host ''
Write-Host '================ Linux CI runner (WSL2) ================'
Write-Host ("Runner       : {0}  ({1} on {2})" -f $Name, $Scope, $Instance)
Write-Host ("Distribution : {0}, user {1}, Docker Engine inside; runner files in {2}" -f $Distro, $LinuxUser, $runnerDir)
Write-Host ("Keep-alive   : scheduled task {0}, {1}" -f $TaskName, $when)
if ($ok) { Write-Host 'Connection   : OK (the runner declared itself to the forge)' }
else { Warn "connection not confirmed: wsl -d $Distro -u $LinuxUser -- docker logs jumphp-runner-$Name-runner-1" }
Write-Host 'Check it in the forge: Settings > Actions > Runners (status Idle = ready).'
Write-Host ("Remove       : .\install-wsl-linux-runner.ps1 -Uninstall -Name {0}   (then delete the runner in the forge)" -f $Name)
if (-not $ok) { exit 2 }
exit 0
