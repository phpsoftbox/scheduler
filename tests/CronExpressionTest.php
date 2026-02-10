<?php

declare(strict_types=1);

namespace PhpSoftBox\Scheduler\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PhpSoftBox\Scheduler\CronExpression;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CronExpression::class)]
final class CronExpressionTest extends TestCase
{
    /**
     * Проверяет, что шаг выражения обрабатывается корректно.
     */
    #[Test]
    public function testMatchesStepExpression(): void
    {
        $expression = new CronExpression('*/5 * * * *');

        $due    = new DateTimeImmutable('2024-01-01 10:05:00');
        $notDue = new DateTimeImmutable('2024-01-01 10:06:00');

        $this->assertTrue($expression->isDue($due));
        $this->assertFalse($expression->isDue($notDue));
    }

    /**
     * Проверяет, что день недели 7 трактуется как воскресенье.
     */
    #[Test]
    public function testMatchesWeekdaySevenAsSunday(): void
    {
        $expression = new CronExpression('* * * * 7');
        $sunday     = new DateTimeImmutable('2024-01-07 00:00:00');

        $this->assertTrue($expression->isDue($sunday));
    }

    /**
     * Проверяет, что неверное выражение приводит к исключению.
     */
    #[Test]
    public function testRejectsInvalidExpression(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CronExpression('* * *');
    }
}
