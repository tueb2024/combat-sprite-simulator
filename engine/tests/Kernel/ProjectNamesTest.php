<?php

namespace JumPHP\Tests\Kernel;

use JumPHP\Kernel\GameConfig;
use JumPHP\Kernel\ProjectConfig;
use JumPHP\Testing\GameTestCase;
use JumPHP\Testing\ProjectTestCase;
use PHPUnit\Framework\TestCase;

/** The names that say "project" for the classes that were named after games: the same classes, nothing breaks. */
final class ProjectNamesTest extends TestCase
{
    public function testTheProjectNamesAreTheGameClasses(): void
    {
        self::assertTrue(class_exists(ProjectConfig::class));
        self::assertSame(GameConfig::class, (new \ReflectionClass(ProjectConfig::class))->getName());
        self::assertTrue(class_exists(ProjectTestCase::class));
        self::assertSame(GameTestCase::class, (new \ReflectionClass(ProjectTestCase::class))->getName());
    }
}
