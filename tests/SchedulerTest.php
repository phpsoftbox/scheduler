<?php

declare(strict_types=1);

namespace PhpSoftBox\Scheduler\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PhpSoftBox\Queue\Drivers\InMemoryDriver;
use PhpSoftBox\Scheduler\ScheduledGroup;
use PhpSoftBox\Scheduler\ScheduledTask;
use PhpSoftBox\Scheduler\ScheduleOutcome;
use PhpSoftBox\Scheduler\Scheduler;
use PhpSoftBox\Scheduler\ScheduleResult;
use PhpSoftBox\Scheduler\Tests\Fixtures\ArrayCache;
use PhpSoftBox\Scheduler\Tests\Fixtures\FailingQueue;
use PhpSoftBox\Scheduler\Tests\Fixtures\FrozenClock;
use PhpSoftBox\Scheduler\Tests\Fixtures\InMemoryScheduleLockStore;
use PhpSoftBox\Scheduler\Tests\Fixtures\InvokableTask;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function class_exists;
use function sha1;

#[CoversClass(Scheduler::class)]
#[CoversClass(ScheduledGroup::class)]
#[CoversClass(ScheduledTask::class)]
#[CoversClass(ScheduleResult::class)]
#[CoversMethod(Scheduler::class, 'dispatch')]
#[CoversMethod(Scheduler::class, 'dispatchResults')]
#[CoversMethod(ScheduledTask::class, 'withoutOverlapping')]
#[CoversMethod(ScheduledTask::class, 'onOneServer')]
final class SchedulerTest extends TestCase
{
    /**
     * Проверяет, что выполняются только задачи, попадающие в расписание.
     *
     * @see Scheduler::dispatch()
     * @see ScheduledTask::cronExpression()
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
     *
     * @see Scheduler::dispatch()
     * @see ScheduledTask::timezone()
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
     *
     * @see Scheduler::dispatch()
     * @see ScheduledTask::withoutOverlapping()
     */
    #[Test]
    public function testLockPreventsDuplicateRun(): void
    {
        $cache = new ArrayCache();

        $scheduler = new Scheduler(cache: $cache);

        $hits = 0;
        $scheduler->run(function () use (&$hits): void {
            $hits++;
        }, 'locked-task')->every(1)->minutes()->withoutOverlapping();

        $cache->set('scheduler_' . sha1('locked-task'), 'token', 60);

        $time = new DateTimeImmutable('2024-01-01 10:00:00');

        $results = $scheduler->dispatch($time);

        $this->assertSame(0, $hits);
        $this->assertSame([], $results);
    }

    /**
     * Проверяет, что режим обслуживания останавливает выполнение задач.
     *
     * @see Scheduler::maintenance()
     * @see Scheduler::dispatch()
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
     *
     * @see Scheduler::group()
     * @see Scheduler::dispatch()
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
     *
     * @see Scheduler::command()
     * @see Scheduler::setCommandRunner()
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
     *
     * @see ScheduledTask::onQueue()
     * @see Scheduler::dispatch()
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

    /**
     * Проверяет, что общий атомарный lease разрешает выполнить задачу только одному scheduler в одном due-window.
     *
     * @see ScheduledTask::onOneServer()
     * @see Scheduler::dispatchResults()
     */
    #[Test]
    public function onOneServerRunsOnceAcrossSchedulerInstances(): void
    {
        $lockStore = new InMemoryScheduleLockStore();

        $first  = new Scheduler(lockStore: $lockStore);
        $second = new Scheduler(lockStore: $lockStore);
        $hits   = 0;

        $first->run(static function () use (&$hits): void {
            $hits++;
        }, 'reports')->onOneServer();
        $second->run(static function () use (&$hits): void {
            $hits++;
        }, 'reports')->onOneServer();
        $time = new DateTimeImmutable('2024-01-01 10:00:00', new DateTimeZone('UTC'));

        $firstResult  = $first->dispatchResults($time);
        $secondResult = $second->dispatchResults($time);

        self::assertSame(1, $hits);
        self::assertSame(ScheduleOutcome::Succeeded, $firstResult[0]->outcome);
        self::assertSame(ScheduleOutcome::SkippedOneServer, $secondResult[0]->outcome);
    }

    /**
     * Проверяет, что PSR-16 fallback не выдаётся за атомарный HA-lock для onOneServer.
     *
     * @see ScheduledTask::onOneServer()
     * @see Scheduler::dispatchResults()
     */
    #[Test]
    public function onOneServerRejectsNonAtomicPsr16Fallback(): void
    {
        $scheduler = new Scheduler(cache: new ArrayCache());

        $scheduler->run(static fn (): null => null, 'reports')->onOneServer();

        $results = $scheduler->dispatchResults(new DateTimeImmutable('2024-01-01 10:00:00'));

        self::assertSame(ScheduleOutcome::Failed, $results[0]->outcome);
        self::assertStringContainsString('atomic ScheduleLockStoreInterface', $results[0]->exception?->getMessage());
    }

    /**
     * Проверяет, что исключение одной due-задачи попадает в result model и не останавливает следующую задачу.
     *
     * @see Scheduler::dispatchResults()
     * @see ScheduleResult::durationSeconds()
     */
    #[Test]
    public function taskFailureIsCapturedWithoutStoppingOtherTasks(): void
    {
        $scheduler = new Scheduler();

        $scheduler->run(static function (): never {
            throw new RuntimeException('broken task');
        }, 'broken');
        $scheduler->run(static fn (): string => 'completed', 'healthy');
        $plannedAt = new DateTimeImmutable('2024-01-01 10:00:00');

        $results = $scheduler->dispatchResults($plannedAt);

        self::assertSame(ScheduleOutcome::Failed, $results[0]->outcome);
        self::assertSame('broken task', $results[0]->exception?->getMessage());
        self::assertSame(ScheduleOutcome::Succeeded, $results[1]->outcome);
        self::assertSame('completed', $results[1]->value);
        self::assertEquals($plannedAt, $results[1]->plannedAt);
        self::assertNotNull($results[1]->durationSeconds());
    }

    /**
     * Проверяет снятие overlap-lock, если передача задачи в очередь завершилась ошибкой.
     *
     * @see ScheduledTask::withoutOverlapping()
     * @see ScheduledTask::onQueue()
     * @see Scheduler::dispatchResults()
     */
    #[Test]
    public function queueFailureReleasesOverlapLock(): void
    {
        if (!class_exists(InvokableTask::class)) {
            self::markTestSkipped('Queue package is not installed.');
        }

        $queue     = new FailingQueue();
        $lockStore = new InMemoryScheduleLockStore();

        $scheduler = new Scheduler(queue: $queue, lockStore: $lockStore);

        $scheduler->run(InvokableTask::class, 'queued-report')
                    ->withoutOverlapping()
                    ->onQueue();
        $time = new DateTimeImmutable('2024-01-01 10:00:00');

        $first  = $scheduler->dispatchResults($time);
        $second = $scheduler->dispatchResults($time);

        self::assertSame(ScheduleOutcome::Failed, $first[0]->outcome);
        self::assertSame(ScheduleOutcome::Failed, $second[0]->outcome);
        self::assertSame(2, $queue->pushCalls);
    }

    /**
     * Проверяет использование PSR-20 clock, когда время dispatch не передано явно.
     *
     * @see Scheduler::dispatchResults()
     */
    #[Test]
    public function dispatchUsesInjectedClock(): void
    {
        $now = new DateTimeImmutable('2024-01-01 10:20:00', new DateTimeZone('UTC'));

        $scheduler = new Scheduler(clock: new FrozenClock($now));

        $scheduler->run(static fn (): string => 'done')->every(10)->minutes();

        $results = $scheduler->dispatchResults();

        self::assertCount(1, $results);
        self::assertEquals($now, $results[0]->plannedAt);
        self::assertEquals($now, $results[0]->startedAt);
        self::assertEquals($now, $results[0]->finishedAt);
    }
}
