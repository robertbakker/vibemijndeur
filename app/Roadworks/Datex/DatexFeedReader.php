<?php

declare(strict_types=1);

namespace App\Roadworks\Datex;

use DOMDocument;
use Generator;
use RuntimeException;
use SimpleXMLElement;
use XMLReader;

/**
 * Streams a DATEX II SituationPublication, yielding one <sit:situation> at a
 * time. Handles plain `.xml`, gzipped `.xml.gz`, and remote URLs (downloaded to
 * a temp `.gz` first, then decompressed on the fly — the 207 MB XML is never
 * fully materialised).
 */
final class DatexFeedReader
{
    /** @return Generator<int, SimpleXMLElement> */
    public function read(string $urlOrPath): Generator
    {
        [$uri, $tmp] = $this->resolve($urlOrPath);

        $reader = new XMLReader;
        if (! $reader->open($uri)) {
            throw new RuntimeException("Unable to open DATEX feed: {$urlOrPath}");
        }

        try {
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'situation') {
                    $doc = new DOMDocument;
                    $node = $reader->expand($doc);
                    if ($node !== false) {
                        $doc->appendChild($node);
                        yield simplexml_import_dom($node);
                    }
                    $reader->next();
                }
            }
        } finally {
            $reader->close();
            if ($tmp !== null) {
                @unlink($tmp);
            }
        }
    }

    /**
     * @return array{0: string, 1: ?string} [uri to open, temp file to clean up or null]
     */
    private function resolve(string $urlOrPath): array
    {
        $isUrl = str_starts_with($urlOrPath, 'http://') || str_starts_with($urlOrPath, 'https://');

        if ($isUrl) {
            $tmp = tempnam(sys_get_temp_dir(), 'datex').'.gz';
            $in = fopen($urlOrPath, 'rb');
            $out = fopen($tmp, 'wb');
            if ($in === false || $out === false) {
                throw new RuntimeException("Unable to download DATEX feed: {$urlOrPath}");
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);

            return ["compress.zlib://{$tmp}", $tmp];
        }

        $uri = str_ends_with($urlOrPath, '.gz') ? "compress.zlib://{$urlOrPath}" : $urlOrPath;

        return [$uri, null];
    }
}
