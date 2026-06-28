<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\LinkRoadworkBuurten;
use App\Actions\LinkRoadworkGemeenten;
use App\Actions\LinkRoadworkLandsdelen;
use App\Actions\LinkRoadworkProvincies;
use App\Actions\LinkRoadworkWijken;
use App\Events\RoadworkSaved;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Rebuilds a saved roadwork's CBS area links at all five levels. Queued so bulk
 * imports are not blocked by the spatial work (runs inline under the sync queue).
 */
final readonly class LinkRoadworkAreas implements ShouldQueue
{
    public function __construct(
        private LinkRoadworkLandsdelen $landsdelen,
        private LinkRoadworkProvincies $provincies,
        private LinkRoadworkGemeenten $gemeenten,
        private LinkRoadworkWijken $wijken,
        private LinkRoadworkBuurten $buurten,
    ) {}

    public function handle(RoadworkSaved $event): void
    {
        ($this->landsdelen)($event->roadworkId);
        ($this->provincies)($event->roadworkId);
        ($this->gemeenten)($event->roadworkId);
        ($this->wijken)($event->roadworkId);
        ($this->buurten)($event->roadworkId);
    }
}
