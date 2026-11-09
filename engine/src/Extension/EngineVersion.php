<?php

namespace JumPHP\Extension;

/**
 * The JumPHP version a game runs on: the VERSION file at the root of the project (the engine is its engine/ folder).
 * Unknown (a build without that file: the browser's, a remote game) = null, and the extensions' version checks wait.
 */
final class EngineVersion
{
    public static function current(?string $file = null): ?string
    {
        $file ??= dirname(__DIR__, 3) . '/VERSION';
        $text = is_file($file) ? trim((string)@file_get_contents($file)) : '';
        return preg_match('/^\d+\.\d+(\.\d+)?/', $text) ? $text : null;
    }
}
