<?php

declare(strict_types=1);

namespace Tests\Unit\Roadworks;

use App\Models\Roadwork;
use App\Roadworks\RoadworkType;
use Tests\TestCase;

class RoadworkTypeTest extends TestCase
{
    private function roadwork(?string $activityType, ?string $cause = null): Roadwork
    {
        $rw = new Roadwork;
        $rw->setRawAttributes([
            'activity_type' => $activityType,
            'feature' => json_encode(['situation' => ['properties' => ['causeDescription' => $cause]]]),
        ], true);

        return $rw;
    }

    public function test_it_maps_a_keyword_in_the_activity_type_to_an_icon(): void
    {
        $this->assertSame(
            ['label' => 'Gas', 'icon' => 'fa-fire-flame-simple'],
            RoadworkType::for($this->roadwork('Gaswerkzaamheden hoofdleiding')),
        );
    }

    public function test_it_falls_back_to_the_cause_description(): void
    {
        $this->assertSame(
            ['label' => 'Glasvezel', 'icon' => 'fa-wifi'],
            RoadworkType::for($this->roadwork(null, 'Aanleg glasvezel in de wijk')),
        );
    }

    public function test_it_returns_a_generic_type_when_nothing_matches(): void
    {
        $this->assertSame(
            ['label' => 'Werkzaamheden', 'icon' => 'fa-person-digging'],
            RoadworkType::for($this->roadwork('Iets onbekends', 'Geen herkenbaar trefwoord')),
        );
    }
}
