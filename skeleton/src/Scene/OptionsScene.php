<?php

namespace App\Scene;

use JumPHP\Kernel\Platform;
use JumPHP\Ui\MenuScene;

/**
 * Settings, saved in var/options.json (IndexedDB in the browser) by the engine's Options service.
 * Pushed over the title screen: back() pops it.
 */
final class OptionsScene extends MenuScene
{
    protected string $title = 'OPTIONS';
    protected float $titleScale = 2.0;

    protected function items(): array
    {
        $options = $this->engine->options;
        $sound = $this->sound();
        $items = [
            ['SOUND', fn () => round($sound->getSfxVolume() * 100) . '%', null, function (int $dir) use ($sound, $options): void {
                $sound->setSfxVolume(round($sound->getSfxVolume() * 10 + $dir) / 10);
                $options->set('sfx_volume', $sound->getSfxVolume());
                $sound->playSfx('menu_move');
            }],
            ['LANGUAGE', fn () => strtoupper($this->engine->lang->getLang()), null, function (int $dir) use ($options): void {
                $langs = $this->engine->lang->available();
                $i = array_search($this->engine->lang->getLang(), $langs, true);
                $lang = $langs[((int)$i + $dir + count($langs)) % count($langs)];
                $this->engine->lang->setLang($lang);
                $options->set('lang', $lang);
            }],
        ];
        if (Platform::isDesktop()) {
            $items[] = ['FULLSCREEN', fn () => $options->get('fullscreen') ? 'ON' : 'OFF', null, fn () => $this->engine->toggleFullscreen()];
        }
        // the browser's install question / the playground page's install menu, and the native downloads (nothing on desktop)
        array_push($items, ...$this->engine->pwa()->menuItems());
        $items[] = ['BACK', null, fn () => $this->back()];
        return $items;
    }

    protected function back(): void
    {
        $this->scenes()->pop();
    }
}
