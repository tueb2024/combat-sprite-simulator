<?php

namespace Tests;

use App\Scene\AddressEntryScene;
use App\Scene\GeoFlightScene;
use JumPHP\Testing\GameTestCase;

final class AddressEntryTest extends GameTestCase
{
    public function testAddressCanBeTypedWithTheBuiltInControllerKeyboard(): void
    {
        $this->game->start(AddressEntryScene::class);
        self::assertSame('', $this->game->peek('address'));
        $this->game->press('confirm');
        self::assertSame('A', $this->game->peek('address'));
        $this->game->press('right');
        $this->game->press('confirm');
        self::assertSame('AB', $this->game->peek('address'));
    }

    public function testFlightHasAnInGameAddressShortcut(): void
    {
        $this->game->start(GeoFlightScene::class);
        $this->game->press('search_address');
        self::assertSame('', $this->game->peek('address'));
    }
}
