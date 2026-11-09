<?php

namespace JumPHP\Util;

/**
 * An RGBA colour. SDL calls take the four components separately; Color::of() accepts the usual shapes:
 * a Color, [r, g, b] / [r, g, b, a], or '#rrggbb' / '#rrggbbaa'.
 * No 0xAARRGGBB integers here: 0xFF000000 does not fit the 32-bit integers of the browser build.
 */
final class Color
{
    public function __construct(
        public readonly int $r = 0,
        public readonly int $g = 0,
        public readonly int $b = 0,
        public readonly int $a = 255,
    ) {
    }

    public static function of(self|array|string $color): self
    {
        if ($color instanceof self) {
            return $color;
        }
        if (is_string($color)) {
            $hex = ltrim($color, '#');
            if (strlen($hex) === 3) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            }
            return new self(
                hexdec(substr($hex, 0, 2)),
                hexdec(substr($hex, 2, 2)),
                hexdec(substr($hex, 4, 2)),
                strlen($hex) >= 8 ? hexdec(substr($hex, 6, 2)) : 255,
            );
        }
        $c = array_values($color);
        return new self((int)($c[0] ?? 0), (int)($c[1] ?? 0), (int)($c[2] ?? 0), (int)($c[3] ?? 255));
    }

    public function withAlpha(int $a): self
    {
        return new self($this->r, $this->g, $this->b, $a);
    }

    public function key(): string
    {
        return $this->r . ',' . $this->g . ',' . $this->b . ',' . $this->a;
    }

    public static function white(): self { return new self(255, 255, 255); }
    public static function black(): self { return new self(0, 0, 0); }
}
