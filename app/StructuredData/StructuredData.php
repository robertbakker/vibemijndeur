<?php

declare(strict_types=1);

namespace App\StructuredData;

/**
 * Request-scoped collector of schema.org nodes, rendered as one JSON-LD
 * `@graph` script tag by the root blade view.
 */
class StructuredData
{
    /** @var list<array<string, mixed>> */
    private array $nodes = [];

    /**
     * @param  array<string, mixed>  $node
     */
    public function push(array $node): void
    {
        $this->nodes[] = $node;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function nodes(): array
    {
        return $this->nodes;
    }

    public function toScript(): string
    {
        if ($this->nodes === []) {
            return '';
        }

        $payload = ['@context' => 'https://schema.org', '@graph' => $this->nodes];

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_THROW_ON_ERROR,
        );

        return '<script type="application/ld+json">'.$json.'</script>';
    }
}
