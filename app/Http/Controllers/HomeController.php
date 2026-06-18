<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Roadwork;
use App\Roadworks\Data\RoadworkCard;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * Homepage: a snapshot of roadworks currently underway, most severe first.
     */
    public function __invoke(): Response
    {
        $roadworks = Roadwork::query()
            ->whereNotNull('coordinates')
            ->where(function ($query): void {
                $query->whereNull('published')->orWhere('published', true);
            })
            ->where(fn ($query) => $query->whereNull('start_date')->orWhere('start_date', '<=', now()))
            ->where(fn ($query) => $query->whereNull('end_date')->orWhere('end_date', '>=', now()))
            ->orderByRaw("array_position(ARRAY['high','medium','low'], severity)")
            ->orderByDesc('start_date')
            ->limit(7)
            ->get();

        return Inertia::render('Home', [
            'projects' => RoadworkCard::collect($roadworks),
            'roadworksTotal' => DB::table('roadworks')->count(),
        ]);
    }
}
