<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Infrastructure\MercadoLibre;

use Contabilium\ReposicionFull\Contracts\MercadoLibreClientInterface;
use Contabilium\ReposicionFull\Domain\Exceptions\NotFoundRecommendationException;
use Contabilium\ReposicionFull\Domain\Exceptions\PermanentException;
use Contabilium\ReposicionFull\Domain\Exceptions\RetryableException;
use Contabilium\ReposicionFull\Domain\ReplenishmentRecommendation;

/**
 * GET de recomendación. La ruta sale de ML_REPLENISHMENT_PATH (no está fija en el código).
 * Placeholders: {meLiUserProductId}, {idUsuario}, {idIntegracion}.
 * El query param country sale de ML_COUNTRY.
 */
final class HttpMercadoLibreClient implements MercadoLibreClientInterface
{
    public function __construct(
        private readonly SqlAccessTokenResolver $tokens,
        private readonly string $baseUrl,
        private readonly string $pathTemplate,
        private readonly string $country,
        private readonly int $timeoutSeconds = 20,
    ) {
    }

    public function getReplenishmentRecommendation(
        int $idUsuario,
        int $idIntegracion,
        string $meLiUserProductId,
    ): ReplenishmentRecommendation {
        //obtiene el token de cada integracion
        $token = $this->tokens->resolve($idIntegracion);
        if ($token === null) {
            throw new PermanentException('missing_access_token');
        }

        $url = $this->url($idUsuario, $idIntegracion, $meLiUserProductId);
        var_dump($url);
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RetryableException('curl_init_failed');
        }

        curl_setopt_array($handle, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ],
        ]);

        $body = curl_exec($handle);
        var_dump($body);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($errno !== 0 || !is_string($body)) {
            throw new RetryableException('curl_' . $errno . ':' . $error);
        }

        return $this->mapResponse($meLiUserProductId, $status, $body);
    }

    private function mapResponse(string $userProductId, int $status, string $body): ReplenishmentRecommendation
    {
        if ($status === 404) {
            throw new NotFoundRecommendationException('http_404', 404, 'not_found');
        }
        if ($status === 403 || $this->errorCode($body) === 'access_denied') {
            throw new NotFoundRecommendationException('access_denied', $status > 0 ? $status : 403, 'access_denied');
        }
        if ($status === 400 || $this->errorCode($body) === 'invalid_request') {
            throw new NotFoundRecommendationException('invalid_request', $status > 0 ? $status : 400, 'invalid_request');
        }
        if ($status === 401) {
            throw new RetryableException('http_401', httpStatus: 401);
        }
        if ($status === 429) {
            throw new RetryableException('http_429', penalizeRateLimit: true, httpStatus: 429);
        }
        if ($status === 0 || $status >= 500) {
            throw new RetryableException('http_' . $status, httpStatus: $status);
        }
        if ($status === 204 || ($status !== 206 && trim($body) === '')) {
            return ReplenishmentRecommendation::empty($userProductId);
        }
        if ($status < 200 || $status >= 300) {
            throw new PermanentException('http_' . $status . ':' . $this->snippet($body));
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RetryableException('invalid_json:' . $this->snippet($body), previous: $e);
        }

        if (!is_array($decoded)) {
            throw new RetryableException('invalid_json_shape');
        }

        return ReplenishmentPayload::fromApi(
            $userProductId,
            $decoded,
            $status === 206 ? 'partial-content' : null,
        );
    }

    private function url(int $idUsuario, int $idIntegracion, string $meLiUserProductId): string
    {
        $path = strtr($this->pathTemplate, [
            '{meLiUserProductId}' => rawurlencode($meLiUserProductId),
            '{idUsuario}' => (string) $idUsuario,
            '{idIntegracion}' => (string) $idIntegracion,
        ]);

        $url = str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
            ? $path
            : rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');

        $country = trim($this->country);
        if ($country === '') {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . 'country=' . rawurlencode($country);
    }

    private function errorCode(string $body): ?string
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded) || !is_string($decoded['error'] ?? null)) {
            return null;
        }

        $error = trim($decoded['error']);

        return $error === '' ? null : $error;
    }

    private function snippet(string $body): string
    {
        $flat = preg_replace('/\s+/', ' ', $body) ?? $body;

        return mb_substr(trim($flat), 0, 300);
    }
}
