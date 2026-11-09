<?php

namespace JumPHP\Gui;

use JumPHP\Renderer\Renderer;

/**
 * A piece of a form: a button, a text box, a list, a container... Its properties come from the form file
 * (forms/<name>.form.yaml) and change from the code: `$this->ok->text = 'Save'`, `$this->photo->visible = false`.
 * Sizes are in dp (Theme::px): `width` / `height` = a number, 'auto' (what the content needs) or 'fill' (all the room
 * the container gives); `grow` shares the free room of a row or a column; `span` is the columns taken in a grid
 * (a number, or per screen width: {xs: 12, md: 6}); `hide` hides it on some screen widths ([xs, sm]).
 * Events: on('click', fn (Widget $sender) => ...), or `events: {click: onOkClick}` in the form file (a method of
 * the form's class).
 *
 * @property string $id
 * @property bool $visible
 * @property bool $enabled
 * @property float|string $width
 * @property float|string $height
 * @property float $grow
 * @property int|array $span
 * @property list<string> $hide
 * @property string $tooltip
 */
abstract class Widget
{
    /** what every widget has; each kind adds its own (DEFAULTS) */
    public const BASE = ['id' => '', 'visible' => true, 'enabled' => true, 'width' => 'auto', 'height' => 'auto', 'grow' => 0,
        'span' => 12, 'hide' => [], 'tooltip' => '', 'minWidth' => 0, 'minHeight' => 0, 'align' => null];

    public const DEFAULTS = [];

    /** @var array<string, mixed> */
    public array $props;

    public ?Container $parent = null;
    public ?Form $form = null;

    /** where it is in the view (pixels), set by the layout */
    public float $x = 0;
    public float $y = 0;
    public float $w = 0;
    public float $h = 0;

    public bool $hovered = false;
    public bool $pressed = false;
    public bool $focused = false;

    /** @var array<string, list<callable>> */
    private array $handlers = [];

    /** @param array<string, mixed> $props */
    public function __construct(array $props = [])
    {
        $this->props = $props + static::DEFAULTS + self::BASE;
    }

    /** The kind of widget in form files: 'button', 'textbox'... */
    public function type(): string
    {
        return strtolower((new \ReflectionClass($this))->getShortName());
    }

    /** A text of this widget in the language of the application (config/lang.yaml, the engine's own texts): the text itself when there is no translation. */
    protected function tr(string $text): string
    {
        return $text === '' ? '' : \JumPHP\Util\Lang::t($text);
    }

    public function __get(string $name): mixed
    {
        return $this->props[$name] ?? null;
    }

    public function __set(string $name, mixed $value): void
    {
        $old = $this->props[$name] ?? null;
        $this->props[$name] = $value;
        if ($old !== $value) {
            $this->changed($name, $old);
        }
    }

    public function __isset(string $name): bool
    {
        return isset($this->props[$name]);
    }

    /** A property changed from the code: what depends on it follows (the form lays itself out again). */
    protected function changed(string $name, mixed $old): void
    {
        $this->form?->invalidate();
    }

    // events -------------------------------------------------------------------------------------------------------

    public function on(string $event, callable $handler): static
    {
        $this->handlers[$event][] = $handler;
        return $this;
    }

    public function off(string $event): void
    {
        unset($this->handlers[$event]);
    }

    /** Calls the handlers of an event with the widget first: fn (Widget $sender, ...$args). */
    public function emit(string $event, mixed ...$args): void
    {
        foreach ($this->handlers[$event] ?? [] as $handler) {
            $handler($this, ...$args);
        }
    }

    /** @return list<string> the events this kind of widget has, the first is the one a double click wires */
    public static function events(): array
    {
        return ['click'];
    }

    // state --------------------------------------------------------------------------------------------------------

    /** Shown at this screen width (and its containers too)? */
    public function isShown(string $breakpoint): bool
    {
        return (bool)$this->props['visible'] && !in_array($breakpoint, (array)$this->props['hide'], true);
    }

    public function isEnabled(): bool
    {
        return (bool)$this->props['enabled'] && ($this->parent === null || $this->parent->isEnabled());
    }

    public function focusable(): bool
    {
        return false;
    }

    /** True while focused when it takes typed text: phones then show their on-screen keyboard (browser: JsBridge 'textinput'). */
    public function wantsText(): bool
    {
        return false;
    }

    /** Takes the whole width of a column (height of a row) when its size is 'auto' and the container stretches. */
    public function stretches(string $axis): bool
    {
        return $axis === 'width';
    }

    public function contains(float $px, float $py): bool
    {
        return $px >= $this->x && $px < $this->x + $this->w && $py >= $this->y && $py < $this->y + $this->h;
    }

    /** The widget under a point: itself (containers: the deepest child). */
    public function find(float $px, float $py, string $breakpoint): ?Widget
    {
        return $this->contains($px, $py) ? $this : null;
    }

    // size and place ----------------------------------------------------------------------------------------------

    /**
     * The size it would like, in pixels, if it may be $maxWidth wide: what its content needs, then its width /
     * height / minWidth / minHeight properties.
     *
     * @return array{0: float, 1: float}
     */
    public function measure(Theme $t, float $maxWidth, string $breakpoint): array
    {
        [$w, $h] = $this->content($t, $maxWidth, $breakpoint);
        if (is_numeric($this->props['width'])) {
            $w = $t->px((float)$this->props['width']);
        }
        if (is_numeric($this->props['height'])) {
            $h = $t->px((float)$this->props['height']);
        }
        return [max($w, $t->px((float)$this->props['minWidth'])), max($h, $t->px((float)$this->props['minHeight']))];
    }

    /** @return array{0: float, 1: float} the size of the content alone */
    abstract protected function content(Theme $t, float $maxWidth, string $breakpoint): array;

    public function layout(Theme $t, float $x, float $y, float $w, float $h, string $breakpoint): void
    {
        $this->x = $x;
        $this->y = $y;
        $this->w = $w;
        $this->h = $h;
    }

    abstract public function render(Renderer $r, Theme $t): void;

    /** Drawn over everything else (an open drop-down list). */
    public function renderOverlay(Renderer $r, Theme $t): void
    {
    }

    /** The open overlay's area, null when none: it gets the pointer first. */
    public function overlayContains(float $px, float $py): bool
    {
        return false;
    }

    public function update(float $dt): void
    {
    }

    // input: the form routes the pointer to the widget under it (or the one pressed), the keys to the focused one ----

    /** The wheel over it (notches, > 0 = up): true when it scrolled, else the widget around it gets it. */
    public function wheel(float $notches): bool
    {
        return false;
    }

    public function pointerDown(Pointer $p): void
    {
    }

    /** $inside: released on the widget (a click) or elsewhere (cancelled) */
    public function pointerUp(Pointer $p, bool $inside): void
    {
        if ($inside && $this->isEnabled()) {
            $this->click($p);
        }
    }

    public function pointerDrag(Pointer $p): void
    {
    }

    protected function click(Pointer $p): void
    {
        $this->emit('click');
        if ($p->double) {
            $this->emit('doubleclick');
        }
    }

    /** @return bool the widget used the keys (Tab then does not move the focus) */
    public function keys(Pointer $p): bool
    {
        return false;
    }

    public function blur(): void
    {
    }

    /** A colour of the theme, or the widget's own ('color', 'background' properties). */
    protected function tint(Theme $t, string $prop, string $themeColor): \JumPHP\Util\Color
    {
        $own = $this->props[$prop] ?? null;
        return is_string($own) && $own !== '' ? \JumPHP\Util\Color::of($own) : $t->color($themeColor);
    }
}
