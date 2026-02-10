<?php

declare(strict_types=1);

namespace PhpSoftBox\Scheduler\Queue;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use PhpSoftBox\Queue\QueueJob;
use PhpSoftBox\Queue\QueueJobHandlerInterface;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;

use function is_array;
use function is_callable;
use function is_string;

final class SchedulerQueueJobHandler implements QueueJobHandlerInterface
{
    public function __construct(
        private readonly ?ContainerInterface $container = null,
        private readonly ?CacheInterface $cache = null,
    ) {
    }

    public function handle(mixed $payload, QueueJob $job): void
    {
        if (!is_array($payload)) {
            throw new InvalidArgumentException('Scheduler payload must be an array.');
        }

        $handlers = $this->resolveHandlers($payload);
        $time     = $this->resolveTime($payload);

        foreach ($handlers as $handler) {
            $handler($time);
        }

        $this->releaseLock($payload);
    }

    /**
     * @return list<callable>
     */
    private function resolveHandlers(array $payload): array
    {
        $handler  = $payload['handler'] ?? null;
        $handlers = $payload['handlers'] ?? null;

        if (is_string($handler)) {
            return [$this->resolveHandler($handler)];
        }

        if (is_array($handlers)) {
            $result = [];
            foreach ($handlers as $entry) {
                if (!is_string($entry)) {
                    continue;
                }
                $result[] = $this->resolveHandler($entry);
            }

            if ($result === []) {
                throw new InvalidArgumentException('Scheduler payload contains no handlers.');
            }

            return $result;
        }

        throw new InvalidArgumentException('Scheduler payload does not contain handlers.');
    }

    private function resolveHandler(string $class): callable
    {
        if ($this->container !== null && $this->container->has($class)) {
            $instance = $this->container->get($class);
        } else {
            $instance = new $class();
        }

        if (!is_callable($instance)) {
            throw new InvalidArgumentException('Handler class is not invokable: ' . $class);
        }

        return $instance;
    }

    private function resolveTime(array $payload): DateTimeImmutable
    {
        $timeValue     = $payload['time'] ?? 'now';
        $timezoneValue = $payload['timezone'] ?? null;

        try {
            $timezone = is_string($timezoneValue) && $timezoneValue !== '' ? new DateTimeZone($timezoneValue) : null;

            return new DateTimeImmutable((string) $timeValue, $timezone);
        } catch (Exception) {
            return new DateTimeImmutable('now');
        }
    }

    private function releaseLock(array $payload): void
    {
        if ($this->cache === null) {
            return;
        }

        $lock = $payload['lock'] ?? null;
        if (!is_array($lock)) {
            return;
        }

        $key   = $lock['key'] ?? null;
        $token = $lock['token'] ?? null;

        if (!is_string($key) || $key === '' || !is_string($token) || $token === '') {
            return;
        }

        if ($this->cache->get($key) === $token) {
            $this->cache->delete($key);
        }
    }
}
