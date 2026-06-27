<?php

declare(strict_types=1);

namespace App\StructuredData;

class PlaceNode
{
    /**
     * @return array<string, mixed>
     */
    public static function make(
        string $name,
        ?float $latitude,
        ?float $longitude,
        ?string $locality,
        ?string $region,
    ): array {
        $address = ['@type' => 'PostalAddress', 'addressCountry' => 'NL'];

        if ($locality !== null) {
            $address['addressLocality'] = $locality;
        }

        if ($region !== null) {
            $address['addressRegion'] = $region;
        }

        $place = ['@type' => 'Place', 'name' => $name, 'address' => $address];

        if ($latitude !== null && $longitude !== null) {
            $place['geo'] = [
                '@type' => 'GeoCoordinates',
                'latitude' => $latitude,
                'longitude' => $longitude,
            ];
        }

        return $place;
    }
}
