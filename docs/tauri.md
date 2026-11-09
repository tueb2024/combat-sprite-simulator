# Desktop apps (Tauri): HTML pages, PHP behind them

[Français](tauri.fr.md)

JumPHP makes games (SDL: desktop, browser, phones) and **desktop applications**: native windows whose
content is **HTML / CSS / JavaScript**, shown by the system's own WebView, and whose logic is **PHP**. The window,
the WebView, the dialogs and the notifications come from [Tauri 2](https://tauri.app); the program is PHP.

```bash
bin/jumphp app:new notes "Notes"      # apps/notes from skeleton-app/
bin/jumphp install notes
bin/jumphp app:run notes              # a window on your screen
bin/jumphp app:test notes             # php -l + phpstan + phpunit (no window) + the app on a virtual screen
bin/jumphp app:shot notes             # apps/notes/var/screenshots/app.png
bin/jumphp app:export notes           # apps/notes/dist/standalone/notes-linux-x86_64: one file to give
```

The example: [`games/hello-desktop`](../games/hello-desktop) (a notepad: open / save with the system's dialogs, a
PHP clock pushed to the page, files dropped on the window, a question before quitting with unsaved text).

**Native only.** Desktop apps do not run in the browser (there is no PHP-to-WebView bridge there, and a web page
is already HTML). Linux, Windows and macOS: the runtime is built and tried on the three by GitHub Actions
([Platforms](#platforms)); the one-file export is made for Linux and Windows. A **game** can use all of it too:
[hybrid games](#hybrid-games-sdl--tauri) (`ui: hybrid`).
**Nothing changes for games**: SDL games on the desktop, in the browser (WebAssembly), as standalone executables,
Android and Apple apps work exactly as before; none of their code, images or commands use any of this.

---

## How it works

PHP is the program, as in a game. Tauri is a *platform backend* that PHP drives:

```
   PHP main thread (the php process)                          libjumphp_tauri.so (Rust, tauri/)
   ─────────────────────────────────                          ─────────────────────────────────
   app.php -> App::boot() -> App::run()
        └── FFI: jt_run(config, frame) ─────────────────────▶ builds the Tauri app, runs the native
                                                              event loop ON THIS THREAD (GTK on Linux)
                                                                       │ every frame (60/s, and at once
   App::frame()  ◀──────────────────── frame() ────────────────────────┘  when something happens)
     ├── jt_poll(): events (JSON) ─▶ dispatch: ready, invoke, page, window, dialog, exit-requested
     ├── tasks, HTTP, $app->on('frame')
     ├── one turn of the ReactPHP loop (timers, streams)
     └── jt_send(command JSON)... ─────────────────────────▶ run on the main thread after the frame:
                                                              window.*, webview.*, dialog.*, notification.*...
   jt_run() returns the exit code  ◀────────────────────────── when PHP sent app.exit
```

- **The same model as the browser engine**: there, the page calls `jumphp_frame()` at every
  `requestAnimationFrame`; here Tauri calls PHP's `frame()` at every tick. PHP never blocks: dialogs, file choosers,
  page calls answer later (a `Task`, a JavaScript `Promise`).
- **PHP decides**: closing a window or the app is an *event* PHP answers. Its default handlers close / quit;
  a listener can `preventDefault()` (ask "save first?").
- **ReactPHP** stays the app's loop for timers, streams and libraries: one turn per frame (`GameLoop::start(1)`),
  any loop implementation (`Loop::get()`).
- **One library for every app**: the pages are served from the app's folder at run time (`tauri://localhost/`,
  through a custom Tauri `Assets`), not compiled into the library. `bin/jumphp tauri:build` builds it once.
- **FFI**: PHP loads the library with `FFI::cdef()` (4 C functions, [`tauri/include/jumphp_tauri.h`](../tauri/include/jumphp_tauri.h)).
  The development image (`jumphp/tauri`) has PHP's FFI; exported apps carry a PHP built with it.

### The C API (tauri/src/lib.rs)

| Function | |
|---|---|
| `int jt_run(const char *config_json, void (*frame)(void))` | runs the app until `app.exit`; returns the exit code (negative: could not start). Once per process. |
| `const char *jt_poll(void)` | the events since the last call, a JSON array (valid until the next call) |
| `void jt_send(const char *command_json)` | one command `{"cmd": "...", ...}`, run on the main thread after the current frame |
| `const char *jt_version(void)` | the runtime's version |

Config of `jt_run`: `identifier`, `product_name`, `version`, `root` (the pages' folder), `tick_hz`.

Events: `ready`, `resumed`, `invoke {id, window, name, payload}`, `page {window, name, payload}`,
`window {window, event: close-requested | destroyed | focus | blur | resized {width, height} | moved {x, y} | drop {paths}}`,
`dialog {id, result}`, `result {id, result | error}` (the answer of a command PHP waits for; a command with an `id`
that fails answers `error` there), `tray {tray, event: menu {item} | click {button}}`, `menu {window, item, checked}` (window `*`: the app's menu),
`shortcut {accelerator, state: pressed | released}`, `exit-requested`, `error {cmd, message}`.

Commands: `window.create {window, url, title, width, height, min_width, min_height, x, y, center, resizable,
fullscreen, maximized, decorations, always_on_top, visible, devtools, icon, data_directory}`, `window.set_title`,
`window.set_size`, `window.set_position`, `window.center`, `window.set_fullscreen`, `window.set_resizable`,
`window.set_always_on_top`, `window.show` / `hide` / `focus` / `maximize` / `unmaximize` / `minimize` / `close`,
`window.navigate {url}`, `webview.eval {js}`, `webview.emit {name, payload}` (no `window`: all),
`invoke.resolve {window, id, ok, result}`, `dialog.message` / `dialog.confirm {id, message, title, kind, ok, cancel}`,
`dialog.open {id, title, directory, filters, multiple, folder}`, `dialog.save {id, title, directory, file_name, filters}`,
`notification.show {title, body}`, `opener.url {url}`, `opener.path {path}`, `opener.reveal {path}`,
`clipboard.write {text}`, `clipboard.read {id}`, `tray.create {tray, icon, tooltip, title, menu: [{id, text, enabled} |
{separator}], menu_on_left_click}`, `tray.set_tooltip {tray, tooltip}`, `tray.set_icon {tray, icon}`, `tray.set_menu {tray, menu: [...]}`, `tray.remove {tray}`,
`menu.set {window, menu: [{id, text, accelerator, enabled, checked} | {separator} | {role, text} | {text, items}]}`
(window `*` or none: the application's menu), `menu.remove {window}`, `menu.set_item {window, item, enabled, checked, text}`,
`shortcut.register {accelerator}`, `shortcut.unregister {accelerator}`, `shortcut.unregister_all`,
`app.emit {name, payload}` (Tauri events, for pages using `@tauri-apps/api`), `app.tick {hz}`, `app.exit {code}`.

You never need them directly: `JumPHP\Tauri\App` and `Window` send them.

---

## An app

```
apps/notes/
  app.php                 exit(JumPHP\Tauri\App::boot(__DIR__)->run());
  composer.json           requires jumphp/engine, autoload App\ -> src/
  config/project.yaml        ui: webview, main: App\Main, app: {...}
  src/Main.php            implements JumPHP\Tauri\AppMain: commands, listeners, timers
  ui/                     the pages: index.html, CSS, JS, pictures (any front-end tool's build output works too)
  assets/icon.png         the window's icon
  tests/MainTest.php      extends JumPHP\Testing\AppTestCase
  var/                    the user's files when run from the project (exported apps: ~/.local/share/<id>)
```

### config/project.yaml

```yaml
project:
  id: notes                     # identifier net.jumphp.notes, data folder, executable name
  title: "Notes"                # window title, product name
  version: 0.1.0
  lang: en
  ui: webview                   # a desktop app: bin/jumphp app:* (run / test / shot hand over to them)
  main: App\Main                # made with new, then boot(App $app) once before the window opens
  app:
    identifier: com.example.notes   # default net.jumphp.<id without dashes>
    pages: ui                   # the pages' folder (default ui)
    tick_hz: 60                 # frames a second when nothing happens
    icon: assets/icon.png       # default: assets/icon.png when it exists
    native_commands: true       # the built-in native.* commands for pages (below)
    smoke_page: true            # app:test fails when the main page never loads
    autostart: {default: false, hidden: true}   # starting with the computer (below): on at the first start?
                                # started at login, the main window hidden (a tray app)? `autostart: true` = default on
    window:                     # the main window: every window.create option
      url: index.html
      width: 960
      height: 640
      min_width: 480
      min_height: 320
      resizable: true
      devtools: false           # true: the WebView inspector opens with it
```

### The PHP side

```php
namespace App;

use JumPHP\Native\Dialog;
use JumPHP\Native\FilePicker;
use JumPHP\Tauri\App;
use JumPHP\Tauri\AppMain;
use JumPHP\Tauri\NativeEvent;

final class Main implements AppMain
{
    public function boot(App $app): void
    {
        // page -> PHP -> page: const r = await jumphp.invoke('greet', {name: 'Ada'})
        $app->command('greet', fn (array $p) => "Hello {$p['name']}");

        // an answer that comes later: return a Task
        $app->command('pick', fn () => FilePicker::open(['filters' => ['Text' => ['txt', 'md']]]));

        // PHP -> page: jumphp.on('clock', ...)
        $app->gameLoop->addPeriodicTimer(1.0, fn () => $app->send('clock', ['time' => date('H:i:s')]));

        // page -> PHP without an answer: jumphp.send('clicked', {...})
        $app->on('page:clicked', fn (mixed $payload, string $window) => null);

        // PHP decides what closing does
        $app->window()->on('close-requested', function (NativeEvent $e) use ($app) {
            $e->preventDefault();
            Dialog::confirm('Quit?', 'Notes', 'Quit', 'Stay')->then(fn ($yes) => $yes && $app->quit());
        });
    }
}
```

`JumPHP\Tauri\App`:

| | |
|---|---|
| `App::boot($root, ?NativeRuntime $runtime = null)` | config, runtime (FFI), main window, `main` class |
| `run(): int` | runs until the app quits; the exit code |
| `command($name, fn (mixed $payload, Invocation $call) => mixed)` | what pages call; a returned `Task` answers when it ends; an exception rejects the page's promise with its message |
| `send($name, $payload)` | to every page (`jumphp.on`) |
| `window($label = 'main')`, `windows()`, `createWindow($label, $options)` | windows |
| `on('ready' \| 'frame' \| 'loaded' \| 'exit-requested' \| 'page:<name>' \| 'error' \| 'quit' \| 'native', ...)` | events |
| `quit($code = 0)` | closes everything after this frame |
| `gameLoop`, `storage`, `options`, `lang`, `config`, `tasks()`, `http()` | as in games (`Storage`, `Options`, `Lang`, `Tasks`, `Http`) |
| `time()`, `frameCount()`, `setTickRate($hz)`, `pagesDir()`, `isSmoke()` | |
| `App::current()` | the running app (for code without access to it) |

`JumPHP\Tauri\Window`: `setTitle()`, `setSize()`, `setPosition()`, `center()`, `setFullscreen()`, `setResizable()`,
`setAlwaysOnTop()`, `show()`, `hide()`, `focus()`, `maximize()`, `unmaximize()`, `minimize()`, `close()`,
`navigate($url)`, `eval($js)`, `send($name, $payload)`, `isOpen()`, `isLoaded()`, `geometry()`; events
`close-requested` (NativeEvent), `closed`, `focus`, `blur`, `resized` (w, h), `moved` (x, y), `drop` (paths),
`loaded`.

### The page side: `window.jumphp`

Injected in every page before its own scripts (it is there in `<script>` tags and modules alike):

```js
const r = await jumphp.invoke('greet', {name: 'Ada'});   // a Promise: resolves with PHP's answer, rejects with its exception's message
jumphp.send('clicked', {x: 1});                          // PHP: $app->on('page:clicked', ...)
const off = jumphp.on('clock', (p) => ...);              // PHP: $app->send('clock', ...) / $window->send(...)
jumphp.native;                                           // true: running in a desktop app
```

The pages are ordinary web pages: any CSS, any framework, a bundler's output in `ui/` (`app.pages` can point at
`dist/`). They are served from `tauri://localhost/` (a request for a folder serves its `index.html`); never from
outside the pages' folder. A window can also show an `https://` page (`url`), without `window.jumphp`.

### Built-in native commands

Without PHP code of its own, a page can ask (they go through PHP, `JumPHP\Tauri\NativeCommands`; project.yaml
`app.native_commands: false` removes them):

```js
await jumphp.invoke('native.message', {message: 'Saved', title: 'Notes', kind: 'info'});      // info | warning | error
const yes = await jumphp.invoke('native.confirm', {message: 'Delete?', ok: 'Delete', cancel: 'Keep'});
const file = await jumphp.invoke('native.open', {filters: {Images: ['png', 'jpg']}});         // null: cancelled
const files = await jumphp.invoke('native.open', {multiple: true});
const dir = await jumphp.invoke('native.open', {folder: true});
const out = await jumphp.invoke('native.save', {file_name: 'notes.txt'});
await jumphp.invoke('native.notify', {title: 'Done', body: 'Export finished'});
await jumphp.invoke('native.openUrl', {url: 'https://jumphp.net'});
const s = await jumphp.invoke('native.autostart');               // {supported, enabled, atLogin}
await jumphp.invoke('native.autostart', {on: true});             // starts with the computer (below)
```

### Starting with the computer

An exported app can start when the user logs in (no administrator rights; `JumPHP\Native\Autostart`):

```php
$start = $app->autostart();
if ($start->isSupported()) {                  // an exported app on Windows, macOS or Linux (not `php app.php`)
    $start->set(true);                        // or false; isEnabled() reads what the system has
}
$start->startedAtLogin();                     // true when the computer started it (the --autostart flag)
```

- Windows: the value `<id>` of `HKCU\Software\Microsoft\Windows\CurrentVersion\Run` (`"<exe>" --autostart`);
- macOS: `~/Library/LaunchAgents/<identifier>.plist` (RunAtLoad) that runs `open -a <the .app> --args --autostart`;
- Linux: `~/.config/autostart/<id>.desktop` (GNOME, KDE, Xfce...).

project.yaml `app.autostart`: `default: true` turns it on at the first start of the exported app (once: then the user
decides, e.g. a check box of the app's menu or settings); `hidden: true` starts the main window hidden when the
computer started it, for an app that lives in the tray (`$app->tray()`: its menu shows the window). A page asks with
`native.autostart`. `games/hello-desktop` has View > Start with the computer.

The installers (`bin/jumphp installer <app>`, jumphp2installer 0.2.9+) show a box "Start <App> with the computer" when
project.yaml has `app: {autostart: ...}`, ticked from its `default`. They write the same entry as `$app->autostart()`, the
uninstaller takes it out, and the app's own first start leaves that choice alone (the installer's `.jumphp-autostart`
file beside the executable).

### The command line (no window): `AppCli`

A main class that also implements `JumPHP\Tauri\AppCli` gets `static cli(array $args, string $root): ?int` first,
before the native library is loaded: return an exit code to stop there (`--help`, `--version`, a silent install), or
`null` to open the window as usual. Blocking is allowed there (no frame runs yet).

```php
final class Main implements AppMain, AppCli
{
    public static function cli(array $args, string $root): ?int
    {
        if (in_array('--version', $args, true)) {
            echo "1.0\n";
            return 0;
        }
        return null;
    }
}
```

---

## Native features from PHP: `JumPHP\Native`

The same calls on every target; who does them depends on where the code runs:

```php
use JumPHP\Native\{Dialog, FilePicker, Notification, Shell, Native};

Dialog::message('Saved.', 'Notes');                                    // Task: true once closed
Dialog::confirm('Delete?', 'Notes', 'Delete', 'Keep')->then(fn (bool $yes) => ...);
FilePicker::open(['filters' => ['Text' => ['txt']]])->then(fn (?string $file) => ...);
FilePicker::open(['multiple' => true])->then(fn (?array $files) => ...);
FilePicker::folder()->then(fn (?string $dir) => ...);
FilePicker::save(['file_name' => 'out.txt'])->then(fn (?string $file) => ...);
Notification::show('Done', 'Export finished');                        // bool
Shell::openUrl('https://jumphp.net'); Shell::openPath($file); Shell::reveal($file);
Clipboard::copy('text'); Clipboard::paste()->then(fn (string $text) => ...);
Native::supports('open'); Native::name();                              // 'tauri', 'sdl', 'browser', 'none'
```

| Target | `Native::name()` | Features |
|---|---|---|
| desktop app (`JumPHP\Tauri\App`), hybrid game | `tauri` | all: message, confirm, open, save, folder, notify, openUrl, openPath, reveal, copy, paste |
| SDL game on the desktop | `sdl` | message (SDL's own message box, modal) |
| browser build | `browser` | openUrl (a new tab), copy |
| headless runs (`test`, `shot`), elsewhere | `none` | nothing |

What a target lacks fails the Task with `JumPHP\Native\UnsupportedFeature` (or returns `false`): check
`Native::supports()` first. `Native::setProvider()` puts another one (tests, a custom provider implementing
`JumPHP\Native\Provider`). Dialogs never block the frame (answers come back as Tasks), except SDL's message box,
which is modal by nature.

Desktop apps and hybrid games also have, through `App` (`App::current()` from anywhere):

```php
// an icon in the system's tray, with a menu (Linux: libayatana-appindicator3 and a desktop that shows tray icons;
// the click event is not sent on Linux, the menu is)
$app->tray('main', ['tooltip' => 'Notes', 'menu' => [['id' => 'show', 'text' => 'Show'], '-', ['id' => 'quit', 'text' => 'Quit']]])
    ->on('menu', fn (string $item) => $item === 'quit' ? $app->quit() : $app->window()->show()->focus());

// a shortcut of the whole system, even without the focus (Linux: X11, not Wayland)
$app->globalShortcut('CmdOrCtrl+Shift+K', fn () => $app->window()->show()->focus());

// menus (JumPHP\Tauri\Menu): a window's ($app->window()->setMenu()) or the whole app's ($app->setMenu());
// items with an id (and an accelerator), check items ('checked'), separators ('-'), submenus ('items') and the
// system's own items ('role': quit about copy cut paste select_all undo redo minimize maximize fullscreen hide
// hide_others show_all close_window services). On macOS there is one menu bar: a window's menu becomes the app's.
$app->window()->setMenu([
    ['text' => 'File', 'items' => [['id' => 'open', 'text' => 'Open...', 'accelerator' => 'CmdOrCtrl+O'], '-', ['role' => 'quit']]],
    ['text' => 'View', 'items' => [['id' => 'wrap', 'text' => 'Wrap lines', 'checked' => true]]],
]);
$app->window()->on('menu', fn (string $item, ?bool $checked) => ...);      // also $app->on('menu', fn ($item, $window, $checked))
$app->window()->setMenuItem('wrap', ['checked' => false, 'enabled' => true, 'text' => 'Wrap']);
```

Lower levels stay available: PHP's **FFI** for any C library of the system (the desktop app's PHP has it), and
PHP extensions.

---

## Hybrid games (SDL + Tauri)

A game can drive Tauri too: `ui: hybrid` in its `config/project.yaml`. It stays an SDL game (scenes, entities, its
SDL window, sound; `app.php` unchanged), and gets the desktop apps' native side: `JumPHP\Native` (file choosers,
dialogs, notifications, clipboard), a tray icon, global shortcuts, and HTML windows beside its own (a control
panel, an editor, a launcher...). Example: [`games/hello-hybrid`](../games/hello-hybrid) (balls bouncing in SDL, an
HTML control panel, "save a picture" through the system's file chooser, a tray menu, Ctrl+Shift+P anywhere).

```yaml
project:
  start_scene: App\Scene\BallsScene
  ui: hybrid
  main: App\Desktop                # optional: an AppMain (commands for the HTML windows, tray icon, shortcuts)
  app:
    window: {url: index.html, width: 340, height: 560}    # optional: an HTML window (pages in ui/)
```

How: `Application::run()` hands over to `JumPHP\Tauri\Hybrid`: the Tauri runtime runs the main thread's loop and
calls the app's frame, which runs one turn of ReactPHP, where the game's frame timer ticks the engine as usual.
The runtime leaves the loop some idle time after each frame, so the WebViews keep painting while the game runs.
The game quits when its SDL window closes (closing the HTML window does not end it); `Engine::quit()` ends both.

The scenes use the native side when it is there and work without it:

```php
if (Native::supports('save')) {
    FilePicker::save(['file_name' => 'picture.png'])->then(fn (?string $f) => $f && $this->engine->sdl->saveScreenshot($f));
}
App::current()?->send('stats', ['score' => $this->score]);    // to the HTML window(s), when there is a native side
```

It runs as a **plain game** (no Tauri) in headless runs (`bin/jumphp test` / `shot`: game tests work as before), in
the browser, in the phone apps, and on a desktop without FFI or without the runtime (a line on stderr says so).
`bin/jumphp run <game>` runs a hybrid game in the Tauri image; `export:standalone <game>` makes it with a micro that
has SDL **and** FFI (`tools/hybrid-extensions.txt`, built once) and the runtime library inside. Test the native
side with `AppTestCase` (it boots the `main` class and the `app.window` of project.yaml), the game with `GameTestCase`.

### One window: the game inside the Tauri window

`app: {embed: true}` puts the game **in** the Tauri window instead of a window of SDL's own
(`JumPHP\Tauri\EmbeddedGame`):

```yaml
project:
  ui: hybrid
  app:
    embed: true
    window: {width: 960, height: 540}     # optional: the window (default: width x height x scale; no url)
```

SDL draws each frame into memory (its software renderer, as headless shots; no SDL window, video driver `dummy`);
the picture (a BMP, ~0.2 ms for 320 x 180) goes to the runtime through `jt_frame()` and JumPHP's page
(`engine/web/tauri/game.html`, served as `/__jumphp/game.html`) draws the newest one on a canvas, sharp and
letterboxed (`integer_scale` honoured), once per screen refresh. The keyboard, the mouse (in the game's pixels) and
the focus come back through `jumphp.send('__input')` into the engine's `InputManager`: actions, F3 and F11
(fullscreen of the Tauri window) work as usual; gamepads are read by SDL. Closing the window ends the game. Sound
stays SDL's. Other HTML windows: `$app->createWindow()` in the `main` class. Tried for real with Pong on Xvfb (the
menu, then Enter from the page started a game). Without what it needs (headless, browser, phones, no FFI), the game
keeps its SDL window. For bigger resolutions or OpenGL games, a window of SDL's own stays the better choice (the
picture is copied once per frame, and GL is not drawn into memory).

---

## Tests

`JumPHP\Testing\AppTestCase` plays the app **without a window** (a `FakeRuntime`: no FFI, no GTK, runs anywhere):
the test is the page and the user.

```php
final class MainTest extends AppTestCase
{
    public function testThePageIsGreeted(): void
    {
        self::assertSame('Hello Ada', $this->invoke('greet', ['name' => 'Ada']));
    }

    public function testClosingAsks(): void
    {
        $this->requestClose();                       // the user clicks the window's close button
        self::assertSame([], $this->sent('window.close'));
        $this->answerDialog(true);                   // and answers the dialog PHP opened
        self::assertNotNull($this->lastSent('app.exit'));
    }
}
```

`invoke($name, $payload)` (the answer; a rejection throws), `pageSend($name, $payload)`, `pageLoaded()`,
`requestClose()`, `answerDialog($answer)`, `frames($n)`, `wait($seconds)` (real time: ReactPHP timers),
`sent($cmd)`, `lastSent($cmd)`, `$this->runtime->push($event)` (any native event), `$this->app`.

`bin/jumphp app:test <app>` runs them, then **the app for real** on a virtual screen (Xvfb, WebKitGTK, the
library): it fails on a PHP warning, an exception, or when the main page never loaded (`JUMPHP_MAX_FRAMES`,
default 240). `bin/jumphp app:shot <app> [frames] [out.png]` saves the main window's picture.

---

## Exporting: one executable

`bin/jumphp app:export <app>` makes `apps/<app>/dist/standalone/<app>-linux-x86_64`: one file, nothing to
install but WebKitGTK (as every Tauri app on Linux); `--windows` makes `<app>-windows-x86_64.exe` (Windows 10 / 11
have WebView2; its micro and library come from JumPHP's GitHub releases, see [Platforms](#platforms)). It reuses
`export:standalone` ([standalone.md](standalone.md)):

| Part | |
|---|---|
| PHP | StaticPHP's micro with `tools/app-extensions.txt` (FFI, OpenSSL, mbstring, sockets, pcntl...; no SDL): its own micro, built once in `~/.jumphp/standalone/spc-app` (~10 min), kept |
| php.ini | `ffi.enable=1`, injected in the executable (micro's ini section) |
| the app | `apps/<app>` + its vendor/, as a PHAR, unpacked on the first launch into `~/.cache/jumphp/<id>-<build>` |
| the runtime | `build/tauri/linux-x86_64/libjumphp_tauri.so`, unpacked with it into `native/linux-x86_64/` |
| the user's files | `~/.local/share/<id>` (saves, options, the WebView's storage in `webview/`) |

The computer needs **WebKitGTK 4.1** (`libwebkit2gtk-4.1-0`: Debian 12+, Ubuntu 22.04+, Fedora 39+, Arch
`webkit2gtk-4.1`) and glibc 2.34 or newer. **When it is missing, the app offers to install it** before opening
any window (`JumPHP\Tauri\LinuxWebView`): it finds the distribution (`/etc/os-release`), asks (in the terminal it
was started from, else a `zenity` / `kdialog` dialog), installs the package with the user's rights (root,
`pkexec`'s password window, or `sudo` in the terminal), then starts. Declined or impossible (no password, no
network, an immutable system, an unknown distribution): it shows the exact command to run and stops.
`JUMPHP_INSTALL=yes` installs without asking (scripts), `no` only says what to do. Tried on bare Debian 13,
Ubuntu 24.04, Fedora 42 and Arch (each installed its package, then the app opened its window), and declining in
a terminal.

The game export is untouched: without the apps' variables, `export:standalone` builds and finds the same micro as
before (same key).

### macOS: an .app

`bin/jumphp app:export <app> --macos` (from Linux, in Docker) makes `dist/standalone/<app>-macos.zip`: `<Title>.app`
with two executables (Apple silicon, Intel: micro + the app), a launcher that starts the Mac's own, `Info.plist`
(project.yaml `title`, `version`, `app.identifier`) and the icon (`assets/icon.png` as `.icns`); the universal library
inside. macOS 12 or newer. The app's cache and data go to `~/Library/Caches/JumPHP` and
`~/Library/Application Support/<id>`. It is not signed (Apple's developer account): the first time, right click on
the app > Open. The workflow `app-export` (Actions > app-export) exports an app and opens it for real on an Apple
silicon Mac and an Intel Mac (and on Ubuntu), then makes its **.dmg** (`hdiutil` only exists on a Mac): the
artifact `dmg` of the run, `<app>-macos.dmg` (the .app and a link to Applications to drag it onto).

## In the browser, on phones

The same app also runs in a web page: `bin/jumphp build <app>` makes `dist/web/` (static files, like a game's),
`bin/jumphp serve <app>` / `webshot <app>` try it. Its pages are the same files (copied to `app/`, each with
`jumphp-app-frame.js` first: the same `window.jumphp`); its PHP runs in JumPHP's WebAssembly engine
(`JumPHP\Tauri\BrowserRuntime`, `engine/bootstrap/wasm.php` starts an `App` for `ui: webview`), and
`engine/web/jumphp-app.js` does in HTML what Tauri does on the desktop:

| On the desktop | In the browser |
|---|---|
| windows | the main one fills the page; the others float over it (title bar, drag, close) |
| menus, their shortcuts, check items | a menu bar at the top (roles copy / paste / undo... act on the page) |
| `Dialog::message()` / `confirm()` | an HTML dialog |
| `FilePicker::open()` / `folder()` | the browser's file picker; the files are copied into PHP's `/tmp/open/` (their paths come back) |
| `FilePicker::save()` | a path in `/tmp/save/`: what PHP writes there is downloaded right after |
| `Notification::show()` | the Notification API (else a message in the corner) |
| `Shell::openUrl()`, `Clipboard` | a new tab, `navigator.clipboard` |
| files dropped on a page | copied into `/tmp/drop/` |
| tray icon, system-wide shortcuts, opening paths | nothing (shortcuts work while the page has the focus) |

The app's own files (`$app->storage`, options) live in the browser (IndexedDB), as games' saves. Phones: the page
works there as it is; as an Android app, `bin/jumphp twa <app> --url <its page online>` (project.yaml `pwa: true`); on an
iPhone, Safari's "Add to Home Screen" (PWA). Tried in headless Chrome with `games/hello-desktop`: the greeting from
PHP, the View menu's check item, the confirm dialog, "Save as" downloading the note PHP wrote, no error.
**Native phone apps**: `bin/jumphp android <app>` (an `.apk` / `.aab`) and `bin/jumphp ios <app>` (an `.ipa` + the
simulator's app, built on GitHub's Macs) put this browser build inside the app, shown by the phone's own WebView
(Android `WebView`, iOS `WKWebView`) from a secure origin of its own, with no network needed. What a page can not do
goes to the phone through a small bridge that `jumphp-app.js` uses when it is there: "save" = the system's save dialog
(Android) / "save to Files" (iOS), notifications, links in the browser, sharing, the clipboard; opening files is the
page's file picker, answered by the system. Android: tried in an emulator with `games/hello-desktop` (the greeting from
PHP, "Save as" opening the system's dialog). iOS: written the same way, not tried yet. (Tauri's own mobile runtime is
not used: its loop belongs to the activity / UIKit there.)

## Updates

An exported app can update itself (`JumPHP\Tauri\Updater`, `$app->updater()`): no installer, the executable is
replaced by the newer one.

```bash
bin/jumphp app:release notes --url https://example.com/notes --notes "Faster saving." --linux --windows --macos
# then upload apps/notes/dist/standalone/ (the executables + update.json) to https://example.com/notes
```

- The first time, it makes the app's signing key (ECDSA P-256) in `~/.jumphp/keys/<id>-update.pem` (never in the
  project; **keep a copy**: without it the apps already given can not be updated) and writes its public half to
  `config/update.pub` (commit it: the app checks what it downloads with it).
- `update.json`: the app's id, its `version` (project.yaml: bump it for each release), the notes, and for each system
  just exported (`linux-x86_64`, `windows-x86_64`, `macos-arm64`, `macos-x86_64`) the executable's address, size and
  SHA-256, all signed.
- The app knows where to look: project.yaml `app: {update: {url: https://example.com/notes/update.json}}`
  (`JUMPHP_UPDATE_URL` tries another one).

```php
$app->updater()->check()->then(function (?Update $update) use ($app) {     // null: up to date
    if ($update !== null && $app->updater()->isInstallable()) {             // false under `php app.php`
        Dialog::confirm("Version $update->version is out. $update->notes\nInstall it?", 'Notes', 'Install', 'Later')
            ->then(fn ($yes) => $yes ? $update->install()->then(fn () => $app->updater()->relaunch()) : null);
    }
});
```

`check()` fails on a wrong signature or another app's file; `install()` downloads, refuses a wrong SHA-256, then
replaces the executable (Windows: the running one is renamed `*.old`, removed at the next start; macOS: the
executable of the Mac's processor inside the `.app`); `relaunch()` starts it and quits. `games/hello-desktop` has
Help > Check for updates (tried for real: 0.1.0 found 0.1.1, installed it and restarted as 0.1.1).

---

## Commands

| Command | |
|---|---|
| `app:new <app> [title]` | `apps/<app>` from `skeleton-app/` |
| `app:run <app>` | the app on your screen (X11, else Wayland; the session bus for notifications; the GPU) |
| `app:test <app>` | php -l, phpstan, phpunit, then the app for real on Xvfb |
| `app:shot <app> [frames] [out.png]` | the main window after N frames (default 120) |
| `app:export <app> [--linux] [--windows] [--macos]` | one executable for Linux (default), an `.exe` for Windows, an `.app` (zip) for macOS |
| `app:release <app> --url <base> [--notes "..."] [--linux] [--windows] [--macos]` | exported + a signed `update.json` ([Updates](#updates)) |
| `tauri:setup` | the `jumphp/tauri` image: the desktop runtime + FFI + Rust + WebKitGTK headers (~5 min) |
| `tauri:build` | `tauri/` -> `build/tauri/linux-x86_64/libjumphp_tauri.so` (~5 min the first time, Cargo caches in `~/.jumphp/tauri`) |
| `tauri:download [platform...\|--all]` | the library of these sources of `tauri/` from JumPHP's GitHub releases: `linux-x86_64` (default), `windows-x86_64`, `macos-universal` |

The app commands get the library when it is missing or older than its sources: downloaded when the release of
these sources exists, else built (`JUMPHP_TAURI_BUILD=1`: always built).

`install <app>` and `composer <app> ...` are the games' commands (composer works the same).
`run`, `test` and `shot` on an app's folder hand over to `app:run`, `app:test`, `app:shot`; `pages` skips apps
(they have no browser build).

Environment: `JUMPHP_TAURI_LIB` (another library file), `JUMPHP_TAURI_TRACE=1` (every command and event on
stderr), `JUMPHP_TAURI_IMAGE`, `JUMPHP_TAURI_BUILD`, `JUMPHP_TAURI_RELEASES`, `JUMPHP_SMOKE`, `JUMPHP_MAX_FRAMES`,
`JUMPHP_SCREENSHOT`, `JUMPHP_VAR`.

Where `TauriRuntime` looks for the library: `JUMPHP_TAURI_LIB`; `<app>/native/<platform>/libjumphp_tauri.so`
(exported apps); `build/tauri/<platform>/` going up from the app (the project); `/usr/local/lib`, `/usr/lib`.

---

## Repository

| Path | |
|---|---|
| `tauri/` | the runtime (Rust crate `jumphp-tauri`, a `cdylib`): `src/lib.rs` (C API, commands, events, assets), `src/bridge.js` (`window.jumphp`), `include/jumphp_tauri.h`, `tauri.conf.json` (a minimal config: windows are made by PHP), `Cargo.lock` |
| `engine/src/Tauri/` | `App`, `Window`, `Menu`, `Tray`, `AppMain`, `Hybrid`, `Invocation`, `NativeEvent`, `NativeCommands`, `NativeRuntime`, `TauriRuntime` (FFI), `FakeRuntime` |
| `engine/src/Native/` | `Native`, `Dialog`, `FilePicker`, `Notification`, `Shell`, `Clipboard`, providers (`TauriProvider`, `SdlProvider`, `BrowserProvider`, `NullProvider`), `UnsupportedFeature` |
| `engine/src/Testing/AppTestCase.php` | tests without a window |
| `skeleton-app/` | the app template (`app:new`) |
| `games/hello-desktop/` | the example app |
| `games/hello-hybrid/` | the example hybrid game |
| `.github/workflows/tauri.yml`, `tools/tauri-key.sh` | the libraries for Linux, Windows, macOS: built, tried, published |
| `docker/tauri/Dockerfile` | the image (FROM the desktop image: same PHP 8.5 and extensions, + FFI, Rust, WebKitGTK, Xvfb tools) |
| `tools/app-commands.sh` | the `app:*` / `tauri:*` commands (sourced by `bin/jumphp`) |
| `tools/app-extensions.txt`, `tools/hybrid-extensions.txt`, `tools/new-app.php` | the micros' extensions of apps / hybrid games, `app:new`'s renaming |

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| `No Tauri runtime (linux-x86_64/libjumphp_tauri.so)` | `bin/jumphp tauri:build`, or `JUMPHP_TAURI_LIB=<file>` |
| `PHP's FFI extension is missing` | run it with `bin/jumphp app:run` (the `jumphp/tauri` image), or a PHP with `--with-ffi` and `ffi.enable=1` (or `preload`, CLI) |
| `FFI API is restricted by "ffi.enable"` | `ffi.enable=1` (exported apps have it) |
| A blank window in a screenshot | taken too early: more frames (`app:shot <app> 180`) |
| `app:test`: the main page never loaded | `ui/index.html` missing (or `app.pages` / `window.url` wrong), or a JavaScript error before the page's end: open the inspector (`devtools: true`, `app:run`) |
| `jumphp.invoke()` rejects `no command 'x'` | `$app->command('x', ...)` in `AppMain::boot()` |
| The exported app does not start: `libwebkit2gtk-4.1.so.0: cannot open shared object file` | install WebKitGTK 4.1 (`sudo apt install libwebkit2gtk-4.1-0`) |
| Nothing happens on a notification | no notification daemon / session bus (inside Docker: `app:run` mounts the session bus when it exists) |
| `Overriding existing handler for signal 10` on stderr | WebKit's own message, harmless |

A PHP *fatal* error (not an exception) during a frame cannot be caught: it ends the process (as in any PHP program).

---

## Platforms

| | Linux x86_64 | Windows x86_64 | macOS (Apple silicon + Intel) |
|---|---|---|---|
| WebView | WebKitGTK 4.1 | WebView2 (Edge; the library packs Microsoft's bootstrapper and installs it silently when it is missing, e.g. a fresh Windows 10) | WKWebView |
| runtime library | `libjumphp_tauri.so` | `jumphp_tauri.dll` | `libjumphp_tauri.dylib` (universal) |
| built and tried by | `tauri:build` here, and the workflow | the workflow | the workflow |
| `app:run` / `app:test` / `app:shot` | yes (Docker) | — (run `php app.php` with PHP's FFI) | — (same) |
| one-file export | `app:export` | `app:export --windows` | `app:export --macos` (an `.app` in a zip) |

The workflow `.github/workflows/tauri.yml` (on each change of `tauri/` or of the PHP side, or by hand) builds the
library on the three systems (Ubuntu 22.04, so glibc 2.35+; Windows; macOS, universal), runs
`games/hello-desktop` for real on each (its page must load and call PHP), and publishes the release
`tauri-<key>` (`tools/tauri-key.sh`: a hash of `tauri/`'s sources) that `tauri:download` and `app:export` use.
The micro of desktop apps for Windows and macOS is published by the workflow `standalone` run with the input
`extensions: tools/app-extensions.txt` (`tools/hybrid-extensions.txt`: hybrid games, Linux and Windows).

On Windows and macOS without Docker: a PHP 8.2+ with FFI (`ffi.enable=1`), `composer install` in the app, then
`JUMPHP_TAURI_LIB=<the library> php app.php`.

---

## Roadmap

Done: the runtime (Linux, Windows, macOS), PHP driving it from its main thread, windows, pages, page <-> PHP both
ways, native dialogs / notifications / opener / clipboard, menus, tray icon, global shortcuts, drag and drop, closing
decided by PHP, hybrid games (SDL + Tauri), tests without a window, real-window tests (Xvfb here, the three systems
on GitHub), one-file export for Linux and Windows, an `.app` and a `.dmg` for macOS, menus, updates, a hybrid game in
one window, starting with the computer.

Next:
- **Services / daemons** (an app or a CLI app running without a user session: Windows service, LaunchDaemon,
  systemd unit; docs: TODO.md).
- **Signed apps**: macOS notarization and Windows Authenticode need the developer's certificates (the Apple
  account, a code signing certificate); unsigned, the systems warn the first time.
- **iOS**: `bin/jumphp ios <app>` (a WKWebView on the browser build) is written but not tried on a Mac yet.
- More native features as Tauri plugins + `JumPHP\Native` providers.
