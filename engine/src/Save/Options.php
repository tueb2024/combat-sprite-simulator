<?php

namespace JumPHP\Save;

/**
 * Player settings, saved in var/options.json as soon as they change.
 * The engine uses: sfx_volume, music_volume (0..1), fullscreen, lang. Games add their own keys.
 *
 *   $options->get('difficulty', 'normal');  $options->set('difficulty', 'hard');
 */
class Options
{
    private ?array $values = null;

    public function __construct(private Storage $storage, private string $file = 'options.json')
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->values ??= (array)$this->storage->readJson($this->file, []);
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values ??= (array)$this->storage->readJson($this->file, []);
        if (($this->values[$key] ?? null) === $value) {
            return;
        }
        $this->values[$key] = $value;
        $this->storage->writeJson($this->file, $this->values);
    }

    public function all(): array
    {
        return $this->values ??= (array)$this->storage->readJson($this->file, []);
    }
}
