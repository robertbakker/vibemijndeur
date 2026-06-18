<?php

namespace App\Melvin;

use DateTimeInterface;
use JsonSerializable;

class FilterParams implements JsonSerializable
{
    public ?DateTimeInterface $startPeriod = null;

    public ?DateTimeInterface $endPeriod = null;

    /** @var string[] Sources */
    public array $sources = [];

    /** @var int[] IDs */
    public array $ids = [];

    /** @var string[] Situation IDs */
    public array $situationIds = [];

    /** @var string[] Situation Record IDs */
    public array $situationRecordIds = [];

    /** @var string[] Activity types */
    public array $activityTypes = [];

    /** @var int[] Area IDs */
    public array $areaIds = [];

    public ?int $areaBuffer = null;

    /** @var string[] Statuses */
    public array $statuses = [];

    public ?bool $published = null;

    public ?bool $rvmConflict = null;

    /** @var string[] Restriction types */
    public array $restrictionTypes = [];

    public ?bool $includeDetours = null;

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_filter([
            'startPeriod' => $this->startPeriod?->format('Y-m-d\TH:i:s\Z'),
            'endPeriod' => $this->endPeriod?->format('Y-m-d\TH:i:s\Z'),
            'sources' => $this->sources,
            'ids' => $this->ids,
            'situationIds' => $this->situationIds,
            'situationRecordIds' => $this->situationRecordIds,
            'activityTypes' => $this->activityTypes,
            'areaIds' => $this->areaIds,
            'areaBuffer' => $this->areaBuffer,
            'statuses' => $this->statuses,
            'published' => $this->published,
            'rvmConflict' => $this->rvmConflict,
            'restrictionTypes' => $this->restrictionTypes,
            'includeDetours' => $this->includeDetours,
        ], function (mixed $value): bool {
            if (is_array($value)) {
                return count($value) > 0;
            }

            return $value !== null;
        });
    }
}
