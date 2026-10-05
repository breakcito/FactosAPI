<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyRequest extends FormRequest
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
            'ruc' => ['required', 'string', 'size:11', 'regex:/^(10|15|17|20)\d{9}$/', 'unique:companies,ruc'],
            'business_name' => ['required', 'string', 'max:255'],
            'trademark_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'ubigeo' => ['nullable', 'string', 'max:6'],
            'department' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'sol_user' => ['required', 'string', 'max:50'],
            'sol_pass' => ['required', 'string'],
            'certificate' => ['nullable', 'file'],
            'certificate_path' => ['nullable', 'string', 'max:500'],
            'certificate_pass' => ['required', 'string'],
            'webhook_url' => ['nullable', 'url', 'max:500'],
            'webhook_secret' => ['nullable', 'string', 'max:100'],
            'is_production' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'email_notifications_active' => ['nullable', 'boolean'],
            'company_copy_emails' => ['nullable', 'array'],
            'company_copy_emails.*' => ['email'],
            'send_to_client_email' => ['nullable', 'boolean'],
            'email_template_settings' => ['nullable', 'array'],
        ];
    }
}
