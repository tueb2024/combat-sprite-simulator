<?php

declare(strict_types=1);

namespace JumPHP\Standalone\Extension;

use Package\Target\php;
use StaticPHP\Attribute\Package\BeforeStage;
use StaticPHP\Attribute\Package\Extension;
use StaticPHP\Attribute\PatchDescription;
use StaticPHP\Package\PhpExtensionPackage;

/** SDL2_ttf (FreeType inside), static; Windows: its config.w32 (registry/w32). */
#[Extension('sdl_ttf')]
class sdl_ttf extends PhpExtensionPackage
{
    #[BeforeStage('php', [php::class, 'buildconfForUnix'], 'ext-sdl_ttf')]
    #[PatchDescription('sdl_ttf: static SDL2_ttf, linked by StaticPHP; its headers from the build root only')]
    public function staticTtf(): void
    {
        $file = "{$this->getSourceDir()}/config.m4";
        jumphp_no_link_test($file, 'SDL2_ttf');
        file_put_contents($file, str_replace('for i in $PHP_SDL_TTF /usr/local /usr; do', 'for i in $PHP_SDL_TTF; do', (string)file_get_contents($file)));
    }

    #[BeforeStage('php', [php::class, 'buildconfForWindows'], 'ext-sdl_ttf')]
    #[PatchDescription('sdl_ttf on Windows: config.w32')]
    public function configW32(): void
    {
        jumphp_config_w32('sdl_ttf', $this->getSourceDir());
    }
}
