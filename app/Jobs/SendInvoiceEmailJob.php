<?php

namespace App\Jobs;

use App\Mail\InvoiceMail;
use App\Models\Company;
use App\Models\Document;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendInvoiceEmailJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Document $document
    ) {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $this->document->loadMissing(['company']);
        $company = $this->document->company;

        // Cleanly discard if email notifications are inactive
        if (!$company->email_notifications_active) {
            return;
        }

        $clientEmail = null;
        if ($company->send_to_client_email && !empty($this->document->client_email)) {
            $clientEmail = filter_var($this->document->client_email, FILTER_VALIDATE_EMAIL) ?: null;
        }

        $ccEmails = [];
        if (!empty($company->company_copy_emails)) {
            foreach ($company->company_copy_emails as $email) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $ccEmails[] = $email;
                }
            }
        }

        if (!$clientEmail && empty($ccEmails)) {
            Log::info("SendInvoiceEmailJob: No valid recipient found for document {$this->document->id}");

            return;
        }

        if ($clientEmail) {
            $sent = false;

            // Intentar con las credenciales SMTP de la empresa si las proporcionó
            if ($company->hasCustomMailConfig()) {
                try {
                    $companyMailer = $this->resolveCompanyMailer($company);
                    $pendingMail = $companyMailer->to($clientEmail);
                    if (!empty($ccEmails)) {
                        $pendingMail->cc($ccEmails);
                    }

                    $pendingMail->send(new InvoiceMail($this->document, useFacturadorSender: false));
                    $sent = true;
                } catch (Throwable $e) {
                    Log::warning("SendInvoiceEmailJob: Falló el envío con credenciales de la empresa {$company->ruc} ({$e->getMessage()}). Reintentando con el correo del facturador...");
                }
            }

            // Si la empresa no proporcionó credenciales o falló el envío con las suyas, el facturador lo envía
            if (!$sent) {
                try {
                    $facturadorMailer = $this->resolveFacturadorMailer();
                    $pendingMail = $facturadorMailer->to($clientEmail);
                    if (!empty($ccEmails)) {
                        $pendingMail->cc($ccEmails);
                    }

                    $pendingMail->send(new InvoiceMail($this->document, useFacturadorSender: true));
                } catch (Throwable $e) {
                    Log::error("SendInvoiceEmailJob: Falló el envío de correo con el facturador para el comprobante {$this->document->id}: {$e->getMessage()}");
                }
            }
        } else {
            // Solo hay correos de copia de la empresa (sin cliente):
            // El correo del facturador se usa para enviar las notificaciones automáticas a la empresa emisora
            $primaryCompanyEmail = array_shift($ccEmails);
            try {
                $facturadorMailer = $this->resolveFacturadorMailer();
                $pendingMail = $facturadorMailer->to($primaryCompanyEmail);
                if (!empty($ccEmails)) {
                    $pendingMail->cc($ccEmails);
                }

                $pendingMail->send(new InvoiceMail($this->document, useFacturadorSender: true));
            } catch (Throwable $e) {
                Log::error("SendInvoiceEmailJob: Falló el envío de notificación automática a la empresa para el comprobante {$this->document->id}: {$e->getMessage()}");
            }
        }
    }

    private function resolveCompanyMailer(Company $company): Mailer
    {
        $mailerKey = "company_{$company->id}";
        $encryption = strtolower((string) ($company->mail_encryption ?: 'tls'));
        if ($encryption === 'none' || $encryption === 'null' || $encryption === '') {
            $encryption = null;
        }

        config([
            "mail.mailers.{$mailerKey}" => [
                'transport' => 'smtp',
                'host' => $company->mail_host ?: 'smtp.gmail.com',
                'port' => (int) ($company->mail_port ?: 587),
                'encryption' => $encryption,
                'username' => $company->mail_username,
                'password' => $company->mail_password,
                'timeout' => 15,
            ],
        ]);

        return Mail::mailer($mailerKey);
    }

    private function resolveFacturadorMailer(): Mailer
    {
        $mailer = \App\Models\SystemSetting::get('mail_mailer', config('mail.default', 'smtp'));

        if ($mailer === 'smtp') {
            $host = \App\Models\SystemSetting::get('mail_host', config('mail.mailers.smtp.host', 'smtp.gmail.com'));
            $port = (int) \App\Models\SystemSetting::get('mail_port', config('mail.mailers.smtp.port', 587));
            $username = \App\Models\SystemSetting::get('mail_username', config('mail.mailers.smtp.username'));
            $password = \App\Models\SystemSetting::get('mail_password', config('mail.mailers.smtp.password'));
            $encryption = strtolower((string) \App\Models\SystemSetting::get('mail_encryption', config('mail.mailers.smtp.encryption', 'tls')));
            if ($encryption === 'none' || $encryption === 'null' || $encryption === '') {
                $encryption = null;
            }

            config([
                'mail.mailers.facturador_system' => [
                    'transport' => 'smtp',
                    'host' => $host,
                    'port' => $port,
                    'encryption' => $encryption,
                    'username' => $username,
                    'password' => $password,
                    'timeout' => 15,
                ],
            ]);

            return Mail::mailer('facturador_system');
        }

        return Mail::mailer();
    }
}
