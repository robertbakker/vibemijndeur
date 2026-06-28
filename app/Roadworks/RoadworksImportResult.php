<?php

declare(strict_types=1);

namespace App\Roadworks;

final readonly class RoadworksImportResult
{
    public function __construct(
        public int $created,
        public int $updated,
        public int $total,
    ) {}
}
