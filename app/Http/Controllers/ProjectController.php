<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Roadwork;
use App\Roadworks\Data\ProjectDetail;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    /**
     * Project detail page for a single roadwork.
     */
    public function show(int $id): Response
    {
        $roadwork = Roadwork::query()
            ->withRepresentativePoint()
            ->findOrFail($id);

        return Inertia::render('Projecten/Show', [
            'project' => ProjectDetail::fromModel($roadwork),
        ]);
    }
}
