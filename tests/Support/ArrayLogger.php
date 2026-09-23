<?php

declare(strict_types=1);

namespace Milzer\SaloonLogger\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

final class ArrayLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return array{level: string, message: string, context: array<string, mixed>}
     */
    public function record(int $index): array
    {
        return $this->records[$index];
    }
}
