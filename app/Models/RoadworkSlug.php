<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;

#[Guarded(['id'])]
class RoadworkSlug extends Model
{
    protected $table = 'roadwork_slugs';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['is_current' => 'boolean'];
    }
}
