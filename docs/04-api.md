# API

## Scheduler

- `run(callable|string $handler, ?string $name = null): ScheduledTask` — регистрация задачи.
- `cron(string $expression, callable|string $handler, ?string $name = null): ScheduledTask` — регистрация по cron.
- `command(string $command, array $argv = [], ?string $name = null): ScheduledTask` — запуск CLI-команды.
- `group(callable|string|null $callbackOrName = null, ?string $name = null): ScheduledGroup` — создать группу задач.
- `due(DateTimeInterface $time): array` — список задач, которые должны выполниться в указанное время.
- `dispatch(?DateTimeInterface $time = null): array` — выполнить задачи, которые должны выполниться в указанное время.
- `task(string $name): ?ScheduledTask` — найти задачу по имени.
- `tasks(): array` — все зарегистрированные задачи.
- `maintenance(bool $enabled = true): void` — включить/выключить режим обслуживания.
- `isMaintenanceEnabled(): bool` — проверить режим обслуживания.
- `setQueue(?object $queue): void` — передать очередь для фонового выполнения.
- `setCommandRunner(?callable $runner): void` — задать runner для CLI-команд.

## ScheduledTask

- `cronExpression(string $expression): self`
- `every(int $number)->minutes()`
- `every(int $number)->hours(?int $minutes = null)`
- `daily()` / `dailyAt(string $time)`
- `weekly()` / `weeklyOn(int $day, string $time)`
- `quarterly()` / `quarterlyOn(int $day, string $time)`
- `yearly()` / `yearlyOn(int $month, int $day, string $time)`
- `timezone(string|\DateTimeZone $timezone)`
- `named(string $name)` — имя задачи (используется для блокировок)
- `withoutOverlapping(int $ttlSeconds = 3600)` — включить блокировку
- `allowOverlapping()` — отключить блокировку
- `onQueue(?string $queueName = null)` — выполнять через очередь (только invokable-классы)

`weekly()` по умолчанию использует понедельник (1) и время `00:00`.
Если `CacheInterface` передан в `Scheduler`, блокировки включены по умолчанию.

## ScheduledGroup

Группа делит одно расписание и таймзону между несколькими задачами:

- `add(callable|string $handler, ?string $name = null): self`
- `runTask(callable|string $handler, ?string $name = null): self`
- `cronExpression(...)`, `dailyAt(...)`, `weeklyOn(...)`, `timezone(...)` и т.д.
- `onQueue(?string $queueName = null)` — поставить в очередь всю группу

Пример:

```php
$group = $scheduler->group(function (Scheduler $scheduler): void {
    $scheduler->run(ReportTask::class, 'reports:daily');
    $scheduler->run(NotifyTask::class, 'reports:notify');
}, 'reports')->dailyAt('02:30')->timezone('Europe/Moscow');
```

Внутри callback используются только `run()`/`command()`. Расписание и таймзона задаются на группе.
Если нужно имя под задачу внутри группы — передайте его вторым аргументом `run()`.

## Команды CLI

Для запуска команд используйте `command()`:

```php
$scheduler->command('cache:clear', ['--force'])->dailyAt('03:00');
```

Планировщик ожидает `command runner` (обычно это `RunnerInterface::runSubCommand` или `CliApp::runCommand`).
Если используется `schedule:run`, runner настраивается автоматически.

## Нейминг и блокировки

Имя задачи используется как ключ блокировки. Это нужно, когда:

- cron запускается каждую минуту, а задача может выполняться дольше минуты;
- один и тот же планировщик запущен несколькими процессами.

Пример:

```php
$scheduler->run(ReportTask::class, 'reports:daily')
    ->dailyAt('02:30')
    ->withoutOverlapping(3600);
```

Если `CacheInterface` доступен, блокировки включены по умолчанию.
Чтобы разрешить параллельный запуск:

```php
$scheduler->run(CleanupTask::class)->dailyAt('03:00')->allowOverlapping();
```

## Очередь

Для фонового выполнения используйте `onQueue()` и передайте очередь в `Scheduler`.
В очередь можно отправлять только invokable-классы (замыкания и `command()` в очередь не попадают).
Интеграция требует установленный пакет `phpsoftbox/queue`.

```php
$scheduler->run(ReportTask::class)->dailyAt('02:30')->onQueue();
```

В обработчике очереди используйте `PhpSoftBox\\Scheduler\\Queue\\SchedulerQueueJobHandler`.
