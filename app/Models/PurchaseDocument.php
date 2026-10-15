<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseDocument extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id',
        'supplier_doc_type',
        'supplier_doc_number',
        'supplier_name',
        'type_code',
        'series',
        'correlative',
        'issue_date',
        'due_date',
        'currency',
        'exchange_rate',
        'taxable_base',
        'igv',
        'unaffected',
        'exonerated',
        'other_taxes',
        'total',
        'goods_description',
        'items',
        'observations',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'exchange_rate' => 'float',
            'taxable_base' => 'float',
            'igv' => 'float',
            'unaffected' => 'float',
            'exonerated' => 'float',
            'other_taxes' => 'float',
            'total' => 'float',
            'items' => 'array',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
