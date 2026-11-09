<?php

namespace JumPHP\Physics;

use Evenement\EventEmitter;
use JumPHP\Entity\Entity;
use JumPHP\Tile\TileMap;

/**
 * Moves entities through a tile map, pixel by pixel, against the collision masks of the collision layer:
 * full blocks, one-way platforms, slopes (height masks) and any pixel shape.
 *
 *   $result = $this->collisions->move($player, $map, $dt);   // applies vx / vy, stops at walls
 *   if ($result->onGround && $this->actions()->isPressed('jump')) { $player->setSpeedY(-300); }
 *
 * - Walking into a step or slope up to $maxStepUp pixels high climbs it (only when on the ground).
 * - Walking down a slope keeps the entity on the ground (snaps up to $snapDown pixels).
 * - One-way tiles stop an entity only when it falls on them from above.
 * - Map edges: left / right are walls when $solidEdges; falling below the map emits 'fell'.
 * - Platforms (moving or not): setPlatformProvider(fn () => [['x','y','width','height','vx','vy','oneWay'], ...]).
 *
 * Events: 'touch' (Entity $e, string $layer, int $tx, int $ty, int $gid, array $properties) for every tile
 * with properties overlapped by the entity in the collision layer and the $touchLayers (items, hazards);
 * 'fell' (Entity $e) when it leaves the bottom of the map.
 */
class CollisionSystem extends EventEmitter
{
    public int $maxStepUp = 4;
    public int $snapDown = 6;
    public bool $solidEdges = true;
    /** @var string[] other layers whose tiles emit 'touch' (e.g. ['items']) */
    public array $touchLayers = [];

    /** @var callable|null fn(): array of platform rects */
    private $platformProvider = null;

    public function setPlatformProvider(?callable $provider): void
    {
        $this->platformProvider = $provider;
    }

    /** Same as move(): kept for code written against the older name. */
    public function checkCollisions(Entity $entity, TileMap $map, float $dt): CollisionResult
    {
        return $this->move($entity, $map, $dt);
    }

    public function move(Entity $entity, TileMap $map, float $dt): CollisionResult
    {
        $result = new CollisionResult();
        [$vx, $vy] = $entity->getVelocity();
        $x = $entity->getFloatX();
        $y = $entity->getFloatY();
        $w = $entity->getWidth();
        $h = $entity->getHeight();
        $wasOnGround = property_exists($entity, 'onGround') && $entity->onGround;
        $platforms = $this->platformProvider ? ($this->platformProvider)() : [];

        // carried by the platform stood on last frame
        if ($wasOnGround && $platforms !== []) {
            foreach ($platforms as $p) {
                if ($this->standsOn($x, $y, $w, $h, $p)) {
                    $x += ($p['vx'] ?? 0) * $dt;
                    $y += ($p['vy'] ?? 0) * $dt;
                    break;
                }
            }
        }

        // --- horizontal, one pixel at a time ---
        $remaining = $vx * $dt;
        while (abs($remaining) > 1e-6) {
            $step = $remaining > 0 ? min(1.0, $remaining) : max(-1.0, $remaining);
            $nx = $x + $step;
            $edge = $step > 0 ? (int)floor($nx + $w - 1) : (int)floor($nx);
            if (!$this->columnHits($map, $edge, $y, $h, $platforms)) {
                $x = $nx;
                $remaining -= $step;
                continue;
            }
            $climbed = false;
            if ($wasOnGround) {
                for ($s = 1; $s <= $this->maxStepUp; $s++) {
                    if (!$this->columnHits($map, $edge, $y - $s, $h, $platforms) && !$this->rowHits($map, $nx, $w, (int)floor($y - $s), $platforms)) {
                        $x = $nx;
                        $y -= $s;
                        $climbed = true;
                        break;
                    }
                }
            }
            if ($climbed) {
                $remaining -= $step;
                continue;
            }
            // flush against the wall (whole pixel: getX() rounds, the sprite must not sink in)
            if ($step > 0) {
                $result->hitRight = true;
                $x = floor($x);
            } else {
                $result->hitLeft = true;
                $x = ceil($x);
            }
            $vx = 0.0;
            break;
        }

        // --- vertical ---
        $remaining = $vy * $dt;
        while (abs($remaining) > 1e-6) {
            $step = $remaining > 0 ? min(1.0, $remaining) : max(-1.0, $remaining);
            $ny = $y + $step;
            if ($step > 0) {
                $feetBefore = (int)floor($y + $h - 1);
                $row = (int)floor($ny + $h - 1);
                if ($row > $feetBefore && $this->rowHits($map, $x, $w, $row, $platforms, $feetBefore)) {
                    $result->onGround = true;
                    $y = floor($y);
                    $vy = 0.0;
                    break;
                }
            } elseif ($this->rowHits($map, $x, $w, (int)floor($ny), $platforms)) {
                $result->hitCeiling = true;
                $y = ceil($y);
                $vy = 0.0;
                break;
            }
            $y = $ny;
            $remaining -= $step;
        }

        // --- stay on the ground: down slopes, or standing still ---
        if (!$result->onGround && $vy >= 0) {
            $feet = (int)floor($y + $h - 1);
            $reach = $wasOnGround ? $this->snapDown : 1;
            for ($s = 1; $s <= $reach; $s++) {
                if ($this->rowHits($map, $x, $w, $feet + $s, $platforms, $feet)) {
                    $y = floor($y) + $s - 1;
                    $result->onGround = true;
                    $vy = 0.0;
                    break;
                }
            }
        }

        if ($result->onGround) {
            $result->groundGid = $this->groundGid($map, $x, $w, (int)floor($y + $h));
            foreach ($platforms as $p) {
                if ($this->standsOn($x, $y, $w, $h, $p)) {
                    $result->platform = $p;
                    break;
                }
            }
        }

        $entity->setXY($x, $y);
        $entity->setVelocity($vx, $vy);
        if (property_exists($entity, 'onGround')) {
            $entity->onGround = $result->onGround;
            $entity->onCeiling = $result->hitCeiling;
            $entity->onWallLeft = $result->hitLeft;
            $entity->onWallRight = $result->hitRight;
            $entity->groundGid = $result->groundGid;
        }

        if ($y > $map->pixelHeight()) {
            $this->emit('fell', [$entity]);
        }
        $this->emitTouches($entity, $map, $x, $y, $w, $h);
        return $result;
    }

    /** Is the pixel (px, py) solid? $feetRow: rows <= it ignore one-way tiles (null: one-way never blocks). */
    public function isSolidAt(TileMap $map, int $px, int $py, ?int $feetRow = null): bool
    {
        if ($px < 0 || $px >= $map->pixelWidth()) {
            return $this->solidEdges && $py < $map->pixelHeight();
        }
        if ($py < 0 || $py >= $map->pixelHeight()) {
            return false;
        }
        $layer = $map->getCollisionLayer();
        if ($layer === null) {
            return false;
        }
        $tx = intdiv($px, $map->tileWidth);
        $ty = intdiv($py, $map->tileHeight);
        $gid = $map->layers[$layer][$ty * $map->width + $tx];
        if ($gid === 0) {
            return false;
        }
        $mask = $map->maskFor($gid);
        if ($mask === null) {
            return false;
        }
        if ($mask->oneWay) {
            // only the top surface of the tile, approached from above
            if ($feetRow === null || $py <= $feetRow) {
                return false;
            }
            $lx = $px - $tx * $map->tileWidth;
            $top = $mask->topAt($lx, $map->tileHeight);
            return $top !== null && $py - $ty * $map->tileHeight === $top;
        }
        return $mask->isSolid($px - $tx * $map->tileWidth, $py - $ty * $map->tileHeight);
    }

    /** Height of the ground under (x, from y down) within $maxDistance pixels, or null. */
    public function groundBelow(TileMap $map, float $x, float $y, int $maxDistance = 64): ?int
    {
        $px = (int)floor($x);
        for ($py = (int)floor($y), $end = $py + $maxDistance; $py <= $end; $py++) {
            if ($this->isSolidAt($map, $px, $py, $py - 1)) {
                return $py;
            }
        }
        return null;
    }

    private function columnHits(TileMap $map, int $px, float $y, int $h, array $platforms): bool
    {
        $top = (int)floor($y);
        $bottom = (int)floor($y + $h - 1);
        for ($py = $top; $py <= $bottom; $py++) {
            if ($this->isSolidAt($map, $px, $py)) {
                return true;
            }
        }
        foreach ($platforms as $p) {
            if (empty($p['oneWay']) && $px >= $p['x'] && $px < $p['x'] + $p['width'] && $bottom >= $p['y'] && $top < $p['y'] + $p['height']) {
                return true;
            }
        }
        return false;
    }

    private function rowHits(TileMap $map, float $x, int $w, int $py, array $platforms, ?int $feetRow = null): bool
    {
        $left = (int)floor($x);
        $right = (int)floor($x + $w - 1);
        for ($px = $left; $px <= $right; $px++) {
            if ($this->isSolidAt($map, $px, $py, $feetRow)) {
                return true;
            }
        }
        foreach ($platforms as $p) {
            if ($right < $p['x'] || $left >= $p['x'] + $p['width']) {
                continue;
            }
            $top = (int)floor($p['y']);
            if (!empty($p['oneWay'])) {
                if ($feetRow !== null && $py === $top && $feetRow < $top) {
                    return true;
                }
            } elseif ($py >= $top && $py < $p['y'] + $p['height']) {
                return true;
            }
        }
        return false;
    }

    private function standsOn(float $x, float $y, int $w, int $h, array $p): bool
    {
        return $x + $w > $p['x'] && $x < $p['x'] + $p['width'] && abs(($y + $h) - $p['y']) <= 1.5;
    }

    private function groundGid(TileMap $map, float $x, int $w, int $py): int
    {
        $layer = $map->getCollisionLayer();
        if ($layer === null) {
            return 0;
        }
        $tx = intdiv((int)floor($x + $w / 2), $map->tileWidth);
        return $map->getTile($layer, $tx, intdiv(max(0, $py), $map->tileHeight));
    }

    private function emitTouches(Entity $entity, TileMap $map, float $x, float $y, int $w, int $h): void
    {
        if ($this->listeners('touch') === []) {
            return;
        }
        $layers = array_unique(array_filter(array_merge([$map->getCollisionLayer()], $this->touchLayers)));
        $tx0 = intdiv(max(0, (int)floor($x)), $map->tileWidth);
        $ty0 = intdiv(max(0, (int)floor($y)), $map->tileHeight);
        $tx1 = intdiv(max(0, (int)floor($x + $w - 1)), $map->tileWidth);
        $ty1 = intdiv(max(0, (int)floor($y + $h - 1)), $map->tileHeight);
        foreach ($layers as $layer) {
            if (!isset($map->layers[$layer])) {
                continue;
            }
            for ($ty = $ty0; $ty <= $ty1; $ty++) {
                for ($tx = $tx0; $tx <= $tx1; $tx++) {
                    $gid = $map->getTile($layer, $tx, $ty);
                    if ($gid !== 0 && ($props = $map->tileProperties($gid)) !== []) {
                        $this->emit('touch', [$entity, $layer, $tx, $ty, $gid, $props]);
                    }
                }
            }
        }
        // also the tile just under the feet (spikes, conveyors, springs are touched by standing on them)
        $layer = $map->getCollisionLayer();
        if ($layer !== null && property_exists($entity, 'onGround') && $entity->onGround && $entity->groundGid !== 0) {
            $props = $map->tileProperties($entity->groundGid);
            if ($props !== []) {
                $tx = intdiv((int)floor($x + $w / 2), $map->tileWidth);
                $ty = intdiv((int)floor($y + $h), $map->tileHeight);
                $this->emit('touch', [$entity, $layer, $tx, $ty, $entity->groundGid, $props + ['_ground' => true]]);
            }
        }
    }
}
