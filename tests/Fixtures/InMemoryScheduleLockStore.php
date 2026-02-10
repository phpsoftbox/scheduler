<?php

declare(strict_types=1);

namespace PhpSoftBox\Scheduler\Tests\Fixtures;

use PhpSoftBox\Scheduler\Contracts\ScheduleLockStoreInterface;

final class InMemoryScheduleLockStore implements ScheduleLockStoreInterface
{
    /** @var array<string, string> */
    private array $owners = [];

    public function isAtomic(): bool
    {
        return true;
    }

    public function acquire(string $key, string $owner, int $ttlSeconds): bool
    {
        if (isset($this->owners[$key])) {
            return false;
        }

        $this->owners[$key] = $owner;

        return true;
    }

    public function release(string $key, string $owner): bool
    {
        if (($this->owners[$key] ?? null) !== $owner) {
            return false;
        }

        unset($this->owners[$key]);

        return true;
    }

    public function contains(string $key): bool
    {
        return isset($this->owners[$key]);
    }
}
