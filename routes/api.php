<?php

use App\Http\Controllers\Api\MockInsurerCallbackController;
use App\Http\Controllers\Api\MockInsurerController;
use App\Http\Controllers\Api\PolicyRequestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Policy Request endpoints
Route::prefix('policy-requests')->group(function () {
    Route::get('/', [PolicyRequestController::class, 'index']);
    Route::post('/', [PolicyRequestController::class, 'store']);
    Route::get('/{id}', [PolicyRequestController::class, 'show']);
    Route::post('/{id}/submit', [PolicyRequestController::class, 'submit']);
});

// Mock Insurer endpoints
Route::prefix('mock-insurer')->group(function () {
    // Callback endpoint (receives provider events)
    Route::post('/callback', [MockInsurerCallbackController::class, 'callback']);

    // Internal mock insurer processing (simulates external insurer)
    Route::post('/process', [MockInsurerController::class, 'process']);

    // Reconciliation / status check
    Route::get('/status/{policyRequestId}', [MockInsurerController::class, 'status']);

    // Development helper: trigger pending callbacks
    Route::post('/trigger-callback/{policyRequestId}', [MockInsurerController::class, 'triggerCallback']);
});
