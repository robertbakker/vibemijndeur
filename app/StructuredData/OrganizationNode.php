<?php

declare(strict_types=1);

namespace App\StructuredData;

/**
 * The voormijndeur publisher organization. Embedded inline as `publisher`
 * on detail pages and as a standalone node on the homepage.
 */
class OrganizationNode
{
    /**
     * @return array<string, mixed>
     */
    public static function make(): array
    {
        return [
            '@type' => 'Organization',
            'name' => 'voormijndeur',
            'url' => url('/'),
        ];
    }
}
