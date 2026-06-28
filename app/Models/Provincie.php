<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsCbsArea;
use Database\Factories\ProvincieFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A CBS provincie. Its `landsdeel_id` parent is resolved spatially on import.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $year
 * @property int|null $landsdeel_id
 * @property string $geometry
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Gemeente> $gemeenten
 * @property-read int|null $gemeenten_count
 * @property-read \App\Models\Landsdeel|null $landsdeel
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Roadwork> $roadworks
 * @property-read int|null $roadworks_count
 * @method static \Database\Factories\ProvincieFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provincie newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provincie newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provincie query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provincie whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provincie whereGeometry($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provincie whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provincie whereLandsdeelId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provincie whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provincie whereYear($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Provincie withGeoJson()
 * @mixin \Eloquent
 */
#[Fillable(['code', 'name', 'year', 'landsdeel_id'])]
#[Table(name: 'provincies')]
#[WithoutTimestamps]
class Provincie extends Model
{
    /** @use HasFactory<ProvincieFactory> */
    use HasFactory, IsCbsArea;

    public function landsdeel(): BelongsTo
    {
        return $this->belongsTo(Landsdeel::class);
    }

    public function gemeenten(): HasMany
    {
        return $this->hasMany(Gemeente::class);
    }

    protected function roadworkPivotTable(): string
    {
        return 'roadwork_provincie';
    }

    protected function roadworkForeignKey(): string
    {
        return 'provincie_id';
    }
}
