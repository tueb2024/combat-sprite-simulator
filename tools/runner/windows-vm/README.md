# A headless Windows VM on Linux, as a CI runner for Windows builds

For a **Linux** user who needs Windows jobs (the Windows library of the Tauri runtime, `.exe`, installers):
QEMU/KVM runs a Windows 10 VM with no window; [`install-windows-runner.ps1`](../install-windows-runner.ps1) is
installed inside it. The full guide (which runner for which job, security, troubleshooting) is
[docs/ci-runner.md](../../../docs/ci-runner.md) ([en français](../../../docs/ci-runner.fr.md)).

Based on a setup in daily use (unattended Windows 10 install, virtio drivers, the QEMU guest agent to drive PowerShell
without SSH). Verified end to end on a Debian host: `create-vm.sh` (French Windows 10 Pro 22H2 ISO, 2 vCPU, 3 GB) then
`provision.sh`, see "What was tested" at the end.

## Licence, plainly

* **Windows needs a licence you own.** This kit installs Windows 10 Pro without a product key it would activate; the
  generic key it uses (published by Microsoft) only lets Setup choose the Pro edition. An unactivated Windows 10 runs
  (watermark, no personalization) but you must hold a licence for it.
* **Nothing is downloaded from Microsoft by these scripts, and no Windows image is shipped here.** You download the
  ISO yourself, from Microsoft's page: <https://www.microsoft.com/software-download/windows10ISO> (from a browser:
  "Windows 10 (multi-edition ISO)", your language, 64-bit). Never use an ISO from another source.
* **Evaluation ISOs expire** (the Enterprise LTSC 2021 evaluation ISO was seen installing already expired; others stop
  after 90 days and the VM then shuts down every hour). Use the multi-edition ISO above.
* Windows 10 no longer receives free security updates since October 2025: keep the VM **throw-away**, with nothing
  personal in it (see the security section of the guide).

## What you need on the host

Linux with KVM (`/dev/kvm`, you in the `kvm` group), 8 GB of RAM or more (the VM takes 8 GB by default, 4 GB is enough
for the Tauri library), 80 GB of disk (sparse file), and:

```bash
sudo apt install qemu-system-x86 qemu-utils ovmf genisoimage python3 curl     # Debian / Ubuntu
sudo dnf install qemu-kvm qemu-img edk2-ovmf genisoimage python3 curl         # Fedora
```

## Steps

```bash
cd tools/runner/windows-vm            # or: bin/jumphp runner:scripts ~/runner-kit && cd ~/runner-kit/windows-vm

# 1. create the VM (about 15 to 40 minutes, unattended; the virtio-win CD, ~700 MB, comes from the Fedora project)
./create-vm.sh --iso ~/Downloads/Win10_22H2_English_x64.iso --dir ~/windows-ci-vm
#    other language: --locale fr-FR --timezone "Romance Standard Time"   (the locale must be in YOUR ISO)
#    the administrator "ci" password is random, saved in ~/windows-ci-vm/credentials.txt (mode 600)

# 2. install the runner INSIDE it (token of ONE user or organization; 20 to 60 minutes the first time)
export JUMPHP_RUNNER_TOKEN=<registration token>          # forge: Settings > Actions > Runners > Create new runner
./provision.sh --dir ~/windows-ci-vm --scope org/<name> --name win-vm

# 3. daily use
./start-vm.sh --dir ~/windows-ci-vm            # start (headless); Windows starts the runner by itself at boot
./start-vm.sh --dir ~/windows-ci-vm status
./start-vm.sh --dir ~/windows-ci-vm stop       # clean shutdown through the guest agent
```

`WINVM_DIR=~/windows-ci-vm` in your environment replaces `--dir` everywhere.

## The files

| File | Role |
|---|---|
| `create-vm.sh` | builds the disk, the UEFI variables, the answer-file CD (`Autounattend.xml` + `firstlogon.ps1`), boots the Windows ISO headless (answers "press any key" over QMP), waits for the end, reboots on the normal virtio hardware, deletes the answer file (it holds the password). `--no-wait` / `--resume` split it in two. |
| `start-vm.sh` | headless QEMU/KVM. `start`, `stop` (guest agent), `status`, `kill`. Ports on **127.0.0.1 only**: RDP 3390, SSH 2222, and the guest agent socket `<dir>/qga.sock`. |
| `guest-exec.py` | PowerShell in the guest through the QEMU guest agent: `-c 'cmd'`, `-f script.ps1`, `--put LOCAL REMOTE`, `--put-env VAR REMOTE`, `--detach`, `--ping`. No SSH, no network. |
| `provision.sh` | copies `../install-windows-runner.ps1` into the guest and runs it there (detached, followed to the end). The token comes from the environment and goes to a file only Administrators can read, deleted at once by the guest wrapper: never on a command line (`ps`). |

Open a desktop on the VM when you need to (activation, a dialog): `xfreerdp /v:127.0.0.1:3390 /u:ci` (password in
`credentials.txt`). The port is bound to localhost: from another machine, go through an SSH tunnel.

## Day-to-day notes

* **Reset to a clean state**: stop the VM and take a copy of `disk.qcow2` + `vars.fd` right after `provision.sh`
  (`qemu-img snapshot -c clean disk.qcow2` while it is stopped, `qemu-img snapshot -a clean disk.qcow2` to go back).
  The runner's credentials are on that disk: after a revert the runner is still registered.
* **Auto-start with your machine**: a systemd user service running `start-vm.sh --dir ... start`, or just start it
  when you need builds; jobs wait in the forge's queue while the runner is off.
* **Disk**: Rust's `target` folders grow; delete `C:\ci\target-tauri` in the guest from time to time.
* **Update the runner / tools**: run `provision.sh` again (it is idempotent: it only installs what is missing; a new
  Rust version is the `-RustVersion` parameter of the `.ps1`, which must match `RUST_VERSION` of the workflow).
* **Remove everything**: `./start-vm.sh --dir D stop`, delete the folder D, then delete the runner in the forge.

## Troubleshooting

* `cannot reach the guest agent socket`: the VM is not running (`start-vm.sh status`), or Windows is still booting
  (the agent answers some 30 s after the logon screen). `create-vm.sh` installs it from the virtio-win CD.
* `/dev/kvm is missing or not writable`: enable virtualization in the BIOS; `sudo usermod -aG kvm $USER`, log in again.
* The install seems stuck: take a look with `python3 - <<'EOF'` ... QMP `screendump` (see `qmp.sock` in the VM folder),
  or RDP once Windows is up. Setup needs the ISO language to match `--locale`; the edition name must exist in the ISO
  (`--image-name "Windows 10 Pro"`; list them with `wiminfo` from `wimtools`, or give `--image-index`).
* The disk is `virtio-blk`: a Windows ISO older than Windows 10 1903 may not take the drivers path; use 22H2.
* `create-vm.sh` was interrupted: `./create-vm.sh --dir D --resume` waits for the end and finishes.
* Everything else about the runner itself (registration, labels, jobs): the troubleshooting section of the guide.

## What was tested

Host Debian 13, QEMU 10, KVM; ISO `Win10 22H2` French multi-edition from Microsoft's page.
`create-vm.sh --locale fr-FR` produced a working Windows 10 Pro (French) in about 15 minutes with the guest agent,
virtio network, RDP and OpenSSH running, and `start-vm.sh` (headless, localhost-only ports) restarted it.
`provision.sh` + `install-windows-runner.ps1` were run on this fresh VM (log in the session notes) and, in a second
VM that already had the tools, re-run to check idempotence. Not tested: other locales, Windows 11 (needs TPM and
Secure Boot, not covered), non-Debian hosts, hosts without KVM.
