<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use ReflectionClass;
use Tests\Support\RequiresMeilisearch;

abstract class TestCase extends BaseTestCase
{
    /**
     * Cached Meilisearch reachability for the whole test run.
     */
    private static ?bool $meilisearchReachable = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->skipWhenMeilisearchUnavailable();
    }

    /**
     * Skip tests marked #[RequiresMeilisearch] when no Meilisearch server is reachable,
     * so the suite fails fast instead of hanging on a dead search backend.
     */
    private function skipWhenMeilisearchUnavailable(): void
    {
        if (! $this->requiresMeilisearch()) {
            return;
        }

        if (! self::meilisearchReachable()) {
            $this->markTestSkipped('Meilisearch is not available; skipping live search test.');
        }
    }

    /**
     * Whether the current test needs Meilisearch but none is reachable.
     *
     * Lets a #[RequiresMeilisearch] test guard its tearDown cleanup so it does
     * not touch the search backend when the test itself was skipped.
     */
    protected function meilisearchUnavailableForThisTest(): bool
    {
        return $this->requiresMeilisearch() && ! self::meilisearchReachable();
    }

    private function requiresMeilisearch(): bool
    {
        $class = new ReflectionClass(static::class);

        if ($class->getAttributes(RequiresMeilisearch::class) !== []) {
            return true;
        }

        $method = $this->name();

        if ($method !== '' && $class->hasMethod($method)) {
            return $class->getMethod($method)->getAttributes(RequiresMeilisearch::class) !== [];
        }

        return false;
    }

    private static function meilisearchReachable(): bool
    {
        if (self::$meilisearchReachable !== null) {
            return self::$meilisearchReachable;
        }

        $parts = parse_url((string) config('scout.meilisearch.host'));
        $host = $parts['host'] ?? '127.0.0.1';
        $port = $parts['port'] ?? 7700;

        $errno = 0;
        $error = '';
        $connection = @fsockopen($host, (int) $port, $errno, $error, 1.0);

        if ($connection !== false) {
            fclose($connection);

            return self::$meilisearchReachable = true;
        }

        return self::$meilisearchReachable = false;
    }
}
