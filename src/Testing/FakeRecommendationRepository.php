<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Testing;

use Contabilium\ReposicionFull\Contracts\RecommendationRepositoryInterface;
use Contabilium\ReposicionFull\Domain\ReplenishmentRecommendation;

final class FakeRecommendationRepository implements RecommendationRepositoryInterface
{
    /** @var array<string, \DateTimeImmutable> */
    public array $freshUntil = [];

    /** @var array<string, bool> */
    public array $active = [];

    /** @var list<array<string, mixed>> */
    public array $upserts = [];

    /** @var list<array<string, mixed>> */
    public array $notFound = [];

    public function isFresh(
        int $idUsuario,
        int $idIntegracion,
        string $meLiUserProductId,
        \DateTimeImmutable $windowStart,
    ): bool {
        $key = $this->key($idUsuario, $idIntegracion, $meLiUserProductId);
        if (!isset($this->freshUntil[$key])) {
            return false;
        }

        return $this->freshUntil[$key] >= $windowStart;
    }

    public function isUsuarioIntegracionActiva(int $idUsuario, int $idIntegracion): bool
    {
        return $this->active[$idUsuario . '|' . $idIntegracion] ?? true;
    }

    public function markEstadoConsulta(
        int $idUsuario,
        int $idIntegracion,
        string $meLiUserProductId,
        string $estadoConsulta,
        \DateTimeImmutable $updatedAt,
        \DateTimeImmutable $semanaRecomendacion,
    ): void {
        $this->freshUntil[$this->key($idUsuario, $idIntegracion, $meLiUserProductId)] = $updatedAt;
        $this->notFound[] = [
            'IDUsuario' => $idUsuario,
            'IDIntegracion' => $idIntegracion,
            'MeLiUserProductId' => $meLiUserProductId,
            'estadoConsulta' => $estadoConsulta,
            'updatedAt' => $updatedAt,
            'semanaRecomendacion' => $semanaRecomendacion,
        ];
    }

    public function upsert(
        int $idUsuario,
        int $idIntegracion,
        string $meLiUserProductId,
        ?int $idConfiguracionPublicacionIntegracion,
        ReplenishmentRecommendation $recommendation,
        \DateTimeImmutable $updatedAt,
        \DateTimeImmutable $semanaRecomendacion,
    ): void {
        $key = $this->key($idUsuario, $idIntegracion, $meLiUserProductId);
        $this->freshUntil[$key] = $updatedAt;
        $this->upserts[] = [
            'IDUsuario' => $idUsuario,
            'IDIntegracion' => $idIntegracion,
            'MeLiUserProductId' => $meLiUserProductId,
            'recommendation' => $recommendation,
            'updatedAt' => $updatedAt,
        ];
    }

    public function markFresh(int $idUsuario, int $idIntegracion, string $up, \DateTimeImmutable $at): void
    {
        $this->freshUntil[$this->key($idUsuario, $idIntegracion, $up)] = $at;
    }

    public function markInactive(int $idUsuario, int $idIntegracion): void
    {
        $this->active[$idUsuario . '|' . $idIntegracion] = false;
    }

    private function key(int $u, int $i, string $up): string
    {
        return $u . '|' . $i . '|' . $up;
    }
}
