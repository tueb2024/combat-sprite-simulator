# AGENTS.md — how to build games with JumPHP

You are working in **JumPHP**, a framework to write **retro 2D games in PHP**. The same PHP code runs:

- on the **desktop** (native PHP 8.5 + SDL2 / OpenGL / OpenAL extensions, in Docker, in a real window with sound),
- in the **browser** (the same PHP 8.5 + extensions compiled to WebAssembly: OpenGL = WebGL, OpenAL = Web Audio;
  the game is a static web page).

Everything (PHP, composer, emscripten, web server, relay server, tests) runs **in Docker** through one
command: `bin/jumphp`. Never install PHP, extensions, emscripten or node on the host; never run `php` or
`composer` directly — always `bin/jumphp ...`.

Read this whole file once before writing code. It is the contract: if you follow it, your game works on
both targets.

---

## 1. Map of the repository

```
bin/jumphp                 the CLI (bash). `bin/jumphp help`
AGENTS.md               this file
README.md, docs/        human documentation (French)
engine/                 the framework: composer package jumphp/engine, namespace JumPHP\  (engine/src)
  src/Kernel/           Application (boot), Engine (the frame), GameConfig, Platform
  src/Loop/             GameLoop (ReactPHP), FixedStep
  src/Renderer/         Sdl (textures), Renderer (drawing), Window, BitmapFont, TtfFont, TextDrawer, CrtFilter
  src/Input/            InputManager, ActionMap, Keyboard, Mouse, Touch, Gamepad
  src/Scene/            Scene, AbstractScene, SceneManager, Camera
  src/Entity/           Entity, GameObject, EntityManager, traits Sprite and Gravity
  src/Physics/          CollisionSystem (tiles), Aabb (boxes), TileCollision trait, CollisionResult
  src/Tile/             TileMap, TileSet, CollisionMask, TileMapRenderer, TiledJsonLoader, ArrayLoader, BinaryLoader
  src/Audio/            SoundManager, Synth (generated sounds), Audio3d (positional sound: OpenAL)
  src/Ui/               Menu, MenuScene, TextInput, Hud
  src/Net/              NetSession, LobbyScene, MessageCodec, SnapshotBuffer, Tcp / TcpConnection (§9), Ssh / SshConnection / SshConnector (§9), BridgeRoute / RuntimeLink (the JumPHP runtime, §9)
  src/Save/             Storage, Options
  src/Async/            Tasks, Pool, Task, TaskFailed, WebWorkers, Worker (threads / Web Workers / between frames)
  src/Bridge/           JsBridge (game <-> web page, synchronous with the frames)
  src/Device/           Lifecycle (hide/show/blur/close...), Device (phone features), Pwa (installable, offline)
  src/Gl/               OpenGl (raw PHP-OpenGL in SDL's context), WebGl (3D layer + post-processing), Mat4
  src/Http/             Http (async requests: fetch / curl / sockets), HttpResponse, HttpError
  src/Util/             Color, Math, Time, Lang, Vector
  src/Tauri/            desktop apps (§16): App, Window, AppMain, TauriRuntime (FFI), FakeRuntime, NativeCommands
  src/Native/           Dialog, FilePicker, Notification, Shell: the system's features, a provider per target (§16)
  src/Testing/          GameTestCase (games), AppTestCase (desktop apps, no window)
  src/Gui/              graphic apps without HTML (§17): Form (a scene + forms/*.form.yaml), Container (row, column, grid), widgets
  bootstrap/wasm.php    browser entry point
  web/                  the web page template (index.html, jumphp.js, jumphp-worker.js, jumphp-files.js (remote games), touch.js,
                        jumphp-device.js, jumphp-http.js, jumphp-pwa.js, jumphp-gamepad.js, jumphp-runtime.js, sw.js)
  stubs/sdl.stub.php    signatures of every SDL function / constant / class (read it to know the raw API)
  stubs/async.stub.php  the TrueAsync API used by JumPHP\Async
  stubs/opengl.stub.php, openal.stub.php  every gl* / openal_* function and constant (tools/gl-stubs.php)
  tests/                engine unit tests
skeleton/               template of a game, copied by `bin/jumphp new`
skeleton-app/           template of a desktop app (Tauri), copied by `bin/jumphp app:new` (§16)
skeleton-gui/           template of a graphic app (forms, JumPHP\Gui), copied by `bin/jumphp gui:new` (§17)
skeleton-cli/           template of a CLI app (terminal: Symfony Console + symfony/tui), copied by `bin/jumphp cli:new` (§18)
skeleton-plugin/        template of a plugin of the JumPHP IDE (PHP), copied by `bin/jumphp plugin:new` (§19)
skeleton-extension/     template of an engine extension + its example game, copied by `bin/jumphp extension:new` (§20)
extensions/<id>/        engine extensions (Composer type jumphp-extension; extensions/particles: particles), §20
tauri/                  the desktop apps' native runtime (Rust + Tauri 2, a C library PHP loads with FFI; §16)
ide/                    JumPHP IDE: package jumphp/ide, PHP core (JumPHP\Ide\) + HTML interface, shared by games/ide
                        (the desktop app) and jumphp.net/ide (docs/ide.md)
games/<id>/             the games (games/pong = the reference game: read it)
apps/<id>/              the other projects: apps, CLI apps, IDE plugins (made by app:new / gui:new / cli:new /
                        plugin:new; older projects keep them in games/, every command finds both)
docker/                 engine-wasm (WebAssembly build), ssh-wasm (OpenSSH's ssh client as WebAssembly: bin/jumphp ssh:build, docs/ssh-wasm.md), desktop (runtime), desktop-async (PHP 8.6 ZTS + TrueAsync),
                        relay (network server), web (FrankenPHP), ext (php-opengl.patch, jumphp-xvfb: shared by them),
                        server (bin/jumphp deploy: FrankenPHP alone / Traefik + FrankenPHP / existing Traefik)
tools/                  build scripts used by bin/jumphp; init.sh (the wizard), post-create.sh (composer create-project)
build/engine/           the compiled WebAssembly engine (bin/jumphp engine:build), shared by all games
```

A game folder:

```
games/<id>/
  app.php               desktop entry point (do not change)
  composer.json         namespace App\ -> src/, requires jumphp/engine (path ../../engine)
  config/project.yaml      id, title, resolution, first scene, smoke test...  (older projects: config/game.yaml under game:, still read)
  config/services.yaml  dependency injection (every class of src/ is autowired, except src/Entity)
  config/input.yaml     actions -> keys / pad / touch
  config/lang.yaml      translations
  src/Scene/*.php       the screens (TitleScene, PlayScene, ...)
  src/Entity/*.php      the things that move (created with `new`)
  assets/               sprites/, tiles/, maps/, sfx/, music/, fonts/, icon.png, thumbnail.png
  var/                  player files (saves, options) — never commit, never read at build time
  site/                 (optional) the game's web site, deployed at the root with the game in /play/
  dist/web/             browser build output (bin/jumphp build)
  AGENTS.md             notes about this game
  BRIEF.md              (projects made by bin/jumphp init) what the game should be: the prompt the user wrote
```

---

## 2. Golden rules (read twice)

1. **Docker only**: `bin/jumphp ...` for everything (see §3).
2. **Never block the frame.** No `sleep()`, `usleep()`, `while (true)`, `readline()`, `fgets(STDIN)`,
   blocking sockets, long loops. The browser calls the engine once per frame; a blocking call freezes the tab.
   Time-based things: count `$dt` in `update()`, or `GameLoop::addTimer($seconds, fn () => ...)`.
3. **32-bit integers in the browser** (`PHP_INT_SIZE === 4`, max 2147483647):
   - no `(int)(microtime(true) * 1000)`, no `hrtime(true)` as int, no `time() * 1000`: use `JumPHP\Util\Time::now()` (float seconds) or `$this->engine->time()`.
   - no colour integers like `0xFF000000`: use `[r, g, b, a]` arrays, `'#rrggbb'` strings or `Color`.
   - no `pack('q'/'J'/'P')`: use `C c v s V g` (see MessageCodec).
   - big counters (score > 2 billion) are floats.
4. **Network only through `JumPHP\Net`** (`$this->engine->net()`, multiplayer; `$this->engine->tcp()`, TCP, §9) and **`JumPHP\Http`**
   (`$this->engine->http()`, web APIs). No `socket_*`, `fsockopen`, `stream_socket_client`, curl or
   `file_get_contents('http...')`: they do not exist in the browser (and they block the frame).
5. **Files**: read game files with paths relative to the game root through `$this->engine->config->path('assets/...')`
   (or relative paths given to Sdl / SoundManager / loaders, which resolve them). Write only into `var/`
   through `$this->engine->storage` / `$this->engine->options` (persisted in IndexedDB in the browser).
   `sys_get_temp_dir()` is fine for temporary files.
6. **SDL limits** (the binding): no `SDL_SetTextureBlendMode`, `SDL_SetRenderDrawBlendMode`,
   `SDL_SetTextureColorMod`, `SDL_SetTextureAlphaMod`, `SDL_GameController*`. Translucency: give a colour with
   alpha to `Renderer::fillRect()` (handled), or use PNGs with alpha. Tinting: pre-coloured images (or
   `createTextureFromPixels` with another palette). Textures ≤ 4064 px wide (Sdl slices wider images itself).
7. **Draw only in `render()`, change state only in `update()`.** Coordinates are virtual pixels
   (`project.yaml` width × height); the engine scales to the window.
8. **Scenes are reused** (one instance per class, from the container): reset ALL state in `enter()`.
9. **No `declare(strict_types=1)`** required, PHP 8.2 syntax max (the desktop runs 8.5, the browser 8.5).
   Code, identifiers and comments in English. Follow the style of `games/pong`.
10. **Forbidden words**: `bin/jumphp lint` fails if the repository contains certain brand names
    (`tools/check-forbidden-words.sh`). Do not name games, classes or files after existing commercial games.
11. **Always verify** before saying you are done (§10): `bin/jumphp test <game>` must pass, and look at a
    screenshot (`bin/jumphp shot <game>`).

12. **Keep a history of what you do** (the JumPHP IDE does it by itself for its Claude / OpenCode window; do it yourself when you
    are launched any other way, e.g. Claude Code in a terminal), **at the root of the project, in `IA/HISTORY/`**, so that any AI can walk
    through the whole folder and know what happened:
    - `IA/HISTORY/INDEX.md`: one line per turn, oldest first (turn number, date, assistant, model, number of actions, result, request, folder);
      the turn numbers only grow (`IA/HISTORY/.seq` holds the last one: 0001, 0002...);
    - per turn, in `IA/HISTORY/<CLAUDE|OPEN CODE>/<model>/<dd-mm-YYYY HH-MM-SS>/` (no `:`, Windows refuses it):
      `<turn>-<request>.md` (every message, the user's and yours, **with its date and time**) and a **separate file**
      `<turn>-<request>.actions.md` with **every command / tool you ran, numbered in order** (`0007-001`, `0007-002`...), each with its
      start and end time, the command itself, its **result** (the end of it when it is long) and whether it worked.
    `IA/` is **never sent to git**: make sure `/IA/` is in the project's `.gitignore`, and tell the user once that this history lives on
    their computer only and is lost if the folder or the computer is.

13. **Never move, delete or re-create a published tag** (`v*` of JumPHP and of the extension repositories `jumphp-<id>`): Packagist
    freezes a stable version's commit; a tag that points elsewhere later makes it refuse the update and mail the maintainer
    (happened 2026-11-07 after the move to git.jumphp.net: `v0.5.6` of shorts / physics / particles, several `jumphp` tags).
    No `git tag -f`, no `git push --force` of a tag, no delete + re-tag, no history rewrite under a tag. A mistake in a release =
    a **new version** (`v0.5.N+1`). Before bumping, `git ls-remote --tags` and compare with
    `https://packagist.org/packages/jumphp/<id>.json`. If a tag was moved anyway, restore it to the reference Packagist shows.

---

## 3. Commands

| Command | What it does |
|---|---|
| `bin/jumphp setup [async]` | builds the Docker images (desktop runtime, relay; `async`: also the PHP 8.6 ZTS + TrueAsync runtime, ~15 min). Done automatically when missing. |
| `bin/jumphp init` | interactive project wizard for humans (tools, game, engine, server, GitHub, Claude Code); `bin/jumphp` alone runs it while `.env` has no `JUMPHP_GAME`. Agents: do not run it (it asks questions) |
| `bin/jumphp engine:download` | downloads the prebuilt WebAssembly engine of this version (release of git.jumphp.net) into `build/engine/` |
| `bin/jumphp engine:build` | builds the WebAssembly engine into `build/engine/` (~25 min the first time, cached). Needed once for browser builds (or `engine:download`). |
| `bin/jumphp new <id> ["Title"]` | creates `games/<id>` from `skeleton/` (id: `a-z0-9-`) |
| `bin/jumphp install <id>` | composer install of the game |
| `bin/jumphp composer <id> require vendor/pkg` | composer (pure-PHP packages only: no extension exists in the browser) |
| `bin/jumphp run <id>` | plays on the desktop (window + sound). Needs a display; not usable by an agent without a screen. Without `<id>`: the folder of `games/` or `apps/` it is typed in, else `JUMPHP_APP` (or the older `JUMPHP_GAME`, .env), else the only project |
| `bin/jumphp test <id>` | `php -l`, phpstan (level 5, if `phpstan.neon` and `bin/jumphp test engine` ran once), phpunit (`tests/` if any, game tests included), **headless smoke test** (600 frames, any PHP warning = failure) |
| `bin/jumphp shot <id> [frames] [out.png]` | **headless screenshot**: runs N frames (default 120) and saves a PNG in `games/<id>/var/screenshots/` — open it to SEE the game |
| `bin/jumphp build <id>` | browser build → `games/<id>/dist/web/` (static files) |
| `bin/jumphp build:remote <id>` | the game as a **remote game** (`dist/remote/`: shared engine + files one by one + manifest, what a games site serves, docs/remote.md); `JUMPHP_WEB=remote bin/jumphp serve/webshot <id>` tries it |
| `bin/jumphp runtime:build` / `runtime:download [version]` | the shared engine of remote games → `build/runtime/` (+ the release asset `.build/jumphp-runtime.tar.gz`) |
| `bin/jumphp serve <id> [port]` | serves the browser build on http://localhost:8080 |
| `bin/jumphp webshot <id> [seconds] [keys]` | headless Chrome: loads the browser build, presses keys, screenshot `dist/webshot.png`, fails on JS / PHP errors |
| `bin/jumphp relay up\|down\|logs` | multiplayer relay server on `ws://localhost:8090/ws` |
| `bin/jumphp export:desktop <id>` | a Docker image that runs the game |
| `bin/jumphp export:standalone <id> [--linux] [--windows]` | the game as one executable for players (dist/standalone/; docs/standalone.md) |
| `bin/jumphp installer <id> --github \| --url https://site/folder/ \| --standalone [--windows] [--linux] [--macos] [--version X]` | an **installer** per system (jumphp2installer: shortcuts, uninstaller, always installs the newest version): `--github` = a release `v<version>` of the project's repository (gh, public repo), `--url` = `dist/installer/` to upload at that address, `--standalone` = the game inside each installer (`dist/installer/`: no site, no network, installs that version, never updates). Builds Windows / Linux first (`--no-build`: the existing ones); macOS: an existing build. jumphp.net: the IDE (Run > Create an Installer…). Publishing: only when asked |
| `bin/jumphp gui:new <id> ["Title"] [--mobile]` | a **graphic app** (forms made in the IDE's visual editor, `JumPHP\Gui`, no HTML; §17); then run / test / shot / build / export as a game |
| `bin/jumphp cli:new <id> ["Title"]` | a **CLI app** (commands + full-screen text screens in the terminal, §18); then run / test / shot (the screen as text) / export:standalone as a game |
| `bin/jumphp plugin:new <id> ["Title"]` / `plugin:pack <id>` | a **plugin of the JumPHP IDE** in PHP (§19); `test` on its folder; `pack` = `dist/<id>-<version>.zip` |
| `bin/jumphp extension:new <id> ["Title"]` | an **engine extension** (§20): the package `extensions/<id>` + its example game `games/<id>-demo` (install / test / shot / build it) |
| `bin/jumphp video <id> <film.mp4> [name] [fps]` | a film as frames + sound for the `video` widget (`assets/videos/<name>/`) |
| `bin/jumphp app:new / app:run / app:test / app:shot / app:export <id>` | **desktop apps** (HTML pages + PHP, Tauri; §16, docs/tauri.md). `run` / `test` / `shot` on an app's folder hand over to them; `app:export --windows --macos`; `app:release` (updates); `build` = the app in the browser |
| `bin/jumphp tauri:setup` / `tauri:build` / `tauri:download` | the Tauri image / the runtime library `build/tauri/linux-x86_64/libjumphp_tauri.so`, built or downloaded (done automatically when needed) |
| `bin/jumphp apple <id>` (`ios <id>`, `macos <id>`) | the game as native Apple apps, built and tried on GitHub's Macs: .dmg (Mac), .ipa (iPhone / iPad), the simulator's app, screenshots (§14, "Apple apps") |
| `bin/jumphp deploy [id...]` | to the project's server (`.env` `JUMPHP_SERVER`, set by `deploy:setup`): builds, rsync, `docker compose up` (FrankenPHP, or Traefik + FrankenPHP), a game with its own web site (`games/<id>/site/index.html`) gets it at the root and the game in `/play/` (`{{DOMAIN}}` replaced in its pages), then checks that `https://<domain>/` answers (shows the server logs if not). Behind a Traefik already on the server: `bin/jumphp server:traefik` prints its entry points and resolver (`JUMPHP_SERVER_MODE` `traefik-external`, or `traefik-passthrough` when it has no working resolver). Without a server: rsync `dist/web` to `$JUMPHP_DEPLOY_TARGET/<id>/`. Publishing: only when asked |
| `bin/jumphp runner:install` / `runner:scripts [dir]` | your own CI runner (docs/ci-runner.md): Docker runner for Linux/macOS/VPS; `tools/runner/install-windows-runner.ps1` (native Windows runner), `install-wsl-linux-runner.ps1` (Linux runner in WSL2), `windows-vm/` (headless Windows VM on Linux); one user/org scope only, tokens from the environment, ask the user first |
| `bin/jumphp update` | merges the newest JumPHP (git remote `jumphp`) into the project |
| `bin/jumphp cli:install` / `cli:uninstall` | the `jumphp` command for the whole system (`~/.local/bin/jumphp`, `tools/jumphp-cli`): `jumphp <command>` from any folder of a project, `jumphp new <folder>`, and once a day a question when Packagist has a newer JumPHP (`bin/jumphp update` on a yes) |
| `bin/jumphp twa <id> --url <page>` | the game as an Android app for Google Play (§14, "Android app") |
| `bin/jumphp pages [id...]` | builds the games (default: all) + a home page from their `game.json`, force-pushes them as the `gh-pages` branch of the git remote (GitHub Pages: `https://<owner>.github.io/<repo>/`). Publishing: only when asked |
| `bin/jumphp debug` | the IDE's debugger as an MCP server for Claude Code in a terminal (`claude mcp add jumphp-debug -- bin/jumphp debug`): breakpoints / exceptions, then run the program yourself with `JUMPHP_XDEBUG=9003`, then `debug_wait`, `debug_step`, `debug_eval`, `debug_set_value`... |
| `bin/jumphp ssh:build` | OpenSSH's `ssh` client compiled to WebAssembly (Docker, a few minutes) -> `build/ssh/ssh.js` + `ssh.wasm`, packed in the runtime of remote games and in the web builds (`runtime:build` does it when missing) |
| `bin/jumphp test engine` | engine unit tests + phpstan |
| `bin/jumphp lint` | php -l everywhere + forbidden words |
| `bin/jumphp php <dir> <args>` | php in the runtime image (from a repo-relative dir) |

**A new project** (you ran `composer create-project jumphp/jumphp <folder>` or cloned one for the user): ask the
user once whether to install the `jumphp` command for the whole system (`bin/jumphp cli:install`: then
`jumphp test <id>` works from any folder, `jumphp new` makes other projects, and they are told when a new
JumPHP is out, updating only if they say yes). Install it only on a yes; it changes their `~/.local/bin` (and,
if they agree in the terminal, their shell profile).

**JumPHP's address is `https://git.jumphp.net/jumphp/jumPhp`** (Forgejo; the old GitHub account is gone). Never use, add or
write `github.com/davidnurdin/...` (no `github` remote, no link, no clone URL): in this repository `origin` is
`https://git.jumphp.net/jumphp/jumPhp.git`, the extensions are `https://git.jumphp.net/jumphp/jumphp-<id>.git`, releases are at
`https://git.jumphp.net/jumphp/jumPhp/releases/download/<tag>/<file>` (not GitHub's `/releases/latest/download/`). `gh` is
`tools/bin/gh` (it talks to the forge). Details: docs/migration-forge.md.

**Which Git host builds what** (a project's `origin` is github.com or a forge such as git.jumphp.net; `gh` talks to both):
`android`, `installer --github`, `standalone` and the other workflows run on the Actions runners of the project's host (a forge:
its Forgejo runners, docs/ci-runner.md; none is shared, register your own). `apple` / `ios` / `macos` need a **macOS runner**:
only GitHub has them, so on a forge `bin/jumphp apple` stops with an explanation (own Mac runner: `JUMPHP_MAC_RUNNER=1`). `pages`
makes the `gh-pages` branch: GitHub Pages serves it; a forge does not, publish on the JumPHP playground (§14) instead.

**The project's own repository**: a project is the user's git repository, remote `origin` on their Git host (the IDE's New
Project creates it through jumphp.net for every kind; `bin/jumphp init` with `gh`). The remote `jumphp` is JumPHP itself:
it only gives updates (`bin/jumphp update`) and cannot be pushed to. Never push, make a release or publish to JumPHP's
repository from a project: `bin/jumphp installer --github`, `android`, `apple`, `pages` refuse it. A project without
`origin`: ask the user (name, public / private) before `gh repo create <name> --source . --remote origin --push`.

Headless runs (`test`, `shot`) use a **fresh temporary `var/`** (options and saves start from the defaults,
the player's files are never touched; `JUMPHP_VAR=<dir>` forces a folder). They are driven by environment variables:

| Variable | Effect |
|---|---|
| `JUMPHP_SCENE='App\Scene\PlayScene'` | first scene (instead of `start_scene`) |
| `JUMPHP_SCENE_PARAMS='{"mode":"cpu"}'` | its `enter()` params (JSON) |
| `JUMPHP_INPUT='[[10,"confirm"],[30,"left","hold"],[90,"left","release"]]'` | scripted actions: `[frame, action]` = one press, `"hold"` / `"release"` |
| `JUMPHP_MAX_FRAMES=600` | how many frames (60 frames = 1 s of game time, fixed step) |
| `JUMPHP_SHOT_EVERY=30` | `shot`: also save one PNG every N frames (`shot-00030.png`...; older ones are deleted first) |
| `JUMPHP_REALTIME=1` | headless frames in real time (network tests between two processes) |
| `JUMPHP_GL=0` / `1` | headless OpenGL off / on (default: on when the game's code calls `gl()` / `opengl()`: real window on Xvfb, Mesa) |
| `JUMPHP_DEBUG=1` | FPS / scene overlay (F3 toggles it too) |
| `JUMPHP_XDEBUG=<port>` | step debugging: PHP runs with Xdebug (desktop image + Xdebug, built once) and connects to a debugger listening on that port of this computer (the JumPHP IDE's Debug sets it); composer, phpstan and `php -l` run without it |
| `JUMPHP_DEBUGGER=xdebug\|php-debugger` | the engine for `JUMPHP_XDEBUG` (default `xdebug`): `php-debugger` = [PHP Debugger](https://php-debugger.dev) (DBGp like Xdebug, near-zero overhead, PHP 8.2-8.5: the async runtime keeps Xdebug; image `docker/desktop-phpdebugger`, built once). The IDE: Debug with… (right click on the Debug button) picks it per run; `bin/jumphp debug` then starts PHP Debugger's own MCP server (`dbgp mcp`, `dbgp` installed: `claude mcp add dbgp -- dbgp mcp`) |

**Which project, what kind, what starts** (the same for a game, a desktop app, a graphic app, a CLI app, a plugin):
- which project: its folder name, `games/<id>` or `apps/<id>`, given to the commands; without it: the folder of `games/` / `apps/`
  the command is typed in, else `JUMPHP_APP` (`JUMPHP_GAME` is read too, `.env`), else the only project;
- what kind: `config/project.yaml` (older projects: `config/game.yaml`) `kind:` (`gui`, `cli`, `plugin`; a game by default), or
  `ui: webview` / `ui: hybrid` (desktop apps);
- what starts: a game: `start_scene:`; a desktop app: `main: App\Main` (its `boot()`); a CLI app: `app.php` calls `App\Main::boot()`.
  The PHP entry is always `app.php` on the desktop, `engine/bootstrap/wasm.php` in the browser (it reads the same project.yaml),
  `tools/standalone-stub.php` in the one-file executables (it loads `app.php`);
- the names that say "project": `JumPHP\Kernel\ProjectConfig` and `JumPHP\Testing\ProjectTestCase` are `GameConfig` and `GameTestCase`
  under another name (both work forever).

Three key-name formats, do not mix them:

| Where | Format | Examples |
|---|---|---|
| `config/input.yaml` | SDL key names, `padN:`, `touch:`, `mouse:` | `Left`, `Space`, `Return`, `Left Shift`, `pad0:a`, `pad0:start` |
| `JUMPHP_INPUT` (headless) | **action names** from input.yaml | `confirm`, `left`, `fire` |
| `bin/jumphp webshot` keys | browser `KeyboardEvent.key` names | `Enter`, `ArrowLeft`, ` ` (space), `Escape`, `a` |

Tip: for tests and shots, add test-only `enter()` params to your scenes (e.g. `{"level":3,"lives":1}`) and
pass them with `JUMPHP_SCENE_PARAMS`, rather than scripting long input sequences.

Examples:

```bash
bin/jumphp shot mygame 60                                     # the title screen after 1 s
JUMPHP_INPUT='[[5,"confirm"]]' bin/jumphp shot mygame 180 var/screenshots/play.png   # press confirm, 3 s later
JUMPHP_SCENE='App\Scene\PlayScene' bin/jumphp shot mygame 300
```

---

## 4. Create a game, step by step

```bash
bin/jumphp new breakout "BREAKOUT"
bin/jumphp install breakout
bin/jumphp test breakout          # the skeleton passes as is
bin/jumphp shot breakout 60       # look at games/breakout/var/screenshots/shot.png
```

The skeleton is a tiny platformer (title menu, options, a level with slopes, a player, coins, a pause menu).
Replace its scenes and entities with your game:

1. **`config/project.yaml`** (all keys under `project:`; every one is optional):

   | Key | Default | |
   |---|---|---|
   | `id`, `title`, `version`, `description` | | identity (page title, `game.json` for the portal) |
   | `descriptions` | {} | the description per language, `{fr: '…', en: '…', es: '…'}`: the playground shows the visitor's. Its languages: `fr en es de it pt nl pl ru uk tr ja zh ko` (fill them all, at least fr and en) |
   | `width`, `height` | 320, 180 | virtual resolution (classic: 320×180, 256×224, 256×192, 320×240) |
   | `scale` | 3 | desktop window = width × scale |
   | `integer_scale` | false | scale by whole factors only (sharper, bigger black borders) |
   | `hd` | 1 | `auto` (or 2..4): the 2D is drawn at the window's resolution, its coordinates unchanged: sharp TTF text (rendered at the real size), round circles, positions at fractions of a pixel; 1 = retro pixels |
   | `fullscreen`, `vsync`, `max_fps` | false, true, 120 | desktop window |
   | `background` | [0,0,0] | letterbox colour |
   | `start_scene`, `start_params` | | first scene class and its `enter()` params |
   | `lang` | en | default language (`config/lang.yaml`) |
   | `net_url` | ws://localhost:8090/ws | relay server |
   | `net_mode` | auto | browser TCP / SSH / UDP / ICMP: `auto` (the user's own network through the JumPHP runtime when it is `ready`, else the server's bridge), `server` (always the server's), `local` (the runtime, or the error "the JumPHP runtime is not available"); env / page `JUMPHP_NET_MODE`; needs the `jumphp/runtime` extension for anything but the server (§9) |
   | `smoke` | | `{scene: ..., params: {...}}` run 600 frames by `bin/jumphp test`: make it a self-playing demo |
   | `online` | false | the game has online play (shown by the portal) |
   | `touch` | | phone pad keys: `{a: Space, b: Escape, start: Return}`; optional `pad: stick` (floating joystick, 8 directions) or `pad: none` (no pad, no buttons: games played by tapping, the touches are mouse clicks), `select: P` (4th button), `labels: {a: JUMP, ..., rotate: TEXT}`; phones go fullscreen + landscape at the first tap (`fullscreen: false` to disable) |
   | `controls` | | one line of help text shown by the web page / portal |
   | `runtime` | default | `async`: desktop runs on PHP 8.6 ZTS + TrueAsync (real threads, §9 Threads) |
   | `pause_scene` | | pushed when the tab is hidden / the window loses the focus (not over menus) |
   | `pause_on_blur`, `mute_when_hidden` | true, true | |
   | `pwa` | false | `true` or `{name, short_name, display, orientation, theme_color, background_color}`: installable, plays offline (§9) |
   | `debug_key`, `fullscreen_key` | F3, F11 | |
   | `double_click_fullscreen` | false | browser: `true` = a double click on the game goes fullscreen (never in apps). Default off: the FULLSCREEN button / playground bar / F11 do it, and double clicks stay free for lists and columns |
2. **`config/input.yaml`**: your actions (`jump`, `fire`, `p1_up`...). The engine already defines
   `up down left right confirm back pause`.
3. **Scenes** in `src/Scene/` (§5): a `TitleScene extends MenuScene`, a `PlayScene extends AbstractScene`,
   overlays (`PauseScene`, `GameOverScene`) with `isOverlay(): true`.
4. **Entities** in `src/Entity/` (§6), created with `new` by the scenes.
5. **Assets** (optional): PNG sprite sheets in `assets/sprites/`, tilesets in `assets/tiles/`, Tiled maps
   (`.tmj`) in `assets/maps/`, sounds (`.wav`, `.ogg`) in `assets/sfx/` and `assets/music/`, TTF fonts in
   `assets/fonts/`, `assets/icon.png` (page icon), `assets/thumbnail.png` (games portal). **No asset is
   required**: draw with rectangles, `createTextureFromPixels()` (pixel art in code), GD, the built-in
   `BitmapFont`, and `Synth` sounds.
6. Replace the skeleton leftovers: `description` in project.yaml, `README.md`, `AGENTS.md`, unused `lang.yaml`
   entries, `src/Entity/Coin.php`...
7. **Game tests as you go** (§10 "Game tests"): each mechanic you write gets a test that plays it, run at once
   with `bin/jumphp test <id>` (the user watches them play); `bin/jumphp shot <id>`; iterate.
8. Browser: `bin/jumphp engine:build` (once per machine), `bin/jumphp build <id>`, `bin/jumphp webshot <id>`.

---

## 5. Scenes

```php
<?php

namespace App\Scene;

use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

final class PlayScene extends AbstractScene
{
    private float $x = 0;

    // constructor: services only (autowired), e.g. TileMapRenderer, CollisionSystem, EntityManager
    public function enter(array $params = []): void
    {
        $this->x = 0;                          // reset everything: the instance is reused
    }

    public function update(float $dt): void    // $dt in seconds (variable; ~0.016)
    {
        if ($this->actions()->isPressed('pause')) {
            $this->scenes()->push(PauseScene::class);
            return;
        }
        $this->x += $this->actions()->axis('left', 'right') * 100 * $dt;
    }

    public function render(Renderer $r): void
    {
        $r->clear([0, 0, 40]);
        $r->fillRect($this->x, 80, 16, 16, '#ffcc00');
        $this->font()->draw($r, 'HELLO', $this->width() / 2, 20, 2, [255, 255, 255], 'center');
    }
}
```

`AbstractScene` helpers: `$this->engine`, `actions()`, `scenes()`, `sound()`, `sdl()`, `font()`,
`width()`, `height()`. Lifecycle: `enter($params)`, `exit()`, `pause()` (something pushed over it),
`resume($result)` (it was popped), `update($dt)`, `render($r)`, `isOverlay()`.

SceneManager (`$this->scenes()`):

| Call | Effect |
|---|---|
| `switchTo(Class::class, $params = [], $fade = true)` | replaces the whole stack (black fade) |
| `push(Class::class, $params = [], $fade = false)` | over the current one (paused) |
| `pop($result = null)` | back to the one below (`resume($result)`) |
| `current()` | the scene on top |

Only the top scene is updated. Overlays (`isOverlay() === true`) are drawn over the scene below.

**Escape is bound to both `pause` and `back`.** In a pause menu (a `MenuScene` overlay), `back()` already
fires on Escape; to also resume with the other pause keys (P, Start) without popping twice:

```php
public function update(float $dt): void
{
    if ($this->actions()->isPressed('pause') && !$this->actions()->isPressed('back')) {
        $this->scenes()->pop();
        return;
    }
    parent::update($dt);
}
```

**Menus**: extend `JumPHP\Ui\MenuScene`, set `$title`, return rows from `items()`:

```php
final class TitleScene extends MenuScene
{
    protected string $title = 'MY GAME';

    protected function items(): array
    {
        return [
            // [label, value, onPress, onSide]
            ['PLAY', null, fn () => $this->scenes()->switchTo(PlayScene::class, ['level' => 1])],
            ['VOLUME', fn () => round($this->sound()->getSfxVolume() * 100) . '%', null,
                fn (int $dir) => $this->sound()->setSfxVolume($this->sound()->getSfxVolume() + $dir / 10)],
            ['QUIT', null, fn () => $this->engine->quit()],
        ];
    }

    protected function back(): void { }        // Escape; sub-menus: $this->scenes()->pop();
}
```

Labels and the `$title` are translated with `config/lang.yaml`. Call `$this->refresh()` after changing a value shown in a
label. Override `renderBackground(Renderer $r)` for a custom background; `$titleScale`, `$menuTop`,
`$background`, `$titleColor` customise it. Menu sounds: define sfx named `menu_move` and `menu_confirm`.
Hide `QUIT` in the browser: `if (Platform::isDesktop())`.

**Hot reload** (the playground's online editor and sandbox, ⚡ Live): a file saved goes into the running game
without reloading the page (`engine/src/Debug/HotReload.php`). Pictures, sounds, `lang.yaml`, `input.yaml`:
replaced in place. Another data file: the current scene is entered again with its parameters. PHP code,
`project.yaml`, `services.yaml`, `composer.json`: PHP restarts in the same WebAssembly engine and boots straight
into the scene that was playing. Its fields are then fresh; a scene whose state is worth keeping while one
tunes the game (the room, the score, the player's position) implements `JumPHP\Scene\HotReloadable`:

```php
final class PlayScene extends AbstractScene implements HotReloadable
{
    public function hotState(): array { return ['room' => $this->room, 'score' => $this->score, 'lives' => $this->lives]; }
    public function hotRestore(array $state): void { ['room' => $this->room, 'score' => $this->score, 'lives' => $this->lives] = $state; }  // after enter()
}
```

Plain values only (they cross from one version of the code to the next). Do it for the main play scene of a
game; it changes nothing outside hot reload. `docs/hot-reload.md` has the details.

---

## 6. Entities, sprites, animation

`JumPHP\Entity\Entity` has **protected** fields `x`, `y` (top-left, float), `vx`, `vy`, `ax`, `ay`, `friction`,
`width`, `height`, `facing` ('left'/'right'), `state` (string): use them as `$this->x` inside the entity
class, and through accessors from outside. **Public** fields: `active`, `visible`, `dead`, `alwaysActive`.
Accessors: `setX()/setY()/setXY()`, `setSpeedX()/setSpeedY()`, `getSpeedX()/getSpeedY()`, `getSpeed()` (length),
`setAcceleration()`, `setFriction()`, `setFacing()/getFacing()`, `setState()/getState()`,
`setSize()`, `getWidth()/getHeight()`.
Methods: `getX()/getY()` (rounded ints), `getFloatX()/getFloatY()`, `setVelocity()`,
`getVelocity()` (`[vx, vy]`), `getBoundingBox()` (`['x','y','width','height']`), `centerX()`, `centerY()`,
`intersects($rect)`, `kill()`, `update($dt)` (integrates velocity, picks the animation `<state><Facing>`),
`render(Renderer $r, $camX, $camY)` (default: magenta box). It is an event emitter (`on()`, `emit()`).

`GameObject extends Entity`: `type`, `name`, `properties` (from the map editor), `property($name)`,
constructor `($x, $y, $width, $height, $type, $name, $properties)` — what `EntityManager::spawn()` calls.

Traits (compose them; the class's own `update()` calls the aliased ones):

```php
final class Hero extends Entity
{
    use Sprite, Gravity, TileCollision {
        Sprite::update as updateSprite;
        Gravity::update as updateGravity;
    }

    protected array $animations = [
        'idleRight' => ['coords' => [['x' => 0, 'y' => 0, 'w' => 16, 'h' => 16]]],
        'walkRight' => ['coords' => [['x' => 16, 'y' => 0, 'w' => 16, 'h' => 16], ['x' => 32, 'y' => 0, 'w' => 16, 'h' => 16]], 'duration' => 0.1],
        'idleLeft'  => 'reverseIdleRight',     // same frames, flipped
        'walkLeft'  => 'reverseWalkRight',
        'dieRight'  => ['coords' => [...], 'loop' => false],   // isAnimationDone() at the end
    ];

    public function __construct(Sdl $sdl, float $x, float $y)
    {
        $this->x = $x; $this->y = $y; $this->width = 12; $this->height = 16;
        $this->gravity = 900; $this->maxFallSpeed = 500;
        $sdl->hasTexture('hero') || $sdl->loadTexture('hero', 'assets/sprites/hero.png');
        $this->initSprite($sdl, 'hero', 'idleRight');
    }

    public function update(float $dt): void
    {
        $this->updateGravity($dt);             // vy += gravity
        $this->state = abs($this->vx) > 5 ? 'walk' : 'idle';
        $this->pickAnimation();                // "<state><Facing>"
        $this->updateSprite($dt);              // next frame
        // the scene then calls $collisions->move($hero, $map, $dt) to move against the tiles
    }

    public function render(Renderer $r, float $camX = 0, float $camY = 0): void
    {
        $this->drawAt($r, $this->x - $camX, $this->y - $camY);
    }
}
```

- `Sprite`: `initSprite($sdl, $textureName, $firstAnimation)`, `setAnimation()`, `hasAnimation()`,
  `getAnimation()`, `isAnimationDone()`, `drawAt($r, $x, $y, $forceFlip = false, $scale = 1)`.
  Animation keys: `coords` (frames in the sheet), `duration` (s per frame, default 0.1), `loop` (default true),
  `offset` ([dx, dy] drawing offset), `flags.flip`.
- `Gravity`: `$gravity` (px/s², default 900), `$maxFallSpeed`, `setGravity()`, `enableGravity(false)`.
- `TileCollision`: `$onGround`, `$onCeiling`, `$onWallLeft`, `$onWallRight`, `$groundGid` (filled by CollisionSystem).

`EntityManager` (inject it in the scene constructor; one per scene):
`register('coin', Coin::class)` or `register('enemy', fn (array $o) => new Enemy(...))`,
`spawnFromMap($map)`, `add($e)`, `remove($e)`, `clear()`, `update($dt, $camera)` (entities far from the
camera wait), `render($r, $camera)`, `all()`, `ofClass(Enemy::class)`, `overlapping($rect, $except)`,
`count()`. Dead entities (`kill()`) are removed automatically.

---

## 7. Drawing, textures, text

`Renderer $r` (in `render()`), view coordinates:

| Call | |
|---|---|
| `clear($color)` | fills the view |
| `fillRect($x, $y, $w, $h, $color)` | colour with alpha < 255 = translucent (works without blend modes) |
| `drawRect($x, $y, $w, $h, $color, $thickness = 1)` | outline |
| `drawLine($x1, $y1, $x2, $y2, $color)`, `drawPoint($x, $y, $color)` | |
| `fillCircle($cx, $cy, $radius, $color)` | |
| `copy($texture, $src = [x,y,w,h] or null, $x, $y, $w = null, $h = null, $flipH = false, $flipV = false, $angle = 0)` | draws a texture |
| `getWidth()`, `getHeight()` | view size |
| `setTarget($texture)` / `setTarget($r->getViewTexture())` | draw into a texture (caches) |
| `getRenderer()` | the raw SDL renderer for `\SDL_*` calls |

Colours everywhere: `[r, g, b]`, `[r, g, b, a]`, `'#rgb'`, `'#rrggbb'`, `'#rrggbbaa'` or `new Color(...)`.

Textures (`$this->sdl()`, class `Sdl`): textures have names.

| Call | |
|---|---|
| `loadTexture('hero', 'assets/sprites/hero.png', $transparentRgb = null)` | now; PNG alpha kept; `[255,0,255]` = colour key |
| `registerTexture('lvl2', 'assets/tiles/lvl2.png', 'level2')` + `loadGroup('level2')` / `releaseGroupsExcept('level2')` | lazy, per level |
| `getTexture('hero')` | `['texture' => resource, 'width' => int, 'height' => int, 'x' => 0]` |
| `hasTexture('hero')` | |
| `createTextureFromPixels('heart', ['.#.#.', '#####', '.###.', '..#..'], ['#' => '#e33'], $scale = 1)` | pixel art in code, `.`/space = transparent |
| `createTextureFromGd('name', $gdImage)` | draw with GD (imagefilledpolygon...) then use as texture |
| `createTexture('name', $w, $h)` | empty render target |
| `setTextureVariant('-night')` | `getTexture('tiles')` returns `tiles-night` when it exists |

The `createTexture*()` / `loadTexture()` calls return a **list of slices** (wide images are cut): the resource is
`getTexture('name')['texture']` (or `[0]['texture']` of what they return), not `['texture']` of the return value.

Text: `$this->font()` is the built-in **BitmapFont** (5×7 pixels, upper case, no file):
`draw($r, $text, $x, $y, $scale = 1, $color = white, $align = 'left'|'center'|'right', $shadowColor = null)`,
`width($text, $scale)`, `height($scale)`, `lineHeight($scale)`. `\n` makes new lines.
A TTF font: `$font = new TtfFont($this->sdl(), 'assets/fonts/x.ttf', 8)` (same interface `TextDrawer`);
`$this->engine->setFont($font)` makes it the default one. `new TtfFont($sdl, $file, $size, smooth: true, style: TtfFont::BOLD,
thicken: 0.3, outline: 0.5)`: anti-aliased, bold, its strokes thickened (view pixels: a thin variable font's default
instance made bolder), and draw()'s shadow colour drawn as an outline around the letters. Smooth text wants `hd: auto`.

Helpers: `Hud::panel($r, $x, $y, $w, $h)`, `Hud::bar($r, $x, $y, $w, $h, $ratio)`,
`Hud::message($r, $font, "TEXT", $cx, $cy)`, `Hud::blink($time)`, `CrtFilter::apply($r)` (scanlines).

**Camera** (`JumPHP\Scene\Camera`): `new Camera($this->width(), $this->height())`, `setBounds(0, 0, $mapW, $mapH)`,
`follow($entity, $deadZoneW = 0.2, $deadZoneH = 0.3, $smoothing = 0)`, `update($dt)`, `getX()`, `getY()`
(whole pixels, subtract them when drawing), `centerOn()`, `lock()`, `shake($strength, $seconds)`,
`setOffset()`, `isVisible($rect, $margin)`, `worldToScreen()`.

---

## 8. Tiles and collisions

**Maps** (`JumPHP\Tile\TileMap`): layers of global tile ids (0 = empty), tilesets, objects, properties.

- From **Tiled** (recommended for real levels, https://www.mapeditor.org): save as JSON `.tmj`, tilesets
  embedded or `.tsj`, one image per tileset, "Infinite" off.
  `$map = TiledJsonLoader::load($this->engine->config->path('assets/maps/level1.tmj'));`
  - collision layer: the layer named `collision` (or with the bool property `collision`), else the first one;
  - per-tile collision: tile property `collision` = `full` | `none` | `oneway` | `slope:FROM,TO` (heights in
    px at the left / right edge, e.g. `slope:0,16`) | `heights:h0,h1,...` | `oneway-slope:...`, or shapes drawn
    in Tiled's collision editor (rectangles / polygons). Without anything a tile of the collision layer is solid;
  - layer property `foreground` (bool) = drawn over the entities; layer `visible` off = not drawn;
  - objects (object layers): `$map->objects` = `['type','name','x','y','width','height','properties','layer','gid']`,
    `$map->object('start')`, `$map->objectsOfType('coin')`; tile properties: `$map->tileProperty($gid, 'hazard')`;
  - animated tiles (Tiled animation editor), flipped tiles, image layers with parallax: supported.
- From **rows in code** (prototypes, tests, generated levels):
  ```php
  $map = ArrayLoader::fromRows([
      '..........',
      '..P...C...',
      '####==####',
  ], legend: ['#' => 1, '=' => 2], objects: ['P' => 'start', 'C' => 'coin'], tileWidth: 16, tileset: 'assets/tiles/tiles.png');
  $map->tilesets[0]->masks[1] = CollisionMask::oneWay();    // gid 2 = local id 1
  ```
  Without `tileset`, solid tiles are drawn as flat blocks.
- From **raw bytes** (`BinaryLoader::load($file, $w, $h, $tileW, $tileH, $image, $columns, $solidityFile, $shapes)`):
  one byte per tile, for levels ripped from old games.

Masks (`CollisionMask`): `full()`, `none()`, `oneWay()`, `slope($w, $h, $fromHeight, $toHeight)`,
`heights([...], $h)`, `fromRows(['#...', ...])`, `parse('slope:0,16', $w, $h)`.

Drawing: inject `TileMapRenderer $tiles` in the scene; in `render()`:
```php
$this->tiles->renderBackground($r, $map, $camera->getX(), $camera->getY(), $this->time);
$this->entities->render($r, $camera);
$this->tiles->renderForeground($r, $map, $camera->getX(), $camera->getY(), $this->time);
```

Collisions with tiles: inject `CollisionSystem $collisions` (one per scene). Each frame, set the velocity
then `$result = $this->collisions->move($entity, $map, $dt);` — it moves pixel by pixel, stops at walls,
lands on floors / one-way platforms, climbs slopes and steps up to `$maxStepUp` px (4), sticks to the ground
going down (`$snapDown`), and fills `TileCollision` flags. `CollisionResult`: `onGround`, `hitCeiling`,
`hitLeft`, `hitRight`, `hitWall()`, `groundGid`, `platform`.
Options: `$collisions->solidEdges` (map sides are walls), `touchLayers = ['items']`,
`setPlatformProvider(fn () => [['x'=>..,'y'=>..,'width'=>..,'height'=>..,'vx'=>..,'vy'=>..,'oneWay'=>true]])` (moving platforms carry the entity).
Events: `$collisions->on('touch', fn (Entity $e, string $layer, int $tx, int $ty, int $gid, array $props) => ...)`
for tiles that have properties (collect: `$map->setTile($layer, $tx, $ty, 0)`), `on('fell', fn (Entity $e) => ...)`.
Remove old listeners in `enter()`: `$this->collisions->removeAllListeners();`.
`isSolidAt($map, $px, $py)`, `groundBelow($map, $x, $y)` for AI (edge detection).

Entity vs entity: `Aabb::intersects($a, $b)`, `Aabb::overlap($a, $b)` (push-out vector), `Aabb::sweep(...)`,
`$entity->intersects($rect)`, `$entities->overlapping($rect)`.

Steady physics: `FixedStep` — `$this->step = new FixedStep(120); $this->step->run($dt, fn (float $h) => $this->simulate($h));`
(fast objects: use 120 Hz so they do not tunnel).

---

## 9. Input, sound, saves, language, network

**Input** (`$this->actions()`, `ActionMap`): `isPressed('jump')` (this frame), `isHeld()`, `isReleased()`,
`axis('left', 'right')` (-1/0/1), `label('confirm')` (key name for texts), `hold($action, $bool)` /
`press($action)` (bots, demos). Raw: `$this->engine->input->getKeyboard()->isKeyHeld(\SDLK_x)`,
`getMouse()->x/y/isHeld(1)` (view coordinates), `getTouch()->active/x/y` (0..1 of the screen),
`getGamepad()`. config/input.yaml binding names: SDL key names (`Left`, `Space`, `Return`, `Escape`,
`Left Shift`, `A`..`Z`, `0`..`9`, `F1`..`F12`, `Keypad 8`), gamepad names (below), `touch:tap`,
`touch:left`, `mouse:1`. Global keys: F3 debug overlay, F11 fullscreen (desktop).

**Gamepads** (`$this->engine->input->getGamepad()`): the same names on every pad and target (W3C "standard"
layout, Xbox names): `pad0:a b x y lb rb lt rt select start home ls rs up down left right`, stick directions
`pad0:ls_left`... `pad0:rs_down` (`pad0:left` = d-pad OR left stick), raw `pad0:button3`. Defaults:
confirm = `pad0:a`, back = `pad0:b`, pause = `pad0:start`. Analog: `axis(0, 'lx')` (-1..1, dead zone
applied; `lx ly rx ry`, triggers `lt rt` 0..1), `isButtonHeld(0, 'a')`, `count()`, `name(0)`, `isConnected(1)`,
`rumble(0, $strong, $weak, $seconds)` (browser), events `connect` / `disconnect`. Browser: Gamepad API.
Desktop: SDL joystick events with an Xbox / PlayStation table (no hat events in the binding: a d-pad seen as
a hat is invisible there, the left stick works).
In the browser, phones get an on-screen pad (arrows + A/B/START buttons; set their keys in
`project.yaml touch: {a: Space, b: Escape, start: Return}`).

**Sound** (`$this->sound()`, `SoundManager`): `loadSfx('jump', 'assets/sfx/jump.wav')`,
`synth('jump', Synth::sweep(300, 900, 0.15))` (generated: `Synth::tone($hz, $s, 'square'|'triangle'|'sine'|'saw')`,
`sweep($from, $to, $s)`, `noise($s)`, `sequence([[hz, s], ...])`), `playSfx('jump', $loops = 0)`, `stopSfx()`,
`hasSfx()`, `loadMusic('level', 'assets/music/level.ogg', 'assets/music/level-intro.ogg')`,
`playMusic('level')`, `stopMusic()`, `setSfxVolume(0..1)`, `setMusicVolume(0..1)`, `setMuted()`.
Without an audio device everything is silent, never an error. Formats: wav, ogg, mp3. Load each sound once
(`if (!$this->sound()->hasSfx('x'))`).

**Saves**: `$this->engine->storage->writeJson('save.json', $data)`, `readJson('save.json', $default)`,
`exists()`, `delete()`; settings: `$this->engine->options->get('difficulty', 'normal')`, `->set(...)`
(saved at once). Engine option keys: `sfx_volume`, `music_volume`, `fullscreen`, `lang`.

**Language**: `config/lang.yaml` (`fr: {'PLAY': 'JOUER'}`), `Lang::t('PLAY')`, `Lang::t('{n} COINS', ['n' => 3])`,
`$this->engine->lang->setLang('fr')`, `available()`. Menus translate their labels.

**Timers**: `$this->engine->gameLoop->addTimer(1.5, fn () => ...)`, `addPeriodicTimer()`, `GameLoop::nextTick()`.
Prefer counting `$dt` in the scene (timers keep running when the scene changes: check
`$this->scenes()->current() === $this` in the callback).

**Network** (online multiplayer, same code desktop and browser):
1. `bin/jumphp relay up` (server on `ws://localhost:8090/ws`; `project.yaml net_url`, or `?net=wss://host/ws` in the page URL, or env `JUMPHP_NET_URL`).
2. Lobby: `$this->scenes()->switchTo(LobbyScene::class, ['next' => PlayScene::class, 'nextParams' => ['mode' => 'online'], 'back' => TitleScene::class, 'players' => 2])`.
   It creates / joins (4-letter code) / quick-matches a room, then switches to `next` with
   `['online' => true, 'net' => NetSession, 'host' => bool, 'peer' => int] + nextParams`.
   `'auto' => 'quick'|'host'|'ABCD'` skips its menu; `?room=ABCD` in the page URL joins directly.
3. In the game scene (`NetSession $net = $params['net']`):
   ```php
   $net->removeAllListeners();
   $net->on('message', fn (int $from, int $type, string $payload) => ...);
   $net->on('peerLeave', fn (int $peer) => ...);
   $net->send(MSG_STATE, pack('gg', $x, $y), reliable: false);   // frequent state: unreliable
   $net->sendJson(MSG_EVENT, ['goal' => 1]);                       // rare events: reliable JSON
   ```
   The engine polls the session every frame. `isHost()`, `peerId()`, `peers()`, `playerCount()`, `roomCode()`,
   `lastPing()` (after `ping()`), `close()`. Decode: `MessageCodec::unpack('gx/gy', $payload)`, `MessageCodec::unjson($payload)`.
4. Design: the **host is the authority** (simulates, scores) and sends snapshots ~20/s; others send their
   input/state ~30/s; draw remote things through `SnapshotBuffer` (`push($this->engine->time(), $state)`,
   `sample($this->engine->time())`, 100-120 ms behind). One type byte per message. 64 KB max, 16 players max.
   See `games/pong/src/Scene/PlayScene.php` and `src/Net/PongProtocol.php`.
5. Test online headless: two processes with `JUMPHP_REALTIME=1`, `JUMPHP_SCENE='JumPHP\Net\LobbyScene'`,
   `JUMPHP_SCENE_PARAMS='{"next":"App\\Scene\\PlayScene","nextParams":{"mode":"online"},"auto":"quick"}'`,
   `JUMPHP_NET_URL=ws://127.0.0.1:8090/ws` and `--network host` (see docs/reseau.md).

**TCP** (`$this->engine->tcp()`, `JumPHP\Net\Tcp`; a SQL client, a Redis tool...): `$c = $engine->tcp()->connect($host, $port)`, events
`open`, `data` (string), `close` (?string reason), `error` (string); `$c->write($bytes)`, `$c->close()`. Never blocks. Desktop: a plain socket.
**Browser**: no socket exists, so it goes through the playground's **TCP bridge** (WebSocket -> TCP), only for an application the
**administrator approved** for this exact commit: the owner asks in the studio (project page, "Accès TCP"), the administrator
(`davidnurdin`) approves or refuses from his notifications; a modified application must be approved again. The page gets a 2-minute
ticket (`POST /api/tcp/ticket`, project.yaml `tcp_ticket_url`, default: the page's own origin in the browser, https://jumphp.net/api/tcp/ticket elsewhere; env JUMPHP_TCP_URL wins) signed with the app's Ed25519 key
(kept encrypted on the owner's account) and the platform's signature of the approval (slug | commit | public key); the bridge
(`docker/tcpbridge` of the playground) checks both, allows database ports only (3306, 5432, 1433, 6379, 27017...), never a private or
reserved address. Without approval the `error` event says why. Code that must work on both: use `Tcp`, never `stream_socket_client`
(rule 4). The administrator sees every connection live (Administration: open connections, bandwidth, who asked, log) and can cut or block an application or an address; quotas (`TCP_QUOTA_MB_PER_HOUR`) block an abuser and raise an alert (docs/tcp.md). Example: `apps/jumphpsql` (`MysqlConnection` takes `['tcp' => $engine->tcp()]` in its config). Tests: `engine/tests/Net/TcpTest.php`.

**Using the user's own network: JumPHP runtime** (docs/runtime.md, docs/runtime.en.md; protocol: docs/runtime-protocol.md). In the browser the bridge above only sees what the
server sees. The **JumPHP runtime** is a small program on the user's computer (tray icon, `ws://127.0.0.1:47820/v1`) that lets the application connect from the user's IP and
network (LAN, VPN, IP-filtered server) **with their consent** (a native window of the runtime; the administrator's ticket is still required: two doors). project.yaml `net_mode`
(or `JUMPHP_NET_MODE`, page `?net_mode=`): `auto` (default: the runtime when `ready`, else the server), `server`, `local` (the runtime, or an error with the state). `Tcp`, `Ssh`, `Udp`, `Icmp`
keep asking the ticket from the server, then open the server's `bridge` or the runtime's address (`JumPHP\Net\BridgeRoute`); the core only knows the `JumPHP\Net\RuntimeLink` interface
(`$engine->runtime()`, default `NoRuntime` = `unsupported`), the engine itself (since 0.5.27; `engine/src/Runtime/`, samples and docs in `extensions/runtime`, demo `games/runtime-demo`) puts its `RuntimeClient` there in the browser (state
`unknown | checking | missing | running | ready | refused | unsupported`, `check()`, `pair($app, $title)`, event `change`) and brings the Gui widgets `JumPHP\Runtime\RuntimeBanner` (**the one line an application adds**: `{class: JumPHP\Runtime\RuntimeBanner, id: runtimeBanner}`: the whole notice, what to do on Android (one button opens the runtime app or the install guide, `docs/runtime-android`), on a computer (the guide), when it runs (ask the permission), "Not now" remembered; an iPhone is told the server is used) and `JumPHP\Runtime\RuntimeBadge`
(`{class: JumPHP\Runtime\RuntimeBadge}`: green active, orange allow this app (click = `pair`), red install (click = the `RuntimeGuide` dialog), grey server bridge) and `RuntimeGuide`. Only active when
`Platform::isWasm()`. Tests: `new ManualRuntime(RuntimeLink::READY)` through `$engine->setRuntime()`. **AI agents**: use it for a browser *application* that must reach the user's network, add the badge next
to the connection fields, keep `net_mode: auto`, keep the connection code unchanged; never bypass the consent (no hand-made WebSocket to the runtime, no edit of its `grants.json`), never ask the user to disable
a security (firewall, Local Network Access, mixed content, origin allow-list), never default a published app to `net_mode: local`.

The runtime client **always looks** (8 s while missing / blocked, 20 s otherwise, at once after a failed connection, when the tab or the network comes back) and when the runtime is ready the badge shows a **Local | Server switch**: the user can go back to the server's proxy for new connections (`RuntimeClient::setUseServer()`, option `net_use_server`).

**UDP and ICMP** (`$this->engine->udp()`, `$this->engine->icmp()`; docs/tcp.md): `$c = $engine->udp()->open($host, $port)` has the events of `Tcp` but every `data` is ONE datagram and every
`write()` sends one (DNS, NTP, game servers); `$engine->icmp()->ping($host, $count, $ttl)` is a Task of replies (`['seq', 'ok', 'ms', 'ttl', 'from']`, or `'error' => 'ttl', 'hop'` for a traceroute with growing TTLs). Desktop: a plain
UDP socket, the system's `ping`. Browser: the TCP bridge, with the **udp** / **icmp** rights (separate, approved by the administrator per commit, like tcp and ssh); udp: any public address, 300 datagrams a
second; icmp: the bridge host's `ping`, one at a time, 5 a second, 200 a connection. **No Fibers in the browser** (PHP's Fiber needs `getcontext`: "The engine crashed: -1"): use generators for a coroutine
(`apps/jumphpsql` `MysqlConnection`).

**SSH** (`$this->engine->ssh()`, `JumPHP\Net\Ssh`; docs/ssh-wasm.md): `$c = $engine->ssh()->connect($host, $port, ['sshHost' => ..., 'sshUser' => ..., 'sshKey' => $keyText, 'sshPassphrase' => ..., 'knownHosts' => ...])`
pipes a stream to `$host:$port` through an SSH server (`ssh -W`), with the events of `Tcp` (`open`, `data`, `close`, `error`, plus `knownHosts`); `$engine->ssh()->via($options)` has
`Tcp`'s `connect($host, $port, $timeout)` for code that takes either. Desktop: the system's `ssh`. **Browser**: the real OpenSSH client compiled to WebAssembly
(`ssh.wasm`, `docker/ssh-wasm`) in a Web Worker, **piped between two wasm** (the application's PHP and ssh.wasm talk through the page's JsBridge: stdin / stdout are the stream), its network the
TCP bridge in raw mode. It needs the **SSH right** of the application (separate from TCP, same approval flow: owner asks, administrator approves per commit) and a key the application
has: ask for the file with `FilePicker::open()` (works in the browser: the file lands in `/tmp/jumphp-picked/`, call it from a click) and keep it in the **key ring** (`$engine->sshKeyring()`: add / remove / all / names, kept in var/ssh_keyring.json: an agent's role, the browser has no ssh-agent; pass `'sshKeys' => $ring->all()`, ssh tries every key). One SSH session carries ONE stream (`ssh -W`): several connections = several sessions (ControlMaster and `-L` need unix sockets and listening sockets, which a browser does not have). Pasted keys are fine too (`Endpoint::browserSsh()` of jumphpsql).

**Threads** (`JumPHP\Async`, `$this->engine->tasks()`): heavy work (level generation, path finding, AI,
procedural pictures) off the main thread, so the frame never waits.

- One API, three backends (`Tasks::backend()`):
  - `'threads'`: desktop with the **async runtime** (`runtime: async` in `project.yaml`: `bin/jumphp` runs the
    game on PHP 8.6 ZTS + [TrueAsync](https://true-async.github.io), `docker/desktop-async`);
  - `'workers'`: **the browser**: a pool of Web Workers, each running its own PHP engine with the game's
    files (`engine/web/jumphp-worker.js`; cores - 1, at most 4; `?workers=N` in the page URL, 0 = none);
  - `'frames'`: default desktop runtime (PHP 8.5): jobs run on the main thread between frames,
    `$tasks->budget` ms per frame (4 by default, at least one job). Also the fallback when no Web Worker
    can start.
  `Tasks::threaded()` is true for the first two. A game must be correct with all three.
- API:
  ```php
  $task = $this->engine->tasks()->thread(static fn () => Maze::generate(64, 48, $seed));   // one job, one thread
  $pool = $this->engine->tasks()->pool();            // one worker per core (Tasks::workers()); in enter()
  $job = $pool->submit(static fn (int $y) => Fractal::row($y, 192), $y)                    // never blocks
      ->then(fn (string $row) => $this->rows[$y] = $row, fn (\Throwable $e) => ...);      // main thread
  $task->isDone(); $task->isOk(); $task->result(); $task->error();
  $pool->close();                                    // in exit()
  ```
  The engine polls the tasks at the start of each frame (before the scenes update): `then()` callbacks run
  there, on the main thread, so they may touch the scene.
- **What crosses to a thread is a copy**, in a fresh PHP world that only has the game's autoloader
  (`vendor/autoload.php`): no engine, no SDL, no scene, no container. In the browser the closure travels as
  its source code + captured values (laravel/serializable-closure): its scope class (the class it is written
  in, for `self::`) must be autoloadable, which scenes are. Use **`static fn`**: a closure written
  in a method carries `$this` (the scene and its SDL resources) even if it does not use it, and is refused
  with a `LogicException` in both modes. Pass plain values (int, float, string, arrays, simple objects); return
  plain values. No references (`&$x`), no resources (files, textures, sounds), no objects with dynamic
  properties. Put thread code in plain static classes (e.g. `src/Work/`, excluded from the container in
  `config/services.yaml`).
- An exception in a thread comes back as `JumPHP\Async\TaskFailed` (`->class`, message, file, line of the
  original): `$task->error()`, or `result()` rethrows it.
- Results only arrive between frames (TrueAsync reports finished threads while the desktop loop sleeps;
  headless runs wait 1 ms per frame while tasks are pending; the page hands Web Worker results over once per
  frame): never busy-loop on `isDone()`. A Web Worker needs ~0.5-1 s to boot its engine: create the pool early
  (in `enter()`), it starts the workers at once.
- Rows of an older request can arrive after a new one started: tag jobs (a generation counter) and ignore
  stale results. Example: `games/hello-threads` (fractal by a thread pool, 60 FPS kept).
- Raw TrueAsync (`Async\spawn`, coroutines, channels, `Async\ThreadChannel`) is available in the async runtime
  too, but only `JumPHP\Async` also works in the browser. Browser jobs can not share memory with the game
  (each worker is a separate PHP): no global state survives between jobs on purpose.

**HTTP** (`$this->engine->http()`, never blocks): `get($url, $headers)`, `post($url, $body)` (array = JSON),
`request($method, $url, $body, $headers, $timeout)` return a `Task`:
`->then(fn (HttpResponse $r) => ..., fn (\Throwable $e) => ...)`. Any status settles it (`$r->status`, `ok()`,
`json()`, `header('content-type')`, `body`); `HttpError` = no answer (network, timeout, CORS). Browser:
`fetch()` (the server must allow CORS); desktop: curl_multi, or non-blocking sockets (async runtime).

**Page / window life** (`$this->engine->lifecycle()`, same events on both targets): `hide` / `show` (tab
hidden, app in background / window minimized), `blur` / `focus`, `online` / `offline`, `resize` (w, h),
`orientation` ('landscape'|'portrait'), `fullscreen` (bool), `close` (page closing / game quitting: save NOW).
`hide` and `close` run at once in the browser, between frames, and var/ is written to IndexedDB right after.
Getters `isVisible()`, `hasFocus()`, `isOnline()`, `orientation()`, `size()`. project.yaml `pause_scene` pushes
your pause menu automatically; the sound is muted while hidden.

**Phone features** (`$this->engine->device()`; what a device lacks does nothing): `vibrate(40)` / `vibrate([30,
20, 30])`, `keepAwake()` (Wake Lock; desktop: no screen saver), `lockOrientation('landscape')`,
`setFullscreen(true)` (at the next tap if the browser wants a gesture), `copy($text)` (clipboard),
`share($text, $url)`, `enableMotion()` then `tilt()` ([x, y] -1..1) / `motion()` (m/s²), `battery()`,
`isMobile()`, `isStandalone()`.

**OpenGL / WebGL** — the PHP-OpenGL extension ([Ponup/php-opengl](https://github.com/Ponup/php-opengl) +
`docker/ext/php-opengl.patch`) runs in SDL's own GL context: OpenGL on the desktop (GPU driver or Mesa), WebGL 1 in
the browser (the same `gl*` calls compiled by emscripten). Write **GLSL ES 1.00** (`attribute` / `varying` /
`gl_FragColor`, `precision mediump float;` in fragment shaders; `#version 100` is added): both targets accept it.
`isAvailable()` is false without a GL context (headless runs with the SDL dummy driver): draw a 2D fallback
(`Mat4::project()` does software 3D). Keep trigonometry arguments small in mediump (`sin(3.14159 * fract(x))`, not
`sin(x * 500.0)`: NaN on some GPUs).

- High level, `$this->engine->gl()` (`WebGl`): a 3D layer under / over the 2D and post-processing of the whole
  picture. `setLayer('below'|'above', 'view'|'native', $keyColour)` (`setSupersample(2)`: the layer drawn at 2x, smoothed: anti-aliased) (below: the 3D shows where the 2D is the key
  colour, e.g. clear with `[255, 0, 255]` and draw the HUD; there only opaque or black-translucent 2D reads well over the
  3D). `setLayer('below', 'view', null)`: composed by the 2D's alpha instead: clear the 2D with `$r->clear([0, 0, 0, 0], true)` (`clear()` is opaque unless asked), then smooth
  text and translucent panels of any colour lie over the 3D as they are (with `hd: auto` the view is the window's
  resolution), resources once in `enter()`: `program($vsGlsl, $fsGlsl)`,
  `buffer($floats)`, `updateBuffer()`, `indexBuffer($ints)`, `texture('assets/x.png')`, `textureFromRgba($w, $h, $bytes)`;
  each frame in `render()`: `clear($colour)`, `draw($program, ['attributes' => ['a_pos' => [$buffer, 3, $stride,
  $offset]], 'uniforms' => ['u_mvp' => Mat4::...], 'textures' => ['u_tex' => $id], 'cull' => 'back'])`.
  `setPostShader('void main() { gl_FragColor = texture2D(u_frame, v_uv); }')` (inputs: `u_frame`, `v_uv`, `u_time`,
  `u_resolution`, `u_viewport`, `u_view`), `setUniform()`, `disable()` in `exit()`.
- Low level, `$this->engine->opengl()` (`OpenGl`): raw `gl*` calls between `begin($clearColour)` and `end()` in
  `render()` (a 3D target of the view size with depth; `end()` puts it into the 2D being drawn, alpha blended:
  draw the HUD after it). SDL's GL state is saved / restored around it: **never call gl* outside begin() / end()**
  (or the resource helpers `program()`, `buffer()`, `texture()`, `renderTarget()`, which do it themselves).
  `setSupersample(2)`: smoother edges. All functions and constants: `engine/stubs/opengl.stub.php`
  (+ the patch: framebuffers, `glGetIntegerv`, uniforms 2f/4f/fv/Matrix3fv, `glBufferSubData`, binary strings
  for buffers / pixels: `pack('g*', ...)`).
- Colours 0..255 in the PHP API, 0..1 in GLSL. Compile errors: `on('error')` + stderr / console `[opengl]`.
- See `games/hello-webgl` (layer + post shaders, and `RawGlScene`: raw gl* + Audio3d) and `games/jumpball` (a
  whole 3D game: the track in chunk buffers rebuilt with `updateBuffer()`, lit meshes, a shader sky).
- Headless: `bin/jumphp test` / `shot` run games that call `gl()` / `opengl()` on Xvfb (Mesa llvmpipe): the shots
  show the real 3D. `JUMPHP_GL=0` forces the no-GL path (the fallback), `JUMPHP_GL=1` forces GL.

**Positional sound** (`$this->engine->audio3d()`, `Audio3d`: [Ponup/php-openal](https://github.com/Ponup/php-openal);
desktop OpenAL Soft, browser Web Audio): `load('engine', 'assets/sfx/engine.wav')` or `synth('beep', Synth::tone(...))`
(mono or stereo WAV, mixed to mono: only mono is placed in space), each frame `listener($position, $forward, $up)`,
`$voice = play('beep', [$x, $y, $z], $gain, $pitch, loop: true)`, `move($voice, $position, $velocity)`, `setGain()`,
`setPitch()`, `isPlaying()`, `stop()`, `setDistance($reference, $rolloff, $max)`. Volume / mute follow SoundManager.
Music and plain sound effects stay in SoundManager. Raw `openal_*` functions: `engine/stubs/openal.stub.php`.

**PWA** (`$this->engine->pwa()`, project.yaml `pwa: true`): the build adds manifest, icons (from
`assets/icon.png`, else the title's initial) and a service worker caching the whole build: installable, plays
offline (https or localhost). `canInstall()` / `install()` (Task: true when accepted; put it in a menu),
`isInstalled()`, `isOfflineReady()`, `updateReady()` / `applyUpdate()` (reloads into the newer build), events
`installable`, `installed`, `offline`, `update`.

**Install entry in the playground** (a game framed by a portal page, `pwa()->hasPortal()`): the frame cannot be installed, its page can. `pwa()->canInstall()` / `install()` use the page's own prompt (postMessage `jumphp:install-query` / `jumphp:install` / `jumphp:install-menu`, answer `jumphp:install-info`), `pwa()->downloads()` lists the native installs (Windows / Linux / macOS / Android) of the project's releases, `pwa()->showInstallMenu()` opens the page's Install menu. Put an "Install" entry in the settings menu of every game / app: `if ($pwa->canInstall()) ... install()`, else `if ($pwa->hasPortal()) ... showInstallMenu()`.

**Web page** (`$this->engine->bridge->send('open', ['url' => ...])`, `send('quit')`, `send('share', [...])`;
custom types reach `window.JumPHP.onBridge(msg)` and the parent frame as `postMessage({type: 'jumphp:bridge'})`).
Page -> game: `window.JumPHP.send('hello', {...})` in the page, `$this->engine->bridge->on('hello', fn (array $d) => ...)`
in PHP (delivered at the start of the next frame). `window.JumPHP.php('echo 1;')` runs PHP between frames
(debugging, browser tests).
`Platform::isWasm()`, `isDesktop()`, `isMobile()`. `$this->engine->quit()`: desktop closes, browser goes to
`?back=` or shows "thanks for playing".

---

## 10. Definition of done (always)

1. `bin/jumphp test <id>` passes (it runs your `smoke` scene 600 frames; make that a self-playing demo), with game
   tests (below) for the main mechanics.
2. `bin/jumphp shot <id> 60` (title) and `JUMPHP_INPUT='[[5,"confirm"]]' bin/jumphp shot <id> 240 var/screenshots/play.png`
   (in game): **open the PNGs and check** that what you see is what you intended.
3. Menus reachable, `pause` / `back` work, the game can be won / lost and restarted.
4. `bin/jumphp lint` passes.
5. If the browser engine exists (`build/engine/php-web.wasm`): `bin/jumphp build <id>` then
   `bin/jumphp webshot <id> 6 '[[1,"Enter"]]'` passes and its screenshot looks right.
6. Update `games/<id>/AGENTS.md` (what the game is, its scenes, its controls, anything non-obvious).

### Game tests: tests that play the game

Besides plain unit tests (`games/<id>/tests/*Test.php`, PHPUnit 11), write a few **game tests**: a test that plays
the game, frame by frame, and checks what happened. Extend `JumPHP\Testing\GameTestCase`; `$this->game` drives it:

```php
<?php
namespace Tests;

use App\Entity\Paddle;
use App\Scene\PlayScene;
use JumPHP\Testing\GameTestCase;

final class PaddleTest extends GameTestCase
{
    public function testThePaddleGoesFromTheTopToTheBottom(): void
    {
        $this->game->start(PlayScene::class, ['mode' => 'local']);   // the scene, no fade, one frame
        $paddle = $this->game->peek('left');                        // a field of the scene, private or not
        $this->game->hold('p1_up', 60);                             // an action held 60 frames (1 s), then let go
        self::assertEqualsWithDelta(PlayScene::TOP, $paddle->getBoundingBox()['y'], 1);
        $this->game->hold('p1_down', 90);
        self::assertEqualsWithDelta(PlayScene::BOTTOM - Paddle::HEIGHT, $paddle->getBoundingBox()['y'], 1);
    }
}
```

`start($scene, $params)`, `press($action, $frames = 1)`, `hold($action, $frames)`, `run($frames)`, `wait($seconds)`,
`scene()`, `peek($field, $object = null)`, `shot($name)` (a PNG of the frame). One game per PHP process: each test
starts the scene it needs. Test what the player sees happen: a move, a jump that lands, a pickup that scores, an
enemy that dies, a level that ends. Name tests as sentences (`testTheBallBouncesOnThePaddle`): they are shown as
such. Watched live: `bin/jumphp test` with `JUMPHP_LIVE=1` (the Claude Code pane `jumphp-live` sets it) writes the
frames as they run and each test's last frame (`var/live/tests/`); `JUMPHP_REALTIME=1` runs them at the game's speed.
They need SDL: `bin/jumphp test` runs them, and so does the playground's sandbox (MCP `run_tests`, without a
window): there each game test is **filmed** (`JUMPHP_FILM`: a frame every 4) and the owner replays it in the
studio, test by test. Write them **while you develop**, not at the end: a mechanic is done when its game test
passes and its frames show it.

## 11. Troubleshooting

| Symptom | Fix |
|---|---|
| `Unknown texture 'x'` | `loadTexture` / `createTexture*` before use (in `enter()` or the constructor), check the path |
| Black screen in a shot | the scene draws nothing or outside the view; `JUMPHP_DEBUG=1`; check `render()` uses view coordinates minus the camera |
| `Scene class ... not found` | namespace `App\Scene\...` must match `src/Scene/...php`; `bin/jumphp composer <id> dump-autoload` |
| A scene keeps old state | reset it in `enter()` |
| Entity sinks / jitters on the ground | move it only with `CollisionSystem::move()` (not also `Entity::update()` velocity integration); keep `width/height` = the collision box |
| Fast object goes through walls | `FixedStep(120)` or smaller speed |
| Smoke test fails on a warning | fix the warning: undefined index / property, division by zero... |
| Works on desktop, not in the browser | §2: a blocking call, a 64-bit integer, a socket, a missing file (only the game folder is packaged; `var/` is not) |
| Browser: "The game did not start" | open the console (webshot prints it): PHP boot error with file and line |
| `bin/jumphp install` fails fetching `php-wasm-network` | the first install clones it from git.jumphp.net (network needed once); later installs use the composer cache |
| Composer package needs an extension | not possible in the browser: pure PHP only |
| OpenGL / WebGL: nothing in 3D, stderr / console `[opengl] ...` | a shader error (also `$gl->on('error')`): GLSL ES 1.00, `precision mediump float;` in the fragment shader; draw every frame in `render()`; `setLayer()` and the key colour cleared in the 2D |
| Raw gl* calls break the 2D (wrong textures, black screen) | gl* only between `opengl()->begin()` and `end()` (or inside `program()` / `buffer()`...): SDL's GL state is saved / restored there |
| A post shader is black in parts on some GPUs | mediump: big arguments to sin / cos lose all precision; `sin(3.14159 * fract(x))` |
| `HttpError ... network error or CORS` in the browser only | the server must send `Access-Control-Allow-Origin` (fetch from a web page) |
| PWA not installable / not offline | project.yaml `pwa`, https (or localhost), rebuild; the first visit fills the cache (`isOfflineReady()`) |
| `Class "App\..." not found` inside a thread | the thread only has the autoloader: use `JumPHP\Async` (it loads `vendor/autoload.php` there), not raw `Async\spawn_thread` without a bootloader |
| `LogicException ... must be a static closure` | write `static fn () => ...` (or a static method) for work sent to `thread()` / `submit()` |
| `ThreadTransferException`, `Cannot transfer a resource between threads` | a reference, a resource or an object with dynamic properties was sent to a thread (as an argument or a captured `use` variable): plain values only |
| The game freezes while threads work | a busy loop on `isDone()`, or raw `Async\ThreadPool::submit()` with a full queue: use `JumPHP\Async\Pool` (never blocks) |

## 12. Remaking a game from its original disc (Amstrad CPC)

When the user wants an existing **Amstrad CPC** game remade "with the same assets, music, sprites" from a
`.dsk` file: use **[dsk2jumphp](https://git.jumphp.net/jumphp/dsk2jumphp)**. It already has everything — a
headless CPC 6128 emulator that runs the game and logs it (screenshots, memory dumps, watched writes, AY sound
log, keys, pokes, ROM or boot-sector boot), DSK / dump / Z80 / sound tools, the method step by step, and a
whole game done this way. **Do not write an emulator, a disassembler or a DSK reader yourself.**

```bash
git clone https://git.jumphp.net/jumphp/dsk2jumphp.git <somewhere outside the project> && make -C <it> test
mkdir -p .claude/skills && ln -s <it>/.claude/skills/dsk2jumphp .claude/skills/dsk2jumphp   # the skill
```

Then read its `AGENTS.md` and `docs/method.md`. In short:
- run the game in its emulator and look at the screenshots; find variables by diffing dumps and watching
  writes; read code only around the PCs the logs give; steer the game with keys and pokes;
- extract with its tools: palette, tiles and sprites (PNG, pen 0 transparent, mode 0 pixels 2 wide), font,
  maps rebuilt from write logs (JSON), music and effects from the AY log (Ogg);
- keep the extraction reproducible in the game: `games/<id>/tools/extract/` (scripts + README of the
  addresses + a script that rebuilds `assets/` from the .dsk path); **never commit the .dsk, CPC ROMs or dumps**;
  ROMs only when the game needs the firmware, from the user's machine (ask), never downloaded;
- write the game in readable PHP with the original's screen size, rules and timings (50 Hz frames), and check
  it side by side with the emulator (`cpc -s` vs `bin/jumphp shot`).

Example: the Rainbow Islands remake, https://git.jumphp.net/jumphp/rainbow-island (its `tools/extract/`), and
`examples/rainbow-islands/` in dsk2jumphp.

## 13. A web site for the game (`site/`)

A game can have its own home page: `games/<id>/site/` (static files). `bin/jumphp deploy` puts it at the root
of the domain with the game in `/play/`; the JumPHP playground (§14) shows it as the game's page. Make one when
the user asks for a site / home page / landing page, and always for a game published on the playground.
Example: the Rainbow Islands remake (https://git.jumphp.net/jumphp/rainbow-island, `games/rainbow-island/site/`
and `tools/site/`).

- **Themed with the game's own graphics**: real screenshots (`bin/jumphp shot`, several levels / moments,
  `JUMPHP_SCENE_PARAMS` test params), sprites and tiles cut from the game's assets, the game's colours. Make the
  pictures with a script kept in the game (`tools/site/shots.sh` + a Python/PHP image script): it can be re-run
  when the game changes. Pixel art scaled by whole factors, nearest neighbour; `image-rendering: pixelated`.
- **Files**: `site/index.html` (one page is enough; CSS inline), `site/images/` (PNG/JPG/WebP, no SVG),
  `site/robots.txt`, `site/sitemap.xml`; a favicon (`images/favicon.png`); `og.png` 1200 × 630 for sharing.
  No external scripts, fonts or trackers; it must work offline from its folder.
- **Link to the game**: `href="play/"` (relative: it works on your domain and on the playground), big and near
  the top, again further down. The game's page title, controls (keyboard, gamepad, phone), what the game is.
- **SEO**: `<html lang>`, `<title>` (game + genre + "play online free"), `<meta name="description">`, canonical,
  Open Graph + Twitter card, `<h1>`, one `<h2>` per section, `alt` on every image, JSON-LD
  `VideoGame` (name, description, genre, gamePlatform, image, screenshot, `isAccessibleForFree`,
  `potentialAction` PlayAction → `play/`), FAQ as `FAQPage` if there is one. Absolute URLs are written
  `https://{{DOMAIN}}/...`: replaced by the real address when deployed (`https://<domain>/` on your server,
  `https://<playground>/<game>/` on the playground).
- **Phones**: works at 390 px wide, no sideways scrolling, 16 px side margins.
- Language: the user's language (or the game's `lang`). Credit the original authors for a remake.
- **Other languages** (the playground speaks `fr en es de it pt nl pl ru uk tr ja zh ko` and shows a game's site in the
  visitor's language when it has it): `site/index.html` is the page in the main language (its `<html lang>`), each
  translation is `site/<code>/index.html` (same page, translated: title, description, Open Graph, `alt`, JSON-LD
  texts). Inside `site/<code>/`, relative paths go one folder up: `../images/…`, the game `../play/` (with `?back=` if the
  main page has one: `../play/?back=../<code>/`). Every page (the main one included) lists all the languages:
  `<link rel="alternate" hreflang="<code>" href="https://{{DOMAIN}}/<code>/">`, the main one at `https://{{DOMAIN}}/`, plus
  `hreflang="x-default"` = the main one; canonical and `og:url` = the page itself, `og:locale` its language; a small menu
  of the languages (links `../<code>/`, `../` for the main one, each in its own language). `sitemap.xml`: one `<url>` per
  language with the `xhtml:link` alternates.
- **A first version**: `bin/jumphp site:new <id>` writes one from the game itself (shots of its smoke scene, og.png,
  favicon, SEO, robots.txt, sitemap.xml; never over an existing `site/`): a start to improve, not the end.
- **Check it**: `bin/jumphp build:site <id>` then `bin/jumphp siteshot <id>`: it fails on a broken image, a
  JavaScript error, a missing title / description / `play/` link or sideways scrolling, and writes
  `var/screenshots/site-desktop.png` and `site-phone.png`: **open them** and look.

## 14. Publishing on the JumPHP playground

The **JumPHP playground** (https://jumphp.net) is a web site of JumPHP games: a directory by category, each game at
`https://<playground>/<game>`, played in the browser with a shared engine (remote mode, docs/remote.md), its
sources browsable, an online editor. Its source: https://git.jumphp.net/jumphp/jumphp-playground. A project
there is a **public Git repository of the user** holding a JumPHP project with **one game** in
`games/<id>/`; the playground publishes it after each push to the default branch.

The user signs in with Git on the playground, makes an MCP token (`/studio/mcp`) and adds the server to
Claude Code (the page shows the command, `claude mcp add --transport http --scope user jumphp https://<playground>/mcp
--header "Authorization: Bearer jph_..."`). Its tools: `whoami`, `list_categories`, `list_projects`,
`create_project`, `get_project`, `update_project`, `sync_project`, `import_project`, `list_private_files`,
`upload_private_files`, `remove_private_files`, `list_games`, `list_game_files`, `read_game_file`, `run_tests`,
`get_test_run` (the game's PHPUnit tests, game tests filmed), notes and todos, feedback. The server's
instructions say the same as this section.

To make a game for the user on the playground:
1. `create_project` (title, description, category, `kind`, brief = the user's request); `get_project` until its
   status is no longer `creating`. `kind`: `original` (a new game), `cpc` (the remake of an Amstrad CPC game
   from its `.dsk`: made with dsk2jumphp, §12; ask the user for the path of their disc), `port` (a port of a game
   whose source code exists). It made the repository from this version of JumPHP with the skeleton game and
   `BRIEF.md`.
2. `git clone` it (`clone_url`), make the game as this file says (§4-§10), in `games/<id>/` only, with a game
   test for each mechanic as you write it (§10).
3. Its home page `games/<id>/site/` (§13) and `games/<id>/assets/thumbnail.png` (the directory's picture,
   16:9; else the site's `og:image`, else `assets/icon.png`).
4. Check it like the playground runs it: `bin/jumphp build:remote <id>` then
   `JUMPHP_WEB=remote bin/jumphp webshot <id> 6 '[[1,"Enter"]]'`.
5. Commit, `git push`, `sync_project`: it publishes at once and returns errors / warnings. Fix and push until
   the status is `ready`. `run_tests` then `get_test_run`: the tests pass there too (the user replays the
   filmed game tests in the studio). Then `update_project listed=true` and give the user the game's URL.

What the playground publishes and how:
- only `games/<id>/` (the `vendor/` of the game is not used: the engine and its packages come from the shared
  engine; Composer packages of a game are not supported there). An application without the engine (a CLI app:
  `kind: cli`, Symfony Console + symfony/tui) gets its own packages: the playground installs its composer.json's
  `require` (Packagist only, no scripts nor plugins) for its tests and its page;
- the engine version is the repository's `VERSION` (it must be one the playground serves: keep JumPHP up to
  date with `bin/jumphp update`);
- a commit with a PHP syntax error (`php -l`) is not published; no PHP runs on the server (it runs in the
  player's browser);
- the site is served as the game's page (framed, on a separate origin); `{{DOMAIN}}` there = `<playground>/<game>`;
- limits: 4000 files, 120 MB in all, 20 MB per file; no symbolic links;
- files that may not be public (the data of an original game): keep them out of git and give them to the
  playground as **private files**: the game loads them, no repository, source page or fork holds them.
  `upload_private_files` gives a signed URL (2 hours) and the `curl` commands: `-F path=assets/data/X.BIN
  -F file=@X.BIN` (one file), or `-F folder=assets/data -F archive=@data.zip` (a folder; its paths kept), 20 MB
  per request; 64 MB and 2000 files per game. `list_private_files`, `remove_private_files`. No push or sync
  needed. (The user can also do it in Studio → the project → "Fichiers privés".);
- a game without a picture of its own is photographed by the playground (its screen, in a browser);
- the game's page `/<game>/play`: a game WITHOUT a web site of its own goes fullscreen at the first click (`?fullscreen=1` in the frame's address; the engine page does it, a game with a site keeps its own page and play button), an APPLICATION (project.yaml `kind: gui | cli | app`, `view: window`, or `ui: webview`) starts at once, with no "click / tap to play" screen (the page gets `kind` / `view` from game.json);
- every published game / application has an **Install** menu: the PWA (the browser's prompt, or how to do it: Chrome / Edge, iPhone, Firefox), the native installs it offers (the IDE's executables and installer, the files of the repository's newest GitHub release: `.exe` `.msi` `.dmg` `.AppImage` `.deb` `.apk`...), Google Play when `android_package` is set, and the GitHub releases;
- never put the MCP token or any secret in the repository;
- the **administrator** (the Git account `User::ADMIN_GITHUB_ID`) has an Administration page on the playground (docs/admin.md): TCP / SSH approvals, the TCP bridge live (connections, bandwidth, who asks, log newest first, alerts, cut / block), every project (status, directory, stage, delete) and every account (ban, unban, delete).

### One executable (Linux, Windows)

When the user wants to give the game to players with nothing installed ("an executable", "a .exe", "a binary"):
`bin/jumphp export:standalone <id>` (Linux; `--windows` for Windows, `--linux --windows` for both) makes
`games/<id>/dist/standalone/<id>-linux-x86_64` / `<id>-windows-x86_64.exe`: StaticPHP's micro (PHP 8.5 with
SDL, OpenGL, OpenAL built in) + the game as a PHAR, unpacked into the player's cache on the first launch; saves go
to the player's data folder (docs/standalone.md). The micro is downloaded from JumPHP's releases or built once
in Docker (Linux, ~15 min: run it in the background); Windows' only comes from the releases (the GitHub workflow
`standalone`): if it is missing, say so, do not try to build it.

**The micro cache is keyed by CONTENT, never touch it by hand** (`~/.jumphp/standalone/micro-<target>-<key>.sfx`; the key = `bash tools/standalone-key.sh |
sha256sum | cut -c1-16`: PHP version, the extension list of the project kind, StaticPHP's commit, `docker/standalone` and the patches). Games and graphic apps (`kind: gui`)
use `tools/standalone-extensions.txt` (SDL, OpenGL, OpenAL), desktop apps (`ui: webview`) `tools/app-extensions.txt` (FFI, NO SDL): two different keys, two different files.
**Never copy, rename, symlink or "reuse" a micro under another key** to save a rebuild, even when you changed only comments or Dockerfile addresses: on 2026-11-06 the apps' micro
was copied under the games' key and every executable of jumphpsql crashed on the players' computers with `Call to undefined function SDL_Init()` (published installers
included). If the key changed, the micro must be rebuilt: Linux `JUMPHP_STANDALONE_BUILD=1 bin/jumphp export:standalone <id> --linux` (~15 min, background), Windows /
macOS by the workflow `standalone` (docs/standalone.md). `tools/standalone.sh` now checks that the micro holds every extension of its list (symbols `SDL_CreateWindow`, `IMG_Load`,
`Mix_OpenAudio`, `TTF_OpenFont`, `glGenBuffers`, `alcOpenDevice`), removes a wrong file from the cache and stops: if you see that message, do not work around it, rebuild.
**Before publishing any executable or installer, run it** (Linux: `SDL_VIDEODRIVER=dummy SDL_RENDER_DRIVER=software SDL_AUDIODRIVER=dummy timeout 10 ./<file>` must run until the
timeout, not die with a PHP error) and never upload a binary you did not start. Check the Linux one runs: `SDL_VIDEODRIVER=dummy
SDL_RENDER_DRIVER=software SDL_AUDIODRIVER=dummy timeout 5 <file>` exits by the timeout, not with an error. dist/ is not in git.

### Android app (Google Play)

When the user wants the game on the Play Store (or "on Android", "an app"): `bin/jumphp twa <id> --url <the
game's page online, https>` makes a Trusted Web Activity with Google's Bubblewrap, in Docker (docs/android.md).
It asks the app's package the first time (suggest `io.github.<github user>.<id with _>`; it never changes) and,
the first time on this computer, **the user must accept the Android SDK license themselves**: tell them to run
the command in their terminal (`! bin/jumphp twa <id> --url ...` in Claude Code); never accept it for them.
Then:
- out: `games/<id>/dist/android/<id>-<version>.aab` (Google Play), `.apk` (a phone), `assetlinks.json`, and the
  SHA-256 fingerprint of the upload key (printed). `games/<id>/android/twa-manifest.json` goes into git; the key
  and its password (`~/.jumphp/android/keys/`) never do: tell the user to back them up;
- the site must vouch for the app, or it opens with an address bar: on the playground, `update_project` with
  `android_package` and `android_fingerprints`; on one's own server, `dist/android/assetlinks.json` served at
  `https://<domain>/.well-known/assetlinks.json` (`bin/jumphp deploy` serves `site/.well-known/` if you copy it there);
- the name under the icon: project.yaml `pwa: {short_name: ...}` (12 characters show whole on most phones);
- the Play Console is the user's (their developer account): list the steps (create the app with the same
  package, internal testing with the .aab, then **add Google Play's app signing fingerprint** with
  `update_project` too, store listing with `bin/jumphp shot` screenshots);
- a new version of the app (name, icon): `bin/jumphp twa <id>` again (versionCode + 1). The game itself updates
  with the site, no new app needed.

**Publishing on Google Play** (web app or native app `bin/jumphp android`): follow `docs/google-play.md`, step by
step. Its rules, every time:
- back up the upload key (`~/.jumphp/android/keys/<package>.keystore` + `.env`) before the first upload: lost, the
  updates are refused; keys, passwords, `dist/`, `var/` never in git or in notes;
- the web app's assetlinks needs **both** SHA-256 fingerprints (the upload key's, printed by the build, and Google
  Play's app signing key's, Play Console > Setup > App signing) or the Play version opens with an address bar;
- try the `.apk` on a real phone with `adb` and look at a screenshot (`adb exec-out screencap -p`) before any upload;
  a web app that opens then closes = two Chrome on the phone (clone / dual space), see the guide;
- the privacy policy address must answer (open it): a page of the game's site (`site/privacy.html`) or the
  repository's `PRIVACY.md`; Store settings needs a category and the contact e-mail the user gives;
- the target audience, the content rating answers, accepting terms and **sending to production** are the user's:
  show them what you will answer / send and wait for their yes, for that step; never say the game is for children
  unless they want it;
- never remove a phone's user / clone space or uninstall an app without asking (data is lost);
- each build is versionCode + 1: commit `android/twa-manifest.json` / `android/app.json` at once.

### Apple apps (macOS, iPhone / iPad)

When the user wants the game on a Mac, an iPhone or an iPad as an app ("an app for iOS", "on the App Store", "a Mac
app"): `bin/jumphp apple <id>` (or `ios <id>` / `macos <id>`; docs/apple.md). Xcode is needed, so GitHub builds them
on one of its Macs: the command writes `games/<id>/apple/app.json` (in git: bundle identifier asked the first time,
build + 1, version), puts `.github/workflows/apple.yml` in the project, pushes after the user's yes, runs it and
downloads `games/<id>/dist/apple/`: `<id>-<v>-macos.dmg` / `.zip` (Apple silicon + Intel), `<id>-<v>-ios.ipa`,
`<id>-<v>-ios-simulator.zip`, and `check/macos.png` (the Mac app ran the game headless) / `check/ios.png` (the app in an
iPhone simulator): **open the two screenshots** and check the game is there. First build ~40 min (run it in the
background), then ~10.
- without an Apple developer account (99 $ a year) the apps are signed ad hoc: a Mac opens it after right click > Open;
  an iPhone installs nothing unsigned (the simulator's app and the web version in the meantime). Signing, notarization
  and TestFlight come with the account (docs/apple.md): never ask for or store Apple credentials yourself;
- in the app: `JUMPHP_IOS` (Platform::isPhoneApp(), touch controls, the game's orientation) or `JUMPHP_MACOS`; SDL on
  OpenGL ES 2 (iOS) / OpenGL (macOS: the engine turns GLSL ES 1.00 into GLSL 1.20 itself).

## 15. Reference

- `games/hello-world/` — the smallest game: a menu (play, sound on / off, quit), a text moved by the arrows, a
  Synth sound. The start of the playground's tutorials.
- `games/pong/` — complete game: menus, options, AI, local 2 players, online, pause, game over.
- `games/hello-threads/` — threads (`runtime: async`): a thread pool computes a fractal, the frame never waits.
- `games/hello-webgl/` — WebGL / OpenGL: 3D cubes under a 2D HUD, post-processing shaders, raw gl* + OpenAL (`RawGlScene`),
  gamepad sticks / rumble, automatic pause (lifecycle), PWA (installable, offline).
- `games/jumpball/` — a 3D runner on OpenGL / WebGL: procedural track (seeded), ball physics at a fixed step, a bot
  (title screen demo + smoke test), chunked vertex buffers, filled software 3D without GL, best score saved.
- `skeleton/` — platformer: tiles, slopes, one-way platforms, camera, coins, sprite from pixel strings.
- `apps/hello-tcp/` and `apps/hello-ssh/` — the tutorials of docs/tuto-tcp.md and docs/tuto-ssh.md: a TCP connection (PING to a Redis), the same through an SSH server (key chooser, key ring), with tests that play the window.
- `games/hello-hybrid/` — a hybrid game (§16): SDL balls + an HTML control panel, native file chooser / dialog, tray
  menu, global shortcut.
- `games/ide/` + `ide/` — the JumPHP IDE (docs/ide.md): a big desktop app, its core tested with PHPUnit
  (`bin/jumphp php ide vendor/bin/phpunit`), its interface checked with `games/ide/tools/shot.sh`.
- `games/hello-gui/` — a graphic app (§17): a form made in the visual editor (responsive grid of two panels), its code
  behind, saved contacts, game tests.
- `games/hello-plugin/` — a plugin of the JumPHP IDE (§19): a tool window, an action, problems, completion, its tests.
- `extensions/particles/` + `games/particles-demo/` — the particle engine, an engine extension (§20), and its showcase.
- `games/hello-cli/` — a CLI app (§18): a task list, commands and a full-screen screen, tested on a virtual terminal.
- `games/hello-desktop/` — a desktop app (Tauri, §16): a notepad, HTML page + PHP (files, native dialogs, PHP clock,
  drag and drop, quit confirmation), tested with `AppTestCase`.
- `engine/stubs/sdl.stub.php` — every raw SDL / SDL_image / SDL_mixer / SDL_ttf function and constant.
- `engine/tests/` — usage examples of CollisionSystem, TiledJsonLoader, SceneManager, Menu, Net.
- `docs/` (French) — architecture, build, browser pitfalls, tiles, network, porting an existing game, remaking a CPC game (`docs/cpc.md`).
- [dsk2jumphp](https://git.jumphp.net/jumphp/dsk2jumphp) — remake an Amstrad CPC game from its disc (§12).

## 16. Desktop apps (Tauri): HTML pages, PHP behind them

Besides games, JumPHP makes **desktop applications**: native windows whose content is HTML / CSS / JS (the system's
WebView), whose logic is PHP. Linux, Windows, macOS, and the same app in the browser / on phones (`bin/jumphp build
<app>`: PHP in WebAssembly, the native side done in HTML). Full guide: `docs/tauri.md`
(French: `docs/tauri.fr.md`). Example: `games/hello-desktop`. Nothing of this touches games.

When the user wants "an app", "a desktop application", "a tool with a window", "an HTML interface" (not a game):

```bash
bin/jumphp app:new notes "Notes"     # apps/notes from skeleton-app/ (config/project.yaml: ui: webview, main: App\Main)
bin/jumphp install notes
bin/jumphp app:test notes            # php -l + phpstan + phpunit (AppTestCase) + the app for real on Xvfb: must pass
bin/jumphp app:shot notes            # var/screenshots/app.png: OPEN IT and look
bin/jumphp app:export notes          # dist/standalone/notes-linux-x86_64 (the user's computer needs WebKitGTK 4.1)
bin/jumphp app:export notes --windows --macos    # + notes-windows-x86_64.exe, notes-macos.zip (an .app, both Macs)
bin/jumphp build notes               # dist/web/: the same app in a web page (webshot to check it)
```

- **PHP is the program**: `app.php` = `exit(JumPHP\Tauri\App::boot(__DIR__)->run());`. PHP starts the Tauri runtime
  from its main thread (FFI, `libjumphp_tauri.so`), which calls PHP back at every frame (like the browser engine):
  never block (rule 2 holds), answers come later as `Task`s.
- **`src/Main.php`** implements `JumPHP\Tauri\AppMain::boot(App $app)`: `$app->command('name', fn (mixed $payload) =>
  value | Task)` (what pages call), `$app->send('event', $payload)` / `$app->window()->send(...)` (PHP -> pages),
  `$app->on('page:event', fn ($payload, $window) => ...)`, `$app->window()->on('close-requested', fn (NativeEvent $e)
  => $e->preventDefault())`, timers with `$app->gameLoop->addPeriodicTimer()`, `$app->storage` / `options` / `http()` /
  `tasks()` as in games.
- **Pages** in `ui/` (project.yaml `app.pages`): plain HTML / CSS / JS. `window.jumphp` is injected:
  `await jumphp.invoke('name', payload)`, `jumphp.send('event', payload)`, `jumphp.on('event', fn)`, and the built-in
  `jumphp.invoke('native.open' | 'native.save' | 'native.message' | 'native.confirm' | 'native.notify' | 'native.openUrl', {...})`.
- **Native features from PHP** (any target, `JumPHP\Native`): `Dialog::message()`, `Dialog::confirm()`,
  `FilePicker::open() / folder() / save()` (Tasks; in the browser only `open()`: the chosen file is written into the engine's memory, `/tmp/jumphp-picked/<name>`, call it from a click), `Notification::show()`, `Shell::openUrl() / openPath() / reveal()`;
  `Native::supports($feature)` first (SDL games: message only; browser: openUrl only; headless: none).
- **Tests**: `tests/*Test.php` extending `JumPHP\Testing\AppTestCase` play the app without a window: `invoke()`,
  `pageSend()`, `requestClose()`, `answerDialog()`, `frames()`, `wait()`, `sent('window.set_title')`, `lastSent()`.
  Write one per feature as you go, like game tests.
- Also for apps (and hybrid games): `Clipboard::copy()` / `paste()`, `$app->tray($id, ['menu' => [...]])->on('menu', ...)`,
  `$app->globalShortcut('CmdOrCtrl+Shift+K', fn () => ...)`. `JUMPHP_TAURI_TRACE=1` prints every native command / event.
- **Starting with the computer** (an exported app): `$app->autostart()->set(true)` / `isEnabled()` / `startedAtLogin()`;
  project.yaml `app: {autostart: {default: true, hidden: true}}` (on at the first start; window hidden at login: a tray app).
- **Menus**: `$app->window()->setMenu([['text' => 'File', 'items' => [['id' => 'open', 'text' => 'Open...',
  'accelerator' => 'CmdOrCtrl+O'], '-', ['role' => 'quit']]], ...])` (check items: `'checked' => true`; roles: quit
  copy cut paste undo redo select_all...), `$app->window()->on('menu', fn (string $item, ?bool $checked) => ...)`,
  `setMenuItem()`; `$app->setMenu()` for the whole app. Test: `$this->menu('open')` in an AppTestCase.
- **Updates** of an exported app: `bin/jumphp app:release <app> --url <base> [--linux] [--windows] [--macos]` (signed
  update.json; the key stays in `~/.jumphp/keys`, `config/update.pub` is committed), project.yaml
  `app: {update: {url: <base>/update.json}}`, `$app->updater()->check()->then(fn (?Update $u) => $u?->install()
  ->then(fn () => $app->updater()->relaunch()))`. Bump project.yaml `version` for each release.
- **Hybrid games** (`ui: hybrid` in a game's project.yaml, `main: App\Desktop` optional, `app.window` optional): an SDL
  game that also gets all this and HTML windows beside its own (`games/hello-hybrid`). Headless / browser / phones:
  a plain game, so scenes must check `Native::supports()` and use `App::current()?->send(...)`. `app: {embed: true}`:
  the game drawn **inside** the Tauri window (one window, `JumPHP\Tauri\EmbeddedGame`; not for OpenGL games).
- **Services** (a program in the background, started at boot / login, restarted on failure): project.yaml `service:
  {name, args: [serve], restart: on-failure, user: true}`, `JumPHP\Service\Daemon::loop()` in the program,
  `bin/jumphp service:install / status / logs / uninstall <id>` on the exported executable (systemd, launchd, WinSW;
  docs/services.md).
- Platforms: Linux (Docker commands), Windows and macOS (the workflow `tauri` builds and tries the library there,
  `app-export` opens the exported .app on two Macs). `tauri:download` fetches the library of these sources. In the
  browser: windows become frames, menus a bar, dialogs HTML, save = download, no tray / global shortcuts. Phones:
  `bin/jumphp android <app>` / `bin/jumphp ios <app>` = that browser build in the phone's WebView (iOS not tried yet).
- Don'ts: no SDL / `Engine` in an app (it has no SDL window); no blocking calls; never write outside `var/` (the
  user's data folder once exported) except files the user chose in a dialog.


## 17. Graphic apps (JumPHP\Gui): forms, no HTML

When the user wants "an app" made of windows with buttons, lists, fields... drawn by PHP itself (no HTML, the same on
desktop, web and phones), or a "mobile app" of that kind: `bin/jumphp gui:new <id> "Title"` (`--mobile` for phones).
Full guide: `docs/gui.md`. Example: `games/hello-gui`.

- A window = `forms/<Name>.form.yaml` (its widgets: what the IDE's visual editor writes) + `src/Form/<Name>.php`
  (`final class <Name> extends JumPHP\Gui\Form`, a scene: `loaded($params)`, event methods named in the form file
  `events: {click: onSaveClick}`, `tick($dt)`). Widgets by id: `$this->save`; keep one `@property Button $save` line
  per widget id in the class docblock (the visual editor does it; do it when you edit the YAML by hand).
- project.yaml: `kind: gui`, `view: window` (the view follows the window, pixel for pixel; sizes in forms are dp),
  `touch: false`, `start_scene: App\Form\MainForm`.
- Layout like a web page without HTML: `column` / `row` / `grid` (12 columns, `span: {xs: 12, md: 6}` per screen
  width: xs < 600 dp, sm < 900, md < 1200, lg) / `stack` / `panel`; `gap`, `padding`, `items`, `justify`; children
  `width` / `height` (dp, `auto`, `fill`), `grow`, `align`, `hide: [xs]`; a `spacer` takes the free room.
- Widgets: label, button, textbox, checkbox, radio, combobox, listbox, slider, progressbar, image, video (`src: assets/videos/intro.mp4`:
  bin/jumphp cuts the film into pictures by itself), spacer, separator. Mouse wheel, dead keys, input methods: handled. Their events: docs/gui.md.
- Tests (GameTestCase): `$form = $this->game->scene(); $form->click($form->widget('add')); $form->type("Ada\n");`.
  Check with `bin/jumphp shot <id>` (and a phone width: `--mobile` apps are 390 dp wide).
- **Languages**: every text of a widget (label, button, checkbox, tooltip, placeholder, group title) is translated by the engine with `Lang` when the text is a key of `config/lang.yaml` (`fr: {'Save': 'Enregistrer'}`; the language is the page's `?lang=`, the browser's, or project.yaml `lang`). Texts with a variable: `Lang::t('{name} saved', ['name' => $x])`. The engine's own texts (runtime banner, badge, guide) are in `engine/src/Util/engine-lang.yaml` in the 14 languages of JumPHP (`fr en es de it pt nl pl ru uk tr ja zh ko`); the game's file wins. A new application must give its `lang.yaml` in those 14 languages.
- Don'ts: no game-style `actions()` for the forms (they read the mouse, touch and keys themselves); no HTML;
  colours as '#rrggbb'; never block (rule 2).


## 18. CLI apps: applications in the terminal

When the user wants a command-line tool, "a CLI", "a TUI", a program "in the terminal": `bin/jumphp cli:new <id>
"Title"` (`skeleton-cli/`, `config/project.yaml kind: cli`). Full guide: `docs/cli.md`. Example: `games/hello-cli`.

- Commands: Symfony Console, one class per command in `src/Command/` (`#[AsCommand]`), registered in `src/Main.php`.
- Full-screen screens: symfony/tui in `src/Ui/` (`Tui`, widgets, `onInput` / `onSelect` / `onSubmit`, `run()` /
  `stop()`); with no terminal (a pipe), fall back to plain output.
- `bin/jumphp run <id> [command...]` (in this terminal), `test` (php -l, phpstan, phpunit, `php app.php list`),
  `shot <id> [seconds]` (the real program in a pseudo-terminal, `JUMPHP_KEYS='[[1,"a"],[1.5,"\r"]]'`: **read** the
  screen text it prints / writes in `var/screenshots/screen.txt`), `export:standalone <id> --linux --windows --macos`
  (one executable each, a console program on Windows).
- Tests as you go: `ApplicationTester` for commands, a `VirtualTerminal` for screens (`simulateInput()`, `tick()`,
  `ScreenBuffer::getScreen()`).
- Don'ts: no SDL / engine scenes, no `sleep()` inside a screen (`scheduleInterval()`), data only in `Main::boot()`'s
  folder. In the browser (the playground's Play, `docs/cli.md`) its commands are typed in a web terminal, one
  `app.php` run each, interactive like a real terminal: questions wait for the typed line, full-screen screens get raw
  keys through `src/Ui/WebTerminal.php` + `WebLoop.php` (a Revolt loop without fibers: the browser's PHP has none) when
  `JUMPHP_WEB_TERMINAL=1`; `stream_isatty()` is false there, so check `WebTerminal::available()` too, and keep the
  plain output for pipes. A widget removed from the `Tui` (`remove()`) loses its listeners:
  build a new one to show it again. A screen needs a terminal both ways (`stream_isatty(STDIN)` and `STDOUT`): on a
  pipe, print something plain instead.


## 19. Plugins of the JumPHP IDE (PHP)

When the user wants to extend the JumPHP IDE (a tool window, an action, completion, checks of their files, a run
configuration): `bin/jumphp plugin:new <id> "Title"` (`skeleton-plugin/`, `config/project.yaml kind: plugin`). Full
guide: `docs/ide-plugins.md`. Example: `games/hello-plugin`.

- `composer.json` `extra.jumphp-ide` (id, name, version, class) + `autoload` psr-4 in a namespace of its own
  (`JumphpPlugins\<Id>\`); no package of its own at run time.
- The class implements `JumPHP\Ide\Plugin\Plugin::activate(Api $ide)`: `action()`, `toolWindow()` (`Ui::` nodes,
  callbacks in PHP), `runConfiguration()`, `completion()`, `diagnostics()`, `mcpTool()` (a tool for Claude on the
  IDE's MCP server: `mcp__jumphp-ide__plugin_<id>_<name>`), `on('file.saved')`, `every()`, `notify()`, `openFile()`,
  `workspace()`, `setting()`.
- Share it: Tools > Plugins… > "Publish on jumphp.net" (https://jumphp.net/ide-plugins), or `bin/jumphp plugin:pack`.
- Tests as you go: `tests/*Test.php` extend `JumPHP\Ide\Testing\PluginTestCase` (`project()`, `action()`,
  `toolText()`, `click()`, `submit()`, `complete()`, `problems()`, `save()`, `mcp()`). `bin/jumphp test <id>` must pass.
- Don'ts: never block (the IDE's frame), no network waits, nothing written outside the project / its settings.


## 20. Engine extensions (Composer packages that add to the engine)

A subsystem a game can require (particles, physics, a tile editor's format...): a Composer package of **type
`jumphp-extension`**. The engine finds it in the game's `vendor/composer/installed.json` at boot: nothing to wire.
Full guide: `docs/extensions.md`. Example: `extensions/particles` (+ `games/particles-demo`).
Also `extensions/physics` + `extensions/shorts` (+ `games/shorts-demo`): vertical videos for TikTok / Shorts, each bounce a
note, `bin/jumphp video:make <game> [--seed N] [--count N] [--seconds S]` -> MP4 1080x1920 (`docs/video-maker.md`).

- `composer.json` `extra.jumphp`: `services` (classes autowired, public: scenes ask for them in their constructor),
  `services_file` (a Symfony services file of the package), `boot` (a class implementing `JumPHP\Extension\Extension`:
  `boot(Engine $engine)` before the first scene), `loaders` (`{".particles.json": "Class"}` implementing
  `JumPHP\Extension\AssetLoader`: `$this->engine->extensions()->load('assets/fx/x.particles.json')`), `config` (project.yaml
  keys with their `default`, merged under the game's own values).
- `bin/jumphp extension:new <id> "Title"`: `extensions/<id>` (namespace `JumphpExtensions\<Id>\`) + `games/<id>-demo`
  requiring it through a path repository; its game tests are the extension's tests (`bin/jumphp test <id>-demo`).
- Pure PHP (the browser build packs vendor/ and compiles the container with it): rules of §2 apply. Not on jumphp.net
  yet (its shared engine ignores a game's own packages).
- **When the user asks for an extension** (IDE: New Project > Engine extension, or `bin/jumphp extension:new <id> "Title"`; it is made on the
  computer, nothing online), do not stop at the code. A finished extension has, in `extensions/<id>/`:
  1. the code in `src/` + its tests (unit tests and game tests, in `games/<id>-demo/tests/`);
  2. **`doc/index.md`**: what it brings, short usage code, the project.yaml keys, the list of samples (the IDE shows it, `extension:add` copies it);
  3. **`doc/samples/*.php`: one sample for each thing it does**, each a scene (`final class <Thing>Sample extends AbstractScene`, namespace
     `App\Extensions\<Pascal of the id>`, `public const LABEL = 'one line on what it shows'`, a comment saying what to press and how to run it, the engine's
     actions or the mouse only, a text on the screen), concrete, about 60 lines, runnable alone: `extension:add` copies them into the user's game
     (`src/Extensions/<Name>/`), the IDE has a ▶ Play for each, and in the extension's own project a ▶ on the sample's `class` line runs it through
     `games/<id>-demo` (it autoloads `doc/samples/` itself);
  4. `games/<id>-demo/tests/SamplesTest.php` (already in the skeleton) starts every sample 90 frames: `bin/jumphp test <id>-demo` must pass;
  5. `README.md`, and `composer.json` `extra.jumphp` filled (`summary`, `features`, `engine`, screenshots made with `bin/jumphp shot`);
  6. `bin/jumphp lint` and a look at a `bin/jumphp shot <id>-demo`.
  Publishing is the user's click: the IDE's Publish (Publish extension) puts the package in its own Git repository `<login>/jumphp-<id>`, tagged, and
  submits it on Packagist (docs/extensions.md "Make and publish your own"): never make repositories or tags yourself, never write a token in a file.
- Runtime (in the engine core since 0.5.27, samples in `extensions/runtime`): the client of the JumPHP runtime (the user's own network from the browser), `RuntimeBadge` and `RuntimeGuide`; project.yaml `net_mode`, `jumphp_runtime` (§9, docs/runtime.md). Namespace `JumPHP\Runtime\`.
- Particles (`jumphp/particles`): `ParticleSystem` (one per scene: `add(new Emitter([...]))`, `burst()`, `update($dt)`,
  `render($r, $camX, $camY)`, `reset($seed)`), `Emitter` settings `rate life speed angle spread gravity drag colors size
  shape area duration`, project.yaml `particles: {max, seed}`, `.particles.json` files of named emitters.
