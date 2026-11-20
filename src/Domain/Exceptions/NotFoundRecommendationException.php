<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Domain\Exceptions;

/** 404, 403 y invalid_request no pisan los datos. En todos se hace ACK. */
final class NotFoundRecommendationException extends \RuntimeException
{
    public function __construct(
        string $message = '',
        public readonly int $httpStatus = 404,
        public readonly ?string $estadoConsulta = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
