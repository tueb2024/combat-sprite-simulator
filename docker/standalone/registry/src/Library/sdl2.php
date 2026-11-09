<?php

declare(strict_types=1);

namespace JumPHP\Standalone\Library;

use StaticPHP\Attribute\Package\BuildFor;
use StaticPHP\Attribute\Package\Library;
use StaticPHP\Package\LibraryPackage;
use StaticPHP\Runtime\Executor\UnixCMakeExecutor;
use StaticPHP\Runtime\Executor\WindowsCMakeExecutor;

/**
 * SDL2, static. Linux: X11, OpenGL, ALSA and PulseAudio loaded at run time from the player's system (dlopen), no
 * Wayland or KMS (headless consoles). StaticPHP's toolchain makes CMake look in the build root only: the headers of
 * X11, ALSA and PulseAudio are copied there (declarations only), the libraries named by their path (SDL reads their
 * soname, to load them), libpulse.pc written there while SDL is configured.
 */
#[Library('sdl2')]
final class sdl2 extends LibraryPackage
{
    public const LINUX_HEADERS = '-idirafter /usr/include -idirafter /usr/include/x86_64-linux-gnu';

    private const LINUX_LIBS = '/usr/lib/x86_64-linux-gnu';

    #[BuildFor('Linux')]
    public function buildLinux(LibraryPackage $lib): void
    {
        $root = $lib->getBuildRootPath();
        foreach (['X11', 'alsa', 'pulse'] as $headers) {
            exec("mkdir -p $root/include && cp -r /usr/include/$headers $root/include/");
        }
        $pc = "$root/lib/pkgconfig/libpulse.pc";
        @mkdir(dirname($pc), 0777, true);
        file_put_contents($pc, "Name: libpulse\nDescription: PulseAudio (headers only: SDL loads it at run time)\nVersion: 16.1\nLibs: -lpulse\nCflags:\n");
        $found = [];
        foreach (['X11', 'Xext', 'Xcursor', 'Xi', 'Xfixes', 'Xrandr', 'Xrender', 'Xss', 'asound', 'pulse'] as $name) {
            $found[] = '-D' . strtoupper($name) . '_LIB=' . self::LINUX_LIBS . "/lib$name.so";
        }
        $found[] = "-DALSA_INCLUDE_DIR=$root/include";
        $found[] = '-DALSA_LIBRARY=' . self::LINUX_LIBS . '/libasound.so';
        try {
            self::buildLinuxWith($lib, $found);
        } finally {
            @unlink($pc);
        }
        $config = (string)file_get_contents("$root/include/SDL2/SDL_config.h");
        foreach (['SDL_VIDEO_DRIVER_X11_DYNAMIC', 'SDL_AUDIO_DRIVER_ALSA_DYNAMIC', 'SDL_AUDIO_DRIVER_PULSEAUDIO_DYNAMIC'] as $driver) {
            if (!preg_match("/#define $driver \"/", $config)) {
                throw new \StaticPHP\Exception\BuildFailureException("SDL2 built without $driver: the game would have no window or no sound");
            }
        }
        $lib->patchPkgconfPrefix(['sdl2.pc']);
    }

    /** @param string[] $found */
    private static function buildLinuxWith(LibraryPackage $lib, array $found): void
    {
        UnixCMakeExecutor::create($lib)
            ->appendEnv(['CFLAGS' => self::LINUX_HEADERS])
            ->addConfigureArgs(...self::common(), ...[
                '-DSDL_X11=ON', '-DSDL_X11_SHARED=ON', '-DSDL_X11_XRANDR=ON', '-DSDL_X11_XCURSOR=ON', '-DSDL_X11_XINPUT=ON',
                '-DSDL_X11_XSCRNSAVER=ON', '-DSDL_X11_XSHAPE=ON', '-DSDL_X11_XFIXES=ON', '-DSDL_X11_XDBE=OFF',
                '-DSDL_WAYLAND=OFF', '-DSDL_KMSDRM=OFF', '-DSDL_RPI=OFF', '-DSDL_VIVANTE=OFF', '-DSDL_DIRECTFB=OFF',
                '-DSDL_OPENGL=ON', '-DSDL_OPENGLES=ON', '-DSDL_VULKAN=OFF',
                '-DSDL_ALSA=ON', '-DSDL_ALSA_SHARED=ON', '-DSDL_PULSEAUDIO=ON', '-DSDL_PULSEAUDIO_SHARED=ON',
                '-DSDL_PIPEWIRE=OFF', '-DSDL_JACK=OFF', '-DSDL_SNDIO=OFF', '-DSDL_ESD=OFF', '-DSDL_ARTS=OFF', '-DSDL_NAS=OFF',
                '-DSDL_LIBSAMPLERATE=OFF', '-DSDL_DBUS=OFF', '-DSDL_IBUS=OFF', '-DSDL_LIBUDEV=OFF',
            ], ...$found)
            ->build();
    }

    #[BuildFor('Windows')]
    public function buildWindows(LibraryPackage $lib): void
    {
        WindowsCMakeExecutor::create($lib)
            ->addConfigureArgs(...self::common(), ...['-DSDL_FORCE_STATIC_VCRT=ON', '-DSDL_LIBC=ON'])
            ->build();
    }

    /** @return string[] */
    private static function common(): array
    {
        return ['-DSDL_SHARED=OFF', '-DSDL_STATIC=ON', '-DSDL_STATIC_PIC=ON', '-DSDL_TEST=OFF', '-DSDL2_DISABLE_SDL2MAIN=OFF'];
    }
}
