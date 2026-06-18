<?php

declare(strict_types=1);

namespace Tests\Feature\Datex;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportDatexRoadworksTest extends TestCase
{
    use DatabaseMigrations;

    private function fixture(): string
    {
        return base_path('tests/Fixtures/datex/sample.xml');
    }

    public function test_imports_real_situations_and_skips_test_records(): void
    {
        $this->artisan('roadworks:import:datex', ['file' => $this->fixture()])->assertSuccessful();

        $this->assertSame(2, (int) DB::scalar("SELECT count(*) FROM roadworks WHERE source='DATEX'"));

        $work = DB::selectOne("SELECT kind, severity, status, road_authority, ST_AsGeoJSON(coordinates) AS g, feature FROM roadworks WHERE source_id='NDW03_100'");
        $this->assertSame('WORK', $work->kind);
        $this->assertSame('medium', $work->severity);
        $this->assertSame('running', $work->status);
        $this->assertSame('RWS Zuid', $work->road_authority);
        $this->assertStringContainsString('6.127533', $work->g);

        $feature = json_decode($work->feature, true);
        $line = $feature['detours'][0]['geometry'];
        $this->assertSame('LineString', $line['type']);
        $this->assertEqualsWithDelta(6.127, $line['coordinates'][0][0], 1e-6);

        $event = DB::selectOne("SELECT kind, feature FROM roadworks WHERE source_id='NDW03_200'");
        $this->assertSame('EVENT', $event->kind);
        $this->assertSame('https://example.test/attachment/abc', json_decode($event->feature, true)['attachments'][0]['url']);
    }

    public function test_reimport_is_idempotent_and_writes_history_on_change(): void
    {
        $this->artisan('roadworks:import:datex', ['file' => $this->fixture()])->assertSuccessful();
        $this->artisan('roadworks:import:datex', ['file' => $this->fixture()])->assertSuccessful();

        $this->assertSame(2, (int) DB::scalar("SELECT count(*) FROM roadworks WHERE source='DATEX'"));
        $this->assertSame(0, (int) DB::scalar("SELECT count(*) FROM roadworks_history WHERE source_id='NDW03_100'"));

        DB::update("UPDATE roadworks SET severity='high' WHERE source_id='NDW03_100'");
        $this->artisan('roadworks:import:datex', ['file' => $this->fixture()])->assertSuccessful();
        $this->assertGreaterThanOrEqual(1, (int) DB::scalar("SELECT count(*) FROM roadworks_history WHERE source_id='NDW03_100'"));
        $this->assertSame('medium', DB::scalar("SELECT severity FROM roadworks WHERE source_id='NDW03_100'"));
    }
}
