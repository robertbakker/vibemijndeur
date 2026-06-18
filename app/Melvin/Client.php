<?php

namespace App\Melvin;

use App\Melvin\Data\FeatureCollection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Client for the Melvin GeoJSON API (https://melvin.ndw.nu).
 *
 * Authentication is delegated to {@see OAuth2Client}; each request is sent
 * with a fresh bearer token.
 */
class Client
{
    public function __construct(
        private readonly OAuth2Client $oauth,
        private readonly string $baseUrl,
    ) {}

    /**
     * @return Area[]
     */
    public function getAreas(): array
    {
        $data = $this->request()
            ->get('/melvinservice/rest/area/all')
            ->throw()
            ->json();

        return array_map(
            static fn (array $area): Area => new Area($area['id'], $area['type'], $area['name']),
            $data,
        );
    }

    /**
     * Export GeoJSON features matching the given filter.
     */
    public function exportFeatures(FilterParams $params): FeatureCollection
    {
        $data = $this->request()
            ->post('/melvinservice/rest/export', $params->jsonSerialize())
            ->throw()
            ->json();

        return FeatureCollection::from($data);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->oauth->accessToken())
            ->acceptJson();
    }
}
