<?php

declare(strict_types=1);

namespace PhpSoftBox\Scheduler;

use DateTimeImmutable;
use Throwable;

final readonly class ScheduleResult
{
    public function __construct(
        public ?string $taskName,
        public DateTimeImmutable $plannedAt,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
        public ScheduleOutcome $outcome,
        public mixed $value = null,
        public ?Throwable $exception = null,
    ) {
    }

    public function durationSeconds(): ?float
    {
        if ($this->startedAt === null || $this->finishedAt === null) {
            return null;
        }

        return (float) $this->finishedAt->format('U.u') - (float) $this->startedAt->format('U.u');
    }
}
