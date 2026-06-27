<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\Suggestion;
use Tests\TestCase;

class SuggestionTest extends TestCase
{
    public function test_it_serializes_to_the_endpoint_shape(): void
    {
        $suggestion = new Suggestion('gemeente', 'Amsterdam', '/amsterdam', 42);

        $this->assertSame(
            ['type' => 'gemeente', 'label' => 'Amsterdam', 'url' => '/amsterdam', 'count' => 42],
            $suggestion->toArray(),
        );
    }
}
