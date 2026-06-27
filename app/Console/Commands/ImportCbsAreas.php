<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Areas\CbsArchive;
use App\Areas\CbsAreaImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('cbs:import:areas
    {file? : Path to a CBS .gpkg or the gebiedsindelingen .zip}
    {--year= : Year layer set to import (defaults to config cbs.default_year)}
    {--download : Fetch the ~591 MB CBS archive when no file is given}')]
#[Description('Import the CBS area hierarchy (landsdeel → provincie → gemeente → wijk → buurt)')]
class ImportCbsAreas extends Command
{
    public function handle(CbsAreaImporter $importer): int
    {
        $year = (int) ($this->option('year') ?: config('cbs.default_year'));
        $file = $this->argument('file');

        if ($file === null && ! $this->option('download')) {
            $this->error('Provide a .gpkg/.zip path, or pass --download to fetch the CBS archive.');

            return self::FAILURE;
        }

        $workDir = sys_get_temp_dir().'/cbs-'.$year;
        if (! is_dir($workDir)) {
            mkdir($workDir, 0775, true);
        }
        $archive = new CbsArchive($workDir);

        try {
            $geoPackage = $file !== null
                ? $archive->geoPackageFor($file, $year)
                : $archive->download(config('cbs.archive_url'), $year);

            $counts = $importer->import($geoPackage, $year);
        } catch (Throwable $e) {
            $this->error('CBS area import failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Level', 'Imported'],
            array_map(fn (string $level, int $n): array => [$level, $n], array_keys($counts), array_values($counts)),
        );
        $this->info("Imported CBS areas for {$year}.");

        return self::SUCCESS;
    }
}
