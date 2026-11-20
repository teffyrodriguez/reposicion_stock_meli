<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Contracts;

use Contabilium\ReposicionFull\Domain\EligibleProduct;

interface CheckpointStoreInterface
{
    public function get(): ?EligibleProduct;

    public function save(EligibleProduct $cursor): void;

    public function clear(): void;
}
