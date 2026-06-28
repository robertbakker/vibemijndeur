<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsCbsArea;
use Database\Factories\LandsdeelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A CBS landsdeel — the top of the area hierarchy (4 nationwide).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $year
 * @property string $geometry
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Provincie> $provincies
 * @property-read int|null $provincies_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Roadwork> $roadworks
 * @property-read int|null $roadworks_count
 * @method static \Database\Factories\LandsdeelFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Landsdeel newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Landsdeel newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Landsdeel query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Landsdeel whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Landsdeel whereGeometry($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Landsdeel whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Landsdeel whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Landsdeel whereYear($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Landsdeel withGeoJson()
 * @mixin \Eloquent
 */
#[Fillable(['code', 'name', 'year'])]
#[Table(name: 'landsdelen')]
#[WithoutTimestamps]
class Landsdeel extends Model
{
    /** @use HasFactory<LandsdeelFactory> */
    use HasFactory, IsCbsArea;

    public function provincies(): HasMany
    {
        return $this->hasMany(Provincie::class);
    }

    protected function roadworkPivotTable(): string
    {
        return 'roadwork_landsdeel';
    }

    protected function roadworkForeignKey(): string
    {
        return 'landsdeel_id';
    }
}
