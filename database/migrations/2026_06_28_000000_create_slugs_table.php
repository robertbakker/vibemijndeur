<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Unified routing slugs for every resolvable entity (roadworks + CBS areas).
 * One `is_current` slug per (parent_id, slug); historical rows are 301 targets.
 * `parent_id` is the STRUCTURAL parent's slug row; null = root-eligible.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE slugs (
                id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                slug           varchar(255) NOT NULL,
                sluggable_type varchar(255) NOT NULL,
                sluggable_id   bigint NOT NULL,
                parent_id      bigint NULL REFERENCES slugs(id) ON DELETE CASCADE,
                is_current     boolean NOT NULL DEFAULT true,
                created_at     timestamptz NOT NULL DEFAULT current_timestamp
            );

            CREATE UNIQUE INDEX slugs_sibling_unique
                ON slugs (parent_id, slug) WHERE is_current;
            CREATE INDEX slugs_sluggable_index ON slugs (sluggable_type, sluggable_id);
            CREATE INDEX slugs_slug_index ON slugs (slug);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS slugs;');
    }
};
