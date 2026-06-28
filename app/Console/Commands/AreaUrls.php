<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Gemeente;
use App\Models\Provincie;
use App\Models\Slug;
use App\Router\CanonicalPath;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('List canonical pretty URLs for all current area slugs.')]
#[Signature('area:urls')]
class AreaUrls extends Command
{
    public function handle(): int
    {
        $areaTypes = [(new Provincie)->getMorphClass(), (new Gemeente)->getMorphClass()];

        Slug::query()->where('is_current', true)
            ->whereIn('sluggable_type', $areaTypes)
            ->with('parent.parent.parent')
            ->chunk(500, function ($slugs): void {
                foreach ($slugs as $slug) {
                    $this->line('/'.CanonicalPath::for($slug));
                }
            });

        return self::SUCCESS;
    }
}
