<?php

declare(strict_types=1);

use App\Models\Roadwork;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Copy existing roadwork_slugs rows into the unified slugs table as flat
 * (parent_id null) roadwork slugs, preserving is_current. The old table is
 * left in place for one release as a safety net.
 */
return new class extends Migration
{
    public function up(): void
    {
        $morph = (new Roadwork)->getMorphClass();

        DB::statement(
            'INSERT INTO slugs (slug, sluggable_type, sluggable_id, parent_id, is_current, created_at)
             SELECT slug, ?, roadwork_id, NULL, is_current, created_at FROM roadwork_slugs',
            [$morph],
        );
    }

    public function down(): void
    {
        $morph = (new Roadwork)->getMorphClass();
        DB::statement('DELETE FROM slugs WHERE sluggable_type = ?', [$morph]);
    }
};
