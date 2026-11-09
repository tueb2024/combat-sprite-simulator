<?php

declare(strict_types=1);

namespace JumPHP\Standalone\Library;

use StaticPHP\Attribute\Package\BuildFor;
use StaticPHP\Attribute\Package\Library;
use StaticPHP\Attribute\Package\PatchBeforeBuild;
use StaticPHP\Attribute\PatchDescription;
use StaticPHP\Package\LibraryPackage;
use StaticPHP\Runtime\Executor\UnixCMakeExecutor;
use StaticPHP\Runtime\Executor\WindowsCMakeExecutor;

/** OpenAL Soft, static (the 3D sound of the engine); Linux: ALSA and PulseAudio loaded at run time. */
#[Library('openal')]
final class openal extends LibraryPackage
{
    #[PatchBeforeBuild]
    #[PatchDescription('OpenAL Soft on glibc 2.17 (no C11 threads.h): its pthread version')]
    public function patchBeforeBuild(LibraryPackage $lib): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return;
        }
        $file = "{$lib->getSourceDir()}/common/althreads.h";
        $code = (string)file_get_contents($file);
        file_put_contents($file, str_replace('#elif defined(__STDC_NO_THREADS__) || !__has_include(<threads.h>)', '#elif 1 /* JumPHP: glibc 2.17 */', $code));
    }

    #[BuildFor('Linux')]
    public function buildLinux(LibraryPackage $lib): void
    {
        UnixCMakeExecutor::create($lib)
            ->appendEnv(['CFLAGS' => sdl2::LINUX_HEADERS, 'CXXFLAGS' => sdl2::LINUX_HEADERS])
            ->addConfigureArgs(...self::args(), ...[
                '-DALSOFT_BACKEND_ALSA=ON', '-DALSOFT_BACKEND_PULSEAUDIO=ON', '-DALSOFT_BACKEND_OSS=ON',
                '-DALSOFT_BACKEND_PIPEWIRE=OFF', '-DALSOFT_BACKEND_JACK=OFF', '-DALSOFT_BACKEND_SNDIO=OFF',
                '-DALSOFT_BACKEND_PORTAUDIO=OFF', '-DALSOFT_DLOPEN=ON',
            ])
            ->build();
        $lib->patchPkgconfPrefix(['openal.pc']);
    }

    #[BuildFor('Windows')]
    public function buildWindows(LibraryPackage $lib): void
    {
        // OpenAL Soft 1.25 builds C++20 modules by default ("module 'alc.context' not found" under MSVC and the Visual Studio generator): off
        WindowsCMakeExecutor::create($lib)->addConfigureArgs(...self::args(), ...['-DALSOFT_ENABLE_MODULES=OFF'])->build();
    }

    /** @return string[] */
    private static function args(): array
    {
        return [
            '-DLIBTYPE=STATIC', '-DALSOFT_UTILS=OFF', '-DALSOFT_EXAMPLES=OFF', '-DALSOFT_TESTS=OFF',
            '-DALSOFT_INSTALL_CONFIG=OFF', '-DALSOFT_INSTALL_HRTF_DATA=OFF', '-DALSOFT_INSTALL_AMBDEC_PRESETS=OFF',
            '-DALSOFT_INSTALL_EXAMPLES=OFF', '-DALSOFT_INSTALL_UTILS=OFF', '-DALSOFT_EAX=OFF',
        ];
    }
}
