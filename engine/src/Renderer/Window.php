<?php

namespace JumPHP\Renderer;

/**
 * The SDL window. In the browser it is the page's <canvas>: SDL takes its CSS size (the canvas must have
 * an explicit CSS size and no CSS zoom, see engine/web/index.html).
 */
class Window
{
    private $window;
    private bool $fullscreen;

    private static ?Window $instance = null;

    public function __construct(private int $width, private int $height, private string $title = '', bool $fullscreen = false)
    {
        self::$instance = $this;
        $this->fullscreen = $fullscreen;

        $flags = \SDL_WINDOW_SHOWN | \SDL_WINDOW_RESIZABLE;
        // canvas at the screen resolution (devicePixelRatio 2-3 on phones): sharp pixels
        $flags |= \defined('SDL_WINDOW_ALLOW_HIGHDPI') ? \SDL_WINDOW_ALLOW_HIGHDPI : 0x2000;
        if ($fullscreen) {
            $flags |= \SDL_WINDOW_FULLSCREEN_DESKTOP;
        }

        $this->window = \SDL_CreateWindow($title, \SDL_WINDOWPOS_CENTERED, \SDL_WINDOWPOS_CENTERED, $width, $height, $flags);
        if (!$this->window) {
            throw new \RuntimeException('SDL_CreateWindow failed: ' . \SDL_GetError());
        }
    }

    public static function getInstance(): ?self
    {
        return self::$instance;
    }

    public function getWindow()
    {
        return $this->window;
    }

    public function isFullscreen(): bool
    {
        return $this->fullscreen;
    }

    /** Desktop fullscreen: no video mode change, the view is letterboxed into the screen. */
    public function setFullscreen(bool $on): void
    {
        $this->fullscreen = $on;
        \SDL_SetWindowFullscreen($this->window, $on ? \SDL_WINDOW_FULLSCREEN_DESKTOP : 0);
    }

    public function toggleFullscreen(): void
    {
        $this->setFullscreen(!$this->fullscreen);
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
        \SDL_SetWindowTitle($this->window, $title);
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function destroy(): void
    {
        if ($this->window) {
            \SDL_DestroyWindow($this->window);
            $this->window = null;
        }
    }
}
