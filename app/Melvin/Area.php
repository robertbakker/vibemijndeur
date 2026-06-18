<?php

declare(strict_types=1);

namespace App\Melvin;

final readonly class Area
{
    public function __construct(
        public int $id,
        public string $type,
        public string $name,
    ) {}
}
