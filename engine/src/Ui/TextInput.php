<?php

namespace JumPHP\Ui;

use JumPHP\Input\ActionMap;
use JumPHP\Input\Keyboard;
use JumPHP\Renderer\Renderer;
use JumPHP\Renderer\TextDrawer;

/**
 * Text entry that works with a keyboard AND with a pad / touch screen: type letters directly, or move on
 * the on-screen grid and press confirm. Used for names and room codes.
 *
 *   $this->input = new TextInput(maxLength: 4, charset: TextInput::ROOM_CODE);
 *   // update: $state = $this->input->update($this->actions(), $keyboard);  'done' | 'cancel' | null
 *   // render: $this->input->render($r, $this->font(), $this->width() / 2, 60);
 */
class TextInput
{
    public const LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    public const ALNUM = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    /** Codes of the php-wasm-network relay: 4 letters, no I or O. */
    public const ROOM_CODE = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    public string $value = '';
    private int $cursor = 0;
    /** @var string[] */
    private array $keys;

    public function __construct(public int $maxLength = 8, public string $charset = self::ALNUM, public int $columns = 8)
    {
        $this->keys = array_merge(str_split($charset), ['DEL', 'OK']);
    }

    /** @return 'done'|'cancel'|null */
    public function update(ActionMap $actions, ?Keyboard $keyboard = null): ?string
    {
        foreach ($keyboard?->getTyped() ?? [] as $key) {
            if ($key === 8) {                       // Backspace
                $this->value = substr($this->value, 0, -1);
                return null;
            }
            if ($key === 13 || $key === 0x4000009E || $key === 1073741912) { // Return, keypad Enter
                return $this->value !== '' ? 'done' : null;
            }
            if ($key >= 32 && $key < 127) {
                $char = strtoupper(chr($key));
                if (str_contains($this->charset, $char)) {
                    $this->type($char);
                    return null;
                }
            }
        }
        $n = count($this->keys);
        if ($actions->isPressed('right')) {
            $this->cursor = ($this->cursor + 1) % $n;
        } elseif ($actions->isPressed('left')) {
            $this->cursor = ($this->cursor - 1 + $n) % $n;
        } elseif ($actions->isPressed('down')) {
            $this->cursor = min($n - 1, $this->cursor + $this->columns);
        } elseif ($actions->isPressed('up')) {
            $this->cursor = max(0, $this->cursor - $this->columns);
        } elseif ($actions->isPressed('confirm')) {
            $key = $this->keys[$this->cursor];
            if ($key === 'OK') {
                return $this->value !== '' ? 'done' : null;
            }
            if ($key === 'DEL') {
                $this->value = substr($this->value, 0, -1);
            } else {
                $this->type($key);
            }
        } elseif ($actions->isPressed('back')) {
            return 'cancel';
        }
        return null;
    }

    private function type(string $char): void
    {
        if (strlen($this->value) < $this->maxLength) {
            $this->value .= $char;
        }
        if (strlen($this->value) >= $this->maxLength) {
            $this->cursor = count($this->keys) - 1; // full: jump to OK
        }
    }

    public function render(Renderer $r, TextDrawer $font, float $cx, float $y, float $scale = 1.0): void
    {
        $shown = str_pad($this->value, $this->maxLength, '_');
        $font->draw($r, implode(' ', str_split($shown)), $cx, $y, $scale * 2, [255, 255, 255], 'center');
        $cell = (int)round(12 * $scale);
        $gridY = $y + $font->lineHeight($scale * 2) + 8 * $scale;
        $rows = (int)ceil(count($this->keys) / $this->columns);
        $left = $cx - $this->columns * $cell / 2;
        foreach ($this->keys as $i => $key) {
            $col = $i % $this->columns;
            $row = intdiv($i, $this->columns);
            $kx = $left + $col * $cell + $cell / 2;
            $ky = $gridY + $row * $cell;
            if (strlen($key) > 1) {
                // DEL / OK: on their own last row
                $kx = $cx + ($key === 'DEL' ? -1 : 1) * $cell * 1.5;
                $ky = $gridY + $rows * $cell;
            }
            $selected = $i === $this->cursor;
            $font->draw($r, $key, $kx, $ky, $scale, $selected ? [255, 255, 80] : [170, 170, 170], 'center');
            if ($selected) {
                $w = $font->width($key, $scale) + 4;
                $r->drawRect($kx - $w / 2, $ky - 2, $w, $font->height($scale) + 4, [255, 255, 80]);
            }
        }
    }
}
