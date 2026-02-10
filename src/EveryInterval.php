<?php

declare(strict_types=1);

namespace PhpSoftBox\Scheduler;

use InvalidArgumentException;

use function sprintf;

final class EveryInterval
{
    public function __construct(
        private readonly ScheduledTask|ScheduledGroup $task,
        private readonly int $interval,
    ) {
    }

    public function minutes(): ScheduledTask
    {
        if ($this->interval < 1) {
            throw new InvalidArgumentException('Interval must be >= 1.');
        }

        return $this->task->cronExpression(sprintf('*/%d * * * *', $this->interval));
    }

    public function hours(?int $minutes = null): ScheduledTask
    {
        if ($this->interval < 1) {
            throw new InvalidArgumentException('Interval must be >= 1.');
        }

        $minutes = $minutes ?? 0;
        if ($minutes < 0 || $minutes > 59) {
            throw new InvalidArgumentException('Minutes must be between 0 and 59.');
        }

        return $this->task->cronExpression(sprintf('%d */%d * * *', $minutes, $this->interval));
    }
}
