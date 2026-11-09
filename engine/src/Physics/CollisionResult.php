<?php

namespace JumPHP\Physics;

/** What CollisionSystem::move() found during one move. */
final class CollisionResult
{
    public bool $onGround = false;
    public bool $hitCeiling = false;
    public bool $hitLeft = false;
    public bool $hitRight = false;
    /** gid of the tile under the feet (0: none or a platform) */
    public int $groundGid = 0;
    /** @var array|null the platform stood on (platform provider) */
    public ?array $platform = null;

    public function hitWall(): bool
    {
        return $this->hitLeft || $this->hitRight;
    }
}
