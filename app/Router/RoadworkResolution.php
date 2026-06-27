<?php

declare(strict_types=1);

namespace App\Router;

final readonly class RoadworkResolution
{
    public function __construct(
        public int $roadworkId,
        public ?string $redirectToSlug = null,
    ) {}
}
