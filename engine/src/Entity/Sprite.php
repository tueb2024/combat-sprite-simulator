<?php

namespace JumPHP\Entity;

use JumPHP\Renderer\Renderer;
use JumPHP\Renderer\Sdl;

/**
 * Animated sprite from a sprite sheet. The class using it defines $animations:
 *
 *   protected array $animations = [
 *       'idleRight' => ['coords' => [['x' => 0, 'y' => 0, 'w' => 16, 'h' => 16]]],
 *       'walkRight' => ['coords' => [[...], [...], [...]], 'duration' => 0.08],
 *       'walkLeft'  => 'reverseWalkRight',          // same frames, drawn flipped
 *       'die'       => ['coords' => [...], 'loop' => false],
 *   ];
 *
 * and calls initSprite($sdl, 'sheetTextureName') once (constructor). 'duration' (seconds per frame) and
 * 'loop' are optional per animation; 'offset' => [dx, dy] shifts the drawing from the entity position.
 */
trait Sprite
{
    protected string $currentAnimation = '';
    protected int $frameIndex = 0;
    protected float $frameTimer = 0;
    protected float $frameDuration = 0.1;
    protected bool $loop = true;
    protected ?string $tilesetName = null;
    protected ?Sdl $sdl = null;
    protected bool $animationDone = false;

    public function initSprite(Sdl $sdl, ?string $tilesetName = null, ?string $firstAnimation = null): void
    {
        $this->sdl = $sdl;
        $this->tilesetName = $tilesetName ?? $this->tilesetName;
        if (!property_exists($this, 'animations') || $this->animations === []) {
            throw new \InvalidArgumentException(static::class . ' uses Sprite but defines no $animations.');
        }
        foreach ($this->animations as $name => $frames) {
            if (is_string($frames) && str_starts_with($frames, 'reverse')) {
                // 'reverseWalkRight' => the frames of 'walkRight', flipped
                $source = lcfirst(substr($frames, 7));
                if (!isset($this->animations[$source]) || !is_array($this->animations[$source])) {
                    throw new \InvalidArgumentException("Animation '$name': '$source' not found.");
                }
                $this->animations[$name] = $this->animations[$source];
                $this->animations[$name]['flags']['flip'] = true;
            }
        }
        $this->setAnimation($firstAnimation ?? (string)array_key_first($this->animations));
    }

    public function hasAnimation(string $animation): bool
    {
        return isset($this->animations[$animation]);
    }

    public function setAnimation(string $animation): void
    {
        if (!isset($this->animations[$animation])) {
            throw new \InvalidArgumentException("Animation '$animation' is not valid.");
        }
        if ($this->currentAnimation !== $animation) {
            $this->currentAnimation = $animation;
            $this->frameIndex = 0;
            $this->frameTimer = 0;
            $this->animationDone = false;
            $this->loop = $this->animations[$animation]['loop'] ?? true;
        }
    }

    public function getAnimation(): string
    {
        return $this->currentAnimation;
    }

    /** A non-looping animation reached its last frame. */
    public function isAnimationDone(): bool
    {
        return $this->animationDone;
    }

    public function getCurrentAnimation(): array
    {
        return $this->animations[$this->currentAnimation];
    }

    /** @return array{x: int, y: int, w: int, h: int} */
    public function getCurrentFrame(): array
    {
        return $this->getCurrentAnimation()['coords'][$this->frameIndex];
    }

    public function update(float $deltaTime): void
    {
        $animation = $this->animations[$this->currentAnimation];
        $duration = $animation['duration'] ?? $this->frameDuration;
        $count = count($animation['coords']);
        $this->frameTimer += $deltaTime;
        while ($this->frameTimer >= $duration && $duration > 0) {
            $this->frameTimer -= $duration;
            $this->frameIndex++;
            if ($this->frameIndex >= $count) {
                if ($this->loop) {
                    $this->frameIndex = 0;
                } else {
                    $this->frameIndex = $count - 1;
                    $this->animationDone = true;
                    break;
                }
            }
        }
    }

    public function getTexture(): array
    {
        return $this->sdl->getTexture($this->tilesetName);
    }

    /** Draws the current frame with its top-left at ($x, $y) (view pixels). */
    public function drawAt(Renderer $r, float $x, float $y, bool $forceFlip = false, float $scale = 1.0): void
    {
        $frame = $this->getCurrentFrame();
        $animation = $this->getCurrentAnimation();
        $flip = $forceFlip || !empty($animation['flags']['flip']);
        [$dx, $dy] = $animation['offset'] ?? [0, 0];
        $r->copy($this->getTexture()['texture'], [$frame['x'], $frame['y'], $frame['w'], $frame['h']],
            $x + $dx, $y + $dy, $frame['w'] * $scale, $frame['h'] * $scale, $flip);
    }

    /** Raw SDL variant: draws into $destRect. */
    public function draw(\SDL_Rect $destRect, bool $forceFlip = false): void
    {
        $frame = $this->getCurrentFrame();
        $animation = $this->getCurrentAnimation();
        $flip = ($forceFlip || !empty($animation['flags']['flip'])) ? \SDL_FLIP_HORIZONTAL : \SDL_FLIP_NONE;
        \SDL_RenderCopyEx($this->sdl->getRenderer()->getRenderer(), $this->getTexture()['texture'],
            new \SDL_Rect($frame['x'], $frame['y'], $frame['w'], $frame['h']), $destRect, 0, null, $flip);
    }
}
