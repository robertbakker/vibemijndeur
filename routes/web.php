<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\ListingController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RoadworkGeometryController;
use App\Http\Controllers\RoadworkSearchController;
use App\Http\Controllers\WerkzaamhedenController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::inertia('/kaart', 'Kaart')->name('kaart');

Route::get('/werkzaamheden', WerkzaamhedenController::class)->name('werkzaamheden.index');

Route::get('/api/roadworks', RoadworkSearchController::class)->name('api.roadworks');

Route::get('/api/roadworks/{id}/geometry', RoadworkGeometryController::class)
    ->whereNumber('id')
    ->name('api.roadworks.geometry');

Route::get('/projecten/{id}', [ProjectController::class, 'redirectFromId'])
    ->whereNumber('id')
    ->name('projecten.legacy');

/*
 * Serve the basemap PMTiles through Laravel so HTTP Range requests work.
 * The PHP built-in dev server (`php artisan serve`, used by Sail) does not
 * byte-serve static files; BinaryFileResponse handles Range/206 automatically.
 */
Route::get('/tiles/basemap-nl.pmtiles', fn () => response()
    ->file(public_path('basemap-nl.pmtiles'), ['Content-Type' => 'application/octet-stream']))
    ->name('tiles.basemap');

// Pretty hierarchical listing + roadwork detail catch-all. MUST stay last so
// the named routes above win.
Route::get('/{path}', ListingController::class)
    ->where('path', '[a-z0-9-]+(?:/[a-z0-9-]+)*')
    ->name('listing');
