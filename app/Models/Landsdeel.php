<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsCbsArea;
use Database\Factories\LandsdeelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 */
#[Fillable(['code', 'name', 'year'])]
class Landsdeel extends Model
{
    /** @use HasFactory<LandsdeelFactory> */
    use HasFactory, IsCbsArea;

    protected $table = 'landsdelen';

    public $timestamps = false;

    public function provincies(): HasMany
    {
        return $this->hasMany(Provincie::class);
    }
}
