<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\ProjectDetail;
use App\Models\Gemeente;
use App\Models\Roadwork;
use App\Models\Wijk;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

class ProjectDetailTest extends TestCase
{
    private function roadwork(array $attributes): Roadwork
    {
        $rw = new Roadwork;
        $rw->setRawAttributes([
            'id' => 1,
            'source' => 'DATEX',
            'source_id' => 'X1',
            'status' => 'running',
            'road_authority' => 'Gemeente Maastricht',
            ...$attributes,
        ], true);
        $rw->setRelation('currentSlug', null);

        return $rw;
    }

    public function test_maps_hindrance_and_severity_labels(): void
    {
        $detail = ProjectDetail::fromModel($this->roadwork([
            'hindrance' => 'hindranceClass2',
            'severity' => 'medium',
        ]));

        $this->assertSame('Matige hinder', $detail->hindranceLabel);
        $this->assertSame(2, $detail->hindranceLevel);
        $this->assertSame('Gemiddeld', $detail->severityLabel);
    }

    public function test_car_access_copy_scales_with_hindrance(): void
    {
        $detail = ProjectDetail::fromModel($this->roadwork(['hindrance' => 'hindranceClass4']));

        $car = collect($detail->access)->firstWhere('icon', 'fa-car');
        $this->assertSame('Auto — beperkt', $car['title']);
    }

    public function test_location_prefers_wijk_and_gemeente(): void
    {
        $roadwork = $this->roadwork(['hindrance' => 'hindranceClass1']);
        $roadwork->setRelation('gemeenten', new Collection([(new Gemeente)->forceFill(['name' => 'Maastricht'])]));
        $roadwork->setRelation('wijken', new Collection([(new Wijk)->forceFill(['name' => 'Binnenstad'])]));

        $this->assertSame('Binnenstad, Maastricht', ProjectDetail::fromModel($roadwork)->locationLabel);
    }

    public function test_location_falls_back_to_authority_without_areas(): void
    {
        $detail = ProjectDetail::fromModel($this->roadwork(['hindrance' => 'hindranceClass1']));

        $this->assertSame('Gemeente Maastricht', $detail->locationLabel);
    }

    public function test_description_uses_cleaned_cause_with_type_prefix(): void
    {
        $detail = ProjectDetail::fromModel($this->roadwork([
            'feature' => json_encode([
                'situation' => ['properties' => [
                    'causeType' => 'roadMaintenance',
                    'causeDescription' => 'Kabels / Leidingen, , ASG Technics B.V. i.o. Enexis 06',
                ]],
            ]),
        ]));

        $this->assertSame('Wegonderhoud: Kabels / Leidingen, ASG Technics B.V. i.o. Enexis 06.', $detail->description);
    }

    public function test_description_without_cause_falls_back(): void
    {
        $detail = ProjectDetail::fromModel($this->roadwork([]));

        $this->assertSame('Voor dit project is nog geen uitgebreide omschrijving beschikbaar.', $detail->description);
    }
}
