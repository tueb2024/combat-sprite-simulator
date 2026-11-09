<?php

namespace JumPHP\Net;

/**
 * Game messages = one type byte + a payload. JSON for rare messages (start, goal, chat), pack() for
 * frequent state (positions 20-30 times a second: a few bytes instead of a JSON text).
 *
 *   $bytes = MessageCodec::json(MSG_START, ['seed' => 42]);
 *   $bytes = MessageCodec::pack(MSG_STATE, 'gg', $x, $y);          // g = float little-endian
 *   [$type, $payload] = MessageCodec::decode($bytes);
 *   $data = MessageCodec::unjson($payload);   $values = MessageCodec::unpack('gx/gy', $payload);
 *
 * pack() formats that keep the same size on desktop and in the browser: C (u8), c (i8), v (u16), s (i16),
 * V (u32: avoid above 2^31 in the browser), g (float32). Never q/P/J: 64-bit integers do not exist there.
 */
final class MessageCodec
{
    public static function encode(int $type, string $payload = ''): string
    {
        return chr($type & 0xFF) . $payload;
    }

    /** @return array{0: int, 1: string} */
    public static function decode(string $data): array
    {
        return $data === '' ? [0, ''] : [ord($data[0]), substr($data, 1)];
    }

    public static function json(int $type, array $data): string
    {
        return self::encode($type, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function unjson(string $payload): array
    {
        $data = json_decode($payload, true);
        return is_array($data) ? $data : [];
    }

    public static function pack(int $type, string $format, mixed ...$values): string
    {
        return self::encode($type, pack($format, ...$values));
    }

    /** @param string $format unpack() format with names: 'gx/gy/Cflags' */
    public static function unpack(string $format, string $payload): array
    {
        $values = @unpack($format, $payload);
        return is_array($values) ? $values : [];
    }
}
