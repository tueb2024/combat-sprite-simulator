<?php

namespace JumPHP\Tests\Scene;

use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;
use JumPHP\Scene\SceneManager;
use PHPUnit\Framework\TestCase;

final class LogScene extends AbstractScene
{
    public static array $log = [];

    public function __construct(public string $name = 'scene')
    {
    }

    public function enter(array $params = []): void { self::$log[] = "{$this->name}:enter"; }
    public function exit(): void { self::$log[] = "{$this->name}:exit"; }
    public function pause(): void { self::$log[] = "{$this->name}:pause"; }
    public function resume(mixed $result = null): void { self::$log[] = "{$this->name}:resume:" . json_encode($result); }
    public function update(float $dt): void { self::$log[] = "{$this->name}:update"; }
    public function render(Renderer $r): void { }
}

final class SceneManagerTest extends TestCase
{
    public function testStack(): void
    {
        LogScene::$log = [];
        $m = new SceneManager();
        $a = new LogScene('a');
        $b = new LogScene('b');
        $m->switchTo($a, [], false);
        $m->push($b);
        self::assertSame($b, $m->current());
        $m->update(0.016);
        $m->pop('done');
        $m->update(0.016);
        self::assertSame(['a:enter', 'a:pause', 'b:enter', 'b:update', 'b:exit', 'a:resume:"done"', 'a:update'], LogScene::$log);
    }

    public function testSwitchWithFadeWaitsForTheDarkHalf(): void
    {
        LogScene::$log = [];
        $m = new SceneManager();
        $m->setFadeDuration(0.1);
        $m->switchTo(new LogScene('a'), [], false);
        $m->switchTo(new LogScene('b'));
        self::assertTrue($m->isTransitioning());
        for ($i = 0; $i < 20; $i++) {
            $m->update(0.02);
        }
        self::assertFalse($m->isTransitioning());
        self::assertContains('b:enter', LogScene::$log);
        self::assertContains('a:exit', LogScene::$log);
        self::assertSame('b', $m->current()->name);
    }

    public function testResolverByClassName(): void
    {
        $m = new SceneManager();
        $m->setResolver(fn (string $class) => new LogScene('resolved'));
        $m->switchTo(LogScene::class, [], false);
        self::assertSame('resolved', $m->current()->name);
    }
}
