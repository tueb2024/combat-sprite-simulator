<?php

namespace JumPHP\Gl;

use Evenement\EventEmitter;
use JumPHP\Kernel\Platform;
use JumPHP\Renderer\Renderer;
use JumPHP\Renderer\Sdl;
use JumPHP\Util\Color;

/**
 * OpenGL from PHP ($this->engine->opengl()): the PHP-OpenGL extension (Ponup/php-opengl + docker/ext/php-opengl.patch)
 * in the same GL context as SDL's 2D renderer. Desktop: OpenGL (Mesa / the GPU driver); browser: WebGL 1, the
 * same calls compiled by emscripten. Write GLSL ES 1.00 (attribute / varying / gl_FragColor, `precision` in
 * fragment shaders): program() adds `#version 100`, which both accept.
 *
 * SDL draws the 2D with the same context, so raw gl* calls go between begin() and end():
 *
 *   if ($gl->begin([20, 20, 60])) {              // a 3D target (view size, depth), cleared; SDL's state saved
 *       glUseProgram($program); ...; glDrawArrays(GL_TRIANGLES, 0, 36);   // or $gl->draw($program, [...])
 *       $gl->end();                              // the 3D into the current 2D target, alpha blended; state restored
 *   }
 *   $this->font()->draw($r, 'HUD', ...);         // 2D over the 3D
 *
 * Resources: program(), buffer(), indexBuffer(), texture(), textureFromRgba() return GL names; they can be
 * created any time (they save / restore the state themselves). WebGl ($this->engine->gl()) is the higher level
 * API built on this class (a 3D layer under / over the whole picture + post-processing shaders).
 *
 * isAvailable() is false without the extension or without a GL context (headless smoke tests with the SDL
 * dummy driver, a software renderer): draw a 2D fallback then.
 *
 * Events: 'error' (string $message): a shader does not compile / link.
 */
class OpenGl extends EventEmitter
{
    private ?bool $available = null;
    private int $depth = 0;
    /** @var array<string, mixed>|null the SDL state saved by the outermost save() */
    private ?array $saved = null;
    /** @var array<int, array<string, array{0: int, 1: int, 2: int}>> program => uniform name => [location, type, size] */
    private array $uniforms = [];
    /** @var array<int, array<string, int>> program => attribute name => location */
    private array $attributes = [];
    /** @var array<int, int> buffer name => float count */
    private array $bufferSizes = [];
    /** @var array<int, true> index buffers */
    private array $indexBuffers = [];
    private int $vao = 0;
    private int $quad = 0;
    private int $blit = 0;
    /** @var array{fb: int, tex: int, depth: int, w: int, h: int}|null */
    private ?array $target = null;
    private int $supersample = 1;
    private ?bool $swapsRedBlue = null;
    private ?bool $legacyGlsl = null;

    public function __construct(private Sdl $sdl)
    {
    }

    /** True with the extension and a GL context (desktop window, browser). */
    public function isAvailable(): bool
    {
        if ($this->available === null) {
            if (!$this->sdl->isReady()) {
                return false;          // asked before the window exists: ask again later
            }
            $this->available = extension_loaded('OpenGL') && !$this->sdl->isSoftware() && (string)@glGetString(GL_VERSION) !== '';
        }
        return $this->available;
    }

    /** "OpenGL ES 2.0 (WebGL 1.0)", "4.5 (Compatibility Profile) Mesa 22.3.6"... */
    public function version(): string
    {
        return $this->isAvailable() ? (string)glGetString(GL_VERSION) . ' | ' . (string)glGetString(GL_RENDERER) : '';
    }

    public function renderer(): Renderer
    {
        return $this->sdl->getRenderer();
    }

    public function isWebGl(): bool
    {
        return Platform::isWasm();
    }

    /** begin(): the 3D target is $n times the view size (2 = smoother edges, more pixels to fill). */
    public function setSupersample(int $n): void
    {
        $this->supersample = max(1, min(4, $n));
    }

    // --- raw GL between begin() and end() ---------------------------------------------------------

    /**
     * Starts raw GL drawing: SDL's pending 2D is drawn, its GL state saved, a 3D target (view size × supersample,
     * colour + depth) bound and cleared with $clear (null: kept from the previous frame). @return bool false
     * without GL (draw a fallback)
     */
    public function begin(Color|array|string|null $clear = [0, 0, 0, 0]): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        $r = $this->sdl->getRenderer();
        $this->sdlSwapsRedBlue();        // measured with SDL calls: before the state is saved
        $this->save();
        [$vw, $vh] = $r->viewPixels();   // the view texture's pixels (bigger than the view in HD)
        $t = $this->target($vw * $this->supersample, $vh * $this->supersample);
        glBindFramebuffer(GL_FRAMEBUFFER, $t['fb']);
        glViewport(0, 0, $t['w'], $t['h']);
        if ($clear !== null) {
            $c = Color::of($clear);
            glClearColor($c->r / 255, $c->g / 255, $c->b / 255, $c->a / 255);
            glDepthMask(1);
            glClear(GL_COLOR_BUFFER_BIT | GL_DEPTH_BUFFER_BIT);
        }
        glEnable(GL_DEPTH_TEST);
        glDepthFunc(GL_LEQUAL);
        return true;
    }

    /** The 3D target of begin() into the 2D picture being drawn (alpha blended), SDL's state back. */
    public function end(): void
    {
        if ($this->target === null || $this->saved === null) {
            return;
        }
        // into the scene's target: the view texture (SDL sets its viewport lazily: never trust the current one)
        [$vw, $vh] = $this->sdl->getRenderer()->viewPixels();
        glBindFramebuffer(GL_FRAMEBUFFER, $this->saved['fb']);
        glViewport(0, 0, $vw, $vh);
        $this->blit($this->target['tex'], true, true);
        $this->restore();
    }

    /**
     * True when SDL's target textures hold their pixels as BGR (SDL's GLES2 renderer, the browser): what GL
     * writes into them must swap red and blue (u_swap of the passes). Measured once: SDL fills a 1x1 target in red.
     */
    public function sdlSwapsRedBlue(): bool
    {
        if ($this->swapsRedBlue === null) {
            $r = $this->sdl->getRenderer();
            $probe = $r->createTargetTexture(1, 1);
            $previous = $r->setTarget($probe);
            \SDL_SetRenderDrawColor($r->getRenderer(), 255, 0, 0, 255);
            \SDL_RenderClear($r->getRenderer());
            $r->flush();
            $pixel = (string)glReadPixels(0, 0, 1, 1, GL_RGBA, GL_UNSIGNED_BYTE);
            $r->setTarget($previous);
            \SDL_DestroyTexture($probe);
            $this->swapsRedBlue = strlen($pixel) === 4 && ord($pixel[0]) < 128 && ord($pixel[2]) >= 128;
        }
        return $this->swapsRedBlue;
    }

    /** Size of the current begin() target (the viewport). @return array{0: int, 1: int} */
    public function targetSize(): array
    {
        return $this->target === null ? [0, 0] : [$this->target['w'], $this->target['h']];
    }

    // --- resources --------------------------------------------------------------------------------

    /**
     * A shader program from GLSL ES 1.00 sources (`#version 100` added when missing). Errors: 'error' event,
     * returns 0. @param array<string, int> $attributes fixed locations (name => index), optional
     */
    public function program(string $vertex, string $fragment, array $attributes = []): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }
        $this->save();
        $program = glCreateProgram();
        $ok = true;
        foreach ([GL_VERTEX_SHADER => $vertex, GL_FRAGMENT_SHADER => $fragment] as $type => $source) {
            $shader = glCreateShader($type);
            $source = self::versioned($source, $this->legacyGlsl());
            glShaderSource($shader, 1, $source, 0);
            if (!@glCompileShader($shader)) {
                $this->fail(($type === GL_VERTEX_SHADER ? 'vertex' : 'fragment') . ' shader: ' . trim(glGetShaderInfoLog($shader) ?: 'compile error'));
                $ok = false;
                break;
            }
            glAttachShader($program, $shader);
            glDeleteShader($shader);      // freed with the program
        }
        foreach ($attributes as $name => $index) {
            glBindAttribLocation($program, $index, $name);
        }
        if ($ok && !@glLinkProgram($program)) {
            $this->fail('link: ' . trim(glGetProgramInfoLog($program) ?: 'link error'));
            $ok = false;
        }
        if (!$ok) {
            glDeleteProgram($program);
            $this->restore();
            return 0;
        }
        $this->uniforms[$program] = [];
        for ($i = 0, $n = glGetProgramiv($program, GL_ACTIVE_UNIFORMS); $i < $n; $i++) {
            $u = glGetActiveUniform($program, $i);
            if (is_array($u)) {
                $name = preg_replace('/\[0\]$/', '', $u['name']);
                $this->uniforms[$program][$name] = [glGetUniformLocation($program, $u['name']), $u['type'], $u['size']];
            }
        }
        $this->restore();
        return $program;
    }

    /** @param float[]|string $data floats, or a binary string of floats (pack('g*', ...)) @return int buffer name */
    public function buffer(array|string $data, bool $dynamic = false): int
    {
        return $this->createBuffer(GL_ARRAY_BUFFER, $data, $dynamic);
    }

    /** @param int[] $indices (16-bit: at most 65535 vertices) @return int buffer name */
    public function indexBuffer(array $indices): int
    {
        $id = $this->createBuffer(GL_ELEMENT_ARRAY_BUFFER, pack('v*', ...array_map('intval', $indices)), false);
        if ($id > 0) {
            $this->indexBuffers[$id] = true;
            $this->bufferSizes[$id] = count($indices);
        }
        return $id;
    }

    /** New contents for a buffer() (any size). @param float[]|string $data */
    public function updateBuffer(int $id, array|string $data): void
    {
        if (!$this->isAvailable() || $id <= 0) {
            return;
        }
        $bytes = is_string($data) ? $data : self::floats($data);
        $this->save();
        if ($this->depth === 1) {
            glBindVertexArray(0);
        }
        glBindBuffer(GL_ARRAY_BUFFER, $id);
        glBufferData(GL_ARRAY_BUFFER, strlen($bytes), $bytes === '' ? null : $bytes, GL_DYNAMIC_DRAW);
        $this->bufferSizes[$id] = intdiv(strlen($bytes), 4);
        $this->restore();
    }

    /**
     * A texture from an image of the game (png / jpg / gif, read with GD), rows top first: sample it with
     * v = 0 at the top. @param string $path relative to the game root @return int texture name
     */
    public function texture(string $path, bool $smooth = false, bool $repeat = false): int
    {
        $file = \JumPHP\Kernel\Platform::isAbsolutePath($path) ? $path : (\defined('JUMPHP_ROOT') ? JUMPHP_ROOT . '/' : '') . $path;
        $data = @file_get_contents($file);
        $image = $data === false ? false : @imagecreatefromstring($data);
        if ($image === false) {
            $this->fail("texture: cannot read $path");
            return 0;
        }
        imagesavealpha($image, true);
        return $this->textureFromRgba(imagesx($image), imagesy($image), WebGl::rgbaFromGd($image), $smooth, $repeat);
    }

    /** A texture from raw RGBA bytes (w × h × 4). Give $id to replace its pixels. @return int texture name */
    public function textureFromRgba(int $width, int $height, string $rgba, bool $smooth = false, bool $repeat = false, ?int $id = null): int
    {
        if (strlen($rgba) !== $width * $height * 4) {
            throw new \InvalidArgumentException('textureFromRgba: ' . strlen($rgba) . " bytes for {$width}x{$height}");
        }
        if (!$this->isAvailable()) {
            return 0;
        }
        $this->save();
        if ($id === null) {
            $names = [];
            glGenTextures(1, $names);
            $id = (int)$names[0];
        }
        glActiveTexture(GL_TEXTURE0);
        glBindTexture(GL_TEXTURE_2D, $id);
        glPixelStorei(GL_UNPACK_ALIGNMENT, 1);
        // desktop GL: SDL leaves the row length of its last texture upload set; ours are tightly packed (without
        // this, a texture made after SDL updated one of its own is read with the wrong rows). WebGL 1 and the phone
        // apps' OpenGL ES 2 have no row length.
        $unpack = [];
        if (!\JumPHP\Kernel\Platform::isWasm() && !\JumPHP\Kernel\Platform::isPhoneApp()) {
            foreach ([GL_UNPACK_ROW_LENGTH, GL_UNPACK_SKIP_ROWS, GL_UNPACK_SKIP_PIXELS] as $param) {
                $unpack[$param] = (int)glGetIntegerv($param);
                glPixelStorei($param, 0);
            }
        }
        glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA, $width, $height, 0, GL_RGBA, GL_UNSIGNED_BYTE, $rgba);
        foreach ($unpack as $param => $value) {
            glPixelStorei($param, $value);
        }
        // WebGL 1 repeats power-of-two textures only
        $pot = ($width & ($width - 1)) === 0 && ($height & ($height - 1)) === 0;
        $this->params($smooth, $repeat && $pot);
        $this->restore();
        return $id;
    }

    /** An empty RGBA texture + framebuffer (+ depth): render to texture. @return array{fb: int, tex: int, depth: int, w: int, h: int} */
    public function renderTarget(int $width, int $height, bool $depth = true, bool $smooth = false): array
    {
        $this->save();
        $names = [];
        glGenTextures(1, $names);
        $tex = (int)$names[0];
        glActiveTexture(GL_TEXTURE0);
        glBindTexture(GL_TEXTURE_2D, $tex);
        glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA, $width, $height, 0, GL_RGBA, GL_UNSIGNED_BYTE, null);
        $this->params($smooth, false);
        $names = [];
        glGenFramebuffers(1, $names);
        $fb = (int)$names[0];
        glBindFramebuffer(GL_FRAMEBUFFER, $fb);
        glFramebufferTexture2D(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $tex, 0);
        $rb = 0;
        if ($depth) {
            $names = [];
            glGenRenderbuffers(1, $names);
            $rb = (int)$names[0];
            glBindRenderbuffer(GL_RENDERBUFFER, $rb);
            glRenderbufferStorage(GL_RENDERBUFFER, GL_DEPTH_COMPONENT16, $width, $height);
            glFramebufferRenderbuffer(GL_FRAMEBUFFER, GL_DEPTH_ATTACHMENT, GL_RENDERBUFFER, $rb);
        }
        if (glCheckFramebufferStatus(GL_FRAMEBUFFER) !== GL_FRAMEBUFFER_COMPLETE) {
            $this->fail("render target {$width}x{$height} is not complete");
        }
        $this->restore();
        return ['fb' => $fb, 'tex' => $tex, 'depth' => $rb, 'w' => $width, 'h' => $height];
    }

    /** @param array{fb: int, tex: int, depth: int, w: int, h: int} $target */
    public function deleteTarget(array $target): void
    {
        glDeleteFramebuffers(1, [$target['fb']]);
        glDeleteTextures(1, [$target['tex']]);
        if ($target['depth'] > 0) {
            glDeleteRenderbuffers(1, [$target['depth']]);
        }
    }

    public function deleteBuffer(int $id): void
    {
        if ($id > 0 && $this->isAvailable()) {
            glDeleteBuffers(1, [$id]);
            unset($this->bufferSizes[$id], $this->indexBuffers[$id]);
        }
    }

    public function deleteTexture(int $id): void
    {
        if ($id > 0 && $this->isAvailable()) {
            glDeleteTextures(1, [$id]);
        }
    }

    public function deleteProgram(int $id): void
    {
        if ($id > 0 && $this->isAvailable()) {
            glDeleteProgram($id);
            unset($this->uniforms[$id], $this->attributes[$id]);
        }
    }

    // --- drawing helpers --------------------------------------------------------------------------

    /**
     * One draw call with a program (inside begin() / end(), or a WebGl layer). $options:
     *   attributes  name => [buffer, size, stride, offset] (stride / offset in floats; 0 = packed)
     *   uniforms    name => float | vecN | mat3 / mat4 (Mat4: 16 floats, column-major) | int (samplers)
     *   textures    sampler name => texture name (units 0, 1, 2...)
     *   indices     index buffer; count, first: vertices (count 0 = all)
     *   mode        triangles (default), lines, line_strip, line_loop, points, triangle_strip, triangle_fan
     *   depth       depth test (default true); cull 'back' | 'front' | null (default); blend (alpha, default false)
     * @param array<string, mixed> $options
     */
    public function draw(int $program, array $options): void
    {
        if ($program <= 0 || !isset($this->uniforms[$program])) {
            return;
        }
        glUseProgram($program);
        $vertices = 0;
        $enabled = [];
        foreach ($options['attributes'] ?? [] as $name => $a) {
            $loc = $this->attributes[$program][$name] ??= glGetAttribLocation($program, (string)$name);
            $buffer = (int)$a[0];
            if ($loc < 0 || !isset($this->bufferSizes[$buffer])) {
                continue;
            }
            [$size, $stride, $offset] = [(int)$a[1], (int)($a[2] ?? 0), (int)($a[3] ?? 0)];
            glBindBuffer(GL_ARRAY_BUFFER, $buffer);
            glEnableVertexAttribArray($loc);
            glVertexAttribPointer($loc, $size, GL_FLOAT, 0, $stride * 4, $offset * 4);
            $enabled[] = $loc;
            $vertices = $vertices ?: intdiv($this->bufferSizes[$buffer] - $offset, $stride ?: $size);
        }
        foreach ($options['uniforms'] ?? [] as $name => $value) {
            $this->uniform($program, (string)$name, $value);
        }
        $unit = 0;
        foreach ($options['textures'] ?? [] as $name => $texture) {
            glActiveTexture(GL_TEXTURE0 + $unit);
            glBindTexture(GL_TEXTURE_2D, (int)$texture);
            $this->uniform($program, (string)$name, $unit);
            $unit++;
        }
        ($options['depth'] ?? true) ? glEnable(GL_DEPTH_TEST) : glDisable(GL_DEPTH_TEST);
        $cull = $options['cull'] ?? null;
        if ($cull !== null) {
            glEnable(GL_CULL_FACE);
            glCullFace($cull === 'front' ? GL_FRONT : GL_BACK);
        } else {
            glDisable(GL_CULL_FACE);
        }
        if ($options['blend'] ?? false) {
            glEnable(GL_BLEND);
            glBlendFunc(GL_SRC_ALPHA, GL_ONE_MINUS_SRC_ALPHA);
        } else {
            glDisable(GL_BLEND);
        }
        $mode = self::mode((string)($options['mode'] ?? 'triangles'));
        $first = (int)($options['first'] ?? 0);
        if (isset($options['indices'])) {
            $ib = (int)$options['indices'];
            glBindBuffer(GL_ELEMENT_ARRAY_BUFFER, $ib);
            glDrawElements($mode, (int)($options['count'] ?? 0) ?: ($this->bufferSizes[$ib] ?? 0), GL_UNSIGNED_SHORT, null);
        } else {
            $count = (int)($options['count'] ?? 0) ?: $vertices - $first;
            if ($count > 0) {
                glDrawArrays($mode, $first, $count);
            }
        }
        foreach ($enabled as $loc) {
            glDisableVertexAttribArray($loc);
        }
        glActiveTexture(GL_TEXTURE0);
    }

    /** Sets a uniform of $program (current program) with the type GL reports. */
    public function uniform(int $program, string $name, float|int|bool|array $value): void
    {
        $u = $this->uniforms[$program][$name] ?? null;
        if ($u === null) {
            return;
        }
        [$loc, $type] = $u;
        $v = is_array($value) ? array_values($value) : [$value];
        switch ($type) {
            case GL_FLOAT: count($v) > 1 ? glUniform1fv($loc, self::floatList($v)) : glUniform1f($loc, (float)$v[0]); break;
            case GL_FLOAT_VEC2: glUniform2fv($loc, self::floatList($v)); break;
            case GL_FLOAT_VEC3: glUniform3fv($loc, self::floatList($v)); break;
            case GL_FLOAT_VEC4: glUniform4fv($loc, self::floatList($v)); break;
            case GL_FLOAT_MAT3: glUniformMatrix3fv($loc, intdiv(count($v), 9) ?: 1, false, self::floatList($v)); break;
            case GL_FLOAT_MAT4: glUniformMatrix4fv($loc, intdiv(count($v), 16) ?: 1, false, self::floatList($v)); break;
            case GL_INT_VEC2: case GL_BOOL_VEC2: glUniform2i($loc, (int)round($v[0]), (int)round($v[1] ?? 0)); break;
            case GL_INT_VEC3: case GL_BOOL_VEC3: glUniform3i($loc, (int)round($v[0]), (int)round($v[1] ?? 0), (int)round($v[2] ?? 0)); break;
            case GL_INT_VEC4: case GL_BOOL_VEC4: glUniform4i($loc, (int)round($v[0]), (int)round($v[1] ?? 0), (int)round($v[2] ?? 0), (int)round($v[3] ?? 0)); break;
            default: glUniform1i($loc, (int)round((float)$v[0]));      // int, bool, samplers
        }
    }

    /**
     * A full-target quad with $program (attribute a_pos, -1..1; QUAD_VERTEX: set u_flip to 1 when drawing into
     * an SDL target, which stores its top row first), $texture on unit 0 as u_frame: post-processing and
     * compositing passes.
     * @param callable(int): void|null $uniforms sets the extra uniforms (the program is current)
     */
    public function pass(int $program, int $texture, ?callable $uniforms = null): void
    {
        glUseProgram($program);
        $loc = $this->attributes[$program]['a_pos'] ??= glGetAttribLocation($program, 'a_pos');
        glBindBuffer(GL_ARRAY_BUFFER, $this->quad());
        glEnableVertexAttribArray($loc);
        glVertexAttribPointer($loc, 2, GL_FLOAT, 0, 0, 0);
        glDisable(GL_DEPTH_TEST);
        glDisable(GL_CULL_FACE);
        glActiveTexture(GL_TEXTURE0);
        glBindTexture(GL_TEXTURE_2D, $texture);
        $this->uniform($program, 'u_frame', 0);
        if ($uniforms !== null) {
            $uniforms($program);
        }
        glDrawArrays(GL_TRIANGLES, 0, 6);
        glDisableVertexAttribArray($loc);
    }

    // --- SDL's state ------------------------------------------------------------------------------

    /**
     * Before raw GL: SDL's queued 2D is drawn and its GL state saved (nested calls share the outermost save).
     * Every save() needs its restore().
     */
    public function save(): void
    {
        if ($this->depth++ > 0) {
            return;
        }
        $this->sdl->getRenderer()->flush();
        $this->saved = [
            'fb' => glGetIntegerv(GL_FRAMEBUFFER_BINDING),
            'program' => glGetIntegerv(GL_CURRENT_PROGRAM),
            'array' => glGetIntegerv(GL_ARRAY_BUFFER_BINDING),
            'viewport' => glGetIntegerv(GL_VIEWPORT),
            'active' => glGetIntegerv(GL_ACTIVE_TEXTURE),
            'clear' => glGetFloatv(GL_COLOR_CLEAR_VALUE),
            'blendFunc' => [glGetIntegerv(GL_BLEND_SRC_RGB), glGetIntegerv(GL_BLEND_DST_RGB), glGetIntegerv(GL_BLEND_SRC_ALPHA), glGetIntegerv(GL_BLEND_DST_ALPHA)],
            'caps' => [],
            'textures' => [],
        ];
        foreach ([GL_BLEND, GL_DEPTH_TEST, GL_CULL_FACE, GL_SCISSOR_TEST] as $cap) {
            $this->saved['caps'][$cap] = glIsEnabled($cap);
        }
        for ($unit = 0; $unit < 3; $unit++) {
            glActiveTexture(GL_TEXTURE0 + $unit);
            $this->saved['textures'][$unit] = glGetIntegerv(GL_TEXTURE_BINDING_2D);
        }
        // our vertex arrays never touch SDL's (the default one)
        glBindVertexArray($this->vao());
    }

    public function restore(): void
    {
        if (--$this->depth > 0 || $this->saved === null) {
            $this->depth = max(0, $this->depth);
            return;
        }
        $s = $this->saved;
        glBindVertexArray(0);
        glBindFramebuffer(GL_FRAMEBUFFER, $s['fb']);
        glUseProgram($s['program']);
        glBindBuffer(GL_ARRAY_BUFFER, $s['array']);
        [$x, $y, $w, $h] = $s['viewport'];
        glViewport($x, $y, $w, $h);
        foreach ($s['textures'] as $unit => $texture) {
            glActiveTexture(GL_TEXTURE0 + $unit);
            glBindTexture(GL_TEXTURE_2D, $texture);
        }
        glActiveTexture($s['active']);
        foreach ($s['caps'] as $cap => $on) {
            $on ? glEnable($cap) : glDisable($cap);
        }
        [$sr, $dr, $sa, $da] = $s['blendFunc'];
        glBlendFuncSeparate($sr, $dr, $sa, $da);
        [$r, $g, $b, $a] = $s['clear'];
        glClearColor($r, $g, $b, $a);
        glDepthMask(1);
        $this->saved = null;
    }

    /** Draws $texture over the current framebuffer (viewport set), optionally from an SDL target / into one. */
    public function blit(int $texture, bool $blend = false, bool $intoSdl = false): void
    {
        $program = $this->blitProgram();
        if ($blend) {
            glEnable(GL_BLEND);
            glBlendFuncSeparate(GL_SRC_ALPHA, GL_ONE_MINUS_SRC_ALPHA, GL_ONE, GL_ONE_MINUS_SRC_ALPHA);
        } else {
            glDisable(GL_BLEND);
        }
        glDisable(GL_SCISSOR_TEST);
        $swap = $intoSdl && $this->sdlSwapsRedBlue() ? 1.0 : 0.0;
        $this->pass($program, $texture, function (int $p) use ($intoSdl, $swap): void {
            $this->uniform($p, 'u_flip', $intoSdl ? 1.0 : 0.0);
            $this->uniform($p, 'u_swap', $swap);
        });
    }

    /** The vertex shader of full-target passes: v_uv 0..1 with y up; u_flip 1 when drawing into an SDL target. */
    public const QUAD_VERTEX = <<<'GLSL'
        attribute vec2 a_pos;
        uniform float u_flip;
        varying vec2 v_uv;
        void main() {
            v_uv = vec2(a_pos.x * 0.5 + 0.5, mix(a_pos.y * 0.5 + 0.5, 0.5 - a_pos.y * 0.5, u_flip));
            gl_Position = vec4(a_pos, 0.0, 1.0);
        }
        GLSL;

    // ---------------------------------------------------------------------------------------------

    /**
     * `#version 100` first when the source has no #version (blank lines and comments before it are kept).
     * $legacy: a desktop GL that knows no GLSL ES (macOS: OpenGL 2.1), the same shader as GLSL 1.20 (no precision).
     */
    public static function versioned(string $source, bool $legacy = false): string
    {
        $source = preg_match('/^\s*#version/m', $source) ? $source : "#version 100\n" . $source;
        if ($legacy) {
            $source = (string)preg_replace('/^([ \t]*)#version 100\b[^\n]*/m', "\$1#version 120\n#define lowp\n#define mediump\n#define highp", $source, 1);
            $source = (string)preg_replace('/^[ \t]*precision\s+(lowp|mediump|highp)\s+\w+\s*;[ \t]*$/m', '', $source);
        }
        return $source;
    }

    /** macOS' OpenGL (2.1 with SDL's renderer) compiles GLSL 1.20, not GLSL ES 1.00 as Mesa, Android, iOS and WebGL do. */
    private function legacyGlsl(): bool
    {
        return $this->legacyGlsl ??= PHP_OS_FAMILY === 'Darwin' && !str_contains((string)glGetString(GL_VERSION), 'OpenGL ES');
    }

    /** @param float[] $data */
    public static function floats(array $data): string
    {
        return $data === [] ? '' : pack('g*', ...$data);
    }

    private function createBuffer(int $kind, array|string $data, bool $dynamic): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }
        $bytes = is_string($data) ? $data : self::floats($data);
        $this->save();
        $names = [];
        glGenBuffers(1, $names);
        $id = (int)$names[0];
        glBindBuffer($kind, $id);
        glBufferData($kind, strlen($bytes), $bytes === '' ? null : $bytes, $dynamic ? GL_DYNAMIC_DRAW : GL_STATIC_DRAW);
        $this->bufferSizes[$id] = intdiv(strlen($bytes), 4);
        $this->restore();
        return $id;
    }

    private function params(bool $smooth, bool $repeat): void
    {
        $filter = $smooth ? GL_LINEAR : GL_NEAREST;
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MIN_FILTER, $filter);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MAG_FILTER, $filter);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_S, $repeat ? GL_REPEAT : GL_CLAMP_TO_EDGE);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_T, $repeat ? GL_REPEAT : GL_CLAMP_TO_EDGE);
    }

    /** @return array{fb: int, tex: int, depth: int, w: int, h: int} */
    private function target(int $w, int $h): array
    {
        if ($this->target !== null && ($this->target['w'] !== $w || $this->target['h'] !== $h)) {
            $this->deleteTarget($this->target);
            $this->target = null;
        }
        return $this->target ??= $this->renderTarget($w, $h, true, $this->supersample > 1);
    }

    private function vao(): int
    {
        if ($this->vao === 0) {
            $names = [];
            glGenVertexArrays(1, $names);
            $this->vao = (int)$names[0];
        }
        return $this->vao;
    }

    private function quad(): int
    {
        if ($this->quad === 0) {
            $this->quad = $this->buffer([-1, -1, 1, -1, 1, 1, -1, -1, 1, 1, -1, 1]);
        }
        return $this->quad;
    }

    private function blitProgram(): int
    {
        return $this->blit = $this->blit ?: $this->program(self::QUAD_VERTEX,
            "precision mediump float;\nuniform sampler2D u_frame;\nuniform float u_swap;\nvarying vec2 v_uv;\n"
            . "void main() { vec4 c = texture2D(u_frame, v_uv); gl_FragColor = u_swap > 0.5 ? c.bgra : c; }");
    }

    private function fail(string $message): void
    {
        \JumPHP\Kernel\Application::stderr("[opengl] $message\n");
        $this->emit('error', [$message]);
    }

    private static function mode(string $mode): int
    {
        return match ($mode) {
            'points' => GL_POINTS,
            'lines' => GL_LINES,
            'line_strip' => GL_LINE_STRIP,
            'line_loop' => GL_LINE_LOOP,
            'triangle_strip' => GL_TRIANGLE_STRIP,
            'triangle_fan' => GL_TRIANGLE_FAN,
            'triangles' => GL_TRIANGLES,
            default => throw new \InvalidArgumentException("draw: unknown mode '$mode'"),
        };
    }

    /** @param array<int, mixed> $v @return float[] */
    private static function floatList(array $v): array
    {
        return array_map('floatval', $v);
    }
}
