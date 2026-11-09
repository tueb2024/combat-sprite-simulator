<?php

namespace JumPHP\Tests\Gl;

use JumPHP\Gl\Mat4;
use JumPHP\Gl\OpenGl;
use JumPHP\Gl\WebGl;
use JumPHP\Kernel\GameConfig;
use JumPHP\Renderer\Sdl;
use PHPUnit\Framework\TestCase;

final class Mat4Test extends TestCase
{
    public function testMultiplyOrderAndTransform(): void
    {
        // translate after scale: (1,0,0) -> scaled to (2,0,0) -> moved to (3,0,0)
        $m = Mat4::multiply(Mat4::translate(1, 0, 0), Mat4::scale(2));
        self::assertEqualsWithDelta([3.0, 0.0, 0.0, 1.0], Mat4::transform($m, [1, 0, 0]), 1e-9);
        self::assertEqualsWithDelta(Mat4::identity(), Mat4::multiply(Mat4::identity(), Mat4::identity()), 1e-9);
        self::assertEqualsWithDelta([0.0, 0.0, -1.0, 1.0], Mat4::transform(Mat4::rotateY(M_PI / 2), [1, 0, 0]), 1e-9);
    }

    public function testProjection(): void
    {
        $mvp = Mat4::multiply(Mat4::perspective(90, 1, 0.1, 100), Mat4::lookAt([0, 0, 5], [0, 0, 0]));
        // the target is the centre of the view
        self::assertEqualsWithDelta([160.0, 90.0], array_slice(Mat4::project($mvp, [0, 0, 0], 320, 180), 0, 2), 1e-6);
        // up is up on screen
        self::assertLessThan(90, Mat4::project($mvp, [0, 1, 0], 320, 180)[1]);
        // behind the camera
        self::assertNull(Mat4::project($mvp, [0, 0, 10], 320, 180));
    }

    public function testWithoutGlContextEverythingIsANoOp(): void
    {
        // no window (SDL not initialised): no GL context, every call returns 0 / does nothing
        $opengl = new OpenGl(new Sdl(new GameConfig([], '/tmp')));
        $gl = new WebGl($opengl);
        self::assertFalse($gl->isAvailable());
        self::assertFalse($opengl->begin());
        $gl->setLayer();
        $gl->setPostShader('void main() { gl_FragColor = vec4(1.0); }');
        self::assertSame(0, $gl->program('a', 'b'));
        self::assertSame(0, $gl->buffer([1.0, 2.0, 3.0]));
        $gl->draw(1, ['count' => 3]);
        $gl->clear([0, 0, 0]);
    }

    public function testVersionedShaders(): void
    {
        self::assertStringStartsWith("#version 100\n", OpenGl::versioned("void main() {}"));
        self::assertSame("#version 100\nvoid main() {}", OpenGl::versioned("#version 100\nvoid main() {}"));
        self::assertSame("// x\n#version 300 es\n", OpenGl::versioned("// x\n#version 300 es\n"));
    }

    public function testShadersForMacosOpenGl(): void
    {
        $fragment = "precision mediump float;\nvarying mediump vec2 v_uv;\nvoid main() { gl_FragColor = vec4(v_uv, 0.0, 1.0); }";
        self::assertSame("#version 120\n#define lowp\n#define mediump\n#define highp\n\nvarying mediump vec2 v_uv;\n"
            . "void main() { gl_FragColor = vec4(v_uv, 0.0, 1.0); }", OpenGl::versioned($fragment, true));
        self::assertSame("// x\n#version 120\n#define lowp\n#define mediump\n#define highp\nvoid main() {}",
            OpenGl::versioned("// x\n#version 100\nvoid main() {}", true));
        self::assertSame("#version 300 es\n", OpenGl::versioned("#version 300 es\n", true));
    }
}
