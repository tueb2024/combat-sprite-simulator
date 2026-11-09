<?php

namespace JumPHP\Tile;

/**
 * The solid pixels of one tile. Kinds:
 *  - none / full: nothing / everything (most tiles)
 *  - oneWay: solid from above only (jump through platforms)
 *  - heights: per column, how many pixels are solid from the bottom (slopes: [0, 1, 2, ... 15])
 *  - pixels: any shape, from rows of '#' (solid) and '.' (empty)
 *
 * Any mask can be one-way. Coordinates are local to the tile: (0, 0) = top-left pixel.
 */
final class CollisionMask
{
    public const NONE = 0;
    public const FULL = 1;
    public const HEIGHTS = 2;
    public const PIXELS = 3;

    /** @var int[] per column: first solid row from the top (height = tileHeight - top) */
    private array $tops = [];
    /** @var array<int, string> rows of '#'/'.' */
    private array $rows = [];

    private function __construct(public readonly int $kind, public readonly bool $oneWay = false)
    {
    }

    public static function none(): self
    {
        return new self(self::NONE);
    }

    public static function full(bool $oneWay = false): self
    {
        return new self(self::FULL, $oneWay);
    }

    public static function oneWay(): self
    {
        return new self(self::FULL, true);
    }

    /** @param int[] $heights per column, solid pixels counted from the bottom; $tileHeight of the tile */
    public static function heights(array $heights, int $tileHeight, bool $oneWay = false): self
    {
        $mask = new self(self::HEIGHTS, $oneWay);
        foreach (array_values($heights) as $x => $h) {
            $mask->tops[$x] = $tileHeight - max(0, min($tileHeight, (int)$h));
        }
        return $mask;
    }

    /** Straight slope from $fromHeight (left column) to $toHeight (right column). */
    public static function slope(int $tileWidth, int $tileHeight, int $fromHeight, int $toHeight, bool $oneWay = false): self
    {
        $heights = [];
        for ($x = 0; $x < $tileWidth; $x++) {
            $t = $tileWidth > 1 ? $x / ($tileWidth - 1) : 0;
            $heights[] = (int)round($fromHeight + ($toHeight - $fromHeight) * $t);
        }
        return self::heights($heights, $tileHeight, $oneWay);
    }

    /** @param string[] $rows '#' = solid, anything else = empty */
    public static function fromRows(array $rows, bool $oneWay = false): self
    {
        $mask = new self(self::PIXELS, $oneWay);
        $mask->rows = array_values($rows);
        return $mask;
    }

    /**
     * Tiled tile property "collision": full | none | oneway | slope:FROM,TO | heights:h0,h1,... | oneway-slope:FROM,TO
     */
    public static function parse(string $spec, int $tileWidth, int $tileHeight): self
    {
        $spec = strtolower(trim($spec));
        $oneWay = str_starts_with($spec, 'oneway-');
        if ($oneWay) {
            $spec = substr($spec, 7);
        }
        [$kind, $args] = array_pad(explode(':', $spec, 2), 2, '');
        $numbers = $args === '' ? [] : array_map('intval', explode(',', $args));
        return match ($kind) {
            'none', '', 'false', '0' => self::none(),
            'oneway' => self::oneWay(),
            'slope' => self::slope($tileWidth, $tileHeight, $numbers[0] ?? 0, $numbers[1] ?? $tileHeight, $oneWay),
            'heights' => self::heights($numbers, $tileHeight, $oneWay),
            default => self::full($oneWay), // full, solid, true, 1
        };
    }

    public function isSolid(int $x, int $y): bool
    {
        return match ($this->kind) {
            self::FULL => true,
            self::HEIGHTS => $y >= ($this->tops[$x] ?? PHP_INT_MAX),
            self::PIXELS => ($this->rows[$y][$x] ?? '.') === '#',
            default => false,
        };
    }

    /** First solid row of column $x from the top, null if the column is empty. */
    public function topAt(int $x, int $tileHeight): ?int
    {
        return match ($this->kind) {
            self::FULL => 0,
            self::HEIGHTS => ($this->tops[$x] ?? $tileHeight) < $tileHeight ? $this->tops[$x] : null,
            self::PIXELS => $this->pixelTop($x),
            default => null,
        };
    }

    private function pixelTop(int $x): ?int
    {
        foreach ($this->rows as $y => $row) {
            if (($row[$x] ?? '.') === '#') {
                return $y;
            }
        }
        return null;
    }
}
