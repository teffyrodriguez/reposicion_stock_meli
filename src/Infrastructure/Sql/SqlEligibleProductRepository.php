<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Infrastructure\Sql;

use Contabilium\ReposicionFull\Contracts\EligibleProductRepositoryInterface;
use Contabilium\ReposicionFull\Domain\EligibleProduct;
use PDO;

/**
 * UP Full pendientes desde el inicio de la ventana ML.
 * La comparación contra FechaActualizacion se hace en UTC.
 */
final class SqlEligibleProductRepository implements EligibleProductRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function fetchPendingBatch(
        \DateTimeImmutable $windowStart,
        int                $batchSize,
        ?EligibleProduct   $afterCursor,
    ): array
    {
        var_dump("batchSize :". $batchSize);
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('batchSize tiene que ser mayor a 0');
        }

        $windowUtc = $windowStart->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $withCursor = $afterCursor !== null;

        $sql = <<<SQL
SELECT TOP {$batchSize}
    c.IDUsuario,
    c.IDIntegracion,
    MIN(c.IDConfiguracionPublicacionIntegracion) AS IDConfiguracionPublicacionIntegracion,
    c.MeLiUserProductId
FROM ConfiguracionPublicacionIntegracion AS c
INNER JOIN Usuarios AS u
    ON u.IDUsuario = c.IDUsuario AND u.Activo = 1
INNER JOIN Integraciones AS i
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
SQL;

        if ($withCursor) {
            $sql .= <<<'SQL'

  AND (
        c.IDUsuario > :cursorUsuario
     OR (c.IDUsuario = :cursorUsuarioEq AND c.IDIntegracion > :cursorIntegracion)
     OR (
            c.IDUsuario = :cursorUsuarioEq2
        AND c.IDIntegracion = :cursorIntegracionEq
        AND c.MeLiUserProductId > :cursorUp
     )
  )
SQL;
        }

        $sql .= <<<'SQL'

GROUP BY c.IDUsuario, c.IDIntegracion, c.MeLiUserProductId
ORDER BY c.IDUsuario, c.IDIntegracion, c.MeLiUserProductId
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('windowStart', $windowUtc);
        if ($withCursor) {
            $stmt->bindValue('cursorUsuario', $afterCursor->idUsuario, PDO::PARAM_INT);
            $stmt->bindValue('cursorUsuarioEq', $afterCursor->idUsuario, PDO::PARAM_INT);
            $stmt->bindValue('cursorUsuarioEq2', $afterCursor->idUsuario, PDO::PARAM_INT);
            $stmt->bindValue('cursorIntegracion', $afterCursor->idIntegracion, PDO::PARAM_INT);
            $stmt->bindValue('cursorIntegracionEq', $afterCursor->idIntegracion, PDO::PARAM_INT);
            $stmt->bindValue('cursorUp', $afterCursor->meLiUserProductId);
        }
        $stmt->execute();

        $out = [];
        $data = $stmt->fetchAll();
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = new EligibleProduct(
                idUsuario: (int)$row['IDUsuario'],
                idIntegracion: (int)$row['IDIntegracion'],
                meLiUserProductId: (string)$row['MeLiUserProductId'],
                idConfiguracionPublicacionIntegracion: isset($row['IDConfiguracionPublicacionIntegracion'])
                    ? (int)$row['IDConfiguracionPublicacionIntegracion']
                    : null,
            );
        }

        return $out;
    }
}
