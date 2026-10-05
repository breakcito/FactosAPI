<?php

namespace App\Console\Commands;

use App\Jobs\SendDespatchToSunatJob;
use App\Jobs\SendDocumentToSunatJob;
use App\Models\Despatch;
use App\Models\Document;
use Illuminate\Console\Command;

class RetryWaitingDocumentsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'documents:retry-waiting {--limit=50 : Maximum number of documents to retry}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sweep and retry pending documents and despatches in waiting_sunat status';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $documents = Document::query()
            ->where('status', 'waiting_sunat')
            ->where(function ($query): void {
                $query->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            })
            ->where('retry_count', '<', 10)
            ->limit($limit)
            ->get();

        $despatches = Despatch::query()
            ->where('status', 'waiting_sunat')
            ->where(function ($query): void {
                $query->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            })
            ->where('retry_count', '<', 10)
            ->limit($limit)
            ->get();

        if ($documents->isEmpty() && $despatches->isEmpty()) {
            $this->info('No waiting documents to retry.');

            return self::SUCCESS;
        }

        $count = 0;
        foreach ($documents as $document) {
            SendDocumentToSunatJob::dispatch($document)->onQueue('sunat');
            $count++;
        }

        $despatchCount = 0;
        foreach ($despatches as $despatch) {
            SendDespatchToSunatJob::dispatch($despatch)->onQueue('sunat');
            $despatchCount++;
        }

        if ($count > 0) {
            $this->info("Re-enqueued {$count} waiting documents for SUNAT processing.");
        }
        if ($despatchCount > 0) {
            $this->info("Re-enqueued {$despatchCount} waiting despatches for SUNAT processing.");
        }

        return self::SUCCESS;
    }
}
