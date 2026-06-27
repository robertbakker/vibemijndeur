<?php

declare(strict_types=1);

namespace Tests\Unit\Roadworks;

use App\Models\Roadwork;
use App\Roadworks\RoadworkTitle;
use Tests\TestCase;

class RoadworkTitleTest extends TestCase
{
    private function roadwork(?string $cause, ?string $authority = null, ?string $kind = null): Roadwork
    {
        $rw = new Roadwork;
        $rw->setRawAttributes([
            'road_authority' => $authority,
            'kind' => $kind,
            'feature' => json_encode(['situation' => ['properties' => ['causeDescription' => $cause]]]),
        ], true);

        return $rw;
    }

    public function test_title_is_last_comma_part(): void
    {
        $this->assertSame('GAS Hoofdstraat', RoadworkTitle::for($this->roadwork('Kabels / Leidingen, , GAS Hoofdstraat')));
    }

    public function test_title_falls_back_to_authority_and_kind(): void
    {
        $this->assertSame("Gemeente 's-Gravenhage – WORK", RoadworkTitle::for($this->roadwork(null, "Gemeente 's-Gravenhage", 'WORK')));
    }

    public function test_parts_are_trimmed_and_deduped(): void
    {
        $this->assertSame(['Overig', 'Kademuur'], RoadworkTitle::parts($this->roadwork('Overig, , Kademuur, Overig')));
    }
}
