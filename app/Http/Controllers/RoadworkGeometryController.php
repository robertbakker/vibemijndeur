<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Roadwork;
use App\Roadworks\RoadworkGeometry;
use Illuminate\Http\JsonResponse;

class RoadworkGeometryController extends Controller
{
    /**
     * Full geometry for a single roadwork as a GeoJSON FeatureCollection: the
     * situation plus every restriction and detour, each tagged with a `role`.
     * The map normally gets this from the search index in bulk; this endpoint backs
     * any single-roadwork view (e.g. a deep link).
     */
    public function __invoke(int $id): JsonResponse
    {
        $roadwork = Roadwork::query()->findOrFail($id);

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => RoadworkGeometry::features($roadwork->feature, $roadwork->id),
        ]);
    }
}
