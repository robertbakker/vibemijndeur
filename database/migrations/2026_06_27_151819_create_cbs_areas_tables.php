<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CBS administrative area hierarchy (gebiedsindelingen): landsdeel → provincie →
 * gemeente → wijk → buurt. One table per level, each holding the simplified
 * (`gegeneraliseerd`) MultiPolygon boundary as a PostGIS geometry in EPSG:4326,
 * matching the `roadworks.coordinates` convention.
 *
 * These are reference data replaced wholesale on reimport, so — unlike roadworks —
 * there is no temporal `versioning()` trigger. Parent FKs are nullable: an
 * unresolved parent (spatial miss, edge gemeente) must never abort the import.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE landsdelen (
                id        bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                code      varchar(10) NOT NULL UNIQUE,
                name      varchar(255) NOT NULL,
                year      smallint NOT NULL,
                geometry  geometry(MultiPolygon, 4326) NOT NULL
            );
            CREATE INDEX landsdelen_geometry_gist ON landsdelen USING gist (geometry);

            CREATE TABLE provincies (
                id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                code         varchar(10) NOT NULL UNIQUE,
                name         varchar(255) NOT NULL,
                year         smallint NOT NULL,
                landsdeel_id bigint REFERENCES landsdelen (id) ON DELETE SET NULL,
                geometry     geometry(MultiPolygon, 4326) NOT NULL
            );
            CREATE INDEX provincies_geometry_gist ON provincies USING gist (geometry);
            CREATE INDEX provincies_landsdeel_id_index ON provincies (landsdeel_id);

            CREATE TABLE gemeenten (
                id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                code         varchar(10) NOT NULL UNIQUE,
                name         varchar(255) NOT NULL,
                year         smallint NOT NULL,
                provincie_id bigint REFERENCES provincies (id) ON DELETE SET NULL,
                geometry     geometry(MultiPolygon, 4326) NOT NULL
            );
            CREATE INDEX gemeenten_geometry_gist ON gemeenten USING gist (geometry);
            CREATE INDEX gemeenten_provincie_id_index ON gemeenten (provincie_id);

            CREATE TABLE wijken (
                id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                code        varchar(10) NOT NULL UNIQUE,
                name        varchar(255) NOT NULL,
                year        smallint NOT NULL,
                gemeente_id bigint REFERENCES gemeenten (id) ON DELETE SET NULL,
                geometry    geometry(MultiPolygon, 4326) NOT NULL
            );
            CREATE INDEX wijken_geometry_gist ON wijken USING gist (geometry);
            CREATE INDEX wijken_gemeente_id_index ON wijken (gemeente_id);

            CREATE TABLE buurten (
                id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                code        varchar(10) NOT NULL UNIQUE,
                name        varchar(255) NOT NULL,
                year        smallint NOT NULL,
                wijk_id     bigint REFERENCES wijken (id) ON DELETE SET NULL,
                gemeente_id bigint REFERENCES gemeenten (id) ON DELETE SET NULL,
                geometry    geometry(MultiPolygon, 4326) NOT NULL
            );
            CREATE INDEX buurten_geometry_gist ON buurten USING gist (geometry);
            CREATE INDEX buurten_wijk_id_index ON buurten (wijk_id);
            CREATE INDEX buurten_gemeente_id_index ON buurten (gemeente_id);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS buurten;
            DROP TABLE IF EXISTS wijken;
            DROP TABLE IF EXISTS gemeenten;
            DROP TABLE IF EXISTS provincies;
            DROP TABLE IF EXISTS landsdelen;
            SQL);
    }
};
