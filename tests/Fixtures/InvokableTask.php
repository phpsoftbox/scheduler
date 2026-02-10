<?php

declare(strict_types=1);

namespace PhpSoftBox\Scheduler\Tests\Fixtures;

use DateTimeInterface;

final class InvokableTask
{
    public static int $hits = 0;

    public function __invoke(DateTimeInterface $time): void
    {
        self::$hits++;
    }
}
