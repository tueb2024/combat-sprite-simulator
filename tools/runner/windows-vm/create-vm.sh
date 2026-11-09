#!/usr/bin/env bash
# Creates a headless Windows 10 VM (QEMU/KVM) with an UNATTENDED installation, ready to receive the CI runner
# (provision.sh). Nothing is downloaded from Microsoft: YOU give the path of a Windows 10 ISO you obtained from
# Microsoft (https://www.microsoft.com/software-download/windows10ISO) and Windows needs a licence YOU own.
# Only the virtio drivers CD (Fedora project, https://fedorapeople.org/groups/virt/virtio-win/) is downloaded,
# unless you pass --virtio-iso.
#
#   ./create-vm.sh --iso /path/to/Win10_22H2.iso [--dir DIR] [options]
#
#   --dir DIR            where the VM lives (default $WINVM_DIR or ~/.jumphp/windows-vm); must not contain a comma
#   --disk-size SIZE     virtual disk, grows as used (default 80G: Build Tools + Rust + target folders)
#   --cpus N  --ram SIZE (default 4 and 8G)
#   --locale LL-CC       language of YOUR ISO and of the system: en-US (default), fr-FR, de-DE, es-ES, it-IT...
#                        It must be a language the ISO contains (a French ISO needs fr-FR).
#   --input-locale ID    keyboard, e.g. 040c:0000040c (default: derived from --locale for the common ones)
#   --timezone NAME      Windows time zone name (default "UTC"; e.g. "Romance Standard Time")
#   --image-name NAME    edition to install from the ISO (default "Windows 10 Pro": RDP needs Pro or better).
#   --image-index N      same, by index in install.wim (overrides --image-name)
#   --product-key KEY    default: the generic key Microsoft publishes to INSTALL Pro without activating it.
#                        Pass --product-key none to give no key (single-edition ISOs).
#   --password PW        password of the local administrator "ci" (default: random, saved in DIR/credentials.txt)
#   --ssh-port N --rdp-port N   host ports on 127.0.0.1 (default 2222 and 3390)
#   --virtio-iso PATH    use this virtio-win.iso instead of downloading it
#   --no-wait            start the installation and return (it then runs 15 to 40 minutes on its own)
#   --resume             only wait for an installation already started in --dir (after --no-wait, or a Ctrl-C),
#                        then finish: reboot on the normal hardware, delete the answer file
#
# What happens: disk + UEFI variables + answer-file CD (Autounattend.xml, first-logon script) are made, the VM
# boots the ISO (the "press any key" prompt is answered over QEMU's QMP socket), Windows installs itself, the
# virtio drivers and the QEMU guest agent are installed at the first logon, the VM reboots on its disk.
# Then: ./provision.sh (installs the runner inside), see README.md.
set -euo pipefail

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
dir="${WINVM_DIR:-$HOME/.jumphp/windows-vm}" iso="" disk_size=80G cpus=4 ram=8G locale=en-US input_locale="" tz=UTC
image_name="Windows 10 Pro" image_index="" key="W269N-WFGWX-YVC9B-4J6C9-T83GX" password="" ssh_port=2222 rdp_port=3390
virtio_iso="" wait=1 resume=0
VIRTIO_URL="https://fedorapeople.org/groups/virt/virtio-win/direct-downloads/stable-virtio/virtio-win.iso"

while [ $# -gt 0 ]; do
  case "$1" in
    --iso) iso="${2:?}"; shift ;;
    --dir) dir="${2:?}"; shift ;;
    --disk-size) disk_size="${2:?}"; shift ;;
    --cpus) cpus="${2:?}"; shift ;;
    --ram) ram="${2:?}"; shift ;;
    --locale) locale="${2:?}"; shift ;;
    --input-locale) input_locale="${2:?}"; shift ;;
    --timezone) tz="${2:?}"; shift ;;
    --image-name) image_name="${2:?}"; shift ;;
    --image-index) image_index="${2:?}"; shift ;;
    --product-key) key="${2:?}"; shift ;;
    --password) password="${2:?}"; shift ;;
    --ssh-port) ssh_port="${2:?}"; shift ;;
    --rdp-port) rdp_port="${2:?}"; shift ;;
    --virtio-iso) virtio_iso="${2:?}"; shift ;;
    --no-wait) wait=0 ;;
    --resume) resume=1 ;;
    -h|--help) sed -n '2,/^set -euo/p' "$0" | sed '$d' | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "unknown option: $1" >&2; exit 1 ;;
  esac
  shift
done

die() { echo "create-vm: $*" >&2; exit 1; }

finish() {
  # ---- 4. wait for the end of the installation, then reboot on the normal (virtio) hardware ---------------------
  ge=(python3 -I "$here/guest-exec.py" --socket "$dir/qga.sock" --timeout 20)
  echo "waiting for the guest agent (installation + first logon)..."
  for i in $(seq 1 360); do   # up to 1 hour
    if "${ge[@]}" --ping >/dev/null 2>&1 && [ "$("${ge[@]}" -c 'if (Test-Path C:\jumphp-firstlogon.done) { "done" }' 2>/dev/null | tr -d '\r\n')" = "done" ]; then break; fi
    [ $((i % 6)) -ne 0 ] || echo "  still installing ($((i / 6)) min)"
    sleep 10
    st=$("$here/start-vm.sh" --dir "$dir" status 2>&1 || true)
    [[ "$st" == running* ]] || die "the VM stopped during the installation: see $dir (start it again with start-vm.sh)"
  done
  "${ge[@]}" --ping >/dev/null 2>&1 || die "no guest agent after an hour: connect with RDP/VNC? see README.md, Troubleshooting"
  echo "Windows is installed. Switching to the normal hardware and rebooting..."
  rm -f "$dir/installing"
  "$here/start-vm.sh" --dir "$dir" stop
  "$here/start-vm.sh" --dir "$dir" start
  for _ in $(seq 1 90); do "${ge[@]}" --ping >/dev/null 2>&1 && break; sleep 5; done
  "${ge[@]}" --ping >/dev/null 2>&1 || die "the guest agent does not answer after the reboot"
  # the answer file holds the password in clear text: delete the CD
  rm -rf "$dir/unattend" "$dir/unattend.iso"
  echo "VM ready in $dir. Next: export JUMPHP_RUNNER_TOKEN=... && $here/provision.sh --dir $dir --scope org/<name>"
}

if [ "$resume" = 1 ]; then
  dir=$(cd "$dir" 2>/dev/null && pwd) || die "no such folder: $dir"
  [ -e "$dir/installing" ] || die "$dir has no installation in progress"
  finish; exit 0
fi
[ -n "$iso" ] || die "--iso /path/to/Windows10.iso is required (get it from https://www.microsoft.com/software-download/windows10ISO; this script never downloads Windows)"
[ -f "$iso" ] || die "no such file: $iso"
[[ "$dir" != *,* ]] || die "the VM folder must not contain a comma (QEMU option syntax)"
[[ "$locale" =~ ^[a-z]{2}-[A-Z]{2}$ ]] || die "--locale like en-US"
[[ "$tz" =~ ^[A-Za-z0-9\ ._+-]+$ ]] || die "--timezone: unexpected characters"
[[ "$image_name" =~ ^[A-Za-z0-9\ ._+-]+$ ]] || die "--image-name: unexpected characters"
[[ "$ssh_port$rdp_port" =~ ^[0-9]+$ ]] || die "ports are numbers"
[[ "$cpus" =~ ^[0-9]+$ ]] || die "--cpus is a number"
[[ "$key" =~ ^([A-Z0-9]{5}-){4}[A-Z0-9]{5}$|^none$ ]] || die "--product-key: 5x5 characters, or none"
for t in qemu-system-x86_64 qemu-img python3; do command -v "$t" >/dev/null || die "$t is required (Debian/Ubuntu: apt install qemu-system-x86 qemu-utils python3 ovmf)"; done
mkiso=""
for t in genisoimage mkisofs xorriso; do command -v "$t" >/dev/null && { mkiso="$t"; break; }; done
[ -n "$mkiso" ] || die "genisoimage (or xorriso) is required to build the answer-file CD: apt install genisoimage"

# keyboard layout ids for the common locales
if [ -z "$input_locale" ]; then
  case "$locale" in
    en-US) input_locale="0409:00000409" ;; en-GB) input_locale="0809:00000809" ;;
    fr-FR) input_locale="040c:0000040c" ;; de-DE) input_locale="0407:00000407" ;;
    es-ES) input_locale="0c0a:0000040a" ;; it-IT) input_locale="0410:00000410" ;;
    pt-BR) input_locale="0416:00000416" ;; nl-NL) input_locale="0413:00020409" ;;
    *) die "give --input-locale for $locale (keyboard id, e.g. 0409:00000409)" ;;
  esac
fi

mkdir -p "$dir"
dir=$(cd "$dir" && pwd)
if [ -e "$dir/disk.qcow2" ]; then die "$dir already holds a VM (disk.qcow2). Use another --dir, or delete that folder."; fi
chmod 700 "$dir"     # the answer file holds the administrator password
[ -n "$password" ] || password=$(LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 20 || true)
[[ "$password" =~ ^[A-Za-z0-9._@#%+=-]{8,}$ ]] || die "--password: at least 8 characters among letters, digits and . _ @ # % + = -"

# ---- 1. disks ---------------------------------------------------------------------------------------------------
ovmf_vars=""
for f in /usr/share/OVMF/OVMF_VARS_4M.fd /usr/share/OVMF/OVMF_VARS.fd /usr/share/edk2/ovmf/OVMF_VARS.fd /usr/share/edk2/x64/OVMF_VARS.4m.fd; do
  [ -f "$f" ] && { ovmf_vars="$f"; break; }
done
[ -n "$ovmf_vars" ] || die "no OVMF_VARS file found: install the 'ovmf' (or 'edk2-ovmf') package"
cp "$ovmf_vars" "$dir/vars.fd"
qemu-img create -f qcow2 "$dir/disk.qcow2" "$disk_size" >/dev/null
ln -sf "$(readlink -f "$iso")" "$dir/windows.iso"
if [ -n "$virtio_iso" ]; then ln -sf "$(readlink -f "$virtio_iso")" "$dir/virtio-win.iso"
else
  echo "downloading the virtio drivers CD (~700 MB) from the Fedora project..."
  if command -v curl >/dev/null; then curl -fL --retry 3 -o "$dir/virtio-win.iso" "$VIRTIO_URL"
  else wget -O "$dir/virtio-win.iso" "$VIRTIO_URL"; fi
fi

# ---- 2. answer-file CD ------------------------------------------------------------------------------------------
ans="$dir/unattend"
mkdir -p "$ans"
if [ -n "$image_index" ]; then
  [[ "$image_index" =~ ^[0-9]+$ ]] || die "--image-index is a number"
  image_xml="<Key>/IMAGE/INDEX</Key><Value>$image_index</Value>"
else
  image_xml="<Key>/IMAGE/NAME</Key><Value>$image_name</Value>"
fi
key_xml=""
[ "$key" = none ] || key_xml="<ProductKey><Key>$key</Key><WillShowUI>OnError</WillShowUI></ProductKey>"
# the virtio storage driver is loaded by Setup from whichever CD letter holds it (D: to I:), so Windows installs
# straight onto the virtio disk
driver_paths=""
n=0
for l in D E F G H I; do n=$((n + 1)); driver_paths+="<PathAndCredentials wcm:action=\"add\" wcm:keyValue=\"$n\"><Path>$l:\\viostor\\w10\\amd64</Path></PathAndCredentials>"; done
ns='xmlns:wcm="http://schemas.microsoft.com/WMIConfig/2002/State" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
comp='processorArchitecture="amd64" publicKeyToken="31bf3856ad364e35" language="neutral" versionScope="nonSxS"'
cat > "$ans/Autounattend.xml" <<XML
<?xml version="1.0" encoding="utf-8"?>
<unattend xmlns="urn:schemas-microsoft-com:unattend">
  <settings pass="windowsPE">
    <component name="Microsoft-Windows-International-Core-WinPE" $comp $ns>
      <SetupUILanguage><UILanguage>$locale</UILanguage></SetupUILanguage>
      <InputLocale>$input_locale</InputLocale><SystemLocale>$locale</SystemLocale><UILanguage>$locale</UILanguage><UserLocale>$locale</UserLocale>
    </component>
    <component name="Microsoft-Windows-PnpCustomizationsWinPE" $comp $ns>
      <DriverPaths>$driver_paths</DriverPaths>
    </component>
    <component name="Microsoft-Windows-Setup" $comp $ns>
      <DiskConfiguration>
        <Disk wcm:action="add">
          <DiskID>0</DiskID><WillWipeDisk>true</WillWipeDisk>
          <CreatePartitions>
            <CreatePartition wcm:action="add"><Order>1</Order><Type>EFI</Type><Size>100</Size></CreatePartition>
            <CreatePartition wcm:action="add"><Order>2</Order><Type>MSR</Type><Size>16</Size></CreatePartition>
            <CreatePartition wcm:action="add"><Order>3</Order><Type>Primary</Type><Extend>true</Extend></CreatePartition>
          </CreatePartitions>
          <ModifyPartitions>
            <ModifyPartition wcm:action="add"><Order>1</Order><PartitionID>1</PartitionID><Format>FAT32</Format><Label>System</Label></ModifyPartition>
            <ModifyPartition wcm:action="add"><Order>2</Order><PartitionID>2</PartitionID></ModifyPartition>
            <ModifyPartition wcm:action="add"><Order>3</Order><PartitionID>3</PartitionID><Format>NTFS</Format><Label>Windows</Label><Letter>C</Letter></ModifyPartition>
          </ModifyPartitions>
        </Disk>
      </DiskConfiguration>
      <ImageInstall><OSImage>
        <InstallTo><DiskID>0</DiskID><PartitionID>3</PartitionID></InstallTo>
        <InstallFrom><MetaData wcm:action="add">$image_xml</MetaData></InstallFrom>
      </OSImage></ImageInstall>
      <UserData><AcceptEula>true</AcceptEula>$key_xml</UserData>
    </component>
  </settings>
  <settings pass="specialize">
    <component name="Microsoft-Windows-Shell-Setup" $comp $ns>
      <ComputerName>WIN-CI</ComputerName><TimeZone>$tz</TimeZone>
    </component>
    <component name="Microsoft-Windows-Deployment" $comp $ns>
      <RunSynchronous>
        <!-- no "find devices on this network" question (it would wait for a click) -->
        <RunSynchronousCommand wcm:action="add"><Order>1</Order><Path>reg add HKLM\SYSTEM\CurrentControlSet\Control\Network\NewNetworkWindowOff /f</Path></RunSynchronousCommand>
      </RunSynchronous>
    </component>
  </settings>
  <settings pass="oobeSystem">
    <component name="Microsoft-Windows-International-Core" $comp $ns>
      <InputLocale>$input_locale</InputLocale><SystemLocale>$locale</SystemLocale><UILanguage>$locale</UILanguage><UserLocale>$locale</UserLocale>
    </component>
    <component name="Microsoft-Windows-Shell-Setup" $comp $ns>
      <OOBE>
        <HideEULAPage>true</HideEULAPage><HideLocalAccountScreen>true</HideLocalAccountScreen><HideOEMRegistrationScreen>true</HideOEMRegistrationScreen>
        <HideOnlineAccountScreens>true</HideOnlineAccountScreens><HideWirelessSetupInOOBE>true</HideWirelessSetupInOOBE>
        <NetworkLocation>Other</NetworkLocation><ProtectYourPC>3</ProtectYourPC><SkipMachineOOBE>true</SkipMachineOOBE><SkipUserOOBE>true</SkipUserOOBE>
      </OOBE>
      <UserAccounts><LocalAccounts><LocalAccount wcm:action="add">
        <Name>ci</Name><Group>Administrators</Group><Password><Value>$password</Value><PlainText>true</PlainText></Password>
      </LocalAccount></LocalAccounts></UserAccounts>
      <AutoLogon><Enabled>true</Enabled><Username>ci</Username><Password><Value>$password</Value><PlainText>true</PlainText></Password><LogonCount>999</LogonCount></AutoLogon>
      <FirstLogonCommands>
        <SynchronousCommand wcm:action="add"><Order>1</Order><Description>Drivers, guest agent, RDP</Description><CommandLine>powershell -NoProfile -ExecutionPolicy Bypass -Command "foreach (\$d in [IO.DriveInfo]::GetDrives()) { \$f = \$d.Name + 'firstlogon.ps1'; if (Test-Path \$f) { &amp; \$f; break } }"</CommandLine></SynchronousCommand>
      </FirstLogonCommands>
    </component>
  </settings>
</unattend>
XML
cat > "$ans/firstlogon.ps1" <<'PS'
# First logon (runs elevated, from the answer-file CD). Log: C:\jumphp-firstlogon.txt. ASCII only.
$ErrorActionPreference = 'Continue'
function L($m) { ((Get-Date -Format s) + ' ' + $m) | Out-File C:\jumphp-firstlogon.txt -Append -Encoding ascii }
L 'start'
# a build machine never sleeps; no hibernation file
powercfg /h off | Out-Null
powercfg /setactive SCHEME_MIN | Out-Null
foreach ($o in 'standby-timeout-ac','monitor-timeout-ac','disk-timeout-ac') { powercfg /change $o 0 | Out-Null }
# the clock (the guest agent needs the time service to set it back after a pause)
Set-Service W32Time -StartupType Automatic; Start-Service W32Time -ErrorAction SilentlyContinue
# Remote Desktop (the host port is bound to 127.0.0.1 only)
reg add 'HKLM\SYSTEM\CurrentControlSet\Control\Terminal Server' /v fDenyTSConnections /t REG_DWORD /d 0 /f | Out-Null
Enable-NetFirewallRule -DisplayGroup 'Remote Desktop' -ErrorAction SilentlyContinue
# virtio drivers (disk, network, balloon, serial, input, display) + the QEMU guest agent, from the virtio-win CD
$gt = $null
foreach ($d in [IO.DriveInfo]::GetDrives()) { $f = $d.Name + 'virtio-win-guest-tools.exe'; if (Test-Path $f) { $gt = $f; break } }
if ($gt) {
  L ('virtio installer: ' + $gt)
  $p = Start-Process $gt -ArgumentList '/install','/quiet','/norestart' -Wait -PassThru
  L ('virtio installer exit code ' + $p.ExitCode)
  Start-Service QEMU-GA -ErrorAction SilentlyContinue
  L ('guest agent: ' + (Get-Service QEMU-GA -ErrorAction SilentlyContinue).Status)
} else { L 'virtio-win CD not found' }
# OpenSSH server (best effort: it downloads a Windows component, needs the network)
try {
  $cap = Get-WindowsCapability -Online -Name 'OpenSSH.Server*' | Select-Object -First 1
  if ($cap.State -ne 'Installed') { Add-WindowsCapability -Online -Name $cap.Name | Out-Null }
  Set-Service sshd -StartupType Automatic; Start-Service sshd
  New-NetFirewallRule -Name 'sshd-ci' -DisplayName 'OpenSSH (CI VM)' -Enabled True -Direction Inbound -Protocol TCP -Action Allow -LocalPort 22 -ErrorAction SilentlyContinue | Out-Null
  L 'sshd started'
} catch { L ('OpenSSH not installed: ' + $_.Exception.Message) }
'ok' | Out-File C:\jumphp-firstlogon.done -Encoding ascii
L 'end'
PS
"$mkiso" -quiet -J -R -V UNATTEND -o "$dir/unattend.iso" "$ans"
printf 'NAME=jumphp-win\nCPUS=%s\nRAM=%s\nSSH_PORT=%s\nRDP_PORT=%s\n' "$cpus" "$ram" "$ssh_port" "$rdp_port" > "$dir/vm.conf"
umask 077
printf 'user: ci\npassword: %s\n' "$password" > "$dir/credentials.txt"
touch "$dir/installing"

# ---- 3. boot the installer ------------------------------------------------------------------------------------
"$here/start-vm.sh" --dir "$dir" start
# OVMF asks "Press any key to boot from CD": answer it for a while over QMP (the keys do nothing later on)
python3 -I - "$dir/qmp.sock" <<'PY' &
import json, socket, sys, time
s = socket.socket(socket.AF_UNIX)
for _ in range(50):
    try: s.connect(sys.argv[1]); break
    except OSError: time.sleep(0.2)
f = s.makefile('rw'); f.readline()
f.write(json.dumps({'execute': 'qmp_capabilities'}) + '\n'); f.flush(); f.readline()
end = time.time() + 45
while time.time() < end:
    f.write(json.dumps({'execute': 'send-key', 'arguments': {'keys': [{'type': 'qcode', 'data': 'spc'}]}}) + '\n'); f.flush(); f.readline()
    time.sleep(1.5)
PY
echo "Windows is installing itself (15 to 40 minutes). Administrator account: ci, password in $dir/credentials.txt"

[ "$wait" = 1 ] || { echo "Not waiting (--no-wait). Finish later with: $0 --dir $dir --resume"; exit 0; }
finish
