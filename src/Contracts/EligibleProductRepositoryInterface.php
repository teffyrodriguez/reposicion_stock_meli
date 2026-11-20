<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Contracts;

use Contabilium\ReposicionFull\Domain\EligibleProduct;

interface EligibleProductRepositoryInterface
{
    /**
     * Productos Full elegibles no actualizados desde $windowStart, paginados por cursor.
     *
     * @return list<EligibleProduct>
     */
    public function fetchPendingBatch(
        \DateTimeImmutable $windowStart,
        int $batchSize,
        ?EligibleProduct $afterCursor,
    ): array;
}
