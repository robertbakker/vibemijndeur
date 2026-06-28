<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $roadwork_id
 * @property string $slug
 * @property bool $is_current
 * @property string $created_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoadworkSlug newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoadworkSlug newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoadworkSlug query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoadworkSlug whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoadworkSlug whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoadworkSlug whereIsCurrent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoadworkSlug whereRoadworkId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoadworkSlug whereSlug($value)
 * @mixin \Eloquent
 */
#[Guarded(['id'])]
#[Table(name: 'roadwork_slugs')]
#[WithoutTimestamps]
class RoadworkSlug extends Model
{
    #[\Override]
    protected function casts(): array
    {
        return ['is_current' => 'boolean'];
    }
}
