# Quick Start

Регистрация задач и запуск только тех, что должны выполниться в указанное время:

```php
use DateTimeImmutable;
use PhpSoftBox\Scheduler\Scheduler;

$scheduler = new Scheduler();

$scheduler->run(function (DateTimeImmutable $time): void {
    // рабочие дни в 9:00
})->cronExpression('0 9 * * 1-5');

$scheduler->run(function (): void {
    // каждый день в 02:30
})->dailyAt('02:30');

$results = $scheduler->dispatch(new DateTimeImmutable('2024-01-01 09:00:00'));
```

Метод `dispatch()` возвращает результаты выполнения задач, которые были запущены.
