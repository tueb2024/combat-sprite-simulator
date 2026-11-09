<?php

namespace JumPHP\Tile;

/**
 * Maps stored as raw bytes (one byte per tile, row-major), the format of many old games and of tools
 * that rip their levels. Optional "solidity" file: one byte per tile TYPE (index = tile value), giving a
 * collision shape through $shapes (byte => CollisionMask) and, with bit 7 set, a tile drawn in front of
 * the entities (layer "foreground").
 *
 *   $map = BinaryLoader::load('assets/maps/level1.bin', 256, 16, 32, 32, 'assets/tiles/level1.png', 16);
 *
 * Tile value v is drawn with tile v of the image (gid = v + 1, value 0 is drawn too unless $zeroIsEmpty).
 */
final class BinaryLoader
{
    /** @param array<int, CollisionMask> $shapes solidity byte (low 7 bits) => mask; 0 = empty */
    public static function load(string $file, int $width, int $height, int $tileWidth, int $tileHeight,
                                string $tilesetImage = '', int $tilesetColumns = 16, ?string $solidityFile = null,
                                array $shapes = [], bool $zeroIsEmpty = false): TileMap
    {
        $bytes = @file_get_contents($file);
        if ($bytes === false) {
            throw new \RuntimeException("Map file not found: $file");
        }
        $values = array_values(unpack('C*', $bytes) ?: []);
        $count = $width * $height;
        $values = array_pad(array_slice($values, 0, $count), $count, 0);

        $solidity = [];
        if ($solidityFile !== null && is_file($solidityFile)) {
            $solidity = array_values(unpack('C*', (string)file_get_contents($solidityFile)) ?: []);
        }

        $map = new TileMap($width, $height, $tileWidth, $tileHeight);
        $tileset = new TileSet('tiles', $tilesetImage, $tileWidth, $tileHeight, $tilesetColumns, 256, 1);
        $background = [];
        $foreground = [];
        $collision = [];
        foreach ($values as $i => $v) {
            $gid = ($zeroIsEmpty && $v === 0) ? 0 : $v + 1;
            $s = $solidity[$v] ?? 0;
            $front = ($s & 0x80) !== 0;
            $background[$i] = $front ? 0 : $gid;
            $foreground[$i] = $front ? $gid : 0;
            $collision[$i] = ($s & 0x7F) !== 0 ? $gid : 0;
        }
        foreach ($solidity as $v => $s) {
            $shape = $s & 0x7F;
            $tileset->masks[$v] = $shape === 0 ? CollisionMask::none() : ($shapes[$shape] ?? CollisionMask::full());
        }
        $map->addTileSet($tileset);
        $map->addLayer('background', $background);
        $map->addLayer('foreground', $foreground, ['foreground' => true]);
        $map->addLayer('collision', $collision, ['visible' => false]);
        if ($solidity === []) {
            // no solidity file: every non-zero tile is solid
            $map->layers['collision'] = array_map(fn (int $g) => ($g > 1 ? $g : 0), $background);
        }
        return $map;
    }
}
