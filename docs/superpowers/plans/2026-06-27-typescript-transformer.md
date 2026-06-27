# TypeScript Transformer v3 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generate TypeScript types from `spatie/laravel-data` DTOs so every controller→Inertia→Vue seam is typed from a single PHP source of truth.

**Architecture:** Install `spatie/laravel-typescript-transformer` v3, register its shipped laravel-data `DataClassTransformer` (auto-types every `BaseData` subclass), write a global-namespace `generated.d.ts`, and consume it in the Vue pages. A vite plugin (wayfinder-style) regenerates on build/HMR.

**Tech Stack:** PHP 8.3+, Laravel 13, spatie/laravel-data v4, spatie/laravel-typescript-transformer v3, Inertia v3 + Vue 3, Vite, vue-tsc.

## Global Constraints

- TT version: `spatie/laravel-typescript-transformer:^3.0` (already installed).
- Frontend-seam DTOs live in `App\Data` namespace (`app/Data/`). Domain Data classes (`RoadworkDocument`, `Melvin\Data\Feature`) stay put.
- Generated file: `resources/js/types/generated.d.ts`, global namespace via `GlobalNamespaceWriter('generated.d.ts')`, output dir `resource_path('js/types')`.
- No formatter this branch (Biome adoption is a separate effort).
- `generated.d.ts` is gitignored.
- After editing any PHP file, run `vendor/bin/pint --dirty --format agent`.
- Use the real binaries (shell aliases are broken): `/opt/homebrew/bin/php`, `/opt/homebrew/bin/composer`. Run npm via `/opt/homebrew/bin/npm` if the `npm` alias misbehaves.
- Tests are PHPUnit. Run with `/opt/homebrew/bin/php artisan test --compact`.

---

### Task 1: Relocate frontend DTOs to `App\Data`

Move the two controller→Inertia DTOs into one `App\Data` namespace; domain Data classes are untouched.

**Files:**
- Move: `app/Roadworks/Data/ProjectDetail.php` → `app/Data/ProjectDetail.php`
- Move: `app/Roadworks/Data/RoadworkCard.php` → `app/Data/RoadworkCard.php`
- Modify: `app/Http/Controllers/ProjectController.php` (import)
- Modify: `app/Http/Controllers/HomeController.php` (import)
- Test (existing, must stay green): `tests/Feature/Roadworks/ProjectPageTest.php`

**Interfaces:**
- Produces: `App\Data\ProjectDetail` (same constructor + `fromModel(Roadwork): self` as before), `App\Data\RoadworkCard` (same `fromModel` + `collect`). Public shapes unchanged — only the namespace moves.

- [ ] **Step 1: Move the two DTO files and rename their namespace**

```bash
mkdir -p app/Data
git mv app/Roadworks/Data/ProjectDetail.php app/Data/ProjectDetail.php
git mv app/Roadworks/Data/RoadworkCard.php app/Data/RoadworkCard.php
```

In both moved files change the namespace line:

```php
// app/Data/ProjectDetail.php  and  app/Data/RoadworkCard.php
namespace App\Data;
```

Leave all other `use` statements (`App\Models\Roadwork`, `App\Roadworks\RoadworkTitle`, `Carbon\CarbonImmutable`, `Spatie\LaravelData\Data`) unchanged — they are already fully-qualified imports.

- [ ] **Step 2: Update the two controllers' imports**

`app/Http/Controllers/ProjectController.php`:

```php
use App\Data\ProjectDetail;
```

`app/Http/Controllers/HomeController.php`:

```php
use App\Data\RoadworkCard;
```

- [ ] **Step 3: Confirm no stale references remain**

Run: `grep -rn "Roadworks\\\\Data\\\\ProjectDetail\|Roadworks\\\\Data\\\\RoadworkCard\|Roadworks/Data/ProjectDetail\|Roadworks/Data/RoadworkCard" app tests database`
Expected: no output (RoadworkDocument refs are fine — different class).

- [ ] **Step 4: Format**

Run: `vendor/bin/pint --dirty --format agent`
Expected: clean.

- [ ] **Step 5: Run the affected tests**

Run: `/opt/homebrew/bin/php artisan test --compact tests/Feature/Roadworks/ProjectPageTest.php`
Expected: PASS (DTO public shape unchanged; only namespace moved).

- [ ] **Step 6: Commit**

```bash
git add app/Data app/Http/Controllers/ProjectController.php app/Http/Controllers/HomeController.php
git commit -m "refactor: move frontend DTOs to App\\Data namespace"
```

---

### Task 2: Install transformer config + generation test

Scaffold the provider, point it at the shipped laravel-data transformer, gitignore the output, and lock the behaviour with a test that asserts the generated types.

**Files:**
- Create: `app/Providers/TypeScriptTransformerServiceProvider.php` (via `typescript:install`, then edited)
- Modify: `bootstrap/providers.php` (auto-registered by install command)
- Modify: `.gitignore`
- Create: `tests/Feature/TypeScript/GenerateTypesTest.php`

**Interfaces:**
- Produces: artisan command `typescript:transform` writing `resources/js/types/generated.d.ts` containing `declare namespace App.Data { export type ProjectDetail = {...}; export type RoadworkCard = {...}; }`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TypeScript/GenerateTypesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\TypeScript;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class GenerateTypesTest extends TestCase
{
    public function test_it_generates_typescript_for_frontend_dtos(): void
    {
        $output = resource_path('js/types/generated.d.ts');

        if (file_exists($output)) {
            unlink($output);
        }

        $exit = Artisan::call('typescript:transform');

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertFileExists($output);

        $contents = (string) file_get_contents($output);

        $this->assertStringContainsString('namespace App.Data', $contents);
        $this->assertStringContainsString('ProjectDetail', $contents);
        $this->assertStringContainsString('RoadworkCard', $contents);
        // slug field proves the DTO is the source of truth (fixes prior drift):
        $this->assertStringContainsString('slug', $contents);
        // precise badge array shape, not a loose `any`/`array`:
        $this->assertStringContainsString('label', $contents);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/homebrew/bin/php artisan test --compact --filter=GenerateTypesTest`
Expected: FAIL — `typescript:transform` returns non-zero with "TypeScript Transformer is not configured. Run `php artisan typescript:install` first."

- [ ] **Step 3: Scaffold the provider**

Run: `/opt/homebrew/bin/php artisan typescript:install`
Expected: publishes `app/Providers/TypeScriptTransformerServiceProvider.php` and registers it in `bootstrap/providers.php`.

- [ ] **Step 4: Configure the provider**

Replace the body of `app/Providers/TypeScriptTransformerServiceProvider.php` with:

```php
<?php

namespace App\Providers;

use Spatie\LaravelTypeScriptTransformer\LaravelData\Transformers\DataClassTransformer;
use Spatie\LaravelTypeScriptTransformer\TypeScriptTransformerApplicationServiceProvider as BaseTypeScriptTransformerServiceProvider;
use Spatie\TypeScriptTransformer\Transformers\AttributedClassTransformer;
use Spatie\TypeScriptTransformer\Transformers\EnumTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;
use Spatie\TypeScriptTransformer\Writers\GlobalNamespaceWriter;

class TypeScriptTransformerServiceProvider extends BaseTypeScriptTransformerServiceProvider
{
    protected function configure(TypeScriptTransformerConfigFactory $config): void
    {
        $config
            ->transformer(new DataClassTransformer())
            ->transformer(AttributedClassTransformer::class)
            ->transformer(EnumTransformer::class)
            ->transformDirectories(app_path())
            ->outputDirectory(resource_path('js/types'))
            ->writer(new GlobalNamespaceWriter('generated.d.ts'));
    }
}
```

- [ ] **Step 5: Gitignore the generated file**

Add to `.gitignore`:

```
/resources/js/types/generated.d.ts
```

- [ ] **Step 6: Format**

Run: `vendor/bin/pint --dirty --format agent`
Expected: clean.

- [ ] **Step 7: Run test to verify it passes**

Run: `/opt/homebrew/bin/php artisan test --compact --filter=GenerateTypesTest`
Expected: PASS.

If the `label` assertion fails (badge emitted as loose `array`/`any`), add an explicit `@var` shape on the promoted properties in `app/Data/RoadworkCard.php` and re-run:

```php
public function __construct(
    public int $id,
    public string $title,
    public string $description,
    /** @var array{label: string, class: string} */
    public array $badge,
    /** @var list<array{icon: string, text: string, class: string}> */
    public array $meta,
    public ?string $authority,
    public string $authorityInitials,
    public ?string $endLabel,
    public ?string $slug,
) {}
```

- [ ] **Step 8: Commit**

```bash
git add app/Providers/TypeScriptTransformerServiceProvider.php bootstrap/providers.php .gitignore tests/Feature/TypeScript/GenerateTypesTest.php app/Data/RoadworkCard.php
git commit -m "feat: generate TypeScript types from laravel-data DTOs"
```

---

### Task 3: Migrate Vue pages to generated globals

Replace the hand-written interfaces with the ambient `App.Data.*` globals. Fixes the `slug` drift.

**Files:**
- Modify: `resources/js/pages/Home.vue`
- Modify: `resources/js/pages/Projecten/Show.vue`

**Interfaces:**
- Consumes: ambient global types `App.Data.RoadworkCard`, `App.Data.ProjectDetail` from `resources/js/types/generated.d.ts` (no import — `tsconfig.json` already globs `resources/js/**/*.d.ts`).

- [ ] **Step 1: Ensure types are generated**

Run: `/opt/homebrew/bin/php artisan typescript:transform`
Expected: writes `resources/js/types/generated.d.ts`.

- [ ] **Step 2: Migrate `Home.vue`**

In `resources/js/pages/Home.vue`, delete the local interface:

```ts
interface ProjectCard {
    id: number;
    title: string;
    description: string;
    badge: { label: string; class: string };
    meta: { icon: string; text: string; class: string }[];
    authority: string | null;
    authorityInitials: string;
    endLabel: string | null;
}
```

and change the props type:

```ts
const props = defineProps<{
    projects: App.Data.RoadworkCard[];
    roadworksTotal: number;
}>();
```

- [ ] **Step 3: Migrate `Show.vue`**

In `resources/js/pages/Projecten/Show.vue`, delete the local interface:

```ts
export interface ProjectDetail {
    id: number;
    reference: string;
    title: string;
    description: string;
    statusLabel: string;
    period: string;
    endLabel: string | null;
    authority: string | null;
    locationLabel: string;
    latitude: number | null;
    longitude: number | null;
}
```

and change the props type:

```ts
const props = defineProps<{ project: App.Data.ProjectDetail }>();
```

- [ ] **Step 4: Type-check the frontend**

Run: `/opt/homebrew/bin/npm run types:check`
Expected: PASS (`vue-tsc --noEmit`, no errors). The globals resolve; `project.slug` etc. are available.

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/Home.vue resources/js/pages/Projecten/Show.vue
git commit -m "refactor: consume generated App.Data types in Vue pages"
```

---

### Task 4: Wire generation into Vite + CI

Auto-regenerate types on build/HMR (wayfinder pattern) and ensure CI generates before type-checking.

**Files:**
- Create: `resources/js/vite-plugin-typescript-transform.ts`
- Modify: `vite.config.ts`
- Modify: `composer.json` (`ci:check` script)

**Interfaces:**
- Consumes: artisan `typescript:transform` from Task 2.
- Produces: vite plugin `typescriptTransform()` running generation on `buildStart` and on `app/**/Data/**/*.php` HMR.

- [ ] **Step 1: Create the vite plugin**

Create `resources/js/vite-plugin-typescript-transform.ts` (modeled on `@laravel/vite-plugin-wayfinder`):

```ts
import { exec } from 'child_process';
import { minimatch } from 'minimatch';
import osPath from 'path';
import type { PluginContext } from 'rollup';
import { promisify } from 'util';
import type { HmrContext, Plugin } from 'vite';

const execAsync = promisify(exec);

interface TypeScriptTransformOptions {
    patterns?: string[];
    command?: string;
}

let context: PluginContext;

export const typescriptTransform = ({
    patterns = ['app/**/Data/**/*.php', 'app/Data/**/*.php'],
    command = 'php artisan typescript:transform',
}: TypeScriptTransformOptions = {}): Plugin => {
    patterns = patterns.map((pattern) => pattern.replace('\\', '/'));

    const runCommand = async () => {
        try {
            await execAsync(command);
            context.info('TypeScript types generated');
        } catch (error) {
            context.error('Error generating TypeScript types: ' + error);
        }
    };

    return {
        name: 'typescript-transform',
        enforce: 'pre',
        buildStart() {
            context = this;
            return runCommand();
        },
        async handleHotUpdate({ file, server }: HmrContext) {
            if (shouldRun(patterns, { file, server })) {
                await runCommand();
            }
        },
    };
};

const shouldRun = (
    patterns: string[],
    opts: Pick<HmrContext, 'file' | 'server'>,
): boolean => {
    const file = opts.file.replaceAll('\\', '/');

    return patterns.some((pattern) => {
        pattern = osPath.resolve(opts.server.config.root, pattern).replaceAll('\\', '/');

        return minimatch(file, pattern);
    });
};
```

- [ ] **Step 2: Register the plugin in `vite.config.ts`**

Add the import and the plugin (place it before `wayfinder(...)`):

```ts
import { typescriptTransform } from './resources/js/vite-plugin-typescript-transform';
```

```ts
        typescriptTransform(),
        wayfinder({
            formVariants: true,
        }),
```

- [ ] **Step 3: Verify `minimatch` resolves**

Run: `/opt/homebrew/bin/node -e "require.resolve('minimatch'); console.log('ok')"`
Expected: `ok`. If it errors with "Cannot find module", run `/opt/homebrew/bin/npm install -D minimatch` and add it to `devDependencies`.

- [ ] **Step 4: Add generation to the CI check**

In `composer.json`, prepend the generate step to `ci:check` so `npm run types:check` sees fresh types:

```json
        "ci:check": [
            "Composer\\Config::disableProcessTimeout",
            "@php artisan typescript:transform",
            "npm run lint:check",
            "npm run format:check",
            "npm run types:check",
            "@test"
        ],
```

- [ ] **Step 5: Verify the build regenerates types**

Run: `rm -f resources/js/types/generated.d.ts && /opt/homebrew/bin/npm run build`
Expected: build succeeds and `resources/js/types/generated.d.ts` exists afterward.

Run: `test -f resources/js/types/generated.d.ts && echo present`
Expected: `present`.

- [ ] **Step 6: Commit**

```bash
git add resources/js/vite-plugin-typescript-transform.ts vite.config.ts composer.json package.json package-lock.json
git commit -m "build: regenerate TypeScript types on vite build and in CI"
```

---

### Task 5: Full verification

- [ ] **Step 1: Run the whole PHP suite**

Run: `/opt/homebrew/bin/php artisan test --compact`
Expected: PASS.

- [ ] **Step 2: Run frontend lint + types**

Run: `/opt/homebrew/bin/npm run lint:check && /opt/homebrew/bin/npm run types:check`
Expected: both PASS.

- [ ] **Step 3: Confirm generated file is gitignored**

Run: `git status --porcelain resources/js/types/generated.d.ts`
Expected: no output (ignored).

---

## Notes for the implementer

- The shipped `DataClassTransformer` matches every `BaseData` subclass, so `RoadworkDocument` (`App.Roadworks.Data`) and `Melvin\Data\Feature` (`App.Melvin.Data`) are also generated under their own namespaces — expected and harmless.
- Generation reflects over all of `app/`. If an unrelated `Data` class fails to transform, fix that class's type hints rather than narrowing `transformDirectories` — keeping `app_path()` is what makes "every seam typed" automatic.
