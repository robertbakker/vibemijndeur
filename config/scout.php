<?php

declare(strict_types=1);

use App\Models\Roadwork;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Search Engine
    |--------------------------------------------------------------------------
    */

    'driver' => env('SCOUT_DRIVER', 'meilisearch'),

    /*
    |--------------------------------------------------------------------------
    | Index Prefix
    |--------------------------------------------------------------------------
    */

    'prefix' => env('SCOUT_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Queue Data Syncing
    |--------------------------------------------------------------------------
    */

    'queue' => env('SCOUT_QUEUE', false),

    /*
    |--------------------------------------------------------------------------
    | Database Transactions
    |--------------------------------------------------------------------------
    */

    'after_commit' => true,

    /*
    |--------------------------------------------------------------------------
    | Chunk Sizes
    |--------------------------------------------------------------------------
    */

    'chunk' => [
        'searchable' => 500,
        'unsearchable' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Soft Deletes
    |--------------------------------------------------------------------------
    */

    'soft_delete' => false,

    /*
    |--------------------------------------------------------------------------
    | Identify User
    |--------------------------------------------------------------------------
    */

    'identify' => env('SCOUT_IDENTIFY', false),

    /*
    |--------------------------------------------------------------------------
    | Meilisearch Configuration
    |--------------------------------------------------------------------------
    |
    | `index-settings` is pushed to Meilisearch by `scout:sync-index-settings`.
    | Geo lives in the special `_geo` attribute; it must be declared filterable
    | (for `_geoRadius` / `_geoBoundingBox`) and sortable (for `_geoPoint`).
    | Dates are indexed as unix timestamps so Meilisearch can range-filter/sort.
    |
    */

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
        'key' => env('MEILISEARCH_KEY'),

        'index-settings' => [
            Roadwork::class => [
                // Allow the map to page past Meilisearch's default 1,000-hit ceiling
                // so a zoomed-out viewport can return every roadwork in the country.
                'pagination' => [
                    'maxTotalHits' => 30000,
                ],
                // The footer counts works for every Dutch gemeente (~340), so the
                // gemeente facet distribution must not be capped at Meilisearch's
                // default of 100 values.
                'faceting' => [
                    'maxValuesPerFacet' => 400,
                ],
                'filterableAttributes' => [
                    '_geo',
                    'source',
                    'kind',
                    'severity',
                    'status',
                    'status_key',
                    'work_type',
                    'gemeente',
                    'provincie',
                    'wijk',
                    'buurt',
                    'hindrance',
                    'activity_type',
                    'published',
                    'road_authority',
                    'start_ts',
                    'end_ts',
                ],
                'sortableAttributes' => [
                    '_geo',
                    'status_order',
                    'start_ts',
                    'end_ts',
                    'last_seen_ts',
                ],
                'searchableAttributes' => [
                    'road_authority',
                    'kind',
                    'description',
                ],
            ],
        ],
    ],

];
