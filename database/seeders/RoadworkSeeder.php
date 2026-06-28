<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Buurt;
use App\Roadworks\RoadworkUpserter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Seeds a small, deterministic set of roadworks so the Home, listing and detail
 * pages render with realistic data in CI (Core Web Vitals monitoring).
 *
 * Goes through RoadworkUpserter — the same path every import uses — so each row
 * gets its slug synced and its administrative areas linked (via the queued
 * RoadworkSaved listener, which runs inline when QUEUE_CONNECTION=sync).
 */
class RoadworkSeeder extends Seeder
{
    /**
     * Geometry shared by all seeded roadworks; sits inside the area polygons the
     * factories generate (POLYGON((0 0,1 0,1 1,0 1,0 0))) so spatial linking hits.
     *
     * @var array{type: string, coordinates: array{float, float}}
     */
    private const POINT = ['type' => 'Point', 'coordinates' => [0.5, 0.5]];

    public function run(): void
    {
        // One full area chain (Buurt -> Wijk -> Gemeente -> Provincie -> Landsdeel)
        // covering the shared point, so every roadwork links to all five levels.
        Buurt::factory()->create();

        $upserter = app(RoadworkUpserter::class);
        $now = CarbonImmutable::now();

        foreach ($this->roadworks($now) as $index => $rw) {
            $upserter->upsert(
                source: 'SEED',
                sourceId: 'seed-'.($index + 1),
                promoted: [
                    'severity' => $rw['severity'],
                    'status' => 'running',
                    'activity_type' => $rw['activity_type'],
                    'published' => true,
                    'road_authority' => $rw['road_authority'],
                    'start_date' => $rw['start_date'],
                    'end_date' => $rw['end_date'],
                ],
                point: self::POINT,
                document: [
                    'situation' => [
                        'id' => 'seed-'.($index + 1),
                        'type' => 'Feature',
                        'geometry' => self::POINT,
                        'properties' => [
                            'situationId' => 'seed-'.($index + 1),
                            'type' => 'SITUATION',
                            'causeDescription' => $rw['cause'],
                            'causeType' => 'roadMaintenance',
                        ],
                    ],
                    'restrictions' => [],
                    'detours' => [],
                ],
                seenAt: $now,
            );
        }
    }

    /**
     * @return list<array{severity: string, activity_type: string, road_authority: string, cause: string, start_date: string, end_date: string}>
     */
    private function roadworks(CarbonImmutable $now): array
    {
        $rows = [
            ['high', 'Asfalteren', 'Gemeente Utrecht', 'Werkzaamheden aan de weg, Asfalt vervangen'],
            ['medium', 'Riolering', 'Gemeente Amsterdam', 'Vervangen riool, Rioolwerkzaamheden'],
            ['low', 'Glasvezel', 'Gemeente Rotterdam', 'Aanleg glasvezel, Fiber'],
            ['medium', 'Drinkwater', 'Gemeente Den Haag', 'Vervangen waterleiding, Drinkwater'],
            ['high', 'Brugonderhoud', 'Provincie Noord-Holland', 'Onderhoud brug, Brug en kade'],
            ['low', 'Groenonderhoud', 'Gemeente Eindhoven', 'Snoeien groen, Boom verwijderen'],
            ['medium', 'Kabels', 'Gemeente Groningen', 'Vervangen kabels, Elektra'],
            ['high', 'Bestrating', 'Gemeente Tilburg', 'Herstraten wegdek, Klinkers'],
        ];

        return array_map(static fn (array $r): array => [
            'severity' => $r[0],
            'activity_type' => $r[1],
            'road_authority' => $r[2],
            'cause' => $r[3],
            'start_date' => $now->subDays(7)->format(DATE_ATOM),
            'end_date' => $now->addDays(21)->format(DATE_ATOM),
        ], $rows);
    }
}
