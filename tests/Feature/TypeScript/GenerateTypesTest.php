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

        // ProjectDetail is emitted directly under the App.Data namespace, and
        // carries `slug` — proving the DTO is the source of truth (fixes the
        // prior drift where the hand-written interface lacked it).
        $this->assertMatchesRegularExpression(
            '/namespace App \{\s*namespace Data \{\s*export type ProjectDetail = \{[^}]*\bslug\b:[^}]*\};/s',
            $contents,
        );

        // ProjectDetail's numeric `progress` is emitted as `number`, proving a
        // typed scalar (not a loose `any`) flows through from the DTO.
        $this->assertMatchesRegularExpression(
            '/export type ProjectDetail = \{.*?progress: number,/s',
            $contents,
        );

        // RoadworkCard carries the design's status palette fields as typed
        // strings — the card DTO is the single source of truth for the UI.
        $this->assertMatchesRegularExpression(
            '/export type RoadworkCard = \{.*?statusKey: string,.*?markerColor: string,/s',
            $contents,
        );
    }
}
