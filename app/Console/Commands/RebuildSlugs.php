<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Router\AreaSlugGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Regenerate area slug rows in the unified slugs table (idempotent).')]
#[Signature('slugs:rebuild')]
class RebuildSlugs extends Command
{
    public function handle(AreaSlugGenerator $generator): int
    {
        $generator->rebuild();
        $this->info('Area slugs rebuilt.');

        return self::SUCCESS;
    }
}
