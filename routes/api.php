<?php

use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\MeController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\DespatchController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.health');

// API v1 Routes
Route::prefix('v1')->name('api.v1.')->group(function (): void {
    // Auth
    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::post('/register', RegisterController::class)->name('register');
        Route::post('/login', LoginController::class)->name('login');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('/me', MeController::class)->name('me');
            Route::post('/logout', LogoutController::class)->name('logout');
        });
    });

    // Public / Token-optional document download endpoints (using UUID links)
    Route::get('/documents/{document}/xml', [DocumentController::class, 'xml'])->name('documents.xml');
    Route::get('/documents/{document}/cdr', [DocumentController::class, 'cdr'])->name('documents.cdr');
    Route::get('/documents/{document}/pdf', [DocumentController::class, 'pdf'])->name('documents.pdf');
    Route::get('/documents/{document}/void-xml', [DocumentController::class, 'voidXml'])->name('documents.voidXml');
    Route::get('/documents/{document}/void-cdr', [DocumentController::class, 'voidCdr'])->name('documents.voidCdr');

    // Despatches public downloads
    Route::get('/despatches/{despatch}/xml', [DespatchController::class, 'xml'])->name('despatches.xml');
    Route::get('/despatches/{despatch}/cdr', [DespatchController::class, 'cdr'])->name('despatches.cdr');
    Route::get('/despatches/{despatch}/pdf', [DespatchController::class, 'pdf'])->name('despatches.pdf');

    // Protected endpoints
    Route::middleware('auth:sanctum')->group(function (): void {
        // Companies (Tenants)
        Route::apiResource('companies', CompanyController::class)->only(['index', 'store', 'show', 'update']);
        Route::get('/companies/{company}/webhooks', [CompanyController::class, 'webhooks'])->name('companies.webhooks');

        // Electronic Documents (Invoices, Boletas, Notas de Crédito, Notas de Débito)
        Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
        Route::post('/documents', [InvoiceController::class, 'store'])->name('documents.store');
        Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');

        // Dedicated Aliases for developer convenience
        Route::post('/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
        Route::post('/boletas', [InvoiceController::class, 'store'])->name('boletas.store');
        Route::post('/credit-notes', [InvoiceController::class, 'store'])->name('credit-notes.store');
        Route::post('/debit-notes', [InvoiceController::class, 'store'])->name('debit-notes.store');

        // Document Annullment / Bajas SUNAT
        Route::post('/documents/void', [DocumentController::class, 'void'])->name('documents.voidGeneral');
        Route::post('/documents/{document}/void', [DocumentController::class, 'void'])->name('documents.void');

        // Guías de Remisión Electrónica (GRE)
        Route::get('/despatches', [DespatchController::class, 'index'])->name('despatches.index');
        Route::post('/despatches', [DespatchController::class, 'store'])->name('despatches.store');
        Route::get('/despatches/{despatch}', [DespatchController::class, 'show'])->name('despatches.show');
        Route::post('/despatches/{despatch}/void', [DespatchController::class, 'void'])->name('despatches.void');

        // Auxiliary Services (DNI, RUC, Exchange Rate)
        Route::prefix('services')->name('services.')->group(function (): void {
            Route::get('/dni/{number}', [ServiceController::class, 'dni'])->name('dni');
            Route::get('/ruc/{number}', [ServiceController::class, 'ruc'])->name('ruc');
            Route::get('/exchange-rate', [ServiceController::class, 'exchangeRate'])->name('exchangeRate');
        });

        // Webhooks retry
        Route::post('/webhooks/{webhook}/retry', [WebhookController::class, 'retry'])->name('webhooks.retry');
    });
});
