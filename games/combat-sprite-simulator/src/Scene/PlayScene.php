<?php

namespace App\Scene;

use App\Entity\Hitbox;
use App\Entity\EnemyProfile;
use App\Geo\AddressLookup;
use App\Geo\AircraftShadow;
use App\Geo\MissionTerrain;
use App\Geo\OrthoTileManager;
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
    private bool $paused = false;
    private bool $demo = false;
    private array $enemies = [];
    private array $shots = [];
    private array $sparks = [];
    private int $spawnCount = 0;
    private $hurricaneTex = null;
    private $junkersTex = null;
    private $bf109Tex = null;
    private $ju87Tex = null;
    private $groundTex = null;

    private ?MissionTerrain $missionTerrain = null;
    private ?OrthoTileManager $missionTiles = null;
    private string $missionAddress = '';
    private string $geoState = 'none';
    private string $geoMessage = '';
    private float $geoElapsed = 0.0;
    private int $geoGeneration = 0;
    private $playerShadowTex = null;
    private $bf109ShadowTex = null;
    private $ju87ShadowTex = null;
    private $bossShadowTex = null;

    public function enter(array $params = []): void
    {
        // Cancel outstanding geocoding and tile responses from previous missions.
        ++$this->geoGeneration;
        $this->missionTiles?->stop();
        $this->missionTiles = null;
        $this->missionTerrain = null;
        $this->geoElapsed = 0;
        $this->missionAddress = trim((string) ($params['address'] ?? ''));
        $this->geoState = $this->missionAddress === '' ? 'none' : 'search';
        $this->geoMessage = '';
        $this->releaseShadows();
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
        $this->paused = false;
        $this->demo = !empty($params['demo']);
        $this->enemies = $this->shots = $this->sparks = [];
        $this->spawnCount = 0;
        if (!empty($params['boss'])) {
            $this->phase = 'boss';
            $this->bossY = 85;
            $this->bossHp = (int)($params['bossHp'] ?? 100);
        }
        // Flight mode can pre-load the Hurricane before the combat scene.
        // Load each asset independently so the other planes are never skipped.
        foreach ([
            'combat-hurricane-ww2' => 'assets/sprites/hurricane-mk1-atlas.png',
            'combat-junkers' => 'assets/sprites/junkers-atlas.png',
            'combat-ground' => 'assets/tiles/ign-beauce-2023.png',
            'combat-bf109' => 'assets/sprites/bf109-e4-atlas.png',
            'combat-ju87' => 'assets/sprites/ju87-b2-atlas.png',
        ] as $name => $path) {
            if (!$this->sdl()->hasTexture($name)) {
                $this->sdl()->loadTexture($name, $path);
            }
        }
        $this->hurricaneTex = $this->sdl()->getTexture('combat-hurricane-ww2')['texture'];
        $this->junkersTex = $this->sdl()->getTexture('combat-junkers')['texture'];
        $this->bf109Tex = $this->sdl()->getTexture('combat-bf109')['texture'];
        $this->ju87Tex = $this->sdl()->getTexture('combat-ju87')['texture'];
        $this->groundTex = $this->sdl()->getTexture('combat-ground')['texture'];

        if ($this->missionAddress !== '') {
            if (isset($params['latitude'], $params['longitude'])
                && is_numeric($params['latitude']) && is_numeric($params['longitude'])) {
                // Pre-resolved coordinates: used on restart and in deterministic tests.
                $this->startAtCoordinates(
                    (float) $params['latitude'], (float) $params['longitude'],
                    (string) ($params['label'] ?? $this->missionAddress)
                );
            } else {
                $generation = $this->geoGeneration;
                (new AddressLookup($this->engine->http()))->search(
                    $this->missionAddress,
                    function (float $lat, float $lon, string $label) use ($generation): void {
                        if ($generation === $this->geoGeneration) {
                            $this->startAtCoordinates($lat, $lon, $label);
                        }
                    },
                    function (string $message) use ($generation): void {
                        if ($generation === $this->geoGeneration) {
                            $this->geoState = 'error';
                            $this->geoMessage = $message;
                        }
                    }
                );
            }
        }
    }

    public function exit(): void
    {
        ++$this->geoGeneration;
        $this->missionTiles?->stop();
        $this->missionTiles = null;
        $this->releaseShadows();
    }

    private function startAtCoordinates(float $lat, float $lon, string $label): void
    {
        if ($lat < 41.0 || $lat > 51.5 || $lon < -6.0 || $lon > 10.0) {
            $this->geoState = 'error';
            $this->geoMessage = 'Adresse hors de France metropolitaine';
            return;
        }
        $this->missionTiles?->stop();
        $this->missionTerrain = new MissionTerrain($lat, $lon, $label);
        $this->missionTiles = new OrthoTileManager($this->engine->http(), $this->sdl());
        $this->geoElapsed = 0.0;
        $this->geoState = 'loading';
        $this->geoMessage = 'Chargement des photographies IGN';
        $this->createShadows();
    }

    private function createShadows(): void
    {
        $this->releaseShadows();
        $this->playerShadowTex = AircraftShadow::create($this->sdl(),
            $this->engine->config->path('assets/sprites/hurricane-mk1-atlas.png'),
            512, 64, 'mission-shadow-hurricane');
        $this->bf109ShadowTex = AircraftShadow::create($this->sdl(),
            $this->engine->config->path('assets/sprites/bf109-e4-atlas.png'),
            512, 64, 'mission-shadow-bf109');
        $this->ju87ShadowTex = AircraftShadow::create($this->sdl(),
            $this->engine->config->path('assets/sprites/ju87-b2-atlas.png'),
            512, 64, 'mission-shadow-ju87');
        $this->bossShadowTex = AircraftShadow::create($this->sdl(),
            $this->engine->config->path('assets/sprites/junkers-atlas.png'),
            512, 64, 'mission-shadow-boss', 0, 0, 256);
    }

    private function releaseShadows(): void
    {
        foreach (['mission-shadow-hurricane', 'mission-shadow-bf109',
            'mission-shadow-ju87', 'mission-shadow-boss'] as $name) {
            if ($this->sdl()->hasTexture($name)) {
                $this->sdl()->releaseTexture($name);
            }
        }
        $this->playerShadowTex = $this->bf109ShadowTex = null;
        $this->ju87ShadowTex = $this->bossShadowTex = null;
    }

    /** Refresh tile requests while the level scrolls through the same real-world area. */
    private function updateTerrainTiles(): void
    {
        if ($this->missionTerrain === null || $this->missionTiles === null) {
            return;
        }
        [$cameraX, $cameraY] = $this->missionTerrain->camera($this->scroll);
        $this->missionTiles->tick($cameraX, $cameraY, $this->width(),
            $this->height(), $this->geoElapsed);
    }

    public function update(float $dt): void
    {
        if ($this->actions()->isPressed('pause')) $this->paused = !$this->paused;
        if ($this->paused) return;
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
                $lane = ($this->spawnCount * 3 + 1) % 5;
                $type = ['bf109', 'ju87', 'bf109', 'bf109', 'ju87'][$this->spawnCount % 5];
                $this->enemies[] = EnemyProfile::spawn($type, 40 + $lane * 60);
                $this->spawnCount++;
                $this->spawnDelay = 1.15;
            }
            if ($this->time >= 50) {
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
            EnemyProfile::step($enemy, $dt);
            if ($enemy['fireDelay'] <= 0 && $enemy['y'] > 24 && $enemy['y'] < 310) {
                $this->shots[] = ['x' => $enemy['x'], 'y' => $enemy['y'] + $enemy['hitHeight'] / 2,
                    'vy' => $enemy['type'] === 'bf109' ? 145 : 110, 'friendly' => false];
                $enemy['fireDelay'] = $enemy['type'] === 'bf109' ? 1.8 : 2.8;
            }
            if ($enemy['y'] > 500) unset($this->enemies[$i]);
            else {
                [$ex, $ey, $ew, $eh] = EnemyProfile::hitbox($enemy);
                if ($this->invulnerable <= 0 && Hitbox::overlap($ex, $ey, $ew, $eh, $this->x - 15, $this->y - 15, 30, 30)) $this->damage();
            }
        }
        unset($enemy);
        foreach ($this->shots as $i => &$shot) {
            $shot['y'] += $shot['vy'] * $dt;
            if ($shot['y'] < -10 || $shot['y'] > 490) { unset($this->shots[$i]); continue; }
            if (!$shot['friendly']) {
                if ($this->invulnerable <= 0 && Hitbox::overlap($shot['x'] - 3, $shot['y'] - 5, 6, 10, $this->x - 15, $this->y - 15, 30, 30)) {
                    unset($this->shots[$i]);
                    $this->damage();
                }
                continue;
            }
            foreach ($this->enemies as $j => &$enemy) {
                [$ex, $ey, $ew, $eh] = EnemyProfile::hitbox($enemy);
                if (!Hitbox::overlap($shot['x'] - 2, $shot['y'] - 7, 4, 14, $ex, $ey, $ew, $eh)) continue;
                unset($this->shots[$i]);
                $this->burst($shot['x'], $shot['y']);
                if (--$enemy['hp'] <= 0) { $this->score += $enemy['type'] === 'bf109' ? 100 : 250; unset($this->enemies[$j]); }
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
        $offset = fmod($this->scroll, 960);
        for ($i = -1; $i < 2; $i++) {
            $y = $offset + $i * 960;
            $r->copy($this->groundTex, null, 0, $y, 320, 480);
            $r->copy($this->groundTex, null, 0, $y + 480, 320, 480, false, true);
        }
        $r->fillRect(0, 0, 320, 480, [10, 28, 23, 70]);
        foreach ($this->enemies as $e) {
            $texture = $e['type'] === 'bf109' ? $this->bf109Tex : $this->ju87Tex;
            $r->copy($texture, EnemyProfile::frame($e), $e['x'] - $e['width'] / 2,
                $e['y'] - $e['height'] / 2, $e['width'], $e['height']);
        }
        if ($this->phase === 'boss') {
            $damageFrame = $this->bossHp < 50 ? 1 : 0;
            $r->copy($this->junkersTex, [$damageFrame * 512, 0, 512, 256], $this->bossX - 80, $this->bossY - 40, 160, 80);
        }
        foreach ($this->shots as $s) $r->fillRect($s['x'] - 2, $s['y'] - 6, 4, 12, $s['friendly'] ? [255, 219, 91] : [247, 105, 64]);
        if ($this->invulnerable <= 0 || (int)($this->time * 10) % 2 === 0) {
            $bankFrame = $this->demo ? 1 : ($this->actions()->isHeld('left') ? 0 : ($this->actions()->isHeld('right') ? 2 : 1));
            $propFrame = ((int)($this->time * 18)) % 3;
            $r->copy($this->hurricaneTex, [$propFrame * 512, $bankFrame * 512, 512, 512], $this->x - 30, $this->y - 30, 60, 60);
        }
        foreach ($this->sparks as $s) $r->fillRect($s['x'], $s['y'], 4, 4, [255, 177, 63]);
        $r->fillRect(0, 0, 320, 31, [20, 30, 34, 225]);
        $this->font()->draw($r, 'LIVES ' . $this->lives . '   SCORE ' . $this->score, 7, 8, 1, [255, 248, 215]);
        $this->font()->draw($r, '(C) IGN 2023', 315, 466, 1, [255, 248, 215], 'right', [0, 0, 0]);
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
        if ($this->paused) {
            $r->fillRect(30, 206, 260, 65, [18, 28, 32, 235]);
            $this->font()->draw($r, 'PAUSED', 160, 221, 2, [255, 230, 180], 'center');
            $this->font()->draw($r, 'ESC TO RESUME', 160, 253, 1, [220, 220, 210], 'center');
        }
    }
}
