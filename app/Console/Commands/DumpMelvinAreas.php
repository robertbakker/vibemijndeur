<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Melvin\Area;
use App\Melvin\Client;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('melvin:areas')]
#[Description('Fetch and dump all areas from the Melvin API')]
class DumpMelvinAreas extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(Client $melvin): int
    {
        try {
            $areas = $melvin->getAreas();
        } catch (Throwable $e) {
            $this->error('Failed to fetch areas: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['ID', 'Type', 'Name'],
            array_map(
                static fn (Area $area): array => [$area->id, $area->type, $area->name],
                $areas,
            ),
        );

        $this->info(sprintf('%d areas fetched from Melvin.', count($areas)));

        return self::SUCCESS;
    }
}
