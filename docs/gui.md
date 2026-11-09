# Graphic apps in PHP (JumPHP\Gui): forms without HTML

A **graphic app** is a JumPHP program whose windows are **forms**: buttons, text boxes, lists, drop-down lists, check
boxes, sliders, pictures, videos, laid out in rows, columns and a responsive grid. They are drawn by the engine (SDL),
so the same app runs, unchanged, on Linux, Windows, macOS, in the browser, and as an Android / iOS app. No HTML, no CSS.

The forms are made in the **visual editor of the JumPHP IDE** (open a `forms/*.form.yaml` file: Design view): drop
widgets, move them, set them in the inspector, and **double click a widget to write its event** in the code behind,
as in the visual tools of old.

```bash
bin/jumphp gui:new contacts "Contacts"            # a desktop app (apps/contacts, from skeleton-gui/)
bin/jumphp gui:new notes "Notes" --mobile         # a phone app (portrait window, installable)
bin/jumphp install contacts
bin/jumphp run contacts                           # the window
bin/jumphp test contacts                          # phpunit (game tests) + the smoke test
bin/jumphp shot contacts                          # var/screenshots/shot.png
bin/jumphp build contacts                         # the browser version (dist/web)
bin/jumphp export:standalone contacts --linux --windows   # executables
bin/jumphp android contacts / bin/jumphp apple contacts   # phone apps (docs/android.md, docs/apple.md)
```

## An app

```
apps/contacts/
  config/project.yaml            kind: gui, view: window (the view is the window: forms lay themselves out in it), touch: false
  forms/MainForm.form.yaml    the window: its widgets (written by the visual editor; plain YAML by hand too)
  src/Form/MainForm.php       the code behind it: a class extending JumPHP\Gui\Form (a scene)
  assets/fonts/ui.ttf         the text font (Lato, OFL; ui-bold.ttf for bold): replace them to change the look
  assets/images/              pictures for Image widgets and buttons
  tests/MainFormTest.php      tests that use the window (GameTestCase)
```

### The form file

```yaml
form:
  title: Contacts               # the window's title
  theme: light                  # or dark; accent: '#3b6ef5'; fontSize, radius, controlHeight, padding (dp); zoom
  layout: column                # the window's own layout, gap, padding, items, justify, scroll (default true)
  children:
    - {type: label, id: heading, text: Contacts, style: title}
    - type: row
      children:
        - {type: textbox, id: name, placeholder: Name, grow: 1, events: {submit: onNameSubmit}}
        - {type: button, id: add, text: Add, style: primary, default: true, events: {click: onAddClick}}
    - {type: listbox, id: people, height: fill, events: {change: onPeopleChange}}
```

### The code behind

```php
/**
 * @property TextBox $name          (the visual editor keeps these lines in step with the widgets' ids)
 * @property Button $add
 * @property ListBox $people
 */
final class MainForm extends Form
{
    protected function loaded(array $params = []): void    // the widgets are made (each time the form is shown)
    {
        $this->people->items = $this->engine->storage->readJson('people.json', []);
    }

    public function onAddClick(Button $sender): void
    {
        $this->people->addItem($this->name->text);
        $this->name->text = '';
    }
}
```

Widgets by id: `$this->add`, `$this->widget('add')`. Properties are read and written as fields
(`$this->add->enabled = false`); a change lays the form out again. Widgets made in code:
`$this->root->add(new Button(['id' => 'more', 'text' => 'More', 'events' => ['click' => 'onMoreClick']]))`, or
`$button->on('click', fn (Button $b) => ...)`.

Other windows: `$this->scenes()->switchTo(OtherForm::class)`. A dialog over the window: a form with `dialog: true`
(and `width`) pushed with `$this->scenes()->push(AskForm::class)`, closed with `$this->close($result)`; the window under
it gets `resume($result)`. Each frame: `tick(float $dt)`. Everything of the engine is there: `$this->engine->storage`,
`http()`, `tasks()`, `sound()`, timers (AGENTS.md).

## Layout: rows, columns, a grid, spacers

Sizes are **dp** (density-independent pixels): a button is as big on a HiDPI screen or a phone as on a plain screen.

| Container | Lays out its children |
|---|---|
| `column` (default) | one under the other |
| `row` | side by side, centred vertically; `wrap: true`: on several lines when the row is full |
| `grid` | in 12 columns (`columns`); each child takes `span` of them, **per screen width** |
| `stack` | on top of each other (a text over a picture) |
| `panel` | a column with a frame and a `title` |

Container settings: `gap` (between children), `padding`, `items` (start / center / end / stretch: across),
`justify` (start / center / end / between / around: along), `background`, `border`, `scroll` (a long content scrolls:
drag it, its bar or the mouse wheel).

Child settings: `width` / `height` = a number (dp), `auto` (what its content needs) or `fill` (all the room);
`grow` (shares the free room of a row or column: `grow: 1` and `grow: 2` = a third and two thirds); `align` (itself,
across); `minWidth`, `minHeight`; `visible`, `enabled`, `tooltip`.

A **spacer** without `size` takes the free room: one before a button pushes it to the end of a row, one on each side
centres it. With `size: 24` it is a fixed gap.

**Responsive**: the screen width in dp gives a breakpoint, `xs` (< 600, phones), `sm` (< 900, tablets), `md`
(< 1200), `lg`. In a grid, `span: {xs: 12, md: 6}` = the whole width on a phone, half from 900 dp; `hide: [xs]` hides a
widget on phones. The visual editor shows each width (desktop window, laptop, tablet, iPhone, Android; drag the desktop
window's edge).

## Widgets

| Type | Properties | Events (the first: double click) |
|---|---|---|
| label | text, style (title / subtitle / muted / small), size, bold, color, textAlign, wrap | click |
| button | text, style (default / primary / danger / flat), image, default (Return), cancel (Escape) | click |
| textbox | text, placeholder, password, multiline, rows, maxLength, readonly | change (string $text), submit, click |
| checkbox | text, checked | change (bool $checked), click |
| radio | text, checked, group (exclusive in the group, default the container) | change (bool $checked), click |
| combobox | items, selected, placeholder; `value()` | change (int $index, string $item), click |
| listbox | items, selected; `value()`, `addItem()`, `removeItem()`, `select()` | change (int, string), activate (double click / Return), click |
| slider | value, min, max, step | change (float $value) |
| progressbar | value, max, showText | click |
| image | src (assets/... or any file: `load($path)`), fit (contain / cover / fill / none) | click |
| video | src (a film of assets/videos/, or a folder of frames), fps, audio, autoplay, loop, controls; `play()`, `pause()`, `stop()`, `seek()` | end, click |
| spacer | size | |
| separator | | |
| column, row, grid, stack, panel | see Layout | click |

A widget of the app: a class extending `JumPHP\Gui\Widget` (`content()` = its size, `render()`), used with
`class: App\Gui\Gauge` in the form file.

**Video**: put the film in `assets/videos/` (`intro.mp4`, `.webm`, `.mov`, `.mkv`, `.avi`) and give it as `src:
assets/videos/intro.mp4`. SDL decodes no video stream, so `bin/jumphp` cuts it into pictures + its sound
(`assets/videos/intro/00001.jpg...`, `audio.ogg`; ffmpeg, in Docker) at the widget's `fps` before `run`, `test`, `shot`,
the builds and the exports, again only when the film changes; the packages carry the pictures, not the film. It then plays
the same on a PC, a Mac, a phone and the web. Commit the `assets/videos/intro/` folder too: the JumPHP playground and
the web IDE do not cut films. `bin/jumphp video contacts ~/film.mp4` does it by hand for a film from elsewhere.

## Keyboard, mouse, touch

Click / tap, double click, drag (sliders, lists, scrolled areas: a finger scrolls them), Tab / Shift+Tab between the
fields, Return clicks the `default: true` button (or submits a text box), Escape the `cancel: true` one (or closes a
dialog), arrows in lists / drop-down lists / sliders, held keys repeat, Ctrl+C / Ctrl+V in text boxes (where the target
has a clipboard). On phones the system keyboard opens with a text box.

The mouse wheel (and two fingers on a touchpad) scrolls what is under the pointer: a list, an open drop-down list, else
the scrolled area around it. Text comes from the system's text input: the keyboard's layout, dead keys (`^` then `e` = `ê`)
and input methods (Chinese, Japanese, Korean...: what they compose is shown underlined at the cursor, their window
opens under it). Both need the desktop image and the browser engine built with `docker/ext/php-sdl-events.patch`
(`bin/jumphp setup`, `bin/jumphp engine:build` or a newer `engine:download`): with older ones there is no wheel and the characters come from the
key codes.

## Tests

```php
final class MainFormTest extends GameTestCase
{
    public function testAddingAName(): void
    {
        $this->game->start(MainForm::class);
        $form = $this->game->scene();
        $form->click($form->widget('name'));       // focus
        $form->type("Ada\n");                       // \n = Return, \t = Tab
        $form->click($form->widget('add'));
        self::assertSame(['Ada'], $form->widget('people')->props['items']);
    }
}
```

`click($widget, $double)`, `clickAt($x, $y)`, `type($text)`, `key(Pointer::DOWN)`. A form also lays out without
SDL (`new MainForm()`, `build($definition)`, `layoutTo($w, $h)`): plain PHPUnit tests of a layout.

## Text fields: selection, words, clipboard, right click

A `textbox` works like the system's: Shift + arrows / Home / End select, Ctrl + arrows jump by words (Ctrl + Shift: select words), Ctrl + Backspace / Ctrl + Delete delete
the word before / after the cursor, Ctrl + A selects everything, Ctrl + C / X / V copy, cut, paste (Shift + Delete cuts), a double click selects the word, a
drag selects, and a **right click** opens a small menu (Cut, Copy, Paste, Select all). A password is never copied. Typing replaces the selection.
The clipboard is the system's: on Linux it uses `wl-copy` / `wl-paste` or `xclip` (or `xsel`) (they are in the Docker runtime image of `bin/jumphp run`; an
exported Linux executable needs one of them installed), `pbcopy` / `pbpaste` on macOS, PowerShell on Windows, the browser's clipboard on the web.
A widget of your own with a context menu uses the trait `JumPHP\Gui\HasContextMenu` (rows: `menuItems()`, what they do: `runMenu()`).

## The interface's size follows the screen

On a HiDPI screen (a density above 1) the forms scale by themselves; on X11 and Windows, which do not report one, the scale of the desktop's own setting is used
(`Xft.dpi`, `GDK_SCALE`), or `JUMPHP_GUI_SCALE=1.5`. With a desktop that stretches a smaller picture to the screen (xrandr `--scale`, bilinear), every
text is blurred whatever the program: use the screen's own resolution and the DPI setting instead (Xft.dpi 144 for 150 %): the forms then draw at the real pixels.

## Graphics card in Docker (`bin/jumphp run`)

The runtime image has Mesa 25 (bookworm-backports) and the Vulkan drivers, and the host's `/dev/dri` is passed. A recent AMD GPU (gfx1150 and above, e.g. Strix Halo)
is unknown to the image's LLVM: `bin/jumphp run` then sets `MESA_LOADER_DRIVER_OVERRIDE=zink` (OpenGL over Vulkan) so that the GPU draws, not the CPU (a big
window lagged). `JUMPHP_MESA_DRIVER=<driver>` (also in `.env`) forces another driver: `radeonsi`, `iris`, `llvmpipe` (the CPU)...

## Choisir un fichier du visiteur dans le navigateur

`JumPHP\Native\FilePicker::open()` marche dans le navigateur (`Native::supports('open')`) : appelé depuis un clic, il ouvre le sélecteur de fichier du système,
écrit le fichier choisi dans la mémoire du moteur (`/tmp/jumphp-picked/<nom>`) et rend ce chemin ; lisez-le, gardez ce qu'il faut, supprimez-le. Dossier et
« enregistrer sous » : pas dans le navigateur. Exemple : le choix de la clé SSH de `jumphpsql`. Une application (`kind: gui`) démarre sans l'écran « click / tap to play ».
