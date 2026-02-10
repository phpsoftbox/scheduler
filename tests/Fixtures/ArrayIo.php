<?php

declare(strict_types=1);

namespace PhpSoftBox\Scheduler\Tests\Fixtures;

use PhpSoftBox\CliApp\Io\IoInterface;
use PhpSoftBox\CliApp\Io\ProgressInterface;

use function sprintf;

final class ArrayIo implements IoInterface
{
    /** @var list<string> */
    public array $messages = [];

    public function ask(string $question, ?string $default = null): string
    {
        return $default ?? '';
    }

    public function confirm(string $question, bool $default = false): bool
    {
        return $default;
    }

    public function secret(string $question): string
    {
        return '';
    }

    public function writeln(string $message, string $style = 'info'): void
    {
        $this->messages[] = sprintf('%s:%s', $style, $message);
    }

    public function table(array $headers, array $rows): void
    {
    }

    public function progress(int $max): ProgressInterface
    {
        return new NullProgress();
    }
}
