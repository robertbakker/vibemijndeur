<?php

declare(strict_types=1);

namespace App\Roadworks\Datex;

/**
 * Output of {@see DatexSituationMapper}: promoted scalars + the GeoJSON point
 * + the {situation, restrictions, detours, attachments} document for jsonb.
 */
final readonly class MappedRoadwork
{
    public function __construct(
        public string $sourceId,
        public ?string $kind,
        public ?string $severity,
        public ?string $status,
        public ?string $hindrance,
        public ?string $roadAuthority,
        public ?string $startDate,
        public ?string $endDate,
        /** @var array<string, mixed>|null GeoJSON Point */
        public ?array $point,
        /** @var array<string, mixed> */
        public array $document,
    ) {
    }
}
