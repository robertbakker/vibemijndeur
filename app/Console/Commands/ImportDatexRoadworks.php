<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Roadworks\Datex\DatexFeedReader;
use App\Roadworks\Datex\DatexSituationMapper;
use App\Roadworks\RoadworkUpserter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('roadworks:import:datex {file? : Local .xml/.xml.gz path; downloads the live feed if omitted}')]
#[Description('Import roadworks & events from the NDW open DATEX planningsfeed')]
class ImportDatexRoadworks extends Command
{
    private const FEED_URL = 'https://opendata.ndw.nu/planningsfeed_wegwerkzaamheden_en_evenementen.xml.gz';

    public function handle(DatexFeedReader $reader, DatexSituationMapper $mapper, RoadworkUpserter $upserter): int
    {
        $source = $this->argument('file') ?? self::FEED_URL;
        $runAt = CarbonImmutable::now();
        $created = $updated = $skipped = 0;

        try {
            DB::beginTransaction();
            $n = 0;
            foreach ($reader->read($source) as $situation) {
                try {
                    $mapped = $mapper->map($situation);
                    if ($mapped === null) {
                        $skipped++;

                        continue;
                    }
                    $isNew = $upserter->upsert(
                        'DATEX',
                        $mapped->sourceId,
                        [
                            'kind' => $mapped->kind,
                            'severity' => $mapped->severity,
                            'status' => $mapped->status,
                            'hindrance' => $mapped->hindrance,
                            'road_authority' => $mapped->roadAuthority,
                            'start_date' => $mapped->startDate,
                            'end_date' => $mapped->endDate,
                        ],
                        $mapped->point,
                        $mapped->document,
                        $runAt,
                    );
                    $isNew ? $created++ : $updated++;
                } catch (Throwable $e) {
                    $skipped++;
                    $this->warn("Skipped a situation: {$e->getMessage()}");
                }

                if (++$n % 500 === 0) {
                    DB::commit();
                    DB::beginTransaction();
                }
            }
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error("Import failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->table(['Created', 'Updated', 'Skipped'], [[$created, $updated, $skipped]]);

        return self::SUCCESS;
    }
}
