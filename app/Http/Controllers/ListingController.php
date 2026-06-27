<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\ProjectDetail;
use App\Models\Roadwork;
use App\Router\ListingUrlMapper;
use App\Router\SegmentCursor;
use App\Router\Segments\RoadworkSegment;
use App\Router\UnmatchedSegmentException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The pretty-URL catch-all. Resolves a path to either a faceted listing
 * (area + status/type/authority) or a roadwork detail page, issuing 301s to the
 * canonical form when the requested path is a longer/stale equivalent.
 */
class ListingController extends Controller
{
    public function __construct(
        private readonly ListingUrlMapper $mapper,
        private readonly RoadworkSegment $roadworkSegment,
        private readonly WerkzaamhedenController $werkzaamheden,
    ) {}

    public function __invoke(Request $request, string $path): Response|RedirectResponse
    {
        // 1. Try the faceted listing pipeline.
        try {
            $query = $this->mapper->parse($path);

            if ($query->area() !== null || $query->statuses() !== [] || $query->types() !== [] || $query->authorities() !== []) {
                $canonical = ltrim($this->mapper->build($query), '/');
                if ($canonical !== $path) {
                    return redirect('/'.$canonical, 301);
                }

                return $this->werkzaamheden->renderFromQuery($query, $request);
            }
        } catch (UnmatchedSegmentException) {
            // Not a listing path; fall through to detail resolution.
        }

        // 2. Try a roadwork detail page.
        $resolution = $this->roadworkSegment->resolve(new SegmentCursor(
            array_values(array_filter(explode('/', trim($path, '/'))))
        ));

        if ($resolution !== null) {
            if ($resolution->redirectToSlug !== null) {
                return redirect('/'.$resolution->redirectToSlug, 301);
            }

            $roadwork = Roadwork::query()
                ->withRepresentativePoint()
                ->with('currentSlug')
                ->findOrFail($resolution->roadworkId);

            return Inertia::render('Projecten/Show', [
                'project' => ProjectDetail::fromModel($roadwork),
            ]);
        }

        abort(404);
    }
}
