<?php

namespace JumPHP\Physics;

/**
 * Collision flags on an entity, filled by CollisionSystem::move(): use TileCollision; in an Entity class.
 */
trait TileCollision
{
    public bool $onGround = false;
    public bool $onCeiling = false;
    public bool $onWallLeft = false;
    public bool $onWallRight = false;
    public int $groundGid = 0;
}
