<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDespatchRequest extends FormRequest
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
        return [
            'company_id' => [
                'required',
                'uuid',
                Rule::exists('companies', 'id')
                    ->whereNull('deleted_at')
                    ->where('is_active', true)
                    ->when(!$this->user()?->isSuperAdmin(), function ($query) {
                        return $query->where('user_id', $this->user()?->id);
                    }),
            ],
            'is_test' => ['nullable', 'boolean'],
            'test_mode' => ['nullable', 'boolean'],
            'is_production' => ['nullable', 'boolean'],
            'type_code' => ['nullable', 'string', 'in:09,31'],
            'external_id' => ['nullable', 'string', 'max:100'],
            'series' => ['required', 'string', 'size:4', 'regex:/^[TV][A-Z0-9]{3}$/i'],
            'correlative' => [
                'required',
                'integer',
                'min:1',
                Rule::unique('despatches')->where(function ($query) {
                    return $query->where('company_id', $this->input('company_id'))
                        ->where('type_code', $this->input('type_code', '09'))
                        ->where('series', strtoupper($this->input('series', '')));
                }),
            ],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'issue_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/'],
            'transfer_date' => ['required', 'date_format:Y-m-d'],
            'delivery_date' => ['nullable', 'date_format:Y-m-d'],
            'transport_mode' => ['required', 'string', 'in:01,02'],
            'transfer_reason' => ['required', 'string', 'max:5'],
            'transfer_description' => ['nullable', 'string', 'max:255'],
            'total_weight' => ['required', 'numeric', 'min:0.001'],
            'weight_unit' => ['required', 'string', 'in:KGM,TNE'],
            'packages_count' => ['required', 'integer', 'min:1'],

            // Destinatario
            'recipient' => ['required', 'array'],
            'recipient.doc_type' => ['required', 'string', 'in:1,4,6,7'],
            'recipient.doc_number' => ['required', 'string', 'max:15'],
            'recipient.name' => ['required', 'string', 'max:255'],
            'recipient.address' => ['nullable', 'string', 'max:255'],
            'recipient.email' => ['nullable', 'email', 'max:255'],

            // Puntos de partida y llegada
            'origin' => ['required', 'array'],
            'origin.ubigeo' => ['required', 'string', 'size:6'],
            'origin.address' => ['required', 'string', 'max:255'],
            'destination' => ['required', 'array'],
            'destination.ubigeo' => ['required', 'string', 'size:6'],
            'destination.address' => ['required', 'string', 'max:255'],

            // Transportista público
            'carrier' => ['nullable', 'array', 'required_if:transport_mode,01'],
            'carrier.doc_type' => ['nullable', 'string', 'in:6'],
            'carrier.doc_number' => ['required_with:carrier', 'string', 'max:15'],
            'carrier.name' => ['required_with:carrier', 'string', 'max:255'],
            'carrier.mtc' => ['nullable', 'string', 'max:50'],

            // Transporte privado
            'driver' => ['nullable', 'array', 'required_if:transport_mode,02'],
            'driver.doc_type' => ['nullable', 'string', 'in:1,4,7'],
            'driver.doc_number' => ['required_with:driver', 'string', 'max:15'],
            'driver.name' => ['required_with:driver', 'string', 'max:255'],
            'driver.license' => ['required_with:driver', 'string', 'max:50'],

            'vehicle' => ['nullable', 'array', 'required_if:transport_mode,02'],
            'vehicle.plate' => ['required_with:vehicle', 'string', 'max:20'],
            'vehicle.secondary_plate' => ['nullable', 'string', 'max:20'],

            // Documentos Relacionados
            'related_documents' => ['nullable', 'array'],
            'related_documents.*.type_code' => ['required_with:related_documents', 'string'],
            'related_documents.*.number' => ['required_with:related_documents', 'string'],

            // Items
            'items' => ['required', 'array', 'min:1'],
            'items.*.internal_code' => ['nullable', 'string', 'max:50'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.unit_code' => ['required', 'string', 'max:5'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'company_id.required' => 'El identificador de la empresa emisora es obligatorio.',
            'company_id.exists' => 'La empresa emisora especificada no existe o no pertenece a su usuario.',
            'series.regex' => 'La serie debe comenzar con T o V y tener 4 caracteres alfanuméricos.',
            'correlative.unique' => 'Ya existe una guía emitida con esta serie y correlativo para esta empresa.',
            'carrier.required_if' => 'Los datos de la empresa de transporte son obligatorios para transporte público (01).',
            'driver.required_if' => 'Los datos del conductor son obligatorios para transporte privado (02).',
            'vehicle.required_if' => 'Los datos del vehículo son obligatorios para transporte privado (02).',
        ];
    }
}
