<?php

namespace JumPHP\Net;

use JumPHP\Renderer\Renderer;
use JumPHP\Ui\Hud;
use JumPHP\Ui\Menu;
use JumPHP\Ui\TextInput;
use JumPHP\Ui\MenuScene;

/**
 * Ready-made online lobby: create a room (shows its code), join one by code, or quick match.
 * When the room is full, switches to your game scene with the session:
 *
 *   $this->scenes()->switchTo(LobbyScene::class, [
 *       'next' => PlayScene::class,          // gets ['online' => true, 'net' => NetSession, 'host' => bool, 'peer' => int] + nextParams
 *       'nextParams' => ['mode' => 'online'],
 *       'back' => TitleScene::class,         // Escape
 *       'players' => 2,
 *       'auto' => null,                      // 'quick' | 'host' | 'ABCD' (room code): skip the menu
 *   ]);
 *
 * A room code in the page URL (?room=ABCD, constant JUMPHP_ROOM) joins that room directly.
 *
 * The URL of the relay server comes from game.yaml net_url, or JUMPHP_NET_URL (page ?net=..., env).
 */
class LobbyScene extends MenuScene
{
    protected string $title = 'ONLINE';
    protected float $titleScale = 2.0;

    private array $params = [];
    private string $state = 'menu';   // menu | waiting | code | error
    private string $message = '';
    private ?TextInput $input = null;
    private float $time = 0.0;

    public function enter(array $params = []): void
    {
        $this->params = $params + ['players' => 2, 'nextParams' => [], 'back' => null];
        $this->state = 'menu';
        $this->message = '';
        $this->time = 0.0;
        parent::enter($params);

        $net = $this->engine->net();
        $net->removeAllListeners();
        $net->on('room', fn () => $this->state = 'waiting');
        $net->on('error', function (string $code, string $message): void {
            $this->state = 'error';
            $this->message = match ($code) {
                'no-room' => 'NO ROOM WITH THIS CODE',
                'room-full' => 'THIS ROOM IS FULL',
                default => strtoupper($message !== '' ? $message : $code),
            };
        });
        $net->on('close', function (): void {
            if ($this->state !== 'menu') {
                $this->state = 'error';
                $this->message = 'CANNOT REACH THE SERVER';
            }
        });

        $auto = $params['auto'] ?? (\JumPHP\Kernel\Platform::setting('JUMPHP_ROOM') ?: null);
        $players = (int)$this->params['players'];
        match (true) {
            $auto === null => null,
            $auto === 'quick' => $this->start(fn (NetSession $n) => $n->quickJoin($players)),
            $auto === 'host' => $this->start(fn (NetSession $n) => $n->host($players)),
            default => $this->start(fn (NetSession $n) => $n->join((string)$auto)),
        };
    }

    protected function items(): array
    {
        return [
            ['CREATE A ROOM', null, fn () => $this->start(fn (NetSession $n) => $n->host((int)$this->params['players']))],
            ['JOIN WITH A CODE', null, function (): void {
                $this->input = new TextInput(4, TextInput::ROOM_CODE, 8);
                $this->state = 'code';
            }],
            ['QUICK MATCH', null, fn () => $this->start(fn (NetSession $n) => $n->quickJoin((int)$this->params['players']))],
            ['BACK', null, fn () => $this->back()],
        ];
    }

    private function start(callable $action): void
    {
        $this->state = 'waiting';
        $this->message = 'CONNECTING...';
        $net = $this->engine->net();
        try {
            if (!$net->isActive()) {
                $net->connect();
            }
            $action($net);
        } catch (\Throwable $e) {
            $this->state = 'error';
            $this->message = 'CANNOT REACH THE SERVER';
        }
    }

    protected function back(): void
    {
        $this->engine->net()->close();
        if ($this->params['back'] !== null) {
            $this->scenes()->switchTo($this->params['back']);
        } else {
            $this->scenes()->pop();
        }
    }

    public function update(float $dt): void
    {
        $this->time += $dt;
        $net = $this->engine->net();
        switch ($this->state) {
            case 'menu':
                parent::update($dt);
                return;
            case 'code':
                $result = $this->input->update($this->actions(), $this->engine->input->getKeyboard());
                if ($result === 'done') {
                    $code = $this->input->value;
                    $this->start(fn (NetSession $n) => $n->join($code));
                } elseif ($result === 'cancel') {
                    $this->state = 'menu';
                }
                return;
            case 'waiting':
                if ($net->inRoom() && $net->playerCount() >= (int)$this->params['players']) {
                    $net->lockRoom(true);
                    $net->removeAllListeners();
                    $this->scenes()->switchTo($this->params['next'], $this->params['nextParams'] + [
                        'online' => true, 'net' => $net, 'host' => $net->isHost(), 'peer' => $net->peerId(),
                    ]);
                    return;
                }
                break;
            case 'error':
                if ($this->actions()->isPressed('confirm')) {
                    $this->state = 'menu';
                    $net->close();
                    return;
                }
                break;
        }
        if ($this->actions()->isPressed('back')) {
            $net->close();
            $this->state = 'menu';
        }
    }

    public function render(Renderer $r): void
    {
        if ($this->state === 'menu') {
            parent::render($r);
            return;
        }
        $this->renderBackground($r);
        $font = $this->font();
        $cx = $this->width() / 2;
        $font->draw($r, \JumPHP\Util\Lang::t($this->title), $cx, $this->height() * 0.12, $this->titleScale, $this->titleColor, 'center');
        $net = $this->engine->net();
        if ($this->state === 'code') {
            $font->draw($r, 'ROOM CODE', $cx, $this->height() * 0.3, 1, [200, 200, 200], 'center');
            $this->input->render($r, $font, $cx, $this->height() * 0.38);
            return;
        }
        if ($this->state === 'error') {
            Hud::message($r, $font, $this->message . "\n\nPRESS " . $this->actions()->label('confirm'), $cx, $this->height() / 2);
            return;
        }
        if ($net->inRoom()) {
            $font->draw($r, 'ROOM', $cx, $this->height() * 0.35, 1, [200, 200, 200], 'center');
            $font->draw($r, (string)$net->roomCode(), $cx, $this->height() * 0.43, 4, [255, 255, 80], 'center');
            $dots = str_repeat('.', 1 + (int)fmod($this->time * 2, 3));
            $font->draw($r, 'WAITING FOR PLAYERS ' . $net->playerCount() . '/' . $this->params['players'] . $dots,
                $cx, $this->height() * 0.7, 1, [200, 200, 200], 'center');
        } else {
            $font->draw($r, $this->message, $cx, $this->height() / 2, 1, [200, 200, 200], 'center');
        }
        $font->draw($r, $this->actions()->label('back') . ': CANCEL', $cx, $this->height() - 14, 1, [120, 120, 120], 'center');
    }
}
