<?php

namespace JumPHP\Gui;

use JumPHP\Native\Clipboard;
use JumPHP\Renderer\Renderer;

/**
 * A text field: `text`, `placeholder`, `password` (shown as dots), `maxLength` (0: no limit), `multiline` (Return goes
 * to the next line, the box grows with its `rows`), `readonly`.
 * The text comes from the system's text input: the keyboard's layout, dead keys (^ then e = ê) and input methods
 * (Chinese, Japanese...: what they compose is shown underlined until it is chosen).
 * Events: change (each key), submit (Return in a one-line box).
 *
 * @property string $text
 * @property string $placeholder
 */
class TextBox extends Widget implements Overlay
{
    use HasContextMenu;

    public const DEFAULTS = ['text' => '', 'placeholder' => '', 'password' => false, 'maxLength' => 0, 'multiline' => false,
        'rows' => 4, 'readonly' => false];

    /** where the cursor is (characters) */
    public int $cursor = 0;
    /** what the system's input method (or a dead key) is composing: shown underlined at the cursor, not in the text yet */
    public string $composition = '';
    /** @var array{0: float, 1: float, 2: float, 3: float}|null the cursor's box (x, y, w, h, view pixels) when last drawn: the input method's window opens there */
    public ?array $caret = null;
    /** the other end of the selection (the cursor is one end); null: nothing selected */
    public ?int $anchor = null;
    private bool $composing = false;
    private bool $dragging = false;
    private float $scrollX = 0;
    private float $blink = 0;
    /** @var list<int> where each line drawn by the multi-line box starts in the text */
    private array $lineStarts = [];

    public static function events(): array
    {
        return ['change', 'submit', 'click'];
    }

    public function focusable(): bool
    {
        return $this->isEnabled();
    }

    public function wantsText(): bool
    {
        return $this->isEnabled() && !$this->props['readonly'];
    }

    protected function content(Theme $t, float $maxWidth, string $breakpoint): array
    {
        $lines = $this->props['multiline'] ? max(1, (int)$this->props['rows']) : 1;
        $lh = $t->font()->lineHeight();
        return [$t->px(200), $lines === 1 ? $t->px($t->controlHeight) : $lines * $lh + 2 * $t->px($t->padding * 0.8)];
    }

    protected function changed(string $name, mixed $old): void
    {
        if ($name === 'text') {
            $this->cursor = min($this->cursor, mb_strlen((string)$this->props['text']));
            $this->anchor = $this->anchor === null ? null : min($this->anchor, mb_strlen((string)$this->props['text']));
        }
        parent::changed($name, $old);
    }

    private function shown(): string
    {
        $text = (string)$this->props['text'];
        if ($this->props['password']) {
            return str_repeat('•', mb_strlen($text));
        }
        return $this->composing ? mb_substr($text, 0, $this->cursor) . $this->composition . mb_substr($text, $this->cursor) : $text;
    }

    public function update(float $dt): void
    {
        $this->blink = $this->focused ? fmod($this->blink + $dt, 1.0) : 0;
    }

    public function render(Renderer $r, Theme $t): void
    {
        // a composition is drawn as if typed, the cursor after it
        $this->composing = $this->focused && $this->composition !== '' && !$this->props['password'];
        $length = $this->composing ? mb_strlen($this->composition) : 0;
        $this->cursor += $length;
        try {
            $this->draw($r, $t, $length);
        } finally {
            $this->cursor -= $length;
            $this->composing = false;
        }
    }

    private function draw(Renderer $r, Theme $t, int $composed): void
    {
        $border = $this->focused ? $t->color('focus') : ($this->hovered ? $t->color('muted') : $t->color('border'));
        if ($this->focused) {
            $t->box($r, $this->x - $t->px(2), $this->y - $t->px(2), $this->w + $t->px(4), $this->h + $t->px(4), $t->color('focus')->withAlpha(70), $t->px($t->radius + 2));
        }
        $t->frame($r, $this->x, $this->y, $this->w, $this->h, $this->isEnabled() && !$this->props['readonly'] ? $t->color('surface') : $t->color('track'), $border);
        $font = $t->font();
        $pad = $t->px($t->padding);
        $innerW = $this->w - 2 * $pad;
        $text = $this->shown();
        $lh = $font->lineHeight();
        $color = $this->isEnabled() ? $this->tint($t, 'color', 'text') : $t->color('disabled');
        if ($text === '' && !$this->focused) {
            $font->draw($r, $this->tr((string)$this->props['placeholder']), $this->x + $pad, $this->textTop($t), 1, $t->color('muted'));
            return;
        }
        if ($this->props['multiline']) {
            [$lines, $cursorLine, $cursorCol] = $this->lines($t, $innerW);
            $first = max(0, $cursorLine - (int)floor(($this->h - $pad) / $lh) + 1);
            $y = $this->y + $t->px($t->padding * 0.8);
            for ($n = $first; $n < count($lines) && $y + $lh <= $this->y + $this->h; $n++, $y += $lh) {
                if ($this->focused && ($sel = $this->selection()) !== null) {
                    $ls = $this->lineStarts[$n] ?? 0;
                    $from = max($sel[0], $ls);
                    $to = min($sel[1], $ls + mb_strlen($lines[$n]));
                    if ($to > $from) {
                        $x0 = $this->x + $pad + $font->width(mb_substr($lines[$n], 0, $from - $ls));
                        $r->fillRect($x0, $y, $font->width(mb_substr($lines[$n], $from - $ls, $to - $from)), $lh, $t->color('selection'));
                    }
                }
                $font->draw($r, $lines[$n], $this->x + $pad, $y + ($lh - $font->height()) / 2, 1, $color);
                if ($this->focused && $n === $cursorLine) {
                    $cx = $this->x + $pad + $font->width(mb_substr($lines[$n], 0, $cursorCol));
                    $this->caret = [$cx, $y, max(1, $t->px(1.5)), $lh];
                    if ($composed > 0) {
                        $from = $this->x + $pad + $font->width(mb_substr($lines[$n], 0, max(0, $cursorCol - $composed)));
                        $r->fillRect($from, $y + $lh - $t->px(2), $cx - $from, max(1, $t->px(1)), $color);
                    }
                    if ($this->blink < 0.55) {
                        $r->fillRect($cx, $y + $t->px(2), max(1, $t->px(1.5)), $lh - $t->px(4), $t->color('text'));
                    }
                }
            }
            return;
        }
        $before = $font->width(mb_substr($text, 0, $this->cursor));
        // the cursor stays in sight: the text slides
        if ($before - $this->scrollX > $innerW) {
            $this->scrollX = $before - $innerW;
        } elseif ($before < $this->scrollX) {
            $this->scrollX = max(0, $before - $innerW / 3);
        }
        $visible = $this->clip($font, $text, $this->scrollX, $innerW, $offset);
        if ($this->focused && ($sel = $this->selection()) !== null) {
            $x0 = max(0.0, $font->width(mb_substr($text, 0, $sel[0])) - $this->scrollX);
            $x1 = min($innerW, $font->width(mb_substr($text, 0, $sel[1])) - $this->scrollX);
            if ($x1 > $x0) {
                $r->fillRect($this->x + $pad + $x0, $this->y + $t->px(4), $x1 - $x0, $this->h - $t->px(8), $t->color('selection'));
            }
        }
        $font->draw($r, $visible, $this->x + $pad + $offset, $this->textTop($t), 1, $color);
        if ($this->focused) {
            $cx = $this->x + $pad + $before - $this->scrollX;
            $this->caret = [$cx, $this->y, max(1, $t->px(1.5)), $this->h];
            if ($composed > 0) {
                $from = $this->x + $pad + $font->width(mb_substr($text, 0, $this->cursor - $composed)) - $this->scrollX;
                $r->fillRect(max($this->x + $pad, $from), $this->textTop($t) + $font->height() + $t->px(1), $cx - max($this->x + $pad, $from), max(1, $t->px(1)), $color);
            }
            if ($this->blink < 0.55) {
                $r->fillRect($cx, $this->y + $t->px(8), max(1, $t->px(1.5)), $this->h - $t->px(16), $t->color('text'));
            }
        }
    }

    private function textTop(Theme $t): float
    {
        $font = $t->font();
        return $this->props['multiline'] ? $this->y + $t->px($t->padding * 0.8) + ($font->lineHeight() - $font->height()) / 2
            : $this->y + ($this->h - $font->height()) / 2;
    }

    /**
     * The characters seen between $from and $from + $width pixels ($offset: where the first one starts).
     *
     * @param-out float $offset
     */
    private function clip(\JumPHP\Renderer\TextDrawer $font, string $text, float $from, float $width, ?float &$offset): string
    {
        $start = 0;
        $len = mb_strlen($text);
        while ($start < $len && $font->width(mb_substr($text, 0, $start + 1)) <= $from) {
            $start++;
        }
        $offset = $font->width(mb_substr($text, 0, $start)) - $from;
        $end = $start;
        while ($end < $len && $font->width(mb_substr($text, $start, $end - $start + 1)) + $offset <= $width) {
            $end++;
        }
        return mb_substr($text, $start, $end - $start);
    }

    /**
     * The text cut into lines that fit the width, and where the cursor is in them.
     *
     * @return array{0: list<string>, 1: int, 2: int}
     */
    private function lines(Theme $t, float $width): array
    {
        $font = $t->font();
        // the wrapping measures the text with the font over and over: done again only when the text, the width or the size changed
        // (it was done every frame, a few ms per frame in the browser on a phone)
        $text = $this->shown();
        $key = $width . '|' . $font->lineHeight() . '|' . $text;
        if ($this->wrapKey !== $key) {
            $this->wrapKey = $key;
            $this->wrapped = [];
            $this->wrapStarts = [];
            $this->wrapParagraphs = [];
            $pos = 0;
            foreach (explode("\n", $text) as $paragraph) {
                $chars = mb_str_split($paragraph) ?: [];
                $line = '';
                $start = $pos;
                foreach ($chars as $char) {
                    if ($line !== '' && $font->width($line . $char) > $width) {
                        $this->wrapped[] = $line;
                        $this->wrapStarts[] = $start;
                        $start += mb_strlen($line);
                        $line = '';
                    }
                    $line .= $char;
                }
                $this->wrapped[] = $line;
                $this->wrapStarts[] = $start;
                $this->wrapParagraphs[] = [$start, $pos + count($chars), count($this->wrapped) - 1];
                $pos += count($chars) + 1;   // + the new line
            }
        }
        $this->lineStarts = $this->wrapStarts;
        $cursorLine = 0;
        $cursorCol = 0;
        foreach ($this->wrapParagraphs as [$start, $end, $last]) {
            if ($this->cursor >= $start && $this->cursor <= $end) {
                $cursorLine = $last;
                $cursorCol = $this->cursor - $start;
            }
        }
        return [$this->wrapped, $cursorLine, $cursorCol];
    }

    private string $wrapKey = '';
    /** @var list<string> */
    private array $wrapped = [];
    /** @var list<int> */
    private array $wrapStarts = [];
    /** @var list<array{0: int, 1: int, 2: int}> start of the last line, end of the paragraph, index of its last line */
    private array $wrapParagraphs = [];

    /** The character position under a pointer x (a one-line box); the end for a multi-line box. */
    private function cursorAt(Pointer $p): int
    {
        $text = $this->shown();
        $len = mb_strlen($text);
        if ($this->props['multiline'] || $this->form === null) {
            return $len;
        }
        $t = $this->form->theme;
        $font = $t->font();
        $x = $p->x - $this->x - $t->px($t->padding) + $this->scrollX;
        for ($i = 0; $i < $len; $i++) {
            if ($font->width(mb_substr($text, 0, $i)) + $font->width(mb_substr($text, $i, 1)) / 2 > $x) {
                return $i;
            }
        }
        return $len;
    }

    public function pointerDown(Pointer $p): void
    {
        if ($this->menuOpen) {
            return;     // a click on the menu (pointerUp), or elsewhere: the form closes the menu
        }
        $pos = $this->cursorAt($p);
        if ($p->shift && $this->focused) {
            $this->anchor ??= $this->cursor;       // shift + click: the selection goes on to there
        } else {
            $this->anchor = $pos;
        }
        $this->cursor = $pos;
        $this->dragging = true;
        $this->blink = 0;
    }

    /** The mouse is held down and moves: the selection follows. */
    public function pointerDrag(Pointer $p): void
    {
        if ($this->dragging && !$this->menuOpen && !$this->props['multiline']) {
            $this->cursor = $this->cursorAt($p);
            $this->blink = 0;
        }
    }

    public function pointerUp(Pointer $p, bool $inside): void
    {
        $this->dragging = false;
        if ($this->menuOpen && $this->menuClick($p)) {
            return;
        }
        if ($this->anchor === $this->cursor) {
            $this->anchor = null;                // a plain click: no selection
        }
        if ($inside && $p->double) {
            [$from, $to] = $this->wordAt((string)$this->props['text'], $this->cursor);
            $this->anchor = $from;
            $this->cursor = $to;
        }
        parent::pointerUp($p, $inside);
    }

    // selection, words ---------------------------------------------------------------------------------------------

    /** @return array{0: int, 1: int}|null the selected characters, from / to, null when none */
    public function selection(): ?array
    {
        if ($this->anchor === null || $this->anchor === $this->cursor) {
            return null;
        }
        return [min($this->anchor, $this->cursor), max($this->anchor, $this->cursor)];
    }

    /** 0 a space, 1 a letter / digit / _, 2 anything else (punctuation): a word is a run of one class */
    private static function charClass(string $c): int
    {
        return preg_match('/\s/u', $c) ? 0 : (preg_match('/[\p{L}\p{N}_]/u', $c) ? 1 : 2);
    }

    /** Where Ctrl+Left goes: the start of the word before $pos. */
    public static function prevWord(string $text, int $pos): int
    {
        $i = min($pos, mb_strlen($text));
        while ($i > 0 && self::charClass(mb_substr($text, $i - 1, 1)) === 0) {
            $i--;
        }
        if ($i > 0) {
            $class = self::charClass(mb_substr($text, $i - 1, 1));
            while ($i > 0 && self::charClass(mb_substr($text, $i - 1, 1)) === $class) {
                $i--;
            }
        }
        return $i;
    }

    /** Where Ctrl+Right goes: the start of the next word after $pos. */
    public static function nextWord(string $text, int $pos): int
    {
        $len = mb_strlen($text);
        $i = max(0, $pos);
        if ($i < $len && self::charClass(mb_substr($text, $i, 1)) !== 0) {
            $class = self::charClass(mb_substr($text, $i, 1));
            while ($i < $len && self::charClass(mb_substr($text, $i, 1)) === $class) {
                $i++;
            }
        }
        while ($i < $len && self::charClass(mb_substr($text, $i, 1)) === 0) {
            $i++;
        }
        return $i;
    }

    /** The word (or the space / punctuation run) at $pos, for a double click. @return array{0: int, 1: int} */
    private function wordAt(string $text, int $pos): array
    {
        $len = mb_strlen($text);
        if ($len === 0) {
            return [0, 0];
        }
        $pos = min($pos, $len - 1);
        $class = self::charClass(mb_substr($text, $pos, 1));
        $from = $pos;
        $to = $pos + 1;
        while ($from > 0 && self::charClass(mb_substr($text, $from - 1, 1)) === $class) {
            $from--;
        }
        while ($to < $len && self::charClass(mb_substr($text, $to, 1)) === $class) {
            $to++;
        }
        return [$from, $to];
    }

    /** Deletes the selection from $text; true when there was one. */
    private function removeSelection(string &$text): bool
    {
        $sel = $this->selection();
        if ($sel === null) {
            $this->anchor = null;
            return false;
        }
        $text = mb_substr($text, 0, $sel[0]) . mb_substr($text, $sel[1]);
        $this->cursor = $sel[0];
        $this->anchor = null;
        return true;
    }

    /** Copies the selection to the system's clipboard (not from a password box); true when something was copied. */
    private function copySelection(string $text): bool
    {
        $sel = $this->selection();
        if ($sel === null || $this->props['password']) {
            return false;
        }
        try {
            Clipboard::copy(mb_substr($text, $sel[0], $sel[1] - $sel[0]));
        } catch (\Throwable) {
            return false;
        }
        return true;
    }

    /** Puts $insert at the cursor (instead of the selection), within maxLength. */
    public function insertText(string $insert): void
    {
        if ($this->props['readonly'] || !$this->isEnabled()) {
            return;
        }
        $text = (string)$this->props['text'];
        $this->removeSelection($text);
        $insert = str_replace(["\r\n", "\r"], "\n", $insert);
        if (!$this->props['multiline']) {
            $insert = str_replace("\n", ' ', $insert);
        }
        $max = (int)$this->props['maxLength'];
        if ($max > 0) {
            $insert = mb_substr($insert, 0, max(0, $max - mb_strlen($text)));
        }
        $text = mb_substr($text, 0, $this->cursor) . $insert . mb_substr($text, $this->cursor);
        $this->cursor += mb_strlen($insert);
        $this->props['text'] = $text;
        $this->form?->invalidate();
        $this->emit('change', $text);
    }

    private function pasteFromClipboard(): void
    {
        try {
            Clipboard::paste()->then(function (mixed $text): void {
                if (is_string($text) && $text !== '') {
                    $this->insertText($text);
                }
            }, static fn () => null);
        } catch (\Throwable) {
        }
    }

    public function keys(Pointer $p): bool
    {
        if ($this->menuOpen) {
            $escape = $p->pressed(Pointer::ESCAPE);
            $this->close();
            if ($escape) {
                return true;
            }
        }
        $text = (string)$this->props['text'];
        $len = mb_strlen($text);
        $this->cursor = min($this->cursor, $len);
        $this->anchor = $this->anchor === null ? null : min($this->anchor, $len);
        $editable = !$this->props['readonly'] && $this->isEnabled();
        $new = $text;
        $ctrl = $p->ctrl;
        $move = function (int $to) use ($p, &$new): void {
            if ($p->shift) {
                $this->anchor ??= $this->cursor;
            } else {
                $this->anchor = null;
            }
            $this->cursor = max(0, min(mb_strlen($new), $to));
        };
        foreach ($p->keys as $key) {
            $this->blink = 0;
            $letter = $ctrl && $key >= 97 && $key <= 122 ? chr($key) : ($ctrl && $key >= 65 && $key <= 90 ? strtolower(chr($key)) : null);
            if ($letter === 'a') {
                $this->anchor = 0;
                $this->cursor = mb_strlen($new);
                continue;
            }
            if ($letter === 'c') {
                $this->copySelection($new);
                continue;
            }
            if ($letter === 'x') {
                if ($editable && $this->copySelection($new)) {
                    $this->removeSelection($new);
                }
                continue;
            }
            if ($letter === 'v') {
                if ($editable) {
                    if ($new !== $text) {          // what was typed before the paste goes first
                        $this->props['text'] = $new;
                        $this->emit('change', $new);
                        $text = $new;
                    }
                    $this->pasteFromClipboard();
                }
                continue;
            }
            switch ($key) {
                case Pointer::LEFT:
                    $sel = $this->selection();
                    if ($sel !== null && !$p->shift) {
                        $this->cursor = $sel[0];
                        $this->anchor = null;
                    } else {
                        $move($ctrl ? self::prevWord($new, $this->cursor) : $this->cursor - 1);
                    }
                    break;
                case Pointer::RIGHT:
                    $sel = $this->selection();
                    if ($sel !== null && !$p->shift) {
                        $this->cursor = $sel[1];
                        $this->anchor = null;
                    } else {
                        $move($ctrl ? self::nextWord($new, $this->cursor) : $this->cursor + 1);
                    }
                    break;
                case Pointer::HOME:
                    $move(0);
                    break;
                case Pointer::END:
                    $move(mb_strlen($new));
                    break;
                case Pointer::BACKSPACE:
                    if ($editable) {
                        if (!$this->removeSelection($new) && $this->cursor > 0) {
                            $from = $ctrl ? self::prevWord($new, $this->cursor) : $this->cursor - 1;
                            $new = mb_substr($new, 0, $from) . mb_substr($new, $this->cursor);
                            $this->cursor = $from;
                        }
                    }
                    break;
                case Pointer::DELETE:
                    if ($editable) {
                        if ($p->shift && $this->selection() !== null) {          // Shift+Delete: cut
                            if ($this->copySelection($new)) {
                                $this->removeSelection($new);
                            }
                        } elseif (!$this->removeSelection($new)) {
                            $to = $ctrl ? self::nextWord($new, $this->cursor) : $this->cursor + 1;
                            $new = mb_substr($new, 0, $this->cursor) . mb_substr($new, $to);
                        }
                    }
                    break;
                case Pointer::RETURN:
                case Pointer::KP_ENTER:
                    if ($this->props['multiline']) {
                        if ($editable) {
                            $this->removeSelection($new);
                            $new = mb_substr($new, 0, $this->cursor) . "\n" . mb_substr($new, $this->cursor);
                            $this->cursor++;
                        }
                    } else {
                        $this->emit('submit', $new);
                    }
                    break;
            }
        }
        if ($editable && $p->text !== []) {
            $this->removeSelection($new);
            foreach ($p->text as $char) {
                $max = (int)$this->props['maxLength'];
                if ($max > 0 && mb_strlen($new) >= $max) {
                    break;
                }
                $new = mb_substr($new, 0, $this->cursor) . $char . mb_substr($new, $this->cursor);
                $this->cursor++;
            }
        }
        if ($new !== $text) {
            $this->props['text'] = $new;
            $this->form?->invalidate();
            $this->emit('change', $new);
        }
        // Tab leaves the field; everything else was for it
        return $p->keys !== [Pointer::TAB] && ($p->keys !== [] || $p->text !== []);
    }

    // the context menu (right click): the rows and what they do; the menu itself is HasContextMenu ---------------------------

    /** @return list<array{0: string, 1: bool}> the menu's rows: what it does, whether it can now */
    protected function menuItems(): array
    {
        $editable = !$this->props['readonly'] && $this->isEnabled();
        $selected = $this->selection() !== null && !$this->props['password'];
        return [['cut', $editable && $selected], ['copy', $selected], ['paste', $editable], ['all', (string)$this->props['text'] !== '']];
    }

    protected static function menuLabel(string $id): string
    {
        $label = ['cut' => 'Cut', 'copy' => 'Copy', 'paste' => 'Paste', 'all' => 'Select all'][$id] ?? $id;
        return class_exists(\JumPHP\Util\Lang::class) ? \JumPHP\Util\Lang::t($label) : $label;
    }

    protected function runMenu(string $id): void
    {
        $text = (string)$this->props['text'];
        match ($id) {
            'copy' => $this->copySelection($text),
            'cut' => $this->cutSelection(),
            'paste' => $this->pasteFromClipboard(),
            'all' => [$this->anchor, $this->cursor] = [0, mb_strlen($text)],
            default => null,
        };
    }

    private function cutSelection(): void
    {
        $text = (string)$this->props['text'];
        if ($this->copySelection($text) && $this->removeSelection($text)) {
            $this->props['text'] = $text;
            $this->form?->invalidate();
            $this->emit('change', $text);
        }
    }

    public function blur(): void
    {
        $this->close();
        $this->anchor = null;
        $this->dragging = false;
        $this->scrollX = 0;
        $this->composition = '';
        $this->caret = null;
    }
}
