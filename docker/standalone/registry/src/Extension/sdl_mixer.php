<?php

declare(strict_types=1);

namespace JumPHP\Standalone\Extension;

use Package\Target\php;
use StaticPHP\Attribute\Package\BeforeStage;
use StaticPHP\Attribute\Package\Extension;
use StaticPHP\Attribute\PatchDescription;
use StaticPHP\Package\PhpExtensionPackage;

/** Windows: its config.w32 (registry/w32); its zval helpers as plain functions. */
#[Extension('sdl_mixer')]
class sdl_mixer extends PhpExtensionPackage
{
    #[BeforeStage('php', [php::class, 'buildconfForWindows'], 'ext-sdl_mixer')]
    #[PatchDescription('sdl_mixer on Windows: config.w32; mix_*_to_zval not inline')]
    public function configW32(): void
    {
        jumphp_config_w32('sdl_mixer', $this->getSourceDir());
        // `inline void mix_chunk_to_zval(...)`, called from the other files: an external function for GCC / clang
        // (C99: the header declares it without inline), none for MSVC (LNK2019)
        foreach (['Mix_Chunk', 'Mix_Music'] as $name) {
            $file = "{$this->getSourceDir()}/src/$name.c";
            $code = (string)file_get_contents($file);
            file_put_contents($file, preg_replace('/^inline (void mix_\w+_to_zval\()/m', '$1', $code));
        }
    }
}
