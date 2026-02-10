<?php

declare(strict_types=1);

namespace PhpSoftBox\Scheduler;

use function glob;
use function is_callable;
use function is_dir;
use function is_file;
use function sort;

final class ScheduleLoader
{
    public function __construct(
        private readonly string $path,
    ) {
    }

    public function load(Scheduler $scheduler): void
    {
        foreach ($this->resolveFiles() as $file) {
            $definition = require $file;

            if (!is_callable($definition)) {
                throw new SchedulerException('Schedule file must return callable: ' . $file);
            }

            $definition($scheduler);
        }
    }

    /**
     * @return list<string>
     */
    private function resolveFiles(): array
    {
        if (is_file($this->path)) {
            return [$this->path];
        }

        if (!is_dir($this->path)) {
            return [];
        }

        $files = glob($this->path . '/*.php');
        if ($files === false) {
            return [];
        }

        sort($files);

        return $files;
    }
}
