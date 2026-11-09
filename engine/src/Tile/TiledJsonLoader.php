<?php

namespace JumPHP\Tile;

/**
 * Loads a map made with the Tiled editor (https://www.mapeditor.org), saved as JSON (.tmj / .json).
 *
 * Supported: finite orthogonal maps; tile layers (CSV/array or base64, uncompressed / zlib / gzip);
 * embedded or external (.tsj / .json) tilesets made of one image; flipped tiles; animated tiles;
 * tile properties; per-tile collision from the property "collision" (see CollisionMask::parse) or from
 * rectangles / polygons drawn in Tiled's collision editor; object layers; image layers (parallax);
 * map and layer properties ("foreground": true draws a layer over the entities, "collision": true
 * makes it the collision layer).
 *
 * Image paths are made absolute (relative to the map file), so the map works from any directory.
 */
final class TiledJsonLoader
{
    public static function load(string $file): TileMap
    {
        $data = self::readJson($file);
        if (!empty($data['infinite'])) {
            throw new \RuntimeException("$file: infinite Tiled maps are not supported (Map > Map Properties > Infinite: off)");
        }
        $dir = dirname($file);
        $map = new TileMap((int)$data['width'], (int)$data['height'], (int)$data['tilewidth'], (int)$data['tileheight']);
        $map->properties = self::properties($data['properties'] ?? []);
        if (!empty($data['backgroundcolor'])) {
            $hex = ltrim($data['backgroundcolor'], '#');
            $hex = strlen($hex) === 8 ? substr($hex, 2) : $hex; // #AARRGGBB
            $map->backgroundColor = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        }

        foreach ($data['tilesets'] ?? [] as $ts) {
            $firstGid = (int)$ts['firstgid'];
            $tsDir = $dir;
            if (isset($ts['source'])) {
                $source = self::join($dir, $ts['source']);
                $ts = self::readJson($source);
                $tsDir = dirname($source);
            }
            $map->addTileSet(self::tileset($ts, $firstGid, $tsDir));
        }

        self::layers($map, $data['layers'] ?? [], $dir);
        return $map;
    }

    private static function layers(TileMap $map, array $layers, string $dir, string $prefix = ''): void
    {
        foreach ($layers as $layer) {
            $name = $prefix . ($layer['name'] ?? 'layer');
            $props = self::properties($layer['properties'] ?? []);
            $props['visible'] = (bool)($layer['visible'] ?? true);
            $props['parallaxX'] = (float)($layer['parallaxx'] ?? 1.0);
            $props['parallaxY'] = (float)($layer['parallaxy'] ?? 1.0);
            switch ($layer['type'] ?? '') {
                case 'tilelayer':
                    [$gids, $flips] = self::decodeTiles($layer, $map->width * $map->height);
                    $map->addLayer($name, $gids, $props);
                    if ($flips !== []) {
                        $map->flips[$name] = $flips;
                    }
                    break;
                case 'objectgroup':
                    foreach ($layer['objects'] ?? [] as $o) {
                        $gid = 0;
                        $y = (float)($o['y'] ?? 0);
                        if (isset($o['gid'])) {
                            [$gid] = self::splitGid($o['gid']);
                            $y -= (float)($o['height'] ?? 0); // tile objects are anchored bottom-left
                        }
                        $map->objects[] = [
                            'type' => (string)($o['type'] ?? $o['class'] ?? ''),
                            'name' => (string)($o['name'] ?? ''),
                            'x' => (float)($o['x'] ?? 0),
                            'y' => $y,
                            'width' => (float)($o['width'] ?? 0),
                            'height' => (float)($o['height'] ?? 0),
                            'properties' => self::properties($o['properties'] ?? []),
                            'layer' => $name,
                            'gid' => $gid,
                        ];
                    }
                    break;
                case 'imagelayer':
                    if (!empty($layer['image'])) {
                        $map->imageLayers[] = [
                            'name' => $name,
                            'image' => self::join($dir, $layer['image']),
                            'parallaxX' => $props['parallaxX'],
                            'parallaxY' => $props['parallaxY'],
                            'offsetX' => (float)($layer['offsetx'] ?? 0),
                            'offsetY' => (float)($layer['offsety'] ?? 0),
                            'repeatX' => !empty($layer['repeatx']),
                            'repeatY' => !empty($layer['repeaty']),
                        ];
                    }
                    break;
                case 'group':
                    self::layers($map, $layer['layers'] ?? [], $dir, $name . '/');
                    break;
            }
        }
    }

    private static function tileset(array $ts, int $firstGid, string $dir): TileSet
    {
        if (empty($ts['image'])) {
            throw new \RuntimeException("Tileset '" . ($ts['name'] ?? '?') . "': image collection tilesets are not supported (use one image)");
        }
        $tw = (int)$ts['tilewidth'];
        $th = (int)$ts['tileheight'];
        $tileset = new TileSet(
            (string)($ts['name'] ?? 'tiles'),
            self::join($dir, $ts['image']),
            $tw,
            $th,
            (int)($ts['columns'] ?? max(1, intdiv((int)($ts['imagewidth'] ?? $tw), $tw))),
            (int)($ts['tilecount'] ?? 0),
            $firstGid,
            (int)($ts['margin'] ?? 0),
            (int)($ts['spacing'] ?? 0),
        );
        foreach ($ts['tiles'] ?? [] as $tile) {
            $id = (int)$tile['id'];
            $props = self::properties($tile['properties'] ?? []);
            if (isset($tile['type']) || isset($tile['class'])) {
                $props['type'] ??= $tile['type'] ?? $tile['class'];
            }
            $tileset->properties[$id] = $props;
            if (isset($props['collision'])) {
                $tileset->masks[$id] = CollisionMask::parse((string)(is_bool($props['collision']) ? ($props['collision'] ? 'full' : 'none') : $props['collision']), $tw, $th);
            } elseif (!empty($tile['objectgroup']['objects'])) {
                $tileset->masks[$id] = self::maskFromShapes($tile['objectgroup']['objects'], $tw, $th, !empty($props['oneway']));
            } elseif (!empty($props['oneway'])) {
                $tileset->masks[$id] = CollisionMask::oneWay();
            }
            foreach ($tile['animation'] ?? [] as $frame) {
                $tileset->animations[$id][] = [(int)$frame['tileid'], ((int)$frame['duration']) / 1000];
            }
        }
        return $tileset;
    }

    /** Rectangles / polygons of Tiled's collision editor, rasterised into a pixel mask. */
    private static function maskFromShapes(array $objects, int $tw, int $th, bool $oneWay): CollisionMask
    {
        $rows = array_fill(0, $th, str_repeat('.', $tw));
        foreach ($objects as $o) {
            $ox = (float)($o['x'] ?? 0);
            $oy = (float)($o['y'] ?? 0);
            $polygon = null;
            if (isset($o['polygon'])) {
                $polygon = array_map(fn ($p) => [$ox + $p['x'], $oy + $p['y']], $o['polygon']);
            }
            for ($y = 0; $y < $th; $y++) {
                for ($x = 0; $x < $tw; $x++) {
                    $px = $x + 0.5;
                    $py = $y + 0.5;
                    $inside = $polygon !== null
                        ? self::inPolygon($px, $py, $polygon)
                        : ($px >= $ox && $px < $ox + ($o['width'] ?? 0) && $py >= $oy && $py < $oy + ($o['height'] ?? 0));
                    if ($inside) {
                        $rows[$y][$x] = '#';
                    }
                }
            }
        }
        return CollisionMask::fromRows($rows, $oneWay);
    }

    private static function inPolygon(float $x, float $y, array $points): bool
    {
        $inside = false;
        for ($i = 0, $j = count($points) - 1, $n = count($points); $i < $n; $j = $i++) {
            [$xi, $yi] = $points[$i];
            [$xj, $yj] = $points[$j];
            if ((($yi > $y) !== ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-9) + $xi)) {
                $inside = !$inside;
            }
        }
        return $inside;
    }

    /** @return array{0: int[], 1: array<int, int>} gids and flip flags */
    private static function decodeTiles(array $layer, int $count): array
    {
        $raw = $layer['data'] ?? [];
        if (is_string($raw)) {
            $bytes = base64_decode($raw);
            $bytes = match ($layer['compression'] ?? '') {
                'zlib' => gzuncompress($bytes),
                'gzip' => gzdecode($bytes),
                '' => $bytes,
                default => throw new \RuntimeException("Tile layer compression '{$layer['compression']}' is not supported (use CSV, base64, zlib or gzip)"),
            };
            // little-endian unsigned 32-bit: two 16-bit halves keep it exact on 32-bit PHP (browser build)
            $halves = array_values(unpack('v*', (string)$bytes));
            $raw = [];
            for ($i = 0, $n = count($halves); $i + 1 < $n; $i += 2) {
                $raw[] = $halves[$i] + $halves[$i + 1] * 65536.0;
            }
        }
        $gids = [];
        $flips = [];
        foreach ($raw as $i => $value) {
            [$gid, $flags] = self::splitGid($value);
            $gids[] = $gid;
            if ($flags !== 0) {
                $flips[$i] = $flags;
            }
        }
        return [array_pad($gids, $count, 0), $flips];
    }

    /**
     * Tiled stores flip flags in the 3 high bits of the gid. Values above 2^31 are floats on 32-bit PHP:
     * divide instead of masking. @return array{0: int, 1: int} gid, flags (TileMap::FLIP_*)
     */
    public static function splitGid(int|float $value): array
    {
        $flags = (int)floor($value / 0x20000000);   // 2^29
        $gid = (int)($value - $flags * 0x20000000);
        return [$gid, $flags & 7];
    }

    /** Tiled properties [{name, type, value}] => [name => value] */
    private static function properties(array $list): array
    {
        $props = [];
        foreach ($list as $p) {
            if (isset($p['name'])) {
                $props[$p['name']] = $p['value'] ?? null;
            }
        }
        return $props;
    }

    private static function readJson(string $file): array
    {
        if (!is_file($file)) {
            throw new \RuntimeException("Map file not found: $file");
        }
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data)) {
            throw new \RuntimeException("$file: invalid JSON (save the map as JSON in Tiled: .tmj)");
        }
        return $data;
    }

    private static function join(string $dir, string $path): string
    {
        if (\JumPHP\Kernel\Platform::isAbsolutePath($path)) {
            return $path;
        }
        $parts = [];
        foreach (explode('/', $dir . '/' . $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }
        return (str_starts_with($dir, '/') ? '/' : '') . implode('/', $parts);
    }
}
