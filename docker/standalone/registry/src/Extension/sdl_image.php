<?php

declare(strict_types=1);

namespace JumPHP\Standalone\Extension;

use Package\Target\php;
use StaticPHP\Attribute\Package\BeforeStage;
use StaticPHP\Attribute\Package\Extension;
use StaticPHP\Attribute\PatchDescription;
use StaticPHP\Package\PhpExtensionPackage;

/** Windows: its config.w32 (registry/w32). */
#[Extension('sdl_image')]
class sdl_image extends PhpExtensionPackage
{
    #[BeforeStage('php', [php::class, 'buildconfForWindows'], 'ext-sdl_image')]
    #[PatchDescription('sdl_image on Windows: config.w32')]
    public function configW32(): void
    {
        jumphp_config_w32('sdl_image', $this->getSourceDir());
    }
}
