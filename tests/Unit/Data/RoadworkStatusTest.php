<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\RoadworkStatus;
use App\Models\Roadwork;
use Tests\TestCase;

class RoadworkStatusTest extends TestCase
{
    private function roadwork(?string $status, ?string $start = null, ?string $end = null): Roadwork
    {
        $rw = new Roadwork;
        $rw->setRawAttributes([
            'status' => $status,
            'start_date' => $start,
            'end_date' => $end,
        ], true);

        return $rw;
    }

    public function test_running_status_is_active(): void
    {
        $this->assertSame(RoadworkStatus::Active, RoadworkStatus::for($this->roadwork('running')));
    }

    public function test_final_status_is_done(): void
    {
        $this->assertSame(RoadworkStatus::Done, RoadworkStatus::for($this->roadwork('final')));
    }

    public function test_future_start_without_status_is_planned(): void
    {
        $this->assertSame(RoadworkStatus::Planned, RoadworkStatus::for($this->roadwork(null, '2099-01-01', '2099-03-01')));
    }

    public function test_past_end_without_status_is_done(): void
    {
        $this->assertSame(RoadworkStatus::Done, RoadworkStatus::for($this->roadwork(null, '2000-01-01', '2000-03-01')));
    }

    public function test_label_and_palette_are_exposed(): void
    {
        $this->assertSame('In uitvoering', RoadworkStatus::Active->label());
        $this->assertSame('#FFC400', RoadworkStatus::Active->palette()['markerColor']);
    }
}
