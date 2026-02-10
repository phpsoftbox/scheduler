<?php

declare(strict_types=1);

namespace PhpSoftBox\Scheduler\Lock;

use PhpSoftBox\Scheduler\Contracts\ScheduleLockStoreInterface;
use Redis;
use RuntimeException;

use function extension_loaded;

final readonly class RedisScheduleLockStore implements ScheduleLockStoreInterface
{
    private const string RELEASE_SCRIPT = <<<'LUA'
if redis.call('get', KEYS[1]) == ARGV[1] then
    return redis.call('del', KEYS[1])
end
return 0
LUA;

    public function __construct(
        private Redis $redis,
        private string $prefix = 'scheduler_lock:',
    ) {
        if (!extension_loaded('redis')) {
            throw new RuntimeException('RedisScheduleLockStore requires ext-redis.');
        }
    }

    public function isAtomic(): bool
    {
        return true;
    }

    public function acquire(string $key, string $owner, int $ttlSeconds): bool
    {
        return $this->redis->set(
            $this->prefix . $key,
            $owner,
            ['nx', 'ex' => $ttlSeconds],
        ) !== false;
    }

    public function release(string $key, string $owner): bool
    {
        return (int) $this->redis->eval(
            self::RELEASE_SCRIPT,
            [$this->prefix . $key, $owner],
            1,
        ) === 1;
    }
}
