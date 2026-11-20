<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Infrastructure\MercadoLibre;

use PDO;

/** Access token de la integración que se está procesando. */
final class SqlAccessTokenResolver
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function resolve(int $idIntegracion): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT TOP 1 Valor
             FROM ParametrosIntegracion
             WHERE IDIntegracion = :idIntegracion
               AND Clave = N'AccessToken'"
        );
        $stmt->bindValue('idIntegracion', $idIntegracion, PDO::PARAM_INT);
        $stmt->execute();

        $value = $stmt->fetchColumn();
        var_dump("token-cbl", $value);
        if ($value === false || $value === null) {
            return null;
        }

        $token = trim(str_replace(["\r", "\n"], '', (string) $value));
        if ($token === '') {
            return null;
        }
        if (str_starts_with(strtolower($token), 'bearer ')) {
            $token = trim(substr($token, 7));
        }

        return $token === '' ? null : $token;
    }
}
