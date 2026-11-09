<?php

namespace App\Scene;

use App\Geo\AddressLookup;
use App\Geo\AircraftShadow;
use App\Geo\GeoProjection;
use App\Geo\OrthoTileManager;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

/**
 * Address-based low-altitude orthophoto flight.
 * No separate JS map renderer: PHP coordinates + JumPHP SDL rendering.
 */
final class GeoFlightScene extends AbstractScene
{
    private ?OrthoTileManager $tiles = null;
    private $shadowTexture = null;
    private $hurricaneTexture = null;
    private $addressListener = null;

    private float $planeX = 0;
    private float $planeY = 0;
    private float $seconds = 0;
    private int $lookupGeneration = 0;
    private string $location = 'Beauce - demonstration';
    private string $status = 'Enter a French address above the game';
    private float $latitude = 48.17;

    private const PLANE_X = 160;
    private const PLANE_Y = 300;
    private const ALTITUDE_M = 15.0;
    private const FLIGHT_SPEED_PX_S = 40.0;

    public function enter(array $params = []): void
    {
        $this->seconds = 0;
        $this->lookupGeneration++;
        $this->location = 'Beauce - demonstration';
        $this->status = 'IGN 20cm orthophoto - loading';
        $this->setPosition(48.17, 1.54, $this->location);

        $this->tiles = new OrthoTileManager($this->engine->http(), $this->sdl());
        if (!$this->sdl()->hasTexture('combat-hurricane')) {
            $this->sdl()->loadTexture('combat-hurricane', 'assets/sprites/hurricane-atlas.png');
        }
        $this->hurricaneTexture = $this->sdl()->getTexture('combat-hurricane')['texture'];

        $atlas = $this->engine->config->path('assets/sprites/hurricane-atlas.png');
        $this->shadowTexture = AircraftShadow::create($this->sdl(), $atlas);

        $this->addressListener = function (array $event): void {
            $address = (string) ($event['address'] ?? '');
            $this->searchAddress($address);
        };
        $this->engine->bridge->on('fly-to-address', $this->addressListener);
        if (isset($params['address']) && is_string($params['address'])) {
            $this->searchAddress($params['address']);
        }
    }

    public function exit(): void
    {
        ++$this->lookupGeneration;
        if ($this->addressListener !== null) {
            $this->engine->bridge->removeListener('fly-to-address', $this->addressListener);
            $this->addressListener = null;
        }
        $this->tiles?->stop();
        $this->tiles = null;
        if ($this->shadowTexture !== null) {
            $this->sdl()->releaseTexture('css-flight-shadow');
            $this->shadowTexture = null;
        }
    }

    private function searchAddress(string $address): void
    {
        $this->status = 'Searching address...';
        $generation = ++$this->lookupGeneration;
        (new AddressLookup($this->engine->http()))->search($address,
            function (float $lat, float $lon, string $label) use ($generation): void {
                if ($generation !== $this->lookupGeneration) {
                    return;
                }
                $this->tiles?->stop();
                $this->tiles = new OrthoTileManager($this->engine->http(), $this->sdl());
                $this->setPosition($lat, $lon, $label);
                $this->status = 'Flying over ' . $label;
            },
            function (string $message) use ($generation): void {
                if ($generation === $this->lookupGeneration) {
                    $this->status = $message;
                }
            }
        );
    }

    private function setPosition(float $lat, float $lon, string $label): void
    {
        [$this->planeX, $this->planeY] = GeoProjection::toPixels($lat, $lon);
        $this->latitude = $lat;
        $this->location = $label;
    }

    public function update(float $dt): void
    {
        if ($this->actions()->isPressed('back')) {
            $this->scenes()->switchTo(TitleScene::class, [], false);
            return;
        }

        $this->seconds += $dt;
        $dx = $this->actions()->axis('left', 'right');
        $dy = $this->actions()->axis('up', 'down');

        // Continuous northward flight; vertical input changes advance speed.
        $advance = max(0, self::FLIGHT_SPEED_PX_S - $dy * 55);
        $this->planeX += $dx * 85 * $dt;
        $this->planeY -= $advance * $dt;

        $max = (256 * (2 ** GeoProjection::ZOOM)) - 1.0;
        $this->planeX = max(1.0, min($max, $this->planeX));
        $this->planeY = max(1.0, min($max, $this->planeY));

        $this->tiles?->tick(
            $this->planeX - self::PLANE_X,
            $this->planeY - self::PLANE_Y,
            $this->width(), $this->height(), $this->seconds
        );
    }

    public function render(Renderer $r): void
    {
        $r->clear([31, 52, 46]);
        $cameraX = $this->planeX - self::PLANE_X;
        $cameraY = $this->planeY - self::PLANE_Y;
        $this->tiles?->draw($r, $cameraX, $cameraY, $this->width(), $this->height());

        // Simulated 15 m altitude, high afternoon sun. World-pinned, not part of the aircraft sprite.
        $shadowX = self::PLANE_X + self::ALTITUDE_M * 1.4;
        $shadowY = self::PLANE_Y + self::ALTITUDE_M * 1.7;
        if ($this->shadowTexture !== null) {
            $r->copy($this->shadowTexture, null, $shadowX - 27, $shadowY - 27, 54, 54);
        }
        $r->copy(
            $this->hurricaneTexture,
            [256, 256, 256, 256],
            self::PLANE_X - 28, self::PLANE_Y - 28, 56, 56
        );

        $r->fillRect(0, 0, 320, 54, [10, 24, 28, 210]);
        $this->font()->draw($r, 'SURVOL LIBRE - IGN 20 CM', 160, 7, 1, [255, 244, 200], 'center');
        $this->font()->draw($r, substr($this->location, 0, 41), 7, 25, 1, [242, 240, 220]);
        $meters = GeoProjection::metersPerPixel($this->latitude);
        $this->font()->draw($r, 'RES ' . round($meters * 100) . ' CM/PX', 7, 40, 1, [224, 224, 208]);

        $r->fillRect(0, 450, 320, 30, [10, 24, 28, 210]);
        $loading = $this->tiles?->readyCount() ?? 0;
        $message = $loading < 4 ? 'LOADING IGN IMAGERY...' : 'ARROWS: FLY   ESC: MENU';
        if (($this->tiles?->failures() ?? 0) > 3 && $loading === 0) {
            $message = 'IGN UNAVAILABLE - CHECK NETWORK / CORS';
        }
        $this->font()->draw($r, $message, 160, 456, 1, [255, 244, 200], 'center');
        $this->font()->draw($r, '(C) IGN - BD ORTHO', 313, 470, 1, [230, 230, 220], 'right');
    }
}
