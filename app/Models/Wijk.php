<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsCbsArea;
use Database\Factories\WijkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 */
#[Fillable(['code', 'name', 'year', 'gemeente_id'])]
class Wijk extends Model
{
    /** @use HasFactory<WijkFactory> */
    use HasFactory, IsCbsArea;

    protected $table = 'wijken';

    public $timestamps = false;

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
