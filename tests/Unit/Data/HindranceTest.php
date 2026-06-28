<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\Hindrance;
use App\Models\Roadwork;
use Tests\TestCase;

class HindranceTest extends TestCase
{
    private function roadwork(?string $hindrance): Roadwork
    {
        $rw = new Roadwork;
        $rw->setRawAttributes(['hindrance' => $hindrance], true);

        return $rw;
    }

    public function test_maps_each_class_to_label_and_level(): void
    {
        $this->assertSame(Hindrance::None, Hindrance::for($this->roadwork('hindranceClass0')));
        $this->assertSame('Matige hinder', Hindrance::for($this->roadwork('hindranceClass2'))->label());
        $this->assertSame(4, Hindrance::for($this->roadwork('hindranceClass4'))->level());
    }

    public function test_missing_class_falls_back_to_unknown(): void
    {
        $this->assertSame(Hindrance::Unknown, Hindrance::for($this->roadwork(null)));
        $this->assertSame('Hinder onbekend', Hindrance::Unknown->label());
        $this->assertSame(-1, Hindrance::Unknown->level());
    }
}
