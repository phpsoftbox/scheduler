<?php

declare(strict_types=1);

namespace PhpSoftBox\Scheduler\Clock;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final readonly class SystemSchedulerClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
