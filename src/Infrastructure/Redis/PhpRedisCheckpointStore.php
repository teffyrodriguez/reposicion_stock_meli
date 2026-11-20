<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Infrastructure\Redis;

use Contabilium\ReposicionFull\Contracts\CheckpointStoreInterface;
use Contabilium\ReposicionFull\Domain\EligibleProduct;
use Redis;

/** Cursor del productor en Redis (sobrevive entre corridas). */
final class PhpRedisCheckpointStore implements CheckpointStoreInterface
{
    public function __construct(private readonly Redis $redis)
    {
    }

    public function get(): ?EligibleProduct
    {
        $raw = $this->redis->get(RedisKeys::CHECKPOINT);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !isset($data['IDUsuario'], $data['IDIntegracion'], $data['MeLiUserProductId'])) {
            throw new \RuntimeException('Checkpoint de productor inválido en ' . RedisKeys::CHECKPOINT);
        }

        return new EligibleProduct(
            idUsuario: (int) $data['IDUsuario'],
            idIntegracion: (int) $data['IDIntegracion'],
            meLiUserProductId: (string) $data['MeLiUserProductId'],
            idConfiguracionPublicacionIntegracion: isset($data['IDConfiguracionPublicacionIntegracion'])
                ? (int) $data['IDConfiguracionPublicacionIntegracion']
                : null,
        );
    }

    public function save(EligibleProduct $cursor): void
    {
        $this->redis->set(RedisKeys::CHECKPOINT, json_encode([
            'IDUsuario' => $cursor->idUsuario,
            'IDIntegracion' => $cursor->idIntegracion,
            'MeLiUserProductId' => $cursor->meLiUserProductId,
            'IDConfiguracionPublicacionIntegracion' => $cursor->idConfiguracionPublicacionIntegracion,
        ], JSON_THROW_ON_ERROR));
    }

    public function clear(): void
    {
        $this->redis->del(RedisKeys::CHECKPOINT);
    }
}
