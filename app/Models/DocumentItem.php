<?php

namespace App\Models;

use Database\Factories\DocumentItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $document_id
 * @property string|null $internal_code
 * @property string $description
 * @property string $unit_code
 * @property string $quantity
 * @property string $unit_value
 * @property string $unit_price
 * @property string $igv_type
 * @property string $igv_amount
 * @property string $total
 * @property array<string, mixed>|null $attributes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Document $document
 */
class DocumentItem extends Model
{
    /** @use HasFactory<DocumentItemFactory> */
    use HasFactory;

    protected $fillable = [
        'document_id',
        'internal_code',
        'description',
        'unit_code',
        'quantity',
        'unit_value',
        'unit_price',
        'igv_type',
        'igv_amount',
        'total',
        'attributes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'unit_value' => 'float',
            'unit_price' => 'float',
            'igv_amount' => 'float',
            'total' => 'float',
            'attributes' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
