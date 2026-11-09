<?php

namespace JumPHP\Tile;

/**
 * A sheet of tiles: one image cut in a grid. Global tile ids (gid) of a map start at $firstGid;
 * local id = gid - firstGid, numbered left to right, top to bottom.
 */
class TileSet
{
    /** @var array<int, array<string, mixed>> local id => properties */
    public array $properties = [];
    /** @var array<int, CollisionMask> local id => mask */
    public array $masks = [];
    /** @var array<int, array<int, array{0: int, 1: float}>> local id => [[local id, seconds], ...] */
    public array $animations = [];

    public function __construct(
        public string $name,
        public string $image,             // path of the image (absolute, or relative to the game root)
        public int $tileWidth,
        public int $tileHeight,
        public int $columns,
        public int $tileCount,
        public int $firstGid = 1,
        public int $margin = 0,
        public int $spacing = 0,
    ) {
    }

    public function contains(int $gid): bool
    {
        return $gid >= $this->firstGid && $gid < $this->firstGid + $this->tileCount;
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} source rectangle of a local id in the image */
    public function srcRect(int $localId): array
    {
        $cols = max(1, $this->columns);
        $col = $localId % $cols;
        $row = intdiv($localId, $cols);
        return [
            $this->margin + $col * ($this->tileWidth + $this->spacing),
            $this->margin + $row * ($this->tileHeight + $this->spacing),
            $this->tileWidth,
            $this->tileHeight,
        ];
    }

    /** The local id to draw now for an animated tile ($time in seconds). */
    public function animatedId(int $localId, float $time): int
    {
        $frames = $this->animations[$localId] ?? null;
        if ($frames === null) {
            return $localId;
        }
        $total = 0.0;
        foreach ($frames as [, $duration]) {
            $total += $duration;
        }
        $t = $total > 0 ? fmod($time, $total) : 0;
        foreach ($frames as [$id, $duration]) {
            if ($t < $duration) {
                return $id;
            }
            $t -= $duration;
        }
        return $localId;
    }

    public function textureName(): string
    {
        return 'tileset:' . $this->image;
    }
}
