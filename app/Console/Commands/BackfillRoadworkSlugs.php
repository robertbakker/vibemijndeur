<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Roadwork;
use App\Roadworks\RoadworkSlugSynchronizer;
use Illuminate\Console\Command;

class BackfillRoadworkSlugs extends Command
{
    protected $signature = 'roadworks:backfill-slugs';

    protected $description = 'Generate current slugs for all roadworks (idempotent).';

    public function handle(RoadworkSlugSynchronizer $synchronizer): int
    {
        $count = 0;

        Roadwork::query()->select('id')->chunkById(500, function ($roadworks) use ($synchronizer, &$count): void {
            foreach ($roadworks as $roadwork) {
                $synchronizer->sync((int) $roadwork->id);
                $count++;
            }
        });

        $this->info("Synced slugs for {$count} roadworks.");

        return self::SUCCESS;
    }
}
