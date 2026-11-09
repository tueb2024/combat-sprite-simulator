<?php

namespace JumPHP\Scene;

use JumPHP\Entity\Entity;

/**
 * Which part of the world is on screen. Follows a target with a dead zone (the target moves freely in the
 * middle of the view, the camera only moves when it gets near an edge), stays inside the world bounds.
 *
 *   $camera = new Camera($r->getWidth(), $r->getHeight());
 *   $camera->setBounds(0, 0, $map->pixelWidth(), $map->pixelHeight());
 *   $camera->follow($player);
 *   // each frame: $camera->update($dt); then draw at ($x - $camera->getX(), $y - $camera->getY())
 */
class Camera
{
    public float $x = 0.0;
    public float $y = 0.0;
    private ?array $bounds = null;
    /** @var Entity|callable|null */
    private $target = null;
    private float $deadZoneW = 0.2;
    private float $deadZoneH = 0.3;
    private float $smoothing = 0.0;
    private bool $locked = false;
    private float $shakeTime = 0.0;
    private float $shakeStrength = 0.0;
    private int $shakeX = 0;
    private int $shakeY = 0;
    private float $offsetX = 0.0;
    private float $offsetY = 0.0;

    public function __construct(private int $viewWidth, private int $viewHeight)
    {
    }

    /**
     * @param Entity|callable(): array{0: float, 1: float} $target an entity (its centre) or a callable giving [x, y]
     * @param float $deadZoneW width of the dead zone, fraction of the view (0 = always centred)
     * @param float $smoothing 0 = snap, else catch-up speed (about 8 is smooth)
     */
    public function follow(Entity|callable $target, float $deadZoneW = 0.2, float $deadZoneH = 0.3, float $smoothing = 0.0): void
    {
        $this->target = $target;
        $this->deadZoneW = $deadZoneW;
        $this->deadZoneH = $deadZoneH;
        $this->smoothing = $smoothing;
    }

    public function setBounds(float $minX, float $minY, float $maxX, float $maxY): void
    {
        $this->bounds = [$minX, $minY, $maxX, $maxY];
    }

    public function setViewSize(int $width, int $height): void
    {
        $this->viewWidth = $width;
        $this->viewHeight = $height;
    }

    /** Look ahead / look up: an offset added to the target position. */
    public function setOffset(float $x, float $y): void
    {
        $this->offsetX = $x;
        $this->offsetY = $y;
    }

    /** A locked camera stops following (boss arena); bounds still apply. */
    public function lock(bool $locked = true): void
    {
        $this->locked = $locked;
    }

    public function centerOn(float $x, float $y): void
    {
        $this->x = $x - $this->viewWidth / 2;
        $this->y = $y - $this->viewHeight / 2;
        $this->clamp();
    }

    public function shake(float $strength = 3.0, float $seconds = 0.3): void
    {
        $this->shakeStrength = $strength;
        $this->shakeTime = $seconds;
    }

    public function update(float $dt): void
    {
        if ($this->target !== null && !$this->locked) {
            [$tx, $ty] = $this->targetPosition();
            $tx += $this->offsetX;
            $ty += $this->offsetY;
            $goalX = $this->x;
            $goalY = $this->y;
            $halfW = $this->viewWidth * $this->deadZoneW / 2;
            $halfH = $this->viewHeight * $this->deadZoneH / 2;
            $cx = $this->x + $this->viewWidth / 2;
            $cy = $this->y + $this->viewHeight / 2;
            if ($tx < $cx - $halfW) {
                $goalX = $tx + $halfW - $this->viewWidth / 2;
            } elseif ($tx > $cx + $halfW) {
                $goalX = $tx - $halfW - $this->viewWidth / 2;
            }
            if ($ty < $cy - $halfH) {
                $goalY = $ty + $halfH - $this->viewHeight / 2;
            } elseif ($ty > $cy + $halfH) {
                $goalY = $ty - $halfH - $this->viewHeight / 2;
            }
            if ($this->smoothing > 0) {
                $k = min(1.0, $this->smoothing * $dt);
                $this->x += ($goalX - $this->x) * $k;
                $this->y += ($goalY - $this->y) * $k;
            } else {
                $this->x = $goalX;
                $this->y = $goalY;
            }
        }
        $this->clamp();

        $this->shakeX = $this->shakeY = 0;
        if ($this->shakeTime > 0) {
            $this->shakeTime -= $dt;
            $s = (int)round($this->shakeStrength);
            $this->shakeX = random_int(-$s, $s);
            $this->shakeY = random_int(-$s, $s);
        }
    }

    private function targetPosition(): array
    {
        if ($this->target instanceof Entity) {
            $box = $this->target->getBoundingBox();
            return [$box['x'] + $box['width'] / 2, $box['y'] + $box['height'] / 2];
        }
        return ($this->target)();
    }

    private function clamp(): void
    {
        if ($this->bounds === null) {
            return;
        }
        [$minX, $minY, $maxX, $maxY] = $this->bounds;
        $this->x = max($minX, min($this->x, max($minX, $maxX - $this->viewWidth)));
        $this->y = max($minY, min($this->y, max($minY, $maxY - $this->viewHeight)));
    }

    /** Draw offset: whole pixels (no shimmering tiles), shake included. */
    public function getX(): int
    {
        return (int)floor($this->x) + $this->shakeX;
    }

    public function getY(): int
    {
        return (int)floor($this->y) + $this->shakeY;
    }

    /** @return array{0: float, 1: float} */
    public function worldToScreen(float $x, float $y): array
    {
        return [$x - $this->getX(), $y - $this->getY()];
    }

    /** @param array{x: float, y: float, width: float, height: float} $rect */
    public function isVisible(array $rect, float $margin = 0): bool
    {
        return $rect['x'] + $rect['width'] >= $this->x - $margin && $rect['x'] <= $this->x + $this->viewWidth + $margin
            && $rect['y'] + $rect['height'] >= $this->y - $margin && $rect['y'] <= $this->y + $this->viewHeight + $margin;
    }

    public function getViewWidth(): int { return $this->viewWidth; }
    public function getViewHeight(): int { return $this->viewHeight; }
}
