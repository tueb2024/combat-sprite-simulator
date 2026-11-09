<?php

namespace Tests;

use App\Entity\Hitbox;
use App\Entity\EnemyProfile;
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

    public function testPauseStopsTheMissionClock(): void
    {
        $this->game->start(PlayScene::class);
        $this->game->press('pause');
        $time = $this->game->peek('time');
        $this->game->wait(1);
        self::assertSame($time, $this->game->peek('time'));
        $this->game->press('pause');
        $this->game->wait(0.2);
        self::assertGreaterThan($time, $this->game->peek('time'));
    }

    public function testBothDocumentedEnemyTypesSpawnInTheWave(): void
    {
        $this->game->start(PlayScene::class);
        $this->game->wait(1.35);
        $types = array_column($this->game->peek('enemies'), 'type');
        self::assertContains('bf109', $types);
        self::assertContains('ju87', $types);
    }

    public function testEnemyProfilesHaveDistinctMotionHitboxesAndFrames(): void
    {
        $fighter = EnemyProfile::spawn('bf109', 160);
        $bomber = EnemyProfile::spawn('ju87', 160);
        EnemyProfile::step($fighter, 0.4);
        EnemyProfile::step($bomber, 0.4);
        self::assertGreaterThan($bomber['y'], $fighter['y']);
        self::assertSame(2, $fighter['hp']);
        self::assertSame(4, $bomber['hp']);
        self::assertGreaterThan(EnemyProfile::hitbox($fighter)[2], EnemyProfile::hitbox($bomber)[2]);
        self::assertSame([0, 512, 512, 512], EnemyProfile::frame(EnemyProfile::spawn('ju87', 160)));
        self::assertNotSame(EnemyProfile::frame($fighter), EnemyProfile::frame(EnemyProfile::spawn('bf109', 160)));
    }

    public function testFighterFiresAndBothEnemyHitboxesMatchTheirGameScale(): void
    {
        $fighter = EnemyProfile::spawn('bf109', 160);
        $bomber = EnemyProfile::spawn('ju87', 160);
        [$fx, $fy, $fw, $fh] = EnemyProfile::hitbox($fighter);
        [$sx, $sy, $sw, $sh] = EnemyProfile::hitbox($bomber);
        self::assertTrue(Hitbox::overlap($fx, $fy, $fw, $fh, 158, -42, 4, 4));
        self::assertTrue(Hitbox::overlap($sx, $sy, $sw, $sh, 158, -42, 4, 4));
        self::assertFalse(Hitbox::overlap($fx, $fy, $fw, $fh, 180, -42, 4, 4));
        self::assertTrue(Hitbox::overlap($sx, $sy, $sw, $sh, 180, -42, 4, 4));

        $this->game->start(PlayScene::class);
        $this->game->wait(1.8);
        self::assertContains(false, array_column($this->game->peek('shots'), 'friendly'));
    }
}
