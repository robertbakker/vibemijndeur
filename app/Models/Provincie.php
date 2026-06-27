<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsCbsArea;
use Database\Factories\ProvincieFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 */
#[Fillable(['code', 'name', 'year', 'landsdeel_id'])]
class Provincie extends Model
{
    /** @use HasFactory<ProvincieFactory> */
    use HasFactory, IsCbsArea;

    protected $table = 'provincies';

    public $timestamps = false;

    public function landsdeel(): BelongsTo
    {
        return $this->belongsTo(Landsdeel::class);
    }

    public function gemeenten(): HasMany
    {
        return $this->hasMany(Gemeente::class);
    }
}
