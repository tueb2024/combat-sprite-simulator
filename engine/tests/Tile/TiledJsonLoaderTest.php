<?php

namespace JumPHP\Tests\Tile;

use JumPHP\Tile\TiledJsonLoader;
use JumPHP\Tile\TileMap;
use PHPUnit\Framework\TestCase;

final class TiledJsonLoaderTest extends TestCase
{
    public function testLoadsTheMap(): void
    {
        $map = TiledJsonLoader::load(__DIR__ . '/../fixtures/map.tmj');
        self::assertSame([4, 2, 16, 16], [$map->width, $map->height, $map->tileWidth, $map->tileHeight]);
        self::assertSame('level1', $map->property('music'));
        self::assertSame([32, 48, 64], $map->backgroundColor);

        // base64 layer, with a horizontally flipped tile (high bit of the gid)
        self::assertSame([0, 1, 2, 2, 0, 0, 1, 1], $map->layers['collision']);
        self::assertSame([3 => TileMap::FLIP_H], $map->flips['collision']);
        self::assertSame('collision', $map->getCollisionLayer());
        self::assertTrue($map->layerProperties['front']['foreground']);

        // tileset: image path made absolute, per-tile mask, properties, animation
        $ts = $map->tilesets[0];
        self::assertStringEndsWith('/tests/img/tiles.png', $ts->image);
        self::assertTrue($map->maskFor(1)->oneWay);
        self::assertTrue($map->tileProperty(2, 'hazard'));
        self::assertSame(2, $ts->animatedId(1, 0.15));

        // objects: tile objects are anchored bottom-left in Tiled, here top-left
        self::assertSame(['x' => 8.0, 'y' => 4.0], array_intersect_key($map->object('start'), ['x' => 0, 'y' => 0]));
        $coin = $map->objectsOfType('coin')[0];
        self::assertSame(16.0, $coin['y']);
        self::assertSame(5, $coin['properties']['value']);
    }

    public function testSplitGidIsSafeFor32BitFloats(): void
    {
        self::assertSame([5, 4], TiledJsonLoader::splitGid(2147483653.0));
        self::assertSame([7, 0], TiledJsonLoader::splitGid(7));
    }
}
