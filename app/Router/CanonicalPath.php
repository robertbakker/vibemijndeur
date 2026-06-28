<?php

declare(strict_types=1);

namespace App\Router;

use App\Models\Slug;

final class CanonicalPath
{
    /**
     * The canonical single-segment path for an area slug. Area slugs are
     * globally unique (see AreaSlugGenerator), so the slug itself is canonical.
     */
    public static function for(Slug $slug): string
    {
        return $slug->slug;
    }
}
