# DATEX Planningsfeed Importer — Design

**Date:** 2026-06-18
**Status:** Approved (pending spec review)

## Goal

Import the NDW open-data DATEX II feed
`planningsfeed_wegwerkzaamheden_en_evenementen` (road works + events) into the
existing `roadworks` table as an open, auth-free, CC0 data source, alongside the
authenticated Melvin GeoJSON importer. One `Roadwork` model serves both sources.

## Context & decisions

- **Source:** single feed, `https://opendata.ndw.nu/planningsfeed_wegwerkzaamheden_en_evenementen.xml.gz` (~17 MB gz, ~207 MB XML, DATEX II v3 `SituationPublication`). Built cleanly so a second feed can be added later; no speculative multi-feed framework (YAGNI).
- **Licence:** CC0 (NDW default); commercial reuse allowed, attribution courtesy only.
- **Fidelity:** validated — for road works/events the feed matches the Melvin public map (same situations, identical route geometry; detour `gmlLineString` ~95% coverage, median ~30 pts). Attachments are best-effort in the feed.
- **Run model:** `roadworks:import:datex {file?}` — downloads + stream-gunzips by default; accepts a local file for replay/testing.
- **Storage shape:** normalize to the same `{situation, restrictions, detours}` jsonb document as the Melvin importer, so `Roadwork` / `RoadworkDocument` work unchanged.
- **Geometry:** `coordinates` column = the situation point (marker + `nearby`); detour/restriction polylines stored as GeoJSON `LineString` in `feature` jsonb. Promote a spatial line column only if a future feature needs it.
- **Staleness:** never delete (content archive). Track `first_seen_at` / `last_seen_at`. "Live now" = present in the latest run and/or period not ended.

## Components

Each unit has one purpose, a clear interface, and is testable in isolation.

1. **`App\Console\Commands\ImportDatexRoadworks`** — command `roadworks:import:datex {file?}`. Orchestrates: open source → iterate situations → map → upsert in batches → print summary (created/updated/skipped/total). Stamps a single run timestamp used for `last_seen_at`.

2. **`App\Roadworks\Datex\DatexFeedReader`** — opens the feed (`.gz` URL stream or local file), gunzips on the fly (`zlib.inflate`/gzopen stream filter), wraps `XMLReader`, and **yields one `<sit:situation>` element at a time** as `SimpleXMLElement`. Constant memory regardless of file size. Interface: `read(string $urlOrPath): \Generator<\SimpleXMLElement>`.

3. **`App\Roadworks\Datex\DatexSituationMapper`** — pure mapper, no I/O. One situation → normalized result:
   - Buckets `situationRecord`s by `xsi:type`:
     - `MaintenanceWorks` / `ConstructionWorks` / `PublicEvent` → **situation** (primary)
     - `RoadOrCarriagewayOrLaneManagement` / `SpeedManagement` → **restrictions**
     - `ReroutingManagement` → **detours**
   - Builds the `{situation, restrictions, detours}` jsonb document (records kept near-verbatim as arrays, incl. `attachments` parsed from `urlLinkAddress` + `urlLinkDescription`).
   - Converts geometry: `loc:pointByCoordinates` → GeoJSON `Point`; `loc:gmlLineString/posList` → GeoJSON `LineString`, swapping DATEX `lat lon` → `[lon, lat]`; dedupes geometry repeated across records.
   - Extracts promoted fields (see schema).
   - Returns `null` to signal "skip" (e.g. `informationStatus != 'real'`).
   - Interface: `map(\SimpleXMLElement $situation): ?MappedRoadwork`.

4. **`App\Roadworks\RoadworkUpserter`** (shared) — refactored out of the existing `RoadworksImporter` so both importers use one upsert path. Upserts on `(source, source_id)` via `INSERT ... ON CONFLICT DO UPDATE ... RETURNING (xmax = 0)`, point geometry via `ST_SetSRID(ST_GeomFromGeoJSON(?),4326)`, sets `last_seen_at = :run`, `first_seen_at = COALESCE(roadworks.first_seen_at, :run)`. Returns created/updated. The Melvin `RoadworksImporter` is updated to call it (no behaviour change).

## Data flow

```
command
  └─ DatexFeedReader.read(urlOrPath)         # streaming, yields situations
       └─ DatexSituationMapper.map(sit)      # → MappedRoadwork | null(skip)
            └─ RoadworkUpserter.upsert(...)   # batched in a DB transaction (~500/tx)
  └─ summary: created / updated / skipped / total
```

`source = 'DATEX'`, `source_id = <sit:situation id>` (e.g. `NDW03_582497`, `RWS01_SM1065233_D2`).

## Schema (edit existing create-table migration)

We are pre-launch in dev, so **edit `2026_06_18_060100_create_roadworks_table`** rather than adding an ALTER migration. Because `roadworks_history` is created with `LIKE roadworks` *after* the columns are defined, it inherits them automatically — no dual-maintenance.

Columns added to `roadworks` (all nullable; Melvin importer leaves DATEX-only ones null):

| Column | Type | Source (DATEX) |
|---|---|---|
| `kind` | varchar | record type / `causeType` (works vs events) |
| `severity` | varchar | `overallSeverity` |
| `hindrance` | varchar | `nle:roadworkHindranceClass` |
| `road_authority` | varchar | `source/sourceName` |
| `start_date` | timestamptz | `validity/overallStartTime` |
| `end_date` | timestamptz | `validity/overallEndTime` |
| `first_seen_at` | timestamptz | import run |
| `last_seen_at` | timestamptz | import run |

Existing columns reused: `source, source_id, status, activity_type, published, coordinates, feature, sys_period`. `status` ← `nle:roadworkStatus`/`roadworkPlanningStatus`.

Indexes added: `kind`, `severity`, `(start_date, end_date)`, `last_seen_at`.

## Error handling

- Per-situation `try/catch` → log and skip a malformed situation; never abort the whole run.
- `informationStatus != 'real'` (test records) → skip.
- Defensive mapping: missing field → `null`.
- Batched transactions (~500 situations) isolate failures and bound memory.

## Testing

PostGIS (`ST_GeomFromGeoJSON`), `tstzrange`, and the temporal trigger require **pgsql** — sqlite cannot run these migrations, so feature tests run against the sail/pgsql test database.

- **Fixture:** small committed XML (`tests/Fixtures/datex/sample.xml`) with 3–4 situations: a `MaintenanceWorks` with a rerouting (`gmlLineString`), a `PublicEvent`, and an attachment case.
- **Asserts:**
  - situation upserted with `source='DATEX'`, `source_id`, promoted columns populated, `coordinates` point set;
  - detour `LineString` present in `feature` jsonb with `[lon,lat]` order;
  - re-import is idempotent (no duplicate row);
  - a changed re-import writes a row into `roadworks_history`;
  - non-`real` record skipped.
- **Unit:** `DatexSituationMapper` tested directly on fixture snippets (no DB) for bucketing + geometry conversion.

## Out of scope

- Other feeds (brugopeningen, vaarweg, rail).
- Authenticated Melvin attachment fetching.
- A spatial geometry column for routes (jsonb suffices for rendering).
- Scheduling config (command is cron-ready; scheduling is a deploy concern).
