<?php

namespace JumPHP\Gui;

use JumPHP\Renderer\Renderer;
use JumPHP\Renderer\TextDrawer;
use JumPHP\Util\Color;

/** Text sizes without any font (no SDL: the layout's unit tests, the designer's checks): draws nothing. */
final class EstimatedFont implements TextDrawer
{
    public function __construct(private float $px)
    {
    }

    public function draw(Renderer $r, string $text, float $x, float $y, float $scale = 1, Color|array|string $color = [255, 255, 255],
                         string $align = 'left', Color|array|string|null $shadow = null): void
    {
    }

    public function width(string $text, float $scale = 1): int
    {
        $max = 0;
        foreach (explode("\n", $text) as $line) {
            $max = max($max, mb_strlen($line));
        }
        return (int)round($max * $this->px * 0.55 * $scale);
    }

    public function height(float $scale = 1): int
    {
        return (int)round($this->px * $scale);
    }

    public function lineHeight(float $scale = 1): int
    {
        return (int)round($this->px * 1.3 * $scale);
    }
}
