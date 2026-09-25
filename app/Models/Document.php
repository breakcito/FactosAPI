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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company $company
 * @property-read Collection<int, DocumentItem> $items
 * @property-read Collection<int, WebhookDelivery> $webhookDeliveries
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id',
        'external_id',
        'type_code',
        'series',
        'correlative',
        'issue_date',
        'issue_time',
        'due_date',
        'currency',
        'client_doc_type',
        'client_doc_number',
        'client_name',
        'client_address',
        'client_email',
        'total_taxable',
        'total_unaffected',
        'total_exonerated',
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
            'correlative' => 'integer',
            'retry_count' => 'integer',
            'total_taxable' => 'decimal:2',
            'total_unaffected' => 'decimal:2',
            'total_exonerated' => 'decimal:2',
            'total_igv' => 'decimal:2',
            'total_icbper' => 'decimal:2',
            'total_discount' => 'decimal:2',
            'total' => 'decimal:2',
            'sunat_notes' => 'array',
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
