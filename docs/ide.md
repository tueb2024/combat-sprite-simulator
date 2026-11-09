# JumPHP IDE

[Français](ide.fr.md)

An IDE for JumPHP projects (games, desktop apps, hybrid games, Amstrad CPC remakes, ports), **written in PHP**, that
works the same way **as a native app on your computer** and **on the web** (`jumphp.net/ide`). It edits your real
projects: you sign in with jumphp.net, it lists your projects (your Git repositories), clones them, and pushes your
commits.

```
ide/                   the package jumphp/ide: the PHP core + the interface (HTML / CSS / JS, Monaco)
  src/                 JumPHP\Ide\: Workspace, Php (index, code intelligence), Search, Git, Run, Project, Ide (commands)
  ui/                  index.html, css/, js/ (app, editor, tree, search, git, run, welcome, designer, backend), img/
  tools/               fetch-vendor.sh (Monaco and xterm.js into ui/vendor/, not in git), icon.php
  tests/               PHPUnit (the core: completion, definition, usages, rename, problems, search, Git, workspaces)
games/ide/             the desktop app (Tauri, docs/tauri.md): Main (session, projects, dispatch), Playground (API),
                       Loopback (sign-in), Creator (New Project); pages = vendor/jumphp/ide/ui
```

## Features

| | |
|---|---|
| **Editor** | Monaco (the editor of VS Code): tabs, preview tabs, split right, sticky scroll, multi-cursor, minimap (option), diff, autosave, dark / light themes |
| **Config completion** | `config/project.yaml` (every key with what it does, scenes of the project, languages, key names...) `config/input.yaml` (actions, key / pad / touch / mouse names) `config/services.yaml` (`_defaults`, a service's keys, the project's classes), `config/lang.yaml` (the languages, then the texts another language has and this one not yet) and the forms (`*.form.yaml`: a widget's settings, their values, its events); in PHP, the game's texts in `t('…')` (config/lang.yaml) and its actions in `isPressed('…')`, `isHeld('…')`... |
| **PHP code intelligence** | completion that follows types (`$this->engine->config->`, return types, `@var`, `@property`, `@method`, generics: `list<Foo>`, `@template` classes `Box<Foo>` and their `@extends`, `class-string<T>` factories, `foreach` over collections, array shapes `array{hero: Foo}` and their keys), the libraries' classes, functions (`use function` added) and classmaps (Composer's vendor/), the extensions' stubs, auto-import (`use`), parameter hints, go to declaration / implementation, find usages, rename (classes, methods, properties, constants, variables; a class renames its file), quick documentation, file structure, problems (syntax errors, unknown classes / methods / functions), breadcrumbs, highlight of the symbol under the cursor |
| **Navigation** | Search Everywhere (Double Shift: classes, files, symbols, actions, `:line`), Run Anything (Ctrl twice: a `bin/jumphp` command, its commands and the project's games completed, Enter runs it), go to file / class / symbol, recent files, find / replace in files (case, words, regex, file masks), back / forward (also the mouse's back / forward buttons) |
| **Git** | Commit tool window (changes, unversioned files, check boxes, amend), diff with HEAD, commit and push, update (pull), branches (checkout, new, merge, delete), stash, log with the files of each commit and their diff, annotate (blame), rollback; **log menu** (right click a commit: new branch, tag, cherry-pick, revert, reset soft / mixed / hard), **Git Remotes…** (add, change, remove), **Git Tags…** (make, push, delete), **Git Stashes…** (apply, pop, drop), branch rename and delete on the server, push with `--force-with-lease`, **Git Credentials…** (desktop: a user and a token for any https host, GitLab or a company's server; GitHub comes from the jumphp.net sign-in, one account per project), and a message saying what to do when git cannot sign in; the log draws the branches' **graph**, **Stage by Change…** (a file's hunks one at a time), **By block…** in a merge (each conflict: ours, theirs or both), **Git Submodules…**, **SSH Keys…** (desktop: the keys of ~/.ssh, generate an ed25519 key, copy the public key, test `ssh -T`) |
| **Run** | the run configurations of the project (`bin/jumphp run / test / shot / build / webshot / serve / export / lint`, per game or app, plus `.jumphp/run.json`), Run tool window with live output, clickable `file:line`, test results (PHPUnit testdox) as a tree, screenshots opened in a tab |
| **Terminal** (desktop) | `Alt+F12` (View > Terminal): shells in the project's folder, one tab each (`+`). Linux / macOS: your shell (`$SHELL`) in a pseudo-terminal: colours, prompts, Ctrl+C, full-screen programs, its size follows the window; the keys go to the shell (only `Alt+…` reaches the IDE), copy / paste with `Ctrl+Shift+C / V`. Windows: `cmd.exe` (`JUMPHP_IDE_SHELL=powershell` for PowerShell) on pipes: the line is edited in the IDE, then sent |
| **Local History** (desktop) | File › Show Local History (also the right click of an editor tab or of a file in the Project view): the versions of a file the IDE kept on this computer, without git: one at each save, and one when the file changed outside the IDE (Claude Code, a terminal, git) before the IDE saves or opens it again. **Compare** (a diff whose right side is the file, editable) or **Revert** (one change in the editor: Ctrl+Z takes it back). Kept in `.jumphp/history/files/` (not in git): the 60 newest versions of each file, 60 days at most, text files up to 1 MB |
| **Debugger** (desktop) | Xdebug: click in the gutter left of a line of PHP for a breakpoint (right click: a condition, `Ctrl+F8` at the caret), **Debug** (`Shift+F9`, the bug beside Run) runs the selected configuration with `JUMPHP_XDEBUG=<port>` (`bin/jumphp` then starts PHP in the desktop image with Xdebug, which connects back to the IDE; phpstan, `php -l` and composer run without it). Where it stops: the line marked, the **Debug** tool window with the stack (click a frame), the variables as a tree (read lazily), watches, an expression evaluated there, a variable given a new value (double click or `F2`: a PHP expression); Resume `F9`, Step Over `F8`, Into `F7`, Out `Shift+F8`, **Run to Cursor** `Alt+F9`, Stop. **Stop at Exceptions…** (the ⚠ of the Debug window): `*` or class names, it stops where one is thrown, caught or not, and says which with its message. Breakpoints are kept per project and follow their lines as you edit. Best with headless runs (`shot`, `test`): a game stopped in a window freezes until you resume. **Two engines**, Xdebug by default and [PHP Debugger](https://php-debugger.dev) (DBGp too, near-zero overhead, much faster): **Debug with…** (right click on the Debug button, or the action) picks one for that run and can make it the default; from a terminal `JUMPHP_DEBUGGER=php-debugger` (PHP 8.2-8.5; the async runtime keeps Xdebug) |
| **Visual HTML / CSS editor** | `.html` files: Code / Split / Design. Components (drag and drop), layers, inspector (attributes, classes, inline styles), the CSS rules of the linked stylesheets (edited in place), devices (desktop, tablet, phone), undo / redo; the HTML is kept as written (scripts, head, comments). For desktop apps' pages (`games/<app>/ui/`) and game sites (`games/<id>/site/`) |
| **Play** | green Play in the editor's and the Project view's context menus (`Ctrl+Shift+F10`): a `src/Scene/*.php` starts the game in that scene, a `tests/*Test.php` runs only its tests (results as a tree in Run), else the whole game. Web: the **Game** window (right or bottom) with ⚡ Live reload and the game's console |
| **Hierarchy, libraries** | Hierarchy tool window (`Ctrl+H`): the class's ancestors as a tree, the classes under it; External Libraries in the Project view (the engine, vendor packages), read only |
| **Plugins** (desktop) | PHP plugins (docs/ide-plugins.md): actions (menus, Find Action, shortcuts), tool windows, run configurations, completion and problems for the files they choose, listeners, timers, **tools for Claude** on the IDE's MCP server (`mcp__jumphp-ide__plugin_<id>_<name>`); Tools › Plugins… installs (a folder, the project's own plugins, [jumphp.net/ide-plugins](https://jumphp.net/ide-plugins); publishes there too), enables, removes them; `bin/jumphp plugin:new` makes one, tested without a window (PluginTestCase) |
| **Claude** | desktop: the Claude tool window (`Alt+8`) talks to the Claude Code of your computer (`claude -p` in the project's folder): its answers, the files it reads and edits (click to open), the commands it runs, as they come; the conversation goes on; what it may do (a selector, Shift+Tab in the box changes it): auto mode, edit files, plan only, ask, full auto; when it asks for a permission, a dialog lets you allow it once, for 5 / 15 minutes, 1 hour, the session or always, for that kind of command or for everything (⚙ > Forget the permissions given). "Ask Claude about the Selection" in the editor's menu. A context 80 % full is compacted (`/compact`) by itself; the Context / 5 h / Week gauges follow in real time. Each turn of Claude or OpenCode is also written in the project's `IA/HISTORY/<CLAUDE\|OPEN CODE>/<model>/<dd-mm-YYYY HH-MM-SS>/<request>.md` (`IA/` goes into `.gitignore`: never sent to git, so it is lost with the computer). OpenCode's permissions follow the mode (it cannot ask). A missing git / Docker / Claude Code / OpenCode: the error notification has an **Install** button (winget, Homebrew, pkexec + the package manager, else the download page). Create its Git repository: on "Bad credentials" a **Sign in again** button signs in and retries |
| **Claude: tabs, windows, history** | a tab per conversation: `+` starts another one and the others go on (each its own Claude Code process); right click on a tab: **Open in a Window** (the conversation goes on in a window of its own; closing that window brings it back as a tab), Rename, Copy as Markdown, Close. Every conversation is kept in the project, `.jumphp/history/claude/<id>.json` (a `.gitignore` keeps `.jumphp/history/` out of git): the **History** tab lists them, newest first; one clicked opens again in a tab and goes on where it was (`--resume`) |
| **Claude, seen working** | the eye of the Claude window (**Follow**, on by default): the IDE shows what Claude does as it does it. The file it reads opens at the lines it reads (blue), what an edit replaces is marked before (orange) and what it wrote after (green), its commands appear in the Run window with their output, its searches in Find, its plan (TodoWrite) above the conversation, the file in the Project view. The keyboard stays in the Claude window and nothing moves while you type in an editor |
| **Step by step** | the Claude window's mode **Step by step** (Shift+Tab, or the ⚙ menu): Claude Code asks the IDE before every Edit / MultiEdit / Write and nothing is written until you answer. The file opens at the change, what Claude removes in **red**, what it adds in **green** under it, each block with its own tick. The step's card in the conversation shows the request that led to it, a message field ("not that way: ...", sent to Claude with a refusal) and **Accept** / **Decline** (**Apply the ticked blocks** when a MultiEdit has several). You can also write to Claude while it waits. An accepted step stays in the conversation with **See the diff** and **Roll back**: each step is undone on its own, what came after it stays (if the file changed in the same place, the IDE says so and touches nothing). Commands and web pages still ask in their own dialog |
| **Claude and jumphp.net** | the Claude window checks that Claude Code has jumphp.net's MCP server (`jumphp`: your projects, publishing, tests on jumphp.net, players' feedback, notes); when it is missing, a banner connects it in one click (`claude mcp add --scope user jumphp https://jumphp.net/mcp`, with the IDE's own sign-in as the token: signing in first if needed). Also Find Action › Connect Claude to jumphp.net |
| **Claude drives the IDE** | the IDE gives the Claude it starts its own tools (an MCP server, `jumphp-ide`, see below): open a file, point at lines with a note, show a diff or a tool window, run a run configuration in the Run window and read its result, play a scene, the problems of a file, what you are looking at (open files, cursor, selection), ask you a question in a dialog, a notification |
| **Claude on the web** | jumphp.net/ide uses the Claude Code of your computer: `bin/jumphp claude:connect` (or the account menu › Claude for jumphp.net) opens the JumPHP IDE, authorized once in the browser; it takes the web IDE's messages, runs Claude on a copy of the project with your drafts (`~/.cache/jumphp/claude-bridge/`), streams the answer back, and the files Claude changed become drafts |
| **Language** (selector at the bottom of the welcome screen, in the News tab and in Settings) | the welcome screen and the news change language at once (the 13 languages besides English: `ide/ui/js/i18n/<code>.js`, `t()` in ui.js); the rest of the IDE is English for now |
| **News** (welcome screen) | the latest news of JumPHP (jumphp.net/nouveautes, in the user's language: the system's by default, or the one chosen in Settings / on the tab, among jumphp.net's 14) and the IDE's own updates: its version, Check for updates, Download and install, Restart now |
| **Projects** | sign in with jumphp.net, your projects, clone, open a folder, clone any repository, New Project: game, graphic / mobile / desktop / CLI app, IDE plugin, hybrid game, Amstrad CPC remake (`.dsk` kept local), port of an open-source game — a game or an application is made **on this computer only** (the template cloned, the skeleton of its kind, one commit; no account needed, nothing created on GitHub or jumphp.net: a project made to try the IDE leaves no trace online); a project of the welcome list has a trash (its folder; an unchecked box also deletes its GitHub repository and jumphp.net project); `.md` files open rendered (View / Edit / Edit + View, the project's pictures included); several GitHub accounts (account menu > Add another GitHub account…, one assigned to each project); **Publish** (or **Create its GitHub repository…**) then makes the public repository on the user's account, commits and pushes everything and puts the project on jumphp.net; an **engine extension** is made on this computer too (the package `extensions/<id>` and its example game `games/<id>-demo`), and **Publish extension** (with a Packagist user name and API token it also makes / updates the Packagist package and tells jumphp.net to refresh its directory) puts the package, under `<your login>/<id>`, in its own GitHub repository `jumphp-<id>` (made the first time, tagged `v<version>`) for Packagist (submit once; jumphp.net's "My extensions" page and directory list it); an IDE plugin gets its repository at once; JumPHP stays the `jumphp` remote, fetch-only |
| **JumPHP kept up to date** | when a JumPHP project opens, the IDE brings its JumPHP files (bin/jumphp, the engine, tools, docker, skeletons, docs) to JumPHP's newest, never `games/` nor `README.md`: at once when you did not change them, else it asks (the files named; keep your changes or replace them). The commit they come from is kept in `.jumphp/jumphp.json` (commit it). `bin/jumphp update` does the same from a terminal for projects with their own history |
| **Games kept alone** | a repository holding only the game (`config/project.yaml` at its root, `composer.json` → `../../engine`) runs too: the IDE keeps its own JumPHP clone (the toolkit, in its data folder), links the game into it as `games/<id>` and runs `bin/jumphp` there |
| **jumphp.net** (`Alt+J`, desktop) | the game's project on jumphp.net: publish (push + sync), its settings (title, descriptions, category, listed), statistics and players' messages, make its web site (`bin/jumphp site:new`), build the executables (Windows, Linux, macOS, Android) and put them on its page, the installer hosted on jumphp.net (it installs the newest version sent, with shortcuts and updates), or **Run > Create an Installer…** (also in Find Action): choose the systems (Windows, Linux, macOS) and where it goes — jumphp.net, a GitHub release of the project's repository, another web site (a `dist/installer/` folder to upload at the address given; `bin/jumphp installer`), or standalone (the game inside each installer: no site, no network; `bin/jumphp installer --standalone`) — then the links to give, the Android and Apple apps with the store steps one by one (links to Play Console, App Store Connect…) |

PhpStorm's keyboard shortcuts: Double Shift, Ctrl twice, `Ctrl+N`, `Ctrl+Shift+N`, `Ctrl+E`, `Ctrl+B`, `Ctrl+Alt+B`, `Alt+F7`, `Shift+F6`,
`Ctrl+F12`, `Ctrl+P`, `Ctrl+Q`, `Ctrl+Shift+F` / `R`, `Ctrl+K`, `Ctrl+Shift+K`, `Ctrl+T`, `Shift+F10`, `Ctrl+Shift+F10`, `Ctrl+F2`, `Shift+F9`, `F7` / `F8` / `F9`, `Ctrl+F8`,
`Ctrl+D`, `Ctrl+Y`, `Ctrl+W`, `Ctrl+Alt+L`, `Alt+1/0/3/4/6/7/9`, `Ctrl+Alt+Left/Right`, `Alt+Insert`… (Help > Keyboard Shortcuts).

Windows without Docker Desktop: the IDE finds Docker Engine inside WSL and runs bin/jumphp there
([windows-wsl.md](windows-wsl.md)).

## How it works

One interface, one PHP core, two hosts:

```
            ide/ui (HTML / JS, Monaco)                         the same files on both
                     │  backend.invoke(name, payload), backend.on(event)
       ┌─────────────┴──────────────┐
  desktop: window.jumphp (Tauri)     web: fetch POST /api/ide/<slug>/invoke
       │                              │
  games/ide Main ── session, projects, sign-in        jumphp-playground WebIde ── drafts, preview, tests
       │                              │
  JumPHP\Ide\Ide ── LocalWorkspace    JumPHP\Ide\Ide ── OverlayWorkspace (published game + drafts)
       files, php.*, search.*, git.* (git program), run.* (processes)
```

- **The core** (`JumPHP\Ide\Ide`) answers commands: `file.*`, `php.*` (complete, definition, references, rename, hover,
  signature, diagnostics, outline, symbols), `search.*`, `git.*`, `run.*`, `project.info`. It never blocks: Git and runs are
  processes read a little at every frame (`Processes`), results are `Deferred`.
- **The PHP index** (`Php\Index`): every PHP file of the project analysed with nikic/php-parser (declarations, references with
  the receiver's type when the file tells it), a few ms per frame, cached; libraries (Composer `vendor/`) found by their PSR-4
  prefixes and analysed when a class is needed, then all of them in the background once the project is done (`indexLibraries()`:
  their implementations and subclasses, classmap classes; cached in `<cache>.libraries`); PHP's own classes and functions by Reflection.
- **Desktop**: `games/ide` is a JumPHP desktop app (docs/tauri.md). The pages call one command, `ide`; Main answers the session
  and project commands and hands the rest to the open project's `Ide`. Sign-in: a loopback (as native apps do OAuth): the IDE
  listens on `127.0.0.1:<random port>`, opens `jumphp.net/ide/connect`, you authorize, jumphp.net sends the browser back with a
  token (`jpi_…`, revocable in the studio). Git push uses your Git token through jumphp.net, passed in git's environment,
  never written in the repository.
- **Web**: `jumphp.net/ide/<project>` serves the same `ui/`; its commands work on the published game with your drafts on top
  (the same drafts as the online editor); commit = one commit pushed to Git, the **Game** tool window (right, `Shift+F10` / `Alt+5`) plays the game with the
  drafts beside the code: ⚡ Live puts each save into the running game (hot reload: pictures and sounds in place, code by PHP
  started again in the scene that was playing), Restart, the game's PHP output in its console; tests run
  in the playground's sandbox. The engine's classes and SDL / OpenGL / OpenAL functions resolve there too (sources and stubs
  installed with the IDE by the playground's `bin/ide-install`). Branches, log, blame and local runs are desktop only.

### The IDE's tools for an AI (MCP)

Each `claude -p` the Claude window starts gets `--mcp-config` (a file only you can read, in the temporary folder) naming
the IDE's MCP server: HTTP on `127.0.0.1`, a random port, a random token (`Authorization: Bearer`), refused to web pages
(Origin). Its tools are allowed (`--allowedTools mcp__jumphp-ide`) and told to Claude (`--append-system-prompt`).

```
claude ──HTTP JSON-RPC──▶ McpServer (ide/src/Agent) ──agent.call {call, tool, args}──▶ ide/ui/js/agent.js
       ◀── tool result ─── IdeAgent (waits, timeout) ◀──── agent.reply {call, result | error} ───┘  (does it on screen)
```

| Tool | |
|---|---|
| `get_state` | open files, the active one with its cursor and selected text, tool windows, run configurations (ids), problems |
| `open_file` {path, line, end_line} | opens and selects |
| `highlight` {path, start_line, end_line, message} / `clear_highlights` | marks lines with a note (violet) until cleared |
| `show_diff` {path?} | the diff with HEAD, or the Commit window |
| `show_tool` {tool} | project, commit, run, find, problems, game, git… |
| `find_in_files` {query, regex, include} | shown in Find, the counts returned |
| `run` {config, wait} | a configuration id (`test:pong`) or `bin/jumphp test|shot|build|webshot|lint|run|app:test… <id>` (only bin/jumphp's, not any program): in the Run window; returns exit code, tests, screenshot, end of the output |
| `play` {path} | as the IDE's Play (a scene, a test file) |
| `diagnostics` {path?} | the IDE's PHP problems |
| `ask_user` {question, options} | a dialog; the answer (10 min at most) |
| `notify` {message, kind} | a notification |
| `debug_breakpoints` {path, lines, clear_all} | sets a file's breakpoints (lines, or `{line, condition}`), shown in the gutter; no path: the list |
| `debug_run` {config, timeout} | a configuration under the debugger (as Debug): waits until a breakpoint stops it, then where, the stack, the locals; or it ended / still runs |
| `debug_state` {frame} / `debug_variables` {name, frame} | where it stopped, a frame's variables, the children of one (`$this->player`) |
| `debug_step` {action} | step_over, step_into, step_out, run, stop: waits for the next stop |
| `debug_eval` {expression} | PHP evaluated where it stopped (shown in the Debug window too) |
| `debug_run_to` {path, line} | runs to that line (Run to Cursor) |
| `debug_set_value` {name, value, frame} | a variable given a new value (a PHP expression) |
| `debug_exceptions` {classes} | exceptions that stop it where thrown (`*`: all; `[]`: none) |

Add one: its definition in `AgentTools::all()` (name, description, JSON schema, timeout), its `tool_<name>(args)` in
`AgentTools` of `agent.js`. Tests: `ide/tests/AgentTest.php` (the protocol over a real socket). The web IDE (Claude of
your computer through jumphp.net) has Follow, not the tools yet.

## Commands

```bash
bin/jumphp install ide           # composer install (and Monaco into ide/ui/vendor/)
bin/jumphp app:run ide           # the IDE on your screen (Docker: see below)
bin/jumphp app:test ide          # php -l, phpstan, phpunit (AppTestCase), the app for real on Xvfb
bin/jumphp app:export ide        # games/ide/dist/standalone/ide-linux-x86_64: the IDE as one executable
bin/jumphp php ide vendor/bin/phpunit   # the core's tests
games/ide/tools/shot.sh var/screenshots/x.png 600 /workspace "<javascript>"   # a screenshot driven by a script
```

**Use the exported executable** to work on your projects: it runs on your computer, with your `git` and your Docker
(`bin/jumphp run / test` of the projects need Docker, as always). `app:run` runs the IDE inside the Tauri image: fine to
try it, but there it only sees the JumPHP repository and has no Docker.

Environment: `JUMPHP_IDE_SERVER` (another jumphp.net, e.g. `http://jumphp.localhost:8000`), `JUMPHP_IDE_PROJECTS` (where
projects are cloned, default `~/JumPHP-Projects`), `JUMPHP_IDE_PROJECT` (open this folder), `JUMPHP_IDE_EVAL` (JavaScript run
in the page once loaded: checks and screenshots).

**Releases**: bump project.yaml `version`, then `games/ide/tools/release.sh "What is new."`. It runs
`bin/jumphp app:release ide --linux --windows --macos` (the executables + `update.json` signed with
`~/.jumphp/keys/ide-update.pem`: **keep a copy of that key**, without it the installed IDEs can never be updated again;
its public half is `games/ide/config/update.pub`, inside the IDE), attaches them to the GitHub release `ide-v<version>`
(with the installers of the previous release), checks every address answers, then commits and pushes
`games/ide/installer/update.json` and `version.json` on main. The installers already given read `version.json` there
and install the newest version: no need to remake them.

**The IDE updates itself** (`games/ide/src/SelfUpdate.php`): 8 s after it starts and every 6 hours it reads
`games/ide/installer/update.json` on main (project.yaml `app.update.url`), checks its signature, and when the version is
newer downloads it, checks its SHA-256 and puts it in place of its executable, in the background; a notification then
offers *Restart Now* (otherwise the next start runs it). Help > Check for Updates…, Help > Update Automatically (on by
default). Run from its sources it only says a newer version is out. `JUMPHP_UPDATE_URL` tries another update.json,
`JUMPHP_IDE_UPDATES=0` turns the checks off (tests). IDEs older than 0.1.3 have no updater: reinstall them once.
Not `tools/github-release.sh` (it points the installers to the repository's newest release, which is JumPHP's, not the IDE's).
Remaking them (another installer version, pictures, `install.yaml`): from a jumphp2installer clone, with the package
folder copied into it and its base installers in `bases/` (`gh release download installer-v… -p 'jumphp2installer-*'`):
`bin/jumphp php . tools/package-release.php <pkg> https://git.jumphp.net/jumphp/jumPhp/raw/branch/main/games/ide/installer/ bases out`,
then `gh release upload ide-v<version> out/JumPHP-IDE-setup*`. `games/ide/tools/installer-banner.php` remakes its pictures (the banner, the icon, and `jumphp-ide.ico` for the Windows shortcuts).

The IDE's own files: `~/.local/share/ide` (exported) or `games/ide/var/` (session, recent projects, PHP index cache).
A project's IDE settings: `.jumphp/ide.json` (its jumphp.net project, a CPC disc path), kept out of git
(`.git/info/exclude`); custom run configurations: `.jumphp/run.json` (`[{"label": "…", "command": ["…"]}]`).

## Playground side

See the playground's README ("Le JumPHP IDE"): `bin/ide-install --from ../JumPHP` copies `ide/src` and `ide/ui` into it;
routes `/ide`, `/ide/<slug>`, `/ide/connect`, `/studio/ide`, `/api/ide/*` (Bearer `jpi_…`) and `/api/ide/<slug>/invoke`.

## Logs

**View > Logs** (`Alt+F11`, also Help > Show Logs): the IDE's detailed logs as they happen, like `tail -f` but visual. What the IDE does and
fails at, and every program it runs (`bin/jumphp`, Docker, git, Claude, OpenCode…: the command, how it ended, and the end of its
error output when it failed), with a level (debug / info / warn / error), a source, a text search, **Pause** / follow, Copy and Clear. An
error that arrives while the window is closed puts a red mark on its button. The same lines go to `<IDE data folder>/logs/ide.log`
(turned into `ide.log.1` at 2 MB: two files at most): the folder button copies its path, send it with a bug report.

## AI: online, local, and for the code

**Generate… in the resource editors** (sprites, sound effects, tunes, levels, tilesets) asks one of:

| provider | needs | where it runs |
|---|---|---|
| Claude | the `claude` command, your login | Anthropic |
| OpenCode | the `opencode` command ([opencode.ai](https://opencode.ai)); Model: `provider/model`, the list is `opencode models` | the model OpenCode is set up for |
| OpenAI, Mistral, Groq, OpenRouter, Google Gemini | your API key (kept in the IDE on this computer, never in the project) | online |
| **Ollama**, **LM Studio**, **llama.cpp**'s server, any other OpenAI-compatible server (address + model) | nothing | **your computer: nothing leaves it** |
| OpenAI Images | your API key | online (sprites and tilesets: the picture is made pixel art) |
| **ElevenLabs** sound effects and music | your API key | online: a **real sound** (mp3, 1-30 s for an effect, 10-300 s for music) |

A **tileset** (the tileset editor's Generate…: tile size, columns, rows) comes back as a sheet of tiles that fit side by side, drawn as a
PNG next to the `.tsj`, with the collision each tile should have (full, one-way, none): the editor then goes on as with any tileset
(click a tile to change its collision). A **real sound** is not an editor's setting but an audio file: it is saved as an mp3 next to
the resource being edited (`assets/sfx/<description>-<code>.mp3`, `assets/music/…`) and the notification opens it; a game plays mp3
as it is (`SoundManager::loadSfx`, `loadMusic`).

The chat services speak OpenAI's chat completions API (`<server>/chat/completions`, JSON answers); the Model field lists
what the server has (`<server>/models`). Models without structured output get the JSON schema in the prompt, the JSON is found
wherever the model put it, and an answer the editor cannot use is sent back once with what is wrong (small local models
often need that second try). The core checks every answer before an editor gets it.

**Code**: the Claude window (Alt+8) has an **assistant** selector, **Claude** or **OpenCode**. With OpenCode each message is an
`opencode run --format json` in the project's folder (`--session` goes on with the conversation, `--model provider/model` from the
list of `opencode models` or typed), and its answer, the files it reads and edits, the commands it runs appear in the window as
Claude's do. With a local model set up in OpenCode (Ollama, LM Studio), the code stays on your computer. It answers one message at a
time and edits files directly (there is no "ask first" mode): review its changes with Git. Switching assistant starts a session
of its own. Tools > **OpenCode (code assistant, local models)** still opens its full interface in the Terminal, and
**Ask OpenCode…** sends one request there.

## Debugging from a terminal's Claude Code

`bin/jumphp debug` is the same debugger as an MCP server (stdin / stdout, in Docker), for Claude Code run in a terminal:

```bash
claude mcp add jumphp-debug -- bin/jumphp debug
```

It waits for Xdebug on port 9003 (`JUMPHP_DEBUG_PORT`). With `JUMPHP_DEBUGGER=php-debugger` it starts PHP Debugger's own MCP server instead (`dbgp mcp`, install `dbgp` from https://php-debugger.dev/agentic-debugging/installation: `claude mcp add dbgp -- dbgp mcp`). Claude sets breakpoints (`debug_breakpoints`) or exceptions
(`debug_exceptions`), starts the program itself in the background with `JUMPHP_XDEBUG=9003` (`JUMPHP_XDEBUG=9003
bin/jumphp test pong`, `shot`, `php`...), then `debug_wait` (where it stopped, the stack, the variables), `debug_step`,
`debug_run_to`, `debug_eval`, `debug_variables`, `debug_set_value`. Paths are relative to the project.

## Roadmap

The debugger for remote / browser games, terminal tool window, PHP formatting, local history, more refactorings (extract
method / variable, move class), a tile map editor for games, multiple projects in one window, signed macOS / Windows executables.

## Code updates (no new executable)

The desktop IDE is an executable (PHP + the Tauri runtime) that carries its own code. A **code update** replaces only the code: the IDE downloads one
small signed archive (about 0.5 MB: `games/ide/src`, `config`, and the IDE package's `src` and `ui`, without Monaco) and runs from it at its next start.
Making one takes a minute (`games/ide/tools/release-code.sh "notes"` after raising `version` in `games/ide/config/game.yaml`), against a quarter of an hour
for the three executables (`games/ide/tools/release.sh`, still needed when the executable changes). The mechanism is `JumPHP\Tauri\Overlay` (engine), the
IDE's side `games/ide/src/CodeUpdate.php`; the archive and the signed `update.json` are made by `tools/overlay-build.php`.

How it stays safe:
- the archive is announced by the **signed** `update.json` (`overlay`: version, `compat`, https address, SHA-256) and installed only if the download matches that
  SHA-256, then every file matches the SHA-256 of the archive's manifest;
- an overlay writes **only** in `src/`, `config/`, `vendor/jumphp/ide/src/` and `vendor/jumphp/ide/ui/`: never the engine, the executable or Composer's autoloader;
  a path with `..`, an absolute path, anything else is refused. `config/update.pub` (the key that checks updates) is never in an overlay;
- `overlay_compat` in `game.yaml`: the number of the executable's generation. An overlay made for another number is ignored. Raise it when a release changes what an
  overlay cannot carry (the engine's code, a PHP extension, the runtime, a dependency in `games/ide/composer.json`) and publish a full release;
- **rollback by itself**: from the moment an overlay starts, it is *pending* until the IDE's page has loaded and asked for its state (`session.state`). If the next start
  finds it still pending after a **PHP fatal error** (parse error, uncaught exception: written down by a shutdown function), it is marked bad, never tried again (the signed update.json
  offering it is ignored), and the IDE runs from the files of its executable. A start that was only *not confirmed* (a frozen window killed by the user: it also happens without any
  overlay) gets another try, and the overlay is dropped after 3 such starts in a row (`Overlay::MAX_TRIES`);
- the executable's own files are never touched: the overlay runs from a copy made with hard links (`<cache>/ide-<build>-ov-<version>`), the older copies are removed;
- `JUMPHP_NO_OVERLAY=1` starts the IDE from its executable's files (and leaves the overlay alone), for a bug that needs ruling out;
- installers install the last **full** release (`version.json` is not touched by a code update); the new IDE then takes the code update by itself at its first check.

Seen in the IDE as a normal update: "available", "installing", "Restart now". The version shown is the overlay's. Executables older than 0.1.77 do not know code updates:
they are updated by the full release, then take the following code updates.
