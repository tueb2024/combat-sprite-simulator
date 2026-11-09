<?php

namespace JumPHP\Gui;

use JumPHP\Renderer\Renderer;
use JumPHP\Renderer\TextDrawer;
use JumPHP\Util\Color;

/** Another font drawn bigger or smaller (the pixel font at a GUI text size). */
final class ScaledFont implements TextDrawer
{
    public function __construct(private TextDrawer $font, private float $factor)
    {
    }

    public function draw(Renderer $r, string $text, float $x, float $y, float $scale = 1, Color|array|string $color = [255, 255, 255],
                         string $align = 'left', Color|array|string|null $shadow = null): void
    {
        $this->font->draw($r, $text, $x, $y, $scale * $this->factor, $color, $align, $shadow);
    }

    public function width(string $text, float $scale = 1): int
    {
        return $this->font->width($text, $scale * $this->factor);
    }

    public function height(float $scale = 1): int
    {
        return $this->font->height($scale * $this->factor);
    }

    public function lineHeight(float $scale = 1): int
    {
        return $this->font->lineHeight($scale * $this->factor);
    }
}
