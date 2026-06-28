<?php

declare(strict_types=1);

namespace Tests\Support;

use Attribute;

/**
 * Marks a test class or method that exercises the live Meilisearch path.
 *
 * Meilisearch is no longer part of the default Sail stack (Manticore is the
 * roadwork search engine). When the Meilisearch container is not running, the
 * Scout/Meili HTTP client would otherwise hang with no connect timeout, so the
 * base TestCase skips these tests instead of blocking the whole suite.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class RequiresMeilisearch {}
