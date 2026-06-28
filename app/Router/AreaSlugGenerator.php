<?php

declare(strict_types=1);

namespace App\Router;

use App\Models\Buurt;
use App\Models\Gemeente;
use App\Models\Landsdeel;
use App\Models\Provincie;
use App\Models\Slug;
use App\Models\Wijk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * (Re)builds the area slug rows in the unified slugs table from the CBS area
 * tables. Top-down so each level's structural parent slug already exists.
 * Slugs are globally unique; a rebuild that changes a slug retires the old row
 * (is_current=false) so stale URLs keep 301-redirecting.
 */
final class AreaSlugGenerator
{
    public function rebuild(): void
    {
        DB::transaction(function (): void {
            $areaTypes = array_map(fn (string $c): string => (new $c)->getMorphClass(), [
                Landsdeel::class, Provincie::class, Gemeente::class, Wijk::class, Buurt::class,
            ]);

            // Retire (don't delete) so stale slugs keep redirecting.
            Slug::query()->whereIn('sluggable_type', $areaTypes)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            $this->generate(Landsdeel::class, null, fn (Landsdeel $a): ?int => null);
            $this->generate(Provincie::class, Landsdeel::class, fn (Provincie $a): ?int => $a->landsdeel_id);
            $this->generate(Gemeente::class, Provincie::class, fn (Gemeente $a): ?int => $a->provincie_id);
            $this->generate(Wijk::class, Gemeente::class, fn (Wijk $a): ?int => $a->gemeente_id);
            $this->generate(Buurt::class, Gemeente::class, fn (Buurt $a): ?int => $a->gemeente_id);

            // Detach retired rows from the (now-retired) hierarchy so pruning a
            // redundant retired parent can't ON DELETE CASCADE a real redirect
            // child. Retired rows redirect by slug alone; the chain is unused.
            Slug::query()->whereIn('sluggable_type', $areaTypes)
                ->where('is_current', false)
                ->update(['parent_id' => null]);

            $this->pruneRedundantRetired($areaTypes);
        });
    }

    /**
     * Drop retired rows whose (slug,type,id) exactly equals a freshly written
     * current row — they would be duplicate dead weight, not a real redirect.
     *
     * @param  list<string>  $areaTypes
     */
    private function pruneRedundantRetired(array $areaTypes): void
    {
        $current = Slug::query()
            ->whereIn('sluggable_type', $areaTypes)
            ->where('is_current', true)
            ->get(['slug', 'sluggable_type', 'sluggable_id']);

        foreach ($current as $row) {
            Slug::query()
                ->where('is_current', false)
                ->where('slug', $row->slug)
                ->where('sluggable_type', $row->sluggable_type)
                ->where('sluggable_id', $row->sluggable_id)
                ->delete();
        }
    }

    /**
     * @param  class-string<Model>  $model
     * @param  class-string<Model>|null  $parentModel
     * @param  callable(Model):(int|null)  $parentKey
     */
    private function generate(string $model, ?string $parentModel, callable $parentKey): void
    {
        $parentMorph = $parentModel === null ? null : (new $parentModel)->getMorphClass();
        $morph = (new $model)->getMorphClass();

        $model::query()->chunkById(500, function ($areas) use ($morph, $parentMorph, $parentKey): void {
            foreach ($areas as $area) {
                $parentId = null;
                if ($parentMorph !== null && ($key = $parentKey($area)) !== null) {
                    $parentId = Slug::query()
                        ->where('sluggable_type', $parentMorph)
                        ->where('sluggable_id', $key)
                        ->where('is_current', true)
                        ->value('id');
                }

                Slug::query()->create([
                    'slug' => $this->uniqueSlug($area, $parentId),
                    'sluggable_type' => $morph,
                    'sluggable_id' => $area->getKey(),
                    'parent_id' => $parentId,
                    'is_current' => true,
                ]);
            }
        });
    }

    /**
     * Globally-unique slug. Base from the name; on a collision with any current
     * row (areas are generated largest-level first, so the larger area keeps the
     * bare slug), qualify with the parent area name, then the numeric id.
     */
    private function uniqueSlug(Model $area, ?int $parentId): string
    {
        $base = Str::slug((string) $area->name);
        if (! $this->slugTaken($base)) {
            return $base;
        }

        $parentName = $parentId === null
            ? null
            : Slug::query()->whereKey($parentId)->value('slug');

        if ($parentName !== null) {
            $qualified = "{$base}-{$parentName}";
            if (! $this->slugTaken($qualified)) {
                return $qualified;
            }
        }

        return "{$base}-{$area->getKey()}";
    }

    private function slugTaken(string $slug): bool
    {
        return Slug::query()
            ->where('slug', $slug)
            ->where('is_current', true)
            ->exists();
    }
}
