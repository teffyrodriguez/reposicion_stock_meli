<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Support;

use Psr\Log\AbstractLogger;

/**
 * @phpstan-type LogRecord array{level: string, message: string, context: array<string, mixed>}
 */
final class ArrayLogger extends AbstractLogger
{
    /** @var list<LogRecord> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
