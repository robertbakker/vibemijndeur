<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsCbsArea;
use Database\Factories\GemeenteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A CBS gemeente. Its `provincie_id` parent is resolved spatially on import.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $year
 * @property int|null $provincie_id
 * @property string $geometry
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Buurt> $buurten
 * @property-read int|null $buurten_count
 * @property-read \App\Models\Provincie|null $provincie
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Roadwork> $roadworks
 * @property-read int|null $roadworks_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Wijk> $wijken
 * @property-read int|null $wijken_count
 * @method static \Database\Factories\GemeenteFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Gemeente newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Gemeente newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Gemeente query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Gemeente whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Gemeente whereGeometry($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Gemeente whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Gemeente whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Gemeente whereProvincieId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Gemeente whereYear($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Gemeente withGeoJson()
 * @mixin \Eloquent
 */
#[Fillable(['code', 'name', 'year', 'provincie_id'])]
#[Table(name: 'gemeenten')]
#[WithoutTimestamps]
class Gemeente extends Model
{
    /** @use HasFactory<GemeenteFactory> */
    use HasFactory, IsCbsArea;

    public function provincie(): BelongsTo
    {
        return $this->belongsTo(Provincie::class);
    }

    public function wijken(): HasMany
    {
        return $this->hasMany(Wijk::class);
    }

    public function buurten(): HasMany
    {
        return $this->hasMany(Buurt::class);
    }

    protected function roadworkPivotTable(): string
    {
        return 'roadwork_gemeente';
    }

    protected function roadworkForeignKey(): string
    {
        return 'gemeente_id';
    }
}
