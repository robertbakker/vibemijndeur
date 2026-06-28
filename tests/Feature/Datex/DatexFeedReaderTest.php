<?php

declare(strict_types=1);

namespace Tests\Feature\Datex;

use App\Roadworks\Datex\DatexFeedReader;
use PHPUnit\Framework\TestCase;

class DatexFeedReaderTest extends TestCase
{
    public function test_yields_each_situation_with_namespaces(): void
    {
        $ids = [];
        foreach ((new DatexFeedReader)->read(dirname(__DIR__, 2).'/Fixtures/datex/sample.xml') as $sit) {
            $sit->registerXPathNamespace('sit', 'http://datex2.eu/schema/3/situation');
            $ids[] = (string) $sit['id'];
        }

        $this->assertSame(['NDW03_100', 'NDW03_200', 'NDW03_300'], $ids);
    }

    public function test_reads_gzipped_file(): void
    {
        $gz = tempnam(sys_get_temp_dir(), 'datex').'.gz';
        file_put_contents("compress.zlib://{$gz}", file_get_contents(dirname(__DIR__, 2).'/Fixtures/datex/sample.xml'));

        $count = iterator_count((new DatexFeedReader)->read($gz));
        @unlink($gz);

        $this->assertSame(3, $count);
    }
}
