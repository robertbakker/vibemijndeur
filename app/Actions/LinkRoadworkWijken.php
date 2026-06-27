<?php

declare(strict_types=1);

namespace App\Actions;

/**
 * Links a roadwork to the wijken its geometry intersects.
 */
final class LinkRoadworkWijken extends LinkRoadworkToArea
{
    protected function pivotTable(): string
    {
        return 'roadwork_wijk';
    }

    protected function areaTable(): string
    {
        return 'wijken';
    }

    protected function areaKey(): string
    {
        return 'wijk_id';
    }
}
