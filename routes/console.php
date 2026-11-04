<?php

use App\Jobs\ProcessDespatchJob;
use App\Jobs\ProcessDocumentJob;
use App\Models\Despatch;
use App\Models\Document;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('documents:retry-waiting')->everyMinute();
Schedule::command('documents:check-void-tickets')->everyMinute();

Artisan::command('factos:process-document {id}', function (string $id) {
    $doc = Document::find($id);
    if ($doc) {
        ProcessDocumentJob::dispatchSync($doc);
    }
})->purpose('Procesa firma XML, envío a SUNAT, correo y webhook de un comprobante en segundo plano');

Artisan::command('factos:process-despatch {id}', function (string $id) {
    $desp = Despatch::find($id);
    if ($desp) {
        ProcessDespatchJob::dispatchSync($desp);
    }
})->purpose('Procesa firma XML, envío a SUNAT y webhook de una guía de remisión en segundo plano');
