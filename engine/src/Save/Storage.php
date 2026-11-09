<?php

namespace JumPHP\Save;

/**
 * Files of the player in var/ of the game: saves, high scores, options. JSON helpers included.
 * In the browser var/ lives in IndexedDB (the page saves it every 2 s and when the tab is closed):
 * same code, the data survives a reload.
 *
 *   $storage->writeJson('highscores.json', $scores);
 *   $scores = $storage->readJson('highscores.json', []);
 */
class Storage
{
    public function __construct(private string $dir)
    {
    }

    /**
     * var/ of the game. Headless runs (bin/jumphp test / shot) use a fresh temporary folder instead, so they never
     * read or change the player's saves and always start the same way. JUMPHP_VAR=<dir> forces a folder.
     */
    public static function fromConfig(\JumPHP\Kernel\GameConfig $config): self
    {
        $dir = \JumPHP\Kernel\Platform::setting('JUMPHP_VAR');
        if ($dir === null && \JumPHP\Kernel\Platform::isHeadless()) {
            $dir = sys_get_temp_dir() . '/jumphp-var-' . getmypid();
        }
        return $dir !== null ? new self(rtrim((string)$dir, '/')) : self::forRoot($config->root());
    }

    public static function forRoot(string $root): self
    {
        return new self(rtrim($root, '/') . '/var');
    }

    public function path(string $name): string
    {
        return $this->dir . '/' . ltrim($name, '/');
    }

    public function exists(string $name): bool
    {
        return is_file($this->path($name));
    }

    public function read(string $name): ?string
    {
        $file = $this->path($name);
        $data = is_file($file) ? @file_get_contents($file) : false;
        return $data === false ? null : $data;
    }

    public function write(string $name, string $data): bool
    {
        $file = $this->path($name);
        @mkdir(dirname($file), 0777, true);
        // write then rename: a crash never leaves half a file
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $data) === false) {
            return false;
        }
        return @rename($tmp, $file);
    }

    public function readJson(string $name, mixed $default = null): mixed
    {
        $data = $this->read($name);
        if ($data === null) {
            return $default;
        }
        $value = json_decode($data, true);
        return $value ?? $default;
    }

    public function writeJson(string $name, mixed $value): bool
    {
        return $this->write($name, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function delete(string $name): void
    {
        @unlink($this->path($name));
    }
}
