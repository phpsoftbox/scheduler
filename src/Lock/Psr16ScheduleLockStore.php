<?php

declare(strict_types=1);

namespace PhpSoftBox\Scheduler\Lock;

use PhpSoftBox\Scheduler\Contracts\ScheduleLockStoreInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Backward-compatible lock adapter for a single scheduler process.
 *
 * PSR-16 does not define add-if-absent or compare-and-delete operations, so this
 * adapter deliberately reports itself as non-atomic and must not be used to
 * claim HA guarantees.
 */
final readonly class Psr16ScheduleLockStore implements ScheduleLockStoreInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function isAtomic(): bool
    {
        return false;
    }

    public function acquire(string $key, string $owner, int $ttlSeconds): bool
    {
        if ($this->cache->get($key) !== null) {
            return false;
        }

        return $this->cache->set($key, $owner, $ttlSeconds);
    }

    public function release(string $key, string $owner): bool
    {
        if ($this->cache->get($key) !== $owner) {
            return false;
        }

        return $this->cache->delete($key);
    }
}
