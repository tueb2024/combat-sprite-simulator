<?php

namespace JumPHP\Extension;

/**
 * Reads a file format of an extension (composer.json extra.jumphp.loaders: {"suffix": "Class"}):
 * Engine::extensions()->load('assets/fx/fire.particles.json') picks the loader by the file name's end.
 */
interface AssetLoader
{
    /** @param string $file a path under the game's root (already resolved) */
    public function load(string $file): mixed;
}
