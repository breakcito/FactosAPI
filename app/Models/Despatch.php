<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\DespatchFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $company_id
 * @property string|null $external_id
 * @property string $type_code
 * @property string $series
 * @property int $correlative
 * @property CarbonInterface $issue_date
 * @property string $issue_time
 * @property CarbonInterface $transfer_date
 * @property CarbonInterface|null $delivery_date
 * @property string $transport_mode
 * @property string $transfer_reason
 * @property string|null $transfer_description
 * @property string $total_weight
 * @property string $weight_unit
 * @property int $packages_count
 * @property string $recipient_doc_type
 * @property string $recipient_doc_number
 * @property string $recipient_name
 * @property string|null $recipient_address
 * @property string|null $recipient_email
 * @property string $origin_ubigeo
 * @property string $origin_address
 * @property string $destination_ubigeo
 * @property string $destination_address
 * @property string|null $carrier_doc_type
 * @property string|null $carrier_doc_number
 * @property string|null $carrier_name
 * @property string|null $carrier_mtc
 * @property string|null $driver_doc_type
 * @property string|null $driver_doc_number
 * @property string|null $driver_name
 * @property string|null $driver_license
 * @property string|null $vehicle_plate
 * @property string|null $secondary_vehicle_plate
 * @property array<string, mixed>|null $related_documents
 * @property string $status
 * @property string|null $sunat_ticket
 * @property string|null $sunat_code
 * @property string|null $sunat_description
 * @property array<string>|null $sunat_notes
 * @property string|null $hash
 * @property string|null $xml_path
 * @property string|null $cdr_path
 * @property string|null $pdf_path
 * @property int $retry_count
 * @property CarbonInterface|null $next_retry_at
 * @property string|null $void_ticket
 * @property string|null $void_reason
 * @property string|null $void_xml_path
 * @property string|null $void_cdr_path
 * @property string|null $void_sunat_code
 * @property string|null $void_sunat_description
 * @property CarbonInterface|null $voided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company $company
 * @property-read Collection<int, DespatchItem> $items
 * @property-read Collection<int, WebhookDelivery> $webhookDeliveries
 */
class Despatch extends Model
{
    /** @use HasFactory<DespatchFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id',
        'external_id',
        'type_code',
        'series',
        'correlative',
        'issue_date',
        'issue_time',
        'transfer_date',
        'delivery_date',
        'transport_mode',
        'transfer_reason',
        'transfer_description',
        'total_weight',
        'weight_unit',
        'packages_count',
        'recipient_doc_type',
        'recipient_doc_number',
        'recipient_name',
        'recipient_address',
        'recipient_email',
        'origin_ubigeo',
        'origin_address',
        'destination_ubigeo',
        'destination_address',
        'carrier_doc_type',
        'carrier_doc_number',
        'carrier_name',
        'carrier_mtc',
        'driver_doc_type',
        'driver_doc_number',
        'driver_name',
        'driver_license',
        'vehicle_plate',
        'secondary_vehicle_plate',
        'related_documents',
        'status',
        'sunat_ticket',
        'sunat_code',
        'sunat_description',
        'sunat_notes',
        'hash',
        'xml_path',
        'cdr_path',
        'pdf_path',
        'retry_count',
        'next_retry_at',
        'void_ticket',
        'void_reason',
        'void_xml_path',
        'void_cdr_path',
        'void_sunat_code',
        'void_sunat_description',
        'voided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'transfer_date' => 'date',
            'delivery_date' => 'date',
            'next_retry_at' => 'datetime',
            'voided_at' => 'datetime',
            'correlative' => 'integer',
            'packages_count' => 'integer',
            'retry_count' => 'integer',
            'total_weight' => 'decimal:3',
            'sunat_notes' => 'array',
            'related_documents' => 'array',
        ];
    }

    public function getDocumentNumber(): string
    {
        return sprintf('%s-%s', $this->series, $this->correlative);
    }

    public function getSunatFileName(): string
    {
        return sprintf('%s-%s-%s-%s', $this->company->ruc, $this->type_code, $this->series, $this->correlative);
    }

    public function isPublicTransport(): bool
    {
        return $this->transport_mode === '01';
    }

    public function isPrivateTransport(): bool
    {
        return $this->transport_mode === '02';
    }

    public function isVoided(): bool
    {
        return $this->status === 'voided';
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<DespatchItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(DespatchItem::class);
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
