# PhpSoftBox Scheduler

## About
`phpsoftbox/scheduler` — планировщик задач с cron-выражениями и удобным API для расписаний. Позволяет регистрировать задания и запускать их только в нужное время.

Ключевые свойства:
- `Scheduler` для регистрации задач и запуска по расписанию
- `ScheduledTask` для настройки расписаний
- `ScheduledGroup` для групповых расписаний
- `CronExpression` для проверки времени
- поддержка DI для invokable-обработчиков
- блокировки задач через `CacheInterface`
- запуск CLI-команд через `command()`
- опциональная интеграция с `Queue`

## Quick Start
```php
use DateTimeImmutable;
use PhpSoftBox\Scheduler\Scheduler;

$scheduler = new Scheduler();

$scheduler->run(function (DateTimeImmutable $time): void {
    // задача каждые 5 минут
})->every(5)->minutes();

$scheduler->dispatch(new DateTimeImmutable('now'));
```

## Оглавление
- [Документация](docs/index.md)
- [About](docs/01-about.md)
- [Quick Start](docs/02-quick-start.md)
- [Cron выражения](docs/03-cron.md)
- [API](docs/04-api.md)
- [CLI](docs/05-cli.md)
- [DI и конфигурация](docs/06-di.md)
