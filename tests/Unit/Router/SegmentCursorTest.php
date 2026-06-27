<?php

declare(strict_types=1);

namespace Tests\Unit\Router;

use App\Router\SegmentCursor;
use PHPUnit\Framework\TestCase;

class SegmentCursorTest extends TestCase
{
    public function test_it_consumes_variable_width(): void
    {
        $cursor = new SegmentCursor(['noord-holland', 'amsterdam', 'gestremd']);

        $this->assertTrue($cursor->isFirst());
        $this->assertSame(['noord-holland', 'amsterdam'], $cursor->peek(2));
        $this->assertSame(['noord-holland', 'amsterdam', 'gestremd'], $cursor->remaining());

        $cursor->consume(2);

        $this->assertFalse($cursor->isFirst());
        $this->assertSame(['gestremd'], $cursor->remaining());
        $this->assertFalse($cursor->done());

        $cursor->consume(1);
        $this->assertTrue($cursor->done());
        $this->assertSame([], $cursor->remaining());
    }
}
