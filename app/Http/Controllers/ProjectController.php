<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Roadwork;
use App\Models\RoadworkSlug;
use App\Roadworks\Data\ProjectDetail;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function showBySlug(string $slug): Response|RedirectResponse
    {
        $slugRow = RoadworkSlug::where('slug', $slug)->first();

        if ($slugRow === null) {
            abort(404);
        }

        if (! $slugRow->is_current) {
            $current = RoadworkSlug::where('roadwork_id', $slugRow->roadwork_id)
                ->where('is_current', true)
                ->firstOrFail();

            return redirect()->route('projecten.show', $current->slug, 301);
        }

        $roadwork = Roadwork::query()
            ->withRepresentativePoint()
            ->with('currentSlug')
            ->findOrFail($slugRow->roadwork_id);

        return Inertia::render('Projecten/Show', [
            'project' => ProjectDetail::fromModel($roadwork),
        ]);
    }

    public function redirectFromId(int $id): RedirectResponse
    {
        $current = RoadworkSlug::where('roadwork_id', $id)
            ->where('is_current', true)
            ->firstOrFail();

        return redirect()->route('projecten.show', $current->slug, 301);
    }
}
