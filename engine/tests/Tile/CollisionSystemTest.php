<?php

namespace JumPHP\Tests\Tile;

use JumPHP\Entity\Entity;
use JumPHP\Physics\CollisionSystem;
use JumPHP\Physics\TileCollision;
use JumPHP\Tile\ArrayLoader;
use JumPHP\Tile\CollisionMask;
use JumPHP\Tile\TileMap;
use PHPUnit\Framework\TestCase;

final class Body extends Entity
{
    use TileCollision;

    public function __construct(float $x, float $y)
    {
        $this->x = $x;
        $this->y = $y;
        $this->width = 8;
        $this->height = 8;
    }
}

final class CollisionSystemTest extends TestCase
{
    private function map(array $rows): TileMap
    {
        $map = ArrayLoader::fromRows($rows, ['#' => 1, '=' => 2, '/' => 3], tileWidth: 16);
        $map->tilesets[0]->masks[1] = CollisionMask::oneWay();
        $map->tilesets[0]->masks[2] = CollisionMask::slope(16, 16, 1, 16);
        return $map;
    }

    /** Steps the body with gravity for $seconds. */
    private function simulate(CollisionSystem $cs, Body $b, TileMap $map, float $seconds, float $vx = 0.0): void
    {
        for ($t = 0; $t < $seconds; $t += 1 / 60) {
            [, $vy] = $b->getVelocity();
            $b->setVelocity($vx, $vy + 900 / 60);
            $cs->move($b, $map, 1 / 60);
        }
    }

    public function testFallsAndLandsOnTheGround(): void
    {
        $map = $this->map(['....', '....', '####']);
        $b = new Body(20, 0);
        $this->simulate(new CollisionSystem(), $b, $map, 1.0);
        self::assertTrue($b->onGround);
        self::assertSame(32 - 8, $b->getY(), 'feet on the top of row 2');
    }

    public function testWallStopsHorizontalMotion(): void
    {
        $map = $this->map(['......#', '......#', '#######']);
        $b = new Body(10, 24);
        $this->simulate(new CollisionSystem(), $b, $map, 2.0, 100);
        self::assertSame(96 - 8, $b->getX());
        self::assertTrue($b->onWallRight);
    }

    public function testOneWayPlatformFromBelowAndAbove(): void
    {
        $map = $this->map(['....', '.==.', '....', '####']);
        $cs = new CollisionSystem();
        $below = new Body(20, 40);
        $below->setVelocity(0, -400);           // jumps up through the platform
        $cs->move($below, $map, 0.1);
        self::assertLessThan(16, $below->getY(), 'went through from below');

        $above = new Body(20, 0);
        $this->simulate($cs, $above, $map, 1.0);
        self::assertTrue($above->onGround);
        self::assertSame(16 - 8, $above->getY(), 'landed on the platform');
    }

    public function testWalksUpASlope(): void
    {
        $map = $this->map(['.....', '..../', '#####']);
        $b = new Body(0, 24);
        $this->simulate(new CollisionSystem(), $b, $map, 1.5, 60);
        self::assertGreaterThan(64, $b->getX(), 'went up the slope');
        self::assertLessThan(24, $b->getY());
    }

    public function testFellEventBelowTheMap(): void
    {
        $map = $this->map(['....', '....']);
        $cs = new CollisionSystem();
        $fell = false;
        $cs->on('fell', function () use (&$fell): void { $fell = true; });
        $this->simulate($cs, new Body(20, 0), $map, 1.0);
        self::assertTrue($fell);
    }
}
