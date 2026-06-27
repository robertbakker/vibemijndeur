<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsCbsArea;
use Database\Factories\GemeenteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 */
#[Fillable(['code', 'name', 'year', 'provincie_id'])]
class Gemeente extends Model
{
    /** @use HasFactory<GemeenteFactory> */
    use HasFactory, IsCbsArea;

    protected $table = 'gemeenten';

    public $timestamps = false;

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
}
