<?php

namespace App\Models;

use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $company_id
 * @property string|null $document_id
 * @property string|null $despatch_id
 * @property string $event
 * @property array<string, mixed> $payload
 * @property int|null $response_code
 * @property string|null $response_body
 * @property string $status
 * @property int $attempts
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 * @property-read Company $company
 * @property-read Document|null $document
 * @property-read Despatch|null $despatch
 */
class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id',
        'document_id',
        'despatch_id',
        'event',
        'payload',
        'response_code',
        'response_body',
        'status',
        'attempts',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'response_code' => 'integer',
            'attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<Despatch, $this>
     */
    public function despatch(): BelongsTo
    {
        return $this->belongsTo(Despatch::class);
    }
}
