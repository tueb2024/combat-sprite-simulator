<#
.SYNOPSIS
  Turns this Windows machine into a CI build machine: a Forgejo Actions runner (git.jumphp.net or any Forgejo)
  that builds Windows artifacts (the Tauri library jumphp_tauri.dll, executables, installers).

.DESCRIPTION
  Run it in an elevated PowerShell (Administrator), Windows PowerShell 5.1 or later. It is idempotent: run it again
  and it only does what is missing. It installs, from the official sources only (checksum or signature checked):
    - Git for Windows       (its bash is what the workflow steps use)
    - Visual Studio 2022 Build Tools (C++ x64 tools + Windows SDK, minimal components)   [-SkipBuildTools to skip]
    - Rust (rustup, the version of RUST_VERSION in .github/workflows/tauri.yml), machine-wide RUSTUP_HOME / CARGO_HOME
    - Node.js LTS           (the JavaScript actions such as actions/checkout need it)
    - forgejo-runner        (code.forgejo.org publishes NO Windows build today: it is compiled from its release tag
                             with a Go toolchain that is downloaded, used, then deleted. If one day a Windows
                             binary is published as a release asset, the script downloads that one instead.)
  then registers the runner (HOST mode: jobs run directly on this machine, as SYSTEM, no container) and installs a
  scheduled task that starts it at boot and restarts it when it dies.

  SECURITY: scope the runner to ONE user or organization (-Scope), never to a whole instance. A host-mode runner
  runs whatever the workflows of that scope say, with full rights on this machine: use a throw-away VM or a
  machine with nothing precious on it (docs/ci-runner.md, "Security").
  The registration token is NOT stored anywhere by this script (it is used once; the runner keeps its own
  credentials in <InstallDir>\runner\.runner, readable by Administrators and SYSTEM only).

.PARAMETER Instance      The forge. Default https://git.jumphp.net
.PARAMETER Scope         org/<name> or user/<login>: who may use the runner. Required (except with -Uninstall).
.PARAMETER Token         Registration token (forge: Settings > Actions > Runners > Create new runner, of that user or
                         organization). Better: leave it out and set the environment variable JUMPHP_RUNNER_TOKEN;
                         if neither is set the script asks for it (hidden input).
.PARAMETER Name          Runner name shown in the forge. Default win-<computer name>
.PARAMETER Labels        What `runs-on:` of a workflow can ask for. Default windows-latest:host,windows:host
.PARAMETER InstallDir    Where the runner, its job workspaces and its logs live. Default C:\ci
.PARAMETER RustDir       Machine-wide RUSTUP_HOME / CARGO_HOME parent. Default C:\rust
.PARAMETER RustVersion   Rust toolchain. Keep it equal to RUST_VERSION of .github/workflows/tauri.yml
.PARAMETER RunnerVersion forgejo-runner release tag to install ('latest' = the newest release). Default v13.2.1
.PARAMETER SkipBuildTools Do not install Visual Studio Build Tools (you already have a C++ toolchain, or you will not build Rust)
.PARAMETER ReRegister    Register again even if <InstallDir>\runner\.runner exists (new token needed)
.PARAMETER Uninstall     Stop and remove the runner and its scheduled task. The tools (Git, Rust...) stay.
.PARAMETER Purge         With -Uninstall: also delete the whole InstallDir (job workspaces, caches)

.EXAMPLE
  $env:JUMPHP_RUNNER_TOKEN = '<token>'
  .\install-windows-runner.ps1 -Scope org/myorg -Name office-pc
.EXAMPLE
  .\install-windows-runner.ps1 -Uninstall -Name office-pc
#>
[CmdletBinding()]
param(
    [string]$Instance = 'https://git.jumphp.net',
    [string]$Scope = '',
    [string]$Token = '',
    [string]$Name = '',
    [string]$Labels = 'windows-latest:host,windows:host',
    [string]$InstallDir = 'C:\ci',
    [string]$RustDir = 'C:\rust',
    [string]$RustVersion = '1.95.0',
    [string]$RunnerVersion = 'v13.2.1',
    [switch]$SkipBuildTools,
    [switch]$ReRegister,
    [switch]$Uninstall,
    [switch]$Purge
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'      # Invoke-WebRequest is 10x slower with the progress bar
Set-StrictMode -Version 2
try { [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 } catch { }

# ----------------------------------------------------------------------------------------------------------------
# helpers
# ----------------------------------------------------------------------------------------------------------------
$script:RebootNeeded = $false
$script:Summary = New-Object System.Collections.ArrayList

function Say($m)  { Write-Host ("[{0}] {1}" -f (Get-Date -Format 'HH:mm:ss'), $m) }
function Warn($m) { Write-Host ("[{0}] WARNING: {1}" -f (Get-Date -Format 'HH:mm:ss'), $m) -ForegroundColor Yellow }
function Fail($m) { throw $m }
function Note($what, $state) { [void]$script:Summary.Add(('{0,-22} {1}' -f $what, $state)) }

function Test-Admin {
    $p = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
    return $p.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

# Download with 3 tries into the download folder; returns the file path.
function Get-File($url, $dest) {
    for ($i = 1; $i -le 3; $i++) {
        try {
            Invoke-WebRequest -UseBasicParsing -Uri $url -OutFile $dest
            return $dest
        } catch {
            if ($i -eq 3) { Fail "download of $url failed: $($_.Exception.Message)" }
            Start-Sleep -Seconds (3 * $i)
        }
    }
}

function Get-Json($url) {
    for ($i = 1; $i -le 3; $i++) {
        try { return (Invoke-RestMethod -UseBasicParsing -Uri $url -Headers @{ 'User-Agent' = 'jumphp-runner-installer' }) }
        catch { if ($i -eq 3) { Fail "request to $url failed: $($_.Exception.Message)" }; Start-Sleep -Seconds (3 * $i) }
    }
}

function Get-Text($url) {
    $r = Invoke-WebRequest -UseBasicParsing -Uri $url
    if ($r.Content -is [byte[]]) { return [Text.Encoding]::UTF8.GetString($r.Content) }
    return [string]$r.Content
}

# Refuse a file whose SHA-256 is not the one the vendor published.
function Assert-Sha256($file, $expected, $what) {
    $actual = (Get-FileHash -Algorithm SHA256 -Path $file).Hash.ToLower()
    if ($actual -ne $expected.ToLower().Trim()) {
        Remove-Item $file -Force -ErrorAction SilentlyContinue
        Fail "$what : SHA-256 mismatch (expected $expected, got $actual). The file was deleted. Not installing it."
    }
    Say "$what : SHA-256 verified"
}

# Run an installer, return its exit code (0 and 3010 = ok, 3010 = reboot needed).
function Invoke-Installer($file, $arguments, $what) {
    Say "installing $what (this can take a while)..."
    $p = Start-Process -FilePath $file -ArgumentList $arguments -Wait -PassThru
    if ($p.ExitCode -eq 3010) { $script:RebootNeeded = $true; return 3010 }
    if ($p.ExitCode -ne 0) { Fail "$what installer failed with exit code $($p.ExitCode)" }
    return 0
}

# Runs a native program so that its stderr lines (rustup, git and go write progress there) are shown as plain text
# instead of aborting the script ($ErrorActionPreference = 'Stop' turns stderr into a terminating error).
# $LASTEXITCODE is the program's.
function Invoke-Native([scriptblock]$Command) {
    $old = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { & $Command 2>&1 | ForEach-Object { Write-Host ("  " + $_) } } finally { $ErrorActionPreference = $old }
}

function Get-MachinePath { return [Environment]::GetEnvironmentVariable('Path', 'Machine') }

# Adds a folder to the machine PATH once (and to this process, so the next steps see it).
function Add-MachinePath($dir) {
    $cur = Get-MachinePath
    $has = $false
    foreach ($e in ($cur -split ';')) { if ($e.TrimEnd('\') -ieq $dir.TrimEnd('\')) { $has = $true } }
    if (-not $has) {
        [Environment]::SetEnvironmentVariable('Path', ($cur.TrimEnd(';') + ';' + $dir), 'Machine')
        Say "added $dir to the machine PATH"
    }
    if (($env:Path -split ';') -notcontains $dir) { $env:Path = $env:Path + ';' + $dir }
}

function Refresh-Path { $env:Path = (Get-MachinePath) + ';' + [Environment]::GetEnvironmentVariable('Path', 'User') }

if (-not (Test-Admin)) { Fail 'Run this script in a PowerShell opened as Administrator (right click > Run as administrator).' }
if ($Instance -notmatch '^https?://[^/\s]+$') { Fail "-Instance must look like https://git.jumphp.net (no path, no trailing slash)" }
if ($Name -eq '') { $Name = 'win-' + $env:COMPUTERNAME.ToLower() }
if ($Name -notmatch '^[A-Za-z0-9._-]+$') { Fail '-Name: letters, digits . _ - only' }
$InstallDir = $InstallDir.TrimEnd('\')
$RunnerDir  = Join-Path $InstallDir 'runner'
$HomeDir    = Join-Path $InstallDir 'home'
$DlDir      = Join-Path $InstallDir 'dl'
$RunnerExe  = Join-Path $RunnerDir 'forgejo-runner.exe'
$TaskName   = "ForgejoRunner-$Name"

# ----------------------------------------------------------------------------------------------------------------
# uninstall
# ----------------------------------------------------------------------------------------------------------------
if ($Uninstall) {
    Say "uninstalling the runner '$Name' ($RunnerDir)"
    $t = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    if ($t) { Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue; Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false; Say "scheduled task $TaskName removed" }
    else { Say "no scheduled task $TaskName" }
    # the task's children (cmd, forgejo-runner) are not always stopped with it
    Get-CimInstance Win32_Process -Filter "Name='forgejo-runner.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.ExecutablePath -and ($_.ExecutablePath -ieq $RunnerExe) } |
        ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue; Say "stopped process $($_.ProcessId)" }
    Start-Sleep -Seconds 2
    if ($Purge) { if (Test-Path $InstallDir) { Remove-Item $InstallDir -Recurse -Force; Say "deleted $InstallDir" } }
    else {
        foreach ($d in @($RunnerDir, $DlDir)) { if (Test-Path $d) { Remove-Item $d -Recurse -Force; Say "deleted $d" } }
    }
    Write-Host ''
    Write-Host "Done. Still to do by hand: delete the runner '$Name' in the forge (Settings > Actions > Runners of its user or organization)."
    Write-Host "The tools (Git, Visual Studio Build Tools, Rust in $RustDir, Node.js) were kept: remove them in Settings > Apps if you want."
    return
}

# ----------------------------------------------------------------------------------------------------------------
# parameters
# ----------------------------------------------------------------------------------------------------------------
if ($Scope -notmatch '^(org|user)/[A-Za-z0-9._-]+$') {
    Fail "-Scope org/<name> or user/<login> is required: the runner serves ONE organization or user, never the whole instance (anyone could then run code on this machine)."
}
if ($Token -eq '' -and $env:JUMPHP_RUNNER_TOKEN) { $Token = $env:JUMPHP_RUNNER_TOKEN }
$Token = $Token.Trim()
$alreadyRegistered = (Test-Path (Join-Path $RunnerDir '.runner')) -and (-not $ReRegister)
if ($Token -eq '' -and -not $alreadyRegistered) {
    $sec = Read-Host -AsSecureString "Registration token for $Scope on $Instance (Settings > Actions > Runners > Create new runner)"
    $Token = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec)).Trim()
    if ($Token -eq '') { Fail 'no registration token' }
}

Say "runner '$Name' for $Scope on $Instance, labels $Labels, folder $InstallDir"
foreach ($d in @($InstallDir, $RunnerDir, $HomeDir, $DlDir)) { New-Item -ItemType Directory -Force -Path $d | Out-Null }
# The runner folder holds the runner's credentials (.runner): Administrators and SYSTEM only.
& icacls.exe $RunnerDir /inheritance:r /grant:r '*S-1-5-32-544:(OI)(CI)F' '*S-1-5-18:(OI)(CI)F' | Out-Null

# ----------------------------------------------------------------------------------------------------------------
# 1. Git for Windows
# ----------------------------------------------------------------------------------------------------------------
$GitBash = Join-Path $env:ProgramFiles 'Git\bin\bash.exe'
if (Test-Path $GitBash) {
    Say 'Git for Windows: already installed'
    Note 'Git for Windows' 'already there'
} else {
    $rel = Get-Json 'https://api.github.com/repos/git-for-windows/git/releases/latest'
    $asset = $rel.assets | Where-Object { $_.name -match '^Git-.*-64-bit\.exe$' } | Select-Object -First 1
    if (-not $asset) { Fail 'Git for Windows: no 64-bit installer in the latest release' }
    $sha = ''
    if ($asset.PSObject.Properties['digest'] -and $asset.digest -match '^sha256:([0-9a-fA-F]{64})$') { $sha = $Matches[1] }
    elseif ($rel.body -match ([regex]::Escape($asset.name) + '\s*\|\s*([0-9a-fA-F]{64})')) { $sha = $Matches[1] }
    if ($sha -eq '') { Fail 'Git for Windows: the release publishes no SHA-256 for the installer; not installing it unverified' }
    $f = Get-File $asset.browser_download_url (Join-Path $DlDir $asset.name)
    Assert-Sha256 $f $sha 'Git for Windows'
    # PathOption=Cmd: git on the PATH, but NOT bash (System32\bash.exe is the WSL stub; run.ps1 puts Git's bash first)
    [void](Invoke-Installer $f '/VERYSILENT /NORESTART /NOCANCEL /SP- /CLOSEAPPLICATIONS /o:PathOption=Cmd' 'Git for Windows')
    Note 'Git for Windows' ('installed ' + $rel.tag_name)
}
Refresh-Path
if (-not (Test-Path $GitBash)) { Fail "Git for Windows: $GitBash not found after the installation" }
$GitCmd = Join-Path $env:ProgramFiles 'Git\cmd'
Add-MachinePath $GitCmd
Invoke-Native { & (Join-Path $env:ProgramFiles 'Git\cmd\git.exe') config --system core.longpaths true }

# ----------------------------------------------------------------------------------------------------------------
# 2. Visual Studio 2022 Build Tools (C++ x64 + Windows SDK)
# ----------------------------------------------------------------------------------------------------------------
$VsComponents = @('Microsoft.VisualStudio.Component.VC.Tools.x86.x64', 'Microsoft.VisualStudio.Component.Windows11SDK.22621')
function Test-BuildTools {
    $vw = Join-Path ${env:ProgramFiles(x86)} 'Microsoft Visual Studio\Installer\vswhere.exe'
    if (-not (Test-Path $vw)) { return $false }
    $req = @('-products', '*')
    foreach ($c in $VsComponents) { $req += @('-requires', $c) }
    $out = & $vw @req -property installationPath 2>$null
    return [bool]$out
}
if ($SkipBuildTools) {
    Say 'Visual Studio Build Tools: skipped (-SkipBuildTools)'
    Note 'VS Build Tools' 'skipped'
} elseif (Test-BuildTools) {
    Say 'Visual Studio Build Tools (C++ + Windows SDK): already installed'
    Note 'VS Build Tools' 'already there'
} else {
    # Microsoft publishes no checksum for the bootstrapper (the link always serves the current one): its Authenticode
    # signature is checked instead.
    $f = Get-File 'https://aka.ms/vs/17/release/vs_BuildTools.exe' (Join-Path $DlDir 'vs_BuildTools.exe')
    $sig = Get-AuthenticodeSignature $f
    if ($sig.Status -ne 'Valid' -or $sig.SignerCertificate.Subject -notmatch 'O=Microsoft Corporation') {
        Remove-Item $f -Force
        Fail "vs_BuildTools.exe: the Microsoft signature is not valid ($($sig.Status)). Not running it."
    }
    Say 'vs_BuildTools.exe: Microsoft signature verified'
    $a = '--quiet --wait --norestart --nocache'
    foreach ($c in $VsComponents) { $a += " --add $c" }
    [void](Invoke-Installer $f $a 'Visual Studio 2022 Build Tools (about 5 GB, 10 to 30 minutes)')
    if (-not (Test-BuildTools)) { Fail 'Visual Studio Build Tools: components still missing after the installation (see %TEMP%\dd_*.log)' }
    Note 'VS Build Tools' 'installed'
}

# ----------------------------------------------------------------------------------------------------------------
# 3. Rust, machine-wide (RUSTUP_HOME / CARGO_HOME), the version the workflows use
# ----------------------------------------------------------------------------------------------------------------
$RustupHome = Join-Path $RustDir 'rustup'
$CargoHome  = Join-Path $RustDir 'cargo'
$CargoBin   = Join-Path $CargoHome 'bin'
[Environment]::SetEnvironmentVariable('RUSTUP_HOME', $RustupHome, 'Machine')
[Environment]::SetEnvironmentVariable('CARGO_HOME', $CargoHome, 'Machine')
$env:RUSTUP_HOME = $RustupHome; $env:CARGO_HOME = $CargoHome
Add-MachinePath $CargoBin
$rustc = Join-Path $CargoBin 'rustc.exe'
$haveRust = $false
if (Test-Path $rustc) { try { $v = (& $rustc +$RustVersion --version 2>$null); if ($LASTEXITCODE -eq 0 -and $v -match "rustc $([regex]::Escape($RustVersion))") { $haveRust = $true } } catch { } }
if ($haveRust) {
    Say "Rust $RustVersion : already installed"
    Note 'Rust' "already there ($RustVersion)"
} else {
    if (Test-Path (Join-Path $CargoBin 'rustup.exe')) {
        Say "Rust: installing the toolchain $RustVersion with the existing rustup"
        Invoke-Native { & (Join-Path $CargoBin 'rustup.exe') toolchain install $RustVersion --profile minimal }
        if ($LASTEXITCODE -ne 0) { Fail 'rustup toolchain install failed' }
        Invoke-Native { & (Join-Path $CargoBin 'rustup.exe') default $RustVersion }
    } else {
        $base = 'https://static.rust-lang.org/rustup/dist/x86_64-pc-windows-msvc/rustup-init.exe'
        $f = Get-File $base (Join-Path $DlDir 'rustup-init.exe')
        $sha = ((Get-Text ($base + '.sha256')).Trim() -split '\s+')[0]
        Assert-Sha256 $f $sha 'rustup-init'
        Say "installing Rust $RustVersion into $RustDir ..."
        Invoke-Native { & $f -y --default-toolchain $RustVersion --profile minimal --default-host x86_64-pc-windows-msvc --no-modify-path }
        if ($LASTEXITCODE -ne 0) { Fail 'rustup-init failed' }
    }
    Note 'Rust' "installed $RustVersion"
}

# ----------------------------------------------------------------------------------------------------------------
# 4. Node.js LTS (the JavaScript actions: actions/checkout...)
# ----------------------------------------------------------------------------------------------------------------
$NodeExe = Join-Path $env:ProgramFiles 'nodejs\node.exe'
$haveNode = $false
if (Test-Path $NodeExe) { $nv = (& $NodeExe --version); if ($nv -match '^v(\d+)\.' -and [int]$Matches[1] -ge 20) { $haveNode = $true } }
if ($haveNode) {
    Say "Node.js $nv : already installed"
    Note 'Node.js' "already there ($nv)"
} else {
    $idx = Get-Json 'https://nodejs.org/dist/index.json'
    $lts = $idx | Where-Object { $_.lts } | Select-Object -First 1
    $ver = $lts.version
    $file = "node-$ver-x64.msi"
    $sums = Get-Text "https://nodejs.org/dist/$ver/SHASUMS256.txt"
    $line = ($sums -split "`n") | Where-Object { $_ -match ('\s' + [regex]::Escape($file) + '\s*$') } | Select-Object -First 1
    if (-not $line) { Fail "Node.js: no checksum for $file in SHASUMS256.txt" }
    $f = Get-File "https://nodejs.org/dist/$ver/$file" (Join-Path $DlDir $file)
    Assert-Sha256 $f (($line.Trim() -split '\s+')[0]) "Node.js $ver"
    [void](Invoke-Installer 'msiexec.exe' "/i `"$f`" /qn /norestart" "Node.js $ver")
    Note 'Node.js' "installed $ver"
}
Refresh-Path
Add-MachinePath (Join-Path $env:ProgramFiles 'nodejs')

# ----------------------------------------------------------------------------------------------------------------
# 5. forgejo-runner: a published Windows binary if there is one, else compiled from the release tag
# ----------------------------------------------------------------------------------------------------------------
$rrel = $null
if ($RunnerVersion -eq 'latest') {
    $rrel = Get-Json 'https://code.forgejo.org/api/v1/repos/forgejo/runner/releases/latest'
    $RunnerVersion = $rrel.tag_name
} else {
    try { $rrel = Get-Json "https://code.forgejo.org/api/v1/repos/forgejo/runner/releases/tags/$RunnerVersion" } catch { Fail "forgejo-runner $RunnerVersion : no such release on code.forgejo.org" }
}
if ($RunnerVersion -notmatch '^v\d+\.\d+\.\d+$') { Fail "-RunnerVersion: a tag like v13.2.1, or 'latest'" }
$haveRunner = $false
if (Test-Path $RunnerExe) { try { $rv = (& $RunnerExe --version 2>&1 | Out-String); if ($rv -match [regex]::Escape($RunnerVersion)) { $haveRunner = $true } } catch { } }
if ($haveRunner) {
    Say "forgejo-runner $RunnerVersion : already installed"
    Note 'forgejo-runner' "already there ($RunnerVersion)"
} else {
    # make sure the old one is not running (a running .exe cannot be replaced)
    if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) { Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue }
    Get-CimInstance Win32_Process -Filter "Name='forgejo-runner.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.ExecutablePath -and ($_.ExecutablePath -ieq $RunnerExe) } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
    $num = $RunnerVersion.TrimStart('v')
    $bin = $rrel.assets | Where-Object { $_.name -match "windows-amd64(\.exe)?$" } | Select-Object -First 1
    $done = $false
    if ($bin) {
        $shaAsset = $rrel.assets | Where-Object { $_.name -eq ($bin.name + '.sha256') } | Select-Object -First 1
        if ($shaAsset) {
            Say "forgejo-runner: a published Windows build exists ($($bin.name)), using it"
            $f = Get-File $bin.browser_download_url (Join-Path $DlDir $bin.name)
            Assert-Sha256 $f (((Get-Text $shaAsset.browser_download_url).Trim() -split '\s+')[0]) "forgejo-runner $RunnerVersion"
            Copy-Item $f $RunnerExe -Force
            $done = $true
        } else { Warn 'a Windows build is published but without a checksum: compiling from source instead' }
    }
    if (-not $done) {
        Say "forgejo-runner: no published Windows build, compiling $RunnerVersion from source (a few minutes)"
        $tools = Join-Path $InstallDir 'tools'
        if (Test-Path $tools) { Remove-Item $tools -Recurse -Force }
        New-Item -ItemType Directory -Force -Path $tools | Out-Null
        # Go toolchain: official zip, checksum from go.dev's own JSON listing; lives in $tools and is deleted after the build
        $gl = (Get-Json 'https://go.dev/dl/?mode=json') | Select-Object -First 1
        $gf = $gl.files | Where-Object { $_.os -eq 'windows' -and $_.arch -eq 'amd64' -and $_.kind -eq 'archive' } | Select-Object -First 1
        if (-not $gf) { Fail 'Go: no windows-amd64 zip in go.dev/dl' }
        $zip = Get-File ('https://go.dev/dl/' + $gf.filename) (Join-Path $DlDir $gf.filename)
        Assert-Sha256 $zip $gf.sha256 "Go $($gl.version)"
        Add-Type -AssemblyName System.IO.Compression.FileSystem
        [IO.Compression.ZipFile]::ExtractToDirectory($zip, $tools)
        $goExe = Join-Path $tools 'go\bin\go.exe'
        $src = Join-Path $tools 'src'
        Invoke-Native { & (Join-Path $env:ProgramFiles 'Git\cmd\git.exe') -c advice.detachedHead=false clone --quiet --depth 1 --branch $RunnerVersion https://code.forgejo.org/forgejo/runner.git $src }
        if ($LASTEXITCODE -ne 0) { Fail "git clone of forgejo-runner $RunnerVersion failed" }
        $mod = ((Get-Content (Join-Path $src 'go.mod') | Select-Object -First 1) -replace '^module\s+', '').Trim()
        $env:GOPATH = Join-Path $tools 'gopath'; $env:GOCACHE = Join-Path $tools 'gocache'; $env:GOTOOLCHAIN = 'local'
        $env:GOROOT = Join-Path $tools 'go'
        $env:CGO_ENABLED = '0'; $env:GOOS = 'windows'; $env:GOARCH = 'amd64'
        Push-Location $src
        try {
            Invoke-Native { & $goExe build -tags 'netgo osusergo' -ldflags "-s -w -X $mod/internal/pkg/ver.version=$RunnerVersion" -o $RunnerExe . }
            if ($LASTEXITCODE -ne 0) { Fail 'go build of forgejo-runner failed' }
        } finally { Pop-Location }
        Remove-Item Env:\GOPATH, Env:\GOCACHE, Env:\GOTOOLCHAIN, Env:\GOROOT, Env:\CGO_ENABLED, Env:\GOOS, Env:\GOARCH -ErrorAction SilentlyContinue
        # Go's module cache is read-only: clear the attribute so the folder can be deleted
        & cmd.exe /c "attrib -r `"$tools\*`" /s /d >nul 2>&1"
        Remove-Item $tools -Recurse -Force -ErrorAction SilentlyContinue
    }
    Note 'forgejo-runner' "installed $RunnerVersion"
}

# ----------------------------------------------------------------------------------------------------------------
# 6. config + registration
# ----------------------------------------------------------------------------------------------------------------
$Config = Join-Path $RunnerDir 'config.yml'
if (-not (Test-Path $Config)) {
    # cmd redirection: PowerShell 5.1's > would write UTF-16
    & cmd.exe /c "`"$RunnerExe`" generate-config > `"$Config`""
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path $Config)) { Fail 'forgejo-runner generate-config failed' }
}
$RunnerFile = Join-Path $RunnerDir '.runner'
if ($alreadyRegistered) {
    $reg = Get-Content $RunnerFile -Raw | ConvertFrom-Json
    Say "runner already registered as '$($reg.name)' on $($reg.address) (use -ReRegister with a new token to change that)"
    if ($reg.address -and ($reg.address.TrimEnd('/') -ne $Instance)) { Warn "it is registered on $($reg.address), not on $Instance" }
    Note 'registration' "already there ($($reg.name))"
} else {
    if (Test-Path $RunnerFile) { Remove-Item $RunnerFile -Force }
    Say "registering '$Name' on $Instance for $Scope"
    # The token is on the command line of this one process for a second or two; nothing is written to disk by this script.
    Push-Location $RunnerDir
    try {
        Invoke-Native { & $RunnerExe register --no-interactive --config $Config --instance $Instance --token $Token --name $Name --labels $Labels }
        if ($LASTEXITCODE -ne 0 -or -not (Test-Path $RunnerFile)) { Fail 'registration failed (wrong token? a token is for ONE user/organization and works once)' }
    } finally { Pop-Location }
    $Token = $null
    Note 'registration' "registered as $Name"
}

# ----------------------------------------------------------------------------------------------------------------
# 7. the launcher and the scheduled task
# ----------------------------------------------------------------------------------------------------------------
# run.ps1: a loop around the daemon. Git's bash FIRST in PATH (C:\Windows\System32\bash.exe is the WSL stub and breaks
# jobs); the machine PATH is read again from the registry (the task scheduler keeps the PATH of the last boot).
$run = @"
# Generated by install-windows-runner.ps1 - runs the Forgejo runner '$Name' forever (restarts it 10 s after it stops).
`$env:Path = '$($env:ProgramFiles)\Git\bin;$($env:ProgramFiles)\Git\usr\bin;' + [Environment]::GetEnvironmentVariable('Path','Machine')
`$env:RUSTUP_HOME = '$RustupHome'; `$env:CARGO_HOME = '$CargoHome'
`$env:HOME = '$HomeDir'; `$env:USERPROFILE = '$HomeDir'
Set-Location '$RunnerDir'
while (`$true) {
    if ((Test-Path runner.log) -and ((Get-Item runner.log).Length -gt 20MB)) { Move-Item runner.log runner.old.log -Force }
    cmd /c "forgejo-runner.exe daemon --config config.yml >> runner.log 2>&1"
    Start-Sleep -Seconds 10
}
"@
Set-Content -Path (Join-Path $RunnerDir 'run.ps1') -Value $run -Encoding ASCII

$action    = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -NonInteractive -ExecutionPolicy Bypass -File `"$RunnerDir\run.ps1`"" -WorkingDirectory $RunnerDir
$trigger   = New-ScheduledTaskTrigger -AtStartup
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$settings  = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `
              -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew
if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) { Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue; Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false }
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings `
    -Description "Forgejo Actions runner $Name ($Scope on $Instance), installed by install-windows-runner.ps1" | Out-Null
Get-CimInstance Win32_Process -Filter "Name='forgejo-runner.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.ExecutablePath -and ($_.ExecutablePath -ieq $RunnerExe) } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
Start-ScheduledTask -TaskName $TaskName
Note 'scheduled task' "$TaskName (at startup, SYSTEM)"

# wait for the runner to say it is connected
$logFile = Join-Path $RunnerDir 'runner.log'
$ok = $false
for ($i = 0; $i -lt 30 -and -not $ok; $i++) {
    Start-Sleep -Seconds 2
    if (Test-Path $logFile) { $ok = [bool](Select-String -Path $logFile -Pattern 'declared successfully' -SimpleMatch -Quiet -ErrorAction SilentlyContinue) }
}
if ($ok) { Say 'the runner is connected to the forge' ; Note 'connection' 'OK (declared successfully)' }
else { Warn "no 'declared successfully' in $logFile after 60 s: read that file (wrong instance URL? token already used?)"; Note 'connection' 'NOT confirmed, see runner.log' }

# ----------------------------------------------------------------------------------------------------------------
# summary
# ----------------------------------------------------------------------------------------------------------------
Write-Host ''
Write-Host '================ Windows CI runner ================'
Write-Host ("Runner      : {0}  ({1} on {2})" -f $Name, $Scope, $Instance)
Write-Host ("Labels      : {0}   -> a workflow uses it with  runs-on: windows-latest" -f $Labels)
Write-Host ("Folder      : {0}   (log: {1})" -f $RunnerDir, $logFile)
foreach ($s in $script:Summary) { Write-Host ("  " + $s) }
Write-Host ''
Write-Host 'Check it in the forge: Settings > Actions > Runners (status Idle = ready).'
Write-Host ("Stop / start: Stop-ScheduledTask {0} / Start-ScheduledTask {0}" -f $TaskName)
Write-Host ("Remove      : .\install-windows-runner.ps1 -Uninstall -Name {0}   (then delete the runner in the forge)" -f $Name)
Write-Host 'Reminder    : a host-mode runner runs the workflows of that scope with full rights on this machine.'
if ($script:RebootNeeded) { Warn 'A reboot is needed to finish the Visual Studio / MSI installation: restart this machine (the runner starts by itself).' }
if (-not $ok) { exit 2 }
exit 0
