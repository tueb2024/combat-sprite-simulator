#!/usr/bin/env bash
# Installs the Windows CI runner INSIDE the Windows VM: copies ../install-windows-runner.ps1 into the guest, runs it
# there through the QEMU guest agent (no SSH), and shows its progress until it ends.
#
#   export JUMPHP_RUNNER_TOKEN=<registration token>      # forge: Settings > Actions > Runners > Create new runner
#   ./provision.sh --dir ~/windows-ci-vm --scope org/myorg [--name win-vm] [--instance https://git.jumphp.net]
#                  [--labels windows-latest:host,windows:host] [--install-dir C:\ci] [--skip-build-tools]
#                  [--socket /path/to/qga.sock]
#
# The token is read from the environment, never from the command line (visible in `ps`): it is written to a file
# in the guest through the agent (a folder only Administrators/SYSTEM can read), read and deleted by the guest
# wrapper at once, and handed to the installer as an environment variable.
# Safe to run again: the installer is idempotent (it skips what is installed and the registration that exists).
set -euo pipefail

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
dir="${WINVM_DIR:-$HOME/.jumphp/windows-vm}" scope="" name="" instance="" labels="" install_dir="" skip_bt=0 sock=""

usage() { sed -n '2,/^set -euo/p' "$0" | sed '$d' | sed 's/^# \{0,1\}//'; exit "${1:-0}"; }
while [ $# -gt 0 ]; do
  case "$1" in
    --dir) dir="${2:?}"; shift ;;
    --scope) scope="${2:?}"; shift ;;
    --name) name="${2:?}"; shift ;;
    --instance) instance="${2:?}"; shift ;;
    --labels) labels="${2:?}"; shift ;;
    --install-dir) install_dir="${2:?}"; shift ;;
    --skip-build-tools) skip_bt=1 ;;
    --socket) sock="${2:?}"; shift ;;
    -h|--help) usage 0 ;;
    *) echo "unknown option: $1" >&2; usage 1 ;;
  esac
  shift
done
[[ "$scope" =~ ^(org|user)/[A-Za-z0-9._-]+$ ]] || { echo "--scope org/<name> or user/<login> is required (ONE organization or user, never the whole instance)" >&2; exit 1; }
for v in name instance labels install_dir; do
  [[ "${!v}" =~ ^[A-Za-z0-9._:/,@\\-]*$ ]] || { echo "--${v//_/-}: unexpected characters" >&2; exit 1; }
done
[ -n "$sock" ] || sock="$dir/qga.sock"
ge=(python3 -I "$here/guest-exec.py" --socket "$sock")

"${ge[@]}" --ping >/dev/null || { echo "the guest agent does not answer on $sock: is the VM running (start-vm.sh) and Windows booted?" >&2; exit 1; }

if [ -z "${JUMPHP_RUNNER_TOKEN:-}" ]; then
  echo "JUMPHP_RUNNER_TOKEN is not set: only OK if the runner is already registered in the guest (re-run)." >&2
fi

wd='C:\jumphp-provision'
# 1. a private folder in the guest (Administrators + SYSTEM only), then the files
"${ge[@]}" -c "New-Item -ItemType Directory -Force '$wd' | Out-Null; icacls '$wd' /inheritance:r /grant:r '*S-1-5-32-544:(OI)(CI)F' '*S-1-5-18:(OI)(CI)F' | Out-Null; Remove-Item '$wd\\done','$wd\\token.txt' -Force -ErrorAction SilentlyContinue; exit 0"
"${ge[@]}" --put "$here/../install-windows-runner.ps1" "$wd\\install-windows-runner.ps1"
[ -z "${JUMPHP_RUNNER_TOKEN:-}" ] || "${ge[@]}" --put-env JUMPHP_RUNNER_TOKEN "$wd\\token.txt"

# 2. the wrapper: token file -> environment variable -> installer; the exit code goes to "done"
wrapper=$(mktemp)
trap 'rm -f "$wrapper"' EXIT
cat > "$wrapper" <<'PS'
param([string]$Scope, [string]$Name = '', [string]$Instance = '', [string]$Labels = '', [string]$InstallDir = '', [switch]$SkipBuildTools)
$d = 'C:\jumphp-provision'
$tok = Join-Path $d 'token.txt'
if (Test-Path $tok) { $env:JUMPHP_RUNNER_TOKEN = (Get-Content -Raw $tok).Trim(); Remove-Item $tok -Force }
$a = @{ Scope = $Scope }
if ($Name) { $a.Name = $Name }
if ($Instance) { $a.Instance = $Instance }
if ($Labels) { $a.Labels = $Labels }
if ($InstallDir) { $a.InstallDir = $InstallDir }
if ($SkipBuildTools) { $a.SkipBuildTools = $true }
$code = 0
$global:LASTEXITCODE = 0
try { & (Join-Path $d 'install-windows-runner.ps1') @a *>&1 | Out-File -Append -Encoding ascii (Join-Path $d 'install.log'); if ($LASTEXITCODE) { $code = $LASTEXITCODE } }
catch { ($_ | Out-String) | Out-File -Append -Encoding ascii (Join-Path $d 'install.log'); $code = 1 }
Remove-Item Env:\JUMPHP_RUNNER_TOKEN -ErrorAction SilentlyContinue
Set-Content -Path (Join-Path $d 'done') -Value $code -Encoding ascii
PS
"${ge[@]}" --put "$wrapper" "$wd\\wrapper.ps1"
"${ge[@]}" -c "Remove-Item '$wd\\install.log' -Force -ErrorAction SilentlyContinue; exit 0"

# 3. start it detached (a first install takes 20 to 60 minutes: Build Tools, Rust)
# values were checked above (no quote, no space); PowerShell parameters are written bare, values single-quoted
psargs="-Scope '$scope'"
[ -z "$name" ] || psargs+=" -Name '$name'"
[ -z "$instance" ] || psargs+=" -Instance '$instance'"
[ -z "$labels" ] || psargs+=" -Labels '$labels'"
[ -z "$install_dir" ] || psargs+=" -InstallDir '$install_dir'"
[ "$skip_bt" = 0 ] || psargs+=" -SkipBuildTools"
"${ge[@]}" --detach -c "& '$wd\\wrapper.ps1' $psargs" >/dev/null
echo "installer started in the guest (log: $wd\\install.log). Following it; Ctrl-C only stops the display, not the install."

# 4. follow the log until "done" appears
shown=0
while :; do
  chunk=$("${ge[@]}" -c "if (Test-Path '$wd\\install.log') { Get-Content '$wd\\install.log' | Select-Object -Skip $shown }" 2>/dev/null || true)
  if [ -n "$chunk" ]; then printf '%s\n' "$chunk"; shown=$((shown + $(printf '%s\n' "$chunk" | wc -l))); fi
  code=$("${ge[@]}" -c "if (Test-Path '$wd\\done') { (Get-Content '$wd\\done').Trim() }" 2>/dev/null | tr -d '\r' || true)
  if [ -n "$code" ]; then
    # one last read of the log
    chunk=$("${ge[@]}" -c "Get-Content '$wd\\install.log' | Select-Object -Skip $shown" 2>/dev/null || true)
    [ -z "$chunk" ] || printf '%s\n' "$chunk"
    echo "installer finished with exit code $code"
    case "$code" in
      0) echo "OK: the runner is installed and connected. Check it in the forge: Settings > Actions > Runners." ;;
      2) printf '%s\n' 'Installed, but the connection was not confirmed: read C:\ci\runner\runner.log in the guest.' ;;
      *) echo "FAILED: see the log above; fix and run provision.sh again (it resumes where it stopped)." ;;
    esac
    exit "$code"
  fi
  sleep 10
done
