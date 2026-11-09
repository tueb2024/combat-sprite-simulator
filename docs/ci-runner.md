# Run your CI jobs on your own machine

[Français](ci-runner.fr.md)

The workflows of `.github/workflows/` run on the forge (git.jumphp.net, Forgejo Actions) with the same syntax as GitHub
Actions (`actions/*` come from github.com through the forge's `DEFAULT_ACTIONS_URL`). **Nobody's jobs run on the
forge's own machines but its administrator's: you bring your own runner**, on a PC, a VPS or a VM. This page tells you
which one, how to install it with one script, and what to watch out for.

| Your project lives on | Runners | How |
|---|---|---|
| **GitHub** | GitHub's (hosted), or your own self-hosted ones | the workflows of your repository, on **your** GitHub account's runners ([GitHub self-hosted notes](#a-project-on-github-and-github-self-hosted-runners)) |
| **git.jumphp.net** | **your own runners** | this page |

## 1. Which runner do I need?

| What you build | Needs | Runner (label) | Your machine | Script |
|---|---|---|---|---|
| JumPHP builds, engine tests, Android, `web`, anything in Docker | Linux + Docker | Linux (`ubuntu-latest`) | **Linux / macOS / a VPS** | `bin/jumphp runner:install --scope ... --name ...` |
| the same | Linux + Docker | Linux (`ubuntu-latest`) | **Windows** | [`tools/runner/install-wsl-linux-runner.ps1`](../tools/runner/install-wsl-linux-runner.ps1) (WSL2 + Docker Engine inside) |
| the Windows library of the Tauri runtime (`jumphp_tauri.dll`), `.exe`, Windows installers | Windows + MSVC + Rust | Windows (`windows-latest`) | **Windows** | [`tools/runner/install-windows-runner.ps1`](../tools/runner/install-windows-runner.ps1) |
| the same | Windows + MSVC + Rust | Windows (`windows-latest`) | **Linux** | [`tools/runner/windows-vm/`](../tools/runner/windows-vm/README.md) (a headless QEMU/KVM Windows VM, then the script above inside it) |
| macOS / iOS apps | a Mac + Xcode | macOS | a Mac | no script yet (use GitHub's Macs, [docs/apple.md](apple.md)) |

You can have several runners on the same machine (a Windows PC can host the Windows runner *and* the WSL2 Linux one).
Get the scripts without a checkout: `bin/jumphp runner:scripts <folder>` copies `tools/runner/` where you say, or download
them from the repository.

**The registration token** (all cases): in the forge, the **organization** (or your **user**) > Settings > Actions >
Runners > *Create new runner*. It works once and is for that user/organization only. Give it to the scripts in the
environment variable `JUMPHP_RUNNER_TOKEN` (not on the command line: command lines are visible to other processes
and stay in your shell history).

## 2. Linux, macOS or a VPS: one command

Only Docker is needed.

```bash
bin/jumphp runner:install --scope user/<your-login> --name my-pc      # your own repositories
bin/jumphp runner:install --scope org/<organization> --name vps-1     # an organization's repositories
```

- **Scoped to ONE user or organization, never the whole instance** (registrations on the forge are open: an instance-wide
  runner would let anyone run code on your machine).
- The jobs run in the runner's **own Docker daemon** (`docker:dind`): the host's `docker.sock` is never mounted.
- The token: `JUMPHP_RUNNER_TOKEN`, or made through the API with your forge token (`JUMPHP_FORGE_TOKEN`, or the one git
  already has for git.jumphp.net). It is not stored.
- Files: `~/.jumphp/runners/<name>/` (`compose.yaml`, `data/`). `docker compose -f ... logs -f` to watch it.
- Remove it: `bin/jumphp runner:remove --name my-pc`, then delete it in the forge (Settings > Actions > Runners).
- On a VPS: install Docker, get JumPHP, run the command, done (`restart: unless-stopped`).

## 3. Windows, native runner (Windows builds)

> The `standalone` workflow (PHP micro for Windows, StaticPHP + Visual Studio) works on a Windows of any language: it sets `VSLANG=1033` (MSVC tools in English, PHP's `configure.js` reads their output), adds Git's `usr\bin` to the PATH, and installs PHP + Composer itself when the runner has none. Visual Studio 2022 Build Tools (C++) and Git for Windows must be there; it runs in Windows PowerShell (no `pwsh` needed).

[`tools/runner/install-windows-runner.ps1`](../tools/runner/install-windows-runner.ps1), PowerShell 5.1 or later, **as
Administrator**. Windows 10 or 11, 64-bit, about 15 GB free, internet.

```powershell
$env:JUMPHP_RUNNER_TOKEN = '<registration token>'
.\install-windows-runner.ps1 -Scope org/<organization> -Name office-pc
```

(If scripts are blocked: `powershell -ExecutionPolicy Bypass -File .\install-windows-runner.ps1 -Scope ...`.)

Parameters: `-Instance` (default `https://git.jumphp.net`), `-Scope org/<name>|user/<login>` (required), `-Token` (else
`JUMPHP_RUNNER_TOKEN`, else asked, hidden), `-Name`, `-Labels` (default `windows-latest:host,windows:host`),
`-InstallDir` (default `C:\ci`), `-RustVersion` (default = `RUST_VERSION` of `.github/workflows/tauri.yml`: keep them
equal), `-RunnerVersion`, `-SkipBuildTools`, `-ReRegister`, `-Uninstall` (`-Purge`: also the folder).

What it installs, from official sources only (the check is in brackets):

| Tool | Source |
|---|---|
| Git for Windows (its `bash` runs the workflow steps) | GitHub releases of git-for-windows (SHA-256 published with the release) |
| Visual Studio 2022 Build Tools: C++ x64 tools + Windows 11 SDK, nothing else | `aka.ms/vs/17/release/vs_BuildTools.exe` (Microsoft publishes no checksum: its Authenticode signature is checked) |
| Rust, the workflow's version, **machine-wide** `RUSTUP_HOME=C:\rust\rustup`, `CARGO_HOME=C:\rust\cargo` | `static.rust-lang.org` (SHA-256 of rustup-init) |
| Node.js LTS (the JavaScript actions such as `actions/checkout`) | `nodejs.org/dist` (SHA-256 of `SHASUMS256.txt`) |
| forgejo-runner | **code.forgejo.org publishes no Windows build** (Linux only, checked 2026-11-07): the script compiles the release tag with a Go toolchain (go.dev, SHA-256 checked) that it deletes afterwards. If a Windows binary with a checksum appears in the release, it downloads that instead. |

Then it registers the runner (**host mode**: jobs run directly on this machine, no container) and creates the scheduled
task `ForgejoRunner-<name>`: at startup, as SYSTEM, restarted on failure, with Git's `bash` **first** in the `PATH`
(see Troubleshooting). It prints a summary and waits for the "declared successfully" line of the runner.

Idempotent: run it again and only what is missing is done (an installed tool, a registered runner are skipped). A first
run takes 20 to 60 minutes (Build Tools and the Rust download dominate). Reboot: only if the Visual Studio installer asks
for it (the script says so; the runner then starts by itself at boot). Remove: `.\install-windows-runner.ps1 -Uninstall -Name office-pc`
and delete the runner in the forge. The tools stay.

A laptop must not sleep: Settings > Power > "Never" when plugged in.

## 4. Windows, Linux jobs (JumPHP builds, Android, tests)

[`tools/runner/install-wsl-linux-runner.ps1`](../tools/runner/install-wsl-linux-runner.ps1), as Administrator, Windows 10
2004+ or 11, virtualization enabled in the BIOS. **No Docker Desktop**: Docker Engine is installed *inside* WSL2.

```powershell
$env:JUMPHP_RUNNER_TOKEN = '<registration token>'
.\install-wsl-linux-runner.ps1 -Scope user/<login> -Name alice-pc
```

It enables WSL and the Virtual Machine Platform, installs `Ubuntu-24.04` without opening it (user `jumphp`, systemd on in
`/etc/wsl.conf`), installs Docker Engine from Docker's official apt repository (key checked against Docker's published
fingerprint), clones JumPHP into the distribution, runs `bin/jumphp runner:install --scope ... --name ...` there, and
creates the scheduled task `JumphpWslRunner-<name>` that keeps the distribution alive (a distribution without a
process is stopped by WSL, and so would be the runner).

**Reboots.** The first time, enabling WSL usually needs a restart of Windows: the script stops with exit code 3010 and
says so. Restart, open an Administrator PowerShell and **run the same command again**: it continues where it stopped
(the token is never stored, so set `JUMPHP_RUNNER_TOKEN` again; once the runner is registered the token is not asked any more).

Options: `-Distro`, `-LinuxUser`, `-Memory 8GB -Processors 4` (written to `%USERPROFILE%\.wslconfig`: WSL2's default is half
the RAM), `-Labels`, `-RunAtBoot` (see below), `-Uninstall` (`-RemoveDistro` deletes the distribution and everything in it).

**Start at logon or at boot.** WSL distributions belong to one Windows user, so the task runs as *you*: by default at your
logon (the runner is off while nobody is logged on). `-RunAtBoot` asks for your Windows password (kept by Windows' Task
Scheduler, not by the script) and starts at boot. For a dedicated machine, also set up auto-logon, or use `-RunAtBoot`.

## 5. Linux, Windows jobs: a headless Windows VM

For the Windows library of the Tauri runtime when you are on Linux. [`tools/runner/windows-vm/`](../tools/runner/windows-vm/README.md)
creates a Windows 10 VM with QEMU/KVM (unattended install, virtio drivers, no window), then installs section 3 inside it:

```bash
cd tools/runner/windows-vm
./create-vm.sh --iso ~/Downloads/Win10_22H2.iso --dir ~/windows-ci-vm          # 15-40 min, unattended
export JUMPHP_RUNNER_TOKEN=<registration token>
./provision.sh --dir ~/windows-ci-vm --scope org/<name> --name win-vm           # 20-60 min
./start-vm.sh --dir ~/windows-ci-vm [start|stop|status]
```

- **Windows needs a licence you own.** Download the ISO yourself from Microsoft
  (<https://www.microsoft.com/software-download/windows10ISO>); the scripts download no Windows and ship none. Evaluation
  ISOs expire (one was seen installing already expired): use the multi-edition ISO. Windows 10 no longer gets free
  security updates: keep the VM a throw-away machine.
- The VM's RDP / SSH ports are bound to `127.0.0.1`; PowerShell inside it is driven through the QEMU guest agent
  (`guest-exec.py`, no SSH). The token goes into the guest through the agent (a private folder, deleted at once), never on a
  command line.
- Needs `/dev/kvm`, 8 GB RAM, 80 GB disk. Everything: the README of that folder.

## 6. How a project chooses its runner

By the **labels** in `runs-on:`. A job waits until a runner of its scope has *all* the labels.

| Label | Registered by | Runs in |
|---|---|---|
| `ubuntu-latest`, `ubuntu-22.04` | `runner:install`, WSL2 script | a Docker container (`node:20-bookworm`) |
| `windows-latest`, `windows` | `install-windows-runner.ps1` (host mode) | directly on the machine (the `:host` labels) |

```yaml
jobs:
  linux:   { runs-on: ubuntu-latest,  steps: [ ... ] }
  windows:
    if: github.server_url != 'https://github.com'      # on GitHub, windows-latest is GitHub's own
    runs-on: windows-latest
    steps:
      - uses: actions/checkout@v4
      - shell: bash                                    # Git for Windows' bash, same scripts as on Linux
        run: ...
```

Your own label: `-Labels my-gpu:host` (Windows script) or `--labels my-gpu:docker://image` (`runner:install`), then `runs-on: my-gpu`.
Where the workflows live: **`.github/workflows/`**. Do **not** create `.forgejo/workflows/`: Forgejo reads it *instead of*
`.github/workflows` when it exists, and every other workflow would silently stop running.

The Tauri Windows library is a working example: `.github/workflows/tauri-windows.yml` (`runs-on: windows-latest`, forge only)
builds `jumphp_tauri.dll` and publishes it as the asset `jumphp_tauri-windows-x86_64.dll` of the release `tauri-<key>`
(`bash tools/tauri-key.sh | sha256sum | cut -c1-16`) that `bin/jumphp tauri:download windows-x86_64` and
`app:export <app> --windows` fetch. It publishes with the job's `github.token` (it can create releases and upload assets)
or a `FORGE_TOKEN` secret if you set one. Its Cargo target folder is `C:/ci/target-tauri` (kept: incremental builds), which is
why `-InstallDir` defaults to `C:\ci`.

## 7. A project on GitHub, and GitHub self-hosted runners

Its workflows use GitHub's runners (`ubuntu-latest`, `macos-15`, `windows-latest`) with your account's minutes, or your own
**self-hosted GitHub runner** (Settings > Actions > Runners > *New self-hosted runner*: GitHub gives the commands for your
system). The JumPHP scripts above are for **Forgejo** runners; for GitHub the equivalents are:

- Windows: GitHub's `config.cmd --url ... --token ...` then `svc.cmd install`; install the same tools (Git, Build Tools, Rust,
  Node) with the first half of `install-windows-runner.ps1` (`-SkipBuildTools` etc. do not apply: just read its steps);
  `runs-on: [self-hosted, windows]`.
- Linux: GitHub's `config.sh` and `svc.sh install`, or a container image of your choice; `runs-on: [self-hosted, linux]`.
- **Never use a self-hosted runner on a public repository** whose pull requests anyone can open: a fork's workflow would run
  on your machine. Same rule as for the forge.

The `gh` commands of `bin/jumphp` (android, apple, installer, release) work on both forges: `tools/bin/gh` is the real `gh` for
GitHub and talks to git.jumphp.net's API otherwise.

## 8. Security (read this)

- **Scope to ONE user or organization**, never the instance. Registrations on the forge are open: an instance-wide runner lets
  anyone run code on your machine.
- **A runner runs the code of the workflows of its scope.** Anyone who can push a workflow to one of that owner's repositories
  (or whose pull request a workflow runs) can run commands on the runner. Register runners only for owners you trust, and give
  write access sparingly.
- **Host mode (Windows script) has no isolation**: jobs run as SYSTEM directly on the machine, with all its files and network.
  Use a **throw-away VM** (section 5) or a machine with nothing precious: no personal data, no saved credentials, no access to
  your other machines. Linux runners isolate jobs in containers inside their own Docker daemon (privileged `dind`, still: do not
  expose it to strangers).
- **Tokens are not stored**: the scripts take the registration token from the environment (or a hidden prompt), use it once, never
  write it to a file or a log. The runner keeps its own credential (`<InstallDir>\runner\.runner`, readable by Administrators and
  SYSTEM only; `~/.jumphp/runners/<name>/data/.runner`): treat it as a secret. Registration tokens are one-shot, but do not paste
  them into chats or commit them.
- **Revoke**: delete the runner in the forge (Settings > Actions > Runners): it can no longer take jobs. Then run the script with
  `-Uninstall` / `runner:remove` on the machine, or delete the VM.
- Secrets of a repository are given to its jobs: a runner you share with someone else's workflows can read them. One runner per
  trust level.
- A runner works while its machine is on; jobs wait in the queue while it is off.

## 9. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| Jobs fail with odd `bash` errors on Windows, or "WSL has no installed distribution" | `C:\Windows\System32\bash.exe` is the **WSL stub**. The runner's `run.ps1` puts `C:\Program Files\Git\bin` first in `PATH`; if you start the runner another way, do the same. |
| A script step dies silently on Windows | the workflow `shell: bash` runs with `-e`: a failing `grep` that finds nothing ends the step. Append `|| true`. |
| `sha256sum` hashes differ between Windows and Linux | Git for Windows prints `hash *file`. Cut the first field (`cut -d' ' -f1`) / `sed 's/ \*/  /'`. |
| A hash of checked-out files differs (a key made from files) | Git for Windows turned LF into CRLF at checkout: `git config core.autocrlf false` and `git checkout HEAD -- <files>` (done in `tauri-windows.yml`). |
| The workflow does not run at all | a `.forgejo/workflows/` folder exists (it replaces `.github/workflows`); or the push did not touch the workflow's `paths:`; or no runner of that scope has all the labels. |
| "no matching runner" / job waits forever | runner offline (`Settings > Actions > Runners`), wrong scope (a `user/` runner does not serve an organization), a label missing. |
| `registration failed` | the token is used already or belongs to another scope; make a new one. Check `-Instance`. |
| A re-run did nothing new | by design (idempotent). `-ReRegister` (Windows) / `-Uninstall` then again, to start over. |
| VS Build Tools: exit code 3010 | reboot needed; the runner starts by itself afterwards. 1618: another installer is running. Logs: `%TEMP%\dd_*.log`. |
| PowerShell: `NativeCommandError` on rustup / git / go output | PowerShell treats stderr as an error under `$ErrorActionPreference='Stop'`; the scripts wrap native programs for that. Do the same in your own scripts. |
| `generate-config` gives a config nobody can read (Windows) | PowerShell 5.1's `>` writes UTF-16; use `cmd /c "... > file"` (the script does). |
| The release step says "already exists" / creates a wrong release | the key of the release comes from hashes of the sources (above); fix them, delete the stale release by hand. |
| WSL: the runner stops when you close the terminal | the keep-alive scheduled task is missing or not running: `Get-ScheduledTask JumphpWslRunner-*`, `Start-ScheduledTask`. |
| WSL: `systemd` is not running | `/etc/wsl.conf` needs `[boot] systemd=true`; `wsl --shutdown`, run the script again. |
| Windows VM: cannot reach the guest agent | VM off, or Windows still booting; `./start-vm.sh status`. |
| Windows VM: install does not finish | the ISO language must match `--locale`; the edition name must exist in the ISO (`--image-index`). |
| Build disk fills up | Rust `target` folders (`C:\ci\target-tauri`) and Docker images (`docker system prune`). |
| Runner version "dev" or too old for the forge | install a newer one: `-RunnerVersion v13.x.y` (the Docker runner image of `runner:install` is `forgejo/runner:13`). |

Logs: Windows `<InstallDir>\runner\runner.log`; Linux `docker compose -f ~/.jumphp/runners/<name>/compose.yaml logs -f`;
WSL `wsl -d Ubuntu-24.04 -u jumphp -- docker logs jumphp-runner-<name>-runner-1`.

> Building the PHP micro (`standalone` workflow) on these runners, and what GitHub's images gave for free: [ci-standalone.md](ci-standalone.md).

## 10. For AI agents

You may be asked "set up a runner on my machine". Do this, and nothing else:

1. **Ask first.** Say what you are going to install (Git, Visual Studio Build Tools about 5 GB, Rust, Node, a runner, WSL, a VM...),
   where, and that it needs administrator rights and a long download. Install nothing until the user says yes.
2. **Never register an instance-wide runner.** Scope = ONE user or organization that the user names. Ask which, do not guess.
3. **Tokens**: the user creates the registration token in the forge and gives it to you in an environment variable
   (`JUMPHP_RUNNER_TOKEN`), or types it in their own terminal. Never write a token in a file, a commit, a note, a script argument or
   your answer; never put it in `AGENTS.md`, `.env` files committed to git, or a workflow. If a token appears in your context,
   do not repeat it.
4. **Give the user the exact commands** (they run them as Administrator in their terminal; you cannot elevate for them) and
   explain the reboot and "run it again" mechanics. Prefer scripts of `tools/runner/` to improvising.
5. Warn about host mode (no isolation) and suggest a throw-away VM; warn about the Windows licence for the VM kit and never download Windows
   from an unofficial source.
6. Do not touch runners that are not the user's (other people's, the maintainer's), do not delete runners in the forge unless asked.
7. When done, check "Idle" in the forge's runner list (or the script's summary) and tell the user how to remove the runner.

## 11. What was tested, and what was not

| Piece | Tested | How |
|---|---|---|
| `install-windows-runner.ps1` | yes | on a Windows 10 22H2 VM that already had the tools: registration, connection ("declared successfully"), re-run (idempotence), `-Uninstall`; on a freshly installed VM (the `windows-vm` kit): the whole install from nothing (see the README of `tools/runner/windows-vm`). PowerShell 5.1. |
| `windows-vm/*` | yes | `create-vm.sh` with a French Windows 10 Pro ISO on a Debian host with KVM, then `provision.sh`; `start-vm.sh`, `guest-exec.py` |
| `install-wsl-linux-runner.ps1` | **partly** | PowerShell syntax checked; the Linux parts (wsl.conf edit, Docker's apt repository and key fingerprint, the clone step) ran in an Ubuntu 24.04 container. **Not run on a real Windows with WSL**: enabling WSL, `wsl --install --no-launch`, the first-launch fallback, WSLENV token passing, `sg docker`, the scheduled task, `-RunAtBoot` are untested. Expect to fix small things; report them. |
| Windows 11, ARM64 Windows, Windows Server | no | |
| macOS runner | no script | |

## The maintainer's runners (an example)

The Linux runner of the JumPHP maintainer's PC is registered for the organization `jumphp` only; the engine and runtime releases are
built locally (`bin/jumphp engine:pack`, `runtime:build`) and published with `gh release create vX.Y.Z .build/jumphp-engine.tar.gz ...`
(`gh` = the forge's). The Windows runner `win10-vt2` is a Windows 10 VM (4 vCPU, 5 GB) on the same PC, set up exactly like section 3.
