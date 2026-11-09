<?php

namespace JumPHP\Tile;

/**
 * A tile map: layers of global tile ids (0 = empty), the tilesets they come from, the objects placed in
 * the editor and the map properties. Pure data (no SDL): load it with a loader, draw it with
 * TileMapRenderer, collide with it through Physics\CollisionSystem.
 *
 * Collisions use one layer: the one named "collision", else the first layer with the property
 * collision = true, else the first tile layer. A tile of that layer is solid unless its tileset gives it
 * another mask (Tiled tile property "collision": none | oneway | slope:0,16 | heights:...).
 */
class TileMap
{
    public const FLIP_H = 4;
    public const FLIP_V = 2;
    public const FLIP_D = 1;

    /** @var array<string, int[]> layer name => gids, row-major (width * height) */
    public array $layers = [];
    /** @var array<string, int[]> layer name => flip flags (FLIP_*) by index, only where set */
    public array $flips = [];
    /** @var array<string, array<string, mixed>> layer name => properties (foreground, visible, parallax...) */
    public array $layerProperties = [];
    /** @var TileSet[] by firstGid ascending */
    public array $tilesets = [];
    /** @var array<int, array{type: string, name: string, x: float, y: float, width: float, height: float, properties: array, layer: string, gid: int}> */
    public array $objects = [];
    /** @var array<string, mixed> */
    public array $properties = [];
    /** @var array<int, array{name: string, image: string, parallaxX: float, parallaxY: float, offsetX: float, offsetY: float, repeatX: bool, repeatY: bool}> */
    public array $imageLayers = [];
    /** @var array{0: int, 1: int, 2: int}|null */
    public ?array $backgroundColor = null;

    public ?string $collisionLayer = null;
    /** What a tile of the collision layer is when its tileset says nothing. */
    public bool $solidByDefault = true;

    private int $version = 0;
    /** @var callable[] fn(string $layer, int $tx, int $ty, int $old, int $new) */
    private array $tileListeners = [];
    /** @var array<int, array{0: ?TileSet, 1: ?CollisionMask}> gid lookup cache */
    private array $gidCache = [];

    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly int $tileWidth,
        public readonly int $tileHeight,
    ) {
    }

    public function pixelWidth(): int
    {
        return $this->width * $this->tileWidth;
    }

    public function pixelHeight(): int
    {
        return $this->height * $this->tileHeight;
    }

    /** @param int[] $gids row-major, width * height values */
    public function addLayer(string $name, array $gids, array $properties = []): void
    {
        if (count($gids) !== $this->width * $this->height) {
            throw new \InvalidArgumentException("Layer '$name': " . count($gids) . " tiles, expected {$this->width}x{$this->height}");
        }
        $this->layers[$name] = array_values($gids);
        $this->layerProperties[$name] = $properties;
    }

    public function addTileSet(TileSet $tileset): void
    {
        $this->tilesets[] = $tileset;
        usort($this->tilesets, fn (TileSet $a, TileSet $b) => $a->firstGid <=> $b->firstGid);
        $this->gidCache = [];
    }

    public function getCollisionLayer(): ?string
    {
        if ($this->collisionLayer !== null) {
            return $this->collisionLayer;
        }
        if (isset($this->layers['collision'])) {
            return $this->collisionLayer = 'collision';
        }
        foreach ($this->layerProperties as $name => $props) {
            if (!empty($props['collision'])) {
                return $this->collisionLayer = $name;
            }
        }
        return $this->collisionLayer = array_key_first($this->layers);
    }

    public function getTile(string $layer, int $tx, int $ty): int
    {
        if ($tx < 0 || $ty < 0 || $tx >= $this->width || $ty >= $this->height) {
            return 0;
        }
        return $this->layers[$layer][$ty * $this->width + $tx] ?? 0;
    }

    /** Changes a tile (collected item, opened door...): bumps the version, tells the listeners (network, replay). */
    public function setTile(string $layer, int $tx, int $ty, int $gid): void
    {
        if ($tx < 0 || $ty < 0 || $tx >= $this->width || $ty >= $this->height || !isset($this->layers[$layer])) {
            return;
        }
        $i = $ty * $this->width + $tx;
        $old = $this->layers[$layer][$i];
        if ($old === $gid) {
            return;
        }
        $this->layers[$layer][$i] = $gid;
        unset($this->flips[$layer][$i]);
        $this->version++;
        foreach ($this->tileListeners as $listener) {
            $listener($layer, $tx, $ty, $old, $gid);
        }
    }

    public function onTileChanged(callable $listener): void
    {
        $this->tileListeners[] = $listener;
    }

    /** Changes with every setTile(): renderers rebuild their cache when it moves. */
    public function getVersion(): int
    {
        return $this->version;
    }

    public function tilesetFor(int $gid): ?TileSet
    {
        return $this->lookup($gid)[0];
    }

    /** The collision mask of a gid of the collision layer. */
    public function maskFor(int $gid): ?CollisionMask
    {
        return $gid === 0 ? null : $this->lookup($gid)[1];
    }

    /** @return array<string, mixed> properties of the tile (from its tileset) */
    public function tileProperties(int $gid): array
    {
        $ts = $this->tilesetFor($gid);
        return $ts?->properties[$gid - $ts->firstGid] ?? [];
    }

    public function tileProperty(int $gid, string $name, mixed $default = null): mixed
    {
        return $this->tileProperties($gid)[$name] ?? $default;
    }

    /** @return array{0: ?TileSet, 1: ?CollisionMask} */
    private function lookup(int $gid): array
    {
        if (isset($this->gidCache[$gid])) {
            return $this->gidCache[$gid];
        }
        $found = null;
        foreach ($this->tilesets as $ts) {
            if ($ts->contains($gid)) {
                $found = $ts;
            }
        }
        $mask = null;
        if ($found !== null && isset($found->masks[$gid - $found->firstGid])) {
            $mask = $found->masks[$gid - $found->firstGid];
        } elseif ($this->solidByDefault) {
            $mask = CollisionMask::full();
        }
        return $this->gidCache[$gid] = [$found, $mask];
    }

    /** @return array<int, array> the objects of a type (Tiled "type"/"class") */
    public function objectsOfType(string $type): array
    {
        return array_values(array_filter($this->objects, fn (array $o) => $o['type'] === $type));
    }

    /** The first object named $name (spawn points: "start"). */
    public function object(string $name): ?array
    {
        foreach ($this->objects as $o) {
            if ($o['name'] === $name || $o['type'] === $name) {
                return $o;
            }
        }
        return null;
    }

    public function property(string $name, mixed $default = null): mixed
    {
        return $this->properties[$name] ?? $default;
    }
}
