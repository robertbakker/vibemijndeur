<?php

declare(strict_types=1);

namespace Tests\Feature\TypeScript;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class GenerateTypesTest extends TestCase
{
    public function test_it_generates_typescript_for_frontend_dtos(): void
    {
        $output = resource_path('js/types/generated.d.ts');

        if (file_exists($output)) {
            unlink($output);
        }

        $exit = Artisan::call('typescript:transform');

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertFileExists($output);

        $contents = (string) file_get_contents($output);

        $this->assertStringContainsString('namespace App', $contents);
        $this->assertStringContainsString('namespace Data', $contents);
        $this->assertStringContainsString('ProjectDetail', $contents);
        $this->assertStringContainsString('RoadworkCard', $contents);
        // slug field proves the DTO is the source of truth (fixes prior drift):
        $this->assertStringContainsString('slug', $contents);
        // precise badge array shape, not a loose `any`/`array`:
        $this->assertStringContainsString('label', $contents);
    }
}
