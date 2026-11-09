# Plugins of the JumPHP IDE

The JumPHP IDE (docs/ide.md) can be extended with **plugins written in PHP**. A plugin runs inside the IDE, on the
user's computer. It can add:

- actions: the Tools menu (or another menu), Find Action and keyboard shortcuts;
- tool windows, built from simple PHP nodes;
- run configurations;
- completion and problems for the files it chooses;
- **tools for Claude**, on the IDE's MCP server;
- listeners and timers.

You develop a plugin like any JumPHP project: one folder, `bin/jumphp test` to test it, and tests that use the
plugin without a window. Example: `games/hello-plugin` (TODO Finder).

```bash
bin/jumphp plugin:new todo-finder "TODO Finder"   # apps/todo-finder from skeleton-plugin/
bin/jumphp install todo-finder
bin/jumphp test todo-finder         # php -l, phpstan, the plugin loads in the IDE's core, its tests
bin/jumphp plugin:pack todo-finder  # apps/todo-finder/dist/todo-finder-0.1.0.zip
```

Try it in the IDE: **Tools > Plugins…** (also in File and Find Action). The window lists the installed plugins
(enable, disable, remove) and **the plugins of this project** ("Install in the IDE"). "Install from a Folder…"
takes any plugin folder. A plugin installed for the first time works at once; reload the window to see its tool
windows. A new version of a plugin that is already loaded takes effect at the next start ("Restart Now"), because
PHP cannot unload a class.

The IDE looks for plugins in two places:
- the user's plugins folder, in the IDE's data folder: `plugins/<id>/`. Its `plugins.json` keeps which plugins are
  disabled and their settings;
- the project's own plugins: `.jumphp/plugins/<id>/`, committed with the project.

Plugins run in the desktop IDE only. The web IDE (jumphp.net/ide) does not run them: a plugin is PHP, and there it
would run on the server.

## A plugin's folder

```
apps/todo-finder/
  composer.json      what the IDE reads (below)
  config/project.yaml   kind: plugin (so bin/jumphp and the IDE know this folder)
  src/TodoPlugin.php the plugin's class
  tests/             PluginTestCase tests
  phpstan.neon
```

```json
{
    "name": "jumphp-plugins/todo-finder",
    "type": "jumphp-ide-plugin",
    "description": "Lists the TODO and FIXME comments of the project.",
    "require-dev": { "jumphp/ide": "*" },
    "repositories": [{ "type": "path", "url": "../../ide", "options": { "symlink": true } }],
    "autoload": { "psr-4": { "JumphpPlugins\\TodoFinder\\": "src/" } },
    "extra": {
        "jumphp-ide": { "id": "todo-finder", "name": "TODO Finder", "version": "0.1.0", "class": "JumphpPlugins\\TodoFinder\\TodoPlugin" }
    }
}
```

- `extra.jumphp-ide`:
  - `id`: lower case letters, digits and dashes;
  - `name`: what the IDE shows;
  - `version`;
  - `class`: the class that implements `JumPHP\Ide\Plugin\Plugin`.
- The IDE loads the plugin's classes with its `autoload` (`psr-4`, `classmap`, `files`). All plugins share one
  PHP, so give each plugin a namespace of its own (`plugin:new` uses `JumphpPlugins\<Id>\`).
- A plugin has **no Composer packages of its own** when it runs. It can use PHP and the IDE's own packages
  (nikic/php-parser, symfony/yaml). `jumphp/ide` in `require-dev` is only for its tests.

## The API

```php
use JumPHP\Ide\Plugin\{Api, EditorState, Plugin, Ui};

final class TodoPlugin implements Plugin
{
    public function activate(Api $ide): void
    {
        $ide->action('count', 'Count the TODOs', fn (EditorState $at) => $ide->notify(substr_count($at->text, 'TODO') . ' TODO'),
            ['kbd' => 'Ctrl+Alt+T', 'icon' => 'check', 'menu' => 'Tools']);

        $window = $ide->toolWindow('todos', 'TODOs', fn () => Ui::column(
            Ui::input('Filter', fn (string $q) => $this->filter = $q, $this->filter),
            Ui::list(array_map(fn ($t) => Ui::item($t['text'], fn () => $ide->openFile($t['path'], $t['line']), $t['path']), $this->todos())),
        ), ['side' => 'right', 'icon' => 'check']);

        $ide->on('file.saved', fn (string $path) => $window->refresh());
        $ide->diagnostics('*.php', fn (string $path, string $text) => [['line' => 3, 'message' => '...', 'severity' => 'warning']]);
        $ide->completion('config/*.yaml', fn (EditorState $at) => [['label' => 'speed', 'insert' => 'speed: ', 'detail' => 'pixels per second']]);
        $ide->mcpTool('list_todos', 'The TODO comments of the project: path, line, text.', fn (array $args) => $this->todos($args['path'] ?? ''),
            ['path' => ['type' => 'string', 'description' => 'only under this folder']]);
        $ide->runConfiguration('assets', 'Rebuild the assets', ['bin/jumphp', 'php', 'games/x', 'tools/assets.php'], ['kind' => 'build']);
    }
}
```

| `Api` method | What it does |
|---|---|
| `action($id, $label, fn (EditorState $at), [kbd, icon, menu])` | Adds an action: Find Action, the main menu (`menu`: Tools by default; also File, Edit, View, Navigate, Code, Refactor, Run, Git) and a shortcut. If the action returns a string, the IDE shows it as a notification. |
| `toolWindow($id, $label, fn () => Ui node, [side, icon])` | Adds a tool window: a button on the stripe of `side` (left, right or bottom). The IDE calls the render function each time the window shows and after each of its callbacks. `->refresh()` draws the window again. |
| `runConfiguration($id, $label, $command, [kind, icon, group])` | Adds a run configuration to the Run widget: the command runs in the project's folder, with its output in the Run window. |
| `completion($glob, fn (EditorState) => items)` | Completion in the files that match: `label`, `insert`, `detail`, `kind` (text, keyword, value, property, method, function, class, snippet). |
| `diagnostics($glob, fn ($path, $text) => problems)` | Problems in the files that match, checked as the user types: `line`, `column`, `endLine`, `endColumn`, `message`, `severity` (warning or error). |
| `mcpTool($name, $description, fn (array $args), $properties, $required)` | Adds a tool for Claude, on the IDE's MCP server (see below). Returns its MCP name. |
| `on('file.saved' \| 'files.changed' \| 'setting.changed', fn)` | A listener. `file.saved` gets the path; `files.changed` means files changed outside the editor (git, a run); `setting.changed` gets the name and the value saved on the plugin's settings page. |
| `every($seconds, fn)` | A timer, called on the IDE's frame. |
| `notify($message, $kind)`, `openFile($path, $line)`, `showToolWindow($id)`, `run($command, $label)` | Asks the interface to show a notification, open a file at a line, show a tool window, or run a command in the Run window. |
| `workspace()` | The project's files: `files()`, `read()`, `write()`, `exists()`… Paths are relative to the project. |
| `root()` | The project's folder on this computer. |
| `core()` | The IDE's core: `->index` (the PHP index), `->php` (code intelligence: completion, definitions, usages), `->search`, `->git`. |
| `settings([$name => field, ...])` | The plugin's **settings page**: Tools › Plugins… › **Settings…** next to it. A field: `label`, `type` (text, number, bool, choice), `default`, `description`, `choices` (choice: value => label), `min` / `max` (number). The IDE draws the form and checks the values (a number is kept between min and max). |
| `setting($name, $default)`, `setSetting($name, $value)` | The plugin's settings, kept on this computer for all projects. Without a value of the user's, `setting()` gives `$default`, else the field's `default`. `on('setting.changed', fn (string $name, mixed $value) => ...)` follows the settings page. |

Globs: `*` (every file), `*.php` (a file name), `config/*.yaml` (a path).

`EditorState` describes the editor:
- `path` and `text`: the active file and its text as typed;
- `line` and `column`: the caret's position, both counted from 1;
- `selection`: the selected text;
- helpers: `lineText()`, `before()` (the line up to the caret), `word()` (the word being typed), `extension()`.

**Ui nodes** (`JumPHP\Ide\Plugin\Ui`):
- `column(...)`, `row(...)`;
- `heading()`, `text($t, $muted)`, `code()`, `separator()`;
- `button($label, fn, $icon, $primary)`, `link($label, fn)`;
- `list($items, $empty)` and `item($label, fn, $hint, $icon)`;
- `input($placeholder, fn (string), $value)`, called on Enter;
- `checkbox($label, $checked, fn (bool))`;
- `html()`: trusted HTML of the plugin's own.

The IDE draws them in its own style, in both themes. A string among the children is a text, and a `null` is
skipped. The callbacks run in PHP, and the window is drawn again after each one.

Icons use the IDE's names:
- play, test, debug, refresh, check, warning;
- file, folder, search, find, settings;
- plus, minus, close, star, globe;
- package, terminal, history, code, game, app, plugin.

## Tools for Claude (MCP)

The IDE runs an MCP server, `jumphp-ide`, for the Claude Code it starts. That covers:
- the Claude tool window;
- jumphp.net's web IDE, through `bin/jumphp claude:connect`.

Claude uses it to drive the IDE: open files, point at lines, run configurations, debug. A plugin adds its own tools
to that server with `mcpTool()`:

```php
$ide->mcpTool('list_todos', 'The TODO and FIXME comments of the project: path, line, tag, text. Use it to find what is left to do.',
    function (array $args) use ($ide): array {
        return array_values(array_filter($this->scan($ide), fn ($t) => empty($args['fixme_only']) || $t['tag'] === 'FIXME'));
    },
    ['fixme_only' => ['type' => 'boolean', 'description' => 'only the FIXME ones'],
        'path' => ['type' => 'string', 'description' => 'only under this folder of the project']],
    [],      // the required arguments
);
```

- **Name**: `$name` is lower case letters, digits and `_`. The server offers the tool as `plugin_<id>_<name>`, with
  the `-` of the id written `_`: `plugin_todo_finder_list_todos`. Claude Code sees it as
  `mcp__jumphp-ide__plugin_todo_finder_list_todos`.
- **Description**: the server puts the plugin's name in front of it, as `[TODO Finder] …`. Claude chooses its tools
  from their descriptions: say what the tool returns and when to use it.
- **Arguments**: the JSON Schema of each argument, and which ones are required.
- **What the handler returns** is what Claude reads:
  - a string;
  - an array, sent as JSON;
  - a whole MCP result, `['content' => [['type' => 'text', 'text' => '…']]]` (images too);
  - a `JumPHP\Ide\Async\Deferred` of one of these, for work that ends later.

  An exception is the tool's error: Claude reads its message.
- **Where it runs**: in PHP, in the IDE, on its frame, not in the interface. Never block. For long work, return a
  `Deferred` and settle it from `every()`.
- **Permissions**: the IDE lets its Claude use every tool of `jumphp-ide` without asking (`--allowedTools
  mcp__jumphp-ide`), so a plugin's tools run without a question. A tool that changes files or runs commands should
  say so in its description, and the plugin should ask the user itself when needed (a notification or a tool
  window).
- **When the list changes**: Claude Code reads the tool list when it starts. A plugin installed or enabled later
  gives its tools to the next conversation.
- **Where to see them**: the Plugins window lists each plugin's tools ("Tools for Claude"). `bin/jumphp test`
  counts them.

In tests:
- `$this->mcp('list_todos', ['fixme_only' => true])` calls the tool as the server does. It waits for a `Deferred`
  that settles at once.
- `$this->mcpTools()` lists the MCP names.

## The rules

- **Never block.** The plugin runs on the IDE's frame: no `sleep()`, no long loops, no network calls that wait.
  For work that takes time, do a slice at a time with `every()`, or run a command (`runConfiguration()`, `run()`).
- If the plugin throws an exception (when it activates, in an action, a window, a provider or a timer), the IDE
  shows it as a notification, and the Plugins window shows "failed" with the message. The IDE never stops because
  of it. A plugin that fails to activate loses everything it had added.
- **Only install plugins you trust.** A plugin is PHP that runs with the user's rights. The project's own plugins
  (`.jumphp/plugins/`) load when the project opens, so only open projects you trust.

## Tests

`JumPHP\Ide\Testing\PluginTestCase` runs the IDE's core, with no window and no Docker, on a project made of a few
files. The plugin is loaded in it, and the test does what a user does:

```php
final class TodoPluginTest extends PluginTestCase
{
    public function testAClickOpensTheLine(): void
    {
        $this->project(['src/Hero.php' => "<?php\n\n// TODO: jump higher\n"]);
        self::assertStringContainsString('jump higher', $this->toolText('todos'));
        $this->click('todos', 'jump higher');
        self::assertSame(['path' => 'src/Hero.php', 'line' => 3], $this->opened());
    }
}
```

| Helper | What it does |
|---|---|
| `project([$path => $content])` | A fresh project made of these files, with the plugin loaded in it. |
| `action($id, $path, $line)` | Runs an action. |
| `tool($id)` | The window's Ui nodes. |
| `toolText($id)` | The window's texts, one per line. |
| `click($tool, $label)` | Clicks a button, link, list item or check box with this label. |
| `submit($tool, $placeholder, $text)` | Types text into an input and presses Enter. |
| `complete($path, "text with | at the caret")` | The completion labels. |
| `problems($path, $text)` | The problems the plugin finds. |
| `save($path, $content)` | Saves a file as the editor does, so the plugin hears `file.saved`. |
| `wait($seconds)` | Lets time pass for the timers. |
| `mcp($name, $args)` | Calls one of the plugin's tools for Claude. |
| `mcpTools()` | The MCP names of the plugin's tools. |
| `settingsPage()` | The settings page as the IDE shows it: name => value (the defaults until changed). |
| `changeSetting($name, $value)` | The user saves a field of the settings page (`setting.changed` listeners run); returns the value kept. |
| `notifications()`, `opened()`, `ui($do)`, `errors()` | What the plugin asked the interface, and the errors it reported. |

## Sharing a plugin: jumphp.net

[jumphp.net/ide-plugins](https://jumphp.net/ide-plugins) is the plugins' marketplace.

- **Install**: Tools › Plugins…, section "On jumphp.net". Search, then Install. When a newer version is out, the
  button says "Update to x.y.z".
- **Updates**: the IDE checks jumphp.net for the plugins you installed, a little after it starts and then every 6
  hours.
  - When a newer version is out, a notification offers **Update**, **Plugins…** and **Update Automatically**.
  - With **Update plugins automatically** (in the Plugins window), the IDE installs the new versions by itself and
    tells you.
  - **Tools › Check for Plugin Updates** checks now.
  - An update takes effect at the next start ("Restart Now"), because PHP keeps the classes it loaded.
  - Only the plugins installed in the IDE are updated. A project's own plugins (`.jumphp/plugins/`) change with the
    project.
- **Publish**: Tools › Plugins…, "Plugins of this project", then "Publish on jumphp.net". Sign in with jumphp.net
  first. The IDE packs the folder as `plugin:pack` does and sends that version.
  - The first account to publish an id owns it.
  - Each version is kept. To publish again, raise `extra.jumphp-ide.version`.
  - `homepage` (or `extra.jumphp-ide.repository`), an https address, shows as the plugin's source code.
- `bin/jumphp plugin:pack <id>` makes the same zip: `dist/<id>-<version>.zip`. It holds the folder without
  `vendor/`, `tests/`, `var/` and `dist/`.
- The API:
  - public: `GET https://jumphp.net/api/plugins?q=`, `/api/plugins/<id>`, `/api/plugins/<id>/download?version=`;
  - the IDE's (its sign-in token): `POST /api/ide/plugins` (the zip as the body), `GET /api/ide/plugins/mine`,
    `DELETE /api/ide/plugins/<id>?version=`.
- **Marks**: anyone can give a plugin 1 to 5 stars, on its page (no account needed, as for the games) or in the
  IDE (Plugins window › Rate…, signed in). The average and the number of marks show on the page, in the list and in
  the IDE.
- **Statistics**:
  - public: the total downloads, those of the last 30 days, the mark;
  - private, for the plugin's author only: each of the last 30 days (page views, installs and updates from the
    IDE, .zip downloads from the site), the downloads per version, how many marks of each. They show on the
    plugin's page when the author is signed in, and in the IDE (Plugins window › Statistics, next to a plugin of
    the project);
  - `GET /api/ide/plugins/<id>/stats` (the author's token), `POST /api/ide/plugins/<id>/rating` `{"score": 4}`.
- jumphp.net never runs a plugin: it only reads its composer.json.
