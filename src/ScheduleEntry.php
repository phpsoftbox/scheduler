<?php

declare(strict_types=1);

namespace PhpSoftBox\Scheduler;

use DateTimeInterface;

interface ScheduleEntry
{
    public function name(): ?string;

    public function lockId(): ?string;

    public function isLockEnabled(): bool;

    public function lockTtlSeconds(): int;

    public function isQueued(): bool;

    public function queueName(): ?string;

    /**
     * @return array<string, mixed>
     */
    public function queuePayload(DateTimeInterface $time): array;

    public function isDue(DateTimeInterface $time): bool;

    public function run(DateTimeInterface $time): mixed;
}
