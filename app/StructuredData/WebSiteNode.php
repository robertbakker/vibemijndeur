<?php

declare(strict_types=1);

namespace App\StructuredData;

class WebSiteNode
{
    /**
     * @return array<string, mixed>
     */
    public static function make(): array
    {
        return [
            '@type' => 'WebSite',
            'name' => 'voormijndeur',
            'url' => url('/'),
        ];
    }
}
