<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Domain\Exceptions;

final class RetryableException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $penalizeRateLimit = false,
        public readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
