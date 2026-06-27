<?php

declare(strict_types=1);

namespace App\StructuredData;

class SpecialAnnouncementNode
{
    /**
     * @param  array<string, mixed>  $place
     * @param  array<string, mixed>  $publisher
     * @return array<string, mixed>
     */
    public static function make(
        string $name,
        string $text,
        string $url,
        ?string $datePosted,
        ?string $expires,
        array $place,
        array $publisher,
    ): array {
        $node = [
            '@type' => 'SpecialAnnouncement',
            'name' => $name,
            'text' => $text,
            'url' => $url,
            'spatialCoverage' => $place,
            'publisher' => $publisher,
        ];

        if ($datePosted !== null) {
            $node['datePosted'] = $datePosted;
        }

        if ($expires !== null) {
            $node['expires'] = $expires;
        }

        return $node;
    }
}
