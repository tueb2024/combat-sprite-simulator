<?php

namespace Tests;

use App\Geo\GeoProjection;
use JumPHP\Testing\GameTestCase;

final class GeoProjectionTest extends GameTestCase
{
    public function testMetroFrancePixelScaleAtZoom19(): void
    {
        $metersPerPixel = GeoProjection::metersPerPixel(48.17);
        self::assertGreaterThan(0.19, $metersPerPixel);
        self::assertLessThan(0.21, $metersPerPixel);
    }

    public function testAddressCoordinatesMapToConsistentTileAndOffset(): void
    {
        [$x, $y] = GeoProjection::toPixels(48.17, 1.54);
        [$col, $row] = GeoProjection::toTile(48.17, 1.54);
        self::assertSame($col, (int) floor($x / 256));
        self::assertSame($row, (int) floor($y / 256));
        self::assertGreaterThanOrEqual(0, $col);
        self::assertLessThan(2 ** 19, $col);
        self::assertGreaterThanOrEqual(0, $row);
        self::assertLessThan(2 ** 19, $row);
    }

    public function testWmtsUrlContainsRequiredParameters(): void
    {
        [$col, $row] = GeoProjection::toTile(48.17, 1.54);
        parse_str((string) parse_url(GeoProjection::tileUrl($col, $row), PHP_URL_QUERY), $query);
        self::assertSame('WMTS', $query['SERVICE']);
        self::assertSame('GetTile', $query['REQUEST']);
        self::assertSame('HR.ORTHOIMAGERY.ORTHOPHOTOS', $query['LAYER']);
        self::assertSame('PM', $query['TILEMATRIXSET']);
        self::assertSame('19', $query['TILEMATRIX']);
        self::assertSame((string) $col, $query['TILECOL']);
        self::assertSame((string) $row, $query['TILEROW']);
    }
}
