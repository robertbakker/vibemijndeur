<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Melvin\Client;
use App\Melvin\FilterParams;
use App\Roadworks\RoadworksImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('melvin:import {areaId : The Melvin area id to import roadworks for}')]
#[Description('Import roadworks from Melvin for the given area')]
class ImportMelvinRoadworks extends Command
{
    public function handle(Client $client, RoadworksImporter $importer): int
    {
        $areaId = (int) $this->argument('areaId');

        $params = new FilterParams;
        $params->areaIds = [$areaId];
        $params->includeDetours = true;
        $params->areaBuffer = 0;

        try {
            $collection = $client->exportFeatures($params);
        } catch (Throwable $e) {
            $this->error('Failed to export from Melvin: '.$e->getMessage());

            return self::FAILURE;
        }

        $result = $importer->import($collection);

        $this->table(
            ['Created', 'Updated', 'Total'],
            [[$result->created, $result->updated, $result->total]],
        );
        $this->info(sprintf('Imported roadworks for area %d.', $areaId));

        return self::SUCCESS;
    }
}
