<?php

use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\MeController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.health');

// Fallback legacy auth routes
Route::prefix('auth')->name('api.auth.')->group(function (): void {
    Route::post('/register', RegisterController::class)->name('register');
    Route::post('/login', LoginController::class)->name('login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', MeController::class)->name('me');
        Route::post('/logout', LogoutController::class)->name('logout');
    });
});

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

    // Protected endpoints
    Route::middleware('auth:sanctum')->group(function (): void {
        // Companies (Tenants)
        Route::apiResource('companies', CompanyController::class)->only(['index', 'store', 'show', 'update']);
        Route::get('/companies/{company}/webhooks', [CompanyController::class, 'webhooks'])->name('companies.webhooks');

        // Electronic Invoicing (Asynchronous)
        Route::post('/invoices', [InvoiceController::class, 'store'])->name('invoices.store');

        // Documents Consultation
        Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');

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
