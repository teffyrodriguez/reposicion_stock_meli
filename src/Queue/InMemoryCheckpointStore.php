<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Queue;

use Contabilium\ReposicionFull\Contracts\CheckpointStoreInterface;
use Contabilium\ReposicionFull\Domain\EligibleProduct;

final class InMemoryCheckpointStore implements CheckpointStoreInterface
{
    private ?EligibleProduct $cursor = null;

    public function get(): ?EligibleProduct
    {
        return $this->cursor;
    }

    public function save(EligibleProduct $cursor): void
    {
        $this->cursor = $cursor;
    }

    public function clear(): void
    {
        $this->cursor = null;
    }
}
