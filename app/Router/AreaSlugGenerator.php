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
 * Idempotent: wipes current area slugs first.
 */
final class AreaSlugGenerator
{
    /** @var array<class-string<Model>, string> morph class => suffix token for collision losers */
    private const LEVEL_TOKEN = [
        Wijk::class => 'wijk',
        Buurt::class => 'buurt',
    ];

    public function rebuild(): void
    {
        DB::transaction(function (): void {
            $areaTypes = array_map(fn (string $c): string => (new $c)->getMorphClass(), [
                Landsdeel::class, Provincie::class, Gemeente::class, Wijk::class, Buurt::class,
            ]);
            Slug::query()->whereIn('sluggable_type', $areaTypes)->delete();

            $this->generate(Landsdeel::class, null, fn (Landsdeel $a): ?int => null);
            $this->generate(Provincie::class, Landsdeel::class, fn (Provincie $a): ?int => $a->landsdeel_id);
            $this->generate(Gemeente::class, Provincie::class, fn (Gemeente $a): ?int => $a->provincie_id);
            $this->generate(Wijk::class, Gemeente::class, fn (Wijk $a): ?int => $a->gemeente_id);
            $this->generate(Buurt::class, Gemeente::class, fn (Buurt $a): ?int => $a->gemeente_id);
        });
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

        $model::query()->chunkById(500, function ($areas) use ($model, $morph, $parentMorph, $parentKey): void {
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
                    'slug' => $this->uniqueSlug($area, $parentId, $model),
                    'sluggable_type' => $morph,
                    'sluggable_id' => $area->getKey(),
                    'parent_id' => $parentId,
                    'is_current' => true,
                ]);
            }
        });
    }

    /**
     * Base slug from the name; if a current sibling already owns it (the larger
     * area won), append this level's token.
     *
     * @param  class-string<Model>  $model
     */
    private function uniqueSlug(Model $area, ?int $parentId, string $model): string
    {
        $base = Str::slug((string) $area->name);

        $taken = Slug::query()
            ->where('parent_id', $parentId)
            ->where('slug', $base)
            ->where('is_current', true)
            ->exists();

        if (! $taken) {
            return $base;
        }

        $token = self::LEVEL_TOKEN[$model] ?? Str::slug(class_basename($model));

        return "{$base}-{$token}";
    }
}
