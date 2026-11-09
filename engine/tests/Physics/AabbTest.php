<?php

namespace JumPHP\Tests\Physics;

use JumPHP\Physics\Aabb;
use PHPUnit\Framework\TestCase;

final class AabbTest extends TestCase
{
    public function testIntersects(): void
    {
        $a = Aabb::rect(0, 0, 10, 10);
        self::assertTrue(Aabb::intersects($a, Aabb::rect(5, 5, 10, 10)));
        self::assertFalse(Aabb::intersects($a, Aabb::rect(10, 0, 5, 5)), 'touching edges do not overlap');
    }

    public function testOverlapPushesOutOnTheShortestAxis(): void
    {
        self::assertSame([-2.0, 0.0], Aabb::overlap(Aabb::rect(0, 0, 10, 10), Aabb::rect(8, 1, 10, 8)));
        self::assertNull(Aabb::overlap(Aabb::rect(0, 0, 1, 1), Aabb::rect(5, 5, 1, 1)));
    }

    public function testSweep(): void
    {
        $hit = Aabb::sweep(Aabb::rect(0, 0, 2, 2), 10, 0, Aabb::rect(6, 0, 2, 2));
        self::assertNotNull($hit);
        self::assertEqualsWithDelta(0.4, $hit['time'], 1e-9);
        self::assertSame(-1, $hit['nx']);
        self::assertNull(Aabb::sweep(Aabb::rect(0, 0, 2, 2), 10, 0, Aabb::rect(6, 5, 2, 2)));
    }
}
