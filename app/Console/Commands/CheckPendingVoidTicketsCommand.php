<?php

namespace App\Console\Commands;

use App\Jobs\SendWebhookJob;
use App\Models\Document;
use App\Services\Greenter\GreenterService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckPendingVoidTicketsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'documents:check-void-tickets {--limit=50 : Maximum number of void tickets to check}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check pending void tickets against SUNAT and finalize document voiding';

    /**
     * Execute the console command.
     */
    public function handle(GreenterService $greenterService): int
    {
        $limit = (int) $this->option('limit');

        $documents = Document::query()
            ->where('status', 'void_pending')
            ->whereNotNull('void_ticket')
            ->with('company')
            ->limit($limit)
            ->get();

        if ($documents->isEmpty()) {
            $this->info('No pending void tickets to check.');

            return self::SUCCESS;
        }

        $resolved = 0;
        foreach ($documents as $document) {
            $company = $document->company;

            try {
                $statusResult = $greenterService->checkTicketStatus($company, (string) $document->void_ticket);

                if ($statusResult->isSuccess()) {
                    $cdrResponse = $statusResult->getCdrResponse();
                    $cdrZip = $statusResult->getCdrZip();

                    if ($cdrZip) {
                        $cdrPath = $greenterService->saveCdr($document, $cdrZip, isVoid: true);
                        $document->void_cdr_path = $cdrPath;
                    }

                    $document->status = 'voided';
                    $document->voided_at = now();
                    $document->void_sunat_code = $cdrResponse ? (string) $cdrResponse->getCode() : '0';
                    $document->void_sunat_description = $cdrResponse ? $cdrResponse->getDescription() : 'Baja aceptada por SUNAT.';
                    $document->save();

                    SendWebhookJob::dispatch($document, 'document.voided')->onQueue('webhooks');
                    $resolved++;
                } else {
                    $code = (string) $statusResult->getCode();
                    if ($code === '98') {
                        $document->void_sunat_code = '98';
                        $document->void_sunat_description = 'Ticket en proceso de validación en SUNAT.';
                        $document->save();
                    } elseif ($code !== '' && $code !== '0') {
                        $document->status = 'accepted';
                        $document->void_sunat_code = $code;
                        $document->void_sunat_description = $statusResult->getError()?->getMessage() ?? 'Baja rechazada por SUNAT.';
                        $document->save();

                        SendWebhookJob::dispatch($document, 'document.void_failed')->onQueue('webhooks');
                        $resolved++;
                    }
                }
            } catch (Throwable $e) {
                Log::warning("Error verificando ticket de baja {$document->void_ticket} para documento {$document->id}: {$e->getMessage()}");
            }
        }

        $this->info("Checked {$documents->count()} void tickets, {$resolved} resolved.");

        return self::SUCCESS;
    }
}
