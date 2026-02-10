<?php

declare(strict_types=1);

namespace PhpSoftBox\Scheduler;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

use function count;
use function explode;
use function is_string;
use function sprintf;
use function trim;

final class ScheduledGroup implements ScheduleEntry
{
    /** @var list<GroupTask> */
    private array $tasks = [];
    private CronExpression $expression;
    private ?DateTimeZone $timezone = null;
    private bool $lockEnabled       = true;
    private int $lockTtlSeconds     = 3600;
    private bool $queued            = false;
    private ?string $queueName      = null;

    public function __construct(
        private readonly Closure $resolver,
        private readonly Closure $descriptor,
        private readonly Closure $handlerClassResolver,
        private ?string $name = null,
    ) {
        $this->expression = new CronExpression('* * * * *');
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function named(string $name): self
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Group name must be non-empty.');
        }

        $this->name = $name;

        return $this;
    }

    public function lockId(): ?string
    {
        return $this->name;
    }

    public function cronExpression(string $expression): self
    {
        $this->expression = new CronExpression($expression);

        return $this;
    }

    public function every(int $number): EveryInterval
    {
        if ($number < 1) {
            throw new InvalidArgumentException('Interval must be >= 1.');
        }

        return new EveryInterval($this, $number);
    }

    public function daily(): self
    {
        return $this->dailyAt('00:00');
    }

    public function dailyAt(string $time): self
    {
        [$hour, $minute] = $this->parseTime($time);

        return $this->cronExpression(sprintf('%d %d * * *', $minute, $hour));
    }

    public function weekly(): self
    {
        return $this->weeklyOn(1, '00:00');
    }

    public function weeklyOn(int $day, string $time = '00:00'): self
    {
        if ($day < 0 || $day > 7) {
            throw new InvalidArgumentException('Week day must be between 0 and 7.');
        }

        [$hour, $minute] = $this->parseTime($time);

        return $this->cronExpression(sprintf('%d %d * * %d', $minute, $hour, $day));
    }

    public function quarterly(): self
    {
        return $this->quarterlyOn(1, '00:00');
    }

    public function quarterlyOn(int $day, string $time = '00:00'): self
    {
        if ($day < 1 || $day > 31) {
            throw new InvalidArgumentException('Day of month must be between 1 and 31.');
        }

        [$hour, $minute] = $this->parseTime($time);

        return $this->cronExpression(sprintf('%d %d %d 1,4,7,10 *', $minute, $hour, $day));
    }

    public function yearly(): self
    {
        return $this->yearlyOn(1, 1, '00:00');
    }

    public function yearlyOn(int $month, int $day, string $time = '00:00'): self
    {
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException('Month must be between 1 and 12.');
        }

        if ($day < 1 || $day > 31) {
            throw new InvalidArgumentException('Day of month must be between 1 and 31.');
        }

        [$hour, $minute] = $this->parseTime($time);

        return $this->cronExpression(sprintf('%d %d %d %d *', $minute, $hour, $day, $month));
    }

    public function timezone(string|DateTimeZone $timezone): self
    {
        $this->timezone = is_string($timezone) ? new DateTimeZone($timezone) : $timezone;

        return $this;
    }

    public function withoutOverlapping(int $ttlSeconds = 3600): self
    {
        if ($ttlSeconds < 1) {
            throw new InvalidArgumentException('Lock TTL must be >= 1.');
        }

        $this->lockEnabled    = true;
        $this->lockTtlSeconds = $ttlSeconds;

        return $this;
    }

    public function allowOverlapping(): self
    {
        $this->lockEnabled = false;

        return $this;
    }

    public function isLockEnabled(): bool
    {
        return $this->lockEnabled;
    }

    public function lockTtlSeconds(): int
    {
        return $this->lockTtlSeconds;
    }

    public function onQueue(?string $queueName = null): self
    {
        $this->queued    = true;
        $this->queueName = $queueName;

        return $this;
    }

    public function isQueued(): bool
    {
        return $this->queued;
    }

    public function queueName(): ?string
    {
        return $this->queueName;
    }

    public function queuePayload(DateTimeInterface $time): array
    {
        if (!$this->queued) {
            return [];
        }

        $handlers = [];
        foreach ($this->tasks as $task) {
            $handlerClass = $task->handlerClass();
            if ($handlerClass === null) {
                throw new SchedulerException('Queued groups require invokable class handlers only.');
            }
            $handlers[] = $handlerClass;
        }

        $time = $this->resolveTime($time);

        return [
            'type'     => 'scheduler-group',
            'handlers' => $handlers,
            'name'     => $this->name,
            'time'     => $time->format('Y-m-d H:i:s'),
            'timezone' => $time->getTimezone()->getName(),
            'queue'    => $this->queueName,
        ];
    }

    public function isDue(DateTimeInterface $time): bool
    {
        return $this->expression->isDue($this->resolveTime($time));
    }

    public function run(DateTimeInterface $time): mixed
    {
        $results = [];
        $time    = $this->resolveTime($time);

        foreach ($this->tasks as $task) {
            $results[] = $task->run($time);
        }

        return $results;
    }

    /**
     * @param callable(DateTimeInterface): mixed|class-string $handler
     */
    public function add(callable|string $handler, ?string $name = null): self
    {
        $resolved     = ($this->resolver)($handler);
        $handlerId    = ($this->descriptor)($handler);
        $handlerClass = ($this->handlerClassResolver)($handler);

        return $this->addResolved($resolved, $name, $handlerId, $handlerClass);
    }

    /**
     * @param callable(DateTimeInterface): mixed $handler
     */
    public function addResolved(
        callable $handler,
        ?string $name = null,
        ?string $handlerId = null,
        ?string $handlerClass = null,
    ): self {
        $this->tasks[] = new GroupTask($handler, $name, $handlerId, $handlerClass);

        return $this;
    }

    public function runTask(callable|string $handler, ?string $name = null): self
    {
        return $this->add($handler, $name);
    }

    /**
     * @return list<GroupTask>
     */
    public function tasks(): array
    {
        return $this->tasks;
    }

    private function resolveTime(DateTimeInterface $time): DateTimeInterface
    {
        if ($this->timezone === null) {
            return $time;
        }

        return DateTimeImmutable::createFromInterface($time)->setTimezone($this->timezone);
    }

    /**
     * @return array{0:int,1:int}
     */
    private function parseTime(string $time): array
    {
        $time = trim($time);
        if ($time === '') {
            throw new InvalidArgumentException('Time must be non-empty.');
        }

        $parts = explode(':', $time);
        if (count($parts) < 2 || count($parts) > 3) {
            throw new InvalidArgumentException('Time must be in HH:MM format.');
        }

        $hour   = (int) $parts[0];
        $minute = (int) $parts[1];

        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            throw new InvalidArgumentException('Time values are out of range.');
        }

        return [$hour, $minute];
    }
}
