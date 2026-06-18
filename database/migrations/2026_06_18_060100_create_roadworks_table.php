<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Roadworks are a mirror of Melvin's source-of-truth data, so they're stored as
 * the raw GeoJSON feature (`feature` jsonb) plus a handful of promoted columns
 * we filter/sort/index on. New Melvin fields land in jsonb with no migration.
 *
 * History is kept via the nearform/temporal_tables `versioning()` trigger:
 * every change copies the whole previous row (incl. the full feature jsonb) into
 * `roadworks_history`. The stable schema means history never needs ALTERs.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE roadworks (
                id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                source         varchar(255) NOT NULL,
                source_id      varchar(255) NOT NULL,
                kind           varchar(255),
                severity       varchar(255),
                status         varchar(255),
                hindrance      varchar(255),
                activity_type  varchar(255),
                published      boolean,
                road_authority varchar(255),
                start_date     timestamptz,
                end_date       timestamptz,
                coordinates    geometry(Geometry, 4326),
                feature        jsonb NOT NULL,
                sys_period     tstzrange NOT NULL DEFAULT tstzrange(current_timestamp, null),
                CONSTRAINT roadworks_source_source_id_unique UNIQUE (source, source_id)
            );

            CREATE INDEX roadworks_coordinates_gist ON roadworks USING gist (coordinates);
            CREATE INDEX roadworks_feature_gin      ON roadworks USING gin (feature);
            CREATE INDEX roadworks_status_index     ON roadworks (status);
            CREATE INDEX roadworks_kind_index       ON roadworks (kind);
            CREATE INDEX roadworks_severity_index   ON roadworks (severity);
            CREATE INDEX roadworks_dates_index      ON roadworks (start_date, end_date);

            CREATE TABLE roadworks_history (
                history_id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                LIKE roadworks
            );

            CREATE TRIGGER roadworks_history_trigger
                BEFORE INSERT OR UPDATE OR DELETE ON roadworks
                FOR EACH ROW
                EXECUTE PROCEDURE versioning('sys_period', 'roadworks_history', 'true', 'true');

            -- Feed-presence tracking, deliberately NOT versioned (no trigger):
            -- updating last_seen_at on every import must not churn roadworks history.
            CREATE TABLE roadwork_seen (
                source        varchar(255) NOT NULL,
                source_id     varchar(255) NOT NULL,
                first_seen_at timestamptz NOT NULL,
                last_seen_at  timestamptz NOT NULL,
                PRIMARY KEY (source, source_id)
            );
            CREATE INDEX roadwork_seen_last_seen_index ON roadwork_seen (last_seen_at);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS roadworks_history_trigger ON roadworks;
            DROP TABLE IF EXISTS roadworks_history;
            DROP TABLE IF EXISTS roadworks;
            DROP TABLE IF EXISTS roadwork_seen;
            SQL);
    }
};
