# Relate roadworks to CBS areas

Link each roadwork to the CBS areas it falls in, at all five levels (landsdeel,
provincie, gemeente, wijk, buurt), as many-to-many pivots kept current on every
roadwork upsert.

## Membership

Spatial **intersect** against the true situation geometry, not the reduced point:
`ST_Intersects(area.geometry, ST_GeomFromGeoJSON(feature->'situation'->'geometry'))`.
The promoted `roadworks.coordinates` column is always a Point; the real geometry
(LineString/Polygon in future Melvin data) lives in `feature` jsonb. A point lands
in one area per level; a line links to every area it crosses. Each level is resolved
independently — a line spanning two gemeenten in different provincies links to both.

A roadwork with no situation geometry gets no links (its rows are cleared).

## Pivot tables

Migration `create_roadwork_area_pivots`, five tables:
`roadwork_landsdeel`, `roadwork_provincie`, `roadwork_gemeente`, `roadwork_wijk`,
`roadwork_buurt`. Each: `(roadwork_id, <area>_id)` composite primary key, both FKs
`ON DELETE CASCADE`, index on the area id. The area `TRUNCATE … RESTART IDENTITY
CASCADE` in `CbsAreaImporter` cascades to these, clearing links on an area refresh —
the backfill command rebuilds them.

## Models

`Roadwork` gains five `belongsToMany` relations (explicit pivot table + keys). The
`IsCbsArea` trait adds the reverse `roadworks()` to all five area models.

## Per-level actions

One invokable action per level, sharing an abstract base:

- `App\Actions\LinkRoadworkLandsdelen`
- `App\Actions\LinkRoadworkProvincies`
- `App\Actions\LinkRoadworkGemeenten`
- `App\Actions\LinkRoadworkWijken`
- `App\Actions\LinkRoadworkBuurten`

Base `App\Actions\LinkRoadworkToArea` defines `__invoke(int $roadworkId): void`:
delete the roadwork's rows in the level's pivot, then `INSERT … SELECT a.id FROM
<areaTable> a WHERE ST_Intersects(a.geometry, <situation geom for that roadwork>)`.
Each subclass declares its area table, pivot table, and area FK column. Idempotent.

## On-upsert flow

`RoadworkUpserter` dispatches `App\Events\RoadworkSaved(int $roadworkId)` after every
successful upsert (insert or update — links stay correct when a roadwork moves;
idempotent so re-imports are safe). `App\Listeners\LinkRoadworkAreas` (queued, so bulk
imports are not blocked; runs inline under the sync queue in tests) invokes the five
actions for that roadwork.

## Backfill command

`roadworks:link-areas` chunks all roadworks and invokes the five actions for each
(progress bar). Required to link the existing ~12k rows and to rebuild links after an
area refresh. Per-row keeps each `ST_GeomFromGeoJSON(jsonb)` to a single row — a
full-table parse times out.

## Testing

- Schema: five pivot tables, composite PK, cascade delete.
- Actions: a point inside a buurt links the roadwork at all five levels; a LineString
  crossing two buurten links both buurten and their parent gemeenten; null situation
  geometry clears links. (`RefreshDatabase` — no external process.)
- `RoadworkUpserter` dispatches `RoadworkSaved`.
- Listener → actions end-to-end: upserting a roadwork over seeded areas creates links.
- Command backfills all roadworks.

## Out of scope

- Surfacing area links in the Meilisearch document or search API.
- Gating dispatch on geometry change (every upsert re-links; idempotent).
