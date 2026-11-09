<?php

namespace JumPHP\Tile;

use JumPHP\Renderer\Renderer;
use JumPHP\Renderer\Sdl;
use JumPHP\Util\Color;

/**
 * Draws a TileMap: image layers (parallax backgrounds), then tile layers. Only the tiles in view are drawn.
 *
 *   $this->tiles->renderBackground($r, $map, $camera->getX(), $camera->getY(), $this->time);
 *   $this->entities->render($r, $camera);
 *   $this->tiles->renderForeground($r, $map, $camera->getX(), $camera->getY(), $this->time);
 *
 * Layers with the property foreground = true are drawn by renderForeground() (over the entities);
 * layers with visible = false (and the "collision" layer when it has no tileset image) are not drawn.
 * Tilesets without an image (ArrayLoader) are drawn as flat colour blocks: handy for prototypes.
 */
class TileMapRenderer
{
    /** Colour of tiles whose tileset has no image. */
    public Color $placeholder;

    public function __construct(private Sdl $sdl)
    {
        $this->placeholder = new Color(90, 110, 140);
    }

    public function renderBackground(Renderer $r, TileMap $map, float $camX, float $camY, float $time = 0.0): void
    {
        foreach ($map->imageLayers as $layer) {
            $this->renderImageLayer($r, $layer, $camX, $camY);
        }
        foreach ($map->layers as $name => $gids) {
            $props = $map->layerProperties[$name] ?? [];
            if (empty($props['foreground']) && $this->isVisible($map, $name)) {
                $this->renderLayer($r, $map, $name, $camX, $camY, $time);
            }
        }
    }

    public function renderForeground(Renderer $r, TileMap $map, float $camX, float $camY, float $time = 0.0): void
    {
        foreach ($map->layers as $name => $gids) {
            $props = $map->layerProperties[$name] ?? [];
            if (!empty($props['foreground']) && $this->isVisible($map, $name)) {
                $this->renderLayer($r, $map, $name, $camX, $camY, $time);
            }
        }
    }

    private function isVisible(TileMap $map, string $name): bool
    {
        return ($map->layerProperties[$name]['visible'] ?? true) !== false;
    }

    public function renderLayer(Renderer $r, TileMap $map, string $layer, float $camX, float $camY, float $time = 0.0): void
    {
        $props = $map->layerProperties[$layer] ?? [];
        $camX *= (float)($props['parallaxX'] ?? 1.0);
        $camY *= (float)($props['parallaxY'] ?? 1.0);
        $tw = $map->tileWidth;
        $th = $map->tileHeight;
        $x0 = max(0, (int)floor($camX / $tw));
        $y0 = max(0, (int)floor($camY / $th));
        $x1 = min($map->width - 1, (int)floor(($camX + $r->getWidth()) / $tw));
        $y1 = min($map->height - 1, (int)floor(($camY + $r->getHeight()) / $th));
        $gids = $map->layers[$layer];
        $flips = $map->flips[$layer] ?? [];
        $camX = (int)floor($camX);
        $camY = (int)floor($camY);

        for ($ty = $y0; $ty <= $y1; $ty++) {
            $row = $ty * $map->width;
            for ($tx = $x0; $tx <= $x1; $tx++) {
                $gid = $gids[$row + $tx];
                if ($gid === 0) {
                    continue;
                }
                $ts = $map->tilesetFor($gid);
                $dx = $tx * $tw - $camX;
                $dy = $ty * $th - $camY;
                if ($ts === null || $ts->image === '') {
                    $r->fillRect($dx, $dy, $tw, $th, $this->placeholder);
                    continue;
                }
                $local = $gid - $ts->firstGid;
                if ($ts->animations !== []) {
                    $local = $ts->animatedId($local, $time);
                }
                $flip = $flips[$row + $tx] ?? 0;
                // tiles bigger than the map grid are anchored at the bottom-left of their cell (Tiled)
                $r->copy($this->texture($ts), $ts->srcRect($local), $dx, $dy + $th - $ts->tileHeight, $ts->tileWidth, $ts->tileHeight,
                    ($flip & TileMap::FLIP_H) !== 0, ($flip & TileMap::FLIP_V) !== 0);
            }
        }
    }

    private function renderImageLayer(Renderer $r, array $layer, float $camX, float $camY): void
    {
        $name = 'image:' . $layer['image'];
        if (!$this->sdl->hasTexture($name)) {
            $this->sdl->registerTexture($name, $layer['image']);
        }
        $tex = $this->sdl->getTexture($name);
        $x = $layer['offsetX'] - $camX * $layer['parallaxX'];
        $y = $layer['offsetY'] - $camY * $layer['parallaxY'];
        $w = $tex['width'];
        $h = $tex['height'];
        if ($layer['repeatX'] && $w > 0) {
            $x = fmod($x, $w);
            if ($x > 0) {
                $x -= $w;
            }
        }
        if ($layer['repeatY'] && $h > 0) {
            $y = fmod($y, $h);
            if ($y > 0) {
                $y -= $h;
            }
        }
        $viewW = $r->getWidth();
        $viewH = $r->getHeight();
        for ($dy = $y; $dy < $viewH; $dy += $h) {
            for ($dx = $x; $dx < $viewW; $dx += $w) {
                $r->copy($tex['texture'], null, $dx, $dy, $w, $h);
                if (!$layer['repeatX']) {
                    break;
                }
            }
            if (!$layer['repeatY']) {
                break;
            }
        }
    }

    private function texture(TileSet $ts)
    {
        $name = $ts->textureName();
        if (!$this->sdl->hasTexture($name)) {
            $this->sdl->registerTexture($name, $ts->image);
        }
        return $this->sdl->getTexture($name)['texture'];
    }
}
