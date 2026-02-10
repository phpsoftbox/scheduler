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

use function file_put_contents;
use function mkdir;
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

    private function createSchedulePath(): string
    {
        $directory = sys_get_temp_dir() . '/psb-schedule-worker-' . uniqid('', true);
        mkdir($directory, 0775, true);

        file_put_contents($directory . '/test.php', <<<'PHP'
<?php

declare(strict_types=1);

return static function (\PhpSoftBox\Scheduler\Scheduler $scheduler): void {
    $scheduler
        ->run(static fn (\DateTimeImmutable $time): string => $time->format('H:i'))
        ->every(1)
        ->minutes();
};
PHP);

        return $directory;
    }
}
