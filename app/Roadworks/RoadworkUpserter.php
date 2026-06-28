<?php

declare(strict_types=1);

namespace App\Roadworks;

use App\Events\RoadworkSaved;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Single upsert path for roadworks rows, shared by every import source.
 * Upserts content on (source, source_id) — the temporal trigger records history
 * only on genuine content changes. Feed-presence timestamps are tracked in the
 * non-versioned `roadwork_seen` table so re-imports don't churn history.
 */
final readonly class RoadworkUpserter
{
    public function __construct(private RoadworkSlugSynchronizer $slugs) {}

    private const array PROMOTED = [
        'kind', 'severity', 'status', 'hindrance', 'activity_type',
        'published', 'road_authority', 'start_date', 'end_date',
    ];

    /**
     * @param  array<string, mixed>  $promoted  subset of self::PROMOTED
     * @param  array<string, mixed>|null  $point  GeoJSON Point, or null
     * @param  array<string, mixed>  $document  the feature jsonb document
     * @return bool true if a new row was inserted, false if an existing row was updated
     */
    public function upsert(string $source, string $sourceId, array $promoted, ?array $point, array $document, DateTimeInterface $seenAt): bool
    {
        $vals = [];
        foreach (self::PROMOTED as $c) {
            $vals[$c] = $promoted[$c] ?? null;
        }
        $seen = $seenAt->format(DateTimeInterface::ATOM);

        $pointExpr = $point === null ? 'NULL' : 'ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)';

        $bindings = [
            $source, $sourceId,
            $vals['kind'], $vals['severity'], $vals['status'], $vals['hindrance'],
            $vals['activity_type'],
            $vals['published'] === null ? null : ($vals['published'] ? 'true' : 'false'),
            $vals['road_authority'], $vals['start_date'], $vals['end_date'],
        ];
        if ($point !== null) {
            $bindings[] = json_encode($point, JSON_THROW_ON_ERROR);
        }
        $bindings[] = json_encode($document, JSON_THROW_ON_ERROR);

        $row = DB::selectOne(
            <<<SQL
                INSERT INTO roadworks
                    (source, source_id, kind, severity, status, hindrance, activity_type,
                     published, road_authority, start_date, end_date,
                     coordinates, feature)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?::boolean, ?, ?::timestamptz, ?::timestamptz,
                     {$pointExpr}, ?::jsonb)
                ON CONFLICT (source, source_id) DO UPDATE SET
                    kind=EXCLUDED.kind, severity=EXCLUDED.severity, status=EXCLUDED.status,
                    hindrance=EXCLUDED.hindrance, activity_type=EXCLUDED.activity_type,
                    published=EXCLUDED.published, road_authority=EXCLUDED.road_authority,
                    start_date=EXCLUDED.start_date, end_date=EXCLUDED.end_date,
                    coordinates=EXCLUDED.coordinates, feature=EXCLUDED.feature
                RETURNING id, (xmax = 0) AS inserted
                SQL,
            $bindings,
        );

        // Feed-presence tracking lives outside the versioned table (no trigger),
        // so bumping last_seen_at on every import never churns history.
        DB::statement(
            <<<'SQL'
                INSERT INTO roadwork_seen (source, source_id, first_seen_at, last_seen_at)
                VALUES (?, ?, ?::timestamptz, ?::timestamptz)
                ON CONFLICT (source, source_id) DO UPDATE SET last_seen_at = EXCLUDED.last_seen_at
                SQL,
            [$source, $sourceId, $seen, $seen],
        );

        $this->slugs->sync((int) $row->id);

        RoadworkSaved::dispatch((int) $row->id, (bool) $row->inserted);

        return (bool) $row->inserted;
    }
}
