<?php

namespace JumPHP\Extension;

use JumPHP\Kernel\Engine;
use JumPHP\Kernel\GameConfig;
use Psr\Container\ContainerInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

/**
 * The engine extensions of a game: the Composer packages of type "jumphp-extension" it requires, found in
 * vendor/composer/installed.json (no wiring by hand). Each one says what it brings in composer.json extra.jumphp:
 *
 *   "extra": {"jumphp": {
 *       "name": "Particles",
 *       "services": ["JumPHP\\Particles\\ParticleSystem"],      autowired, public (a scene asks for them in its constructor)
 *       "services_file": "config/services.yaml",                optional: a Symfony services file of the package
 *       "boot": "JumPHP\\Particles\\ParticlesExtension",        optional: an Extension, boot() before the first scene
 *       "loaders": {".particles.json": "JumPHP\\Particles\\EmitterLoader"},   optional: AssetLoader per file name end
 *       "config": {"particles": {"default": {"max": 4000}, "doc": "..."}}     optional: project.yaml keys + defaults
 *       "engine": "^0.5"                                       optional: the JumPHP versions it works with (Constraint), checked at boot
 *   }}
 *
 * Pure PHP: the same in the browser (the build packs vendor/, the container is compiled with them).
 */
final class Extensions
{
    /** @var array<string, list<array{name: string, package: string, dir: string, jumphp: array<string, mixed>}>> */
    private static array $found = [];

    /** @param list<array{name: string, package: string, dir: string, jumphp: array<string, mixed>}> $list */
    public function __construct(private array $list = [], private ?ContainerInterface $container = null)
    {
    }

    /** The game's extensions, found at run time from its root (the container compiled for the browser keeps no path). */
    public static function fromConfig(GameConfig $config, ?ContainerInterface $container = null): self
    {
        return new self(self::discover($config->path()), $container);
    }

    /** @return list<array{name: string, package: string, dir: string, jumphp: array<string, mixed>}> */
    public static function discover(string $root): array
    {
        $root = rtrim($root, '/');
        if (isset(self::$found[$root])) {
            return self::$found[$root];
        }
        // the game's vendor/ (desktop, browser build), and vendor-extensions/ (a remote game: the playground or
        // build:remote hand its extensions over beside the shared engine, whose vendor/ is not the game's)
        $list = [];
        foreach (['vendor/composer' => 'vendor', 'vendor-extensions' => 'vendor-extensions'] as $at => $vendor) {
            $file = "$root/$at/installed.json";
            $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
            foreach (is_array($data) ? ($data['packages'] ?? $data) : [] as $p) {
                if (!is_array($p) || ($p['type'] ?? '') !== 'jumphp-extension' || !is_string($p['name'] ?? null) || isset($list[$p['name']])) {
                    continue;
                }
                $jumphp = is_array($p['extra']['jumphp'] ?? null) ? $p['extra']['jumphp'] : [];
                $dir = isset($p['install-path']) ? "$root/$at/" . $p['install-path'] : "$root/$vendor/" . $p['name'];
                $list[$p['name']] = ['name' => (string)($jumphp['name'] ?? $p['name']), 'package' => $p['name'], 'dir' => rtrim($dir, '/'), 'jumphp' => $jumphp];
            }
        }
        $list = array_values($list);
        usort($list, fn ($a, $b) => strcmp($a['package'], $b['package']));
        return self::$found[$root] = $list;
    }

    /** The services of every extension, into the game's container (before the game's own services.yaml). */
    public static function register(ContainerBuilder $c, string $root): void
    {
        $list = self::discover($root);
        foreach ($list as $e) {
            $j = $e['jumphp'];
            foreach ((array)($j['services'] ?? []) as $class) {
                $c->autowire((string)$class)->setPublic(true);
            }
            foreach (array_filter([$j['boot'] ?? null, ...array_values((array)($j['loaders'] ?? []))]) as $class) {
                if (!$c->has((string)$class)) {
                    $c->autowire((string)$class)->setPublic(true);
                }
            }
            if (isset($j['services_file']) && is_file($e['dir'] . '/' . $j['services_file'])) {
                $path = $e['dir'] . '/' . $j['services_file'];
                (new YamlFileLoader($c, new FileLocator(dirname($path))))->load(basename($path));
            }
        }
        $c->register(self::class)->setFactory([self::class, 'fromConfig'])
            ->setArguments([new Reference(GameConfig::class), new Reference('service_container')])->setPublic(true);
    }

    /**
     * The defaults of the project.yaml keys extensions declare (extra.jumphp.config.<key>.default).
     *
     * @return array<string, mixed>
     */
    public static function configDefaults(string $root): array
    {
        $defaults = [];
        foreach (self::discover($root) as $e) {
            foreach ((array)($e['jumphp']['config'] ?? []) as $key => $schema) {
                if (is_array($schema) && array_key_exists('default', $schema)) {
                    $defaults[$key] = $schema['default'];
                }
            }
        }
        return $defaults;
    }

    /** @return list<array{name: string, package: string, dir: string, jumphp: array<string, mixed>}> */
    public function all(): array
    {
        return $this->list;
    }

    public function has(string $package): bool
    {
        foreach ($this->list as $e) {
            if ($e['package'] === $package || $e['name'] === $package) {
                return true;
            }
        }
        return false;
    }

    /**
     * The extensions whose extra.jumphp.engine does not accept the JumPHP version ($version, else VERSION): what they need
     * and what runs. Nothing when the version is unknown or an extension says nothing.
     *
     * @return list<array{package: string, requires: string, engine: string}>
     */
    public function incompatible(?string $version = null): array
    {
        $version ??= EngineVersion::current();
        $bad = [];
        foreach ($version === null ? [] : $this->list as $e) {
            $needs = $e['jumphp']['engine'] ?? null;
            if (is_string($needs) && $needs !== '' && !Constraint::satisfies($version, $needs)) {
                $bad[] = ['package' => $e['package'], 'requires' => $needs, 'engine' => $version];
            }
        }
        return $bad;
    }

    /** Runs each extension's boot class when the engine is ready (its 'boot' event, before the first scene). */
    public function attach(Engine $engine): void
    {
        $engine->on('boot', function () use ($engine): void {
            foreach ($this->incompatible() as $b) {
                throw new \RuntimeException("{$b['package']} works with JumPHP {$b['requires']}, this game runs JumPHP {$b['engine']}: update JumPHP (bin/jumphp update) or the extension (composer update {$b['package']})");
            }
            foreach ($this->list as $e) {
                $class = $e['jumphp']['boot'] ?? null;
                if (is_string($class) && $class !== '') {
                    $boot = $this->make($class);
                    if (!$boot instanceof Extension) {
                        throw new \LogicException("$class (extra.jumphp.boot of {$e['package']}) must implement " . Extension::class);
                    }
                    $boot->boot($engine);
                }
            }
        });
    }

    /** Reads a file with the loader an extension registered for its name's end (".particles.json"...). */
    public function load(string $file): mixed
    {
        $path = Engine::get()->config->path($file);
        foreach ($this->list as $e) {
            foreach ((array)($e['jumphp']['loaders'] ?? []) as $suffix => $class) {
                if (str_ends_with($file, (string)$suffix)) {
                    $loader = $this->make((string)$class);
                    if (!$loader instanceof AssetLoader) {
                        throw new \LogicException("$class (a loader of {$e['package']}) must implement " . AssetLoader::class);
                    }
                    return $loader->load($path);
                }
            }
        }
        throw new \RuntimeException("No extension reads '$file' (extra.jumphp.loaders)");
    }

    private function make(string $class): object
    {
        return $this->container !== null && $this->container->has($class) ? $this->container->get($class) : new $class();
    }
}
