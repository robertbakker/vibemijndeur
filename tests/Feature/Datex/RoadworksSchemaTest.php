<?php

declare(strict_types=1);

namespace Tests\Feature\Datex;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RoadworksSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_roadworks_has_datex_promoted_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('roadworks', [
            'kind', 'severity', 'hindrance', 'road_authority', 'start_date', 'end_date',
        ]));
    }

    public function test_feed_presence_is_tracked_outside_the_versioned_table(): void
    {
        // first_seen_at/last_seen_at live in the non-versioned roadwork_seen table
        $this->assertFalse(Schema::hasColumn('roadworks', 'last_seen_at'));
        $this->assertTrue(Schema::hasColumns('roadwork_seen', ['first_seen_at', 'last_seen_at']));
    }
}
