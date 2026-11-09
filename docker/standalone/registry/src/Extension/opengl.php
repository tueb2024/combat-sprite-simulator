<?php

declare(strict_types=1);

namespace JumPHP\Standalone\Extension;

use Package\Target\php;
use StaticPHP\Attribute\Package\BeforeStage;
use StaticPHP\Attribute\Package\Extension;
use StaticPHP\Attribute\PatchDescription;
use StaticPHP\Exception\BuildFailureException;
use StaticPHP\Package\PhpExtensionPackage;

/** PHP-OpenGL with JumPHP's additions (docker/ext/php-opengl.patch, as the desktop runtime has it), OpenGL from SDL. */
#[Extension('opengl')]
class opengl extends PhpExtensionPackage
{
    #[PatchDescription('JumPHP additions to PHP-OpenGL')]
    public function patchJumphp(): void
    {
        $dir = $this->getSourceDir();
        if (is_file("$dir/jumphp_gl.c")) {
            return;
        }
        $patch = dirname(__DIR__, 4) . '/ext/php-opengl.patch';
        if (!is_file($patch)) {
            throw new BuildFailureException("JumPHP's PHP-OpenGL patch not found: $patch");
        }
        exec('cd ' . escapeshellarg($dir) . ' && git apply -p1 ' . escapeshellarg($patch) . ' 2>&1', $out, $code);
        if ($code !== 0) {
            throw new BuildFailureException("php-opengl.patch does not apply:\n" . implode("\n", $out));
        }
    }

    #[BeforeStage('php', [php::class, 'buildconfForUnix'], 'ext-opengl')]
    #[PatchDescription('PHP-OpenGL on Linux: OpenGL from SDL at run time; the GL headers; configure keeps no -lGL')]
    public function linux(): void
    {
        $this->patchJumphp();
        $dir = $this->getSourceDir();
        jumphp_gl_loader($dir, false);
        // the headers only (Khronos', from the build machine): next to the other libraries' in buildroot
        foreach (['GL', 'KHR'] as $headers) {
            exec('mkdir -p ' . BUILD_ROOT_PATH . "/include && cp -r /usr/include/$headers " . BUILD_ROOT_PATH . '/include/');
        }
        // its configure links libGL (and leaves -lMesaGL in LIBS when none links: every later test then fails)
        $m4 = (string)file_get_contents("$dir/config.m4");
        if (!str_contains($m4, 'JumPHP')) {
            $m4 = str_replace('  unset additional_libs', '  LIBS="$saved_LIBS" # JumPHP: no OpenGL library linked' . "\n  unset additional_libs", $m4);
            file_put_contents("$dir/config.m4", str_replace('PHP_ADD_LIBRARY_WITH_PATH(GL, /usr/$PHP_LIBDIR, OPENGL_SHARED_LIBADD)', ':', $m4));
        }
    }

    #[BeforeStage('php', [php::class, 'buildconfForWindows'], 'ext-opengl')]
    #[PatchDescription('PHP-OpenGL on Windows: config.w32, OpenGL 1.2+ from SDL at run time')]
    public function windows(): void
    {
        $this->patchJumphp();
        jumphp_config_w32('opengl', $this->getSourceDir());
        jumphp_gl_loader($this->getSourceDir(), true, BUILD_ROOT_PATH . '/include/SDL2/SDL_opengl_glext.h');
    }
}
