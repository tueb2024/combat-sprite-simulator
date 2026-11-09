<?php

namespace JumPHP\Gl;

use Evenement\EventEmitter;
use JumPHP\Renderer\Renderer;
use JumPHP\Util\Color;

/**
 * A 3D layer and post-processing shaders ($this->engine->gl()), on desktop and in the browser: built on
 * OpenGl (the PHP-OpenGL extension, in SDL's own GL context; WebGL 1 in the browser).
 *
 * At the end of each frame the 2D picture drawn by SDL is mixed with the 3D layer drawn by the commands of the
 * frame, then an optional post-processing shader runs on the whole picture:
 *
 *   SDL picture (2D) ─┐
 *                     ├─ composite ─> post shader (optional) ─> screen
 *   3D layer ─────────┘   'below': the 3D shows through the 2D where the 2D has the key colour (HUD over 3D);
 *                                  a darker shade of the key shows a darker 3D (fades, translucent menus)
 *                         'below' with no key (null), 'view' resolution: the 2D's own alpha says where the 3D
 *                                  shows: clear the 2D with clear([0, 0, 0, 0], true), then anything drawn over it (smooth
 *                                  text, translucent colours) is mixed over the 3D as it is (with an HD renderer
 *                                  the view is the window's resolution: Renderer::setHd())
 *                         'above': the 3D is drawn over the 2D (its transparent pixels show the 2D)
 *
 * Resources are created once (enter()), they get an id; drawing is immediate mode: draw() in render(), every
 * frame (the commands run at the end of the frame, in the layer).
 *
 *   $gl = $this->engine->gl();
 *   if ($gl->isAvailable()) {
 *       $gl->setLayer('below', 'view', [255, 0, 255]);     // 3D at the virtual resolution, behind the 2D
 *       $this->prog = $gl->program($vertexGlsl, $fragmentGlsl);
 *       $this->cube = $gl->buffer($floats);                  // x y z r g b, x y z r g b...
 *   }
 *   // render():
 *   $r->clear([255, 0, 255]);                                // the key colour: here the 3D shows
 *   $gl->clear([0, 0, 0, 255]);
 *   $gl->draw($this->prog, [
 *       'attributes' => ['a_pos' => [$this->cube, 3, 6, 0], 'a_color' => [$this->cube, 3, 6, 3]],  // [buffer, size, stride, offset] in floats
 *       'uniforms' => ['u_mvp' => Mat4::multiply($projection, $model)],
 *   ]);
 *   $gl->setPostShader('void main() { gl_FragColor = texture2D(u_frame, v_uv) * vec4(1.0, 0.9, 0.8, 1.0); }');
 *
 * GLSL ES 1.00 (`#version 100` is added). A post shader gets: varying vec2 v_uv (0..1 over the picture, y up),
 * uniform sampler2D u_frame (the picture), vec2 u_resolution (pixels of the picture it runs on), vec4
 * u_viewport (where the game view is in it: x, y, w, h), vec2 u_view (virtual size), float u_time (game
 * seconds), plus its own uniforms. These declarations and `precision mediump float;` come before the source.
 * With resolution 'view' the layer, the composite and the post shader run at the virtual resolution (big
 * pixels); with 'native' at the window's (smooth 3D, over the scaled picture).
 *
 * Without OpenGL (headless smoke tests with the SDL dummy driver, no extension): isAvailable() is false and
 * every call does nothing: draw a 2D fallback (Mat4::project() for software 3D, see games/jumpball).
 * For raw gl* calls, see OpenGl::begin() / end().
 *
 * Events: 'error' (string $message) when a shader does not compile / link (also written to stderr / the console).
 */
class WebGl extends EventEmitter
{
    private const POST_HEADER = <<<'GLSL'
        precision mediump float;
        varying vec2 v_uv;
        uniform sampler2D u_frame;
        uniform vec2 u_resolution;
        uniform vec4 u_viewport;
        uniform vec2 u_view;
        uniform float u_time;

        GLSL;

    // the 3D layer mixed with the 2D picture: u_mode 0 = below (the key colour shows the layer), 1 = above (alpha),
    // 2 = the picture only (before a post shader), 3 = below by the 2D's alpha (premultiplied: SDL blended it over
    // a transparent clear). Below: a pixel equal to the key colour times s (0..1) shows the layer times s, so
    // translucent black over the key (pause menus, scene fades) darkens the 3D too.
    private const COMPOSITE = <<<'GLSL'
        precision mediump float;
        varying vec2 v_uv;
        uniform sampler2D u_frame;
        uniform sampler2D u_layer;
        uniform float u_frameTop;
        uniform float u_swapIn;
        uniform float u_swapOut;
        uniform vec3 u_key;
        uniform float u_mode;
        void main() {
            vec4 f = texture2D(u_frame, vec2(v_uv.x, mix(v_uv.y, 1.0 - v_uv.y, u_frameTop)));
            if (u_swapIn > 0.5) {
                f = f.bgra;
            }
            vec3 c = f.rgb;
            if (u_mode > 2.5) {
                vec4 l = texture2D(u_layer, v_uv);
                c = f.rgb + l.rgb * l.a * (1.0 - f.a);
            } else if (u_mode < 1.5) {
                vec4 l = texture2D(u_layer, v_uv);
                if (u_mode < 0.5) {
                    float s = clamp(dot(f.rgb, u_key) / dot(u_key, u_key), 0.0, 1.0);
                    c = (s > 0.02 && distance(f.rgb, u_key * s) < 0.02) ? l.rgb * l.a * s : f.rgb;
                } else {
                    c = mix(f.rgb, l.rgb, l.a);
                }
            }
            gl_FragColor = u_swapOut > 0.5 ? vec4(c.bgr, 1.0) : vec4(c, 1.0);
        }
        GLSL;

    private int $nextId = 1;
    /** @var array<int, array{0: string, 1: int}> id => [kind, GL name] */
    private array $res = [];
    /** @var array{position: string, resolution: string, key: float[], alpha: bool}|null */
    private ?array $layer = null;
    /** @var array{program: int, uniforms: array<string, mixed>}|null */
    private ?array $post = null;
    /** @var array<int, array{0: string, 1: mixed, 2?: mixed}> the commands of this frame */
    private array $commands = [];
    /** @var array{fb: int, tex: int, depth: int, w: int, h: int, smooth?: bool}|null */
    private ?array $layerTarget = null;
    /** @var array{fb: int, tex: int, depth: int, w: int, h: int, smooth?: bool}|null */
    private ?array $compTarget = null;
    private int $frameTex = 0;
    /** @var array{0: int, 1: int, 2: bool} size of $frameTex, with alpha or not */
    private array $frameSize = [0, 0, false];
    private int $composite = 0;
    private bool $hooked = false;
    private int $supersample = 1;
    /** @var \Closure(): float */
    private \Closure $clock;

    public function __construct(private OpenGl $gl, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => 0.0;
        $gl->on('error', fn (string $message) => $this->emit('error', [$message]));
    }

    /** True with OpenGL: the browser (WebGL), a desktop window with a GPU or Mesa. */
    public function isAvailable(): bool
    {
        return $this->gl->isAvailable();
    }

    public function openGl(): OpenGl
    {
        return $this->gl;
    }

    // --- layer and post-processing -------------------------------------------------------------

    /**
     * Turns the 3D layer on.
     * @param string $position 'below' (2D over 3D, through the key colour) or 'above' (3D over the 2D)
     * @param string $resolution 'view' (the view texture: game.yaml size, big retro pixels; the window's
     *        resolution with an HD renderer) or 'native' (window pixels: smooth)
     * @param Color|array|string|null $key 'below' only: the 2D colour that shows the 3D (exact match); null: the
     *        2D's own alpha (clear it with clear([0, 0, 0, 0], true); 'view' resolution only: the window has no alpha)
     */
    public function setLayer(string $position = 'below', string $resolution = 'view', Color|array|string|null $key = [255, 0, 255]): void
    {
        if (!in_array($position, ['below', 'above'], true) || !in_array($resolution, ['view', 'native'], true)) {
            throw new \InvalidArgumentException("setLayer: position below|above, resolution view|native");
        }
        if ($key === null && ($position !== 'below' || $resolution !== 'view')) {
            throw new \InvalidArgumentException("setLayer: no key colour (the 2D's alpha) needs 'below' and 'view'");
        }
        $c = Color::of($key ?? [255, 0, 255]);
        $this->layer = ['position' => $position, 'resolution' => $resolution, 'key' => [$c->r / 255, $c->g / 255, $c->b / 255],
            'alpha' => $key === null];
        $this->hook();
    }

    /**
     * Draws the layer at $n x its size (2: 4 samples per pixel), smoothed down when composed: anti-aliased
     * edges. Capped so that the layer stays at most 2560 pixels wide.
     */
    public function setSupersample(int $n): void
    {
        $this->supersample = max(1, min(4, $n));
    }

    public function removeLayer(): void
    {
        $this->layer = null;
    }

    /**
     * Post-processing of the whole picture (null: none). Only the body: see the class comment for the inputs.
     * @param array<string, float|int|array> $uniforms its own uniforms (setUniform() changes them)
     */
    public function setPostShader(?string $fragment, array $uniforms = []): void
    {
        if ($this->post !== null) {
            $this->gl->deleteProgram($this->post['program']);
        }
        $this->post = null;
        if ($fragment === null || !$this->isAvailable()) {
            return;
        }
        // the game's main() runs, then its colour is stored the way SDL's target wants it
        $program = $this->gl->program(OpenGl::QUAD_VERTEX, self::POST_HEADER . "#define main jumphp_post_main\n" . $fragment
            . "\n#undef main\nuniform float jumphp_swap;\nvoid main() {\n    jumphp_post_main();\n    if (jumphp_swap > 0.5) gl_FragColor = gl_FragColor.bgra;\n}\n");
        if ($program > 0) {
            $this->post = ['program' => $program, 'uniforms' => $uniforms];
            $this->hook();
        }
    }

    /** A uniform of the post shader. */
    public function setUniform(string $name, float|int|array $value): void
    {
        if ($this->post !== null) {
            $this->post['uniforms'][$name] = $value;
        }
    }

    /** Back to plain SDL output: no layer, no post shader, resources kept. */
    public function disable(): void
    {
        $this->layer = null;
        $this->setPostShader(null);
        $this->commands = [];
    }

    // --- resources ------------------------------------------------------------------------------

    /** A shader program (GLSL ES 1.00, full sources). Errors: 'error' event. @return int id */
    public function program(string $vertex, string $fragment): int
    {
        return $this->add('program', $this->gl->program($vertex, $fragment));
    }

    /** @param float[] $data vertex data (Float32) @return int id */
    public function buffer(array $data, bool $dynamic = false): int
    {
        return $this->add('buffer', $this->gl->buffer($data, $dynamic));
    }

    /** @param int[] $indices (Uint16: at most 65535 vertices) @return int id */
    public function indexBuffer(array $indices): int
    {
        return $this->add('buffer', $this->gl->indexBuffer($indices));
    }

    /** New data for a buffer. */
    public function updateBuffer(int $id, array $data): void
    {
        $this->gl->updateBuffer($this->name($id, 'buffer'), $data);
    }

    /**
     * A texture from an image of the game (png, jpg). Power-of-two sizes may repeat; others are clamped.
     * @param string $path relative to the game root: 'assets/textures/crate.png'
     * @return int id
     */
    public function texture(string $path, bool $smooth = false, bool $repeat = false): int
    {
        return $this->add('texture', $this->gl->texture($path, $smooth, $repeat));
    }

    /**
     * A texture from raw RGBA bytes (strlen = w * h * 4): procedural pictures, GD output (see rgbaFromGd()).
     * Give the same id again to replace its pixels. @return int id
     */
    public function textureFromRgba(int $width, int $height, string $rgba, bool $smooth = false, ?int $id = null): int
    {
        $pot = ($width & ($width - 1)) === 0 && ($height & ($height - 1)) === 0;
        $name = $this->gl->textureFromRgba($width, $height, $rgba, $smooth, $pot, $id !== null ? $this->name($id, 'texture') : null);
        return $id ?? $this->add('texture', $name);
    }

    /** RGBA bytes of a GD image, for textureFromRgba(). */
    public static function rgbaFromGd(\GdImage $image): string
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $out = '';
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($image, $x, $y);
                $alpha = ($c >> 24) & 0x7F;
                $out .= chr(($c >> 16) & 0xFF) . chr(($c >> 8) & 0xFF) . chr($c & 0xFF) . chr(255 - (int)round($alpha * 255 / 127));
            }
        }
        return $out;
    }

    public function delete(int $id): void
    {
        if (!isset($this->res[$id])) {
            return;
        }
        [$kind, $name] = $this->res[$id];
        unset($this->res[$id]);
        match ($kind) {
            'program' => $this->gl->deleteProgram($name),
            'buffer' => $this->gl->deleteBuffer($name),
            default => $this->gl->deleteTexture($name),
        };
    }

    // --- drawing (immediate: every frame) ---------------------------------------------------------

    /** Clears the 3D layer. @param Color|array|string $color alpha 0 = transparent ('above' layers) */
    public function clear(Color|array|string $color = [0, 0, 0, 0], bool $depth = true): void
    {
        if ($this->isAvailable()) {
            $c = Color::of($color);
            $this->commands[] = ['clear', [$c->r / 255, $c->g / 255, $c->b / 255, $c->a / 255], $depth];
        }
    }

    /**
     * Draws with a program into the 3D layer. $options:
     *   attributes  name => [buffer, size, stride, offset] (stride / offset in floats; 0 = packed)
     *   uniforms    name => float, vecN, mat3 / mat4 (16 floats, column-major: Mat4)
     *   textures    sampler name => texture id
     *   indices     index buffer id; count, first: vertices (count 0 = all)
     *   mode        triangles (default), lines, line_strip, line_loop, points, triangle_strip, triangle_fan
     *   depth       depth test (default true); cull 'back' | 'front' | null (default); blend (alpha, default false)
     * @param array<string, mixed> $options
     */
    public function draw(int $program, array $options): void
    {
        if (!$this->isAvailable()) {
            return;
        }
        $mode = $options['mode'] ?? 'triangles';
        if (!in_array($mode, ['points', 'lines', 'line_strip', 'line_loop', 'triangles', 'triangle_strip', 'triangle_fan'], true)) {
            throw new \InvalidArgumentException("draw: mode '$mode'");
        }
        foreach ($options['attributes'] ?? [] as $name => $a) {
            $options['attributes'][$name][0] = $this->name((int)$a[0], 'buffer');
        }
        foreach ($options['textures'] ?? [] as $name => $id) {
            $options['textures'][$name] = $this->name((int)$id, 'texture');
        }
        if (isset($options['indices'])) {
            $options['indices'] = $this->name((int)$options['indices'], 'buffer');
        }
        $this->commands[] = ['draw', $this->name($program, 'program'), $options];
    }

    /** @deprecated nothing to do: the frame is composed by the engine (Renderer frame hooks). */
    public function flush(Renderer $renderer, float $time): void
    {
    }

    // --- end of the frame -----------------------------------------------------------------------

    private function hook(): void
    {
        if ($this->hooked || !$this->isAvailable()) {
            return;
        }
        $this->hooked = true;
        $renderer = $this->gl->renderer();
        $renderer->addFrameHook('view', fn (Renderer $r) => $this->compose($r, 'view'));
        $renderer->addFrameHook('window', fn (Renderer $r) => $this->compose($r, 'native'));
    }

    /** At the end of the frame: the layer (its commands), the composite, the post shader. */
    private function compose(Renderer $r, string $stage): void
    {
        $resolution = $this->layer['resolution'] ?? 'view';
        if ($resolution !== $stage) {
            return;
        }
        if ($this->layer === null && $this->post === null) {
            $this->commands = [];
            return;
        }
        $gl = $this->gl;
        $gl->sdlSwapsRedBlue();          // measured with SDL calls: before the state is saved
        $gl->save();
        $sdlFb = (int)glGetIntegerv(GL_FRAMEBUFFER_BINDING);
        if ($stage === 'view') {
            // the view texture's pixels: the view's size, times the renderer's HD
            [$w, $h] = $r->viewPixels();
            [$x, $y] = [0, 0];
        } else {
            [$ow, $oh] = $r->outputSize();
            [$lx, $ly, $w, $h] = $r->letterbox();
            [$x, $y] = [$lx, $oh - $ly - $h];        // GL: y up
        }
        if ($w <= 0 || $h <= 0) {
            $gl->restore();
            return;
        }

        if ($this->layer !== null) {
            $ss = max(1, min($this->supersample, intdiv(2560, $w)));
            $this->layerTarget = $this->resized($this->layerTarget, $w * $ss, $h * $ss, true, $ss > 1);
            glBindFramebuffer(GL_FRAMEBUFFER, $this->layerTarget['fb']);
            glViewport(0, 0, $w * $ss, $h * $ss);
            glClearColor(0, 0, 0, 0);
            glDepthMask(1);
            glClear(GL_COLOR_BUFFER_BIT | GL_DEPTH_BUFFER_BIT);
            foreach ($this->commands as $cmd) {
                if ($cmd[0] === 'clear') {
                    glClearColor(...$cmd[1]);
                    glDepthMask(1);
                    glClear(GL_COLOR_BUFFER_BIT | ($cmd[2] ? GL_DEPTH_BUFFER_BIT : 0));
                } else {
                    $gl->draw($cmd[1], $cmd[2]);
                }
            }
        }
        $this->commands = [];

        // the 2D picture, copied from SDL's target (an SDL target texture stores its top row first); with its
        // alpha when the layer is composed by it (the view texture has an alpha, the window has not)
        $alpha = (bool)($this->layer['alpha'] ?? false);
        glBindFramebuffer(GL_FRAMEBUFFER, $sdlFb);
        $this->frameTexture($w, $h, $alpha);
        glCopyTexSubImage2D(GL_TEXTURE_2D, 0, 0, 0, $x, $y, $w, $h);
        $intoSdl = $stage === 'view';

        $composite = $this->compositeProgram();
        $mode = $this->layer === null ? 2.0 : ($this->layer['position'] === 'above' ? 1.0 : ($alpha ? 3.0 : 0.0));
        $swap = $intoSdl && $gl->sdlSwapsRedBlue() ? 1.0 : 0.0;     // colours as SDL's GLES2 targets store them
        $setup = function (int $p, bool $flip) use ($gl, $mode, $intoSdl, $swap): void {
            $gl->uniform($p, 'u_flip', $flip ? 1.0 : 0.0);
            glActiveTexture(GL_TEXTURE1);
            glBindTexture(GL_TEXTURE_2D, $this->layerTarget['tex'] ?? 0);
            $gl->uniform($p, 'u_layer', 1);
            glActiveTexture(GL_TEXTURE0);
            $gl->uniform($p, 'u_key', $this->layer['key'] ?? [1, 0, 1]);
            $gl->uniform($p, 'u_mode', $mode);
            $gl->uniform($p, 'u_frameTop', $intoSdl ? 1.0 : 0.0);
            $gl->uniform($p, 'u_swapIn', $swap);
            $gl->uniform($p, 'u_swapOut', $flip ? $swap : 0.0);
        };
        glDisable(GL_BLEND);
        glDisable(GL_SCISSOR_TEST);
        if ($this->post === null) {
            glBindFramebuffer(GL_FRAMEBUFFER, $sdlFb);
            glViewport($x, $y, $w, $h);
            $gl->pass($composite, $this->frameTex, fn (int $p) => $setup($p, $intoSdl));
        } else {
            $this->compTarget = $this->resized($this->compTarget, $w, $h, false);
            glBindFramebuffer(GL_FRAMEBUFFER, $this->compTarget['fb']);
            glViewport(0, 0, $w, $h);
            $gl->pass($composite, $this->frameTex, fn (int $p) => $setup($p, false));
            glBindFramebuffer(GL_FRAMEBUFFER, $sdlFb);
            glViewport($x, $y, $w, $h);
            $post = $this->post;
            $gl->pass($post['program'], $this->compTarget['tex'], function (int $p) use ($gl, $post, $w, $h, $r, $intoSdl, $swap): void {
                $gl->uniform($p, 'u_flip', $intoSdl ? 1.0 : 0.0);
                $gl->uniform($p, 'jumphp_swap', $swap);
                $gl->uniform($p, 'u_resolution', [$w, $h]);
                $gl->uniform($p, 'u_viewport', [0, 0, $w, $h]);
                $gl->uniform($p, 'u_view', [$r->getWidth(), $r->getHeight()]);
                $gl->uniform($p, 'u_time', ($this->clock)());
                foreach ($post['uniforms'] as $name => $value) {
                    $gl->uniform($p, (string)$name, $value);
                }
            });
        }
        $gl->restore();
    }

    /**
     * @param array{fb: int, tex: int, depth: int, w: int, h: int, smooth?: bool}|null $target
     * @return array{fb: int, tex: int, depth: int, w: int, h: int, smooth?: bool}
     */
    private function resized(?array $target, int $w, int $h, bool $depth, bool $smooth = false): array
    {
        if ($target !== null && $target['w'] === $w && $target['h'] === $h && ($target['smooth'] ?? false) === $smooth) {
            return $target;
        }
        if ($target !== null) {
            $this->gl->deleteTarget($target);
        }
        return ['smooth' => $smooth] + $this->gl->renderTarget($w, $h, $depth, $smooth);
    }

    private function frameTexture(int $w, int $h, bool $alpha = false): void
    {
        if ($this->frameTex === 0) {
            $names = [];
            glGenTextures(1, $names);
            $this->frameTex = (int)$names[0];
        }
        glActiveTexture(GL_TEXTURE0);
        glBindTexture(GL_TEXTURE_2D, $this->frameTex);
        if ($this->frameSize !== [$w, $h, $alpha]) {
            // RGB: WebGL refuses to copy a framebuffer without alpha (the window, 'native') into an RGBA texture;
            // RGBA only when the 2D's alpha composes the layer (copied from the view texture, which has one)
            $format = $alpha ? GL_RGBA : GL_RGB;
            glTexImage2D(GL_TEXTURE_2D, 0, $format, $w, $h, 0, $format, GL_UNSIGNED_BYTE, null);
            foreach ([GL_TEXTURE_MIN_FILTER, GL_TEXTURE_MAG_FILTER] as $p) {
                glTexParameteri(GL_TEXTURE_2D, $p, GL_NEAREST);
            }
            glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_S, GL_CLAMP_TO_EDGE);
            glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_T, GL_CLAMP_TO_EDGE);
            $this->frameSize = [$w, $h, $alpha];
        }
    }

    private function compositeProgram(): int
    {
        return $this->composite = $this->composite ?: $this->gl->program(OpenGl::QUAD_VERTEX, self::COMPOSITE);
    }

    private function add(string $kind, int $name): int
    {
        if ($name <= 0) {
            return 0;
        }
        $id = $this->nextId++;
        $this->res[$id] = [$kind, $name];
        return $id;
    }

    private function name(int $id, string $kind): int
    {
        $r = $this->res[$id] ?? null;
        return $r !== null && $r[0] === $kind ? $r[1] : 0;
    }
}
