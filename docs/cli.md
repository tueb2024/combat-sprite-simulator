# CLI apps: applications in the terminal

Besides games, desktop apps (docs/tauri.md) and graphic apps (docs/gui.md), JumPHP makes **CLI apps**: programs that
live in the terminal. Commands (`todo add "Buy milk"`) with [Symfony Console](https://symfony.com/doc/current/components/console.html),
and full-screen text screens (lists, fields, progress, keyboard) with [symfony/tui](https://symfony.com/doc/current/tui.html),
Fabien Potencier's terminal UI component. The same commands as games: run, test, shot, one executable per system.
Example: `games/hello-cli` (a task list).

```bash
bin/jumphp cli:new todo "Todo"           # apps/todo from skeleton-cli/ (config/project.yaml: kind: cli)
bin/jumphp install todo
bin/jumphp run todo                      # the full-screen screen (in this terminal)
bin/jumphp run todo add "Buy milk"       # a command and its arguments
bin/jumphp test todo                     # php -l + phpstan + phpunit + smoke test (php app.php list)
JUMPHP_KEYS='[[1,"a"],[1.3,"Milk"],[1.6,"\r"]]' bin/jumphp shot todo 2.5   # the screen as text
bin/jumphp export:standalone todo --linux --windows --macos
```

## The project

```
apps/todo/
  app.php                 require vendor/autoload.php; exit(App\Main::boot(__DIR__)->run());
  config/project.yaml        id, title, version, description, kind: cli
  src/Main.php            the Console application: its commands, the default one, the data folder
  src/Command/*.php       one command per class (#[AsCommand('add', 'Adds a task')])
  src/Ui/*.php            full-screen screens (symfony/tui)
  tests/                  CommandsTest (ApplicationTester), TaskScreenTest (VirtualTerminal)
  var/                    the data while developing (the user's data folder once exported)
```

- **No command**: the full-screen screen when the output is a terminal, the plain list when it is a pipe or a file
  (`Main::__construct`, `stream_isatty(STDOUT)`): everything stays usable in scripts.
- **Data**: `Main::boot()` gives `JUMPHP_VAR` (set by the exported executable: `~/.local/share/<id>`,
  `%APPDATA%\<id>`, `~/Library/Application Support/<id>`), else `var/`.
- **Paths typed by the user** are relative to the folder they ran the program from: the exported executable unpacks
  the app in a cache folder, `Main::boot()` goes back to `JUMPHP_CWD`.

## Screens (symfony/tui)

A `Tui` holds widgets (`TextWidget`, `SelectListWidget`, `InputWidget`, `EditorWidget`, `ProgressBarWidget`,
`MarkdownWidget`, `SettingsListWidget`, `LoaderWidget`...), runs its own loop (`run()` until `stop()`), and gives the
keys to the focused widget (`setFocus()`); a widget's `onInput()` sees them first (`a`, `d`, `q` in the example).
Events: `onSelect`, `onSubmit`, `onCancel`, `onChange`. Timers: `scheduleInterval($callback, $seconds)` (never
`sleep()`). Styles: `StyleSheet` (Tailwind-like classes, `TailwindStylesheet`). The component is still marked
experimental by Symfony: pin its version in composer.json when the app is done.

## Tests

- Commands: `Symfony\Component\Console\Tester\ApplicationTester` on `(new Main($dir))->console`.
- Screens: the screen on a `VirtualTerminal($columns, $rows)`: `$tui->start(); $tui->tick();`, then
  `$terminal->simulateInput("\x1b[B")` (down; `"\r"` Enter, `"\x1b"` Escape, `"\x03"` Ctrl+C) and `$tui->tick()`, and
  read what the user sees: `$buffer = new ScreenBuffer($columns, $rows); $buffer->write($terminal->getOutput());
  $buffer->getScreen()`.
- `bin/jumphp shot <app> [seconds] [out.txt]` runs the real program in a pseudo-terminal (`JUMPHP_COLUMNS` x
  `JUMPHP_ROWS`, 100 x 30), types `JUMPHP_KEYS` (`[[seconds, "text"], ...]`), passes `JUMPHP_ARGS`, and writes the
  screen as text in `var/screenshots/screen.txt` (a fresh data folder each time).

## On jumphp.net

Published on the playground (a project of kind `cli`), the app's **Play** is a terminal in the page: each command line
typed there runs `app.php` with those arguments in the browser's PHP (WebAssembly), with the app's own Composer
packages (the playground installs its composer.json's `require` from Packagist: no scripts nor plugins). Its output
keeps its colours (`FORCE_COLOR`); `var/` (its data) is kept in the visitor's browser. The playground runs the app's
tests (`tests/`) with the same packages.

It is interactive like a real terminal: PHP runs in a Web Worker and waits for what is typed (a SharedArrayBuffer; the
page is cross-origin isolated by its COOP / COEP / Document-Isolation-Policy headers).
- **Questions** (Symfony's `ask()`, `confirm()`, `choice()`, any `fgets(STDIN)`): the page edits and echoes the line,
  Enter gives it to PHP; Ctrl+D or Ctrl+C is the end of the input (Symfony: "Aborted."). Twice Ctrl+C within 1.5 s stops
  the command (its PHP is started again: what it did not save is lost). Hidden answers are not hidden there (no `stty`).
- **Full-screen screens** (symfony/tui): `JUMPHP_WEB_TERMINAL=1` says the app runs there; `WindowsTerminal::create()`
  then gives `src/Ui/WebTerminal.php` (raw keys and the size through the page's `/dev/jumphp-term` and
  `/dev/jumphp-keys`) and installs `src/Ui/WebLoop.php`, a Revolt event loop without fibers (the browser's PHP has none:
  Revolt's own drivers abort there). `stream_isatty()` stays false: check `WebTerminal::available()` too (the skeleton's
  `ui` command does). Apps made before: copy `WebTerminal.php`, `WebLoop.php` and the `create()` / `UiCommand` lines.
- A browser that cannot isolate the page in a frame (no Document-Isolation-Policy) shows a link to open it in a tab of
  its own; there questions end at once and screens show their plain fallback, as before.

## One executable

`bin/jumphp export:standalone <app>` (`--linux`, `--windows`, `--macos`): StaticPHP's micro of the desktop apps
(no SDL: `tools/app-extensions.txt`) + the app as a PHAR, booted by `App\Main` (`dist/standalone/<app>-linux-x86_64`,
`<app>-windows-x86_64.exe`, `<app>-macos-arm64` / `-x86_64`). On Windows it stays a **console** program (games are
GUI programs: no console window). `bin/jumphp installer <app>` makes installers of it as for a game (no shortcut: a console program, put on the PATH instead: typed by its name in a new terminal; macOS: the installer installs the executable of the Mac, `{arch}`).

Windows: the commands work in any console, and so do the full-screen screens: symfony/tui's own terminal needs `stty`
(raw keys, the size), so the skeleton's `src/Ui/WindowsTerminal.php` (`WindowsTerminal::create()`) talks to the console
through FFI instead (raw mode with VT input, its key and resize events, the window's size, UTF-8). Tried for real on
GitHub's Windows (`.github/workflows/cli-windows.yml`: keys typed into hello-cli's screen). Apps made before: copy that
file and give `WindowsTerminal::create()` to `new Tui(terminal: ...)`.
