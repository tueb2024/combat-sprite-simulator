<?php

namespace App\Entity;

final class Hitbox
{
    public static function overlap(float $ax, float $ay, float $aw, float $ah, float $bx, float $by, float $bw, float $bh): bool
    {
        return $ax < $bx + $bw && $ax + $aw > $bx && $ay < $by + $bh && $ay + $ah > $by;
    }
}
