<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsCbsArea;
use Database\Factories\BuurtFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A CBS buurt — the leaf of the hierarchy. Its `wijk_id` parent is derived from the
 * buurt code (`BU` + 6 digits → `WK……`); `gemeente_id` from the CBS `gm_code`.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $year
 * @property int|null $wijk_id
 * @property int|null $gemeente_id
 * @property string $geometry
 * @property-read \App\Models\Gemeente|null $gemeente
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Roadwork> $roadworks
 * @property-read int|null $roadworks_count
 * @property-read \App\Models\Wijk|null $wijk
 * @method static \Database\Factories\BuurtFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Buurt newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Buurt newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Buurt query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Buurt whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Buurt whereGemeenteId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Buurt whereGeometry($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Buurt whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Buurt whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Buurt whereWijkId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Buurt whereYear($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Buurt withGeoJson()
 * @mixin \Eloquent
 */
#[Fillable(['code', 'name', 'year', 'wijk_id', 'gemeente_id'])]
#[Table(name: 'buurten')]
#[WithoutTimestamps]
class Buurt extends Model
{
    /** @use HasFactory<BuurtFactory> */
    use HasFactory, IsCbsArea;

    public function wijk(): BelongsTo
    {
        return $this->belongsTo(Wijk::class);
    }

    public function gemeente(): BelongsTo
    {
        return $this->belongsTo(Gemeente::class);
    }

    protected function roadworkPivotTable(): string
    {
        return 'roadwork_buurt';
    }

    protected function roadworkForeignKey(): string
    {
        return 'buurt_id';
    }
}
