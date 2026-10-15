<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppliedAdvance extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'document_id',
        'advance_document_id',
        'advance_type_code',
        'advance_series',
        'advance_correlative',
        'advance_issue_date',
        'amount',
        'description',
        'is_reversed',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'advance_issue_date' => 'date',
            'amount' => 'float',
            'is_reversed' => 'boolean',
        ];
    }

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function advanceDocument()
    {
        return $this->belongsTo(Document::class, 'advance_document_id');
    }
}
