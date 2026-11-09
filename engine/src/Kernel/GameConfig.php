<?php

namespace JumPHP\Kernel;

use JumPHP\Extension\Extensions;
use Symfony\Component\Yaml\Yaml;

/**
 * config/project.yaml of the project (the `project:` key; older projects: config/game.yaml, `game:`), with defaults. Read-only once booted.
 */
final class GameConfig
{
    public const NET_AUTO = 'auto';
    public const NET_SERVER = 'server';
    public const NET_LOCAL = 'local';

    public const DEFAULTS = [
        'id' => 'game',
        'title' => 'JumPHP game',
        'version' => '0.1.0',
        'description' => '',
        'descriptions' => [],    // the description per language {fr: ..., en: ...}: the playground shows the visitor's
        'width' => 320,          // virtual resolution: everything is drawn at this size, then scaled
        'height' => 180,
        'scale' => 3,            // initial window size = width * scale (desktop)
        'view' => 'fixed',       // 'window': the view is the window's size, pixel for pixel (apps made of JumPHP\Gui forms)
        'fullscreen' => false,
        'vsync' => true,
        'max_fps' => 120,        // desktop frame cap (vsync usually paces first)
        'background' => [0, 0, 0],
        'start_scene' => null,   // class name of the first scene
        'start_params' => [],
        'lang' => 'en',
        'net_url' => 'ws://localhost:8090/ws',
        'tcp_ticket_url' => null,        // null: the page's own origin in the browser (/api/tcp/ticket, no CORS), else https://jumphp.net/api/tcp/ticket
        'net_mode' => 'auto',             // browser TCP / SSH / UDP / ICMP: auto (the user's runtime when ready, else the server) | server | local (the runtime or an error)
        'debug_key' => 'F3',
        'fullscreen_key' => 'F11',
        'double_click_fullscreen' => false, // browser: true = a double click on the game goes fullscreen (off by default: the FULLSCREEN button / F11 do it)
        'smoke' => [],           // {scene: ..., params: {...}}: what `jumphp test` runs headless
        'online' => false,       // shown by the future games portal (game.json)
        'runtime' => 'default',  // 'async': PHP 8.6 ZTS + TrueAsync on the desktop (real threads, JumPHP\Async)
        'pwa' => false,          // true / {name, short_name, display, orientation, theme_color, background_color}: installable, offline
        'pause_scene' => null,   // pushed when the game is hidden / loses the focus (JumPHP\Device\Lifecycle)
        'pause_on_blur' => true,
        'mute_when_hidden' => true,
    ];

    /** @var array<string, mixed> */
    private array $values;

    /** @param array<string, mixed> $values */
    public function __construct(array $values = [], private string $root = '.')
    {
        $this->values = array_replace(self::DEFAULTS, $values);
    }

    public static function load(string $root): self
    {
        $file = self::yamlFile($root);
        $data = is_file($file) ? (Yaml::parseFile($file) ?? []) : [];
        $values = $data['project'] ?? $data['game'] ?? [];
        // keys declared by the engine extensions (composer.json extra.jumphp.config): their defaults under the game's
        foreach (Extensions::configDefaults($root) as $key => $default) {
            $values[$key] = is_array($default) && is_array($values[$key] ?? null) ? array_replace_recursive($default, $values[$key])
                : ($values[$key] ?? $default);
        }
        return new self($values, $root);
    }

    /** config/project.yaml (under project:), else the older config/game.yaml (under game:): both are read. */
    public static function yamlFile(string $root): string
    {
        return is_file($root . '/config/project.yaml') ? $root . '/config/project.yaml' : $root . '/config/game.yaml';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    public function root(): string
    {
        return $this->root;
    }

    /** Absolute path of a file of the game: path('assets/sprites/ball.png'). */
    public function path(string $relative = ''): string
    {
        return rtrim($this->root, '/') . ($relative === '' ? '' : '/' . ltrim($relative, '/'));
    }

    public function width(): int { return (int)$this->values['width']; }
    public function height(): int { return (int)$this->values['height']; }
    public function title(): string { return (string)$this->values['title']; }
    public function id(): string { return (string)$this->values['id']; }

    /**
     * Where a TCP bridge ticket is asked (JumPHP\Net\Tcp): JUMPHP_TCP_URL (env / page) wins over project.yaml `tcp_ticket_url`; without
     * either, the browser asks its own origin (the playground answers /api/tcp/ticket on the games origin too: no cross-origin call, no CORS)
     * and the desktop / phone applications ask jumphp.net.
     */
    public function tcpTicketUrl(): string
    {
        $default = $this->values['tcp_ticket_url'] ?? (Platform::isWasm() ? '/api/tcp/ticket' : 'https://jumphp.net/api/tcp/ticket');
        return (string)Platform::setting('JUMPHP_TCP_URL', $default);
    }

    /**
     * The network mode of the browser's TCP / SSH / UDP / ICMP connections (JumPHP\Net\BridgeRoute): JUMPHP_NET_MODE (env / page) wins
     * over project.yaml `net_mode`; auto | server | local, anything else is auto.
     */
    public function netMode(): string
    {
        $mode = strtolower(trim((string)Platform::setting('JUMPHP_NET_MODE', $this->values['net_mode'])));
        return in_array($mode, [self::NET_SERVER, self::NET_LOCAL], true) ? $mode : self::NET_AUTO;
    }

    /** The relay server: JUMPHP_NET_URL (page ?net= / env) wins over game.yaml. */
    public function netUrl(): string
    {
        return (string)Platform::setting('JUMPHP_NET_URL', $this->values['net_url']);
    }

    public function lang(): string
    {
        return (string)Platform::setting('JUMPHP_LANG', $this->values['lang']);
    }

    /** Reads another YAML file of config/ (input.yaml, lang.yaml...). */
    public function file(string $name): array
    {
        $file = $this->path('config/' . $name);
        return is_file($file) ? (Yaml::parseFile($file) ?? []) : [];
    }
}
