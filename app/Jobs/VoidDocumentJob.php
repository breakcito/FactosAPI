<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\Greenter\GreenterService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class VoidDocumentJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Document $document,
        public string $reason
    ) {
        $this->onQueue('sunat');
    }

    public function handle(GreenterService $greenterService): void
    {
        $this->document->loadMissing('company');
        $company = $this->document->company;

        // Daily correlative for voiding: count void communications sent today for this company
        $todayStr = now()->format('Ymd');
        $correlative = (int) (Document::where('company_id', $company->id)
            ->where(function ($q) use ($todayStr): void {
                $q->where('void_xml_path', 'like', "%-{$todayStr}-%")
                    ->orWhere(function ($sub): void {
                        $sub->whereNotNull('void_ticket')
                            ->whereDate('updated_at', now()->toDateString());
                    });
            })
            ->where('id', '!=', $this->document->id)
            ->count() + 1);

        $voiding = $greenterService->sendVoiding($this->document, $this->reason, $correlative);
        $result = $voiding['result'];

        $this->document->void_reason = $this->reason;
        $this->document->void_xml_path = $voiding['xml_path'];

        if (! $result->isSuccess()) {
            // Keep document accepted so the company can retry voiding; record error in void fields
            $this->document->status = 'accepted';
            $this->document->void_sunat_code = $result->getError()?->getCode() ?? 'ERROR';
            $this->document->void_sunat_description = $result->getError()?->getMessage() ?? 'Error al comunicar baja a SUNAT.';
            $this->document->save();

            SendWebhookJob::dispatch($this->document, 'document.void_failed')->onQueue('webhooks');

            return;
        }

        $ticket = $result->getTicket();
        $this->document->void_ticket = $ticket;
        $this->document->status = 'void_pending';
        $this->document->save();

        // Check ticket status immediately
        try {
            $statusResult = $greenterService->checkTicketStatus($company, $ticket);

            if ($statusResult->isSuccess()) {
                $cdrResponse = $statusResult->getCdrResponse();
                $cdrZip = $statusResult->getCdrZip();

                if ($cdrZip) {
                    $cdrPath = $greenterService->saveCdr($this->document, $cdrZip, isVoid: true);
                    $this->document->void_cdr_path = $cdrPath;
                }

                $this->document->status = 'voided';
                $this->document->voided_at = now();
                $this->document->void_sunat_code = $cdrResponse ? $cdrResponse->getCode() : '0';
                $this->document->void_sunat_description = $cdrResponse ? $cdrResponse->getDescription() : 'Baja aceptada por SUNAT.';
                $this->document->save();

                SendWebhookJob::dispatch($this->document, 'document.voided')->onQueue('webhooks');
            } else {
                $this->document->void_sunat_code = $statusResult->getCode();
                $this->document->void_sunat_description = $statusResult->getError()?->getMessage();
                $this->document->save();
            }
        } catch (Throwable $e) {
            $this->document->void_sunat_description = 'Ticket generado: '.$ticket.'. Error al consultar: '.$e->getMessage();
            $this->document->save();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->document->update([
            'status' => 'accepted',
            'void_sunat_description' => $exception?->getMessage() ?? 'Error al procesar la anulación.',
        ]);
    }
}
