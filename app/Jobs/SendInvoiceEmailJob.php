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
        if (! $company->email_notifications_active) {
            return;
        }

        $toEmail = null;
        if ($company->send_to_client_email && ! empty($this->document->client_email)) {
            $toEmail = filter_var($this->document->client_email, FILTER_VALIDATE_EMAIL) ?: null;
        }

        $ccEmails = [];
        if (! empty($company->company_copy_emails)) {
            foreach ($company->company_copy_emails as $email) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $ccEmails[] = $email;
                }
            }
        }

        // If no client email, but copy emails exist, use first copy email as recipient
        if (! $toEmail && ! empty($ccEmails)) {
            $toEmail = array_shift($ccEmails);
        }

        if (! $toEmail) {
            Log::info("SendInvoiceEmailJob: No valid recipient found for document {$this->document->id}");

            return;
        }

        try {
            $mailer = $this->resolveMailer($company);

            $pendingMail = $mailer->to($toEmail);
            if (! empty($ccEmails)) {
                $pendingMail->cc($ccEmails);
            }

            $pendingMail->send(new InvoiceMail($this->document));
        } catch (Throwable $e) {
            Log::warning("Failed sending invoice email for document {$this->document->id}: {$e->getMessage()}");
        }
    }

    private function resolveMailer(Company $company): Mailer
    {
        if ($company->hasCustomMailConfig()) {
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

        return Mail::mailer();
    }
}
