<?php

declare(strict_types=1);

namespace PhpSoftBox\Scheduler\Tests\Cli;

use DateTimeImmutable;
use DateTimeZone;
use PhpSoftBox\CliApp\Request\Request;
use PhpSoftBox\Scheduler\Cli\ScheduleWorker;
use PhpSoftBox\Scheduler\ScheduleLoader;
use PhpSoftBox\Scheduler\Scheduler;
use PhpSoftBox\Scheduler\Tests\Fixtures\ArrayIo;
use PhpSoftBox\Scheduler\Tests\Fixtures\FakeRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function date_default_timezone_get;
use function date_default_timezone_set;
use function file_put_contents;
use function mkdir;
use function str_replace;
use function sys_get_temp_dir;
use function uniqid;

#[CoversClass(ScheduleWorker::class)]
#[CoversMethod(ScheduleWorker::class, 'run')]
final class ScheduleWorkerTest extends TestCase
{
    /**
     * Проверяет, что daemon-worker выполняет расписание на каждой итерации и ждёт следующую минуту.
     *
     * @see ScheduleWorker::run()
     * @see Scheduler::dispatch()
     * @see ScheduleLoader::load()
     */
    #[Test]
    public function runDispatchesScheduleOnEachTick(): void
    {
        $schedulePath = $this->createSchedulePath();
        $now          = new DateTimeImmutable('2024-01-01 10:00:00', new DateTimeZone('UTC'))->getTimestamp();
        $sleepCalls   = [];

        $io = new ArrayIo();

        $runner = new FakeRunner(new Request([], []), $io);
        $worker = new ScheduleWorker(
            new Scheduler(),
            new ScheduleLoader($schedulePath),
            static function () use (&$now): int {
                return $now;
            },
            static function (int $seconds) use (&$now, &$sleepCalls): void {
                $sleepCalls[] = $seconds;
                $now += $seconds;
            },
        );

        $runs = $worker->run($runner, new DateTimeZone('UTC'), 60, 2);

        self::assertSame(2, $runs);
        self::assertSame([60], $sleepCalls);
        self::assertSame([
            'info:[2024-01-01 10:00:00 UTC] Выполнено задач: 1',
            'info:[2024-01-01 10:01:00 UTC] Выполнено задач: 1',
        ], $io->messages);
    }

    /**
     * Проверим, что без --timezone время тика берётся в поясе приложения, а не в UTC:
     * dailyAt('10:00') срабатывает в 10:00 по date.timezone.
     *
     * @see ScheduleWorker::run()
     */
    #[Test]
    public function runUsesApplicationTimezoneByDefault(): void
    {
        $schedulePath    = $this->createSchedulePath('->dailyAt(\'10:00\')');
        $defaultTimezone = date_default_timezone_get();
        $io              = new ArrayIo();

        // 07:00 UTC = 10:00 по Москве.
        $now = new DateTimeImmutable('2024-01-01 07:00:00', new DateTimeZone('UTC'))->getTimestamp();

        $worker = new ScheduleWorker(
            new Scheduler(),
            new ScheduleLoader($schedulePath),
            static fn (): int => $now,
            static function (): void {
            },
        );

        date_default_timezone_set('Europe/Moscow');
        try {
            $worker->run(new FakeRunner(new Request([], []), $io), null, 60, 1);
        } finally {
            date_default_timezone_set($defaultTimezone);
        }

        self::assertSame(['info:[2024-01-01 10:00:00 MSK] Выполнено задач: 1'], $io->messages);
    }

    /**
     * Проверим, что сброс состояния вызывается после каждого тика.
     *
     * @see ScheduleWorker::run()
     */
    #[Test]
    public function runResetsStateAfterEachTick(): void
    {
        $resets = 0;
        $worker = new ScheduleWorker(
            new Scheduler(),
            new ScheduleLoader($this->createSchedulePath()),
            static fn (): int => 0,
            static function (): void {
            },
            resetState: static function () use (&$resets): void {
                $resets++;
            },
        );

        $worker->run(new FakeRunner(new Request([], []), new ArrayIo()), new DateTimeZone('UTC'), 60, 3);

        self::assertSame(3, $resets);
    }

    /**
     * Проверим, что ошибка сброса состояния выводится и не останавливает worker.
     *
     * @see ScheduleWorker::run()
     */
    #[Test]
    public function runContinuesWhenResetFails(): void
    {
        $io     = new ArrayIo();
        $worker = new ScheduleWorker(
            new Scheduler(),
            new ScheduleLoader($this->createSchedulePath()),
            static fn (): int => 0,
            static function (): void {
            },
            resetState: static function (): never {
                throw new RuntimeException('Reset failed.');
            },
        );

        $runs = $worker->run(new FakeRunner(new Request([], []), $io), new DateTimeZone('UTC'), 60, 2);

        self::assertSame(2, $runs);
        self::assertContains('error:Ошибка сброса состояния: Reset failed.', $io->messages);
    }

    private function createSchedulePath(string $schedule = '->every(1)->minutes()'): string
    {
        $directory = sys_get_temp_dir() . '/psb-schedule-worker-' . uniqid('', true);
        mkdir($directory, 0775, true);

        $content = <<<'PHP'
<?php

declare(strict_types=1);

return static function (\PhpSoftBox\Scheduler\Scheduler $scheduler): void {
    $scheduler
        ->run(static fn (\DateTimeImmutable $time): string => $time->format('H:i'))
        __SCHEDULE__;
};
PHP;

        file_put_contents($directory . '/test.php', str_replace('__SCHEDULE__', $schedule, $content));

        return $directory;
    }
}
