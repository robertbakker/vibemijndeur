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

        $desired = $this->slugger->unique($this->slugger->base($roadwork), $roadworkId);

        DB::transaction(function () use ($roadworkId, $desired): void {
            DB::table('roadworks')->where('id', $roadworkId)->lockForUpdate()->first();

            $current = DB::table('roadwork_slugs')
                ->where('roadwork_id', $roadworkId)
                ->where('is_current', true)
                ->first();

            if ($current !== null && $current->slug === $desired) {
                return;
            }

            DB::table('roadwork_slugs')
                ->where('roadwork_id', $roadworkId)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            $existing = DB::table('roadwork_slugs')
                ->where('roadwork_id', $roadworkId)
                ->where('slug', $desired)
                ->first();

            if ($existing !== null) {
                DB::table('roadwork_slugs')->where('id', $existing->id)->update(['is_current' => true]);
            } else {
                DB::table('roadwork_slugs')->insert([
                    'roadwork_id' => $roadworkId,
                    'slug' => $desired,
                    'is_current' => true,
                ]);
            }
        });
    }
}
