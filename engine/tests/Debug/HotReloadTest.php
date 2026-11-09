<?php

namespace JumPHP\Tests\Debug;

use JumPHP\Debug\HotReload;
use JumPHP\Kernel\Application;
use JumPHP\Util\Lang;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class HotReloadTest extends TestCase
{
    private static string $game;
    private static Application $app;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('SDL')) {
            self::markTestSkipped('needs SDL');
        }
        self::$game = sys_get_temp_dir() . '/jumphp-hot-' . bin2hex(random_bytes(4));
        (new Filesystem())->mirror(__DIR__ . '/../fixtures/hot-game', self::$game);
        self::png(self::$game . '/assets/dot.png', 2);
        putenv('JUMPHP_TEST=1');
        self::$app = Application::boot(self::$game);
        \React\EventLoop\Loop::stop();
        $GLOBALS['app'] = self::$app;
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$app)) {
            self::$app->engine->sound->close();
            self::$app->engine->sdl->quit();
        }
        unset($GLOBALS['app']);
        putenv('JUMPHP_TEST');
        (new Filesystem())->remove(self::$game);
    }

    public function testWhatChangedDecidesHowTheGameReloads(): void
    {
        $engine = self::$app->engine;
        $engine->tick();
        $scene = $engine->scenes->current();
        self::assertInstanceOf(HotScene::class, $scene);
        self::assertSame(['level' => 2], $engine->scenes->paramsOf($scene));
        $scene->score = 7;

        // in place: the translations, a picture; the scene goes on
        file_put_contents(self::$game . '/config/lang.yaml', "en:\n  HELLO: \"HI\"\n");
        self::assertSame('assets', HotReload::reload($engine, ['config/lang.yaml']));
        self::assertSame('HI', Lang::t('HELLO'));
        $engine->sdl->loadTexture('dot', 'assets/dot.png');
        self::png(self::$game . '/assets/dot.png', 5);
        self::assertSame('assets', HotReload::reload($engine, ['assets/dot.png']));
        // what the game does not read: nothing happens
        self::assertSame('assets', HotReload::reload($engine, ['AGENTS.md', 'tests/PlayTest.php', 'site/index.html']));
        self::assertSame(5, $engine->sdl->getTexture('dot')['width']);
        self::assertSame(1, $scene->entered);

        // a data file: the scene is entered again, with its parameters and its state
        self::assertSame('scene', HotReload::reload($engine, ['assets/data/level.json']));
        $again = $engine->scenes->current();
        self::assertInstanceOf(HotScene::class, $again);
        // a new one here (the scene is no service of the container): entered once, its score given back
        self::assertNotSame($scene, $again);
        self::assertSame([1, ['level' => 2], 7], [$again->entered, $again->params, $again->score]);

        // code: a restart, the page saves the scene first; the next boot starts in it
        self::assertSame('restart', HotReload::reload($engine, ['src/Scene/PlayScene.php', 'assets/dot.png']));
        $file = self::$game . '/hot.json';
        HotReload::save($file);
        self::assertSame([HotScene::class, ['level' => 2], ['score' => 7]], HotReload::pending($file));
        self::assertNull(HotReload::pending($file));
    }

    private static function png(string $file, int $width): void
    {
        @mkdir(dirname($file), 0777, true);
        $image = imagecreatetruecolor($width, 1);
        imagepng($image, $file);
    }
}
