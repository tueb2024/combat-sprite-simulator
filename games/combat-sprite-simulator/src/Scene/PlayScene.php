<?php

namespace App\Scene;

use App\Entity\Hitbox;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

final class PlayScene extends AbstractScene
{
    private float $time = 0;
    private float $scroll = 0;
    private float $x = 160;
    private float $y = 405;
    private float $fireDelay = 0;
    private float $spawnDelay = 0;
    private float $bossDelay = 0;
    private float $invulnerable = 0;
    private float $bossX = 160;
    private float $bossY = -90;
    private int $lives = 3;
    private int $score = 0;
    private int $bossHp = 100;
    private string $phase = 'wave';
    private bool $demo = false;
    private array $enemies = [];
    private array $shots = [];
    private array $sparks = [];
    private $hurricaneTex = null;
    private $junkersTex = null;

    public function enter(array $params = []): void
    {
        $this->time = $this->scroll = $this->fireDelay = $this->spawnDelay = $this->bossDelay = 0;
        $this->x = 160;
        $this->y = 405;
        $this->bossX = 160;
        $this->bossY = -90;
        $this->invulnerable = 2;
        $this->lives = 3;
        $this->score = 0;
        $this->bossHp = 100;
        $this->phase = 'wave';
        $this->demo = !empty($params['demo']);
        $this->enemies = $this->shots = $this->sparks = [];
        if (!empty($params['boss'])) {
            $this->phase = 'boss';
            $this->bossY = 85;
            $this->bossHp = (int)($params['bossHp'] ?? 100);
        }
        if (!$this->sdl()->hasTexture('combat-hurricane')) {
            $this->sdl()->loadTexture('combat-hurricane', 'assets/sprites/hurricane-atlas.png');
            $this->sdl()->loadTexture('combat-junkers', 'assets/sprites/junkers-atlas.png');
        }
        $this->hurricaneTex = $this->sdl()->getTexture('combat-hurricane')['texture'];
        $this->junkersTex = $this->sdl()->getTexture('combat-junkers')['texture'];
    }

    public function update(float $dt): void
    {
        if ($this->phase === 'victory' || $this->phase === 'defeat') {
            if ($this->actions()->isPressed('confirm') || $this->actions()->isPressed('fire')) $this->enter();
            return;
        }
        $this->time += $dt;
        $this->scroll += $dt * 75;
        $this->invulnerable = max(0, $this->invulnerable - $dt);
        $dx = $this->demo ? sin($this->time * 1.4) * 0.6 : $this->actions()->axis('left', 'right');
        $dy = $this->demo ? 0 : $this->actions()->axis('up', 'down');
        $this->x = max(28, min(292, $this->x + $dx * 175 * $dt));
        $this->y = max(100, min(440, $this->y + $dy * 175 * $dt));
        $this->fireDelay -= $dt;
        if (($this->demo || $this->actions()->isHeld('fire')) && $this->fireDelay <= 0) {
            foreach ([-10, 10] as $offset) $this->shots[] = ['x' => $this->x + $offset, 'y' => $this->y - 22, 'vy' => -370, 'friendly' => true];
            $this->fireDelay = 0.14;
        }
        if ($this->phase === 'wave') {
            $this->spawnDelay -= $dt;
            if ($this->spawnDelay <= 0) {
                $lane = ((int)floor($this->time * 5)) % 5;
                $this->enemies[] = ['x' => 40 + $lane * 60, 'y' => -25, 'hp' => 3];
                $this->spawnDelay = 1.2;
            }
            if ($this->time >= 30) {
                $this->phase = 'boss';
                $this->enemies = [];
            }
        } else {
            $this->bossY = min(85, $this->bossY + 45 * $dt);
            $this->bossX = 160 + sin($this->time * 0.8) * 60;
            $this->bossDelay -= $dt;
            if ($this->bossY >= 80 && $this->bossDelay <= 0) {
                foreach ([-36, 0, 36] as $offset) $this->shots[] = ['x' => $this->bossX + $offset, 'y' => $this->bossY + 34, 'vy' => 160, 'friendly' => false];
                $this->bossDelay = 1.5;
            }
        }
        foreach ($this->enemies as $i => &$enemy) {
            $enemy['y'] += 100 * $dt;
            if ($enemy['y'] > 500) unset($this->enemies[$i]);
            elseif ($this->invulnerable <= 0 && Hitbox::overlap($enemy['x'] - 14, $enemy['y'] - 12, 28, 24, $this->x - 13, $this->y - 18, 26, 36)) $this->damage();
        }
        unset($enemy);
        foreach ($this->shots as $i => &$shot) {
            $shot['y'] += $shot['vy'] * $dt;
            if ($shot['y'] < -10 || $shot['y'] > 490) { unset($this->shots[$i]); continue; }
            if (!$shot['friendly']) {
                if ($this->invulnerable <= 0 && Hitbox::overlap($shot['x'] - 3, $shot['y'] - 5, 6, 10, $this->x - 13, $this->y - 18, 26, 36)) {
                    unset($this->shots[$i]);
                    $this->damage();
                }
                continue;
            }
            foreach ($this->enemies as $j => &$enemy) {
                if (!Hitbox::overlap($shot['x'] - 2, $shot['y'] - 7, 4, 14, $enemy['x'] - 14, $enemy['y'] - 12, 28, 24)) continue;
                unset($this->shots[$i]);
                $this->burst($shot['x'], $shot['y']);
                if (--$enemy['hp'] <= 0) { unset($this->enemies[$j]); $this->score += 100; }
                break;
            }
            unset($enemy);
            if ($this->phase === 'boss' && isset($this->shots[$i]) && $this->bossY >= 70 && Hitbox::overlap($shot['x'] - 2, $shot['y'] - 7, 4, 14, $this->bossX - 75, $this->bossY - 35, 150, 70)) {
                unset($this->shots[$i]);
                $this->burst($shot['x'], $shot['y']);
                if (--$this->bossHp <= 0) { $this->phase = 'victory'; $this->score += 2000; $this->burst($this->bossX, $this->bossY); }
            }
        }
        unset($shot);
        foreach ($this->sparks as $i => &$spark) {
            $spark['life'] -= $dt;
            $spark['x'] += $spark['vx'] * $dt;
            $spark['y'] += $spark['vy'] * $dt;
            if ($spark['life'] <= 0) unset($this->sparks[$i]);
        }
        unset($spark);
    }

    private function damage(): void
    {
        $this->burst($this->x, $this->y);
        $this->invulnerable = 2;
        if (--$this->lives <= 0) $this->phase = 'defeat';
    }

    private function burst(float $x, float $y): void
    {
        for ($i = 0; $i < 8; $i++) {
            $a = $i * 0.785;
            $this->sparks[] = ['x' => $x, 'y' => $y, 'vx' => cos($a) * 60, 'vy' => sin($a) * 60, 'life' => 0.45];
        }
    }

    public function render(Renderer $r): void
    {
        $r->clear([38, 63, 53]);
        for ($i = -1; $i < 8; $i++) {
            $y = $i * 80 + fmod($this->scroll, 80);
            $r->fillRect(0, $y, 320, 28, [48, 77, 58]);
            $r->fillRect(15, $y + 26, 75, 24, [70, 94, 64]);
            $r->fillRect(210, $y + 44, 100, 20, [58, 83, 61]);
            $r->drawLine(0, $y + 7, 320, $y + 53, [81, 96, 67]);
        }
        foreach ($this->enemies as $e) {
            $r->fillRect($e['x'] - 14, $e['y'] - 4, 28, 9, [148, 138, 110]);
            $r->fillRect($e['x'] - 4, $e['y'] - 12, 8, 25, [188, 175, 138]);
            $r->fillRect($e['x'] - 10, $e['y'] + 8, 20, 4, [105, 103, 89]);
        }
        if ($this->phase === 'boss') {
            $damageFrame = $this->bossHp < 50 ? 1 : 0;
            $r->copy($this->junkersTex, [$damageFrame * 512, 0, 512, 256], $this->bossX - 80, $this->bossY - 40, 160, 80);
        }
        foreach ($this->shots as $s) $r->fillRect($s['x'] - 2, $s['y'] - 6, 4, 12, $s['friendly'] ? [255, 219, 91] : [247, 105, 64]);
        if ($this->invulnerable <= 0 || (int)($this->time * 10) % 2 === 0) {
            $bankFrame = $this->demo ? 1 : ($this->actions()->isHeld('left') ? 0 : ($this->actions()->isHeld('right') ? 2 : 1));
            $propFrame = ((int)($this->time * 18)) % 3;
            $r->copy($this->hurricaneTex, [$propFrame * 256, $bankFrame * 256, 256, 256], $this->x - 28, $this->y - 28, 56, 56);
        }
        foreach ($this->sparks as $s) $r->fillRect($s['x'], $s['y'], 4, 4, [255, 177, 63]);
        $r->fillRect(0, 0, 320, 31, [20, 30, 34, 225]);
        $this->font()->draw($r, 'LIVES ' . $this->lives . '   SCORE ' . $this->score, 7, 8, 1, [255, 248, 215]);
        if ($this->phase === 'boss') {
            $r->fillRect(55, 40, 210, 9, [27, 31, 31]);
            $r->fillRect(57, 42, 206 * $this->bossHp / 100, 5, [217, 79, 58]);
            $this->font()->draw($r, 'JUNKERS G 38', 160, 54, 1, [255, 248, 215], 'center');
        }
        if ($this->phase === 'victory' || $this->phase === 'defeat') {
            $r->fillRect(20, 190, 280, 90, [18, 28, 32, 235]);
            $this->font()->draw($r, $this->phase === 'victory' ? 'VICTORY' : 'MISSION FAILED', 160, 212, 2, [255, 230, 180], 'center');
            $this->font()->draw($r, 'ENTER OR SPACE TO RESTART', 160, 253, 1, [220, 220, 210], 'center');
        }
    }
}
