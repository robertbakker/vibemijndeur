<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\Severity;
use App\Models\Roadwork;
use Tests\TestCase;

class SeverityTest extends TestCase
{
    private function roadwork(?string $severity): Roadwork
    {
        $rw = new Roadwork;
        $rw->setRawAttributes(['severity' => $severity], true);

        return $rw;
    }

    public function test_maps_severity_to_label(): void
    {
        $this->assertSame('Laag', Severity::for($this->roadwork('low'))->label());
        $this->assertSame('Zeer hoog', Severity::for($this->roadwork('highest'))->label());
    }

    public function test_missing_severity_falls_back_to_unknown(): void
    {
        $this->assertSame(Severity::Unknown, Severity::for($this->roadwork(null)));
        $this->assertSame('Onbekend', Severity::Unknown->label());
    }
}
