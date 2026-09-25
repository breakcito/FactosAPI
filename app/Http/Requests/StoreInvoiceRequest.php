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

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $typeCode = str_starts_with(strtoupper($this->input('series', '')), 'B') ? '03' : '01';

        return [
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
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
            'client' => ['required', 'array'],
            'client.doc_type' => ['required', 'string', 'in:0,1,4,6,7'],
            'client.doc_number' => ['required', 'string', 'max:15'],
            'client.name' => ['required', 'string', 'max:255'],
            'client.address' => ['nullable', 'string', 'max:255'],
            'client.email' => ['nullable', 'email', 'max:255'],
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

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'company_id.required' => 'El identificador de la empresa emisora es obligatorio.',
            'company_id.exists' => 'La empresa emisora especificada no existe.',
            'series.regex' => 'La serie debe comenzar con F (Factura) o B (Boleta) y tener 4 caracteres alfanuméricos.',
            'correlative.unique' => 'Ya existe un comprobante emitido con esta misma serie y correlativo para esta empresa.',
            'items.min' => 'El comprobante debe contener al menos un ítem.',
            'totals.total.required' => 'El importe total del comprobante es obligatorio.',
        ];
    }
}
