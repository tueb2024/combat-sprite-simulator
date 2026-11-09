<?php

namespace App\Scene;

use App\Entity\Coin;
use App\Entity\Player;
use JumPHP\Audio\Synth;
use JumPHP\Entity\EntityManager;
use JumPHP\Physics\CollisionSystem;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;
use JumPHP\Scene\Camera;
use JumPHP\Tile\ArrayLoader;
use JumPHP\Tile\CollisionMask;
use JumPHP\Tile\TileMap;
use JumPHP\Tile\TileMapRenderer;

/**
 * A small level: tiles with slopes and a jump-through platform, a player, coins, a camera.
 * Replace the rows by a Tiled map when the game grows: TiledJsonLoader::load($this->engine->config->path('assets/maps/level1.tmj')).
 */
final class PlayScene extends AbstractScene
{
    private const LEVEL = [
        '..................................................................',
        '..................................................................',
        '..........................C.C.C...................................',
        '.........................=======...............C.C................',
        '..........C.C.........................................######......',
        '........=======..........................C.........##.........C..',
        '.....................................====.............../...######',
        '...P...........................C.....................//#..........',
        '#########............C......######...............///####..........',
        '#########..........#####...########.........C..////#####...C......',
        '#########\\.......#######../########\\......########.#####..####...',
        '##########\\.....########./#########\\.....#########.#####..####...',
        '###########################################################.....##',
    ];

    private TileMap $map;
    private Player $player;
    private Camera $camera;
    private int $coins = 0;
    private bool $demo = false;
    private float $time = 0.0;

    public function __construct(
        private TileMapRenderer $tiles,
        private CollisionSystem $collisions,
        private EntityManager $entities,
    ) {
    }

    public function enter(array $params = []): void
    {
        $this->demo = !empty($params['demo']);
        $this->coins = 0;
        $this->time = 0.0;

        // '#' solid, '=' jump-through, '/' and '\' slopes; 'P' start, 'C' coins
        $this->map = ArrayLoader::fromRows(self::LEVEL, legend: ['#' => 1, '=' => 2, '/' => 3, '\\' => 4],
            objects: ['P' => 'start', 'C' => 'coin'], tileWidth: 16, tileset: 'generated:tiles');
        $tileset = $this->map->tilesets[0];
        $tileset->masks[1] = CollisionMask::oneWay();
        $tileset->masks[2] = CollisionMask::slope(16, 16, 1, 16);
        $tileset->masks[3] = CollisionMask::slope(16, 16, 16, 1);
        $this->makeTileset();

        $this->entities->clear();
        $this->entities->register('coin', Coin::class);
        $this->entities->spawnFromMap($this->map);

        $start = $this->map->object('start');
        $this->player = new Player($this->sdl(), $start['x'], $start['y']);
        $this->camera = new Camera($this->width(), $this->height());
        $this->camera->setBounds(0, 0, $this->map->pixelWidth(), $this->map->pixelHeight());
        $this->camera->follow($this->player, 0.15, 0.3);

        $this->collisions->removeAllListeners();
        $this->collisions->on('fell', fn () => $this->respawn());

        if (!$this->sound()->hasSfx('jump')) {
            $this->sound()->synth('jump', Synth::sweep(250, 600, 0.12));
            $this->sound()->synth('coin', Synth::sequence([[988, 0.05], [1319, 0.12]]));
            $this->sound()->synth('fall', Synth::sweep(500, 80, 0.4, 'triangle'));
        }
    }

    public function update(float $dt): void
    {
        $this->time += $dt;
        if ($this->actions()->isPressed('pause')) {
            $this->scenes()->push(PauseScene::class);
            return;
        }

        if ($this->demo) {
            // headless test (game.yaml smoke): a bot runs right and jumps
            $move = 1;
            $jump = fmod($this->time, 1.2) < 0.05;
            $held = fmod($this->time, 1.2) < 0.4;
        } else {
            $move = $this->actions()->axis('left', 'right');
            $jump = $this->actions()->isPressed('jump');
            $held = $this->actions()->isHeld('jump');
        }
        if ($jump && $this->player->onGround) {
            $this->sound()->playSfx('jump');
        }
        $this->player->control($move, $jump, $held, $dt);
        $this->player->update($dt);
        $this->collisions->move($this->player, $this->map, $dt);

        $this->entities->update($dt, $this->camera);
        foreach ($this->entities->overlapping($this->player->getBoundingBox()) as $entity) {
            if ($entity instanceof Coin) {
                $entity->kill();
                $this->coins++;
                $this->sound()->playSfx('coin');
            }
        }
        $this->camera->update($dt);
    }

    private function respawn(): void
    {
        $this->sound()->playSfx('fall');
        $start = $this->map->object('start');
        $this->player->setXY($start['x'], $start['y']);
        $this->player->setVelocity(0, 0);
        $this->camera->centerOn($start['x'], $start['y']);
    }

    public function render(Renderer $r): void
    {
        $r->clear([92, 148, 252]);
        $cx = $this->camera->getX();
        $cy = $this->camera->getY();
        // far hills (parallax: they move at a quarter of the camera speed)
        for ($i = -1; $i < 8; $i++) {
            $x = $i * 80 - fmod($cx * 0.25, 80);
            $r->fillCircle($x + 40, $this->height() + 10, 50, [70, 120, 200]);
        }
        $this->tiles->renderBackground($r, $this->map, $cx, $cy, $this->time);
        $this->entities->render($r, $this->camera);
        $this->player->render($r, $cx, $cy);
        $this->tiles->renderForeground($r, $this->map, $cx, $cy, $this->time);

        $font = $this->font();
        $font->draw($r, \JumPHP\Util\Lang::t('COINS') . ' ' . $this->coins, 6, 6, 1, [255, 255, 255], 'left', [0, 0, 0]);
    }

    /** The 4 tiles of the level drawn with GD (a real game loads a PNG: TiledJsonLoader / TileSet image). */
    private function makeTileset(): void
    {
        if ($this->sdl()->hasTexture('tileset:generated:tiles')) {
            return;
        }
        $img = imagecreatetruecolor(64, 16);
        imagesavealpha($img, true);
        imagealphablending($img, false);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        $dirt = imagecolorallocate($img, 150, 90, 50);
        $grass = imagecolorallocate($img, 70, 190, 70);
        $wood = imagecolorallocate($img, 200, 140, 70);
        imagefilledrectangle($img, 0, 0, 15, 15, $dirt);                     // 1: block
        imagefilledrectangle($img, 0, 0, 15, 2, $grass);
        imagefilledrectangle($img, 16, 0, 31, 4, $wood);                     // 2: jump-through plank
        imagefilledpolygon($img, [32, 15, 47, 0, 47, 15], $dirt);            // 3: slope up
        imageline($img, 32, 15, 47, 0, $grass);
        imagefilledpolygon($img, [48, 0, 63, 15, 48, 15], $dirt);            // 4: slope down
        imageline($img, 48, 0, 63, 15, $grass);
        $this->sdl()->createTextureFromGd('tileset:generated:tiles', $img);
    }
}
