<?php

namespace JumPHP\Tests\Net;

use JumPHP\Net\MessageCodec;
use JumPHP\Net\SnapshotBuffer;
use PHPUnit\Framework\TestCase;

final class NetTest extends TestCase
{
    public function testCodec(): void
    {
        [$type, $payload] = MessageCodec::decode(MessageCodec::json(7, ['a' => 1]));
        self::assertSame(7, $type);
        self::assertSame(['a' => 1], MessageCodec::unjson($payload));

        [$type, $payload] = MessageCodec::decode(MessageCodec::pack(2, 'gg', 1.5, -3.25));
        self::assertSame(2, $type);
        self::assertSame(['x' => 1.5, 'y' => -3.25], MessageCodec::unpack('gx/gy', $payload));
    }

    public function testSnapshotInterpolation(): void
    {
        $buffer = new SnapshotBuffer(0.1);
        self::assertNull($buffer->sample(1.0));
        $buffer->push(1.0, ['x' => 0.0, 'name' => 'a']);
        $buffer->push(1.2, ['x' => 100.0, 'name' => 'b']);
        $s = $buffer->sample(1.2);            // 0.1 s in the past: halfway
        self::assertEqualsWithDelta(50.0, $s['x'], 1e-9);
        self::assertSame('a', $s['name']);
        self::assertSame(100.0, $buffer->sample(5.0)['x']);
    }
}
