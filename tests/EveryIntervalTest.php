<?php

declare(strict_types=1);

namespace PhpSoftBox\Scheduler\Tests;

use DateTimeImmutable;
use PhpSoftBox\Scheduler\EveryInterval;
use PhpSoftBox\Scheduler\ScheduledGroup;
use PhpSoftBox\Scheduler\Scheduler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EveryInterval::class)]
#[CoversMethod(EveryInterval::class, 'minutes')]
#[CoversMethod(EveryInterval::class, 'hours')]
final class EveryIntervalTest extends TestCase
{
    /**
     * Проверим, что интервал в минутах задаётся и группе, а не только задаче: группа запускается по расписанию.
     *
     * @see EveryInterval::minutes()
     * @see ScheduledGroup::every()
     */
    #[Test]
    public function minutesConfiguresGroup(): void
    {
        $scheduler = new Scheduler();

        $group = $scheduler->group(static function (Scheduler $scheduler): void {
            $scheduler->run(static fn (): string => 'first');
        }, 'reports')->every(5)->minutes();

        self::assertInstanceOf(ScheduledGroup::class, $group);
        self::assertSame([['first']], $scheduler->dispatch(new DateTimeImmutable('2024-01-01 10:05:00')));
        self::assertSame([], $scheduler->dispatch(new DateTimeImmutable('2024-01-01 10:06:00')));
    }

    /**
     * Проверим, что интервал в часах задаётся группе.
     *
     * @see EveryInterval::hours()
     * @see ScheduledGroup::every()
     */
    #[Test]
    public function hoursConfiguresGroup(): void
    {
        $scheduler = new Scheduler();

        $scheduler->group(static function (Scheduler $scheduler): void {
            $scheduler->run(static fn (): string => 'first');
        }, 'reports')->every(2)->hours(15);

        self::assertSame([['first']], $scheduler->dispatch(new DateTimeImmutable('2024-01-01 10:15:00')));
        self::assertSame([], $scheduler->dispatch(new DateTimeImmutable('2024-01-01 11:15:00')));
    }
}
