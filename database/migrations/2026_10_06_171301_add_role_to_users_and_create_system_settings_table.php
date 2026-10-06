<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add role & is_active to users
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 30)->default('developer')->after('email');
            $table->boolean('is_active')->default(true)->after('role');
        });

        // Ensure user ID 1 or admin is superadmin
        DB::table('users')
            ->where('id', 1)
            ->orWhere('email', 'admin@factos.pe')
            ->update([
                'role' => 'superadmin',
                'is_active' => true,
            ]);

        // 2. Create system_settings table
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->longText('value')->nullable();
            $table->string('group', 50)->default('general')->index();
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });

        // 3. Seed default system settings
        $now = now();
        $defaultSettings = [
            // Correo del Facturador
            ['key' => 'mail_mailer', 'value' => (string) config('mail.default', 'smtp'), 'group' => 'mail', 'description' => 'Driver de correo (smtp, log, sendmail)'],
            ['key' => 'mail_host', 'value' => (string) config('mail.mailers.smtp.host', 'smtp.gmail.com'), 'group' => 'mail', 'description' => 'Servidor SMTP del sistema'],
            ['key' => 'mail_port', 'value' => (string) config('mail.mailers.smtp.port', 587), 'group' => 'mail', 'description' => 'Puerto SMTP'],
            ['key' => 'mail_username', 'value' => (string) (config('mail.mailers.smtp.username') ?? ''), 'group' => 'mail', 'description' => 'Usuario o correo SMTP'],
            ['key' => 'mail_password', 'value' => (string) (config('mail.mailers.smtp.password') ?? ''), 'group' => 'mail', 'description' => 'Contraseña de aplicación SMTP'],
            ['key' => 'mail_encryption', 'value' => (string) (config('mail.mailers.smtp.encryption') ?? 'tls'), 'group' => 'mail', 'description' => 'Cifrado (tls, ssl, none)'],
            ['key' => 'mail_from_address', 'value' => (string) (config('mail.from.address') ?? 'facturacion@factos.pe'), 'group' => 'mail', 'description' => 'Dirección de correo remitente'],
            ['key' => 'mail_from_name', 'value' => (string) (config('mail.from.name') ?? 'Factos Facturador'), 'group' => 'mail', 'description' => 'Nombre del remitente'],

            // ApisPerú
            ['key' => 'api_key_dni_ruc', 'value' => (string) (config('factos.apisperu.token_dni_ruc') ?: env('API_KEY_DNI_RUC', '')), 'group' => 'apisperu', 'description' => 'Token Bearer de ApisPerú para DNI y RUC'],
            ['key' => 'api_key_tc', 'value' => (string) (config('factos.apisperu.token_exchange_rate') ?: env('API_KEY_TC', '')), 'group' => 'apisperu', 'description' => 'Token Bearer de ApisPerú para Tipo de Cambio'],
            ['key' => 'dni_ruc_url', 'value' => (string) config('factos.apisperu.dni_ruc_url', 'https://dniruc.apisperu.com/api/v1'), 'group' => 'apisperu', 'description' => 'URL base de consulta DNI/RUC'],
            ['key' => 'exchange_rate_url', 'value' => (string) config('factos.apisperu.exchange_rate_url', 'https://tipocambio.apisperu.com/api/v1'), 'group' => 'apisperu', 'description' => 'URL base de Tipo de Cambio'],
        ];

        foreach ($defaultSettings as $setting) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $setting['key']],
                [
                    'value' => $setting['value'],
                    'group' => $setting['group'],
                    'description' => $setting['description'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_settings');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_active']);
        });
    }
};
