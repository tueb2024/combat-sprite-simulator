<?php

namespace JumPHP\Physics;

/**
 * Axis-aligned boxes: ['x' => left, 'y' => top, 'width' => w, 'height' => h] (Entity::getBoundingBox()).
 */
final class Aabb
{
    public static function rect(float $x, float $y, float $width, float $height): array
    {
        return ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height];
    }

    public static function intersects(array $a, array $b): bool
    {
        return $a['x'] < $b['x'] + $b['width'] && $a['x'] + $a['width'] > $b['x']
            && $a['y'] < $b['y'] + $b['height'] && $a['y'] + $a['height'] > $b['y'];
    }

    public static function contains(array $rect, float $x, float $y): bool
    {
        return $x >= $rect['x'] && $x < $rect['x'] + $rect['width'] && $y >= $rect['y'] && $y < $rect['y'] + $rect['height'];
    }

    /**
     * Smallest move that pushes $a out of $b: [dx, dy] (one of them is 0), or null when they do not touch.
     * Typical use: resolve a ball against a paddle.
     */
    public static function overlap(array $a, array $b): ?array
    {
        if (!self::intersects($a, $b)) {
            return null;
        }
        $left = $b['x'] - ($a['x'] + $a['width']);       // < 0: push left
        $right = ($b['x'] + $b['width']) - $a['x'];      // > 0: push right
        $up = $b['y'] - ($a['y'] + $a['height']);
        $down = ($b['y'] + $b['height']) - $a['y'];
        $dx = abs($left) < abs($right) ? $left : $right;
        $dy = abs($up) < abs($down) ? $up : $down;
        return abs($dx) < abs($dy) ? [$dx, 0.0] : [0.0, $dy];
    }

    /**
     * Moving box $a (velocity $vx, $vy over one step) against static box $b: when does it hit?
     * @return array{time: float, nx: int, ny: int}|null time 0..1 of the step, normal of the face hit
     */
    public static function sweep(array $a, float $vx, float $vy, array $b): ?array
    {
        $xEntry = $vx > 0 ? $b['x'] - ($a['x'] + $a['width']) : ($b['x'] + $b['width']) - $a['x'];
        $xExit = $vx > 0 ? ($b['x'] + $b['width']) - $a['x'] : $b['x'] - ($a['x'] + $a['width']);
        $yEntry = $vy > 0 ? $b['y'] - ($a['y'] + $a['height']) : ($b['y'] + $b['height']) - $a['y'];
        $yExit = $vy > 0 ? ($b['y'] + $b['height']) - $a['y'] : $b['y'] - ($a['y'] + $a['height']);

        $txEntry = $vx == 0.0 ? -INF : $xEntry / $vx;
        $txExit = $vx == 0.0 ? INF : $xExit / $vx;
        $tyEntry = $vy == 0.0 ? -INF : $yEntry / $vy;
        $tyExit = $vy == 0.0 ? INF : $yExit / $vy;
        if ($vx == 0.0 && ($a['x'] + $a['width'] <= $b['x'] || $a['x'] >= $b['x'] + $b['width'])) {
            return null;
        }
        if ($vy == 0.0 && ($a['y'] + $a['height'] <= $b['y'] || $a['y'] >= $b['y'] + $b['height'])) {
            return null;
        }

        $entry = max($txEntry, $tyEntry);
        $exit = min($txExit, $tyExit);
        if ($entry > $exit || $entry < 0 || $entry > 1) {
            return null;
        }
        if ($txEntry > $tyEntry) {
            return ['time' => $entry, 'nx' => $vx > 0 ? -1 : 1, 'ny' => 0];
        }
        return ['time' => $entry, 'nx' => 0, 'ny' => $vy > 0 ? -1 : 1];
    }
}
