<?php

declare(strict_types=1);

namespace PhpSoftBox\Scheduler;

use InvalidArgumentException;

use function array_key_exists;
use function explode;
use function is_numeric;
use function str_contains;
use function trim;

final class CronField
{
    /** @var array<int, true> */
    private array $allowed = [];

    public function __construct(
        private readonly string $expression,
        private readonly int $min,
        private readonly int $max,
        private readonly bool $mapSevenToZero = false,
    ) {
        $this->parse();
    }

    public function matches(int $value): bool
    {
        return array_key_exists($value, $this->allowed);
    }

    private function parse(): void
    {
        $segments = explode(',', $this->expression);

        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            $this->parseSegment($segment);
        }

        if ($this->allowed === []) {
            throw new InvalidArgumentException('Cron field expression is empty.');
        }

        if ($this->mapSevenToZero && array_key_exists(7, $this->allowed)) {
            $this->allowed[0] = true;
            unset($this->allowed[7]);
        }
    }

    private function parseSegment(string $segment): void
    {
        $step  = 1;
        $range = $segment;

        if (str_contains($segment, '/')) {
            [$range, $stepValue] = explode('/', $segment, 2);
            if ($stepValue === '' || !is_numeric($stepValue)) {
                throw new InvalidArgumentException('Cron step value is invalid.');
            }
            $step = (int) $stepValue;
            if ($step <= 0) {
                throw new InvalidArgumentException('Cron step value must be positive.');
            }
        }

        if ($range === '' || $range === '*') {
            $start = $this->min;
            $end   = $this->max;
        } elseif (str_contains($range, '-')) {
            [$startValue, $endValue] = explode('-', $range, 2);
            if (!is_numeric($startValue) || !is_numeric($endValue)) {
                throw new InvalidArgumentException('Cron range contains non-numeric values.');
            }
            $start = (int) $startValue;
            $end   = (int) $endValue;
        } else {
            if (!is_numeric($range)) {
                throw new InvalidArgumentException('Cron value is invalid.');
            }
            $start = (int) $range;
            $end   = (int) $range;
        }

        if ($start < $this->min || $end > $this->max || $start > $end) {
            throw new InvalidArgumentException('Cron field values are out of range.');
        }

        for ($value = $start; $value <= $end; $value += $step) {
            $this->allowed[$value] = true;
        }
    }
}
