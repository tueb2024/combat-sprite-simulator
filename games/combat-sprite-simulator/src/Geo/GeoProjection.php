<?php

namespace App\Geo;

/**
 * WGS84 -> WMTS GoogleMapsCompatible/PM pixel grid (256 px tiles).
 * At z=19 one source pixel is about 0.20 m at 48 degrees latitude.
 */
final class GeoProjection
{
    public const TILE_SIZE = 256;
    public const ZOOM = 19;
    private const MAX_LAT = 85.05112878;

    /** @return array{0: float, 1: float} */
    public static function toPixels(float $latitude, float $longitude, int $zoom = self::ZOOM): array
    {
        if ($zoom < 0 || $zoom > 19) {
            throw new \InvalidArgumentException('Supported WMTS zoom: 0..19');
        }
        $lat = deg2rad(max(-self::MAX_LAT, min(self::MAX_LAT, $latitude)));
        $world = self::TILE_SIZE * (2 ** $zoom);

        return [
            ($longitude + 180.0) / 360.0 * $world,
            (1.0 - asinh(tan($lat)) / M_PI) / 2.0 * $world,
        ];
    }

    /** @return array{0: int, 1: int} */
    public static function toTile(float $latitude, float $longitude, int $zoom = self::ZOOM): array
    {
        [$x, $y] = self::toPixels($latitude, $longitude, $zoom);
        return [(int) floor($x / self::TILE_SIZE), (int) floor($y / self::TILE_SIZE)];
    }

    public static function metersPerPixel(float $latitude, int $zoom = self::ZOOM): float
    {
        return cos(deg2rad($latitude)) * 40075016.686 / (self::TILE_SIZE * (2 ** $zoom));
    }

    public static function tileUrl(int $col, int $row, int $zoom = self::ZOOM): string
    {
        $n = 2 ** $zoom;
        if ($col < 0 || $row < 0 || $col >= $n || $row >= $n) {
            throw new \InvalidArgumentException('Tile coordinate out of bounds');
        }
        return 'https://data.geopf.fr/wmts?' . http_build_query([
            'SERVICE' => 'WMTS',
            'REQUEST' => 'GetTile',
            'VERSION' => '1.0.0',
            'LAYER' => 'HR.ORTHOIMAGERY.ORTHOPHOTOS',
            'STYLE' => 'normal',
            'FORMAT' => 'image/jpeg',
            'TILEMATRIXSET' => 'PM',
            'TILEMATRIX' => (string) $zoom,
            'TILEROW' => (string) $row,
            'TILECOL' => (string) $col,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
