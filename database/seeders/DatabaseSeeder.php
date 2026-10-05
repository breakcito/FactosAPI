<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'admin@factos.pe'],
            [
                'name' => 'Administrador Factos',
                'password' => bcrypt('password'),
            ]
        );

        Company::firstOrCreate(
            ['ruc' => '20000000001'],
            [
                'id' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
                'user_id' => $user->id,
                'business_name' => 'EMPRESA DE PRUEBA SUNAT S.A.C.',
                'trademark_name' => 'FACTOS BETA TEST',
                'address' => 'AV. LOS TESTERS 123 - URB. INDUSTRIAL',
                'ubigeo' => '150101',
                'department' => 'LIMA',
                'province' => 'LIMA',
                'district' => 'LIMA',
                'establishment_code' => '0000',
                'sol_user' => 'MODDATOS',
                'sol_pass' => 'moddatos',
                'client_id' => 'test-85e5b0ae-255c-4891-a595-0b98c65c9854',
                'client_secret' => 'test-Hty/M6QshYvPgItX2P0+Kw==',
                'certificate_path' => 'cert.pem',
                'certificate_pass' => '123456',
                'webhook_url' => 'https://webhook.site/demo-factos-receipt',
                'webhook_secret' => 'secret_webhook_factos_test_key_123',
                'is_production' => false,
                'is_active' => true,
                'email_notifications_active' => true,
                'company_copy_emails' => ['contabilidad@empresa-prueba.pe', 'gerencia@empresa-prueba.pe'],
                'send_to_client_email' => true,
                'email_template_settings' => [
                    'color' => '#1E40AF',
                    'footer_text' => 'Gracias por su preferencia - Comprobante electrónico emitido con Factos API',
                ],
                'mail_host' => 'smtp.gmail.com',
                'mail_port' => 587,
                'mail_username' => 'facturacion.empresa.prueba@gmail.com',
                'mail_password' => 'abcd efgh ijkl mnop',
                'mail_encryption' => 'tls',
                'mail_from_address' => 'facturacion.empresa.prueba@gmail.com',
                'mail_from_name' => 'Facturación - Empresa de Prueba S.A.C.',
            ]
        );
    }
}
