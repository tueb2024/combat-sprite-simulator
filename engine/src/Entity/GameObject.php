<?php

namespace JumPHP\Entity;

/**
 * An object of a level: enemy, item, platform, spring... It has a type (from the map: Tiled object
 * "type"/"class", or a number) and the properties given in the editor.
 * The EntityManager creates them from the map objects through its registry (type => class).
 */
class GameObject extends Entity
{
    /** @param array<string, mixed> $properties */
    public function __construct(
        float $x = 0,
        float $y = 0,
        int $width = 16,
        int $height = 16,
        public string $type = '',
        public string $name = '',
        public array $properties = [],
    ) {
        $this->x = $x;
        $this->y = $y;
        $this->width = $width;
        $this->height = $height;
    }

    public function property(string $name, mixed $default = null): mixed
    {
        return $this->properties[$name] ?? $default;
    }

    /** Places the object so that its bottom sits on the bottom of the tile ($tileX, $tileY). */
    public function placeOnTile(int $tileX, int $tileY, int $tileWidth, int $tileHeight, int $bottomOffset = 0): void
    {
        $this->x = $tileX * $tileWidth + ($tileWidth - $this->width) / 2;
        $this->y = ($tileY + 1) * $tileHeight - $this->height - $bottomOffset;
    }

    /** Frames helper: frames([[x, y, w, h], ...]) => ['coords' => [['x' =>, 'y' =>, 'w' =>, 'h' =>], ...]] */
    public static function frames(array $rects, array $flags = []): array
    {
        $coords = [];
        foreach ($rects as $r) {
            $coords[] = ['x' => $r[0], 'y' => $r[1], 'w' => $r[2], 'h' => $r[3]];
        }
        return ['flags' => $flags, 'coords' => $coords];
    }
}
