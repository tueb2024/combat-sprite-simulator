<?php

namespace App\Scene;

use JumPHP\Renderer\Renderer;
use JumPHP\Ui\MenuScene;

final class TitleScene extends MenuScene
{
    protected string $title = 'COMBAT SPRITE';
    private $flightListener = null;

    public function enter(array $params = []): void
    {
        parent::enter($params);
        // Address search comes from the game's site HTML, through the official
        // JumPHP bridge. Dispatch only while the title menu owns the screen.
        $this->flightListener = function (array $event): void {
            if ($this->scenes()->current() !== $this) {
                return;
            }
            $address = trim((string) ($event['address'] ?? ''));
            if ($address !== '') {
                $this->scenes()->switchTo(GeoFlightScene::class, ['address' => $address], false);
            }
        };
        $this->engine->bridge->on('fly-to-address', $this->flightListener);
    }

    public function exit(): void
    {
        if ($this->flightListener !== null) {
            $this->engine->bridge->removeListener('fly-to-address', $this->flightListener);
            $this->flightListener = null;
        }
    }

    protected function items(): array
    {
        return [
            ['START MISSION', null, fn () => $this->scenes()->switchTo(PlayScene::class)],
            ['SURVOL LIBRE', null, fn () => $this->scenes()->switchTo(GeoFlightScene::class)],
        ];
    }

    protected function renderBackground(Renderer $r): void
    {
        $r->clear([32, 57, 53]);
        $r->fillRect(20, 22, 280, 436, [47, 73, 59]);
        $r->fillRect(35, 42, 250, 2, [211, 189, 130]);
        $this->font()->draw($r, '1940  -  ARCADE FICTION', 160, 170, 1, [229, 213, 160], 'center');
        $this->font()->draw($r, 'ARROWS / WASD  +  SPACE', 160, 345, 1, [241, 237, 217], 'center');
    }
}
