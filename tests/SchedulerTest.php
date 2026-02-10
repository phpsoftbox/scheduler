<?php

declare(strict_types=1);

namespace PhpSoftBox\Scheduler\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PhpSoftBox\Queue\Drivers\InMemoryDriver;
use PhpSoftBox\Scheduler\ScheduledGroup;
use PhpSoftBox\Scheduler\ScheduledTask;
use PhpSoftBox\Scheduler\Scheduler;
use PhpSoftBox\Scheduler\Tests\Fixtures\ArrayCache;
use PhpSoftBox\Scheduler\Tests\Fixtures\InvokableTask;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function class_exists;

#[CoversClass(Scheduler::class)]
#[CoversClass(ScheduledGroup::class)]
#[CoversClass(ScheduledTask::class)]
final class SchedulerTest extends TestCase
{
    /**
     * Проверяет, что выполняются только задачи, попадающие в расписание.
     */
    #[Test]
    public function testRunsOnlyDueTasks(): void
    {
        $scheduler = new Scheduler();

        $hits = 0;
        $scheduler->run(function () use (&$hits): string {
            $hits++;

            return 'hit';
        })->every(10)->minutes();

        $scheduler->run(function (): string {
            return 'noop';
        })->cronExpression('1 0 1 1 *');

        $time = new DateTimeImmutable('2024-01-01 10:20:00');

        $results = $scheduler->dispatch($time);

        $this->assertSame(1, $hits);
        $this->assertSame(['hit'], $results);
    }

    /**
     * Проверяет, что задача получает время с заданной таймзоной.
     */
    #[Test]
    public function testTimezoneIsApplied(): void
    {
        $scheduler = new Scheduler();

        $hits = 0;
        $scheduler->run(function (DateTimeImmutable $time) use (&$hits): void {
            $hits++;
            self::assertSame('Europe/Moscow', $time->getTimezone()->getName());
        })->dailyAt('10:00')->timezone('Europe/Moscow');

        $utcTime = new DateTimeImmutable('2024-01-01 07:00:00', new DateTimeZone('UTC'));

        $scheduler->dispatch($utcTime);

        $this->assertSame(1, $hits);
    }

    /**
     * Проверяет, что блокировка предотвращает повторный запуск.
     */
    #[Test]
    public function testLockPreventsDuplicateRun(): void
    {
        $cache = new ArrayCache();

        $scheduler = new Scheduler(cache: $cache);

        $hits = 0;
        $scheduler->run(function () use (&$hits): void {
            $hits++;
        }, 'locked-task')->every(1)->minutes();

        $cache->set('scheduler:locked-task', 'token', 60);

        $time = new DateTimeImmutable('2024-01-01 10:00:00');

        $results = $scheduler->dispatch($time);

        $this->assertSame(0, $hits);
        $this->assertSame([], $results);
    }

    /**
     * Проверяет, что режим обслуживания останавливает выполнение задач.
     */
    #[Test]
    public function testMaintenanceStopsExecution(): void
    {
        $scheduler = new Scheduler();

        $scheduler->maintenance(true);

        $hits = 0;
        $scheduler->run(function () use (&$hits): void {
            $hits++;
        })->every(1)->minutes();

        $results = $scheduler->dispatch(new DateTimeImmutable('2024-01-01 10:00:00'));

        $this->assertSame(0, $hits);
        $this->assertSame([], $results);
    }

    /**
     * Проверяет, что группа выполняет все зарегистрированные задачи.
     */
    #[Test]
    public function testGroupRunsAllTasks(): void
    {
        $scheduler = new Scheduler();

        $group = $scheduler->group(function (Scheduler $scheduler): void {
            $scheduler->run(fn (): string => 'first');
            $scheduler->run(fn (): string => 'second');
        }, 'reports')->dailyAt('10:00');

        $results = $scheduler->dispatch(new DateTimeImmutable('2024-01-01 10:00:00'));

        $this->assertSame([['first', 'second']], $results);
    }

    /**
     * Проверяет, что задачи через command() идут в runner.
     */
    #[Test]
    public function testCommandTaskRunsThroughRunner(): void
    {
        $scheduler = new Scheduler();
        $calls     = [];

        $scheduler->setCommandRunner(function (string $command, array $argv) use (&$calls): int {
            $calls[] = [$command, $argv];

            return 0;
        });

        $scheduler->command('cache:clear', ['--force'])->every(1)->minutes();

        $scheduler->dispatch(new DateTimeImmutable('2024-01-01 10:00:00'));

        $this->assertSame([['cache:clear', ['--force']]], $calls);
    }

    /**
     * Проверяет, что задача с onQueue попадает в очередь.
     */
    #[Test]
    public function testQueuedTaskPushesJob(): void
    {
        if (!class_exists(InMemoryDriver::class)) {
            $this->markTestSkipped('Queue package is not installed.');
        }

        $queue = new InMemoryDriver();

        $scheduler = new Scheduler(queue: $queue);

        InvokableTask::$hits = 0;
        $scheduler->run(InvokableTask::class)->every(1)->minutes()->onQueue();

        $scheduler->dispatch(new DateTimeImmutable('2024-01-01 10:00:00'));

        $this->assertSame(0, InvokableTask::$hits);
        $this->assertSame(1, $queue->size());
    }
}
