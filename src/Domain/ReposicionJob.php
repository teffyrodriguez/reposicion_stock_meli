<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Domain;

final class ReposicionJob
{
    public function __construct(
        public readonly string $jobId,
        public readonly string $meLiUserProductId,
        public readonly int $idUsuario,
        public readonly int $idIntegracion,
        public readonly ?int $idConfiguracionPublicacionIntegracion,
        public readonly string $source,
        public readonly string $enqueuedAt,
        public readonly int $attempt = 0,
        public readonly ?string $lastError = null,
        /** JSON exacto guardado en processing. No se serializa en la cola. */
        public readonly ?string $processingMember = null,
    ) {
    }

    public static function createNew(
        string $meLiUserProductId,
        int $idUsuario,
        int $idIntegracion,
        ?int $idConfiguracionPublicacionIntegracion,
        string $source,
        ?\DateTimeImmutable $now = null,
    ): self {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return new self(
            jobId: self::uuidV4(),
            meLiUserProductId: $meLiUserProductId,
            idUsuario: $idUsuario,
            idIntegracion: $idIntegracion,
            idConfiguracionPublicacionIntegracion: $idConfiguracionPublicacionIntegracion,
            source: $source,
            enqueuedAt: $now->format(\DateTimeInterface::ATOM),
            attempt: 0,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            jobId: (string) $data['jobId'],
            meLiUserProductId: (string) $data['MeLiUserProductId'],
            idUsuario: (int) $data['IDUsuario'],
            idIntegracion: (int) $data['IDIntegracion'],
            idConfiguracionPublicacionIntegracion: isset($data['IDConfiguracionPublicacionIntegracion'])
                ? (int) $data['IDConfiguracionPublicacionIntegracion']
                : null,
            source: (string) ($data['source'] ?? 'reconcile'),
            enqueuedAt: (string) ($data['enqueuedAt'] ?? gmdate('c')),
            attempt: (int) ($data['attempt'] ?? 0),
            lastError: isset($data['lastError']) ? (string) $data['lastError'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schemaVersion' => 1,
            'jobId' => $this->jobId,
            'MeLiUserProductId' => $this->meLiUserProductId,
            'IDUsuario' => $this->idUsuario,
            'IDIntegracion' => $this->idIntegracion,
            'IDConfiguracionPublicacionIntegracion' => $this->idConfiguracionPublicacionIntegracion,
            'source' => $this->source,
            'enqueuedAt' => $this->enqueuedAt,
            'attempt' => $this->attempt,
            'lastError' => $this->lastError,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    public function businessKey(): string
    {
        return 'REPOSICION-MELI' . $this->idUsuario . '|' . $this->idIntegracion . '|' . $this->meLiUserProductId;
    }

    public function withRetry(string $error): self
    {
        return new self(
            jobId: $this->jobId,
            meLiUserProductId: $this->meLiUserProductId,
            idUsuario: $this->idUsuario,
            idIntegracion: $this->idIntegracion,
            idConfiguracionPublicacionIntegracion: $this->idConfiguracionPublicacionIntegracion,
            source: 'retry',
            enqueuedAt: $this->enqueuedAt,
            attempt: $this->attempt + 1,
            lastError: mb_substr($error, 0, 500),
            processingMember: $this->processingMember,
        );
    }

    public function withProcessingMember(string $payload): self
    {
        return new self(
            jobId: $this->jobId,
            meLiUserProductId: $this->meLiUserProductId,
            idUsuario: $this->idUsuario,
            idIntegracion: $this->idIntegracion,
            idConfiguracionPublicacionIntegracion: $this->idConfiguracionPublicacionIntegracion,
            source: $this->source,
            enqueuedAt: $this->enqueuedAt,
            attempt: $this->attempt,
            lastError: $this->lastError,
            processingMember: $payload,
        );
    }

    /**
     * Payloads a borrar de processing. Incluye el miembro original aunque
     * attempt, source o lastError hayan cambiado.
     *
     * @return list<string>
     */
    public function ackPayloads(): array
    {
        $payloads = [];
        if ($this->processingMember !== null && $this->processingMember !== '') {
            $payloads[] = $this->processingMember;
        }
        $current = $this->toJson();
        if (!in_array($current, $payloads, true)) {
            $payloads[] = $current;
        }

        return $payloads;
    }

    private static function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
