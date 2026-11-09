#!/usr/bin/env bash
# Starts (or stops, or shows the state of) the Windows CI VM, HEADLESS: no window, no display. Every port the VM
# exposes is bound to 127.0.0.1 ONLY (nothing is reachable from your network):
#     RDP  127.0.0.1:<rdp-port>   (default 3390)      SSH 127.0.0.1:<ssh-port> (default 2222, if OpenSSH was installed)
#     guest agent: the unix socket <dir>/qga.sock (used by guest-exec.py and provision.sh)
#
#   ./start-vm.sh [--dir DIR] [start|stop|status|kill]
#
# DIR (default $WINVM_DIR or ~/.jumphp/windows-vm) is the folder made by create-vm.sh: disk.qcow2, vars.fd, vm.conf...
# "stop" asks Windows to shut down through the guest agent (clean); "kill" quits QEMU at once (like pulling the plug).
# While the file DIR/installing exists (create-vm.sh), the VM boots the Windows ISO with the answer file.
set -euo pipefail

dir="${WINVM_DIR:-$HOME/.jumphp/windows-vm}" action=start
while [ $# -gt 0 ]; do
  case "$1" in
    --dir) dir="${2:?}"; shift ;;
    start|stop|status|kill) action="$1" ;;
    -h|--help) sed -n '2,/^set -euo/p' "$0" | sed '$d' | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "unknown argument: $1" >&2; exit 1 ;;
  esac
  shift
done
dir=$(cd "$dir" 2>/dev/null && pwd) || { echo "no such VM folder: $dir (run create-vm.sh first)" >&2; exit 1; }
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
[ -f "$dir/vm.conf" ] || { echo "$dir/vm.conf missing: run create-vm.sh first" >&2; exit 1; }
# vm.conf: simple KEY=value lines written by create-vm.sh (NAME, CPUS, RAM, SSH_PORT, RDP_PORT)
NAME=jumphp-win; CPUS=4; RAM=8G; SSH_PORT=2222; RDP_PORT=3390
while IFS='=' read -r k v; do
  case "$k" in NAME|CPUS|RAM|SSH_PORT|RDP_PORT) printf -v "$k" '%s' "$v" ;; esac
done < "$dir/vm.conf"

pidfile="$dir/qemu.pid"
running() { [ -s "$pidfile" ] && kill -0 "$(cat "$pidfile")" 2>/dev/null; }

case "$action" in
  status)
    if running; then
      echo "running (pid $(cat "$pidfile")), RDP 127.0.0.1:$RDP_PORT, SSH 127.0.0.1:$SSH_PORT"
      python3 -I "$here/guest-exec.py" --socket "$dir/qga.sock" --timeout 5 --ping 2>/dev/null || echo "guest agent: not answering (Windows still booting, or tools not installed)"
    else echo "not running"; fi
    exit 0 ;;
  stop)
    running || { echo "not running"; exit 0; }
    python3 -I "$here/guest-exec.py" --socket "$dir/qga.sock" --timeout 20 -c 'Stop-Computer -Force' >/dev/null 2>&1 || echo "guest agent did not answer; use: $0 --dir $dir kill" >&2
    for _ in $(seq 1 60); do running || { echo "stopped"; exit 0; }; sleep 2; done
    echo "still running after 2 minutes; use 'kill' if Windows is stuck" >&2; exit 1 ;;
  kill)
    running && kill "$(cat "$pidfile")" && echo "killed"; exit 0 ;;
esac

running && { echo "already running (pid $(cat "$pidfile"))"; exit 0; }
if ! { [ -c /dev/kvm ] && [ -w /dev/kvm ]; }; then echo "/dev/kvm is missing or not writable for you: enable virtualization in the BIOS and add yourself to the kvm group" >&2; exit 1; fi

# UEFI firmware (Debian/Ubuntu: ovmf; Fedora: edk2-ovmf; Arch: edk2-ovmf)
ovmf=""
for f in /usr/share/OVMF/OVMF_CODE_4M.fd /usr/share/OVMF/OVMF_CODE.fd /usr/share/edk2/ovmf/OVMF_CODE.fd /usr/share/edk2/x64/OVMF_CODE.4m.fd /usr/share/qemu/OVMF_CODE.fd; do
  [ -f "$f" ] && { ovmf="$f"; break; }
done
[ -n "$ovmf" ] || { echo "no OVMF firmware found: install the 'ovmf' (or 'edk2-ovmf') package" >&2; exit 1; }

rm -f "$dir/qga.sock" "$dir/qmp.sock"
# shellcheck disable=SC2054  # QEMU options contain commas on purpose
args=(
  -name "$NAME" -machine q35,accel=kvm
  # Hyper-V enlightenments: Windows uses far less CPU in a VM with these
  -cpu host,hv_relaxed,hv_vapic,hv_spinlocks=0x1fff,hv_time,hv_vpindex,hv_runtime,hv_synic,hv_stimer,hv_tlbflush,hv_ipi
  -rtc base=localtime,driftfix=slew
  -smp "$CPUS" -m "$RAM"
  -drive if=pflash,format=raw,readonly=on,file="$ovmf"
  -drive if=pflash,format=raw,file="$dir/vars.fd"
  -object iothread,id=io1 -device ich9-ahci,id=ahci
  -drive file="$dir/disk.qcow2",if=none,id=hd0,format=qcow2,cache=none,aio=native,discard=unmap,detect-zeroes=unmap
  -device virtio-blk-pci,drive=hd0,iothread=io1,bootindex=1
  -device virtio-balloon-pci
  # the guest agent channel: a unix socket in the VM folder (owner-only permissions through the folder)
  -chardev socket,id=qga0,path="$dir/qga.sock",server=on,wait=off
  -device virtio-serial-pci,id=vser -device virtserialport,chardev=qga0,name=org.qemu.guest_agent.0
  -device VGA,xres=1280,yres=800
  -display none -monitor none -serial none -parallel none
  -device qemu-xhci,id=xhci -device usb-tablet,bus=xhci.0
  -qmp "unix:$dir/qmp.sock,server=on,wait=off"
  -pidfile "$pidfile" -daemonize
)
fwd="hostfwd=tcp:127.0.0.1:$SSH_PORT-:22,hostfwd=tcp:127.0.0.1:$RDP_PORT-:3389"
if [ -e "$dir/installing" ]; then
  # install mode: e1000e network (Windows has the driver), the Windows ISO, the virtio-win CD, the answer-file CD
  # shellcheck disable=SC2054
  args+=(-device "e1000e,netdev=n0" -netdev "user,id=n0,$fwd"
         -drive file="$dir/windows.iso",if=none,id=cd0,media=cdrom,readonly=on -device ide-cd,drive=cd0,bus=ahci.1,bootindex=0
         -drive file="$dir/virtio-win.iso",if=none,id=cd1,media=cdrom,readonly=on -device ide-cd,drive=cd1,bus=ahci.2
         -drive file="$dir/unattend.iso",if=none,id=cd2,media=cdrom,readonly=on -device ide-cd,drive=cd2,bus=ahci.3)
else
  args+=(-device "virtio-net-pci,netdev=n0" -netdev "user,id=n0,$fwd")
fi
qemu-system-x86_64 "${args[@]}"
echo "started $NAME (pid $(cat "$pidfile")): RDP 127.0.0.1:$RDP_PORT, SSH 127.0.0.1:$SSH_PORT, agent $dir/qga.sock"
