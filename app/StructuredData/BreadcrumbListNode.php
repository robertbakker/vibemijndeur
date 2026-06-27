<?php

declare(strict_types=1);

namespace App\StructuredData;

class BreadcrumbListNode
{
    /**
     * @param  list<array{name: string, url: ?string}>  $crumbs
     * @return array<string, mixed>
     */
    public static function make(array $crumbs): array
    {
        $items = [];

        foreach (array_values($crumbs) as $index => $crumb) {
            $item = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $crumb['name'],
            ];

            if (($crumb['url'] ?? null) !== null) {
                $item['item'] = $crumb['url'];
            }

            $items[] = $item;
        }

        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }
}
