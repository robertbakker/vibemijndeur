<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\RoadworkSaved;
use App\Listeners\LinkRoadworkAreas as LinkRoadworkAreasListener;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds every roadwork's CBS area links. Needed to backfill rows that predate the
 * on-upsert linking, and to repopulate the pivots after an area refresh (which
 * cascades them away). Runs the same linking the listener does, but synchronously and
 * one roadwork at a time so each jsonb geometry parse stays cheap.
 */
#[Signature('roadworks:link-areas')]
#[Description('Rebuild every roadwork\'s links to the CBS areas it intersects')]
class LinkRoadworkAreas extends Command
{
    public function handle(LinkRoadworkAreasListener $linker): int
    {
        $total = DB::table('roadworks')->count();
        $this->info("Linking {$total} roadworks to CBS areas…");

        $bar = $this->output->createProgressBar($total);

        DB::table('roadworks')->select('id')->orderBy('id')->chunk(500, function ($rows) use ($linker, $bar): void {
            foreach ($rows as $row) {
                $linker->handle(new RoadworkSaved((int) $row->id, false));
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info('Done.');

        return self::SUCCESS;
    }
}
