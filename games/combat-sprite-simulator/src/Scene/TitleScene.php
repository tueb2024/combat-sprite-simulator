<?php

namespace App\Scene;

use JumPHP\Renderer\Renderer;
use JumPHP\Ui\MenuScene;

final class TitleScene extends MenuScene
{
    protected string $title = 'COMBAT SPRITE';

    protected function items(): array
    {
        return [['START MISSION', null, fn () => $this->scenes()->switchTo(PlayScene::class)]];
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
