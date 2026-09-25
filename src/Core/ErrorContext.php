<?php

declare(strict_types=1);

namespace Milzer\HttpLogger\Core;

use Milzer\HttpLogger\Core\Redaction\Redactor;
use Throwable;

/**
 * Describes an exception for a log entry without leaking secrets.
 *
 * Exception messages can contain URLs with API keys (e.g. Guzzle's "... for https://host?api_key=..."),
 * and an exception's message cannot be changed after it was created. So by default only a masked
 * description is logged, not the exception object itself.
 *
 * @internal
 */
final class ErrorContext
{
    private const MAX_FRAMES = 20;

    private const MAX_PREVIOUS = 5;

    /**
     * @return array<string, mixed> ['error' => [...]] plus ['exception' => $throwable] when opted in.
     */
    public static function from(Throwable $throwable, Redactor $redactor, bool $includeObject): array
    {
        $error = [
            ...self::describe($throwable, $redactor),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'trace' => self::trace($throwable),
        ];

        $previous = [];

        for ($cause = $throwable->getPrevious(); $cause instanceof Throwable && count($previous) < self::MAX_PREVIOUS; $cause = $cause->getPrevious()) {
            $previous[] = self::describe($cause, $redactor);
        }

        if ($previous !== []) {
            $error['previous'] = $previous;
        }

        return $includeObject ? ['error' => $error, 'exception' => $throwable] : ['error' => $error];
    }

    /**
     * @return array{type: class-string, message: string, code: int|string}
     */
    private static function describe(Throwable $throwable, Redactor $redactor): array
    {
        return [
            'type' => $throwable::class,
            'message' => $redactor->string($throwable->getMessage()),
            'code' => $throwable->getCode(),
        ];
    }

    /**
     * Frames as "file:line Class->method()" without arguments, which could hold secrets.
     *
     * @return list<string>
     */
    private static function trace(Throwable $throwable): array
    {
        $frames = [];

        foreach (array_slice($throwable->getTrace(), 0, self::MAX_FRAMES) as $index => $frame) {
            $frames[] = sprintf(
                '#%d %s%s %s%s%s()',
                $index,
                $frame['file'] ?? '[internal]',
                isset($frame['line']) ? ':'.$frame['line'] : '',
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function'],
            );
        }

        return $frames;
    }
}
