<?php

declare(strict_types=1);

namespace App\StructuredData;

use App\Data\RoadworkCard;

class ItemListNode
{
    /**
     * @param  list<RoadworkCard>  $cards
     * @return array<string, mixed>
     */
    public static function fromCards(array $cards): array
    {
        $elements = [];
        $position = 1;

        foreach ($cards as $card) {
            if ($card->slug === null) {
                continue;
            }

            $elements[] = [
                '@type' => 'ListItem',
                'position' => $position++,
                'url' => url('/'.$card->slug),
                'name' => $card->title,
            ];
        }

        return [
            '@type' => 'ItemList',
            'numberOfItems' => count($elements),
            'itemListElement' => $elements,
        ];
    }
}
