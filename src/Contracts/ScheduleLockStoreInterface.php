<?php

declare(strict_types=1);

namespace PhpSoftBox\Scheduler\Contracts;

interface ScheduleLockStoreInterface
{
    /**
     * Reports whether acquire() and release() are atomic across processes.
     */
    public function isAtomic(): bool;

    /**
     * Atomically stores owner when the key does not exist.
     */
    public function acquire(string $key, string $owner, int $ttlSeconds): bool;

    /**
     * Atomically removes the key only when it still belongs to owner.
     */
    public function release(string $key, string $owner): bool;
}
