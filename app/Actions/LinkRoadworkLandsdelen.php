<?php

declare(strict_types=1);

namespace App\Actions;

/**
 * Links a roadwork to the landsdelen its geometry intersects.
 */
final class LinkRoadworkLandsdelen extends LinkRoadworkToArea
{
    protected function pivotTable(): string
    {
        return 'roadwork_landsdeel';
    }

    protected function areaTable(): string
    {
        return 'landsdelen';
    }

    protected function areaKey(): string
    {
        return 'landsdeel_id';
    }
}
