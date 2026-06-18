<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RoadworkGeometryController;
use App\Http\Controllers\RoadworkSearchController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::inertia('/kaart', 'Kaart')->name('kaart');

Route::get('/api/roadworks', RoadworkSearchController::class)->name('api.roadworks');

Route::get('/api/roadworks/{id}/geometry', RoadworkGeometryController::class)
    ->whereNumber('id')
    ->name('api.roadworks.geometry');

Route::get('/projecten/{id}', [ProjectController::class, 'show'])
    ->whereNumber('id')
    ->name('projecten.show');

/*
 * Serve the basemap PMTiles through Laravel so HTTP Range requests work.
 * The PHP built-in dev server (`php artisan serve`, used by Sail) does not
 * byte-serve static files; BinaryFileResponse handles Range/206 automatically.
 */
Route::get('/tiles/basemap-nl.pmtiles', fn () => response()
    ->file(public_path('basemap-nl.pmtiles'), ['Content-Type' => 'application/octet-stream']))
    ->name('tiles.basemap');
