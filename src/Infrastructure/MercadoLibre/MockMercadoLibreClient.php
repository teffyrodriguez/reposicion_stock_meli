<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Infrastructure\MercadoLibre;

use Contabilium\ReposicionFull\Contracts\MercadoLibreClientInterface;
use Contabilium\ReposicionFull\Domain\Exceptions\NotFoundRecommendationException;
use Contabilium\ReposicionFull\Domain\Exceptions\PermanentException;
use Contabilium\ReposicionFull\Domain\Exceptions\RetryableException;
use Contabilium\ReposicionFull\Domain\ReplenishmentRecommendation;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Respuesta local para pruebas manuales. Lee el JSON en cada llamada.
 * status 200 devuelve body; 206 guarda partial-content; 404 marca not_found; 403 marca access_denied; invalid_request marca ese estado; 401 reintenta hasta el máximo; 429/5xx reintenta.
 */
final class MockMercadoLibreClient implements MercadoLibreClientInterface
{
    public function __construct(
        private readonly string $file,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function getReplenishmentRecommendation(
        int $idUsuario,
        int $idIntegracion,
        string $meLiUserProductId,
    ): ReplenishmentRecommendation {
        $decoded = $this->read();
        $status = (int) ($decoded['status'] ?? 200);
        $body = isset($decoded['body']) && is_array($decoded['body']) ? $decoded['body'] : $decoded;
        if ($body === $decoded) {
            unset($body['status']);
        }

        $this->logger->info('reposicion.consumer.mock', [
            'file' => $this->file,
            'status' => $status,
            'IDUsuario' => $idUsuario,
            'IDIntegracion' => $idIntegracion,
            'MeLiUserProductId' => $meLiUserProductId,
        ]);

        $error = isset($decoded['error']) && is_string($decoded['error']) ? $decoded['error'] : null;
        if ($status === 404) {
            throw new NotFoundRecommendationException('mock_http_404', 404, 'not_found');
        }
        if ($status === 403 || $error === 'access_denied') {
            throw new NotFoundRecommendationException('access_denied', $status > 0 ? $status : 403, 'access_denied');
        }
        if ($status === 400 || $error === 'invalid_request') {
            throw new NotFoundRecommendationException('invalid_request', $status > 0 ? $status : 400, 'invalid_request');
        }
        if ($status === 401) {
            throw new RetryableException('mock_http_401', httpStatus: 401);
        }
        if ($status === 429) {
            throw new RetryableException('mock_http_429', penalizeRateLimit: true, httpStatus: 429);
        }
        if ($status === 0 || $status >= 500) {
            throw new RetryableException('mock_http_' . $status, httpStatus: $status);
        }
        if ($status === 204 || ($status !== 206 && $body === [])) {
            return ReplenishmentRecommendation::empty($meLiUserProductId);
        }
        if ($status < 200 || $status >= 300) {
            throw new PermanentException('mock_http_' . $status);
        }

        return ReplenishmentPayload::fromApi(
            $meLiUserProductId,
            $body,
            $status === 206 ? 'partial-content' : null,
        );
    }

    /**
     * @return array<mixed>
     */
    private function read(): array
    {
        if (!is_file($this->file)) {
            throw new PermanentException('mock_file_missing:' . $this->file);
        }

        $raw = file_get_contents($this->file);
        if ($raw === false) {
            throw new RetryableException('mock_file_unreadable');
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new PermanentException('mock_invalid_json', previous: $e);
        }

        if (!is_array($decoded)) {
            throw new PermanentException('mock_invalid_json_shape');
        }

        return $decoded;
    }
}
