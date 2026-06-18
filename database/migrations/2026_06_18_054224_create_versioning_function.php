<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Loads the nearform/temporal_tables `versioning()` trigger function.
     *
     * @see https://github.com/nearform/temporal_tables
     */
    public function up(): void
    {
        DB::unprepared(file_get_contents(__DIR__.'/sql/versioning_function.sql'));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS versioning() CASCADE;');
    }
};
