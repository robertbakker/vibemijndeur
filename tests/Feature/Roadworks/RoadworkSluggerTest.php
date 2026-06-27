<?php

declare(strict_types=1);

namespace Tests\Feature\Roadworks;

use App\Models\Roadwork;
use App\Roadworks\RoadworkSlugger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoadworkSluggerTest extends TestCase
{
    use RefreshDatabase;

    private function roadwork(string $cause, ?string $authority): Roadwork
    {
        $rw = new Roadwork;
        $rw->setRawAttributes([
            'road_authority' => $authority,
            'feature' => json_encode(['situation' => ['properties' => ['causeDescription' => $cause]]]),
        ], true);

        return $rw;
    }

    public function test_base_strips_prefix_and_slugifies(): void
    {
        $slugger = app(RoadworkSlugger::class);
        $this->assertSame('s-gravenhage-gas-hoofdstraat', $slugger->base($this->roadwork('GAS Hoofdstraat', "Gemeente 's-Gravenhage")));
    }

    public function test_base_falls_back_to_nederland_without_authority(): void
    {
        $slugger = app(RoadworkSlugger::class);
        $this->assertSame('nederland-kademuur', $slugger->base($this->roadwork('Kademuur', null)));
    }

    public function test_unique_appends_counter_only_on_collision(): void
    {
        $other = DB::table('roadworks')->insertGetId(['source' => 'X', 'source_id' => 'OTHER', 'feature' => '{}'], 'id');
        DB::table('roadwork_slugs')->insert(['roadwork_id' => $other, 'slug' => 'utrecht-n201', 'is_current' => true]);

        $slugger = app(RoadworkSlugger::class);
        $this->assertSame('utrecht-n201-2', $slugger->unique('utrecht-n201', 999));
        $this->assertSame('utrecht-n201', $slugger->unique('utrecht-n201', $other), 'own slug is not a collision');
    }
}
