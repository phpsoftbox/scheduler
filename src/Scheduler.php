<?php

declare(strict_types=1);

namespace PhpSoftBox\Scheduler;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use PhpSoftBox\Scheduler\Clock\SystemSchedulerClock;
use PhpSoftBox\Scheduler\Contracts\ScheduleLockStoreInterface;
use PhpSoftBox\Scheduler\Lock\Psr16ScheduleLockStore;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

use function array_pop;
use function bin2hex;
use function class_exists;
use function count;
use function get_class;
use function interface_exists;
use function is_a;
use function is_array;
use function is_callable;
use function is_object;
use function is_string;
use function method_exists;
use function random_bytes;
use function sha1;
use function spl_object_id;
use function sprintf;

final class Scheduler
{
    /** @var list<ScheduleEntry> */
    private array $tasks = [];
    /** @var list<ScheduledGroup> */
    private array $groupStack        = [];
    private bool $maintenanceEnabled = false;
    private ?object $queue;
    private readonly ?ScheduleLockStoreInterface $lockStore;
    private readonly ClockInterface $clock;
    /** @var callable(string, array): mixed|null */
    private $commandRunner = null;

    /**
     * Создаёт планировщик с опциональной поддержкой DI, кеш-блокировок и очереди.
     */
    public function __construct(
        private readonly ?ContainerInterface $container = null,
        ?CacheInterface $cache = null,
        ?object $queue = null,
        ?ScheduleLockStoreInterface $lockStore = null,
        ?ClockInterface $clock = null,
    ) {
        $this->queue     = $queue;
        $this->lockStore = $lockStore ?? ($cache !== null ? new Psr16ScheduleLockStore($cache) : null);
        $this->clock     = $clock ?? new SystemSchedulerClock();
    }

    /**
     * Регистрирует задачу и возвращает объект настройки расписания.
     *
     * @param callable(DateTimeInterface): mixed|class-string $handler
     */
    public function run(callable|string $handler, ?string $name = null): ScheduledTask
    {
        $group        = $this->currentGroup();
        $handlerId    = $this->describeHandler($handler);
        $handlerClass = $this->handlerClass($handler);
        $resolved     = $this->resolveHandler($handler);
        $task         = new ScheduledTask($resolved, $name, $handlerId, $handlerClass);

        if ($group !== null) {
            $group->addResolved($resolved, $name, $handlerId, $handlerClass);

            return $task;
        }

        $this->tasks[] = $task;

        return $task;
    }

    /**
     * Регистрирует задачу по cron-выражению (синоним run()->cronExpression()).
     *
     * @param callable(DateTimeInterface): mixed|class-string $handler
     */
    public function cron(string $expression, callable|string $handler, ?string $name = null): ScheduledTask
    {
        return $this->run($handler, $name)->cronExpression($expression);
    }

    /**
     * Регистрирует CLI-команду как задачу.
     *
     * @param list<string> $argv
     */
    public function command(string $command, array $argv = [], ?string $name = null): ScheduledTask
    {
        $group     = $this->currentGroup();
        $handler   = $this->createCommandHandler($command, $argv);
        $handlerId = 'command:' . $command;
        $task      = new ScheduledTask($handler, $name, $handlerId, null);

        if ($group !== null) {
            $group->addResolved($handler, $name, $handlerId, null);

            return $task;
        }

        $this->tasks[] = $task;

        return $task;
    }

    /**
     * Создаёт группу задач с единым расписанием и таймзоной.
     */
    public function group(callable|string|null $callbackOrName = null, ?string $name = null): ScheduledGroup
    {
        $callback  = null;
        $groupName = $name;

        if (is_string($callbackOrName)) {
            $groupName = $callbackOrName;
        } elseif ($callbackOrName !== null && is_callable($callbackOrName)) {
            $callback = $callbackOrName;
        }

        $group = new ScheduledGroup(
            resolver: fn (callable|string $handler): callable => $this->resolveHandler($handler),
            descriptor: fn (callable|string $handler): ?string => $this->describeHandler($handler),
            handlerClassResolver: fn (callable|string $handler): ?string => $this->handlerClass($handler),
            name: $groupName,
        );

        $this->tasks[] = $group;

        if ($callback !== null) {
            $this->groupStack[] = $group;
            try {
                $callback($this);
            } finally {
                array_pop($this->groupStack);
            }
        }

        return $group;
    }

    /**
     * Возвращает список задач, которые должны выполниться в указанное время.
     *
     * @return list<ScheduleEntry>
     */
    public function due(DateTimeInterface $time): array
    {
        $due = [];

        foreach ($this->tasks as $task) {
            if ($task->isDue($time)) {
                $due[] = $task;
            }
        }

        return $due;
    }

    /**
     * Запускает все задачи, которые должны выполниться, и возвращает результаты.
     *
     * @return list<mixed>
     */
    public function dispatch(?DateTimeInterface $time = null): array
    {
        $results = [];
        foreach ($this->dispatchResults($time) as $result) {
            if ($result->outcome === ScheduleOutcome::Succeeded || $result->outcome === ScheduleOutcome::Queued) {
                $results[] = $result->value;
            }
        }

        return $results;
    }

    /**
     * Runs all due entries and returns an explicit result for success, failure or a skipped lock.
     *
     * An exception from one entry is captured in its result and does not prevent later entries
     * from running.
     *
     * @return list<ScheduleResult>
     */
    public function dispatchResults(?DateTimeInterface $time = null): array
    {
        if ($this->maintenanceEnabled) {
            return [];
        }

        $time ??= $this->clock->now();
        $results = [];

        foreach ($this->due($time) as $task) {
            if ($this->maintenanceEnabled) {
                break;
            }

            $results[] = $this->handleEntry($task, $time);
        }

        return $results;
    }

    /**
     * Возвращает задачу по имени или null, если она не найдена.
     */
    public function task(string $name): ?ScheduledTask
    {
        foreach ($this->tasks as $task) {
            if ($task instanceof ScheduledTask && $task->name() === $name) {
                return $task;
            }
        }

        return null;
    }

    /**
     * Возвращает все зарегистрированные задачи.
     *
     * @return list<ScheduleEntry>
     */
    public function tasks(): array
    {
        return $this->tasks;
    }

    /**
     * Включает или отключает режим обслуживания (выполнение задач останавливается).
     */
    public function maintenance(bool $enabled = true): void
    {
        $this->maintenanceEnabled = $enabled;
    }

    /**
     * Проверяет, активен ли режим обслуживания.
     */
    public function isMaintenanceEnabled(): bool
    {
        return $this->maintenanceEnabled;
    }

    /**
     * Устанавливает очередь для фонового выполнения задач.
     */
    public function setQueue(?object $queue): void
    {
        $this->queue = $queue;
    }

    /**
     * Задаёт обработчик для запуска CLI-команд внутри задач.
     *
     * @param callable(string, array): mixed|null $runner
     */
    public function setCommandRunner(?callable $runner): void
    {
        $this->commandRunner = $runner;
    }

    /**
     * Преобразует описатель обработчика в callable с поддержкой DI.
     *
     * @param callable(DateTimeInterface): mixed|class-string $handler
     */
    private function resolveHandler(callable|string $handler): callable
    {
        if (is_string($handler)) {
            if (!class_exists($handler) && is_callable($handler)) {
                return $handler;
            }

            $instance = null;

            if ($this->container !== null && $this->container->has($handler)) {
                $instance = $this->container->get($handler);
            } elseif (class_exists($handler)) {
                $instance = new $handler();
            }

            if ($instance === null || !is_callable($instance)) {
                throw new SchedulerException('Handler class is not invokable: ' . $handler);
            }

            return $instance;
        }

        return $handler;
    }

    /**
     * Создаёт обработчик CLI-команды на основе доступного runner.
     *
     * @param list<string> $argv
     */
    private function createCommandHandler(string $command, array $argv): callable
    {
        return function (DateTimeInterface $time) use ($command, $argv): mixed {
            $runner = $this->commandRunner;
            if ($runner === null) {
                throw new SchedulerException('Command runner is not configured.');
            }

            return $runner($command, $argv);
        };
    }

    /**
     * Создаёт читаемый идентификатор обработчика для блокировок.
     *
     * @param callable(DateTimeInterface): mixed|class-string $handler
     */
    private function describeHandler(callable|string $handler): ?string
    {
        if (is_string($handler)) {
            return $handler;
        }

        if (is_array($handler) && isset($handler[0], $handler[1])) {
            $target     = $handler[0];
            $method     = $handler[1];
            $targetName = is_object($target) ? get_class($target) : (string) $target;

            return $targetName . '::' . $method;
        }

        if (is_object($handler)) {
            return get_class($handler) . '#' . spl_object_id($handler);
        }

        return null;
    }

    /**
     * Определяет класс обработчика для задач, которые можно отправить в очередь.
     *
     * @param callable(DateTimeInterface): mixed|class-string $handler
     */
    private function handlerClass(callable|string $handler): ?string
    {
        return is_string($handler) && class_exists($handler) ? $handler : null;
    }

    private function currentGroup(): ?ScheduledGroup
    {
        if ($this->groupStack === []) {
            return null;
        }

        return $this->groupStack[count($this->groupStack) - 1];
    }

    private function handleEntry(ScheduleEntry $task, DateTimeInterface $time): ScheduleResult
    {
        $plannedAt = DateTimeImmutable::createFromInterface($time);

        try {
            if (!$this->acquireOneServerLease($task, $time)) {
                return new ScheduleResult(
                    $task->name(),
                    $plannedAt,
                    null,
                    null,
                    ScheduleOutcome::SkippedOneServer,
                );
            }

            $token = $this->acquireLock($task);
            if ($token === false) {
                return new ScheduleResult(
                    $task->name(),
                    $plannedAt,
                    null,
                    null,
                    ScheduleOutcome::SkippedOverlapping,
                );
            }

            $startedAt = $this->clock->now();
            $queued    = false;
            try {
                $value  = $task->isQueued() ? $this->enqueueEntry($task, $time, $token) : $task->run($time);
                $queued = $task->isQueued();

                return new ScheduleResult(
                    $task->name(),
                    $plannedAt,
                    $startedAt,
                    $this->clock->now(),
                    $queued ? ScheduleOutcome::Queued : ScheduleOutcome::Succeeded,
                    $value,
                );
            } catch (Throwable $exception) {
                return new ScheduleResult(
                    $task->name(),
                    $plannedAt,
                    $startedAt,
                    $this->clock->now(),
                    ScheduleOutcome::Failed,
                    exception: $exception,
                );
            } finally {
                if (!$queued) {
                    $this->releaseLock($task, $token);
                }
            }
        } catch (Throwable $exception) {
            $now = $this->clock->now();

            return new ScheduleResult(
                $task->name(),
                $plannedAt,
                $now,
                $now,
                ScheduleOutcome::Failed,
                exception: $exception,
            );
        }
    }

    /**
     * Пытается установить блокировку для задачи.
     */
    private function acquireLock(ScheduleEntry $task): string|false|null
    {
        if (!$task->isLockEnabled()) {
            return null;
        }

        if ($this->lockStore === null) {
            throw new SchedulerException('withoutOverlapping() requires a ScheduleLockStoreInterface or PSR-16 cache.');
        }

        $token = bin2hex(random_bytes(8));

        return $this->lockStore->acquire($this->lockKey($task), $token, $task->lockTtlSeconds())
            ? $token
            : false;
    }

    /**
     * Снимает блокировку, если задача завершилась в текущем процессе.
     */
    private function releaseLock(ScheduleEntry $task, string|null $token): void
    {
        if ($this->lockStore === null || !$task->isLockEnabled()) {
            return;
        }

        $key = $this->lockKey($task);
        if ($token === null) {
            return;
        }

        $this->lockStore->release($key, $token);
    }

    /**
     * Формирует ключ блокировки для задачи.
     */
    private function lockKey(ScheduleEntry $task): string
    {
        $id = $task->lockId() ?? 'task-' . spl_object_id($task);

        return sprintf('scheduler_%s', sha1($id));
    }

    private function acquireOneServerLease(ScheduleEntry $task, DateTimeInterface $time): bool
    {
        if (!$task->isOneServer()) {
            return true;
        }

        if ($this->lockStore === null || !$this->lockStore->isAtomic()) {
            throw new SchedulerException('onOneServer() requires an atomic ScheduleLockStoreInterface implementation.');
        }

        $id = $task->lockId();
        if ($id === null) {
            throw new SchedulerException('onOneServer() requires a stable task name.');
        }

        $window = DateTimeImmutable::createFromInterface($time)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('YmdHi');
        $key   = sprintf('scheduler_once_%s_%s', sha1($id), $window);
        $owner = bin2hex(random_bytes(8));

        return $this->lockStore->acquire($key, $owner, $task->oneServerTtlSeconds());
    }

    /**
     * Кладёт задачу в очередь вместо немедленного выполнения.
     *
     * @param string|null $token Owner token of an overlap lock held until the queued job finishes.
     */
    private function enqueueEntry(ScheduleEntry $task, DateTimeInterface $time, ?string $token): mixed
    {
        if ($this->queue === null) {
            throw new SchedulerException('Queue is not configured for scheduler.');
        }

        if (!interface_exists('PhpSoftBox\\Queue\\QueueInterface')) {
            throw new SchedulerException('phpsoftbox/queue is required for queued tasks.');
        }

        if (!is_object($this->queue) || !is_a($this->queue, 'PhpSoftBox\\Queue\\QueueInterface')) {
            throw new SchedulerException('Queue instance does not implement QueueInterface.');
        }

        if (!class_exists('PhpSoftBox\\Queue\\QueueJob')) {
            throw new SchedulerException('phpsoftbox/queue is required for queued tasks.');
        }

        $payload = $task->queuePayload($time);
        if ($payload === []) {
            throw new SchedulerException('Queued task payload is empty.');
        }

        if ($token !== null) {
            $payload['lock'] = [
                'key'   => $this->lockKey($task),
                'token' => $token,
            ];
        }

        $jobClass = 'PhpSoftBox\\Queue\\QueueJob';
        /** @var object $job */
        $job = $jobClass::fromPayload($payload);

        $this->queue->push($job);

        $jobId = method_exists($job, 'id') ? $job->id() : null;

        return $jobId;
    }
}
