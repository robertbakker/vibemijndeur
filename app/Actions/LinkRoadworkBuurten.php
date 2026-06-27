<?php

declare(strict_types=1);

namespace App\Actions;

/**
 * Links a roadwork to the buurten its geometry intersects.
 */
final class LinkRoadworkBuurten extends LinkRoadworkToArea
{
    protected function pivotTable(): string
    {
        return 'roadwork_buurt';
    }

    protected function areaTable(): string
    {
        return 'buurten';
    }

    protected function areaKey(): string
    {
        return 'buurt_id';
    }
}
