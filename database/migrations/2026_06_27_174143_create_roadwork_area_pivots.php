<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Many-to-many links between a roadwork and the CBS areas its geometry intersects,
 * one pivot per level. A point-geometry roadwork links to one area per level; a
 * line/polygon links to every area it crosses.
 *
 * Both FKs cascade on delete: deleting a roadwork or re-importing the areas (the
 * importer's `TRUNCATE … RESTART IDENTITY CASCADE`) clears the affected links, which
 * the `roadworks:link-areas` backfill then rebuilds.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE roadwork_landsdeel (
                roadwork_id  bigint NOT NULL REFERENCES roadworks (id) ON DELETE CASCADE,
                landsdeel_id bigint NOT NULL REFERENCES landsdelen (id) ON DELETE CASCADE,
                PRIMARY KEY (roadwork_id, landsdeel_id)
            );
            CREATE INDEX roadwork_landsdeel_landsdeel_id_index ON roadwork_landsdeel (landsdeel_id);

            CREATE TABLE roadwork_provincie (
                roadwork_id  bigint NOT NULL REFERENCES roadworks (id) ON DELETE CASCADE,
                provincie_id bigint NOT NULL REFERENCES provincies (id) ON DELETE CASCADE,
                PRIMARY KEY (roadwork_id, provincie_id)
            );
            CREATE INDEX roadwork_provincie_provincie_id_index ON roadwork_provincie (provincie_id);

            CREATE TABLE roadwork_gemeente (
                roadwork_id bigint NOT NULL REFERENCES roadworks (id) ON DELETE CASCADE,
                gemeente_id bigint NOT NULL REFERENCES gemeenten (id) ON DELETE CASCADE,
                PRIMARY KEY (roadwork_id, gemeente_id)
            );
            CREATE INDEX roadwork_gemeente_gemeente_id_index ON roadwork_gemeente (gemeente_id);

            CREATE TABLE roadwork_wijk (
                roadwork_id bigint NOT NULL REFERENCES roadworks (id) ON DELETE CASCADE,
                wijk_id     bigint NOT NULL REFERENCES wijken (id) ON DELETE CASCADE,
                PRIMARY KEY (roadwork_id, wijk_id)
            );
            CREATE INDEX roadwork_wijk_wijk_id_index ON roadwork_wijk (wijk_id);

            CREATE TABLE roadwork_buurt (
                roadwork_id bigint NOT NULL REFERENCES roadworks (id) ON DELETE CASCADE,
                buurt_id    bigint NOT NULL REFERENCES buurten (id) ON DELETE CASCADE,
                PRIMARY KEY (roadwork_id, buurt_id)
            );
            CREATE INDEX roadwork_buurt_buurt_id_index ON roadwork_buurt (buurt_id);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS roadwork_buurt;
            DROP TABLE IF EXISTS roadwork_wijk;
            DROP TABLE IF EXISTS roadwork_gemeente;
            DROP TABLE IF EXISTS roadwork_provincie;
            DROP TABLE IF EXISTS roadwork_landsdeel;
            SQL);
    }
};
