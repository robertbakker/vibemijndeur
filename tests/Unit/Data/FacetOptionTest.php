<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\FacetOption;
use PHPUnit\Framework\TestCase;

class FacetOptionTest extends TestCase
{
    public function test_it_exposes_the_toggle_url(): void
    {
        $option = new FacetOption('planned', 'Gepland', 12, false, '/gepland', '#2F6BD8');

        $this->assertSame('/gepland', $option->url);
        $this->assertFalse($option->checked);
        $this->assertSame('#2F6BD8', $option->dot);
    }
}
