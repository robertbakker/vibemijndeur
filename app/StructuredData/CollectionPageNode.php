<?php

declare(strict_types=1);

namespace App\StructuredData;

class CollectionPageNode
{
    /**
     * @param  array<string, mixed>  $itemList
     * @return array<string, mixed>
     */
    public static function make(string $name, string $url, array $itemList): array
    {
        return [
            '@type' => 'CollectionPage',
            'name' => $name,
            'url' => $url,
            'mainEntity' => $itemList,
        ];
    }
}
