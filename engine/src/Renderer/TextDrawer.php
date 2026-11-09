<?php

namespace JumPHP\Renderer;

use JumPHP\Util\Color;

/**
 * Something that draws text: BitmapFont (built in) or TtfFont (a .ttf file of the game).
 * Menus, HUD and dialogs take a TextDrawer, so a game can switch fonts without changing them.
 */
interface TextDrawer
{
    /** $align: 'left' (x is the left edge), 'center' (x is the middle) or 'right' (x is the right edge). */
    public function draw(Renderer $r, string $text, float $x, float $y, float $scale = 1, Color|array|string $color = [255, 255, 255],
                         string $align = 'left', Color|array|string|null $shadow = null): void;

    public function width(string $text, float $scale = 1): int;

    public function height(float $scale = 1): int;

    public function lineHeight(float $scale = 1): int;
}
