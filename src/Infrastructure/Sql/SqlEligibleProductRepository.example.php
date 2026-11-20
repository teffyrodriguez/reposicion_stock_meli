<?php

declare(strict_types=1);

/**
 * EJEMPLO de implementación PDO/SQL Server — copiar y adaptar al stack Contabilium.
 * No se autoload-ea (sufijo .example.php).
 *
 * Query alineada a docs/FLUJO-PRODUCTOR-CONSUMIDOR-REDIS.md
 */

namespace Contabilium\ReposicionFull\Infrastructure\Sql;

use Contabilium\ReposicionFull\Contracts\EligibleProductRepositoryInterface;
use Contabilium\ReposicionFull\Domain\EligibleProduct;
use PDO;

final class SqlEligibleProductRepositoryExample implements EligibleProductRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function fetchPendingBatch(
        \DateTimeImmutable $windowStart,
        int $batchSize,
        ?EligibleProduct $afterCursor,
    ): array {
        $sql = <<<'SQL'
SELECT TOP (:batchSize)
    c.IDUsuario,
    c.IDIntegracion,
    MIN(c.IDConfiguracionPublicacionIntegracion) AS IDConfiguracionPublicacionIntegracion,
    c.MeLiUserProductId
FROM ConfiguracionPublicacionIntegracion AS c
INNER JOIN Usuarios AS u
    ON u.IDUsuario = c.IDUsuario AND u.Activo = 1
INNER JOIN Integracion AS i
    ON i.IDIntegracion = c.IDIntegracion AND i.FechaEliminacion IS NULL
LEFT JOIN RecomendacionReposicion AS r
    ON r.IDUsuario = c.IDUsuario
   AND r.IDIntegracion = c.IDIntegracion
   AND r.MeLiUserProductId = c.MeLiUserProductId
WHERE c.TipoShipping = N'fulfillment'
  AND c.MeLiUserProductId IS NOT NULL
  AND LTRIM(RTRIM(c.MeLiUserProductId)) <> N''
  AND (
        r.MeLiUserProductId IS NULL
     OR r.FechaActualizacion IS NULL
     OR r.FechaActualizacion < :windowStart
  )
  AND (
        :cursorUsuario IS NULL
     OR c.IDUsuario > :cursorUsuario
     OR (c.IDUsuario = :cursorUsuario AND c.IDIntegracion > :cursorIntegracion)
     OR (c.IDUsuario = :cursorUsuario AND c.IDIntegracion = :cursorIntegracion
         AND c.MeLiUserProductId > :cursorUp)
  )
GROUP BY c.IDUsuario, c.IDIntegracion, c.MeLiUserProductId
ORDER BY c.IDUsuario, c.IDIntegracion, c.MeLiUserProductId
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('batchSize', $batchSize, PDO::PARAM_INT);
        $stmt->bindValue('windowStart', $windowStart->format('Y-m-d H:i:s'));
        $stmt->bindValue('cursorUsuario', $afterCursor?->idUsuario);
        $stmt->bindValue('cursorIntegracion', $afterCursor?->idIntegracion);
        $stmt->bindValue('cursorUp', $afterCursor?->meLiUserProductId);
        $stmt->execute();

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = new EligibleProduct(
                idUsuario: (int) $row['IDUsuario'],
                idIntegracion: (int) $row['IDIntegracion'],
                meLiUserProductId: (string) $row['MeLiUserProductId'],
                idConfiguracionPublicacionIntegracion: isset($row['IDConfiguracionPublicacionIntegracion'])
                    ? (int) $row['IDConfiguracionPublicacionIntegracion']
                    : null,
            );
        }

        return $out;
    }
}
