<?php

declare(strict_types=1);

namespace PhpSoftBox\Scheduler\Tests\Fixtures;

use PhpSoftBox\CliApp\Io\ProgressInterface;

final class NullProgress implements ProgressInterface
{
    public function advance(int $step = 1): void
    {
    }

    public function finish(): void
    {
    }
}
