<?php

declare(strict_types=1);

namespace App\Actions;

/**
 * Links a roadwork to the gemeenten its geometry intersects.
 */
final class LinkRoadworkGemeenten extends LinkRoadworkToArea
{
    protected function pivotTable(): string
    {
        return 'roadwork_gemeente';
    }

    protected function areaTable(): string
    {
        return 'gemeenten';
    }

    protected function areaKey(): string
    {
        return 'gemeente_id';
    }
}
