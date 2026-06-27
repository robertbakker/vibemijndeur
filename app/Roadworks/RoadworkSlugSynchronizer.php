<?php

declare(strict_types=1);

namespace App\Roadworks;

use App\Models\Roadwork;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles a roadwork's slugs after its content changes: computes the desired
 * current slug and, when it differs, demotes the old current slug to a
 * historical redirect row and promotes (or reuses) the new one.
 */
final class RoadworkSlugSynchronizer
{
    public function __construct(private readonly RoadworkSlugger $slugger) {}

    public function sync(int $roadworkId): void
    {
        $roadwork = Roadwork::find($roadworkId);

        if ($roadwork === null) {
            return;
        }

        $morph = (new Roadwork)->getMorphClass();
        $desired = $this->slugger->unique($this->slugger->base($roadwork), $roadworkId);

        DB::transaction(function () use ($roadworkId, $morph, $desired): void {
            DB::table('roadworks')->where('id', $roadworkId)->lockForUpdate()->first();

            $current = DB::table('slugs')
                ->where('sluggable_type', $morph)
                ->where('sluggable_id', $roadworkId)
                ->where('is_current', true)
                ->first();

            if ($current !== null && $current->slug === $desired) {
                return;
            }

            DB::table('slugs')
                ->where('sluggable_type', $morph)
                ->where('sluggable_id', $roadworkId)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            $existing = DB::table('slugs')
                ->where('sluggable_type', $morph)
                ->where('sluggable_id', $roadworkId)
                ->where('slug', $desired)
                ->first();

            if ($existing !== null) {
                DB::table('slugs')->where('id', $existing->id)->update(['is_current' => true]);
            } else {
                DB::table('slugs')->insert([
                    'slug' => $desired,
                    'sluggable_type' => $morph,
                    'sluggable_id' => $roadworkId,
                    'parent_id' => null,
                    'is_current' => true,
                ]);
            }
        });
    }
}
