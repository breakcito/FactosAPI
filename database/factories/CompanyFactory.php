<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'ruc' => '20'.fake()->numerify('#########'),
            'business_name' => fake()->company().' S.A.C.',
            'trademark_name' => fake()->company(),
            'address' => fake()->streetAddress(),
            'ubigeo' => '150101',
            'department' => 'LIMA',
            'province' => 'LIMA',
            'district' => 'LIMA',
            'sol_user' => 'MODDATOS',
            'sol_pass' => 'moddatos',
            'certificate_path' => 'certificates/cert.pem',
            'certificate_pass' => '123456',
            'webhook_url' => 'https://example.com/webhook',
            'webhook_secret' => 'supersecretwebhookkey',
            'is_production' => false,
            'is_active' => true,
            'email_notifications_active' => false,
            'company_copy_emails' => ['admin@empresa.com'],
            'send_to_client_email' => false,
            'email_template_settings' => [
                'subject' => 'Comprobante Electrónico',
                'primary_color' => '#1a56a0',
            ],
        ];
    }
}
