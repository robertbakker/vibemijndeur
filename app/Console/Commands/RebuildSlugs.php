<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Router\AreaSlugGenerator;
use Illuminate\Console\Command;

class RebuildSlugs extends Command
{
    protected $signature = 'slugs:rebuild';

    protected $description = 'Regenerate area slug rows in the unified slugs table (idempotent).';

    public function handle(AreaSlugGenerator $generator): int
    {
        $generator->rebuild();
        $this->info('Area slugs rebuilt.');

        return self::SUCCESS;
    }
}
