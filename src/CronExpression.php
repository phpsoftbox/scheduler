<?php

declare(strict_types=1);

namespace PhpSoftBox\Scheduler;

use DateTimeInterface;
use InvalidArgumentException;

use function count;
use function preg_split;
use function trim;

final class CronExpression
{
    private CronField $minutes;
    private CronField $hours;
    private CronField $days;
    private CronField $months;
    private CronField $weekdays;

    public function __construct(string $expression)
    {
        $parts = preg_split('/\s+/', trim($expression));

        if ($parts === false || count($parts) !== 5) {
            throw new InvalidArgumentException('Cron expression must have 5 fields.');
        }

        $this->minutes = new CronField($parts[0], 0, 59);

        $this->hours = new CronField($parts[1], 0, 23);

        $this->days = new CronField($parts[2], 1, 31);

        $this->months = new CronField($parts[3], 1, 12);

        $this->weekdays = new CronField($parts[4], 0, 7, true);
    }

    public function isDue(DateTimeInterface $time): bool
    {
        $minute  = (int) $time->format('i');
        $hour    = (int) $time->format('G');
        $day     = (int) $time->format('j');
        $month   = (int) $time->format('n');
        $weekday = (int) $time->format('w');

        return $this->minutes->matches($minute)
            && $this->hours->matches($hour)
            && $this->days->matches($day)
            && $this->months->matches($month)
            && $this->weekdays->matches($weekday);
    }
}
