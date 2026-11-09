<?php

namespace App\Geo;

/**
 * A continuous northbound mission corridor anchored to a real IGN-geocoded address.
 * Mission waves and the boss use the same world origin: no terrain reset at a phase change.
 *
 * Zoom 19 is approximately 20 cm per pixel in metropolitan France.
 */
final class MissionTerrain
{
    private float $originX;
    private float $originY;

    public function __construct(
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly string $label,
    ) {
        [$this->originX, $this->originY] = GeoProjection::toPixels($latitude, $longitude);
    }

    /** @return array{0:float,1:float} Top-left world pixel of the viewport */
    public function camera(float $scroll, float $playerY = 405.0, float $width = 320.0): array
    {
        // At t=0 the player is directly above the requested address.
        // Decreasing world Y makes the ground stream southward in the viewport.
        return [$this->originX - $width / 2, $this->originY - $playerY - $scroll];
    }

    /** @return array{0:float,1:float} Real orthophoto position below a screen point */
    public function screenToWorld(float $screenX, float $screenY, float $scroll): array
    {
        [$cameraX, $cameraY] = $this->camera($scroll);
        return [$cameraX + $screenX, $cameraY + $screenY];
    }

    public function metersPerPixel(): float
    {
        return GeoProjection::metersPerPixel($this->latitude);
    }
}
