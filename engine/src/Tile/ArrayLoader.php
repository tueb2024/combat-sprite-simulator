<?php

namespace JumPHP\Tile;

/**
 * Maps written in PHP code, one string per row: quick levels, tests, generated levels.
 *
 *   $map = ArrayLoader::fromRows([
 *       '....................',
 *       '..P.......C.........',
 *       '########..####/..###',
 *   ], legend: ['#' => 1, '/' => 2], objects: ['P' => 'player', 'C' => 'coin'], tileWidth: 16);
 *   $map->tilesets[0]->masks[1] = CollisionMask::slope(16, 16, 0, 16); // gid 2 = slope (local id 1)
 *
 * Legend characters become tiles of the layer "collision" (solid); object characters become map objects
 * of that type at that tile (spawnFromMap). '.' and ' ' are empty. Use addLayer() for decoration layers.
 * $tileset: an image whose tiles are drawn for the gids (gid 1 = first tile of the image), or null to draw
 * nothing (TileMapRenderer then fills solid tiles with a colour).
 */
final class ArrayLoader
{
    /**
     * @param string[] $rows
     * @param array<string, int> $legend character => gid
     * @param array<string, string> $objects character => object type
     */
    public static function fromRows(array $rows, array $legend = ['#' => 1], array $objects = [], int $tileWidth = 16,
                                    ?int $tileHeight = null, ?string $tileset = null, int $tilesetColumns = 8, string $layer = 'collision'): TileMap
    {
        $tileHeight ??= $tileWidth;
        $rows = array_values($rows);
        $height = count($rows);
        $width = max(array_map('strlen', $rows));
        $map = new TileMap($width, $height, $tileWidth, $tileHeight);
        $gids = array_fill(0, $width * $height, 0);
        foreach ($rows as $y => $row) {
            for ($x = 0, $n = strlen($row); $x < $n; $x++) {
                $c = $row[$x];
                if (isset($legend[$c])) {
                    $gids[$y * $width + $x] = $legend[$c];
                } elseif (isset($objects[$c])) {
                    $map->objects[] = [
                        'type' => $objects[$c], 'name' => $objects[$c],
                        'x' => (float)($x * $tileWidth), 'y' => (float)($y * $tileHeight),
                        'width' => (float)$tileWidth, 'height' => (float)$tileHeight,
                        'properties' => [], 'layer' => 'objects', 'gid' => 0,
                    ];
                }
            }
        }
        $map->addLayer($layer, $gids);
        $count = max(1, ...array_values($legend ?: [1]));
        $map->addTileSet(new TileSet('tiles', $tileset ?? '', $tileWidth, $tileHeight, $tilesetColumns, max($count, $tilesetColumns * 64)));
        return $map;
    }
}
