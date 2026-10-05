<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'type_code' => $this->determineTypeCode(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $typeCode = $this->determineTypeCode();

        return [
            'company_id' => [
                'required',
                'uuid',
                Rule::exists('companies', 'id')->where('user_id', $this->user()?->id),
            ],
            'type_code' => ['nullable', 'string', 'in:01,03,07,08'],
            'external_id' => ['nullable', 'string', 'max:100'],
            'series' => ['required', 'string', 'size:4', 'regex:/^[FB][A-Z0-9]{3}$/i'],
            'correlative' => [
                'required',
                'integer',
                'min:1',
                Rule::unique('documents')->where(function ($query) use ($typeCode) {
                    return $query->where('company_id', $this->input('company_id'))
                        ->where('type_code', $typeCode)
                        ->where('series', strtoupper($this->input('series', '')));
                }),
            ],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'issue_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'currency' => ['required', 'string', 'in:PEN,USD,EUR'],

            // Forma de pago y Cuotas
            'payment_method' => ['nullable', 'string', 'in:contado,credito,CONTADO,CREDITO'],
            'installments' => ['nullable', 'array', 'required_if:payment_method,credito,CREDITO'],
            'installments.*.due_date' => ['required_with:installments', 'date_format:Y-m-d'],
            'installments.*.amount' => ['required_with:installments', 'numeric', 'min:0.01'],

            // Detracciones y Retenciones
            'detraction' => ['nullable', 'array'],
            'detraction.payment_method_code' => ['required_with:detraction', 'string'],
            'detraction.bank_account' => ['required_with:detraction', 'string'],
            'detraction.service_code' => ['required_with:detraction', 'string'],
            'detraction.percent' => ['required_with:detraction', 'numeric', 'min:0'],
            'detraction.amount' => ['required_with:detraction', 'numeric', 'min:0'],

            'retention' => ['nullable', 'array'],
            'retention.code' => ['required_with:retention', 'string'],
            'retention.percent' => ['required_with:retention', 'numeric', 'min:0'],
            'retention.amount' => ['required_with:retention', 'numeric', 'min:0'],

            // Anticipos y Guías / Docs Relacionados
            'prepayments' => ['nullable', 'array'],
            'prepayments.*.type_code' => ['required_with:prepayments', 'string'],
            'prepayments.*.number' => ['required_with:prepayments', 'string'],
            'prepayments.*.total' => ['required_with:prepayments', 'numeric', 'min:0.01'],

            'related_documents' => ['nullable', 'array'],
            'related_documents.*.type_code' => ['required_with:related_documents', 'string'],
            'related_documents.*.number' => ['required_with:related_documents', 'string'],

            // Datos de Nota (si es 07 u 08)
            'note' => ['nullable', 'array', Rule::requiredIf(in_array($typeCode, ['07', '08']))],
            'note.affected_type' => ['required_with:note', 'string', 'in:01,03'],
            'note.affected_series' => ['required_with:note', 'string', 'size:4'],
            'note.affected_correlative' => ['required_with:note', 'integer', 'min:1'],
            'note.code' => ['required_with:note', 'string'],
            'note.reason' => ['required_with:note', 'string', 'max:250'],

            // Cliente
            'client' => ['required', 'array'],
            'client.doc_type' => [
                'required',
                'string',
                $typeCode === '01' ? 'in:6' : 'in:0,1,4,6,7',
            ],
            'client.doc_number' => [
                'required',
                'string',
                $typeCode === '01' ? 'size:11' : 'max:15',
                $typeCode === '01' ? 'regex:/^(10|15|17|20)\d{9}$/' : 'regex:/^[A-Z0-9\-]+$/i',
            ],
            'client.name' => ['required', 'string', 'max:255'],
            'client.address' => ['nullable', 'string', 'max:255'],
            'client.email' => ['nullable', 'email', 'max:255'],

            // Items
            'items' => ['required', 'array', 'min:1'],
            'items.*.internal_code' => ['nullable', 'string', 'max:50'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.unit_code' => ['required', 'string', 'max:5'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_value' => ['required', 'numeric', 'min:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.igv_type' => ['required', 'string', 'in:10,11,12,13,14,15,16,17,20,21,30,31,32,33,34,35,36,40'],
            'items.*.igv_amount' => ['required', 'numeric', 'min:0'],
            'items.*.total' => ['required', 'numeric', 'min:0'],

            // Totales
            'totals' => ['required', 'array'],
            'totals.taxable' => ['nullable', 'numeric', 'min:0'],
            'totals.unaffected' => ['nullable', 'numeric', 'min:0'],
            'totals.exonerated' => ['nullable', 'numeric', 'min:0'],
            'totals.igv' => ['nullable', 'numeric', 'min:0'],
            'totals.icbper' => ['nullable', 'numeric', 'min:0'],
            'totals.discount' => ['nullable', 'numeric', 'min:0'],
            'totals.total' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function determineTypeCode(): string
    {
        if ($this->filled('type_code')) {
            return (string) $this->input('type_code');
        }

        $routeName = (string) $this->route()?->getName();
        if (str_contains($routeName, 'credit-notes')) {
            return '07';
        }
        if (str_contains($routeName, 'debit-notes')) {
            return '08';
        }
        if (str_contains($routeName, 'boletas')) {
            return '03';
        }
        if (str_contains($routeName, 'invoices')) {
            return '01';
        }

        if ($this->is('*/credit-notes*')) {
            return '07';
        }
        if ($this->is('*/debit-notes*')) {
            return '08';
        }
        if ($this->is('*/boletas*')) {
            return '03';
        }
        if ($this->is('*/invoices*')) {
            return '01';
        }

        $series = strtoupper((string) $this->input('series', ''));
        if (str_starts_with($series, 'B')) {
            return '03'; // Boleta
        }

        return '01'; // Factura
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $typeCode = $this->determineTypeCode();

        return [
            'company_id.required' => 'El identificador de la empresa emisora es obligatorio.',
            'company_id.exists' => 'La empresa emisora especificada no existe o no pertenece a su usuario.',
            'series.regex' => 'La serie debe comenzar con F o B y tener 4 caracteres alfanuméricos.',
            'correlative.unique' => 'Ya existe un comprobante emitido con esta misma serie y correlativo para esta empresa.',
            'items.min' => 'El comprobante debe contener al menos un ítem.',
            'totals.total.required' => 'El importe total del comprobante es obligatorio.',
            'installments.required_if' => 'Las cuotas son requeridas cuando la forma de pago es al crédito.',
            'note.required' => 'El objeto note es obligatorio para Notas de Crédito y Débito.',
            'client.doc_type.in' => $typeCode === '01'
                ? 'Para Facturas (01) el tipo de documento del cliente debe ser RUC (6).'
                : 'El tipo de documento del cliente no es válido.',
            'client.doc_number.size' => 'El RUC del cliente debe tener exactamente 11 dígitos.',
            'client.doc_number.regex' => $typeCode === '01'
                ? 'El RUC del cliente debe tener 11 dígitos numéricos válidos (comenzar con 10, 15, 17 o 20).'
                : 'El número de documento del cliente no es válido.',
        ];
    }
}
