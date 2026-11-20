<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Infrastructure\Sql;

use Contabilium\ReposicionFull\Contracts\RecommendationRepositoryInterface;
use Contabilium\ReposicionFull\Domain\ReplenishmentRecommendation;
use PDO;

/**
 * Frescura y MERGE en RecomendacionReposicion.
 * FechaActualizacion es el momento del UPSERT (UTC). FechaSemanal es el lunes de la ventana.
 */
final class SqlRecommendationRepository implements RecommendationRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function isFresh(
        int $idUsuario,
        int $idIntegracion,
        string $meLiUserProductId,
        \DateTimeImmutable $windowStart,
    ): bool {
        $stmt = $this->pdo->prepare(
            'SELECT FechaActualizacion
             FROM RecomendacionReposicion
             WHERE IDUsuario = :idUsuario
               AND IDIntegracion = :idIntegracion
               AND MeLiUserProductId = :meLiUserProductId'
        );
        $stmt->bindValue('idUsuario', $idUsuario, PDO::PARAM_INT);
        $stmt->bindValue('idIntegracion', $idIntegracion, PDO::PARAM_INT);
        $stmt->bindValue('meLiUserProductId', $meLiUserProductId);
        $stmt->execute();

        $raw = $stmt->fetchColumn();
        if ($raw instanceof \DateTimeInterface) {
            $updatedAt = \DateTimeImmutable::createFromInterface($raw);
        } elseif (is_string($raw) && trim($raw) !== '') {
            try {
                $updatedAt = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
            } catch (\Exception) {
                return false;
            }
        } else {
            return false;
        }

        return $updatedAt >= $windowStart;
    }

    public function isUsuarioIntegracionActiva(int $idUsuario, int $idIntegracion): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT CASE WHEN EXISTS (
                SELECT 1
                FROM Usuarios AS u
                INNER JOIN Integraciones AS i
                    ON i.IDIntegracion = :idIntegracion
                WHERE u.IDUsuario = :idUsuario
                  AND u.Activo = 1
                  AND i.FechaEliminacion IS NULL
            ) THEN 1 ELSE 0 END'
        );
        $stmt->bindValue('idUsuario', $idUsuario, PDO::PARAM_INT);
        $stmt->bindValue('idIntegracion', $idIntegracion, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() === 1;
    }

    public function markEstadoConsulta(
        int $idUsuario,
        int $idIntegracion,
        string $meLiUserProductId,
        string $estadoConsulta,
        \DateTimeImmutable $updatedAt,
        \DateTimeImmutable $semanaRecomendacion,
    ): void {
        $sql = <<<'SQL'
SET NOCOUNT ON;
MERGE RecomendacionReposicion AS t
USING (
    SELECT :idUsuario AS IDUsuario,
           :idIntegracion AS IDIntegracion,
           :meLiUserProductId AS MeLiUserProductId
) AS s
ON t.IDUsuario = s.IDUsuario
AND t.IDIntegracion = s.IDIntegracion
AND t.MeLiUserProductId = s.MeLiUserProductId
WHEN MATCHED THEN UPDATE SET
    FechaSemanal = :fechaSemanal,
    FechaActualizacion = :fechaActualizacion,
    EstadoConsulta = :estadoConsulta
WHEN NOT MATCHED THEN INSERT (
    MeLiUserProductId, IDIntegracion, IDUsuario,
    FechaSemanal, FechaActualizacion, EstadoConsulta
) VALUES (
    :meLiUserProductIdIns, :idIntegracionIns, :idUsuarioIns,
    :fechaSemanalIns, :fechaActualizacionIns, :estadoConsultaIns
);
SQL;

        $fechaActualizacion = $updatedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $fechaSemanal = $semanaRecomendacion->format('Y-m-d');
        $estado = $this->clip($estadoConsulta, 100) ?? 'not_found';

        $stmt = $this->pdo->prepare($sql);
        $this->bind($stmt, 'idUsuario', $idUsuario, PDO::PARAM_INT);
        $this->bind($stmt, 'idIntegracion', $idIntegracion, PDO::PARAM_INT);
        $this->bind($stmt, 'meLiUserProductId', $meLiUserProductId, PDO::PARAM_STR);
        $this->bind($stmt, 'fechaSemanal', $fechaSemanal, PDO::PARAM_STR);
        $this->bind($stmt, 'fechaActualizacion', $fechaActualizacion, PDO::PARAM_STR);
        $this->bind($stmt, 'estadoConsulta', $estado, PDO::PARAM_STR);
        $this->bind($stmt, 'meLiUserProductIdIns', $meLiUserProductId, PDO::PARAM_STR);
        $this->bind($stmt, 'idIntegracionIns', $idIntegracion, PDO::PARAM_INT);
        $this->bind($stmt, 'idUsuarioIns', $idUsuario, PDO::PARAM_INT);
        $this->bind($stmt, 'fechaSemanalIns', $fechaSemanal, PDO::PARAM_STR);
        $this->bind($stmt, 'fechaActualizacionIns', $fechaActualizacion, PDO::PARAM_STR);
        $this->bind($stmt, 'estadoConsultaIns', $estado, PDO::PARAM_STR);
        $stmt->execute();
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
        $titulo = $this->titulo($idUsuario, $idIntegracion, $meLiUserProductId, $idConfiguracionPublicacionIntegracion);

        $sql = <<<'SQL'
SET NOCOUNT ON;
MERGE RecomendacionReposicion AS t
USING (
    SELECT :idUsuario AS IDUsuario,
           :idIntegracion AS IDIntegracion,
           :meLiUserProductId AS MeLiUserProductId
) AS s
ON t.IDUsuario = s.IDUsuario
AND t.IDIntegracion = s.IDIntegracion
AND t.MeLiUserProductId = s.MeLiUserProductId
WHEN MATCHED THEN UPDATE SET
    FechaSemanal = :fechaSemanal,
    FechaActualizacion = :fechaActualizacion,
    CantidadSugerida = :cantidadSugerida,
    CantidadSugeridaMinima = :cantidadSugeridaMinima,
    CantidadSugeridaMaxima = :cantidadSugeridaMaxima,
    InventoryId = :inventoryId,
    UrgenciaDeEnvio = :urgenciaDeEnvio,
    PlazoLimite = :plazoLimite,
    StockTotal = :stockTotal,
    Beneficio = :beneficio,
    Tags = :tags,
    Sku = :sku,
    Titulo = :titulo,
    EstadoConsulta = :estadoConsulta
WHEN NOT MATCHED THEN INSERT (
    MeLiUserProductId, IDIntegracion, IDUsuario,
    FechaSemanal, FechaActualizacion,
    CantidadSugerida, CantidadSugeridaMinima, CantidadSugeridaMaxima, InventoryId,
    UrgenciaDeEnvio, PlazoLimite,
    StockTotal, Beneficio, Tags, Sku, Titulo, EstadoConsulta
) VALUES (
    :meLiUserProductIdIns, :idIntegracionIns, :idUsuarioIns,
    :fechaSemanalIns, :fechaActualizacionIns,
    :cantidadSugeridaIns, :cantidadSugeridaMinimaIns, :cantidadSugeridaMaximaIns, :inventoryIdIns,
    :urgenciaDeEnvioIns, :plazoLimiteIns,
    :stockTotalIns, :beneficioIns, :tagsIns, :skuIns, :tituloIns, :estadoConsultaIns
);
SQL;

        $fechaActualizacion = $updatedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $fechaSemanal = $semanaRecomendacion->format('Y-m-d');
        $estado = $this->clip(
            $recommendation->estadoConsulta ?? ($recommendation->empty ? 'sin_recomendacion' : 'ok'),
            100,
        );

        $stmt = $this->pdo->prepare($sql);
        $this->bind($stmt, 'idUsuario', $idUsuario, PDO::PARAM_INT);
        $this->bind($stmt, 'idIntegracion', $idIntegracion, PDO::PARAM_INT);
        $this->bind($stmt, 'meLiUserProductId', $meLiUserProductId, PDO::PARAM_STR);
        $this->bind($stmt, 'fechaSemanal', $fechaSemanal, PDO::PARAM_STR);
        $this->bind($stmt, 'fechaActualizacion', $fechaActualizacion, PDO::PARAM_STR);
        $this->bind($stmt, 'cantidadSugerida', $recommendation->cantidadSugerida, PDO::PARAM_INT);
        $this->bind($stmt, 'cantidadSugeridaMinima', $recommendation->cantidadSugeridaMinima, PDO::PARAM_INT);
        $this->bind($stmt, 'cantidadSugeridaMaxima', $recommendation->cantidadSugeridaMaxima, PDO::PARAM_INT);
        $this->bind($stmt, 'inventoryId', $recommendation->inventoryId, PDO::PARAM_STR);
        $this->bind($stmt, 'urgenciaDeEnvio', $this->clip($recommendation->urgenciaDeEnvio, 50), PDO::PARAM_STR);
        $this->bind($stmt, 'plazoLimite', $this->clip($recommendation->plazoLimite, 100), PDO::PARAM_STR);
        $this->bind($stmt, 'stockTotal', $recommendation->stockTotal, PDO::PARAM_INT);
        $this->bind($stmt, 'beneficio', $this->clip($recommendation->beneficio, 100), PDO::PARAM_STR);
        $this->bind($stmt, 'tags', $recommendation->tags, PDO::PARAM_STR);
        $this->bind($stmt, 'sku', $recommendation->sku, PDO::PARAM_STR);
        $this->bind($stmt, 'titulo', $titulo, PDO::PARAM_STR);
        $this->bind($stmt, 'estadoConsulta', $estado, PDO::PARAM_STR);

        $this->bind($stmt, 'idUsuarioIns', $idUsuario, PDO::PARAM_INT);
        $this->bind($stmt, 'idIntegracionIns', $idIntegracion, PDO::PARAM_INT);
        $this->bind($stmt, 'meLiUserProductIdIns', $meLiUserProductId, PDO::PARAM_STR);
        $this->bind($stmt, 'fechaSemanalIns', $fechaSemanal, PDO::PARAM_STR);
        $this->bind($stmt, 'fechaActualizacionIns', $fechaActualizacion, PDO::PARAM_STR);
        $this->bind($stmt, 'cantidadSugeridaIns', $recommendation->cantidadSugerida, PDO::PARAM_INT);
        $this->bind($stmt, 'cantidadSugeridaMinimaIns', $recommendation->cantidadSugeridaMinima, PDO::PARAM_INT);
        $this->bind($stmt, 'cantidadSugeridaMaximaIns', $recommendation->cantidadSugeridaMaxima, PDO::PARAM_INT);
        $this->bind($stmt, 'inventoryIdIns', $recommendation->inventoryId, PDO::PARAM_STR);
        $this->bind($stmt, 'urgenciaDeEnvioIns', $this->clip($recommendation->urgenciaDeEnvio, 50), PDO::PARAM_STR);
        $this->bind($stmt, 'plazoLimiteIns', $this->clip($recommendation->plazoLimite, 100), PDO::PARAM_STR);
        $this->bind($stmt, 'stockTotalIns', $recommendation->stockTotal, PDO::PARAM_INT);
        $this->bind($stmt, 'beneficioIns', $this->clip($recommendation->beneficio, 100), PDO::PARAM_STR);
        $this->bind($stmt, 'tagsIns', $recommendation->tags, PDO::PARAM_STR);
        $this->bind($stmt, 'skuIns', $recommendation->sku, PDO::PARAM_STR);
        $this->bind($stmt, 'tituloIns', $titulo, PDO::PARAM_STR);
        $this->bind($stmt, 'estadoConsultaIns', $estado, PDO::PARAM_STR);
        $stmt->execute();
    }

    private function titulo(
        int $idUsuario,
        int $idIntegracion,
        string $meLiUserProductId,
        ?int $idConfiguracionPublicacionIntegracion,
    ): ?string {
        if ($idConfiguracionPublicacionIntegracion !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT TOP 1
                    CASE
                        WHEN NULLIF(LTRIM(RTRIM(MeLiFamilyName)), N\'\') IS NOT NULL THEN MeLiFamilyName
                        ELSE Nombre
                    END
                 FROM ConfiguracionPublicacionIntegracion
                 WHERE IDConfiguracionPublicacionIntegracion = :idConfig'
            );
            $stmt->bindValue('idConfig', $idConfiguracionPublicacionIntegracion, PDO::PARAM_INT);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT TOP 1
                    CASE
                        WHEN NULLIF(LTRIM(RTRIM(MeLiFamilyName)), N\'\') IS NOT NULL THEN MeLiFamilyName
                        ELSE Nombre
                    END
                 FROM ConfiguracionPublicacionIntegracion
                 WHERE IDUsuario = :idUsuario
                   AND IDIntegracion = :idIntegracion
                   AND MeLiUserProductId = :meLiUserProductId
                 ORDER BY IDConfiguracionPublicacionIntegracion'
            );
            $stmt->bindValue('idUsuario', $idUsuario, PDO::PARAM_INT);
            $stmt->bindValue('idIntegracion', $idIntegracion, PDO::PARAM_INT);
            $stmt->bindValue('meLiUserProductId', $meLiUserProductId);
        }

        $stmt->execute();
        $value = $stmt->fetchColumn();
        if ($value === false || $value === null) {
            return null;
        }

        $titulo = trim((string) $value);

        return $titulo === '' ? null : $titulo;
    }

    private function clip(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $max);
    }

    private function bind(\PDOStatement $stmt, string $name, mixed $value, int $type): void
    {
        if ($value === null) {
            $stmt->bindValue($name, null, PDO::PARAM_NULL);

            return;
        }

        $stmt->bindValue($name, $value, $type);
    }
}
