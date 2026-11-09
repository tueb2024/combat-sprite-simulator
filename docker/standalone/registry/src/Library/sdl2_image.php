<?php

declare(strict_types=1);

namespace JumPHP\Standalone\Library;

use StaticPHP\Attribute\Package\BuildFor;
use StaticPHP\Attribute\Package\Library;
use StaticPHP\Package\LibraryPackage;
use StaticPHP\Runtime\Executor\UnixCMakeExecutor;
use StaticPHP\Runtime\Executor\WindowsCMakeExecutor;

/** SDL2_image, static: PNG, JPG, BMP, GIF, TGA... with its built-in decoders (stb), no other library. */
#[Library('sdl2_image')]
final class sdl2_image extends LibraryPackage
{
    #[BuildFor('Linux')]
    public function buildLinux(LibraryPackage $lib): void
    {
        UnixCMakeExecutor::create($lib)
            ->appendEnv(['CFLAGS' => sdl2::LINUX_HEADERS])
            ->addConfigureArgs(...self::args($lib))
            ->build();
        $lib->patchPkgconfPrefix(['SDL2_image.pc']);
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
            '-DSDL2IMAGE_SAMPLES=OFF', '-DSDL2IMAGE_TESTS=OFF', '-DSDL2IMAGE_VENDORED=OFF', '-DSDL2IMAGE_DEPS_SHARED=OFF',
            '-DSDL2IMAGE_BACKEND_STB=ON', '-DSDL2IMAGE_BACKEND_WIC=OFF',
            '-DSDL2IMAGE_AVIF=OFF', '-DSDL2IMAGE_JXL=OFF', '-DSDL2IMAGE_TIF=OFF', '-DSDL2IMAGE_WEBP=OFF', '-DSDL2IMAGE_SVG=OFF',
            '-DSDL2IMAGE_PNG_SAVE=OFF', '-DSDL2IMAGE_JPG_SAVE=OFF',
        ];
    }
}
