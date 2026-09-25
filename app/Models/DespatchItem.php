<?php

namespace App\Models;

use Database\Factories\DespatchItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $despatch_id
 * @property string|null $internal_code
 * @property string $description
 * @property string $unit_code
 * @property string $quantity
 * @property-read Despatch $despatch
 */
class DespatchItem extends Model
{
    /** @use HasFactory<DespatchItemFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'despatch_id',
        'internal_code',
        'description',
        'unit_code',
        'quantity',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<Despatch, $this>
     */
    public function despatch(): BelongsTo
    {
        return $this->belongsTo(Despatch::class);
    }
}
