# CBS gebiedsindelingen importer

Import the Dutch CBS administrative area hierarchy — landsdeel → provincie →
gemeente → wijk → buurt — from the official CBS "gebiedsindelingen" download, with
parent/child relations resolved from the data.

## Source data

- Page: <https://www.cbs.nl/nl-nl/dossier/nederland-regionaal/geografische-data/cbs-gebiedsindelingen>
- Download: `https://geodata.cbs.nl/files/Gebiedsindelingen/cbsgebiedsindelingen2016_heden.zip`
  (~591 MB). Unzips to one GeoPackage **per year**: `cbsgebiedsindelingen<YEAR>.gpkg`.
- Each gpkg holds many layers. We use the five `_gegeneraliseerd` (cartographically
  simplified) MultiPolygon layers:

  | layer | features (2024) | attributes |
  |---|---|---|
  | `landsdeel_gegeneraliseerd` | 4 | `statcode`, `statnaam` |
  | `provincie_gegeneraliseerd` | 12 | `statcode`, `statnaam` |
  | `gemeente_gegeneraliseerd` | 342 | `statcode`, `statnaam` |
  | `wijk_gegeneraliseerd` | 3393 | `statcode`, `statnaam`, `gm_code` |
  | `buurt_gegeneraliseerd` | 14574 | `statcode`, `statnaam`, `gm_code` |

- Codes (`statcode`): `LD01`, `PV20`, `GM0034`, `WK003401`, `BU00340101`.
- Source SRS is **EPSG:28992** (Amersfoort / RD New); reprojected to **EPSG:4326**
  on load to match the existing `roadworks.coordinates` convention.
- **Year selected: latest *complete* year = 2024.** 2025 is absent from the archive
  and 2026 is provisional (`voorlopig`) with no wijk/buurt layers. The importer
  defaults to 2024 but accepts `--year`.

## Storage — table per level

Five tables, mirroring roadworks PostGIS conventions (geometry column, GiST index).
These are reference data, replaced wholesale on reimport — **no temporal trigger**.

| table | columns |
|---|---|
| `landsdelen` | `id`, `code` (unique), `name`, `year`, `geometry geometry(MultiPolygon,4326)` |
| `provincies` | + `landsdeel_id` (FK, nullable) |
| `gemeenten` | + `provincie_id` (FK, nullable) |
| `wijken` | + `gemeente_id` (FK) |
| `buurten` | + `wijk_id` (FK, nullable), `gemeente_id` (FK) |

Indexes: GiST on each `geometry`; unique on `code`; index on each FK.
Parent FKs are nullable so an unresolved parent never aborts the import.

## Parent resolution — codes first, spatial fallback

- **buurt → wijk**: from code. `BU00340101` → wijk `WK003401` (drop `BU`, take the
  first 6 digits, prepend `WK`). Match on `wijken.code`.
- **wijk → gemeente**: `gm_code` attribute (e.g. `GM0034`) → `gemeenten.code`.
- **buurt → gemeente**: `gm_code` attribute (kept directly for convenient queries).
- **gemeente → provincie**: **spatial.** No code link exists. Match the gemeente's
  representative point (`ST_PointOnSurface(geometry)`) inside a provincie polygon via
  `ST_Contains`.
- **provincie → landsdeel**: **spatial**, same technique (provincie point in landsdeel).

## Importer

Command `cbs:import:areas`:

```
cbs:import:areas {file? : path to .zip or .gpkg}
                 {--year=2024 : year layer set to import}
                 {--download : fetch the 591 MB archive when no file is given}
```

- A `file` path is required by default. Passing `--download` (no `file`) fetches and
  unzips the archive to a temp dir, then picks `<year>.gpkg`. This avoids a surprise
  591 MB download on a routine run.
- Steps (one DB transaction):
  1. Resolve the `.gpkg` (from `file`, an unzipped `file.zip`, or `--download`).
  2. For each of the five layers, `ogr2ogr` it into a Postgres **staging** table
     (`-t_srs EPSG:4326 -nlt MULTIPOLYGON -lco GEOMETRY_NAME=geometry`, overwrite).
     ogr2ogr performs the bulk geometry load — no per-feature PHP.
  3. Truncate the five final tables, then insert top-down (landsdeel → buurt),
     resolving parents per the rules above (spatial joins for gemeente/provincie).
  4. Drop staging tables. Report inserted counts and unresolved-parent counts per level.

A thin command delegates to a `CbsAreaImporter` service that owns the staging-load
shell-out and the transform SQL — matching the `ImportMelvinRoadworks` →
`RoadworksImporter` split.

## Models

`Landsdeel`, `Provincie`, `Gemeente`, `Wijk`, `Buurt` (Eloquent), with the tree wired
via `hasMany`/`belongsTo`:
`Landsdeel hasMany Provincie hasMany Gemeente hasMany Wijk hasMany Buurt`.
Each model exposes a `withGeoJson` scope (`ST_AsGeoJSON(geometry)`) like
`Roadwork::withCoordinatesGeoJson`. Factories for all five.

## Testing

- A tiny fixture GeoPackage (a handful of features per level, covering at least one
  spatial gemeente→provincie and provincie→landsdeel containment) committed under
  `tests/Fixtures/cbs/`. Feature test runs `cbs:import:areas` against it and asserts:
  per-level counts, code-based links (buurt→wijk, wijk→gemeente), and spatial links
  (gemeente→provincie, provincie→landsdeel).
- A schema test (à la `RoadworksSchemaTest`) asserting the five tables, geometry
  column type/SRID, GiST + unique indexes, and FK constraints.

## Out of scope

- Years other than the single `--year` snapshot (no per-year history table).
- Non-simplified (`niet_gegeneraliseerd`) polygons and the other 28 CBS divisions
  (COROP, GGD-regio, etc.).
- Any frontend/API surface for the areas — importer + models only.
