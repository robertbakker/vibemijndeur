<?php

declare(strict_types=1);

namespace App\Actions;

/**
 * Links a roadwork to the provincies its geometry intersects.
 */
final class LinkRoadworkProvincies extends LinkRoadworkToArea
{
    protected function pivotTable(): string
    {
        return 'roadwork_provincie';
    }

    protected function areaTable(): string
    {
        return 'provincies';
    }

    protected function areaKey(): string
    {
        return 'provincie_id';
    }
}
