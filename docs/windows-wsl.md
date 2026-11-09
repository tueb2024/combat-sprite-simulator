# Windows: Docker Engine inside WSL (without Docker Desktop)

JumPHP runs everything in Docker. On Windows, the usual way is Docker Desktop. Docker Engine installed **inside a WSL
distribution** works too: no Docker Desktop licence, often faster.

## Setting it up

1. WSL 2 with a distribution (PowerShell as administrator): `wsl --install -d Ubuntu`, then restart.
2. Inside the distribution: Docker Engine (https://docs.docker.com/engine/install/ubuntu/; or Ubuntu's own `sudo apt install docker.io
   docker-buildx`: **buildx is needed**, the images are built with `docker build --build-context`, and `bin/jumphp` says so when it is
   missing), then `sudo usermod -aG docker $USER`, close the WSL window, open it again.
3. Check from Windows: `wsl -e docker info` must answer (and `docker info` on Windows may not: that is the point).
   With systemd in WSL (the default on recent Ubuntu), Docker starts with the distribution; else
   `wsl -e sudo service docker start`.

## What JumPHP does

- **bin/jumphp**, started from Git for Windows' bash (or by the IDE through it): when Windows' `docker` does not answer
  but `wsl -e docker info` does, it runs itself again inside WSL (`tools/wsl.sh`): the project's folder as Linux sees
  it (`C:\Users\me\Proj` → `/mnt/c/Users/me/Proj`, `\\wsl$\Ubuntu\home\me\proj` → `/home/me/proj`), the arguments quoted
  as they were, the `JUMPHP_*` variables carried over (`WSLENV`).
- **The JumPHP IDE**: its Docker check finds WSL's Docker (state `wsl`, said once in a notification), its runs give
  `JUMPHP_DOCKER=wsl` to bin/jumphp (no second check), and without Git for Windows it starts them inside WSL directly.
- To choose yourself, in the project's `.env`: `JUMPHP_DOCKER=wsl` (always inside WSL) or `JUMPHP_DOCKER=native`
  (never: Docker Desktop).

## Keep the project in WSL's file system

A project on `C:\` is seen from WSL as `/mnt/c/...`: every file goes through the Windows file system bridge, and Docker
volumes from there are **slow** (composer install, builds, tests). Much faster: keep the project inside the
distribution, e.g. `/home/me/projects/my-game`, and open it from Windows as `\\wsl$\Ubuntu\home\me\projects\my-game`
(Explorer shows it under "Linux"). bin/jumphp handles both kinds of path.

## Limits

- Playing on the desktop (`bin/jumphp run`) then happens inside WSL: it needs WSLg (Windows 11, or Windows 10 with a
  recent WSL) for the window and the sound; without it, use the browser build (`bin/jumphp build` + `serve`).
- Tried with fake `docker` / `wsl` programs (ide/tests/WslTest.php, tools/tests/wsl.test.sh); on a real Windows with
  Docker inside WSL: not yet.
