<?php

namespace Tests;

use App\Geo\GeoProjection;
use App\Geo\MissionTerrain;
use App\Scene\AddressEntryScene;
use App\Scene\PlayScene;
use JumPHP\Testing\GameTestCase;

final class MissionTerrainTest extends GameTestCase
{
    public function testChosenAddressIsExactlyUnderThePlayerAtMissionStart(): void
    {
        $terrain = new MissionTerrain(48.17, 1.54, 'Beauce');
        [$x, $y] = $terrain->screenToWorld(160, 405, 0);
        [$expectedX, $expectedY] = GeoProjection::toPixels(48.17, 1.54);
        self::assertEqualsWithDelta($expectedX, $x, 0.001);
        self::assertEqualsWithDelta($expectedY, $y, 0.001);
        self::assertGreaterThan(0.19, $terrain->metersPerPixel());
        self::assertLessThan(0.21, $terrain->metersPerPixel());
    }

    public function testCombatAndBossStayOnTheSameContinuousMap(): void
    {
        $terrain = new MissionTerrain(48.17, 1.54, 'Beauce');
        [$startX, $startY] = $terrain->camera(0);
        [$bossX, $bossY] = $terrain->camera(2500);
        self::assertEqualsWithDelta($startX, $bossX, 0.001);
        self::assertEqualsWithDelta($startY - 2500, $bossY, 0.001);
    }

    public function testMissionAddressEntryIsIndependentFromFreeFlight(): void
    {
        $this->game->start(AddressEntryScene::class, ['purpose' => 'mission']);
        self::assertSame('mission', $this->game->peek('purpose'));
        $this->game->press('confirm');
        self::assertSame('A', $this->game->peek('address'));
    }

    public function testInvalidLocationCannotStartGeoreferencedMission(): void
    {
        $this->game->start(PlayScene::class, [
            'address' => 'Invalid coordinate',
            'latitude' => -25.0, 'longitude' => 2.0,
        ]);
        self::assertSame('error', $this->game->peek('geoState'));
        self::assertNull($this->game->peek('missionTerrain'));
        $this->game->press('search_address');
        self::assertSame('mission', $this->game->peek('purpose'));
    }
}
