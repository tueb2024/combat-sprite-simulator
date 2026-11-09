<?php

namespace App\Entity;

/** Arcade behaviour and sprite geometry for the two 1940 enemy silhouettes. */
final class EnemyProfile
{
    public static function spawn(string $type, float $x): array
    {
        if ($type !== 'bf109' && $type !== 'ju87') {
            throw new \InvalidArgumentException('Unknown enemy type');
        }
        return [
            'type' => $type,
            'x' => $x,
            'baseX' => $x,
            'y' => -40.0,
            'age' => 0.0,
            'hp' => $type === 'bf109' ? 2 : 4,
            'speed' => $type === 'bf109' ? 190.0 : 85.0,
            'width' => $type === 'bf109' ? 46 : 72,
            'height' => $type === 'bf109' ? 48 : 68,
            'hitWidth' => $type === 'bf109' ? 24 : 44,
            'hitHeight' => $type === 'bf109' ? 24 : 34,
            'bank' => 1,
            'prop' => 0,
            'fireDelay' => $type === 'bf109' ? 1.6 : 2.4,
        ];
    }

    public static function step(array &$enemy, float $dt): void
    {
        $previousX = $enemy['x'];
        $enemy['age'] += $dt;
        $age = $enemy['age'];
        if ($enemy['type'] === 'bf109') {
            $enemy['y'] += $enemy['speed'] * $dt;
            $enemy['x'] = max(24, min(296, $enemy['baseX'] + sin($age * 7) * 25 + sin($age * 2.3) * 9));
        } else {
            $enemy['y'] += ($enemy['speed'] + ($age > 2.2 ? 25 : 0)) * $dt;
            $enemy['x'] = max(37, min(283, $enemy['baseX'] + sin($age * 1.4) * 8));
        }
        $move = $enemy['x'] - $previousX;
        $enemy['bank'] = $move < -0.35 ? 0 : ($move > 0.35 ? 2 : 1);
        $enemy['prop'] = ((int) ($age * 18)) % 3;
        $enemy['fireDelay'] -= $dt;
    }

    public static function hitbox(array $enemy): array
    {
        return [
            $enemy['x'] - $enemy['hitWidth'] / 2,
            $enemy['y'] - $enemy['hitHeight'] / 2,
            $enemy['hitWidth'],
            $enemy['hitHeight'],
        ];
    }

    public static function frame(array $enemy): array
    {
        return [$enemy['prop'] * 512, $enemy['bank'] * 512, 512, 512];
    }
}
