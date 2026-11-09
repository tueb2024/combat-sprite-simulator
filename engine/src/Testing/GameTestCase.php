<?php

namespace JumPHP\Testing;

use PHPUnit\Framework\TestCase;

/**
 * A PHPUnit test that plays the game (Game): $this->game, booted from the game's folder (bin/jumphp test runs
 * PHPUnit there). Each test starts the scene it needs; its last frame is kept and shown by the jumphp-live pane.
 * Needs SDL (bin/jumphp test, the JumPHP image); elsewhere (the playground's sandbox) these tests are skipped.
 *
 *   final class PaddleTest extends GameTestCase
 *   {
 *       public function testThePaddleGoesDown(): void
 *       {
 *           $this->game->start(PlayScene::class, ['mode' => 'local']);
 *           ...
 *       }
 *   }
 */
abstract class GameTestCase extends TestCase
{
    protected Game $game;

    protected function setUp(): void
    {
        if (!extension_loaded('SDL')) {
            self::markTestSkipped('A game test needs SDL: run it with bin/jumphp test.');
        }
        $this->game = Game::boot((string)getcwd());
        $this->game->begin(static::class . '::' . $this->name());
    }

    protected function tearDown(): void
    {
        if (isset($this->game)) {
            $this->game->end();
        }
    }
}
