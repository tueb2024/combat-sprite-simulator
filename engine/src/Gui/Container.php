<?php

namespace JumPHP\Gui;

use JumPHP\Renderer\Renderer;

/**
 * A box of widgets laid out like a web page, without HTML:
 * - `layout: column` (the default): one under the other; `row`: side by side (`wrap: true` goes to the next line
 *   when the row is full); `grid`: 12 columns (`columns`), each child takes `span` of them, per screen width
 *   (`span: {xs: 12, sm: 6, lg: 4}`: one per line on a phone, two on a tablet, three on a big screen);
 *   `stack`: on top of each other (a picture with a text over it).
 * - `gap` between the children, `padding` inside (dp); `align` (start | center | end | stretch) across,
 *   `justify` (start | center | end | between) along; a Spacer (or `grow` of a child) takes the free room.
 * - `title` + `border`: a framed group; `background`: a colour; `scroll: true`: a long content scrolls (drag it, its
 *   bar or the wheel) inside the container's height.
 *
 * @property string $layout
 * @property float $gap
 * @property float $padding
 * @property string $justify
 * @property bool $wrap
 * @property int $columns
 * @property string $title
 * @property bool $border
 * @property string $background
 * @property bool $scroll
 */
class Container extends Widget
{
    public const DEFAULTS = ['layout' => 'column', 'gap' => 8, 'padding' => 0, 'justify' => 'start', 'wrap' => false,
        'columns' => 12, 'title' => '', 'border' => false, 'background' => '', 'scroll' => false, 'items' => 'stretch'];

    public const BREAKPOINTS = ['xs' => 0, 'sm' => 600, 'md' => 900, 'lg' => 1200];

    /** @var list<Widget> */
    public array $children = [];

    /** scrolled by (pixels), the content's height */
    public float $scrollY = 0;
    private float $contentHeight = 0;
    private mixed $scrollTexture = null;
    /** @var array{0: int, 1: int} */
    private array $scrollTextureSize = [0, 0];
    private ?float $dragFrom = null;
    private bool $dragBar = false;
    private string $breakpoint = 'xs';

    /** The breakpoint of a width in dp: xs < 600 (phones), sm < 900 (tablets), md < 1200, lg. */
    public static function breakpoint(float $widthDp): string
    {
        $found = 'xs';
        foreach (self::BREAKPOINTS as $name => $min) {
            if ($widthDp >= $min) {
                $found = $name;
            }
        }
        return $found;
    }

    public static function events(): array
    {
        return ['click'];
    }

    public function add(Widget ...$widgets): static
    {
        foreach ($widgets as $widget) {
            $widget->parent = $this;
            $widget->form = $this->form;
            $this->children[] = $widget;
        }
        $this->form?->adopt($this);
        return $this;
    }

    public function remove(Widget $widget): void
    {
        $this->children = array_values(array_filter($this->children, fn (Widget $w) => $w !== $widget));
        $widget->parent = null;
        $this->form?->invalidate();
    }

    public function clear(): void
    {
        $this->children = [];
        $this->scrollY = 0;
        $this->form?->invalidate();
    }

    /** @return list<Widget> the children shown at this screen width */
    public function shown(string $breakpoint): array
    {
        return array_values(array_filter($this->children, fn (Widget $w) => $w->isShown($breakpoint)));
    }

    /** The columns a child takes in a grid at this screen width: its `span` for the biggest breakpoint up to it. */
    public function spanOf(Widget $child, string $breakpoint): int
    {
        $span = $child->props['span'];
        $columns = max(1, (int)$this->props['columns']);
        if (is_array($span)) {
            $found = $columns;
            foreach (array_keys(self::BREAKPOINTS) as $name) {
                if (isset($span[$name])) {
                    $found = (int)$span[$name];
                }
                if ($name === $breakpoint) {
                    break;
                }
            }
            $span = $found;
        }
        return max(1, min($columns, (int)$span));
    }

    // size and place ----------------------------------------------------------------------------------------------

    protected function content(Theme $t, float $maxWidth, string $breakpoint): array
    {
        $pad = $t->px((float)$this->props['padding']) + $this->chrome($t);
        $inner = max(0, (is_numeric($this->props['width']) ? $t->px((float)$this->props['width']) : $maxWidth) - 2 * $pad);
        [$w, $h] = $this->arrange($t, 0, 0, $inner, null, $breakpoint, false);
        return [$w + 2 * $pad, $h + 2 * $pad + $this->titleHeight($t)];
    }

    public function layout(Theme $t, float $x, float $y, float $w, float $h, string $breakpoint): void
    {
        parent::layout($t, $x, $y, $w, $h, $breakpoint);
        $this->breakpoint = $breakpoint;
        $pad = $t->px((float)$this->props['padding']) + $this->chrome($t);
        $top = $this->titleHeight($t);
        $innerH = $h - 2 * $pad - $top;
        $scroll = (bool)$this->props['scroll'];
        // the bar of a scrolled container is drawn over the content's right edge (as on phones): no room kept for it
        // a scrolled container whose content fits lays it out in its height (growing widgets fill it)
        $fits = !$scroll || $this->arrange($t, 0, 0, $w - 2 * $pad, null, $breakpoint, false)[1] <= $innerH;
        [, $this->contentHeight] = $this->arrange($t, $x + $pad, $y + $pad + $top, $w - 2 * $pad, $fits ? $innerH : null, $breakpoint, true);
        $this->scrollY = $scroll ? max(0, min($this->scrollY, $this->contentHeight - $innerH)) : 0;
    }

    /** The border and the title take some room. */
    private function chrome(Theme $t): float
    {
        return $this->props['border'] || $this->props['title'] !== '' ? $t->px(1) : 0;
    }

    private function titleHeight(Theme $t): float
    {
        return $this->props['title'] === '' ? 0 : $t->font($t->fontSize * 0.9, true)->lineHeight() + $t->px(8);
    }

    /**
     * Measures ($apply false) or places ($apply true) the children in a box: inner width $w, height $h (null: as high as
     * the children need).
     *
     * @return array{0: float, 1: float} the size the children take
     */
    private function arrange(Theme $t, float $x, float $y, float $w, ?float $h, string $bp, bool $apply): array
    {
        $children = $this->shown($bp);
        $gap = $t->px((float)$this->props['gap']);
        if ($children === []) {
            return [0, 0];
        }
        return match ($this->props['layout']) {
            'row' => $this->arrangeRow($t, $children, $x, $y, $w, $h, $gap, $bp, $apply),
            'grid' => $this->arrangeGrid($t, $children, $x, $y, $w, $h, $gap, $bp, $apply),
            'stack' => $this->arrangeStack($t, $children, $x, $y, $w, $h, $bp, $apply),
            default => $this->arrangeColumn($t, $children, $x, $y, $w, $h, $gap, $bp, $apply),
        };
    }

    /** @param list<Widget> $children */
    private function arrangeColumn(Theme $t, array $children, float $x, float $y, float $w, ?float $h, float $gap, string $bp, bool $apply): array
    {
        $sizes = [];
        $total = 0;
        $grow = 0;
        $widest = 0;
        foreach ($children as $i => $child) {
            [$cw, $ch] = $child->measure($t, $w, $bp);
            $cw = $this->crossSize($child, $cw, $w, 'width');
            [, $ch] = $child->measure($t, $cw, $bp);
            $sizes[$i] = [$cw, $ch];
            $total += $ch;
            $grow += $this->growOf($child, 'height');
            $widest = max($widest, $cw);
        }
        $total += $gap * (count($children) - 1);
        if (!$apply) {
            return [$widest, $total];
        }
        $free = $h === null ? 0 : max(0, $h - $total);
        [$start, $between] = $grow > 0 ? [0, 0] : $this->justify($free, count($children));
        $cy = $y + $start;
        foreach ($children as $i => $child) {
            [$cw, $ch] = $sizes[$i];
            if ($grow > 0) {
                $ch += $free * $this->growOf($child, 'height') / $grow;
            }
            $child->layout($t, $x + $this->crossOffset($child, $cw, $w), $cy, $cw, $ch, $bp);
            $cy += $ch + $gap + $between;
        }
        return [$widest, $total + ($h !== null && $grow > 0 ? $free : 0)];
    }

    /** @param list<Widget> $children */
    private function arrangeRow(Theme $t, array $children, float $x, float $y, float $w, ?float $h, float $gap, string $bp, bool $apply): array
    {
        // lines of children (one line without wrap)
        $lines = [[]];
        $used = 0;
        $widths = [];
        foreach ($children as $i => $child) {
            [$cw] = $child->measure($t, $w, $bp);
            $cw = min($cw, $w);
            $widths[$i] = $cw;
            $line = count($lines) - 1;
            if ($this->props['wrap'] && $lines[$line] !== [] && $used + $gap + $cw > $w) {
                $lines[] = [];
                $line++;
                $used = 0;
            }
            $used += ($lines[$line] === [] ? 0 : $gap) + $cw;
            $lines[$line][] = $i;
        }
        $totalH = 0;
        $widest = 0;
        $placed = [];
        foreach ($lines as $line) {
            $sum = $gap * (count($line) - 1);
            $grow = 0;
            foreach ($line as $i) {
                $sum += $widths[$i];
                $grow += $this->growOf($children[$i], 'width');
            }
            $free = max(0, $w - $sum);
            $sizes = [];
            foreach ($line as $i) {
                $sizes[$i] = $widths[$i];
            }
            if ($sum > $w) {
                // too wide: the supple children (texts, boxes, containers: not buttons, ticks or fixed widths) give room
                // back, in proportion; if that is not enough, everybody shrinks
                $supple = array_filter($line, fn (int $i) => !is_numeric($children[$i]->props['width']) && $children[$i]->stretches('width'));
                $pool = array_sum(array_map(fn (int $i) => $widths[$i], $supple));
                $over = $sum - $w;
                foreach ($supple as $i) {
                    $sizes[$i] = max(0, $widths[$i] - $over * $widths[$i] / max(1, $pool));
                }
                $left = array_sum($sizes) + $gap * (count($line) - 1);
                if ($left > $w) {
                    foreach ($line as $i) {
                        $sizes[$i] *= max(0, $w - $gap * (count($line) - 1)) / max(1, $left - $gap * (count($line) - 1));
                    }
                }
            }
            $final = [];
            $lineH = 0;
            foreach ($line as $i) {
                $cw = $sizes[$i] + ($grow > 0 ? $free * $this->growOf($children[$i], 'width') / $grow : 0);
                [, $ch] = $children[$i]->measure($t, $cw, $bp);
                $final[$i] = [$cw, $ch];
                $lineH = max($lineH, $ch);
            }
            $placed[] = [$line, $final, $lineH, $grow > 0 ? 0 : $free];
            $totalH += $lineH;
            $widest = max($widest, min($w, $sum));
        }
        $totalH += $gap * (count($lines) - 1);
        if (!$apply) {
            return [$widest, $totalH];
        }
        $single = count($lines) === 1 && $h !== null;
        $cy = $y;
        foreach ($placed as [$line, $final, $lineH, $free]) {
            $lineH = $single ? max($lineH, (float)$h) : $lineH;
            [$start, $between] = $this->justify($free, count($line));
            $cx = $x + $start;
            foreach ($line as $i) {
                [$cw, $ch] = $final[$i];
                $ch = $this->crossSize($children[$i], $ch, $lineH, 'height', 'center');
                $children[$i]->layout($t, $cx, $cy + $this->crossOffset($children[$i], $ch, $lineH, 'center'), $cw, $ch, $bp);
                $cx += $cw + $gap + $between;
            }
            $cy += $lineH + $gap;
        }
        return [$widest, $totalH];
    }

    /** @param list<Widget> $children */
    private function arrangeGrid(Theme $t, array $children, float $x, float $y, float $w, ?float $h, float $gap, string $bp, bool $apply): array
    {
        $columns = max(1, (int)$this->props['columns']);
        $col = ($w - $gap * ($columns - 1)) / $columns;
        $rows = [[]];
        $used = 0;
        foreach ($children as $i => $child) {
            $span = $this->spanOf($child, $bp);
            if ($used + $span > $columns) {
                $rows[] = [];
                $used = 0;
            }
            $rows[count($rows) - 1][] = [$i, $used, $span];
            $used += $span;
        }
        $cy = $y;
        $total = 0;
        foreach ($rows as $n => $row) {
            $rowH = 0;
            $sizes = [];
            foreach ($row as [$i, $start, $span]) {
                $cw = $col * $span + $gap * ($span - 1);
                [, $ch] = $children[$i]->measure($t, $cw, $bp);
                $sizes[$i] = $cw;
                $rowH = max($rowH, $ch);
            }
            if ($h !== null && count($rows) === 1) {
                $rowH = max($rowH, $h);       // one line in a grid given a height: its cells are that high
            }
            if ($apply) {
                foreach ($row as [$i, $start, $span]) {
                    [, $ch] = $children[$i]->measure($t, $sizes[$i], $bp);
                    $ch = $this->crossSize($children[$i], $ch, $rowH, 'height');
                    $children[$i]->layout($t, $x + ($col + $gap) * $start, $cy + $this->crossOffset($children[$i], $ch, $rowH), $sizes[$i], $ch, $bp);
                }
            }
            $cy += $rowH + $gap;
            $total += $rowH + ($n > 0 ? $gap : 0);
        }
        return [$w, $total];
    }

    /** @param list<Widget> $children */
    private function arrangeStack(Theme $t, array $children, float $x, float $y, float $w, ?float $h, string $bp, bool $apply): array
    {
        $high = 0;
        $wide = 0;
        foreach ($children as $child) {
            [$cw, $ch] = $child->measure($t, $w, $bp);
            $high = max($high, $ch);
            $wide = max($wide, $cw);
        }
        if ($apply) {
            $box = $h ?? $high;
            foreach ($children as $child) {
                [$cw, $ch] = $child->measure($t, $w, $bp);
                $cw = $this->crossSize($child, $cw, $w, 'width');
                $ch = $this->crossSize($child, $ch, $box, 'height');
                $child->layout($t, $x + $this->crossOffset($child, $cw, $w), $y + $this->crossOffset($child, $ch, $box), $cw, $ch, $bp);
            }
        }
        return [$wide, $high];
    }

    /** The share of the free room a child takes: its `grow`, 1 for 'fill', a Spacer without a size grows. */
    private function growOf(Widget $child, string $axis): float
    {
        if ($child->props[$axis] === 'fill') {
            return max(1.0, (float)$child->props['grow']);
        }
        return (float)$child->props['grow'];
    }

    /** Across the layout: stretched (align stretch / 'fill', the default for an auto width) or its own size. */
    private function crossSize(Widget $child, float $size, float $room, string $axis, string $default = 'stretch'): float
    {
        if ($child->props[$axis] === 'fill') {
            return $room;
        }
        $align = $child->props['align'] ?? ($this->props['items'] === 'stretch' ? $default : $this->props['items']);
        if ($align === 'stretch' && $child->props[$axis] === 'auto' && $child->stretches($axis)) {
            return $room;
        }
        return min($size, $room);
    }

    private function crossOffset(Widget $child, float $size, float $room, string $default = 'start'): float
    {
        $align = $child->props['align'] ?? ($this->props['items'] === 'stretch' ? $default : $this->props['items']);
        return match ($align) {
            'center' => ($room - $size) / 2,
            'end' => $room - $size,
            default => 0,
        };
    }

    /** @return array{0: float, 1: float} the room before the first child and between two of them */
    private function justify(float $free, int $count): array
    {
        return match ($this->props['justify']) {
            'center' => [$free / 2, 0],
            'end' => [$free, 0],
            'between' => [0, $count > 1 ? $free / ($count - 1) : 0],
            'around' => [$free / $count / 2, $free / $count],
            default => [0, 0],
        };
    }

    // drawing ----------------------------------------------------------------------------------------------------

    public function render(Renderer $r, Theme $t): void
    {
        $framed = $this->props['border'] || $this->props['title'] !== '';
        if ($framed) {
            $fill = $this->props['background'] !== '' ? $this->tint($t, 'background', 'surface') : $t->color('surface');
            $t->frame($r, $this->x, $this->y, $this->w, $this->h, $fill, $t->color('border'));
        } elseif ($this->props['background'] !== '') {
            $t->box($r, $this->x, $this->y, $this->w, $this->h, $this->tint($t, 'background', 'surface'), $this->form?->root === $this ? 0 : -1);
        }
        if ($this->props['title'] !== '') {
            $pad = $t->px((float)$this->props['padding']) + $this->chrome($t);
            $t->font($t->fontSize * 0.9, true)->draw($r, $this->tr((string)$this->props['title']), $this->x + max($pad, $t->px(10)), $this->y + $t->px(6), 1, $t->color('muted'));
        }
        if ($this->props['scroll'] && $this->contentHeight > $this->viewHeight($t)) {
            $this->renderScrolled($r, $t);
            return;
        }
        foreach ($this->shown($this->breakpoint) as $child) {
            $child->render($r, $t);
        }
    }

    public function renderOverlay(Renderer $r, Theme $t): void
    {
        $r->translate(0, -$this->scrollY);
        foreach ($this->shown($this->breakpoint) as $child) {
            $child->renderOverlay($r, $t);
        }
        $r->translate(0, $this->scrollY);
    }

    public function stretches(string $axis): bool
    {
        return true;
    }

    /** The visible part of the content: drawn into a texture of its own (the children stay in view coordinates). */
    private function renderScrolled(Renderer $r, Theme $t): void
    {
        [$vx, $vy, $vw, $vh] = $this->viewport($t);
        $size = [(int)ceil($vw), (int)ceil($vh)];
        if ($size[0] < 1 || $size[1] < 1) {
            return;
        }
        if ($this->scrollTexture === null || $this->scrollTextureSize !== $size) {
            if ($this->scrollTexture !== null) {
                \SDL_DestroyTexture($this->scrollTexture);
            }
            $this->scrollTexture = $r->createTargetTexture($size[0], $size[1]);
            $this->scrollTextureSize = $size;
        }
        $previous = $r->getTarget();
        [$ox, $oy] = $r->translation();
        $r->setTarget($this->scrollTexture);
        $r->translate(-$ox - $vx, -$oy - $vy - $this->scrollY);
        $r->clear($this->props['background'] !== '' || $this->props['border'] || $this->props['title'] !== ''
            ? ($this->props['background'] !== '' ? $this->tint($t, 'background', 'surface') : $t->color('surface'))
            : $this->backdrop($t));
        foreach ($this->shown($this->breakpoint) as $child) {
            if ($child->y + $child->h >= $vy + $this->scrollY && $child->y <= $vy + $this->scrollY + $vh) {
                $child->render($r, $t);
            }
        }
        $r->translate($ox + $vx, $oy + $vy + $this->scrollY);
        $r->setTarget($previous);
        $r->copy($this->scrollTexture, null, $vx, $vy, $vw, $vh);
        // the bar
        $barW = $t->px(4);
        $thumbH = max($t->px(24), $vh * $vh / $this->contentHeight);
        $thumbY = $vy + ($vh - $thumbH) * $this->scrollY / max(1, $this->contentHeight - $vh);
        $t->box($r, $vx + $vw - $barW - $t->px(1), $thumbY, $barW, $thumbH, $this->dragBar ? $t->color('muted') : $t->color('border'), $barW / 2);
    }

    /** The colour under a container without a background of its own. */
    private function backdrop(Theme $t): \JumPHP\Util\Color
    {
        for ($p = $this->parent; $p !== null; $p = $p->parent) {
            if ($p->props['background'] !== '') {
                return $p->tint($t, 'background', 'surface');
            }
            if ($p->props['border'] || $p->props['title'] !== '') {
                return $t->color('surface');
            }
        }
        return $t->color('background');
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} the area the children are seen in */
    public function viewport(Theme $t): array
    {
        $pad = $t->px((float)$this->props['padding']) + $this->chrome($t);
        $top = $this->titleHeight($t);
        return [$this->x + $this->chrome($t), $this->y + $pad + $top, $this->w - 2 * $this->chrome($t), max(0, $this->h - 2 * $pad - $top)];
    }

    private function viewHeight(Theme $t): float
    {
        return $this->viewport($t)[3];
    }

    public function isScrolling(): bool
    {
        return (bool)$this->props['scroll'] && $this->form !== null && $this->contentHeight > $this->viewHeight($this->form->theme);
    }

    public function scrollBy(float $dy): void
    {
        if ($this->form === null) {
            return;
        }
        $this->scrollY = max(0, min($this->scrollY + $dy, $this->contentHeight - $this->viewHeight($this->form->theme)));
    }

    public function wheel(float $notches): bool
    {
        if (!$this->isScrolling() || $this->form === null) {
            return false;
        }
        $before = $this->scrollY;
        $this->scrollBy(-$notches * $this->form->theme->px(48));
        return $this->scrollY !== $before;
    }

    // input ------------------------------------------------------------------------------------------------------

    public function find(float $px, float $py, string $breakpoint): ?Widget
    {
        if (!$this->contains($px, $py)) {
            return null;
        }
        if ($this->isScrolling()) {
            [$vx, $vy, $vw, $vh] = $this->viewport($this->form->theme);
            if ($px >= $vx + $vw - $this->form->theme->px(10)) {
                return $this;            // the bar
            }
            if ($py < $vy || $py >= $vy + $vh) {
                return $this;
            }
            $py += $this->scrollY;
        }
        foreach (array_reverse($this->shown($breakpoint)) as $child) {
            $found = $child->find($px, $py, $breakpoint);
            if ($found !== null) {
                return $found;
            }
        }
        return $this;
    }

    /** How far its content is scrolled, with its containers' (pointer coordinates of a child). */
    public function scrollOffset(): float
    {
        return $this->scrollY + ($this->parent?->scrollOffset() ?? 0);
    }

    public function pointerDown(Pointer $p): void
    {
        if (!$this->isScrolling()) {
            return;
        }
        [$vx, , $vw] = $this->viewport($this->form->theme);
        $this->dragBar = $p->x >= $vx + $vw - $this->form->theme->px(10);
        $this->dragFrom = $p->y;
    }

    public function pointerDrag(Pointer $p): void
    {
        if ($this->dragFrom === null) {
            return;
        }
        $dy = $p->y - $this->dragFrom;
        $this->dragFrom = $p->y;
        if ($this->dragBar) {
            $vh = $this->viewHeight($this->form->theme);
            $this->scrollBy($dy * $this->contentHeight / max(1, $vh));
        } else {
            $this->scrollBy(-$dy);
        }
    }

    public function pointerUp(Pointer $p, bool $inside): void
    {
        $this->dragFrom = null;
        $this->dragBar = false;
        if ($inside && $this->isEnabled()) {
            $this->click($p);
        }
    }

    public function keys(Pointer $p): bool
    {
        return false;
    }

    /** All its widgets, deep. @return \Generator<Widget> */
    public function walk(): \Generator
    {
        foreach ($this->children as $child) {
            yield $child;
            if ($child instanceof Container) {
                yield from $child->walk();
            }
        }
    }
}
