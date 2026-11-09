<?php

namespace Tests;

use App\Entity\Hitbox;
use App\Scene\PlayScene;
use JumPHP\Testing\GameTestCase;

final class CombatTest extends GameTestCase
{
    public function testMovementAndFire(): void
    {
        $this->game->start(PlayScene::class);
        $startX = $this->game->peek('x');
        $this->game->hold('right', 30);
        self::assertGreaterThan($startX, $this->game->peek('x'));
        $this->game->hold('fire', 10);
        self::assertNotEmpty($this->game->peek('shots'));
    }

    public function testBossCanBeDestroyedAndMissionRestarted(): void
    {
        $this->game->start(PlayScene::class, ['boss' => true, 'bossHp' => 4]);
        $this->game->hold('fire', 90);
        self::assertSame('victory', $this->game->peek('phase'));
        $this->game->press('confirm');
        self::assertSame('wave', $this->game->peek('phase'));
        self::assertSame(3, $this->game->peek('lives'));
    }

    public function testHitboxesMeetOnlyWhenTheyOverlap(): void
    {
        self::assertTrue(Hitbox::overlap(0, 0, 10, 10, 9, 9, 2, 2));
        self::assertFalse(Hitbox::overlap(0, 0, 10, 10, 10, 0, 2, 2));
    }
}
