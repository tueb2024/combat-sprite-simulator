<?php

declare(strict_types=1);

namespace JumPHP\Standalone\Extension;

use Package\Target\php;
use StaticPHP\Attribute\Package\BeforeStage;
use StaticPHP\Attribute\Package\Extension;
use StaticPHP\Attribute\PatchDescription;
use StaticPHP\Package\PhpExtensionPackage;

/** PHP-OpenAL with OpenAL Soft static; Windows: its config.w32 (registry/w32). */
#[Extension('openal')]
class openal extends PhpExtensionPackage
{
    #[BeforeStage('php', [php::class, 'buildconfForUnix'], 'ext-openal')]
    #[PatchDescription('PHP-OpenAL: static OpenAL Soft (C++), linked by StaticPHP, not by a configure test')]
    public function staticOpenal(): void
    {
        jumphp_no_link_test("{$this->getSourceDir()}/config.m4", 'openal');
    }

    #[BeforeStage('php', [php::class, 'buildconfForWindows'], 'ext-openal')]
    #[PatchDescription('openal on Windows: config.w32')]
    public function configW32(): void
    {
        jumphp_config_w32('openal', $this->getSourceDir());
    }
}
