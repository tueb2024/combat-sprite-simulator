<?php

namespace JumPHP\Entity;

use JumPHP\Renderer\Renderer;
use JumPHP\Scene\Camera;

/**
 * The entities of a scene: update, draw, remove the dead ones, spawn from map objects.
 *
 *   $this->entities->register('coin', Coin::class);              // Tiled object type => class
 *   $this->entities->register('enemy', fn (array $o) => new Enemy($o['x'], $o['y'], $o['properties']));
 *   $this->entities->spawnFromMap($map);                         // every object of the map with a known type
 *   $this->entities->add(new Bullet(...));
 *   $this->entities->update($dt, $camera);  $this->entities->render($r, $camera);
 *
 * Activity window: with a camera, entities farther than $activityMargin pixels from the view are
 * not updated (they wait, like on old consoles), unless their $alwaysActive property is true.
 */
class EntityManager
{
    /** @var Entity[] */
    private array $entities = [];
    /** @var array<string, string|callable> */
    private array $registry = [];
    public float $activityMargin = 128.0;

    /** @param string|callable(array): ?Entity $factory a class (new $class($x, $y, $w, $h, $type, $name, $properties)) or a callable */
    public function register(string $type, string|callable $factory): void
    {
        $this->registry[$type] = $factory;
    }

    public function add(Entity $entity): Entity
    {
        $this->entities[spl_object_id($entity)] = $entity;
        return $entity;
    }

    public function remove(Entity $entity): void
    {
        unset($this->entities[spl_object_id($entity)]);
    }

    public function clear(): void
    {
        $this->entities = [];
    }

    /** @param array{type: string, name?: string, x: float, y: float, width?: float, height?: float, properties?: array} $object */
    public function spawn(array $object): ?Entity
    {
        $factory = $this->registry[$object['type']] ?? null;
        if ($factory === null) {
            return null;
        }
        $entity = is_string($factory)
            ? new $factory($object['x'], $object['y'], (int)($object['width'] ?? 16), (int)($object['height'] ?? 16),
                $object['type'], $object['name'] ?? '', $object['properties'] ?? [])
            : $factory($object);
        return $entity ? $this->add($entity) : null;
    }

    /** Spawns every object of the map whose type is registered. @return Entity[] */
    public function spawnFromMap(\JumPHP\Tile\TileMap $map): array
    {
        $spawned = [];
        foreach ($map->objects as $object) {
            if ($entity = $this->spawn($object)) {
                $spawned[] = $entity;
            }
        }
        return $spawned;
    }

    public function update(float $dt, ?Camera $camera = null): void
    {
        foreach ($this->entities as $id => $entity) {
            if ($entity->dead) {
                unset($this->entities[$id]);
                continue;
            }
            if (!$entity->active) {
                continue;
            }
            if ($camera !== null && !$entity->alwaysActive && !$camera->isVisible($entity->getBoundingBox(), $this->activityMargin)) {
                continue;
            }
            $entity->update($dt);
        }
        foreach ($this->entities as $id => $entity) {
            if ($entity->dead) {
                unset($this->entities[$id]);
            }
        }
    }

    public function render(Renderer $r, ?Camera $camera = null): void
    {
        $cx = $camera?->getX() ?? 0;
        $cy = $camera?->getY() ?? 0;
        foreach ($this->entities as $entity) {
            if ($entity->visible && ($camera === null || $camera->isVisible($entity->getBoundingBox(), 32))) {
                $entity->render($r, $cx, $cy);
            }
        }
    }

    /** @return Entity[] */
    public function all(): array
    {
        return array_values($this->entities);
    }

    /** @param class-string $class  @return Entity[] */
    public function ofClass(string $class): array
    {
        return array_values(array_filter($this->entities, fn (Entity $e) => $e instanceof $class));
    }

    /** @return Entity[] entities overlapping $rect */
    public function overlapping(array $rect, ?Entity $except = null): array
    {
        $found = [];
        foreach ($this->entities as $entity) {
            if ($entity !== $except && !$entity->dead && $entity->intersects($rect)) {
                $found[] = $entity;
            }
        }
        return $found;
    }

    public function count(): int
    {
        return count($this->entities);
    }
}
