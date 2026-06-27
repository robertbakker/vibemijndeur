<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Routing slugs for roadworks. Deliberately NOT versioned (no temporal trigger):
 * slug churn must not bloat roadworks history. One `is_current` slug per roadwork
 * is the canonical URL; the rest are historical redirect targets.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE roadwork_slugs (
                id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                roadwork_id bigint NOT NULL REFERENCES roadworks(id) ON DELETE CASCADE,
                slug        varchar(255) NOT NULL,
                is_current  boolean NOT NULL DEFAULT false,
                created_at  timestamptz NOT NULL DEFAULT current_timestamp,
                CONSTRAINT roadwork_slugs_slug_unique UNIQUE (slug)
            );

            CREATE UNIQUE INDEX roadwork_slugs_one_current
                ON roadwork_slugs (roadwork_id) WHERE is_current;
            CREATE INDEX roadwork_slugs_roadwork_id_index ON roadwork_slugs (roadwork_id);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS roadwork_slugs;');
    }
};
