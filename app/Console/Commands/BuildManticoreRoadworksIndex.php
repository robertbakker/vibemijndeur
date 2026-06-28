<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Roadwork;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Scout\EngineManager;
use RomanStruk\ManticoreScoutEngine\Mysql\Builder;

/**
 * (Re)build the Manticore `roadworks` table from Postgres directly, not through
 * Scout. Drops and recreates the table from
 * {@see Roadwork::scoutIndexMigration()}, then bulk-replaces every searchable
 * roadwork using {@see Roadwork::toManticoreDocument()}.
 */
#[Description('Build the Manticore roadworks index from the database')]
#[Signature('manticore:build-roadworks {--chunk=500 : Rows loaded per batch}')]
class BuildManticoreRoadworksIndex extends Command
{
    public function handle(EngineManager $engines): int
    {
        $model = new Roadwork;
        $index = $model->searchableAs();
        $chunk = max(1, (int) $this->option('chunk'));

        $this->components->task("Recreating Manticore index [{$index}]", function () use ($engines, $model, $index): void {
            try {
                app(Builder::class)->index($index)->drop();
            } catch (\Throwable) {
                // Index did not exist yet — nothing to drop.
            }

            $engines->driver('manticore')->createIndex($index, $model->scoutIndexMigration());
        });

        $total = 0;
        $query = Roadwork::query()
            ->withRepresentativePoint()
            ->withAdministrativeAreas()
            ->with('currentSlug')
            ->orderBy('roadworks.id');

        $bar = $this->output->createProgressBar();
        $query->chunk($chunk, function ($roadworks) use ($index, &$total, $bar): void {
            foreach ($roadworks as $roadwork) {
                if (! $roadwork->shouldBeSearchable()) {
                    continue;
                }

                ['id' => $id, 'attributes' => $attributes] = $roadwork->toManticoreDocument();
                app(Builder::class)->index($index)->replace($attributes, $id);
                $total++;
                $bar->advance();
            }
        });
        $bar->finish();

        $this->newLine(2);
        $this->components->info("Indexed {$total} roadworks into Manticore [{$index}].");

        return self::SUCCESS;
    }
}
