<?php

declare(strict_types=1);

namespace JumPHP\Standalone\Library;

use StaticPHP\Attribute\Package\BuildFor;
use StaticPHP\Attribute\Package\Library;
use StaticPHP\Package\LibraryPackage;
use StaticPHP\Runtime\Executor\UnixCMakeExecutor;
use StaticPHP\Runtime\Executor\WindowsCMakeExecutor;

/** SDL2_ttf, static, with the FreeType of its own archive (no HarfBuzz: no complex scripts). */
#[Library('sdl2_ttf')]
final class sdl2_ttf extends LibraryPackage
{
    #[BuildFor('Linux')]
    public function buildLinux(LibraryPackage $lib): void
    {
        UnixCMakeExecutor::create($lib)
            ->appendEnv(['CFLAGS' => sdl2::LINUX_HEADERS])
            ->addConfigureArgs(...self::args($lib))
            ->build();
        $lib->patchPkgconfPrefix(['SDL2_ttf.pc']);
    }

    #[BuildFor('Windows')]
    public function buildWindows(LibraryPackage $lib): void
    {
        WindowsCMakeExecutor::create($lib)->addConfigureArgs(...self::args($lib))->build();
    }

    /** @return string[] */
    private static function args(LibraryPackage $lib): array
    {
        return [
            "-DSDL2_DIR={$lib->getBuildRootPath()}/lib/cmake/SDL2", "-DCMAKE_PREFIX_PATH={$lib->getBuildRootPath()}",
            '-DSDL2TTF_SAMPLES=OFF', '-DSDL2TTF_VENDORED=ON', '-DSDL2TTF_HARFBUZZ=OFF', '-DFT_DISABLE_ZLIB=ON',
            '-DFT_DISABLE_PNG=ON', '-DFT_DISABLE_BZIP2=ON', '-DFT_DISABLE_BROTLI=ON', '-DFT_DISABLE_HARFBUZZ=ON',
        ];
    }
}
