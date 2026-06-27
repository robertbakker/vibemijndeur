<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsCbsArea;
use Database\Factories\BuurtFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 */
#[Fillable(['code', 'name', 'year', 'wijk_id', 'gemeente_id'])]
class Buurt extends Model
{
    /** @use HasFactory<BuurtFactory> */
    use HasFactory, IsCbsArea;

    protected $table = 'buurten';

    public $timestamps = false;

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
