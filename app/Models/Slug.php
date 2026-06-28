<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SlugFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A routing slug for any resolvable entity. Roadworks are flat (`parent_id`
 * null); CBS areas chain via their structural `parent_id`. One `is_current`
 * row per (parent_id, slug); historical rows redirect (301) to the current one.
 *
 * @property int $id
 * @property string $slug
 * @property string $sluggable_type
 * @property int $sluggable_id
 * @property int|null $parent_id
 * @property bool $is_current
 * @property string $created_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Slug> $children
 * @property-read int|null $children_count
 * @property-read Slug|null $parent
 * @property-read Model|\Eloquent $sluggable
 * @method static \Database\Factories\SlugFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Slug newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Slug newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Slug query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Slug whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Slug whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Slug whereIsCurrent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Slug whereParentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Slug whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Slug whereSluggableId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Slug whereSluggableType($value)
 * @mixin \Eloquent
 */
#[Guarded(['id'])]
#[Table(name: 'slugs')]
#[WithoutTimestamps]
class Slug extends Model
{
    /** @use HasFactory<SlugFactory> */
    use HasFactory;

    #[\Override]
    protected function casts(): array
    {
        return ['is_current' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function sluggable(): MorphTo
    {
        return $this->morphTo();
    }
}
