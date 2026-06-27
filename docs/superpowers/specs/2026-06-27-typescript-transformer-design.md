# TypeScript Transformer v3 — typed controller→Inertia seams

**Date:** 2026-06-27
**Branch:** `feat/seo-slug-urls` (or a fresh `feat/typescript-transformer`)
**Status:** Approved design, pending spec review

## Goal

Make every controller→Inertia→Vue seam typed by a single source of truth: the
`spatie/laravel-data` `Data` class. PHP DTO is authored once; the matching
TypeScript type is generated, never hand-written.

This branch delivers the tooling plus migration of the two existing seams. It is
the first step toward typing *all* seams; future Data classes are typed
automatically with zero extra work.

## Current state

- Project already uses `spatie/laravel-data` v4.23. Frontend-seam DTOs exist:
  - `App\Roadworks\Data\ProjectDetail` → `ProjectController` → `Projecten/Show.vue`
  - `App\Roadworks\Data\RoadworkCard` → `HomeController` → `Home.vue`
- Other `Data` classes are domain types, **not** frontend seams, and stay where
  they are: `App\Roadworks\Data\RoadworkDocument` (a `Roadwork::feature` DB cast)
  and `App\Melvin\Data\Feature` (ingestion shape). Auto-collect still generates
  TS for them — harmless — under their own namespaces.
- Frontend hand-duplicates these shapes as local TS interfaces. They have already
  drifted: the `ProjectDetail` Data class exposes `slug`, the frontend interface
  does not. This drift is exactly what the transformer eliminates.
- `tsconfig.json` already globs `resources/js/**/*.d.ts`, so a generated `.d.ts`
  in `resources/js/types/` is picked up globally with no import.

## Version decision

`spatie/laravel-typescript-transformer` **v3** (per user requirement).

The `spatie/laravel-data` package itself ships only a **v2** integration, but the
**TT v3 laravel package** (`spatie/laravel-typescript-transformer` v3) ships its
own laravel-data integration —
`Spatie\LaravelTypeScriptTransformer\LaravelData\Transformers\DataClassTransformer`
— which matches every `BaseData` class and carries the right property processors
(laravel-data attributes + array-shape fixing). So **no custom transformer is
needed**; we register the shipped one. (Confirmed by reading installed v3 source.)

## Components

### 0. Relocate frontend DTOs to `App\Data`

Consolidate the controller→Inertia DTOs under one `App\Data` namespace (spatie
convention; yields the clean `App.Data.*` TS namespace).

- Move `app/Roadworks/Data/ProjectDetail.php` → `app/Data/ProjectDetail.php`,
  namespace `App\Data`.
- Move `app/Roadworks/Data/RoadworkCard.php` → `app/Data/RoadworkCard.php`,
  namespace `App\Data`.
- Update references: `App\Http\Controllers\ProjectController` (imports
  `ProjectDetail`) and `App\Http\Controllers\HomeController` (imports
  `RoadworkCard`). No other code references these two (tests assert via Inertia
  props, not the class).
- Leave `RoadworkDocument` and `Melvin\Data\Feature` untouched (domain types).

### 1. Install + scaffold

```
composer require --dev spatie/laravel-typescript-transformer:^3.0   # done
php artisan typescript:install   # publishes provider stub + registers in bootstrap/providers.php
```

`typescript:install` creates `App\Providers\TypeScriptTransformerServiceProvider`
and registers it in `bootstrap/providers.php`. No custom transformer class is
written — the shipped laravel-data `DataClassTransformer` is used.

### 2. Configure `App\Providers\TypeScriptTransformerServiceProvider`

Edit the scaffolded `configure()`: register the shipped laravel-data
`DataClassTransformer` (auto-matches every `BaseData` subclass — the
auto-collect-all choice), output to `resources/js/types`, drop the formatter.

```php
use Spatie\LaravelTypeScriptTransformer\LaravelData\Transformers\DataClassTransformer;
use Spatie\TypeScriptTransformer\Transformers\AttributedClassTransformer;
use Spatie\TypeScriptTransformer\Transformers\EnumTransformer;
use Spatie\TypeScriptTransformer\Writers\GlobalNamespaceWriter;

protected function configure(TypeScriptTransformerConfigFactory $config): void
{
    $config
        ->transformer(new DataClassTransformer())        // all BaseData → TS
        ->transformer(AttributedClassTransformer::class) // #[TypeScript] opt-in for non-Data
        ->transformer(EnumTransformer::class)
        ->transformDirectories(app_path())
        ->outputDirectory(resource_path('js/types'))
        ->writer(new GlobalNamespaceWriter('generated.d.ts'));
    // No formatter this branch — see "Formatting" below.
}
```

`transformDirectories(app_path())` scans all of `app/`, so every `BaseData`
subclass (`App\Data\*`, plus `RoadworkDocument`, `Melvin\Data\Feature`) is
generated under its own namespace. `outputDirectory` overrides the stub default
of `resource_path('js/generated')`.

Output shape:

```ts
declare namespace App.Data {
    export type ProjectDetail = {
        id: number;
        reference: string;
        title: string;
        // …
        slug: string | null;
    };
}
```

Generation command: `php artisan typescript:transform`.

### 4. Vite plugin — `resources/js/vite-plugin-typescript-transform.ts`

Modeled directly on `@laravel/vite-plugin-wayfinder` (read its source for the
pattern):

- `enforce: 'pre'`, `buildStart()` → `exec('php artisan typescript:transform')`.
- `handleHotUpdate({ file })` → re-run when a watched file changes. Pattern:
  `app/**/Data/**/*.php` (and any dir holding Data classes), matched with
  `minimatch` exactly as wayfinder does.
- Wire into `vite.config.ts` plugins array alongside `wayfinder(...)`.

`minimatch` is already an implicit dep via wayfinder; add it explicitly to
`devDependencies` if the import is not resolvable.

### 5. Migrate the two seams

- `resources/js/pages/Home.vue`: delete local `interface ProjectCard`; type the
  prop as `App.Data.RoadworkCard[]`.
- `resources/js/pages/Projecten/Show.vue`: delete local `interface ProjectDetail`;
  type the prop as `App.Data.ProjectDetail`. Drops the `slug` drift.
- Update child components that imported the local `ProjectDetail` interface (e.g.
  `Show.vue` exports it) to reference the global type.

### 6. Array-shape annotation in `RoadworkCard`

`public array $badge` / `public array $meta` carry `@phpstan-type` shapes the base
transformer ignores (emits loose `Array`/`any`). Annotate each promoted property
so generated types are precise — inline `/** @var array{label: string, class:
string} */` on the property, or `#[LiteralTypeScriptType('{ label: string; class:
string }')]`. Implementation picks whichever the installed v3 supports; precise
shapes for `badge` and `meta` are the requirement.

## Formatting (deferred)

User wants Biome to format the generated file. Biome is **not** in the project
(currently prettier + eslint) and adopting it is a separate, repo-wide effort
tracked as its own spec/branch. This branch ships **no formatter** — the file is
gitignored and regenerated on every build, so formatting it has no value yet.
When Biome lands, add a `BiomeFormatter` (TT `Formatter` shelling
`npx biome format --write`) to the service provider.

## Generated file handling

`resources/js/types/generated.d.ts` is **gitignored** (treated like wayfinder
output). CI runs `php artisan typescript:transform` before `vue-tsc --noEmit` so
the type check sees fresh types. Add the generate step to the relevant composer
scripts (`dev`, `fix`) and to `ci:check` ahead of `types:check`.

## Testing

Per CLAUDE.md every change is programmatically tested.

- **PHP feature test** (`tests/Feature/TypeScript/GenerateTypesTest.php`):
  run `Artisan::call('typescript:transform')`, read the output file, assert it
  contains `ProjectDetail` with `slug`, and `RoadworkCard` with the precise
  `badge`/`meta` shapes. Use a temp output dir to avoid clobbering the dev file.
- **Frontend type check**: `npm run types:check` (`vue-tsc --noEmit`) proves the
  migrated pages consume the global types correctly. Runs against freshly
  generated types.

## Out of scope

- Adopting Biome project-wide (separate spec).
- Typing seams beyond Home/Show (happens automatically as new Data classes are
  added; no further wiring needed).
- Wayfinder changes.

## Risks / open items

- v3 API surface confirmed against installed source: `typescript:install` +
  `typescript:transform` commands, shipped `DataClassTransformer`, config factory
  (`transformer`/`transformDirectories`/`outputDirectory`/`writer`),
  `GlobalNamespaceWriter('generated.d.ts')`.
- `RoadworkCard` `badge`/`meta` shapes: the shipped `DataClassPropertyProcessor`
  reads property/constructor docblocks. Verify during implementation whether the
  existing `@phpstan-type Badge` aliases resolve; if they emit loose `array`/`any`,
  add inline `/** @var array{...} */` on the promoted properties. The generation
  test asserts the precise shapes, so this is caught automatically.
