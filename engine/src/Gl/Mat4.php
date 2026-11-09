<?php

namespace JumPHP\Gl;

/**
 * 4x4 matrices as 16 floats in column-major order (what GLSL uniforms expect), for WebGl::draw() and for
 * software 3D (transform() + Renderer::drawLine: the fallback of a 3D game without OpenGL).
 *
 *   $mvp = Mat4::multiply(Mat4::perspective(60, 16 / 9, 0.1, 100),
 *          Mat4::multiply(Mat4::lookAt([0, 1, 4], [0, 0, 0]), Mat4::rotateY($t)));
 *   [$x, $y, $z, $w] = Mat4::transform($mvp, [1, 1, 1]);    // clip space: divide by $w
 */
final class Mat4
{
    /** @return float[] */
    public static function identity(): array
    {
        return [1.0, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 0.0, 1.0];
    }

    /** @return float[] $fovY in degrees */
    public static function perspective(float $fovY, float $aspect, float $near, float $far): array
    {
        $f = 1.0 / tan(deg2rad($fovY) / 2);
        $nf = 1.0 / ($near - $far);
        return [$f / $aspect, 0.0, 0.0, 0.0, 0.0, $f, 0.0, 0.0, 0.0, 0.0, ($far + $near) * $nf, -1.0, 0.0, 0.0, 2 * $far * $near * $nf, 0.0];
    }

    /** @return float[] */
    public static function ortho(float $left, float $right, float $bottom, float $top, float $near = -1.0, float $far = 1.0): array
    {
        $lr = 1 / ($left - $right);
        $bt = 1 / ($bottom - $top);
        $nf = 1 / ($near - $far);
        return [-2 * $lr, 0.0, 0.0, 0.0, 0.0, -2 * $bt, 0.0, 0.0, 0.0, 0.0, 2 * $nf, 0.0,
            ($left + $right) * $lr, ($top + $bottom) * $bt, ($far + $near) * $nf, 1.0];
    }

    /**
     * Camera at $eye looking at $target.
     * @param float[] $eye @param float[] $target @param float[] $up
     * @return float[]
     */
    public static function lookAt(array $eye, array $target, array $up = [0, 1, 0]): array
    {
        $z = self::normalize([$eye[0] - $target[0], $eye[1] - $target[1], $eye[2] - $target[2]]);
        $x = self::normalize(self::cross($up, $z));
        $y = self::cross($z, $x);
        return [
            $x[0], $y[0], $z[0], 0.0,
            $x[1], $y[1], $z[1], 0.0,
            $x[2], $y[2], $z[2], 0.0,
            -self::dot($x, $eye), -self::dot($y, $eye), -self::dot($z, $eye), 1.0,
        ];
    }

    /** @return float[] */
    public static function translate(float $x, float $y, float $z): array
    {
        $m = self::identity();
        [$m[12], $m[13], $m[14]] = [$x, $y, $z];
        return $m;
    }

    /** @return float[] */
    public static function scale(float $x, ?float $y = null, ?float $z = null): array
    {
        $m = self::identity();
        [$m[0], $m[5], $m[10]] = [$x, $y ?? $x, $z ?? $x];
        return $m;
    }

    /** @return float[] radians */
    public static function rotateX(float $a): array
    {
        [$c, $s] = [cos($a), sin($a)];
        return [1.0, 0.0, 0.0, 0.0, 0.0, $c, $s, 0.0, 0.0, -$s, $c, 0.0, 0.0, 0.0, 0.0, 1.0];
    }

    /** @return float[] radians */
    public static function rotateY(float $a): array
    {
        [$c, $s] = [cos($a), sin($a)];
        return [$c, 0.0, -$s, 0.0, 0.0, 1.0, 0.0, 0.0, $s, 0.0, $c, 0.0, 0.0, 0.0, 0.0, 1.0];
    }

    /** @return float[] radians */
    public static function rotateZ(float $a): array
    {
        [$c, $s] = [cos($a), sin($a)];
        return [$c, $s, 0.0, 0.0, -$s, $c, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 0.0, 1.0];
    }

    /**
     * $a × $b (apply $b first, then $a). More than two: multiply($p, $v, $m) = $p × $v × $m.
     * @param float[] ...$matrices
     * @return float[]
     */
    public static function multiply(array ...$matrices): array
    {
        $out = array_shift($matrices) ?? self::identity();
        foreach ($matrices as $b) {
            $a = $out;
            $out = [];
            for ($col = 0; $col < 4; $col++) {
                for ($row = 0; $row < 4; $row++) {
                    $out[$col * 4 + $row] = $a[$row] * $b[$col * 4] + $a[4 + $row] * $b[$col * 4 + 1]
                        + $a[8 + $row] * $b[$col * 4 + 2] + $a[12 + $row] * $b[$col * 4 + 3];
                }
            }
        }
        return $out;
    }

    /** @param float[] $m @param float[] $p [x, y, z] (w = 1) @return float[] [x, y, z, w] */
    public static function transform(array $m, array $p): array
    {
        [$x, $y, $z] = $p;
        return [
            $m[0] * $x + $m[4] * $y + $m[8] * $z + $m[12],
            $m[1] * $x + $m[5] * $y + $m[9] * $z + $m[13],
            $m[2] * $x + $m[6] * $y + $m[10] * $z + $m[14],
            $m[3] * $x + $m[7] * $y + $m[11] * $z + $m[15],
        ];
    }

    /**
     * A point to view pixels (software 3D): null when behind the camera.
     * @param float[] $mvp @param float[] $p @return float[]|null [x, y, depth 0..1]
     */
    public static function project(array $mvp, array $p, float $viewWidth, float $viewHeight): ?array
    {
        [$x, $y, $z, $w] = self::transform($mvp, $p);
        if ($w <= 1e-6) {
            return null;
        }
        return [($x / $w + 1) * 0.5 * $viewWidth, (1 - $y / $w) * 0.5 * $viewHeight, ($z / $w + 1) * 0.5];
    }

    /** @param float[] $v @return float[] */
    private static function normalize(array $v): array
    {
        $len = sqrt($v[0] ** 2 + $v[1] ** 2 + $v[2] ** 2) ?: 1.0;
        return [$v[0] / $len, $v[1] / $len, $v[2] / $len];
    }

    /** @param float[] $a @param float[] $b @return float[] */
    private static function cross(array $a, array $b): array
    {
        return [$a[1] * $b[2] - $a[2] * $b[1], $a[2] * $b[0] - $a[0] * $b[2], $a[0] * $b[1] - $a[1] * $b[0]];
    }

    /** @param float[] $a @param float[] $b */
    private static function dot(array $a, array $b): float
    {
        return $a[0] * $b[0] + $a[1] * $b[1] + $a[2] * $b[2];
    }
}
