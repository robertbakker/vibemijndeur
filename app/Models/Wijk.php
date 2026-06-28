<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsCbsArea;
use Database\Factories\WijkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A CBS wijk. Its `gemeente_id` parent comes from the CBS `gm_code` attribute.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $year
 * @property int|null $gemeente_id
 * @property string $geometry
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Buurt> $buurten
 * @property-read int|null $buurten_count
 * @property-read \App\Models\Gemeente|null $gemeente
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Roadwork> $roadworks
 * @property-read int|null $roadworks_count
 * @method static \Database\Factories\WijkFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wijk newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wijk newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wijk query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wijk whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wijk whereGemeenteId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wijk whereGeometry($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wijk whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wijk whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wijk whereYear($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wijk withGeoJson()
 * @mixin \Eloquent
 */
#[Fillable(['code', 'name', 'year', 'gemeente_id'])]
#[Table(name: 'wijken')]
#[WithoutTimestamps]
class Wijk extends Model
{
    /** @use HasFactory<WijkFactory> */
    use HasFactory, IsCbsArea;

    public function gemeente(): BelongsTo
    {
        return $this->belongsTo(Gemeente::class);
    }

    public function buurten(): HasMany
    {
        return $this->hasMany(Buurt::class);
    }

    protected function roadworkPivotTable(): string
    {
        return 'roadwork_wijk';
    }

    protected function roadworkForeignKey(): string
    {
        return 'wijk_id';
    }
}
