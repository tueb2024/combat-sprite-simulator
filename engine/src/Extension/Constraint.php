<?php

namespace JumPHP\Extension;

/**
 * Version constraints of extra.jumphp.engine (what JumPHP an extension works with), the Composer syntax that matters:
 * "^0.5", "~0.5.2", ">=0.5 <0.7", "0.5.*", "0.5.7", "*", and "||" between alternatives. Pure PHP (the browser has no
 * composer/semver). A pre-release suffix (0.6.0-beta) counts as the plain version.
 */
final class Constraint
{
    public static function satisfies(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return true;
        }
        foreach (preg_split('/\s*\|\|?\s*/', $constraint) ?: [] as $alternative) {
            if (self::all($version, $alternative)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<int> [major, minor, patch] */
    public static function parse(string $version): array
    {
        preg_match('/(\d+)(?:\.(\d+))?(?:\.(\d+))?/', ltrim($version, 'vV= '), $m);
        return [(int)($m[1] ?? 0), (int)($m[2] ?? 0), (int)($m[3] ?? 0)];
    }

    private static function all(string $version, string $alternative): bool
    {
        // "1.2 - 1.5", ">=1.2, <2" and ">=1.2 <2" are all "and"
        $alternative = (string)preg_replace('/\s+-\s+/', ' - ', trim($alternative));
        if (preg_match('/^(\S+) - (\S+)$/', $alternative, $m)) {
            return self::one($version, ">=$m[1]") && self::one($version, "<=$m[2]");
        }
        foreach (preg_split('/[\s,]+/', $alternative, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if (!self::one($version, $part)) {
                return false;
            }
        }
        return true;
    }

    private static function one(string $version, string $part): bool
    {
        $v = self::parse($version);
        if (!preg_match('/^(\^|~|>=|<=|!=|<>|>|<|=)?\s*v?(\d+|\*|x)(?:\.(\d+|\*|x))?(?:\.(\d+|\*|x))?/i', $part, $m)) {
            return false;
        }
        $op = $m[1];
        $isWild = fn (?string $s) => $s === null || $s === '' || $s === '*' || strtolower($s) === 'x';
        $given = [$m[2], $m[3] ?? null, $m[4] ?? null];
        $wild = $isWild($m[2]);
        if ($wild) {
            return true;
        }
        $base = self::parse($part);
        $cmp = static fn (array $a, array $b) => $a <=> $b;
        $specified = 0;
        foreach ($given as $g) {
            if (!$isWild($g)) {
                $specified++;
            } else {
                break;
            }
        }
        switch ($op) {
            case '':
            case '=':
                // "0.5.*", "0.5" (two parts): that series; "0.5.7": exactly
                return array_slice($v, 0, $specified) === array_slice($base, 0, $specified) && ($specified < 3 || $v === $base || $op === '=' && $v === $base);
            case '>=':
                return $cmp($v, $base) >= 0;
            case '>':
                return $cmp($v, $base) > 0;
            case '<=':
                return $cmp($v, $base) <= 0 || array_slice($v, 0, $specified) === array_slice($base, 0, $specified);
            case '<':
                return $cmp($v, $base) < 0;
            case '!=':
            case '<>':
                return $v !== $base;
            case '~':
                // ~1.2.3 = >=1.2.3 <1.3 ; ~1.2 = >=1.2 <2
                $upper = $specified >= 3 ? [$base[0], $base[1] + 1, 0] : [$base[0] + 1, 0, 0];
                return $cmp($v, $base) >= 0 && $cmp($v, $upper) < 0;
            case '^':
                // ^1.2.3 = >=1.2.3 <2 ; ^0.5.2 = >=0.5.2 <0.6 ; ^0.0.3 = >=0.0.3 <0.0.4
                $upper = $base[0] > 0 ? [$base[0] + 1, 0, 0] : ($base[1] > 0 || $specified < 3 ? [0, $base[1] + 1, 0] : [0, 0, $base[2] + 1]);
                if ($base[0] === 0 && $specified === 1) {
                    $upper = [1, 0, 0];
                }
                return $cmp($v, $base) >= 0 && $cmp($v, $upper) < 0;
        }
        return false;
    }
}
