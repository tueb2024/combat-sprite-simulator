<?php

declare(strict_types=1);

namespace JumPHP\Standalone\Library;

use StaticPHP\Attribute\Package\BuildFor;
use StaticPHP\Attribute\Package\Library;
use StaticPHP\Package\LibraryPackage;
use StaticPHP\Runtime\Executor\UnixCMakeExecutor;
use StaticPHP\Runtime\Executor\WindowsCMakeExecutor;

/** SDL2_mixer, static: WAV, OGG (stb_vorbis), MP3 (minimp3), FLAC (dr_flac), all built in. */
#[Library('sdl2_mixer')]
final class sdl2_mixer extends LibraryPackage
{
    #[BuildFor('Linux')]
    public function buildLinux(LibraryPackage $lib): void
    {
        UnixCMakeExecutor::create($lib)
            ->appendEnv(['CFLAGS' => sdl2::LINUX_HEADERS])
            ->addConfigureArgs(...self::args($lib))
            ->build();
        $lib->patchPkgconfPrefix(['SDL2_mixer.pc']);
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
            '-DSDL2MIXER_SAMPLES=OFF', '-DSDL2MIXER_VENDORED=OFF', '-DSDL2MIXER_DEPS_SHARED=OFF',
            '-DSDL2MIXER_WAVE=ON', '-DSDL2MIXER_VORBIS=STB', '-DSDL2MIXER_MP3=ON', '-DSDL2MIXER_MP3_MINIMP3=ON',
            '-DSDL2MIXER_MP3_MPG123=OFF', '-DSDL2MIXER_FLAC=ON', '-DSDL2MIXER_FLAC_DRFLAC=ON', '-DSDL2MIXER_FLAC_LIBFLAC=OFF',
            '-DSDL2MIXER_MOD=OFF', '-DSDL2MIXER_MIDI=OFF', '-DSDL2MIXER_OPUS=OFF', '-DSDL2MIXER_WAVPACK=OFF', '-DSDL2MIXER_GME=OFF',
        ];
    }
}
