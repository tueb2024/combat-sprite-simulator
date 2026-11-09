<?php

namespace JumPHP\Gui;

use JumPHP\Kernel\Platform;
use JumPHP\Native\Clipboard;
use JumPHP\Renderer\Renderer;
use JumPHP\Renderer\Sdl;
use JumPHP\Scene\AbstractScene;
use Symfony\Component\Yaml\Yaml;

/**
 * A window of a graphic PHP app (no HTML): a scene that shows the widgets of its form file, made in the IDE's visual
 * editor (forms/<ClassName>.form.yaml), and the code behind them, in the class:
 *
 *   /** @property Button $save  @property TextBox $name *\/
 *   final class MainForm extends Form
 *   {
 *       protected function loaded(): void { $this->name->text = 'Ada'; }        // once its widgets are made (each enter())
 *       public function onSaveClick(Button $sender): void { $this->status->text = 'Saved ' . $this->name->text; }
 *   }
 *
 * The form file's `events: {click: onSaveClick}` wire a widget's event to a method (a double click in the editor
 * writes both). Widgets by id: `$this->save`, `$this->widget('save')`; made from code: `$this->root->add(new Button([...]))`.
 * Another window: `$this->scenes()->switchTo(OtherForm::class)`; a dialog over it: push() a form with
 * `isOverlay()` true (`dialog: true` in its file), close it with `$this->close($result)`.
 * Sizes are dp: the theme's scale makes them as big on every screen; the layout follows the window's width
 * (breakpoints xs / sm / md / lg, Container::span).
 */
abstract class Form extends AbstractScene
{
    public Theme $theme;
    public ?Container $root = null;
    /** the widget whose drop-down is open (it gets the pointer first) */
    public ?Widget $overlay = null;
    /** drawn in the visual editor: spacers show */
    public bool $designing = false;

    /** the form file, relative to the app; '' = forms/<short class name>.form.yaml */
    protected string $file = '';

    /** @var array<string, mixed> the form's own settings (title, theme, dialog, width...) */
    protected array $settings = [];

    /** @var array<string, Widget> */
    private array $byId = [];
    private bool $dirty = true;
    /** @var array{0: float, 1: float} */
    private array $laidOut = [0, 0];
    private string $breakpoint = 'xs';
    private ?Widget $hover = null;
    private ?Widget $pressedWidget = null;
    private ?Widget $focus = null;
    private float $clock = 0;
    /** @var array{0: float, 1: float, 2: float} time, x, y of the last press */
    private array $lastDown = [-10, 0, 0];
    private bool $doubleArmed = false;
    /** @var array{0: float, 1: float} where the press started */
    private array $pressFrom = [0, 0];
    private ?int $repeatKey = null;
    private float $repeatAt = 0;
    private float $width = 0;
    private float $height = 0;

    public function __construct()
    {
        $this->theme = new Theme();
    }

    // the form file ----------------------------------------------------------------------------------------------

    /** The form file (relative to the app). */
    public function file(): string
    {
        return $this->file !== '' ? $this->file : 'forms/' . (new \ReflectionClass($this))->getShortName() . '.form.yaml';
    }

    public function enter(array $params = []): void
    {
        $file = $this->file();
        $path = isset($this->engine) ? $this->engine->config->path($file) : $file;
        $definition = is_file($path) ? (Yaml::parseFile($path)['form'] ?? []) : [];
        $this->build(is_array($definition) ? $definition : []);
        $this->loaded($params);
        $this->root?->emit('load', $params);
    }

    /** Called once the widgets are made (each enter(): $params are the scene's). */
    protected function loaded(array $params = []): void
    {
    }

    /**
     * Makes the widgets of a form definition (what is under `form:` in the file) and wires their events to the methods
     * of this class.
     *
     * @param array<string, mixed> $definition
     */
    public function build(array $definition): void
    {
        $this->settings = array_diff_key($definition, ['children' => 1, 'events' => 1]);
        $this->theme = new Theme($this->sdlOrNull(), (string)($definition['theme'] ?? 'light'));
        foreach (['fontSize', 'radius', 'controlHeight', 'padding'] as $size) {
            if (isset($definition[$size])) {
                $this->theme->$size = (float)$definition[$size];
            }
        }
        if (isset($definition['accent'])) {
            $this->theme->colors['accent'] = $this->theme->colors['focus'] = (string)$definition['accent'];
        }
        $this->byId = [];
        $this->hover = $this->pressedWidget = $this->focus = $this->overlay = null;
        $rootDef = array_intersect_key($definition, array_flip(['layout', 'gap', 'padding', 'background', 'justify', 'items', 'columns', 'scroll', 'children', 'events', 'wrap']))
            + ['type' => 'container', 'padding' => 16, 'gap' => 12, 'background' => $this->theme->colors['background'], 'scroll' => true];
        $root = FormLoader::widget($rootDef);
        if (!$root instanceof Container) {
            throw new \LogicException('The root of a form is a container');
        }
        $this->root = $root;
        $this->adopt($root);
        $this->dirty = true;
        if (isset($this->engine) && !empty($definition['title']) && !$this->isOverlay()) {
            $window = $this->engine->sdl->getWindow()?->getWindow();
            if ($window) {
                \SDL_SetWindowTitle($window, (string)$definition['title']);
            }
        }
    }

    /** A widget joins the form (and its children): ids known, events wired. */
    public function adopt(Widget $widget): void
    {
        $widget->form = $this;
        if ($widget->props['id'] !== '') {
            $this->byId[(string)$widget->props['id']] = $widget;
        }
        foreach ((array)($widget->props['events'] ?? []) as $event => $method) {
            if (is_string($method) && method_exists($this, $method)) {
                $widget->off((string)$event);
                $widget->on((string)$event, fn (Widget $sender, mixed ...$args) => $this->$method($sender, ...$args));
            }
        }
        if ($widget instanceof Container) {
            foreach ($widget->children as $child) {
                $child->parent = $widget;
                $this->adopt($child);
            }
        }
        $this->dirty = true;
    }

    public function widget(string $id): ?Widget
    {
        return $this->byId[$id] ?? null;
    }

    /** @return array<string, Widget> */
    public function widgets(): array
    {
        return $this->byId;
    }

    public function __get(string $name): mixed
    {
        if (isset($this->byId[$name])) {
            return $this->byId[$name];
        }
        trigger_error('No widget "' . $name . '" in ' . $this->file(), E_USER_WARNING);
        return null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->byId[$name]);
    }

    /** @return mixed a setting of the form file (title, dialog, width...) */
    public function setting(string $name, mixed $default = null): mixed
    {
        return $this->settings[$name] ?? $default;
    }

    public function isOverlay(): bool
    {
        return (bool)($this->settings['dialog'] ?? false);
    }

    /** A dialog closes (it goes back to the form under it, with a result: resume($result) there). */
    public function close(mixed $result = null): void
    {
        $this->scenes()->pop($result);
    }

    /** The engine once the scene is started, null before (widgets of the app use it: a badge asks the engine's services). */
    public function engineOrNull(): ?\JumPHP\Kernel\Engine
    {
        return isset($this->engine) ? $this->engine : null;
    }

    public function sdlOrNull(): ?Sdl
    {
        return isset($this->engine) ? $this->engine->sdl : null;
    }

    public function invalidate(): void
    {
        $this->dirty = true;
    }

    public function setOverlay(?Widget $widget): void
    {
        $this->overlay = $widget;
    }

    public function viewWidth(): float
    {
        return $this->width;
    }

    public function viewHeight(): float
    {
        return $this->height;
    }

    public function breakpoint(): string
    {
        return $this->breakpoint;
    }

    public function focused(): ?Widget
    {
        return $this->focus;
    }

    // layout -----------------------------------------------------------------------------------------------------

    /** Lays the widgets out in a view of this size (pixels); done by itself when the window or a property changes. */
    public function layoutTo(float $width, float $height): void
    {
        if ($this->root === null) {
            return;
        }
        $this->width = $width;
        $this->height = $height;
        $this->breakpoint = Container::breakpoint($width / max(0.01, $this->theme->scale));
        [$x, $y, $w, $h] = $this->frameBox($width, $height);
        $this->root->layout($this->theme, $x, $y, $w, $h, $this->breakpoint);
        $this->laidOut = [$width, $height];
        $this->dirty = false;
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} where the root goes: the whole view, or a dialog's box */
    private function frameBox(float $width, float $height): array
    {
        if (!$this->isOverlay() || $this->root === null) {
            return [0, 0, $width, $height];
        }
        $t = $this->theme;
        $w = min($width - $t->px(32), $t->px((float)($this->settings['width'] ?? 420)));
        [, $h] = $this->root->measure($t, $w, Container::breakpoint($width / max(0.01, $t->scale)));
        $h = min($h, $height - $t->px(32));
        return [($width - $w) / 2, ($height - $h) / 2, $w, $h];
    }

    /** The scale of the theme: the screen's density (HiDPI, phones) times the app's zoom (`zoom` in the form file). */
    private static ?float $systemScale = null;

    /** The interface scale the user's system asks for (1 when it says nothing), read once. */
    private static function systemScale(): float
    {
        if (self::$systemScale !== null) {
            return self::$systemScale;
        }
        $scale = (float)(getenv('JUMPHP_GUI_SCALE') ?: 0);
        if ($scale <= 0 && PHP_OS_FAMILY === 'Linux' && !\JumPHP\Kernel\Platform::isWasm() && getenv('DISPLAY') && function_exists('shell_exec')) {
            $scale = self::scaleFromXrdb((string)@shell_exec('xrdb -query 2>/dev/null'));
        }
        if ($scale <= 0 && getenv('GDK_SCALE')) {
            $scale = (float)getenv('GDK_SCALE') * (float)(getenv('GDK_DPI_SCALE') ?: 1);
        }
        return self::$systemScale = $scale > 0 ? max(1.0, min(4.0, round($scale * 4) / 4)) : 1.0;
    }

    /** "Xft.dpi: 144" in the output of `xrdb -query` -> 1.5 (96 dpi is 1), 0 when it is not there. */
    public static function scaleFromXrdb(string $xrdb): float
    {
        return preg_match('/^Xft\.dpi:\s*([0-9.]+)/m', $xrdb, $m) ? (float)$m[1] / 96 : 0.0;
    }

    private function updateScale(): void
    {
        if (!isset($this->engine)) {
            return;
        }
        $density = 1.0;
        $window = $this->engine->sdl->getWindow()?->getWindow();
        if ($window && $this->engine->config->get('view') === 'window') {
            // the view follows the window: one view pixel = one screen pixel (2 or 3 per dp on HiDPI screens and phones)
            $ww = $wh = 0;
            \SDL_GetWindowSize($window, $ww, $wh);
            [$ow] = $this->engine->sdl->getRenderer()->outputSize();
            if ($ww > 0 && $ow > 0) {
                $density = $ow / $ww;
            }
            // X11 and Windows do not report a HiDPI screen as a density: the user's own setting (Xft.dpi, GDK_SCALE, JUMPHP_GUI_SCALE) says how
            // big the interface should be, so it is drawn at the screen's real pixels (sharp) instead of tiny, or stretched by the desktop (blurred)
            if ($density < 1.05) {
                $density *= self::systemScale();
            }
        } elseif ($window === null && $this->engine->config->get('view') === 'window') {
            // headless (tests, screenshots): a surface twice the window's size, as a HiDPI screen
            [$ow] = $this->engine->sdl->getRenderer()->outputSize();
            $density = $ow > 0 ? $ow / max(1, $this->engine->config->width()) : 1.0;
        }
        $scale = max(0.5, $density * (float)($this->settings['zoom'] ?? $this->engine->options->get('gui_zoom', 1.0)));
        if (abs($scale - $this->theme->scale) > 0.01) {
            $this->theme->scale = $scale;
            $this->dirty = true;
        }
    }

    // a frame ------------------------------------------------------------------------------------------------------

    public function update(float $dt): void
    {
        $this->clock += $dt;
        $this->updateScale();
        $w = isset($this->engine) ? (float)$this->width() : $this->width;
        $h = isset($this->engine) ? (float)$this->height() : $this->height;
        if ($this->dirty || $this->laidOut !== [$w, $h]) {
            $this->layoutTo($w, $h);
        }
        if (isset($this->engine)) {
            $this->handle($this->readInput());
        }
        foreach ($this->root?->walk() ?? [] as $widget) {
            $widget->update($dt);
        }
        $this->tick($dt);
        if (isset($this->engine)) {
            $this->syncKeyboard();
        }
    }

    private ?bool $keyboardOn = null;

    /** Browser: tells the page when a text field has the focus, so that it shows the phone's on-screen keyboard (jumphp-keyboard.js). */
    private function syncKeyboard(): void
    {
        $want = $this->focus !== null && $this->focus->wantsText();
        if ($want !== $this->keyboardOn) {
            $this->keyboardOn = $want;
            $this->engine->bridge->send('textinput', ['on' => $want]);   // does nothing outside the browser
        }
    }

    /** Each frame, after the widgets (timers, animations of the app). */
    protected function tick(float $dt): void
    {
    }

    public function render(Renderer $r): void
    {
        if ($this->root === null) {
            return;
        }
        if ($this->dirty) {
            $this->layoutTo($this->width ?: $r->getWidth(), $this->height ?: $r->getHeight());
        }
        if ($this->isOverlay()) {
            $r->fillRect(0, 0, $r->getWidth(), $r->getHeight(), [0, 0, 0, 110]);
            $t = $this->theme;
            $root = $this->root;
            $t->box($r, $root->x + $t->px(2), $root->y + $t->px(4), $root->w, $root->h, [0, 0, 0, 50], $t->px($t->radius * 2));
            $t->box($r, $root->x, $root->y, $root->w, $root->h, $t->color('surface'), $t->px($t->radius * 2));
            $saved = $root->props['background'];
            $root->props['background'] = '';
            $root->render($r, $this->theme);
            $root->props['background'] = $saved;
        } else {
            $r->clear($this->theme->color('background'));
            $this->root->render($r, $this->theme);
        }
        $this->root->renderOverlay($r, $this->theme);
        $this->renderTooltip($r);
        $this->placeInputMethod($r);
    }

    /** @var list<int>|null where the input method's window was put */
    private ?array $imeAt = null;

    /** The window of an input method (Chinese, Japanese...: its candidates) opens under the cursor of the text box. */
    private function placeInputMethod(Renderer $r): void
    {
        $box = $this->focus instanceof TextBox ? $this->focus->caret : null;
        if ($box === null || !isset($this->engine) || !function_exists('SDL_SetTextInputRect') || !class_exists('SDL_Rect')) {
            return;
        }
        $offset = $this->focus->parent?->scrollOffset() ?? 0;
        [$x1, $y1] = $r->viewToWindow($box[0], $box[1] - $offset);
        [$x2, $y2] = $r->viewToWindow($box[0] + $box[2], $box[1] + $box[3] - $offset);
        $at = [(int)$x1, (int)$y1, max(1, (int)($x2 - $x1)), max(1, (int)($y2 - $y1))];
        if ($at !== $this->imeAt) {
            $this->imeAt = $at;
            \SDL_SetTextInputRect(new \SDL_Rect(...$at));
        }
    }

    private function renderTooltip(Renderer $r): void
    {
        $tip = (string)($this->hover?->props['tooltip'] ?? '');
        $tip = $tip === '' ? '' : \JumPHP\Util\Lang::t($tip);
        if ($tip === '' || $this->pressedWidget !== null || !isset($this->engine)) {
            return;
        }
        $t = $this->theme;
        $font = $t->font($t->fontSize * 0.85);
        $mouse = $this->engine->input->getMouse();
        $w = $font->width((string)$tip) + $t->px(12);
        $h = $font->lineHeight() + $t->px(8);
        $x = min($mouse->x + $t->px(12), $r->getWidth() - $w - 2);
        $y = min($mouse->y + $t->px(18), $r->getHeight() - $h - 2);
        $t->box($r, $x, $y, $w, $h, $t->colors['text'], $t->px(4));
        $font->draw($r, (string)$tip, $x + $t->px(6), $y + $t->px(4), 1, $t->color('background'));
    }

    // input ------------------------------------------------------------------------------------------------------

    private const SHIFT_US = ['1' => '!', '2' => '@', '3' => '#', '4' => '$', '5' => '%', '6' => '^', '7' => '&', '8' => '*', '9' => '(',
        '0' => ')', '-' => '_', '=' => '+', '[' => '{', ']' => '}', '\\' => '|', ';' => ':', "'" => '"', ',' => '<', '.' => '>',
        '/' => '?', '`' => '~'];
    private const KEYPAD = [1073741922 => '0', 1073741913 => '1', 1073741914 => '2', 1073741915 => '3', 1073741916 => '4',
        1073741917 => '5', 1073741918 => '6', 1073741919 => '7', 1073741920 => '8', 1073741921 => '9', 1073741923 => '.',
        1073741908 => '/', 1073741909 => '*', 1073741910 => '-', 1073741911 => '+'];
    /** keys repeated while held */
    private const REPEAT = [Pointer::BACKSPACE, Pointer::DELETE, Pointer::LEFT, Pointer::RIGHT, Pointer::UP, Pointer::DOWN];

    /** This frame's pointer and keys, from the engine's input. */
    private function readInput(): Pointer
    {
        $p = new Pointer();
        $mouse = $this->engine->input->getMouse();
        $keyboard = $this->engine->input->getKeyboard();
        $p->x = $mouse->x;
        $p->y = $mouse->y;
        $p->down = $mouse->isPressed(1);
        $p->up = $mouse->isReleased(1);
        $p->held = $mouse->isHeld(1);
        $p->right = $mouse->isPressed(3);
        $p->wheel = $mouse->wheelY;
        $mods = (int)\SDL_GetModState();
        // the keys' own state too: the binding's modifier state is empty in some places, and a quick tap is over before the frame reads it
        $p->shift = ($mods & 3) !== 0 || $keyboard->shiftHeld() || $keyboard->typedWithShift();
        $p->ctrl = ($mods & (192 | 3072)) !== 0 || $keyboard->ctrlHeld() || $keyboard->typedWithCtrl();
        $caps = ($mods & 8192) !== 0;
        $typed = $keyboard->getTyped();
        // held keys repeat (the keyboard only reports the first press)
        $last = $keyboard->getLastKeyPressed();
        if ($typed !== []) {
            $this->repeatKey = end($typed);
            $this->repeatAt = $this->clock + 0.45;
        } elseif ($last !== null && $last === $this->repeatKey && $keyboard->isKeyHeld($last) && $this->clock >= $this->repeatAt
            && (in_array($last, self::REPEAT, true) || (self::character($last) !== null && !$keyboard->hasTextEvents()))) {
            $typed = [$last];
            $this->repeatAt = $this->clock + 0.035;
        } elseif ($last === null) {
            $this->repeatKey = null;
        }
        // the system's text input (the keyboard's layout, dead keys, input methods): the characters come from it, the
        // keys that type one are left out; without it (older engines), the characters come from the key codes
        $system = $keyboard->hasTextEvents();
        foreach ($typed as $key) {
            $char = $p->ctrl ? null : self::character($key, $p->shift, $caps);
            if ($char === null) {
                $p->keys[] = $key;
            } elseif (!$system) {
                $p->text[] = $char;
            }
        }
        if ($system) {
            foreach (mb_str_split($keyboard->getText()) ?: [] as $char) {
                if ($char >= ' ' && $char !== "\x7f") {
                    $p->text[] = $char;
                }
            }
            if ($this->focus instanceof TextBox) {
                $this->focus->composition = $keyboard->getComposition();
            }
        }
        return $p;
    }

    /** The character a key types (SDL key codes are the characters of the keyboard's layout), null for the others. */
    public static function character(int $key, bool $shift = false, bool $caps = false): ?string
    {
        if (isset(self::KEYPAD[$key])) {
            return self::KEYPAD[$key];
        }
        if ($key < 32 || $key === 127 || $key >= 0x40000000 || $key > 0x10FFFF) {
            return null;
        }
        $char = mb_chr($key, 'UTF-8');
        if ($char === false) {
            return null;
        }
        if ($shift && isset(self::SHIFT_US[$char])) {
            return self::SHIFT_US[$char];
        }
        return $shift !== $caps ? mb_strtoupper($char) : $char;
    }

    /** One frame of input: the widget under the pointer, the one pressed, the one with the focus. */
    public function handle(Pointer $p): void
    {
        if ($this->root === null) {
            return;
        }
        $target = $this->targetAt($p->x, $p->y);
        if ($this->hover !== $target) {
            if ($this->hover !== null) {
                $this->hover->hovered = false;
            }
            $this->hover = $target;
            if ($target !== null) {
                $target->hovered = true;
            }
        }
        if ($this->overlay instanceof Overlay && $target === $this->overlay && !$p->held) {
            $this->overlay->hoverAt($this->local($p, $this->overlay)->y);
        }

        if ($p->right && $target instanceof Overlay && method_exists($target, 'openMenu') && $target->isEnabled()) {
            if ($this->overlay instanceof Overlay && $this->overlay !== $target) {
                $this->overlay->close();
            }
            $this->setFocus($target);
            $target->openMenu($p->x, $p->y);
        }
        if ($p->down) {
            $this->doubleArmed = $this->clock - $this->lastDown[0] < 0.4 && abs($p->x - $this->lastDown[1]) < 8 && abs($p->y - $this->lastDown[2]) < 8;
            $this->lastDown = [$this->clock, $p->x, $p->y];
            $this->pressFrom = [$p->x, $p->y];
            if ($this->overlay !== null && $target !== $this->overlay && $this->overlay instanceof Overlay) {
                $this->overlay->close();
            }
            $this->pressedWidget = $target;
            if ($target !== null) {
                $target->pressed = true;
                if ($target->focusable()) {
                    $this->setFocus($target);
                } elseif ($target !== $this->overlay) {
                    $this->setFocus(null);
                }
                $target->pointerDown($this->local($p, $target));
            }
        } elseif ($p->held && $this->pressedWidget !== null) {
            $this->dragToScroll($p);
            $this->pressedWidget?->pointerDrag($this->local($p, $this->pressedWidget));
        }
        if ($p->up && $this->pressedWidget !== null) {
            $widget = $this->pressedWidget;
            $p->double = $this->doubleArmed;
            $local = $this->local($p, $widget);
            $inside = $target === $widget || ($widget === $this->overlay && $widget->overlayContains($local->x, $local->y));
            $widget->pressed = false;
            $this->pressedWidget = null;
            $widget->pointerUp($local, $inside);
        }

        if ($p->wheel != 0) {
            $this->wheel($p, $target);
        }
        $this->keys($p);
    }

    /** The wheel: the widget under the pointer scrolls (a list, an open list), or else the scrolled container around it. */
    private function wheel(Pointer $p, ?Widget $target): void
    {
        for ($w = $target; $w !== null; $w = $w->parent) {
            if ($w->wheel($p->wheel)) {
                return;
            }
        }
    }

    /** A press on a widget inside a scrolled container that moves: it scrolls the container (phones). */
    private function dragToScroll(Pointer $p): void
    {
        $widget = $this->pressedWidget;
        if ($widget === null || $widget instanceof Container || $widget instanceof Slider || $widget instanceof ListBox || $widget === $this->overlay) {
            return;
        }
        if (abs($p->y - $this->pressFrom[1]) < $this->theme->px(10)) {
            return;
        }
        for ($c = $widget->parent; $c !== null; $c = $c->parent) {
            if ($c->isScrolling()) {
                $widget->pressed = false;
                $this->pressedWidget = $c;
                $start = clone $p;
                $start->y = $this->pressFrom[1];
                $c->pointerDown($this->local($start, $c));
                return;
            }
        }
    }

    private function targetAt(float $x, float $y): ?Widget
    {
        if ($this->overlay !== null && $this->overlay->overlayContains($x, $y + ($this->overlay->parent?->scrollOffset() ?? 0))) {
            return $this->overlay;
        }
        $found = $this->root?->find($x, $y, $this->breakpoint);
        if ($found === null && $this->isOverlay()) {
            return null;            // outside a dialog
        }
        return $found;
    }

    /** The pointer in the coordinates of a widget's content (its containers may be scrolled). */
    private function local(Pointer $p, Widget $widget): Pointer
    {
        $offset = $widget->parent?->scrollOffset() ?? 0;
        if ($offset == 0) {
            return $p;
        }
        $local = clone $p;
        $local->y += $offset;
        return $local;
    }

    public function setFocus(?Widget $widget): void
    {
        if ($this->focus === $widget) {
            return;
        }
        if ($this->focus !== null) {
            $this->focus->focused = false;
            $this->focus->blur();
            $this->focus->emit('blur');
        }
        $this->focus = $widget;
        if ($widget !== null) {
            $widget->focused = true;
            $widget->emit('focus');
        }
        // phones: the on-screen keyboard while a text box has the focus
        if (isset($this->engine) && function_exists('SDL_StartTextInput')) {
            $widget instanceof TextBox ? \SDL_StartTextInput() : \SDL_StopTextInput();
        }
    }

    private function keys(Pointer $p): void
    {
        if ($p->keys === [] && $p->text === []) {
            return;
        }
        $used = $this->focus?->keys($p) ?? false;
        if ($used) {
            return;
        }
        if ($p->pressed(Pointer::TAB)) {
            $this->moveFocus($p->shift ? -1 : 1);
        } elseif ($p->pressed(Pointer::RETURN) || $p->pressed(Pointer::KP_ENTER)) {
            $this->buttonWith('default')?->emit('click');
        } elseif ($p->pressed(Pointer::ESCAPE)) {
            $cancel = $this->buttonWith('cancel');
            if ($cancel !== null) {
                $cancel->emit('click');
            } elseif ($this->isOverlay()) {
                $this->close();
            }
        }
    }

    private function buttonWith(string $flag): ?Widget
    {
        foreach ($this->root?->walk() ?? [] as $widget) {
            if ($widget instanceof Button && !empty($widget->props[$flag]) && $widget->isEnabled() && $widget->isShown($this->breakpoint)) {
                return $widget;
            }
        }
        return null;
    }

    /** Tab / Shift+Tab: the next widget that takes the focus. */
    public function moveFocus(int $direction): void
    {
        $list = [];
        foreach ($this->root?->walk() ?? [] as $widget) {
            if ($widget->focusable() && $this->visible($widget)) {
                $list[] = $widget;
            }
        }
        if ($list === []) {
            return;
        }
        $at = $this->focus === null ? -1 : (int)array_search($this->focus, $list, true);
        $next = $this->focus === null ? ($direction > 0 ? 0 : count($list) - 1) : ($at + $direction + count($list)) % count($list);
        $this->setFocus($list[$next]);
    }

    private function visible(Widget $widget): bool
    {
        for ($w = $widget; $w !== null; $w = $w->parent) {
            if (!$w->isShown($this->breakpoint)) {
                return false;
            }
        }
        return true;
    }

    /** Tests: a click (press then release) at a point of the view, or on a widget's centre. */
    public function clickAt(float $x, float $y, bool $double = false): void
    {
        $down = new Pointer();
        [$down->x, $down->y, $down->down, $down->held] = [$x, $y, true, true];
        $this->handle($down);
        $up = new Pointer();
        [$up->x, $up->y, $up->up] = [$x, $y, true];
        $this->doubleArmed = $this->doubleArmed || $double;
        $this->handle($up);
    }

    public function click(Widget $widget, bool $double = false): void
    {
        $offset = $widget->parent?->scrollOffset() ?? 0;
        $this->clickAt($widget->x + $widget->w / 2, $widget->y + $widget->h / 2 - $offset, $double);
    }

    /** Tests: text typed into the widget that has the focus (\n = Return, \t = Tab). */
    public function type(string $text): void
    {
        foreach (mb_str_split($text) ?: [] as $char) {
            $p = new Pointer();
            match ($char) {
                "\n" => $p->keys[] = Pointer::RETURN,
                "\t" => $p->keys[] = Pointer::TAB,
                "\x08" => $p->keys[] = Pointer::BACKSPACE,
                default => $p->text[] = $char,
            };
            $this->keys($p);
        }
    }

    /** Tests: the wheel turned over a point of the view (notches, > 0 = up). */
    public function wheelAt(float $x, float $y, float $notches): void
    {
        $p = new Pointer();
        [$p->x, $p->y, $p->wheel] = [$x, $y, $notches];
        $this->handle($p);
    }

    /** Tests: one key (Pointer::LEFT...). */
    public function key(int $key, bool $shift = false): void
    {
        $p = new Pointer();
        $p->keys[] = $key;
        $p->shift = $shift;
        $this->keys($p);
    }

    public function isDesktop(): bool
    {
        return Platform::isDesktop() && !Platform::isPhoneApp();
    }
}
