<?php

namespace App\Geo;

use JumPHP\Http\Http;
use JumPHP\Http\HttpResponse;
use JumPHP\Renderer\Renderer;
use JumPHP\Renderer\Sdl;

/**
 * Streaming IGN orthophoto tile cache for SDL and php-wasm.
 *
 * Network traffic only uses JumPHP\Http (async bridge/fetch on wasm).
 * IMG_Load expects a file, so validated JPEG bytes are written to a /tmp
 * file and removed immediately after SDL loads the texture.
 */
final class OrthoTileManager
{
    private const MAX_REQUESTS = 4;
    private const MAX_TEXTURES = 30;
    private const RETRY_SECONDS = 30.0;

    /** @var array<string,array{state:string,name:string,last:int,retryAt:float}> */
    private array $tiles = [];
    private int $inflight = 0;
    private int $frame = 0;
    private int $generation = 0;
    private bool $active = true;
    private int $failures = 0;

    public function __construct(private Http $http, private Sdl $sdl, private int $zoom = GeoProjection::ZOOM)
    {
    }

    public function stop(): void
    {
        ++$this->generation;
        $this->active = false;
        foreach ($this->tiles as $tile) {
            if ($tile['state'] === 'ready') {
                $this->sdl->releaseTexture($tile['name']);
            }
        }
        $this->tiles = [];
        $this->inflight = 0;
    }

    public function failures(): int
    {
        return $this->failures;
    }

    public function readyCount(): int
    {
        $count = 0;
        foreach ($this->tiles as $tile) {
            if ($tile['state'] === 'ready') {
                ++$count;
            }
        }
        return $count;
    }

    /** Request viewport tiles and one border without blocking update(). */
    public function tick(float $cameraX, float $cameraY, int $width, int $height, float $seconds): void
    {
        if (!$this->active) {
            return;
        }
        ++$this->frame;
        $needed = [];
        $minX = (int) floor($cameraX / 256) - 1;
        $minY = (int) floor($cameraY / 256) - 1;
        $maxX = (int) floor(($cameraX + $width) / 256) + 1;
        $maxY = (int) floor(($cameraY + $height) / 256) + 1;
        $limit = 2 ** $this->zoom;

        for ($row = $minY; $row <= $maxY; ++$row) {
            for ($col = $minX; $col <= $maxX; ++$col) {
                if ($col < 0 || $row < 0 || $col >= $limit || $row >= $limit) {
                    continue;
                }
                $key = $this->zoom . '-' . $col . '-' . $row;
                $needed[$key] = [
                    'col' => $col,
                    'row' => $row,
                    'distance' => abs($col * 256 + 128 - ($cameraX + $width / 2))
                        + abs($row * 256 + 128 - ($cameraY + $height / 2)),
                ];
                if (isset($this->tiles[$key])) {
                    $this->tiles[$key]['last'] = $this->frame;
                }
            }
        }
        uasort($needed, static fn (array $a, array $b): int => $a['distance'] <=> $b['distance']);

        foreach ($needed as $key => $position) {
            if ($this->inflight >= self::MAX_REQUESTS) {
                break;
            }
            if (isset($this->tiles[$key])) {
                $previous = $this->tiles[$key];
                if ($previous['state'] !== 'failed' || $previous['retryAt'] > $seconds) {
                    continue;
                }
            }
            $this->download($key, $position['col'], $position['row'], $seconds);
        }
        $this->evict($needed);
    }

    private function download(string $key, int $col, int $row, float $seconds): void
    {
        $name = 'css-ortho-' . $key;
        $this->tiles[$key] = ['state' => 'loading', 'name' => $name, 'last' => $this->frame, 'retryAt' => 0.0];
        ++$this->inflight;
        $generation = $this->generation;
        $url = GeoProjection::tileUrl($col, $row, $this->zoom);

        $this->http->get($url)->then(
            function (HttpResponse $response) use ($key, $name, $generation, $seconds): void {
                if (!$this->active || $generation !== $this->generation) {
                    return;
                }
                --$this->inflight;
                if (!$response->ok() || strlen($response->body) > 1024 * 1024) {
                    $this->failed($key, $seconds);
                    return;
                }
                $size = @getimagesizefromstring($response->body);
                if (!is_array($size) || $size[0] !== 256 || $size[1] !== 256
                    || !in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
                    $this->failed($key, $seconds);
                    return;
                }
                $path = sys_get_temp_dir() . '/css-ign-' . md5($key) . '.jpg';
                try {
                    if (@file_put_contents($path, $response->body) === false) {
                        throw new \RuntimeException('Could not save temporary tile.');
                    }
                    $this->sdl->loadTexture($name, $path);
                    $this->tiles[$key]['state'] = 'ready';
                    $this->tiles[$key]['last'] = $this->frame;
                } catch (\Throwable $error) {
                    $this->failed($key, $seconds);
                } finally {
                    @unlink($path);
                }
            },
            function (\Throwable $error) use ($key, $generation, $seconds): void {
                if (!$this->active || $generation !== $this->generation) {
                    return;
                }
                --$this->inflight;
                $this->failed($key, $seconds);
            }
        );
    }

    private function failed(string $key, float $seconds): void
    {
        ++$this->failures;
        $this->tiles[$key]['state'] = 'failed';
        $this->tiles[$key]['retryAt'] = $seconds + self::RETRY_SECONDS;
    }

    /** Render only ready images. Missing tiles retain the scene background. */
    public function draw(Renderer $renderer, float $cameraX, float $cameraY, int $width, int $height): void
    {
        $minX = (int) floor($cameraX / 256);
        $minY = (int) floor($cameraY / 256);
        $maxX = (int) floor(($cameraX + $width) / 256);
        $maxY = (int) floor(($cameraY + $height) / 256);

        for ($row = $minY; $row <= $maxY; ++$row) {
            for ($col = $minX; $col <= $maxX; ++$col) {
                $key = $this->zoom . '-' . $col . '-' . $row;
                if (($this->tiles[$key]['state'] ?? null) !== 'ready') {
                    continue;
                }
                $texture = $this->sdl->getTexture($this->tiles[$key]['name'])['texture'];
                $renderer->copy($texture, null, $col * 256 - $cameraX, $row * 256 - $cameraY, 256, 256);
            }
        }
    }

    /** Release textures behind the flight path; keep a small image cache. */
    private function evict(array $needed): void
    {
        $ready = [];
        foreach ($this->tiles as $key => $tile) {
            if ($tile['state'] === 'ready') {
                $ready[$key] = $tile['last'];
            }
        }
        if (count($ready) <= self::MAX_TEXTURES) {
            return;
        }
        asort($ready, SORT_NUMERIC);
        foreach ($ready as $key => $last) {
            if (count($ready) <= self::MAX_TEXTURES) {
                break;
            }
            if (isset($needed[$key])) {
                continue;
            }
            $this->sdl->releaseTexture($this->tiles[$key]['name']);
            unset($this->tiles[$key], $ready[$key]);
        }
    }
}
