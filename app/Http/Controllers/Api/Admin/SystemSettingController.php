<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SystemSettingController extends Controller
{
    public function index(): JsonResponse
    {
        $settings = SystemSetting::query()->get()->keyBy('key');

        $mailConfig = [
            'mail_mailer' => $settings['mail_mailer']->value ?? config('mail.default', 'smtp'),
            'mail_host' => $settings['mail_host']->value ?? config('mail.mailers.smtp.host', 'smtp.gmail.com'),
            'mail_port' => (int) ($settings['mail_port']->value ?? config('mail.mailers.smtp.port', 587)),
            'mail_username' => $settings['mail_username']->value ?? (config('mail.mailers.smtp.username') ?? ''),
            'mail_password' => $settings['mail_password']->value ? '********' : '',
            'has_password' => !empty($settings['mail_password']->value),
            'mail_encryption' => $settings['mail_encryption']->value ?? (config('mail.mailers.smtp.encryption') ?? 'tls'),
            'mail_from_address' => $settings['mail_from_address']->value ?? (config('mail.from.address') ?? 'facturacion@factos.pe'),
            'mail_from_name' => $settings['mail_from_name']->value ?? (config('mail.from.name') ?? 'Factos Facturador'),
        ];

        $apisPeruConfig = [
            'api_key_dni_ruc' => $settings['api_key_dni_ruc']->value ?? (config('factos.apisperu.token_dni_ruc') ?? ''),
            'api_key_tc' => $settings['api_key_tc']->value ?? (config('factos.apisperu.token_exchange_rate') ?? ''),
            'dni_ruc_url' => $settings['dni_ruc_url']->value ?? config('factos.apisperu.dni_ruc_url', 'https://dniruc.apisperu.com/api/v1'),
            'exchange_rate_url' => $settings['exchange_rate_url']->value ?? config('factos.apisperu.exchange_rate_url', 'https://tipocambio.apisperu.com/api/v1'),
        ];

        return response()->json([
            'status' => 'success',
            'data' => [
                'mail' => $mailConfig,
                'apisperu' => $apisPeruConfig,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mail.mail_mailer' => ['nullable', 'string', 'in:smtp,log,sendmail'],
            'mail.mail_host' => ['nullable', 'string', 'max:255'],
            'mail.mail_port' => ['nullable', 'integer', 'between:1,65535'],
            'mail.mail_username' => ['nullable', 'string', 'max:255'],
            'mail.mail_password' => ['nullable', 'string', 'max:255'],
            'mail.mail_encryption' => ['nullable', 'string', 'in:tls,ssl,none,TLS,SSL'],
            'mail.mail_from_address' => ['nullable', 'email', 'max:255'],
            'mail.mail_from_name' => ['nullable', 'string', 'max:255'],

            'apisperu.api_key_dni_ruc' => ['nullable', 'string'],
            'apisperu.api_key_tc' => ['nullable', 'string'],
            'apisperu.dni_ruc_url' => ['nullable', 'string', 'url'],
            'apisperu.exchange_rate_url' => ['nullable', 'string', 'url'],
        ]);

        if (isset($data['mail'])) {
            foreach ($data['mail'] as $key => $val) {
                // If password is '********', don't overwrite with asterisks
                if ($key === 'mail_password' && ($val === '********' || $val === '')) {
                    continue;
                }
                SystemSetting::set($key, $val, 'mail');
            }
        }

        if (isset($data['apisperu'])) {
            foreach ($data['apisperu'] as $key => $val) {
                SystemSetting::set($key, $val, 'apisperu');
            }
        }

        SystemSetting::clearCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Configuraciones del sistema guardadas exitosamente.',
        ]);
    }

    public function testMail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'recipient' => ['required', 'email'],
            'mail_host' => ['nullable', 'string'],
            'mail_port' => ['nullable', 'integer'],
            'mail_username' => ['nullable', 'string'],
            'mail_password' => ['nullable', 'string'],
            'mail_encryption' => ['nullable', 'string'],
            'mail_from_address' => ['nullable', 'email'],
            'mail_from_name' => ['nullable', 'string'],
        ]);

        $recipient = $data['recipient'];

        $host = $data['mail_host'] ?? SystemSetting::get('mail_host', config('mail.mailers.smtp.host', 'smtp.gmail.com'));
        $port = (int) ($data['mail_port'] ?? SystemSetting::get('mail_port', config('mail.mailers.smtp.port', 587)));
        $username = $data['mail_username'] ?? SystemSetting::get('mail_username', config('mail.mailers.smtp.username'));

        $password = $data['mail_password'] ?? '';
        if (empty($password) || $password === '********') {
            $password = SystemSetting::get('mail_password', config('mail.mailers.smtp.password'));
        }

        $encryption = strtolower((string) ($data['mail_encryption'] ?? SystemSetting::get('mail_encryption', config('mail.mailers.smtp.encryption', 'tls'))));
        $fromAddress = $data['mail_from_address'] ?? SystemSetting::get('mail_from_address', config('mail.from.address', 'facturacion@factos.pe'));
        $fromName = $data['mail_from_name'] ?? SystemSetting::get('mail_from_name', config('mail.from.name', 'Factos Facturador'));

        $testMailerKey = 'system_test_' . time();
        Config::set("mail.mailers.{$testMailerKey}", [
            'transport' => 'smtp',
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption === 'none' ? null : $encryption,
            'username' => $username,
            'password' => $password,
            'timeout' => 10,
        ]);

        try {
            Mail::mailer($testMailerKey)->raw(
                "¡Hola!\n\nEste es un correo de prueba enviado exitosamente desde el sistema gestor Factos.\nFecha y hora: " . now()->format('d/m/Y H:i:s') . "\nHost SMTP: {$host}:{$port}\nRemitente: {$fromName} <{$fromAddress}>",
                function ($message) use ($recipient, $fromAddress, $fromName) {
                    $message->to($recipient)
                        ->from($fromAddress, $fromName)
                        ->subject('✅ Prueba de Conexión SMTP - Factos Facturador');
                }
            );

            return response()->json([
                'status' => 'success',
                'message' => "Correo de prueba enviado satisfactoriamente a {$recipient}.",
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al enviar correo de prueba: ' . $e->getMessage(),
            ], 422);
        }
    }

    public function testApisPeru(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:dni,ruc,tc'],
            'query' => ['nullable', 'string'],
            'token' => ['nullable', 'string'],
        ]);

        $type = $data['type'];
        $query = $data['query'] ?? null;
        $customToken = $data['token'] ?? null;

        try {
            if ($type === 'dni') {
                $dni = $query ?: '47586940';
                $token = $customToken ?: SystemSetting::get('api_key_dni_ruc', config('factos.apisperu.token_dni_ruc', ''));
                $baseUrl = SystemSetting::get('dni_ruc_url', config('factos.apisperu.dni_ruc_url', 'https://dniruc.apisperu.com/api/v1'));

                $res = Http::withToken($token)->timeout(10)->get("{$baseUrl}/dni/{$dni}");
                if (!$res->successful()) {
                    throw new Exception("Error ({$res->status()}): {$res->body()}");
                }

                return response()->json([
                    'status' => 'success',
                    'message' => 'Consulta DNI exitosa',
                    'data' => $res->json(),
                ]);
            }

            if ($type === 'ruc') {
                $ruc = $query ?: '20000000001';
                $token = $customToken ?: SystemSetting::get('api_key_dni_ruc', config('factos.apisperu.token_dni_ruc', ''));
                $baseUrl = SystemSetting::get('dni_ruc_url', config('factos.apisperu.dni_ruc_url', 'https://dniruc.apisperu.com/api/v1'));

                $res = Http::withToken($token)->timeout(10)->get("{$baseUrl}/ruc/{$ruc}");
                if (!$res->successful()) {
                    throw new Exception("Error ({$res->status()}): {$res->body()}");
                }

                return response()->json([
                    'status' => 'success',
                    'message' => 'Consulta RUC exitosa',
                    'data' => $res->json(),
                ]);
            }

            if ($type === 'tc') {
                $token = $customToken ?: SystemSetting::get('api_key_tc', config('factos.apisperu.token_exchange_rate', ''));
                $baseUrl = SystemSetting::get('exchange_rate_url', config('factos.apisperu.exchange_rate_url', 'https://tipocambio.apisperu.com/api/v1'));

                $res = Http::withToken($token)->timeout(10)->get("{$baseUrl}/sunat?date=" . now()->toDateString());
                if (!$res->successful()) {
                    throw new Exception("Error ({$res->status()}): {$res->body()}");
                }

                return response()->json([
                    'status' => 'success',
                    'message' => 'Consulta Tipo de Cambio exitosa',
                    'data' => $res->json(),
                ]);
            }

            return response()->json(['status' => 'error', 'message' => 'Tipo no soportado'], 400);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
