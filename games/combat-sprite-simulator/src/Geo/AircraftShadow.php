<?php

namespace App\Geo;

use JumPHP\Renderer\Sdl;

/** Extract the player's actual alpha silhouette and tint it as a ground shadow. */
final class AircraftShadow
{
    /**
     * Central atlas frame: middle bank (row 1), middle propeller state (col 1).
     * Works with both the original and the Blender-refined Hurricane atlas.
     */
    public static function create(Sdl $sdl, string $atlasPath, int $frameSize = 256, int $size = 64)
    {
        $atlas = @imagecreatefrompng($atlasPath);
        if (!$atlas) {
            return null;
        }
        $sample = imagecreatetruecolor($size, $size);
        imagealphablending($sample, false);
        imagesavealpha($sample, true);
        imagecopyresampled($sample, $atlas, 0, 0, $frameSize, $frameSize,
            $size, $size, $frameSize, $frameSize);
        imagedestroy($atlas);

        $shadow = imagecreatetruecolor($size, $size);
        imagealphablending($shadow, false);
        imagesavealpha($shadow, true);
        imagefill($shadow, 0, 0, imagecolorallocatealpha($shadow, 0, 0, 0, 127));

        for ($y = 0; $y < $size; ++$y) {
            for ($x = 0; $x < $size; ++$x) {
                $alpha = (imagecolorat($sample, $x, $y) >> 24) & 0x7F;
                if ($alpha >= 125) {
                    continue;
                }
                // A visible but translucent dark silhouette; preserve source antialiasing.
                $tint = min(127, 68 + (int) round($alpha * 0.46));
                imagesetpixel($shadow, $x, $y, imagecolorallocatealpha($shadow, 9, 22, 19, $tint));
            }
        }
        imagedestroy($sample);
        $texture = $sdl->createTextureFromGd('css-flight-shadow', $shadow)[0]['texture'];
        imagedestroy($shadow);
        return $texture;
    }
}
