<?php

declare(strict_types=1);

namespace Milzer\SaloonLogger\Tests\Support;

use OutOfBoundsException;
use Psr\Log\AbstractLogger;
use Stringable;

final class ArrayLogger extends AbstractLogger
{
    private static ?self $current = null;

    /** @var list<LogRecord> */
    public array $records = [];

    /**
     * Starts a fresh logger for the current test and returns it.
     */
    public static function fresh(): self
    {
        return self::$current = new self;
    }

    public static function current(): self
    {
        return self::$current ?? throw new OutOfBoundsException('No test logger has been started.');
    }

    /**
     * @param  array<array-key, mixed>  $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = new LogRecord(is_string($level) ? $level : 'unknown', (string) $message, $context);
    }

    public function record(int $index): LogRecord
    {
        return $this->records[$index] ?? throw new OutOfBoundsException(sprintf('No log record at index %d.', $index));
    }
}
