<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Roadwork search engine
    |--------------------------------------------------------------------------
    |
    | Which implementation of App\Roadworks\Contracts\RoadworkSearchEngine the
    | map API, listing, footer counts and autosuggest resolve to. Meilisearch
    | (Scout) stays the indexing/search default; "manticore" routes the same
    | queries through App\Roadworks\ManticoreRoadworkSearch (direct client) so
    | the two can be benchmarked side by side without touching SCOUT_DRIVER.
    |
    | Supported: "meili", "manticore"
    |
    */

    'search_engine' => env('ROADWORK_SEARCH_ENGINE', 'meili'),

    /*
    | The Manticore index (table) name. Connection details (mysql host/port on
    | 9306) live in the package's config/manticore.php, driven by MANTICORE_*.
    */
    'manticore_index' => env('MANTICORE_INDEX', 'roadworks'),

];
