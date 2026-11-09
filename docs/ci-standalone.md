# Build the PHP micro on your own runners (the `standalone` workflow)

[Français](ci-standalone.fr.md)

`bin/jumphp export:standalone` and `app:export` glue a game or an app onto a **micro**: PHP built statically (StaticPHP) with the
extensions JumPHP needs. The micros are built by the workflow `.github/workflows/standalone.yml` and published as the release
`standalone-<key>` (the key is a hash of PHP's version, the extension list, StaticPHP's commit and JumPHP's registry and patches:
`tools/standalone-key.sh`). A micro is therefore built **again only when one of those changes**. This page tells you how to run
that workflow on runners of your own (see [ci-runner.md](ci-runner.md) to install them), and what GitHub's machines gave you
for free that yours may not.

## Run it

Forge > the repository > Actions > *standalone* > *Run workflow*, or from a terminal (`gh` is the forge's one in a JumPHP project):

```bash
gh workflow run standalone.yml -f extensions=tools/app-extensions.txt -f macos=false -f linux=false
```

| Input | Values | |
|---|---|---|
| `extensions` | `tools/standalone-extensions.txt` (games: SDL, OpenGL, OpenAL), `tools/app-extensions.txt` (desktop apps, the IDE), `tools/hybrid-extensions.txt` | the micro made = its own key |
| `linux` | `true` / `false` | `false`: no Linux micro (see "Docker" below); `export:standalone` then builds the Linux one on the user's computer |
| `macos` | `true` / `false` | `false`: no Mac micro (desktop apps only); without a macOS runner the jobs would wait forever |

The Windows job takes about 10 minutes with the downloads cached, 30-60 the very first time. Its result:
`https://<forge>/<owner>/<repo>/releases/download/standalone-<key>/micro-windows-x86_64.sfx`.

## What GitHub's machines had, and yours may not

On GitHub the same workflow "just worked" because `windows-latest` and `ubuntu-latest` are images prepared by GitHub. A runner of your
own is a bare machine. Everything below is handled by the workflow, or told here:

| GitHub's image | A runner of your own | What the workflow / you do |
|---|---|---|
| PowerShell 7 (`pwsh`), PHP, Composer, Git | often only Windows PowerShell 5.1 and Git | the steps run in Windows PowerShell; PHP 8.4 and Composer are downloaded (php.net, getcomposer.org, hash checked) when missing; Git's `usr\bin` goes into the PATH |
| Windows in English | your Windows language (French, ...) | `VSLANG=1033` makes the Visual Studio tools speak English: PHP's `configure.js` reads the linker's output and stops with a JScript error otherwise |
| Visual Studio 2022 | **you install it**: *Build Tools 2022*, workload "Desktop development with C++" | the one thing a Windows runner must have (with Git for Windows). [install-windows-runner.ps1](../tools/runner/install-windows-runner.ps1) installs both |
| Fast, rarely rate-limited access to `api.github.com` | the same API answers `504` or "rate limit exceeded" | StaticPHP downloads are retried 4 times and kept in `%USERPROFILE%\.spc-downloads` between runs; Composer installs from sources (`--prefer-source`) |
| `actions/upload-artifact@v4`, `download-artifact@v4` | **Forgejo does not support them** ("not currently supported on GHES") | on a forge the Windows job publishes the micro itself to the release (the job's `github.token`, the forge API); the `release` job only runs on GitHub |
| Docker in the Linux job | the job runs in a container without Docker | see below |
| LF line endings | Git for Windows converts to CRLF | `git config --global core.autocrlf false` before cloning StaticPHP (a patch of its files matches their text) |

## Docker: where it is needed, where it is not

- The **Windows job builds natively** with Visual Studio. It does not use Docker and a Windows runner cannot run it for you (Linux containers
  on Windows need WSL2). Nothing to install for that.
- The **Linux job** runs `docker build docker/standalone` then `docker run` of StaticPHP. Inside a job container there is no Docker
  unless the runner gives it one: mount the host's (or the runner's dind) Docker socket into the job (`container.options` /
  `valid_volumes` in the runner's `config.yml`), or use the **WSL2 Linux runner** ([ci-runner.md](ci-runner.md) section 4), which is a
  real Linux with Docker. Without that, run with `linux=false`: `bin/jumphp export:standalone <id> --linux` builds the Linux micro
  on the computer that exports (about 15 minutes, then kept in `~/.jumphp/standalone/`).
- Whatever the runner, **never copy or rename a micro under another key**: it would be a PHP without the right extensions, and the
  executables of your users would crash (`Call to undefined function SDL_Init()`). If the key changed, the micro must be rebuilt.

## Troubleshooting

Job logs are read on the forge's page of the run (job > the red step); the forge's API does not return them.

| Symptom | Cause / fix |
|---|---|
| The job `key` fails in 30 s, right after "Fetching the repository" | the job's container cannot resolve the forge: the runner's Docker-in-Docker has no outside DNS. Give it some: `dns: [1.1.1.1, 9.9.9.9]` on the `dind` service of the runner's compose file. Test: `docker run --network <a new network> node:20-bookworm getent hosts <forge>` |
| `Cannot find: pwsh in PATH` | the workflow asks Windows PowerShell (`shell: powershell`); an old copy of the workflow asked `pwsh`. Update it |
| `configure.js ... Microsoft JScript runtime error` after "Checking for link.exe" | Visual Studio's tools print in the language of Windows. `VSLANG=1033` (in the workflow since this page) |
| `ERROR: bison is required` | seen after a **half-done earlier build** in the same folder: StaticPHP keeps the msys2 tools but does not put them in the PATH again. Delete the workspace's `spc/pkgroot`, `spc/source`, `spc/buildroot` and run again (a clean workspace, as a new job has, works) |
| A patch of StaticPHP "not patched (StaticPHP changed)" or "Failed patch module" | CRLF line endings (`core.autocrlf false` and clone again), or a source folder patched by an earlier attempt (delete `spc/source/php-src`) |
| `Failed to get GitHub release API info for madler/zlib` / `HTTP/2 504` | `api.github.com` is rate-limiting or failing for your address. The workflow retries; the first run on a new machine may need a second try. Downloads are kept, so each retry goes further |
| `GHESNotSupportedError: upload-artifact@v4 ... not currently supported` | you use Forgejo: use the workflow of this repository (it publishes without artifacts) |
| The macOS jobs stay "waiting" and the run never ends | no macOS runner: `-f macos=false` |
| `Cannot find path ...\buildroot\bin\micro.sfx` | the build failed before: read the lines above it (they are the cause) |

## For AI agents

You may be asked to "make the standalone workflow work on my runner". Read the failing step on the run's page (ask the user to paste it if
you cannot reach it), change the **workflow** (it must stay valid on GitHub too: test `github.server_url`), not the user's machine, unless
what is missing is a prerequisite above (Git, Visual Studio Build Tools, Docker for the Linux job): then tell the user and install it
only when they say yes. Never register a runner, never write a token in a file, never publish a micro that you did not build from the
current files with this workflow's own commands, never move a release under another key. After a successful run, check the asset
(`gh release view standalone-<key>`, a download answering 200) and, for a Windows micro, run a script with it in the runner's machine
(`copy /b micro.sfx + test.php test.exe`, then `test.exe`: PHP version, `extension_loaded('ffi')`...).
