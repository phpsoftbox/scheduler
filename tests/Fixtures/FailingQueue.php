<?php

declare(strict_types=1);

namespace PhpSoftBox\Scheduler\Tests\Fixtures;

use PhpSoftBox\Queue\QueueInterface;
use PhpSoftBox\Queue\QueueJob;
use RuntimeException;

final class FailingQueue implements QueueInterface
{
    public int $pushCalls = 0;

    public function push(QueueJob $job): void
    {
        $this->pushCalls++;

        throw new RuntimeException('Queue is unavailable.');
    }

    public function pop(): ?QueueJob
    {
        return null;
    }

    public function size(): int
    {
        return 0;
    }
}
