<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Console;

use Psr\Log\AbstractLogger;

final class StderrLogger extends AbstractLogger
{
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $suffix = '';
        if ($context !== []) {
            $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($encoded !== false) {
                $suffix = ' ' . $encoded;
            }
        }

        fwrite(STDERR, sprintf(
            "[%s] %s %s%s\n",
            gmdate('c'),
            (string) $level,
            (string) $message,
            $suffix,
        ));
    }
}
