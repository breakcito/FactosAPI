<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\DocumentFactory;
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
 * @property CarbonInterface|null $due_date
 * @property string $currency
 * @property string $payment_method
 * @property array<string, mixed>|null $installments
 * @property array<string, mixed>|null $detraction
 * @property array<string, mixed>|null $retention
 * @property array<string, mixed>|null $prepayments
 * @property array<string, mixed>|null $related_documents
 * @property array<string, mixed>|null $note_data
 * @property string $client_doc_type
 * @property string $client_doc_number
 * @property string $client_name
 * @property string|null $client_address
 * @property string|null $client_email
 * @property string $total_taxable
 * @property string $total_unaffected
 * @property string $total_exonerated
 * @property string $total_igv
 * @property string $total_icbper
 * @property string $total_discount
 * @property string $total
 * @property string $status
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
 * @property-read Collection<int, DocumentItem> $items
 * @property-read Collection<int, WebhookDelivery> $webhookDeliveries
 */
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id',
        'external_id',
        'type_code',
        'operation_type',
        'series',
        'establishment_code',
        'correlative',
        'issue_date',
        'issue_time',
        'due_date',
        'currency',
        'payment_method',
        'installments',
        'detraction',
        'retention',
        'prepayments',
        'related_documents',
        'purchase_order',
        'plate_number',
        'note_data',
        'extra_fields',
        'client_doc_type',
        'client_doc_number',
        'client_name',
        'client_address',
        'client_email',
        'total_taxable',
        'total_unaffected',
        'total_exonerated',
        'total_free',
        'total_exportation',
        'total_igv',
        'total_icbper',
        'total_discount',
        'total',
        'status',
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
            'due_date' => 'date',
            'next_retry_at' => 'datetime',
            'voided_at' => 'datetime',
            'correlative' => 'integer',
            'retry_count' => 'integer',
            'total_taxable' => 'float',
            'total_unaffected' => 'float',
            'total_exonerated' => 'float',
            'total_free' => 'float',
            'total_exportation' => 'float',
            'total_igv' => 'float',
            'total_icbper' => 'float',
            'total_discount' => 'float',
            'total' => 'float',
            'sunat_notes' => 'array',
            'installments' => 'array',
            'detraction' => 'array',
            'retention' => 'array',
            'prepayments' => 'array',
            'related_documents' => 'array',
            'note_data' => 'array',
            'extra_fields' => 'array',
        ];
    }

    /**
     * Get document short number: F001-452.
     */
    public function getDocumentNumber(): string
    {
        return sprintf('%s-%s', $this->series, $this->correlative);
    }

    /**
     * Get full SUNAT file name without extension: {RUC}-{type_code}-{series}-{correlative}.
     */
    public function getSunatFileName(): string
    {
        return sprintf('%s-%s-%s-%s', $this->company->ruc, $this->type_code, $this->series, $this->correlative);
    }

    public function isInvoice(): bool
    {
        return $this->type_code === '01';
    }

    public function isBoleta(): bool
    {
        return $this->type_code === '03';
    }

    public function isCreditNote(): bool
    {
        return $this->type_code === '07';
    }

    public function isDebitNote(): bool
    {
        return $this->type_code === '08';
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
     * @return HasMany<DocumentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(DocumentItem::class);
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
