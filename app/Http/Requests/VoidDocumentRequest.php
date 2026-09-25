<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VoidDocumentRequest extends FormRequest
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
        $hasRouteDoc = (bool) ($this->route('document') || $this->route('id'));

        return [
            'reason' => ['required', 'string', 'min:3', 'max:250'],
            'company_id' => [$hasRouteDoc ? 'nullable' : 'required', 'uuid', 'exists:companies,id'],
            'type_code' => [$hasRouteDoc ? 'nullable' : 'required', 'string', 'in:01,03,07,08'],
            'series' => [$hasRouteDoc ? 'nullable' : 'required', 'string', 'size:4'],
            'correlative' => [$hasRouteDoc ? 'nullable' : 'required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'El motivo de anulación es obligatorio.',
            'company_id.required' => 'El identificador de la empresa emisora es obligatorio.',
            'series.required' => 'La serie del comprobante a anular es obligatoria.',
            'correlative.required' => 'El correlativo del comprobante a anular es obligatorio.',
        ];
    }
}
