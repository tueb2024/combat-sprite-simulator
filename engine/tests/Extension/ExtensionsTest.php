<?php

namespace JumPHP\Tests\Extension;

use JumPHP\Extension\Extensions;
use JumPHP\Kernel\Application;
use JumPHP\Kernel\GameConfig;
use PHPUnit\Framework\TestCase;

final class ExtensionsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/jumphp-ext-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/vendor/composer', 0777, true);
        mkdir($this->root . '/config');
        file_put_contents($this->root . '/vendor/composer/installed.json', json_encode(['packages' => [
            ['name' => 'acme/library', 'type' => 'library', 'install-path' => '../acme/library'],
            ['name' => 'acme/stars', 'type' => 'jumphp-extension', 'install-path' => '../acme/stars', 'extra' => ['jumphp' => [
                'name' => 'Stars',
                'services' => [StarField::class],
                'config' => ['stars' => ['default' => ['count' => 50, 'speed' => 2]], 'twinkle' => ['default' => true]],
            ]]],
        ]]));
        file_put_contents($this->root . '/config/project.yaml', "project:\n  title: T\n  stars: {count: 10}\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testOnlyThePackagesOfTypeJumphpExtensionAreFound(): void
    {
        $found = Extensions::discover($this->root);
        self::assertCount(1, $found);
        self::assertSame('Stars', $found[0]['name']);
        self::assertSame('acme/stars', $found[0]['package']);
    }

    public function testTheirConfigKeysGetDefaultsUnderTheGamesOwnValues(): void
    {
        $config = GameConfig::load($this->root);
        self::assertSame(['count' => 10, 'speed' => 2], $config->get('stars'));
        self::assertTrue($config->get('twinkle'));
        self::assertSame('T', $config->title());
    }

    public function testTheirServicesJoinTheContainer(): void
    {
        $c = Application::buildContainer($this->root);
        self::assertTrue($c->has(StarField::class));
        self::assertTrue($c->has(Extensions::class));
    }

    public function testARemoteGameFindsItsExtensionsInVendorExtensions(): void
    {
        $root = $this->root . '/remote';
        mkdir("$root/vendor/composer", 0777, true);
        mkdir("$root/vendor-extensions");
        // the shared engine's own vendor/: no extension there
        file_put_contents("$root/vendor/composer/installed.json", json_encode(['packages' => [['name' => 'symfony/yaml', 'type' => 'library']]]));
        file_put_contents("$root/vendor-extensions/installed.json", json_encode(['packages' => [
            ['name' => 'acme/rain', 'type' => 'jumphp-extension', 'install-path' => 'acme/rain', 'extra' => ['jumphp' => ['name' => 'Rain']]],
        ]]));
        $found = Extensions::discover($root);
        self::assertCount(1, $found);
        self::assertSame('Rain', $found[0]['name']);
        self::assertSame("$root/vendor-extensions/acme/rain", $found[0]['dir']);
    }

    public function testAGameWithoutExtensionsHasNone(): void
    {
        self::assertSame([], Extensions::discover(sys_get_temp_dir() . '/jumphp-no-such-game'));
        self::assertSame([], (new Extensions())->all());
    }
}

final class StarField
{
}
